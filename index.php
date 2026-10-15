<?php
/* ===========================================================================
   SUPPEX — the home page, with its content in it
   ---------------------------------------------------------------------------
   index.html is the page, and stays the page: it is the static demo, and the
   design lives there. This file serves it with what a static file cannot have.

   Its grids - the categories, the featured products, the journal, the logo
   wall - are empty in index.html and were filled by app.js from four API
   calls. Measured, that meant:

     - Google's renderer saw a home page with no products. PageSpeed runs on
       Google's own machines, and four of those calls timed out there: this
       host runs only a few PHP requests at once, and five fired together came
       back after as long as nineteen seconds.
     - The AI crawlers, and Torob and Emalls, which read the HTML without
       running a script, saw the same page with nothing to buy on it and not
       one link to a product.

   So the grids arrive filled - plain cards, the same ones category.php draws,
   which app.js then replaces with the full ones exactly as before - and the
   answers to the script's calls come with the page (lib/boot.php), so it asks
   the network for nothing. The head gets the shared assets (fonts preloaded,
   one stylesheet), an absolute og:image - the relative one was unusable to
   every preview bot - and the featured products as an ItemList.

   Each change finds its place by an exact marker in index.html. A marker that
   has moved is left alone and logged, so an edit to index.html can cost this
   page an improvement but never its content. tests/test-speed.php renders this
   file and checks every marker is still found.

   .htaccess serves this file for «/» (DirectoryIndex) and sends /index.html
   here with a 301, so there is one address for the home page.
   =========================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/products.php';
require_once __DIR__ . '/lib/journal.php';
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/assets.php';
require_once __DIR__ . '/lib/seo.php';
require_once __DIR__ . '/lib/goals.php';

/* The product grid's first page, and each «نمایش بیشتر» after it - the same
   number as app.js's HOME_PRODUCTS, which asks for it from the page's boot. */
const HOME_PRODUCTS = 12;

$html   = (string) file_get_contents(__DIR__ . '/index.html');
$origin = seo_origin();
$missed = [];

/** Replace one exact marker, once; note it when it is not there. */
$put = static function (string $marker, string $with, string $label) use (&$html, &$missed): void {
    $at = strpos($html, $marker);
    if ($at === false) {
        $missed[] = $label;
        return;
    }
    $html = substr($html, 0, $at) . $with . substr($html, $at + strlen($marker));
};

/* --- the content ---------------------------------------------------------- */
$put('<span data-shipping-announcement>ارسال رایگان از <span class="num">۱,۵۰۰,۰۰۰</span> تومان</span>',
     '<span data-shipping-announcement>' . shipping_announcement_html() . '</span>', 'shipping announcement');
$categories = [];
$featured   = [];
$deals      = [];
$brands     = [];
$articles   = [];
try {
    $categories = categories_list();
    $featured   = products_list(['limit' => HOME_PRODUCTS]);
    $deals      = products_list(['badge' => 'topdeal']);
    $brands     = array_map('brand_api', brands_list());
} catch (Throwable $e) {
    error_log('[suppex] home: ' . $e->getMessage());
}
$articles = journal_all();

/* The hero's deals, chosen as app.js chooses them, and the first 24 only when
   the script would ask for them - when nothing ticked can be sold. */
$picks   = [];
$first24 = null;
try {
    $picks = hero_deal_picks($deals, []);
    if (!$picks) {
        $first24 = products_list(['limit' => 24]);
        $picks   = hero_deal_picks([], $first24);
    }
} catch (Throwable $e) {
    error_log('[suppex] home deals: ' . $e->getMessage());
}

$icon = static fn(string $path): string =>
    '<svg class="" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor"'
    . ' stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"'
    . ' focusable="false">' . $path . '</svg>';

/* The same cards app.js draws (ui.categoryCard, ui.journalCard), without the
   reveal class: these must be visible to a reader whose script never runs. */
/* A category with nothing on sale is not offered as a way in: «۰ محصول» is a
   dead end with a picture on it. The same rule as app.js (shopCategories()). */
$onSale   = array_values(array_filter($categories, static fn(array $c): bool => (int) ($c['count'] ?? 0) > 0));
$catCards = '';
foreach ($onSale as $c) {
    $id = (string) $c['id'];
    /* A real photograph from the category's shelf where there is one, on the
       category's own colour; the drawn tub otherwise. ui.categoryCard() draws
       the same tile. */
    $photo = (string) ($c['photo'] ?? '');
    $art   = $photo !== ''
        ? '<span class="media media--4x5 ccard__art" style="border-radius:0;display:block" data-cat="' . e($id) . '">'
          . '<img class="ccard__photo" src="' . e($photo) . '"'
          . image_srcset_attr($photo, '(min-width: 980px) 210px, (min-width: 700px) 30vw, 46vw')
          . ' alt="دسته‌بندی ' . e((string) $c['name']) . '" loading="lazy" decoding="async" width="320" height="400"></span>'
        : '<span class="media media--4x5" style="border-radius:0;display:block">'
          . '<img src="' . e((string) ($c['image'] ?? '')) . '" alt="دسته‌بندی ' . e((string) $c['name'])
          . '" loading="lazy" width="320" height="400"></span>';
    $catCards .= '<a class="ccard' . ($photo !== '' ? ' ccard--photo' : '') . '" href="category.php?c=' . e(rawurlencode($id)) . '" data-category="' . e($id) . '">'
        . $art
        . '<span class="ccard__label"><span><span class="ccard__name">' . e((string) $c['name']) . '</span><br>'
        . '<span class="ccard__count"><span class="num">' . (int) ($c['count'] ?? 0) . '</span>&nbsp;محصول</span>'
        . '</span><span class="ccard__go">' . $icon('<path d="M5 12h14M13 6l6 6-6 6"/>') . '</span></span></a>';
}

$chips = '<button type="button" class="chip is-active" data-filter="">همه محصولات</button>';
foreach ($onSale as $c) {
    $chips .= '<button type="button" class="chip" data-filter="' . e((string) $c['id']) . '">'
        . e((string) $c['name']) . '</button>';
}

/* category.php's plain card: a link, a picture, a name, a price. */
$prodCards = '';
foreach ($featured as $p) {
    $href = 'product.php?p=' . rawurlencode((string) $p['slug']);
    $prodCards .= '<article class="pcard">'
        . '<a class="media media--1x1 media--lit" href="' . e($href) . '">'
        . '<img src="' . e(product_image_or_placeholder((string) $p['image'])) . '"'
        . image_srcset_attr((string) $p['image'], '(min-width: 1100px) 270px, (min-width: 700px) 31vw, 47vw')
        . ' alt="' . e((string) $p['nameFa']) . '" loading="lazy" decoding="async" width="400" height="400"></a>'
        . '<div class="pcard__body"><div class="pcard__brand lat">' . e((string) $p['brand']) . '</div>'
        . '<h3 class="pcard__title"><a href="' . e($href) . '">' . e((string) $p['nameFa']) . '</a></h3>'
        . (($fact = product_key_fact($p)) !== '' ? '<p class="pcard__fact">' . e($fact) . '</p>' : '')
        . '<div class="pcard__foot"><span class="price"><span class="price__now"><span class="num">'
        . money_fa((int) $p['price']) . '</span>&nbsp;' . price_unit_html() . '</span></span></div></div>'
        . '</article>';
}

$journalCards = '';
foreach (array_slice($articles, 0, 3) as $a) {
    $journalCards .= '<a class="pcard" href="article.php?a=' . e(rawurlencode((string) $a['slug'])) . '">'
        . '<div class="media media--16x10"><img src="' . e((string) $a['image'])
        . '" alt="" loading="lazy" width="480" height="300"></div>'
        . '<div class="pcard__body"><div class="row" style="gap:8px">'
        . '<span class="u-eyebrow u-eyebrow--bare">' . e((string) $a['category']) . '</span>'
        . '<span class="u-xs u-dim"><span class="num">' . (int) $a['readTime'] . '</span> دقیقه مطالعه</span></div>'
        . '<h3 class="u-h3" style="font-size:1.15rem;line-height:1.5">' . e((string) $a['title']) . '</h3>'
        . '</div></a>';
}

/* «هدف شما چیست؟»: the goals with something on sale (lib/goals.php). */
$goalCards = '';
foreach (goals_live($categories) as $g) {
    $goalCards .= goal_card_html($g);
}

/* The brands, in the band under the hero (lib/brands.php). */
$brandStrip = '';
try {
    $brandStrip = brand_strip_html(brands_list());
} catch (Throwable $e) {
    error_log('[suppex] home brands: ' . $e->getMessage());
}

/* How many there are in all, for «۱۲ از ۴۰ محصول» under the grid. */
$totalProducts = 0;
try {
    $totalProducts = (int) db_value('SELECT COUNT(*) FROM products WHERE is_active = 1');
} catch (Throwable $e) {
    error_log('[suppex] home count: ' . $e->getMessage());
}

/* --- the head ---------------------------------------------------------------- */
ob_start();
include __DIR__ . '/partials/head-assets.php';
$headAssets = trim((string) ob_get_clean());

$html = (string) preg_replace(
    '~<link rel="icon" href="assets/icons/favicon\.svg" type="image/svg\+xml">\s*'
    . '<link rel="stylesheet" href="assets/css/tokens\.css">\s*<link rel="stylesheet" href="assets/css/base\.css">\s*'
    . '<link rel="stylesheet" href="assets/css/components\.css">\s*<link rel="stylesheet" href="assets/css/layout\.css">~',
    str_replace(['\\', '$'], ['\\\\', '\\$'], $headAssets), $html, 1, $headCount);
if ($headCount !== 1) {
    $missed[] = 'head assets';
}

$put('<link rel="canonical" href="https://suppex.ir/">',
     '<link rel="canonical" href="' . e($origin . '/') . '">', 'canonical');
$put('<meta property="og:image" content="assets/images/og-cover.png">',
     '<meta property="og:url" content="' . e($origin . '/') . '">' . "\n"
     . '<meta property="og:image" content="' . e(seo_cover()) . '">', 'og:image');

/* The brand's own profiles, from the contact settings in the panel.

   index.html was written with two handles that have since gone stale, and its
   list never named the Instagram page - @suppex__, which Google already shows
   for «suppex مکمل». sameAs is how a search engine ties the name on those
   profiles to this site, so it has to follow the panel, not the day the
   template was written. Profiles only: a phone number, an email address or a
   WhatsApp chat is a way to reach the shop, not a page about it. */
$profiles = [];
foreach (['instagram_url', 'telegram_url', 'bale_url', 'aparat_url'] as $key) {
    $url = trim((string) setting($key));
    if ($url !== '' && preg_match('~^https?://~i', $url) && !in_array($url, $profiles, true)) {
        $profiles[] = $url;
    }
}
$html = (string) preg_replace_callback('~"sameAs":\s*\[[^\]]*\]~',
    static fn(): string => '"sameAs": ' . json_encode($profiles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    $html, 1, $sameAsCount);
if ($sameAsCount !== 1) {
    $missed[] = 'organization sameAs';
}

/* The products a visitor sees first, as a list a search engine can read. */
if ($featured) {
    $items = [];
    foreach ($featured as $i => $p) {
        $items[] = ['@type' => 'ListItem', 'position' => $i + 1,
                    'url' => seo_product_url((string) $p['slug']),
                    'name' => trim((string) $p['nameFa'] . ' ' . (string) ($p['name'] ?? ''))];
    }
    $list = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'ItemList',
        'name' => 'محصولات پرطرفدار',
        'itemListElement' => $items,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    if ($list !== false) {
        $put('</head>', '<script type="application/ld+json">' . $list . "</script>\n</head>", 'item list');
    }
}

/* --- the grids ------------------------------------------------------------ */
if ($goalCards !== '') {
    $put('data-goals-section hidden>', 'data-goals-section>', 'goals section');
    /* The bento's shape follows how many goals have something on sale. */
    $put('<div class="goals" data-goals></div>', '<div class="goals" data-goals data-count="'
         . count(goals_live($categories)) . '">' . $goalCards . '</div>', 'goals');
    /* «از کجا شروع کنم؟» is answered by a goal better than by a category. */
    $put('<a class="btn btn--ghost" href="#categories">از کجا شروع کنم؟</a>',
         '<a class="btn btn--ghost" href="#goals">از کجا شروع کنم؟</a>', 'hero: to the goals');
}
$put('<div class="grid grid--cats" data-categories></div>',
     '<div class="grid grid--cats" data-categories>' . $catCards . '</div>', 'categories');
$put('<div class="row" style="gap:8px;margin-bottom:28px" data-filters role="group" aria-label="فیلتر دسته‌بندی"></div>',
     '<div class="row" style="gap:8px;margin-bottom:28px" data-filters role="group" aria-label="فیلتر دسته‌بندی">'
     . $chips . '</div>', 'filters');
$put('<div class="grid grid--products" data-products></div>',
     '<div class="grid grid--products" data-products>' . $prodCards . '</div>', 'products');
$put('<div class="grid grid--3" data-journal></div>',
     '<div class="grid grid--3" data-journal>' . $journalCards . '</div>', 'journal');
/* The deals box, drawn as app.js draws it (lib/products.php), so it has its
   size from the first paint: PageSpeed measured the page's one layout shift
   as this box growing from the poster's square into the deals. The poster
   underneath is hidden by .has-deals, so it is not fetched - lazy - rather
   than fetched first. The bars start paused; app.js starts the clock. */
if ($picks) {
    $slides = '';
    foreach ($picks as $i => $p) {
        $slide = hero_deal_slide($p, $i, count($picks));
        /* The first photograph is what the eye lands on; the others wait. */
        $slides .= str_replace(' decoding="async">', ' decoding="async" fetchpriority="' . ($i === 0 ? 'high' : 'low') . '">', $slide);
    }
    $put('<div class="stage-wrap" data-deals-wrap>', '<div class="stage-wrap has-deals" data-deals-wrap>', 'deals: frame');
    $put('width="520" height="560" fetchpriority="high">', 'width="520" height="560" loading="lazy">', 'deals: poster');
    $put('<div class="deals" data-deals role="region" aria-roledescription="اسلایدر" aria-labelledby="deals-title" hidden>',
         '<div class="deals is-paused" data-deals role="region" aria-roledescription="اسلایدر" aria-labelledby="deals-title">',
         'deals: box');
    $put('<div class="deals__slides" data-deals-row aria-live="off"></div>',
         '<div class="deals__slides" data-deals-row aria-live="off" data-drawn="'
         . e(implode(',', array_map(static fn(array $p): string => (string) $p['slug'], $picks))) . '">' . $slides . '</div>',
         'deals: slides');
    if (count($picks) > 1) {
        $put('data-deals-toggle aria-label="توقف نمایش خودکار" hidden>', 'data-deals-toggle aria-label="توقف نمایش خودکار">', 'deals: pause');
        $put('<div class="deals__pips" data-deals-pips hidden></div>',
             '<div class="deals__pips" data-deals-pips>' . hero_deal_pips($picks) . '</div>', 'deals: bars');
    }
}

if ($brandStrip !== '') {
    $from = strpos($html, '<!-- brandstrip -->');
    $to   = strpos($html, '<!-- /brandstrip -->');
    if ($from !== false && $to !== false && $to > $from) {
        $html = substr($html, 0, $from) . $brandStrip . substr($html, $to + strlen('<!-- /brandstrip -->'));
    } else {
        $missed[] = 'brand strip';
    }
}
$put('<div class="more" data-more hidden>',
     '<div class="more" data-more data-total="' . $totalProducts . '"' . ($totalProducts > count($featured) ? '' : ' hidden') . '>',
     'more: box');
$put('<p class="more__note" data-more-note aria-live="polite"></p>',
     '<p class="more__note" data-more-note aria-live="polite">' . fa_digits((string) count($featured)) . ' از '
     . fa_digits((string) $totalProducts) . ' محصول</p>', 'more: count');

/* The promo names whey, creatine and a multivitamin: a photograph from each of
   those three shelves stands beside it, each a link to its shelf - only the
   shelves with something on them. */
$stack = '';
foreach (['protein', 'creatine', 'vitamins'] as $want) {
    foreach ($onSale as $c) {
        $photo = (string) ($c['photo'] ?? '');
        if ((string) $c['id'] === $want && $photo !== '') {
            $stack .= '<a class="promo__pick" href="category.php?c=' . e(rawurlencode($want)) . '">'
                . '<img src="' . e($photo) . '"' . image_srcset_attr($photo, '(min-width: 860px) 220px, 32vw')
                . ' alt="" loading="lazy" decoding="async" width="260" height="260">'
                . '<span class="promo__pick-name">' . e((string) $c['name']) . '</span></a>';
        }
    }
}
if ($stack !== '') {
    $put('<div class="promo__stack" data-promo-stack></div>',
         '<div class="promo__stack" data-promo-stack>' . $stack . '</div>', 'promo: stack');
}

/* --- the menu's two lists (lib/goals.php) --------------------------------------
   Empty markers in index.html, filled with what the other pages' menu shows
   (partials/chrome-top.php): the goals with something on sale, the guides.
   «همه هدف‌ها» is the section on this very page. */
$menuGoals  = nav_goal_items(goals_live($categories), '');
$menuGuides = nav_guide_items($categories);
foreach ([
    '<!-- nav:goals --><!-- /nav:goals -->'     => nav_dropdown_html('nav-goals', 'هدف‌ها', $menuGoals),
    '<!-- nav:guides --><!-- /nav:guides -->'   => nav_dropdown_html('nav-guides', 'راهنماها', $menuGuides),
    '<!-- panel:goals --><!-- /panel:goals -->' => nav_panel_group_html('هدف‌ها', $menuGoals),
    '<!-- panel:guides --><!-- /panel:guides -->' => nav_panel_group_html('راهنماها', $menuGuides),
] as $marker => $with) {
    $put($marker, $with, 'menu ' . $marker);
}

/* --- one address for the home page ------------------------------------------ */
$html = str_replace('href="index.html"', 'href="./"', $html);

/* --- the scripts, and what they are about to ask for ---------------------------- */
$bootRequests = [
    ['categories', [], $categories],
    ['products', ['limit' => HOME_PRODUCTS], $featured],
    ['products', ['badge' => 'topdeal'], $deals],
    ['brands', [], $brands],
];
/* app.js falls back to the biggest discounts among 24 when nothing ticked
   can be sold; only then is that list worth sending - already fetched above. */
if ($first24 !== null) {
    $bootRequests[] = ['products', ['limit' => 24], $first24];
}
$scripts = boot_script($bootRequests) . "\n";
foreach (['config', 'catalog', 'repo', 'store', 'ui'] as $script) {
    $scripts .= '<script src="' . e(asset('assets/js/' . $script . '.js')) . '"></script>' . "\n";
}
$html = (string) preg_replace(
    '~<script src="assets/js/config\.js\?v=\d+"></script>\s*<script src="assets/js/catalog\.js\?v=\d+"></script>\s*'
    . '<script src="assets/js/repo\.js\?v=\d+"></script>\s*<script src="assets/js/store\.js\?v=\d+"></script>\s*'
    . '<script src="assets/js/ui\.js\?v=\d+"></script>\s*~',
    str_replace(['\\', '$'], ['\\\\', '\\$'], $scripts), $html, 1, $scriptCount);
if ($scriptCount !== 1) {
    $missed[] = 'scripts';
}
$html = (string) preg_replace('~<script src="assets/js/app\.js\?v=\d+"></script>~',
    '<script src="' . e(asset('assets/js/app.js')) . '"></script>', $html, 1, $appCount);
if ($appCount !== 1) {
    $missed[] = 'app.js';
}

if ($missed) {
    error_log('[suppex] index.php: markers not found in index.html: ' . implode(', ', $missed));
}

header('Content-Type: text/html; charset=utf-8');
echo $html;
