<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/* ===========================================================================
   After the order: the receipt, and «سفارش‌های من».
   ---------------------------------------------------------------------------
   A receipt is a card number, a name and an amount - the most private file
   the shop holds - so most of what is held here is about where it may and
   may not end up: never in a public folder, never readable without a login,
   never for an order the sender did not prove is theirs.

   «سفارش‌های من» answers for many orders in one request, which in the wrong
   hands is many guesses in one request; so the throttle rules are held here
   too.

   The upload itself cannot be made from the command line - is_uploaded_file()
   is false for any file that did not arrive over HTTP - so receipt_save() is
   tested directly, and the route is driven through tests/apipost.php up to
   the point where it would read the file.
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
require_once SUPPEX_ROOT . '/lib/orders.php';
require_once SUPPEX_ROOT . '/lib/track.php';
require_once SUPPEX_ROOT . '/lib/receipts.php';

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
/* The photographs tests/faketg.php was sent, by chat - in the order sent. */
function standin_photos(string $log): array
{
    $chats = [];
    foreach (is_file($log) ? (file($log, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
        $call = json_decode($line, true);
        if (is_array($call) && ($call['method'] ?? '') === 'sendPhoto' && !empty($call['photo'])) {
            $chats[] = (string) $call['chat'];
        }
    }
    return $chats;
}
/* receipt_notify() in its own process, under the config that points Telegram
   at the stand-in, with $chat as the bot's chat_id. */
function notify_via_standin(int $receiptId, string $chat, int $port): array
{
    $env = getenv();
    $env['SUPPEX_CONFIG']      = __DIR__ . '/test-config-tg.php';
    $env['SUPPEX_FAKETG']      = 'http://127.0.0.1:' . $port;
    $env['SUPPEX_FAKETG_CHAT'] = $chat;
    $proc = proc_open([PHP_BINARY, __DIR__ . '/notifyonce.php', (string) $receiptId],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__, $env);
    $text = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $json = json_decode($text, true);
    return is_array($json) ? $json : ['_raw' => $text];
}
$clearThrottles = static function (): void {
    db_query("DELETE FROM login_attempts WHERE username IN (?, ?)", [TRACK_THROTTLE_USER, RECEIPT_THROTTLE_USER]);
};
$clearThrottles();

/* Two real orders on one phone, placed the way the API places them. */
$phone = '09121112233';
$made = [];
foreach ([['whey-original', 1], ['whey-original', 2]] as [$slug, $qty]) {
    $r = order_create([
        'customer' => ['name' => 'آزمون رسید', 'phone' => $phone,
                       'address' => 'تهران، خیابان نمونه، پلاک ۲', 'postal' => '1234567890'],
        'items'    => [['slug' => $slug, 'sizeId' => '900', 'qty' => $qty]],
        'channel'  => 'telegram',
    ]);
    $made[] = $r['order'] ?? null;
}
[$orderA, $orderB] = $made;
ok('two test orders placed', $orderA !== null && $orderB !== null,
   json_encode(array_map(static fn($m) => $m === null ? null : $m['code'], $made)));

head('the table is in place, and receipts live outside the site');
ok('the migration made order_receipts', receipts_ready());
ok('the suite writes its receipts to its own folder', receipts_dir() === rtrim((string) suppex_config()['private_dir'], '/\\') . '/receipts',
   receipts_dir());
ok('which is not the public uploads folder',
   strpos(str_replace('\\', '/', receipts_dir()), str_replace('\\', '/', uploads_dir())) !== 0);

head('what is stored is a picture and nothing else');
$tmp = sys_get_temp_dir() . '/suppex-receipt-test.png';
$im = imagecreatetruecolor(1600, 3200);
imagesavealpha($im, true);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagefilledrectangle($im, 200, 400, 1400, 900, imagecolorallocate($im, 20, 120, 60));
imagepng($im, $tmp);
imagedestroy($im);
$saved = receipt_save($tmp, (int) filesize($tmp));
ok('a screenshot is accepted', $saved['ok'] === true, json_encode($saved, JSON_UNESCAPED_UNICODE));
$path = $saved['ok'] ? receipt_path($saved['file']) : null;
ok('  and found again by its name', $path !== null && is_file((string) $path));
$info = $path ? getimagesize($path) : false;
ok('  re-encoded as a JPEG', $info !== false && $info[2] === IMAGETYPE_JPEG);
ok('  no taller than 2000px, in proportion', $info !== false && $info[1] === 2000 && $info[0] === 1000,
   $info ? $info[0] . 'x' . $info[1] : '');
if ($path) {
    $g = imagecreatefromjpeg($path);
    $c = imagecolorsforindex($g, imagecolorat($g, 5, 5));
    ok('  and what was transparent is white, not black', $c['red'] > 245 && $c['green'] > 245 && $c['blue'] > 245,
       json_encode($c));
    imagedestroy($g);
}
@unlink($tmp);

file_put_contents($tmp, "<?php echo 'x'; ?>" . str_repeat("\0", 64));
$bad = receipt_save($tmp, (int) filesize($tmp));
ok('a file that is not a picture is refused', $bad['ok'] === false && mb_strpos((string) $bad['error'], 'تصویر نیست') !== false);
@unlink($tmp);
ok('an empty file is refused', receipt_save($tmp, 0)['ok'] === false);
ok('so is one over the size limit', receipt_save($tmp, RECEIPT_MAX_BYTES + 1)['ok'] === false);

head('a stored name cannot walk out of the receipts folder');
ok('a name made here resolves', $saved['ok'] && receipt_path($saved['file']) !== null);
foreach (['../../suppex-config.php', '2026/09/../../x.jpg', '2026/09/20260924-101010-ABCDEF0123456789.jpg',
          '2026/09/20260924-101010-abcdef0123456789.php', '/etc/passwd', ''] as $evil) {
    ok('  «' . $evil . '» does not', receipt_path($evil) === null);
}
/* The sharp one: a detour that lands on a real receipt. Only the name's
   exact shape stops it - the file it points at does exist. */
ok('  nor does a detour that lands on a real receipt',
   $saved['ok'] && receipt_path('../receipts/' . $saved['file']) === null);

head('which orders take a receipt');
foreach (['new' => true, 'paid' => true, 'expired' => true,
          'shipped' => false, 'done' => false, 'cancelled' => false] as $st => $want) {
    ok("  $st: " . ($want ? 'yes' : 'no'), receipt_accepts(['status' => $st]) === $want);
}

head('the Telegram caption');
$cap = receipt_caption($orderA, [], 'https://suppex.ir');
ok('names the order and the amount', mb_strpos($cap, $orderA['code']) !== false
   && mb_strpos($cap, money((int) $orderA['total'])) !== false, $cap);
ok('  and links to it in the panel', mb_strpos($cap, 'https://suppex.ir/admin/order.php?id=' . (int) $orderA['id']) !== false);
ok('without a site address there is no link', mb_strpos(receipt_caption($orderA, [], ''), '/admin/') === false);
$long = $orderA;
$long['customer_name'] = str_repeat('نام بلند ', 400);
ok('never over Telegram\'s caption limit', mb_strlen(receipt_caption($long, [], 'https://suppex.ir')) <= 1024);

head('a receipt is filed against its order');
$rid = $saved['ok'] ? receipt_add((int) $orderA['id'], $saved) : 0;
ok('recorded', $rid > 0 && receipt_count((int) $orderA['id']) === 1);
ok('  and listed on that order only', count(receipts_for_order((int) $orderA['id'])) === 1
   && count(receipts_for_order((int) $orderB['id'])) === 0);
/* The suite's config has Telegram switched off: nothing may be sent. */
$sent = receipt_notify($orderA, (array) receipt_get($rid), 'https://suppex.ir');
ok('with Telegram off, nothing is sent and nothing is marked',
   $sent === ['chat' => null, 'group' => null, 'sent' => 0, 'busy' => false]
   && (int) receipt_get($rid)['sent_chat'] === 0, json_encode($sent));

head('the same picture twice is one receipt');
$tmp2 = sys_get_temp_dir() . '/suppex-receipt-twice.png';
$im = imagecreatetruecolor(700, 1400);
imagefill($im, 0, 0, imagecolorallocate($im, 250, 250, 250));
imagefilledrectangle($im, 60, 200, 640, 420, imagecolorallocate($im, 30, 90, 200));
imagepng($im, $tmp2);
imagedestroy($im);
$first = receipt_save($tmp2, (int) filesize($tmp2));
$again = receipt_save($tmp2, (int) filesize($tmp2));
$firstId = $first['ok'] ? receipt_add((int) $orderA['id'], $first) : 0;
ok('the same upload twice comes out as the same file, byte for byte', $first['ok'] && $again['ok']
   && sha1_file((string) receipt_path($first['file'])) === sha1_file((string) receipt_path($again['file'])));
$same = $again['ok'] ? receipt_duplicate_of((int) $orderA['id'], $again['file']) : null;
ok('  and is known for the receipt already filed', $same !== null && (int) $same['id'] === $firstId);
ok('  on that order only', $again['ok'] && receipt_duplicate_of((int) $orderB['id'], $again['file']) === null);
$im = imagecreatetruecolor(700, 1400);
imagefill($im, 0, 0, imagecolorallocate($im, 250, 250, 250));
imagefilledrectangle($im, 60, 200, 640, 420, imagecolorallocate($im, 200, 60, 30));
imagepng($im, $tmp2);
imagedestroy($im);
$other = receipt_save($tmp2, (int) filesize($tmp2));
ok('a different picture is a receipt of its own',
   $other['ok'] && receipt_duplicate_of((int) $orderA['id'], $other['file']) === null);
if ($other['ok']) { receipt_discard($other['file']); }
if ($again['ok']) { receipt_discard($again['file']); }
ok('the copy that is not kept is removed from the disk', $again['ok'] && receipt_path($again['file']) === null);
/* Back to the one receipt the checks below count on. */
if ($firstId) {
    receipt_discard($first['file']);
    db_query('DELETE FROM order_receipts WHERE id = ?', [$firstId]);
}
@unlink($tmp2);

head('Telegram: each chat gets each receipt once');
/* The stand-in, on a port of its own. It records every call and says yes. */
$fakeLog = __DIR__ . '/tmp/faketg.log';
@unlink($fakeLog);
$fakePort = 18600 + random_int(0, 399);
$devNull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
$fake = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $fakePort, __DIR__ . '/faketg.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $devNull, 'w'], 2 => ['file', $devNull, 'w']], $fakePipes, __DIR__);
$fakeUp = false;
for ($i = 0; $i < 50 && !$fakeUp; $i++) {
    usleep(100000);
    $fakeUp = @file_get_contents('http://127.0.0.1:' . $fakePort . '/ping') !== false;
}
ok('the stand-in for Telegram is listening', $fakeUp);
$unsent = static function (int $id): void {
    db_query('UPDATE order_receipts SET sent_chat = 0, sent_group = 0 WHERE id = ?', [$id]);
};
$marks = static function (int $id): string {
    $row = (array) receipt_get($id);
    return (int) ($row['sent_chat'] ?? 0) . (int) ($row['sent_group'] ?? 0);
};
$groupBefore = (string) setting('telegram_group_id', '');

settings_save(['telegram_group_id' => '']);
$unsent($rid);
@unlink($fakeLog);
$n = notify_via_standin($rid, '111222', $fakePort);
ok('the owner\'s chat alone: one photograph, to it', standin_photos($fakeLog) === ['111222']
   && ($n['chat'] ?? null) === true && array_key_exists('group', $n) && $n['group'] === null
   && ($n['sent'] ?? 0) === 1, json_encode($n) . ' ' . implode(',', standin_photos($fakeLog)));

/* What the owner saw: the bot's chat_id is the group itself. */
settings_save(['telegram_group_id' => '-100555']);
$unsent($rid);
@unlink($fakeLog);
$n = notify_via_standin($rid, '-100555', $fakePort);
ok('the group set as the bot\'s chat as well: ONE photograph in it, not two',
   standin_photos($fakeLog) === ['-100555'], implode(',', standin_photos($fakeLog)));
ok('  marked as delivered to both', $marks($rid) === '11'
   && ($n['chat'] ?? null) === true && ($n['group'] ?? null) === true, json_encode($n));

@unlink($fakeLog);
$n = notify_via_standin($rid, '-100555', $fakePort);
ok('a receipt already delivered is not sent again', standin_photos($fakeLog) === []
   && ($n['sent'] ?? -1) === 0 && ($n['chat'] ?? null) === true, json_encode($n));

settings_save(['telegram_group_id' => '-100777']);
$unsent($rid);
db_query('UPDATE order_receipts SET sent_chat = 1 WHERE id = ?', [$rid]);
@unlink($fakeLog);
notify_via_standin($rid, '111222', $fakePort);
ok('«ارسال دوباره» goes only where it did not arrive', standin_photos($fakeLog) === ['-100777']
   && $marks($rid) === '11', implode(',', standin_photos($fakeLog)));

$unsent($rid);
@unlink($fakeLog);
notify_via_standin($rid, '111222', $fakePort);
$photos = standin_photos($fakeLog);
sort($photos);
ok('two different chats: one photograph each', $photos === ['-100777', '111222'], implode(',', $photos));

/* A double click on «ارسال دوباره»: the second send finds the first one
   holding the receipt, and does nothing. */
$unsent($rid);
@unlink($fakeLog);
db_value('SELECT GET_LOCK(?, 0)', ['suppex_receipt_' . $rid]);
$n = notify_via_standin($rid, '111222', $fakePort);
db_value('SELECT RELEASE_LOCK(?)', ['suppex_receipt_' . $rid]);
ok('while another send has the receipt, a second sends nothing', standin_photos($fakeLog) === []
   && ($n['busy'] ?? false) === true && $marks($rid) === '00', json_encode($n));

settings_save(['telegram_group_id' => $groupBefore]);
$unsent($rid);
proc_terminate($fake);
proc_close($fake);
@unlink($fakeLog);

head('only a miss counts toward the lookup throttle');
$clearThrottles();
$r = [];
for ($i = 0; $i < TRACK_THROTTLE_MAX + 2; $i++) {
    $r = api_post('orderstate', ['code' => $orderA['code'], 'phone' => $phone], '127.0.0.77');
}
/* Asked on every page for two weeks after an order: counted, twelve pages
   in ten minutes shut a customer out of their own order and its receipt. */
ok('a customer\'s own order, asked about on every page, never locks them out',
   ($r['ok'] ?? null) === true, json_encode($r, JSON_UNESCAPED_UNICODE));
ok('  those questions are not counted as guesses', (int) db_value(
   'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND username = ?',
   [inet_pton('127.0.0.77'), TRACK_THROTTLE_USER]) === 0);
ok('  and the answer says a receipt is in, so the bar stops asking for payment',
   ($r['receipts'] ?? null) === 1, json_encode($r, JSON_UNESCAPED_UNICODE));
for ($i = 0; $i < TRACK_THROTTLE_MAX; $i++) {
    api_post('orderstate', ['code' => 'SPX-000000-ZZZZ', 'phone' => $phone], '127.0.0.78');
}
$r = api_post('orderstate', ['code' => $orderA['code'], 'phone' => $phone], '127.0.0.78');
ok('guesses are held to exactly what they were', isset($r['error'])
   && mb_strpos((string) $r['error'], 'زیاد') !== false, json_encode($r, JSON_UNESCAPED_UNICODE));
$clearThrottles();
api_post('receipt', ['code' => $orderA['code'], 'phone' => $phone], '127.0.0.79');
ok('a receipt for a proved order is not a guess either, though it is an upload',
   (int) db_value('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND username = ?',
                  [inet_pton('127.0.0.79'), TRACK_THROTTLE_USER]) === 0
   && (int) db_value('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND username = ?',
                     [inet_pton('127.0.0.79'), RECEIPT_THROTTLE_USER]) === 1);

head('the route answers only for a proved order');
$clearThrottles();
$r = api_post('receipt', ['code' => $orderA['code'], 'phone' => '09120000000']);
ok('the wrong phone gets the one answer for every miss', ($r['ok'] ?? null) === false
   && mb_strpos((string) ($r['error'] ?? ''), 'پیدا نشد') !== false, json_encode($r, JSON_UNESCAPED_UNICODE));
$r = api_post('receipt', ['code' => $orderA['code'], 'phone' => $phone]);
ok('the right pair with no file is told what is missing', ($r['ok'] ?? null) === false
   && mb_strpos((string) ($r['error'] ?? ''), 'پیدا نشد') === false, json_encode($r, JSON_UNESCAPED_UNICODE));
db_query("UPDATE orders SET status = 'cancelled' WHERE id = ?", [(int) $orderB['id']]);
$r = api_post('receipt', ['code' => $orderB['code'], 'phone' => $phone]);
ok('a cancelled order takes none', ($r['ok'] ?? null) === false
   && mb_strpos((string) ($r['error'] ?? ''), 'نمی‌پذیرد') !== false, json_encode($r, JSON_UNESCAPED_UNICODE));
for ($i = 1; $i < RECEIPT_PER_ORDER; $i++) {
    db_query('INSERT INTO order_receipts (order_id, file) VALUES (?, ?)', [(int) $orderA['id'], 'x']);
}
$r = api_post('receipt', ['code' => $orderA['code'], 'phone' => $phone]);
ok('an order with its fill of receipts takes no more', ($r['ok'] ?? null) === false
   && mb_strpos((string) ($r['error'] ?? ''), 'کافی') !== false, json_encode($r, JSON_UNESCAPED_UNICODE));
$clearThrottles();
for ($i = 0; $i < RECEIPT_THROTTLE_MAX; $i++) {
    api_post('receipt', ['code' => 'SPX-000000-XXXX', 'phone' => '09000000000'], '127.0.0.44');
}
$r = api_post('receipt', ['code' => $orderA['code'], 'phone' => $phone], '127.0.0.44');
ok('hammering it is throttled', isset($r['error']) && mb_strpos((string) $r['error'], 'زیاد') !== false,
   json_encode($r, JSON_UNESCAPED_UNICODE));

head('«سفارش‌های من»: the live state of the orders a browser remembers');
$clearThrottles();
db_query("UPDATE orders SET status = 'shipped', tracking_code = '1234567890123' WHERE id = ?", [(int) $orderB['id']]);
$r = api_post('myorders', ['orders' => [
    ['code' => $orderA['code'], 'phone' => $phone],
    ['code' => strtolower(str_replace('-', '', $orderB['code'])), 'phone' => '۰۹۱۲۱۱۱۲۲۳۳'],
    ['code' => 'SPX-000000-QQQQ', 'phone' => $phone],
]]);
$byCode = [];
foreach ((array) ($r['orders'] ?? []) as $o) { $byCode[$o['code']] = $o; }
ok('the orders it proves, and not the one it cannot', ($r['ok'] ?? null) === true && count($byCode) === 2,
   json_encode($r, JSON_UNESCAPED_UNICODE));
ok('  a code typed any old way still finds its order', isset($byCode[$orderB['code']]));
$b = $byCode[$orderB['code']] ?? [];
ok('  with its status, in words', ($b['status'] ?? '') === 'shipped' && ($b['statusFa'] ?? '') === (ORDER_STATUS_FA['shipped'] ?? ''));
ok('  its postal tracking code', ($b['trackingCode'] ?? '') === '1234567890123');
ok('  what was in it, and its total', ($b['summary'] ?? '') !== '' && ($b['more'] ?? -1) === 0
   && ($b['total'] ?? 0) === (int) $orderB['total']);
ok('  and nothing it did not need: no name, no address, no phone',
   !array_key_exists('customer_name', $b) && !array_key_exists('address', $b) && !array_key_exists('phone', $b));

$clearThrottles();
$guesses = [];
for ($i = 0; $i < 50; $i++) { $guesses[] = ['code' => sprintf('SPX-260101-%04d', $i), 'phone' => $phone]; }
$r = api_post('myorders', ['orders' => $guesses], '127.0.0.55');
$tries = (int) db_value('SELECT COUNT(*) FROM login_attempts WHERE username = ? AND ip = ?',
    [TRACK_THROTTLE_USER, inet_pton('127.0.0.55')]);
/* A customer's own list never misses, so only misses count; and the batch
   stops at the throttle, so fifty guesses buy the same twelve as the form. */
ok('a batch of guesses stops at the throttle', $tries === TRACK_THROTTLE_MAX, "recorded $tries");
$r = api_post('myorders', ['orders' => [['code' => $orderA['code'], 'phone' => $phone]]], '127.0.0.55');
ok('  and the next request is refused outright', isset($r['error']) && mb_strpos((string) $r['error'], 'زیاد') !== false);
$clearThrottles();
for ($i = 0; $i < 3; $i++) {
    api_post('myorders', ['orders' => [['code' => $orderA['code'], 'phone' => $phone]]], '127.0.0.66');
}
ok('a customer checking their own list over and over is never throttled',
   (int) db_value('SELECT COUNT(*) FROM login_attempts WHERE ip = ?', [inet_pton('127.0.0.66')]) === 0);

/* ------------------------------------------------------------- cleanup */
foreach ([$orderA, $orderB] as $o) {
    foreach (receipts_for_order((int) $o['id']) as $x) {
        $p = receipt_path((string) $x['file']);
        if ($p) { @unlink($p); }
    }
    db_query('DELETE FROM orders WHERE id = ?', [(int) $o['id']]);
}
$clearThrottles();

echo implode("\n", $out);
echo "\n\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
