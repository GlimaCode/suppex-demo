<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/bootstrap.php';
if (suppex_config()['db']['name'] !== 'suppex_test') { exit("Disposable test database required\n"); }
require_once SUPPEX_ROOT . '/lib/catalog_photos.php';
require_once SUPPEX_ROOT . '/lib/products.php';

$pass = 0; $fail = 0;
function ok(string $label, bool $condition): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "  ok   $label\n"; }
    else { $fail++; echo "  FAIL $label\n"; }
}
function commercial_snapshot(int $id): array {
    $out = [];
    foreach (['products' => 'id', 'product_flavors' => 'product_id',
              'product_attribute_values' => null, 'product_variants' => 'product_id'] as $table => $key) {
        $rows = $key === null
            ? db_all('SELECT v.* FROM product_attribute_values v JOIN product_attributes a ON a.id = v.attribute_id WHERE a.product_id = ? ORDER BY v.id', [$id])
            : db_all('SELECT * FROM ' . $table . ' WHERE ' . $key . ' = ? ORDER BY id', [$id]);
        foreach ($rows as &$row) { unset($row['image'], $row['updated_at']); } unset($row);
        $out[$table] = $rows;
    }
    return $out;
}

$manifest = catalog_photos_manifest();
ok('two reviewed products only; ON remains pending an exact photograph',
   count($manifest) === 2 && !isset($manifest['optimum-nutrition-micronized-creatine-blueberry-raspberry']));
$bad = $manifest['mutant-amino']['photos'][0]; $bad['sha256'] = str_repeat('0', 64);
try { catalog_photos_source($bad); $refused = false; } catch (RuntimeException $e) { $refused = true; }
ok('a changed photograph fails checksum validation', $refused);
$bad = ['file' => 'config.sample.php', 'sha256' => hash_file('sha256', SUPPEX_ROOT . '/config.sample.php')];
try { catalog_photos_source($bad); $refused = false; } catch (RuntimeException $e) { $refused = true; }
ok('a valid hash does not permit files outside the product asset directory', $refused);
foreach ($manifest as $pack) { foreach ($pack['photos'] as $photo) {
    $file = catalog_photos_source($photo);
    ok(basename($file) . ' is WebP', getimagesize($file)[2] === IMAGETYPE_WEBP);
    $im = imagecreatefromwebp($file); $w = imagesx($im); $h = imagesy($im);
    $paddingClear = true; $topProduct = false; $bottomProduct = false;
    for ($x = 0; $x < $w; $x++) {
        $topProduct = $topProduct || (((imagecolorat($im, $x, 100) >> 24) & 127) < 127);
        $bottomProduct = $bottomProduct || (((imagecolorat($im, $x, $h - 101) >> 24) & 127) < 127);
        for ($y = 0; $y < 100; $y++) {
            if (((imagecolorat($im, $x, $y) >> 24) & 127) !== 127
                || ((imagecolorat($im, $x, $h - 1 - $y) >> 24) & 127) !== 127) { $paddingClear = false; }
        }
    }
    ok(basename($file) . ' has exactly 100 transparent rows above and below the product', $paddingClear && $topProduct && $bottomProduct);
    imagedestroy($im);
} }

$ids = [];
try {
    foreach (['zoomad-labs-one-raw-creatine' => 'Zoomad Labs', 'mutant-amino' => 'Mutant'] as $slug => $brand) {
        if (db_value('SELECT 1 FROM products WHERE slug = ?', [$slug]) !== null) {
            throw new RuntimeException('Fixture slug already in use');
        }
        db_query('INSERT INTO products (slug, name_fa, name_en, brand, price, compare_at, image, stock_qty)
                  VALUES (?, ?, ?, ?, 4180000, 4500000, ?, 9)', [$slug, $slug, $slug, $brand, 'assets/images/product-placeholder.svg']);
        $ids[$slug] = (int) db()->lastInsertId();
    }
    $zoom = $ids['zoomad-labs-one-raw-creatine']; $mutant = $ids['mutant-amino'];
    $attr = variants_attribute_upsert($zoom, 'flavor', 'طعم', 0);
    foreach ($manifest['zoomad-labs-one-raw-creatine']['photos'] as $i => $photo) {
        variants_value_upsert($attr, $photo['flavor'], $photo['label'], '', $i);
        db_query('INSERT INTO product_flavors (product_id, ext_id, name, sort_order) VALUES (?, ?, ?, ?)',
                 [$zoom, $photo['flavor'], $photo['label'], $i]);
    }
    variants_generate($zoom);
    $before = [$zoom => commercial_snapshot($zoom), $mutant => commercial_snapshot($mutant)];
    $status = catalog_photos_status();
    ok('matched products with placeholders are ready', count(array_filter($status, static fn($r) => $r['missing'] && $r['valid'])) === 2);
    $result = catalog_photos_apply(array_merge(array_keys($manifest), ['unknown-product', '../../setup.php']));
    ok('installs the two selected products without errors', $result['products'] === 2 && !$result['errors']);
    ok('six photographs are attached: five flavours and one bottle', $result['gallery'] === 6);
    ok('pricing, cost, stock and variant configuration are preserved',
       commercial_snapshot($zoom) === $before[$zoom] && commercial_snapshot($mutant) === $before[$mutant]);
    foreach ($ids as $slug => $id) {
        $product = product_get($slug);
        ok($slug . ' has a real main image in the API', catalog_photos_is_real((string) $product['image']));
        ok($slug . ' main image leads its gallery', $product['gallery'][0] === $product['image']);
        ok($slug . ' has responsive WebP copies', !empty($product['imgv']));
    }
    foreach ($manifest['zoomad-labs-one-raw-creatine']['photos'] as $photo) {
        $flavor = $photo['flavor'];
        $path = catalog_photos_store($photo);
        ok($flavor . ' is attached to the legacy flavour', db_value('SELECT image FROM product_flavors WHERE product_id = ? AND ext_id = ?', [$zoom, $flavor]) === $path);
        ok($flavor . ' is attached to its combination', db_value('SELECT image FROM product_variants WHERE product_id = ? AND ext_id = ?', [$zoom, $flavor]) === $path);
    }
    $again = catalog_photos_apply(array_keys($manifest));
    ok('repeated installation has zero changes and no duplicate gallery rows',
       $again['products'] === 0 && $again['gallery'] === 0 && $again['slots'] === 0 && !$again['errors']);
    // Emulate the previous PNG/JPEG pack, including main and flavour links.
    foreach ($manifest as $slug => $pack) { foreach ($pack['photos'] as $photo) {
        $id = $ids[$slug]; $path = catalog_photos_store($photo);
        $old = catalog_photos_previous_paths($photo)[0];
        foreach (['products' => 'id', 'product_images' => 'product_id', 'product_flavors' => 'product_id', 'product_variants' => 'product_id'] as $table => $key) {
            $column = $table === 'product_images' ? 'path' : 'image';
            db_query('UPDATE ' . $table . ' SET ' . $column . ' = ? WHERE ' . $key . ' = ? AND ' . $column . ' = ?', [$old, $id, $path]);
        }
        db_query('UPDATE product_attribute_values v JOIN product_attributes a ON a.id = v.attribute_id SET v.image = ? WHERE a.product_id = ? AND v.image = ?', [$old, $id, $path]);
    } }
    $oldStatus = catalog_photos_status();
    ok('the old pack is offered for upgrade even with a real main photo', count(array_filter($oldStatus, static fn($r) => $r['upgrade'])) === 2);
    $upgraded = catalog_photos_apply(array_keys($manifest));
    ok('the old gallery is upgraded in place', $upgraded['products'] === 2 && $upgraded['gallery'] === 6 && !$upgraded['errors']);
    ok('upgrading preserves commercial data and gallery count', commercial_snapshot($zoom) === $before[$zoom]
       && commercial_snapshot($mutant) === $before[$mutant]
       && (int) db_value('SELECT COUNT(*) FROM product_images WHERE product_id IN (?, ?)', [$zoom, $mutant]) === 6);
    $linksUpdated = true;
    foreach ($manifest as $slug => $pack) { foreach ($pack['photos'] as $photo) {
        $old = catalog_photos_previous_paths($photo)[0]; $id = $ids[$slug];
        $linksUpdated = $linksUpdated && db_value('SELECT 1 FROM product_images WHERE product_id = ? AND path = ?', [$id, $old]) === null
            && db_value('SELECT 1 FROM products WHERE id = ? AND image = ?', [$id, $old]) === null
            && db_value('SELECT 1 FROM product_flavors WHERE product_id = ? AND image = ?', [$id, $old]) === null
            && db_value('SELECT 1 FROM product_variants WHERE product_id = ? AND image = ?', [$id, $old]) === null;
    } }
    ok('old main, gallery and flavour links all point to the new WebP photos', $linksUpdated);
    $again = catalog_photos_apply(array_keys($manifest));
    ok('an upgraded pack is also idempotent', $again['products'] === 0 && $again['gallery'] === 0 && $again['slots'] === 0);
    db_query('UPDATE products SET image = ? WHERE id = ?', ['/uploads/products/owner-main.jpg', $zoom]);
    db_query('UPDATE product_attribute_values SET image = ? WHERE attribute_id = ? AND ext_id = ?',
             ['/uploads/products/owner-mango.jpg', $attr, 'mad-mango']);
    db_query("UPDATE product_variants SET image = '' WHERE product_id = ? AND ext_id = 'mad-mango'", [$zoom]);
    catalog_photos_apply(['zoomad-labs-one-raw-creatine']);
    ok('an owner-selected main image is retained', db_value('SELECT image FROM products WHERE id = ?', [$zoom]) === '/uploads/products/owner-main.jpg');
    ok('a chosen flavour photograph remains authoritative for an empty variant',
       db_value("SELECT image FROM product_variants WHERE product_id = ? AND ext_id = 'mad-mango'", [$zoom]) === '/uploads/products/owner-mango.jpg');
    ok('an untrusted previous filename cannot replace arbitrary uploads', catalog_photos_previous_paths(['previous_files' => ['../../owner.jpg', 'owner.jpg', str_repeat('a', 24) . '.php']]) === []);
    db_query("UPDATE products SET brand = 'Another brand' WHERE id = ?", [$mutant]);
    $wrong = catalog_photos_apply(['mutant-amino']);
    ok('a matching slug with a different brand is refused', count($wrong['errors']) === 1 && $wrong['products'] === 0);
    ok('refusal leaves no active transaction', !db()->inTransaction());
    $mutantMain = db_value('SELECT image FROM products WHERE id = ?', [$mutant]);
    $status = catalog_photos_status();
    ok('a mismatched product is not offered for attachment', count(array_filter($status, static fn($r) => $r['slug'] === 'mutant-amino' && !$r['matches'])) === 1);
    ok('mismatched product image was preserved', db_value('SELECT image FROM products WHERE id = ?', [$mutant]) === $mutantMain);
} finally {
    foreach ($ids as $id) { db_query('DELETE FROM products WHERE id = ?', [$id]); }
}
echo "\npassed: $pass  failed: $fail\n";
exit($fail ? 1 : 0);
