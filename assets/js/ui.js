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

  /* The currency label, split for the stacked treatment in components.css:
     last letter on top, the rest below. Split HERE rather than in the CSS
     so it follows cfg.currency.label - «ریال» would give «ل» over «ریا»
     without anybody editing a stylesheet.

     The whole word stays in the element as clipped text: that is what gets
     copied and what a screen reader reads. */
  function unitHtml(label) {
    var s = String(label == null ? '' : label);
    var head = s.slice(-1);
    var tail = s.slice(0, -1);
    return '<span class="price__unit" data-head="' + esc(head) +
           '" data-tail="' + esc(tail) + '"><span class="sr-only">' +
           esc(s) + '</span></span>';
  }

  /* Digits only, for the figures that are not money and so do not go through
     the currency formatter - the discount badge is the one that matters. A
     badge reading «−40%» hard against a price reading «۳,۲۹۰,۰۰۰» looks like
     a bug rather than a choice. */
  function faDigits(value) {
    return String(value).replace(/[0-9]/g, function (d) {
      return String.fromCharCode(0x06F0 + (d.charCodeAt(0) - 48));
    });
  }

  /* A product can exist before anyone has uploaded a photo for it — the admin
     panel deliberately allows that, so the shop owner can enter names and
     prices first and add pictures later. An empty src renders as a broken-image
     icon, which looks like a bug rather than a gap, so an inert neutral tile
     stands in until the real photo arrives. Inline SVG, so it costs no request
     and cannot itself 404. */
  /* A tub with the shop's mark, in the card's own tones - the same file
     lib/products.php draws (PRODUCT_PLACEHOLDER). */
  var IMAGE_PLACEHOLDER = 'assets/images/product-placeholder.svg';

  function imgSrc(value) {
    var src = String(value == null ? '' : value).trim();
    return src === '' ? IMAGE_PLACEHOLDER : esc(src);
  }

  /* Lighter copies of an uploaded picture (lib/images.php). A product
     carries imgv: for each of its pictures, the widths it has WebP copies at,
     «x.jpg» at 320 being «x-w320.webp». A card hands the browser the set and
     lets it choose; a thumbnail asks for the smallest copy that is sharp at
     its size. A picture with no copies - a bundled .svg, an upload not yet
     processed - is used as it is, exactly as before. */
  var SIZES_CARD = '(min-width: 1100px) 270px, (min-width: 700px) 31vw, 47vw';
  /* A category tile's photograph; index.php writes the same. */
  var SIZES_CAT = '(min-width: 980px) 210px, (min-width: 700px) 30vw, 46vw';
  var SIZES_DEAL = '(min-width: 1024px) 480px, 88vw';
  /* The product photograph. product.php writes the same sizes into the
     server's <img>, so the script taking over does not fetch it again. */
  var SIZES_MAIN = '(min-width: 1024px) 560px, 100vw';

  function variantUrl(src, w) {
    return String(src).replace(/\.(jpe?g|png|webp)$/i, '-w' + w + '.webp');
  }

  function srcsetOf(src, imgv) {
    var ws = imgv && src ? imgv[src] : null;
    if (!ws || !ws.length) { return ''; }
    return ws.map(function (w) { return variantUrl(src, w) + ' ' + w + 'w'; }).join(', ');
  }

  function srcsetAttr(src, imgv, sizes) {
    var set = srcsetOf(src, imgv);
    return set ? ' srcset="' + esc(set) + '" sizes="' + esc(sizes) + '"' : '';
  }

  function thumbSrc(src, imgv, min) {
    var ws = imgv && src ? imgv[src] : null;
    if (ws) {
      for (var i = 0; i < ws.length; i++) {
        if (ws[i] >= min) { return variantUrl(src, ws[i]); }
      }
    }
    return src;
  }

  function priceHtml(product, opts) {
    opts = opts || {};
    var cls = 'price' + (opts.large ? ' price--lg' : '');
    var html = '<div class="' + cls + '">';
    /* The isolate goes on the digits, not on the whole price. With .num on
       the outer span the run lays out LTR and تومان lands to the RIGHT of the
       number - the first thing a Persian reader meets. The space is
       non-breaking because it was the only break opportunity in the string,
       which is how the price came to stack across three lines at 375px. */
    html += '<span class="price__now"><span class="num">' + money(product.price) +
            '</span>\u00a0' + unitHtml(cfg.currency.label) + '</span>';
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
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.2 1.9"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 7.6v.6"/>',
    copy: '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
    /* Named for the way they point, not for what they mean: which of them is
       «next» depends on the reading direction, and that is the caller's call. */
    chevR: '<path d="M9 6l6 6-6 6"/>',
    chevL: '<path d="M15 6l-6 6 6 6"/>',
    zoom: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5M11 8v6M8 11h6"/>',
    upload: '<path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/>',
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
    /* Only real ratings: approved reviews by buyers (lib/reviews.php), and
       nothing at all for a product without one. Inventing them is the one
       placeholder that damages trust rather than standing in for it. */
    if (!cfg.flags.showRatings || !product || !(product.reviewCount > 0)) { return ''; }
    return '<div class="rating">' + stars(product.rating) +
      '<span class="num">' + faDigits(product.rating) + '</span>' +
      '<span class="u-dim">(<span class="num">' + faDigits(product.reviewCount) + '</span>)</span>' +
      '</div>';
  }

  var BADGE_TEXT = { bestseller: 'پرفروش', new: 'جدید' };

  /* --- components ------------------------------------------------------- */

  /* The three or four facts worth showing before a click.

     Drawn from `features`, which the catalogue already writes as the headline
     spec per product, plus what is actually being bought - the sizes, the
     serving count, the flavours. Nothing is invented: a product with no
     features shows only what it has, and one with nothing at all gets no
     panel rather than an empty box. */
  /* Three items, no more: the panel is anchored to the foot of the image, so
     anything over that budget grows upwards and prints over the badges. */
  var SPEC_MAX_ROWS = 3;
  /* And no row may run past four values, or one six-flavour product wraps to a
     third line and spends the slack the cap just bought. */
  var SPEC_MAX_VALUES = 4;

  function specList(values) {
    var rest = values.length - SPEC_MAX_VALUES;
    if (rest <= 0) { return values.join(' · '); }
    return values.slice(0, SPEC_MAX_VALUES).join(' · ') + ' +' + rest;
  }

  /* The one line a card shows under the product's name: the first of its
     features that says something about the product rather than repeating its
     brand - «۲۵ گرم پروتئین در هر سروینگ». The catalogue writes features
     headline-first (see quickSpec() below). A leading «حاوی» goes, and the
     digits are Persian like the price under it. product_key_fact() in
     lib/products.php is the same rule, for the cards the server draws;
     tests/key-facts.json holds the two to one table. */
  function keyFact(product) {
    var list = (product && product.features) || [];
    for (var i = 0; i < list.length; i++) {
      var f = list[i];
      if (typeof f !== 'string') { continue; }
      f = f.replace(/[\s\u00a0]+/g, ' ').trim();
      if (!f || /^برند(?:\s|:|$)/.test(f)) { continue; }
      return faDigits(f.replace(/^حاوی\s+/, ''));
    }
    return '';
  }

  function quickSpec(product) {
    var rows = [];

    /* Sizes when the product has several - that is the question a shopper
       actually has on a listing page - otherwise the variant line, which
       already reads «وانیل · 900 گرم» or «60 قرص · 60 روز». */
    var sizes = (product.sizes || []).filter(function (s) { return s && s.label; });
    if (sizes.length > 1) {
      /* «حجم‌ها», not «حجم‌های موجود»: the key is nowrap, so on a narrow card
         the longer label took half the row and pushed the sizes themselves
         onto a third line. */
      rows.push({ k: 'حجم‌ها', v: specList(sizes.map(function (s) { return s.label; })) });
    }

    /* Servings only when it is not already in the size line. */
    var serv = sizes.length === 1 && sizes[0].servings ? sizes[0].servings : null;
    if (serv) { rows.push({ k: 'تعداد وعده', v: String(serv) }); }

    var flav = (product.flavors || []).filter(function (f) { return f && f.name; });
    if (flav.length > 1) {
      rows.push({ k: 'طعم‌ها', v: specList(flav.map(function (f) { return f.name; })) });
    }

    /* The rows are the concrete choice, so they take the budget first and the
       features fill what is left. The catalogue writes features headline-first,
       so the one that survives on a crowded card is «۲۴ گرم پروتئین در هر
       پیمانه» - the number the shopper came for. */
    var feats = (product.features || []).filter(function (f) {
      return typeof f === 'string' && f.trim() !== '';
    }).slice(0, Math.max(0, SPEC_MAX_ROWS - rows.length));

    if (!feats.length && !rows.length) { return ''; }

    var html = '<div class="pcard__spec" aria-hidden="true"><div class="pcard__spec-in">';
    if (feats.length) {
      html += '<ul class="spec-list">';
      feats.forEach(function (f) { html += '<li>' + esc(f) + '</li>'; });
      html += '</ul>';
    }
    rows.forEach(function (r) {
      html += '<div class="spec-row"><span class="spec-row__k">' + esc(r.k) + '</span>' +
              '<span class="spec-row__v">' + esc(r.v) + '</span></div>';
    });
    html += '</div></div>';
    return html;
  }

  function productCard(product) {
    var href = (cfg.productPage || 'product.html') + '?p=' + encodeURIComponent(product.slug);
    var flags = '';

    var left = '';
    (product.badges || []).forEach(function (b) {
      if (BADGE_TEXT[b]) { left += '<span class="badge badge--light">' + esc(BADGE_TEXT[b]) + '</span>'; }
    });
    if (!product.inStock) { left += '<span class="badge badge--outline">ناموجود</span>'; }
    /* The server only sends a count when it is low enough to matter, so
       anything that arrives here is worth printing. typeof, because 0 is a
       number the shop means - it just never reaches this line, since a product
       with none left is not inStock. */
    else if (typeof product.stock === 'number' && product.stock > 0) {
      /* Short on purpose. Three badges can share this row on a card that is
         155px wide at the narrow end of the grid, and the number is the part
         that has to survive - «تنها» is decoration. */
      left += '<span class="badge badge--warn"><span class="num">' +
              product.stock + '</span> عدد مانده</span>';
    }

    var right = product.onSale
      ? /* The minus was written last so that RTL reordering would carry it
             to the front - but .num pins this run LTR, so it rendered as
             "18%-". Written in the order it is meant to be read. */
          '<span class="badge badge--accent num">\u2212' + faDigits(product.discountPercent) + '\u066A</span>'
      : '';

    if (left || right) {
      flags = '<div class="pcard__flags"><div class="row" style="gap:6px">' + left + '</div>' + right + '</div>';
    }

    return '' +
      '<article class="pcard reveal">' +
        '<div class="pcard__media">' +
          '<a href="' + href + '" class="media media--1x1 media--lit" aria-label="' + esc(product.nameFa) + '">' +
            '<img src="' + imgSrc(product.image) + '"' + srcsetAttr(product.image, product.imgv, SIZES_CARD) +
              ' alt="' + esc(product.nameFa + ' — ' + product.name) + '" loading="lazy" decoding="async" width="400" height="400">' +
            /* Inside the link rather than beside it. .media is the box that
               carries the rounded corner and the overflow: hidden, so putting
               the panel in there makes one edge clip the image and the scrim
               together - instead of a square panel over a curved image. The
               link's accessible name is its aria-label, so nothing in here can
               affect it, and the panel is inert: aria-hidden, no interactive
               content, pointer-events off. */
            quickSpec(product) +
          '</a>' +
          flags +
        '</div>' +
        '<div class="pcard__body">' +
          '<div class="pcard__brand lat">' + esc(product.brand) + '</div>' +
          '<h3 class="pcard__title"><a href="' + href + '">' + esc(product.nameFa) +
            ' <span class="pcard__latin lat">' + esc(product.name) + '</span></a></h3>' +
          (keyFact(product) ? '<p class="pcard__fact">' + esc(keyFact(product)) + '</p>' : '') +
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

  /* One product in the top-deals hero, standing alone: the photograph
     large, then the brand, the name and the price, the whole thing one link.
     No add button - the hero is a shop window, and a tap on it should land
     on the product page, where the flavour and size get chosen before
     anything goes in the basket.

     alt is empty because the name is right under the picture, inside the
     same link: a screen reader would otherwise read it twice. The group and
     its «۱ از ۳» are the WAI-ARIA carousel pattern's slide; the first one is
     showing until rotateDeals() in app.js says otherwise. */
  function dealSlide(product, i, total) {
    var href = (cfg.productPage || 'product.html') + '?p=' + encodeURIComponent(product.slug);
    var off = product.onSale && product.discountPercent > 0
      ? '<span class="deal__off"><span class="num">' + faDigits(product.discountPercent) +
          '\u066A</span> تخفیف</span>'
      : '';
    return '' +
      '<div class="deal' + (i === 0 ? ' is-on' : '') + '" role="group" ' +
          'aria-roledescription="اسلاید" aria-label="' + faDigits(i + 1) + ' از ' + faDigits(total) + '">' +
        '<a class="deal__link" href="' + href + '">' +
          '<span class="deal__photo">' +
            '<img src="' + imgSrc(product.image) + '"' + srcsetAttr(product.image, product.imgv, SIZES_DEAL) +
              ' alt="" width="480" height="480" decoding="async">' +
            off +
          '</span>' +
          '<div class="deal__info">' +
            '<div class="deal__title">' +
              (product.brand ? '<span class="deal__brand lat">' + esc(product.brand) + '</span>' : '') +
              '<span class="deal__name">' + esc(product.nameFa) + '</span>' +
            '</div>' +
            '<div class="deal__foot">' +
              priceHtml(product) +
              '<span class="deal__go" aria-hidden="true">' + icon('arrow') + '</span>' +
            '</div>' +
          '</div>' +
        '</a>' +
      '</div>';
  }

  function categoryCard(cat) {
    /* A real photograph from the category's own shelf where there is one
       (categories_list() picks it), standing on the category's colour; the
       drawn tub only until a product in it has a photograph. */
    var photo = String(cat.photo || '');
    var art = photo
      ? '<span class="media media--4x5 ccard__art" style="border-radius:0;display:block" data-cat="' + esc(cat.id) + '">' +
          '<img class="ccard__photo" src="' + imgSrc(photo) + '"' + srcsetAttr(photo, cat.imgv, SIZES_CAT) +
            ' alt="دسته‌بندی ' + esc(cat.name) + '" loading="lazy" decoding="async" width="320" height="400">' +
        '</span>'
      : '<span class="media media--4x5" style="border-radius:0;display:block">' +
          '<img src="' + imgSrc(cat.image) + '" alt="دسته‌بندی ' + esc(cat.name) + '" loading="lazy" width="320" height="400">' +
        '</span>';
    return '' +
      /* href="#featured" was the one line that made a category unlinkable:
         the card scrolled to the grid and filtered it in place, so a category
         had no URL to share, bookmark or index. With no category page
         configured it still does exactly that. */
      '<a class="ccard reveal' + (photo ? ' ccard--photo' : '') + '" href="' +
        (cfg.categoryPage
          ? cfg.categoryPage + '?c=' + encodeURIComponent(cat.id)
          : '#featured') +
        '" data-category="' + esc(cat.id) + '">' +
        art +
        '<span class="ccard__label">' +
          '<span>' +
            '<span class="ccard__name">' + esc(cat.name) + '</span><br>' +
            /* .num isolates LTR, so it belongs on the digit alone - around
               the whole run it puts محصول to the right of the number. */
            '<span class="ccard__count"><span class="num">' + cat.count +
            '</span>\u00a0محصول</span>' +
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
      '<a class="pcard reveal" href="article.php?a=' + encodeURIComponent(post.slug || '') + '">' +
        '<div class="media media--16x10">' +
          '<img src="' + imgSrc(post.image) + '" alt="" loading="lazy" width="480" height="300">' +
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
    /* A line the shop cannot sell any more stays in the drawer, so the shopper
       can see what became of it - but it has to look like what it is. It was
       drawn like every other line, price and buttons and all, while the total
       under it quietly left it out. */
    var gone = item.unavailable === true;
    return '' +
      '<div class="line-item' + (gone ? ' line-item--gone' : '') + '" data-key="' + esc(item.key) + '">' +
        '<span class="line-item__media media media--1x1">' +
          '<img src="' + imgSrc(item.image) + '" alt="" width="74" height="74">' +
        '</span>' +
        '<div class="line-item__info">' +
          '<div class="line-item__title">' + esc(item.nameFa) + '</div>' +
          '<div class="u-xs u-dim">' + esc(item.variantLabel || '') + '</div>' +
          (gone
            ? '<p class="line-item__gone">ناموجود — در سفارش حساب نمی‌شود. با دکمهٔ × از سبد بردارید.</p>'
            : '<div class="line-item__ctl">' +
                '<span class="stepper-mini">' +
                  '<button type="button" data-qty="-1" aria-label="کاهش تعداد">' + icon('minus') + '</button>' +
                  '<span class="num">' + item.qty + '</span>' +
                  '<button type="button" data-qty="1" aria-label="افزایش تعداد">' + icon('plus') + '</button>' +
                '</span>' +
                '<span class="u-sm" style="font-weight:800">' +
                  '<span class="num">' + money(item.lineTotal) + '</span> ' + esc(cfg.currency.label) +
                '</span>' +
              '</div>') +
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

  /* Sits under the channel buttons, before anything opens.

     Whether the order lands in the chat box by itself depends on the app and
     on how long the order is — neither of which the shopper knows or should
     have to. Left unexplained, an empty chat box reads as "the site is
     broken", and the order is simply abandoned. Saying it up front costs one
     short paragraph and removes that whole failure. */
  /* Prices that moved since the shopper added the item.

     Shown rather than silently corrected. The cart is the last place someone
     reads a number before they transfer it by hand, and quietly swapping it
     for a different one is how a customer ends up feeling misled by a shop
     that did nothing wrong. Naming the old and the new figure costs one line
     and removes the whole argument. */
  function priceChangeNotice(changes) {
    if (!changes || !changes.length) { return ''; }

    /* Which KINDS of change happened, because the heading and the explanation
       below both depend on it. A run can carry both at once — the rate moved
       and the last tub went — and the notice has to be true about each. */
    var movedPrice = changes.some(function (c) { return typeof c.to === 'number'; });
    var movedStock = changes.some(function (c) { return typeof c.qtyTo === 'number' || c.gone; });

    var rows = changes.map(function (c) {
      if (c.gone) {
        return '<li><strong>' + esc(c.nameFa) + '</strong> — ' +
               'دیگر موجود نیست و در سفارش حساب نمی‌شود.</li>';
      }
      /* A quantity the shop can no longer fill. Said as what was done rather
         than what went wrong: the shopper asked for three, they are getting
         two, and the sentence has to leave them able to decide whether two is
         still worth it. */
      if (typeof c.qtyTo === 'number') {
        return '<li><strong>' + esc(c.nameFa) + '</strong> — ' +
               'فقط <span class="num">' + c.qtyTo + '</span> عدد مانده بود، ' +
               'تعداد از <span class="num">' + c.qtyFrom + '</span> کم شد.</li>';
      }
      var up = c.to > c.from;
      return '<li><strong>' + esc(c.nameFa) + '</strong> — ' +
             '<span class="num">' + money(c.from) + '</span> ' +
             (up ? '↑' : '↓') +
             ' <span class="num">' + money(c.to) + '</span></li>';
    }).join('');

    return '<div class="cart-notice">' +
      icon('info') +
      '<div>' +
        '<strong>' + (movedStock ? 'سبد شما به‌روز شد' : 'قیمت‌ها به‌روز شد') + '</strong>' +
        '<ul>' + rows + '</ul>' +
        /* One sentence per kind of change, and only for the kinds that
           actually happened. A cart trimmed from two to one was being told
           its exchange rate had moved. */
        (movedPrice
          ? '<span class="cart-notice__why">' +
              'قیمت این محصولات به نرخ ارز بسته است و از زمانی که به سبد اضافه کردید تغییر کرده. ' +
              'مبلغی که پرداخت می‌کنید همین عدد جدید است.' +
            '</span>'
          : '') +
        (movedStock
          ? '<span class="cart-notice__why">' +
              'موجودی انبار از زمانی که به سبد اضافه کردید کم شده. ' +
              'همین تعداد را می‌توانیم برایتان بفرستیم.' +
            '</span>'
          : '') +
      '</div>' +
    '</div>';
  }

  function orderHelpNote() {
    return '<div class="order-help">' +
      icon('info') +
      '<div>' +
        '<strong>اگر متن سفارش خودش در کادر پیام نوشته نشد</strong>، نگران نباشید — ' +
        'با زدن دکمه، متن سفارش در حافظهٔ گوشی کپی می‌شود و در صفحهٔ بعد هم دکمهٔ «کپی متن سفارش» هست. ' +
        'در گفتگوی فروشگاه روی کادر تایپ نگه دارید، گزینهٔ «چسباندن / Paste» را بزنید و پیام را بفرستید.' +
      '</div>' +
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
  /* `server` is the response from the order API, or null in prototype mode.
     When it is present its card details win: the shop can change the card from
     the admin panel without anyone redeploying the site, and a stale number
     baked into config.js is exactly the failure that sends a customer's money
     to the wrong account. */
  function paymentPanel(snapshot, channel, route, server) {
    var p = cfg.payment;
    var pay = (server && server.payment) || {};
    var appName = (channel && channel.label) || 'همان گفتگو';
    var needsPaste = route ? route.needsPaste : !(channel && channel.prefillParam);
    /* On its way to the server: no code, no clock and no card yet - and not
       the «the card comes in the chat» line either, which belongs to a shop
       with no server at all and would be untrue a second later. */
    var pending = !!(server && server.pending);

    /* ONLY the server's card, with no fallback of any kind.

       This used to read `pay.cardNumber || p.cardNumber`, and an empty string
       is falsy - so a shop that had not set its card yet fell through to the
       sample number in config.js and showed the customer a plausible, grouped,
       copyable 16-digit number belonging to nobody. That is the exact failure
       the comment above warns about, committed by the guard meant to prevent
       it. A shop with no card must say it has no card. */
    var digits = String(pay.cardNumber || '').replace(/\D/g, '');
    var hasCard = digits.length >= 12;
    var grouped = digits.replace(/(\d{4})(?=\d)/g, '$1 ');
    var bankName   = hasCard ? (pay.bankName || '') : '';
    var cardHolder = hasCard ? (pay.cardHolder || '—') : '';

    return '' +
      '<div class="pay" data-pay-panel>' +
        '<div class="pay__done">' + icon('check') +
          '<span>سفارش شما آماده ارسال در ' + esc(appName) + ' است</span></div>' +

        /* Shown only when the order really was recorded. It is the reference
           the shopper quotes if anything goes wrong, so inventing one in
           prototype mode — where nothing was saved — would be worse than
           showing none at all. */
        (server && server.code
          ? '<div class="pay__code">شماره پیگیری سفارش: ' +
            '<strong class="num" dir="ltr">' + esc(server.code) + '</strong></div>'
          : '') +

        /* Apps without URL prefill got the order via the clipboard. A toast
           vanishes; this has to stay on screen until they have pasted it. */
        (needsPaste ? pasteNote(appName, route) : '') +

        /* The server's total wins whenever there is one.

           This is the figure the shopper is told to transfer, and the order in
           the database was recorded at the server's number, not at the cart's.
           The two can differ with nobody doing anything wrong: the shop edits a
           price while an old tab still holds the previous one in localStorage,
           or a shipping rule changes between the cart being filled and the
           order being sent. Showing the browser's copy would ask for one amount
           and record another, and the mismatch surfaces later as a customer who
           paid "the wrong price" — with the receipt to prove the site told them
           to. */
        /* The quote has a deadline, so say so before the amount rather than
           after it. A customer who transfers an hour later at a rate that has
           moved is a refund conversation neither side wants, and card-to-card
           has no authorisation to adjust. */
        (server && server.expiresAt
          ? '<div class="pay__hold" data-expires="' + server.expiresAt + '"' +
            (server.serverNow ? ' data-now="' + server.serverNow + '"' : '') + '>' +
              icon('clock') +
              /* «این قیمت تا ۲۹:۴۱ دیگر معتبر است» read as «valid until
                 29:41 more». A label and the clock read right at every second,
                 and at the end too: «… : پایان یافته». */
              '<span>مهلت پرداخت با این قیمت: <strong data-hold-countdown>—</strong></span>' +
            '</div>'
          : '') +

        '<div class="pay__amount">' +
          '<span class="u-sm u-muted">مبلغ قابل پرداخت</span>' +
          '<strong><span class="num">' +
            money(server && typeof server.total === 'number' ? server.total : snapshot.total) +
          '</span> ' + esc(cfg.currency.label) + '</strong>' +
        '</div>' +

        /* No card configured: say so. Never a number, never a copy button -
           a customer who copies a number believes they can pay with it. */
        (pending
          ? '<div class="pay__card pay__card--pending" aria-busy="true">' +
              '<div class="pay__holder">در حال ثبت سفارش و گرفتن شمارهٔ پیگیری…</div>' +
            '</div>'
          : hasCard
          ? '<div class="pay__card">' +
              '<div class="pay__card-head">' +
                '<span class="u-eyebrow u-eyebrow--bare">' + esc(bankName) + '</span>' +
                '<button class="pay__copy" type="button" data-copy-card="' + esc(digits) + '">کپی شماره</button>' +
              '</div>' +
              '<div class="pay__number num" dir="ltr">' + esc(grouped) + '</div>' +
              '<div class="pay__holder">به نام ' + esc(cardHolder) + '</div>' +
            '</div>'
          : '<div class="pay__card pay__card--pending">' +
              '<div class="pay__holder">شماره کارت در همان گفتگوی ' + esc(appName) +
              ' برایتان فرستاده می‌شود. تا آن موقع مبلغی واریز نکنید.</div>' +
            '</div>') +

        (server && server.expiresAt ? payExpired(true) : '') +

        /* After the transfer, the receipt - only for an order the server has,
           because it is filed against that order's code. Not in here,
           though: app.js pins it under the panel (receiptBlock(code, '',
           'dock')), because at the end of the panel it was below the fold on
           a phone. Where it is pinned, the old «send the receipt in the
           chat» note would only argue with it. */
        (server
          ? ''
          : '<p class="pay__note">' + esc(String(p.note || '').replace('{channel}', appName)) + '</p>') +
      '</div>';
  }

  /* The order text, for an app that cannot take it in the link.

     Says it was copied only once it was - a clipboard can refuse, and «کپی
     شد» over an empty one sent the shopper to paste nothing - and offers it
     again either way: the card number copied next takes its place on the
     clipboard. app.js repaints the sentence when the copy lands. */
  function pasteNote(appName, route) {
    return '<div class="pay__paste" data-paste-note>' + icon('copy') +
      '<div class="pay__paste-body">' +
        '<span data-paste-text>' + pasteText(appName, !!(route && route.copied)) + '</span>' +
        '<button class="pay__copy-text" type="button" data-copy-order>کپی متن سفارش</button>' +
      '</div>' +
    '</div>';
  }

  function pasteText(appName, copied) {
    return copied
      ? 'متن سفارش کپی شد — در گفتگوی ' + esc(appName) + ' آن را <strong>پیست کنید و بفرستید</strong>.'
      : 'متن سفارش را با دکمهٔ زیر کپی کنید و در گفتگوی ' + esc(appName) +
        ' <strong>پیست کنید و بفرستید</strong>.';
  }

  /* What the panel says once its clock is at zero. Hidden until then:
     app.js marks the panel, and the CSS swaps the card and the amount for
     this - a card number under a price that has run out is an invitation to
     transfer it. The button puts the same goods back in the basket, at
     whatever they cost now. */
  function payExpired(again) {
    return '<div class="pay__expired">' +
      '<strong>مهلت پرداخت با این قیمت تمام شد.</strong>' +
      '<span>اگر واریز نکرده‌اید، مبلغی واریز نکنید و سفارش را دوباره ثبت کنید. ' +
        'اگر واریز کرده‌اید، رسید را بفرستید.</span>' +
      (again
        ? '<button class="btn btn--ghost btn--block" type="button" data-order-again>' +
            'افزودن دوبارهٔ همین کالاها به سبد</button>'
        : '') +
    '</div>';
  }

  /* The order did not reach the shop's records. Said plainly, with the one
     step that puts it right - not the payment panel, which without a recorded
     order has no code and no card to show. `o`: {message, action, label},
     action being fix | cart | retry (app.js). */
  function orderFailed(o) {
    return '<div class="pay pay--failed" data-order-failed>' +
      '<div class="pay__fail" role="alert">' + icon('info') +
        '<div><strong>سفارش ثبت نشد</strong><span>' + esc(o.message) + '</span></div>' +
      '</div>' +
      '<p class="pay__note">شمارهٔ پیگیری و شمارهٔ کارت بعد از ثبت سفارش نشان داده می‌شود؛ ' +
        'تا آن موقع مبلغی واریز نکنید.</p>' +
      '<button class="btn btn--primary btn--block" type="button" data-order-' + esc(o.action) + '>' +
        esc(o.label) + '</button>' +
    '</div>';
  }

  /* The basket in one line and the amount, above the buttons that send it.
     The drawer's own summary is hidden while the form is up, to give the form
     the room - and an order went out without its total in sight. */
  function checkoutSummary(snapshot) {
    var s = snapshot || {};
    var ship = s.count && !s.freeShipping && s.shipping
      ? '<span class="num">' + money(s.shipping) + '</span> ' + esc(cfg.currency.label)
      : 'رایگان';
    return '<div class="checkout-sum" data-checkout-sum>' +
      '<div class="checkout-sum__row"><span>' + faDigits(s.count || 0) + ' کالا</span>' +
        '<span>هزینهٔ ارسال: ' + ship + '</span></div>' +
      '<div class="checkout-sum__total"><span>مبلغ قابل پرداخت</span>' +
        '<strong><span class="num">' + money(s.total || 0) + '</span> ' + esc(cfg.currency.label) +
        '</strong></div>' +
    '</div>';
  }

  /* One order in «سفارش‌های من» (track.php). mine is what this browser
     remembers; live is what the server answered for it - null while it has
     not answered, and when it did not know the order, which then shows as
     remembered, with «پیدا نشد» where the status would be.

     «جزئیات سفارش» is a small POST form, not a link: a link would carry the
     phone number in its URL, into the browser's history and the server's
     logs - the reason the tracking form posts in the first place. */
  function myOrderRow(mine, live, answered) {
    var when = live ? live.createdAt : mine.at;
    var total = live ? live.total : mine.total;
    var date = when
      ? new Date(when).toLocaleDateString('fa-IR', { year: 'numeric', month: 'long', day: 'numeric' })
      : '';
    return '' +
      '<li class="myorder">' +
        '<div class="myorder__top">' +
          '<span class="myorder__code num" dir="ltr">' + esc(mine.code) + '</span>' +
          (live
            ? '<span class="track__badge track__badge--' + esc(live.status) + '">' + esc(live.statusFa) + '</span>'
            : (answered
              ? '<span class="myorder__unknown">پیدا نشد</span>'
              : '<span class="myorder__wait" aria-hidden="true"></span>')) +
        '</div>' +
        (live && live.summary
          ? '<p class="myorder__what">' + esc(live.summary) +
            (live.more > 0 ? ' <span class="u-muted">و ' + faDigits(live.more) + ' قلم دیگر</span>' : '') + '</p>'
          : '') +
        '<p class="myorder__meta">' +
          (date ? '<span>' + esc(date) + '</span>' : '') +
          (typeof total === 'number'
            ? '<span><span class="num">' + money(total) + '</span> ' + esc(cfg.currency.label) + '</span>'
            : '') +
        '</p>' +
        (live && live.trackingCode
          ? '<p class="myorder__parcel">کد رهگیری پست: <span class="num" dir="ltr">' + esc(live.trackingCode) + '</span></p>'
          : '') +
        '<div class="myorder__actions">' +
          '<form method="post" action="track.php">' +
            '<input type="hidden" name="code" value="' + esc(mine.code) + '">' +
            '<input type="hidden" name="phone" value="' + esc(mine.phone) + '">' +
            '<button class="btn btn--ghost btn--sm" type="submit">جزئیات سفارش</button>' +
          '</form>' +
          '<button class="myorder__forget" type="button" data-forget-order="' + esc(mine.code) + '">' +
            'حذف از این فهرست</button>' +
        '</div>' +
      '</li>';
  }

  /* Where the customer sends the photograph of the transfer (?r=receipt).
     The tracking page draws the full box under the card. The drawer pins
     the 'dock' one under the payment panel (setPayDock() in app.js): the
     same parts, pared down to a line and a button, so it fits under a thumb
     and never scrolls out of reach. initReceipts() in app.js makes either
     work. The phone comes with it on the tracking page, where it was just
     typed; in the drawer it is read from the order form at the moment of
     sending. */
  function receiptBlock(code, phone, variant) {
    var dock = variant === 'dock';
    return '' +
      '<div class="receipt-up' + (dock ? ' receipt-up--dock' : '') + '" data-receipt data-code="' + esc(code) + '"' +
          (phone ? ' data-phone="' + esc(phone) + '"' : '') + '>' +
        (dock
          ? '<p class="receipt-up__lead">واریز کردید؟ عکس یا اسکرین‌شات رسید را همین‌جا بفرستید.</p>'
          : '<div class="receipt-up__head">' +
              '<span class="receipt-up__icon">' + icon('upload') + '</span>' +
              '<div>' +
                '<strong class="receipt-up__title">رسید واریز را همین‌جا بفرستید</strong>' +
                '<span class="receipt-up__sub">عکس یا اسکرین‌شات رسید. برای ما در تلگرام فرستاده می‌شود ' +
                  'تا سفارش زودتر تأیید شود.</span>' +
              '</div>' +
            '</div>') +
        '<label class="btn ' + (dock ? 'btn--primary' : 'btn--ghost') + ' btn--block receipt-up__pick">' +
          '<input class="sr-only" type="file" accept="image/*" data-receipt-file>' +
          (dock ? icon('upload') : '') +
          '<span data-receipt-label>' + (dock ? 'ارسال رسید واریز' : 'انتخاب عکس رسید') + '</span>' +
        '</label>' +
        '<div class="receipt-up__state" data-receipt-state aria-live="polite"></div>' +
      '</div>';
  }

  /* A brand's page. */
  function brandHref(slug) {
    return 'brand.php?b=' + encodeURIComponent(String(slug == null ? '' : slug));
  }

  /* One tile of the logo wall. brand_card_html() in lib/brands.php draws the
     same tile for the pages PHP draws; tests/test-brands.mjs holds the two to
     the same markup.

     The plate is light whatever the theme: a brand's logo is drawn for a
     white box nine times in ten, and on this site's dark ground a black
     wordmark simply vanishes. With no logo uploaded, the name itself stands
     on the plate. Every tile has the same three parts - plate, name, count -
     so a row reads level: the Persian name when there is one, else the name,
     even where the plate already spells it out. */
  function brandCard(b) {
    var name = String(b.name || '');
    var nameFa = String(b.nameFa || '');
    var logo = String(b.logo || '');
    var caption = nameFa || name;
    return '' +
      '<a class="bcard" href="' + esc(brandHref(b.slug)) + '">' +
        '<span class="bcard__plate">' +
          (logo
            ? '<img class="bcard__logo" src="' + imgSrc(logo) + '" alt="' + esc(name) + '" loading="lazy" width="160" height="80">'
            : '<span class="bcard__word lat" dir="ltr">' + esc(name) + '</span>') +
        '</span>' +
        '<span class="bcard__name" dir="auto">' + esc(caption) + '</span>' +
        '<span class="bcard__count"><span class="num">' + faDigits(Number(b.count) || 0) +
          '</span>\u00a0محصول</span>' +
      '</a>';
  }

  /* The band of brand logos under the home page's hero, as brand_strip_html()
     in lib/brands.php draws it - for the static demo, where no PHP does. */
  function brandStrip(list) {
    var item = function (b, echo) {
      var name = String(b.name || '');
      var inner = b.logo
        ? '<img src="' + imgSrc(b.logo) + '" alt="' + (echo ? '' : esc(name)) + '" loading="lazy" decoding="async" width="132" height="44">'
        : '<span class="ticker__word lat" dir="ltr">' + esc(name) + '</span>';
      return '<a class="ticker__brand" href="' + esc(brandHref(b.slug)) + '"' +
        (echo ? ' tabindex="-1" aria-hidden="true"' : '') + '>' + inner + '</a>';
    };
    var first = '', again = '';
    list.forEach(function (b) { first += item(b, false); again += item(b, true); });
    var copies = Math.max(1, Math.ceil(12 / list.length));
    var rep = function (s, n) { var out = ''; for (var i = 0; i < n; i++) { out += s; } return out; };
    return '<section class="ticker ticker--brands" aria-label="برندهای فروشگاه" data-brandstrip data-drawn>' +
      '<div class="ticker__track">' +
        '<div class="ticker__group">' + first + rep(again, copies - 1) + '</div>' +
        '<div class="ticker__group" aria-hidden="true">' + rep(again, copies) + '</div>' +
      '</div>' +
      '<a class="ticker__all" href="brands.php">همه برندها' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M15 6l-6 6 6 6"/></svg>' +
      '</a></section>';
  }

  /* Cards shaped like the real ones, shimmering, while more products load:
     the grid keeps its height and the eye knows where they will land. */
  function productSkeleton(n) {
    var one = '<div class="pcard pcard--skel" aria-hidden="true">' +
      '<span class="media media--1x1 skel"></span>' +
      '<span class="pcard__body"><span class="skel skel--line"></span>' +
      '<span class="skel skel--line skel--short"></span><span class="skel skel--line skel--price"></span></span>' +
    '</div>';
    var out = '';
    for (var i = 0; i < n; i++) { out += one; }
    return out;
  }

  function searchRow(product) {
    return '' +
      '<a class="search__row" href="' + (cfg.productPage || 'product.html') + '?p=' + encodeURIComponent(product.slug) + '">' +
        '<span class="media media--1x1"><img src="' + imgSrc(thumbSrc(product.image, product.imgv, 160)) + '" alt="" width="52" height="52"></span>' +
        '<span style="flex:1;min-width:0">' +
          '<span style="font-weight:800;font-size:.9rem;display:block">' + esc(product.nameFa) + '</span>' +
          '<span class="u-xs u-dim lat">' + esc(product.name) + '</span>' +
        '</span>' +
        '<span class="u-sm" style="font-weight:800"><span class="num">' + money(product.price) + '</span></span>' +
      '</a>';
  }

  return {
    esc: esc,
    brandStrip: brandStrip,
    productSkeleton: productSkeleton,
    money: money,
    unitHtml: unitHtml,
    faDigits: faDigits,
    icon: icon,
    stars: stars,
    ratingHtml: ratingHtml,
    priceHtml: priceHtml,
    productCard: productCard,
    srcsetOf: srcsetOf,
    srcsetAttr: srcsetAttr,
    thumbSrc: thumbSrc,
    keyFact: keyFact,
    SIZES_MAIN: SIZES_MAIN,
    dealSlide: dealSlide,
    categoryCard: categoryCard,
    brandCard: brandCard,
    brandHref: brandHref,
    reviewCard: reviewCard,
    journalCard: journalCard,
    lineItem: lineItem,
    customerForm: customerForm,
    priceChangeNotice: priceChangeNotice,
    orderHelpNote: orderHelpNote,
    channelButtons: channelButtons,
    paymentPanel: paymentPanel,
    pasteText: pasteText,
    orderFailed: orderFailed,
    checkoutSummary: checkoutSummary,
    receiptBlock: receiptBlock,
    myOrderRow: myOrderRow,
    searchRow: searchRow,
  };
})();
