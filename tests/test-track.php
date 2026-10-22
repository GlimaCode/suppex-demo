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

head("the code is accepted in whatever shape it was pasted");
/* The FAQ promises this in as many words, and track_find()'s own comment
   claimed it - but the function only STRIPPED stray characters and never put
   the dashes back, so a code copied without them was looked up verbatim and
   matched nothing. Every earlier test here passed the canonical form, which is
   why the gap survived. */
$bare = str_replace("-", "", $code);
ok("the same code with no dashes at all resolves",
   track_find($bare, $phone) !== null, $bare);
ok("  in lower case as well", track_find(strtolower($bare), $phone) !== null);
ok("  with spaces where the dashes were",
   track_find(str_replace("-", " ", $code), $phone) !== null);
ok("  and with the whole thing wrapped in punctuation",
   track_find("«" . $bare . "»", $phone) !== null);
/* Rebuilding only happens for the exact shape SPX + 6 digits + 4 characters.
   Anything else is passed through as typed rather than guessed at, so a near
   miss still fails rather than resolving to somebody else's order. */
ok("a code of the right shape but wrong value still finds nothing",
   track_find("SPX9909094ZZZ", $phone) === null);
ok("and a phone that does not match is still refused, dashes or not",
   track_find($bare, "09129999999") === null);

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

head("the postal tracking number");
/* The shipping page tells the customer this appears on their own tracking
   page. Before this it had nowhere to be stored, so the page said it arrived
   in the chat - a message somebody had to remember to send. */
ok("an order starts with no tracking number",
   (string) db_value("SELECT tracking_code FROM orders WHERE code = ?", [$code]) === "");

order_set_tracking((int) $made["order"]["id"], "1234567890123456789012");
ok("it is stored",
   (string) db_value("SELECT tracking_code FROM orders WHERE code = ?", [$code])
   === "1234567890123456789012");

/* Copied off a receipt with a Persian keyboard. The post office's own site
   expects Latin digits, so storing ۰۹ would hand the customer a number that
   does not work where they are told to paste it. */
order_set_tracking((int) $made["order"]["id"], "۱۲۳۴۵۶۷۸۹۰");
ok("Persian digits are folded to Latin",
   (string) db_value("SELECT tracking_code FROM orders WHERE code = ?", [$code]) === "1234567890");

order_set_tracking((int) $made["order"]["id"], " 1234 5678-90 ");
ok("spaces and dashes off a printed label are stripped",
   (string) db_value("SELECT tracking_code FROM orders WHERE code = ?", [$code]) === "1234567890");

ok("something absurdly long is refused rather than truncated",
   order_set_tracking((int) $made["order"]["id"], str_repeat("9", 60)) === false);
ok("  and the stored value is untouched by the refusal",
   (string) db_value("SELECT tracking_code FROM orders WHERE code = ?", [$code]) === "1234567890");

order_set_tracking((int) $made["order"]["id"], "");
ok("an empty value clears it, so a number typed on the wrong order can be taken back",
   (string) db_value("SELECT tracking_code FROM orders WHERE code = ?", [$code]) === "");

head("the customer sees it, and only when there is one");
db_query("UPDATE orders SET status = ?, tracking_code = ? WHERE code = ?",
         ["shipped", "", $code]);
db_query("DELETE FROM login_attempts WHERE username = ?", [TRACK_THROTTLE_USER]);
$noCode = $render(["code" => $code, "phone" => $phone]);
ok("no tracking block while there is no number",
   strpos($noCode, "track__parcel") === false);
/* A heading over an empty space reads as something that failed to load. */
ok("  and no heading for it either",
   strpos($noCode, "کد رهگیری مرسوله") === false);

db_query("UPDATE orders SET tracking_code = ? WHERE code = ?", ["990012345678", $code]);
db_query("DELETE FROM login_attempts WHERE username = ?", [TRACK_THROTTLE_USER]);
$withCode = $render(["code" => $code, "phone" => $phone]);
ok("the block appears once there is a number", strpos($withCode, "track__parcel") !== false);
ok("  the number itself is on the page", strpos($withCode, "990012345678") !== false);
ok("  printed left-to-right, because it is pasted into a Latin-digit form",
   strpos($withCode, 'class="track__parcel-code num" dir="ltr"') !== false);
/* Its own class. The first version reused .track__code, which already meant
   the ORDER code in the panel head - so the new CSS rule silently restyled a
   number the customer copies, shrinking it and letting it break mid-run. */
ok("  under its own class, not the order code's",
   substr_count($withCode, 'class="track__code ') === 1,
   (string) substr_count($withCode, 'class="track__code '));
/* Pointing at the post office is a link this shop can make without claiming
   anything about itself. */
ok("  with the post office's own tracking service to paste it into",
   strpos($withCode, "tracking.post.ir") !== false);
ok('Tipax tracking is also available without assuming every parcel is postal',
   strpos($withCode, 'https://tipaxco.com/tracking') !== false
   && strpos($withCode, 'هماهنگی تحویل با پیک') !== false);
ok("  opened safely", strpos($withCode, 'rel="noopener noreferrer"') !== false);

/* Not gated on the status: a parcel handed over has a number whether or not
   the panel has caught up, and the customer holding it is better off. */
db_query("UPDATE orders SET status = ? WHERE code = ?", ["paid", $code]);
db_query("DELETE FROM login_attempts WHERE username = ?", [TRACK_THROTTLE_USER]);
$paidWithCode = $render(["code" => $code, "phone" => $phone]);
ok("a number set before the status catches up is still shown",
   strpos($paidWithCode, "990012345678") !== false);

db_query("UPDATE orders SET status = ?, tracking_code = ? WHERE code = ?",
         ["new", "", $code]);

/* Clean up the fixture. */
db_query("DELETE oi FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.code = ?", [$code]);
db_query("DELETE FROM orders WHERE code = ?", [$code]);
db_query("DELETE FROM login_attempts WHERE username = ?", [TRACK_THROTTLE_USER]);


head('the card, on the page they come back to');
/* The payment panel lives in the cart drawer, and the drawer is gone the
   moment the tab is closed or reloaded - the card number with it. A customer
   who came back half an hour later had no way to pay the order the shop had
   already taken except asking in the chat.

   Its own order: the fixture above has been cancelled, shipped, paid and
   deleted by the time we get here. */
db_query('DELETE FROM login_attempts WHERE username = ?', [TRACK_THROTTLE_USER]);
settings_save(['card_number' => '6219861012345678',
               'card_holder' => 'آزمون دارنده', 'bank_name' => 'بانک آزمون']);

$payMade = order_create([
    'customer' => ['name' => 'آزمون کارت', 'phone' => $phone,
                   'address' => 'تهران، خیابان کارت، پلاک ۲', 'postal' => '1234567890'],
    'items'    => [['slug' => 'whey-original', 'sizeId' => '900', 'qty' => 1]],
    'channel'  => 'telegram',
]);
ok('a second order to look at the card with', !empty($payMade['ok']),
   implode(' | ', $payMade['errors'] ?? []));
$payCode = (string) ($payMade['order']['code'] ?? '');

db_query("UPDATE orders SET status = 'new', paid_at = NULL,
           expires_at = DATE_ADD(NOW(), INTERVAL 20 MINUTE) WHERE code = ?", [$payCode]);
db_query('DELETE FROM login_attempts WHERE username = ?', [TRACK_THROTTLE_USER]);

$unpaid = $render(['code' => $payCode, 'phone' => $phone]);
ok('an unpaid order inside its window shows the card',
   strpos($unpaid, '6219 8610 1234 5678') !== false);
ok('  with the holder and the bank',
   strpos($unpaid, 'آزمون دارنده') !== false && strpos($unpaid, 'بانک آزمون') !== false);
ok('  a copy button carrying the digits',
   strpos($unpaid, 'data-copy-card="6219861012345678"') !== false);
ok('  and a countdown for app.js to drive',
   strpos($unpaid, 'data-hold-countdown') !== false
   && strpos($unpaid, 'data-expires=') !== false);

/* A card number on a settled order is an invitation to pay twice. */
db_query('DELETE FROM login_attempts WHERE username = ?', [TRACK_THROTTLE_USER]);
db_query("UPDATE orders SET status = 'paid' WHERE code = ?", [$payCode]);
$paidLook = $render(['code' => $payCode, 'phone' => $phone]);
ok('a paid order shows no card at all',
   strpos($paidLook, '6219 8610 1234 5678') === false
   && strpos($paidLook, 'data-copy-card') === false);
ok('  but still shows the order', strpos($paidLook, 'track__result') !== false);

/* And an expired hold: that price is no longer the price. */
db_query('DELETE FROM login_attempts WHERE username = ?', [TRACK_THROTTLE_USER]);
db_query("UPDATE orders SET status = 'new', paid_at = NULL,
           expires_at = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE code = ?", [$payCode]);
$stale = $render(['code' => $payCode, 'phone' => $phone]);
ok('an expired hold shows no card',
   strpos($stale, '6219 8610 1234 5678') === false);
/* track.php sweeps due holds itself now. Before, the sweep ran only from the
   admin pages, so the customer was shown «ثبت شده» for an order whose window
   had closed - and would now have been offered a dead price. */
ok('  and the page has expired it rather than still calling it new',
   (string) db_value('SELECT status FROM orders WHERE code = ?', [$payCode]) === 'expired',
   (string) db_value('SELECT status FROM orders WHERE code = ?', [$payCode]));

db_query('DELETE oi FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.code = ?', [$payCode]);
db_query('DELETE FROM orders WHERE code = ?', [$payCode]);
db_query('DELETE FROM login_attempts WHERE username = ?', [TRACK_THROTTLE_USER]);
ok('card fixtures cleaned up',
   (int) db_value('SELECT COUNT(*) FROM orders WHERE code = ?', [$payCode]) === 0);

head('the form has to look like a form');
/* base.css strips every input to no border and no background; `.input` is what
   puts the box back. This form used `.field` and `.field__hint`, which exist in
   admin/assets/admin.css and in NONE of the four stylesheets this page loads -
   so both boxes rendered as bare text and nothing looked fillable. */
ok('the boxes carry the storefront control classes',
   substr_count($empty, 'class="input form__control"') === 2,
   (string) substr_count($empty, 'class="input form__control"'));
ok('  and not the admin-only ones that this page never loads',
   strpos($empty, 'class="field"') === false
   && strpos($empty, 'field__hint') === false);
ok('both fields are marked required in the markup',
   substr_count($empty, 'form__req') === 2);
ok('each one says where its value comes from',
   substr_count($empty, 'form__hint') === 2);
ok('and the form says up front that both are needed',
   strpos($empty, 'هر دو کادر را پر کنید') !== false);
ok('the page tells app.js it is the tracking page, so the dot can clear',
   strpos($empty, 'data-track-page') !== false);

echo implode("\n", $out);
echo "\n\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
