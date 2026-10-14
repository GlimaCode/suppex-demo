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

/* variants_has() and variants_list(): a converted product offers its
   combinations to the pricing page instead of its sizes. */
require_once __DIR__ . '/variants.php';

/* How far out of line a proposed price may be before it is treated as a
   probable data-entry error rather than a repricing. Five-fold in either
   direction: large enough that no ordinary rate move reaches it, small enough
   that a decimal point in the wrong place always does. */
const PRICING_SANITY_FACTOR = 5;

/** A promo may not eat more than this share of the unit's profit. */
const PRICING_MAX_PROMO_SHARE = 0.5;

/* A dirham purchase price above this was typed in toman. A supplement the shop
   resells costs tens or hundreds of dirhams; twenty thousand is a price in
   toman with the currency forgotten, and priced as dirhams it becomes a shelf
   price in the trillions. The catalogue import has refused it since the start;
   the product form refuses it too now that saving there sets the price. */
const PRICING_MAX_COST_AED = 20000;

/**
 * A dirham figure as the operator typed it, or null for a blank.
 *
 * Persian digits, and the decimal point in the three shapes it arrives in on
 * an Iranian keyboard: «.», «٫» and «/» - «۴۲/۵» is how 42.5 is written by
 * hand here. The thousands separators «,» and «٬» are dropped. Only the
 * leading number counts, the way PHP's own (float) reads «42 درهم».
 *
 * The product form's script reads a figure the same way before this ever
 * sees it (aedNum() in admin/product-edit.php), so the price the page shows
 * while typing is the price the save stores. Until this existed the two
 * disagreed: «42٫5» previewed as 425 dirhams and was saved as 42.
 */
function pricing_parse_aed(string $raw): ?float
{
    $s = to_latin_digits($raw);
    $s = str_replace(["\u{066B}", '/'], '.', $s);
    $s = preg_replace('/[\s,\x{066C}\x{200C}\x{200E}\x{200F}]+/u', '', $s) ?? '';
    if (!preg_match('/^(\d+(?:\.\d*)?|\.\d+)/', $s, $m)) {
        return null;
    }

    return round((float) $m[1], 2);
}

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
/**
 * The ratio implied by "it cost me this, I want to make that".
 *
 * Expressed against COST rather than against the sale price. The two carry the
 * same information — profit/cost = k and profit/price = k/(1+k) determine each
 * other exactly, so locking either one locks the other — but cost is the term
 * that is known first and cannot become a zero denominator part-way through.
 *
 * Returns null when there is nothing to infer from, which is the signal to
 * leave the unit on fixed-toman profit rather than to store a zero.
 */
function pricing_percent_from(int $costToman, int $profitToman): ?float
{
    if ($costToman <= 0 || $profitToman <= 0) {
        return null;
    }

    return round($profitToman / $costToman * 100, 3);
}

/**
 * What this unit should earn at a given cost.
 *
 * Two intents live side by side deliberately:
 *
 *   profit_percent set   the shop wants a RATIO, so the toman figure is
 *                        re-derived from the cost every time the rate moves and
 *                        the agreed margin survives a rising dirham;
 *   profit_percent NULL  the original fixed-toman behaviour, kept because
 *                        converting a live catalogue to a different pricing
 *                        model without being asked is not an upgrade.
 *
 * Why the ratio matters: a fixed profit_toman means the percentage margin
 * shrinks as the dirham climbs — 52.9% at a rate of 20,000 becomes 31.0% at
 * 50,000 on a 40-dirham tub — while every shelf price rises, so nothing looks
 * wrong. It also does not fund the replacement tub, which now costs more toman
 * than this sale earned.
 */
function pricing_profit(array $unit, int $costToman): int
{
    $typed = max(0, (int) ($unit['profit_toman'] ?? 0));
    $pct   = $unit['profit_percent'] ?? null;
    if ($pct === null || (float) $pct <= 0) {
        return $typed;
    }

    $derived = (int) round($costToman * ((float) $pct) / 100);

    /* At the cost the ratio was worked out from, the figure a person typed
       wins. The ratio is stored to three decimals, so on a 22,365,000-toman
       cost it gives back a typed 7,200,000 as 7,199,963 - a difference nobody
       chose, which then crossed a rounding step now and again and moved a
       shelf price by 10,000 at a rate that had not moved. Only a difference
       that small is forgiven: a new rate or a new purchase price moves the
       cost by far more, and then the ratio decides, as it always has. */
    if ($typed > 0 && abs($derived - $typed) <= pricing_ratio_slack($costToman)) {
        return $typed;
    }

    return $derived;
}

/** How far a stored ratio can miss the profit it was taken from, in toman. */
function pricing_ratio_slack(int $costToman): int
{
    /* Half a unit in the third decimal of a percentage is 5e-6 of the cost;
       doubled, plus a few toman for the roundings either side. */
    return (int) ceil(max(0, $costToman) * 0.00001) + 3;
}

/**
 * Margin as a share of the SELLING price — the number a shopkeeper means by
 * "what do I make on this". Reported, never stored: the stored figure is the
 * cost-side ratio, and this is the same fact wearing the other hat.
 */
function pricing_margin_pct(int $cost, int $price): float
{
    if ($price <= 0) {
        return 0.0;
    }

    return round(($price - $cost) / $price * 100, 1);
}

/**
 * Turn each flavour's dirham premium into the toman figure the shop charges.
 *
 * Called for a whole product rather than per unit, because a flavour belongs to
 * the product and not to any one size — «کوکی» costs the same two dirhams extra
 * whichever tub it is in, which is the assumption this whole design rests on.
 * If a premium ever needs to differ by size, this is the thing that would have
 * to become a matrix.
 *
 * The premium runs through the SAME margin as the product. Charging only the
 * bare cost difference would leave the expensive flavours carrying a thinner
 * margin than the plain ones, which is the opposite of what anybody intends
 * when they mark one up.
 *
 * @return int  how many flavour rows were rewritten
 */
function pricing_sync_flavors(int $productId, float $rate, ?float $profitPercent = null): int
{
    /* Resolved here rather than passed in, so there is one rule instead of one
       per caller. A product with four sizes has four margins to choose from and
       the flavour belongs to none of them in particular; its own is used when
       set, and otherwise the first size's, which is the same order of
       preference the rest of the pricing uses. */
    if ($profitPercent === null) {
        $own = db_value('SELECT profit_percent FROM products WHERE id = ?', [$productId]);
        if ($own === null) {
            $own = db_value(
                'SELECT profit_percent FROM product_sizes
                  WHERE product_id = ? AND profit_percent IS NOT NULL
                  ORDER BY sort_order, id LIMIT 1',
                [$productId]
            );
        }
        $profitPercent = $own === null ? null : (float) $own;
    }

    $rows = db_all(
        'SELECT id, cost_delta_aed FROM product_flavors WHERE product_id = ?',
        [$productId]
    );

    $n = 0;
    foreach ($rows as $f) {
        /* NULL means "same price as the others", and must stay distinguishable
           from a zero somebody typed. Neither changes the price, but only one
           of them is a decision. */
        if ($f['cost_delta_aed'] === null) {
            continue;
        }

        $extraCost = (float) $f['cost_delta_aed'] * $rate;
        $margin    = $profitPercent !== null && $profitPercent > 0
            ? 1 + $profitPercent / 100
            : 1.0;

        /* Rounded to the shop's own step so the delta cannot push a clean price
           on to an odd number - a 2,540,000 tub plus a 47,300 premium would
           otherwise be advertised at 2,587,300. */
        $delta = pricing_round_up((int) round($extraCost * $margin));

        db_query('UPDATE product_flavors SET price_delta_toman = ? WHERE id = ?',
                 [$delta, (int) $f['id']]);
        $n++;
    }

    return $n;
}

function pricing_compute(array $unit, float $rate): ?array
{
    $costAed = (float) ($unit['cost_aed'] ?? 0);
    if ($costAed <= 0) {
        return null;
    }

    $cost = (int) round($costAed * $rate);

    return pricing_from_cost(
        $cost,
        pricing_profit($unit, $cost),
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
                p.profit_toman, p.profit_percent, p.promo_toman, p.price_mode,
                p.price_applied_rate,
                (SELECT COUNT(*) FROM product_sizes s WHERE s.product_id = p.id) AS size_count
           FROM products p
          WHERE p.price_mode = "aed"
          ORDER BY p.sort_order, p.id'
    );

    foreach ($products as $p) {
        /* Combinations first. Once a product has them they are what is sold,
           and listing its sizes here would reprice rows nobody buys while
           leaving the ones they do buy frozen at yesterday's rate. */
        if (variants_has((int) $p['id'])) {
            foreach (variants_list((int) $p['id']) as $v) {
                $units[] = [
                    'kind'       => 'variant',
                    'id'         => (int) $v['id'],
                    'product_id' => (int) $p['id'],
                    'label'      => $p['name_fa'],
                    'sub'        => implode(' · ', array_column($v['values'], 'label')),
                    /* Same parent fallback as a size: a combination with no
                       cost of its own inherits the product's rather than
                       dropping silently out of the preview. */
                    'cost_aed'     => (float) ($v['cost_aed'] ?? 0) ?: (float) $p['cost_aed'],
                    'profit_toman' => (int) ($v['profit_toman'] ?? 0) ?: (int) $p['profit_toman'],
                    'profit_percent' => $v['profit_percent'] !== null
                        ? (float) $v['profit_percent']
                        : ($p['profit_percent'] === null ? null : (float) $p['profit_percent']),
                    'promo_toman'  => (int) ($v['promo_toman'] ?? 0) ?: (int) $p['promo_toman'],
                    'current'      => (int) $v['price'],
                    'applied_at_rate' => $v['price_applied_rate'] === null
                        ? null : (float) $v['price_applied_rate'],
                ];
            }
            continue;
        }

        if ((int) $p['size_count'] === 0) {
            $units[] = [
                'kind'       => 'product',
                'id'         => (int) $p['id'],
                'product_id' => (int) $p['id'],
                'label'      => $p['name_fa'],
                'sub'        => '',
                'cost_aed'   => (float) $p['cost_aed'],
                'profit_toman' => (int) $p['profit_toman'],
                'profit_percent' => $p['profit_percent'] === null ? null : (float) $p['profit_percent'],
                'promo_toman'  => (int) $p['promo_toman'],
                'current'    => (int) $p['price'],
                'applied_at_rate' => $p['price_applied_rate'] === null ? null : (float) $p['price_applied_rate'],
            ];
            continue;
        }

        $sizes = db_all(
            'SELECT id, label, price, cost_aed, profit_toman, profit_percent,
                    promo_toman, price_applied_rate
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
                /* Same parent fallback as the cost above, and for the same
                   reason: a size added to a product that was priced as a single
                   unit inherits the intent that was already set rather than
                   silently reverting to fixed-toman profit. */
                'profit_percent' => $s['profit_percent'] !== null
                    ? (float) $s['profit_percent']
                    : ($p['profit_percent'] === null ? null : (float) $p['profit_percent']),
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

        /* The profit the unit is MEANT to earn, before any promo is taken off.
           $margin is the realised figure and is the wrong thing to store: on a
           discounted line it is smaller, and writing it back would ratchet the
           product's profit down a little further every time the rate moved
           while a sale was running. For a unit with no percent this returns
           exactly what is already in the column, so the write is a no-op. */
        $profitNow = pricing_profit($unit, $computed['cost']);

        $out[] = $unit + [
            'proposed'   => $next,
            'compare_at' => $computed['compare_at'],
            'cost'       => $computed['cost'],
            'delta'      => $next - $current,
            'delta_pct'  => $current > 0 ? round(($next - $current) / $current * 100, 1) : 0.0,
            'margin'     => $margin,
            'profit_now' => $profitNow,
            'margin_pct' => pricing_margin_pct($computed['cost'], $next),

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
/**
 * Apply the proposed prices.
 *
 * $onlyKeys null  = every row in the preview.
 * $onlyKeys array = exactly those rows, and an EMPTY array means none.
 *
 * That distinction is the point. It used to default to [] and test it with a
 * plain truthiness check, so an operator who reviewed a bad preview, unticked
 * every row and pressed apply wrote the whole catalogue live - the opposite of
 * what the page told them unticking would do.
 */
function pricing_apply(float $rate, array $admin, ?array $onlyKeys = null,
                       string $source = 'manual'): array
{
    $preview = pricing_preview($rate);
    $applied = 0;
    $skipped = 0;
    /* Products a row was actually written for, so the flavour sync below can
       respect the same selection the price writes do. */
    $touchedProducts = [];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($preview as $row) {
            $key = $row['kind'] . ':' . $row['id'];
            if ($onlyKeys !== null && !in_array($key, $onlyKeys, true)) {
                continue;
            }
            /* Refused outright. An implausible row is not refused here — it
               simply arrives unticked from the form, so reaching this point
               means the operator looked at it and chose it. */
            if ($row['no_margin']) {
                $skipped++;
                continue;
            }

            if ($row['kind'] === 'variant') {
                db_query(
                    'UPDATE product_variants
                        SET price = ?, compare_at = ?, cost_toman = ?, profit_toman = ?,
                            price_applied_rate = ?, price_applied_at = NOW()
                      WHERE id = ?',
                    [$row['proposed'], $row['compare_at'], $row['cost'], $row['profit_now'],
                     $rate, $row['id']]
                );
                pricing_sync_parent((int) $row['product_id']);
            } elseif ($row['kind'] === 'size') {
                db_query(
                    /* profit_toman is written too, not just the price. On a
                   ratio-locked unit the toman profit is derived, and leaving
                   the old figure in place would make the margin sheet and the
                   order profit snapshot disagree with price - cost. */
                    'UPDATE product_sizes
                        SET price = ?, compare_at = ?, cost_toman = ?, profit_toman = ?,
                            price_applied_rate = ?, price_applied_at = NOW()
                      WHERE id = ?',
                    [$row['proposed'], $row['compare_at'], $row['cost'], $row['profit_now'],
                     $rate, $row['id']]
                );

                /* The parent row is what the product card and the catalogue
                   listing show, so it tracks the cheapest buyable size. Left
                   alone, the grid would keep advertising a stale price that no
                   longer matches anything on the product page. */
                pricing_sync_parent((int) $row['product_id']);
            } else {
                db_query(
                    'UPDATE products
                        SET price = ?, compare_at = ?, cost_toman = ?, profit_toman = ?,
                            price_applied_rate = ?, price_applied_at = NOW()
                      WHERE id = ?',
                    [$row['proposed'], $row['compare_at'], $row['cost'], $row['profit_now'],
                     $rate, $row['id']]
                );
            }
            /* Lock in the ratio the first time this unit is priced.
               A product created before any rate existed could not have its
               toman cost known at entry, so it arrives here with a profit and
               no ratio; without this it would keep fixed-toman profit for ever
               and quietly behave differently from everything beside it. */
            if (($row['profit_percent'] ?? null) === null && $row['profit_now'] > 0
                && $row['cost'] > 0) {
                $locked = pricing_percent_from((int) $row['cost'], (int) $row['profit_now']);
                if ($locked !== null) {
                    $lockSql = 'UPDATE products SET profit_percent = ? WHERE id = ?';
                    if ($row['kind'] === 'size') {
                        $lockSql = 'UPDATE product_sizes SET profit_percent = ? WHERE id = ?';
                    } elseif ($row['kind'] === 'variant') {
                        $lockSql = 'UPDATE product_variants SET profit_percent = ? WHERE id = ?';
                    }
                    db_query($lockSql, [$locked, $row['id']]);
                }
            }

            $touchedProducts[(int) $row['product_id']] = true;
            $applied++;
        }

        /* Once per product, not once per unit: a flavour premium belongs to the
           product, and a tub with four sizes would otherwise have its flavours
           rewritten four times to the same value.

           And only for products something was actually APPLIED to. Iterating
           the whole preview ignored the operator's row selection, so a product
           they had deliberately left unticked — the only defence the page
           offers against a row it flagged implausible — kept its old base price
           and got a new-rate premium stacked on top of it, with nothing on
           screen saying anything had moved. */
        foreach (array_keys($touchedProducts) as $pid) {
            pricing_sync_flavors((int) $pid, $rate);
            /* After the prices, because the typed struck price is shown only
               while the new price is still below it. */
            pricing_place_manual_compare((int) $pid);
        }

        $previous = pricing_last_applied_rate();
        db_query(
            /* The source is threaded through rather than hardcoded, because an
               automatic repricing and one the owner made by hand are different
               events and the log is the only place that difference survives.
               Told apart, "why did prices move on Tuesday" has an answer. */
            'INSERT INTO rate_history (rate, previous, source, admin_id, admin_name, applied_to, note)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$rate, $previous, $source, $admin['id'] ?? null, $admin['name'] ?? '', $applied,
             $skipped > 0 ? $skipped . ' مورد به دلیل نداشتن سود اعمال نشد' : '']
        );

        $saved = [
            'aed_rate'            => (string) $rate,
            'aed_rate_updated_at' => date('Y-m-d H:i:s'),
        ];

        /* A MANUAL apply re-anchors; an automatic one does not.
           rate_anchor is the last rate a person looked at and accepted, and it
           is what rate_decide() measures a reading against before deciding
           something has gone badly wrong (lib/rate.php). It was read in two
           places and written in none, so that guard could never fire — the
           automatic pricer would follow a threefold move straight onto the
           shelf. Letting the automatic path write it would defeat the point:
           an anchor that drifts with the thing it is anchoring is not an
           anchor. */
        if ($source === 'manual') {
            $saved['rate_anchor'] = (string) $rate;
        }

        settings_save($saved);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[suppex] pricing_apply failed: ' . $e->getMessage());
        throw $e;
    }

    return ['applied' => $applied, 'skipped' => $skipped];
}

/**
 * Price one dirham-bought product at the rate the shelf is already on.
 *
 * The pricing page moves every price when the RATE moves. This is the other
 * half - the product's own figures moving: a new purchase price, a different
 * profit, a discount - and it is what the product form runs when it saves.
 * Before it the form stored whatever had been typed into «قیمت», and the
 * formula only caught up at the next change of rate: a 350-dirham tub with
 * 7,200,000 profit went on sale at a hand-rounded 29,500,000 where the
 * formula says 29,570,000, and the difference came out of the margin.
 *
 * The units, the formula and the columns written are the pricing page's own
 * (pricing_units(), pricing_from_cost(), pricing_apply()), so the next apply
 * at this rate finds nothing to change.
 *
 * Combinations are the one place this does more. Their purchase price, profit
 * and ratio are COPIED from the size or the product when the combination is
 * made, and nothing on the form edits the copies, so a purchase price or a
 * profit typed above them never reached the shelf again. Here each one takes
 * them from its size, or the product, afresh:
 *
 *   purchase  its own, when one was typed into the grid; otherwise its
 *             size's, or the product's, plus its flavour's premium - the rule
 *             variants_adopt() makes them with
 *   profit    its size's, or the product's, scaled by purchase price, so a
 *             dearer flavour keeps the same margin - the rule
 *             pricing_sync_flavors() already prices premiums by
 *
 * Sizes are priced too, for the form's own sizes table and for a product
 * that has not been converted, but their profit column is left as typed: a
 * blank there means «the product's», and filling it in here would quietly
 * stop the size following the product.
 *
 * @return array{price:int, compare_at:?int, capped:bool}
 */
function pricing_reprice_product(int $productId, float $rate): array
{
    $out = ['price' => 0, 'compare_at' => null, 'capped' => false];

    $p = db_one('SELECT * FROM products WHERE id = ?', [$productId]);
    if ($p === null || $p['price_mode'] !== 'aed' || $rate <= 0) {
        return $out;
    }

    $product = [
        'cost_aed'       => (float) ($p['cost_aed'] ?? 0),
        'profit_toman'   => (int) ($p['profit_toman'] ?? 0),
        'profit_percent' => $p['profit_percent'] === null ? null : (float) $p['profit_percent'],
        'promo_toman'    => (int) ($p['promo_toman'] ?? 0),
    ];

    /* One unit through the formula, remembering whether its discount had to
       be cut down to half the profit - the operator is told, because a saving
       typed and a saving shown being different numbers is the kind of thing
       only a customer notices. */
    $price = static function (array $unit) use ($rate, &$out): ?array {
        $costAed = (float) ($unit['cost_aed'] ?? 0);
        if ($costAed <= 0) {
            return null;
        }
        $cost   = (int) round($costAed * $rate);
        $profit = pricing_profit($unit, $cost);
        $promo  = (int) ($unit['promo_toman'] ?? 0);
        if ($promo > (int) floor($profit * PRICING_MAX_PROMO_SHARE)) {
            $out['capped'] = true;
        }
        return pricing_from_cost($cost, $profit, $promo) + ['profit' => $profit];
    };

    $productBase = $price($product);
    $productBase = $productBase === null ? null : $product + $productBase;

    /* The pricing page's fallbacks, exactly: a blank on a size is the
       product's figure. */
    $sizeBase = [];
    foreach (db_all('SELECT * FROM product_sizes WHERE product_id = ? ORDER BY sort_order, id',
                    [$productId]) as $s) {
        $unit = [
            'cost_aed'       => (float) ($s['cost_aed'] ?? 0) ?: $product['cost_aed'],
            'profit_toman'   => (int) ($s['profit_toman'] ?? 0) ?: $product['profit_toman'],
            'profit_percent' => $s['profit_percent'] !== null
                ? (float) $s['profit_percent'] : $product['profit_percent'],
            'promo_toman'    => (int) ($s['promo_toman'] ?? 0) ?: $product['promo_toman'],
        ];
        $c = $price($unit);
        if ($c === null) {
            continue;
        }
        db_query(
            'UPDATE product_sizes
                SET price = ?, compare_at = ?, cost_toman = ?,
                    price_applied_rate = ?, price_applied_at = NOW()
              WHERE id = ?',
            [$c['price'], $c['compare_at'], $c['cost'], $rate, (int) $s['id']]
        );
        $sizeBase[(string) $s['ext_id']] = $unit + $c;
    }

    if (variants_has($productId)) {
        $premium = [];
        foreach (db_all('SELECT ext_id, cost_delta_aed FROM product_flavors WHERE product_id = ?',
                        [$productId]) as $f) {
            $premium[(string) $f['ext_id']] = (float) ($f['cost_delta_aed'] ?? 0);
        }

        foreach (variants_list($productId) as $v) {
            $sizeExt = null;
            $flavorExt = null;
            foreach ($v['values'] as $val) {
                if ($val['attr_ext'] === VARIANT_SIZE_ATTR)   { $sizeExt   = (string) $val['ext_id']; }
                if ($val['attr_ext'] === VARIANT_FLAVOR_ATTR) { $flavorExt = (string) $val['ext_id']; }
            }
            $base = ($sizeExt !== null && isset($sizeBase[$sizeExt])) ? $sizeBase[$sizeExt] : $productBase;
            if ($base === null) {
                continue;
            }

            $own     = (float) ($v['cost_aed'] ?? 0);
            $costAed = $own > 0
                ? $own
                : round($base['cost_aed'] + ($flavorExt !== null ? ($premium[$flavorExt] ?? 0.0) : 0.0), 2);
            $cost    = (int) round($costAed * $rate);

            $c = $price([
                'cost_aed'       => $costAed,
                'profit_toman'   => $base['cost'] > 0
                    ? (int) round($base['profit'] * $cost / $base['cost'])
                    : $base['profit'],
                'profit_percent' => $base['profit_percent'],
                'promo_toman'    => (int) ($v['promo_toman'] ?? 0) ?: $product['promo_toman'],
            ]);
            if ($c === null) {
                continue;
            }
            db_query(
                'UPDATE product_variants
                    SET cost_aed = ?, cost_toman = ?, profit_toman = ?, profit_percent = ?,
                        price = ?, compare_at = ?, price_applied_rate = ?, price_applied_at = NOW()
                  WHERE id = ?',
                [$costAed, $c['cost'], $c['profit'], $base['profit_percent'],
                 $c['price'], $c['compare_at'], $rate, (int) $v['id']]
            );
        }
        pricing_sync_parent($productId);
    } elseif ($sizeBase) {
        pricing_sync_parent($productId);
    } elseif ($productBase !== null) {
        /* The product is the thing sold. Its profit is written as
           pricing_apply() writes it: the figure this price was built from. */
        db_query(
            'UPDATE products
                SET price = ?, compare_at = ?, cost_toman = ?, profit_toman = ?,
                    price_applied_rate = ?, price_applied_at = NOW()
              WHERE id = ?',
            [$productBase['price'], $productBase['compare_at'], $productBase['cost'],
             $productBase['profit'], $rate, $productId]
        );
    }

    pricing_place_manual_compare($productId);

    $now = db_one('SELECT price, compare_at FROM products WHERE id = ?', [$productId]);
    $out['price']      = (int) ($now['price'] ?? 0);
    $out['compare_at'] = ($now['compare_at'] ?? null) === null ? null : (int) $now['compare_at'];

    return $out;
}

/** Has the column for a hand-typed struck price arrived (lib/migrate.php)? */
function pricing_manual_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        $ready = db_value('SELECT 1 FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                              AND COLUMN_NAME = ?', ['products', 'compare_manual']) !== null;
    }
    return $ready;
}

/**
 * Put the owner's own struck price back where it belongs.
 *
 * compare_at is what the storefront strikes through, and for a dirham product
 * every repricing writes it - from the discount, or empty when there is none.
 * A figure typed into «قیمت قبل از تخفیف» therefore lives in compare_manual,
 * which no repricing writes, and this copies it back afterwards: onto the
 * product itself, which is the card, and onto the combinations of the size
 * the card shows. Only those. A struck price typed while looking at a
 * 29,570,000 tub means nothing beside the 4,430,000 one of the same product.
 *
 * It is a fixed number and does not follow the rate. Once the selling price
 * reaches it, it is not a saving any more - product_derive() would not show it
 * either - so nothing is copied and the discount's own struck price, if any,
 * stands. It comes back by itself if the price falls below it again.
 */
function pricing_place_manual_compare(int $productId): void
{
    if (!pricing_manual_ready()) {
        return;
    }
    $p = db_one('SELECT price_mode, price, compare_manual FROM products WHERE id = ?', [$productId]);
    if ($p === null || $p['price_mode'] !== 'aed' || $p['compare_manual'] === null) {
        return;
    }
    $manual = (int) $p['compare_manual'];
    $card   = (int) $p['price'];
    if ($card <= 0 || $manual <= $card) {
        return;
    }

    db_query('UPDATE products SET compare_at = ? WHERE id = ?', [$manual, $productId]);

    $sizeOf = static function (array $v): ?string {
        foreach ($v['values'] as $val) {
            if ($val['attr_ext'] === VARIANT_SIZE_ATTR) {
                return (string) $val['ext_id'];
            }
        }
        return null;
    };

    if (variants_has($productId)) {
        /* The card's combination is the one pricing_sync_parent() chose: the
           cheapest that is for sale. Its size - or, with no sizes, every
           flavour - is what the typed figure was meant for. */
        $rows = variants_list($productId);
        $best = null;
        foreach ($rows as $v) {
            if ((int) $v['is_active'] !== 1 || (int) $v['price'] <= 0) {
                continue;
            }
            if ($best === null || (int) $v['price'] < (int) $best['price']) {
                $best = $v;
            }
        }
        if ($best === null) {
            return;
        }
        $cardSize = $sizeOf($best);
        foreach ($rows as $v) {
            if ($sizeOf($v) === $cardSize && (int) $v['price'] < $manual) {
                db_query('UPDATE product_variants SET compare_at = ? WHERE id = ?',
                         [$manual, (int) $v['id']]);
            }
        }
        if ($cardSize !== null) {
            db_query('UPDATE product_sizes SET compare_at = ?
                       WHERE product_id = ? AND ext_id = ? AND price < ?',
                     [$manual, $productId, $cardSize, $manual]);
        }
        return;
    }

    db_query('UPDATE product_sizes SET compare_at = ? WHERE product_id = ? AND price = ?',
             [$manual, $productId, $card]);
}

/** Point the parent product at its cheapest size, which is what the grid shows. */
function pricing_sync_parent(int $productId): void
{
    /* The cheapest thing a shopper can actually buy. For a converted product
       that is a combination, not a size - the card would otherwise advertise
       a price nothing on the product page matches.

       Only rows that are for sale: a switched-off combination is not an
       offer, and letting one set the card price would advertise something
       the checkout refuses. */
    $cheapest = db_one(
        'SELECT price, compare_at, cost_toman, price_applied_rate
           FROM product_variants
          WHERE product_id = ? AND is_active = 1 AND price > 0
          ORDER BY price ASC LIMIT 1',
        [$productId]
    );
    if ($cheapest === null) {
        $cheapest = db_one(
            'SELECT price, compare_at, cost_toman, price_applied_rate
               FROM product_sizes WHERE product_id = ? ORDER BY price ASC LIMIT 1',
            [$productId]
        );
    }
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
 * The units are the ones pricing_units() prices, which means the COMBINATIONS
 * of a product priced by combination and not its sizes. The sizes of such a
 * product are never repriced, so counting them called every converted product
 * «still on the old rate» after the first rate change, for good.
 *
 * The age is measured to the later of the last apply and the hourly job's last
 * check that found the shelf already on the market (rate_auto_apply()): a quiet
 * week is not five days of stale prices when the job confirmed them every hour.
 *
 * drift_pct is how far the latest reading of the last six hours sits from the
 * rate the shelf is on - what the dashboard leads with - or null without one.
 *
 * @return array{stale:bool,days:?float,rate:?float,behind:int,total:int,drift_pct:?float}
 */
function pricing_staleness(): array
{
    $rate  = pricing_rate();
    $units = array_values(array_filter(pricing_units((float) ($rate ?? 0)),
        static fn(array $u): bool => (float) $u['cost_aed'] > 0));
    $total = count($units);

    if ($rate === null || $total === 0) {
        return ['stale' => false, 'days' => null, 'rate' => $rate, 'behind' => 0,
                'total' => $total, 'drift_pct' => null];
    }

    $ages = [];
    $updated = trim((string) setting('aed_rate_updated_at', ''));
    if ($updated !== '' && strtotime($updated) !== false) {
        $ages[] = time() - (int) strtotime($updated);
    }
    $checked = (int) setting('rate_auto_last_apply', '0');
    if ($checked > 0) {
        $ages[] = time() - $checked;
    }
    $days = $ages ? min($ages) / 86400 : null;

    /* Units still carrying a different rate than the one now in force. */
    $behind = count(array_filter($units, static fn(array $u): bool =>
        $u['applied_at_rate'] === null || abs((float) $u['applied_at_rate'] - $rate) > 0.005));

    $drift = null;
    try {
        $latest = db_value('SELECT rate FROM rate_polls
                             WHERE accepted = 1 AND rate IS NOT NULL
                               AND created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
                             ORDER BY id DESC LIMIT 1');
        if ($latest !== null && (float) $latest > 0) {
            $drift = round(((float) $latest - $rate) / $rate * 100, 1);
        }
    } catch (Throwable $e) {
        /* No rate_polls table before its migration: no drift to report. */
    }

    $maxDays = setting_float('price_max_age_days', 3.0);

    return [
        'stale'     => ($days !== null && $days >= $maxDays) || $behind > 0,
        'days'      => $days === null ? null : round($days, 1),
        'rate'      => $rate,
        'behind'    => $behind,
        'total'     => $total,
        'drift_pct' => $drift,
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
