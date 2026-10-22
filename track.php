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
require_once __DIR__ . '/lib/receipts.php';
require_once __DIR__ . '/lib/reviews.php';
require_once __DIR__ . '/lib/seo.php';

$code  = trim((string) ($_REQUEST['code'] ?? ''));
$phone = trim((string) ($_REQUEST['phone'] ?? ''));
$asked = $code !== '' || $phone !== '';

$order    = null;
$error    = '';
$reviewFor  = 0;
$reviewSent = null;
$throttled = false;

if ($asked) {
    if (track_is_throttled()) {
        $throttled = true;
        $error = 'تعداد جست‌وجوهای شما زیاد بوده است. چند دقیقه بعد دوباره تلاش کنید.';
    } else {
        /* The hold sweep ran only from admin pages, so an order whose window
           closed an hour ago still read as "new" to the person looking at it -
           and would now be offered a card and a countdown that had already run
           out. Cheap, indexed, and reached only by a real lookup. */
        orders_expire_due();
        $order = track_find($code, $phone);
        if ($order === null) {
            /* Only a miss counts towards the throttle, as on the API: every
               successful look - a customer refreshing to see their order move,
               a review sent - used to count too, and shut the same customer out
               of sending their receipt (?r=receipt reads the same counter). */
            track_record_attempt();
            /* One message for every kind of miss. Telling them which half was
               wrong would confirm that a code exists. */
            $error = 'سفارشی با این شماره پیگیری و شماره موبایل پیدا نشد.';
        } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['review_product'])) {
            /* A review, sent from the form below: the order is the one the
               code and phone just opened (lib/reviews.php checks the rest). */
            $reviewFor  = (int) $_POST['review_product'];
            $reviewSent = review_submit($order, $reviewFor, (int) ($_POST['rating'] ?? 0),
                                        (string) ($_POST['body'] ?? ''), (string) ($_POST['author'] ?? ''));
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

<?php include __DIR__ . '/partials/head-assets.php'; ?>


<title><?= e($title) ?> | <?= e(setting('shop_name', 'SUPPEX')) ?></title>
<meta name="description" content="وضعیت سفارش خود را با شماره پیگیری و شماره موبایل ببینید.">
<meta name="robots" content="noindex, nofollow">
<link rel="canonical" href="<?= e($url) ?>">
</head>

<?php include __DIR__ . '/partials/chrome-top.php'; ?>

<?php /* app.js reads this: arriving here is what counts as having seen the
         order, so the dot on the account icon clears. */ ?>
<main id="main" data-track-page>
  <section class="section">
    <div class="container container--narrow">

      <nav class="article__crumbs" aria-label="مسیر صفحه">
        <a href="./">خانه</a>
        <span aria-hidden="true">/</span>
        <span>پیگیری سفارش</span>
      </nav>

      <header class="article__head">
        <h1 class="article__title">پیگیری سفارش</h1>
        <p><a href="inquiry.php">پیگیری درخواست استعلام موجودی</a></p>
        <p class="article__lede">
          شماره پیگیری همان کدی است که پس از ثبت سفارش در سایت دریافت کردید.
        </p>
      </header>

      <?php /* «سفارش‌های من»: the orders this browser has placed or looked up,
               drawn by initMyOrders() in app.js from what the browser
               remembers, then with each one's live status. Hidden until there
               is one. It lives on the device, not behind a phone number - see
               the comment on MY_ORDERS_KEY for why. */ ?>
      <section class="myorders" data-my-orders hidden aria-labelledby="myorders-title">
        <h2 class="myorders__title" id="myorders-title">سفارش‌های من</h2>
        <p class="myorders__note">
          سفارش‌هایی که در این گوشی یا مرورگر ثبت یا پیگیری کرده‌اید. سفارشی را که جای دیگری
          ثبت کرده‌اید، با شماره پیگیری و موبایلش در فرم پایین پیدا کنید تا به همین فهرست اضافه شود.
        </p>
        <ul class="myorders__list" data-my-orders-list></ul>
      </section>

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
      <?php /* .field and .field__hint are ADMIN classes - they exist in
               admin/assets/admin.css and in none of the four stylesheets this
               page loads. base.css strips every input down to no border and no
               background, and `.input` is what puts the box back, so both fields
               rendered as bare text and nothing on the page looked fillable.
               These are the checkout's own classes. */ ?>
      <form class="form track__form" method="post" action="track.php" novalidate>
        <p class="form__intro">
          هر دو کادر را پر کنید: شماره پیگیری و همان شماره موبایلی که
          هنگام ثبت سفارش نوشتید. بدون هر دو، سفارش پیدا نمی‌شود.
        </p>

        <div class="form__row">
          <label class="form__label" for="code">شماره پیگیری<span class="form__req">*</span></label>
          <input class="input form__control" type="text" id="code" name="code"
                 dir="ltr" autocomplete="off" placeholder="SPX-000000-XXXX"
                 value="<?= e($code) ?>" required>
          <p class="form__hint">کدی که بعد از ثبت سفارش گرفتید — با خط تیره یا بدون آن، فرقی نمی‌کند.</p>
        </div>

        <div class="form__row">
          <label class="form__label" for="phone">شماره موبایل<span class="form__req">*</span></label>
          <input class="input form__control" type="tel" id="phone" name="phone"
                 dir="ltr" inputmode="numeric" autocomplete="tel"
                 placeholder="09xxxxxxxxx" value="<?= e($phone) ?>" required>
          <p class="form__hint">همان شماره‌ای که هنگام ثبت سفارش وارد کردید.</p>
        </div>

        <button class="btn btn--primary" type="submit">دیدن وضعیت</button>
      </form>

      <?php if ($error !== ''): ?>
        <p class="track__error" role="alert"><?= e($error) ?></p>
        <?php if (!$throttled): ?>
          <p class="u-xs u-dim" style="margin-top:10px">
            اگر کد را گم کرده‌اید، از پشتیبانی فروشگاه کمک بگیرید.
          </p>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($order !== null): ?>
        <?php $st = (string) $order['status']; ?>
        <section class="track__result" aria-label="وضعیت سفارش" data-found-order
                 data-code="<?= e((string) $order['code']) ?>" data-phone="<?= e((string) $order['phone']) ?>"
                 data-total="<?= (int) $order['total'] ?>">

          <div class="track__head">
            <div>
              <p class="u-eyebrow u-eyebrow--bare">شماره پیگیری</p>
              <p class="track__code num" dir="ltr"><?= e((string) $order['code']) ?></p>
            </div>
            <span class="track__badge track__badge--<?= e($st) ?>">
              <?= e(ORDER_STATUS_FA[$st] ?? $st) ?>
            </span>
          </div>

          <?php
          /* The note under the badge, unless the receipt box is on this page:
             then «send the receipt in the chat» would argue with it. Said as
             what to do next - or, once a receipt is in, that it arrived. */
          $trackNote = track_status_note($st);
          if ($st === 'new' && receipts_ready() && receipt_accepts($order)) {
              $trackNote = receipt_count((int) $order['id']) > 0
                  ? 'رسید واریز شما رسیده است. پس از بررسی، پرداخت تأیید می‌شود.'
                  : 'سفارش ثبت شده و منتظر تأیید پرداخت است. اگر واریز کرده‌اید، رسید را همین پایین بفرستید.';
          }
          ?>
          <p class="track__note"><?= e($trackNote) ?></p>

          <?php
          /* Unpaid and still inside the window: this is what they came back
             for. Nothing is shown once the order is paid - a card number on a
             settled order is an invitation to pay twice - and nothing is shown
             once the hold is over, because that price is no longer the price. */
          $payDigits  = preg_replace('/\D/', '', (string) setting('card_number', '')) ?? '';
          $payHasCard = strlen($payDigits) >= 12;
          $payExpires = $order['expires_at'] === null
              ? 0 : (int) strtotime((string) $order['expires_at']);
          /* Nor once a receipt is in. The sweep no longer expires such an
             order (orders_expire_due()), so its clock would run to zero over
             a card the customer has already paid - and a card number shown
             after the receipt is an invitation to pay twice. */
          $payReceipts = receipts_ready() ? receipt_count((int) $order['id']) : 0;
          ?>
          <?php if ($st === 'new' && $payExpires > time() && $payReceipts === 0): ?>
            <div class="pay" style="padding-block:0;margin-block:var(--sp-5)">

              <div class="pay__hold" data-expires="<?= $payExpires * 1000 ?>"
                   data-now="<?= time() * 1000 ?>">
                <svg viewBox="0 0 24 24" width="1em" height="1em" fill="none"
                     stroke="currentColor" stroke-width="1.7" stroke-linecap="round"
                     stroke-linejoin="round" aria-hidden="true" focusable="false">
                  <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.2 1.9"/>
                </svg>
                <span>مهلت پرداخت با این قیمت: <strong data-hold-countdown>—</strong></span>
              </div>

              <div class="pay__amount">
                <span class="u-sm u-muted">مبلغ قابل پرداخت</span>
                <strong><span class="num"><?= money_fa((int) $order['total']) ?></span>
                  <?= e(setting('currency_label', 'تومان')) ?></strong>
              </div>

              <?php if ($payHasCard): ?>
                <div class="pay__card">
                  <div class="pay__card-head">
                    <span class="u-eyebrow u-eyebrow--bare"><?= e(setting('bank_name', '')) ?></span>
                    <button class="pay__copy" type="button"
                            data-copy-card="<?= e($payDigits) ?>">کپی شماره</button>
                  </div>
                  <div class="pay__number num" dir="ltr"><?= e(trim(chunk_split($payDigits, 4, ' '))) ?></div>
                  <div class="pay__holder">به نام <?= e(setting('card_holder', '—')) ?></div>
                </div>
              <?php else: ?>
                <?php /* Never a number the shop has not set. A customer who copies
                         one believes they can pay with it. */ ?>
                <div class="pay__card pay__card--pending">
                  <div class="pay__holder">شماره کارت نمایش داده نمی‌شود. برای دریافت اطلاعات پرداخت
                    با پشتیبانی فروشگاه تماس بگیرید. تا پیش از دریافت اطلاعات معتبر، واریز نکنید.</div>
                </div>
              <?php endif; ?>

              <?php /* Shown by app.js when the clock reaches zero, in place of
                       the card and the amount. */ ?>
              <div class="pay__expired">
                <strong>مهلت پرداخت با این قیمت تمام شد.</strong>
                <span>اگر واریز نکرده‌اید، مبلغی واریز نکنید و سفارش را دوباره ثبت کنید.
                  اگر واریز کرده‌اید، رسید را همین پایین بفرستید.</span>
              </div>

              <?php if (!receipts_ready()): ?>
                <p class="pay__note">رسید واریز را همراه کد پیگیری برای پشتیبانی فروشگاه
                  بفرستید. پرداخت پس از بررسی فروشگاه تأیید می‌شود.</p>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php /* The receipt, sent from here - for an order still waiting on
                   its payment, or one that ran out of time with the money
                   already sent. app.js turns the slot into the box. */ ?>
          <?php if (receipts_ready() && receipt_accepts($order)): ?>
            <?php $sentReceipts = receipt_count((int) $order['id']); ?>
            <?php if ($sentReceipts > 0): ?>
              <p class="track__receipts u-sm">
                <span class="num"><?= e(fa_digits((string) $sentReceipts)) ?></span>
                رسید برای این سفارش فرستاده‌اید.
              </p>
            <?php endif; ?>
            <?php if ($sentReceipts < RECEIPT_PER_ORDER): ?>
              <div data-receipt-slot data-code="<?= e((string) $order['code']) ?>"
                   data-phone="<?= e((string) $order['phone']) ?>"></div>
            <?php endif; ?>
          <?php endif; ?>

          <?php /* The barcode, when there is one. Printed only then: a heading
                   over an empty space reads as something that failed to load
                   rather than something that has not happened yet.

                   Not gated on the status. A parcel handed to the post office
                   has a number whether or not the panel has caught up, and the
                   customer holding the number is better off than the customer
                   waiting for a status to change. */ ?>
          <?php $tracking = trim((string) ($order['tracking_code'] ?? '')); ?>
          <?php if ($tracking !== ''): ?>
            <div class="track__parcel">
              <span class="u-eyebrow u-eyebrow--bare">کد رهگیری مرسوله</span>
              <p class="track__parcel-code num" dir="ltr"><?= e($tracking) ?></p>
              <p class="u-xs u-dim">
                کد تیپاکس را در
                <a href="https://tipaxco.com/tracking" target="_blank" rel="noopener noreferrer">سامانه رهگیری تیپاکس</a>
                و کد پست را در
                <a href="https://tracking.post.ir/" target="_blank" rel="noopener noreferrer">سامانه رهگیری پست</a>
                وارد کنید. سامانه را مطابق شرکت حمل انتخاب کنید؛ ثبت اولیهٔ مرسوله ممکن است کمی زمان ببرد.
                هماهنگی تحویل با پیک از طریق پشتیبانی انجام می‌شود.
              </p>
            </div>
          <?php endif; ?>

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
            <caption class="sr-only">کالاهای این سفارش</caption>
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
                <td class="num u-nowrap"><?= money_fa((int) $it['line_total']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td>هزینه ارسال</td>
                <td></td>
                <td class="num u-nowrap">
                  <?= (int) $order['shipping'] === 0 ? 'رایگان' : money_fa((int) $order['shipping']) ?>
                </td>
              </tr>
              <tr class="track__total">
                <td>مبلغ کل</td>
                <td></td>
                <td class="num u-nowrap"><?= money_fa((int) $order['total']) ?> تومان</td>
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

          <?php /* Reviews (lib/reviews.php): once the order has been sent, one
                   for each product in it. Shown on the product page only after
                   the owner approves it, which the form says. */
                $toReview = review_candidates($order); ?>
          <?php if ($toReview): ?>
            <div class="track__reviews" id="reviews">
              <h2 class="u-h3">نظر شما درباره خریدتان</h2>
              <p class="u-sm u-muted">نظر شما بعد از بررسی، با برچسب «خرید تأییدشده» در صفحهٔ محصول نمایش داده می‌شود.</p>
              <?php foreach ($toReview as $c): ?>
                <?php $pid = (int) $c['product_id']; ?>
                <?php if ($reviewFor === $pid && $reviewSent !== null && !$reviewSent['ok']): ?>
                  <p class="track__error" role="alert"><?= e((string) $reviewSent['error']) ?></p>
                <?php endif; ?>
                <?php if ($c['review'] !== null): ?>
                  <p class="review-form__done" <?= $reviewFor === $pid ? 'role="status"' : '' ?>>
                    <strong><?= e($c['name']) ?>:</strong>
                    <?= $c['review']['status'] === 'approved'
                        ? 'نظر شما منتشر شده است. <a href="' . e('product.php?p=' . rawurlencode($c['slug'])) . '#reviews">دیدن در صفحهٔ محصول</a>'
                        : 'نظر شما ثبت شده است؛ ممنون.' ?>
                  </p>
                <?php else: ?>
                  <form class="review-form" method="post" action="track.php#reviews">
                    <input type="hidden" name="code" value="<?= e((string) $order['code']) ?>">
                    <input type="hidden" name="phone" value="<?= e((string) $order['phone']) ?>">
                    <input type="hidden" name="review_product" value="<?= $pid ?>">
                    <fieldset class="review-form__stars">
                      <legend>امتیاز شما به «<?= e($c['name']) ?>»</legend>
                      <div class="stars-input">
                        <?php for ($s = 5; $s >= 1; $s--): ?>
                          <input type="radio" id="rv-<?= $pid ?>-<?= $s ?>" name="rating" value="<?= $s ?>" required>
                          <label for="rv-<?= $pid ?>-<?= $s ?>"><span class="sr-only"><?= $s ?> ستاره</span><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 2.6l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5-5.8-3-5.8 3 1.1-6.5L2.6 9.4l6.5-.9z" fill="currentColor"/></svg></label>
                        <?php endfor; ?>
                      </div>
                    </fieldset>
                    <?php /* The checkout's own field classes, as the lookup form
                             above explains - .field is the panel's. */ ?>
                    <div class="form__row">
                      <label class="form__label" for="rv-body-<?= $pid ?>">نظرتان (اختیاری)</label>
                      <textarea class="input form__control" id="rv-body-<?= $pid ?>" name="body" rows="3"
                                maxlength="<?= REVIEW_BODY_MAX ?>" placeholder="کیفیت، طعم، حل شدن، بسته‌بندی…"></textarea>
                    </div>
                    <div class="form__row">
                      <label class="form__label" for="rv-author-<?= $pid ?>">نامی که کنار نظرتان نوشته شود (اختیاری)</label>
                      <input class="input form__control" type="text" id="rv-author-<?= $pid ?>" name="author"
                             maxlength="<?= REVIEW_AUTHOR_MAX ?>" autocomplete="given-name" placeholder="اگر خالی بماند: «خریدار»">
                    </div>
                    <button class="btn btn--primary" type="submit">ثبت نظر</button>
                  </form>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

        </section>
      <?php endif; ?>

    </div>
  </section>
</main>


<!-- ══════════════════════════════════════════ Footer ═══ -->
<?php include __DIR__ . '/partials/chrome-foot.php'; ?>
