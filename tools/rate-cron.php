<?php
/* ===========================================================================
   SUPPEX — the hourly dirham job
   ---------------------------------------------------------------------------
   Runs every hour. Every run POLLS, and every run then APPLIES what the
   reading says (rate_auto_apply() in lib/rate.php).

   HOURLY, SINCE 2026-10-02
   ------------------------
   It used to read hourly and move the shelf once a day, at four in the
   morning, so a dirham that rose at ten was on the shelf the next day. The
   owner asked for every hourly reading to reach every price there and then,
   and the reasons for waiting were each answered somewhere else:

     - prices round up to the nearest 10,000 toman, so a 0.3% wobble would
       move a price a whole step: the deadband in rate_decide() leaves the
       shelf alone until the market is 0.5% above it or 2% below it, measured
       from the rate last APPLIED, so small moves still add up and get through;
     - payment is card-to-card, so the gap between seeing a price and paying
       is minutes or hours: the quote holds what the shopper was shown while
       they check out (lib/quotes.php), and an order holds its total for its
       payment window;
     - Iran's currency market quotes for roughly fifty hours a week: outside
       them the readings are refused as stale, there is nothing fresh to price
       from, and the shelf stays on the last market price - which it should.

   INSTALLING IT
   -------------
   cPanel -> Cron Jobs -> Once Per Hour (0 * * * *):

       /opt/cpanel/ea-php80/root/usr/bin/php /home/USER/rate-cron.php

   Keep it OUT of public_html. It is CLI-only anyway (the guard below), but a
   file that is not served cannot be probed for its error messages either.
   =========================================================================== */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/* Find the shop, rather than assuming where this file sits relative to it.
 *
 * This script is meant to live OUTSIDE public_html, which means it cannot know
 * its own position in advance: dropped in the home directory, dirname(__DIR__)
 * is /home3 and there is no lib/ there; left in the repo's tools/ directory it
 * is the project root and there is. Guessing one of those and being wrong
 * produces a require_once fatal in a cron job, which is the least visible place
 * in the whole system for an error to happen.
 *
 * So: try the places it can reasonably be, and if none of them hold a shop, say
 * so in a sentence somebody can act on instead of a stack trace.
 */
/* Set this if the search below does not find the shop — the folder holding
   lib/, admin/ and api/, usually /home3/USER/public_html. Left empty, the
   search runs. */
const SUPPEX_DIR = '';

$candidates = SUPPEX_DIR !== '' ? [rtrim(SUPPEX_DIR, '/\\') . '/lib/bootstrap.php'] : [
    __DIR__ . '/public_html/lib/bootstrap.php',   // script in the home directory
    dirname(__DIR__) . '/lib/bootstrap.php',      // script in the repo's tools/
    __DIR__ . '/lib/bootstrap.php',               // script in the project root
    dirname(__DIR__) . '/public_html/lib/bootstrap.php',
];

$bootstrap = '';
foreach ($candidates as $c) {
    if (is_file($c)) { $bootstrap = $c; break; }
}

if ($bootstrap === '') {
    fwrite(STDERR,
        "SUPPEX rate cron: could not find the shop.\n\n"
        . "This script is at:\n  " . __FILE__ . "\n\n"
        . "It looked for lib/bootstrap.php in:\n  "
        . implode("\n  ", $candidates) . "\n\n"
        . "Set SUPPEX_DIR at the top of this file to the folder that contains\n"
        . "lib/, admin/ and api/ — usually /home3/USER/public_html.\n");
    exit(1);
}

require_once $bootstrap;
require_once SUPPEX_ROOT . '/lib/settings.php';
require_once SUPPEX_ROOT . '/lib/pricing.php';
require_once SUPPEX_ROOT . '/lib/rate.php';
require_once SUPPEX_ROOT . '/lib/notify.php';

function out(string $s): void
{
    echo date('Y-m-d H:i:s'), '  ', $s, "\n";
}

/**
 * Tell the owner, but only once per kind of problem per day.
 *
 * A fortnight of Nowruz produces a fortnight of identical "not enough
 * readings" messages, and by the end of it nobody reads any of them — which
 * is precisely when the one message that matters arrives. Repetition is
 * collapsed; a NEW kind of problem always gets through.
 */
function rate_alert(string $kind, string $text): void
{
    $key  = 'rate_alert_' . $kind;
    $last = (string) setting($key, '');
    if ($last !== '' && (time() - (int) $last) < 20 * 3600) {
        out('  (alert «' . $kind . '» suppressed — already sent today)');
        return;
    }
    settings_save([$key => (string) time()]);

    $cfg = suppex_config()['telegram_bot'] ?? [];
    if (empty($cfg['enabled']) || empty($cfg['token']) || empty($cfg['chat_id'])) {
        out('  (telegram not configured; alert not sent: ' . $text . ')');
        return;
    }
    notify_post(
        'https://api.telegram.org/bot' . $cfg['token'] . '/sendMessage',
        ['chat_id' => $cfg['chat_id'], 'text' => "SUPPEX — نرخ درهم\n\n" . $text]
    );
    out('  alert sent: ' . $kind);
}

/* --- one run at a time ----------------------------------------------------
   flock with the handle held for the whole script, not a lock FILE that is
   created and deleted. The kernel releases a flock when the process dies
   however it dies; a lock file left behind by a killed run blocks every run
   after it until somebody notices and deletes it by hand. */
$lockPath = sys_get_temp_dir() . '/suppex-rate.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false) {
    out('cannot open the lock file — refusing to run');
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    out('another run still holds the lock; skipping this hour');
    exit(0);
}

/* A heartbeat written before anything can hang, so "the job has not run since
   Tuesday" is answerable without guessing. */
settings_save(['rate_cron_last_run' => (string) time()]);

/* --- 1. always poll ------------------------------------------------------- */
$poll = rate_poll();
out($poll['accepted']
    ? sprintf('poll accepted: %s toman', number_format((float) $poll['rate']))
    : 'poll refused: ' . $poll['reason']);

if (!$poll['accepted']) {
    /* One refusal is ordinary — a timeout, a market minute with no print. A day
       of them is the shop quietly going blind. */
    $recent = (int) db_value(
        'SELECT COUNT(*) FROM rate_polls
          WHERE accepted = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)');
    if ($recent === 0) {
        rate_alert('blind', "شش ساعت است هیچ نرخ معتبری خوانده نشده.\n\n"
            . 'آخرین دلیل: ' . $poll['reason']);
    }
}

/* --- 2. apply, every run ------------------------------------------------- */
$step = rate_auto_apply();
if ($step['estimate'] !== null) {
    out(sprintf('estimate %s toman', number_format((float) $step['estimate'])));
}
out('decision: ' . $step['action'] . ' — ' . $step['reason']);

switch ($step['action']) {
    case 'wait':
        /* Nothing fresh to price from: the market is closed, or a source is
           down. Not an alert - most nights are this. A real outage is the
           «blind» alert above, after six hours without a single reading. */
    case 'skip':
        /* Not an alert either. The shelf not moving because the market did not
           move is the system working. */
        break;

    case 'bootstrap':
        rate_alert('bootstrap',
            "قیمت‌گذاری خودکار هنوز شروع نشده.\n\n" . $step['reason']
            . "\n\nیک‌بار در پنل، صفحه قیمت‌گذاری، نرخ را دستی اعمال کنید تا خودکار فعال شود.");
        break;

    case 'reject':
    case 'hold':
        rate_alert('held', "نرخ خودکار اعمال نشد.\n\n" . $step['reason']
            . "\n\nقیمت‌ها دست‌نخورده ماندند.");
        break;

    case 'clamp':
    case 'apply':
        out(sprintf('applied %s toman to %d units (%d skipped)',
            number_format((float) $step['rate']), $step['applied'], $step['skipped']));

        /* A clamp is news; an ordinary apply is not - least of all now that
           one can happen every hour. Telling the owner about every routine
           repricing is how he stops reading the ones that matter. */
        if ($step['action'] === 'clamp') {
            rate_alert('clamped', "نرخ با محدودیت اعمال شد.\n\n" . $step['reason']
                . sprintf("\n\n%d قلم قیمت‌گذاری شد.", $step['applied']));
        }
        break;
}

out('done');
