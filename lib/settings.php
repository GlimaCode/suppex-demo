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

function settings_save(array $pairs): void
{
    Settings::save($pairs);
}
