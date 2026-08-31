<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/* ===========================================================================
   The shared page chrome.
   ---------------------------------------------------------------------------
   The header and footer used to be copied into five templates. They were
   byte-identical, which was luck: nothing checked, and the next edit to one of
   them was the one that would break it. article.php said so in its own header
   comment and the comment sat there for months.

   Three of the five are PHP and now include partials/. Two cannot: index.html
   and product.html are the static no-backend mode of the site and have no PHP
   to include anything with. So the thing that used to be luck is checked here
   instead.

   The strong check is product.html, whose chrome must stay byte-identical to
   the partials. index.html's is legitimately different - it is the home page,
   so its nav anchors are same-page rather than absolute - so what is pinned
   there is the part that actually drifts: the set of links in the footer.
   =========================================================================== */

$pass = 0; $fail = 0; $out = [];
function ok(string $l, bool $c, ?string $x = ''): void {
    global $pass, $fail, $out;
    if ($c) { $pass++; $out[] = "  ok   $l"; }
    else { $fail++; $out[] = "  FAIL $l" . ($x ? "  --  $x" : ''); }
}
function head(string $t): void { global $out; $out[] = "\n== $t =="; }

require __DIR__ . '/../lib/bootstrap.php';

$root = SUPPEX_ROOT;

/** A file, with every line ending and trailing space normalised away. */
function chrome_norm(string $s): string
{
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = implode("\n", array_map('rtrim', explode("\n", $s)));
    return trim($s);
}

/** The slice of a template between two markers, inclusive. */
function chrome_slice(string $s, string $from, string $to): ?string
{
    $i = strpos($s, $from);
    if ($i === false) { return null; }
    $j = strpos($s, $to, $i);
    if ($j === false) { return null; }
    return substr($s, $i, $j - $i + strlen($to));
}

/** What a partial actually renders. */
function chrome_render(string $file): string
{
    ob_start();
    include $file;
    return (string) ob_get_clean();
}

head('the partials exist and refuse to be requested directly');
foreach (['chrome-top', 'chrome-foot'] as $p) {
    $path = $root . '/partials/' . $p . '.php';
    ok("partials/$p.php exists", is_file($path));
    $src = (string) @file_get_contents($path);
    /* Included, never requested. On its own it would render a header with no
       page under it, which is a soft 404 waiting to be indexed. */
    ok("  it refuses to run outside the app",
       strpos($src, "!defined('SUPPEX_ROOT')") !== false);
}
ok('and the directory is denied by Apache as well',
   trim((string) @file_get_contents($root . '/partials/.htaccess')) === 'Require all denied');

head('every PHP page includes the chrome rather than carrying a copy');
foreach (['product.php', 'article.php', 'track.php', 'page.php', 'journal.php'] as $f) {
    $path = $root . '/' . $f;
    if (!is_file($path)) { continue; }
    $src = (string) file_get_contents($path);
    ok("$f includes chrome-top",  strpos($src, "partials/chrome-top.php") !== false);
    ok("$f includes chrome-foot", strpos($src, "partials/chrome-foot.php") !== false);
    /* The failure this catches is a page that was written by copying another
       one, which is how there came to be five copies in the first place. */
    ok("  and holds no copy of the footer itself",
       substr_count($src, '<footer class="footer"') === 0, $f);
}

head('product.html is byte-identical to the partials it cannot include');
$top  = chrome_norm(chrome_render($root . '/partials/chrome-top.php'));
$foot = chrome_norm(chrome_render($root . '/partials/chrome-foot.php'));
$ph   = (string) file_get_contents($root . '/product.html');

$phTop  = chrome_slice($ph, '<body>', '</header>');
$phFoot = chrome_slice($ph, '<footer class="footer"', '</html>');
ok('product.html has a header block', $phTop !== null);
ok('product.html has a footer block', $phFoot !== null);
/* Not a style preference. product.html is what a visitor sees in the static
   demo mode, and a footer link fixed in the partial and not here is a dead
   link that only appears in the mode nobody tests. */
ok('its header matches the partial exactly', chrome_norm((string) $phTop) === $top,
   'product.html has drifted from partials/chrome-top.php');
ok('its footer matches the partial exactly', chrome_norm((string) $phFoot) === $foot,
   'product.html has drifted from partials/chrome-foot.php');

head('index.html carries the same footer LINKS, whatever its markup does');
/* index.html's chrome is legitimately different - it is the home page, so its
   nav anchors are same-page rather than absolute, and it carries an
   explanatory comment the others do not. Comparing the markup would fail for
   reasons that are correct. What must not differ is where the links GO. */
$ix = (string) file_get_contents($root . '/index.html');
$ixFoot = chrome_slice($ix, '<footer class="footer"', '</footer>');
ok('index.html has a footer block', $ixFoot !== null);

$hrefs = static function (string $html): array {
    preg_match_all('/href="([^"]*)"/', $html, $m);
    $out = [];
    foreach ($m[1] as $h) {
        /* Same-page anchors are the one thing that is meant to differ: the
           home page links to #standard, every other page to
           index.html#standard. Compared without that prefix. */
        $h = preg_replace('#^index\.html(?=[#?]|$)#', '', $h);
        if ($h === '') { $h = 'index.html'; }
        $out[$h] = true;
    }
    ksort($out);
    return array_keys($out);
};

$partialFoot = chrome_slice($foot, '<footer class="footer"', '</footer>');
$a = $hrefs((string) $partialFoot);
$b = $hrefs((string) $ixFoot);
$missing = array_values(array_diff($a, $b));
$extra   = array_values(array_diff($b, $a));
ok('index.html links nowhere the partial does not',
   $extra === [], implode(', ', $extra));
ok('and misses nothing the partial links to',
   $missing === [], implode(', ', $missing));

head('no page in the chrome links into the void');
/* href="#" is not a link, it is a promise the page does not keep. Every one of
   them in the footer was exactly that for the whole life of this project. */
foreach (['partials/chrome-top.php', 'partials/chrome-foot.php',
          'index.html', 'product.html', '404.html'] as $f) {
    $path = $root . '/' . $f;
    if (!is_file($path)) { continue; }
    /* Only the MARKUP is scanned. Both partials open with a PHP block comment
       explaining what href="#" used to be here and why it is gone, and the
       markup itself carries HTML comments doing the same - a check that counts
       its own explanation as a violation is one nobody can make pass. */
    $src = (string) file_get_contents($path);
    if (substr($f, -4) === '.php') {
        $src = substr($src, (int) strpos($src, '?>') + 2);
    }
    $src = (string) preg_replace('/<!--.*?-->/s', '', $src);
    $n = preg_match_all('/href="#"/', (string) $src);
    ok("$f has no href=\"#\"", $n === 0, "$n found");
}

head('the claims that were removed do not come back');
/* Every string here was live on this site and was removed after the owner
   confirmed it was not true. They are pinned across the whole visitor-facing
   surface rather than per-file, because the last few survived a careful manual
   sweep by sitting in files nobody thought to re-read: the product page's
   assurance strip, and the catalogue seed that installs into the live
   database. */
$surface = [];
foreach (['index.html', 'product.html', '404.html', 'product.php', 'article.php',
          'track.php', 'page.php', 'journal.php',
          'partials/chrome-top.php', 'partials/chrome-foot.php',
          'assets/js/catalog.js', 'assets/js/ui.js', 'assets/js/app.js',
          'assets/js/config.js', 'db/catalog-seed.json'] as $f) {
    $path = $root . '/' . $f;
    if (!is_file($path)) { continue; }
    /* Comments explaining what was removed, and why, are not the claim coming
       back - so both comment syntaxes are stripped before the search. */
    $src = (string) file_get_contents($path);
    $src = (string) preg_replace('/<!--.*?-->/s', '', $src);
    $src = (string) preg_replace('~/\*.*?\*/~s', '', $src);
    $surface[$f] = $src;
}
ok('the whole visitor-facing surface was read', count($surface) >= 14,
   (string) count($surface));

$banned = [
    /* The ASSERTIVE forms only. An article titled «برگه آزمایش را چطور
       بخوانیم» teaches a buyer to read someone else's certificate, which is
       useful and true; banning the noun would forbid the shop from discussing
       the subject at all, and a check nobody can satisfy gets deleted. */
    'برگه آزمایش سری'  => 'the shop issues no per-batch certificate',
    'برگه آزمایش هر'   => 'nor one for every batch',
    'برگه آزمایش مستقل' => 'nor an independent one',
    'آزمایشگاه مستقل' => 'no independent laboratory tests anything here',
    'هولوگرام'       => 'no hologram is applied to anything',
    'سه برابر'       => 'nobody agreed to a triple-refund guarantee',
    'مشاوران ما'     => 'there is one person, and no stated credential',
    'شرکت مکمل'      => 'no company is registered',
    '88776655'       => 'that landline is not real',
    'ولیعصر'         => 'there is no office',
    'info@suppex.ir' => 'that mailbox does not exist; the setting stays empty until it does',
    'ساعت ۱۵'        => 'no dispatch cut-off is enforced anywhere',
    'شامل مالیات'    => 'no tax is computed or remitted',
    '<sup>®</sup>'   => 'nothing is registered',
];
foreach ($surface as $f => $src) {
    foreach ($banned as $needle => $why) {
        ok("$f has no \"$needle\"", mb_strpos($src, $needle) === false, $why);
    }
}

head('the footer is structurally sound');
/* The legal row and the watermark rendered OUTSIDE .container on every page,
   because four of the five copies of this footer carried one closing </div>
   too many and the extraction inherited it from article.php. Counting is the
   only way a test catches this - a browser silently repairs the markup and the
   page merely looks slightly wrong. */
$footSrc  = chrome_render($root . '/partials/chrome-foot.php');
$footOnly = (string) chrome_slice($footSrc, '<footer class="footer"', '</footer>');
$stripped = (string) preg_replace('/<!--.*?-->/s', '', $footOnly);
ok('div tags balance inside the footer',
   substr_count($stripped, '<div') === substr_count($stripped, '</div>'),
   substr_count($stripped, '<div') . ' open vs ' . substr_count($stripped, '</div>') . ' close');

head('nothing marked hidden can be un-hidden by a layout rule');
/* element.hidden reported true while getComputedStyle reported display:flex,
   so four social icons rendered as empty circles on every page. The `hidden`
   attribute lives in the UA stylesheet and ANY author display beats it. */
$css = (string) @file_get_contents($root . '/assets/css/base.css');
ok('base.css forces [hidden] to stay hidden',
   preg_match('/\[hidden\]\s*\{[^}]*display:\s*none\s*!important/', $css) === 1);

echo implode("\n", $out);
echo "\n\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
