<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/* One API route, driven from the command line, the way tests/editsave.php
   drives a panel page:

     php apipost.php <route> <base64 of the json> [ip]

   Base64, like tests/productpost.php: on Windows escapeshellarg() turns
   every double quote into a space, and JSON is made of them.

   Prints what the route sends. The JSON becomes $_POST, which is what
   api_json_body() reads when there is no raw request body - always the case
   here. A file upload cannot be made this way (is_uploaded_file() is false
   for anything that did not arrive over HTTP), so what this reaches is every
   answer the receipt route gives BEFORE it looks at the file. */

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR']    = $argv[3] ?? '127.0.0.9';
$_SERVER['HTTP_HOST']      = 'suppex.ir';
$_GET  = ['r' => (string) ($argv[1] ?? '')];
$_POST = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];

require __DIR__ . '/../api/index.php';
