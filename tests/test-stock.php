<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/* ===========================================================================
   Stock counts.
   ---------------------------------------------------------------------------
   The checks that matter here are not "does the number go down". They are:

     - a product nobody has counted must behave EXACTLY as it did before, on
       every path, because that is fifteen of fifteen products at launch;
     - the last tub can be sold once and only once;
     - a hold that is given back must never be given back twice, since cancel →
       reopen → cancel is an ordinary afternoon in the panel;
     - a failed order must leave nothing behind — no code, no held stock, no
       half-written line.
   =========================================================================== */

$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = 'suppex.ir';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['SCRIPT_NAME']    = '/index.php';

$pass = 0; $fail = 0; $out = [];
function ok(string $l, bool $c, ?string $x = ''): void {
    global $pass, $fail, $out;
    if ($c) { $pass++; $out[] = "  ok   $l"; }
    else { $fail++; $out[] = "  FAIL $l" . ($x ? "  --  $x" : ''); }
}
function head(string $t): void { global $out; $out[] = "\n== $t =="; }

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/orders.php';
require_once SUPPEX_ROOT . '/lib/stock.php';

/* --- fixtures -------------------------------------------------------------
   creatine-mono has no sizes; whey-original does. Between them they cover both
   halves of the resolution rule. */
$plain  = (int) db_value('SELECT id FROM products WHERE slug = ?', ['creatine-mono']);
$sized  = (int) db_value('SELECT id FROM products WHERE slug = ?', ['whey-original']);
$sizeId = (string) db_value(
    'SELECT ext_id FROM product_sizes WHERE product_id = ? ORDER BY sort_order, id LIMIT 1', [$sized]);

$phones = [];
function customer(string $phone): array {
    global $phones;
    $phones[] = $phone;
    return ['name' => 'آزمون موجودی', 'phone' => $phone,
            'address' => 'تهران، خیابان نمونه، پلاک ۱', 'postal' => '1234567890'];
}
function place(string $phone, array $items): array {
    db_query("DELETE FROM login_attempts WHERE username = ?", [ORDER_THROTTLE_USER]);
    return order_create(['customer' => customer($phone), 'items' => $items, 'channel' => 'telegram']);
}
function qtyOf(int $pid): ?int {
    $v = db_value('SELECT stock_qty FROM products WHERE id = ?', [$pid]);
    return $v === null ? null : (int) $v;
}
function sizeQtyOf(int $pid, string $ext): ?int {
    $v = db_value('SELECT stock_qty FROM product_sizes WHERE product_id = ? AND ext_id = ?', [$pid, $ext]);
    return $v === null ? null : (int) $v;
}

$admin = ['id' => null, 'name' => 'test'];

/* Everything starts untracked, which is how the shop ships. */
db_query('UPDATE products SET stock_qty = NULL, in_stock = 1 WHERE id IN (?, ?)', [$plain, $sized]);
db_query('UPDATE product_sizes SET stock_qty = NULL WHERE product_id = ?', [$sized]);

head('an uncounted product behaves exactly as it always did');
ok('nothing is counted to begin with', qtyOf($plain) === null);
$r = place('09120000001', [['slug' => 'creatine-mono', 'qty' => 9]]);
ok('an order of nine goes through', ($r['ok'] ?? false) === true,
   json_encode($r['errors'] ?? [], JSON_UNESCAPED_UNICODE));
ok('and the count is still nothing, not zero', qtyOf($plain) === null);
ok('the line holds nothing', (int) db_value(
    'SELECT COALESCE(SUM(stock_held),0) FROM order_items WHERE order_id = ?',
    [(int) $r['order']['id']]) === 0);
ok('stock_remaining() says "uncounted"', stock_remaining($plain) === null);

head('a counted product is sold down and then refused');
db_query('UPDATE products SET stock_qty = 2 WHERE id = ?', [$plain]);
$a = place('09120000002', [['slug' => 'creatine-mono', 'qty' => 1]]);
ok('first buyer gets a code', ($a['ok'] ?? false) === true);
ok('  one is taken', qtyOf($plain) === 1);
$b = place('09120000003', [['slug' => 'creatine-mono', 'qty' => 1]]);
ok('second buyer gets a code', ($b['ok'] ?? false) === true);
ok('  none are left', qtyOf($plain) === 0);

$c = place('09120000004', [['slug' => 'creatine-mono', 'qty' => 1]]);
ok('third buyer is refused', ($c['ok'] ?? true) === false);
ok('  and told it is gone, not given a generic failure',
   strpos((string) ($c['errors']['items'] ?? ''), 'تمام شد') !== false,
   json_encode($c['errors'] ?? [], JSON_UNESCAPED_UNICODE));
/* The whole point of doing this inside the transaction. A refused order that
   still wrote a row would hand out a code for goods that do not exist. */
ok('  no order row survives the refusal',
   (int) db_value('SELECT COUNT(*) FROM orders WHERE phone = ?', ['09120000004']) === 0);
ok('  and the count is untouched by the attempt', qtyOf($plain) === 0);

head('asking for more than exists says how many there are');
db_query('UPDATE products SET stock_qty = 2 WHERE id = ?', [$plain]);
$d = place('09120000005', [['slug' => 'creatine-mono', 'qty' => 5]]);
ok('refused', ($d['ok'] ?? true) === false);
ok('  the real number is in the message',
   strpos((string) ($d['errors']['items'] ?? ''), '۲') !== false,
   (string) ($d['errors']['items'] ?? ''));
ok('  nothing was taken', qtyOf($plain) === 2);
$e = place('09120000006', [['slug' => 'creatine-mono', 'qty' => 2]]);
ok('exactly what is there is allowed', ($e['ok'] ?? false) === true);
ok('  leaving none', qtyOf($plain) === 0);

head('one bad line rolls the whole basket back');
db_query('UPDATE products SET stock_qty = 5 WHERE id = ?', [$plain]);
db_query('UPDATE product_sizes SET stock_qty = 0 WHERE product_id = ? AND ext_id = ?', [$sized, $sizeId]);
$f = place('09120000007', [
    ['slug' => 'creatine-mono', 'qty' => 2],
    ['slug' => 'whey-original', 'sizeId' => $sizeId, 'qty' => 1],
]);
ok('the order is refused', ($f['ok'] ?? true) === false);
/* The first line succeeded before the second one failed. Without the rollback
   those two units would be held by an order that does not exist. */
ok('  the line that DID succeed gave its units back', qtyOf($plain) === 5);
ok('  and no order row was left behind',
   (int) db_value('SELECT COUNT(*) FROM orders WHERE phone = ?', ['09120000007']) === 0);

head('a size carries its own count, and falls back to the product when it has none');
db_query('UPDATE product_sizes SET stock_qty = NULL WHERE product_id = ?', [$sized]);
db_query('UPDATE products SET stock_qty = 4 WHERE id = ?', [$sized]);
ok('an uncounted size reports the product\'s number',
   stock_remaining($sized, $sizeId) === 4, (string) stock_remaining($sized, $sizeId));
$g = place('09120000008', [['slug' => 'whey-original', 'sizeId' => $sizeId, 'qty' => 1]]);
ok('buying it comes out of the product row', ($g['ok'] ?? false) === true && qtyOf($sized) === 3);
ok('  and the size row is untouched', sizeQtyOf($sized, $sizeId) === null);

db_query('UPDATE product_sizes SET stock_qty = 1 WHERE product_id = ? AND ext_id = ?', [$sized, $sizeId]);
ok('once the size has a number, that is the number', stock_remaining($sized, $sizeId) === 1);
$h = place('09120000009', [['slug' => 'whey-original', 'sizeId' => $sizeId, 'qty' => 1]]);
ok('buying it comes out of the SIZE row',
   ($h['ok'] ?? false) === true && sizeQtyOf($sized, $sizeId) === 0);
ok('  and the product row is untouched', qtyOf($sized) === 3);
ok('  the line records where it took them from',
   (string) db_value('SELECT stock_from FROM order_items WHERE order_id = ?',
       [(int) $h['order']['id']]) === $sizeId);

head('the hold goes back, and only once');
db_query('UPDATE products SET stock_qty = 5 WHERE id = ?', [$plain]);
$i = place('09120000010', [['slug' => 'creatine-mono', 'qty' => 2]]);
ok('two are held', qtyOf($plain) === 3);
order_set_status((int) $i['order']['id'], 'cancelled', $admin);
ok('cancelling returns them', qtyOf($plain) === 5);
order_set_status((int) $i['order']['id'], 'cancelled', $admin);
ok('cancelling again returns nothing more', qtyOf($plain) === 5);
order_set_status((int) $i['order']['id'], 'new', $admin);
ok('reopening takes them again', qtyOf($plain) === 3);
order_set_status((int) $i['order']['id'], 'paid', $admin);
ok('paid still holds them', qtyOf($plain) === 3);
order_set_status((int) $i['order']['id'], 'shipped', $admin);
ok('shipped still holds them', qtyOf($plain) === 3);
order_set_status((int) $i['order']['id'], 'done', $admin);
ok('done keeps them gone — the hold became the sale', qtyOf($plain) === 3);

head('reopening may oversell, and says so');
db_query('UPDATE products SET stock_qty = 2 WHERE id = ?', [$plain]);
$j = place('09120000011', [['slug' => 'creatine-mono', 'qty' => 2]]);
ok('two held, none left', qtyOf($plain) === 0);
order_set_status((int) $j['order']['id'], 'cancelled', $admin);
db_query('UPDATE products SET stock_qty = 1 WHERE id = ?', [$plain]);   // one sold elsewhere
order_set_status((int) $j['order']['id'], 'new', $admin);
ok('the reopen is not refused — the operator knows best',
   (string) db_value('SELECT status FROM orders WHERE id = ?', [(int) $j['order']['id']]) === 'new');
ok('  the count goes negative rather than silently clamping', qtyOf($plain) === -1);
ok('  and the oversell is reported by name',
   in_array('کراتین مونوهیدرات', stock_oversold_for_order((int) $j['order']['id']), true),
   implode(',', stock_oversold_for_order((int) $j['order']['id'])));

head('the expiry sweep gives goods back');
db_query('UPDATE products SET stock_qty = 3 WHERE id = ?', [$plain]);
$k = place('09120000012', [['slug' => 'creatine-mono', 'qty' => 1]]);
ok('held while the window is open', qtyOf($plain) === 2);
db_query('UPDATE orders SET expires_at = ? WHERE id = ?',
    [date('Y-m-d H:i:s', time() - 60), (int) $k['order']['id']]);
orders_expire_due();
ok('the sweep expires it',
   (string) db_value('SELECT status FROM orders WHERE id = ?', [(int) $k['order']['id']]) === 'expired');
ok('  and the tub is back on the shelf', qtyOf($plain) === 3);
orders_expire_due();
ok('  a second sweep does not credit it twice', qtyOf($plain) === 3);

head('six people, three tubs, at the same moment');
/* The claim the whole feature rests on, checked the only way it can be: real
   concurrent processes against one database. Everything above runs in one
   process, where a broken lock still looks correct.

   Each child places one order for one tub. Six start before any of them
   finishes; exactly three must succeed. The throttle is off the table because
   each child arrives with its own phone number and the IP window is six, and
   the duplicate guard cannot fire because the phones differ. */
db_query('DELETE FROM login_attempts WHERE username = ?', [ORDER_THROTTLE_USER]);
db_query('UPDATE products SET stock_qty = 3, in_stock = 1 WHERE id = ?', [$plain]);

$procs = [];
$pipes = [];
for ($n = 0; $n < 6; $n++) {
    $phone = '0912888' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    $phones[] = $phone;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/race-order.php')
         . ' ' . escapeshellarg($phone) . ' creatine-mono 2>&1';
    /* One pipe with stderr folded in. Two pipes deadlock here: the parent
       blocks reading stdout to EOF while a child that emitted a warning blocks
       writing stderr — see the note in run.php. */
    $p = proc_open($cmd, [1 => ['pipe', 'w']], $pipe, null,
                   array_merge(getenv(), ['SUPPEX_CONFIG' => getenv('SUPPEX_CONFIG') ?: '']));
    if (is_resource($p)) { $procs[$n] = $p; $pipes[$n] = $pipe[1]; }
}
ok('six children started', count($procs) === 6, (string) count($procs));

$won = 0;
foreach ($procs as $n => $p) {
    $text = stream_get_contents($pipes[$n]);
    fclose($pipes[$n]);
    proc_close($p);
    if (strpos($text, 'OK') !== false) { $won++; }
}
ok('exactly three of the six got an order', $won === 3, (string) $won);
ok('  and the shelf is empty, not negative', qtyOf($plain) === 0, var_export(qtyOf($plain), true));
ok('  three order rows exist, not six',
   (int) db_value('SELECT COUNT(*) FROM orders WHERE phone LIKE ?', ['0912888%']) === 3);
/* The sum of what the winners hold has to equal what left the shelf. If a
   child could take stock and then fail to record it, this is where it shows. */
ok('  and the winners hold exactly three units between them',
   (int) db_value('SELECT COALESCE(SUM(oi.stock_held),0) FROM order_items oi
                     JOIN orders o ON o.id = oi.order_id WHERE o.phone LIKE ?', ['0912888%']) === 3);

head('the catalogue tells the truth about what is left');
db_query('UPDATE products SET stock_qty = 0, in_stock = 1 WHERE id = ?', [$plain]);
$p = product_get('creatine-mono');
ok('a product with none left is not inStock', $p !== null && $p['inStock'] === false);
db_query('UPDATE products SET stock_qty = 2 WHERE id = ?', [$plain]);
$p = product_get('creatine-mono');
ok('with two left it is', $p['inStock'] === true);
ok('  and it says how few', $p['stock'] === 2, var_export($p['stock'], true));
/* Scarcity is worth publishing; inventory is not. */
db_query('UPDATE products SET stock_qty = 500 WHERE id = ?', [$plain]);
$p = product_get('creatine-mono');
ok('a healthy count is not published at all', $p['stock'] === null, var_export($p['stock'], true));
ok('  but it is still available', $p['inStock'] === true);
db_query('UPDATE products SET stock_qty = NULL WHERE id = ?', [$plain]);
$p = product_get('creatine-mono');
ok('an uncounted product publishes no number', $p['stock'] === null);
ok('  and is available', $p['inStock'] === true);

head('the manual switch still overrules everything');
db_query('UPDATE products SET stock_qty = 99, in_stock = 0 WHERE id = ?', [$plain]);
$p = product_get('creatine-mono');
ok('ninety-nine in the room, switch off, not available', $p['inStock'] === false);
$l = place('09120000013', [['slug' => 'creatine-mono', 'qty' => 1]]);
ok('  and it cannot be ordered', ($l['ok'] ?? true) === false);
ok('  and nothing was taken from the count', qtyOf($plain) === 99);
db_query('UPDATE products SET in_stock = 1 WHERE id = ?', [$plain]);

head('a sized product is available while ANY size is');
db_query('UPDATE products SET stock_qty = NULL WHERE id = ?', [$sized]);
db_query('UPDATE product_sizes SET stock_qty = 0 WHERE product_id = ?', [$sized]);
$p = product_get('whey-original');
ok('every size at zero means not available', $p['inStock'] === false);
db_query('UPDATE product_sizes SET stock_qty = 2 WHERE product_id = ? AND ext_id = ?', [$sized, $sizeId]);
$p = product_get('whey-original');
ok('one size with stock is enough', $p['inStock'] === true);
$one = null;
foreach ($p['sizes'] as $s) { if ($s['id'] === $sizeId) { $one = $s; } }
ok('  that size reports its count', $one !== null && $one['stock'] === 2);
foreach ($p['sizes'] as $s) {
    if ($s['id'] !== $sizeId) { ok('  a sold-out size reports zero', $s['stock'] === 0); break; }
}
/* The card would otherwise print a number that is about nothing: this product
   is sold in twelve sizes. */
ok('the product-level number stays absent on a sized product', $p['stock'] === null);

head('the listing agrees with the product page');
$list = products_list(['limit' => 200]);
$bySlug = [];
foreach ($list as $row) { $bySlug[$row['slug']] = $row; }
ok('whey is in the list and available', ($bySlug['whey-original']['inStock'] ?? null) === true);
db_query('UPDATE product_sizes SET stock_qty = 0 WHERE product_id = ?', [$sized]);
$list = products_list(['limit' => 200]);
foreach ($list as $row) { if ($row['slug'] === 'whey-original') { $bySlug['whey-original'] = $row; } }
ok('every size gone takes it off the list as unavailable',
   $bySlug['whey-original']['inStock'] === false);

head('the stock page writes only what was actually edited');
/* The number on this page moves on its own: every order takes from it while
   the panel is open. A form that posted everything it rendered would undo a
   sale each time somebody saved a page they had not touched — so the page
   compares each field against what it was drawn with, and leaves the
   unchanged ones alone. That is the check. */
$harness = __DIR__ . '/stocksave.php';
ok('the stock-save harness exists', is_file($harness), $harness);
$save = static function (array $args) use ($harness): string {
    return (string) shell_exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' '
        . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1');
};

db_query('UPDATE products SET stock_qty = 7 WHERE id = ?', [$plain]);
$r1 = $save([(string) $plain, '4', '7']);
ok('an edited field is written', strpos($r1, 'PRODUCT=4') !== false, trim($r1));

/* The page was drawn showing 4. An order takes one while it sits open. The
   operator changes nothing and presses save: the 3 must survive. */
db_query('UPDATE products SET stock_qty = 3 WHERE id = ?', [$plain]);
$r2 = $save([(string) $plain, '4', '4']);
ok('an untouched field does NOT overwrite a sale made meanwhile',
   strpos($r2, 'PRODUCT=3') !== false, trim($r2));

$r3 = $save([(string) $plain, '', '3']);
ok('clearing a field goes back to uncounted', strpos($r3, 'PRODUCT=NULL') !== false, trim($r3));

$r4 = $save([(string) $plain, '۵', '']);
ok('Persian digits are accepted, since that is what the keyboard produces',
   strpos($r4, 'PRODUCT=5') !== false, trim($r4));

/* Being oversold is a state the shop can genuinely be in — reopening a
   cancelled order puts it there — so the operator has to be able to type it
   back after they have counted the shelf. */
$r5 = $save([(string) $plain, '-2', '5']);
ok('a negative count can be recorded rather than refused',
   strpos($r5, 'PRODUCT=-2') !== false, trim($r5));

db_query('UPDATE product_sizes SET stock_qty = NULL WHERE product_id = ?', [$sized]);
$r6 = $save([(string) $sized, '', '', $sizeId, '6', '']);
ok('a size count is written on its own row',
   strpos($r6, 'SIZE ' . $sizeId . '=6') !== false, trim($r6));
ok('  and the product row stays uncounted',
   strpos($r6, 'PRODUCT=NULL') !== false, trim($r6));

/* --- clean up ------------------------------------------------------------- */
db_query('UPDATE products SET stock_qty = NULL, in_stock = 1 WHERE id IN (?, ?)', [$plain, $sized]);
db_query('UPDATE product_sizes SET stock_qty = NULL WHERE product_id = ?', [$sized]);
foreach ($phones as $ph) {
    db_query('DELETE oi FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.phone = ?', [$ph]);
    db_query('DELETE FROM orders WHERE phone = ?', [$ph]);
}
db_query('DELETE FROM login_attempts WHERE username = ?', [ORDER_THROTTLE_USER]);

echo implode("\n", $out);
echo "\n\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
