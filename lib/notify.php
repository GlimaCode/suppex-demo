<?php
/* ===========================================================================
   SUPPEX — Order notifications
   ---------------------------------------------------------------------------
   Pushes a new order to the shop owner the instant it is placed, so they see
   it even if the customer never gets round to pasting the message into chat.

   Every credential used here is read from the server-side config file. That is
   the whole reason this code is on the server: an API key placed in the
   JavaScript is downloaded by every visitor and can be read straight out of
   the page source, and anyone who reads it can spend the shop's SMS credit or
   post as the shop's bot.
   =========================================================================== */

declare(strict_types=1);

/** Plain-text summary, the same order the customer sees. */
function notify_format_order(array $order): string
{
    $lines = [];
    $lines[] = 'سفارش جدید — ' . $order['code'];
    $lines[] = '';

    foreach ($order['items'] as $i => $item) {
        $lines[] = ($i + 1) . ') ' . $item['name_fa'];
        if ($item['variant_label'] !== '') {
            $lines[] = '   ' . $item['variant_label'];
        }
        $lines[] = '   ' . $item['qty'] . ' × ' . money((int) $item['unit_price'])
                 . ' = ' . money((int) $item['line_total']) . ' تومان';
    }

    $lines[] = '';
    $lines[] = 'جمع کالاها: ' . money((int) $order['subtotal']) . ' تومان';
    $lines[] = 'هزینه ارسال: ' . ((int) $order['shipping'] === 0
        ? 'رایگان'
        : money((int) $order['shipping']) . ' تومان');
    $lines[] = 'مبلغ قابل پرداخت: ' . money((int) $order['total']) . ' تومان';
    $lines[] = '';
    $lines[] = 'گیرنده: ' . $order['customer_name'];
    $lines[] = 'تماس: ' . $order['phone'];
    $lines[] = 'آدرس: ' . $order['address'];
    $lines[] = 'کد پستی: ' . $order['postal'];
    if (trim((string) $order['note']) !== '') {
        $lines[] = 'توضیحات: ' . $order['note'];
    }

    return implode("\n", $lines);
}

function notify_telegram(array $order): void
{
    $cfg = suppex_config()['telegram_bot'] ?? [];
    if (empty($cfg['enabled']) || empty($cfg['token']) || empty($cfg['chat_id'])) {
        return;
    }

    notify_post(
        'https://api.telegram.org/bot' . $cfg['token'] . '/sendMessage',
        ['chat_id' => $cfg['chat_id'], 'text' => notify_format_order($order)]
    );
}

function notify_sms(array $order): void
{
    $cfg = suppex_config()['sms'] ?? [];
    if (empty($cfg['enabled']) || empty($cfg['api_key']) || empty($cfg['to'])) {
        return;
    }

    /* Deliberately short. An SMS is a nudge to go and open the panel, not a
       copy of the order — Persian costs one credit per 70 characters, so a
       full order would be six or seven messages every time. */
    $text = 'سفارش جدید ' . $order['code'] . ' — ' . money((int) $order['total']) . ' تومان';

    switch ($cfg['provider']) {
        case 'kavenegar':
            notify_post(
                'https://api.kavenegar.com/v1/' . $cfg['api_key'] . '/sms/send.json',
                ['receptor' => $cfg['to'], 'sender' => $cfg['sender'] ?? '', 'message' => $text]
            );
            break;

        case 'smsir':
            notify_post(
                'https://api.sms.ir/v1/send/bulk',
                ['lineNumber' => $cfg['sender'] ?? '', 'messageText' => $text,
                 'mobiles' => [$cfg['to']]],
                ['Content-Type: application/json', 'x-api-key: ' . $cfg['api_key']],
                true
            );
            break;

        default:
            error_log('[suppex] unknown SMS provider: ' . (string) $cfg['provider']);
    }
}

/**
 * Fire-and-forget HTTP POST.
 *
 * Notification failures are logged and swallowed on purpose. If Telegram is
 * unreachable or the SMS credit has run out, the order is already safely in
 * the database — turning that into an error page would lose a sale over a
 * side-effect that was never essential to it.
 */
function notify_post(string $url, array $payload, array $headers = [], bool $asJson = false): void
{
    if (!function_exists('curl_init')) {
        error_log('[suppex] cURL is not available; notification skipped');
        return;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $asJson ? json_encode($payload, JSON_UNESCAPED_UNICODE) : $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false || $status >= 400) {
        /* The provider's own reason, not just the status. Telegram answers a
           dead chat_id with 400 and "group chat was upgraded to a supergroup
           chat" - which is the failure that will actually happen, because
           converting a group changes its id and nothing here would otherwise
           say so. A bare "400" sends whoever reads the log looking at the
           token instead. */
        $why = curl_error($ch);
        if ($why === '' && is_string($response)) {
            $body = json_decode($response, true);
            $why  = is_array($body) && isset($body['description'])
                ? (string) $body['description']
                : mb_substr((string) $response, 0, 200);
        }
        error_log('[suppex] notification failed (' . $status . '): ' . $why);
    }
    curl_close($ch);
}

function notify_new_order(array $order): void
{
    try {
        notify_telegram($order);
        notify_sms($order);
    } catch (Throwable $e) {
        error_log('[suppex] notification threw: ' . $e->getMessage());
    }
}

/* --- Telegram, with the answer ---------------------------------------------
   notify_post() above is fire-and-forget: a new-order push that fails costs
   a log line and nothing else. These return what Telegram said, because the
   panel shows it - a receipt that did not reach the group is marked «not
   sent» with a button to try again, and the group finder lists what it
   found. */

/**
 * Call one Bot API method. The decoded answer when Telegram accepted the
 * call, null when anything failed - the reason goes to the log in Telegram's
 * own words, because «400» alone sends whoever reads it looking at the token.
 */
function telegram_call(string $token, string $method, array $fields = [], int $timeout = 12): ?array
{
    if ($token === '' || !function_exists('curl_init')) {
        return null;
    }
    /* 'telegram_api' in the config points these calls at a stand-in server;
       the tests use one to see exactly what would have been sent. */
    $base = rtrim((string) (suppex_config()['telegram_api'] ?? 'https://api.telegram.org'), '/');
    $ch = curl_init($base . '/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    $body = is_string($response) ? json_decode($response, true) : null;
    if ($response === false || $status >= 400 || !is_array($body) || empty($body['ok'])) {
        $why = $error !== ''
            ? $error
            : (is_array($body) && isset($body['description'])
                ? (string) $body['description']
                : mb_substr((string) $response, 0, 200));
        error_log('[suppex] telegram ' . $method . ' failed (' . $status . '): ' . $why);
        return null;
    }
    return $body;
}

/** A photograph with a caption. Whether Telegram took it. */
function telegram_send_photo(string $token, string $chatId, string $path, string $caption): bool
{
    if (!class_exists('CURLFile') || !is_file($path)) {
        return false;
    }
    return telegram_call($token, 'sendPhoto', [
        'chat_id' => $chatId,
        'caption' => $caption,
        'photo'   => new CURLFile($path, 'image/jpeg', 'receipt.jpg'),
    ], 30) !== null;
}

/** A line of text. Whether Telegram took it. */
function telegram_send_text(string $token, string $chatId, string $text): bool
{
    return telegram_call($token, 'sendMessage', ['chat_id' => $chatId, 'text' => $text]) !== null;
}

/**
 * The groups the bot has been added to lately, read from its update queue.
 * Adding a bot to a group always leaves an update, whatever its privacy
 * setting. Telegram keeps updates for about a day, so add the bot and look
 * here the same day.
 *
 * @return array<int,array{id:string,title:string}>|null  null: Telegram could not be asked
 */
function telegram_recent_groups(string $token): ?array
{
    $body = telegram_call($token, 'getUpdates', [
        'allowed_updates' => json_encode(['message', 'my_chat_member']),
    ]);
    if ($body === null) {
        return null;
    }
    $found = [];
    foreach ((array) ($body['result'] ?? []) as $update) {
        foreach (['message', 'my_chat_member', 'edited_message'] as $kind) {
            $chat = $update[$kind]['chat'] ?? null;
            if (!is_array($chat) || !in_array($chat['type'] ?? '', ['group', 'supergroup'], true)) {
                continue;
            }
            $found[(string) $chat['id']] = (string) ($chat['title'] ?? '');
        }
    }
    $groups = [];
    foreach ($found as $id => $title) {
        $groups[] = ['id' => (string) $id, 'title' => $title];
    }
    return $groups;
}
