<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* ===========================================================================
   Harness: one panel page, opened as a signed-in admin - what a GET in the
   browser shows. Its own process, because a page can end in exit.

   argv[1] = the page under admin/, e.g. order-print.php
   argv[2] = base64 of a JSON object for $_GET - base64, because on Windows
             escapeshellarg() turns every double quote into a space

   Prints the page.
   =========================================================================== */

$page = basename((string) ($argv[1] ?? ''));
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = 'suppex.ir';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/admin/' . $page;
$_SERVER['SCRIPT_NAME']    = '/admin/' . $page;

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';

$admin = db_one('SELECT id, name, role FROM admins ORDER BY id LIMIT 1');
auth_start_session();
$_SESSION['admin_id']   = (int) ($admin['id'] ?? 1);
$_SESSION['admin_name'] = (string) ($admin['name'] ?? 'tester');
$_SESSION['admin_role'] = (string) ($admin['role'] ?? 'support');
require_once __DIR__ . '/admin-session.php'; test_admin_session();
$_SESSION['csrf']       = 't';

$get  = json_decode((string) base64_decode((string) ($argv[2] ?? ''), true), true);
$_GET = is_array($get) ? $get : [];

require SUPPEX_ROOT . '/admin/' . $page;
