<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/* ===========================================================================
   The standing pages, and the journal index.
   ---------------------------------------------------------------------------
   Two different things are checked here and only one of them is about code.

   The first is the loader, which is the same escape-first renderer the journal
   uses, so the questions are the journal's questions: can a slug escape the
   content directory, and can anything in a file become a tag.

   The second is the CONTENT, and it is the reason this file is long. These
   pages exist because the shop makes promises on them - a seven-working-day
   withdrawal right, a refund route, a statement about what data is kept - and
   the failure mode that matters is not a broken page. It is two pages that
   disagree, or a page that quietly reintroduces a claim the shop cannot back.
   Those are pinned as assertions, because nothing else will catch them.
   =========================================================================== */

$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = 'suppex.ir';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/page.php';
$_SERVER['SCRIPT_NAME']    = '/page.php';

$pass = 0; $fail = 0; $out = [];
function ok(string $l, bool $c, ?string $x = ''): void {
    global $pass, $fail, $out;
    if ($c) { $pass++; $out[] = "  ok   $l"; }
    else { $fail++; $out[] = "  FAIL $l" . ($x ? "  --  $x" : ''); }
}
function head(string $t): void { global $out; $out[] = "\n== $t =="; }

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/pages.php';
require_once SUPPEX_ROOT . '/lib/seo.php';

head('all five pages load');
$all = pages_all();
ok('all five are found', count($all) === 5, (string) count($all));
ok('and in the order a customer meets them, not alphabetically',
   array_column($all, 'slug') === ['shipping', 'returns', 'faq', 'terms', 'privacy'],
   implode(',', array_column($all, 'slug')));

foreach (PAGE_ORDER as $slug) {
    $p = page_get($slug);
    ok("$slug loads", $p !== null);
    if ($p === null) { continue; }
    ok("  it has a title", $p['title'] !== '');
    /* The summary is the meta description. Empty means the search result for
       this page is whatever Google decides to quote. */
    ok("  a summary long enough to be a description", mb_strlen($p['summary']) > 40,
       (string) mb_strlen($p['summary']));
    ok("  a last-updated date", $p['updated'] !== '');
    ok("  and enough body to be worth linking to", mb_strlen($p['body']) > 2000,
       (string) mb_strlen($p['body']));
}

head('a slug cannot reach outside content/pages');
foreach ([
    '../journal/window',
    '../../lib/db',
    'shipping/../../../../etc/passwd',
    '..%2f..%2fconfig',
    'Shipping',              // the file is lower case; case must not be guessed at
    'ship ping',
    'shipping.md',
    '',
    'a/b',
] as $bad) {
    ok("refuses \"$bad\"", page_get($bad) === null);
}
ok('but the real slug still loads', page_get('shipping') !== null);
/* A file dropped into the directory does not become a footer link on every
   page of the site before anybody decided it should. */
ok('pages_all() lists only what PAGE_ORDER names',
   count(pages_all()) === count(array_filter(PAGE_ORDER, static fn(string $s): bool => page_get($s) !== null)));

head('nothing in a page file can become a tag');
$hostile = "## <script>alert(1)</script>\n\nمتن با <img src=x onerror=alert(2)> داخلش\n\n"
         . "- <b>مورد</b> و <iframe src=//evil>\n\n**<svg onload=alert(3)>**\n";
$html = journal_render($hostile);
preg_match_all('/<\/?([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>/', $html, $m);
$tags = array_values(array_unique($m[1]));
sort($tags);
ok('only the tags the renderer may emit appear',
   array_diff($tags, ['h2', 'h3', 'h4', 'li', 'p', 'span', 'strong', 'ul']) === [],
   implode(',', $tags));
ok("the attacker's markup survives as visible text, escaped",
   strpos($html, '&lt;img src=x onerror=alert(') !== false);

head('every page renders through the markdown subset, with nothing left over');
foreach (PAGE_ORDER as $slug) {
    $p = page_get($slug);
    if ($p === null) { continue; }
    $h = journal_render($p['body']);
    preg_match_all('/<\/?([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>/', $h, $m);
    $t = array_values(array_unique($m[1]));
    ok("$slug emits only allowed tags",
       array_diff($t, ['h2', 'h3', 'h4', 'li', 'p', 'span', 'strong', 'ul']) === [],
       implode(',', $t));
    /* The renderer has no anchor tag. A markdown link written into a file
       renders as literal [text](url), which looks like a typo to a customer
       and is invisible to whoever wrote it. */
    ok("  no markdown link syntax survived into $slug", strpos($p['body'], '](') === false);
    ok("  no h1 in the body - the page owns its h1", !preg_match('/^# /m', $p['body']));
}

head('the pages do not contradict each other');
$body = [];
foreach (PAGE_ORDER as $slug) {
    $p = page_get($slug);
    $body[$slug] = $p === null ? '' : $p['body'];
}
/* The withdrawal right is statutory - ماده ۳۷ قانون تجارت الکترونیکی - and a
   shop's own page cannot shorten it. shipping.md once imposed 48 hours for
   reporting a problem while returns.md promised in as many words that no
   shorter deadline would be imposed. A customer reading both had to assume the
   one that cost them a right. */
ok('the returns page states the seven working days',
   strpos($body['returns'], '۷ روز کاری') !== false);
ok('and the shipping page does not impose a shorter deadline',
   strpos($body['shipping'], '۴۸ ساعت') === false,
   'shipping.md is imposing 48 hours again');

head('no page reintroduces a claim the shop cannot back');
/* Everything here was on the site and was removed after the owner confirmed
   it was not true. These pages are the most likely place for it to come back,
   because they are where somebody writes reassuring sentences. */
/* The exact ASSERTIONS that were removed, not the bare nouns. These pages say
   «ما آزمایشگاه نداریم» and «هیچ سری تولیدی را آزمایش نمی‌کنیم», which are
   denials and are exactly what should be there - a check on the word
   «آزمایشگاه» alone would forbid the shop from admitting it has none. */
$forbidden = [
    'آزمایشگاه مستقل'   => 'no independent laboratory tests anything for this shop',
    'برگه آزمایش هر'    => 'there is no per-batch certificate',
    'برگه آزمایش مستقل' => 'and no independent one either',
    'هولوگرام'          => 'no hologram is applied to anything',
    'شرکت مکمل'         => 'no company is registered',
    '88776655'          => 'that landline is not real',
    'ولیعصر'            => 'there is no office',
    'سه برابر'          => 'nobody agreed to a triple-refund guarantee',
];
foreach (PAGE_ORDER as $slug) {
    foreach ($forbidden as $needle => $why) {
        ok("$slug does not claim: $needle", strpos($body[$slug], $needle) === false, $why);
    }
}

head('the page itself');
$render = function (string $slug): string {
    $_GET = ['p' => $slug]; $_POST = []; $_REQUEST = $_GET;
    ob_start();
    include SUPPEX_ROOT . '/page.php';
    return (string) ob_get_clean();
};

$page = $render('returns');
ok('renders', strlen($page) > 15000, (string) strlen($page));
ok('has exactly one h1', substr_count($page, '<h1') === 1);
ok('the title is in <title>', strpos($page, '<title>مرجوعی') !== false);
ok('says it is indexable - these answer the question a buyer has',
   strpos($page, 'index, follow') !== false);
ok('is canonical to itself', strpos($page, 'page.php?p=returns') !== false);
ok('carries WebPage schema, not Article',
   strpos($page, '"@type": "WebPage"') !== false && strpos($page, '"@type": "Article"') === false);
ok('shows when it was last updated', strpos($page, 'آخرین به‌روزرسانی') !== false);
ok('links to the other four', substr_count($page, 'page.php?p=') >= 5);
/* The whole value of this page is text a crawler and a preview bot can read
   without running JavaScript. */
/* The renderer isolates Latin digit runs in a .num span, so the citation
   arrives as «ماده <span class="num">۳۷</span>» - Persian numerals are left
   alone, but checking for the rendered shape rather than the source shape is
   the honest way to assert that the BODY reached the page. */
ok('the policy text is in the served markup',
   strpos($page, 'ماده ۳۷ قانون تجارت الکترونیکی') !== false);
ok('no PHP diagnostic leaked into the page',
   stripos($page, 'Warning:') === false && stripos($page, 'Notice:') === false);

head('a missing page is a real 404');
$missing = $render('no-such-page');
ok('says so on the page', strpos($missing, 'صفحه پیدا نشد') !== false);
ok('and asks not to be indexed', strpos($missing, 'noindex') !== false);
ok('and carries no schema', strpos($missing, 'application/ld+json') === false);

head('the journal index');
$_GET = []; $_POST = []; $_REQUEST = [];
ob_start();
include SUPPEX_ROOT . '/journal.php';
$jr = (string) ob_get_clean();
ok('renders', strlen($jr) > 10000, (string) strlen($jr));
ok('has exactly one h1', substr_count($jr, '<h1') === 1);
ok('lists all three articles', substr_count($jr, 'article.php?a=') >= 3);
ok('carries CollectionPage schema', strpos($jr, '"@type": "CollectionPage"') !== false);
ok('and an ItemList of the articles', strpos($jr, '"@type": "ItemList"') !== false);
ok('is indexable', strpos($jr, 'index, follow') !== false);
ok('no PHP diagnostic leaked', stripos($jr, 'Warning:') === false);

head('the sitemap knows about all of it');
$_GET = []; $_SERVER['REQUEST_URI'] = '/sitemap.php';
ob_start();
include SUPPEX_ROOT . '/sitemap.php';
$sitemap = (string) ob_get_clean();
ok('the sitemap is valid XML', simplexml_load_string($sitemap) !== false);
ok('it lists the journal index', strpos($sitemap, '/journal.php') !== false);
foreach (PAGE_ORDER as $slug) {
    ok("  and $slug", strpos($sitemap, 'page.php?p=' . $slug) !== false);
}

echo implode("\n", $out);
echo "\n\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
