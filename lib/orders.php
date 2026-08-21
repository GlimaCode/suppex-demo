<?php
/* ===========================================================================
   SUPPEX — Orders
   ---------------------------------------------------------------------------
   Creates an order from what the browser posts, and reads orders back for the
   admin panel.

   The single most important rule in this file: THE CLIENT'S PRICES ARE NEVER
   USED. The browser sends slugs, variant ids and quantities; every price, the
   shipping rule and the total are looked up and recomputed here. A cart lives
   in localStorage where anyone can edit it, so a total that arrives from the
   page is a number the customer chose. Recomputing server-side is also what
   makes the commission figure mean anything — a ledger built from
   client-supplied amounts records whatever the client felt like claiming.
   =========================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/products.php';

const ORDER_STATUSES = ['new', 'paid', 'shipped', 'done', 'cancelled'];

const ORDER_STATUS_FA = [
    'new'       => 'ثبت شده',
    'paid'      => 'پرداخت شده',
    'shipped'   => 'ارسال شده',
    'done'      => 'تکمیل شده',
    'cancelled' => 'لغو شده',
];

/**
 * Resolve one posted line into a priced item, or null if it cannot be trusted.
 *
 * @param array{slug?:string,flavorId?:string,sizeId?:string,qty?:mixed} $line
 */
function order_price_line(array $line): ?array
{
    $slug = clean_text($line['slug'] ?? '', 120);
    if ($slug === '') {
        return null;
    }

    $product = db_one(
        'SELECT id, slug, name_fa, price, variant_label, in_stock
           FROM products WHERE slug = ? AND is_active = 1',
        [$slug]
    );
    if ($product === null || (int) $product['in_stock'] !== 1) {
        return null;
    }

    /* Quantity is clamped, not merely cast. 10,000 tubs of whey is not an
       order, it is either a typo or someone probing the endpoint. */
    $qty = (int) digits_only((string) ($line['qty'] ?? '1'));
    $qty = max(1, min($qty, 99));

    $unitPrice   = (int) $product['price'];
    $variantBits = [];

    $sizeId = clean_text($line['sizeId'] ?? '', 60);
    if ($sizeId !== '') {
        $size = db_one(
            'SELECT label, price FROM product_sizes WHERE product_id = ? AND ext_id = ?',
            [(int) $product['id'], $sizeId]
        );
        if ($size !== null) {
            $unitPrice     = (int) $size['price'];
            $variantBits[] = $size['label'];
        }
    }

    $flavorId = clean_text($line['flavorId'] ?? '', 60);
    if ($flavorId !== '') {
        $flavor = db_one(
            'SELECT name FROM product_flavors WHERE product_id = ? AND ext_id = ?',
            [(int) $product['id'], $flavorId]
        );
        if ($flavor !== null) {
            array_unshift($variantBits, $flavor['name']);
        }
    }

    return [
        'product_id'    => (int) $product['id'],
        'slug'          => $product['slug'],
        'name_fa'       => $product['name_fa'],
        'variant_label' => $variantBits ? implode(' · ', $variantBits) : (string) $product['variant_label'],
        'qty'           => $qty,
        'unit_price'    => $unitPrice,
        'line_total'    => $unitPrice * $qty,
    ];
}

/**
 * @param array $payload  { items: [...], customer: {...}, channel: string }
 * @return array{ok:bool,order?:array,errors?:array<string,string>}
 */
function order_create(array $payload): array
{
    $errors = [];

    /* --- Items ----------------------------------------------------------- */
    $posted = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    if (count($posted) === 0) {
        return ['ok' => false, 'errors' => ['items' => 'سبد خرید خالی است.']];
    }
    if (count($posted) > 50) {
        $posted = array_slice($posted, 0, 50);
    }

    $items = [];
    foreach ($posted as $line) {
        if (!is_array($line)) {
            continue;
        }
        $priced = order_price_line($line);
        if ($priced !== null) {
            $items[] = $priced;
        }
    }
    if (count($items) === 0) {
        return ['ok' => false, 'errors' => ['items' => 'هیچ‌کدام از کالاهای سبد در دسترس نیست.']];
    }

    /* --- Customer --------------------------------------------------------- */
    $c = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];

    $name    = clean_text($c['name'] ?? '', 180);
    $phone   = digits_only((string) ($c['phone'] ?? ''));
    $address = clean_multiline($c['address'] ?? '', 1000);
    $postal  = digits_only((string) ($c['postal'] ?? ''));
    $note    = clean_multiline($c['note'] ?? '', 1000);

    if ($name === '')                  { $errors['name']    = 'نام و نام خانوادگی لازم است.'; }
    if (!is_valid_mobile($phone))      { $errors['phone']   = 'شماره موبایل باید ۱۱ رقم و با ۰۹ شروع شود.'; }
    if (mb_strlen($address) < 10)      { $errors['address'] = 'آدرس دقیق را کامل وارد کنید.'; }
    if (!is_valid_postal($postal))     { $errors['postal']  = 'کد پستی باید دقیقاً ۱۰ رقم باشد.'; }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    /* --- Totals, recomputed from the shop's own rules ---------------------- */
    $subtotal  = array_sum(array_column($items, 'line_total'));
    $threshold = setting_int('free_shipping_threshold', 1500000);
    $flatRate  = setting_int('shipping_flat_rate', 65000);
    $shipping  = ($threshold > 0 && $subtotal >= $threshold) ? 0 : $flatRate;
    $total     = $subtotal + $shipping;

    /* Commission is snapshotted at the rate in force right now. Changing the
       rate later must not rewrite what was owed on past orders — that is the
       difference between a ledger and a guess. It is calculated on goods only,
       not on shipping, because shipping is a cost passed through rather than
       revenue either side earns. */
    $commissionPercent = max(0.0, min(100.0, setting_float('commission_percent', 0)));
    $commissionAmount  = (int) round($subtotal * $commissionPercent / 100);

    $channel = clean_text($payload['channel'] ?? '', 32);

    /* --- Write ------------------------------------------------------------- */
    $pdo = db();
    $pdo->beginTransaction();
    try {
        /* Codes carry four random characters out of a 32-symbol alphabet, so a
           same-day collision is possible if unlikely; the unique index would
           reject it, and retrying is cheaper than widening the code. */
        $orderId = 0;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = generate_order_code();
            try {
                $orderId = db_insert(
                    'INSERT INTO orders
                        (code, status, customer_name, phone, address, postal, note,
                         subtotal, shipping, total, commission_percent, commission_amount,
                         channel, ip, user_agent)
                     VALUES (?, "new", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $code, $name, $phone, $address, $postal, $note,
                        $subtotal, $shipping, $total, $commissionPercent, $commissionAmount,
                        $channel, client_ip_binary(),
                        mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                    ]
                );
                break;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000' || $attempt === 4) {
                    throw $e;
                }
            }
        }

        foreach ($items as $it) {
            db_query(
                'INSERT INTO order_items
                    (order_id, product_id, slug, name_fa, variant_label, qty, unit_price, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$orderId, $it['product_id'], $it['slug'], $it['name_fa'],
                 $it['variant_label'], $it['qty'], $it['unit_price'], $it['line_total']]
            );
        }

        db_query(
            'INSERT INTO order_events (order_id, admin_name, from_status, to_status, note)
             VALUES (?, "", "", "new", "ثبت توسط مشتری")',
            [$orderId]
        );

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[suppex] order_create failed: ' . $e->getMessage());
        return ['ok' => false, 'errors' => ['_' => 'ثبت سفارش با خطا مواجه شد. لطفاً دوباره تلاش کنید.']];
    }

    return ['ok' => true, 'order' => order_get($orderId)];
}

function order_get(int $id): ?array
{
    $o = db_one('SELECT * FROM orders WHERE id = ?', [$id]);
    if ($o === null) {
        return null;
    }
    $o['items']  = db_all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$id]);
    $o['events'] = db_all('SELECT * FROM order_events WHERE order_id = ? ORDER BY id', [$id]);
    return $o;
}

function order_get_by_code(string $code): ?array
{
    $id = db_value('SELECT id FROM orders WHERE code = ?', [$code]);
    return $id === null ? null : order_get((int) $id);
}

/**
 * Change status and record who did it. Returns false if the status is unknown.
 * paid_at is stamped the first time an order reaches "paid" and never moved
 * afterwards, so a later "shipped" does not reset the payment date.
 */
function order_set_status(int $id, string $status, array $admin, string $note = ''): bool
{
    if (!in_array($status, ORDER_STATUSES, true)) {
        return false;
    }
    $current = db_one('SELECT status, paid_at FROM orders WHERE id = ?', [$id]);
    if ($current === null) {
        return false;
    }

    $paidStamp = ($status !== 'new' && $status !== 'cancelled' && $current['paid_at'] === null)
        ? date('Y-m-d H:i:s')
        : $current['paid_at'];

    db_query('UPDATE orders SET status = ?, paid_at = ? WHERE id = ?', [$status, $paidStamp, $id]);
    db_query(
        'INSERT INTO order_events (order_id, admin_id, admin_name, from_status, to_status, note)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$id, $admin['id'] ?? null, $admin['name'] ?? '', $current['status'], $status,
         clean_text($note, 500)]
    );
    return true;
}

/** @param array{status?:string,q?:string,from?:string,to?:string,limit?:int,offset?:int} $f */
function orders_list(array $f = []): array
{
    $sql = 'SELECT * FROM orders WHERE 1';
    $params = [];

    if (!empty($f['status']) && in_array($f['status'], ORDER_STATUSES, true)) {
        $sql .= ' AND status = ?';
        $params[] = $f['status'];
    }
    if (!empty($f['q'])) {
        $needle = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], clean_text($f['q'], 60)) . '%';
        $sql .= ' AND (code LIKE ? OR customer_name LIKE ? OR phone LIKE ?)';
        array_push($params, $needle, $needle, $needle);
    }
    if (!empty($f['from'])) {
        $sql .= ' AND created_at >= ?';
        $params[] = $f['from'] . ' 00:00:00';
    }
    if (!empty($f['to'])) {
        $sql .= ' AND created_at <= ?';
        $params[] = $f['to'] . ' 23:59:59';
    }

    $limit  = max(1, min((int) ($f['limit'] ?? 50), 200));
    $offset = max(0, (int) ($f['offset'] ?? 0));
    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

    return db_all($sql, $params);
}

function orders_count(array $f = []): int
{
    $sql = 'SELECT COUNT(*) FROM orders WHERE 1';
    $params = [];
    if (!empty($f['status']) && in_array($f['status'], ORDER_STATUSES, true)) {
        $sql .= ' AND status = ?';
        $params[] = $f['status'];
    }
    if (!empty($f['q'])) {
        $needle = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], clean_text($f['q'], 60)) . '%';
        $sql .= ' AND (code LIKE ? OR customer_name LIKE ? OR phone LIKE ?)';
        array_push($params, $needle, $needle, $needle);
    }
    if (!empty($f['from'])) { $sql .= ' AND created_at >= ?'; $params[] = $f['from'] . ' 00:00:00'; }
    if (!empty($f['to']))   { $sql .= ' AND created_at <= ?'; $params[] = $f['to'] . ' 23:59:59'; }
    return (int) db_value($sql, $params);
}

/**
 * The settlement report — the number both sides of the partnership look at.
 *
 * Only orders that actually got paid are counted. An order sitting at "new" is
 * a message someone sent, not a sale, and cancelled orders are excluded
 * outright. Commission comes from the per-order snapshot rather than the
 * current rate, so a rate change next month cannot alter last month's figure.
 */
function orders_settlement(string $from, string $to): array
{
    $row = db_one(
        'SELECT COUNT(*)                    AS order_count,
                COALESCE(SUM(subtotal), 0)  AS goods_total,
                COALESCE(SUM(shipping), 0)  AS shipping_total,
                COALESCE(SUM(total), 0)     AS gross_total,
                COALESCE(SUM(commission_amount), 0) AS commission_total
           FROM orders
          WHERE status IN ("paid", "shipped", "done")
            AND created_at >= ? AND created_at <= ?',
        [$from . ' 00:00:00', $to . ' 23:59:59']
    );

    return [
        'from'             => $from,
        'to'               => $to,
        'order_count'      => (int) $row['order_count'],
        'goods_total'      => (int) $row['goods_total'],
        'shipping_total'   => (int) $row['shipping_total'],
        'gross_total'      => (int) $row['gross_total'],
        'commission_total' => (int) $row['commission_total'],
    ];
}
