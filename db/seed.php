<?php
/* ===========================================================================
   SUPPEX — Demo catalogue import
   ---------------------------------------------------------------------------
   Loads db/catalog-seed.json (generated from assets/js/catalog.js by
   db/dump-catalog.js) into the database, so the shop opens with a populated
   catalogue instead of an empty grid that gives the owner nothing to look at.

   Called by db/setup.php. Safe to run more than once: rows are matched on
   slug and updated rather than duplicated.
   =========================================================================== */

declare(strict_types=1);

/** @return array{categories:int,products:int} */
function suppex_seed(): array
{
    $file = __DIR__ . '/catalog-seed.json';
    if (!is_file($file)) {
        throw new RuntimeException('db/catalog-seed.json پیدا نشد.');
    }

    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        throw new RuntimeException('db/catalog-seed.json خوانده نشد.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        /* --- Categories ---------------------------------------------------- */
        $categoryIds = [];
        $sort = 0;

        foreach ($data['categories'] ?? [] as $c) {
            $slug = (string) $c['id'];
            $existing = db_value('SELECT id FROM categories WHERE slug = ?', [$slug]);

            $params = [
                (string) $c['name'],
                (string) ($c['nameLatin'] ?? ''),
                (string) ($c['blurb'] ?? ''),
                (string) ($c['image'] ?? ''),
                $sort++,
            ];

            if ($existing !== null) {
                db_query('UPDATE categories SET name_fa = ?, name_latin = ?, blurb = ?,
                                 image = ?, sort_order = ? WHERE id = ?',
                    array_merge($params, [(int) $existing]));
                $categoryIds[$slug] = (int) $existing;
            } else {
                $categoryIds[$slug] = db_insert(
                    'INSERT INTO categories (name_fa, name_latin, blurb, image, sort_order, slug)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    array_merge($params, [$slug])
                );
            }
        }

        /* --- Products ------------------------------------------------------- */
        $productCount = 0;
        $sort = 0;

        foreach ($data['products'] ?? [] as $p) {
            $slug = (string) $p['slug'];

            $fields = [
                'name_fa'           => (string) $p['nameFa'],
                'name_en'           => (string) ($p['name'] ?? ''),
                'brand'             => (string) ($p['brand'] ?? ''),
                'category_id'       => $categoryIds[$p['category'] ?? ''] ?? null,
                'price'             => (int) $p['price'],
                'compare_at'        => isset($p['compareAt']) && $p['compareAt'] ? (int) $p['compareAt'] : null,
                'in_stock'          => !empty($p['inStock']) ? 1 : 0,
                'badges'            => implode(',', (array) ($p['badges'] ?? [])),
                'image'             => (string) ($p['image'] ?? ''),
                'variant_label'     => (string) ($p['variantLabel'] ?? ''),
                'short'             => (string) ($p['short'] ?? ''),
                'description'       => (string) ($p['description'] ?? ''),
                'features'          => json_encode_pretty((array) ($p['features'] ?? [])),
                'ingredients'       => (string) ($p['ingredients'] ?? ''),
                'usage_text'        => (string) ($p['usage'] ?? ''),
                'nutrition_serving' => (string) ($p['nutrition']['servingSize'] ?? ''),
                'nutrition_rows'    => json_encode_pretty((array) ($p['nutrition']['rows'] ?? [])),
                'sort_order'        => $sort++,
                'is_active'         => 1,
            ];

            $existing = db_value('SELECT id FROM products WHERE slug = ?', [$slug]);
            $columns  = array_keys($fields);

            if ($existing !== null) {
                $productId = (int) $existing;
                db_query(
                    'UPDATE products SET ' . implode(', ', array_map(static fn($c) => $c . ' = ?', $columns))
                        . ' WHERE id = ?',
                    array_merge(array_values($fields), [$productId])
                );
            } else {
                $productId = db_insert(
                    'INSERT INTO products (' . implode(', ', $columns) . ', slug) VALUES ('
                        . implode(', ', array_fill(0, count($columns), '?')) . ', ?)',
                    array_merge(array_values($fields), [$slug])
                );
            }

            /* Child rows are replaced rather than merged — re-running the seed
               should reproduce the source file exactly, not accumulate. */
            db_query('DELETE FROM product_images  WHERE product_id = ?', [$productId]);
            db_query('DELETE FROM product_flavors WHERE product_id = ?', [$productId]);
            db_query('DELETE FROM product_sizes   WHERE product_id = ?', [$productId]);

            foreach (array_values((array) ($p['gallery'] ?? [])) as $i => $path) {
                db_query('INSERT INTO product_images (product_id, path, sort_order) VALUES (?, ?, ?)',
                    [$productId, (string) $path, $i]);
            }

            foreach (array_values((array) ($p['flavors'] ?? [])) as $i => $f) {
                db_query('INSERT INTO product_flavors (product_id, ext_id, name, sort_order)
                          VALUES (?, ?, ?, ?)',
                    [$productId, (string) $f['id'], (string) $f['name'], $i]);
            }

            foreach (array_values((array) ($p['sizes'] ?? [])) as $i => $s) {
                db_query('INSERT INTO product_sizes
                            (product_id, ext_id, label, servings, price, compare_at, sort_order)
                          VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$productId, (string) $s['id'], (string) $s['label'],
                     isset($s['servings']) ? (int) $s['servings'] : null,
                     (int) $s['price'],
                     isset($s['compareAt']) && $s['compareAt'] ? (int) $s['compareAt'] : null,
                     $i]);
            }

            $productCount++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['categories' => count($categoryIds), 'products' => $productCount];
}
