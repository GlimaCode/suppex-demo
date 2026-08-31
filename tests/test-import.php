<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP.
   bootstrap falls back to the real suppex-config.php when SUPPEX_CONFIG
   is unset - which is exactly what a web request has - so one GET to an
   uploaded tests/ folder could empty the live database. The .htaccess
   beside this file is process; this is code. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* Round-trips a cost sheet through the importer against the throwaway database. */

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/products.php';
require_once SUPPEX_ROOT . '/lib/import.php';
/* The features round-trip checks the export the shop actually downloads. */
require_once SUPPEX_ROOT . '/lib/backup.php';

$pass = 0; $fail = 0;
function ok(string $l, bool $c, ?string $x = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $l\n"; }
    else { $fail++; echo "  FAIL $l" . ($x ? "  --  $x" : '') . "\n"; }
}

$tmp = sys_get_temp_dir() . '/suppex-import-test.csv';
/* Rows are given by column KEY, never by position. Positional rows are how
   this suite broke the first time the sheet grew a column: every value after
   the new one shifted left into the wrong field, and the failures pointed at
   the importer rather than at the fixture. */
function sheet(array $rows): string {
    global $tmp;
    $keys = array_keys(IMPORT_COLUMNS);
    $fh = fopen($tmp, 'w');
    fwrite($fh, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($fh, array_values(IMPORT_COLUMNS));
    foreach ($rows as $r) {
        foreach (array_keys($r) as $k) {
            if (!in_array($k, $keys, true)) {
                throw new RuntimeException('fixture uses an unknown column: ' . $k);
            }
        }
        $line = [];
        foreach ($keys as $k) { $line[] = (string) ($r[$k] ?? ''); }
        fputcsv($fh, $line);
    }
    fclose($fh);
    return $tmp;
}

$admin = ['id' => 1, 'name' => 'tester'];
settings_save(['aed_rate' => '25000', 'price_rounding_step' => '10000']);

echo "\n== the template is readable by its own parser ==\n";
file_put_contents($tmp, import_template_csv());
$r = import_read_csv($tmp);
ok('template parses', $r['error'] === null, (string) $r['error']);
ok('template has example rows', count($r['rows']) === 5, (string) count($r['rows']));
$plan = import_plan($r['rows']);
ok('template produces no errors', $plan['errors'] === [], implode(' | ', $plan['errors']));
ok('four products from five rows', count($plan['products']) === 4, (string) count($plan['products']));

echo "\n== rows sharing a name become one product with sizes ==\n";
$multi = $plan['products'][0];
ok('the multi-size product has 2 sizes', count($multi['sizes']) === 2, (string) count($multi['sizes']));
ok('the single-size product collapses',
   isset($plan['products'][1]['single']) && $plan['products'][1]['sizes'] === []);

echo "\n== the dirham formula is applied at import time ==\n";
$small = $multi['sizes'][0];
/* 40 AED x 25,000 = 1,000,000 ; + 1,500,000 = 2,500,000 */
ok('price computed from cost and profit', (int) $small['price'] === 2500000, (string) $small['price']);
ok('cost carried through', (float) $small['cost_aed'] === 40.0);

echo "\n== semicolon-separated files are handled ==\n";
$semi = "\xEF\xBB\xBF" . implode(';', array_values(IMPORT_COLUMNS)) . "\n"
      . "تست سمی‌کالن;Semi Test;SUPPEX;تست;;30;800000;;;;بله;;;;\n";
file_put_contents($tmp, $semi);
$r2 = import_read_csv($tmp);
ok('parsed despite semicolons', $r2['error'] === null && count($r2['rows']) === 1,
   (string) ($r2['error'] ?? count($r2['rows'])));
ok('the name survived', ($r2['rows'][0]['name_fa'] ?? '') === 'تست سمی‌کالن',
   $r2['rows'][0]['name_fa'] ?? '');

echo "\n== bad data is refused, not imported ==\n";
$bad = import_plan(import_read_csv(sheet([
    ['name_fa' => '',             'name_en' => 'No Name',    'cost_aed' => '20',      'profit_toman' => '500000'],
    ['name_fa' => 'قیمت تومانی',  'name_en' => 'Toman Cost', 'cost_aed' => '1200000', 'profit_toman' => '500000'],
    ['name_fa' => 'بدون سود',     'name_en' => 'No Profit',  'cost_aed' => '30'],
    ['name_fa' => 'هیچی',         'name_en' => 'Nothing'],
]))['rows']);
ok('empty name refused',      count(array_filter($bad['errors'], fn($e) => str_contains($e, 'نام فارسی'))) === 1);
ok('toman-in-dirham refused', count(array_filter($bad['errors'], fn($e) => str_contains($e, 'غیرواقعی'))) === 1);
ok('missing profit refused',  count(array_filter($bad['errors'], fn($e) => str_contains($e, 'سود لازم'))) === 1);
ok('no price at all refused', count(array_filter($bad['errors'], fn($e) => str_contains($e, 'یکی از این سه لازم است'))) === 1);
ok('nothing valid survived',  $bad['products'] === [], (string) count($bad['products']));

echo "\n== a multi-size product with a blank size label is refused ==\n";
$blank = import_plan(import_read_csv(sheet([
    ['name_fa' => 'محصول الف', 'name_en' => 'A', 'size_label' => '900 گرم', 'cost_aed' => '40', 'profit_toman' => '1000000'],
    ['name_fa' => 'محصول الف', 'name_en' => 'A', 'size_label' => '',        'cost_aed' => '80', 'profit_toman' => '1500000'],
]))['rows']);
ok('the blank-label row is flagged',
   count(array_filter($blank['errors'], fn($e) => str_contains($e, 'ستون اندازه'))) === 1,
   implode(' | ', $blank['errors']));

echo "\n== apply writes products and sizes ==\n";
db()->exec('SET FOREIGN_KEY_CHECKS = 0');
db()->exec('TRUNCATE TABLE product_sizes');
db()->exec('DELETE FROM products');
db()->exec('SET FOREIGN_KEY_CHECKS = 1');

$good = import_plan(import_read_csv(sheet([
    ['name_fa' => 'پروتئین تست', 'name_en' => 'Test Whey', 'brand' => 'SUPPEX', 'category' => 'پروتئین',
     'size_label' => '900 گرم',  'cost_aed' => '40', 'profit_toman' => '1500000', 'in_stock' => 'بله',
     'short' => 'توضیح کوتاه'],
    ['name_fa' => 'پروتئین تست', 'name_en' => 'Test Whey', 'brand' => 'SUPPEX', 'category' => 'پروتئین',
     'size_label' => '2270 گرم', 'cost_aed' => '95', 'profit_toman' => '2000000', 'in_stock' => 'بله'],
    ['name_fa' => 'کراتین تست',  'name_en' => 'Test Creatine', 'brand' => 'SUPPEX', 'category' => 'کراتین',
     'cost_aed' => '18', 'profit_toman' => '900000', 'in_stock' => 'خیر'],
]))['rows']);
ok('plan is clean', $good['errors'] === [], implode(' | ', $good['errors']));

$res = import_apply($good['products'], $admin);
ok('two products created', $res['created'] === 2, json_encode($res));
ok('two sizes written',    $res['sizes'] === 2,   json_encode($res));

$p = db_one("SELECT * FROM products WHERE name_fa = 'پروتئین تست'");
ok('product exists', $p !== null);
ok('slug came from the latin name', $p['slug'] === 'test-whey', $p['slug']);
ok('category was created', (int) $p['category_id'] > 0);
ok('price tracks the cheapest size', (int) $p['price'] === 2500000, $p['price']);
ok('price_mode is dirham-linked', $p['price_mode'] === 'aed', $p['price_mode']);

$sizes = db_all('SELECT * FROM product_sizes WHERE product_id = ? ORDER BY sort_order', [(int) $p['id']]);
ok('both sizes stored', count($sizes) === 2);
ok('each size has its own cost',
   (float) $sizes[0]['cost_aed'] === 40.0 && (float) $sizes[1]['cost_aed'] === 95.0,
   $sizes[0]['cost_aed'] . ' / ' . $sizes[1]['cost_aed']);
ok('large size is priced higher', (int) $sizes[1]['price'] > (int) $sizes[0]['price']);

$c = db_one("SELECT * FROM products WHERE name_fa = 'کراتین تست'");
ok('single-size product has no sizes',
   (int) db_value('SELECT COUNT(*) FROM product_sizes WHERE product_id = ?', [(int) $c['id']]) === 0);
ok('out-of-stock honoured', (int) $c['in_stock'] === 0, (string) $c['in_stock']);

echo "\n== re-importing updates rather than duplicating ==\n";
$again = import_plan(import_read_csv(sheet([
    ['name_fa' => 'پروتئین تست', 'name_en' => 'Test Whey', 'brand' => 'SUPPEX', 'category' => 'پروتئین',
     'size_label' => '900 گرم', 'cost_aed' => '45', 'profit_toman' => '1600000', 'in_stock' => 'بله',
     'short' => 'توضیح تازه'],
]))['rows']);
$res2 = import_apply($again['products'], $admin);
ok('updated, not created', $res2['created'] === 0 && $res2['updated'] === 1, json_encode($res2));
ok('still one product of that name',
   (int) db_value("SELECT COUNT(*) FROM products WHERE name_fa = 'پروتئین تست'") === 1);
ok('the dropped size is gone',
   (int) db_value('SELECT COUNT(*) FROM product_sizes WHERE product_id = ?', [(int) $p['id']]) === 1,
   'sizes are replaced wholesale from the sheet');
ok('the new cost took effect',
   (float) db_value('SELECT cost_aed FROM product_sizes WHERE product_id = ?', [(int) $p['id']]) === 45.0);

echo "\n== the storefront can read what was imported ==\n";
$api = product_get($p['slug']);
ok('product_get finds it', $api !== null);
ok('it carries its sizes', $api !== null && count($api['sizes']) === 1);

/* --- the columns added for the client-s cost sheet ---------------------- */

echo "\n== commission: empty, zero and a number are three different answers ==\n";
ok("empty inherits the shop rate", import_percent("") === null);
ok("zero is a real decision, not inherit", import_percent("0") === 0.0);
ok("and the two are not conflated", import_percent("0") !== import_percent(""));
ok("persian digits", import_percent("۱۲") === 12.0, json_encode(import_percent("۱۲")));
ok("a trailing percent sign is fine", import_percent("15%") === 15.0);
ok("a persian percent sign too", import_percent("15٪") === 15.0);
ok("over 100 is refused", import_percent("140") === false);
ok("negative is refused", import_percent("-5") === false);
ok("words are refused", import_percent("زیاد") === false);

echo "\n== flavours split on whatever separator was used ==\n";
ok("persian comma", import_flavors("شکلاتی، وانیلی") === ["شکلاتی", "وانیلی"]);
ok("latin comma", import_flavors("Chocolate, Vanilla") === ["Chocolate", "Vanilla"]);
ok("slash", count(import_flavors("شکلاتی / وانیلی / موز")) === 3);
ok("duplicates collapse", import_flavors("موز، موز") === ["موز"]);
ok("empty gives nothing", import_flavors("") === []);

echo "\n== the new columns survive a round trip ==\n";
sheet([
    ["name_fa" => "پروتئین تست", "name_en" => "Test Protein", "brand" => "SUPPEX",
     "category" => "پروتئین", "size_label" => "۹۰۰ گرم", "cost_aed" => "40",
     "profit_toman" => "1500000", "promo_toman" => "100000", "commission" => "12",
     "in_stock" => "بله", "flavors" => "شکلاتی، وانیلی", "servings" => "30",
     "short" => "توضیح کوتاه", "description" => "توضیح کامل محصول"],
    ["name_fa" => "پروتئین تست", "name_en" => "Test Protein", "brand" => "SUPPEX",
     "category" => "پروتئین", "size_label" => "۲۲۷۰ گرم", "cost_aed" => "95",
     "profit_toman" => "2000000", "commission" => "12", "in_stock" => "بله",
     "servings" => "76"],
    ["name_fa" => "کراتین تست", "name_en" => "Test Creatine", "brand" => "SUPPEX",
     "category" => "کراتین", "cost_aed" => "18", "profit_toman" => "900000",
     "in_stock" => "بله", "servings" => "100"],
]);
$r = import_read_csv($tmp);
ok("sheet parses", $r["error"] === null, (string) $r["error"]);
$plan = import_plan($r["rows"]);
ok("no errors", $plan["errors"] === [], implode(" | ", $plan["errors"]));

$multi = null; $single = null;
foreach ($plan["products"] as $p) {
    if ($p["name_fa"] === "پروتئین تست") { $multi = $p; }
    if ($p["name_fa"] === "کراتین تست")  { $single = $p; }
}
ok("multi-size product found", $multi !== null);
ok("description read", $multi !== null && $multi["description"] === "توضیح کامل محصول",
   $multi["description"] ?? "");
ok("flavours read from the first row that had them",
   $multi !== null && $multi["flavors"] === ["شکلاتی", "وانیلی"],
   json_encode($multi["flavors"] ?? [], JSON_UNESCAPED_UNICODE));
ok("promo on the size that carried it",
   $multi !== null && (int) $multi["sizes"][0]["promo_toman"] === 100000,
   json_encode($multi["sizes"][0]["promo_toman"] ?? null));
ok("and none on the size that did not",
   $multi !== null && $multi["sizes"][1]["promo_toman"] === null);
ok("servings read", $multi !== null && (int) $multi["sizes"][0]["servings"] === 30);
ok("commission on each size", $multi !== null
   && $multi["sizes"][0]["commission"] === 12.0
   && $multi["sizes"][1]["commission"] === 12.0);
ok("a blank commission stays null", $single !== null
   && array_key_exists("commission", $single["single"])
   && $single["single"]["commission"] === null,
   json_encode($single["single"] ?? null));

$res = import_apply($plan["products"], $admin);
/* These two names already exist from the block above, so the sheet updates
   rather than creates. Either way it is two products. */
ok("applied", $res["created"] + $res["updated"] === 2, json_encode($res));

$row = db_one("SELECT id, description, commission_percent, promo_toman
                 FROM products WHERE name_fa = ?", ["پروتئین تست"]);
ok("description stored", $row !== null && $row["description"] === "توضیح کامل محصول");
ok("both sizes agree, so the parent carries the rate",
   $row !== null && (float) $row["commission_percent"] === 12.0,
   json_encode($row["commission_percent"] ?? null));
ok("flavours stored",
   (int) db_value("SELECT COUNT(*) FROM product_flavors WHERE product_id = ?",
                  [(int) $row["id"]]) === 2);
$sz = db_all("SELECT label, servings, promo_toman, commission_percent, price, compare_at
                FROM product_sizes WHERE product_id = ? ORDER BY sort_order", [(int) $row["id"]]);
ok("two size rows", count($sz) === 2, (string) count($sz));
ok("servings stored", (int) $sz[0]["servings"] === 30);
ok("commission stored per size", (float) $sz[0]["commission_percent"] === 12.0);
ok("the discount produced a strikethrough",
   $sz[0]["compare_at"] !== null && (int) $sz[0]["compare_at"] > (int) $sz[0]["price"],
   json_encode([$sz[0]["price"], $sz[0]["compare_at"]]));

echo "\n== the commission log records what the import decided ==\n";
ok("a log entry per rate set",
   (int) db_value("SELECT COUNT(*) FROM commission_log WHERE label LIKE ?",
                  ["پروتئین تست%"]) >= 1);
ok("it names the admin who ran the import",
   db_value("SELECT admin_name FROM commission_log ORDER BY id DESC LIMIT 1") === "tester");
ok("and the count is reported back", ($res["rates"] ?? 0) >= 1, json_encode($res));

echo "\n== sizes that disagree leave the parent on the shop rate ==\n";
sheet([
    ["name_fa" => "اختلاف تست", "name_en" => "Split Test", "category" => "پروتئین",
     "size_label" => "۹۰۰ گرم",  "cost_aed" => "40", "profit_toman" => "1500000", "commission" => "12"],
    ["name_fa" => "اختلاف تست", "name_en" => "Split Test", "category" => "پروتئین",
     "size_label" => "۲۲۷۰ گرم", "cost_aed" => "95", "profit_toman" => "2000000", "commission" => "6"],
]);
$plan = import_plan(import_read_csv($tmp)["rows"]);
ok("parses", $plan["errors"] === [], implode(" | ", $plan["errors"]));
import_apply($plan["products"], $admin);
$row = db_one("SELECT id, commission_percent FROM products WHERE name_fa = ?", ["اختلاف تست"]);
ok("the parent stays null when the sizes disagree",
   $row !== null && $row["commission_percent"] === null,
   json_encode($row["commission_percent"] ?? "missing"));
$rates = db_all("SELECT commission_percent FROM product_sizes
                  WHERE product_id = ? ORDER BY sort_order", [(int) $row["id"]]);
ok("but each size keeps its own",
   (float) $rates[0]["commission_percent"] === 12.0
   && (float) $rates[1]["commission_percent"] === 6.0,
   json_encode($rates));

echo "\n== a nonsense commission stops the whole import ==\n";
sheet([
    ["name_fa" => "بد تست", "name_en" => "Bad", "cost_aed" => "20",
     "profit_toman" => "800000", "commission" => "250"],
]);
$plan = import_plan(import_read_csv($tmp)["rows"]);
ok("refused", count($plan["errors"]) === 1, implode(" | ", $plan["errors"]));
ok("and nothing was planned", $plan["products"] === []);

echo "\n== an over-large discount warns rather than silently shrinking ==\n";
sheet([
    ["name_fa" => "تخفیف تست", "name_en" => "Promo", "cost_aed" => "20",
     "profit_toman" => "800000", "promo_toman" => "600000"],
]);
$plan = import_plan(import_read_csv($tmp)["rows"]);
ok("no error", $plan["errors"] === []);
ok("but a warning", count($plan["warnings"]) === 1, implode(" | ", $plan["warnings"]));
ok("naming the capped figure",
   mb_strpos($plan["warnings"][0] ?? "", "400,000") !== false, $plan["warnings"][0] ?? "");

echo "\n== an old sheet without the new columns still imports ==\n";
$fh = fopen($tmp, "w");
fwrite($fh, "\xEF\xBB\xBF");
fputcsv($fh, ["نام فارسی", "نام انگلیسی", "برند", "دسته", "اندازه",
              "قیمت خرید (درهم)", "سود (تومان)", "قیمت فروش (تومان)", "موجود", "توضیح کوتاه"]);
fputcsv($fh, ["قدیمی تست", "Legacy", "SUPPEX", "پروتئین", "", "30", "1000000", "", "بله", "توضیح"]);
fclose($fh);
$r = import_read_csv($tmp);
ok("parses", $r["error"] === null, (string) $r["error"]);
$plan = import_plan($r["rows"]);
ok("no errors", $plan["errors"] === [], implode(" | ", $plan["errors"]));
ok("commission defaults to inherit",
   array_key_exists("commission", $plan["products"][0]["single"])
   && $plan["products"][0]["single"]["commission"] === null,
   json_encode($plan["products"][0]["single"]));
$res = import_apply($plan["products"], $admin);
ok("applied", $res["created"] === 1, json_encode($res));
echo "\n== an imported catalogue books real profit before anyone reprices ==\n";
/* pricing_apply() fills cost_toman for dirham-priced rows, but a shop that
   imports and goes live the same afternoon may never have pressed it. Until
   the import wrote the converted cost itself, every one of those orders
   recorded a cost of zero and booked the whole price as profit. */
db()->exec("SET FOREIGN_KEY_CHECKS = 0");
db()->exec("TRUNCATE TABLE product_sizes");
db()->exec("TRUNCATE TABLE product_flavors");
db()->exec("DELETE FROM products");
db()->exec("SET FOREIGN_KEY_CHECKS = 1");
settings_save(["aed_rate" => "25000", "price_rounding_step" => "10000"]);

$plan = import_plan(import_read_csv(sheet([
    ["name_fa" => "درهمی تست", "name_en" => "Aed Buy", "category" => "پروتئین",
     "cost_aed" => "40", "profit_toman" => "1500000", "in_stock" => "بله"],
]))["rows"]);
ok("parses", $plan["errors"] === [], implode(" | ", $plan["errors"]));
import_apply($plan["products"], $admin);

$row = db_one("SELECT id, slug, price, cost_toman, price_applied_rate, price_mode
                 FROM products WHERE name_fa = ?", ["درهمی تست"]);
/* 40 x 25,000 = 1,000,000 */
ok("the converted cost was stored", (int) $row["cost_toman"] === 1000000,
   json_encode($row["cost_toman"]));
ok("with the rate it was built from", (float) $row["price_applied_rate"] === 25000.0,
   json_encode($row["price_applied_rate"]));
ok("and it stays dirham-linked", $row["price_mode"] === "aed", $row["price_mode"]);

require_once SUPPEX_ROOT . "/lib/orders.php";
$line = order_price_line(["slug" => $row["slug"], "qty" => 1]);
ok("an order booked before any repricing knows the cost",
   $line["unit_cost"] === 1000000, json_encode($line["unit_cost"]));
ok("and the profit, not the whole price",
   $line["unit_profit"] === 1500000, json_encode($line["unit_profit"]));

/* Sizes too: the parent card must mirror the cheapest size, cost and all. */
db()->exec("SET FOREIGN_KEY_CHECKS = 0");
db()->exec("TRUNCATE TABLE product_sizes");
db()->exec("DELETE FROM products");
db()->exec("SET FOREIGN_KEY_CHECKS = 1");
$plan = import_plan(import_read_csv(sheet([
    ["name_fa" => "درهمی چنداندازه", "name_en" => "Aed Sizes", "category" => "پروتئین",
     "size_label" => "۹۰۰ گرم",  "cost_aed" => "40", "profit_toman" => "1500000", "in_stock" => "بله"],
    ["name_fa" => "درهمی چنداندازه", "name_en" => "Aed Sizes", "category" => "پروتئین",
     "size_label" => "۲۲۷۰ گرم", "cost_aed" => "95", "profit_toman" => "2000000", "in_stock" => "بله"],
]))["rows"]);
import_apply($plan["products"], $admin);
$p  = db_one("SELECT id, price, cost_toman FROM products WHERE name_fa = ?", ["درهمی چنداندازه"]);
$sz = db_all("SELECT ext_id, price, cost_toman FROM product_sizes
               WHERE product_id = ? ORDER BY price", [(int) $p["id"]]);
ok("each size stored its own converted cost",
   (int) $sz[0]["cost_toman"] === 1000000 && (int) $sz[1]["cost_toman"] === 2375000,
   json_encode(array_column($sz, "cost_toman")));
ok("the card mirrors the cheapest size's cost",
   (int) $p["cost_toman"] === (int) $sz[0]["cost_toman"],
   $p["cost_toman"] . " vs " . $sz[0]["cost_toman"]);
$line = order_price_line(["slug" => db_value("SELECT slug FROM products WHERE id = ?", [(int) $p["id"]]),
                          "sizeId" => $sz[1]["ext_id"], "qty" => 1]);
ok("the larger size books its own cost, not the card's",
   $line["unit_cost"] === 2375000, json_encode($line["unit_cost"]));
echo "\n== a purchase made in toman ==\n";
/* The shop buys most stock in dirham, but not all of it. Before this column
   existed the toman-bought lines had no cost at all, so every order booked
   their whole price as profit - and the profit basis is what the partnership
   share is computed from. */
db()->exec("SET FOREIGN_KEY_CHECKS = 0");
db()->exec("TRUNCATE TABLE product_sizes");
db()->exec("TRUNCATE TABLE product_flavors");
db()->exec("DELETE FROM products");
db()->exec("SET FOREIGN_KEY_CHECKS = 1");

$plan = import_plan(import_read_csv(sheet([
    ["name_fa" => "تومانی تست", "name_en" => "Toman Buy", "category" => "ویتامین و مکمل",
     "cost_toman" => "650000", "profit_toman" => "600000", "in_stock" => "بله"],
]))["rows"]);
ok("parses", $plan["errors"] === [], implode(" | ", $plan["errors"]));
$u = $plan["products"][0]["single"];
ok("the toman cost is kept", (int) $u["cost_toman"] === 650000, json_encode($u["cost_toman"]));
ok("no dirham cost", $u["cost_aed"] === null);
/* 650,000 + 600,000 = 1,250,000, already on the 10,000 step. */
ok("price is cost plus profit", (int) $u["price"] === 1250000, (string) $u["price"]);

import_apply($plan["products"], $admin);
$row = db_one("SELECT id, price, cost_toman, cost_aed, price_mode
                 FROM products WHERE name_fa = ?", ["تومانی تست"]);
ok("cost_toman reached the database", (int) $row["cost_toman"] === 650000,
   json_encode($row["cost_toman"]));
ok("and it is not dirham-linked", $row["price_mode"] === "manual", $row["price_mode"]);

/* The point of the whole column: an order now knows what the tub cost. */
require_once SUPPEX_ROOT . "/lib/orders.php";
$line = order_price_line(["slug" => db_value("SELECT slug FROM products WHERE id = ?",
                                             [(int) $row["id"]]), "qty" => 1]);
ok("an order records the cost", $line["unit_cost"] === 650000, json_encode($line["unit_cost"]));
ok("and the real profit, not the whole price",
   $line["unit_profit"] === 600000, json_encode($line["unit_profit"]));

echo "\n== the dirham rate does not move a toman-bought line ==\n";
settings_save(["aed_rate" => "40000"]);
$units = pricing_units(40000.0);
ok("it stays out of the repricing preview",
   count(array_filter($units, static fn($x) => $x["product_id"] === (int) $row["id"])) === 0,
   (string) count($units));
settings_save(["aed_rate" => "25000"]);

echo "\n== a market-set price, with the cost recorded beside it ==\n";
$plan = import_plan(import_read_csv(sheet([
    ["name_fa" => "رقابتی تست", "name_en" => "Crowded", "category" => "آمینو و BCAA",
     "cost_toman" => "820000", "price_toman" => "1150000", "in_stock" => "بله"],
]))["rows"]);
ok("parses with no profit column", $plan["errors"] === [], implode(" | ", $plan["errors"]));
$u = $plan["products"][0]["single"];
ok("the typed price is used", (int) $u["price"] === 1150000, (string) $u["price"]);
import_apply($plan["products"], $admin);
$line = order_price_line(["slug" => db_value("SELECT slug FROM products WHERE name_fa = ?",
                                             ["رقابتی تست"]), "qty" => 1]);
ok("profit is the difference", $line["unit_profit"] === 330000, json_encode($line));

echo "\n== the ways of getting the purchase price wrong ==\n";
$bad = import_plan(import_read_csv(sheet([
    ["name_fa" => "هردو", "cost_aed" => "40", "cost_toman" => "900000", "profit_toman" => "500000"],
    ["name_fa" => "ریز", "cost_toman" => "40", "profit_toman" => "500000"],
    ["name_fa" => "بی‌قیمت", "cost_toman" => "800000"],
    ["name_fa" => "وارونه", "cost_toman" => "1200000", "price_toman" => "900000"],
]))["rows"]);
ok("both columns filled is refused",
   count(array_filter($bad["errors"], static fn($e) => str_contains($e, "فقط یکی از دو ستون"))) === 1,
   implode(" | ", $bad["errors"]));
ok("a dirham figure in the toman column is refused",
   count(array_filter($bad["errors"], static fn($e) => str_contains($e, "احتمالاً به درهم"))) === 1);
ok("a cost with no profit and no price is refused",
   count(array_filter($bad["errors"], static fn($e) => str_contains($e, "یا سود لازم است"))) === 1);
ok("a price below the cost is refused",
   count(array_filter($bad["errors"], static fn($e) => str_contains($e, "جابه‌جا"))) === 1);
ok("nothing survived", $bad["products"] === [], (string) count($bad["products"]));

echo "\n== sizes bought in toman each keep their own cost ==\n";
$plan = import_plan(import_read_csv(sheet([
    ["name_fa" => "چنداندازه تومانی", "name_en" => "Toman Sizes", "category" => "کراتین",
     "size_label" => "۳۰۰ گرم", "cost_toman" => "400000", "profit_toman" => "300000", "in_stock" => "بله"],
    ["name_fa" => "چنداندازه تومانی", "name_en" => "Toman Sizes", "category" => "کراتین",
     "size_label" => "۶۰۰ گرم", "cost_toman" => "700000", "profit_toman" => "500000", "in_stock" => "بله"],
]))["rows"]);
ok("parses", $plan["errors"] === [], implode(" | ", $plan["errors"]));
import_apply($plan["products"], $admin);
$p = db_one("SELECT id, price, cost_toman, price_mode FROM products WHERE name_fa = ?",
            ["چنداندازه تومانی"]);
$sz = db_all("SELECT label, price, cost_toman FROM product_sizes
               WHERE product_id = ? ORDER BY sort_order", [(int) $p["id"]]);
ok("each size has its own cost",
   (int) $sz[0]["cost_toman"] === 400000 && (int) $sz[1]["cost_toman"] === 700000,
   json_encode(array_column($sz, "cost_toman")));
ok("the card shows the cheapest size price", (int) $p["price"] === (int) $sz[0]["price"],
   $p["price"] . " vs " . $sz[0]["price"]);
ok("and carries THAT size's cost, not the other one's",
   (int) $p["cost_toman"] === (int) $sz[0]["cost_toman"],
   $p["cost_toman"] . " vs " . $sz[0]["cost_toman"]);
ok("a toman-only product is not marked dirham-linked",
   $p["price_mode"] === "manual", $p["price_mode"]);

/* An order for the larger tub must use the larger tub's cost. Mixing them is
   the bug that corrupted the ledger once already. */
$slug = db_value("SELECT slug FROM products WHERE id = ?", [(int) $p["id"]]);
$ext  = db_value("SELECT ext_id FROM product_sizes WHERE product_id = ? ORDER BY sort_order DESC LIMIT 1",
                 [(int) $p["id"]]);
$line = order_price_line(["slug" => $slug, "sizeId" => $ext, "qty" => 1]);
ok("the large size books its own cost", $line["unit_cost"] === 700000, json_encode($line));
ok("and its own profit", $line["unit_profit"] === 500000, json_encode($line));
echo "\n== a column the sheet computes is not read back as data ==\n";
/* The workbook works out the share in toman beside the percentage, and Excel
   exports that as a value like any other cell. The importer has to skip it:
   a computed column feeding itself back in would be the sheet dictating money
   to the shop. */
$keys = array_keys(IMPORT_COLUMNS);
$fh = fopen($tmp, "w");
fwrite($fh, chr(0xEF) . chr(0xBB) . chr(0xBF));
$head = [];
foreach ($keys as $k) {
    $head[] = IMPORT_COLUMNS[$k];
    if ($k === "commission") { $head[] = "سهم ما (تومان)"; }
}
fputcsv($fh, $head);
$vals = ["name_fa" => "ستون اضافه", "name_en" => "Extra Col",
         "cost_toman" => "650000", "profit_toman" => "600000", "in_stock" => "بله"];
$line = [];
foreach ($keys as $k) {
    $line[] = $vals[$k] ?? "";
    if ($k === "commission") { $line[] = "999999999"; }
}
fputcsv($fh, $line);
fclose($fh);

$r = import_read_csv($tmp);
ok("an unknown column does not break parsing", $r["error"] === null, (string) $r["error"]);
$plan = import_plan($r["rows"]);
ok("nor planning", $plan["errors"] === [], implode(" | ", $plan["errors"]));
$u = $plan["products"][0]["single"];
ok("the share stays unset", $u["commission"] === null, json_encode($u["commission"]));
ok("and the real columns still landed",
   (int) $u["cost_toman"] === 650000 && (int) $u["price"] === 1250000,
   json_encode([$u["cost_toman"], $u["price"]]));
import_apply($plan["products"], $admin);
ok("nothing absurd reached the database",
   (int) db_value("SELECT COUNT(*) FROM products WHERE commission_percent > 100") === 0);

echo "\n== a discount on a market-priced row actually comes off ==\n";
/* The promo used to be stored and never applied on this route: no saving, no
   strikethrough, and nothing saying why. */
$plan = import_plan(import_read_csv(sheet([
    ["name_fa" => "بی‌تخفیف", "cost_toman" => "820000", "price_toman" => "1150000"],
    ["name_fa" => "باتخفیف",  "cost_toman" => "820000", "price_toman" => "1150000",
     "promo_toman" => "50000"],
]))["rows"]);
ok("parses", $plan["errors"] === [], implode(" | ", $plan["errors"]));
$plain = $discounted = null;
foreach ($plan["products"] as $p) {
    if ($p["name_fa"] === "بی‌تخفیف") { $plain = $p["single"]; }
    if ($p["name_fa"] === "باتخفیف")  { $discounted = $p["single"]; }
}
ok("without a discount the typed price stands",
   (int) $plain["price"] === 1150000 && $plain["compare_at"] === null,
   json_encode([$plain["price"], $plain["compare_at"]]));
ok("with one, the price drops", (int) $discounted["price"] === 1100000,
   (string) $discounted["price"]);
ok("and the old price is struck through",
   (int) $discounted["compare_at"] === 1150000, json_encode($discounted["compare_at"]));
ok("the cost is untouched either way",
   (int) $discounted["cost_toman"] === 820000, json_encode($discounted["cost_toman"]));
echo "\n== a panel edit must not erase what the sheet set ==\n";
/* The product form replaces a product's sizes wholesale, and has no input for
   the commission rate or the discount. Opening a product and pressing save
   without changing anything used to destroy both, along with the toman cost -
   and a commission rate silently reverting to the shop default is money moving
   between two parties because somebody looked at a page. */
db()->exec("SET FOREIGN_KEY_CHECKS = 0");
db()->exec("TRUNCATE TABLE product_sizes");
db()->exec("TRUNCATE TABLE product_flavors");
db()->exec("DELETE FROM products");
db()->exec("SET FOREIGN_KEY_CHECKS = 1");
db_query("INSERT INTO admins (id, username, password_hash, name, role)
          VALUES (1, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE username = username",
         ["tester", password_hash("x", PASSWORD_DEFAULT), "tester", "owner"]);

$plan = import_plan(import_read_csv(sheet([
    ["name_fa" => "ویرایش تست", "name_en" => "Edit Guard", "category" => "کراتین",
     "size_label" => "۳۰۰ گرم", "cost_toman" => "400000", "profit_toman" => "300000",
     "promo_toman" => "20000", "commission" => "12", "in_stock" => "بله"],
    ["name_fa" => "ویرایش تست", "name_en" => "Edit Guard", "category" => "کراتین",
     "size_label" => "۶۰۰ گرم", "cost_toman" => "700000", "profit_toman" => "500000",
     "commission" => "8", "in_stock" => "بله"],
]))["rows"]);
ok("the fixture imports", $plan["errors"] === [], implode(" | ", $plan["errors"]));
import_apply($plan["products"], $admin);

$pid = (int) db_value("SELECT id FROM products WHERE name_fa = ?", ["ویرایش تست"]);
$snap = static fn(int $id): array => db_all(
    "SELECT ext_id, label, price, cost_toman, promo_toman, commission_percent
       FROM product_sizes WHERE product_id = ? ORDER BY sort_order", [$id]);
$before = $snap($pid);
ok("the sizes carry what the sheet set",
   (int) $before[0]["cost_toman"] === 400000
   && (int) $before[0]["promo_toman"] === 20000
   && (float) $before[0]["commission_percent"] === 12.0
   && (float) $before[1]["commission_percent"] === 8.0,
   json_encode($before, JSON_UNESCAPED_UNICODE));

/* Saved through the real form, in its own process: product-edit.php ends a
   successful save with header() + exit, which would kill anything after it. */
$saver = __DIR__ . "/editsave.php";
ok("the form-save harness exists", is_file($saver), $saver);
shell_exec(escapeshellarg(PHP_BINARY) . " " . escapeshellarg($saver) . " " . $pid . " 2>&1");

$after = $snap($pid);
ok("the sizes are still there", count($after) === count($before),
   count($before) . " -> " . count($after));
ok("the toman cost survived",
   (int) ($after[0]["cost_toman"] ?? 0) === 400000
   && (int) ($after[1]["cost_toman"] ?? 0) === 700000,
   json_encode(array_column($after, "cost_toman")));
ok("the discount survived", (int) ($after[0]["promo_toman"] ?? 0) === 20000,
   json_encode(array_column($after, "promo_toman")));

echo "\n== a refused save gives the operator their work back ==\n";
/* The product editor is where someone adds three sizes and six flavours in one
   sitting. A refused save used to re-read all of that from the database, and
   json_decode the raw features textarea into an empty array - so one bad
   compare_at threw it all away while description and nutrition_serving
   survived, making the loss both silent and inconsistent.

   Own process again: the page renders a whole document, and the harness prints
   KEPT/LOST per token so the assertions here stay readable. */
$refuser = __DIR__ . "/editrefuse.php";
ok("the refused-save harness exists", is_file($refuser), $refuser);
$out = (string) shell_exec(
    escapeshellarg(PHP_BINARY) . " " . escapeshellarg($refuser) . " " . $pid . " 2>&1");

$kept = static fn(string $token): bool => strpos($out, "KEPT " . $token) !== false;

ok("the typed feature bullets come back",
   $kept("AAA-TYPED-FEATURE") && $kept("BBB-SECOND-FEATURE"), $out);
ok("the description comes back", $kept("CCC-TYPED-DESCRIPTION"));
ok("the renamed flavours come back", $kept("DDD-") && $kept("EEE-"), $out);
ok("the renamed sizes come back", $kept("FFF-"), $out);
ok("both size rows come back, not just the first", $kept("GGG-"), $out);
ok("the per-size profit typed into the form comes back", $kept("1705000"), $out);
ok("the nutrition row comes back", $kept("HHH-") && $kept("III-24 g"), $out);
ok("nothing was reported lost", strpos($out, "LOST ") === false, $out);
ok("the agreed share survived",
   (float) ($after[0]["commission_percent"] ?? 0) === 12.0
   && (float) ($after[1]["commission_percent"] ?? 0) === 8.0,
   json_encode(array_column($after, "commission_percent")));
ok("and the prices did not move",
   (int) $after[0]["price"] === (int) $before[0]["price"]
   && (int) $after[1]["price"] === (int) $before[1]["price"],
   json_encode([array_column($before, "price"), array_column($after, "price")]));

/* An order still knows the cost, which is the whole point of keeping it. */
require_once SUPPEX_ROOT . "/lib/orders.php";
$line = order_price_line(["slug" => db_value("SELECT slug FROM products WHERE id = ?", [$pid]),
                          "sizeId" => $after[1]["ext_id"], "qty" => 1]);
ok("an order after the edit still books real profit",
   $line["unit_cost"] === 700000 && $line["unit_profit"] > 0, json_encode($line));

echo "\n== a re-import touches only what the sheet spoke about ==\n";
/* The plan the shop actually has: type the catalogue into the panel, import a
   price sheet later. The sheet carries sixteen columns; the products table has
   about two dozen. Every column the sheet cannot speak about was being
   overwritten with nothing - including is_active, so a product taken off the
   shop on purpose came back because a price list was re-imported. */
db()->exec("SET FOREIGN_KEY_CHECKS = 0");
db()->exec("TRUNCATE TABLE product_sizes");
db()->exec("TRUNCATE TABLE product_flavors");
db()->exec("DELETE FROM products");
db()->exec("SET FOREIGN_KEY_CHECKS = 1");

$pid = db_insert(
    'INSERT INTO products
        (slug, name_fa, name_en, brand, price, in_stock, badges, image,
         variant_label, short, description, features, ingredients, usage_text,
         nutrition_serving, sort_order, is_active, price_mode, commission_percent)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    ["panel-whey", "پنلی تست", "Panel Whey", "ON", 2500000, 0,
     "bestseller", "/uploads/products/26/08/x.jpg", "قوطی بزرگ", "خط کوتاه",
     "متنی که یک ساعت طول کشیده", '["الف"]', "مواد", "روش مصرف",
     "هر پیمانه ۳۰ گرم", 7, 0, "manual", 9]
);
db_query('INSERT INTO product_sizes (product_id, ext_id, label, price, sort_order)
          VALUES (?, ?, ?, ?, ?)', [$pid, "900", "900 گرم", 1500000, 0]);
db_query('INSERT INTO product_sizes (product_id, ext_id, label, price, sort_order)
          VALUES (?, ?, ?, ?, ?)', [$pid, "2270", "2270 گرم", 2500000, 1]);
db_query('INSERT INTO product_flavors (product_id, ext_id, name, sort_order)
          VALUES (?, ?, ?, ?)', [$pid, "choco", "شکلاتی", 0]);

$cols = ["name_en", "brand", "badges", "image", "variant_label", "short",
         "description", "features", "ingredients", "usage_text",
         "nutrition_serving", "sort_order", "is_active"];
$snapP = static fn(int $id, array $c): array =>
    db_one("SELECT " . implode(", ", $c) . " FROM products WHERE id = ?", [$id]);
$before = $snapP($pid, $cols);

/* A sheet naming the product but not its sizes would replace those sizes with
   nothing, and say "1 product updated" while doing it. */
$read = import_read_csv(sheet([
    ["name_fa" => "پنلی تست", "cost_aed" => "40", "profit_toman" => "1500000"],
]));
$plan = import_plan($read["rows"], $read["columns"] ?? []);
ok("a sheet row with no size is refused for a product that has sizes",
   count(array_filter($plan["errors"], static fn($e) => str_contains($e, "اندازه دارد"))) === 1,
   implode(" | ", $plan["errors"]));
ok("and nothing was planned", $plan["products"] === []
   || count(array_filter($plan["errors"])) > 0);

/* Listing the sizes is the honest way to reprice. Then nothing else may move. */
$read = import_read_csv(sheet([
    ["name_fa" => "پنلی تست", "size_label" => "900 گرم",
     "cost_aed" => "40", "profit_toman" => "1500000"],
    ["name_fa" => "پنلی تست", "size_label" => "2270 گرم",
     "cost_aed" => "95", "profit_toman" => "2000000"],
]));
$plan = import_plan($read["rows"], $read["columns"] ?? []);
ok("listing the sizes parses", $plan["errors"] === [], implode(" | ", $plan["errors"]));
$res = import_apply($plan["products"], $admin);
ok("it updates rather than creating", $res["updated"] === 1, json_encode($res));

$after = $snapP($pid, $cols);
$moved = [];
foreach ($cols as $c) {
    if ((string) $before[$c] !== (string) $after[$c]) { $moved[] = $c; }
}
ok("no field the sheet is silent about moved", $moved === [], implode(", ", $moved));
ok("a hidden product stays hidden", (int) $after["is_active"] === 0,
   (string) $after["is_active"]);
ok("the description survived", $after["description"] === $before["description"]);
ok("the flavours survived",
   (int) db_value("SELECT COUNT(*) FROM product_flavors WHERE product_id = ?", [$pid]) === 1);
ok("the sizes are still two",
   (int) db_value("SELECT COUNT(*) FROM product_sizes WHERE product_id = ?", [$pid]) === 2);
$sizePrices = array_map("intval", array_column(
    db_all("SELECT price FROM product_sizes WHERE product_id = ? ORDER BY price", [$pid]),
    "price"));
ok("the sizes were repriced from the sheet", $sizePrices === [2500000, 4380000],
   json_encode($sizePrices));
ok("and the card tracks the cheapest of them",
   (int) db_value("SELECT price FROM products WHERE id = ?", [$pid]) === $sizePrices[0],
   db_value("SELECT price FROM products WHERE id = ?", [$pid]) . " vs " . $sizePrices[0]);

echo "\n== a sheet missing the stock and share columns leaves both alone ==\n";
/* Both reset by hand: the import above legitimately cleared the share,
   because that file HAD a share column and left the cell blank - which
   means "use the shop default". What is under test here is the other
   case: a file with no such column, which says nothing at all. */
db_query("UPDATE products SET in_stock = 0, commission_percent = 9 WHERE id = ?", [$pid]);
$keys = array_values(array_filter(array_keys(IMPORT_COLUMNS),
    static fn($k) => !in_array($k, ["in_stock", "commission"], true)));
$fh = fopen($tmp, "w");
fwrite($fh, chr(0xEF) . chr(0xBB) . chr(0xBF));
fputcsv($fh, array_map(static fn($k) => IMPORT_COLUMNS[$k], $keys));
foreach ([["900 گرم", "40", "1500000"], ["2270 گرم", "95", "2000000"]] as [$lbl, $aed, $prof]) {
    $v = ["name_fa" => "پنلی تست", "size_label" => $lbl,
          "cost_aed" => $aed, "profit_toman" => $prof];
    $line = [];
    foreach ($keys as $k) { $line[] = $v[$k] ?? ""; }
    fputcsv($fh, $line);
}
fclose($fh);
$read = import_read_csv($tmp);
ok("it parses without a stock column", $read["error"] === null, (string) $read["error"]);
$plan = import_plan($read["rows"], $read["columns"] ?? []);
ok("and plans", $plan["errors"] === [], implode(" | ", $plan["errors"]));
import_apply($plan["products"], $admin);
ok("an out-of-stock product is not put back on sale",
   (int) db_value("SELECT in_stock FROM products WHERE id = ?", [$pid]) === 0,
   (string) db_value("SELECT in_stock FROM products WHERE id = ?", [$pid]));

ok("the agreed share is still what the panel set",
   (float) db_value("SELECT commission_percent FROM products WHERE id = ?", [$pid]) === 9.0,
   json_encode(db_value("SELECT commission_percent FROM products WHERE id = ?", [$pid])));

echo "\n== what the adversarial review caught ==\n";
db()->exec("SET FOREIGN_KEY_CHECKS = 0");
db()->exec("TRUNCATE TABLE product_sizes");
db()->exec("TRUNCATE TABLE product_flavors");
db()->exec("TRUNCATE TABLE commission_log");
db()->exec("DELETE FROM products");
db()->exec("SET FOREIGN_KEY_CHECKS = 1");
settings_save(["aed_rate" => "25000", "price_rounding_step" => "10000",
               "commission_percent" => "20"]);

/* --- A: a market-priced row must not invent a cost --------------------- */
$plan = import_plan(import_read_csv(sheet([
    ["name_fa" => "بی‌هزینه تست", "name_en" => "No Cost", "price_toman" => "1150000"],
]))["rows"]);
ok("a price-only row is accepted", $plan["errors"] === [], implode(" | ", $plan["errors"]));
$u = $plan["products"][0]["single"];
ok("and records no cost at all", $u["cost_toman"] === null, json_encode($u["cost_toman"]));
import_apply($plan["products"], $admin);
require_once SUPPEX_ROOT . "/lib/orders.php";
$line = order_price_line(["slug" => db_value("SELECT slug FROM products WHERE name_fa = ?",
                                             ["بی‌هزینه تست"]), "qty" => 1]);
ok("so an order reports the cost as unknown, not as the price",
   $line["unit_cost"] === 0, json_encode($line["unit_cost"]));
ok("rather than claiming zero profit on a real margin",
   (int) db_value("SELECT cost_toman FROM products WHERE name_fa = ?", ["بی‌هزینه تست"]) !== 1150000,
   json_encode(db_value("SELECT cost_toman FROM products WHERE name_fa = ?", ["بی‌هزینه تست"])));

/* --- B: a discount needs something to come off ------------------------- */
$plan = import_plan(import_read_csv(sheet([
    ["name_fa" => "تخفیف بی‌پایه", "price_toman" => "1150000", "promo_toman" => "50000"],
]))["rows"]);
ok("a discount with no cost behind it is refused",
   count(array_filter($plan["errors"], static fn($e) => str_contains($e, "برای تخفیف"))) === 1,
   implode(" | ", $plan["errors"]));

/* With a cost, the same discount is honoured. */
$plan = import_plan(import_read_csv(sheet([
    ["name_fa" => "تخفیف باپایه", "cost_toman" => "820000",
     "price_toman" => "1150000", "promo_toman" => "50000"],
]))["rows"]);
ok("with a cost it is accepted", $plan["errors"] === [], implode(" | ", $plan["errors"]));
$u = $plan["products"][0]["single"];
ok("and actually comes off the price", (int) $u["price"] === 1100000, (string) $u["price"]);

/* --- C: a dirham catalogue imported before the rate is set ------------- */
settings_save(["aed_rate" => ""]);
$plan = import_plan(import_read_csv(sheet([
    ["name_fa" => "بی‌نرخ تست", "name_en" => "No Rate", "size_label" => "۹۰۰ گرم",
     "cost_aed" => "40", "profit_toman" => "1500000"],
    ["name_fa" => "بی‌نرخ تست", "name_en" => "No Rate", "size_label" => "۲۲۷۰ گرم",
     "cost_aed" => "95", "profit_toman" => "2000000"],
]))["rows"]);
ok("it imports, with a warning", $plan["errors"] === [] && $plan["warnings"] !== [],
   implode(" | ", $plan["errors"]));
import_apply($plan["products"], $admin);
$row = db_one("SELECT id, price_mode FROM products WHERE name_fa = ?", ["بی‌نرخ تست"]);
ok("and is marked dirham-linked despite having no price yet",
   $row["price_mode"] === "aed", $row["price_mode"]);
settings_save(["aed_rate" => "25000"]);
require_once SUPPEX_ROOT . "/lib/pricing.php";
$units = pricing_units(25000.0);
ok("so the pricing page can see it once the rate arrives",
   count(array_filter($units, static fn($x) => $x["product_id"] === (int) $row["id"])) === 2,
   (string) count($units));

/* --- D: a sheet with no share column must not clear per-size rates ----- */
$plan = import_plan(import_read_csv(sheet([
    ["name_fa" => "نرخ سایز", "name_en" => "Size Rates", "size_label" => "۹۰۰ گرم",
     "cost_toman" => "400000", "profit_toman" => "300000", "commission" => "12"],
    ["name_fa" => "نرخ سایز", "name_en" => "Size Rates", "size_label" => "۲۲۷۰ گرم",
     "cost_toman" => "700000", "profit_toman" => "500000", "commission" => "8"],
]))["rows"]);
import_apply($plan["products"], $admin);
$pid = (int) db_value("SELECT id FROM products WHERE name_fa = ?", ["نرخ سایز"]);
$rates = static fn(int $id): array => array_map("floatval", array_column(
    db_all("SELECT commission_percent FROM product_sizes
             WHERE product_id = ? ORDER BY sort_order", [$id]), "commission_percent"));
ok("the sizes carry their agreed rates", $rates($pid) === [12.0, 8.0],
   json_encode($rates($pid)));

/* The same catalogue on the sheet that has no share column at all. */
$keys = array_values(array_filter(array_keys(IMPORT_COLUMNS),
    static fn($k) => $k !== "commission"));
$fh = fopen($tmp, "w");
fwrite($fh, chr(0xEF) . chr(0xBB) . chr(0xBF));
fputcsv($fh, array_map(static fn($k) => IMPORT_COLUMNS[$k], $keys));
foreach ([["۹۰۰ گرم", "410000", "300000"], ["۲۲۷۰ گرم", "710000", "500000"]] as [$lbl, $cost, $prof]) {
    $v = ["name_fa" => "نرخ سایز", "size_label" => $lbl,
          "cost_toman" => $cost, "profit_toman" => $prof];
    $line = [];
    foreach ($keys as $k) { $line[] = $v[$k] ?? ""; }
    fputcsv($fh, $line);
}
fclose($fh);
$read = import_read_csv($tmp);
$plan = import_plan($read["rows"], $read["columns"] ?? []);
ok("the share-less sheet parses", $plan["errors"] === [], implode(" | ", $plan["errors"]));
$logBefore = (int) db_value("SELECT COUNT(*) FROM commission_log");
import_apply($plan["products"], $admin);
ok("the per-size rates survived a sheet that never mentioned them",
   $rates($pid) === [12.0, 8.0], json_encode($rates($pid)));
ok("and the costs did update", (int) db_value(
   "SELECT cost_toman FROM product_sizes WHERE product_id = ? ORDER BY sort_order LIMIT 1",
   [$pid]) === 410000);
ok("with nothing written to the rate log",
   (int) db_value("SELECT COUNT(*) FROM commission_log") === $logBefore,
   $logBefore . " -> " . db_value("SELECT COUNT(*) FROM commission_log"));

/* --- F: a blank stock cell means available ----------------------------- */
ok("a blank stock cell is not a no", import_truthy("") === true);
ok("but an explicit no still is", import_truthy("خیر") === false);
ok("and so is zero", import_truthy("0") === false);
$plan = import_plan(import_read_csv(sheet([
    ["name_fa" => "موجودی خالی", "cost_toman" => "500000", "profit_toman" => "400000"],
]))["rows"]);
import_apply($plan["products"], $admin);
ok("a new product with a blank stock cell is on sale",
   (int) db_value("SELECT in_stock FROM products WHERE name_fa = ?", ["موجودی خالی"]) === 1,
   (string) db_value("SELECT in_stock FROM products WHERE name_fa = ?", ["موجودی خالی"]));

echo "\n== a size keeps the id a cart already stored ==\n";
/* ext_id is what the cart holds. Regenerating it from the label on every
   import detaches every basket holding that size: order_price_line() finds no
   matching row and prices from the parent, so a 2270g tub quietly becomes a
   900g one at checkout. */
db()->exec("SET FOREIGN_KEY_CHECKS = 0");
db()->exec("TRUNCATE TABLE product_sizes");
db()->exec("TRUNCATE TABLE product_flavors");
db()->exec("DELETE FROM products");
db()->exec("SET FOREIGN_KEY_CHECKS = 1");

/* Sizes as the panel makes them: a short numeric id, not a slug of the label. */
$pid = db_insert('INSERT INTO products (slug, name_fa, price, in_stock, is_active, price_mode)
                  VALUES (?, ?, ?, 1, 1, "manual")', ["cart-guard", "سبد تست", 2450000]);
foreach ([["900", "900 گرم", 2450000], ["2270", "2270 گرم", 5490000]] as $i => [$ext, $label, $price]) {
    db_query('INSERT INTO product_sizes (product_id, ext_id, label, price, sort_order)
              VALUES (?, ?, ?, ?, ?)', [$pid, $ext, $label, $price, $i]);
}
$idsBefore = array_column(db_all("SELECT ext_id FROM product_sizes WHERE product_id = ?
                                  ORDER BY sort_order", [$pid]), "ext_id");
ok("the panel's ids are short", $idsBefore === ["900", "2270"], json_encode($idsBefore));

/* What a shopper has in their basket right now. */
$inCart = order_price_line(["slug" => "cart-guard", "sizeId" => "2270", "qty" => 1]);
ok("the big tub prices at its own price", $inCart["unit_price"] === 5490000,
   json_encode($inCart["unit_price"]));

/* A price sheet for the same product, naming the sizes by label. */
$read = import_read_csv(sheet([
    ["name_fa" => "سبد تست", "size_label" => "900 گرم",
     "cost_toman" => "1200000", "profit_toman" => "1250000"],
    ["name_fa" => "سبد تست", "size_label" => "2270 گرم",
     "cost_toman" => "2900000", "profit_toman" => "2590000"],
]));
$plan = import_plan($read["rows"], $read["columns"] ?? []);
ok("the sheet parses", $plan["errors"] === [], implode(" | ", $plan["errors"]));
import_apply($plan["products"], $admin);

$idsAfter = array_column(db_all("SELECT ext_id FROM product_sizes WHERE product_id = ?
                                 ORDER BY sort_order", [$pid]), "ext_id");
ok("the ids did not change", $idsAfter === $idsBefore, json_encode($idsAfter));

$stillThere = order_price_line(["slug" => "cart-guard", "sizeId" => "2270", "qty" => 1]);
ok("the basket still resolves to the big tub",
   $stillThere["unit_price"] === 5490000, json_encode($stillThere["unit_price"]));
ok("and not to the parent's price",
   $stillThere["unit_price"] !== (int) db_value("SELECT price FROM products WHERE id = ?", [$pid])
   || $stillThere["unit_price"] === 5490000);

/* A size the sheet introduces still gets a generated id. */
$read = import_read_csv(sheet([
    ["name_fa" => "سبد تست", "size_label" => "900 گرم",
     "cost_toman" => "1200000", "profit_toman" => "1250000"],
    ["name_fa" => "سبد تست", "size_label" => "2270 گرم",
     "cost_toman" => "2900000", "profit_toman" => "2590000"],
    ["name_fa" => "سبد تست", "size_label" => "4540 گرم",
     "cost_toman" => "5600000", "profit_toman" => "4400000"],
]));
$plan = import_plan($read["rows"], $read["columns"] ?? []);
import_apply($plan["products"], $admin);
$ids = array_column(db_all("SELECT ext_id, label FROM product_sizes WHERE product_id = ?
                            ORDER BY sort_order", [$pid]), "ext_id");
ok("the two old ids survived and the new one was generated",
   $ids[0] === "900" && $ids[1] === "2270" && $ids[2] !== "" && $ids[2] !== "900",
   json_encode($ids, JSON_UNESCAPED_UNICODE));

echo "\n== one file, two passes ==\n";
/* The catalogue is typed in by one person with the share column left empty,
   then the same file comes back with the shares agreed and is imported again.
   The second pass must change the shares and nothing else: same products,
   same ids, same prices, same flavours. A re-import that quietly renumbered
   products would break every link already handed out. */
db()->exec("SET FOREIGN_KEY_CHECKS = 0");
db()->exec("TRUNCATE TABLE product_sizes");
db()->exec("TRUNCATE TABLE product_flavors");
db()->exec("DELETE FROM products");
db()->exec("SET FOREIGN_KEY_CHECKS = 1");

$catalogue = static fn(?string $share): array => [
    ["name_fa" => "دو مرحله وی", "name_en" => "Two Pass Whey", "category" => "پروتئین",
     "size_label" => "۹۰۰ گرم",  "cost_aed" => "40", "profit_toman" => "1500000",
     "commission" => $share, "flavors" => "شکلاتی، وانیلی", "in_stock" => "بله"],
    ["name_fa" => "دو مرحله وی", "name_en" => "Two Pass Whey", "category" => "پروتئین",
     "size_label" => "۲۲۷۰ گرم", "cost_aed" => "95", "profit_toman" => "2000000",
     "commission" => $share, "in_stock" => "بله"],
];

/* Pass one: the share column is untouched. */
$plan = import_plan(import_read_csv(sheet($catalogue(null)))["rows"]);
ok("pass one parses", $plan["errors"] === [], implode(" | ", $plan["errors"]));
$r1 = import_apply($plan["products"], ["id" => 1, "name" => "data entry"]);
ok("pass one creates the product", $r1["created"] === 1, json_encode($r1));
ok("and logs no rate at all", $r1["rates"] === 0, json_encode($r1));

$before = db_one("SELECT id, slug, price FROM products WHERE name_fa = ?", ["دو مرحله وی"]);
$flavoursBefore = (int) db_value("SELECT COUNT(*) FROM product_flavors WHERE product_id = ?",
                                 [(int) $before["id"]]);
ok("the share falls back to the shop rate",
   db_value("SELECT commission_percent FROM products WHERE id = ?", [(int) $before["id"]]) === null);

/* Pass two: the same rows, now carrying a share. */
$plan = import_plan(import_read_csv(sheet($catalogue("14")))["rows"]);
ok("pass two parses", $plan["errors"] === [], implode(" | ", $plan["errors"]));
$r2 = import_apply($plan["products"], ["id" => 1, "name" => "owner"]);
ok("pass two updates rather than creating",
   $r2["created"] === 0 && $r2["updated"] === 1, json_encode($r2));
ok("and records the rate once", $r2["rates"] === 1, json_encode($r2));

$after = db_one("SELECT id, slug, price, commission_percent FROM products WHERE name_fa = ?",
                ["دو مرحله وی"]);
ok("same product id", (int) $after["id"] === (int) $before["id"],
   $before["id"] . " -> " . $after["id"]);
ok("same slug", $after["slug"] === $before["slug"], $before["slug"] . " -> " . $after["slug"]);
ok("same price", (int) $after["price"] === (int) $before["price"],
   $before["price"] . " -> " . $after["price"]);
ok("the share is now set", (float) $after["commission_percent"] === 14.0,
   json_encode($after["commission_percent"]));
ok("flavours survived the second pass",
   (int) db_value("SELECT COUNT(*) FROM product_flavors WHERE product_id = ?",
                  [(int) $after["id"]]) === $flavoursBefore,
   $flavoursBefore . " -> " . db_value("SELECT COUNT(*) FROM product_flavors WHERE product_id = ?",
                                       [(int) $after["id"]]));
ok("both sizes carry it",
   (int) db_value("SELECT COUNT(*) FROM product_sizes
                    WHERE product_id = ? AND commission_percent = 14", [(int) $after["id"]]) === 2);

$log = db_one("SELECT scope, label, old_percent, new_percent, admin_name
                 FROM commission_log ORDER BY id DESC LIMIT 1");
ok("the log says it went from the default", $log["old_percent"] === null, json_encode($log));
ok("to fourteen", (float) $log["new_percent"] === 14.0, json_encode($log));
ok("and names who did it", $log["admin_name"] === "owner", json_encode($log));

echo "\n== a sheet with no share column at all ==\n";
/* The sheet handed to whoever is typing in the catalogue omits the commission
   column: the share is a term between two parties, not a product attribute.
   Everything must therefore inherit the shop rate, and nothing may reach the
   commission log \u2014 a log entry claiming a rate was set here would be a
   record of a decision nobody made. */
$cols = IMPORT_COLUMNS;
unset($cols["commission"]);
$fh = fopen($tmp, "w");
fwrite($fh, chr(0xEF) . chr(0xBB) . chr(0xBF));
fputcsv($fh, array_values($cols));
$before = (int) db_value("SELECT COUNT(*) FROM commission_log");
foreach ([["۳۰۰ گرم", "34", "700000"], ["۶۰۰ گرم", "60", "1100000"]] as [$label, $aed, $profit]) {
    $line = [];
    foreach (array_keys($cols) as $k) {
        $line[] = [
            "name_fa" => "بدون سهم تست", "name_en" => "No Share", "brand" => "ON",
            "category" => "کراتین", "size_label" => $label,
            "cost_aed" => $aed, "profit_toman" => $profit, "in_stock" => "بله",
        ][$k] ?? "";
    }
    fputcsv($fh, $line);
}
fclose($fh);

$r = import_read_csv($tmp);
ok("parses without the share column", $r["error"] === null, (string) $r["error"]);
$plan = import_plan($r["rows"]);
ok("no errors", $plan["errors"] === [], implode(" | ", $plan["errors"]));
ok("every unit inherits",
   count(array_filter($plan["products"][0]["sizes"],
        static fn($u) => $u["commission"] === null)) === 2,
   json_encode(array_column($plan["products"][0]["sizes"], "commission")));
$res = import_apply($plan["products"], $admin);
ok("applied", $res["created"] === 1, json_encode($res));
ok("no rate was recorded", ($res["rates"] ?? -1) === 0, json_encode($res));
ok("and the commission log did not grow",
   (int) db_value("SELECT COUNT(*) FROM commission_log") === $before,
   $before . " -> " . db_value("SELECT COUNT(*) FROM commission_log"));
$row = db_one("SELECT id, commission_percent FROM products WHERE name_fa = ?", ["بدون سهم تست"]);
ok("the product falls back to the shop rate", $row["commission_percent"] === null,
   json_encode($row["commission_percent"]));
ok("and so does every size",
   (int) db_value("SELECT COUNT(*) FROM product_sizes
                    WHERE product_id = ? AND commission_percent IS NOT NULL",
                  [(int) $row["id"]]) === 0);
echo "\n== the column that feeds the card's hover panel ==\n";
/* The panel shows «۲۴ گرم پروتئین در هر پیمانه» because the seeded catalogue
   has features. The client's sheet had no column for them, so on the real
   catalogue the panel would have shown sizes and flavours and never the number
   the shopper came for. */
ok("the sheet has a column for it", isset(IMPORT_COLUMNS["features"]),
   implode(", ", array_keys(IMPORT_COLUMNS)));
ok("and leaving it blank is allowed", in_array("features", IMPORT_OPTIONAL, true));

/* One per line is what the panel's own editor takes, so the same text typed in
   either place means the same thing. */
ok("line breaks separate features",
   import_features("خط اول\nخط دوم") === ["خط اول", "خط دوم"],
   json_encode(import_features("خط اول\nخط دوم"), JSON_UNESCAPED_UNICODE));
ok("so does a semicolon, which is easier to type in one cell",
   count(import_features("۲۴ گرم پروتئین؛ بدون قند افزوده")) === 2);
/* The one that matters. A feature is a sentence and Persian sentences are full
   of commas; splitting on «،» would cut them in half. */
ok("but «،» does not - it belongs inside a sentence",
   import_features("ترکیب کنسانتره و ایزوله، با فیلتراسیون سرد")
     === ["ترکیب کنسانتره و ایزوله، با فیلتراسیون سرد"],
   json_encode(import_features("ترکیب کنسانتره و ایزوله، با فیلتراسیون سرد"),
               JSON_UNESCAPED_UNICODE));
ok("blank lines are not printed as empty bullets",
   import_features("الف\n\n\nب") === ["الف", "ب"]);
ok("nor are duplicates", import_features("الف؛الف؛ب") === ["الف", "ب"]);
ok("an empty cell yields nothing at all", import_features("") === []);

db()->exec("SET FOREIGN_KEY_CHECKS = 0");
db()->exec("TRUNCATE TABLE product_sizes");
db()->exec("TRUNCATE TABLE product_flavors");
db()->exec("DELETE FROM products");
db()->exec("SET FOREIGN_KEY_CHECKS = 1");

$read = import_read_csv(sheet([
    ["name_fa" => "پنل تست", "cost_toman" => "800000", "profit_toman" => "700000",
     "features" => "۲۴ گرم پروتئین در هر پیمانه\nبدون قند افزوده، بدون رنگ مصنوعی"],
]));
$plan = import_plan($read["rows"], $read["columns"] ?? []);
import_apply($plan["products"], $admin);
$saved = json_decode_array(db_value("SELECT features FROM products WHERE name_fa = ?",
                                    ["پنل تست"]));
ok("features typed in the sheet reach the product", count($saved) === 2,
   json_encode($saved, JSON_UNESCAPED_UNICODE));
ok("in the order they were written", $saved[0] === "۲۴ گرم پروتئین در هر پیمانه");
ok("with the comma still inside the second one",
   $saved[1] === "بدون قند افزوده، بدون رنگ مصنوعی", $saved[1] ?? "");

/* A product typed into the panel, then priced from a sheet whose author had no
   opinion about features: the panel's text must survive. This is the working
   pattern - one person types the catalogue, another agrees the prices. */
$read = import_read_csv(sheet([
    ["name_fa" => "پنل تست", "cost_toman" => "900000", "profit_toman" => "700000"],
]));
$plan = import_plan($read["rows"], $read["columns"] ?? []);
import_apply($plan["products"], $admin);
ok("a blank features cell erases nothing",
   count(json_decode_array(db_value("SELECT features FROM products WHERE name_fa = ?",
                                    ["پنل تست"]))) === 2);

/* And the export has to be able to hand them back. */
$csv = backup_catalogue_csv();
$round = sys_get_temp_dir() . "/suppex-features-round.csv";
file_put_contents($round, $csv);
$back = import_read_csv($round);
ok("the export carries the column", in_array("features", $back["columns"], true),
   implode(", ", $back["columns"]));
$cell = "";
foreach ($back["rows"] as $r) {
    if ($r["name_fa"] === "پنل تست") { $cell = $r["features"] ?? ""; break; }
}
ok("and the cell reads back as the same two features",
   import_features($cell) === $saved, $cell);
@unlink($round);

/* This suite empties the products table, and the SEO suite reads the seeded
   catalogue. Restore what was destroyed rather than leaving the next suite to
   discover it. */
require_once SUPPEX_ROOT . '/db/seed.php';
db()->exec('SET FOREIGN_KEY_CHECKS = 0');
db()->exec('TRUNCATE TABLE product_sizes');
db()->exec('DELETE FROM products');
db()->exec('SET FOREIGN_KEY_CHECKS = 1');
suppex_seed();
ok('catalogue restored for the next suite',
   (int) db_value('SELECT COUNT(*) FROM products') === 15,
   (string) db_value('SELECT COUNT(*) FROM products'));

@unlink($tmp);
echo "\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
