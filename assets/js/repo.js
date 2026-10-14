/* ==========================================================================
   SUPPEX — Repository layer
   --------------------------------------------------------------------------
   The single seam between the UI and wherever data actually comes from.
   Every method returns a Promise, even though the prototype resolves from an
   in-memory object. That is the whole point: when the backend lands, only the
   bodies of these methods change — no caller, renderer or page controller is
   touched.

       SUPPEX.config.api.baseUrl = null          -> bundled catalogue
       SUPPEX.config.api.baseUrl = 'https://…'   -> real HTTP calls
   ========================================================================== */
window.SUPPEX = window.SUPPEX || {};

SUPPEX.repo = (function () {
  'use strict';

  var cfg = SUPPEX.config;
  var db = SUPPEX.catalog;

  /* Simulated latency, so loading/skeleton states are exercised during a demo
     instead of only appearing in production. Zero in the prototype by default. */
  var LATENCY = 0;

  function local(value) {
    return new Promise(function (resolve) {
      if (LATENCY) { setTimeout(function () { resolve(value); }, LATENCY); }
      else { resolve(value); }
    });
  }

  /* The API is a single front controller routed by ?r=, rather than a path per
     endpoint. That is a deliberate choice for shared hosting: pretty paths
     would need mod_rewrite, which is not guaranteed to be enabled and fails in
     a way that looks like the whole site is broken. A query parameter works on
     any server that can run PHP at all. */
  function apiUrl(route, params) {
    var base = cfg.api.baseUrl;
    var url = base + (base.indexOf('?') === -1 ? '?' : '&') + 'r=' + encodeURIComponent(route);
    Object.keys(params || {}).forEach(function (k) {
      var v = params[k];
      if (v == null || v === '') { return; }
      url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v);
    });
    return url;
  }

  /* The answers the page arrived with (lib/boot.php). On this host each ?r=
     call is its own PHP process and only a few run at once: five fired
     together came back after as long as nineteen seconds, and Google's
     renderer gave up on them. So a page carries what it is going to ask for,
     and asking costs nothing.

     The key is the route and its parameters, empty ones left out, sorted by
     name - boot_key() in lib/boot.php, character for character. */
  var BOOT = window.SUPPEX_BOOT || {};

  function bootKey(route, params) {
    var p = params || {};
    var keys = Object.keys(p).filter(function (k) {
      var v = p[k];
      return v != null && v !== '' && v !== false;
    }).sort();
    return route + (keys.length ? '?' + keys.map(function (k) {
      return k + '=' + (p[k] === true ? '1' : String(p[k]));
    }).join('&') : '');
  }

  /* Every caller gets its own copy, as it always did from res.json(): a
     renderer that decorates what it is handed must not decorate the next
     caller's answer too. */
  function copy(v) { return v == null ? v : JSON.parse(JSON.stringify(v)); }

  /* A request already on its way is shared rather than sent again. Every
     page asked for ?r=config twice - the footer and the cart each wanted it -
     and the home page asked for the categories twice. */
  var pending = {};

  function remote(route, params) {
    var key = bootKey(route, params);
    if (Object.prototype.hasOwnProperty.call(BOOT, key)) {
      return Promise.resolve(copy(BOOT[key]));
    }
    if (!pending[key]) {
      var url = apiUrl(route, params);
      pending[key] = fetch(url, { headers: { Accept: 'application/json' } }).then(function (res) {
        if (!res.ok) { throw new Error('Request failed: ' + res.status + ' ' + url); }
        return res.json();
      });
      var done = function () { delete pending[key]; };
      pending[key].then(done, done);
    }
    return pending[key].then(copy);
  }

  function isRemote() { return !!cfg.api.baseUrl; }

  /* --- Derived helpers (kept here so views never compute business rules) -- */

  function withDerived(p) {
    if (!p) { return p; }
    var onSale = !!(cfg.flags.saleMode && p.compareAt && p.compareAt > p.price);
    return Object.assign({}, p, {
      onSale: onSale,
      discountPercent: onSale ? Math.round((1 - p.price / p.compareAt) * 100) : 0,
    });
  }

  /* A brand's slug, the way lib/brands.php's brand_slug() makes it: its name
     (brandName() below) in lower case, and anything that is not a letter or a
     digit one hyphen. The API sends the slug with everything that needs one,
     so this is for the bundled catalogue alone.

     The Unicode pattern is built at run time, and falls back to Latin,
     Persian and digits: as a literal, \p{L} is a syntax error to an older
     browser, and a syntax error in this file stops the whole shop. */
  var NOT_WORD = (function () {
    try { return new RegExp('[^\\p{L}\\p{N}]+', 'gu'); }
    catch (e) { return /[^a-z0-9\u0600-\u06ff]+/g; }
  })();

  var ARABIC = /[\u0600-\u06ff\u0750-\u077f\u08a0-\u08ff\ufb50-\ufdff\ufe70-\ufeff]/;

  /* A brand's name as brand_parts() reads it: what is in brackets set aside,
     and of «A / B» the first - «Optimum Nutrition (اپتیموم نوتریشن)» and
     «Karen Pharma / PNC» come down to «Optimum Nutrition» and «Karen
     Pharma». A brand written only in brackets, in Persian, is that name. */
  function brandName(raw) {
    var s = String(raw == null ? '' : raw).replace(/\s+/g, ' ').trim();
    var name = s.replace(/\([^()]*\)/g, ' ').trim().split(/\s+\/\s+/)[0]
      .replace(/\s+/g, ' ').trim();
    if (!name) {
      var groups = s.match(/\([^()]*\)/g) || [];
      for (var i = 0; i < groups.length; i++) {
        var inner = groups[i].slice(1, -1);
        if (ARABIC.test(inner)) { name = inner.trim().split(/\s*\/\s*/)[0].trim(); break; }
      }
    }
    return name;
  }

  function brandSlug(raw) {
    var s = brandName(raw)
      .replace(/[\u06f0-\u06f9]/g, function (d) { return String(d.charCodeAt(0) - 0x06f0); })
      .replace(/[\u0660-\u0669]/g, function (d) { return String(d.charCodeAt(0) - 0x0660); })
      .toLowerCase()
      .replace(NOT_WORD, '-')
      .replace(/^-+|-+$/g, '');
    return s.slice(0, 110).replace(/-+$/, '');
  }

  return {
    brandSlug: brandSlug,
    brandName: brandName,
    /* For tests/test-speed.mjs, which holds it to the table boot_key() is
       held to (tests/boot-keys.json). */
    bootKey: bootKey,

    /* ------------------------------------------------------------------ */
    getCategories: function () {
      if (isRemote()) { return remote(cfg.api.endpoints.categories); }
      return local(db.categories.slice());
    },

    getCategory: function (id) {
      return this.getCategories().then(function (list) {
        return list.filter(function (c) { return c.id === id; })[0] || null;
      });
    },

    /* The logo wall (?r=brands): brands with something on sale, in the
       owner's order. The bundled catalogue has no logos and no order, so
       offline it is its brands, most products first. */
    getBrands: function () {
      if (isRemote()) { return remote('brands'); }
      var bySlug = {};
      var order = [];
      db.products.forEach(function (p) {
        var slug = brandSlug(p.brand);
        if (!slug) { return; }
        if (!bySlug[slug]) {
          bySlug[slug] = { slug: slug, name: brandName(p.brand), nameFa: '', logo: '', count: 0 };
          order.push(slug);
        }
        bySlug[slug].count++;
      });
      var list = order.map(function (s) { return bySlug[s]; });
      list.sort(function (a, b) { return b.count - a.count || (a.name < b.name ? -1 : a.name > b.name ? 1 : 0); });
      return local(list);
    },

    /* opts: { category, badge, limit, exclude, brand } */
    getProducts: function (opts) {
      opts = opts || {};
      if (isRemote()) { return remote(cfg.api.endpoints.products, opts); }

      var list = db.products.slice();
      if (opts.category) {
        list = list.filter(function (p) { return p.category === opts.category; });
      }
      if (opts.notCategory) {
        list = list.filter(function (p) { return p.category !== opts.notCategory; });
      }
      if (opts.badge) {
        list = list.filter(function (p) { return (p.badges || []).indexOf(opts.badge) !== -1; });
      }
      if (opts.exclude) {
        list = list.filter(function (p) { return p.slug !== opts.exclude; });
      }
      if (opts.brand) {
        var want = brandSlug(opts.brand);
        list = list.filter(function (p) { return brandSlug(p.brand) === want; });
      }
      if (opts.limit) { list = list.slice(0, opts.limit); }
      return local(list.map(withDerived));
    },

    getProduct: function (slug) {
      if (isRemote()) { return remote(cfg.api.endpoints.product, { slug: slug }); }
      var found = db.products.filter(function (p) { return p.slug === slug; })[0];
      return local(found ? withDerived(found) : null);
    },

    search: function (query) {
      var q = (query || '').trim().toLowerCase();
      if (!q) { return local([]); }
      if (isRemote()) { return remote(cfg.api.endpoints.search, { q: q }); }

      var catName = {};
      db.categories.forEach(function (c) { catName[c.id] = c.name; });

      var hits = db.products.filter(function (p) {
        var haystack = [p.name, p.nameFa, p.brand, p.short, catName[p.category] || '']
          .join(' ')
          .toLowerCase();
        return haystack.indexOf(q) !== -1;
      });
      return local(hits.map(withDerived));
    },

    /* getReviews() lived here and returned db.reviews, which no longer
       exists - the three testimonials in it were invented. Real reviews will
       need a real source; this returned an array from a file. */
    getJournal: function () { return local(db.journal.slice()); },

    /* ------------------------------------------------------------------
       Records the order server-side.

       Only slugs, variant ids and quantities are sent. Prices deliberately
       are not: the cart lives in localStorage where anyone can edit it, so a
       total posted from here is a number the customer chose. The server looks
       every price up again and recomputes the total from its own catalogue.

       Resolves to null when there is no backend configured, which is what the
       file:// prototype does — the caller falls back to the message-only flow
       and nothing breaks. */
    /* Current prices for the slugs in a cart.

       Resolves to null with no backend configured, which is what the static
       demo does — the caller then leaves the cart alone rather than wiping it. */
    getPrices: function (slugs) {
      if (!isRemote() || !slugs || !slugs.length) { return local(null); }
      return remote(cfg.api.endpoints.prices || 'prices', { slugs: slugs.join(',') })
        .then(function (res) {
          /* The answer became {prices, quote} when the shop started standing by
             the price it displays. An array is still accepted so a cached copy
             of an older backend does not break the cart — it just carries no
             quote, and the order pays the current price as it always did. */
          if (Array.isArray(res)) { return { prices: res, quote: '' }; }
          if (res && Array.isArray(res.prices)) {
            return { prices: res.prices, quote: String(res.quote || '') };
          }
          return null;
        })
        .catch(function () {
          /* A failed refresh must not block checkout. The server recomputes
             every price when the order is placed regardless, so the worst case
             is the message showing a stale figure — which is exactly the state
             we were in before, not a regression. */
          return null;
        });
    },

    /* The shop's own settings. ?r=config has existed since the backend was
       written and nothing ever called it, so the panel wrote these values into
       the database and the storefront went on reading config.js.

       Resolves to null with no backend, which is the static demo - and a demo
       has no settings, so printing nothing is the right answer there too. */
    getShopConfig: function () {
      if (!isRemote()) { return local(null); }
      return remote(cfg.api.endpoints.config || 'config', {})
        .catch(function () { return null; });
    },

    /* Has this order changed since the device last looked?

       Resolves to null rather than rejecting on every kind of failure - a
       wrong pair, a rate limit, no network, no backend at all. Everything
       that reads this is decoration: a countdown in a bar and a dot on an
       icon. Neither is worth an error in the console, and neither may ever
       get in the way of somebody trying to buy something. */
    getOrderState: function (code, phone) {
      if (!isRemote() || !code || !phone) { return local(null); }
      return fetch(apiUrl('orderstate'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ code: code, phone: phone }),
      }).then(function (res) {
        return res.ok ? res.json() : null;
      }).then(function (body) {
        return body && body.ok ? body : null;
      }).catch(function () { return null; });
    },

    createOrder: function (payload) {
      if (!isRemote()) { return local(null); }
      return fetch(apiUrl('order'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(payload),
      }).then(function (res) {
        return res.json().then(function (body) {
          if (!res.ok) {
            var err = new Error(body && body.error ? body.error : 'order failed');
            err.fields = body && body.fields;
            throw err;
          }
          return body;
        });
      });
    },

    /* The live state of the orders this browser remembers (?r=myorders).
       null on any failure: the list then shows what the browser knows. */
    getMyOrders: function (pairs) {
      if (!isRemote() || !pairs || !pairs.length) { return local(null); }
      return fetch(apiUrl('myorders'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ orders: pairs }),
      }).then(function (res) {
        return res.ok ? res.json() : null;
      }).then(function (body) {
        return body && body.ok && Array.isArray(body.orders) ? body.orders : null;
      }).catch(function () { return null; });
    },

    /* A payment receipt for an order - ?r=receipt. XMLHttpRequest rather
       than fetch, for the upload's progress: a photograph on a phone
       connection takes long enough that a moving bar is the difference
       between waiting and tapping again. Always resolves - to the server's
       answer, or {ok:false, error} - because the box has a sentence for
       every outcome and none of them is an exception. */
    uploadReceipt: function (code, phone, blob, onProgress) {
      return new Promise(function (resolve) {
        if (!isRemote()) {
          resolve({ ok: false, error: 'ارسال رسید در این نسخه از سایت فعال نیست. رسید را در همان گفتگو بفرستید.' });
          return;
        }
        var form = new FormData();
        form.append('code', code);
        form.append('phone', phone);
        form.append('receipt', blob, 'receipt.jpg');
        var xhr = new XMLHttpRequest();
        xhr.open('POST', apiUrl('receipt'));
        xhr.setRequestHeader('Accept', 'application/json');
        if (xhr.upload && onProgress) {
          xhr.upload.onprogress = function (e) {
            if (e.lengthComputable) { onProgress(e.loaded / e.total); }
          };
        }
        xhr.onload = function () {
          var body = null;
          try { body = JSON.parse(xhr.responseText); } catch (err) { body = null; }
          if (body && typeof body.ok === 'boolean') { resolve(body); return; }
          resolve({ ok: false, error: (body && body.error) || 'رسید فرستاده نشد. دوباره تلاش کنید.' });
        };
        xhr.onerror = function () {
          resolve({ ok: false, error: 'اتصال برقرار نشد. اینترنت را بررسی کنید و دوباره تلاش کنید.' });
        };
        xhr.send(form);
      });
    },
  };
})();
