<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP.
   bootstrap falls back to the real suppex-config.php when SUPPEX_CONFIG
   is unset - which is exactly what a web request has - so one GET to an
   uploaded tests/ folder could empty the live database. The .htaccess
   beside this file is process; this is code. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
return [
  "db" => ["host" => "127.0.0.1;port=" . (getenv("SUPPEX_DB_PORT") ?: "3399"), "name" => "suppex_fresh",
           "user" => "root", "pass" => "", "charset" => "utf8mb4"],
  "uploads_dir"  => __DIR__ . '/tmp/uploads',
  "uploads_url"  => "/uploads",
  "session_name" => "suppex_fresh",
  "https_only"   => false,
  "login"        => ["max_attempts" => 8, "window_minutes" => 15],
  "env"          => "development",
];