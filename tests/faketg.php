<?php
/* ===========================================================================
   A stand-in for api.telegram.org, for tests/test-receipts.php.

     php -S 127.0.0.1:<port> tests/faketg.php

   Records every call it gets as one JSON line in tests/tmp/faketg.log - the
   method, the chat and whether a photograph came with it - and says yes to
   all of them. The suite counts the lines: «each chat gets each receipt
   once» is a count of messages, and only something that receives them can
   count them. Nothing here talks to the real Telegram.
   =========================================================================== */
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
file_put_contents(__DIR__ . '/tmp/faketg.log', json_encode([
    'method' => basename($path),
    'chat'   => (string) ($_POST['chat_id'] ?? ''),
    'photo'  => isset($_FILES['photo']) && ($_FILES['photo']['error'] ?? 1) === UPLOAD_ERR_OK,
]) . "\n", FILE_APPEND | LOCK_EX);

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'result' => ['message_id' => 1]]);
