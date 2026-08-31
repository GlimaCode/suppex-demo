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

<?php include __DIR__ . '/partials/chrome-top.php'; ?>

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
                این کد را در
                <a href="https://tracking.post.ir/" target="_blank" rel="noopener noreferrer">سامانه رهگیری پست</a>
                وارد کنید تا ببینید مرسوله کجاست. تا چند ساعت بعد از تحویل به پست،
                ممکن است هنوز در سامانه ثبت نشده باشد.
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
<?php include __DIR__ . '/partials/chrome-foot.php'; ?>
