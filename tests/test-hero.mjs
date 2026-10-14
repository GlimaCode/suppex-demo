/* ===========================================================================
   The top-deals hero, and the struck price on the product page
   ---------------------------------------------------------------------------
   Three pure helpers in app.js and one renderer in ui.js:

     pickHeroDeals()  which three products stand where the 3D tub used to
     dealClock()      how long each of them stands alone, and what stops it
     beforePrice()    which «before» figure, if any, the product page strikes
     ui.dealSlide()   one product of the hero

   pickHeroDeals decides what a visitor sees first on the home page, so the
   rules it keeps are held here: ticked first, biggest discount first, never
   more than three, never anything that cannot be bought.

   beforePrice exists because the Cell-Tech page never showed its discount:
   the product is 7,500,000 -> 6,950,000, but its size and both combinations
   carry no «before» price of their own, and the page redrew the price block
   from those and dropped it. The fallback it adds must not go further than
   that - a struck price nobody set is a discount claim nobody made.
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
    sandbox tests/test-checkout.mjs uses. */
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
  return { app: sb.SUPPEX.app, ui: sb.SUPPEX.ui, config: sb.SUPPEX.config };
}

const { app, ui, config } = bootApp();

/** A product in the shape the list API sends, with sensible defaults. */
const P = (slug, extra = {}) => Object.assign({
  slug,
  nameFa: slug,
  price: 1000000,
  compareAt: null,
  inStock: true,
  onSale: false,
  discountPercent: 0,
  badges: [],
  image: '',
}, extra);
const slugs = (list) => list.map((p) => p.slug).join(',');

head('the helpers are exported for this file');
ok('pickHeroDeals', typeof app.pickHeroDeals === 'function');
ok('beforePrice', typeof app.beforePrice === 'function');
ok('dealClock', typeof app.dealClock === 'function');
ok('ui.dealSlide', typeof ui.dealSlide === 'function');


head('ticked products come first, biggest discount first, three at most');
{
  const ticked = [
    P('a', { badges: ['topdeal'], onSale: true, discountPercent: 5 }),
    P('b', { badges: ['topdeal'], onSale: true, discountPercent: 20 }),
    P('c', { badges: ['topdeal'] }),
    P('d', { badges: ['topdeal'], onSale: true, discountPercent: 12 }),
  ];
  const picked = app.pickHeroDeals(ticked, []);
  ok('never more than three', picked.length === 3, String(picked.length));
  /* Biggest first, which in a right-to-left row is also the first read. */
  ok('ordered by discount, largest first', slugs(picked) === 'b,d,a', slugs(picked));
  ok('the owner\'s list is not reordered in place',
     slugs(ticked) === 'a,b,c,d', slugs(ticked));
  /* The owner chose it; a missing «before» price is not a reason to overrule
     them, it just means there is no struck price on the tile. */
  ok('a ticked product with no discount still gets a place when there is room',
     slugs(app.pickHeroDeals([P('c', { badges: ['topdeal'] })], [])) === 'c');
  ok('and the badge sits beside the others, not instead of them',
     slugs(app.pickHeroDeals([P('m', { badges: ['bestseller', 'topdeal'] })], [])) === 'm');
}


head('nothing that cannot be bought stands in the window');
{
  const soldOut = P('gone', { badges: ['topdeal'], inStock: false, onSale: true, discountPercent: 40 });
  const unpriced = P('zero', { badges: ['topdeal'], price: 0 });
  const fine = P('fine', { badges: ['topdeal'] });
  ok('a sold-out product is skipped, however big its discount',
     slugs(app.pickHeroDeals([soldOut, fine], [])) === 'fine');
  ok('an unpriced one too', slugs(app.pickHeroDeals([unpriced, fine], [])) === 'fine');
}


head('an API that ignored ?badge= cannot make everything count as ticked');
{
  /* The server filters on the badge; so does this, in case it ever stops. */
  const everything = [
    P('untick', { onSale: true, discountPercent: 30 }),
    P('tick', { badges: ['topdeal'] }),
  ];
  ok('only the product that carries the badge',
     slugs(app.pickHeroDeals(everything, [])) === 'tick');
}


head('nothing ticked falls back to the biggest discounts on offer');
{
  const all = [
    P('p1', { onSale: true, discountPercent: 8 }),
    P('p2'),
    P('p3', { onSale: true, discountPercent: 25 }),
    P('p4', { onSale: true, discountPercent: 15, inStock: false }),
  ];
  ok('discounted, in stock, largest first',
     slugs(app.pickHeroDeals([], all)) === 'p3,p1', slugs(app.pickHeroDeals([], all)));
  ok('the same when every ticked product is sold out',
     slugs(app.pickHeroDeals([P('z', { badges: ['topdeal'], inStock: false })], all)) === 'p3,p1');
  /* The static poster stays underneath for exactly this case. */
  ok('nothing on sale and nothing ticked leaves the window empty',
     app.pickHeroDeals([], [P('q1'), P('q2')]).length === 0);
  ok('and a missing list is an empty one, not an exception',
     app.pickHeroDeals(null, undefined).length === 0);
}


head('the struck price on the product page');
{
  const cell = { price: 6950000, compareAt: 7500000 };
  ok('Cell-Tech: nothing on the size or combination, so the product\'s own figure',
     app.beforePrice(cell, 6950000, null) === 7500000,
     String(app.beforePrice(cell, 6950000, null)));
  ok('a selection\'s own «before» price wins over the product\'s',
     app.beforePrice(cell, 6950000, 8000000) === 8000000);
  /* The product's figure belongs to the product's headline price only. */
  /* 5,990,000 is under the product's 7,500,000, so only the price-point rule
     stands between it and a struck price nobody set for it. */
  ok('it is not lent to a different price point',
     app.beforePrice(cell, 5990000, null) === null,
     String(app.beforePrice(cell, 5990000, null)));
  ok('  even one it would still sit above',
     app.beforePrice({ price: 6950000, compareAt: 12000000 }, 9990000, null) === null);
  ok('a selection\'s figure at or below its price is ignored, not struck',
     app.beforePrice({ price: 100, compareAt: null }, 100, 100) === null);
  ok('a product figure below the price is never shown',
     app.beforePrice({ price: 100, compareAt: 90 }, 100, null) === null);
  ok('no product at all is no «before» price',
     app.beforePrice(null, 100, null) === null);
}


head('one product of the hero');
{
  const cell = P('cell-tech', {
    nameFa: 'کراتین سل تک', brand: 'MuscleTech', price: 6950000, compareAt: 7500000,
    onSale: true, discountPercent: 7, image: '/uploads/x.webp',
  });
  const html = ui.dealSlide(cell, 0, 3);
  const page = config.productPage || 'product.html';
  /* The WAI-ARIA carousel pattern's slide: a group, called a slide, and
     numbered, so a screen reader says where in the rotation it is. */
  ok('it is a numbered slide', html.indexOf('role="group"') !== -1
     && html.indexOf('aria-roledescription="اسلاید"') !== -1 && html.indexOf('aria-label="۱ از ۳"') !== -1);
  ok('the first one is the one showing', html.indexOf('<div class="deal is-on"') === 0, html.slice(0, 40));
  const second = ui.dealSlide(cell, 1, 3);
  ok('  and the others are not', second.indexOf('<div class="deal"') === 0
     && second.indexOf('aria-label="۲ از ۳"') !== -1);
  ok('the whole product links to its page',
     html.indexOf('<a class="deal__link" href="' + page + '?p=cell-tech"') !== -1);
  /* The hero is a shop window: flavour and size get chosen on the product
     page before anything goes in the basket. */
  ok('it carries no add-to-cart button', html.indexOf('add-btn') === -1 && html.indexOf('data-add') === -1);
  ok('the photo is not announced a second time', html.indexOf('alt=""') !== -1);
  ok('the discount is on it, in Persian digits', html.indexOf('deal__off') !== -1
     && html.indexOf('۷\u066A') !== -1 && html.indexOf('تخفیف') !== -1);
  ok('the struck price comes from the shared price block', html.indexOf('price__was') !== -1);
  ok('  and so does the stacked unit', html.indexOf('data-head=') !== -1);
  ok('the brand sits above the name, in the box that holds both to the price',
     html.indexOf('deal__title') < html.indexOf('deal__brand')
     && html.indexOf('deal__brand') < html.indexOf('deal__name'));

  const plain = ui.dealSlide(P('plain', { price: 5000000 }), 0, 1);
  ok('no discount sticker where there is no discount', plain.indexOf('deal__off') === -1);
  ok('no brand line where there is no brand', plain.indexOf('deal__brand') === -1);
  ok('the name is escaped', ui.dealSlide(P('x', { nameFa: '<b>x</b>' }), 0, 1).indexOf('&lt;b&gt;') !== -1);
  ok('  and so is the brand', ui.dealSlide(P('x', { brand: '<i>b</i>' }), 0, 1).indexOf('&lt;i&gt;') !== -1);
  ok('the slug is encoded into the link', ui.dealSlide(P('a b'), 0, 1).indexOf('?p=a%20b"') !== -1);
}


head('the hero clock: five or six seconds a product, and a way to stop it');
{
  ok('each product stands for five to six seconds, as the owner asked',
     app.HERO_DEALS_MS >= 5000 && app.HERO_DEALS_MS <= 6000, String(app.HERO_DEALS_MS));

  /* Time moves only when the test says so. */
  let now = 0;
  let seq = 0;
  const pending = new Map();
  const timer = {
    now: () => now,
    set: (fn, t) => { seq += 1; pending.set(seq, { fn, at: now + t }); return seq; },
    clear: (id) => { pending.delete(id); },
  };
  const advance = (ms) => {
    const until = now + ms;
    for (;;) {
      let due = null;
      for (const [id, p] of pending) { if (p.at <= until && (!due || p.at < due.p.at)) { due = { id, p }; } }
      if (!due) { break; }
      pending.delete(due.id);
      now = due.p.at;
      due.p.fn();
    }
    now = until;
  };

  /* rotateDeals() shows the next product on a tick, and a product that has
     just arrived resets the clock: the same two calls here. */
  let ticks = 0;
  const clock = app.dealClock(5500, () => { ticks += 1; clock.reset(); }, timer);
  clock.hold('user', false, true);            // what rotateDeals() does to start

  advance(5499);
  ok('nothing moves before the time is up', ticks === 0, String(ticks));
  advance(1);
  ok('the next product at exactly 5.5s', ticks === 1, String(ticks));
  advance(5500);
  ok('  and the one after it 5.5s later', ticks === 2, String(ticks));
  ok('only ever one timer waiting', pending.size === 1, String(pending.size));

  advance(2000);
  clock.hold('hover', true);
  advance(20000);
  ok('a mouse resting on the box holds it', ticks === 2 && !clock.running(), String(ticks));
  ok('  with no timer left running', pending.size === 0, String(pending.size));
  clock.hold('hover', false);
  advance(3499);
  ok('  and nothing moves a moment early once the mouse has gone', ticks === 2, String(ticks));
  advance(1);
  ok('  leaving gives back the time that was left - 3.5s, not a fresh 5.5s', ticks === 3, String(ticks));

  clock.hold('hover', true);
  clock.hold('away', true);
  clock.hold('hover', false);
  advance(20000);
  ok('one reason letting go does not start it while another holds', ticks === 3, String(ticks));
  clock.hold('away', false);
  advance(5500);
  ok('  the last one letting go does', ticks === 4, String(ticks));

  advance(3000);
  clock.hold('user', true);
  advance(60000);
  ok('the pause button stops it for good', ticks === 4 && pending.size === 0, String(ticks));
  clock.hold('user', false, true);
  advance(5499);
  ok('play gives the product in front a full turn', ticks === 4, String(ticks));
  advance(1);
  ok('  and then moves on', ticks === 5, String(ticks));

  advance(4000);
  clock.reset();
  advance(5499);
  ok('a product picked by hand gets its full time too', ticks === 5, String(ticks));
  advance(1);
  ok('  and then the rotation carries on', ticks === 6, String(ticks));
}

console.log('\n----------------------------------------\npassed: ' + pass + '    failed: ' + fail);
process.exit(fail > 0 ? 1 : 0);
