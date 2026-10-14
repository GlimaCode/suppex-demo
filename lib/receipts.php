<?php
/* ===========================================================================
   SUPPEX — Payment receipts
   ---------------------------------------------------------------------------
   The customer's photograph of the transfer, sent from the payment panel or
   the tracking page. It goes to the shop's Telegram - the owner's chat, and
   the group where orders are confirmed when one is set - and it stays on the
   order in the panel.

   A receipt shows a card number, a name and an amount: it is the most private
   file the shop holds. So it is NOT stored under uploads/, where every file
   can be opened by anyone holding its URL. It goes into a directory beside the
   site, above public_html, and the panel reads it back through
   admin/receipt.php, which asks for a login first.

   The upload is a stranger's file, so it gets the same treatment as a product
   photograph in lib/images.php: the type is read from the bytes, the name is
   made here, and the picture is re-encoded through GD, which keeps the pixels
   and nothing else.
   =========================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/images.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/orders.php';

/* The browser shrinks a receipt before sending it (app.js), so these are
   the ceiling for an old browser, not the usual case. */
const RECEIPT_MAX_BYTES = 8 * 1024 * 1024;
/* A receipt is text on a phone screenshot - often 1080x2400. 2000px keeps
   every digit readable; product photographs stop at 1400. */
const RECEIPT_MAX_EDGE  = 2000;
const RECEIPT_JPEG_Q    = 85;
/* A customer sending a clearer photo, or a second transfer - not a flood. */
const RECEIPT_PER_ORDER = 5;

const RECEIPT_THROTTLE_USER    = '~receipt';
const RECEIPT_THROTTLE_MAX     = 10;
const RECEIPT_THROTTLE_MINUTES = 60;

/** Orders a receipt can still be sent for. */
const RECEIPT_STATUSES = ['new', 'paid', 'expired'];

/**
 * Where receipts live: beside the site, never inside it.
 *
 * suppex-config.php can name another place with 'private_dir'. Without it,
 * the directory next to the site's own folder - on the host that is the
 * account's home, beside public_html and suppex-config.php.
 */
function receipts_dir(): string
{
    $base = (string) (suppex_config()['private_dir'] ?? '');
    if (trim($base) === '') {
        $base = dirname(SUPPEX_ROOT) . '/suppex-private';
    }
    return rtrim($base, '/\\') . '/receipts';
}

/** Whether the migration that adds order_receipts has run. */
function receipts_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        $ready = db_value(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['order_receipts']
        ) !== null;
    }
    return $ready;
}

function receipt_is_throttled(): bool
{
    $since = date('Y-m-d H:i:s', time() - RECEIPT_THROTTLE_MINUTES * 60);
    return (int) db_value(
        'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND username = ? AND attempted_at > ?',
        [client_ip_binary(), RECEIPT_THROTTLE_USER, $since]
    ) >= RECEIPT_THROTTLE_MAX;
}

function receipt_record_attempt(): void
{
    db_query('INSERT INTO login_attempts (ip, username) VALUES (?, ?)',
        [client_ip_binary(), RECEIPT_THROTTLE_USER]);
}

function receipt_accepts(array $order): bool
{
    return in_array((string) ($order['status'] ?? ''), RECEIPT_STATUSES, true);
}

/**
 * Store an uploaded receipt.
 *
 * @param array $file one entry of $_FILES
 * @return array{ok:bool,file?:string,bytes?:int,width?:int,height?:int,error?:string}
 */
function receipt_store(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => image_upload_error_message((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE))];
    }
    /* A path the request pointed at, such as /etc/passwd, is not an upload. */
    if (!is_uploaded_file((string) $file['tmp_name'])) {
        return ['ok' => false, 'error' => 'فایل معتبر نیست.'];
    }
    return receipt_save((string) $file['tmp_name'], (int) ($file['size'] ?? 0));
}

/**
 * The pipeline itself, on a file already on disk. receipt_store() is the only
 * caller that takes a file from a request; the tests call this directly.
 */
function receipt_save(string $tmp, int $bytes): array
{
    if ($bytes <= 0 || $bytes > RECEIPT_MAX_BYTES) {
        return ['ok' => false, 'error' => 'حجم تصویر بیشتر از ۸ مگابایت است. یک اسکرین‌شات از رسید بفرستید.'];
    }

    /* The type comes from the bytes. The name and the MIME type the browser
       sent are strings the sender chose. */
    $info = @getimagesize($tmp);
    if ($info === false) {
        return ['ok' => false, 'error' => 'این فایل تصویر نیست. عکس یا اسکرین‌شات رسید را بفرستید.'];
    }
    [$width, $height, $type] = $info;

    if (!function_exists('imagecreatefromjpeg')) {
        return ['ok' => false, 'error' => 'دریافت تصویر روی سرور فعال نیست. رسید را در همان گفتگو بفرستید.'];
    }
    $decoders = [
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG  => 'imagecreatefrompng',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
    ];
    if (!isset($decoders[$type]) || !function_exists($decoders[$type])) {
        return ['ok' => false, 'error' => 'این قالب تصویر پذیرفته نمی‌شود. عکس JPG یا PNG بفرستید.'];
    }

    /* The same decompression-bomb ceiling as a product photograph: half the
       memory limit, at four bytes a pixel. */
    $memoryLimit = image_memory_limit_bytes();
    $maxPixels   = $memoryLimit > 0 ? (int) (($memoryLimit * 0.5) / 4) : 12_000_000;
    if ($width * $height > $maxPixels) {
        return ['ok' => false, 'error' => 'ابعاد تصویر بیش از حد بزرگ است. یک اسکرین‌شات از رسید بفرستید.'];
    }

    $src = @$decoders[$type]($tmp);
    if ($src === false) {
        return ['ok' => false, 'error' => 'تصویر قابل خواندن نیست.'];
    }

    $scale = min(1.0, RECEIPT_MAX_EDGE / max($width, $height));
    $newW  = max(1, (int) round($width * $scale));
    $newH  = max(1, (int) round($height * $scale));
    $dst   = imagecreatetruecolor($newW, $newH);
    /* White under anything transparent: a receipt is read, and a PNG with an
       alpha channel would otherwise come out on black. */
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
    imagedestroy($src);

    $sub = date('Y/m');
    $dir = receipts_dir() . '/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        imagedestroy($dst);
        error_log('[suppex] cannot create receipts directory: ' . $dir);
        return ['ok' => false, 'error' => 'رسید ذخیره نشد. لطفاً آن را در همان گفتگو بفرستید.'];
    }
    receipts_guard_dir();

    $name = date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.jpg';
    $full = $dir . '/' . $name;
    $written = imagejpeg($dst, $full, RECEIPT_JPEG_Q);
    imagedestroy($dst);
    if (!$written) {
        return ['ok' => false, 'error' => 'رسید ذخیره نشد. لطفاً آن را در همان گفتگو بفرستید.'];
    }
    @chmod($full, 0600);

    return ['ok' => true, 'file' => $sub . '/' . $name, 'bytes' => (int) filesize($full),
            'width' => $newW, 'height' => $newH];
}

/**
 * A deny-all .htaccess at the top of the private directory.
 *
 * The directory is outside public_html by default, where no URL reaches it.
 * This is for a 'private_dir' that somebody pointed inside it after all.
 */
function receipts_guard_dir(): void
{
    $top = dirname(receipts_dir());
    $htaccess = $top . '/.htaccess';
    if (is_dir($top) && !is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\n");
    }
}

/**
 * The file on disk for a stored name, or null.
 *
 * The name is matched against the exact shape receipt_save() makes, so
 * nothing read from the database can walk out of the directory - «../» and
 * friends never reach the filesystem.
 */
function receipt_path(string $file): ?string
{
    if (!preg_match('~\A[0-9]{4}/[0-9]{2}/[0-9]{8}-[0-9]{6}-[0-9a-f]{16}\.jpg\z~', $file)) {
        return null;
    }
    $full = receipts_dir() . '/' . $file;
    return is_file($full) ? $full : null;
}

/** @param array{file:string,bytes:int,width:int,height:int} $stored */
function receipt_add(int $orderId, array $stored): int
{
    return (int) db_insert(
        'INSERT INTO order_receipts (order_id, file, bytes, width, height, ip) VALUES (?, ?, ?, ?, ?, ?)',
        [$orderId, $stored['file'], (int) $stored['bytes'], (int) $stored['width'], (int) $stored['height'],
         client_ip_binary()]
    );
}

function receipt_get(int $id): ?array
{
    return db_one('SELECT * FROM order_receipts WHERE id = ?', [$id]);
}

function receipts_for_order(int $orderId): array
{
    if (!receipts_ready()) {
        return [];
    }
    return db_all('SELECT * FROM order_receipts WHERE order_id = ? ORDER BY id', [$orderId]);
}

function receipt_count(int $orderId): int
{
    if (!receipts_ready()) {
        return 0;
    }
    return (int) db_value('SELECT COUNT(*) FROM order_receipts WHERE order_id = ?', [$orderId]);
}

/** The caption under the photograph in Telegram. */
function receipt_caption(array $order, array $receipt, string $origin = ''): string
{
    $lines = [];
    $lines[] = 'رسید واریز — ' . $order['code'];
    $lines[] = 'مبلغ سفارش: ' . money((int) $order['total']) . ' تومان';
    $lines[] = 'گیرنده: ' . $order['customer_name'];
    $lines[] = 'تماس: ' . $order['phone'];
    $lines[] = 'وضعیت: ' . (ORDER_STATUS_FA[(string) $order['status']] ?? (string) $order['status']);
    if ($origin !== '') {
        $lines[] = '';
        $lines[] = $origin . '/admin/order.php?id=' . (int) $order['id'];
    }
    /* Telegram refuses a caption over 1024 characters, and a name or an
       address typed at length could get there. */
    return mb_substr(implode("\n", $lines), 0, 1000);
}

/**
 * An earlier receipt on the same order that is this very picture, or null.
 *
 * The same screenshot sent twice - a customer not sure the first one went, or
 * «ارسال رسید دیگر» tapped with the same photo - is one receipt, and a second
 * copy in the group reads as a second payment. The re-encode above is
 * deterministic, and so is the browser's shrink before it, so the same
 * picture sent again arrives as the same file, byte for byte.
 */
function receipt_duplicate_of(int $orderId, string $file): ?array
{
    $path = receipt_path($file);
    if ($path === null) {
        return null;
    }
    $size = filesize($path);
    $hash = sha1_file($path);
    foreach (receipts_for_order($orderId) as $earlier) {
        if ((string) $earlier['file'] === $file) {
            continue;
        }
        $other = receipt_path((string) $earlier['file']);
        if ($other !== null && filesize($other) === $size && sha1_file($other) === $hash) {
            return $earlier;
        }
    }
    return null;
}

/** A stored file that is not going to be kept. */
function receipt_discard(string $file): void
{
    $path = receipt_path($file);
    if ($path !== null) {
        @unlink($path);
    }
}

/**
 * Where a receipt goes: one entry per distinct chat, with the flags on the
 * receipt that it settles.
 *
 * The owner's chat and the confirmation group can be one and the same - the
 * bot's chat_id in suppex-config.php set to the group itself, so new orders
 * land there too - and then every receipt arrived in that group twice. The
 * same chat is one message, marked for both.
 *
 * @return array<int,array{chat:string,marks:array<int,string>}>
 */
function receipt_targets(string $chatId, string $groupId): array
{
    $chatId  = trim($chatId);
    $groupId = trim($groupId);
    $targets = [];
    if ($chatId !== '') {
        $targets[] = ['chat' => $chatId, 'marks' => ['chat']];
    }
    if ($groupId !== '') {
        if ($groupId === $chatId) {
            $targets[0]['marks'][] = 'group';
        } else {
            $targets[] = ['chat' => $groupId, 'marks' => ['group']];
        }
    }
    return $targets;
}

/**
 * Send one receipt to Telegram: the owner's chat from the config file, and
 * the confirmation group set in the panel. Each that goes through is marked
 * on the receipt, so the panel can show what did not and offer to resend.
 *
 * Each chat gets each receipt once. A chat the receipt is already marked as
 * delivered to is skipped - so «ارسال دوباره» in the panel sends only where
 * it did not arrive - and a named lock keeps two sends of one receipt from
 * running side by side: a double click on that button, or a resend while
 * the first send is still on its way.
 *
 * @return array{chat:?bool,group:?bool,sent:int,busy:bool}
 *   chat, group: true delivered (now or before), false failed, null not set up.
 *   sent: messages this call sent. busy: another send had the receipt.
 */
function receipt_notify(array $order, array $receipt, string $origin = ''): array
{
    $result = ['chat' => null, 'group' => null, 'sent' => 0, 'busy' => false];
    $cfg = suppex_config()['telegram_bot'] ?? [];
    if (empty($cfg['enabled']) || empty($cfg['token'])) {
        return $result;
    }
    $targets = receipt_targets((string) ($cfg['chat_id'] ?? ''), (string) setting('telegram_group_id', ''));
    if (!$targets) {
        return $result;
    }

    /* Held by this connection until released, and let go by the database if
       the process dies holding it, so nothing can stay locked for ever. */
    $lock = 'suppex_receipt_' . (int) $receipt['id'];
    if ((int) db_value('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
        $result['busy'] = true;
        return $result;
    }
    try {
        /* Read again under the lock: the row the caller holds can be older
           than a send that finished since. */
        $fresh = receipt_get((int) $receipt['id']);
        $path  = $fresh === null ? null : receipt_path((string) $fresh['file']);
        if ($fresh === null || $path === null) {
            return $result;
        }
        $caption = receipt_caption($order, $fresh, $origin);
        foreach ($targets as $target) {
            $done = false;
            foreach ($target['marks'] as $mark) {
                if ((int) $fresh['sent_' . $mark] === 1) {
                    $done = true;
                }
            }
            if (!$done) {
                $done = telegram_send_photo((string) $cfg['token'], $target['chat'], $path, $caption);
                if ($done) {
                    $result['sent']++;
                }
            }
            foreach ($target['marks'] as $mark) {
                $result[$mark] = $done;
                if ($done && (int) $fresh['sent_' . $mark] !== 1) {
                    db_query('UPDATE order_receipts SET sent_' . $mark . ' = 1 WHERE id = ?', [(int) $fresh['id']]);
                }
            }
        }
    } finally {
        db_value('SELECT RELEASE_LOCK(?)', [$lock]);
    }
    return $result;
}
