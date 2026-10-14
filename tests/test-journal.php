<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP.
   bootstrap falls back to the real suppex-config.php when SUPPEX_CONFIG
   is unset - which is exactly what a web request has - so one GET to an
   uploaded tests/ folder could empty the live database. The .htaccess
   beside this file is process; this is code. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* ===========================================================================
   The journal: loader, Markdown subset, and the page.
   ---------------------------------------------------------------------------
   The renderer turns files into HTML, and those files will one day be edited by
   someone who is not thinking about HTML. So the tests that matter most here
   are not "does a heading become an h2" - they are "can anything in a file
   become a tag", and "can a slug reach a file outside the content directory".
   =========================================================================== */

$_SERVER["REMOTE_ADDR"]    = "127.0.0.1";
$_SERVER["HTTP_HOST"]      = "suppex.ir";
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["REQUEST_URI"]    = "/article.php";
$_SERVER["SCRIPT_NAME"]    = "/article.php";

$pass = 0; $fail = 0; $out = [];
function ok(string $l, bool $c, ?string $x = ""): void {
    global $pass, $fail, $out;
    if ($c) { $pass++; $out[] = "  ok   $l"; }
    else { $fail++; $out[] = "  FAIL $l" . ($x ? "  --  $x" : ""); }
}
function head(string $t): void { global $out; $out[] = "\n== $t =="; }

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/journal.php';
require_once SUPPEX_ROOT . '/lib/seo.php';

head("the three articles load");
$all = journal_all();
ok("all three are found", count($all) === 3, (string) count($all));
foreach (['overload', 'window', 'counterfeit'] as $slug) {
    $a = journal_get($slug);
    ok("$slug loads", $a !== null);
    if ($a === null) { continue; }
    ok("  it has a title", $a['title'] !== '');
    ok("  a category", $a['category'] !== '');
    ok("  a summary", mb_strlen($a['summary']) > 30);
    /* The cards promised 4-6 minutes for 1700-2400 word articles before this
       was corrected. A read time in single digits for a piece this long is the
       symptom, so it is pinned. */
    ok("  a read time that matches its length", $a['readTime'] >= 8, (string) $a['readTime']);
    ok("  and its sources", count($a['sources']) >= 8, (string) count($a['sources']));
}

head("a slug cannot reach outside content/journal");
foreach ([
    '../../lib/db',
    '../config.sample',
    'window/../../../../etc/passwd',
    '..%2f..%2fconfig',
    'Window',            // the file is lower case; case must not be guessed at
    'win dow',
    'window.md',
    '',
    'a/b',
] as $bad) {
    ok("refuses \"$bad\"", journal_get($bad) === null);
}
ok("but the real slug still loads", journal_get('window') !== null);

head("nothing in a file can become a tag");
$hostile = "## <script>alert(1)</script>\n\n"
         . "متن با <img src=x onerror=alert(2)> داخلش\n\n"
         . "- <b>مورد</b> و <iframe src=//evil>\n\n"
         . "**<svg onload=alert(3)>**\n\n"
         . "یک \"نقل قول\" و یک 'آپاستروف' و & و <\n";
$html = journal_render($hostile);
preg_match_all('/<\/?([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>/', $html, $m);
$tags = array_values(array_unique($m[1]));
sort($tags);
$allowed = ['h2', 'h3', 'h4', 'li', 'p', 'span', 'strong', 'ul'];
ok("only the eight tags the renderer is allowed to emit appear",
   array_diff($tags, $allowed) === [], implode(',', $tags));
/* The attacker's text must survive as text - proving it was escaped rather
   than stripped, which would hide the problem instead of neutralising it. */
ok("the attacker's markup survives as visible text, escaped",
   strpos($html, '&lt;img src=x onerror=alert(') !== false);
ok("an ampersand is escaped once, not twice",
   strpos($html, '&amp;amp;') === false);

head("the Markdown subset it is supposed to handle");
$md = "## عنوان\n\nپاراگراف اول\nروی دو خط\n\n- مورد یک\n- مورد دو\n\nپاراگراف با **تاکید** داخلش\n";
$h = journal_render($md);
ok("## becomes h2, never h1 - the page owns the h1", strpos($h, '<h2>عنوان</h2>') !== false, $h);
ok("no h1 is ever emitted from a body", strpos($h, '<h1') === false);
ok("two lines join into one paragraph",
   strpos($h, '<p>پاراگراف اول روی دو خط</p>') !== false, $h);
ok("a dash list becomes a ul", substr_count($h, '<li>') === 2);
ok("**bold** becomes strong", strpos($h, '<strong>تاکید</strong>') !== false);

head("Latin digits inside Persian are isolated, as everywhere else on the site");
$h = journal_render("در سال 2001 حدود 24 گرم بود\n");
ok("a bare number is wrapped in .num", substr_count($h, 'class="num"') === 2, $h);
ok("and the digits are inside the span", strpos($h, '<span class="num">2001</span>') !== false, $h);

head("front matter is read, and a broken file is not an article");
[$front, $body] = journal_split("---\ntitle: \"سلام\"\nreadTime: 7\nsources:\n  - \"یک\"\n  - \"دو\"\n---\nمتن\n");
ok("a quoted value loses its quotes", ($front['title'] ?? '') === 'سلام');
ok("a number is read", ($front['readTime'] ?? '') === '7');
ok("an indented list is collected", count($front['sources'] ?? []) === 2);
ok("the body is what follows the fence", trim($body) === 'متن');
[$f2, $b2] = journal_split("no front matter here\n");
ok("a file with no fence is all body", $f2 === [] && trim($b2) === 'no front matter here');

head("the page itself");
$page = (function (): string {
    $_GET = ['a' => 'window'];
    ob_start();
    include SUPPEX_ROOT . '/article.php';
    return (string) ob_get_clean();
})();
ok("renders", strlen($page) > 20000, (string) strlen($page));
ok("has exactly one h1", substr_count($page, '<h1') === 1);
ok("the title is in <title>", strpos($page, '<title>پنجره طلایی') !== false);
ok("declares og:type article", strpos($page, 'content="article"') !== false);
ok("carries Article JSON-LD, not Product",
   strpos($page, '"@type": "Article"') !== false && strpos($page, '"@type": "Product"') === false);
ok("is canonical to itself", strpos($page, 'article.php?a=window') !== false);
ok("says it is indexable", strpos($page, 'index, follow') !== false);
ok("lists its sources", substr_count($page, '<li>') >= 20);
ok("carries the not-medical-advice note", strpos($page, 'جای مشورت با پزشک') !== false);
/* The body has to be in the HTML, not fetched later: the entire value of an
   article is text a crawler and a preview bot can read without running JS. */
ok("the article text is in the served markup",
   strpos($page, 'دو مطالعه کوچک') !== false);
ok("no PHP notice leaked into the page",
   stripos($page, 'Warning:') === false && stripos($page, 'Notice:') === false);

head("the header wears its cover's colour");
ok("each cover gives the colour of its glow",
   journal_cover_tone('assets/images/journal/j1.svg') === '#F25914'
   && journal_cover_tone('assets/images/journal/j2.svg') === '#1FA894'
   && journal_cover_tone('assets/images/journal/j3.svg') === '#4A8FD6');
ok("nothing outside the journal's own covers is read",
   journal_cover_tone('../suppex-config.php') === ''
   && journal_cover_tone('assets/images/journal/../og-cover.png') === ''
   && journal_cover_tone('https://example.com/assets/images/journal/j1.svg') === '');
ok("a missing cover gives nothing, not a warning", journal_cover_tone('assets/images/journal/none.svg') === '');
ok("the page's header carries the tone",
   strpos($page, '<header class="article__head article__head--cover" style="--tone:#1FA894">') !== false);

head("a missing article is a real 404");
$missing = (function (): string {
    $_GET = ['a' => 'no-such-article'];
    ob_start();
    include SUPPEX_ROOT . '/article.php';
    return (string) ob_get_clean();
})();
ok("says so on the page", strpos($missing, 'مقاله پیدا نشد') !== false);
ok("and asks not to be indexed", strpos($missing, 'noindex') !== false);
ok("and carries no Article schema", strpos($missing, '"@type": "Article"') === false);

echo implode("\n", $out);
echo "\n\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
