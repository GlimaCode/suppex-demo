<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* The suite's config with Telegram switched on - pointed at tests/faketg.php,
   never at the real api.telegram.org. The token is made up, and with no
   stand-in address the base is empty, so a call has nowhere to go at all.
   Used only by tests/notifyonce.php, run from tests/test-receipts.php. */
$cfg = require __DIR__ . '/test-config.php';
$cfg['telegram_bot'] = [
    'enabled' => true,
    'token'   => 'TESTTOKEN',
    'chat_id' => (string) getenv('SUPPEX_FAKETG_CHAT'),
];
$cfg['telegram_api'] = (string) getenv('SUPPEX_FAKETG');
return $cfg;
