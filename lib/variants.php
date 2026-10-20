<?php

declare(strict_types=1);

/* ===========================================================================
   Attributes and variations
   ---------------------------------------------------------------------------
   One section for every option a product has: «اندازه», «طعم», and anything
   else the owner adds. A variant is one real thing a shopper can buy — one
   point on every axis — with its own price, its own count and its own picture.

   This sits ALONGSIDE product_sizes and product_flavors rather than replacing
   them. A product that has no variants is priced exactly as it was yesterday;
   a product that has them is priced from them. That is what lets a shop which
   is taking orders today move one product at a time instead of all of them in
   one irreversible step.

   The bridge between the two models is the variant's ext_id: the size's ext_id
   and the flavour's ext_id joined by a pipe, in attribute order. The cart
   already stores exactly that pair, so a basket filled before a product was
   converted still resolves to the right variant afterwards.

   The cost of this model is multiplicative and worth stating where somebody
   will read it: four sizes and thirteen flavours is fifty-two rows to price
   and to keep current, where the delta model needed seventeen. Everything
   below is written to keep that bearable — combinations are generated rather
   than typed, and a generated row inherits the price the delta model would
   have produced, so converting a product changes no price on the site.
   =========================================================================== */

/** The two axes the legacy model already had, in the order they are shown. */
const VARIANT_SIZE_ATTR   = 'size';
const VARIANT_FLAVOR_ATTR = 'flavor';

/** Additive rollout: the shop keeps working between uploading code and running
    the migration. Only the new checkbox waits for its column. */
function variants_inquiry_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        $ready = db_value("SELECT 1 FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_variants'
                             AND COLUMN_NAME = 'requires_inquiry'") !== null;
    }
    return $ready;
}

function variant_requires_inquiry(array $variant): bool
{
    return !empty($variant['requires_inquiry']);
}

/** Joins the per-axis ext_ids into one variant key. */
function variant_key(array $extIds): string
{
    return implode('|', $extIds);
}

/**
 * Every attribute of a product, each with its values.
 *
 * @return list<array{id:int,ext_id:string,name:string,values:list<array>}>
 */
function variants_attributes(int $productId): array
{
    $attrs = db_all(
        'SELECT id, ext_id, name, sort_order FROM product_attributes
          WHERE product_id = ? ORDER BY sort_order, id',
        [$productId]
    );
    foreach ($attrs as &$a) {
        $a['id']     = (int) $a['id'];
        $a['values'] = array_map(
            static fn(array $v) => [
                'id'     => (int) $v['id'],
                'ext_id' => (string) $v['ext_id'],
                'label'  => (string) $v['label'],
                'image'  => (string) $v['image'],
            ],
            db_all('SELECT id, ext_id, label, image FROM product_attribute_values
                     WHERE attribute_id = ? ORDER BY sort_order, id', [$a['id']])
        );
    }
    unset($a);
    return $attrs;
}

/** Does this product price itself from variants yet? */
function variants_has(int $productId): bool
{
    return (int) db_value('SELECT COUNT(*) FROM product_variants WHERE product_id = ?',
                          [$productId]) > 0;
}

/**
 * Every variant, with the value ids it is made of.
 *
 * @return list<array>
 */
function variants_list(int $productId): array
{
    $rows = db_all('SELECT * FROM product_variants WHERE product_id = ?
                     ORDER BY sort_order, id', [$productId]);
    if (!$rows) {
        return [];
    }

    /* One query for every variant's values rather than one per variant: a
       product with fifty-two combinations would otherwise be fifty-three
       round trips to draw one page. */
    $ids = array_map(static fn($r) => (int) $r['id'], $rows);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $map = [];
    foreach (db_all(
        'SELECT vv.variant_id, v.id, v.ext_id, v.label, v.image, a.ext_id AS attr_ext,
                a.name AS attr_name, a.sort_order AS attr_sort
           FROM product_variant_values vv
           JOIN product_attribute_values v ON v.id = vv.value_id
           JOIN product_attributes a ON a.id = v.attribute_id
          WHERE vv.variant_id IN (' . $in . ')
          ORDER BY a.sort_order, a.id', $ids) as $v) {
        $map[(int) $v['variant_id']][] = $v;
    }

    foreach ($rows as &$r) {
        $r['id']     = (int) $r['id'];
        $r['values'] = $map[$r['id']] ?? [];
    }
    unset($r);
    return $rows;
}

/**
 * The variant a set of choices names, or null.
 *
 * @param array<string,string> $choice attribute ext_id => value ext_id
 */
function variants_resolve(int $productId, array $choice): ?array
{
    if (!$choice) {
        return null;
    }
    /* Built from the attribute order rather than from the order the caller
       happened to pass, so {flavor:…, size:…} and {size:…, flavor:…} are the
       same basket line. */
    $parts = [];
    foreach (variants_attributes($productId) as $a) {
        if (!isset($choice[$a['ext_id']])) {
            return null;
        }
        $parts[] = (string) $choice[$a['ext_id']];
    }
    $row = db_one('SELECT * FROM product_variants WHERE product_id = ? AND ext_id = ?',
                  [$productId, variant_key($parts)]);
    if ($row === null) {
        return null;
    }
    $row['id'] = (int) $row['id'];
    return $row;
}

/** Create an attribute if it is not there, and return its id. */
function variants_attribute_upsert(int $productId, string $extId, string $name,
                                   int $sort): int
{
    $id = db_value('SELECT id FROM product_attributes WHERE product_id = ? AND ext_id = ?',
                   [$productId, $extId]);
    if ($id !== null) {
        db_query('UPDATE product_attributes SET name = ?, sort_order = ? WHERE id = ?',
                 [$name, $sort, (int) $id]);
        return (int) $id;
    }
    return db_insert(
        'INSERT INTO product_attributes (product_id, ext_id, name, sort_order)
         VALUES (?, ?, ?, ?)',
        [$productId, $extId, $name, $sort]
    );
}

/** Same for one value on an axis. */
function variants_value_upsert(int $attributeId, string $extId, string $label,
                               string $image, int $sort): int
{
    $id = db_value('SELECT id FROM product_attribute_values
                     WHERE attribute_id = ? AND ext_id = ?', [$attributeId, $extId]);
    if ($id !== null) {
        /* The picture is only ever written when there is one to write: a value
           edited through a form that had no file chosen must keep what it has,
           the same rule the flavour rows follow. */
        if ($image !== '') {
            db_query('UPDATE product_attribute_values SET label = ?, image = ?, sort_order = ?
                       WHERE id = ?', [$label, $image, $sort, (int) $id]);
        } else {
            db_query('UPDATE product_attribute_values SET label = ?, sort_order = ?
                       WHERE id = ?', [$label, $sort, (int) $id]);
        }
        return (int) $id;
    }
    return db_insert(
        'INSERT INTO product_attribute_values (attribute_id, ext_id, label, image, sort_order)
         VALUES (?, ?, ?, ?, ?)',
        [$attributeId, $extId, $label, $image, $sort]
    );
}

/**
 * Turn a product's existing sizes and flavours into attributes and variants.
 *
 * Priced so that converting changes nothing on the site: every generated
 * combination gets exactly the number the delta model was already charging —
 * the size's price plus the flavour's premium. The dirham figures add the same
 * way, so a converted product still repricess itself when the rate moves.
 *
 * Idempotent. Running it twice adds no rows and moves no price.
 *
 * @return int how many variants exist afterwards
 */
function variants_adopt(int $productId): int
{
    $product = db_one('SELECT * FROM products WHERE id = ?', [$productId]);
    if ($product === null) {
        return 0;
    }

    $sizes   = db_all('SELECT * FROM product_sizes WHERE product_id = ? ORDER BY sort_order, id',
                      [$productId]);
    $flavors = db_all('SELECT * FROM product_flavors WHERE product_id = ? ORDER BY sort_order, id',
                      [$productId]);

    /* Nothing to convert. A product with neither is a single thing on a shelf
       and does not need a variant to say so. */
    if (!$sizes && !$flavors) {
        return 0;
    }

    $axes = [];

    if ($sizes) {
        $aid = variants_attribute_upsert($productId, VARIANT_SIZE_ATTR, 'اندازه', 0);
        $vals = [];
        foreach ($sizes as $i => $s) {
            $vals[(string) $s['ext_id']] = [
                'value_id' => variants_value_upsert($aid, (string) $s['ext_id'],
                                                    (string) $s['label'], '', $i),
                'row'      => $s,
            ];
        }
        $axes[VARIANT_SIZE_ATTR] = $vals;
    }

    if ($flavors) {
        $aid = variants_attribute_upsert($productId, VARIANT_FLAVOR_ATTR, 'طعم',
                                         $sizes ? 1 : 0);
        $vals = [];
        foreach ($flavors as $i => $f) {
            $vals[(string) $f['ext_id']] = [
                'value_id' => variants_value_upsert($aid, (string) $f['ext_id'],
                                                    (string) $f['name'],
                                                    (string) ($f['image'] ?? ''), $i),
                'row'      => $f,
            ];
        }
        $axes[VARIANT_FLAVOR_ATTR] = $vals;
    }

    /* Points that no longer have a row behind them.

       The upserts above add and update; without this they never remove, so a
       flavour deleted in the panel stayed on the axis and its combinations
       stayed for sale. The variants themselves are left to variants_prune(),
       which is the one place that decides a combination is incomplete.

       Scoped to size and flavour deliberately: a custom attribute's values
       are typed into the form and have no legacy row to be missing from, so
       comparing them here would delete every one of them. */
    foreach ([VARIANT_SIZE_ATTR => $sizes, VARIANT_FLAVOR_ATTR => $flavors] as $attrExt => $rows) {
        $aid = db_value('SELECT id FROM product_attributes WHERE product_id = ? AND ext_id = ?',
                        [$productId, $attrExt]);
        if ($aid === null) {
            continue;
        }
        $live = [];
        foreach ($rows as $row) {
            $live[(string) $row['ext_id']] = true;
        }
        foreach (db_all('SELECT id, ext_id FROM product_attribute_values WHERE attribute_id = ?',
                        [(int) $aid]) as $val) {
            if (!isset($live[(string) $val['ext_id']])) {
                db_query('DELETE FROM product_variant_values WHERE value_id = ?', [(int) $val['id']]);
                db_query('DELETE FROM product_attribute_values WHERE id = ?', [(int) $val['id']]);
            }
        }
    }

    /* The cartesian product, in attribute order. */
    $combos = [[]];
    foreach ($axes as $attrExt => $vals) {
        $next = [];
        foreach ($combos as $combo) {
            foreach ($vals as $valExt => $val) {
                $c = $combo;
                $c[$attrExt] = ['ext' => $valExt] + $val;
                $next[] = $c;
            }
        }
        $combos = $next;
    }

    $sort = 0;
    foreach ($combos as $combo) {
        $key = variant_key(array_map(static fn($c) => $c['ext'], $combo));

        $size   = $combo[VARIANT_SIZE_ATTR]['row']   ?? null;
        $flavor = $combo[VARIANT_FLAVOR_ATTR]['row'] ?? null;

        /* The delta model's arithmetic, written out once. A size carries the
           price; a flavour adds to it; with no sizes the product's own price
           is the base. */
        $base  = $size !== null ? (int) $size['price'] : (int) $product['price'];
        $price = $base + (int) ($flavor['price_delta_toman'] ?? 0);

        $costAed = null;
        if ($size !== null && $size['cost_aed'] !== null) {
            $costAed = (float) $size['cost_aed'];
        } elseif ($product['cost_aed'] !== null) {
            $costAed = (float) $product['cost_aed'];
        }
        if ($costAed !== null && ($flavor['cost_delta_aed'] ?? null) !== null) {
            $costAed += (float) $flavor['cost_delta_aed'];
        }

        $existing = db_value('SELECT id FROM product_variants WHERE product_id = ? AND ext_id = ?',
                             [$productId, $key]);
        if ($existing !== null) {
            /* Already converted. Left exactly as it is - the owner may have
               priced it by hand since, and a second run must not undo that. */
            $sort++;
            continue;
        }

        /* The toman cost, for a product bought inside Iran rather than in
           dirhams. Not derived from the dirham figure and not added to by the
           flavour: cost_delta_aed is a DIRHAM premium, and a manual product
           has no toman equivalent of it. */
        $costToman = $size !== null && $size['cost_toman'] !== null
            ? (int) $size['cost_toman']
            : ($product['cost_toman'] === null ? null : (int) $product['cost_toman']);

        $vid = db_insert(
            'INSERT INTO product_variants
                (product_id, ext_id, price, compare_at, cost_aed, cost_toman,
                 profit_toman, profit_percent, commission_percent, price_applied_rate,
                 stock_qty, image, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $productId, $key, $price,
                $size !== null ? $size['compare_at'] : $product['compare_at'],
                $costAed, $costToman,
                $size !== null ? $size['profit_toman'] : $product['profit_toman'],
                $size !== null ? $size['profit_percent'] : $product['profit_percent'],
                $size !== null ? $size['commission_percent'] : $product['commission_percent'],
                $size !== null ? $size['price_applied_rate'] : $product['price_applied_rate'],
                /* Only the size ever counted stock; a flavour never did. */
                $size !== null ? $size['stock_qty'] : $product['stock_qty'],
                /* The picture belongs to the flavour: the same tub in two sizes
                   is one photograph. */
                (string) ($flavor['image'] ?? ''),
                $sort++,
            ]
        );

        foreach ($combo as $c) {
            db_query('INSERT INTO product_variant_values (variant_id, value_id) VALUES (?, ?)',
                     [$vid, $c['value_id']]);
        }
    }

    return (int) db_value('SELECT COUNT(*) FROM product_variants WHERE product_id = ?',
                          [$productId]);
}

/**
 * Add any combination the current attributes imply but that has no variant.
 *
 * What the panel calls after somebody adds a flavour: the twelve rows that
 * already have prices keep them, and only the four new ones appear.
 *
 * @return int how many were created
 */
function variants_generate(int $productId): int
{
    $attrs = variants_attributes($productId);
    if (!$attrs) {
        return 0;
    }

    $combos = [[]];
    foreach ($attrs as $a) {
        if (!$a['values']) {
            /* An axis with no points would multiply everything by zero and
               wipe the grid. An attribute half-typed is not a reason to
               delete a product's prices. */
            return 0;
        }
        $next = [];
        foreach ($combos as $combo) {
            foreach ($a['values'] as $v) {
                $next[] = array_merge($combo, [$v]);
            }
        }
        $combos = $next;
    }

    $made = 0;
    $sort = (int) db_value('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_variants
                             WHERE product_id = ?', [$productId]);

    foreach ($combos as $combo) {
        $key = variant_key(array_map(static fn($v) => $v['ext_id'], $combo));
        if (db_value('SELECT id FROM product_variants WHERE product_id = ? AND ext_id = ?',
                     [$productId, $key]) !== null) {
            continue;
        }
        /* A new combination starts at zero rather than at a guess. The
           readiness page already refuses to launch with an unpriced active
           product, so a zero is loud where an invented price would be quiet. */
        $vid = db_insert(
            'INSERT INTO product_variants (product_id, ext_id, price, image, sort_order)
             VALUES (?, ?, 0, ?, ?)',
            [$productId, $key,
             /* Inherit the picture from whichever value has one - almost always
                the flavour. */
             (string) (array_values(array_filter(array_map(
                 static fn($v) => $v['image'], $combo)))[0] ?? ''),
             $sort++]
        );
        foreach ($combo as $v) {
            db_query('INSERT INTO product_variant_values (variant_id, value_id) VALUES (?, ?)',
                     [$vid, $v['id']]);
        }
        $made++;
    }

    return $made;
}

/**
 * Drop variants whose combination no longer exists.
 *
 * Called after a value is deleted in the panel. A variant with a dangling
 * value would still be resolvable by a stale cart, which is how somebody ends
 * up buying a flavour the shop stopped stocking.
 *
 * @return int how many were removed
 */
function variants_prune(int $productId): int
{
    /* Only axes that actually have points on them.

       Counting every attribute meant that naming a new one - which is the
       first half of adding it, before its options are typed - made every
       existing variant one value short of the total, and this deleted the
       lot. variants_generate() already refuses to build against an empty
       axis; refusing to prune against one is the same rule read backwards. */
    $attrCount = (int) db_value(
        'SELECT COUNT(*) FROM product_attributes a
          WHERE a.product_id = ?
            AND EXISTS (SELECT 1 FROM product_attribute_values v
                         WHERE v.attribute_id = a.id)',
        [$productId]);
    if ($attrCount === 0) {
        return 0;
    }

    $gone = db_all(
        'SELECT v.id FROM product_variants v
          WHERE v.product_id = ?
            AND (SELECT COUNT(*) FROM product_variant_values vv
                  JOIN product_attribute_values av ON av.id = vv.value_id
                 WHERE vv.variant_id = v.id) <> ?',
        [$productId, $attrCount]
    );
    foreach ($gone as $g) {
        db_query('DELETE FROM product_variant_values WHERE variant_id = ?', [(int) $g['id']]);
        db_query('DELETE FROM product_variants WHERE id = ?', [(int) $g['id']]);
    }
    return count($gone);
}
