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


/** app.js on top of the store, with just enough DOM for it to load. It only
    touches the document from inside its controllers; shipping text nodes can
    be supplied to verify the settings merge on the rendered text as well. */
function bootApp(shippingNodes = {}) {
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
      querySelectorAll: (selector) => shippingNodes[selector] || [],
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
  sb.SUPPEX.store.init();
  return { app: sb.SUPPEX.app, store: sb.SUPPEX.store, config: sb.SUPPEX.config };
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

head('a cart holding more than the shop has is trimmed, not refused at the end');
{
  /* Caught here rather than at the order endpoint. The endpoint refuses it
     correctly, but by then the shopper has typed their name, phone, address
     and postal code - and being sent back to edit the basket is where an order
     stops being placed. */
  const S = boot(['config.js', 'store.js']).store;
  S.add({ slug: 'a', nameFa: 'الف', price: 400000, qty: 3 });
  const changes = S.syncPrices([{ slug: 'a', available: true, price: 400000, stock: 1 }]);
  ok('the shopper is told', changes.length === 1);
  ok('  what they asked for', changes[0].qtyFrom === 3, String(changes[0].qtyFrom));
  ok('  and what they are getting', changes[0].qtyTo === 1, String(changes[0].qtyTo));
  ok('the quantity is cut to what exists', S.snapshot().items[0].qty === 1);
  ok('the total follows it down', S.snapshot().subtotal === 400000);
  ok('and the line is still orderable', S.snapshot().blocked === 0);
}

{
  /* Zero is a number, not an absence. A cart line for something that has run
     out entirely must be blocked, not trimmed to nothing. */
  const S = boot(['config.js', 'store.js']).store;
  S.add({ slug: 'a', nameFa: 'الف', price: 400000, qty: 2 });
  const changes = S.syncPrices([{ slug: 'a', available: true, price: 400000, stock: 0 }]);
  ok('a line with none left is reported as gone', changes[0].gone === true);
  ok('  and does not reach the total', S.snapshot().subtotal === 0);
  ok('  and is counted as blocked so the drawer can say why', S.snapshot().blocked === 1);
  ok('  and is left out of the order payload',
     S.asOrderPayload('telegram').items.length === 0);
}

{
  /* Uncounted is the state every product ships in, so the quiet path is the
     one that must not move. */
  const S = boot(['config.js', 'store.js']).store;
  S.add({ slug: 'a', nameFa: 'الف', price: 400000, qty: 4 });
  const changes = S.syncPrices([{ slug: 'a', available: true, price: 400000 }]);
  ok('an uncounted product is left completely alone', changes.length === 0);
  ok('  keeping its quantity', S.snapshot().items[0].qty === 4);
}

{
  /* A size carries its own count, the same way it carries its own price. */
  const S = boot(['config.js', 'store.js']).store;
  S.add({ slug: 'a', nameFa: 'الف', price: 400000, qty: 3, sizeId: '900' });
  S.syncPrices([{ slug: 'a', available: true, price: 400000, stock: 50,
                  sizes: [{ id: '900', price: 400000, stock: 2 }] }]);
  ok('a size carries its own count, and it wins', S.snapshot().items[0].qty === 2,
     String(S.snapshot().items[0].qty));
}

head('shipping announcements follow the same settings as the cart');
for (const c of JSON.parse(fs.readFileSync(new URL('tests/shipping-copy.json', ROOT), 'utf8'))) {
  const announcement = { innerHTML: '' }, policy = { innerHTML: '' };
  const { app, store: S } = bootApp({ '[data-shipping-announcement]': [announcement], '[data-shipping-policy]': [policy] });
  app.mergeRemoteConfig({ freeShippingThreshold: c.threshold, shippingFlatRate: c.flat });
  const text = (html) => html.replace(/<[^>]*>/g, '');
  ok('announcement reflects ' + c.threshold + '/' + c.flat, text(announcement.innerHTML) === c.announcement);
  ok('policy reflects ' + c.threshold + '/' + c.flat, text(policy.innerHTML) === c.policy);
  S.add({slug: 'shipping-check', nameFa: 'آزمایشی', price: 1000000, qty: 1});
  ok('below threshold the cart charges the configured flat rate', S.snapshot().shipping === c.flat);
  if (c.threshold > 0) {
    S.add({slug: 'shipping-more', nameFa: 'آزمایشی', price: c.threshold, qty: 1});
    ok('above threshold the cart is free', S.snapshot().shipping === 0);
  }
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


head('the settings page must not empty the checkout');
{
  /* This one shipped. settings_shop_config() marks a channel configured by
     giving it a url and sends no `enabled` flag; store.channels() keeps a row
     only when `enabled` is truthy. The merge counted the raw rows, saw two,
     swapped them in - and the store's filter then dropped both, leaving the
     drawer with no way to send the order at all. Driven through the real
     merge, not a copy of it, because a copy is what missed it. */
  const sb = bootApp();
  const { app, store: S, config } = sb;

  const bundled = S.channels().length;
  ok('the bundled config ships usable channels', bundled > 0, String(bundled));

  /* Exactly what /api/index.php?r=config returns today. */
  app.mergeRemoteConfig({
    freeShippingThreshold: 2000000,
    shippingFlatRate: 70000,
    channels: [
      { id: 'telegram', label: 'تلگرام', url: 'https://t.me/shop', prefillParam: 'text' },
      { id: 'bale', label: 'بله', url: 'https://ble.ir/shop', prefillParam: 'text' },
    ],
  });

  const after = S.channels();
  ok('the checkout still has channels after the merge', after.length === 2,
     String(after.length));
  ok('and they are the panel\'s, not the bundled ones',
     after.map((c) => c.url).join(',') === 'https://t.me/shop,https://ble.ir/shop',
     after.map((c) => c.url).join(','));
  ok('the shipping figures come from the panel too',
     config.freeShippingThreshold === 2000000 && config.shippingFlatRate === 70000);

  /* whatsapp_url is how the owner turns WhatsApp on; it must arrive as a
     button, not be silently filtered out for lacking a flag. */
  const sb2 = bootApp();
  sb2.app.mergeRemoteConfig({
    channels: [{ id: 'whatsapp', label: 'واتس‌اپ', url: 'https://wa.me/989120000000', prefillParam: 'text' }],
  });
  const wa = sb2.store.channels();
  ok('a whatsapp-only panel yields a whatsapp button',
     wa.length === 1 && wa[0].id === 'whatsapp', JSON.stringify(wa));

  /* An empty or unusable response must leave the shop orderable rather than
     replacing working buttons with nothing. */
  for (const [label, remote] of [
    ['an empty channel list', { channels: [] }],
    ['rows with no url', { channels: [{ id: 'telegram', label: 'تلگرام' }] }],
    ['rows switched off', { channels: [{ id: 'telegram', url: 'https://t.me/x', enabled: false }] }],
  ]) {
    const s3 = bootApp();
    s3.app.mergeRemoteConfig(remote);
    ok('  ' + label + ' falls back to the bundled ones',
       s3.store.channels().length === bundled, String(s3.store.channels().length));
  }

  /* A dead API must not take the shipping figures down with it. */
  const s4 = bootApp();
  ok('a null response is refused outright', s4.app.mergeRemoteConfig(null) === false);
  ok('  and leaves the channels alone', s4.store.channels().length === bundled);
}


head('telegram cannot be handed a prewritten message');
{
  /* wa.me/<number>?text= is documented by WhatsApp and Bale was checked on a
     device, but t.me/<username> takes no message parameter - Telegram does not
     let a website put words in your outgoing message. The bundled config
     claimed it did, and the link then failed to open the app at all: the shop
     lost every Telegram order while whatsapp and bale worked. */
  const S = boot(['config.js', 'store.js']).store;
  S.add({ slug: 'x', nameFa: 'کالا', price: 1000000, qty: 1 });

  const by = {};
  for (const c of boot(['config.js', 'store.js']).config.ordering.channels) { by[c.id] = c; }

  ok('telegram declares no prefill parameter', by.telegram.prefillParam === null,
     String(by.telegram.prefillParam));
  ok('  so its route is a plain profile link',
     S.resolveChannel(by.telegram).url === by.telegram.url,
     S.resolveChannel(by.telegram).url);
  ok('  and the order goes on the clipboard instead',
     S.resolveChannel(by.telegram).needsPaste === true);

  /* The two that were actually verified must keep working. */
  for (const id of ['bale', 'whatsapp']) {
    ok('  ' + id + ' still prefills',
       by[id].prefillParam === 'text'
       && S.resolveChannel(by[id]).needsPaste === false
       && S.resolveChannel(by[id]).url.indexOf('?text=') > 0);
  }
}

head('emptying the basket must not wipe the payment panel');
{
  /* The cart list and the payment panel share one container, and renderCart is
     a store subscriber - so store.clear() on a confirmed order repainted that
     container as «سبد خرید خالی است» one tick after the panel went in, taking
     the tracking code and the card number with it. In a card-to-card shop that
     panel is the only place the customer is told where to transfer. */
  const keeps = bootApp().app.cartBodyKeepsPanel;

  ok('a confirmed order leaves the panel standing', keeps(true, 0) === true);
  ok('adding something hands the container back to the cart',
     keeps(true, 2) === false);
  ok('an ordinary empty cart still renders its empty state',
     keeps(false, 0) === false);
  ok('and an ordinary cart with items renders normally',
     keeps(false, 3) === false);
}

head('the shipped config must not unplug the shop');
{
  /* config.js holds both code the whole site reads and three values that are
     deployment decisions. It used to ship the no-backend demo's defaults, so
     uploading it to fix something else quietly cut the storefront off from its
     own database: sample products, no order recorded, and a payment panel with
     no card number for the customer to transfer to - all with no error. The
     readiness page caught it, after it had already happened to a live shop. */
  const cfg = boot(['config.js']).config;

  ok('the catalogue comes from the backend',
     cfg.api.baseUrl === 'api/index.php', String(cfg.api.baseUrl));
  ok('  product links reach the server-rendered page',
     cfg.productPage === 'product.php', String(cfg.productPage));
  ok('  and so do category links',
     cfg.categoryPage === 'category.php', String(cfg.categoryPage));

  /* lib/preflight.php greps for these exact spellings, so a reformat that
     keeps the value but changes the quoting would turn the readiness page
     green while telling it nothing. */
  const src = fs.readFileSync(JS('config.js'), 'utf8');
  ok('  spelled the way the readiness check greps for',
     /baseUrl:\s*'api\/index\.php'/.test(src)
     && /productPage:\s*'product\.php'/.test(src));
}

head('the cart must not reprice a combination back to its size');
{
  /* syncPrices() re-reads every line against ?r=prices so a price that moved
     while the basket sat in localStorage is corrected before a total is shown.
     It resolved size and then flavour premium - the delta model - so a line
     holding a combination priced at 7,777,000 came back as the size's
     2,450,000. The drawer would show one number while the server charged
     another, and in a card-to-card shop the screen is what gets transferred. */
  const S = boot(['config.js', 'store.js']).store;
  S.add({ slug: 'tub', nameFa: 'قوطی', price: 7777000, qty: 1,
          sizeId: '900', flavorId: 'choco', attrs: { colour: 'blue' } });

  S.syncPrices([{
    slug: 'tub', price: 2450000, available: true, stock: null,
    sizes: [{ id: '900', price: 2450000, stock: null }],
    flavors: [{ id: 'choco', priceDelta: 0 }],
    variants: [
      { id: '900|choco|blue', price: 7777000, compareAt: null, stock: 2,
        choice: { size: '900', flavor: 'choco', colour: 'blue' } },
      { id: '900|choco|red', price: 2450000, compareAt: null, stock: 4,
        choice: { size: '900', flavor: 'choco', colour: 'red' } },
    ],
  }]);

  const line = S.snapshot().items[0];
  ok('the combination keeps its own price', line.price === 7777000,
     String(line.price));
  ok('  and its count comes from the combination too',
     S.snapshot().items[0].unavailable !== true);
}

head('a combination that is no longer sold');
{
  /* Switched off in the panel, or renamed. The server refuses it, so the
     drawer has to say so rather than quote a price that will not be honoured. */
  const S = boot(['config.js', 'store.js']).store;
  S.add({ slug: 'tub', nameFa: 'قوطی', price: 7777000, qty: 1,
          sizeId: '900', flavorId: 'choco', attrs: { colour: 'blue' } });

  S.syncPrices([{
    slug: 'tub', price: 2450000, available: true, stock: null,
    sizes: [{ id: '900', price: 2450000, stock: null }],
    flavors: [{ id: 'choco', priceDelta: 0 }],
    variants: [
      { id: '900|choco|red', price: 2450000, compareAt: null, stock: 4,
        choice: { size: '900', flavor: 'choco', colour: 'red' } },
    ],
  }]);

  ok('the line is marked unavailable, not silently repriced',
     S.snapshot().items[0].unavailable === true);
  ok('  so it is out of the total', S.snapshot().subtotal === 0,
     String(S.snapshot().subtotal));
}

head('a product with no combinations is untouched');
{
  /* Every product until one is converted. The delta arithmetic has to keep
     working exactly as it did. */
  const S = boot(['config.js', 'store.js']).store;
  S.add({ slug: 'tub', nameFa: 'قوطی', price: 2450000, qty: 1,
          sizeId: '900', flavorId: 'cook' });

  S.syncPrices([{
    slug: 'tub', price: 2000000, available: true, stock: null,
    sizes: [{ id: '900', price: 2450000, stock: null }],
    flavors: [{ id: 'cook', priceDelta: 250000 }],
    variants: [],
  }]);

  ok('the size price plus the premium is still what is charged',
     S.snapshot().items[0].price === 2700000,
     String(S.snapshot().items[0].price));
}

head('two colours of one thing are two lines');
{
  /* The basket keys a line by what was chosen. «red» and «blue» in the same
     size and flavour are two different objects on a shelf, and merging them
     would ship one of something the shopper asked for two of. */
  const S = boot(['config.js', 'store.js']).store;
  const base = { slug: 'tub', nameFa: 'قوطی', price: 2450000, qty: 1,
                 sizeId: '900', flavorId: 'choco' };
  S.add(Object.assign({}, base, { attrs: { colour: 'red' } }));
  S.add(Object.assign({}, base, { attrs: { colour: 'blue' } }));
  ok('they stay apart', S.snapshot().items.length === 2,
     String(S.snapshot().items.length));

  /* And the same choice written in a different order is the SAME line - the
     object comes from whichever order the page built it in. */
  S.add(Object.assign({}, base, { attrs: { colour: 'red' } }));
  ok('  and the same choice merges', S.snapshot().items.length === 2,
     String(S.snapshot().items.length));
  ok('    onto the line it belongs to',
     S.snapshot().items.filter((i) => i.attrs.colour === 'red')[0].qty === 2);
}

head('choosing anything repaints the price, the picture and the count');
{
  /* A combination carries its own price, its own photograph and its own
     count, so every handler that changes which combination is selected has
     to repaint all three.

     The size handler repainted two of them. «وی شکلاتی ۹۰۰ گرمی» and «وی
     شکلاتی ۱.۸ کیلوگرمی» moved the price and kept one photograph between
     them - and no amount of clicking flavour chips would have shown it,
     because the flavour handler had the call. */
  const src = fs.readFileSync(JS('app.js'), 'utf8');

  /* The body of each of the three handlers: from the line that assigns the
     selection to the end of that handler. */
  const bodyAfter = (marker) => {
    const i = src.indexOf(marker);
    if (i < 0) { return null; }
    const j = src.indexOf('});', i);
    return j < 0 ? null : src.slice(i, j);
  };

  const handlers = {
    size:   bodyAfter('selected.size = sizes.filter'),
    flavor: bodyAfter('selected.flavor = flavors.filter'),
    custom: bodyAfter('selected.attrs[row.getAttribute'),
  };

  for (const [name, body] of Object.entries(handlers)) {
    ok('the ' + name + ' handler exists', body !== null);
    if (!body) { continue; }
    ok('  it repaints the price',   body.includes('paintPrice()'));
    ok('  it repaints the picture', body.includes('paintChoiceImage()'));
  }

  /* The old name said the picture belonged to the flavour, which is how the
     missing call went unnoticed. Matched as code - «paintFlavorImage(» -
     rather than as a string, because the comment above the painter names
     the old name on purpose, to say what went wrong under it. */
  ok('nothing calls or defines the old name any more',
     !src.includes('paintFlavorImage('));
}

head('what a card\'s «+» adds (review of 2026-10-02)');
{
  /* Ten of the twelve products on the home page went into the basket as
     «دیگر موجود نیست»: priced by combination, added with no combination. The
     server now says which products a card may add and with what. */
  const { app } = bootApp();
  const plan = app.cardAddPlan;
  const go = plan({ slug: 'w', needsChoice: true, choice: null });
  ok('a product with a choice to make goes to its page', go && /p=w$/.test(go.go || ''), JSON.stringify(go));
  const one = plan({ slug: 'w', needsChoice: false, price: 4000000,
    choice: { sizeId: '910', flavorId: 'choc', attrs: null, label: 'شکلاتی · ۹۱۰ گرم', price: 4200000 } });
  ok('one combination is added with every id the order needs',
     one && one.add && one.add.sizeId === '910' && one.add.flavorId === 'choc', JSON.stringify(one));
  ok('  its words on the line', one.add.variantLabel === 'شکلاتی · ۹۱۰ گرم');
  ok('  and its own price rather than the card\'s headline', one.add.price === 4200000);
  const plain = plan({ slug: 'p', needsChoice: false,
    choice: { sizeId: null, flavorId: null, attrs: null, label: '', price: 1300000 } });
  ok('a product with nothing to choose is added as it is',
     plain.add && plain.add.sizeId === null && plain.add.flavorId === null && plain.add.variantLabel === undefined);
  /* A copy of the list cached before the server said: the old rule, which
     now also holds back several flavours. */
  ok('an older answer with two sizes still goes to the page',
     !!plan({ slug: 'o', sizes: [{}, {}], flavors: [] }).go);
  ok('  and with two flavours too - the old rule let those in with none chosen',
     !!plan({ slug: 'o', sizes: [{}], flavors: [{}, {}] }).go);
  ok('  one of each is added', !!plan({ slug: 'o', sizes: [{}], flavors: [{}] }).add);
  ok('nothing in the cache is a trip to the page, not a dead button', plan(undefined) === null);
}

head('the basket after the order: only what was ordered comes out');
{
  const S = boot(['config.js', 'store.js']).store;
  S.add({ slug: 'a', nameFa: 'الف', price: 100, qty: 1 });
  S.add({ slug: 'gone', nameFa: 'رفته', price: 300, qty: 1 });
  S.syncPrices([{ slug: 'a', available: true, price: 100 }]);   // 'gone' absent: unavailable
  const sent = S.snapshot();
  /* What the shopper did while the order was on its way. */
  S.add({ slug: 'a', nameFa: 'الف', price: 100, qty: 2 });
  S.add({ slug: 'b', nameFa: 'ب', price: 200, qty: 1 });
  S.removeLines(sent.items);
  const left = S.snapshot().items.map((i) => i.slug + ':' + i.qty).sort().join(',');
  ok('the two added meanwhile stay, and the new line too', left === 'a:2,b:1', left);
  ok('the line the order left out as unavailable goes with it',
     !S.snapshot().items.some((i) => i.slug === 'gone'));
}

head('free shipping at zero is no free shipping');
{
  /* order_create() reads a threshold of 0 as «never free»; the drawer read it
     as «always free», and showed «رایگان» over an order charged the flat rate. */
  const sb = boot(['config.js', 'store.js']);
  sb.config.freeShippingThreshold = 0;
  sb.config.shippingFlatRate = 65000;
  sb.store.add({ slug: 'a', nameFa: 'الف', price: 9000000, qty: 1 });
  const s = sb.store.snapshot();
  ok('the flat rate is charged', s.shipping === 65000 && s.freeShipping === false, JSON.stringify(s));
  ok('  and there is no free-shipping line to draw', s.freeShippingOffered === false && s.remainingForFreeShipping === 0);
  const sb2 = boot(['config.js', 'store.js']);
  sb2.store.add({ slug: 'a', nameFa: 'الف', price: 9000000, qty: 1 });
  ok('a real threshold is unchanged', sb2.store.snapshot().freeShipping === true
     && sb2.store.snapshot().freeShippingOffered === true);
}

head('a size or flavour the product no longer has');
{
  const S = boot(['config.js', 'store.js']).store;
  S.add({ slug: 'a', nameFa: 'الف', price: 100, qty: 1, sizeId: 'old' });
  S.add({ slug: 'b', nameFa: 'ب', price: 100, qty: 1, flavorId: 'old' });
  S.add({ slug: 'c', nameFa: 'ج', price: 100, qty: 1, sizeId: 'any' });
  S.syncPrices([
    { slug: 'a', available: true, price: 100, sizes: [{ id: 'new', price: 120 }], flavors: [] },
    { slug: 'b', available: true, price: 100, sizes: [], flavors: [{ id: 'new', priceDelta: 0 }] },
    { slug: 'c', available: true, price: 100, sizes: [], flavors: [] },
  ]);
  const by = {};
  S.snapshot().items.forEach((i) => { by[i.slug] = i; });
  ok('a renamed size is unavailable, as the server refuses it', by.a.unavailable === true);
  ok('  and a renamed flavour', by.b.unavailable === true);
  ok('a size id on a product with no sizes means nothing, as on the server', by.c.unavailable === false);
}

head('the text copied again is the order that was sent');
{
  const S = boot(['config.js', 'store.js']).store;
  S.add({ slug: 'a', nameFa: 'کالای فرستاده', price: 100, qty: 1 });
  S.setCustomer({ name: 'خریدار', phone: '09120000000' });
  const sent = S.snapshot();
  S.removeLines(sent.items);
  S.add({ slug: 'b', nameFa: 'کالای بعدی', price: 200, qty: 1 });
  const text = S.asOrderText(sent, 'SPX-261002-AB12');
  ok('it lists what was sent, not what is in the basket now',
     text.includes('کالای فرستاده') && !text.includes('کالای بعدی'));
  ok('  with the tracking code once there is one', text.includes('SPX-261002-AB12'));
  ok('  and the customer it was sent with', text.includes('خریدار'));
  /* Signed like everything the shop sends (lib/order_messages.php). */
  ok('  and it ends with where it was placed', /ثبت‌شده در SUPPEX$/.test(text), text.slice(-60));
}

head('typing does not repaint');
{
  const S = boot(['config.js', 'store.js']).store;
  let calls = 0;
  S.subscribe(() => { calls++; });
  const before = calls;
  S.setCustomer({ address: 'تهران' }, true);
  ok('a quiet save reaches no listener', calls === before);
  ok('  but is kept', S.getCustomer().address === 'تهران');
}

head('the form is checked as the server checks it');
{
  const { app } = bootApp();
  const V = app.VALIDATORS;
  ok('a pasted number with direction marks is a number', V.mobile('\u200e۰۹۱۲ ۳۴۵ ۶۷۸۹\u200f') === null);
  ok('  +98 is the same number', V.mobile('+98 912 345 6789') === null
     && app.normaliseMobile('+98 912 345 6789') === '09123456789');
  ok('  so is 0098', app.normaliseMobile('0098-912-345-6789') === '09123456789');
  ok('  a short one is still refused', V.mobile('0912345') !== null);
  ok('a postal code in Persian digits', V.postal('۱۲۳۴۵-۶۷۸۹۰') === null);
  ok('an address under ten characters is refused here, not by the server', V.address('تهران') !== null);
  ok('  ten is enough', V.address('تهران، ولیعصر') === null);
}

head('the payment panel says what is true at each moment');
{
  const ui = boot(['config.js', 'ui.js']).ui;
  const snap = { items: [], subtotal: 0, shipping: 0, total: 5000, count: 1 };
  const ch = { id: 'telegram', label: 'تلگرام' };
  const waiting = ui.paymentPanel(snap, ch, { needsPaste: true, copied: false }, { pending: true });
  ok('while the order is on its way: no card, no code', !waiting.includes('data-copy-card') && !waiting.includes('pay__code'));
  ok('  it says it is being recorded', waiting.includes('در حال ثبت سفارش'));
  ok('  and not the «the card comes in the chat» line', !waiting.includes('pay__note') && !waiting.includes('برایتان فرستاده می‌شود'));
  ok('a copy that has not happened is not announced', !waiting.includes('متن سفارش کپی شد'));
  ok('  and can be made again', waiting.includes('data-copy-order'));
  ok('one that has, is', ui.paymentPanel(snap, ch, { needsPaste: true, copied: true }, { pending: true })
     .includes('متن سفارش کپی شد'));
  const live = ui.paymentPanel(snap, ch, { needsPaste: false }, {
    code: 'SPX-1', total: 5000, expiresAt: Date.now() + 60000, serverNow: Date.now(),
    payment: { cardNumber: '6219861012345678', cardHolder: 'الف', bankName: 'ب' } });
  ok('the clock reads as a label and a time', live.includes('مهلت پرداخت با این قیمت:') && !live.includes('دیگر معتبر است'));
  ok('what to do when it runs out is there, for the CSS to show',
     live.includes('pay__expired') && live.includes('data-order-again'));
  const failed = ui.orderFailed({ message: 'آدرس دقیق را کامل وارد کنید.', action: 'fix', label: 'اصلاح مشخصات' });
  ok('a refused order says so, with the server\'s words and one way on',
     failed.includes('سفارش ثبت نشد') && failed.includes('آدرس دقیق') && failed.includes('data-order-fix'));
  ok('  and no card', !failed.includes('data-copy-card'));
  const gone = ui.lineItem({ key: 'k', nameFa: 'الف', qty: 1, lineTotal: 100, unavailable: true });
  ok('an unavailable line looks like one', gone.includes('line-item--gone') && gone.includes('ناموجود')
     && !gone.includes('data-qty'));
}

head('another tab changed the basket');
{
  /* Each tab wrote its whole basket back on every change, so the last writer
     won and the other tab's additions were lost. */
  const mem = {};
  const handlers = {};
  const sb = {
    console,
    document: { documentElement: {} },
    localStorage: {
      getItem: (k) => (k in mem ? mem[k] : null),
      setItem: (k, v) => { mem[k] = String(v); },
      removeItem: (k) => { delete mem[k]; },
    },
    addEventListener: (type, fn) => { handlers[type] = fn; },
  };
  sb.window = sb;
  vm.createContext(sb);
  for (const f of ['config.js', 'store.js']) { vm.runInContext(fs.readFileSync(JS(f), 'utf8'), sb); }
  const S = sb.SUPPEX.store;
  S.init();
  let seen = null;
  S.subscribe((snap) => { seen = snap; });
  S.add({ slug: 'a', nameFa: 'الف', price: 100, qty: 1 });
  const key = sb.SUPPEX.config.storageKey;
  const other = JSON.stringify({ items: [{ slug: 'b', nameFa: 'ب', price: 200, qty: 2 }], customer: {}, quote: '' });
  mem[key] = other;
  ok('the store listens for it', typeof handlers.storage === 'function');
  handlers.storage({ key: key, newValue: other });
  ok('this tab now shows the other tab\'s basket',
     S.snapshot().items.length === 1 && S.snapshot().items[0].slug === 'b');
  ok('  and repaints with it', seen && seen.count === 2);
  ok('  without writing it back', mem[key] === other);
  handlers.storage({ key: 'something-else', newValue: '{}' });
  ok('another key is ignored', S.snapshot().items[0].slug === 'b');
}
head('the selected combination controls availability before the cart');
{
  const A = bootApp().app;
  const live = { inStock: true, variants: [{ id: 'chocolate' }] };
  const count = (n) => ({ stock: n });
  ok('a disabled/missing flavour does not fall back to the size being available',
    A.productAvailability(live, null, 10).available === false);
  ok('an empty active-variant list is unavailable, not legacy mode',
    A.productAvailability({ inStock: true, variants: [] }, null, null).available === false);
  ok('a zero-count flavour is unavailable even when its size has stock',
    A.productAvailability(live, count(0), 10).available === false);
  ok('a positive flavour count wins over the size count',
    A.productAvailability(live, count(2), 0).available === true
      && A.productAvailability(live, count(2), 10).ceiling === 2);
  ok('an uncounted active flavour is available without a fabricated count',
    A.productAvailability(live, count(null), null).available === true
      && A.productAvailability(live, count(null), null).stock === null);
  ok('the product manual off switch still wins',
    A.productAvailability({ inStock: false, variants: live.variants }, count(4), 4).available === false);
  ok('an oversold combination cannot be bought',
    A.productAvailability(live, count(-1), null).available === false);
  ok('the shop quantity limit is still twenty',
    A.productAvailability(live, count(50), null).ceiling === 20);
  ok('a legacy product without a variant catalogue still sells normally',
    A.productAvailability({ inStock: true }, null, null).available === true);
  ok('legacy size zero still disables buying',
    A.productAvailability({ inStock: true }, null, 0).available === false);
  ok('legacy size counts still cap quantity',
    A.productAvailability({ inStock: true }, null, 3).ceiling === 3);
}

console.log('\n----------------------------------------');
head('supplier stock inquiry is separate from buying');
{
  const { app:A } = bootApp();
  const p={nameFa:'پروتئین تست',slug:'test-protein',inStock:true,inquiryAllowed:true,variants:[{id:'v'}]};
  const request={stock:0,requiresInquiry:true};
  const state=A.productAvailability(p,request,null);
  ok('zero-count inquiry is visible as an inquiry but cannot be purchased',state.inquiry===true&&state.available===false);
  ok('a positive count does not bypass the inquiry flag',A.productAvailability(p,{stock:5,requiresInquiry:true},null).available===false);
  ok('an all-inquiry product can still be inquired about',A.productAvailability({...p,inStock:false},request,null).inquiry===true);
  ok('the manual product off switch suppresses inquiries',A.productAvailability({...p,inStock:false,inquiryAllowed:false},request,null).inquiry===false);
  ok('a disabled/missing flavour cannot become an inquiry',A.productAvailability(p,null,null).inquiry===false);
  ok('approval with counted stock restores buying',A.productAvailability(p,{stock:3,requiresInquiry:false},null).available===true);
  ok('inquiry cards navigate to the choice page',!!A.cardAddPlan({...p,requiresInquiry:true,needsChoice:false,choice:{price:100}}).go);
  const msg=A.inquiryMessage(p,['وانیل','۹۰۰ گرم'],2,'https://suppex.ir/product.php?p=test-protein');
  ok('the request names the product, flavour, size, quantity and response time',msg.includes('پروتئین تست')&&msg.includes('وانیل')&&msg.includes('۹۰۰ گرم')&&msg.includes('۲')&&msg.includes('۳۰'));
  const routes=A.inquiryRoutes([{id:'whatsapp',url:'https://wa.me/989000000000',enabled:true},{id:'telegram',url:'https://t.me/suppex',enabled:true},{id:'whatsapp',url:'javascript:alert(1)',enabled:true}],msg);
  ok('WhatsApp receives the request text, not the shopping basket',decodeURIComponent(routes[0].url).includes(msg)&&routes[0].needsPaste===false);
  ok('Telegram uses the supported clipboard path',routes[1].url==='https://t.me/suppex'&&routes[1].needsPaste===true);
  ok('unsafe destinations are not offered',routes.length===2);
  ok('disabled channels stay unavailable',A.inquiryRoutes([{id:'telegram',url:'https://t.me/suppex',enabled:false}],msg).length===0);
  ok('long messages use copy instead of truncating the request',A.inquiryRoutes([{id:'whatsapp',url:'https://wa.me/989000000000'}],'الف'.repeat(1000))[0].needsPaste===true);
}
console.log(`passed: ${pass}    failed: ${fail}`);
process.exit(fail ? 1 : 0);
