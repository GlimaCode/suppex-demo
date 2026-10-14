<?php
/* ===========================================================================
   SUPPEX — Page metadata
   ---------------------------------------------------------------------------
   Builds the <head> for a product page from the product actually being viewed.

   Why this exists at all: the storefront is static HTML hydrated by JavaScript,
   and product.html carries ONE hardcoded title, description, og:image and
   JSON-LD. Every product therefore shared the metadata of whichever product
   happened to be written into the file. Search engines see a catalogue of
   identical pages, which is bad — but the sharper problem is that this shop
   sells through Telegram and Instagram, where every link a customer or the
   seller pastes into a chat renders a preview card. Those preview bots read
   the og: tags and DO NOT run JavaScript, so a link to the creatine would show
   the whey's name, description and picture. The channel the business actually
   runs on would misrepresent every product but one.

   Server-rendering just the head fixes that without touching the rest of the
   page: the body stays exactly the static markup the JS already hydrates.
   =========================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/ogimage.php';
require_once __DIR__ . '/assets.php';

/** Absolute site origin, e.g. https://example.ir — derived, never hardcoded. */
function seo_origin(): string
{
    $configured = trim((string) setting('site_url', ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    /* Behind cPanel's proxy the scheme is often only in X-Forwarded-Proto.
       HTTPS alone reports "off" and the canonical would announce http:// on a
       site that redirects to https, which is a redirect chain for every
       crawler. */
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    /* Host comes from the request, so it is attacker-controlled. Anything that
       is not a plausible hostname is dropped rather than reflected into a
       canonical tag. */
    if (!preg_match('/^[a-zA-Z0-9.\-]+(:\d+)?$/', $host)) {
        $host = 'localhost';
    }

    return ($https ? 'https://' : 'http://') . $host;
}

/** Canonical URL for one product. */
function seo_product_url(string $slug): string
{
    return seo_origin() . '/product.php?p=' . rawurlencode($slug);
}

/** Turn a relative asset path into an absolute one. Preview bots require it. */
function seo_absolute(string $path): string
{
    if ($path === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }
    return seo_origin() . '/' . ltrim($path, '/');
}

/**
 * The shared picture a link shows when its page has none of its own - the
 * home page, the brands, the guides - which Telegram and WhatsApp draw in the
 * chat. Redrawn 2026-10-05 with the shop's own photographs and the new logo,
 * as a JPEG: a PNG of photographs runs past the ~300 KB WhatsApp will still
 * preview. Versioned like the stylesheet, so a new cover is a new address and
 * a preview bot cannot go on showing the old one.
 */
function seo_cover(): string
{
    return seo_absolute(asset('assets/images/og-cover.jpg'));
}

/**
 * Trim to a length that survives a search snippet without ending mid-word.
 * Cut on a space so the result reads as a sentence rather than as truncation.
 */
/**
 * The canonical URL for a category page.
 *
 * ?c=slug rather than a path. Shared cPanel hosting cannot be assumed to have
 * mod_rewrite, and Google's own ecommerce URL guidance recommends exactly this
 * shape - "use ?key=value URL parameters" - so this is the correct answer here
 * rather than a compromise. One function, so the canonical, the sitemap, the
 * breadcrumb and the footer cannot drift apart.
 */
function seo_category_url(string $slug): string
{
    return seo_origin() . '/category.php?c=' . rawurlencode($slug);
}

function seo_clip(string $text, int $max = 155): string
{
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    $cut = mb_substr($text, 0, $max);
    $sp  = mb_strrpos($cut, ' ');
    $tail = $sp !== false && $sp > $max * 0.6 ? mb_substr($cut, 0, $sp) : $cut;
    /* preg_replace with /u, not rtrim. rtrim's character list is a set of
       BYTES: ی is DB 8C and «،» is D8 8C, so trimming «،» stripped the 8C off a
       trailing ی and left a dangling DB. The result failed mb_check_encoding,
       showed a U+FFFD in the meta description, and - worse - made json_encode
       return false at :174 so the product's whole JSON-LD vanished silently. */
    return preg_replace('/[\s،.]+$/u', '', $tail) . '…';
}

/**
 * The whole <head> metadata block for a product.
 *
 * @return array{title:string,description:string,image:string,url:string,jsonld:string}
 */
function seo_product_meta(?array $product): array
{
    $shop = (string) setting('shop_name', 'SUPPEX');

    if ($product === null) {
        return [
            'title'       => 'محصول پیدا نشد | ' . $shop,
            'description' => '',
            'image'       => seo_cover(),
            'url'         => seo_origin() . '/product.php',
            'jsonld'      => '',
            'robots'      => 'noindex, follow',
        ];
    }

    $name  = (string) $product['nameFa'];
    $latin = trim((string) ($product['name'] ?? ''));

    $desc = seo_clip((string) ($product['short'] ?: $product['description'] ?? ''));
    if ($desc === '') {
        $desc = $name . ' — ' . $shop;
    }

    /* A card drawn for this product, not the brand cover.

       Preview bots do not rasterise SVG, so a vector placeholder produced a
       card with an empty picture — and every product fell back to the same
       cover, so fifteen different links produced fifteen identical previews.
       For a shop whose distribution IS Telegram, the picture on that card is
       the product listing.

       og.php draws the name, the price and the saving over the photograph when
       there is one, and over type when there is not. The URL carries a
       fingerprint of everything it shows, because WhatsApp caches a preview
       per URL with no way to invalidate it: a card whose price moved has to BE
       a different URL, or the old one is what people keep seeing. */
    $image = seo_absolute(og_url($product));

    /* --- JSON-LD ---------------------------------------------------------
       Deliberately NO aggregateRating or review. Rating markup that does not
       correspond to reviews visible on the page is a manual-action risk with
       Google, and this shop has no reviews yet. The storefront already keeps
       ratings behind a flag for the same reason — inventing social proof is
       the one placeholder that costs more than it saves. */
    /* Prices are stored in TOMAN, and schema.org wants an ISO 4217 code.
       Iran's only ISO code is IRR (rial), and one toman is ten rial — so the
       stored number has to be multiplied, not relabelled. Publishing the toman
       figure as IRR advertises every product at a tenth of its price, which is
       worse than publishing nothing: price-comparison engines rank on it.

       "IRT" and "TOMAN" are not ISO 4217 codes. Google rejects them outright,
       so relabelling is not an available shortcut. The visible page keeps
       showing toman, which is what customers actually read. */
    $offer = [
        '@type'         => 'Offer',
        'url'           => seo_product_url((string) $product['slug']),
        'price'         => (string) ((int) $product['price'] * 10),
        'priceCurrency' => 'IRR',
        'availability'  => !empty($product['inStock'])
            ? 'https://schema.org/InStock'
            : 'https://schema.org/OutOfStock',
        /* Sealed and unopened is what the shop sells and says on every page. */
        'itemCondition' => 'https://schema.org/NewCondition',
        'seller'        => ['@type' => 'Organization', 'name' => $shop],
    ];

    /* One Offer at the card's price, even where sizes or combinations are
       priced apart: that is the price the page shows first, and a plain
       Offer is what Google's merchant listings accept - an AggregateOffer
       range would keep the product out of them. */

    /* A breadcrumb, which this page has never had. Of the structured data a
       listing or a product page can carry, BreadcrumbList is the one Google
       actually consumes and displays - and a product that knows its category
       can state the trail truthfully. Emitted as a @graph beside the Product
       rather than nested, so neither has to be valid inside the other. */
    $crumbs = [
        ['@type' => 'ListItem', 'position' => 1, 'name' => $shop,
         'item' => seo_origin() . '/'],
    ];
    if (!empty($product['category'])) {
        $catName = (string) db_value(
            'SELECT name_fa FROM categories WHERE slug = ? AND is_active = 1',
            [(string) $product['category']]
        );
        if ($catName !== '') {
            $crumbs[] = ['@type' => 'ListItem', 'position' => 2, 'name' => $catName,
                         'item' => seo_category_url((string) $product['category'])];
        }
    }
    $crumbs[] = ['@type' => 'ListItem', 'position' => count($crumbs) + 1,
                 'name' => $name, 'item' => seo_product_url((string) $product['slug'])];

    /* The product's own photographs, not the share card: the card carries
       the price and the name drawn over the picture, which is what a Telegram
       preview needs and what Google's product results reject - they want the
       product itself. The card stands in only when there is no photograph. */
    $photos = [];
    foreach ((array) ($product['gallery'] ?? []) as $g) {
        $g = trim((string) $g);
        if ($g !== '' && !str_ends_with(strtolower($g), '.svg')) {
            $photos[] = seo_absolute($g);
        }
    }
    $photos = array_slice(array_values(array_unique($photos)), 0, 8) ?: [$image];

    $ld = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Product',
        'name'        => $latin !== '' ? $name . ' ' . $latin : $name,
        'description' => $desc,
        'sku'         => (string) $product['slug'],
        'url'         => seo_product_url((string) $product['slug']),
        'image'       => $photos,
        'offers'      => $offer,
    ];
    if (!empty($product['brand'])) {
        $ld['brand'] = ['@type' => 'Brand', 'name' => (string) $product['brand']];
    }
    if (isset($catName) && $catName !== '') {
        $ld['category'] = $catName;
    }
    /* Ratings from approved reviews by buyers (lib/reviews.php), and none at
       all until there is one. Only reviews signed with a name go into the
       list: Google does not take «خریدار» as an author, and it still counts
       in the average. */
    $pid = (int) ($product['id'] ?? 0);
    $sum = review_summaries([$pid])[$pid] ?? null;
    if ($sum !== null && $sum['count'] > 0) {
        $ld['aggregateRating'] = [
            '@type'       => 'AggregateRating',
            'ratingValue' => (string) $sum['avg'],
            'reviewCount' => $sum['count'],
            'bestRating'  => '5',
            'worstRating' => '1',
        ];
        $named = array_values(array_filter(reviews_for_product($pid, 20),
            static fn(array $r): bool => trim((string) $r['author']) !== ''));
        if ($named) {
            $ld['review'] = array_map(static fn(array $r): array => [
                '@type'         => 'Review',
                'reviewRating'  => ['@type' => 'Rating', 'ratingValue' => (string) (int) $r['rating'], 'bestRating' => '5'],
                'author'        => ['@type' => 'Person', 'name' => (string) $r['author']],
                'datePublished' => date('Y-m-d', (int) strtotime((string) $r['created_at'])),
                'reviewBody'    => (string) $r['body'],
            ], array_slice($named, 0, 5));
        }
    }

    return [
        'title'       => $name . ($latin !== '' ? ' ' . $latin : '') . ' | ' . $shop,
        'description' => $desc,
        'image'       => $image,
        'url'         => seo_product_url((string) $product['slug']),
        'robots'      => 'index, follow',
        /* JSON_HEX_TAG: reviews put buyers' words in here, and «</script>» in
           one would close the element and run what follows it. */
        'jsonld'      => json_encode([
            '@context' => 'https://schema.org',
            '@graph'   => [
                $ld,
                ['@type' => 'BreadcrumbList', 'itemListElement' => $crumbs],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG) ?: '',
    ];
}
