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
        error_log('[suppex] notification failed (' . $status . '): ' . curl_error($ch));
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
