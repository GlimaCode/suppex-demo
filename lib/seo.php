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
 * Trim to a length that survives a search snippet without ending mid-word.
 * Cut on a space so the result reads as a sentence rather than as truncation.
 */
function seo_clip(string $text, int $max = 155): string
{
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    $cut = mb_substr($text, 0, $max);
    $sp  = mb_strrpos($cut, ' ');
    return rtrim($sp !== false && $sp > $max * 0.6 ? mb_substr($cut, 0, $sp) : $cut, ' ،.') . '…';
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
            'image'       => seo_absolute('assets/images/og-cover.png'),
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

    /* Preview bots do not rasterise SVG. Telegram, WhatsApp and Instagram all
       fetch og:image and render it as a bitmap or not at all, so a vector
       placeholder produces a card with an empty picture — which looks worse
       than a card with a generic one. The brand cover is the honest fallback
       until a real photograph is uploaded.

       This matters more than it sounds because WhatsApp caches a preview per
       URL with no way to invalidate it: a link shared while the image is
       broken keeps showing the broken card on that device. */
    $raw   = (string) ($product['image'] ?? '');
    $isSvg = $raw !== '' && preg_match('~\.svgz?($|\?)~i', $raw) === 1;

    $image = ($raw === '' || $isSvg)
        ? seo_absolute('assets/images/og-cover.png')
        : seo_absolute($raw);

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
        'seller'        => ['@type' => 'Organization', 'name' => $shop],
    ];

    $ld = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Product',
        'name'        => $latin !== '' ? $name . ' ' . $latin : $name,
        'description' => $desc,
        'sku'         => (string) $product['slug'],
        'image'       => [$image],
        'offers'      => $offer,
    ];
    if (!empty($product['brand'])) {
        $ld['brand'] = ['@type' => 'Brand', 'name' => (string) $product['brand']];
    }

    return [
        'title'       => $name . ($latin !== '' ? ' ' . $latin : '') . ' | ' . $shop,
        'description' => $desc,
        'image'       => $image,
        'url'         => seo_product_url((string) $product['slug']),
        'robots'      => 'index, follow',
        'jsonld'      => json_encode($ld,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '',
    ];
}
