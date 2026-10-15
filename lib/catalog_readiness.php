<?php
declare(strict_types=1);

const CATALOG_UNPRICED_MARKER = 999999999;

function catalog_price_valid(int $price): bool
{
    return $price > 0 && $price < CATALOG_UNPRICED_MARKER;
}

/** Official manufacturer assets; only empty logo slots are filled. */
function launch_brand_logos(): int
{
    $assets = [
        'mutant' => ['Mutant', 'f5eac7b0132c3788f7938b7f6a48b40b78557beea5963776f1c2cf5b2ef8d0e9'],
        'zoomad-labs' => ['Zoomad Labs', 'd26965b2c25fb3e3af6e1df61dfc898cecd7b31e65354c82da14b4f995246677'],
    ];
    $present = [];
    foreach (db_all("SELECT DISTINCT brand FROM products WHERE brand <> ''") as $p) { $present[brand_slug($p['brand'])] = true; }
    $filled = 0;
    foreach ($assets as $slug => [$name, $hash]) {
        if (!isset($present[$slug])) { continue; }
        $path = 'assets/images/brands/verified-20261015/' . $slug . '.webp';
        $file = SUPPEX_ROOT . '/' . $path;
        if (!is_file($file) || hash_file('sha256', $file) !== $hash || (getimagesize($file)[2] ?? 0) !== IMAGETYPE_WEBP) {
            throw new RuntimeException('فایل لوگوی بررسی‌شده کامل نیست.');
        }
        $existing = db_one('SELECT logo FROM brands WHERE slug = ?', [$slug]);
        if ($existing === null) {
            db_query('INSERT INTO brands(slug, name, logo) VALUES(?,?,?)', [$slug, $name, $path]);
            $filled++;
        } elseif (trim((string) $existing['logo']) === '') {
            db_query("UPDATE brands SET logo = ? WHERE slug = ? AND (logo = '' OR logo IS NULL)", [$path, $slug]);
            $filled++;
        }
    }
    return $filled;
}

/** Products needing owner-supplied facts must stay out of public sale. */
function launch_catalog_issues(): array
{
    $out = [];
    foreach (db_all('SELECT id, slug, name_fa, image, price FROM products WHERE is_active = 1 ORDER BY id') as $p) {
        $id = (int) $p['id'];
        $bad = !catalog_price_valid((int) $p['price'])
            || (int) db_value('SELECT COUNT(*) FROM product_sizes WHERE product_id = ? AND price >= ?', [$id, CATALOG_UNPRICED_MARKER]) > 0
            || (int) db_value('SELECT COUNT(*) FROM product_variants WHERE product_id = ? AND is_active = 1 AND (price <= 0 OR price >= ?)', [$id, CATALOG_UNPRICED_MARKER]) > 0;
        $image = trim((string) $p['image']);
        $missing = $image === '' || strtolower(pathinfo($image, PATHINFO_EXTENSION)) === 'svg';
        if ($bad || $missing) {
            $p['reason'] = $bad ? 'قیمت نیازمند تکمیل' : 'عکس واقعی نیازمند تکمیل';
            $out[] = $p;
        }
    }
    return $out;
}

/** Explicit, authenticated deployment step; never changes prices or stock. */
function launch_prepare(): array
{
    require_once __DIR__ . '/catalog_photos.php';
    $available = [];
    foreach (catalog_photos_status() as $p) {
        if ($p['matches']) {
            if (!$p['valid']) { throw new RuntimeException('فایل‌های بستهٔ تصاویر کامل نیستند.'); }
            $available[] = $p['slug'];
        }
    }
    $photos = catalog_photos_apply($available);
    if ($photos['errors']) { throw new RuntimeException('اتصال تصاویر کامل نشد؛ بخش مدیریت تصاویر را بررسی کنید.'); }
    $hidden = [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Serialize catalogue preparation against normal parent-row edits.
        db_all('SELECT id FROM products WHERE is_active = 1 ORDER BY id FOR UPDATE');
        foreach (launch_catalog_issues() as $p) {
            db_query('UPDATE products SET is_active = 0 WHERE id = ? AND is_active = 1', [(int) $p['id']]);
            $hidden[] = ['slug' => $p['slug'], 'name' => $p['name_fa'], 'reason' => $p['reason']];
        }
        $logos = launch_brand_logos();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return ['photos' => $photos, 'hidden' => $hidden, 'logos' => $logos];
}
