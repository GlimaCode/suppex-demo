/* ===========================================================================
   The page's own data, and the lighter pictures - the browser's side
   ---------------------------------------------------------------------------
   repo.js answers from window.SUPPEX_BOOT (lib/boot.php) before it thinks of
   the network, shares a request that is already on its way, and hands every
   caller a copy of its own. ui.js offers the browser the lighter WebP copies
   of a photograph (lib/images.php) and gives a thumbnail the copy its size.

   bootKey() is held to tests/boot-keys.json, the table tests/test-speed.php
   holds boot_key() to: a key one character off is a page that quietly goes
   back to the network, which on this host is what took nineteen seconds.
   =========================================================================== */
import fs from 'node:fs';
import vm from 'node:vm';

const ROOT = new URL('../', import.meta.url);
const read = (f) => fs.readFileSync(new URL(f, ROOT), 'utf8');

let pass = 0;
let fail = 0;
const ok = (label, cond, extra = '') => {
  if (cond) { pass++; console.log('  ok   ' + label); }
  else { fail++; console.log('  FAIL ' + label + (extra ? '  --  ' + extra : '')); }
};
const head = (t) => console.log('\n== ' + t + ' ==');
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

/** The scripts in a sandbox with just enough of a browser for them to load -
    the one tests/test-gallery.mjs uses - and a fetch that is counted. */
function sandbox(files, boot) {
  const mem = {};
  const noop = () => {};
  const el = { addEventListener: noop, classList: { toggle: noop, add: noop, remove: noop } };
  const calls = [];
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
    respond: (url) => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ from: 'network', url }) }),
  };
  sb.fetch = (url) => { calls.push(String(url)); return sb.respond(String(url)); };
  sb.window = sb;
  if (boot !== undefined) { sb.SUPPEX_BOOT = boot; }
  vm.createContext(sb);
  for (const f of files) {
    vm.runInContext(read('assets/js/' + f), sb);
  }
  return { S: sb.SUPPEX, calls, sb };
}

/* ------------------------------------------------------------------------ */
head('bootKey(): the same rule as lib/boot.php');
const { S: S0 } = sandbox(['config.js', 'catalog.js', 'repo.js'], {});
const cases = JSON.parse(read('tests/boot-keys.json'));
ok('the shared table is there', Array.isArray(cases) && cases.length >= 10);
for (const c of cases) {
  const got = S0.repo.bootKey(c.route, c.params);
  ok('  ' + c.why, got === c.key, got + '  !=  ' + c.key);
}

/* ------------------------------------------------------------------------ */
head('an answer the page carries costs nothing');
const BOOT = {
  config: { shop: 'boot' },
  'products?limit=8': [{ slug: 'a', nameFa: 'الف', price: 100 }],
  'product?slug=whey-original': { slug: 'whey-original', nameFa: 'وی', price: 200 },
};
const one = sandbox(['config.js', 'catalog.js', 'repo.js'], BOOT);
const r1 = await one.S.repo.getProducts({ limit: 8 });
ok('the list comes from the page', same(r1, BOOT['products?limit=8']), JSON.stringify(r1));
ok('  without a request', one.calls.length === 0, one.calls.join(' '));
r1[0].slug = 'changed by a renderer';
const r2 = await one.S.repo.getProducts({ limit: 8 });
ok('  and each caller gets its own copy', r2[0].slug === 'a' && BOOT['products?limit=8'][0].slug === 'a');
const pr = await one.S.repo.getProduct('whey-original');
ok('the product, by the slug in the address', pr && pr.slug === 'whey-original' && one.calls.length === 0);
/* Parameters the page did not prepare for still go to the server. */
await one.S.repo.getProducts({ limit: 8, category: 'protein' });
ok('a call the page did not prepare for goes to the server as before',
  one.calls.length === 1 && /[?&]r=products\b/.test(one.calls[0]) && /[?&]category=protein\b/.test(one.calls[0]),
  one.calls.join(' '));

/* ------------------------------------------------------------------------ */
head('a request already on its way is shared');
const two = sandbox(['config.js', 'catalog.js', 'repo.js'], {});
const [a1, a2] = await Promise.all([two.S.repo.getProducts({ limit: 99 }), two.S.repo.getProducts({ limit: 99 })]);
ok('two callers, one request', two.calls.length === 1, two.calls.join(' '));
ok('  both answered, each with its own copy', same(a1, a2) && a1 !== a2 && a1.from === 'network');
await two.S.repo.getProducts({ limit: 99 });
ok('  and once it is back, the next call asks again - nothing is cached for ever', two.calls.length === 2);

let failed = false;
two.sb.respond = () => Promise.resolve({ ok: false, status: 500, json: () => Promise.resolve({}) });
try { await two.S.repo.getProducts({ limit: 7 }); } catch (e) { failed = true; }
ok('a failed request fails its callers', failed);
two.sb.respond = (url) => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ url }) });
const again = await two.S.repo.getProducts({ limit: 7 });
ok('  and is not remembered: the next call tries again', two.calls.length === 4 && again && again.url);

const none = sandbox(['config.js', 'catalog.js', 'repo.js']);
await none.S.repo.getProducts({ limit: 8 });
ok('a page with no data at all works as it always did', none.calls.length === 1);

/* ------------------------------------------------------------------------ */
head('the lighter copies of a photograph');
const { S: U } = sandbox(['config.js', 'store.js', 'ui.js']);
const ui = U.ui;
const src = '/uploads/products/2026/09/x.jpg';
const imgv = { [src]: [160, 320, 640, 960, 1221] };
ok('srcset names each copy and its width',
  ui.srcsetOf(src, imgv) === '/uploads/products/2026/09/x-w160.webp 160w, /uploads/products/2026/09/x-w320.webp 320w, '
    + '/uploads/products/2026/09/x-w640.webp 640w, /uploads/products/2026/09/x-w960.webp 960w, '
    + '/uploads/products/2026/09/x-w1221.webp 1221w', ui.srcsetOf(src, imgv));
ok('  a PNG\'s copies are WebP too', ui.srcsetOf('/uploads/p/y.png', { '/uploads/p/y.png': [160] }) === '/uploads/p/y-w160.webp 160w');
ok('  a picture without copies gets no srcset, and is used as it is',
  ui.srcsetAttr('assets/images/products/whey-original.svg', {}, 'S') === '' && ui.srcsetAttr(src, undefined, 'S') === '');
ok('  with them, srcset and sizes', ui.srcsetAttr(src, { [src]: [160] }, 'S') === ' srcset="/uploads/products/2026/09/x-w160.webp 160w" sizes="S"');
ok('a thumbnail takes the smallest copy that is sharp at its size', ui.thumbSrc(src, imgv, 160) === '/uploads/products/2026/09/x-w160.webp'
  && ui.thumbSrc(src, { [src]: [320, 640] }, 160) === '/uploads/products/2026/09/x-w320.webp');
ok('  and the original when there is none', ui.thumbSrc(src, null, 160) === src && ui.thumbSrc(src, { [src]: [120] }, 160) === src);

const card = ui.productCard({
  slug: 'x', nameFa: 'وی', name: 'Whey', brand: 'SUPPEX', price: 1000, inStock: true,
  image: src, imgv: { [src]: [160, 320] }, badges: [],
});
ok('a product card offers the copies, sized for the grid',
  card.includes('srcset="/uploads/products/2026/09/x-w160.webp 160w, /uploads/products/2026/09/x-w320.webp 320w"')
  && card.includes('sizes="(min-width: 1100px) 270px, (min-width: 700px) 31vw, 47vw"'), card.slice(0, 400));
ok('  decoded off the main thread', card.includes('decoding="async"'));
const plain = ui.productCard({ slug: 'y', nameFa: 'ی', name: 'Y', brand: '', price: 1, inStock: true, image: 'assets/images/products/y.svg', badges: [] });
ok('  and a card without copies is the card it always was', !plain.includes('srcset='));

/* The deals box: index.php draws these slides (hero_deal_slide() in
   lib/products.php) and app.js keeps them, so they must be what dealSlide()
   writes, byte for byte - tests/test-speed.php holds the server to the same
   file. */
const slideCases = JSON.parse(read('tests/deal-slide.json'));
for (const c of slideCases) {
  ok('dealSlide() still writes the shared slide: ' + c.why, ui.dealSlide(c.product, c.i, c.total) === c.html);
}

/* The server draws the product photograph with the sizes the script uses,
   or the browser fetches the picture a second time when the script takes
   over. */
const productPhp = read('product.php');
ok('the product photograph\'s sizes are product.php\'s, character for character',
  typeof ui.SIZES_MAIN === 'string' && productPhp.includes("image_srcset_attr($pImage, '" + ui.SIZES_MAIN + "')"));

console.log('\n----------------------------------------\npassed: ' + pass + '    failed: ' + fail);
process.exit(fail > 0 ? 1 : 0);
