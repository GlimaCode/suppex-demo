<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/variants.php';
require_once dirname(__DIR__, 2) . '/lib/stock.php';

/** Read the same stock source checkout uses; old size counts are not variant stock. */
function admin_inventory_summary(array $product, array $variants, array $sizes): array
{
    $out = ['kind' => 'empty', 'qty' => 0, 'variants' => count($variants),
            'inquiries' => 0, 'counted' => 0, 'low' => false];
    $sources = [];
    if ($variants) {
        foreach ($variants as $v) {
            if ((int) $v['is_active'] !== 1 || (int) $v['price'] <= 0) { continue; }
            if (variant_requires_inquiry($v)) { $out['inquiries']++; continue; }
            $sources[] = $v['stock_qty'] === null ? null : (int) $v['stock_qty'];
        }
    } else {
        $parent = $product['stock_qty'] === null ? null : (int) $product['stock_qty'];
        $shared = !$sizes;
        foreach ($sizes as $s) {
            if ($s['stock_qty'] === null) { $shared = true; }
            else { $sources[] = (int) $s['stock_qty']; }
        }
        // Several sizes may inherit one shared product count: count it only once.
        if ($shared) { $sources[] = $parent; }
    }
    $untracked = false;
    foreach ($sources as $qty) {
        if ($qty === null) { $untracked = true; continue; }
        $out['counted']++;
        $out['qty'] += max(0, $qty);
        if ($qty <= stock_low_threshold()) { $out['low'] = true; }
    }
    if ((int) $product['in_stock'] !== 1) {
        $out['kind'] = 'unavailable';
    } elseif ($untracked || $out['qty'] > 0) {
        $out['kind'] = 'available';
    } elseif ($out['inquiries'] > 0) {
        $out['kind'] = 'inquiry';
    }
    if ($untracked) { $out['qty'] = null; }
    return $out;
}

/** Two batched reads, including products whose variants are all disabled. */
function admin_inventory_map(array $products): array
{
    $ids = array_values(array_unique(array_map(static fn(array $p): int => (int) $p['id'], $products)));
    if (!$ids) { return []; }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $variants = $sizes = [];
    $inquiry = variants_inquiry_ready() ? 'requires_inquiry' : '0 AS requires_inquiry';
    foreach (db_all("SELECT product_id, is_active, price, stock_qty, $inquiry
                       FROM product_variants WHERE product_id IN ($in)", $ids) as $v) {
        $variants[(int) $v['product_id']][] = $v;
    }
    foreach (db_all("SELECT product_id, stock_qty FROM product_sizes WHERE product_id IN ($in)", $ids) as $s) {
        $sizes[(int) $s['product_id']][] = $s;
    }
    $out = [];
    foreach ($products as $p) {
        $id = (int) $p['id'];
        $out[$id] = admin_inventory_summary($p, $variants[$id] ?? [], $sizes[$id] ?? []);
    }
    return $out;
}

function admin_inventory_badge(array $summary): string
{
    if ($summary['kind'] === 'unavailable') {
        return '<span class="pill pill--cancelled">ناموجود</span>';
    }
    if ($summary['kind'] === 'inquiry') {
        return '<span class="pill pill--new">نیازمند استعلام</span>';
    }
    if ($summary['kind'] !== 'available') {
        return '<span class="pill pill--cancelled">تمام شد</span>';
    }
    if ($summary['qty'] === null) {
        return '<span class="pill pill--paid">موجود</span>';
    }
    $class = (int) $summary['qty'] <= stock_low_threshold() ? 'new' : 'paid';
    return '<span class="pill pill--' . $class . '"><span class="num">'
        . (int) $summary['qty'] . '</span> عدد</span>';
}
