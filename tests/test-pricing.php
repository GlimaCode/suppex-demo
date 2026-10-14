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
/* profit_percent is cleared alongside, because setting a toman profit by hand
   states a FIXED intent. Left in place, the locked ratio would win at the next
   repricing and these two carefully-chosen margins would quietly become one. */
db_query("UPDATE product_sizes SET cost_aed=40,  profit_toman=2000000, profit_percent=NULL
           WHERE product_id=? AND ext_id='900'", [$wheyId]);
db_query("UPDATE product_sizes SET cost_aed=110, profit_toman=200000, profit_percent=NULL
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


/* ---------------------------------------------------------------------------
   Profit as a RATIO rather than a fixed toman sum.

   profit_toman is a fixed amount, so the percentage margin shrinks every time
   the dirham climbs — 52.9% on a 40-dirham tub at a rate of 20,000 becomes
   31.0% at 50,000 — while every shelf price rises, so nothing looks wrong.
   Storing the ratio re-derives the toman profit from the cost each time.
   --------------------------------------------------------------------------- */
echo "
== profit kept as a ratio survives a moving dirham ==
";

$pct = pricing_percent_from(800000, 900000);
ok("  the ratio is taken against COST", abs($pct - 112.5) < 0.001, (string) $pct);
ok("  no ratio can be inferred without a cost", pricing_percent_from(0, 900000) === null);
ok("  nor without a profit", pricing_percent_from(800000, 0) === null);

$fixed = ["cost_aed" => 40.0, "profit_toman" => 900000, "profit_percent" => null, "promo_toman" => 0];
$ratio = ["cost_aed" => 40.0, "profit_toman" => 900000, "profit_percent" => $pct, "promo_toman" => 0];

/* At the rate the ratio was derived from, the two must agree exactly — so
   adopting the model does not reprice a single product on the day it lands. */
$a = pricing_compute($fixed, 20000.0);
$b = pricing_compute($ratio, 20000.0);
ok("  at the entry rate both models give the same price",
   $a["price"] === $b["price"], $a["price"] . " vs " . $b["price"]);

/* And then they diverge, which is the whole point. */
$fixedAt50 = pricing_compute($fixed, 50000.0);
$ratioAt50 = pricing_compute($ratio, 50000.0);
ok("  the fixed model's margin erodes",
   pricing_margin_pct($fixedAt50["cost"], $fixedAt50["price"]) < 32.0,
   (string) pricing_margin_pct($fixedAt50["cost"], $fixedAt50["price"]));
ok("  the ratio model holds its margin",
   abs(pricing_margin_pct($ratioAt50["cost"], $ratioAt50["price"]) - 52.9) < 0.6,
   (string) pricing_margin_pct($ratioAt50["cost"], $ratioAt50["price"]));

/* Across the whole plausible range, not just two points. */
$held = true;
foreach ([18000, 25000, 33000, 41000, 60000, 90000] as $r) {
    $c = pricing_compute($ratio, (float) $r);
    if (abs(pricing_margin_pct($c["cost"], $c["price"]) - 52.9) > 0.6) { $held = false; }
}
ok("  and holds it at every rate from 18,000 to 90,000", $held);

/* A unit with no ratio must keep behaving exactly as it did. */
ok("  a null ratio still uses the typed toman profit",
   pricing_profit(["profit_toman" => 750000, "profit_percent" => null], 1000000) === 750000);
ok("  a zero ratio is treated as no ratio, not as zero profit",
   pricing_profit(["profit_toman" => 750000, "profit_percent" => 0.0], 1000000) === 750000);

/* The two ways of saying the same thing. */
ok("  margin on price is the mirror of markup on cost",
   abs(pricing_margin_pct(800000, 1700000) - 52.9) < 0.05,
   (string) pricing_margin_pct(800000, 1700000));

echo "
== the ratio is written, and the derived profit written with it ==
";

db_query("DELETE FROM products WHERE slug = ?", ["zz-ratio"]);
$rid = db_insert(
    "INSERT INTO products (slug, name_fa, price, price_mode, cost_aed, profit_toman,
                           profit_percent, is_active, in_stock)
     VALUES ('zz-ratio', 'زد-آزمون درصدی', 0, 'aed', 40, 900000, ?, 1, 1)",
    [$pct]);

$before = (int) db_value("SELECT profit_toman FROM products WHERE id = ?", [$rid]);
pricing_apply(50000.0, ["id" => 1, "name" => "test"], ["product:" . $rid]);
$row = db_one("SELECT price, cost_toman, profit_toman, profit_percent FROM products WHERE id = ?", [$rid]);

ok("  the price used the ratio", (int) $row["price"] === $ratioAt50["price"],
   $row["price"] . " vs " . $ratioAt50["price"]);
/* If profit_toman were left at the entered figure the margin sheet and the
   order profit snapshot would disagree with price - cost. */
ok("  profit_toman was re-derived, not left stale",
   (int) $row["profit_toman"] > $before, $before . " -> " . $row["profit_toman"]);
ok("  and it equals price minus cost",
   (int) $row["profit_toman"] === (int) $row["price"] - (int) $row["cost_toman"],
   $row["profit_toman"] . " vs " . ((int) $row["price"] - (int) $row["cost_toman"]));
ok("  the ratio itself was not disturbed",
   abs((float) $row["profit_percent"] - $pct) < 0.01, (string) $row["profit_percent"]);

echo "
== a unit priced before any ratio existed gets one locked in ==
";

db_query("DELETE FROM products WHERE slug = ?", ["zz-noratio"]);
$nid = db_insert(
    "INSERT INTO products (slug, name_fa, price, price_mode, cost_aed, profit_toman,
                           profit_percent, is_active, in_stock)
     VALUES ('zz-noratio', 'زد-آزمون بدون درصد', 0, 'aed', 40, 900000, NULL, 1, 1)");
ok("  it starts with no ratio",
   db_value("SELECT profit_percent FROM products WHERE id = ?", [$nid]) === null);

pricing_apply(20000.0, ["id" => 1, "name" => "test"], ["product:" . $nid]);
$locked = db_value("SELECT profit_percent FROM products WHERE id = ?", [$nid]);
ok("  the first apply locks the ratio in", $locked !== null, var_export($locked, true));
ok("  and locks in the ratio the operator actually typed",
   abs((float) $locked - 112.5) < 0.5, (string) $locked);

db_query("DELETE FROM products WHERE slug IN ('zz-ratio', 'zz-noratio')");
ok("  cleaned up",
   (int) db_value("SELECT COUNT(*) FROM products WHERE slug LIKE 'zz-%ratio'") === 0);



echo "\n== a save must not move the margin ==\n";
/* The ratio is what survives a rising dirham; a save that touches nothing must
   not touch it either. Driven through the real admin page rather than a copy of
   its SQL, because the defect was IN the page: product_sizes.profit_percent was
   absent from both the carry-over SELECT and the rebuild INSERT, so every save
   wiped it. */
db_query("DELETE FROM products WHERE slug LIKE 'zz-ratio%'");
$ratioSaver = __DIR__ . '/editsave.php';
ok('the edit-save harness exists', is_file($ratioSaver), $ratioSaver);

settings_save(['aed_rate' => '66000']);   // higher than the rate it was locked at

$rpid = db_insert(
    "INSERT INTO products (slug, name_fa, price, price_mode, is_active, in_stock,
                           cost_aed, profit_toman, profit_percent)
     VALUES ('zz-ratio', 'آزمون درصد', 0, 'aed', 1, 1, 40, 1000000, 50.000)");
db_query(
    "INSERT INTO product_sizes (product_id, ext_id, label, price, cost_aed,
                                profit_toman, profit_percent, sort_order)
     VALUES (?, '900', '۹۰۰ گرم', 0, 20, 1000000, 50.000, 0),
            (?, '2270', '۲۲۷۰ گرم', 0, 40, 2000000, 50.000, 1)",
    [$rpid, $rpid]);

shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ratioSaver)
           . ' ' . $rpid . ' 2>&1');

$rProd = db_value('SELECT profit_percent FROM products WHERE id = ?', [$rpid]);
ok('the product keeps its locked ratio after a save that changed nothing',
   $rProd !== null && abs((float) $rProd - 50.0) < 0.01,
   $rProd === null ? 'NULL' : (string) $rProd);

$rSizes = db_all('SELECT ext_id, profit_percent FROM product_sizes
                   WHERE product_id = ? ORDER BY sort_order', [$rpid]);
ok('  both sizes still have one', count($rSizes) === 2);
foreach ($rSizes as $rs) {
    ok('  size ' . $rs['ext_id'] . ' kept 50%',
       $rs['profit_percent'] !== null && abs((float) $rs['profit_percent'] - 50.0) < 0.01,
       $rs['profit_percent'] === null ? 'NULL — wiped' : (string) $rs['profit_percent']);
}

/* The margin must not drift even after several rounds — this was a ratchet,
   losing a little on every edit while the dirham climbed. */
foreach (['70000', '74000'] as $r) {
    settings_save(['aed_rate' => $r]);
    shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ratioSaver)
               . ' ' . $rpid . ' 2>&1');
}
$rProd = db_value('SELECT profit_percent FROM products WHERE id = ?', [$rpid]);
ok('and three saves at three rising rates still leave it at 50%',
   $rProd !== null && abs((float) $rProd - 50.0) < 0.01,
   $rProd === null ? 'NULL' : (string) $rProd);

db_query("DELETE FROM products WHERE slug LIKE 'zz-ratio%'");
ok('ratio fixtures cleaned up',
   (int) db_value("SELECT COUNT(*) FROM products WHERE slug LIKE 'zz-ratio%'") === 0);


echo "\n== pressing save must not lose a flavour picture ==\n";
/* The flavour rows are rebuilt wholesale on every save - DELETE then INSERT -
   and no form field carries the picture back, because it is a file input and
   an untouched one posts nothing. So the page has to read the existing
   pictures out of the database first and put them back. Driven through the
   real admin page, because that is where the defect would be. */
db_query("DELETE FROM products WHERE slug LIKE 'zz-fsave%'");
$fsSaver = __DIR__ . '/editsave.php';

$fsPid = db_insert(
    "INSERT INTO products (slug, name_fa, price, price_mode, is_active, in_stock)
     VALUES ('zz-fsave', 'زد-ذخیره طعم', 2400000, 'manual', 1, 1)");
db_query(
    "INSERT INTO product_flavors (product_id, ext_id, name, image, price_delta_toman, sort_order)
     VALUES (?, 'choco', 'شکلاتی', 'uploads/products/2026/09/choco.webp', 0, 0),
            (?, 'plain', 'ساده',   '',                                    0, 1),
            (?, 'cook',  'کوکی',   'uploads/products/2026/09/cook.webp', 250000, 2)",
    [$fsPid, $fsPid, $fsPid]);

shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fsSaver)
           . ' ' . $fsPid . ' 2>&1');

$fsAfter = [];
foreach (db_all('SELECT name, image, price_delta_toman FROM product_flavors
                   WHERE product_id = ? ORDER BY sort_order', [$fsPid]) as $f) {
    $fsAfter[(string) $f['name']] = $f;
}
ok('all three flavours survive the save', count($fsAfter) === 3,
   (string) count($fsAfter));
ok('  the first keeps its picture',
   ($fsAfter['شکلاتی']['image'] ?? null) === 'uploads/products/2026/09/choco.webp',
   $fsAfter['شکلاتی']['image'] ?? 'MISSING');
ok('  the third keeps its picture and its premium',
   ($fsAfter['کوکی']['image'] ?? null) === 'uploads/products/2026/09/cook.webp'
   && (int) ($fsAfter['کوکی']['price_delta_toman'] ?? 0) === 250000);
ok('  and the one with none does not acquire one',
   ($fsAfter['ساده']['image'] ?? null) === '', $fsAfter['ساده']['image'] ?? 'MISSING');

/* Several saves in a row, because a ratchet loses a little each time rather
   than everything at once. */
shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fsSaver) . ' ' . $fsPid . ' 2>&1');
shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fsSaver) . ' ' . $fsPid . ' 2>&1');
ok('three saves later they are still there',
   (int) db_value("SELECT COUNT(*) FROM product_flavors
                    WHERE product_id = ? AND image <> ''", [$fsPid]) === 2,
   (string) db_value("SELECT COUNT(*) FROM product_flavors
                       WHERE product_id = ? AND image <> ''", [$fsPid]));

db_query("DELETE FROM products WHERE slug LIKE 'zz-fsave%'");
ok('flavour-save fixtures cleaned up',
   (int) db_value("SELECT COUNT(*) FROM products WHERE slug LIKE 'zz-fsave%'") === 0);

echo "\n== a converted product still moves with the dirham ==\n";
/* The pricing page lists sizes, and a converted product does not sell sizes
   any more - so converting one took it out of the preview entirely and froze
   its prices at whatever rate was current that day. For a shop whose whole
   model is «buy in dirhams, sell in toman, keep the margin», that is the one
   thing that must not quietly stop. */
require_once SUPPEX_ROOT . '/lib/variants.php';

$pvWipe = static function (): void {
    foreach (db_all("SELECT id FROM products WHERE slug LIKE 'zz-pv%'") as $r) {
        $id = (int) $r['id'];
        db_query('DELETE vv FROM product_variant_values vv JOIN product_variants v
                    ON v.id = vv.variant_id WHERE v.product_id = ?', [$id]);
        db_query('DELETE FROM product_variants WHERE product_id = ?', [$id]);
        db_query('DELETE av FROM product_attribute_values av JOIN product_attributes a
                    ON a.id = av.attribute_id WHERE a.product_id = ?', [$id]);
        db_query('DELETE FROM product_attributes WHERE product_id = ?', [$id]);
    }
    db_query("DELETE FROM products WHERE slug LIKE 'zz-pv%'");
};
$pvWipe();

settings_save(['aed_rate' => '60000', 'price_rounding_step' => '10000']);

$pvPid = db_insert(
    "INSERT INTO products (slug, name_fa, price, price_mode, is_active, in_stock, cost_aed)
     VALUES ('zz-pv', 'زد-درهمی', 0, 'aed', 1, 1, NULL)");
db_query("INSERT INTO product_sizes (product_id, ext_id, label, price, cost_aed, profit_toman, sort_order)
          VALUES (?, '900', '۹۰۰ گرم', 0, 20, 400000, 0),
                 (?, '2270','۲۲۷۰ گرم',0, 45, 900000, 1)", [$pvPid, $pvPid]);
db_query("INSERT INTO product_flavors (product_id, ext_id, name, cost_delta_aed, sort_order)
          VALUES (?, 'plain', 'ساده', NULL, 0),
                 (?, 'cook',  'کوکی',  3,   1)", [$pvPid, $pvPid]);

pricing_apply(60000.0, ['id' => 1, 'name' => 'tester'], null, 'manual');
variants_adopt($pvPid);

$pvMine = static fn(array $preview): array => array_values(array_filter(
    $preview, static fn($r) => (int) $r['product_id'] === $pvPid));

$pvRows = $pvMine(pricing_preview(72000.0));
ok('the preview offers one row per combination, not per size',
   count($pvRows) === 4, (string) count($pvRows));
ok('  and they are combinations',
   count(array_filter($pvRows, static fn($r) => $r['kind'] === 'variant')) === 4,
   implode(',', array_column($pvRows, 'kind')));

pricing_apply(72000.0, ['id' => 1, 'name' => 'tester'], null, 'manual');

$pvAfter = [];
foreach (db_all('SELECT ext_id, price, profit_percent FROM product_variants
                   WHERE product_id = ?', [$pvPid]) as $v) {
    $pvAfter[(string) $v['ext_id']] = $v;
}
ok('the plain combination reprices with the rate',
   (int) $pvAfter['900|plain']['price'] === 1920000,
   (string) ($pvAfter['900|plain']['price'] ?? '—'));
/* The dearer flavour's dirham premium rides along: 20 + 3 aed, not 20. */
ok('  and the dearer flavour carries its dirham premium',
   (int) $pvAfter['900|cook']['price'] === 2210000,
   (string) ($pvAfter['900|cook']['price'] ?? '—'));
ok('  every combination keeps its locked ratio',
   count(array_filter($pvAfter, static fn($v) =>
       $v['profit_percent'] !== null && abs((float) $v['profit_percent'] - 33.333) < 0.01)) === 4);

/* The card and the catalogue show the parent row, so it has to track the
   cheapest thing a shopper can actually buy - a combination now, not a size. */
ok('the card price tracks the cheapest combination',
   (int) db_value('SELECT price FROM products WHERE id = ?', [$pvPid]) === 1920000,
   (string) db_value('SELECT price FROM products WHERE id = ?', [$pvPid]));

/* What «still on the old rate» counts is what is sold. The sizes of a
   converted product are never repriced, so counting them reported every such
   product as behind after the first change of rate - for good. */
$pvBehind0 = pricing_staleness()['behind'];
db_query('UPDATE product_sizes SET price_applied_rate = 111 WHERE product_id = ?', [$pvPid]);
ok('a converted product\'s sizes are not counted as behind - they are not what is sold',
   pricing_staleness()['behind'] === $pvBehind0, json_encode(pricing_staleness()));
db_query("UPDATE product_variants SET price_applied_rate = 111 WHERE product_id = ? AND ext_id = '900|cook'", [$pvPid]);
ok('  its combinations are', pricing_staleness()['behind'] === $pvBehind0 + 1, json_encode(pricing_staleness()));
db_query("UPDATE product_variants SET price_applied_rate = 72000 WHERE product_id = ? AND ext_id = '900|cook'", [$pvPid]);
ok('the dashboard gets the drift it prints', array_key_exists('drift_pct', pricing_staleness()));

/* The pricing page's own apply: it filtered the ticked rows down to products
   and sizes, so on a catalogue priced by combination it applied nothing. */
$pricingPage = (string) file_get_contents(SUPPEX_ROOT . '/admin/pricing.php');
ok('the panel\'s manual apply keeps combination rows',
   strpos($pricingPage, 'preg_match(\'/^(product|size|variant):[0-9]+$/\', $k)') !== false);

db_query("UPDATE product_variants SET price = 1, is_active = 0
           WHERE product_id = ? AND ext_id = '900|plain'", [$pvPid]);
pricing_sync_parent($pvPid);
ok('  and a switched-off one never sets it',
   (int) db_value('SELECT price FROM products WHERE id = ?', [$pvPid]) === 2210000,
   (string) db_value('SELECT price FROM products WHERE id = ?', [$pvPid]));

/* Everything that was not converted must behave exactly as it did. */
$pvPlain = db_insert(
    "INSERT INTO products (slug, name_fa, price, price_mode, is_active, in_stock, cost_aed)
     VALUES ('zz-pv-plain', 'زد-بدون ترکیب', 0, 'aed', 1, 1, NULL)");
db_query("INSERT INTO product_sizes (product_id, ext_id, label, price, cost_aed, profit_toman, sort_order)
          VALUES (?, '900', '۹۰۰ گرم', 0, 20, 400000, 0)", [$pvPlain]);
$pvPlainRows = array_values(array_filter(pricing_preview(72000.0),
    static fn($r) => (int) $r['product_id'] === $pvPlain));
ok('an unconverted product is still priced by its sizes',
   count($pvPlainRows) === 1 && $pvPlainRows[0]['kind'] === 'size',
   $pvPlainRows ? $pvPlainRows[0]['kind'] : 'none');

$pvWipe();
ok('pricing-variant fixtures cleaned up',
   (int) db_value("SELECT COUNT(*) FROM products WHERE slug LIKE 'zz-pv%'") === 0);
echo "\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
