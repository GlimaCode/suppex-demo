/* ==========================================================================
   SUPPEX — Cart store
   --------------------------------------------------------------------------
   A tiny observable store. Views subscribe; nothing reads cart state directly
   out of the DOM. Persistence is isolated behind load()/save(), so replacing
   localStorage with a server-side cart later is a two-function change.
   ========================================================================== */
window.SUPPEX = window.SUPPEX || {};

SUPPEX.store = (function () {
  'use strict';

  var cfg = SUPPEX.config;
  var listeners = [];

  /* A line item is keyed by slug + variant, so the same product in two
     flavours or two sizes stays two separate lines — as a real cart must. */
  var state = { items: [] };

  function keyOf(item) {
    return [item.slug, item.flavorId || '-', item.sizeId || '-'].join('::');
  }

  function load() {
    if (!cfg.flags.persistCart) { return; }
    try {
      var raw = localStorage.getItem(cfg.storageKey);
      if (!raw) { return; }
      var parsed = JSON.parse(raw);
      if (parsed && Array.isArray(parsed.items)) { state.items = parsed.items; }
    } catch (err) {
      /* Corrupt or unavailable storage (private mode, quota) must never stop
         the page from rendering — start empty and carry on. */
      console.warn('[suppex] cart could not be restored:', err);
    }
  }

  function save() {
    if (!cfg.flags.persistCart) { return; }
    try {
      localStorage.setItem(cfg.storageKey, JSON.stringify(state));
    } catch (err) {
      console.warn('[suppex] cart could not be saved:', err);
    }
  }

  function emit() {
    save();
    var snapshot = api.snapshot();
    listeners.forEach(function (fn) { fn(snapshot); });
  }

  var api = {
    subscribe: function (fn) {
      listeners.push(fn);
      fn(api.snapshot());
      return function unsubscribe() {
        listeners = listeners.filter(function (l) { return l !== fn; });
      };
    },

    /* payload: { slug, name, nameFa, image, price, variantLabel,
                  flavorId, flavorName, sizeId, sizeLabel, qty } */
    add: function (payload) {
      var qty = Math.max(1, payload.qty || 1);
      var key = keyOf(payload);
      var existing = state.items.filter(function (i) { return keyOf(i) === key; })[0];

      if (existing) {
        existing.qty += qty;
      } else {
        state.items.push(Object.assign({}, payload, { qty: qty }));
      }
      emit();
    },

    setQty: function (key, qty) {
      state.items = state.items
        .map(function (i) { return keyOf(i) === key ? Object.assign({}, i, { qty: qty }) : i; })
        .filter(function (i) { return i.qty > 0; });
      emit();
    },

    remove: function (key) {
      state.items = state.items.filter(function (i) { return keyOf(i) !== key; });
      emit();
    },

    clear: function () { state.items = []; emit(); },

    keyOf: keyOf,

    /* Totals are computed here, never in a template. */
    snapshot: function () {
      var subtotal = state.items.reduce(function (sum, i) { return sum + i.price * i.qty; }, 0);
      var count = state.items.reduce(function (sum, i) { return sum + i.qty; }, 0);
      var freeShipping = subtotal >= cfg.freeShippingThreshold;
      var shipping = count === 0 || freeShipping ? 0 : cfg.shippingFlatRate;

      return {
        items: state.items.map(function (i) {
          return Object.assign({}, i, { key: keyOf(i), lineTotal: i.price * i.qty });
        }),
        count: count,
        subtotal: subtotal,
        shipping: shipping,
        total: subtotal + shipping,
        freeShipping: freeShipping,
        remainingForFreeShipping: Math.max(0, cfg.freeShippingThreshold - subtotal),
      };
    },

    /* Formats the cart as a plain-text order for a messaging app. Kept in the
       store rather than a view because it is the order itself, not a piece of
       presentation — the same text a server would eventually receive. */
    asOrderText: function () {
      var snap = api.snapshot();
      var money = cfg.currency.format;
      var unit = cfg.currency.label;
      var lines = [cfg.ordering.intro, ''];

      snap.items.forEach(function (item, i) {
        lines.push((i + 1) + ') ' + item.nameFa);
        if (item.variantLabel) { lines.push('   ' + item.variantLabel); }
        lines.push('   ' + item.qty + ' × ' + money(item.price) +
                   ' = ' + money(item.lineTotal) + ' ' + unit);
        lines.push('');
      });

      lines.push('—————————————');
      lines.push('جمع کالاها: ' + money(snap.subtotal) + ' ' + unit);
      lines.push('هزینه ارسال: ' + (snap.freeShipping ? 'رایگان' : money(snap.shipping) + ' ' + unit));
      lines.push('مبلغ قابل پرداخت: ' + money(snap.total) + ' ' + unit);
      return lines.join('\n');
    },

    /* The destination for that message. Returns null when the shop has moved
       on to a real gateway, which the caller treats as "run checkout". */
    orderUrl: function () {
      var o = cfg.ordering;
      var text = encodeURIComponent(api.asOrderText());
      if (o.method === 'telegram') {
        return 'https://t.me/' + encodeURIComponent(o.telegramUsername) + '?text=' + text;
      }
      if (o.method === 'whatsapp') {
        return 'https://wa.me/' + encodeURIComponent(o.whatsappNumber) + '?text=' + text;
      }
      return null;
    },

    init: function () { load(); emit(); },
  };

  return api;
})();
