<?php
/* ===========================================================================
   The schema migrations, in one place because two paths need them.
   ---------------------------------------------------------------------------
   db/schema.sql is the shape the project started with. Everything the dirham
   pricing, the cost and profit snapshot and the partnership commission added
   since then lives here, as steps that check before they act.

   Both callers run the same list:
     setup.php          - a fresh install, right after the schema is created
     admin/migrate.php  - an existing shop being brought up to date

   That matters. While only the admin page called this, a fresh install came
   up ten columns and one table short, and the first order died with "Unknown
   column 'profit_total'" inside a transaction - so the shop looked installed
   and silently could not take money.

   Every step asks whether it has already been applied and skips if so, so
   running this twice is safe and running it on a half-migrated database
   finishes the job rather than failing.
   =========================================================================== */

function column_exists(string $table, string $column): bool
{
    return db_value(
        'SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column]
    ) !== null;
}

/** Does this index exist? A step must be able to ask about every object it
    creates, not just the first one. */
function index_exists(string $table, string $index): bool
{
    return db_one(
        "SELECT 1 FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1",
        [$table, $index]
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

/**
 * Apply every migration that is not already in place.
 *
 * @param array<int,array{0:string,1:string}> $log filled with [state, label],
 *        state being 'done', 'skip' or 'fail'.
 */
function migrate_run(array &$log): void
{
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
        'products.promo_toman — a discount as an amount off, not a second price',
        static fn(): bool => column_exists('products', 'promo_toman'),
        static function (): void {
            /* Stored as an amount off rather than as a second price, so the
               strikethrough keeps tracking the rate. A compare_at typed
               directly would shrink the advertised saving on its own every time
               the dirham rose, and would vanish entirely once price overtook
               it. */
            db()->exec('ALTER TABLE products
                ADD COLUMN promo_toman BIGINT NULL AFTER profit_toman');
        },
        $log
    );

    step(
        'product_sizes — dirham cost, profit and promo per size',
        static fn(): bool => column_exists('product_sizes', 'cost_aed'),
        static function (): void {
            /* A 900g tub and a 2270g tub are bought separately at different
               dirham prices and sold at different toman prices. Pricing only
               the parent would leave every size frozen at an old price while
               reporting success, and would record the parent's cost against a
               variant's revenue — which is the number the partnership share
               is computed from. */
            db()->exec('ALTER TABLE product_sizes
                ADD COLUMN cost_aed DECIMAL(12,2) NULL AFTER compare_at,
                ADD COLUMN profit_toman BIGINT NULL AFTER cost_aed,
                ADD COLUMN promo_toman BIGINT NULL AFTER profit_toman,
                ADD COLUMN cost_toman BIGINT NULL AFTER promo_toman,
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

    /* 2026-08 — per-line commission basis --------------------------------- */

    step(
        "orders.commission_basis — allow 'mixed'",
        static fn(): bool => (bool) db_one(
            "SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'orders'
                AND column_name = 'commission_basis' AND column_type LIKE '%mixed%' LIMIT 1"),
        static function (): void {
            /* An order whose lines were charged on different bases is neither
               'goods' nor 'profit', and saying either would be a quiet lie on
               the one record the partnership settles from. */
            db()->exec("ALTER TABLE orders
                MODIFY COLUMN commission_basis ENUM('goods','profit','mixed')
                NOT NULL DEFAULT 'goods'");
        },
        $log
    );

    step(
        'order_items.commission_basis — which basis each line was charged on',
        static fn(): bool => column_exists('order_items', 'commission_basis'),
        static function (): void {
            db()->exec("ALTER TABLE order_items
                ADD COLUMN commission_basis VARCHAR(8) NOT NULL DEFAULT 'goods'
                AFTER commission_percent");
        },
        $log
    );

    /* 2026-08 — the order endpoint's duplicate guard ---------------------- */

    step(
        'orders.items_hash — so a double-tapped button is one order',
        static fn(): bool => column_exists('orders', 'items_hash'),
        static function (): void {
            /* Each object is checked on its own. Dropping the column leaves the
               composite index behind on the remaining columns, so a step that
               tested only the column and then created both failed on the index
               with "Duplicate key name" - and a half-migrated database is
               precisely what this mechanism exists to finish. */
            if (!column_exists('orders', 'items_hash')) {
                db()->exec('ALTER TABLE orders
                    ADD COLUMN items_hash CHAR(40) NULL AFTER phone');
            }
            /* Indexed with phone because that is how it is queried, and only
               ever over the last minute. */
            if (!index_exists('orders', 'idx_orders_dupe')) {
                db()->exec('CREATE INDEX idx_orders_dupe ON orders (phone, items_hash, created_at)');
            }
        },
        $log
    );

    /* 2026-08 - stock counts ----------------------------------------------- */

    step(
        'products.stock_qty + product_sizes.stock_qty - how many are left',
        static fn(): bool => column_exists('products', 'stock_qty')
            && column_exists('product_sizes', 'stock_qty'),
        static function (): void {
            /* NULL, not 0. NULL means nobody has counted this, which is the
               state every existing product is in and must stay in - defaulting
               to zero would take the whole catalogue off sale the moment this
               ran. */
            if (!column_exists('products', 'stock_qty')) {
                db()->exec('ALTER TABLE products
                    ADD COLUMN stock_qty INT NULL AFTER in_stock');
            }
            if (!column_exists('product_sizes', 'stock_qty')) {
                db()->exec('ALTER TABLE product_sizes
                    ADD COLUMN stock_qty INT NULL AFTER price');
            }
            /* ext_id is how every order line and every reservation finds a
               size, and the only index on the table is (product_id,
               sort_order) - so each lookup scanned the product's sizes. That
               was tolerable while it was one SELECT; it is not once the
               reservation takes a row lock through it, because the scan locks
               every size of the product instead of the one being sold.

               Not UNIQUE. A duplicate ext_id is a bug, but adding a constraint
               that existing rows might already violate turns a migration into
               a failed install, and this index is here for the lookup. */
            if (!index_exists('product_sizes', 'idx_psizes_ext')) {
                db()->exec('CREATE INDEX idx_psizes_ext ON product_sizes (product_id, ext_id)');
            }
        },
        $log
    );

    step(
        'order_items.size_ext + stock_held + stock_from - a reversible hold',
        static fn(): bool => column_exists('order_items', 'stock_held'),
        static function (): void {
            /* size_ext is the size that was ordered. The line already stores
               variant_label, but that is prose assembled for a human - it can
               say "وانیل · 900 گرم" - and giving stock back needs the id.

               stock_held is how many units this line is holding NOW, so
               zeroing it as the units go back makes the release idempotent
               without an order-level flag. stock_from records which row they
               came out of, because a size may start or stop being counted
               between the order and its cancellation and the units have to go
               home to where they were taken from. */
            if (!column_exists('order_items', 'size_ext')) {
                db()->exec("ALTER TABLE order_items
                    ADD COLUMN size_ext VARCHAR(60) NOT NULL DEFAULT '' AFTER variant_label");
            }
            if (!column_exists('order_items', 'stock_held')) {
                db()->exec('ALTER TABLE order_items
                    ADD COLUMN stock_held INT NOT NULL DEFAULT 0,
                    ADD COLUMN stock_from VARCHAR(60) NULL');
            }
        },
        $log
    );

    /* 2026-08 - the postal tracking number ---------------------------------- */

    step(
        'orders.tracking_code - the barcode the customer would otherwise have to ask for',
        static fn(): bool => column_exists('orders', 'tracking_code'),
        static function (): void {
            /* VARCHAR, not BIGINT. Iran Post's barcode is twenty-four digits,
               which overflows a signed 64-bit integer, and a leading zero on a
               tracking number is part of the number. Nothing arithmetic is
               ever done with it. */
            db()->exec("ALTER TABLE orders
                ADD COLUMN tracking_code VARCHAR(40) NOT NULL DEFAULT '' AFTER channel");
        },
        $log
    );
}

/** Which migrations are present. Used by the panel and by preflight. */
function migrate_pending(): array
{
    return [
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
        'orders.items_hash'          => column_exists('orders', 'items_hash'),
        'products.stock_qty'         => column_exists('products', 'stock_qty'),
        'order_items.stock_held'     => column_exists('order_items', 'stock_held'),
        'orders.tracking_code'       => column_exists('orders', 'tracking_code'),
    ];
}
