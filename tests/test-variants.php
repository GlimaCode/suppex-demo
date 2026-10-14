<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/* ===========================================================================
   Attributes and variations.
   ---------------------------------------------------------------------------
   One property matters more than all the others here: converting a product
   from the delta model to variants must not change a single price. The shop is
   card to card, so the figure on the screen is the figure that gets
   transferred; a conversion that quietly reprices a catalogue is not a
   migration, it is a pricing incident.
   =========================================================================== */

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$pass = 0; $fail = 0; $out = [];
function ok(string $l, bool $c, ?string $x = ''): void {
    global $pass, $fail, $out;
    if ($c) { $pass++; $out[] = "  ok   $l"; }
    else { $fail++; $out[] = "  FAIL $l" . ($x ? "  --  $x" : ''); }
}
function head(string $t): void { global $out; $out[] = "\n== $t =="; }

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/variants.php';

$clean = static function (): void {
    foreach (db_all("SELECT id FROM products WHERE slug LIKE 'zz-var%'") as $r) {
        $id = (int) $r['id'];
        db_query('DELETE vv FROM product_variant_values vv
                    JOIN product_variants v ON v.id = vv.variant_id
                   WHERE v.product_id = ?', [$id]);
        db_query('DELETE FROM product_variants WHERE product_id = ?', [$id]);
        db_query('DELETE av FROM product_attribute_values av
                    JOIN product_attributes a ON a.id = av.attribute_id
                   WHERE a.product_id = ?', [$id]);
        db_query('DELETE FROM product_attributes WHERE product_id = ?', [$id]);
    }
    db_query("DELETE FROM products WHERE slug LIKE 'zz-var%'");
};
$clean();

/* Two sizes, three flavours, one of them dearer and two with pictures. */
$pid = db_insert(
    "INSERT INTO products (slug, name_fa, price, price_mode, is_active, in_stock,
                           cost_aed, profit_percent, stock_qty)
     VALUES ('zz-var', 'زد-ویژگی', 0, 'aed', 1, 1, NULL, 80, NULL)");
db_query(
    "INSERT INTO product_sizes (product_id, ext_id, label, price, cost_aed,
                                profit_percent, stock_qty, sort_order)
     VALUES (?, '900',  '۹۰۰ گرم',  1800000, 20, 80, 4, 0),
            (?, '2270', '۲۲۷۰ گرم', 4100000, 45, 80, 1, 1)",
    [$pid, $pid]);
db_query(
    "INSERT INTO product_flavors (product_id, ext_id, name, cost_delta_aed,
                                  price_delta_toman, image, sort_order)
     VALUES (?, 'choco',   'شکلاتی', NULL, 0,      'uploads/choco.webp', 0),
            (?, 'vanilla', 'وانیلی',  NULL, 0,      '',                   1),
            (?, 'cookies', 'کوکی',    3,    250000, 'uploads/cook.webp',  2)",
    [$pid, $pid, $pid]);

head('converting a product must not move a price');

/* What the delta model charges right now, worked out independently of the
   code under test. */
$expect = [];
foreach (db_all('SELECT ext_id, price, cost_aed, stock_qty FROM product_sizes
                  WHERE product_id = ? ORDER BY sort_order', [$pid]) as $s) {
    foreach (db_all('SELECT ext_id, price_delta_toman, cost_delta_aed, image
                       FROM product_flavors WHERE product_id = ? ORDER BY sort_order',
                    [$pid]) as $f) {
        $expect[$s['ext_id'] . '|' . $f['ext_id']] = [
            'price' => (int) $s['price'] + (int) $f['price_delta_toman'],
            'aed'   => (float) $s['cost_aed'] + (float) ($f['cost_delta_aed'] ?? 0),
            'stock' => $s['stock_qty'],
            'image' => (string) $f['image'],
        ];
    }
}

$built = variants_adopt($pid);
ok('two sizes and three flavours make six variants', $built === 6, (string) $built);

$seen = [];
foreach (variants_list($pid) as $v) {
    $key = (string) $v['ext_id'];
    $seen[$key] = true;
    $e = $expect[$key] ?? null;
    ok('  ' . $key . ' is priced exactly as the delta model priced it',
       $e !== null && (int) $v['price'] === $e['price'],
       $e === null ? 'unexpected combination' : (string) $v['price']);
    ok('    its dirham cost is the size plus the premium',
       $e !== null && abs((float) $v['cost_aed'] - $e['aed']) < 0.001,
       (string) $v['cost_aed']);
    /* Only the size ever counted stock; a flavour never did. */
    ok('    it inherits the size\'s count',
       $e !== null && (string) $v['stock_qty'] === (string) $e['stock']);
    /* The same tub in two sizes is one photograph, so the picture comes from
       the flavour. */
    ok('    and the flavour\'s picture',
       $e !== null && (string) $v['image'] === $e['image'],
       (string) $v['image']);
}
ok('every combination exists and no others do',
   count($seen) === count($expect), count($seen) . ' vs ' . count($expect));

head('the conversion is safe to run twice');
$before = db_all('SELECT ext_id, price, image FROM product_variants
                   WHERE product_id = ? ORDER BY ext_id', [$pid]);
variants_adopt($pid);
$after = db_all('SELECT ext_id, price, image FROM product_variants
                  WHERE product_id = ? ORDER BY ext_id', [$pid]);
ok('a second run adds nothing and moves nothing', $before === $after);

/* Somebody prices a combination by hand afterwards; the next run must respect
   that rather than resetting it to the derived figure. */
db_query("UPDATE product_variants SET price = 9999000
           WHERE product_id = ? AND ext_id = '900|choco'", [$pid]);
variants_adopt($pid);
ok('and it does not overwrite a price set by hand',
   (int) db_value("SELECT price FROM product_variants
                    WHERE product_id = ? AND ext_id = '900|choco'", [$pid]) === 9999000);

head('resolving what a basket already holds');
/* The basket has stored {slug, sizeId, flavor} since long before variants
   existed, so a cart filled last week has to keep working. */
$r = variants_resolve($pid, ['size' => '2270', 'flavor' => 'cookies']);
ok('a size and a flavour name one variant', $r !== null);
ok('  and it is the dearer one',
   $r !== null && (int) $r['price'] === 4350000,
   $r === null ? '—' : (string) $r['price']);

$r2 = variants_resolve($pid, ['flavor' => 'cookies', 'size' => '2270']);
ok('  the order the caller passes them in does not matter',
   $r2 !== null && $r2['id'] === $r['id']);

ok('an incomplete choice resolves to nothing rather than to a guess',
   variants_resolve($pid, ['size' => '2270']) === null);
ok('and so does a combination that was never made',
   variants_resolve($pid, ['size' => '2270', 'flavor' => 'nope']) === null);

head('adding a value fills in only the gaps');
$aid = (int) db_value("SELECT id FROM product_attributes
                        WHERE product_id = ? AND ext_id = 'flavor'", [$pid]);
variants_value_upsert($aid, 'berry', 'توت‌فرنگی', '', 3);
$made = variants_generate($pid);
ok('one new flavour across two sizes makes two rows', $made === 2, (string) $made);
ok('  the six that existed are untouched',
   (int) db_value("SELECT price FROM product_variants
                    WHERE product_id = ? AND ext_id = '900|choco'", [$pid]) === 9999000);
/* A new combination starts at zero rather than at a guess: the readiness page
   already blocks a launch on an unpriced active product, so a zero is loud
   where an invented number would be quiet. */
ok('  a new combination starts unpriced rather than guessed',
   (int) db_value("SELECT price FROM product_variants
                    WHERE product_id = ? AND ext_id = '900|berry'", [$pid]) === 0);
ok('running generate again makes nothing', variants_generate($pid) === 0);

head('a half-typed attribute must not wipe the grid');
/* Naming an attribute is the first half of adding one; typing its options is
   the second, and there is a save in between. This was found by driving the
   real panel: adding «رنگ درب» to a product with three sizes and two flavours
   took all six combinations away, because prune counted an axis that had no
   points on it yet and every variant came up one value short. */
$emptyAttr = variants_attribute_upsert($pid, 'colour', 'رنگ', 5);
$countBefore = (int) db_value('SELECT COUNT(*) FROM product_variants WHERE product_id = ?', [$pid]);
ok('an axis with no values yet generates nothing', variants_generate($pid) === 0);
ok('  and generate deletes nothing',
   (int) db_value('SELECT COUNT(*) FROM product_variants WHERE product_id = ?', [$pid])
   === $countBefore, (string) $countBefore);
ok('  and neither does prune',
   variants_prune($pid) === 0);
ok('    the grid is still all there',
   (int) db_value('SELECT COUNT(*) FROM product_variants WHERE product_id = ?', [$pid])
   === $countBefore,
   (string) db_value('SELECT COUNT(*) FROM product_variants WHERE product_id = ?', [$pid]));

/* Once it has options it becomes a real axis, and then the old combinations
   genuinely are incomplete and should go. */
variants_value_upsert($emptyAttr, 'red', 'قرمز', '', 0);
variants_value_upsert($emptyAttr, 'blue', 'آبی', '', 1);
$pruned = variants_prune($pid);
ok('filling the axis in retires the combinations that predate it',
   $pruned === $countBefore, $pruned . ' of ' . $countBefore);
$rebuilt = variants_generate($pid);
ok('  and the full grid is rebuilt across all three axes',
   (int) db_value('SELECT COUNT(*) FROM product_variants WHERE product_id = ?', [$pid])
   === 2 * 4 * 2,
   (string) db_value('SELECT COUNT(*) FROM product_variants WHERE product_id = ?', [$pid]));

db_query('DELETE FROM product_attribute_values WHERE attribute_id = ?', [$emptyAttr]);
db_query('DELETE FROM product_attributes WHERE id = ?', [$emptyAttr]);
variants_prune($pid);

head('a product with neither sizes nor flavours');
$plain = db_insert(
    "INSERT INTO products (slug, name_fa, price, price_mode, is_active, in_stock)
     VALUES ('zz-var-plain', 'زد-ساده', 900000, 'manual', 1, 1)");
ok('is left alone - one thing on a shelf needs no variant',
   variants_adopt($plain) === 0);
ok('  and reports that it has none', variants_has($plain) === false);


head('the toman cost comes across too');
/* cost_aed was copied and cost_toman was not, so a product bought inside
   Iran came out of the conversion with no cost at all - and every order of
   it would then report zero profit, which is the figure the partnership
   share is computed from. */
$mid = db_insert(
    "INSERT INTO products (slug, name_fa, price, price_mode, is_active, in_stock,
                           cost_toman)
     VALUES ('zz-var-man', 'زد-تومانی', 0, 'manual', 1, 1, 400000)");
db_query("INSERT INTO product_sizes (product_id, ext_id, label, price, cost_toman, sort_order)
          VALUES (?, 's1', 'کوچک', 1000000, 500000, 0),
                 (?, 's2', 'بزرگ', 2000000, 900000, 1)", [$mid, $mid]);
db_query("INSERT INTO product_flavors (product_id, ext_id, name, price_delta_toman, sort_order)
          VALUES (?, 'a', 'الف', 0, 0)", [$mid]);
variants_adopt($mid);

ok('the small size carries its own toman cost',
   (int) db_value("SELECT cost_toman FROM product_variants
                    WHERE product_id = ? AND ext_id = 's1|a'", [$mid]) === 500000,
   (string) db_value("SELECT cost_toman FROM product_variants
                       WHERE product_id = ? AND ext_id = 's1|a'", [$mid]));
ok('  and the large one carries its own',
   (int) db_value("SELECT cost_toman FROM product_variants
                    WHERE product_id = ? AND ext_id = 's2|a'", [$mid]) === 900000);

head('ordering a combination');
require_once SUPPEX_ROOT . '/lib/orders.php';

$oid = db_insert(
    "INSERT INTO products (slug, name_fa, price, price_mode, is_active, in_stock)
     VALUES ('zz-var-ord', 'زد-سفارش', 0, 'manual', 1, 1)");
db_query("INSERT INTO product_sizes (product_id, ext_id, label, price, cost_toman, stock_qty, sort_order)
          VALUES (?, '900', '۹۰۰ گرم', 1800000, 900000, 5, 0)", [$oid]);
db_query("INSERT INTO product_flavors (product_id, ext_id, name, price_delta_toman, sort_order)
          VALUES (?, 'choco', 'شکلاتی', 0, 0),
                 (?, 'cook', 'کوکی', 250000, 1)", [$oid, $oid]);
variants_adopt($oid);

/* The thing the delta model could not express: one combination priced on its
   own, unrelated to size plus premium. */
db_query("UPDATE product_variants SET price = 3333000, cost_toman = 1000000, stock_qty = 3
           WHERE product_id = ? AND ext_id = '900|cook'", [$oid]);

$oCust = ['name' => 'آزمون ترکیب', 'phone' => '09121110000',
          'address' => 'تهران، پلاک ۱', 'postal' => '1234567890'];

$made = order_create(['items' => [['slug' => 'zz-var-ord', 'sizeId' => '900',
                                   'flavorId' => 'cook', 'qty' => 2]],
                      'customer' => $oCust]);
ok('the order goes through', !empty($made['ok']),
   implode(' | ', $made['errors'] ?? []));

$oItem = $made['ok'] ? db_one('SELECT * FROM order_items WHERE order_id = ?',
                              [(int) $made['order']['id']]) : null;
ok('  it is charged the price set on the combination',
   $oItem !== null && (int) $oItem['unit_price'] === 3333000,
   $oItem === null ? '—' : (string) $oItem['unit_price']);
ok('  the cost is the combination\'s, not the size\'s',
   $oItem !== null && (int) $oItem['unit_cost'] === 1000000,
   $oItem === null ? '—' : (string) $oItem['unit_cost']);
ok('  the picking slip names every axis',
   $oItem !== null && strpos((string) $oItem['variant_label'], 'کوکی') !== false
   && strpos((string) $oItem['variant_label'], 'گرم') !== false,
   $oItem === null ? '—' : (string) $oItem['variant_label']);

/* Units come off the combination, and the size's own count is left alone -
   nobody is selling from it any more. */
ok('  the units come off the combination',
   (int) db_value("SELECT stock_qty FROM product_variants
                    WHERE product_id = ? AND ext_id = '900|cook'", [$oid]) === 1,
   (string) db_value("SELECT stock_qty FROM product_variants
                       WHERE product_id = ? AND ext_id = '900|cook'", [$oid]));
ok('  and the size\'s own count is untouched',
   (int) db_value("SELECT stock_qty FROM product_sizes
                    WHERE product_id = ? AND ext_id = '900'", [$oid]) === 5);

head('a basket naming something that is not for sale');
$oBad = order_create(['items' => [['slug' => 'zz-var-ord', 'sizeId' => '900',
                                   'flavorId' => 'nope', 'qty' => 1]],
                      'customer' => $oCust]);
ok('an unknown combination is refused, not approximated', empty($oBad['ok']));

db_query("UPDATE product_variants SET is_active = 0
           WHERE product_id = ? AND ext_id = '900|choco'", [$oid]);
$oOff = order_create(['items' => [['slug' => 'zz-var-ord', 'sizeId' => '900',
                                   'flavorId' => 'choco', 'qty' => 1]],
                      'customer' => $oCust]);
ok('a switched-off combination is refused too', empty($oOff['ok']));
db_query("UPDATE product_variants SET is_active = 1 WHERE product_id = ?", [$oid]);

head('the price lock covers a combination');
/* The chat message opens before the order is recorded, so the figure the
   customer read has to be honoured. One key for the whole combination -
   there is no base and premium left to add back together. */
$oQuote = quote_issue([quote_key('zz-var-ord', '900|cook') => 3000000]);
$oHeld = order_create(['items' => [['slug' => 'zz-var-ord', 'sizeId' => '900',
                                    'flavorId' => 'cook', 'qty' => 1]],
                       'customer' => $oCust, 'quote' => $oQuote]);
ok('a lower quote is honoured',
   !empty($oHeld['ok']) && (int) $oHeld['order']['total'] === 3000000,
   empty($oHeld['ok']) ? 'refused' : (string) $oHeld['order']['total']);

/* And never MORE than the current price, so a price cut reaches a shopper
   holding an old quote as well. */
/* Topped up first: three units minus the two the first order took minus the
   one the quoted order took leaves nothing, and a line refused for want of
   stock would read here as a pricing failure. */
db_query("UPDATE product_variants SET stock_qty = 5
           WHERE product_id = ? AND ext_id = '900|cook'", [$oid]);
$oHigh = quote_issue([quote_key('zz-var-ord', '900|cook') => 9000000]);
$oCut = order_create(['items' => [['slug' => 'zz-var-ord', 'sizeId' => '900',
                                   'flavorId' => 'cook', 'qty' => 1]],
                      'customer' => $oCust, 'quote' => $oHigh]);
ok('  and a stale HIGHER quote never charges more than today',
   !empty($oCut['ok']) && (int) $oCut['order']['total'] === 3333000,
   empty($oCut['ok']) ? 'refused' : (string) $oCut['order']['total']);

db_query("DELETE oi FROM order_items oi JOIN orders o ON o.id = oi.order_id
           WHERE o.customer_name = 'آزمون ترکیب'");
db_query("DELETE FROM orders WHERE customer_name = 'آزمون ترکیب'");


head('the grid in the panel');
/* Driven through the real admin page rather than by calling the library:
   the interesting failures are in the form handling - a row matched by
   position instead of by key, an unticked checkbox read as an edit, an empty
   count stored as zero. */
$gPid = db_insert(
    "INSERT INTO products (slug, name_fa, price, price_mode, is_active, in_stock, category_id)
     VALUES ('zz-var-grid', 'زد-جدول', 1800000, 'manual', 1, 1,
             (SELECT id FROM (SELECT id FROM categories ORDER BY id LIMIT 1) c))");
db_query("INSERT INTO product_sizes (product_id, ext_id, label, price, cost_toman, stock_qty, sort_order)
          VALUES (?, '900', '۹۰۰ گرم', 1800000, 900000, 5, 0),
                 (?, '2270','۲۲۷۰ گرم',4100000, 2000000, 2, 1)", [$gPid, $gPid]);
db_query("INSERT INTO product_flavors (product_id, ext_id, name, price_delta_toman, sort_order)
          VALUES (?, 'choco', 'شکلاتی', 0, 0),
                 (?, 'cook',  'کوکی',   250000, 1)", [$gPid, $gPid]);

$gRun = static function (array $args) {
    return shell_exec(escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__DIR__ . '/gridsave.php') . ' '
        . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1');
};

/* Pressing save is all it takes: the grid builds itself from the axes. */
$gRun([(string) $gPid]);
ok('saving the page builds the grid',
   (int) db_value('SELECT COUNT(*) FROM product_variants WHERE product_id = ?', [$gPid]) === 4,
   (string) db_value('SELECT COUNT(*) FROM product_variants WHERE product_id = ?', [$gPid]));
ok('  and it is priced as the delta model priced it',
   (int) db_value("SELECT price FROM product_variants
                    WHERE product_id = ? AND ext_id = '900|cook'", [$gPid]) === 2050000,
   (string) db_value("SELECT price FROM product_variants
                       WHERE product_id = ? AND ext_id = '900|cook'", [$gPid]));

/* One priced by hand, one uncounted, one switched off. */
$gRun([(string) $gPid,
       '900|choco:1800000:900000:5:1,'
     . '900|cook:3333000:1000000::1,'
     . '2270|choco:4100000:2000000:2:1,'
     . '2270|cook:4350000:2000000:2:']);

ok('a price typed into the grid reaches the row',
   (int) db_value("SELECT price FROM product_variants
                    WHERE product_id = ? AND ext_id = '900|cook'", [$gPid]) === 3333000);
/* Empty is «not counted», which the whole schema distinguishes from none
   left. Storing a zero here would take a product off sale. */
ok('  an empty count is stored as «not counted», not as zero',
   db_value("SELECT stock_qty FROM product_variants
              WHERE product_id = ? AND ext_id = '900|cook'", [$gPid]) === null);
ok('  an unticked row is switched off',
   (int) db_value("SELECT is_active FROM product_variants
                    WHERE product_id = ? AND ext_id = '2270|cook'", [$gPid]) === 0);
ok('  and a ticked one stays on',
   (int) db_value("SELECT is_active FROM product_variants
                    WHERE product_id = ? AND ext_id = '900|choco'", [$gPid]) === 1);

$gBefore = db_all('SELECT ext_id, price, cost_toman, stock_qty, is_active
                     FROM product_variants WHERE product_id = ? ORDER BY ext_id', [$gPid]);
$gRun([(string) $gPid,
       '900|choco:1800000:900000:5:1,'
     . '900|cook:3333000:1000000::1,'
     . '2270|choco:4100000:2000000:2:1,'
     . '2270|cook:4350000:2000000:2:']);
ok('pressing save again changes nothing',
   $gBefore === db_all('SELECT ext_id, price, cost_toman, stock_qty, is_active
                          FROM product_variants WHERE product_id = ? ORDER BY ext_id', [$gPid]));

/* The save that has bitten twice already in this project: adding one option
   must not cost the operator the figures they typed into the others. */
db_query("INSERT INTO product_flavors (product_id, ext_id, name, price_delta_toman, sort_order)
          VALUES (?, 'berry', 'توت‌فرنگی', 0, 2)", [$gPid]);
$gRun([(string) $gPid,
       '900|choco:1800000:900000:5:1,'
     . '900|cook:3333000:1000000::1,'
     . '2270|choco:4100000:2000000:2:1,'
     . '2270|cook:4350000:2000000:2:']);

ok('adding a flavour grows the grid to six',
   (int) db_value('SELECT COUNT(*) FROM product_variants WHERE product_id = ?', [$gPid]) === 6,
   (string) db_value('SELECT COUNT(*) FROM product_variants WHERE product_id = ?', [$gPid]));
ok('  the hand-typed price survives it',
   (int) db_value("SELECT price FROM product_variants
                    WHERE product_id = ? AND ext_id = '900|cook'", [$gPid]) === 3333000);
ok('  so does the switched-off row',
   (int) db_value("SELECT is_active FROM product_variants
                    WHERE product_id = ? AND ext_id = '2270|cook'", [$gPid]) === 0);
ok('  and the two new combinations are there',
   (int) db_value("SELECT COUNT(*) FROM product_variants
                    WHERE product_id = ? AND ext_id LIKE '%berry'", [$gPid]) === 2);


head('a value the owner deleted must stop selling');
/* variants_adopt() upserted every size and flavour onto its axis and never
   took one away, so deleting a flavour in the panel left its point on the
   axis and its combinations for sale. The product page went on offering a
   flavour the shop had removed. */
$rPid = db_insert(
    "INSERT INTO products (slug, name_fa, price, price_mode, is_active, in_stock)
     VALUES ('zz-var-ret', 'زد-بازنشسته', 1000000, 'manual', 1, 1)");
db_query("INSERT INTO product_sizes (product_id, ext_id, label, price, sort_order)
          VALUES (?, 's1', 'کوچک', 1000000, 0)", [$rPid]);
db_query("INSERT INTO product_flavors (product_id, ext_id, name, sort_order)
          VALUES (?, 'a', 'الف', 0), (?, 'b', 'ب', 1)", [$rPid, $rPid]);
variants_adopt($rPid);
ok('two flavours make two combinations',
   (int) db_value('SELECT COUNT(*) FROM product_variants WHERE product_id = ?', [$rPid]) === 2);

db_query("DELETE FROM product_flavors WHERE product_id = ? AND ext_id = 'b'", [$rPid]);
variants_adopt($rPid);
variants_prune($rPid);
variants_generate($rPid);

ok('the deleted flavour is off its axis',
   (int) db_value("SELECT COUNT(*) FROM product_attribute_values v
                     JOIN product_attributes a ON a.id = v.attribute_id
                    WHERE a.product_id = ? AND a.ext_id = 'flavor' AND v.ext_id = 'b'",
                  [$rPid]) === 0);
ok('  and its combination is not sellable',
   (int) db_value("SELECT COUNT(*) FROM product_variants
                    WHERE product_id = ? AND ext_id = 's1|b'", [$rPid]) === 0);
ok('  while the one that stayed is untouched',
   (int) db_value("SELECT COUNT(*) FROM product_variants
                    WHERE product_id = ? AND ext_id = 's1|a'", [$rPid]) === 1);

/* A custom axis is typed into the form and has no legacy row behind it, so
   nothing here may reach in and delete its values. */
$rAttr = variants_attribute_upsert($rPid, 'colour', 'رنگ', 9);
variants_value_upsert($rAttr, 'red', 'قرمز', '', 0);
variants_adopt($rPid);
ok('a custom axis keeps its own values',
   (int) db_value('SELECT COUNT(*) FROM product_attribute_values WHERE attribute_id = ?',
                  [$rAttr]) === 1);

db_query('DELETE vv FROM product_variant_values vv JOIN product_variants v
            ON v.id = vv.variant_id WHERE v.product_id = ?', [$rPid]);
db_query('DELETE FROM product_variants WHERE product_id = ?', [$rPid]);
db_query('DELETE av FROM product_attribute_values av JOIN product_attributes a
            ON a.id = av.attribute_id WHERE a.product_id = ?', [$rPid]);
db_query('DELETE FROM product_attributes WHERE product_id = ?', [$rPid]);
db_query("DELETE FROM products WHERE id = ?", [$rPid]);

$clean();
ok('fixtures cleaned up',
   (int) db_value("SELECT COUNT(*) FROM products WHERE slug LIKE 'zz-var%'") === 0);

echo implode("\n", $out);
echo "\n\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
