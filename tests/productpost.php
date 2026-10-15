<?php
/* ===========================================================================
   Harness: one POST to admin/products.php, the way the browser sends it.
   ---------------------------------------------------------------------------
   Its own process because the page redirects and exits after every write, which
   would take the calling suite with it. The same reason tests/stocksave.php
   exists for the stock page.

   argv: a JSON object of POST fields, and optionally a role.
   Prints the resulting rows as JSON so the suite can assert on them.
   =========================================================================== */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI']    = '/admin/products.php';
$_SERVER['SCRIPT_NAME']    = '/admin/products.php';
$_SERVER['QUERY_STRING']   = '';

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';

$admin = db_one('SELECT id, name FROM admins ORDER BY id LIMIT 1');
auth_start_session();
$_SESSION['admin_id']   = (int) $admin['id'];
$_SESSION['admin_name'] = $admin['name'];
$_SESSION['admin_role'] = (string) ($argv[2] ?? 'support');
require_once __DIR__ . '/admin-session.php'; test_admin_session();
$_SESSION['csrf']       = 't';

/* base64, not raw JSON. On Windows escapeshellarg() replaces every double
   quote with a SPACE, so a JSON argument arrives as { action : quick:12 ... }
   and decodes to nothing - the harness then runs with no POST at all and every
   assertion fails as though the page did nothing. Encoded, there are no quotes
   to destroy. */
$fields = json_decode((string) base64_decode((string) ($argv[1] ?? ''), true), true);
if (!is_array($fields)) { $fields = []; }
$_POST = array_merge(['_csrf' => 't'], $fields);
$_GET  = [];
$_REQUEST = $_POST;

/* The page's redirect ends the request, so the report is registered as a
   shutdown handler and runs after it. */
register_shutdown_function(static function (): void {
    if (ob_get_level() > 0) { ob_end_clean(); }
    $rows = db_all('SELECT id, price, compare_at, is_active, in_stock, sort_order,
                           price_mode, stock_qty, category_id, badges
                      FROM products ORDER BY id');
    echo json_encode($rows, JSON_UNESCAPED_UNICODE), "\n";
});

ob_start();
include SUPPEX_ROOT . '/admin/products.php';
