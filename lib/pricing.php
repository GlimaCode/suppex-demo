<?php
/* ===========================================================================
   SUPPEX — Dirham-linked pricing
   ---------------------------------------------------------------------------
   The shop buys stock in UAE dirham and sells in toman. The rate moves — often
   several percent in a week — so a toman price typed once is wrong within days,
   and re-typing sixty prices by hand every time is not something anyone will
   keep doing.

   The model: store what is actually stable — the dirham cost and the toman
   profit the shop wants per unit — and derive the shelf price from them:

       compare_at = round_up( cost_aed × rate + profit )
       price      = round_up( cost_aed × rate + profit − promo )

   Three decisions in here matter more than the formula.

   FIRST: prices are NOT computed at render time. A live-converted price changes
   under the shopper's feet — one number on the product page, another in the
   cart, a third at checkout — and in a card-to-card shop, where the customer
   transfers a specific amount by hand, that is not a cosmetic problem. Instead
   the rate is stored, the new prices are PREVIEWED, and the operator applies
   them deliberately. Between applies, the price is a fixed number in the
   products table like any other.

   SECOND: the unit of pricing is the BUYABLE UNIT, not the product. A 900g tub
   and a 2270g tub are bought separately in Dubai at different prices and sold
   at different prices, so each carries its own dirham cost. Pricing the parent
   product and ignoring its sizes would leave every size variant frozen at an
   old price while the operator is told "48 products updated" — and, worse,
   would record the parent's cost against a variant's revenue, which is the
   number the partnership share is computed from.

   THIRD: a discount is stored as an amount off, not as a second price. Storing
   compare_at directly means it never tracks the rate: as the rate rises the
   advertised saving shrinks on its own, and once price overtakes compare_at the
   sale badge disappears catalogue-wide. Deriving both ends from one promo
   figure keeps the strikethrough honest at every rate.
   =========================================================================== */

declare(strict_types=1);

/* How far out of line a proposed price may be before it is treated as a
   probable data-entry error rather than a repricing. Five-fold in either
   direction: large enough that no ordinary rate move reaches it, small enough
   that a decimal point in the wrong place always does. */
const PRICING_SANITY_FACTOR = 5;

/** A promo may not eat more than this share of the unit's profit. */
const PRICING_MAX_PROMO_SHARE = 0.5;

/** Rounding step, in toman. Prices land on a round number so the shelf price
    reads as a decision rather than as the output of a spreadsheet. */
function pricing_step(): int
{
    $step = setting_int('price_rounding_step', 10000);
    return $step > 0 ? $step : 1;
}

/** Round UP, always. Rounding to nearest would sometimes shave the margin
    below the profit the shop asked for, which is the one direction that must
    never happen silently. */
function pricing_round_up(int $amount, ?int $step = null): int
{
    $step = $step ?? pricing_step();
    if ($step <= 1) {
        return $amount;
    }
    return (int) (ceil($amount / $step) * $step);
}

/** The rate currently entered in settings, or null if none has been set yet. */
function pricing_rate(): ?float
{
    $raw = trim((string) setting('aed_rate', ''));
    if ($raw === '') {
        return null;
    }
    $rate = (float) to_latin_digits($raw);
    return $rate > 0 ? $rate : null;
}

/**
 * Price one buyable unit.
 *
 * @param array $unit  cost_aed, profit_toman, promo_toman
 * @return array{cost:int,price:int,compare_at:?int}|null
 */
function pricing_compute(array $unit, float $rate): ?array
{
    $costAed = (float) ($unit['cost_aed'] ?? 0);
    if ($costAed <= 0) {
        return null;
    }

    return pricing_from_cost(
        (int) round($costAed * $rate),
        (int) ($unit['profit_toman'] ?? 0),
        (int) ($unit['promo_toman'] ?? 0)
    );
}

/**
 * Price one unit from a cost already in toman.
 *
 * The shop buys most things in dirham, but not everything: some lines are
 * bought inside Iran and priced in toman from the start. Those follow exactly
 * the same rounding and the same discount rule — the only difference is that
 * their cost is not multiplied by anything, so their shelf price does not move
 * when the dirham does.
 *
 * @return array{cost:int,price:int,compare_at:?int}
 */
function pricing_from_cost(int $cost, int $profit, int $promo): array
{
    $profit = max(0, $profit);
    $promo  = max(0, $promo);

    /* A promo bigger than the profit sells at a loss, and one that eats most of
       it turns a 1,750,000-toman margin into pocket change without anybody
       noticing. Capped rather than rejected, because the intent — "put this on
       sale" — is still honoured, just not to the point of self-harm. */
    $promo = min($promo, (int) floor($profit * PRICING_MAX_PROMO_SHARE));

    $full = pricing_round_up($cost + $profit);
    $now  = $promo > 0 ? pricing_round_up($cost + $profit - $promo) : $full;

    return [
        'cost'       => $cost,
        'price'      => $now,
        /* Only a real saving is advertised. Equal values would render a
           strikethrough over the same number the customer is being charged. */
        'compare_at' => $now < $full ? $full : null,
    ];
}

/**
 * Every buyable unit that is priced in dirham: the product itself when it has
 * no sizes, and each size row when it has.
 *
 * A product with sizes is never priced on its own row, because nothing can be
 * bought at that price — order_price_line() always charges the size price when
 * a size exists. Including it would inflate the count and invite the operator
 * to fill in a cost that is never used.
 *
 * @return array<int,array>
 */
function pricing_units(float $rate): array
{
    $units = [];

    $products = db_all(
        'SELECT p.id, p.slug, p.name_fa, p.price, p.compare_at, p.cost_aed,
                p.profit_toman, p.promo_toman, p.price_mode, p.price_applied_rate,
                (SELECT COUNT(*) FROM product_sizes s WHERE s.product_id = p.id) AS size_count
           FROM products p
          WHERE p.price_mode = "aed"
          ORDER BY p.sort_order, p.id'
    );

    foreach ($products as $p) {
        if ((int) $p['size_count'] === 0) {
            $units[] = [
                'kind'       => 'product',
                'id'         => (int) $p['id'],
                'product_id' => (int) $p['id'],
                'label'      => $p['name_fa'],
                'sub'        => '',
                'cost_aed'   => (float) $p['cost_aed'],
                'profit_toman' => (int) $p['profit_toman'],
                'promo_toman'  => (int) $p['promo_toman'],
                'current'    => (int) $p['price'],
                'applied_at_rate' => $p['price_applied_rate'] === null ? null : (float) $p['price_applied_rate'],
            ];
            continue;
        }

        $sizes = db_all(
            'SELECT id, label, price, cost_aed, profit_toman, promo_toman, price_applied_rate
               FROM product_sizes WHERE product_id = ? ORDER BY sort_order, id',
            [(int) $p['id']]
        );
        foreach ($sizes as $s) {
            $units[] = [
                'kind'       => 'size',
                'id'         => (int) $s['id'],
                'product_id' => (int) $p['id'],
                'label'      => $p['name_fa'],
                'sub'        => (string) $s['label'],
                /* A size with no cost of its own falls back to the parent's, so
                   a single-price product that later gains sizes does not
                   silently drop out of the preview. */
                'cost_aed'     => (float) ($s['cost_aed'] ?? 0) ?: (float) $p['cost_aed'],
                'profit_toman' => (int) ($s['profit_toman'] ?? 0) ?: (int) $p['profit_toman'],
                'promo_toman'  => (int) ($s['promo_toman'] ?? 0) ?: (int) $p['promo_toman'],
                'current'      => (int) $s['price'],
                'applied_at_rate' => $s['price_applied_rate'] === null ? null : (float) $s['price_applied_rate'],
            ];
        }
    }

    return $units;
}

/**
 * Every buyable unit with its current and proposed price.
 *
 * Showing the whole catalogue before anything is written is the guard against a
 * mistyped rate: a fat-fingered extra zero is obvious in a column of proposed
 * prices, and invisible in a success message that says "48 products updated".
 */
function pricing_preview(float $rate): array
{
    $out = [];

    foreach (pricing_units($rate) as $unit) {
        $computed = pricing_compute($unit, $rate);
        if ($computed === null) {
            continue;
        }

        $current = (int) $unit['current'];
        $next    = $computed['price'];
        $margin  = $next - $computed['cost'];

        $out[] = $unit + [
            'proposed'   => $next,
            'compare_at' => $computed['compare_at'],
            'cost'       => $computed['cost'],
            'delta'      => $next - $current,
            'delta_pct'  => $current > 0 ? round(($next - $current) / $current * 100, 1) : 0.0,
            'margin'     => $margin,

            /* --- Two different mistakes, caught two different ways ---------

               no_margin: the price would not clear its own cost. Only reachable
               when the profit field is empty or zero, since the formula adds
               profit and then rounds up — but that is a real way to lose money
               on every sale, so it is refused outright rather than warned about.

               implausible: the proposed price is wildly out of line with the
               price this unit has today. This is the one that catches the
               mistake that actually happens — a cost typed in TOMAN into the
               dirham field. A "below cost" test cannot see that error at all:
               1,200,000 in the dirham field yields a cost of 29 billion toman
               and a price of 29 billion and ten thousand, which clears its cost
               perfectly well and is nonsense. Comparing against the current
               price is scale-free, so it works the same for a 300,000-toman
               item and a 6,000,000-toman one, and it catches a missing digit as
               readily as an extra one.

               Implausible rows are not refused, only left unticked: a genuine
               five-fold repricing is possible after a long gap, and the
               operator can tick it deliberately. */
            'no_margin'   => $margin <= 0,
            'implausible' => $current > 0
                && ($next > $current * PRICING_SANITY_FACTOR
                    || $next < $current / PRICING_SANITY_FACTOR),
        ];
    }

    return $out;
}

/**
 * Write the computed prices.
 *
 * @param array<string> $onlyKeys  "product:12" / "size:34"; empty = all
 * @return array{applied:int,skipped:int}
 */
function pricing_apply(float $rate, array $admin, array $onlyKeys = []): array
{
    $preview = pricing_preview($rate);
    $applied = 0;
    $skipped = 0;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($preview as $row) {
            $key = $row['kind'] . ':' . $row['id'];
            if ($onlyKeys && !in_array($key, $onlyKeys, true)) {
                continue;
            }
            /* Refused outright. An implausible row is not refused here — it
               simply arrives unticked from the form, so reaching this point
               means the operator looked at it and chose it. */
            if ($row['no_margin']) {
                $skipped++;
                continue;
            }

            if ($row['kind'] === 'size') {
                db_query(
                    'UPDATE product_sizes
                        SET price = ?, compare_at = ?, cost_toman = ?,
                            price_applied_rate = ?, price_applied_at = NOW()
                      WHERE id = ?',
                    [$row['proposed'], $row['compare_at'], $row['cost'], $rate, $row['id']]
                );

                /* The parent row is what the product card and the catalogue
                   listing show, so it tracks the cheapest buyable size. Left
                   alone, the grid would keep advertising a stale price that no
                   longer matches anything on the product page. */
                pricing_sync_parent((int) $row['product_id']);
            } else {
                db_query(
                    'UPDATE products
                        SET price = ?, compare_at = ?, cost_toman = ?,
                            price_applied_rate = ?, price_applied_at = NOW()
                      WHERE id = ?',
                    [$row['proposed'], $row['compare_at'], $row['cost'], $rate, $row['id']]
                );
            }
            $applied++;
        }

        $previous = pricing_last_applied_rate();
        db_query(
            'INSERT INTO rate_history (rate, previous, source, admin_id, admin_name, applied_to, note)
             VALUES (?, ?, "manual", ?, ?, ?, ?)',
            [$rate, $previous, $admin['id'] ?? null, $admin['name'] ?? '', $applied,
             $skipped > 0 ? $skipped . ' مورد به دلیل نداشتن سود اعمال نشد' : '']
        );

        settings_save([
            'aed_rate'            => (string) $rate,
            'aed_rate_updated_at' => date('Y-m-d H:i:s'),
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[suppex] pricing_apply failed: ' . $e->getMessage());
        throw $e;
    }

    return ['applied' => $applied, 'skipped' => $skipped];
}

/** Point the parent product at its cheapest size, which is what the grid shows. */
function pricing_sync_parent(int $productId): void
{
    $cheapest = db_one(
        'SELECT price, compare_at, cost_toman, price_applied_rate
           FROM product_sizes WHERE product_id = ? ORDER BY price ASC LIMIT 1',
        [$productId]
    );
    if ($cheapest === null) {
        return;
    }
    db_query(
        'UPDATE products SET price = ?, compare_at = ?, cost_toman = ?,
                price_applied_rate = ?, price_applied_at = NOW()
          WHERE id = ?',
        [(int) $cheapest['price'],
         $cheapest['compare_at'] === null ? null : (int) $cheapest['compare_at'],
         $cheapest['cost_toman'] === null ? null : (int) $cheapest['cost_toman'],
         $cheapest['price_applied_rate'], $productId]
    );
}

/** The rate the most recent apply used, or null. */
function pricing_last_applied_rate(): ?float
{
    $v = db_value('SELECT rate FROM rate_history ORDER BY id DESC LIMIT 1');
    return $v === null ? null : (float) $v;
}

/**
 * Are the published prices out of date?
 *
 * The primary signal is TIME, not drift. An earlier version compared the rate
 * in settings against the rate the prices were applied at — but applying writes
 * both, so the two were always equal and the warning could never fire. It also
 * asked the wrong question: a non-technical operator understands "the rate was
 * last updated 6 days ago" and does not act on "average drift 2.1%". At roughly
 * 1% average daily movement, a price more than a few days old is already wrong.
 *
 * Drift is still reported, but only as the secondary count of units whose own
 * applied rate differs from the current one — which does move independently,
 * because a unit can be left unticked during an apply.
 *
 * @return array{stale:bool,days:?float,rate:?float,behind:int,total:int}
 */
function pricing_staleness(): array
{
    $rate  = pricing_rate();
    $total = (int) db_value(
        'SELECT (SELECT COUNT(*) FROM products WHERE price_mode = "aed" AND cost_aed > 0)
              + (SELECT COUNT(*) FROM product_sizes WHERE cost_aed > 0)'
    );

    if ($rate === null || $total === 0) {
        return ['stale' => false, 'days' => null, 'rate' => $rate, 'behind' => 0, 'total' => $total];
    }

    $updated = trim((string) setting('aed_rate_updated_at', ''));
    $days    = $updated === '' ? null : (time() - strtotime($updated)) / 86400;

    /* Units still carrying a different rate than the one now in force. */
    $behind = (int) db_value(
        'SELECT (SELECT COUNT(*) FROM products
                  WHERE price_mode = "aed" AND cost_aed > 0
                    AND (price_applied_rate IS NULL OR price_applied_rate <> ?))
              + (SELECT COUNT(*) FROM product_sizes
                  WHERE cost_aed > 0
                    AND (price_applied_rate IS NULL OR price_applied_rate <> ?))',
        [$rate, $rate]
    );

    $maxDays = setting_float('price_max_age_days', 3.0);

    return [
        'stale'  => ($days !== null && $days >= $maxDays) || $behind > 0,
        'days'   => $days === null ? null : round($days, 1),
        'rate'   => $rate,
        'behind' => $behind,
        'total'  => $total,
    ];
}

/**
 * Sanity band for a newly entered rate.
 *
 * A rate is a single number that reprices the entire catalogue at once, and the
 * realistic failure is a typo — a missing digit, or one too many. Compared
 * against the last applied rate rather than an absolute range, because any
 * absolute range hard-coded today is wrong within a year in this economy.
 */
function pricing_rate_warning(float $rate): ?string
{
    if ($rate <= 0) {
        return 'نرخ باید بزرگ‌تر از صفر باشد.';
    }

    $last = pricing_last_applied_rate();
    if ($last === null || $last <= 0) {
        return null;
    }

    $change = ($rate - $last) / $last * 100;
    if (abs($change) < 25) {
        return null;
    }

    return sprintf(
        'این نرخ %s%% نسبت به آخرین نرخ اعمال‌شده (%s) تفاوت دارد. اگر اشتباه تایپی نیست، ادامه دهید.',
        number_format(abs($change), 1),
        number_format($last)
    );
}
