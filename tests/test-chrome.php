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
foreach (['chrome-top', 'chrome-foot', 'head-assets'] as $p) {
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
foreach (['product.php', 'article.php', 'track.php', 'page.php', 'journal.php',
          'category.php', 'brand.php', 'brands.php'] as $f) {
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
/* The partials link the home page as «./», product.html as «index.html» -
   the same page, one address live and one that also works from the disk
   (see the top of chrome-top.php). Compared as the same link. */
$homeAsFile = static fn(string $s): string => (string) preg_replace('~href="\./(?=[#"])~', 'href="index.html', $s);
$shippingBefore = ['free_shipping_threshold' => setting('free_shipping_threshold', '1500000'),
                   'shipping_flat_rate' => setting('shipping_flat_rate', '65000')];
settings_save(['free_shipping_threshold' => '1500000', 'shipping_flat_rate' => '65000']);
$top  = chrome_norm($homeAsFile(chrome_render($root . '/partials/chrome-top.php')));
settings_save($shippingBefore);
$foot = chrome_norm($homeAsFile(chrome_render($root . '/partials/chrome-foot.php')));
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
/* The script tags are the one part that may differ: the partial computes
   each one's version (asset()) and sends the page's data before them
   (lib/boot.php), which a static file cannot. So the markup is compared
   without them, and the scripts by name and order. */
$noScripts = static fn(string $s): string =>
    chrome_norm((string) preg_replace('~[ \t]*<script\b[^>]*>.*?</script>[ \t]*\R?~s', '', $s));
$scriptNames = static function (string $s): array {
    preg_match_all('~<script src="assets/js/([a-z0-9-]+)\.js~', $s, $m);
    return $m[1];
};
ok('its footer matches the partial exactly', $noScripts((string) $phFoot) === $noScripts($foot),
   'product.html has drifted from partials/chrome-foot.php');
ok('  and loads the same scripts in the same order',
   $scriptNames((string) $phFoot) === $scriptNames($foot) && count($scriptNames($foot)) === 6,
   implode(',', $scriptNames((string) $phFoot)) . ' / ' . implode(',', $scriptNames($foot)));

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
        /* ~, not #, as the delimiter: the class below contains a #, and with
           # around it the pattern ended at [ - every call warned and returned
           null, so every link compared as the same empty string and this
           check could not fail. */
        $h = (string) preg_replace('~^index\.html(?=[#?]|$)~', '', $h);
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

head('index.html says the same things as the shared chrome');
/* The announcement bar is on every page and lives in the partial, except on
   index.html, which carries its own copy because it cannot include PHP. The
   claims purge changed index.html's copy and not the partial's, so the home
   page and every other page made different claims for a whole commit - and
   nothing noticed, because product.html was compared to the partial (both
   carried the old text) and index.html was compared only on its footer links.
   This is that missing eye. */
$announce = static function (string $html): string {
    $i = strpos($html, '<div class="announce">');
    if ($i === false) { return ''; }
    $j = strpos($html, '</div>', strpos($html, '</div>', $i) + 6);
    /* Text only: the markup around it legitimately differs nowhere, but
       comparing rendered TEXT is what actually matters for a claim. */
    $slice = substr($html, $i, ($j === false ? strlen($html) : $j) - $i);
    $slice = (string) preg_replace('/<[^>]*>/', ' ', $slice);
    return trim((string) preg_replace('/\s+/u', ' ', $slice));
};
$partialAnnounce = $announce($top);
$indexAnnounce   = $announce($ix);
ok('the partial has an announcement bar', $partialAnnounce !== '');
ok('index.html has one too', $indexAnnounce !== '');
ok('and they say the same thing', $partialAnnounce === $indexAnnounce,
   "partial: $partialAnnounce" . "  ||  index: $indexAnnounce");
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
          'track.php', 'page.php', 'journal.php', 'category.php', 'brand.php', 'brands.php',
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
ok('the whole visitor-facing surface was read', count($surface) >= 15,
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
    /* Nothing is photographed or sent before an order ships; the owner said
       so on 2026-09-24. The promise took three wordings - a tick on every
       product page, a home-page feature and a clause in the FAQ - and each
       is pinned. \u{200C} is the half-space the site types inside «می‌». */
    'عکس محصول پیش از ارسال'       => 'no photo is sent before an order ships',
    "خودِ محصول را می\u{200C}بینید" => 'nobody sees the product before it ships',
    "عکس می\u{200C}گیریم"           => 'no order is photographed',
    'عکس، همان بسته'              => 'nor is a photo of the parcel sent',
];
/* The policy pages are visitor-facing too, and the FAQ carried the photo
   promise in a wording of its own. Markdown has no comments to strip. */
$claimsSurface = $surface;
foreach (glob($root . '/content/pages/*.md') as $p) {
    $claimsSurface['content/pages/' . basename($p)] = (string) file_get_contents($p);
}
ok('  and so were the policy pages', count($claimsSurface) >= count($surface) + 5,
   (string) (count($claimsSurface) - count($surface)));
foreach ($claimsSurface as $f => $src) {
    foreach ($banned as $needle => $why) {
        ok("$f has no \"$needle\"", mb_strpos($src, $needle) === false, $why);
    }
}

/* The pins above are the wordings the promise did take. This is for the next
   one: «we photograph» or «a photo is sent» inside one sentence, either way
   round. What the pages ask a BUYER to do - «عکس بگیرید», «بفرستید» - is
   second person and does not match, and the lookbehind keeps «برعکس» out. */
$zw    = '[\x{200C} ]?';
$verb  = "(?:می{$zw}(?:گیریم|فرستیم)|فرستاده{$zw}می{$zw}شود|ارسال{$zw}می{$zw}(?:کنیم|شود))";
$photo = '/(?<!\p{L})عکس[^.؛!؟\n]{0,80}?' . $verb . '|' . $verb . '[^.؛!؟\n]{0,80}?(?<!\p{L})عکس/u';
$prose = $claimsSurface;
foreach (glob($root . '/content/journal/*.md') as $p) {
    $prose['content/journal/' . basename($p)] = (string) file_get_contents($p);
}
foreach ($prose as $f => $src) {
    ok("$f promises no photo before shipping, in any wording",
       preg_match($photo, $src, $pm) === 0, $pm[0] ?? '');
}

head('the «شفافیت» list still counts properly');
/* Taking the photo item out left «01» and «03»: the numbers are typed by
   hand, so they are checked rather than trusted. */
$std = (string) chrome_slice($surface['index.html'], 'id="standard"', '</section>');
preg_match_all('/<span class="feature__num">(\d+)<\/span>/', $std, $fn);
$want = [];
for ($i = 1; $i <= count($fn[1]); $i++) { $want[] = sprintf('%02d', $i); }
ok('its items are numbered 01, 02, … with no gap',
   count($fn[1]) >= 2 && $fn[1] === $want, implode(',', $fn[1]));
$lastFeature = strrpos($std, '<div class="feature reveal"');
ok('  and the last one still draws the closing rule',
   $lastFeature !== false && strpos(substr($std, $lastFeature, 120), 'border-bottom') !== false);

head('no PHP string interpolates a variable straight into a Persian character');
/* PHP identifier characters are [a-zA-Z_\x80-\xff], so EVERY byte of a
   multibyte character is a legal part of a variable name. "«$name»" therefore
   reads the variable as $name» - undefined - and renders with the value
   missing. This project writes Persian around interpolated values on almost
   every page, and the failure is silent enough that it took a mystery test
   failure to notice. {$name} is the fix. */
$interp = 0;
$offenders = [];
$dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($dir as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') { continue; }
    $path = str_replace('\\', '/', $file->getPathname());
    if (strpos($path, '/.git/') !== false || strpos($path, '/uploads/') !== false) { continue; }
    /* Comments stripped first - this check's own explanation contains the
       very pattern it looks for, and a check that fails on its own
       documentation is one somebody deletes rather than fixes. */
    $src = (string) preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '',
                                 (string) @file_get_contents($path));
    if (preg_match_all('/\$[A-Za-z_][A-Za-z0-9_]*[\x80-\xff]/', $src, $mm)) {
        $offenders[] = str_replace($root . '/', '', $path) . ': ' . implode(' ', array_slice($mm[0], 0, 3));
        $interp += count($mm[0]);
    }
}
ok('no bare $var runs into a multibyte character in any PHP file',
   $interp === 0, implode(' | ', $offenders));

head('no real credential is sitting in a tracked file');
/* config.sample.php is the file people edit by mistake: it is the one they open
   to copy from, so filling it in instead of copying it is the natural slip -
   and unlike suppex-config.php it is tracked, so the password goes to the
   repository with the next commit. This caught exactly that, once. */
$sample = (array) @include $root . '/config.sample.php';
ok('config.sample.php has an empty database password',
   ($sample['db']['pass'] ?? '') === '', 'a real password is in the sample config');
ok('  and placeholder names',
   strpos((string) ($sample['db']['name'] ?? ''), 'CPANELUSER') === 0,
   (string) ($sample['db']['name'] ?? ''));
foreach (['telegram_bot' => 'token', 'sms' => 'api_key'] as $grp => $key) {
    ok("  and no $grp $key", (string) ($sample[$grp][$key] ?? '') === '');
}
/* The real config legitimately sits beside the code on a development machine -
   that is how the site runs there without an environment variable. What must
   never happen is git taking it, so THAT is what is asserted: if the file
   exists, .gitignore names it. On the server it lives above public_html and
   this is moot. */
if (is_file($root . '/suppex-config.php')) {
    $ignore = (string) @file_get_contents($root . '/.gitignore');
    ok('the real config exists here, and .gitignore refuses it',
       strpos($ignore, 'suppex-config.php') !== false,
       'a file holding the database password is one commit from the repository');
    ok('  and so are archives of the whole tree',
       strpos($ignore, '*.zip') !== false);
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

head('the footer says each thing once');
/* It was 1327px tall on a 640px screen and «قوطی» wide of what the owner
   wanted it to say. Three of its twenty-five links - قوانین, حریم خصوصی and
   مرجوعی - appeared in a column AND in the legal row underneath, which is
   exactly the kind of thing nobody spots in review and everybody feels while
   scrolling. */
preg_match_all('/href="([^"#][^"]*)"/', $stripped, $fm);
$seen = [];
$dupes = [];
foreach ($fm[1] as $h) {
    if (isset($seen[$h])) { $dupes[$h] = true; }
    $seen[$h] = true;
}
ok('no destination is linked twice in the footer',
   $dupes === [], implode(', ', array_keys($dupes)));
/* Decoration is the first thing to go when the complaint is length: this one
   was 128px of ghost wordmark on a phone and up to 208px on a desktop. */
ok('the oversized ghost wordmark is gone',
   strpos($footOnly, 'footer__mark') === false);
/* The panel has had a bale_url field since the channels were built, and an
   order can be placed there - but storefront_config() never carried the key,
   so the footer could not show the icon however carefully it was filled in. */
ok('every channel the panel can hold has a footer icon',
   strpos($footOnly, 'data-social="bale"') !== false);
$settings = (string) @file_get_contents($root . '/lib/settings.php');
ok('  and storefront_config sends its URL',
   strpos($settings, "'bale'      => 'bale_url'") !== false);

head('the words the owner asked us to drop stay dropped');
/* «قوطی» is not false - these really are sealed tubs - so it does not belong
   in the claims list above. It is a decision: the owner would rather the site
   said the product is ORIGINAL than describe its packaging, and the word was
   in seventeen places when the owner asked.

   «سری تولید» goes with it. The batch number was trade language a worried
   buyer does not think in, and the sentence it lived in promised a photograph
   of the expiry date before every shipment - which is not something the owner
   sends. */
$vocabulary = $surface;
foreach (['content/pages/faq.md', 'content/pages/returns.md',
          'content/pages/shipping.md', 'content/pages/terms.md'] as $f) {
    $vocabulary[$f] = (string) @file_get_contents($root . '/' . $f);
}
foreach ($vocabulary as $f => $src) {
    /* Comments were already stripped from $surface. The markdown files carry
       none, and a check that failed on its own explanation is one somebody
       deletes rather than fixes. */
    foreach (['قوطی' => 'the owner asked for «محصول اورجینال» instead',
              'سری تولید' => 'the batch number is trade language, and the owner does not send it'] as $needle => $why) {
        ok("$f has no \"$needle\"", mb_strpos($src, $needle) === false, $why);
    }
}

head('the price on the product page is the whole price');
/* The flavour chips carried «+160,000». It read as a surcharge for having a
   preference one click before the buyer commits, and it is not even how the
   price is stored any more - a product with combinations holds one number per
   real thing. What must stay true is the half that made dropping it safe:
   picking a flavour repaints the headline. */
$app = $surface['assets/js/app.js'];
ok('no chip quotes a surcharge', strpos($app, 'chip__extra') === false);
/* Sliced to the handler itself. Matching paintPrice() anywhere in the file
   would pass on the SIZE handler, which has always repainted - and the
   flavour one not repainting is the exact bug that once let a premium
   flavour reach the cart at the base price. */
$fh = strpos($app, 'flavorWrap.addEventListener');
$flavourHandler = $fh === false ? '' : substr($app, $fh, 900);
ok('  and the flavour handler still repaints the price',
   strpos($flavourHandler, 'paintPrice()') !== false);
ok('  and repaints the picture with it',
   strpos($flavourHandler, 'paintChoiceImage()') !== false);
/* .num sets direction:ltr on whatever carries it, and `text-align: end`
   resolves against the element's own direction - so a nutrition table aligned
   its numeric rows to one edge and its «مقدار» header to the other. */
ok('the nutrition value cell carries no direction-flipping class',
   strpos($app, '</th><td class="num">') === false);
/* lib/products.php always sets $p['nutrition'], so `if (product.nutrition)`
   was true for every product ever made and the «هنوز ثبت نشده» message had
   never run once. All four active products on the live site return an
   empty rows list, so all four opened on a table containing the word
   «مقدار» and nothing else. The guard has to read the ROWS. */
ok('an empty nutrition table is not drawn at all',
   strpos($app, 'if (product.nutrition) {') === false
   && preg_match('/var nRows = .*?
\s*if \(nRows\.length\) \{/s', $app) === 1);
$comp = (string) @file_get_contents($root . '/assets/css/components.css');
ok('  and components.css names the edge outright',
   preg_match('/\.spec td \{[^}]*text-align: right/s', $comp) === 1);

head('the shop reads Persian digits and the panel reads Latin');
/* Two formatters, two audiences. The shop shows «۱,۸۰۰,۰۰۰ تومان» because the
   owner asked for it; the panel, the margin tables and the Telegram
   notification stay Latin because somebody reconciling a card transfer against
   a banking app should not have to read one figure in two shapes. Getting
   these backwards is silent - the page still renders, just in the wrong
   script for whoever is looking at it. */
$util = (string) @file_get_contents($root . '/lib/util.php');
ok('lib/util.php has both formatters',
   strpos($util, 'function money(int $value)') !== false
   && strpos($util, 'function money_fa(int $value)') !== false);
ok('  money() is still the Latin one',
   preg_match('/function money\(int \$value\): string\s*\{\s*return number_format\(\$value\);/s', $util) === 1);
ok('  and money_fa() converts the digits',
   preg_match('/function money_fa\(int \$value\): string\s*\{\s*return fa_digits\(number_format\(\$value\)\);/s', $util) === 1);

foreach (['category.php', 'product.php', 'track.php'] as $f) {
    $src = (string) preg_replace('/<!--.*?-->/s', '',
           (string) @file_get_contents($root . '/' . $f));
    ok("$f prices the shop in Persian", strpos($src, 'money_fa(') !== false);
    /* money( with no _fa, and not preceded by a letter or underscore. */
    ok("  and calls no Latin money() on a customer page",
       preg_match('/(?<![A-Za-z_])money\(/', $src) === 0,
       'a panel formatter is rendering to a customer');
}
/* The other direction: the owner's own message must NOT be converted. */
$notify = (string) @file_get_contents($root . '/lib/notify.php');
ok('the Telegram notification stays Latin',
   strpos($notify, 'money_fa(') === false && strpos($notify, 'money(') !== false);
/* Nothing machine-read moves. Google wants a plain decimal in the offer, and
   lib/seo.php has always built it from the integer rather than a formatter. */
$seo = (string) @file_get_contents($root . '/lib/seo.php');
ok('the JSON-LD offer price is still built from the integer',
   strpos($seo, 'money') === false);
/* Tried and reverted: og_text() reorders to visual order itself and reads
   Latin digits as the marker for a left-to-right run, so Persian digits joined
   the RTL run and the card came out «۰۰۰,۰۰۸,۱ ناموت». */
$ogimg = (string) @file_get_contents($root . '/lib/ogimage.php');
/* Comments stripped before the search: the note explaining why money_fa()
   is NOT used here contains the string money_fa(, and a check that fails on
   its own documentation is one somebody deletes rather than fixes. */
$ogCode = (string) preg_replace('~/\*.*?\*/~s', '', $ogimg);
ok('the social card keeps Latin digits',
   strpos($ogCode, 'money_fa(') === false,
   'if this card is ever converted, look at the PNG before believing it');
ok('  and the reason is written down beside it',
   mb_strpos($ogimg, 'ناموت') !== false);

$cfg = $surface['assets/js/config.js'];
ok('config.js formats prices in Persian digits',
   strpos($cfg, '0x06F0') !== false);
ok('  and no longer claims the digits stay Latin',
   mb_strpos($cfg, 'Digits stay Latin') === false);
/* Hand-written prices in the markup went through no formatter at all, so the
   announcement bar sat above a page of Persian prices reading «1,500,000». */
foreach (['index.html', 'product.html', 'partials/chrome-top.php'] as $f) {
    $src = (string) preg_replace('/<!--.*?-->/s', '',
           (string) @file_get_contents($root . '/' . $f));
    ok("$f writes no Latin-grouped price into its markup",
       preg_match('/>[^<]*[0-9]{1,3},[0-9]{3}/', $src) === 0);
}

head('the size rows say what the owner wants them to say');
/* «۸۵,۰۰۰ در هر وعده» was a real argument for the bigger tub, and the owner does not
   want it on the page. The stock warnings that share the slot stay: those
   change what somebody does next. */
$app = $surface['assets/js/app.js'];
ok('no per-serving figure is computed', strpos($app, 'perServing') === false);
ok('  and the stock warning still is',
   mb_strpos($app, 'عدد مانده') !== false && mb_strpos($app, 'تمام شد') !== false);
/* Sliced to the size-row builder. ui.unitHtml() appears twice in this file -
   the headline price uses it too - so searching the whole file passes even
   when the rows have been changed back to a hand-written span. */
$sw = strpos($app, 'sizeWrap.innerHTML = sizes.map');
$sizeBlock = $sw === false ? '' : substr($app, $sw, 1400);
ok('  and the size price carries its unit',
   strpos($sizeBlock, 'ui.unitHtml(cfg.currency.label)') !== false);

head('«تومان» is part of the price, not a footnote on it');
/* The owner sent a mock-up: same colour as the figure, about half its size.
   The trap is the selector. .price__unit is ALSO what «ارسال رایگان بالای
   ۱,۵۰۰,۰۰۰» uses, sitting outside .price__now on the product page - style
   the bare class and a shipping threshold starts shouting at the same
   volume as the price. */
$comp2 = (string) @file_get_contents($root . '/assets/css/components.css');
ok('the unit is styled inside .price__now',
   preg_match('/\.price__now \.price__unit \{[^}]*color: inherit/s', $comp2) === 1);
ok('  and the bare class keeps its footnote look for whatever uses it next',
   preg_match('/^\.price__unit \{[^}]*--text-dim/m', $comp2) === 1);
/* One helper per side builds this markup - ui.unitHtml() in the browser,
   price_unit_html() on the server - so a price rendered by PHP and the same
   price repainted by JS cannot come out looking different. Four call sites
   spelling the same spans by hand is how they drift. */
$cat  = (string) @file_get_contents($root . '/category.php');
$prod = (string) @file_get_contents($root . '/product.php');
$util2 = (string) @file_get_contents($root . '/lib/util.php');
$ui   = $surface['assets/js/ui.js'];
ok('the server builds the unit through one helper',
   strpos($util2, 'function price_unit_html(') !== false
   && strpos($cat, 'price_unit_html()') !== false
   && strpos($prod, 'price_unit_html($unit)') !== false);
ok('  and the browser through its twin',
   strpos($ui, 'function unitHtml(') !== false
   && strpos($ui, 'unitHtml: unitHtml') !== false);
ok('  and no page spells the unit span by hand any more',
   strpos($cat, '<span class="price__unit">') === false
   && strpos($prod, '<span class="price__unit">') === false);

/* «تومان» is drawn as two lines, «ن» over «توما». Everything below is what
   stops that split from leaking out of the styling and into the data. */
$comp3 = (string) @file_get_contents($root . '/assets/css/components.css');
/* Generated content is not copied and the whole word is still in the DOM, so
   a customer pasting a price into a chat gets «تومان», not «نتوما». */
ok('the visible halves are generated content, not text nodes',
   preg_match('/\\.price__now \\.price__unit::before \\{ content: attr\\(data-head\\)/', $comp3) === 1
   && preg_match('/\\.price__now \\.price__unit::after  \\{ content: attr\\(data-tail\\)/', $comp3) === 1);
ok('  and the whole word stays in the element for copy and screen readers',
   strpos($util2, 'class="sr-only"') !== false
   && strpos($ui, 'class="sr-only"') !== false);
/* config.js says a switch to Rial should touch one function. A «تومان»
   written into a stylesheet would quietly outlive the label it came from. */
ok('  and neither half is hard-coded in the CSS',
   mb_strpos($comp3, 'content: "توما') === false
   && mb_strpos($comp3, "content: 'توما") === false);
ok('  so the split follows the label instead',
   strpos($ui, 's.slice(-1)') !== false && strpos($util2, "mb_substr(\$label, -1)") !== false);
/* An inline-block takes its baseline from its LAST line box, which is «توما».
   Two real spans could not do this: one ordering breaks the copy, the other
   hangs the unit below the number by an offset that is not a constant. */
ok('  and the stack is an inline-block so «توما» lands on the baseline',
   preg_match('/\\.price__now \\.price__unit \\{[^}]*display: inline-block/s', $comp3) === 1);

head('nothing on a page is wider than the phone it is on');
/* Measured on suppex.ir before this: the document was 391px wide on a 375px
   phone, 382 on 360 and 371 on 320. Chrome answers that by widening the
   page and shifting every full-width box sideways - the owner's screenshots,
   with the header and the headings cut off down one edge. */
$layoutCss = (string) @file_get_contents($root . '/assets/css/layout.css');
$compCss   = (string) @file_get_contents($root . '/assets/css/components.css');
$baseCss   = (string) @file_get_contents($root . '/assets/css/base.css');
ok('the four page sections clip what sticks out sideways',
   preg_match('/main,\s*\.announce,\s*\.header,\s*\.footer\s*\{\s*overflow-x:\s*clip;\s*\}/', $layoutCss) === 1);
/* On html it would work too - and stop body's overflow reaching the
   viewport, which is what the drawer scroll lock (body.is-locked) relies on. */
ok('  and html is left alone, so the drawer scroll lock still reaches the viewport',
   preg_match('/(^|[};\s])html\s*\{[^}]*overflow-x\s*:\s*clip/', $baseCss . $layoutCss . $compCss) === 0);
/* The net is not the fix. The two things that were actually too wide: */
ok('a narrow card gives the price a line of its own',
   strpos($compCss, '.pcard__body { container: pcard / inline-size; }') !== false
   && strpos($compCss, '@container pcard (max-width: 175px)') !== false);
ok('  and a very narrow one puts the button under it',
   strpos($compCss, '@container pcard (max-width: 109px)') !== false);
ok('the header tightens its spacing below 400px',
   preg_match('/@media \(max-width: 400px\) \{\s*\.header__inner \{ gap: var\(--sp-2\); \}/', $layoutCss) === 1);
ok('  and gives up the wordmark below 340px rather than a tap target',
   preg_match('/@media \(max-width: 339px\) \{\s*\.logo__text \{ display: none; \}/', $layoutCss) === 1
   && preg_match('/\.icon-btn \{[^}]*width: 44px; height: 44px;/s', $compCss) === 1);
ok('the feature ticks stay beside their text',
   strpos($compCss, '.pdp__about li > .u-sm { flex: 1 1 0; min-width: 0; }') !== false);

head('the logo is drawn, its orange S the first letter of the word (2026-10-05)');
/* The owner chose the s3-shear mark and asked for it in place of the S of
   SUPPEX, so the word has one S. Paths, not a font: an SVG that names a font
   is drawn in Arial Black by everything that does not load it - Google,
   Telegram - which is what logo.svg used to be. */
foreach (['partials/chrome-top.php', 'partials/chrome-foot.php', 'index.html', 'product.html', '404.html'] as $f) {
    $src = (string) @file_get_contents($root . '/' . $f);
    ok("$f draws the S and then UPPEX", strpos($src, '<svg class="logo__mark" viewBox="0 0 95.04 96"') !== false
       && strpos($src, '<svg class="logo__text" viewBox="0 0 460 96" fill="currentColor"') !== false);
    ok('  and no longer types the name in a font', strpos($src, '<span class="logo__text"') === false);
}
ok('the S stays in front of the word on an rtl page', preg_match('/\.logo \{[^}]*direction: ltr;/', $layoutCss) === 1);
ok('the footer keeps the logo cream, not its links\' grey',
   strpos($layoutCss, '.footer .logo, .footer .logo:hover { color: var(--text); }') !== false);
$logoSvg = (string) @file_get_contents($root . '/assets/icons/logo.svg');
ok('logo.svg, the one Google reads, is paths only',
   $logoSvg !== '' && strpos($logoSvg, '<text') === false && strpos($logoSvg, '<style') === false
   && strpos($logoSvg, 'fill="#F25914"') !== false);
ok('  with dark letters, for the white Google shows it on', strpos($logoSvg, 'fill="#131210"') !== false);
ok('  and no ®: nothing is registered', strpos($logoSvg, '®') === false);
$headAssets = (string) @file_get_contents($root . '/partials/head-assets.php');
ok('the tab icon is the S, and versioned so a browser lets the old one go',
   strpos((string) @file_get_contents($root . '/assets/icons/favicon.svg'), 'fill="#F25914"') !== false
   && strpos($headAssets, "asset('assets/icons/favicon.svg')") !== false);
ok('  and an iPhone gets an icon of its own',
   strpos($headAssets, "asset('assets/icons/apple-touch-icon.png')") !== false
   && is_file($root . '/assets/icons/apple-touch-icon.png'));

head('the product page price shows the saving, not the shipping threshold');
$app2 = $surface['assets/js/app.js'];
ok('«ارسال رایگان بالای …» is gone from beside the price',
   mb_strpos($app2, 'ارسال رایگان بالای') === false);
/* The Cell-Tech page never showed its discount: the product carries it, its
   size and combinations do not, and the repaint dropped it. */
ok('the struck price falls back to the product\'s own figure',
   strpos($app2, 'compare = beforePrice(product, shown, compare);') !== false);
ok('  and the saving is drawn beside it', strpos($app2, 'price__off') !== false);
ok('the server draws the saving too, so the page does not jump when the script runs',
   strpos($surface['product.php'], 'price__off') !== false);
foreach (['product.php', 'product.html'] as $f) {
    ok("$f no longer lists «عکس محصول پیش از ارسال»",
       mb_strpos($surface[$f], 'عکس محصول پیش از ارسال') === false);
}

head('the product pictures: their own scroll area, arrows, and an enlarged view');
/* On a desktop the sticky gallery was taller than the screen: the thumbnails
   sat under the fold, and a scroll over them moved the buy box instead. The
   owner asked for the wheel over the pictures to move the pictures, and the
   wheel anywhere else to move the page. */
$lay6 = (string) @file_get_contents($root . '/assets/css/layout.css');
ok('on a desktop the gallery is its own scroll area',
   preg_match('/\.pdp__gallery \{[^}]*position: sticky;[^}]*overflow-y: auto;/s', $lay6) === 1);
ok('  as tall as the screen allows',
   preg_match('/\.pdp__gallery \{[^}]*max-height: calc\(100dvh - 96px/s', $lay6) === 1);
ok('  and it does not hand its scroll on to the page',
   preg_match('/\.pdp__gallery \{[^}]*overscroll-behavior: contain;/s', $lay6) === 1);
foreach (['product.php', 'product.html'] as $f) {
    $src = $surface[$f];
    ok("$f has the arrows, labelled for a screen reader",
       strpos($src, 'data-gallery-step="-1" aria-label="تصویر قبلی"') !== false
       && strpos($src, 'data-gallery-step="1" aria-label="تصویر بعدی"') !== false);
    ok("  and a button into the enlarged view", strpos($src, 'data-gallery-open aria-label="نمایش بزرگ تصویر"') !== false);
    ok("  and a count", strpos($src, 'data-gallery-count') !== false);
}
$app6 = $surface['assets/js/app.js'];
/* A dialog opened with showModal() is inert behind, closes on Escape and
   hands focus back - the three things a hand-built overlay gets wrong. */
ok('the enlarged view is a modal dialog', strpos($app6, 'dlg.showModal();') !== false);
ok('  and the phone\'s back button closes it rather than leaving the page',
   strpos($app6, "history.pushState({ lightbox: true }, '')") !== false
   && strpos($app6, "window.addEventListener('popstate'") !== false);
/* The close event is a queued task a background tab can hold back: the page
   stayed locked, with nothing on it, until the tab came forward. */
ok('  and cleans up straight away, not only when the close event arrives',
   strpos($app6, "dlg.addEventListener('close', finish);") !== false
   && preg_match('/function shut\(\) \{\s*if \(dlg\.open\) \{ dlg\.close\(\); \}\s*finish\(\);/', $app6) === 1);

head('the owner\'s notes on the product page, and the cache behind one of them');
$lay7 = (string) @file_get_contents($root . '/assets/css/layout.css');
ok('the gallery scrolls without a scrollbar',
   preg_match('/\.pdp__gallery \{[^}]*scrollbar-width: none;/s', $lay7) === 1
   && strpos($lay7, '.pdp__gallery::-webkit-scrollbar { display: none; }') !== false);
foreach (['product.php', 'product.html'] as $f) {
    $src = $surface[$f];
    /* A phone with an older app.js cached got a magnifier that did nothing. */
    ok("$f: the magnifier waits for the script that answers it",
       strpos($src, 'data-gallery-open aria-label="نمایش بزرگ تصویر" hidden>') !== false);
    $flav = strpos($src, 'data-p-flavors');
    $size = strpos($src, 'data-p-sizes');
    $price = strpos($src, 'data-p-price');
    $buy = strpos($src, 'class="pdp__buy"');
    ok("  and the price comes after the choices, just before the button",
       $flav !== false && $size !== false && $price !== false && $buy !== false
       && $flav < $price && $size < $price && $price < $buy);
}
ok('app.js shows the magnifier once it is wired', strpos($surface['assets/js/app.js'], 'zoomBtn.hidden = false;') !== false);
$ht = (string) @file_get_contents($root . '/.htaccess');
/* The host serves .js as text/javascript, so a rule by MIME type never matched
   and each browser guessed how long to keep app.js. By file name it cannot miss. */
ok('CSS and JS are checked with the server on every load',
   preg_match('/<FilesMatch "\\\\\.\(css\|js\)\$">\s*Header set Cache-Control "no-cache"/', $ht) === 1);
ok('  and no MIME-type lifetime is left to disagree with it',
   strpos($ht, 'ExpiresByType application/javascript') === false && strpos($ht, 'ExpiresByType text/css') === false);

head('receipts and «سفارش‌های من»: the decisions that keep them private');
/* A receipt is a card number, a name and an amount. */
$recLib = (string) @file_get_contents($root . '/lib/receipts.php');
$apiSrc = (string) @file_get_contents($root . '/api/index.php');
$viewer = (string) @file_get_contents($root . '/admin/receipt.php');
$privMd = (string) @file_get_contents($root . '/content/pages/privacy.md');
ok('a receipt is kept beside the site, not inside it',
   strpos($recLib, "dirname(SUPPEX_ROOT) . '/suppex-private'") !== false);
ok('  and never in the public uploads folder', strpos($recLib, 'uploads_dir()') === false);
$authAt = strpos($viewer, 'auth_require();');
$readAt = strpos($viewer, 'readfile(');
ok('the viewer asks for a login before it reads a byte',
   $authAt !== false && $readAt !== false && $authAt < $readAt);
ok('  and a shared computer\'s cache keeps no copy', strpos($viewer, 'Cache-Control: private, no-store') !== false);
ok('the receipt route proves the order before it touches the file',
   preg_match("/case 'receipt':.*?track_find\(.*?receipt_store\(/s", $apiSrc) === 1);
ok('  and is throttled on both counts',
   preg_match("/case 'receipt':.*?track_is_throttled\(\) \|\| receipt_is_throttled\(\)/s", $apiSrc) === 1);
/* A customer's own list never misses: only misses count, and a batch stops
   at the throttle, so it buys no more guesses than the form. */
ok('«سفارش‌های من» counts only misses, and stops at the throttle',
   preg_match("/case 'myorders':.*?if \(\\\$found === null\) \{\s*track_record_attempt\(\);\s*if \(track_is_throttled\(\)\) \{\s*break;/s", $apiSrc) === 1);
$trk = $surface['track.php'];
ok('the tracking page has the list, the slot for a receipt, and remembers what it finds',
   strpos($trk, 'data-my-orders') !== false && strpos($trk, 'data-receipt-slot') !== false
   && strpos($trk, 'data-found-order') !== false);
ok('the privacy page says what the browser keeps now', strpos($privMd, 'suppex.orders.v1') !== false);
ok('  and what happens to a receipt', strpos($privMd, '## رسید واریز') !== false);

head('receipts on a phone, and one message per chat');
/* Where a phone still holding an old ui.js is retired. On the live site
   every script is asked for under its own version - asset(), the file's
   time - in the partial every PHP page ends with, and in index.php, which
   rewrites index.html's tags. .htaccess keeps these for a year, so a page
   that asked for one without its version would pin a stale copy on every
   phone that saw it. */
$footSrc  = (string) @file_get_contents($root . '/partials/chrome-foot.php');
$indexSrc = (string) @file_get_contents($root . '/index.php');
ok('every script in the partial comes through asset()',
   strpos($footSrc, 'e(asset(\'assets/js/\' . $script . \'.js\'))') !== false
   && strpos($footSrc, 'foreach ([\'config\', \'catalog\', \'repo\', \'store\', \'ui\', \'app\'] as $script)') !== false
   && preg_match('~<script src="assets/js/~', $footSrc) === 0);
ok('  and the home page\'s, through index.php', strpos($indexSrc, 'e(asset(\'assets/js/\' . $script . \'.js\'))') !== false
   && strpos($indexSrc, "e(asset('assets/js/app.js'))") !== false);
/* The static pages - the demo, and index.html on its own where index.php is
   missing - cannot compute a version, so they carry one date between them.
   Live, both are redirected: product.html to product.php, index.html to «/». */
$scriptVers = [];
$scriptBare = [];
foreach (['index.html', 'product.html'] as $f) {
    $src = (string) @file_get_contents($root . '/' . $f);
    preg_match_all('~<script src="assets/js/([a-z0-9-]+)\.js(?:\?v=([0-9]{8}))?"~', $src, $mm, PREG_SET_ORDER);
    foreach ($mm as $hit) {
        if (empty($hit[2])) { $scriptBare[] = "$f: {$hit[1]}.js"; } else { $scriptVers[$hit[2]] = true; }
    }
}
ok('every static script tag carries the date that retires the old cached copies',
   $scriptBare === [] && $scriptVers !== [], implode(', ', $scriptBare));
ok('  one date on both static pages, or one file is cached under two names', count($scriptVers) === 1,
   implode(', ', array_keys($scriptVers)));
$app7 = $surface['assets/js/app.js'];
ok('the payment step pins the receipt under the panel',
   strpos($app7, "setPayDock(server && server.code ? String(server.code) : '');") !== false
   && strpos($app7, "ui.receiptBlock(code, '', 'dock')") !== false);
ok('  an empty basket reopens on the order while it can still be paid',
   strpos($app7, "if (name === 'cart') { openCartBody(); refreshCartPrices(); }") !== false);
ok('  the bar back to an unpaid order opens the order itself, by POST',
   preg_match("~function showResumeBar\(rec\) \{.*?document\.createElement\('form'\).*?bar\.method = 'post';.*?bar\.action = 'track\.php';~s", $app7) === 1);
ok('  and stops asking once a receipt is in', strpos($app7, '&& !(state.receipts > 0);') !== false);
$recLib = (string) @file_get_contents($root . '/lib/receipts.php');
ok('one Telegram message per chat, under a lock',
   strpos($recLib, 'function receipt_targets(') !== false && strpos($recLib, "SELECT GET_LOCK(?, 0)") !== false);
ok('the receipt route files the same picture once',
   preg_match("/case 'receipt':.*?receipt_duplicate_of\(.*?receipt_add\(/s", $apiSrc) === 1);
/* The page the bar leads to: its note must not send them to the chat with
   the receipt box right under it, and the card number must stay one number. */
ok('the tracking page\'s note points at the receipt box, not the chat',
   strpos($surface['track.php'], 'رسید را همین پایین بفرستید') !== false);
ok('the card number stays on one line', preg_match('/\.pay__number \{[^}]*white-space: nowrap;/s',
   (string) @file_get_contents($root . '/assets/css/components.css')) === 1);

head('brands: the wall, the pages, and the way to them');
/* The menus: one link to the wall, in the header and in the phone's menu,
   on the home page and on every page the partials draw (product.html is held
   to the partials by the drift check above). */
ok('the header menu has «برندها»', strpos($surface['partials/chrome-top.php'],
   '<li><a class="nav__link" href="brands.php">برندها</a></li>') !== false
   && strpos($surface['index.html'], '<li><a class="nav__link" href="brands.php">برندها</a></li>') !== false);
ok('  so does the phone\'s menu', strpos($surface['partials/chrome-foot.php'],
   '<a class="mobile-nav__link" href="brands.php" data-close>برندها</a>') !== false
   && strpos($surface['index.html'], '<a class="mobile-nav__link" href="brands.php" data-close>برندها</a>') !== false);
/* The owner first asked for the wall at the foot of the home page; in
   2026-10, for the brands much higher. They run in the orange band right
   under the hero (brand_strip_html() in lib/brands.php), and the wall at the
   foot is gone - brands.php keeps the whole wall with each brand's count. */
$ixB = $surface['index.html'];
/* The markers are comments, which $surface has stripped: read the file. */
$ixRaw = (string) @file_get_contents($root . '/index.html');
$stripAt = strpos($ixRaw, '<!-- brandstrip -->');
ok('the brands\' band is marked right under the hero, before every section',
   $stripAt !== false && $stripAt > strpos($ixRaw, '<section class="hero"')
   && $stripAt < strpos($ixRaw, 'id="goals"') && strpos($ixRaw, '<!-- /brandstrip -->') > $stripAt);
ok('  and the wall at the foot of the page is gone', strpos($ixB, 'id="brands"') === false
   && strpos($ixB, 'data-brands-section') === false);
$brandsLib = (string) @file_get_contents($root . '/lib/brands.php');
ok('  with the way to the whole wall', strpos($brandsLib, '<a class="ticker__all" href="brands.php">') !== false
   && strpos((string) @file_get_contents($root . '/assets/js/ui.js'), '<a class="ticker__all" href="brands.php">') !== false);
$appB = $surface['assets/js/app.js'];
ok('app.js fills the wall and a brand page\'s cards',
   strpos($appB, 'initHomeBrands();') !== false && strpos($appB, 'initBrandPage();') !== false);
ok('the API has the brands, and products by brand',
   strpos($apiSrc, "case 'brands':") !== false && strpos($apiSrc, "'brand'       => \$_GET['brand']") !== false);
$panelNav = (string) @file_get_contents($root . '/admin/partials/layout.php');
ok('the panel\'s menu has «برندها»', strpos($panelNav, "['href' => 'brands.php',") !== false);
$panelBrands = (string) @file_get_contents($root . '/admin/brands.php');
/* A logo goes through the same GD re-encode as a product photograph, into
   its own folder, and only for a brand the products produced. */
ok('  a logo is stored through image_store(), in uploads/brands',
   strpos($panelBrands, 'image_store($_FILES[\'logo\'], \'brands\', BRAND_LOGO_MAX_EDGE)') !== false);
ok('  many logos at once, each under the brand its file name says',
   strpos($panelBrands, 'if ($action === \'bulk\') {') !== false
   && strpos($panelBrands, 'name="logos[]" multiple') !== false
   && strpos($panelBrands, 'brand_attach_logo($f[\'name\'], (string) $stored[\'path\'])') !== false);
ok('  and nothing is saved for a slug no product has',
   preg_match('/\$brand\s*=\s*\$bySlug\[\$slug\] \?\? null;.*?if \(\$brand === null\) \{/s', $panelBrands) === 1);

head('the top-deals hero replaced the 3D tub');
$ix2 = $surface['index.html'];
ok('the 3D scripts are no longer loaded',
   strpos($ix2, 'hero3d.js') === false && strpos($ix2, 'hero-labels.js') === false);
ok('the deals block is there, hidden until it has something to show',
   preg_match('/<div class="deals" data-deals[^>]*\bhidden\b/', $ix2) === 1);
ok('  with the poster still underneath, for no JavaScript and nothing on offer',
   strpos($ix2, 'hero-tub.svg') !== false);
ok('app.js fills it from the ticked products',
   strpos($app2, 'initHeroDeals();') !== false && strpos($app2, "badge: 'topdeal'") !== false);
/* One product at a time, large, each for HERO_DEALS_MS - the owner asked
   for it: three abreast they were 90 to 128px wide beside the headline. */
$lay5 = (string) @file_get_contents($root . '/assets/css/layout.css');
ok('the deals take turns, with a pause button before the products it stops',
   preg_match('/data-deals-toggle[^>]*aria-label="توقف نمایش خودکار"/', $ix2) === 1
   && strpos($ix2, 'data-deals-toggle') < strpos($ix2, 'data-deals-row'));
ok('  and a place for the bars that move between them', strpos($ix2, 'data-deals-pips') !== false);
/* tokens.css cuts every animation to 0.01ms under prefers-reduced-motion. A
   rotation that waited for the bar's animation to end would race through
   the products for exactly the visitors who asked for less movement. */
ok('the rotation runs on a timer, not on the bar\'s animation',
   strpos($app2, 'function dealClock(') !== false && strpos($app2, 'animationend') === false);
ok('  and starts paused for a visitor who asked for less motion',
   strpos($app2, "matchMedia('(prefers-reduced-motion: reduce)')") !== false);
ok('the photograph never sizes the box',
   preg_match('/\.deal__photo \{[^}]*contain: size;/s', $lay5) === 1);
ok('on a desktop the box stands as tall as the copy beside it, within reason',
   preg_match('/\.stage-wrap\.has-deals \{[^}]*align-self: stretch;/s', $lay5) === 1
   && preg_match('/\.stage-wrap\.has-deals \.stage \{[^}]*max-height: 160cqi;/s', $lay5) === 1);
ok('the bar stops when the rotation does',
   strpos($lay5, '.deals.is-paused .deals__pip.is-on::after { animation-play-state: paused; }') !== false);
$editSrc = (string) @file_get_contents($root . '/admin/product-edit.php');
ok('the product form has the «تخفیف برتر» box, and saves it',
   strpos($editSrc, 'name="badge_topdeal"') !== false
   && strpos($editSrc, "['bestseller', 'new', 'topdeal']") !== false);
$listSrc = (string) @file_get_contents($root . '/admin/products.php');
ok('the products list offers it as a bulk badge',
   strpos($listSrc, '<option value="topdeal">') !== false
   && strpos($listSrc, "['bestseller', 'new', 'topdeal']") !== false);

head('the owner\'s own text reads the way it was typed');
$comp4 = (string) @file_get_contents($root . '/assets/css/components.css');
/* Taking .num off the value cells fixed «مقدار» and broke every Latin value:
   a right-to-left cell reorders «6.7 g» into «g 6.7». */
ok('a nutrition value takes its direction from its own first letter',
   preg_match('/\\.spec td \\{[^}]*unicode-bidi: plaintext/s', $comp4) === 1);
ok('  and still reads from the one right-hand edge',
   preg_match('/\\.spec td \\{[^}]*text-align: right/s', $comp4) === 1);
/* The ingredients are typed as a list, one item a line; a <p> folded it. */
ok('the ingredients and usage keep their line breaks, each line its own direction',
   preg_match('/\\.acc__body--lines \\{[^}]*white-space: pre-line;[^}]*unicode-bidi: plaintext;/s', $comp4) === 1);
foreach (['product.php', 'product.html'] as $f) {
    $src = (string) @file_get_contents($root . '/' . $f);
    ok("  $f puts that on both panels",
       strpos($src, 'class="acc__body acc__body--lines" data-p-ingredients') !== false
       && strpos($src, 'class="acc__body acc__body--lines" data-p-usage') !== false);
    ok("  and gives the product name its own quieter class",
       strpos($src, 'class="u-h1 pdp__title" data-p-title') !== false);
}
ok('the description keeps its paragraphs too',
   preg_match('/\\.pdp__about p \\{[^}]*white-space: pre-line/', $comp4) === 1);
$lay4 = (string) @file_get_contents($root . '/assets/css/layout.css');
/* 900 is not a weight Vazirmatn has; the browser fakes it. */
ok('the product name is the real bold, not a synthesised 900',
   preg_match('/\\.pdp__title \\{[^}]*font-weight: 700;/s', $lay4) === 1);

head('the panel arranges the images, and the order it saves is the site\'s');
$edit4 = (string) @file_get_contents($root . '/admin/product-edit.php');
/* The inputs sit outside the product form (each tile has its own delete
   form, and forms do not nest), so they have to name it to belong to it. */
ok('the product form can be named from outside it', strpos($edit4, '<form id="product-form"') !== false);
ok('  and each tile carries an order[] input that belongs to it',
   strpos($edit4, 'name="order[]" value="<?= (int) $img[\'id\'] ?>" form="product-form"') !== false);
ok('the tiles can be dragged, or moved with the arrows',
   strpos($edit4, 'data-img-sort') !== false && strpos($edit4, 'draggable="true"') !== false
   && strpos($edit4, 'data-move="-1"') !== false && strpos($edit4, 'data-move="1"') !== false);
/* «اصلی کن» set the main image without moving it: the cards and the page
   disagreed about which photo came first. Moving it to the front does both. */
/* Only HTML comments stripped: the card's own note explains what «اصلی کن»
   used to do. Not the block-comment kind as well - the page has
   accept="image/<star>", and a
   pattern that takes that for the start of a comment swallows the whole
   image manager, which is how this check first passed with the button back. */
$edit4Html = (string) preg_replace('/<!--.*?-->/s', '', $edit4);
ok('«اصلی کن» is gone - first is main',
   mb_strpos($edit4Html, 'اصلی کن') === false && strpos($edit4, 'value="main_image"') === false);
$adminCss = (string) @file_get_contents($root . '/admin/assets/admin.css');
ok('the panel has a visually-hidden class for the move announcement',
   preg_match('/\\.sr-only \\{[^}]*clip: rect\\(0 0 0 0\\)/s', $adminCss) === 1);

head('the social icons sit in the middle of their circles');
/* .socials a and .footer__col a both score (0,1,1) and the column rule comes
   later in layout.css, so the icons were laid out inline-flex with no
   justify-content - flex-start, which in RTL parked every glyph 9.7px right of
   centre. min-height beat height too, so the 38px circles were 38x40 ovals. */
$layout = (string) @file_get_contents($root . '/assets/css/layout.css');
ok('a rule outranks .footer__col a for the social circles',
   preg_match('/\.footer__col \.socials a \{[^}]*place-items: center/s', $layout) === 1);
ok('  and squares them at the footer\'s own touch-target size',
   preg_match('/\.footer__col \.socials a \{[^}]*width: 40px;\s*height: 40px/s', $layout) === 1);

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
