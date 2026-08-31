<?php
/* ===========================================================================
   Page chrome, bottom: the footer, the overlays and the scripts.
   ---------------------------------------------------------------------------
   Every link in the footer used to be href="#". Fixing that across five
   hand-maintained copies is how three of them end up different, so the copies
   became this file first.

   See the note in chrome-top.php about product.html and index.html.
   =========================================================================== */
if (!defined('SUPPEX_ROOT')) { http_response_code(404); exit; }
?>
<footer class="footer" id="contact">
  <div class="container">

    <!-- A newsletter form offering «۱۰٪ تخفیف اولین سفارش» stood here. No
         coupon mechanism exists anywhere in this codebase, so the discount
         could never be issued, and the submit handler answered «ایمیل شما ثبت
         شد» while discarding the address. A restock list is worth building for
         a shop that now counts its stock - with a table behind it. -->

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
          <!-- The ® is gone: nothing is registered. ™ would be free to use
               but claims a trademark nobody has asserted either. -->
          <span class="logo__text">SUPPEX</span>
        </a>
        <p class="u-sm u-muted" style="margin-top:14px;max-width:34ch">
          مکمل اصل برندهای معتبر، پلمب‌شده و مستقیم از دبی. سفارش در تلگرام، پیگیری با کد.
        </p>
        <!-- Every icon was href="#" on a shop whose customers arrive from
             Instagram and Telegram. They are filled by app.js from ?r=config
             and each one stays hidden until its URL is set in the panel, so an
             account the owner does not have never shows a dead icon - and one
             he opens later needs no developer. hidden, not display:none, so it
             is out of the accessibility tree too. -->
        <div class="socials" data-socials>
          <a data-social="instagram" aria-label="اینستاگرام" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r="1.1" fill="currentColor" stroke="none"/></svg></a>
          <a data-social="telegram" aria-label="تلگرام" hidden><svg viewBox="0 0 24 24" fill="currentColor"><path d="M21 4L3 11l5 1.8L19 6.5l-8.4 8.1.4 5 2.8-3.4L18.5 19z"/></svg></a>
          <a data-social="whatsapp" aria-label="واتس‌اپ" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20l1.2-3.8A8 8 0 1 1 8.6 19z"/><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5"/></svg></a>
          <a data-social="aparat" aria-label="آپارات" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="5" width="18" height="14" rx="4"/><path d="M11 9.8l4 2.2-4 2.2z" fill="currentColor" stroke="none"/></svg></a>
        </div>
      </div>

      <nav class="footer__col" aria-labelledby="f-shop">
        <h3 id="f-shop">فروشگاه</h3>
        <ul>
          <!-- These six labels were always the six CATEGORIES. They linked to
               one arbitrary product each because a category had no URL; now
               they point at what they are named after. Also sturdier: a
               product slug can be renamed or the product discontinued, and
               then the footer of every page on the site points at a 404. -->
          <li><a href="category.php?c=protein">پروتئین</a></li>
          <li><a href="category.php?c=creatine">کراتین</a></li>
          <li><a href="category.php?c=mass-gainer">افزایش وزن</a></li>
          <li><a href="category.php?c=pre-workout">قبل تمرین</a></li>
          <li><a href="category.php?c=amino">آمینو و BCAA</a></li>
          <li><a href="category.php?c=vitamins">ویتامین و مکمل</a></li>
        </ul>
      </nav>

      <nav class="footer__col" aria-labelledby="f-company">
        <!-- «شرکت» was the wrong word before the links were: there is no
             registered company. Three of the five entries here were removed
             rather than built - «گواهی آزمایشگاه» (no lab, no certificates),
             «فرصت‌های شغلی» and «همکاری در فروش» (nobody to hire, no affiliate
             scheme). A page saying "we have no vacancies" is a worse answer
             than not asking the question. -->
        <h3 id="f-company">درباره ساپکس</h3>
        <ul>
          <li><a href="index.html#standard">استاندارد ما</a></li>
          <li><a href="journal.php">مجله ساپکس</a></li>
          <li><a href="page.php?p=faq">سؤالات متداول</a></li>
          <li><a href="page.php?p=terms">قوانین و شرایط استفاده</a></li>
        </ul>
      </nav>

      <div class="footer__col">
        <h3>پشتیبانی</h3>
        <ul>
          <!-- Order tracking first: it is the thing somebody who has already
               paid actually came back for. -->
          <li><a href="track.php">پیگیری سفارش</a></li>
          <li><a href="page.php?p=shipping">رویه ارسال</a></li>
          <li><a href="page.php?p=returns">مرجوعی و بازگشت وجه</a></li>
          <li><a href="page.php?p=privacy">حریم خصوصی</a></li>
          <!-- Before buying, not only after. This shop's whole funnel is a
               chat, so the chat belongs in the footer rather than only behind
               the checkout button. Filled and unhidden by app.js from the
               channel the owner has actually configured. -->
          <li hidden data-ask><a>پرسیدن قبل از خرید</a></li>
          <!-- A landline (021-88776655) and info@suppex.ir stood here. The
               owner confirmed neither is real; both are wired to settings and
               appear the moment he fills them in, rather than being deleted
               and needing a developer later. -->
          <li hidden data-contact="phone"><a dir="ltr" class="num"></a></li>
          <li hidden data-contact="email"><a dir="ltr"></a></li>
        </ul>
        <!-- These were three empty dashed boxes. One said «درگاه امن», and
             config.js states in its own words that this phase deliberately has
             no payment gateway - so the badge advertised something that does
             not exist. The other two were seals the shop has not been granted.
             What replaces them is the thing that is both true and reassuring:
             how paying actually works here.

             When نماد اعتماد الکترونیکی and ساماندهی are really issued, their
             own script tags go back in this block - a trust seal is a code
             snippet from the issuer, never a box we draw ourselves. -->
        <div class="payinfo">
          <p class="payinfo__title">پرداخت چطور انجام می‌شود</p>
          <p class="payinfo__body">
            سفارش را در تلگرام ثبت می‌کنید، مبلغ و موجودی تأیید می‌شود، و بعد
            شماره کارت برایتان فرستاده می‌شود. تا وقتی سفارش تأیید نشده،
            پرداختی در کار نیست.
          </p>
        </div>
      <!-- There was a fourth </div> here, closing .container early so the
           watermark and the legal row below rendered OUTSIDE it and lost the
           page's max-width. It was in article.php, product.php, track.php and
           product.html - four of the five copies - and index.html was the one
           that had it right. Which is the argument for this file existing:
           four hand-maintained copies drifted, and nothing noticed until
           legal.closest('.container') came back null in a browser. -->
      </div>
    </div>

    <div class="footer__mark" aria-hidden="true">SUPPEX</div>

    <div class="footer__legal">
      <!-- «شرکت مکمل‌های ورزشی ساپکس» named a company that is not
           registered. Confirmed with the owner: it is one person trading under
           a name, which is what this now says. -->
      <span>© <span class="num" data-year>2026</span> ساپکس</span>
      <nav aria-label="پیوندهای حقوقی">
        <!-- «قوانین و مقررات» and «شرایط استفاده» were two links to the same
             document that did not exist. Now one link to one that does. -->
        <a href="page.php?p=terms">قوانین و شرایط استفاده</a>
        <a href="page.php?p=privacy">حریم خصوصی</a>
        <a href="page.php?p=returns">مرجوعی</a>
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