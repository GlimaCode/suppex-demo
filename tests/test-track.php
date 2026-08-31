<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/* ===========================================================================
   Order lookup.
   ---------------------------------------------------------------------------
   The page shows somebody's name, address and what they bought, so the tests
   that matter most are the ones about who is allowed to see it. A code alone
   must never be enough: SPX-YYMMDD-XXXX has a guessable date half and four
   characters of entropy, which is a reference, not a password.
   =========================================================================== */

$_SERVER["REMOTE_ADDR"]    = "127.0.0.1";
$_SERVER["HTTP_HOST"]      = "suppex.ir";
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["REQUEST_URI"]    = "/track.php";
$_SERVER["SCRIPT_NAME"]    = "/track.php";

$pass = 0; $fail = 0; $out = [];
function ok(string $l, bool $c, ?string $x = ""): void {
    global $pass, $fail, $out;
    if ($c) { $pass++; $out[] = "  ok   $l"; }
    else { $fail++; $out[] = "  FAIL $l" . ($x ? "  --  $x" : ""); }
}
function head(string $t): void { global $out; $out[] = "\n== $t =="; }

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/orders.php';
require_once SUPPEX_ROOT . '/lib/track.php';

/* A real order to look up, placed the way the API places one. */
db_query("DELETE FROM login_attempts WHERE username = ?", [TRACK_THROTTLE_USER]);
$phone = '09121110000';
$made  = order_create([
    'customer' => ['name' => 'آزمون پیگیری', 'phone' => $phone,
                   'address' => 'تهران، خیابان نمونه، پلاک ۱', 'postal' => '1234567890'],
    'items'    => [['slug' => 'whey-original', 'sizeId' => '900', 'qty' => 1]],
    'channel'  => 'telegram',
]);
if (!$made['ok']) {
    echo "could not place the fixture order: ",
         json_encode($made['errors'], JSON_UNESCAPED_UNICODE), "\n";
    exit(1);
}
$code = (string) $made['order']['code'];

head("a code alone is not enough");
ok("the right code and the right phone finds it",
   track_find($code, $phone) !== null);
ok("the right code with a WRONG phone finds nothing",
   track_find($code, '09129999999') === null);
ok("a made-up code finds nothing", track_find('SPX-000000-XXXX', $phone) === null);
ok("an empty code finds nothing", track_find('', $phone) === null);
ok("an empty phone finds nothing", track_find($code, '') === null);
/* The failure is the same in every case on purpose: a different message for
   "wrong phone" would confirm that the code exists. */
ok("a phone with punctuation still matches, since it is pasted from chat",
   track_find($code, '0912-111-0000') !== null);
ok("a lower-case code still matches", track_find(strtolower($code), $phone) !== null);
ok("a code with stray spaces still matches", track_find('  ' . $code . ' ', $phone) !== null);

head("what it hands back");
$o = track_find($code, $phone);
ok("the order code", (string) $o['code'] === $code);
ok("the status", in_array($o['status'], ORDER_STATUSES, true));
ok("the items", count($o['items'] ?? []) === 1);
ok("the total", (int) $o['total'] > 0);

head("every status says what happens next, not just its own name");
foreach (ORDER_STATUSES as $st) {
    ok("  $st has a note", track_status_note($st) !== '');
}
ok("an unknown status says nothing rather than guessing",
   track_status_note('not-a-status') === '');

head("the lookup is throttled, because it would otherwise enumerate orders");
db_query("DELETE FROM login_attempts WHERE username = ?", [TRACK_THROTTLE_USER]);
ok("not throttled to begin with", track_is_throttled() === false);
for ($i = 0; $i < TRACK_THROTTLE_MAX; $i++) { track_record_attempt(); }
ok("throttled after " . TRACK_THROTTLE_MAX . " attempts", track_is_throttled() === true);
db_query("DELETE FROM login_attempts WHERE username = ?", [TRACK_THROTTLE_USER]);
ok("and clear again once they age out", track_is_throttled() === false);

head("the page");
$render = function (array $post): string {
    $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    $_POST = $post; $_GET = []; $_REQUEST = $post;
    ob_start();
    include SUPPEX_ROOT . '/track.php';
    return (string) ob_get_clean();
};

$empty = $render([]);
ok("renders a form with no query", strpos($empty, 'track__form') !== false);
ok("asks not to be indexed - every URL here is a form or somebody's order",
   strpos($empty, 'noindex') !== false);
ok("shows no result panel", strpos($empty, 'track__result') === false);

db_query("DELETE FROM login_attempts WHERE username = ?", [TRACK_THROTTLE_USER]);
$found = $render(['code' => $code, 'phone' => $phone]);
ok("finds the order", strpos($found, 'track__result') !== false);
ok("shows the code", strpos($found, $code) !== false);
ok("shows what the customer bought", strpos($found, 'وی پروتئین') !== false);
ok("shows the delivery address so a wrong one can be caught",
   strpos($found, 'خیابان نمونه') !== false);
ok("shows the progress rail for a live order",
   strpos($found, 'track__rail') !== false);

db_query("DELETE FROM login_attempts WHERE username = ?", [TRACK_THROTTLE_USER]);
$wrong = $render(['code' => $code, 'phone' => '09129999999']);
ok("a wrong phone shows no order", strpos($wrong, 'track__result') === false);
ok("and leaks neither the name nor the address",
   strpos($wrong, 'خیابان نمونه') === false && strpos($wrong, 'آزمون پیگیری') === false);
ok("the message is the same one a wrong code gets",
   strpos($wrong, 'پیدا نشد') !== false);

/* A cancelled order is not on the path to completion, so it must not be shown
   a rail implying it is still moving. */
db_query("UPDATE orders SET status = 'cancelled' WHERE code = ?", [$code]);
db_query("DELETE FROM login_attempts WHERE username = ?", [TRACK_THROTTLE_USER]);
$cancelled = $render(['code' => $code, 'phone' => $phone]);
ok("a cancelled order still resolves", strpos($cancelled, 'track__result') !== false);
ok("but gets no progress rail", strpos($cancelled, 'track__rail') === false);
ok("and says what to do about it", strpos($cancelled, 'لغو شده') !== false);

/* Clean up the fixture. */
db_query("DELETE oi FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.code = ?", [$code]);
db_query("DELETE FROM orders WHERE code = ?", [$code]);
db_query("DELETE FROM login_attempts WHERE username = ?", [TRACK_THROTTLE_USER]);

echo implode("\n", $out);
echo "\n\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
