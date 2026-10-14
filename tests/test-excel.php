<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP.
   bootstrap falls back to the real suppex-config.php when SUPPEX_CONFIG
   is unset - which is exactly what a web request has - so one GET to an
   uploaded tests/ folder could empty the live database. The .htaccess
   beside this file is process; this is code. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* ===========================================================================
   The Excel import: the .xlsx reader and writer (lib/xlsx.php), the columns
   that let a sheet carry everything the product form does (lib/import.php),
   the photographs a sheet names, and the workbook the panel hands out
   (lib/import_sheet.php). Against the throwaway database.
   =========================================================================== */

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/products.php';
require_once SUPPEX_ROOT . '/lib/import_sheet.php';

$pass = 0; $fail = 0;
function ok(string $l, bool $c, ?string $x = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $l\n"; }
    else { $fail++; echo "  FAIL $l" . ($x ? "  --  $x" : '') . "\n"; }
}
function head(string $t): void { echo "\n== $t ==\n"; }

$tmpDir = sys_get_temp_dir() . '/suppex-excel-test';
@mkdir($tmpDir);
$tmp = $tmpDir . '/sheet.xlsx';

/** A workbook whose «محصولات» sheet has these headings and rows (keyed). */
function workbook(array $keys, array $rows, array $labels = []): string {
    global $tmp;
    $head = [];
    foreach ($keys as $k) { $head[] = $labels[$k] ?? IMPORT_COLUMNS[$k]; }
    $out = [$head];
    foreach ($rows as $r) {
        $line = [];
        foreach ($keys as $k) { $line[] = (string) ($r[$k] ?? ''); }
        $out[] = $line;
    }
    file_put_contents($tmp, xlsx_build([['name' => IMPORT_SHEET, 'rtl' => true, 'rows' => $out]]));
    return $tmp;
}

/* --------------------------------------------------------------------------
   lib/xlsx.php on its own
   -------------------------------------------------------------------------- */
head('column letters');
$roundTrip = true;
foreach ([0 => 'A', 25 => 'Z', 26 => 'AA', 51 => 'AZ', 701 => 'ZZ', 702 => 'AAA'] as $i => $l) {
    $roundTrip = $roundTrip && xlsx_col_letter($i) === $l && xlsx_col_index($l) === $i + 1;
}
ok('A, Z, AA, AZ, ZZ, AAA both ways', $roundTrip);

head('numbers come back as a person typed them');
foreach (['42.499999999999993' => '42.5', '1E6' => '1000000', '1850000' => '1850000',
          '0.30000000000000004' => '0.3', '-0' => '0', '0912' => '912', 'abc' => 'abc'] as $in => $want) {
    ok("«{$in}» → «{$want}»", xlsx_number((string) $in) === $want, xlsx_number((string) $in));
}

head('a ZIP made here opens here, and damage is noticed');
$files = ['a.txt' => str_repeat('ساپکس ', 400), 'b/c.xml' => '<x/>', 'empty' => ''];
$zip = zip_build($files);
$back = zip_extract($zip);
ok('every file comes back byte for byte', is_array($back) && $back === $files);
ok('only the wanted ones are inflated', zip_extract($zip, static fn($n) => $n === 'b/c.xml') === ['b/c.xml' => '<x/>']);
ok('not a zip is said', zip_extract('plain text, not an archive at all......') === 'not-zip');
$broken = $zip;
$at = strpos($broken, 'a.txt') + 5 + 10;   // inside a.txt's deflated data
$broken[$at] = chr(ord($broken[$at]) ^ 0xFF);
ok('a flipped byte is caught (crc or inflate)', zip_extract($broken) === 'broken');

head('a workbook written here reads back');
$bin = xlsx_build([
    ['name' => 'راهنما', 'rows' => [['help']]],
    ['name' => IMPORT_SHEET, 'rtl' => true, 'freeze' => 1, 'rows' => [
        ['نام', 'قیمت', 'یادداشت'],
        ['توت‌فرنگی «ویژه» & <نو>', 1850000, "خط اول\nخط دوم"],
        ['  فاصله  ', 42.5, null, 'ستون چهارم'],
    ]],
    ['name' => 'lists', 'hidden' => true, 'rows' => [['x']]],
]);
file_put_contents($tmp, $bin);
$r = xlsx_read($tmp, [IMPORT_SHEET]);
ok('the preferred sheet is read, not the first', $r['sheet'] === IMPORT_SHEET, $r['sheet']);
ok('ZWNJ, quotes and markup survive', ($r['rows'][2][0] ?? '') === 'توت‌فرنگی «ویژه» & <نو>');
ok('numbers come back as text', ($r['rows'][2][1] ?? '') === '1850000' && ($r['rows'][3][1] ?? '') === '42.5');
ok('a line break inside a cell survives', ($r['rows'][2][2] ?? '') === "خط اول\nخط دوم");
ok('spaces at either end survive', ($r['rows'][3][0] ?? '') === '  فاصله  ');
ok('an empty cell is absent and the next keeps its column', !isset($r['rows'][3][2]) && ($r['rows'][3][3] ?? '') === 'ستون چهارم');
$r2 = xlsx_read($tmp, []);
ok('with no preference, the first VISIBLE sheet', $r2['sheet'] === 'راهنما');

head('what other programs write: shared strings, rich text, prefixes, types');
$ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
$foreign = zip_build([
    '[Content_Types].xml' => '<Types/>',
    'xl/workbook.xml' => '<?xml version="1.0"?><x:workbook xmlns:x="' . $ns . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><x:sheets>'
        . '<x:sheet name="Sheet1" sheetId="1" r:id="rId9"/></x:sheets></x:workbook>',
    'xl/_rels/workbook.xml.rels' => '<Relationships><Relationship Id="rId9" Type="ws" Target="/xl/worksheets/data.xml"/></Relationships>',
    'xl/sharedStrings.xml' => '<x:sst xmlns:x="' . $ns . '"><x:si><x:t>وی</x:t></x:si>'
        . '<x:si><x:r><x:rPr><x:b/></x:rPr><x:t>پر</x:t></x:r><x:r><x:t xml:space="preserve">رنگ </x:t></x:r>'
        . '<x:rPh sb="0" eb="1"><x:t>PHONETIC</x:t></x:rPh></x:si>'
        . '<x:si><x:t>a_x000D_b &amp; c</x:t></x:si><x:si><x:r><x:t/></x:r><x:r><x:t>بعد</x:t></x:r></x:si></x:sst>',
    'xl/worksheets/data.xml' => '<x:worksheet xmlns:x="' . $ns . '"><x:sheetData>'
        . '<x:row r="1"><x:c r="A1" t="s"><x:v>0</x:v></x:c><x:c r="C1" t="s"><x:v>1</x:v></x:c></x:row>'
        . '<x:row r="3"><x:c r="A3" t="b"><x:v>1</x:v></x:c><x:c r="B3" t="str"><x:f>A1&amp;"x"</x:f><x:v>ویx</x:v></x:c>'
        . '<x:c r="C3" t="e"><x:v>#DIV/0!</x:v></x:c><x:c r="D3"><x:f>2*3</x:f><x:v>6</x:v></x:c>'
        . '<x:c r="E3" t="inlineStr"><x:is><x:t>درون‌خط</x:t></x:is></x:c></x:row>'
        . '<x:row r="4"><x:c r="A4" t="s"><x:v>2</x:v></x:c><x:c r="B4" t="s"><x:v>3</x:v></x:c></x:row>'
        . '</x:sheetData></x:worksheet>',
]);
file_put_contents($tmp, $foreign);
$f = xlsx_read($tmp);
ok('prefixed parts and an absolute relationship target are followed', $f['error'] === null && $f['sheet'] === 'Sheet1', (string) $f['error']);
ok('a shared string', ($f['rows'][1][0] ?? '') === 'وی');
ok('rich text joined, its phonetic guide left out', ($f['rows'][1][2] ?? '') === 'پررنگ ');
ok('rows keep their own numbers (row 2 is missing)', isset($f['rows'][3]) && !isset($f['rows'][2]));
ok('a boolean as 1', ($f['rows'][3][0] ?? '') === '1');
ok('a formula as its stored value', ($f['rows'][3][1] ?? '') === 'ویx' && ($f['rows'][3][3] ?? '') === '6');
ok('an error cell as nothing', !isset($f['rows'][3][2]));
ok('an inline string', ($f['rows'][3][4] ?? '') === 'درون‌خط');
ok('Excel\'s _x000D_ and an entity decoded', ($f['rows'][4][0] ?? '') === "a\nb & c", json_encode($f['rows'][4][0] ?? ''));
ok('an empty <t/> does not swallow the next run', ($f['rows'][4][1] ?? '') === 'بعد', $f['rows'][4][1] ?? '');

/* --------------------------------------------------------------------------
   The import reading a workbook
   -------------------------------------------------------------------------- */
$admin = ['id' => 1, 'name' => 'tester'];
settings_save(['aed_rate' => '25000', 'price_rounding_step' => '10000']);

head('headings are matched leniently, and an .xls is named');
$keys = ['name_fa', 'brand', 'size_label', 'price_toman', 'stock_qty', 'images'];
workbook($keys, [['name_fa' => 'آزمون عنوان', 'brand' => 'TESTX', 'size_label' => '1 کیلو', 'price_toman' => '900000', 'stock_qty' => '3']],
         ['name_fa' => 'نام  فارسی *', 'price_toman' => 'قیمت فروش(تومان)', 'stock_qty' => 'موجودی', 'images' => 'تصاویر']);
$read = import_read_file($tmp);
ok('read', $read['error'] === null && count($read['rows']) === 1, (string) $read['error']);
ok('«نام  فارسی *», «قیمت فروش(تومان)» and two aliases found their columns',
   $read['columns'] === ['name_fa', 'brand', 'size_label', 'price_toman', 'stock_qty', 'images'],
   json_encode($read['columns'], JSON_UNESCAPED_UNICODE));
ok('the row is numbered as Excel numbers it', ($read['rows'][0]['_line'] ?? 0) === 2);
file_put_contents($tmpDir . '/old.xls', "\xD0\xCF\x11\xE0" . str_repeat("\0", 600));
$old = import_read_file($tmpDir . '/old.xls');
ok('an old .xls is named, not misread', $old['error'] !== null && strpos($old['error'], 'xls') !== false);
file_put_contents($tmpDir . '/h.xlsx', xlsx_build([['name' => IMPORT_SHEET, 'rows' => [['ستونی دیگر'], ['x']]]]));
$noName = import_read_file($tmpDir . '/h.xlsx');
ok('no Persian-name column is said', $noName['error'] !== null && mb_strpos($noName['error'], 'نام فارسی') !== false);

/* --------------------------------------------------------------------------
   Every column of the form, end to end
   -------------------------------------------------------------------------- */
foreach (db_all("SELECT id FROM products WHERE brand IN ('EXCELTEST', 'TESTX')") as $p) {
    db_query('DELETE FROM products WHERE id = ?', [(int) $p['id']]);
}
db_query("DELETE FROM import_photos WHERE name_key LIKE 'xl-%'");
$photosReady = import_photos_ready();
ok('the photographs\' tables exist after the migration', $photosReady);
/* Waiting photographs, as the uploader leaves them (the files need not exist
   for the import to attach them; the panel serves what the row says). */
foreach (['xl-front.jpg', 'xl-back.png', 'xl-side.webp'] as $n) {
    db_query('INSERT INTO import_photos (name_key, original_name, path) VALUES (?, ?, ?)',
             [$n, strtoupper($n), '/uploads/products/test/' . $n]);
}

$all = ['name_fa', 'name_en', 'brand', 'category', 'size_label', 'servings', 'cost_aed', 'cost_toman',
        'profit_toman', 'price_toman', 'promo_toman', 'stock_qty', 'in_stock', 'is_active', 'badges',
        'sort_order', 'flavors', 'images', 'short', 'features', 'description', 'ingredients', 'usage',
        'nutrition_serving', 'nutrition'];
$rows = [
    ['name_fa' => 'اکسل وی', 'name_en' => 'Excel Whey', 'brand' => 'EXCELTEST', 'category' => 'پروتئین',
     'size_label' => '900 گرم', 'servings' => '30', 'cost_aed' => '40', 'profit_toman' => '1500000',
     'stock_qty' => '5', 'in_stock' => 'بله', 'is_active' => 'خیر', 'badges' => 'پرفروش، تخفیف برتر',
     'sort_order' => '3', 'flavors' => 'شکلاتی، وانیلی=2', 'images' => 'XL-Front.jpg, xl-back',
     'short' => 'یک جمله', 'features' => "ویژگی یک\nویژگی دو", 'description' => "بند اول\n\nبند دوم",
     'ingredients' => "پروتئین وی\nطعم‌دهنده", 'usage' => 'یک پیمانه بعد از تمرین',
     'nutrition_serving' => 'هر پیمانه ۳۰ گرم', 'nutrition' => "پروتئین: 24 g\nچربی: 1 g"],
    ['name_fa' => 'اکسل وی', 'brand' => 'EXCELTEST', 'size_label' => '2270 گرم', 'servings' => '76',
     'cost_aed' => '95', 'profit_toman' => '2000000'],
    ['name_fa' => 'اکسل کراتین', 'brand' => 'EXCELTEST', 'category' => 'کراتین', 'cost_toman' => '650000',
     'profit_toman' => '600000', 'stock_qty' => '9', 'flavors' => 'بدون طعم، لیمو=150000', 'images' => 'xl-missing.jpg'],
];
workbook($all, $rows);
$read = import_read_file($tmp);
$plan = import_plan($read['rows'], $read['columns']);
ok('the full sheet plans without errors', $plan['errors'] === [], implode(' | ', $plan['errors']));
ok('two products', count($plan['products']) === 2);
$warned = implode(' | ', $plan['warnings']);
ok('the photograph nobody uploaded is warned about, by name', strpos($warned, 'xl-missing.jpg') !== false, $warned);
$whey = $plan['products'][0];
ok('photos found: one by its name in another case, one without its extension',
   ($whey['photo_status']['xl-front.jpg'] ?? '') === 'ready' && ($whey['photo_status']['xl-back'] ?? '') === 'ready',
   json_encode($whey['photo_status']));

$res = import_apply($plan['products'], $admin);
ok('applied: two products, three sizes, two photographs', $res['created'] === 2 && $res['sizes'] === 2 && $res['photos'] === 2,
   json_encode($res));
$w = db_one("SELECT * FROM products WHERE name_fa = 'اکسل وی' AND brand = 'EXCELTEST'");
ok('badges stored as the panel\'s keys', ($w['badges'] ?? '') === 'bestseller,topdeal', (string) ($w['badges'] ?? ''));
ok('«نمایش در سایت: خیر» hides it', (int) $w['is_active'] === 0);
ok('display order', (int) $w['sort_order'] === 3);
ok('ingredients keep their line break', ($w['ingredients'] ?? '') === "پروتئین وی\nطعم‌دهنده");
ok('usage', ($w['usage_text'] ?? '') === 'یک پیمانه بعد از تمرین');
ok('the description keeps its paragraphs', ($w['description'] ?? '') === "بند اول\n\nبند دوم");
ok('the nutrition table, in the form\'s own shape',
   json_decode((string) $w['nutrition_rows'], true) === [['label' => 'پروتئین', 'value' => '24 g'], ['label' => 'چربی', 'value' => '1 g']],
   (string) $w['nutrition_rows']);
ok('its serving line', $w['nutrition_serving'] === 'هر پیمانه ۳۰ گرم');
$sizes = db_all('SELECT label, stock_qty FROM product_sizes WHERE product_id = ? ORDER BY sort_order', [(int) $w['id']]);
ok('the first size is counted at 5, the second not counted',
   $sizes[0]['stock_qty'] !== null && (int) $sizes[0]['stock_qty'] === 5 && $sizes[1]['stock_qty'] === null,
   json_encode($sizes, JSON_UNESCAPED_UNICODE));
$fl = db_all('SELECT name, cost_delta_aed, price_delta_toman FROM product_flavors WHERE product_id = ? ORDER BY sort_order', [(int) $w['id']]);
ok('flavour names without the premium', array_column($fl, 'name') === ['شکلاتی', 'وانیلی'], json_encode(array_column($fl, 'name'), JSON_UNESCAPED_UNICODE));
ok('a dirham premium on a dirham product, priced at today\'s rate',
   (float) $fl[1]['cost_delta_aed'] === 2.0 && (int) $fl[1]['price_delta_toman'] > 0 && $fl[0]['cost_delta_aed'] === null,
   json_encode($fl, JSON_UNESCAPED_UNICODE));
$imgs = db_all('SELECT path, source_name FROM product_images WHERE product_id = ? ORDER BY sort_order', [(int) $w['id']]);
ok('both photographs attached, in the sheet\'s order, remembering their files',
   array_column($imgs, 'source_name') === ['xl-front.jpg', 'xl-back'], json_encode($imgs));
ok('the first is the main picture', $w['image'] === '/uploads/products/test/xl-front.jpg', (string) $w['image']);
ok('the attached ones no longer wait; the unnamed one still does',
   db_value("SELECT COUNT(*) FROM import_photos WHERE name_key IN ('xl-front.jpg', 'xl-back.png')") == 0
   && db_value("SELECT COUNT(*) FROM import_photos WHERE name_key = 'xl-side.webp'") == 1);
$c = db_one("SELECT * FROM products WHERE name_fa = 'اکسل کراتین' AND brand = 'EXCELTEST'");
ok('a new product with no display column is shown', (int) $c['is_active'] === 1);
ok('a single product\'s own count', $c['stock_qty'] !== null && (int) $c['stock_qty'] === 9);
$cf = db_all('SELECT name, cost_delta_aed, price_delta_toman FROM product_flavors WHERE product_id = ? ORDER BY sort_order', [(int) $c['id']]);
ok('a toman premium on a toman product', (int) $cf[1]['price_delta_toman'] === 150000 && $cf[1]['cost_delta_aed'] === null,
   json_encode($cf, JSON_UNESCAPED_UNICODE));

head('importing the same sheet again changes nothing it already did');
$read = import_read_file($tmp);
$plan2 = import_plan($read['rows'], $read['columns']);
$w2 = $plan2['products'][0];
ok('its photographs are recognised as attached', ($w2['photo_status']['xl-front.jpg'] ?? '') === 'attached'
   && ($w2['photo_status']['xl-back'] ?? '') === 'attached', json_encode($w2['photo_status']));
$res2 = import_apply($plan2['products'], $admin);
ok('two updated, nothing created, no photograph twice', $res2['created'] === 0 && $res2['updated'] === 2 && $res2['photos'] === 0,
   json_encode($res2));
ok('still two pictures', (int) db_value('SELECT COUNT(*) FROM product_images WHERE product_id = ?', [(int) $w['id']]) === 2);

head('a sheet without a stock column keeps every count (it used to clear them)');
db_query('UPDATE product_sizes SET stock_qty = 7 WHERE product_id = ? AND label = ?', [(int) $w['id'], '2270 گرم']);
workbook(['name_fa', 'brand', 'size_label', 'cost_aed', 'profit_toman'], [
    ['name_fa' => 'اکسل وی', 'brand' => 'EXCELTEST', 'size_label' => '900 گرم', 'cost_aed' => '41', 'profit_toman' => '1500000'],
    ['name_fa' => 'اکسل وی', 'brand' => 'EXCELTEST', 'size_label' => '2270 گرم', 'cost_aed' => '96', 'profit_toman' => '2000000'],
]);
$read = import_read_file($tmp);
import_apply(import_plan($read['rows'], $read['columns'])['products'], $admin);
$after = db_all('SELECT label, stock_qty FROM product_sizes WHERE product_id = ? ORDER BY sort_order', [(int) $w['id']]);
ok('5 and 7 survive a price-only re-import',
   (int) $after[0]['stock_qty'] === 5 && (int) $after[1]['stock_qty'] === 7, json_encode($after, JSON_UNESCAPED_UNICODE));
$w3 = db_one('SELECT badges, is_active, sort_order, ingredients FROM products WHERE id = ?', [(int) $w['id']]);
ok('and the columns it lacked are untouched', $w3['badges'] === 'bestseller,topdeal' && (int) $w3['is_active'] === 0
   && (int) $w3['sort_order'] === 3 && $w3['ingredients'] === "پروتئین وی\nطعم‌دهنده");

head('a dash clears, «بله» shows again');
workbook(['name_fa', 'brand', 'size_label', 'cost_aed', 'profit_toman', 'badges', 'is_active', 'stock_qty'], [
    ['name_fa' => 'اکسل وی', 'brand' => 'EXCELTEST', 'size_label' => '900 گرم', 'cost_aed' => '41', 'profit_toman' => '1500000',
     'badges' => '-', 'is_active' => 'بله', 'stock_qty' => '-'],
    ['name_fa' => 'اکسل وی', 'brand' => 'EXCELTEST', 'size_label' => '2270 گرم', 'cost_aed' => '96', 'profit_toman' => '2000000'],
]);
$read = import_read_file($tmp);
import_apply(import_plan($read['rows'], $read['columns'])['products'], $admin);
$w4 = db_one('SELECT badges, is_active FROM products WHERE id = ?', [(int) $w['id']]);
$s4 = db_all('SELECT stock_qty FROM product_sizes WHERE product_id = ? ORDER BY sort_order', [(int) $w['id']]);
ok('badges cleared', $w4['badges'] === '');
ok('shown again', (int) $w4['is_active'] === 1);
ok('the dashed size stops being counted, the other keeps 7', $s4[0]['stock_qty'] === null && (int) $s4[1]['stock_qty'] === 7);

head('a picture per flavour («عکس طعم‌ها»)');
ok('the cell: «طعم: فایل» a line each, «=» too, a line without either skipped',
   import_flavor_photos("شکلاتی: XL-Choc.jpg\nوانیلی = xl-van.jpg\n\nبی‌نشان") === ['شکلاتی' => 'xl-choc.jpg', 'وانیلی' => 'xl-van.jpg'],
   json_encode(import_flavor_photos("شکلاتی: XL-Choc.jpg\nوانیلی = xl-van.jpg\n\nبی‌نشان"), JSON_UNESCAPED_UNICODE));
foreach (['xl-choc.jpg', 'xl-van.jpg', 'xl-shared.jpg'] as $n) {
    db_query('INSERT INTO import_photos (name_key, original_name, path) VALUES (?, ?, ?)',
             [$n, $n, '/uploads/products/test/' . $n]);
}
$flavorSheet = [
    ['name_fa' => 'اکسل طعم', 'brand' => 'EXCELTEST', 'category' => 'پروتئین', 'cost_aed' => '30',
     'profit_toman' => '900000', 'flavors' => 'شکلاتی، وانیلی، موزی، لیمویی', 'images' => 'xl-shared.jpg',
     'flavor_images' => "شکلاتی: xl-choc.jpg\nوانیلی: xl-van\nموزی: xl-shared.jpg\nلیمویی: xl-nowhere.jpg\nتوت: xl-van.jpg"],
];
workbook(['name_fa', 'brand', 'category', 'cost_aed', 'profit_toman', 'flavors', 'images', 'flavor_images'], $flavorSheet);
$read = import_read_file($tmp);
ok('the heading is read', in_array('flavor_images', $read['columns'], true), json_encode($read['columns']));
$fp = import_plan($read['rows'], $read['columns']);
$fw = implode(' | ', $fp['warnings']);
$st = $fp['products'][0]['flavor_photo_status'] ?? [];
ok('it plans without errors', $fp['errors'] === [], implode(' | ', $fp['errors']));
ok('found: by name, without its extension, and the gallery\'s own photograph',
   ($st['شکلاتی'] ?? '') === 'ready' && ($st['وانیلی'] ?? '') === 'ready' && ($st['موزی'] ?? '') === 'ready',
   json_encode($st, JSON_UNESCAPED_UNICODE));
ok('a file nobody uploaded is named in a warning', mb_strpos($fw, 'xl-nowhere.jpg') !== false && ($st['لیمویی'] ?? '') === 'missing', $fw);
ok('so is a flavour the product does not have', mb_strpos($fw, '«توت»') !== false, $fw);
$fr = import_apply($fp['products'], $admin);
$fid = (int) db_value("SELECT id FROM products WHERE name_fa = 'اکسل طعم' AND brand = 'EXCELTEST'");
$fimg = [];
foreach (db_all('SELECT name, image FROM product_flavors WHERE product_id = ? ORDER BY sort_order', [$fid]) as $f) {
    $fimg[$f['name']] = $f['image'];
}
ok('each flavour wears its own picture; the one without a file has none',
   $fimg === ['شکلاتی' => '/uploads/products/test/xl-choc.jpg', 'وانیلی' => '/uploads/products/test/xl-van.jpg',
              'موزی' => '/uploads/products/test/xl-shared.jpg', 'لیمویی' => ''], json_encode($fimg, JSON_UNESCAPED_UNICODE));
ok('counted with the gallery: one photograph and three flavours', $fr['photos'] === 4, json_encode($fr));
ok('the gallery still has its one photograph',
   (int) db_value('SELECT COUNT(*) FROM product_images WHERE product_id = ?', [$fid]) === 1);
ok('the used files no longer wait',
   (int) db_value("SELECT COUNT(*) FROM import_photos WHERE name_key IN ('xl-choc.jpg', 'xl-van.jpg', 'xl-shared.jpg')") === 0);
$read = import_read_file($tmp);
$fp2 = import_plan($read['rows'], $read['columns']);
$st2 = $fp2['products'][0]['flavor_photo_status'] ?? [];
ok('the same sheet again: the pictures it gave are recognised, without a warning',
   ($st2['شکلاتی'] ?? '') === 'attached' && ($st2['وانیلی'] ?? '') === 'attached' && ($st2['موزی'] ?? '') === 'attached'
   && mb_strpos(implode(' | ', $fp2['warnings']), 'xl-choc') === false, json_encode($st2, JSON_UNESCAPED_UNICODE));
ok('and changes nothing', import_apply($fp2['products'], $admin)['photos'] === 0);
db_query('INSERT INTO import_photos (name_key, original_name, path) VALUES (?, ?, ?)',
         ['xl-choc.jpg', 'xl-choc.jpg', '/uploads/products/test/xl-choc-2.jpg']);
$fp3 = import_plan(import_read_file($tmp)['rows'], $read['columns']);
ok('a photograph uploaded again under the name is ready', ($fp3['products'][0]['flavor_photo_status']['شکلاتی'] ?? '') === 'ready');
import_apply($fp3['products'], $admin);
ok('and replaces the flavour\'s picture', db_value("SELECT image FROM product_flavors WHERE product_id = ? AND name = 'شکلاتی'", [$fid])
   === '/uploads/products/test/xl-choc-2.jpg');
variants_adopt($fid);
db_query('INSERT INTO import_photos (name_key, original_name, path) VALUES (?, ?, ?)',
         ['xl-van.jpg', 'xl-van.jpg', '/uploads/products/test/xl-van-2.jpg']);
import_apply(import_plan(import_read_file($tmp)['rows'], $read['columns'])['products'], $admin);
$vimg = db_value("SELECT v.image FROM product_attribute_values v JOIN product_attributes a ON a.id = v.attribute_id
                   WHERE a.product_id = ? AND v.label = 'وانیلی'", [$fid]);
ok('a product priced by combinations shows it too (its flavour value)', $vimg === '/uploads/products/test/xl-van-2.jpg', (string) $vimg);

head('what the sheet gets wrong is refused, by row');
workbook(['name_fa', 'brand', 'cost_aed', 'profit_toman', 'badges', 'stock_qty', 'sort_order', 'flavors'], [
    ['name_fa' => 'غلط یک', 'brand' => 'EXCELTEST', 'cost_aed' => '10', 'profit_toman' => '100000', 'badges' => 'فوق‌العاده'],
    ['name_fa' => 'غلط دو', 'brand' => 'EXCELTEST', 'cost_aed' => '10', 'profit_toman' => '100000', 'stock_qty' => 'ده'],
    ['name_fa' => 'غلط سه', 'brand' => 'EXCELTEST', 'cost_aed' => '10', 'profit_toman' => '100000', 'flavors' => 'وانیلی=150000'],
    ['name_fa' => 'غلط چهار', 'brand' => 'EXCELTEST', 'cost_aed' => '10', 'profit_toman' => '100000', 'sort_order' => 'اول'],
]);
$read = import_read_file($tmp);
$bad = import_plan($read['rows'], $read['columns']);
$errs = implode(' | ', $bad['errors']);
ok('an unknown badge, on its row', strpos($errs, 'سطر 2') !== false && mb_strpos($errs, 'فوق‌العاده') !== false, $errs);
ok('a stock that is not a number', strpos($errs, 'سطر 3') !== false, $errs);
ok('a toman-sized premium on a dirham product', mb_strpos($errs, 'به تومان نوشته شده') !== false, $errs);
ok('a display order that is not a number', strpos($errs, 'سطر 5') !== false, $errs);
workbook(['name_fa', 'brand', 'cost_toman', 'profit_toman', 'flavors'], [
    ['name_fa' => 'غلط پنج', 'brand' => 'EXCELTEST', 'cost_toman' => '500000', 'profit_toman' => '100000', 'flavors' => 'لیمو=2'],
]);
$read = import_read_file($tmp);
$bad2 = import_plan($read['rows'], $read['columns']);
ok('a dirham-sized premium on a toman product', mb_strpos(implode(' | ', $bad2['errors']), 'به درهم نوشته شده') !== false,
   implode(' | ', $bad2['errors']));

/* --------------------------------------------------------------------------
   The workbook the panel hands out
   -------------------------------------------------------------------------- */
head('the template');
file_put_contents($tmp, import_template_xlsx());
$t = xlsx_read($tmp, [IMPORT_SHEET]);
ok('its fill-in sheet is first and empty below the heading', $t['sheet'] === IMPORT_SHEET && count($t['rows']) === 1
   && $t['sheets'] === [IMPORT_SHEET, IMPORT_SHEET_EXAMPLE, IMPORT_SHEET_GUIDE, IMPORT_SHEET_LISTS], json_encode($t['sheets'], JSON_UNESCAPED_UNICODE));
ok('every layout column has its heading', count($t['rows'][1]) === count(IMPORT_LAYOUT));
$known = 0;
foreach ($t['rows'][1] as $h) { $known += isset(import_header_map()[import_header_key($h)]) ? 1 : 0; }
ok('and every heading is one the import reads', $known === count(IMPORT_LAYOUT));
$layoutKeys = array_column(IMPORT_LAYOUT, 'key');
ok('the layout covers every column the import reads', count(array_diff(array_keys(IMPORT_COLUMNS), $layoutKeys)) === 0,
   implode(',', array_diff(array_keys(IMPORT_COLUMNS), $layoutKeys)));
$hintsOk = true;
foreach (IMPORT_LAYOUT as $col) { $hintsOk = $hintsOk && $col['hint'] !== '' && mb_strlen($col['hint']) <= 255; }
ok('every column has a hint Excel can show (≤ 255)', $hintsOk);
$ex = xlsx_read($tmp, [IMPORT_SHEET_EXAMPLE]);
$exRows = [];
foreach ($ex['rows'] as $n => $cells) {
    if ($n === 1) { continue; }
    $row = ['_line' => $n];
    foreach ($layoutKeys as $i => $k) { $row[$k] = (string) ($cells[$i] ?? ''); }
    $exRows[] = $row;
}
$exPlan = import_plan($exRows, $layoutKeys);
ok('its worked example plans without errors', $exPlan['errors'] === [], implode(' | ', $exPlan['errors']));

head('today\'s catalogue, out and back in');
file_put_contents($tmp, import_catalogue_xlsx());
$read = import_read_file($tmp);
$rt = import_plan($read['rows'], $read['columns']);
ok('the export reads back with no errors', $read['error'] === null && $rt['errors'] === [], implode(' | ', $rt['errors']));
ok('one planned product per product', count($rt['products']) === (int) db_value('SELECT COUNT(*) FROM products'),
   count($rt['products']) . ' vs ' . db_value('SELECT COUNT(*) FROM products'));
$flCell = '';
foreach ($read['rows'] as $row) { if (($row['name_fa'] ?? '') === 'اکسل وی' && ($row['flavors'] ?? '') !== '') { $flCell = $row['flavors']; } }
ok('a dirham premium is exported in dirhams', mb_strpos($flCell, 'وانیلی=2') !== false, $flCell);
$crRow = null;
foreach ($read['rows'] as $row) { if (($row['name_fa'] ?? '') === 'اکسل کراتین') { $crRow = $row; } }
ok('a toman premium in toman, and the count', $crRow !== null && mb_strpos($crRow['flavors'], 'لیمو=150000') !== false
   && $crRow['stock_qty'] === '9', json_encode($crRow, JSON_UNESCAPED_UNICODE));

head('the panel page');
$page = (string) file_get_contents(SUPPEX_ROOT . '/admin/import.php');
ok('it hands out the template and the catalogue', strpos($page, '?template=xlsx') !== false && strpos($page, '?export=xlsx') !== false);
ok('photographs go up one request each, with the form token', strpos($page, "fd.append('_csrf', tokenEl.value)") !== false
   && strpos($page, "fd.append('action', 'photo')") !== false);
ok('every form on it carries the token', substr_count($page, '<?= csrf_field() ?>') === 4);
ok('it checks the login first', strpos($page, '$user = auth_require();') !== false);
$layout = (string) file_get_contents(SUPPEX_ROOT . '/admin/partials/layout.php');
ok('the menu calls it «ورود از اکسل»', strpos($layout, "'label' => 'ورود از اکسل'") !== false);

/* Tidy: the test products, and the photograph rows left waiting. */
foreach (db_all("SELECT id FROM products WHERE brand IN ('EXCELTEST', 'TESTX')") as $p) {
    db_query('DELETE FROM products WHERE id = ?', [(int) $p['id']]);
}
db_query("DELETE FROM import_photos WHERE name_key LIKE 'xl-%'");
@unlink($tmp);
@unlink($tmpDir . '/old.xls');
@unlink($tmpDir . '/h.xlsx');

echo "\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail ? 1 : 0);
