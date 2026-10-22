<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/admin/partials/inventory.php';
require_once SUPPEX_ROOT . '/lib/products.php';
if (db_value('SELECT DATABASE()') !== 'suppex_test') { exit(1); }
$pass = $fail = 0;
function ok(string $label, bool $condition): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "  ok   $label\n"; }
    else { $fail++; echo "  FAIL $label\n"; }
}
$product = ['in_stock' => 1, 'stock_qty' => 0];
$variant = ['is_active' => 1, 'price' => 1000000, 'stock_qty' => 10, 'requires_inquiry' => 0];
$summary = static fn(array $vs, array $p = [], array $ss = []) => admin_inventory_summary(array_merge($product, $p), $vs, $ss);
$s = $summary([$variant], [], [['stock_qty' => 0]]);
ok('ten flavor units override stale zero product and size counts', $s['kind'] === 'available' && $s['qty'] === 10);
ok('positive count replaces the sold-out badge', strpos(admin_inventory_badge($s), '>10<') !== false && strpos(admin_inventory_badge($s), 'تمام شد') === false);
$s = $summary([$variant, array_merge($variant, ['stock_qty' => 0])]);
ok('an empty flavor does not make the other flavor sold out', $s['qty'] === 10 && $s['kind'] === 'available' && $s['low']);
foreach (['is_active' => 0, 'price' => 0, 'requires_inquiry' => 1] as $field => $value) {
    $s = $summary([$variant, array_merge($variant, [$field => $value, 'stock_qty' => 99])]);
    ok($field . ' excludes non-purchasable units from the count', $s['qty'] === 10);
}
$s = $summary([array_merge($variant, ['requires_inquiry' => 1])]);
ok('inquiry-only stock is not offered as buyable', $s['kind'] === 'inquiry' && $s['qty'] === 0 && strpos(admin_inventory_badge($s), 'استعلام') !== false);
$s = $summary([array_merge($variant, ['stock_qty' => null])]);
ok('an untracked flavor remains available despite the old zero', $s['kind'] === 'available' && $s['qty'] === null);
$s = $summary([$variant, array_merge($variant, ['stock_qty' => null])]);
ok('a mixed finite/untracked product does not claim an exact total', $s['kind'] === 'available' && $s['qty'] === null);
ok('manual unavailable still overrides all flavor quantities', $summary([$variant], ['in_stock' => 0])['kind'] === 'unavailable');
ok('all disabled flavors do not fall back to old parent stock', $summary([array_merge($variant, ['is_active' => 0])], ['stock_qty' => 99])['kind'] === 'empty');
ok('negative counts are sold out', $summary([array_merge($variant, ['stock_qty' => -2])])['kind'] === 'empty');
$s = $summary([], [], [['stock_qty' => 0], ['stock_qty' => 4]]);
ok('legacy sizes report available stock rather than the minimum size', $s['kind'] === 'available' && $s['qty'] === 4);
$s = $summary([], ['stock_qty' => 6], [['stock_qty' => null], ['stock_qty' => null], ['stock_qty' => 2]]);
ok('several legacy sizes sharing the parent pool count that pool once', $s['qty'] === 8 && $s['counted'] === 2);
ok('a legacy untracked product is still available', $summary([], ['stock_qty' => null])['qty'] === null);
ok('empty inventory lists are safe', admin_inventory_map([]) === []);

/* Reproduce the imported product with real tables and authenticated pages. */
db_query("DELETE FROM products WHERE slug = 'zz-inventory'");
$pid = db_insert("INSERT INTO products (slug, name_fa, price, stock_qty, in_stock, is_active, sort_order)
                  VALUES ('zz-inventory', 'zz-inventory آزمون طعم', 1000000, 0, 1, 1, -999)");
try {
    db_query("INSERT INTO product_sizes (product_id, ext_id, label, price, stock_qty) VALUES (?, 'one', 'یک بسته', 1000000, 0)", [$pid]);
    db_query("INSERT INTO product_flavors (product_id, ext_id, name) VALUES (?, 'berry', 'توت')", [$pid]);
    variants_adopt($pid);
    db_query('UPDATE product_variants SET stock_qty = 10 WHERE product_id = ?', [$pid]);
    $p = db_one('SELECT id, in_stock, stock_qty FROM products WHERE id = ?', [$pid]);
    $map = admin_inventory_map([$p]);
    ok('batched DB summary reads the persisted flavor count', $map[$pid]['qty'] === 10 && $map[$pid]['variants'] === 1);
    $public = product_get('zz-inventory');
    ok('admin availability agrees with the storefront', $map[$pid]['kind'] === 'available' && !empty($public['inStock']));
    $render = static function (string $page, string $filter = ''): string {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/admin-inventory-page.php')
            . ' ' . escapeshellarg($page) . ' ' . escapeshellarg($filter);
        exec($cmd . ' 2>&1', $lines, $code);
        return $code === 0 ? implode("\n", $lines) : '';
    };
    foreach (['products', 'stock'] as $page) {
        $html = $render($page);
        ok($page . ' renders the actual count and the combination editor link',
           strpos($html, '>10<') !== false && strpos($html, 'id=' . $pid . '#sec-options') !== false);
        $obsolete = $page === 'stock' ? 'name="p[' . $pid . ']"' : 'name="q[' . $pid . '][stock_qty]"';
        ok($page . ' has no misleading editable legacy quantity for a variant product', strpos($html, $obsolete) === false);
    }
    ok('healthy variants ignore stale zero in the low-stock filter', strpos($render('stock', 'low'), 'zz-inventory آزمون طعم') === false);
    db_query('UPDATE product_variants SET stock_qty = 1 WHERE product_id = ?', [$pid]);
    ok('a genuinely low flavor appears in the low-stock filter', strpos($render('stock', 'low'), 'zz-inventory آزمون طعم') !== false);
    db_query('UPDATE product_variants SET stock_qty = 10 WHERE product_id = ?', [$pid]);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/stocksave.php') . " $pid 99 0 one 99 0";
    exec($cmd . ' 2>&1', $lines, $code);
    ok('a stale stock form cannot change obsolete parent/size counts', $code === 0
       && (int) db_value('SELECT stock_qty FROM products WHERE id = ?', [$pid]) === 0
       && (int) db_value('SELECT stock_qty FROM product_sizes WHERE product_id = ?', [$pid]) === 0);
    $fields = ['action' => 'quick:' . $pid, 'q' => [$pid => ['price' => '1000000', 'is_active' => '1', 'in_stock' => '1', 'stock_was' => '0', 'stock_qty' => '99']]];
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/productpost.php') . ' '
         . escapeshellarg(base64_encode((string) json_encode($fields)));
    exec($cmd . ' 2>&1', $quickLines, $code);
    ok('quick edit also ignores obsolete quantity fields on variant products', $code === 0 && (int) db_value('SELECT stock_qty FROM products WHERE id = ?', [$pid]) === 0);
    ok('reading and legacy form submissions preserve the real variant stock', (int) db_value('SELECT stock_qty FROM product_variants WHERE product_id = ?', [$pid]) === 10);
    db_query('UPDATE product_variants SET requires_inquiry = 1 WHERE product_id = ?', [$pid]);
    $map = admin_inventory_map([$p]);
    $public = product_get('zz-inventory');
    ok('admin and storefront agree after switching to inquiry', $map[$pid]['kind'] === 'inquiry' && !empty($public['requiresInquiry']) && empty($public['inStock']));
} finally {
    db_query('DELETE FROM products WHERE id = ?', [$pid]);
}
echo "\n  passed: $pass  failed: $fail\n";
exit($fail ? 1 : 0);
