<?php
/* ===========================================================================
   SUPPEX — Shared helpers
   =========================================================================== */

declare(strict_types=1);

/** HTML-escape for output. Short name because it appears on nearly every line
    of every template, and a helper people find tedious to type is a helper
    people forget to use. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* --- Persian / Arabic digits ----------------------------------------------
   Phone numbers, postal codes and prices arrive in whatever digits the
   visitor's keyboard produces. Stored unconverted, "۰۹۱۲..." and "0912..."
   are two different strings: a search for the order finds nothing and an SMS
   to the number fails. Normalising once at the boundary keeps every layer
   behind it working with plain Latin digits. */
function to_latin_digits(string $s): string
{
    $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $en = ['0','1','2','3','4','5','6','7','8','9'];
    return str_replace($ar, $en, str_replace($fa, $en, $s));
}

/** Digits only, Persian-aware. */
function digits_only(string $s): string
{
    return preg_replace('/\D+/', '', to_latin_digits($s)) ?? '';
}

/** Trim, collapse runs of whitespace, cap the length. Applied to every text
    field so a pasted blob cannot overflow a column or wreck a layout. */
function clean_text(?string $s, int $max = 500): string
{
    $s = trim(preg_replace('/\s+/u', ' ', (string) $s) ?? '');
    return mb_substr($s, 0, $max);
}

/** Same, but newlines survive — for addresses and descriptions. */
function clean_multiline(?string $s, int $max = 5000): string
{
    $s = str_replace(["\r\n", "\r"], "\n", (string) $s);
    $s = preg_replace('/\n{3,}/u', "\n\n", $s) ?? '';
    return mb_substr(trim($s), 0, $max);
}

/** Iranian mobile: 11 digits starting 09. */
function is_valid_mobile(string $s): bool
{
    return (bool) preg_match('/^09\d{9}$/', digits_only($s));
}

/** Iranian postal code: exactly 10 digits. */
function is_valid_postal(string $s): bool
{
    return (bool) preg_match('/^\d{10}$/', digits_only($s));
}

/* --- Slugs -----------------------------------------------------------------
   Persian characters are kept rather than stripped: a URL of %D9%88%DB%8C is
   ugly but works everywhere and, more importantly, a product named only in
   Persian would otherwise slugify to an empty string and collide with every
   other such product. */
function slugify(string $s): string
{
    $s = to_latin_digits(trim($s));
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s) ?? '';
    $s = trim($s, '-');
    $s = mb_strtolower($s);
    return $s !== '' ? mb_substr($s, 0, 110) : 'item-' . bin2hex(random_bytes(3));
}

/** Make a slug unique within a table, appending -2, -3 … as needed. */
function unique_slug(string $base, string $table, ?int $ignoreId = null): string
{
    $slug = $base;
    $n = 1;
    while (true) {
        $sql = "SELECT id FROM {$table} WHERE slug = ?";
        $params = [$slug];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $ignoreId;
        }
        if (db_one($sql, $params) === null) {
            return $slug;
        }
        $slug = $base . '-' . (++$n);
    }
}

/* --- Money -----------------------------------------------------------------
   Toman integers in, grouped Latin digits out — matching the front-end's
   formatter exactly so the same order never shows two different totals. */
function money(int $value): string
{
    return number_format($value);
}

/** Accepts "۲,۴۵۰,۰۰۰" or "2450000" and returns 2450000. */
function parse_money($input): int
{
    $digits = digits_only((string) $input);
    return $digits === '' ? 0 : (int) $digits;
}

/* --- Client address --------------------------------------------------------
   Stored as packed binary so IPv6 fits and comparisons are exact. Proxy
   headers are deliberately ignored: anyone can send an X-Forwarded-For, so
   trusting it would let an attacker sidestep the login throttle by inventing
   a new address on every request. */
function client_ip_binary(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $packed = @inet_pton($ip);
    return $packed === false ? null : $packed;
}

/* --- Order codes -----------------------------------------------------------
   Date prefix so an order can be found by eye, random suffix so codes cannot
   be enumerated. Ambiguous characters (0/O, 1/I) are excluded because these
   get read aloud over the phone. */
function generate_order_code(): string
{
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $suffix = '';
    for ($i = 0; $i < 4; $i++) {
        $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return 'SPX-' . date('ymd') . '-' . $suffix;
}

/* --- Iranian bank card -----------------------------------------------------
   The Luhn check every card number satisfies. Used to refuse an obviously
   mistyped card in the settings form, before a customer transfers money into
   a stranger's account because of a slipped digit. It cannot prove the card
   exists — only that the number is not malformed. */
function is_valid_card_number(string $s): bool
{
    $n = digits_only($s);
    if (strlen($n) !== 16) {
        return false;
    }
    $sum = 0;
    for ($i = 0; $i < 16; $i++) {
        $d = (int) $n[$i];
        if ($i % 2 === 0) {
            $d *= 2;
            if ($d > 9) {
                $d -= 9;
            }
        }
        $sum += $d;
    }
    return $sum % 10 === 0;
}

/** JSON in / out of a TEXT column, never returning null to a caller. */
function json_decode_array(?string $raw): array
{
    if ($raw === null || $raw === '') {
        return [];
    }
    $out = json_decode($raw, true);
    return is_array($out) ? $out : [];
}

function json_encode_pretty($value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
}

/** One text field per line, blank lines dropped — how the admin form edits
    the features list without needing a repeating-row widget. */
function lines_to_array(?string $raw): array
{
    $lines = preg_split('/\R/u', (string) $raw) ?: [];
    $out = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $out[] = mb_substr($line, 0, 300);
        }
    }
    return $out;
}

function array_to_lines(array $items): string
{
    return implode("\n", array_map('strval', $items));
}
