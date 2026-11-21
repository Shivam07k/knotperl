/* STOCK ASSET — realistic round brilliant-cut diamond (not currently used in the intro).
   Ported from the supplied reference. To use it, import this module instead of crystal.js
   and call startDiamond(canvas, optionalColour). Uses local three.module.js. */
import * as THREE from './three.module.js';

var DEFAULT_TINT = '#4fc3ff';

export function startDiamond(canvas, colour) {
    if (!canvas) { return function () {}; }

    var renderer = new THREE.WebGLRenderer({ canvas: canvas, antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    var initialSize = Math.max(canvas.clientWidth || 1, canvas.clientHeight || 1);
    renderer.setSize(initialSize, initialSize, false);

    var scene = new THREE.Scene();
    var camera = new THREE.PerspectiveCamera(38, 1, 0.1, 100);
    camera.position.set(0, 0.35, 7);
    camera.lookAt(0, -0.25, 0);

    // Round brilliant cut with extra facet rings (~100 facets).
    function pol(r, a, y) { return new THREE.Vector3(Math.cos(a) * r, y, Math.sin(a) * r); }
    var A8 = Math.PI * 2 / 8, A16 = Math.PI / 8;
    var T = [], S = [], B = [], U = [], G = [], Gl = [], L = [], H = [];
    for (var i = 0; i < 8; i++) {
        T.push(pol(0.55, i * A8, 0.66));
        S.push(pol(0.76, (i + 0.5) * A8, 0.45));
        B.push(pol(0.88, i * A8, 0.25));
        U.push(pol(0.95, (i + 0.5) * A8, 0.11));
        L.push(pol(0.74, (i + 0.5) * A8, -0.40));
        H.push(pol(0.42, i * A8, -0.92));
    }
    for (var k = 0; k < 16; k++) { G.push(pol(1, k * A16, 0)); Gl.push(pol(1, k * A16, -0.07)); }
    var C = new THREE.Vector3(0, -1.5, 0);
    var centre = new THREE.Vector3(0, -0.4, 0);
    var pos = [];
    function poly(pts) {
        var n = new THREE.Vector3().crossVectors(
            new THREE.Vector3().subVectors(pts[1], pts[0]),
            new THREE.Vector3().subVectors(pts[2], pts[0]));
        var c = new THREE.Vector3();
        pts.forEach(function (p) { c.add(p); });
        c.divideScalar(pts.length);
        if (n.dot(c.sub(centre)) < 0) { pts = pts.slice().reverse(); }
        for (var j = 1; j < pts.length - 1; j++) {
            [pts[0], pts[j], pts[j + 1]].forEach(function (p) { pos.push(p.x, p.y, p.z); });
        }
    }
    poly(T);
    for (var a = 0; a < 8; a++) {
        var a1 = (a + 1) % 8, am = (a + 7) % 8;
        poly([T[a], T[a1], S[a]]);
        poly([T[a], S[am], B[a], S[a]]);
        poly([S[a], B[a], U[a]]);
        poly([S[a], U[a], B[a1]]);
        poly([B[a], G[2 * a], G[2 * a + 1], U[a]]);
        poly([U[a], G[2 * a + 1], G[(2 * a + 2) % 16], B[a1]]);
        poly([C, H[a], L[a], H[a1]]);
        poly([H[a], L[a], Gl[2 * a], L[am]]);
        poly([L[a], Gl[2 * a], Gl[2 * a + 1]]);
        poly([L[a], Gl[2 * a + 1], Gl[(2 * a + 2) % 16]]);
    }
    for (var g = 0; g < 16; g++) {
        var g1 = (g + 1) % 16;
        poly([G[g], G[g1], Gl[g1], Gl[g]]);
    }
    var geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3));
    geo.computeVertexNormals();

    // Studio environment the facets reflect.
    var cubeRT = new THREE.WebGLCubeRenderTarget(256, { type: THREE.HalfFloatType, format: THREE.RGBAFormat });
    var cubeCam = new THREE.CubeCamera(0.1, 100, cubeRT);
    var envScene = new THREE.Scene();
    var envMeshes = [];
    var sky = new THREE.Mesh(new THREE.SphereGeometry(40, 32, 16), new THREE.ShaderMaterial({
        side: THREE.BackSide,
        vertexShader: 'varying vec3 p; void main(){ p=position; gl_Position=projectionMatrix*modelViewMatrix*vec4(position,1.); }',
        fragmentShader: 'varying vec3 p; void main(){ float h=normalize(p).y*.5+.5; gl_FragColor=vec4(mix(vec3(.005,.006,.012),vec3(.10,.12,.18),h*h),1.); }'
    }));
    envScene.add(sky);
    envMeshes.push(sky);
    var lights = new THREE.Group();
    envScene.add(lights);
    var tints = [[1, 1, 1], [1, 1, 1], [1, .95, .85], [.85, .92, 1], [1, 1, 1], [1, .8, .9], [.85, 1, .95], [1, .9, .7]];
    function randDir() { return new THREE.Vector3(Math.random() - .5, Math.random() - .5, Math.random() - .5).normalize(); }
    for (var l = 0; l < 40; l++) {
        var strip = l % 3 === 0;
        var w = strip ? 0.5 + Math.random() * 0.8 : 1 + Math.random() * 3;
        var h = strip ? 5 + Math.random() * 7 : w * (0.4 + Math.random());
        var m = new THREE.Mesh(new THREE.PlaneGeometry(w, h), new THREE.MeshBasicMaterial({ side: THREE.DoubleSide }));
        var tnt = tints[l % tints.length], bright = 5 + Math.random() * 12;
        m.material.color.setRGB(tnt[0] * bright, tnt[1] * bright, tnt[2] * bright);
        m.position.copy(randDir().multiplyScalar(14));
        m.lookAt(0, 0, 0);
        m.rotateZ(Math.random() * 3.14);
        lights.add(m);
        envMeshes.push(m);
    }

    // Diamond shaders: reflection + dispersion + internal bounces.
    var shared = {
        env: { value: cubeRT.texture },
        tint: { value: new THREE.Color(colour || DEFAULT_TINT) },
        time: { value: 0 },
        floorY: { value: -1.78 }
    };
    var vert = [
        'varying vec3 vW; varying vec3 vN;',
        'void main(){',
        ' vec4 w = modelMatrix * vec4(position,1.);',
        ' vW = w.xyz; vN = normalize(mat3(modelMatrix) * normal);',
        ' gl_Position = projectionMatrix * viewMatrix * w;',
        '}'
    ].join('\n');
    var fragFront = [
        'uniform samplerCube env; uniform vec3 tint; uniform float time, mir, floorY;',
        'varying vec3 vW; varying vec3 vN;',
        'vec3 bounce(vec3 I, vec3 N, float ior){',
        ' vec3 d  = refract(I, N, 1.0/ior);',
        ' vec3 d2 = reflect(d, normalize(N * vec3(1.,-1.,1.) + vec3(0.,.35,0.)));',
        ' vec3 d3 = reflect(d2, normalize(vec3(N.z, N.y*.4, -N.x)));',
        ' vec3 d4 = reflect(d3, normalize(vec3(-N.x*.6, N.y, N.z)));',
        ' return (textureCube(env, d2).rgb + textureCube(env, d3).rgb + textureCube(env, d4).rgb) / 3.0;',
        '}',
        'void main(){',
        ' vec3 I = normalize(vW - cameraPosition);',
        ' vec3 N = normalize(vN);',
        ' float fres = pow(1.0 - abs(dot(-I, N)), 3.0);',
        ' vec3 refl = textureCube(env, reflect(I, N)).rgb;',
        ' vec3 inner = vec3(bounce(I,N,2.34).r, bounce(I,N,2.43).g, bounce(I,N,2.55).b);',
        ' vec3 col = inner * mix(vec3(1.), tint * 1.7, .85) * 1.15;',
        ' col += refl * (.22 + fres * .9);',
        ' vec3 Ld = normalize(vec3(sin(time*.8), .8, cos(time*.8)));',
        ' col += pow(max(dot(reflect(I,N), Ld), 0.), 60.) * 3.0 * (vec3(1.) + tint);',
        ' col = col / (1.0 + col * .33);',
        ' col = pow(col, vec3(.88));',
        ' float a = mix(.88, .42 * smoothstep(floorY - 2.4, floorY, vW.y), mir);',
        ' gl_FragColor = vec4(col, a);',
        '}'
    ].join('\n');
    var fragBack = [
        'uniform samplerCube env; uniform vec3 tint; uniform float mir, floorY;',
        'varying vec3 vW; varying vec3 vN;',
        'void main(){',
        ' vec3 I = normalize(vW - cameraPosition);',
        ' vec3 N = -normalize(vN);',
        ' vec3 r = textureCube(env, reflect(I, N)).rgb;',
        ' vec3 col = r * mix(vec3(1.), tint * 1.5, .9) * .95;',
        ' col = col / (1.0 + col * .33);',
        ' float a = mix(1., .42 * smoothstep(floorY - 2.4, floorY, vW.y), mir);',
        ' gl_FragColor = vec4(col, a);',
        '}'
    ].join('\n');
    function makeGem(mir) {
        var u = Object.assign({}, shared, { mir: { value: mir } });
        var back = new THREE.Mesh(geo, new THREE.ShaderMaterial({
            uniforms: u, vertexShader: vert, fragmentShader: fragBack,
            side: THREE.BackSide, transparent: mir > 0, depthWrite: mir === 0
        }));
        var front = new THREE.Mesh(geo, new THREE.ShaderMaterial({
            uniforms: u, vertexShader: vert, fragmentShader: fragFront,
            side: THREE.FrontSide, transparent: true, depthWrite: false
        }));
        back.renderOrder = 1;
        front.renderOrder = 2;
        var group = new THREE.Group();
        group.add(back, front);
        group.scale.setScalar(1.35);
        return group;
    }
    var gem = makeGem(0);
    scene.add(gem);

    var mirrorWrap = new THREE.Group();
    var gemM = makeGem(1);
    mirrorWrap.add(gemM);
    mirrorWrap.scale.y = -1;
    mirrorWrap.position.y = 2 * shared.floorY.value;
    scene.add(mirrorWrap);

    function radialTex(inner) {
        var c = document.createElement('canvas');
        c.width = c.height = 256;
        var x = c.getContext('2d');
        var grad = x.createRadialGradient(128, 128, 0, 128, 128, 128);
        grad.addColorStop(0, inner);
        grad.addColorStop(.35, 'rgba(255,255,255,.25)');
        grad.addColorStop(1, 'rgba(255,255,255,0)');
        x.fillStyle = grad;
        x.fillRect(0, 0, 256, 256);
        return new THREE.CanvasTexture(c);
    }
    var spill = new THREE.Mesh(new THREE.PlaneGeometry(8, 8),
        new THREE.MeshBasicMaterial({
            map: radialTex('rgba(255,255,255,.9)'),
            transparent: true, blending: THREE.AdditiveBlending,
            depthWrite: false, color: new THREE.Color(colour || DEFAULT_TINT)
        }));
    spill.rotation.x = -Math.PI / 2;
    spill.position.y = shared.floorY.value + 0.01;
    scene.add(spill);

    // Star-shaped glints that flash on the facets.
    function flareTex() {
        var c = document.createElement('canvas');
        c.width = c.height = 128;
        var x = c.getContext('2d');
        var grad = x.createRadialGradient(64, 64, 0, 64, 64, 64);
        grad.addColorStop(0, 'rgba(255,255,255,1)');
        grad.addColorStop(.15, 'rgba(255,255,255,.6)');
        grad.addColorStop(1, 'rgba(255,255,255,0)');
        x.fillStyle = grad;
        x.fillRect(0, 0, 128, 128);
        x.globalCompositeOperation = 'lighter';
        [[0, 1], [1, 0]].forEach(function (axis) {
            var a = axis[0], b = axis[1];
            var lg = x.createLinearGradient(64 - 64 * a, 64 - 64 * b, 64 + 64 * a, 64 + 64 * b);
            lg.addColorStop(0, 'rgba(255,255,255,0)');
            lg.addColorStop(.5, 'rgba(255,255,255,1)');
            lg.addColorStop(1, 'rgba(255,255,255,0)');
            x.fillStyle = lg;
            x.fillRect(a ? 0 : 62, b ? 0 : 62, a ? 128 : 4, b ? 128 : 4);
        });
        return new THREE.CanvasTexture(c);
    }
    var ftex = flareTex();
    var vpts = [];
    for (var vi = 0; vi < pos.length; vi += 3) { vpts.push(new THREE.Vector3(pos[vi], pos[vi + 1], pos[vi + 2])); }
    var flares = [];
    for (var fi = 0; fi < 16; fi++) {
        var sprite = new THREE.Sprite(new THREE.SpriteMaterial({
            map: ftex, blending: THREE.AdditiveBlending,
            depthTest: false, transparent: true, depthWrite: false
        }));
        sprite.userData = { idx: (Math.random() * vpts.length) | 0, ph: Math.random() * 6.28, sp: 1 + Math.random() * 1.8 };
        scene.add(sprite);
        flares.push(sprite);
    }

    var dragX = 0, dragY = 0.5, velX = 0.006;
    var clock = new THREE.Clock();
    var tmp = new THREE.Vector3(), nrm = new THREE.Vector3(), view = new THREE.Vector3(), cen = new THREE.Vector3();
    var raf = 0;
    var disposed = false;

    function onResize() {
        var s = Math.max(canvas.clientWidth || 1, canvas.clientHeight || 1);
        camera.aspect = 1;
        camera.updateProjectionMatrix();
        renderer.setSize(s, s, false);
    }
    window.addEventListener('resize', onResize);

    function frame() {
        if (disposed) { return; }
        var t = clock.getElapsedTime();
        velX += (0.006 - velX) * 0.02;
        dragX += velX;
        var rx = dragY * 0.6 + Math.sin(t * .6) * 0.08;
        var by = 0.3 + Math.sin(t * 1.2) * 0.06;
        gem.rotation.set(rx, dragX, 0);
        gem.position.y = by;
        gemM.rotation.set(rx, dragX, 0);
        gemM.position.y = by;
        lights.rotation.y = t * 0.15;
        lights.rotation.x = Math.sin(t * .2) * .3;
        shared.time.value = t;
        spill.material.opacity = .75 + Math.sin(t * 2) * .12;

        gem.updateMatrixWorld(true);
        cen.set(0, 0, 0);
        gem.localToWorld(cen);
        flares.forEach(function (s) {
            var d = s.userData;
            var k = Math.sin(t * d.sp + d.ph);
            k = k > 0 ? Math.pow(k, 5) : 0;
            if (k < 0.005 && d.armed) { d.idx = (Math.random() * vpts.length) | 0; d.armed = false; }
            if (k > 0.3) { d.armed = true; }
            tmp.copy(vpts[d.idx]);
            gem.localToWorld(tmp);
            nrm.subVectors(tmp, cen).normalize();
            view.subVectors(camera.position, tmp).normalize();
            var facing = Math.max(0, Math.min(1, nrm.dot(view) * 3));
            s.position.copy(tmp);
            var v = k * facing;
            s.scale.setScalar(0.25 + v * 1.1);
            s.material.opacity = v;
            s.material.rotation = t * 0.3 + d.ph;
        });

        gem.visible = false;
        gemM.visible = false;
        spill.visible = false;
        flares.forEach(function (f) { f.visible = false; });
        cubeCam.update(renderer, envScene);
        gem.visible = true;
        gemM.visible = true;
        spill.visible = true;
        flares.forEach(function (f) { f.visible = true; });
        renderer.render(scene, camera);
        raf = requestAnimationFrame(frame);
    }
    frame();

    return function () {
        disposed = true;
        cancelAnimationFrame(raf);
        window.removeEventListener('resize', onResize);
        scene.traverse(function (o) {
            if (o.geometry) { o.geometry.dispose(); }
            if (o.material) { o.material.dispose(); }
        });
        envMeshes.forEach(function (m) { m.geometry.dispose(); m.material.dispose(); });
        geo.dispose();
        ftex.dispose();
        cubeRT.dispose();
        renderer.dispose();
    };
}
