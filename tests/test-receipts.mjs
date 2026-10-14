/* ===========================================================================
   After the order, in the browser: the receipt box, and «سفارش‌های من».
   ---------------------------------------------------------------------------
   ui.receiptBlock()   the box the customer sends the transfer's photo from
   ui.paymentPanel()   which shows it once the order is recorded
   ui.myOrderRow()     one order in the list on the tracking page
   rememberMyOrder() / myOrders() / forgetMyOrder()   the list, on this device

   The list lives in this browser and nowhere else, on purpose - a history
   opened by a phone number alone would show one person's orders to anybody
   who typed that number. So what is held here is that the list holds
   exactly what the browser proved, and that the phone never lands in a URL.
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
    sandbox tests/test-hero.mjs uses, localStorage included. */
function bootApp() {
  const mem = {};
  const noop = () => {};
  const el = { addEventListener: noop, classList: { toggle: noop, add: noop, remove: noop } };
  const sb = {
    console,
    setTimeout,
    clearTimeout,
    Date,
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
  return { app: sb.SUPPEX.app, ui: sb.SUPPEX.ui, mem };
}

const { app, ui, mem } = bootApp();

head('the receipt box');
{
  const box = ui.receiptBlock('SPX-260924-4F7A', '09121112233');
  ok('it is filed against the order code', box.indexOf('data-code="SPX-260924-4F7A"') !== -1);
  ok('  with the phone when the page has it', box.indexOf('data-phone="09121112233"') !== -1);
  ok('  and without it when it does not - the form is read at the moment of sending',
     ui.receiptBlock('SPX-260924-4F7A').indexOf('data-phone') === -1);
  ok('pictures only, from the camera or the gallery', box.indexOf('type="file" accept="image/*"') !== -1);
  /* Hidden to the eye, not to the keyboard: display:none would take it out
     of the tab order. */
  ok('  the input hidden visually, not removed', box.indexOf('class="sr-only" type="file"') !== -1);
  ok('what happens is read out as it happens', box.indexOf('data-receipt-state aria-live="polite"') !== -1);
  ok('the code is escaped', ui.receiptBlock('<b>').indexOf('data-code="&lt;b&gt;"') !== -1);
}

head('the receipt, pinned under the payment panel');
{
  const dock = ui.receiptBlock('SPX-260924-4F7A', '', 'dock');
  ok('the same box, filed against the same code', dock.indexOf('data-receipt data-code="SPX-260924-4F7A"') !== -1
     && dock.indexOf('data-receipt-file') !== -1 && dock.indexOf('data-receipt-state aria-live="polite"') !== -1);
  ok('  pared down to a line and a button', dock.indexOf('receipt-up--dock') !== -1
     && dock.indexOf('receipt-up__lead') !== -1 && dock.indexOf('receipt-up__head') === -1);
  ok('  and the button is the step to take', dock.indexOf('btn btn--primary btn--block receipt-up__pick') !== -1);
  ok('  the phone read from the order form when it is sent, not written into the page',
     dock.indexOf('data-phone') === -1);
  const full = ui.receiptBlock('SPX-260924-4F7A', '09121112233');
  ok('the tracking page keeps the full box', full.indexOf('receipt-up__head') !== -1
     && full.indexOf('receipt-up--dock') === -1 && full.indexOf('btn btn--ghost btn--block receipt-up__pick') !== -1);
}

head('the payment panel leaves the receipt to the dock');
{
  const snapshot = { total: 1000, count: 1, items: [] };
  const channel = { label: 'تلگرام', prefillParam: 'text' };
  const route = { needsPaste: false };
  const recorded = ui.paymentPanel(snapshot, channel, route, { code: 'SPX-260924-4F7A', total: 1000 });
  /* At the end of the panel the box was below the fold on a phone. */
  ok('no box inside the panel once the order is recorded', recorded.indexOf('data-receipt') === -1);
  ok('  and no «send it in the chat» note to argue with the pinned one', recorded.indexOf('pay__note') === -1);
  const unrecorded = ui.paymentPanel(snapshot, channel, route, null);
  ok('an order the server does not have keeps the chat note', unrecorded.indexOf('pay__note') !== -1
     && unrecorded.indexOf('data-receipt') === -1);
}

head('one order in «سفارش‌های من»');
{
  const mine = { code: 'SPX-260924-4F7A', phone: '09121112233', at: Date.UTC(2026, 8, 24), total: 1180000 };
  const live = { code: mine.code, status: 'shipped', statusFa: 'ارسال شده', total: 1180000,
    createdAt: Date.UTC(2026, 8, 24), trackingCode: '1234567890123', summary: 'کراتین مونوهیدرات', more: 2 };
  const row = ui.myOrderRow(mine, live, true);
  ok('its status, as a badge', row.indexOf('track__badge--shipped') !== -1 && row.indexOf('ارسال شده') !== -1);
  ok('what was in it', row.indexOf('کراتین مونوهیدرات') !== -1 && row.indexOf('۲ قلم دیگر') !== -1);
  ok('its postal tracking code', row.indexOf('1234567890123') !== -1);
  /* The reason the tracking form posts: a phone in a URL ends up in the
     browser's history and every log between here and the host. */
  ok('the details open by POST', row.indexOf('<form method="post" action="track.php">') !== -1
     && row.indexOf('name="phone" value="09121112233"') !== -1);
  ok('  and the phone is in no link', !/href="[^"]*0912/.test(row));
  ok('it can be taken off the list', row.indexOf('data-forget-order="SPX-260924-4F7A"') !== -1);
  ok('before the server answers, a placeholder where the status goes',
     ui.myOrderRow(mine, null, false).indexOf('myorder__wait') !== -1);
  ok('an order the server does not know says so', ui.myOrderRow(mine, null, true).indexOf('پیدا نشد') !== -1);
  ok('the code is escaped', ui.myOrderRow({ code: '<i>', phone: '1', at: 0, total: null }, null, true)
     .indexOf('&lt;i&gt;') !== -1);
}

head('the list, on this device');
{
  const KEY = 'suppex.orders.v1';
  delete mem[KEY];
  ok('it starts empty', app.myOrders().length === 0);
  app.rememberMyOrder('SPX-1', '09120000001', 1000);
  app.rememberMyOrder('SPX-2', '09120000001', 2000);
  ok('newest first', app.myOrders().map((o) => o.code).join(',') === 'SPX-2,SPX-1');
  const firstAt = app.myOrders()[1].at;
  app.rememberMyOrder('SPX-1', '09120000001', 1500);
  ok('an order already there keeps its place and its date',
     app.myOrders().map((o) => o.code).join(',') === 'SPX-2,SPX-1' && app.myOrders()[1].at === firstAt);
  ok('  and takes the newer total', app.myOrders()[1].total === 1500);
  app.rememberMyOrder('SPX-3', '', 1);
  app.rememberMyOrder('', '09120000001', 1);
  ok('nothing without both halves of the credential', app.myOrders().length === 2);
  app.forgetMyOrder('SPX-2');
  ok('taken off when asked', app.myOrders().map((o) => o.code).join(',') === 'SPX-1');
  for (let i = 0; i < 60; i++) { app.rememberMyOrder('SPX-B' + i, '09120000001', i); }
  ok('fifty at most, the oldest let go', app.myOrders().length === 50 && app.myOrders()[0].code === 'SPX-B59');
  mem[KEY] = '{not json';
  ok('a damaged list is an empty one, not an exception', app.myOrders().length === 0);
  mem[KEY] = JSON.stringify([{ code: 'SPX-X' }, null, { code: 'SPX-Y', phone: '0912' }]);
  ok('  and entries missing a half are dropped', app.myOrders().map((o) => o.code).join(',') === 'SPX-Y');
  delete mem[KEY];
}

console.log('\n----------------------------------------\npassed: ' + pass + '    failed: ' + fail);
process.exit(fail > 0 ? 1 : 0);
