<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP.
   bootstrap falls back to the real suppex-config.php when SUPPEX_CONFIG
   is unset - which is exactly what a web request has - so one GET to an
   uploaded tests/ folder could empty the live database. The .htaccess
   beside this file is process; this is code. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* The pages set their own status; the report waits in a buffer so PHP lets
   them. */
ob_start();
/* ===========================================================================
   Reviews from buyers only (lib/reviews.php)
   ---------------------------------------------------------------------------
   The one rule everything here serves: a star on this site comes from
   somebody who bought the product, and appears only after the owner has read
   it. So: a review needs an order that was sent, opened by its code and
   phone; one per product per order; nothing shown while it waits; the
   storefront's stars, the product page and the structured data all count
   approved ones alone - and draw nothing at all when there are none.
   =========================================================================== */
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = 'suppex.ir';
$_SERVER['HTTPS']          = 'on';
$_SERVER['REQUEST_METHOD'] = 'GET';

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/orders.php';
require_once SUPPEX_ROOT . '/lib/track.php';
require_once SUPPEX_ROOT . '/lib/products.php';
require_once SUPPEX_ROOT . '/lib/reviews.php';
require_once SUPPEX_ROOT . '/lib/seo.php';

$pass = 0; $fail = 0;
function ok(string $l, bool $c, ?string $x = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $l\n"; }
    else { $fail++; echo "  FAIL $l" . ($x ? "  --  $x" : '') . "\n"; }
}
function head(string $t): void { echo "\n== $t ==\n"; }
function rv_page(string $file, array $get = [], array $post = []): string {
    $_GET = $get;
    $_POST = $post;
    $_REQUEST = $get + $post;
    $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    ob_start();
    include SUPPEX_ROOT . '/' . $file;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    return (string) ob_get_clean();
}
function rv_ld(string $html): array {
    preg_match_all('~<script type="application/ld\+json">\s*(.*?)\s*</script>~s', $html, $m);
    foreach ($m[1] as $j) {
        $d = json_decode($j, true);
        if (!is_array($d)) { continue; }
        foreach (isset($d['@graph']) ? (array) $d['@graph'] : [$d] as $n) {
            if (($n['@type'] ?? '') === 'Product') { return $n; }
        }
    }
    return [];
}

head('the table');
ok('it is there after the migration', reviews_ready());

/* Two orders placed the way the API places them, by one buyer. */
db_query('DELETE FROM login_attempts WHERE username = ?', [TRACK_THROTTLE_USER]);
$phone = '09121112233';
$size  = static fn(string $slug): string => (string) db_value(
    'SELECT s.ext_id FROM product_sizes s JOIN products p ON p.id = s.product_id WHERE p.slug = ? ORDER BY s.sort_order, s.id LIMIT 1', [$slug]);
$place = static function (array $slugs) use ($phone, $size): array {
    $made = order_create([
        'customer' => ['name' => 'آزمون نظر', 'phone' => $phone, 'address' => 'تهران، خیابان نمونه ۱', 'postal' => '1234567890'],
        'items'    => array_map(static fn(string $s): array => ['slug' => $s, 'sizeId' => $size($s), 'qty' => 1], $slugs),
        'channel'  => 'telegram',
    ]);
    if (!$made['ok']) {
        echo 'could not place the fixture order: ', json_encode($made['errors'], JSON_UNESCAPED_UNICODE), "\n";
        exit(1);
    }
    return $made['order'];
};
$a = $place(['whey-original', 'creatine-mono']);
$b = $place(['whey-original']);
$whey     = (int) db_value('SELECT id FROM products WHERE slug = ?', ['whey-original']);
$creatine = (int) db_value('SELECT id FROM products WHERE slug = ?', ['creatine-mono']);
$reload   = static fn(array $o): array => (array) track_find((string) $o['code'], $phone);

head('who may write one');
$order = $reload($a);
ok('not while the order is new', review_candidates($order) === []
   && review_submit($order, $whey, 5, 'x', '')['ok'] === false);
db_query("UPDATE orders SET status = 'paid' WHERE id = ?", [(int) $a['id']]);
ok('  nor once it is paid - it has not arrived', review_candidates($reload($a)) === []);
db_query("UPDATE orders SET status = 'shipped' WHERE id = ?", [(int) $a['id']]);
$order = $reload($a);
$cands = review_candidates($order);
ok('once it has been sent: each of its products', array_column($cands, 'product_id') === [$whey, $creatine],
   json_encode(array_column($cands, 'product_id')));
ok('  none written yet', $cands[0]['review'] === null && $cands[1]['review'] === null);
ok('a product not in the order is refused', review_submit($order, (int) db_value("SELECT id FROM products WHERE slug = 'iso-zero'"), 5, '', '')['ok'] === false);
ok('no stars, or six, is refused', review_submit($order, $whey, 0, '', '')['ok'] === false
   && review_submit($order, $whey, 6, '', '')['ok'] === false);
$r = review_submit($order, $whey, 4, "  خوب حل می‌شود.\n\n\n\nطعمش هم خوب است.  ", '  علی  ');
ok('a buyer writes one', $r['ok'] === true, json_encode($r, JSON_UNESCAPED_UNICODE));
$row = db_one('SELECT * FROM product_reviews WHERE order_id = ? AND product_id = ?', [(int) $a['id'], $whey]);
ok('  kept waiting, tidied: the name trimmed, no runs of blank lines', $row && $row['status'] === 'pending'
   && $row['author'] === 'علی' && $row['body'] === "خوب حل می‌شود.\n\nطعمش هم خوب است." && (int) $row['rating'] === 4,
   json_encode($row, JSON_UNESCAPED_UNICODE));
ok('  and only once per product per order', review_submit($reload($a), $whey, 5, '', '')['ok'] === false);
ok('  the other product of the order is still open', review_candidates($reload($a))[1]['review'] === null);
ok('a long text and a long name are cut to size',
   review_submit($reload($a), $creatine, 5, str_repeat('ب', 1500), str_repeat('ن', 90))['ok'] === true
   && mb_strlen((string) db_value('SELECT body FROM product_reviews WHERE order_id = ? AND product_id = ?', [(int) $a['id'], $creatine])) === REVIEW_BODY_MAX
   && mb_strlen((string) db_value('SELECT author FROM product_reviews WHERE order_id = ? AND product_id = ?', [(int) $a['id'], $creatine])) === REVIEW_AUTHOR_MAX);

head('nothing shows while it waits');
ok('no approved reviews', reviews_for_product($whey) === [] && review_summaries([$whey]) === []);
$listed = array_values(array_filter(products_list([]), static fn(array $p): bool => $p['slug'] === 'whey-original'))[0] ?? [];
ok('the card has no stars', ($listed['reviewCount'] ?? null) === 0 && ($listed['rating'] ?? null) === 0);
$pp = rv_page('product.php', ['p' => 'whey-original']);
ok('the product page says where to write one, and shows none', strpos($pp, 'class="reviews__empty') !== false
   && strpos($pp, 'href="track.php"') !== false && strpos($pp, 'class="review"') === false);
ok('  no aggregateRating in its structured data', !isset(rv_ld($pp)['aggregateRating']) && !isset(rv_ld($pp)['review']));

head('approved, it counts');
$pending = reviews_list('pending');
ok('the panel lists what waits, with its product and order', count($pending) >= 2
   && ($pending[0]['order_code'] ?? '') === $a['code'] && isset($pending[0]['product_name']));
ok('the menu counts them', reviews_pending_count() >= 2);
ok('approving one', review_decide((int) $row['id'], 'approved'));
ok('  and nothing else can be decided', review_decide((int) $row['id'], 'deleted') === false && review_decide(999999, 'approved') === false);
$sum = review_summaries([$whey])[$whey] ?? null;
ok('its product now has one review of 4', $sum === ['count' => 1, 'avg' => 4.0], json_encode($sum));
$listed = array_values(array_filter(products_list([]), static fn(array $p): bool => $p['slug'] === 'whey-original'))[0] ?? [];
ok('  on the card', ($listed['reviewCount'] ?? 0) === 1 && (float) ($listed['rating'] ?? 0) === 4.0);
ok('  and in the product\'s own data', (product_get('whey-original')['reviewCount'] ?? 0) === 1);
$pp = rv_page('product.php', ['p' => 'whey-original']);
ok('the product page shows it: the name, the badge, the date', strpos($pp, '<span class="review__author">علی</span>') !== false
   && strpos($pp, 'خرید تأییدشده') !== false && preg_match('~<time datetime="\d{4}-\d{2}-\d{2}">[^<]*(?:مهر|آبان|آذر|دی|بهمن|اسفند|فروردین|اردیبهشت|خرداد|تیر|مرداد|شهریور)[^<]*</time>~u', $pp) === 1);
ok('  line breaks kept as the buyer wrote them', strpos($pp, "<p class=\"review__body\">خوب حل می‌شود.\n\nطعمش هم خوب است.</p>") !== false);
$ld = rv_ld($pp);
ok('the structured data rates it from that review', ($ld['aggregateRating']['ratingValue'] ?? '') === '4'
   && ($ld['aggregateRating']['reviewCount'] ?? 0) === 1 && ($ld['review'][0]['author']['name'] ?? '') === 'علی');

db_query("UPDATE orders SET status = 'done' WHERE id = ?", [(int) $b['id']]);
review_submit($reload($b), $whey, 5, 'عالی', '');
$anon = (int) db_value('SELECT id FROM product_reviews WHERE order_id = ?', [(int) $b['id']]);
review_decide($anon, 'approved');
$pp = rv_page('product.php', ['p' => 'whey-original']);
$ld = rv_ld($pp);
ok('a second, unsigned: the average moves, «خریدار» on the page', ($ld['aggregateRating']['ratingValue'] ?? '') === '4.5'
   && strpos($pp, '<span class="review__author">خریدار</span>') !== false);
ok('  and it stays out of the structured list - Google takes no «خریدار»', count($ld['review'] ?? []) === 1);

head('rejected or deleted');
ok('a rejected one disappears again', review_decide($anon, 'rejected')
   && (review_summaries([$whey])[$whey]['count'] ?? 0) === 1);
ok('  and its buyer cannot write another', review_submit($reload($b), $whey, 5, '', '')['ok'] === false);
ok('a deleted one is gone, and its buyer may write again', review_delete($anon)
   && review_candidates($reload($b))[0]['review'] === null);

head('the tracking page');
db_query('DELETE FROM login_attempts WHERE username = ?', [TRACK_THROTTLE_USER]);
$tp = rv_page('track.php', [], ['code' => (string) $b['code'], 'phone' => $phone]);
ok('an order that was sent offers the form for each product', strpos($tp, 'class="review-form"') !== false
   && strpos($tp, 'name="review_product" value="' . $whey . '"') !== false);
ok('  five stars to choose from, the fields in the checkout\'s classes', substr_count($tp, 'name="rating" value=') === 5
   && strpos($tp, 'class="input form__control" id="rv-body-' . $whey . '"') !== false);
$tp = rv_page('track.php', [], ['code' => (string) $b['code'], 'phone' => $phone, 'review_product' => (string) $whey,
    'rating' => '3', 'body' => '<script>alert(1)</script> متوسط', 'author' => '<b>x</b>']);
ok('sent from it, it is stored and the page says so', strpos($tp, 'نظر شما ثبت شده است') !== false
   && (int) db_value('SELECT rating FROM product_reviews WHERE order_id = ?', [(int) $b['id']]) === 3);
ok('  a wrong phone writes nothing', rv_page('track.php', [], ['code' => (string) $a['code'], 'phone' => '09120000000',
    'review_product' => (string) $creatine, 'rating' => '5']) !== ''
   && (int) db_value('SELECT COUNT(*) FROM product_reviews WHERE order_id = ?', [(int) $a['id']]) === 2);
review_decide((int) db_value('SELECT id FROM product_reviews WHERE order_id = ?', [(int) $b['id']]), 'approved');
$pp = rv_page('product.php', ['p' => 'whey-original']);
ok('what a buyer types is shown as text, never as markup', strpos($pp, '<script>alert(1)</script>') === false
   && strpos($pp, '&lt;script&gt;alert(1)&lt;/script&gt; متوسط') !== false && strpos($pp, '&lt;b&gt;x&lt;/b&gt;') !== false);
/* The structured data carries it too - the element it sits in must not end
   where the buyer's «</script>» is. */
$bodies = array_column((array) (rv_ld($pp)['review'] ?? []), 'reviewBody');
ok('  in the structured data as well: escaped there, and read back exactly as typed',
   in_array('<script>alert(1)</script> متوسط', $bodies, true) && strpos($pp, '\u003Cscript\u003E') !== false,
   json_encode($bodies, JSON_UNESCAPED_UNICODE));

head('the storefront\'s stars');
$ui  = (string) file_get_contents(SUPPEX_ROOT . '/assets/js/ui.js');
$cfg = (string) file_get_contents(SUPPEX_ROOT . '/assets/js/config.js');
ok('switched on, now that they are real', strpos($cfg, 'showRatings: true,') !== false);
ok('  and never drawn for a product without one', strpos($ui, "if (!cfg.flags.showRatings || !product || !(product.reviewCount > 0)) { return ''; }") !== false);
$layout = (string) file_get_contents(SUPPEX_ROOT . '/admin/partials/layout.php');
ok('the panel has «نظرات», with how many wait', strpos($layout, "['href' => 'reviews.php',  'label' => 'نظرات'") !== false
   && strpos($layout, 'function admin_reviews_waiting(): int') !== false);
$admin = (string) file_get_contents(SUPPEX_ROOT . '/admin/reviews.php');
ok('  its page checks the login and the form token', strpos($admin, '$user = auth_require();') !== false
   && strpos($admin, 'csrf_check();') !== false && substr_count($admin, '<?= csrf_field() ?>') === 3);

head('the Persian calendar');
foreach ([['2026-10-01 10:00:00', '۹ مهر ۱۴۰۵'], ['2026-03-21', '۱ فروردین ۱۴۰۵'], ['2026-03-20', '۲۹ اسفند ۱۴۰۴'],
          ['2024-03-20', '۱ فروردین ۱۴۰۳'], ['2026-12-22', '۱ دی ۱۴۰۵'], ['2000-01-01', '۱۱ دی ۱۳۷۸']] as [$g, $want]) {
    ok("  $g is $want", review_date_fa($g) === $want, review_date_fa($g));
}

/* Leave the catalogue as the other suites expect it. */
db_query('DELETE FROM product_reviews WHERE order_id IN (?, ?)', [(int) $a['id'], (int) $b['id']]);

echo "\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
