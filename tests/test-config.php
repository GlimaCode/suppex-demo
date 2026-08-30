<?php
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
