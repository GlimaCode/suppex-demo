<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';

auth_start_session();

/* Already signed in — no reason to show the form again. */
if (auth_user() !== null) {
    header('Location: index.php');
    exit;
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $result = auth_login((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));

    if ($result['ok']) {
        /* Only same-site paths are honoured as a redirect target. Echoing back
           whatever ?next= contains would turn the login page into an open
           redirect — a link that looks like it goes to this shop and lands the
           admin on a copy of it that harvests the password they just typed. */
        $next = (string) ($_POST['next'] ?? $_GET['next'] ?? '');
        $safe = (strlen($next) > 1 && $next[0] === '/' && $next[1] !== '/')
            ? $next
            : 'index.php';
        header('Location: ' . $safe);
        exit;
    }
    $error = $result['error'];
}

$next = (string) ($_GET['next'] ?? '');
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>ورود — پنل مدیریت SUPPEX</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;700;800;900&family=Archivo:wght@400;600;700;800&family=Archivo+Black&display=swap">
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<div class="login">
  <form class="login__box" method="post" action="login.php">
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">

    <div class="login__logo">SUPPEX</div>
    <p class="login__sub">پنل مدیریت فروشگاه</p>

    <?php if ($error !== ''): ?>
      <div class="flash flash--error"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="field">
      <label for="username">نام کاربری</label>
      <input type="text" id="username" name="username" required autofocus
             autocomplete="username" dir="ltr">
    </div>

    <div class="field">
      <label for="password">رمز عبور</label>
      <input type="password" id="password" name="password" required
             autocomplete="current-password" dir="ltr">
    </div>

    <button class="btn btn--primary btn--block" type="submit">ورود</button>
  </form>
</div>
</body>
</html>
