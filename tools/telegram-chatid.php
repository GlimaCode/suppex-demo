<?php
/* ===========================================================================
   SUPPEX — which chat is the bot actually talking to?
   ---------------------------------------------------------------------------
   Asks Telegram, from the server, which chats have recently spoken to the bot,
   and prints their ids.

   It runs here rather than in a browser because of where each machine sits:
   the shop's host reaches api.telegram.org perfectly well — orders used to
   arrive through it — while a browser inside Iran generally does not. The
   usual advice of "open api.telegram.org/bot<TOKEN>/getUpdates in your
   browser" is exactly the thing that will not work for this shop.

   It also checks the token itself and, when a chat_id is already configured,
   sends one test message — so a run of this either produces the id you need or
   tells you precisely why the messages are not arriving.

   HOW TO USE
   ----------
   1. Upload next to rate-cron.php, in the home directory.
   2. In Telegram, SEND ANY MESSAGE IN THE GROUP the bot belongs to. Telegram
      only reports chats with recent activity, so a silent group is invisible
      here. "hello" is enough.
   3. cPanel -> Cron Jobs -> add a job, «Once Per Five Minutes», command:

          /opt/cpanel/ea-php80/root/usr/bin/php /home3/USER/telegram-chatid.php

      Put your address in the cron job's email field.
   4. Read the output, put the id in suppex-config.php, then DELETE this file
      and the cron job.

   This script only reads, apart from the single test message it sends to a
   chat you have already configured.
   =========================================================================== */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/* Same search as rate-cron.php: this file sits outside public_html, so it
   cannot assume where it is relative to the shop. */
const SUPPEX_DIR = '';

$candidates = SUPPEX_DIR !== '' ? [rtrim(SUPPEX_DIR, '/\\') . '/lib/bootstrap.php'] : [
    __DIR__ . '/public_html/lib/bootstrap.php',
    dirname(__DIR__) . '/lib/bootstrap.php',
    __DIR__ . '/lib/bootstrap.php',
    dirname(__DIR__) . '/public_html/lib/bootstrap.php',
];

$bootstrap = '';
foreach ($candidates as $c) {
    if (is_file($c)) { $bootstrap = $c; break; }
}
if ($bootstrap === '') {
    fwrite(STDERR, "Could not find the shop. Looked in:\n  "
        . implode("\n  ", $candidates) . "\n");
    exit(1);
}
require_once $bootstrap;

function line(string $s = ''): void { echo $s, "\n"; }
function head(string $s): void { line(); line('=== ' . $s . ' ==='); }

$cfg   = suppex_config()['telegram_bot'] ?? [];
$token = trim((string) ($cfg['token'] ?? ''));
$chat  = trim((string) ($cfg['chat_id'] ?? ''));

line('SUPPEX telegram check — ' . date('Y-m-d H:i:s'));
line('  enabled : ' . (!empty($cfg['enabled']) ? 'yes' : 'NO — nothing will be sent'));
/* Never the whole token. This output goes out by email and ends up pasted into
   chats; the last thing it should do is publish the credential it is checking. */
line('  token   : ' . ($token === ''
    ? 'MISSING'
    : substr($token, 0, 10) . '…' . substr($token, -4) . ' (' . strlen($token) . ' chars)'));
line('  chat_id : ' . ($chat === '' ? 'MISSING' : $chat));

if ($token === '') {
    line();
    line('There is no token in suppex-config.php. Nothing else can be checked.');
    exit(1);
}

/**
 * One call to the Bot API.
 *
 * @return array{ok:bool,body:array,error:string}
 */
function tg(string $token, string $method, array $params = []): array
{
    $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'body' => [], 'error' => 'curl is not available'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => (bool) $params,
        CURLOPT_POSTFIELDS     => $params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'body' => [], 'error' => $err ?: 'request failed'];
    }
    $body = json_decode((string) $raw, true);
    if (!is_array($body)) {
        return ['ok' => false, 'body' => [], 'error' => 'unreadable answer'];
    }

    return ['ok' => !empty($body['ok']), 'body' => $body,
            'error' => (string) ($body['description'] ?? '')];
}

/* --- 1. is the token live? ------------------------------------------------ */
head('the token');
$me = tg($token, 'getMe');
if (!$me['ok']) {
    line('  REFUSED: ' . $me['error']);
    line();
    line('  «Unauthorized» means this token was revoked or mistyped. Get a fresh');
    line('  one from @BotFather (/mybots -> your bot -> API Token) and put it in');
    line('  suppex-config.php.');
    exit(1);
}
$bot = $me['body']['result'] ?? [];
line('  OK — @' . ($bot['username'] ?? '?') . ' (' . ($bot['first_name'] ?? '') . ')');

/* --- 2. which chats has it seen? ------------------------------------------ */
head('chats this bot has heard from recently');
$up = tg($token, 'getUpdates', ['limit' => 100]);
if (!$up['ok']) {
    line('  could not read updates: ' . $up['error']);
} else {
    $seen = [];
    foreach (($up['body']['result'] ?? []) as $u) {
        foreach (['message', 'edited_message', 'channel_post', 'my_chat_member'] as $k) {
            $c = $u[$k]['chat'] ?? null;
            if (is_array($c) && isset($c['id'])) {
                $seen[(string) $c['id']] = [
                    'type'  => (string) ($c['type'] ?? '?'),
                    'title' => (string) ($c['title'] ?? trim(
                        ($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))),
                    'user'  => (string) ($c['username'] ?? ''),
                ];
            }
        }
    }

    if (!$seen) {
        line('  Nothing. Telegram only reports chats with RECENT activity, so a');
        line('  quiet group does not appear here.');
        line();
        line('  Send any message in the group, then run this again within a few');
        line('  minutes. If the shop is also polling getUpdates elsewhere, that');
        line('  consumes the queue — but nothing in SUPPEX does.');
    } else {
        foreach ($seen as $id => $c) {
            printf("  %-18s %-10s %s%s\n", $id, $c['type'],
                $c['title'] !== '' ? $c['title'] : '(no title)',
                $c['user'] !== '' ? '  @' . $c['user'] : '');
        }
        line();
        line('  A group id starts with a minus sign. Put it in suppex-config.php');
        line('  IN QUOTES — it is longer than a PHP integer on some builds:');
        line();
        line("      'chat_id' => '-1001234567890',");
    }
}

/* --- 3. does the configured chat actually work? --------------------------- */
head('the chat_id currently in the config');
if ($chat === '') {
    line('  none set — pick one from the list above');
} else {
    $send = tg($token, 'sendMessage', [
        'chat_id' => $chat,
        'text'    => "پیام آزمایشی ساپکس ✅\nاگر این را می‌بینید، هشدارها به همین گفتگو می‌رسند.",
    ]);
    if ($send['ok']) {
        line('  OK — a test message was delivered to ' . $chat);
        line('  Alerts and order notifications will arrive here.');
    } else {
        line('  REFUSED: ' . $send['error']);
        line();
        $why = mb_strtolower($send['error']);
        if (mb_strpos($why, "can't initiate") !== false || mb_strpos($why, 'blocked') !== false) {
            line('  This is a USER id, and that person has never opened the bot.');
            line('  A bot cannot send the first message. Either they open @'
                 . ($bot['username'] ?? 'yourbot') . ' and press START, or — better —');
            line('  use the GROUP id from the list above.');
        } elseif (mb_strpos($why, 'chat not found') !== false) {
            line('  No such chat. If this was a group that Telegram upgraded to a');
            line('  supergroup, its id CHANGED — take the new one from the list above.');
        } elseif (mb_strpos($why, 'kicked') !== false || mb_strpos($why, 'not a member') !== false) {
            line('  The bot is no longer in that group. Add @'
                 . ($bot['username'] ?? 'yourbot') . ' back to it.');
        }
    }
}

head('done');
line('Send this whole output back. Then delete this file and its cron job.');
