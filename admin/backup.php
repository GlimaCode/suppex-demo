<?php
/* ===========================================================================
   SUPPEX admin — backup and export
   ---------------------------------------------------------------------------
   Both downloads stream and neither writes to disk: a dump holds every
   customer's address and every admin's password hash, and a file sitting in
   public_html is fetchable by anyone who guesses the name.
   =========================================================================== */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/backup.php';
require_once __DIR__ . '/partials/layout.php';

$user = auth_require();

/* --- Downloads ------------------------------------------------------------
   Served before a byte of HTML, or the page would be welded to the file. */
$want = (string) ($_GET['download'] ?? '');

if ($want === 'sql' || $want === 'catalogue') {
    /* A dump is the one page on this site that must never be cached: a shared
       computer's browser history should not hold the customer list. */
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    /* Any buffering has to go, or a large dump is assembled in memory first —
       which is exactly what the chunked reader exists to avoid. */
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if ($want === 'catalogue') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'
            . backup_filename('catalogue', 'csv') . '"');
        echo backup_catalogue_csv();
        exit;
    }

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="'
        . backup_filename('backup', 'sql') . '"');
    backup_stream_sql(static function (string $chunk): void {
        echo $chunk;
        flush();
    });
    exit;
}

$uploads  = backup_uploads_summary();
/* Read the export back before offering it. A backup that cannot be
   restored is worse than none, because it is believed. */
$check    = backup_catalogue_check();
$tables   = backup_tables();
$orders   = (int) db_value('SELECT COUNT(*) FROM orders');
$products = (int) db_value('SELECT COUNT(*) FROM products');

$mb = static fn(int $bytes): string =>
    $bytes < 1048576
        ? number_format($bytes / 1024, 0) . ' KB'
        : number_format($bytes / 1048576, 1) . ' MB';

admin_head('پشتیبان‌گیری', ['user' => $user]);
?>

<div class="card">
  <h2 class="card__title">پشتیبان کامل</h2>
  <p style="color:var(--text-muted);line-height:2.1">
    همه چیز در یک فایل: ساختار جدول‌ها، محصولات،
    <span class="num"><?= $orders ?></span> سفارش با مشخصات مشتری‌ها،
    تنظیمات، و حساب‌های مدیر.
    <span class="num"><?= count($tables) ?></span> جدول.
  </p>
  <div class="flash flash--info" style="margin-block-start:14px">
    این فایل <strong>اطلاعات مشتری‌ها و رمز حساب‌های مدیر</strong> را دارد.
    جایی نگهش دارید که فقط خودتان دسترسی دارید — نه در پوشه سایت، نه در گروه تلگرام.
  </div>
  <div style="margin-block-start:16px">
    <a class="btn btn--primary" href="?download=sql">دانلود پشتیبان (SQL)</a>
  </div>
  <p class="hint" style="margin-block-start:14px">
    برای برگرداندن: در cPanel وارد phpMyAdmin شوید، دیتابیس را انتخاب کنید و از
    زبانه <strong>Import</strong> همین فایل را بدهید. جدول‌های قبلی خودشان
    جایگزین می‌شوند.
  </p>
</div>

<div class="card">
  <h2 class="card__title">خروجی محصولات</h2>
  <p style="color:var(--text-muted);line-height:2.1">
    <span class="num"><?= $products ?></span> محصول، دقیقاً با همان ستون‌های فایل اکسل.
    همین فایل را می‌شود دوباره در
    <a href="import.php" style="text-decoration:underline">ورود گروهی</a>
    بارگذاری کرد — پس برای جابه‌جا کردن کاتالوگ بین دو سایت،
    یا برای ویرایش گروهی قیمت‌ها در اکسل، همین کافی است.
  </p>
  <?php if ($check['error'] !== null): ?>
    <div class="flash flash--error" style="margin-block-start:14px">
      فایل خودش خوانده نشد: <?= e($check['error']) ?>
    </div>
  <?php elseif ($check['errors']): ?>
    <div class="flash flash--error" style="margin-block-start:14px">
      <strong>این فایل کامل برنمی‌گردد.</strong>
      از <span class="num"><?= $check['expected'] ?></span> محصول فروشگاه،
      <span class="num"><?= $check['products'] ?></span> تا قابل ورود دوباره‌اند.
      دلیلش داده‌های خود محصول است، نه فایل:
      <ul style="padding-inline-start:20px;line-height:2;margin-block-start:8px">
        <?php foreach (array_slice($check['errors'], 0, 8) as $msg): ?>
          <li><?= e($msg) ?></li>
        <?php endforeach; ?>
      </ul>
      <?php if (count($check['errors']) > 8): ?>
        <span class="num"><?= count($check['errors']) - 8 ?></span> مورد دیگر.
      <?php endif; ?>
      پشتیبان کامل (SQL) این مشکل را ندارد — همه چیز را عیناً می‌برد.
    </div>
  <?php else: ?>
    <div class="flash flash--ok" style="margin-block-start:14px">
      بررسی شد: هر
      <span class="num"><?= $check['expected'] ?></span> محصول از روی همین فایل دوباره ساخته می‌شود
      (<span class="num"><?= $check['rows'] ?></span> سطر).
    </div>
  <?php endif; ?>

  <div style="margin-block-start:16px">
    <a class="btn btn--ghost" href="?download=catalogue">دانلود محصولات (CSV)</a>
  </div>
  <p class="hint" style="margin-block-start:14px">
    عکس‌ها و متن‌های صفحه محصول (ویژگی‌ها، مواد، ارزش غذایی) در این فایل
    <strong>نیستند</strong> — آن‌ها فقط در پشتیبان کامل بالا هستند.
  </p>
</div>

<div class="card">
  <h2 class="card__title">عکس‌ها</h2>
  <?php if (!$uploads['readable']): ?>
    <div class="flash flash--error">
      پوشه عکس‌ها خوانده نشد: <span class="lat" dir="ltr"><?= e($uploads['path']) ?></span>
    </div>
  <?php else: ?>
    <p style="color:var(--text-muted);line-height:2.1">
      <span class="num"><?= number_format($uploads['files']) ?></span> فایل،
      <span class="num"><?= e($mb($uploads['bytes'])) ?></span>. اینجا:
    </p>
    <p class="lat" dir="ltr" style="direction:ltr;text-align:start;background:var(--surface-2);
              padding:12px 14px;border-radius:8px;margin-block-start:10px;user-select:all">
      <?= e($uploads['path']) ?>
    </p>
    <p class="hint" style="margin-block-start:14px">
      این پوشه در فایل SQL نمی‌آید — عکس‌ها فایل‌اند، نه رکورد دیتابیس.
      کل پوشه را با FTP یا File Manager کپی کنید.
      <strong>فایل مخفی <span class="lat">.htaccess</span> داخلش را جا نیندازید</strong>؛
      همان است که جلوی اجرای فایل آپلودشده را می‌گیرد.
    </p>
  <?php endif; ?>
</div>

<div class="card">
  <h2 class="card__title">چند وقت یک‌بار</h2>
  <ul style="padding-inline-start:20px;line-height:2.1;color:var(--text-muted)">
    <li><strong>قبل از هر تغییر بزرگ</strong> — ورود گروهی، اعمال نرخ درهم جدید، به‌روزرسانی دیتابیس.</li>
    <li><strong>هفته‌ای یک‌بار</strong> وقتی سفارش واقعی می‌گیرید. سفارش‌ها فقط در دیتابیس‌اند.</li>
    <li>فایل را <strong>جای دیگری</strong> نگه دارید، نه روی همان هاست. پشتیبانی که کنار اصل بماند، با آن هم از بین می‌رود.</li>
  </ul>
</div>

<?php admin_foot(); ?>
