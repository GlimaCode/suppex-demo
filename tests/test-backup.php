<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP.
   bootstrap falls back to the real suppex-config.php when SUPPEX_CONFIG
   is unset - which is exactly what a web request has - so one GET to an
   uploaded tests/ folder could empty the live database. The .htaccess
   beside this file is process; this is code. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* ===========================================================================
   Backup and export.
   ---------------------------------------------------------------------------
   The only claim a backup makes is that it can put things back. So this suite
   does not check that the files look right - it destroys the catalogue and
   rebuilds it from the export, and destroys the whole database and rebuilds it
   from the dump, then compares.
   =========================================================================== */

$_SERVER["REMOTE_ADDR"]    = "127.0.0.1";
$_SERVER["HTTP_HOST"]      = "suppex.ir";
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["REQUEST_URI"]    = "/admin/backup.php";
$_SERVER["SCRIPT_NAME"]    = "/admin/backup.php";

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . "/lib/auth.php";
require_once SUPPEX_ROOT . "/lib/backup.php";
require_once SUPPEX_ROOT . "/lib/orders.php";
require_once SUPPEX_ROOT . "/lib/pricing.php";

auth_start_session();
$_SESSION["admin_id"] = 1; $_SESSION["admin_name"] = "tester"; $_SESSION["admin_role"] = "support";
require_once __DIR__ . '/admin-session.php'; test_admin_session();

$pass = 0; $fail = 0; $out = [];
function ok(string $l, bool $c, ?string $x = ""): void {
    global $pass, $fail, $out;
    if ($c) { $pass++; $out[] = "  ok   $l"; }
    else { $fail++; $out[] = "  FAIL $l" . ($x ? "  --  $x" : ""); }
}
function head(string $t): void { global $out; $out[] = "\n== $t =="; }

$admin = ["id" => 1, "name" => "tester"];
/* A real host has this directory; the throwaway test config points at one
   that may not exist yet, and the page correctly reports that. Create it so
   the normal branch is what gets exercised. */
@mkdir(uploads_dir() . "/products", 0775, true);
$tmp   = sys_get_temp_dir() . "/suppex-backup-test.csv";

settings_save(["aed_rate" => "25000", "price_rounding_step" => "10000",
               "commission_percent" => "20"]);

db()->exec("SET FOREIGN_KEY_CHECKS = 0");
db()->exec("TRUNCATE TABLE product_sizes");
db()->exec("TRUNCATE TABLE product_flavors");
db()->exec("DELETE FROM products");
db()->exec("SET FOREIGN_KEY_CHECKS = 1");
db_query("INSERT INTO admins (id, username, password_hash, name, role)
          VALUES (1, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE username = username",
         ["tester", password_hash("x", PASSWORD_DEFAULT), "tester", "support"]);

/* --- A catalogue using every pricing route the sheet supports ----------- */
require_once SUPPEX_ROOT . "/lib/import.php";
$keys = array_keys(IMPORT_COLUMNS);
$fh = fopen($tmp, "w");
fwrite($fh, chr(0xEF) . chr(0xBB) . chr(0xBF));
fputcsv($fh, array_values(IMPORT_COLUMNS));
$rows = [
    /* dirham + profit, two sizes, different agreed shares */
    ["name_fa" => "وی درهمی", "name_en" => "Aed Whey", "brand" => "ON", "category" => "پروتئین",
     "size_label" => "۹۰۰ گرم", "cost_aed" => "40", "profit_toman" => "1500000",
     "commission" => "12", "in_stock" => "بله", "flavors" => "شکلاتی، وانیلی",
     "servings" => "30", "short" => "خط کوتاه", "description" => "متن کامل"],
    ["name_fa" => "وی درهمی", "name_en" => "Aed Whey", "brand" => "ON", "category" => "پروتئین",
     "size_label" => "۲۲۷۰ گرم", "cost_aed" => "95", "profit_toman" => "2000000",
     "commission" => "8", "in_stock" => "بله", "servings" => "76"],
    /* toman cost + profit, single size, with a discount */
    ["name_fa" => "مولتی تومانی", "name_en" => "Toman Multi", "brand" => "ON",
     "category" => "ویتامین و مکمل", "cost_toman" => "650000", "profit_toman" => "600000",
     "promo_toman" => "40000", "in_stock" => "بله", "servings" => "60"],
    /* market price beside a cost, share explicitly zero */
    ["name_fa" => "آمینو رقابتی", "name_en" => "Crowded BCAA", "brand" => "ON",
     "category" => "آمینو و BCAA", "cost_toman" => "820000", "price_toman" => "1150000",
     "commission" => "0", "in_stock" => "خیر", "flavors" => "هندوانه"],
    /* nothing but a price */
    ["name_fa" => "فقط قیمت", "name_en" => "Price Only", "price_toman" => "990000",
     "in_stock" => "بله"],
];
foreach ($rows as $r) {
    $line = [];
    foreach ($keys as $k) { $line[] = $r[$k] ?? ""; }
    fputcsv($fh, $line);
}
fclose($fh);

$read = import_read_csv($tmp);
$plan = import_plan($read["rows"], $read["columns"] ?? []);
if ($plan["errors"]) { echo "fixture failed: ", implode(" | ", $plan["errors"]), "\n"; exit(1); }
import_apply($plan["products"], $admin);

/* What the shop looks like, in the terms that matter. */
function catalogue(): array
{
    $out = [];
    foreach (db_all("SELECT * FROM products ORDER BY name_fa") as $p) {
        $sizes = db_all("SELECT label, price, compare_at, cost_aed, cost_toman,
                                profit_toman, promo_toman, commission_percent, servings
                           FROM product_sizes WHERE product_id = ? ORDER BY sort_order",
                        [(int) $p["id"]]);
        $out[$p["name_fa"]] = [
            "name_en"    => $p["name_en"],
            "brand"      => $p["brand"],
            "in_stock"   => (int) $p["in_stock"],
            "price"      => (int) $p["price"],
            "price_mode" => $p["price_mode"],
            "cost_toman" => $p["cost_toman"] === null ? null : (int) $p["cost_toman"],
            "commission" => $p["commission_percent"] === null ? null : (float) $p["commission_percent"],
            "flavors"    => array_column(db_all(
                "SELECT name FROM product_flavors WHERE product_id = ? ORDER BY sort_order",
                [(int) $p["id"]]), "name"),
            "sizes"      => array_map(static fn($s) => [
                "label"      => $s["label"],
                "price"      => (int) $s["price"],
                "cost_aed"   => $s["cost_aed"] === null ? null : (float) $s["cost_aed"],
                "cost_toman" => $s["cost_toman"] === null ? null : (int) $s["cost_toman"],
                "commission" => $s["commission_percent"] === null ? null : (float) $s["commission_percent"],
                "servings"   => $s["servings"] === null ? null : (int) $s["servings"],
            ], $sizes),
        ];
    }
    return $out;
}

$before = catalogue();

head("the export is readable by the importer that wrote its format");
$csv = backup_catalogue_csv();
ok("it starts with a BOM, so Excel opens it as UTF-8",
   substr($csv, 0, 3) === chr(0xEF) . chr(0xBB) . chr(0xBF));
file_put_contents($tmp, $csv);
$read = import_read_csv($tmp);
ok("it parses", $read["error"] === null, (string) $read["error"]);
ok("every column was recognised",
   count($read["columns"]) === count(IMPORT_COLUMNS),
   count($read["columns"]) . " of " . count(IMPORT_COLUMNS));
ok("one row per buyable unit", count($read["rows"]) === 5, (string) count($read["rows"]));

$plan = import_plan($read["rows"], $read["columns"]);
ok("and it plans with no errors", $plan["errors"] === [], implode(" | ", $plan["errors"]));
ok("with no warnings either", $plan["warnings"] === [], implode(" | ", $plan["warnings"]));

head("destroy the catalogue and rebuild it from the export");
db()->exec("SET FOREIGN_KEY_CHECKS = 0");
db()->exec("TRUNCATE TABLE product_sizes");
db()->exec("TRUNCATE TABLE product_flavors");
db()->exec("DELETE FROM products");
db()->exec("SET FOREIGN_KEY_CHECKS = 1");
ok("the catalogue is gone", (int) db_value("SELECT COUNT(*) FROM products") === 0);

$res = import_apply($plan["products"], $admin);
ok("it rebuilds", $res["created"] === 4, json_encode($res));

$after = catalogue();
ok("the same products came back", array_keys($before) === array_keys($after),
   json_encode([array_keys($before), array_keys($after)], JSON_UNESCAPED_UNICODE));

$diffs = [];
foreach ($before as $name => $b) {
    $a = $after[$name] ?? null;
    if ($a === null) { $diffs[] = "$name missing"; continue; }
    foreach ($b as $field => $value) {
        if (json_encode($a[$field] ?? null) !== json_encode($value)) {
            $diffs[] = "$name.$field: " . json_encode($value, JSON_UNESCAPED_UNICODE)
                     . " -> " . json_encode($a[$field] ?? null, JSON_UNESCAPED_UNICODE);
        }
    }
}
ok("and every priced field survived the round trip", $diffs === [],
   implode(" | ", array_slice($diffs, 0, 6)));

head("the distinctions that decide money survive it");
ok("an agreed per-size share came back",
   ($after["وی درهمی"]["sizes"][0]["commission"] ?? null) === 12.0
   && ($after["وی درهمی"]["sizes"][1]["commission"] ?? null) === 8.0,
   json_encode(array_column($after["وی درهمی"]["sizes"] ?? [], "commission")));
ok("a share of exactly zero came back as zero, not as inherit",
   ($after["آمینو رقابتی"]["commission"] ?? "x") === 0.0,
   json_encode($after["آمینو رقابتی"]["commission"] ?? "missing"));
ok("an inherited share came back blank, not as zero",
   array_key_exists("فقط قیمت", $after)
   && $after["فقط قیمت"]["commission"] === null,
   json_encode($after["فقط قیمت"] ?? "product missing", JSON_UNESCAPED_UNICODE));
ok("an out-of-stock product stayed out of stock",
   ($after["آمینو رقابتی"]["in_stock"] ?? 1) === 0);
ok("a price-only product still records no cost",
   array_key_exists("فقط قیمت", $after)
   && $after["فقط قیمت"]["cost_toman"] === null,
   json_encode($after["فقط قیمت"] ?? "product missing", JSON_UNESCAPED_UNICODE));
ok("a dirham product is still dirham-linked",
   ($after["وی درهمی"]["price_mode"] ?? "") === "aed");
ok("a toman product is not",
   ($after["مولتی تومانی"]["price_mode"] ?? "") === "manual");
ok("flavours came back", ($after["وی درهمی"]["flavors"] ?? []) === ["شکلاتی", "وانیلی"],
   json_encode($after["وی درهمی"]["flavors"] ?? [], JSON_UNESCAPED_UNICODE));

head("never both purchase columns on one row");
$lines = array_map("str_getcsv", array_filter(explode("\n", str_replace("\r", "", $csv))));
$hdr   = $lines[0];
$hdr[0] = preg_replace('/^\xEF\xBB\xBF/', '', $hdr[0]);
$iAed  = array_search(IMPORT_COLUMNS["cost_aed"], $hdr, true);
$iTom  = array_search(IMPORT_COLUMNS["cost_toman"], $hdr, true);
ok("both purchase columns are present in the header", $iAed !== false && $iTom !== false);
$both = 0;
foreach (array_slice($lines, 1) as $l) {
    if (trim((string) ($l[$iAed] ?? "")) !== "" && trim((string) ($l[$iTom] ?? "")) !== "") {
        $both++;
    }
}
ok("and no row fills both", $both === 0, (string) $both);

head("the export reads itself back before it is offered");
/* A backup that cannot be restored is worse than none, because it is believed.
   The catalogue file has exactly one consumer, so the honest check is to hand
   the bytes to that consumer. This was written after a real backup of the real
   shop produced a file the importer rejected - and nothing said so. */
$chk = backup_catalogue_check();
ok("the check runs", $chk["error"] === null, (string) $chk["error"]);
ok("it counts the shop's products",
   $chk["expected"] === (int) db_value("SELECT COUNT(*) FROM products"),
   (string) $chk["expected"]);
ok("and the file rebuilds all of them",
   $chk["products"] === $chk["expected"],
   $chk["products"] . " of " . $chk["expected"]);
ok("with no errors", $chk["errors"] === [], implode(" | ", $chk["errors"]));

/* Now put a value in the shop that the importer refuses, and confirm the
   check says so instead of handing over a file that silently loses a product. */
/* On a sized product the dirham cost sits on the size rows and the parent's is
   NULL by design, so this is the row that actually carries it. */
$victim = (int) db_value("SELECT id FROM product_sizes WHERE cost_aed IS NOT NULL LIMIT 1");
ok("there is a dirham-priced size to spoil", $victim > 0, (string) $victim);
$was = db_value("SELECT cost_aed FROM product_sizes WHERE id = ?", [$victim]);
db_query("UPDATE product_sizes SET cost_aed = 1200000 WHERE id = ?", [$victim]);

$bad = backup_catalogue_check();
ok("the check notices", $bad["errors"] !== [], json_encode($bad["errors"], JSON_UNESCAPED_UNICODE));
ok("and says which row", count(array_filter($bad["errors"],
   static fn($e) => str_contains($e, "غیرواقعی"))) === 1, implode(" | ", $bad["errors"]));
ok("and the page can say how much of the shop the file still covers",
   $bad["products"] <= $bad["expected"] && $bad["expected"] > 0,
   $bad["products"] . " of " . $bad["expected"]);

db_query("UPDATE product_sizes SET cost_aed = ? WHERE id = ?", [$was, $victim]);
$fixed = backup_catalogue_check();
ok("putting the value back clears it", $fixed["errors"] === [],
   implode(" | ", $fixed["errors"]));

ok("nothing was left in the system temp directory",
   count(glob(sys_get_temp_dir() . "/suppex-check-*") ?: []) === 0,
   implode(", ", glob(sys_get_temp_dir() . "/suppex-check-*") ?: []));

/* The SQL dump carries it regardless, which is the point of having both. */
db_query("UPDATE product_sizes SET cost_aed = 1200000 WHERE id = ?", [$victim]);
$sqlBad = "";
backup_stream_sql(static function (string $c) use (&$sqlBad): void { $sqlBad .= $c; });
ok("the SQL dump carries even a value the sheet cannot express",
   str_contains($sqlBad, "1200000.00") || str_contains($sqlBad, "'1200000"),
   "dirham cost not found in the dump");
db_query("UPDATE product_sizes SET cost_aed = ? WHERE id = ?", [$was, $victim]);

head("the SQL splitter does not cut multi-byte letters in half");
/* PCRE's \R matches the single byte 0x85 outside UTF-8 mode, and م is D9 85.
   Splitting a dump on \R therefore severed every م, turned the orphaned D9
   into a question mark and glued a newline where the letter had been. Nothing
   noticed because db/schema.sql contains no م - a restore contains thousands. */
$probe = "INSERT INTO t (a) VALUES ('مولتی ویتامین');\n"
       . "INSERT INTO t (a) VALUES ('کراتین مونوهیدرات');\n";
$stmts = db_sql_statements($probe);
ok("two statements", count($stmts) === 2, (string) count($stmts));
ok("the first keeps its م intact", str_contains($stmts[0], "مولتی ویتامین"),
   bin2hex(substr($stmts[0], 0, 60)));
ok("the second too", str_contains($stmts[1], "کراتین مونوهیدرات"));
ok("no stray newline was introduced",
   !str_contains($stmts[0], "\n") && !str_contains($stmts[1], "\n"),
   json_encode($stmts));

/* Every byte of every Persian letter that carries 0x85 or 0x0b or 0x0c. */
foreach (["م" => "meem", "ت" => "teh", "ح" => "hah"] as $ch => $name) {
    $one = db_sql_statements("SELECT '" . str_repeat($ch, 3) . "';\n");
    ok("$name survives", count($one) === 1 && str_contains($one[0], str_repeat($ch, 3)),
       bin2hex($one[0] ?? ""));
}

/* The behaviour the splitter exists for is unchanged. */
$withComments = "-- a comment\nSELECT 1;\n-- another\nSELECT 2;\n";
$stmts = db_sql_statements($withComments);
ok("full-line comments are still stripped", $stmts === ["SELECT 1", "SELECT 2"],
   json_encode($stmts));
$crlf = db_sql_statements("SELECT 1;\r\nSELECT 2;\r\n");
ok("CRLF files still split", count($crlf) === 2, json_encode($crlf));

head("the SQL dump");
$sql = "";
backup_stream_sql(static function (string $c) use (&$sql): void { $sql .= $c; });
ok("it is not empty", strlen($sql) > 2000, (string) strlen($sql));
ok("it disables foreign keys around the restore",
   str_contains($sql, "SET FOREIGN_KEY_CHECKS = 0")
   && str_contains($sql, "SET FOREIGN_KEY_CHECKS = 1"));
ok("it sets the charset", str_contains($sql, "SET NAMES utf8mb4"));
foreach (["products", "product_sizes", "orders", "order_items", "settings", "admins"] as $t) {
    ok("it contains table $t", str_contains($sql, "DROP TABLE IF EXISTS `$t`;"));
}
ok("it carries the catalogue rows", str_contains($sql, "INSERT INTO `products`"));
ok("persian text is not mangled", str_contains($sql, "وی درهمی"));

head("destroy the whole database and restore it from the dump");
$counts = [];
foreach (backup_tables() as $t) {
    $counts[$t] = (int) db_value("SELECT COUNT(*) FROM `$t`");
}
$dumpFile = sys_get_temp_dir() . "/suppex-restore-test.sql";
file_put_contents($dumpFile, $sql);

db()->exec("SET FOREIGN_KEY_CHECKS = 0");
foreach (array_keys($counts) as $t) { db()->exec("DROP TABLE IF EXISTS `$t`"); }
db()->exec("SET FOREIGN_KEY_CHECKS = 1");
ok("every table is gone", backup_tables() === [], implode(", ", backup_tables()));

$ran = db_run_sql_file($dumpFile);
ok("the dump replays through db_run_sql_file()", $ran > 0, (string) $ran);
@unlink($dumpFile);

$restored = [];
foreach (backup_tables() as $t) {
    $restored[$t] = (int) db_value("SELECT COUNT(*) FROM `$t`");
}
ok("the same tables came back", array_keys($counts) === array_keys($restored),
   implode(", ", array_diff(array_keys($counts), array_keys($restored))));
$rowDiffs = [];
foreach ($counts as $t => $n) {
    if (($restored[$t] ?? -1) !== $n) { $rowDiffs[] = "$t: $n -> " . ($restored[$t] ?? "missing"); }
}
ok("with the same row counts", $rowDiffs === [], implode(" | ", $rowDiffs));

$post = catalogue();
$restoreDiffs = [];
foreach ($after as $name => $a) {
    $q = $post[$name] ?? null;
    if ($q === null) { $restoreDiffs[] = "$name missing"; continue; }
    foreach ($a as $field => $value) {
        if (json_encode($q[$field] ?? null) !== json_encode($value)) {
            $restoreDiffs[] = "$name.$field: " . json_encode($value, JSON_UNESCAPED_UNICODE)
                            . " -> " . json_encode($q[$field] ?? null, JSON_UNESCAPED_UNICODE);
        }
    }
}
ok("and the catalogue is identical after a full restore", $restoreDiffs === [],
   implode(" | ", array_slice($restoreDiffs, 0, 6)));
ok("orders survived", (int) db_value("SELECT COUNT(*) FROM orders") === $counts["orders"]);
ok("settings survived", setting("commission_percent") === "20", (string) setting("commission_percent"));

head("the page renders and the downloads are not written to disk");
ob_start(); include SUPPEX_ROOT . "/admin/backup.php"; $html = ob_get_clean();
ok("renders", strlen($html) > 3000, (string) strlen($html));
ok("offers both downloads",
   str_contains($html, "?download=sql") && str_contains($html, "?download=catalogue"));
ok("warns what the dump contains", mb_strpos($html, "اطلاعات مشتری‌ها") !== false);
ok("names the uploads folder", mb_strpos($html, "htaccess") !== false);
ok("no PHP leaked", mb_strpos($html, "<?") === false);
$stray = glob(SUPPEX_ROOT . "/*.sql") ?: [];
ok("no dump file was left in the web root", $stray === [], implode(", ", $stray));

/* Put the seeded catalogue back for the suites that read it. */
require_once SUPPEX_ROOT . "/db/seed.php";
db()->exec("SET FOREIGN_KEY_CHECKS = 0");
db()->exec("TRUNCATE TABLE product_sizes");
db()->exec("TRUNCATE TABLE product_flavors");
db()->exec("DELETE FROM products");
db()->exec("SET FOREIGN_KEY_CHECKS = 1");
suppex_seed();
ok("catalogue restored for the next suite",
   (int) db_value("SELECT COUNT(*) FROM products") === 15,
   (string) db_value("SELECT COUNT(*) FROM products"));

@unlink($tmp);
echo implode("\n", $out), "\n";
echo "\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
