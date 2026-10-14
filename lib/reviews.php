<?php
/* ===========================================================================
   SUPPEX — reviews, from the people who bought the thing
   ---------------------------------------------------------------------------
   The storefront has had stars and review counts in its markup from the
   start, switched off (cfg.flags.showRatings) because there were none; the
   three testimonials once shipped with it were invented, and removed. A
   rating is worth showing only when it comes from buyers.

   So a review is written on the order tracking page (track.php), by whoever
   has the order's code and the phone it was placed with - the two things that
   open the order - and only for a product in that order, once the order has
   been sent (shipped or done). One per product per order. Each waits for the
   owner in the panel (admin/reviews.php) and nothing is shown before it is
   approved. The stars on the cards and the product page, and aggregateRating
   in the structured data, count approved reviews alone, and appear only when
   there is one.

   What a buyer writes is shown as written, name included when they give one
   - «خریدار» otherwise. Nothing of the order is shown beside it.
   =========================================================================== */

declare(strict_types=1);

const REVIEW_STATUSES     = ['pending', 'approved', 'rejected'];
const REVIEW_ORDER_STATES = ['shipped', 'done'];
const REVIEW_BODY_MAX     = 1000;
const REVIEW_AUTHOR_MAX   = 40;

/** Has the table arrived (lib/migrate.php)? Until then: no reviews and no form. */
function reviews_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        $ready = db_value("SELECT 1 FROM information_schema.TABLES
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_reviews'") !== null;
    }
    return $ready;
}

/**
 * The products of an order a review can be written for, each with the review
 * already written for it, or null: an order that has been sent, and those of
 * its lines that still point at a product on sale. Two lines of one product -
 * two sizes - are one review.
 *
 * @return list<array{product_id:int,slug:string,name:string,review:?array}>
 */
function review_candidates(array $order): array
{
    if (!reviews_ready() || !in_array((string) ($order['status'] ?? ''), REVIEW_ORDER_STATES, true)) {
        return [];
    }
    $out  = [];
    $seen = [];
    foreach ((array) ($order['items'] ?? []) as $it) {
        $pid = (int) ($it['product_id'] ?? 0);
        if ($pid <= 0 || isset($seen[$pid])) {
            continue;
        }
        $p = db_one('SELECT id, slug, name_fa FROM products WHERE id = ? AND is_active = 1', [$pid]);
        if ($p === null) {
            continue;
        }
        $seen[$pid] = true;
        $out[] = [
            'product_id' => $pid,
            'slug'       => (string) $p['slug'],
            'name'       => (string) ($p['name_fa'] !== '' ? $p['name_fa'] : ($it['name_fa'] ?? '')),
            'review'     => db_one('SELECT id, rating, status FROM product_reviews WHERE order_id = ? AND product_id = ?',
                                   [(int) $order['id'], $pid]),
        ];
    }
    return $out;
}

/**
 * Write a review for a product of an order found by its code and phone
 * (track_find()). Everything else is checked here.
 *
 * @return array{ok:bool,error?:string}
 */
function review_submit(array $order, int $productId, int $rating, string $body, string $author): array
{
    if (!reviews_ready()) {
        return ['ok' => false, 'error' => 'ثبت نظر هنوز فعال نشده است.'];
    }
    if (!in_array((string) ($order['status'] ?? ''), REVIEW_ORDER_STATES, true)) {
        return ['ok' => false, 'error' => 'نظر دادن بعد از ارسال سفارش ممکن است.'];
    }
    $candidates = [];
    foreach (review_candidates($order) as $c) {
        $candidates[$c['product_id']] = $c;
    }
    if (!isset($candidates[$productId])) {
        return ['ok' => false, 'error' => 'این محصول در این سفارش نیست.'];
    }
    if ($candidates[$productId]['review'] !== null) {
        return ['ok' => false, 'error' => 'برای این محصول قبلاً نظر ثبت کرده‌اید.'];
    }
    if ($rating < 1 || $rating > 5) {
        return ['ok' => false, 'error' => 'لطفاً از یک تا پنج ستاره انتخاب کنید.'];
    }
    $body   = clean_multiline($body, REVIEW_BODY_MAX);
    $author = clean_text($author, REVIEW_AUTHOR_MAX);

    try {
        db_query('INSERT INTO product_reviews (product_id, order_id, rating, body, author) VALUES (?, ?, ?, ?, ?)',
                 [$productId, (int) $order['id'], $rating, $body, $author]);
    } catch (PDOException $e) {
        /* The unique key: the same form sent twice at once. */
        return ['ok' => false, 'error' => 'برای این محصول قبلاً نظر ثبت کرده‌اید.'];
    }
    review_notify($candidates[$productId]['name'], $rating, $body);
    return ['ok' => true];
}

/**
 * Tell the owner a review is waiting, in the chat new orders go to. Nothing
 * when Telegram is off - as it is in the tests and the local previews.
 */
function review_notify(string $productName, int $rating, string $body): void
{
    $cfg = suppex_config()['telegram_bot'] ?? [];
    if (empty($cfg['enabled']) || empty($cfg['token']) || empty($cfg['chat_id'])) {
        return;
    }
    $text = 'نظر تازه برای «' . $productName . '»: ' . str_repeat('★', $rating) . str_repeat('☆', 5 - $rating)
          . ($body !== '' ? "\n" . mb_substr($body, 0, 300) : '')
          . "\nتا تأییدش نکنید نمایش داده نمی‌شود: پنل ← نظرات";
    try {
        require_once __DIR__ . '/notify.php';
        telegram_call((string) $cfg['token'], 'sendMessage', ['chat_id' => (string) $cfg['chat_id'], 'text' => $text], 6);
    } catch (Throwable $e) {
        error_log('[suppex] review notice: ' . $e->getMessage());
    }
}

/**
 * A product's approved reviews, newest first.
 *
 * @return list<array{id:int,rating:int,body:string,author:string,created_at:string}>
 */
function reviews_for_product(int $productId, int $limit = 30): array
{
    if ($productId <= 0 || !reviews_ready()) {
        return [];
    }
    return db_all("SELECT id, rating, body, author, created_at FROM product_reviews
                    WHERE product_id = ? AND status = 'approved'
                    ORDER BY created_at DESC, id DESC LIMIT " . max(1, $limit), [$productId]);
}

/**
 * The approved reviews' count and average for each of these products, keyed
 * by product id; a product with none is not in the answer.
 *
 * @param list<int> $ids
 * @return array<int,array{count:int,avg:float}>
 */
function review_summaries(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0)));
    if (!$ids || !reviews_ready()) {
        return [];
    }
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $out = [];
    foreach (db_all("SELECT product_id, COUNT(*) AS n, AVG(rating) AS avg FROM product_reviews
                      WHERE status = 'approved' AND product_id IN ($in) GROUP BY product_id", $ids) as $r) {
        $out[(int) $r['product_id']] = ['count' => (int) $r['n'], 'avg' => round((float) $r['avg'], 1)];
    }
    return $out;
}

/** The API's product shapes, each with rating and reviewCount (0 when none). */
function reviews_attach(array $products): array
{
    $sum = review_summaries(array_column($products, 'id'));
    foreach ($products as $i => $p) {
        $s = $sum[(int) ($p['id'] ?? 0)] ?? null;
        $products[$i]['rating']      = $s['avg'] ?? 0;
        $products[$i]['reviewCount'] = $s['count'] ?? 0;
    }
    return $products;
}

/* --- the panel ------------------------------------------------------------ */

/**
 * Reviews in one state, newest first, with their product and order.
 *
 * @return list<array<string,mixed>>
 */
function reviews_list(string $status, int $limit = 200): array
{
    if (!reviews_ready() || !in_array($status, REVIEW_STATUSES, true)) {
        return [];
    }
    return db_all("SELECT r.*, p.slug, p.name_fa AS product_name, o.code AS order_code
                     FROM product_reviews r
                     JOIN products p ON p.id = r.product_id
                     JOIN orders o ON o.id = r.order_id
                    WHERE r.status = ?
                    ORDER BY r.created_at DESC, r.id DESC LIMIT " . max(1, $limit), [$status]);
}

function reviews_pending_count(): int
{
    return reviews_ready()
        ? (int) db_value("SELECT COUNT(*) FROM product_reviews WHERE status = 'pending'")
        : 0;
}

/** Approve or reject. False for an unknown state or review. */
function review_decide(int $id, string $status): bool
{
    if (!reviews_ready() || !in_array($status, ['approved', 'rejected'], true)) {
        return false;
    }
    return db_query('UPDATE product_reviews SET status = ?, decided_at = NOW() WHERE id = ?', [$status, $id])
        ->rowCount() > 0;
}

function review_delete(int $id): bool
{
    return reviews_ready() && db_query('DELETE FROM product_reviews WHERE id = ?', [$id])->rowCount() > 0;
}

/* --- drawing them ----------------------------------------------------------- */

/** Five stars, the share filled that ui.js's stars() fills. */
function review_stars_html(float $rating): string
{
    $full = (int) round($rating);
    $star = '<svg class="" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor"'
          . ' stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
          . '<path d="M12 2.6l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5-5.8-3-5.8 3 1.1-6.5L2.6 9.4l6.5-.9z" fill="currentColor" stroke="none"/></svg>';
    $out = '<span class="rating__stars" aria-hidden="true">';
    for ($i = 0; $i < 5; $i++) {
        $out .= '<span style="opacity:' . ($i < $full ? '1' : '0.25') . '">' . $star . '</span>';
    }
    return $out . '</span>';
}

/** The Persian calendar's year, month and day of a Gregorian date. */
function jalali_from_gregorian(int $gy, int $gm, int $gd): array
{
    $gdm  = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2  = $gm > 2 ? $gy + 1 : $gy;
    $days = 355666 + 365 * $gy + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400)
          + $gd + $gdm[$gm - 1];
    $jy   = -1595 + 33 * intdiv($days, 12053);
    $days %= 12053;
    $jy  += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy  += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        return [$jy, 1 + intdiv($days, 31), 1 + $days % 31];
    }
    return [$jy, 7 + intdiv($days - 186, 30), 1 + ($days - 186) % 30];
}

/** «۹ مهر ۱۴۰۵» for a stored date-time. */
function review_date_fa(string $datetime): string
{
    $t = strtotime($datetime);
    if ($t === false) {
        return '';
    }
    [$y, $m, $d] = jalali_from_gregorian((int) date('Y', $t), (int) date('n', $t), (int) date('j', $t));
    $months = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
               'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    return fa_digits($d . ' ' . $months[$m - 1] . ' ' . $y);
}
