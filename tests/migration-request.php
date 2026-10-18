<?php
/* Real migration controller in an isolated CLI process; never public. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = ($argv[1] ?? '') === 'get' ? 'GET' : 'POST';
$_SERVER['REQUEST_URI'] = '/admin/migrate.php';
$_SERVER['SCRIPT_NAME'] = '/admin/migrate.php';
require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
auth_start_session();
$_SESSION['admin_id'] = 1;
$_SESSION['admin_name'] = 'migration tester';
$_SESSION['admin_role'] = (string) ($argv[2] ?? 'support');
$_SESSION['csrf'] = 'migration-request-token';
$_POST = [];
if (($argv[1] ?? '') === 'valid') { $_POST['_csrf'] = 'migration-request-token'; }
if (($argv[1] ?? '') === 'wrong') { $_POST['_csrf'] = 'wrong-token'; }
ob_start();
register_shutdown_function(static function (): void {
    $html = (string) ob_get_clean();
    echo json_encode([
        'status' => http_response_code() ?: 200,
        'ran' => $GLOBALS['ran'] ?? false,
        'formToken' => strpos($html, 'name="_csrf" value="migration-request-token"') !== false,
    ]), PHP_EOL;
});
include SUPPEX_ROOT . '/admin/migrate.php';
