<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/* Post a product edit that WILL be refused, and print what the form re-renders.

   In its own process because product-edit.php renders a whole page and any
   comparison has to happen against that output, not alongside it.

   argv[1] = product id */

$_SERVER["REMOTE_ADDR"]    = "127.0.0.1";
$_SERVER["HTTP_HOST"]      = "suppex.ir";
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REQUEST_URI"]    = "/admin/product-edit.php";
$_SERVER["SCRIPT_NAME"]    = "/admin/product-edit.php";

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';

$id = (int) ($argv[1] ?? 0);

$admin = db_one('SELECT id, name, role FROM admins ORDER BY id LIMIT 1');
auth_start_session();
$_SESSION['admin_id']   = (int) $admin['id'];
$_SESSION['admin_name'] = $admin['name'];
$_SESSION['admin_role'] = $admin['role'];
require_once __DIR__ . '/admin-session.php'; test_admin_session();
$_SESSION['csrf']       = 't';

$_GET  = ['id' => (string) $id];
$_POST = [
    '_csrf'       => 't',
    'action'      => 'save',
    'name_fa'     => 'محصول آزمایشی رد شده',
    'name_en'     => 'Refused Product',
    'brand'       => 'SUPPEX',
    'slug'        => 'refused-product-test',
    'price'       => '1000000',
    /* The refusal: compare_at below price is rejected by the validator. */
    'compare_at'  => '1',
    'in_stock'    => '1',

    /* Everything below is what the operator had just typed and must get back. */
    'features'    => "AAA-TYPED-FEATURE\nBBB-SECOND-FEATURE",
    'description' => 'CCC-TYPED-DESCRIPTION',

    'flavor_id'   => ['van', 'choc'],
    'flavor_name' => ['DDD-وانیل', 'EEE-شکلات'],

    'size_id'         => ['900', '1800'],
    'size_label'      => ['FFF-900 گرم', 'GGG-1800 گرم'],
    'size_servings'   => ['30', '60'],
    'size_cost_aed'   => ['42.50', '80'],
    'size_cost_toman' => ['', ''],
    'size_profit'     => ['1705000', '2000000'],
    'size_price'      => ['2450000', '4590000'],
    'size_compare'    => ['2980000', '5600000'],
    'size_prev_id'    => ['900', '1800'],

    'nutrition_label' => ['HHH-پروتئین'],
    'nutrition_value' => ['III-24 g'],
];

ob_start();
include SUPPEX_ROOT . '/admin/product-edit.php';
$html = (string) ob_get_clean();

/* Print only what the checker needs, so the suite can assert on tokens rather
   than on a whole rendered page. */
foreach (['AAA-TYPED-FEATURE', 'BBB-SECOND-FEATURE', 'CCC-TYPED-DESCRIPTION',
          'DDD-وانیل', 'EEE-شکلات', 'FFF-900 گرم', 'GGG-1800 گرم',
          'HHH-پروتئین', 'III-24 g', '1705000'] as $token) {
    echo (strpos($html, $token) !== false ? 'KEPT ' : 'LOST '), $token, "\n";
}
