<?php
/* ===========================================================================
   SUPPEX — Schema migrations
   ---------------------------------------------------------------------------
   Brings an already-installed database up to the current schema. Run from the
   browser after uploading a new version:

       https://your-domain.ir/admin/migrate.php

   Every step is idempotent — it checks whether the change is already there
   before making it — so running this twice is harmless, and a half-finished
   run can simply be repeated.

   Why this exists rather than "just re-import schema.sql": once the shop is
   live, schema.sql cannot be re-run. Its CREATE TABLE statements are guarded
   with IF NOT EXISTS, so on a live database they do nothing at all, and a new
   column added to schema.sql would never appear. Editing the live tables by
   hand in phpMyAdmin is the alternative, and it goes wrong quietly.
   =========================================================================== */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';

/* Behind the login, and not merely because /db/ is blocked by .htaccess.
   This endpoint runs ALTER TABLE. Left public, anyone who found the URL
   could reshape the shop's database on demand. */
auth_require();

/* --- Introspection helpers ------------------------------------------------- */

function column_exists(string $table, string $column): bool
{
    return db_value(
        'SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column]
    ) !== null;
}

function table_exists(string $table): bool
{
    return db_value(
        'SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$table]
    ) !== null;
}

/**
 * @param callable():bool $isDone   true when the change is already applied
 * @param callable():void $apply
 */
function step(string $label, callable $isDone, callable $apply, array &$log): void
{
    if ($isDone()) {
        $log[] = ['skip', $label];
        return;
    }
    try {
        $apply();
        $log[] = ['done', $label];
    } catch (Throwable $e) {
        $log[] = ['fail', $label . ' — ' . $e->getMessage()];
        error_log('[suppex] migration failed (' . $label . '): ' . $e->getMessage());
    }
}

/* --- Migrations ------------------------------------------------------------ */

$log = [];
$ran = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $ran = true;

    /* 2026-08 — dirham-linked pricing ------------------------------------- */

    step(
        'products.cost_aed — purchase price in dirham',
        static fn(): bool => column_exists('products', 'cost_aed'),
        static function (): void {
            db()->exec('ALTER TABLE products
                ADD COLUMN cost_aed DECIMAL(12,2) NULL AFTER compare_at');
        },
        $log
    );

    step(
        'products.profit_toman — fixed markup per unit',
        static fn(): bool => column_exists('products', 'profit_toman'),
        static function (): void {
            db()->exec('ALTER TABLE products
                ADD COLUMN profit_toman BIGINT NULL AFTER cost_aed');
        },
        $log
    );

    step(
        'products.price_mode — manual toman price, or derived from the rate',
        static fn(): bool => column_exists('products', 'price_mode'),
        static function (): void {
            db()->exec("ALTER TABLE products
                ADD COLUMN price_mode ENUM('manual','aed') NOT NULL DEFAULT 'manual'
                AFTER profit_toman");
        },
        $log
    );

    step(
        'products.cost_toman + price_applied_rate — what the live price was built from',
        static fn(): bool => column_exists('products', 'price_applied_rate'),
        static function (): void {
            /* cost_toman is the dirham cost converted at the rate the price was
               last applied at. Stored rather than recomputed so an order placed
               today records the cost the shop actually priced against, not the
               cost implied by whatever the rate happens to be when a report is
               run next month. */
            db()->exec('ALTER TABLE products
                ADD COLUMN cost_toman BIGINT NULL AFTER price_mode,
                ADD COLUMN price_applied_rate DECIMAL(14,2) NULL AFTER cost_toman,
                ADD COLUMN price_applied_at DATETIME NULL AFTER price_applied_rate');
        },
        $log
    );

    step(
        'per-product commission override',
        static fn(): bool => column_exists('products', 'commission_percent'),
        static function (): void {
            /* NULL means "use the shop-wide rate". A per-product rate exists
               only for the cases the shop-wide one genuinely cannot express —
               a deliberate loss-leader, or a line negotiated separately. It is
               not the normal way to price a product, and defaulting it to NULL
               keeps it that way. */
            db()->exec('ALTER TABLE products
                ADD COLUMN commission_percent DECIMAL(5,2) NULL AFTER promo_toman');
            db()->exec('ALTER TABLE product_sizes
                ADD COLUMN commission_percent DECIMAL(5,2) NULL AFTER promo_toman');
        },
        $log
    );

    step(
        'order_items commission snapshot',
        static fn(): bool => column_exists('order_items', 'commission_percent'),
        static function (): void {
            /* With per-product rates the order total is a sum of lines, not one
               percentage applied to one number. Each line therefore records the
               rate it was charged at, exactly as it already records its price
               and its cost — an order has to stay readable after the rates
               change. */
            db()->exec('ALTER TABLE order_items
                ADD COLUMN commission_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER line_profit,
                ADD COLUMN commission_amount BIGINT NOT NULL DEFAULT 0 AFTER commission_percent');
        },
        $log
    );

    step(
        'commission_log — every change to a rate, with who and when',
        static fn(): bool => table_exists('commission_log'),
        static function (): void {
            /* The rate decides money moving between two parties, so a change to
               it is not an ordinary edit. Append-only, and it survives the
               product being deleted — which is why there is no foreign key. */
            db()->exec('CREATE TABLE commission_log (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                scope       VARCHAR(20)  NOT NULL,
                scope_id    INT UNSIGNED NULL,
                label       VARCHAR(200) NOT NULL DEFAULT "",
                old_percent DECIMAL(5,2) NULL,
                new_percent DECIMAL(5,2) NULL,
                admin_id    INT UNSIGNED NULL,
                admin_name  VARCHAR(120) NOT NULL DEFAULT "",
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_commission_log_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        },
        $log
    );

    step(
        'orders.expires_at + the expired status — the price-hold window',
        static fn(): bool => column_exists('orders', 'expires_at'),
        static function (): void {
            /* A price quoted from a dirham rate cannot stay valid indefinitely.
               The order holds its price for a fixed window; past that it
               expires rather than sitting in the list looking payable at a
               rate that has since moved. "expired" is its own status, not a
               reuse of "cancelled", so a report can tell an order the customer
               abandoned from one somebody actively called off. */
            db()->exec("ALTER TABLE orders
                MODIFY COLUMN status
                    ENUM('new','paid','shipped','done','cancelled','expired')
                    NOT NULL DEFAULT 'new',
                ADD COLUMN expires_at DATETIME NULL AFTER created_at,
                ADD KEY idx_orders_expiry (status, expires_at)");
        },
        $log
    );

    step(
        'rate_history — every exchange-rate change, who made it and when',
        static fn(): bool => table_exists('rate_history'),
        static function (): void {
            db()->exec('CREATE TABLE rate_history (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                rate        DECIMAL(14,2) NOT NULL,
                previous    DECIMAL(14,2) NULL,
                source      VARCHAR(40)  NOT NULL DEFAULT "manual",
                admin_id    INT UNSIGNED NULL,
                admin_name  VARCHAR(120) NOT NULL DEFAULT "",
                applied_to  INT          NOT NULL DEFAULT 0,
                note        VARCHAR(300) NOT NULL DEFAULT "",
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_rate_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        },
        $log
    );

    step(
        'order_items.unit_cost / unit_profit — profit snapshot per line',
        static fn(): bool => column_exists('order_items', 'unit_cost'),
        static function (): void {
            /* Copied onto the order for the same reason name and price already
               are: the commission is computed from these, and an invoice that
               changes retroactively is not an invoice. */
            db()->exec('ALTER TABLE order_items
                ADD COLUMN unit_cost   BIGINT NOT NULL DEFAULT 0 AFTER unit_price,
                ADD COLUMN unit_profit BIGINT NOT NULL DEFAULT 0 AFTER unit_cost,
                ADD COLUMN line_profit BIGINT NOT NULL DEFAULT 0 AFTER line_total');
        },
        $log
    );

    step(
        'orders.profit_total + commission_basis',
        static fn(): bool => column_exists('orders', 'commission_basis'),
        static function (): void {
            db()->exec("ALTER TABLE orders
                ADD COLUMN profit_total BIGINT NOT NULL DEFAULT 0 AFTER total,
                ADD COLUMN commission_basis ENUM('goods','profit') NOT NULL DEFAULT 'goods'
                    AFTER commission_amount,
                ADD COLUMN rate_at_order DECIMAL(14,2) NULL AFTER commission_basis");
        },
        $log
    );

    step(
        'settings — pricing defaults',
        static fn(): bool => db_value(
            'SELECT 1 FROM settings WHERE setting_key = ?', ['aed_rate']
        ) !== null,
        static function (): void {
            $defaults = [
                'aed_rate'              => '',      // blank on purpose: an invented rate would price the whole catalogue wrongly
                'aed_rate_updated_at'   => '',
                'price_rounding_step'   => '10000', // toman
                'price_max_age_days'    => '3',     // warn when the rate has not been refreshed for this long
                'order_hold_minutes'    => '30',    // how long a quoted price stays valid
                'commission_basis'      => 'goods', // 'goods' | 'profit'
            ];
            foreach ($defaults as $k => $v) {
                db_query('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                          ON DUPLICATE KEY UPDATE setting_key = setting_key', [$k, $v]);
            }
        },
        $log
    );

    /* Backfill: existing rows keep behaving exactly as before. A product with
       no dirham cost stays in 'manual' mode and its typed price is untouched. */
    step(
        'backfill — existing products stay on their typed prices',
        static fn(): bool => (int) db_value(
            "SELECT COUNT(*) FROM products WHERE price_mode IS NULL"
        ) === 0,
        static function (): void {
            db()->exec("UPDATE products SET price_mode = 'manual' WHERE price_mode IS NULL");
        },
        $log
    );
}

$pending = [
    'products.cost_aed'          => column_exists('products', 'cost_aed'),
    'products.promo_toman'       => column_exists('products', 'promo_toman'),
    'product_sizes.cost_aed'     => column_exists('product_sizes', 'cost_aed'),
    'products.price_mode'        => column_exists('products', 'price_mode'),
    'products.price_applied_rate'=> column_exists('products', 'price_applied_rate'),
    'rate_history'               => table_exists('rate_history'),
    'order_items.unit_cost'      => column_exists('order_items', 'unit_cost'),
    'orders.commission_basis'    => column_exists('orders', 'commission_basis'),
    'orders.expires_at'          => column_exists('orders', 'expires_at'),
    'products.commission_percent'=> column_exists('products', 'commission_percent'),
    'order_items.commission_percent' => column_exists('order_items', 'commission_percent'),
    'commission_log'             => table_exists('commission_log'),
];
$allDone = !in_array(false, $pending, true);
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>به‌روزرسانی دیتابیس SUPPEX</title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<div class="login">
  <div class="login__box" style="max-width:600px">
    <div class="login__logo">SUPPEX</div>
    <p class="login__sub">به‌روزرسانی ساختار دیتابیس</p>

    <?php if ($ran): ?>
      <?php foreach ($log as [$state, $label]): ?>
        <div class="flash flash--<?= $state === 'fail' ? 'error' : ($state === 'skip' ? 'info' : 'ok') ?>">
          <?= $state === 'done' ? '✓ ' : ($state === 'skip' ? '— ' : '✕ ') ?><?= e($label) ?>
        </div>
      <?php endforeach; ?>
      <?php if (!$log): ?>
        <div class="flash flash--info">چیزی برای به‌روزرسانی نبود.</div>
      <?php endif; ?>
      <a class="btn btn--primary btn--block" href="pricing.php" style="margin-block-start:16px">
        رفتن به صفحه قیمت‌گذاری
      </a>

    <?php else: ?>
      <div class="flash flash--<?= $allDone ? 'ok' : 'info' ?>">
        <?= $allDone
          ? 'دیتابیس به‌روز است. نیازی به اجرای دوباره نیست.'
          : 'چند تغییر در ساختار دیتابیس لازم است.' ?>
      </div>

      <table style="width:100%;margin-block:16px">
        <?php foreach ($pending as $name => $ok): ?>
          <tr>
            <td class="lat" style="padding:6px 0"><?= e($name) ?></td>
            <td class="u-right">
              <span class="pill <?= $ok ? 'pill--paid' : 'pill--off' ?>">
                <?= $ok ? 'موجود' : 'لازم است' ?>
              </span>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>

      <p class="hint">
        این عملیات فقط ستون و جدول <strong>اضافه</strong> می‌کند و هیچ داده‌ای را
        پاک یا بازنویسی نمی‌کند. با این حال، قبل از اجرا از دیتابیس
        خروجی (Backup) بگیرید — این یک عادت است که فقط یک‌بار لازم می‌شود
        و همان یک‌بار جبران همه دفعات را می‌کند.
      </p>

      <form method="post" action="migrate.php">
        <button class="btn btn--primary btn--block" type="submit" style="margin-block-start:16px">
          اجرای به‌روزرسانی
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
