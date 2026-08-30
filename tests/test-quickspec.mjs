/* The hover panel decides what a shopper learns before deciding to click, and
   it sits on top of the product image - so the two things that can go wrong
   are showing the wrong facts and showing too many of them. The second one is
   not cosmetic: the panel is anchored to the foot of the image, so an
   over-budget panel grows upwards over the discount badge.

   Tested through productCard() rather than quickSpec() directly, because the
   panel has to land inside the media block and outside the price, and that is
   the part a refactor would break silently. */

import fs from 'node:fs';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const ROOT = new URL('../', import.meta.url);
const SRC = fileURLToPath(new URL('assets/js/ui.js', ROOT));

/* Only the slice of SUPPEX.config that a card reads. Mirrors config.js:
   Latin digits, Toman, ratings off - the shop has no reviews to show. */
const sandbox = {
  SUPPEX: {
    config: {
      productPage: 'product.html',
      flags: { showRatings: false },
      currency: {
        label: 'تومان',
        format: (v) => new Intl.NumberFormat('en-US').format(Math.round(v)),
      },
    },
  },
};
sandbox.window = sandbox;
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(SRC, 'utf8'), sandbox);

const ui = sandbox.SUPPEX.ui;

let pass = 0, fail = 0;
const ok = (label, cond, extra = '') => {
  if (cond) { pass++; console.log('  ok   ' + label); }
  else { fail++; console.log('  FAIL ' + label + (extra ? '  --  ' + extra : '')); }
};
const head = (t) => console.log('\n== ' + t + ' ==');

/* A product with everything, so each test can take things away. */
const full = (over = {}) => ({
  slug: 'whey', nameFa: 'وی پروتئین', name: 'Whey', brand: 'SUPPEX',
  price: 2450000, compareAt: 2980000, onSale: true, inStock: true, badges: [],
  image: '/x.jpg', variantLabel: 'وانیل · 900 گرم', categorySlug: 'protein',
  features: ['۲۴ گرم پروتئین در هر پیمانه', 'کنسانتره و ایزوله', 'بدون قند افزوده'],
  sizes: [{ label: '900 گرم' }, { label: '1800 گرم' }, { label: '2270 گرم' }],
  flavors: [{ name: 'وانیل' }, { name: 'شکلات' }, { name: 'قهوه' }, { name: 'کوکی' }],
  ...over,
});

/* The panel markup only - so a match cannot be satisfied by the card body. */
const panel = (p) => {
  const html = ui.productCard(p);
  const m = html.match(/<div class="pcard__spec"[\s\S]*?<\/div><\/div>/);
  return m ? m[0] : '';
};
const items = (p) => {
  const h = panel(p);
  return (h.match(/<li>|<div class="spec-row">/g) || []).length;
};

head('the panel carries the facts a listing page is asked for');
{
  const h = panel(full());
  ok('the headline spec survives', h.includes('۲۴ گرم پروتئین در هر پیمانه'));
  ok('the sizes are listed', h.includes('900 گرم · 1800 گرم · 2270 گرم'));
  ok('under a label a shopper reads', h.includes('حجم‌ها'));
  ok('the flavours are listed', h.includes('وانیل · شکلات · قهوه · کوکی'));
}

head('three items, never more - the panel grows upward over the badges');
{
  ok('a product with everything is capped at three', items(full()) === 3,
     String(items(full())));
  ok('the concrete rows win the budget',
     panel(full()).includes('حجم‌ها') && panel(full()).includes('طعم‌ها'));
  ok('and exactly one feature rides along',
     (panel(full()).match(/<li>/g) || []).length === 1);
  ok('the surviving feature is the headline one',
     panel(full()).includes('۲۴ گرم پروتئین') && !panel(full()).includes('بدون قند'));
  ok('with no rows, three features fill the budget',
     (panel(full({ sizes: [], flavors: [] })).match(/<li>/g) || []).length === 3);
  ok('a fourth feature is still dropped',
     items(full({ sizes: [], flavors: [],
                  features: ['a', 'b', 'c', 'd'] })) === 3);
}

head('long value lists are cut, not wrapped');
{
  const many = full({
    flavors: ['وانیل', 'شکلات', 'قهوه', 'کوکی', 'توت', 'موز'].map((n) => ({ name: n })),
  });
  const h = panel(many);
  ok('only four flavours are printed', h.includes('وانیل · شکلات · قهوه · کوکی'));
  ok('the rest are counted, not listed', h.includes('+2') && !h.includes('موز'), h);
  ok('exactly four is not given a tail',
     !panel(full()).includes('+0') && !panel(full()).includes('+'));
}

head('nothing is invented');
{
  ok('no features and no variants means no panel at all',
     panel(full({ features: [], sizes: [], flavors: [] })) === '');
  ok('a single size is not a choice, so no size row',
     !panel(full({ sizes: [{ label: '900 گرم' }], flavors: [] })).includes('حجم‌ها'));
  ok('a single flavour is not a choice either',
     !panel(full({ flavors: [{ name: 'وانیل' }] })).includes('طعم‌ها'));
  ok('servings show when there is one size to attach them to',
     panel(full({ sizes: [{ label: '900 گرم', servings: 30 }], flavors: [] }))
       .includes('تعداد وعده'));
  ok('and not when several sizes each have their own',
     !panel(full({ sizes: [{ label: 'a', servings: 30 }, { label: 'b', servings: 60 }] }))
       .includes('تعداد وعده'));
  ok('missing keys do not throw',
     panel({ slug: 's', nameFa: 'x', name: 'x', brand: 'b', price: 1, badges: [] }) === '');
  ok('blank features are not printed as empty bullets',
     items(full({ features: ['', '   ', 'واقعی'], sizes: [], flavors: [] })) === 1);
  ok('a null in the size list is skipped',
     panel(full({ sizes: [null, { label: '900 گرم' }, { label: '1800 گرم' }] }))
       .includes('900 گرم · 1800 گرم'));
}

head('it never costs a click');
{
  const html = ui.productCard(full());
  const spec = html.indexOf('pcard__spec');
  ok('the panel sits inside the media block',
     spec > html.indexOf('pcard__media') && spec < html.indexOf('pcard__body'));
  /* The corner fix is structural, not a value: .media carries the radius and
     the overflow: hidden, so the panel only ends on the image's curve while it
     is inside that element. Moving it back out returns the hard square. */
  ok('and inside the link, which is what clips it to the rounded corner',
     spec < html.indexOf('</a>'), String(spec) + ' vs ' + html.indexOf('</a>'));
  ok('the link keeps its own accessible name regardless of what is in there',
     /<a [^>]*aria-label="[^"]+"/.test(html));
  ok('so the price is outside it',
     html.indexOf('price__now') > html.indexOf('pcard__body'));
  ok('and so is the add button',
     html.indexOf('add-btn') > html.indexOf('pcard__body'));
  ok('it is hidden from screen readers, which have the product page',
     panel(full()).includes('aria-hidden="true"'));
}

head('the panel escapes what it prints');
{
  const h = panel(full({ features: ['<img src=x onerror=alert(1)>'], sizes: [], flavors: [] }));
  ok('a feature cannot inject markup', !h.includes('<img'), h);
  const s = panel(full({ sizes: [{ label: '<b>900' }, { label: '1800' }], flavors: [] }));
  ok('nor can a size label', !s.includes('<b>'), s);
  const f = panel(full({ flavors: [{ name: '"><script>' }, { name: 'x' }], sizes: [] }));
  ok('nor a flavour name', !f.includes('<script'), f);
}

console.log('\n----------------------------------------');
console.log(`passed: ${pass}    failed: ${fail}`);
process.exit(fail ? 1 : 0);
