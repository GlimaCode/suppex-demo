<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/inquiries.php';
require_once __DIR__ . '/lib/seo.php';
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');
$row = null; $error = ''; $notifyId = 0;
$code = clean_text((string) ($_GET['code'] ?? $_POST['code'] ?? ''), 32);
$phone = digits_only((string) ($_POST['phone'] ?? ''));
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!inquiry_same_origin()) { http_response_code(403); $error = 'این درخواست پذیرفته نشد؛ از داخل سایت اقدام کنید.'; }
    else {
        try {
            if (($_POST['action'] ?? '') === 'create') {
                $result = inquiry_create($_POST);
                if (!$result['ok']) { $error = $result['error']; }
                else {
                    $row = $result['row']; $code = $row['code']; $phone = $row['phone'];
                    if (empty($result['duplicate'])) { $notifyId = (int) $row['id']; }
                }
            } else {
                $row = inquiry_find($code, $phone);
                if ($row === null) { $error = 'درخواستی با این کد و موبایل پیدا نشد یا تعداد تلاش‌ها زیاد بوده است.'; }
            }
        } catch (Throwable $e) {
            error_log('[suppex] inquiry request failed: ' . $e->getMessage());
            $error = 'ارتباط موقتاً برقرار نشد؛ دوباره تلاش کنید. ارسال دوباره همان فرم، درخواست تکراری ایجاد نمی‌کند.';
        }
    }
}
$public = $row === null ? null : inquiry_public($row);
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>پیگیری استعلام موجودی | SUPPEX</title>
<?php include __DIR__ . '/partials/head-assets.php'; ?>
</head>
<?php include __DIR__ . '/partials/chrome-top.php'; ?>
<main id="main" data-inquiry-page>
<section class="section"><div class="container container--narrow">
  <h1 class="u-h2">پیگیری استعلام موجودی</h1>
  <p>درخواست در پنل فروشگاه ثبت می‌شود؛ نیازی به پیام خصوصی نیست. پاسخ را حداکثر تا ۳۰ دقیقه در همین صفحه بررسی کنید.</p>
  <?php if ($error !== ''): ?><p class="form__error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <section class="inquiry-result" data-inquiry-result role="status" aria-live="polite"<?= $row === null ? ' hidden' : '' ?>>
  <?php if ($public !== null): ?>
    <h2 class="u-h3"><?= e(INQUIRY_STATUS_FA[$public['status']]) ?></h2>
    <p>کد پیگیری: <bdi><?= e($public['code']) ?></bdi></p>
    <p><?= e($public['product']) ?> · <?= e($public['choice']) ?> · <?= e(fa_digits((string) $public['qty'])) ?> عدد</p>
    <?php if ($public['status'] === 'pending'): ?>
      <p><?= $public['deadline'] < time() * 1000 ? 'مهلت پاسخ گذشته؛ درخواست هنوز در حال بررسی است.' : 'درخواست ثبت شده و در انتظار بررسی فروشگاه است.' ?></p>
      <p>مهلت پاسخ: <bdi><?= e($row['respond_by']) ?></bdi></p>
    <?php elseif ($public['status'] === 'approved'): ?>
      <p>موجودی همین تعداد تأیید و به موجودی عمومی اضافه شد. این استعلام رزرو شخصی نیست؛ موجودی و قیمت هنگام خرید دوباره بررسی می‌شوند.</p>
      <p><?= $public['available'] ? 'اکنون می‌توانید صفحه محصول را باز کنید و خرید کنید.' : 'موجودی قابل خرید فعلاً در دسترس نیست؛ وضعیت صفحه محصول را بررسی کنید.' ?></p>
    <?php else: ?><p>فروشگاه موجودی این درخواست را تأیید نکرد. برای این درخواست پرداختی انجام ندهید.</p><?php endif; ?>
    <p><a class="btn btn--primary" href="<?= e($public['productUrl']) ?>">بازگشت به محصول</a></p>
  <?php endif; ?>
  </section>
  <form class="form track__form" method="post" action="inquiry.php" data-inquiry-lookup>
    <div class="form__row"><label class="form__label" for="inquiry-code">کد پیگیری استعلام</label>
      <input class="input form__control" id="inquiry-code" name="code" dir="ltr" maxlength="32" required value="<?= e($code) ?>" placeholder="SI-000000-0000000000000000"></div>
    <div class="form__row"><label class="form__label" for="inquiry-phone">موبایل ثبت درخواست</label>
      <input class="input form__control" id="inquiry-phone" name="phone" type="tel" dir="ltr" maxlength="15" autocomplete="tel" required value="<?= e($phone) ?>"></div>
    <button class="btn btn--primary" type="submit">بررسی نتیجه</button>
    <p class="form__hint" data-inquiry-poll-note>با بازماندن این صفحه، نتیجه خودکار تازه می‌شود. کد و موبایل را برای مراجعهٔ بعدی نگه دارید.</p>
  </form>
</div></section>
</main>
<?php include __DIR__ . '/partials/chrome-foot.php'; ?>
<?php
if ($notifyId > 0) {
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
    elseif (function_exists('litespeed_finish_request')) { litespeed_finish_request(); }
    else { if (ob_get_level() > 0) { @ob_end_flush(); } @flush(); }
    try { inquiry_notify($notifyId); }
    catch (Throwable $e) { error_log('[suppex] inquiry notification failed: ' . $e->getMessage()); }
}
