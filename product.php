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
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<meta name="theme-color" content="#131210">


<link rel="icon" href="assets/icons/favicon.svg" type="image/svg+xml">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800;900&family=Archivo:wght@400;600;700;800&family=Archivo+Black&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">

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
<meta name="twitter:card" content="summary_large_image">

<?php if ($meta['jsonld'] !== ''): ?>
<script type="application/ld+json">
<?= $meta['jsonld'] ?>

</script>
<?php endif; ?>
</head>

<body>
<a class="skip-link" href="#main">پرش به محتوای اصلی</a>

<!-- ══════════════════════════════════════ Announcement ═══ -->
<div class="announce">
  <div class="container announce__inner">
    <span class="announce__item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.6"/><circle cx="17.5" cy="18" r="1.6"/></svg>
      ارسال رایگان بالای <span class="num">1,500,000</span> تومان
    </span>
    <span class="announce__item announce__item--key">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 3l7 3v6c0 4.5-3 8-7 9-4-1-7-4.5-7-9V6l7-3z"/><path d="M9.5 12l1.8 1.8L15 10"/></svg>
      ضمانت اصالت کالا
    </span>
    <span class="announce__item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M21 4L3 11l5 1.8L19 6.5l-8.4 8.1.4 5 2.8-3.4L18.5 19z" fill="currentColor" stroke="none"/></svg>
      ثبت سفارش در تلگرام
    </span>
  </div>
</div>

<!-- ══════════════════════════════════════════ Header ═══ -->
<header class="header" data-header>
  <div class="container header__inner">

    <button class="icon-btn burger" type="button" data-open="nav" aria-label="باز کردن منو">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
    </button>

    <a class="logo" href="index.html" aria-label="SUPPEX — صفحه اصلی">
      <svg class="logo__mark" viewBox="0 0 40 40" fill="none" aria-hidden="true">
        <rect x="1" y="1" width="38" height="38" rx="11" stroke="currentColor" stroke-width="1.6" opacity="0.28"/>
        <rect x="7"  y="16" width="5"  height="8"  rx="2" fill="#F25914"/>
        <rect x="13" y="13" width="4"  height="14" rx="2" fill="currentColor"/>
        <rect x="17" y="18" width="6"  height="4"  rx="2" fill="currentColor"/>
        <rect x="23" y="13" width="4"  height="14" rx="2" fill="currentColor"/>
        <rect x="28" y="16" width="5"  height="8"  rx="2" fill="#F25914"/>
      </svg>
      <span class="logo__text">SUPPEX<sup>®</sup></span>
    </a>

    <nav class="nav" aria-label="ناوبری اصلی">
      <ul class="nav__list">
        <li><a class="nav__link" href="index.html">خانه</a></li>
        <li><a class="nav__link" href="index.html#featured" aria-current="page">محصولات</a></li>
        <li><a class="nav__link" href="index.html#categories">دسته‌بندی‌ها</a></li>
        <li><a class="nav__link" href="index.html#promo">تخفیف‌ها</a></li>
        <li><a class="nav__link" href="index.html#standard">درباره ما</a></li>
        <li><a class="nav__link" href="index.html#contact">تماس با ما</a></li>
      </ul>
    </nav>

    <div class="header__actions">
      <button class="icon-btn" type="button" data-open="search" aria-label="جستجو در محصولات">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
      </button>
      <a class="icon-btn" href="#" aria-label="حساب کاربری">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6"/></svg>
      </a>
      <button class="icon-btn" type="button" data-open="cart" aria-label="سبد خرید">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h2l2.6 11.2a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 2-1.5L21 8H6"/><circle cx="10" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/></svg>
        <span class="icon-btn__count num" data-cart-count hidden>0</span>
      </button>
    </div>
  </div>
</header>

<main id="main" data-product-page>
  <div class="container">

    <nav class="breadcrumb" aria-label="مسیر صفحه">
      <a href="index.html">خانه</a>
      <span aria-hidden="true">/</span>
      <a href="index.html#categories">محصولات</a>
      <span aria-hidden="true">/</span>
      <span aria-current="page" data-p-breadcrumb>وی پروتئین اورجینال</span>
    </nav>

    <!-- ═════════════════════════════════════ Product ═══ -->
    <article class="pdp">

      <!-- Gallery -->
      <div class="pdp__gallery">
        <div class="gallery__main media media--1x1 media--lit" style="border-radius:var(--r-xl)">
          <img data-p-image src="assets/images/products/whey-original.svg" alt="وی پروتئین اورجینال" width="640" height="640">
          <div class="pcard__flags" data-p-flags style="inset-block-start:var(--sp-5);inset-inline:var(--sp-5)"></div>
        </div>
        <div class="gallery__thumbs" data-p-thumbs></div>
      </div>

      <!-- Buy box -->
      <div class="pdp__info">
        <p class="u-eyebrow" data-p-eyebrow>SUPPEX</p>
        <h1 class="u-h1" data-p-title>وی پروتئین اورجینال</h1>

        <div class="row" style="gap:14px" data-p-rating></div>

        <div class="price price--lg" data-p-price></div>

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
          <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg> مرجوعی ۷ روزه</span>
          <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg> برگه آزمایش سری تولید</span>
          <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg> ارسال از انبار تهران</span>
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
              برگه آزمایشگاه
              <svg class="acc__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            </button>
            <div class="acc__panel"><div><p class="acc__body">
              این قوطی از سری تولید <span class="num" dir="ltr">۲۶۰۸-V</span> است. در مرداد ۱۴۰۵ آزمایشگاهی
              خارج از مجموعه ما خلوص، فلزات سنگین و بار میکروبی آن را بررسی کرده و نتیجه کامل در دسترس است.
              <a href="#" style="color:var(--amber);text-decoration:underline;text-underline-offset:3px">دانلود گواهی (PDF)</a>
            </p></div></div>
          </div>

          <div class="acc__item">
            <button class="acc__trigger" type="button" aria-expanded="false">
              ارسال و مرجوعی
              <svg class="acc__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            </button>
            <div class="acc__panel"><div><p class="acc__body">
              سفارش‌های ثبت‌شده تا ساعت ۱۵ همان روز ارسال می‌شود. ارسال بالای
              <span class="num">1,500,000</span> تومان رایگان است و در غیر این صورت
              <span class="num">65,000</span> تومان محاسبه می‌شود. مرجوعی تا ۷ روز پذیرفته می‌شود، به شرط آنکه پلمب بسته باز نشده باشد.
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

  </div>
</main>

<!-- ══════════════════════════════════════════ Footer ═══ -->
<footer class="footer" id="contact">
  <div class="container">

    <div class="newsletter">
      <div>
        <h2 class="u-h2" style="font-size:clamp(1.5rem,1.1rem+1.6vw,2.2rem)">
          <span class="num">10</span>٪ تخفیف اولین سفارش
        </h2>
        <p class="u-sm u-muted" style="margin-top:10px">
          خبر محصولات جدید، موجودی مجدد و نکات تمرینی. بدون هرزنامه — هر زمان بخواهید لغو عضویت کنید.
        </p>
      </div>
      <form class="newsletter__form" data-newsletter novalidate>
        <label class="sr-only" for="nl-email">نشانی ایمیل</label>
        <input class="input" id="nl-email" type="email" name="email" placeholder="you@example.com" dir="ltr" required>
        <button class="btn btn--primary" type="submit">عضویت</button>
      </form>
    </div>

    <div class="footer__cols">
      <div class="footer__col">
        <a class="logo" href="index.html" aria-label="SUPPEX">
          <svg class="logo__mark" viewBox="0 0 40 40" fill="none" aria-hidden="true">
            <rect x="1" y="1" width="38" height="38" rx="11" stroke="currentColor" stroke-width="1.6" opacity="0.28"/>
            <rect x="7"  y="16" width="5"  height="8"  rx="2" fill="#F25914"/>
            <rect x="13" y="13" width="4"  height="14" rx="2" fill="currentColor"/>
            <rect x="17" y="18" width="6"  height="4"  rx="2" fill="currentColor"/>
            <rect x="23" y="13" width="4"  height="14" rx="2" fill="currentColor"/>
            <rect x="28" y="16" width="5"  height="8"  rx="2" fill="#F25914"/>
          </svg>
          <span class="logo__text">SUPPEX<sup>®</sup></span>
        </a>
        <p class="u-sm u-muted" style="margin-top:14px;max-width:34ch">
          مکمل ورزشی با مقدار مؤثر و برگه آزمایش مستقل. ساخته‌شده برای باشگاه، اثبات‌شده در آزمایشگاه.
        </p>
        <div class="socials">
          <a href="#" aria-label="اینستاگرام"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r="1.1" fill="currentColor" stroke="none"/></svg></a>
          <a href="#" aria-label="تلگرام"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M21 4L3 11l5 1.8L19 6.5l-8.4 8.1.4 5 2.8-3.4L18.5 19z"/></svg></a>
          <a href="#" aria-label="واتس‌اپ"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20l1.2-3.8A8 8 0 1 1 8.6 19z"/><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5"/></svg></a>
          <a href="#" aria-label="آپارات"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="5" width="18" height="14" rx="4"/><path d="M11 9.8l4 2.2-4 2.2z" fill="currentColor" stroke="none"/></svg></a>
        </div>
      </div>

      <nav class="footer__col" aria-labelledby="f-shop">
        <h3 id="f-shop">فروشگاه</h3>
        <ul>
          <li><a href="product.html?p=whey-original">وی پروتئین</a></li>
          <li><a href="product.html?p=creatine-mono">کراتین</a></li>
          <li><a href="product.html?p=mass-builder">افزایش وزن</a></li>
          <li><a href="product.html?p=pre-blast">قبل تمرین</a></li>
          <li><a href="product.html?p=bcaa-211">آمینو و BCAA</a></li>
          <li><a href="product.html?p=multi-sport">ویتامین‌ها</a></li>
        </ul>
      </nav>

      <nav class="footer__col" aria-labelledby="f-company">
        <h3 id="f-company">شرکت</h3>
        <ul>
          <li><a href="index.html#standard">استاندارد ما</a></li>
          <li><a href="#">گواهی آزمایشگاه</a></li>
          <li><a href="#">مجله ساپکس</a></li>
          <li><a href="#">فرصت‌های شغلی</a></li>
          <li><a href="#">همکاری در فروش</a></li>
        </ul>
      </nav>

      <div class="footer__col">
        <h3>پشتیبانی</h3>
        <ul>
          <li><a href="tel:+982188776655" dir="ltr" class="num">021-88776655</a></li>
          <li><a href="mailto:info@suppex.ir" dir="ltr">info@suppex.ir</a></li>
          <li><a href="#">رویه ارسال</a></li>
          <li><a href="#">مرجوعی و بازگشت وجه</a></li>
          <li><a href="#">سؤالات متداول</a></li>
        </ul>
        <div class="trustmarks" aria-label="نمادهای اعتماد">
          <span class="trustmark">نماد<br>اعتماد<br>الکترونیکی</span>
          <span class="trustmark">ساماندهی</span>
          <span class="trustmark">درگاه<br>امن</span>
        </div>
      </div>
    </div>

    <div class="footer__mark" aria-hidden="true">SUPPEX</div>

    <div class="footer__legal">
      <span>© <span class="num" data-year>2026</span> شرکت مکمل‌های ورزشی ساپکس — تمام حقوق محفوظ است.</span>
      <nav aria-label="پیوندهای حقوقی">
        <a href="#">قوانین و مقررات</a>
        <a href="#">حریم خصوصی</a>
        <a href="#">شرایط استفاده</a>
      </nav>
    </div>

  </div>
</footer>

<!-- ═══════════════════════════════════════ Overlays ═══ -->
<div class="scrim" data-scrim></div>

<nav class="mobile-nav" data-mobile-nav aria-label="ناوبری موبایل">
  <div class="row row--between" style="margin-bottom:14px">
    <span class="logo__text" style="font-size:1.1rem">SUPPEX</span>
    <button class="icon-btn" type="button" data-close aria-label="بستن منو">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
  </div>
  <a class="mobile-nav__link" href="index.html" data-close>خانه</a>
  <a class="mobile-nav__link" href="index.html#featured" data-close>محصولات</a>
  <a class="mobile-nav__link" href="index.html#categories" data-close>دسته‌بندی‌ها</a>
  <a class="mobile-nav__link" href="index.html#promo" data-close>تخفیف‌ها</a>
  <a class="mobile-nav__link" href="index.html#standard" data-close>درباره ما</a>
  <a class="mobile-nav__link" href="#contact" data-close>تماس با ما</a>
</nav>

<aside class="drawer" data-drawer aria-label="سبد خرید">
  <div class="drawer__head">
    <h2 class="u-h3">سبد خرید</h2>
    <button class="icon-btn" type="button" data-close aria-label="بستن سبد خرید">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
  </div>
  <div class="drawer__body" data-cart-items></div>
  <div class="drawer__foot">
    <p class="drawer__hint" data-cart-hint></p>
    <div class="drawer__row">
      <span class="u-muted">جمع کالاها</span>
      <strong><span class="num" data-cart-subtotal>0</span> تومان</strong>
    </div>
    <div class="drawer__row">
      <span class="u-muted">هزینه ارسال</span>
      <strong data-cart-shipping>—</strong>
    </div>
    <div class="drawer__row drawer__row--total">
      <span>مبلغ قابل پرداخت</span>
      <strong><span class="num" data-cart-total>0</span> تومان</strong>
    </div>
    <button class="btn btn--primary btn--block" type="button" data-checkout disabled>ادامه فرآیند خرید</button>
  </div>
</aside>

<div class="search" data-search role="dialog" aria-modal="true" aria-label="جستجوی محصولات">
  <div class="search__panel">
    <div class="search__field">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
      <label class="sr-only" for="search-input">جستجو</label>
      <input id="search-input" type="search" data-search-input placeholder="مثلاً وی پروتئین، کراتین یا BCAA…" autocomplete="off">
      <button class="icon-btn" type="button" data-close aria-label="بستن جستجو" style="margin-inline-end:6px">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
      </button>
    </div>
    <div class="search__results" data-search-results>
      <p class="u-sm u-dim" style="padding:12px 4px">نام محصول، برند یا دسته‌بندی را بنویسید.</p>
    </div>
  </div>
</div>

<div class="toast-stack" data-toasts aria-live="polite"></div>

<script src="assets/js/config.js"></script>
<script src="assets/js/catalog.js"></script>
<script src="assets/js/repo.js"></script>
<script src="assets/js/store.js"></script>
<script src="assets/js/ui.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
