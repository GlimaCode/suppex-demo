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
require_once __DIR__ . '/catalog_readiness.php';

require_once __DIR__ . '/products.php';
require_once __DIR__ . '/pricing.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/quotes.php';
require_once __DIR__ . '/variants.php';

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
    /* Not one with a receipt: that customer has paid, and the owner simply
       has not confirmed it yet. Expiring it released the stock they paid for
       and told them on the tracking page to build the basket again - an
       invitation to pay twice. */
    $due = db_all(
        'SELECT o.id FROM orders o
          WHERE o.status = "new" AND o.expires_at IS NOT NULL AND o.expires_at < NOW()
            AND NOT EXISTS (SELECT 1 FROM order_receipts r WHERE r.order_id = o.id)
          LIMIT 200'
    );
    if (!$due) {
        return 0;
    }

    foreach ($due as $row) {
        $id = (int) $row['id'];
        /* The status guard repeats in the UPDATE so a payment confirmed between
           the SELECT and here is not overwritten by the sweep. */
        if (db_query('UPDATE orders SET status = "expired" WHERE id = ? AND status = "new"',
                     [$id])->rowCount() === 0) {
            continue;   // somebody paid it between the SELECT and here
        }
        /* The hold ends with the window. This is the whole reason stock can be
           reserved at order time rather than at payment: the expiry already
           existed to end a price quote, and a reservation with no end is a
           slow leak of goods that are sitting on the shelf. */
        stock_release_order($id);
        db_query(
            'INSERT INTO order_events (order_id, admin_name, from_status, to_status, note)
             VALUES (?, "", "new", "expired", ?)',
            [$id, 'مهلت پرداخت تمام شد']
        );
    }
    return count($due);
}

/* The order endpoint is the only public route that costs money per request:
   it sends a Telegram message and, with SMS on, a billed one. It had no
   throttle of any kind, while the login form - which costs nothing - has had
   one from the start.

   Reuses login_attempts rather than adding a table. The reserved username
   cannot collide with a real account: the panel does not accept a space in a
   username, so nothing can ever register as this. */
const ORDER_THROTTLE_USER    = '~order';
const ORDER_THROTTLE_MAX     = 6;     // per IP
const ORDER_THROTTLE_MINUTES = 10;

/** True when this IP has placed too many orders too quickly. */
function order_is_throttled(): bool
{
    $since = date('Y-m-d H:i:s', time() - ORDER_THROTTLE_MINUTES * 60);
    return (int) db_value(
        'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND username = ? AND attempted_at > ?',
        [client_ip_binary(), ORDER_THROTTLE_USER, $since]
    ) >= ORDER_THROTTLE_MAX;
}

/** Record one order attempt against that window. */
function order_record_attempt(): void
{
    db_query('INSERT INTO login_attempts (ip, username) VALUES (?, ?)',
        [client_ip_binary(), ORDER_THROTTLE_USER]);
    /* Housekeeping, since shared hosting rarely has a cron worth relying on. */
    if (random_int(1, 20) === 1) {
        db_query('DELETE FROM login_attempts WHERE attempted_at < ?',
            [date('Y-m-d H:i:s', time() - 86400)]);
    }
}

/**
 * A stable signature of what is being bought.
 *
 * Sorted, so the same basket in a different order hashes the same, and short
 * enough to index. Only what identifies the goods - not quantities of attention
 * like the channel or the note.
 */
function order_items_hash(array $items): string
{
    $sig = [];
    foreach ($items as $i) {
        /* The custom attributes too: the same tub in another colour is
           another order, not a repeat of this one. */
        $attrs = is_array($i['attrs'] ?? null) ? $i['attrs'] : [];
        ksort($attrs);
        $sig[] = ($i['slug'] ?? '') . '|' . ($i['sizeId'] ?? '') . '|'
               . ($i['flavorId'] ?? '') . '|' . (int) ($i['qty'] ?? 1)
               . ($attrs ? '|' . http_build_query($attrs) : '');
    }
    sort($sig);
    return substr(hash('sha256', implode(';', $sig)), 0, 40);
}

/**
 * The order this request would duplicate, if any.
 *
 * Same phone, same basket, inside a minute: a double tap or a retry after a
 * flaky connection, not a second order. A customer who genuinely wants two
 * would have to change something, which is the right price for the guard.
 */
function order_recent_duplicate(string $phone, array $items): ?array
{
    if ($phone === '') {
        return null;
    }
    $row = db_one(
        'SELECT id, code, total, created_at FROM orders
          WHERE phone = ? AND items_hash = ? AND created_at > (NOW() - INTERVAL 60 SECOND)
          ORDER BY id DESC LIMIT 1',
        [$phone, order_items_hash($items)]
    );
    return $row ?: null;
}

/**
 * Resolve one posted line into a priced item, or null if it cannot be trusted.
 *
 * @param array{slug?:string,flavorId?:string,sizeId?:string,qty?:mixed} $line
 */
/**
 * @param array<string,int> $quoted  prices the shop already promised this
 *        shopper, keyed by quote_key(). Empty means no promise was made.
 */
function order_price_line(array $line, array $quoted = []): ?array
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

    /* --- the variant model ------------------------------------------------

       Once a product has variants they are what is sold: one row per real
       thing, with its own price, its own count and its own picture. The
       basket still sends {sizeId, flavorId} because it always has, plus
       `attrs` for anything the owner added since - and the three together
       name exactly one combination.

       Returns from inside rather than setting up variables the code below
       will re-derive: the delta arithmetic further down is the OTHER model,
       and letting both touch one line is how it ends up half-priced by
       each. */
    if (variants_has((int) $product['id'])) {
        $choice = [];
        $sizeIn = clean_text($line['sizeId'] ?? '', 60);
        if ($sizeIn !== '') { $choice[VARIANT_SIZE_ATTR] = $sizeIn; }
        $flavIn = clean_text($line['flavorId'] ?? '', 60);
        if ($flavIn !== '') { $choice[VARIANT_FLAVOR_ATTR] = $flavIn; }
        foreach ((array) ($line['attrs'] ?? []) as $ak => $av) {
            $ak = clean_text((string) $ak, 60);
            $av = clean_text((string) $av, 60);
            if ($ak !== '' && $av !== '') { $choice[$ak] = $av; }
        }

        $variant = variants_resolve((int) $product['id'], $choice);
        /* Refused rather than approximated. A basket that names a combination
           this product no longer has is stale, and the shopper picked
           something specific. */
        if ($variant === null || (int) $variant['is_active'] !== 1) {
            return null;
        }

        $vPrice = (int) $variant['price'];
        if (!catalog_price_valid($vPrice)) { return null; }
        /* The whole combination is quoted under one key - the variant's own
           ext_id - rather than a base and a premium added back together. With
           a price per combination there is nothing left to add. */
        if ($quoted !== []) {
            $vPrice = quote_price_for($quoted, (string) $product['slug'],
                                      (string) $variant['ext_id'], $vPrice);
        }
        /* A dirham product saved active before the pricing page has run sits
           at zero, and zero is never a real price. */
        if (!catalog_price_valid($vPrice)) {
            return null;
        }

        $vCost = (int) ($variant['cost_toman'] ?? 0);
        $bits  = [];
        foreach ($variant['values'] ?? [] as $val) {
            $bits[] = (string) $val['label'];
        }
        if (!$bits) {
            foreach (db_all(
                'SELECT v.label FROM product_variant_values vv
                   JOIN product_attribute_values v ON v.id = vv.value_id
                   JOIN product_attributes a ON a.id = v.attribute_id
                  WHERE vv.variant_id = ? ORDER BY a.sort_order, a.id',
                [(int) $variant['id']]) as $val) {
                $bits[] = (string) $val['label'];
            }
        }

        $vRate = $variant['commission_percent'] !== null
            ? $variant['commission_percent'] : $product['commission_percent'];

        return [
            'product_id'    => (int) $product['id'],
            'slug'          => $product['slug'],
            'name_fa'       => $product['name_fa'],
            'variant_label' => $bits ? implode(' · ', $bits)
                                     : (string) $product['variant_label'],
            /* The variant's ext_id travels where the size's used to. It is
               what stock_target() looks the count up by, and what the order
               line has to carry so the units can be given back. */
            'size_ext'      => (string) $variant['ext_id'],
            'qty'           => $qty,
            'unit_price'    => $vPrice,
            'unit_cost'     => $vCost,
            'unit_profit'   => $vCost > 0 ? max(0, $vPrice - $vCost) : 0,
            'line_total'    => $vPrice * $qty,
            'line_profit'   => ($vCost > 0 ? max(0, $vPrice - $vCost) : 0) * $qty,
            'rate_override' => $vRate === null ? null : (float) $vRate,
        ];
    }

    $sizeExt = '';

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

    /* A size id means nothing on a product that has no sizes, and must not be
       treated as a stale one.

       The product page synthesises a size for single-price products so its
       price and stock rendering has something to read (assets/js/app.js), and
       that synthetic id reached here as a size that could not be resolved —
       so every one of the fourteen single-price products was refused at
       checkout with «هیچ‌کدام از کالاهای سبد در دسترس نیست», while the same
       product added from a card's + button went through. The distinction that
       matters is not whether an id was sent, it is whether the product has any
       sizes for it to refer to. */
    if ($sizeId !== '' && (int) db_value(
            'SELECT COUNT(*) FROM product_sizes WHERE product_id = ?',
            [(int) $product['id']]) === 0) {
        $sizeId = '';
    }

    if ($sizeId !== '') {
        $size = db_one(
            'SELECT label, price, cost_toman, commission_percent FROM product_sizes
              WHERE product_id = ? AND ext_id = ?',
            [(int) $product['id'], $sizeId]
        );
        /* An unresolved size drops the line rather than falling back to the
           parent's price. It used to skip this block, keep products.price and
           record an empty variant label - so a cart holding a renamed size's
           ext_id bought the 2270g tub at the 900g price, and the picking slip
           said nothing about which one. Both writers rebuild sizes with DELETE
           + INSERT and carts live in localStorage indefinitely, so a stale
           ext_id is routine, not exotic. */
        if ($size === null) {
            return null;
        }

        $unitPrice     = (int) $size['price'];
        $unitCost      = (int) ($size['cost_toman'] ?? 0);
        $variantBits[] = $size['label'];
        /* Carried through to the order line. variant_label is prose assembled
           for a human — it can read "وانیل · 900 گرم" — and giving stock back
           needs the id the size is actually keyed by. */
        $sizeExt       = $sizeId;
        if ($size['commission_percent'] !== null) {
            $rateOverride = $size['commission_percent'];
        }
    }

    /* The flavour, before the price is final rather than after it.

       It used to be read further down, purely to put a name on the picking
       slip, which meant two things: a flavour that costs more was never
       charged for, and once it was, the quote below would have covered the tub
       but not the flavour chosen on it. */
    $flavorId    = clean_text($line['flavorId'] ?? '', 60);
    $flavorDelta = 0;
    if ($flavorId !== '') {
        $flavor = db_one(
            'SELECT name, price_delta_toman FROM product_flavors
              WHERE product_id = ? AND ext_id = ?',
            [(int) $product['id'], $flavorId]
        );
        if ($flavor !== null) {
            array_unshift($variantBits, $flavor['name']);
            $flavorDelta = max(0, (int) $flavor['price_delta_toman']);
        } else {
            /* An unknown flavour id is a stale cart, the same as an unresolved
               size — and it gets the same answer, because the shopper picked
               something that no longer exists and quietly serving them a
               different one is how the wrong tub ships. */
            return null;
        }
    }

    /* Honour what the shopper was shown.

       The order message is already open in their chat app by the time this
       runs — window.open has to happen inside the click gesture, so the server
       is told about the order afterwards. The figure in that message came from
       the prices the cart last fetched, and the nightly repricing can have run
       in between.

       The lower of the two, always: never more than was quoted, so the message
       and the order agree; and never more than the current price, so a price
       CUT reaches a shopper holding an old quote too. There is no way to
       combine them that charges more than expected, which is also why an old
       token is not worth hoarding. */
    if (!catalog_price_valid($unitPrice + $flavorDelta)) { return null; }
    if ($quoted !== []) {
        $unitPrice   = quote_price_for($quoted, (string) $product['slug'], $sizeExt, $unitPrice);
        $flavorDelta = quote_flavor_delta_for(
            $quoted, (string) $product['slug'], $flavorId, $flavorDelta);
    }

    /* Added after both halves have been held to their quotes, so the shopper
       pays the lower of what they were shown and what it costs now, on the tub
       and on the flavour independently. */
    $unitPrice += $flavorDelta;

    /* A price of zero is never a real price. A dirham product saved active
       before the pricing page has computed its price sits at 0, and this would
       otherwise sell it for nothing and hand out an order code for it. */
    if (!catalog_price_valid($unitPrice)) {
        return null;
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
        'size_ext'      => $sizeExt,
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

    /* Whatever the shop last told this shopper these cost. An absent, unknown
       or expired token yields an empty array, and the order is then priced at
       today's prices exactly as it always was. */
    $quoted = quote_prices(clean_text((string) ($payload['quote'] ?? ''), 32));

    $items = [];
    $gone  = [];
    foreach ($posted as $line) {
        if (!is_array($line)) {
            continue;
        }
        $priced = order_price_line($line, $quoted);
        if ($priced !== null) {
            $items[] = $priced;
        } else {
            $gone[] = (string) ($line['slug'] ?? '');
        }
    }
    if (count($items) === 0) {
        return ['ok' => false, 'errors' => ['items' => 'هیچ‌کدام از کالاهای سبد در دسترس نیست.']];
    }
    /* A line that cannot be priced - a size or flavour since removed, a
       combination switched off - used to vanish from the order while the
       chat message the customer had already sent still listed it, at a total
       the shop then did not record. Refused, naming what, so the basket can be
       corrected and sent again. */
    if ($gone) {
        $names = [];
        foreach (array_unique(array_filter($gone)) as $slug) {
            $n = db_value('SELECT name_fa FROM products WHERE slug = ?', [$slug]);
            $names[] = $n !== null ? (string) $n : $slug;
        }
        return ['ok' => false, 'errors' => ['items' =>
            'این کالا با همین انتخاب دیگر موجود نیست: ' . implode('، ', $names)
            . '. سبد خرید را دوباره باز کنید تا به‌روز شود.']];
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
    /* The fallback used to look at the whole order: one costed line kept the
       order on the profit basis and every uncosted line beside it computed a
       commission of zero. Nine active products have no cost, so that was the
       normal case - and because it depended on what else was in the basket, the
       same product paid differently from one order to the next. The decision
       moved into the loop below, where each line knows whether it has a cost.
       This stays as the order's headline basis: what the agreement says. */

    /* Commission is summed per line, not applied once to the order total.

       A product may carry its own rate — a deliberate loss-leader, or a line
       negotiated separately — so one percentage over one number would be the
       wrong answer the moment any override exists. Each line records the rate
       it was charged at, and the order-level percentage becomes the blended
       result rather than an input. Where nothing is overridden the two are
       identical, so the simple case reads exactly as before. */
    $commissionBase   = 0;
    $commissionAmount = 0;

    $anyGoodsFallback = false;
    $anyProfitLine    = false;

    foreach ($items as $i => $it) {
        $rate = $it['rate_override'] ?? $commissionPercent;
        $rate = max(0.0, min(100.0, (float) $rate));

        /* Profit only where a profit is actually known. A line with no recorded
           cost has line_profit = 0, and charging a percentage of zero is not an
           agreement to take nothing - it is missing data wearing the costume of
           a number. Those lines fall back to goods, one line at a time. */
        $lineBasis = ($basis === 'profit' && (int) ($it['unit_cost'] ?? 0) > 0)
            ? 'profit'
            : 'goods';
        if ($basis === 'profit' && $lineBasis === 'goods') {
            $anyGoodsFallback = true;
        }
        if ($lineBasis === 'profit') {
            $anyProfitLine = true;
        }

        $lineBase = $lineBasis === 'profit' ? $it['line_profit'] : $it['line_total'];
        $lineComm = (int) round($lineBase * $rate / 100);

        $items[$i]['commission_percent'] = $rate;
        $items[$i]['commission_amount']  = $lineComm;
        $items[$i]['commission_basis']   = $lineBasis;

        $commissionBase   += $lineBase;
        $commissionAmount += $lineComm;
    }

    /* Say what actually happened, rather than leaving the partner to work it
       out from the arithmetic.

       'mixed' only when the order genuinely was mixed. If EVERY line fell back
       - an order of nothing but uncosted products - then the order was charged
       on goods and should say goods, which is also what the old whole-order
       fallback did and what the settlement expects to read. */
    if ($anyGoodsFallback) {
        $basis = $anyProfitLine ? 'mixed' : 'goods';
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
                        (code, status, customer_name, phone, items_hash, address, postal, note,
                         subtotal, shipping, total, profit_total,
                         commission_percent, commission_amount, commission_basis, rate_at_order,
                         expires_at, channel, ip, user_agent)
                     VALUES (?, "new", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $code, $name, $phone, order_items_hash($posted), $address, $postal, $note,
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
            /* The goods are taken here, not when the money arrives. Deciding
               at payment leaves the whole race open: everyone who orders the
               last tub gets a code and a card number, and the ones who lose
               find out after they have paid. Where nothing is counted this
               returns held = 0 and changes nothing, which is every product in
               the shop until somebody types a number. */
            $hold = stock_take_line(
                (int) $it['product_id'], (string) ($it['size_ext'] ?? ''),
                (int) $it['qty'], (string) $it['name_fa']
            );

            db_query(
                'INSERT INTO order_items
                    (order_id, product_id, slug, name_fa, variant_label, size_ext, qty,
                     unit_price, unit_cost, unit_profit, line_total, line_profit,
                     commission_percent, commission_basis, commission_amount,
                     stock_held, stock_from)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$orderId, $it['product_id'], $it['slug'], $it['name_fa'],
                 $it['variant_label'], $it['size_ext'] ?? '', $it['qty'], $it['unit_price'],
                 $it['unit_cost'], $it['unit_profit'], $it['line_total'], $it['line_profit'],
                 $it['commission_percent'], $it['commission_basis'] ?? 'goods',
                 $it['commission_amount'], $hold['held'], $hold['from']]
            );
        }

        db_query(
            'INSERT INTO order_events (order_id, admin_name, from_status, to_status, note)
             VALUES (?, "", "", "new", "ثبت توسط مشتری")',
            [$orderId]
        );

        $pdo->commit();
    } catch (StockShortfall $e) {
        /* Rolled back, so nothing survives: no code, no order, no stock taken
           by the lines that did succeed before this one. The customer is told
           which product and how many are left, because "ثبت سفارش با خطا مواجه
           شد" leaves them with nothing to change. */
        $pdo->rollBack();
        return ['ok' => false, 'errors' => ['items' => $e->remaining > 0
            ? 'از «' . $e->productName . '» فقط ' . fa_digits((string) $e->remaining)
              . ' عدد باقی مانده است. تعداد را در سبد کم کنید و دوباره بفرستید.'
            : '«' . $e->productName . '» همین الان تمام شد. آن را از سبد بردارید و دوباره بفرستید.']];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[suppex] order_create failed: ' . $e->getMessage());
        return ['ok' => false, 'errors' => ['_' => 'ثبت سفارش با خطا مواجه شد. لطفاً دوباره تلاش کنید.']];
    }

    return ['ok' => true, 'order' => order_get($orderId)];
}

function order_requires_stock_confirmation(array $order): bool
{
    if (($order['status'] ?? '') !== 'new') { return false; }
    if (isset($order['items'])) {
        foreach ($order['items'] as $item) {
            if (empty($item['stock_from'])) { return true; }
        }
        return false;
    }
    return db_value('SELECT 1 FROM order_items WHERE order_id = ? AND stock_from IS NULL LIMIT 1',
                    [(int) $order['id']]) !== null;
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

    /* Stock follows the status. Leaving 'held' means new, paid, shipped and
       done — done included, because the goods left the building and the count
       must stay down; the hold simply becomes the sale.

       Both directions are handled: cancelling an order puts the goods back on
       the shelf, and putting a cancelled order back on the board takes them
       again. Only doing the first would leak stock upward every time somebody
       changed their mind twice. */
    $heldBefore = order_holds_stock($current['status']);
    $heldAfter  = order_holds_stock($status);
    if ($heldBefore && !$heldAfter) {
        stock_release_order($id);
    } elseif (!$heldBefore && $heldAfter) {
        stock_take_order($id);
    }

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

/**
 * Record the postal tracking number for an order.
 *
 * Digits only, and Persian digits are folded to Latin first: the number is
 * copied off a receipt, and a keyboard set to Persian produces ۰۹ where the
 * post office's own site expects 09. Storing both shapes would mean the
 * customer's copy did not match what they type into tracking.post.ir.
 *
 * An empty string clears it, which is how a number typed into the wrong order
 * gets taken back.
 */
function order_set_tracking(int $id, string $code): bool
{
    $code = digits_only($code);
    if (mb_strlen($code) > 40) {
        return false;
    }
    return db_query('UPDATE orders SET tracking_code = ? WHERE id = ?', [$code, $id])
        ->rowCount() >= 0;
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
