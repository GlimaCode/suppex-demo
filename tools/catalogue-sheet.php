<?php
/* ===========================================================================
   A filled import workbook, prepared off the server
   ---------------------------------------------------------------------------
   The panel's own Excel template - same columns, hints, drop-downs, example
   and guide (lib/import_sheet.php) - filled with products written as JSON,
   for the owner to upload on «ورود از اکسل». Used for researched new products:
   the facts go in the JSON, the workbook comes out ready.

       php tools/catalogue-sheet.php <rows.json> <out.xlsx> [<export.xlsx>]

   rows.json: a list of rows keyed like IMPORT_COLUMNS (lib/import.php); a
   product with several sizes is several rows with the same «name_fa».
   export.xlsx: a workbook downloaded from the live panel; its hidden «lists»
   sheet gives the drop-downs the live shop's brands and categories instead of
   this machine's. Prints the import's own plan of the result - errors and
   warnings - so a mistake shows here, not on the shop.

   tools/ is never uploaded.
   =========================================================================== */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/import_sheet.php';

$json = $argv[1] ?? '';
$out  = $argv[2] ?? '';
$from = $argv[3] ?? '';
if ($json === '' || $out === '' || !is_file($json)) {
    fwrite(STDERR, "usage: php tools/catalogue-sheet.php <rows.json> <out.xlsx> [<export.xlsx>]\n");
    exit(2);
}

$rows = json_decode((string) file_get_contents($json), true);
if (!is_array($rows)) {
    fwrite(STDERR, "rows.json is not a JSON list\n");
    exit(2);
}
foreach ($rows as $i => $r) {
    foreach (array_keys($r) as $k) {
        if (!isset(IMPORT_COLUMNS[$k])) {
            fwrite(STDERR, "row " . ($i + 1) . ": unknown column «{$k}»\n");
            exit(2);
        }
    }
}

$lists = null;
if ($from !== '') {
    $x = xlsx_read($from, [IMPORT_SHEET_LISTS]);
    if (($x['sheet'] ?? '') === IMPORT_SHEET_LISTS) {
        $lists = ['categories' => [], 'brands' => []];
        foreach ($x['rows'] as $n => $cells) {
            if ($n === 1) {
                continue;
            }
            if (trim((string) ($cells[0] ?? '')) !== '') {
                $lists['categories'][] = trim((string) $cells[0]);
            }
            if (trim((string) ($cells[1] ?? '')) !== '') {
                $lists['brands'][] = trim((string) $cells[1]);
            }
        }
        foreach ($rows as $r) {
            $b = trim((string) ($r['brand'] ?? ''));
            if ($b !== '' && !in_array($b, $lists['brands'], true)) {
                $lists['brands'][] = $b;
            }
        }
    }
}

file_put_contents($out, import_workbook($rows, $lists));

$read = import_read_file($out);
$plan = import_plan($read['rows'], $read['columns']);
printf("%s: %d rows, %d products, %d bytes\n", $out, count($rows), count($plan['products']), filesize($out));
foreach ($plan['errors'] as $e) {
    echo "  ERROR   {$e}\n";
}
foreach ($plan['warnings'] as $w) {
    echo "  warning {$w}\n";
}
exit($plan['errors'] ? 1 : 0);
