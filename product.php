<?php
/* ===========================================================================
   SUPPEX — Product page (server-rendered head)
   ---------------------------------------------------------------------------
   The body below is the same static markup product.html has always had, and
   the same JavaScript hydrates it. Only the <head> is built on the server, and
   only because it has to be:

   Every product used to be reached at product.html?p=<slug>, one file with one
   hardcoded title, description, og:image and JSON-LD. Search engines saw fifty
   identical pages — but the sharper cost was that this shop sells through
   Telegram and Instagram, whose preview bots read og: tags and never run
   JavaScript. A link to the creatine rendered the whey's name and picture, in
   the channel the business actually runs on.

   product.html is kept as-is for the GitHub Pages demo, where there is no PHP.
   =========================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/products.php';
require_once __DIR__ . '/lib/seo.php';

$slug    = clean_text($_GET['p'] ?? '', 120);
$product = $slug === '' ? null : product_get($slug);

/* A missing product answers 404, not 200 with an empty page. A soft 404 gets
   indexed as a real page and quietly fills the index with nothing. */
if ($product === null) {
    http_response_code(404);
}

$meta = seo_product_meta($product);

/* What app.js asks for on this page, answered now (lib/boot.php): the
   product, keyed by the ?p= the script reads, and the «معمولاً با هم خریده
   می‌شوند» shelf - the same two calls, with the same arguments, in the same
   order initProduct() makes them, the second only when the first comes up
   short. */
$bootRequests = [];
if ($product !== null) {
    $bootRequests[] = ['product', ['slug' => (string) ($_GET['p'] ?? '')], $product];
    $relatedArgs = ['notCategory' => $product['category'] ?? null,
                    'exclude' => (string) $product['slug'], 'limit' => 4];
    $related = products_list($relatedArgs);
    $bootRequests[] = ['products', $relatedArgs, $related];
    if (count($related) < 4) {
        $bootRequests[] = ['products', ['exclude' => (string) $product['slug'], 'limit' => 8]];
    }
}

/* The body below is server-rendered for the same reason the head is, and it is
   a separate problem from the head.

   Googlebot renders JavaScript, so for Google alone the hydrated page would be
   enough. Nothing else is: Torob and Emalls — the price-comparison engines that
   matter more than Google for this category — crawl raw HTML, and a product
   page with no price in the markup cannot be listed on them at all. The AI
   crawlers read raw text too. And a customer arriving from a Telegram link
   would see the whey painted first, then swapped for the thing they clicked.

   The JavaScript then hydrates over identical values, so this is not a second
   source of truth — it is the same values, arriving early enough to be read. */
$pName  = $product === null ? '' : (string) $product['nameFa'];
$pLatin = $product === null ? '' : trim((string) ($product['name'] ?? ''));
$pBrand = $product === null ? '' : (string) ($product['brand'] ?? '');
/* The picture the script opens on, not simply the product's own: when the
   first flavour or combination has a photograph, that is what app.js shows,
   and drawing another one here cost a phone a second download and the
   shopper a picture that changed under them (product_opening_image()). */
$pImage = $product === null ? '' : product_opening_image($product);
$pPrice = $product === null ? 0  : (int) $product['price'];
$pWas   = ($product === null || empty($product['onSale'])) ? null : (int) $product['compareAt'];
/* The saving beside the struck price - what app.js repaints there too, so the
   page does not jump when the script takes over. */
$pOff   = ($pWas !== null && $pWas > $pPrice) ? (int) ($product['discountPercent'] ?? 0) : 0;
$pStock = $product !== null && !empty($product['inStock']);
$pInquiry = $product !== null && !empty($product['requiresInquiry']);
$unit   = 'تومان';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<meta name="theme-color" content="#131210">


<?php include __DIR__ . '/partials/head-assets.php'; ?>


<title><?= e($meta['title']) ?></title>
<meta name="description" content="<?= e($meta['description']) ?>">
<meta name="robots" content="<?= e($meta['robots']) ?>">
<link rel="canonical" href="<?= e($meta['url']) ?>">

<!-- Absolute URLs throughout: Telegram, WhatsApp and Instagram preview bots do
     not resolve relative paths and do not run JavaScript, so anything not
     spelled out here simply does not appear on the card. -->
<meta property="og:type" content="product">
<meta property="og:site_name" content="<?= e(setting('shop_name', 'SUPPEX')) ?>">
<meta property="og:locale" content="fa_IR">
<meta property="og:title" content="<?= e($meta['title']) ?>">
<meta property="og:description" content="<?= e($meta['description']) ?>">
<meta property="og:url" content="<?= e($meta['url']) ?>">
<meta property="og:image" content="<?= e($meta['image']) ?>">
<!-- Declared dimensions are what make Telegram and WhatsApp render the large
     card rather than a thumbnail; without them the preview shrinks. -->
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="<?= e($meta['title']) ?>">
<meta name="twitter:card" content="summary_large_image">

<?php if ($meta['jsonld'] !== ''): ?>
<script type="application/ld+json">
<?= $meta['jsonld'] ?>

</script>
<?php endif; ?>
</head>

<?php include __DIR__ . '/partials/chrome-top.php'; ?>

<main id="main"<?= $product === null ? '' : ' data-product-page' ?>>
  <div class="container">

<?php if ($product === null): ?>
    <?php /* No product, so nothing below applies: the add-to-cart button, the
             batch certificate, the shipping terms all belong to a thing that is
             not here. Saying so and pointing onward is the whole page. */ ?>
    <nav class="breadcrumb" aria-label="مسیر صفحه">
      <a href="./">خانه</a>
      <span aria-hidden="true">/</span>
      <a href="./#categories">محصولات</a>
    </nav>

    <section class="notfound">
      <p class="notfound__code">۴۰۴</p>
      <h1 class="notfound__title">این محصول دیگر نیست</h1>
      <p class="notfound__text">
        شاید از فهرست برداشته شده باشد، یا لینک ناقص کپی شده باشد.
        همین جنس را می‌خواهید؟ در فهرست محصولات پیدایش کنید یا به ما پیام بدهید.
      </p>
      <div class="notfound__actions">
        <a class="btn btn--primary" href="./#categories">دیدن محصولات</a>
        <a class="btn btn--ghost" href="./">صفحه اصلی</a>
      </div>
    </section>
<?php else: ?>

    <nav class="breadcrumb" aria-label="مسیر صفحه">
      <a href="./">خانه</a>
      <span aria-hidden="true">/</span>
      <a href="./#categories">محصولات</a>
      <span aria-hidden="true">/</span>
      <span aria-current="page" data-p-breadcrumb><?= e($pName) ?></span>
    </nav>

    <!-- ═════════════════════════════════════ Product ═══ -->
    <article class="pdp">

      <!-- Gallery.
           The photograph opens the enlarged view - openLightbox() in app.js -
           and the arrows step through the pictures without leaving the page.
           On a desktop the whole column is its own scroll area: see
           .pdp__gallery in layout.css.

           The arrows and the magnifier start hidden, and app.js shows them
           once they work. A phone holding an older app.js in its cache got
           this page with a magnifier that did nothing and no arrows. -->
      <div class="pdp__gallery" data-gallery>
        <div class="gallery__main media media--1x1 media--lit" style="border-radius:var(--r-xl)" data-gallery-main>
          <?php /* The largest thing on the page, so fetched first: PageSpeed
                   found it waiting 1.9 s behind the gallery's thumbnails.
                   The sizes are ui.js's SIZES_MAIN, character for character,
                   or the script taking over would fetch it a second time. */ ?>
          <img data-p-image src="<?= e(product_image_or_placeholder($pImage)) ?>"<?= image_srcset_attr($pImage, '(min-width: 1024px) 560px, 100vw') ?> alt="<?= e(trim($pName . " " . $pLatin)) ?>" width="640" height="640" fetchpriority="high" draggable="false">
          <div class="pcard__flags" data-p-flags style="inset-block-start:var(--sp-5);inset-inline:var(--sp-5)"></div>
          <button class="gallery__nav gallery__nav--prev" type="button" data-gallery-step="-1" aria-label="تصویر قبلی" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 6l6 6-6 6"/></svg></button>
          <button class="gallery__nav gallery__nav--next" type="button" data-gallery-step="1" aria-label="تصویر بعدی" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M15 6l-6 6 6 6"/></svg></button>
          <button class="gallery__zoom" type="button" data-gallery-open aria-label="نمایش بزرگ تصویر" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5M11 8v6M8 11h6"/></svg></button>
          <span class="gallery__count num" data-gallery-count hidden></span>
        </div>
        <div class="gallery__thumbs" data-p-thumbs></div>
      </div>

      <!-- Buy box -->
      <div class="pdp__info">
        <?php $pBrandSlug = $pBrand === '' ? null : brand_link_slug($pBrand); ?>
        <!-- The brand leads to its page (brand.php), unless the owner hid it. -->
        <p class="u-eyebrow" data-p-eyebrow><?php if ($pBrandSlug !== null): ?><a class="pdp__brand" href="<?= e(brand_url($pBrandSlug)) ?>"><?= e($pBrand) ?></a><?php else: ?><?= e($pBrand !== '' ? $pBrand : setting('shop_name', 'SUPPEX')) ?><?php endif; ?></p>
        <h1 class="u-h1 pdp__title" data-p-title><?= e($pName) ?></h1>

        <div class="row" style="gap:14px" data-p-rating></div>

        <!-- Stock state in the markup, not only as a disabled button: a crawler
             reading text has no other way to tell a listable product from one
             that is out of stock. -->
        <p class="sr-only" data-p-stock><?= $pInquiry ? 'نیازمند استعلام موجودی' : ($pStock ? 'موجود در انبار' : 'ناموجود') ?></p>

        <div class="pdp__field">
          <span class="pdp__field-label">طعم</span>
          <div class="row" style="gap:8px" data-p-flavors></div>
        </div>

        <!-- Axes beyond size and flavour, built by app.js from the
             product's own attributes. Empty, and hidden, for a product
             that has none - which is every product until one is given
             an attribute in the panel. -->
        <div class="pdp__field" data-p-attrs-field hidden>
          <div data-p-attrs></div>
        </div>

        <div class="pdp__field">
          <span class="pdp__field-label">وزن بسته</span>
          <div class="stack" style="--gap:10px" data-p-sizes></div>
        </div>

        <!-- The price follows the choices it depends on and sits on the button
             it leads to: flavour, then size, then what that costs, then
             «افزودن به سبد خرید». Above the flavours it read as the price of
             something not yet chosen, and the owner asked for this order. -->
        <!-- The price, the quantity, the button and the two lines under it are
             one decision, so they sit in one panel (.pdp__purchase). -->
        <div class="pdp__purchase">
        <div class="price price--lg" data-p-price>
          <?php if ($pPrice > 0): ?>
            <?php /* The isolate belongs on the digits alone. On the whole
                     price .num pins the run LTR and تومان lands to the
                     right of the number - the first thing a Persian
                     reader meets. Server-rendered, so this is also what
                     a Telegram preview and Torob read. */ ?>
            <span class="price__now"><span class="num"><?= money_fa($pPrice) ?></span>&nbsp;<?= price_unit_html($unit) ?></span>
            <?php if ($pWas !== null && $pWas > $pPrice): ?>
              <span class="price__was num"><?= money_fa($pWas) ?></span>
            <?php endif; ?>
            <?php if ($pOff > 0): ?>
              <span class="badge badge--accent num price__off">&minus;<?= fa_digits((string) $pOff) ?>٪</span>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <div class="pdp__buy">
          <div class="qty" data-p-qty>
            <button type="button" data-step="-1" aria-label="کاهش تعداد">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="18" height="18"><path d="M5 12h14"/></svg>
            </button>
            <span class="qty__val num" data-qty-val aria-live="polite">1</span>
            <button type="button" data-step="1" aria-label="افزایش تعداد">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="18" height="18"><path d="M12 5v14M5 12h14"/></svg>
            </button>
          </div>
          <button class="btn btn--primary" type="button" data-p-add><?= $pInquiry ? 'استعلام موجودی' : 'افزودن به سبد خرید' ?></button>
        </div>
        <p class="u-sm u-muted" data-p-inquiry-note<?= $pInquiry ? '' : ' hidden' ?>>
          موجودی این انتخاب باید بررسی شود. درخواست را در سایت ثبت کنید؛ پاسخ حداکثر تا ۳۰ دقیقه در صفحهٔ پیگیری، خرید پس از تأیید موجودی.
        </p>

        <div class="pdp__assur">
          <!-- The middle item used to read «برگه آزمایش سری تولید», with a
               green tick, on all fifteen products, in the server-rendered
               HTML - while the shop's own returns, FAQ and terms pages say in
               plain Persian that there is no laboratory and no batch is
               tested. What replaced it promised something the shop does not do
               either, so it went too: the strip keeps only what is true.

               «مرجوعی ۷ روزه» became «۷ روز کاری»: the withdrawal right in
               ماده ۳۷ is in WORKING days, so the old wording was narrower than
               both the law and the shop's own returns page. -->
          <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg> مرجوعی ۷ روز کاری</span>
          <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg> اورجینال و پلمب‌شده</span>
        </div>

        </div>
        <!-- Details -->
        <div class="acc" style="margin-top:12px">
          <div class="acc__item is-open">
            <button class="acc__trigger" type="button" aria-expanded="true">
              ارزش غذایی
              <svg class="acc__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            </button>
            <div class="acc__panel"><div><div class="acc__body" data-p-nutrition></div></div></div>
          </div>

          <div class="acc__item">
            <button class="acc__trigger" type="button" aria-expanded="false">
              مواد تشکیل‌دهنده
              <svg class="acc__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            </button>
            <div class="acc__panel"><div><p class="acc__body acc__body--lines" data-p-ingredients></p></div></div>
          </div>

          <div class="acc__item">
            <button class="acc__trigger" type="button" aria-expanded="false">
              روش مصرف
              <svg class="acc__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            </button>
            <div class="acc__panel"><div><p class="acc__body acc__body--lines" data-p-usage></p></div></div>
          </div>

          <div class="acc__item">
            <button class="acc__trigger" type="button" aria-expanded="false">
              ارسال و مرجوعی
              <svg class="acc__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            </button>
            <div class="acc__panel"><div><p class="acc__body">
              <!-- Was: «سفارش‌های ثبت‌شده تا ساعت ۱۵ همان روز ارسال می‌شود»
                   - a dispatch SLA nothing in the code enforces and one
                   person cannot promise, removed from index.html for the same
                   reason - and «مرجوعی تا ۷ روز», which is narrower than the
                   ۷ روز کاری the law gives and the returns page states.

                   Shipping copy follows the shop settings. -->
              <span data-shipping-policy><?= storefront_shipping_html(true) ?></span> زمان ارسال هر سفارش را در
              همان گفتگو می‌گوییم. مرجوعی تا ۷ روز کاری پذیرفته می‌شود؛ شرایط و
              استثناهایش در صفحه مرجوعی و بازگشت وجه نوشته شده است.
            </p></div></div>
          </div>
        </div>

        <!-- The description used to sit between the price and the flavour
             chips. It is five or six lines of prose on most products, which
             put «افزودن به سبد خرید» the better part of a screen further
             down for somebody who had already decided what they wanted.

             Below the panels it is still on the page, still crawlable, and
             still the first thing an undecided reader reaches after the
             specification. It needs the heading because a paragraph that
             follows four collapsed panels with no label reads as an
             orphan. -->
        <section class="pdp__about" aria-labelledby="pdp-about">
          <h2 class="u-h3" id="pdp-about">توضیحات تکمیلی</h2>
          <p class="u-sm u-muted" data-p-desc></p>
          <ul data-p-features></ul>
        </section>
      </div>
    </article>

    <?php if ($product !== null):
        /* What buyers wrote (lib/reviews.php): approved ones only. With none,
           the page says where a buyer can write one rather than showing an
           empty box - or stars nobody gave. */
        $reviews    = reviews_for_product((int) $product['id']);
        $reviewSum  = review_summaries([(int) $product['id']])[(int) $product['id']] ?? null; ?>
    <!-- ══════════════════════════════ Reviews ═══ -->
    <section class="section section--flush-top reviews" id="reviews" aria-labelledby="reviews-title">
      <div class="section__head">
        <div>
          <p class="u-eyebrow">نظر خریداران</p>
          <h2 class="u-h2" id="reviews-title" style="margin-top:8px">نظر کسانی که خریده‌اند</h2>
        </div>
      </div>
      <?php if ($reviewSum !== null): ?>
        <div class="reviews__summary">
          <span class="reviews__avg num"><?= e(fa_digits((string) $reviewSum['avg'])) ?></span>
          <?= review_stars_html((float) $reviewSum['avg']) ?>
          <span class="u-sm u-muted">از <span class="num"><?= e(fa_digits((string) $reviewSum['count'])) ?></span> نظر خریدار</span>
        </div>
        <ul class="reviews__list">
          <?php foreach ($reviews as $r): ?>
            <li class="review">
              <div class="review__head">
                <?= review_stars_html((float) $r['rating']) ?>
                <span class="sr-only"><?= (int) $r['rating'] ?> از ۵</span>
                <span class="review__author"><?= e(trim((string) $r['author']) !== '' ? (string) $r['author'] : 'خریدار') ?></span>
                <span class="review__badge">خرید تأییدشده</span>
                <time datetime="<?= e(date('Y-m-d', (int) strtotime((string) $r['created_at']))) ?>"><?= e(review_date_fa((string) $r['created_at'])) ?></time>
              </div>
              <?php if (trim((string) $r['body']) !== ''): ?>
                <p class="review__body"><?= e((string) $r['body']) ?></p>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="reviews__empty u-sm u-muted">
          هنوز نظری برای این محصول ثبت نشده. اگر آن را از ما خریده‌اید، بعد از ارسال سفارش در
          <a href="track.php">صفحهٔ پیگیری سفارش</a> با شمارهٔ پیگیری و موبایلتان نظرتان را بنویسید.
        </p>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- ══════════════════════════════ Pairs well with ═══ -->
    <section class="section section--flush-top" aria-labelledby="rel-title">
      <div class="section__head">
        <div>
          <p class="u-eyebrow">پیشنهاد تکمیلی</p>
          <h2 class="u-h2" id="rel-title" style="margin-top:8px">معمولاً با هم خریده می‌شوند</h2>
        </div>
        <a class="link-more" href="./#featured">
          همه محصولات
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </a>
      </div>
      <div class="grid grid--products" data-related></div>
    </section>
<?php endif; ?>

  </div>

<?php if ($product !== null): ?>
  <!-- On a phone, once the buy button has scrolled away: the product, what
       the current choice costs, and a button that presses the real one - so
       there is one set of rules for what goes into the basket (app.js,
       initProduct). Not on a desktop, where the buy box stays beside the
       gallery. -->
  <div class="buybar" data-buybar hidden>
    <div class="buybar__info">
      <span class="buybar__name"><?= e($pName) ?></span>
      <span class="buybar__meta"><span class="price" data-buybar-price></span><span class="buybar__choice" data-buybar-choice></span></span>
    </div>
    <button class="btn btn--primary buybar__btn" type="button" data-buybar-add>افزودن به سبد</button>
  </div>
<?php endif; ?>
</main>

<!-- ══════════════════════════════════════════ Footer ═══ -->
<?php include __DIR__ . '/partials/chrome-foot.php'; ?>
