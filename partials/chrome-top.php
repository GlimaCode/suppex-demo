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
   =========================================================================== */
if (!defined('SUPPEX_ROOT')) { http_response_code(404); exit; }
?>
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
      <!-- No ® : nothing is registered. -->
      <span class="logo__text">SUPPEX</span>
    </a>

    <nav class="nav" aria-label="ناوبری اصلی">
      <ul class="nav__list">
        <li><a class="nav__link" href="index.html">خانه</a></li>
        <!-- aria-current="page" was hardcoded here. This nav is now shared by
             the product, article, tracking, policy and journal pages, so a
             screen reader was told the user is on the products page on every
             one of them. Nothing sets it now, which is right for all of them:
             none IS the products listing - that lives on the home page. -->
        <li><a class="nav__link" href="index.html#featured">محصولات</a></li>
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