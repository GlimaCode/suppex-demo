<?php
/* ===========================================================================
   SUPPEX admin — panel users
   ---------------------------------------------------------------------------
   There was no way to change a role except by hand in phpMyAdmin, which is
   also why the role column sat unread for so long: a permission you cannot
   assign is a permission nobody uses.

   Only «پشتیبان» reaches this page. A role that can grant itself a capability
   is not a role.
   =========================================================================== */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once __DIR__ . '/partials/layout.php';

$user = auth_require_can('maintenance');

$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    /* Read the target first: every branch below needs to know whether it is
       about to act on the last support account or on the person doing it. */
    $target = $id > 0 ? db_one('SELECT id, username, role, is_active FROM admins WHERE id = ?', [$id]) : null;
    $supports = (int) db_value("SELECT COUNT(*) FROM admins WHERE role = 'support' AND is_active = 1");

    if ($action !== 'create' && $target === null) {
        $errors[] = 'این کاربر وجود ندارد.';

    } elseif ($action === 'role') {
        $role = (string) ($_POST['role'] ?? '');
        if (!isset(ROLES[$role])) {
            $errors[] = 'نقش نامعتبر است.';
        } elseif ($role !== 'support' && (string) $target['role'] === 'support' && $supports <= 1) {
            /* The shop must keep a way back in. Demoting the only support
               account leaves nobody who can reach the backup, the readiness
               page, or this page - including to undo it. */
            $errors[] = 'این تنها حساب «پشتیبان» فعال است. اول یکی دیگر را پشتیبان کنید.';
        } else {
            db_query('UPDATE admins SET role = ? WHERE id = ?', [$role, $id]);
            /* The session carries the role, so a change to your own account
               has to reach it or the nav keeps showing what you just gave up. */
            if ($id === (int) $user['id']) {
                auth_start_session();
                $_SESSION['admin_role'] = $role;
            }
            flash('ok', 'نقش «' . $target['username'] . '» به «' . ROLES[$role] . '» تغییر کرد.');
            header('Location: users.php');
            exit;
        }

    } elseif ($action === 'active') {
        $on = !empty($_POST['is_active']);
        if (!$on && $id === (int) $user['id']) {
            $errors[] = 'نمی‌توانید حساب خودتان را غیرفعال کنید.';
        } elseif (!$on && (string) $target['role'] === 'support' && $supports <= 1) {
            $errors[] = 'این تنها حساب «پشتیبان» فعال است.';
        } else {
            db_query('UPDATE admins SET is_active = ? WHERE id = ?', [$on ? 1 : 0, $id]);
            flash('ok', 'حساب «' . $target['username'] . '» ' . ($on ? 'فعال' : 'غیرفعال') . ' شد.');
            header('Location: users.php');
            exit;
        }

    } elseif ($action === 'password') {
        $pw  = (string) ($_POST['password'] ?? '');
        $pw2 = (string) ($_POST['password2'] ?? '');
        /* Not trimmed, matching setup.php and admin-reset.php. Trimming in one
           place and not another is what produces "the password is right and it
           does not work". */
        if (mb_strlen($pw) < 10) {
            $errors[] = 'رمز باید حداقل ۱۰ کاراکتر باشد.';
        } elseif ($pw !== $pw2) {
            $errors[] = 'دو رمز یکی نیستند.';
        } else {
            db_query('UPDATE admins SET password_hash = ? WHERE id = ?',
                [password_hash($pw, PASSWORD_DEFAULT), $id]);
            /* Somebody whose password was just reset has usually earned a few
               failed attempts; leaving them makes the new password look broken. */
            db_query('DELETE FROM login_attempts WHERE username = ?', [$target['username']]);
            flash('ok', 'رمز «' . $target['username'] . '» عوض شد.');
            header('Location: users.php');
            exit;
        }

    } elseif ($action === 'create') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $name     = clean_text($_POST['name'] ?? '', 120);
        $role     = (string) ($_POST['role'] ?? 'manager');
        $pw       = (string) ($_POST['password'] ?? '');

        if (!preg_match('/^[a-zA-Z0-9._-]{3,64}$/', $username)) {
            $errors[] = 'نام کاربری فقط حروف انگلیسی، عدد، نقطه، خط تیره و زیرخط — بین ۳ تا ۶۴ کاراکتر.';
        } elseif (db_value('SELECT id FROM admins WHERE username = ?', [$username]) !== null) {
            $errors[] = 'این نام کاربری قبلاً گرفته شده است.';
        } elseif (mb_strlen($pw) < 10) {
            $errors[] = 'رمز باید حداقل ۱۰ کاراکتر باشد.';
        } elseif (!isset(ROLES[$role])) {
            $errors[] = 'نقش نامعتبر است.';
        } else {
            db_query('INSERT INTO admins (username, password_hash, name, role) VALUES (?, ?, ?, ?)',
                [$username, password_hash($pw, PASSWORD_DEFAULT),
                 $name !== '' ? $name : $username, $role]);
            flash('ok', 'کاربر «' . $username . '» ساخته شد.');
            header('Location: users.php');
            exit;
        }
    }
}

$admins = db_all('SELECT id, username, name, role, is_active, last_login_at, created_at
                    FROM admins ORDER BY id');

admin_head('کاربران پنل', ['user' => $user]);
?>

<?php foreach ($errors as $m): ?>
  <div class="flash flash--error"><?= e($m) ?></div>
<?php endforeach; ?>

<div class="card">
  <h2 class="card__title">نقش‌ها</h2>
  <p class="u-dim" style="max-width:70ch;line-height:2">
    <strong>پشتیبان</strong> به همه‌جا دسترسی دارد، از جمله پشتیبان‌گیری، آمادگی
    راه‌اندازی و همین صفحه.
    <br>
    <strong>مدیرکل</strong> فروشگاه را می‌گرداند — محصولات، سفارش‌ها،
    قیمت‌گذاری، موجودی، گزارش و تنظیمات — ولی ابزارهای نگهداری برایش نمایش داده
    نمی‌شود و اگر نشانی‌شان را هم مستقیم باز کند، صفحه او را برمی‌گرداند.
  </p>
</div>

<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>نام کاربری</th>
        <th>نام</th>
        <th style="width:11rem">نقش</th>
        <th>وضعیت</th>
        <th>آخرین ورود</th>
        <th style="width:16rem">رمز عبور</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($admins as $a): $me = (int) $a['id'] === (int) $user['id']; ?>
        <tr>
          <td>
            <div class="t-title lat"><?= e((string) $a['username']) ?></div>
            <?php if ($me): ?><div class="t-sub">خودتان</div><?php endif; ?>
          </td>
          <td class="u-dim"><?= e((string) $a['name']) ?></td>
          <td>
            <form method="post" action="users.php" class="row" style="gap:6px">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="role">
              <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
              <select name="role" onchange="this.form.submit()">
                <?php foreach (ROLES as $key => $label): ?>
                  <option value="<?= e($key) ?>"<?= (string) $a['role'] === $key ? ' selected' : '' ?>>
                    <?= e($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <noscript><button class="btn btn--ghost btn--sm" type="submit">ثبت</button></noscript>
            </form>
          </td>
          <td>
            <form method="post" action="users.php">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="active">
              <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
              <input type="hidden" name="is_active" value="<?= (int) $a['is_active'] === 1 ? '' : '1' ?>">
              <button class="pill <?= (int) $a['is_active'] === 1 ? 'pill--paid' : 'pill--off' ?>"
                      type="submit" style="border:0;cursor:pointer"
                      <?= $me ? 'disabled title="حساب خودتان"' : '' ?>>
                <?= (int) $a['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?>
              </button>
            </form>
          </td>
          <td class="u-dim num"><?= e((string) ($a['last_login_at'] ?? '—')) ?></td>
          <td>
            <form method="post" action="users.php" class="row" style="gap:6px">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="password">
              <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
              <input type="password" name="password" placeholder="رمز جدید" autocomplete="new-password" style="width:7rem">
              <input type="password" name="password2" placeholder="دوباره" autocomplete="new-password" style="width:7rem">
              <button class="btn btn--ghost btn--sm" type="submit">ثبت</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card" style="margin-block-start:24px">
  <h2 class="card__title">کاربر جدید</h2>
  <form method="post" action="users.php" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="field">
      <label for="u-username">نام کاربری</label>
      <input type="text" id="u-username" name="username" dir="ltr" required>
      <span class="hint">حروف انگلیسی، عدد، نقطه، خط تیره و زیرخط.</span>
    </div>
    <div class="field">
      <label for="u-name">نام</label>
      <input type="text" id="u-name" name="name">
    </div>
    <div class="field">
      <label for="u-role">نقش</label>
      <select id="u-role" name="role">
        <?php foreach (ROLES as $key => $label): ?>
          <option value="<?= e($key) ?>"<?= $key === 'manager' ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="hint">پیش‌فرض «مدیرکل» است — کمترین دسترسی لازم.</span>
    </div>
    <div class="field">
      <label for="u-pw">رمز عبور</label>
      <input type="password" id="u-pw" name="password" autocomplete="new-password" required>
      <span class="hint">حداقل ۱۰ کاراکتر. یک عبارت چندکلمه‌ای بهتر از یک رمز کوتاه پیچیده است.</span>
    </div>
    <div class="field field--wide">
      <button class="btn btn--primary" type="submit">ساختن کاربر</button>
    </div>
  </form>
</div>

<?php admin_foot(); ?>
