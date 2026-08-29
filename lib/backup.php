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

    $products = db_all(
        'SELECT p.*, c.name_fa AS category_name
           FROM products p
      LEFT JOIN categories c ON c.id = p.category_id
       ORDER BY p.sort_order, p.id'
    );

    foreach ($products as $p) {
        $flavours = implode('، ', array_column(
            db_all('SELECT name FROM product_flavors WHERE product_id = ? ORDER BY sort_order',
                   [(int) $p['id']]),
            'name'
        ));

        $sizes = db_all(
            'SELECT * FROM product_sizes WHERE product_id = ? ORDER BY sort_order',
            [(int) $p['id']]
        );

        /* A product with no sizes is one row with the size column blank —
           exactly how the sheet asks for a single-price product. */
        $units = $sizes ?: [null];

        foreach ($units as $i => $size) {
            $unit = $size ?? $p;

            $row = backup_unit_row($p, $unit, $size !== null);
            /* Flavours and the descriptions belong to the product, and the
               importer takes the first non-empty value it sees, so writing
               them on every row of a multi-size product would be noise. */
            if ($i === 0) {
                $row['flavors']     = $flavours;
                $row['short']       = (string) $p['short'];
                $row['description'] = (string) ($p['description'] ?? '');
            }

            $line = [];
            foreach ($keys as $k) {
                $line[] = (string) ($row[$k] ?? '');
            }
            fputcsv($out, $line);
        }
    }

    rewind($out);
    $csv = stream_get_contents($out) ?: '';
    fclose($out);
    return $csv;
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

/** A filename nobody has to think about, sortable by date. */
function backup_filename(string $suffix, string $ext): string
{
    return 'suppex-' . $suffix . '-' . date('Y-m-d-Hi') . '.' . $ext;
}
