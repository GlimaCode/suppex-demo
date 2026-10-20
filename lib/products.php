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
require_once __DIR__ . '/variants.php';
require_once __DIR__ . '/brands.php';
/* image_variants_map(): which lighter copies of a picture exist. */
require_once __DIR__ . '/images.php';
require_once __DIR__ . '/reviews.php';

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
        /* Printed clean: the catalogue writes «Optimum Nutrition (اپتیموم
           نوتریشن)», and a card sets the brand in spaced capitals - see
           brand_display_name() in lib/brands.php. */
        'brand'        => brand_display_name((string) $r['brand']),
        'category'     => $r['category_slug'] ?? null,
        'price'        => (int) $r['price'],
        'compareAt'    => $r['compare_at'] === null ? null : (int) $r['compare_at'],
        /* Was (bool) $r['in_stock'] alone. The flag the whole storefront
           already renders from now knows about counts, so a sold-out product
           gets its «ناموجود» badge and its disabled buy button without a line
           of rendering changing. */
        'inStock'      => product_row_available($r),
        'inquiryAllowed' => (int) $r['in_stock'] === 1,
        'stock'        => product_row_stock($r),
        'badges'       => $r['badges'] === '' ? [] : explode(',', $r['badges']),
        'image'        => $r['image'],
        /* The widths the picture has lighter copies at (lib/images.php), so
           a card can offer the browser a 320-pixel WebP instead of the
           1400-pixel original. Empty for a picture without them. */
        'imgv'         => image_variants_map([(string) $r['image']]),
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

    /* Not the amounts — just that the shopper has a priced decision to make. */
    $p['hasFlavorPremium'] = (int) ($r['flavor_premiums'] ?? 0) > 0;

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
       FROM product_flavors f WHERE f.product_id = p.id) AS flavor_names,
    /* Whether ANY flavour costs more than the others. The listing carries
       flavour names but not their prices, so a card had no way to know that
       picking one would change the total — and the + button was adding such a
       product with no flavour chosen at all. One boolean is enough: the card
       does not need to price the choice, only to know that a choice exists. */
    (SELECT COUNT(*) FROM product_flavors f
      WHERE f.product_id = p.id AND f.price_delta_toman > 0) AS flavor_premiums';

/**
 * @param array{category?:string,notCategory?:string,badge?:string,
 *              exclude?:string,brand?:string,limit?:int} $opts
 */
function products_list(array $opts = []): array
{
    /* A brand is every spelling of it on the products (lib/brands.php), so
       the filter is a list rather than one value. A brand no product names
       has no products, and the query is not worth running. */
    $brandSpellings = [];
    if (!empty($opts['brand'])) {
        $brandSpellings = brand_spellings((string) $opts['brand']);
        if (!$brandSpellings) {
            return [];
        }
    }

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
    if ($brandSpellings) {
        $sql .= ' AND p.brand IN (' . implode(', ', array_fill(0, count($brandSpellings), '?')) . ')';
        array_push($params, ...$brandSpellings);
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
    /* The stars a card shows come with it: approved reviews only, and a
       count of 0 draws none (lib/reviews.php). */
    return reviews_attach(product_list_choices(array_map(
        static fn(array $r): array => product_row_add_spec(product_row_to_api($r), $r),
        db_all($sql, $params)
    )));
}

/**
 * Whether a card's «+» can add each product by itself, and exactly what.
 *
 * A card has no size or flavour buttons, and it used to add whatever it was
 * given with no size and no flavour. For a product priced by combination that
 * is a line the server cannot price: ten of the twelve products on the home
 * page went into the basket as «دیگر موجود نیست». For one with several
 * flavours it was a tub of nobody's choosing.
 *
 * So each product says which it is. `needsChoice`: more than one size or
 * flavour, a flavour that costs extra, or not exactly one combination on sale -
 * the card sends the shopper to the product page, where the choice is. Or
 * `choice`: the one thing there is to buy, with the ids the order needs, the
 * words the basket line shows and the price the server will charge for it.
 *
 * Four queries for the whole list rather than four per product.
 *
 * @param list<array> $list product_row_add_spec() output
 */
function product_list_choices(array $list): array
{
    $ids = array_values(array_unique(array_filter(
        array_map(static fn(array $p): int => (int) ($p['id'] ?? 0), $list))));
    $sizes = $flavors = $variants = $values = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (db_all("SELECT product_id, COUNT(*) AS n, MIN(ext_id) AS one,
                                MIN(label) AS label, MIN(price) AS price
                           FROM product_sizes WHERE product_id IN ($in)
                          GROUP BY product_id", $ids) as $r) {
            $sizes[(int) $r['product_id']] = $r;
        }
        foreach (db_all("SELECT product_id, COUNT(*) AS n, MIN(ext_id) AS one, MIN(name) AS name
                           FROM product_flavors WHERE product_id IN ($in)
                          GROUP BY product_id", $ids) as $r) {
            $flavors[(int) $r['product_id']] = $r;
        }
        $inquirySql = variants_inquiry_ready() ? 'requires_inquiry' : '0';
        /* Inquiries stay visible, but are never a card's direct cart choice. */
        foreach (db_all("SELECT product_id,
                                SUM(is_active = 1 AND price > 0 AND $inquirySql = 0) AS live,
                                SUM(is_active = 1 AND price > 0 AND $inquirySql = 1) AS inquiries,
                                SUM(is_active = 1 AND price > 0 AND $inquirySql = 0
                                    AND (stock_qty IS NULL OR stock_qty > 0)) AS ready,
                                MIN(CASE WHEN is_active = 1 AND price > 0 AND $inquirySql = 0 THEN id END) AS one,
                                MIN(CASE WHEN is_active = 1 AND price > 0 AND $inquirySql = 0 THEN price END) AS price
                           FROM product_variants WHERE product_id IN ($in)
                          GROUP BY product_id", $ids) as $r) {
            $variants[(int) $r['product_id']] = $r;
        }
        $ones = [];
        foreach ($variants as $v) {
            if ((int) $v['live'] === 1) {
                $ones[] = (int) $v['one'];
            }
        }
        if ($ones) {
            foreach (db_all('SELECT vv.variant_id, a.ext_id AS attr, v.ext_id AS val, v.label
                               FROM product_variant_values vv
                               JOIN product_attribute_values v ON v.id = vv.value_id
                               JOIN product_attributes a ON a.id = v.attribute_id
                              WHERE vv.variant_id IN (' . implode(',', array_fill(0, count($ones), '?')) . ')
                              ORDER BY a.sort_order, a.id', $ones) as $r) {
                $values[(int) $r['variant_id']][(string) $r['attr']] = $r;
            }
        }
    }

    foreach ($list as &$p) {
        $id    = (int) ($p['id'] ?? 0);
        $s     = $sizes[$id] ?? null;
        $f     = $flavors[$id] ?? null;
        $many  = ($s !== null && (int) $s['n'] > 1) || ($f !== null && (int) $f['n'] > 1);
        $choice = null;

        if (isset($variants[$id])) {
            /* Priced by combination, so the combination is the whole answer:
               exactly one on sale, and the card adds that one - every axis it
               is made of, the way order_price_line() resolves it. */
            $v    = $variants[$id];
            $p['hasInquiry'] = !empty($p['inquiryAllowed']) && (int) $v['inquiries'] > 0;
            $p['requiresInquiry'] = $p['hasInquiry'] && (int) $v['ready'] === 0;
            /* A converted product sells variant counts, never its old size count. */
            $p['inStock'] = !empty($p['inquiryAllowed']) && (int) $v['ready'] > 0;
            $vals = (int) $v['live'] === 1 ? ($values[(int) $v['one']] ?? []) : [];
            if (!$many && $vals) {
                $attrs = [];
                foreach ($vals as $attr => $val) {
                    if ($attr !== VARIANT_SIZE_ATTR && $attr !== VARIANT_FLAVOR_ATTR) {
                        $attrs[$attr] = (string) $val['val'];
                    }
                }
                $choice = [
                    'sizeId'   => isset($vals[VARIANT_SIZE_ATTR]) ? (string) $vals[VARIANT_SIZE_ATTR]['val'] : null,
                    'flavorId' => isset($vals[VARIANT_FLAVOR_ATTR]) ? (string) $vals[VARIANT_FLAVOR_ATTR]['val'] : null,
                    'attrs'    => $attrs ?: null,
                    'label'    => product_choice_label(
                        isset($vals[VARIANT_FLAVOR_ATTR]) ? (string) $vals[VARIANT_FLAVOR_ATTR]['label'] : '',
                        isset($vals[VARIANT_SIZE_ATTR]) ? (string) $vals[VARIANT_SIZE_ATTR]['label'] : '',
                        array_map(static fn(array $x): string => (string) $x['label'],
                            array_diff_key($vals, [VARIANT_SIZE_ATTR => 1, VARIANT_FLAVOR_ATTR => 1]))
                    ),
                    'price'    => (int) $v['price'],
                ];
            }
        } elseif (!$many && empty($p['hasFlavorPremium'])) {
            /* The older model: the size's own price when there is a size, as
               order_price_line() charges it. */
            $price = $s !== null ? (int) $s['price'] : (int) ($p['price'] ?? 0);
            if ($price > 0) {
                $choice = [
                    'sizeId'   => $s !== null ? (string) $s['one'] : null,
                    'flavorId' => $f !== null ? (string) $f['one'] : null,
                    'attrs'    => null,
                    'label'    => product_choice_label(
                        $f !== null ? (string) $f['name'] : '',
                        $s !== null ? (string) $s['label'] : '',
                        []
                    ),
                    'price'    => $price,
                ];
            }
        }

        $p['needsChoice'] = $choice === null || !empty($p['hasInquiry']);
        $p['choice']      = $choice;
    }
    unset($p);
    return $list;
}

/** «flavour · size · anything else», the order the product page writes a line in. */
function product_choice_label(string $flavor, string $size, array $rest): string
{
    $bits = [];
    if ($flavor !== '') {
        $bits[] = $flavor;
    }
    if ($size !== '' && $size !== '—') {
        $bits[] = $size;
    }
    foreach ($rest as $label) {
        if ($label !== '') {
            $bits[] = $label;
        }
    }
    return implode(' · ', $bits);
}

/* A product still waiting for its photograph is drawn with this, not with an
   empty src - which every browser painted as a black square that read as a
   broken page. ui.js's IMAGE_PLACEHOLDER is the same file. */
const PRODUCT_PLACEHOLDER = 'assets/images/product-placeholder.svg';

function product_image_or_placeholder(string $src): string
{
    return trim($src) !== '' ? $src : PRODUCT_PLACEHOLDER;
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

    /* The main image leads, wherever it sits in the sort order. «اصلی» in the
       panel and the first photo on this page used to be two different things:
       the cards showed the one marked main, and this page opened on whichever
       was uploaded first. The panel now arranges the images in this same order
       and saves the first as main, so this only matters for a product nobody
       has re-saved since - but for those it is the difference between the
       photo the owner chose and one nobody chose. */
    $gallery = array_column(db_all('SELECT path FROM product_images
                                     WHERE product_id = ? ORDER BY sort_order, id', [$id]), 'path');
    $mainAt  = array_search((string) $r['image'], $gallery, true);
    if ($mainAt !== false && $mainAt > 0) {
        unset($gallery[$mainAt]);
        array_unshift($gallery, (string) $r['image']);
    }
    $p['gallery'] = $gallery
        ? array_values($gallery)
        : ($r['image'] !== '' ? [$r['image']] : []);

    /* priceDelta is what this flavour adds to whatever the chosen size costs.
       Zero for almost every flavour, which is why it is a delta and not a
       price: the shopper picks a size AND a flavour, so a full price per
       combination would be one number per pair to type and to keep current. */
    $p['flavors'] = array_map(
        static fn(array $f) => [
            'id'         => $f['ext_id'],
            'name'       => $f['name'],
            'priceDelta' => (int) $f['price_delta_toman'],
            /* '' means «this flavour has no picture of its own», and the page
               falls back to the product's. Sent even when empty so the client
               never has to tell «no picture» from «an older API». */
            'image'      => (string) ($f['image'] ?? ''),
        ],
        db_all('SELECT ext_id, name, price_delta_toman, image FROM product_flavors
                 WHERE product_id = ? ORDER BY sort_order, id', [$id])
    );

    /* --- attributes and variations --------------------------------------

       Only for a product that actually has them. An empty array would tell
       the page there are axes to draw and none to draw them from; absent
       says «this one is priced the old way», which is true and is what every
       reader already assumes when the key is missing. */
    $attrRows = variants_attributes($id);
    if ($attrRows) {
        $p['attributes'] = array_map(
            static fn(array $a) => [
                'id'     => $a['ext_id'],
                'name'   => $a['name'],
                'values' => array_map(
                    static fn(array $v) => [
                        'id'    => $v['ext_id'],
                        'label' => $v['label'],
                        'image' => $v['image'],
                    ],
                    $a['values']
                ),
            ],
            $attrRows
        );

        /* Switched-off combinations are left out rather than sent with a
           flag. The page would only ever use them to refuse a selection, and
           an absent one already reads as «not for sale» - one rule instead
           of two that have to agree. */
        $p['variants'] = [];
        foreach (variants_list($id) as $v) {
            if ((int) $v['is_active'] !== 1) {
                continue;
            }
            $p['variants'][] = [
                'id'        => (string) $v['ext_id'],
                'price'     => (int) $v['price'],
                'compareAt' => $v['compare_at'] === null ? null : (int) $v['compare_at'],
                /* null is «not counted», not «none left» - the same rule the
                   sizes below follow. */
                'stock'     => $v['stock_qty'] === null ? null : max(0, (int) $v['stock_qty']),
                'requiresInquiry' => variant_requires_inquiry($v),
                'image'     => (string) $v['image'],
                /* The choice this row stands for, so the page can match a
                   selection to it without knowing how the key is built. */
                'choice'    => (function (array $vals): array {
                    $out = [];
                    foreach ($vals as $val) {
                        $out[(string) $val['attr_ext']] = (string) $val['ext_id'];
                    }
                    return $out;
                })($v['values']),
            ];
        }
        $p['hasInquiry'] = $p['inquiryAllowed'] && (bool) array_filter($p['variants'],
            static fn(array $v): bool => $v['requiresInquiry']);
        $readyVariants = array_filter($p['variants'], static fn(array $v): bool =>
            !$v['requiresInquiry'] && $v['price'] > 0 && ($v['stock'] === null || $v['stock'] > 0));
        $p['requiresInquiry'] = $p['hasInquiry'] && !$readyVariants;
        $p['inStock'] = $p['inquiryAllowed'] && (bool) $readyVariants;
    }

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

    /* Where the brand's name on the product page links to - null when there
       is no brand page to go to, so the page shows the name as plain text
       rather than a link to «برند پیدا نشد». */
    $p['brandSlug'] = brand_link_slug((string) $p['brand']);

    /* Every picture this page can show - the gallery, each flavour's, each
       option's, each combination's - with the widths it has lighter copies
       at. The gallery's thumbnails come from here as 160-pixel copies. */
    $pics = array_merge([(string) $p['image']], (array) ($p['gallery'] ?? []),
                        array_column((array) ($p['flavors'] ?? []), 'image'),
                        array_column((array) ($p['variants'] ?? []), 'image'));
    foreach ((array) ($p['attributes'] ?? []) as $attr) {
        $pics = array_merge($pics, array_column((array) ($attr['values'] ?? []), 'image'));
    }
    $p['imgv'] = image_variants_map($pics);

    return reviews_attach([$p])[0];
}

/**
 * The picture a product page opens on - the one app.js paints before anybody
 * touches a chip.
 *
 * app.js's own rule (paintChoiceImage()), from the choices it starts with:
 * the first size that can be bought, the first flavour, the first value of
 * every other axis. Then the photograph of exactly that combination, else the
 * flavour's, else the product's own. Types and truthiness are JavaScript's,
 * on the same array the page receives as its data, so the two agree.
 *
 * Only for a product with flavours: the page paints the choice on opening
 * when it draws the flavour chips, and nowhere else, so without them it
 * opens on the product's own photograph whatever the first combination has.
 *
 * product.php draws this one. Drawing the product's own photograph instead,
 * the script replaced it as soon as it ran: on the live page a phone
 * downloaded the product's photograph (88 KB) and then the first flavour's
 * (97 KB) to show one picture, and the shopper saw it change.
 */
function product_opening_image(array $p): string
{
    $gallery = (array) ($p['gallery'] ?? []);
    $own     = $gallery ? (string) $gallery[0] : (string) ($p['image'] ?? '');
    $flavors = (array) ($p['flavors'] ?? []);
    if (!$flavors) {
        return $own;
    }

    $truthy = static fn($v): bool => !($v === null || $v === '' || $v === 0 || $v === false);

    $sizes = (array) ($p['sizes'] ?? []);
    $size  = null;
    foreach ($sizes as $s) {
        if (($s['stock'] ?? null) !== 0) {
            $size = $s;
            break;
        }
    }
    $size   = $size ?? ($sizes[0] ?? null);
    $flavor = $flavors[0];

    $want = [];
    if ($size !== null && $truthy($size['id'] ?? null)) {
        $want['size'] = $size['id'];
    }
    if ($flavor !== null && $truthy($flavor['id'] ?? null)) {
        $want['flavor'] = $flavor['id'];
    }
    foreach ((array) ($p['attributes'] ?? []) as $a) {
        $aid = $a['id'] ?? null;
        if ($aid === 'size' || $aid === 'flavor' || empty($a['values'])) {
            continue;
        }
        $want[$aid] = $a['values'][0]['id'] ?? null;
    }

    $variant = null;
    foreach ((array) ($p['variants'] ?? []) as $v) {
        $choice = (array) ($v['choice'] ?? []);
        if (count($choice) !== count($want)) {
            continue;
        }
        $hit = true;
        foreach ($choice as $k => $val) {
            if (!array_key_exists($k, $want) || $want[$k] !== $val) {
                $hit = false;
                break;
            }
        }
        if ($hit) {
            $variant = $v;
            break;
        }
    }

    foreach ([(string) ($variant['image'] ?? ''), (string) ($flavor['image'] ?? ''), $own] as $src) {
        if ($src !== '') {
            return $src;
        }
    }
    return '';
}

function categories_list(): array
{
    /* photo: a real photograph from the category's own shelf - the first
       product in the owner's order, one in stock before one that is not -
       for the home page's tile. The drawn tub (image) stays for a category
       with no photographed product yet. */
    $rows = db_all("SELECT c.slug, c.name_fa, c.name_latin, c.blurb, c.image,
                           (SELECT COUNT(*) FROM products p
                             WHERE p.category_id = c.id AND p.is_active = 1) AS product_count,
                           (SELECT p.image FROM products p
                             WHERE p.category_id = c.id AND p.is_active = 1
                               AND p.image <> '' AND p.image NOT LIKE '%.svg'
                             ORDER BY p.in_stock DESC, p.sort_order, p.id LIMIT 1) AS photo
                      FROM categories c
                     WHERE c.is_active = 1
                     ORDER BY c.sort_order, c.id");

    return array_map(static fn(array $c) => [
        'id'        => $c['slug'],
        'name'      => $c['name_fa'],
        'nameLatin' => $c['name_latin'],
        'blurb'     => $c['blurb'],
        'image'     => $c['image'],
        'count'     => (int) $c['product_count'],
        'photo'     => (string) ($c['photo'] ?? ''),
        'imgv'      => image_variants_map([(string) ($c['photo'] ?? '')]),
    ], $rows);
}

/**
 * One category, or null.
 *
 * Built on categories_list() deliberately rather than its own SELECT.
 * products_list() filters on c.slug ALONE - it never looks at c.is_active - so
 * a category switched off in the panel still returns its products, and a page
 * that decided it existed by counting products would happily publish a
 * category the owner had hidden. categories_list() is the only reader that
 * applies is_active, so it is the one that gets to answer "does this exist".
 */
function category_get(string $slug): ?array
{
    if ($slug === '') {
        return null;
    }
    foreach (categories_list() as $c) {
        if ($c['id'] === $slug) {
            return $c;
        }
    }
    return null;
}

/** Free-text search across the fields a shopper would actually type. */
/* ---------------------------------------------------------------------------
   Search
   ---------------------------------------------------------------------------
   It was one LIKE per column, which is exactly what people do not type:
   «پروتئين» from an Arabic keyboard found nothing, «پروتیین» typed in a hurry
   found nothing, «پریورک» found nothing beside «پری‌ورک‌اوت», and «گینر»
   found nothing in a category called «افزایش وزن». Now the words are
   compared the way they are read - one alphabet, no half-spaces, no
   diacritics - each word may be the start of a word in the product, and a
   word that is a known name for something (SEARCH_SYNONYMS) or a goal
   (SEARCH_INTENTS) also finds that. The catalogue is a few hundred products
   at most, so this runs over products_list() in PHP rather than in SQL. */

/** Words that are the same thing, in both directions. Written as typed;
    compared after search_normalize(). */
const SEARCH_SYNONYMS = [
    ['پروتئین', 'protein', 'وی', 'whey', 'ایزوله', 'isolate', 'ایزو', 'iso', 'کازئین', 'casein', 'هیدرولیز'],
    ['کراتین', 'creatine', 'مونوهیدرات', 'monohydrate', 'کرتین'],
    ['گینر', 'gainer', 'مس گینر', 'افزایش وزن', 'وزن گیری', 'حجم', 'چاقی'],
    ['قبل تمرین', 'پیش تمرین', 'پری ورک اوت', 'پری ورک', 'pre workout', 'preworkout', 'پمپ', 'pump', 'انرژی زا'],
    ['آمینو', 'amino', 'bcaa', 'بی سی ای ای', 'eaa', 'اسید آمینه', 'گلوتامین', 'glutamine'],
    ['ویتامین', 'vitamin', 'مولتی ویتامین', 'multivitamin', 'مولتی', 'امگا', 'omega', 'زینک', 'zinc', 'منیزیم', 'magnesium'],
];

/** A goal typed as a search: what it leads to, in one direction only -
    «عضله» finds the protein, but «پروتئین» must not find the creatine. The
    same mapping as lib/goals.php. */
const SEARCH_INTENTS = [
    [['عضله', 'عضله سازی', 'بدنسازی', 'muscle'], ['پروتئین', 'کراتین', 'آمینو']],
    [['ریکاوری', 'recovery', 'بازیابی'], ['پروتئین', 'آمینو']],
    [['قدرت', 'strength', 'انرژی'], ['کراتین', 'قبل تمرین']],
    [['سلامت', 'سلامت عمومی', 'ایمنی'], ['ویتامین']],
];

/** Words that say nothing about a product and would otherwise have to match. */
const SEARCH_STOPWORDS = ['و', 'با', 'برای', 'از', 'به', 'در', 'که', 'را', 'یا', 'مکمل', 'خرید', 'قیمت', 'the', 'and', 'for'];

/**
 * Text as search compares it: Arabic letters as their Persian twins, the
 * hamza and madda carriers as the plain letter, no diacritics or tatweel,
 * Latin digits, half-spaces and punctuation as spaces, Latin in lower case.
 */
function search_normalize(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, [
        'ي' => 'ی', 'ى' => 'ی', 'ئ' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ۀ' => 'ه', 'ؤ' => 'و',
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        "\u{200C}" => ' ', "\u{200D}" => '', 'ـ' => '',
    ]);
    $s = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $s);
    $s = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    return trim($s);
}

/**
 * What else a word of the query may be found as, or null when it is not a
 * known name. A word of three letters or more also counts as the start of a
 * name, so the list is there while the shopper is still typing.
 *
 * @return list<string>|null
 */
function search_alternatives(string $term): ?array
{
    /* Also with the spaces out of both: «پریورک» is «پری ورک اوت» typed
       without them. */
    $squeezed = str_replace(' ', '', $term);
    $starts = static fn(string $phrase): bool =>
        $phrase === $term || (mb_strlen($term) >= 3 && (strpos($phrase, $term) === 0
            || strpos(str_replace(' ', '', $phrase), $squeezed) === 0));
    $alts = [$term];
    $hit  = false;
    foreach (SEARCH_SYNONYMS as $group) {
        $group = array_map('search_normalize', $group);
        foreach ($group as $phrase) {
            if ($starts($phrase)) {
                array_push($alts, ...$group);
                $hit = true;
                break;
            }
        }
    }
    foreach (SEARCH_INTENTS as [$keys, $targets]) {
        foreach (array_map('search_normalize', $keys) as $key) {
            if ($starts($key)) {
                array_push($alts, ...array_map('search_normalize', $targets));
                $hit = true;
                break;
            }
        }
    }
    return $hit ? array_values(array_unique($alts)) : null;
}

/** Is one of these found in this text - a phrase where a word begins, or a
    word as the start of one of its words (or inside it with the spaces taken
    out, for «پریورک» against «پری ورک اوت»)?

    A name of one or two letters is a whole word or nothing: «وی» is whey,
    and as the start of a word it found every «ویتامین». Only what the
    shopper typed, when it is no known name - «کر» on the way to «کراتین» -
    may be the start of a word however short ($loose). */
function search_found(array $alternatives, string $text, bool $loose = false): bool
{
    if ($text === '') {
        return false;
    }
    $padded = ' ' . $text . ' ';
    $words  = explode(' ', $text);
    /* The text run together from each word on: «پریورک» is the start of
       «پریورکاوت», which begins at a word. Squeezing the whole text instead
       found «امینو» inside «ویتامین و مواد». */
    $joined = [];
    foreach ($words as $i => $w) {
        $joined[] = implode('', array_slice($words, $i, 4));
    }
    foreach (array_values($alternatives) as $k => $alt) {
        if ($alt === '') {
            continue;
        }
        if (strpos($alt, ' ') !== false) {
            if (strpos($padded, ' ' . $alt) !== false) {
                return true;
            }
            $alt = str_replace(' ', '', $alt);
        }
        $whole = mb_strlen($alt) <= 2 && !($loose && $k === 0);
        foreach ($words as $w) {
            if ($whole ? $w === $alt : strpos($w, $alt) === 0) {
                return true;
            }
        }
        if (mb_strlen($alt) >= 3) {
            foreach ($joined as $run) {
                if (strpos($run, $alt) === 0) {
                    return true;
                }
            }
        }
    }
    return false;
}

/**
 * The products matching a query, best first: every word must be found
 * somewhere; where it is found decides the order - the name, then the brand,
 * then the category, the summary and the features.
 *
 * @return list<array<string,mixed>>
 */
function products_search(string $query): array
{
    $q = search_normalize(clean_text($query, 80));
    if ($q === '') {
        return [];
    }

    /* The whole query first, when it is a known name of more than one word
       («قبل تمرین», «افزایش وزن»); otherwise word by word. */
    $units  = [];
    $phrase = strpos($q, ' ') !== false ? search_alternatives($q) : null;
    if ($phrase !== null) {
        $units[] = ['alts' => $phrase, 'loose' => false];
    } else {
        $words = array_values(array_filter(explode(' ', $q), static fn(string $w): bool =>
            $w !== '' && !in_array($w, SEARCH_STOPWORDS, true)));
        if (!$words) {
            $words = explode(' ', $q);
        }
        foreach ($words as $w) {
            $known   = search_alternatives($w);
            $units[] = ['alts' => $known ?? [$w], 'loose' => $known === null];
        }
    }

    $catName = [];
    foreach (categories_list() as $c) {
        $catName[(string) $c['id']] = (string) $c['name'];
    }
    $brandFa = [];
    foreach (brands_list() as $b) {
        $brandFa[(string) $b['slug']] = (string) ($b['nameFa'] ?? '');
    }

    $found = [];
    foreach (products_list([]) as $i => $p) {
        $brand  = (string) ($p['brand'] ?? '');
        $slug   = $brand === '' ? '' : brand_slug($brand);
        $fields = [
            5 => search_normalize((string) $p['nameFa'] . ' ' . (string) ($p['name'] ?? '')),
            4 => search_normalize($brand . ' ' . ($brandFa[$slug] ?? '')),
            3 => search_normalize($catName[(string) ($p['category'] ?? '')] ?? ''),
            2 => search_normalize((string) ($p['short'] ?? '')),
            1 => search_normalize(implode(' ', array_filter((array) ($p['features'] ?? []), 'is_string'))),
        ];
        $matched = 0;
        $score   = 0;
        foreach ($units as $u) {
            foreach ($fields as $weight => $text) {
                if (search_found($u['alts'], $text, $u['loose'])) {
                    $matched++;
                    $score += $weight;
                    break;
                }
            }
        }
        if ($matched > 0) {
            $found[] = [$matched, $score, $i, $p];
        }
    }

    /* The products with every word; when none has them all - «کراتین و
       پروتئین» is two products, not one - those with any, the most words
       first. */
    $all  = array_values(array_filter($found, static fn(array $f): bool => $f[0] === count($units)));
    $pool = $all ?: $found;
    usort($pool, static fn(array $a, array $b): int => [$b[0], $b[1], $a[2]] <=> [$a[0], $a[1], $b[2]]);
    return array_slice(array_map(static fn(array $f): array => $f[3], $pool), 0, 40);
}

/**
 * The one line a product card shows under its name: the first of the
 * product's features that says something about the product itself - «25 گرم
 * پروتئین در هر سروینگ» - rather than repeating its brand. The catalogue
 * writes features headline-first (quickSpec() in ui.js relies on the same).
 * A leading «حاوی» goes and the digits are Persian, like the price under it.
 *
 * ui.js's keyFact() is the same rule; tests/key-facts.json holds the two to
 * one table.
 */
function product_key_fact(array $p): string
{
    foreach ((array) ($p['features'] ?? []) as $f) {
        if (!is_string($f)) {
            continue;
        }
        $f = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $f));
        if ($f === '' || preg_match('/^برند(?:\s|:|$)/u', $f)) {
            continue;
        }
        return fa_digits((string) preg_replace('/^حاوی\s+/u', '', $f));
    }
    return '';
}

/* ---------------------------------------------------------------------------
   The home page's top deals, drawn by the server as app.js draws them
   ---------------------------------------------------------------------------
   The deals box in the hero was a square holding the poster until app.js
   drew the deals into it, and then the height of the deals: PageSpeed's one
   layout shift on the home page (0.056 on a phone), everything under the hero
   moving. index.php draws the same slides into the page, so the box has its
   size from the first paint, and app.js keeps them (initHeroDeals()).

   Each function below is its app.js or ui.js namesake, byte for byte - the
   markup has to be the script's, or the box would change size when the
   script takes over. tests/deal-slide.json holds both to one expected slide:
   tests/test-speed.php this side, tests/test-speed.mjs the other. */

/** ui.js's esc(): &#39; where htmlspecialchars() writes &#039;. */
function js_esc(string $s): string
{
    return str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&#39;'], $s);
}

/** encodeURIComponent(): rawurlencode() leaves nothing of !*'() unencoded. */
function js_encode_uri_component(string $s): string
{
    return strtr(rawurlencode($s), ['%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')']);
}

/**
 * The deals the hero shows - pickHeroDeals() in app.js: of $ticked, the ones
 * ticked «تخفیف برتر» that can be sold; failing that, of $all, the ones on
 * sale that can be sold. The largest discount first, three at most. app.js
 * calls it with the ticked list and then, when that comes up empty, with the
 * first 24 products; index.php does the same.
 *
 * @return list<array<string,mixed>>
 */
function hero_deal_picks(array $ticked, array $all): array
{
    $usable = static fn($p): bool => is_array($p) && ($p['inStock'] ?? null) !== false && ($p['price'] ?? 0) > 0;
    $chosen = array_values(array_filter($ticked, static fn($p): bool =>
        $usable($p) && in_array('topdeal', (array) ($p['badges'] ?? []), true)));
    if (!$chosen) {
        $chosen = array_values(array_filter($all, static fn($p): bool => $usable($p) && !empty($p['onSale'])));
    }
    /* usort() keeps equal ones in order since PHP 8, as Array#sort does. */
    usort($chosen, static fn(array $a, array $b): int =>
        (($b['discountPercent'] ?? 0) ?: 0) <=> (($a['discountPercent'] ?? 0) ?: 0));
    return array_slice($chosen, 0, 3);
}

/** One slide - ui.js's dealSlide(product, i, total). */
function hero_deal_slide(array $p, int $i, int $total): string
{
    $src   = (string) ($p['image'] ?? '');
    $shown = trim($src) === '' ? '' : js_esc(trim($src));
    $set   = '';
    $ws    = ($src !== '' && isset($p['imgv'][$src])) ? (array) $p['imgv'][$src] : [];
    if ($ws) {
        $list = [];
        foreach ($ws as $w) {
            $list[] = preg_replace('~\.(jpe?g|png|webp)$~i', '-w' . $w . '.webp', $src) . ' ' . $w . 'w';
        }
        /* SIZES_DEAL in ui.js */
        $set = ' srcset="' . js_esc(implode(', ', $list)) . '" sizes="' . js_esc('(min-width: 1024px) 480px, 88vw') . '"';
    }
    $onSale = !empty($p['onSale']);
    $off = $onSale && ($p['discountPercent'] ?? 0) > 0
        ? '<span class="deal__off"><span class="num">' . fa_digits((string) $p['discountPercent'])
          . "\u{066A}</span> تخفیف</span>"
        : '';
    $price = '<div class="price"><span class="price__now"><span class="num">' . money_fa((int) round((float) $p['price']))
           . "</span>\u{00A0}" . price_unit_html('تومان') . '</span>'
           . ($onSale ? '<span class="price__was num">' . money_fa((int) round((float) ($p['compareAt'] ?? 0))) . '</span>' : '')
           . '</div>';
    $arrow = '<svg class="" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor"'
           . ' stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"'
           . ' focusable="false"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
    $brand = (string) ($p['brand'] ?? '');

    return '<div class="deal' . ($i === 0 ? ' is-on' : '') . '" role="group" '
        . 'aria-roledescription="اسلاید" aria-label="' . fa_digits((string) ($i + 1)) . ' از ' . fa_digits((string) $total) . '">'
        . '<a class="deal__link" href="product.php?p=' . js_encode_uri_component((string) $p['slug']) . '">'
        . '<span class="deal__photo">'
        . '<img src="' . $shown . '"' . $set . ' alt="" width="480" height="480" decoding="async">'
        . $off
        . '</span>'
        . '<div class="deal__info">'
        . '<div class="deal__title">'
        . ($brand !== '' ? '<span class="deal__brand lat">' . js_esc($brand) . '</span>' : '')
        . '<span class="deal__name">' . js_esc((string) ($p['nameFa'] ?? '')) . '</span>'
        . '</div>'
        . '<div class="deal__foot">'
        . $price
        . '<span class="deal__go" aria-hidden="true">' . $arrow . '</span>'
        . '</div>'
        . '</div>'
        . '</a>'
        . '</div>';
}

/** The bars under two or more - rotateDeals() in app.js; '' for one. */
function hero_deal_pips(array $picks): string
{
    $count = count($picks);
    if ($count < 2) {
        return '';
    }
    $html = '';
    foreach (array_values($picks) as $i => $p) {
        $html .= '<button type="button" class="deals__pip' . ($i === 0 ? ' is-on" aria-current="true"' : '"')
            . ' data-deals-go="' . $i . '" aria-label="'
            . js_esc(fa_digits((string) ($i + 1)) . ' از ' . fa_digits((string) $count) . '، ' . (string) ($p['nameFa'] ?? ''))
            . '"></button>';
    }
    return $html;
}
