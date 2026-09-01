<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP.
   bootstrap falls back to the real suppex-config.php when SUPPEX_CONFIG
   is unset - which is exactly what a web request has - so one GET to an
   uploaded tests/ folder could empty the live database. The .htaccess
   beside this file is process; this is code. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* Integration harness for dirham-linked pricing, per-variant costs, and the
   profit-basis commission. Runs against a throwaway MariaDB database selected
   by SUPPEX_CONFIG. Not part of the deliverable. */

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/products.php';
require_once SUPPEX_ROOT . '/lib/orders.php';
require_once SUPPEX_ROOT . '/lib/pricing.php';

$pass = 0; $fail = 0;
function ok(string $l, bool $c, ?string $x = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $l\n"; }
    else { $fail++; echo "  FAIL $l" . ($x !== '' ? "  --  $x" : '') . "\n"; }
}

db_run_sql_file(SUPPEX_ROOT . '/db/schema.sql');

function col(string $t, string $c): bool {
    return db_value('SELECT 1 FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$t, $c]) !== null;
}

echo "\n== migration ==\n";
$alters = [
    ['products', 'cost_aed',     'ALTER TABLE products ADD COLUMN cost_aed DECIMAL(12,2) NULL'],
    ['products', 'profit_toman', 'ALTER TABLE products ADD COLUMN profit_toman BIGINT NULL'],
    ['products', 'promo_toman',  'ALTER TABLE products ADD COLUMN promo_toman BIGINT NULL'],
    ['products', 'price_mode',   "ALTER TABLE products ADD COLUMN price_mode ENUM('manual','aed') NOT NULL DEFAULT 'manual'"],
    ['products', 'cost_toman',   'ALTER TABLE products ADD COLUMN cost_toman BIGINT NULL, ADD COLUMN price_applied_rate DECIMAL(14,2) NULL, ADD COLUMN price_applied_at DATETIME NULL'],
    ['product_sizes', 'cost_aed', 'ALTER TABLE product_sizes ADD COLUMN cost_aed DECIMAL(12,2) NULL, ADD COLUMN profit_toman BIGINT NULL, ADD COLUMN promo_toman BIGINT NULL, ADD COLUMN cost_toman BIGINT NULL, ADD COLUMN price_applied_rate DECIMAL(14,2) NULL, ADD COLUMN price_applied_at DATETIME NULL'],
    ['order_items', 'unit_cost', 'ALTER TABLE order_items ADD COLUMN unit_cost BIGINT NOT NULL DEFAULT 0, ADD COLUMN unit_profit BIGINT NOT NULL DEFAULT 0, ADD COLUMN line_profit BIGINT NOT NULL DEFAULT 0'],
    ['orders', 'commission_basis', "ALTER TABLE orders ADD COLUMN profit_total BIGINT NOT NULL DEFAULT 0, ADD COLUMN commission_basis ENUM('goods','profit') NOT NULL DEFAULT 'goods', ADD COLUMN rate_at_order DECIMAL(14,2) NULL"],
    ['orders', 'expires_at', "ALTER TABLE orders MODIFY COLUMN status ENUM('new','paid','shipped','done','cancelled','expired') NOT NULL DEFAULT 'new', ADD COLUMN expires_at DATETIME NULL"],
    ['products', 'commission_percent', 'ALTER TABLE products ADD COLUMN commission_percent DECIMAL(5,2) NULL'],
    ['product_sizes', 'commission_percent', 'ALTER TABLE product_sizes ADD COLUMN commission_percent DECIMAL(5,2) NULL'],
    ['order_items', 'commission_percent', 'ALTER TABLE order_items ADD COLUMN commission_percent DECIMAL(5,2) NOT NULL DEFAULT 0, ADD COLUMN commission_amount BIGINT NOT NULL DEFAULT 0'],
];
foreach ($alters as [$t, $c, $sql]) { if (!col($t, $c)) { db()->exec($sql); } }
if (db_value("SELECT 1 FROM information_schema.TABLES
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rate_history'") === null) {
    db()->exec('CREATE TABLE rate_history (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT, rate DECIMAL(14,2) NOT NULL,
        previous DECIMAL(14,2) NULL, source VARCHAR(40) NOT NULL DEFAULT "manual",
        admin_id INT UNSIGNED NULL, admin_name VARCHAR(120) NOT NULL DEFAULT "",
        applied_to INT NOT NULL DEFAULT 0, note VARCHAR(300) NOT NULL DEFAULT "",
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}
foreach ([['products','promo_toman'], ['product_sizes','cost_aed'], ['product_sizes','cost_toman'],
          ['order_items','unit_cost'], ['orders','commission_basis']] as [$t, $c]) {
    ok("$t.$c exists", col($t, $c));
}

require_once SUPPEX_ROOT . '/db/seed.php';
suppex_seed();

db()->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['orders', 'order_items', 'order_events', 'rate_history'] as $t) {
    db()->exec('TRUNCATE TABLE ' . $t);
}
db()->exec('SET FOREIGN_KEY_CHECKS = 1');

db_query('INSERT INTO admins (id, username, password_hash, name, role)
          VALUES (1, "tester", ?, "tester", "support")
          ON DUPLICATE KEY UPDATE username = username', [password_hash('x', PASSWORD_DEFAULT)]);
$admin = ['id' => 1, 'name' => 'tester'];

settings_save(['price_rounding_step' => '10000', 'price_max_age_days' => '3',
               'commission_basis' => 'goods', 'commission_percent' => '20',
               'free_shipping_threshold' => '1500000', 'shipping_flat_rate' => '65000',
               'aed_rate' => '', 'aed_rate_updated_at' => '']);

/* --- rounding ------------------------------------------------------------ */
echo "\n== rounding always goes up ==\n";
ok('exact multiple untouched', pricing_round_up(2450000, 10000) === 2450000);
ok('1 over rounds up',         pricing_round_up(2450001, 10000) === 2460000);
ok('9999 over rounds up',      pricing_round_up(2459999, 10000) === 2460000);
ok('step of 1 is a no-op',     pricing_round_up(1234567, 1) === 1234567);

/* --- formula ------------------------------------------------------------- */
echo "\n== price formula ==\n";
$r = pricing_compute(['cost_aed' => 42.50, 'profit_toman' => 1750000], 24500);
ok('decimals in the dirham cost survive', $r['cost'] === 1041250, (string) $r['cost']);
ok('price = cost + profit, rounded up',   $r['price'] === 2800000, (string) $r['price']);
ok('no discount means no strikethrough',  $r['compare_at'] === null);
ok('no dirham cost -> not priceable',     pricing_compute(['cost_aed' => 0], 24500) === null);

$promo = pricing_compute(['cost_aed' => 42.50, 'profit_toman' => 1750000, 'promo_toman' => 300000], 24500);
ok('promo lowers the price',        $promo['price'] === 2500000, (string) $promo['price']);
ok('compare_at is the full price',  $promo['compare_at'] === 2800000, (string) $promo['compare_at']);
ok('a real saving is shown',        $promo['compare_at'] > $promo['price']);

$greedy = pricing_compute(['cost_aed' => 42.50, 'profit_toman' => 1000000, 'promo_toman' => 5000000], 24500);
ok('promo capped at half the profit', $greedy['price'] >= $greedy['cost'] + 500000,
   $greedy['price'] . ' vs cost ' . $greedy['cost']);

$hi = pricing_compute(['cost_aed' => 42.50, 'profit_toman' => 1750000], 26000);
ok('a higher rate raises the price', $hi['price'] > $r['price']);
ok('profit survives the rate move',  ($hi['price'] - $hi['cost']) >= 1750000);

/* --- per-unit preview ---------------------------------------------------- */
echo "\n== the unit of pricing is the buyable unit ==\n";
db_query("UPDATE products SET price_mode='manual', cost_aed=NULL, profit_toman=NULL, promo_toman=NULL,
                              cost_toman=NULL, price_applied_rate=NULL");
db_query("UPDATE product_sizes SET cost_aed=NULL, profit_toman=NULL, promo_toman=NULL,
                                   cost_toman=NULL, price_applied_rate=NULL");

$wheyId = (int) db_value("SELECT id FROM products WHERE slug='whey-original'");
$isoId  = (int) db_value("SELECT id FROM products WHERE slug='iso-zero'");
$sizeCount = (int) db_value('SELECT COUNT(*) FROM product_sizes WHERE product_id = ?', [$wheyId]);
ok('the fixture product has sizes', $sizeCount === 3, $sizeCount . ' sizes');
ok('the control product has none',
   (int) db_value('SELECT COUNT(*) FROM product_sizes WHERE product_id = ?', [$isoId]) === 0);

db_query("UPDATE products SET price_mode='aed', cost_aed=40, profit_toman=1500000 WHERE id=?", [$wheyId]);
db_query("UPDATE product_sizes SET cost_aed=40, profit_toman=1500000 WHERE product_id=? AND ext_id='900'", [$wheyId]);
db_query("UPDATE product_sizes SET cost_aed=75, profit_toman=1800000 WHERE product_id=? AND ext_id='1800'", [$wheyId]);
db_query("UPDATE product_sizes SET cost_aed=95, profit_toman=2000000 WHERE product_id=? AND ext_id='2270'", [$wheyId]);
db_query("UPDATE products SET price_mode='aed', cost_aed=95, profit_toman=2000000 WHERE id=?", [$isoId]);

$prev = pricing_preview(25000);
$kinds = array_count_values(array_column($prev, 'kind'));
ok('sizes appear as their own rows', ($kinds['size'] ?? 0) === 3, json_encode($kinds));
ok('a product with sizes is not priced on its own row',
   count(array_filter($prev, static fn($u) => $u['kind'] === 'product' && $u['product_id'] === $wheyId)) === 0);
ok('a product without sizes still appears',
   count(array_filter($prev, static fn($u) => $u['kind'] === 'product' && $u['product_id'] === $isoId)) === 1);
ok('total rows equal the buyable units', count($prev) === 4, count($prev) . ' rows');

$big = null;
foreach ($prev as $u) { if (strpos($u['sub'], '2270') !== false) { $big = $u; } }
ok('the largest size prices from its own cost',
   $big !== null && $big['proposed'] === pricing_round_up(95 * 25000 + 2000000),
   (string) ($big['proposed'] ?? 0));

/* --- apply --------------------------------------------------------------- */
echo "\n== apply writes each unit ==\n";
$keys = array_map(static fn($u) => $u['kind'] . ':' . $u['id'], $prev);
$res = pricing_apply(25000, $admin, $keys);
ok('every unit applied', $res['applied'] === 4, json_encode($res));

$sizeRows = db_all('SELECT ext_id, price, cost_toman FROM product_sizes
                     WHERE product_id = ? ORDER BY sort_order', [$wheyId]);
ok('900g repriced',  (int) $sizeRows[0]['price'] === pricing_round_up(40 * 25000 + 1500000),
   (string) $sizeRows[0]['price']);
ok('2270g repriced', (int) $sizeRows[2]['price'] === pricing_round_up(95 * 25000 + 2000000),
   (string) $sizeRows[2]['price']);
ok('each size stores its OWN cost',
   (int) $sizeRows[0]['cost_toman'] === 1000000 && (int) $sizeRows[2]['cost_toman'] === 2375000,
   $sizeRows[0]['cost_toman'] . ' / ' . $sizeRows[2]['cost_toman']);
ok('the parent tracks the cheapest size',
   (int) db_value('SELECT price FROM products WHERE id = ?', [$wheyId]) === (int) $sizeRows[0]['price'],
   db_value('SELECT price FROM products WHERE id = ?', [$wheyId]) . ' vs ' . $sizeRows[0]['price']);

/* --- the defect this release fixes --------------------------------------- */
echo "\n== a variant order records the VARIANT's cost, not the parent's ==\n";
$customer = ['name' => 'Mina Ahmadi', 'phone' => '09121112233',
             'address' => 'Tehran, Saadat Abad, street 12, no 8', 'postal' => '1998765432'];

$o = order_create(['items' => [['slug' => 'whey-original', 'sizeId' => '2270', 'qty' => 1]],
                   'customer' => $customer, 'channel' => 'telegram']);
ok('order created', $o['ok'] === true, json_encode($o['errors'] ?? [], JSON_UNESCAPED_UNICODE));
$item = $o['order']['items'][0];

$bigPrice = (int) $sizeRows[2]['price'];
ok('charged the variant price', (int) $item['unit_price'] === $bigPrice,
   $item['unit_price'] . ' vs ' . $bigPrice);
ok('costed at the VARIANT cost', (int) $item['unit_cost'] === 2375000,
   $item['unit_cost'] . ' (1,000,000 here would be the old bug)');
ok('profit uses the right cost', (int) $item['unit_profit'] === $bigPrice - 2375000,
   (string) $item['unit_profit']);

$o2 = order_create(['items' => [['slug' => 'whey-original', 'sizeId' => '900', 'qty' => 1]],
                    'customer' => $customer, 'channel' => 'telegram']);
$item2 = $o2['order']['items'][0];
ok('the small size is costed correctly too', (int) $item2['unit_cost'] === 1000000,
   (string) $item2['unit_cost']);
ok('the two sizes yield different profits',
   (int) $item['unit_profit'] !== (int) $item2['unit_profit'],
   $item['unit_profit'] . ' vs ' . $item2['unit_profit']);

/* --- commission basis ---------------------------------------------------- */
echo "\n== commission basis ==\n";
ok('goods by default', $o['order']['commission_basis'] === 'goods');
settings_save(['commission_basis' => 'profit']);
$o3 = order_create(['items' => [['slug' => 'whey-original', 'sizeId' => '2270', 'qty' => 2]],
                    'customer' => $customer, 'channel' => 'telegram']);
$ord3 = $o3['order'];
ok('profit basis recorded', $ord3['commission_basis'] === 'profit');
ok('commission is 20% of recorded profit',
   (int) $ord3['commission_amount'] === (int) round((int) $ord3['profit_total'] * 0.20),
   $ord3['commission_amount'] . ' of ' . $ord3['profit_total']);

db_query("UPDATE products SET price_mode='manual', cost_aed=NULL, cost_toman=NULL
           WHERE slug='creatine-mono'");
$o4 = order_create(['items' => [['slug' => 'creatine-mono', 'qty' => 1]],
                    'customer' => $customer, 'channel' => 'telegram']);
ok('falls back to goods when cost is unknown', $o4['order']['commission_basis'] === 'goods',
   $o4['order']['commission_basis']);
ok('and the share is not zero', (int) $o4['order']['commission_amount'] > 0);

/* One costed line beside an uncosted one. This is the case the whole-order
   fallback got wrong: profit_total was above zero because of the costed line,
   so the basis stayed 'profit' and the uncosted line - whose line_profit is 0 -
   paid the partner nothing at all. */
$o5 = order_create(['items' => [
                        ['slug' => 'whey-original', 'sizeId' => '2270', 'qty' => 1],
                        ['slug' => 'creatine-mono', 'qty' => 1],
                    ],
                    'customer' => $customer, 'channel' => 'telegram']);
$ord5  = $o5['order'];
$lines = db_all('SELECT slug, line_total, line_profit, commission_basis, commission_amount
                   FROM order_items WHERE order_id = ? ORDER BY id', [$ord5['id']]);
$bySlug = [];
foreach ($lines as $l) { $bySlug[$l['slug']] = $l; }

ok('a mixed order says it is mixed', $ord5['commission_basis'] === 'mixed',
   $ord5['commission_basis']);
ok('the costed line is charged on profit',
   ($bySlug['whey-original']['commission_basis'] ?? '') === 'profit',
   json_encode($bySlug['whey-original'] ?? null));
ok('the uncosted line falls back to goods on its own',
   ($bySlug['creatine-mono']['commission_basis'] ?? '') === 'goods');
/* The whole point: this used to be zero. */
ok('so the uncosted line pays the partner something',
   (int) ($bySlug['creatine-mono']['commission_amount'] ?? 0) > 0,
   (string) ($bySlug['creatine-mono']['commission_amount'] ?? 'null'));
ok('and it is 20% of that line goods total',
   (int) $bySlug['creatine-mono']['commission_amount']
     === (int) round((int) $bySlug['creatine-mono']['line_total'] * 0.20));
ok('while the costed line is 20% of its profit',
   (int) $bySlug['whey-original']['commission_amount']
     === (int) round((int) $bySlug['whey-original']['line_profit'] * 0.20));
ok('the order total is the sum of the lines',
   (int) $ord5['commission_amount']
     === (int) $bySlug['whey-original']['commission_amount']
       + (int) $bySlug['creatine-mono']['commission_amount']);

settings_save(['commission_basis' => 'goods']);

/* --- staleness ----------------------------------------------------------- */
echo "\n== staleness is time-based, and can actually fire ==\n";
ok('fresh right after applying', pricing_staleness()['stale'] === false,
   json_encode(pricing_staleness()));

settings_save(['aed_rate_updated_at' => date('Y-m-d H:i:s', time() - 5 * 86400)]);
$st = pricing_staleness();
ok('stale after five days', $st['stale'] === true, json_encode($st));
ok('reports the age in days', $st['days'] !== null && $st['days'] >= 4.9, json_encode($st['days']));

settings_save(['aed_rate_updated_at' => date('Y-m-d H:i:s')]);
ok('fresh again once refreshed', pricing_staleness()['stale'] === false);

db_query('UPDATE product_sizes SET price_applied_rate = 111 WHERE product_id = ? AND ext_id = "900"', [$wheyId]);
$st = pricing_staleness();
ok('counts units left on an old rate', $st['behind'] === 1, json_encode($st));
ok('and that alone marks it stale', $st['stale'] === true);

/* --- typo guard ---------------------------------------------------------- */
echo "\n== fat-finger guard ==\n";
ok('a normal move passes quietly', pricing_rate_warning(25500) === null);
ok('an extra zero is flagged',     pricing_rate_warning(250000) !== null);
ok('a missing digit is flagged',   pricing_rate_warning(2500) !== null);
ok('zero is rejected',             pricing_rate_warning(0) !== null);

echo "\n== a cost typed in toman is caught ==\n";
db_query("UPDATE product_sizes SET cost_aed = 1200000 WHERE product_id = ? AND ext_id = '900'", [$wheyId]);
$bad = array_values(array_filter(pricing_preview(25000), static fn($u) => $u['implausible']));
ok('flagged as implausible', count($bad) === 1, count($bad) . ' flagged');
ok('but not refused outright', $bad && $bad[0]['no_margin'] === false);

/* --- past orders are immutable ------------------------------------------- */
echo "\n== a rate change does not rewrite past orders ==\n";
db_query("UPDATE product_sizes SET cost_aed = 40 WHERE product_id = ? AND ext_id = '900'", [$wheyId]);
$before = order_get((int) $o['order']['id']);
pricing_apply(31000, $admin, array_map(
    static fn($u) => $u['kind'] . ':' . $u['id'], pricing_preview(31000)));
$after = order_get((int) $o['order']['id']);
ok('recorded price unchanged',  (int) $after['items'][0]['unit_price'] === (int) $before['items'][0]['unit_price']);
ok('recorded cost unchanged',   (int) $after['items'][0]['unit_cost'] === 2375000);
ok('recorded profit unchanged', (int) $after['profit_total'] === (int) $before['profit_total']);
ok('but the live price moved',
   (int) db_value("SELECT price FROM product_sizes WHERE product_id = ? AND ext_id = '2270'", [$wheyId])
     > (int) $before['items'][0]['unit_price']);

/* --- settlement ---------------------------------------------------------- */
echo "\n== settlement report ==\n";
order_set_status((int) $o['order']['id'], 'paid', $admin, '');
$rep = orders_settlement(date('Y-m-d'), date('Y-m-d'));
ok('profit is reported', (int) $rep['profit_total'] === (int) $before['profit_total'],
   $rep['profit_total'] . ' vs ' . $before['profit_total']);
ok('only the paid order counts', $rep['order_count'] === 1, (string) $rep['order_count']);


/* --- the price-hold window ----------------------------------------------- */
echo "
== orders hold their price for a fixed window ==
";
settings_save(['order_hold_minutes' => '30']);
ok('hold minutes read from settings', order_hold_minutes() === 30, (string) order_hold_minutes());

settings_save(['order_hold_minutes' => '0']);
ok('zero is clamped up',   order_hold_minutes() === 5,   (string) order_hold_minutes());
settings_save(['order_hold_minutes' => '9999']);
ok('huge is clamped down', order_hold_minutes() === 180, (string) order_hold_minutes());
settings_save(['order_hold_minutes' => '30']);

$h = order_create(['items' => [['slug' => 'iso-zero', 'qty' => 1]],
                   'customer' => $customer, 'channel' => 'telegram']);
ok('order created', $h['ok'] === true, json_encode($h['errors'] ?? [], JSON_UNESCAPED_UNICODE));
$hid = (int) $h['order']['id'];
ok('a new order carries a deadline', $h['order']['expires_at'] !== null);
$window = strtotime($h['order']['expires_at']) - strtotime($h['order']['created_at']);
ok('the deadline matches the configured window', abs($window - 1800) <= 5, $window . 's');

ok('nothing expires while the window is open', orders_expire_due() === 0);
ok('and the order is still new', order_get($hid)['status'] === 'new');

db_query('UPDATE orders SET expires_at = ? WHERE id = ?',
         [date('Y-m-d H:i:s', time() - 60), $hid]);
ok('the sweep expires an overdue order', orders_expire_due() >= 1);
$expired = order_get($hid);
ok('status is expired', $expired['status'] === 'expired', $expired['status']);
ok('it is not marked paid', $expired['paid_at'] === null);
ok('the expiry is in the audit trail',
   count(array_filter($expired['events'], static fn($e) => $e['to_status'] === 'expired')) === 1);
ok('the order itself is not deleted', $expired['code'] === $h['order']['code']);

$paid = order_create(['items' => [['slug' => 'iso-zero', 'qty' => 1]],
                      'customer' => $customer, 'channel' => 'telegram']);
$pid = (int) $paid['order']['id'];
order_set_status($pid, 'paid', $admin, '');
db_query('UPDATE orders SET expires_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 3600), $pid]);
orders_expire_due();
ok('a paid order is never expired', order_get($pid)['status'] === 'paid');

order_set_status($hid, 'new', $admin, 'reopened');
$revived = order_get($hid);
ok('revived back to new', $revived['status'] === 'new');
ok('and given a fresh deadline', strtotime($revived['expires_at']) > time() + 1500,
   (string) $revived['expires_at']);
orders_expire_due();
ok('the next sweep leaves it alone', order_get($hid)['status'] === 'new');

db_query('UPDATE orders SET expires_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 60), $hid]);
orders_expire_due();
$repH = orders_settlement(date('Y-m-d'), date('Y-m-d'));
ok('expired orders never reach the settlement report',
   $repH['order_count'] === (int) db_value(
     'SELECT COUNT(*) FROM orders WHERE status IN ("paid","shipped","done")'),
   (string) $repH['order_count']);


/* --- per-product commission rate ----------------------------------------- */
echo "\n== a per-product rate overrides the shop rate ==\n";
settings_save(['commission_basis' => 'profit', 'commission_percent' => '20']);
db_query('UPDATE products SET commission_percent = NULL');
db_query('UPDATE product_sizes SET commission_percent = NULL');

/* Two units, same product, very different margins - the case the client
   described: one line with little competition, one with a lot. */
db_query("UPDATE product_sizes SET cost_aed=40,  profit_toman=2000000
           WHERE product_id=? AND ext_id='900'", [$wheyId]);
db_query("UPDATE product_sizes SET cost_aed=110, profit_toman=200000
           WHERE product_id=? AND ext_id='1800'", [$wheyId]);
pricing_apply(25000, $admin, array_map(
    static fn($u) => $u['kind'] . ':' . $u['id'], pricing_preview(25000)));

$fat  = order_create(['items' => [['slug' => 'whey-original', 'sizeId' => '900',  'qty' => 1]],
                      'customer' => $customer, 'channel' => 'telegram'])['order'];
$thin = order_create(['items' => [['slug' => 'whey-original', 'sizeId' => '1800', 'qty' => 1]],
                      'customer' => $customer, 'channel' => 'telegram'])['order'];

ok('the profit basis already scales with margin',
   (int) $fat['commission_amount'] > (int) $thin['commission_amount'] * 3,
   $fat['commission_amount'] . ' vs ' . $thin['commission_amount']);
ok('both used the shop rate',
   (float) $fat['items'][0]['commission_percent'] === 20.0
   && (float) $thin['items'][0]['commission_percent'] === 20.0);

/* Override the thin line to zero - a deliberate loss-leader. */
db_query("UPDATE product_sizes SET commission_percent = 0 WHERE product_id=? AND ext_id='1800'", [$wheyId]);
$free = order_create(['items' => [['slug' => 'whey-original', 'sizeId' => '1800', 'qty' => 1]],
                      'customer' => $customer, 'channel' => 'telegram'])['order'];
ok('a zero override really means zero', (int) $free['commission_amount'] === 0,
   (string) $free['commission_amount']);
ok('zero is distinguishable from unset',
   (float) $free['items'][0]['commission_percent'] === 0.0);

/* Half rate on one line, shop rate on the other. */
db_query("UPDATE product_sizes SET commission_percent = 10 WHERE product_id=? AND ext_id='1800'", [$wheyId]);
$mixed = order_create(['items' => [
      ['slug' => 'whey-original', 'sizeId' => '900',  'qty' => 1],
      ['slug' => 'whey-original', 'sizeId' => '1800', 'qty' => 1],
   ], 'customer' => $customer, 'channel' => 'telegram'])['order'];

$byRate = [];
foreach ($mixed['items'] as $it) { $byRate[(string) (float) $it['commission_percent']] = $it; }
ok('each line kept its own rate', isset($byRate['20'], $byRate['10']),
   implode(',', array_keys($byRate)));
ok('the order total is the sum of the lines',
   (int) $mixed['commission_amount'] ===
     (int) $byRate['20']['commission_amount'] + (int) $byRate['10']['commission_amount'],
   (string) $mixed['commission_amount']);
ok('the order-level percent is the blend, not an input',
   (float) $mixed['commission_percent'] > 10.0 && (float) $mixed['commission_percent'] < 20.0,
   (string) $mixed['commission_percent']);

/* Product-level override, inherited by a size that has none of its own. */
db_query('UPDATE product_sizes SET commission_percent = NULL WHERE product_id = ?', [$wheyId]);
db_query('UPDATE products SET commission_percent = 5 WHERE id = ?', [$wheyId]);
$inherited = order_create(['items' => [['slug' => 'whey-original', 'sizeId' => '900', 'qty' => 1]],
                           'customer' => $customer, 'channel' => 'telegram'])['order'];
ok('a size with no rate inherits the product rate',
   (float) $inherited['items'][0]['commission_percent'] === 5.0,
   (string) $inherited['items'][0]['commission_percent']);

db_query("UPDATE product_sizes SET commission_percent = 30 WHERE product_id=? AND ext_id='900'", [$wheyId]);
$specific = order_create(['items' => [['slug' => 'whey-original', 'sizeId' => '900', 'qty' => 1]],
                          'customer' => $customer, 'channel' => 'telegram'])['order'];
ok('the size rate beats the product rate',
   (float) $specific['items'][0]['commission_percent'] === 30.0,
   (string) $specific['items'][0]['commission_percent']);

/* Changing a rate must not rewrite an order already placed. */
$was = (int) $specific['commission_amount'];
db_query("UPDATE product_sizes SET commission_percent = 1 WHERE product_id=? AND ext_id='900'", [$wheyId]);
settings_save(['commission_percent' => '90']);
$reread = order_get((int) $specific['id']);
ok('a rate change leaves past orders alone',
   (int) $reread['commission_amount'] === $was, (string) $reread['commission_amount']);
ok('the line still shows the rate it was charged at',
   (float) $reread['items'][0]['commission_percent'] === 30.0);

settings_save(['commission_percent' => '20']);
db_query("UPDATE product_sizes SET commission_percent = 150 WHERE product_id=? AND ext_id='900'", [$wheyId]);
$clamped = order_create(['items' => [['slug' => 'whey-original', 'sizeId' => '900', 'qty' => 1]],
                         'customer' => $customer, 'channel' => 'telegram'])['order'];
ok('a rate above 100 is clamped',
   (float) $clamped['items'][0]['commission_percent'] === 100.0,
   (string) $clamped['items'][0]['commission_percent']);

db_query('UPDATE product_sizes SET commission_percent = NULL WHERE product_id = ?', [$wheyId]);
db_query('UPDATE products SET commission_percent = NULL WHERE id = ?', [$wheyId]);
settings_save(['commission_basis' => 'goods']);

echo "\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
