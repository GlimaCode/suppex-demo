<?php
/* ===========================================================================
   SUPPEX — Catalogue import
   ---------------------------------------------------------------------------
   Reads the cost sheet the shop fills in and turns it into products, sizes and
   dirham prices.

   This exists because the alternative is the real launch delay. Fifty products,
   each with two or three sizes, a dirham cost, a profit figure and a
   description, typed one at a time into a web form, is most of a working day
   and every field is a chance to fat-finger a price. The seller already keeps
   this data in a spreadsheet; the job is to read it, not to re-key it.

   Two rules shape the whole file:

   NOTHING IS WRITTEN UNTIL THE WHOLE FILE VALIDATES. A partial import is worse
   than a failed one — half a catalogue live at unknown prices, with no clean
   way back. Parsing and writing are therefore separate passes, and the writing
   pass runs in a transaction.

   EVERY ROW IS PREVIEWED BEFORE ANY ROW IS WRITTEN. The same reasoning as the
   pricing page: a mistyped column is obvious in a table and invisible in a
   success message.
   =========================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/pricing.php';

/** The columns the sheet is expected to carry, in order. */
const IMPORT_COLUMNS = [
    'name_fa'      => 'نام فارسی',
    'name_en'      => 'نام انگلیسی',
    'brand'        => 'برند',
    'category'     => 'دسته',
    'size_label'   => 'اندازه',
    'cost_aed'     => 'قیمت خرید (درهم)',
    'profit_toman' => 'سود (تومان)',
    'price_toman'  => 'قیمت فروش (تومان)',
    'in_stock'     => 'موجود',
    'short'        => 'توضیح کوتاه',
];

/**
 * Read a CSV into rows keyed by our column names.
 *
 * Excel on a Persian Windows writes CSV in UTF-8 *with* a BOM, and often with
 * semicolons rather than commas because the list separator follows the locale.
 * Both are handled here rather than being explained to the person exporting.
 *
 * @return array{rows:array<int,array<string,string>>,error:?string}
 */
function import_read_csv(string $path): array
{
    $fh = @fopen($path, 'r');
    if ($fh === false) {
        return ['rows' => [], 'error' => 'فایل خوانده نشد.'];
    }

    /* Seek PAST the BOM rather than stripping it from the parsed field.

       It has to happen at the stream, before fgetcsv sees the line. Excel
       quotes any header containing a space, so the first line begins
       BOM + '"' — and fgetcsv, finding those three bytes where it expects the
       enclosure, decides the field is not quoted at all and hands back a value
       with a literal quote character welded to the front. Stripping the BOM
       from the result afterwards leaves that quote behind, and the column
       matches nothing. */
    $bomOffset = fread($fh, 3) === "\xEF\xBB\xBF" ? 3 : 0;
    fseek($fh, $bomOffset);

    $first = fgets($fh);
    if ($first === false) {
        fclose($fh);
        return ['rows' => [], 'error' => 'فایل خالی است.'];
    }

    /* Whichever separator appears more often in the header line is the one in
       use. Counting beats guessing from the locale, which we cannot see. */
    $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

    fseek($fh, $bomOffset);
    $header = fgetcsv($fh, 0, $delimiter);
    if ($header === false) {
        fclose($fh);
        return ['rows' => [], 'error' => 'سطر عنوان خوانده نشد.'];
    }

    /* Map the sheet's header labels onto our keys. Matching on the Persian
       label means the seller can reorder or add columns without breaking it. */
    $map = [];
    foreach ($header as $i => $label) {
        $label = trim((string) $label);
        foreach (IMPORT_COLUMNS as $key => $expected) {
            if ($label === $expected || $label === $key) {
                $map[$i] = $key;
            }
        }
    }

    if (!in_array('name_fa', $map, true)) {
        fclose($fh);
        return ['rows' => [], 'error' =>
            'ستون «' . IMPORT_COLUMNS['name_fa'] . '» در فایل پیدا نشد. ' .
            'از همان فایل نمونه استفاده کنید و عنوان ستون‌ها را عوض نکنید.'];
    }

    $rows = [];
    $line = 1;
    while (($data = fgetcsv($fh, 0, $delimiter)) !== false) {
        $line++;
        /* fgetcsv yields [null] for a blank line. */
        if ($data === [null] || count(array_filter($data, static fn($v) => trim((string) $v) !== '')) === 0) {
            continue;
        }
        $row = ['_line' => $line];
        foreach ($map as $i => $key) {
            $row[$key] = trim((string) ($data[$i] ?? ''));
        }
        $rows[] = $row;
    }
    fclose($fh);

    return ['rows' => $rows, 'error' => null];
}

/**
 * Validate and shape rows into products.
 *
 * Rows sharing a name become one product with several sizes, which is how the
 * seller naturally writes a cost sheet — one line per thing they buy.
 *
 * @return array{products:array,errors:array<int,string>,warnings:array<int,string>}
 */
function import_plan(array $rows): array
{
    $products = [];
    $errors   = [];
    $warnings = [];

    $rate = pricing_rate();

    foreach ($rows as $row) {
        $line   = (int) $row['_line'];
        $nameFa = clean_text($row['name_fa'] ?? '', 180);

        if ($nameFa === '') {
            $errors[] = 'سطر ' . $line . ': نام فارسی خالی است.';
            continue;
        }

        $costAedRaw = trim(to_latin_digits((string) ($row['cost_aed'] ?? '')));
        $costAed    = $costAedRaw === '' ? null : (float) str_replace(',', '', $costAedRaw);
        $profit     = parse_money($row['profit_toman'] ?? '');
        $price      = parse_money($row['price_toman'] ?? '');

        if ($costAed !== null && $costAed <= 0) {
            $errors[] = 'سطر ' . $line . ': قیمت خرید باید بزرگ‌تر از صفر باشد.';
            continue;
        }

        /* A cost typed in toman is the mistake that actually happens, and it is
           catchable here: a dirham price for a supplement is tens, not
           millions. Refused rather than warned about, because the resulting
           shelf price would be absurd and someone would have to notice. */
        if ($costAed !== null && $costAed > 20000) {
            $errors[] = 'سطر ' . $line . ': قیمت خرید ' . number_format($costAed) .
                ' درهم غیرواقعی است — احتمالاً به تومان وارد شده.';
            continue;
        }

        if ($costAed === null && $price <= 0) {
            $errors[] = 'سطر ' . $line . ': یا قیمت خرید به درهم لازم است، یا قیمت فروش به تومان.';
            continue;
        }

        if ($costAed !== null && $profit <= 0) {
            $errors[] = 'سطر ' . $line . ': برای قیمت‌گذاری درهمی، مقدار سود لازم است.';
            continue;
        }

        /* Dirham-priced rows need a rate before a shelf price can exist. The
           import still succeeds — the pricing page fills them in — but say so,
           because otherwise the products appear with a price of zero and it
           looks like the import lost them. */
        if ($costAed !== null && $rate === null) {
            $warnings[] = 'سطر ' . $line . ': نرخ درهم هنوز ثبت نشده، پس قیمت این قلم بعد از ' .
                'وارد کردن نرخ در صفحه قیمت‌گذاری محاسبه می‌شود.';
        }

        $key = mb_strtolower($nameFa);
        if (!isset($products[$key])) {
            $products[$key] = [
                'name_fa'  => $nameFa,
                'name_en'  => clean_text($row['name_en'] ?? '', 180),
                'brand'    => clean_text($row['brand'] ?? '', 120),
                'category' => clean_text($row['category'] ?? '', 120),
                'short'    => clean_text($row['short'] ?? '', 500),
                'in_stock' => import_truthy($row['in_stock'] ?? '1'),
                'sizes'    => [],
                'lines'    => [],
            ];
        }

        $products[$key]['lines'][] = $line;

        $sizeLabel = clean_text($row['size_label'] ?? '', 120);
        $computed  = ($costAed !== null && $rate !== null)
            ? pricing_compute(['cost_aed' => $costAed, 'profit_toman' => $profit], $rate)
            : null;

        $products[$key]['sizes'][] = [
            'label'        => $sizeLabel,
            'cost_aed'     => $costAed,
            'profit_toman' => $profit > 0 ? $profit : null,
            'price'        => $computed !== null ? $computed['price'] : $price,
            'line'         => $line,
        ];
    }

    /* A product with exactly one unnamed size is a single-price product, not a
       product with one variant. Collapsing it here keeps the storefront from
       showing a pointless size selector with one option in it. */
    foreach ($products as $k => $p) {
        if (count($p['sizes']) === 1 && $p['sizes'][0]['label'] === '') {
            $products[$k]['single'] = $p['sizes'][0];
            $products[$k]['sizes']  = [];
        } else {
            foreach ($p['sizes'] as $i => $s) {
                if ($s['label'] === '') {
                    $errors[] = 'سطر ' . $s['line'] . ': این محصول چند اندازه دارد، ' .
                        'پس ستون اندازه نمی‌تواند خالی باشد.';
                    unset($products[$k]['sizes'][$i]);
                }
            }
            $products[$k]['sizes'] = array_values($products[$k]['sizes']);
        }
    }

    return ['products' => array_values($products), 'errors' => $errors, 'warnings' => $warnings];
}

/** Persian and English ways of writing yes. */
function import_truthy(string $v): bool
{
    $v = mb_strtolower(trim(to_latin_digits($v)));
    return !in_array($v, ['0', 'no', 'false', 'خیر', 'ناموجود', 'نه', ''], true);
}

/**
 * Write the planned products.
 *
 * All or nothing: a half-imported catalogue is live at unknown prices with no
 * clean way back.
 *
 * @return array{created:int,updated:int,sizes:int}
 */
function import_apply(array $products, array $admin): array
{
    $created = 0;
    $updated = 0;
    $sizes   = 0;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($products as $p) {
            $categoryId = $p['category'] === '' ? null : import_category_id($p['category']);

            $single = $p['single'] ?? null;
            $fields = [
                'name_fa'      => $p['name_fa'],
                'name_en'      => $p['name_en'],
                'brand'        => $p['brand'],
                'category_id'  => $categoryId,
                'short'        => $p['short'],
                'in_stock'     => $p['in_stock'] ? 1 : 0,
                'is_active'    => 1,
                'price'        => $single !== null ? (int) $single['price'] : 0,
                'cost_aed'     => $single !== null ? $single['cost_aed'] : null,
                'profit_toman' => $single !== null ? $single['profit_toman'] : null,
                'price_mode'   => ($single !== null && $single['cost_aed'] !== null) ? 'aed' : 'manual',
            ];

            /* Matched on the Persian name, because that is what the seller
               retypes when they send an updated sheet — a slug would change
               with every edit to the name and re-import as a new product. */
            $existing = db_value('SELECT id FROM products WHERE name_fa = ?', [$p['name_fa']]);

            if ($existing !== null) {
                $id = (int) $existing;
                $set = implode(', ', array_map(static fn($c) => $c . ' = ?', array_keys($fields)));
                db_query('UPDATE products SET ' . $set . ' WHERE id = ?',
                    array_merge(array_values($fields), [$id]));
                $updated++;
            } else {
                $fields['slug'] = unique_slug(
                    slugify($p['name_en'] !== '' ? $p['name_en'] : $p['name_fa']), 'products');
                $cols = array_keys($fields);
                $id = db_insert(
                    'INSERT INTO products (' . implode(', ', $cols) . ') VALUES ('
                    . implode(', ', array_fill(0, count($cols), '?')) . ')',
                    array_values($fields));
                $created++;
            }

            /* Sizes are replaced wholesale — the sheet is the source of truth,
               and a size dropped from it should disappear rather than linger. */
            db_query('DELETE FROM product_sizes WHERE product_id = ?', [$id]);
            foreach ($p['sizes'] as $i => $s) {
                db_query(
                    'INSERT INTO product_sizes
                        (product_id, ext_id, label, price, cost_aed, profit_toman, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$id, slugify($s['label']), $s['label'], (int) $s['price'],
                     $s['cost_aed'], $s['profit_toman'], $i]
                );
                $sizes++;
            }

            if ($p['sizes']) {
                /* The card price tracks the cheapest size, same rule the
                   pricing page uses. */
                $cheapest = db_value(
                    'SELECT MIN(price) FROM product_sizes WHERE product_id = ? AND price > 0', [$id]);
                if ($cheapest !== null) {
                    db_query('UPDATE products SET price = ?, price_mode = "aed" WHERE id = ?',
                        [(int) $cheapest, $id]);
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[suppex] import failed: ' . $e->getMessage());
        throw $e;
    }

    return ['created' => $created, 'updated' => $updated, 'sizes' => $sizes];
}

/** Find a category by name, creating it if the sheet names a new one. */
function import_category_id(string $name): int
{
    $id = db_value('SELECT id FROM categories WHERE name_fa = ?', [$name]);
    if ($id !== null) {
        return (int) $id;
    }
    return db_insert(
        'INSERT INTO categories (slug, name_fa, sort_order, is_active) VALUES (?, ?, 99, 1)',
        [unique_slug(slugify($name), 'categories'), $name]
    );
}

/** The template the seller fills in. */
function import_template_csv(): string
{
    $rows = [
        array_values(IMPORT_COLUMNS),
        ['وی پروتئین اورجینال', 'Whey Original', 'SUPPEX', 'پروتئین', '900 گرم', '40', '1500000', '', 'بله', 'وی کنسانتره و ایزوله میکروفیلتر شده'],
        ['وی پروتئین اورجینال', 'Whey Original', 'SUPPEX', 'پروتئین', '2270 گرم', '95', '2000000', '', 'بله', ''],
        ['کراتین مونوهیدرات', 'Creatine Monohydrate', 'SUPPEX', 'کراتین', '', '18', '900000', '', 'بله', 'کراتین میکرونایز خالص'],
    ];

    $out = fopen('php://temp', 'r+');
    /* A BOM, so Excel on a Persian Windows opens it as UTF-8 instead of
       rendering every product name as mojibake. */
    fwrite($out, "\xEF\xBB\xBF");
    foreach ($rows as $r) {
        fputcsv($out, $r);
    }
    rewind($out);
    $csv = stream_get_contents($out) ?: '';
    fclose($out);
    return $csv;
}
