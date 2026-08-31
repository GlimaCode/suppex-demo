<?php
/* ===========================================================================
   Order lookup for the customer.
   ---------------------------------------------------------------------------
   In a card-to-card shop the customer transfers real money and, until now,
   received nothing they could come back to: the order code was returned once by
   ?r=order and rendered into the cart drawer, and store.js never persisted it.
   Close the tab and the reference was gone permanently. That is the largest
   trust hole in a model with no gateway behind it, and it converts directly
   into "did my order go through?" messages the owner answers by hand.

   CODE AND PHONE, both. An order code alone would be enough to read a
   stranger's name, address and phone: the format is SPX-YYMMDD-XXXX, the date
   half is guessable, and four characters is not a secret. Requiring the phone
   that placed the order turns the code from a password into a reference, which
   is what a customer treats it as anyway - they paste it into chat.

   Throttled on the same login_attempts table as everything else, because a
   lookup that can be hammered is an enumeration tool.
   =========================================================================== */

const TRACK_THROTTLE_USER    = '~track';
const TRACK_THROTTLE_MAX     = 12;
const TRACK_THROTTLE_MINUTES = 10;

function track_is_throttled(): bool
{
    $since = date('Y-m-d H:i:s', time() - TRACK_THROTTLE_MINUTES * 60);
    return (int) db_value(
        'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND username = ? AND attempted_at > ?',
        [client_ip_binary(), TRACK_THROTTLE_USER, $since]
    ) >= TRACK_THROTTLE_MAX;
}

function track_record_attempt(): void
{
    db_query('INSERT INTO login_attempts (ip, username) VALUES (?, ?)',
        [client_ip_binary(), TRACK_THROTTLE_USER]);
}

/**
 * Find an order for a customer.
 *
 * Both must match. Returns null for a wrong code, a wrong phone, or a code that
 * exists under a different phone - the caller cannot tell which, deliberately:
 * distinguishing them would confirm that a code exists.
 */
function track_find(string $code, string $phone): ?array
{
    $code  = strtoupper(trim($code));
    $phone = digits_only($phone);

    if ($code === '' || $phone === '') {
        return null;
    }
    /* Accept the code with or without its dashes and in either case, because
       it arrives pasted from a chat message and nobody retypes it carefully.

       This comment was here before the code that makes it true. Stripping the
       stray characters is not enough: the column stores the canonical
       SPX-YYMMDD-XXXX, so a code pasted without dashes was looked up as
       SPX2508314F7A and matched nothing - while the FAQ told the customer
       plainly that either form works. The dashes go back on. */
    $code = preg_replace('/[^A-Z0-9-]/', '', $code) ?? '';
    if ($code === '' || mb_strlen($code) > 32) {
        return null;
    }
    /* Rebuilt only when the shape is unmistakable: the SPX prefix, six digits
       of date and the four-character suffix. Anything else is passed through
       as typed rather than guessed at. */
    if (preg_match('/^SPX([0-9]{6})([A-Z0-9]{4})$/', $code, $m)) {
        $code = 'SPX-' . $m[1] . '-' . $m[2];
    }

    $order = order_get_by_code($code);
    if ($order === null) {
        return null;
    }
    /* hash_equals rather than ===: the comparison is on a value the requester
       supplied, and constant time costs nothing here. */
    if (!hash_equals((string) $order['phone'], $phone)) {
        return null;
    }
    return $order;
}

/**
 * What the customer is told, per status.
 *
 * Written as what happens next rather than as a state name: "پرداخت شده" tells
 * them nothing they did not already know, and the question they actually have
 * is always "so what now".
 */
function track_status_note(string $status): string
{
    switch ($status) {
        case 'new':
            return 'سفارش ثبت شده و منتظر تأیید پرداخت است. اگر واریز کرده‌اید و '
                 . 'بیش از چند ساعت گذشته، رسید را در همان گفتگو بفرستید.';
        case 'paid':
            return 'پرداخت شما تأیید شد. سفارش در نوبت بسته‌بندی است.';
        case 'shipped':
            return 'سفارش تحویل پست داده شده است.';
        case 'done':
            return 'سفارش تکمیل شده است.';
        case 'cancelled':
            return 'این سفارش لغو شده است. اگر مبلغی واریز کرده‌اید، در همان گفتگو پیگیری کنید.';
        case 'expired':
            return 'مهلت این سفارش تمام شده و قیمت‌ها ممکن است تغییر کرده باشند. '
                 . 'برای ثبت دوباره، سبد را از نو بسازید.';
        default:
            return '';
    }
}
