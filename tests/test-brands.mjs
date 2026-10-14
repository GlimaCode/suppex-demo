/* ===========================================================================
   Brands, in the browser: the logo wall's tile, and the brand slug.
   ---------------------------------------------------------------------------
   ui.brandCard()      the tile on the home page's logo wall
   repo.brandSlug()    the slug, for the bundled catalogue
   repo.getBrands()    the wall's brands, offline
   repo.getProducts()  a brand's products, offline

   PHP draws the same tile (brand_card_html() in lib/brands.php) on brands.php
   and brand.php, and makes the same slug (brand_slug()). Two copies of one
   thing drift, so this asks PHP for its answer and holds the two together:
   a tile that differs between the home page and brands.php is a wall that
   looks like two designs.
   =========================================================================== */
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import vm from 'node:vm';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const ROOT = new URL('../', import.meta.url);
const JS = (f) => new URL('assets/js/' + f, ROOT);

let pass = 0;
let fail = 0;
const ok = (label, cond, extra = '') => {
  if (cond) { pass++; console.log('  ok   ' + label); }
  else { fail++; console.log('  FAIL ' + label + (extra ? '  --  ' + extra : '')); }
};
const head = (t) => console.log('\n== ' + t + ' ==');

/* config, the bundled catalogue, repo and ui - no DOM needed for any of it. */
const sb = { console, setTimeout, clearTimeout, Promise, URLSearchParams };
sb.window = sb;
vm.createContext(sb);
for (const f of ['config.js', 'catalog.js', 'repo.js', 'ui.js']) {
  vm.runInContext(fs.readFileSync(JS(f), 'utf8'), sb);
}
const { ui, repo, catalog } = sb.SUPPEX;
/* The shop runs against the API; the offline branch is the bundled demo's,
   and what this file checks. repo reads the setting on every call. */
sb.SUPPEX.config.api.baseUrl = null;

head('the tile');
{
  const withLogo = ui.brandCard({ slug: 'muscletech', name: 'MuscleTech', nameFa: 'ماسل تک',
    logo: '/uploads/brands/2026/09/a.webp', count: 4 });
  ok('it links to the brand\'s page', withLogo.indexOf('<a class="bcard" href="brand.php?b=muscletech">') === 0);
  ok('the logo on the plate, named for a screen reader',
     withLogo.indexOf('<span class="bcard__plate"><img class="bcard__logo" src="/uploads/brands/2026/09/a.webp" alt="MuscleTech"') !== -1);
  ok('  the Persian name under it', withLogo.indexOf('<span class="bcard__name" dir="auto">ماسل تک</span>') !== -1);
  ok('  and how many products, in Persian digits', withLogo.indexOf('<span class="num">۴</span> محصول') !== -1);

  const logoOnly = ui.brandCard({ slug: 'x', name: 'Xtend', nameFa: '', logo: '/l.png', count: 1 });
  ok('a logo with no Persian name: the name under it', logoOnly.indexOf('<span class="bcard__name" dir="auto">Xtend</span>') !== -1);

  const bare = ui.brandCard({ slug: 'body-attack', name: 'Body Attack', nameFa: '', logo: '', count: 2 });
  ok('no logo: the name stands on the plate', bare.indexOf('<span class="bcard__word lat" dir="ltr">Body Attack</span>') !== -1);
  /* Every tile the same three parts, so a row of them reads level. */
  ok('  and every tile still has its name line', bare.indexOf('<span class="bcard__name" dir="auto">Body Attack</span>') !== -1);
  const faOnly = ui.brandCard({ slug: 'k', name: 'Karen', nameFa: 'کارن', logo: '', count: 1 });
  ok('  the Persian name, when there is one', faOnly.indexOf('<span class="bcard__name" dir="auto">کارن</span>') !== -1);

  const evil = ui.brandCard({ slug: 'a"b', name: '<b>x</b>', nameFa: '"q"', logo: '', count: 1 });
  ok('everything is escaped', evil.indexOf('<b>') === -1 && evil.indexOf('href="brand.php?b=a%22b"') !== -1
     && evil.indexOf('&quot;q&quot;') !== -1);
  ok('a Persian slug is encoded in the link', ui.brandHref('کارن') === 'brand.php?b=' + encodeURIComponent('کارن'));
}

head('the same tile and the same slug as PHP');
{
  const brands = [
    { slug: 'muscletech', name: 'MuscleTech', nameFa: 'ماسل تک', logo: '/uploads/brands/2026/09/a.webp', count: 4 },
    { slug: 'body-attack', name: 'Body Attack', nameFa: '', logo: '', count: 2 },
    { slug: 'x', name: 'Xtend', nameFa: '', logo: '/l.png', count: 13 },
    { slug: 'کارن', name: 'Karen', nameFa: 'کارن', logo: '', count: 1 },
  ];
  const names = ['Body Attack', ' MuscleTech ', 'Optimum  Nutrition', 'Nutrex-Research', 'Dymatize ISO·100',
                 'کارن ۲۰۲۴', '٣٠٠ Labs', '!!!', '', 'BSN',
                 /* The catalogue sheets' ways of writing one brand. */
                 'Optimum Nutrition (اپتیموم نوتریشن)', 'Dymatize (دایماتیز / دایمتایز)',
                 'Karen Pharma / PNC (کارن / پی ان سی)', 'Karen Pharma (PNC)', 'Doobis (Dubis Nutrition)',
                 'Xtend (Cellucor / Nutrabolt)', '(کاله پرو)', '(PNC)', 'S/N Labs'];

  const helper = path.join(os.tmpdir(), 'suppex-brand-parity.php');
  fs.writeFileSync(helper,
    '<?php require ' + JSON.stringify(fileURLToPath(new URL('lib/util.php', ROOT))) + ';\n' +
    'require ' + JSON.stringify(fileURLToPath(new URL('lib/brands.php', ROOT))) + ';\n' +
    '$in = json_decode(file_get_contents("php://stdin"), true);\n' +
    '$out = ["cards" => [], "slugs" => []];\n' +
    'foreach ($in["brands"] as $b) { $out["cards"][] = brand_card_html($b); }\n' +
    'foreach ($in["names"] as $n) { $out["slugs"][] = brand_slug($n); }\n' +
    'echo json_encode($out, JSON_UNESCAPED_UNICODE);');

  const php = process.env.SUPPEX_PHP || 'php';
  let theirs = null;
  try {
    theirs = JSON.parse(execFileSync(php, [helper],
      { input: JSON.stringify({ brands, names }), encoding: 'utf8' }));
  } catch (e) {
    console.log('  skip   PHP is not runnable here (' + php + ')');
  }
  fs.unlinkSync(helper);

  if (theirs) {
    /* PHP writes a no-break space as &nbsp; and an apostrophe as &#039;; the
       browser builds the same characters. Compared as what the page shows. */
    const same = (s) => s.replace(/&nbsp;/g, ' ').replace(/&#0?39;/g, "'");
    brands.forEach((b, i) => {
      ok('the tile for «' + b.name + '» is the same on both sides',
         same(ui.brandCard(b)) === same(theirs.cards[i]),
         '\n      js:  ' + ui.brandCard(b) + '\n      php: ' + theirs.cards[i]);
    });
    names.forEach((n, i) => {
      ok('«' + n + '» has the same slug on both sides', repo.brandSlug(n) === theirs.slugs[i],
         'js=' + JSON.stringify(repo.brandSlug(n)) + ' php=' + JSON.stringify(theirs.slugs[i]));
    });
  }
}

head('offline: the bundled catalogue\'s brands');
{
  const list = await repo.getBrands();
  const counted = {};
  catalog.products.forEach((p) => {
    const s = repo.brandSlug(p.brand);
    if (s) { counted[s] = (counted[s] || 0) + 1; }
  });
  ok('one entry per brand', list.length === Object.keys(counted).length && list.length > 0,
     list.map((b) => b.slug).join(','));
  ok('  each with its count', list.every((b) => b.count === counted[b.slug]));
  ok('  most products first', list.every((b, i) => i === 0 || list[i - 1].count >= b.count));
  const first = list[0];
  const mine = await repo.getProducts({ brand: first.name.toUpperCase() });
  ok('a brand\'s products, however it is typed', mine.length === first.count
     && mine.every((p) => repo.brandSlug(p.brand) === first.slug));
  ok('an unknown brand has none', (await repo.getProducts({ brand: 'no such brand' })).length === 0);
}

console.log('\n----------------------------------------\npassed: ' + pass + '    failed: ' + fail);
process.exit(fail > 0 ? 1 : 0);
