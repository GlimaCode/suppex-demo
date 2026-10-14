<?php
/* ===========================================================================
   SUPPEX — Brands
   ---------------------------------------------------------------------------
   A brand is not a record that products point at. Each product carries its
   brand as text (products.brand), typed in the panel or brought in by the
   catalogue import, and that stays exactly as it is: nothing about adding or
   importing a product changes.

   So a brand is what those texts have in common. Every way of writing one
   that comes down to the same name is one brand - «Body Attack» and «Body
   attack», and the catalogue sheets' «Optimum Nutrition (اپتیموم نوتریشن)»
   beside a plain «Optimum Nutrition» (brand_parts()). The name shown is the
   one most of its products use, unless the owner sets one in the panel.

   The `brands` table holds only what the owner adds on top: a logo, a Persian
   name, a line about the brand, an order, and whether it is shown at all. A
   brand without a row is shown by its name. Everything here works before the
   migration that adds the table has run - there are just no logos yet.

   Nothing is cached between calls. The queries are two small GROUP BYs, and
   a cache here is how a logo saved in the panel fails to appear.
   =========================================================================== */

declare(strict_types=1);

/* How many the home page shows before «همه برندها». Two rows at six across. */
const BRANDS_HOME_MAX = 12;
/* A logo is drawn at most about 240px wide; twice that is enough for any
   screen, and a logo is not a photograph. */
const BRAND_LOGO_MAX_EDGE = 480;
/* The cream every logo is drawn in by default: the site's own text colour,
   --text in tokens.css. */
const BRAND_LOGO_INK = [242, 237, 227];

/**
 * A brand as typed on a product, taken apart: its name, and the Persian name
 * the catalogue sheets give in brackets.
 *
 * The sheets write one brand several ways - «Optimum Nutrition», «Optimum
 * Nutrition (اپتیموم نوتریشن)», «Karen Pharma / PNC (کارن / پی ان سی)»,
 * «Doobis (Dubis Nutrition)» - and each way would be a brand of its own on
 * the wall. So what is in brackets is set aside, and of a name given as
 * «A / B» the first is the name. Bracketed text in Persian script becomes the
 * Persian name - the first of its «/» alternatives.
 *
 * @return array{name:string,fa:string}
 */
function brand_parts(string $raw): array
{
    $raw = trim((string) preg_replace('/\s+/u', ' ', $raw));
    $fa = '';
    if (preg_match_all('/\(([^()]*)\)/u', $raw, $m)) {
        foreach ($m[1] as $inner) {
            if ($fa === '' && preg_match('/\p{Arabic}/u', $inner) === 1) {
                $fa = trim((string) (preg_split('~\s*/\s*~u', trim($inner))[0] ?? ''));
            }
        }
    }
    $name = trim((string) preg_replace('/\([^()]*\)/u', ' ', $raw));
    $name = trim((string) (preg_split('~\s+/\s+~u', $name)[0] ?? ''));
    $name = trim((string) preg_replace('/\s+/u', ' ', $name));
    /* A brand written only in brackets, in Persian: that is its name. */
    if ($name === '' && $fa !== '') {
        $name = $fa;
    }
    return ['name' => $name, 'fa' => $fa];
}

/**
 * A product's brand as the storefront prints it: its name without the
 * catalogue's brackets or «/ alternative» (brand_parts()), or the text as
 * typed when that leaves nothing.
 *
 * A card sets the brand in spaced capitals, in the Latin face: «OPTIMUM
 * NUTRITION» reads, «OPTIMUM NUTRITION (اپتیموم نوتریشن)» does not - and the
 * spacing pulls the Persian letters apart. The Persian name is the brand's,
 * shown on its tile and its page.
 */
function brand_display_name(string $raw): string
{
    $name = brand_parts($raw)['name'];
    return $name !== '' ? $name : trim($raw);
}

/**
 * The brand's slug: its name (brand_parts()) in lower case, anything that is
 * not a letter or a digit one hyphen. '' for a name with neither - such a
 * product has no brand page.
 *
 * Deliberately not slugify(): that one invents a random slug for an empty
 * result, and a brand's slug has to come out the same on every request.
 */
function brand_slug(string $raw): string
{
    $s = mb_strtolower(to_latin_digits(brand_parts($raw)['name']));
    $s = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $s), '-');
    return trim(mb_substr($s, 0, 110), '-');
}

/** Relative link to a brand's page. */
function brand_url(string $slug): string
{
    return 'brand.php?b=' . rawurlencode($slug);
}

/**
 * Whether the brands table is there, with the columns this code writes - the
 * two migration steps that make it have both run.
 */
function brands_ready(): bool
{
    return db_value(
        'SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['brands', 'logo_style']
    ) !== null;
}

/**
 * Every brand, from the products that name it.
 *
 * The storefront's view has only brands that are shown and that have at least
 * one product on sale. The panel's view ($forPanel) has all of them: hidden
 * ones, ones whose products are all switched off, and rows left behind by a
 * brand no product names any more.
 *
 * Ordered by the owner's number, then by how many products a brand has, then
 * by name - so with nothing set, the brands the shop carries most come first.
 *
 * @return list<array{slug:string,name:string,nameSaved:string,nameFa:string,
 *                    nameFaSaved:string,logo:string,logoFile:string,
 *                    logoMono:string,logoColor:string,logoStyle:string,
 *                    blurb:string,sort:int,visible:bool,count:int,total:int,
 *                    saved:bool,spellings:array<string,int>}>
 */
function brands_all(bool $forPanel = false): array
{
    /* Grouped on the bytes, because the column's collation is case-blind:
       without it «Body Attack» and «Body attack» come back as one row under
       whichever spelling the database picks, and the panel could not show the
       owner that both are in use. The same expression is selected as grouped,
       which MySQL's ONLY_FULL_GROUP_BY insists on - the host may be MySQL
       rather than the MariaDB this was written against. */
    $rows = db_all("SELECT CAST(brand AS BINARY) AS brand, SUM(is_active = 1) AS active, COUNT(*) AS total
                      FROM products
                     WHERE brand <> ''
                     GROUP BY CAST(brand AS BINARY)");

    $found = [];
    foreach ($rows as $r) {
        $spelling = trim((string) $r['brand']);
        $slug = brand_slug($spelling);
        if ($slug === '') {
            continue;
        }
        $b = $found[$slug] ?? ['count' => 0, 'total' => 0, 'spellings' => []];
        $b['count'] += (int) $r['active'];
        $b['total'] += (int) $r['total'];
        $b['spellings'][$spelling] = ($b['spellings'][$spelling] ?? 0) + (int) $r['total'];
        $found[$slug] = $b;
    }

    $saved = [];
    if (brands_ready()) {
        foreach (db_all('SELECT * FROM brands') as $row) {
            $saved[(string) $row['slug']] = $row;
            $found[(string) $row['slug']] ??= ['count' => 0, 'total' => 0, 'spellings' => []];
        }
    }

    $out = [];
    foreach ($found as $slug => $b) {
        $slug = (string) $slug;
        $row = $saved[$slug] ?? null;
        $visible = $row === null || (int) $row['is_visible'] === 1;
        if (!$forPanel && (!$visible || $b['count'] === 0)) {
            continue;
        }
        $spellings = brand_rank_spellings($b['spellings']);

        /* The name the products give, and the Persian one their brackets
           give - the first spelling that has any. */
        $nameFromProducts = $slug;
        $faFromProducts = '';
        foreach (array_keys($spellings) as $i => $spelling) {
            $parts = brand_parts((string) $spelling);
            if ($i === 0 && $parts['name'] !== '') {
                $nameFromProducts = $parts['name'];
            }
            if ($faFromProducts === '' && $parts['fa'] !== '') {
                $faFromProducts = $parts['fa'];
            }
        }
        $nameSaved   = trim((string) ($row['name'] ?? ''));
        $nameFaSaved = trim((string) ($row['name_fa'] ?? ''));
        $logoFile    = trim((string) ($row['logo'] ?? ''));
        $logoMono    = trim((string) ($row['logo_mono'] ?? ''));
        $logoColor   = trim((string) ($row['logo_color'] ?? ''));
        $logoStyle   = ($row['logo_style'] ?? 'mono') === 'color' ? 'color' : 'mono';

        $out[] = [
            'slug'        => $slug,
            'name'        => $nameSaved !== '' ? $nameSaved : $nameFromProducts,
            /* The panel's fields: what the owner typed, apart from what the
               products give - so an empty field stays empty, and the brand
               keeps following its products. */
            'nameSaved'   => $nameSaved,
            'nameFa'      => $nameFaSaved !== '' ? $nameFaSaved : $faFromProducts,
            'nameFaSaved' => $nameFaSaved,
            /* What a tile draws: the version of the logo the owner chose. The
               uploaded file itself is kept (logoFile) and drawn only when the
               versions could not be made - a logo saved before they existed. */
            'logo'        => brand_logo_display($logoFile, $logoMono, $logoColor, $logoStyle),
            'logoFile'    => $logoFile,
            'logoMono'    => $logoMono,
            'logoColor'   => $logoColor,
            'logoStyle'   => $logoStyle,
            'blurb'       => trim((string) ($row['blurb'] ?? '')),
            'sort'        => (int) ($row['sort_order'] ?? 0),
            'visible'     => $visible,
            'count'       => $b['count'],
            'total'       => $b['total'],
            'saved'       => $row !== null,
            'spellings'   => $spellings,
        ];
    }

    usort($out, static function (array $a, array $b): int {
        return [$a['sort'], -$a['count'], mb_strtolower($a['name'])]
           <=> [$b['sort'], -$b['count'], mb_strtolower($b['name'])];
    });
    return $out;
}

/** Which file a tile draws for a logo. */
function brand_logo_display(string $file, string $mono, string $color, string $style): string
{
    if ($style === 'color' && $color !== '') {
        return $color;
    }
    if ($style !== 'color' && $mono !== '') {
        return $mono;
    }
    return $file;
}

/**
 * The spellings of one brand, the one to show first.
 *
 * Most products first. On a tie, the one with more capitals - «Body Attack»
 * over «Body attack», «MuscleTech» over «Muscletech» - which is how a brand
 * writes its own name far more often than not. Then alphabetical, so the
 * answer never depends on the order the database returned the rows in.
 *
 * @param array<string,int> $spellings
 * @return array<string,int>
 */
function brand_rank_spellings(array $spellings): array
{
    $keys = array_keys($spellings);
    usort($keys, static function ($a, $b) use ($spellings): int {
        $a = (string) $a;
        $b = (string) $b;
        return [-$spellings[$a], -preg_match_all('/\p{Lu}/u', $a), $a]
           <=> [-$spellings[$b], -preg_match_all('/\p{Lu}/u', $b), $b];
    });
    $ranked = [];
    foreach ($keys as $k) {
        $ranked[(string) $k] = $spellings[$k];
    }
    return $ranked;
}

/** The storefront's brands. */
function brands_list(): array
{
    return brands_all(false);
}

/**
 * One brand as the storefront sees it, or null: unknown, hidden in the panel,
 * or with nothing on sale. The slug is normalised first, so ?b=MuscleTech
 * finds muscletech - the page's canonical URL then says which is the one.
 */
function brand_get(string $slug): ?array
{
    $slug = brand_slug($slug);
    if ($slug === '') {
        return null;
    }
    foreach (brands_list() as $b) {
        if ($b['slug'] === $slug) {
            return $b;
        }
    }
    return null;
}

/**
 * Every spelling of a brand on the products, whether or not the brand is
 * shown - products_list() filters by these.
 *
 * @return list<string>
 */
function brand_spellings(string $slug): array
{
    $slug = brand_slug($slug);
    if ($slug === '') {
        return [];
    }
    foreach (brands_all(true) as $b) {
        if ($b['slug'] === $slug) {
            return array_map('strval', array_keys($b['spellings']));
        }
    }
    return [];
}

/**
 * The slug a product's brand links to, or null when there is no page to link
 * to: no usable name, or a brand the owner hid. The product itself is on sale
 * - that is why its page is being drawn - so its brand has at least one.
 */
function brand_link_slug(string $brand): ?string
{
    $slug = brand_slug($brand);
    if ($slug === '') {
        return null;
    }
    if (brands_ready() && db_value('SELECT 1 FROM brands WHERE slug = ? AND is_visible = 0', [$slug]) !== null) {
        return null;
    }
    return $slug;
}

/** What ?r=brands sends: what a tile draws, and nothing about the panel. */
function brand_api(array $b): array
{
    return [
        'slug'   => $b['slug'],
        'name'   => $b['name'],
        'nameFa' => $b['nameFa'],
        'logo'   => $b['logo'],
        'count'  => $b['count'],
    ];
}

/**
 * Save the owner's additions to one brand. The row is made the first time;
 * the slug is its key, the one thing the owner cannot change here - it comes
 * from the products.
 *
 * @param array{name?:string,name_fa?:string,logo?:string,logo_mono?:string,logo_color?:string,
 *              logo_style?:string,blurb?:string,sort_order?:int,is_visible?:bool} $f
 */
function brand_save(string $slug, array $f): void
{
    db_query(
        'INSERT INTO brands (slug, name, name_fa, logo, logo_mono, logo_color, logo_style, blurb, sort_order, is_visible)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), name_fa = VALUES(name_fa), logo = VALUES(logo),
                                 logo_mono = VALUES(logo_mono), logo_color = VALUES(logo_color),
                                 logo_style = VALUES(logo_style), blurb = VALUES(blurb),
                                 sort_order = VALUES(sort_order), is_visible = VALUES(is_visible)',
        [
            $slug,
            clean_text($f['name'] ?? '', 120),
            clean_text($f['name_fa'] ?? '', 120),
            clean_text($f['logo'] ?? '', 255),
            clean_text($f['logo_mono'] ?? '', 255),
            clean_text($f['logo_color'] ?? '', 255),
            ($f['logo_style'] ?? 'mono') === 'color' ? 'color' : 'mono',
            clean_text($f['blurb'] ?? '', 255),
            (int) ($f['sort_order'] ?? 0),
            empty($f['is_visible']) ? 0 : 1,
        ]
    );
}

/** A file under uploads/ for a web path image_store() gave, or null. */
function brand_upload_file(string $webPath): ?string
{
    $prefix = uploads_url() . '/';
    if (strpos($webPath, $prefix) !== 0) {
        return null;
    }
    $base = realpath(uploads_dir());
    $file = realpath(uploads_dir() . '/' . substr($webPath, strlen($prefix)));
    if ($base === false || $file === false || strpos($file, $base . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }
    return $file;
}

function brand_smooth(float $edge0, float $edge1, float $x): float
{
    $t = max(0.0, min(1.0, ($x - $edge0) / ($edge1 - $edge0)));
    return $t * $t * (3 - 2 * $t);
}

/**
 * Two versions of an uploaded logo for the dark storefront, written beside it:
 *
 *   -color.png  the logo's own colours, with its background taken away;
 *   -mono.png   the same shape in the site's cream - how every brand is drawn
 *               unless the owner picks the colours. Thirty brand colours on a
 *               dark page read as noise, and half of those logos are drawn in
 *               black, for white paper, where they would not show at all.
 *
 * What counts as the logo: in a transparent PNG or WebP, its opaque pixels;
 * in anything else, whatever differs from the colour around the image's edge
 * - the white or coloured box it was exported on.
 *
 * Inside the logo, a part much lighter or darker than most of it AND closed
 * in by it on three sides at least is a detail cut out of it - the white «ON»
 * in Optimum Nutrition's black badge - so the cream version keeps it as a
 * hole instead of filling it in. A lighter part that stands on its own - a
 * grey word beside a black one - is part of the logo and stays.
 *
 * Both are trimmed to the logo, so one exported with a wide margin is not
 * drawn at half the size of its neighbours.
 *
 * @return array{ok:bool,mono?:string,color?:string,error?:string} web paths
 */
function brand_logo_variants(string $webPath): array
{
    $file = brand_upload_file($webPath);
    $info = $file === null ? false : @getimagesize($file);
    if ($file === null || $info === false) {
        return ['ok' => false, 'error' => 'فایل لوگو پیدا نشد.'];
    }
    [$w, $h, $type] = $info;
    $open = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng',
             IMAGETYPE_WEBP => 'imagecreatefromwebp'];
    if (!isset($open[$type]) || !function_exists($open[$type])) {
        return ['ok' => false, 'error' => 'این قالب تصویر روی سرور خوانده نمی‌شود. PNG بفرستید.'];
    }
    $im = @$open[$type]($file);
    if ($im === false) {
        return ['ok' => false, 'error' => 'تصویر لوگو قابل خواندن نیست.'];
    }
    if (!imageistruecolor($im)) {
        imagepalettetotruecolor($im);
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $n = $w * $h;

    /* GD's colour as one int: alpha 0 (opaque) to 127 (clear), then RGB. */
    $px = [];
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $px[] = imagecolorat($im, $x, $y);
        }
    }
    imagedestroy($im);
    $opacity = static fn(int $p): float => 1 - (($p >> 24) & 0x7F) / 127;

    /* --- the background: clear around the edge, or one colour ------------ */
    $edge = [];
    for ($x = 0; $x < $w; $x++) {
        $edge[] = $x;
        $edge[] = ($h - 1) * $w + $x;
    }
    for ($y = 1; $y < $h - 1; $y++) {
        $edge[] = $y * $w;
        $edge[] = $y * $w + $w - 1;
    }
    $clear = 0;
    $rs = $gs = $bs = [];
    foreach ($edge as $i) {
        $p = $px[$i];
        if ($opacity($p) < 0.5) {
            $clear++;
        } else {
            $rs[] = ($p >> 16) & 0xFF;
            $gs[] = ($p >> 8) & 0xFF;
            $bs[] = $p & 0xFF;
        }
    }
    $ink = [];
    if ($clear >= 0.5 * count($edge)) {
        foreach ($px as $p) {
            $ink[] = $opacity($p);
        }
    } else {
        /* One colour the edge mostly is - the box a logo is exported on. An
           edge of many colours is a photograph, and nothing in it can be told
           apart as the logo. */
        $edgeRgb = [];
        foreach ($rs as $k => $r) {
            $edgeRgb[] = [$r, $gs[$k], $bs[$k]];
        }
        sort($rs);
        sort($gs);
        sort($bs);
        $mid = intdiv(count($rs), 2);
        [$br, $bg, $bb] = [$rs[$mid], $gs[$mid], $bs[$mid]];
        $alike = 0;
        foreach ($edgeRgb as [$r, $g, $b]) {
            if (sqrt(($r - $br) ** 2 + ($g - $bg) ** 2 + ($b - $bb) ** 2) / 441.673 < 0.12) {
                $alike++;
            }
        }
        if ($alike < 0.6 * count($edgeRgb)) {
            return ['ok' => false, 'error' => 'پس‌زمینهٔ این تصویر از لوگو جدا نشد. لوگو را با پس‌زمینهٔ شفاف یا سفید بفرستید.'];
        }
        foreach ($px as $p) {
            $d = sqrt((((($p >> 16) & 0xFF) - $br) ** 2) + (((($p >> 8) & 0xFF) - $bg) ** 2)
                    + ((($p & 0xFF) - $bb) ** 2)) / 441.673;
            $ink[] = $opacity($p) * brand_smooth(0.08, 0.24, $d);
        }
    }

    /* --- the trim, and whether there is a logo here at all ---------------- */
    $x0 = $w;
    $y0 = $h;
    $x1 = -1;
    $y1 = -1;
    $sum = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $sum += $ink[$i];
        if ($ink[$i] > 0.06) {
            $x = $i % $w;
            $y = intdiv($i, $w);
            $x0 = min($x0, $x);
            $x1 = max($x1, $x);
            $y0 = min($y0, $y);
            $y1 = max($y1, $y);
        }
    }
    if ($x1 < 0) {
        return ['ok' => false, 'error' => 'در این تصویر لوگویی پیدا نشد. تصویر دیگری بفرستید.'];
    }
    /* Everything is logo: a photograph, or a background the edge could not
       tell apart. Nothing sensible can be drawn from it in cream. */
    if ($sum / $n > 0.92) {
        return ['ok' => false, 'error' => 'پس‌زمینهٔ این تصویر از لوگو جدا نشد. لوگو را با پس‌زمینهٔ شفاف یا سفید بفرستید.'];
    }

    /* --- the cream version: details closed in by the logo are holes ------- */
    $lum = static fn(int $p): float
        => (0.2126 * (($p >> 16) & 0xFF) + 0.7152 * (($p >> 8) & 0xFF) + 0.0722 * ($p & 0xFF)) / 255;
    $bins = array_fill(0, 8, 0);
    $binSum = array_fill(0, 8, 0.0);
    for ($i = 0; $i < $n; $i++) {
        if ($ink[$i] >= 0.5) {
            $l = $lum($px[$i]);
            $k = min(7, (int) ($l * 8));
            $bins[$k]++;
            $binSum[$k] += $l;
        }
    }
    $main = (int) array_search(max($bins), $bins, true);
    $mainLum = $bins[$main] > 0 ? $binSum[$main] / $bins[$main] : 0.0;

    /* T clear, M the logo's own body, D a part that stands apart from it. */
    $cls = str_repeat('T', $n);
    $contrast = [];
    for ($i = 0; $i < $n; $i++) {
        $c = abs($lum($px[$i]) - $mainLum);
        $contrast[] = $c;
        if ($ink[$i] >= 0.3) {
            $cls[$i] = $c > 0.35 ? 'D' : 'M';
        }
    }
    /* For each D, the first thing that is not D in each direction; the
       image's edge counts as clear. Four sweeps, not a search per pixel. */
    $closed = array_fill(0, $n, 0);
    for ($y = 0; $y < $h; $y++) {
        $last = 'T';
        for ($x = 0, $i = $y * $w; $x < $w; $x++, $i++) {
            if ($cls[$i] === 'D') { $closed[$i] += $last === 'M' ? 1 : 0; } else { $last = $cls[$i]; }
        }
        $last = 'T';
        for ($x = $w - 1, $i = $y * $w + $w - 1; $x >= 0; $x--, $i--) {
            if ($cls[$i] === 'D') { $closed[$i] += $last === 'M' ? 1 : 0; } else { $last = $cls[$i]; }
        }
    }
    for ($x = 0; $x < $w; $x++) {
        $last = 'T';
        for ($y = 0, $i = $x; $y < $h; $y++, $i += $w) {
            if ($cls[$i] === 'D') { $closed[$i] += $last === 'M' ? 1 : 0; } else { $last = $cls[$i]; }
        }
        $last = 'T';
        for ($y = $h - 1, $i = ($h - 1) * $w + $x; $y >= 0; $y--, $i -= $w) {
            if ($cls[$i] === 'D') { $closed[$i] += $last === 'M' ? 1 : 0; } else { $last = $cls[$i]; }
        }
    }

    /* --- write both, trimmed, with a couple of pixels to breathe --------- */
    $pad = 2;
    $x0 = max(0, $x0 - $pad);
    $y0 = max(0, $y0 - $pad);
    $x1 = min($w - 1, $x1 + $pad);
    $y1 = min($h - 1, $y1 + $pad);
    $cw = $x1 - $x0 + 1;
    $ch = $y1 - $y0 + 1;
    $mono = imagecreatetruecolor($cw, $ch);
    $color = imagecreatetruecolor($cw, $ch);
    foreach ([$mono, $color] as $out) {
        imagealphablending($out, false);
        imagesavealpha($out, true);
    }
    [$ir, $ig, $ib] = BRAND_LOGO_INK;
    $inkRgb = ($ir << 16) | ($ig << 8) | $ib;
    for ($y = $y0; $y <= $y1; $y++) {
        for ($x = $x0; $x <= $x1; $x++) {
            $i = $y * $w + $x;
            $a = $ink[$i];
            $am = $cls[$i] === 'D' && $closed[$i] >= 3
                ? $a * (1 - brand_smooth(0.3, 0.45, $contrast[$i]))
                : $a;
            imagesetpixel($color, $x - $x0, $y - $y0,
                ((int) round(127 * (1 - $a)) << 24) | ($px[$i] & 0xFFFFFF));
            imagesetpixel($mono, $x - $x0, $y - $y0,
                ((int) round(127 * (1 - $am)) << 24) | $inkRgb);
        }
    }

    $stem = substr($file, 0, (int) strrpos($file, '.'));
    $webStem = substr($webPath, 0, (int) strrpos($webPath, '.'));
    $okMono = imagepng($mono, $stem . '-mono.png', 9);
    $okColor = imagepng($color, $stem . '-color.png', 9);
    imagedestroy($mono);
    imagedestroy($color);
    if (!$okMono || !$okColor) {
        return ['ok' => false, 'error' => 'ذخیرهٔ لوگو ناموفق بود.'];
    }
    @chmod($stem . '-mono.png', 0644);
    @chmod($stem . '-color.png', 0644);
    return ['ok' => true, 'mono' => $webStem . '-mono.png', 'color' => $webStem . '-color.png'];
}

/**
 * A logo from the panel's many-at-once upload, filed under the brand its
 * file name says: «Optimum Nutrition (اپتیموم نوتریشن).png» is Optimum
 * Nutrition, and the bracket is its Persian name if it has none yet - the
 * same way of writing a brand the catalogue sheets use (brand_parts()).
 *
 * The brand need not have a product yet. The row is made now and the logo
 * waits: the storefront shows a brand only once something of it is on sale,
 * and when the catalogue brings its products in, their brand text comes to
 * the same slug and finds the logo already there.
 *
 * What the owner set by hand is kept - the Persian name, the order, whether
 * it is shown, the choice of colours; only the logo is replaced. The files of
 * a logo it replaces are returned for the caller to delete (image_delete() is
 * the panel's, in lib/images.php).
 *
 * @param string $fileName the uploaded file's own name
 * @param string $stored   the web path image_store() gave it
 * @return array{ok:bool,slug?:string,name?:string,hasProducts?:bool,replaced?:list<string>,error?:string}
 */
function brand_attach_logo(string $fileName, string $stored): array
{
    $stem  = (string) preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', basename(str_replace('\\', '/', $fileName)));
    $parts = brand_parts($stem);
    $slug  = brand_slug($stem);
    if ($slug === '') {
        return ['ok' => false, 'error' => 'از نام این فایل نام برندی درنمی‌آید؛ فایل را به نام برند بنامید.'];
    }
    $made = brand_logo_variants($stored);
    if (empty($made['ok'])) {
        return ['ok' => false, 'error' => (string) ($made['error'] ?? 'لوگو ساخته نشد.')];
    }

    $before = null;
    foreach (brands_all(true) as $b) {
        if ($b['slug'] === $slug) {
            $before = $b;
            break;
        }
    }
    $hasProducts = $before !== null && $before['total'] > 0;
    brand_save($slug, [
        /* A brand with no product yet has no name but this one; a brand that
           has products keeps following theirs. */
        'name'       => $before !== null && $before['nameSaved'] !== ''
            ? $before['nameSaved'] : ($hasProducts ? '' : $parts['name']),
        'name_fa'    => $before !== null && $before['nameFaSaved'] !== '' ? $before['nameFaSaved'] : $parts['fa'],
        'logo'       => $stored,
        'logo_mono'  => (string) $made['mono'],
        'logo_color' => (string) $made['color'],
        'logo_style' => $before['logoStyle'] ?? 'mono',
        'blurb'      => $before['blurb'] ?? '',
        'sort_order' => $before['sort'] ?? 0,
        'is_visible' => $before === null || $before['saved'] === false || $before['visible'],
    ]);
    $replaced = $before === null ? [] : array_values(array_filter(
        [$before['logoFile'], $before['logoMono'], $before['logoColor']],
        static fn(string $f): bool => $f !== ''
    ));
    return ['ok' => true, 'slug' => $slug, 'name' => $parts['name'], 'hasProducts' => $hasProducts,
            'replaced' => $replaced];
}

/**
 * One tile of the logo wall, for the pages PHP draws (brands.php, and «برندهای
 * دیگر» on a brand page). ui.brandCard() in ui.js draws the same tile for the
 * home page; tests/test-brands.mjs holds the two to the same markup.
 *
 * A dark card like a product card, the logo standing in the same lit well the
 * jars stand in - in cream, or in its own colours if the owner chose them.
 * With no logo uploaded, the name itself stands in the well, set like one.
 */
function brand_card_html(array $b): string
{
    $name   = (string) $b['name'];
    $nameFa = (string) ($b['nameFa'] ?? '');
    $logo   = (string) ($b['logo'] ?? '');
    $plate  = $logo !== ''
        ? '<img class="bcard__logo" src="' . e($logo) . '" alt="' . e($name) . '" loading="lazy" width="160" height="80">'
        : '<span class="bcard__word lat" dir="ltr">' . e($name) . '</span>';
    /* Every tile has the same three parts - well, name, count - so a row of
       them reads level whatever each one has. The Persian name when there is
       one; until then the name, even where the well already spells it out. */
    $caption = $nameFa !== '' ? $nameFa : $name;
    return '<a class="bcard" href="' . e(brand_url((string) $b['slug'])) . '">'
         . '<span class="bcard__plate">' . $plate . '</span>'
         . '<span class="bcard__name" dir="auto">' . e($caption) . '</span>'
         . '<span class="bcard__count"><span class="num">' . e(fa_digits((string) (int) $b['count']))
         . '</span>&nbsp;محصول</span>'
         . '</a>';
}

/**
 * The band of logos under the home page's hero: every brand on sale, each a
 * link to its page, sliding past.
 *
 * It took the place of a strip of category words in the same orange band,
 * and of the logo wall at the very foot of the page, which the owner wanted
 * much higher. One copy of the brands is repeated until it fills a wide
 * screen, then the whole run is doubled: the second half is what the first
 * slides into, so the loop has no seam. Only the first copy can be reached
 * by keyboard or screen reader; the repeats are decoration.
 *
 * Each logo sits in a fixed box, so a logo arriving late cannot push the
 * others along (a layout shift PageSpeed would count). ui.brandStrip() draws
 * the same band in the static demo.
 *
 * @param array<int,array{slug:string,name:string,logo:string}> $brands
 */
function brand_strip_html(array $brands): string
{
    if (!$brands) {
        return '';
    }
    $item = static function (array $b, bool $echo): string {
        $name  = (string) $b['name'];
        $inner = (string) $b['logo'] !== ''
            ? '<img src="' . e((string) $b['logo']) . '" alt="' . ($echo ? '' : e($name)) . '" loading="lazy" decoding="async" width="132" height="44">'
            : '<span class="ticker__word lat" dir="ltr">' . e($name) . '</span>';
        return '<a class="ticker__brand" href="brand.php?b=' . e(rawurlencode((string) $b['slug'])) . '"'
             . ($echo ? ' tabindex="-1" aria-hidden="true"' : '') . '>' . $inner . '</a>';
    };
    $first = '';
    $again = '';
    foreach ($brands as $b) {
        $first .= $item($b, false);
        $again .= $item($b, true);
    }
    $copies = max(1, (int) ceil(12 / count($brands)));
    return '<section class="ticker ticker--brands" aria-label="برندهای فروشگاه" data-brandstrip data-drawn>'
        . '<div class="ticker__track">'
        . '<div class="ticker__group">' . $first . str_repeat($again, $copies - 1) . '</div>'
        . '<div class="ticker__group" aria-hidden="true">' . str_repeat($again, $copies) . '</div>'
        . '</div>'
        . '<a class="ticker__all" href="brands.php">همه برندها'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M15 6l-6 6 6 6"/></svg>'
        . '</a></section>';
}
