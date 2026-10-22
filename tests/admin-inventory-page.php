<?php
/* Render an authenticated admin GET in its own process. Never expose over HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$page = (string) ($argv[1] ?? '');
if (!in_array($page, ['products', 'stock'], true)) { exit(1); }
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/admin/' . $page . '.php';
$_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'];
require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
if (db_value('SELECT DATABASE()') !== 'suppex_test') { exit(1); }
$admin = db_one('SELECT id, name, role FROM admins ORDER BY id LIMIT 1');
auth_start_session();
$_SESSION['admin_id'] = (int) $admin['id'];
$_SESSION['admin_name'] = $admin['name'];
$_SESSION['admin_role'] = $admin['role'];
$_GET = $page === 'products' ? ['q' => 'zz-inventory'] : [];
if (($argv[2] ?? '') === 'low') { $_GET['low'] = '1'; }
include SUPPEX_ROOT . '/admin/' . $page . '.php';
