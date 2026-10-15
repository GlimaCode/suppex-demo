<?php
/* ===========================================================================
   Page chrome, top: the announcement strip and the header.
   ---------------------------------------------------------------------------
   This was copied into five templates, and article.php's own comment said so:
   "four copies of a header is a maintenance debt and should become one
   include". The copies were still byte-identical, which is luck rather than
   discipline - the next edit to one of them was the one that would break it.

   product.html and index.html cannot include this: they are the static
   no-backend mode of the site and have no PHP. product.html's copy is pinned
   against this file by tests/test-chrome.php, so a change here that is not
   mirrored there fails the suite rather than drifting quietly. index.html's
   header is genuinely different - it is the home page, so its nav anchors are
   same-page rather than absolute.

   The home page is linked as «./», its one address: /index.html is a 301 to
   «/» on the live site (.htaccess), and a link to a redirect costs every
   click a round trip and every crawler a hop. product.html keeps
   «index.html», because the demo also opens it straight from the disk, where
   «./» is a folder listing; the test compares the two as the same link.
   =========================================================================== */
if (!defined('SUPPEX_ROOT')) { http_response_code(404); exit; }
/* The menu's two lists (lib/goals.php): the goals with something on sale and
   the category guides. chrome-foot.php draws the same in the phone's panel. */
require_once dirname(__DIR__) . '/lib/goals.php';
$chromeGoals  = nav_goal_items();
$chromeGuides = nav_guide_items();
?>
<body>
<a class="skip-link" href="#main">پرش به محتوای اصلی</a>

<!-- ══════════════════════════════════════ Announcement ═══ -->
<div class="announce">
  <div class="container announce__inner">
    <span class="announce__item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.6"/><circle cx="17.5" cy="18" r="1.6"/></svg>
      <span data-shipping-announcement><?= storefront_shipping_html() ?></span>
    </span>
    <span class="announce__item announce__item--key">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 3l7 3v6c0 4.5-3 8-7 9-4-1-7-4.5-7-9V6l7-3z"/><path d="M9.5 12l1.8 1.8L15 10"/></svg>
      کالای اصل و پلمب‌شده
    </span>
    <span class="announce__item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M21 4L3 11l5 1.8L19 6.5l-8.4 8.1.4 5 2.8-3.4L18.5 19z" fill="currentColor" stroke="none"/></svg>
      ثبت سفارش در سایت
    </span>
  </div>
</div>

<!-- ══════════════════════════════════════════ Header ═══ -->
<header class="header" data-header>
  <div class="container header__inner">

    <button class="icon-btn burger" type="button" data-open="nav" aria-label="باز کردن منو">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
    </button>

    <a class="logo" href="./" aria-label="SUPPEX — صفحه اصلی">
      <!-- The orange S is the mark and the word's first letter: S + UPPEX,
           one S (the owner's choice, 2026-10-05). Below 340px the word goes
           and the S stays. No ® : nothing is registered. -->
      <svg class="logo__mark" viewBox="0 0 95.04 96" aria-hidden="true" focusable="false"><path fill="#F25914" d="M41.9 0 H95.04 L86.5 20.57 H38.67 L34.56 37.71 H85.99 L76.53 77.14 L53.14 96 H0 L8.54 75.43 H56.37 L60.48 58.29 H9.05 L18.51 18.86 Z"/></svg>
      <svg class="logo__text" viewBox="0 0 460 96" fill="currentColor" aria-hidden="true" focusable="false"><path d="M0 0 L24 0 L24 78 L60 78 L60 0 L84 0 L84 96 L0 96 Z"/><path fill-rule="evenodd" d="M94 0 L178 0 L178 62 L118 62 L118 96 L94 96 Z M118 18 L154 18 L154 44 L118 44 Z"/><path fill-rule="evenodd" d="M188 0 L272 0 L272 62 L212 62 L212 96 L188 96 Z M212 18 L248 18 L248 44 L212 44 Z"/><path d="M282 0 L357 0 L366 18 L306 18 L306 39 L344 39 L353 57 L306 57 L306 78 L357 78 L366 96 L282 96 Z"/><path d="M376 0 L410 0 L458 96 L424 96 Z M434 0 L460 0 L402 96 L376 96 Z"/></svg>
    </a>

    <nav class="nav" aria-label="ناوبری اصلی">
      <ul class="nav__list">
        <li><a class="nav__link" href="./">خانه</a></li>
        <!-- aria-current="page" was hardcoded here. This nav is now shared by
             the product, article, tracking, policy and journal pages, so a
             screen reader was told the user is on the products page on every
             one of them. Nothing sets it now, which is right for all of them:
             none IS the products listing - that lives on the home page. -->
        <?= nav_dropdown_html('nav-goals', 'هدف‌ها', $chromeGoals) ?>
        <li><a class="nav__link" href="./#categories">دسته‌بندی‌ها</a></li>
        <li><a class="nav__link" href="brands.php">برندها</a></li>
        <?= nav_dropdown_html('nav-guides', 'راهنماها', $chromeGuides) ?>
        <li><a class="nav__link" href="./#standard">درباره ما</a></li>
        <li><a class="nav__link" href="./#contact">تماس با ما</a></li>
      </ul>
    </nav>

    <div class="header__actions">
      <button class="icon-btn" type="button" data-open="search" aria-label="جستجو در محصولات">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
      </button>
      <a class="icon-btn" href="track.php" aria-label="پیگیری سفارش">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6"/></svg>
        <!-- Shown by app.js when the shop has moved this customer's order on
             since this device last looked. hidden rather than absent, so a
             browser with no JavaScript never shows an empty mark. -->
        <span class="icon-btn__dot" data-order-dot hidden
              aria-label="خبر تازه درباره سفارش شما" role="status"></span>
      </a>
      <button class="icon-btn" type="button" data-open="cart" aria-label="سبد خرید">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h2l2.6 11.2a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 2-1.5L21 8H6"/><circle cx="10" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/></svg>
        <span class="icon-btn__count num" data-cart-count hidden>0</span>
      </button>
    </div>
  </div>
</header>