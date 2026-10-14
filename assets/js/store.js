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
  var state = { items: [], customer: {}, quote: '' };
  var DIVIDER = '—————————————';

  /* Every axis the line was chosen on, in a stable order.

     Sorted rather than taken as given: the object comes from whichever order
     the page happened to build it in, and two identical choices that hashed
     differently would sit in the basket as two lines of one each. */
  function attrKey(item) {
    var a = item.attrs;
    if (!a) { return ''; }
    var keys = Object.keys(a).sort();
    var out = [];
    for (var i = 0; i < keys.length; i++) {
      if (a[keys[i]]) { out.push(keys[i] + '=' + a[keys[i]]); }
    }
    return out.join(';');
  }

  function keyOf(item) {
    return [item.slug, item.flavorId || '-', item.sizeId || '-',
            attrKey(item) || '-'].join('::');
  }

  function load() {
    if (!cfg.flags.persistCart) { return; }
    try {
      var raw = localStorage.getItem(cfg.storageKey);
      if (!raw) { return; }
      var parsed = JSON.parse(raw);
      if (parsed && Array.isArray(parsed.items)) { state.items = parsed.items; }
      if (parsed && parsed.customer) { state.customer = parsed.customer; }
      /* A restored token may already be expired; that is not worth checking
         here. The server treats an unknown or stale token as no token at all
         and charges the current price, which is the right answer for a cart
         that has been sitting in a browser for a week. */
      if (parsed && typeof parsed.quote === 'string') { state.quote = parsed.quote; }
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
    notify();
  }

  /* The listeners alone, without writing back: for a change that came out of
     storage, which another tab has already written (see the end of this
     file). */
  function notify() {
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

    /* Take an order's lines out of the basket, and only those.

       The order is sent from a snapshot and the server takes a moment to
       answer. Emptying the whole basket when it did threw away whatever the
       shopper added in that moment - two more of the same tub included, so
       this goes by quantity: what was ordered comes off, the rest stays for
       the next order. A line the order left out as unavailable goes as well;
       the drawer already said it was not being sent. */
    removeLines: function (lines) {
      var taken = {};
      (lines || []).forEach(function (l) {
        var k = keyOf(l);
        taken[k] = (taken[k] || 0) + Math.max(0, l.qty || 0);
      });
      state.items = state.items
        .map(function (i) {
          var k = keyOf(i);
          return taken[k] ? Object.assign({}, i, { qty: i.qty - taken[k] }) : i;
        })
        .filter(function (i) { return i.qty > 0; });
      emit();
    },

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
        /* ?r=prices omits slugs it does not know rather than reporting them as
           unavailable, so a product deleted while the cart sat in localStorage
           came back as "unchanged" and was quoted to the customer at a price
           the server would refuse to honour. Absent means gone. */
        if (!fresh) {
          changes.push({ nameFa: item.nameFa, from: item.price, to: null, gone: true });
          return Object.assign({}, item, { unavailable: true });
        }

        if (!fresh.available) {
          changes.push({ nameFa: item.nameFa, from: item.price, to: null, gone: true });
          return Object.assign({}, item, { unavailable: true });
        }

        var price = fresh.price;
        var left  = fresh.stock;

        /* A combination first, exactly as order_price_line() resolves it.

           Once a product has these, one row holds the whole price - there is
           no base and premium left to add up, and running the delta
           arithmetic below would walk the line back to the size's price. The
           drawer would then show one number while the server charged
           another. */
        var combo = null;
        if (Array.isArray(fresh.variants) && fresh.variants.length) {
          var want = {};
          if (item.sizeId)   { want.size = item.sizeId; }
          if (item.flavorId) { want.flavor = item.flavorId; }
          if (item.attrs) {
            Object.keys(item.attrs).forEach(function (k) { want[k] = item.attrs[k]; });
          }
          for (var vi = 0; vi < fresh.variants.length; vi++) {
            var c = fresh.variants[vi].choice || {};
            var ck = Object.keys(c);
            var hit = ck.length === Object.keys(want).length;
            for (var cj = 0; hit && cj < ck.length; cj++) {
              if (want[ck[cj]] !== c[ck[cj]]) { hit = false; }
            }
            if (hit) { combo = fresh.variants[vi]; break; }
          }

          /* A product that HAS combinations but none matching this line means
             the shopper is holding one that was switched off or renamed. The
             server refuses it, so the drawer must say so here rather than
             quote a price that will not be honoured. */
          if (!combo) {
            changes.push({ nameFa: item.nameFa, from: item.price, to: null, gone: true });
            return Object.assign({}, item, { unavailable: true });
          }
        }

        if (combo) {
          price = combo.price;
          left  = combo.stock;
        } else if (item.sizeId && Array.isArray(fresh.sizes) && fresh.sizes.length) {
          /* A size carries its own price, so resolve the same way the server
             does rather than falling back to the parent's. */
          var sizeHit = false;
          for (var i = 0; i < fresh.sizes.length; i++) {
            if (fresh.sizes[i].id === item.sizeId) {
              price = fresh.sizes[i].price;
              left  = fresh.sizes[i].stock;
              sizeHit = true;
              break;
            }
          }
          /* A size the product no longer has - renamed or removed. The server
             refuses that line (order_price_line()), so the drawer must not go
             on quoting it at the product's own price. */
          if (!sizeHit) {
            changes.push({ nameFa: item.nameFa, from: item.price, to: null, gone: true });
            return Object.assign({}, item, { unavailable: true });
          }
        }

        /* Then the flavour premium, resolved the same way the server does in
           order_price_line: the base plus whatever this flavour adds. Without
           this the drawer would quietly walk a premium flavour back to the
           plain price every time it refreshed. A flavour that is not there any
           more is refused by the server, and is marked so here. */
        if (!combo && item.flavorId && Array.isArray(fresh.flavors)) {
          var flavorHit = false;
          for (var k = 0; k < fresh.flavors.length; k++) {
            if (fresh.flavors[k].id === item.flavorId) {
              price += (fresh.flavors[k].priceDelta || 0);
              flavorHit = true;
              break;
            }
          }
          if (!flavorHit) {
            changes.push({ nameFa: item.nameFa, from: item.price, to: null, gone: true });
            return Object.assign({}, item, { unavailable: true });
          }
        }

        var next = Object.assign({}, item, { unavailable: false });

        /* A cart holding three of the last one. Caught here rather than at
           checkout: the order endpoint refuses it correctly, but by then the
           shopper has typed their name, phone, address and postal code, and
           being told to go back and edit the basket is where an order gets
           abandoned. typeof, not truthiness - zero is a real answer. */
        if (typeof left === 'number' && left < item.qty) {
          changes.push({
            nameFa: item.nameFa, from: item.price, to: null,
            gone: left === 0, qtyFrom: item.qty, qtyTo: left,
          });
          if (left === 0) {
            next.unavailable = true;
          } else {
            next.qty = left;
          }
        }

        if (typeof price !== 'number' || price <= 0 || price === item.price) {
          return next;
        }

        changes.push({ nameFa: item.nameFa, from: item.price, to: price, gone: false });
        next.price = price;
        return next;
      });

      if (changes.length) { emit(); }
      return changes;
    },

    /* The shop's promise about the prices just handed over. Held with the cart
       so it survives the shopper wandering off mid-checkout, which is the whole
       window this is meant to cover. See lib/quotes.php. */
    setQuote: function (token) {
      state.quote = typeof token === 'string' ? token : '';
      emit();
    },

    /* Persisted with the cart so a returning shopper does not retype their
       address. It never leaves the browser except inside the order message. */
    setCustomer: function (fields, quiet) {
      state.customer = Object.assign({}, state.customer, fields);
      /* Quiet while the shopper is typing: kept, so a drawer closed by
         mistake or a reload does not lose an address half written, but not
         repainted - a repaint per keystroke would bump the basket's badge on
         every letter. */
      if (quiet) { save(); } else { emit(); }
    },
    getCustomer: function () { return Object.assign({}, state.customer); },

    /* Totals are computed here, never in a template. */
    snapshot: function () {
      /* Only what can actually be bought counts. An unavailable line stays in
         `items` so the drawer can show the shopper what was dropped and why,
         but it must not reach the subtotal, the free-shipping threshold or the
         order text - the server drops it too, and the two numbers have to be
         the same number. */
      var live = state.items.filter(function (i) { return !i.unavailable; });
      var subtotal = live.reduce(function (sum, i) { return sum + i.price * i.qty; }, 0);
      var count = live.reduce(function (sum, i) { return sum + i.qty; }, 0);
      var blocked = state.items.length - live.length;
      /* Zero means the shop has no free shipping at all, the way
         order_create() reads it. Read here as «everything ships free», the
         drawer said «رایگان» over an order the server charged the flat rate. */
      var threshold = cfg.freeShippingThreshold > 0 ? cfg.freeShippingThreshold : 0;
      var freeShipping = threshold > 0 && subtotal >= threshold;
      var shipping = count === 0 || freeShipping ? 0 : cfg.shippingFlatRate;

      return {
        customer: Object.assign({}, state.customer),
        items: state.items.map(function (i) {
          return Object.assign({}, i, { key: keyOf(i), lineTotal: i.price * i.qty });
        }),
        count: count,
        /* How many lines cannot be ordered. The checkout uses this to refuse to
           send an order that the server would silently shrink. */
        blocked: blocked,
        subtotal: subtotal,
        shipping: shipping,
        total: subtotal + shipping,
        freeShipping: freeShipping,
        remainingForFreeShipping: threshold > 0 ? Math.max(0, threshold - subtotal) : 0,
        /* Whether there is a free-shipping line to draw at all. */
        freeShippingOffered: threshold > 0,
      };
    },

    /* Formats the cart as a plain-text order for a messaging app. Kept in the
       store rather than a view because it is the order itself, not a piece of
       presentation — the same text a server would eventually receive. */
    /* `snap` is the snapshot an order was sent from, when there is one: the
       basket moves on after the order (removeLines()), and the text copied
       again from the payment panel has to be the order that was sent. `code`
       is its tracking code, once the shop has given one. */
    asOrderText: function (snap, code) {
      snap = snap || api.snapshot();
      var money = cfg.currency.format;
      var unit = cfg.currency.label;
      var lines = [cfg.ordering.intro, ''];
      if (code) { lines.push('شماره پیگیری سفارش: ' + code, ''); }

      snap.items.filter(function (it) { return !it.unavailable; }).forEach(function (item, i) {
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
      var c = snap.customer || state.customer || {};
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

      /* Signed like everything the shop sends (lib/order_messages.php), so the
         site's address stays in the customer's own chat beside the order. The
         host is read from the page rather than written here, for the reason
         config.js keeps its paths relative. */
      var host = '';
      try { host = String(window.location.hostname || '').replace(/^www\./, ''); } catch (e) { host = ''; }
      lines.push('');
      lines.push('ثبت‌شده در ' + cfg.brand.name + (host ? ' — ' + host : ''));

      return lines.join('\n');
    },

    /* What the server needs to record this order.

       Note what is absent: prices, line totals and the grand total. The server
       recomputes all of them from its own catalogue, so sending them would be
       pointless at best and, if the server trusted them, an open invitation to
       edit localStorage and set your own price. */
    asOrderPayload: function (channelId) {
      return {
        /* The server would refuse these anyway; sending them just makes the
           response disagree with what the shopper was shown. */
        items: state.items.filter(function (i) { return !i.unavailable; }).map(function (i) {
          return {
            slug: i.slug,
            flavorId: i.flavorId || null,
            sizeId: i.sizeId || null,
            /* Only when there is one. An empty object on every line would
               make every order payload claim a third axis nobody chose. */
            attrs: (i.attrs && Object.keys(i.attrs).length) ? i.attrs : undefined,
            qty: i.qty,
          };
        }),
        customer: Object.assign({}, state.customer),
        channel: channelId || '',
        /* What the shop said these would cost. The server charges the lower of
           this and the current price, so the amount the customer already pasted
           into their chat cannot be undercut by a repricing that happened while
           they were typing their address. */
        quote: state.quote || '',
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

  /* Another tab changed the basket.

     Each tab keeps the cart in memory and writes the whole of it back on
     every change, so the tab that wrote last won: a basket filled in one tab
     was wiped by a quantity changed in another left open since the morning,
     and the order sent from that one was not the basket the shopper had just
     built. Re-read and repaint - and do not write back; the other tab already
     has. */
  if (typeof window.addEventListener === 'function') {
    window.addEventListener('storage', function (e) {
      if (!cfg.flags.persistCart || (e.key !== null && e.key !== cfg.storageKey)) { return; }
      state = { items: [], customer: {}, quote: '' };
      load();
      notify();
    });
  }

  return api;
})();
