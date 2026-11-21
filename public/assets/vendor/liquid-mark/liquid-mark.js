/* Liquid-metal mark — uses the vendored liquid-logo GLSL shaders.
   Feeds the site's own inline SVG mark into the shader as a texture, so the
   chrome/liquid animation is clipped to the approved knot & pearl silhouette. */
var LIQUID_MARK_BASE = '/assets/vendor/liquid-mark/';

function liquidLoadText(url) {
    return fetch(url).then(function (r) {
        if (!r.ok) { throw new Error('shader ' + url); }
        return r.text();
    });
}

/* Serialize the live SVG with its computed CSS inlined — the mark's look lives
   in site.css, so a bare XMLSerializer copy would render as black shapes. */
function liquidSvgSource(svg) {
    var clone = svg.cloneNode(true);
    var src = svg.querySelectorAll('*');
    var dst = clone.querySelectorAll('*');
    var props = ['fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'opacity'];
    for (var i = 0; i < src.length; i++) {
        var computed = window.getComputedStyle(src[i]);
        var style = '';
        for (var p = 0; p < props.length; p++) {
            var key = props[p];
            var value = computed.getPropertyValue(key);
            /* Keep `none` too — dropping it makes inheriting nodes (rings, bezels)
               pick up the root fill and turn into solid disks. */
            if (value) { style += key + ':' + value + ';'; }
        }
        /* The CSS draw-on animation starts knots fully dashed-out; force them drawn. */
        if (src[i].classList && src[i].classList.contains('knot')) {
            style += 'stroke-dasharray:none;stroke-dashoffset:0;';
        }
        dst[i].setAttribute('style', style);
    }
    dst[0].setAttribute('style', 'fill:none;stroke:none;');
    clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(new XMLSerializer().serializeToString(clone));
}

function liquidLoadImage(src) {
    return new Promise(function (resolve, reject) {
        var img = new Image();
        img.onload = function () { resolve(img); };
        img.onerror = reject;
        img.src = src;
    });
}

function liquidCompile(gl, type, source) {
    var shader = gl.createShader(type);
    gl.shaderSource(shader, source);
    gl.compileShader(shader);
    if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
        console.warn('liquid mark shader:', gl.getShaderInfoLog(shader));
        gl.deleteShader(shader);
        return null;
    }
    return shader;
}

export function startLiquidMark(canvas, svg) {
    if (!canvas || !svg) { return function () {}; }

    var gl = canvas.getContext('webgl', { alpha: true, antialias: true, premultipliedAlpha: false });
    if (!gl) { return function () {}; }

    var raf = 0;
    var disposed = false;
    var program = null, buffer = null, texture = null, vs = null, fs = null;

    var uniforms = {
        speed: 0.32, iterations: 12, scale: 2.4, dotFactor: 0.06,
        dotMultiplier: 0.55, vOffset: 5.4, intensityFactor: 0.13,
        expFactor: 0.45, colorShift: 0.3, noiseIntensity: 0.6,
        logoScale: 1.0, logoAspectRatio: 1.0, logoInteractStrength: 0.55,
        logoBlendMode: 0, logoOpacity: 1.0
    };
    /* Warm gold chrome so the effect stays on-brand (no pink, no beige wash). */
    var colorFactors = [1.35, 0.95, 0.28];

    function sizeCanvas() {
        var dpr = Math.min(window.devicePixelRatio || 1, 2);
        var size = Math.max(canvas.clientWidth || 152, canvas.clientHeight || 152);
        var px = Math.round(size * dpr);
        if (canvas.width !== px || canvas.height !== px) {
            canvas.width = px;
            canvas.height = px;
        }
        gl.viewport(0, 0, canvas.width, canvas.height);
    }

    Promise.all([
        liquidLoadText(LIQUID_MARK_BASE + 'vertex.glsl'),
        liquidLoadText(LIQUID_MARK_BASE + 'fragment.glsl'),
        liquidLoadImage(liquidSvgSource(svg))
    ]).then(function (loaded) {
        if (disposed) { return; }
        vs = liquidCompile(gl, gl.VERTEX_SHADER, loaded[0]);
        fs = liquidCompile(gl, gl.FRAGMENT_SHADER, loaded[1]);
        if (!vs || !fs) { return; }

        program = gl.createProgram();
        gl.attachShader(program, vs);
        gl.attachShader(program, fs);
        gl.linkProgram(program);
        if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
            console.warn('liquid mark link:', gl.getProgramInfoLog(program));
            return;
        }
        gl.useProgram(program);

        buffer = gl.createBuffer();
        gl.bindBuffer(gl.ARRAY_BUFFER, buffer);
        gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1]), gl.STATIC_DRAW);
        var pos = gl.getAttribLocation(program, 'aVertexPosition');
        gl.enableVertexAttribArray(pos);
        gl.vertexAttribPointer(pos, 2, gl.FLOAT, false, 0, 0);

        /* Rasterize through a 2D canvas: SVG images can upload to WebGL as an
           opaque/incomplete texture in Chrome, which kills the alpha clip. */
        var raster = document.createElement('canvas');
        raster.width = 256;
        raster.height = 256;
        raster.getContext('2d').drawImage(loaded[2], 0, 0, 256, 256);

        texture = gl.createTexture();
        gl.bindTexture(gl.TEXTURE_2D, texture);
        gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false);
        gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, raster);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);

        var loc = function (n) { return gl.getUniformLocation(program, 'u_' + n); };
        gl.uniform1i(loc('logoTexture'), 0);
        gl.uniform1f(loc('speed'), uniforms.speed);
        gl.uniform1f(loc('iterations'), uniforms.iterations);
        gl.uniform1f(loc('scale'), uniforms.scale);
        gl.uniform1f(loc('dotFactor'), uniforms.dotFactor);
        gl.uniform1f(loc('dotMultiplier'), uniforms.dotMultiplier);
        gl.uniform1f(loc('vOffset'), uniforms.vOffset);
        gl.uniform1f(loc('intensityFactor'), uniforms.intensityFactor);
        gl.uniform1f(loc('expFactor'), uniforms.expFactor);
        gl.uniform1f(loc('colorShift'), uniforms.colorShift);
        gl.uniform1f(loc('noiseIntensity'), uniforms.noiseIntensity);
        gl.uniform1f(loc('logoScale'), uniforms.logoScale);
        gl.uniform1f(loc('logoAspectRatio'), uniforms.logoAspectRatio);
        gl.uniform1f(loc('logoInteractStrength'), uniforms.logoInteractStrength);
        gl.uniform1f(loc('logoOpacity'), uniforms.logoOpacity);
        gl.uniform1i(loc('logoBlendMode'), uniforms.logoBlendMode);
        gl.uniform3fv(loc('colorFactors'), colorFactors);

        var resolutionLoc = loc('resolution');
        var timeLoc = loc('time');
        var start = Date.now();

        sizeCanvas();
        window.addEventListener('resize', sizeCanvas);

        function frame() {
            if (disposed) { return; }
            gl.clearColor(0, 0, 0, 0);
            gl.clear(gl.COLOR_BUFFER_BIT);
            gl.uniform2f(resolutionLoc, canvas.width, canvas.height);
            gl.uniform1f(timeLoc, (Date.now() - start) / 1000);
            gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
            raf = requestAnimationFrame(frame);
        }
        frame();
        svg.parentNode.classList.add('is-liquid');
    }).catch(function () { /* decorative effect — never break the intro */ });

    return function () {
        disposed = true;
        if (raf) { cancelAnimationFrame(raf); }
        window.removeEventListener('resize', sizeCanvas);
        if (svg.parentNode) { svg.parentNode.classList.remove('is-liquid'); }
        if (program) { gl.deleteProgram(program); }
        if (vs) { gl.deleteShader(vs); }
        if (fs) { gl.deleteShader(fs); }
        if (buffer) { gl.deleteBuffer(buffer); }
        if (texture) { gl.deleteTexture(texture); }
    };
}
