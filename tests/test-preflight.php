<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP.
   bootstrap falls back to the real suppex-config.php when SUPPEX_CONFIG
   is unset - which is exactly what a web request has - so one GET to an
   uploaded tests/ folder could empty the live database. The .htaccess
   beside this file is process; this is code. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
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
          VALUES (1, "tester", ?, "tester", "support")
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
$_SESSION['admin_id'] = 1; $_SESSION['admin_name'] = 'tester'; $_SESSION['admin_role'] = 'support';
require_once __DIR__ . '/admin-session.php'; test_admin_session();
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


echo "\n== a product imported but never priced ==\n";
/* The catalogue sheets put 999,999,999 in the price column for a row that has
   been researched but not priced yet, and pricing_round_up() lifts it to a
   round 1,000,000,000. «محصول بدون قیمت» only looks for price <= 0, so the
   whole readiness page went green while a product carrying that number sat on
   the home page for customers to read. */
db_query("DELETE FROM products WHERE slug LIKE 'zz-pf-mark%'");
$pfLabel = 'قیمت جایگزین از فایل ورودی';

ok('a clean catalogue passes', state_of($pfLabel) === 'ok', state_of($pfLabel));

$pfId = db_insert(
    "INSERT INTO products (slug, name_fa, price, price_mode, is_active, in_stock)
     VALUES ('zz-pf-mark', 'زد-بی‌قیمت', 1000000000, 'manual', 1, 0)");

ok('an active product at the marker price blocks',
   state_of($pfLabel) === 'blocker', state_of($pfLabel));
/* The check this one exists to cover for. If it ever starts catching the
   marker itself, the new check is redundant and should go. */
ok('  and the old price check still does not see it',
   state_of('محصول بدون قیمت') === 'ok', state_of('محصول بدون قیمت'));
ok('  the fix text names the way out',
   mb_strpos(check_for($pfLabel)['fix'] ?? '', 'نمایش داده شود') !== false);

/* Hiding it is the documented way out and must actually clear the check. */
db_query('UPDATE products SET is_active = 0 WHERE id = ?', [$pfId]);
ok('hiding it from the site clears the check',
   state_of($pfLabel) === 'ok', state_of($pfLabel));

/* A size carries its own price, so a parent can look priced while the size a
   customer actually buys is still on the marker. */
db_query('UPDATE products SET is_active = 1, price = 2500000 WHERE id = ?', [$pfId]);
db_query(
    "INSERT INTO product_sizes (product_id, ext_id, label, price, sort_order)
     VALUES (?, '900', '۹۰۰ گرم', 999999999, 0)", [$pfId]);
ok('a size still on the marker blocks even when the parent is priced',
   state_of($pfLabel) === 'blocker', state_of($pfLabel));

/* A real price, however large, must not trip it. */
db_query('UPDATE product_sizes SET price = 21180000 WHERE product_id = ?', [$pfId]);
ok('a genuinely expensive product is left alone',
   state_of($pfLabel) === 'ok', state_of($pfLabel));


echo "\n== a combination is the third place a price hides ==\n";
/* Once a product has combinations, order_price_line() charges the
   combination and the parent row is only the headline. Both catalogue
   blockers read products.price and product_sizes.price and had never looked
   at product_variants.price, which did not exist when they were written - so
   a grid still holding the import marker sold at 1,000,000,000 while this
   page reported green. Same failure the marker check exists to prevent, one
   table over. */
db_query('UPDATE products SET is_active = 1, price = 2500000 WHERE id = ?', [$pfId]);
db_query('DELETE FROM product_sizes WHERE product_id = ?', [$pfId]);
ok('the catalogue is clean before the combination is added',
   state_of($pfLabel) === 'ok', state_of($pfLabel));

$pfVid = db_insert(
    "INSERT INTO product_variants (product_id, ext_id, price, is_active)
     VALUES (?, '900|choco', 999999999, 1)", [$pfId]);
ok('a combination still on the marker blocks',
   state_of($pfLabel) === 'blocker', state_of($pfLabel));

/* A combination nobody can order is not a blocker - the «فعال» tick is the
   documented way to take one out of circulation. */
db_query('UPDATE product_variants SET is_active = 0 WHERE id = ?', [$pfVid]);
ok('  and switching that combination off clears it',
   state_of($pfLabel) === 'ok', state_of($pfLabel));

/* Zero is the other way a grid arrives unpriced, and «محصول بدون قیمت» could
   not see it either. */
db_query('UPDATE product_variants SET is_active = 1, price = 0 WHERE id = ?', [$pfVid]);
ok('a combination priced at zero blocks',
   state_of('محصول بدون قیمت') === 'blocker', state_of('محصول بدون قیمت'));
ok('  and a real price clears it',
   (static function () use ($pfVid) {
        db_query('UPDATE product_variants SET price = 2630000 WHERE id = ?', [$pfVid]);
        return true;
    })() && state_of('محصول بدون قیمت') === 'ok', state_of('محصول بدون قیمت'));
ok('  and the marker check is happy with it too',
   state_of($pfLabel) === 'ok', state_of($pfLabel));

db_query("DELETE FROM products WHERE slug LIKE 'zz-pf-mark%'");
ok('marker fixtures cleaned up',
   (int) db_value("SELECT COUNT(*) FROM products WHERE slug LIKE 'zz-pf-mark%'") === 0);
echo "\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
