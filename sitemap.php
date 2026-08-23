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

header('Content-Type: application/xml; charset=utf-8');
/* Cached briefly: crawlers re-request this far more often than the catalogue
   changes, and it is a full table scan each time. */
header('Cache-Control: public, max-age=3600');

$origin = seo_origin();

$products = db_all(
    'SELECT slug, updated_at FROM products
      WHERE is_active = 1 ORDER BY sort_order, id'
);

$xml = new XMLWriter();
$xml->openMemory();
$xml->setIndent(true);
$xml->startDocument('1.0', 'UTF-8');
$xml->startElement('urlset');
$xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

/** One <url> entry. */
$entry = static function (string $loc, ?string $lastmod, string $freq, string $priority) use ($xml): void {
    $xml->startElement('url');
    $xml->writeElement('loc', $loc);
    if ($lastmod !== null) {
        $xml->writeElement('lastmod', date('Y-m-d', strtotime($lastmod)));
    }
    $xml->writeElement('changefreq', $freq);
    $xml->writeElement('priority', $priority);
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
        '0.8'
    );
}

$xml->endElement();
$xml->endDocument();
echo $xml->outputMemory();
