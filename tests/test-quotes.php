<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/* ===========================================================================
   Standing by the price the shopper was shown.
   ---------------------------------------------------------------------------
   The window being closed here is specific and it is the only place in this
   shop where a wrong price costs cash rather than embarrassment:

     the order message opens in the customer's own chat app BEFORE the server
     hears about the order — window.open only survives a popup blocker while
     the click gesture is on the stack — so the amount they paste into Telegram
     comes from prices their browser holds, while the amount the shop records
     comes from the database moments later. Card-to-card has no authorisation
     step in which to notice the two disagree.

   So the checks that matter are about what gets CHARGED after a price moves
   underneath a shopper, not about tokens round-tripping.
   =========================================================================== */

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/products.php';
require_once SUPPEX_ROOT . '/lib/orders.php';
require_once SUPPEX_ROOT . '/lib/quotes.php';

$pass = 0; $fail = 0;
function ok(string $l, bool $c, ?string $x = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $l\n"; }
    else    { $fail++; echo "  FAIL $l" . ($x !== '' ? "  --  $x" : '') . "\n"; }
}

$customer = [
    'name' => 'آزمون قیمت', 'phone' => '09121234567',
    'address' => 'تهران، خیابان آزمایشی، پلاک ۱۲', 'postal' => '1234567890',
];

echo "\n== the shop charges the lower of what it quoted and what it charges now ==\n";
$q = quote_issue([quote_key('zz-quote', '') => 1500000]);
ok('a quote gets a token', preg_match('/^[0-9a-f]{32}$/', $q) === 1, $q);

$prices = quote_prices($q);
ok('  which resolves back to the price', ($prices[quote_key('zz-quote', '')] ?? 0) === 1500000);

ok('a risen price is charged at the quote',
   quote_price_for($prices, 'zz-quote', '', 1700000) === 1500000);
/* The other direction matters just as much: a price CUT has to reach a shopper
   holding an old quote, or the quote becomes a way to overcharge them. */
ok('a fallen price is charged at the new lower one',
   quote_price_for($prices, 'zz-quote', '', 1400000) === 1400000);
ok('an unquoted line is charged at today\'s price',
   quote_price_for($prices, 'something-else', '', 999000) === 999000);

echo "\n== a size is quoted separately from its parent ==\n";
/* The 900g and 2270g tubs are different prices, and must not be able to
   resolve to each other. */
$q2 = quote_issue([
    quote_key('zz-quote', '900')  => 900000,
    quote_key('zz-quote', '2270') => 1900000,
]);
$p2 = quote_prices($q2);
ok('the small size keeps its own price',
   quote_price_for($p2, 'zz-quote', '900', 5000000) === 900000);
ok('the large size keeps its own',
   quote_price_for($p2, 'zz-quote', '2270', 5000000) === 1900000);
ok('and the parent, unquoted here, is not covered by either',
   quote_price_for($p2, 'zz-quote', '', 5000000) === 5000000);

echo "\n== a token that is not a token ==\n";
/* An expired or unknown token must not be an error. The cart lives in
   localStorage indefinitely, so a shopper returning next week arrives with a
   token that is simply too old — they pay today's price, which is correct. */
ok('an unknown token yields nothing', quote_prices(str_repeat('a', 32)) === []);
ok('a malformed token yields nothing', quote_prices('not-a-token') === []);
ok('an empty token yields nothing', quote_prices('') === []);
/* SQL injection through the token is impossible anyway — it is bound — but the
   shape check should reject it before it ever reaches the query. */
ok('a token with SQL in it yields nothing', quote_prices("' OR 1=1 --") === []);

db_query("DELETE FROM price_quotes WHERE token = ?", [$q]);
ok('an expired quote yields nothing', quote_prices($q) === []);

echo "\n== end to end: a real order, priced after the shelf moved ==\n";
db_query("DELETE FROM products WHERE slug LIKE 'zz-quote%'");
$pid = db_insert(
    "INSERT INTO products (slug, name_fa, price, price_mode, is_active, in_stock)
     VALUES ('zz-quote-live', 'زد-آزمون قیمت', 1500000, 'manual', 1, 1)");
ok('a product to sell', $pid > 0);

/* What the shopper saw, and what their chat message therefore says. */
$token = quote_issue([quote_key('zz-quote-live', '') => 1500000]);

/* Then the nightly repricing runs while they are typing their address. */
db_query('UPDATE products SET price = 1800000 WHERE id = ?', [$pid]);

$res = order_create([
    'items' => [['slug' => 'zz-quote-live', 'qty' => 2]],
    'customer' => $customer,
    'quote' => $token,
]);
ok('the order goes through', !empty($res['ok']),
   json_encode($res['errors'] ?? [], JSON_UNESCAPED_UNICODE));

$total = (int) ($res['order']['total'] ?? 0);
/* Two at the quoted 1,500,000, not at the new 1,800,000. Without this the
   customer transfers 3,000,000 against an order that says 3,600,000. */
ok('  and is charged at the price the shopper was shown',
   $total === 3000000, number_format($total));

$orderId = (int) ($res['order']['id'] ?? 0);
db_query('DELETE FROM order_items WHERE order_id = ?', [$orderId]);
db_query('DELETE FROM orders WHERE id = ?', [$orderId]);

echo "\n== and the same order with no quote pays today's price ==\n";
$res2 = order_create([
    'items' => [['slug' => 'zz-quote-live', 'qty' => 2]],
    'customer' => $customer,
]);
ok('an order without a quote still works', !empty($res2['ok']));
ok('  and pays the current price', (int) ($res2['order']['total'] ?? 0) === 3600000,
   number_format((int) ($res2['order']['total'] ?? 0)));

$orderId2 = (int) ($res2['order']['id'] ?? 0);
db_query('DELETE FROM order_items WHERE order_id = ?', [$orderId2]);
db_query('DELETE FROM orders WHERE id = ?', [$orderId2]);

echo "\n== a quote cannot be used to pay more than today ==\n";
/* Holding a stale HIGH quote through a price cut must gain the shop nothing —
   otherwise the mechanism meant to protect the customer becomes a way to
   overcharge one who never cleared their cart. */
$high = quote_issue([quote_key('zz-quote-live', '') => 5000000]);
$res3 = order_create([
    'items' => [['slug' => 'zz-quote-live', 'qty' => 1]],
    'customer' => $customer,
    'quote' => $high,
]);
ok('a stale high quote never charges more than today',
   (int) ($res3['order']['total'] ?? 0) === 1800000,
   number_format((int) ($res3['order']['total'] ?? 0)));

$orderId3 = (int) ($res3['order']['id'] ?? 0);
db_query('DELETE FROM order_items WHERE order_id = ?', [$orderId3]);
db_query('DELETE FROM orders WHERE id = ?', [$orderId3]);


echo "\n== a flavour that costs more than its neighbours ==\n";
/* Most flavours of a tub cost the same and a few do not. The premium is stored
   as a DELTA rather than a full price because these products have sizes AND
   flavours and the shopper picks both: a price per combination would be four
   sizes times ten flavours to type and to keep current, while a delta is one
   number on the two that differ. */
require_once SUPPEX_ROOT . '/lib/pricing.php';

db_query("DELETE FROM products WHERE slug LIKE 'zz-flav%'");
settings_save(['aed_rate' => '60000']);

$fpid = db_insert(
    "INSERT INTO products (slug, name_fa, price, price_mode, is_active, in_stock,
                           cost_aed, profit_percent)
     VALUES ('zz-flav', 'وی آزمون طعم', 0, 'aed', 1, 1, 40, 80)");
db_query(
    "INSERT INTO product_sizes (product_id, ext_id, label, price, cost_aed, profit_percent, sort_order)
     VALUES (?, '2270', '۲۲۷۰ گرم', 0, 40, 80, 0)", [$fpid]);
db_query(
    "INSERT INTO product_flavors (product_id, ext_id, name, cost_delta_aed, sort_order)
     VALUES (?, 'vanilla', 'وانیل', NULL, 0), (?, 'cookies', 'کوکی', 3, 1)",
    [$fpid, $fpid]);

pricing_apply(60000.0, ['id' => 1, 'name' => 'test'], null);

$plain   = (int) db_value("SELECT price_delta_toman FROM product_flavors
                            WHERE product_id = ? AND ext_id = 'vanilla'", [$fpid]);
$premium = (int) db_value("SELECT price_delta_toman FROM product_flavors
                            WHERE product_id = ? AND ext_id = 'cookies'", [$fpid]);
$base    = (int) db_value('SELECT price FROM product_sizes WHERE product_id = ?', [$fpid]);

ok('a flavour with no premium adds nothing', $plain === 0, (string) $plain);
/* 3 dirhams at 60,000, carrying the product's own 80% margin, rounded up to the
   shop's 10,000 step: 3 x 60,000 x 1.8 = 324,000 -> 330,000. The margin matters
   — charging only the bare cost difference would leave the premium flavours on
   a thinner margin than the plain ones, which is the opposite of the intent. */
ok('a premium flavour carries the product margin, not just the cost',
   $premium === 330000, number_format($premium));
ok('  the base price is unaffected', $base === 4320000, number_format($base));

echo "\n== what the customer is actually charged ==\n";
$flavCust = ['name' => 'آزمون طعم', 'phone' => '09121234567',
             'address' => 'تهران، خیابان آزمایشی، پلاک ۱۲', 'postal' => '1234567890'];
$mk = static function (string $flavor, string $quote = '') use ($flavCust) {
    return order_create([
        'items' => [['slug' => 'zz-flav', 'sizeId' => '2270',
                     'flavorId' => $flavor, 'qty' => 1]],
        'customer' => $flavCust,
    ] + ($quote === '' ? [] : ['quote' => $quote]));
};
$drop = static function (array $o): void {
    $id = (int) ($o['order']['id'] ?? 0);
    if ($id > 0) {
        db_query('DELETE FROM order_items WHERE order_id = ?', [$id]);
        db_query('DELETE FROM orders WHERE id = ?', [$id]);
    }
};

$o1 = $mk('vanilla');
ok('the plain flavour pays the base price',
   (int) ($o1['order']['total'] ?? 0) === 4320000,
   number_format((int) ($o1['order']['total'] ?? 0)));
$drop($o1);

$o2 = $mk('cookies');
ok('the premium flavour pays base plus premium',
   (int) ($o2['order']['total'] ?? 0) === 4650000,
   number_format((int) ($o2['order']['total'] ?? 0)));
$drop($o2);

/* A cart can hold a flavour that has since been deleted. Serving the plain one
   instead would ship a tub the customer did not order, so it is refused — the
   same answer an unresolved size already gets. */
$o3 = $mk('no-such-flavour');
ok('a flavour that no longer exists is refused, not downgraded', empty($o3['ok']));

echo "\n== the quote covers the premium as well as the tub ==\n";
/* The two are quoted separately so a product with four sizes and ten flavours
   needs fourteen entries rather than forty — but both halves have to be
   honoured, or a shopper who was shown a premium flavour would find the
   premium repriced under them while the base was held. */
$fq = quote_issue([
    quote_key('zz-flav', '2270')             => 4320000,
    quote_flavor_key('zz-flav', 'cookies')   => 330000,
]);
db_query('UPDATE product_sizes SET price = 5000000 WHERE product_id = ?', [$fpid]);
db_query("UPDATE product_flavors SET price_delta_toman = 900000
           WHERE product_id = ? AND ext_id = 'cookies'", [$fpid]);

$o4 = $mk('cookies', $fq);
ok('both halves are held to what was quoted',
   (int) ($o4['order']['total'] ?? 0) === 4650000,
   number_format((int) ($o4['order']['total'] ?? 0)));
$drop($o4);

/* And downward, so a premium the shop has since REDUCED reaches a shopper
   holding the old quote. */
db_query("UPDATE product_flavors SET price_delta_toman = 100000
           WHERE product_id = ? AND ext_id = 'cookies'", [$fpid]);
$o5 = $mk('cookies', $fq);
ok('a reduced premium reaches an old quote too',
   (int) ($o5['order']['total'] ?? 0) === 4420000,
   number_format((int) ($o5['order']['total'] ?? 0)));
$drop($o5);

ok('the two key shapes cannot collide',
   quote_key('p', 'x') !== quote_flavor_key('p', 'x'));

db_query("DELETE FROM products WHERE slug LIKE 'zz-flav%'");
ok('flavour fixtures cleaned up',
   (int) db_value("SELECT COUNT(*) FROM products WHERE slug LIKE 'zz-flav%'") === 0);

echo "\n== housekeeping ==\n";
db_query('DELETE FROM price_quotes');
db_query(
    'INSERT INTO price_quotes (token, payload, expires_at)
     VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 3 DAY))',
    [str_repeat('b', 32), '{"x|":1}']);
db_query(
    'INSERT INTO price_quotes (token, payload, expires_at)
     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 3 HOUR))',
    [str_repeat('c', 32), '{"y|":1}']);
$swept = quotes_sweep();
ok('the sweep drops what has long expired', $swept === 1, (string) $swept);
ok('  and leaves a live one alone',
   (int) db_value('SELECT COUNT(*) FROM price_quotes') === 1);

db_query('DELETE FROM price_quotes');
db_query("DELETE FROM products WHERE slug LIKE 'zz-quote%'");
ok('cleaned up',
   (int) db_value("SELECT COUNT(*) FROM products WHERE slug LIKE 'zz-quote%'") === 0);

echo "\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
