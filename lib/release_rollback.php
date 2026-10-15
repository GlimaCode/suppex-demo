<?php
declare(strict_types=1);

/** Repair only the fixed, reviewed application paths from the rollback ZIP. */
function release_rollback_files(): array
{
    $manifest = require __DIR__ . '/rollback_manifest.php';
    $root = realpath(SUPPEX_ROOT);
    $result = ['checked' => 0, 'errors' => []];
    foreach ($manifest as $relative => $hash) {
        $file = SUPPEX_ROOT . '/' . $relative;
        $resolved = realpath($file);
        if (!$resolved || is_link($file) || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)
            || !hash_equals($hash, (string) @hash_file('sha256', $file))) {
            $result['errors'][] = $relative;
            continue;
        }
        $dir = dirname($file);
        while ($dir !== SUPPEX_ROOT && strlen($dir) > strlen(SUPPEX_ROOT)) {
            if (is_link($dir) || !@chmod($dir, 0755)) { $result['errors'][] = $relative; break; }
            $dir = dirname($dir);
        }
        if (!@chmod($file, 0644)) { $result['errors'][] = $relative; }
        if (str_ends_with($file, '.php') && function_exists('opcache_invalidate')) { @opcache_invalidate($file, true); }
        $result['checked']++;
    }
    $result['errors'] = array_values(array_unique($result['errors']));
    return $result;
}

/** Undo visibility changes for the three known, previously public products.
 * Never restore a database dump or modify prices, stock, orders or receipts.
 * A persistent marker makes a repeated submission harmless after later edits.
 */
function release_rollback_catalog(): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_query("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('rollback_release_20261015', 'pending')");
        $state = db_value("SELECT setting_value FROM settings WHERE setting_key = 'rollback_release_20261015' FOR UPDATE");
        if ($state === 'done') { $pdo->commit(); return ['products' => 0, 'logos' => 0, 'already' => true]; }
        $products = 0;
        foreach (['rule-1-mass-gainer', 'rule-1-whey-protein-r1-whey-blend', 'optimum-nutrition-micronized-creatine-blueberry-raspberry'] as $slug) {
            $products += db_query('UPDATE products SET is_active = 1 WHERE slug = ? AND is_active = 0', [$slug])->rowCount();
        }
        $logos = 0;
        foreach (['mutant', 'zoomad-labs'] as $slug) {
            $logos += db_query("UPDATE brands SET logo = '' WHERE slug = ? AND logo = ?",
                [$slug, 'assets/images/brands/verified-20261015/' . $slug . '.webp'])->rowCount();
        }
        db_query("UPDATE settings SET setting_value = 'done' WHERE setting_key = 'rollback_release_20261015'");
        $pdo->commit();
        return ['products' => $products, 'logos' => $logos, 'already' => false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}
