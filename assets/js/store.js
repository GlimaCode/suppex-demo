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
  var state = { items: [], customer: {} };
  var DIVIDER = '—————————————';

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
      if (parsed && parsed.customer) { state.customer = parsed.customer; }
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

    /* Reconcile stored cart prices against what the shop charges now.

       The cart keeps the price it saw when the item was added, and with a
       dirham-linked catalogue that is stale within days. The order message the
       customer pastes into chat is built from these numbers, so left alone they
       would send one figure while the server recorded another — and with
       card-to-card there is no authorisation to correct the difference
       afterwards.

       Returns what changed so the drawer can say so. Silently correcting a
       price the shopper already read is its own kind of dishonest.

       @param list  [{slug, price, available, sizes:[{id, price}]}]
       @return [{nameFa, from, to, gone}]                                    */
    syncPrices: function (list) {
      if (!Array.isArray(list) || !list.length) { return []; }

      var bySlug = {};
      list.forEach(function (p) { bySlug[p.slug] = p; });

      var changes = [];

      state.items = state.items.map(function (item) {
        var fresh = bySlug[item.slug];
        if (!fresh) { return item; }

        if (!fresh.available) {
          changes.push({ nameFa: item.nameFa, from: item.price, to: null, gone: true });
          return Object.assign({}, item, { unavailable: true });
        }

        /* A size carries its own price, so resolve the same way the server
           does rather than falling back to the parent's. */
        var price = fresh.price;
        if (item.sizeId && Array.isArray(fresh.sizes)) {
          for (var i = 0; i < fresh.sizes.length; i++) {
            if (fresh.sizes[i].id === item.sizeId) { price = fresh.sizes[i].price; break; }
          }
        }

        if (typeof price !== 'number' || price <= 0 || price === item.price) {
          return Object.assign({}, item, { unavailable: false });
        }

        changes.push({ nameFa: item.nameFa, from: item.price, to: price, gone: false });
        return Object.assign({}, item, { price: price, unavailable: false });
      });

      if (changes.length) { emit(); }
      return changes;
    },

    /* Persisted with the cart so a returning shopper does not retype their
       address. It never leaves the browser except inside the order message. */
    setCustomer: function (fields) {
      state.customer = Object.assign({}, state.customer, fields);
      emit();
    },
    getCustomer: function () { return Object.assign({}, state.customer); },

    /* Totals are computed here, never in a template. */
    snapshot: function () {
      var subtotal = state.items.reduce(function (sum, i) { return sum + i.price * i.qty; }, 0);
      var count = state.items.reduce(function (sum, i) { return sum + i.qty; }, 0);
      var freeShipping = subtotal >= cfg.freeShippingThreshold;
      var shipping = count === 0 || freeShipping ? 0 : cfg.shippingFlatRate;

      return {
        customer: Object.assign({}, state.customer),
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

      /* The whole point of collecting these is that they arrive WITH the order
         rather than being asked for afterwards, one message at a time. */
      var c = state.customer || {};
      var filled = (cfg.customerFields || []).filter(function (f) {
        return String(c[f.id] || '').trim();
      });
      if (filled.length) {
        lines.push('');
        lines.push(DIVIDER);
        lines.push('مشخصات گیرنده:');
        filled.forEach(function (f) {
          lines.push(f.label.replace(' (اختیاری)', '') + ': ' + String(c[f.id]).trim());
        });
      }

      return lines.join('\n');
    },

    /* What the server needs to record this order.

       Note what is absent: prices, line totals and the grand total. The server
       recomputes all of them from its own catalogue, so sending them would be
       pointless at best and, if the server trusted them, an open invitation to
       edit localStorage and set your own price. */
    asOrderPayload: function (channelId) {
      return {
        items: state.items.map(function (i) {
          return {
            slug: i.slug,
            flavorId: i.flavorId || null,
            sizeId: i.sizeId || null,
            qty: i.qty,
          };
        }),
        customer: Object.assign({}, state.customer),
        channel: channelId || '',
      };
    },

    /* Channels the shopper can actually pick from, in config order. */
    channels: function () {
      return (cfg.ordering.channels || []).filter(function (c) { return c.enabled; });
    },

    /* Decides how a channel receives the order: in the URL, or via the
       clipboard.

       The length guard matters more than it looks. Persian percent-encodes to
       roughly 4.6x its character count — every letter becomes %D8%A8 — so a
       four-item order with a real address reaches ~3,400 characters. Apps and
       browsers disagree about how long a URL may be, and the failure mode is
       silent truncation: the order arrives cut off mid-line and neither side
       notices until something is missing from the parcel. Above the limit the
       clipboard route is used instead, which has no length ceiling. */
    resolveChannel: function (channel) {
      if (!channel || !channel.url) { return null; }
      if (!channel.prefillParam) {
        return { url: channel.url, needsPaste: true };
      }
      var sep = channel.url.indexOf('?') === -1 ? '?' : '&';
      var full = channel.url + sep + encodeURIComponent(channel.prefillParam) +
                 '=' + encodeURIComponent(api.asOrderText());
      var limit = cfg.ordering.maxPrefillUrlLength || 2000;
      if (full.length > limit) {
        return { url: channel.url, needsPaste: true, tooLongForUrl: true };
      }
      return { url: full, needsPaste: false };
    },

    /* Kept for callers that only want the address. */
    channelUrl: function (channel) {
      var r = api.resolveChannel(channel);
      return r ? r.url : null;
    },

    init: function () { load(); emit(); },
  };

  return api;
})();
