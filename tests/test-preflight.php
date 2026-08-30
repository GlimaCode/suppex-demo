<?php
/* Exercises the launch preflight checks and the page that renders them. */

$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = 'suppex.ir';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/admin/preflight.php';
$_SERVER['SCRIPT_NAME']    = '/admin/preflight.php';

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/preflight.php';

$pass = 0; $fail = 0;
function ok(string $l, bool $c, ?string $x = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $l\n"; }
    else { $fail++; echo "  FAIL $l" . ($x ? "  --  $x" : '') . "\n"; }
}

function check_for(string $label): ?array {
    foreach (preflight_checks() as $c) {
        if ($c['label'] === $label) { return $c; }
    }
    return null;
}
function state_of(string $label): string { return check_for($label)['state'] ?? 'missing'; }
function detail_of(string $label): string { return check_for($label)['detail'] ?? ''; }

db_query('INSERT INTO admins (id, username, password_hash, name, role)
          VALUES (1, "tester", ?, "tester", "owner")
          ON DUPLICATE KEY UPDATE username = username', [password_hash('x', PASSWORD_DEFAULT)]);

/* A known-good baseline, so each test can break exactly one thing. */
settings_save([
    'card_number' => '6104337600000002', 'card_holder' => 'تستر',
    'telegram_url' => 'https://t.me/example', 'bale_url' => '', 'whatsapp_url' => '',
    'aed_rate' => '25000',
]);

echo "\n== the checks describe reality, not a fixed list ==\n";
$all = preflight_checks();
ok('returns a populated list', count($all) > 12, (string) count($all));
ok('every entry is well-formed',
   count(array_filter($all, static fn($c) =>
       isset($c['group'], $c['label'], $c['state'], $c['detail'], $c['fix'])
       && in_array($c['state'], ['ok', 'warn', 'blocker'], true))) === count($all));
ok('every non-ok entry names a fix',
   count(array_filter($all, static fn($c) => $c['state'] !== 'ok' && trim($c['fix']) === '')) === 0,
   implode(', ', array_map(static fn($c) => $c['label'],
       array_filter($all, static fn($c) => $c['state'] !== 'ok' && trim($c['fix']) === ''))));

echo "\n== the card check reacts to the setting ==\n";
settings_save(['card_number' => '']);
ok('missing card blocks', state_of('شماره کارت') === 'blocker', state_of('شماره کارت'));
ok('and says so', detail_of('شماره کارت') === 'ثبت نشده', detail_of('شماره کارت'));

settings_save(['card_number' => '6104337600000001']);   // Luhn-invalid
ok('a mistyped card blocks too', state_of('شماره کارت') === 'blocker');
ok('and is described as a typo', mb_strpos(detail_of('شماره کارت'), 'اشتباه') !== false,
   detail_of('شماره کارت'));

settings_save(['card_number' => '6104337600000002']);
ok('a valid card clears', state_of('شماره کارت') === 'ok', state_of('شماره کارت'));

echo "\n== fixing something moves the total ==\n";
settings_save(['card_number' => '', 'telegram_url' => '']);
$before = preflight_summary(preflight_checks())['blockers'];
settings_save(['card_number' => '6104337600000002', 'telegram_url' => 'https://t.me/example']);
$after  = preflight_summary(preflight_checks())['blockers'];
ok('two fixes remove two blockers', $after === $before - 2, "$before -> $after");

echo "\n== catalogue checks read the database ==\n";
$active = (int) db_value('SELECT COUNT(*) FROM products WHERE is_active = 1');
ok('active product count is the real one', detail_of('محصول فعال') === (string) $active, $active . '');

db_query("UPDATE products SET price = 0 WHERE slug = 'iso-zero'");
ok('a zero-priced product blocks', state_of('محصول بدون قیمت') === 'blocker');
ok('and is counted', detail_of('محصول بدون قیمت') === '1', detail_of('محصول بدون قیمت'));
db_query("UPDATE products SET price = 5690000 WHERE slug = 'iso-zero'");
ok('and clears once fixed', state_of('محصول بدون قیمت') === 'ok');

echo "\n== the dirham rate is only required when something needs it ==\n";
db_query("UPDATE products SET price_mode = 'manual', cost_aed = NULL");
db_query('UPDATE product_sizes SET cost_aed = NULL');
settings_save(['aed_rate' => '']);
ok('no rate needed when nothing is dirham-priced', state_of('نرخ درهم') === 'ok',
   state_of('نرخ درهم'));

db_query("UPDATE products SET price_mode = 'aed', cost_aed = 40 WHERE slug = 'whey-original'");
ok('but required once something is', state_of('نرخ درهم') === 'blocker', state_of('نرخ درهم'));
settings_save(['aed_rate' => '25000']);
ok('and satisfied by setting it', state_of('نرخ درهم') === 'ok');
db_query("UPDATE products SET price_mode = 'manual', cost_aed = NULL WHERE slug = 'whey-original'");

echo "\n== server checks report the running environment ==\n";
ok('PHP version is the real one', detail_of('نسخه PHP') === PHP_VERSION, detail_of('نسخه PHP'));
ok('a loaded extension reads as enabled',
   state_of('افزونه pdo_mysql') === 'ok' && detail_of('افزونه pdo_mysql') === 'فعال');
ok('curl is only a warning, never a blocker',
   in_array(state_of('افزونه curl'), ['ok', 'warn'], true), state_of('افزونه curl'));

echo "\n== HTTPS logic ==\n";
unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
ok('plain http blocks', state_of('HTTPS') === 'blocker');
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
ok('a proxy header counts as https', state_of('HTTPS') === 'ok',
   'cPanel often only sets X-Forwarded-Proto');
$_SERVER['HTTPS'] = 'on';
ok('so does HTTPS=on', state_of('HTTPS') === 'ok');

echo "\n== setup.php is a blocker once an admin exists ==\n";
ok('an existing admin is detected', preflight_has_admin());
$setup = check_for('حذف setup.php');
ok('and the leftover installer blocks',
   is_file(SUPPEX_ROOT . '/setup.php') ? $setup['state'] === 'blocker' : $setup['state'] === 'ok',
   $setup['state']);

echo "\n== the page renders ==\n";
auth_start_session();
$_SESSION['admin_id'] = 1; $_SESSION['admin_name'] = 'tester'; $_SESSION['admin_role'] = 'owner';
ob_start(); include SUPPEX_ROOT . '/admin/preflight.php'; $html = ob_get_clean();
ok('renders a page', strlen($html) > 4000, (string) strlen($html));
/* Matched on a fragment that does not straddle the template's line break. */
ok('shows the verdict banner',
   mb_strpos($html, 'آماده است') !== false || mb_strpos($html, 'مورد باید قبل از') !== false);
ok('lists what it cannot check', mb_strpos($html, 'نمی‌تواند بررسی کند') !== false);
ok('links the rewrite test', mb_strpos($html, 'sitemap.xml') !== false);
ok('no PHP leaked', mb_strpos($html, '<?') === false);
ok('every group appears',
   mb_strpos($html, 'سرور') !== false && mb_strpos($html, 'کاتالوگ') !== false
   && mb_strpos($html, 'امنیت') !== false);

echo "\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
