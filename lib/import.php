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
    'promo_toman'  => 'تخفیف (تومان)',
    'commission'   => 'درصد همکاری',
    'in_stock'     => 'موجود',
    'flavors'      => 'طعم‌ها',
    'servings'     => 'تعداد وعده',
    'short'        => 'توضیح کوتاه',
    'description'  => 'توضیح کامل',
];

/* Columns a row may leave empty. Everything else is either required or has a
   safe default; this list exists so the preview can say which blanks were
   deliberate rather than making the operator diff two spreadsheets. */
const IMPORT_OPTIONAL = [
    'name_en', 'brand', 'category', 'size_label', 'price_toman', 'promo_toman',
    'commission', 'in_stock', 'flavors', 'servings', 'short', 'description',
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

        $commission = import_percent($row['commission'] ?? '');
        if ($commission === false) {
            $errors[] = 'سطر ' . $line . ': درصد همکاری باید عددی بین ۰ تا ۱۰۰ باشد.';
            continue;
        }

        $costAedRaw = trim(to_latin_digits((string) ($row['cost_aed'] ?? '')));
        $costAed    = $costAedRaw === '' ? null : (float) str_replace(',', '', $costAedRaw);
        $profit     = parse_money($row['profit_toman'] ?? '');
        $price      = parse_money($row['price_toman'] ?? '');
        $promo      = parse_money($row['promo_toman'] ?? '');
        $servings   = (int) to_latin_digits((string) ($row['servings'] ?? ''));

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

        /* pricing_compute() caps a promo at half the profit rather than
           refusing it, so an over-large discount would quietly become a
           smaller one. Say so here instead — a discount the operator typed
           and a discount the shop applied being different numbers is exactly
           the kind of thing nobody notices until a customer does. */
        if ($promo > 0 && $profit > 0 && $promo > $profit * PRICING_MAX_PROMO_SHARE) {
            $warnings[] = 'سطر ' . $line . ': تخفیف ' . number_format($promo) .
                ' بیش از نیمی از سود است و تا ' .
                number_format((int) floor($profit * PRICING_MAX_PROMO_SHARE)) . ' کاهش داده می‌شود.';
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
                'description' => clean_text($row['description'] ?? '', 4000),
                'flavors'  => import_flavors($row['flavors'] ?? ''),
                'in_stock' => import_truthy($row['in_stock'] ?? '1'),
                'sizes'    => [],
                'lines'    => [],
            ];
        }

        /* Description and flavours belong to the product, not the tub, and the
           seller writes them on whichever row they happen to be thinking about.
           Take the first non-empty value rather than letting a later blank row
           erase what an earlier one said. */
        if ($products[$key]['description'] === '') {
            $products[$key]['description'] = clean_text($row['description'] ?? '', 4000);
        }
        if (!$products[$key]['flavors']) {
            $products[$key]['flavors'] = import_flavors($row['flavors'] ?? '');
        }
        if ($products[$key]['short'] === '') {
            $products[$key]['short'] = clean_text($row['short'] ?? '', 500);
        }

        $products[$key]['lines'][] = $line;

        $sizeLabel = clean_text($row['size_label'] ?? '', 120);
        $computed  = ($costAed !== null && $rate !== null)
            ? pricing_compute([
                'cost_aed'     => $costAed,
                'profit_toman' => $profit,
                'promo_toman'  => $promo,
              ], $rate)
            : null;

        $products[$key]['sizes'][] = [
            'label'        => $sizeLabel,
            'cost_aed'     => $costAed,
            'profit_toman' => $profit > 0 ? $profit : null,
            'promo_toman'  => $promo > 0 ? $promo : null,
            'commission'   => $commission,
            'servings'     => $servings > 0 ? $servings : null,
            'price'        => $computed !== null ? $computed['price'] : $price,
            'compare_at'   => $computed !== null ? $computed['compare_at'] : null,
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

/**
 * A commission rate from a spreadsheet cell.
 *
 * Empty means "use the shop-wide rate", which is a real and common answer, so
 * it cannot be conflated with zero — a zero rate is a deliberate decision to
 * take nothing on a line, and the two must stay tellable apart.
 *
 * @return float|null|false  the rate, null for "inherit", false for nonsense
 */
function import_percent($raw)
{
    $v = trim(to_latin_digits((string) $raw));
    $v = str_replace(['%', '٪', ' '], '', $v);
    if ($v === '') {
        return null;
    }
    if (!is_numeric($v)) {
        return false;
    }
    $n = (float) $v;
    return ($n < 0 || $n > 100) ? false : round($n, 2);
}

/**
 * Flavours from one cell.
 *
 * Split on every separator a person might reach for. Someone filling fifty
 * rows will not be consistent about it, and refusing a slash where a comma was
 * expected teaches nothing useful.
 *
 * @return array<int,string>
 */
function import_flavors($raw): array
{
    $parts = preg_split("~[,،؛;/|\r\n]+~u", (string) $raw) ?: [];
    $out   = [];
    foreach ($parts as $p) {
        $p = clean_text($p, 120);
        if ($p !== '' && !in_array($p, $out, true)) {
            $out[] = $p;
        }
    }
    return array_slice($out, 0, 20);
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
    /* Commission changes are collected during the transaction and written to
       the log inside it, so a rolled-back import leaves no record of rates it
       did not actually set. */
    $rateLog = [];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($products as $p) {
            $categoryId = $p['category'] === '' ? null : import_category_id($p['category']);

            $single = $p['single'] ?? null;

            /* A product with sizes has no commission rate of its own unless
               every size agrees on one. Averaging them would invent a rate
               nobody chose, and picking the first would silently apply one
               tub's deal to another. Disagreement therefore leaves the parent
               NULL, and each size carries its own — which is exactly the
               order order_price_line() resolves them in. */
            $units = $p['sizes'] ?: ($single !== null ? [$single] : []);
            /* Keyed by a string, deliberately. array_unique() compares loosely,
               and under a loose comparison null equals 0.0 — which would fold
               "inherit the shop rate" and "take nothing on this line" into one
               value. Those are different decisions and must stay tellable
               apart all the way down. */
            $seen = [];
            foreach ($units as $u) {
                $seen[$u['commission'] === null ? 'inherit' : (string) $u['commission']]
                    = $u['commission'];
            }
            $parentRate = count($seen) === 1 ? reset($seen) : null;

            $fields = [
                'name_fa'      => $p['name_fa'],
                'name_en'      => $p['name_en'],
                'brand'        => $p['brand'],
                'category_id'  => $categoryId,
                'short'        => $p['short'],
                'description'  => $p['description'] !== '' ? $p['description'] : null,
                'in_stock'     => $p['in_stock'] ? 1 : 0,
                'is_active'    => 1,
                'price'        => $single !== null ? (int) $single['price'] : 0,
                'compare_at'   => $single !== null ? $single['compare_at'] : null,
                'cost_aed'     => $single !== null ? $single['cost_aed'] : null,
                'profit_toman' => $single !== null ? $single['profit_toman'] : null,
                'promo_toman'  => $single !== null ? $single['promo_toman'] : null,
                'commission_percent' => $parentRate,
                'price_mode'   => ($single !== null && $single['cost_aed'] !== null) ? 'aed' : 'manual',
            ];

            /* Matched on the Persian name, because that is what the seller
               retypes when they send an updated sheet — a slug would change
               with every edit to the name and re-import as a new product. */
            $existing = db_value('SELECT id FROM products WHERE name_fa = ?', [$p['name_fa']]);

            if ($existing !== null) {
                $id = (int) $existing;
                $was = db_value('SELECT commission_percent FROM products WHERE id = ?', [$id]);
                $was = $was === null ? null : (float) $was;
                if ($was !== $parentRate) {
                    $rateLog[] = ['product', $id, $p['name_fa'], $was, $parentRate];
                }
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
                if ($parentRate !== null) {
                    $rateLog[] = ['product', $id, $p['name_fa'], null, $parentRate];
                }
            }

            /* Flavours are replaced only when the sheet names some. A blank
               cell means "not my department today", not "delete the flavours
               someone added in the editor last week". */
            if ($p['flavors']) {
                db_query('DELETE FROM product_flavors WHERE product_id = ?', [$id]);
                foreach ($p['flavors'] as $i => $flavor) {
                    db_query(
                        'INSERT INTO product_flavors (product_id, ext_id, name, sort_order)
                         VALUES (?, ?, ?, ?)',
                        [$id, slugify($flavor) ?: 'f' . $i, $flavor, $i]
                    );
                }
            }

            /* Sizes are replaced wholesale — the sheet is the source of truth,
               and a size dropped from it should disappear rather than linger. */
            db_query('DELETE FROM product_sizes WHERE product_id = ?', [$id]);
            foreach ($p['sizes'] as $i => $s) {
                db_query(
                    'INSERT INTO product_sizes
                        (product_id, ext_id, label, servings, price, compare_at,
                         cost_aed, profit_toman, promo_toman, commission_percent, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$id, slugify($s['label']) ?: 's' . $i, $s['label'], $s['servings'],
                     (int) $s['price'], $s['compare_at'],
                     $s['cost_aed'], $s['profit_toman'], $s['promo_toman'],
                     $s['commission'], $i]
                );
                $sizes++;
                if ($s['commission'] !== null && $s['commission'] !== $parentRate) {
                    $rateLog[] = ['size', $id,
                        $p['name_fa'] . ' — ' . $s['label'], null, $s['commission']];
                }
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

        /* A commission rate decides money moving between two parties, so a
           bulk import changing sixty of them is exactly the event the log
           exists for — more so than a single edit in the product form. */
        foreach ($rateLog as [$scope, $scopeId, $label, $old, $new]) {
            db_query(
                'INSERT INTO commission_log
                    (scope, scope_id, label, old_percent, new_percent, admin_id, admin_name)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$scope, $scopeId, $label, $old, $new,
                 $admin['id'] ?? null, (string) ($admin['name'] ?? '')]
            );
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[suppex] import failed: ' . $e->getMessage());
        throw $e;
    }

    return ['created' => $created, 'updated' => $updated, 'sizes' => $sizes,
            'rates' => count($rateLog)];
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

/**
 * The sheet the seller fills in.
 *
 * Shipped with worked examples rather than an empty grid, because the columns
 * that get filled in wrongly are always the same three: a size written as one
 * cell with a slash in it instead of two rows, a dirham cost typed in toman,
 * and a commission left at 0 when the intent was "whatever the shop rate is".
 * An example row answers all three without anybody reading instructions.
 */
function import_template_csv(): string
{
    $rows = [
        array_values(IMPORT_COLUMNS),
        /* Two rows, one product: this is the pattern to copy. */
        ['وی پروتئین اورجینال', 'Whey Original', 'SUPPEX', 'پروتئین',
         '900 گرم', '40', '1500000', '', '', '', 'بله',
         'شکلاتی، وانیلی، توت فرنگی', '30',
         'وی کنسانتره و ایزوله میکروفیلتر شده',
         'هر وعده ۲۴ گرم پروتئین دارد. بعد از تمرین با آب یا شیر مصرف شود.'],
        ['وی پروتئین اورجینال', 'Whey Original', 'SUPPEX', 'پروتئین',
         '2270 گرم', '95', '2000000', '', '', '', 'بله', '', '76', '', ''],
        /* One size, so the size column stays empty. */
        ['کراتین مونوهیدرات', 'Creatine Monohydrate', 'SUPPEX', 'کراتین',
         '', '18', '900000', '', '', '', 'بله', '', '100',
         'کراتین میکرونایز خالص', ''],
        /* A crowded line: thin margin, and a lower share agreed on it. */
        ['بی سی آمینو', 'BCAA 2:1:1', 'SUPPEX', 'آمینو',
         '', '22', '700000', '', '50000', '8', 'بله', 'هندوانه، لیمو', '40',
         'آمینو شاخه‌دار با نسبت ۲:۱:۱', ''],
    ];

    $out = fopen('php://temp', 'r+');
    /* A BOM, so Excel on a Persian Windows opens it as UTF-8 instead of
       rendering every product name as mojibake. */
    fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    foreach ($rows as $r) {
        fputcsv($out, $r);
    }
    rewind($out);
    $csv = stream_get_contents($out) ?: '';
    fclose($out);
    return $csv;
}
