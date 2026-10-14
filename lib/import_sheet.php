<?php
/* ===========================================================================
   SUPPEX — the catalogue as an Excel workbook
   ---------------------------------------------------------------------------
   The template the shop downloads, the same workbook filled with today's
   catalogue for editing many products at once, and the photographs a sheet
   names - uploaded on the import page one at a time and held here until an
   import attaches them (lib/import.php).

   One workbook shape for both: a «محصولات» sheet to fill, a «نمونه» sheet of
   worked rows, a «راهنما» sheet explaining every column, and a hidden sheet of
   the shop's categories and brands for the drop-downs. Every column of the
   fill-in sheet also carries its hint, which Excel shows the moment a cell of
   that column is selected - help exactly where the typing happens.
   =========================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/import.php';
require_once __DIR__ . '/xlsx.php';
require_once __DIR__ . '/images.php';
require_once __DIR__ . '/backup.php';

/**
 * The columns in the order a person fills them in.
 *
 * w: column width; req: must be filled; list: a drop-down; num: written as a
 * number, 'money' with thousands separators; hint: Excel's prompt, at most
 * 255 characters.
 */
const IMPORT_LAYOUT = [
    ['key' => 'name_fa', 'w' => 30, 'req' => true,
     'hint' => 'نام محصول همان‌طور که روی سایت دیده می‌شود. محصولی که چند اندازه دارد: برای هر اندازه یک سطر، با همین نام.'],
    ['key' => 'name_en', 'w' => 26,
     'hint' => 'نام انگلیسی، همان که روی بسته نوشته شده. آدرس صفحهٔ محصول تازه از همین ساخته می‌شود.'],
    ['key' => 'brand', 'w' => 16, 'list' => 'brands',
     'hint' => 'برند. محصولی با همین نام و برند اگر در فروشگاه باشد به‌روزرسانی می‌شود، نه تکرار.'],
    ['key' => 'category', 'w' => 16, 'list' => 'categories',
     'hint' => 'دسته؛ از فهرست انتخاب کنید. نام دستهٔ تازه را هم می‌شود نوشت تا ساخته شود.'],
    ['key' => 'size_label', 'w' => 14,
     'hint' => 'مثلاً «۹۰۰ گرم». هر اندازه یک سطر جدا. محصول تک‌اندازه: خالی بگذارید.'],
    ['key' => 'servings', 'w' => 10, 'num' => 'int',
     'hint' => 'تعداد وعدهٔ همین اندازه، مثلاً ۳۰.'],
    ['key' => 'cost_aed', 'w' => 13, 'num' => 'dec',
     'hint' => 'قیمت خرید به درهم؛ قیمت فروش با نرخ روز درهم به‌روز می‌شود. فقط یکی از دو ستون قیمت خرید را پر کنید.'],
    ['key' => 'cost_toman', 'w' => 15, 'num' => 'money',
     'hint' => 'قیمت خرید به تومان، برای کالایی که داخل ایران خریده شده؛ با نرخ درهم تکان نمی‌خورد.'],
    ['key' => 'profit_toman', 'w' => 14, 'num' => 'money',
     'hint' => 'سودی که روی همین بسته می‌خواهید. قیمت فروش = قیمت خرید + سود.'],
    ['key' => 'price_toman', 'w' => 15, 'num' => 'money',
     'hint' => 'فقط وقتی بازار قیمت را تعیین کرده: سود را خالی بگذارید، ولی قیمت خرید را حتماً بنویسید.'],
    ['key' => 'promo_toman', 'w' => 13, 'num' => 'money',
     'hint' => 'مبلغی که از قیمت کم می‌شود، نه قیمت دوم. بیشتر از نصف سود قبول نمی‌شود.'],
    ['key' => 'commission', 'w' => 11, 'num' => 'dec',
     'hint' => 'خالی = درصد پیش‌فرض فروشگاه. صفر یعنی روی این قلم سهمی برداشته نشود.'],
    ['key' => 'stock_qty', 'w' => 11, 'num' => 'int',
     'hint' => 'تعداد موجود در انبار برای همین اندازه. خالی = دست نزن؛ خط تیره (-) = شمرده نشود.'],
    ['key' => 'in_stock', 'w' => 9, 'list' => 'yesno',
     'hint' => 'بله یا خیر. خالی = موجود برای محصول تازه، و بدون تغییر برای محصول موجود.'],
    ['key' => 'is_active', 'w' => 11, 'list' => 'yesno',
     'hint' => '«خیر» محصول را از سایت پنهان می‌کند. خالی = نمایش برای محصول تازه، و بدون تغییر برای محصول موجود.'],
    ['key' => 'badges', 'w' => 16,
     'hint' => 'پرفروش، جدید، تخفیف برتر؛ با ویرگول جدا کنید. «تخفیف برتر» یعنی بالای صفحهٔ اول. خط تیره (-) همه را برمی‌دارد.'],
    ['key' => 'sort_order', 'w' => 10, 'num' => 'int',
     'hint' => 'عدد کوچک‌تر بالاتر نمایش داده می‌شود. خالی = بدون تغییر.'],
    ['key' => 'flavors', 'w' => 26,
     'hint' => 'با ویرگول جدا کنید. طعم گران‌تر: بعد از نامش = و مبلغ اضافه، به همان واحدِ قیمت خرید. مثال: شکلاتی، وانیلی=150000'],
    ['key' => 'images', 'w' => 26,
     'hint' => 'نام فایل عکس‌ها با ویرگول؛ اولی عکس اصلی است. عکس‌ها را پیش از ثبت، در صفحهٔ «ورود از اکسل» بارگذاری کنید.'],
    ['key' => 'flavor_images', 'w' => 30, 'wrap' => true,
     'hint' => 'برای هر طعم یک خط: «نام طعم: نام فایل عکس»، نام طعم همان‌طور که در ستون «طعم‌ها» آمده. در سایت با انتخاب آن طعم همین عکس دیده می‌شود. عکس‌ها را مثل ستون «عکس‌ها» بارگذاری کنید.'],
    ['key' => 'short', 'w' => 30,
     'hint' => 'یک جمله که زیر نام محصول می‌آید.'],
    ['key' => 'features', 'w' => 34, 'wrap' => true,
     'hint' => 'مشخصات کلیدی، هر کدام در یک خط (Alt+Enter). اولی روی کارت محصول نمایش داده می‌شود.'],
    ['key' => 'description', 'w' => 40, 'wrap' => true,
     'hint' => 'توضیح کامل صفحهٔ محصول. برای پاراگراف تازه Alt+Enter بزنید.'],
    ['key' => 'ingredients', 'w' => 30, 'wrap' => true,
     'hint' => 'ترکیبات، همان‌طور که روی برچسب آمده.'],
    ['key' => 'usage', 'w' => 30, 'wrap' => true,
     'hint' => 'روش مصرف.'],
    ['key' => 'nutrition_serving', 'w' => 18,
     'hint' => 'اندازهٔ هر وعده برای جدول ارزش غذایی، مثلاً «هر پیمانه ۳۰ گرم».'],
    ['key' => 'nutrition', 'w' => 30, 'wrap' => true,
     'hint' => 'جدول ارزش غذایی، هر ردیف در یک خط: «عنوان: مقدار». مثال: پروتئین: 24 g'],
];

/** The workbook's sheet names. The fill-in one is the one the import reads. */
const IMPORT_SHEET_EXAMPLE = 'نمونه';
const IMPORT_SHEET_GUIDE   = 'راهنما';
const IMPORT_SHEET_LISTS   = 'lists';
/* What the products sell for today (backup_selling_rows()), beside an
   export. Never read back. */
const IMPORT_SHEET_SELLING = 'قیمت فروش';

/** Rows of the «نمونه» sheet: one product in two sizes, one single, one bought in toman. */
function import_example_rows(): array
{
    return [
        ['name_fa' => 'وی پروتئین نمونه', 'name_en' => 'Sample Whey', 'brand' => 'SUPPEX',
         'category' => 'پروتئین', 'size_label' => '900 گرم', 'servings' => '30',
         'cost_aed' => '40', 'profit_toman' => '1500000', 'stock_qty' => '12',
         'in_stock' => 'بله', 'is_active' => 'بله', 'badges' => 'پرفروش', 'sort_order' => '1',
         'flavors' => 'شکلاتی، وانیلی، توت فرنگی=2', 'images' => 'whey-front.jpg, whey-back.jpg',
         'flavor_images' => "شکلاتی: whey-chocolate.jpg\nوانیلی: whey-vanilla.jpg",
         'short' => 'وی کنسانتره و ایزوله میکروفیلتر شده',
         'features' => "۲۴ گرم پروتئین در هر پیمانه\nبدون قند افزوده",
         'description' => "هر وعده ۲۴ گرم پروتئین دارد.\nبعد از تمرین با آب یا شیر مصرف شود.",
         'ingredients' => 'کنسانترهٔ پروتئین وی، ایزولهٔ پروتئین وی، طعم‌دهندهٔ طبیعی',
         'usage' => 'یک پیمانه در ۲۵۰ میلی‌لیتر آب یا شیر، بعد از تمرین.',
         'nutrition_serving' => 'هر پیمانه ۳۰ گرم',
         'nutrition' => "انرژی: 120 kcal\nپروتئین: 24 g\nکربوهیدرات: 3 g"],
        ['name_fa' => 'وی پروتئین نمونه', 'size_label' => '2270 گرم', 'servings' => '76',
         'cost_aed' => '95', 'profit_toman' => '2000000', 'stock_qty' => '4'],
        ['name_fa' => 'کراتین نمونه', 'name_en' => 'Sample Creatine', 'brand' => 'SUPPEX',
         'category' => 'کراتین', 'servings' => '100', 'cost_aed' => '18', 'profit_toman' => '900000',
         'in_stock' => 'بله', 'images' => 'creatine.png', 'short' => 'کراتین میکرونایز خالص',
         'features' => "۵ گرم کراتین خالص در هر وعده\nبدون نیاز به دوره بارگیری"],
        ['name_fa' => 'مولتی ویتامین نمونه', 'name_en' => 'Sample Multivitamin', 'brand' => 'SUPPEX',
         'category' => 'ویتامین و مکمل', 'servings' => '60', 'cost_toman' => '650000',
         'profit_toman' => '600000', 'in_stock' => 'بله', 'badges' => 'جدید',
         'short' => 'مولتی ویتامین کامل روزانه'],
    ];
}

/** A value as the cell its column wants: a number where Excel should see one. */
function import_sheet_cell(string $v, array $col)
{
    if ($v === '') {
        return '';
    }
    $num = $col['num'] ?? null;
    $raw = str_replace([',', '٬'], '', to_latin_digits($v));
    if ($num !== null && is_numeric($raw)) {
        $n = $num === 'dec' ? (float) $raw : (int) $raw;
        if ($num === 'dec' && floor($n) == $n) {
            $n = (int) $n;
        }
        return ['v' => $n, 's' => $num === 'money' ? 'money' : 'cell'];
    }
    return ['v' => $v, 's' => !empty($col['wrap']) || strpos($v, "\n") !== false ? 'wrap' : 'cell'];
}

/** The shop's categories and brands, for the drop-downs. */
function import_sheet_lists(): array
{
    $cats = array_column(db_all('SELECT name_fa FROM categories ORDER BY sort_order, id'), 'name_fa');
    $brands = array_column(db_all("SELECT DISTINCT brand FROM products WHERE brand <> '' ORDER BY brand"), 'brand');
    try {
        foreach (db_all("SELECT name FROM brands WHERE name <> '' ORDER BY sort_order, name") as $b) {
            if (!in_array($b['name'], $brands, true)) {
                $brands[] = (string) $b['name'];
            }
        }
    } catch (Throwable $e) {
        /* No brands table yet - the products' own brands are enough. */
    }
    return ['categories' => array_values(array_filter(array_map('strval', $cats))),
            'brands'     => array_values(array_filter(array_map('strval', $brands)))];
}

/**
 * The whole workbook: «محصولات» holding $rows (empty for the template), then
 * the worked example, the guide, and the hidden lists.
 *
 * @param array<int,array<string,string>> $rows  keyed by IMPORT_COLUMNS
 * @param array{categories:array,brands:array}|null $lists  the drop-downs' lists;
 *        the shop's own when null (tools/catalogue-sheet.php passes the live
 *        shop's, read from an exported workbook)
 * @param array|null $selling  backup_selling_rows(), for an export: a sheet of
 *        what each product sells for today, after «محصولات»
 */
function import_workbook(array $rows, ?array $lists = null, ?array $selling = null): string
{
    $lists = $lists ?? import_sheet_lists();

    $header = [];
    foreach (IMPORT_LAYOUT as $col) {
        $header[] = ['v' => IMPORT_COLUMNS[$col['key']] . (!empty($col['req']) ? ' *' : ''),
                     's' => !empty($col['req']) ? 'req' : 'head'];
    }
    $widths = array_map(static fn($c) => (float) $c['w'], IMPORT_LAYOUT);

    $fill = static function (array $source) use ($header): array {
        $out = [['cells' => $header, 'height' => 40]];
        foreach ($source as $r) {
            $line = [];
            foreach (IMPORT_LAYOUT as $col) {
                $line[] = import_sheet_cell((string) ($r[$col['key']] ?? ''), $col);
            }
            $out[] = $line;
        }
        return $out;
    };

    /* Hints on every column, drop-downs where there is a list. Reaching well
       past the last row, so a person adding products below still gets them. */
    $last = max(1000, count($rows) + 500);
    $validations = [];
    foreach (IMPORT_LAYOUT as $i => $col) {
        $letter = xlsx_col_letter($i);
        $v = ['sqref' => $letter . '2:' . $letter . $last,
              'title' => IMPORT_COLUMNS[$col['key']], 'prompt' => $col['hint']];
        $kind = $col['list'] ?? null;
        if ($kind === 'yesno') {
            $v['list'] = '"بله,خیر"';
        } elseif ($kind === 'categories' && $lists['categories']) {
            $v['list'] = IMPORT_SHEET_LISTS . '!$A$2:$A$' . (count($lists['categories']) + 1);
        } elseif ($kind === 'brands' && $lists['brands']) {
            $v['list'] = IMPORT_SHEET_LISTS . '!$B$2:$B$' . (count($lists['brands']) + 1);
        }
        $validations[] = $v;
    }

    $listRows = [['دسته‌ها', 'برندها']];
    $n = max(count($lists['categories']), count($lists['brands']));
    for ($i = 0; $i < $n; $i++) {
        $listRows[] = [$lists['categories'][$i] ?? '', $lists['brands'][$i] ?? ''];
    }

    $sheets = [
        ['name' => IMPORT_SHEET, 'rtl' => true, 'freeze' => 1, 'selected' => true, 'tab' => 'FFF25914',
         'widths' => $widths, 'rows' => $fill($rows), 'validations' => $validations],
        ['name' => IMPORT_SHEET_EXAMPLE, 'rtl' => true, 'freeze' => 1, 'widths' => $widths,
         'rows' => $fill(import_example_rows())],
        ['name' => IMPORT_SHEET_GUIDE, 'rtl' => true, 'tab' => 'FF2A2622',
         'widths' => [22, 10, 70, 30], 'rows' => import_guide_rows()],
        ['name' => IMPORT_SHEET_LISTS, 'hidden' => true, 'rows' => $listRows],
    ];
    if ($selling !== null) {
        array_splice($sheets, 1, 0, [import_selling_sheet($selling)]);
    }
    return xlsx_build($sheets);
}

/**
 * The «قیمت فروش» sheet: what each thing a shopper can buy costs today.
 * A price of exactly 1,000,000,000 is the import's «not priced yet» (see
 * the catalogue notes), and says so rather than showing the number.
 */
function import_selling_sheet(array $selling): array
{
    $head = static fn(string $t): array => ['v' => $t, 's' => 'head'];
    $rows = [
        ['cells' => [['v' => 'قیمت فروش امروز', 's' => 'title']], 'height' => 26],
        [['v' => 'فقط برای دیدن؛ «ورود از اکسل» این برگه را نمی‌خواند.', 's' => 'note']],
        ['cells' => [$head('محصول'), $head('اندازه'), $head('طعم'), $head('گزینهٔ دیگر'),
                     $head('قیمت فروش (تومان)'), $head('قیمت قبل از تخفیف'), $head('موجودی'),
                     $head('در فروش')], 'height' => 32],
    ];
    foreach ($selling as $s) {
        $unpriced = (int) $s['price'] === 1000000000;
        $rows[] = [
            ['v' => (string) $s['product'], 's' => 'label'],
            ['v' => (string) $s['size'], 's' => 'cell'],
            ['v' => (string) $s['flavor'], 's' => 'cell'],
            ['v' => (string) $s['other'], 's' => 'cell'],
            $unpriced || (int) $s['price'] <= 0
                ? ['v' => 'قیمت‌گذاری نشده', 's' => 'cell']
                : ['v' => (int) $s['price'], 's' => 'money'],
            $s['compare_at'] !== null && !$unpriced
                ? ['v' => (int) $s['compare_at'], 's' => 'money']
                : ['v' => '', 's' => 'cell'],
            $s['stock'] === null ? ['v' => 'شمرده نمی‌شود', 's' => 'cell'] : ['v' => (int) $s['stock'], 's' => 'cell'],
            ['v' => $s['on_sale'] && !$unpriced ? 'بله' : 'خیر', 's' => 'cell'],
        ];
    }
    return ['name' => IMPORT_SHEET_SELLING, 'rtl' => true, 'freeze' => 3, 'tab' => 'FF61BD67',
            'widths' => [34, 22, 22, 18, 18, 18, 14, 10], 'rows' => $rows];
}

/** The file name for one product's workbook: its slug, as far as a file name
    can carry it, or its number. */
function import_export_filename(array $product): string
{
    $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($product['slug'] ?? '')));
    $slug = trim(substr($slug, 0, 60), '-');
    return 'suppex-product-' . ($slug !== '' ? $slug : (string) (int) ($product['id'] ?? 0))
         . '-' . date('Y-m-d') . '.xlsx';
}

/** The «راهنما» sheet: the few rules first, then every column. */
function import_guide_rows(): array
{
    $rows = [
        ['cells' => [['v' => 'راهنمای فایل محصولات ساپکس', 's' => 'title']], 'height' => 26],
        [['v' => 'محصولات را در برگهٔ «محصولات» بنویسید؛ برگهٔ «نمونه» فقط برای دیدن است و خوانده نمی‌شود.', 's' => 'note']],
        [['v' => 'هر اندازه یک سطر است: بستهٔ ۹۰۰ گرمی و ۲۲۷۰ گرمی یک محصول با دو سطر و نام یکسان‌اند.', 's' => 'note']],
        [['v' => 'خانهٔ خالی یعنی «دست نزن»: در محصولی که از قبل هست، آنچه خالی بماند عوض نمی‌شود.', 's' => 'note']],
        [['v' => 'عکس‌ها: نام فایل‌ها را در ستون «عکس‌ها» بنویسید و خود عکس‌ها را پیش از ثبت در صفحهٔ «ورود از اکسل» بارگذاری کنید.', 's' => 'note']],
        [['v' => 'عکس هر طعم: در ستون «عکس طعم‌ها»، برای هر طعم یک خط «نام طعم: نام فایل» بنویسید؛ همان عکس با انتخاب آن طعم نشان داده می‌شود.', 's' => 'note']],
        [['v' => 'چند خط در یک خانه: Alt+Enter. ستون‌های ستاره‌دار لازم‌اند؛ از ستون‌های قیمت، یکی از سه راه کافی است.', 's' => 'note']],
        [['v' => 'قیمت محصولی که برای هر اندازه و طعم قیمت جدا دارد، با ستون‌های قیمت این فایل عوض نمی‌شود؛ آن را در صفحهٔ ویرایش محصول تغییر دهید. قیمت امروزِ هر کدام در برگهٔ «قیمت فروش» فایل خروجی هست.', 's' => 'note']],
        [''],
        ['cells' => [['v' => 'ستون', 's' => 'head'], ['v' => 'لازم؟', 's' => 'head'],
                     ['v' => 'چه بنویسیم', 's' => 'head'], ['v' => 'نمونه', 's' => 'head']], 'height' => 24],
    ];
    $example = import_example_rows()[0];
    foreach (IMPORT_LAYOUT as $col) {
        $rows[] = [
            ['v' => IMPORT_COLUMNS[$col['key']], 's' => 'label'],
            ['v' => !empty($col['req']) ? 'بله' : '', 's' => 'cell'],
            ['v' => $col['hint'], 's' => 'wrap'],
            ['v' => (string) ($example[$col['key']] ?? ''), 's' => 'wrap'],
        ];
    }
    return $rows;
}

/** The empty template. */
function import_template_xlsx(): string
{
    return import_workbook([]);
}

/** Today's catalogue in the same workbook, for editing many products at once -
    or one product, from its row in the panel or its edit page. Either way
    with the «قیمت فروش» sheet beside it, and either way a file that goes
    back in through «ورود از اکسل» like any other. */
function import_catalogue_xlsx(?int $productId = null): string
{
    return import_workbook(backup_catalogue_rows($productId), null, backup_selling_rows($productId));
}

/* --------------------------------------------------------------------------
   Photographs waiting for a sheet
   -------------------------------------------------------------------------- */

/**
 * Keep one uploaded photograph under the name a sheet will use for it.
 *
 * Stored the way every product photograph is - resized, with its lighter
 * copies made now (image_store()) - so attaching it later is only a row. The
 * same name uploaded again replaces the earlier file.
 *
 * @return array{ok:bool,error?:string,key?:string,name?:string,path?:string}
 */
function import_photo_store(array $file): array
{
    if (!import_photos_ready()) {
        return ['ok' => false, 'error' => 'اول یک بار «به‌روزرسانی دیتابیس» را اجرا کنید.'];
    }
    $name = (string) ($file['name'] ?? '');
    $key  = import_photo_key($name);
    $ext  = strtolower(pathinfo($key, PATHINFO_EXTENSION));
    if ($key === '' || !in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        return ['ok' => false, 'error' => 'فقط عکس JPG، PNG یا WebP.'];
    }

    $stored = image_store($file);
    if (!$stored['ok']) {
        return ['ok' => false, 'error' => (string) $stored['error']];
    }

    $old = db_one('SELECT id, path FROM import_photos WHERE name_key = ?', [$key]);
    if ($old !== null) {
        db_query('UPDATE import_photos SET path = ?, original_name = ?, created_at = NOW() WHERE id = ?',
                 [$stored['path'], mb_substr($name, 0, 255), (int) $old['id']]);
        import_photo_forget((string) $old['path']);
    } else {
        db_query('INSERT INTO import_photos (name_key, original_name, path) VALUES (?, ?, ?)',
                 [$key, mb_substr($name, 0, 255), $stored['path']]);
    }
    return ['ok' => true, 'key' => $key, 'name' => $name, 'path' => (string) $stored['path']];
}

/** Delete a file no product uses: a photograph replaced or cleared before any import named it. */
function import_photo_forget(string $path): void
{
    $used = db_value('SELECT 1 FROM product_images WHERE path = ? LIMIT 1', [$path]) !== null
         || db_value('SELECT 1 FROM products WHERE image = ? LIMIT 1', [$path]) !== null;
    if (!$used) {
        image_delete($path);
    }
}

/** The photographs still waiting, newest first. */
function import_photos_pending(): array
{
    if (!import_photos_ready()) {
        return [];
    }
    return db_all('SELECT id, name_key, original_name, path, created_at
                     FROM import_photos ORDER BY created_at DESC, id DESC');
}

/** Throw away every photograph no import has used. */
function import_photos_clear(): int
{
    $n = 0;
    foreach (import_photos_pending() as $p) {
        db_query('DELETE FROM import_photos WHERE id = ?', [(int) $p['id']]);
        import_photo_forget((string) $p['path']);
        $n++;
    }
    return $n;
}
