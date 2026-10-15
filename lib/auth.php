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
    $admin = db_one('SELECT id, name, username, role, is_active, password_hash FROM admins WHERE id = ?',
                    [(int) $_SESSION['admin_id']]);
    $fingerprint = $admin === null ? '' : hash('sha256', (string) $admin['password_hash']);
    if ($admin === null || !(int) $admin['is_active']
        || empty($_SESSION['admin_auth'])
        || !hash_equals($fingerprint, (string) $_SESSION['admin_auth'])) {
        /* Old sessions must sign in once after this security upgrade. */
        $_SESSION = [];
        session_regenerate_id(true);
        return null;
    }
    $_SESSION['admin_name'] = $admin['name'] !== '' ? $admin['name'] : $admin['username'];
    $_SESSION['admin_role'] = $admin['role'];
    return [
        'id'   => (int) $_SESSION['admin_id'],
        'name' => (string) ($_SESSION['admin_name'] ?? ''),
        'role' => (string) ($_SESSION['admin_role'] ?? 'staff'),
    ];
}

/** Guard at the top of every admin page. */
/* --- Roles ------------------------------------------------------------------
   The role column has existed since the first schema and nothing ever read it,
   so until now every signed-in admin could open backup.php - which streams
   every customer's name, phone and address, and every admin password hash.

   Two roles, and the naming is deliberate even though it inverts the usual
   ranking: «پشتیبان» is whoever keeps the software running and needs
   everything; «مدیرکل» runs the shop and has no use for a database dump or a
   deployment readiness page. */
const ROLES = ['support' => 'پشتیبان', 'manager' => 'مدیرکل'];

/* What each role may do beyond the ordinary running of the shop.

   Only the exceptions are listed. Everything not named here - products,
   orders, categories, pricing, stock, settings, reports, import - is open to
   both roles, because both people genuinely need it and a permission system
   that has to be consulted for ordinary work is one that gets switched off. */
const ROLE_CAPABILITIES = [
    'support' => ['maintenance'],
    'manager' => [],

    /* The legacy value, for the minutes between uploading this code and
       running the migration that renames it. Without this line the panel
       silently loses three menu items during that window - and the person
       staring at the reduced menu is the one who has to go and run the
       migration, from a page that is not in the menu either.

       It costs nothing afterwards: once migrate has run, no row holds 'owner'
       and this entry can never match again. It is an explicit legacy name, not
       a fallback - an unknown role still gets nothing. */
    'owner'   => ['maintenance'],
];

/** May the signed-in admin do this? */
function auth_can(string $capability): bool
{
    $user = auth_user();
    if ($user === null) {
        return false;
    }
    return auth_role_can((string) $user['role'], $capability);
}

function auth_role_can(string $role, string $capability): bool
{
    /* An unknown role gets nothing extra rather than everything. A row whose
       role was hand-edited to something misspelled must fail closed. */
    return in_array($capability, ROLE_CAPABILITIES[$role] ?? [], true);
}

/**
 * Require a capability, or send them somewhere they are allowed to be.
 *
 * Called as the FIRST act of every gated page. Hiding the nav item alone would
 * be theatre - the URL still works, and a bookmark or a guessed path reaches
 * it - so the page refuses, and the nav merely stops advertising what cannot
 * be opened.
 */
function auth_require_can(string $capability): array
{
    $user = auth_require();
    if (!auth_can($capability)) {
        /* flash() lives in admin/partials/layout.php, which some pages load
           AFTER auth - preflight.php does. Guarded rather than required, so
           the refusal never turns into a fatal error on the one path where
           the refusal is the point. */
        if (function_exists('flash')) {
            flash('error', 'این بخش برای نقش شما در دسترس نیست.');
        }
        header('Location: index.php');
        exit;
    }
    return $user;
}

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
   Two limits, both scoped to the requesting address:

     * failures from this IP against any username  — stops one attacker working
       through a list of usernames;
     * failures from this IP against THIS username — stops one attacker working
       through a list of passwords.

   Neither counter is global to the username, and that is the important part.
   An earlier version here counted failures for a username across all
   addresses, which meant anyone who knew the admin's username could lock them
   out of their own shop from anywhere, permanently, by failing on purpose once
   every couple of minutes. Refusing the real owner is a worse outcome than the
   guessing attempt the counter was meant to stop.

   The trade is that a guesser spread across many addresses is not rate-limited
   by username. That is the accepted cost: a long password defeats distributed
   guessing, while nothing in the application can defeat a lockout that the
   application itself imposes. */
function auth_is_throttled(string $username): bool
{
    $cfg    = suppex_config()['login'] ?? [];
    $max    = (int) ($cfg['max_attempts'] ?? 8);
    $window = (int) ($cfg['window_minutes'] ?? 15);
    $since  = date('Y-m-d H:i:s', time() - $window * 60);
    $ip     = client_ip_binary();

    $byIp = (int) db_value(
        'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > ?',
        [$ip, $since]
    );
    $byIpAndUser = (int) db_value(
        'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND username = ? AND attempted_at > ?',
        [$ip, $username, $since]
    );

    return $byIp >= $max || $byIpAndUser >= $max;
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
        $admin['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        db_query('UPDATE admins SET password_hash = ? WHERE id = ?',
            [$admin['password_hash'], (int) $admin['id']]);
    }

    session_regenerate_id(true);
    $_SESSION['admin_id']   = (int) $admin['id'];
    $_SESSION['admin_name'] = $admin['name'] !== '' ? $admin['name'] : $admin['username'];
    $_SESSION['admin_role'] = $admin['role'];
    $_SESSION['admin_auth'] = hash('sha256', (string) $admin['password_hash']);

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
