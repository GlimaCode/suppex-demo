<?php
/* ===========================================================================
   Harness: one POST to admin/categories.php, the way the browser sends it.
   ---------------------------------------------------------------------------
   Its own process for the same reason tests/productpost.php is: the page
   redirects and exits after every write, which would take the calling suite
   down with it.

   argv: base64 of a JSON object of POST fields, and optionally a role.
   Prints the resulting category rows as JSON so the suite can assert on them.
   =========================================================================== */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI']    = '/admin/categories.php';
$_SERVER['SCRIPT_NAME']    = '/admin/categories.php';
$_SERVER['QUERY_STRING']   = '';

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';

$admin = db_one('SELECT id, name FROM admins ORDER BY id LIMIT 1');
auth_start_session();
$_SESSION['admin_id']   = (int) $admin['id'];
$_SESSION['admin_name'] = $admin['name'];
$_SESSION['admin_role'] = (string) ($argv[2] ?? 'support');
$_SESSION['csrf']       = 't';

/* base64, not raw JSON — on Windows escapeshellarg() turns every double quote
   into a space, so a raw JSON argument arrives unparseable and the harness
   silently runs with no POST at all. */
$fields = json_decode((string) base64_decode((string) ($argv[1] ?? ''), true), true);
if (!is_array($fields)) { $fields = []; }
$_POST = array_merge(['_csrf' => 't'], $fields);
$_GET  = [];
$_REQUEST = $_POST;

register_shutdown_function(static function (): void {
    if (ob_get_level() > 0) { ob_end_clean(); }
    $rows = db_all('SELECT id, slug, name_fa, name_latin, blurb, image, sort_order, is_active
                      FROM categories ORDER BY id');
    echo json_encode($rows, JSON_UNESCAPED_UNICODE), "\n";
});

ob_start();
include SUPPEX_ROOT . '/admin/categories.php';
