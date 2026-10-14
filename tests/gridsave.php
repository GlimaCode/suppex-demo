<?php
/* One save through the real admin page, editing the combinations grid.
   Own process: product-edit.php declares helpers at file scope.

   argv[1] = product id
   argv[2] = ext:price:cost:stock:active , … */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = 'suppex.ir';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI']    = '/admin/product-edit.php';
$_SERVER['SCRIPT_NAME']    = '/admin/product-edit.php';

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';

auth_start_session();
$_SESSION['admin_id'] = 1; $_SESSION['admin_name'] = 'tester'; $_SESSION['admin_role'] = 'support';
$_SESSION['csrf'] = 't';

$id = (int) ($argv[1] ?? 0);
$p  = db_one('SELECT * FROM products WHERE id = ?', [$id]);
if ($p === null) { fwrite(STDERR, "no product $id\n"); exit(2); }

$sizes = db_all('SELECT * FROM product_sizes WHERE product_id = ? ORDER BY sort_order', [$id]);
$flavs = db_all('SELECT * FROM product_flavors WHERE product_id = ? ORDER BY sort_order', [$id]);

$_GET  = ['id' => (string) $id];
$_POST = [
    'action' => 'save', '_csrf' => 't',
    'name_fa' => $p['name_fa'], 'name_en' => $p['name_en'], 'slug' => $p['slug'],
    'brand' => $p['brand'], 'category_id' => (string) $p['category_id'],
    'price' => (string) $p['price'],
    'compare_at' => $p['compare_at'] === null ? '' : (string) $p['compare_at'],
    'in_stock' => (int) $p['in_stock'] === 1 ? '1' : '',
    'is_active' => (int) $p['is_active'] === 1 ? '1' : '',
    'short' => (string) $p['short'], 'description' => (string) $p['description'],
    'variant_label' => (string) $p['variant_label'], 'sort_order' => (string) $p['sort_order'],
    'price_mode' => $p['price_mode'],
    'cost_aed' => $p['cost_aed'] === null ? '' : (string) $p['cost_aed'],
    'profit_toman' => $p['profit_toman'] === null ? '' : (string) $p['profit_toman'],
    'commission_percent' => $p['commission_percent'] === null ? '' : (string) $p['commission_percent'],

    'size_id'       => array_column($sizes, 'ext_id'),
    'size_label'    => array_column($sizes, 'label'),
    'size_price'    => array_map(static fn($s) => (string) $s['price'], $sizes),
    'size_compare'  => array_map(static fn($s) => (string) ($s['compare_at'] ?? ''), $sizes),
    'size_cost_aed' => array_map(static fn($s) => (string) ($s['cost_aed'] ?? ''), $sizes),
    'size_cost_toman' => array_map(static fn($s) => (string) ($s['cost_toman'] ?? ''), $sizes),
    'size_profit'   => array_map(static fn($s) => (string) ($s['profit_toman'] ?? ''), $sizes),
    'size_servings' => array_map(static fn($s) => (string) ($s['servings'] ?? ''), $sizes),

    'flavor_id'    => array_column($flavs, 'ext_id'),
    'flavor_name'  => array_column($flavs, 'name'),
    /* Posted back rather than blanked. An empty premium is a real edit -
       the first version of this harness sent one for every flavour and so
       zeroed them all before the grid was built from them. */
    'flavor_extra' => array_map(static function ($f) use ($p) {
        if ($p['price_mode'] === 'aed') {
            return $f['cost_delta_aed'] === null ? '' : (string) (float) $f['cost_delta_aed'];
        }
        return (int) $f['price_delta_toman'] > 0 ? (string) (int) $f['price_delta_toman'] : '';
    }, $flavs),
];

/* The grid, exactly as the form posts it: parallel arrays plus a checkbox map
   keyed by ext_id. */
if (($argv[2] ?? '') !== '') {
    $ext = []; $price = []; $cost = []; $stock = []; $active = [];
    foreach (explode(',', (string) $argv[2]) as $spec) {
        [$e, $pr, $co, $st, $ac] = array_pad(explode(':', $spec), 5, '');
        $ext[] = $e; $price[] = $pr; $cost[] = $co; $stock[] = $st;
        if ($ac === '1') { $active[$e] = '1'; }
    }
    $_POST['variant_ext']    = $ext;
    $_POST['variant_price']  = $price;
    $_POST['variant_cost']   = $cost;
    $_POST['variant_stock']  = $stock;
    $_POST['variant_active'] = $active;
}


 ob_start();
 include SUPPEX_ROOT . '/admin/product-edit.php';
 ob_end_clean();
 
