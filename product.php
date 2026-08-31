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
$pImage = $product === null ? '' : (string) ($product['image'] ?? '');
$pPrice = $product === null ? 0  : (int) $product['price'];
$pWas   = ($product === null || empty($product['onSale'])) ? null : (int) $product['compareAt'];
$pStock = $product !== null && !empty($product['inStock']);
$unit   = 'تومان';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<meta name="theme-color" content="#131210">


<link rel="icon" href="assets/icons/favicon.svg" type="image/svg+xml">


<link rel="stylesheet" href="assets/css/tokens.css">
<link rel="stylesheet" href="assets/css/base.css">
<link rel="stylesheet" href="assets/css/components.css">
<link rel="stylesheet" href="assets/css/layout.css">
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
      <a href="index.html">خانه</a>
      <span aria-hidden="true">/</span>
      <a href="index.html#categories">محصولات</a>
    </nav>

    <section class="notfound">
      <p class="notfound__code">۴۰۴</p>
      <h1 class="notfound__title">این محصول دیگر نیست</h1>
      <p class="notfound__text">
        شاید از فهرست برداشته شده باشد، یا لینک ناقص کپی شده باشد.
        همین جنس را می‌خواهید؟ در فهرست محصولات پیدایش کنید یا به ما پیام بدهید.
      </p>
      <div class="notfound__actions">
        <a class="btn btn--primary" href="index.html#categories">دیدن محصولات</a>
        <a class="btn btn--ghost" href="index.html">صفحه اصلی</a>
      </div>
    </section>
<?php else: ?>

    <nav class="breadcrumb" aria-label="مسیر صفحه">
      <a href="index.html">خانه</a>
      <span aria-hidden="true">/</span>
      <a href="index.html#categories">محصولات</a>
      <span aria-hidden="true">/</span>
      <span aria-current="page" data-p-breadcrumb><?= e($pName) ?></span>
    </nav>

    <!-- ═════════════════════════════════════ Product ═══ -->
    <article class="pdp">

      <!-- Gallery -->
      <div class="pdp__gallery">
        <div class="gallery__main media media--1x1 media--lit" style="border-radius:var(--r-xl)">
          <img data-p-image src="<?= e($pImage) ?>" alt="<?= e(trim($pName . " " . $pLatin)) ?>" width="640" height="640">
          <div class="pcard__flags" data-p-flags style="inset-block-start:var(--sp-5);inset-inline:var(--sp-5)"></div>
        </div>
        <div class="gallery__thumbs" data-p-thumbs></div>
      </div>

      <!-- Buy box -->
      <div class="pdp__info">
        <p class="u-eyebrow" data-p-eyebrow><?= e($pBrand !== '' ? $pBrand : setting('shop_name', 'SUPPEX')) ?></p>
        <h1 class="u-h1" data-p-title><?= e($pName) ?></h1>

        <div class="row" style="gap:14px" data-p-rating></div>

        <div class="price price--lg" data-p-price>
          <?php if ($pPrice > 0): ?>
            <?php /* The isolate belongs on the digits alone. On the whole
                     price .num pins the run LTR and تومان lands to the
                     right of the number - the first thing a Persian
                     reader meets. Server-rendered, so this is also what
                     a Telegram preview and Torob read. */ ?>
            <span class="price__now"><span class="num"><?= money($pPrice) ?></span>&nbsp;<span class="price__unit"><?= e($unit) ?></span></span>
            <?php if ($pWas !== null && $pWas > $pPrice): ?>
              <span class="price__was num"><?= money($pWas) ?></span>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <!-- Stock state in the markup, not only as a disabled button: a crawler
             reading text has no other way to tell a listable product from one
             that is out of stock. -->
        <p class="sr-only" data-p-stock><?= $pStock ? 'موجود در انبار' : 'ناموجود' ?></p>

        <p class="u-lead" data-p-desc></p>

        <ul data-p-features style="margin-top:4px"></ul>

        <div class="pdp__field">
          <span class="pdp__field-label">طعم</span>
          <div class="row" style="gap:8px" data-p-flavors></div>
        </div>

        <div class="pdp__field">
          <span class="pdp__field-label">وزن بسته</span>
          <div class="stack" style="--gap:10px" data-p-sizes></div>
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
          <button class="btn btn--primary" type="button" data-p-add>افزودن به سبد خرید</button>
        </div>

        <div class="pdp__assur">
          <!-- The middle item used to read «برگه آزمایش سری تولید», with a
               green tick, on all fifteen products, in the server-rendered
               HTML - while the shop's own returns, FAQ and terms pages say in
               plain Persian that there is no laboratory and no batch is
               tested. What replaces it is the thing he actually does before
               packing, which index.html now describes as well.

               «مرجوعی ۷ روزه» became «۷ روز کاری»: the withdrawal right in
               ماده ۳۷ is in WORKING days, so the old wording was narrower than
               both the law and the shop's own returns page. -->
          <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg> مرجوعی ۷ روز کاری</span>
          <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg> عکس سری تولید پیش از ارسال</span>
          <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg> قوطی پلمب‌شده</span>
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
            <div class="acc__panel"><div><p class="acc__body" data-p-ingredients></p></div></div>
          </div>

          <div class="acc__item">
            <button class="acc__trigger" type="button" aria-expanded="false">
              روش مصرف
              <svg class="acc__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            </button>
            <div class="acc__panel"><div><p class="acc__body" data-p-usage></p></div></div>
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

                   The two shipping numbers stay: those are real, and the
                   server enforces them when it recomputes the order. -->
              ارسال بالای <span class="num">1,500,000</span> تومان رایگان است و در غیر این صورت
              <span class="num">65,000</span> تومان محاسبه می‌شود. زمان ارسال هر سفارش را در
              همان گفتگو می‌گوییم. مرجوعی تا ۷ روز کاری پذیرفته می‌شود؛ شرایط و
              استثناهایش در صفحه مرجوعی و بازگشت وجه نوشته شده است.
            </p></div></div>
          </div>
        </div>
      </div>
    </article>

    <!-- ══════════════════════════════ Pairs well with ═══ -->
    <section class="section section--flush-top" aria-labelledby="rel-title">
      <div class="section__head">
        <div>
          <p class="u-eyebrow">پیشنهاد تکمیلی</p>
          <h2 class="u-h2" id="rel-title" style="margin-top:8px">معمولاً با هم خریده می‌شوند</h2>
        </div>
        <a class="link-more" href="index.html#featured">
          همه محصولات
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </a>
      </div>
      <div class="grid grid--products" data-related></div>
    </section>
<?php endif; ?>

  </div>
</main>

<!-- ══════════════════════════════════════════ Footer ═══ -->
<?php include __DIR__ . '/partials/chrome-foot.php'; ?>
