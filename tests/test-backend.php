<?php
/* Local integration harness — exercises schema, seed, model layer, order
   creation, auth and the settlement report against a real MariaDB. Not part
   of the deliverable. */

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require __DIR__ . '/../lib/bootstrap.php';
require SUPPEX_ROOT . '/lib/products.php';
require SUPPEX_ROOT . '/lib/orders.php';
require SUPPEX_ROOT . '/lib/auth.php';

$pass = 0; $failCount = 0;
function ok(string $label, bool $cond, string $extra = ''): void {
    global $pass, $failCount;
    if ($cond) { $pass++; echo "  ok   $label\n"; }
    else { $failCount++; echo "  FAIL $label" . ($extra !== '' ? "  --  $extra" : '') . "\n"; }
}

/* Every suite shares one test database, so each starts from a known state.
   Without this the order counts and settlement totals quietly pick up rows
   left by whichever suite ran before, and the arithmetic assertions drift. */
function reset_tables(): void {
    db()->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['orders', 'order_items', 'order_events', 'login_attempts', 'rate_history'] as $t) {
        try { db()->exec('TRUNCATE TABLE ' . $t); } catch (Throwable $e) { /* not created yet */ }
    }
    db()->exec('SET FOREIGN_KEY_CHECKS = 1');
}

echo "\n== schema ==\n";
foreach (db_sql_statements((string) file_get_contents(SUPPEX_ROOT . '/db/schema.sql')) as $st) {
    try { db()->exec($st); }
    catch (Throwable $e) {
        echo "  FAIL statement: " . substr($st, 0, 70) . "\n       " . $e->getMessage() . "\n";
        $failCount++;
    }
}
/* SHOW TABLES names its column after the database (Tables_in_<db>), so read
   the first column positionally instead of hard-coding one database name. */
reset_tables();
$tables = array_map(static fn(array $r) => array_values($r)[0], db_all('SHOW TABLES'));
foreach (['admins','login_attempts','categories','products','product_images','product_flavors',
          'product_sizes','orders','order_items','order_events','settings'] as $t) {
    ok("table $t", in_array($t, $tables, true));
}

echo "\n== seed ==\n";
require SUPPEX_ROOT . '/db/seed.php';
$counts = suppex_seed();
ok('categories seeded', $counts['categories'] === 6, json_encode($counts));
ok('products seeded',   $counts['products'] === 15, json_encode($counts));
ok('seed is idempotent', suppex_seed()['products'] === 15
     && (int) db_value('SELECT COUNT(*) FROM products') === 15);

echo "\n== catalogue model ==\n";
$cats = categories_list();
ok('categories_list shape', isset($cats[0]['id'], $cats[0]['name'], $cats[0]['count']));
ok('category counts products', $cats[0]['count'] > 0, json_encode($cats[0]));

$list = products_list(['limit' => 5]);
ok('products_list limit', count($list) === 5);
ok('products_list shape', isset($list[0]['slug'], $list[0]['nameFa'], $list[0]['price'], $list[0]['onSale']));
ok('badges is an array', is_array($list[0]['badges']));

$byBadge = products_list(['badge' => 'bestseller']);
ok('badge filter narrows', count($byBadge) > 0 && count($byBadge) < 15, count($byBadge) . ' hits');

$byCat = products_list(['category' => 'protein']);
ok('category filter narrows', count($byCat) > 0 && count($byCat) < 15, count($byCat) . ' hits');

$one = product_get('whey-original');
ok('product_get found', $one !== null);
ok('gallery loaded', count($one['gallery']) === 4, count($one['gallery']) . ' images');
ok('flavors loaded', count($one['flavors']) === 4);
ok('sizes loaded',   count($one['sizes']) === 3);
ok('nutrition rows loaded', count($one['nutrition']['rows']) === 7);
ok('discount derived', $one['onSale'] === true && $one['discountPercent'] === 18,
   $one['discountPercent'] . '%');
ok('missing product returns null', product_get('does-not-exist') === null);

ok('search finds persian', count(products_search('وی')) > 0);
ok('search escapes wildcards', count(products_search('%')) === 0,
   count(products_search('%')) . ' hits for a bare %');
ok('blank search returns none', products_search('   ') === []);

echo "\n== settings ==\n";
/* Pinned explicitly, including the basis. A suite that inherits ambient
   settings from whichever suite ran before it is not testing the code, it
   is testing the order the suites happened to run in. */
settings_save(['commission_percent' => '12.5', 'commission_basis' => 'goods',
               'shipping_flat_rate' => '65000',
               'free_shipping_threshold' => '1500000',
               'order_hold_minutes' => '30']);
ok('reads back after save', setting('commission_percent') === '12.5', setting('commission_percent'));
ok('setting_float', setting_float('commission_percent') === 12.5);
ok('setting_int',   setting_int('shipping_flat_rate') === 65000);

echo "\n== order creation ==\n";
$payload = [
  'items' => [
    ['slug' => 'whey-original', 'flavorId' => 'chocolate', 'sizeId' => '1800', 'qty' => 2],
    ['slug' => 'iso-zero', 'qty' => 1],
  ],
  'customer' => [
    'name'    => 'علی رضایی',
    'phone'   => '۰۹۱۲۳۴۵۶۷۸۹',
    'address' => 'تهران، خیابان ولیعصر، پلاک ۲۴۱، واحد ۵',
    'postal'  => '۱۹۶۷۷۱۳۱۱۱',
    'note'    => 'بعدازظهر تحویل بگیرید',
  ],
  'channel' => 'telegram',
];
$r = order_create($payload);
ok('order created', $r['ok'] === true, json_encode($r['errors'] ?? [], JSON_UNESCAPED_UNICODE));
$order = $r['order'];
ok('code format', preg_match('/^SPX-\d{6}-[A-Z0-9]{4}$/', $order['code']) === 1, $order['code']);
ok('persian phone digits normalised', $order['phone'] === '09123456789', $order['phone']);
ok('persian postal normalised', $order['postal'] === '1967713111', $order['postal']);
ok('two item rows', count($order['items']) === 2);

$expectedSub = 4590000 * 2 + 5690000;
ok('subtotal computed from DB prices', (int) $order['subtotal'] === $expectedSub,
   $order['subtotal'] . ' vs expected ' . $expectedSub);
ok('free shipping applied', (int) $order['shipping'] === 0);
ok('total equals subtotal', (int) $order['total'] === $expectedSub);
ok('commission rate snapshotted', (float) $order['commission_percent'] === 12.5);
ok('commission amount', (int) $order['commission_amount'] === (int) round($expectedSub * 0.125),
   (string) $order['commission_amount']);
ok('variant label built from flavour+size',
   $order['items'][0]['variant_label'] === 'شکلات · 1800 گرم', $order['items'][0]['variant_label']);
ok('creation event logged', count($order['events']) === 1);

echo "\n== client-supplied prices are ignored ==\n";
$evil = $payload;
$evil['items'][0]['price'] = 1;
$evil['items'][0]['unit_price'] = 1;
$evil['subtotal'] = 1;
$evil['total'] = 1;
$evil['commission_amount'] = 0;
$r2 = order_create($evil);
ok('tampered prices rejected', (int) $r2['order']['subtotal'] === $expectedSub,
   (string) $r2['order']['subtotal']);

echo "\n== validation ==\n";
$bad = order_create(['items' => $payload['items'],
                     'customer' => ['name' => '', 'phone' => '12345', 'address' => 'x', 'postal' => '99']]);
ok('rejects incomplete customer', $bad['ok'] === false);
ok('flags name',    isset($bad['errors']['name']));
ok('flags phone',   isset($bad['errors']['phone']));
ok('flags address', isset($bad['errors']['address']));
ok('flags postal',  isset($bad['errors']['postal']));
ok('rejects empty cart',
   order_create(['items' => [], 'customer' => $payload['customer']])['ok'] === false);
ok('rejects unknown slug',
   order_create(['items' => [['slug' => 'nope', 'qty' => 1]],
                 'customer' => $payload['customer']])['ok'] === false);

$clamped = order_create(['items' => [['slug' => 'iso-zero', 'qty' => 99999]],
                         'customer' => $payload['customer']]);
ok('quantity clamped to 99', (int) $clamped['order']['items'][0]['qty'] === 99,
   (string) $clamped['order']['items'][0]['qty']);

echo "\n== shipping rule ==\n";
settings_save(['free_shipping_threshold' => '999999999']);
$small = order_create(['items' => [['slug' => 'iso-zero', 'qty' => 1]],
                       'customer' => $payload['customer']]);
ok('flat rate below threshold', (int) $small['order']['shipping'] === 65000,
   (string) $small['order']['shipping']);
ok('total includes shipping',
   (int) $small['order']['total'] === (int) $small['order']['subtotal'] + 65000);
settings_save(['free_shipping_threshold' => '1500000']);

echo "\n== status changes and audit trail ==\n";
db_query('INSERT INTO admins (id, username, password_hash, name, role)
          VALUES (1, "tester", ?, "تستر", "owner")
          ON DUPLICATE KEY UPDATE username = username',
         [password_hash('x', PASSWORD_DEFAULT)]);
$admin = ['id' => 1, 'name' => 'تستر'];

ok('set status to paid', order_set_status((int) $order['id'], 'paid', $admin, 'رسید دریافت شد'));
$after = order_get((int) $order['id']);
ok('status changed', $after['status'] === 'paid');
ok('paid_at stamped', $after['paid_at'] !== null);
ok('event appended', count($after['events']) === 2);
ok('event records who', $after['events'][1]['admin_name'] === 'تستر');
$paidAt = $after['paid_at'];
order_set_status((int) $order['id'], 'shipped', $admin);
ok('paid_at not reset by a later status',
   order_get((int) $order['id'])['paid_at'] === $paidAt);
ok('rejects unknown status', order_set_status((int) $order['id'], 'banana', $admin) === false);

echo "\n== settlement report ==\n";
$today = date('Y-m-d');
$rep = orders_settlement($today, $today);
ok('counts only paid orders', $rep['order_count'] === 1, $rep['order_count'] . ' counted');
ok('goods total matches', $rep['goods_total'] === $expectedSub, (string) $rep['goods_total']);
ok('commission total matches', $rep['commission_total'] === (int) round($expectedSub * 0.125));

settings_save(['commission_percent' => '30']);
$repAfter = orders_settlement($today, $today);
settings_save(['commission_percent' => '12.5']);
ok('changing the rate does not rewrite history',
   $repAfter['commission_total'] === (int) round($expectedSub * 0.125),
   (string) $repAfter['commission_total']);

echo "\n== auth ==\n";
db_query('UPDATE admins SET password_hash = ? WHERE id = 1',
         [password_hash('correct-horse-battery', PASSWORD_DEFAULT)]);
ok('rejects wrong password', auth_login('tester', 'wrong')['ok'] === false);
ok('rejects unknown user',   auth_login('nobody', 'whatever')['ok'] === false);
ok('identical error for both cases',
   auth_login('tester', 'wrong')['error'] === auth_login('nobody', 'x')['error']);
ok('accepts the right password', auth_login('tester', 'correct-horse-battery')['ok'] === true);
ok('failures cleared on success', (int) db_value('SELECT COUNT(*) FROM login_attempts') === 0);

for ($i = 0; $i < 8; $i++) { auth_login('victim', 'guess' . $i); }
ok('throttles after 8 failures', auth_is_throttled('victim'));
$blocked = auth_login('victim', 'guess');
ok('throttled login refused', $blocked['ok'] === false && str_contains($blocked['error'], 'صبر'));

echo "\n== helpers ==\n";
ok('luhn rejects the placeholder card', is_valid_card_number('6037 9911 0000 0000') === false);
ok('luhn rejects a short number', is_valid_card_number('60379911') === false);
/* 4111111111111111 is the canonical Luhn test number; 6037991234567893 is a
   Bank Melli BIN with a correctly computed check digit. */
ok('luhn accepts canonical test number', is_valid_card_number('4111111111111111'));
ok('luhn accepts a valid iranian number', is_valid_card_number('6037991234567893'));
ok('luhn rejects a single flipped digit', is_valid_card_number('6037991234567894') === false);
ok('mobile validator', is_valid_mobile('۰۹۱۲۳۴۵۶۷۸۹') && !is_valid_mobile('0812345678'));
ok('postal validator', is_valid_postal('1967713111') && !is_valid_postal('196771311'));
ok('parse_money handles persian separators', parse_money('۲,۴۵۰,۰۰۰') === 2450000,
   (string) parse_money('۲,۴۵۰,۰۰۰'));
ok('slugify keeps persian letters', slugify('وی پروتئین') === 'وی-پروتئین', slugify('وی پروتئین'));
ok('slugify latin', slugify('Whey Original 900g') === 'whey-original-900g',
   slugify('Whey Original 900g'));
ok('unique_slug increments', unique_slug('whey-original', 'products') === 'whey-original-2',
   unique_slug('whey-original', 'products'));
ok('esc escapes markup', e('<script>') === '&lt;script&gt;');
ok('lines_to_array drops blanks', lines_to_array("a\n\n b \n") === ['a', 'b']);
ok('clean_text collapses whitespace', clean_text("  a   b  ") === 'a b');

echo "\n----------------------------------------\n";
echo "passed: $pass    failed: $failCount\n";
exit($failCount > 0 ? 1 : 0);
