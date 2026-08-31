<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP.
   bootstrap falls back to the real suppex-config.php when SUPPEX_CONFIG
   is unset - which is exactly what a web request has - so one GET to an
   uploaded tests/ folder could empty the live database. The .htaccess
   beside this file is process; this is code. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* ===========================================================================
   Social preview cards, and the Persian shaping underneath them.
   ---------------------------------------------------------------------------
   The shaping is checked by code point rather than by eye, because a shaped
   string looks plausible while being wrong: the difference between a medial
   and a final form is one pixel of a stroke and the whole word.
   =========================================================================== */

$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["HTTP_HOST"]   = "suppex.ir";

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . "/lib/products.php";
require_once SUPPEX_ROOT . "/lib/ogimage.php";
require_once SUPPEX_ROOT . "/lib/seo.php";

$pass = 0; $fail = 0;
function ok(string $l, bool $c, ?string $x = ""): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $l\n"; }
    else { $fail++; echo "  FAIL $l" . ($x ? "  --  $x" : "") . "\n"; }
}
/** The code points of a shaped string, so a wrong form is visible. */
function cps(string $s): string {
    $o = [];
    foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $c) {
        $o[] = strtoupper(dechex(mb_ord($c, "UTF-8")));
    }
    return implode(" ", $o);
}

echo "\n== letters join the way Persian joins them ==\n";
/* Drawn left to right, so these read in reverse. بابا: alef-final,
   beh-initial, alef-final, beh-initial. */
ok("بابا", cps(arabic_shape("بابا")) === "FE8E FE91 FE8E FE91", cps(arabic_shape("بابا")));
/* ابر: alef does not join forward, so the beh is INITIAL, not medial - the
   single most common shaping mistake. */
ok("ابر keeps beh initial", cps(arabic_shape("ابر")) === "FEAE FE91 FE8D",
   cps(arabic_shape("ابر")));
ok("مکمل joins all four", cps(arabic_shape("مکمل")) === "FEDE FEE4 FB91 FEE3",
   cps(arabic_shape("مکمل")));

echo "\n== the seven non-joiners really do not join ==\n";
foreach (["د" => "FEA9 FEA9", "ر" => "FEAD FEAD", "و" => "FEED FEED",
          "ز" => "FEAF FEAF", "ا" => "FE8D FE8D", "ذ" => "FEAB FEAB",
          "ژ" => "FB8A FB8A"] as $ch => $want) {
    ok("$ch$ch stays two isolated forms", cps(arabic_shape($ch . $ch)) === $want,
       cps(arabic_shape($ch . $ch)));
}

echo "\n== the zero-width non-joiner breaks a join ==\n";
$joined = arabic_shape("میخواهم");
$broken = arabic_shape("می‌خواهم");
ok("without it, yeh is medial", mb_strpos($joined, "\u{FBFF}") !== false, cps($joined));
ok("with it, yeh is final",     mb_strpos($broken, "\u{FBFD}") !== false, cps($broken));
ok("and the ZWNJ is not drawn", mb_strpos($broken, "\u{200C}") === false);
ok("it does not survive as a glyph", mb_strlen($broken) === mb_strlen($joined),
   mb_strlen($joined) . " vs " . mb_strlen($broken));

echo "\n== lam-alef is one ligature, never two letters ==\n";
ok("لا is a single glyph", mb_strlen(arabic_shape("لا")) === 1, cps(arabic_shape("لا")));
ok("and takes its final form after a joiner",
   cps(arabic_shape("بالا")) === "FEFB FE8E FE91", cps(arabic_shape("بالا")));

echo "\n== latin and digits survive the reversal ==\n";
$mixed = arabic_shape("وی پروتئین Gold Standard 900 گرم");
ok("a latin phrase is not reversed", mb_strpos($mixed, "Gold Standard") !== false, $mixed);
ok("nor are digits", mb_strpos($mixed, "900") !== false);
ok("a pure latin string is untouched",
   arabic_shape("Gold Standard Whey 2270") === "Gold Standard Whey 2270");
ok("persian is detected", arabic_has_persian("وی") === true);
ok("latin is not", arabic_has_persian("Whey") === false);

echo "\n== the card ==\n";
$p = product_get("whey-original");
ok("a product to draw", $p !== null);
$png = og_render($p);
ok("it is a PNG", substr($png, 1, 3) === "PNG", bin2hex(substr($png, 0, 4)));
$size = getimagesizefromstring($png);
ok("1200x630, the size every preview wants",
   $size[0] === 1200 && $size[1] === 630, $size[0] . "x" . $size[1]);
ok("and not a blank rectangle", strlen($png) > 15000, strlen($png) . " bytes");

echo "\n== every product gets a different card ==\n";
$seen = [];
foreach (["whey-original", "creatine-mono", "iso-zero", "mass-builder"] as $slug) {
    $q = product_get($slug);
    if ($q === null) { continue; }
    $seen[$slug] = og_fingerprint($q);
}
ok("four products", count($seen) === 4, (string) count($seen));
ok("four different fingerprints", count(array_unique($seen)) === count($seen),
   json_encode($seen));

echo "\n== the fingerprint tracks what the card shows ==\n";
$before = og_fingerprint($p);
$moved  = $p; $moved["price"] = (int) $p["price"] + 10000;
ok("a price change changes it", og_fingerprint($moved) !== $before);
$renamed = $p; $renamed["nameFa"] = $p["nameFa"] . " ";
ok("a name change changes it", og_fingerprint($renamed) !== $before);
$same = $p;
ok("but nothing else does", og_fingerprint($same) === $before);
ok("and the url carries it", str_contains(og_url($p), "v=" . $before), og_url($p));

echo "\n== the cache keeps one card per product ==\n";
$card = og_card($p);
ok("a card comes back", $card !== null && strlen($card["bytes"]) > 15000);
ok("and is on disk", $card["path"] !== "" && is_file($card["path"]), $card["path"]);
$first = $card["path"];

/* Reprice it and check the old file is swept rather than accumulating. */
$p2 = $p; $p2["price"] = (int) $p["price"] + 50000;
$card2 = og_card($p2);
ok("a repriced product gets a new file", $card2["path"] !== $first);
ok("and the old one is gone", !is_file($first), $first);
ok("exactly one card for this product",
   count(glob(og_cache_dir() . "/whey-original-*.png") ?: []) === 1,
   implode(", ", array_map("basename", glob(og_cache_dir() . "/whey-original-*.png") ?: [])));

echo "\n== a product with no photograph still gets a real card ==\n";
$noPhoto = $p; $noPhoto["image"] = "";
$png = og_render($noPhoto);
ok("still a PNG", substr($png, 1, 3) === "PNG");
ok("still 1200x630", getimagesizefromstring($png)[0] === 1200);
ok("an SVG placeholder is not treated as a photo",
   og_local_path("assets/images/products/whey-original.svg") === null);
ok("nor is a path that walks out of the site",
   og_local_path("/uploads/../../etc/passwd") === null);

echo "\n== the metadata points at the card ==\n";
$meta = seo_product_meta($p);
ok("og:image is the generated card", str_contains($meta["image"], "og.php?p=whey-original"),
   $meta["image"]);
ok("it is absolute", preg_match('~^https?://~', $meta["image"]) === 1, $meta["image"]);
ok("and carries the fingerprint", str_contains($meta["image"], "&v="), $meta["image"]);

$m2 = seo_product_meta(product_get("creatine-mono"));
ok("a different product gets a different image", $m2["image"] !== $meta["image"]);

echo "\n== the endpoint ==\n";
/* In its own process: this harness has already echoed, so og.php's header()
   calls would warn straight into the captured output - which looks exactly
   like the endpoint returning nonsense. */
$once = static function (string $slug): string {
    /* Through a file, not a pipe: on Windows a pipe is read in text mode and
       stops at 0x1A, which is byte seven of every PNG signature. The first
       three bytes still spell "PNG", so a piped check passes on five bytes. */
    $tmp = sys_get_temp_dir() . "/suppex-og-" . bin2hex(random_bytes(4)) . ".bin";
    shell_exec(escapeshellarg(PHP_BINARY) . " " . escapeshellarg(__DIR__ . "/og-once.php")
        . " " . escapeshellarg($slug) . " " . escapeshellarg($tmp) . " 2>&1");
    $body = is_file($tmp) ? (string) file_get_contents($tmp) : "";
    @unlink($tmp);
    return $body;
};

$out = $once("whey-original");
ok("serves PNG bytes", substr($out, 1, 3) === "PNG", bin2hex(substr($out, 0, 8)));
ok("and a whole image", strlen($out) > 15000, strlen($out) . " bytes");
$sz = @getimagesizefromstring($out);
ok("that decodes at 1200x630", $sz !== false && $sz[0] === 1200 && $sz[1] === 630,
   $sz === false ? "not an image" : $sz[0] . "x" . $sz[1]);

$miss = $once("no-such-thing");
ok("a missing product is a short 404, not an HTML page",
   trim($miss) === "Not Found", substr($miss, 0, 80));
ok("and carries no markup", strpos($miss, "<") === false);

echo "\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
