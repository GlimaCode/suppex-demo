<?php
/* ===========================================================================
   SUPPEX — social preview cards
   ---------------------------------------------------------------------------
   A shop that sells through Telegram lives on link previews. Until now every
   product shared the same og:image — the brand cover — so fifteen different
   links produced fifteen identical cards, and the picture said nothing about
   what was being sent.

   This draws a card per product: the photo if there is one, the name in
   Persian, the price, and the saving when there is a real one.

   Three decisions.

   RENDERED ON DEMAND, NOT ON UPLOAD. A card baked when the photo was saved
   would carry whatever the price was that day, and this shop's prices move
   with the dirham. Generated from the current row and cached against a
   fingerprint of it instead.

   THE URL CARRIES THE FINGERPRINT. WhatsApp caches a preview per URL with no
   way to invalidate it, so a card whose price changed must be a new URL or the
   old one is what people keep seeing.

   THE PERSIAN IS SHAPED FIRST. GD draws glyphs and does no joining, so an
   unshaped name renders as disconnected letters in the wrong order — see
   lib/arabic.php.
   =========================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/arabic.php';
require_once __DIR__ . '/images.php';

const OG_W = 1200;
const OG_H = 630;

/** Where rendered cards are kept. Under uploads/, which is already writable. */
function og_cache_dir(): string
{
    return uploads_dir() . '/og';
}

function og_font(bool $bold = true): string
{
    return SUPPEX_ROOT . '/assets/fonts/Vazirmatn-' . ($bold ? 'Bold' : 'Regular') . '.ttf';
}

/**
 * A short hash of everything the card shows.
 *
 * Anything that changes the picture must change this, or a stale card lives on
 * in every preview cache that has already seen it.
 */
function og_fingerprint(array $product): string
{
    $img  = (string) ($product['image'] ?? '');
    $file = og_local_path($img);

    return substr(sha1(implode('|', [
        (string) ($product['nameFa'] ?? ''),
        (string) ($product['name'] ?? ''),
        (string) ($product['brand'] ?? ''),
        (string) (int) ($product['price'] ?? 0),
        (string) (int) ($product['compareAt'] ?? 0),
        $img,
        /* The photo can be replaced without its path changing. */
        $file !== null && is_file($file) ? (string) filemtime($file) : '',
        /* Bump when the drawing changes, so old cards are not served. */
        'v2',
    ])), 0, 12);
}

/** The URL a preview bot should fetch. */
function og_url(array $product): string
{
    $slug = (string) ($product['slug'] ?? '');
    return 'og.php?p=' . rawurlencode($slug) . '&v=' . og_fingerprint($product);
}

/**
 * Turn a stored image path into a path on disk, or null.
 *
 * Stored paths are either '/uploads/...' (uploaded) or 'assets/...' (seeded),
 * and only the first is a photograph. SVG is skipped: it is a placeholder, and
 * GD cannot read it anyway.
 */
function og_local_path(string $stored): ?string
{
    $stored = trim($stored);
    if ($stored === '' || preg_match('~\.svgz?($|\?)~i', $stored) === 1) {
        return null;
    }

    /* Before anything else. The uploads branch below used to return early, so
       a '..' inside a path that started with the uploads prefix was never
       looked at. */
    if (strpos($stored, '..') !== false || strpos($stored, "\0") !== false) {
        return null;
    }

    $url = uploads_url();
    $path = strpos($stored, $url . '/') === 0
        ? uploads_dir() . substr($stored, strlen($url))
        : SUPPEX_ROOT . '/' . ltrim($stored, '/');

    if (!is_file($path)) {
        return null;
    }

    /* And again against what the filesystem says, which is the only check that
       sees through symlinks. An image may live in exactly two places. */
    $real = realpath($path);
    if ($real === false) {
        return null;
    }
    foreach ([realpath(uploads_dir()), realpath(SUPPEX_ROOT)] as $base) {
        if ($base !== false && strpos($real, $base . DIRECTORY_SEPARATOR) === 0) {
            return $path;
        }
    }
    return null;
}

/**
 * Render the card. Returns PNG bytes.
 */
function og_render(array $product): string
{
    $im = imagecreatetruecolor(OG_W, OG_H);
    imagesavealpha($im, false);

    $ink     = imagecolorallocate($im, 242, 237, 227);
    $muted   = imagecolorallocate($im, 150, 144, 134);
    $accent  = imagecolorallocate($im, 242, 89, 20);

    /* A vertical wash rather than a flat fill: a flat dark rectangle reads as
       a failed image in a Telegram card, and the gradient makes it obviously
       deliberate. */
    for ($y = 0; $y < OG_H; $y++) {
        $t = $y / OG_H;
        $c = imagecolorallocate($im,
            (int) (19 + 8 * $t), (int) (18 + 7 * $t), (int) (16 + 6 * $t));
        imageline($im, 0, $y, OG_W, $y, $c);
    }

    /* A copper band down the right edge — the reading side in an RTL layout. */
    imagefilledrectangle($im, OG_W - 12, 0, OG_W, OG_H, $accent);

    $photo = og_local_path((string) ($product['image'] ?? ''));
    $hasPhoto = $photo !== null && og_draw_photo($im, $photo);

    /* Text occupies the right when there is a photo on the left, and the full
       width when there is not. */
    $right = OG_W - 76;
    $left  = $hasPhoto ? 520 : 90;
    $wide  = $right - $left;

    $brand = trim((string) ($product['brand'] ?? ''));
    $y     = 190;

    if ($brand !== '') {
        og_text($im, $brand, 26, $right, $y, $accent, true, $wide);
        $y += 76;
    }

    /* og_wrap() hands back the next free baseline, so nothing has to guess how
       far a 62px Persian descender reaches. */
    $nameFa = trim((string) ($product['nameFa'] ?? ''));
    $y = og_wrap($im, $nameFa, 62, $right, $y, $ink, true, $wide, 2);

    $latin = trim((string) ($product['name'] ?? ''));
    if ($latin !== '') {
        og_text($im, $latin, 28, $right, $y + 6, $muted, false, $wide);
    }

    /* --- price ---------------------------------------------------------- */
    $price = (int) ($product['price'] ?? 0);
    $was   = (int) ($product['compareAt'] ?? 0);

    $y = OG_H - 96;
    if ($price > 0) {
        $label = number_format($price) . ' ' . arabic_shape('تومان');
        $box   = og_text($im, $label, 46, $right, $y, $ink, true, $wide);

        if ($was > $price) {
            $old  = number_format($was);
            $oldW = og_text($im, $old, 30, $right - $box - 34, $y - 6, $muted, false, $wide);
            /* Struck through rather than merely faint: a second number beside
               the price is ambiguous until it is crossed out. */
            $x2 = $right - $box - 34;
            imagefilledrectangle($im, $x2 - $oldW, $y - 16, $x2, $y - 13, $muted);

            $off = (int) round((1 - $price / $was) * 100);
            if ($off > 0) {
                /* ٪۱۸, the way it is written in Persian. Built as one
                   pre-shaped token so the visual-order pass does not treat the
                   digits as a Latin run and swap the sign to the far side. */
                og_badge($im, $off . '٪', $left, $y, $accent);
            }
        }
    }

    ob_start();
    imagepng($im, null, 6);
    $png = (string) ob_get_clean();
    imagedestroy($im);
    return $png;
}

/** Draw the product photo into the left half, cropped to fill. */
function og_draw_photo($im, string $path): bool
{
    $info = @getimagesize($path);
    if ($info === false) {
        return false;
    }
    $src = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG  => @imagecreatefrompng($path),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default        => false,
    };
    if (!$src) {
        return false;
    }

    $boxW = 430;
    $boxH = 470;
    $boxX = 60;
    $boxY = (OG_H - $boxH) / 2;

    $sw = imagesx($src);
    $sh = imagesy($src);
    /* Contain, not cover: a supplement tub cropped at the edges loses the
       label, which is the only part anyone recognises. */
    $scale = min($boxW / $sw, $boxH / $sh);
    $dw = (int) round($sw * $scale);
    $dh = (int) round($sh * $scale);

    imagecopyresampled($im, $src,
        (int) ($boxX + ($boxW - $dw) / 2), (int) ($boxY + ($boxH - $dh) / 2),
        0, 0, $dw, $dh, $sw, $sh);

    imagedestroy($src);
    return true;
}

/**
 * One line of text, right-aligned at $right.
 *
 * @return int the width drawn
 */
function og_text($im, string $text, int $size, int $right, int $y, int $colour,
                 bool $bold = true, int $max = 0): int
{
    if ($text === '') {
        return 0;
    }
    $shaped = arabic_has_persian($text) ? arabic_shape($text) : $text;
    $font   = og_font($bold);

    $box = imagettfbbox($size, 0, $font, $shaped);
    $w   = (int) abs($box[2] - $box[0]);

    imagettftext($im, $size, 0, $right - $w, $y, $colour, $font, $shaped);
    return $w;
}

/**
 * Text wrapped to at most $lines lines, right-aligned.
 *
 * Wrapping happens BEFORE shaping: shaped text is presentation forms in visual
 * order, so splitting it on spaces would cut words in the wrong places.
 *
 * @return int the y of the last baseline drawn
 */
function og_wrap($im, string $text, int $size, int $right, int $y, int $colour,
                 bool $bold, int $max, int $lines): int
{
    if (trim($text) === '') {
        return $y;
    }
    $font  = og_font($bold);
    $words = preg_split('/\s+/u', trim($text)) ?: [];

    $out  = [];
    $line = '';
    foreach ($words as $word) {
        $try    = $line === '' ? $word : $line . ' ' . $word;
        $shaped = arabic_has_persian($try) ? arabic_shape($try) : $try;
        $box    = imagettfbbox($size, 0, $font, $shaped);
        if (abs($box[2] - $box[0]) > $max && $line !== '') {
            $out[] = $line;
            $line  = $word;
            if (count($out) === $lines) { break; }
        } else {
            $line = $try;
        }
    }
    if ($line !== '' && count($out) < $lines) {
        $out[] = $line;
    }

    /* An overflowing name is cut with an ellipsis rather than shrunk: a card
       whose type size changes with the name length looks like a different
       template each time. */
    if (count($out) === $lines && count($words) > 0) {
        $drawn = implode(' ', $out);
        if (mb_strlen($drawn) < mb_strlen(trim($text))) {
            $out[count($out) - 1] .= '…';
        }
    }

    $lead = (int) round($size * 1.45);
    foreach ($out as $i => $l) {
        og_text($im, $l, $size, $right, $y + $i * $lead, $colour, $bold, $max);
    }

    /* The NEXT free baseline, not the last one used. Returning the last one
       made every caller add its own guess at the descender depth, and the
       first guess was wrong by about forty pixels. */
    return $y + count($out) * $lead;
}

/** The discount badge, drawn left of the price. */
function og_badge($im, string $text, int $x, int $y, int $accent): void
{
    $font   = og_font(true);
    $shaped = arabic_has_persian($text) ? arabic_shape($text) : $text;
    $box    = imagettfbbox(30, 0, $font, $shaped);
    $w      = (int) abs($box[2] - $box[0]);

    imagefilledrectangle($im, $x, $y - 44, $x + $w + 40, $y + 12, $accent);
    imagettftext($im, 30, 0, $x + 20, $y - 8, imagecolorallocate($im, 255, 255, 255),
                 $font, $shaped);
}

/**
 * Drop this product's older cards.
 *
 * The fingerprint is in the filename, so a repriced product leaves its previous
 * card behind - and this shop reprices whenever the dirham moves. A preview
 * cache still holding an old URL gets the current card rather than a 404,
 * because og.php renders from the product row and ignores the v parameter; the
 * fingerprint is there to defeat WhatsApp's cache, not to address a file.
 */
function og_sweep(string $slug, string $keep): void
{
    foreach (glob(og_cache_dir() . '/' . $slug . '-*.png') ?: [] as $old) {
        if ($old !== $keep) {
            @unlink($old);
        }
    }
}

/**
 * The card for a product, from cache when it is there.
 *
 * @return array{path:string,bytes:string}|null
 */
function og_card(array $product): ?array
{
    $slug = preg_replace('~[^a-z0-9\-_]~i', '', (string) ($product['slug'] ?? ''));
    if ($slug === '' || $slug === null) {
        return null;
    }

    $dir = og_cache_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        /* No cache is survivable — render every time rather than fail. */
        return ['path' => '', 'bytes' => og_render($product)];
    }

    $path = $dir . '/' . $slug . '-' . og_fingerprint($product) . '.png';
    if (is_file($path)) {
        $bytes = @file_get_contents($path);
        if ($bytes !== false && $bytes !== '') {
            return ['path' => $path, 'bytes' => $bytes];
        }
    }

    $bytes = og_render($product);

    /* Written through a temp name so a bot fetching mid-write never gets half
       a PNG — which some caches would then keep. */
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $bytes) !== false) {
        @rename($tmp, $path);
        @unlink($tmp);
        og_sweep($slug, $path);
    }

    return ['path' => $path, 'bytes' => $bytes];
}
