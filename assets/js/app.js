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
  /* kind 'error' for what went wrong: every toast used to carry the green
     tick, «کپی نشد» and «لطفاً موارد مشخص‌شده را تکمیل کنید» included, and it
     stays up longer because it asks for something to be done. */
  function toast(message, kind) {
    var stack = $('[data-toasts]');
    if (!stack) { return; }
    var bad = kind === 'error';
    var el = document.createElement('div');
    el.className = 'toast' + (bad ? ' toast--error' : '');
    el.setAttribute('role', bad ? 'alert' : 'status');
    el.innerHTML = ui.icon(bad ? 'info' : 'check') + '<span>' + ui.esc(message) + '</span>';
    stack.appendChild(el);
    setTimeout(function () {
      el.classList.add('is-out');
      setTimeout(function () { el.remove(); }, 300);
    }, bad ? 4200 : 2600);
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
      if (name === 'cart') { openCartBody(); refreshCartPrices(); }
      drawer.classList.toggle('is-open', name === 'cart');
      setDrawerReach(drawer, name === 'cart');
    }
    if (search) { search.classList.toggle('is-open', name === 'search'); }

    if (name === 'search') {
      var input = $('[data-search-input]');
      if (input) { setTimeout(function () { input.focus(); }, 60); }
    }
  }

  function closeLayers() { setLayer(null); }

  /* The basket, in reach only while it is open. Closed, it is only moved off
     screen, so its buttons stayed in the Tab order and a keyboard walked
     through every control of a basket nobody could see. Opening takes the
     focus into it; closing hands it back to whatever opened it. */
  var drawerOpener = null;

  function setDrawerReach(drawer, open) {
    var was = drawer.getAttribute('data-reach') === 'open';
    drawer.inert = !open;
    drawer.setAttribute('data-reach', open ? 'open' : 'closed');
    if (open && !was) {
      drawerOpener = document.activeElement;
      if (!drawer.hasAttribute('tabindex')) { drawer.setAttribute('tabindex', '-1'); }
      try { drawer.focus({ preventScroll: true }); } catch (e) { /* an older engine */ }
    } else if (!open && was) {
      var back = drawerOpener;
      drawerOpener = null;
      if (back && back.focus && document.documentElement.contains(back)) {
        try { back.focus({ preventScroll: true }); } catch (e) { /* as above */ }
      }
    }
  }

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

    repo.getPrices(slugs).then(function (res) {
      if (!res) { return; }
      /* Kept before the reconcile, so the token always describes the prices the
         shopper is about to be shown. */
      store.setQuote(res.quote);
      var changed = store.syncPrices(res.prices);
      if (changed.length) {
        priceChanges = changed;
        /* syncPrices emits, so the cart has already re-rendered by now with
           the new prices but without the notice — render once more to show it. */
        renderCart(store.snapshot());
        /* With the form up the drawer is the form's (renderCart leaves it
           alone), so the notice goes above it: a price that moved while the
           address was being typed is still news before the order goes. */
        var slot = detailsUp ? $('[data-drawer] [data-details-notice]') : null;
        if (slot) { slot.innerHTML = ui.priceChangeNotice(changed); }
      }
    });
  }

  /* Is the payment panel currently occupying the drawer body?

     It has to be a flag rather than a DOM probe: renderCart runs from a store
     event, and by then the panel's own markup is exactly what is about to be
     overwritten. Read by cartBodyKeepsPanel(), which is where the rule lives
     so it can be tested without a browser. */
  var paymentPanelUp = false;

  /* The other two steps that hold the drawer: the details form, and the
     answer that the order was not recorded. Every store change repainted the
     basket over the form - the price refresh that runs as the drawer opens
     among them, which arrives about when the shopper taps «ادامه» - and left
     them on a list of products with the drawer's summary hidden and no way
     to send. */
  var detailsUp = false;
  var failedUp = false;

  /* The order a click sent that the server has not answered yet. One at a
     time: a second tap, a reopened drawer or a store change while it is on
     its way must not send it again, nor take the drawer from the panel that
     is waiting for the answer. */
  var inFlight = null;

  /* The last order sent from this page: what it was, where it went, the text
     the shopper was given and the request that records it. Kept to send the
     request again, to copy the text again, and to put the same goods back in
     the basket once the price has run out. */
  var lastSend = null;

  /* The last recorded order's payment panel, for as long as that order can
     be paid at its price.

     Opening the cart always started over - on the empty basket a recorded
     order leaves - so closing the drawer by mistake threw away the card and
     the one place the receipt could be sent from, short of the tracking
     page. An empty basket now reopens on this instead. `skew` is the
     server's clock against this one, taken when the order came back, so the
     countdown is right however much later the drawer is reopened. */
  var pendingPayment = null;

  function paymentStillOpen(p) {
    return !!(p && p.server && p.server.expiresAt
      && Number(p.server.expiresAt) > Date.now() - (p.skew || 0));
  }

  function openCartBody() {
    /* An order on its way keeps its panel: reopening the drawer must not show
       the basket the order is about to take its lines out of. */
    if (inFlight) {
      showPaymentStep(inFlight.snapshot, inFlight.channel, inFlight.route, { pending: true });
      return;
    }
    var p = pendingPayment;
    if (store.snapshot().count === 0 && paymentStillOpen(p)) {
      var server = {};
      Object.keys(p.server).forEach(function (k) { server[k] = p.server[k]; });
      server.serverNow = Date.now() - (p.skew || 0);
      showPaymentStep(p.snapshot, p.channel, p.route, server);
      return;
    }
    resetPaymentStep();
  }

  /* Whether renderCart must leave the drawer body alone.

     True only while the panel is up AND the basket is empty - the state a
     confirmed order leaves behind. As soon as the shopper adds something the
     cart takes the container back, so the panel cannot strand them in it. */
  function cartBodyKeepsPanel(panelUp, itemCount) {
    return panelUp === true && itemCount === 0;
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

    if (inFlight) { return; }
    if ((detailsUp || failedUp) && snapshot.count > 0) {
      if (detailsUp) { paintCheckoutSum(snapshot); }
      return;
    }
    if (detailsUp || failedUp) {
      /* The basket emptied under the form - in another tab, say. Back to it. */
      detailsUp = false;
      failedUp = false;
      var footBack = $('[data-drawer] .drawer__foot');
      if (footBack) { footBack.hidden = false; }
      setDrawerBack(false);
    }

    if (cartBodyKeepsPanel(paymentPanelUp, snapshot.items.length)) { return; }
    if (paymentPanelUp) {
      /* The basket has something in it again and takes the drawer back:
         with the summary the panel hid, and without the pinned receipt. */
      setPayDock('');
      var footAgain = $('[data-drawer] .drawer__foot');
      if (footAgain) { footAgain.hidden = false; }
      setDrawerBack(false);
    }
    paymentPanelUp = false;

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
        : (snapshot.shipping === 0 ? 'رایگان' : ui.money(snapshot.shipping) + ' ' + cfg.currency.label);
    }

    var total = $('[data-cart-total]');
    if (total) { total.textContent = ui.money(snapshot.total); }

    var hint = $('[data-cart-hint]');
    if (hint) {
      if (snapshot.count === 0) {
        hint.textContent = '';
      } else if (snapshot.shipping === 0) {
        hint.innerHTML = ui.icon('truck') + ' ارسال این سفارش رایگان است';
      } else if (snapshot.freeShippingOffered === false) {
        hint.textContent = '';
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

    detailsUp = true;
    paymentPanelUp = false;
    failedUp = false;
    body.innerHTML = '<div data-details-notice></div>' +
      ui.customerForm(store.getCustomer()) +
      ui.checkoutSummary(store.snapshot()) +
      '<div class="channels" data-channels>' + ui.channelButtons(store.channels()) + '</div>' +
      ui.orderHelpNote();
    if (foot) { foot.hidden = true; }
    setDrawerBack(true);
    keepTypedDetails(body);
    body.scrollTop = 0;
  }

  /* The amount above the send buttons, kept current while the form is up. */
  function paintCheckoutSum(snapshot) {
    var sum = $('[data-drawer] [data-checkout-sum]');
    if (sum) { sum.outerHTML = ui.checkoutSummary(snapshot); }
  }

  /* What the shopper types is kept as it is typed (quietly - see
     store.setCustomer()), so closing the drawer by mistake or a reload does
     not cost them the address. A field's error goes as soon as they start
     correcting it. */
  function keepTypedDetails(body) {
    var form = $('[data-customer-form]', body);
    if (!form) { return; }
    form.addEventListener('input', function (e) {
      var input = e.target;
      var id = input && input.getAttribute ? input.getAttribute('data-field') : null;
      if (!id) { return; }
      var patch = {};
      patch[id] = input.value;
      store.setCustomer(patch, true);
      var row = input.closest('.form__row');
      if (row && row.classList.contains('is-invalid')) {
        row.classList.remove('is-invalid');
        var err = $('[data-error]', row);
        if (err) { err.textContent = ''; }
      }
    });
  }

  /* The server's own words on the fields it would not take. */
  function markFieldErrors(fields) {
    var first = null;
    Object.keys(fields || {}).forEach(function (id) {
      var row = $('[data-drawer] [data-row="' + id + '"]');
      if (!row) { return; }
      row.classList.add('is-invalid');
      var err = $('[data-error]', row);
      if (err) { err.textContent = String(fields[id]); }
      if (!first) { first = $('[data-field]', row); }
    });
    if (first) { first.focus(); }
  }

  /* Step 3: amount and card details. Told which app was used so it never
     names the wrong one. */
  /* Ticks the price-hold countdowns: the payment panel's and the bar's.

     Driven off the SERVER's clock, not the phone's: a device an hour fast
     would otherwise show a fresh quote as already dead, and one running slow
     would promise time that no longer exists.

     One timer per clock, and never two on the same one. There used to be a
     single timer, which was right while only one clock could be on the
     page; the bar now appears the moment an order is recorded, behind the
     drawer's panel, and a shared timer froze whichever of the two started
     first. A clock that has left the page - the panel re-rendered, the bar
     removed - stops its own timer on the next tick. */
  var holdTimers = [];

  function startHoldCountdown(root) {
    var box = root && root.querySelector('[data-expires]');
    holdTimers = holdTimers.filter(function (t) {
      if (t.box === box || !document.documentElement.contains(t.box)) {
        clearInterval(t.id);
        return false;
      }
      return true;
    });
    if (!box) { return; }

    var expiresAt = Number(box.getAttribute('data-expires'));
    var serverNow = Number(box.getAttribute('data-now')) || Date.now();
    var skew      = Date.now() - serverNow;
    var out       = box.querySelector('[data-hold-countdown]');
    if (!expiresAt || !out) { return; }

    var timer = { box: box, id: 0 };
    function stop() {
      clearInterval(timer.id);
      holdTimers = holdTimers.filter(function (t) { return t !== timer; });
    }
    function tick() {
      if (!document.documentElement.contains(box)) { stop(); return; }
      var left = Math.round((expiresAt - (Date.now() - skew)) / 1000);
      if (left <= 0) {
        out.textContent = 'پایان یافته';
        box.classList.add('pay__hold--over');
        /* The card goes with the clock: a number to transfer to under a price
           that has run out is an invitation to pay it. The panel says what to
           do instead (ui.paymentPanel(), track.php). */
        var panel = box.closest ? box.closest('.pay') : null;
        if (panel) { panel.classList.add('is-expired'); }
        stop();
        return;
      }
      var m = Math.floor(left / 60);
      var s = left % 60;
      out.textContent = m + ':' + (s < 10 ? '0' : '') + s;
      box.classList.toggle('pay__hold--soon', left <= 300);
    }

    holdTimers.push(timer);
    tick();
    if (holdTimers.indexOf(timer) !== -1) { timer.id = setInterval(tick, 1000); }
  }

  function showPaymentStep(snapshot, channel, route, server) {
    var body = $('[data-cart-items]');
    var foot = $('[data-drawer] .drawer__foot');
    if (!body) { return; }
    body.innerHTML = ui.paymentPanel(snapshot, channel, route, server);
    paymentPanelUp = true;
    detailsUp = false;
    failedUp = false;
    if (foot) { foot.hidden = true; }
    setPayDock(server && server.code ? String(server.code) : '');
    /* No way back while the order is on its way: back is the basket, and the
       basket is what the answer takes the ordered lines out of. */
    setDrawerBack(!(server && server.pending));
    startHoldCountdown(body);
    initReceipts(body);
    body.scrollTop = 0;
  }

  /* The receipt, pinned under the payment panel rather than at its end.

     At the end it was below the fold on a phone - the code, the paste
     reminder, the clock, the amount and the card fill the screen - and a
     customer back from the bank app saw the card they had just paid and
     nowhere to send the receipt. Pinned, it is where the thumb already is,
     however far the panel is scrolled. The same order keeps the same dock,
     state and all: reopening the drawer does not lose «رسید دریافت شد». */
  function setPayDock(code) {
    var drawer = $('[data-drawer]');
    if (!drawer) { return; }
    var dock = $('[data-pay-dock]', drawer);
    if (dock && code && dock.getAttribute('data-code') === code) { return; }
    if (dock) { dock.remove(); }
    if (!code) { return; }
    dock = document.createElement('div');
    dock.className = 'pay-dock';
    dock.setAttribute('data-pay-dock', '');
    dock.setAttribute('data-code', code);
    dock.innerHTML = ui.receiptBlock(code, '', 'dock');
    drawer.appendChild(dock);
    initReceipts(dock);
  }

  /* A receipt is in, from this page: the bar asking for payment goes, and
     the drawer stops reopening on the card - a card number shown again after
     the receipt is an invitation to pay twice. */
  function receiptArrived(code) {
    /* The panel stops asking too: no clock and no card once the money is
       said to be sent - in the drawer for the order just placed, and on the
       tracking page for the order on it. */
    if (lastSend && lastSend.code === String(code)) {
      var drawerPanel = $('[data-drawer] .pay');
      if (drawerPanel) { drawerPanel.classList.add('is-paid'); }
    }
    $$('[data-found-order]').forEach(function (sec) {
      if (sec.getAttribute('data-code') === String(code)) {
        $$('.pay', sec).forEach(function (p) { p.classList.add('is-paid'); });
      }
    });
    var bar = $('[data-resume-bar]');
    if (bar && bar.getAttribute('data-code') === String(code)) { bar.remove(); }
    if (pendingPayment && String(pendingPayment.server.code) === String(code)) {
      pendingPayment = null;
    }
  }

  /* «سفارش‌های من» on the tracking page: drawn at once from what this
     browser remembers, then again with each order's live status. */
  function initMyOrders() {
    /* An order found on the tracking page joins the list: its code and its
       phone have just been proved together. */
    var found = $('[data-found-order]');
    if (found) {
      rememberMyOrder(found.getAttribute('data-code'), found.getAttribute('data-phone'),
                      Number(found.getAttribute('data-total')) || null);
    }
    /* The one order remembered before this list existed, so it is not lost. */
    var last = lastOrder();
    if (last && last.code && last.phone) { rememberMyOrder(last.code, last.phone, null); }

    var box = $('[data-my-orders]');
    var listEl = box ? $('[data-my-orders-list]', box) : null;
    if (!box || !listEl) { return; }
    var list = myOrders();
    if (!list.length) { return; }

    var live = null;
    function paint() {
      var byCode = {};
      (live || []).forEach(function (o) { byCode[o.code] = o; });
      listEl.innerHTML = myOrders().map(function (o) {
        return ui.myOrderRow(o, byCode[o.code] || null, live !== null);
      }).join('');
    }
    paint();
    box.hidden = false;
    /* The one-tap line for the last order says less than this list does. */
    var lastLine = $('[data-last-order]');
    if (lastLine) { lastLine.hidden = true; }

    repo.getMyOrders(list.map(function (o) { return { code: o.code, phone: o.phone }; }))
      .then(function (answer) {
        if (answer) {
          live = answer;
          paint();
        }
      });

    listEl.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-forget-order]');
      if (!btn) { return; }
      forgetMyOrder(btn.getAttribute('data-forget-order'));
      var row = btn.closest('.myorder');
      if (row) { row.parentNode.removeChild(row); }
      if (!myOrders().length) { box.hidden = true; }
    });
  }

  /* The receipt boxes (ui.receiptBlock). The payment panel draws one once
     the order is recorded; the tracking page asks for one, with a
     [data-receipt-slot], for an order still waiting on its payment.

     The photograph is shrunk in the browser first - at most 2000px, as a
     JPEG - because a phone photo is 3-5MB, a shared host's upload limit can
     be 2MB, and a phone connection pays for every byte of it. The server
     re-encodes it regardless; this only decides how much has to travel. */
  function initReceipts(root) {
    $$('[data-receipt-slot]', root || document).forEach(function (slot) {
      var wrap = document.createElement('div');
      wrap.innerHTML = ui.receiptBlock(slot.getAttribute('data-code') || '',
                                       slot.getAttribute('data-phone') || '');
      slot.parentNode.replaceChild(wrap.firstChild, slot);
    });
    $$('[data-receipt]', root || document).forEach(function (box) {
      if (box.getAttribute('data-receipt-ready')) { return; }
      box.setAttribute('data-receipt-ready', '1');
      var input = $('[data-receipt-file]', box);
      var label = $('[data-receipt-label]', box);
      var state = $('[data-receipt-state]', box);
      if (!input || !state) { return; }

      input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        /* Cleared at once, so choosing the same file again still fires. */
        input.value = '';
        if (!file) { return; }
        var code = box.getAttribute('data-code') || '';
        var phone = box.getAttribute('data-phone') || (store.getCustomer() || {}).phone || '';
        if (!code || !phone) {
          paintReceipt(state, 'error', 'شمارهٔ سفارش پیدا نشد؛ رسید را در همان گفتگو بفرستید.');
          return;
        }
        box.classList.add('is-busy');
        input.disabled = true;
        paintReceipt(state, 'busy', 'در حال آماده کردن تصویر…');
        shrinkReceipt(file).then(function (blob) {
          paintReceipt(state, 'busy', 'در حال ارسال رسید…', 0);
          return repo.uploadReceipt(code, phone, blob, function (part) {
            paintReceipt(state, 'busy', 'در حال ارسال رسید…', part);
          }).then(function (res) { return { res: res, blob: blob }; });
        }).then(function (sent) {
          box.classList.remove('is-busy');
          input.disabled = false;
          if (sent.res && sent.res.ok) {
            /* The same picture a second time is not a second receipt: the
               server keeps the first and says so, and nothing new goes to
               the group. */
            paintReceipt(state, 'done', sent.res.duplicate
              ? 'این همان رسیدی است که قبلاً فرستادید و به دست ما رسیده است. لازم نیست دوباره بفرستید.'
              : 'رسید در سفارش شما ثبت شد. پرداخت پس از بررسی فروشگاه تأیید می‌شود.', null, sent.blob);
            if (label) { label.textContent = 'ارسال رسید دیگر'; }
            box.classList.add('is-sent');
            /* Nothing is owed any more, so the pinned button stops looking
               like the next thing to do. */
            var pick = $('.receipt-up__pick', box);
            if (pick) {
              pick.classList.remove('btn--primary');
              pick.classList.add('btn--ghost');
            }
            receiptArrived(code);
          } else {
            paintReceipt(state, 'error', (sent.res && sent.res.error) || 'رسید فرستاده نشد. دوباره تلاش کنید.');
          }
        });
      });
    });
  }

  function paintReceipt(state, kind, text, part, blob) {
    var html = '<p class="receipt-up__msg receipt-up__msg--' + kind + '">' +
      (kind === 'done' ? ui.icon('check') : '') + '<span>' + ui.esc(text) + '</span></p>';
    if (kind === 'busy' && typeof part === 'number') {
      html += '<div class="receipt-up__bar"><span style="width:' +
        Math.max(4, Math.round(part * 100)) + '%"></span></div>';
    }
    state.innerHTML = html;
    /* What was sent, shown back small: the customer can see it was the
       right screenshot without anything leaving their own phone. */
    if (blob && window.URL && URL.createObjectURL) {
      var img = document.createElement('img');
      img.className = 'receipt-up__thumb';
      img.alt = 'رسیدی که فرستادید';
      img.src = URL.createObjectURL(blob);
      img.onload = function () { URL.revokeObjectURL(img.src); };
      state.appendChild(img);
    }
  }

  /* A receipt at most 2000px on its long edge, as a JPEG on white. Anything
     the browser cannot draw goes as it came, and the server decides. */
  function shrinkReceipt(file) {
    return new Promise(function (resolve) {
      if (!window.URL || !URL.createObjectURL) { resolve(file); return; }
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        var w = img.naturalWidth;
        var h = img.naturalHeight;
        var scale = Math.min(1, 2000 / Math.max(w, h, 1));
        var canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(w * scale));
        canvas.height = Math.max(1, Math.round(h * scale));
        var g = canvas.getContext('2d');
        g.fillStyle = '#fff';
        g.fillRect(0, 0, canvas.width, canvas.height);
        g.drawImage(img, 0, 0, canvas.width, canvas.height);
        URL.revokeObjectURL(url);
        if (!canvas.toBlob) { resolve(file); return; }
        canvas.toBlob(function (blob) {
          /* The redrawn copy only when it is smaller, or when the original
             is a format the server does not read. */
          resolve(blob && (blob.size < file.size || !/^image\/(jpeg|png|webp)$/.test(file.type))
            ? blob : file);
        }, 'image/jpeg', 0.85);
      };
      img.onerror = function () {
        URL.revokeObjectURL(url);
        resolve(file);
      };
      img.src = url;
    });
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
    paymentPanelUp = false;
    detailsUp = false;
    failedUp = false;
    setPayDock('');
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

  /* Every non-digit goes - spaces, dashes, and the invisible direction marks
     a phone's contacts paste around a number - once Persian and Arabic digits
     are Latin: what the server's digits_only() does. Stripping spaces and
     dashes alone refused a pasted number the server would have taken. */
  function digitsOnly(v) {
    return toLatinDigits(v).replace(/\D+/g, '');
  }

  /* The other ways of writing the same mobile, as the shop dials it:
     +98 912…, 0098 912… and 912… are all 0912…. */
  function normaliseMobile(v) {
    var d = digitsOnly(v);
    if (d.length === 12 && d.indexOf('989') === 0) { return '0' + d.slice(2); }
    if (d.length === 14 && d.indexOf('00989') === 0) { return '0' + d.slice(4); }
    if (d.length === 10 && d.charAt(0) === '9') { return '0' + d; }
    return d;
  }

  /* Counted the way the server counts it - mb_strlen() after
     clean_multiline(): characters, not UTF-16 units. */
  function textLength(v) {
    return String(v).replace(/\r\n?/g, '\n').trim()
      .replace(/[\uD800-\uDBFF][\uDC00-\uDFFF]/g, '_').length;
  }

  var VALIDATORS = {
    mobile: function (v) {
      return /^09\d{9}$/.test(normaliseMobile(v)) ? null : 'شماره موبایل باید با ۰۹ شروع شود و ۱۱ رقم باشد';
    },
    postal: function (v) {
      return /^\d{10}$/.test(digitsOnly(v)) ? null : 'کد پستی باید دقیقاً ۱۰ رقم باشد';
    },
    /* The server refuses an address under ten characters. Caught here it is
       a line under the field; caught there, it was an order the chat already
       had and the shop never recorded. */
    address: function (v) {
      return textLength(v) >= 10 ? null : 'آدرس را کامل‌تر بنویسید: شهر، خیابان، کوچه، پلاک و واحد';
    },
  };

  /* What a checked field is sent as. */
  var NORMALISE = { mobile: normaliseMobile, postal: digitsOnly };

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
        values[f.id] = NORMALISE[f.validate] ? NORMALISE[f.validate](raw) : raw;
      }
    });

    if (firstBad) {
      firstBad.focus();
      return null;
    }
    return values;
  }

  function sendOrder(channelId) {
    if (inFlight) { return; }
    if (store.snapshot().count === 0) { return; }

    var channel = store.channels().filter(function (c) { return c.id === channelId; })[0];
    if (!channel) { return; }

    /* Validate before anything opens — a popup that appears and then turns out
       to be premature is far worse than a blocked one. */
    var customer = readCustomerForm();
    if (customer === null) {
      toast('لطفاً موارد مشخص‌شده را تکمیل کنید', 'error');
      return;
    }
    store.setCustomer(customer);
    /* «complete the marked fields» from the try before is no longer true, and
       it sat over the panel for its seconds while the order went. */
    $$('.toast--error').forEach(function (el) { el.remove(); });

    /* Taken after the details are saved, so the message, the panel and the
       request carry the same name and address. */
    var snapshot = store.snapshot();
    var text = store.asOrderText(snapshot);

    /* The store decides whether the order fits in the URL. When it does not —
       either the app has no prefill parameter, or the order is too long to
       survive one — it goes on the clipboard instead. */
    var route = store.resolveChannel(channel);
    if (route.needsPaste) {
      /* Inside the click, while the browser still allows a copy, and said
         only once it has happened: a clipboard that refused used to fail in
         silence under a panel announcing «کپی شد». */
      route.copied = false;
      copyText(text, function () {
        route.copied = true;
        paintPasteNote(channel, true);
      }, function () { /* the panel's button offers it again */ });
    }

    /* Opened synchronously inside the click: a popup blocker only permits it
       while the gesture is still on the stack. This is why the order is NOT
       awaited first — a round trip here would put the window.open outside the
       gesture and the chat would silently fail to open on most phones. */
    window.open(route.url, '_blank', 'noopener');

    pingWebhook(snapshot, channel);

    lastSend = { snapshot: snapshot, channel: channel, route: route, text: text,
                 payload: store.asOrderPayload(channelId), code: '' };
    recordOrder();
  }

  /* Record the last order sent, and show what came of it: the payment panel
     with the code, the clock and the card - or why it was not recorded, and
     the step that puts it right.

     That second half is new. A failure used to be swallowed, on the reasoning
     that the message was already on its way in the chat - but the panel then
     sat on «the card comes in the chat, transfer nothing until then» with no
     code, no clock and no record, while the reason could be as small as an
     address the server thought too short. */
  function recordOrder() {
    var s = lastSend;
    if (!s || inFlight) { return; }
    var backend = !!(cfg.api && cfg.api.baseUrl);

    /* Drawn at once from what the browser already knows, so the shopper is
       never left looking at a spinner - and, with a server to answer, as an
       order waiting for it rather than a finished one. */
    inFlight = backend ? s : null;
    showPaymentStep(s.snapshot, s.channel, s.route, backend ? { pending: true } : null);
    if (!backend) { return; }

    repo.createOrder(s.payload).then(function (server) {
      inFlight = null;
      if (!server || !server.ok) {
        showOrderFailed(s, { message: (server && server.error) || '' });
        return;
      }
      s.code = String(server.code || '');
      var phone = (s.snapshot.customer || {}).phone;
      rememberOrderCode(server.code, server, phone);
      pendingPayment = { snapshot: s.snapshot, channel: s.channel, route: s.route, server: server,
                         skew: server.serverNow ? Date.now() - Number(server.serverNow) : 0 };

      /* Out of the basket now that the shop has the order - the lines it took
         and nothing else (store.removeLines()).

         store.clear() existed with no caller once, so the cart survived
         checkout: the panel said the order was placed while the basket beside
         it still held everything, and the next visit opened on a full cart
         for an order already sent. In a shop where the order travels as a
         chat message, that is how the same one gets sent twice. Only on a
         CONFIRMED order: when the record fails, the basket is the only copy
         of what they wanted. */
      store.removeLines(s.snapshot.items);
      showPaymentStep(s.snapshot, s.channel, s.route, server);
      /* And the bar, behind the drawer: close it by mistake, and the way back
         to the card and the receipt is already on the page. */
      if (server.expiresAt && phone) {
        showResumeBar({ code: server.code, phone: phone,
                        expiresAt: server.expiresAt, serverNow: server.serverNow });
      }
    }, function (err) {
      inFlight = null;
      console.warn('[suppex] order was not recorded server-side:', err && err.message);
      showOrderFailed(s, err || {});
    });
  }

  /* Why the order was not recorded, and the one step that fixes it:
     - a detail the server would not take: back to the form, the field marked
       in the server's own words;
     - something in the basket it could not sell: back to the basket, which
       re-reads the prices and marks the line;
     - anything else - no connection, a busy host, too many orders from one
       connection: the same request again, without opening the chat twice.
     The basket is untouched in every case: it is the only copy of the order
     left to send. */
  var failedFields = {};

  function showOrderFailed(s, err) {
    var body = $('[data-cart-items]');
    if (!body) { return; }
    var fields = (err && err.fields) || {};
    var who = ['name', 'phone', 'address', 'postal'].filter(function (k) { return fields[k]; });
    var said = err && err.message && /[\u0600-\u06FF]/.test(err.message) ? err.message : '';
    var o;
    if (who.length) {
      failedFields = {};
      who.forEach(function (k) { failedFields[k] = fields[k]; });
      o = { action: 'fix', label: 'اصلاح مشخصات',
            message: who.map(function (k) { return fields[k]; }).join(' ') };
    } else if (fields.items) {
      o = { action: 'cart', label: 'بازگشت به سبد خرید', message: String(fields.items) };
    } else {
      o = { action: 'retry', label: 'تلاش دوباره',
            message: fields._ || said
              || 'پاسخی از سایت نرسید؛ ممکن است اینترنت قطع شده باشد. دوباره امتحان کنید.' };
    }
    paymentPanelUp = false;
    detailsUp = false;
    failedUp = true;
    body.innerHTML = ui.orderFailed(o);
    var foot = $('[data-drawer] .drawer__foot');
    if (foot) { foot.hidden = true; }
    setPayDock('');
    setDrawerBack(true);
    body.scrollTop = 0;
  }

  /* The paste sentence in the payment panel, once the copy has landed. */
  function paintPasteNote(channel, copied) {
    var out = $('[data-drawer] [data-paste-text]');
    if (out) { out.innerHTML = ui.pasteText((channel && channel.label) || 'همان گفتگو', copied); }
  }

  /* Copying failed both ways: the text itself, selected, to copy by hand. */
  function showOrderText(btn, text) {
    var note = btn.closest('[data-paste-note]');
    var host = note ? $('.pay__paste-body', note) : null;
    if (!host || $('textarea', host)) { return; }
    var ta = document.createElement('textarea');
    ta.className = 'input pay__text';
    ta.readOnly = true;
    ta.rows = 6;
    ta.value = text;
    host.appendChild(ta);
    ta.focus();
    ta.select();
    toast('کپی خودکار نشد — متن را از کادر انتخاب و کپی کنید', 'error');
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

  function rememberOrderCode(code, server, phone) {
    if (!code) { return; }
    rememberMyOrder(code, phone, server && typeof server.total === 'number' ? server.total : null);
    try {
      window.localStorage.setItem(LAST_ORDER_KEY, JSON.stringify({
        code: String(code),
        at: Date.now(),
        /* The phone is the other half of the tracking credential and it is
           already in this browser - the customer typed it into the checkout
           form, which the store keeps. Holding it here too is what lets the
           page ask the server about the order without asking them again. */
        phone: String(phone || ''),
        /* Milliseconds, from the SERVER's clock. A phone set an hour fast
           would otherwise show a live hold as already finished. */
        expiresAt: (server && server.expiresAt) || 0,
        /* What the customer has been shown. The dot appears when the real
           status stops matching this, and clears when they go and look. */
        seenStatus: 'new',
      }));
    } catch (e) { /* private mode, quota, or storage disabled */ }
  }

  /* «سفارش‌های من»: every order this browser placed or looked up - its code
     and the phone it was placed with, the two halves of the tracking
     credential - so the tracking page can list them with their live status.

     On this device only, and on purpose. A history keyed on the phone number
     alone would show one person's orders to anybody who typed that number,
     and anybody can: the order form takes whatever number it is given. Every
     entry here was proved by a code that only its owner was shown. Kept
     until the customer removes it - the privacy page says so - and fifty at
     most. */
  var MY_ORDERS_KEY = 'suppex.orders.v1';
  var MY_ORDERS_MAX = 50;

  function myOrders() {
    try {
      var list = JSON.parse(window.localStorage.getItem(MY_ORDERS_KEY) || '[]');
      return Array.isArray(list)
        ? list.filter(function (o) { return o && o.code && o.phone; })
        : [];
    } catch (e) { return []; }
  }

  function saveMyOrders(list) {
    try {
      window.localStorage.setItem(MY_ORDERS_KEY, JSON.stringify(list.slice(0, MY_ORDERS_MAX)));
    } catch (e) { /* private mode or a full quota: the list is a convenience */ }
  }

  /* Newest first; an order already there keeps its place and its date. */
  function rememberMyOrder(code, phone, total) {
    if (!code || !phone) { return; }
    code = String(code);
    phone = String(phone);
    var list = myOrders();
    var had = list.filter(function (o) { return o.code === code; })[0];
    if (had) {
      had.phone = phone;
      if (typeof total === 'number') { had.total = total; }
    } else {
      list.unshift({ code: code, phone: phone, at: Date.now(), total: typeof total === 'number' ? total : null });
    }
    saveMyOrders(list);
  }

  function forgetMyOrder(code) {
    saveMyOrders(myOrders().filter(function (o) { return o.code !== String(code); }));
    /* The last order too, with its bar: initMyOrders() puts the last order
       back into this list on every page, so «حذف از این فهرست» undid itself
       on the next visit. */
    var last = lastOrder();
    if (last && String(last.code) === String(code)) {
      try { window.localStorage.removeItem(LAST_ORDER_KEY); } catch (e) { /* as everywhere */ }
      var bar = document.querySelector('[data-resume-bar]');
      if (bar && bar.getAttribute('data-code') === String(code)) { bar.remove(); }
    }
  }

  /* The whole stored record. The only place the two-week rule lives, and the
     only reader of the key - lastOrderCode() goes through here.

     ERASED, not merely ignored, once it is stale. This used to return nothing
     and leave the value in localStorage for ever, while the privacy page told
     the customer the code removes itself after two weeks. A promise about
     somebody's data is the last one to keep only approximately. */
  function lastOrder() {
    try {
      var raw = window.localStorage.getItem(LAST_ORDER_KEY);
      if (!raw) { return null; }
      var v = JSON.parse(raw);
      if (!v || !v.code || Date.now() - (v.at || 0) > 14 * 864e5) {
        try { window.localStorage.removeItem(LAST_ORDER_KEY); } catch (e2) {}
        return null;
      }
      return v;
    } catch (e) { return null; }
  }

  function patchLastOrder(fields) {
    var v = lastOrder();
    if (!v) { return; }
    Object.keys(fields).forEach(function (k) { v[k] = fields[k]; });
    try {
      window.localStorage.setItem(LAST_ORDER_KEY, JSON.stringify(v));
    } catch (e) { /* as above: never fatal */ }
  }

  /* One line, because lastOrder() owns the two-week rule now. It used to be a
     second copy of the same expiry check, which is how one of them ends up
     fixed and the other quietly keeping the old behaviour. */
  function lastOrderCode() {
    var v = lastOrder();
    return v ? String(v.code) : '';
  }

  /* Offer the way back, on any page, once there is something to go back to. */
  /* «سفارش در انتظار پرداخت» - one line, every page, while the hold runs.

     Built rather than reused from the drawer on purpose. The drawer's panel
     carries the card number, and rebuilding that on every page would mean
     keeping the card in localStorage; track.php renders it from the database
     instead, behind the same code-plus-phone gate it already had. So this is
     the clock and the way back, and nothing else.

     The way back is a POST of the code and the phone, like «جزئیات سفارش» in
     «سفارش‌های من»: it opens the order itself - the card, the amount and the
     receipt box - where a link opened an empty form to fill in first. A
     phone that reloads the page while its owner is in the bank app lands
     here, and this bar is then the way to the receipt. On the page the order
     was placed from, the panel is still in memory, and the bar reopens it
     instead of leaving. */
  function showResumeBar(rec) {
    var old = document.querySelector('[data-resume-bar]');
    if (old) {
      if (old.getAttribute('data-code') === String(rec.code)) { return; }
      old.remove();
    }

    var bar = document.createElement('form');
    bar.className = 'resume';
    bar.method = 'post';
    bar.action = 'track.php';
    bar.setAttribute('data-resume-bar', '');
    bar.setAttribute('data-code', String(rec.code));
    bar.innerHTML =
      '<input type="hidden" name="code" value="' + ui.esc(rec.code) + '">' +
      '<input type="hidden" name="phone" value="' + ui.esc(rec.phone || '') + '">' +
      ui.icon('clock') +
      '<span class="resume__text">سفارش<span class="resume__code"> <strong class="num" dir="ltr">' +
        ui.esc(rec.code) + '</strong></span> در انتظار پرداخت است</span>' +
      '<span class="resume__hold" data-expires="' + Number(rec.expiresAt) + '"' +
        (rec.serverNow ? ' data-now="' + Number(rec.serverNow) + '"' : '') + '>' +
        '<strong data-hold-countdown>—</strong>' +
      '</span>' +
      '<button class="resume__go" type="submit">پرداخت و رسید</button>';

    bar.addEventListener('submit', function (e) {
      /* The drawer reopens on the panel only when the basket is empty
         (openCartBody()); with something added since, it opened on the basket
         and the bar led nowhere. Then the form goes to track.php, which shows
         the order, its card and its receipt box. */
      if (pendingPayment && String(pendingPayment.server.code) === String(rec.code)
          && paymentStillOpen(pendingPayment) && store.snapshot().count === 0) {
        e.preventDefault();
        setLayer('cart');
      }
    });

    document.body.appendChild(bar);
    startHoldCountdown(bar);
  }

  /* A mark beside the account icon when the shop has moved the order on.
     There are no accounts here, so the icon leads to the tracking page - and
     going there is what counts as having seen it. */
  function markAccountNew(on) {
    $$('[data-order-dot]').forEach(function (el) { el.hidden = !on; });
  }

  function initOrderWatch() {
    var rec = lastOrder();
    if (!rec || !rec.phone) { return; }

    /* Clearing happens on the page the customer went to look at, so it is
       gone by the time they come back rather than while they are reading. */
    if (document.querySelector('[data-track-page]') && rec.status) {
      patchLastOrder({ seenStatus: rec.status });
    }

    repo.getOrderState(rec.code, rec.phone).then(function (state) {
      if (!state) { return; }

      patchLastOrder({ status: state.status });

      var onTrackPage = !!document.querySelector('[data-track-page]');
      if (onTrackPage) {
        patchLastOrder({ seenStatus: state.status });
      } else if (state.status !== (rec.seenStatus || 'new')) {
        markAccountNew(true);
      }

      /* The bar is for an order that can still be paid at the price it was
         quoted. Once it is paid, or once the hold is over, it would be
         telling the customer to do something that is no longer true. */
      /* Nor once a receipt is in: the customer has done their part, and a
         bar still saying «در انتظار پرداخت» reads as the receipt lost. */
      var live = state.status === 'new'
        && state.expiresAt
        && state.expiresAt > (state.serverNow || Date.now())
        && !(state.receipts > 0);
      if (live && !onTrackPage) {
        showResumeBar({ code: rec.code, phone: rec.phone, expiresAt: state.expiresAt,
                        serverNow: state.serverNow });
      }
    });
  }

  function initLastOrder() {
    var rec = lastOrder();
    var code = rec ? String(rec.code) : '';
    if (!code) { return; }

    /* The code box was filled in and the phone box was not, so the form still
       could not be submitted without the customer digging out their own
       number. Both or neither. */
    var phoneField = document.getElementById('phone');
    if (phoneField && !phoneField.value && rec.phone) {
      phoneField.value = rec.phone;
    }
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
    copyText(digits, function () { flashLabel(btn, 'کپی شد'); },
             function () { toast('کپی نشد — شماره را دستی انتخاب کنید', 'error'); });
  }

  /* «کپی شد» for a moment, then the button's own words. Kept from the first
     time: a second tap inside the moment read «کپی شد» as the label, and the
     button said it for good. */
  function flashLabel(btn, text) {
    if (!btn.hasAttribute('data-label')) { btn.setAttribute('data-label', btn.textContent); }
    btn.textContent = text;
    btn.classList.add('is-done');
    clearTimeout(btn.flashTimer);
    btn.flashTimer = setTimeout(function () {
      btn.textContent = btn.getAttribute('data-label');
      btn.classList.remove('is-done');
    }, 1600);
  }

  /* Onto the clipboard, then done() - or fail() when it could not be.
     navigator.clipboard needs a secure context and can still refuse, so the
     old execCommand way is the fallback either way. */
  function copyText(text, done, fail) {
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, function () {
        if (legacyCopy(text)) { done(); } else { fail(); }
      });
    } else if (legacyCopy(text)) {
      done();
    } else {
      fail();
    }
  }

  function legacyCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    /* Fixed and 16px: pushed off to one side it could scroll a right-to-left
       page, and iOS zooms into a smaller field. */
    ta.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;font-size:16px';
    document.body.appendChild(ta);
    ta.select();
    try { ta.setSelectionRange(0, text.length); } catch (err) { /* an older engine */ }

    /* execCommand reports failure by RETURNING false, not by throwing. Taking
       the return value on trust would flash "کپی شد" over an empty clipboard,
       and the shopper would paste nothing into their banking app. */
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
    ta.remove();
    return ok;
  }

  /* What a card's «+» does with a product: add exactly one thing, or send
     the shopper to the page where the choice is.

     A product sold in several sizes or flavours cannot honestly be added
     from a card: nothing on the card says WHICH one, and adding anyway put
     something in the basket the shopper never chose - at a price they never
     saw, which in a card-to-card shop is the figure they transfer. A product
     priced by combination was worse: added with no choice at all it was a
     line the server could not price, and ten of the twelve products on the
     home page went into the basket as «دیگر موجود نیست».

     The server says which it is (product_list_choices() in
     lib/products.php): needsChoice, or the one combination there is - its
     ids, its words and its price. An answer from before it said - a copy
     cached by the browser, or the bundled demo catalogue - gets the old
     rule, with several flavours added to it.

     Returns {go: url} or {add: extras for addToCart()}. Exported for
     tests/test-checkout.mjs. */
  function cardAddPlan(product) {
    if (!product) { return null; }
    var page = (cfg.productPage || 'product.php') + '?p=' + encodeURIComponent(product.slug);
    if (typeof product.needsChoice === 'boolean') {
      var c = product.choice;
      if (product.needsChoice || !c) { return { go: page }; }
      var extras = {
        sizeId: c.sizeId || null,
        flavorId: c.flavorId || null,
        attrs: c.attrs && Object.keys(c.attrs).length ? c.attrs : null,
      };
      if (c.label) { extras.variantLabel = c.label; }
      if (typeof c.price === 'number' && c.price > 0) { extras.price = c.price; }
      return { add: extras };
    }
    var many = (product.sizes && product.sizes.length > 1)
      || (product.flavors && product.flavors.length > 1)
      || product.hasFlavorPremium === true;
    return many ? { go: page } : { add: {} };
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
      /* Part of the line's identity as well as its description: «قرمز» and
         «آبی» in the same size and flavour are two lines, not one of two. */
      attrs: extras.attrs || null,
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
     later - so the day they are typed into the panel they appear on every page,
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
        /* The home page's closing band asks the same question, by name. */
        var band = $('[data-ask-band]');
        var cta = band && $('[data-ask-cta]', band);
        if (cta) {
          cta.setAttribute('href', chat);
          cta.setAttribute('rel', 'noopener');
          cta.setAttribute('target', '_blank');
          cta.textContent = c.telegram ? 'پرسیدن در تلگرام'
            : (c.whatsapp ? 'پرسیدن در واتس‌اپ' : 'پرسیدن در اینستاگرام');
          band.hidden = false;
        }
      }
    }).catch(function () { /* the footer simply stays as rendered */ });
  }

  /* initFooterProductLinks() lived here. It rewrote six footer hrefs from
     cfg.productPage, because the «فروشگاه» column linked to one product per
     category and the right page differed between the static demo and the
     real deployment. That column links to categories now, which are the
     same URL in both, so there is nothing left to rewrite. */

  /* The category page's product grid, upgraded from the server-rendered
     links to the full cards.

     The links are rendered by category.php so that a crawler and a reader
     without JavaScript get real, followable product links - the rich card
     markup stays in ui.productCard rather than being copied into PHP, where
     the two would drift the way five copies of the footer did. */
  function initCategoryPage() {
    var grid = $('[data-cat-products]');
    if (!grid) { return; }
    var slug = new URLSearchParams(location.search).get('c');
    if (!slug) { return; }

    repo.getProducts({ category: slug, limit: 60 }).then(function (list) {
      if (!list || !list.length) { return; }   // keep the server's links
      /* Into the cache, or the cards' «+» has nothing to add (see the click
         handler in initChrome). */
      grid.innerHTML = cache(list).map(ui.productCard).join('');
      observeReveals(grid);
    }).catch(function () { /* the server-rendered links stay */ });
  }

  /* brand.php: the same swap as a category page - the server's plain links
     for the full cards - for one brand's products. */
  /* The header's two lists (partials/chrome-top.php). CSS opens one while the
     pointer is over it; this opens it on a tap or Enter, keeps aria-expanded
     true to what is shown, and closes it on Escape - focus back on its button
     - on a tap elsewhere, or when the focus leaves it. */
  function initNavMenus() {
    var toggles = $$('[data-nav-toggle]');
    if (!toggles.length) { return; }
    var setOpen = function (btn, open) {
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      btn.parentNode.classList.toggle('is-open', open);
    };
    toggles.forEach(function (btn) {
      var item = btn.parentNode;
      btn.addEventListener('click', function () {
        var open = btn.getAttribute('aria-expanded') !== 'true';
        toggles.forEach(function (b) { if (b !== btn) { setOpen(b, false); } });
        setOpen(btn, open);
      });
      item.addEventListener('mouseenter', function () { btn.setAttribute('aria-expanded', 'true'); });
      item.addEventListener('mouseleave', function () {
        if (!item.classList.contains('is-open')) { btn.setAttribute('aria-expanded', 'false'); }
      });
      item.addEventListener('focusout', function (e) {
        if (!item.contains(e.relatedTarget)) { setOpen(btn, false); }
      });
    });
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') { return; }
      toggles.forEach(function (b) {
        if (b.parentNode.classList.contains('is-open')) { setOpen(b, false); b.focus(); }
      });
    });
    document.addEventListener('click', function (e) {
      toggles.forEach(function (b) {
        if (!b.parentNode.contains(e.target)) { setOpen(b, false); }
      });
    });
  }

  /* goal.php: the plain links of each category's section for the full cards -
     a category page's swap, one section at a time (lib/goals.php). */
  function initGoalPage() {
    $$('[data-goal-products]').forEach(function (grid) {
      var cat = grid.getAttribute('data-category');
      if (!cat) { return; }
      repo.getProducts({ category: cat, limit: 60 }).then(function (list) {
        if (!list || !list.length) { return; }   // keep the server's links
        grid.innerHTML = cache(list).map(ui.productCard).join('');
        observeReveals(grid);
      }).catch(function () { /* the server-rendered links stay */ });
    });
  }

  function initBrandPage() {
    var grid = $('[data-brand-products]');
    if (!grid) { return; }
    var slug = new URLSearchParams(location.search).get('b');
    if (!slug) { return; }

    repo.getProducts({ brand: slug, limit: 60 }).then(function (list) {
      if (!list || !list.length) { return; }   // keep the server's links
      grid.innerHTML = cache(list).map(ui.productCard).join('');
      observeReveals(grid);
    }).catch(function () { /* the server-rendered links stay */ });
  }

  /* The brands' band under the home page's hero. index.php draws it
     (lib/brands.php, data-drawn); in the static demo it is drawn here, in the
     place of the strip of words that stands there until then. */
  function initHomeBrands() {
    var strip = $('[data-brandstrip]');
    if (!strip || strip.hasAttribute('data-drawn')) { return; }
    repo.getBrands().then(function (list) {
      if (!list || !list.length || !strip.parentNode) { return; }
      var box = document.createElement('div');
      box.innerHTML = ui.brandStrip(list);
      strip.parentNode.replaceChild(box.firstChild, strip);
    }).catch(function () { /* the words stay */ });
  }


  /* Let the settings page win over the bundled config.js.

     Kept out of initChrome and free of the DOM so tests/test-checkout.mjs can
     hand it the real API shape. Returns whether anything was taken, which is
     also the answer to "is there anything on screen worth repainting".

     The channel rule is the subtle one. store.channels() keeps a row when
     `enabled` is truthy; settings_shop_config() marks a channel configured by
     GIVING it a url and emits no flag at all on older builds. Counting the raw
     rows conflated the two: a response with two urls looked like two channels
     and replaced two working buttons with two the filter then dropped, leaving
     a checkout drawer with no way to send the order. So count what survives
     the store's own filter, and read a missing flag as enabled - the row would
     not be in the response otherwise. */
  /* --- the top-deals hero ------------------------------------------------
     Which three products stand where the 3D tub used to. The owner chooses
     them in the panel with the «تخفیف برتر» tick, which is a badge -
     `topdeal` - stored beside «پرفروش» and «جدید», so it needed no new column
     and nothing that could break if the files went up in the wrong order.

     Ticked products come first. If none is ticked, or every ticked one is
     sold out, the hero falls back to the biggest discounts on offer rather
     than going empty; and if nothing at all is discounted, the static poster
     that has always been underneath simply stays.

     Biggest discount first, which in a right-to-left row is also the first
     one read. Sold-out products never appear: a shop window that leads with
     something nobody can buy is worse than one with two things in it.

     Pure, and exported, so tests/test-hero.mjs can hold it to that. */
  var HERO_DEALS_MAX = 3;

  /* How long each product stands alone in the hero before the next takes
     its place. The owner asked for five or six seconds. */
  var HERO_DEALS_MS = 5500;

  function pickHeroDeals(ticked, all) {
    function usable(p) { return !!p && p.inStock !== false && p.price > 0; }
    function byDiscount(a, b) { return (b.discountPercent || 0) - (a.discountPercent || 0); }
    /* The badge is checked here as well as by the server's filter: an API
       that ignored ?badge= would otherwise hand back the whole catalogue
       and every product would count as ticked. */
    var chosen = (ticked || []).filter(function (p) {
      return usable(p) && (p.badges || []).indexOf('topdeal') !== -1;
    });
    if (!chosen.length) {
      chosen = (all || []).filter(function (p) { return usable(p) && p.onSale; });
    }
    return chosen.slice().sort(byDiscount).slice(0, HERO_DEALS_MAX);
  }

  /* The «before» price to strike through on the product page, or null.

     The selection's own figure wins - a combination or size with its own
     «قیمت قبل از تخفیف». Failing that, the product's: that is what the owner
     fills in on the product form, and it is what the card and the photo
     badge already show. It was being dropped here, because on the Cell-Tech
     page neither the size nor the combinations carry one of their own.

     But the product's figure belongs to the product's headline price and to
     nothing else. A 900g tub at the headline price may show it; the 1.8kg
     tub beside it may not, because nobody ever said what the 1.8kg one cost
     before - and a struck price nobody set is a discount claim nobody made. */
  function beforePrice(product, shown, own) {
    if (own && own > shown) { return own; }
    var p = product || {};
    if (p.compareAt && p.compareAt > shown && shown === p.price) { return p.compareAt; }
    return null;
  }

  function renderShippingCopy() {
    var threshold = cfg.freeShippingThreshold;
    var flat = cfg.shippingFlatRate;
    var amount = function (n) { return '<span class="num">' + ui.money(n) + '</span> تومان'; };
    var announcement;
    var policy;
    if (flat <= 0) {
      announcement = 'ارسال رایگان';
      policy = 'ارسال همهٔ سفارش‌ها رایگان است.';
    } else if (threshold <= 0) {
      announcement = 'هزینهٔ ارسال ' + amount(flat);
      policy = announcement + ' است.';
    } else {
      announcement = 'ارسال رایگان از ' + amount(threshold);
      policy = 'ارسال سفارش‌های ' + amount(threshold) + ' و بیشتر رایگان است؛ برای سفارش‌های کمتر، '
        + amount(flat) + ' هزینهٔ ارسال محاسبه می‌شود.';
    }
    $$('[data-shipping-announcement]').forEach(function (el) { el.innerHTML = announcement; });
    $$('[data-shipping-policy]').forEach(function (el) { el.innerHTML = policy; });
  }

  function mergeRemoteConfig(remote) {
    if (!remote) { return false; }

    if (typeof remote.freeShippingThreshold === 'number') {
      cfg.freeShippingThreshold = remote.freeShippingThreshold;
    }
    if (typeof remote.shippingFlatRate === 'number') {
      cfg.shippingFlatRate = remote.shippingFlatRate;
    }

    var incoming = (remote.ordering && remote.ordering.channels)
      || remote.channels || [];
    var usable = incoming
      .filter(function (c) { return c && c.url && c.enabled !== false; })
      .map(function (c) {
        if (c.enabled === true) { return c; }
        var copy = {};
        Object.keys(c).forEach(function (k) { copy[k] = c[k]; });
        copy.enabled = true;
        return copy;
      });
    if (usable.length) {
      cfg.ordering.channels = usable;
    }
    renderShippingCopy();
    return true;
  }

  function initChrome() {
    /* PHP has already rendered the current settings. Static pages use config.js. */
    if (!window.SUPPEX_BOOT) { renderShippingCopy(); }
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
        var addSlug = add.getAttribute('data-add');
        var product = productCache[addSlug];
        var plan = cardAddPlan(product);
        if (!plan || plan.go) {
          /* The choice is on the product page (cardAddPlan()). And a card
             whose product this script never fetched - which is what every
             card on a category, goal and brand page was, so their «+» did
             nothing at all - goes there too rather than being a dead button. */
          window.location.href = plan ? plan.go
            : (cfg.productPage || 'product.php') + '?p=' + encodeURIComponent(addSlug);
          return;
        }
        if (product) {
          addToCart(product, plan.add);
          flyToCart(add.closest('.pcard'));
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

      /* The order text again, with its code once there is one. */
      var copyOrder = e.target.closest('[data-copy-order]');
      if (copyOrder) {
        e.preventDefault();
        if (!lastSend) { return; }
        var orderText = store.asOrderText(lastSend.snapshot, lastSend.code);
        copyText(orderText, function () {
          lastSend.route.copied = true;
          paintPasteNote(lastSend.channel, true);
          flashLabel(copyOrder, 'کپی شد');
        }, function () { showOrderText(copyOrder, orderText); });
        return;
      }

      /* The order was not recorded: put it right (showOrderFailed()). */
      if (e.target.closest('[data-order-fix]')) {
        e.preventDefault();
        showDetailsStep();
        markFieldErrors(failedFields);
        return;
      }
      if (e.target.closest('[data-order-cart]')) {
        e.preventDefault();
        resetPaymentStep();
        refreshCartPrices();
        return;
      }
      if (e.target.closest('[data-order-retry]')) {
        e.preventDefault();
        recordOrder();
        return;
      }

      /* Its price ran out: the same goods back in the basket, at whatever
         they cost now - the drawer re-reads the prices and says what moved. */
      if (e.target.closest('[data-order-again]')) {
        e.preventDefault();
        if (lastSend) {
          lastSend.snapshot.items.forEach(function (i) {
            if (i.unavailable) { return; }
            store.add({ slug: i.slug, nameFa: i.nameFa, name: i.name, image: i.image,
                        price: i.price, variantLabel: i.variantLabel,
                        flavorId: i.flavorId || null, sizeId: i.sizeId || null,
                        attrs: i.attrs || null, qty: i.qty });
          });
        }
        pendingPayment = null;
        resetPaymentStep();
        refreshCartPrices();
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
      /* Which query the list on screen may answer. On this host an answer
         can take seconds, and one for «پرو» arriving after the one for
         «پروتئین» painted the wrong list over the right one. */
      var asked = 0;
      searchInput.addEventListener('input', function () {
        clearTimeout(timer);
        var q = searchInput.value;
        timer = setTimeout(function () {
          var mine = ++asked;
          var out = $('[data-search-results]');
          if (!q.trim()) {
            out.innerHTML = '<p class="u-sm u-dim" style="padding:12px 4px">نام محصول، برند، دسته یا هدف (مثلاً «عضله‌سازی») را بنویسید.</p>';
            return;
          }
          repo.search(q).then(function (hits) {
            if (mine !== asked) { return; }
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

    var drawerEl = $('[data-drawer]');
    if (drawerEl) { setDrawerReach(drawerEl, false); }

    /* Let the panel win.

       assets/js/config.js is the BUNDLED fallback — the shop reads its
       catalogue from it when there is no backend at all. Once a backend exists,
       the shipping figures and the chat links belong to the settings page, and
       until now only `contact` was taken from the API: the free-shipping
       threshold, the flat rate and the ordering channels all stayed on whatever
       was hardcoded in the file.

       That was a money bug, not a tidiness one. lib/orders.php:402-404 computes
       shipping from the SETTINGS, so changing the flat rate in the panel showed
       the customer one figure and charged another — and in a card-to-card shop
       the figure they read is the figure they transfer.

       Merged after the first render rather than before it: the values only
       affect the cart drawer and the «ارسال رایگان بالای …» line, neither of
       which a shopper can reach in the time one request takes, and blocking the
       whole page on a config fetch would trade a rare wrong number for a
       guaranteed blank screen when the API is slow. */
    repo.getShopConfig().then(function (remote) {
      if (!mergeRemoteConfig(remote)) { return; }
      /* Whatever is on screen was drawn from the old numbers. */
      renderCart(store.snapshot());
    });
  }

  /* --------------------------------------------------------------------
     Into the basket, seen: the card's photograph flies to the basket in the
     header, which gives a small bump when it lands. Feedback, not decoration
     - the toast says it in words, this says where it went. A shopper who
     asked for less motion gets only the bump.
     -------------------------------------------------------------------- */
  function bumpCart() {
    var btn = $('[data-open="cart"]');
    if (!btn) { return; }
    btn.classList.remove('is-bump');
    void btn.offsetWidth;
    btn.classList.add('is-bump');
  }

  function flyToCart(card) {
    var target = $('[data-open="cart"]');
    var img = card && card.querySelector('.media img');
    var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (still || !img || !target || !img.animate) { bumpCart(); return; }
    var a = img.getBoundingClientRect();
    var b = target.getBoundingClientRect();
    if (!a.width || !b.width) { bumpCart(); return; }
    var ghost = document.createElement('img');
    ghost.src = img.currentSrc || img.src;
    ghost.alt = '';
    ghost.className = 'fly-ghost';
    ghost.style.cssText = 'left:' + a.left + 'px;top:' + a.top + 'px;width:' + a.width + 'px;height:' + a.height + 'px';
    document.body.appendChild(ghost);
    var dx = (b.left + b.width / 2) - (a.left + a.width / 2);
    var dy = (b.top + b.height / 2) - (a.top + a.height / 2);
    var flight = ghost.animate([
      { transform: 'translate(0, 0) scale(1)', opacity: 1 },
      { transform: 'translate(' + dx * 0.45 + 'px, ' + (dy * 0.45 - 70) + 'px) scale(.5)', opacity: 0.95, offset: 0.5 },
      { transform: 'translate(' + dx + 'px, ' + dy + 'px) scale(.08)', opacity: 0.25 }
    ], { duration: 680, easing: 'cubic-bezier(.45, 0, .25, 1)' });
    var done = function () { if (ghost.parentNode) { ghost.parentNode.removeChild(ghost); } bumpCart(); };
    flight.onfinish = done;
    flight.oncancel = done;
  }

  /* --------------------------------------------------------------------
     From a card to its page, the photograph carries over: a cross-document
     view transition (@view-transition in base.css), where the browser has
     one. The tapped card's picture is named at the moment of the tap - only
     that one, since two elements under one name cancel the whole transition
     - and the product page's main photograph carries the same name in CSS.
     -------------------------------------------------------------------- */
  function initPhotoHandoff() {
    if (!('onpagereveal' in window)) { return; }
    var clear = function () {
      $$('[data-vt-named]').forEach(function (el) {
        el.style.viewTransitionName = '';
        el.removeAttribute('data-vt-named');
      });
    };
    document.addEventListener('click', function (e) {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
      var link = e.target.closest('a[href*="product.php?p="], a[href*="product.html?p="]');
      if (!link || link.target === '_blank') { return; }
      var holder = link.closest('.pcard, .deal');
      var img = holder && holder.querySelector('img');
      clear();
      if (!img) { return; }
      /* On a product page the main photograph already has the name. */
      var main = $('[data-p-image]');
      if (main && main !== img) {
        main.style.viewTransitionName = 'none';
        main.setAttribute('data-vt-named', '');
      }
      img.style.viewTransitionName = 'product-photo';
      img.setAttribute('data-vt-named', '');
    });
    /* Back from the product page, out of the cache: the name must not linger
       for the next tap to find twice. */
    window.addEventListener('pageshow', clear);
  }

  /* --------------------------------------------------------------------
     The light under the pointer on a card, on a desktop: the card's
     ::before reads --mx and --my (layout.css). One listener for the page,
     once a frame, and only for a mouse - a finger has no hover.
     -------------------------------------------------------------------- */
  function initSpotlight() {
    if (!window.matchMedia || !window.matchMedia('(hover: hover) and (pointer: fine)').matches
        || window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }
    var queued = false;
    var last = null;
    document.addEventListener('pointermove', function (e) {
      last = e;
      if (queued) { return; }
      queued = true;
      requestAnimationFrame(function () {
        queued = false;
        var card = last.target && last.target.closest && last.target.closest('.pcard, .goal, .trust-card');
        if (!card) { return; }
        var r = card.getBoundingClientRect();
        card.style.setProperty('--mx', Math.round(last.clientX - r.left) + 'px');
        card.style.setProperty('--my', Math.round(last.clientY - r.top) + 'px');
      });
    }, { passive: true });
  }

  /* ====================================================================
     Home page
     ==================================================================== */
  /* The product grid's page size; index.php's HOME_PRODUCTS is the same. */
  var HOME_PRODUCTS = 12;

  /* A category with nothing on sale is not offered as a way in - «۰ محصول»
     is a dead end with a picture on it. index.php applies the same rule. */
  function shopCategories(cats) {
    return (cats || []).filter(function (c) { return Number(c.count) > 0; });
  }

  function initHome() {
    var catGrid = $('[data-categories]');
    var prodGrid = $('[data-products]');
    if (!catGrid && !prodGrid) { return; }

    /* How many each category has, for «۱۲ از ۴۰» under the grid. */
    var catCount = {};
    if (catGrid) {
      repo.getCategories().then(function (cats) {
        catGrid.innerHTML = shopCategories(cats).map(ui.categoryCard).join('');
        observeReveals(catGrid);
      });
    }

    /* Featured grid with category filtering. The filter runs through the
       repository rather than hiding DOM nodes, so it behaves identically once
       a server is doing the filtering.

       Twelve at first, twelve more for each «نمایش بیشتر» - asked for as one
       longer list, so the first twelve come from the page's boot and the rest
       from one request, in the order the shop sets. Only the new cards are
       added; the ones on screen stay where they are. */
    var activeCategory = null;
    var shown = HOME_PRODUCTS;
    var moreBox  = $('[data-more]');
    var moreBtn  = $('[data-more-btn]');
    var moreNote = $('[data-more-note]');
    var allTotal = moreBox ? parseInt(moreBox.getAttribute('data-total'), 10) : NaN;

    function paintMore(count, asked) {
      if (!moreBox) { return; }
      var total = activeCategory ? catCount[activeCategory] : allTotal;
      var more = typeof total === 'number' && !isNaN(total) ? count < total : count >= asked;
      moreBox.hidden = !more;
      moreNote.textContent = typeof total === 'number' && !isNaN(total)
        ? ui.faDigits(count) + ' از ' + ui.faDigits(total) + ' محصول' : '';
      moreBtn.disabled = false;
      moreBtn.classList.remove('is-loading');
    }

    function loadProducts(more) {
      if (!prodGrid) { return; }
      if (!more) { shown = HOME_PRODUCTS; }
      var asked = shown;
      if (more && moreBtn) {
        moreBtn.disabled = true;
        moreBtn.classList.add('is-loading');
        prodGrid.insertAdjacentHTML('beforeend', ui.productSkeleton(4));
      }
      repo.getProducts({ category: activeCategory, limit: asked }).then(function (list) {
        cache(list);
        if (more) {
          $$('.pcard--skel', prodGrid).forEach(function (s) { s.parentNode.removeChild(s); });
          var have = $$('.pcard', prodGrid).length;
          prodGrid.insertAdjacentHTML('beforeend', list.slice(have).map(ui.productCard).join(''));
        } else {
          prodGrid.innerHTML = list.length
            ? list.map(ui.productCard).join('')
            : '<p class="u-muted">محصولی در این دسته‌بندی موجود نیست.</p>';
        }
        observeReveals(prodGrid);
        paintMore(list.length, asked);
      }).catch(function () {
        $$('.pcard--skel', prodGrid).forEach(function (s) { s.parentNode.removeChild(s); });
        if (moreBtn) { moreBtn.disabled = false; moreBtn.classList.remove('is-loading'); }
      });
    }

    if (moreBtn) {
      moreBtn.addEventListener('click', function () {
        shown += HOME_PRODUCTS;
        loadProducts(true);
      });
    }

    var filterBar = $('[data-filters]');
    if (filterBar) {
      repo.getCategories().then(function (cats) {
        var chips = ['<button type="button" class="chip is-active" data-filter="">همه محصولات</button>'];
        cats.forEach(function (c) { catCount[c.id] = c.count; });
        shopCategories(cats).forEach(function (c) {
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

  /* The hero's clock, apart from the page so tests/test-hero.mjs can run it
     on a fake timer.

     tick() is called each time a product has had its `ms`. hold(why, on)
     stops or releases the clock for one reason - a mouse resting on the
     box, keyboard focus inside it, the box scrolled away, the tab hidden,
     the pause button - and it runs only while no reason holds it.

     A hold keeps the time already served: a pointer that rests on the box
     for a moment does not cost the product in front of it its turn. The
     exception is `fresh`, for the play button - whoever presses play gets
     the product in front of them for its full time, and the bar starts
     again with it. reset() is the full time too, for a product that has
     just arrived. */
  function dealClock(ms, tick, timer) {
    timer = timer || {
      now: function () { return Date.now(); },
      set: function (fn, t) { return setTimeout(fn, t); },
      clear: function (id) { clearTimeout(id); },
    };
    var held = {};
    var left = ms;
    var since = 0;
    var id = null;

    function running() {
      for (var why in held) {
        if (held[why]) { return false; }
      }
      return true;
    }
    function stop() {
      if (id === null) { return; }
      timer.clear(id);
      id = null;
      left = Math.max(0, left - (timer.now() - since));
    }
    function go() {
      if (id !== null || !running()) { return; }
      since = timer.now();
      id = timer.set(function () {
        id = null;
        left = ms;
        tick();
        go();
      }, left);
    }

    return {
      hold: function (why, on, fresh) {
        stop();
        held[why] = !!on;
        if (fresh) { left = ms; }
        go();
      },
      reset: function () {
        stop();
        left = ms;
        go();
      },
      running: running,
    };
  }

  function initHeroDeals() {
    var wrap = $('[data-deals-wrap]');
    var list = $('[data-deals]');
    var row  = $('[data-deals-row]');
    if (!wrap || !list || !row) { return; }

    repo.getProducts({ badge: 'topdeal' })
      .then(function (ticked) {
        var picks = pickHeroDeals(ticked, []);
        if (picks.length) { return picks; }
        /* Nothing ticked that can be sold: the biggest discounts instead.
           Twenty-four is what the 3D shelf read for the same purpose. */
        return repo.getProducts({ limit: 24 }).then(function (all) {
          return pickHeroDeals([], all);
        });
      })
      .then(function (picks) {
        if (!picks || !picks.length) { return; }
        /* index.php draws these same slides into the page, so the box has
           its size from the first paint (hero_deal_slide() in
           lib/products.php). Drawn already, they are kept: drawing them
           again would only fetch the photographs a second time. */
        var drawn = picks.map(function (p) { return p.slug; }).join(',');
        if (row.getAttribute('data-drawn') !== drawn) {
          row.innerHTML = picks.map(function (p, i) {
            return ui.dealSlide(p, i, picks.length);
          }).join('');
        }
        list.hidden = false;
        wrap.classList.add('has-deals');
        rotateDeals(list, picks);
      })
      /* Any failure leaves the poster exactly as the page drew it. */
      .catch(function () {});
  }

  /* The products take turns, each for HERO_DEALS_MS (dealClock above).

     The clock is a JavaScript timer, and the bar under the product only
     pictures it. tokens.css cuts every animation to 0.01ms under
     prefers-reduced-motion, so a rotation that moved on when the bar's
     animation ended would flick through all three in a frame - for exactly
     the visitors who asked for less movement.

     It holds while a mouse rests on the box, while keyboard focus is inside
     it, while it is scrolled out of view and while the tab is hidden; and
     for good once the pause button is pressed, which WCAG 2.2.2 asks of
     anything that changes on its own for longer than five seconds. Under
     prefers-reduced-motion it starts paused, the bars and the play button
     still working. */
  function rotateDeals(list, picks) {
    var row = $('[data-deals-row]', list);
    var pips = $('[data-deals-pips]', list);
    var toggle = $('[data-deals-toggle]', list);
    var slides = row ? $$('.deal', row) : [];
    var count = slides.length;
    if (count < 2 || !pips || !toggle) { return; }

    var at = 0;
    var clock = dealClock(HERO_DEALS_MS, function () { show(at + 1); });

    list.style.setProperty('--deals-ms', HERO_DEALS_MS + 'ms');
    pips.innerHTML = picks.slice(0, count).map(function (p, i) {
      return '<button type="button" class="deals__pip' + (i === 0 ? ' is-on" aria-current="true"' : '"') +
        ' data-deals-go="' + i + '" aria-label="' +
        ui.esc(ui.faDigits(i + 1) + ' از ' + ui.faDigits(count) + '، ' + p.nameFa) + '"></button>';
    }).join('');
    var bars = $$('.deals__pip', pips);
    pips.hidden = false;
    toggle.hidden = false;

    function paint() {
      list.classList.toggle('is-paused', !clock.running());
    }
    function hold(why, on, fresh) {
      clock.hold(why, on, fresh);
      paint();
    }
    function show(i) {
      var next = ((i % count) + count) % count;
      if (next === at) { return; }
      at = next;
      slides.forEach(function (s, n) { s.classList.toggle('is-on', n === at); });
      bars.forEach(function (b, n) {
        b.classList.toggle('is-on', n === at);
        if (n === at) { b.setAttribute('aria-current', 'true'); } else { b.removeAttribute('aria-current'); }
      });
      clock.reset();
      paint();
    }
    function stopped(on) {
      list.classList.toggle('is-stopped', on);
      toggle.setAttribute('aria-label', on ? 'ادامهٔ نمایش خودکار' : 'توقف نمایش خودکار');
      /* Stopped, it changes only when somebody asks, so each change can be
         read out; running, it would talk over the rest of the page. */
      row.setAttribute('aria-live', on ? 'polite' : 'off');
      hold('user', on, !on);
    }

    toggle.addEventListener('click', function () {
      stopped(!list.classList.contains('is-stopped'));
    });
    pips.addEventListener('click', function (e) {
      var b = e.target.closest('[data-deals-go]');
      if (b) { show(Number(b.getAttribute('data-deals-go'))); }
    });

    /* A mouse only: a finger that taps the box has not come to rest on it. */
    list.addEventListener('pointerenter', function (e) {
      if (e.pointerType === 'mouse') { hold('hover', true); }
    });
    list.addEventListener('pointerleave', function (e) {
      if (e.pointerType === 'mouse') { hold('hover', false); }
    });
    /* Keyboard focus only. A click on play focuses the button as well, and
       holding for that would stop the rotation the click had just started. */
    list.addEventListener('focusin', function (e) {
      var keyboard = true;
      try { keyboard = e.target.matches(':focus-visible'); } catch (err) { /* no :focus-visible - hold */ }
      hold('focus', keyboard);
    });
    list.addEventListener('focusout', function (e) {
      if (!e.relatedTarget || !list.contains(e.relatedTarget)) { hold('focus', false); }
    });
    document.addEventListener('visibilitychange', function () { hold('hidden', document.hidden); });
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (entries) {
        entries.forEach(function (en) { hold('away', en.intersectionRatio < 0.35); });
      }, { threshold: [0, 0.35, 0.6] }).observe(list);
    }

    /* A sideways swipe moves along. In a right-to-left row the next product
       comes from the left, so a finger moving right brings it. */
    var startX = null;
    var startY = 0;
    var swiped = false;
    row.addEventListener('pointerdown', function (e) {
      swiped = false;
      if (e.pointerType === 'mouse') { return; }
      startX = e.clientX;
      startY = e.clientY;
    });
    row.addEventListener('pointerup', function (e) {
      if (startX === null) { return; }
      var dx = e.clientX - startX;
      var dy = e.clientY - startY;
      startX = null;
      if (Math.abs(dx) < 40 || Math.abs(dx) < Math.abs(dy) * 1.2) { return; }
      swiped = true;
      var rtl = window.getComputedStyle(row).direction === 'rtl';
      show(at + ((dx > 0) === rtl ? 1 : -1));
    });
    row.addEventListener('pointercancel', function () { startX = null; });
    /* The swipe ended on a link; that was not a tap on it. */
    row.addEventListener('click', function (e) {
      if (swiped) { e.preventDefault(); swiped = false; }
    }, true);

    hold('hidden', document.hidden);
    stopped(!!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches));
  }

  /* ====================================================================
     Pictures: stepping through them, and the enlarged view
     ==================================================================== */

  /* i moved by d, wrapping at either end of n pictures. */
  function galleryStep(i, d, n) {
    if (!n) { return 0; }
    return (((i + d) % n) + n) % n;
  }

  function isRtl() {
    return (document.documentElement.getAttribute('dir') || '').toLowerCase() === 'rtl';
  }

  /* A swipe of dx pixels, as a step. In a right-to-left page the next
     picture comes from the left, so a finger moving right brings it. */
  function swipeStep(dx, rtl) {
    return (dx > 0) === rtl ? 1 : -1;
  }

  /* The arrow keys, mirrored the same way: ← is «next» read right to left. */
  function keyStep(key, rtl) {
    if (key === 'ArrowLeft') { return rtl ? 1 : -1; }
    if (key === 'ArrowRight') { return rtl ? -1 : 1; }
    return 0;
  }

  /* Zoom to s about a point - (px, py), measured from the centre of the
     stage - so the part of the picture under the finger stays under it.
     z is {s, x, y}: a scale and a pan, applied as translate(x, y) scale(s)
     about the picture's centre. */
  function zoomAbout(z, s, px, py) {
    var ix = (px - z.x) / z.s;
    var iy = (py - z.y) / z.s;
    return { s: s, x: px - s * ix, y: py - s * iy };
  }

  /* Never pan past an edge: a zoomed picture keeps the stage covered in any
     direction it is larger than the stage, and stays centred in any it is
     not. w and h are the picture's unzoomed size, sw and sh the stage's. */
  function clampPan(z, w, h, sw, sh) {
    var mx = Math.max(0, (w * z.s - sw) / 2);
    var my = Math.max(0, (h * z.s - sh) / 2);
    return { s: z.s, x: Math.min(mx, Math.max(-mx, z.x)), y: Math.min(my, Math.max(-my, z.y)) };
  }

  /* A product photograph, enlarged, on every screen size.

     A <dialog> opened with showModal(): the page behind it is inert, Escape
     closes it and focus goes back to whatever opened it - three things a
     hand-built overlay has to get right one at a time. A tap on the picture
     zooms to LIGHTBOX_ZOOM at that spot and a second tap comes back out; a
     drag pans, two fingers pinch, the wheel zooms on a desktop, and a swipe
     at 1x moves to the next picture. The phone's back button closes it
     instead of leaving the product page, because on a phone that is what
     the back button is for.

     A browser without <dialog> gets the picture in a new tab instead. */
  var LIGHTBOX_ZOOM = 2.5;
  var LIGHTBOX_MAX = 4;
  var lightbox = null;

  function openLightbox(list, start, label, onClose) {
    if (!list || !list.length) { return; }
    if (lightbox === null) { lightbox = buildLightbox() || false; }
    if (!lightbox) {
      window.open(list[start] || list[0], '_blank', 'noopener');
      return;
    }
    lightbox.open(list, start, label, onClose);
  }

  function buildLightbox() {
    var dlg = document.createElement('dialog');
    if (typeof dlg.showModal !== 'function') { return null; }
    dlg.className = 'lightbox';
    dlg.setAttribute('aria-label', 'تصاویر محصول');
    dlg.innerHTML = '' +
      '<div class="lightbox__bar">' +
        '<span class="lightbox__count num" data-lb-count aria-live="polite"></span>' +
        '<button type="button" class="lightbox__btn" data-lb-close aria-label="بستن">' + ui.icon('close') + '</button>' +
      '</div>' +
      '<div class="lightbox__stage" data-lb-stage>' +
        '<img class="lightbox__img" data-lb-img alt="" draggable="false">' +
      '</div>' +
      '<button type="button" class="lightbox__btn lightbox__nav lightbox__nav--prev" data-lb-step="-1" ' +
        'aria-label="تصویر قبلی">' + ui.icon('chevR') + '</button>' +
      '<button type="button" class="lightbox__btn lightbox__nav lightbox__nav--next" data-lb-step="1" ' +
        'aria-label="تصویر بعدی">' + ui.icon('chevL') + '</button>' +
      '<p class="lightbox__hint">برای بزرگ‌نمایی روی تصویر بزنید؛ دوباره بزنید تا برگردد.</p>';
    document.body.appendChild(dlg);

    var stage = $('[data-lb-stage]', dlg);
    var img = $('[data-lb-img]', dlg);
    var count = $('[data-lb-count]', dlg);
    var list = [];
    var at = 0;
    var label = '';
    var done = null;
    var z = { s: 1, x: 0, y: 0 };
    var pushed = false;
    var closed = true;

    function apply(animate) {
      img.classList.toggle('is-moving', !animate);
      img.style.transform = z.s > 1
        ? 'translate(' + z.x + 'px, ' + z.y + 'px) scale(' + z.s + ')'
        : '';
      dlg.classList.toggle('is-zoomed', z.s > 1);
    }
    function fit(next) {
      return clampPan(next, img.offsetWidth, img.offsetHeight, stage.clientWidth, stage.clientHeight);
    }
    function unzoom(animate) {
      z = { s: 1, x: 0, y: 0 };
      apply(animate);
    }
    function centre() {
      var r = stage.getBoundingClientRect();
      return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
    }
    function show(i) {
      at = galleryStep(i, 0, list.length);
      unzoom(false);
      img.src = list[at];
      img.alt = label + ' — تصویر ' + ui.faDigits(at + 1) + ' از ' + ui.faDigits(list.length);
      count.textContent = ui.faDigits(at + 1) + ' / ' + ui.faDigits(list.length);
      count.hidden = list.length < 2;
      $$('[data-lb-step]', dlg).forEach(function (b) { b.hidden = list.length < 2; });
      if (list.length > 1) {
        new Image().src = list[galleryStep(at, 1, list.length)];
        new Image().src = list[galleryStep(at, -1, list.length)];
      }
    }

    /* Everything closing the view has to undo, in one place and safe to run
       twice. It runs straight away from every close this code makes, and
       again from the dialog's own close event - which is how Escape gets
       here. That event is a queued task, and a page in a background tab
       can hold it back indefinitely: the page stayed locked with nothing
       on it until the tab came forward. */
    function finish() {
      if (closed) { return; }
      closed = true;
      document.body.classList.remove('is-locked');
      unzoom(false);
      if (pushed) {
        pushed = false;
        try { history.back(); } catch (err) { /* nothing to undo */ }
      }
      if (done) { done(at); }
    }
    function shut() {
      if (dlg.open) { dlg.close(); }
      finish();
    }

    dlg.addEventListener('click', function (e) {
      if (e.target.closest('[data-lb-close]')) { shut(); return; }
      var step = e.target.closest('[data-lb-step]');
      if (step) { show(at + Number(step.getAttribute('data-lb-step'))); }
    });
    dlg.addEventListener('keydown', function (e) {
      var d = keyStep(e.key, isRtl());
      if (d && list.length > 1) {
        e.preventDefault();
        show(at + d);
      }
    });
    dlg.addEventListener('close', finish);
    window.addEventListener('popstate', function () {
      if (dlg.open) {
        pushed = false;
        shut();
      }
    });

    /* One finger, or the mouse, pans a zoomed picture and swipes an unzoomed
       one; two fingers pinch; a tap zooms in or back out; a tap beside the
       picture closes the view. */
    var pts = {};
    var g = null;
    function spread(a, b) { return Math.sqrt((a.x - b.x) * (a.x - b.x) + (a.y - b.y) * (a.y - b.y)) || 1; }
    function mid(a, b) { return { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 }; }

    stage.addEventListener('pointerdown', function (e) {
      if (e.pointerType === 'mouse' && e.button !== 0) { return; }
      try { stage.setPointerCapture(e.pointerId); } catch (err) { /* already released */ }
      pts[e.pointerId] = { x: e.clientX, y: e.clientY };
      var ids = Object.keys(pts);
      if (ids.length === 1) {
        g = { kind: 'one', x: e.clientX, y: e.clientY, t: Date.now(), z: z, moved: false, onImg: e.target === img };
      } else if (ids.length === 2) {
        g = { kind: 'pinch', d: spread(pts[ids[0]], pts[ids[1]]), m: mid(pts[ids[0]], pts[ids[1]]), z: z };
      }
    });
    stage.addEventListener('pointermove', function (e) {
      if (!pts[e.pointerId] || !g) { return; }
      pts[e.pointerId] = { x: e.clientX, y: e.clientY };
      if (g.kind === 'pinch') {
        var ids = Object.keys(pts);
        if (ids.length < 2) { return; }
        var a = pts[ids[0]];
        var b = pts[ids[1]];
        var m = mid(a, b);
        var c = centre();
        var s = Math.min(LIGHTBOX_MAX, Math.max(1, g.z.s * spread(a, b) / g.d));
        var next = zoomAbout(g.z, s, g.m.x - c.x, g.m.y - c.y);
        next.x += m.x - g.m.x;
        next.y += m.y - g.m.y;
        z = fit(next);
        apply(false);
        return;
      }
      var dx = e.clientX - g.x;
      var dy = e.clientY - g.y;
      if (Math.abs(dx) > 6 || Math.abs(dy) > 6) { g.moved = true; }
      if (g.moved && z.s > 1) {
        z = fit({ s: z.s, x: g.z.x + dx, y: g.z.y + dy });
        apply(false);
      }
    });
    function lift(e) {
      if (!pts[e.pointerId]) { return; }
      delete pts[e.pointerId];
      if (!g) { return; }
      if (g.kind === 'pinch') {
        if (!Object.keys(pts).length) {
          if (z.s < 1.05) { unzoom(true); }
          g = null;
        }
        return;
      }
      var was = g;
      g = null;
      if (e.type === 'pointercancel') { return; }
      var dx = e.clientX - was.x;
      var dy = e.clientY - was.y;
      if (!was.moved && Date.now() - was.t < 450) {
        if (z.s > 1) { unzoom(true); return; }
        if (!was.onImg) { shut(); return; }
        var c = centre();
        z = fit(zoomAbout(z, LIGHTBOX_ZOOM, e.clientX - c.x, e.clientY - c.y));
        apply(true);
        return;
      }
      if (z.s === 1 && list.length > 1 && Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.2) {
        show(at + swipeStep(dx, isRtl()));
      }
    }
    stage.addEventListener('pointerup', lift);
    stage.addEventListener('pointercancel', lift);
    stage.addEventListener('wheel', function (e) {
      e.preventDefault();
      var c = centre();
      var s = Math.min(LIGHTBOX_MAX, Math.max(1, z.s * Math.exp(-e.deltaY * 0.0015)));
      if (s <= 1.01) { unzoom(false); return; }
      z = fit(zoomAbout(z, s, e.clientX - c.x, e.clientY - c.y));
      apply(false);
    }, { passive: false });

    return {
      open: function (pics, start, name, onClose) {
        list = pics.slice();
        label = name || '';
        done = onClose || null;
        show(start || 0);
        closed = false;
        dlg.showModal();
        document.body.classList.add('is-locked');
        try {
          history.pushState({ lightbox: true }, '');
          pushed = true;
        } catch (err) { pushed = false; }
      },
    };
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
      /* Empty, not 'default'. This row exists only so the price and stock
         rendering has something to read; it does not correspond to any
         product_sizes row, and inventing an id for it made the server treat
         every single-price product as a cart holding a size that no longer
         exists — which it refuses. */
      id: '', label: product.variantLabel || '—',
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

    /* Axes beyond size and flavour - «رنگ», «تعداد کپسول», whatever the owner
       added. Those two already have chips of their own further down. */
    var extraAttrs = (product.attributes || []).filter(function (a) {
      return a.id !== 'size' && a.id !== 'flavor';
    });

    var selected = {
      size: firstSellable,
      flavor: flavors[0] || null,
      /* One value per extra axis, defaulting to the first - the same rule the
         flavour chips follow, so the page is never in a state the shopper did
         not choose and cannot see. */
      attrs: (function () {
        var out = {};
        extraAttrs.forEach(function (a) {
          if (a.values && a.values.length) { out[a.id] = a.values[0].id; }
        });
        return out;
      })(),
      qty: 1,
    };

    /* The combination the current selection names, or null.

       Matched on the choice map the API sends with each row rather than on a
       key this file builds: the key's shape is the server's business, and a
       second implementation of it here is a second thing to keep in step. */
    function currentVariant() {
      var rows = product.variants;
      if (!rows || !rows.length) { return null; }

      var want = {};
      if (selected.size && selected.size.id) { want.size = selected.size.id; }
      if (selected.flavor && selected.flavor.id) { want.flavor = selected.flavor.id; }
      Object.keys(selected.attrs).forEach(function (k) { want[k] = selected.attrs[k]; });

      for (var i = 0; i < rows.length; i++) {
        var c = rows[i].choice || {};
        var keys = Object.keys(c);
        var hit = keys.length === Object.keys(want).length;
        for (var j = 0; hit && j < keys.length; j++) {
          if (want[keys[j]] !== c[keys[j]]) { hit = false; }
        }
        if (hit) { return rows[i]; }
      }
      return null;
    }

    document.title = product.nameFa + ' | ' + cfg.brand.name;
    var metaDesc = $('meta[name="description"]');
    if (metaDesc) { metaDesc.setAttribute('content', product.short); }

    /* --- static text --- */
    var set = function (sel, value) {
      var el = $(sel);
      if (el) { el.innerHTML = value; }
    };

    /* The brand's name leads to its page. The API says which slug, and
       sends null for a brand the owner hid - then it stays plain text rather
       than a link to «برند پیدا نشد». The bundled catalogue has no say, so
       offline every brand links. */
    var brandSlug = product.brandSlug !== undefined ? product.brandSlug : repo.brandSlug(product.brand);
    set('[data-p-eyebrow]',
      (brandSlug
        ? '<a class="pdp__brand" href="' + ui.esc(ui.brandHref(brandSlug)) + '">' + ui.esc(product.brand) + '</a>'
        : ui.esc(product.brand)) +
      ' · <span class="lat">' + ui.esc(product.name) + '</span>');
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
    var galleryBox = $('[data-gallery]');
    var mainBox = $('[data-gallery-main]');
    var countEl = $('[data-gallery-count]');
    var many = gallery.length > 1;

    /* The picture on the main stage - and, when the chosen flavour or
       combination has a photograph of its own, that photograph, standing in
       the first place where the product's own would be. One list, so the
       arrows, the thumbnails, the count and the enlarged view never disagree
       about which picture is which. */
    var shown = 0;
    var lead = null;

    function pictures() {
      var list = gallery.slice();
      if (lead) { list[0] = lead; }
      return list;
    }

    function setLead(src) {
      lead = src;
      var first = thumbs ? $('[data-thumb="0"] img', thumbs) : null;
      if (first) { first.src = ui.thumbSrc(src || gallery[0], product.imgv, 160); }
    }

    function showPicture(i) {
      var list = pictures();
      shown = galleryStep(i, 0, list.length);
      /* Only when it is another picture. The server draws the one the page
         opens on (product_opening_image() in lib/products.php); setting it
         again asks the browser to start it over. */
      if (mainImg && mainImg.getAttribute('src') !== list[shown]) {
        /* srcset before src, and always both: with a srcset in place the
           browser draws from it and ignores src, so a picture without copies
           must drop the previous one's set or keep showing it. */
        var set = ui.srcsetOf(list[shown], product.imgv);
        if (set) {
          mainImg.sizes = ui.SIZES_MAIN;
          mainImg.srcset = set;
        } else {
          mainImg.removeAttribute('srcset');
        }
        mainImg.src = list[shown];
      }
      if (mainImg) {
        /* The alt says which flavour is on screen, because for a shopper
           using a screen reader the picture changing is the only thing that
           just happened and it must not be silent. */
        mainImg.alt = product.nameFa + (shown > 0
          ? ' — نمای ' + ui.faDigits(shown + 1)
          : (lead && selected.flavor && selected.flavor.image
            ? ' — ' + selected.flavor.name
            : ' — ' + product.name));
      }
      if (thumbs) {
        $$('.gallery__thumb', thumbs).forEach(function (t) {
          var on = Number(t.getAttribute('data-thumb')) === shown;
          t.classList.toggle('is-active', on);
          if (on) { t.setAttribute('aria-current', 'true'); } else { t.removeAttribute('aria-current'); }
        });
      }
      if (countEl) { countEl.textContent = ui.faDigits(shown + 1) + ' / ' + ui.faDigits(list.length); }
      /* The next one is the likely next request; fetch it while this one is
         looked at - once this one has arrived. Started together, the two
         shared a phone's connection and the picture on screen, the largest
         thing on the page and what Google times, came later: 147 KB of the
         next photograph beside the 97 KB of this one. */
      if (many) {
        /* The same copy the main picture will ask for, not the original. */
        var nextSrc = list[galleryStep(shown, 1, list.length)];
        var fetchAhead = function () {
          var ahead = new Image();
          var aheadSet = ui.srcsetOf(nextSrc, product.imgv);
          if (aheadSet) { ahead.sizes = ui.SIZES_MAIN; ahead.srcset = aheadSet; }
          ahead.src = nextSrc;
        };
        if (!mainImg || mainImg.complete) {
          fetchAhead();
        } else {
          var whenShown = function () {
            mainImg.removeEventListener('load', whenShown);
            mainImg.removeEventListener('error', whenShown);
            fetchAhead();
          };
          mainImg.addEventListener('load', whenShown);
          mainImg.addEventListener('error', whenShown);
        }
      }
    }

    if (thumbs) {
      thumbs.innerHTML = gallery.map(function (src, i) {
        return '<button type="button" class="gallery__thumb media media--1x1' + (i === 0 ? ' is-active' : '') + '" ' +
          'data-thumb="' + i + '" aria-label="تصویر ' + (i + 1) + '">' +
          '<img src="' + ui.esc(ui.thumbSrc(src, product.imgv, 160)) + '" alt="' + ui.esc(product.nameFa) + ' — نمای ' + (i + 1) +
          '" width="120" height="120" decoding="async"></button>';
      }).join('');
      thumbs.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-thumb]');
        if (btn) { showPicture(Number(btn.getAttribute('data-thumb'))); }
      });
    }

    if (mainBox) {
      $$('[data-gallery-step]', mainBox).forEach(function (b) { b.hidden = !many; });
      /* Shown only now that something answers it - see the note on the
         gallery in product.php. */
      var zoomBtn = $('[data-gallery-open]', mainBox);
      if (zoomBtn) { zoomBtn.hidden = false; }
      mainBox.classList.add('is-zoomable');
      if (countEl) { countEl.hidden = !many; }

      /* A sideways swipe on the photograph steps through the pictures. */
      var swipeX = null;
      var swipeY = 0;
      var swiped = false;
      mainBox.addEventListener('pointerdown', function (e) {
        swiped = false;
        if (e.pointerType === 'mouse') { return; }
        swipeX = e.clientX;
        swipeY = e.clientY;
      });
      mainBox.addEventListener('pointerup', function (e) {
        if (swipeX === null) { return; }
        var dx = e.clientX - swipeX;
        var dy = e.clientY - swipeY;
        swipeX = null;
        if (!many || Math.abs(dx) < 40 || Math.abs(dx) < Math.abs(dy) * 1.2) { return; }
        swiped = true;
        showPicture(shown + swipeStep(dx, isRtl()));
      });
      mainBox.addEventListener('pointercancel', function () { swipeX = null; });

      mainBox.addEventListener('click', function (e) {
        var step = e.target.closest('[data-gallery-step]');
        if (step) { showPicture(shown + Number(step.getAttribute('data-gallery-step'))); return; }
        /* The swipe ended on the photograph; that was not a tap on it. */
        if (swiped) { swiped = false; return; }
        openLightbox(pictures(), shown, product.nameFa, showPicture);
      });
    }
    if (galleryBox && many) {
      galleryBox.addEventListener('keydown', function (e) {
        var d = keyStep(e.key, isRtl());
        if (!d) { return; }
        e.preventDefault();
        showPicture(shown + d);
      });
    }
    showPicture(0);

    /* What the picture goes back to when the chosen flavour has none of its
       own - which is what almost every flavour will be. Captured here rather
       than read from mainImg.src at swap time: by then it may be holding the
       PREVIOUS flavour's picture, and the fallback would drift one flavour
       behind every choice. */
    var defaultImage = gallery[0];
    var choiceShown = defaultImage;

    /* The picture for whatever is selected right now.

       Every handler that changes the selection calls this - size, flavour and
       any axis the owner added. It was called paintFlavorImage while only a
       flavour could change the look, and under that name the size handler was
       never given the call: «وی شکلاتی ۹۰۰ گرمی» and «وی شکلاتی ۱.۸ کیلوگرمی»
       moved the price and kept one photograph between them. */
    function paintChoiceImage() {
      if (!mainImg) { return; }
      /* Most specific first: this exact combination, then the flavour's, then
         the product's own. A tub photographed in red is a different object
         from the same tub in blue; a flavour photographed once covers every
         size of it. */
      var v = currentVariant();
      var src = (v && v.image)
        || (selected.flavor && selected.flavor.image)
        || defaultImage;
      /* Only when the selection's picture actually changes. A new size of the
         same flavour is the same photograph, and a shopper who had stepped to
         the nutrition panel is left looking at it. */
      if (!src || src === choiceShown) { return; }
      choiceShown = src;
      var at = gallery.indexOf(src);
      if (at > 0) {
        /* One of the product's own photographs: step to it where it is. */
        setLead(null);
        showPicture(at);
      } else {
        setLead(src === defaultImage ? null : src);
        showPicture(0);
      }
    }

    var flagWrap = $('[data-p-flags]');
    if (flagWrap) {
      var html = '';
      if ((product.badges || []).indexOf('bestseller') !== -1) { html += '<span class="badge badge--light">پرفروش</span>'; }
      if ((product.badges || []).indexOf('new') !== -1) { html += '<span class="badge badge--light">جدید</span>'; }
      flagWrap.innerHTML = '<div class="row" style="gap:6px">' + html + '</div>' +
        (product.onSale ? '<span class="badge badge--accent num">\u2212' + ui.faDigits(product.discountPercent) + '\u066A</span>' : '');
    }

    /* --- options --- */
    var flavorWrap = $('[data-p-flavors]');
    if (flavorWrap) {
      if (!flavors.length) {
        flavorWrap.closest('.pdp__field').hidden = true;
      } else {
        flavorWrap.innerHTML = flavors.map(function (f, i) {
          /* The chip used to carry «+160,000» when the flavour cost more.
             It read as a surcharge for having a preference, which is the
             wrong thing to make somebody feel one click before they buy -
             and it is no longer even how the price is stored: a product
             with combinations holds one number per real thing, not a base
             and a premium to add up.

             Nothing is hidden by dropping it. The headline repaints on
             every chip click - paintPrice() is wired to this handler - so
             the shopper reads the whole price for the thing they picked,
             which is the figure they will transfer card to card. */
          return '<button type="button" class="chip' + (i === 0 ? ' is-active' : '') + '" data-flavor="' +
            ui.esc(f.id) + '">' + ui.esc(f.name) + '</button>';
        }).join('');
        /* The first chip is drawn active, so the picture has to match it
           before anybody clicks anything. */
        paintChoiceImage();
        flavorWrap.addEventListener('click', function (e) {
          var btn = e.target.closest('[data-flavor]');
          if (!btn) { return; }
          $$('.chip', flavorWrap).forEach(function (c) { c.classList.remove('is-active'); });
          btn.classList.add('is-active');
          selected.flavor = flavors.filter(function (f) { return f.id === btn.getAttribute('data-flavor'); })[0];
          /* The size handler has always repainted; this one never did, so a
             premium flavour left the headline price unchanged and the shopper
             first saw the real figure in the cart. */
          paintPrice();
          paintChoiceImage();
        });
      }
    }

    /* One chip row per axis the owner added. Built here rather than in the
       page because how many there are is a per-product answer. */
    var attrWrap = $('[data-p-attrs]');
    if (attrWrap && extraAttrs.length) {
      var attrField = $('[data-p-attrs-field]');
      if (attrField) { attrField.hidden = false; }

      attrWrap.innerHTML = extraAttrs.map(function (a) {
        return '<div class="pdp__attr">' +
          '<span class="pdp__field-label">' + ui.esc(a.name) + '</span>' +
          '<div class="row" style="gap:8px" data-attr-row="' + ui.esc(a.id) + '">' +
            (a.values || []).map(function (v, i) {
              return '<button type="button" class="chip' + (i === 0 ? ' is-active' : '') +
                '" data-attr-value="' + ui.esc(v.id) + '">' + ui.esc(v.label) + '</button>';
            }).join('') +
          '</div>' +
        '</div>';
      }).join('');

      attrWrap.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-attr-value]');
        if (!btn) { return; }
        var row = btn.closest('[data-attr-row]');
        if (!row) { return; }
        $$('.chip', row).forEach(function (c) { c.classList.remove('is-active'); });
        btn.classList.add('is-active');
        selected.attrs[row.getAttribute('data-attr-row')] =
          btn.getAttribute('data-attr-value');
        /* All three, because a combination carries its own price, its own
           picture and its own count - and the shopper just changed which
           combination they are looking at. */
        paintPrice();
        paintChoiceImage();
        paintAvailability();
      });
    }

    var sizeWrap = $('[data-p-sizes]');
    if (sizeWrap) {
      if (sizes.length < 2) {
        sizeWrap.closest('.pdp__field').hidden = true;
      } else {
        sizeWrap.innerHTML = sizes.map(function (s) {
          var out = s.stock === 0;
          /* «۸۵,۰۰۰ در هر وعده» used to sit here when a size knew how many
             servings it held. It is a real argument for the bigger tub -
             cheaper per scoop - but the owner does not want it on the page,
             and it is the owner's shop.

             The stock warnings keep the slot. «تمام شد» and «۳ عدد مانده»
             change what somebody does next; a unit price does not. */
          var meta = out
            ? '<span class="opt__meta">تمام شد</span>'
            : (typeof s.stock === 'number'
                ? '<span class="opt__meta"><span class="num">' + ui.faDigits(s.stock) + '</span> عدد مانده</span>'
                : '');
          return '<button type="button" class="opt' +
              (s === firstSellable ? ' is-active' : '') + (out ? ' is-out' : '') +
              '" data-size="' + ui.esc(s.id) + '"' + (out ? ' disabled' : '') + '>' +
            '<span class="opt__label">' + ui.esc(s.label) + '</span>' +
            meta +
            /* The unit was never here, which read oddly beside a headline
               price that has one - and the owner's own mock-up shows a price
               as «۳,۲۹۰,۰۰۰ تومان». */
            '<span class="opt__price num">' + ui.money(s.price) + ' ' +
              ui.unitHtml(cfg.currency.label) + '</span>' +
          '</button>';
        }).join('');
        sizeWrap.addEventListener('click', function (e) {
          var btn = e.target.closest('[data-size]');
          if (!btn || btn.disabled) { return; }
          $$('.opt', sizeWrap).forEach(function (o) { o.classList.remove('is-active'); });
          btn.classList.add('is-active');
          selected.size = sizes.filter(function (s) { return s.id === btn.getAttribute('data-size'); })[0];
          paintPrice();
          paintChoiceImage();
          paintAvailability();
        });
      }
    }

    /* --- price, quantity, add to cart --- */

    /* The current choice in words - «شکلاتی · ۹۰۰ گرم»: the basket line's
       label, and on the phone's buy bar the reminder of what it adds. */
    function choiceParts() {
      var parts = [];
      if (selected.flavor) { parts.push(selected.flavor.name); }
      if (selected.size && selected.size.label !== '—') { parts.push(selected.size.label); }
      /* Every axis the shopper chose on, so the picking slip says what to
         put in the box rather than most of it. */
      extraAttrs.forEach(function (a) {
        var chosen = selected.attrs[a.id];
        if (!chosen) { return; }
        var v = (a.values || []).filter(function (x) { return x.id === chosen; })[0];
        if (v) { parts.push(v.label); }
      });
      return parts;
    }

    function paintPrice() {
      var s = selected.size;
      /* The same sum addToCart() and order_price_line() compute, so the three
         cannot disagree. */
      var extra = (selected.flavor && selected.flavor.priceDelta) || 0;
      var shown = s.price + extra;
      var compare = s.compareAt;

      /* Once a product has combinations, the combination is the price. There
         is no base and premium left to add up - the grid in the panel holds
         one number per real thing, and that is the number the server will
         charge. */
      var v = currentVariant();
      if (v) {
        shown   = v.price;
        compare = v.compareAt;
      }
      compare = beforePrice(product, shown, compare);
      var onSale = cfg.flags.saleMode && compare && compare > shown;
      var off = onSale ? Math.round((1 - shown / compare) * 100) : 0;
      set('[data-p-price]',
        /* Isolate the digits, not the price. See priceHtml() in ui.js. */
        '<span class="price__now"><span class="num">' + ui.money(shown) +
        '</span>\u00a0' + ui.unitHtml(cfg.currency.label) + '</span>' +
        (onSale ? '<span class="price__was num">' + ui.money(compare) + '</span>' : '') +
        /* «ارسال رایگان بالای ۱,۵۰۰,۰۰۰» stood here, and «شامل مالیات» before
           it. The tax line went because nothing computes or remits a tax;
           the shipping line went because the owner wanted this place for
           the discount instead - the threshold is still in the bar at the
           top of every page and in «ارسال و مرجوعی» below. What stands here
           now is the struck price and how much it saves, and only when
           there is a real saving to show. */
        (off > 0
          ? '<span class="badge badge--accent num price__off">\u2212' +
              ui.faDigits(off) + '\u066A</span>'
          : ''));
      /* The phone's buy bar says the same: what this choice costs, and what
         it is. */
      set('[data-buybar-price]',
        '<span class="price__now"><span class="num">' + ui.money(shown) +
        '</span>\u00a0' + ui.unitHtml(cfg.currency.label) + '</span>' +
        (onSale ? '<span class="price__was num">' + ui.money(compare) + '</span>' : ''));
      set('[data-buybar-choice]', ui.esc(choiceParts().join(' · ')));
    }
    paintPrice();

    var qtyVal = $('[data-qty-val]');
    function paintQty() { if (qtyVal) { qtyVal.textContent = selected.qty; } }
    paintQty();

    /* The ceiling on the stepper. Twenty is the shop's own limit; a counted
       size lowers it to what exists, so the page cannot offer to sell three of
       something there are two of and then refuse at checkout. */
    function qtyCeiling() {
      var v = currentVariant();
      /* A combination's own count wins: it is what the server will reserve
         against, so offering three of something it holds two of would be a
         refusal at checkout after the address has been typed. */
      var left = v ? v.stock : (selected.size ? selected.size.stock : null);
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
      var barBtn = $('[data-buybar-add]');
      if (barBtn) {
        barBtn.disabled    = sold;
        barBtn.textContent = sold ? 'ناموجود' : 'افزودن به سبد';
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

    /* The bar at the foot of a phone's screen (product.php): shown once the
       buy button has scrolled up out of sight, and never over the bar that
       leads back to an unpaid order - that one matters more. Its button
       presses the real one, so there is one set of rules for what goes into
       the basket. On a desktop the CSS keeps it away; the buy box stays
       beside the gallery there. */
    var buybar = $('[data-buybar]');
    if (buybar && buyBtn) {
      $('[data-buybar-add]', buybar).addEventListener('click', function () { buyBtn.click(); });
      /* Read on scroll, once a frame, rather than by an IntersectionObserver:
         that reports a crossing, and a fling or a jump from above the button
         to far below it crosses nothing, so the bar never came. */
      var buybarQueued = false;
      var paintBuybar = function () {
        buybarQueued = false;
        var show = buyBtn.getBoundingClientRect().bottom < 0
          && !document.querySelector('[data-resume-bar]');
        if (buybar.hidden === show) {
          buybar.hidden = !show;
          document.body.classList.toggle('has-buybar', show);
        }
      };
      var queueBuybar = function () {
        if (!buybarQueued) {
          buybarQueued = true;
          window.requestAnimationFrame(paintBuybar);
        }
      };
      window.addEventListener('scroll', queueBuybar, { passive: true });
      window.addEventListener('resize', queueBuybar);
      paintBuybar();
    }

    if (buyBtn) {
      buyBtn.addEventListener('click', function () {
        /* Guarded rather than left unbound, because the handler now outlives
           any single availability state - the shopper can switch to a sold-out
           size and back. */
        if (buyBtn.disabled) { return; }
        var parts = choiceParts();

        var buyVariant = currentVariant();
        addToCart(product, {
          /* The combination's own price when there is one - there is no base
             and premium left to add. Otherwise the flavour premium is part of
             the price, not a note on it. */
          price: buyVariant
            ? buyVariant.price
            : selected.size.price + ((selected.flavor && selected.flavor.priceDelta) || 0),
          variantLabel: parts.join(' · '),
          flavorId: selected.flavor ? selected.flavor.id : null,
          sizeId: selected.size.id,
          /* Copied, not passed by reference: the shopper can carry on
             clicking chips after adding, and the line already in the basket
             must not change under them. */
          attrs: Object.keys(selected.attrs).length
            ? JSON.parse(JSON.stringify(selected.attrs)) : null,
          qty: selected.qty,
        });
        setLayer('cart');
      });
    }

    /* --- accordion content --- */
    var nutri = $('[data-p-nutrition]');
    if (nutri) {
      /* On the ROWS, not on the object. lib/products.php always sets
         nutrition - it is an array with a servingSize and a rows list, and
         an empty one is still an array - so `if (product.nutrition)` was
         true for every product ever made, and the «هنوز ثبت نشده» branch
         below had never once run. What a shopper got instead was a table
         whose only content was the word «مقدار» above nothing, in a panel
         that opens by itself. Every active product on the live site is in
         that state today. */
      var nRows = (product.nutrition && product.nutrition.rows) || [];
      if (nRows.length) {
        nutri.innerHTML = '<table class="spec"><caption class="sr-only">جدول ارزش غذایی</caption><tbody>' +
          /* The serving size can be blank on a product whose rows are
             filled in, and an empty <th> leaves a stray rule across the
             top of the table. */
          (product.nutrition.servingSize
            ? '<tr><th>' + ui.esc(product.nutrition.servingSize) + '</th><td>مقدار</td></tr>'
            : '') +
          nRows.map(function (r) {
            /* No .num here. It sets direction:ltr on the cell, which flipped
               `text-align: end` for the numeric rows and not for the «مقدار»
               header, so one column read from two edges. A value like «۲.۵
               گرم» is mostly Persian anyway; the tabular figures come from
               .spec td in components.css instead. */
            return '<tr><th>' + ui.esc(r.label) + '</th><td>' + ui.esc(r.value) + '</td></tr>';
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
    /* Exposed for tests/test-checkout.mjs, not for pages. */
    mergeRemoteConfig: mergeRemoteConfig,
    cartBodyKeepsPanel: cartBodyKeepsPanel,
    pickHeroDeals: pickHeroDeals,
    beforePrice: beforePrice,
    dealClock: dealClock,
    HERO_DEALS_MS: HERO_DEALS_MS,
    galleryStep: galleryStep,
    swipeStep: swipeStep,
    keyStep: keyStep,
    zoomAbout: zoomAbout,
    clampPan: clampPan,
    myOrders: myOrders,
    rememberMyOrder: rememberMyOrder,
    forgetMyOrder: forgetMyOrder,
    cardAddPlan: cardAddPlan,
    VALIDATORS: VALIDATORS,
    normaliseMobile: normaliseMobile,
    init: function () {
      initChrome();
      initNavMenus();
      initContact();
      initHome();
      initPhotoHandoff();
      initSpotlight();
      initHeroDeals();
      initHomeBrands();
      initCategoryPage();
      initGoalPage();
      initBrandPage();
      initProduct();
      /* Every page, not only the homepage: the point of remembering the code is
         that the way back to an order is there wherever the customer lands. */
      initLastOrder();
      initMyOrders();
      initReceipts(document);
      initOrderWatch();
      /* track.php renders its own hold countdown server-side; the drawer's is
         started by showPaymentStep. This picks up whichever is on the page. */
      startHoldCountdown(document);
      observeReveals(document);
      if (SUPPEX.hero3d) { SUPPEX.hero3d.init(); }
    },
  };
})();

document.addEventListener('DOMContentLoaded', function () { SUPPEX.app.init(); });
