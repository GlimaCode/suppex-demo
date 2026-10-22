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

    <!-- Measured at 1327px tall on a 640px screen before this rewrite. Three of
         the twenty-five links were in a column AND in the legal row below it;
         a bordered payment box stretched its whole grid row to 580px and left
         the column beside it two thirds empty; and a 128px ghost wordmark sat
         under the lot. The destinations are all still here, once each. -->
    <div class="footer__cols">
      <div class="footer__col">
        <a class="logo" href="./" aria-label="SUPPEX">
          <!-- The ® is gone: nothing is registered. ™ would be free to use
               but claims a trademark nobody has asserted either. -->
          <svg class="logo__mark" viewBox="0 0 95.04 96" aria-hidden="true" focusable="false"><path fill="#F25914" d="M41.9 0 H95.04 L86.5 20.57 H38.67 L34.56 37.71 H85.99 L76.53 77.14 L53.14 96 H0 L8.54 75.43 H56.37 L60.48 58.29 H9.05 L18.51 18.86 Z"/></svg>
          <svg class="logo__text" viewBox="0 0 460 96" fill="currentColor" aria-hidden="true" focusable="false"><path d="M0 0 L24 0 L24 78 L60 78 L60 0 L84 0 L84 96 L0 96 Z"/><path fill-rule="evenodd" d="M94 0 L178 0 L178 62 L118 62 L118 96 L94 96 Z M118 18 L154 18 L154 44 L118 44 Z"/><path fill-rule="evenodd" d="M188 0 L272 0 L272 62 L212 62 L212 96 L188 96 Z M212 18 L248 18 L248 44 L212 44 Z"/><path d="M282 0 L357 0 L366 18 L306 18 L306 39 L344 39 L353 57 L306 57 L306 78 L357 78 L366 96 L282 96 Z"/><path d="M376 0 L410 0 L458 96 L424 96 Z M434 0 L460 0 L402 96 L376 96 Z"/></svg>
        </a>
        <p class="u-sm u-muted" style="margin-top:14px;max-width:34ch">
          مکمل اورجینال برندهای معتبر، پلمب‌شده و مستقیم از دبی. ثبت سفارش در سایت، پرداخت کارت‌به‌کارت و پیگیری با کد.
        </p>
        <!-- Every icon was href="#" on a shop whose customers arrive from
             Instagram and Telegram. They are filled by app.js from ?r=config
             and each one stays hidden until its URL is set in the panel, so an
             account the owner does not have never shows a dead icon - and one
             opened later needs no developer. hidden, not display:none, so it
             is out of the accessibility tree too.

             Three of these marks were wrong rather than merely plain. WhatsApp
             was a speech bubble with an arc where the handset belongs;
             Telegram was a solid quadrilateral with no fold, so the plane read
             as a sliver at 17px; and Aparat was a play triangle inside a
             rounded rectangle, which is YouTube's mark and points a visitor at
             the wrong brand entirely. Bale is new: the shop takes orders
             there, the panel has had a field for it all along, and the footer
             had nowhere to put it. -->
        <div class="socials" data-socials>
          <a data-social="instagram" aria-label="اینستاگرام" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r="1.1" fill="currentColor" stroke="none"/></svg></a>
          <a data-social="telegram" aria-label="تلگرام" hidden><svg viewBox="0 0 24 24" fill="currentColor"><path d="M21.6 3.2 2.6 10.5c-.8.3-.8 1.4 0 1.7l4.5 1.6 1.8 5.5c.2.7 1.1.9 1.6.3l2.4-2.7 4.6 3.4c.6.4 1.4.1 1.5-.6l3-14.7c.2-.8-.6-1.5-1.4-1.2zM8.6 13.1l9.2-5.6-7.4 6.9-.3 3.3-1.5-4.6z"/></svg></a>
          <a data-social="bale" aria-label="بله" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.5 8.5 0 0 1-12.3 7.6L3 21l1.9-5.6A8.5 8.5 0 1 1 21 11.5z"/><path d="M8.3 11.6l2.6 2.6 4.8-5"/></svg></a>
          <a data-social="whatsapp" aria-label="واتس‌اپ" hidden><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12.03 2A9.9 9.9 0 0 0 3.6 17.1L2.05 21.4a.5.5 0 0 0 .62.63l4.42-1.5A9.9 9.9 0 1 0 12.03 2zm0 1.8a8.1 8.1 0 1 1-4.2 15.03.9.9 0 0 0-.75-.08l-2.72.92.95-2.62a.9.9 0 0 0-.08-.78A8.1 8.1 0 0 1 12.03 3.8zM8.9 7.3c-.2 0-.5.07-.77.36-.26.29-1 .98-1 2.38s1.03 2.76 1.17 2.95c.14.2 1.98 3.15 4.9 4.29 2.42.94 2.92.76 3.45.71.53-.05 1.7-.69 1.94-1.36.24-.67.24-1.24.17-1.36-.07-.12-.26-.2-.55-.34-.29-.15-1.7-.84-1.96-.93-.26-.1-.46-.15-.65.14-.19.29-.74.93-.91 1.12-.17.2-.34.22-.63.07-.29-.14-1.22-.45-2.32-1.43-.86-.76-1.44-1.7-1.6-1.99-.17-.29-.02-.44.13-.59.13-.13.29-.34.43-.5.15-.17.19-.29.29-.49.1-.19.05-.36-.02-.5-.07-.15-.64-1.57-.9-2.14-.22-.5-.44-.44-.6-.45l-.51-.01z"/></svg></a>
          <a data-social="aparat" aria-label="آپارات" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="7.6"/><circle cx="12" cy="12" r="2.4"/><g fill="currentColor" stroke="none"><circle cx="12" cy="2.6" r="2.2"/><circle cx="12" cy="21.4" r="2.2"/><circle cx="2.6" cy="12" r="2.2"/><circle cx="21.4" cy="12" r="2.2"/></g></svg></a>
        </div>
        <!-- The ways to reach the shop, under its social circles (moved out of
             «راهنما و پشتیبانی» at the owner's request, 2026-10-05). Before
             buying, not only after: this shop's whole funnel is a chat, so the
             chat belongs here rather than only behind the checkout button.
             app.js fills and unhides each row from the panel's settings; one
             that is not filled in is never printed. -->
        <ul class="footer__contact">
          <li hidden data-ask><a>پرسیدن قبل از خرید</a></li>
          <li hidden data-contact="phone"><a dir="ltr" class="num"></a></li>
          <li hidden data-contact="email"><a dir="ltr"></a></li>
        </ul>
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

      <nav class="footer__col" aria-labelledby="f-help">
        <!-- «درباره ساپکس» and «پشتیبانی» were two columns holding eight links
             between them, three of which were repeated in the legal row below.
             They are one column now. «استاندارد ما» is the only destination
             that did not survive: it was an anchor to a section of the home
             page, which is not what somebody opens a footer looking for. -->
        <h3 id="f-help">راهنما و پشتیبانی</h3>
        <ul>
          <!-- Order tracking first: it is the thing somebody who has already
               paid actually came back for. -->
          <li><a href="track.php">پیگیری سفارش</a></li>
          <li><a href="page.php?p=shipping">رویه ارسال</a></li>
          <li><a href="page.php?p=returns">مرجوعی و بازگشت وجه</a></li>
          <li><a href="journal.php">مجله ساپکس</a></li>
        </ul>
        <p class="u-xs u-muted" style="margin-top:12px">
          تهران با پیک: ارسال در همان روز؛ تیپاکس: حدود ۲ روز؛ سایر استان‌ها: ۲ تا ۳ روز. هماهنگی پس از تأیید پرداخت و موجودی.<br>
          مرجوعی طبق سیاست فروشگاه فقط با باز نشدن محصول و اثبات غیراصل‌بودن آن پذیرفته می‌شود؛ حقوق قانونی خریدار محفوظ است.
        </p>
      </nav>

      <div class="footer__col">
        <!-- These were three empty dashed boxes. One said «درگاه امن», and
             config.js states in its own words that this phase deliberately has
             no payment gateway - so the badge advertised something that does
             not exist. The other two were seals the shop has not been granted.
             What replaces them is the thing that is both true and reassuring:
             how paying actually works here.

             It used to be a bordered box hanging off the bottom of the support
             column, which stretched that grid row to 580px on a phone. Same
             words, its own column.

             When نماد اعتماد الکترونیکی and ساماندهی are really issued, their
             own script tags go back in this block - a trust seal is a code
             snippet from the issuer, never a box we draw ourselves. -->
        <h3>پرداخت</h3>
        <p>
          پس از ثبت سفارش در سایت، کد پیگیری، مبلغ و شماره کارت را در صفحهٔ پرداخت
          می‌بینید. کارت‌به‌کارت واریز کنید و رسید را از همان صفحه یا بخش پیگیری سفارش
          بفرستید. پرداخت پس از بررسی فروشگاه تأیید می‌شود.
        </p>
        <a href="page.php?p=faq">راهنمای خرید و پرداخت</a>
      </div>
    </div>

    <!-- An oversized ghost «SUPPEX» wordmark stood here, 128px tall on a phone
         and up to 208px on a desktop. In a footer the owner had just called too
         long, decoration is the first thing to go. -->

    <div class="footer__legal">
      <!-- «شرکت مکمل‌های ورزشی ساپکس» named a company that is not
           registered. Confirmed with the owner: it is one person trading under
           a name, which is what this now says. -->
      <span>© <span class="num" data-year>2026</span> ساپکس</span>
      <nav aria-label="پیوندهای حقوقی">
        <!-- «قوانین و مقررات» and «شرایط استفاده» were two links to the same
             document that did not exist. «مرجوعی» was a third link to a page
             the column above already reaches. -->
        <a href="page.php?p=terms">قوانین و شرایط استفاده</a>
        <a href="page.php?p=privacy">حریم خصوصی</a>
      </nav>
    </div>

  </div>
</footer>

<!-- ═══════════════════════════════════════ Overlays ═══ -->
<div class="scrim" data-scrim></div>

<nav class="mobile-nav" data-mobile-nav aria-label="ناوبری موبایل">
  <div class="row row--between" style="margin-bottom:14px">
    <svg class="mobile-nav__logo" viewBox="0 0 561.04 96" fill="currentColor" role="img" aria-label="SUPPEX"><path fill="#F25914" d="M41.9 0 H95.04 L86.5 20.57 H38.67 L34.56 37.71 H85.99 L76.53 77.14 L53.14 96 H0 L8.54 75.43 H56.37 L60.48 58.29 H9.05 L18.51 18.86 Z"/><path d="M101.04 0 L125.04 0 L125.04 78 L161.04 78 L161.04 0 L185.04 0 L185.04 96 L101.04 96 Z"/><path fill-rule="evenodd" d="M195.04 0 L279.04 0 L279.04 62 L219.04 62 L219.04 96 L195.04 96 Z M219.04 18 L255.04 18 L255.04 44 L219.04 44 Z"/><path fill-rule="evenodd" d="M289.04 0 L373.04 0 L373.04 62 L313.04 62 L313.04 96 L289.04 96 Z M313.04 18 L349.04 18 L349.04 44 L313.04 44 Z"/><path d="M383.04 0 L458.04 0 L467.04 18 L407.04 18 L407.04 39 L445.04 39 L454.04 57 L407.04 57 L407.04 78 L458.04 78 L467.04 96 L383.04 96 Z"/><path d="M477.04 0 L511.04 0 L559.04 96 L525.04 96 Z M535.04 0 L561.04 0 L503.04 96 L477.04 96 Z"/></svg>
    <button class="icon-btn" type="button" data-close aria-label="بستن منو">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
  </div>
  <?php /* The lists chrome-top.php drew for the desktop menu, or the same
           worked out here when this file is drawn on its own. */
        require_once dirname(__DIR__) . '/lib/goals.php';
        $chromeGoals  = $chromeGoals ?? nav_goal_items();
        $chromeGuides = $chromeGuides ?? nav_guide_items(); ?>
  <a class="mobile-nav__link" href="./" data-close>خانه</a>
  <?= nav_panel_group_html('هدف‌ها', $chromeGoals) ?>
  <a class="mobile-nav__link" href="./#categories" data-close>دسته‌بندی‌ها</a>
  <a class="mobile-nav__link" href="brands.php" data-close>برندها</a>
  <?= nav_panel_group_html('راهنماها', $chromeGuides) ?>
  <a class="mobile-nav__link" href="./#standard" data-close>درباره ما</a>
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

<?php
/* The answers this page's script is about to ask the API for, sent with the
   page (lib/boot.php): the shop's settings always, and whatever the page put
   in $bootRequests before including this file. Before the scripts, which
   read it when they start.

   Then the scripts, each under a URL that carries its version (asset() in
   lib/assets.php): a new upload is a new URL, so .htaccess can let browsers
   keep them for a year instead of asking on every page. */
require_once dirname(__DIR__) . '/lib/boot.php';
require_once dirname(__DIR__) . '/lib/assets.php';
echo boot_script($bootRequests ?? []), "\n";
foreach (['config', 'catalog', 'repo', 'store', 'ui', 'app'] as $script) {
    echo '<script src="', e(asset('assets/js/' . $script . '.js')), '"></script>', "\n";
}
?>
</body>
</html>