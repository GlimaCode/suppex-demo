<?php
/* ===========================================================================
   SUPPEX — First-run setup
   ---------------------------------------------------------------------------
   Creates the tables and the first admin account, then imports the demo
   catalogue. Run it once from the browser:

       https://your-domain.ir/db/setup.php

   DELETE THIS FILE once it reports success. It creates an administrator, and
   a script that can create administrators has no business staying reachable
   on a live site. The guard below refuses to run a second time, but deleting
   it is the real protection.
   =========================================================================== */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');

/* ---------------------------------------------------------------------------
   Does the schema already exist, and does it already have an admin?
   --------------------------------------------------------------------------- */
$hasTables = false;
$hasAdmin  = false;
try {
    $hasTables = db_value("SHOW TABLES LIKE 'products'") !== null;
    if ($hasTables) {
        $hasAdmin = (int) db_value('SELECT COUNT(*) FROM admins') > 0;
    }
} catch (Throwable $e) {
    $hasTables = false;
}

$done   = [];
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    if ($hasAdmin) {
        $errors[] = 'یک حساب مدیر از قبل وجود دارد. این اسکریپت دوباره اجرا نمی‌شود. '
                  . 'فایل db/setup.php را حذف کنید.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $name     = trim((string) ($_POST['name'] ?? ''));
        $seed     = !empty($_POST['seed']);

        if (!preg_match('/^[a-zA-Z0-9._-]{3,64}$/', $username)) {
            $errors[] = 'نام کاربری باید ۳ تا ۶۴ کاراکتر انگلیسی، عدد، نقطه، خط تیره یا زیرخط باشد.';
        }
        if (mb_strlen($password) < 10) {
            $errors[] = 'رمز عبور باید حداقل ۱۰ کاراکتر باشد.';
        }

        if (!$errors) {
            try {
                /* --- Schema --------------------------------------------------- */
                if (!$hasTables) {
                    db_run_sql_file(__DIR__ . '/schema.sql');
                    $done[] = 'جدول‌های دیتابیس ساخته شد.';
                }

                /* --- Admin --------------------------------------------------- */
                db_query(
                    'INSERT INTO admins (username, password_hash, name, role) VALUES (?, ?, ?, "owner")',
                    [$username, password_hash($password, PASSWORD_DEFAULT),
                     $name !== '' ? mb_substr($name, 0, 120) : $username]
                );
                $done[] = 'حساب مدیر ساخته شد.';

                /* --- Demo catalogue ------------------------------------------- */
                if ($seed) {
                    require __DIR__ . '/seed.php';
                    $counts = suppex_seed();
                    $done[] = sprintf(
                        '%d دسته و %d محصول نمونه وارد شد.',
                        $counts['categories'], $counts['products']
                    );
                }

                $done[] = 'نصب کامل شد.';
            } catch (Throwable $e) {
                error_log('[suppex] setup failed: ' . $e->getMessage());
                $errors[] = 'خطا در نصب: ' . $e->getMessage();
            }
        }
    }
}
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>نصب SUPPEX</title>
<link rel="stylesheet" href="../admin/assets/admin.css">
</head>
<body>
<div class="login">
  <div class="login__box" style="max-width:520px">
    <div class="login__logo">SUPPEX</div>
    <p class="login__sub">نصب اولیه</p>

    <?php foreach ($errors as $msg): ?>
      <div class="flash flash--error"><?= e($msg) ?></div>
    <?php endforeach; ?>
    <?php foreach ($done as $msg): ?>
      <div class="flash flash--ok"><?= e($msg) ?></div>
    <?php endforeach; ?>

    <?php if ($done): ?>
      <div class="flash flash--error">
        <strong>حالا فایل db/setup.php را از روی هاست حذف کنید.</strong>
        تا وقتی این فایل روی سرور باشد، یک صفحه ساخت حساب مدیر روی سایت باز است.
      </div>
      <a class="btn btn--primary btn--block" href="../admin/login.php">ورود به پنل مدیریت</a>

    <?php elseif ($hasAdmin): ?>
      <div class="flash flash--info">
        نصب قبلاً انجام شده است. فایل db/setup.php را حذف کنید.
      </div>
      <a class="btn btn--primary btn--block" href="../admin/login.php">ورود به پنل مدیریت</a>

    <?php else: ?>
      <form method="post" action="setup.php">
        <div class="field">
          <label for="username">نام کاربری مدیر</label>
          <input type="text" id="username" name="username" dir="ltr" required
                 value="<?= e((string) ($_POST['username'] ?? '')) ?>">
        </div>
        <div class="field">
          <label for="name">نام نمایشی</label>
          <input type="text" id="name" name="name" value="<?= e((string) ($_POST['name'] ?? '')) ?>">
        </div>
        <div class="field">
          <label for="password">رمز عبور</label>
          <input type="password" id="password" name="password" dir="ltr" required>
          <span class="hint">
            حداقل ۱۰ کاراکتر. یک عبارت طولانی از چند کلمه بهتر از یک رمز کوتاه و پیچیده است.
          </span>
        </div>
        <label class="check" style="margin-block-start:16px">
          <input type="checkbox" name="seed" value="1" checked>
          <span>محصولات نمونه هم وارد شود</span>
        </label>
        <span class="hint" style="display:block;margin-block-start:6px">
          محصولات نمونه را بعداً می‌توانید از پنل ویرایش یا حذف کنید.
          بدون آن‌ها فروشگاه با کاتالوگ خالی شروع می‌شود.
        </span>

        <button class="btn btn--primary btn--block" type="submit" style="margin-block-start:20px">
          شروع نصب
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
