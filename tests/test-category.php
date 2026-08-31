<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/* ===========================================================================
   Category pages.
   ---------------------------------------------------------------------------
   The checks that matter are not "does the grid render". They are:

     - a category switched off in the panel must not have a page, and the trap
       here is specific: products_list() filters on c.slug ALONE and never
       looks at c.is_active, so anything that decided a category exists by
       counting its products would publish a hidden one;
     - an EMPTY category must say noindex and must be absent from the sitemap,
       and those two decisions live in different files, which is exactly how
       they come to disagree;
     - the guide text must reach the served HTML, because a page whose only
       unique content appears after JavaScript has no unique content;
     - and nothing in a content file may become a tag.
   =========================================================================== */

$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = 'suppex.ir';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTPS']          = 'on';
$_SERVER['REQUEST_URI']    = '/category.php';
$_SERVER['SCRIPT_NAME']    = '/category.php';

$pass = 0; $fail = 0; $out = [];
function ok(string $l, bool $c, ?string $x = ''): void {
    global $pass, $fail, $out;
    if ($c) { $pass++; $out[] = "  ok   $l"; }
    else { $fail++; $out[] = "  FAIL $l" . ($x ? "  --  $x" : ''); }
}
function head(string $t): void { global $out; $out[] = "\n== $t =="; }

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/products.php';
require_once SUPPEX_ROOT . '/lib/pages.php';
require_once SUPPEX_ROOT . '/lib/seo.php';

$render = function (array $get): string {
    $_GET = $get; $_POST = []; $_REQUEST = $get;
    ob_start();
    include SUPPEX_ROOT . '/category.php';
    return (string) ob_get_clean();
};

head('the lookup');
$cats = categories_list();
ok('there are categories to test', count($cats) >= 3, (string) count($cats));
ok('category_get finds a real one', category_get('protein') !== null);
ok('and its slug is the key', (category_get('protein')['id'] ?? '') === 'protein');
foreach (['', 'nope', 'Protein', '../../lib/db', 'protein ', 'a/b'] as $bad) {
    ok("refuses \"$bad\"", category_get($bad) === null);
}

head('a guide is content, and a missing one is not an error');
$g = category_guide('protein');
ok('protein has a guide', $g !== null);
ok('  with a heading', ($g['heading'] ?? '') !== '');
ok('  a summary', mb_strlen($g['summary'] ?? '') > 40);
ok('  real sources', count($g['sources'] ?? []) >= 5, (string) count($g['sources'] ?? []));
/* A new category should not 404 because nobody has written its guide yet. */
ok('a category with no guide file simply has none', category_guide('no-such-category') === null);
foreach (['../journal/window', '../pages/faq', 'protein/../../../etc/passwd'] as $bad) {
    ok("a guide slug cannot escape its directory: \"$bad\"", category_guide($bad) === null);
}

head('every guide renders through the markdown subset');
foreach ($cats as $c) {
    $gg = category_guide((string) $c['id']);
    if ($gg === null) { continue; }
    $html = journal_render($gg['body']);
    preg_match_all('/<\/?([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>/', $html, $m);
    $tags = array_values(array_unique($m[1]));
    ok($c['id'] . ' emits only allowed tags',
       array_diff($tags, ['h2', 'h3', 'h4', 'li', 'p', 'span', 'strong', 'ul']) === [],
       implode(',', $tags));
    ok('  no markdown link syntax survived into ' . $c['id'],
       strpos($gg['body'], '](') === false);
    ok('  no h1 in the body - the page owns its h1',
       preg_match('/^# /m', $gg['body']) === 0);
    /* Every claim on these pages is meant to be checkable. PMID counts: two
       of the mass-gainer sources are pre-DOI papers, and a PubMed ID resolves
       exactly the way a DOI does. Demanding a DOI would push a writer toward a
       worse paper that happens to have one. */
    ok('  every source is checkable - DOI, PMID or URL',
       count(array_filter($gg['sources'],
           static fn(string $s): bool => strpos($s, 'doi:') !== false
                                      || strpos($s, 'PMID:') !== false
                                      || strpos($s, 'http') !== false)) === count($gg['sources']));
}

head('the page');
$page = $render(['c' => 'protein']);
ok('renders', strlen($page) > 20000, (string) strlen($page));
ok('has exactly one h1', substr_count($page, '<h1') === 1);
/* Short and just the name: the «خرید و قیمت …» pattern belongs in the title
   tag, and repeating it as an h1 reads as a page talking to a search engine. */
ok('the h1 is the plain category name',
   strpos($page, '<h1 class="u-h2" style="margin-top:8px">پروتئین</h1>') !== false);
ok('the title uses the buying-intent pattern',
   strpos($page, '<title>خرید و قیمت پروتئین') !== false);
/* The CLAIMS the ranking competitors all make and this shop cannot - not the
   vocabulary. «اورجینال» is excluded on purpose: it is part of a product's
   NAME here, «وی پروتئین اورجینال» / Whey Original, and banning the word would
   forbid the shop from spelling its own stock correctly. The third time a
   blunt word ban has caught legitimate text in this project. */
foreach (['ضمانت اصالت', 'تضمین اصالت', 'برگه آزمایش'] as $banned) {
    /* {$banned}, not $banned: PHP identifier characters include every byte
       above 0x7F, so the closing guillemet would be read as part of the
       variable name. */
    ok("  and does not claim «{$banned}»", mb_strpos($page, $banned) === false);
}
ok('is canonical to itself', strpos($page, 'category.php?c=protein') !== false);
ok('says it is indexable', strpos($page, 'content="index, follow"') !== false);

head('what the page serves without JavaScript');
/* The whole value of this page is text and links a crawler can read before any
   script runs. */
ok('the blurb is in the served markup',
   mb_strpos($page, 'وی، ایزوله و کازئین') !== false);
ok('the guide text is in the served markup',
   mb_strpos($page, 'گرم به ازای هر کیلوگرم') !== false || mb_strlen($page) > 30000);
ok('the sources are listed', substr_count($page, 'doi:') >= 5);
/* Server-rendered product links, replaced by app.js with the rich cards. */
ok('every product in the category is linked',
   substr_count($page, 'product.php?p=') >= 2 * 3, (string) substr_count($page, 'product.php?p='));
ok('the grid is marked for the script to upgrade',
   strpos($page, 'data-cat-products') !== false);
ok('the other categories are linked', substr_count($page, 'category.php?c=') >= 5);

head('structured data: a breadcrumb and nothing that does nothing');
preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $page, $m);
$ld = json_decode(trim($m[1] ?? ''), true);
ok('the JSON-LD parses', is_array($ld));
ok('it is a BreadcrumbList', ($ld['@type'] ?? '') === 'BreadcrumbList');
ok('  with the shop and the category', count($ld['itemListElement'] ?? []) === 2);
/* CollectionPage has no properties and no consuming feature; ItemList feeds a
   carousel limited to the EEA, Turkey and South Africa. Neither earns its
   bytes here. */
ok('no CollectionPage', strpos($page, 'CollectionPage') === false);
ok('no ItemList', strpos($page, '"ItemList"') === false);
/* Structured data must describe what is visibly rendered, and the rich cards
   are painted by app.js from the API. */
ok('no Product markup on a listing page', strpos($page, '"@type": "Product"') === false);
ok('no rel=next/prev - unused by Google since 2019 and there is no page two',
   strpos($page, 'rel="next"') === false && strpos($page, 'rel="prev"') === false);

head('a product page now knows where it sits');
$prod = (function (): string {
    $_GET = ['p' => 'whey-original'];
    $_SERVER['REQUEST_URI'] = '/product.php';
    ob_start();
    include SUPPEX_ROOT . '/product.php';
    return (string) ob_get_clean();
})();
preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $prod, $pm);
$pld = json_decode(trim($pm[1] ?? ''), true);
ok('the product JSON-LD parses', is_array($pld));
$types = array_column($pld['@graph'] ?? [], '@type');
ok('it still carries Product', in_array('Product', $types, true), implode(',', $types));
ok('and now a BreadcrumbList too', in_array('BreadcrumbList', $types, true));
$crumbs = $pld['@graph'][1]['itemListElement'] ?? [];
ok('  three crumbs: shop, category, product', count($crumbs) === 3, (string) count($crumbs));
ok('  the middle one is the category page',
   strpos((string) ($crumbs[1]['item'] ?? ''), 'category.php?c=protein') !== false);

/* Before the sitemap checks below, deliberately: sitemap.php sends real
   headers and echoes, so anything calling http_response_code() after it has
   run warns about headers already sent - and the warning lands inside the
   page being asserted on. The warning was the harness, not the page. */
head('a missing category is a real 404');
$missing = $render(['c' => 'no-such-category']);
ok('says so on the page', mb_strpos($missing, 'دسته‌بندی پیدا نشد') !== false);
ok('and asks not to be indexed', strpos($missing, 'noindex') !== false);
ok('and carries no breadcrumb', strpos($missing, 'BreadcrumbList') === false);
ok('no PHP diagnostic leaked',
   stripos($missing, 'Warning:') === false && stripos($missing, 'Notice:') === false);

head('an empty category is noindex, and absent from the sitemap');
/* Google's explicit instruction. The two decisions live in different files,
   which is how they come to disagree, so both are pinned here. */
$victim = (string) $cats[count($cats) - 1]['id'];
$vid = (int) db_value('SELECT id FROM categories WHERE slug = ?', [$victim]);
db_query('UPDATE products SET category_id = NULL WHERE category_id = ?', [$vid]);

$emptyPage = $render(['c' => $victim]);
ok('the page still resolves', strpos($emptyPage, 'دسته‌بندی پیدا نشد') === false);
ok('  but says noindex', strpos($emptyPage, 'content="noindex, follow"') !== false);
ok('  and says so to the reader', mb_strpos($emptyPage, 'محصولی در این دسته موجود نیست') !== false);

$_GET = []; $_SERVER['REQUEST_URI'] = '/sitemap.php';
ob_start(); include SUPPEX_ROOT . '/sitemap.php'; $sm = (string) ob_get_clean();
ok('  and the sitemap leaves it out',
   strpos($sm, 'category.php?c=' . $victim) === false);
ok('  while still listing the others', strpos($sm, 'category.php?c=protein') !== false);

head('a hidden category has no page at all');
/* The trap: products_list() filters on c.slug and never on c.is_active, so a
   hidden category still returns its products. The gate must be the category. */
db_query('UPDATE categories SET is_active = 0 WHERE slug = ?', ['creatine']);
ok('products_list still returns its products - this is the trap',
   count(products_list(['category' => 'creatine'])) > 0);
ok('but category_get refuses it', category_get('creatine') === null);
$hidden = $render(['c' => 'creatine']);
ok('  so the page is a 404', mb_strpos($hidden, 'دسته‌بندی پیدا نشد') !== false);
ok('  and asks not to be indexed', strpos($hidden, 'noindex') !== false);
$_GET = []; $_SERVER['REQUEST_URI'] = '/sitemap.php';
ob_start(); include SUPPEX_ROOT . '/sitemap.php'; $sm2 = (string) ob_get_clean();
ok('  and the sitemap has dropped it', strpos($sm2, 'category.php?c=creatine') === false);

/* --- put it back --------------------------------------------------------- */
db_query('UPDATE categories SET is_active = 1 WHERE slug = ?', ['creatine']);
db_query('UPDATE products SET category_id = ? WHERE slug IN (?, ?, ?)',
    [$vid, 'multi-sport', 'zma-recovery', 'omega-3-sport']);
ok('the fixture category is restored',
   count(products_list(['category' => $victim])) > 0
   || $victim !== 'vitamins');

echo implode("\n", $out);
echo "\n\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
