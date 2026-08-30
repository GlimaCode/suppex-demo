<?php
/* Renders product.php for real products and checks that each one gets its own
   metadata — the defect being fixed is that they all shared one product's. */
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST']   = 'suppex.ir';
$_SERVER['HTTPS']       = 'on';
$_SERVER['REQUEST_METHOD'] = 'GET';

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/products.php';
require_once SUPPEX_ROOT . '/lib/seo.php';

$pass = 0; $fail = 0;
function ok(string $l, bool $c, ?string $x = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $l\n"; }
    else { $fail++; echo "  FAIL $l" . ($x ? "  --  $x" : '') . "\n"; }
}
function render(string $slug): string {
    $_GET = ['p' => $slug];
    ob_start(); include SUPPEX_ROOT . '/product.php'; return ob_get_clean();
}
function tagOf(string $html, string $pattern): string {
    return preg_match($pattern, $html, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : '';
}

echo "\n== each product gets its own metadata ==\n";
$a = render('whey-original');
$b = render('iso-zero');

$titleA = tagOf($a, '~<title>(.*?)</title>~s');
$titleB = tagOf($b, '~<title>(.*?)</title>~s');
ok('titles differ between products', $titleA !== '' && $titleA !== $titleB, "$titleA | $titleB");
ok('title names the product viewed', mb_strpos($titleA, 'وی پروتئین') !== false, $titleA);

$ogA = tagOf($a, '~og:title" content="(.*?)"~');
$ogB = tagOf($b, '~og:title" content="(.*?)"~');
ok('og:title differs too', $ogA !== $ogB, "$ogA | $ogB");

$descA = tagOf($a, '~name="description" content="(.*?)"~');
$descB = tagOf($b, '~name="description" content="(.*?)"~');
ok('descriptions differ', $descA !== '' && $descA !== $descB);
ok('description is a sensible length', mb_strlen($descA) <= 160 && mb_strlen($descA) > 20,
   mb_strlen($descA) . ' chars');

$imgA = tagOf($a, '~og:image" content="(.*?)"~');
ok('og:image is absolute', str_starts_with($imgA, 'https://suppex.ir/'), $imgA);
/* Every product now gets a card drawn for it, so the picture carries the name
   and the price whether or not a photograph has been uploaded yet. The old
   answer — fall back to one shared brand cover — gave fifteen links the same
   preview, which for a shop that sells through Telegram is the listing. */
$imgB = tagOf($b, '~og:image" content="(.*?)"~');
ok('og:image is a generated card', str_contains($imgA, '/og.php?p=whey-original'), $imgA);
ok('not the shared brand cover',   !str_ends_with($imgA, '/og-cover.png'), $imgA);
ok('and a different product gets a different one', $imgA !== $imgB, $imgA . ' vs ' . $imgB);

db_query("UPDATE products SET image = '/uploads/products/2026/08/real-whey.jpg'
           WHERE slug = 'whey-original'");
db_query("UPDATE products SET image = '/uploads/products/2026/08/real-iso.webp'
           WHERE slug = 'iso-zero'");
$ra = tagOf(render('whey-original'), '~og:image" content="([^"]*)"~');
$rb = tagOf(render('iso-zero'),      '~og:image" content="([^"]*)"~');
/* A photograph changes what the card looks like, so it must change the URL:
   WhatsApp caches a preview per URL with no way to invalidate it. */
ok('uploading a photo changes the card url', $ra !== $imgA, $ra);
ok('and it is still the card, not the bare file',
   str_contains($ra, '/og.php?p=whey-original'), $ra);
ok('and differs per product',      $ra !== $rb, "$ra | $rb");
ok('still absolute',               str_starts_with($ra, 'https://suppex.ir/'), $ra);
db_query("UPDATE products SET image = 'assets/images/products/whey-original.svg'
           WHERE slug = 'whey-original'");
db_query("UPDATE products SET image = 'assets/images/products/iso-zero.svg'
           WHERE slug = 'iso-zero'");

$canA = tagOf($a, '~rel="canonical" href="(.*?)"~');
ok('canonical is absolute and per-product',
   $canA === 'https://suppex.ir/product.php?p=whey-original', $canA);

echo "\n== structured data describes the product being viewed ==\n";
preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $a, $m);
$ld = json_decode(trim($m[1] ?? ''), true);
ok('JSON-LD parses', is_array($ld), substr(trim($m[1] ?? ''), 0, 60));
ok('it is a Product', ($ld['@type'] ?? '') === 'Product');
ok('name matches this product', mb_strpos($ld['name'] ?? '', 'وی پروتئین') !== false, $ld['name'] ?? '');
ok('sku is this slug', ($ld['sku'] ?? '') === 'whey-original', $ld['sku'] ?? '');
ok('offer carries a real price', (int) ($ld['offers']['price'] ?? 0) > 0, (string) ($ld['offers']['price'] ?? ''));
ok('offer url is the canonical', ($ld['offers']['url'] ?? '') === $canA);
/* Rating markup without visible reviews is a manual-action risk. */
ok('NO invented aggregateRating', !isset($ld['aggregateRating']));
ok('NO invented review',          !isset($ld['review']));

echo "\n== a missing product is a real 404 ==\n";
http_response_code(200);
$missing = render('does-not-exist-at-all');
/* http_response_code() cannot be set from CLI once the harness has echoed, so
   the status itself is verified over real HTTP instead; what is checked here is
   that the page asks not to be indexed and carries no product. */
/* Scoped to <main>: the footer's shop links legitimately name products on
   every page, including this one. */
$mainOnly = (string) (preg_match('~<main[^>]*>.*?</main>~s', $missing, $m) ? $m[0] : $missing);
ok('carries no product name', mb_strpos($mainOnly, 'وی پروتئین') === false);
ok('and no empty product skeleton either',
   mb_strpos($missing, 'data-p-title') === false);
ok('and asks not to be indexed',
   mb_strpos($missing, 'name="robots" content="noindex') !== false);

echo "\n== head hygiene ==\n";
/* Matched with the closing quote of the property name, so og:image does not
   also count og:image:width, og:image:height and og:image:alt. */
foreach (['<title>', 'og:title"', 'og:image"', 'rel="canonical"',
          'name="description"', 'og:site_name"'] as $needle) {
    ok("exactly 1 × $needle", substr_count($a, $needle) === 1,
        (string) substr_count($a, $needle));
}
foreach (['og:image:width', 'og:image:height', 'og:image:alt'] as $needle) {
    ok("declared: $needle", substr_count($a, $needle) === 1);
}
ok('lang and dir are set', mb_strpos($a, 'lang="fa"') !== false && mb_strpos($a, 'dir="rtl"') !== false);
ok('exactly one h1', substr_count($a, '<h1') === 1, (string) substr_count($a, '<h1'));
ok('no PHP leaked into the output', mb_strpos($a, '<?') === false);

echo "\n== sitemap ==\n";
$_GET = [];
/* The harness has already echoed, so header() calls in the included file warn
   into the same buffer. Strip anything before the XML declaration — the file
   itself is fine, the CLI context is not. */
ob_start(); include SUPPEX_ROOT . '/sitemap.php'; $raw = ob_get_clean();
$xml = ($pos = strpos($raw, '<?xml')) !== false ? substr($raw, $pos) : $raw;
ok('is well-formed XML', simplexml_load_string($xml) !== false);
$sx = simplexml_load_string($xml);
$count = $sx === false ? 0 : count($sx->url);
ok('lists the homepage plus every active product', $count >= 2, $count . ' urls');
ok('urls are absolute', mb_strpos($xml, 'https://suppex.ir/') !== false);
ok('hidden products are excluded',
   $count === 1 + (int) db_value('SELECT COUNT(*) FROM products WHERE is_active = 1'),
   $count . ' vs ' . (1 + (int) db_value('SELECT COUNT(*) FROM products WHERE is_active = 1')));


echo "\n== what a crawler with no JavaScript sees ==\n";
$whey = render('whey-original');
$iso  = render('iso-zero');

/* Torob and Emalls run no JS. A product page with no price in the markup
   cannot be listed on a price-comparison engine at all. */
$p = (int) db_value("SELECT price FROM products WHERE slug='whey-original'");
ok('the price is in the raw HTML', mb_strpos($whey, number_format($p)) !== false,
   number_format($p));
ok('the h1 names THIS product',
   preg_match('~<h1[^>]*>(.*?)</h1>~s', $whey, $m) && mb_strpos($m[1], 'وی پروتئین') !== false,
   trim($m[1] ?? ''));
ok('a different product gets a different h1',
   preg_match('~<h1[^>]*>(.*?)</h1>~s', $iso, $m2) && trim($m2[1]) !== trim($m[1]),
   trim($m2[1] ?? ''));
ok('the main image is this product', mb_strpos($whey, 'whey-original.svg') !== false);
ok('breadcrumb names this product', mb_strpos($whey, 'data-p-breadcrumb>وی پروتئین') !== false);
ok('stock state is stated in text',
   mb_strpos($whey, 'موجود در انبار') !== false || mb_strpos($whey, 'ناموجود') !== false);
ok('no other product leaked into the page',
   mb_strpos($whey, 'ایزوله زیرو') === false);

echo "\n== schema price is in rial, not toman mislabelled ==\n";
preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $whey, $m);
$ld = json_decode(trim($m[1]), true);
ok('priceCurrency is a real ISO 4217 code', ($ld['offers']['priceCurrency'] ?? '') === 'IRR');
ok('price is the toman figure x10',
   (int) $ld['offers']['price'] === $p * 10,
   $ld['offers']['price'] . ' vs ' . ($p * 10));

echo "\n== og:image is never a vector ==\n";
$img = '';
preg_match('~og:image" content="([^"]*)"~', $whey, $mi) && $img = $mi[1];
ok('svg product image falls back to the cover',
   !preg_match('~\.svgz?$~i', $img), $img);
ok('dimensions are declared', mb_strpos($whey, 'og:image:width') !== false);
ok('alt is declared', mb_strpos($whey, 'og:image:alt') !== false);

echo "\n== robots.txt ==\n";
$_GET = [];
ob_start(); include SUPPEX_ROOT . '/robots.php'; $raw = ob_get_clean();
$rb = ($pos = strpos($raw, 'User-agent')) !== false ? substr($raw, $pos) : $raw;
ok('Sitemap is an absolute URL',
   preg_match('~^Sitemap: https?://[^/]+/sitemap\.xml~m', $rb) === 1,
   trim(strstr($rb, 'Sitemap:') ?: ''));
ok('admin is disallowed', mb_strpos($rb, 'Disallow: /admin/') !== false);
ok('uploads stays crawlable', mb_strpos($rb, 'Disallow: /uploads') === false);
ok('api stays crawlable', mb_strpos($rb, 'Disallow: /api') === false);

echo "\n== a product that is gone says so, and does not draw an empty shop ==\n";
/* product.php already answered 404 for a missing slug, but then rendered the
   whole product page with every field blank: an add-to-cart button for
   nothing, a batch certificate for a product that does not exist. A stale link
   shared in Telegram - the realistic way anyone arrives here - looked like a
   broken site rather than a removed product. */
$_GET = ["p" => "no-such-product-at-all"];
$_SERVER["REQUEST_URI"] = "/product.php?p=no-such-product-at-all";
http_response_code(200);
ob_start(); include SUPPEX_ROOT . "/product.php"; $gone = ob_get_clean();

ok("the title says the product is missing",
   mb_strpos($gone, "محصول پیدا نشد") !== false);
ok("it is not indexable", mb_strpos($gone, 'name="robots" content="noindex') !== false);
ok("it explains itself", mb_strpos($gone, "این محصول دیگر نیست") !== false);
ok("and offers a way onward",
   mb_strpos($gone, 'href="index.html#categories"') !== false);

ok("no add-to-cart for a product that is not there",
   mb_strpos($gone, "افزودن به سبد") === false);
/* «برگه آزمایش سری تولید» sits in the announcement bar on every page;
   what must be gone is the product's own certificate block. */
ok("no batch certificate block",
   mb_strpos($gone, "دانلود گواهی") === false);
ok("no price", preg_match('/data-p-price/', $gone) === 0);
ok("the page is short, not a full skeleton", strlen($gone) < 30000, (string) strlen($gone));
ok("but it keeps the site header", mb_strpos($gone, "SUPPEX") !== false);
ok("no PHP leaked", mb_strpos($gone, "<?") === false);

/* A real product is untouched by the branch. */
$_GET = ["p" => "whey-original"];
ob_start(); include SUPPEX_ROOT . "/product.php"; $live = ob_get_clean();
ok("a real product still has its add-to-cart", mb_strpos($live, "افزودن به سبد") !== false);
ok("and its price", mb_strpos($live, "data-p-price") !== false);
ok("and is indexable", mb_strpos($live, "noindex") === false);

echo "\n== the site-wide 404 page ==\n";
$page = SUPPEX_ROOT . "/404.html";
ok("404.html exists", is_file($page));
$html = (string) file_get_contents($page);
ok("it is not indexable", mb_strpos($html, 'content="noindex') !== false);
ok("it links home and to the catalogue",
   mb_strpos($html, 'href="/"') !== false && mb_strpos($html, 'href="/#categories"') !== false);
/* Apache serves this file for /any/deep/path, so a relative stylesheet would
   be looked for under that imaginary directory and the page would arrive
   unstyled - which reads as a broken server rather than a wrong address. */
ok("every asset path is absolute",
   preg_match('~(?:href|src)="(?!/|https?:|#)[^"]+\.(css|js|svg|png)"~', $html) === 0,
   "a relative asset would break on a deep URL");
ok("and .htaccess points at it",
   mb_strpos((string) file_get_contents(SUPPEX_ROOT . "/.htaccess"),
             "ErrorDocument 404 /404.html") !== false);

echo "\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
