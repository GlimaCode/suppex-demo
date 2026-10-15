<?php
/* ===========================================================================
   SUPPEX — Launch preflight checks
   ---------------------------------------------------------------------------
   Answers "is this shop ready to take a real order?" as data, so the page that
   shows it stays a template and the whole thing can be exercised by a test.

   Every check below is something that has actually gone wrong in a cPanel
   deployment. Each one reports the fix rather than the symptom, because on
   launch day the symptom is usually a blank page and the fault is three layers
   away from it.

   Severities:
     blocker — do not take a real order until this is fixed
     warn    — will bite later, or is only needed in some setups
     ok      — nothing to do
   =========================================================================== */

declare(strict_types=1);
require_once __DIR__ . '/catalog_readiness.php';

/* What the catalogue sheets put in the price column for a product that has
   been researched and imported but not yet priced. Kept as a constant so the
   number has a name where it is checked; the sheets themselves are the only
   other place it appears. */
const PREFLIGHT_UNPRICED_MARKER = CATALOG_UNPRICED_MARKER;

/**
 * @return array<int,array{group:string,label:string,state:string,detail:string,fix:string}>
 */
function preflight_checks(): array
{
    $out = [];
    $add = static function (string $group, string $label, string $state,
                            string $detail = '', string $fix = '') use (&$out): void {
        $out[] = ['group' => $group, 'label' => $label, 'state' => $state,
                  'detail' => $detail, 'fix' => $fix];
    };

    $cfg = suppex_config();

    /* --- Server ---------------------------------------------------------- */
    $add('سرور', 'نسخه PHP',
        version_compare(PHP_VERSION, '8.0.0', '>=') ? 'ok' : 'blocker',
        PHP_VERSION,
        'در cPanel → Select PHP Version نسخه ۸.۰ یا بالاتر را انتخاب کنید.');

    foreach ([
        'pdo_mysql' => ['blocker', 'بدون آن هیچ صفحه‌ای کار نمی‌کند.'],
        'mbstring'  => ['blocker', 'متن فارسی بدون آن بریده و خراب می‌شود.'],
        'gd'        => ['blocker', 'آپلود تصویر محصول بدون آن ممکن نیست.'],
        'curl'      => ['warn',    'فقط برای اطلاع‌رسانی سفارش (پیامک یا تلگرام) لازم است.'],
    ] as $ext => [$severity, $why]) {
        $on = extension_loaded($ext);
        $add('سرور', 'افزونه ' . $ext, $on ? 'ok' : $severity,
            $on ? 'فعال' : 'غیرفعال',
            $why . ' در cPanel → Select PHP Version → Extensions تیک بزنید.');
    }

    $upload = (string) ini_get('upload_max_filesize');
    $add('سرور', 'حداکثر حجم آپلود', (int) $upload >= 4 ? 'ok' : 'warn',
        'upload_max_filesize=' . $upload . ' , post_max_size=' . ini_get('post_max_size'),
        'برای آپلود عکس محصول حداقل ۴ مگابایت لازم است.');

    /* --- Configuration ---------------------------------------------------- */
    $cfgPath = null;
    foreach ([dirname(SUPPEX_ROOT) . '/suppex-config.php',
              SUPPEX_ROOT . '/suppex-config.php'] as $p) {
        if (is_file($p)) { $cfgPath = $p; break; }
    }
    $inside = $cfgPath !== null && strpos($cfgPath, SUPPEX_ROOT) === 0;
    /* The VERDICT, not the path. This line renders during the pre-setup window,
       which is the one moment this page has no login - and an absolute path
       like /home/suppexir/suppex-config.php hands over the cPanel account name
       and the home directory layout to anyone who loads it. Whether the file
       sits outside public_html is the entire question here; exactly where it
       sits is the operator's business, and he is the one who put it there. */
    $add('تنظیمات', 'محل فایل تنظیمات',
        $cfgPath === null ? 'blocker' : ($inside ? 'warn' : 'ok'),
        $cfgPath === null
            ? 'پیدا نشد'
            : ($inside ? 'داخل public_html' : 'بیرون از public_html'),
        $inside
            ? 'فایل داخل public_html است. اگر روزی اجرای PHP خراب شود، رمز دیتابیس به‌صورت متن ساده به بازدیدکننده نمایش داده می‌شود. یک پوشه بالاتر ببریدش.'
            : 'از config.sample.php کپی بگیرید و یک پوشه بالاتر از public_html بگذارید.');

    $dbOk = true;
    try {
        db()->query('SELECT 1');
    } catch (Throwable $e) {
        $dbOk = false;
    }
    $add('تنظیمات', 'اتصال دیتابیس', $dbOk ? 'ok' : 'blocker',
        $dbOk ? (string) ($cfg['db']['name'] ?? '') : 'وصل نشد',
        'در cPanel کاربر را به دیتابیس اضافه کنید و ALL PRIVILEGES بدهید. نام دیتابیس و کاربر باید با پیشوند حساب نوشته شوند.');

    if (!$dbOk) {
        /* Nothing below this point can run without a connection, and a page of
           cascading failures hides the one that matters. */
        return $out;
    }

    /* --- Schema -----------------------------------------------------------
       Asks lib/migrate.php rather than keeping a second list. The hand-written
       one here had eight pairs against migrate_pending()'s sixteen, and the
       eight were the old ones - it knew nothing about stock_qty, stock_held,
       items_hash or tracking_code, so a database missing every recent
       migration passed this check and then failed at the first order. */
    require_once SUPPEX_ROOT . '/lib/migrate.php';
    $pending = [];
    try {
        foreach (migrate_pending() as $label => $present) {
            if (!$present) { $pending[] = $label; }
        }
    } catch (Throwable $e) {
        $pending = ['(بررسی نشد)'];
    }
    $add('دیتابیس', 'مهاجرت‌های انجام‌نشده', $pending ? 'blocker' : 'ok',
        $pending ? implode('، ', $pending) : 'همه انجام شده',
        'صفحه migrate.php را یک بار باز کنید و دکمه‌اش را بزنید.');

    $missing = [];
    /* A list of pairs, not a map keyed by table: two columns on the same
       table are now worth checking, and a map can only hold one of them.
       Each entry is the LAST column a migration added to that table, so a
       database that stopped halfway shows up here rather than at the first
       order. */
    foreach ([['products', 'commission_percent'], ['products', 'stock_qty'],
              ['product_sizes', 'cost_aed'], ['product_sizes', 'stock_qty'],
              ['orders', 'expires_at'], ['orders', 'items_hash'],
              ['order_items', 'commission_percent'], ['order_items', 'stock_held']] as [$t, $c]) {
        $present = db_value('SELECT 1 FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE()
                                AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c]) !== null;
        if (!$present) { $missing[] = $t . '.' . $c; }
    }
    $add('دیتابیس', 'ساختار جدول‌ها', $missing ? 'blocker' : 'ok',
        $missing ? 'ناقص: ' . implode(', ', $missing) : 'کامل',
        'صفحه migrate.php را یک بار اجرا کنید.');

    $hasAdmin = preflight_has_admin();
    $add('دیتابیس', 'حساب مدیر', $hasAdmin ? 'ok' : 'blocker',
        $hasAdmin ? 'ساخته شده' : 'وجود ندارد', 'setup.php را اجرا کنید.');

    /* --- Files ------------------------------------------------------------- */
    $uploads  = (string) ($cfg['uploads_dir'] ?? (SUPPEX_ROOT . '/uploads'));
    $writable = is_dir($uploads) && is_writable($uploads);
    $add('فایل‌ها', 'پوشه uploads', $writable ? 'ok' : 'blocker',
        $uploads . ($writable ? ' (قابل نوشتن)' : ' (قابل نوشتن نیست)'),
        'دسترسی پوشه را روی 755 بگذارید. 777 لازم نیست و نباید استفاده شود.');

    $setup = is_file(SUPPEX_ROOT . '/setup.php');
    /* Every server-rendered page includes partials/chrome-top.php; without it
       product, category, page, journal, article and track all fatal on the
       include. These are the two directories DEPLOY.md's upload tree used to
       omit, which is how the guide and the check managed to be wrong about the
       same thing at the same time. */
    foreach ([
        'partials/chrome-top.php'  => 'قالب مشترک بالای صفحه',
        'partials/chrome-foot.php' => 'قالب مشترک پایین صفحه',
        'content/pages'            => 'صفحه‌های قوانین',
        'content/categories'       => 'راهنمای دسته‌بندی‌ها',
        'content/journal'          => 'مقاله‌ها',
    ] as $rel => $label) {
        $there = file_exists(SUPPEX_ROOT . '/' . $rel);
        $add('فایل‌ها', $label, $there ? 'ok' : 'blocker',
            $there ? 'هست' : 'آپلود نشده',
            'پوشه ' . $rel . ' را هم آپلود کنید — بدون آن صفحه‌های سایت باز نمی‌شوند.');
    }

    $add('فایل‌ها', 'حذف setup.php',
        $setup ? ($hasAdmin ? 'blocker' : 'warn') : 'ok',
        $setup ? 'هنوز روی سرور است' : 'حذف شده',
        'این اسکریپت حساب مدیر می‌سازد. بعد از نصب حتماً حذفش کنید.');

    /* Same reasoning as setup.php, and the same severity: a page that can set
       an administrator's password is worth what one that creates an
       administrator is worth. It is gated by a phrase, but a gate is not a
       reason to leave it lying about. */
    $reset = is_file(SUPPEX_ROOT . '/admin-reset.php');
    $add('فایل‌ها', 'حذف admin-reset.php',
        $reset ? 'blocker' : 'ok',
        $reset ? 'هنوز روی سرور است' : 'روی سرور نیست',
        'این اسکریپت رمز مدیر را عوض می‌کند. بعد از استفاده حتماً حذفش کنید.');

    $add('فایل‌ها', 'فایل .htaccess',
        is_file(SUPPEX_ROOT . '/.htaccess') ? 'ok' : 'blocker', '',
        'بدون آن پوشه lib و db از اینترنت قابل خواندن‌اند.');

    /* --- Storefront wiring --------------------------------------------------
       Read out of config.js, because getting these two wrong is silent: the
       site looks perfectly fine and simply never records an order. */
    $js = (string) (@file_get_contents(SUPPEX_ROOT . '/assets/js/config.js') ?: '');
    $apiOn = preg_match("~baseUrl:\s*'api/index\.php'~", $js) === 1;
    $add('فروشگاه', 'اتصال فروشگاه به دیتابیس', $apiOn ? 'ok' : 'blocker',
        $apiOn ? "baseUrl = 'api/index.php'" : 'baseUrl هنوز null است',
        'در assets/js/config.js مقدار baseUrl را به api/index.php تغییر دهید — وگرنه سایت محصولات نمونه را نشان می‌دهد و سفارش‌ها اصلاً ثبت نمی‌شوند، بدون هیچ پیام خطایی.');

    $pageOn = preg_match("~productPage:\s*'product\.php'~", $js) === 1;
    $add('فروشگاه', 'صفحه محصول', $pageOn ? 'ok' : 'warn',
        $pageOn ? "productPage = 'product.php'" : "productPage = 'product.html'",
        'روی product.php بگذارید تا هر محصول عنوان و عکس خودش را در پیش‌نمایش تلگرام داشته باشد.');

    /* --- Shop settings ------------------------------------------------------ */

    /* Until this is set, seo_origin() falls back to the Host header, so every
       canonical, og:url and sitemap <loc> announces whatever hostname the
       request arrived on. On cPanel that is at least four - the domain, www,
       the serverN path and the preview subdomain - and Google indexes the same
       catalogue under all of them. A blocker, not a warning: it is invisible
       until it has already cost the ranking. */
    $siteUrl = trim((string) setting('site_url', ''));
    $add('فروشگاه', 'نشانی اصلی سایت',
        $siteUrl === '' ? 'blocker' : 'ok',
        $siteUrl === '' ? 'ثبت نشده' : $siteUrl,
        'در تنظیمات ثبتش کنید. تا ثبت نشود، سایت هر نامی را که مرورگر بفرستد '
        . 'به‌عنوان نشانی رسمی اعلام می‌کند و یک فروشگاه چند بار جداگانه ایندکس می‌شود.');

    $card = (string) setting('card_number', '');
    $add('فروشگاه', 'شماره کارت',
        $card === '' ? 'blocker' : (is_valid_card_number($card) ? 'ok' : 'blocker'),
        $card === '' ? 'ثبت نشده'
            : (is_valid_card_number($card) ? 'معتبر' : 'نامعتبر — احتمالاً اشتباه تایپی'),
        'در تنظیمات ثبتش کنید. تا ثبت نشود، مشتری بعد از سفارش شماره‌ای برای واریز نمی‌بیند.');

    $add('فروشگاه', 'نام صاحب حساب',
        trim((string) setting('card_holder', '')) !== '' ? 'ok' : 'warn', '',
        'دقیقاً همان‌طور که در بانک ثبت شده.');

    $channels = array_filter([
        setting('telegram_url', ''), setting('bale_url', ''), setting('whatsapp_url', ''),
    ]);
    $add('فروشگاه', 'راه دریافت سفارش', $channels ? 'ok' : 'blocker',
        count($channels) . ' مورد فعال',
        'حداقل یکی از لینک‌های تلگرام، بله یا واتس‌اپ باید پر باشد.');

    /* --- Catalogue ---------------------------------------------------------- */
    $active = (int) db_value('SELECT COUNT(*) FROM products WHERE is_active = 1');
    $add('کاتالوگ', 'محصول فعال', $active > 0 ? 'ok' : 'blocker', (string) $active,
        'از صفحه «ورود از اکسل» فایل قیمت را وارد کنید.');

    $aed  = (int) db_value(
        'SELECT (SELECT COUNT(*) FROM products WHERE price_mode = "aed" AND cost_aed > 0)
              + (SELECT COUNT(*) FROM product_sizes WHERE cost_aed > 0)');
    $rate = trim((string) setting('aed_rate', ''));
    /* The fifteen invented products. «آیا محصول فعالی هست» is satisfied
       perfectly by a catalogue nobody ever stocked - so a shop could pass
       every check on this page and be selling «وی پروتئین اورجینال» at a
       made-up price to somebody who is about to transfer real money.

       Matched on the seed's own slugs rather than on a flag, because there is
       no flag: setup.php writes these rows through the ordinary product
       insert and nothing marks them afterwards. */
    $demoSlugs = ['whey-original', 'iso-zero', 'casein-night', 'creatine-mono',
                  'creatine-hcl', 'mass-builder', 'serious-bulk', 'pre-blast',
                  'pump-extreme', 'bcaa-211', 'eaa-complete', 'glutamine-pure',
                  'multi-sport', 'zma-recovery', 'omega-3-sport'];
    $in    = implode(',', array_fill(0, count($demoSlugs), '?'));
    $demos = (int) db_value(
        'SELECT COUNT(*) FROM products WHERE is_active = 1 AND slug IN (' . $in . ')',
        $demoSlugs);
    $add('کاتالوگ', 'محصولات نمونه', $demos === 0 ? 'ok' : 'blocker',
        $demos === 0 ? 'حذف شده' : $demos . ' محصول ساختگی هنوز فعال است',
        'این‌ها محصولات نمونه‌اند — نام و قیمت و مشخصاتشان واقعی نیست و مشتری '
      . 'می‌تواند سفارششان بدهد. از صفحه محصولات حذفشان کنید یا تیک «در سایت '
      . 'نمایش داده شود» را بردارید، و کاتالوگ واقعی را از «ورود از اکسل» وارد کنید.');

    $add('کاتالوگ', 'نرخ درهم',
        $aed === 0 ? 'ok' : ($rate === '' ? 'blocker' : 'ok'),
        $aed === 0 ? 'هیچ محصولی درهمی نیست'
            : ($rate === '' ? 'ثبت نشده' : number_format((float) $rate)),
        'در صفحه قیمت‌گذاری نرخ را وارد و اعمال کنید، وگرنه قیمت این محصولات صفر می‌ماند.');

    /* product_variants counts too. Once a product has combinations the
       combination is what order_price_line() charges, and the parent row is
       only the headline - so a grid full of zeros is a product that sells
       for nothing while products.price looks perfectly healthy. */
    $noPrice = (int) db_value(
        'SELECT (SELECT COUNT(*) FROM products
                  WHERE is_active = 1 AND price <= 0)
              + (SELECT COUNT(*) FROM product_variants v
                   JOIN products p ON p.id = v.product_id
                  WHERE p.is_active = 1 AND v.is_active = 1 AND v.price <= 0)');
    $add('کاتالوگ', 'محصول بدون قیمت', $noPrice === 0 ? 'ok' : 'blocker', (string) $noPrice,
        'قیمتشان را وارد کنید یا از سایت پنهانشان کنید. اگر محصول ترکیب دارد، '
      . 'قیمت در جدول ترکیب‌ها گذاشته می‌شود، نه بالای صفحه.');

    /* «بدون قیمت» above means price <= 0, and the catalogue sheets do not
       leave the column empty - they carry 999,999,999 as the marker for a row
       that was imported but never priced, which pricing_round_up() then lifts
       to a round 1,000,000,000. A positive number, so it passed as priced: the
       whole page reported green while a product with that figure on it sat on
       the home page. Compared with >= rather than == because whether the
       rounding has run yet depends on which page last touched the row, and a
       real supplement is nowhere near either number. */
    $marker  = (int) db_value(
        'SELECT (SELECT COUNT(*) FROM products
                  WHERE is_active = 1 AND price >= ' . PREFLIGHT_UNPRICED_MARKER . ')
              + (SELECT COUNT(*) FROM product_sizes s
                   JOIN products p ON p.id = s.product_id
                  WHERE p.is_active = 1 AND s.price >= ' . PREFLIGHT_UNPRICED_MARKER . ')
              + (SELECT COUNT(*) FROM product_variants v
                   JOIN products p ON p.id = v.product_id
                  WHERE p.is_active = 1 AND v.is_active = 1
                    AND v.price >= ' . PREFLIGHT_UNPRICED_MARKER . ')');
    $add('کاتالوگ', 'قیمت جایگزین از فایل ورودی', $marker === 0 ? 'ok' : 'blocker',
        $marker === 0 ? 'نداریم' : $marker . ' مورد',
        'این عدد یعنی محصول از فایل وارد شده ولی قیمتش را هنوز نگذاشته‌اید — '
      . 'مشتری روی سایت عدد ۱,۰۰۰,۰۰۰,۰۰۰ تومان را می‌بیند. قیمت واقعی را '
      . 'در صفحه ویرایش محصول وارد کنید یا تیک «در سایت نمایش داده شود» را بردارید.');

    $noPhoto = (int) db_value(
        "SELECT COUNT(*) FROM products WHERE is_active = 1 AND (image = '' OR image LIKE '%.svg')");
    $add('کاتالوگ', 'عکس واقعی محصول', $noPhoto === 0 ? 'ok' : 'warn',
        $noPhoto . ' محصول بدون عکس واقعی',
        'تصاویر SVG جایگزین‌اند و ربات پیش‌نمایش تلگرام آن‌ها را نشان نمی‌دهد — کارت لینک با کاور عمومی می‌آید.');

    /* --- HTTPS -------------------------------------------------------------- */
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $only  = (bool) ($cfg['https_only'] ?? false);

    $add('امنیت', 'HTTPS', $https ? 'ok' : 'blocker',
        $https ? 'فعال' : 'این صفحه با http باز شده',
        'در cPanel → SSL/TLS Status گواهی رایگان AutoSSL را صادر کنید.');

    $add('امنیت', 'کوکی فقط روی HTTPS',
        $https ? ($only ? 'ok' : 'warn') : ($only ? 'blocker' : 'ok'),
        'https_only = ' . ($only ? 'true' : 'false'),
        $https
            ? 'حالا که SSL کار می‌کند، در suppex-config.php مقدار https_only را true کنید.'
            : 'https_only روشن است ولی سایت روی http باز شده — با این وضع نمی‌توانید وارد پنل شوید.');

    return $out;
}

/** Whether any admin account exists yet. Safe before the schema is created. */
/**
 * Whether an administrator exists — and whether we could tell.
 *
 * The boolean version of this could not distinguish "no administrator yet"
 * from "the database did not answer", and admin/preflight.php gated its
 * authentication on it. So an unreachable database - routine on shared
 * hosting: wrong credentials, MySQL restarting, max_user_connections - turned
 * the readiness page into an unauthenticated page.
 *
 * @return array{known:bool,has:bool}
 */
function preflight_admin_state(): array
{
    try {
        return ['known' => true, 'has' => (int) db_value('SELECT COUNT(*) FROM admins') > 0];
    } catch (Throwable $e) {
        return ['known' => false, 'has' => false];
    }
}

function preflight_has_admin(): bool
{
    try {
        return (int) db_value('SELECT COUNT(*) FROM admins') > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** @param array $checks @return array{blockers:int,warns:int,ready:bool} */
function preflight_summary(array $checks): array
{
    $blockers = 0;
    $warns    = 0;
    foreach ($checks as $c) {
        if ($c['state'] === 'blocker') { $blockers++; }
        if ($c['state'] === 'warn')    { $warns++; }
    }
    return ['blockers' => $blockers, 'warns' => $warns, 'ready' => $blockers === 0];
}
