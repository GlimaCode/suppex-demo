<?php
/* ===========================================================================
   SUPPEX — Admin authentication
   ---------------------------------------------------------------------------
   Session login for the admin panel, plus CSRF tokens for every form that
   changes something.

   Three things here are not optional and are easy to leave out:

     1. Passwords are stored with password_hash() and checked with
        password_verify(). Never md5, sha1, or a "salted" hand-rolled scheme —
        those are all fast, and fast is precisely what a password hash must
        not be.

     2. session_regenerate_id() runs on login. Without it, an attacker who can
        set a session id before sign-in still holds a valid one afterwards
        (session fixation).

     3. Failed attempts are counted and throttled. A login form with no limit
        is a password guesser's free pass, and shared hosting has no fail2ban
        watching for it.
   =========================================================================== */

declare(strict_types=1);

function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $cfg = suppex_config();

    session_name($cfg['session_name'] ?? 'suppex_admin');
    session_set_cookie_params([
        'lifetime' => 0,                       // dies with the browser session
        'path'     => '/',
        'httponly' => true,                    // JavaScript cannot read it, so XSS cannot steal it
        'secure'   => (bool) ($cfg['https_only'] ?? false),
        'samesite' => 'Lax',                   // blocks the cross-site form-post shape of CSRF
    ]);
    session_start();
}

function auth_user(): ?array
{
    auth_start_session();
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    return [
        'id'   => (int) $_SESSION['admin_id'],
        'name' => (string) ($_SESSION['admin_name'] ?? ''),
        'role' => (string) ($_SESSION['admin_role'] ?? 'staff'),
    ];
}

/** Guard at the top of every admin page. */
function auth_require(): array
{
    $user = auth_user();
    if ($user === null) {
        $target = $_SERVER['REQUEST_URI'] ?? '/admin/';
        header('Location: /admin/login.php?next=' . rawurlencode($target));
        exit;
    }
    return $user;
}

/* --- Login throttle --------------------------------------------------------
   Counted per IP and per username so that neither an attacker spraying one
   username from many addresses, nor one address trying many usernames, gets a
   free run. Locking on username alone would also let anyone lock a real admin
   out by failing on purpose, which is why the IP condition is an OR of two
   separately-counted limits rather than a single combined key. */
function auth_is_throttled(string $username): bool
{
    $cfg    = suppex_config()['login'] ?? [];
    $max    = (int) ($cfg['max_attempts'] ?? 8);
    $window = (int) ($cfg['window_minutes'] ?? 15);
    $since  = date('Y-m-d H:i:s', time() - $window * 60);

    $byIp = (int) db_value(
        'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > ?',
        [client_ip_binary(), $since]
    );
    $byUser = (int) db_value(
        'SELECT COUNT(*) FROM login_attempts WHERE username = ? AND attempted_at > ?',
        [$username, $since]
    );

    return $byIp >= $max || $byUser >= $max;
}

function auth_record_failure(string $username): void
{
    db_query('INSERT INTO login_attempts (ip, username) VALUES (?, ?)',
        [client_ip_binary(), mb_substr($username, 0, 64)]);

    /* Housekeeping, since shared hosting rarely has a cron worth relying on. */
    if (random_int(1, 20) === 1) {
        db_query('DELETE FROM login_attempts WHERE attempted_at < ?',
            [date('Y-m-d H:i:s', time() - 86400)]);
    }
}

function auth_clear_failures(string $username): void
{
    db_query('DELETE FROM login_attempts WHERE username = ? OR ip = ?',
        [$username, client_ip_binary()]);
}

/** @return array{ok:bool,error?:string} */
function auth_login(string $username, string $password): array
{
    auth_start_session();

    $username = clean_text($username, 64);
    if ($username === '' || $password === '') {
        return ['ok' => false, 'error' => 'نام کاربری و رمز عبور را وارد کنید.'];
    }

    if (auth_is_throttled($username)) {
        return ['ok' => false, 'error' => 'تلاش‌های ناموفق زیاد بوده است. چند دقیقه صبر کنید و دوباره امتحان کنید.'];
    }

    $admin = db_one(
        'SELECT id, username, password_hash, name, role FROM admins
          WHERE username = ? AND is_active = 1',
        [$username]
    );

    /* One message for both "no such user" and "wrong password". Two different
       messages would confirm which usernames exist, which is the first thing
       an attacker wants to learn. */
    if ($admin === null || !password_verify($password, $admin['password_hash'])) {
        auth_record_failure($username);
        return ['ok' => false, 'error' => 'نام کاربری یا رمز عبور اشتباه است.'];
    }

    /* Re-hash if PHP's default cost has moved on since this password was set. */
    if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
        db_query('UPDATE admins SET password_hash = ? WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), (int) $admin['id']]);
    }

    session_regenerate_id(true);
    $_SESSION['admin_id']   = (int) $admin['id'];
    $_SESSION['admin_name'] = $admin['name'] !== '' ? $admin['name'] : $admin['username'];
    $_SESSION['admin_role'] = $admin['role'];

    db_query('UPDATE admins SET last_login_at = NOW() WHERE id = ?', [(int) $admin['id']]);
    auth_clear_failures($username);

    return ['ok' => true];
}

function auth_logout(): void
{
    auth_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* --- CSRF ------------------------------------------------------------------
   Without this, a page on another site can make the admin's own browser POST
   to this panel — the session cookie rides along and the request succeeds.
   SameSite=Lax blocks the common case, but it is a browser default that can
   be relaxed, so the token stays the real defence. */
function csrf_token(): string
{
    auth_start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Call first in every POST handler. */
function csrf_check(): void
{
    auth_start_session();
    $sent = (string) ($_POST['_csrf'] ?? '');
    /* Constant-time compare: a plain === leaks how much of the token matched
       through timing, which is enough to reconstruct it given enough tries. */
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(419);
        header('Content-Type: text/html; charset=utf-8');
        exit('<p style="font-family:sans-serif;direction:rtl">نشست شما منقضی شده است. '
           . 'صفحه را تازه کنید و دوباره تلاش کنید.</p>');
    }
}
