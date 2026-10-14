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
        'products/product_sizes.profit_percent — keep the MARGIN, not the amount',
        static fn(): bool => column_exists('products', 'profit_percent')
            && column_exists('product_sizes', 'profit_percent'),
        static function (): void {
            /* profit_toman is a fixed sum, so the percentage margin shrinks
               every time the dirham climbs: 52.9% on a 40-dirham tub at a rate
               of 20,000 is 31.0% at 50,000, while every shelf price rises — so
               nothing looks wrong. It also stops funding the replacement tub,
               which now costs more toman than the sale earned.

               Storing the ratio lets the toman profit be re-derived from the
               cost whenever the rate moves. Held against COST rather than
               against the sale price: the two determine each other exactly
               (profit/cost = k, profit/price = k/(1+k)), and cost is the term
               that is known first and can never be a zero denominator. */
            if (!column_exists('products', 'profit_percent')) {
                db()->exec('ALTER TABLE products
                    ADD COLUMN profit_percent DECIMAL(7,3) NULL AFTER profit_toman');
            }
            if (!column_exists('product_sizes', 'profit_percent')) {
                db()->exec('ALTER TABLE product_sizes
                    ADD COLUMN profit_percent DECIMAL(7,3) NULL AFTER profit_toman');
            }

            /* Backfill from what is already priced, so today's catalogue keeps
               today's prices and simply gains the intent behind them. Anything
               without a real cost stays NULL and goes on behaving as it does
               now — an upgrade must not reprice a shop that did not ask. */
            db()->exec('UPDATE products
                           SET profit_percent = ROUND(profit_toman / cost_toman * 100, 3)
                         WHERE price_mode = "aed"
                           AND profit_percent IS NULL
                           AND cost_toman > 0
                           AND profit_toman > 0');
            db()->exec('UPDATE product_sizes s
                          JOIN products p ON p.id = s.product_id
                           SET s.profit_percent = ROUND(s.profit_toman / s.cost_toman * 100, 3)
                         WHERE p.price_mode = "aed"
                           AND s.profit_percent IS NULL
                           AND s.cost_toman > 0
                           AND s.profit_toman > 0');
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
        'product_flavors price delta — a flavour that costs more than its siblings',
        static fn(): bool => column_exists('product_flavors', 'price_delta_toman'),
        static function (): void {
            /* A delta, not a price. These products have sizes AND flavours and
               the shopper picks both, so a full price per combination would be
               four sizes times ten flavours of data entry — and every one of
               them would need revisiting when the dirham moved. A delta is one
               number on the flavours that actually differ, blank on the rest,
               and it composes with whatever the size already costs.

               cost_delta_aed is what the flavour costs extra in Dubai and runs
               through the same rate and margin as everything else;
               price_delta_toman is the resolved figure, rewritten by the
               pricing page for a dirham product and typed directly for one
               bought inside Iran. Zero is the default, which is the common
               case: flavours usually cost the same. */
            db()->exec('ALTER TABLE product_flavors
                ADD COLUMN cost_delta_aed DECIMAL(12,2) NULL AFTER name,
                ADD COLUMN price_delta_toman BIGINT NOT NULL DEFAULT 0 AFTER cost_delta_aed');
        },
        $log
    );

    step(
        'price_quotes — the prices the shop promised a shopper it would honour',
        static fn(): bool => table_exists('price_quotes'),
        static function (): void {
            /* Checkout opens the shopper's chat app BEFORE the server hears
               about the order — window.open only survives a popup blocker
               inside the click gesture — so the amount they paste into Telegram
               comes from prices their browser holds, while the amount the shop
               records comes from the database moments later. The nightly
               repricing can fall between the two, and card-to-card has no
               authorisation step in which to notice.

               A quote is the shop writing down what it displayed, so it can
               charge the lower of that and the current price. */
            db()->exec('CREATE TABLE price_quotes (
                token      CHAR(32)  NOT NULL,
                payload    TEXT      NOT NULL,
                created_at DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME  NOT NULL,
                PRIMARY KEY (token),
                KEY idx_quotes_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        },
        $log
    );

    step(
        'rate_polls — every automatic reading of the dirham, kept or discarded',
        static fn(): bool => table_exists('rate_polls'),
        static function (): void {
            /* Discarded readings are stored as well, which is the reason this
               table exists at all rather than just rate_history. A rate that
               has not moved and a source that has stopped answering look
               identical from outside, and after the fact there is no way to
               tell them apart unless the failures were written down too. The
               reason column is what turns "prices stopped updating" from a
               mystery into a sentence. */
            db()->exec('CREATE TABLE rate_polls (
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                rate         DECIMAL(14,2) NULL,       -- toman, null when nothing usable came back
                source       VARCHAR(40)  NOT NULL DEFAULT "",
                source_ts    DATETIME     NULL,        -- the quote timestamp, not ours
                cross_rate   DECIMAL(14,2) NULL,       -- what the second source said
                cross_source VARCHAR(40)  NOT NULL DEFAULT "",
                peg          DECIMAL(8,4) NULL,        -- observed USD/AED; the peg is 3.6725
                accepted     TINYINT(1)   NOT NULL DEFAULT 0,
                reason       VARCHAR(200) NOT NULL DEFAULT "",
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_rate_polls_created (created_at),
                KEY idx_rate_polls_accepted (accepted, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
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

    /* 2026-08 - the six categories, which were only ever demo data ---------- */

    step(
        'categories - the six the guides and the home page are written against',
        static fn(): bool => (int) db_value('SELECT COUNT(*) FROM categories') > 0,
        static function (): void {
            /* Keyed to the slugs content/categories/*.md are named after, so
               the guides can render at all. INSERT IGNORE, so a shop that has
               renamed one keeps its own name.

               The guard above is "are there ANY categories" rather than "are
               these six there": a shop that built its own taxonomy and deleted
               these must not have them pushed back every time this runs. */
            db()->exec("INSERT IGNORE INTO categories
                    (slug, name_fa, name_latin, blurb, image, sort_order) VALUES
                     ('protein', 'پروتئین', 'Protein', 'وی، ایزوله و کازئین برای ریکاوری و رشد عضله', 'assets/images/categories/protein.svg', 1),
                     ('creatine', 'کراتین', 'Creatine', 'افزایش قدرت، توان انفجاری و حجم سلولی', 'assets/images/categories/creatine.svg', 2),
                     ('mass-gainer', 'افزایش وزن', 'Mass Gainer', 'کالری متراکم برای دوره حجم', 'assets/images/categories/mass-gainer.svg', 3),
                     ('pre-workout', 'قبل تمرین', 'Pre-Workout', 'انرژی، تمرکز و پامپ برای جلسات سنگین', 'assets/images/categories/pre-workout.svg', 4),
                     ('amino', 'آمینو و BCAA', 'Amino', 'حفظ عضله در تمرین و کات', 'assets/images/categories/amino.svg', 5),
                     ('vitamins', 'ویتامین و مکمل', 'Vitamins', 'پایه سلامت، خواب و ریکاوری روزانه', 'assets/images/categories/vitamins.svg', 6)");
        },
        $log
    );

    /* 2026-09 - roles that mean something ---------------------------------- */

    step(
        "admins.role - 'support' and 'manager' replace 'owner' and 'staff'",
        static fn(): bool => (string) db_value(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins'
                AND COLUMN_NAME = 'role'") === "enum('support','manager')",
        static function (): void {
            /* Widened before anything is remapped: an ENUM rejects a value it
               does not yet know, so remapping first would set every row to ''
               and lock everybody out of the maintenance tools at once. */
            db()->exec("ALTER TABLE admins MODIFY role
                ENUM('owner','staff','support','manager') NOT NULL DEFAULT 'manager'");

            /* owner kept full access, so it becomes support; staff becomes
               manager. Nobody loses anything they had yesterday. */
            db()->exec("UPDATE admins SET role = 'support' WHERE role = 'owner'");
            db()->exec("UPDATE admins SET role = 'manager' WHERE role = 'staff'");

            /* And now the old names go, so there is one vocabulary rather than
               four values where two are dead. */
            db()->exec("ALTER TABLE admins MODIFY role
                ENUM('support','manager') NOT NULL DEFAULT 'manager'");
        },
        $log
    );
    /* 2026-09 - attributes and variations ---------------------------------- */

    step(
        'product_attributes + variants - one section for every option',
        static fn(): bool => table_exists('product_variants'),
        static function (): void {
            /* An axis: «اندازه», «طعم», or anything the owner adds.

               Per product rather than global. A shop-wide attribute list is
               what WooCommerce does and it earns its keep when hundreds of
               products share a taxonomy; here one owner types the flavours
               that came in the box, and a global list would mean maintaining
               a vocabulary as well as a catalogue. */
            db()->exec("CREATE TABLE IF NOT EXISTS product_attributes (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                product_id  INT UNSIGNED NOT NULL,
                ext_id      VARCHAR(60)  NOT NULL,
                name        VARCHAR(120) NOT NULL,
                /* Whether picking this one changes the price on its own, the
                   way size does, or only in combination. Kept so the panel can
                   explain itself; the variant's price is what is charged. */
                sort_order  INT NOT NULL DEFAULT 0,
                UNIQUE KEY uq_attr (product_id, ext_id),
                KEY idx_attr_product (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            /* A point on that axis. Carries the picture, because a photograph
               belongs to «شکلاتی» rather than to «۹۰۰ گرم شکلاتی» - the same
               tub in two sizes is one photograph. A variant may still override
               it when that is not true. */
            db()->exec("CREATE TABLE IF NOT EXISTS product_attribute_values (
                id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                attribute_id INT UNSIGNED NOT NULL,
                ext_id       VARCHAR(60)  NOT NULL,
                label        VARCHAR(120) NOT NULL,
                image        VARCHAR(255) NOT NULL DEFAULT '',
                sort_order   INT NOT NULL DEFAULT 0,
                UNIQUE KEY uq_value (attribute_id, ext_id),
                KEY idx_value_attr (attribute_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            /* One thing a shopper can actually buy.

               The money columns mirror product_sizes exactly, because the
               dirham pricing, the locked margin and the applied rate all have
               to work here the same way - a variant that could not be priced
               in dirhams would be a step backwards for this shop. */
            db()->exec("CREATE TABLE IF NOT EXISTS product_variants (
                id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                product_id         INT UNSIGNED NOT NULL,
                ext_id             VARCHAR(160) NOT NULL,
                price              BIGINT NOT NULL DEFAULT 0,
                compare_at         BIGINT NULL,
                cost_aed           DECIMAL(12,2) NULL,
                cost_toman         BIGINT NULL,
                profit_toman       BIGINT NULL,
                profit_percent     DECIMAL(7,3) NULL,
                promo_toman        BIGINT NULL,
                commission_percent DECIMAL(5,2) NULL,
                price_applied_rate DECIMAL(14,2) NULL,
                price_applied_at   DATETIME NULL,
                /* NULL means «not counted», the same as everywhere else in
                   this schema - not «none left». */
                stock_qty          INT NULL,
                image              VARCHAR(255) NOT NULL DEFAULT '',
                is_active          TINYINT(1) NOT NULL DEFAULT 1,
                sort_order         INT NOT NULL DEFAULT 0,
                UNIQUE KEY uq_variant (product_id, ext_id),
                KEY idx_variant_product (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            /* Which point on each axis this variant is. A row per axis, so a
               third attribute costs a row rather than a column. */
            db()->exec("CREATE TABLE IF NOT EXISTS product_variant_values (
                variant_id INT UNSIGNED NOT NULL,
                value_id   INT UNSIGNED NOT NULL,
                PRIMARY KEY (variant_id, value_id),
                KEY idx_vv_value (value_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        },
        $log
    );

    /* 2026-09 - a photograph per flavour ----------------------------------- */

    step(
        'product_flavors.image - a picture per flavour',
        static fn(): bool => column_exists('product_flavors', 'image'),
        static function (): void {
            /* Empty, not NULL: every reader treats '' as «use the product's own
               picture», and a nullable column would give the same meaning two
               spellings for every one of them to get right. */
            db()->exec("ALTER TABLE product_flavors
                ADD COLUMN image VARCHAR(255) NOT NULL DEFAULT '' AFTER name");
        },
        $log
    );

    /* 2026-09 - the pictures the seed never got to write ------------------- */

    step(
        'categories.image - artwork for categories that were seeded without it',
        static fn(): bool => migrate_category_artwork(false) === 0,
        static function (): void { migrate_category_artwork(true); },
        $log
    );

    /* 2026-09 - payment receipts from the site ------------------------------ */

    step(
        'order_receipts - the customer\'s photograph of the transfer',
        static fn(): bool => table_exists('order_receipts'),
        static function (): void {
            /* file is a name inside the private receipts directory - see
               lib/receipts.php - never a web path: a receipt cannot be
               reached by URL at all. sent_* is what Telegram accepted, so
               the panel can say what did not arrive. */
            db()->exec("CREATE TABLE IF NOT EXISTS order_receipts (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id    BIGINT UNSIGNED NOT NULL,
                file        VARCHAR(120) NOT NULL,
                bytes       INT UNSIGNED NOT NULL DEFAULT 0,
                width       INT UNSIGNED NOT NULL DEFAULT 0,
                height      INT UNSIGNED NOT NULL DEFAULT 0,
                ip          VARBINARY(16) NULL,
                sent_chat   TINYINT(1) NOT NULL DEFAULT 0,
                sent_group  TINYINT(1) NOT NULL DEFAULT 0,
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_receipts_order (order_id),
                CONSTRAINT fk_receipts_order FOREIGN KEY (order_id)
                  REFERENCES orders (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        },
        $log
    );

    /* 2026-09 - brands: a logo, a Persian name and an order ---------------- */

    step(
        'brands - logos, Persian names and order for the brands on the products',
        static fn(): bool => table_exists('brands'),
        static function (): void {
            /* Not a list of brands: those come from products.brand, and a
               brand with no row here is still a brand (lib/brands.php). A row
               is what the owner added to one - keyed by the slug its products'
               spellings share. */
            db()->exec("CREATE TABLE IF NOT EXISTS brands (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                slug        VARCHAR(120) NOT NULL,
                name        VARCHAR(120) NOT NULL DEFAULT '',
                name_fa     VARCHAR(120) NOT NULL DEFAULT '',
                logo        VARCHAR(255) NOT NULL DEFAULT '',
                blurb       VARCHAR(255) NOT NULL DEFAULT '',
                sort_order  INT NOT NULL DEFAULT 0,
                is_visible  TINYINT(1) NOT NULL DEFAULT 1,
                updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_brands_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        },
        $log
    );

    step(
        'brands.logo_style - the cream and the coloured version of each logo',
        static fn(): bool => column_exists('brands', 'logo_style'),
        static function (): void {
            /* Two files made from the one the owner uploads (brand_logo_variants()
               in lib/brands.php), and which of them the storefront draws. The
               upload itself stays in `logo`. */
            db()->exec("ALTER TABLE brands
                ADD COLUMN logo_mono  VARCHAR(255) NOT NULL DEFAULT '' AFTER logo,
                ADD COLUMN logo_color VARCHAR(255) NOT NULL DEFAULT '' AFTER logo_mono,
                ADD COLUMN logo_style VARCHAR(10)  NOT NULL DEFAULT 'mono' AFTER logo_color");
        },
        $log
    );

    step(
        'products.compare_manual - a struck price typed by hand on a dirham product',
        static fn(): bool => column_exists('products', 'compare_manual'),
        static function (): void {
            /* compare_at is what the storefront strikes through, and for a
               dirham product every repricing rewrites it. A figure the owner
               types has to live where the pricing never writes; it is copied
               back after each repricing by pricing_place_manual_compare() in
               lib/pricing.php. */
            db()->exec('ALTER TABLE products
                ADD COLUMN compare_manual BIGINT NULL AFTER compare_at');

            /* A dirham product's struck price that no discount explains was
               typed by hand - the formula writes one only from a discount - so
               it becomes the typed figure it always was. Left behind in
               compare_at, the next save or rate change would drop it without a
               word. */
            db()->exec("UPDATE products SET compare_manual = compare_at
                         WHERE price_mode = 'aed' AND compare_at > 0
                           AND (promo_toman IS NULL OR promo_toman = 0)");
        },
        $log
    );

    /* 2026-10 - reviews, from buyers ---------------------------------------- */

    step(
        'product_reviews - what buyers wrote, waiting for the owner',
        static fn(): bool => table_exists('product_reviews'),
        static function (): void {
            /* One per product per order, written on the tracking page by
               whoever has the order's code and phone (lib/reviews.php). */
            db()->exec("CREATE TABLE IF NOT EXISTS product_reviews (
                  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                  product_id  INT UNSIGNED NOT NULL,
                  order_id    BIGINT UNSIGNED NOT NULL,
                  rating      TINYINT UNSIGNED NOT NULL,
                  body        TEXT NULL,
                  author      VARCHAR(60) NOT NULL DEFAULT '',
                  status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  decided_at  DATETIME NULL,
                  PRIMARY KEY (id),
                  UNIQUE KEY uq_reviews_order_product (order_id, product_id),
                  KEY idx_reviews_product (product_id, status),
                  KEY idx_reviews_status (status, created_at),
                  CONSTRAINT fk_reviews_product FOREIGN KEY (product_id)
                    REFERENCES products (id) ON DELETE CASCADE,
                  CONSTRAINT fk_reviews_order FOREIGN KEY (order_id)
                    REFERENCES orders (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        },
        $log
    );

    /* 2026-10 - photographs for the Excel import ----------------------------- */

    step(
        'import_photos - photographs waiting for a sheet to name them',
        static fn(): bool => table_exists('import_photos'),
        static function (): void {
            /* Uploaded on the import page one at a time, already resized,
               and attached when an import names them (lib/import.php). */
            db()->exec("CREATE TABLE IF NOT EXISTS import_photos (
                  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  name_key      VARCHAR(190) NOT NULL,
                  original_name VARCHAR(255) NOT NULL DEFAULT '',
                  path          VARCHAR(255) NOT NULL,
                  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (id),
                  UNIQUE KEY uq_import_photos_name (name_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        },
        $log
    );

    step(
        'product_images.source_name - which uploaded file a photograph came from',
        static fn(): bool => column_exists('product_images', 'source_name'),
        static function (): void {
            /* So importing the same sheet twice does not attach its
               photographs twice. */
            db()->exec('ALTER TABLE product_images
                ADD COLUMN source_name VARCHAR(190) NULL AFTER path');
        },
        $log
    );

    /* 2026-10 - an order line carries a combination's whole key ------------ */

    step(
        'order_items.size_ext - long enough for a size-and-flavour key',
        static fn(): bool => migrate_column_length('order_items', 'size_ext') >= 160,
        static function (): void {
            /* A combination's key is «size|flavour», up to 160 characters
               (product_variants.ext_id), and the order line has to carry it
               whole. At 60 the insert failed outright in strict mode - the
               order was not recorded, the customer got no code and no card -
               and outside strict mode a cut key could never give its stock
               back. */
            db()->exec("ALTER TABLE order_items
                MODIFY size_ext VARCHAR(160) NOT NULL DEFAULT '',
                MODIFY stock_from VARCHAR(160) NULL");
        },
        $log
    );
}

/** A text column's length in characters, 0 when the column is not there. */
function migrate_column_length(string $table, string $column): int
{
    return (int) db_value(
        'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column]
    );
}


/**
 * Give the bundled artwork to categories that have no picture of their own.
 *
 * The seed inserts the six WITH their pictures, but its already-done guard is
 * «are there ANY categories» - so a shop whose categories arrived some other
 * way skipped it and ended up with six rows and an empty image column. The
 * storefront draws those as black rectangles, and nothing in the panel says
 * why.
 *
 * Matched on slug OR Persian name, because the two shapes are both real:
 * setup.php seeds Latin slugs like 'protein', while a catalogue import
 * slugifies the Persian name and produces 'پروتئین'. name_latin cannot
 * arbitrate - on a live shop only one of the six rows has it filled. The
 * Persian display name is the one identifier both shapes share.
 *
 * One function for both the guard and the action deliberately: a guard that
 * asks a different question from the action is how a migration comes to
 * report success while changing nothing.
 *
 * @param bool $apply false to count what is missing, true to also fill it.
 * @return int rows still without a picture that the artwork covers.
 */
function migrate_category_artwork(bool $apply): int
{
    /* file on disk => the name_fa the seed gives that category */
    $artwork = [
        'protein'     => 'پروتئین',
        'creatine'    => 'کراتین',
        'mass-gainer' => 'افزایش وزن',
        'pre-workout' => 'قبل تمرین',
        'amino'       => 'آمینو و BCAA',
        'vitamins'    => 'ویتامین و مکمل',
    ];

    $missing = 0;
    foreach ($artwork as $file => $nameFa) {
        $rel = 'assets/images/categories/' . $file . '.svg';
        /* Writing a path to a file this host does not have would trade a black
           card for a broken one, so an absent file simply means no backfill. */
        if (!is_file(SUPPEX_ROOT . '/' . $rel)) {
            continue;
        }

        /* Only rows with NO picture: one the owner has already photographed
           keeps what they chose. */
        $where = "(image IS NULL OR image = '') AND (slug = ? OR name_fa = ?)";
        $rows  = (int) db_value("SELECT COUNT(*) FROM categories WHERE $where",
                                [$file, $nameFa]);
        if ($rows === 0) {
            continue;
        }

        $missing += $rows;
        if ($apply) {
            db_query("UPDATE categories SET image = ? WHERE $where",
                     [$rel, $file, $nameFa]);
        }
    }

    return $missing;
}

/** Which migrations are present. Used by the panel and by preflight. */
function migrate_pending(): array
{
    return [
        'products.cost_aed'          => column_exists('products', 'cost_aed'),
        'products.promo_toman'       => column_exists('products', 'promo_toman'),
        'product_sizes.cost_aed'     => column_exists('product_sizes', 'cost_aed'),
        'products.price_mode'        => column_exists('products', 'price_mode'),
        'products.profit_percent'    => column_exists('products', 'profit_percent'),
        'product_sizes.profit_percent' => column_exists('product_sizes', 'profit_percent'),
        'products.price_applied_rate'=> column_exists('products', 'price_applied_rate'),
        'rate_history'               => table_exists('rate_history'),
        'rate_polls'                 => table_exists('rate_polls'),
        'price_quotes'               => table_exists('price_quotes'),
        'product_flavors.price_delta_toman' => column_exists('product_flavors', 'price_delta_toman'),
        'product_flavors.image'      => column_exists('product_flavors', 'image'),
        'product_attributes'         => table_exists('product_attributes'),
        'product_attribute_values'   => table_exists('product_attribute_values'),
        'product_variants'           => table_exists('product_variants'),
        'product_variant_values'     => table_exists('product_variant_values'),
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
        'categories seeded'          => (int) db_value('SELECT COUNT(*) FROM categories') > 0,
        'categories.image'           => migrate_category_artwork(false) === 0,
        'order_receipts'             => table_exists('order_receipts'),
        'brands'                     => table_exists('brands'),
        'brands.logo_style'          => column_exists('brands', 'logo_style'),
        'products.compare_manual'    => column_exists('products', 'compare_manual'),
        'product_reviews'            => table_exists('product_reviews'),
        'import_photos'              => table_exists('import_photos'),
        'product_images.source_name' => column_exists('product_images', 'source_name'),
        'order_items.size_ext 160'   => migrate_column_length('order_items', 'size_ext') >= 160,
        'admins.role support/manager'=> (string) db_value(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins'
                AND COLUMN_NAME = 'role'") === "enum('support','manager')",
    ];
}
