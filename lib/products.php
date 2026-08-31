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

require_once __DIR__ . '/stock.php';

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

/**
 * Is there anything left to sell, once counts are taken into account.
 *
 * in_stock is checked first and separately: it is the manual off switch, and a
 * count must never be able to overrule a human who has said no.
 *
 * The rest is the same most-specific-first resolution the reservation uses. A
 * size with its own count speaks for itself; sizes without one are covered by
 * the product's count; a product with no count at all is unlimited, which is
 * where every product in this shop starts and stays until somebody types a
 * number.
 *
 * @param array $r a products row carrying stock_qty and the three size tallies
 */
function product_row_available(array $r): bool
{
    if ((int) $r['in_stock'] !== 1) {
        return false;
    }

    $count      = $r['stock_qty'] ?? null;
    $productOk  = $count === null || (int) $count > 0;

    $sizesTotal = (int) ($r['sizes_total'] ?? 0);
    if ($sizesTotal === 0) {
        return $productOk;
    }

    /* Any counted size with units left is enough on its own. Otherwise the
       product is only still sellable through a size that is NOT counted, which
       falls back to the product's own number. */
    if ((int) ($r['sizes_available'] ?? 0) > 0) {
        return true;
    }
    $uncounted = $sizesTotal - (int) ($r['sizes_counted'] ?? 0);
    return $uncounted > 0 && $productOk;
}

/**
 * The remaining count to print on a card, or null to print nothing.
 *
 * Only for products without sizes. "۲ عدد باقی مانده" under a product sold in
 * six sizes is a number about nothing in particular — the count that means
 * something there belongs on the size button, which is where the product page
 * puts it.
 *
 * And only when it is low. A shopper does nothing differently on hearing that
 * forty-seven are left, so the number is not news; sending it anyway would
 * turn the public catalogue into a stock report for whoever is reselling the
 * same tubs down the road. Scarcity is worth saying, inventory is not.
 */
function product_row_stock(array $r): ?int
{
    if ((int) ($r['sizes_total'] ?? 0) > 0 || ($r['stock_qty'] ?? null) === null) {
        return null;
    }
    $left = max(0, (int) $r['stock_qty']);
    return $left <= stock_low_threshold() ? $left : null;
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
        /* Was (bool) $r['in_stock'] alone. The flag the whole storefront
           already renders from now knows about counts, so a sold-out product
           gets its «ناموجود» badge and its disabled buy button without a line
           of rendering changing. */
        'inStock'      => product_row_available($r),
        'stock'        => product_row_stock($r),
        'badges'       => $r['badges'] === '' ? [] : explode(',', $r['badges']),
        'image'        => $r['image'],
        'variantLabel' => $r['variant_label'],
        'short'        => $r['short'],
    ]);
}

/**
 * Add the few extra fields the listing card's hover panel reads.
 *
 * Kept separate from product_row_to_api() because product_get() fills the same
 * keys from the real rows straight after, and would otherwise overwrite these
 * summaries with the full ones - which is what it should do.
 *
 * The size and flavour lists arrive pre-joined from SQL: the panel shows them
 * as one line each, so fetching the rows to re-join them here would be work
 * for nothing.
 */
function product_row_add_spec(array $p, array $r): array
{
    $p['features'] = json_decode_array($r['features'] ?? null);

    $sizes = trim((string) ($r['size_labels'] ?? ''));
    $p['sizes'] = $sizes === '' ? [] : array_map(
        static fn(string $label): array => ['label' => $label, 'servings' => null],
        explode(chr(0x1f), $sizes)
    );
    /* One size carries its serving count; several do not, because the panel
       shows the labels themselves in that case. */
    if (count($p['sizes']) === 1 && ($r['size_servings'] ?? null) !== null) {
        $p['sizes'][0]['servings'] = (int) $r['size_servings'];
    }

    $flav = trim((string) ($r['flavor_names'] ?? ''));
    $p['flavors'] = $flav === '' ? [] : array_map(
        static fn(string $name): array => ['id' => '', 'name' => $name],
        explode(chr(0x1f), $flav)
    );

    return $p;
}

/* The trailing three feed the card's hover panel. Joined with U+001F rather
   than a comma because a size label may legitimately contain one. */
const PRODUCT_LIST_COLUMNS = '
    p.id, p.slug, p.name_fa, p.name_en, p.brand, p.price, p.compare_at,
    p.in_stock, p.stock_qty, p.badges, p.image, p.variant_label, p.short, p.features,
    c.slug AS category_slug,
    (SELECT COUNT(*) FROM product_sizes s WHERE s.product_id = p.id) AS sizes_total,
    (SELECT COUNT(s.stock_qty) FROM product_sizes s WHERE s.product_id = p.id) AS sizes_counted,
    (SELECT COUNT(*) FROM product_sizes s
      WHERE s.product_id = p.id AND s.stock_qty > 0) AS sizes_available,
    (SELECT GROUP_CONCAT(s.label ORDER BY s.sort_order, s.id SEPARATOR 0x1f)
       FROM product_sizes s WHERE s.product_id = p.id) AS size_labels,
    (SELECT MIN(s.servings) FROM product_sizes s WHERE s.product_id = p.id) AS size_servings,
    (SELECT GROUP_CONCAT(f.name ORDER BY f.sort_order, f.id SEPARATOR 0x1f)
       FROM product_flavors f WHERE f.product_id = p.id) AS flavor_names';

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

    /* The listing card carries a hover panel, so the list shape gains the few
       fields it reads. product_get() overwrites them with the full rows. */
    return array_map(
        static fn(array $r): array => product_row_add_spec(product_row_to_api($r), $r),
        db_all($sql, $params)
    );
}

/** The full product, with gallery, flavours, sizes and nutrition. */
function product_get(string $slug): ?array
{
    $r = db_one('SELECT p.*, c.slug AS category_slug,
                       (SELECT COUNT(*) FROM product_sizes s
                         WHERE s.product_id = p.id) AS sizes_total,
                       (SELECT COUNT(s.stock_qty) FROM product_sizes s
                         WHERE s.product_id = p.id) AS sizes_counted,
                       (SELECT COUNT(*) FROM product_sizes s
                         WHERE s.product_id = p.id AND s.stock_qty > 0) AS sizes_available
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

    /* A size's count falls back to the product's, which is the same
       resolution the reservation uses — so what the button says and what the
       order actually takes come from one rule rather than two that agree
       until they do not. */
    $parentStock = $r['stock_qty'] === null ? null : (int) $r['stock_qty'];
    $low         = stock_low_threshold();
    $p['sizes'] = array_map(
        static function (array $s) use ($parentStock, $low): array {
            $stock = $s['stock_qty'] !== null
                ? max(0, (int) $s['stock_qty'])
                : $parentStock;
            /* Same rule as the card: a number only when it is low enough to
               change a decision. Zero always travels, because that is what
               greys the size button out. */
            if ($stock !== null) {
                $stock = max(0, $stock);
                if ($stock > $low) { $stock = null; }
            }
            return [
                'id'        => $s['ext_id'],
                'label'     => $s['label'],
                'servings'  => $s['servings'] === null ? null : (int) $s['servings'],
                'price'     => (int) $s['price'],
                'compareAt' => $s['compare_at'] === null ? null : (int) $s['compare_at'],
                'stock'     => $stock,
            ];
        },
        db_all('SELECT ext_id, label, servings, price, compare_at, stock_qty
                  FROM product_sizes
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
