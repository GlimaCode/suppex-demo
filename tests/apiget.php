<?php
/* Read-only API request in an isolated CLI process; never upload tests. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.18';
$_SERVER['HTTP_HOST'] = 'suppex.ir';
$_GET = json_decode(base64_decode($argv[2] ?? '', true) ?: '{}', true) ?: [];
$_GET['r'] = (string) ($argv[1] ?? '');
require __DIR__ . '/../api/index.php';
