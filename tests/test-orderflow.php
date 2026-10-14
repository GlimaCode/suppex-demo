<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/* ===========================================================================
   From the «+» on a card to the order on the server (review of 2026-10-02).
   ---------------------------------------------------------------------------
   Each block is a way the purchase could fail that a customer met, or could
   have met, on the live shop:

   - an order for most of the Rule 1 whey's combinations was refused outright,
     because the order line could not hold the combination's key;
   - a card's «+» put a product priced by combination into the basket with no
     combination, and the basket called it unavailable;
   - a line the server could not price vanished from an order the chat
     message still listed;
   - an order with its receipt in was expired by the sweep;
   - and one product's Excel, which did not exist.
   =========================================================================== */

$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = 'suppex.ir';
$_SERVER['REQUEST_METHOD'] = 'GET';

$pass = 0; $fail = 0; $out = [];
function ok(string $l, bool $c, ?string $x = ''): void {
    global $pass, $fail, $out;
    if ($c) { $pass++; $out[] = "  ok   $l"; }
    else { $fail++; $out[] = "  FAIL $l" . ($x ? "  --  $x" : ''); }
}
function head(string $t): void { global $out; $out[] = "\n== $t =="; }

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/migrate.php';
require_once SUPPEX_ROOT . '/lib/products.php';
require_once SUPPEX_ROOT . '/lib/orders.php';
require_once SUPPEX_ROOT . '/lib/track.php';
require_once SUPPEX_ROOT . '/lib/quotes.php';
require_once SUPPEX_ROOT . '/lib/variants.php';
require_once SUPPEX_ROOT . '/lib/receipts.php';
require_once SUPPEX_ROOT . '/lib/import_sheet.php';
require_once SUPPEX_ROOT . '/lib/order_messages.php';

/* The route, in its own process: it sends and exits. */
function api_post(string $route, array $body, string $ip = '127.0.0.9'): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/apipost.php') . ' '
         . escapeshellarg($route) . ' ' . escapeshellarg(base64_encode((string) json_encode($body, JSON_UNESCAPED_UNICODE)))
         . ' ' . escapeshellarg($ip);
    $raw = (string) shell_exec($cmd);
    $json = json_decode(substr($raw, (int) strpos($raw, '{')), true);
    return is_array($json) ? $json : ['_raw' => $raw];
}

/* A panel page as a signed-in admin sees it (tests/adminview.php). */
function admin_view(string $page, array $get): string
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/adminview.php') . ' '
         . escapeshellarg($page) . ' ' . escapeshellarg(base64_encode((string) json_encode($get)));
    return (string) shell_exec($cmd);
}

$log = [];
migrate_run($log);

$clean = static function (): void {
    foreach (db_all("SELECT id FROM products WHERE slug LIKE 'zz-flow%'") as $r) {
        $id = (int) $r['id'];
        db_query('DELETE vv FROM product_variant_values vv
                    JOIN product_variants v ON v.id = vv.variant_id WHERE v.product_id = ?', [$id]);
        db_query('DELETE FROM product_variants WHERE product_id = ?', [$id]);
        db_query('DELETE av FROM product_attribute_values av
                    JOIN product_attributes a ON a.id = av.attribute_id WHERE a.product_id = ?', [$id]);
        db_query('DELETE FROM product_attributes WHERE product_id = ?', [$id]);
        db_query('DELETE FROM product_sizes WHERE product_id = ?', [$id]);
        db_query('DELETE FROM product_flavors WHERE product_id = ?', [$id]);
    }
    foreach (db_all("SELECT id FROM orders WHERE phone = '09120007788'") as $o) {
        db_query('DELETE FROM order_receipts WHERE order_id = ?', [(int) $o['id']]);
        db_query('DELETE FROM order_events WHERE order_id = ?', [(int) $o['id']]);
        db_query('DELETE FROM order_items WHERE order_id = ?', [(int) $o['id']]);
        db_query('DELETE FROM orders WHERE id = ?', [(int) $o['id']]);
    }
    db_query("DELETE FROM products WHERE slug LIKE 'zz-flow%'");
    db_query('DELETE FROM login_attempts WHERE username IN (?, ?)', [ORDER_THROTTLE_USER, TRACK_THROTTLE_USER]);
};
$clean();

$customer = ['name' => 'آزمون مسیر خرید', 'phone' => '09120007788',
             'address' => 'تهران، خیابان آزمون، کوچه دوم، پلاک ۴', 'postal' => '1234567890'];

/* A whey the way the import names one: a size label and a flavour name long
   enough that each key is near its 60 characters - which is what the Rule 1
   whey's are on the live shop. */
$wheyId = db_insert(
    "INSERT INTO products (slug, name_fa, price, price_mode, is_active, in_stock)
     VALUES ('zz-flow-whey', 'زد وی مسیر', 4200000, 'manual', 1, 1)");
$sizeExt  = str_repeat('s', 30) . '-۲-پوندی-۹۱۰-گرم';
$flavExt  = str_repeat('f', 30) . '-chocolate-peanut-butter-blast';
db_query("INSERT INTO product_sizes (product_id, ext_id, label, price, sort_order)
          VALUES (?, ?, '۲ پوندی (۹۱۰ گرم)', 4200000, 0)", [$wheyId, $sizeExt]);
db_query("INSERT INTO product_flavors (product_id, ext_id, name, price_delta_toman, sort_order)
          VALUES (?, ?, 'Chocolate Peanut Butter', 0, 0)", [$wheyId, $flavExt]);
variants_adopt($wheyId);
$wheyKey = (string) db_value('SELECT ext_id FROM product_variants WHERE product_id = ?', [$wheyId]);

head('an order line holds a combination\'s whole key');
ok('the migration widened the order line', migrate_column_length('order_items', 'size_ext') >= 160
   && migrate_column_length('order_items', 'stock_from') >= 160,
   migrate_column_length('order_items', 'size_ext') . ' / ' . migrate_column_length('order_items', 'stock_from'));
ok('  and says so on the readiness list', (migrate_pending()['order_items.size_ext 160'] ?? false) === true);
ok('the key in this test is longer than the old column', mb_strlen($wheyKey) > 60, (string) mb_strlen($wheyKey));
$long = order_create(['items' => [['slug' => 'zz-flow-whey', 'sizeId' => $sizeExt, 'flavorId' => $flavExt, 'qty' => 1]],
                      'customer' => $customer, 'channel' => 'telegram']);
ok('so the order goes through', !empty($long['ok']), json_encode($long['errors'] ?? [], JSON_UNESCAPED_UNICODE));
$longLine = $long['ok'] ? ($long['order']['items'][0] ?? []) : [];
ok('  carrying the key whole', (string) ($longLine['size_ext'] ?? '') === $wheyKey,
   (string) ($longLine['size_ext'] ?? ''));

head('what a card\'s «+» may add by itself');
$listed = [];
foreach (products_list(['limit' => 200]) as $p) { $listed[$p['slug']] = $p; }
$w = $listed['zz-flow-whey'] ?? null;
ok('the list says, for every product', $w !== null && array_key_exists('needsChoice', $w) && array_key_exists('choice', $w));
ok('one size and one flavour: the card can add it', $w !== null && $w['needsChoice'] === false);
ok('  with the ids the order needs',
   ($w['choice']['sizeId'] ?? null) === $sizeExt && ($w['choice']['flavorId'] ?? null) === $flavExt,
   json_encode($w['choice'] ?? null, JSON_UNESCAPED_UNICODE));
ok('  the words the basket line shows, flavour first as on the product page',
   ($w['choice']['label'] ?? '') === 'Chocolate Peanut Butter · ۲ پوندی (۹۱۰ گرم)', (string) ($w['choice']['label'] ?? ''));
ok('  and the combination\'s own price', ($w['choice']['price'] ?? 0) === 4200000);
$fromCard = order_price_line(['slug' => 'zz-flow-whey', 'sizeId' => $w['choice']['sizeId'] ?? '',
                              'flavorId' => $w['choice']['flavorId'] ?? '', 'qty' => 1]);
ok('what the card adds, the server prices - at the same figure',
   $fromCard !== null && (int) $fromCard['unit_price'] === (int) ($w['choice']['price'] ?? -1));
$noChoice = order_price_line(['slug' => 'zz-flow-whey', 'qty' => 1]);
ok('  where the old card\'s line, with no choice at all, could not be priced', $noChoice === null);

/* A second flavour, and the card must send the shopper to the page. */
$flav2 = 'vanilla-' . bin2hex(random_bytes(3));
db_query("INSERT INTO product_flavors (product_id, ext_id, name, price_delta_toman, sort_order)
          VALUES (?, ?, 'وانیلی', 0, 1)", [$wheyId, $flav2]);
variants_adopt($wheyId);
$listed = [];
foreach (products_list(['limit' => 200]) as $p) { $listed[$p['slug']] = $p; }
ok('two flavours: the card sends them to choose', ($listed['zz-flow-whey']['needsChoice'] ?? null) === true
   && array_key_exists('choice', $listed['zz-flow-whey'] ?? []) && $listed['zz-flow-whey']['choice'] === null);

/* One combination switched off leaves one on sale, but two flavours are still
   two to choose between on the page - the card does not decide for them. */
db_query("UPDATE product_variants SET is_active = 0 WHERE product_id = ? AND ext_id LIKE ?", [$wheyId, '%' . $flav2]);
$listed = [];
foreach (products_list(['limit' => 200]) as $p) { $listed[$p['slug']] = $p; }
ok('  even with only one of them on sale', ($listed['zz-flow-whey']['needsChoice'] ?? null) === true);

/* The older model: a product with no combinations. */
$plainId = db_insert(
    "INSERT INTO products (slug, name_fa, price, price_mode, is_active, in_stock)
     VALUES ('zz-flow-plain', 'زد ساده مسیر', 1300000, 'manual', 1, 1)");
$sizedId = db_insert(
    "INSERT INTO products (slug, name_fa, price, price_mode, is_active, in_stock)
     VALUES ('zz-flow-sized', 'زد اندازه‌دار مسیر', 900000, 'manual', 1, 1)");
db_query("INSERT INTO product_sizes (product_id, ext_id, label, price, sort_order)
          VALUES (?, 'one', '۳۰۰ گرم', 1450000, 0)", [$sizedId]);
$premId = db_insert(
    "INSERT INTO products (slug, name_fa, price, price_mode, is_active, in_stock)
     VALUES ('zz-flow-prem', 'زد طعم گران مسیر', 900000, 'manual', 1, 1)");
db_query("INSERT INTO product_flavors (product_id, ext_id, name, price_delta_toman, sort_order)
          VALUES (?, 'mango', 'انبه', 150000, 0)", [$premId]);
$listed = [];
foreach (products_list(['limit' => 200]) as $p) { $listed[$p['slug']] = $p; }
ok('a product with nothing to choose is added as it is',
   ($listed['zz-flow-plain']['needsChoice'] ?? null) === false
   && is_array($listed['zz-flow-plain']['choice'] ?? null)
   && $listed['zz-flow-plain']['choice']['sizeId'] === null
   && ($listed['zz-flow-plain']['choice']['price'] ?? 0) === 1300000);
ok('one size: added at the size\'s price, which is what the server charges',
   ($listed['zz-flow-sized']['choice']['sizeId'] ?? '') === 'one'
   && ($listed['zz-flow-sized']['choice']['price'] ?? 0) === 1450000
   && (int) (order_price_line(['slug' => 'zz-flow-sized', 'sizeId' => 'one', 'qty' => 1])['unit_price'] ?? 0) === 1450000);
ok('a flavour that costs extra still asks first', ($listed['zz-flow-prem']['needsChoice'] ?? null) === true);

head('a line the server cannot price refuses the order, by name');
$mixed = order_create(['items' => [
        ['slug' => 'zz-flow-plain', 'qty' => 1],
        ['slug' => 'zz-flow-sized', 'sizeId' => 'gone-size', 'qty' => 1],
    ], 'customer' => $customer, 'channel' => 'telegram']);
ok('not recorded short of a line', empty($mixed['ok']));
ok('  and the shopper is told which', mb_strpos((string) ($mixed['errors']['items'] ?? ''), 'زد اندازه‌دار مسیر') !== false,
   (string) ($mixed['errors']['items'] ?? ''));
ok('nothing written for it', (int) db_value("SELECT COUNT(*) FROM orders WHERE phone = '09120007788'") === 1);

head('the same basket in another colour is another order');
ok('custom options are part of the duplicate signature',
   order_items_hash([['slug' => 'x', 'sizeId' => 'a', 'qty' => 1, 'attrs' => ['colour' => 'red']]])
   !== order_items_hash([['slug' => 'x', 'sizeId' => 'a', 'qty' => 1, 'attrs' => ['colour' => 'blue']]]));
ok('  in any order they were written',
   order_items_hash([['slug' => 'x', 'qty' => 1, 'attrs' => ['a' => '1', 'b' => '2']]])
   === order_items_hash([['slug' => 'x', 'qty' => 1, 'attrs' => ['b' => '2', 'a' => '1']]]));
ok('  and a basket without any hashes as it always did',
   order_items_hash([['slug' => 'x', 'sizeId' => 'a', 'flavorId' => 'f', 'qty' => 2]])
   === substr(hash('sha256', 'x|a|f|2'), 0, 40));

head('a receipt keeps the order open');
$withReceipt = order_create(['items' => [['slug' => 'zz-flow-plain', 'qty' => 2]],
                             'customer' => $customer, 'channel' => 'telegram']);
$noReceipt   = order_create(['items' => [['slug' => 'zz-flow-plain', 'qty' => 3]],
                             'customer' => $customer, 'channel' => 'telegram']);
$wrId = (int) ($withReceipt['order']['id'] ?? 0);
$nrId = (int) ($noReceipt['order']['id'] ?? 0);
db_query('UPDATE orders SET expires_at = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE id IN (?, ?)', [$wrId, $nrId]);
db_query("INSERT INTO order_receipts (order_id, file) VALUES (?, 'zz-flow-test.jpg')", [$wrId]);
orders_expire_due();
ok('the paid-for one is still waiting for its confirmation',
   (string) db_value('SELECT status FROM orders WHERE id = ?', [$wrId]) === 'new');
ok('the unpaid one ran out as before',
   (string) db_value('SELECT status FROM orders WHERE id = ?', [$nrId]) === 'expired');

head('the tracking code as a Persian keyboard types it');
$code = (string) ($withReceipt['order']['code'] ?? '');
$faCode = strtr($code, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
                        '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
ok('the code has digits to convert', $faCode !== $code);
$foundFa = track_find($faCode, '۰۹۱۲۰۰۰۷۷۸۸');
ok('Persian digits find the order', $foundFa !== null && (string) $foundFa['code'] === $code);
ok('  lower case too', track_find(strtolower($code), '09120007788') !== null);

head('only a miss counts against the lookup');
$trackSrc = (string) file_get_contents(SUPPEX_ROOT . '/track.php');
$hitAt  = strpos($trackSrc, '$order = track_find($code, $phone);');
$missAt = strpos($trackSrc, 'if ($order === null) {', (int) $hitAt);
$recAt  = strpos($trackSrc, 'track_record_attempt();');
ok('track.php records an attempt only inside the miss',
   $hitAt !== false && $missAt !== false && $recAt !== false && $recAt > $missAt
   && substr_count($trackSrc, 'track_record_attempt();') === 1);

head('a basket of combinations is quoted whole');
ok('two hundred lines to a quote', QUOTE_MAX_LINES === 200);
$many = [];
for ($i = 0; $i < 150; $i++) { $many[quote_key('zz-flow-q', 'k' . $i)] = 1000 + $i; }
$tok = quote_issue($many);
ok('  and a quote of 150 keeps the last of them', quote_prices($tok)[quote_key('zz-flow-q', 'k149')] ?? null === 1149);

head('a repeated send gets the order\'s card and clock too');
settings_save(['card_number' => '6219861012345678', 'card_holder' => 'آزمون دارنده', 'bank_name' => 'بانک آزمون']);
db_query('DELETE FROM login_attempts WHERE username = ?', [ORDER_THROTTLE_USER]);
$body = ['items' => [['slug' => 'zz-flow-sized', 'sizeId' => 'one', 'qty' => 1]],
         'customer' => $customer, 'channel' => 'telegram'];
$first  = api_post('order', $body);
$second = api_post('order', $body);
ok('the first send records the order', !empty($first['ok']) && !empty($first['code']), json_encode($first, JSON_UNESCAPED_UNICODE));
ok('the second is the same order', !empty($second['duplicate']) && ($second['code'] ?? '') === ($first['code'] ?? 'x'));
ok('  with the card', ($second['payment']['cardNumber'] ?? '') === '6219861012345678');
ok('  and the clock', !empty($second['expiresAt']) && !empty($second['serverNow']) && !empty($second['holdMinutes']));
db_query("UPDATE orders SET status = 'paid' WHERE code = ?", [(string) ($first['code'] ?? '')]);
$third = api_post('order', $body);
ok('but not for an order that is no longer waiting for money',
   !empty($third['duplicate']) && !isset($third['payment']), json_encode($third, JSON_UNESCAPED_UNICODE));

head('everything sent to a customer is signed (2026-10-03)');
/* The owner asked for «SUPPEX — suppex.ir» on everything a customer gets: the
   messages in the chat, the invoice in the box, the label on the parcel. */
$signKeep = ['site_url' => (string) setting('site_url', ''), 'shop_name' => (string) setting('shop_name', '')];
settings_save(['site_url' => 'https://www.suppex.ir', 'shop_name' => 'SUPPEX']);
ok('the sign-off is the shop\'s name and its address, without a www', brand_signature() === 'SUPPEX — suppex.ir',
   brand_signature());
$signedId = (int) db_value('SELECT id FROM orders WHERE code = ?', [(string) ($first['code'] ?? '')]);
db_query("UPDATE orders SET status = 'shipped', tracking_code = '123456789012345678901234' WHERE id = ?", [$signedId]);
$signed = order_get($signedId);
$msgs = $signed ? order_messages($signed) : [];
ok('the message for where the order stands comes first', ($msgs[0]['key'] ?? '') === 'shipped' && ($msgs[0]['now'] ?? false) === true,
   json_encode(array_column($msgs, 'key')));
ok('  with the parcel\'s tracking code in it', mb_strpos((string) ($msgs[0]['text'] ?? ''), '123456789012345678901234') !== false);
ok('  and where to look the order up', mb_strpos((string) ($msgs[0]['text'] ?? ''), 'suppex.ir/track.php') !== false);
$allSigned = $msgs !== [];
foreach ($msgs as $msg) {
    $allSigned = $allSigned && str_ends_with((string) $msg['text'], 'SUPPEX — suppex.ir');
}
ok('every message ends with the sign-off', $allSigned);
ok('the order\'s own text too', $signed !== null && str_ends_with(order_message_details($signed), 'SUPPEX — suppex.ir'));
$orderPage = admin_view('order.php', ['id' => $signedId]);
ok('the order page offers the messages to copy', strpos($orderPage, 'پیام آماده برای مشتری') !== false
   && strpos($orderPage, 'data-copy-msg="msg-shipped"') !== false, mb_substr(strip_tags($orderPage), 0, 200));
ok('  and the label and invoice to print', strpos($orderPage, 'order-print.php?id=' . $signedId) !== false);
$printPage = admin_view('order-print.php', ['id' => $signedId]);
ok('the print has the label: who it goes to, from whom',
   strpos($printPage, 'آزمون مسیر خرید') !== false && strpos($printPage, '1234567890') !== false
   && strpos($printPage, 'فرستنده') !== false, mb_substr(strip_tags($printPage), 0, 200));
ok('  and the invoice, with the order\'s lines', strpos($printPage, 'فاکتور سفارش') !== false
   && strpos($printPage, 'زد اندازه‌دار مسیر') !== false);
ok('  both signed', substr_count($printPage, 'SUPPEX — suppex.ir') >= 2);
$printSrc = (string) file_get_contents(SUPPEX_ROOT . '/admin/order-print.php');
ok('  and nothing of it without a login',
   strpos($printSrc, '$user = auth_require();') !== false
   && strpos($printSrc, '$user = auth_require();') < strpos($printSrc, 'order_get('));
settings_save($signKeep);

head('one product\'s Excel');
$sheetFile = sys_get_temp_dir() . '/zz-flow-one.xlsx';
file_put_contents($sheetFile, import_catalogue_xlsx($sizedId));
$read = import_read_file($sheetFile);
$plan = import_plan($read['rows'], $read['columns']);
ok('it holds that product and no other', $read['error'] === null && count($plan['products']) === 1
   && ($plan['products'][0]['name_fa'] ?? '') === 'زد اندازه‌دار مسیر',
   count($plan['products']) . ' ' . implode(' | ', $plan['errors']));
ok('  and goes back in without an error', $plan['errors'] === [], implode(' | ', $plan['errors']));
$sellingBook = xlsx_read($sheetFile, [IMPORT_SHEET_SELLING]);
$sellingText = json_encode($sellingBook['rows'] ?? [], JSON_UNESCAPED_UNICODE);
ok('beside it, what it sells for today', mb_strpos($sellingText, 'زد اندازه‌دار مسیر') !== false
   && mb_strpos($sellingText, '1450000') !== false, mb_substr($sellingText, 0, 300));
$whole = sys_get_temp_dir() . '/zz-flow-all.xlsx';
file_put_contents($whole, import_catalogue_xlsx());
$wholeRead = import_read_file($whole);
ok('the whole catalogue is still every product',
   count(import_plan($wholeRead['rows'], $wholeRead['columns'])['products'])
   === (int) db_value('SELECT COUNT(*) FROM products'));
ok('the file is named after the product',
   import_export_filename(['id' => 7, 'slug' => 'Rule-1 Whey/2lb']) === 'suppex-product-rule-1-whey-2lb-' . date('Y-m-d') . '.xlsx');
ok('  or numbered when its slug has no letters a file name can carry',
   import_export_filename(['id' => 7, 'slug' => 'وی']) === 'suppex-product-7-' . date('Y-m-d') . '.xlsx');

/* A product priced by combination: its sizes keep the figures they had when it
   was converted, and the sheet's price column is per size. The selling sheet
   is where its real price is, and re-importing its own export must leave the
   card on the cheapest combination rather than the stale size. */
db_query('UPDATE product_variants SET price = 5100000, is_active = 1 WHERE product_id = ?', [$wheyId]);
db_query('UPDATE products SET price = 5100000 WHERE id = ?', [$wheyId]);
file_put_contents($sheetFile, import_catalogue_xlsx($wheyId));
$sellingText = json_encode(xlsx_read($sheetFile, [IMPORT_SHEET_SELLING])['rows'] ?? [], JSON_UNESCAPED_UNICODE);
ok('a converted product\'s sheet shows the combinations\' price, not the size\'s',
   mb_strpos($sellingText, '5100000') !== false && mb_strpos($sellingText, '4200000') === false, mb_substr($sellingText, 0, 300));
$read = import_read_file($sheetFile);
$plan = import_plan($read['rows'], $read['columns']);
$applied = import_apply($plan['products'], ['id' => null, 'name' => 'test']);
ok('  its own file goes back in', $plan['errors'] === [] && !empty($applied), implode(' | ', $plan['errors']));
ok('  and the card stays on the combination\'s price',
   (int) db_value('SELECT price FROM products WHERE id = ?', [$wheyId]) === 5100000,
   (string) db_value('SELECT price FROM products WHERE id = ?', [$wheyId]));
ok('  which the combinations kept', (int) db_value('SELECT MIN(price) FROM product_variants WHERE product_id = ?', [$wheyId]) === 5100000);

head('the panel offers it');
$listSrc = (string) file_get_contents(SUPPEX_ROOT . '/admin/products.php');
$editSrc = (string) file_get_contents(SUPPEX_ROOT . '/admin/product-edit.php');
$impSrc  = (string) file_get_contents(SUPPEX_ROOT . '/admin/import.php');
ok('on every row of the product list', strpos($listSrc, 'import.php?export=xlsx&amp;product=<?= $pid ?>') !== false);
ok('  and on the product\'s own page', strpos($editSrc, 'import.php?export=xlsx&amp;product=<?= $id ?>') !== false);
ok('the download is behind the login like the rest of the page',
   strpos($impSrc, '$user = auth_require();') !== false
   && strpos($impSrc, '$user = auth_require();') < strpos($impSrc, "\$oneId = (int) (\$_GET['product'] ?? 0);"));

@unlink($sheetFile);
@unlink($whole);
$clean();
ok('fixtures cleaned up', (int) db_value("SELECT COUNT(*) FROM products WHERE slug LIKE 'zz-flow%'") === 0
   && (int) db_value("SELECT COUNT(*) FROM orders WHERE phone = '09120007788'") === 0);

echo implode("\n", $out);
echo "\n\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
