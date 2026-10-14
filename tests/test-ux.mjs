/* ===========================================================================
   The card's key fact - the browser's side
   ---------------------------------------------------------------------------
   ui.js's keyFact() writes the one line under a product's name on the cards
   the script draws; lib/products.php's product_key_fact() writes it on the
   cards the server draws, which the script then replaces. tests/key-facts.json
   is the table both are held to (tests/test-ux.php is the other half): a line
   that differs between the two jumps when the script takes over.
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

/* ui.js in the sandbox tests/test-gallery.mjs uses. */
function loadUi() {
  const mem = {};
  const noop = () => {};
  const el = { addEventListener: noop, classList: { toggle: noop, add: noop, remove: noop } };
  const sb = {
    console, setTimeout, clearTimeout,
    document: { documentElement: {}, addEventListener: noop, querySelector: () => null, querySelectorAll: () => [], body: el },
    localStorage: { getItem: (k) => (k in mem ? mem[k] : null), setItem: (k, v) => { mem[k] = String(v); }, removeItem: (k) => { delete mem[k]; } },
    addEventListener: noop,
    matchMedia: () => ({ matches: false, addEventListener: noop }),
  };
  sb.window = sb;
  vm.createContext(sb);
  for (const f of ['config.js', 'store.js', 'ui.js']) {
    vm.runInContext(read('assets/js/' + f), sb);
  }
  return sb.SUPPEX.ui;
}
const ui = loadUi();

head('keyFact(): the same rule as the server');
const cases = JSON.parse(read('tests/key-facts.json'));
ok('the shared table is there', Array.isArray(cases) && cases.length >= 10);
for (const c of cases) {
  const got = ui.keyFact(c.features === null ? {} : { features: c.features });
  ok('  ' + c.why, got === c.fact, JSON.stringify(got));
}

head('on the card');
const base = { slug: 'x', nameFa: 'وی', name: 'Whey', brand: 'MuscleTech', price: 1000, inStock: true,
  image: 'assets/images/products/x.svg', badges: [] };
const card = ui.productCard(Object.assign({}, base, { features: ['برند: MuscleTech', 'حاوی 25 گرم پروتئین در هر سروینگ'] }));
ok('under the name, before the size', /<\/h3><p class="pcard__fact">۲۵ گرم پروتئین در هر سروینگ<\/p><div class="pcard__variant">/.test(card),
  card.slice(card.indexOf('</h3>') - 20, card.indexOf('</h3>') + 120));
ok('escaped like the rest of the card',
  ui.productCard(Object.assign({}, base, { features: ['<b>&"x"'] })).includes('<p class="pcard__fact">&lt;b&gt;&amp;&quot;x&quot;</p>'));
ok('no line at all when there is nothing to say', !ui.productCard(Object.assign({}, base, { features: ['برند: X'] })).includes('pcard__fact')
  && !ui.productCard(base).includes('pcard__fact'));

head('stars on the card - real reviews only');
/* lib/reviews.php: approved reviews by buyers. A product without one gets no
   stars, not five empty ones. */
ok('switched on in config.js', ui.ratingHtml({ rating: 4.5, reviewCount: 2 }) !== '');
ok('  nothing for a product with no review', ui.ratingHtml({ rating: 0, reviewCount: 0 }) === ''
  && ui.ratingHtml({}) === '' && ui.ratingHtml(null) === '');
const stars = ui.ratingHtml({ rating: 4.5, reviewCount: 12 });
ok('  the average and the count, in Persian digits', stars.includes('<span class="num">۴.۵</span>')
  && stars.includes('(<span class="num">۱۲</span>)'), stars.slice(-160));
ok('  on the card', ui.productCard(Object.assign({}, base, { rating: 5, reviewCount: 1 })).includes('class="rating"')
  && !ui.productCard(base).includes('class="rating"'));

console.log('\n----------------------------------------\npassed: ' + pass + '    failed: ' + fail);
process.exit(fail > 0 ? 1 : 0);
