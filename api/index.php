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
       GET  ?r=prices&slugs=a,b,c   current prices, to refresh a stale cart
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

        /* Current prices for what is sitting in someone's cart.

           The cart lives in localStorage and keeps the price it saw when the
           item was added. With a dirham-linked catalogue that price is stale
           within days, and the order message the customer pastes into chat is
           built from it — so they would send one figure while the server
           recorded another. One request, keyed by slug, is enough to
           reconcile the whole cart before checkout. */
        case 'prices':
            $slugs = array_slice(array_filter(array_map(
                static fn($s) => clean_text($s, 120),
                explode(',', (string) ($_GET['slugs'] ?? ''))
            )), 0, 50);

            if (!$slugs) {
                api_send([], 200, 0);
            }

            $in   = implode(',', array_fill(0, count($slugs), '?'));
            $rows = db_all(
                'SELECT id, slug, price, compare_at, in_stock, is_active
                   FROM products WHERE slug IN (' . $in . ')',
                $slugs
            );

            $out = [];
            foreach ($rows as $r) {
                $sizes = [];
                foreach (db_all('SELECT ext_id, price, compare_at FROM product_sizes
                                  WHERE product_id = ? ORDER BY sort_order, id',
                                [(int) $r['id']]) as $sz) {
                    $sizes[] = [
                        'id'        => $sz['ext_id'],
                        'price'     => (int) $sz['price'],
                        'compareAt' => $sz['compare_at'] === null ? null : (int) $sz['compare_at'],
                    ];
                }
                $out[] = [
                    'slug'      => $r['slug'],
                    'price'     => (int) $r['price'],
                    'compareAt' => $r['compare_at'] === null ? null : (int) $r['compare_at'],
                    /* A product hidden since the item was added is treated as
                       unavailable rather than omitted, so the cart can say so
                       instead of silently dropping a line. */
                    'available' => ((int) $r['is_active'] === 1 && (int) $r['in_stock'] === 1),
                    'sizes'     => $sizes,
                ];
            }
            api_send($out, 200, 0);

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
                /* Milliseconds since the epoch, so the page can count down
                   without parsing a date or guessing a timezone. The server's
                   clock is the authority: a phone set an hour fast would
                   otherwise show the quote as already dead. */
                'expiresAt'  => $order['expires_at'] === null
                    ? null : strtotime($order['expires_at']) * 1000,
                'serverNow'  => time() * 1000,
                'holdMinutes' => order_hold_minutes(),
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
