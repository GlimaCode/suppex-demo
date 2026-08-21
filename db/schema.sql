-- ===========================================================================
-- SUPPEX — Database schema (MySQL 5.7+ / MariaDB 10.3+)
-- ---------------------------------------------------------------------------
-- Import once through phpMyAdmin in cPanel, then run db/seed.php to load the
-- catalogue that currently lives in assets/js/catalog.js.
--
-- Conventions used throughout:
--   * Money is a BIGINT of Toman. Never a decimal, never a formatted string —
--     the same rule the front-end already follows, so no conversion is needed
--     at the boundary and no rounding error can appear between them.
--   * utf8mb4 everywhere. utf8 in MySQL is three bytes and silently mangles
--     emoji and some Persian presentation forms; there is no reason to use it.
--   * Structured extras (features, nutrition rows) are TEXT holding JSON
--     rather than the JSON column type, which shared hosts on older MariaDB
--     do not always have.
-- ===========================================================================

SET NAMES utf8mb4;
SET time_zone = '+03:30';


-- --- Who can sign in to the admin panel ------------------------------------
CREATE TABLE IF NOT EXISTS admins (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username       VARCHAR(64)  NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,   -- password_hash(), never a plain or md5 password
  name           VARCHAR(120) NOT NULL DEFAULT '',
  role           ENUM('owner','staff') NOT NULL DEFAULT 'staff',
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at  DATETIME     NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admins_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --- Failed sign-in attempts, for throttling -------------------------------
-- A login form with no rate limit is a password guesser's free pass. Rows are
-- keyed by IP *and* username so one attacker cannot lock out a real admin by
-- hammering their username from elsewhere.
CREATE TABLE IF NOT EXISTS login_attempts (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip          VARBINARY(16) NOT NULL,
  username    VARCHAR(64)   NOT NULL,
  attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attempts_ip   (ip, attempted_at),
  KEY idx_attempts_user (username, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --- Catalogue --------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(80)  NOT NULL,       -- 'protein' — what the URL and the front-end use
  name_fa     VARCHAR(120) NOT NULL,
  name_latin  VARCHAR(120) NOT NULL DEFAULT '',
  blurb       VARCHAR(255) NOT NULL DEFAULT '',
  image       VARCHAR(255) NOT NULL DEFAULT '',
  sort_order  INT          NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS products (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(120) NOT NULL,
  name_fa       VARCHAR(180) NOT NULL,
  name_en       VARCHAR(180) NOT NULL DEFAULT '',
  brand         VARCHAR(120) NOT NULL DEFAULT '',
  category_id   INT UNSIGNED NULL,
  price         BIGINT       NOT NULL DEFAULT 0,   -- Toman
  compare_at    BIGINT       NULL,                 -- pre-discount price, NULL = not on sale
  in_stock      TINYINT(1)   NOT NULL DEFAULT 1,
  badges        VARCHAR(255) NOT NULL DEFAULT '',  -- comma list: bestseller,new
  image         VARCHAR(255) NOT NULL DEFAULT '',  -- primary image
  variant_label VARCHAR(180) NOT NULL DEFAULT '',
  short         VARCHAR(500) NOT NULL DEFAULT '',
  description   TEXT         NULL,
  features      TEXT         NULL,                 -- JSON array of strings
  ingredients   TEXT         NULL,
  usage_text    TEXT         NULL,                 -- USAGE is a reserved word
  nutrition_serving VARCHAR(180) NOT NULL DEFAULT '',
  nutrition_rows TEXT        NULL,                 -- JSON [{label,value}]
  sort_order    INT          NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_slug (slug),
  KEY idx_products_category (category_id, is_active),
  KEY idx_products_active (is_active, sort_order),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id)
    REFERENCES categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS product_images (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id INT UNSIGNED NOT NULL,
  path       VARCHAR(255) NOT NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_pimages_product (product_id, sort_order),
  CONSTRAINT fk_pimages_product FOREIGN KEY (product_id)
    REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Flavours and sizes are separate tables rather than one polymorphic "options"
-- table because they behave differently: a size carries its own price, a
-- flavour does not. Collapsing them would mean a nullable price column and a
-- kind check on every read.
CREATE TABLE IF NOT EXISTS product_flavors (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id INT UNSIGNED NOT NULL,
  ext_id     VARCHAR(60)  NOT NULL,   -- 'vanilla' — stable id the cart stores
  name       VARCHAR(120) NOT NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_pflavors_product (product_id, sort_order),
  CONSTRAINT fk_pflavors_product FOREIGN KEY (product_id)
    REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS product_sizes (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id INT UNSIGNED NOT NULL,
  ext_id     VARCHAR(60)  NOT NULL,   -- '900'
  label      VARCHAR(120) NOT NULL,
  servings   INT          NULL,
  price      BIGINT       NOT NULL DEFAULT 0,
  compare_at BIGINT       NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_psizes_product (product_id, sort_order),
  CONSTRAINT fk_psizes_product FOREIGN KEY (product_id)
    REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --- Orders -----------------------------------------------------------------
-- Written the moment the shopper presses "send order", BEFORE the messaging
-- app opens. That ordering is deliberate: if the order only existed as a
-- Telegram message, an order that never got pasted would leave no trace at
-- all, and there would be nothing to reconcile a payment against.
CREATE TABLE IF NOT EXISTS orders (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(20)  NOT NULL,     -- human-readable, e.g. SPX-250821-4F7A
  status        ENUM('new','paid','shipped','done','cancelled') NOT NULL DEFAULT 'new',

  customer_name VARCHAR(180) NOT NULL DEFAULT '',
  phone         VARCHAR(32)  NOT NULL DEFAULT '',
  address       TEXT         NULL,
  postal        VARCHAR(20)  NOT NULL DEFAULT '',
  note          TEXT         NULL,

  subtotal      BIGINT NOT NULL DEFAULT 0,
  shipping      BIGINT NOT NULL DEFAULT 0,
  total         BIGINT NOT NULL DEFAULT 0,

  -- Snapshot, not a live lookup. Changing the rate next month must not silently
  -- rewrite what was owed on orders already placed.
  commission_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  commission_amount  BIGINT       NOT NULL DEFAULT 0,

  channel       VARCHAR(32)  NOT NULL DEFAULT '',   -- telegram | bale | ...
  ip            VARBINARY(16) NULL,
  user_agent    VARCHAR(255) NOT NULL DEFAULT '',

  receipt_note  TEXT     NULL,     -- what the admin typed when marking it paid
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at       DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_code (code),
  KEY idx_orders_status  (status, created_at),
  KEY idx_orders_created (created_at),
  KEY idx_orders_phone   (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Item rows copy the name and price rather than joining to products, so an
-- old order still reads correctly after the product is renamed, repriced or
-- deleted. An invoice must not change retroactively.
CREATE TABLE IF NOT EXISTS order_items (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id      BIGINT UNSIGNED NOT NULL,
  product_id    INT UNSIGNED NULL,
  slug          VARCHAR(120) NOT NULL DEFAULT '',
  name_fa       VARCHAR(180) NOT NULL DEFAULT '',
  variant_label VARCHAR(180) NOT NULL DEFAULT '',
  qty           INT    NOT NULL DEFAULT 1,
  unit_price    BIGINT NOT NULL DEFAULT 0,
  line_total    BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_oitems_order (order_id),
  CONSTRAINT fk_oitems_order FOREIGN KEY (order_id)
    REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --- Audit trail ------------------------------------------------------------
-- Every status change, append-only, with who did it. This is what makes the
-- commission figure defensible: "cancelled" is a logged decision by a named
-- account at a known time, not an order quietly vanishing from a report.
CREATE TABLE IF NOT EXISTS order_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id    BIGINT UNSIGNED NOT NULL,
  admin_id    INT UNSIGNED NULL,
  admin_name  VARCHAR(120) NOT NULL DEFAULT '',
  from_status VARCHAR(20)  NOT NULL DEFAULT '',
  to_status   VARCHAR(20)  NOT NULL DEFAULT '',
  note        VARCHAR(500) NOT NULL DEFAULT '',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_oevents_order (order_id, created_at),
  CONSTRAINT fk_oevents_order FOREIGN KEY (order_id)
    REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --- Shop settings the admin can change without a deploy --------------------
CREATE TABLE IF NOT EXISTS settings (
  setting_key   VARCHAR(64) NOT NULL,
  setting_value TEXT        NULL,
  updated_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('shop_name',              'SUPPEX'),
  ('shop_phone',             ''),
  ('free_shipping_threshold','1500000'),
  ('shipping_flat_rate',     '65000'),
  ('card_number',            ''),
  ('card_holder',            ''),
  ('bank_name',              ''),
  ('commission_percent',     '0'),
  ('telegram_url',           'https://t.me/ARSENX2003'),
  ('bale_url',               'https://ble.ir/ALIARSENX'),
  ('whatsapp_url',           ''),
  ('sale_mode',              '1')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
