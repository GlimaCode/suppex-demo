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
require_once __DIR__ . '/pricing.php';

const ORDER_STATUSES = ['new', 'paid', 'shipped', 'done', 'cancelled', 'expired'];

const ORDER_STATUS_FA = [
    'new'       => 'ثبت شده',
    'paid'      => 'پرداخت شده',
    'shipped'   => 'ارسال شده',
    'done'      => 'تکمیل شده',
    'cancelled' => 'لغو شده',
    'expired'   => 'منقضی شده',
];

/* How long a quoted price stays good. Clamped rather than trusted: zero would
   expire every order the instant it was placed, and a very long window
   reintroduces the problem the window exists to solve — an order payable
   tomorrow at a rate from today. */
function order_hold_minutes(): int
{
    return max(5, min(setting_int('order_hold_minutes', 30), 180));
}

/**
 * Expire orders whose hold window has passed.
 *
 * Called opportunistically from the pages that read orders, because shared
 * hosting has no cron worth relying on. Only untouched "new" orders are
 * affected — anything an admin has already acted on is left alone, and an
 * order that was paid is never expired however late it was recorded.
 *
 * @return int how many were expired
 */
function orders_expire_due(): int
{
    $due = db_all(
        'SELECT id FROM orders
          WHERE status = "new" AND expires_at IS NOT NULL AND expires_at < NOW()
          LIMIT 200'
    );
    if (!$due) {
        return 0;
    }

    foreach ($due as $row) {
        $id = (int) $row['id'];
        /* The status guard repeats in the UPDATE so a payment confirmed between
           the SELECT and here is not overwritten by the sweep. */
        db_query('UPDATE orders SET status = "expired" WHERE id = ? AND status = "new"', [$id]);
        db_query(
            'INSERT INTO order_events (order_id, admin_name, from_status, to_status, note)
             VALUES (?, "", "new", "expired", ?)',
            [$id, 'مهلت پرداخت تمام شد']
        );
    }
    return count($due);
}

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
        'SELECT id, slug, name_fa, price, variant_label, in_stock,
                cost_toman, profit_toman, price_mode, commission_percent
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

    /* When a size is chosen, BOTH its price and its cost come from the size
       row. Taking the price from the variant and the cost from the parent —
       which an earlier version did — records a 2270g tub's revenue against a
       900g tub's cost, overstating profit on large sizes and flooring it at
       zero on small ones. That figure is what the partnership share is computed
       from, so the mismatch is not cosmetic. */
    $unitCost = (int) ($product['cost_toman'] ?? 0);

    /* The commission rate resolves most-specific first: size, then product,
       then the shop-wide setting. NULL means "not overridden here", which is
       why an unset rate has to stay NULL rather than 0 — a zero override and
       an absent one mean opposite things. */
    $rateOverride = $product['commission_percent'];

    $sizeId = clean_text($line['sizeId'] ?? '', 60);
    if ($sizeId !== '') {
        $size = db_one(
            'SELECT label, price, cost_toman, commission_percent FROM product_sizes
              WHERE product_id = ? AND ext_id = ?',
            [(int) $product['id'], $sizeId]
        );
        if ($size !== null) {
            $unitPrice     = (int) $size['price'];
            $unitCost      = (int) ($size['cost_toman'] ?? 0);
            $variantBits[] = $size['label'];
            if ($size['commission_percent'] !== null) {
                $rateOverride = $size['commission_percent'];
            }
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

    /* Cost and profit are snapshotted alongside the price, because the
       partnership share is computed from them and an old order must keep
       reporting the profit it was actually made at. cost_toman is the dirham
       cost converted at the rate the price was last applied — the rate the shop
       genuinely priced against — not whatever the rate happens to be when a
       report is run next month.

       Profit is derived by subtraction rather than read from profit_toman,
       because a size variant overrides the price but not the cost: on a larger
       tub the extra revenue is extra profit, and the per-unit figure would
       understate it. Where no cost is recorded both stay zero, and the report
       treats that as unknown rather than as zero profit. */
    $unitProfit = $unitCost > 0 ? max(0, $unitPrice - $unitCost) : 0;

    return [
        'product_id'    => (int) $product['id'],
        'slug'          => $product['slug'],
        'name_fa'       => $product['name_fa'],
        'variant_label' => $variantBits ? implode(' · ', $variantBits) : (string) $product['variant_label'],
        'qty'           => $qty,
        'unit_price'    => $unitPrice,
        'unit_cost'     => $unitCost,
        'unit_profit'   => $unitProfit,
        'line_total'    => $unitPrice * $qty,
        'line_profit'   => $unitProfit * $qty,
        'rate_override' => $rateOverride === null ? null : (float) $rateOverride,
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

    /* The share can be taken on goods revenue or on realised profit.

       Profit is the fairer basis for a shop that imports at a volatile rate: a
       percentage of revenue silently grows as the rate rises, even though the
       shop's own margin has not moved at all. But it is only meaningful once
       costs are actually recorded. With no cost data a profit basis computes a
       commission of zero, which reads as agreement rather than as missing data,
       so it falls back to goods and stores which basis was used — making the
       fallback visible on the order instead of silent. */
    $profitTotal = array_sum(array_column($items, 'line_profit'));
    $basis = setting('commission_basis', 'goods') === 'profit' ? 'profit' : 'goods';
    if ($basis === 'profit' && $profitTotal <= 0) {
        $basis = 'goods';
    }

    /* Commission is summed per line, not applied once to the order total.

       A product may carry its own rate — a deliberate loss-leader, or a line
       negotiated separately — so one percentage over one number would be the
       wrong answer the moment any override exists. Each line records the rate
       it was charged at, and the order-level percentage becomes the blended
       result rather than an input. Where nothing is overridden the two are
       identical, so the simple case reads exactly as before. */
    $commissionBase   = 0;
    $commissionAmount = 0;

    foreach ($items as $i => $it) {
        $rate = $it['rate_override'] ?? $commissionPercent;
        $rate = max(0.0, min(100.0, (float) $rate));

        $lineBase = $basis === 'profit' ? $it['line_profit'] : $it['line_total'];
        $lineComm = (int) round($lineBase * $rate / 100);

        $items[$i]['commission_percent'] = $rate;
        $items[$i]['commission_amount']  = $lineComm;

        $commissionBase   += $lineBase;
        $commissionAmount += $lineComm;
    }

    /* Reported back as the rate that actually applied, so the order page does
       not claim 20% on an order where half the lines were charged 10%. */
    $effectivePercent = $commissionBase > 0
        ? round($commissionAmount / $commissionBase * 100, 2)
        : $commissionPercent;

    $rateAtOrder = pricing_rate();

    $channel = clean_text($payload['channel'] ?? '', 32);

    /* The window starts now. This price was computed from a rate that will
       move, so the quote has to carry an end. */
    $expiresAt = date('Y-m-d H:i:s', time() + order_hold_minutes() * 60);

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
                         subtotal, shipping, total, profit_total,
                         commission_percent, commission_amount, commission_basis, rate_at_order,
                         expires_at, channel, ip, user_agent)
                     VALUES (?, "new", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $code, $name, $phone, $address, $postal, $note,
                        $subtotal, $shipping, $total, $profitTotal,
                        $effectivePercent, $commissionAmount, $basis, $rateAtOrder,
                        $expiresAt, $channel, client_ip_binary(),
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
                    (order_id, product_id, slug, name_fa, variant_label, qty,
                     unit_price, unit_cost, unit_profit, line_total, line_profit,
                     commission_percent, commission_amount)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$orderId, $it['product_id'], $it['slug'], $it['name_fa'],
                 $it['variant_label'], $it['qty'], $it['unit_price'],
                 $it['unit_cost'], $it['unit_profit'], $it['line_total'], $it['line_profit'],
                 $it['commission_percent'], $it['commission_amount']]
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

    $paidStamp = ($status !== 'new' && $status !== 'cancelled' && $status !== 'expired'
                  && $current['paid_at'] === null)
        ? date('Y-m-d H:i:s')
        : $current['paid_at'];

    /* Putting an expired order back on the board gives it a fresh window.
       Without this the next page load would expire it again, which reads as
       the panel ignoring the click. */
    if ($status === 'new') {
        db_query('UPDATE orders SET status = ?, paid_at = ?, expires_at = ? WHERE id = ?',
            [$status, $paidStamp,
             date('Y-m-d H:i:s', time() + order_hold_minutes() * 60), $id]);
    } else {
        db_query('UPDATE orders SET status = ?, paid_at = ? WHERE id = ?',
            [$status, $paidStamp, $id]);
    }
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
                COALESCE(SUM(profit_total), 0) AS profit_total,
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
        'profit_total'     => (int) $row['profit_total'],
        'commission_total' => (int) $row['commission_total'],
    ];
}
