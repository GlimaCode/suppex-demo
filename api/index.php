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
       POST ?r=receipt         a payment receipt for an order: code, phone, image
       POST ?r=myorders        the live state of orders a browser remembers

   Endpoints are read-only apart from ?r=order, and nothing here can reach the
   admin tables.
   =========================================================================== */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/products.php';
require_once SUPPEX_ROOT . '/lib/orders.php';
require_once SUPPEX_ROOT . '/lib/track.php';
require_once SUPPEX_ROOT . '/lib/variants.php';
require_once SUPPEX_ROOT . '/lib/quotes.php';
require_once SUPPEX_ROOT . '/lib/notify.php';
require_once SUPPEX_ROOT . '/lib/receipts.php';
require_once SUPPEX_ROOT . '/lib/seo.php';
require_once SUPPEX_ROOT . '/lib/inquiries.php';

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
        } elseif (function_exists('litespeed_finish_request')) {
            /* The same thing under LiteSpeed, which many cPanel hosts run in
               place of PHP-FPM. Without it the customer waited for Telegram
               to answer before seeing «رسید دریافت شد». */
            litespeed_finish_request();
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

        case 'inquirystate':
            if ($method !== 'POST') { api_error('Method not allowed.', 405); }
            if (!inquiry_same_origin()) { api_error('Forbidden.', 403); }
            $body = api_json_body();
            $inquiry = inquiry_find((string) ($body['code'] ?? ''), (string) ($body['phone'] ?? ''));
            api_send($inquiry === null ? ['ok' => false] : inquiry_public($inquiry));

        case 'products':
            api_send(products_list([
                'category'    => $_GET['category']    ?? null,
                'notCategory' => $_GET['notCategory'] ?? null,
                'badge'       => $_GET['badge']       ?? null,
                'exclude'     => $_GET['exclude']     ?? null,
                'brand'       => $_GET['brand']       ?? null,
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

        /* The logo wall at the foot of the home page (lib/brands.php): brands
           with something on sale, in the owner's order. Five minutes, like
           the categories - a logo changed in the panel is not urgent. */
        case 'brands':
            api_send(array_map('brand_api', brands_list()), 200, 300);

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
            /* Everything quoted in this answer, so the shop can be held to it.
               See lib/quotes.php: the chat window opens before the order is
               recorded, so the price shown here is the one the customer's own
               message will carry. */
            $quoted = [];
            foreach ($rows as $r) {
                $parentStock = $r['stock_qty'] === null ? null : (int) $r['stock_qty'];
                $sizes = [];
                foreach (db_all('SELECT ext_id, price, compare_at, stock_qty FROM product_sizes
                                  WHERE product_id = ? ORDER BY sort_order, id',
                                [(int) $r['id']]) as $sz) {
                    /* Same fallback as everywhere else: the size's own count,
                       or the product's if the size has none. */
                    $sizeStock = $sz['stock_qty'] !== null ? (int) $sz['stock_qty'] : $parentStock;
                    $quoted[quote_key((string) $r['slug'], (string) $sz['ext_id'])] = (int) $sz['price'];
                    $sizes[] = [
                        'id'        => $sz['ext_id'],
                        'price'     => (int) $sz['price'],
                        'compareAt' => $sz['compare_at'] === null ? null : (int) $sz['compare_at'],
                        'stock'     => $sizeStock === null ? null : max(0, $sizeStock),
                    ];
                }
                $quoted[quote_key((string) $r['slug'], '')] = (int) $r['price'];
                /* Flavour premiums are quoted separately from the base, so a
                   product with four sizes and ten flavours costs fourteen
                   entries rather than forty. lib/quotes.php explains why. */
                foreach (db_all('SELECT ext_id, price_delta_toman FROM product_flavors
                                  WHERE product_id = ? AND price_delta_toman <> 0',
                                [(int) $r['id']]) as $fl) {
                    $quoted[quote_flavor_key((string) $r['slug'], (string) $fl['ext_id'])]
                        = (int) $fl['price_delta_toman'];
                }
                /* The cart needs these to keep showing the right line total
                   for a flavour that costs more, the same way it re-reads the
                   size prices. */
                $flavorDeltas = [];
                foreach (db_all('SELECT ext_id, price_delta_toman FROM product_flavors
                                  WHERE product_id = ?', [(int) $r['id']]) as $fl) {
                    $flavorDeltas[] = ['id' => $fl['ext_id'],
                                       'priceDelta' => (int) $fl['price_delta_toman']];
                }
                /* A product that prices itself from variants quotes them
                   instead of its sizes and premiums: one key for the whole
                   combination, because there is no base and delta left to add
                   back together. The sizes above are quoted anyway and cost
                   nothing - a converted product simply never asks for them. */
                $variantRows = [];
                $allVariantRows = variants_list((int) $r['id']);
                foreach ($allVariantRows as $vr) {
                    if ((int) $vr['is_active'] !== 1 || (int) $vr['price'] <= 0
                        || variant_requires_inquiry($vr)) {
                        /* An unpriced or switched-off combination is quoted at
                           nothing, so a basket holding it is corrected here
                           rather than refused at checkout. */
                        continue;
                    }
                    $quoted[quote_key((string) $r['slug'], (string) $vr['ext_id'])]
                        = (int) $vr['price'];
                    $variantRows[] = [
                        'id'        => (string) $vr['ext_id'],
                        'price'     => (int) $vr['price'],
                        'compareAt' => $vr['compare_at'] === null ? null : (int) $vr['compare_at'],
                        'stock'     => $vr['stock_qty'] === null ? null : max(0, (int) $vr['stock_qty']),
                        'image'     => (string) $vr['image'],
                        /* The choice this row stands for. The cart matches on
                           this rather than on the key, so leaving it out made
                           every line of a converted product fail to match -
                           and the drawer marked them all unavailable. Built
                           exactly as product_get() builds it. */
                        'choice'    => (function (array $vals): array {
                            $out = [];
                            foreach ($vals as $val) {
                                $out[(string) $val['attr_ext']] = (string) $val['ext_id'];
                            }
                            return $out;
                        })($vr['values']),
                    ];
                }

                $out[] = [
                    'slug'      => $r['slug'],
                    'price'     => (int) $r['price'],
                    'flavors'   => $flavorDeltas,
                    'variants'  => $variantRows,
                    'compareAt' => $r['compare_at'] === null ? null : (int) $r['compare_at'],
                    /* A product hidden since the item was added is treated as
                       unavailable rather than omitted, so the cart can say so
                       instead of silently dropping a line. */
                    'available' => (int) $r['is_active'] === 1 && (int) $r['in_stock'] === 1
                        && ($allVariantRows ? (bool) array_filter($variantRows,
                            static fn(array $v): bool => $v['stock'] === null || $v['stock'] > 0)
                            : product_row_available($r)),
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
            /* This changes the shape of the answer from a bare list to an
               object, so repo.js reads .prices now. The two ship together, and
               a stale cached copy of the script degrades safely rather than
               breaking: repo.getPrices() already swallows anything unexpected
               and returns null, and a null reconcile leaves the cart on the
               prices it had — which is exactly where it stood before quotes
               existed. */
            api_send(['prices' => $out, 'quote' => quote_issue($quoted)], 200, 0);

        /* Has anything happened to my order?

           POST, not GET: the phone number would otherwise sit in the URL, in
           browser history and in every access log between here and the host -
           the same reason track.php posts its form. */
        case 'orderstate':
            if ($method !== 'POST') {
                api_error('Method not allowed.', 405);
            }
            if (track_is_throttled()) {
                api_error('درخواست‌ها زیاد بوده است. کمی بعد دوباره تلاش کنید.', 429);
            }

            /* Same opportunistic sweep track.php runs: a hold that closed an
               hour ago must not still answer "new". */
            orders_expire_due();

            $stateBody = api_json_body();
            $stateOrder = track_find(
                (string) ($stateBody['code'] ?? ''),
                (string) ($stateBody['phone'] ?? '')
            );

            /* One answer for every kind of miss, and a 200 rather than a 404:
               a different status code would confirm that a code exists.

               And only a miss counts toward the throttle, as in ?r=myorders.
               This is asked on every page a customer opens for two weeks
               after ordering, with the code and phone their own browser
               kept: counted, twelve pages in ten minutes locked them out of
               their own order - and out of sending its receipt, which checks
               the same throttle. A guess is a miss, so guessing is held to
               exactly what it was. */
            if ($stateOrder === null) {
                track_record_attempt();
                api_send(['ok' => false], 200, 0);
            }

            api_send([
                'ok'        => true,
                'status'    => (string) $stateOrder['status'],
                /* Milliseconds, and the server's own clock beside it, so a
                   phone set an hour fast does not show the hold as dead. */
                'expiresAt' => $stateOrder['expires_at'] === null
                    ? null : strtotime((string) $stateOrder['expires_at']) * 1000,
                'serverNow' => time() * 1000,
                /* The bar that asks for payment stops once a receipt is in. */
                'receipts'  => receipt_count((int) $stateOrder['id']),
            ], 200, 0);

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
                    /* The same answer the first send would have had - code,
                       hold and card - when that order is still open. Without
                       them a customer whose first response was lost saw
                       «تا آن موقع مبلغی واریز نکنید» beside «واریز کردید؟» and
                       no clock. The card goes out on the same terms as before:
                       to the phone that placed this very order a minute ago. */
                    $open = (string) $existing['status'] === 'new'
                        && ($existing['expires_at'] === null
                            || strtotime((string) $existing['expires_at']) > time());
                    $again = [
                        'ok'        => true,
                        'code'      => $existing['code'],
                        'total'     => (int) $existing['total'],
                        'duplicate' => true,
                    ];
                    if ($open) {
                        $again['expiresAt']   = $existing['expires_at'] === null
                            ? null : strtotime((string) $existing['expires_at']) * 1000;
                        $again['serverNow']   = time() * 1000;
                        $again['holdMinutes'] = order_hold_minutes();
                        $again['payment']     = [
                            'cardNumber' => setting('card_number'),
                            'cardHolder' => setting('card_holder'),
                            'bankName'   => setting('bank_name'),
                        ];
                    }
                    api_send($again);
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

        case 'myorders':
            if ($method !== 'POST') {
                api_error('Method not allowed.', 405);
            }
            /* «سفارش‌های من» on the tracking page: every order this browser
               placed or looked up, sent back as code-and-phone pairs it already
               holds. A batch of pairs is a batch of guesses in the wrong hands,
               so only a MISS counts toward the lookup throttle - a customer's
               own list never misses - and the loop stops the moment the
               throttle is reached, which keeps a batch to the same twelve
               guesses the form allows. */
            if (track_is_throttled()) {
                api_error('درخواست‌ها زیاد بوده است. کمی بعد دوباره تلاش کنید.', 429);
            }
            orders_expire_due();
            $pairs = api_json_body()['orders'] ?? [];
            $pairs = is_array($pairs) ? array_slice(array_values($pairs), 0, 50) : [];
            $mine  = [];
            foreach ($pairs as $pair) {
                if (!is_array($pair)) {
                    continue;
                }
                $found = track_find((string) ($pair['code'] ?? ''), (string) ($pair['phone'] ?? ''));
                if ($found === null) {
                    track_record_attempt();
                    if (track_is_throttled()) {
                        break;
                    }
                    continue;
                }
                $items = $found['items'] ?? [];
                $mine[] = [
                    'code'         => (string) $found['code'],
                    'status'       => (string) $found['status'],
                    'statusFa'     => ORDER_STATUS_FA[(string) $found['status']] ?? (string) $found['status'],
                    'total'        => (int) $found['total'],
                    'createdAt'    => strtotime((string) $found['created_at']) * 1000,
                    'trackingCode' => trim((string) ($found['tracking_code'] ?? '')),
                    /* Enough to recognise the order by, not the whole of it:
                       the first thing in it and how many more. */
                    'summary'      => isset($items[0]['name_fa']) ? (string) $items[0]['name_fa'] : '',
                    'more'         => max(0, count($items) - 1),
                ];
            }
            api_send(['ok' => true, 'orders' => $mine], 200, 0);

        case 'receipt':
            if ($method !== 'POST') {
                api_error('Method not allowed.', 405);
            }
            /* Both throttles. The lookup inside is a code-and-phone guess, the
               same as ?r=orderstate, and every attempt is a file coming in. */
            if (track_is_throttled() || receipt_is_throttled()) {
                api_error('درخواست‌ها زیاد بوده است. کمی بعد دوباره تلاش کنید.', 429);
            }
            if (!receipts_ready()) {
                api_send(['ok' => false,
                    'error' => 'ارسال رسید از سایت هنوز فعال نشده است. رسید را در همان گفتگو بفرستید.'], 200, 0);
            }
            /* Every upload counts toward the receipt throttle; only a miss
               toward the lookup one, as in ?r=orderstate. */
            receipt_record_attempt();
            orders_expire_due();

            /* One answer for a wrong code and a wrong phone, as everywhere. */
            $receiptOrder = track_find((string) ($_POST['code'] ?? ''), (string) ($_POST['phone'] ?? ''));
            if ($receiptOrder === null) {
                track_record_attempt();
                api_send(['ok' => false,
                    'error' => 'سفارشی با این شماره پیگیری و این شماره موبایل پیدا نشد.'], 200, 0);
            }
            if (!receipt_accepts($receiptOrder)) {
                api_send(['ok' => false,
                    'error' => 'این سفارش دیگر رسید نمی‌پذیرد. اگر واریز کرده‌اید، در همان گفتگو خبر بدهید.'], 200, 0);
            }
            if (receipt_count((int) $receiptOrder['id']) >= RECEIPT_PER_ORDER) {
                api_send(['ok' => false,
                    'error' => 'برای این سفارش به اندازهٔ کافی رسید رسیده است. اگر چیزی مانده، در همان گفتگو بفرستید.'], 200, 0);
            }

            $stored = receipt_store(is_array($_FILES['receipt'] ?? null) ? $_FILES['receipt'] : []);
            if (!$stored['ok']) {
                api_send(['ok' => false, 'error' => (string) $stored['error']], 200, 0);
            }
            $receiptOrigin = seo_origin();

            /* The same picture again is the receipt already filed: not kept
               twice, and not sent to the group twice. It does go out again
               to wherever the first copy did not reach - receipt_notify()
               skips every chat that has it - so a customer re-sending because
               nothing seemed to happen is a retry, not a duplicate. */
            $receiptSame = receipt_duplicate_of((int) $receiptOrder['id'], (string) $stored['file']);
            if ($receiptSame !== null) {
                receipt_discard((string) $stored['file']);
                api_send([
                    'ok'        => true,
                    'duplicate' => true,
                    'count'     => receipt_count((int) $receiptOrder['id']),
                ], 200, 0, static function () use ($receiptOrder, $receiptSame, $receiptOrigin): void {
                    receipt_notify($receiptOrder, $receiptSame, $receiptOrigin);
                });
            }

            $receiptRow = receipt_get(receipt_add((int) $receiptOrder['id'], $stored));

            /* Telegram after the answer, like the new-order push: the customer
               sees «received» the moment the file is safe on the server. */
            api_send([
                'ok'    => true,
                'count' => receipt_count((int) $receiptOrder['id']),
            ], 201, 0, static function () use ($receiptOrder, $receiptRow, $receiptOrigin): void {
                if ($receiptRow !== null) {
                    receipt_notify($receiptOrder, $receiptRow, $receiptOrigin);
                }
            });

        default:
            api_error('Unknown route.', 404);
    }
} catch (Throwable $e) {
    error_log('[suppex] api error on route "' . $route . '": ' . $e->getMessage());
    api_error('خطای داخلی سرور.', 500);
}
