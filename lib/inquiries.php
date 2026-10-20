<?php
/* Stock enquiries are requests, not orders or reservations. Approval adds
   exactly the requested count once, under the same variant row lock checkout
   uses. Only the configured Telegram GROUP receives the notification. */
declare(strict_types=1);
require_once __DIR__ . '/variants.php';
require_once __DIR__ . '/track.php';
require_once __DIR__ . '/notify.php';

const INQUIRY_STATUS_FA = ['pending' => 'در انتظار بررسی', 'approved' => 'موجودی تأیید شد', 'rejected' => 'موجود نیست'];

function inquiries_ready(): bool
{
    static $ready;
    return $ready ?? ($ready = db_value("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_inquiries'") !== null);
}

function inquiry_same_origin(): bool
{
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') { return false; }
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') { return true; }
    require_once __DIR__ . '/seo.php';
    return rtrim($origin, '/') === rtrim(seo_origin(), '/');
}

function inquiry_get(int $id): ?array
{
    return inquiries_ready() ? db_one('SELECT * FROM stock_inquiries WHERE id = ?', [$id]) : null;
}

function inquiries_waiting(): int
{
    return inquiries_ready() ? (int) db_value("SELECT COUNT(*) FROM stock_inquiries WHERE status = 'pending'") : 0;
}

/** No phone number, customer name or private configuration in this payload. */
function inquiry_public(array $r): array
{
    $v = db_one('SELECT v.*, p.slug, p.is_active AS product_active, p.in_stock AS product_stock
                   FROM product_variants v JOIN products p ON p.id = v.product_id
                  WHERE v.id = ? AND v.product_id = ? AND v.ext_id = ?',
                  [(int) $r['variant_id'], (int) $r['product_id'], $r['variant_ext']]);
    $available = $v !== null && (int) $v['product_active'] === 1 && (int) $v['product_stock'] === 1
        && (int) $v['is_active'] === 1 && !variant_requires_inquiry($v) && (int) $v['price'] > 0
        && ($v['stock_qty'] === null || (int) $v['stock_qty'] > 0);
    return ['ok' => true, 'code' => $r['code'], 'status' => $r['status'],
        'product' => $r['product_name'], 'choice' => $r['variant_label'], 'qty' => (int) $r['qty'],
        'deadline' => strtotime($r['respond_by']) * 1000, 'serverNow' => time() * 1000,
        'available' => $available, 'productUrl' => 'product.php?p=' . rawurlencode($v['slug'] ?? $r['product_slug'])];
}

function inquiry_find(string $code, string $phone): ?array
{
    if (!inquiries_ready() || track_is_throttled()) { return null; }
    $code = strtoupper(trim(to_latin_digits($code)));
    $phone = digits_only($phone);
    $r = preg_match('/^SI-[0-9]{6}-[A-F0-9]{16}$/D', $code)
        ? db_one('SELECT * FROM stock_inquiries WHERE code = ?', [$code]) : null;
    if ($r === null || !hash_equals((string) $r['phone'], $phone)) {
        track_record_attempt();
        return null;
    }
    return $r;
}

/** Idempotency is checked before throttling. A lost response can be retried. */
function inquiry_create(array $b): array
{
    if (!inquiries_ready() || !variants_inquiry_ready()) {
        return ['ok' => false, 'error' => 'ثبت استعلام هنوز آماده نیست؛ کمی بعد دوباره تلاش کنید.'];
    }
    $name = clean_text((string) ($b['name'] ?? ''), 100);
    $phone = digits_only((string) ($b['phone'] ?? ''));
    $slug = clean_text((string) ($b['slug'] ?? ''), 120);
    $ext = clean_text((string) ($b['variant'] ?? ''), 160);
    $key = (string) ($b['request_key'] ?? '');
    $qtyRaw = to_latin_digits(trim((string) ($b['qty'] ?? '')));
    if (mb_strlen($name) < 2 || !preg_match('/^09[0-9]{9}$/D', $phone)
        || !preg_match('/^[a-f0-9]{32}$/D', $key) || !preg_match('/^[0-9]{1,2}$/D', $qtyRaw)
        || (int) $qtyRaw < 1 || (int) $qtyRaw > 20 || !empty($b['website'])) {
        return ['ok' => false, 'error' => 'نام، موبایل معتبر و تعداد بین ۱ تا ۲۰ را وارد کنید.'];
    }
    $qty = (int) $qtyRaw;
    $ip = hash('sha256', (string) client_ip_binary());
    $lock = 'suppex_i_' . substr($ip, 0, 40);
    if ((int) db_value('SELECT GET_LOCK(?, 3)', [$lock]) !== 1) {
        return ['ok' => false, 'error' => 'ثبت درخواست در حال انجام است؛ دوباره تلاش کنید.'];
    }
    try {
        $dupe = db_one('SELECT * FROM stock_inquiries WHERE request_key = ?', [hash('sha256', $key)]);
        if ($dupe !== null) {
            if ($dupe['phone'] !== $phone || $dupe['product_slug'] !== $slug
                || $dupe['variant_ext'] !== $ext || (int) $dupe['qty'] !== $qty) {
                return ['ok' => false, 'error' => 'اطلاعات درخواست عوض شده؛ صفحه محصول را تازه کنید.'];
            }
            return ['ok' => true, 'row' => $dupe, 'duplicate' => true];
        }
        if ((int) db_value('SELECT COUNT(*) FROM stock_inquiries WHERE (ip_hash = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)) OR (phone = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR))', [$ip, $phone]) >= 8) {
            return ['ok' => false, 'error' => 'تعداد درخواست‌ها زیاد است؛ لطفاً کمی بعد دوباره تلاش کنید.'];
        }
        db()->beginTransaction();
        $p = db_one('SELECT id, slug, name_fa, is_active, in_stock FROM products WHERE slug = ? FOR UPDATE', [$slug]);
        $v = $p === null ? null : db_one('SELECT * FROM product_variants WHERE product_id = ? AND ext_id = ? FOR UPDATE', [(int) $p['id'], $ext]);
        /* A retry from another IP may have waited behind the first insert. */
        $late = db_one('SELECT * FROM stock_inquiries WHERE request_key = ? FOR UPDATE', [hash('sha256', $key)]);
        if ($late !== null) {
            db()->rollBack();
            if ($late['phone'] !== $phone || $late['product_slug'] !== $slug || $late['variant_ext'] !== $ext || (int) $late['qty'] !== $qty) {
                return ['ok' => false, 'error' => 'اطلاعات درخواست عوض شده؛ صفحه محصول را تازه کنید.'];
            }
            return ['ok' => true, 'row' => $late, 'duplicate' => true];
        }
        if ($p === null || !(int) $p['is_active'] || !(int) $p['in_stock'] || $v === null
            || !(int) $v['is_active'] || !variant_requires_inquiry($v) || (int) $v['price'] <= 0) {
            db()->rollBack();
            return ['ok' => false, 'error' => 'این انتخاب اکنون قابل استعلام نیست؛ صفحه محصول را تازه کنید.'];
        }
        if (db_value("SELECT id FROM stock_inquiries WHERE variant_id = ? AND phone = ? AND status = 'pending' AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)", [(int) $v['id'], $phone]) !== null) {
            db()->rollBack();
            return ['ok' => false, 'error' => 'برای همین طعم درخواست در انتظار دارید؛ نتیجه را با کد قبلی پیگیری کنید.'];
        }
        $labels = db_all('SELECT a.label FROM product_variant_values vv JOIN product_attribute_values a ON a.id = vv.value_id WHERE vv.variant_id = ? ORDER BY a.attribute_id, a.sort_order, a.id', [(int) $v['id']]);
        $code = 'SI-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(8)));
        $id = db_insert('INSERT INTO stock_inquiries
            (code, request_key, product_id, variant_id, product_slug, product_name, variant_ext, variant_label, qty, customer_name, phone, ip_hash, respond_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))',
            [$code, hash('sha256', $key), $p['id'], $v['id'], $slug, $p['name_fa'], $ext,
             mb_substr(implode(' · ', array_column($labels, 'label')), 0, 500), $qty, $name, $phone, $ip]);
        db()->commit();
        return ['ok' => true, 'row' => inquiry_get($id), 'duplicate' => false];
    } catch (Throwable $e) {
        if (db()->inTransaction()) { db()->rollBack(); }
        throw $e;
    } finally { db_value('SELECT RELEASE_LOCK(?)', [$lock]); }
}

/** No quantity supplied by the form: it always comes from the locked request. */
function inquiry_decide(int $id, string $decision, string $adminName, string $note = ''): array
{
    if (!inquiries_ready() || !in_array($decision, ['approved', 'rejected'], true)) {
        return ['ok' => false, 'error' => 'درخواست معتبر نیست.'];
    }
    db()->beginTransaction();
    try {
        $r = db_one('SELECT * FROM stock_inquiries WHERE id = ? FOR UPDATE', [$id]);
        if ($r === null) { db()->rollBack(); return ['ok' => false, 'error' => 'درخواست پیدا نشد.']; }
        if ($r['status'] !== 'pending') {
            db()->rollBack();
            return ['ok' => true, 'duplicate' => true];
        }
        $before = null; $after = null;
        if ($decision === 'approved') {
            $p = db_one('SELECT id, is_active, in_stock FROM products WHERE id = ? FOR UPDATE', [(int) $r['product_id']]);
            $v = db_one('SELECT * FROM product_variants WHERE id = ? AND product_id = ? AND ext_id = ? FOR UPDATE',
                [(int) $r['variant_id'], (int) $r['product_id'], $r['variant_ext']]);
            if ($p === null || !(int) $p['is_active'] || !(int) $p['in_stock'] || $v === null || !(int) $v['is_active'] || (int) $v['price'] <= 0) {
                db()->rollBack();
                return ['ok' => false, 'error' => 'محصول یا ترکیب آن غیرفعال، حذف‌شده یا بی‌قیمت است؛ ابتدا آن را در ویرایش محصول بررسی کنید.'];
            }
            $before = $v['stock_qty'] === null ? 0 : max(0, (int) $v['stock_qty']);
            $after = $before + (int) $r['qty'];
            if ($after > 2147483647) { throw new RuntimeException('inquiry stock limit'); }
            db_query('UPDATE product_variants SET stock_qty = ?, requires_inquiry = 0 WHERE id = ?', [$after, $v['id']]);
        }
        db_query('UPDATE stock_inquiries SET status = ?, decided_at = NOW(), decided_by = ?, decision_note = ?, stock_before = ?, stock_after = ? WHERE id = ?',
            [$decision, clean_text($adminName, 100), clean_text($note, 300), $before, $after, $id]);
        db()->commit();
        return ['ok' => true, 'duplicate' => false];
    } catch (Throwable $e) {
        if (db()->inTransaction()) { db()->rollBack(); }
        throw $e;
    }
}

/** Group-only, serialized retries. Never falls back to the owner's private chat. */
function inquiry_notify(int $id): bool
{
    $lock = 'suppex_inotify_' . $id;
    if ((int) db_value('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) { return false; }
    try {
        $r = inquiry_get($id);
        if ($r === null) { return false; }
        $group = trim((string) setting('telegram_group_id', ''));
        $bot = suppex_config()['telegram_bot'] ?? [];
        if ($r['notified_at'] !== null && $r['notified_group'] === $group) { return true; }
        $sent = false;
        if (!empty($bot['enabled']) && !empty($bot['token']) && preg_match('/^-[0-9]+$/D', $group)) {
            /* Never put an untrusted HTTP Host in the staff group's link. */
            $site = rtrim((string) setting('site_url', ''), '/');
            if (!preg_match('~^https?://[a-z0-9.\-]+(?::[0-9]+)?$~iD', $site)) { $site = ''; }
            $text = "درخواست استعلام موجودی\n" . $r['code'] . "\n" . $r['product_name'] . "\n" . $r['variant_label']
                . "\nتعداد درخواستی: " . $r['qty'] . "\nمهلت پاسخ: " . $r['respond_by']
                . "\nبررسی و تأیید در پنل:\n" . $site . '/admin/inquiries.php?id=' . $id;
            $sent = telegram_send_text((string) $bot['token'], $group, $text);
        }
        db_query('UPDATE stock_inquiries SET notify_attempted_at = NOW(), notify_state = ?, notified_at = IF(? = 1, NOW(), notified_at), notified_group = IF(? = 1, ?, notified_group) WHERE id = ?',
            [$sent ? 'sent' : 'failed', $sent ? 1 : 0, $sent ? 1 : 0, $group, $id]);
        return $sent;
    } finally { db_value('SELECT RELEASE_LOCK(?)', [$lock]); }
}
