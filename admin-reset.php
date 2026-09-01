<?php
/* ===========================================================================
   SUPPEX — One-shot admin recovery
   ---------------------------------------------------------------------------
   For the one situation nothing else covers: the panel password is wrong or
   lost, so the panel cannot be used to fix the panel.

   IT IS GATED, AND IT MUST BE DELETED. A page that can set an administrator's
   password is worth exactly as much to an attacker as one that creates an
   administrator, so this file is dangerous in precisely the way setup.php is.
   Two things keep that window shut:

     1. RESET_GATE below is empty, and an empty gate refuses everything. You
        set it to a phrase you invent, and pass that phrase in the URL. Finding
        the file is then not enough.
     2. admin/preflight.php treats the presence of this file the same way it
        treats setup.php - a launch blocker - so the readiness page will not go
        green while it exists.

   HOW TO USE IT
     1. Open this file and put a phrase between the quotes on the RESET_GATE
        line. Anything you can retype: «tanha-baraye-emrooz» is fine.
     2. Upload it beside index.php.
     3. Open https://your-domain.ir/admin-reset.php?k=YOUR-PHRASE
     4. Do what you came for.
     5. DELETE THE FILE.
   =========================================================================== */

declare(strict_types=1);

/* Empty refuses everything. Put your own phrase here before uploading. */
const RESET_GATE = '';

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';

header('Content-Type: text/html; charset=utf-8');
/* Never indexed, never cached, whatever else happens. */
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

/* hash_equals rather than ===, and the empty check first: an unset gate must
   refuse rather than match an empty ?k=. */
$given = (string) ($_GET['k'] ?? '');
if (RESET_GATE === '' || !hash_equals(RESET_GATE, $given)) {
    http_response_code(404);
    exit('<!doctype html><meta charset="utf-8"><p style="font-family:sans-serif">Not found.</p>');
}

$done   = [];
$errors = [];

/* --- What is actually in the table ---------------------------------------- */
$admins = [];
$dbError = '';
try {
    $admins = db_all('SELECT id, username, name, role, is_active, last_login_at, created_at,
                             LENGTH(password_hash) AS hash_len
                        FROM admins ORDER BY id');
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $dbError === '') {
    $id  = (int) ($_POST['id'] ?? 0);
    $pw  = (string) ($_POST['password'] ?? '');
    $pw2 = (string) ($_POST['password2'] ?? '');

    /* NOT trimmed, and that is deliberate: setup.php does not trim it either,
       and a password is whatever the person typed. Trimming here and not there
       is exactly the kind of asymmetry that produces "the password is right
       and it does not work". */
    if ($id <= 0) {
        $errors[] = 'کدام حساب؟';
    } elseif (mb_strlen($pw) < 10) {
        $errors[] = 'رمز باید حداقل ۱۰ کاراکتر باشد.';
    } elseif ($pw !== $pw2) {
        $errors[] = 'دو رمز یکی نیستند.';
    } else {
        try {
            db_query('UPDATE admins SET password_hash = ?, is_active = 1 WHERE id = ?',
                [password_hash($pw, PASSWORD_DEFAULT), $id]);
            /* The throttle counts failures per IP per username. Somebody who
               has just been locked out has almost certainly earned a few, and
               leaving them in place means the new password appears not to work
               either. */
            db_query('DELETE FROM login_attempts WHERE ip = ?', [client_ip_binary()]);
            $done[] = 'رمز عوض شد و تلاش‌های ناموفق پاک شدند. حالا وارد شوید — و این فایل را حذف کنید.';
            $admins = db_all('SELECT id, username, name, role, is_active, last_login_at, created_at,
                                     LENGTH(password_hash) AS hash_len FROM admins ORDER BY id');
        } catch (Throwable $e) {
            $errors[] = 'انجام نشد: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>بازیابی حساب مدیر</title>
<style>
  body { font-family: system-ui, sans-serif; background: #131210; color: #F2EDE3;
         margin: 0; padding: 2.5rem 1.25rem; line-height: 1.9; }
  .box { max-width: 44rem; margin: 0 auto; }
  h1 { font-size: 1.4rem; margin: 0 0 1.5rem; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 2rem; font-size: .9rem; }
  th, td { text-align: right; padding: .55rem .6rem; border-bottom: 1px solid rgba(242,237,227,.14); }
  th { color: rgba(242,237,227,.6); font-weight: 600; }
  .lat { direction: ltr; unicode-bidi: isolate; font-family: ui-monospace, monospace; }
  form { background: #1C1A17; border: 1px solid rgba(242,237,227,.13);
         border-radius: 10px; padding: 1.3rem; }
  label { display: block; margin-bottom: .3rem; font-size: .9rem; }
  select, input { width: 100%; padding: .6rem .7rem; margin-bottom: 1rem;
                  background: #242019; color: inherit; font: inherit;
                  border: 1px solid rgba(242,237,227,.2); border-radius: 7px; }
  input[type=password] { direction: ltr; }
  button { background: #F25914; color: #fff; border: 0; border-radius: 7px;
           padding: .7rem 1.6rem; font: inherit; font-weight: 700; cursor: pointer; }
  .msg { padding: .8rem 1rem; border-radius: 8px; margin-bottom: 1.2rem; }
  .ok  { background: rgba(97,189,103,.14); border-inline-start: 3px solid #61BD67; }
  .err { background: rgba(242,89,20,.14); border-inline-start: 3px solid #F25914; }
  .warn { background: rgba(249,124,61,.14); border-inline-start: 3px solid #F97C3D;
          margin-top: 2rem; padding: 1rem 1.15rem; border-radius: 8px; }
</style>
</head>
<body>
<div class="box">

  <h1>بازیابی حساب مدیر</h1>

  <?php foreach ($done as $m): ?><div class="msg ok"><?= e($m) ?></div><?php endforeach; ?>
  <?php foreach ($errors as $m): ?><div class="msg err"><?= e($m) ?></div><?php endforeach; ?>

  <?php if ($dbError !== ''): ?>
    <div class="msg err">
      به دیتابیس وصل نشد. یعنی مشکل از رمز نیست، از فایل تنظیمات است:
      <span class="lat"><?= e($dbError) ?></span>
    </div>
  <?php elseif (!$admins): ?>
    <div class="msg err">
      <b>هیچ حساب مدیری در دیتابیس نیست.</b> یعنی <span class="lat">setup.php</span>
      حساب را نساخته — احتمالاً فرم خطا داده و پیام موفقیت نیامده. این فایل را حذف
      کنید، <span class="lat">setup.php</span> را دوباره آپلود کنید و یک بار دیگر اجرایش کنید.
    </div>
  <?php else: ?>
    <p>این حساب‌ها در دیتابیس هستند:</p>
    <table>
      <thead><tr>
        <th>#</th><th>نام کاربری</th><th>نقش</th><th>فعال</th>
        <th>طول هش</th><th>ساخته شده</th><th>آخرین ورود</th>
      </tr></thead>
      <tbody>
        <?php foreach ($admins as $a): ?>
          <tr>
            <td class="lat"><?= (int) $a['id'] ?></td>
            <td class="lat"><?= e((string) $a['username']) ?></td>
            <td><?= e((string) $a['role']) ?></td>
            <td><?= (int) $a['is_active'] === 1 ? 'بله' : '<b>خیر</b>' ?></td>
            <td class="lat"><?= (int) $a['hash_len'] ?></td>
            <td class="lat"><?= e((string) $a['created_at']) ?></td>
            <td class="lat"><?= e((string) ($a['last_login_at'] ?? '—')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <p>
      <b>نام کاربری را دقیقاً از همین جدول بخوانید.</b> اگر آن چیزی نیست که فکر
      می‌کردید، احتمالاً <span class="lat">setup.php</span> را دو بار پر کرده‌اید و
      فقط بار اول ثبت شده. اگر «فعال» خیر است، رمز عوض‌کردن از همین‌جا آن را هم
      روشن می‌کند. طول هش باید <span class="lat">60</span> باشد.
    </p>

    <form method="post">
      <label for="id">حساب</label>
      <select id="id" name="id">
        <?php foreach ($admins as $a): ?>
          <option value="<?= (int) $a['id'] ?>"><?= e((string) $a['username']) ?></option>
        <?php endforeach; ?>
      </select>

      <label for="password">رمز جدید — حداقل ۱۰ کاراکتر</label>
      <input type="password" id="password" name="password" required autocomplete="new-password">

      <label for="password2">دوباره</label>
      <input type="password" id="password2" name="password2" required autocomplete="new-password">

      <button type="submit">ثبت رمز جدید</button>
    </form>
  <?php endif; ?>

  <div class="warn">
    <b>بعد از اینکه وارد شدید، این فایل را حذف کنید.</b>
    تا وقتی روی سرور است، صفحه‌ای که می‌تواند رمز مدیر را عوض کند روی سایت شما باز
    است — و صفحهٔ «آمادگی راه‌اندازی» هم تا آن موقع سبز نمی‌شود.
  </div>

</div>
</body>
</html>
