<?php
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$failed = is_file(__DIR__ . '/tmp/inquiry-tg-fail');
file_put_contents(__DIR__ . '/tmp/inquiry-tg.log', json_encode(['chat' => $_POST['chat_id'] ?? '', 'text' => $_POST['text'] ?? '', 'failed' => $failed]) . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
if ($failed) { http_response_code(500); }
echo json_encode(['ok' => !$failed, 'description' => $failed ? 'synthetic failure' : 'ok', 'result' => ['message_id' => 1]]);
