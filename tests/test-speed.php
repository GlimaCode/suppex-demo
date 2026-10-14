<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP.
   bootstrap falls back to the real suppex-config.php when SUPPEX_CONFIG
   is unset - which is exactly what a web request has - so one GET to an
   uploaded tests/ folder could empty the live database. The .htaccess
   beside this file is process; this is code. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* The pages set their own status - a missing product is a 404 - which PHP
   refuses once anything has been written; so the report waits in a buffer. */
ob_start();
/* ===========================================================================
   Speed, and what the search engines and AI assistants read
   ---------------------------------------------------------------------------
   The round that followed PageSpeed and a crawl of the live site:

     - each page carries the answers to the API calls its script makes
       (lib/boot.php), so a page asks the network for nothing - on this host
       five calls at once came back after up to nineteen seconds, and
       Google's renderer gave up on them and saw a shop with no products;
     - the home page arrives with its products in it (index.php), for the
       crawlers that do not run a script at all;
     - one stylesheet, the fonts preloaded, every file under a URL that names
       its version (lib/assets.php), so browsers keep them for a year;
     - lighter WebP copies of every product photograph (lib/images.php);
     - structured data: the product's own photographs and condition, the FAQ
       as FAQPage, the featured products as an ItemList;
     - llms.txt, the shop in one plain page for an AI assistant.

   What each check pins came back once already or would come back quietly: a
   boot key one character off sends a page back to the network with nothing
   failing, a stale site.css ignores an edit to the four sources, a script
   linked without its version is kept on every phone for a year.
   =========================================================================== */
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = 'suppex.ir';
$_SERVER['HTTPS']          = 'on';
$_SERVER['REQUEST_METHOD'] = 'GET';

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/products.php';
require_once SUPPEX_ROOT . '/lib/seo.php';
require_once SUPPEX_ROOT . '/lib/boot.php';
require_once SUPPEX_ROOT . '/lib/assets.php';
require_once SUPPEX_ROOT . '/lib/images.php';
require_once SUPPEX_ROOT . '/lib/pages.php';
require_once SUPPEX_ROOT . '/lib/journal.php';

$pass = 0; $fail = 0;
function ok(string $l, bool $c, ?string $x = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $l\n"; }
    else { $fail++; echo "  FAIL $l" . ($x ? "  --  $x" : '') . "\n"; }
}
function head(string $t): void { echo "\n== $t ==\n"; }

/** A storefront page, rendered as a visitor gets it. */
function speed_page(string $file, array $get = []): string {
    $_GET = $get;
    http_response_code(200);
    ob_start();
    include SUPPEX_ROOT . '/' . $file;
    return (string) ob_get_clean();
}
/** window.SUPPEX_BOOT from a page, decoded; null when there is none. */
function speed_boot(string $html): ?array {
    if (!preg_match('~<script>window\.SUPPEX_BOOT=(.*?);</script>~s', $html, $m)) { return null; }
    $d = json_decode($m[1], true);
    return is_array($d) ? $d : null;
}
/** Every JSON-LD block on a page, decoded. */
function speed_ld(string $html): array {
    preg_match_all('~<script type="application/ld\+json">\s*(.*?)\s*</script>~s', $html, $m);
    $out = [];
    foreach ($m[1] as $j) {
        $d = json_decode($j, true);
        if (is_array($d)) { $out[] = $d; }
    }
    return $out;
}
/** The first JSON-LD node of a type, looking inside @graph too. */
function speed_ld_node(array $blocks, string $type): ?array {
    foreach ($blocks as $b) {
        foreach (isset($b['@graph']) ? (array) $b['@graph'] : [$b] as $n) {
            if (($n['@type'] ?? '') === $type) { return $n; }
        }
    }
    return null;
}
/** The same data, as the browser would receive it. */
function speed_json($v): string {
    return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
/** A picture made for these checks, under the test uploads folder. */
function speed_picture(string $webPath, int $w, int $h): void {
    $file = (string) image_upload_file($webPath);
    @mkdir(dirname($file), 0755, true);
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 240, 240, 236));
    imagefilledellipse($im, intdiv($w, 2), intdiv($h, 2), intdiv($w, 2), intdiv($h, 2),
                       imagecolorallocate($im, 214, 67, 0));
    imagejpeg($im, $file, 82);
    imagedestroy($im);
}

$ui     = (string) file_get_contents(SUPPEX_ROOT . '/assets/js/ui.js');
$sizeOf = static fn(string $name): string =>
    preg_match("~var $name = '([^']+)';~", $ui, $m) ? $m[1] : '';
$SIZES_CARD = $sizeOf('SIZES_CARD');
$SIZES_MAIN = $sizeOf('SIZES_MAIN');

/* ------------------------------------------------------------------------ */
head('boot keys: the same rule as repo.js');
/* tests/boot-keys.json is the one table both sides are held to:
   tests/test-speed.mjs checks repo.js's bootKey() against it. */
$cases = json_decode((string) file_get_contents(__DIR__ . '/boot-keys.json'), true);
ok('the shared table is there', is_array($cases) && count($cases) >= 10);
foreach ((array) $cases as $c) {
    $got = boot_key((string) $c['route'], (array) $c['params']);
    ok('  ' . $c['why'], $got === $c['key'], "$got  !=  {$c['key']}");
}

head('boot_script(): what the page carries');
$s = boot_script();
ok('every page gets the shop\'s settings', str_starts_with($s, '<script>window.SUPPEX_BOOT=')
   && str_ends_with($s, ';</script>'));
$d = speed_boot($s);
ok('  as the API would answer ?r=config', $d !== null
   && speed_json($d['config'] ?? null) === speed_json(storefront_config()));
/* A product name is text an owner types; «</script>» in it must not end the
   element early, and the browser must get the text back exactly. */
$evil = ['nameFa' => 'پودر </script><script>alert(1)</script> & «نقل‌قول» \'x\' "y"'];
$s2 = boot_script([['product', ['slug' => 'x'], $evil]]);
ok('a </script> inside the data cannot close the element',
   substr_count($s2, '</script>') === 1, $s2);
$d2 = speed_boot($s2);
ok('  and decodes back to exactly what was put in', ($d2['product?slug=x']['nameFa'] ?? null) === $evil['nameFa']);
$s3 = boot_script([['product', ['slug' => 'no-such-product']]]);
$d3 = (array) speed_boot($s3);
ok('a product that is not there is left out - the API answers that with a 404',
   !array_key_exists('product?slug=no-such-product', $d3) && array_key_exists('config', $d3));
$s4 = boot_script([['products', ['limit' => 3], ['first']], ['products', ['limit' => 3], ['second']]]);
ok('the same call twice is sent once, the first answer kept',
   (speed_boot($s4)['products?limit=3'] ?? null) === ['first']);
ok('an answer worked out here is the API\'s own',
   speed_json(boot_answer('products', ['category' => 'protein', 'limit' => 60]))
   === speed_json(products_list(['category' => 'protein', 'limit' => 60])));

/* ------------------------------------------------------------------------ */
head('the home page arrives with its content');
$log = __DIR__ . '/tmp/speed-error.log';
@mkdir(dirname($log), 0755, true);
@unlink($log);
$prevLog = ini_get('error_log');
ini_set('error_log', $log);
$home = speed_page('index.php');
ini_set('error_log', (string) $prevLog);
$logged = trim((string) @file_get_contents($log));
ok('index.php finds every marker it fills in index.html', strpos($logged, 'markers not found') === false, $logged);

/* The brand's profiles come from the panel, not from the handles index.html
   was written with: those went stale and never named the Instagram page, and
   sameAs is how Google ties «SUPPEX» on those profiles to this site. */
$sameKeep = [];
foreach (['instagram_url', 'telegram_url', 'bale_url', 'aparat_url', 'whatsapp_url'] as $k) {
    $sameKeep[$k] = (string) setting($k, '');
}
settings_save(['instagram_url' => 'https://instagram.com/zz_brand', 'telegram_url' => 'https://t.me/zz_brand',
               'bale_url' => '', 'aparat_url' => '', 'whatsapp_url' => 'https://wa.me/989120000000']);
$sameAs = null;
foreach (speed_ld(speed_page('index.php')) as $ld) {
    foreach (($ld['@graph'] ?? [$ld]) as $node) {
        if (($node['@type'] ?? '') === 'Organization') { $sameAs = $node['sameAs'] ?? null; }
    }
}
settings_save($sameKeep);
ok('the home page\'s profiles are the ones set in the panel',
   $sameAs === ['https://instagram.com/zz_brand', 'https://t.me/zz_brand'], json_encode($sameAs));

$featured = products_list(['limit' => 12]);
$cats     = categories_list();
/* Only the categories with something on sale are tiles and filters. */
$onSale   = array_values(array_filter($cats, static fn(array $c): bool => (int) $c['count'] > 0));
$grid = preg_match('~<div class="grid grid--products" data-products>(.*?)</div>\s*(?:<!--|</div>|<div)~s', $home, $gm) ? $gm[1] : '';
ok('the featured products are in the page', substr_count($home, '<article class="pcard">') === count($featured)
   && count($featured) > 0, substr_count($home, '<article class="pcard">') . ' / ' . count($featured));
$linked = 0;
foreach ($featured as $p) {
    if (strpos($home, 'href="product.php?p=' . rawurlencode((string) $p['slug']) . '"') !== false) { $linked++; }
}
ok('  each one a link a crawler can follow', $linked === count($featured), "$linked / " . count($featured));
ok('  named in a heading', substr_count($home, '<h3 class="pcard__title">') === count($featured));
ok('the categories are in the page', substr_count($home, '<a class="ccard') === count($onSale), (string) count($onSale));
ok('  with their filter chips', substr_count($home, 'data-filter="') === count($onSale) + 1);
ok('  a tile with a photograph uses it', substr_count($home, '<a class="ccard ccard--photo"')
   === count(array_filter($onSale, static fn(array $c): bool => (string) ($c['photo'] ?? '') !== '')));
$totalOn = (int) db_value('SELECT COUNT(*) FROM products WHERE is_active = 1');
ok('«نمایش بیشتر» knows how many there are, and shows only when there are more',
   strpos($home, 'data-more data-total="' . $totalOn . '"' . ($totalOn > count($featured) ? '>' : ' hidden>')) !== false);
ok('  visible without the script: no reveal class on the cards', strpos($home, 'class="ccard reveal') === false);
ok('the journal is in the page', substr_count($home, 'href="article.php?a=') >= min(3, count(journal_all())));
$brandsNow = brands_list();
if ($brandsNow) {
    ok('the brands\' band is drawn, since there are brands', strpos($home, 'data-brandstrip data-drawn') !== false
       && strpos($home, '<!-- brandstrip -->') === false);
}

$bh = speed_boot($home);
ok('the page carries what app.js will ask for', $bh !== null);
foreach (['config', 'categories', 'products?limit=12', 'products?badge=topdeal', 'brands'] as $k) {
    ok("  $k", is_array($bh) && array_key_exists($k, $bh));
}
ok('  and the 24 for the hero only when nothing ticked can be sold',
   array_key_exists('products?limit=24', (array) $bh) === !hero_deal_picks(products_list(['badge' => 'topdeal']), []));
ok('  the same answers the API gives', speed_json($bh['products?limit=12'] ?? null) === speed_json($featured)
   && speed_json($bh['categories'] ?? null) === speed_json($cats));

ok('one stylesheet, the bundle, under its version',
   preg_match_all('~<link rel="stylesheet"[^>]*>~', $home, $css) === 1
   && strpos($css[0][0], 'href="' . asset(ASSET_CSS_BUNDLE) . '"') !== false, implode(' ', $css[0] ?? []));
ok('the fonts of the first paint are preloaded', substr_count($home, 'as="font" type="font/woff2" crossorigin') === 3);
ok('every script under its version, the data before them',
   preg_match_all('~<script src="([^"]+)"~', $home, $sm) === 6
   && count(array_filter($sm[1], static fn(string $u): bool => (bool) preg_match('~^assets/js/[a-z]+\.js\?v=[0-9a-z]+$~', $u))) === 6
   && strpos($home, 'window.SUPPEX_BOOT=') < strpos($home, '<script src="'), implode(' ', $sm[1] ?? []));
ok('  none of them the static page\'s date', preg_match('~\.js\?v=20\d{6}"~', $home) === 0);
ok('the canonical is the site itself', strpos($home, '<link rel="canonical" href="https://suppex.ir/">') !== false);
ok('og:url and an absolute og:image', strpos($home, '<meta property="og:url" content="https://suppex.ir/">') !== false
   && preg_match('~<meta property="og:image" content="https://suppex\.ir/assets/images/og-cover\.jpg\?v=[0-9a-z]+">~', $home) === 1);
ok('no link to /index.html - it is a redirect now', strpos($home, 'href="index.html') === false);

$ldHome = speed_ld($home);
$list = speed_ld_node($ldHome, 'ItemList');
ok('the featured products as an ItemList', $list !== null
   && count((array) ($list['itemListElement'] ?? [])) === count($featured));
$good = true;
foreach ((array) ($list['itemListElement'] ?? []) as $i => $it) {
    $good = $good && ($it['position'] ?? 0) === $i + 1
        && str_starts_with((string) ($it['url'] ?? ''), 'https://suppex.ir/product.php?p=');
}
ok('  numbered from 1, each an absolute product URL', $good);
ok('the shop is known by its Persian name too',
   (speed_ld_node($ldHome, 'Organization')['alternateName'] ?? '') === 'ساپکس'
   && (speed_ld_node($ldHome, 'WebSite')['alternateName'] ?? '') === 'ساپکس');

/* ------------------------------------------------------------------------ */
head('every page: one stylesheet, its data, its scripts versioned');
foreach (['product.php', 'category.php', 'brand.php', 'brands.php', 'journal.php',
          'article.php', 'page.php', 'track.php'] as $f) {
    $src = (string) file_get_contents(SUPPEX_ROOT . '/' . $f);
    ok("$f takes its head from partials/head-assets.php",
       strpos($src, "include __DIR__ . '/partials/head-assets.php';") !== false
       && strpos($src, '<link rel="stylesheet"') === false);
    ok('  and links home as ./, not the redirect', strpos($src, 'href="index.html') === false);
}

$slug    = 'whey-original';
$product = product_get($slug);
$pp      = speed_page('product.php', ['p' => $slug]);
$bp      = (array) speed_boot($pp);
ok('the product page carries the product', speed_json($bp['product?slug=' . $slug] ?? null) === speed_json($product));
$relArgs = ['notCategory' => $product['category'], 'exclude' => $slug, 'limit' => 4];
ok('  and the shelf under it', speed_json($bp[boot_key('products', $relArgs)] ?? null)
   === speed_json(products_list($relArgs)), implode(', ', array_keys($bp)));
ok('  asking for nothing it does not show',
   count($bp) === (count(products_list($relArgs)) < 4 ? 4 : 3), implode(', ', array_keys($bp)));
ok('the photograph is fetched first', preg_match('~<img data-p-image [^>]*fetchpriority="high"~', $pp) === 1);
ok('  with the same sizes ui.js gives it, so the script does not fetch it again',
   $SIZES_MAIN !== '' && strpos((string) file_get_contents(SUPPEX_ROOT . '/product.php'),
       "image_srcset_attr(\$pImage, '" . $SIZES_MAIN . "')") !== false);
$gonePage = speed_page('product.php', ['p' => 'no-such-product-at-all']);
http_response_code(200);
ok('a missing product carries no product', !array_key_exists('product?slug=no-such-product-at-all',
   (array) speed_boot($gonePage)));

$cp = speed_page('category.php', ['c' => 'protein']);
ok('a category page carries its list', speed_json((speed_boot($cp) ?? [])['products?category=protein&limit=60'] ?? null)
   === speed_json(products_list(['category' => 'protein', 'limit' => 60])));
ok('  its cards offer the lighter copies with ui.js\'s sizes',
   $SIZES_CARD !== '' && substr_count((string) file_get_contents(SUPPEX_ROOT . '/category.php'), "'" . $SIZES_CARD . "'") === 1
   && substr_count((string) file_get_contents(SUPPEX_ROOT . '/brand.php'), "'" . $SIZES_CARD . "'") === 1
   && substr_count((string) file_get_contents(SUPPEX_ROOT . '/index.php'), "'" . $SIZES_CARD . "'") === 1);

if ($brandsNow) {
    $b0 = $brandsNow[0];
    $brp = speed_page('brand.php', ['b' => (string) $b0['slug']]);
    $bKey = boot_key('products', ['brand' => $b0['slug'], 'limit' => 60]);
    $bList = products_list(['brand' => $b0['slug'], 'limit' => 60]);
    ok('a brand page carries its list', speed_json((speed_boot($brp) ?? [])[$bKey] ?? null) === speed_json($bList));
    if ((string) $b0['blurb'] === '' && $bList) {
        $desc = preg_match('~<meta name="description" content="([^"]*)"~', $brp, $dm)
            ? html_entity_decode($dm[1], ENT_QUOTES, 'UTF-8') : '';
        ok('  and, with no blurb, is described by what it sells',
           mb_strpos($desc, (string) $bList[0]['nameFa']) !== false, $desc);
    }
}
$bsp = speed_page('brands.php');
ok('the brands index says what it lists', strpos($bsp, '<title>برندهای مکمل ورزشی |') !== false
   && strpos($bsp, '<meta property="og:title" content="برندهای مکمل ورزشی">') !== false);

/* ------------------------------------------------------------------------ */
head('asset(): the URL names the bytes');
$v = asset('assets/js/app.js');
ok('a file gets its time, in base 36', $v === 'assets/js/app.js?v='
   . base_convert((string) filemtime(SUPPEX_ROOT . '/assets/js/app.js'), 10, 36), $v);
ok('a missing file its bare path', asset('assets/js/no-such-file.js') === 'assets/js/no-such-file.js');
$foot = (string) file_get_contents(SUPPEX_ROOT . '/partials/chrome-foot.php');
ok('the footer\'s scripts come through it', strpos($foot, "e(asset('assets/js/' . \$script . '.js'))") !== false);

head('site.css: the four sources, current');
/* The four files are the source; the bundle is built by tools/build-css.php.
   An edit without the rebuild is a site that silently ignores it. */
ok('site.css is exactly what the four sources build - run php tools/build-css.php',
   (string) @file_get_contents(SUPPEX_ROOT . '/' . ASSET_CSS_BUNDLE) === asset_css_bundle());
ok('  and much smaller than they are', strlen(asset_css_bundle()) < 0.8 * array_sum(array_map(
   static fn(string $f): int => (int) filesize(SUPPEX_ROOT . '/' . $f), ASSET_CSS_SOURCES)));
$min = static fn(string $css): string => trim(asset_css_minify($css));
ok('the minifier drops comments and the space around { } ; ,',
   $min("a { color: red; } /* why */ b { top: 0; }") === 'a{color: red}b{top: 0}',
   $min("a { color: red; } /* why */ b { top: 0; }"));
ok('  a comment between two words still parts them', $min('a{border:1px/**/solid red}') === 'a{border:1px solid red}');
ok('  and nowhere else - .a/**/.b is one compound selector', $min('.a/**/.b{x:y}') === '.a.b{x:y}');
ok('  keeps the space a descendant selector needs', $min(".nav   .link { x: y }") === '.nav .link{x: y}',
   $min(".nav   .link { x: y }"));
ok('  keeps the spaces calc() needs', strpos($min('a{width:calc(100% - 2px)}'), 'calc(100% - 2px)') !== false);
ok('  never touches the inside of a string', strpos($min('a::after{content:"/* x */  ;{ }"}'), '"/* x */  ;{ }"') !== false);
ok('  keeps a media query readable', strpos($min('@media (min-width: 700px) and (max-width: 900px) { a { x: y } }'),
   '(min-width: 700px) and (max-width: 900px)') !== false);
$headSrc = (string) file_get_contents(SUPPEX_ROOT . '/partials/head-assets.php');
preg_match_all('~<link rel="preload" href="assets/fonts/([^"]+)" as="font"~', $headSrc, $pre);
$bundle = (string) @file_get_contents(SUPPEX_ROOT . '/' . ASSET_CSS_BUNDLE);
$same = count($pre[1]) === 3;
foreach ($pre[1] as $font) {
    $same = $same && is_file(SUPPEX_ROOT . '/assets/fonts/' . $font)
        && strpos($bundle, "url('../fonts/" . $font . "')") !== false;
}
ok('each preloaded font is the one the stylesheet asks for, or it downloads twice', $same, implode(', ', $pre[1]));

head('contrast: the orange that carries text');
$tokens = (string) file_get_contents(SUPPEX_ROOT . '/assets/css/tokens.css');
ok('--accent-ink is defined', preg_match('~--accent-ink:\s*#([0-9A-Fa-f]{6});~', $tokens, $hex) === 1);
$lum = static function (string $h): float {
    $c = array_map(static function (string $x): float {
        $v = hexdec($x) / 255;
        return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }, str_split($h, 2));
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
};
$ratio = isset($hex[1]) ? 1.05 / ($lum($hex[1]) + 0.05) : 0.0;
ok('  white text on it passes WCAG AA (4.5:1)', $ratio >= 4.5, sprintf('%.2f:1', $ratio));
$comp = (string) file_get_contents(SUPPEX_ROOT . '/assets/css/components.css');
ok('  the discount badge and the active chip use it',
   preg_match('~\.badge--accent \{[^}]*background: var\(--accent-ink\)~', $comp) === 1
   && preg_match('~\.chip\.is-active \{[^}]*background: var\(--accent-ink\)~', $comp) === 1);

/* ------------------------------------------------------------------------ */
head('lighter copies of the photographs');
$tag  = bin2hex(random_bytes(3));
$big  = '/uploads/products/2026/09/speed-' . $tag . '-a.jpg';
$tiny = '/uploads/products/2026/09/speed-' . $tag . '-b.jpg';
$late = '/uploads/products/2026/09/speed-' . $tag . '-c.jpg';
$bulk = '/uploads/products/bulk/speed-' . $tag . '.jpg';
speed_picture($big, 1200, 900);
speed_picture($tiny, 120, 120);
speed_picture($bulk, 400, 400);
ok('a new photograph has no copies yet', image_needs_variants($big));
ok('five are made: four widths and its own', image_make_variants($big) === 5);
$want = [160, 320, 640, 960, 1200];
ok('  at the widths the pages draw', image_variant_widths($big) === $want, implode(',', image_variant_widths($big)));
$shapes = true;
foreach ($want as $w) {
    $info = @getimagesize((string) image_upload_file(image_variant_path($big, $w)));
    $shapes = $shapes && $info !== false && $info[2] === IMAGETYPE_WEBP && $info[0] === $w
        && abs($info[1] - (int) round(900 * $w / 1200)) <= 1;
}
ok('  each one WebP, that wide, the same shape', $shapes);
ok('  and none twice', image_make_variants($big) === 0 && !image_needs_variants($big));
ok('a small picture gets one copy, at its own width - never enlarged',
   image_make_variants($tiny) === 1 && image_variant_widths($tiny) === [120]);
$attr = image_srcset_attr($big, $SIZES_CARD);
ok('srcset lists every copy with its width', preg_match_all('~-w\d+\.webp \d+w~', $attr) === 5
   && strpos($attr, 'speed-' . $tag . '-a-w160.webp 160w') !== false
   && strpos($attr, 'sizes="' . e($SIZES_CARD) . '"') !== false, $attr);
ok('  and a bundled drawing gets none', image_srcset_attr('assets/images/products/whey-original.svg', 'x') === '');
$orig = image_originals();
ok('the originals are listed, not their copies, not the bulk drop folder',
   in_array($big, $orig, true) && in_array($tiny, $orig, true)
   && !array_filter($orig, 'image_is_variant') && !in_array($bulk, $orig, true));
speed_picture($late, 700, 700);
$r = image_variants_backfill(20.0);
ok('catching up makes what is missing', $r['made'] >= 4 && $r['pending'] === 0 && !image_needs_variants($late),
   speed_json($r));

/* The product that shows them: a photograph as its main picture and in its
   gallery, as the panel saves one. Put back afterwards. */
$row = db_one('SELECT id, image FROM products WHERE slug = ?', [$slug]);
db_query('UPDATE products SET image = ? WHERE id = ?', [$big, (int) $row['id']]);
db_query('INSERT INTO product_images (product_id, path, sort_order) VALUES (?, ?, -2), (?, ?, -1)',
        [(int) $row['id'], $big, (int) $row['id'], $late]);
try {
    $listed = null;
    foreach (products_list(['limit' => 60]) as $p) {
        if ($p['slug'] === $slug) { $listed = $p; }
    }
    ok('the API sends a card the widths it can choose from', ($listed['imgv'][$big] ?? null) === $want);
    $full = product_get($slug);
    ok('  and the product page every picture\'s', ($full['imgv'][$big] ?? null) === $want
       && ($full['imgv'][$late] ?? null) === [160, 320, 640, 700]);

    $cp2 = speed_page('category.php', ['c' => (string) $full['category']]);
    ok('the category page\'s card offers them', strpos($cp2, 'speed-' . $tag . '-a-w320.webp 320w') !== false);
    $pp2 = speed_page('product.php', ['p' => $slug]);
    ok('the product photograph offers them', preg_match('~<img data-p-image [^>]*srcset="[^"]*-w960\.webp 960w~', $pp2) === 1);
    $ld = speed_ld_node(speed_ld($pp2), 'Product');
    ok('the structured data shows the photographs, absolute', ($ld['image'] ?? null) === [
        'https://suppex.ir' . $big, 'https://suppex.ir' . $late]);
    ob_start(); include SUPPEX_ROOT . '/sitemap.php'; $sm = (string) ob_get_clean();
    ok('the sitemap lists them under the product, for Google Images',
       preg_match('~<loc>https://suppex\.ir/product\.php\?p=' . preg_quote($slug, '~') . '</loc>.*?<image:loc>https://suppex\.ir'
       . preg_quote($big, '~') . '</image:loc>\s*</image:image>\s*<image:image>\s*<image:loc>https://suppex\.ir'
       . preg_quote($late, '~') . '</image:loc>~s', $sm) === 1);
    ok('  and never a drawing', preg_match('~<image:loc>[^<]*\.svg</image:loc>~', $sm) === 0
       && strpos($sm, 'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"') !== false);
} finally {
    db_query('DELETE FROM product_images WHERE product_id = ? AND path IN (?, ?)', [(int) $row['id'], $big, $late]);
    db_query('UPDATE products SET image = ? WHERE id = ?', [(string) $row['image'], (int) $row['id']]);
}

ok('deleting a photograph deletes its copies', image_delete($big)
   && !is_file((string) image_upload_file($big))
   && !array_filter($want, static fn(int $w): bool => is_file((string) image_upload_file(image_variant_path($big, $w)))));
image_delete($tiny);
image_delete($late);
@unlink((string) image_upload_file($bulk));

/* ------------------------------------------------------------------------ */
head('structured data');
$ld = speed_ld_node(speed_ld(speed_page('product.php', ['p' => $slug])), 'Product');
$offer = (array) ($ld['offers'] ?? []);
ok('one Offer - merchant listings do not take a range', ($offer['@type'] ?? '') === 'Offer');
ok('  new and sealed', ($offer['itemCondition'] ?? '') === 'https://schema.org/NewCondition');
ok('  priced in rials, the toman price times ten', ($offer['priceCurrency'] ?? '') === 'IRR'
   && ($offer['price'] ?? '') === (string) ((int) $product['price'] * 10));
ok('the product\'s own address and category', ($ld['url'] ?? '') === 'https://suppex.ir/product.php?p=' . $slug
   && ($ld['category'] ?? '') !== '');
ok('with no photograph, the drawn card stands in - never an SVG', count((array) ($ld['image'] ?? [])) === 1
   && !str_ends_with((string) ($ld['image'][0] ?? ''), '.svg'));
ok('no ratings or reviews nobody wrote', !isset($ld['aggregateRating']) && !isset($ld['review']));

$faqMd = (string) file_get_contents(SUPPEX_ROOT . '/content/pages/faq.md');
$faqLd = speed_ld_node(speed_ld(speed_page('page.php', ['p' => 'faq'])), 'FAQPage');
$qs = (array) ($faqLd['mainEntity'] ?? []);
ok('the FAQ is an FAQPage', $faqLd !== null);
ok('  one Question for each of its headings', count($qs) === preg_match_all('/^##\s+\S/mu', $faqMd) && count($qs) > 3,
   count($qs) . ' / ' . preg_match_all('/^##\s+\S/mu', $faqMd));
$clean = true;
foreach ($qs as $q) {
    $a = (string) ($q['acceptedAnswer']['text'] ?? '');
    $clean = $clean && ($q['@type'] ?? '') === 'Question' && (string) ($q['name'] ?? '') !== ''
        && $a !== '' && strpos($a, '**') === false && strpos($a, '](') === false;
}
ok('  each with its answer, in plain words', $clean);
$other = speed_ld(speed_page('page.php', ['p' => 'shipping']));
ok('another page stays a WebPage', speed_ld_node($other, 'WebPage') !== null && speed_ld_node($other, 'FAQPage') === null);
$items = page_faq_items("مقدمه\n## سوال **یک**؟\nجواب با [لینک](page.php?p=x) و `کد`.\n\n- مورد اول\n- مورد دوم\n## بی‌جواب\n\n## سوال سه\n### ریز\nجواب سه");
ok('page_faq_items(): questions, answers without markdown, no empty answer', $items === [
    ['q' => 'سوال یک؟', 'a' => 'جواب با لینک و کد. مورد اول مورد دوم'],
    ['q' => 'سوال سه', 'a' => 'ریز جواب سه'],
], speed_json($items));

$catPage = speed_page('category.php', ['c' => 'protein']);
ok('a category\'s share picture is one a preview bot can draw',
   preg_match('~<meta property="og:image" content="[^"]+\.svg"~', $catPage) === 0);
$arts = journal_all();
if ($arts) {
    $artPage = speed_page('article.php', ['a' => (string) $arts[0]['slug']]);
    ok('  and an article\'s', preg_match('~<meta property="og:image" content="[^"]+\.svg"~', $artPage) === 0);
}

/* ------------------------------------------------------------------------ */
head('the picture a product page opens on');
/* app.js paints its first choice's picture when it draws the flavour chips.
   product.php draws the same one (product_opening_image()); drawing the
   product's own instead, the script swapped it at once - on the live page a
   phone downloaded 88 KB and then 97 KB to show one photograph. */
$base = ['image' => '/u/main.jpg', 'gallery' => ['/u/main.jpg', '/u/side.jpg'], 'sizes' => [], 'flavors' => []];
ok('no flavours: its own photograph, whatever its first combination shows',
   product_opening_image(['variants' => [['choice' => [], 'image' => '/u/v.jpg']]] + $base) === '/u/main.jpg');
$fl = ['flavors' => [['id' => 'choc', 'image' => '/u/choc.jpg'], ['id' => 'van', 'image' => '/u/van.jpg']]];
ok('the first flavour\'s photograph', product_opening_image($fl + $base) === '/u/choc.jpg');
ok('  or the product\'s own when that flavour has none',
   product_opening_image(['flavors' => [['id' => 'choc', 'image' => ''], ['id' => 'van', 'image' => '/u/van.jpg']]] + $base)
   === '/u/main.jpg');
$combo = ['sizes' => [['id' => 's1', 'stock' => 0], ['id' => 's2', 'stock' => null]],
          'variants' => [
              ['choice' => ['size' => 's1', 'flavor' => 'choc'], 'image' => '/u/s1-choc.jpg'],
              ['choice' => ['size' => 's2', 'flavor' => 'choc'], 'image' => '/u/s2-choc.jpg'],
          ]];
ok('the combination of the first size that can be bought', product_opening_image($combo + $fl + $base) === '/u/s2-choc.jpg');
$noPic = $combo;
$noPic['variants'][1]['image'] = '';
ok('  then that flavour\'s, when the combination has no photograph', product_opening_image($noPic + $fl + $base) === '/u/choc.jpg');
$allGone = $combo;
$allGone['sizes'][1]['stock'] = 0;
ok('  every size sold out: the first one', product_opening_image($allGone + $fl + $base) === '/u/s1-choc.jpg');
$axis = ['attributes' => [['id' => 'color', 'values' => [['id' => 'red'], ['id' => 'blue']]]]];
ok('another axis must match too', product_opening_image($axis + $combo + $fl + $base) === '/u/choc.jpg');
ok('  and does, at its first value', product_opening_image($axis + ['variants' => [
       ['choice' => ['size' => 's2', 'flavor' => 'choc', 'color' => 'blue'], 'image' => '/u/blue.jpg'],
       ['choice' => ['size' => 's2', 'flavor' => 'choc', 'color' => 'red'], 'image' => '/u/red.jpg'],
   ]] + $combo + $fl + $base) === '/u/red.jpg');
ok('  the size and flavour axes are the chips\', not extra ones', product_opening_image(['attributes' => [
       ['id' => 'size', 'values' => [['id' => 's1']]], ['id' => 'flavor', 'values' => [['id' => 'van']]],
   ]] + $combo + $fl + $base) === '/u/s2-choc.jpg');
ok('no gallery: the product\'s image, or nothing', product_opening_image(['gallery' => []] + $base) === '/u/main.jpg'
   && product_opening_image(['gallery' => [], 'image' => '', 'flavors' => [['id' => 'x', 'image' => '']]]) === '');

/* The rule it mirrors. A change to any of these lines in app.js is a change
   product_opening_image() has to make too. */
$appJs = (string) file_get_contents(SUPPEX_ROOT . '/assets/js/app.js');
ok('app.js still starts on the first size that can be bought and the first flavour',
   strpos($appJs, 'var firstSellable = sizes.filter(function (s) { return s.stock !== 0; })[0] || sizes[0];') !== false
   && strpos($appJs, 'flavor: flavors[0] || null,') !== false);
ok('  paints the combination\'s picture, else the flavour\'s, else the product\'s',
   preg_match('~var src = \(v && v\.image\)\s*\|\| \(selected\.flavor && selected\.flavor\.image\)\s*\|\| defaultImage;~', $appJs) === 1);
ok('  and does so on opening only when it draws the flavour chips',
   preg_match('~before anybody clicks anything\. \*/\s*paintChoiceImage\(\);~', $appJs) === 1
   && substr_count($appJs, 'paintChoiceImage();') === 4);
ok('it leaves the server\'s picture alone rather than setting it again',
   strpos($appJs, "if (mainImg && mainImg.getAttribute('src') !== list[shown]) {") !== false);
ok('  and fetches the next picture only once this one has arrived',
   preg_match("~if \(!mainImg \|\| mainImg\.complete\) \{\s*fetchAhead\(\);\s*\} else \{.*?mainImg\.addEventListener\('load', whenShown\);~s", $appJs) === 1);

/* On a real product: its first flavour photographed, the page opens on it. */
$first = db_one('SELECT f.id, f.image, p.slug FROM product_flavors f JOIN products p ON p.id = f.product_id
                  WHERE p.is_active = 1 ORDER BY p.sort_order, p.id, f.sort_order, f.id LIMIT 1');
if ($first) {
    db_query('UPDATE product_flavors SET image = ? WHERE id = ?', ['/uploads/products/2026/09/flavour-test.jpg', (int) $first['id']]);
    try {
        $opened = product_opening_image((array) product_get((string) $first['slug']));
        $pg = speed_page('product.php', ['p' => (string) $first['slug']]);
        ok('a product whose first flavour has a photograph opens on it', $opened === '/uploads/products/2026/09/flavour-test.jpg'
           && strpos($pg, '<img data-p-image src="/uploads/products/2026/09/flavour-test.jpg"') !== false, $opened);
    } finally {
        db_query('UPDATE product_flavors SET image = ? WHERE id = ?', [(string) $first['image'], (int) $first['id']]);
    }
}

/* ------------------------------------------------------------------------ */
head('the deals box, drawn by the server');
/* PageSpeed's one layout shift on the home page (0.056, a phone) was this box
   growing from the poster's square into the deals when app.js drew them.
   index.php draws the same slides; tests/deal-slide.json is the markup
   ui.js's dealSlide() writes, which tests/test-speed.mjs holds the script to. */
$slideCases = json_decode((string) file_get_contents(__DIR__ . '/deal-slide.json'), true);
ok('the shared slides are there', is_array($slideCases) && count($slideCases) >= 2);
foreach ((array) $slideCases as $c) {
    $got = hero_deal_slide($c['product'], (int) $c['i'], (int) $c['total']);
    ok('  a slide byte for byte as ui.js draws it: ' . $c['why'], $got === $c['html'], $got);
}
$d = static fn(string $slug, array $x = []): array => $x + ['slug' => $slug, 'nameFa' => $slug, 'price' => 1000,
    'inStock' => true, 'onSale' => true, 'discountPercent' => 5, 'badges' => ['topdeal']];
$slugs = static fn(array $list): string => implode(',', array_map(static fn(array $p): string => $p['slug'], $list));
ok('the ticked ones that can be sold, the largest discount first, three at most', $slugs(hero_deal_picks([
    $d('a', ['discountPercent' => 5]), $d('b', ['discountPercent' => 20]), $d('c', ['inStock' => false]),
    $d('d', ['price' => 0]), $d('e', ['discountPercent' => 12]), $d('f', ['discountPercent' => 12]),
    $d('g', ['badges' => []]),
], [])) === 'b,e,f');
ok('  one ticked but not for sale: the discounts among the rest instead',
   $slugs(hero_deal_picks([$d('x', ['inStock' => false])], [])) === ''
   && $slugs(hero_deal_picks([], [$d('p', ['onSale' => false, 'badges' => []]), $d('q', ['badges' => [], 'discountPercent' => 9])])) === 'q');
ok('  equal discounts keep their order, as Array#sort does',
   $slugs(hero_deal_picks([$d('m'), $d('n'), $d('o')], [])) === 'm,n,o');
ok('one deal has no bars', hero_deal_pips([$d('a')]) === '');
$pips = hero_deal_pips([$d('a'), $d('b', ['nameFa' => "کراتین 'ویژه'"])]);
ok('  two have two, the first on, as rotateDeals() draws them', $pips ===
   '<button type="button" class="deals__pip is-on" aria-current="true" data-deals-go="0" aria-label="۱ از ۲، a"></button>'
   . '<button type="button" class="deals__pip" data-deals-go="1" aria-label="۲ از ۲، کراتین &#39;ویژه&#39;"></button>', $pips);

$appSrc = (string) file_get_contents(SUPPEX_ROOT . '/assets/js/app.js');
ok('app.js still chooses them this way', strpos($appSrc, 'var HERO_DEALS_MAX = 3;') !== false
   && strpos($appSrc, "function usable(p) { return !!p && p.inStock !== false && p.price > 0; }") !== false
   && strpos($appSrc, "function byDiscount(a, b) { return (b.discountPercent || 0) - (a.discountPercent || 0); }") !== false
   && strpos($appSrc, "return usable(p) && (p.badges || []).indexOf('topdeal') !== -1;") !== false
   && strpos($appSrc, "chosen = (all || []).filter(function (p) { return usable(p) && p.onSale; });") !== false);
ok('  still draws the bars this way', strpos($appSrc, "return '<button type=\"button\" class=\"deals__pip' + (i === 0 ? ' is-on\" aria-current=\"true\"' : '\"') +") !== false
   && strpos($appSrc, "ui.esc(ui.faDigits(i + 1) + ' از ' + ui.faDigits(count) + '، ' + p.nameFa) + '\"></button>';") !== false);
ok('  and keeps slides the server drew', strpos($appSrc, "if (row.getAttribute('data-drawn') !== drawn) {") !== false);

$homePicks = hero_deal_picks(products_list(['badge' => 'topdeal']), []) ?: hero_deal_picks([], products_list(['limit' => 24]));
if ($homePicks) {
    ok('the home page draws the box with its deals', strpos($home, '<div class="stage-wrap has-deals" data-deals-wrap>') !== false
       && strpos($home, 'aria-labelledby="deals-title" hidden>') === false
       && substr_count($home, 'class="deal') - substr_count($home, 'class="deal__') - substr_count($home, 'class="deals') === count($homePicks));
    ok('  the ones app.js will choose, so it keeps them', strpos($home, 'data-drawn="' . e($slugs($homePicks)) . '"') !== false);
    ok('  the first photograph fetched first, the others after',
       substr_count($home, 'decoding="async" fetchpriority="high"') === 1
       && substr_count($home, 'decoding="async" fetchpriority="low"') === count($homePicks) - 1);
    ok('  the hidden poster not fetched at all', strpos($home, 'width="520" height="560" loading="lazy">') !== false);
    ok('  its bars and pause button there when there are two or more', count($homePicks) < 2
       || (preg_match_all('~class="deals__pip( is-on)?"~', $home) === count($homePicks)
           && strpos($home, 'data-deals-toggle aria-label="توقف نمایش خودکار">') !== false
           && strpos($home, '<div class="deals is-paused" data-deals') !== false));
} else {
    ok('no deals: the poster, as before', strpos($home, '<div class="stage-wrap" data-deals-wrap>') !== false
       && strpos($home, 'fetchpriority="high">') !== false);
}

/* ------------------------------------------------------------------------ */
head('llms.txt: the shop for an AI assistant');
ob_start(); include SUPPEX_ROOT . '/llms.php'; $llms = (string) ob_get_clean();
ok('a title, then the home page\'s own summary', str_starts_with($llms, '# ')
   && preg_match('~<meta name="description" content="([^"]+)">~u', (string) file_get_contents(SUPPEX_ROOT . '/index.html'), $md) === 1
   && strpos($llms, "\n> " . html_entity_decode($md[1], ENT_QUOTES, 'UTF-8') . "\n") !== false);
$all = products_list([]);
$missing = [];
foreach ($all as $p) {
    if (strpos($llms, '](https://suppex.ir/product.php?p=' . rawurlencode((string) $p['slug']) . '): ') === false) {
        $missing[] = $p['slug'];
    }
}
ok('every product on sale, with its link', $all && !$missing, implode(', ', $missing));
ok('  priced in toman', substr_count($llms, ' تومان') >= count($all));
$noStruck = true;
foreach ($all as $p) {
    if (empty($p['onSale']) && preg_match('~p=' . preg_quote(rawurlencode((string) $p['slug']), '~') . '\):[^\n]*قبل از تخفیف~u', $llms)) {
        $noStruck = false;
    }
}
ok('  and no «before discount» price that nobody set', $noStruck);
foreach (categories_list() as $c) {
    if ((int) $c['count'] > 0) {
        ok('  the category ' . $c['id'], strpos($llms, '](https://suppex.ir/category.php?c=' . rawurlencode((string) $c['id']) . ')') !== false);
    }
}
foreach (['shipping', 'returns', 'faq'] as $pg) {
    ok("  the page «{$pg}»",strpos($llms, '](https://suppex.ir/page.php?p=' . $pg . ')') !== false);
}
ok('the sitemap, as optional', strpos($llms, "## Optional\n\n- [") !== false && strpos($llms, 'https://suppex.ir/sitemap.xml') !== false);
ok('nothing of the purchase side: no dirham cost, no margin', stripos($llms, 'AED') === false
   && mb_strpos($llms, 'حاشیه سود') === false && mb_strpos($llms, 'قیمت خرید') === false);

/* ------------------------------------------------------------------------ */
head('.htaccess: one address, and a year for what is versioned');
$ht = (string) file_get_contents(SUPPEX_ROOT . '/.htaccess');
$www = strpos($ht, 'RewriteCond %{HTTP_HOST} ^www\.(.+)$ [NC]');
$https = strpos($ht, 'RewriteCond %{HTTPS} off');
ok('www goes to the bare name, before the https rule - one hop, not two',
   $www !== false && $https !== false && $www < $https
   && strpos($ht, 'RewriteRule ^(.*)$ https://%1/$1 [R=301,L]') !== false);
ok('«/» is index.php, then index.html', strpos($ht, 'DirectoryIndex index.php index.html') !== false);
ok('/index.html and /index.php are 301s to «/», by THE_REQUEST so «/» does not loop',
   strpos($ht, 'RewriteCond %{THE_REQUEST} \s/index\.(html|php)[\s?] [NC]') !== false
   && strpos($ht, 'RewriteRule ^index\.(html|php)$ / [R=301,L]') !== false);
ok('llms.txt is served', strpos($ht, 'RewriteRule ^llms\.txt$    llms.php    [L]') !== false);
ok('HSTS', strpos($ht, 'Header always set Strict-Transport-Security "max-age=31536000"') !== false);
preg_match('~<FilesMatch "\^\(([^)]+)\)\$">\s*Header set Cache-Control "public, max-age=31536000, immutable"~', $ht, $fm);
$names = isset($fm[1]) ? explode('|', str_replace('\.', '.', $fm[1])) : [];
sort($names);
$versioned = ['site.css', 'config.js', 'catalog.js', 'repo.js', 'store.js', 'ui.js', 'app.js'];
sort($versioned);
ok('a year, by name, for exactly the files every page asks for through asset()', $names === $versioned, implode(',', $names));
ok('  after the no-cache rule for everything else, which it overrides',
   ($nc = strpos($ht, '<FilesMatch "\.(css|js)$">')) !== false && isset($fm[0]) && $nc < strpos($ht, $fm[0]));
ok('fonts for a year', preg_match('~<FilesMatch "\\\\\.\(woff2\|woff\|ttf\)\$">\s*Header set Cache-Control "public, max-age=31536000, immutable"~', $ht) === 1);
ok('uploaded pictures for a year - a new picture is a new name',
   preg_match('~<FilesMatch "\\\\\.\(webp\|jpe\?g\|png\|gif\|avif\)\$">\s*Header set Cache-Control "public, max-age=31536000, immutable"~',
              (string) file_get_contents(SUPPEX_ROOT . '/uploads/.htaccess')) === 1);
ob_start(); include SUPPEX_ROOT . '/robots.php'; $robots = (string) ob_get_clean();
ok('robots.txt shuts no crawler out, the AI ones included', preg_match('~^Disallow:\s*/\s*$~m', $robots) === 0
   && strpos($robots, 'Sitemap: https://suppex.ir/sitemap.xml') !== false);

echo "\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
