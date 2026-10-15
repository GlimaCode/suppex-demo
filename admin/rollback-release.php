<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
auth_require_can('maintenance');
require_once SUPPEX_ROOT . '/lib/release_rollback.php';
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
$result = null;
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $result = release_rollback_files();
    if (!$result['errors']) {
        try { $result['catalog'] = release_rollback_catalog(); }
        catch (Throwable $e) {
            error_log('[suppex] release rollback: ' . $e->getMessage());
            $error = 'فایل‌ها بررسی شدند؛ بازیابی نمایش محصولات کامل نشد. دوباره تلاش کنید.';
        }
    }
}
?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>بازگشت نسخهٔ SUPPEX</title>
<style>body{font:17px/2 Tahoma,Arial,sans-serif;background:#f3f4f6;color:#17202a;margin:0;padding:24px}main{max-width:760px;background:white;margin:24px auto;padding:28px;border-radius:14px}button,a{font:inherit}button{background:#174c35;color:white;border:0;border-radius:8px;padding:12px 20px;cursor:pointer}.ok{background:#e0f5e9;padding:16px}.error{background:#ffe4e4;padding:16px}code{direction:ltr;unicode-bidi:embed;display:inline-block;font-size:14px}li{overflow-wrap:anywhere}</style></head><body><main>
<h1>بازگشت به نسخهٔ قبل از ZIP</h1>
<p>فایل‌های نسخهٔ قبل بررسی و دسترسی فایل‌ها و پوشه‌های برنامه اصلاح می‌شود. سه محصولی که آماده‌سازی پنهان کرده بود دوباره نمایش داده می‌شوند و دو لوگوی افزوده‌شده به حالت خالی قبل برمی‌گردند. عکس‌های WebP موجود حفظ می‌شوند.</p>
<p>این عملیات قیمت، موجودی، سفارش‌ها، رسیدها و اطلاعات مشتریان را تغییر نمی‌دهد.</p>
<?php if ($error !== ''): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
<?php if ($result !== null): ?>
<?php if ($result['errors']): ?><div class="error">این فایل‌ها کامل جایگزین نشده‌اند یا اجازهٔ اصلاح دسترسی ندارند. ابتدا ZIP را داخل public_html مجدداً Extract و جایگزینی فایل‌ها را تأیید کنید. دسترسی فایل‌ها باید 644 و پوشه‌ها 755 باشد.<ul><?php foreach ($result['errors'] as $path): ?><li><code><?= e($path) ?></code></li><?php endforeach; ?></ul></div>
<?php elseif ($error === ''): ?><p class="ok">بازگشت کامل شد. <?= (int) $result['checked'] ?> فایل بررسی شد. <?= (int) $result['catalog']['products'] ?> محصول دوباره فعال شد. اکنون فروشگاه و پنل را با Ctrl+F5 باز کنید.</p><p><a href="../">بازکردن فروشگاه</a> · <a href="index.php">بازکردن پنل</a></p><?php endif; ?>
<?php endif; ?>
<form method="post"><?= csrf_field() ?><button type="submit">اجرای بازگشت و اصلاح دسترسی‌ها</button></form>
</main></body></html>
