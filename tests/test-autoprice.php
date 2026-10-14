<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP.
   bootstrap falls back to the real suppex-config.php when SUPPEX_CONFIG
   is unset - which is exactly what a web request has - so one GET to an
   uploaded tests/ folder could empty the live database. The .htaccess
   beside this file is process; this is code. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* ===========================================================================
   A dirham product is priced by the formula the moment its form is saved.
   ---------------------------------------------------------------------------
   The owner, looking at a product they had just typed in: «بهتر نیست وقتی قیمت
   رو بر اساس درهم وارد میکنم و سود روهم مینویسم خودش قیمت تومانی فروش رو
   حساب کنه». Until then the form stored whatever was typed into «قیمت», and
   the formula only arrived with the next change of rate: 350 dirhams at
   63,900 plus 7,200,000 profit went on sale at a hand-rounded 29,500,000
   where the formula says 29,570,000, and the difference came out of the
   margin.

   Driven through the real page (tests/formpost.php), because the failures
   worth catching are in the form handling: which posted figure is trusted, in
   which currency it was typed, and in which order the rows are written.
   =========================================================================== */

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/pricing.php';
require_once SUPPEX_ROOT . '/lib/variants.php';
require_once SUPPEX_ROOT . '/lib/migrate.php';

$pass = 0; $fail = 0;
function ok(string $l, bool $c, ?string $x = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $l\n"; }
    else { $fail++; echo "  FAIL $l" . ($x !== '' ? "  --  $x" : '') . "\n"; }
}
function head(string $t): void { echo "\n== $t ==\n"; }

/* One save (or one look) through the page, in its own process. */
function form(int $id, $fields): array {
    $arg = $fields === 'view' ? 'view' : base64_encode(json_encode($fields, JSON_UNESCAPED_UNICODE));
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/formpost.php')
        . ' ' . $id . ' ' . escapeshellarg($arg) . ' 2>&1');
    $lines = preg_split('/\R/u', trim($out));
    $json  = json_decode((string) end($lines), true);
    return is_array($json)
        ? $json + ['raw' => $out]
        : ['id' => 0, 'saved' => false, 'flash' => [], 'errors' => [], 'html' => '', 'raw' => $out];
}
function flashes(array $r): string {
    return implode(' | ', array_map(static fn($f) => (string) $f['message'], $r['flash'] ?? []));
}

function wipe(): void {
    foreach (db_all("SELECT id FROM products WHERE slug LIKE 'zz-auto%'") as $r) {
        $pid = (int) $r['id'];
        db_query('DELETE vv FROM product_variant_values vv JOIN product_variants v
                    ON v.id = vv.variant_id WHERE v.product_id = ?', [$pid]);
        db_query('DELETE FROM product_variants WHERE product_id = ?', [$pid]);
        db_query('DELETE av FROM product_attribute_values av JOIN product_attributes a
                    ON a.id = av.attribute_id WHERE a.product_id = ?', [$pid]);
        db_query('DELETE FROM product_attributes WHERE product_id = ?', [$pid]);
        db_query('DELETE FROM product_sizes WHERE product_id = ?', [$pid]);
        db_query('DELETE FROM product_flavors WHERE product_id = ?', [$pid]);
        db_query('DELETE FROM commission_log WHERE scope = "product" AND scope_id = ?', [$pid]);
    }
    db_query("DELETE FROM products WHERE slug LIKE 'zz-auto%'");
}

$rateBefore = setting('aed_rate', '');
$stepBefore = setting('price_rounding_step', '');
wipe();
settings_save(['aed_rate' => '63900', 'price_rounding_step' => '10000']);
$RATE = 63900.0;

/* Everything the top of the form posts, as the owner's screenshot had it. */
$base = static fn(array $over = []): array => array_merge([
    'name_fa' => 'زد-خودکار الایت', 'name_en' => 'Elite 100% Whey', 'slug' => 'zz-auto-elite',
    'brand' => 'Dymatize', 'category_id' => '',
    /* The struck price starts blank: a figure in it is the owner's own and has
       its section below. */
    'price' => '29500000', 'compare_at' => '',
    'in_stock' => '1', 'is_active' => '1', 'price_mode' => 'aed',
    'cost_aed' => '350', 'profit_toman' => '7200000', 'promo_toman' => '',
    'commission_percent' => '', 'cost_toman' => '', 'flavor_unit' => 'aed',
], $over);
$row = static fn(int $pid): array => db_one('SELECT * FROM products WHERE id = ?', [$pid]) ?? [];


head('a dirham figure is read the way it is typed');
foreach (['350' => 350.0, '۳۵۰' => 350.0, '42.5' => 42.5, '42٫5' => 42.5, '۴۲/۵' => 42.5,
          '1,250.75' => 1250.75, '۲۲٬۳۶۵' => 22365.0, '42 درهم' => 42.0, '.5' => 0.5] as $in => $want) {
    ok("«{$in}» is {$want}", pricing_parse_aed((string) $in) === $want,
       var_export(pricing_parse_aed((string) $in), true));
}
ok('a blank is nothing, not zero', pricing_parse_aed('') === null && pricing_parse_aed('  ') === null);
ok('and neither are words', pricing_parse_aed('درهم') === null);


head('a profit typed today is honoured exactly today');
$c350 = (int) round(350 * $RATE);
$pct  = pricing_percent_from($c350, 7200000);
ok('the ratio is stored to three decimals', abs($pct - 32.193) < 0.0001, (string) $pct);
ok('  and gives the typed figure back only roughly', (int) round($c350 * $pct / 100) !== 7200000,
   (string) round($c350 * $pct / 100));
ok('so at that cost the typed 7,200,000 is what counts',
   pricing_profit(['profit_toman' => 7200000, 'profit_percent' => $pct], $c350) === 7200000);
$c360 = (int) round(360 * $RATE);
ok('at a new purchase price the ratio decides, as it always has',
   pricing_profit(['profit_toman' => 7200000, 'profit_percent' => $pct], $c360)
       === (int) round($c360 * $pct / 100),
   (string) pricing_profit(['profit_toman' => 7200000, 'profit_percent' => $pct], $c360));
ok('  and at a new rate too',
   pricing_profit(['profit_toman' => 7200000, 'profit_percent' => $pct], (int) round(350 * 66000))
       === (int) round(350 * 66000 * $pct / 100));


head('the owner\'s product: the typed price gives way to the formula');
$r = form(0, $base());
$pid = (int) $r['id'];
ok('the new product was saved', $r['saved'] && $pid > 0, $r['raw']);
$p = $row($pid);
ok('350 × 63,900 + 7,200,000, rounded up: 29,570,000 - not the 29,500,000 typed',
   (int) ($p['price'] ?? 0) === 29570000, (string) ($p['price'] ?? 'none'));
ok('  and no struck price, because nobody set a discount',
   array_key_exists('compare_at', $p) && $p['compare_at'] === null, var_export($p['compare_at'] ?? null, true));
ok('  the toman cost is the one the price was built on',
   (int) ($p['cost_toman'] ?? 0) === 22365000, (string) ($p['cost_toman'] ?? ''));
ok('  priced at the shelf\'s rate, now',
   (float) ($p['price_applied_rate'] ?? 0) === $RATE && ($p['price_applied_at'] ?? null) !== null);
ok('  the profit is the one typed, to the toman', (int) ($p['profit_toman'] ?? 0) === 7200000);
ok('  and its ratio is locked for the next rate',
   abs((float) ($p['profit_percent'] ?? 0) - 32.193) < 0.0001, (string) ($p['profit_percent'] ?? ''));
ok('the owner is told the price, with the rate it was worked at',
   strpos(flashes($r), '29,570,000') !== false && strpos(flashes($r), '63,900') !== false, flashes($r));

$prev = array_values(array_filter(pricing_preview($RATE), static fn($u) => $u['product_id'] === $pid));
ok('the pricing page, at the same rate, has nothing to change',
   count($prev) === 1 && $prev[0]['delta'] === 0 && $prev[0]['compare_at'] === null,
   json_encode($prev));


head('a discount is an amount off, and the struck price is the full one');
/* The same shelf the owner typed by hand - 31,000,000 struck, 29,500,000
   charged - reached the formula's way: the full price is cost + profit, and
   the discount comes off it. */
$r = form($pid, $base(['profit_toman' => '8635000', 'promo_toman' => '1500000']));
$p = $row($pid);
ok('cost + 8,635,000 is 31,000,000 in full', (int) ($p['compare_at'] ?? 0) === 31000000,
   (string) ($p['compare_at'] ?? 'none'));
ok('  and 1,500,000 off it is 29,500,000', (int) $p['price'] === 29500000, (string) $p['price']);
ok('  the discount is stored as typed', (int) ($p['promo_toman'] ?? 0) === 1500000);
ok('  and the message names both', strpos(flashes($r), '31,000,000') !== false, flashes($r));
$prev = array_values(array_filter(pricing_preview($RATE), static fn($u) => $u['product_id'] === $pid));
ok('the pricing page agrees, struck price and all',
   count($prev) === 1 && $prev[0]['delta'] === 0 && $prev[0]['compare_at'] === 31000000, json_encode($prev));

$r = form($pid, $base(['promo_toman' => '5000000']));
$p = $row($pid);
ok('a discount above half the profit is cut to half: 29,565,000 - 3,600,000, rounded up',
   (int) $p['price'] === 25970000 && (int) $p['compare_at'] === 29570000,
   $p['price'] . ' / ' . $p['compare_at']);
ok('  kept as typed, so a later rate can honour more of it', (int) $p['promo_toman'] === 5000000);
ok('  and the owner is told it was cut', strpos(flashes($r), 'نصف سود') !== false, flashes($r));

$r = form($pid, $base(['promo_toman' => '']));
$p = $row($pid);
ok('an emptied discount takes the struck price away',
   $p['compare_at'] === null && $p['promo_toman'] === null && (int) $p['price'] === 29570000,
   json_encode([$p['compare_at'], $p['promo_toman'], $p['price']]));

$noField = $base();
unset($noField['promo_toman']);
db_query('UPDATE products SET promo_toman = 400000 WHERE id = ?', [$pid]);
form($pid, $noField);
ok('a page from before the field existed does not erase a discount',
   (int) ($row($pid)['promo_toman'] ?? 0) === 400000);
form($pid, $base(['promo_toman' => '']));


head('the margin survives a new purchase price');
/* Profit left as it was, purchase price changed: the locked ratio carries to
   the new cost, the way it carries to a new rate. */
$r = form($pid, $base(['cost_aed' => '360']));
$p = $row($pid);
$cost360 = (int) round(360 * $RATE);
$prof360 = (int) round($cost360 * 32.193 / 100);
ok('the ratio is kept', abs((float) $p['profit_percent'] - 32.193) < 0.0001, (string) $p['profit_percent']);
ok('  so the profit follows the cost', (int) $p['profit_toman'] === $prof360,
   $p['profit_toman'] . ' vs ' . $prof360);
ok('  and so does the price', (int) $p['price'] === pricing_round_up($cost360 + $prof360),
   (string) $p['price']);
form($pid, $base());


head('the rest of the page is as it was');
db_query("INSERT INTO products (slug, name_fa, price, compare_at, price_mode, promo_toman, is_active, in_stock)
          VALUES ('zz-auto-manual', 'زد-دستی', 1250000, 1500000, 'manual', 50000, 1, 1)");
$mid = (int) db_value("SELECT id FROM products WHERE slug = 'zz-auto-manual'");
$r = form($mid, $base(['slug' => 'zz-auto-manual', 'name_fa' => 'زد-دستی', 'price_mode' => 'manual',
                       'price' => '1250000', 'compare_at' => '1500000', 'cost_aed' => '',
                       'profit_toman' => '', 'flavor_unit' => 'toman']));
$m = $row($mid);
ok('a manual product keeps the price typed into it',
   (int) $m['price'] === 1250000 && (int) $m['compare_at'] === 1500000, $m['price'] . ' / ' . $m['compare_at']);
ok('  and the discount the import gave it', (int) $m['promo_toman'] === 50000);
ok('  and nothing is said about a formula', strpos(flashes($r), 'نرخ') === false, flashes($r));

settings_save(['aed_rate' => '']);
$r = form($pid, $base(['price' => '2000000', 'compare_at' => '']));
ok('with no rate on record the typed price stands, as before',
   (int) $row($pid)['price'] === 2000000, (string) $row($pid)['price']);
settings_save(['aed_rate' => '63900']);
form($pid, $base());
ok('  and a rate prices it again on the next save', (int) $row($pid)['price'] === 29570000);


head('a purchase price typed in toman is refused');
$r = form($pid, $base(['cost_aed' => '22365000']));
ok('the save is refused', !$r['saved'], $r['raw']);
ok('  saying why', (bool) array_filter($r['errors'], static fn($e) => strpos($e, 'غیرواقعی') !== false),
   json_encode($r['errors'], JSON_UNESCAPED_UNICODE));
ok('  and nothing moved', (int) $row($pid)['price'] === 29570000 && (float) $row($pid)['cost_aed'] === 350.0);
ok('Persian digits and «/» for the decimal point are a price, not an error',
   form($pid, $base(['cost_aed' => '۳۵۰/۵']))['saved'] && (float) $row($pid)['cost_aed'] === 350.5);
form($pid, $base());


head('sizes, flavours and their combinations, in one save');
/* One product, new, with three sizes - one with its own purchase price and
   profit, one with its own profit only, one with neither - and a flavour
   that costs two dirhams more. Every combination has to exist after ONE
   save and be priced: the sizes used to be written after the combinations
   were built, so a new product came out with flavour rows only. */
$sized = $base([
    'slug' => 'zz-auto-sized', 'name_fa' => 'زد-اندازه‌دار', 'price' => '', 'compare_at' => '',
    'cost_aed' => '80', 'profit_toman' => '2000000',
    'size_id' => ['900', '5lb', '2270'], 'size_label' => ['۹۰۰ گرم', '۵ پوند', '۲۲۷۰ گرم'],
    'size_servings' => ['', '', ''], 'size_cost_aed' => ['42.5', '', ''],
    'size_cost_toman' => ['', '', ''], 'size_profit' => ['1705000', '3000000', ''],
    'size_price' => ['', '', ''], 'size_compare' => ['', '', ''], 'size_prev_id' => ['', '', ''],
    'flavor_id' => ['choco', 'cook'], 'flavor_name' => ['شکلاتی', 'کوکی'], 'flavor_extra' => ['', '2'],
]);
$r = form(0, $sized);
$sid = (int) $r['id'];
ok('saved', $r['saved'] && $sid > 0, $r['raw']);
$v = [];
foreach (db_all('SELECT * FROM product_variants WHERE product_id = ?', [$sid]) as $vr) {
    $v[(string) $vr['ext_id']] = $vr;
}
ok('all six combinations exist after one save', count($v) === 6, implode(', ', array_keys($v)));

$unit = static function (float $aed, int $profit, ?float $pct) use ($RATE): array {
    $cost = (int) round($aed * $RATE);
    return pricing_from_cost($cost, pricing_profit(['profit_toman' => $profit, 'profit_percent' => $pct], $cost), 0);
};
$pct900 = pricing_percent_from((int) round(42.5 * $RATE), 1705000);
$want900 = $unit(42.5, 1705000, $pct900);
ok('900 g: its own 42.5 dirhams and its own profit', (int) ($v['900|choco']['price'] ?? 0) === $want900['price'],
   ($v['900|choco']['price'] ?? 'none') . ' vs ' . $want900['price']);
ok('  4,430,000, worked by hand', $want900['price'] === 4430000, (string) $want900['price']);

$cook900 = (int) round(44.5 * $RATE);
$scaled  = (int) round(1705000 * $cook900 / (int) round(42.5 * $RATE));
ok('the dearer flavour costs 44.5 and keeps the same margin on it',
   (float) ($v['900|cook']['cost_aed'] ?? 0) === 44.5
   && (int) $v['900|cook']['price'] === pricing_round_up($cook900 + $scaled),
   ($v['900|cook']['cost_aed'] ?? '') . ' / ' . ($v['900|cook']['price'] ?? ''));

$pct5 = (float) db_value("SELECT profit_percent FROM product_sizes WHERE product_id = ? AND ext_id = '5lb'", [$sid]);
ok('a size with its own profit but the product\'s purchase price gets its own ratio',
   abs($pct5 - pricing_percent_from((int) round(80 * $RATE), 3000000)) < 0.001, (string) $pct5);
ok('  and is priced by its own profit, not the product\'s ratio',
   (int) ($v['5lb|choco']['price'] ?? 0) === pricing_round_up((int) round(80 * $RATE) + 3000000),
   (string) ($v['5lb|choco']['price'] ?? ''));
ok('a size with neither is the product: 80 dirhams and 2,000,000',
   (int) ($v['2270|choco']['price'] ?? 0) === pricing_round_up((int) round(80 * $RATE) + 2000000),
   (string) ($v['2270|choco']['price'] ?? ''));
ok('every combination carries the rate and its cost',
   count(array_filter($v, static fn($x) => (float) $x['price_applied_rate'] === $RATE
       && (int) $x['cost_toman'] === (int) round((float) $x['cost_aed'] * $RATE))) === 6);
$sp = $row($sid);
ok('the card shows the cheapest combination', (int) $sp['price'] === min(array_map(
    static fn($x) => (int) $x['price'], $v)), (string) $sp['price']);
ok('  and says so to the owner', strpos(flashes($r), number_format((int) $sp['price'])) !== false, flashes($r));
$sizeRows = db_all('SELECT ext_id, price, profit_toman FROM product_sizes WHERE product_id = ? ORDER BY sort_order', [$sid]);
ok('the sizes table carries its prices too', (int) $sizeRows[0]['price'] === $want900['price'],
   json_encode($sizeRows));
ok('  but a blank profit stays blank, so the size keeps following the product',
   $sizeRows[2]['profit_toman'] === null, json_encode($sizeRows));

$prevS = array_values(array_filter(pricing_preview($RATE), static fn($u) => $u['product_id'] === $sid));
ok('the pricing page, at the same rate, would change none of the six',
   count($prevS) === 6 && !array_filter($prevS, static fn($u) => $u['delta'] !== 0),
   json_encode(array_map(static fn($u) => [$u['sub'], $u['current'], $u['proposed']], $prevS),
               JSON_UNESCAPED_UNICODE));


head('the grid follows the size, unless told otherwise');
$page = form($sid, 'view')['html'];
ok('a combination that costs what its size costs is drawn blank',
   (bool) preg_match('~name="variant_ext\[\]" value="2270\|choco">.*?name="variant_cost\[\]"[^>]*value=""[^>]*placeholder="80"~s', $page),
   'no blank 2270|choco');
ok('  with the figure it follows as the placeholder',
   (bool) preg_match('~name="variant_ext\[\]" value="900\|cook">.*?name="variant_cost\[\]"[^>]*value=""[^>]*placeholder="44.5"~s', $page),
   'no 44.5 placeholder');

/* The page as the owner would post it next: the product's purchase price
   raised to 90, one combination given a price of its own, and a new size. */
$grid = static function (array $own) use ($sid): array {
    $out = ['variant_ext' => [], 'variant_price' => [], 'variant_cost' => [], 'variant_stock' => [],
            'variant_active' => []];
    foreach (variants_list($sid) as $gv) {
        $k = (string) $gv['ext_id'];
        $out['variant_ext'][] = $k;
        $out['variant_price'][] = (string) $gv['price'];
        $out['variant_cost'][] = $own[$k] ?? '';
        $out['variant_stock'][] = '';
        $out['variant_active'][$k] = '1';
    }
    return $out;
};
$next = array_merge($sized, $grid(['900|cook' => '50']), [
    'cost_aed' => '90',
    'size_id' => ['900', '5lb', '2270', '10lb'], 'size_label' => ['۹۰۰ گرم', '۵ پوند', '۲۲۷۰ گرم', '۱۰ پوند'],
    'size_servings' => ['', '', '', ''], 'size_cost_aed' => ['42.5', '', '', '150'],
    'size_cost_toman' => ['', '', '', ''], 'size_profit' => ['1705000', '3000000', '', '4000000'],
    'size_price' => ['', '', '', ''], 'size_compare' => ['', '', '', ''],
    'size_prev_id' => ['900', '5lb', '2270', ''], 'grid_unit' => 'aed',
]);
$r = form($sid, $next);
ok('saved', $r['saved'], $r['raw']);
$v = [];
foreach (db_all('SELECT * FROM product_variants WHERE product_id = ?', [$sid]) as $vr) {
    $v[(string) $vr['ext_id']] = $vr;
}
ok('the combinations of a size that follows the product follow its new 90 dirhams',
   (float) ($v['2270|choco']['cost_aed'] ?? 0) === 90.0 && (float) ($v['2270|cook']['cost_aed'] ?? 0) === 92.0,
   ($v['2270|choco']['cost_aed'] ?? '') . ' / ' . ($v['2270|cook']['cost_aed'] ?? ''));
/* The product's profit was left as it was, so its locked ratio carries to
   the new purchase price - the margin, not the toman figure, is what holds. */
$pct80 = pricing_percent_from((int) round(80 * $RATE), 2000000);
$c90   = (int) round(90 * $RATE);
ok('  and are priced from it, at the product\'s locked margin',
   (int) $v['2270|choco']['price']
       === pricing_round_up($c90 + pricing_profit(['profit_toman' => 2000000, 'profit_percent' => $pct80], $c90)),
   (string) $v['2270|choco']['price']);
ok('the one given a figure of its own keeps it', (float) ($v['900|cook']['cost_aed'] ?? 0) === 50.0,
   (string) ($v['900|cook']['cost_aed'] ?? ''));
ok('  and the ones of a size with its own purchase price did not move',
   (float) ($v['900|choco']['cost_aed'] ?? 0) === 42.5);
ok('a size added on this save has its combinations already, priced',
   isset($v['10lb|choco'], $v['10lb|cook'])
   && (int) $v['10lb|choco']['price'] === pricing_round_up((int) round(150 * $RATE) + 4000000),
   implode(', ', array_keys($v)));
$page = form($sid, 'view')['html'];
ok('the figure of its own is drawn, not blanked',
   (bool) preg_match('~name="variant_ext\[\]" value="900\|cook">.*?name="variant_cost\[\]"[^>]*value="50\.00"~s', $page),
   'no 50.00');


head('a premium typed in toman is not read as dirhams');
/* A manual product with a 150,000-toman flavour premium, switched to
   dirham pricing and saved from the same page: the premium column still
   says toman, and 150,000 dirhams would be a nine-billion shelf price. */
db_query("INSERT INTO products (slug, name_fa, price, price_mode, is_active, in_stock)
          VALUES ('zz-auto-switch', 'زد-تغییر حالت', 3000000, 'manual', 1, 1)");
$wid = (int) db_value("SELECT id FROM products WHERE slug = 'zz-auto-switch'");
db_query("INSERT INTO product_flavors (product_id, ext_id, name, price_delta_toman, sort_order)
          VALUES (?, 'van', 'وانیل', 0, 0), (?, 'cook', 'کوکی', 150000, 1)", [$wid, $wid]);
form($wid, 'view');   // a look changes nothing
$r = form($wid, $base([
    'slug' => 'zz-auto-switch', 'name_fa' => 'زد-تغییر حالت', 'cost_aed' => '40', 'profit_toman' => '1000000',
    'flavor_unit' => 'toman', 'flavor_id' => ['van', 'cook'], 'flavor_name' => ['وانیل', 'کوکی'],
    'flavor_extra' => ['', '150000'],
]));
ok('the save goes through', $r['saved'], $r['raw']);
ok('  without the premium', db_value("SELECT cost_delta_aed FROM product_flavors
                                      WHERE product_id = ? AND ext_id = 'cook'", [$wid]) === null);
ok('  every combination priced from 40 dirhams',
   !array_filter(db_all('SELECT price FROM product_variants WHERE product_id = ?', [$wid]),
                 static fn($x) => (int) $x['price'] !== pricing_round_up((int) round(40 * $RATE) + 1000000)),
   json_encode(db_all('SELECT ext_id, price FROM product_variants WHERE product_id = ?', [$wid])));
ok('  and the owner is told to type it again in dirhams', strpos(flashes($r), 'اضافه‌بها') !== false, flashes($r));
$r = form($wid, $base([
    'slug' => 'zz-auto-switch', 'name_fa' => 'زد-تغییر حالت', 'cost_aed' => '40', 'profit_toman' => '1000000',
    'flavor_id' => ['van', 'cook'], 'flavor_name' => ['وانیل', 'کوکی'], 'flavor_extra' => ['', '150000'],
]));
ok('the same figure posted as dirhams is refused as a toman price',
   !$r['saved'] && (bool) array_filter($r['errors'], static fn($e) => strpos($e, 'تومان') !== false),
   json_encode($r['errors'], JSON_UNESCAPED_UNICODE));


head('a struck price typed by hand, in dirham mode');
/* The owner's words: the formula's price can be right and they still want a
   figure of their own struck through beside it. compare_at is rewritten at
   every repricing, so the figure lives in compare_manual and is put back by
   pricing_place_manual_compare() - after the form's save, after the pricing
   page, after the automatic rate. */
ok('the column for it has arrived with the migration', pricing_manual_ready()
   && (migrate_pending()['products.compare_manual'] ?? false) === true);
$r = form($pid, $base(['compare_at' => '40000000']));
$p = $row($pid);
ok('the typed figure is kept as typed', (int) ($p['compare_manual'] ?? 0) === 40000000,
   (string) ($p['compare_manual'] ?? 'none'));
ok('  and struck through beside the formula\'s price',
   (int) $p['compare_at'] === 40000000 && (int) $p['price'] === 29570000, $p['compare_at'] . ' / ' . $p['price']);
ok('  the message names it', strpos(flashes($r), '40,000,000') !== false, flashes($r));

$admin = ['id' => null, 'name' => 'آزمون'];
pricing_apply(66000.0, $admin, null, 'manual');
$p = $row($pid);
ok('a new rate moves the price and leaves the struck price where it was',
   (int) $p['price'] > 29570000 && (int) $p['compare_at'] === 40000000, $p['price'] . ' / ' . $p['compare_at']);
pricing_apply(100000.0, $admin, null, 'manual');
$p = $row($pid);
ok('once the price passes it, it is not shown - a struck price below the price is no saving',
   (int) $p['price'] > 40000000 && $p['compare_at'] === null && (int) $p['compare_manual'] === 40000000,
   json_encode([$p['price'], $p['compare_at'], $p['compare_manual']]));
pricing_apply(63900.0, $admin, null, 'manual');
$p = $row($pid);
ok('  and when it falls back below, the figure is there again',
   (int) $p['price'] === 29570000 && (int) $p['compare_at'] === 40000000, $p['price'] . ' / ' . $p['compare_at']);

$r = form($pid, $base(['compare_at' => '40000000', 'promo_toman' => '1500000']));
$p = $row($pid);
ok('with a discount as well, the discount lowers the price and the typed figure is the one struck',
   (int) $p['price'] === 28070000 && (int) $p['compare_at'] === 40000000, $p['price'] . ' / ' . $p['compare_at']);

$r = form($pid, $base(['compare_at' => '20000000']));
$p = $row($pid);
ok('a figure below the price is kept but not shown, and the owner is told',
   (int) $p['compare_manual'] === 20000000 && $p['compare_at'] === null
   && strpos(flashes($r), 'بیشتر نیست') !== false, flashes($r));

$r = form($pid, $base(['compare_at' => '']));
$p = $row($pid);
ok('emptied, the struck price is the discount\'s again - none here',
   $p['compare_manual'] === null && $p['compare_at'] === null);

/* A product with sizes: the figure belongs beside the card's price - the
   cheapest size on sale - and to that size's flavours, not to every size. */
$r = form($sid, array_merge($next, ['compare_at' => '6000000']));
$vs = [];
foreach (db_all('SELECT ext_id, price, compare_at FROM product_variants WHERE product_id = ?', [$sid]) as $vr) {
    $vs[(string) $vr['ext_id']] = $vr;
}
ok('with sizes, the card and the cheapest size\'s combinations carry it',
   (int) $row($sid)['compare_at'] === 6000000
   && (int) ($vs['900|choco']['compare_at'] ?? 0) === 6000000 && (int) ($vs['900|cook']['compare_at'] ?? 0) === 6000000,
   json_encode(array_map(static fn($x) => [$x['price'], $x['compare_at']], $vs)));
ok('  and no other size does',
   isset($vs['2270|choco'], $vs['10lb|cook'])
   && $vs['2270|choco']['compare_at'] === null && $vs['10lb|cook']['compare_at'] === null,
   json_encode(array_map(static fn($x) => $x['compare_at'], $vs)));
form($sid, array_merge($next, ['compare_at' => '']));
ok('  emptied, it is gone from all of them',
   (int) db_value('SELECT COUNT(*) FROM product_variants WHERE product_id = ? AND compare_at IS NOT NULL', [$sid]) === 0
   && $row($sid)['compare_at'] === null);

$r = form($mid, $base(['slug' => 'zz-auto-manual', 'name_fa' => 'زد-دستی', 'price_mode' => 'manual',
                       'price' => '1250000', 'compare_at' => '1700000', 'cost_aed' => '',
                       'profit_toman' => '', 'flavor_unit' => 'toman']));
ok('a manual product\'s struck price is still simply the one typed',
   (int) $row($mid)['compare_at'] === 1700000 && $row($mid)['compare_manual'] === null,
   json_encode([$row($mid)['compare_at'], $row($mid)['compare_manual']]));


head('the page shows it');
$page = form($pid, 'view')['html'];
ok('in dirham mode the price is the formula\'s: read-only, marked',
   (bool) preg_match('~id="price"[^>]*data-auto-price readonly~', $page) && strpos($page, 'auto-tag') !== false);
ok('  but not the struck price, which is typed by hand',
   !preg_match('~id="compare_at"[^>]*readonly~', $page) && strpos($page, '"manualCompare":true') !== false);
ok('  and the toman cost, which the save works out itself',
   (bool) preg_match('~id="cost_toman"[^>]*data-auto-cost readonly~', $page));
ok('  the discount is a field of its own', strpos($page, 'name="promo_toman"') !== false);
ok('  the working has a place beside the figures', strpos($page, 'data-sum-price') !== false);
ok('  and the script has the rate and the step',
   strpos($page, '"rate":63900') !== false && strpos($page, '"step":10000') !== false);
ok('the premium column says which currency it was drawn in',
   strpos($page, 'name="flavor_unit" value="aed"') !== false);
$mpage = form($mid, 'view')['html'];
ok('a manual product\'s toman cost is typed as ever',
   !preg_match('~id="cost_toman"[^>]*readonly~', $mpage));
ok('a manual product\'s price is typed as ever',
   (bool) preg_match('~id="price"[^>]*data-auto-price\s*\n?\s*value~', $mpage)
   && !preg_match('~id="price"[^>]*readonly~', $mpage));
ok('  and the discount field waits hidden for dirham mode',
   (bool) preg_match('~<div class="field" data-aed-only hidden>\s*<label for="promo_toman">~u', $mpage));

settings_save(['aed_rate' => '']);
$npage = form($pid, 'view')['html'];
ok('with no rate the page says to type the price for now',
   strpos($npage, 'نرخ درهم هنوز ثبت نشده') !== false && !preg_match('~id="price"[^>]*readonly~', $npage));


head('the migration keeps a struck price typed before it');
/* Before compare_manual existed, a figure typed into «قیمت قبل از تخفیف» on a
   dirham product was stored in compare_at. The migration moves the ones no
   discount explains into the new column, so the owner's figure survives the
   next save. Proved on the real migration: the column is dropped, three
   products are set up the way the old code left them, and it is run again. */
db()->exec('ALTER TABLE products DROP COLUMN compare_manual');
db_query("INSERT INTO products (slug, name_fa, price, compare_at, price_mode, promo_toman, is_active, in_stock)
          VALUES ('zz-auto-mig-typed', 'زد-تایپی', 29500000, 31000000, 'aed', NULL, 1, 1),
                 ('zz-auto-mig-promo', 'زد-تخفیفی', 29500000, 31000000, 'aed', 1500000, 1, 1),
                 ('zz-auto-mig-hand',  'زد-دستی', 1250000, 1500000, 'manual', NULL, 1, 1)");
$migLog = [];
migrate_run($migLog);
$mig = [];
foreach (db_all("SELECT slug, compare_at, compare_manual FROM products WHERE slug LIKE 'zz-auto-mig-%'") as $mr) {
    $mig[(string) $mr['slug']] = $mr;
}
ok('the column is back', (bool) array_filter($migLog, static fn($l) => $l[0] === 'done'
   && strpos($l[1], 'compare_manual') !== false), json_encode($migLog, JSON_UNESCAPED_UNICODE));
ok('a dirham struck price with no discount behind it becomes the typed figure',
   (int) ($mig['zz-auto-mig-typed']['compare_manual'] ?? 0) === 31000000, json_encode($mig));
ok('  one a discount explains stays the discount\'s',
   array_key_exists('zz-auto-mig-promo', $mig) && $mig['zz-auto-mig-promo']['compare_manual'] === null);
ok('  and a manual product\'s is left as it is',
   array_key_exists('zz-auto-mig-hand', $mig) && $mig['zz-auto-mig-hand']['compare_manual'] === null
   && (int) $mig['zz-auto-mig-hand']['compare_at'] === 1500000);


wipe();
settings_save(['aed_rate' => (string) $rateBefore, 'price_rounding_step' => (string) $stepBefore]);
ok('fixtures cleaned up', (int) db_value("SELECT COUNT(*) FROM products WHERE slug LIKE 'zz-auto%'") === 0);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
