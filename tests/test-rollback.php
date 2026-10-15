<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/bootstrap.php';
if (suppex_config()['db']['name'] !== 'suppex_test') { throw new RuntimeException('Disposable test database required'); }
require_once SUPPEX_ROOT . '/lib/release_rollback.php';
$pass = 0;
function check_rollback(string $name, bool $value): void {
    global $pass;
    if (!$value) { throw new RuntimeException('FAIL ' . $name); }
    $pass++; echo "ok $name\n";
}
$slugs = ['rule-1-mass-gainer', 'rule-1-whey-protein-r1-whey-blend', 'optimum-nutrition-micronized-creatine-blueberry-raspberry'];
$ids = [];
$oldMarker = db_one("SELECT * FROM settings WHERE setting_key = 'rollback_release_20261015'");
$oldBrands = db_all("SELECT * FROM brands WHERE slug IN ('mutant', 'zoomad-labs') ORDER BY slug");
try {
    db_query("DELETE FROM settings WHERE setting_key = 'rollback_release_20261015'");
    foreach (array_merge($slugs, ['rollback-unrelated']) as $slug) {
        if (db_value('SELECT id FROM products WHERE slug = ?', [$slug]) !== null) { throw new RuntimeException('Fixture slug already exists'); }
        $ids[] = db_insert('INSERT INTO products(slug,name_fa,price,stock_qty,is_active,image) VALUES(?,?,?,7,0,?)',
            [$slug, $slug, 4230000, '/uploads/keep-photo.webp']);
    }
    foreach (['mutant', 'zoomad-labs'] as $slug) {
        db_query('INSERT INTO brands(slug,name,logo) VALUES(?,?,?) ON DUPLICATE KEY UPDATE logo=VALUES(logo)',
            [$slug, $slug, $slug === 'mutant' ? 'assets/images/brands/verified-20261015/mutant.webp' : '/uploads/custom-logo.webp']);
    }
    $beforeOrders = [];
    foreach (['orders', 'order_items', 'order_receipts', 'order_events'] as $table) { $beforeOrders[$table] = db_all('SELECT * FROM ' . $table); }
    $beforeProducts = db_all('SELECT * FROM products ORDER BY id');
    $r = release_rollback_catalog();
    check_rollback('only the three previously public products are restored', $r['products'] === 3 && !$r['already']);
    check_rollback('unrelated hidden product remains hidden', (int) db_value("SELECT is_active FROM products WHERE slug='rollback-unrelated'") === 0);
    $afterProducts = db_all('SELECT * FROM products ORDER BY id');
    foreach ($beforeProducts as &$row) { unset($row['is_active'], $row['updated_at']); } unset($row);
    foreach ($afterProducts as &$row) { unset($row['is_active'], $row['updated_at']); } unset($row);
    check_rollback('all product prices, stock, images and other fields are preserved', $beforeProducts === $afterProducts);
    foreach ($beforeOrders as $table => $rows) { check_rollback($table . ' is untouched', $rows === db_all('SELECT * FROM ' . $table)); }
    check_rollback('only the release-added logo is cleared', $r['logos'] === 1 && db_value("SELECT logo FROM brands WHERE slug='mutant'") === '');
    check_rollback('a custom logo is preserved', db_value("SELECT logo FROM brands WHERE slug='zoomad-labs'") === '/uploads/custom-logo.webp');
    db_query('UPDATE products SET is_active=0 WHERE id=?', [$ids[0]]);
    $r = release_rollback_catalog();
    check_rollback('repeat execution preserves subsequent admin edits', $r['already'] && $r['products'] === 0 && (int) db_value('SELECT is_active FROM products WHERE id=?', [$ids[0]]) === 0);
} finally {
    foreach ($ids as $id) { db_query('DELETE FROM products WHERE id=?', [$id]); }
    db_query("DELETE FROM brands WHERE slug IN ('mutant','zoomad-labs')");
    foreach ($oldBrands as $row) {
        $columns = array_keys($row);
        db_query('INSERT INTO brands (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')', array_values($row));
    }
    db_query("DELETE FROM settings WHERE setting_key='rollback_release_20261015'");
    if ($oldMarker) { db_query('INSERT INTO settings(setting_key,setting_value,updated_at) VALUES(?,?,?)', array_values($oldMarker)); }
}
echo "$pass rollback checks passed\n";
