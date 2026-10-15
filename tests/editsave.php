<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP.
   bootstrap falls back to the real suppex-config.php when SUPPEX_CONFIG
   is unset - which is exactly what a web request has - so one GET to an
   uploaded tests/ folder could empty the live database. The .htaccess
   beside this file is process; this is code. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* Save one product through the admin form, changing nothing.

   In its own process because admin/product-edit.php ends a successful save
   with header() + exit, which would kill any comparison running after it.

   argv[1] = product id
   argv[2] = optional comma-separated image ids, posted as order[] - what the
             «مدیریت تصاویر» card sends when the owner has arranged the photos */

$_SERVER["REMOTE_ADDR"]    = "127.0.0.1";
$_SERVER["HTTP_HOST"]      = "suppex.ir";
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REQUEST_URI"]    = "/admin/product-edit.php";
$_SERVER["SCRIPT_NAME"]    = "/admin/product-edit.php";

require __DIR__ . "/../lib/bootstrap.php";
require_once SUPPEX_ROOT . "/lib/auth.php";

auth_start_session();
$_SESSION["admin_id"] = 1; $_SESSION["admin_name"] = "tester"; $_SESSION["admin_role"] = "support";
$_SESSION["csrf"] = "t";

$id = (int) ($argv[1] ?? 0);
$p  = db_one("SELECT * FROM products WHERE id = ?", [$id]);
if ($p === null) { fwrite(STDERR, "no product $id\n"); exit(2); }

$sizes = db_all("SELECT * FROM product_sizes WHERE product_id = ? ORDER BY sort_order", [$id]);
$flavs = db_all("SELECT * FROM product_flavors WHERE product_id = ? ORDER BY sort_order", [$id]);

/* Exactly what the form posts back when a person opens the page and presses
   save without touching anything: every input the template renders, filled
   with the value the template would have rendered into it. */
$_GET  = ["id" => (string) $id];
$_POST = [
    "action"     => "save",
    "_csrf"      => "t",
    "name_fa"    => $p["name_fa"],
    "name_en"    => $p["name_en"],
    "slug"       => $p["slug"],
    "brand"      => $p["brand"],
    "category_id" => (string) $p["category_id"],
    "price"      => (string) $p["price"],
    "compare_at" => $p["compare_at"] === null ? "" : (string) $p["compare_at"],
    "in_stock"   => (int) $p["in_stock"] === 1 ? "1" : "",
    "is_active"  => (int) $p["is_active"] === 1 ? "1" : "",
    "short"      => (string) $p["short"],
    "description" => (string) $p["description"],
    "variant_label" => (string) $p["variant_label"],
    "sort_order" => (string) $p["sort_order"],
    "price_mode" => $p["price_mode"],
    "cost_aed"   => $p["cost_aed"] === null ? "" : (string) $p["cost_aed"],
    "profit_toman" => $p["profit_toman"] === null ? "" : (string) $p["profit_toman"],
    "commission_percent" => $p["commission_percent"] === null ? "" : (string) $p["commission_percent"],
    "size_id"       => array_column($sizes, "ext_id"),
    "size_label"    => array_column($sizes, "label"),
    "size_price"    => array_map(static fn($s) => (string) $s["price"], $sizes),
    "size_compare"  => array_map(static fn($s) => (string) ($s["compare_at"] ?? ""), $sizes),
    "size_cost_aed" => array_map(static fn($s) => (string) ($s["cost_aed"] ?? ""), $sizes),
    "size_profit"   => array_map(static fn($s) => (string) ($s["profit_toman"] ?? ""), $sizes),
    "size_servings" => array_map(static fn($s) => (string) ($s["servings"] ?? ""), $sizes),

    /* The flavour rows are always on the page, so a browser always posts them.
       Leaving them out here made the harness post something no browser sends -
       and an empty flavor_name[] means «this product has no flavours», so the
       save quietly deleted every one of them.

       The premium is one field in whichever unit the product is bought in,
       and the template renders it blank when there is none. No image field:
       the form posts a file input, and pressing save without choosing a file
       posts nothing at all - which is precisely the case worth testing. */
    "flavor_id"    => array_column($flavs, "ext_id"),
    "flavor_name"  => array_column($flavs, "name"),
    "flavor_extra" => array_map(static function ($f) use ($p) {
        if ($p["price_mode"] === "aed") {
            return $f["cost_delta_aed"] === null ? "" : (string) (float) $f["cost_delta_aed"];
        }
        return (int) $f["price_delta_toman"] > 0 ? (string) (int) $f["price_delta_toman"] : "";
    }, $flavs),
];

/* The badge boxes, ticked exactly where the template ticks them. This harness
   promises to post what the form posts when somebody presses save without
   touching anything - and it never posted these, so every product it saved
   lost its «پرفروش», «جدید» and «تخفیف برتر». A browser does not do that: it
   posts a checked box. An unchecked one posts nothing, which is why only the
   ones that are on appear here. */
foreach (['bestseller', 'new', 'topdeal'] as $b) {
    if (in_array($b, explode(',', (string) $p['badges']), true)) {
        $_POST['badge_' . $b] = '1';
    }
}

if (isset($argv[2]) && $argv[2] !== '') {
    $_POST['order'] = explode(',', $argv[2]);
}

ob_start();
include SUPPEX_ROOT . "/admin/product-edit.php";
ob_end_clean();
