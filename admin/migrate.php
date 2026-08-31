<?php
/* ===========================================================================
   SUPPEX — Schema migrations
   ---------------------------------------------------------------------------
   Brings an already-installed database up to the current schema. Run from the
   browser after uploading a new version:

       https://your-domain.ir/admin/migrate.php

   Every step is idempotent — it checks whether the change is already there
   before making it — so running this twice is harmless, and a half-finished
   run can simply be repeated.

   Why this exists rather than "just re-import schema.sql": once the shop is
   live, schema.sql cannot be re-run. Its CREATE TABLE statements are guarded
   with IF NOT EXISTS, so on a live database they do nothing at all, and a new
   column added to schema.sql would never appear. Editing the live tables by
   hand in phpMyAdmin is the alternative, and it goes wrong quietly.
   =========================================================================== */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';

/* Behind the login, and not merely because /db/ is blocked by .htaccess.
   This endpoint runs ALTER TABLE. Left public, anyone who found the URL
   could reshape the shop's database on demand. */
auth_require();

/* --- Introspection helpers ------------------------------------------------- */

require_once SUPPEX_ROOT . '/lib/migrate.php';

/* --- Migrations ------------------------------------------------------------
   The list itself lives in lib/migrate.php: setup.php has to run exactly the
   same one on a fresh install, and two copies would drift. */

$log = [];
$ran = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $ran = true;
    migrate_run($log);
}

$pending = migrate_pending();
$allDone = !in_array(false, $pending, true);
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>به‌روزرسانی دیتابیس SUPPEX</title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<div class="login">
  <div class="login__box" style="max-width:600px">
    <div class="login__logo">SUPPEX</div>
    <p class="login__sub">به‌روزرسانی ساختار دیتابیس</p>

    <?php if ($ran): ?>
      <?php if (!$allDone): ?>
        <?php /* A step can fail because an earlier one did, and the fix is
                 simply to run again — every step is idempotent. Saying so
                 here matters: without it the page shows one red line among a
                 dozen green ones and a friendly button onward, and the shop
                 goes live on a half-built schema. */ ?>
        <div class="flash flash--error">
          <strong>به‌روزرسانی کامل نشد.</strong>
          دکمه پایین را دوباره بزنید. اجرای دوباره بی‌خطر است و فقط چیزهایی را
          می‌سازد که هنوز ساخته نشده‌اند. اگر بار دوم هم کامل نشد، متن قرمز
          زیر را بفرستید.
        </div>
      <?php endif; ?>
      <?php foreach ($log as [$state, $label]): ?>
        <div class="flash flash--<?= $state === 'fail' ? 'error' : ($state === 'skip' ? 'info' : 'ok') ?>">
          <?= $state === 'done' ? '✓ ' : ($state === 'skip' ? '— ' : '✕ ') ?><?= e($label) ?>
        </div>
      <?php endforeach; ?>
      <?php if (!$log): ?>
        <div class="flash flash--info">چیزی برای به‌روزرسانی نبود.</div>
      <?php endif; ?>
      <?php if ($allDone): ?>
        <a class="btn btn--primary btn--block" href="pricing.php" style="margin-block-start:16px">
          رفتن به صفحه قیمت‌گذاری
        </a>
      <?php else: ?>
        <form method="post" action="migrate.php">
          <button class="btn btn--primary btn--block" type="submit" style="margin-block-start:16px">
            اجرای دوباره
          </button>
        </form>
      <?php endif; ?>

    <?php else: ?>
      <div class="flash flash--<?= $allDone ? 'ok' : 'info' ?>">
        <?= $allDone
          ? 'دیتابیس به‌روز است. نیازی به اجرای دوباره نیست.'
          : 'چند تغییر در ساختار دیتابیس لازم است.' ?>
      </div>

      <table style="width:100%;margin-block:16px">
        <?php foreach ($pending as $name => $ok): ?>
          <tr>
            <td class="lat" style="padding:6px 0"><?= e($name) ?></td>
            <td class="u-right">
              <span class="pill <?= $ok ? 'pill--paid' : 'pill--off' ?>">
                <?= $ok ? 'موجود' : 'لازم است' ?>
              </span>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>

      <p class="hint">
        این عملیات فقط ستون و جدول <strong>اضافه</strong> می‌کند و هیچ داده‌ای را
        پاک یا بازنویسی نمی‌کند. با این حال، قبل از اجرا از دیتابیس
        خروجی (Backup) بگیرید — این یک عادت است که فقط یک‌بار لازم می‌شود
        و همان یک‌بار جبران همه دفعات را می‌کند.
      </p>

      <form method="post" action="migrate.php">
        <button class="btn btn--primary btn--block" type="submit" style="margin-block-start:16px">
          اجرای به‌روزرسانی
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
