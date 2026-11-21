/* Theme-tinted lustrous pearl in Three.js — no floor, no spill, no square edges. */
import * as THREE from './three.module.js';

export function startCrystal(canvas) {
    if (!canvas) { return function () {}; }

    var accent = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#d4af37';
    var tint = new THREE.Color(accent);

    var renderer = new THREE.WebGLRenderer({ canvas: canvas, alpha: true, antialias: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    var size = Math.max(canvas.clientWidth || 1, canvas.clientHeight || 1);
    renderer.setSize(size, size, false);

    var scene = new THREE.Scene();
    var camera = new THREE.PerspectiveCamera(42, 1, 0.1, 100);
    camera.position.z = 4.2;

    var uniforms = { tint: { value: tint }, time: { value: 0 } };
    var pearl = new THREE.Mesh(
        new THREE.SphereGeometry(0.62, 64, 48),
        new THREE.ShaderMaterial({
            uniforms: uniforms,
            vertexShader: [
                'varying vec3 vW; varying vec3 vN; varying vec3 vObj;',
                'void main(){',
                ' vec4 w = modelMatrix * vec4(position,1.);',
                ' vW = w.xyz; vN = normalize(mat3(modelMatrix) * normal); vObj = position;',
                ' gl_Position = projectionMatrix * viewMatrix * w;',
                '}'
            ].join('\n'),
            fragmentShader: [
                'uniform vec3 tint; uniform float time;',
                'varying vec3 vW; varying vec3 vN; varying vec3 vObj;',
                'void main(){',
                ' vec3 N = normalize(vN);',
                ' vec3 V = normalize(cameraPosition - vW);',
                ' float fres = pow(1.0 - max(dot(N,V),0.0), 2.2);',
                ' vec3 L1 = normalize(vec3(cos(time*0.7)*0.9, 0.85, sin(time*0.7)*0.9));',
                ' vec3 L2 = normalize(vec3(cos(time*0.45+2.0)*0.7, -0.25, sin(time*0.45+2.0)*0.7));',
                ' float d1 = max(dot(N,L1),0.0);',
                ' float d2 = max(dot(N,L2),0.0);',
                ' float s1 = pow(max(dot(N, normalize(L1+V)),0.0), 110.0);',
                ' float s2 = pow(max(dot(N, normalize(L2+V)),0.0), 55.0);',
                ' float spark = pow(max(dot(N, normalize(vec3(0.0,1.0,0.2)+V)),0.0), 220.0);',
                ' vec3 base = mix(vec3(0.78,0.76,0.72), vec3(1.0,0.99,0.97), d1);',
                ' vec3 col = base * (0.35 + 0.65*d1) + d2 * tint * 0.12;',
                ' col += tint * fres * 0.45;',
                ' col += vec3(1.0)*s1*2.2 + tint*s2*0.9 + vec3(1.0)*spark*1.6;',
                ' float nacre = sin(vObj.x*7.0 + time*0.8) * sin(vObj.y*9.0 - time*0.6) * sin(vObj.z*8.0 + time*0.5);',
                ' col += tint * (nacre*0.5+0.5) * 0.06;',
                ' col += tint * (sin(fres*14.0 + time)*0.5+0.5) * fres * 0.15;',
                ' col = col / (1.0 + col * 0.22);',
                ' gl_FragColor = vec4(col,1.0);',
                '}'
            ].join('\n')
        })
    );
    scene.add(pearl);

    var raf = 0;
    var disposed = false;
    var start = Date.now();

    function onResize() {
        var s = Math.max(canvas.clientWidth || 1, canvas.clientHeight || 1);
        camera.aspect = 1;
        camera.updateProjectionMatrix();
        renderer.setSize(s, s, false);
    }
    window.addEventListener('resize', onResize);

    function loop() {
        if (disposed) { return; }
        var t = (Date.now() - start) / 1000;
        uniforms.time.value = t;
        pearl.rotation.y += 0.006;
        pearl.rotation.z = Math.sin(t * 0.6) * 0.12;
        pearl.position.y = Math.sin(t * 1.6) * 0.11;
        pearl.position.x = Math.sin(t * 0.9) * 0.04;
        renderer.render(scene, camera);
        raf = requestAnimationFrame(loop);
    }
    loop();

    return function () {
        disposed = true;
        cancelAnimationFrame(raf);
        window.removeEventListener('resize', onResize);
        pearl.geometry.dispose();
        pearl.material.dispose();
        renderer.dispose();
    };
}
