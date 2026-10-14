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
  -- support «پشتیبان» keeps the software running and needs everything.
  -- manager «مدیرکل» runs the shop and has no use for a database dump or a
  -- deployment readiness page. See ROLE_CAPABILITIES in lib/auth.php.
  role           ENUM('support','manager') NOT NULL DEFAULT 'manager',
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at  DATETIME     NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admins_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --- Failed sign-in attempts, for throttling -------------------------------
-- A login form with no rate limit is a password guesser's free pass.
--
-- Both counters in auth_is_throttled() are scoped to the requesting IP: one
-- counts that address's failures against any username, the other its failures
-- against one username. Counting a username's failures across ALL addresses
-- would let anyone who knows the admin's username lock them out of their own
-- shop from anywhere, which is a worse outcome than the guessing it prevents.
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
  source_name VARCHAR(190) NULL,                 -- the uploaded file an import named
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
  status        ENUM('new','paid','shipped','done','cancelled','expired') NOT NULL DEFAULT 'new',

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
  -- How long the quoted price stays good. A price derived from a dirham
  -- rate cannot be open-ended; past this the order expires rather than
  -- sitting in the list looking payable at a rate that has since moved.
  expires_at    DATETIME NULL,
  paid_at       DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_code (code),
  KEY idx_orders_status  (status, created_at),
  KEY idx_orders_created (created_at),
  KEY idx_orders_phone   (phone),
  KEY idx_orders_expiry  (status, expires_at)
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
  -- The commission rate this line was charged at. With per-product rates the
  -- order total is a sum of lines, not one percentage over one number, and an
  -- order has to stay readable after the rates change.
  commission_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  commission_amount  BIGINT       NOT NULL DEFAULT 0,
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

-- The six categories are STRUCTURE, not sample data.
--
-- They used to exist only in db/catalog-seed.json, which is the invented
-- products file and is opt-in. So a shop installed correctly - samples
-- declined - had no categories at all: an empty section on the home page, six
-- category pages answering 404, and the six written buying guides in
-- content/categories/ unable to render, since they are keyed to exactly these
-- slugs.
--
-- INSERT IGNORE on the unique slug: this adds what is missing and never
-- overwrites a name the owner has since changed.
INSERT IGNORE INTO categories (slug, name_fa, name_latin, blurb, image, sort_order) VALUES
  ('protein', 'پروتئین', 'Protein', 'وی، ایزوله و کازئین برای ریکاوری و رشد عضله', 'assets/images/categories/protein.svg', 1),
  ('creatine', 'کراتین', 'Creatine', 'افزایش قدرت، توان انفجاری و حجم سلولی', 'assets/images/categories/creatine.svg', 2),
  ('mass-gainer', 'افزایش وزن', 'Mass Gainer', 'کالری متراکم برای دوره حجم', 'assets/images/categories/mass-gainer.svg', 3),
  ('pre-workout', 'قبل تمرین', 'Pre-Workout', 'انرژی، تمرکز و پامپ برای جلسات سنگین', 'assets/images/categories/pre-workout.svg', 4),
  ('amino', 'آمینو و BCAA', 'Amino', 'حفظ عضله در تمرین و کات', 'assets/images/categories/amino.svg', 5),
  ('vitamins', 'ویتامین و مکمل', 'Vitamins', 'پایه سلامت، خواب و ریکاوری روزانه', 'assets/images/categories/vitamins.svg', 6);


INSERT INTO settings (setting_key, setting_value) VALUES
  ('shop_name',              'SUPPEX'),
  ('shop_phone',             ''),
  ('free_shipping_threshold','1500000'),
  ('shipping_flat_rate',     '65000'),
  ('card_number',            ''),
  ('card_holder',            ''),
  ('bank_name',              ''),
  ('commission_percent',     '0'),
  ('order_hold_minutes',     '30'),
  -- How few is "nearly gone". Below this the storefront says how many are
  -- left; above it, nothing is published at all.
  ('low_stock_threshold',    '3'),
  -- Empty on purpose. The footer used to hard-code a landline, an email
  -- and a Valiasr Street address, none of which were real; these render
  -- nothing until they are filled in, and appear everywhere the moment they
  -- are. shop_phone already existed here and was never read by anything.
  ('shop_email',             ''),
  ('instagram_url',          ''),
  ('aparat_url',             ''),
  ('telegram_url',           'https://t.me/ARSENX2003'),
  ('bale_url',               'https://ble.ir/ALIARSENX'),
  ('whatsapp_url',           ''),
  ('sale_mode',              '1')
ON DUPLICATE KEY UPDATE setting_key = setting_key;


-- --- Commission rate changes ------------------------------------------------
-- The rate decides money moving between two parties, so a change to it is not
-- an ordinary edit. Append-only, and deliberately without a foreign key so the
-- record survives the product being deleted.
CREATE TABLE IF NOT EXISTS commission_log (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope       VARCHAR(20)  NOT NULL,          -- 'shop' | 'product' | 'size'
  scope_id    INT UNSIGNED NULL,
  label       VARCHAR(200) NOT NULL DEFAULT '',
  old_percent DECIMAL(5,2) NULL,
  new_percent DECIMAL(5,2) NULL,
  admin_id    INT UNSIGNED NULL,
  admin_name  VARCHAR(120) NOT NULL DEFAULT '',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_commission_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payment receipts the customer sent from the site (lib/receipts.php). The
-- file is a name inside the private receipts directory, above public_html -
-- never a web path. sent_* is what Telegram accepted.
CREATE TABLE IF NOT EXISTS order_receipts (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What the owner adds to a brand in the panel: a logo, a Persian name, a
-- line about it, an order, and whether it is shown (lib/brands.php). The
-- brands themselves come from products.brand; a brand with no row here is
-- still a brand. Keyed by the slug its products' spellings share.
CREATE TABLE IF NOT EXISTS brands (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(120) NOT NULL,
  name        VARCHAR(120) NOT NULL DEFAULT '',
  name_fa     VARCHAR(120) NOT NULL DEFAULT '',
  logo        VARCHAR(255) NOT NULL DEFAULT '',
  logo_mono   VARCHAR(255) NOT NULL DEFAULT '',
  logo_color  VARCHAR(255) NOT NULL DEFAULT '',
  logo_style  VARCHAR(10)  NOT NULL DEFAULT 'mono',
  blurb       VARCHAR(255) NOT NULL DEFAULT '',
  sort_order  INT NOT NULL DEFAULT 0,
  is_visible  TINYINT(1) NOT NULL DEFAULT 1,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_brands_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What buyers wrote about what they bought (lib/reviews.php): one per product
-- per order, from the tracking page, and shown only once the owner approves
-- it in the panel.
CREATE TABLE IF NOT EXISTS product_reviews (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Photographs uploaded on the import page, waiting for a sheet to name them
-- (lib/import.php). An import attaches each to its product and deletes the row.
CREATE TABLE IF NOT EXISTS import_photos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name_key      VARCHAR(190) NOT NULL,
  original_name VARCHAR(255) NOT NULL DEFAULT '',
  path          VARCHAR(255) NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_import_photos_name (name_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
