/* What goes on the tub decides what the shop advertises, so the filter is
   worth testing on its own. Everything else in hero-labels.js draws on a
   canvas and is checked by looking at it. */

import fs from 'node:fs';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const ROOT = new URL('../', import.meta.url);
const SRC = fileURLToPath(new URL('assets/js/hero-labels.js', ROOT));

/* In a browser `window.X` and the global `X` are the same slot. In a vm they
   are not unless window points back at the sandbox, and the file assigns
   window.SUPPEX then reads bare SUPPEX. */
const sandbox = { document: { createElement: () => ({ getContext: () => ({}) }) } };
sandbox.window = sandbox;
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(SRC, 'utf8'), sandbox);

const pick = sandbox.window.SUPPEX.heroLabels.pick;
const MAX = sandbox.window.SUPPEX.heroLabels.max;

let pass = 0, fail = 0;
const ok = (label, cond, extra = '') => {
  if (cond) { pass++; console.log('  ok   ' + label); }
  else { fail++; console.log('  FAIL ' + label + (extra ? '  --  ' + extra : '')); }
};

const p = (slug, price, compareAt, extra = {}) =>
  ({ slug, price, compareAt, onSale: compareAt > price, inStock: true, ...extra });

console.log('\n== only real savings reach the tub ==');
const mixed = [
  p('a', 100, 200),          // 50% off
  p('b', 900, 1000),         // 10% off
  p('c', 500, 0),            // no sale
  p('d', 500, 400),          // "was" below the price: not a saving
  p('e', 700, 1000),         // 30% off
];
const got = pick(mixed).map(x => x.slug);
ok('full-price products are left out', !got.includes('c'), got.join(','));
ok('a compare_at below the price is not a saving', !got.includes('d'), got.join(','));
ok('biggest discount first', got[0] === 'a', got.join(','));
ok('and the order runs down from there', got.join(',') === 'a,e,b', got.join(','));

console.log('\n== things that must never be advertised ==');
ok('out of stock is excluded',
   !pick([p('x', 100, 200, { inStock: false })]).length);
ok('a zero price is excluded',
   !pick([p('y', 0, 200)]).length);
ok('onSale false is excluded even with a compare_at',
   !pick([{ slug: 'z', price: 100, compareAt: 200, onSale: false, inStock: true }]).length);
ok('a null list does not throw', pick(null).length === 0);
ok('nor does a list of nulls', pick([null, undefined]).length === 0);

console.log('\n== the tub is not infinite ==');
const many = Array.from({ length: 20 }, (_, i) => p('s' + i, 100, 100 + (i + 1) * 10));
ok(`at most ${MAX} faces`, pick(many).length === MAX, String(pick(many).length));
ok('and they are the deepest discounts',
   pick(many)[0].slug === 's19', pick(many)[0].slug);

console.log('\n----------------------------------------');
console.log(`passed: ${pass}    failed: ${fail}`);
process.exit(fail > 0 ? 1 : 0);
