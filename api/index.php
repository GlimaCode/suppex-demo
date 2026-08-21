<?php
/* ===========================================================================
   SUPPEX — Public API
   ---------------------------------------------------------------------------
   One entry point, routed by ?r=. A front controller rather than a directory
   of scripts because it keeps the bootstrap, the JSON envelope and the header
   policy in exactly one place — with separate files each new endpoint is a
   fresh chance to forget one of them.

   Routes:
       GET  ?r=products[&category=&badge=&limit=&exclude=&notCategory=]
       GET  ?r=product&slug=…
       GET  ?r=categories
       GET  ?r=search&q=…
       GET  ?r=config          storefront settings (card details excluded)
       POST ?r=order           create an order, returns code + payment details

   Endpoints are read-only apart from ?r=order, and nothing here can reach the
   admin tables.
   =========================================================================== */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/products.php';
require_once SUPPEX_ROOT . '/lib/orders.php';
require_once SUPPEX_ROOT . '/lib/notify.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* Catalogue responses are safe to cache briefly; an order response never is. */
function api_send($data, int $status = 200, int $cacheSeconds = 0): void
{
    http_response_code($status);
    header($cacheSeconds > 0
        ? 'Cache-Control: public, max-age=' . $cacheSeconds
        : 'Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(string $message, int $status = 400, array $extra = []): void
{
    api_send(array_merge(['error' => $message], $extra), $status);
}

/** Accepts a JSON body, falling back to a form post. */
function api_json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}

$route  = $_GET['r'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($route) {

        case 'products':
            api_send(products_list([
                'category'    => $_GET['category']    ?? null,
                'notCategory' => $_GET['notCategory'] ?? null,
                'badge'       => $_GET['badge']       ?? null,
                'exclude'     => $_GET['exclude']     ?? null,
                'limit'       => $_GET['limit']       ?? null,
            ]), 200, 60);

        case 'product':
            $product = product_get(clean_text($_GET['slug'] ?? '', 120));
            if ($product === null) {
                api_error('محصول پیدا نشد.', 404);
            }
            api_send($product, 200, 60);

        case 'categories':
            api_send(categories_list(), 200, 300);

        case 'search':
            api_send(products_search((string) ($_GET['q'] ?? '')), 200, 30);

        /* Storefront-safe settings only. The card number is deliberately NOT
           here: it is returned once, with the order that was actually placed.
           Publishing it on an open endpoint would let anyone scrape it and
           reuse it in a scam that the shop then gets blamed for. */
        case 'config':
            api_send([
                'freeShippingThreshold' => setting_int('free_shipping_threshold', 1500000),
                'shippingFlatRate'      => setting_int('shipping_flat_rate', 65000),
                'saleMode'              => setting_bool('sale_mode', true),
                'channels'              => array_values(array_filter([
                    setting('telegram_url') ? ['id' => 'telegram', 'label' => 'تلگرام',
                        'url' => setting('telegram_url'), 'prefillParam' => 'text'] : null,
                    setting('bale_url') ? ['id' => 'bale', 'label' => 'بله',
                        'url' => setting('bale_url'), 'prefillParam' => 'text'] : null,
                    setting('whatsapp_url') ? ['id' => 'whatsapp', 'label' => 'واتس‌اپ',
                        'url' => setting('whatsapp_url'), 'prefillParam' => 'text'] : null,
                ])),
            ], 200, 60);

        case 'order':
            if ($method !== 'POST') {
                api_error('Method not allowed.', 405);
            }

            $result = order_create(api_json_body());
            if (!$result['ok']) {
                api_error('اطلاعات سفارش کامل نیست.', 422, ['fields' => $result['errors']]);
            }

            $order = $result['order'];
            notify_new_order($order);

            /* The card details go out only now, attached to a real order. */
            api_send([
                'ok'      => true,
                'code'    => $order['code'],
                'total'   => (int) $order['total'],
                'payment' => [
                    'cardNumber' => setting('card_number'),
                    'cardHolder' => setting('card_holder'),
                    'bankName'   => setting('bank_name'),
                ],
            ], 201);

        default:
            api_error('Unknown route.', 404);
    }
} catch (Throwable $e) {
    error_log('[suppex] api error on route "' . $route . '": ' . $e->getMessage());
    api_error('خطای داخلی سرور.', 500);
}
