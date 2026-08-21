<?php
/* ===========================================================================
   SUPPEX — Catalogue model
   ---------------------------------------------------------------------------
   Reads products out of MySQL and shapes them into exactly the objects the
   front-end already consumes. That shape is not negotiable: assets/js/ui.js
   renders `nameFa`, `compareAt`, `variantLabel` and so on today, and the whole
   point of the repository seam is that switching to a real backend changes no
   rendering code. The mapping happens here, once.
   =========================================================================== */

declare(strict_types=1);

/** Discount fields are derived, never stored — a stored percentage drifts out
    of step with the prices the moment either one is edited. */
function product_derive(array $p): array
{
    $saleMode  = setting_bool('sale_mode', true);
    $compareAt = $p['compareAt'] ?? null;
    $onSale    = $saleMode && $compareAt !== null && $compareAt > $p['price'];

    $p['onSale'] = $onSale;
    $p['discountPercent'] = $onSale
        ? (int) round((1 - $p['price'] / $compareAt) * 100)
        : 0;
    return $p;
}

function product_row_to_api(array $r): array
{
    return product_derive([
        'id'           => (int) $r['id'],
        'slug'         => $r['slug'],
        'name'         => $r['name_en'],
        'nameFa'       => $r['name_fa'],
        'brand'        => $r['brand'],
        'category'     => $r['category_slug'] ?? null,
        'price'        => (int) $r['price'],
        'compareAt'    => $r['compare_at'] === null ? null : (int) $r['compare_at'],
        'inStock'      => (bool) $r['in_stock'],
        'badges'       => $r['badges'] === '' ? [] : explode(',', $r['badges']),
        'image'        => $r['image'],
        'variantLabel' => $r['variant_label'],
        'short'        => $r['short'],
    ]);
}

const PRODUCT_LIST_COLUMNS = '
    p.id, p.slug, p.name_fa, p.name_en, p.brand, p.price, p.compare_at,
    p.in_stock, p.badges, p.image, p.variant_label, p.short,
    c.slug AS category_slug';

/**
 * @param array{category?:string,notCategory?:string,badge?:string,
 *              exclude?:string,limit?:int} $opts
 */
function products_list(array $opts = []): array
{
    $sql = 'SELECT ' . PRODUCT_LIST_COLUMNS . '
              FROM products p
              LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.is_active = 1';
    $params = [];

    if (!empty($opts['category'])) {
        $sql .= ' AND c.slug = ?';
        $params[] = $opts['category'];
    }
    if (!empty($opts['notCategory'])) {
        $sql .= ' AND (c.slug IS NULL OR c.slug <> ?)';
        $params[] = $opts['notCategory'];
    }
    if (!empty($opts['badge'])) {
        /* FIND_IN_SET matches whole comma-separated entries, so 'new' does not
           also match a hypothetical 'renewed'. */
        $sql .= ' AND FIND_IN_SET(?, p.badges)';
        $params[] = $opts['badge'];
    }
    if (!empty($opts['exclude'])) {
        $sql .= ' AND p.slug <> ?';
        $params[] = $opts['exclude'];
    }

    $sql .= ' ORDER BY p.sort_order ASC, p.id ASC';

    /* LIMIT cannot be a bound parameter in MySQL when emulation is off, so it
       is cast to an int and clamped instead of interpolated raw. */
    $limit = isset($opts['limit']) ? (int) $opts['limit'] : 0;
    if ($limit > 0) {
        $sql .= ' LIMIT ' . min($limit, 200);
    }

    return array_map('product_row_to_api', db_all($sql, $params));
}

/** The full product, with gallery, flavours, sizes and nutrition. */
function product_get(string $slug): ?array
{
    $r = db_one('SELECT p.*, c.slug AS category_slug
                   FROM products p
                   LEFT JOIN categories c ON c.id = p.category_id
                  WHERE p.slug = ? AND p.is_active = 1', [$slug]);
    if ($r === null) {
        return null;
    }

    $p = product_row_to_api($r);
    $id = (int) $r['id'];

    $gallery = db_all('SELECT path FROM product_images
                        WHERE product_id = ? ORDER BY sort_order, id', [$id]);
    $p['gallery'] = $gallery
        ? array_column($gallery, 'path')
        : ($r['image'] !== '' ? [$r['image']] : []);

    $p['flavors'] = array_map(
        static fn(array $f) => ['id' => $f['ext_id'], 'name' => $f['name']],
        db_all('SELECT ext_id, name FROM product_flavors
                 WHERE product_id = ? ORDER BY sort_order, id', [$id])
    );

    $p['sizes'] = array_map(
        static fn(array $s) => [
            'id'        => $s['ext_id'],
            'label'     => $s['label'],
            'servings'  => $s['servings'] === null ? null : (int) $s['servings'],
            'price'     => (int) $s['price'],
            'compareAt' => $s['compare_at'] === null ? null : (int) $s['compare_at'],
        ],
        db_all('SELECT ext_id, label, servings, price, compare_at FROM product_sizes
                 WHERE product_id = ? ORDER BY sort_order, id', [$id])
    );

    $p['description'] = (string) $r['description'];
    $p['features']    = json_decode_array($r['features']);
    $p['ingredients'] = (string) $r['ingredients'];
    $p['usage']       = (string) $r['usage_text'];

    $rows = json_decode_array($r['nutrition_rows']);
    $p['nutrition'] = [
        'servingSize' => $r['nutrition_serving'],
        'rows'        => $rows,
    ];

    return $p;
}

function categories_list(): array
{
    $rows = db_all('SELECT c.slug, c.name_fa, c.name_latin, c.blurb, c.image,
                           (SELECT COUNT(*) FROM products p
                             WHERE p.category_id = c.id AND p.is_active = 1) AS product_count
                      FROM categories c
                     WHERE c.is_active = 1
                     ORDER BY c.sort_order, c.id');

    return array_map(static fn(array $c) => [
        'id'        => $c['slug'],
        'name'      => $c['name_fa'],
        'nameLatin' => $c['name_latin'],
        'blurb'     => $c['blurb'],
        'image'     => $c['image'],
        'count'     => (int) $c['product_count'],
    ], $rows);
}

/** Free-text search across the fields a shopper would actually type. */
function products_search(string $query): array
{
    $q = clean_text($query, 80);
    if ($q === '') {
        return [];
    }

    /* LIKE wildcards inside the user's own text are escaped, so searching for
       "100%" looks for a literal percent sign instead of matching everything. */
    $needle = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';

    $sql = 'SELECT ' . PRODUCT_LIST_COLUMNS . '
              FROM products p
              LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.is_active = 1
               AND (p.name_fa LIKE ? OR p.name_en LIKE ? OR p.brand LIKE ?
                    OR p.short LIKE ? OR c.name_fa LIKE ?)
             ORDER BY p.sort_order, p.id
             LIMIT 40';

    return array_map(
        'product_row_to_api',
        db_all($sql, [$needle, $needle, $needle, $needle, $needle])
    );
}
