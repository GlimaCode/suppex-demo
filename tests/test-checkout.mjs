/* The two places where the browser and the server can disagree about money.
   ---------------------------------------------------------------------------
   A card-to-card shop has no authorisation step. Whatever number the customer
   is shown is the number they transfer, and if the server recorded a different
   one there is nothing to adjust afterwards - it becomes a refund conversation.
   So these two are pinned:

   1. The cart's total must equal what the server would record, including when a
      line has gone out of stock or been deleted since it was added.
   2. The payment panel must never show a card number the server did not send. */

import fs from 'node:fs';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const ROOT = new URL('../', import.meta.url);
const JS = (n) => fileURLToPath(new URL('assets/js/' + n, ROOT));

let pass = 0, fail = 0;
const ok = (label, cond, extra = '') => {
  if (cond) { pass++; console.log('  ok   ' + label); }
  else { fail++; console.log('  FAIL ' + label + (extra ? '  --  ' + extra : '')); }
};
const head = (t) => console.log('\n== ' + t + ' ==');

/** A fresh sandbox per test: the store keeps module-level state. */
function boot(files) {
  const mem = {};
  const sb = {
    console,
    document: { documentElement: {} },
    localStorage: {
      getItem: (k) => (k in mem ? mem[k] : null),
      setItem: (k, v) => { mem[k] = String(v); },
      removeItem: (k) => { delete mem[k]; },
    },
  };
  sb.window = sb;
  vm.createContext(sb);
  for (const f of files) { vm.runInContext(fs.readFileSync(JS(f), 'utf8'), sb); }
  return sb.SUPPEX;
}

head('the cart total is the total the server would record');
{
  const S = boot(['config.js', 'store.js']).store;
  S.add({ slug: 'multi-sport',  nameFa: 'مولتی',  price: 890000,  qty: 1 });
  S.add({ slug: 'serious-bulk', nameFa: 'بالک',   price: 5450000, qty: 1 });
  S.add({ slug: 'ghost',        nameFa: 'حذف‌شده', price: 300000,  qty: 1 });

  /* Exactly what ?r=prices answers: one in stock, one sold out, and the deleted
     one simply absent - the endpoint omits slugs it does not know rather than
     reporting them gone, which is what made a deleted line invisible. */
  S.syncPrices([
    { slug: 'multi-sport',  available: true,  price: 890000 },
    { slug: 'serious-bulk', available: false, price: 5450000 },
  ]);

  const s = S.snapshot();
  ok('a sold-out line is out of the subtotal', s.subtotal === 890000, String(s.subtotal));
  ok('a deleted line is out of it too - absent from the response means gone',
     s.subtotal === 890000);
  ok('so the free-shipping threshold is judged on real money', s.shipping === 65000,
     String(s.shipping));
  ok('and the total matches what the server records', s.total === 955000, String(s.total));
  ok('both blocked lines are counted', s.blocked === 2, String(s.blocked));
  /* They stay visible: a line that vanishes silently is worse than one struck
     through, because the shopper cannot tell what happened to their basket. */
  ok('but they stay in the drawer so the shopper sees what was dropped',
     s.items.length === 3, String(s.items.length));

  const txt = S.asOrderText();
  ok('the order message does not quote the sold-out line', !txt.includes('بالک'));
  ok('nor the deleted one', !txt.includes('حذف‌شده'));
  ok('and it does list the one being bought', txt.includes('مولتی'));
  ok('the payload carries only the orderable line',
     S.asOrderPayload('telegram').items.length === 1);
}

head('an ordinary cart is untouched by all that');
{
  const S = boot(['config.js', 'store.js']).store;
  S.add({ slug: 'a', nameFa: 'الف', price: 400000, qty: 2 });
  S.syncPrices([{ slug: 'a', available: true, price: 400000 }]);
  const s = S.snapshot();
  ok('subtotal is qty x price', s.subtotal === 800000, String(s.subtotal));
  ok('nothing is blocked', s.blocked === 0);
  ok('shipping is charged below the threshold', s.shipping === 65000);
  ok('the line is in the payload', S.asOrderPayload('telegram').items.length === 1);
}

head('a price that moved is applied, not blocked');
{
  const S = boot(['config.js', 'store.js']).store;
  S.add({ slug: 'a', nameFa: 'الف', price: 400000, qty: 1 });
  const changes = S.syncPrices([{ slug: 'a', available: true, price: 470000 }]);
  ok('the change is reported to the shopper', changes.length === 1);
  ok('the new price is used', S.snapshot().subtotal === 470000);
  ok('and the line is still orderable', S.snapshot().blocked === 0);
}

head('the payment panel never invents a card number');
{
  const ui = boot(['config.js', 'ui.js']).ui;
  const snap = { items: [], subtotal: 0, shipping: 0, total: 0 };
  const ch = { id: 'telegram', label: 'تلگرام' };
  const digitsIn = (h) => (h.match(/[\d\s]{14,25}/g) || [])
    .map((x) => x.trim()).filter((x) => x.replace(/\D/g, '').length >= 12);

  /* The state the database is actually in before the owner sets a card. */
  const empty = ui.paymentPanel(snap, ch, null, { payment: { cardNumber: '', cardHolder: '', bankName: '' } });
  ok('an unset card shows no digits at all', digitsIn(empty).length === 0, JSON.stringify(digitsIn(empty)));
  ok('and no copy button to copy them with', !empty.includes('data-copy-card'));
  ok('it tells the customer not to transfer yet', empty.includes('واریز نکنید'));
  /* config.js used to hold 6037 9911 0000 0000, a live Bank Melli BIN. An empty
     string is falsy, so it was what a shop with no card configured displayed. */
  ok('the config placeholder cannot come back', !empty.includes('6037'));

  const noServer = ui.paymentPanel(snap, ch, null, null);
  ok('prototype mode shows no number either', digitsIn(noServer).length === 0);

  const set = ui.paymentPanel(snap, ch, null, {
    payment: { cardNumber: '6219861012345678', cardHolder: 'علی رضایی', bankName: 'بانک سامان' },
  });
  ok('a real card is shown, grouped in fours', set.includes('6219 8610 1234 5678'));
  ok('with its holder', set.includes('علی رضایی'));
  ok('and a copy button', set.includes('data-copy-card'));

  /* Short or malformed is treated as absent rather than rendered half-formed. */
  const short = ui.paymentPanel(snap, ch, null, { payment: { cardNumber: '1234' } });
  ok('a too-short card is treated as no card', digitsIn(short).length === 0);
}

console.log('\n----------------------------------------');
console.log(`passed: ${pass}    failed: ${fail}`);
process.exit(fail ? 1 : 0);
