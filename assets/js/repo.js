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

  function remote(path, params) {
    var url = cfg.api.baseUrl + path;
    if (params) {
      var qs = Object.keys(params)
        .filter(function (k) { return params[k] != null && params[k] !== ''; })
        .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); })
        .join('&');
      if (qs) { url += '?' + qs; }
    }
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
      if (isRemote()) {
        return remote(cfg.api.endpoints.product.replace(':slug', encodeURIComponent(slug)));
      }
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
  };
})();
