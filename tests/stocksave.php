<?php
/* ===========================================================================
   Harness: post the stock page's form the way a browser would.
   ---------------------------------------------------------------------------
   Its own process because admin/stock.php redirects and exits after a POST,
   which would take the calling suite with it.

   argv: <product-id> <typed> <rendered-as> [<size-ext> <typed> <rendered-as>]

   "rendered-as" is what the form was showing when it was drawn. The page must
   only write a field whose typed value differs from that — the count moves on
   its own while the page is open, and a save that touched nothing else must
   not undo an order placed in the meantime.
   =========================================================================== */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI']    = '/admin/stock.php';
$_SERVER['SCRIPT_NAME']    = '/admin/stock.php';

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';

$admin = db_one('SELECT id, name, role FROM admins ORDER BY id LIMIT 1');
auth_start_session();
$_SESSION['admin_id']   = (int) $admin['id'];
$_SESSION['admin_name'] = $admin['name'];
$_SESSION['admin_role'] = $admin['role'];
$_SESSION['csrf']       = 't';

$pid = (int) ($argv[1] ?? 0);

$_POST = [
    '_csrf' => 't',
    'p'     => [$pid => (string) ($argv[2] ?? '')],
    'p_was' => [$pid => (string) ($argv[3] ?? '')],
];

if (isset($argv[4])) {
    $ext = (string) $argv[4];
    $_POST['s']     = [$pid => [$ext => (string) ($argv[5] ?? '')]];
    $_POST['s_was'] = [$pid => [$ext => (string) ($argv[6] ?? '')]];
}

$_GET = [];
$_REQUEST = $_POST;

/* The page calls header() then exit. Buffer so nothing is printed before the
   result line, and register the report as a shutdown handler so it runs after
   that exit. */
register_shutdown_function(static function () use ($pid): void {
    ob_end_clean();
    $p = db_value('SELECT stock_qty FROM products WHERE id = ?', [$pid]);
    $s = db_all('SELECT ext_id, stock_qty FROM product_sizes WHERE product_id = ? ORDER BY sort_order, id', [$pid]);
    echo 'PRODUCT=', $p === null ? 'NULL' : (int) $p, "\n";
    foreach ($s as $row) {
        echo 'SIZE ', $row['ext_id'], '=', $row['stock_qty'] === null ? 'NULL' : (int) $row['stock_qty'], "\n";
    }
});

ob_start();
include SUPPEX_ROOT . '/admin/stock.php';
