/* ===========================================================================
   The product form's price script, held to the PHP it mirrors.
   ---------------------------------------------------------------------------
   In dirham mode the form shows the selling price while the purchase price
   and profit are typed, and the save then works the same price out again in
   lib/pricing.php. Two copies of one formula drift, and the drift is the one
   thing the owner asked this feature to remove - «اینجوری دقیق تره». So the
   page's own functions are lifted out of admin/product-edit.php and run
   against PHP's over a few thousand combinations of dirham price, rate,
   profit, ratio, discount and rounding step: every price, struck price,
   toman cost and profit has to come out identical.
   =========================================================================== */
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const ROOT = new URL('../', import.meta.url);
const PAGE = fileURLToPath(new URL('admin/product-edit.php', ROOT));
const LIB  = (f) => fileURLToPath(new URL('lib/' + f, ROOT));

let pass = 0, fail = 0;
const ok = (label, cond, extra = '') => {
  if (cond) { pass++; console.log('  ok   ' + label); }
  else { fail++; console.log('  FAIL ' + label + (extra ? '  --  ' + extra : '')); }
};
const head = (t) => console.log('\n== ' + t + ' ==');

head('the page carries the arithmetic');
const src   = fs.readFileSync(PAGE, 'utf8');
const start = src.indexOf('The price a dirham product will sell at, worked out while it is typed.');
const from  = src.indexOf("  var FA = '", start);
const to    = src.indexOf('  function isAed() {', from);
ok('the price script is there', start > 0 && from > start && to > from);

let fns = null;
if (start > 0 && from > start && to > from) {
  const CFG = { step: 10000, share: 0.5 };
  fns = new Function('CFG', 'form',
    src.slice(from, to) +
    '\nreturn { aedNum: aedNum, toman: toman, phpRound: phpRound, fromCost: fromCost,' +
    ' profitAt: profitAt, pctFrom: pctFrom, slugify: slugify, CFG: CFG };')(CFG, null);
}
ok('  and it lifts out whole', !!fns && typeof fns.fromCost === 'function');

/* PHP's answers, from the real functions, in one process. The rounding step
   is a setting; it is put straight into the settings cache so no database is
   needed to ask. */
const helper = path.join(os.tmpdir(), 'suppex-autoprice-parity.php');
fs.writeFileSync(helper, `<?php
declare(strict_types=1);
require ${JSON.stringify(LIB('util.php'))};
require ${JSON.stringify(LIB('settings.php'))};
require ${JSON.stringify(LIB('pricing.php'))};
$cache = new ReflectionProperty(Settings::class, 'cache');
$cache->setAccessible(true);
$in = json_decode(file_get_contents('php://stdin'), true);
$out = ['parse' => [], 'units' => [], 'pct' => []];
foreach ($in['parse'] as $s) { $out['parse'][] = pricing_parse_aed($s); }
foreach ($in['units'] as $u) {
    $cache->setValue(null, ['price_rounding_step' => (string) $u['step']]);
    $cost   = (int) round($u['aed'] * $u['rate']);
    $profit = pricing_profit(['profit_toman' => $u['typed'], 'profit_percent' => $u['pct']], $cost);
    $c      = pricing_from_cost($cost, $profit, $u['promo']);
    $out['units'][] = [$cost, $profit, $c['price'], $c['compare_at']];
}
foreach ($in['pct'] as [$cost, $profit]) { $out['pct'][] = pricing_percent_from($cost, $profit); }
echo json_encode($out);
`);

const parseCases = ['350', '۳۵۰', '42.5', '42٫5', '۴۲/۵', '42,50', '1,250.75', '۲۲٬۳۶۵',
  '42 درهم', '.5', '0', '', '  ', 'درهم', '12.345', '12.355', '99.999', '١٢٣٫٤٥', '7‌5'];

const units = [];
for (const aed of [1, 12.5, 42.55, 42.555, 99.99, 350, 350.5, 1234.56]) {
  for (const rate of [25000, 63900, 63901, 71234.5]) {
    for (const typed of [0, 150000, 1705000, 7200000]) {
      for (const pct of [null, 32.193, 50, 62.782]) {
        for (const promo of [0, 100000, 1500000, 99999999]) {
          for (const step of [1, 1000, 10000]) {
            units.push({ aed, rate, typed, pct, promo, step });
          }
        }
      }
    }
  }
}
const pctCases = [];
for (const cost of [1, 2715750, 22365000, 23004000, 5112000, 987654321]) {
  for (const profit of [0, 1, 150000, 1705000, 7200000, 3000000]) { pctCases.push([cost, profit]); }
}

const php = process.env.SUPPEX_PHP || 'php';
let theirs = null;
try {
  theirs = JSON.parse(execFileSync(php, [helper], {
    input: JSON.stringify({ parse: parseCases, units, pct: pctCases }),
    encoding: 'utf8', maxBuffer: 64 * 1024 * 1024,
  }));
} catch (e) {
  console.log('  skip   PHP is not runnable here (' + php + '): ' + String(e.message).split('\n')[0]);
}
fs.unlinkSync(helper);

if (fns && theirs) {
  head('a dirham figure reads the same on both sides');
  parseCases.forEach((s, i) => {
    const js = fns.aedNum(s);
    const ph = theirs.parse[i] === null ? 0 : theirs.parse[i];
    ok('  ' + JSON.stringify(s) + ' → ' + js, js === ph, 'js=' + js + ' php=' + JSON.stringify(theirs.parse[i]));
  });

  head('every price, cost, profit and struck price agrees');
  let bad = [];
  units.forEach((u, i) => {
    fns.CFG.step = u.step;
    const cost = fns.phpRound(u.aed * u.rate);
    const profit = fns.profitAt(u.typed, u.pct, cost);
    const r = fns.fromCost(cost, profit, u.promo);
    const [pc, pp, pprice, pcmp] = theirs.units[i];
    if (cost !== pc || profit !== pp || r.price !== pprice || r.compare !== pcmp) {
      bad.push({ u, js: [cost, profit, r.price, r.compare], php: theirs.units[i] });
    }
  });
  ok(units.length + ' combinations, not one different', bad.length === 0,
     bad.length + ' differ, first: ' + JSON.stringify(bad[0]));

  const owner = (() => {
    fns.CFG.step = 10000;
    const cost = fns.phpRound(350 * 63900);
    return fns.fromCost(cost, fns.profitAt(7200000, fns.pctFrom(cost, 7200000), cost), 0);
  })();
  ok('the owner\'s tub: 350 dirhams at 63,900 with 7,200,000 profit is 29,570,000',
     owner.price === 29570000 && owner.compare === null, JSON.stringify(owner));
  const onSale = fns.fromCost(22365000, 8635000, 1500000);
  ok('  and 8,635,000 profit less 1,500,000 is 29,500,000 with 31,000,000 struck',
     onSale.price === 29500000 && onSale.compare === 31000000, JSON.stringify(onSale));
  const capped = fns.fromCost(22365000, 7200000, 5000000);
  ok('  a discount beyond half the profit is cut to half, and the page knows it asked for more',
     capped.promo === 3600000 && capped.asked === 5000000 && capped.price === 25970000,
     JSON.stringify(capped));

  head('the ratio a typed profit implies agrees');
  bad = [];
  pctCases.forEach(([c, p], i) => {
    const js = fns.pctFrom(c, p);
    if (js !== theirs.pct[i]) { bad.push([c, p, js, theirs.pct[i]]); }
  });
  ok(pctCases.length + ' ratios, the same to the third decimal', bad.length === 0, JSON.stringify(bad.slice(0, 3)));
}

head('the script only proposes');
/* The save prices the product itself; nothing here may send or store. */
const script = src.slice(start, src.indexOf('</script>', start));
ok('no fetch, XHR or form submit in it', !/\bfetch\s*\(|XMLHttpRequest|\.submit\s*\(/.test(script));
ok('and it builds its Unicode pattern at run time, so an old browser loses only the slug',
   script.indexOf("new RegExp('[^\\\\p{L}\\\\p{N}]+', 'gu')") !== -1);

console.log('\n----------------------------------------');
console.log(`passed: ${pass}    failed: ${fail}`);
process.exit(fail ? 1 : 0);
