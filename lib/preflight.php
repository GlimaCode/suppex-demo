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
    $add('تنظیمات', 'محل فایل تنظیمات',
        $cfgPath === null ? 'blocker' : ($inside ? 'warn' : 'ok'),
        (string) $cfgPath,
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

    /* --- Schema ----------------------------------------------------------- */
    $missing = [];
    foreach (['products' => 'commission_percent', 'product_sizes' => 'cost_aed',
              'orders' => 'expires_at', 'order_items' => 'commission_percent'] as $t => $c) {
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
    $add('فایل‌ها', 'حذف setup.php',
        $setup ? ($hasAdmin ? 'blocker' : 'warn') : 'ok',
        $setup ? 'هنوز روی سرور است' : 'حذف شده',
        'این اسکریپت حساب مدیر می‌سازد. بعد از نصب حتماً حذفش کنید.');

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
        'از صفحه «ورود گروهی» فایل قیمت را وارد کنید.');

    $aed  = (int) db_value(
        'SELECT (SELECT COUNT(*) FROM products WHERE price_mode = "aed" AND cost_aed > 0)
              + (SELECT COUNT(*) FROM product_sizes WHERE cost_aed > 0)');
    $rate = trim((string) setting('aed_rate', ''));
    $add('کاتالوگ', 'نرخ درهم',
        $aed === 0 ? 'ok' : ($rate === '' ? 'blocker' : 'ok'),
        $aed === 0 ? 'هیچ محصولی درهمی نیست'
            : ($rate === '' ? 'ثبت نشده' : number_format((float) $rate)),
        'در صفحه قیمت‌گذاری نرخ را وارد و اعمال کنید، وگرنه قیمت این محصولات صفر می‌ماند.');

    $noPrice = (int) db_value('SELECT COUNT(*) FROM products WHERE is_active = 1 AND price <= 0');
    $add('کاتالوگ', 'محصول بدون قیمت', $noPrice === 0 ? 'ok' : 'blocker', (string) $noPrice,
        'قیمتشان را وارد کنید یا از سایت پنهانشان کنید.');

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
