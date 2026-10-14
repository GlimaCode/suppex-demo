<?php
/* ===========================================================================
   SUPPEX — standing by the price the shopper was shown
   ---------------------------------------------------------------------------
   THE PROBLEM THIS SOLVES
   -----------------------
   Checkout here is card-to-card, and the order message is opened in the
   shopper's own chat app BEFORE the server ever hears about the order — it has
   to be, because window.open only survives a popup blocker while the click
   gesture is still on the stack (assets/js/app.js). So the figure the customer
   copies into Telegram comes from prices their browser is holding, and the
   figure the shop records comes from the database at the moment the request
   lands. Between those two instants the nightly repricing can run.

   The result is a customer transferring one amount against an order that says
   another, with no authorisation step anywhere to catch the difference. It is
   the one place in this shop where a wrong price costs real money rather than
   embarrassment.

   THE FIX
   -------
   Waiting for the server before opening the chat is not available: that round
   trip is exactly what the popup blocker kills. So instead of asking the
   shopper to re-confirm, the shop COMMITS to what it displayed. When the cart
   asks for current prices, the server writes down what it answered and hands
   back a token. The order sends the token, and each line is charged:

       min(the price we quoted, the price right now)

   Never more than the quote, so the shop honours what the customer saw and
   what their message says. Never more than the current price, so an old quote
   is only ever an advantage the customer already had — holding one through a
   price CUT gains them nothing, which is what stops the token being worth
   hoarding.

   The window is deliberately short. Prices can move every hour (the hourly
   job, tools/rate-cron.php); two hours covers filling in an address and
   finding a bank app, and a quote cannot carry a price across a day of
   repricings.
   =========================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** How long the shop stands by a quoted price. Long enough for someone to fill
    in an address and find their bank app; short beside prices that can move
    every hour. Was six hours while they moved once a day. */
const QUOTE_TTL_HOURS = 2;

/** One shopper's cart, not a catalogue dump - but a product priced by
    combination quotes every one of them (32 for a 2-size, 16-flavour whey),
    and at 60 one such product left the rest of the basket unquoted. 200 lines
    of JSON stay well inside the TEXT column. */
const QUOTE_MAX_LINES = 200;

/**
 * The key a quoted price is stored under.
 *
 * A size carries its own price, so the product slug alone is not enough — the
 * 900g and 2270g tubs of one product are two different prices and must not be
 * able to resolve to each other.
 */
function quote_key(string $slug, string $sizeExt = ''): string
{
    return $slug . '|' . $sizeExt;
}

/**
 * The key a flavour's PREMIUM is stored under.
 *
 * Kept separate from the size key on purpose. A shopper picks a size and a
 * flavour, so quoting the finished price of every combination would mean four
 * sizes times ten flavours of entries for one product — enough to blow past
 * QUOTE_MAX_LINES on a cart holding two of them. Quoting the base and the
 * premium apart keeps it to sizes PLUS flavours, and the two are added back
 * together at order time.
 */
function quote_flavor_key(string $slug, string $flavorExt): string
{
    return $slug . '#' . $flavorExt;
}

/**
 * Record what was just quoted and return the token that stands for it.
 *
 * @param array<string,int> $prices  quote_key() => toman
 * @return string  empty when there was nothing worth quoting
 */
function quote_issue(array $prices): string
{
    $clean = [];
    foreach ($prices as $key => $price) {
        $price = (int) $price;
        if ($price > 0 && is_string($key) && $key !== '') {
            $clean[$key] = $price;
        }
        if (count($clean) >= QUOTE_MAX_LINES) {
            break;
        }
    }
    if ($clean === []) {
        return '';
    }

    $token = bin2hex(random_bytes(16));
    db_query(
        'INSERT INTO price_quotes (token, payload, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))',
        [$token, json_encode($clean, JSON_UNESCAPED_UNICODE), QUOTE_TTL_HOURS]
    );

    return $token;
}

/**
 * The prices a token stands for, or an empty array.
 *
 * An expired or unknown token is not an error and must not be one: the cart
 * lives in localStorage indefinitely, so a shopper returning after a week
 * arrives with a token that is simply too old. They then pay today's price,
 * which is the correct outcome and the same one they would have had before any
 * of this existed.
 *
 * @return array<string,int>
 */
function quote_prices(string $token): array
{
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
        return [];
    }

    $row = db_one(
        'SELECT payload FROM price_quotes WHERE token = ? AND expires_at > NOW()',
        [$token]
    );
    if ($row === null) {
        return [];
    }

    $data = json_decode((string) $row['payload'], true);
    if (!is_array($data)) {
        return [];
    }

    $out = [];
    foreach ($data as $k => $v) {
        if (is_string($k) && is_numeric($v) && (int) $v > 0) {
            $out[$k] = (int) $v;
        }
    }

    return $out;
}

/**
 * What to charge for one line.
 *
 * The lower of the two, always. Quoting binds the shop downward — it may not
 * charge more than it displayed — and the current price binds it downward
 * again, so a price cut reaches even a shopper holding an old quote. There is
 * no combination of the two that charges more than the shopper expected.
 */
function quote_price_for(array $quoted, string $slug, string $sizeExt, int $current): int
{
    $key = quote_key($slug, $sizeExt);
    if (!isset($quoted[$key])) {
        return $current;
    }

    return min($quoted[$key], $current);
}

/**
 * What to add for the chosen flavour.
 *
 * The same lower-of-the-two rule as the base price, applied to the premium
 * separately. Keeping it separate means a shopper who was quoted a base price
 * still gets that base honoured even if they then switch to a flavour the shop
 * has since repriced — the two halves are independent promises, and each one
 * only ever binds the shop downward.
 */
function quote_flavor_delta_for(array $quoted, string $slug, string $flavorExt, int $current): int
{
    if ($flavorExt === '') {
        return $current;
    }

    $key = quote_flavor_key($slug, $flavorExt);
    if (!isset($quoted[$key])) {
        return $current;
    }

    return min($quoted[$key], $current);
}

/**
 * Drop what has expired.
 *
 * Rides along with other work rather than needing its own schedule, the same
 * way the order sweep does — the rate cron is hourly but a shop should not
 * depend on it for its own housekeeping.
 */
function quotes_sweep(): int
{
    /* Housekeeping must never be the thing that breaks a page. On a database
       that has not been migrated yet the table is simply absent, and the panel
       that would tell somebody to run the migration is one of the callers. */
    try {
        return db_query(
            'DELETE FROM price_quotes WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)'
        )->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}
