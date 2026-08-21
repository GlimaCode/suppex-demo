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
 * @param array $file  one entry of $_FILES
 * @return array{ok:bool,path?:string,error?:string}  path is web-relative
 */
function image_store(array $file): array
{
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
    $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
    if (!in_array($type, $allowed, true)) {
        return ['ok' => false, 'error' => 'فقط JPG، PNG و WebP پذیرفته می‌شود.'];
    }

    /* A "decompression bomb": a small file that expands to a gigantic bitmap
       and exhausts the PHP memory limit the moment GD opens it. */
    if ($width * $height > 40_000_000) {
        return ['ok' => false, 'error' => 'ابعاد تصویر بیش از حد بزرگ است.'];
    }

    if (!function_exists('imagecreatefromjpeg')) {
        return ['ok' => false, 'error' => 'افزونه GD روی سرور فعال نیست. از پشتیبانی هاست بخواهید آن را فعال کند.'];
    }

    $src = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($file['tmp_name']),
        IMAGETYPE_PNG  => @imagecreatefrompng($file['tmp_name']),
        IMAGETYPE_WEBP => @imagecreatefromwebp($file['tmp_name']),
        default        => false,
    };
    if ($src === false) {
        return ['ok' => false, 'error' => 'تصویر قابل خواندن نیست.'];
    }

    /* Downscale only. Enlarging a small photo makes it blurrier, not better. */
    $scale = min(1.0, UPLOAD_MAX_EDGE / max($width, $height));
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
    $dir  = uploads_dir() . '/products/' . $sub;
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

    return ['ok' => true, 'path' => uploads_url() . '/products/' . $sub . '/' . $name];
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

    return @unlink($realFile);
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
