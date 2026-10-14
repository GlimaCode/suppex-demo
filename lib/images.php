<?php
/* ===========================================================================
   SUPPEX — Product image uploads
   ---------------------------------------------------------------------------
   The most dangerous form in any admin panel. An upload field that trusts what
   it is given is how a shop becomes a malware host: a file called photo.php
   lands in a web-readable directory, gets requested once, and the attacker now
   runs code as the site.

   Four independent defences, because any one of them can be worked around:

     1. The type is decided by getimagesize(), which reads the actual bytes.
        The browser-supplied MIME type and the file extension are both just
        strings the uploader chose and neither is checked for anything.
     2. The stored filename is generated here. Nothing the uploader typed
        survives, so ".php", a null byte or a path traversal cannot reach the
        filesystem.
     3. The image is re-encoded through GD rather than moved. Re-encoding
        discards everything that is not pixels, including PHP hidden in EXIF —
        the classic "valid JPEG that is also a valid script" trick.
     4. uploads/.htaccess refuses to execute anything at all, so even a file
        that somehow got there could not run.
   =========================================================================== */

declare(strict_types=1);

const UPLOAD_MAX_BYTES = 6 * 1024 * 1024;   // 6 MB
const UPLOAD_MAX_EDGE  = 1400;              // px; product images are square-ish
const UPLOAD_JPEG_Q    = 82;

/* Lighter copies of every product photograph, as WebP, beside the original:
   «x.jpg» gets «x-w160.webp» … «x-w960.webp» and one at its own width. The
   page then lets the browser pick (srcset), and a thumbnail is a thumbnail.

   PageSpeed measured why: the product page's five gallery thumbnails were the
   1221x1400 originals, 160 to 216 KB each, drawn at 79x79 - 1.2 MB a phone
   downloaded for pictures the size of a fingernail, while the main photograph
   waited behind them. The widths are what the pages draw: a thumbnail at 2x,
   a card on a phone and on a desktop, the product photograph on a desktop;
   the full-width copy is the same picture as WebP, a third lighter than the
   JPEG, for a phone's high-density screen. */
const IMAGE_VARIANT_WIDTHS = [160, 320, 640, 960];
const IMAGE_VARIANT_Q      = 76;

function uploads_dir(): string
{
    $dir = suppex_config()['uploads_dir'] ?? (SUPPEX_ROOT . '/uploads');
    return rtrim($dir, '/\\');
}

function uploads_url(): string
{
    return rtrim(suppex_config()['uploads_url'] ?? '/uploads', '/');
}

/**
 * @param array  $file     one entry of $_FILES
 * @param string $folder   under uploads/: 'products', or 'brands' for a logo
 * @param int    $maxEdge  longest side kept, in px
 * @return array{ok:bool,path?:string,error?:string}  path is web-relative
 */
function image_store(array $file, string $folder = 'products', int $maxEdge = UPLOAD_MAX_EDGE): array
{
    /* A fixed list, never a path: this becomes part of a directory name. */
    if (!in_array($folder, ['products', 'brands'], true)) {
        $folder = 'products';
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => image_upload_error_message((int) ($file['error'] ?? 0))];
    }

    /* Confirms the file really came through an HTTP upload and is not a path
       the request managed to point at, such as /etc/passwd. */
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'فایل معتبر نیست.'];
    }

    if ((int) $file['size'] > UPLOAD_MAX_BYTES) {
        return ['ok' => false, 'error' => 'حجم تصویر بیشتر از ۶ مگابایت است.'];
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return ['ok' => false, 'error' => 'این فایل تصویر نیست.'];
    }

    [$width, $height, $type] = $info;

    if (!function_exists('imagecreatefromjpeg')) {
        return ['ok' => false, 'error' => 'افزونه GD روی سرور فعال نیست. از پشتیبانی هاست بخواهید آن را فعال کند.'];
    }

    /* The allow-list is built from the decoders this GD build actually has,
       rather than being a fixed list.

       getimagesize() identifies WebP by reading the file header and needs no
       GD at all, so on a build compiled without libwebp — still common on older
       cPanel stacks — a .webp file would pass a hard-coded allow-list and then
       hit a fatal "call to undefined function imagecreatefromwebp()". That is a
       white page with no explanation, where the honest answer is "this server
       cannot read WebP, send a JPG". */
    $decoders = [
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG  => 'imagecreatefrompng',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
    ];
    if (!isset($decoders[$type]) || !function_exists($decoders[$type])) {
        return ['ok' => false, 'error' => 'این قالب تصویر روی سرور پشتیبانی نمی‌شود. از JPG یا PNG استفاده کنید.'];
    }

    /* A "decompression bomb": a small file that expands to a gigantic bitmap
       and exhausts the memory limit the moment GD opens it.

       The ceiling is derived from the memory actually available rather than
       being a fixed pixel count. GD allocates four bytes per pixel for a
       truecolor image, so a flat-colour 7000x5600 PNG compresses to a couple of
       hundred kilobytes — sailing past the file-size check — and then asks for
       about 160 MB, which a stock 128 MB limit cannot give it. A fixed 40 MP
       guard never fires before that happens; it is above the real ceiling, not
       below it. Half the limit leaves room for the resized copy that is
       allocated at the same time. */
    $memoryLimit = image_memory_limit_bytes();
    $maxPixels   = $memoryLimit > 0
        ? (int) (($memoryLimit * 0.5) / 4)
        : 12_000_000;
    if ($width * $height > $maxPixels) {
        return ['ok' => false, 'error' => 'ابعاد تصویر بیش از حد بزرگ است. تصویر را کوچک‌تر کنید.'];
    }

    $src = @$decoders[$type]($file['tmp_name']);
    if ($src === false) {
        return ['ok' => false, 'error' => 'تصویر قابل خواندن نیست.'];
    }

    /* Downscale only. Enlarging a small photo makes it blurrier, not better. */
    $scale = min(1.0, max(1, $maxEdge) / max($width, $height));
    $newW  = max(1, (int) round($width * $scale));
    $newH  = max(1, (int) round($height * $scale));

    $dst = imagecreatetruecolor($newW, $newH);

    /* Product cut-outs are usually PNG or WebP with a transparent background;
       without this they would come out with a black box behind them. */
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
    imagealphablending($dst, true);

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
    imagedestroy($src);

    $keepAlpha = ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP);
    $ext       = $keepAlpha ? 'webp' : 'jpg';

    /* Month folders keep the directory listing usable after a few hundred
       products; a single flat folder becomes painful to browse over FTP. */
    $sub  = date('Y/m');
    $dir  = uploads_dir() . '/' . $folder . '/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        imagedestroy($dst);
        error_log('[suppex] cannot create upload directory: ' . $dir);
        return ['ok' => false, 'error' => 'پوشه آپلود قابل نوشتن نیست. دسترسی پوشه uploads را روی 755 بگذارید.'];
    }

    $name = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $full = $dir . '/' . $name;

    $written = $keepAlpha && function_exists('imagewebp')
        ? imagewebp($dst, $full, UPLOAD_JPEG_Q)
        : imagejpeg($dst, $full, UPLOAD_JPEG_Q);

    imagedestroy($dst);

    if (!$written) {
        return ['ok' => false, 'error' => 'ذخیره تصویر ناموفق بود.'];
    }
    @chmod($full, 0644);

    $webPath = uploads_url() . '/' . $folder . '/' . $sub . '/' . $name;

    /* A product photograph gets its lighter copies now, while the upload is
       still in memory's reach; a brand logo is drawn small already. A failure
       here costs speed, never the upload - the page then serves the original,
       as it always did. */
    if ($folder === 'products') {
        try {
            image_make_variants($webPath);
        } catch (Throwable $e) {
            error_log('[suppex] image variants: ' . $e->getMessage());
        }
    }

    return ['ok' => true, 'path' => $webPath];
}

/**
 * Delete a stored image.
 *
 * The path is confirmed to resolve inside the uploads directory before
 * anything is unlinked. Without that check a crafted path such as
 * "/uploads/../assets/js/app.js" would delete a file the panel has no
 * business touching.
 */
function image_delete(string $webPath): bool
{
    $prefix = uploads_url() . '/';
    if (strpos($webPath, $prefix) !== 0) {
        return false;
    }

    $relative = substr($webPath, strlen($prefix));
    $full     = uploads_dir() . '/' . $relative;

    $realBase = realpath(uploads_dir());
    $realFile = realpath($full);
    if ($realBase === false || $realFile === false) {
        return false;
    }
    if (strpos($realFile, $realBase . DIRECTORY_SEPARATOR) !== 0) {
        return false;
    }

    /* Its lighter copies go with it; nothing links to them once it is gone. */
    foreach (image_variant_widths($webPath) as $w) {
        @unlink(dirname($realFile) . '/' . basename(image_variant_path($webPath, $w)));
    }

    return @unlink($realFile);
}

/* ---------------------------------------------------------------------------
   Lighter copies
   --------------------------------------------------------------------------- */

/** The copy of a picture at one width: «…/x.jpg» -> «…/x-w320.webp». */
function image_variant_path(string $webPath, int $width): string
{
    return (string) preg_replace('~\.(jpe?g|png|webp)$~i', '-w' . $width . '.webp', $webPath);
}

/** Is this web path one of the copies rather than a picture of its own? */
function image_is_variant(string $webPath): bool
{
    return (bool) preg_match('~-w\d+\.webp$~i', $webPath);
}

/** The file behind an uploads web path, or null for anything else. */
function image_upload_file(string $webPath): ?string
{
    $prefix = uploads_url() . '/';
    if (strpos($webPath, $prefix) !== 0 || strpos($webPath, '..') !== false
        || !preg_match('~\.(jpe?g|png|webp)$~i', $webPath)) {
        return null;
    }
    return uploads_dir() . '/' . substr($webPath, strlen($prefix));
}

/**
 * The widths this picture has copies at, smallest first - the last is its own
 * width. [] when it has none, or is not an upload at all (a bundled .svg).
 *
 * @return list<int>
 */
function image_variant_widths(string $webPath): array
{
    static $memo = [];
    if (isset($memo[$webPath])) {
        return $memo[$webPath];
    }
    $file = image_upload_file($webPath);
    if ($file === null || image_is_variant($webPath)) {
        return $memo[$webPath] = [];
    }
    $base   = (string) preg_replace('~\.(jpe?g|png|webp)$~i', '', basename($file));
    $widths = [];
    /* The names are a date and random hex (image_store()), so nothing in them
       is a glob pattern; a name that has one is not ours and gets nothing. */
    if (strpbrk($base, '*?[]{}') === false) {
        foreach (glob(dirname($file) . '/' . $base . '-w*.webp') ?: [] as $v) {
            if (preg_match('~-w(\d+)\.webp$~', $v, $m)) {
                $widths[] = (int) $m[1];
            }
        }
    }
    sort($widths);
    return $memo[$webPath] = $widths;
}

/**
 * For each of these pictures that has copies, their widths - what the pages
 * and the API hand the browser to build a srcset from (ui.srcsetAttr()).
 *
 * @param iterable<string> $paths
 * @return array<string,list<int>>
 */
function image_variants_map(iterable $paths): array
{
    $map = [];
    foreach ($paths as $p) {
        $p = (string) $p;
        if ($p === '' || isset($map[$p])) {
            continue;
        }
        $w = image_variant_widths($p);
        if ($w) {
            $map[$p] = $w;
        }
    }
    return $map;
}

/** srcset="…" sizes="…" for a picture that has copies; '' otherwise. */
function image_srcset_attr(string $webPath, string $sizes): string
{
    $widths = image_variant_widths($webPath);
    if (!$widths) {
        return '';
    }
    $set = [];
    foreach ($widths as $w) {
        $set[] = image_variant_path($webPath, $w) . ' ' . $w . 'w';
    }
    return ' srcset="' . e(implode(', ', $set)) . '" sizes="' . e($sizes) . '"';
}

/**
 * Make the copies one picture is missing. Returns how many were written.
 *
 * Decoded once for all of them. The same guard image_store() applies keeps a
 * huge picture from exhausting memory; it simply gets no copies.
 */
function image_make_variants(string $webPath): int
{
    $file = image_upload_file($webPath);
    if ($file === null || image_is_variant($webPath) || !is_file($file)
        || !function_exists('imagewebp')) {
        return 0;
    }
    $info = @getimagesize($file);
    if ($info === false) {
        return 0;
    }
    [$width, $height, $type] = $info;
    if ($width < 1 || $height < 1) {
        return 0;
    }

    $wanted = array_values(array_filter(IMAGE_VARIANT_WIDTHS, static fn(int $w) => $w < $width));
    $wanted[] = (int) $width;
    $todo = [];
    foreach ($wanted as $w) {
        $target = dirname($file) . '/' . basename(image_variant_path($webPath, $w));
        if (!is_file($target)) {
            $todo[$w] = $target;
        }
    }
    if (!$todo) {
        return 0;
    }

    $decoders = [
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG  => 'imagecreatefrompng',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
    ];
    if (!isset($decoders[$type]) || !function_exists($decoders[$type])) {
        return 0;
    }
    $limit = image_memory_limit_bytes();
    if ($limit > 0 && $width * $height * 4 * 2 > $limit * 0.8) {
        return 0;
    }
    $src = @$decoders[$type]($file);
    if ($src === false) {
        return 0;
    }

    $made = 0;
    foreach ($todo as $w => $target) {
        $h   = max(1, (int) round($height * $w / $width));
        $dst = imagecreatetruecolor($w, $h);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
        imagealphablending($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $width, $height);
        imagealphablending($dst, false);
        if (@imagewebp($dst, $target, IMAGE_VARIANT_Q)) {
            @chmod($target, 0644);
            $made++;
        }
        imagedestroy($dst);
    }
    imagedestroy($src);

    return $made;
}

/**
 * Every product photograph already uploaded, and whether its copies exist.
 *
 * The drop folder for the bulk importer (uploads/products/bulk) is left out:
 * those are raw files waiting to be matched, not pictures on the site yet.
 *
 * @return list<string> web paths
 */
function image_originals(): array
{
    $root = uploads_dir() . '/products';
    if (!is_dir($root)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        if (strpos($rel, 'bulk/') === 0 || !preg_match('~\.(jpe?g|png|webp)$~i', $rel)) {
            continue;
        }
        $web = uploads_url() . '/products/' . $rel;
        if (!image_is_variant($web)) {
            $out[] = $web;
        }
    }
    sort($out);
    return $out;
}

/** Does this picture still lack the copy at its own width - the last made? */
function image_needs_variants(string $webPath): bool
{
    $file = image_upload_file($webPath);
    if ($file === null) {
        return false;
    }
    $info = @getimagesize($file);
    if ($info === false) {
        return false;
    }
    return !is_file(dirname($file) . '/' . basename(image_variant_path($webPath, (int) $info[0])));
}

/**
 * Catch up: copies for the pictures uploaded before they existed, or placed
 * by the bulk importer, for as long as the time allowed.
 *
 * @return array{made:int,done:int,pending:int,total:int}
 */
function image_variants_backfill(float $seconds = 5.0): array
{
    $start = microtime(true);
    $made = 0;
    $pending = 0;
    $all = image_originals();
    foreach ($all as $web) {
        if (!image_needs_variants($web)) {
            continue;
        }
        if (microtime(true) - $start > $seconds) {
            $pending++;
            continue;
        }
        $made += image_make_variants($web);
        if (image_needs_variants($web)) {
            $pending++;      // could not be made (too large, unreadable): counted, not retried for ever
        }
    }
    return ['made' => $made, 'done' => count($all) - $pending, 'pending' => $pending, 'total' => count($all)];
}

/**
 * PHP's memory_limit in bytes.
 *
 * ini_get() returns a shorthand string such as "128M", and -1 means unlimited —
 * comparing either against a byte count without converting quietly produces
 * nonsense (PHP casts "128M" to 128).
 *
 * @return int bytes, or 0 when unlimited/unknown
 */
function image_memory_limit_bytes(): int
{
    $raw = trim((string) ini_get('memory_limit'));
    if ($raw === '' || $raw === '-1') {
        return 0;
    }

    $value = (int) $raw;
    switch (strtolower(substr($raw, -1))) {
        case 'g': $value *= 1024 * 1024 * 1024; break;
        case 'm': $value *= 1024 * 1024; break;
        case 'k': $value *= 1024; break;
    }
    return max(0, $value);
}

function image_upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
            'حجم فایل بیشتر از حد مجاز سرور است.',
        UPLOAD_ERR_PARTIAL   => 'آپلود نیمه‌کاره ماند. دوباره تلاش کنید.',
        UPLOAD_ERR_NO_FILE   => 'فایلی انتخاب نشده است.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE =>
            'سرور نتوانست فایل را ذخیره کند. با پشتیبانی هاست تماس بگیرید.',
        default              => 'آپلود ناموفق بود.',
    };
}
