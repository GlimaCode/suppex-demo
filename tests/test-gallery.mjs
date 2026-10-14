/* ===========================================================================
   The product page's pictures
   ---------------------------------------------------------------------------
   Five pure helpers in app.js, behind the gallery's arrows and the enlarged
   view:

     galleryStep()  which picture is next, wrapping at either end
     swipeStep()    which way a swipe goes, in a right-to-left page
     keyStep()      which way the arrow keys go, the same
     zoomAbout()    zooming so the spot under the finger stays under it
     clampPan()     a zoomed picture never pans past its own edges

   The directions are the part most easily got backwards: in a right-to-left
   page the next picture comes from the LEFT, so a finger moving right and
   the ← key both mean «next».
   =========================================================================== */
import fs from 'node:fs';
import vm from 'node:vm';

const ROOT = new URL('../', import.meta.url);
const JS = (f) => new URL('assets/js/' + f, ROOT);

let pass = 0;
let fail = 0;
const ok = (label, cond, extra = '') => {
  if (cond) { pass++; console.log('  ok   ' + label); }
  else { fail++; console.log('  FAIL ' + label + (extra ? '  --  ' + extra : '')); }
};
const head = (t) => console.log('\n== ' + t + ' ==');

/** app.js on top of the store, with just enough DOM for it to load - the same
    sandbox tests/test-hero.mjs uses. */
function bootApp() {
  const mem = {};
  const noop = () => {};
  const el = { addEventListener: noop, classList: { toggle: noop, add: noop, remove: noop } };
  const sb = {
    console,
    setTimeout,
    clearTimeout,
    document: {
      documentElement: {},
      addEventListener: noop,
      querySelector: () => null,
      querySelectorAll: () => [],
      body: el,
    },
    localStorage: {
      getItem: (k) => (k in mem ? mem[k] : null),
      setItem: (k, v) => { mem[k] = String(v); },
      removeItem: (k) => { delete mem[k]; },
    },
    addEventListener: noop,
    matchMedia: () => ({ matches: false, addEventListener: noop }),
  };
  sb.window = sb;
  vm.createContext(sb);
  for (const f of ['config.js', 'store.js', 'ui.js', 'app.js']) {
    vm.runInContext(fs.readFileSync(JS(f), 'utf8'), sb);
  }
  return sb.SUPPEX.app;
}

const app = bootApp();
const near = (a, b) => Math.abs(a - b) < 1e-9;

head('the helpers are exported for this file');
for (const f of ['galleryStep', 'swipeStep', 'keyStep', 'zoomAbout', 'clampPan']) {
  ok(f, typeof app[f] === 'function');
}

head('stepping through the pictures wraps at both ends');
ok('the next of five', app.galleryStep(0, 1, 5) === 1);
ok('past the last comes the first', app.galleryStep(4, 1, 5) === 0);
ok('before the first comes the last', app.galleryStep(0, -1, 5) === 4);
ok('a far jump still lands inside', app.galleryStep(7, 0, 5) === 2 && app.galleryStep(-6, 0, 5) === 4);
ok('no pictures is picture 0, not NaN', app.galleryStep(3, 1, 0) === 0);

head('a swipe and the arrow keys follow the reading direction');
ok('right to left: a finger moving right brings the next picture', app.swipeStep(80, true) === 1);
ok('  and moving left goes back', app.swipeStep(-80, true) === -1);
ok('left to right it is the other way round', app.swipeStep(-80, false) === 1 && app.swipeStep(80, false) === -1);
ok('right to left: ← is next', app.keyStep('ArrowLeft', true) === 1);
ok('  and → is back', app.keyStep('ArrowRight', true) === -1);
ok('left to right, mirrored', app.keyStep('ArrowLeft', false) === -1 && app.keyStep('ArrowRight', false) === 1);
ok('any other key is no step', app.keyStep('Enter', true) === 0 && app.keyStep('ArrowUp', true) === 0);

head('zooming keeps the spot under the finger where it was');
{
  const z0 = { s: 1, x: 0, y: 0 };
  const z1 = app.zoomAbout(z0, 2.5, 100, -40);
  /* A point p of the picture lands at s*p + pan. The one under (100, -40)
     before the zoom must still be under it after. */
  ok('from 1x to 2.5x', z1.s === 2.5 && near(2.5 * 100 + z1.x, 100) && near(2.5 * -40 + z1.y, -40),
     JSON.stringify(z1));
  const px = (-30 - z1.x) / 2.5;
  const py = (20 - z1.y) / 2.5;
  const z2 = app.zoomAbout(z1, 4, -30, 20);
  ok('and on from 2.5x to 4x, about another spot', near(4 * px + z2.x, -30) && near(4 * py + z2.y, 20),
     JSON.stringify(z2));
  const zc = app.zoomAbout(z0, 2, 0, 0);
  ok('zooming about the centre does not pan at all', near(zc.x, 0) && near(zc.y, 0));
}

head('a zoomed picture never pans past its edges');
{
  /* 400x300 at 2x is 800x600 on a 500x500 stage: 150px spare each side
     across, 50 up and down. */
  const c = app.clampPan({ s: 2, x: 999, y: -999 }, 400, 300, 500, 500);
  ok('it stops at the edge in both directions', c.x === 150 && c.y === -50, JSON.stringify(c));
  const inside = app.clampPan({ s: 2, x: -40, y: 20 }, 400, 300, 500, 500);
  ok('a pan inside the limits is left alone', inside.x === -40 && inside.y === 20);
  /* 1.2x is 480x360: smaller than the stage both ways, so it stays centred. */
  const small = app.clampPan({ s: 1.2, x: 30, y: -30 }, 400, 300, 500, 500);
  ok('a picture smaller than the stage stays centred', small.x === 0 && small.y === 0, JSON.stringify(small));
  ok('the scale itself is never changed', c.s === 2 && small.s === 1.2);
}

console.log('\n----------------------------------------\npassed: ' + pass + '    failed: ' + fail);
process.exit(fail > 0 ? 1 : 0);
