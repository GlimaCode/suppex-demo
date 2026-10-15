<?php


/* ===========================================================================
   SUPPEX — Shop settings
   ---------------------------------------------------------------------------
   Key/value rows the admin can change from the panel without a deploy: card
   details, shipping thresholds, messaging links, commission rate.

   Read once per request and cached, because otherwise a page that shows the
   card number, the shipping rule and two channel links costs four identical
   queries. The cache lives in a class static rather than a function static so
   that saving a setting can genuinely invalidate it — a function static is
   unreachable from outside the function, which makes it the wrong place to
   put anything that has to be cleared.
   =========================================================================== */

declare(strict_types=1);

final class Settings
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    /** @return array<string,string> */
    public static function all(): array
    {
        if (self::$cache === null) {
            $map = [];
            foreach (db_all('SELECT setting_key, setting_value FROM settings') as $row) {
                $map[$row['setting_key']] = (string) $row['setting_value'];
            }
            self::$cache = $map;
        }
        return self::$cache;
    }

    public static function save(array $pairs): void
    {
        $sql = 'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)';
        foreach ($pairs as $key => $value) {
            db_query($sql, [(string) $key, (string) $value]);
        }
        self::$cache = null;      // next read reflects what was just written
    }
}

function settings_all(): array
{
    return Settings::all();
}

function setting(string $key, $default = '')
{
    $all = Settings::all();
    return array_key_exists($key, $all) && $all[$key] !== '' ? $all[$key] : $default;
}

function setting_int(string $key, int $default = 0): int
{
    $v = setting($key, null);
    return ($v === null || $v === '') ? $default : (int) $v;
}

function setting_float(string $key, float $default = 0.0): float
{
    $v = setting($key, null);
    return ($v === null || $v === '') ? $default : (float) $v;
}

function setting_bool(string $key, bool $default = false): bool
{
    $all = Settings::all();
    if (!array_key_exists($key, $all) || $all[$key] === '') {
        return $default;
    }
    return $all[$key] === '1' || $all[$key] === 'true';
}

/**
 * The settings the storefront is allowed to know.
 *
 * A whitelist, not a dump. The card number is deliberately absent: it is
 * returned once, with the order that was actually placed, because an open
 * endpoint that hands it out lets anyone scrape it and reuse it in a scam the
 * shop then gets blamed for.
 *
 * Contact details are filtered to what is actually SET, and the difference
 * matters more than it looks: the footer renders a row only when its key is
 * present, so an empty setting means nothing is printed rather than an empty
 * link. The site used to hard-code a landline nobody answers.
 *
 * @return array{freeShippingThreshold:int,shippingFlatRate:int,saleMode:bool,
 *               contact:array<string,string>,channels:list<array<string,string>>}
 */
function storefront_config(): array
{
    /* trim before the comparison: a setting holding only spaces is a setting
       somebody meant to clear, and it must not light up a footer row with a
       blank link in it. */
    $contact = [];
    foreach ([
        'phone'     => 'shop_phone',
        'email'     => 'shop_email',
        'instagram' => 'instagram_url',
        'telegram'  => 'telegram_url',
        /* Bale has had a field in the panel since the channels were built,
           and an order can be placed there - but it was never in this map,
           so the footer had no way to show it however carefully the owner
           filled the setting in. */
        'bale'      => 'bale_url',
        'whatsapp'  => 'whatsapp_url',
        'aparat'    => 'aparat_url',
    ] as $key => $setting) {
        $value = trim((string) setting($setting));
        if ($value !== '') {
            $contact[$key] = $value;
        }
    }

    return [
        'freeShippingThreshold' => setting_int('free_shipping_threshold', 1500000),
        'shippingFlatRate'      => setting_int('shipping_flat_rate', 65000),
        'saleMode'              => setting_bool('sale_mode', true),
        'contact'               => $contact,
        'channels'              => array_values(array_filter([
            /* No prefillParam: t.me/<username> ignores a message parameter and
               the app then refuses the link outright, so the order goes via the
               clipboard instead. Bale and WhatsApp do support it. */
            trim((string) setting('telegram_url')) !== ''
                ? ['id' => 'telegram', 'label' => 'تلگرام', 'enabled' => true,
                   'url' => setting('telegram_url'), 'prefillParam' => null] : null,
            trim((string) setting('bale_url')) !== ''
                ? ['id' => 'bale', 'label' => 'بله', 'enabled' => true,
                   'url' => setting('bale_url'), 'prefillParam' => 'text'] : null,
            trim((string) setting('whatsapp_url')) !== ''
                ? ['id' => 'whatsapp', 'label' => 'واتس‌اپ', 'enabled' => true,
                   'url' => setting('whatsapp_url'), 'prefillParam' => 'text'] : null,
        ])),
    ];
}

function settings_save(array $pairs): void
{
    Settings::save($pairs);
}

/** Public shipping copy, using the same settings as the order total. */
function storefront_shipping_html(bool $details = false): string
{
    $threshold = setting_int('free_shipping_threshold', 1500000);
    $flat = setting_int('shipping_flat_rate', 65000);
    $amount = static fn(int $n): string => '<span class="num">' . money_fa($n) . '</span> تومان';
    if ($flat <= 0) {
        return $details ? 'ارسال همهٔ سفارش‌ها رایگان است.' : 'ارسال رایگان';
    }
    if ($threshold <= 0) {
        return 'هزینهٔ ارسال ' . $amount($flat) . ($details ? ' است.' : '');
    }
    return $details
        ? 'ارسال سفارش‌های ' . $amount($threshold) . ' و بیشتر رایگان است؛ برای سفارش‌های کمتر، '
            . $amount($flat) . ' هزینهٔ ارسال محاسبه می‌شود.'
        : 'ارسال رایگان از ' . $amount($threshold);
}

/**
 * Normalise the shop's canonical origin.
 *
 * Empty stays empty - an unset value is meaningful, it means "not configured
 * yet" and preflight reports it. Anything else gets a scheme if it lacks one
 * and loses a trailing slash, because seo_origin() concatenates paths onto it.
 */
function settings_normalise_site_url($raw): string
{
    $v = trim((string) $raw);
    if ($v === '') {
        return '';
    }
    if (!preg_match('~^https?://~i', $v)) {
        $v = 'https://' . $v;
    }
    /* A host with a path or a query is a mistake, not a canonical origin. */
    $parts = parse_url($v);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }
    /* parse_url is permissive - it accepts a host with a space in it. A
       canonical origin that is not a hostname is worse than none, because it
       would be published in every canonical tag on the site. */
    if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $parts['host'])) {
        return '';
    }
    $scheme = strtolower($parts['scheme'] ?? 'https');
    $port   = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    return $scheme . '://' . strtolower($parts['host']) . $port;
}
