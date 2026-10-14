<?php
/* CLI export of reviewed photo pixels to transparent WebP with 100px padding.
   Mutant uses only imagegen's alpha mask; its photographed label pixels remain
   from the original JPEG. This is asset preparation, never a web endpoint. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
$maskFile = $argv[1] ?? $root . '/products-to-import/processed-20261014/mutant-alpha-mask.png';
if (!is_file($maskFile)) { fwrite(STDERR, "The reviewed Mutant alpha mask is required.\n"); exit(1); }
$manifestFile = $root . '/db/catalog-photos.json';
$manifest = json_decode((string) file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
$outputDir = $root . '/assets/images/products/verified-webp-20261014';
if (!is_dir($outputDir)) { mkdir($outputDir, 0755, true); }

function photo_canvas(int $w, int $h): GdImage {
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, false); imagesavealpha($im, true);
    imagefill($im, 0, 0, 0x7fffffff);
    return $im;
}
function photo_bounds(GdImage $im, int $minimumOpacity = 1): array {
    $w = imagesx($im); $h = imagesy($im); $left = $w; $top = $h; $right = -1; $bottom = -1;
    for ($y = 0; $y < $h; $y++) { for ($x = 0; $x < $w; $x++) {
        $alpha = (imagecolorat($im, $x, $y) >> 24) & 127;
        if (127 - $alpha < $minimumOpacity) { continue; }
        $left = min($left, $x); $top = min($top, $y);
        $right = max($right, $x); $bottom = max($bottom, $y);
    } }
    if ($right < 0) { throw new RuntimeException('No visible product pixels'); }
    return ['x' => $left, 'y' => $top, 'width' => $right - $left + 1, 'height' => $bottom - $top + 1];
}
function photo_mutant(GdImage $original, GdImage $mask): GdImage {
    // Tight bounds of this reviewed reference photograph, derived from its
    // non-white bottle pixels. No generated RGB is used in the final asset.
    $originalBounds = ['x' => 116, 'y' => 41, 'width' => 269, 'height' => 521];
    $maskBounds = photo_bounds($mask, 8); // remove faint isolated alpha specks
    $cutMask = imagecrop($mask, $maskBounds);
    $scaledMask = photo_canvas($originalBounds['width'], $originalBounds['height']);
    imagecopyresampled($scaledMask, $cutMask, 0, 0, 0, 0, imagesx($scaledMask), imagesy($scaledMask), imagesx($cutMask), imagesy($cutMask));
    $out = photo_canvas(imagesx($original), imagesy($original));
    for ($y = 0; $y < imagesy($scaledMask); $y++) { for ($x = 0; $x < imagesx($scaledMask); $x++) {
        $a = (imagecolorat($scaledMask, $x, $y) >> 24) & 127;
        if (127 - $a < 8) { continue; }
        // The bottle is opaque. Generated soft alpha is useful only at its
        // boundary; interior label pixels retain their original RGB exactly.
        $interior = $x > 0 && $y > 0 && $x + 1 < imagesx($scaledMask) && $y + 1 < imagesy($scaledMask);
        if ($interior) {
            foreach ([[$x - 1, $y], [$x + 1, $y], [$x, $y - 1], [$x, $y + 1]] as [$nx, $ny]) {
                if (127 - ((imagecolorat($scaledMask, $nx, $ny) >> 24) & 127) < 8) { $interior = false; break; }
            }
        }
        if ($interior) { $a = 0; }
        $rgb = imagecolorat($original, $x + $originalBounds['x'], $y + $originalBounds['y']);
        // Unmatte antialiased edge pixels from the old white background.
        $opacity = (127 - $a) / 127;
        $channels = [];
        foreach ([16, 8, 0] as $shift) {
            $c = ($rgb >> $shift) & 255;
            $channels[] = $a ? max(0, min(255, (int) round(($c - 255 * (1 - $opacity)) / $opacity))) : $c;
        }
        imagesetpixel($out, $x + $originalBounds['x'], $y + $originalBounds['y'],
                      ($a << 24) | ($channels[0] << 16) | ($channels[1] << 8) | $channels[2]);
    } }
    return $out;
}

foreach ($manifest['products'] as $slug => &$pack) {
    foreach ($pack['photos'] as &$photo) {
        $stem = pathinfo($photo['file'], PATHINFO_FILENAME);
        $source = $root . '/assets/images/products/verified-20261014/' . $stem . ($slug === 'mutant-amino' ? '.jpg' : '.png');
        $im = $slug === 'mutant-amino' ? imagecreatefromjpeg($source) : imagecreatefrompng($source);
        if ($slug === 'mutant-amino') { $im = photo_mutant($im, imagecreatefrompng($maskFile)); }
        $bounds = photo_bounds($im);
        $tight = imagecrop($im, $bounds);
        $canvas = photo_canvas($bounds['width'] + 200, $bounds['height'] + 200);
        imagecopy($canvas, $tight, 100, 100, 0, 0, imagesx($tight), imagesy($tight));
        $file = 'assets/images/products/verified-webp-20261014/' . $stem . '.webp';
        if (!imagewebp($canvas, $root . '/' . $file, IMG_WEBP_LOSSLESS)) { throw new RuntimeException('WebP export failed'); }
        $readBack = imagecreatefromwebp($root . '/' . $file);
        $actual = photo_bounds($readBack);
        if ($actual['y'] !== 100 || imagesy($readBack) - $actual['y'] - $actual['height'] !== 100) {
            throw new RuntimeException('WebP vertical padding verification failed');
        }
        $previous = substr(hash_file('sha256', $source), 0, 24) . '.' . strtolower(pathinfo($source, PATHINFO_EXTENSION));
        $newHash = hash_file('sha256', $root . '/' . $file);
        $previousFiles = array_merge($photo['previous_files'] ?? [], [$previous]);
        if (($photo['sha256'] ?? '') !== $newHash && str_ends_with($photo['file'], '.webp')) {
            $previousFiles[] = substr($photo['sha256'], 0, 24) . '.webp';
        }
        $photo['previous_files'] = array_values(array_unique($previousFiles));
        $photo['file'] = $file;
        $photo['sha256'] = $newHash;
        $photo['width'] = imagesx($readBack); $photo['height'] = imagesy($readBack);
        $photo['padding_top'] = 100; $photo['padding_bottom'] = 100;
        echo basename($file), ': ', imagesx($readBack), 'x', imagesy($readBack), ', top=100 bottom=100, ', filesize($root . '/' . $file), " bytes\n";
    } unset($photo);
} unset($pack);
$manifest['format'] = 'webp'; $manifest['background'] = 'transparent';
$manifest['vertical_padding_px'] = 100;
file_put_contents($manifestFile, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
