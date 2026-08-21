/* ==========================================================================
   SUPPEX — UI layer
   --------------------------------------------------------------------------
   Pure functions: data in, HTML string out. No component here reaches into
   the store, the repository or the DOM. Keeping them pure is what makes them
   reusable when these views are ported to a template engine or a framework.
   ========================================================================== */
window.SUPPEX = window.SUPPEX || {};

SUPPEX.ui = (function () {
  'use strict';

  var cfg = SUPPEX.config;

  /* --- helpers ---------------------------------------------------------- */

  function esc(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function money(value) {
    return cfg.currency.format(value);
  }

  function priceHtml(product, opts) {
    opts = opts || {};
    var cls = 'price' + (opts.large ? ' price--lg' : '');
    var html = '<div class="' + cls + '">';
    html += '<span class="price__now num">' + money(product.price) +
            ' <span class="price__unit">' + esc(cfg.currency.label) + '</span></span>';
    if (product.onSale) {
      html += '<span class="price__was num">' + money(product.compareAt) + '</span>';
    }
    html += '</div>';
    return html;
  }

  /* --- icons -----------------------------------------------------------
     One inline sprite factory. Inline SVG keeps the prototype dependency-free
     and lets icons inherit currentColor. */

  var PATHS = {
    search: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6"/>',
    cart: '<path d="M3 4h2l2.6 11.2a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 2-1.5L21 8H6"/><circle cx="10" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    minus: '<path d="M5 12h14"/>',
    close: '<path d="M6 6l12 12M18 6L6 18"/>',
    menu: '<path d="M3 6h18M3 12h18M3 18h18"/>',
    arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
    check: '<path d="M20 6L9 17l-5-5"/>',
    shield: '<path d="M12 3l7 3v6c0 4.5-3 8-7 9-4-1-7-4.5-7-9V6l7-3z"/><path d="M9.5 12l1.8 1.8L15 10"/>',
    truck: '<path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.6"/><circle cx="17.5" cy="18" r="1.6"/>',
    lab: '<path d="M10 3v6.2L4.6 18A2 2 0 0 0 6.3 21h11.4a2 2 0 0 0 1.7-3L14 9.2V3"/><path d="M9 3h6M7.5 15h9"/>',
    headset: '<path d="M4 13v-1a8 8 0 0 1 16 0v1"/><path d="M4 13h2.5v6H5a1 1 0 0 1-1-1zM20 13h-2.5v6H19a1 1 0 0 0 1-1z"/><path d="M17.5 19v.5a2.5 2.5 0 0 1-2.5 2.5h-2"/>',
    star: '<path d="M12 2.6l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5-5.8-3-5.8 3 1.1-6.5L2.6 9.4l6.5-.9z" fill="currentColor" stroke="none"/>',
    instagram: '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r="1.1" fill="currentColor" stroke="none"/>',
    telegram: '<path d="M21 4L3 11l5 1.8L19 6.5l-8.4 8.1.4 5 2.8-3.4L18.5 19z" fill="currentColor" stroke="none"/>',
    whatsapp: '<path d="M4 20l1.2-3.8A8 8 0 1 1 8.6 19z"/><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5"/>',
    play: '<rect x="3" y="5" width="18" height="14" rx="4"/><path d="M11 9.8l4 2.2-4 2.2z" fill="currentColor" stroke="none"/>',
    box: '<path d="M12 3l8 4v10l-8 4-8-4V7z"/><path d="M4 7l8 4 8-4M12 11v10"/>',
    chat: '<path d="M20 12a8 8 0 0 1-11.6 7.1L4 20l1-4.2A8 8 0 1 1 20 12z"/>',
    copy: '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
  };

  /* width/height are emitted as presentation attributes, not CSS. An inline
     <svg> that carries only a viewBox has no intrinsic size, so a call site
     without a matching CSS rule would fall back to the replaced-element
     default of 300x150 and blow out its container. 1em makes the icon track
     the surrounding text by default, and because presentation attributes lose
     to any CSS selector, every component that already sizes its icons keeps
     winning. */
  function icon(name, extraClass) {
    var body = PATHS[name];
    if (!body) { return ''; }
    return '<svg class="' + (extraClass || '') + '" viewBox="0 0 24 24" ' +
           'width="1em" height="1em" fill="none" ' +
           'stroke="currentColor" stroke-width="1.7" stroke-linecap="round" ' +
           'stroke-linejoin="round" aria-hidden="true" focusable="false">' + body + '</svg>';
  }

  function stars(rating) {
    var full = Math.round(rating);
    var out = '<span class="rating__stars" aria-hidden="true">';
    for (var i = 0; i < 5; i++) {
      out += '<span style="opacity:' + (i < full ? '1' : '0.25') + '">' + icon('star') + '</span>';
    }
    return out + '</span>';
  }

  function ratingHtml(product) {
    /* A shop with no orders yet has no ratings to show; inventing them is the
       one placeholder that damages trust rather than standing in for it. */
    if (!cfg.flags.showRatings) { return ''; }
    return '<div class="rating">' + stars(product.rating) +
      '<span class="num">' + product.rating + '</span>' +
      '<span class="u-dim">(<span class="num">' + product.reviewCount + '</span>)</span>' +
      '</div>';
  }

  var BADGE_TEXT = { bestseller: 'پرفروش', new: 'جدید' };

  /* --- components ------------------------------------------------------- */

  function productCard(product) {
    var href = 'product.html?p=' + encodeURIComponent(product.slug);
    var flags = '';

    var left = '';
    (product.badges || []).forEach(function (b) {
      if (BADGE_TEXT[b]) { left += '<span class="badge badge--light">' + esc(BADGE_TEXT[b]) + '</span>'; }
    });
    if (!product.inStock) { left += '<span class="badge badge--outline">ناموجود</span>'; }

    var right = product.onSale
      ? '<span class="badge badge--accent num">' + product.discountPercent + '%-</span>'
      : '';

    if (left || right) {
      flags = '<div class="pcard__flags"><div class="row" style="gap:6px">' + left + '</div>' + right + '</div>';
    }

    return '' +
      '<article class="pcard reveal">' +
        '<div class="pcard__media">' +
          '<a href="' + href + '" class="media media--1x1 media--lit" aria-label="' + esc(product.nameFa) + '">' +
            '<img src="' + esc(product.image) + '" alt="' + esc(product.nameFa + ' — ' + product.name) + '" loading="lazy" width="400" height="400">' +
          '</a>' +
          flags +
        '</div>' +
        '<div class="pcard__body">' +
          '<div class="pcard__brand lat">' + esc(product.brand) + '</div>' +
          '<h3 class="pcard__title"><a href="' + href + '">' + esc(product.nameFa) +
            ' <span class="lat u-dim" style="font-size:.85em">' + esc(product.name) + '</span></a></h3>' +
          '<div class="pcard__variant">' + esc(product.variantLabel || '') + '</div>' +
          ratingHtml(product) +
          '<div class="pcard__foot">' +
            priceHtml(product) +
            '<button class="add-btn" type="button" data-add="' + esc(product.slug) + '" ' +
              'aria-label="افزودن ' + esc(product.nameFa) + ' به سبد خرید"' +
              (product.inStock ? '' : ' disabled') + '>' +
              icon('plus') +
            '</button>' +
          '</div>' +
        '</div>' +
      '</article>';
  }

  function categoryCard(cat) {
    return '' +
      '<a class="ccard reveal" href="#featured" data-category="' + esc(cat.id) + '">' +
        '<span class="media media--4x5" style="border-radius:0;display:block">' +
          '<img src="' + esc(cat.image) + '" alt="دسته‌بندی ' + esc(cat.name) + '" loading="lazy" width="320" height="400">' +
        '</span>' +
        '<span class="ccard__label">' +
          '<span>' +
            '<span class="ccard__name">' + esc(cat.name) + '</span><br>' +
            '<span class="ccard__count num">' + cat.count + ' محصول</span>' +
          '</span>' +
          '<span class="ccard__go">' + icon('arrow') + '</span>' +
        '</span>' +
      '</a>';
  }

  function reviewCard(review) {
    return '' +
      '<figure class="review reveal">' +
        stars(review.rating) +
        '<blockquote class="review__text">' + esc(review.text) + '</blockquote>' +
        '<figcaption class="review__who">' + esc(review.author) +
          (review.verified ? ' <span class="u-dim" style="font-weight:600">· خریدار تأییدشده</span>' : '') +
        '</figcaption>' +
      '</figure>';
  }

  function journalCard(post) {
    /* Block-level children throughout: an <a> may wrap flow content, but a
       <span> may not contain a heading. */
    return '' +
      '<a class="pcard reveal" href="#">' +
        '<div class="media media--16x10">' +
          '<img src="' + esc(post.image) + '" alt="" loading="lazy" width="480" height="300">' +
        '</div>' +
        '<div class="pcard__body">' +
          '<div class="row" style="gap:8px">' +
            '<span class="u-eyebrow u-eyebrow--bare">' + esc(post.category) + '</span>' +
            '<span class="u-xs u-dim"><span class="num">' + post.readTime + '</span> دقیقه مطالعه</span>' +
          '</div>' +
          '<h3 class="u-h3" style="font-size:1.15rem;line-height:1.5">' + esc(post.title) + '</h3>' +
        '</div>' +
      '</a>';
  }

  function lineItem(item) {
    return '' +
      '<div class="line-item" data-key="' + esc(item.key) + '">' +
        '<span class="line-item__media media media--1x1">' +
          '<img src="' + esc(item.image) + '" alt="" width="74" height="74">' +
        '</span>' +
        '<div class="line-item__info">' +
          '<div class="line-item__title">' + esc(item.nameFa) + '</div>' +
          '<div class="u-xs u-dim">' + esc(item.variantLabel || '') + '</div>' +
          '<div class="line-item__ctl">' +
            '<span class="stepper-mini">' +
              '<button type="button" data-qty="-1" aria-label="کاهش تعداد">' + icon('minus') + '</button>' +
              '<span class="num">' + item.qty + '</span>' +
              '<button type="button" data-qty="1" aria-label="افزایش تعداد">' + icon('plus') + '</button>' +
            '</span>' +
            '<span class="u-sm" style="font-weight:800">' +
              '<span class="num">' + money(item.lineTotal) + '</span> ' + esc(cfg.currency.label) +
            '</span>' +
          '</div>' +
        '</div>' +
        '<button class="icon-btn" type="button" data-remove style="width:32px;height:32px;flex-shrink:0" ' +
          'aria-label="حذف ' + esc(item.nameFa) + '">' + icon('close') + '</button>' +
      '</div>';
  }

  /* The delivery details, asked once before the order goes out instead of
     being chased through the chat afterwards. Values are pre-filled from the
     last order so a repeat customer only has to press send. */
  function customerForm(customer) {
    customer = customer || {};
    var rows = (cfg.customerFields || []).map(function (f) {
      var val = esc(customer[f.id] || '');
      var id = 'cust-' + f.id;
      var attrs =
        ' id="' + id + '" name="' + esc(f.id) + '" data-field="' + esc(f.id) + '"' +
        (f.autocomplete ? ' autocomplete="' + esc(f.autocomplete) + '"' : '') +
        (f.placeholder ? ' placeholder="' + esc(f.placeholder) + '"' : '') +
        (f.required ? ' required' : '');

      var control = f.type === 'textarea'
        ? '<textarea class="input form__control"' + attrs + ' rows="' + (f.rows || 3) + '">' + val + '</textarea>'
        : '<input class="input form__control" type="' + esc(f.type || 'text') + '"' + attrs + ' value="' + val + '">';

      return '<div class="form__row" data-row="' + esc(f.id) + '">' +
        '<label class="form__label" for="' + id + '">' + esc(f.label) +
          (f.required ? '<span class="form__req">*</span>' : '') + '</label>' +
        control +
        '<span class="form__error" data-error></span>' +
      '</div>';
    }).join('');

    return '<div class="form" data-customer-form>' +
      '<p class="form__intro">برای هماهنگی ارسال، این اطلاعات لازم است. یک بار وارد کنید — دفعه بعد پر شده است.</p>' +
      rows +
    '</div>';
  }

  var CHANNEL_ICON = {
    telegram: 'telegram',
    whatsapp: 'whatsapp',
    bale: 'chat',
    eitaa: 'chat',
  };

  /* One button per enabled channel. The first is the primary; the rest are
     ghosts, so the drawer still has a single obvious next step. */
  function channelButtons(channels) {
    return channels.map(function (c, i) {
      return '<button class="btn ' + (i === 0 ? 'btn--primary' : 'btn--ghost') + ' btn--block" ' +
        'type="button" data-channel="' + esc(c.id) + '">' +
        icon(CHANNEL_ICON[c.id] || 'chat') +
        '<span>ثبت سفارش در ' + esc(c.label) + '</span>' +
      '</button>';
    }).join('');
  }

  /* Shown in the drawer the moment an order is handed to Telegram, so the
     shopper has the amount and the card number in front of them without the
     seller having to type anything. */
  function paymentPanel(snapshot, channel) {
    var p = cfg.payment;
    var appName = (channel && channel.label) || 'همان گفتگو';
    var digits = String(p.cardNumber || '').replace(/\D/g, '');
    var grouped = digits.replace(/(\d{4})(?=\d)/g, '$1 ');

    return '' +
      '<div class="pay">' +
        '<div class="pay__done">' + icon('check') +
          '<span>سفارش شما آماده ارسال در ' + esc(appName) + ' است</span></div>' +

        /* Apps without URL prefill got the order via the clipboard. A toast
           vanishes; this has to stay on screen until they have pasted it. */
        (channel && !channel.prefillParam
          ? '<div class="pay__paste">' + icon('copy') +
            '<span>متن سفارش کپی شد — در گفتگوی ' + esc(appName) +
            ' آن را <strong>پیست کنید و بفرستید</strong>.</span></div>'
          : '') +

        '<div class="pay__amount">' +
          '<span class="u-sm u-muted">مبلغ قابل پرداخت</span>' +
          '<strong><span class="num">' + money(snapshot.total) + '</span> ' + esc(cfg.currency.label) + '</strong>' +
        '</div>' +

        '<div class="pay__card">' +
          '<div class="pay__card-head">' +
            '<span class="u-eyebrow u-eyebrow--bare">' + esc(p.bankName || '') + '</span>' +
            '<button class="pay__copy" type="button" data-copy-card="' + esc(digits) + '">کپی شماره</button>' +
          '</div>' +
          '<div class="pay__number num" dir="ltr">' + esc(grouped) + '</div>' +
          '<div class="pay__holder">به نام ' + esc(p.cardHolder || '—') + '</div>' +
        '</div>' +

        '<p class="pay__note">' + esc(String(p.note || '').replace('{channel}', appName)) + '</p>' +
      '</div>';
  }

  function searchRow(product) {
    return '' +
      '<a class="search__row" href="product.html?p=' + encodeURIComponent(product.slug) + '">' +
        '<span class="media media--1x1"><img src="' + esc(product.image) + '" alt="" width="52" height="52"></span>' +
        '<span style="flex:1;min-width:0">' +
          '<span style="font-weight:800;font-size:.9rem;display:block">' + esc(product.nameFa) + '</span>' +
          '<span class="u-xs u-dim lat">' + esc(product.name) + '</span>' +
        '</span>' +
        '<span class="u-sm" style="font-weight:800"><span class="num">' + money(product.price) + '</span></span>' +
      '</a>';
  }

  return {
    esc: esc,
    money: money,
    icon: icon,
    stars: stars,
    ratingHtml: ratingHtml,
    priceHtml: priceHtml,
    productCard: productCard,
    categoryCard: categoryCard,
    reviewCard: reviewCard,
    journalCard: journalCard,
    lineItem: lineItem,
    customerForm: customerForm,
    channelButtons: channelButtons,
    paymentPanel: paymentPanel,
    searchRow: searchRow,
  };
})();
