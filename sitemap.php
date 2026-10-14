<?php
/* ===========================================================================
   SUPPEX — sitemap
   ---------------------------------------------------------------------------
   Generated from the database rather than written by hand, because a hand-kept
   sitemap is wrong within a week of the first product being added and nobody
   notices — a stale sitemap is worse than none, since it actively points
   crawlers at pages that no longer exist.

   Served at /sitemap.xml through a rewrite in .htaccess.

   Only active, in-catalogue products appear. A hidden product listed here is
   an invitation to crawl a 404.
   =========================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/seo.php';
require_once __DIR__ . '/lib/journal.php';
require_once __DIR__ . '/lib/pages.php';
/* This file read products with raw SQL and never needed the catalogue model.
   Categories are not a table it can scan blindly: categories_list() is the only
   reader that filters is_active, and a sitemap listing a category the owner has
   hidden is a sitemap arguing with the page it points at. */
require_once __DIR__ . '/lib/products.php';
require_once __DIR__ . '/lib/goals.php';

header('Content-Type: application/xml; charset=utf-8');
/* Cached briefly: crawlers re-request this far more often than the catalogue
   changes, and it is a full table scan each time. */
header('Cache-Control: public, max-age=3600');

$origin = seo_origin();

$products = db_all(
    'SELECT id, slug, image, updated_at FROM products
      WHERE is_active = 1 ORDER BY sort_order, id'
);

/* Each product's photographs, for Google Images: the main one first, then the
   gallery in the order the page shows it. Photographs only - the bundled
   .svg drawings stand in for a product nobody has photographed yet, and are
   not what an image search should find. Ten at most; nobody pages further. */
$photos = [];
foreach ($products as $p) {
    $photos[(int) $p['id']] = [];
    if ((string) $p['image'] !== '') {
        $photos[(int) $p['id']][] = (string) $p['image'];
    }
}
foreach (db_all('SELECT product_id, path FROM product_images ORDER BY sort_order, id') as $r) {
    if (isset($photos[(int) $r['product_id']])) {
        $photos[(int) $r['product_id']][] = (string) $r['path'];
    }
}
foreach ($photos as $id => $list) {
    $keep = [];
    foreach ($list as $path) {
        $path = trim($path);
        if ($path !== '' && !str_ends_with(strtolower($path), '.svg')) {
            $keep[seo_absolute($path)] = true;
        }
    }
    $photos[$id] = array_slice(array_keys($keep), 0, 10);
}

$xml = new XMLWriter();
$xml->openMemory();
$xml->setIndent(true);
$xml->startDocument('1.0', 'UTF-8');
$xml->startElement('urlset');
$xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
$xml->writeAttribute('xmlns:image', 'http://www.google.com/schemas/sitemap-image/1.1');

/** One <url> entry, with the pictures on that page when there are any. */
$entry = static function (string $loc, ?string $lastmod, string $freq, string $priority,
                          array $images = []) use ($xml): void {
    $xml->startElement('url');
    $xml->writeElement('loc', $loc);
    if ($lastmod !== null) {
        $xml->writeElement('lastmod', date('Y-m-d', strtotime($lastmod)));
    }
    $xml->writeElement('changefreq', $freq);
    $xml->writeElement('priority', $priority);
    foreach ($images as $src) {
        $xml->startElement('image:image');
        $xml->writeElement('image:loc', $src);
        $xml->endElement();
    }
    $xml->endElement();
};

$entry($origin . '/', null, 'daily', '1.0');

/* Prices move with the dirham, so a product page genuinely does change often —
   "daily" here is a description, not an optimisation. */
foreach ($products as $p) {
    $entry(
        $origin . '/product.php?p=' . rawurlencode($p['slug']),
        $p['updated_at'] ?? null,
        'daily',
        '0.8',
        $photos[(int) $p['id']] ?? []
    );
}

/* The journal. Unlike a product, an article is written once and then left
   alone, so it is monthly and its lastmod is the file's own mtime - the only
   honest date available, since there is no database row to ask. */
foreach (journal_all() as $a) {
    $file = SUPPEX_ROOT . '/content/journal/' . $a['slug'] . '.md';
    $mtime = @filemtime($file);
    $entry(
        $origin . '/article.php?a=' . rawurlencode($a['slug']),
        $mtime ? gmdate('Y-m-d', $mtime) : null,
        'monthly',
        '0.6'
    );
}

/* Categories. Weekly, because what is in one changes when stock does.

   An EMPTY category is left out entirely, and its page says noindex in the
   same breath - that is Google's own explicit instruction for a category with
   no items, and having the two decisions in different files is how they end up
   disagreeing. No lastmod: the categories table has no updated_at, and a date
   invented from something else would be worse than none. */
foreach (categories_list() as $c) {
    if ((int) $c['count'] === 0) {
        continue;
    }
    $entry($origin . '/category.php?c=' . rawurlencode((string) $c['id']), null, 'weekly', '0.7');
}

/* The goals (lib/goals.php): a page for each with something on sale - the
   rule the home page shows them by and goal.php answers 404 by. Weekly, like
   the categories they are made of. */
foreach (goals_live() as $g) {
    $entry($origin . '/' . goal_url((string) $g['slug']), null, 'weekly', '0.7');
}

/* Brands: the wall, and a page for each brand with something on sale -
   brands_list() leaves out the empty and the hidden, the same rule the pages
   themselves answer 404 by. Weekly, like a category. */
$brandPages = brands_list();
if ($brandPages) {
    $entry($origin . '/brands.php', null, 'weekly', '0.5');
}
foreach ($brandPages as $b) {
    $entry($origin . '/' . brand_url($b['slug']), null, 'weekly', '0.6');
}

/* The journal index. Monthly, because it changes when an article is added. */
$entry($origin . '/journal.php', null, 'monthly', '0.5');

/* The standing pages. Yearly and low, and both are the truth: a returns policy
   is rewritten when the policy changes, which is rarely, and nobody arrives at
   this shop looking for its terms. They are here because a customer who wants
   to check them before transferring money should be able to FIND them, and
   because a page reachable only through a footer link gets indexed late. */
foreach (pages_all() as $p) {
    $file  = SUPPEX_ROOT . '/content/pages/' . $p['slug'] . '.md';
    $mtime = @filemtime($file);
    $entry(
        $origin . '/' . page_url($p['slug']),
        $mtime ? gmdate('Y-m-d', $mtime) : null,
        'yearly',
        '0.3'
    );
}

$xml->endElement();
$xml->endDocument();
echo $xml->outputMemory();
