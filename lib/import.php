<?php
/* ===========================================================================
   SUPPEX — Catalogue import
   ---------------------------------------------------------------------------
   Reads the cost sheet the shop fills in and turns it into products, sizes and
   dirham prices.

   This exists because the alternative is the real launch delay. Fifty products,
   each with two or three sizes, a dirham cost, a profit figure and a
   description, typed one at a time into a web form, is most of a working day
   and every field is a chance to fat-finger a price. The seller already keeps
   this data in a spreadsheet; the job is to read it, not to re-key it.

   Two rules shape the whole file:

   NOTHING IS WRITTEN UNTIL THE WHOLE FILE VALIDATES. A partial import is worse
   than a failed one — half a catalogue live at unknown prices, with no clean
   way back. Parsing and writing are therefore separate passes, and the writing
   pass runs in a transaction.

   EVERY ROW IS PREVIEWED BEFORE ANY ROW IS WRITTEN. The same reasoning as the
   pricing page: a mistyped column is obvious in a table and invisible in a
   success message.
   =========================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/pricing.php';
require_once __DIR__ . '/variants.php';

/** The columns the sheet is expected to carry, in order. */
const IMPORT_COLUMNS = [
    'name_fa'      => 'نام فارسی',
    'name_en'      => 'نام انگلیسی',
    'brand'        => 'برند',
    'category'     => 'دسته',
    'size_label'   => 'اندازه',
    'cost_aed'     => 'قیمت خرید (درهم)',
    'cost_toman'   => 'قیمت خرید (تومان)',
    'profit_toman' => 'سود (تومان)',
    'price_toman'  => 'قیمت فروش (تومان)',
    'promo_toman'  => 'تخفیف (تومان)',
    'commission'   => 'درصد همکاری',
    'in_stock'     => 'موجود',
    'flavors'      => 'طعم‌ها',
    'servings'     => 'تعداد وعده',
    'features'     => 'مشخصات کلیدی',
    'short'        => 'توضیح کوتاه',
    'description'  => 'توضیح کامل',
    /* Added with the Excel import (2026-10), so a sheet can carry everything
       the product form does. Appended rather than inserted: a CSV written
       before them still lines up, and the order a person fills them in is
       IMPORT_LAYOUT's (lib/import_sheet.php), not this one's. */
    'stock_qty'    => 'موجودی انبار',
    'is_active'    => 'نمایش در سایت',
    'badges'       => 'نشان‌ها',
    'sort_order'   => 'ترتیب نمایش',
    'images'       => 'عکس‌ها',
    'ingredients'  => 'ترکیبات',
    'usage'        => 'روش مصرف',
    'nutrition_serving' => 'اندازه هر سروینگ',
    'nutrition'    => 'ارزش غذایی',
    /* A picture per flavour (2026-10): «نام طعم: نام فایل», a line each.
       Appended for the same reason as the columns above. */
    'flavor_images' => 'عکس طعم‌ها',
];

/* Other headings a person may reasonably type for a column. Matched the same
   lenient way as the real ones (import_header_key()). */
const IMPORT_ALIASES = [
    'عکس'              => 'images',
    'تصاویر'           => 'images',
    'تصویر'            => 'images',
    'عکس محصول'        => 'images',
    'موجودی'           => 'stock_qty',
    'تعداد موجودی'     => 'stock_qty',
    'تعداد در انبار'   => 'stock_qty',
    'نشان'             => 'badges',
    'ترتیب'            => 'sort_order',
    'نمایش'            => 'is_active',
    'روش استفاده'      => 'usage',
    'اندازه هر وعده'   => 'nutrition_serving',
    'جدول ارزش غذایی'  => 'nutrition',
    'طعم'              => 'flavors',
    'عکس طعم'          => 'flavor_images',
    'عکس هر طعم'       => 'flavor_images',
    'تصویر طعم‌ها'     => 'flavor_images',
];

/** The sheet of a workbook the import reads; the first visible one otherwise. */
const IMPORT_SHEET = 'محصولات';

/* «نشان‌ها», as the panel's badge keys. Matched after import_name_key(). */
const IMPORT_BADGES = [
    'پرفروش'      => 'bestseller',
    'bestseller'  => 'bestseller',
    'جدید'        => 'new',
    'new'         => 'new',
    'تخفیف برتر'  => 'topdeal',
    'تخفیف ویژه'  => 'topdeal',
    'topdeal'     => 'topdeal',
];
const IMPORT_BADGE_LABELS = ['bestseller' => 'پرفروش', 'new' => 'جدید', 'topdeal' => 'تخفیف برتر'];

/* A cell that clears what is stored, where blank means «leave it alone». */
const IMPORT_CLEAR = ['-', '—', '–', 'هیچ', 'ندارد'];

/* Columns a row may leave empty. Everything else is either required or has a
   safe default; this list exists so the preview can say which blanks were
   deliberate rather than making the operator diff two spreadsheets. */
const IMPORT_OPTIONAL = [
    'name_en', 'brand', 'category', 'size_label', 'cost_toman', 'price_toman', 'promo_toman',
    'commission', 'in_stock', 'flavors', 'servings', 'features', 'short', 'description',
    'stock_qty', 'is_active', 'badges', 'sort_order', 'images', 'ingredients', 'usage',
    'nutrition_serving', 'nutrition',
];

/* A purchase price in toman below this is a dirham figure typed into the
   toman column — the mirror of the twenty-thousand guard on the dirham one.
   No supplement the shop resells is bought for less. */
const IMPORT_MIN_COST_TOMAN = 10000;

/* The characters a Persian keyboard and an Arabic keyboard disagree about.
   Both sides of every comparison go through this; nothing stored is changed. */
const IMPORT_NORMALISE = [
    "\u{064A}" => "\u{06CC}",   // ARABIC YEH      -> FARSI YEH
    "\u{0649}" => "\u{06CC}",   // ALEF MAKSURA    -> FARSI YEH
    "\u{0643}" => "\u{06A9}",   // ARABIC KAF      -> KEHEH
    "\u{0629}" => "\u{0647}",   // TEH MARBUTA     -> HEH
    "\u{200C}" => '',            // ZWNJ: «مولتی‌ویتامین» and «مولتی ویتامین»
    "\u{200F}" => '',            // RLM
    "\u{200E}" => '',            // LRM
];

/**
 * A product name reduced to what two people typing the same name would share.
 *
 * For MATCHING only. The stored name is whatever the sheet said - the shop's
 * own spelling is not the importer's to correct, and a name silently rewritten
 * on import is a worse surprise than one that looks slightly different.
 */
function import_name_key(string $name): string
{
    $n = strtr($name, IMPORT_NORMALISE);
    /* Collapse whitespace so «کراتین  مونوهیدرات» and «کراتین مونوهیدرات» meet. */
    $n = preg_replace('/\s+/u', ' ', $n);
    return mb_strtolower(trim((string) $n));
}

/**
 * Find an existing product by name and brand.
 *
 * The SQL normalises the stored side the same way import_name_key() normalises
 * the sheet side, so the two agree without needing a stored key column. The
 * catalogue is small enough that a full scan here costs nothing, and an index
 * that only works for one spelling would be worse than none.
 *
 * Brand is part of the identity because this is a multi-brand reseller: two
 * brands' «کراتین مونوهیدرات» are two products, and matching on name alone let
 * an import of one quietly overwrite the other.
 */
function import_find_product(string $nameFa, string $brand): ?int
{
    $key = import_name_key($nameFa);

    $sql = "SELECT id, brand FROM products
             WHERE LOWER(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(name_fa,
                     '\u{064A}', '\u{06CC}'),
                     '\u{0649}', '\u{06CC}'),
                     '\u{0643}', '\u{06A9}'),
                     '\u{0629}', '\u{0647}'),
                     '\u{200C}', ''),
                     '  ', ' '))) = ?";

    $rows = db_all($sql, [$key]);
    if (!$rows) {
        return null;
    }

    /* Same name, different brand: a different product. Same name and no brand
       on either side: the same one. */
    $brandKey = import_name_key($brand);
    foreach ($rows as $r) {
        if (import_name_key((string) ($r['brand'] ?? '')) === $brandKey) {
            return (int) $r['id'];
        }
    }
    /* A sheet that names no brand should still update a product that has one,
       rather than creating a second copy - that is the ordinary case of a price
       list that only carries names and prices. */
    if ($brandKey === '' && count($rows) === 1) {
        return (int) $rows[0]['id'];
    }
    return null;
}

/**
 * Read a CSV into rows keyed by our column names.
 *
 * Excel on a Persian Windows writes CSV in UTF-8 *with* a BOM, and often with
 * semicolons rather than commas because the list separator follows the locale.
 * Both are handled here rather than being explained to the person exporting.
 *
 * @return array{rows:array<int,array<string,string>>,error:?string}
 */
function import_read_csv(string $path): array
{
    $fh = @fopen($path, 'r');
    if ($fh === false) {
        return ['rows' => [], 'error' => 'فایل خوانده نشد.', 'columns' => []];
    }

    /* Seek PAST the BOM rather than stripping it from the parsed field.

       It has to happen at the stream, before fgetcsv sees the line. Excel
       quotes any header containing a space, so the first line begins
       BOM + '"' — and fgetcsv, finding those three bytes where it expects the
       enclosure, decides the field is not quoted at all and hands back a value
       with a literal quote character welded to the front. Stripping the BOM
       from the result afterwards leaves that quote behind, and the column
       matches nothing. */
    $bomOffset = fread($fh, 3) === "\xEF\xBB\xBF" ? 3 : 0;
    fseek($fh, $bomOffset);

    $first = fgets($fh);
    if ($first === false) {
        fclose($fh);
        return ['rows' => [], 'error' => 'فایل خالی است.', 'columns' => []];
    }

    /* The wrong CSV out of Excel, named before anything else can misdiagnose it.

       Excel offers «CSV (Comma delimited)» and «CSV UTF-8 (Comma delimited)»,
       and only the second is UTF-8; the first writes the system ANSI codepage,
       which for a Persian Windows is windows-1256 - a codepage that cannot
       represent Persian ی at all. The file that comes out is not merely
       differently encoded, it has already lost characters.

       Without this the failure still happened, safely, but as «ستون «نام
       فارسی» پیدا نشد» - which sends somebody who used the sample file and
       changed nothing looking for a problem that is not there. */
    if (!mb_check_encoding($first, 'UTF-8')) {
        fclose($fh);
        return ['rows' => [], 'columns' => [], 'error' =>
            'این فایل با کدگذاری UTF-8 ذخیره نشده و متن فارسی‌اش خوانده نمی‌شود. '
          . 'در اکسل موقع ذخیره، گزینه «CSV UTF-8 (Comma delimited)» را انتخاب کنید، '
          . 'نه «CSV (Comma delimited)» را — این دو یکی نیستند و دومی حرف «ی» فارسی را از دست می‌دهد.'];
    }

    /* Whichever separator appears more often in the header line is the one in
       use. Counting beats guessing from the locale, which we cannot see. */
    $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

    fseek($fh, $bomOffset);
    $header = fgetcsv($fh, 0, $delimiter);
    if ($header === false) {
        fclose($fh);
        return ['rows' => [], 'error' => 'سطر عنوان خوانده نشد.', 'columns' => []];
    }

    /* Map the sheet's header labels onto our keys. Matching on the Persian
       label means the seller can reorder or add columns without breaking it;
       matching leniently means «قیمت فروش(تومان)» or a template's «*» does
       not cost them a column either. */
    $map   = [];
    $known = import_header_map();
    foreach ($header as $i => $label) {
        $k = import_header_key((string) $label);
        if (isset($known[$k])) {
            $map[$i] = $known[$k];
        }
    }

    if (!in_array('name_fa', $map, true)) {
        fclose($fh);
        return ['rows' => [], 'columns' => [], 'error' =>
            'ستون «' . IMPORT_COLUMNS['name_fa'] . '» در فایل پیدا نشد. ' .
            'از همان فایل نمونه استفاده کنید و عنوان ستون‌ها را عوض نکنید.'];
    }

    $rows = [];
    $line = 1;
    while (($data = fgetcsv($fh, 0, $delimiter)) !== false) {
        $line++;
        /* fgetcsv yields [null] for a blank line. */
        if ($data === [null] || count(array_filter($data, static fn($v) => trim((string) $v) !== '')) === 0) {
            continue;
        }
        $row = ['_line' => $line];
        foreach ($map as $i => $key) {
            $row[$key] = trim((string) ($data[$i] ?? ''));
        }
        $rows[] = $row;
    }
    fclose($fh);

    /* Which columns the file actually carried. A column that is absent is
       not the same as a column left blank: a sheet without a commission column
       says nothing about commission, and must not clear the rates already
       agreed. */
    return ['rows' => $rows, 'error' => null, 'columns' => array_values($map)];
}

/**
 * Validate and shape rows into products.
 *
 * Rows sharing a name become one product with several sizes, which is how the
 * seller naturally writes a cost sheet — one line per thing they buy.
 *
 * @return array{products:array,errors:array<int,string>,warnings:array<int,string>}
 */
function import_plan(array $rows, array $columns = []): array
{
    $products = [];
    $errors   = [];
    $warnings = [];

    /* An empty list means "assume the file could speak about everything",
       which is what every caller predating this argument intended. */
    $has = static fn(string $key): bool => $columns === [] || in_array($key, $columns, true);

    $rate = pricing_rate();

    foreach ($rows as $row) {
        $line   = (int) $row['_line'];
        $nameFa = clean_text($row['name_fa'] ?? '', 180);

        if ($nameFa === '') {
            $errors[] = 'سطر ' . $line . ': نام فارسی خالی است.';
            continue;
        }

        $commission = import_percent($row['commission'] ?? '');
        if ($commission === false) {
            $errors[] = 'سطر ' . $line . ': درصد همکاری باید عددی بین ۰ تا ۱۰۰ باشد.';
            continue;
        }

        $costAedRaw = trim(to_latin_digits((string) ($row['cost_aed'] ?? '')));
        $costAed    = $costAedRaw === '' ? null : (float) str_replace(',', '', $costAedRaw);
        $costToman  = parse_money($row['cost_toman'] ?? '');
        $profit     = parse_money($row['profit_toman'] ?? '');
        $price      = parse_money($row['price_toman'] ?? '');
        $promo      = parse_money($row['promo_toman'] ?? '');
        $servings   = (int) to_latin_digits((string) ($row['servings'] ?? ''));

        /* «موجودی انبار» for this row's tub: blank leaves the count alone, a
           dash stops counting it, a number is the count. */
        $stockCell  = trim(to_latin_digits((string) ($row['stock_qty'] ?? '')));
        $stockGiven = $stockCell !== '';
        $stockQty   = null;
        if ($stockGiven && !in_array($stockCell, IMPORT_CLEAR, true)) {
            $stockNum = str_replace([',', '٬', ' '], '', $stockCell);
            if (!preg_match('/^\d{1,7}$/', $stockNum)) {
                $errors[] = 'سطر ' . $line . ': موجودی انبار باید عدد باشد '
                    . '(یا خط تیره، یعنی «شمرده نشود»).';
                continue;
            }
            $stockQty = (int) $stockNum;
        }

        if ($costAed !== null && $costAed <= 0) {
            $errors[] = 'سطر ' . $line . ': قیمت خرید باید بزرگ‌تر از صفر باشد.';
            continue;
        }

        /* A cost typed in toman is the mistake that actually happens, and it is
           catchable here: a dirham price for a supplement is tens, not
           millions. Refused rather than warned about, because the resulting
           shelf price would be absurd and someone would have to notice. */
        if ($costAed !== null && $costAed > 20000) {
            $errors[] = 'سطر ' . $line . ': قیمت خرید ' . number_format($costAed) .
                ' درهم غیرواقعی است — احتمالاً به تومان وارد شده.';
            continue;
        }

        /* One purchase price per row. Both filled is not a richer answer, it
           is two different claims about what the tub cost, and nothing in the
           file says which one the shop actually paid. */
        if ($costAed !== null && $costToman > 0) {
            $errors[] = 'سطر ' . $line . ': فقط یکی از دو ستون قیمت خرید ' .
                '(درهم یا تومان) را پر کنید، نه هر دو را.';
            continue;
        }

        /* The mirror of the dirham guard: a supplement is not bought for a few
           thousand toman, so a small number here is a dirham figure that
           landed in the wrong column. */
        if ($costToman > 0 && $costToman < IMPORT_MIN_COST_TOMAN) {
            $errors[] = 'سطر ' . $line . ': قیمت خرید ' . number_format($costToman) .
                ' تومان غیرواقعی است — احتمالاً به درهم وارد شده.';
            continue;
        }

        if ($costAed === null && $costToman <= 0 && $price <= 0) {
            $errors[] = 'سطر ' . $line . ': یکی از این سه لازم است: ' .
                'قیمت خرید به درهم، قیمت خرید به تومان، یا قیمت فروش.';
            continue;
        }

        if ($costAed !== null && $profit <= 0) {
            $errors[] = 'سطر ' . $line . ': برای قیمت‌گذاری درهمی، مقدار سود لازم است.';
            continue;
        }

        /* A toman cost with neither a profit nor a price says what the tub
           cost and nothing about what it sells for. */
        if ($costToman > 0 && $profit <= 0 && $price <= 0) {
            $errors[] = 'سطر ' . $line . ': با قیمت خرید تومانی، یا سود لازم است ' .
                'یا قیمت فروش.';
            continue;
        }

        /* A cost that is not below the price is a transposition, and it would
           publish a product the shop loses money on every time it sells. */
        if ($costToman > 0 && $price > 0 && $price <= $costToman) {
            $errors[] = 'سطر ' . $line . ': قیمت فروش (' . number_format($price) .
                ') از قیمت خرید (' . number_format($costToman) .
                ') بیشتر نیست — احتمالاً جابه‌جا شده‌اند.';
            continue;
        }

        /* A discount in this system is an amount off the margin, and the cap
           that stops it eating the margin needs a margin to measure. On a typed
           price with no cost there is none, so the discount was being capped to
           zero and silently dropped while the preview still showed it. Refused
           instead: one more cell makes the number mean something. */
        if ($promo > 0 && $costAed === null && $costToman <= 0) {
            $errors[] = 'سطر ' . $line . ': برای تخفیف، قیمت خرید لازم است — ' .
                'بدون آن معلوم نیست تخفیف از چه چیزی کم می‌شود.';
            continue;
        }

        /* pricing_compute() caps a promo at half the profit rather than
           refusing it, so an over-large discount would quietly become a
           smaller one. Say so here instead — a discount the operator typed
           and a discount the shop applied being different numbers is exactly
           the kind of thing nobody notices until a customer does. */
        if ($promo > 0 && $profit > 0 && $promo > $profit * PRICING_MAX_PROMO_SHARE) {
            $warnings[] = 'سطر ' . $line . ': تخفیف ' . number_format($promo) .
                ' بیش از نیمی از سود است و تا ' .
                number_format((int) floor($profit * PRICING_MAX_PROMO_SHARE)) . ' کاهش داده می‌شود.';
        }

        /* Dirham-priced rows need a rate before a shelf price can exist. The
           import still succeeds — the pricing page fills them in — but say so,
           because otherwise the products appear with a price of zero and it
           looks like the import lost them. */
        if ($costAed !== null && $rate === null) {
            $warnings[] = 'سطر ' . $line . ': نرخ درهم هنوز ثبت نشده، پس قیمت این قلم بعد از ' .
                'وارد کردن نرخ در صفحه قیمت‌گذاری محاسبه می‌شود.';
        }

        $key = mb_strtolower($nameFa);
        if (!isset($products[$key])) {
            $products[$key] = [
                'name_fa'  => $nameFa,
                'name_en'  => clean_text($row['name_en'] ?? '', 180),
                'brand'    => clean_text($row['brand'] ?? '', 120),
                'category' => clean_text($row['category'] ?? '', 120),
                'short'    => clean_text($row['short'] ?? '', 500),
                /* Paragraphs kept, as the panel's own editor keeps them. */
                'description' => clean_multiline($row['description'] ?? '', 5000),
                'flavors'  => import_flavors($row['flavors'] ?? ''),
                'features' => import_features($row['features'] ?? ''),
                'in_stock' => import_truthy($row['in_stock'] ?? '1'),
                /* Distinguished from its value: "in stock" is what a blank
                   cell means on a NEW product, but on an existing one a blank
                   cell must not overrule someone who marked it unavailable an
                   hour ago. */
                'in_stock_given' => $has('in_stock')
                    && trim((string) ($row['in_stock'] ?? '')) !== '',
                'has_commission_column' => $has('commission'),
                'sizes'    => [],
                'lines'    => [],
                /* The Excel columns. Null is «the sheet said nothing», which
                   on an update leaves the stored value alone. */
                'ingredients'       => '',
                'usage'             => '',
                'nutrition_serving' => '',
                'nutrition'         => [],
                'images'            => [],
                /* flavour name => photograph name, from «عکس طعم‌ها». */
                'flavor_images'     => [],
                'badges'            => null,
                'is_active'         => null,
                'sort_order'        => null,
                /* Read with the names, from the same cell: the first row
                   fills the names in here, so the block below never runs for it. */
                'premiums'          => import_flavor_premiums($row['flavors'] ?? ''),
                'photo_status'      => [],
                'flavor_photo_status' => [],
            ];
        }

        /* Description and flavours belong to the product, not the tub, and the
           seller writes them on whichever row they happen to be thinking about.
           Take the first non-empty value rather than letting a later blank row
           erase what an earlier one said. */
        if ($products[$key]['description'] === '') {
            $products[$key]['description'] = clean_multiline($row['description'] ?? '', 5000);
        }
        if (!$products[$key]['flavors']) {
            $products[$key]['flavors']  = import_flavors($row['flavors'] ?? '');
            $products[$key]['premiums'] = import_flavor_premiums($row['flavors'] ?? '');
        }
        if ($products[$key]['ingredients'] === '') {
            $products[$key]['ingredients'] = clean_multiline($row['ingredients'] ?? '', 2000);
        }
        if ($products[$key]['usage'] === '') {
            $products[$key]['usage'] = clean_multiline($row['usage'] ?? '', 2000);
        }
        if ($products[$key]['nutrition_serving'] === '') {
            $products[$key]['nutrition_serving'] = clean_text($row['nutrition_serving'] ?? '', 180);
        }
        if (!$products[$key]['nutrition']) {
            $products[$key]['nutrition'] = import_nutrition($row['nutrition'] ?? '');
        }
        if (!$products[$key]['images']) {
            $products[$key]['images'] = import_photo_list($row['images'] ?? '');
        }
        if (!$products[$key]['flavor_images']) {
            $products[$key]['flavor_images'] = import_flavor_photos($row['flavor_images'] ?? '');
        }

        $badgeCell = trim((string) ($row['badges'] ?? ''));
        if ($badgeCell !== '' && $products[$key]['badges'] === null) {
            $badges = import_badges($badgeCell);
            if ($badges === null) {
                $errors[] = 'سطر ' . $line . ': نشان «' . $badgeCell . '» شناخته نشد. '
                    . 'یکی یا چندتا از این‌ها: پرفروش، جدید، تخفیف برتر — یا خط تیره برای پاک کردن.';
            } else {
                $products[$key]['badges'] = $badges;
            }
        }
        $activeCell = trim((string) ($row['is_active'] ?? ''));
        if ($activeCell !== '' && $products[$key]['is_active'] === null) {
            $products[$key]['is_active'] = import_truthy($activeCell);
        }
        $sortCell = trim(to_latin_digits((string) ($row['sort_order'] ?? '')));
        if ($sortCell !== '' && $products[$key]['sort_order'] === null) {
            if (!preg_match('/^-?\d{1,6}$/', $sortCell)) {
                $errors[] = 'سطر ' . $line . ': ترتیب نمایش باید یک عدد صحیح باشد.';
            } else {
                $products[$key]['sort_order'] = (int) $sortCell;
            }
        }
        if (!$products[$key]['features']) {
            $products[$key]['features'] = import_features($row['features'] ?? '');
        }
        if ($products[$key]['short'] === '') {
            $products[$key]['short'] = clean_text($row['short'] ?? '', 500);
        }

        $products[$key]['lines'][] = $line;

        $sizeLabel = clean_text($row['size_label'] ?? '', 120);
        /* Three ways to reach a shelf price, in the order the shop thinks:

           dirham cost + profit  — tracks the rate, repriced from the pricing page
           toman cost  + profit  — fixed; bought inside Iran, so nothing to track
           a typed price          — the market set it, not the markup

           The third is the crowded-shelf case the shop described: where a
           competitor decides the price, the cost is still worth recording,
           because the profit on that line is exactly what the share is
           computed from. */
        $computed = null;
        if ($costAed !== null && $rate !== null) {
            $computed = pricing_compute([
                'cost_aed'     => $costAed,
                'profit_toman' => $profit,
                'promo_toman'  => $promo,
            ], $rate);
        } elseif ($costToman > 0 && $profit > 0) {
            $computed = pricing_from_cost($costToman, $profit, $promo);
        } elseif ($price > 0) {
            /* A typed price is the normal price, so a discount beside it still
               has to come off. Without this the promo was stored and never
               applied: the row showed no saving, no strikethrough, and nothing
               anywhere said why. The margin it is capped against is the one
               this route actually has — price minus cost — rather than a
               profit column that is deliberately empty here. */
            $margin   = $costToman > 0 ? max(0, $price - $costToman) : 0;
            $computed = pricing_from_cost(max(0, $price - $margin), $margin, $promo);
        }

        /* Whichever route was taken, the cost in toman is known now, so it is
           recorded now. Orders read cost_toman to snapshot cost and profit, and
           a catalogue that has been imported but not yet repriced would
           otherwise book every sale as pure profit — which is the number the
           partnership share is computed from. */
        /* Only a cost the row actually stated. On the typed-price route with
           no cost, pricing_from_cost() is handed the price itself as the cost,
           so $computed['cost'] there is the shelf price wearing a cost's name -
           and storing it made every order book zero profit on a line the shop
           genuinely earns on. Unknown has to stay NULL: order_price_line()
           already treats a missing cost as unknown rather than as zero. */
        $costInToman = $costToman > 0
            ? $costToman
            : (($costAed !== null && $computed !== null) ? (int) $computed['cost'] : null);

        $products[$key]['sizes'][] = [
            'label'        => $sizeLabel,
            'cost_aed'     => $costAed,
            'cost_toman'   => $costInToman,
            'profit_toman' => $profit > 0 ? $profit : null,
            /* The ratio this sheet's numbers imply. Recomputed on every import
               rather than left alone, because a sheet that changes the profit
               on a product whose ratio is already locked would otherwise be
               silently ignored: the toman figure would land in the column and
               the old ratio would still win at the next repricing. */
            'profit_percent' => ($costInToman !== null && $profit > 0)
                ? pricing_percent_from((int) $costInToman, (int) $profit)
                : null,
            'promo_toman'  => $promo > 0 ? $promo : null,
            'applied_rate' => ($costAed !== null && $computed !== null) ? $rate : null,
            'commission'   => $commission,
            'servings'     => $servings > 0 ? $servings : null,
            'stock_given'  => $stockGiven,
            'stock_qty'    => $stockQty,
            'price'        => $computed !== null ? $computed['price'] : $price,
            'compare_at'   => $computed !== null ? $computed['compare_at'] : null,
            'line'         => $line,
        ];
    }

    /* A sheet row with no size, for a product that already has sizes, is the
       quietest destructive act available: apply replaces sizes wholesale, so
       the variants and every cost and rate on them would vanish, and the
       success message would say one product updated.

       Refused rather than skipped. The operator either lists the sizes or
       takes the product out of the sheet - both are ten seconds, and both are
       a decision somebody made. */
    foreach ($products as $p) {
        $unnamed = count($p['sizes']) === 1 && $p['sizes'][0]['label'] === '';
        if (!$unnamed) {
            continue;
        }
        $existingId = import_find_product($p['name_fa'], (string) ($p['brand'] ?? ''));
        if ($existingId === null) {
            continue;
        }
        $existingSizes = (int) db_value(
            'SELECT COUNT(*) FROM product_sizes WHERE product_id = ?', [(int) $existingId]);
        if ($existingSizes > 0) {
            $errors[] = 'سطر ' . $p['sizes'][0]['line'] . ': «' . $p['name_fa'] .
                '» در فروشگاه ' . $existingSizes . ' اندازه دارد، ' .
                'ولی در فایل یک سطر بدون اندازه آمده است. ' .
                'یا همه اندازه‌ها را در فایل بنویسید، یا این سطر را بردارید.';
        }
    }

    /* A product with exactly one unnamed size is a single-price product, not a
       product with one variant. Collapsing it here keeps the storefront from
       showing a pointless size selector with one option in it. */
    foreach ($products as $k => $p) {
        if (count($p['sizes']) === 1 && $p['sizes'][0]['label'] === '') {
            $products[$k]['single'] = $p['sizes'][0];
            $products[$k]['sizes']  = [];
        } else {
            foreach ($p['sizes'] as $i => $s) {
                if ($s['label'] === '') {
                    $errors[] = 'سطر ' . $s['line'] . ': این محصول چند اندازه دارد، ' .
                        'پس ستون اندازه نمی‌تواند خالی باشد.';
                    unset($products[$k]['sizes'][$i]);
                }
            }
            $products[$k]['sizes'] = array_values($products[$k]['sizes']);
        }
    }

    /* A flavour's premium is in the unit the product is bought in: dirhams
       beside a dirham cost, toman otherwise - the same rule as the product
       form's flavour column. Checked here, once the whole product is known. */
    foreach ($products as $k => $p) {
        if (!$p['premiums']) {
            continue;
        }
        $line  = (int) ($p['lines'][0] ?? 0);
        $aed   = import_buys_in_aed($p);
        $clean = [];
        foreach ($p['premiums'] as $name => $raw) {
            $raw = trim(to_latin_digits((string) $raw));
            if (in_array($raw, array_merge(['0'], IMPORT_CLEAR), true)) {
                $clean[$name] = ['aed' => null, 'toman' => 0];
                continue;
            }
            if ($aed) {
                $v = pricing_parse_aed($raw);
                if ($v === null || $v <= 0) {
                    $errors[] = 'سطر ' . $line . ': اضافه‌قیمت طعم «' . $name . '» عدد نیست.';
                } elseif ($v > PRICING_MAX_COST_AED) {
                    $errors[] = 'سطر ' . $line . ': اضافه‌قیمت طعم «' . $name . '» ' . number_format($v)
                        . ' درهم است — احتمالاً به تومان نوشته شده. این محصول با درهم خریده می‌شود.';
                } else {
                    $clean[$name] = ['aed' => $v, 'toman' => 0];
                }
            } else {
                $v = parse_money($raw);
                if ($v <= 0) {
                    $errors[] = 'سطر ' . $line . ': اضافه‌قیمت طعم «' . $name . '» عدد نیست.';
                } elseif ($v < 1000) {
                    $errors[] = 'سطر ' . $line . ': اضافه‌قیمت طعم «' . $name . '» ' . number_format($v)
                        . ' تومان است — احتمالاً به درهم نوشته شده. این محصول با تومان قیمت‌گذاری می‌شود.';
                } else {
                    $clean[$name] = ['aed' => null, 'toman' => $v];
                }
            }
        }
        $products[$k]['premiums'] = $clean;
    }

    /* Photographs: each named file is waiting (uploaded on the import page),
       already on this product from an earlier import, or missing - which is
       said, and does not stop the rest. */
    $photosOk = import_photos_ready();
    $claimed  = [];
    $toldMigrate = false;
    foreach ($products as $k => $p) {
        if (!$p['images']) {
            continue;
        }
        $line = (int) ($p['lines'][0] ?? 0);
        if (!$photosOk) {
            if (!$toldMigrate) {
                $warnings[] = 'برای وصل کردن عکس‌ها، اول یک بار «به‌روزرسانی دیتابیس» را اجرا کنید؛ '
                    . 'بقیهٔ فایل بدون عکس ثبت می‌شود.';
                $toldMigrate = true;
            }
            continue;
        }
        $existing = import_find_product($p['name_fa'], (string) ($p['brand'] ?? ''));
        foreach ($p['images'] as $photo) {
            $status = 'missing';
            if ($existing !== null && db_value(
                    'SELECT 1 FROM product_images WHERE product_id = ? AND source_name = ?',
                    [$existing, $photo]) !== null) {
                $status = 'attached';
            } elseif (import_photo_find($photo) !== null) {
                $status = 'ready';
                if (isset($claimed[$photo])) {
                    $warnings[] = 'سطر ' . $line . ': عکس «' . $photo . '» برای «' . $claimed[$photo]
                        . '» هم آمده و فقط به همان وصل می‌شود.';
                    $status = 'missing';
                } else {
                    $claimed[$photo] = $p['name_fa'];
                }
            } else {
                $warnings[] = 'سطر ' . $line . ': عکس «' . $photo . '» پیدا نشد. '
                    . 'اول آن را در بخش «عکس‌ها»ی همین صفحه بارگذاری کنید؛ بدون آن، محصول بی‌عکس ثبت می‌شود.';
            }
            $products[$k]['photo_status'][$photo] = $status;
        }
    }

    /* A picture per flavour: for a flavour this product will have, and a file
       that is waiting, is one of its own gallery photographs, or - on a
       product already in the shop whose flavour has a picture - was used
       before (a re-import of the same sheet keeps it, quietly). */
    foreach ($products as $k => $p) {
        if (!$p['flavor_images']) {
            continue;
        }
        $line = (int) ($p['lines'][0] ?? 0);
        if (!$photosOk) {
            if (!$toldMigrate) {
                $warnings[] = 'برای وصل کردن عکس‌ها، اول یک بار «به‌روزرسانی دیتابیس» را اجرا کنید؛ '
                    . 'بقیهٔ فایل بدون عکس ثبت می‌شود.';
                $toldMigrate = true;
            }
            continue;
        }
        $existing = import_find_product($p['name_fa'], (string) ($p['brand'] ?? ''));
        $have = [];
        foreach ($existing === null ? [] : db_all(
                'SELECT name, image FROM product_flavors WHERE product_id = ?', [$existing]) as $f) {
            $have[import_name_key((string) $f['name'])] = (string) $f['image'];
        }
        /* The sheet's flavours when it names some; otherwise the product keeps
           the ones it has (a blank «طعم‌ها» changes nothing). */
        $known = [];
        foreach ($p['flavors'] ?: [] as $f) {
            $known[import_name_key((string) $f)] = true;
        }
        if (!$known) {
            $known = array_fill_keys(array_keys($have), true);
        }
        foreach ($p['flavor_images'] as $flavor => $photo) {
            $fk = import_name_key((string) $flavor);
            if (!isset($known[$fk])) {
                $warnings[] = 'سطر ' . $line . ': طعم «' . $flavor . '» در ستون «طعم‌ها»ی «' . $p['name_fa']
                    . '» نیست؛ عکسش به جایی وصل نمی‌شود.';
                $products[$k]['flavor_photo_status'][$flavor] = 'missing';
                continue;
            }
            $status = 'missing';
            if (isset($p['photo_status'][$photo]) && $p['photo_status'][$photo] !== 'missing') {
                $status = $p['photo_status'][$photo];
            } elseif (import_photo_find($photo) !== null) {
                $status = 'ready';
                if (isset($claimed[$photo]) && $claimed[$photo] !== $p['name_fa']) {
                    $warnings[] = 'سطر ' . $line . ': عکس «' . $photo . '» برای «' . $claimed[$photo]
                        . '» هم آمده و فقط به همان وصل می‌شود.';
                    $status = 'missing';
                } else {
                    $claimed[$photo] = $p['name_fa'];
                }
            } elseif ($existing !== null && db_value(
                    'SELECT 1 FROM product_images WHERE product_id = ? AND source_name = ?',
                    [$existing, $photo]) !== null) {
                $status = 'attached';
            } elseif (($have[$fk] ?? '') !== '') {
                $status = 'attached';
            } else {
                $warnings[] = 'سطر ' . $line . ': عکس طعم «' . $flavor . '» («' . $photo . '») پیدا نشد. '
                    . 'اول آن را در بخش «عکس‌ها»ی همین صفحه بارگذاری کنید؛ بدون آن، این طعم عکس جدا نمی‌گیرد.';
            }
            $products[$k]['flavor_photo_status'][$flavor] = $status;
        }
    }

    return ['products' => array_values($products), 'errors' => $errors, 'warnings' => $warnings];
}

/** Whether a planned product is bought in dirhams: any of its rows says so. */
function import_buys_in_aed(array $p): bool
{
    foreach (($p['sizes'] ?: [$p['single'] ?? null]) as $u) {
        if (is_array($u) && ($u['cost_aed'] ?? null) !== null) {
            return true;
        }
    }
    return false;
}

/**
 * A commission rate from a spreadsheet cell.
 *
 * Empty means "use the shop-wide rate", which is a real and common answer, so
 * it cannot be conflated with zero — a zero rate is a deliberate decision to
 * take nothing on a line, and the two must stay tellable apart.
 *
 * @return float|null|false  the rate, null for "inherit", false for nonsense
 */
function import_percent($raw)
{
    $v = trim(to_latin_digits((string) $raw));
    $v = str_replace(['%', '٪', ' '], '', $v);
    if ($v === '') {
        return null;
    }
    if (!is_numeric($v)) {
        return false;
    }
    $n = (float) $v;
    return ($n < 0 || $n > 100) ? false : round($n, 2);
}

/**
 * Flavours from one cell.
 *
 * Split on every separator a person might reach for. Someone filling fifty
 * rows will not be consistent about it, and refusing a slash where a comma was
 * expected teaches nothing useful.
 *
 * @return array<int,string>
 */
/**
 * The handful of facts the listing card shows on hover, one per line.
 *
 * Split on line breaks and semicolons but never on «،»: a feature is a
 * sentence - «ترکیب کنسانتره و ایزوله، با فیلتراسیون سرد» - and Persian
 * sentences are full of commas. The panel's own editor is a textarea taking
 * one per line, so a feature typed in either place reads the same.
 *
 * Three is what the card can show; a couple more are kept because the product
 * page lists them all.
 */
function import_features($raw): array
{
    $parts = preg_split("~[;؛|\r\n]+~u", (string) $raw) ?: [];
    $out   = [];
    foreach ($parts as $p) {
        $p = clean_text($p, 300);
        if ($p !== '' && !in_array($p, $out, true)) {
            $out[] = $p;
        }
    }
    return array_slice($out, 0, 8);
}

function import_flavors($raw): array
{
    $parts = preg_split("~[,،؛;/|\r\n]+~u", (string) $raw) ?: [];
    $out   = [];
    foreach ($parts as $p) {
        /* «وانیلی=150000»: the name is what comes before the premium. */
        $p = clean_text(preg_replace('/\s*=.*$/su', '', $p) ?? $p, 120);
        if ($p !== '' && !in_array($p, $out, true)) {
            $out[] = $p;
        }
    }
    return array_slice($out, 0, 20);
}

/** Persian and English ways of writing yes. */
/**
 * A stable, storable id for one flavour or size of a product.
 *
 * slugify() clamps at 110 characters, but both ext_id columns are VARCHAR(60)
 * (db/schema.sql:127 and :140). A descriptive flavour name overruns that
 * without looking anywhere near long enough to: «نعناع شکلاتی باسکین رابینز
 * (Baskin-Robbins Mint Chocolate Chip)» slugifies to exactly 61 characters,
 * and MySQL in strict mode answered with SQLSTATE[22001]. Because the throw
 * escaped import_apply() entirely, a six-file catalogue import stopped after
 * the fourth file, committed, with nothing on screen naming the flavour.
 *
 * Clamping can make two options of one product share an id, and neither table
 * has a unique key that would catch it, so $taken carries the ids already
 * handed out for this product and a collision gets a numeric suffix.
 */
function import_ext_id(string $label, string $fallback, array &$taken): string
{
    $ext = mb_substr(slugify($label), 0, 60);
    if ($ext === '') {
        $ext = $fallback;
    }
    if (isset($taken[$ext])) {
        /* Trim before appending so the suffix cannot push it back over 60. */
        $stem = mb_substr($ext, 0, 56);
        for ($n = 2; $n < 100; $n++) {
            $try = $stem . '-' . $n;
            if (!isset($taken[$try])) {
                $ext = $try;
                break;
            }
        }
    }
    $taken[$ext] = true;
    return $ext;
}

function import_truthy(string $v): bool
{
    $v = mb_strtolower(trim(to_latin_digits($v)));
    /* A blank cell is not a "no". The sheet's own instructions say an empty
       stock column means the product is available, and the empty string sitting
       in this list meant every row that left it blank imported as out of stock -
       invisible on the shop, with nothing saying why. On an UPDATE a blank cell
       is handled a level up, by in_stock_given, which leaves the column alone
       rather than asserting either answer. */
    return !in_array($v, ['0', 'no', 'false', 'خیر', 'ناموجود', 'نه'], true);
}

/**
 * Write the planned products.
 *
 * All or nothing: a half-imported catalogue is live at unknown prices with no
 * clean way back.
 *
 * @return array{created:int,updated:int,sizes:int}
 */
function import_apply(array $products, array $admin): array
{
    $created = 0;
    $updated = 0;
    $sizes   = 0;
    $photos  = 0;
    /* Commission changes are collected during the transaction and written to
       the log inside it, so a rolled-back import leaves no record of rates it
       did not actually set. */
    $rateLog = [];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($products as $p) {
            $categoryId = $p['category'] === '' ? null : import_category_id($p['category']);

            $single = $p['single'] ?? null;

            /* A product with sizes has no commission rate of its own unless
               every size agrees on one. Averaging them would invent a rate
               nobody chose, and picking the first would silently apply one
               tub's deal to another. Disagreement therefore leaves the parent
               NULL, and each size carries its own — which is exactly the
               order order_price_line() resolves them in. */
            $units = $p['sizes'] ?: ($single !== null ? [$single] : []);
            /* Keyed by a string, deliberately. array_unique() compares loosely,
               and under a loose comparison null equals 0.0 — which would fold
               "inherit the shop rate" and "take nothing on this line" into one
               value. Those are different decisions and must stay tellable
               apart all the way down. */
            $seen = [];
            foreach ($units as $u) {
                $seen[$u['commission'] === null ? 'inherit' : (string) $u['commission']]
                    = $u['commission'];
            }
            $parentRate = count($seen) === 1 ? reset($seen) : null;

            $fields = [
                'name_fa'      => $p['name_fa'],
                'name_en'      => $p['name_en'],
                'brand'        => $p['brand'],
                'category_id'  => $categoryId,
                'short'        => $p['short'],
                'description'  => $p['description'] !== '' ? $p['description'] : null,
                /* Same JSON shape the panel's textarea writes, so a product can
                   be started in the sheet and finished in the panel. */
                'features'     => $p['features'] ? json_encode_pretty($p['features']) : null,
                'ingredients'  => ($p['ingredients'] ?? '') !== '' ? $p['ingredients'] : null,
                'usage_text'   => ($p['usage'] ?? '') !== '' ? $p['usage'] : null,
                'nutrition_serving' => (string) ($p['nutrition_serving'] ?? ''),
                'nutrition_rows' => !empty($p['nutrition']) ? json_encode_pretty($p['nutrition']) : null,
                'badges'       => (string) ($p['badges'] ?? ''),
                'sort_order'   => (int) ($p['sort_order'] ?? 0),
                'in_stock'     => $p['in_stock'] ? 1 : 0,
                'is_active'    => ($p['is_active'] ?? true) ? 1 : 0,
                'price'        => $single !== null ? (int) $single['price'] : 0,
                'compare_at'   => $single !== null ? $single['compare_at'] : null,
                'cost_aed'     => $single !== null ? $single['cost_aed'] : null,
                /* Written straight through for a toman purchase. Orders read
                   cost_toman to snapshot their cost and profit, so a product
                   without it books its whole price as profit — which is the
                   number the partnership share is computed from. */
                'cost_toman'   => $single !== null ? $single['cost_toman'] : null,
                'price_applied_rate' => $single !== null ? $single['applied_rate'] : null,
                'profit_toman' => $single !== null ? $single['profit_toman'] : null,
                'profit_percent' => $single !== null ? ($single['profit_percent'] ?? null) : null,
                'promo_toman'  => $single !== null ? $single['promo_toman'] : null,
                'commission_percent' => $parentRate,
                /* Only a dirham cost makes a price worth re-deriving when the
                   rate moves. A toman-bought line stays where it was put. */
                'price_mode'   => ($single !== null && $single['cost_aed'] !== null) ? 'aed' : 'manual',
            ];

            /* A single-price product's count is its own row's. */
            if ($single !== null && !empty($single['stock_given'])) {
                $fields['stock_qty'] = $single['stock_qty'];
            }

            /* Matched on the Persian name, because that is what the seller
               retypes when they send an updated sheet — a slug would change
               with every edit to the name and re-import as a new product. */
            $existing = import_find_product($p['name_fa'], (string) ($p['brand'] ?? ''));

            if ($existing !== null) {
                $id = (int) $existing;

                /* Only what the sheet actually said. A blank cell in a
                   sixteen-column file is not an instruction to erase a
                   two-dozen-column product: it is a column the person filling
                   the sheet had no opinion about. */
                $update = $fields;
                foreach (['name_en', 'brand', 'short', 'description', 'category_id',
                          'features', 'ingredients', 'usage_text', 'nutrition_serving',
                          'nutrition_rows'] as $soft) {
                    if (($update[$soft] ?? null) === null || $update[$soft] === '') {
                        unset($update[$soft]);
                    }
                }
                /* Blank is «no opinion»; a dash already arrived as ''. */
                if (($p['badges'] ?? null) === null) {
                    unset($update['badges']);
                }
                if (($p['sort_order'] ?? null) === null) {
                    unset($update['sort_order']);
                }

                /* Only when the sheet says so in its own column. A product
                   taken off the shop on purpose - out of stock for a month, a
                   supplier dispute - must not come back because somebody
                   re-imported a price list that has no such column. */
                if (($p['is_active'] ?? null) === null) {
                    unset($update['is_active']);
                }

                /* Same reasoning one level down: a blank stock cell means "not
                   my department", and a file with no stock column at all says
                   nothing whatsoever about it. */
                if (empty($p['in_stock_given'])) {
                    unset($update['in_stock']);
                }

                /* A file without a commission column - the copy handed to
                   whoever types the catalogue in - must not clear the rates
                   the two parties agreed on the copy that had one. */
                if (!($p['has_commission_column'] ?? true)) {
                    unset($update['commission_percent']);
                }

                if (array_key_exists('commission_percent', $update)) {
                    $was = db_value('SELECT commission_percent FROM products WHERE id = ?', [$id]);
                    $was = $was === null ? null : (float) $was;
                    if ($was !== $parentRate) {
                        $rateLog[] = ['product', $id, $p['name_fa'], $was, $parentRate];
                    }
                }

                $set = implode(', ', array_map(static fn($c) => $c . ' = ?', array_keys($update)));
                db_query('UPDATE products SET ' . $set . ' WHERE id = ?',
                    array_merge(array_values($update), [$id]));
                $updated++;
            } else {
                $fields['slug'] = unique_slug(
                    slugify($p['name_en'] !== '' ? $p['name_en'] : $p['name_fa']), 'products');
                $cols = array_keys($fields);
                $id = db_insert(
                    'INSERT INTO products (' . implode(', ', $cols) . ') VALUES ('
                    . implode(', ', array_fill(0, count($cols), '?')) . ')',
                    array_values($fields));
                $created++;
                if ($parentRate !== null) {
                    $rateLog[] = ['product', $id, $p['name_fa'], null, $parentRate];
                }
            }

            /* Flavours are replaced only when the sheet names some. A blank
               cell means "not my department today", not "delete the flavours
               someone added in the editor last week". */
            if ($p['flavors']) {
                /* What the existing rows carry that the sheet has no column for,
                   keyed by NAME — the only thing a sheet and a flavour row both
                   know each other by, exactly as sizes are keyed by label below.

                   Without this, one export-and-reimport round trip zeroed every
                   flavour premium: the sheet carries the names, so the DELETE
                   fires, and the INSERT rebuilt the rows with cost_delta_aed
                   NULL and price_delta_toman back at its DEFAULT 0. Nothing
                   recovers it afterwards — pricing_sync_flavors() skips rows
                   with no dirham premium — so the shop would quietly sell the
                   expensive flavours at the plain price, and the backup page
                   recommends that very round trip for moving a catalogue. */
                $keptFlavor = [];
                foreach (db_all('SELECT ext_id, name, cost_delta_aed, price_delta_toman, image
                                   FROM product_flavors WHERE product_id = ?', [$id]) as $old) {
                    $keptFlavor[(string) $old['name']] = $old;
                }

                db_query('DELETE FROM product_flavors WHERE product_id = ?', [$id]);
                $takenFlavor = [];
                foreach ($p['flavors'] as $i => $flavor) {
                    $prevF = $keptFlavor[$flavor] ?? [];
                    db_query(
                        'INSERT INTO product_flavors
                            (product_id, ext_id, name, cost_delta_aed, price_delta_toman,
                             image, sort_order)
                         VALUES (?, ?, ?, ?, ?, ?, ?)',
                        /* The id this flavour already had, when it still has
                           one. Regenerating it is what the size rebuild above
                           refuses to do and for the same reason: it is the key
                           a basket stores and the key a combination is built
                           from, so a new one detaches both. Only a flavour the
                           sheet has just introduced gets a fresh id. */
                        [$id,
                         (string) ($prevF['ext_id'] ?? '') !== ''
                             ? (string) $prevF['ext_id']
                             : import_ext_id($flavor, 'f' . $i, $takenFlavor),
                         $flavor,
                         /* A premium the sheet wrote replaces the kept one;
                            a flavour written without one keeps what it had. */
                         isset($p['premiums'][$flavor])
                             ? $p['premiums'][$flavor]['aed']
                             : ($prevF['cost_delta_aed'] ?? null),
                         isset($p['premiums'][$flavor])
                             ? (int) $p['premiums'][$flavor]['toman']
                             : (int) ($prevF['price_delta_toman'] ?? 0),
                         /* A picture is uploaded one flavour at a time and no
                            sheet has a column for it, so losing it to a round
                            trip costs more than losing a number would. */
                         (string) ($prevF['image'] ?? ''), $i]
                    );
                }

                /* A dirham premium the sheet just set becomes a toman one at
                   today's rate, as the form does on save. Only then: a re-import
                   that names no premium must not move the stored ones. */
                if (!empty($p['premiums']) && import_buys_in_aed($p)) {
                    $rateNow = pricing_rate();
                    if ($rateNow !== null && $rateNow > 0) {
                        pricing_sync_flavors($id, (float) $rateNow);
                    }
                }
            }

            /* Sizes are replaced wholesale — the sheet is the source of truth,
               and a size dropped from it should disappear rather than linger. */
            /* What the existing size rows carry, keyed by label - the one
               thing the sheet and the shop both name a size by.

               ext_id matters most: it is what a shopper's cart stores, and
               regenerating it from the label on every import ('900' becoming
               '900-گرم') detaches every cart holding that size.
               order_price_line() then finds no matching row and prices from the
               parent, so a 2270g tub in somebody's basket quietly becomes a
               900g one at checkout.

               The rate rides along for the same reason as the parent row one
               level up: a file with no commission column says nothing about
               the rates on the sizes either. */
            $keptExt   = [];
            $keptRates = [];
            /* The count of each tub, too. The rows are rebuilt below, and a
               price list re-imported without a stock column was setting every
               counted size back to «not counted». */
            $keptStock = [];
            foreach (db_all('SELECT ext_id, label, commission_percent, stock_qty
                               FROM product_sizes WHERE product_id = ?', [$id]) as $old) {
                $key = (string) $old['label'];
                $keptExt[$key]   = (string) $old['ext_id'];
                $keptRates[$key] = $old['commission_percent'] === null
                    ? null : (float) $old['commission_percent'];
                $keptStock[$key] = $old['stock_qty'] === null ? null : (int) $old['stock_qty'];
            }

            db_query('DELETE FROM product_sizes WHERE product_id = ?', [$id]);
            /* The ids carried over from the existing rows are already spoken
               for, so a freshly generated one cannot clamp into a collision
               with a size that some shopper's cart is still pointing at. */
            $takenSize = array_fill_keys(array_values($keptExt), true);
            foreach ($p['sizes'] as $i => $s) {
                $ext  = $keptExt[$s['label']] ?? import_ext_id($s['label'], 's' . $i, $takenSize);
                $rate = ($p['has_commission_column'] ?? true)
                    ? $s['commission']
                    : ($keptRates[$s['label']] ?? null);

                db_query(
                    'INSERT INTO product_sizes
                        (product_id, ext_id, label, servings, price, compare_at,
                         cost_aed, cost_toman, profit_toman, profit_percent, promo_toman,
                         price_applied_rate, commission_percent, stock_qty, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$id, $ext, $s['label'], $s['servings'],
                     (int) $s['price'], $s['compare_at'],
                     $s['cost_aed'], $s['cost_toman'], $s['profit_toman'],
                     $s['profit_percent'] ?? null, $s['promo_toman'],
                     $s['applied_rate'], $rate,
                     !empty($s['stock_given']) ? $s['stock_qty'] : ($keptStock[$s['label']] ?? null),
                     $i]
                );
                $sizes++;
                if ($s['commission'] !== null && $s['commission'] !== $parentRate) {
                    $rateLog[] = ['size', $id,
                        $p['name_fa'] . ' — ' . $s['label'], null, $s['commission']];
                }
            }

            if ($p['sizes']) {
                /* Decided for every sized product, not only for priced ones.
                   admin/import.php invites importing before a dirham rate
                   exists, and then every size prices at zero - so this block's
                   old home inside the cheapest-size guard never ran, the
                   product stayed 'manual', and pricing_units() (which selects
                   only price_mode = "aed") could never reprice it. The
                   catalogue would sit at zero with the pricing page reporting
                   nothing to do. */
                $anyAed = (int) db_value(
                    'SELECT COUNT(*) FROM product_sizes
                      WHERE product_id = ? AND cost_aed IS NOT NULL', [$id]) > 0;
                db_query('UPDATE products SET price_mode = ? WHERE id = ?',
                    [$anyAed ? 'aed' : 'manual', $id]);

                /* The card price tracks the cheapest size, same rule the
                   pricing page uses. */
                $cheapest = db_one(
                    'SELECT price, compare_at, cost_toman, price_applied_rate
                       FROM product_sizes
                      WHERE product_id = ? AND price > 0
                      ORDER BY price LIMIT 1', [$id]);
                if ($cheapest !== null) {
                    /* The card tracks the cheapest size, and it has to carry
                       that size's cost too: a product row showing one size's
                       price against another size's cost is the mismatch that
                       corrupted the commission ledger once already.

                       price_mode follows the sizes rather than being forced to
                       "aed" — a catalogue bought in toman is not repriced when
                       the dirham moves, and marking it dirham-linked would put
                       it in the pricing preview asking to be changed. */
                    db_query('UPDATE products
                                 SET price = ?, compare_at = ?, cost_toman = ?,
                                     price_applied_rate = ?
                               WHERE id = ?',
                        [(int) $cheapest['price'],
                         $cheapest['compare_at'] === null ? null : (int) $cheapest['compare_at'],
                         $cheapest['cost_toman'] === null ? null : (int) $cheapest['cost_toman'],
                         $cheapest['price_applied_rate'], $id]);
                }
            }

            /* The axes are built from the two tables just rebuilt, so a
               converted product has to be brought back into step. Otherwise
               it goes on selling a flavour the sheet dropped and never offers
               one the sheet added.

               Only when it ALREADY has combinations. Converting a product is
               a decision the owner makes one at a time, and a spreadsheet
               must not make it for the whole catalogue.

               Every combination whose key survived keeps the price, the count
               and the picture that were typed into the grid - adopt skips a
               key that already exists. What the sheet removed is retired;
               what it added arrives unpriced, which the readiness page
               already refuses to launch with. */
            if (variants_has($id)) {
                variants_adopt($id);
                variants_prune($id);
                variants_generate($id);
                /* And the card back onto the cheapest combination on sale.
                   The block above set it from the sizes, whose figures a
                   converted product no longer sells at, so re-importing a
                   sheet - one product's export, say - put a price on the
                   card that nothing on the product page matched. */
                pricing_sync_parent($id);
            }

            /* The photographs the sheet named, attached in its order. */
            if (!empty($p['images']) && import_photos_ready()) {
                $photos += import_attach_photos($id, $p['images']);
            }
            /* Then each flavour's own picture - after the gallery, so a
               photograph named in both places is found where it went. */
            if (!empty($p['flavor_images']) && import_photos_ready()) {
                $photos += import_attach_flavor_photos($id, $p['flavor_images']);
            }

            /* The sheet has no struck-price column, so a figure the owner typed
               in the panel is not the sheet's to erase: put it back beside the
               prices just written. */
            pricing_place_manual_compare($id);
        }

        /* A commission rate decides money moving between two parties, so a
           bulk import changing sixty of them is exactly the event the log
           exists for — more so than a single edit in the product form. */
        foreach ($rateLog as [$scope, $scopeId, $label, $old, $new]) {
            db_query(
                'INSERT INTO commission_log
                    (scope, scope_id, label, old_percent, new_percent, admin_id, admin_name)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$scope, $scopeId, $label, $old, $new,
                 $admin['id'] ?? null, (string) ($admin['name'] ?? '')]
            );
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[suppex] import failed: ' . $e->getMessage());
        throw $e;
    }

    return ['created' => $created, 'updated' => $updated, 'sizes' => $sizes,
            'rates' => count($rateLog), 'photos' => $photos];
}

/** Find a category by name, creating it if the sheet names a new one. */
function import_category_id(string $name): int
{
    $id = db_value('SELECT id FROM categories WHERE name_fa = ?', [$name]);
    if ($id !== null) {
        return (int) $id;
    }
    return db_insert(
        'INSERT INTO categories (slug, name_fa, sort_order, is_active) VALUES (?, ?, 99, 1)',
        [unique_slug(slugify($name), 'categories'), $name]
    );
}

/**
 * The sheet the seller fills in.
 *
 * Shipped with worked examples rather than an empty grid, because the columns
 * that get filled in wrongly are always the same three: a size written as one
 * cell with a slash in it instead of two rows, a dirham cost typed in toman,
 * and a commission left at 0 when the intent was "whatever the shop rate is".
 * An example row answers all three without anybody reading instructions.
 */
function import_template_csv(): string
{
    /* Keyed, never positional. Sixteen values under a seventeen-column header
       is how the short description ended up in the مشخصات کلیدی column the
       first time the sheet grew one - silently, because the file still parsed.
       A key that does not exist now raises instead. */
    $examples = [
        /* Two rows, one product: this is the pattern to copy. */
        ['name_fa' => 'وی پروتئین اورجینال', 'name_en' => 'Whey Original',
         'brand' => 'SUPPEX', 'category' => 'پروتئین', 'size_label' => '900 گرم',
         'cost_aed' => '40', 'profit_toman' => '1500000', 'in_stock' => 'بله',
         'flavors' => 'شکلاتی، وانیلی، توت فرنگی', 'servings' => '30',
         'features' => "۲۴ گرم پروتئین در هر پیمانه\nبدون قند افزوده، بدون رنگ مصنوعی",
         'short' => 'وی کنسانتره و ایزوله میکروفیلتر شده',
         'description' => 'هر وعده ۲۴ گرم پروتئین دارد. بعد از تمرین با آب یا شیر مصرف شود.'],
        ['name_fa' => 'وی پروتئین اورجینال', 'name_en' => 'Whey Original',
         'brand' => 'SUPPEX', 'category' => 'پروتئین', 'size_label' => '2270 گرم',
         'cost_aed' => '95', 'profit_toman' => '2000000', 'in_stock' => 'بله',
         'servings' => '76'],
        /* One size, so the size column stays empty. */
        ['name_fa' => 'کراتین مونوهیدرات', 'name_en' => 'Creatine Monohydrate',
         'brand' => 'SUPPEX', 'category' => 'کراتین',
         'cost_aed' => '18', 'profit_toman' => '900000', 'in_stock' => 'بله',
         'servings' => '100',
         'features' => "۵ گرم کراتین خالص در هر وعده\nبدون نیاز به دوره بارگیری",
         'short' => 'کراتین میکرونایز خالص'],
        /* Bought inside Iran: the toman column instead of the dirham one. */
        ['name_fa' => 'مولتی ویتامین روزانه', 'name_en' => 'Daily Multivitamin',
         'brand' => 'SUPPEX', 'category' => 'ویتامین و مکمل',
         'cost_toman' => '650000', 'profit_toman' => '600000', 'in_stock' => 'بله',
         'servings' => '60', 'short' => 'مولتی ویتامین کامل روزانه'],
        /* A crowded line: the market sets the price, so it is typed in and the
           cost is recorded beside it. */
        ['name_fa' => 'بی سی آمینو', 'name_en' => 'BCAA 2:1:1',
         'brand' => 'SUPPEX', 'category' => 'آمینو و BCAA',
         'cost_toman' => '820000', 'price_toman' => '1150000',
         'promo_toman' => '50000', 'commission' => '8', 'in_stock' => 'بله',
         'flavors' => 'هندوانه، لیمو', 'servings' => '40',
         'short' => 'آمینو شاخه‌دار با نسبت ۲:۱:۱'],
    ];

    $rows = [array_values(IMPORT_COLUMNS)];
    foreach ($examples as $ex) {
        foreach (array_keys($ex) as $k) {
            if (!isset(IMPORT_COLUMNS[$k])) {
                throw new RuntimeException('template uses an unknown column: ' . $k);
            }
        }
        $line = [];
        foreach (array_keys(IMPORT_COLUMNS) as $k) {
            $line[] = (string) ($ex[$k] ?? '');
        }
        $rows[] = $line;
    }

    $out = fopen('php://temp', 'r+');
    /* A BOM, so Excel on a Persian Windows opens it as UTF-8 instead of
       rendering every product name as mojibake. */
    fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    foreach ($rows as $r) {
        fputcsv($out, $r);
    }
    rewind($out);
    $csv = stream_get_contents($out) ?: '';
    fclose($out);
    return $csv;
}

/* --------------------------------------------------------------------------
   The Excel import
   -------------------------------------------------------------------------- */

/**
 * A column heading reduced to what two spellings of it share.
 *
 * So the sheet still reads when someone typed «قیمت فروش(تومان)», kept the
 * template's «*», or used an Arabic «ي».
 */
function import_header_key(string $label): string
{
    $label = strtr(trim($label), IMPORT_NORMALISE);
    $label = (string) preg_replace('/[\s()\[\]«»"\'.:*_\-]+/u', '', $label);
    return mb_strtolower($label);
}

/** Heading key → column key, for every way a sheet may name a column. */
function import_header_map(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (IMPORT_ALIASES as $alias => $key) {
            $map[import_header_key($alias)] = $key;
        }
        foreach (IMPORT_COLUMNS as $key => $label) {
            $map[import_header_key($label)] = $key;
            $map[import_header_key($key)]   = $key;
        }
    }
    return $map;
}

/**
 * The sheet the shop filled in: an .xlsx as Excel saved it, or a CSV.
 *
 * Told apart by the first bytes, not the name: a renamed file is still what
 * it is, and the old binary .xls is named rather than misread.
 */
function import_read_file(string $path): array
{
    $fh    = @fopen($path, 'rb');
    $magic = $fh ? (string) fread($fh, 4) : '';
    if ($fh) {
        fclose($fh);
    }
    if ($magic === "PK\x03\x04") {
        return import_read_xlsx($path);
    }
    if ($magic === "\xD0\xCF\x11\xE0") {
        return ['rows' => [], 'columns' => [], 'error' =>
            'این فایل با قالب قدیمی اکسل (‎.xls‎) ذخیره شده است. در اکسل: File ← Save As ← '
          . '«Excel Workbook (*.xlsx)» را انتخاب کنید و دوباره بارگذاری کنید.'];
    }
    return import_read_csv($path);
}

/**
 * The «محصولات» sheet of a workbook, as rows keyed by our column names.
 *
 * The heading is the first row near the top that names the Persian-name
 * column. Rows keep the numbers Excel shows, so «سطر ۱۲» in an error is the
 * row the owner scrolls to.
 */
function import_read_xlsx(string $path): array
{
    require_once __DIR__ . '/xlsx.php';
    $book = xlsx_read($path, [IMPORT_SHEET]);
    if ($book['error'] !== null) {
        return ['rows' => [], 'columns' => [], 'error' =>
            'فایل اکسل خوانده نشد. آن را در اکسل باز کنید، یک بار ذخیره کنید و دوباره بارگذاری کنید.'];
    }

    $known  = import_header_map();
    $header = null;
    $map    = [];
    foreach ($book['rows'] as $num => $cells) {
        if ($num > 15) {
            break;
        }
        $try = [];
        foreach ($cells as $i => $label) {
            $k = import_header_key((string) $label);
            if (isset($known[$k])) {
                $try[$i] = $known[$k];
            }
        }
        if (in_array('name_fa', $try, true)) {
            $header = $num;
            $map    = $try;
            break;
        }
    }
    if ($header === null) {
        return ['rows' => [], 'columns' => [], 'error' =>
            'ستون «' . IMPORT_COLUMNS['name_fa'] . '» در برگهٔ «' . $book['sheet'] . '» پیدا نشد. '
          . 'از فایل نمونهٔ همین صفحه استفاده کنید و عنوان ستون‌ها را عوض نکنید.'];
    }

    $rows = [];
    foreach ($book['rows'] as $num => $cells) {
        if ($num <= $header) {
            continue;
        }
        $row  = ['_line' => $num];
        $any  = false;
        foreach ($map as $i => $key) {
            $row[$key] = trim((string) ($cells[$i] ?? ''));
            $any = $any || $row[$key] !== '';
        }
        if ($any) {
            $rows[] = $row;
        }
    }
    return ['rows' => $rows, 'error' => null, 'columns' => array_values(array_unique($map))];
}

/**
 * «نشان‌ها» as the stored comma list: '' for a dash (clear them), null for a
 * word that is not a badge.
 */
function import_badges(string $raw): ?string
{
    $raw = trim($raw);
    if (in_array($raw, IMPORT_CLEAR, true)) {
        return '';
    }
    $known = [];
    foreach (IMPORT_BADGES as $word => $key) {
        $known[import_name_key($word)] = $key;
    }
    $out = [];
    foreach (preg_split('~[,،؛;/|\r\n]+~u', $raw) ?: [] as $word) {
        $word = import_name_key($word);
        if ($word === '') {
            continue;
        }
        if (!isset($known[$word])) {
            return null;
        }
        $out[$known[$word]] = true;
    }
    return implode(',', array_keys($out));
}

/**
 * The flavours cell's premiums: «وانیلی=150000» → ['وانیلی' => '150000'].
 * Only the flavours that carry one; the number is checked against the
 * product's purchase unit later, in import_plan().
 *
 * @return array<string,string>
 */
function import_flavor_premiums($raw): array
{
    $out = [];
    foreach (preg_split("~[,،؛;/|\r\n]+~u", (string) $raw) ?: [] as $part) {
        if (strpos($part, '=') === false) {
            continue;
        }
        [$name, $amount] = explode('=', $part, 2);
        $name = clean_text($name, 120);
        if ($name !== '') {
            $out[$name] = trim($amount);
        }
    }
    return $out;
}

/**
 * The nutrition table from one cell, a row per line: «پروتئین: 24 g».
 * Same shape the product form stores.
 *
 * @return array<int,array{label:string,value:string}>
 */
function import_nutrition($raw): array
{
    $out = [];
    foreach (preg_split('~[\r\n؛;]+~u', (string) $raw) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = preg_split('/\s*[:：]\s*/u', $line, 2) ?: [$line];
        $label = clean_text($parts[0], 80);
        if ($label !== '') {
            $out[] = ['label' => $label, 'value' => clean_text($parts[1] ?? '', 40)];
        }
    }
    return array_slice($out, 0, 40);
}

/* --------------------------------------------------------------------------
   Photographs named in the sheet
   -------------------------------------------------------------------------- */

/**
 * The name a photograph is known by on both sides: what the sheet wrote and
 * what was uploaded. Folder, case, spaces and Persian-Arabic letter variants
 * do not count.
 */
function import_photo_key(string $name): string
{
    $name = basename(str_replace('\\', '/', trim($name)));
    $name = strtr($name, IMPORT_NORMALISE);
    $name = (string) preg_replace('/\s+/u', '-', $name);
    return mb_strtolower(mb_substr($name, 0, 190));
}

/** The photograph names one cell lists, in order, each once. */
function import_photo_list($raw): array
{
    $out = [];
    foreach (preg_split('~[,،؛;|\r\n]+~u', (string) $raw) ?: [] as $name) {
        $key = import_photo_key($name);
        if ($key !== '' && !in_array($key, $out, true)) {
            $out[] = $key;
        }
    }
    return array_slice($out, 0, 12);
}

/**
 * «عکس طعم‌ها»: a line per flavour, «نام طعم: نام فایل» (or «=»), the flavour
 * as written in «طعم‌ها». Split on lines and semicolons only, never on commas:
 * a flavour's name may be «توت فرنگی و موز» but never holds a line break.
 *
 * @return array<string,string> flavour name => photograph key
 */
function import_flavor_photos($raw): array
{
    $out = [];
    foreach (preg_split('~[\r\n؛;|]+~u', (string) $raw) ?: [] as $line) {
        $parts = preg_split('/\s*[:：=]\s*/u', trim($line), 2) ?: [];
        if (count($parts) < 2) {
            continue;
        }
        $flavor = clean_text($parts[0], 120);
        $key = import_photo_key((string) $parts[1]);
        if ($flavor !== '' && $key !== '') {
            $out[$flavor] = $key;
        }
    }
    return array_slice($out, 0, 30, true);
}

/**
 * Give each flavour the picture the sheet named for it: the uploaded file
 * waiting under that name, else one of the product's own gallery photographs
 * of that name. A product already priced by combinations shows its flavours'
 * pictures from its flavour values, so those are brought along.
 *
 * @param array<string,string> $map flavour name => photograph key
 * @return int how many flavours got a new picture
 */
function import_attach_flavor_photos(int $productId, array $map): int
{
    $byName = [];
    foreach (db_all('SELECT id, name, image FROM product_flavors WHERE product_id = ?', [$productId]) as $f) {
        $byName[import_name_key((string) $f['name'])] = $f;
    }
    $set   = 0;
    $taken = [];
    foreach ($map as $flavor => $key) {
        $row = $byName[import_name_key((string) $flavor)] ?? null;
        if ($row === null) {
            continue;
        }
        $path = $taken[$key] ?? null;
        if ($path === null) {
            $waiting = import_photo_find($key);
            if ($waiting !== null) {
                db_query('DELETE FROM import_photos WHERE id = ?', [$waiting['id']]);
                $path = $taken[$key] = $waiting['path'];
            }
        }
        if ($path === null) {
            $own  = db_value('SELECT path FROM product_images WHERE product_id = ? AND source_name = ?
                               ORDER BY id LIMIT 1', [$productId, $key]);
            $path = $own === null ? null : (string) $own;
        }
        if ($path === null || (string) $row['image'] === $path) {
            continue;
        }
        db_query('UPDATE product_flavors SET image = ? WHERE id = ?', [$path, (int) $row['id']]);
        $set++;
    }
    if ($set > 0 && variants_has($productId)) {
        variants_adopt($productId);
    }
    return $set;
}

/** Whether the two tables the photographs need exist yet. */
function import_photos_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        $ready = db_value("SELECT 1 FROM information_schema.TABLES
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'import_photos'") !== null
              && db_value("SELECT 1 FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_images'
                              AND COLUMN_NAME = 'source_name'") !== null;
    }
    return $ready;
}

/**
 * An uploaded photograph waiting for a sheet to name it.
 *
 * By its full name first; a sheet that wrote the name without its extension
 * («whey-1» for «whey-1.jpg») still finds it.
 *
 * @return array{id:int,path:string,name_key:string}|null
 */
function import_photo_find(string $key): ?array
{
    $row = db_one('SELECT id, path, name_key FROM import_photos WHERE name_key = ?', [$key]);
    if ($row === null && strpos($key, '.') === false) {
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $key) . '.%';
        $row  = db_one('SELECT id, path, name_key FROM import_photos WHERE name_key LIKE ? ORDER BY id LIMIT 1',
                       [$like]);
    }
    return $row === null ? null
        : ['id' => (int) $row['id'], 'path' => (string) $row['path'], 'name_key' => (string) $row['name_key']];
}

/** A photograph rather than a bundled drawing: the line admin/images.php draws. */
function import_photo_is_real(string $path): bool
{
    $path = trim($path);
    return $path !== '' && !str_ends_with(strtolower($path), '.svg');
}

/**
 * Attach the photographs a sheet named to one product, in its order.
 *
 * One already attached by an earlier import of the same sheet is not added
 * twice: product_images.source_name remembers which file each came from.
 * The sheet's first photograph becomes the main one when it has just
 * arrived, or when the product has no real photograph to keep.
 *
 * @param array<int,string> $keys
 * @return int  how many were attached now
 */
function import_attach_photos(int $productId, array $keys): int
{
    $paths    = [];
    $firstNew = false;
    $added    = 0;
    $next     = (int) db_value(
        'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_images WHERE product_id = ?', [$productId]);

    foreach (array_values($keys) as $i => $key) {
        $have = db_value('SELECT path FROM product_images WHERE product_id = ? AND source_name = ?',
                         [$productId, $key]);
        if ($have !== null) {
            $paths[$i] = (string) $have;
            continue;
        }
        $waiting = import_photo_find($key);
        if ($waiting === null) {
            continue;
        }
        db_query('INSERT INTO product_images (product_id, path, sort_order, source_name) VALUES (?, ?, ?, ?)',
                 [$productId, $waiting['path'], $next++, $key]);
        db_query('DELETE FROM import_photos WHERE id = ?', [$waiting['id']]);
        $paths[$i] = $waiting['path'];
        if ($i === 0) {
            $firstNew = true;
        }
        $added++;
    }

    if ($paths) {
        ksort($paths);
        $first   = (string) reset($paths);
        $current = (string) db_value('SELECT image FROM products WHERE id = ?', [$productId]);
        if ($firstNew || !import_photo_is_real($current)) {
            db_query('UPDATE products SET image = ? WHERE id = ?', [$first, $productId]);
        }
    }
    return $added;
}
