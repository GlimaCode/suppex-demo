<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/products.php';
require_once SUPPEX_ROOT . '/lib/orders.php';
require_once SUPPEX_ROOT . '/lib/migrate.php';
if (db_value('SELECT DATABASE()') !== 'suppex_test') { throw new RuntimeException('Disposable database required'); }
$pass = 0; $fail = 0;
function ok(string $label, bool $yes): void {
    global $pass, $fail;
    if ($yes) { $pass++; echo '  ok   ' . $label . PHP_EOL; }
    else { $fail++; echo '  FAIL ' . $label . PHP_EOL; }
}
function child(array $args): string {
    exec(escapeshellarg(PHP_BINARY) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $lines, $exit);
    if ($exit !== 0) { throw new RuntimeException(implode("\n", $lines)); }
    return implode("\n", $lines);
}
function get_api(string $route, array $params): array {
    return json_decode(child([__DIR__ . '/apiget.php', $route, base64_encode(json_encode($params))]), true, 512, JSON_THROW_ON_ERROR);
}

$pid = db_insert("INSERT INTO products (slug, name_fa, price, price_mode, is_active, in_stock)
                  VALUES ('zz-inquiry', 'زد استعلام', 1000000, 'manual', 1, 1)");
db_query("INSERT INTO product_sizes (product_id, ext_id, label, price, stock_qty) VALUES (?, '900', '۹۰۰ گرم', 1000000, 5)", [$pid]);
db_query("INSERT INTO product_flavors (product_id, ext_id, name) VALUES (?, 'vanilla', 'وانیل'), (?, 'choco', 'شکلات')", [$pid, $pid]);
variants_adopt($pid);
$ext = '900|vanilla';
$row = static fn(): array => db_one('SELECT * FROM product_variants WHERE product_id = ? AND ext_id = ?', [$pid, $ext]);
$save = static function (string $stock, bool $inquiry, bool $original, bool $legacy = false) use ($pid, $ext, $row): string {
    $args = [__DIR__ . '/gridsave.php', (string) $pid, $ext . ':1000000::' . $stock . ':1'];
    $currentStock = $row()['stock_qty'];
    $args[] = base64_encode(json_encode([$ext => $currentStock === null ? '' : (string) $currentStock]));
    if (!$legacy) { $args[] = base64_encode(json_encode(['inquiry' => $inquiry ? [$ext => '1'] : [], 'was' => [$ext => $original ? '1' : '0']])); }
    return child($args);
};
$line = ['slug' => 'zz-inquiry', 'sizeId' => '900', 'flavorId' => 'vanilla', 'qty' => 1];
$quote = quote_issue([quote_key('zz-inquiry', $ext) => 1000000]);

ok('migration defaults existing flavours to ordinary buying', !variant_requires_inquiry($row()));
$save('0', true, false);
ok('the real admin form saves the enquiry checkbox', variant_requires_inquiry($row()) && (int) $row()['stock_qty'] === 0);
$product = product_get('zz-inquiry');
$inquiryRows = array_values(array_filter($product['variants'], static fn(array $v): bool => $v['requiresInquiry']));
ok('the public product identifies the exact enquiry flavour', count($inquiryRows) === 1 && $inquiryRows[0]['id'] === $ext);
ok('a sibling flavour is still available normally', order_price_line(['slug' => 'zz-inquiry', 'sizeId' => '900', 'flavorId' => 'choco', 'qty' => 1]) !== null);
ok('an old cart and old price quote cannot buy an enquiry flavour', order_price_line($line, quote_prices($quote)) === null);
$countBefore = (int) db_value('SELECT COUNT(*) FROM orders');
$result = order_create(['items' => [$line], 'quote' => $quote, 'customer' => ['name' => 'تست', 'phone' => '09120000000', 'address' => 'آدرس آزمایشی بدون ارسال', 'postal' => '1234567890']]);
ok('an enquiry does not create an order or payment', !$result['ok'] && (int) db_value('SELECT COUNT(*) FROM orders') === $countBefore);
$api = get_api('prices', ['slugs' => 'zz-inquiry']);
$priced = $api['prices'][0] ?? [];
ok('cart reconciliation keeps the sibling but omits the enquiry row',
   count($priced['variants'] ?? []) === 1
   && in_array('900|choco', array_column($priced['variants'], 'id'), true)
   && !in_array($ext, array_column($priced['variants'], 'id'), true));

foreach ([null, 5] as $stock) {
    db_query('UPDATE product_variants SET stock_qty = ? WHERE product_id = ? AND ext_id = ?', [$stock, $pid, $ext]);
    ok('inquiry overrides ' . ($stock === null ? 'uncounted' : 'positive') . ' stock', stock_remaining($pid, $ext) === 0 && order_price_line($line) === null);
    $blocked = false;
    db()->beginTransaction();
    try { stock_take_line($pid, $ext, 1, 'تست'); }
    catch (StockShortfall $e) { $blocked = true; }
    finally { db()->rollBack(); }
    $currentStock = $row()['stock_qty'];
    ok('the locking reservation also refuses that enquiry', $blocked && ($stock === null ? $currentStock === null : (int) $currentStock === $stock));
}

db_query('UPDATE product_variants SET requires_inquiry = 1 WHERE product_id = ?', [$pid]);
$all = products_list(['limit' => 200]);
$card = array_values(array_filter($all, static fn(array $p): bool => $p['slug'] === 'zz-inquiry'))[0];
ok('an all-enquiry card asks instead of advertising direct buying', $card['requiresInquiry'] && !$card['inStock'] && $card['needsChoice']);
$save('0', false, true);
ok('approval without a positive stock count is refused atomically', variant_requires_inquiry($row()) && (int) $row()['stock_qty'] === 5);
$save('3', false, true);
ok('manual approval accepts an actual positive count', !variant_requires_inquiry($row()) && (int) $row()['stock_qty'] === 3 && order_price_line($line) !== null);
db_query('UPDATE product_variants SET requires_inquiry = 1 WHERE product_id = ? AND ext_id = ?', [$pid, $ext]);
$save('3', false, false, true);
ok('a form opened before the new feature cannot clear the enquiry flag', variant_requires_inquiry($row()));
$save('3', false, false);
ok('an unchanged stale checkbox preserves a newer enquiry decision', variant_requires_inquiry($row()));
db_query('UPDATE product_variants SET requires_inquiry = 0 WHERE product_id = ? AND ext_id = ?', [$pid, $ext]);
$html = $save('4', false, true);
ok('a conflicting approval rolls back the entire save', (int) $row()['stock_qty'] === 3 && str_contains($html, 'هیچ تغییری ذخیره نشد'));
db_query('UPDATE product_variants SET requires_inquiry = 1, is_active = 0 WHERE product_id = ?', [$pid]);
ok('inactive wins over the enquiry checkbox', product_get('zz-inquiry')['variants'] === []);
db_query('UPDATE products SET in_stock = 0 WHERE id = ?', [$pid]);
ok('the product manual off switch also suppresses enquiry', !product_get('zz-inquiry')['inquiryAllowed']);

db_query('DELETE vv FROM product_variant_values vv JOIN product_variants v ON v.id = vv.variant_id WHERE v.product_id = ?', [$pid]);
db_query('DELETE FROM product_variants WHERE product_id = ?', [$pid]);
db_query('DELETE av FROM product_attribute_values av JOIN product_attributes a ON a.id = av.attribute_id WHERE a.product_id = ?', [$pid]);
db_query('DELETE FROM product_attributes WHERE product_id = ?', [$pid]);
db_query('DELETE FROM products WHERE id = ?', [$pid]);

/* Rehearse the upload-before-migration window in fresh request processes. */
db()->exec('ALTER TABLE product_variants DROP COLUMN requires_inquiry');
try {
    $api = get_api('products', ['limit' => 2]);
    ok('the catalogue remains available before the additive migration', count($api) === 2 && isset($api[0]['slug']));
} finally {
    $log = []; migrate_run($log);
}
ok('the existing database upgrades without replacing it', column_exists('product_variants', 'requires_inquiry') && !in_array(false, migrate_pending(), true));
$log = []; migrate_run($log);
ok('the migration can be repeated safely', !array_filter($log, static fn(array $entry): bool => $entry[0] === 'fail'));
ok('existing products stay out of enquiry mode after migration', (int) db_value('SELECT COUNT(*) FROM product_variants WHERE requires_inquiry <> 0') === 0);
echo "\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail ? 1 : 0);
