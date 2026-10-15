<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP.
   bootstrap falls back to the real suppex-config.php when SUPPEX_CONFIG
   is unset - which is exactly what a web request has - so one GET to an
   uploaded tests/ folder could empty the live database. The .htaccess
   beside this file is process; this is code. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* One migration run, in its own process, printing only the outcome.
   Used by test-migrate.php to prove a second run is a no-op: including
   admin/migrate.php twice in one process redeclares its helpers. */
$_SERVER["REMOTE_ADDR"]    = "127.0.0.1";
$_SERVER["HTTP_HOST"]      = "suppex.ir";
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REQUEST_URI"]    = "/admin/migrate.php";
$_SERVER["SCRIPT_NAME"]    = "/admin/migrate.php";

require __DIR__ . "/../lib/bootstrap.php";
require_once SUPPEX_ROOT . "/lib/auth.php";
auth_start_session();
$_SESSION["admin_id"] = 1; $_SESSION["admin_name"] = "tester"; $_SESSION["admin_role"] = "support";

ob_start();
include SUPPEX_ROOT . "/admin/migrate.php";
ob_get_clean();

echo json_encode([
    "states"  => array_map(static fn($e) => $e[0], $log),
    "labels"  => array_map(static fn($e) => $e[1], $log),
    "allDone" => $allDone,
    "columns" => count(db_all("SHOW COLUMNS FROM products")),
], JSON_UNESCAPED_UNICODE);