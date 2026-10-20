<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
if (db_value('SELECT DATABASE()') !== 'suppex_test') { throw new RuntimeException('Disposable database required'); }
auth_start_session();
$_SESSION['admin_id'] = 1; $_SESSION['admin_name'] = 'آزمون پنل'; $_SESSION['admin_role'] = 'manager';
$_POST = ['id' => $argv[1], 'action' => $argv[2], '_csrf' => ($argv[3] ?? '') === 'valid' ? csrf_token() : 'bad'];
if (($argv[3] ?? '') === 'anonymous') { unset($_SESSION['admin_id']); }
require SUPPEX_ROOT . '/admin/inquiries.php';
