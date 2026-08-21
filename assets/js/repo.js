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

  function remote(route, params) {
    var url = apiUrl(route, params);
    return fetch(url, { headers: { Accept: 'application/json' } }).then(function (res) {
      if (!res.ok) { throw new Error('Request failed: ' + res.status + ' ' + url); }
      return res.json();
    });
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

  return {
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

    /* opts: { category, badge, limit, exclude } */
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

    getReviews: function () { return local(db.reviews.slice()); },
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
  };
})();
