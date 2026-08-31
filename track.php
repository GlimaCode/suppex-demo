<?php
/* ===========================================================================
   Order lookup - «پیگیری سفارش»
   ---------------------------------------------------------------------------
   The customer transfers money card-to-card and needs somewhere to come back
   to. Code plus the phone that placed the order; see lib/track.php for why
   both.

   noindex: every URL here is either a form or somebody's order. Nothing on this
   page should ever appear in a search result.
   =========================================================================== */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/orders.php';
require_once __DIR__ . '/lib/track.php';
require_once __DIR__ . '/lib/seo.php';

$code  = trim((string) ($_REQUEST['code'] ?? ''));
$phone = trim((string) ($_REQUEST['phone'] ?? ''));
$asked = $code !== '' || $phone !== '';

$order    = null;
$error    = '';
$throttled = false;

if ($asked) {
    if (track_is_throttled()) {
        $throttled = true;
        $error = 'تعداد جست‌وجوهای شما زیاد بوده است. چند دقیقه بعد دوباره تلاش کنید.';
    } else {
        track_record_attempt();
        $order = track_find($code, $phone);
        if ($order === null) {
            /* One message for every kind of miss. Telling them which half was
               wrong would confirm that a code exists. */
            $error = 'سفارشی با این شماره پیگیری و شماره موبایل پیدا نشد.';
        }
    }
}

$title = 'پیگیری سفارش';
$url   = seo_origin() . '/track.php';
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

<title><?= e($title) ?> | <?= e(setting('shop_name', 'SUPPEX')) ?></title>
<meta name="description" content="وضعیت سفارش خود را با شماره پیگیری و شماره موبایل ببینید.">
<meta name="robots" content="noindex, nofollow">
<link rel="canonical" href="<?= e($url) ?>">
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
      <a class="icon-btn" href="track.php" aria-label="پیگیری سفارش">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6"/></svg>
      </a>
      <button class="icon-btn" type="button" data-open="cart" aria-label="سبد خرید">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h2l2.6 11.2a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 2-1.5L21 8H6"/><circle cx="10" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/></svg>
        <span class="icon-btn__count num" data-cart-count hidden>0</span>
      </button>
    </div>
  </div>
</header>

<main id="main">
  <section class="section">
    <div class="container container--narrow">

      <nav class="article__crumbs" aria-label="مسیر صفحه">
        <a href="index.html">خانه</a>
        <span aria-hidden="true">/</span>
        <span>پیگیری سفارش</span>
      </nav>

      <h1 class="article__title" style="margin-bottom:14px">پیگیری سفارش</h1>
      <p class="article__lede" style="margin-bottom:28px">
        شماره پیگیری همان کدی است که بعد از ثبت سفارش گرفتید و در گفتگو برایتان ماند.
      </p>

      <?php /* Filled in by app.js from localStorage when this browser has placed
               an order recently, so the usual visitor does not retype a code
               they were shown once. Hidden until there is one - an empty box
               offering to remember something is worse than no box. */ ?>
      <p class="track__last" data-last-order hidden>
        آخرین سفارش این دستگاه:
        <button type="button" class="track__last-btn num" dir="ltr"
                data-last-order-code data-fill-code></button>
      </p>

      <?php /* POST, not GET: the phone number would otherwise sit in the URL, in
               browser history, in the referrer of anything the page links to,
               and in every access log between here and the host. */ ?>
      <form class="track__form" method="post" action="track.php" novalidate>
        <div class="field">
          <label for="code">شماره پیگیری</label>
          <input type="text" id="code" name="code" dir="ltr" autocomplete="off"
                 placeholder="SPX-000000-XXXX" value="<?= e($code) ?>" required>
        </div>
        <div class="field">
          <label for="phone">شماره موبایل</label>
          <input type="tel" id="phone" name="phone" dir="ltr" inputmode="numeric"
                 autocomplete="tel" placeholder="09xxxxxxxxx" value="<?= e($phone) ?>" required>
          <p class="field__hint">همان شماره‌ای که هنگام ثبت سفارش وارد کردید.</p>
        </div>
        <button class="btn btn--primary" type="submit">دیدن وضعیت</button>
      </form>

      <?php if ($error !== ''): ?>
        <p class="track__error" role="alert"><?= e($error) ?></p>
        <?php if (!$throttled): ?>
          <p class="u-xs u-dim" style="margin-top:10px">
            اگر کد را گم کرده‌اید، در همان گفتگویی که سفارش را ثبت کردید بپرسید.
          </p>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($order !== null): ?>
        <?php $st = (string) $order['status']; ?>
        <section class="track__result" aria-label="وضعیت سفارش">

          <div class="track__head">
            <div>
              <p class="u-eyebrow u-eyebrow--bare">شماره پیگیری</p>
              <p class="track__code num" dir="ltr"><?= e((string) $order['code']) ?></p>
            </div>
            <span class="track__badge track__badge--<?= e($st) ?>">
              <?= e(ORDER_STATUS_FA[$st] ?? $st) ?>
            </span>
          </div>

          <p class="track__note"><?= e(track_status_note($st)) ?></p>

          <?php /* The steps a normal order walks. A cancelled or expired order
                   is not on this path, so it does not get a rail that would
                   imply it is still moving. */ ?>
          <?php if (!in_array($st, ['cancelled', 'expired'], true)):
              $steps = ['new' => 'ثبت شد', 'paid' => 'پرداخت تأیید شد',
                        'shipped' => 'ارسال شد', 'done' => 'تکمیل شد'];
              $order_of = array_keys($steps);
              $at = array_search($st, $order_of, true);
              $at = $at === false ? 0 : $at; ?>
            <ol class="track__rail">
              <?php foreach ($order_of as $i => $key): ?>
                <li class="track__step<?= $i <= $at ? ' is-done' : '' ?>">
                  <span class="track__dot" aria-hidden="true"></span>
                  <span><?= e($steps[$key]) ?></span>
                </li>
              <?php endforeach; ?>
            </ol>
          <?php endif; ?>

          <table class="track__items">
            <caption class="u-sr-only">کالاهای این سفارش</caption>
            <tbody>
            <?php foreach (($order['items'] ?? []) as $it): ?>
              <tr>
                <td>
                  <?= e((string) $it['name_fa']) ?>
                  <?php if (trim((string) ($it['variant_label'] ?? '')) !== ''): ?>
                    <span class="u-xs u-dim"><?= e((string) $it['variant_label']) ?></span>
                  <?php endif; ?>
                </td>
                <td class="num u-nowrap"><?= (int) $it['qty'] ?>×</td>
                <td class="num u-nowrap"><?= money((int) $it['line_total']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td>هزینه ارسال</td>
                <td></td>
                <td class="num u-nowrap">
                  <?= (int) $order['shipping'] === 0 ? 'رایگان' : money((int) $order['shipping']) ?>
                </td>
              </tr>
              <tr class="track__total">
                <td>مبلغ کل</td>
                <td></td>
                <td class="num u-nowrap"><?= money((int) $order['total']) ?> تومان</td>
              </tr>
            </tfoot>
          </table>

          <?php /* Only what the customer needs to recognise their own order.
                   The address is echoed back so a wrong one can be caught while
                   it still matters; nothing else about them is repeated. */ ?>
          <p class="track__ship">
            <span class="u-eyebrow u-eyebrow--bare">تحویل به</span>
            <?= e((string) $order['customer_name']) ?> —
            <?= e((string) $order['address']) ?>
          </p>

        </section>
      <?php endif; ?>

    </div>
  </section>
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
        <div class="payinfo">
          <p class="payinfo__title">پرداخت چطور انجام می‌شود</p>
          <p class="payinfo__body">
            سفارش را در تلگرام ثبت می‌کنید، مبلغ و موجودی تأیید می‌شود، و بعد
            شماره کارت برایتان فرستاده می‌شود. تا وقتی سفارش تأیید نشده،
            پرداختی در کار نیست.
          </p>
        </div>
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
