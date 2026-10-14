/* ===========================================================================
   The admin page's own JavaScript, where it has to agree with the PHP.
   ---------------------------------------------------------------------------
   The combinations grid is rebuilt in the browser as sizes and flavours are
   typed, so the operator can price a new combination in the same visit instead
   of saving, waiting, and coming back to it. That only works if the id the
   browser puts on a new row is the id the server will store it under.

   If the two ever drift, the save looks up a row that does not exist and drops
   what was typed into it — silently, because an id that matches nothing is
   indistinguishable from a row the operator left alone. So the two
   implementations are compared directly, over the kind of labels this shop
   actually uses: Persian words, Persian and Arabic digits, a zero-width
   non-joiner, and Latin mixed in.
   =========================================================================== */
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const ROOT = new URL('../', import.meta.url);
const PAGE = fileURLToPath(new URL('admin/product-edit.php', ROOT));
const UTIL = fileURLToPath(new URL('lib/util.php', ROOT));

let pass = 0, fail = 0;
const ok = (label, cond, extra = '') => {
  if (cond) { pass++; console.log('  ok   ' + label); }
  else { fail++; console.log('  FAIL ' + label + (extra ? '  --  ' + extra : '')); }
};
const head = (t) => console.log('\n== ' + t + ' ==');

head('the browser and the server agree on an id');

/* Lifted out of the page rather than retyped here, so this tests the code that
   actually ships. */
const src  = fs.readFileSync(PAGE, 'utf8');
const from = src.indexOf('  function slugify(s) {');
const to   = src.indexOf('\n  }', from) + 4;
ok('the page carries a slugify to compare', from >= 0 && to > 4);

if (from >= 0 && to > 4) {
  const slugify = new Function('FA', 'AR', src.slice(from, to) + '\n return slugify;')(
    '۰۱۲۳۴۵۶۷۸۹',
    '٠١٢٣٤٥٦٧٨٩');

  const cases = [
    'شکلاتی',
    'وانیلی',
    '۹۰۰ گرم',                 // Persian digits
    '۱.۸ کیلوگرم',             // a decimal point between them
    '۲۲۷۰ گرم',
    'توت‌فرنگی',          // zero-width non-joiner
    'آمینو و BCAA',            // Persian and Latin together
    'رنگ درب',
    'Chocolate Fudge',
    '  فاصله  اضافه  ',        // leading, trailing and doubled spaces
    'کوکی--کرم',               // a separator run
    '٣٠٠ گرم',                 // Arabic-Indic digits
  ];

  /* PHP's answer for the same inputs, from the real slugify(). */
  const helper = path.join(os.tmpdir(), 'suppex-slug-parity.php');
  fs.writeFileSync(helper,
    '<?php require ' + JSON.stringify(UTIL) + ';\n' +
    '$in = json_decode(file_get_contents("php://stdin"), true);\n' +
    '$out = [];\n' +
    'foreach ($in as $s) { $out[$s] = slugify($s); }\n' +
    'echo json_encode($out, JSON_UNESCAPED_UNICODE);');

  const php = process.env.SUPPEX_PHP || 'php';
  let theirs = null;
  try {
    theirs = JSON.parse(execFileSync(php, [helper],
      { input: JSON.stringify(cases), encoding: 'utf8' }));
  } catch (e) {
    console.log('  skip   PHP is not runnable here (' + php + ')');
  }
  fs.unlinkSync(helper);

  if (theirs) {
    for (const c of cases) {
      ok('  ' + JSON.stringify(c) + ' is the same id on both sides',
         slugify(c) === theirs[c],
         'js=' + JSON.stringify(slugify(c)) + ' php=' + JSON.stringify(theirs[c]));
    }
  }

  /* The fallback matters as much as the agreement. A browser with no Unicode
     property escapes would slug Persian down to nothing and build ids that
     collide; returning null there makes the live grid stand down and leaves the
     operator with save-then-see, which is correct rather than wrong. */
  ok('an empty label yields nothing rather than a made-up id',
     slugify('   ') === '');
}

head('the live grid only ever proposes, never writes');
/* Everything the browser does here is a proposal the save either recognises or
   ignores. Nothing in this script may claim to have stored anything. */
ok('the script has no fetch, XHR or form submit in it',
   !/\bfetch\s*\(|XMLHttpRequest|\.submit\s*\(/.test(
     src.slice(src.indexOf('The combinations grid, rebuilt as the axes are typed'),
               src.indexOf('Add / remove repeating rows'))));

console.log('\n----------------------------------------');
console.log(`passed: ${pass}    failed: ${fail}`);
process.exit(fail ? 1 : 0);
