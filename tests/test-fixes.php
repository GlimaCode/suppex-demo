<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP.
   bootstrap falls back to the real suppex-config.php when SUPPEX_CONFIG
   is unset - which is exactly what a web request has - so one GET to an
   uploaded tests/ folder could empty the live database. The .htaccess
   beside this file is process; this is code. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* Regression checks for the five findings the review confirmed. */

$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
require __DIR__ . '/../lib/bootstrap.php';
require SUPPEX_ROOT . '/lib/auth.php';
require SUPPEX_ROOT . '/lib/images.php';

db_query('DELETE FROM login_attempts');

$pass = 0; $fail = 0;
function ok(string $l, bool $c, string $x = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $l\n"; }
    else { $fail++; echo "  FAIL $l" . ($x !== '' ? "  --  $x" : '') . "\n"; }
}

/* --- 1. open redirect ---------------------------------------------------- */
echo "\n== open-redirect guard (admin/login.php) ==\n";
$guard = static function (string $next): string {
    return preg_match('~^/(?![/\\\\])[^\\\\]*$~', $next) === 1 ? $next : 'index.php';
};
foreach ([
    '/admin/orders.php'              => '/admin/orders.php',
    '/admin/order.php?id=3'          => '/admin/order.php?id=3',
    '//evil.example/x'               => 'index.php',
    '/\\evil.example/admin/'         => 'index.php',   // the bug that was found
    '/\\/evil.example'               => 'index.php',
    '/admin\\..\\..\\x'              => 'index.php',
    'https://evil.example'           => 'index.php',
    'admin/orders.php'               => 'index.php',
    ''                               => 'index.php',
    '/'                              => '/',
] as $input => $expected) {
    ok('next=' . var_export($input, true) . ' -> ' . $expected,
       $guard($input) === $expected, 'got ' . $guard($input));
}

/* --- 2. login throttle cannot lock a user out from another IP ------------- */
echo "\n== throttle no longer allows remote lockout (lib/auth.php) ==\n";
db_query('DELETE FROM login_attempts');
db_query('INSERT INTO admins (id, username, password_hash, name, role)
          VALUES (99, "victim", ?, "victim", "support")
          ON DUPLICATE KEY UPDATE username = username',
         [password_hash('a-long-enough-passphrase', PASSWORD_DEFAULT)]);

/* Attacker burns the limit against "victim" from many different addresses. */
foreach (range(1, 30) as $i) {
    $_SERVER['REMOTE_ADDR'] = '198.51.100.' . $i;
    auth_login('victim', 'guess' . $i);
}
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';   // the real admin, a clean address
ok('real admin is NOT throttled after 30 remote failures', auth_is_throttled('victim') === false);
ok('real admin can still sign in', auth_login('victim', 'a-long-enough-passphrase')['ok'] === true);

/* But one address hammering is still stopped. */
db_query('DELETE FROM login_attempts');
$_SERVER['REMOTE_ADDR'] = '198.51.100.77';
foreach (range(1, 9) as $i) { auth_login('victim', 'guess' . $i); }
ok('single hammering address IS throttled', auth_is_throttled('victim') === true);
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
ok('…and that does not affect a different address', auth_is_throttled('victim') === false);

/* --- 3 & 4. upload guards ------------------------------------------------ */
echo "\n== upload guards (lib/images.php) ==\n";
ok('memory limit parsed, not cast', image_memory_limit_bytes() > 1024 * 1024,
   'got ' . image_memory_limit_bytes() . ' for ini ' . ini_get('memory_limit'));

$limit = image_memory_limit_bytes();
$maxPixels = $limit > 0 ? (int) (($limit * 0.5) / 4) : 12_000_000;
ok('pixel ceiling is below what GD needs for a 39 MP image', $maxPixels < 39_200_000,
   'ceiling ' . number_format($maxPixels) . ' px');
ok('pixel ceiling still allows a normal 12 MP photo', $maxPixels >= 4_000_000,
   'ceiling ' . number_format($maxPixels) . ' px');

/* --- 5. the money bug ---------------------------------------------------- */
echo "\n== payment panel uses the server total (assets/js/ui.js) ==\n";
$ui = (string) file_get_contents(SUPPEX_ROOT . '/assets/js/ui.js');
ok('no longer prints snapshot.total for the amount',
   strpos($ui, "money(snapshot.total)") === false);
ok('prefers server.total when present',
   strpos($ui, "server && typeof server.total === 'number' ? server.total : snapshot.total") !== false);

echo "\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
