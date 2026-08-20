/* ==========================================================================
   SUPPEX — 3D hero stage
   --------------------------------------------------------------------------
   Renders the supplied whey-isolate tub model beside the hero headline.

   Guard rails, in order — the stage only ever initialises if all pass:
     1. the show3DHero flag is on
     2. the viewport is wide enough to be worth the download
     3. the user has not asked for reduced motion
     4. WebGL is actually available
     5. the stage has scrolled into view (IntersectionObserver)

   If any check fails the static poster simply stays visible. The poster is
   in the HTML from the start, so there is no flash of empty space and no
   layout shift either way.

   Both three.js and the mesh are injected as classic <script> tags rather
   than fetched, which is what lets the whole prototype run from file://.
   ========================================================================== */
window.SUPPEX = window.SUPPEX || {};

SUPPEX.hero3d = (function () {
  'use strict';

  var cfg = SUPPEX.config.hero3d;
  var started = false;

  /* Resting tilt of the model on the X axis. Pointer parallax is applied as an
     offset from this, so both places that touch rotation.x share one value. */
  var BASE_TILT_X = 0.055;   /* tipped back a touch, as a product shot sits */

  /* --- capability checks ------------------------------------------------ */

  function prefersReducedMotion() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  /* Respect a metered or genuinely slow connection. 3g is deliberately NOT in
     this list: the poster is already on screen and the 3D fades in whenever it
     arrives, so a slow load costs patience, not a broken layout. */
  function onSlowConnection() {
    if (!cfg.skipOnSlowConnection) { return false; }
    var c = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    if (!c) { return false; }
    if (c.saveData) { return true; }
    return c.effectiveType === 'slow-2g' || c.effectiveType === '2g';
  }

  function hasWebGL() {
    try {
      var canvas = document.createElement('canvas');
      return !!(window.WebGLRenderingContext &&
        (canvas.getContext('webgl') || canvas.getContext('experimental-webgl')));
    } catch (err) {
      return false;
    }
  }

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var el = document.createElement('script');
      el.src = src;
      el.async = true;
      el.onload = resolve;
      el.onerror = function () { reject(new Error('could not load ' + src)); };
      document.head.appendChild(el);
    });
  }

  /* --- mesh decoding ----------------------------------------------------
     Positions arrive as Int16 quantised across the model's bounding box and
     are expanded back to floats here; UVs are Uint16 over 0..1, normals Int8,
     indices Uint16. */

  function decodeMesh(packed) {
    var bin = atob(packed.data);
    var bytes = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) { bytes[i] = bin.charCodeAt(i); }
    var buf = bytes.buffer;

    var n = packed.vertexCount;
    var qpos = new Int16Array(buf, packed.posOffset, n * 3);
    var quv = new Uint16Array(buf, packed.uvOffset, n * 2);
    var qnrm = new Int8Array(buf, packed.nrmOffset, n * 3);
    var index = new Uint16Array(buf, packed.idxOffset, packed.indexCount);

    var position = new Float32Array(n * 3);
    var normal = new Float32Array(n * 3);
    var uv = new Float32Array(n * 2);
    var min = packed.min, max = packed.max;

    for (var v = 0; v < n; v++) {
      for (var k = 0; k < 3; k++) {
        var t = (qpos[v * 3 + k] + 32768) / 65535;
        position[v * 3 + k] = min[k] + t * (max[k] - min[k]);
        normal[v * 3 + k] = qnrm[v * 3 + k] / 127;
      }
      uv[v * 2] = quv[v * 2] / 65535;
      uv[v * 2 + 1] = quv[v * 2 + 1] / 65535;
    }
    return { position: position, normal: normal, uv: uv, index: index };
  }

  /* --- the product label -------------------------------------------------
     The label is the artwork baked into the supplied .glb, lifted out and
     re-encoded (see assets/models/label.tex.js). The .glb itself is not loaded
     directly: at 1.8 MB it is mostly this one texture, and GLTFLoader ships
     only as an ES module in current three.js — which `file://` blocks, taking
     the no-server requirement with it. Lifting the texture keeps the real
     artwork and the light OBJ geometry at the same time.

     The label patch is identical in both files — 194 vertices, UVs spanning
     0..1, v growing upward — so the artwork lands on the OBJ mesh exactly as
     it sat on the glTF one. */

  function loadLabel(THREE) {
    if (!window.SUPPEX_TUB_LABEL) { return drawFallbackLabel(THREE); }
    var tex = new THREE.TextureLoader().load(window.SUPPEX_TUB_LABEL);
    tex.colorSpace = THREE.SRGBColorSpace;
    tex.anisotropy = 8;
    return tex;
  }

  /* Only reached if the texture file is missing from a copied-out build. The
     label material would otherwise render pure white, which looks broken. */
  function drawFallbackLabel(THREE) {
    var W = 1024, H = 716;                 // the patch measures 1.43 : 1
    var c = document.createElement('canvas');
    c.width = W; c.height = H;
    var g = c.getContext('2d');

    var bg = g.createLinearGradient(0, 0, W, H);
    bg.addColorStop(0, '#F25914');
    bg.addColorStop(1, '#8A2A05');
    g.fillStyle = bg;
    g.fillRect(0, 0, W, H);

    g.fillStyle = 'rgba(255,255,255,0.30)';
    g.fillRect(0, 0, W, 10);
    g.fillStyle = 'rgba(0,0,0,0.28)';
    g.fillRect(0, H - 10, W, 10);

    g.textAlign = 'center';
    g.fillStyle = '#fff';

    g.font = '900 74px "Arial Black", Helvetica, sans-serif';
    g.letterSpacing = '18px';
    g.fillText('SUPPEX', W / 2, 168);
    g.letterSpacing = '0px';

    g.fillStyle = 'rgba(255,255,255,0.55)';
    g.fillRect(W / 2 - 150, 210, 300, 5);

    g.fillStyle = '#fff';
    g.font = '900 168px "Arial Black", Helvetica, sans-serif';
    g.fillText('WHEY', W / 2, 380);

    g.font = '900 96px "Arial Black", Helvetica, sans-serif';
    g.letterSpacing = '12px';
    g.fillText('ISOLATE', W / 2, 486);
    g.letterSpacing = '0px';

    g.fillStyle = 'rgba(255,255,255,0.9)';
    g.font = '700 46px Arial, Helvetica, sans-serif';
    g.letterSpacing = '8px';
    g.fillText('24 G PROTEIN  ·  900 G', W / 2, 604);
    g.letterSpacing = '0px';

    var tex = new THREE.CanvasTexture(c);
    tex.colorSpace = THREE.SRGBColorSpace;
    tex.anisotropy = 8;
    return tex;
  }

  /* --- a studio in a canvas ---------------------------------------------
     Glossy surfaces need something to reflect. Rather than pull in an HDR file
     or the examples/ RoomEnvironment module (which would need ES modules, and
     so a server), the environment is painted into a canvas and used as an
     equirectangular reflection map. Cheap, dependency-free, and it gives the
     plastic the long soft highlights a studio softbox would. */

  function buildEnvironment(THREE) {
    var c = document.createElement('canvas');
    c.width = 512; c.height = 256;
    var g = c.getContext('2d');

    var sky = g.createLinearGradient(0, 0, 0, 256);
    sky.addColorStop(0.00, '#3c3a36');
    sky.addColorStop(0.42, '#171614');
    sky.addColorStop(0.52, '#0d0c0b');
    sky.addColorStop(1.00, '#050505');
    g.fillStyle = sky;
    g.fillRect(0, 0, 512, 256);

    function softbox(x, y, w, h, color, blur) {
      g.save();
      g.filter = 'blur(' + blur + 'px)';
      g.fillStyle = color;
      g.fillRect(x, y, w, h);
      g.restore();
    }

    /* key light, warm — the copper accent shows up along the tub's edge */
    softbox(60, 8, 150, 78, 'rgba(255,238,222,0.95)', 16);
    /* opposite fill, cooler and dimmer */
    softbox(330, 22, 110, 54, 'rgba(150,170,200,0.42)', 20);
    /* low warm bounce, separates the base from the background */
    softbox(200, 176, 190, 40, 'rgba(242,124,61,0.30)', 26);

    var tex = new THREE.CanvasTexture(c);
    tex.mapping = THREE.EquirectangularReflectionMapping;
    tex.colorSpace = THREE.SRGBColorSpace;
    return tex;
  }

  /* --- material translation from the .mtl -------------------------------- */

  /* The tub is moulded plastic, not metal. Its shape reads almost entirely
     from specular highlights — a near-black diffuse gives the eye nothing —
     so the plastics get a clearcoat and a strong environment. */
  var SURFACE = {
    'jar-black-plastic':  { roughness: 0.44, clearcoat: 0.65, clearcoatRoughness: 0.24, env: 1.5 },
    'lid-gloss-plastic':  { roughness: 0.26, clearcoat: 0.9,  clearcoatRoughness: 0.11, env: 1.8 },
    'label-print':        { roughness: 0.62, clearcoat: 0.28, clearcoatRoughness: 0.4,  env: 0.85 },
  };

  function buildMaterials(THREE, packed) {
    var out = {};
    Object.keys(packed.materials).forEach(function (name) {
      var src = packed.materials[name];
      var surf = SURFACE[name] || { roughness: 0.5, clearcoat: 0, clearcoatRoughness: 0.5, env: 1 };
      var col = new THREE.Color(src.color[0], src.color[1], src.color[2]);
      col.convertSRGBToLinear();

      var opts = {
        color: col,
        metalness: 0,
        roughness: surf.roughness,
        clearcoat: surf.clearcoat,
        clearcoatRoughness: surf.clearcoatRoughness,
        envMapIntensity: surf.env,
      };
      if (name === 'label-print') {
        opts.map = loadLabel(THREE);
        opts.color = new THREE.Color(0xffffff);   // let the artwork carry the colour
      }
      out[name] = new THREE.MeshPhysicalMaterial(opts);
    });
    return out;
  }

  /* --- the scene --------------------------------------------------------- */

  function build(stage) {
    var THREE = window.THREE;
    var packed = window.SUPPEX_TUB;
    if (!THREE || !packed) { return; }

    var decoded = decodeMesh(packed);

    var geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.BufferAttribute(decoded.position, 3));
    geometry.setAttribute('normal', new THREE.BufferAttribute(decoded.normal, 3));
    geometry.setAttribute('uv', new THREE.BufferAttribute(decoded.uv, 2));
    geometry.setIndex(new THREE.BufferAttribute(decoded.index, 1));

    var materials = buildMaterials(THREE, packed);
    var order = [];
    packed.groups.forEach(function (grp) {
      var slot = order.indexOf(grp.material);
      if (slot === -1) { slot = order.push(grp.material) - 1; }
      geometry.addGroup(grp.start, grp.count, slot);
    });
    var materialList = order.map(function (name) { return materials[name]; });

    var scene = new THREE.Scene();
    var env = buildEnvironment(THREE);
    scene.environment = env;

    var tub = new THREE.Mesh(geometry, materialList);
    /* The model arrives centred on X/Z and normalised to 1.0 tall, so one
       scale factor frames it regardless of the source dimensions. The tub is
       tallest along Y and never widens as it turns, so unlike the dumbbell
       there is no broadside worst case to leave headroom for. */
    tub.scale.setScalar(1.45);

    var pivot = new THREE.Group();
    pivot.add(tub);
    /* Yaw starts at 0: the label is modelled facing +Z, straight at camera. */
    pivot.rotation.set(BASE_TILT_X, 0, 0);
    scene.add(pivot);

    /* Direct lights on top of the environment. Glossy near-black plastic shows
       its form through highlights rather than diffuse shading, so the key sits
       high and front-left and the rim is deliberately hot. */
    var key = new THREE.DirectionalLight(0xfff2e6, 2.2);
    key.position.set(-2.0, 3.2, 2.6);
    scene.add(key);

    var rim = new THREE.DirectionalLight(0xf2660f, 3.4);
    rim.position.set(2.6, 1.4, -2.2);
    scene.add(rim);

    var fill = new THREE.DirectionalLight(0x9fb4d0, 1.1);
    fill.position.set(1.8, -1.2, 2.0);
    scene.add(fill);

    scene.add(new THREE.AmbientLight(0xffffff, 0.26));

    var camera = new THREE.PerspectiveCamera(34, 1, 0.1, 100);
    camera.position.set(0, 0.10, 3.35);
    camera.lookAt(0, -0.02, 0);

    var renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'high-performance' });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.05;
    stage.appendChild(renderer.domElement);

    function resize() {
      var rect = stage.getBoundingClientRect();
      var w = Math.max(1, rect.width);
      var h = Math.max(1, rect.height);
      renderer.setSize(w, h, false);
      camera.aspect = w / h;
      camera.updateProjectionMatrix();
    }
    resize();

    if (window.ResizeObserver) {
      new ResizeObserver(resize).observe(stage);
    } else {
      window.addEventListener('resize', resize);
    }

    /* Pointer parallax — the model leans toward the cursor, then eases back.
       Deliberately small: it should feel like weight, not like a toy. */
    var targetX = 0, targetY = 0, curX = 0, curY = 0;
    stage.addEventListener('pointermove', function (e) {
      var rect = stage.getBoundingClientRect();
      targetY = ((e.clientX - rect.left) / rect.width - 0.5) * 0.6;
      targetX = ((e.clientY - rect.top) / rect.height - 0.5) * 0.34;
    });
    stage.addEventListener('pointerleave', function () { targetX = 0; targetY = 0; });

    /* A full turntable would hide the label — it only wraps 140 deg — so the
       tub sways gently around front instead, keeping the artwork readable. */
    var sway = prefersReducedMotion() ? 0 : 0.0045;
    var visible = true;
    var io = new IntersectionObserver(function (entries) {
      visible = entries[0].isIntersecting;
    }, { threshold: 0 });
    io.observe(stage);

    var baseY = pivot.rotation.y;
    var phase = 0;

    function frame() {
      requestAnimationFrame(frame);
      if (!visible) { return; }              // don't burn a GPU off-screen
      phase += sway;
      curX += (targetX - curX) * 0.06;
      curY += (targetY - curY) * 0.06;
      pivot.rotation.y = baseY + Math.sin(phase) * 0.40 + curY;
      pivot.rotation.x = BASE_TILT_X + curX;
      renderer.render(scene, camera);
    }
    frame();

    stage.classList.add('is-live');
  }

  /* --- public entry ------------------------------------------------------ */

  function start(stage) {
    if (started) { return; }
    started = true;

    loadScript(cfg.threeSrc)
      .then(function () { return loadScript(cfg.modelSrc); })
      .then(function () {
        /* The label is cosmetic: if it alone fails, still show the tub. */
        return loadScript(cfg.labelSrc).catch(function (err) {
          console.warn('[suppex] label texture missing, using drawn fallback:', err.message);
        });
      })
      .then(function () { build(stage); })
      .catch(function (err) {
        /* Offline, blocked CDN, or a GPU that gave up — the poster is already
           on screen, so the hero degrades to exactly what it shows on mobile. */
        console.warn('[suppex] 3D hero unavailable, keeping static poster:', err.message);
      });
  }

  return {
    init: function () {
      var stage = document.querySelector('[data-stage]');
      if (!stage) { return; }

      if (!SUPPEX.config.flags.show3DHero) { return; }
      if (prefersReducedMotion()) { return; }
      if (onSlowConnection()) { return; }
      if (!hasWebGL()) { return; }

      /* Both conditions — wide enough, and near the viewport — are evaluated
         at the moment of the decision rather than once at load. A phone never
         becomes a desktop, but a small desktop window does get maximised, and
         deciding once would strand that viewer on the poster for the rest of
         the session. `started` makes this safe to call as often as we like. */
      var MARGIN = 200;   // start fetching this far before the hero is on screen

      function maybeStart() {
        if (started) { return; }
        if (window.innerWidth < cfg.minViewportWidth) { return; }
        var rect = stage.getBoundingClientRect();
        if (rect.top > window.innerHeight + MARGIN || rect.bottom < -MARGIN) { return; }
        start(stage);
      }

      /* Trigger 1: it is already on screen. The hero sits above the fold on
         essentially every load, so check straight away rather than waiting on
         an observer to deliver its first callback. */
      maybeStart();

      /* Trigger 2: it scrolls into view later (narrow window, deep link, or a
         viewport short enough to push the stage below the fold). */
      var io = new IntersectionObserver(function (entries) {
        if (!entries[0].isIntersecting) { return; }
        maybeStart();                       // too narrow? stay armed for later
        if (started) { io.disconnect(); }
      }, { rootMargin: MARGIN + 'px' });
      if (!started) { io.observe(stage); }

      /* Trigger 3: the window is widened past the threshold while the hero is
         already on screen, which produces no intersection change of its own.
         Checked inline rather than debounced — it is one rect read, and once
         it succeeds `started` short-circuits every later call. */
      window.addEventListener('resize', function () {
        if (started) { return; }
        maybeStart();
        if (started) { io.disconnect(); }
      });
    },
  };
})();
