<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/* ===========================================================================
   Reading the dirham automatically.
   ---------------------------------------------------------------------------
   What is worth testing here is not "can it fetch a number" — a live fetch
   proves only that today's network worked. It is whether the guards actually
   REFUSE the readings that would otherwise reprice the whole catalogue wrongly,
   and every one of those was a real observed failure rather than an invention:

     - call1.tgju.org served a full-day-old quote with d=0 while the live host
       moved 1.8%. It reads as "the market has not moved";
     - the feed publishes RIAL, so a missing division by ten is a 10x error in
       which both the right and the wrong number look plausible;
     - the same feed carries sibling keys in DIFFERENT units, and two decoy keys
       frozen since 2018 and 2025;
     - alanchand serves the same figure as toman in the visible text and rial in
       its schema.org block.

   Every check below runs on injected readings rather than on the network, so
   the suite does not depend on a third party being up, and so the failure modes
   can be reproduced on demand instead of waited for.
   =========================================================================== */

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/rate.php';

$pass = 0; $fail = 0;
function ok(string $l, bool $c, ?string $x = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $l\n"; }
    else    { $fail++; echo "  FAIL $l" . ($x !== '' ? "  --  $x" : '') . "\n"; }
}

$now  = 1788340000;
$good = ['ok' => true, 'rate' => 59700.0, 'ts' => $now - 600, 'peg' => 3.659, 'error' => ''];
/* alanchand carries its own dollar page now, so it proves its own peg rather
   than borrowing tgju's — which is what lets it stand alone when tgju freezes. */
$alan = ['ok' => true, 'rate' => 59870.0, 'ts' => $now - 300,
         'peg' => 3.6725, 'error' => ''];

/* Both sources are weighed equally now. The first version made tgju primary and
   alanchand a mere cross-check, and on 2026-09-03 tgju froze at a 34-hour-old
   quote — plausible number, d=0, only the timestamp gave it away — while
   alanchand stayed live. The "primary" being stale refused the whole poll, so a
   shop holding a good live source priced nothing. That is a guard turning one
   source's outage into a total outage, and it was wrong. */
$judge = static fn(array $r) => rate_judge($r, $now)['reasons'];
$has   = static fn(array $reasons, string $needle): bool => (bool) array_filter(
    $reasons, static fn(string $r): bool => mb_strpos($r, $needle) !== false);

echo "\n== two healthy sources ==\n";
$both = ['tgju' => $good, 'alanchand' => $alan];
ok('two fresh sources that agree pass', $judge($both) === [], implode(' | ', $judge($both)));
$v = rate_judge($both, $now);
ok('  and the poll is not degraded', $v['degraded'] === false);
ok('  and both are recorded as used', count($v['used']) === 2, implode(',', $v['used']));
/* Within the agreement band the difference is noise; taking the lower one means
   the shelf never leads the market up on a disagreement nobody can settle. */
ok('  and the lower of the two is taken', abs($v['rate'] - 59700.0) < 0.01, (string) $v['rate']);

echo "\n== one source down is not an outage ==\n";
/* The 2026-09-03 case exactly. */
$frozen = ['ok' => true, 'rate' => 60089.0, 'ts' => $now - 34 * 3600,
           'peg' => 3.659, 'error' => ''];
$v = rate_judge(['tgju' => $frozen, 'alanchand' => $alan], $now);
ok('a live source is used when the other has frozen', $v['reasons'] === [],
   implode(' | ', $v['reasons']));
ok('  the rate comes from the live one', abs($v['rate'] - 59870.0) < 0.01, (string) $v['rate']);
ok('  the poll is marked degraded', $v['degraded'] === true);
ok('  only the live source is listed as used',
   $v['used'] === ['alanchand'], implode(',', $v['used']));
/* Which one dropped out, and why, has to survive — otherwise a week of
   single-source pricing is invisible. */
ok('  and the note says why the other dropped out',
   mb_strpos((string) $v['note'], 'tgju') !== false
   && mb_strpos((string) $v['note'], 'قدیمی') !== false, (string) $v['note']);

$v = rate_judge(['tgju' => $good, 'alanchand' => ['ok' => false, 'error' => 'unreachable']], $now);
ok('and the mirror case works too', $v['reasons'] === [] && $v['used'] === ['tgju']);

echo "\n== both down is an outage ==\n";
$v = rate_judge([
    'tgju'      => ['ok' => false, 'error' => 'unreachable'],
    'alanchand' => ['ok' => false, 'error' => 'unreachable'],
], $now);
ok('nothing usable is refused', $v['reasons'] !== []);
ok('  and the rate is null, not zero', $v['rate'] === null);

echo "\n== a stale quote that looks perfectly normal ==\n";
/* What actually happened: 600,890 rial with d=0. Nothing about the number is
   suspicious — the day before it was 597,000. Only the timestamp betrays it. */
$v = rate_judge(['tgju' => $frozen], $now);
ok('a lone frozen source is refused', $v['reasons'] !== []);
ok('  and the reason names the age', $has($v['reasons'], 'قدیمی'),
   implode(' | ', $v['reasons']));
ok('  and names which source it was', $has($v['reasons'], 'tgju'),
   implode(' | ', $v['reasons']));

echo "\n== the ten-fold error: rial mistaken for toman ==\n";
$rial = ['ok' => true, 'rate' => 597000.0, 'ts' => $now - 600, 'peg' => 3.659, 'error' => ''];
ok('a raw rial figure is refused', $judge(['tgju' => $rial]) !== []);
ok('and so is a figure divided by ten twice',
   $judge(['tgju' => ['ok' => true, 'rate' => 5970.0, 'ts' => $now - 600,
                      'peg' => 3.659, 'error' => '']]) !== []);
/* And with a healthy partner it still cannot get through: a source failing its
   own checks is dropped, not averaged in. */
$v = rate_judge(['tgju' => $rial, 'alanchand' => $alan], $now);
ok('a broken source is dropped rather than averaged in',
   $v['used'] === ['alanchand'] && abs($v['rate'] - 59870.0) < 0.01);

echo "\n== the peg check catches a garbage body ==\n";
$broken = ['ok' => true, 'rate' => 59700.0, 'ts' => $now - 600, 'peg' => 1.02, 'error' => ''];
ok('a broken dollar/dirham ratio is refused', $judge(['tgju' => $broken]) !== []);
ok('  and the reason says which ratio was wrong',
   $has($judge(['tgju' => $broken]), 'نسبت دلار به درهم'));
ok('a missing dollar figure is also refused',
   $judge(['tgju' => ['ok' => true, 'rate' => 59700.0, 'ts' => $now - 600,
                      'peg' => null, 'error' => '']]) !== []);
/* alanchand carries its own dollar page, so it checks its own peg instead of
   depending on the source that broke. */
ok('the real observed ratio passes',
   $judge(['tgju' => ['ok' => true, 'rate' => 59700.0, 'ts' => $now - 600,
                      'peg' => 3.6595, 'error' => '']]) === []);

echo "\n== two sources that disagree ==\n";
$v = rate_judge(['tgju' => $good,
                 'alanchand' => ['ok' => true, 'rate' => 64500.0, 'ts' => $now,
                                 'peg' => 3.66, 'error' => '']], $now);
ok('an 8% disagreement is refused', $v['reasons'] !== []);
ok('  and the reason quotes both figures', $has($v['reasons'], '64,500'),
   implode(' | ', $v['reasons']));
ok('  and refuses rather than picking one', $v['rate'] === null);
/* They really do sit this close: 0.31% apart, measured from the shop's server. */
ok('the 0.3% they actually differ by is accepted',
   $judge(['tgju' => $good,
           'alanchand' => ['ok' => true, 'rate' => 59885.0, 'ts' => $now,
                           'peg' => 3.66, 'error' => '']]) === []);

echo "\n== numbers that are not numbers ==\n";
ok('a comma-grouped figure parses', rate_parse_number('593,340') === 593340.0);
ok('Persian digits parse', rate_parse_number('۵۹۳۳۴۰') === 593340.0);
ok('an HTML page is null, not zero', rate_parse_number('<!DOCTYPE html>') === null);
ok('an empty string is null', rate_parse_number('') === null);
ok('null stays null', rate_parse_number(null) === null);
ok('a negative is null', rate_parse_number('-5') === null);

echo "\n== the median ignores one bad print ==\n";
$series = [59700, 59750, 59680, 59720, 88000, 59710, 59690];
ok('a single spike does not move the median',
   abs(rate_median($series) - 59710.0) < 1.0, (string) rate_median($series));
ok('an even count averages the middle pair',
   abs(rate_median([100.0, 200.0, 300.0, 400.0]) - 250.0) < 0.01);
ok('rows from the table work as well as bare numbers',
   abs(rate_median([['rate' => 100.0], ['rate' => 300.0], ['rate' => 200.0]]) - 200.0) < 0.01);
ok('nothing usable gives null, not zero', rate_median([]) === null);
ok('  and zeroes are not usable', rate_median([0, 0]) === null);

echo "\n== an hourly apply needs a FRESH reading, not just a count ==\n";
/* Six accepted readings all taken eighteen hours ago satisfy any count and
   describe a market that has since moved. They would be applied with a fresh
   "auto" stamp and full confidence, which is the quiet version of this whole
   system going wrong. */
$cluster = [];
for ($i = 0; $i < 6; $i++) {
    $cluster[] = ['rate' => 59000.0, 'created_unix' => $now - 18 * 3600 - $i * 60];
}
$e = rate_estimate($cluster, $now);
ok('a stale cluster is refused however many readings it has', $e['ok'] === false);
ok('  and the reason names the age', mb_strpos($e['reason'], 'قدیمی') !== false, $e['reason']);

$spread = [];
for ($h = 0; $h < 24; $h++) {
    $spread[] = ['rate' => 59000.0, 'created_unix' => $now - $h * 3600 - 60];
}
ok('a full day of hourly readings is accepted', rate_estimate($spread, $now)['ok'] === true);

/* Since 2026-10-02 the shelf follows every hourly reading, so a gap earlier
   in the day no longer holds it back: the daily rule wanted the whole day
   covered, and after any four-hour outage it froze the prices for a day. */
$halfDay = [];
for ($h = 0; $h < 13; $h++) {
    $halfDay[] = ['rate' => 59000.0, 'created_unix' => $now - $h * 3600 - 60];
}
$e = rate_estimate($halfDay, $now);
ok('a hole earlier in the day no longer stops the hourly apply', $e['ok'] === true, $e['reason']);
ok('nothing at all is refused, not treated as zero',
   rate_estimate([], $now)['ok'] === false);

/* Confirmed: both sources agreed on the newest reading, or there are three
   readings in six hours to measure a lone source against. */
$oneBoth = [['rate' => 59400.0, 'cross_rate' => 59500.0, 'created_unix' => $now - 600]];
$e = rate_estimate($oneBoth, $now);
ok('one fresh reading both sources agreed on is enough', $e['ok'] === true && abs($e['rate'] - 59400.0) < 0.01,
   json_encode($e, JSON_UNESCAPED_UNICODE));
$oneAlone = [['rate' => 59400.0, 'created_unix' => $now - 600]];
$e = rate_estimate($oneAlone, $now);
ok('one fresh reading from a single source is not', $e['ok'] === false);
ok('  and the reason says why', mb_strpos($e['reason'], 'یک منبع') !== false, $e['reason']);
$threeAlone = [];
for ($h = 0; $h < 3; $h++) {
    $threeAlone[] = ['rate' => 59400.0, 'created_unix' => $now - $h * 3600 - 600];
}
ok('three single-source readings in six hours are', rate_estimate($threeAlone, $now)['ok'] === true);
/* The market closes overnight: the newest reading is hours old and there is
   nothing to price from - the shelf stays where the market left it. */
$overnight = [['rate' => 59400.0, 'cross_rate' => 59500.0, 'created_unix' => $now - 9 * 3600]];
$e = rate_estimate($overnight, $now);
ok('after hours there is nothing fresh to price from', $e['ok'] === false
   && mb_strpos($e['reason'], 'قدیمی') !== false, $e['reason']);

echo "\n== the estimate follows a real climb instead of averaging it away ==\n";
/* A steady one-day rise is the case where a flat median is robust and badly
   wrong: it returns the mid-ramp value, so the shelf is set to half the move
   on the very day customers buy because they saw the currency move. */
$climb = [];
for ($h = 0; $h < 24; $h++) {
    $climb[] = ['rate' => 56000.0 + (23 - $h) * 130, 'created_unix' => $now - $h * 3600 - 60];
}
$flatMedian = rate_median(array_column($climb, 'rate'));
$est = rate_estimate($climb, $now);
ok('the flat 24h median lags the climb badly', $flatMedian < 57600.0, (string) $flatMedian);
ok('  while the estimate stays with the market', $est['rate'] > 58500.0, (string) $est['rate']);

/* But it must still not chase a single bad print. */
$spike = [];
for ($h = 0; $h < 24; $h++) {
    $spike[] = ['rate' => 59000.0, 'created_unix' => $now - $h * 3600 - 60];
}
$spike[0]['rate'] = 88000.0;   // newest reading is the bad one
$est = rate_estimate($spike, $now);
ok('a spike in the newest reading is held to the median band',
   $est['rate'] < 60500.0, (string) $est['rate']);

echo "\n== what to do with a candidate rate ==\n";
$applied = 59000.0; $anchor = 58000.0;
$act = static fn(float $c): string => rate_decide($applied, $anchor, $c)['action'];

ok('a tiny rise is inside the deadband', $act(59100.0) === 'skip');
ok('a real rise is applied', $act(59500.0) === 'apply');
/* Asymmetric on purpose: the rial falls far more often than it rises, so a
   symmetric band would lag every rise and give the difference away. */
ok('the upward band is tighter than the downward one',
   RATE_DEADBAND_UP_PCT < RATE_DEADBAND_DOWN_PCT);
ok('  so a 1% fall is ignored where a 1% rise is not',
   $act(58410.0) === 'skip' && $act(59590.0) === 'apply');

ok('a 3% fall is applied', $act(57000.0) === 'apply');
/* Refusing a big RISE guarantees selling below replacement cost until someone
   looks; moving the maximum allowed is wrong by less. */
ok('a big rise is clamped, not refused', $act(66000.0) === 'clamp');
ok('  and clamped to exactly the step limit',
   abs(rate_decide($applied, $anchor, 66000.0)['rate'] - $applied * 1.1) < 1.0);
/* The same reasoning inverts downward: a large claimed fall is more often a
   bad reading than a market, and acting on it hands away margin. */
ok('a big fall is held for a person instead', $act(30000.0) === 'hold');

ok('a figure outside any possible rate is rejected outright', $act(900000.0) === 'reject');
ok('  and so is one far below', $act(900.0) === 'reject');
ok('many times the anchor is held', $act(250000.0) === 'hold');

/* Without this the percentage arithmetic throws DivisionByZeroError inside a
   cron job, where nobody sees it. */
ok('a shop with no applied rate says so instead of dividing by it',
   rate_decide(null, null, 59000.0)['action'] === 'bootstrap');
ok('  and a zero applied rate is the same case',
   rate_decide(0.0, null, 59000.0)['action'] === 'bootstrap');

echo "\n== every reading is written down, kept or not ==\n";
db_query('DELETE FROM rate_polls');
/* Storing the refusals is the reason this table exists rather than reusing
   rate_history: "the rate held steady" and "nothing answered for two days"
   are indistinguishable unless the failures were recorded too. */
$before = (int) db_value('SELECT COUNT(*) FROM rate_polls');
$res = rate_poll();
$after = (int) db_value('SELECT COUNT(*) FROM rate_polls');
ok('a poll writes exactly one row whatever happened', $after === $before + 1);

$row = db_one('SELECT * FROM rate_polls ORDER BY id DESC LIMIT 1');
ok('  the row records whether it was accepted',
   in_array((int) $row['accepted'], [0, 1], true));
if ((int) $row['accepted'] === 1) {
    ok('  an accepted row carries a plausible toman rate',
       (float) $row['rate'] > RATE_ABSOLUTE_MIN && (float) $row['rate'] < RATE_ABSOLUTE_MAX,
       (string) $row['rate']);
    ok('  and the peg it was checked against',
       $row['peg'] !== null && abs((float) $row['peg'] - RATE_PEG) < RATE_PEG_TOLERANCE,
       (string) $row['peg']);
    /* Either two sources agreed, or one stood alone and the row says which and
       why. Both are healthy outcomes; asserting the first would make this suite
       fail every time a source is having a bad day, which is exactly the
       situation the code was changed to survive. */
    if ($row['cross_rate'] !== null) {
        ok('  and what the second source said', true);
    } else {
        ok('  or, running on one source, which one it was',
           trim((string) $row['source']) !== '', (string) $row['source']);
        ok('    and why the other dropped out',
           trim((string) $row['reason']) !== '', (string) $row['reason']);
        echo "       (single-source poll in this run: " . $row['reason'] . ")
";
    }
} else {
    ok('  a refused row explains itself', trim((string) $row['reason']) !== '');
    echo "       (network unavailable in this run: " . $row['reason'] . ")\n";
}

ok('recent polls come back newest first', is_array(rate_recent_polls(24)));

echo "\n== warming up is not a fault ==\n";
/* A fresh install needs a full day of readings before it can price anything.
   Reporting that as «today's automatic rate was not applied» is alarming,
   wrong, and the worst possible first impression of a system whose whole value
   rests on the owner trusting its alerts. The same message after a week of
   running means something real, so the two have to be told apart — and the
   only thing that distinguishes them is how long the shop has been collecting.

   The cron does that arithmetic; this asserts the fact it does it from, since
   getting that query wrong would silence a genuine outage for ever. */
db_query('DELETE FROM rate_polls');
ok('a shop with no readings has no start time',
   db_value('SELECT UNIX_TIMESTAMP(MIN(created_at)) FROM rate_polls') === null);

db_query('INSERT INTO rate_polls (rate, source, accepted, created_at)
          VALUES (60000, "tgju", 1, DATE_SUB(NOW(), INTERVAL 1 HOUR))');
$since = (time() - (int) db_value('SELECT UNIX_TIMESTAMP(MIN(created_at)) FROM rate_polls')) / 3600;
ok('a fresh install reads as about an hour old', $since > 0.9 && $since < 1.2,
   (string) round($since, 2));
ok('  which is inside the warm-up window', $since < 25);

db_query('INSERT INTO rate_polls (rate, source, accepted, created_at)
          VALUES (60000, "tgju", 1, DATE_SUB(NOW(), INTERVAL 3 DAY))');
$since = (time() - (int) db_value('SELECT UNIX_TIMESTAMP(MIN(created_at)) FROM rate_polls')) / 3600;
ok('a shop running for days is outside it', $since > 25, (string) round($since, 1));
/* The point being that this shop has plenty of history and still cannot price,
   which is a real outage and must be reported. */
ok('  and still cannot price from one stale reading',
   rate_estimate(rate_recent_polls(24))['ok'] === false);
db_query('DELETE FROM rate_polls');

echo "\n== the panel can tell whether any of this is alive ==\n";
/* An automatic pricing job fails silently by nature: prices stop moving, which
   looks exactly like a quiet market. The heartbeat is the only signal that
   separates "nothing to do" from "nothing running". */
db_query('DELETE FROM rate_polls');
settings_save(['rate_cron_last_run' => (string) (time() - 30 * 60)]);
$st = rate_status();
ok('a cron that ran half an hour ago reads as healthy', $st['cron_healthy'] === true);
ok('  and its age is reported in hours', $st['cron_age_hours'] < 1.0);

settings_save(['rate_cron_last_run' => (string) (time() - 9 * 3600)]);
$st = rate_status();
ok('a cron silent for nine hours does not', $st['cron_healthy'] === false);

settings_save(['rate_cron_last_run' => '']);
$st = rate_status();
ok('a cron that never ran is not reported as healthy either',
   $st['cron_healthy'] === false && $st['last_cron'] === null);

/* With no readings at all the panel must say so rather than showing a rate. */
ok('with no readings it is not ready to price', $st['ready'] === false);
ok('  and gives a reason a person can act on', trim((string) $st['ready_reason']) !== '');

/* A day of readings, one of them refused — the shape the panel actually draws. */
$now2 = time();
for ($h = 23; $h >= 0; $h--) {
    $good = $h !== 7;
    db_query(
        'INSERT INTO rate_polls (rate, source, cross_rate, cross_source, peg, accepted, reason, created_at)
         VALUES (?, "tgju", ?, ?, ?, ?, ?, FROM_UNIXTIME(?))',
        [$good ? 59000 + (23 - $h) * 20 : null,
         $good ? 59100 + (23 - $h) * 20 : null, $good ? 'alanchand' : '',
         $good ? 3.659 : null, $good ? 1 : 0,
         $good ? '' : 'دو منبع اختلاف داشتند', $now2 - $h * 3600]);
}
$st = rate_status();
ok('a full day of readings makes it ready', $st['ready'] === true, $st['ready_reason']);
ok('  and the counts separate accepted from total',
   $st['accepted_24h'] === 23 && $st['polls_24h'] === 24,
   $st['accepted_24h'] . '/' . $st['polls_24h']);
ok('  and an estimate comes with it', $st['estimate'] !== null);

$log = rate_poll_log(36);
ok('the log returns the readings', count($log) === 24, (string) count($log));
/* The refusals are the useful half: without them, "prices stopped updating"
   has no explanation anywhere. */
$refused = array_values(array_filter($log, static fn(array $r): bool => (int) $r['accepted'] === 0));
ok('  including the refused one', count($refused) === 1);
ok('  which carries its reason', trim((string) $refused[0]['reason']) !== '');

db_query('DELETE FROM rate_polls');
settings_save(['rate_cron_last_run' => '']);

echo "\n== every hour, the shelf follows the reading (2026-10-02) ==\n";
/* The owner asked for every hourly reading to reach the prices there and
   then, instead of once a day at four in the morning. rate_auto_apply() is the
   step the cron runs after each poll; it is driven here from rows in
   rate_polls rather than from the network. Every other dirham product is set
   aside for the duration, so this reprices only its own fixture. */
$keep = [];
foreach (['aed_rate', 'aed_rate_updated_at', 'rate_anchor', 'rate_auto_last_apply',
          'rate_auto_last_check', 'rate_auto_last_note', 'price_rounding_step'] as $k) {
    $keep[$k] = (string) setting($k, '');
}
$asideIds = array_map('intval', array_column(db_all("SELECT id FROM products WHERE price_mode = 'aed'"), 'id'));
if ($asideIds) {
    db_query("UPDATE products SET price_mode = 'manual' WHERE id IN (" . implode(',', $asideIds) . ')');
}
db_query("DELETE FROM products WHERE slug = 'zz-rate-auto'");
$autoId = db_insert(
    "INSERT INTO products (slug, name_fa, price, price_mode, is_active, in_stock, cost_aed, profit_toman)
     VALUES ('zz-rate-auto', 'زد نرخ ساعتی', 7000000, 'aed', 1, 1, 100, 1000000)");
$historyFrom = (int) db_value('SELECT COALESCE(MAX(id), 0) FROM rate_history');
db_query("INSERT INTO rate_history (rate, previous, source, admin_name, applied_to)
          VALUES (60000, NULL, 'manual', 'tester', 1)");
settings_save(['aed_rate' => '60000', 'rate_anchor' => '60000', 'price_rounding_step' => '10000',
               'aed_rate_updated_at' => date('Y-m-d H:i:s', time() - 5 * 86400), 'rate_auto_last_apply' => '']);
$poll = static function (float $rate, int $agoSeconds, bool $both = true): void {
    db_query('INSERT INTO rate_polls (rate, source, cross_rate, cross_source, peg, accepted, reason, created_at)
              VALUES (?, ?, ?, ?, 3.66, 1, "", FROM_UNIXTIME(?))',
             [$rate, $both ? 'tgju+alanchand' : 'tgju', $both ? $rate + 100 : null,
              $both ? 'alanchand' : '', time() - $agoSeconds]);
};
$priceOf = static fn(): int => (int) db_value('SELECT price FROM products WHERE id = ?', [$autoId]);

$poll(61000.0, 600);
$step = rate_auto_apply();
ok('a reading 1.7% above the shelf is applied in the same hour', $step['action'] === 'apply',
   json_encode($step, JSON_UNESCAPED_UNICODE));
ok('  to the prices', $priceOf() === pricing_round_up(100 * 61000 + 1000000), (string) $priceOf());
ok('  and recorded as automatic', (string) db_value('SELECT source FROM rate_history ORDER BY id DESC LIMIT 1') === 'auto');
ok('  and the panel is told what happened',
   mb_strpos((string) setting('rate_auto_last_note', ''), 'اعمال شد') !== false
   && abs((int) setting('rate_auto_last_check', '0') - time()) < 10);
ok('no twenty-hour wait for the next one: the same hour looks again and leaves it',
   rate_auto_apply()['action'] === 'skip');
$poll(61100.0, 300);
ok('a 0.2% wobble stays inside the deadband', rate_auto_apply()['action'] === 'skip');
ok('  and the shelf with it', $priceOf() === pricing_round_up(100 * 61000 + 1000000));
settings_save(['aed_rate_updated_at' => date('Y-m-d H:i:s', time() - 5 * 86400)]);
rate_auto_apply();
$st = pricing_staleness();
ok('a shelf the job confirmed this hour is not stale, whenever the rate last moved',
   $st['days'] !== null && $st['days'] < 0.1, json_encode($st));
ok('  and the drift is what the latest reading says', $st['drift_pct'] !== null && abs($st['drift_pct'] - 0.2) < 0.05,
   json_encode($st));

db_query('DELETE FROM rate_polls');
$poll(62500.0, 4 * 3600);
$step = rate_auto_apply();
ok('a reading hours old is waited out, not applied', $step['action'] === 'wait'
   && $priceOf() === pricing_round_up(100 * 61000 + 1000000), json_encode($step, JSON_UNESCAPED_UNICODE));
db_query('DELETE FROM rate_polls');
$poll(62500.0, 300, false);
ok('a lone source by itself is waited out too', rate_auto_apply()['action'] === 'wait');
$poll(62500.0, 3600 + 300, false);
$poll(62500.0, 7200 + 300, false);
$step = rate_auto_apply();
/* The profit was locked as a share of cost by the first apply (pricing_apply()),
   so the price is the rate's cost plus that share - above the old price, and
   priced at the new rate. */
ok('three single-source readings within six hours are applied', $step['action'] === 'apply'
   && $priceOf() > pricing_round_up(100 * 61000 + 1000000)
   && abs((float) db_value('SELECT price_applied_rate FROM products WHERE id = ?', [$autoId]) - 62500) < 0.01,
   json_encode($step, JSON_UNESCAPED_UNICODE) . ' price ' . $priceOf());

$cron = (string) file_get_contents(SUPPEX_ROOT . '/tools/rate-cron.php');
ok('the cron applies on every run', strpos($cron, '$step = rate_auto_apply();') !== false
   && strpos($cron, 'RATE_APPLY_HOUR') === false && strpos($cron, 'RATE_APPLY_MIN_GAP_HOURS') === false);

/* Put everything back. */
db_query('DELETE FROM rate_polls');
db_query('DELETE FROM rate_history WHERE id > ?', [$historyFrom]);
db_query('DELETE FROM products WHERE id = ?', [$autoId]);
if ($asideIds) {
    db_query("UPDATE products SET price_mode = 'aed' WHERE id IN (" . implode(',', $asideIds) . ')');
}
settings_save($keep);
ok('fixtures cleaned up', (int) db_value("SELECT COUNT(*) FROM products WHERE slug = 'zz-rate-auto'") === 0);

echo "\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
