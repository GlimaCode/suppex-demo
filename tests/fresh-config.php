<?php
return [
  "db" => ["host" => "127.0.0.1;port=3399", "name" => "suppex_fresh",
           "user" => "root", "pass" => "", "charset" => "utf8mb4"],
  "uploads_dir"  => __DIR__ . '/tmp/uploads',
  "uploads_url"  => "/uploads",
  "session_name" => "suppex_fresh",
  "https_only"   => false,
  "login"        => ["max_attempts" => 8, "window_minutes" => 15],
  "env"          => "development",
];