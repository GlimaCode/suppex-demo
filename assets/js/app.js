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
      if (name === 'cart') { resetPaymentStep(); refreshCartPrices(); }
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
  /* Prices the cart is showing that the shop no longer charges. Rendered above
     the line items, and deliberately not cleared on the next render — the
     shopper has to see it at least until they close the drawer. */
  var priceChanges = [];

  /* Reconcile the cart against the live catalogue whenever the drawer opens.

     The cart keeps whatever price it saw when the item was added, and with a
     dirham-linked catalogue that goes stale within days. The order message the
     customer pastes into chat is built from those stored numbers, so without
     this they would send one figure while the server recorded another — and
     card-to-card has no authorisation step in which to correct the difference.

     Fire-and-forget: a failed refresh must not block checkout. The server
     recomputes every price when the order is placed regardless, so the worst
     case is the message quoting a stale figure, which is where things stood
     before this existed. */
  function refreshCartPrices() {
    var snapshot = store.snapshot();
    if (!snapshot.items.length) { priceChanges = []; return; }

    var slugs = [];
    snapshot.items.forEach(function (i) {
      if (slugs.indexOf(i.slug) === -1) { slugs.push(i.slug); }
    });

    repo.getPrices(slugs).then(function (list) {
      if (!list) { return; }
      var changed = store.syncPrices(list);
      if (changed.length) {
        priceChanges = changed;
        /* syncPrices emits, so the cart has already re-rendered by now with
           the new prices but without the notice — render once more to show it. */
        renderCart(store.snapshot());
      }
    });
  }

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
      body.innerHTML = ui.priceChangeNotice(priceChanges) +
                       snapshot.items.map(ui.lineItem).join('');
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

  /* Step 2: the delivery details, followed by the channel buttons. Picking a
     channel is what submits the form, so ordering stays two taps. */
  function showDetailsStep() {
    var body = $('[data-cart-items]');
    var foot = $('[data-drawer] .drawer__foot');
    if (!body) { return; }

    body.innerHTML = ui.customerForm(store.getCustomer()) +
      '<div class="channels" data-channels>' + ui.channelButtons(store.channels()) + '</div>' +
      ui.orderHelpNote();
    if (foot) { foot.hidden = true; }
    setDrawerBack(true);
  }

  /* Step 3: amount and card details. Told which app was used so it never
     names the wrong one. */
  /* Ticks the price-hold countdown in the payment panel.

     Driven off the SERVER's clock, not the phone's: a device an hour fast
     would otherwise show a fresh quote as already dead, and one running slow
     would promise time that no longer exists. Only one timer is ever alive —
     re-rendering the panel clears the previous one, or a customer who reopened
     the drawer twice would get two timers writing to the same element. */
  var holdTimer = null;

  function startHoldCountdown(root) {
    if (holdTimer) { clearInterval(holdTimer); holdTimer = null; }

    var box = root && root.querySelector('[data-expires]');
    if (!box) { return; }

    var expiresAt = Number(box.getAttribute('data-expires'));
    var serverNow = Number(box.getAttribute('data-now')) || Date.now();
    var skew      = Date.now() - serverNow;
    var out       = box.querySelector('[data-hold-countdown]');
    if (!expiresAt || !out) { return; }

    function tick() {
      var left = Math.round((expiresAt - (Date.now() - skew)) / 1000);
      if (left <= 0) {
        out.textContent = 'پایان یافته';
        box.classList.add('pay__hold--over');
        clearInterval(holdTimer);
        holdTimer = null;
        return;
      }
      var m = Math.floor(left / 60);
      var s = left % 60;
      out.textContent = m + ':' + (s < 10 ? '0' : '') + s;
      box.classList.toggle('pay__hold--soon', left <= 300);
    }

    tick();
    holdTimer = setInterval(tick, 1000);
  }

  function showPaymentStep(snapshot, channel, route, server) {
    var body = $('[data-cart-items]');
    var foot = $('[data-drawer] .drawer__foot');
    if (!body) { return; }
    body.innerHTML = ui.paymentPanel(snapshot, channel, route, server);
    if (foot) { foot.hidden = true; }
    setDrawerBack(true);
    startHoldCountdown(body);
  }

  /* A back arrow in the drawer header, shown only past the first step. */
  function setDrawerBack(show) {
    var head = $('[data-drawer] .drawer__head');
    if (!head) { return; }
    var btn = $('[data-drawer-back]', head);
    if (show && !btn) {
      btn = document.createElement('button');
      btn.className = 'icon-btn';
      btn.type = 'button';
      btn.setAttribute('data-drawer-back', '');
      btn.setAttribute('aria-label', 'بازگشت');
      btn.innerHTML = ui.icon('arrow');
      head.insertBefore(btn, head.firstChild);
    } else if (!show && btn) {
      btn.remove();
    }
  }

  function resetPaymentStep() {
    var foot = $('[data-drawer] .drawer__foot');
    if (foot && foot.hidden) {
      foot.hidden = false;
      renderCart(store.snapshot());
    }
    setDrawerBack(false);
  }

  /* renderCart used to disable a single [data-checkout] button; with a channel
     list it has to grey out the whole group instead. */
  function setChannelsEnabled(enabled) {
    $$('[data-channels] [data-channel]').forEach(function (b) { b.disabled = !enabled; });
  }

  /* Shoppers type Persian and Arabic-Indic digits interchangeably; a phone
     number in ۰۹۱۲ form is the same number and must not be rejected. */
  var DIGIT_MAP = { '۰':'0','۱':'1','۲':'2','۳':'3','۴':'4',
                    '۵':'5','۶':'6','۷':'7','۸':'8','۹':'9',
                    '٠':'0','١':'1','٢':'2','٣':'3','٤':'4',
                    '٥':'5','٦':'6','٧':'7','٨':'8','٩':'9' };

  function toLatinDigits(str) {
    return String(str).replace(/[۰-۹٠-٩]/g, function (d) { return DIGIT_MAP[d]; });
  }

  var VALIDATORS = {
    mobile: function (v) {
      var d = toLatinDigits(v).replace(/[\s-]/g, '');
      return /^09\d{9}$/.test(d) ? null : 'شماره موبایل باید با ۰۹ شروع شود و ۱۱ رقم باشد';
    },
    postal: function (v) {
      var d = toLatinDigits(v).replace(/[\s-]/g, '');
      return /^\d{10}$/.test(d) ? null : 'کد پستی باید دقیقاً ۱۰ رقم باشد';
    },
  };

  /* Reads the form, shows errors in place, and returns the values or null.
     Digits are normalised on the way out so the seller always receives a
     number they can dial or paste into a courier form. */
  function readCustomerForm() {
    var form = $('[data-customer-form]');
    if (!form) { return {}; }

    var values = {};
    var firstBad = null;

    (cfg.customerFields || []).forEach(function (f) {
      var input = $('[data-field="' + f.id + '"]', form);
      if (!input) { return; }
      var row = input.closest('.form__row');
      var err = $('[data-error]', row);
      var raw = String(input.value || '').trim();
      var message = null;

      if (f.required && !raw) {
        message = 'این مورد لازم است';
      } else if (raw && f.validate && VALIDATORS[f.validate]) {
        message = VALIDATORS[f.validate](raw);
      }

      if (message) {
        row.classList.add('is-invalid');
        if (err) { err.textContent = message; }
        if (!firstBad) { firstBad = input; }
      } else {
        row.classList.remove('is-invalid');
        if (err) { err.textContent = ''; }
        values[f.id] = f.validate ? toLatinDigits(raw).replace(/[\s-]/g, '') : raw;
      }
    });

    if (firstBad) {
      firstBad.focus();
      return null;
    }
    return values;
  }

  function sendOrder(channelId) {
    var snapshot = store.snapshot();
    if (snapshot.count === 0) { return; }

    var channel = store.channels().filter(function (c) { return c.id === channelId; })[0];
    if (!channel) { return; }

    /* Validate before anything opens — a popup that appears and then turns out
       to be premature is far worse than a blocked one. */
    var customer = readCustomerForm();
    if (customer === null) {
      toast('لطفاً موارد مشخص‌شده را تکمیل کنید');
      return;
    }
    store.setCustomer(customer);

    /* The store decides whether the order fits in the URL. When it does not —
       either the app has no prefill parameter, or the order is too long to
       survive one — it goes on the clipboard instead. */
    var route = store.resolveChannel(channel);

    if (route.needsPaste && navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(store.asOrderText()).then(function () {
        toast('متن سفارش کپی شد — در گفتگو پیست کنید');
      }, function () { /* the panel still shows the order, so this is not fatal */ });
    }

    /* Opened synchronously inside the click: a popup blocker only permits it
       while the gesture is still on the stack. This is why the order is NOT
       awaited first — a round trip here would put the window.open outside the
       gesture and the chat would silently fail to open on most phones. */
    window.open(route.url, '_blank', 'noopener');

    pingWebhook(snapshot, channel);

    /* Render the panel straight away from what the browser already knows, so
       the shopper is never left looking at a spinner while a request runs. */
    showPaymentStep(snapshot, channel, route);

    /* Then record it. The server assigns the tracking code and returns the
       current card details, and the panel is re-rendered with both.

       A failure here is deliberately quiet. The order is already open in the
       shopper's chat window; telling them it failed would make them abandon a
       message that is about to arrive perfectly well. The shop keeps taking
       orders exactly as it does today — it just loses this one from the
       dashboard, which is the lesser of the two problems. */
    repo.createOrder(store.asOrderPayload(channelId)).then(function (server) {
      if (server && server.ok) {
        rememberOrderCode(server.code);
        showPaymentStep(snapshot, channel, route, server);
      }
    }, function (err) {
      console.warn('[suppex] order was not recorded server-side:', err && err.message);
    });
  }

  /* The customer's own copy of their order code.

     Everything else about the order survives on the server, but the code is the
     only handle the customer has on it, and until now it existed solely in the
     drawer that was about to be closed. Kept separately from the cart so
     clearing the cart does not throw away the reference to an order that has
     already been placed.

     Wrapped because localStorage throws outright in some private modes, and
     failing to remember a code must never break the checkout that produced it. */
  var LAST_ORDER_KEY = 'suppex.lastOrder.v1';

  function rememberOrderCode(code) {
    if (!code) { return; }
    try {
      window.localStorage.setItem(LAST_ORDER_KEY, JSON.stringify({
        code: String(code),
        at: Date.now(),
      }));
    } catch (e) { /* private mode, quota, or storage disabled */ }
  }

  function lastOrderCode() {
    try {
      var raw = window.localStorage.getItem(LAST_ORDER_KEY);
      if (!raw) { return ''; }
      var v = JSON.parse(raw);
      /* Two weeks. After that it is more likely to confuse than to help - the
         order is long settled and the code on screen would be stale.

         ERASED, not merely ignored. This used to return '' and leave the value
         in localStorage for ever, while the privacy page told the customer the
         code removes itself after two weeks. A promise about someone's data is
         the last one to keep only approximately. */
      if (!v || !v.code || Date.now() - (v.at || 0) > 14 * 864e5) {
        try { window.localStorage.removeItem(LAST_ORDER_KEY); } catch (e2) {}
        return '';
      }
      return String(v.code);
    } catch (e) { return ''; }
  }

  /* Offer the way back, on any page, once there is something to go back to. */
  function initLastOrder() {
    var code = lastOrderCode();
    if (!code) { return; }
    $$('[data-last-order]').forEach(function (el) {
      el.hidden = false;
      var out = $('[data-last-order-code]', el);
      if (out) { out.textContent = code; }
    });
    /* One tap fills the field, rather than asking someone to copy a code by
       hand on a phone - which is where almost all of this traffic is. */
    $$('[data-fill-code]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var field = document.getElementById('code');
        if (field) { field.value = code; field.focus(); }
      });
    });
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
  /* --------------------------------------------------------------------
     The footer's contact details and social links.

     Everything here starts hidden and is revealed only by a value the owner
     has actually set. That is the opposite of how this footer worked for the
     whole life of the project: it hard-coded a landline, an email and a
     Valiasr Street address that were template filler, and four social icons
     pointing at href="#".

     Deferred rather than deleted because the owner said the real details come
     later - so the day he types them into the panel they appear on every page,
     with no developer involved. Until then nothing is printed, which is the
     honest amount.

     A failure here must never be visible: if the request fails the footer
     stays exactly as rendered, which is a footer with no contact block.
     -------------------------------------------------------------------- */
  function initContact() {
    var host = $('[data-socials]');
    var rows = $$('[data-contact], [data-ask]');
    if (!host && !rows.length) { return; }

    SUPPEX.repo.getShopConfig().then(function (remote) {
      var c = (remote && remote.contact) || {};

      /* Social icons: one per key, shown only where there is a URL. */
      $$('[data-social]').forEach(function (a) {
        var url = c[a.getAttribute('data-social')];
        if (!url) { return; }
        a.setAttribute('href', url);
        a.setAttribute('rel', 'noopener');
        a.setAttribute('target', '_blank');
        a.hidden = false;
      });

      var fill = function (sel, href, text) {
        var li = $(sel);
        if (!li || !href) { return; }
        var a = $('a', li);
        if (!a) { return; }
        a.setAttribute('href', href);
        if (text) { a.textContent = text; }
        li.hidden = false;
      };

      /* tel: wants the dialable form and the label wants the readable one. */
      if (c.phone) {
        fill('[data-contact="phone"]', 'tel:' + String(c.phone).replace(/[^\d+]/g, ''), c.phone);
      }
      if (c.email) {
        fill('[data-contact="email"]', 'mailto:' + c.email, c.email);
      }

      /* «پرسیدن قبل از خرید» goes to whichever chat the shop is actually on.
         Telegram first because that is where this business already lives. */
      var chat = c.telegram || c.whatsapp || c.instagram;
      if (chat) {
        var li = $('[data-ask]');
        var a = li && $('a', li);
        if (a) {
          a.setAttribute('href', chat);
          a.setAttribute('rel', 'noopener');
          a.setAttribute('target', '_blank');
          li.hidden = false;
        }
      }
    }).catch(function () { /* the footer simply stays as rendered */ });
  }

  /* The footer's product links are written as product.html so the static demo
     works when opened straight off disk. On a deployment running the real
     backend that sent every footer visitor to the demo page, which has no
     database behind it. config.js already knows which page this deployment
     uses. */
  function initFooterProductLinks() {
    var page = cfg.productPage || 'product.html';
    $$('[data-p]').forEach(function (a) {
      a.setAttribute('href', page + '?p=' + encodeURIComponent(a.getAttribute('data-p')));
    });
  }

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

      /* A link that closes a layer must still be a link. Every item in the
         mobile menu carries data-close, and preventDefault()ing all of them
         made all six dead - the menu closed and nothing navigated. Close the
         layer, then let a real href do its job. */
      var closer = e.target.closest('[data-close]');
      if (closer || e.target.closest('[data-scrim]')) {
        var isLink = closer && closer.tagName === 'A' && closer.getAttribute('href');
        if (!isLink) { e.preventDefault(); }
        closeLayers();
        return;
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

      /* Cart -> details */
      if (e.target.closest('[data-goto-details]')) {
        e.preventDefault();
        if (store.snapshot().count > 0) { showDetailsStep(); }
        return;
      }

      /* Any step -> back to the cart */
      if (e.target.closest('[data-drawer-back]')) {
        e.preventDefault();
        resetPaymentStep();
        return;
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
        checkout.textContent = 'ادامه و تکمیل مشخصات';
        checkout.setAttribute('data-goto-details', '');
      }
    }

    /* Newsletter — validates, then reports without sending anywhere */
    /* The newsletter handler used to live here. It answered «ایمیل شما ثبت
       شد» and then discarded the address - its own comment said the address
       was not stored anywhere. The form is gone from the footer for that
       reason, and this went with it. A restock list is worth building; it
       needs a table, not a toast. */

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

    /* The reviews grid was fed from a hardcoded array of invented customers
       and its markup is gone from index.html. Left as null so the block below
       short-circuits without reaching for data that no longer exists. */
    var reviewGrid = null;
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
    initAnnounce();
  }

  /* The announcement bar's three messages, taking turns on a narrow screen.

     Only one fits on a phone line, and the old rule simply hid two of them -
     which happened to be the free-shipping threshold and the order channel,
     the two that move money. Rotating shows all three.

     Runs only where the CSS actually hides them, so on a wide screen this does
     nothing and all three stay visible side by side. Nothing moves: the message
     is swapped, not animated, which keeps it readable and keeps it honest under
     prefers-reduced-motion without a second code path. */
  function initAnnounce() {
    var bar = $('.announce__inner');
    if (!bar) { return; }
    var items = $$('.announce__item', bar);
    if (items.length < 2) { return; }

    var narrow = window.matchMedia('(max-width: 759px)');
    var at = 0;
    var timer = null;

    function show(i) {
      items.forEach(function (el, n) { el.classList.toggle('is-on', n === i); });
    }

    function start() {
      if (timer) { return; }
      bar.setAttribute('data-rotating', '');
      show(at);
      timer = setInterval(function () {
        at = (at + 1) % items.length;
        show(at);
      }, 4200);
    }

    function stop() {
      if (timer) { clearInterval(timer); timer = null; }
      bar.removeAttribute('data-rotating');
      items.forEach(function (el) { el.classList.remove('is-on'); });
    }

    function sync() { if (narrow.matches) { start(); } else { stop(); } }

    sync();
    if (narrow.addEventListener) { narrow.addEventListener('change', sync); }
    else if (narrow.addListener) { narrow.addListener(sync); }
  }

  /* Campaign countdown.

     It used to end at the next midnight - every night, forever - so the clock
     reset itself and the deadline was never real. A deadline a shop does not
     mean is worse than no deadline: the second visit teaches the customer that
     the first one was theatre. It now reads cfg.campaignEndsAt, and when there
     is no campaign, or the campaign is over, the whole block is removed rather
     than shown at zero. */
  function initCountdown() {
    var root = $('[data-countdown]');
    if (!root) { return; }

    var end = cfg.campaignEndsAt ? new Date(cfg.campaignEndsAt) : null;
    if (!end || isNaN(end.getTime()) || end <= new Date()) {
      var block = root.closest('[data-countdown-block]') || root;
      block.remove();
      return;
    }

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
    /* An empty array is not a size list, and `[] || fallback` keeps the empty
       array. The API returns sizes: [] for a single-price product, so sizes[0]
       was undefined and paintPrice() threw on it - taking the quantity
       stepper, the nutrition table and the add-to-cart button with it, on all
       fourteen products that have one price. */
    var sizes = (product.sizes && product.sizes.length) ? product.sizes : [{
      id: 'default', label: product.variantLabel || '—',
      price: product.price, compareAt: product.compareAt, servings: null,
      /* The product's own count, because for a single-price product that IS
         the size's count. Left off, the quantity stepper would happily offer
         five of something there are two of and the order endpoint would refuse
         it after the address had been typed. */
      stock: typeof product.stock === 'number' ? product.stock : null,
    }];
    var flavors = product.flavors || [];

    /* The first size that can actually be bought, not simply the first. A
       product whose 900g tub is sold out and whose 2270g tub is not is
       available - opening it on the sold-out one shows a greyed button on a
       product the shop is happily selling, which reads as the site being
       broken. Falls back to sizes[0] when they are all gone, so the page still
       has something selected to price. */
    var firstSellable = sizes.filter(function (s) { return s.stock !== 0; })[0] || sizes[0];

    var selected = {
      size: firstSellable,
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
    /* The stock line used to be written here, once, from product.inStock.
       paintAvailability() owns it now: it depends on which size is selected,
       and this runs before a selection exists. */
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
        (product.onSale ? '<span class="badge badge--accent num">\u2212' + product.discountPercent + '%</span>' : '');
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
        sizeWrap.innerHTML = sizes.map(function (s) {
          var perServing = s.servings ? Math.round(s.price / s.servings) : null;
          var out = s.stock === 0;
          /* The per-serving figure is what this line is for on a normal day.
             On a size with two left, how many are left is the more useful
             number, so it takes the slot rather than being squeezed in beside
             it. */
          var meta = out
            ? '<span class="opt__meta">تمام شد</span>'
            : (typeof s.stock === 'number'
                ? '<span class="opt__meta"><span class="num">' + s.stock + '</span> عدد مانده</span>'
                : (perServing
                    ? '<span class="opt__meta"><span class="num">' + ui.money(perServing) + '</span> در هر وعده</span>'
                    : ''));
          return '<button type="button" class="opt' +
              (s === firstSellable ? ' is-active' : '') + (out ? ' is-out' : '') +
              '" data-size="' + ui.esc(s.id) + '"' + (out ? ' disabled' : '') + '>' +
            '<span class="opt__label">' + ui.esc(s.label) + '</span>' +
            meta +
            '<span class="opt__price num">' + ui.money(s.price) + '</span>' +
          '</button>';
        }).join('');
        sizeWrap.addEventListener('click', function (e) {
          var btn = e.target.closest('[data-size]');
          if (!btn || btn.disabled) { return; }
          $$('.opt', sizeWrap).forEach(function (o) { o.classList.remove('is-active'); });
          btn.classList.add('is-active');
          selected.size = sizes.filter(function (s) { return s.id === btn.getAttribute('data-size'); })[0];
          paintPrice();
          paintAvailability();
        });
      }
    }

    /* --- price, quantity, add to cart --- */
    function paintPrice() {
      var s = selected.size;
      var onSale = cfg.flags.saleMode && s.compareAt && s.compareAt > s.price;
      set('[data-p-price]',
        /* Isolate the digits, not the price. See priceHtml() in ui.js. */
        '<span class="price__now"><span class="num">' + ui.money(s.price) +
        '</span>\u00a0<span class="price__unit">' + ui.esc(cfg.currency.label) +
        '</span></span>' +
        (onSale ? '<span class="price__was num">' + ui.money(s.compareAt) + '</span>' : '') +
        /* «شامل مالیات» was here. This shop is one person reselling; nothing in
           the pricing code computes or remits a tax, and telling a customer a
           price includes one is a claim about an obligation nobody has taken
           on. The free-shipping half is real and the server enforces it. */
        '<span class="price__unit">ارسال رایگان بالای <span class="num">' +
          ui.money(cfg.freeShippingThreshold) + '</span></span>');
    }
    paintPrice();

    var qtyVal = $('[data-qty-val]');
    function paintQty() { if (qtyVal) { qtyVal.textContent = selected.qty; } }
    paintQty();

    /* The ceiling on the stepper. Twenty is the shop's own limit; a counted
       size lowers it to what exists, so the page cannot offer to sell three of
       something there are two of and then refuse at checkout. */
    function qtyCeiling() {
      var left = selected.size ? selected.size.stock : null;
      return typeof left === 'number' ? Math.max(1, Math.min(20, left)) : 20;
    }

    var qtyBox = $('[data-p-qty]');
    if (qtyBox) {
      qtyBox.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-step]');
        if (!btn) { return; }
        selected.qty = Math.max(1, Math.min(qtyCeiling(),
          selected.qty + parseInt(btn.getAttribute('data-step'), 10)));
        paintQty();
      });
    }

    var buyBtn   = $('[data-p-add]');
    var buyLabel = buyBtn ? buyBtn.textContent : '';

    /* Availability is a property of the SELECTED SIZE, not of the product, so
       it has to be repainted every time the selection moves. The button used
       to be disabled once at render time from product.inStock alone, which is
       true of the product and says nothing about the tub in front of you. */
    function paintAvailability() {
      var left = selected.size ? selected.size.stock : null;
      var sold = !product.inStock || left === 0;

      if (selected.qty > qtyCeiling()) { selected.qty = qtyCeiling(); paintQty(); }

      if (buyBtn) {
        buyBtn.disabled    = sold;
        buyBtn.textContent = sold ? 'موقتاً ناموجود' : buyLabel;
      }

      set('[data-p-rating]', ui.ratingHtml(product) +
        '<span class="u-xs" style="color:' +
          (sold ? 'var(--text-dim)' : (typeof left === 'number' ? 'var(--amber)' : 'var(--success)')) +
        '">' +
        (sold ? '● موقتاً ناموجود'
              : (typeof left === 'number'
                  ? '● تنها <span class="num">' + left + '</span> عدد مانده'
                  : '● موجود در انبار')) +
        '</span>');
    }
    paintAvailability();

    if (buyBtn) {
      buyBtn.addEventListener('click', function () {
        /* Guarded rather than left unbound, because the handler now outlives
           any single availability state - the shopper can switch to a sold-out
           size and back. */
        if (buyBtn.disabled) { return; }
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
      initContact();
      initFooterProductLinks();
      initHome();
      initProduct();
      /* Every page, not only the homepage: the point of remembering the code is
         that the way back to an order is there wherever the customer lands. */
      initLastOrder();
      observeReveals(document);
      if (SUPPEX.hero3d) { SUPPEX.hero3d.init(); }
    },
  };
})();

document.addEventListener('DOMContentLoaded', function () { SUPPEX.app.init(); });
