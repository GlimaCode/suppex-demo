<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP.
   bootstrap falls back to the real suppex-config.php when SUPPEX_CONFIG
   is unset - which is exactly what a web request has - so one GET to an
   uploaded tests/ folder could empty the live database. The .htaccess
   beside this file is process; this is code. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* ===========================================================================
   Fresh-install test.
   ---------------------------------------------------------------------------
   Every other suite runs against a database that development BUILT, one ALTER
   at a time over weeks. The client gets a database that db/schema.sql plus
   admin/migrate.php CREATE, in one go, on an empty MySQL. Those are not the
   same database, and the difference stays invisible until launch day.

   So this suite throws the database away and rebuilds it the way the client
   will, then runs the features that matter through it. It was written after
   products.promo_toman and the entire product_sizes pricing block turned out
   to be missing from the migration while 317 other checks were passing.
   =========================================================================== */

$_SERVER["REMOTE_ADDR"]    = "127.0.0.1";
$_SERVER["HTTP_HOST"]      = "suppex.ir";
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REQUEST_URI"]    = "/admin/migrate.php";
$_SERVER["SCRIPT_NAME"]    = "/admin/migrate.php";

$pass = 0; $fail = 0; $out = [];
function ok(string $l, bool $c, ?string $x = ""): void {
    global $pass, $fail, $out;
    if ($c) { $pass++; $out[] = "  ok   $l"; }
    else { $fail++; $out[] = "  FAIL $l" . ($x ? "  --  $x" : ""); }
}
function head(string $t): void { global $out; $out[] = "\n== $t =="; }

/* --- Rebuild from nothing, before a byte of output ----------------------- */
$pdo = new PDO("mysql:host=127.0.0.1;port=" . (getenv("SUPPEX_DB_PORT") ?: "3399") . ";charset=utf8mb4", "root", "",
               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("DROP DATABASE IF EXISTS suppex_fresh");
$pdo->exec("CREATE DATABASE suppex_fresh DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . "/lib/auth.php";
require_once SUPPEX_ROOT . "/lib/pricing.php";
require_once SUPPEX_ROOT . "/lib/import.php";
require_once SUPPEX_ROOT . "/lib/orders.php";

head("db/schema.sql loads on an empty database");
$n = 0; $schemaErr = "";
try { $n = db_run_sql_file(SUPPEX_ROOT . "/db/schema.sql"); }
catch (Throwable $e) { $schemaErr = $e->getMessage(); }
ok("schema.sql runs", $n > 10, $schemaErr ?: "$n statements");

/* The gap that made a brand new shop unable to take money: schema.sql is the
   shape this project started with, and everything the dirham pricing and the
   profit snapshot added since arrived as migrations. setup.php used to run the
   schema and stop, so order_create() died on the first customer with "Unknown
   column 'profit_total'" inside a transaction - no row, no notification.

   This asserts the shape a fresh install actually needs, straight after the
   schema and before any migration has run, so the assertion below has meaning. */
$orderCols = static fn(): array => array_column(
    db_all("SHOW COLUMNS FROM orders"), "Field");
ok("straight from schema.sql, orders has no profit_total",
   !in_array("profit_total", $orderCols(), true),
   "if this fails, schema.sql has caught up and the migration below is moot");

require_once SUPPEX_ROOT . "/lib/migrate.php";
$freshLog = [];
migrate_run($freshLog);
$applied = count(array_filter($freshLog, static fn(array $l): bool => $l[0] === "done"));
$failed  = array_filter($freshLog, static fn(array $l): bool => $l[0] === "fail");
ok("migrate_run() applies the missing steps on a fresh schema", $applied >= 10, (string) $applied);
ok("and none of them fail", $failed === [],
   implode(" | ", array_map(static fn(array $l): string => $l[1], $failed)));

/* The exact INSERT lib/orders.php performs. This is the check that would have
   caught the whole thing: it fails on schema.sql alone and passes after the
   migrations, which is precisely the difference setup.php was missing. */
$orderErr = "";
try {
    db_query("INSERT INTO orders (code, customer_name, phone, address, subtotal,
                                  shipping, total, profit_total, commission_basis,
                                  rate_at_order, status)
              VALUES (?,?,?,?,?,?,?,?,?,?,?)",
             ["SPX-FRESHTEST", "t", "09120000000", "a", 1, 0, 1, 0, "profit", 30000, "new"]);
} catch (Throwable $e) { $orderErr = $e->getMessage(); }
ok("a fresh install can record an order", $orderErr === "", $orderErr);
db_query("DELETE FROM orders WHERE code = ?", ["SPX-FRESHTEST"]);

db_query("INSERT INTO admins (id, username, password_hash, name, role)
          VALUES (1, ?, ?, ?, ?)", ["tester", password_hash("x", PASSWORD_DEFAULT), "tester", "support"]);
auth_start_session();
$_SESSION["admin_id"] = 1; $_SESSION["admin_name"] = "tester"; $_SESSION["admin_role"] = "support";
require_once __DIR__ . '/admin-session.php'; test_admin_session();
$_SESSION['csrf'] = 'migration-test'; $_POST['_csrf'] = 'migration-test';

head("admin/migrate.php completes in ONE run");
ob_start();
include SUPPEX_ROOT . "/admin/migrate.php";
ob_get_clean();

$failed = array_values(array_filter($log, static fn($e) => $e[0] === "fail"));
ok("no step failed", $failed === [],
   implode(" | ", array_map(static fn($e) => $e[1], $failed)));
ok("nothing left pending", $allDone,
   implode(", ", array_keys(array_filter($pending, static fn($v) => !$v))));

head("the schema supports what the code queries");
$need = [
    "products"      => ["cost_aed", "profit_toman", "promo_toman", "price_mode",
                        "cost_toman", "price_applied_rate", "price_applied_at",
                        "commission_percent", "stock_qty"],
    "product_sizes" => ["cost_aed", "profit_toman", "promo_toman", "cost_toman",
                        "price_applied_rate", "price_applied_at", "commission_percent",
                        "stock_qty"],
    "orders"        => ["expires_at", "profit_total", "commission_basis",
                        "commission_percent", "commission_amount", "rate_at_order",
                        "items_hash"],
    "order_items"   => ["unit_cost", "unit_profit", "line_profit",
                        "commission_percent", "commission_amount",
                        "size_ext", "stock_held", "stock_from"],
];
foreach ($need as $table => $cols) {
    $have = array_column(db_all("SHOW COLUMNS FROM " . $table), "Field");
    $missing = array_values(array_diff($cols, $have));
    ok("$table has every pricing column", $missing === [], implode(", ", $missing));
}
/* A count must default to NULL — "nobody has counted this" — and not to 0.
   Defaulting to zero would take an entire freshly installed catalogue off sale
   the moment the migration ran, which is the loudest possible way to get this
   wrong and the easiest one to type. */
foreach ([["products", "stock_qty"], ["product_sizes", "stock_qty"]] as [$t, $c]) {
    /* information_schema, not SHOW COLUMNS ... LIKE ?: the LIKE pattern of a
       SHOW statement cannot be a bound parameter. */
    $def = db_one("SELECT IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                  [$t, $c]);
    /* MariaDB reports a NULL default as the four-character string "NULL" and
       an absent default as SQL NULL. Both mean "no number here"; only a 0
       would be the mistake this is watching for. */
    $default = $def["COLUMN_DEFAULT"] ?? null;
    ok("$t.$c defaults to uncounted, not to zero",
       $def !== null && $def["IS_NULLABLE"] === "YES"
       && ($default === null || strtoupper((string) $default) === "NULL"),
       json_encode($def));
}

foreach (["rate_history", "commission_log", "order_events", "product_sizes",
          "product_flavors", "product_images"] as $t) {
    ok("table $t exists",
       db_value("SELECT 1 FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$t]) !== null);
}

head("and the features actually run on it");
settings_save(["aed_rate" => "25000", "commission_percent" => "10",
               "card_number" => "6104337600000002", "card_holder" => "tester",
               "shipping_flat_rate" => "0", "free_shipping_over" => "0"]);

/* Importing the sheet is the client-s real first act on this database. */
$csv = sys_get_temp_dir() . "/suppex-fresh-import.csv";
file_put_contents($csv, import_template_csv());
$read = import_read_csv($csv);
ok("the template imports into a fresh database", $read["error"] === null, (string) $read["error"]);
$plan = import_plan($read["rows"]);
ok("with no errors", $plan["errors"] === [], implode(" | ", $plan["errors"]));
$res = import_apply($plan["products"], ["id" => 1, "name" => "tester"]);
ok("products created", $res["created"] > 0, json_encode($res));
ok("sizes created", $res["sizes"] > 0, json_encode($res));

/* Pricing must see those size rows as buyable units. The bug this suite
   exists for left pricing blind to every variant. */
$units = pricing_units(25000.0);
ok("pricing sees the size rows",
   count(array_filter($units, static fn($u) => ($u["kind"] ?? "") === "size")) > 0,
   (string) count($units));
$preview = pricing_preview(25000.0);
ok("preview prices every unit", count($preview) === count($units),
   count($preview) . " of " . count($units));

/* And an order has to land, with a commission line, on the fresh schema. */
$p = db_one("SELECT id, slug FROM products WHERE price > 0 AND
             (SELECT COUNT(*) FROM product_sizes s WHERE s.product_id = products.id) = 0
             LIMIT 1");
ok("an imported product is orderable", $p !== null);
if ($p !== null) {
    $r = order_create([
        "items"    => [["slug" => $p["slug"], "qty" => 1]],
        "customer" => ["name" => "\u0639\u0644\u06cc \u0631\u0636\u0627\u06cc\u06cc",
                       "phone" => "09123456789",
                       "address" => "\u062a\u0647\u0631\u0627\u0646\u060c \u062e\u06cc\u0627\u0628\u0627\u0646 \u0648\u0644\u06cc\u0639\u0635\u0631\u060c \u067e\u0644\u0627\u06a9 \u06f2\u06f4\u06f1",
                       "postal" => "1967713111"],
        "channel"  => "telegram",
    ]);
    ok("an order can be placed", ($r["ok"] ?? false) === true,
       json_encode($r["errors"] ?? [], JSON_UNESCAPED_UNICODE));
    if ($r["ok"] ?? false) {
        $line = db_one("SELECT commission_percent, commission_amount
                          FROM order_items WHERE order_id = ?", [(int) $r["order"]["id"]]);
        ok("the line carries the shop commission rate",
           $line !== null && (float) $line["commission_percent"] === 10.0,
           json_encode($line));
        ok("and a commission amount was computed",
           $line !== null && (int) $line["commission_amount"] > 0, json_encode($line));
    }
}

head("running it a second time changes nothing");
/* In its own process: including migrate.php twice redeclares its helpers. */
$before = count(db_all("SHOW COLUMNS FROM products"));
$raw = shell_exec(escapeshellarg(PHP_BINARY) . " " . escapeshellarg(__DIR__ . "/migrate-once.php"));
$second = json_decode((string) $raw, true);
ok("the second run reports back", is_array($second), trim((string) $raw));
if (is_array($second)) {
    ok("every step skipped",
       count(array_filter($second["states"], static fn($s) => $s !== "skip")) === 0,
       implode(" | ", $second["labels"]));
    ok("no column added", (int) $second["columns"] === $before,
       $before . " -> " . $second["columns"]);
    ok("still reports done", $second["allDone"] === true);
}


/* --- category artwork ---------------------------------------------------
   The seed inserts the six categories WITH their pictures, but its
   already-done guard is «are there any categories at all» - so a shop set up
   through setup.php skipped it and got six rows with an empty image column.
   The storefront drew six black rectangles and there was no way to tell from
   the panel that anything was missing. */
require_once SUPPEX_ROOT . '/lib/products.php';

$catBefore = [];
foreach (db_all('SELECT slug, image FROM categories') as $c) {
    $catBefore[(string) $c['slug']] = (string) ($c['image'] ?? '');
}

db_query("UPDATE categories SET image = ''");
/* A category the owner has already photographed must not be overwritten. */
db_query("UPDATE categories SET image = 'uploads/products/mine.webp' WHERE slug = 'creatine'");

ok('an empty image column reads as an unfinished migration',
   migrate_pending()['categories.image'] === false);

$catLog = [];
migrate_run($catLog);

ok('and it is finished after the run',
   migrate_pending()['categories.image'] === true);

$filled = [];
foreach (db_all('SELECT slug, image FROM categories') as $c) {
    $filled[(string) $c['slug']] = (string) ($c['image'] ?? '');
}

foreach (['protein', 'mass-gainer', 'pre-workout', 'amino', 'vitamins'] as $slug) {
    ok('  ' . $slug . ' got its bundled artwork',
       ($filled[$slug] ?? '') === 'assets/images/categories/' . $slug . '.svg',
       $filled[$slug] ?? 'MISSING');
    ok('    and the file is really there',
       is_file(SUPPEX_ROOT . '/' . ($filled[$slug] ?? 'nope')));
}

ok('the owner\'s own photo is left alone',
   ($filled['creatine'] ?? '') === 'uploads/products/mine.webp',
   $filled['creatine'] ?? 'MISSING');

/* What the storefront actually hands the card renderer. */
$catApi = [];
foreach (categories_list() as $c) { $catApi[$c['id']] = (string) ($c['image'] ?? ''); }
ok('no category reaches the storefront without a picture',
   count(array_filter($catApi, static fn($v) => $v === '')) === 0,
   implode(', ', array_keys(array_filter($catApi, static fn($v) => $v === ''))));

/* Running it again must be a no-op, not a re-write. */
$catLog2 = [];
migrate_run($catLog2);
ok('a second run changes nothing',
   (string) db_value("SELECT image FROM categories WHERE slug = 'creatine'")
   === 'uploads/products/mine.webp');

foreach ($catBefore as $slug => $img) {
    db_query('UPDATE categories SET image = ? WHERE slug = ?', [$img, $slug]);
}
ok('category fixtures restored', true);

/* --- category artwork on a shop with Persian slugs ----------------------
   setup.php seeds slugs like 'protein'; a catalogue import slugifies the
   Persian name instead and produces 'پروتئین'. Both are real, and name_latin
   cannot arbitrate - on the live shop only one of the six rows has it. A
   backfill keyed to the Latin slug alone runs, reports done, and leaves every
   category card black. */
$catKeep = db_all('SELECT id, slug, name_latin, image FROM categories');

$catLive = [
    'پروتئین'        => 'پروتئین',
    'کراتین'         => 'کراتین',
    'افزایش-وزن'     => 'افزایش وزن',
    'قبل-تمرین'      => 'قبل تمرین',
    'آمینو-و-bcaa'   => 'آمینو و BCAA',
    'ویتامین-و-مکمل' => 'ویتامین و مکمل',
];
foreach ($catLive as $slug => $nameFa) {
    db_query("UPDATE categories SET slug = ?, name_latin = '', image = '' WHERE name_fa = ?",
             [$slug, $nameFa]);
}

ok('a persian-slug shop reads as unfinished',
   migrate_pending()['categories.image'] === false);

$catLog3 = [];
migrate_run($catLog3);

ok('  and finished after the run',
   migrate_pending()['categories.image'] === true);

$catNow = [];
foreach (db_all('SELECT slug, image FROM categories') as $c) {
    $catNow[(string) $c['slug']] = (string) ($c['image'] ?? '');
}
foreach ([
    'پروتئین'        => 'protein',
    'کراتین'         => 'creatine',
    'افزایش-وزن'     => 'mass-gainer',
    'قبل-تمرین'      => 'pre-workout',
    'آمینو-و-bcaa'   => 'amino',
    'ویتامین-و-مکمل' => 'vitamins',
] as $slug => $file) {
    ok('  ' . $slug . ' matched by its persian name',
       ($catNow[$slug] ?? '') === 'assets/images/categories/' . $file . '.svg',
       $catNow[$slug] ?? 'MISSING');
}

foreach ($catKeep as $k) {
    db_query('UPDATE categories SET slug = ?, name_latin = ?, image = ? WHERE id = ?',
             [$k['slug'], $k['name_latin'], $k['image'], $k['id']]);
}
ok('persian-slug fixtures restored',
   (int) db_value("SELECT COUNT(*) FROM categories WHERE slug = 'protein'") === 1);
echo implode("\n", $out), "\n";
echo "\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);