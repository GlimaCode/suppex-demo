<?php
/* ===========================================================================
   SUPPEX — what the shop sends a customer, with its name and address on it
   ---------------------------------------------------------------------------
   The owner asked on 2026-10-03 for «SUPPEX — suppex.ir» on everything a
   customer receives: the messages in the chat, the invoice in the box, the
   label on the parcel. A Google search for the bare name offers «supplex» and
   two foreign shops in its place; a customer who has the address in their
   chat, on the box and on the slip types it - or searches the name and knows
   the site when they see it. Written here once, so every place that sends
   something ends the same way.

   The messages say what happened and where to look, and nothing the shop has
   not already said: the payment is confirmed, the parcel went with this code,
   a review can be written on the tracking page.
   =========================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/seo.php';

/** «suppex.ir»: the site's own host, without the scheme or a «www.». */
function brand_host(): string
{
    $host = (string) parse_url(seo_origin(), PHP_URL_HOST);
    $host = (string) preg_replace('/^www\./i', '', $host);
    return $host !== '' ? $host : 'suppex.ir';
}

/** The name the shop signs with: the panel's «نام فروشگاه», else SUPPEX. */
function brand_name(): string
{
    $name = trim((string) setting('shop_name', 'SUPPEX'));
    return $name !== '' ? $name : 'SUPPEX';
}

/** «SUPPEX — suppex.ir»: the last line of everything a customer receives. */
function brand_signature(): string
{
    return brand_name() . ' — ' . brand_host();
}

/** Where a customer looks an order up, as it is typed: suppex.ir/track.php */
function brand_track_url(): string
{
    return brand_host() . '/track.php';
}

/**
 * The order as a message: what is in it, what it costs and where it goes. For
 * when the customer's own paste never happened (admin/order.php).
 */
function order_message_details(array $order): string
{
    $lines = ['سفارش ' . $order['code'], ''];
    foreach ($order['items'] as $i => $item) {
        $lines[] = ($i + 1) . ') ' . $item['name_fa'];
        if ((string) $item['variant_label'] !== '') {
            $lines[] = '   ' . $item['variant_label'];
        }
        $lines[] = '   ' . $item['qty'] . ' × ' . money((int) $item['unit_price'])
                 . ' = ' . money((int) $item['line_total']) . ' تومان';
    }
    $lines[] = '';
    $lines[] = 'مبلغ قابل پرداخت: ' . money((int) $order['total']) . ' تومان';
    $lines[] = '';
    $lines[] = 'گیرنده: ' . $order['customer_name'];
    $lines[] = 'تماس: ' . $order['phone'];
    $lines[] = 'آدرس: ' . $order['address'];
    $lines[] = 'کد پستی: ' . $order['postal'];
    $lines[] = '';
    $lines[] = brand_signature();
    return implode("\n", $lines);
}

/**
 * A ready message for each moment of an order, the one for where it stands
 * now first: the owner changes the status, and the message to send about it is
 * at the top of the card, signed.
 *
 * @return list<array{key:string,label:string,text:string,now:bool}>
 */
function order_messages(array $order): array
{
    $name     = trim((string) $order['customer_name']);
    $hello    = 'سلام' . ($name !== '' ? ' ' . $name . ' عزیز' : '');
    $code     = (string) $order['code'];
    $track    = 'پیگیری سفارش: ' . brand_track_url() . ' (با شماره سفارش و شماره موبایلتان)';
    $tracking = trim((string) ($order['tracking_code'] ?? ''));

    $all = [
        'new' => ['label' => 'مشخصات سفارش', 'text' => order_message_details($order)],
        'paid' => ['label' => 'تأیید پرداخت', 'text' => implode("\n", [
            $hello, 'پرداخت سفارش ' . $code . ' تأیید شد. ✅', '', $track, '', brand_signature(),
        ])],
        'shipped' => ['label' => 'ارسال مرسوله', 'text' => implode("\n", array_merge(
            [$hello, 'سفارش ' . $code . ' ارسال شد. 📦'],
            $tracking !== '' ? ['کد رهگیری مرسوله: ' . $tracking] : [],
            ['', $track, '', brand_signature()]
        ))],
        'done' => ['label' => 'درخواست نظر', 'text' => implode("\n", [
            $hello, 'امیدواریم از سفارش ' . $code . ' راضی باشید.',
            'اگر دوست داشتید، نظرتان درباره محصول را در صفحه پیگیری سفارش بنویسید: ' . brand_track_url(),
            '', brand_signature(),
        ])],
    ];

    /* A cancelled or expired order has nothing to announce; its details are
       still worth having to hand. */
    $status = (string) $order['status'];
    $first  = isset($all[$status]) ? $status : 'new';

    $out = [];
    foreach (array_merge([$first], array_diff(array_keys($all), [$first])) as $key) {
        $out[] = ['key' => $key, 'label' => $all[$key]['label'], 'text' => $all[$key]['text'],
                  'now' => $key === $first && isset($all[$status])];
    }
    return $out;
}
