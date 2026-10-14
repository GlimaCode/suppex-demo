<?php
/* ===========================================================================
   SUPPEX — llms.txt
   ---------------------------------------------------------------------------
   What this shop is and sells, as one plain page an AI assistant can read in
   a single request: the categories, the brands, every product on sale with
   today's price and a link, the pages that say how ordering, shipping and
   returns work, and the journal. The convention is llmstxt.org's: a title, a
   one-paragraph summary, then sections of links.

   Built from the database on every request, like the sitemap, so it is never
   a week behind the catalogue. Every sentence in it is either data or text
   the site already publishes - the home page's own description, the pages'
   own summaries - because an assistant repeats what it reads here as fact.

   Served at /llms.txt through a rewrite in .htaccess.
   =========================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/products.php';
require_once __DIR__ . '/lib/journal.php';
require_once __DIR__ . '/lib/pages.php';
require_once __DIR__ . '/lib/seo.php';
require_once __DIR__ . '/lib/goals.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$origin = seo_origin();
$shop   = (string) setting('shop_name', 'SUPPEX');
$cfg    = storefront_config();

/* The summary is the home page's own description, so there is one text. */
$summary = '';
if (preg_match('~<meta name="description" content="([^"]+)">~u',
               (string) @file_get_contents(__DIR__ . '/index.html'), $m)) {
    $summary = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
}

$line = static fn(string $s): string => trim((string) preg_replace('/\s+/u', ' ', $s));
$toman = static fn(int $n): string => number_format($n) . ' تومان';

$out = [];
$out[] = '# ' . $shop . ' (ساپکس)';
$out[] = '';
if ($summary !== '') {
    $out[] = '> ' . $line($summary);
    $out[] = '';
}
$out[] = '- نشانی: ' . $origin . '/';
$out[] = '- زبان سایت فارسی است و قیمت‌ها به تومان.';
$out[] = '- قیمت‌ها از نرخ درهم حساب می‌شوند؛ عددهای این صفحه قیمت همین لحظه‌اند و با نرخ تغییر می‌کنند.';
$out[] = '- هزینهٔ ارسال: رایگان برای جمع کالای ' . $toman((int) $cfg['freeShippingThreshold'])
       . ' و بیشتر؛ زیر آن ' . $toman((int) $cfg['shippingFlatRate']) . '.';
$out[] = '- پیگیری سفارش با شماره پیگیری و شماره موبایل: ' . $origin . '/track.php';
$out[] = '';

$categories = categories_list();
if ($categories) {
    $out[] = '## دسته‌بندی‌ها';
    $out[] = '';
    foreach ($categories as $c) {
        if ((int) $c['count'] === 0) {
            continue;
        }
        $blurb = $line((string) ($c['blurb'] ?? ''));
        $out[] = '- [' . $c['name'] . '](' . seo_category_url((string) $c['id']) . '): '
               . (int) $c['count'] . ' محصول' . ($blurb !== '' ? ' — ' . $blurb : '');
    }
    $out[] = '';
}

$goals = goals_live($categories);
if ($goals) {
    $out[] = '## خرید بر اساس هدف';
    $out[] = '';
    foreach ($goals as $g) {
        $out[] = '- [' . $g['title'] . '](' . $origin . '/' . goal_url((string) $g['slug']) . '): '
               . implode('، ', array_column($g['live'], 'name'));
    }
    $out[] = '';
}

$brands = brands_list();
if ($brands) {
    $out[] = '## برندها';
    $out[] = '';
    foreach ($brands as $b) {
        $label = (string) $b['name'] . ((string) $b['nameFa'] !== '' ? ' (' . $b['nameFa'] . ')' : '');
        $out[] = '- [' . $label . '](' . $origin . '/' . brand_url((string) $b['slug']) . '): '
               . (int) $b['count'] . ' محصول';
    }
    $out[] = '';
}

/* No limit: products_list() caps a given one at 200, and this is the list. */
$products = products_list([]);
if ($products) {
    $out[] = '## محصولات';
    $out[] = '';
    foreach ($products as $p) {
        $name  = trim((string) $p['nameFa'] . ((string) ($p['name'] ?? '') !== '' ? ' — ' . $p['name'] : ''));
        $bits  = [];
        if ((string) ($p['brand'] ?? '') !== '') {
            $bits[] = (string) $p['brand'];
        }
        $price = $toman((int) $p['price']);
        if (!empty($p['onSale']) && (int) ($p['compareAt'] ?? 0) > (int) $p['price']) {
            $price .= ' (قبل از تخفیف ' . $toman((int) $p['compareAt']) . ')';
        }
        $bits[] = $price;
        $bits[] = !empty($p['inStock']) ? 'موجود' : 'ناموجود';
        $short = $line((string) ($p['short'] ?? ''));
        $out[] = '- [' . $name . '](' . seo_product_url((string) $p['slug']) . '): '
               . implode(' · ', $bits) . ($short !== '' ? ' — ' . $short : '');
    }
    $out[] = '';
}

$pages = pages_all();
if ($pages) {
    $out[] = '## خرید، ارسال و مرجوعی';
    $out[] = '';
    foreach ($pages as $pg) {
        $out[] = '- [' . $pg['title'] . '](' . $origin . '/' . page_url((string) $pg['slug']) . ')'
               . ((string) ($pg['summary'] ?? '') !== '' ? ': ' . $line((string) $pg['summary']) : '');
    }
    $out[] = '';
}

$articles = journal_all();
if ($articles) {
    $out[] = '## مجله';
    $out[] = '';
    foreach ($articles as $a) {
        $out[] = '- [' . $a['title'] . '](' . $origin . '/article.php?a=' . rawurlencode((string) $a['slug']) . ')'
               . ((string) $a['summary'] !== '' ? ': ' . $line((string) $a['summary']) : '');
    }
    $out[] = '';
}

$contact = (array) ($cfg['contact'] ?? []);
$names = ['telegram' => 'تلگرام', 'bale' => 'بله', 'whatsapp' => 'واتس‌اپ',
          'instagram' => 'اینستاگرام', 'aparat' => 'آپارات', 'email' => 'ایمیل', 'phone' => 'تلفن'];
$rows = [];
foreach ($names as $key => $label) {
    if (!empty($contact[$key])) {
        $rows[] = '- ' . $label . ': ' . $contact[$key];
    }
}
if ($rows) {
    $out[] = '## تماس';
    $out[] = '';
    array_push($out, ...$rows);
    $out[] = '';
}

$out[] = '## Optional';
$out[] = '';
$out[] = '- [نقشهٔ سایت](' . $origin . '/sitemap.xml): همهٔ صفحه‌ها، برای خزنده‌ها';

echo implode("\n", $out), "\n";
