<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/bootstrap.php';
require SUPPEX_ROOT . '/lib/auth.php';
require __DIR__ . '/admin-session.php';
$probePage = (string) ($argv[1] ?? '');
if (!in_array($probePage, ['users.php', 'migrate.php', 'preflight.php'], true)) { exit(1); }
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/admin/' . $probePage;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
auth_start_session();
$_SESSION['admin_id'] = 1; $_SESSION['admin_role'] = 'support';
test_admin_session();
$_SESSION['csrf'] = 'fixture-csrf';
$_POST = json_decode((string) base64_decode($argv[2] ?? ''), true) ?: [];
register_shutdown_function(static function (): void {
    global $errors;
    while (ob_get_level()) { ob_end_clean(); }
    echo json_encode(['status' => http_response_code() ?: 200, 'errors' => $errors ?? []], JSON_UNESCAPED_UNICODE);
});
ob_start();
require SUPPEX_ROOT . '/admin/' . $probePage;
