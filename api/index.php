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
/**
 * Send the response and stop.
 *
 * $after runs AFTER the customer's browser has the answer and the connection
 * is closed. Only one caller needs it - the order route, which notifies the
 * owner - but that one matters: the notification is an HTTP call to a third
 * party, and on an Iranian host the Telegram API is blocked at the network
 * level, so it does not merely take a moment, it burns its full timeout every
 * time. Doing it before the response meant the customer waited six seconds for
 * an order code at the exact moment they were deciding whether to trust the
 * shop with a bank transfer.
 *
 * fastcgi_finish_request() is the real mechanism and exists under PHP-FPM and
 * LiteSpeed, which between them are almost all cPanel hosting. Where it does
 * not exist the work still runs - just in the old order, which is no worse
 * than before.
 */
function api_send($data, int $status = 200, int $cacheSeconds = 0, ?callable $after = null): void
{
    http_response_code($status);
    header($cacheSeconds > 0
        ? 'Cache-Control: public, max-age=' . $cacheSeconds
        : 'Cache-Control: no-store');

    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    header('Content-Length: ' . strlen((string) $body));
    echo $body;

    if ($after !== null) {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            /* Best effort elsewhere: flush what we have so the bytes are at
               least on their way before the slow call starts. */
            if (ob_get_level() > 0) { @ob_end_flush(); }
            @flush();
        }
        try {
            $after();
        } catch (Throwable $e) {
            /* Nothing can be reported now - the response has already gone. */
            error_log('[suppex] post-response task failed: ' . $e->getMessage());
        }
    }
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
            /* One line, because the payload is a value now rather than a
               statement. It lived inline and ended in api_send(), which exits,
               so nothing could assert on it - which is how this route came to
               exist for months with no caller and no test. */
            api_send(storefront_config(), 200, 60);

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
                'SELECT id, slug, price, compare_at, in_stock, stock_qty, is_active,
                        (SELECT COUNT(*) FROM product_sizes s
                          WHERE s.product_id = products.id) AS sizes_total,
                        (SELECT COUNT(s.stock_qty) FROM product_sizes s
                          WHERE s.product_id = products.id) AS sizes_counted,
                        (SELECT COUNT(*) FROM product_sizes s
                          WHERE s.product_id = products.id AND s.stock_qty > 0) AS sizes_available
                   FROM products WHERE slug IN (' . $in . ')',
                $slugs
            );

            $out = [];
            foreach ($rows as $r) {
                $parentStock = $r['stock_qty'] === null ? null : (int) $r['stock_qty'];
                $sizes = [];
                foreach (db_all('SELECT ext_id, price, compare_at, stock_qty FROM product_sizes
                                  WHERE product_id = ? ORDER BY sort_order, id',
                                [(int) $r['id']]) as $sz) {
                    /* Same fallback as everywhere else: the size's own count,
                       or the product's if the size has none. */
                    $sizeStock = $sz['stock_qty'] !== null ? (int) $sz['stock_qty'] : $parentStock;
                    $sizes[] = [
                        'id'        => $sz['ext_id'],
                        'price'     => (int) $sz['price'],
                        'compareAt' => $sz['compare_at'] === null ? null : (int) $sz['compare_at'],
                        'stock'     => $sizeStock === null ? null : max(0, $sizeStock),
                    ];
                }
                $out[] = [
                    'slug'      => $r['slug'],
                    'price'     => (int) $r['price'],
                    'compareAt' => $r['compare_at'] === null ? null : (int) $r['compare_at'],
                    /* A product hidden since the item was added is treated as
                       unavailable rather than omitted, so the cart can say so
                       instead of silently dropping a line. */
                    'available' => ((int) $r['is_active'] === 1 && product_row_available($r)),
                    /* How many are left, so a cart holding three of the last
                       one is corrected here rather than at checkout, after the
                       customer has typed their address. null = uncounted.

                       Not capped at the low threshold the way the catalogue's
                       is: this route answers about items already in somebody's
                       basket, and trimming a quantity of five needs the real
                       number to trim it to. */
                    'stock'     => ((int) $r['sizes_total'] > 0 || $parentStock === null)
                        ? null : max(0, $parentStock),
                    'sizes'     => $sizes,
                ];
            }
            api_send($out, 200, 0);

        case 'order':
            if ($method !== 'POST') {
                api_error('Method not allowed.', 405);
            }

            /* The only public route that costs money per request. Neither
               guard existed: five identical POSTs in five seconds all returned
               201 with fresh codes, each sending a Telegram message and, with
               SMS on, a billed one. */
            if (order_is_throttled()) {
                api_error('تعداد سفارش‌های شما در این مدت زیاد بوده است. کمی بعد دوباره تلاش کنید.', 429);
            }

            $body = api_json_body();

            /* A double-tapped button is one intention. Return the order they
               already have rather than an error - they wanted an order, and the
               code is what they need. */
            $phoneIn = digits_only((string) (($body['customer']['phone'] ?? '')));
            $lines   = is_array($body['items'] ?? null) ? $body['items'] : [];
            $dupe    = order_recent_duplicate($phoneIn, $lines);
            if ($dupe !== null) {
                $existing = order_get((int) $dupe['id']);
                if ($existing !== null) {
                    api_send([
                        'ok'        => true,
                        'code'      => $existing['code'],
                        'total'     => (int) $existing['total'],
                        'duplicate' => true,
                    ]);
                }
            }

            order_record_attempt();
            $result = order_create($body);
            if (!$result['ok']) {
                api_error('اطلاعات سفارش کامل نیست.', 422, ['fields' => $result['errors']]);
            }

            $order = $result['order'];

            /* The notification moved BELOW the response - see the $after
               parameter on api_send(). It used to run here, and every order
               waited on it. */

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
            /* Notified after the customer has their code and the connection is
               closed. See api_send(): on an Iranian host the Telegram API is
               blocked outright, so this call burns its whole timeout every
               time - which is fine once nobody is waiting on it. */
            ], 201, 0, static function () use ($order): void {
                notify_new_order($order);
            });

        default:
            api_error('Unknown route.', 404);
    }
} catch (Throwable $e) {
    error_log('[suppex] api error on route "' . $route . '": ' . $e->getMessage());
    api_error('خطای داخلی سرور.', 500);
}
