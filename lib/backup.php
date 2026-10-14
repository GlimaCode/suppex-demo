<?php
/* ===========================================================================
   SUPPEX — Backup and export
   ---------------------------------------------------------------------------
   Two different jobs that people confuse, so they are separate here.

   A BACKUP is everything, for putting the shop back after something goes
   wrong: the schema, the orders, the customers, the settings, the admin
   accounts. It is a restore artefact, not a working document.

   An EXPORT is the catalogue in the same shape as the cost sheet, for moving
   or editing it. It round-trips through lib/import.php on purpose — the
   importer is the only thing that understands the pricing rules, and a second
   reader of the same file would drift from it within a month.

   Three decisions worth stating.

   NOTHING IS WRITTEN INTO THE WEB ROOT. A dump holds every customer's name,
   phone and address, and every admin's password hash. A file left in
   public_html is fetchable by anyone who guesses the name, and "backup.sql"
   is the first guess. Both outputs stream straight to the browser and touch
   no disk.

   NO SHELL. mysqldump is disabled on most shared cPanel accounts, and code
   that only works where exec() is allowed is code that fails on the host it
   was written for. This reads through PDO like everything else.

   ROWS ARE READ IN CHUNKS. A shop with ten thousand orders would otherwise
   build the whole dump in memory and hit the 128 MB limit — on the day it is
   most needed, which is the day the shop is biggest.
   =========================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/import.php';
require_once __DIR__ . '/images.php';   // uploads_dir()

/** How many rows to hold in memory at once while dumping. */
const BACKUP_CHUNK = 500;

/**
 * Every table in the current database, in a restorable order.
 *
 * Foreign keys are disabled around the restore, so the order is for human
 * readability rather than correctness.
 *
 * @return array<int,string>
 */
function backup_tables(): array
{
    $out = [];
    foreach (db_all('SHOW TABLES') as $row) {
        $out[] = (string) reset($row);
    }
    sort($out);
    return $out;
}

/**
 * Write a restorable SQL dump.
 *
 * @param callable(string):void $write  receives each chunk of SQL
 */
function backup_stream_sql(callable $write): void
{
    $pdo = db();

    $write("-- SUPPEX backup\n");
    $write('-- ' . date('Y-m-d H:i:s') . "\n");
    $write("--\n");
    $write("-- Restore: create an empty database, then import this file through\n");
    $write("-- phpMyAdmin (Import tab) or:  mysql -u USER -p DBNAME < this-file.sql\n");
    $write("--\n\n");

    $write("SET NAMES utf8mb4;\n");
    $write("SET FOREIGN_KEY_CHECKS = 0;\n");
    /* Without this a restore inherits the exporting server's zero-date and
       strict settings, and a row that saved fine here is rejected there. */
    $write("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

    foreach (backup_tables() as $table) {
        $quoted = '`' . str_replace('`', '``', $table) . '`';

        $create = db_one('SHOW CREATE TABLE ' . $quoted);
        $ddl    = $create === null ? null : ($create['Create Table'] ?? null);
        if ($ddl === null) {
            continue;
        }

        $write("\n-- ---------------------------------------------------------\n");
        $write('-- ' . $table . "\n");
        $write("-- ---------------------------------------------------------\n");
        $write('DROP TABLE IF EXISTS ' . $quoted . ";\n");
        $write($ddl . ";\n\n");

        $count = (int) db_value('SELECT COUNT(*) FROM ' . $quoted);
        if ($count === 0) {
            continue;
        }

        for ($offset = 0; $offset < $count; $offset += BACKUP_CHUNK) {
            $rows = db_all('SELECT * FROM ' . $quoted . ' LIMIT ' . BACKUP_CHUNK
                         . ' OFFSET ' . $offset);
            if (!$rows) {
                break;
            }

            $cols = array_map(
                static fn(string $c): string => '`' . str_replace('`', '``', $c) . '`',
                array_keys($rows[0])
            );

            $values = [];
            foreach ($rows as $row) {
                $cells = [];
                foreach ($row as $cell) {
                    /* quote() escapes for THIS connection's charset, which is
                       what makes a hand-built dump safe. NULL has no quoted
                       form, so it is written literally. */
                    $cells[] = $cell === null ? 'NULL' : $pdo->quote((string) $cell);
                }
                $values[] = '(' . implode(',', $cells) . ')';
            }

            $write('INSERT INTO ' . $quoted . ' (' . implode(',', $cols) . ") VALUES\n"
                 . implode(",\n", $values) . ";\n");
        }
    }

    $write("\nSET FOREIGN_KEY_CHECKS = 1;\n");
}

/**
 * The catalogue, in the cost sheet's own columns.
 *
 * Written so import_read_csv() can read it back: one row per buyable unit, and
 * never both purchase-price columns on one row, because the importer refuses
 * that as two claims about one purchase.
 */
function backup_catalogue_csv(): string
{
    $out = fopen('php://temp', 'r+');
    /* Excel on a Persian Windows needs the BOM to read this as UTF-8. */
    fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, array_values(IMPORT_COLUMNS));

    $keys = array_keys(IMPORT_COLUMNS);
    foreach (backup_catalogue_rows() as $row) {
        $line = [];
        foreach ($keys as $k) {
            $line[] = (string) ($row[$k] ?? '');
        }
        fputcsv($out, $line);
    }

    rewind($out);
    $csv = stream_get_contents($out) ?: '';
    fclose($out);
    return $csv;
}

/**
 * The catalogue as the import's rows, keyed by IMPORT_COLUMNS - one per size,
 * or one for a single-price product. The CSV above and the Excel workbook
 * (lib/import_sheet.php) are both written from it, so the two can never
 * disagree about what a round trip carries.
 *
 * @param int|null $productId one product - «خروجی اکسل» on its row in the
 *                            panel and on its edit page - or null for all
 * @return array<int,array<string,string>>
 */
function backup_catalogue_rows(?int $productId = null): array
{
    $rows = [];
    $products = db_all(
        'SELECT p.*, c.name_fa AS category_name
           FROM products p
      LEFT JOIN categories c ON c.id = p.category_id'
        . ($productId !== null ? ' WHERE p.id = ?' : '') . '
       ORDER BY p.sort_order, p.id',
        $productId !== null ? [$productId] : []
    );

    foreach ($products as $p) {
        $sizes = db_all(
            'SELECT * FROM product_sizes WHERE product_id = ? ORDER BY sort_order',
            [(int) $p['id']]
        );

        /* A product with no sizes is one row with the size column blank —
           exactly how the sheet asks for a single-price product. */
        $units = $sizes ?: [null];
        $built = [];
        foreach ($units as $size) {
            $built[] = backup_unit_row($p, $size ?? $p, $size !== null);
        }

        /* Flavours and the descriptions belong to the product, and the
           importer takes the first non-empty value it sees, so writing them on
           every row of a multi-size product would be noise. */
        $first = &$built[0];
        $first['flavors']     = backup_flavours_cell((int) $p['id'], $built);
        $first['short']       = (string) $p['short'];
        $first['description'] = (string) ($p['description'] ?? '');
        /* One per line, which is how the panel shows them and how
           import_features() reads them back. */
        $first['features']    = implode("\n", json_decode_array($p['features'] ?? null));
        $first['ingredients'] = (string) ($p['ingredients'] ?? '');
        $first['usage']       = (string) ($p['usage_text'] ?? '');
        $first['nutrition_serving'] = (string) ($p['nutrition_serving'] ?? '');
        $first['nutrition']   = implode("\n", array_map(
            static fn($n) => trim(($n['label'] ?? '') . ': ' . ($n['value'] ?? ''), ' :'),
            json_decode_array($p['nutrition_rows'] ?? null)));
        $first['badges']      = implode('، ', array_map(
            static fn($b) => IMPORT_BADGE_LABELS[$b] ?? $b,
            array_values(array_filter(explode(',', (string) ($p['badges'] ?? ''))))));
        $first['is_active']   = (int) $p['is_active'] === 1 ? 'بله' : 'خیر';
        $first['sort_order']  = (string) (int) $p['sort_order'];
        unset($first);

        foreach ($built as $row) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/**
 * What each product sells for today: one row per thing a shopper can buy -
 * every combination of a product priced by combination, every size (and each
 * flavour that costs extra) of one that is not, or the product itself.
 *
 * For reading only; the import never reads it. It exists because the sheet's
 * price columns are per size, and a product priced by combination is not: its
 * sizes keep whatever figures they had when it was converted, and an export
 * showing only those was a price list of numbers the shop no longer charged -
 * on the live shop, nine products out of fourteen on 2026-10-02.
 *
 * @return list<array{product:string,size:string,flavor:string,other:string,
 *                    price:int,compare_at:?int,stock:?int,on_sale:bool}>
 */
function backup_selling_rows(?int $productId = null): array
{
    require_once __DIR__ . '/variants.php';
    $out = [];
    $products = db_all(
        'SELECT id, name_fa, price, compare_at, stock_qty, is_active, in_stock FROM products'
        . ($productId !== null ? ' WHERE id = ?' : '') . ' ORDER BY sort_order, id',
        $productId !== null ? [$productId] : []
    );
    foreach ($products as $p) {
        $id   = (int) $p['id'];
        $open = (int) $p['is_active'] === 1 && (int) $p['in_stock'] === 1;
        $row  = static fn(string $size, string $flavor, string $other, int $price, $compare, $stock, bool $live): array => [
            'product'    => (string) $p['name_fa'],
            'size'       => $size,
            'flavor'     => $flavor,
            'other'      => $other,
            'price'      => $price,
            'compare_at' => $compare === null || (int) $compare <= $price ? null : (int) $compare,
            'stock'      => $stock === null ? null : max(0, (int) $stock),
            'on_sale'    => $live && $price > 0,
        ];

        $variants = variants_list($id);
        if ($variants) {
            foreach ($variants as $v) {
                $size = $flavor = '';
                $other = [];
                foreach ($v['values'] as $val) {
                    if ((string) $val['attr_ext'] === VARIANT_SIZE_ATTR) {
                        $size = (string) $val['label'];
                    } elseif ((string) $val['attr_ext'] === VARIANT_FLAVOR_ATTR) {
                        $flavor = (string) $val['label'];
                    } else {
                        $other[] = $val['attr_name'] . ': ' . $val['label'];
                    }
                }
                $out[] = $row($size, $flavor, implode('، ', $other), (int) $v['price'], $v['compare_at'],
                              $v['stock_qty'], $open && (int) $v['is_active'] === 1);
            }
            continue;
        }

        $sizes = db_all('SELECT label, price, compare_at, stock_qty FROM product_sizes
                          WHERE product_id = ? ORDER BY sort_order, id', [$id]);
        $extra = db_all('SELECT name, price_delta_toman FROM product_flavors
                          WHERE product_id = ? AND price_delta_toman > 0 ORDER BY sort_order, id', [$id]);
        $units = $sizes ?: [['label' => '', 'price' => $p['price'], 'compare_at' => $p['compare_at'],
                             'stock_qty' => $p['stock_qty']]];
        foreach ($units as $u) {
            $stock = $u['stock_qty'] ?? null;
            $out[] = $row((string) $u['label'], '', '', (int) $u['price'], $u['compare_at'], $stock, $open);
            foreach ($extra as $f) {
                $out[] = $row((string) $u['label'], (string) $f['name'], '',
                              (int) $u['price'] + (int) $f['price_delta_toman'], null, $stock, $open);
            }
        }
    }
    return $out;
}

/**
 * The flavours cell, premiums included in the unit the rows are priced in:
 * «شکلاتی، وانیلی=2.5» beside a dirham cost, «وانیلی=150000» otherwise.
 *
 * A premium in the other unit is left off rather than converted - the import
 * keeps a flavour's premium by name when the cell names none, so leaving it
 * off loses nothing, while writing it would be read in the wrong unit.
 *
 * @param array<int,array<string,string>> $rows  this product's exported rows
 */
function backup_flavours_cell(int $productId, array $rows): string
{
    $aed = false;
    foreach ($rows as $r) {
        if (($r['cost_aed'] ?? '') !== '') { $aed = true; }
    }
    $parts = [];
    foreach (db_all('SELECT name, cost_delta_aed, price_delta_toman
                       FROM product_flavors WHERE product_id = ? ORDER BY sort_order', [$productId]) as $f) {
        $name = (string) $f['name'];
        if ($aed && $f['cost_delta_aed'] !== null && (float) $f['cost_delta_aed'] > 0) {
            $name .= '=' . rtrim(rtrim(number_format((float) $f['cost_delta_aed'], 2, '.', ''), '0'), '.');
        } elseif (!$aed && (int) $f['price_delta_toman'] > 0) {
            $name .= '=' . (int) $f['price_delta_toman'];
        }
        $parts[] = $name;
    }
    return implode('، ', $parts);
}

/**
 * One buyable unit as sheet columns.
 *
 * The pricing route is inferred from what is stored, and only the columns that
 * route uses are written. Exporting everything would produce a file the
 * importer rejects — a dirham cost and a toman cost on one row, or a typed
 * price beside a profit that would recompute it differently.
 *
 * @param array $product  the parent row
 * @param array $unit     the size row, or the parent when there are no sizes
 * @return array<string,string>
 */
function backup_unit_row(array $product, array $unit, bool $isSize): array
{
    $row = [
        'name_fa'    => (string) $product['name_fa'],
        'name_en'    => (string) $product['name_en'],
        'brand'      => (string) $product['brand'],
        'category'   => (string) ($product['category_name'] ?? ''),
        'size_label' => $isSize ? (string) $unit['label'] : '',
        'in_stock'   => (int) $product['in_stock'] === 1 ? 'بله' : 'خیر',
        'servings'   => $isSize && !empty($unit['servings']) ? (string) (int) $unit['servings'] : '',
        /* Blank is «not counted», which the import reads as «leave it». */
        'stock_qty'  => ($unit['stock_qty'] ?? null) === null ? '' : (string) (int) $unit['stock_qty'],
    ];

    /* A size with no cost of its own falls back to the parent's, the same way
       pricing_units() resolves it. */
    $costAed = $unit['cost_aed'] ?? null;
    if ($isSize && ($costAed === null || (float) $costAed <= 0)) {
        $costAed = $product['cost_aed'] ?? null;
    }
    $profit = $unit['profit_toman'] ?? null;
    if ($isSize && ($profit === null || (int) $profit <= 0)) {
        $profit = $product['profit_toman'] ?? null;
    }
    $promo = $unit['promo_toman'] ?? null;
    if ($isSize && ($promo === null || (int) $promo <= 0)) {
        $promo = $product['promo_toman'] ?? null;
    }

    $hasAed = $costAed !== null && (float) $costAed > 0;

    if ($hasAed) {
        /* Dirham route. cost_toman is derived from the rate, so it is not
           written — the importer would read the pair as two costs and refuse. */
        $row['cost_aed']     = rtrim(rtrim(number_format((float) $costAed, 2, '.', ''), '0'), '.');
        $row['profit_toman'] = (string) (int) $profit;
    } else {
        $costToman = $unit['cost_toman'] ?? null;
        $hasToman  = $costToman !== null && (int) $costToman > 0;
        if ($hasToman) {
            $row['cost_toman'] = (string) (int) $costToman;
        }

        if ($profit !== null && (int) $profit > 0) {
            /* Cost plus profit: the price is derived, so writing it too would
               invite the two to disagree. */
            $row['profit_toman'] = (string) (int) $profit;
        } else {
            $row['price_toman'] = (string) (int) $unit['price'];
        }
    }

    /* A discount only means something against a margin, and the importer now
       refuses one without a cost. */
    if ($promo !== null && (int) $promo > 0 && ($hasAed || !empty($row['cost_toman']))) {
        $row['promo_toman'] = (string) (int) $promo;
    }

    /* NULL is "inherit the shop rate" and must stay blank; 0 is a decision and
       has to survive the round trip as a zero. */
    $rate = $unit['commission_percent'] ?? null;
    if ($isSize && $rate === null) {
        $rate = $product['commission_percent'] ?? null;
    }
    if ($rate !== null) {
        $row['commission'] = rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.');
        if ($row['commission'] === '') {
            $row['commission'] = '0';
        }
    }

    return $row;
}

/**
 * What is in the uploads folder, so the operator can see whether they copied
 * all of it.
 *
 * Not zipped: ZipArchive is missing on plenty of shared hosts, and a backup
 * feature that works on some of them is worse than one that tells the truth
 * and hands over the path.
 *
 * @return array{path:string,files:int,bytes:int,readable:bool}
 */
function backup_uploads_summary(): array
{
    $dir = uploads_dir();
    if (!is_dir($dir)) {
        return ['path' => $dir, 'files' => 0, 'bytes' => 0, 'readable' => false];
    }

    $files = 0;
    $bytes = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $files++;
                $bytes += $file->getSize();
            }
        }
    } catch (Throwable $e) {
        return ['path' => $dir, 'files' => 0, 'bytes' => 0, 'readable' => false];
    }

    return ['path' => $dir, 'files' => $files, 'bytes' => $bytes, 'readable' => true];
}


/**
 * Read the export back through the importer that will consume it.
 *
 * A backup file nobody has ever read is a hope, not a backup. This one has
 * exactly one consumer, so the honest check is to hand the bytes to that
 * consumer and report what it says - including the case that matters most,
 * where the shop holds a value the importer refuses and the file therefore
 * rebuilds fewer products than it was asked to carry.
 *
 * @return array{rows:int,products:int,expected:int,errors:array,warnings:array,error:?string}
 */
function backup_catalogue_check(): array
{
    $expected = (int) db_value('SELECT COUNT(*) FROM products');
    $blank    = ['rows' => 0, 'products' => 0, 'expected' => $expected,
                 'errors' => [], 'warnings' => [], 'error' => null];

    /* A temp file, not the web root: import_read_csv() reads a path, and the
       one place a file holding the catalogue must never land is next to the
       pages that serve it. */
    $tmp = tempnam(sys_get_temp_dir(), 'suppex-check-');
    if ($tmp === false) {
        return $blank;
    }

    try {
        file_put_contents($tmp, backup_catalogue_csv());
        $read = import_read_csv($tmp);
        if ($read['error'] !== null) {
            return array_merge($blank, ['error' => $read['error']]);
        }
        $plan = import_plan($read['rows'], $read['columns'] ?? []);
        return [
            'rows'     => count($read['rows']),
            'products' => count($plan['products']),
            'expected' => $expected,
            'errors'   => $plan['errors'],
            'warnings' => $plan['warnings'],
            'error'    => null,
        ];
    } catch (Throwable $e) {
        error_log('[suppex] catalogue self-check failed: ' . $e->getMessage());
        return array_merge($blank, ['error' => 'بررسی فایل با خطا مواجه شد.']);
    } finally {
        @unlink($tmp);
    }
}

/** A filename nobody has to think about, sortable by date. */
function backup_filename(string $suffix, string $ext): string
{
    return 'suppex-' . $suffix . '-' . date('Y-m-d-Hi') . '.' . $ext;
}
