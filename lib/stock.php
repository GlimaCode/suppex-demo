<?php
/* ===========================================================================
   SUPPEX — Stock counts
   ---------------------------------------------------------------------------
   Until now the whole stock model was products.in_stock, a boolean somebody
   remembers to flip. Three people can order the last tub inside a minute and
   all three get an order code and a card number; two of them are refunded
   card-to-card by hand, and the shop has spent the only thing it has instead
   of a payment gateway, which is trust.

   WHAT A COUNT MEANS HERE

     stock_qty IS NULL   nothing is counted. Exactly the behaviour the shop has
                         today, and the default, because fifteen products go
                         live without counts and none of them may change.
     stock_qty = 0       counted, and there are none.
     stock_qty > 0       counted, and that many can still be sold.

   in_stock still wins. It is the manual off switch — "I have four but I am not
   selling them this week" — and a count must not be able to override a human
   saying no.

   WHERE A COUNT LIVES

   Most specific first, the same resolution the commission rate uses: the size
   row if it carries a count, otherwise the product row, otherwise nothing is
   counted. A product-level count therefore covers the sizes that do not have
   their own, which is what a shopkeeper who has bothered to count only the
   2270g tubs actually means.

   Not per flavour. Flavour times size is a grid nobody maintains by hand, and
   an unmaintained count is worse than none: it says a number that is wrong.

   WHEN IT IS TAKEN

   At the moment the order is created, not when the money arrives. Deciding at
   payment leaves exactly the race this file exists to close — everyone gets a
   code, the loser finds out afterwards. The order already carries an expiry,
   because the price is only held for the length of the dirham quote; the stock
   is held on that same window and given back when it lapses. A reservation
   with no end is a leak, and the end was already there.

   Held by: new, paid, shipped, done.
   Given back by: cancelled, expired.
   =========================================================================== */

declare(strict_types=1);

/**
 * Whether an order in this status is still holding its goods.
 *
 * "done" holds too: the goods left the building, so the count must stay down.
 * The hold simply becomes the sale rather than being handed back.
 */
function order_holds_stock(string $status): bool
{
    return $status !== 'cancelled' && $status !== 'expired';
}

/** Below this many units the storefront says how few are left. */
function stock_low_threshold(): int
{
    return max(1, min(setting_int('low_stock_threshold', 3), 50));
}

/**
 * Raised when a line cannot be reserved. Carries what the customer needs to
 * know — which product, and how many are actually left — because "سفارش ثبت
 * نشد" gives them nothing to do about it.
 */
final class StockShortfall extends RuntimeException
{
    /* Promoted but not readonly: the shop targets PHP 8.0, where promotion
       exists and readonly does not. */
    public function __construct(
        public string $productName,
        public int $remaining
    ) {
        parent::__construct('stock shortfall: ' . $productName);
    }
}

/**
 * Which row carries this line's count, if any.
 *
 * $forUpdate must be set by anything that is about to write. order_create()
 * resolves this from inside a transaction that has already read and written,
 * so its snapshot is fixed: under REPEATABLE READ a plain SELECT here goes on
 * returning the count as it stood when the order row was inserted, however
 * many tubs somebody else has bought since. A locking read sees the latest
 * committed row and holds it until the transaction ends.
 *
 * @return array{table:string,ext:?string,qty:int}|null  null when nothing is counted
 */
function stock_target(int $productId, string $sizeExt, bool $forUpdate = false,
                      bool $allowInquiry = false): ?array
{
    $lock = $forUpdate ? ' FOR UPDATE' : '';

    if ($sizeExt !== '') {
        /* A variant first. Once a product has them they are what is sold, and
           holding units against the size row instead would take them off a
           count nobody is selling from. Tried before the size rather than
           after, because both tables can hold a row for the same product. */
        $var = db_one(
            'SELECT * FROM product_variants WHERE product_id = ? AND ext_id = ?' . $lock,
            [$productId, $sizeExt]
        );
        if ($var !== null && !empty($var['requires_inquiry']) && !$allowInquiry) {
            return ['table' => 'product_variants', 'ext' => $sizeExt, 'qty' => 0, 'blocked' => true];
        }
        if ($var !== null && $var['stock_qty'] !== null) {
            return ['table' => 'product_variants', 'ext' => $sizeExt,
                    'qty' => (int) $var['stock_qty']];
        }

        $row = db_one(
            'SELECT stock_qty FROM product_sizes WHERE product_id = ? AND ext_id = ?' . $lock,
            [$productId, $sizeExt]
        );
        if ($row !== null && $row['stock_qty'] !== null) {
            return ['table' => 'product_sizes', 'ext' => $sizeExt, 'qty' => (int) $row['stock_qty']];
        }
    }

    $qty = db_value('SELECT stock_qty FROM products WHERE id = ?' . $lock, [$productId]);
    if ($qty === null) {
        return null;
    }
    return ['table' => 'products', 'ext' => null, 'qty' => (int) $qty];
}

/**
 * How many of this line can still be bought: null when nothing is counted.
 *
 * Used by the storefront and by the cart's revalidation, never by the write
 * path — a read here and a write later is the race, so the reservation does
 * its own conditional UPDATE instead of trusting this number.
 */
function stock_remaining(int $productId, string $sizeExt = ''): ?int
{
    $t = stock_target($productId, $sizeExt);
    return $t === null ? null : max(0, $t['qty']);
}

/**
 * Take $qty units off the count for one line.
 *
 * The decision and the write are one statement: UPDATE ... WHERE stock_qty >=
 * ?, and a rowCount of zero means somebody else got there first. Reading the
 * count and then subtracting it would reintroduce the race between the two
 * queries, which on a shop selling the last of something is precisely when it
 * matters. Subtracting a positive number always changes the row, so zero rows
 * changed is never ambiguous.
 *
 * @param bool $force allow the count to go negative — see stock_take_order()
 * @return array{from:?string,held:int}  from: the size ext_id it came out of,
 *         null for the product row. held is 0 when nothing is counted.
 * @throws StockShortfall when there are not enough and $force is false
 */
function stock_take_line(
    int $productId,
    string $sizeExt,
    int $qty,
    string $productName = '',
    bool $force = false
): array {
    if ($qty < 1) {
        return ['from' => null, 'held' => 0];
    }

    $target = stock_target($productId, $sizeExt, true, $force);
    if (!empty($target['blocked'])) {
        throw new StockShortfall($productName, 0);
    }
    if ($target === null) {
        return ['from' => null, 'held' => 0];   // nothing is counted here
    }

    if ($target['table'] === 'product_variants') {
        $sql    = 'UPDATE product_variants SET stock_qty = stock_qty - ?
                    WHERE product_id = ? AND ext_id = ? AND stock_qty IS NOT NULL';
        $params = [$qty, $productId, $sizeExt];
    } elseif ($target['table'] === 'product_sizes') {
        $sql    = 'UPDATE product_sizes SET stock_qty = stock_qty - ?
                    WHERE product_id = ? AND ext_id = ? AND stock_qty IS NOT NULL';
        $params = [$qty, $productId, $sizeExt];
    } else {
        $sql    = 'UPDATE products SET stock_qty = stock_qty - ?
                    WHERE id = ? AND stock_qty IS NOT NULL';
        $params = [$qty, $productId];
    }

    if (!$force) {
        $sql     .= ' AND stock_qty >= ?';
        $params[] = $qty;
    }

    if (db_query($sql, $params)->rowCount() > 0) {
        return ['from' => $target['ext'], 'held' => $qty];
    }

    /* Nothing changed. Either the count moved under us, or the row stopped
       being counted between the two statements — an admin can untrack a
       product at any moment. Re-read to tell those apart, because reporting
       "only 0 left" for a product that is no longer counted at all would be a
       lie the customer cannot act on. */
    $now = stock_target($productId, $sizeExt, true);
    if ($now === null) {
        return ['from' => null, 'held' => 0];
    }
    throw new StockShortfall($productName, max(0, $now['qty']));
}

/** Put $qty units back where they came from. */
function stock_give_back(int $productId, ?string $from, int $qty): void
{
    if ($qty < 1) {
        return;
    }
    /* IS NOT NULL, so units are never credited to a row that has since stopped
       being counted. An untracked count has no number to add to, and writing
       one would silently start tracking a product nobody asked to track. */
    if ($from !== null && $from !== '') {
        /* Credited to whichever of the two holds a counted row under this
           ext_id. Both statements are guarded on IS NOT NULL, so the one that
           does not have it changes nothing rather than inventing a count. */
        db_query('UPDATE product_variants SET stock_qty = stock_qty + ?
                   WHERE product_id = ? AND ext_id = ? AND stock_qty IS NOT NULL',
            [$qty, $productId, $from]);
        db_query('UPDATE product_sizes SET stock_qty = stock_qty + ?
                   WHERE product_id = ? AND ext_id = ? AND stock_qty IS NOT NULL',
            [$qty, $productId, $from]);
    } else {
        db_query('UPDATE products SET stock_qty = stock_qty + ?
                   WHERE id = ? AND stock_qty IS NOT NULL',
            [$qty, $productId]);
    }
}

/**
 * Give back everything one order is holding.
 *
 * Idempotent by construction: stock_held is zeroed in the same breath, so a
 * second call finds nothing to return. That matters more than it looks —
 * cancel, reopen, cancel again is an ordinary afternoon in the panel, and an
 * order that could pay itself back twice would inflate the count without
 * anything looking wrong.
 *
 * @return int units returned
 */
function stock_release_order(int $orderId): int
{
    $rows = db_all(
        'SELECT id, product_id, stock_from, stock_held FROM order_items
          WHERE order_id = ? AND stock_held > 0',
        [$orderId]
    );
    $total = 0;
    foreach ($rows as $r) {
        stock_give_back((int) $r['product_id'], $r['stock_from'], (int) $r['stock_held']);
        db_query('UPDATE order_items SET stock_held = 0 WHERE id = ?', [(int) $r['id']]);
        $total += (int) $r['stock_held'];
    }
    return $total;
}

/**
 * Anything this order touches that is now counted below zero.
 *
 * Only reopening an order can produce this, and only because reopening is
 * forced — see stock_take_order(). Nothing else in the shop can oversell. It
 * is reported rather than prevented, because the person clicking the button is
 * the one who knows what is on the shelf; what they must not do is find out
 * a week later from a customer.
 *
 * @return list<string> product names, each at most once
 */
function stock_oversold_for_order(int $orderId): array
{
    $rows = db_all(
        'SELECT DISTINCT oi.name_fa
           FROM order_items oi
           LEFT JOIN products p ON p.id = oi.product_id
           LEFT JOIN product_sizes s
                  ON s.product_id = oi.product_id AND s.ext_id = oi.stock_from
          WHERE oi.order_id = ?
            AND (p.stock_qty < 0 OR s.stock_qty < 0)',
        [$orderId]
    );
    return array_column($rows, 'name_fa');
}

/**
 * Take stock for an order that is coming back to life.
 *
 * Forced, so the count is allowed to go negative. The admin putting a
 * cancelled order back on the board knows something the database does not —
 * usually that the goods are on the shelf after all — and refusing the click
 * would leave them arguing with a number. A negative count is not a broken
 * state: it is the shop saying it has promised more than it has, which is
 * exactly the thing worth showing on the products page.
 *
 * @return int units taken
 */
function stock_take_order(int $orderId): int
{
    $rows = db_all(
        'SELECT id, product_id, name_fa, size_ext, qty FROM order_items
          WHERE order_id = ? AND stock_held = 0',
        [$orderId]
    );
    $total = 0;
    foreach ($rows as $r) {
        $got = stock_take_line(
            (int) $r['product_id'], (string) $r['size_ext'], (int) $r['qty'],
            (string) $r['name_fa'], true
        );
        if ($got['held'] > 0) {
            db_query('UPDATE order_items SET stock_held = ?, stock_from = ? WHERE id = ?',
                [$got['held'], $got['from'], (int) $r['id']]);
            $total += $got['held'];
        }
    }
    return $total;
}
