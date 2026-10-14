<?php
/* ===========================================================================
   SUPPEX — host probe
   ---------------------------------------------------------------------------
   Answers the questions that cannot be answered from anywhere but the server
   itself, and that every later decision about automatic pricing depends on:

     1. Which PHP binary does cron actually run, and what version is it?
     2. Can this host reach the exchange-rate sources over HTTPS at all?
     3. Which fetch mechanism works here — curl, or plain file_get_contents?
     4. What does each source say the dirham is worth, right now, from HERE?

   Everything I could test about these sources was tested from OUTSIDE Iran.
   This server is inside it. That is the opposite vantage point, so a source
   that answered me may be unreachable here, and one that refused me may work
   perfectly. Guessing either way would be building on nothing.

   HOW TO RUN
   ----------
   cPanel → Cron Jobs → add a job, set it to «Once Per Five Minutes», command:

       /opt/cpanel/ea-php80/root/usr/bin/php /home/USER/probe-host.php

   Replace USER with your cPanel username. If that path is wrong the job will
   say "No such file or directory" — try /usr/local/bin/php instead, and this
   script will report the real path it ended up running under.

   Set the cron job's email field to your address; the output arrives by mail.
   DELETE THE CRON JOB once you have the output. This script only reads.
   =========================================================================== */

declare(strict_types=1);

/* Reachable over the web it would tell a stranger the shop's PHP version and
   binary path for nothing. It is a CLI tool. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function line(string $s = ''): void { echo $s, "\n"; }
function head(string $s): void { line(); line('=== ' . $s . ' ==='); }

line('SUPPEX host probe — ' . date('Y-m-d H:i:s'));

/* --- 1. what is running this ---------------------------------------------- */
head('PHP');
line('  binary        : ' . (defined('PHP_BINARY') ? PHP_BINARY : '(unknown)'));
line('  version       : ' . PHP_VERSION);
line('  sapi          : ' . PHP_SAPI);
line('  max_execution : ' . ini_get('max_execution_time') . ' (0 = unlimited, which CLI should be)');
line('  memory_limit  : ' . ini_get('memory_limit'));
line('  timezone      : ' . date_default_timezone_get());

/* --- 2. what tools are available ------------------------------------------ */
head('fetch mechanisms');
$hasCurl = function_exists('curl_init');
$hasFopen = filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
line('  curl extension    : ' . ($hasCurl ? 'yes' : 'NO'));
line('  allow_url_fopen   : ' . ($hasFopen ? 'yes' : 'NO'));
line('  openssl           : ' . (extension_loaded('openssl') ? 'yes' : 'NO'));
line('  json              : ' . (extension_loaded('json') ? 'yes' : 'NO'));
foreach (['exec', 'shell_exec', 'proc_open'] as $f) {
    line('  ' . str_pad($f, 18) . ': ' . (function_exists($f) ? 'available' : 'disabled'));
}

/* --- 3. can it reach the outside world ------------------------------------ */

/**
 * Fetch a URL both ways and report which one worked.
 *
 * Both are tried on purpose: a host with curl compiled without SSL, or with
 * allow_url_fopen off, fails in ways that look identical from the outside
 * ("no data"), and knowing WHICH mechanism works decides how the real job is
 * written.
 *
 * @return array{ok:bool,via:string,ms:int,code:int,len:int,body:string,error:string}
 */
function probe(string $url, int $timeout = 20): array
{
    $out = ['ok' => false, 'via' => '', 'ms' => 0, 'code' => 0, 'len' => 0,
            'body' => '', 'error' => ''];
    $ua = 'Mozilla/5.0 (compatible; SuppexBot/1.0)';

    if (function_exists('curl_init')) {
        $t = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => $ua,
        ]);
        $body = curl_exec($ch);
        $out['ms']   = (int) round((microtime(true) - $t) * 1000);
        $out['code'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body !== false && $out['code'] === 200) {
            return ['ok' => true, 'via' => 'curl', 'ms' => $out['ms'], 'code' => $out['code'],
                    'len' => strlen($body), 'body' => $body, 'error' => ''];
        }
        $out['error'] = $err !== '' ? $err : ('HTTP ' . $out['code']);
        $out['via'] = 'curl';
    }

    if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        $t = microtime(true);
        $ctx = stream_context_create(['http' => [
            'timeout' => $timeout, 'user_agent' => $ua, 'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $ms = (int) round((microtime(true) - $t) * 1000);

        /* ignore_errors keeps the BODY of a 4xx/5xx instead of returning false,
           which is what makes the status line worth reading — the first run of
           this probe reported brsapi as OK when what it had fetched was 796
           bytes of 404 page. A body is not a success. */
        $code = 0;
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) { $code = (int) $m[1]; }
        }
        if ($body !== false && $code === 200) {
            return ['ok' => true, 'via' => 'file_get_contents', 'ms' => $ms, 'code' => 200,
                    'len' => strlen($body), 'body' => $body, 'error' => ''];
        }
        if ($body !== false) {
            $out['error'] = 'HTTP ' . $code . ' (body returned but not a success)';
            $out['via'] = 'file_get_contents';
            $out['ms'] = $ms;
            return $out;
        }
        $out['error'] .= ' | fopen also failed';
        $out['ms'] = $out['ms'] ?: $ms;
    }

    return $out;
}

head('reaching the rate sources');
line('  (a source that fails here simply cannot be used, however good it looks)');
line();

$sources = [
    'tgju (live host)'   => 'https://call.tgju.org/ajax.json',
    'tgju (call1 mirror)' => 'https://call1.tgju.org/ajax.json',
    'alanchand'          => 'https://alanchand.com/currencies-price/aed',
    'brsapi (same datacentre as this host)' => 'https://brsapi.ir/Api/Market/Gold_Currency.php?key=free',
];

$bodies = [];
foreach ($sources as $label => $url) {
    $r = probe($url);
    $bodies[$label] = $r['ok'] ? $r['body'] : '';
    printf("  %-40s %s\n", $label, $r['ok']
        ? sprintf('OK   via %-17s %5d ms  %7d bytes', $r['via'], $r['ms'], $r['len'])
        : sprintf('FAIL %s', substr($r['error'], 0, 60)));
}

/* --- 4. what do they actually say ----------------------------------------- */
head('the dirham, as seen from this server');

$tgju = $bodies['tgju (live host)'] ?? '';
if ($tgju !== '') {
    $j = json_decode($tgju, true);
    $cur = $j['current'] ?? [];
    foreach (['price_aed', 'price_dollar_rl', 'ice_transfer_aed_sell'] as $k) {
        if (!isset($cur[$k])) { line("  $k : missing"); continue; }
        printf("  %-22s %-14s ts=%s\n", $k, $cur[$k]['p'] ?? '?', $cur[$k]['ts'] ?? '?');
    }
    $aed = (float) str_replace(',', '', (string) ($cur['price_aed']['p'] ?? '0'));
    $usd = (float) str_replace(',', '', (string) ($cur['price_dollar_rl']['p'] ?? '0'));
    if ($aed > 0) {
        line();
        line('  dirham in TOMAN (rial / 10) : ' . number_format($aed / 10));
        if ($usd > 0) {
            /* The dirham is pegged to the dollar at 3.6725. If this ratio is
               not close to that, the two numbers are not in the same unit or
               one of them is garbage - which is the cheapest correctness check
               available and costs nothing to run. */
            printf("  USD/AED ratio               : %.4f   (peg is 3.6725 — %s)\n",
                $usd / $aed,
                abs($usd / $aed - 3.6725) < 0.15 ? 'OK' : 'OFF, do not trust this feed');
        }
        $ts = strtotime((string) ($cur['price_aed']['ts'] ?? ''));
        if ($ts) {
            $age = (time() - $ts) / 3600;
            printf("  age of the quote            : %.1f hours%s\n", $age,
                $age > 6 ? '   <-- STALE' : '');
        }
    }
} else {
    line('  tgju unreachable from here — the whole plan needs a different source.');
}

$alan = $bodies['alanchand'] ?? '';
if ($alan !== '' && preg_match('~"price"\s*:\s*"?([0-9.]+)"?~', $alan, $m)) {
    line('  alanchand ld+json price     : ' . number_format((float) $m[1])
         . '  (rial → ' . number_format(((float) $m[1]) / 10) . ' toman)');
}

$brs = $bodies['brsapi (same datacentre as this host)'] ?? '';
if ($brs !== '') {
    line();
    line('  brsapi answered. First 400 characters, so its shape and unit can be read:');
    line('  ' . substr(preg_replace('~\s+~', ' ', $brs), 0, 400));
}

head('done');
line('Send this whole output back. Then delete the cron job and this file.');
