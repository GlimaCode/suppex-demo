<?php
/* ===========================================================================
   SUPPEX — First-run setup
   ---------------------------------------------------------------------------
   Creates the tables and the first admin account, then imports the demo
   catalogue. Run it once from the browser:

       https://your-domain.ir/setup.php

   DELETE THIS FILE once it reports success. It creates an administrator, and
   a script that can create administrators has no business staying reachable
   on a live site. The guard below refuses to run a second time, but deleting
   it is the real protection.
   =========================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/migrate.php';

header('Content-Type: text/html; charset=utf-8');

/* ---------------------------------------------------------------------------
   Does the schema already exist, and does it already have an admin?
   --------------------------------------------------------------------------- */
/* Three states, not two. $knowState is the important one: it is false when
   the database DECLINED TO ANSWER, which is different from answering "there is
   no administrator" - and only one of those two is a safe basis for creating
   an owner account.

   Before this, a query failure with the connection still up left $hasAdmin at
   its initialised false, and the installer read that as an empty shop. The
   state that produces it is not hypothetical: a dropped, renamed or
   mid-restore admins table is exactly what this guide's own restore procedure
   passes through. */
$hasTables = false;
$hasAdmin  = false;
$knowState = false;
try {
    $hasTables = db_value("SHOW TABLES LIKE 'products'") !== null;
    $hasAdmin  = $hasTables && (int) db_value('SELECT COUNT(*) FROM admins') > 0;
    $knowState = true;
} catch (Throwable $e) {
    /* Deliberately leaves $knowState false. lib/db.php turns an unreachable
       server into a hard exit, so anything caught here is a query that failed
       against a live connection - which means the schema is in a state nobody
       has established, and that is the last moment to be writing to it. */
    error_log('[suppex] setup.php cannot determine installation state: ' . $e->getMessage());
}

$done   = [];
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    if (!$knowState) {
        /* Refuse rather than guess. Creating an administrator is the one
           action here that cannot be undone by re-running anything. */
        $errors[] = 'وضعیت نصب قابل تشخیص نیست — دیتابیس به پرسش جواب نداد. '
                  . 'تا وقتی مشخص نشده که جدول‌ها در چه حالی هستند، این اسکریپت '
                  . 'چیزی نمی‌سازد. اتصال دیتابیس و فایل تنظیمات را بررسی کنید.';
    } elseif ($hasAdmin) {
        $errors[] = 'یک حساب مدیر از قبل وجود دارد. این اسکریپت دوباره اجرا نمی‌شود. '
                  . 'فایل setup.php را حذف کنید.';
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
                    db_run_sql_file(__DIR__ . '/db/schema.sql');
                    $done[] = 'جدول‌های دیتابیس ساخته شد.';
                }

                /* --- Migrations ---------------------------------------------
                   schema.sql is the shape this project started with; the dirham
                   pricing, the cost and profit snapshot and the partnership
                   commission all arrived as migrations afterwards. Without this
                   call a brand new shop comes up ten columns and one table
                   short, everything looks installed, and the first customer's
                   checkout dies inside a transaction with "Unknown column
                   'profit_total'" - no order row, no notification, nothing to
                   reconcile. Every step is idempotent, so this is also safe on
                   a database that already has them. */
                $mlog = [];
                migrate_run($mlog);
                $applied = count(array_filter($mlog, static fn(array $l): bool => $l[0] === 'done'));
                $mfailed = array_filter($mlog, static fn(array $l): bool => $l[0] === 'fail');
                if ($applied > 0) {
                    $done[] = 'ساختار دیتابیس به‌روز شد (' . $applied . ' مورد).';
                }
                if ($mfailed) {
                    /* Loud, not silent: a half-migrated database takes orders
                       right up until the one column it is missing is needed. */
                    foreach ($mfailed as $l) {
                        $errors[] = 'مهاجرت ناموفق: ' . $l[1];
                    }
                    throw new RuntimeException('migration failed during setup');
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
                    require __DIR__ . '/db/seed.php';
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
<link rel="stylesheet" href="admin/assets/admin.css">
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
        <strong>حالا فایل setup.php را از روی هاست حذف کنید.</strong>
        تا وقتی این فایل روی سرور باشد، یک صفحه ساخت حساب مدیر روی سایت باز است.
      </div>
      <a class="btn btn--primary btn--block" href="admin/login.php">ورود به پنل مدیریت</a>

    <?php elseif ($hasAdmin): ?>
      <div class="flash flash--info">
        نصب قبلاً انجام شده است. فایل setup.php را حذف کنید.
      </div>
      <a class="btn btn--primary btn--block" href="admin/login.php">ورود به پنل مدیریت</a>

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
        <!-- Unchecked now. This was checked by default, so the ordinary
             install put fifteen INVENTED products into the shop - with
             invented names, prices and feature bullets - and a real customer
             could order one. Sample data is useful for looking around before
             the real catalogue is imported, which is a decision worth making
             on purpose rather than by not noticing a checkbox. -->
        <label class="check" style="margin-block-start:16px">
          <input type="checkbox" name="seed" value="1">
          <span>محصولات نمونه هم وارد شود</span>
        </label>
        <span class="hint" style="display:block;margin-block-start:6px">
          <strong>این محصولات واقعی نیستند</strong> — نام، قیمت و مشخصاتشان ساختگی است
          و فقط برای این‌اند که پیش از وارد کردن کاتالوگ واقعی، شکل فروشگاه را ببینید.
          اگر تیک بزنید، حتماً پیش از راه‌اندازی از پنل حذفشان کنید.
          کاتالوگ واقعی از صفحه «ورود گروهی» و فایل اکسل وارد می‌شود.
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
