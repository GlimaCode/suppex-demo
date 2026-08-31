<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP.
   bootstrap falls back to the real suppex-config.php when SUPPEX_CONFIG
   is unset - which is exactly what a web request has - so one GET to an
   uploaded tests/ folder could empty the live database. The .htaccess
   beside this file is process; this is code. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* Test-only config. Points at a throwaway database so the suite can wipe
   tables between runs without touching anything real. */
return [
  'db' => ['host' => '127.0.0.1;port=3399', 'name' => 'suppex_test',
           'user' => 'root', 'pass' => '', 'charset' => 'utf8mb4'],
  'uploads_dir'  => __DIR__ . '/tmp/uploads',
  'uploads_url'  => '/uploads',
  'session_name' => 'suppex_test',
  'https_only'   => false,
  'login'        => ['max_attempts' => 8, 'window_minutes' => 15],
  'telegram_bot' => ['enabled' => false, 'token' => '', 'chat_id' => ''],
  'sms'          => ['enabled' => false, 'provider' => '', 'api_key' => '', 'sender' => '', 'to' => ''],
  'env'          => 'development',
];
