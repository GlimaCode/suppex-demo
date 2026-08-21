/* ==========================================================================
   SUPPEX — Application
   --------------------------------------------------------------------------
   Wiring only. Shared chrome (header, search, cart drawer, toasts) is set up
   for every page; the per-page controllers below then fill in whatever
   containers that page happens to declare. A page opts into a feature purely
   by including the matching data-attribute, so adding a category or checkout
   page later needs no changes here.
   ========================================================================== */
window.SUPPEX = window.SUPPEX || {};

SUPPEX.app = (function () {
  'use strict';

  var cfg = SUPPEX.config;
  var repo = SUPPEX.repo;
  var store = SUPPEX.store;
  var ui = SUPPEX.ui;

  /* Products already fetched, so an add-to-cart click doesn't need a round
     trip. With a real API this becomes a tiny client-side cache. */
  var productCache = {};

  function cache(list) {
    list.forEach(function (p) { productCache[p.slug] = p; });
    return list;
  }

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  /* ====================================================================
     Scroll reveal
     ==================================================================== */
  var revealObserver = null;
  var revealFired = false;

  /* Reveal elements start at opacity 0, so anything that stops the observer
     from reporting would leave the page blank. That is an unacceptable way to
     fail in front of a client, so if no reveal has happened shortly after
     load, the effect is abandoned wholesale and everything is shown. */
  function armRevealFailsafe() {
    setTimeout(function () {
      if (revealFired) { return; }
      document.documentElement.classList.add('no-reveal');
      if (revealObserver) { revealObserver.disconnect(); }
    }, 2500);
  }

  function observeReveals(root) {
    if (!('IntersectionObserver' in window)) {
      document.documentElement.classList.add('no-reveal');
      return;
    }
    if (!revealObserver) {
      revealObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            revealFired = true;
            entry.target.classList.add('is-in');
            revealObserver.unobserve(entry.target);
          }
        });
      }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });
      armRevealFailsafe();
    }
    $$('.reveal:not(.is-in)', root).forEach(function (el, i) {
      /* Stagger within a group so a grid cascades instead of popping at once */
      el.style.setProperty('--reveal-delay', Math.min(i, 7) * 55 + 'ms');
      revealObserver.observe(el);
    });
  }

  /* ====================================================================
     Toasts
     ==================================================================== */
  function toast(message) {
    var stack = $('[data-toasts]');
    if (!stack) { return; }
    var el = document.createElement('div');
    el.className = 'toast';
    el.setAttribute('role', 'status');
    el.innerHTML = ui.icon('check') + '<span>' + ui.esc(message) + '</span>';
    stack.appendChild(el);
    setTimeout(function () {
      el.classList.add('is-out');
      setTimeout(function () { el.remove(); }, 300);
    }, 2600);
  }

  /* ====================================================================
     Overlays: mobile nav, search, cart drawer
     ==================================================================== */
  var openLayer = null;

  function setLayer(name) {
    openLayer = name;
    document.body.classList.toggle('is-locked', !!name);

    var scrim = $('[data-scrim]');
    if (scrim) { scrim.classList.toggle('is-open', !!name); }

    var nav = $('[data-mobile-nav]');
    var drawer = $('[data-drawer]');
    var search = $('[data-search]');

    if (nav) { nav.classList.toggle('is-open', name === 'nav'); }
    if (drawer) {
      if (name === 'cart') { resetPaymentStep(); }
      drawer.classList.toggle('is-open', name === 'cart');
    }
    if (search) { search.classList.toggle('is-open', name === 'search'); }

    if (name === 'search') {
      var input = $('[data-search-input]');
      if (input) { setTimeout(function () { input.focus(); }, 60); }
    }
  }

  function closeLayers() { setLayer(null); }

  /* ====================================================================
     Cart rendering
     ==================================================================== */
  function renderCart(snapshot) {
    var count = $('[data-cart-count]');
    if (count) {
      count.textContent = snapshot.count;
      count.hidden = snapshot.count === 0;
      count.classList.add('is-bump');
      setTimeout(function () { count.classList.remove('is-bump'); }, 220);
    }

    var body = $('[data-cart-items]');
    if (!body) { return; }

    if (!snapshot.items.length) {
      body.innerHTML =
        '<div class="empty-state">' + ui.icon('cart') +
        '<p style="font-weight:800;margin-bottom:6px">سبد خرید خالی است</p>' +
        '<p class="u-sm">هنوز محصولی اضافه نکرده‌اید.</p></div>';
    } else {
      body.innerHTML = snapshot.items.map(ui.lineItem).join('');
    }

    var sub = $('[data-cart-subtotal]');
    if (sub) { sub.textContent = ui.money(snapshot.subtotal); }

    var ship = $('[data-cart-shipping]');
    if (ship) {
      ship.textContent = snapshot.count === 0
        ? '—'
        : (snapshot.freeShipping ? 'رایگان' : ui.money(snapshot.shipping) + ' ' + cfg.currency.label);
    }

    var total = $('[data-cart-total]');
    if (total) { total.textContent = ui.money(snapshot.total); }

    var hint = $('[data-cart-hint]');
    if (hint) {
      if (snapshot.count === 0) {
        hint.textContent = '';
      } else if (snapshot.freeShipping) {
        hint.innerHTML = ui.icon('truck') + ' ارسال این سفارش رایگان است';
      } else {
        hint.innerHTML = ui.icon('truck') + ' <span><span class="num">' +
          ui.money(snapshot.remainingForFreeShipping) + '</span> ' + cfg.currency.label +
          ' تا ارسال رایگان</span>';
      }
    }

    var checkout = $('[data-checkout]');
    if (checkout) { checkout.disabled = snapshot.count === 0; }
    setChannelsEnabled(snapshot.count > 0);
  }

  /* ====================================================================
     Payment step
     ==================================================================== */

  /* Iranian bank cards are 16 digits and carry a Luhn check digit. A typo in
     config.payment.cardNumber would otherwise fail silently — the shopper
     copies it, the transfer bounces, and the order is simply lost. */
  function isValidCard(digits) {
    if (!/^\d{16}$/.test(digits)) { return false; }
    var sum = 0;
    for (var i = 0; i < 16; i++) {
      var d = +digits[15 - i];
      if (i % 2) { d *= 2; if (d > 9) { d -= 9; } }
      sum += d;
    }
    return sum % 10 === 0;
  }

  function warnAboutCard() {
    if (cfg.payment.method !== 'card') { return; }
    var digits = String(cfg.payment.cardNumber || '').replace(/\D/g, '');
    if (!isValidCard(digits)) {
      console.warn('[suppex] config.payment.cardNumber is not a valid 16-digit ' +
                   'card number — a transfer to it will be rejected. Set the real one.');
    }
  }

  /* Replaces the cart contents with the amount and card details. Kept inside
     the drawer the shopper already has open rather than a new modal. */
  function showPaymentStep(snapshot) {
    var body = $('[data-cart-items]');
    var foot = $('[data-drawer] .drawer__foot');
    if (!body) { return; }
    body.innerHTML = ui.paymentPanel(snapshot);
    if (foot) { foot.hidden = true; }
  }

  function resetPaymentStep() {
    var foot = $('[data-drawer] .drawer__foot');
    if (foot && foot.hidden) {
      foot.hidden = false;
      renderCart(store.snapshot());
    }
  }

  /* renderCart used to disable a single [data-checkout] button; with a channel
     list it has to grey out the whole group instead. */
  function setChannelsEnabled(enabled) {
    $$('[data-channels] [data-channel]').forEach(function (b) { b.disabled = !enabled; });
  }

  function sendOrder(channelId) {
    var snapshot = store.snapshot();
    if (snapshot.count === 0) { return; }

    var channel = store.channels().filter(function (c) { return c.id === channelId; })[0];
    if (!channel) { return; }

    /* Apps that take a prefill parameter get the order in the URL. The rest
       get the bare chat link, with the order put on the clipboard first so the
       shopper only has to paste — that works no matter what the app supports. */
    if (!channel.prefillParam && navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(store.asOrderText()).then(function () {
        toast('متن سفارش کپی شد — در گفتگو پیست کنید');
      }, function () { /* the panel still shows the order, so this is not fatal */ });
    }

    /* Opened synchronously inside the click: a popup blocker only permits it
       while the gesture is still on the stack. */
    window.open(store.channelUrl(channel), '_blank', 'noopener');

    pingWebhook(snapshot, channel);
    showPaymentStep(snapshot);
  }

  /* Fire-and-forget POST to whatever endpoint the shop points at — the seam an
     SMS notification plugs into. No key lives here; the endpoint holds it. */
  function pingWebhook(snapshot, channel) {
    var url = cfg.notify && cfg.notify.webhookUrl;
    if (!url) { return; }
    try {
      fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          channel: channel.id,
          total: snapshot.total,
          items: snapshot.items.map(function (i) {
            return { name: i.nameFa, variant: i.variantLabel, qty: i.qty, price: i.price };
          }),
          text: store.asOrderText(),
        }),
        keepalive: true,   // survives the tab losing focus to the messaging app
      }).catch(function () { /* the order already went out by chat; never block on this */ });
    } catch (err) { /* ditto */ }
  }

  function copyCard(btn) {
    var digits = btn.getAttribute('data-copy-card') || '';
    var done = function () {
      var original = btn.textContent;
      btn.textContent = 'کپی شد';
      btn.classList.add('is-done');
      setTimeout(function () {
        btn.textContent = original;
        btn.classList.remove('is-done');
      }, 1600);
    };
    /* navigator.clipboard needs a secure context; on plain http it is absent,
       so fall back to the old execCommand trick rather than failing silently. */
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(digits).then(done, function () { legacyCopy(digits, done); });
    } else {
      legacyCopy(digits, done);
    }
  }

  function legacyCopy(text, done) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.cssText = 'position:absolute;left:-9999px;top:0';
    document.body.appendChild(ta);
    ta.select();

    /* execCommand reports failure by RETURNING false, not by throwing. Taking
       the return value on trust would flash "کپی شد" over an empty clipboard,
       and the shopper would paste nothing into their banking app. */
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
    ta.remove();

    if (ok) { done(); }
    else { toast('کپی نشد — شماره را دستی انتخاب کنید'); }
  }

  function addToCart(product, extras) {
    extras = extras || {};
    store.add({
      slug: product.slug,
      nameFa: product.nameFa,
      name: product.name,
      image: product.image,
      price: extras.price != null ? extras.price : product.price,
      variantLabel: extras.variantLabel || product.variantLabel,
      flavorId: extras.flavorId || null,
      sizeId: extras.sizeId || null,
      qty: extras.qty || 1,
    });
    toast(product.nameFa + ' به سبد خرید اضافه شد');
  }

  /* ====================================================================
     Global chrome
     ==================================================================== */
  function initChrome() {
    /* Sticky header shadow */
    var header = $('[data-header]');
    if (header) {
      var onScroll = function () {
        header.classList.toggle('is-stuck', window.scrollY > 8);
      };
      window.addEventListener('scroll', onScroll, { passive: true });
      onScroll();
    }

    /* Year in the footer */
    $$('[data-year]').forEach(function (el) {
      el.textContent = new Date().toLocaleDateString('fa-IR-u-nu-latn', { year: 'numeric' });
    });

    /* One delegated click handler for every overlay trigger and cart action */
    document.addEventListener('click', function (e) {
      var t = e.target.closest('[data-open]');
      if (t) { e.preventDefault(); setLayer(t.getAttribute('data-open')); return; }

      if (e.target.closest('[data-close]') || e.target.closest('[data-scrim]')) {
        e.preventDefault(); closeLayers(); return;
      }

      /* Add to cart from any product card on the page */
      var add = e.target.closest('[data-add]');
      if (add) {
        e.preventDefault();
        var product = productCache[add.getAttribute('data-add')];
        if (product) {
          addToCart(product);
          add.classList.add('is-done');
          add.innerHTML = ui.icon('check');
          setTimeout(function () {
            add.classList.remove('is-done');
            add.innerHTML = ui.icon('plus');
          }, 1200);
        }
        return;
      }

      /* Quantity and removal inside the drawer */
      var row = e.target.closest('.line-item');
      if (row) {
        var key = row.getAttribute('data-key');
        var step = e.target.closest('[data-qty]');
        if (step) {
          var delta = parseInt(step.getAttribute('data-qty'), 10);
          var item = store.snapshot().items.filter(function (i) { return i.key === key; })[0];
          if (item) { store.setQty(key, item.qty + delta); }
          return;
        }
        if (e.target.closest('[data-remove]')) { store.remove(key); return; }
      }

      /* Send the order through the chosen channel */
      var chan = e.target.closest('[data-channel]');
      if (chan) {
        e.preventDefault();
        sendOrder(chan.getAttribute('data-channel'));
        return;
      }

      /* Copy the card number */
      var copyBtn = e.target.closest('[data-copy-card]');
      if (copyBtn) {
        e.preventDefault();
        copyCard(copyBtn);
        return;
      }

      /* Accordions */
      var trigger = e.target.closest('.acc__trigger');
      if (trigger) {
        var accItem = trigger.closest('.acc__item');
        var isOpen = accItem.classList.toggle('is-open');
        trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        return;
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && openLayer) { closeLayers(); }
      /* Ctrl/Cmd+K opens search, as in every modern storefront */
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        setLayer(openLayer === 'search' ? null : 'search');
      }
    });

    /* Checkout hands the order to a messaging app in phase 1. Opened in a new
       tab so the shopper's cart survives if they come back. */
    var checkout = $('[data-checkout]');
    if (checkout) {
      var channels = store.channels();
      if (!channels.length) {
        checkout.textContent = 'راهی برای ثبت سفارش تنظیم نشده';
        checkout.disabled = true;
      } else {
        /* The lone button is replaced by one per channel, so the shopper picks
           the app they actually use rather than being pushed onto Telegram. */
        checkout.outerHTML = '<div class="channels" data-channels>' +
          ui.channelButtons(channels) + '</div>';
      }
    }

    /* Newsletter — validates, then reports without sending anywhere */
    $$('[data-newsletter]').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var input = $('input', form);
        if (!input.value || input.validity.typeMismatch) {
          input.focus();
          toast('لطفاً یک ایمیل معتبر وارد کنید');
          return;
        }
        input.value = '';
        toast('عضویت شما ثبت شد. کد تخفیف ایمیل می‌شود');
      });
    });

    /* Search */
    var searchInput = $('[data-search-input]');
    if (searchInput) {
      var timer = null;
      searchInput.addEventListener('input', function () {
        clearTimeout(timer);
        var q = searchInput.value;
        timer = setTimeout(function () {
          var out = $('[data-search-results]');
          if (!q.trim()) {
            out.innerHTML = '<p class="u-sm u-dim" style="padding:12px 4px">نام محصول، برند یا دسته‌بندی را بنویسید.</p>';
            return;
          }
          repo.search(q).then(function (hits) {
            cache(hits);
            out.innerHTML = hits.length
              ? hits.map(ui.searchRow).join('')
              : '<p class="u-sm u-dim" style="padding:12px 4px">نتیجه‌ای برای «' + ui.esc(q) + '» پیدا نشد.</p>';
          });
        }, 140);
      });
    }

    /* Strip the fabricated social proof entirely rather than leaving empty
       shells behind — a blank "reviews" heading looks worse than no section. */
    if (!cfg.flags.showTestimonials) {
      $$('[data-social-proof]').forEach(function (el) { el.remove(); });
    }

    warnAboutCard();

    store.subscribe(renderCart);
    store.init();
  }

  /* ====================================================================
     Home page
     ==================================================================== */
  function initHome() {
    var catGrid = $('[data-categories]');
    var prodGrid = $('[data-products]');
    if (!catGrid && !prodGrid) { return; }

    if (catGrid) {
      repo.getCategories().then(function (cats) {
        catGrid.innerHTML = cats.map(ui.categoryCard).join('');
        observeReveals(catGrid);
      });
    }

    /* Featured grid with category filtering. The filter runs through the
       repository rather than hiding DOM nodes, so it behaves identically once
       a server is doing the filtering. */
    var activeCategory = null;

    function loadProducts() {
      if (!prodGrid) { return; }
      repo.getProducts({ category: activeCategory, limit: 8 }).then(function (list) {
        cache(list);
        prodGrid.innerHTML = list.length
          ? list.map(ui.productCard).join('')
          : '<p class="u-muted">محصولی در این دسته‌بندی موجود نیست.</p>';
        observeReveals(prodGrid);
      });
    }

    var filterBar = $('[data-filters]');
    if (filterBar) {
      repo.getCategories().then(function (cats) {
        var chips = ['<button type="button" class="chip is-active" data-filter="">همه محصولات</button>'];
        cats.forEach(function (c) {
          chips.push('<button type="button" class="chip" data-filter="' + ui.esc(c.id) + '">' +
            ui.esc(c.name) + '</button>');
        });
        filterBar.innerHTML = chips.join('');
      });

      filterBar.addEventListener('click', function (e) {
        var chip = e.target.closest('[data-filter]');
        if (!chip) { return; }
        $$('.chip', filterBar).forEach(function (c) { c.classList.remove('is-active'); });
        chip.classList.add('is-active');
        activeCategory = chip.getAttribute('data-filter') || null;
        loadProducts();
      });
    }

    loadProducts();

    /* Category cards jump to the featured grid with that filter applied */
    document.addEventListener('click', function (e) {
      var card = e.target.closest('[data-category]');
      if (!card || !filterBar) { return; }
      var id = card.getAttribute('data-category');
      var chip = $('[data-filter="' + id + '"]', filterBar);
      if (chip) { chip.click(); }
    });

    var reviewGrid = cfg.flags.showTestimonials ? $('[data-reviews]') : null;
    if (reviewGrid) {
      repo.getReviews().then(function (list) {
        reviewGrid.insertAdjacentHTML('beforeend', list.map(ui.reviewCard).join(''));
        observeReveals(reviewGrid);
      });
    }

    var journalGrid = $('[data-journal]');
    if (journalGrid) {
      repo.getJournal().then(function (list) {
        journalGrid.innerHTML = list.map(ui.journalCard).join('');
        observeReveals(journalGrid);
      });
    }

    initCountdown();
  }

  /* Campaign countdown — always ends at the next midnight so the banner never
     shows an expired timer during a demo. */
  function initCountdown() {
    var root = $('[data-countdown]');
    if (!root) { return; }

    var end = new Date();
    end.setHours(24, 0, 0, 0);

    var slots = {
      h: $('[data-cd="h"]', root),
      m: $('[data-cd="m"]', root),
      s: $('[data-cd="s"]', root),
    };

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    function tick() {
      var diff = Math.max(0, end - new Date());
      var totalSeconds = Math.floor(diff / 1000);
      if (slots.h) { slots.h.textContent = pad(Math.floor(totalSeconds / 3600)); }
      if (slots.m) { slots.m.textContent = pad(Math.floor((totalSeconds % 3600) / 60)); }
      if (slots.s) { slots.s.textContent = pad(totalSeconds % 60); }
    }
    tick();
    setInterval(tick, 1000);
  }

  /* ====================================================================
     Product detail page
     ==================================================================== */
  function initProduct() {
    var root = $('[data-product-page]');
    if (!root) { return; }

    var slug = new URLSearchParams(window.location.search).get('p') || 'whey-original';

    repo.getProduct(slug).then(function (product) {
      if (!product) {
        root.innerHTML = '<div class="empty-state"><p style="font-weight:800">محصول پیدا نشد</p>' +
          '<p class="u-sm">ممکن است این محصول حذف شده باشد. <a href="index.html" style="color:var(--amber)">بازگشت به فروشگاه</a></p></div>';
        return;
      }
      cache([product]);
      renderProduct(product);
      observeReveals(root);

      /* "Pairs well with" is a complement shelf, not a similar-items shelf:
         someone buying pre-workout wants protein next, not a second
         pre-workout. So sibling products are excluded up front, and the list
         is only topped up from anywhere if there still aren't enough. */
      repo.getProducts({ notCategory: product.category, exclude: product.slug, limit: 4 })
        .then(function (related) {
          if (related.length >= 4) { return related; }
          return repo.getProducts({ exclude: product.slug, limit: 8 }).then(function (fill) {
            var seen = {};
            related.forEach(function (p) { seen[p.slug] = true; });
            fill.forEach(function (p) {
              if (related.length < 4 && !seen[p.slug]) { seen[p.slug] = true; related.push(p); }
            });
            return related;
          });
        })
        .then(function (related) {
          cache(related);
          var grid = $('[data-related]');
          if (grid) {
            grid.innerHTML = related.map(ui.productCard).join('');
            observeReveals(grid);
          }
        });
    });
  }

  function renderProduct(product) {
    /* --- state local to this page --- */
    var sizes = product.sizes || [{
      id: 'default', label: product.variantLabel || '—',
      price: product.price, compareAt: product.compareAt, servings: null,
    }];
    var flavors = product.flavors || [];
    var selected = {
      size: sizes[0],
      flavor: flavors[0] || null,
      qty: 1,
    };

    document.title = product.nameFa + ' | ' + cfg.brand.name;
    var metaDesc = $('meta[name="description"]');
    if (metaDesc) { metaDesc.setAttribute('content', product.short); }

    /* --- static text --- */
    var set = function (sel, value) {
      var el = $(sel);
      if (el) { el.innerHTML = value; }
    };

    set('[data-p-eyebrow]', ui.esc(product.brand) + ' · <span class="lat">' + ui.esc(product.name) + '</span>');
    set('[data-p-title]', ui.esc(product.nameFa));
    set('[data-p-rating]', ui.ratingHtml(product) +
      '<span class="u-xs" style="color:' + (product.inStock ? 'var(--success)' : 'var(--text-dim)') + '">' +
      (product.inStock ? '● موجود در انبار' : '● موقتاً ناموجود') + '</span>');
    set('[data-p-desc]', ui.esc(product.description || product.short));
    set('[data-p-breadcrumb]', ui.esc(product.nameFa));

    var featureList = $('[data-p-features]');
    if (featureList) {
      featureList.innerHTML = (product.features || []).map(function (f) {
        return '<li class="row" style="gap:10px;align-items:flex-start;margin-bottom:10px">' +
          '<span style="color:var(--accent);flex-shrink:0;margin-top:3px">' + ui.icon('check') + '</span>' +
          '<span class="u-sm u-muted">' + ui.esc(f) + '</span></li>';
      }).join('');
    }

    /* --- gallery --- */
    var gallery = product.gallery && product.gallery.length ? product.gallery : [product.image];
    var mainImg = $('[data-p-image]');
    var thumbs = $('[data-p-thumbs]');
    if (thumbs) {
      thumbs.innerHTML = gallery.map(function (src, i) {
        return '<button type="button" class="gallery__thumb media media--1x1' + (i === 0 ? ' is-active' : '') + '" ' +
          'data-thumb="' + i + '" aria-label="تصویر ' + (i + 1) + '">' +
          '<img src="' + ui.esc(src) + '" alt="' + ui.esc(product.nameFa) + ' — نمای ' + (i + 1) + '" width="120" height="120"></button>';
      }).join('');
      thumbs.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-thumb]');
        if (!btn || !mainImg) { return; }
        $$('.gallery__thumb', thumbs).forEach(function (t) { t.classList.remove('is-active'); });
        btn.classList.add('is-active');
        mainImg.src = gallery[parseInt(btn.getAttribute('data-thumb'), 10)];
      });
    }
    if (mainImg) {
      mainImg.src = gallery[0];
      mainImg.alt = product.nameFa + ' — ' + product.name;
    }

    var flagWrap = $('[data-p-flags]');
    if (flagWrap) {
      var html = '';
      if ((product.badges || []).indexOf('bestseller') !== -1) { html += '<span class="badge badge--light">پرفروش</span>'; }
      if ((product.badges || []).indexOf('new') !== -1) { html += '<span class="badge badge--light">جدید</span>'; }
      flagWrap.innerHTML = '<div class="row" style="gap:6px">' + html + '</div>' +
        (product.onSale ? '<span class="badge badge--accent num">' + product.discountPercent + '%-</span>' : '');
    }

    /* --- options --- */
    var flavorWrap = $('[data-p-flavors]');
    if (flavorWrap) {
      if (!flavors.length) {
        flavorWrap.closest('.pdp__field').hidden = true;
      } else {
        flavorWrap.innerHTML = flavors.map(function (f, i) {
          return '<button type="button" class="chip' + (i === 0 ? ' is-active' : '') + '" data-flavor="' +
            ui.esc(f.id) + '">' + ui.esc(f.name) + '</button>';
        }).join('');
        flavorWrap.addEventListener('click', function (e) {
          var btn = e.target.closest('[data-flavor]');
          if (!btn) { return; }
          $$('.chip', flavorWrap).forEach(function (c) { c.classList.remove('is-active'); });
          btn.classList.add('is-active');
          selected.flavor = flavors.filter(function (f) { return f.id === btn.getAttribute('data-flavor'); })[0];
        });
      }
    }

    var sizeWrap = $('[data-p-sizes]');
    if (sizeWrap) {
      if (sizes.length < 2) {
        sizeWrap.closest('.pdp__field').hidden = true;
      } else {
        sizeWrap.innerHTML = sizes.map(function (s, i) {
          var perServing = s.servings ? Math.round(s.price / s.servings) : null;
          return '<button type="button" class="opt' + (i === 0 ? ' is-active' : '') + '" data-size="' + ui.esc(s.id) + '">' +
            '<span class="opt__label">' + ui.esc(s.label) + '</span>' +
            (perServing ? '<span class="opt__meta"><span class="num">' + ui.money(perServing) + '</span> در هر وعده</span>' : '') +
            '<span class="opt__price num">' + ui.money(s.price) + '</span>' +
          '</button>';
        }).join('');
        sizeWrap.addEventListener('click', function (e) {
          var btn = e.target.closest('[data-size]');
          if (!btn) { return; }
          $$('.opt', sizeWrap).forEach(function (o) { o.classList.remove('is-active'); });
          btn.classList.add('is-active');
          selected.size = sizes.filter(function (s) { return s.id === btn.getAttribute('data-size'); })[0];
          paintPrice();
        });
      }
    }

    /* --- price, quantity, add to cart --- */
    function paintPrice() {
      var s = selected.size;
      var onSale = cfg.flags.saleMode && s.compareAt && s.compareAt > s.price;
      set('[data-p-price]',
        '<span class="price__now num">' + ui.money(s.price) +
        ' <span class="price__unit">' + ui.esc(cfg.currency.label) + '</span></span>' +
        (onSale ? '<span class="price__was num">' + ui.money(s.compareAt) + '</span>' : '') +
        '<span class="price__unit">شامل مالیات · ارسال رایگان بالای <span class="num">' +
          ui.money(cfg.freeShippingThreshold) + '</span></span>');
    }
    paintPrice();

    var qtyVal = $('[data-qty-val]');
    function paintQty() { if (qtyVal) { qtyVal.textContent = selected.qty; } }
    paintQty();

    var qtyBox = $('[data-p-qty]');
    if (qtyBox) {
      qtyBox.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-step]');
        if (!btn) { return; }
        selected.qty = Math.max(1, Math.min(20, selected.qty + parseInt(btn.getAttribute('data-step'), 10)));
        paintQty();
      });
    }

    var buyBtn = $('[data-p-add]');
    if (buyBtn) {
      if (!product.inStock) {
        buyBtn.disabled = true;
        buyBtn.textContent = 'موقتاً ناموجود';
      } else {
        buyBtn.addEventListener('click', function () {
          var parts = [];
          if (selected.flavor) { parts.push(selected.flavor.name); }
          if (selected.size && selected.size.label !== '—') { parts.push(selected.size.label); }
          addToCart(product, {
            price: selected.size.price,
            variantLabel: parts.join(' · '),
            flavorId: selected.flavor ? selected.flavor.id : null,
            sizeId: selected.size.id,
            qty: selected.qty,
          });
          setLayer('cart');
        });
      }
    }

    /* --- accordion content --- */
    var nutri = $('[data-p-nutrition]');
    if (nutri) {
      if (product.nutrition) {
        nutri.innerHTML = '<table class="spec"><caption class="sr-only">جدول ارزش غذایی</caption><tbody>' +
          '<tr><th>' + ui.esc(product.nutrition.servingSize) + '</th><td>مقدار</td></tr>' +
          product.nutrition.rows.map(function (r) {
            return '<tr><th>' + ui.esc(r.label) + '</th><td class="num">' + ui.esc(r.value) + '</td></tr>';
          }).join('') + '</tbody></table>';
      } else {
        nutri.innerHTML = '<p class="u-sm u-muted">جدول ارزش غذایی این محصول هنوز ثبت نشده است.</p>';
      }
    }

    var ing = $('[data-p-ingredients]');
    if (ing) { ing.textContent = product.ingredients || 'فهرست کامل مواد تشکیل‌دهنده، روی بسته‌بندی درج شده است.'; }

    var usage = $('[data-p-usage]');
    if (usage) { usage.textContent = product.usage || 'طبق دستور روی بسته‌بندی مصرف شود. در صورت تردید با مربی یا کارشناس تغذیه مشورت کنید.'; }
  }

  /* ====================================================================
     Boot
     ==================================================================== */
  return {
    init: function () {
      initChrome();
      initHome();
      initProduct();
      observeReveals(document);
      if (SUPPEX.hero3d) { SUPPEX.hero3d.init(); }
    },
  };
})();

document.addEventListener('DOMContentLoaded', function () { SUPPEX.app.init(); });
