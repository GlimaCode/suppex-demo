<?php
/* ===========================================================================
   SUPPEX — reading the dirham automatically
   ---------------------------------------------------------------------------
   The shop buys tubs in Dubai with dirhams and sells them in toman, so every
   dirham-priced product's shelf price is derived from an exchange rate that
   somebody has to keep current. Typing it by hand means it is right on the day
   it is typed and drifting every day after.

   This file only ever READS and RECORDS. It never touches a price. Applying is
   a separate, deliberate step in lib/pricing.php, and separating the two is
   what makes it safe to poll often: a bad reading costs a row in a table, not
   a catalogue full of wrong prices.

   WHAT THIS CODE KNOWS THAT IS NOT OBVIOUS
   ----------------------------------------
   Each of these was confirmed against the live sources, and every one of them
   is a way to be silently wrong:

   1. tgju publishes the dirham in RIAL, not toman. Divide by ten. The mistake
      is invisible because both numbers look like a plausible price - 59,716
      and 597,160 are equally believable to anyone not looking closely.

   2. call1.tgju.org through call5.tgju.org sit behind Cloudflare and served
      data a FULL DAY old while call.tgju.org was live. They are the hosts most
      examples on the web use. The stale copy is not obviously broken: it
      carries a real-looking price and d=0, which reads as "the market has not
      moved". Only the timestamp gives it away.

   3. The dirham is pegged to the dollar at 3.6725. tgju publishes both, so
      dividing one by the other is a free correctness check that catches a
      garbage response, a unit switch and a single-key staleness all at once,
      without needing a second network call.

   4. The per-exchange-office keys in the same feed do NOT share a unit.
      maskanexchange_aed_sell reads 57,000 where its neighbours read 582,000;
      one of them is toman and the rest are rial. Only price_aed is used here.

   5. alanchand serves the same number twice on one page: toman in the visible
      text, in Persian digits, and rial in a schema.org block in ASCII. The
      machine-readable one is the rial one. Parsing the wrong half is a
      ten-fold error that looks like careful work.
   =========================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
/* rate_status() reads the heartbeat out of settings and asks pricing what is
   currently applied, so both are required here rather than left to whichever
   page happens to include this file first. */
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/pricing.php';

/** The dirham's peg to the dollar, and how far off it may drift before a
    reading is treated as garbage rather than as news. The peg itself is hard;
    the tolerance covers the two quotes being seconds apart and each rounded. */
const RATE_PEG           = 3.6725;
const RATE_PEG_TOLERANCE = 0.15;

/** A quote older than this is stale, whatever it says. Iran's market prices
    for roughly fifty hours a week, so overnight and weekend quotes are
    legitimately hours old — three hours is inside a trading day but well short
    of the full-day staleness that the cached mirrors serve. */
const RATE_MAX_QUOTE_AGE_HOURS = 3;

/** Two independent sources that disagree by more than this are not confirming
    each other; one of them is wrong and there is no way to tell which. Measured
    live at 0.31% between tgju and alanchand, from the shop's own server. */
const RATE_CROSS_MAX_DIFF_PCT = 2.0;

/** Nothing outside this is a rate; it is a parsing accident.

    One pair of bounds, not two. An earlier version had a wide window here and a
    narrower one at apply time, which meant a figure divided by ten twice —
    5,970 for a dirham — was stored as an ACCEPTED reading and only refused
    hours later. A reading the shop cannot price from is not a reading, and
    logging it as good hides the parsing bug that produced it.

    Still very wide: the dirham sat near 60,000 when this was written, so the
    window spans a threefold fall and a sixfold rise. Anything beyond that is
    not news the shop should act on unattended. */
const RATE_ABSOLUTE_MIN = 20000.0;
const RATE_ABSOLUTE_MAX = 400000.0;

/**
 * One HTTP GET, using whichever mechanism this host actually has.
 *
 * Both paths check the status code. An earlier version of the probe trusted a
 * non-empty body, and reported a 404 page as a successful fetch.
 *
 * @return array{ok:bool,body:string,error:string}
 */
function rate_http_get(string $url, int $timeout = 20): array
{
    $ua = 'Mozilla/5.0 (compatible; SuppexBot/1.0; +https://suppex.ir)';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => $ua,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body !== false && $code === 200) {
            return ['ok' => true, 'body' => (string) $body, 'error' => ''];
        }
        if ($body === false) {
            return ['ok' => false, 'body' => '', 'error' => 'curl: ' . ($err ?: 'failed')];
        }
        return ['ok' => false, 'body' => '', 'error' => 'HTTP ' . $code];
    }

    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        return ['ok' => false, 'body' => '', 'error' => 'no curl and allow_url_fopen is off'];
    }

    $ctx = stream_context_create(['http' => [
        'timeout' => $timeout, 'user_agent' => $ua, 'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) { $code = (int) $m[1]; }
    }
    if ($body !== false && $code === 200) {
        return ['ok' => true, 'body' => (string) $body, 'error' => ''];
    }

    return ['ok' => false, 'body' => '', 'error' => $body === false ? 'fopen failed' : ('HTTP ' . $code)];
}

/**
 * "593,340" -> 593340.0. Returns null for anything that is not a number,
 * rather than PHP's 0 — an HTML error page casts to 0 and 0 is a rate the
 * arithmetic downstream would happily use.
 */
function rate_parse_number(?string $raw): ?float
{
    if ($raw === null) {
        return null;
    }
    $clean = str_replace([',', ' ', "\u{200c}", "\u{a0}"], '', to_latin_digits(trim($raw)));
    if ($clean === '' || !preg_match('~^\d+(\.\d+)?$~', $clean)) {
        return null;
    }

    return (float) $clean;
}

/**
 * The dirham from tgju, in TOMAN, with everything needed to judge it.
 *
 * call.tgju.org, with no digit. The numbered mirrors are Cloudflare-cached and
 * were a full day stale when checked against this host.
 *
 * @return array{ok:bool,rate:?float,ts:?int,peg:?float,error:string}
 */
function rate_fetch_tgju(): array
{
    $fail = static fn(string $why): array =>
        ['ok' => false, 'rate' => null, 'ts' => null, 'peg' => null, 'error' => $why];

    $res = rate_http_get('https://call.tgju.org/ajax.json');
    if (!$res['ok']) {
        return $fail('tgju unreachable — ' . $res['error']);
    }

    $json = json_decode($res['body'], true);
    if (!is_array($json) || !isset($json['current']) || !is_array($json['current'])) {
        return $fail('tgju returned something that is not the expected JSON');
    }
    $cur = $json['current'];

    /* price_aed and nothing else. Its siblings in this same feed disagree about
       whether they are rial or toman. */
    $rial = rate_parse_number($cur['price_aed']['p'] ?? null);
    if ($rial === null) {
        return $fail('tgju: price_aed missing or not a number');
    }

    $toman = $rial / 10;

    $ts = null;
    $tsRaw = trim((string) ($cur['price_aed']['ts'] ?? ''));
    if ($tsRaw !== '') {
        $parsed = strtotime($tsRaw);
        $ts = $parsed === false ? null : $parsed;
    }

    /* The peg check. Free, and it catches a garbage body, a unit switch and one
       key going stale while the other did not. */
    $peg = null;
    $usd = rate_parse_number($cur['price_dollar_rl']['p'] ?? null);
    if ($usd !== null && $rial > 0) {
        $peg = round($usd / $rial, 4);
    }

    return ['ok' => true, 'rate' => $toman, 'ts' => $ts, 'peg' => $peg, 'error' => ''];
}

/**
 * The dirham from alanchand, in TOMAN, read from the schema.org block.
 *
 * The visible text on that page is toman in Persian digits; the ld+json is rial
 * in ASCII with a machine-readable timestamp. The second is the one worth
 * parsing, and dividing it by ten is not optional.
 *
 * @return array{ok:bool,rate:?float,ts:?int,error:string}
 */
function rate_fetch_alanchand(): array
{
    $fail = static fn(string $why): array =>
        ['ok' => false, 'rate' => null, 'ts' => null, 'peg' => null, 'error' => $why];

    $one = static function (string $currency): ?array {
        $res = rate_http_get('https://alanchand.com/currencies-price/' . $currency);
        if (!$res['ok']) {
            return null;
        }
        if (!preg_match('~"price"\s*:\s*"?([0-9]+(?:\.[0-9]+)?)"?~', $res['body'], $m)) {
            return null;
        }
        $rial = (float) $m[1];
        if ($rial <= 0) {
            return null;
        }
        $ts = null;
        if (preg_match('~"priceValidUntil"\s*:\s*"([^"]+)"~', $res['body'], $t)) {
            $parsed = strtotime($t[1]);
            /* priceValidUntil is the end of the window this quote is good for,
               so it sits slightly in the FUTURE on a live page. Treated as the
               freshness stamp it is: a page that stopped updating leaves it in
               the past, which is exactly the signal wanted. */
            $ts = $parsed === false ? null : $parsed;
        }
        return ['rial' => $rial, 'ts' => $ts];
    };

    $aed = $one('aed');
    if ($aed === null) {
        return $fail('alanchand: no usable dirham price on the page');
    }

    /* The dollar page too, so this source can check itself against the peg
       instead of depending on tgju to do it. That independence is the whole
       point: on 2026-09-03 tgju froze at a 34-hour-old quote while alanchand
       stayed live, and a source that can only be validated by the one that
       broke is not a second source at all. */
    $peg = null;
    $usd = $one('usd');
    if ($usd !== null && $aed['rial'] > 0) {
        $peg = round($usd['rial'] / $aed['rial'], 4);
    }

    return ['ok' => true, 'rate' => $aed['rial'] / 10, 'ts' => $aed['ts'],
            'peg' => $peg, 'error' => ''];
}

/**
 * Take one reading and write it down, accepted or not.
 *
 * Never changes a price. The row it writes is the whole product: a rejected
 * reading with a reason is what later explains why the shelf stopped moving,
 * and a table holding only successes cannot tell "the rate held steady" from
 * "nothing answered for two days".
 *
 * @return array{accepted:bool,rate:?float,reason:string}
 */
/**
 * Judge two readings without fetching anything.
 *
 * Split out from rate_poll() so the guards can be tested. Guards that can only
 * be exercised by having a real source misbehave on cue are guards nobody ever
 * proves, and an unproven guard is indistinguishable from an absent one — which
 * is exactly the problem this whole file exists to avoid.
 *
 * @param array $primary  as returned by rate_fetch_tgju()
 * @param array|null $second  as returned by rate_fetch_alanchand(), or null
 * @param int|null $now  overridable so a stale-quote test does not need to wait
 * @return array{reasons:array<int,string>,cross:?float}
 */
function rate_source_check(array $reading, int $now): array
{
    if (empty($reading['ok'])) {
        return [(string) ($reading['error'] ?? 'پاسخ نداد')];
    }

    $reasons = [];
    $rate = $reading['rate'] ?? null;
    $ts   = $reading['ts'] ?? null;
    $peg  = $reading['peg'] ?? null;

    if ($rate === null || $rate < RATE_ABSOLUTE_MIN || $rate > RATE_ABSOLUTE_MAX) {
        $reasons[] = sprintf('نرخ %s خارج از محدوده ممکن است',
            $rate === null ? '—' : number_format($rate));
    }
    if ($ts === null) {
        $reasons[] = 'بدون زمان بود';
    } elseif ($now - $ts > RATE_MAX_QUOTE_AGE_HOURS * 3600) {
        $reasons[] = sprintf('%.1f ساعت قدیمی بود', ($now - $ts) / 3600);
    }
    if ($peg === null) {
        $reasons[] = 'دلار برای بررسی نسبت میخکوب نبود';
    } elseif (abs($peg - RATE_PEG) > RATE_PEG_TOLERANCE) {
        $reasons[] = sprintf('نسبت دلار به درهم %.3f بود، نه %.3f', $peg, RATE_PEG);
    }

    return $reasons;
}

/**
 * Judge a set of readings without fetching anything.
 *
 * The sources are weighed EQUALLY, and that is a correction rather than a
 * preference. The first version made tgju primary and alanchand a mere
 * cross-check, and on 2026-09-03 tgju froze at a 34-hour-old quote — with a
 * perfectly plausible number and d=0, so only its timestamp gave it away —
 * while alanchand stayed live. Because the "primary" was stale the whole poll
 * was refused, and a shop with a good live source in hand priced nothing at
 * all. A guard that turns one source's outage into a total outage is not
 * protecting anything.
 *
 * So each source is checked against its own timestamp, its own peg and the
 * absolute bounds, and then:
 *
 *   two that pass and agree   accepted, and confirmed
 *   two that pass and differ  refused — one of them is wrong and there is
 *                             nothing here that says which
 *   one that passes           accepted, marked degraded. It proved its own
 *                             freshness and its own peg; what it lacks is
 *                             corroboration, which is worth flagging and not
 *                             worth stopping for
 *   none                      refused
 *
 * @param array<string,array> $readings  source name => fetch result
 * @return array{rate:?float,ts:?int,peg:?float,cross:?float,used:array<int,string>,
 *               degraded:bool,reasons:array<int,string>}
 */
function rate_judge(array $readings, ?int $now = null): array
{
    $now  = $now ?? time();
    $good = [];
    $bad  = [];

    foreach ($readings as $name => $reading) {
        if (!is_array($reading)) {
            continue;
        }
        $problems = rate_source_check($reading, $now);
        if ($problems === []) {
            $good[$name] = $reading;
        } else {
            $bad[] = $name . ': ' . implode('، ', $problems);
        }
    }

    $empty = ['rate' => null, 'ts' => null, 'peg' => null, 'cross' => null,
              'used' => [], 'degraded' => false];

    if ($good === []) {
        return $empty + ['reasons' => $bad !== [] ? $bad : ['هیچ منبعی پاسخ نداد']];
    }

    $names = array_keys($good);
    $first = $good[$names[0]];

    if (count($good) === 1) {
        return [
            'rate' => $first['rate'], 'ts' => $first['ts'], 'peg' => $first['peg'] ?? null,
            'cross' => null, 'used' => $names, 'degraded' => true,
            /* Not a refusal, but the reason the other one dropped out is kept
               so the panel can show what "degraded" actually means today. */
            'reasons' => [],
            'note' => $bad === [] ? '' : implode(' | ', $bad),
        ];
    }

    /* Two or more: they have to agree. This is the check that catches a source
       which is confidently wrong rather than obviously broken — a frozen feed
       is internally consistent, so nothing inside it can reveal the problem. */
    $second = $good[$names[1]];
    $a = (float) $first['rate'];
    $b = (float) $second['rate'];
    $diff = $a > 0 ? abs($b - $a) / $a * 100 : 100.0;

    if ($diff > RATE_CROSS_MAX_DIFF_PCT) {
        return $empty + ['reasons' => [sprintf(
            '%s و %s %.1f%% اختلاف داشتند (%s و %s)',
            $names[0], $names[1], $diff, number_format($a), number_format($b))]];
    }

    return [
        /* The lower of two agreeing sources. Within a 2% band the difference is
           noise, and taking the smaller one means the shelf never leads the
           market up on a disagreement the shop cannot resolve. */
        'rate' => min($a, $b), 'ts' => $first['ts'], 'peg' => $first['peg'] ?? null,
        'cross' => $b, 'used' => $names, 'degraded' => false, 'reasons' => [], 'note' => '',
    ];
}

function rate_poll(): array
{
    /* Both, always. The old version skipped the second fetch when the first
       failed, which saved a request and cost the shop every day that the first
       source was the broken one. */
    $verdict = rate_judge([
        'tgju'      => rate_fetch_tgju(),
        'alanchand' => rate_fetch_alanchand(),
    ]);

    $reasons = $verdict['reasons'];
    $rate    = $verdict['rate'];
    $ts      = $verdict['ts'];
    $peg     = $verdict['peg'];
    $cross   = $verdict['cross'];

    $accepted = $reasons === [];

    /* A degraded poll is a real reading and is stored as accepted — but which
       source it came from, and why the other dropped out, is written down with
       it. Without that, a week of single-source pricing is invisible. */
    if ($accepted && $verdict['degraded']) {
        $reasons = [];
    }

    db_query(
        'INSERT INTO rate_polls (rate, source, source_ts, cross_rate, cross_source,
                                 peg, accepted, reason)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $rate === null ? null : round((float) $rate, 2),
            /* Which source this actually came from, not an assumption. When one
               of the two is down for a week, this column is the only place that
               fact is written down. */
            mb_substr(implode('+', $verdict['used']) ?: '—', 0, 40),
            $ts === null ? null : date('Y-m-d H:i:s', $ts),
            $cross === null ? null : round($cross, 2),
            $cross === null ? '' : (string) ($verdict['used'][1] ?? ''),
            $peg,
            $accepted ? 1 : 0,
            mb_substr($accepted
                ? (string) ($verdict['note'] ?? '')     // why it is degraded, if it is
                : implode(' | ', $reasons), 0, 200),
        ]
    );

    return [
        'accepted' => $accepted,
        'rate'     => $rate,
        'degraded' => (bool) ($verdict['degraded'] ?? false),
        'used'     => $verdict['used'],
        'reason'   => $accepted
            ? (string) ($verdict['note'] ?? '')
            : implode(' | ', $reasons),
    ];
}

/**
 * Accepted readings from the last $hours hours, newest first.
 *
 * @return array<int,array>
 */
function rate_recent_polls(int $hours = 24): array
{
    return db_all(
        'SELECT rate, source_ts, cross_rate, peg, created_at
           FROM rate_polls
          WHERE accepted = 1 AND rate IS NOT NULL
            AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
          ORDER BY created_at DESC',
        [max(1, $hours)]
    );
}

/** How much the shelf may move in one automatic step, and the window outside
    which a reading is not news but a parsing accident.

    The absolute window matters more than it looks. Every other guard here is
    RELATIVE — the peg is a ratio, the cross-check is an agreement, the bands
    are percentages — and a relative guard is blind to any failure that moves
    both of its operands. tgju and alanchand agreeing to 0.31% is one number
    wearing two coats, not two independent price discoveries, so if the upstream
    they share redefines what it publishes, every relative check still reports
    green. Only a hard-coded window catches that. */
const RATE_APPLY_ABS_MIN = RATE_ABSOLUTE_MIN;
const RATE_APPLY_ABS_MAX = RATE_ABSOLUTE_MAX;

/** A lone source needs agreement with recent history before it can move the
    catalogue. Wait for confirmation instead of manufacturing a rate by
    clipping it to the median. Two agreeing sources already confirm the rate. */
const RATE_SINGLE_SOURCE_MAX_DEVIATION_PCT = 2.0;

/** Beyond this, one automatic step will not go — but it CLAMPS rather than
    refuses. Refusing looks safe and is not: on a genuine 12% move, doing
    nothing guarantees selling below replacement cost on every dirham-priced
    product until a human happens to look. Moving the allowed maximum and
    saying so is wrong by less, in the safer direction. */
const RATE_MAX_STEP_PCT = 10.0;

/** A reading this far from the anchor — the rate the owner last confirmed by
    hand — is not clamped but held for a person. Clamping assumes the direction
    is real; three-fold means something broke. */
const RATE_ANCHOR_FACTOR = 3.0;

/**
 * The middle of a set of readings.
 *
 * A median rather than an average because the failure this defends against is
 * one bad print, and one bad print moves an average by a fraction of itself
 * while leaving a median untouched.
 */
function rate_median(array $rates): ?float
{
    $vals = [];
    foreach ($rates as $r) {
        $v = is_array($r) ? ($r['rate'] ?? null) : $r;
        if ($v !== null && (float) $v > 0) { $vals[] = (float) $v; }
    }
    if ($vals === []) {
        return null;
    }

    sort($vals);
    $n = count($vals);
    $mid = intdiv($n, 2);

    return $n % 2 === 1 ? $vals[$mid] : ($vals[$mid - 1] + $vals[$mid]) / 2;
}

/**
 * The rate to price from now, out of the recent readings - or why there is none.
 *
 * Asked every hour since 2026-10-02, when the owner asked for the shelf to
 * follow the dirham hour by hour instead of once a night. The daily apply
 * needed the whole day COVERED - twelve hours of readings, no four-hour hole -
 * because it set the shelf for the next day from one look. An hourly apply
 * sets it for the next hour and then looks again, so what it needs is:
 *
 *   fresh      the newest accepted reading is under two hours old. Overnight
 *              and at weekends the market stops quoting, the readings are
 *              refused as stale (rate_source_check()) and nothing is applied:
 *              the shelf stays on the last market price, which is right. A day
 *              rule here would have held the shelf for a day after every gap;
 *   confirmed  both sources agreed on it, or there are three readings in the
 *              last six hours to measure it against. One source standing alone
 *              is a real reading, not one to reprice the shop on by itself;
 *   exact      the newest confirmed reading is used in full. A lone source
 *              more than 2% from the six-hour median waits for confirmation;
 *              it never turns into an invented, partly applied market rate.
 *
 * Six readings taken eighteen hours ago are still refused by the first rule:
 * they describe a market that has since moved.
 *
 * @param array<int,array> $polls  accepted polls, newest first (cross_rate set
 *                                 when the second source agreed)
 * @return array{ok:bool,rate:?float,reason:string,used:int}
 */
function rate_estimate(array $polls, ?int $now = null): array
{
    $now  = $now ?? time();
    $fail = static fn(string $why): array =>
        ['ok' => false, 'rate' => null, 'reason' => $why, 'used' => 0];

    $rows = [];
    foreach ($polls as $p) {
        $t = isset($p['created_unix']) ? (int) $p['created_unix']
            : strtotime((string) ($p['created_at'] ?? ''));
        $r = (float) ($p['rate'] ?? 0);
        if ($t > 0 && $r > 0 && $now - $t <= 24 * 3600) {
            $rows[] = ['t' => $t, 'r' => $r, 'confirmed' => ($p['cross_rate'] ?? null) !== null];
        }
    }
    if ($rows === []) {
        return $fail('هیچ خوانش پذیرفته‌شده‌ای در ۲۴ ساعت گذشته نبود');
    }

    usort($rows, static fn(array $a, array $b): int => $b['t'] <=> $a['t']);

    /* Fresh. A reading from before an outage, or from before the market
       closed, is not a reading about now. */
    $ageHours = ($now - $rows[0]['t']) / 3600;
    if ($ageHours > 2) {
        return $fail(sprintf('تازه‌ترین خوانش %.1f ساعت قدیمی است', $ageHours));
    }

    $recent = [];
    foreach ($rows as $r) {
        if ($now - $r['t'] <= 6 * 3600) { $recent[] = $r['r']; }
    }

    /* Confirmed. */
    if (!$rows[0]['confirmed'] && count($recent) < 3) {
        return $fail('تازه‌ترین خوانش را فقط یک منبع داده و در شش ساعت اخیر خوانش کافی برای سنجیدنش نیست');
    }

    /* The shop follows the actual newest reading, not a smoothed substitute.
       History still checks a lone source; two agreeing sources can confirm a
       genuine move immediately. */
    $mid = rate_median($recent);
    if ($mid === null || $mid <= 0) {
        return $fail('میانه قابل محاسبه نبود');
    }

    if (!$rows[0]['confirmed']
        && abs(($rows[0]['r'] - $mid) / $mid * 100) > RATE_SINGLE_SOURCE_MAX_DEVIATION_PCT) {
        return $fail('نرخ تازهٔ تک‌منبعی بیش از ۲٪ با میانهٔ شش ساعت اخیر اختلاف دارد؛ منتظر تأیید منبع دوم یا خوانش‌های بیشتر');
    }

    return ['ok' => true, 'rate' => round($rows[0]['r'], 2), 'reason' => '', 'used' => count($recent)];
}

/**
 * What to do with a candidate rate.
 *
 * Separated from everything that touches the database so the decision can be
 * tested at every boundary rather than inferred from behaviour.
 *
 * @param float|null $applied  the rate the shelf is currently priced from
 * @param float|null $anchor   the last rate a human confirmed
 * @return array{action:string,rate:?float,reason:string}
 *         action: bootstrap | reject | hold | skip | clamp | apply
 */
function rate_decide(?float $applied, ?float $anchor, float $candidate): array
{
    $out = static fn(string $a, ?float $r, string $why): array =>
        ['action' => $a, 'rate' => $r, 'reason' => $why];

    /* Absolute first, because it is the only check that survives both sources
       being wrong together. */
    if (!is_finite($candidate)
        || $candidate < RATE_APPLY_ABS_MIN || $candidate > RATE_APPLY_ABS_MAX) {
        return $out('reject', null, sprintf(
            'نرخ %s بیرون از محدوده مجاز (%s تا %s) است',
            number_format($candidate), number_format(RATE_APPLY_ABS_MIN),
            number_format(RATE_APPLY_ABS_MAX)));
    }

    /* No history: refuse and say so, rather than dividing by it. A shop that
       has never had a rate applied needs a person to set the first one — and
       without this the percentage arithmetic below throws DivisionByZeroError
       inside a cron job, where nobody sees it. */
    if ($applied === null || $applied <= 0) {
        return $out('bootstrap', null,
            'هنوز هیچ نرخی دستی اعمال نشده — اولین نرخ را باید یک نفر ثبت کند');
    }

    if ($anchor !== null && $anchor > 0) {
        $factor = $candidate / $anchor;
        if ($factor > RATE_ANCHOR_FACTOR || $factor < 1 / RATE_ANCHOR_FACTOR) {
            return $out('hold', null, sprintf(
                'نرخ %s نسبت به لنگر %s %.1f برابر است — نیاز به تأیید انسان',
                number_format($candidate), number_format($anchor), $factor));
        }
    }

    $changePct = ($candidate - $applied) / $applied * 100;

    /* Rates are stored to two decimal places. Every actual change, up or down,
       must reach the catalogue in this run; only the same stored rate skips. */
    if (round($candidate, 2) === round($applied, 2)) {
        return $out('skip', null, 'نرخ تازه با نرخ اعمال‌شده یکسان است');
    }

    /* A big move is treated differently by direction, because the two are not
       symmetric in what they cost.

       UP: refusing looks safe and is not. Leaving every dirham-priced product
       at yesterday's price through a real 12% rise means selling below
       replacement cost on each one until somebody happens to look. Moving the
       maximum allowed and announcing it is wrong by less, in the cheaper
       direction.

       DOWN: the same reasoning inverts. A large claimed FALL in the dirham is
       far more often a bad reading than a real market, and acting on it hands
       away margin on every sale. Nothing is lost by waiting for a person: if
       the fall is real, prices being briefly too high costs goodwill, not
       money. */
    if ($changePct > RATE_MAX_STEP_PCT) {
        $limit = $applied * (1 + RATE_MAX_STEP_PCT / 100);
        return $out('clamp', round($limit, 2), sprintf(
            'افزایش %.1f%% بیش از سقف %.0f%% بود؛ تا سقف اعمال شد (%s به‌جای %s)',
            $changePct, RATE_MAX_STEP_PCT, number_format($limit), number_format($candidate)));
    }
    if ($changePct < -RATE_MAX_STEP_PCT) {
        return $out('hold', null, sprintf(
            'کاهش %.1f%% بیش از سقف %.0f%% بود — کاهش بزرگ خودکار اعمال نمی‌شود، نیاز به تأیید انسان',
            $changePct, RATE_MAX_STEP_PCT));
    }

    return $out('apply', round($candidate, 2),
        sprintf('تغییر %.2f%%', $changePct));
}

/**
 * One automatic look at the shelf: reprice every dirham-priced product from the
 * latest confirmed reading whenever it changes, and record the decision.
 *
 * tools/rate-cron.php calls this every hour, straight after its poll. It used
 * to wait for twenty hours since the last apply AND for four in the morning, so
 * a dirham that rose at ten reached the shelf the next day; the owner asked on
 * 2026-10-02 for every hourly reading to be applied there and then.
 *
 * Small rises and falls are applied too (owner request, 2026-10-20). Source
 * validation and exceptional-move guards still run. A quote holds the price a
 * shopper was shown while they check out (lib/quotes.php), and an order holds
 * its total for its payment window (lib/orders.php).
 *
 * Kept out of the cron script so it can be tested without the network.
 *
 * @return array{action:string,reason:string,rate:?float,estimate:?float,applied:int,skipped:int}
 *   action: wait (nothing fresh to price from) | skip | bootstrap | reject |
 *           hold | clamp | apply
 */
function rate_auto_apply(?int $now = null): array
{
    $now = $now ?? time();
    $out = ['action' => 'wait', 'reason' => '', 'rate' => null, 'estimate' => null,
            'applied' => 0, 'skipped' => 0];

    $estimate = rate_estimate(rate_recent_polls(24), $now);
    if (!$estimate['ok']) {
        $out['reason'] = (string) $estimate['reason'];
        rate_auto_note($out, $now);
        return $out;
    }
    $out['estimate'] = (float) $estimate['rate'];

    $anchorRaw = trim((string) setting('rate_anchor', ''));
    $decision  = rate_decide(pricing_last_applied_rate(),
                             $anchorRaw === '' ? null : (float) $anchorRaw,
                             (float) $estimate['rate']);
    $out['action'] = $decision['action'];
    $out['reason'] = $decision['reason'];
    $out['rate']   = $decision['rate'];

    if ($decision['action'] === 'skip') {
        /* Not news: the shelf is already on exactly this rate. It
           counts as a check for «are these prices current?», which is what
           pricing_staleness() reads it as. */
        settings_save(['rate_auto_last_apply' => (string) $now]);
    } elseif ($decision['action'] === 'clamp' || $decision['action'] === 'apply') {
        $res = pricing_apply((float) $decision['rate'], ['id' => null, 'name' => 'خودکار'], null,
                             $decision['action'] === 'clamp' ? 'auto-clamped' : 'auto');
        settings_save(['rate_auto_last_apply' => (string) $now]);
        $out['applied'] = (int) $res['applied'];
        $out['skipped'] = (int) $res['skipped'];
    }

    rate_auto_note($out, $now);
    return $out;
}

/** The last automatic check in a sentence for the pricing page, and when. */
function rate_auto_note(array $out, int $now): void
{
    switch ($out['action']) {
        case 'wait':
            $note = 'نرخ تازه‌ای برای اعمال نبود: ' . $out['reason'];
            break;
        case 'skip':
            $note = 'قیمت‌ها با بازار هم‌خوان بود و دست نخورد (' . $out['reason'] . ')';
            break;
        case 'apply':
        case 'clamp':
            $note = sprintf('با نرخ %s روی %d قلم اعمال شد', number_format((float) $out['rate']), $out['applied'])
                  . ($out['action'] === 'clamp' ? ' — ' . $out['reason'] : '');
            break;
        default:
            $note = 'اعمال نشد: ' . $out['reason'];
    }
    settings_save(['rate_auto_last_check' => (string) $now,
                   'rate_auto_last_note'  => mb_substr($note, 0, 300)]);
}

/**
 * Everything the panel needs to answer "is this thing working?".
 *
 * The question that matters is not what the dirham is — that is on the page
 * already — but whether the machinery reading it is alive. An automatic pricing
 * job fails silently by nature: the prices simply stop moving, which looks
 * exactly like a quiet market. So the health signals are what this returns, and
 * the most important of them is the heartbeat, because a cron that stopped
 * running produces no errors at all.
 *
 * @return array<string,mixed>
 */
function rate_status(): array
{
    $now = time();

    $int = static function (string $key) {
        $v = trim((string) setting($key, ''));
        return $v === '' ? null : (int) $v;
    };

    $lastCron  = $int('rate_cron_last_run');
    $lastAuto  = $int('rate_auto_last_apply');
    $lastCheck = $int('rate_auto_last_check');
    $anchorRaw = trim((string) setting('rate_anchor', ''));

    $polls    = rate_recent_polls(24);
    $estimate = rate_estimate($polls, $now);

    $total24 = (int) db_value(
        'SELECT COUNT(*) FROM rate_polls WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)');

    $applied = pricing_last_applied_rate();

    return [
        /* Armed means a human has applied a rate at least once. Until then the
           automation deliberately refuses: the first price in a shop is a
           decision, not a reading. */
        'armed'        => $applied !== null && $applied > 0,
        'applied_rate' => $applied,
        'anchor'       => $anchorRaw === '' ? null : (float) $anchorRaw,

        /* The heartbeat. A cron that is not running is the failure mode with no
           symptom — every other problem at least writes a row. */
        'last_cron'      => $lastCron,
        'cron_age_hours' => $lastCron === null ? null : ($now - $lastCron) / 3600,
        'cron_healthy'   => $lastCron !== null && ($now - $lastCron) < 3 * 3600,

        'last_auto'      => $lastAuto,
        'auto_age_hours' => $lastAuto === null ? null : ($now - $lastAuto) / 3600,
        /* What the hourly check last did, in a sentence (rate_auto_note()). */
        'last_check'     => $lastCheck,
        'last_note'      => trim((string) setting('rate_auto_last_note', '')),

        'polls_24h'    => $total24,
        'accepted_24h' => count($polls),
        'ready'        => $estimate['ok'],
        'ready_reason' => $estimate['reason'],
        'estimate'     => $estimate['rate'],
    ];
}

/**
 * The last $limit readings, refused ones included.
 *
 * The refusals are the useful half. "Prices stopped updating" is a mystery
 * until you can see that every reading for two days said the two sources
 * disagreed.
 *
 * @return array<int,array>
 */
function rate_poll_log(int $limit = 40): array
{
    $limit = max(1, min($limit, 200));

    return db_all(
        'SELECT rate, cross_rate, peg, accepted, reason, source_ts, created_at
           FROM rate_polls ORDER BY id DESC LIMIT ' . $limit
    );
}
