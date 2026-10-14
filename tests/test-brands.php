<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/* ===========================================================================
   Brands: the logo wall, brands.php and brand.php.
   ---------------------------------------------------------------------------
   A brand is not a record - it is what the brand text on the products has in
   common (lib/brands.php). So the checks that matter are:

     - two spellings of one brand are ONE brand. The live catalogue has «Body
       Attack» and «Body attack» today; without this the wall shows it twice
       and each page has half its products;
     - a brand the owner hid has no page, is not on the wall, not in the
       sitemap, and its name on a product page is not a link to a 404 - four
       places, one rule (brands_list()), and each checked here;
     - a brand whose products are all switched off has nothing on sale, and
       is treated like a hidden one;
     - the product links reach the served HTML, as on a category page.

   The suite's products are given brands for the run and put back after.
   =========================================================================== */

$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = 'suppex.ir';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTPS']          = 'on';
$_SERVER['REQUEST_URI']    = '/brand.php';
$_SERVER['SCRIPT_NAME']    = '/brand.php';

$pass = 0; $fail = 0; $out = [];
function ok(string $l, bool $c, ?string $x = ''): void {
    global $pass, $fail, $out;
    if ($c) { $pass++; $out[] = "  ok   $l"; }
    else { $fail++; $out[] = "  FAIL $l" . ($x ? "  --  $x" : ''); }
}
function head(string $t): void { global $out; $out[] = "\n== $t =="; }

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/products.php';
require_once SUPPEX_ROOT . '/lib/pages.php';
require_once SUPPEX_ROOT . '/lib/seo.php';

$render = function (string $file, array $get): string {
    $_GET = $get; $_POST = []; $_REQUEST = $get;
    $_SERVER['REQUEST_URI'] = '/' . $file;
    $_SERVER['SCRIPT_NAME'] = '/' . $file;
    http_response_code(200);
    ob_start();
    include SUPPEX_ROOT . '/' . $file;
    return (string) ob_get_clean();
};

/* What the products were before this suite, put back at the end. */
$before = db_all('SELECT id, brand, is_active FROM products ORDER BY id');
$restore = static function () use ($before): void {
    foreach ($before as $r) {
        db_query('UPDATE products SET brand = ?, is_active = ? WHERE id = ?',
            [$r['brand'], (int) $r['is_active'], (int) $r['id']]);
    }
    if (brands_ready()) {
        db_query('DELETE FROM brands');
    }
};

head('a brand\'s slug');
foreach ([
    'Body Attack'          => 'body-attack',
    ' MuscleTech '         => 'muscletech',
    'Optimum  Nutrition'   => 'optimum-nutrition',
    'Nutrex-Research'      => 'nutrex-research',
    'Dymatize ISO·100'     => 'dymatize-iso-100',
    'کارن ۲۰۲۴'            => 'کارن-2024',
    '!!!'                  => '',
    ''                     => '',
] as $in => $want) {
    ok('«' . $in . '» → «' . $want . '»', brand_slug((string) $in) === $want, brand_slug((string) $in));
}
ok('never longer than 110', mb_strlen(brand_slug(str_repeat('ab ', 90))) <= 110);
ok('and never ends on a hyphen', substr(brand_slug(str_repeat('ab ', 90)), -1) !== '-');
ok('its page', brand_url('body-attack') === 'brand.php?b=body-attack'
   && brand_url('کارن') === 'brand.php?b=' . rawurlencode('کارن'));

head('the catalogue\'s ways of writing one brand come to one');
/* Every one of these is in the catalogue sheets on D:, and each would be a
   brand of its own on the wall without brand_parts(). */
foreach ([
    'Optimum Nutrition (اپتیموم نوتریشن)'   => ['Optimum Nutrition', 'اپتیموم نوتریشن', 'optimum-nutrition'],
    'Dymatize (دایماتیز / دایمتایز)'          => ['Dymatize', 'دایماتیز', 'dymatize'],
    'Karen Pharma / PNC (کارن / پی ان سی)'   => ['Karen Pharma', 'کارن', 'karen-pharma'],
    'Karen Pharma (PNC)'                      => ['Karen Pharma', '', 'karen-pharma'],
    'Karen Pharma (کارن)'                     => ['Karen Pharma', 'کارن', 'karen-pharma'],
    'Doobis / Dubis Nutrition (دوبیس)'        => ['Doobis', 'دوبیس', 'doobis'],
    'Doobis (Dubis Nutrition)'                => ['Doobis', '', 'doobis'],
    'Xtend (Cellucor / Nutrabolt)'            => ['Xtend', '', 'xtend'],
    'Universal Nutrition (Animal)'            => ['Universal Nutrition', '', 'universal-nutrition'],
    '(کاله پرو)'                              => ['کاله پرو', 'کاله پرو', 'کاله-پرو'],
    '(PNC)'                                   => ['', '', ''],
] as $raw => [$name, $fa, $slug]) {
    $parts = brand_parts($raw);
    ok("«{$raw}» → «{$name}»" . ($fa !== '' ? " / «{$fa}»" : ''),
       $parts['name'] === $name && $parts['fa'] === $fa && brand_slug($raw) === $slug,
       json_encode([$parts, brand_slug($raw)], JSON_UNESCAPED_UNICODE));
}
ok('a brand called «S/N» is not split on its own slash', brand_parts('S/N Labs')['name'] === 'S/N Labs');

head('the table is in place');
ok('the migration made brands', brands_ready());

/* The scene: seven products, three brands, one of them spelled two ways. */
$ids = array_map(static fn(array $r): int => (int) $r['id'],
    db_all('SELECT id FROM products WHERE is_active = 1 ORDER BY id LIMIT 7'));
ok('there are products to test with', count($ids) === 7, (string) count($ids));
db_query("UPDATE products SET brand = ''");
$set = static function (int $i, string $brand, int $active = 1) use ($ids): void {
    db_query('UPDATE products SET brand = ?, is_active = ? WHERE id = ?', [$brand, $active, $ids[$i]]);
};
$set(0, 'Body Attack');
$set(1, 'Body attack');
$set(2, 'MuscleTech');
$set(3, 'MuscleTech');
$set(4, 'MuscleTech', 0);          // switched off
$set(5, 'Applied Nutrition');
$set(6, '!!!');                    // no usable name, so no brand
db_query('DELETE FROM brands');
$slugOf = static function (int $i) use ($ids): string {
    return (string) db_value('SELECT slug FROM products WHERE id = ?', [$ids[$i]]);
};

head('brands come from the products');
$list = brands_list();
$slugs = array_column($list, 'slug');
ok('three brands, and nothing for a name with no letters in it', $slugs !== []
   && count($slugs) === 3 && !in_array('', $slugs, true), implode(',', $slugs));
$by = array_column($list, null, 'slug');
ok('«Body Attack» and «Body attack» are one brand', isset($by['body-attack'])
   && $by['body-attack']['count'] === 2, json_encode($by['body-attack'] ?? null));
ok('  shown by the spelling with the capitals, on a tie', ($by['body-attack']['name'] ?? '') === 'Body Attack');
ok('  and the panel sees both spellings', count($by['body-attack']['spellings'] ?? []) === 2);
ok('a switched-off product is not counted', ($by['muscletech']['count'] ?? 0) === 2);
ok('  though the panel still sees it', (array_column(brands_all(true), null, 'slug')['muscletech']['total'] ?? 0) === 3);
ok('with nothing set, most products first, then by name',
   $slugs === ['body-attack', 'muscletech', 'applied-nutrition'], implode(',', $slugs));
ok('the API sends what a tile draws, and nothing about the panel',
   array_keys(brand_api($list[0])) === ['slug', 'name', 'nameFa', 'logo', 'count']);

head('the Persian name comes from the products\' brackets');
$set(5, 'Applied Nutrition (اپلاید نوتریشن)');
/* A card sets the brand in spaced capitals: the bracket stays off it. */
$an5 = product_get($slugOf(5));
ok('the product carries the brand\'s name, not the catalogue\'s bracket',
   ($an5['brand'] ?? '') === 'Applied Nutrition', (string) ($an5['brand'] ?? ''));
ok('  in the listing too', in_array('Applied Nutrition', array_column(products_list(['brand' => 'applied-nutrition']), 'brand'), true));
ok('  and its brand link is the same', ($an5['brandSlug'] ?? '') === 'applied-nutrition');
foreach (['Karen Pharma / PNC (کارن / پی ان سی)' => 'Karen Pharma', 'MuscleTech' => 'MuscleTech',
          '(کاله پرو)' => 'کاله پرو', '(PNC)' => '(PNC)'] as $raw => $want) {
    ok("printed «{$raw}» as «{$want}»", brand_display_name($raw) === $want, brand_display_name($raw));
}
$an = array_column(brands_all(true), null, 'slug')['applied-nutrition'] ?? [];
ok('the brackets make no brand of their own: the one brand keeps its plain slug',
   ($an['slug'] ?? '') === 'applied-nutrition' && ($an['count'] ?? 0) === 1
   && count(array_filter(array_column(brands_all(true), 'slug'),
        static fn(string $s): bool => strpos($s, 'applied') === 0)) === 1);
ok('  the name is the name', ($an['name'] ?? '') === 'Applied Nutrition');
ok('  and the Persian name is the bracket\'s', ($an['nameFa'] ?? '') === 'اپلاید نوتریشن'
   && ($an['nameFaSaved'] ?? 'x') === '');
brand_save('applied-nutrition', ['name_fa' => 'اپلاید', 'is_visible' => true]);
ok('  until the owner types one', (brand_get('applied-nutrition')['nameFa'] ?? '') === 'اپلاید');
db_query("DELETE FROM brands WHERE slug = 'applied-nutrition'");
$set(5, 'Applied Nutrition');

head('what the owner adds');
brand_save('muscletech', ['name_fa' => 'ماسل تک', 'logo' => '/uploads/brands/2026/09/test-logo.webp',
    'blurb' => 'یک خط دربارهٔ برند.', 'sort_order' => -1, 'is_visible' => true]);
$list = brands_list();
ok('the owner\'s order comes first', ($list[0]['slug'] ?? '') === 'muscletech');
ok('  with its Persian name, logo and line', ($list[0]['nameFa'] ?? '') === 'ماسل تک'
   && ($list[0]['logo'] ?? '') === '/uploads/brands/2026/09/test-logo.webp'
   && ($list[0]['blurb'] ?? '') === 'یک خط دربارهٔ برند.');
ok('  and the name still comes from the products when none is typed', ($list[0]['name'] ?? '') === 'MuscleTech'
   && ($list[0]['nameSaved'] ?? 'x') === '');
brand_save('muscletech', ['name' => 'Muscletech Inc', 'name_fa' => 'ماسل تک', 'sort_order' => -1, 'is_visible' => true]);
ok('a typed name wins', (brand_get('muscletech')['name'] ?? '') === 'Muscletech Inc');
brand_save('muscletech', ['name_fa' => 'ماسل تک', 'logo' => '/uploads/brands/2026/09/test-logo.webp',
    'blurb' => 'یک خط دربارهٔ برند.', 'sort_order' => -1, 'is_visible' => true]);
ok('saving again updates the row rather than adding one',
   (int) db_value("SELECT COUNT(*) FROM brands WHERE slug = 'muscletech'") === 1);

brand_save('applied-nutrition', ['is_visible' => false]);
ok('a hidden brand is not on the wall', !in_array('applied-nutrition', array_column(brands_list(), 'slug'), true));
ok('  has no page', brand_get('applied-nutrition') === null);
ok('  and a product does not link to it', brand_link_slug('Applied Nutrition') === null);
ok('  while a shown one does', brand_link_slug('MuscleTech') === 'muscletech');
ok('  the panel still lists it, as hidden',
   (array_column(brands_all(true), null, 'slug')['applied-nutrition']['visible'] ?? true) === false);
ok('the lookup takes the brand as it is typed', (brand_get('MuscleTech')['slug'] ?? '') === 'muscletech'
   && (brand_get(' BODY ATTACK ')['slug'] ?? '') === 'body-attack');
foreach (['', 'nope', '../../lib/db', '!!!'] as $bad) {
    ok("no brand «{$bad}»", brand_get($bad) === null);
}

brand_save('left-behind', ['name_fa' => 'برندی که دیگر محصولی ندارد', 'is_visible' => true]);
$panel = array_column(brands_all(true), null, 'slug');
ok('a saved brand no product names any more stays in the panel, empty',
   isset($panel['left-behind']) && $panel['left-behind']['total'] === 0);
ok('  and nowhere on the site', brand_get('left-behind') === null);

head('products by brand');
$bodyAttack = array_column(products_list(['brand' => 'body-attack']), 'slug');
ok('both spellings\' products', count($bodyAttack) === 2
   && in_array($slugOf(0), $bodyAttack, true) && in_array($slugOf(1), $bodyAttack, true), implode(',', $bodyAttack));
ok('  however the brand is typed', count(products_list(['brand' => 'BODY attack'])) === 2);
ok('only the products on sale', count(products_list(['brand' => 'muscletech'])) === 2);
ok('an unknown brand has none', products_list(['brand' => 'nope']) === []);
ok('the product carries where its brand links', (product_get($slugOf(2))['brandSlug'] ?? null) === 'muscletech');
ok('  and nothing for a hidden brand', array_key_exists('brandSlug', (array) product_get($slugOf(5)))
   && product_get($slugOf(5))['brandSlug'] === null);

head('brand.php');
$page = $render('brand.php', ['b' => 'muscletech']);
ok('the Persian name is the heading', preg_match('~<h1[^>]*>\s*ماسل تک\s*</h1>~u', $page) === 1);
ok('  with the Latin name under it', strpos($page, 'brand-head__latin') !== false && strpos($page, '>MuscleTech<') !== false);
ok('  the logo on its plate', strpos($page, 'src="/uploads/brands/2026/09/test-logo.webp"') !== false);
ok('  the owner\'s line', mb_strpos($page, 'یک خط دربارهٔ برند.') !== false);
ok('the title is what people type', mb_strpos($page, '<title>خرید و قیمت محصولات ماسل تک |') !== false);
ok('its products are links in the served page',
   strpos($page, 'product.php?p=' . rawurlencode($slugOf(2))) !== false
   && strpos($page, 'product.php?p=' . rawurlencode($slugOf(3))) !== false);
ok('  not the switched-off one', strpos($page, 'product.php?p=' . rawurlencode($slugOf(4)) . '"') === false);
ok('indexable, at its own address',
   strpos($page, '<meta name="robots" content="index, follow">') !== false
   && strpos($page, '<link rel="canonical" href="https://suppex.ir/brand.php?b=muscletech">') !== false);
ok('a breadcrumb through «برندها»', strpos($page, '"BreadcrumbList"') !== false
   && strpos($page, 'https://suppex.ir/brands.php') !== false);
ok('«برندهای دیگر»: the others, not itself, not the hidden one',
   strpos($page, 'brand.php?b=body-attack') !== false
   && substr_count($page, 'class="bcard" href="brand.php?b=muscletech"') === 0
   && strpos($page, 'brand.php?b=applied-nutrition') === false);
$typed = $render('brand.php', ['b' => 'MuscleTech']);
ok('typed another way, the canonical is still the one address',
   strpos($typed, '<link rel="canonical" href="https://suppex.ir/brand.php?b=muscletech">') !== false);
$plain = $render('brand.php', ['b' => 'body-attack']);
ok('a brand with no logo stands its name on the plate', strpos($plain, '<span class="bcard__word lat" dir="ltr">Body Attack</span>') !== false);
ok('no PHP diagnostic leaked', stripos($page . $plain, 'Warning:') === false && stripos($page . $plain, 'Notice:') === false);

foreach (['applied-nutrition' => 'hidden', 'no-such-brand' => 'unknown', 'left-behind' => 'with no products'] as $slug => $why) {
    $missing = $render('brand.php', ['b' => $slug]);
    ok("a brand $why is a real 404", http_response_code() === 404
       && mb_strpos($missing, 'برند پیدا نشد') !== false && strpos($missing, 'noindex') !== false
       && strpos($missing, 'BreadcrumbList') === false);
}

head('brands.php');
$wall = $render('brands.php', []);
ok('every brand on sale, in order', strpos($wall, 'brand.php?b=muscletech') !== false
   && strpos($wall, 'brand.php?b=muscletech') < strpos($wall, 'brand.php?b=body-attack'));
ok('  not the hidden one', strpos($wall, 'brand.php?b=applied-nutrition') === false);
ok('  each a tile, the logo or the name on the plate',
   substr_count($wall, 'class="bcard"') === 2 && strpos($wall, 'class="bcard__logo"') !== false
   && strpos($wall, 'class="bcard__word lat"') !== false);
ok('every tile has its name line: the Persian one when there is one',
   strpos($wall, '<span class="bcard__name" dir="auto">ماسل تک</span>') !== false
   && strpos($wall, '<span class="bcard__name" dir="auto">Body Attack</span>') !== false
   && substr_count($wall, 'class="bcard__name"') === substr_count($wall, 'class="bcard"'));
ok('its count in Persian digits', strpos($wall, '<span class="num">۲</span>&nbsp;محصول') !== false);
ok('indexable, with its breadcrumb', strpos($wall, '<meta name="robots" content="index, follow">') !== false
   && strpos($wall, '"BreadcrumbList"') !== false);
db_query("UPDATE products SET brand = ''");
$empty = $render('brands.php', []);
ok('with no brands, a sentence rather than an empty wall, and noindex',
   mb_strpos($empty, 'فعلاً برندی برای نمایش نیست.') !== false && strpos($empty, 'noindex') !== false);
$set(0, 'Body Attack'); $set(1, 'Body attack'); $set(2, 'MuscleTech'); $set(3, 'MuscleTech');
$set(4, 'MuscleTech', 0); $set(5, 'Applied Nutrition'); $set(6, '!!!');

head('the product page');
$pdp = $render('product.php', ['p' => $slugOf(2)]);
ok('the brand above the title leads to its page',
   strpos($pdp, '<a class="pdp__brand" href="brand.php?b=muscletech">MuscleTech</a>') !== false);
$pdpHidden = $render('product.php', ['p' => $slugOf(5)]);
ok('  but not to a hidden brand', strpos($pdpHidden, 'pdp__brand') === false
   && strpos($pdpHidden, 'Applied Nutrition') !== false);

head('one logo upload, two versions for the dark storefront');
require_once SUPPEX_ROOT . '/lib/images.php';
$logoDir = uploads_dir() . '/brands/suite';
if (!is_dir($logoDir)) { mkdir($logoDir, 0755, true); }
$px = static function ($im, int $x, int $y): array {
    $c = imagecolorat($im, $x, $y);
    return ['a' => 1 - (($c >> 24) & 0x7F) / 127, 'r' => ($c >> 16) & 0xFF, 'g' => ($c >> 8) & 0xFF, 'b' => $c & 0xFF];
};
$canvas = static function (int $w, int $h, bool $clear, array $fill = [255, 255, 255]) {
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, $clear ? imagecolorallocatealpha($im, 0, 0, 0, 127)
                                : imagecolorallocate($im, $fill[0], $fill[1], $fill[2]));
    imagealphablending($im, true);
    return $im;
};
$save = static function ($im, string $name, string $as = 'png') use ($logoDir): string {
    $as === 'jpg' ? imagejpeg($im, "$logoDir/$name.jpg", 92) : imagepng($im, "$logoDir/$name.png");
    imagedestroy($im);
    return uploads_url() . "/brands/suite/$name." . $as;
};
$open = static fn(string $web) => imagecreatefrompng(uploads_dir() . substr($web, strlen(uploads_url())));

/* A black word on a transparent ground, with a wide margin around it. */
$im = $canvas(400, 200, true);
imagefilledrectangle($im, 100, 80, 299, 119, imagecolorallocate($im, 10, 10, 10));
$made = brand_logo_variants($save($im, 'black-word'));
ok('a black word on transparent: both versions made', !empty($made['ok'])
   && is_file(brand_upload_file($made['mono'] ?? '') ?? '') && is_file(brand_upload_file($made['color'] ?? '') ?? ''),
   json_encode($made, JSON_UNESCAPED_UNICODE));
$mono = $open($made['mono']);
$color = $open($made['color']);
ok('  trimmed to the logo, the margin gone', imagesx($mono) <= 206 && imagesy($mono) <= 46,
   imagesx($mono) . 'x' . imagesy($mono));
$mid = $px($mono, intdiv(imagesx($mono), 2), intdiv(imagesy($mono), 2));
/* Against the design's own colour, --text in tokens.css, not against the
   constant under test - a check that compares a value with itself cannot
   fail. */
preg_match('/--text:\s*#([0-9A-Fa-f]{6})/', (string) file_get_contents(SUPPEX_ROOT . '/assets/css/tokens.css'), $tok);
$cream = isset($tok[1]) ? array_map('hexdec', str_split($tok[1], 2)) : [-1, -1, -1];
ok('  the cream version is the site\'s cream, fully there',
   $mid['a'] > 0.95 && [$mid['r'], $mid['g'], $mid['b']] === $cream, json_encode([$mid, $cream]));
$midC = $px($color, intdiv(imagesx($color), 2), intdiv(imagesy($color), 2));
ok('  the coloured one keeps its own colour', $midC['a'] > 0.95 && $midC['r'] < 30);
ok('  and around the word, nothing', $px($mono, 0, 0)['a'] < 0.05);
imagedestroy($mono);
imagedestroy($color);

/* A red word on a white JPEG: the white box is the background. */
$im = $canvas(360, 160, false);
imagefilledrectangle($im, 60, 50, 299, 109, imagecolorallocate($im, 206, 17, 38));
$made = brand_logo_variants($save($im, 'red-on-white', 'jpg'));
/* A failure here must be a failed check, not a crash that hides the rest. */
if (empty($made['ok'])) {
    ok('a red word on white: the white taken away', false, json_encode($made, JSON_UNESCAPED_UNICODE));
} else {
    $mono = $open($made['mono']);
    $color = $open($made['color']);
    ok('a red word on white: the white taken away', imagesx($mono) <= 254 && imagesy($mono) <= 74
       && $px($color, 0, 0)['a'] < 0.1, imagesx($mono) . 'x' . imagesy($mono));
    $midC = $px($color, intdiv(imagesx($color), 2), intdiv(imagesy($color), 2));
    ok('  the red kept in the coloured version, cream in the other', $midC['a'] > 0.9 && $midC['r'] > 180
       && $px($mono, intdiv(imagesx($mono), 2), intdiv(imagesy($mono), 2))['a'] > 0.9);
    imagedestroy($mono);
    imagedestroy($color);
}

/* A black badge with a white letter cut into it: the letter is a hole in
   the cream version, and stays white in the coloured one. */
$im = $canvas(300, 300, true);
imagefilledrectangle($im, 50, 50, 249, 249, imagecolorallocate($im, 15, 15, 15));
imagefilledrectangle($im, 120, 90, 179, 209, imagecolorallocate($im, 255, 255, 255));
$made = brand_logo_variants($save($im, 'badge'));
$mono = $open($made['mono']);
$color = $open($made['color']);
[$cx, $cy] = [intdiv(imagesx($mono), 2), intdiv(imagesy($mono), 2)];
ok('a badge: its body is cream', $px($mono, 10, 10)['a'] > 0.9);
ok('  the white letter closed in by it is a hole', $px($mono, $cx, $cy)['a'] < 0.1, json_encode($px($mono, $cx, $cy)));
ok('  and white in the coloured version', $px($color, $cx, $cy)['a'] > 0.9 && $px($color, $cx, $cy)['r'] > 240);
imagedestroy($mono);
imagedestroy($color);

/* A grey word beside a black one: not closed in, so part of the logo. */
$im = $canvas(420, 120, true);
imagefilledrectangle($im, 20, 40, 199, 79, imagecolorallocate($im, 10, 10, 10));
imagefilledrectangle($im, 230, 40, 399, 79, imagecolorallocate($im, 200, 200, 200));
$made = brand_logo_variants($save($im, 'two-words'));
$mono = $open($made['mono']);
ok('a light word standing beside a dark one stays in the cream version',
   $px($mono, imagesx($mono) - 30, intdiv(imagesy($mono), 2))['a'] > 0.9);
imagedestroy($mono);

/* A logo already drawn white, for dark pages: it is the logo, not a hole. */
$im = $canvas(300, 120, true);
imagefilledrectangle($im, 40, 40, 259, 79, imagecolorallocate($im, 255, 255, 255));
$made = brand_logo_variants($save($im, 'white-word'));
$mono = $open($made['mono']);
ok('a white logo is kept, not taken for a background', !empty($made['ok'])
   && $px($mono, intdiv(imagesx($mono), 2), intdiv(imagesy($mono), 2))['a'] > 0.9);
imagedestroy($mono);

/* Nothing to find, and nothing but "logo". */
$empty = brand_logo_variants($save($canvas(200, 100, true), 'empty'));
ok('an empty picture is refused, in words', empty($empty['ok']) && mb_strpos((string) ($empty['error'] ?? ''), 'لوگویی پیدا نشد') !== false);
$im = $canvas(200, 100, false);
for ($y = 0; $y < 100; $y++) {
    for ($x = 0; $x < 200; $x++) {
        imagesetpixel($im, $x, $y, imagecolorallocate($im, ($x * 7 + $y * 13) % 256, ($x * 3) % 256, ($y * 11) % 256));
    }
}
$noise = brand_logo_variants($save($im, 'photo'));
ok('so is a photograph whose background cannot be told apart', empty($noise['ok']),
   json_encode($noise, JSON_UNESCAPED_UNICODE));
ok('a path outside uploads/ is not read', empty(brand_logo_variants('/uploads/../lib/brands.php')['ok']));

head('which version the storefront draws');
ok('cream by default', brand_logo_display('/o.webp', '/o-mono.png', '/o-color.png', 'mono') === '/o-mono.png');
ok('its own colours when the owner chose them', brand_logo_display('/o.webp', '/o-mono.png', '/o-color.png', 'color') === '/o-color.png');
ok('the upload itself when there are no versions yet', brand_logo_display('/o.webp', '', '', 'mono') === '/o.webp');
brand_save('body-attack', ['logo' => '/uploads/brands/suite/x.webp', 'logo_mono' => '/uploads/brands/suite/x-mono.png',
    'logo_color' => '/uploads/brands/suite/x-color.png', 'is_visible' => true]);
ok('the wall and the API get the cream one', (brand_get('body-attack')['logo'] ?? '') === '/uploads/brands/suite/x-mono.png'
   && (brand_api(brand_get('body-attack'))['logo'] ?? '') === '/uploads/brands/suite/x-mono.png');
brand_save('body-attack', ['logo' => '/uploads/brands/suite/x.webp', 'logo_mono' => '/uploads/brands/suite/x-mono.png',
    'logo_color' => '/uploads/brands/suite/x-color.png', 'logo_style' => 'color', 'is_visible' => true]);
ok('  or the coloured one', (brand_get('body-attack')['logo'] ?? '') === '/uploads/brands/suite/x-color.png');
db_query("DELETE FROM brands WHERE slug = 'body-attack'");

head('many logos at once, each filed under the brand its file name says');
$word = static function (string $name) use ($canvas, $save): string {
    $im = $canvas(300, 100, true);
    imagefilledrectangle($im, 30, 30, 269, 69, imagecolorallocate($im, 20, 20, 20));
    return $save($im, $name);
};
/* A brand the catalogue will bring, with no product on the site yet. */
$r = brand_attach_logo('Evogen (ایوژن).png', $word('evogen-1'));
$ev = array_column(brands_all(true), null, 'slug')['evogen'] ?? null;
ok('a brand with no product yet is made, under the name the file gives', !empty($r['ok'])
   && ($r['slug'] ?? '') === 'evogen' && $ev !== null && $ev['name'] === 'Evogen' && $ev['total'] === 0,
   json_encode([$r, $ev['name'] ?? null], JSON_UNESCAPED_UNICODE));
ok('  with the Persian name from its brackets', ($ev['nameFa'] ?? '') === 'ایوژن');
ok('  its two versions made, the cream one drawn', ($ev['logoMono'] ?? '') !== '' && ($ev['logoColor'] ?? '') !== ''
   && ($ev['logo'] ?? '') === ($ev['logoMono'] ?? 'x'));
ok('  and nowhere on the site until something of it is on sale', brand_get('evogen') === null);
ok('  though the upload said so', ($r['hasProducts'] ?? true) === false);

/* The catalogue arrives: its brand text finds the logo already there. */
$set(6, 'Evogen (ایوژن)');
ok('when its product comes, the brand is on the wall with the logo waiting',
   (brand_get('evogen')['logo'] ?? '') === ($ev['logoMono'] ?? 'x'));

/* The owner's own choices survive a new logo. */
brand_save('evogen', ['name' => 'Evogen', 'name_fa' => 'اوژن', 'logo' => $ev['logoFile'], 'logo_mono' => $ev['logoMono'],
    'logo_color' => $ev['logoColor'], 'logo_style' => 'color', 'sort_order' => 3, 'is_visible' => true]);
$r2 = brand_attach_logo('EVOGEN.png', $word('evogen-2'));
$ev2 = array_column(brands_all(true), null, 'slug')['evogen'] ?? [];
ok('a second logo for the same brand replaces the first', !empty($r2['ok'])
   && ($ev2['logoFile'] ?? '') !== ($ev['logoFile'] ?? '') && in_array($ev['logoFile'], $r2['replaced'] ?? [], true)
   && count($r2['replaced'] ?? []) === 3);
ok('  keeping the Persian name, the order and the choice of colours the owner set',
   ($ev2['nameFa'] ?? '') === 'اوژن' && ($ev2['sort'] ?? 0) === 3 && ($ev2['logoStyle'] ?? '') === 'color');
ok('  and a file name the brand\'s products spell differently still finds it', ($r2['slug'] ?? '') === 'evogen');
$bad = brand_attach_logo('---.png', $word('no-name'));
ok('a file name with no brand in it is refused, in words', empty($bad['ok'])
   && mb_strpos((string) ($bad['error'] ?? ''), 'نام برندی درنمی‌آید') !== false);
$set(6, '!!!');
db_query("DELETE FROM brands WHERE slug = 'evogen'");

foreach (glob($logoDir . '/*') ?: [] as $f) { unlink($f); }
@rmdir($logoDir);

/* Last, as in test-category.php: sitemap.php sends real headers. */
head('the sitemap');
$_GET = []; $_SERVER['REQUEST_URI'] = '/sitemap.php';
ob_start(); include SUPPEX_ROOT . '/sitemap.php'; $sm = (string) ob_get_clean();
ok('has the wall and each brand on sale', strpos($sm, 'https://suppex.ir/brands.php') !== false
   && strpos($sm, 'https://suppex.ir/brand.php?b=muscletech') !== false
   && strpos($sm, 'https://suppex.ir/brand.php?b=body-attack') !== false);
ok('  and not the hidden one, nor the one with nothing on sale',
   strpos($sm, 'brand.php?b=applied-nutrition') === false && strpos($sm, 'brand.php?b=left-behind') === false);

$restore();

echo implode("\n", $out);
echo "\n\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
