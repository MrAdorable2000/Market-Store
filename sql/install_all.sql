-- ==================================================================
-- install_all.sql — Consolidated, verified full installer
-- ==================================================================
-- WHY THIS FILE EXISTS (root-cause fix):
-- The previous README instructed importing ONLY schema.sql + seed.sql.
-- However many pages (home.php, category.php, explore.php, listing-
-- details.php, admin/*, seller/listings.php, seller/edit-product.php,
-- market-insights.php) query columns such as categories.name_key,
-- listings.sku, listings.stock_quantity, listings.reserved_quantity,
-- listings.low_stock_threshold, listings.is_published and
-- listings.published_at. Those columns are only created by the
-- migration files below (phase2_migrate.sql, seller_upgrade.sql,
-- product_form_upgrade.sql, product_management_upgrade.sql,
-- category_catalog_mega_upgrade.sql, etc). On a schema.sql-only
-- install, those pages throw a fatal, uncaught PDOException the
-- moment they run a query referencing a missing column.
--
-- The Add Product form (pages/sell.php) was previously hardened to
-- check column existence dynamically before inserting, so a product
-- CAN actually be created (and IS inserted into the database) even
-- on a bare schema.sql-only install — but the seller then can't see
-- it anywhere (Seller Center listings page, homepage, category
-- pages, product details) because those pages are NOT protected the
-- same way, and they fatal-error instead of rendering. That is why
-- 'Add Product' looked completely broken end-to-end.
--
-- FIX: import this single file into a fresh database and every
-- required column/table will exist from the start. This is the
-- exact migration order that was tested and verified error-free.
-- ==================================================================

-- ---------- BEGIN schema.sql ----------
-- ============================================================================
--  ISOKO RYACU — Full MySQL Schema (DDL)
--  Target: MySQL 8.0+ / MariaDB 10.5+  (XAMPP default)
--  Encoding: utf8mb4 (supports Kinyarwanda + emoji)
--  Run this FIRST, then run seed.sql
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- 1. ROLES
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS roles;
CREATE TABLE roles (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(50)  NOT NULL UNIQUE,   -- admin, seller, buyer
    description   VARCHAR(255) NULL,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 2. USERS
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS users;
CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id         INT UNSIGNED NOT NULL,
    full_name       VARCHAR(120)  NOT NULL,
    email           VARCHAR(160)  NOT NULL UNIQUE,
    password_hash   VARCHAR(255)  NOT NULL,
    phone           VARCHAR(30)   NULL,
    whatsapp_number VARCHAR(40)   NULL,
    location        VARCHAR(120)  NULL,             -- e.g. "Kigali, Rwanda"
    bio             TEXT          NULL,
    avatar_path     VARCHAR(255)  NULL,
    is_verified     TINYINT(1)    NOT NULL DEFAULT 0,
    is_seller       TINYINT(1)    NOT NULL DEFAULT 0,
    status          ENUM('active','suspended','pending') NOT NULL DEFAULT 'active',
    reset_token     VARCHAR(64)   NULL,
    reset_expires   DATETIME      NULL,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_seen_at    DATETIME      NULL,
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT,
    INDEX idx_users_email (email),
    INDEX idx_users_role (role_id),
    INDEX idx_users_status (status),
    INDEX idx_users_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 3. CATEGORIES
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS categories;
CREATE TABLE categories (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(120)  NOT NULL,
    slug            VARCHAR(160)  NOT NULL UNIQUE,
    name_key        VARCHAR(80)   NULL,             -- i18n translation key (see includes/i18n.php)
    icon            VARCHAR(60)   NULL,             -- icon identifier
    image_path      VARCHAR(255)  NULL,
    description     TEXT          NULL,
    parent_id       INT UNSIGNED  NULL,             -- self-reference for trees
    display_order   INT           NOT NULL DEFAULT 0,
    is_active       TINYINT(1)    NOT NULL DEFAULT 1,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cat_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_cat_slug (slug),
    INDEX idx_cat_parent (parent_id),
    INDEX idx_cat_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 4. SUBCATEGORIES
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS subcategories;
CREATE TABLE subcategories (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id     INT UNSIGNED NOT NULL,
    name            VARCHAR(120)  NOT NULL,
    slug            VARCHAR(160)  NOT NULL,
    name_key        VARCHAR(80)   NULL,             -- i18n translation key (see includes/i18n.php)
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_subcat_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    UNIQUE KEY uq_subcat_slug (category_id, slug),
    INDEX idx_subcat_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 5. LISTINGS  (the heart of the marketplace)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS listings;
CREATE TABLE listings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    seller_id       INT UNSIGNED NOT NULL,
    category_id     INT UNSIGNED NOT NULL,
    subcategory_id  INT UNSIGNED NULL,
    title           VARCHAR(180)  NOT NULL,
    slug            VARCHAR(220)  NOT NULL,
    sku                  VARCHAR(60)  NULL,                  -- seller-defined SKU (see sql/seller_upgrade.sql)
    stock_quantity       INT          NOT NULL DEFAULT 0,    -- inventory for 'sell' listings
    reserved_quantity    INT          NOT NULL DEFAULT 0,
    low_stock_threshold  INT          NOT NULL DEFAULT 5,
    is_published         TINYINT(1)   NOT NULL DEFAULT 1,    -- seller-side publish/unpublish toggle
    description     MEDIUMTEXT    NOT NULL,
    listing_type    ENUM('sell','rent') NOT NULL DEFAULT 'sell',
    price           DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    currency        VARCHAR(8)    NOT NULL DEFAULT 'RWF',
    -- Rental-specific
    price_per_day   DECIMAL(14,2) NULL,
    price_per_week  DECIMAL(14,2) NULL,
    price_per_month DECIMAL(14,2) NULL,
    deposit         DECIMAL(14,2) NULL,
    rental_terms    TEXT          NULL,
    -- Common
    location        VARCHAR(160)  NULL,
    country         VARCHAR(80)   NULL,                      -- structured location (see sql/phase2_migrate.sql)
    province        VARCHAR(80)   NULL,
    district        VARCHAR(80)   NULL,
    area            VARCHAR(120)  NULL,
    condition_state ENUM('new','used','refurbished','for-parts') NULL DEFAULT 'used',
    availability    ENUM('available','sold','rented','reserved')  NOT NULL DEFAULT 'available',
    status          ENUM('pending','active','rejected','expired','draft','archived') NOT NULL DEFAULT 'pending',
    is_featured     TINYINT(1)    NOT NULL DEFAULT 0,
    is_verified     TINYINT(1)    NOT NULL DEFAULT 0,   -- Trust & Verification flag (see includes/smart_features.php)
    published_at    TIMESTAMP     NULL,
    archived_at     TIMESTAMP     NULL,
    views_count     INT UNSIGNED  NOT NULL DEFAULT 0,
    favorites_count INT UNSIGNED  NOT NULL DEFAULT 0,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_list_seller   FOREIGN KEY (seller_id)      REFERENCES users(id)      ON DELETE CASCADE,
    CONSTRAINT fk_list_category FOREIGN KEY (category_id)   REFERENCES categories(id) ON DELETE RESTRICT,
    CONSTRAINT fk_list_subcat    FOREIGN KEY (subcategory_id) REFERENCES subcategories(id) ON DELETE SET NULL,
    INDEX idx_list_seller   (seller_id),
    INDEX idx_list_category (category_id),
    INDEX idx_list_type     (listing_type),
    INDEX idx_list_status   (status),
    INDEX idx_list_location (location),
    INDEX idx_list_featured (is_featured),
    INDEX idx_list_created  (created_at DESC),
    FULLTEXT KEY ft_list_search (title, description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 6. LISTING IMAGES
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS listing_images;
CREATE TABLE listing_images (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    listing_id      INT UNSIGNED NOT NULL,
    image_path      VARCHAR(255) NOT NULL,
    is_primary      TINYINT(1)   NOT NULL DEFAULT 0,
    display_order   INT          NOT NULL DEFAULT 0,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_img_listing FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    INDEX idx_img_listing (listing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 7. LISTING ATTRIBUTES (category-specific key/value pairs)
--     e.g. (make=Toyota, model=Corolla, year=2018, mileage=95000, fuel=petrol)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS listing_attributes;
CREATE TABLE listing_attributes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    listing_id      INT UNSIGNED NOT NULL,
    attr_key        VARCHAR(80)  NOT NULL,
    attr_value      VARCHAR(255) NOT NULL,
    CONSTRAINT fk_attr_listing FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    UNIQUE KEY uq_attr (listing_id, attr_key),
    INDEX idx_attr_listing (listing_id),
    INDEX idx_attr_key (attr_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 8. FAVORITES
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS favorites;
CREATE TABLE favorites (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    listing_id      INT UNSIGNED NOT NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_fav_user   FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    CONSTRAINT fk_fav_listing FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    UNIQUE KEY uq_fav (user_id, listing_id),
    INDEX idx_fav_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 9. RENTAL REQUESTS
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS rental_requests;
CREATE TABLE rental_requests (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    listing_id      INT UNSIGNED NOT NULL,
    renter_id       INT UNSIGNED NOT NULL,
    start_date      DATE         NOT NULL,
    end_date        DATE         NOT NULL,
    deposit_amount  DECIMAL(14,2) NULL,
    message         TEXT         NULL,
    status          ENUM('pending','approved','declined','cancelled','completed') NOT NULL DEFAULT 'pending',
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rent_listing FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    CONSTRAINT fk_rent_renter  FOREIGN KEY (renter_id)  REFERENCES users(id)   ON DELETE CASCADE,
    INDEX idx_rent_listing (listing_id),
    INDEX idx_rent_renter  (renter_id),
    INDEX idx_rent_status  (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 10. CONTACT REQUESTS (messages to sellers)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS contact_requests;
CREATE TABLE contact_requests (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    listing_id      INT UNSIGNED NOT NULL,
    sender_id       INT UNSIGNED NULL,         -- NULL = guest contact
    seller_id       INT UNSIGNED NOT NULL,
    name            VARCHAR(120) NOT NULL,
    email           VARCHAR(160) NOT NULL,
    phone           VARCHAR(30)  NULL,
    message         TEXT         NOT NULL,
    is_read         TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_contact_listing FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    CONSTRAINT fk_contact_seller  FOREIGN KEY (seller_id)  REFERENCES users(id)    ON DELETE CASCADE,
    INDEX idx_contact_seller (seller_id),
    INDEX idx_contact_listing (listing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 11. NOTIFICATIONS
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS notifications;
CREATE TABLE notifications (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    type            VARCHAR(60)  NOT NULL,    -- e.g. favorite, message, rental, system
    title           VARCHAR(180) NOT NULL,
    body            TEXT         NULL,
    link            VARCHAR(255) NULL,
    is_read         TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notif_user (user_id),
    INDEX idx_notif_read  (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 11b. SELLER PROFILES
--     Extends users with seller-specific info (rating, bio, response rate).
--     Required by pages/listing-details.php et al. See sql/phase2_migrate.sql.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS seller_profiles;
CREATE TABLE seller_profiles (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id               INT UNSIGNED NOT NULL UNIQUE,
    business_name         VARCHAR(160)  NULL,
    business_description  TEXT          NULL,
    store_logo            VARCHAR(255)  NULL,
    store_banner          VARCHAR(255)  NULL,
    operating_hours       VARCHAR(255)  NULL,
    is_store_active       TINYINT(1)    NOT NULL DEFAULT 1,
    response_rate         TINYINT UNSIGNED NULL DEFAULT 100,
    response_time_hours   INT UNSIGNED  NULL DEFAULT 1,
    rating_average        DECIMAL(3,2)  NULL DEFAULT 0.00,
    rating_count          INT UNSIGNED  NOT NULL DEFAULT 0,
    total_sales           INT UNSIGNED  NOT NULL DEFAULT 0,
    total_rentals         INT UNSIGNED  NOT NULL DEFAULT 0,
    verified_at           TIMESTAMP     NULL,
    social_facebook       VARCHAR(255)  NULL,
    social_twitter        VARCHAR(255)  NULL,
    social_instagram      VARCHAR(255)  NULL,
    social_whatsapp       VARCHAR(40)   NULL,
    created_at            TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_sp_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 12. REVIEWS
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS reviews;
CREATE TABLE reviews (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reviewer_id     INT UNSIGNED NOT NULL,
    seller_id       INT UNSIGNED NOT NULL,
    listing_id      INT UNSIGNED NULL,
    rating          TINYINT       NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment         TEXT         NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rev_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_rev_seller   FOREIGN KEY (seller_id)   REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_rev_listing  FOREIGN KEY (listing_id)  REFERENCES listings(id) ON DELETE SET NULL,
    UNIQUE KEY uq_rev (reviewer_id, seller_id, listing_id),
    INDEX idx_rev_seller (seller_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 13. REPORTS  (user reports bad listings/sellers)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS reports;
CREATE TABLE reports (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reporter_id     INT UNSIGNED NULL,
    listing_id      INT UNSIGNED NULL,
    reason          VARCHAR(160) NOT NULL,
    details         TEXT         NULL,
    status          ENUM('open','reviewing','resolved','dismissed') NOT NULL DEFAULT 'open',
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rep_reporter FOREIGN KEY (reporter_id) REFERENCES users(id)    ON DELETE SET NULL,
    CONSTRAINT fk_rep_listing  FOREIGN KEY (listing_id)  REFERENCES listings(id) ON DELETE CASCADE,
    INDEX idx_rep_status (status),
    INDEX idx_rep_listing (listing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 14. BLOG POSTS
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS blog_posts;
CREATE TABLE blog_posts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    author_id       INT UNSIGNED NOT NULL,
    title           VARCHAR(220)  NOT NULL,
    slug            VARCHAR(260)  NOT NULL UNIQUE,
    excerpt         VARCHAR(400)  NULL,
    body            MEDIUMTEXT    NOT NULL,
    cover_path      VARCHAR(255)  NULL,
    category        VARCHAR(80)   NULL,        -- e.g. "Buying", "Selling", "Renting"
    tags            VARCHAR(255)  NULL,
    status          ENUM('draft','published','archived') NOT NULL DEFAULT 'published',
    views_count     INT UNSIGNED  NOT NULL DEFAULT 0,
    published_at    TIMESTAMP     NULL,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_blog_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_blog_slug   (slug),
    INDEX idx_blog_status (status),
    INDEX idx_blog_published (published_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 15. MARKET INSIGHTS  (aggregate stats — demo data clearly labelled)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS market_insights;
CREATE TABLE market_insights (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    metric_key      VARCHAR(80)  NOT NULL,    -- e.g. "top_searched_term"
    metric_value    VARCHAR(255) NOT NULL,
    metric_count    INT UNSIGNED NOT NULL DEFAULT 0,
    period          VARCHAR(20)  NOT NULL DEFAULT 'all',  -- all|2025-09|Q3-2025
    recorded_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mi_key (metric_key),
    INDEX idx_mi_period (period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 16. SITE SETTINGS  (admin-tunable)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS site_settings;
CREATE TABLE site_settings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key     VARCHAR(80)  NOT NULL UNIQUE,
    setting_value   TEXT         NOT NULL,
    description     VARCHAR(255) NULL,
    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- End of schema.sql
-- ---------- END schema.sql ----------

-- ---------- BEGIN seed.sql ----------
-- ============================================================================
--  ISOKO RYACU — Seed Data (DEMO)
--  Run AFTER schema.sql.
--  Passwords are bcrypt hashes for:
--     Admin@12345  Seller@12345  Buyer@12345
--  All data below is clearly DEMO data — see the 'demo' tags in market_insights.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Clear demo rows (preserves schema)
TRUNCATE TABLE roles;
TRUNCATE TABLE users;
TRUNCATE TABLE categories;
TRUNCATE TABLE subcategories;
TRUNCATE TABLE listings;
TRUNCATE TABLE listing_images;
TRUNCATE TABLE listing_attributes;
TRUNCATE TABLE favorites;
TRUNCATE TABLE blog_posts;
TRUNCATE TABLE market_insights;
TRUNCATE TABLE site_settings;
TRUNCATE TABLE notifications;
SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------------------------
-- ROLES
-- ----------------------------------------------------------------------------
INSERT INTO roles (id, name, description) VALUES
(1, 'admin',  'Full platform management'),
(2, 'seller', 'Can publish listings + act as buyer'),
(3, 'buyer',  'Can browse, favorite, contact, request rentals');

-- ----------------------------------------------------------------------------
-- USERS  (password_hash = bcrypt)
-- ----------------------------------------------------------------------------
-- Hashes below are bcrypt for the README-documented passwords:
--     admin@isoko.rw  -> Admin@12345
--     seller@isoko.rw -> Seller@12345
--     eric.seller@isoko.rw -> Seller@12345
--     buyer@isoko.rw  -> Buyer@12345
INSERT INTO users (id, role_id, full_name, email, password_hash, phone, location, bio, avatar_path, is_verified, is_seller, status) VALUES
(1, 1, 'Platform Admin',     'admin@isoko.rw',  '$2y$10$XdY.1jpfSLXwayJRXTYoxu9HkQkkUGqDaaBp.c63km7IOXsJfbxiu', '+250 788 000 001', 'Kigali, Rwanda',  'Isoko Ryacu platform administrator.', NULL, 1, 0, 'active'),
(2, 2, 'Aline Uwase',        'seller@isoko.rw', '$2y$10$IA6zA.PZnRmTRd1ed2Zg.eSP.uBlr8gxli4fBdx35/oewIF6JrlEe', '+250 788 000 002', 'Kigali, Rwanda',  'Trusted seller of vehicles and electronics.', NULL, 1, 1, 'active'),
(3, 2, 'Eric Mugisha',       'eric.seller@isoko.rw', '$2y$10$IA6zA.PZnRmTRd1ed2Zg.eSP.uBlr8gxli4fBdx35/oewIF6JrlEe', '+250 788 000 003', 'Huye, Rwanda',  'Property agent and furniture maker.', NULL, 1, 1, 'active'),
(4, 3, 'Claire Iribagiza',   'buyer@isoko.rw',  '$2y$10$17WQlVdqnUMbp59F9CJlveqVIdSGNa/3Tv/BTFYukwXIJE7SB6YAa', '+250 788 000 004', 'Musanze, Rwanda', 'Looking for rental apartments and used phones.', NULL, 0, 0, 'active');

-- ----------------------------------------------------------------------------
-- CATEGORIES  (top-level, with display_order for homepage)
-- ----------------------------------------------------------------------------
INSERT INTO categories (id, name, slug, icon, description, parent_id, display_order, is_active) VALUES
(1,  'Vehicles',               'vehicles',           'car',           'Cars, motorcycles, trucks and machinery on wheels.', NULL, 1, 1),
(2,  'Phones & Electronics',    'phones-electronics', 'phone',         'Smartphones, tablets, audio, and accessories.',       NULL, 2, 1),
(3,  'Computers & Machines',    'computers-machines', 'laptop',       'Laptops, desktops, printers, and office equipment.',  NULL, 3, 1),
(4,  'Fashion',                 'fashion',            'shirt',         'Clothing, shoes, jewelry, and accessories.',          NULL, 4, 1),
(5,  'Homes & Land',            'homes-land',         'home',          'Houses, apartments, plots, and commercial property.', NULL, 5, 1),
(6,  'Furniture',               'furniture',          'sofa',          'Beds, sofas, tables, and office furniture.',           NULL, 6, 1),
(7,  'Construction Materials',  'construction',       'bricks',        'Cement, bricks, steel, paint, and tools.',             NULL, 7, 1),
(8,  'Agriculture & Livestock', 'agriculture',        'leaf',          'Seeds, livestock, equipment, and produce.',            NULL, 8, 1),
(9,  'Event Equipment',         'event-equipment',    'tent',          'Tents, chairs, sound systems, and décor.',              NULL, 9, 1),
(10, 'Services',                'services',           'wrench',        'Plumbing, electrical, catering, transport.',            NULL, 10, 1),
(11, 'Other Products',          'other-products',     'box',           'Anything that does not fit elsewhere.',                 NULL, 11, 1);

-- ----------------------------------------------------------------------------
-- SUBCATEGORIES  (sample — expandable)
-- ----------------------------------------------------------------------------
INSERT INTO subcategories (category_id, name, slug) VALUES
(1, 'Cars',         'cars'),
(1, 'Motorcycles',  'motorcycles'),
(1, 'Trucks',       'trucks'),
(2, 'Smartphones',  'smartphones'),
(2, 'Tablets',      'tablets'),
(2, 'Audio',        'audio'),
(3, 'Laptops',      'laptops'),
(3, 'Desktops',     'desktops'),
(3, 'Printers',     'printers'),
(5, 'Houses',       'houses'),
(5, 'Apartments',   'apartments'),
(5, 'Land',         'land'),
(5, 'Commercial',   'commercial'),
(6, 'Beds',         'beds'),
(6, 'Sofas',        'sofas'),
(6, 'Tables',       'tables'),
(10, 'Transport',   'transport'),
(10, 'Plumbing',    'plumbing'),
(10, 'Catering',    'catering'),
(10, 'Cleaning',    'cleaning');

-- ----------------------------------------------------------------------------
-- LISTINGS  (a varied demo set — sell + rent, multiple categories)
-- ----------------------------------------------------------------------------
INSERT INTO listings (id, seller_id, category_id, subcategory_id, title, slug, description, listing_type, price, currency, price_per_day, price_per_week, price_per_month, deposit, rental_terms, location, condition_state, availability, status, is_featured, views_count, favorites_count, created_at) VALUES
(1, 2, 1, 1,  'Toyota Corolla 2018',  'toyota-corolla-2018',  'Well-maintained Toyota Corolla 2018, single owner, full service history. Fuel-efficient and reliable.', 'sell',  9500000,  'RWF', NULL, NULL, NULL, NULL, NULL, 'Kigali, Kicukiro', 'used', 'available', 'active', 1, 1240, 86, NOW() - INTERVAL 2 DAY),
(2, 2, 1, 2,  'Honda CB 125 Motorcycle', 'honda-cb-125',     'Honda CB 125, low mileage, perfect for deliveries and commuting.', 'sell', 1450000, 'RWF', NULL, NULL, NULL, NULL, NULL, 'Kigali, Gasabo', 'used', 'available', 'active', 1, 640, 32, NOW() - INTERVAL 3 DAY),
(3, 2, 2, 4,  'iPhone 13 Pro 256GB',     'iphone-13-pro-256', 'iPhone 13 Pro 256GB, excellent condition, original box and charger included.', 'sell', 980000, 'RWF', NULL, NULL, NULL, NULL, NULL, 'Kigali, Nyarugenge', 'used', 'available', 'active', 1, 890, 71, NOW() - INTERVAL 1 DAY),
(4, 3, 5, 11, '3-Bedroom Apartment, Kigali', '3-bedroom-apartment-kigali', 'Modern 3-bedroom apartment in Kigali with parking, water, and electricity included.', 'rent', 0, 'RWF', 25000, 150000, 500000, 200000, 'One-month deposit, 3-month minimum lease.', 'Kigali, Kimironko', NULL, 'available', 'active', 1, 540, 42, NOW() - INTERVAL 4 DAY),
(5, 3, 5, 12, 'Residential Plot 600 sqm',    'residential-plot-600', '600 sqm residential plot with land title, ready to build.', 'sell', 18000000, 'RWF', NULL, NULL, NULL, NULL, NULL, 'Musanze', NULL, 'available', 'active', 0, 320, 18, NOW() - INTERVAL 6 DAY),
(6, 2, 3, 7,  'HP EliteBook 840 G7',         'hp-elitebook-840-g7',  'HP EliteBook 840 G7, Intel i7, 16GB RAM, 512GB SSD. Perfect for students and professionals.', 'sell', 720000, 'RWF', NULL, NULL, NULL, NULL, NULL, 'Kigali, Remera', 'used', 'available', 'active', 1, 470, 51, NOW() - INTERVAL 2 DAY),
(7, 3, 6, 14, 'Modern Sofa Set',               'modern-sofa-set',     'Three-seat modern sofa set, fabric, dark grey. Perfect for living rooms.', 'sell', 480000, 'RWF', NULL, NULL, NULL, NULL, NULL, 'Huye', 'new', 'available', 'active', 0, 210, 12, NOW() - INTERVAL 5 DAY),
(8, 2, 9, NULL,'Tent for Events (10x20m)',      'tent-events-10x20',   'Large event tent, 10x20 meters, suitable for weddings and conferences.', 'rent', 0, 'RWF', 35000, 200000, 700000, 100000, 'Damage deposit refundable on return.', 'Kigali, Gikondo', NULL, 'available', 'active', 1, 180, 22, NOW() - INTERVAL 3 DAY),
(9, 2, 8, NULL,'Irrigation Pump (Diesel)',     'irrigation-pump-diesel', 'Diesel irrigation pump, suitable for medium farms. Easy to maintain.', 'rent', 0, 'RWF', 12000, 70000, 250000, 50000, 'Renter pays for fuel and cleaning.', 'Musanze', 'used', 'available', 'active', 0, 95, 6, NOW() - INTERVAL 4 DAY),
(10,3, 10,17,'Airport Pickup Service',         'airport-pickup-service','Reliable airport pickup and drop-off in Kigali. Available 24/7.', 'sell', 15000, 'RWF', NULL, NULL, NULL, NULL, NULL, 'Kigali', NULL, 'available', 'active', 0, 320, 15, NOW() - INTERVAL 7 DAY),
(11,2, 2, 5,  'iPad Air 2022 64GB',            'ipad-air-2022-64',    'iPad Air 2022, 64GB WiFi. Excellent condition, no scratches.', 'sell', 760000, 'RWF', NULL, NULL, NULL, NULL, NULL, 'Kigali, Nyarugenge', 'used', 'available', 'active', 0, 290, 24, NOW() - INTERVAL 1 DAY),
(12,3, 5, 10, '4-Bedroom House, Kigali',       '4-bedroom-house-kigali','Spacious 4-bedroom house with garden and garage. Good neighborhood.', 'rent', 0, 'RWF', 45000, 280000, 900000, 500000, 'Two-month deposit, 1-year lease.', 'Kigali, Kanombe', NULL, 'available', 'active', 1, 410, 35, NOW() - INTERVAL 2 DAY);

-- ----------------------------------------------------------------------------
-- LISTING IMAGES  (paths to real photographs in assets/images/real/)
--   Real African-relevant stock photos downloaded with proper attribution.
--   Each listing has one primary image.  See assets/images/real/ for sources.
-- ----------------------------------------------------------------------------
INSERT INTO listing_images (listing_id, image_path, is_primary, display_order) VALUES
(1,  'assets/images/real/vehicle.jpg', 1, 0),
(2,  'assets/images/real/motorcycle.jpg', 1, 0),
(3,  'assets/images/real/phone.jpg', 1, 0),
(4,  'assets/images/real/apartment.jpg', 1, 0),
(5,  'assets/images/real/land.jpg', 1, 0),
(6,  'assets/images/real/laptop.jpg', 1, 0),
(7,  'assets/images/real/sofa.jpg', 1, 0),
(8,  'assets/images/real/tent.jpg', 1, 0),
(9,  'assets/images/real/agriculture.jpg', 1, 0),
(10, 'assets/images/real/service.jpg', 1, 0),
(11, 'assets/images/real/tablet.jpg', 1, 0),
(12, 'assets/images/real/house.jpg', 1, 0);

-- ----------------------------------------------------------------------------
-- LISTING ATTRIBUTES  (category-specific fields)
-- ----------------------------------------------------------------------------
INSERT INTO listing_attributes (listing_id, attr_key, attr_value) VALUES
(1,'make','Toyota'),(1,'model','Corolla'),(1,'year','2018'),(1,'mileage','78000'),(1,'fuel','petrol'),(1,'transmission','automatic'),
(2,'make','Honda'),(2,'model','CB 125'),(2,'year','2020'),(2,'mileage','22000'),(2,'fuel','petrol'),
(3,'brand','Apple'),(3,'model','iPhone 13 Pro'),(3,'storage','256GB'),(3,'color','graphite'),
(4,'property_type','apartment'),(4,'bedrooms','3'),(4,'bathrooms','2'),(4,'area_sqm','145'),(4,'furnished','no'),
(5,'property_type','land'),(5,'area_sqm','600'),(5,'title_deed','yes'),
(6,'brand','HP'),(6,'model','EliteBook 840 G7'),(6,'cpu','Intel i7-10610U'),(6,'ram_gb','16'),(6,'storage_gb','512'),(6,'storage_type','SSD'),
(7,'material','fabric'),(7,'color','dark grey'),(7,'seats','3'),
(8,'size_m','10x20'),(8,'capacity','200 people'),
(9,'power_source','diesel'),(9,'brand','Honda'),
(12,'property_type','house'),(12,'bedrooms','4'),(12,'bathrooms','3'),(12,'area_sqm','280'),(12,'furnished','yes');

-- ----------------------------------------------------------------------------
-- FAVORITES  (sample)
-- ----------------------------------------------------------------------------
INSERT INTO favorites (user_id, listing_id) VALUES
(4, 1), (4, 3), (4, 4), (4, 6);

-- ----------------------------------------------------------------------------
-- BLOG POSTS
-- ----------------------------------------------------------------------------
INSERT INTO blog_posts (id, author_id, title, slug, excerpt, body, cover_path, category, tags, status, views_count, published_at, created_at) VALUES
(1, 1, 'How to Buy Safely on Isoko Ryacu',  'how-to-buy-safely',
    'A practical checklist to avoid scams and protect your money when buying online.',
    '## 1. Meet in public places\nAlways meet sellers in busy, well-lit public spaces such as malls or police-monitored meeting points.\n\n## 2. Inspect before paying\nNever send money before seeing and inspecting the product yourself.\n\n## 3. Use verified sellers\nLook for the verified badge on seller profiles.\n\n## 4. Keep records\nSave chat screenshots and receipts until the transaction is complete.',
    'assets/images/real/blog-safety.jpg', 'Buying', 'safety,tips,buying', 'published', 1200, NOW() - INTERVAL 10 DAY, NOW() - INTERVAL 10 DAY),
(2, 1, 'How to Sell Online Like a Pro',      'how-to-sell-online',
    'Take better photos, write clearer descriptions, and price your items competitively.',
    '## 1. Use good lighting\nNatural daylight is best. Avoid shadows on your product.\n\n## 2. Write honest descriptions\nMention both strengths and small defects — buyers trust honest sellers.\n\n## 3. Price fairly\nResearch similar listings before setting your price.\n\n## 4. Respond quickly\nReplies within an hour get 3x more deals.',
    'assets/images/real/blog-sell.jpg', 'Selling', 'tips,selling,photos', 'published', 980, NOW() - INTERVAL 9 DAY, NOW() - INTERVAL 9 DAY),
(3, 1, 'Renting Property in Rwanda: A Guide', 'renting-property-guide',
    'Everything you need to know about deposits, leases, and tenant rights in Rwanda.',
    '## 1. Understand deposits\nMost landlords ask for 1 to 2 months of rent as a deposit.\n\n## 2. Sign a written lease\nAlways insist on a written agreement — verbal leases are hard to enforce.\n\n## 3. Inspect the property\nTake photos when you move in so you are not blamed for old damage.\n\n## 4. Know your rights\nTenants in Rwanda are protected by the civil code — read it before signing.',
    'assets/images/real/blog-rent.jpg', 'Renting', 'renting,property,legal', 'published', 1450, NOW() - INTERVAL 8 DAY, NOW() - INTERVAL 8 DAY),
(4, 1, 'How to Avoid Scams',                  'avoiding-scams',
    'Five red flags that should make you walk away from a deal.',
    '## 1. Too good to be true\nIf a price is far below market value, it is probably a scam.\n\n## 2. Pressure to pay now\nScammers always push for immediate payment.\n\n## 3. Refuses to meet\nLegit sellers will meet in person.\n\n## 4. Asks for money before delivery\nNever send mobile money before inspecting the product.\n\n## 5. No verification\nCheck the seller profile, ratings, and listing history.',
    'assets/images/real/blog-scam.jpg', 'Safety', 'scams,security,safety', 'published', 1610, NOW() - INTERVAL 7 DAY, NOW() - INTERVAL 7 DAY),
(5, 1, 'Choosing the Right Used Laptop',    'choosing-used-laptop',
    'Specs that matter, specs that do not, and how to test before you pay.',
    '## 1. CPU and RAM matter most\nFor office work, an Intel i5 with 8GB RAM is the sweet spot.\n\n## 2. Storage type\nAn SSD will feel much faster than a hard drive, even with less capacity.\n\n## 3. Battery health\nAsk the seller for the battery cycle count and run time.\n\n## 4. Test before paying\nOpen several apps, play a video, check Wi-Fi and ports.',
    'assets/images/real/blog-laptop.jpg', 'Buying', 'laptops,tips,electronics', 'published', 760, NOW() - INTERVAL 6 DAY, NOW() - INTERVAL 6 DAY);

-- ----------------------------------------------------------------------------
-- MARKET INSIGHTS  (clearly DEMO data — labelled via period = 'demo')
-- ----------------------------------------------------------------------------
INSERT INTO market_insights (metric_key, metric_value, metric_count, period) VALUES
('top_searched_term', 'iPhone',         1240, 'demo'),
('top_searched_term', 'Toyota',          980,  'demo'),
('top_searched_term', 'House in Kigali', 870,  'demo'),
('top_searched_term', 'Used laptop',     720,  'demo'),
('top_searched_term', 'Plot of land',    540,  'demo'),
('most_active_category', 'Vehicles',            12, 'demo'),
('most_active_category', 'Homes & Land',         10, 'demo'),
('most_active_category', 'Phones & Electronics',  9, 'demo'),
('rental_trend', 'Apartments in Kigali rising', 15, 'demo'),
('avg_price_vehicles',  'RWF 7,500,000',  12, 'demo'),
('avg_price_laptops',   'RWF 650,000',     9, 'demo');

-- ----------------------------------------------------------------------------
-- SITE SETTINGS
-- ----------------------------------------------------------------------------
INSERT INTO site_settings (setting_key, setting_value, description) VALUES
('site_name',     'Isoko Ryacu', 'Public site name'),
('site_tagline',  'Discover Anything. Buy. Sell. Rent.', 'Homepage hero subtitle'),
('contact_email', 'support@isoko.rw', 'Public support email'),
('contact_phone', '+250 788 000 000', 'Public support phone'),
('default_currency', 'RWF', 'Default listing currency'),
('posts_per_page', '12', 'Listings per page on Explore'),
('allow_guest_contact', '1', 'Allow non-logged-in users to message sellers'),
('enable_dark_mode', '1', 'Show dark mode toggle in navbar');

-- ----------------------------------------------------------------------------
-- NOTIFICATIONS  (demo)
-- ----------------------------------------------------------------------------
INSERT INTO notifications (user_id, type, title, body, link, is_read) VALUES
(4, 'favorite', 'Listing favorited', 'Your saved iPhone 13 Pro had a price drop.', 'pages/listing-details.php?id=3', 0),
(4, 'system',   'Welcome to Isoko Ryacu', 'Complete your profile to get better recommendations.', 'pages/profile.php', 0),
(2, 'message',  'New contact request', 'Someone is interested in your Toyota Corolla.', 'pages/listing-details.php?id=1', 0);

-- End of seed.sql
-- ---------- END seed.sql ----------

-- ---------- BEGIN phase2_migrate.sql ----------
-- ============================================================================
--  ISOKO RYACU — Phase 2 Migration
--  -----------------------------------------------------------------------
--  Run AFTER schema.sql + seed.sql from Phase 1.
--  Adds support for:
--    - Translation keys on categories + subcategories (multi-language UI)
--    - Seller profiles (rating, bio, response rate, etc.)
--    - Recently-viewed listings
--    - More subcategories per the Phase 2 brief
--    - Listing location (country / province / district / area) — already in
--      the listings table as a free-text location, so we add structured columns
--  -----------------------------------------------------------------------
--  Safe to re-run: each statement is idempotent (uses IF NOT EXISTS / ON
--  DUPLICATE KEY UPDATE where possible).  No Phase 1 data is destroyed.
-- ============================================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- 1. Add translation keys to categories + subcategories
--    (the UI shows the translated label; the DB stores the English name +
--    a translation key so the app can localize category names per language)
-- ----------------------------------------------------------------------------
ALTER TABLE categories
    ADD COLUMN IF NOT EXISTS name_key VARCHAR(80) NULL AFTER slug;

ALTER TABLE subcategories
    ADD COLUMN IF NOT EXISTS name_key VARCHAR(80) NULL AFTER slug;

-- Backfill name_key for existing categories using their slug
UPDATE categories SET name_key = CONCAT('cat.', slug) WHERE name_key IS NULL;
UPDATE subcategories SET name_key = CONCAT('subcat.', slug) WHERE name_key IS NULL;

-- ----------------------------------------------------------------------------
-- 2. Add structured location columns to listings (Phase 1 used free-text only)
-- ----------------------------------------------------------------------------
ALTER TABLE listings
    ADD COLUMN IF NOT EXISTS country   VARCHAR(80) NULL AFTER location,
    ADD COLUMN IF NOT EXISTS province  VARCHAR(80) NULL AFTER country,
    ADD COLUMN IF NOT EXISTS district  VARCHAR(80) NULL AFTER province,
    ADD COLUMN IF NOT EXISTS area      VARCHAR(120) NULL AFTER district;

-- Backfill country for existing listings (all demo data is in Rwanda)
UPDATE listings SET country = 'Rwanda' WHERE country IS NULL;

-- Add indexes for fast filtering
CREATE INDEX IF NOT EXISTS idx_list_country  ON listings(country);
CREATE INDEX IF NOT EXISTS idx_list_province ON listings(province);
CREATE INDEX IF NOT EXISTS idx_list_district ON listings(district);

-- ----------------------------------------------------------------------------
-- 3. Seller profiles table
--    Extends the users table with seller-specific info (rating, response
--    rate, total sales, etc.).  A user is a seller if users.is_seller = 1
--    OR if they have a row in seller_profiles.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS seller_profiles (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL UNIQUE,
    business_name       VARCHAR(160)  NULL,
    business_description TEXT         NULL,
    response_rate       TINYINT UNSIGNED NULL DEFAULT 100,  -- 0-100 (%)
    response_time_hours INT UNSIGNED  NULL DEFAULT 1,
    rating_average      DECIMAL(3,2)  NULL DEFAULT 0.00,
    rating_count        INT UNSIGNED  NOT NULL DEFAULT 0,
    total_sales         INT UNSIGNED  NOT NULL DEFAULT 0,
    total_rentals       INT UNSIGNED  NOT NULL DEFAULT 0,
    verified_at         TIMESTAMP     NULL,
    social_facebook     VARCHAR(255)  NULL,
    social_twitter      VARCHAR(255)  NULL,
    social_instagram    VARCHAR(255)  NULL,
    social_whatsapp     VARCHAR(40)   NULL,
    created_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_sp_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 4. Recently viewed listings (per user — for logged-in users)
--    For guests we fall back to a cookie (no DB row needed).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS recently_viewed (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    listing_id    INT UNSIGNED NOT NULL,
    viewed_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rv_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    CONSTRAINT fk_rv_listing FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    UNIQUE KEY uq_rv (user_id, listing_id),
    INDEX idx_rv_user (user_id),
    INDEX idx_rv_viewed (viewed_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 5. Add new subcategories per the Phase 2 brief
--    (uses ON DUPLICATE KEY UPDATE so re-running won't error)
-- ----------------------------------------------------------------------------
INSERT INTO subcategories (category_id, name, slug, name_key) VALUES
-- Vehicles (category_id 1)
(1, 'Buses',         'buses',          'subcat.buses'),
(1, 'Spare Parts',   'spare-parts',    'subcat.spare_parts'),
-- Phones & Electronics (category_id 2)
(2, 'TVs',           'tvs',            'subcat.tvs'),
(2, 'Cameras',       'cameras',        'subcat.cameras'),
(2, 'Accessories',   'accessories',    'subcat.accessories'),
-- Computers & Machines (category_id 3) — adds Machines as subcats
(3, 'Agricultural Machines', 'agricultural-machines', 'subcat.agricultural_machines'),
(3, 'Construction Machines', 'construction-machines', 'subcat.construction_machines'),
(3, 'Industrial Machines',   'industrial-machines',   'subcat.industrial_machines'),
(3, 'Generators',             'generators',             'subcat.generators'),
(3, 'Other Equipment',        'other-equipment',        'subcat.other_equipment'),
-- Fashion (category_id 4) — Phase 1 had none, add full set
(4, 'Men',           'men',           'subcat.men'),
(4, 'Women',         'women',         'subcat.women'),
(4, 'Children',      'children',      'subcat.children'),
(4, 'Shoes',         'shoes',         'subcat.shoes'),
(4, 'Bags',          'bags',          'subcat.bags'),
(4, 'Accessories',   'fashion-accessories', 'subcat.fashion_accessories'),
-- Homes & Land (category_id 5) — add Offices
(5, 'Offices',       'offices',       'subcat.offices'),
-- Furniture & Home (category_id 6) — add Home Decor
(6, 'Home Decor',    'home-decor',    'subcat.home_decor'),
(6, 'Kitchen',       'kitchen',       'subcat.kitchen'),
-- Construction Materials (category_id 7) — Phase 1 had no subcats
(7, 'Cement',        'cement',        'subcat.cement'),
(7, 'Bricks',        'bricks',        'subcat.bricks'),
(7, 'Steel',         'steel',         'subcat.steel'),
(7, 'Paint',         'paint',         'subcat.paint'),
(7, 'Tools',         'tools',         'subcat.tools'),
-- Agriculture & Livestock (category_id 8) — Phase 1 had no subcats
(8, 'Seeds',         'seeds',         'subcat.seeds'),
(8, 'Livestock',     'livestock',     'subcat.livestock'),
(8, 'Produce',       'produce',       'subcat.produce'),
(8, 'Equipment',     'agri-equipment','subcat.agri_equipment'),
-- Event Equipment (category_id 9) — Phase 1 had no subcats
(9, 'Tents',         'tents',         'subcat.tents'),
(9, 'Chairs',        'chairs',        'subcat.chairs'),
(9, 'Sound Systems', 'sound-systems', 'subcat.sound_systems'),
(9, 'Décor',         'decor',         'subcat.decor'),
-- Services (category_id 10) — Phase 1 had transport, plumbing, catering, cleaning
(10, 'Electrical',   'electrical',    'subcat.electrical'),
(10, 'Cleaning',     'cleaning',      'subcat.cleaning'),
-- Other (category_id 11)
(11, 'Miscellaneous','miscellaneous', 'subcat.miscellaneous')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ----------------------------------------------------------------------------
-- 6. Backfill category name_keys for the 11 main categories
-- ----------------------------------------------------------------------------
UPDATE categories SET name_key = 'cat.vehicles'            WHERE slug = 'vehicles';
UPDATE categories SET name_key = 'cat.phones_electronics'  WHERE slug = 'phones-electronics';
UPDATE categories SET name_key = 'cat.computers_machines'  WHERE slug = 'computers-machines';
UPDATE categories SET name_key = 'cat.fashion'             WHERE slug = 'fashion';
UPDATE categories SET name_key = 'cat.homes_land'          WHERE slug = 'homes-land';
UPDATE categories SET name_key = 'cat.furniture_home'      WHERE slug = 'furniture';
UPDATE categories SET name_key = 'cat.agriculture_livestock' WHERE slug = 'agriculture';
UPDATE categories SET name_key = 'cat.construction_materials' WHERE slug = 'construction';
UPDATE categories SET name_key = 'cat.event_equipment'     WHERE slug = 'event-equipment';
UPDATE categories SET name_key = 'cat.services'            WHERE slug = 'services';
UPDATE categories SET name_key = 'cat.other'               WHERE slug = 'other-products';

-- ----------------------------------------------------------------------------
-- 7. Rename "Furniture" to "Furniture & Home" for clarity (Phase 2 brief)
-- ----------------------------------------------------------------------------
UPDATE categories SET name = 'Furniture & Home', description = 'Beds, sofas, tables, kitchen, and home decor.' WHERE slug = 'furniture';

-- ----------------------------------------------------------------------------
-- 8. Seed seller profiles for the existing demo sellers (users 2 and 3)
-- ----------------------------------------------------------------------------
INSERT INTO seller_profiles (user_id, business_name, business_description, response_rate, response_time_hours, rating_average, rating_count, total_sales, total_rentals, verified_at, social_whatsapp) VALUES
(2, 'Aline Electronics & Motors', 'Trusted Kigali seller of vehicles, phones, and electronics since 2021.', 95, 1, 4.80, 12, 18, 6, NOW() - INTERVAL 90 DAY, '+250788000002'),
(3, 'Eric Properties & Furniture', 'Huye-based property agent and furniture maker. Verified since 2020.', 88, 3, 4.65, 8, 9, 4, NOW() - INTERVAL 180 DAY, '+250788000003')
ON DUPLICATE KEY UPDATE business_name = VALUES(business_name);

-- ----------------------------------------------------------------------------
-- 9. Add a few demo reviews so the seller profile has ratings to show
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reviews (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reviewer_id     INT UNSIGNED NOT NULL,
    seller_id       INT UNSIGNED NOT NULL,
    listing_id      INT UNSIGNED NULL,
    rating          TINYINT       NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment         TEXT         NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rev_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_rev_seller   FOREIGN KEY (seller_id)   REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_rev_listing  FOREIGN KEY (listing_id)  REFERENCES listings(id) ON DELETE SET NULL,
    UNIQUE KEY uq_rev (reviewer_id, seller_id, listing_id),
    INDEX idx_rev_seller (seller_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- (reviews table already existed in Phase 1 schema — the IF NOT EXISTS above
--  is a no-op on a fresh DB.  Skip seed if already populated.)
INSERT IGNORE INTO reviews (reviewer_id, seller_id, listing_id, rating, comment, created_at) VALUES
(4, 2, 1, 5, 'Quick response, car was exactly as described. Recommended.', NOW() - INTERVAL 8 DAY),
(4, 2, 3, 4, 'iPhone was in great condition. Slight delay in delivery.',  NOW() - INTERVAL 5 DAY),
(4, 3, 4, 5, 'Apartment was clean and the landlord was professional.',   NOW() - INTERVAL 3 DAY);

-- ----------------------------------------------------------------------------
-- 10. Add some demo rental requests for the seller dashboard
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO rental_requests (listing_id, renter_id, start_date, end_date, deposit_amount, message, status, created_at) VALUES
(4, 4, CURDATE() + INTERVAL 7 DAY,  CURDATE() + INTERVAL 37 DAY, 200000, 'I would like to rent this apartment for one month. Please confirm availability.', 'pending',     NOW() - INTERVAL 2 DAY),
(8, 4, CURDATE() + INTERVAL 14 DAY, CURDATE() + INTERVAL 21 DAY, 100000, 'Need a tent for a wedding on these dates. Is delivery available?',                 'pending',     NOW() - INTERVAL 1 DAY),
(12, 4, CURDATE() + INTERVAL 30 DAY, CURDATE() + INTERVAL 365 DAY, 500000, 'Looking for a long-term rental for my family.',                                   'pending',     NOW() - INTERVAL 6 HOUR);

-- ----------------------------------------------------------------------------
-- 11. Add a demo report so the admin dashboard has something to moderate
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO reports (reporter_id, listing_id, reason, details, status, created_at) VALUES
(4, 3, 'Seems overpriced compared to market', 'The same phone is sold for 700,000 RWF elsewhere. Is this listing genuine?', 'open', NOW() - INTERVAL 1 DAY);

-- ============================================================================
--  End of Phase 2 migration
-- ============================================================================
-- ---------- END phase2_migrate.sql ----------

-- ---------- BEGIN seller_upgrade.sql ----------
-- ============================================================================
--  ISOKO RYACU — Seller Center Upgrade Migration (Phase 5)
--  =====================================================================
--  Adds inventory management columns to the existing listings table.
--  Idempotent (ADD COLUMN IF NOT EXISTS) — safe to re-run.
--  Does NOT modify or drop any existing data.
-- ============================================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- 1. Inventory columns on listings (sku, stock, low-stock threshold)
-- ----------------------------------------------------------------------------
ALTER TABLE listings
    ADD COLUMN IF NOT EXISTS sku                 VARCHAR(60)   NULL AFTER slug,
    ADD COLUMN IF NOT EXISTS stock_quantity      INT           NOT NULL DEFAULT 0 AFTER sku,
    ADD COLUMN IF NOT EXISTS reserved_quantity   INT           NOT NULL DEFAULT 0 AFTER stock_quantity,
    ADD COLUMN IF NOT EXISTS low_stock_threshold INT           NOT NULL DEFAULT 5 AFTER reserved_quantity,
    ADD COLUMN IF NOT EXISTS is_published        TINYINT(1)    NOT NULL DEFAULT 1 AFTER low_stock_threshold;

-- Index for low-stock queries
ALTER TABLE listings ADD INDEX IF NOT EXISTS idx_listings_stock (seller_id, stock_quantity, is_published);

-- For existing listings, set a default stock of 1 (single-item marketplace)
-- so they don't appear as out-of-stock.
UPDATE listings SET stock_quantity = GREATEST(stock_quantity, 1) WHERE stock_quantity = 0 AND listing_type = 'sell';

-- ----------------------------------------------------------------------------
-- 2. Store profile columns on seller_profiles (logo, banner, hours)
-- ----------------------------------------------------------------------------
ALTER TABLE seller_profiles
    ADD COLUMN IF NOT EXISTS store_logo       VARCHAR(255) NULL AFTER business_description,
    ADD COLUMN IF NOT EXISTS store_banner     VARCHAR(255) NULL AFTER store_logo,
    ADD COLUMN IF NOT EXISTS operating_hours  VARCHAR(255) NULL AFTER store_banner,
    ADD COLUMN IF NOT EXISTS is_store_active  TINYINT(1)   NOT NULL DEFAULT 1 AFTER operating_hours;

-- End of seller_upgrade.sql
-- ---------- END seller_upgrade.sql ----------

-- ---------- BEGIN product_form_upgrade.sql ----------
-- Isoko Ryacu — Product form compatibility migration
-- Safe to run after seller_upgrade.sql. No existing data is removed.
SET NAMES utf8mb4;
ALTER TABLE listings
    ADD COLUMN IF NOT EXISTS low_stock_threshold INT NOT NULL DEFAULT 5 AFTER reserved_quantity;
-- ---------- END product_form_upgrade.sql ----------

-- ---------- BEGIN product_management_upgrade.sql ----------
-- ISOKO RYACU — Product Management Upgrade
-- Safe to run after seller_upgrade.sql. Uses existing listings as the product entity.
SET NAMES utf8mb4;

ALTER TABLE listings
    ADD COLUMN IF NOT EXISTS published_at TIMESTAMP NULL AFTER is_published,
    ADD COLUMN IF NOT EXISTS archived_at TIMESTAMP NULL AFTER published_at;

ALTER TABLE listings
    MODIFY COLUMN status ENUM('pending','active','rejected','expired','draft','archived') NOT NULL DEFAULT 'pending';

CREATE INDEX IF NOT EXISTS idx_listings_catalog ON listings(status, is_published, availability, is_featured, created_at);
CREATE INDEX IF NOT EXISTS idx_listings_seller_state ON listings(seller_id, status, is_published, availability);

-- Existing active products remain visible. New products still require admin approval.
UPDATE listings SET is_published = 1, published_at = COALESCE(published_at, created_at)
WHERE status = 'active' AND is_published = 1 AND published_at IS NULL;
-- ---------- END product_management_upgrade.sql ----------

-- ---------- BEGIN phase3_migrate.sql ----------
-- ============================================================================
--  ISOKO RYACU — Phase 3 Migration
--  -----------------------------------------------------------------------
--  Adds:
--    - SUPER_ADMIN role (id=4)
--    - A secure super admin account (password is bcrypt-hashed)
--    - Sets the default registration role to USER (role_id=3, "USER")
--    - Adds an index for fast role lookups
--  -----------------------------------------------------------------------
--  Safe to re-run (idempotent).  No Phase 1 or Phase 2 data is destroyed.
-- ============================================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- 1. Add the SUPER_ADMIN role (id=4)
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO roles (id, name, description) VALUES
(4, 'SUPER_ADMIN', 'Full platform administration — single super admin account');

-- ----------------------------------------------------------------------------
-- 2. Create the SUPER_ADMIN account
--    Email: ethiennemugisha35@gmail.com
--    Password: password (bcrypt-hashed — never stored as plain text)
--
--    ⚠️  SECURITY — LOCAL DEVELOPMENT ONLY:
--    This is a well-known demo credential documented in plain text in this
--    repository. NEVER run this seed script against a database that will be
--    reachable from the internet (staging/production) without immediately
--    logging in and changing this password, or deleting/disabling this
--    account first. Anyone who reads this file knows the login.
-- ----------------------------------------------------------------------------
INSERT INTO users (id, role_id, full_name, email, password_hash, phone, location, bio, avatar_path, is_verified, is_seller, status)
VALUES (
    100,
    4,
    'Super Admin',
    'ethiennemugisha35@gmail.com',
    '$2y$10$up5zrOoUlz6T0i3MvNGtmOYrDD7CP9EVcubl3e5um0MYA2RslC7Pm',
    '+250 788 000 000',
    'Kigali, Rwanda',
    'Platform Super Administrator. Full access to all administrative functions.',
    NULL,
    1,
    0,
    'active'
)
ON DUPLICATE KEY UPDATE
    role_id       = 4,
    full_name     = 'Super Admin',
    password_hash = '$2y$10$up5zrOoUlz6T0i3MvNGtmOYrDD7CP9EVcubl3e5um0MYA2RslC7Pm',
    status        = 'active',
    is_verified   = 1;

-- ----------------------------------------------------------------------------
-- 3. Normalize existing role names for clarity (Phase 1 had lowercase names)
-- ----------------------------------------------------------------------------
UPDATE roles SET name = 'ADMIN',        description = 'Standard platform administrator'     WHERE id = 1;
UPDATE roles SET name = 'SELLER',       description = 'Can publish listings + act as buyer' WHERE id = 2;
UPDATE roles SET name = 'USER',         description = 'Standard user (buyer + seller)'      WHERE id = 3;
UPDATE roles SET name = 'SUPER_ADMIN',  description = 'Full platform administration'         WHERE id = 4;

-- ----------------------------------------------------------------------------
-- 4. Make sure no normal user can ever become admin through registration
--    (the application code enforces this — INSERT into users always uses
--    role_id = 3 = USER for new registrations)
--    This UPDATE is a one-time safety check that downgrades any orphan admin
--    accounts that might have been created during testing.
-- ----------------------------------------------------------------------------
-- ⚠️ Commented out by default to avoid changing existing data:
-- UPDATE users SET role_id = 3 WHERE role_id NOT IN (1, 4) AND email NOT IN ('admin@isoko.rw','ethiennemugisha35@gmail.com');

-- ----------------------------------------------------------------------------
-- 5. Helpful indexes for role-based queries
-- ----------------------------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_users_role_status ON users(role_id, status);

-- ============================================================================
--  Verification (run these manually to check):
--    SELECT id, name FROM roles;
--    SELECT id, role_id, full_name, email, status FROM users WHERE role_id = 4;
-- ============================================================================
-- ---------- END phase3_migrate.sql ----------

-- ---------- BEGIN phase4_migrate.sql ----------
-- ============================================================================
--  ISOKO RYACU — Phase 4 Migration (Smart Innovations)
--  -----------------------------------------------------------------------
--  Adds the LISTING VERIFICATION flag for the Trust & Verification system.
--
--  Safe to re-run (fully idempotent).  No Phase 1-3 data is touched.
--  The application ALSO auto-creates this column at runtime
--  (includes/smart_features.php :: listings_verified_ready()) so the
--  feature works even if this migration was not run manually.
-- ============================================================================

SET NAMES utf8mb4;

-- 1. Verified Listing flag (admin-controlled, default 0 = unverified)
ALTER TABLE listings
    ADD COLUMN IF NOT EXISTS is_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER is_featured;

-- 2. Fast lookups for verified listings
CREATE INDEX IF NOT EXISTS idx_list_verified ON listings(is_verified);

-- ============================================================================
--  Verification:
--    SHOW COLUMNS FROM listings LIKE 'is_verified';
-- ============================================================================
-- ---------- END phase4_migrate.sql ----------

-- ---------- BEGIN admin_migrate.sql ----------
-- ============================================================================
--  ISOKO RYACU — Admin upgrade migration (Phase 5: premium admin panel)
--  ------------------------------------------------------------------
--  Adds the system_logs table used by the admin System Logs page.
--
--  HOW TO APPLY (choose ONE of the two options):
--
--  Option A (phpMyAdmin / mysql CLI):
--      Import this file into your existing `isoko_ryacu` database:
--          mysql -u root -p isoko_ryacu < sql/admin_migrate.sql
--
--  Option B (automatic):
--      No action needed. The application creates this table automatically
--      (idempotent CREATE TABLE IF NOT EXISTS in includes/admin_log.php)
--      the first time an admin page or admin action runs.
--
--  SAFE: this file only creates ONE new table. It does not alter, drop or
--  modify any existing table, and it does not touch existing data.
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS system_logs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NULL,               -- admin who performed the action
    user_name       VARCHAR(120)  NULL,              -- denormalised name (survives user deletion)
    module          VARCHAR(40)   NOT NULL,          -- listings | users | categories | messages | reports | rentals | reviews | settings | auth | system
    action          VARCHAR(60)   NOT NULL,          -- e.g. listing_approved, user_suspended
    status          ENUM('success','error') NOT NULL DEFAULT 'success',
    details         VARCHAR(500)  NULL,              -- human-readable summary (no sensitive data)
    ip_address      VARCHAR(45)   NULL,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_log_created (created_at DESC),
    INDEX idx_log_module  (module),
    INDEX idx_log_user    (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- End of admin_migrate.sql
-- ---------- END admin_migrate.sql ----------

-- ---------- BEGIN marketplace_upgrade.sql ----------
-- ============================================================================
--  ISOKO RYACU — Marketplace Upgrade Migration (Phase 4)
--  =====================================================================
--  Adds the missing e-commerce infrastructure to the existing marketplace:
--    wallets, wallet_transactions, orders, order_items, payments,
--    withdrawals, withdrawal_methods, conversations, messages,
--    delivery_methods, delivery_tracking, addresses, pickup_points,
--    disputes, dispute_messages, refunds, audit_log.
--
--  DESIGN PRINCIPLES
--    - Idempotent (CREATE TABLE IF NOT EXISTS) — safe to re-run.
--    - Does NOT modify or drop any existing table.
--    - All monetary amounts stored as DECIMAL(14,2) for precision.
--    - Every balance-changing table has immutable transaction records.
--    - Foreign keys enforce referential integrity.
--    - Indexes on every column used in WHERE / JOIN / ORDER BY.
--
--  EXTERNAL DEPENDENCIES
--    Payment providers (Mobile Money, card, crypto) are NOT created here —
--    they are configured at runtime via site_settings and resolved through
--    the payment abstraction layer (includes/payment.php). No provider
--    credentials are hard-coded.
-- ============================================================================

SET NAMES utf8mb4;

-- ============================================================================
-- 1. WALLETS — one per user (created lazily on first transaction)
-- ============================================================================
CREATE TABLE IF NOT EXISTS wallets (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL UNIQUE,
    available_balance   DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    pending_balance     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    total_earnings      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    total_spending      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    currency            VARCHAR(8)    NOT NULL DEFAULT 'RWF',
    created_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_wallet_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 2. WALLET_TRANSACTIONS — immutable ledger of every balance change
--    Every deposit, payment, escrow hold, release, refund, withdrawal,
--    and adjustment creates a row here.  This is the source of truth for
--    the wallet balance — the `wallets` table is a denormalised cache that
--    MUST always equal the sum of these rows.
-- ============================================================================
CREATE TABLE IF NOT EXISTS wallet_transactions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wallet_id       INT UNSIGNED NOT NULL,
    type            ENUM('deposit','payment','escrow_hold','escrow_release','refund','withdrawal','adjustment') NOT NULL,
    amount          DECIMAL(14,2) NOT NULL,  -- positive = credit, negative = debit
    balance_after   DECIMAL(14,2) NOT NULL,  -- snapshot of available_balance after this tx
    currency        VARCHAR(8)    NOT NULL DEFAULT 'RWF',
    reference_type  VARCHAR(40)   NULL,      -- 'order', 'withdrawal', 'deposit', 'manual'
    reference_id    INT UNSIGNED  NULL,      -- id of the related order/withdrawal/etc
    description     VARCHAR(255)  NULL,
    created_by      INT UNSIGNED  NULL,      -- admin/user who initiated (NULL = system)
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wt_wallet FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE CASCADE,
    CONSTRAINT fk_wt_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_wt_wallet (wallet_id),
    INDEX idx_wt_type (type),
    INDEX idx_wt_reference (reference_type, reference_id),
    INDEX idx_wt_created (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 3. ADDRESSES — user shipping/pickup addresses
-- ============================================================================
CREATE TABLE IF NOT EXISTS addresses (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    label       VARCHAR(60)  NOT NULL DEFAULT 'Home',  -- Home, Work, etc.
    full_name   VARCHAR(120) NOT NULL,
    phone       VARCHAR(30)  NOT NULL,
    country     VARCHAR(60)  NOT NULL DEFAULT 'Rwanda',
    province    VARCHAR(60)  NULL,
    district    VARCHAR(60)  NULL,
    sector      VARCHAR(60)  NULL,
    street      VARCHAR(160) NULL,
    notes       VARCHAR(255) NULL,
    is_default  TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_addr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_addr_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 4. DELIVERY_METHODS — system-wide delivery options (configurable by admin)
-- ============================================================================
CREATE TABLE IF NOT EXISTS delivery_methods (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(40)  NOT NULL UNIQUE,   -- home_delivery, pickup_point, seller_pickup, express, scheduled
    name            VARCHAR(80)  NOT NULL,
    description     VARCHAR(255) NULL,
    base_fee        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    display_order   INT          NOT NULL DEFAULT 0,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dm_active (is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the default delivery methods (idempotent)
INSERT IGNORE INTO delivery_methods (code, name, description, base_fee, is_active, display_order) VALUES
('home_delivery',  'Home Delivery',    'Delivered to your address',                 2000, 1, 1),
('pickup_point',   'Pickup Point',     'Pick up from a nearby pickup point',         500, 1, 2),
('seller_pickup',  'Seller Pickup',    'Pick up directly from the seller',              0, 1, 3),
('express',        'Express Delivery', 'Same-day express delivery',                 5000, 1, 4),
('scheduled',      'Scheduled Delivery','Choose a delivery date and time',           2500, 1, 5);

-- ============================================================================
-- 5. PICKUP_POINTS — locations for pickup_point delivery method
-- ============================================================================
CREATE TABLE IF NOT EXISTS pickup_points (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    address     VARCHAR(255) NOT NULL,
    district    VARCHAR(60)  NULL,
    province    VARCHAR(60)  NULL,
    phone       VARCHAR(30)  NULL,
    latitude    DECIMAL(10,7) NULL,
    longitude   DECIMAL(10,7) NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pp_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 6. ORDERS — buyer purchase orders
-- ============================================================================
CREATE TABLE IF NOT EXISTS orders (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number        VARCHAR(20)   NOT NULL UNIQUE,   -- human-readable e.g. IR-20260910-0001
    buyer_id            INT UNSIGNED  NOT NULL,
    seller_id           INT UNSIGNED  NOT NULL,          -- seller of the (first) item; multi-seller orders split
    listing_id          INT UNSIGNED  NOT NULL,          -- one order = one listing (simpler model)
    delivery_method_id  INT UNSIGNED  NULL,
    delivery_address_id INT UNSIGNED  NULL,              -- copy of buyer's address (NULL for pickup)
    pickup_point_id     INT UNSIGNED  NULL,
    quantity            INT           NOT NULL DEFAULT 1,
    unit_price          DECIMAL(14,2) NOT NULL,          -- snapshot of listing price at purchase time
    subtotal            DECIMAL(14,2) NOT NULL,          -- unit_price * quantity
    delivery_fee        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    platform_fee        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    discount            DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    tax                 DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    grand_total         DECIMAL(14,2) NOT NULL,
    currency            VARCHAR(8)    NOT NULL DEFAULT 'RWF',
    status              ENUM('pending','confirmed','preparing','ready','shipped','out_for_delivery','delivered','completed','cancelled','disputed') NOT NULL DEFAULT 'pending',
    delivery_status     ENUM('pending','confirmed','preparing','ready','shipped','out_for_delivery','delivered','completed','cancelled') NOT NULL DEFAULT 'pending',
    payment_status      ENUM('unpaid','pending','paid','escrow_held','released','refunded','failed') NOT NULL DEFAULT 'unpaid',
    escrow_released     TINYINT(1)    NOT NULL DEFAULT 0,
    notes               TEXT          NULL,
    confirmed_at        TIMESTAMP     NULL,
    delivered_at        TIMESTAMP     NULL,
    completed_at        TIMESTAMP     NULL,
    cancelled_at        TIMESTAMP     NULL,
    created_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_buyer    FOREIGN KEY (buyer_id)           REFERENCES users(id)            ON DELETE CASCADE,
    CONSTRAINT fk_order_seller   FOREIGN KEY (seller_id)          REFERENCES users(id)            ON DELETE RESTRICT,
    CONSTRAINT fk_order_listing  FOREIGN KEY (listing_id)         REFERENCES listings(id)         ON DELETE RESTRICT,
    CONSTRAINT fk_order_dm       FOREIGN KEY (delivery_method_id) REFERENCES delivery_methods(id) ON DELETE SET NULL,
    CONSTRAINT fk_order_addr     FOREIGN KEY (delivery_address_id) REFERENCES addresses(id)       ON DELETE SET NULL,
    CONSTRAINT fk_order_pp       FOREIGN KEY (pickup_point_id)    REFERENCES pickup_points(id)    ON DELETE SET NULL,
    INDEX idx_order_buyer (buyer_id),
    INDEX idx_order_seller (seller_id),
    INDEX idx_order_listing (listing_id),
    INDEX idx_order_status (status),
    INDEX idx_order_payment (payment_status),
    INDEX idx_order_delivery (delivery_status),
    INDEX idx_order_created (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 7. PAYMENTS — every payment attempt (one order can have multiple if retried)
-- ============================================================================
CREATE TABLE IF NOT EXISTS payments (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_reference   VARCHAR(40)   NOT NULL UNIQUE,  -- internal reference e.g. PAY-20260910-0001
    order_id            INT UNSIGNED  NOT NULL,
    user_id             INT UNSIGNED  NOT NULL,          -- payer
    amount              DECIMAL(14,2) NOT NULL,
    currency            VARCHAR(8)    NOT NULL DEFAULT 'RWF',
    method              VARCHAR(40)   NOT NULL,          -- wallet, momo, card, bank, crypto
    provider            VARCHAR(40)   NOT NULL DEFAULT 'internal',  -- internal, mtn_momo, airtel_money, stripe, flutterwave, etc.
    provider_reference  VARCHAR(120)  NULL,              -- provider's transaction id (NULL until verified)
    status              ENUM('pending','processing','successful','failed','cancelled','refunded') NOT NULL DEFAULT 'pending',
    verified            TINYINT(1)    NOT NULL DEFAULT 0, -- 1 only after server-side verification
    verified_at         TIMESTAMP     NULL,
    failure_reason      VARCHAR(255)  NULL,
    metadata            TEXT          NULL,              -- JSON: provider-specific data
    created_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pay_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_pay_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    INDEX idx_pay_order (order_id),
    INDEX idx_pay_user (user_id),
    INDEX idx_pay_status (status),
    INDEX idx_pay_method (method),
    INDEX idx_pay_created (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 8. WITHDRAWAL_METHODS — user's saved withdrawal destinations
-- ============================================================================
CREATE TABLE IF NOT EXISTS withdrawal_methods (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    type        VARCHAR(20)  NOT NULL,          -- momo, bank, wallet
    label       VARCHAR(60)  NOT NULL,          -- "My MTN", "Bank of Kigali"
    -- Mobile Money fields
    phone       VARCHAR(30)  NULL,
    -- Bank fields
    bank_name   VARCHAR(80)  NULL,
    account_number VARCHAR(40) NULL,
    account_name   VARCHAR(120) NULL,
    -- Generic
    is_preferred TINYINT(1)  NOT NULL DEFAULT 0,
    is_verified  TINYINT(1)  NOT NULL DEFAULT 0,
    created_at   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_wm_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 9. WITHDRAWALS — seller withdrawal requests
-- ============================================================================
CREATE TABLE IF NOT EXISTS withdrawals (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    withdrawal_reference VARCHAR(30) NOT NULL UNIQUE,
    user_id             INT UNSIGNED NOT NULL,
    wallet_id           INT UNSIGNED NOT NULL,
    amount              DECIMAL(14,2) NOT NULL,
    fee                 DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    net_amount          DECIMAL(14,2) NOT NULL,
    currency            VARCHAR(8)    NOT NULL DEFAULT 'RWF',
    withdrawal_method_id INT UNSIGNED NOT NULL,
    status              ENUM('pending','processing','completed','rejected','failed') NOT NULL DEFAULT 'pending',
    provider_reference  VARCHAR(120)  NULL,
    admin_notes         VARCHAR(255)  NULL,
    processed_by        INT UNSIGNED  NULL,    -- admin who approved/rejected
    processed_at        TIMESTAMP     NULL,
    created_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_wd_user   FOREIGN KEY (user_id)              REFERENCES users(id)             ON DELETE CASCADE,
    CONSTRAINT fk_wd_wallet FOREIGN KEY (wallet_id)            REFERENCES wallets(id)          ON DELETE RESTRICT,
    CONSTRAINT fk_wd_method FOREIGN KEY (withdrawal_method_id) REFERENCES withdrawal_methods(id) ON DELETE RESTRICT,
    CONSTRAINT fk_wd_admin  FOREIGN KEY (processed_by)         REFERENCES users(id)            ON DELETE SET NULL,
    INDEX idx_wd_user (user_id),
    INDEX idx_wd_status (status),
    INDEX idx_wd_created (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 10. CONVERSATIONS — 1-to-1 buyer ↔ seller chat (optionally linked to order)
-- ============================================================================
CREATE TABLE IF NOT EXISTS conversations (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user1_id    INT UNSIGNED NOT NULL,   -- always the buyer
    user2_id    INT UNSIGNED NOT NULL,   -- always the seller
    listing_id  INT UNSIGNED NULL,       -- optional: conversation about a specific listing
    order_id    INT UNSIGNED NULL,       -- optional: conversation about a specific order
    last_message_at TIMESTAMP NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_conv_user1 FOREIGN KEY (user1_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_conv_user2 FOREIGN KEY (user2_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_conv_listing FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    CONSTRAINT fk_conv_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    UNIQUE KEY uq_conv (user1_id, user2_id, listing_id),  -- one conversation per buyer+seller+listing
    INDEX idx_conv_user1 (user1_id),
    INDEX idx_conv_user2 (user2_id),
    INDEX idx_conv_last (last_message_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 11. MESSAGES — individual chat messages
-- ============================================================================
CREATE TABLE IF NOT EXISTS messages (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    sender_id       INT UNSIGNED NOT NULL,
    body            TEXT NOT NULL,
    is_read         TINYINT(1)  NOT NULL DEFAULT 0,
    read_at         TIMESTAMP   NULL,
    created_at      TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_msg_conv   FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id)       REFERENCES users(id)          ON DELETE CASCADE,
    INDEX idx_msg_conv (conversation_id, created_at),
    INDEX idx_msg_unread (conversation_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 12. DELIVERY_TRACKING — per-order delivery status history
-- ============================================================================
CREATE TABLE IF NOT EXISTS delivery_tracking (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id    INT UNSIGNED NOT NULL,
    status      ENUM('pending','confirmed','preparing','ready','shipped','out_for_delivery','delivered','completed','cancelled') NOT NULL,
    note        VARCHAR(255) NULL,
    location    VARCHAR(160) NULL,
    created_by  INT UNSIGNED NULL,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dt_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_dt_user  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_dt_order (order_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 13. DISPUTES — buyer can open a dispute on an order
-- ============================================================================
CREATE TABLE IF NOT EXISTS disputes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dispute_number  VARCHAR(20)   NOT NULL UNIQUE,
    order_id        INT UNSIGNED  NOT NULL,
    opened_by       INT UNSIGNED  NOT NULL,    -- buyer
    against_user_id INT UNSIGNED  NOT NULL,    -- seller
    reason          VARCHAR(160)  NOT NULL,
    description     TEXT          NOT NULL,
    status          ENUM('open','under_review','waiting_buyer','waiting_seller','resolved','closed') NOT NULL DEFAULT 'open',
    resolution      ENUM('pending','refund_full','refund_partial','release_to_seller','split') NOT NULL DEFAULT 'pending',
    refund_amount   DECIMAL(14,2) NULL,
    admin_id        INT UNSIGNED  NULL,        -- admin handling the dispute
    resolved_at     TIMESTAMP     NULL,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_disp_order    FOREIGN KEY (order_id)        REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_disp_opener   FOREIGN KEY (opened_by)       REFERENCES users(id)  ON DELETE CASCADE,
    CONSTRAINT fk_disp_against  FOREIGN KEY (against_user_id) REFERENCES users(id)  ON DELETE CASCADE,
    CONSTRAINT fk_disp_admin    FOREIGN KEY (admin_id)        REFERENCES users(id)  ON DELETE SET NULL,
    INDEX idx_disp_order (order_id),
    INDEX idx_disp_status (status),
    INDEX idx_disp_created (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 14. DISPUTE_MESSAGES — communication within a dispute
-- ============================================================================
CREATE TABLE IF NOT EXISTS dispute_messages (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dispute_id  INT UNSIGNED NOT NULL,
    sender_id   INT UNSIGNED NOT NULL,
    body        TEXT NOT NULL,
    is_internal TINYINT(1) NOT NULL DEFAULT 0,  -- admin-only internal note
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dm_dispute FOREIGN KEY (dispute_id) REFERENCES disputes(id) ON DELETE CASCADE,
    CONSTRAINT fk_dm_sender  FOREIGN KEY (sender_id)  REFERENCES users(id)    ON DELETE CASCADE,
    INDEX idx_dm_dispute (dispute_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 15. REFUNDS — refund records (linked to payment + order)
-- ============================================================================
CREATE TABLE IF NOT EXISTS refunds (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    refund_reference VARCHAR(30)  NOT NULL UNIQUE,
    payment_id      INT UNSIGNED  NOT NULL,
    order_id        INT UNSIGNED  NOT NULL,
    amount          DECIMAL(14,2) NOT NULL,
    currency        VARCHAR(8)    NOT NULL DEFAULT 'RWF',
    reason          VARCHAR(255)  NOT NULL,
    status          ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    processed_by    INT UNSIGNED  NULL,
    dispute_id      INT UNSIGNED  NULL,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ref_pay    FOREIGN KEY (payment_id)   REFERENCES payments(id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_order  FOREIGN KEY (order_id)     REFERENCES orders(id)   ON DELETE CASCADE,
    CONSTRAINT fk_ref_admin  FOREIGN KEY (processed_by) REFERENCES users(id)   ON DELETE SET NULL,
    CONSTRAINT fk_ref_disp   FOREIGN KEY (dispute_id)   REFERENCES disputes(id) ON DELETE SET NULL,
    INDEX idx_ref_order (order_id),
    INDEX idx_ref_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 16. AUDIT_LOG — immutable trail of important actions
-- ============================================================================
CREATE TABLE IF NOT EXISTS audit_log (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,           -- who performed the action
    action      VARCHAR(60)  NOT NULL,       -- e.g. 'wallet.deposit', 'order.create', 'payment.verify'
    target_type VARCHAR(40)  NULL,           -- 'order', 'wallet', 'withdrawal', 'dispute'
    target_id   INT UNSIGNED NULL,
    metadata    TEXT          NULL,           -- JSON with action-specific data
    ip_address  VARCHAR(45)  NULL,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_action (action),
    INDEX idx_audit_target (target_type, target_id),
    INDEX idx_audit_created (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 17. CART — session-based cart (stored in DB so it survives across devices)
-- ============================================================================
CREATE TABLE IF NOT EXISTS cart_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    listing_id  INT UNSIGNED NOT NULL,
    quantity    INT          NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cart_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    CONSTRAINT fk_cart_listing FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    UNIQUE KEY uq_cart (user_id, listing_id),
    INDEX idx_cart_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- VERIFICATION — run these to confirm the migration applied correctly:
--   SELECT TABLE_NAME FROM information_schema.TABLES
--    WHERE TABLE_SCHEMA = 'isoko_ryacu' AND TABLE_NAME IN
--      ('wallets','wallet_transactions','orders','payments','withdrawals',
--       'conversations','messages','delivery_methods','delivery_tracking',
--       'addresses','pickup_points','disputes','refunds','audit_log','cart_items');
-- ============================================================================

-- End of marketplace_upgrade.sql
-- ---------- END marketplace_upgrade.sql ----------

-- ---------- BEGIN fix_roles_seed.sql ----------
-- ============================================================================
--  ISOKO RYACU — Idempotent roles seed / repair migration
--  ------------------------------------------------------------------
--  PURPOSE
--    Fixes the foreign-key violation reported on user registration:
--      SQLSTATE[23000]: Integrity constraint violation: 1452
--      Cannot add or update a child row: a foreign key constraint fails
--      (`isoko_ryacu`.`users`, CONSTRAINT `fk_users_role`
--       FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`))
--
--  ROOT CAUSE
--    includes/auth.php historically inserted new users with a hardcoded
--    `role_id = 3`.  When the `roles` table is empty (e.g. after re-importing
--    schema.sql without re-running seed.sql) or when only phase3_migrate.sql
--    has been applied, no row with id = 3 exists, so the INSERT violates the
--    fk_users_role constraint.
--
--  WHAT THIS MIGRATION DOES
--    1. Ensures the four canonical roles exist with their canonical IDs:
--         1  ADMIN        — standard platform administrator
--         2  SELLER       — can publish listings + act as buyer
--         3  USER         — standard user (buyer + seller)  ← default registration role
--         4  SUPER_ADMIN  — full platform administration
--       (These names match the uppercase names already used by
--        includes/auth.php::require_role() and pages/admin/users.php.)
--    2. Normalizes legacy lowercase role names ('admin', 'seller', 'buyer')
--       to the uppercase names the application expects, WITHOUT changing IDs.
--    3. Repairs any orphaned users.role_id values (caused by manual deletes)
--       by pointing them at the USER role (id = 3) — safe default that
--       preserves their ability to log in.
--
--  WHAT THIS MIGRATION DOES NOT DO
--    - Does NOT drop the roles table.
--    - Does NOT delete existing users, listings, images, or other data.
--    - Does NOT disable FOREIGN_KEY_CHECKS.
--    - Does NOT remove or weaken the fk_users_role constraint.
--    - Does NOT create duplicate roles (uses INSERT ... ON DUPLICATE KEY UPDATE).
--
--  SAFE TO RE-RUN
--    Every statement is idempotent. Running this file 1, 2, or N times
--    produces the exact same final state.
--
--  HOW TO APPLY
--    Option A — phpMyAdmin:  Import this file into the `isoko_ryacu` database.
--    Option B — mysql CLI:
--        mysql -u root -p isoko_ryacu < sql/fix_roles_seed.sql
-- ============================================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- 1. Ensure the four canonical roles exist with their canonical IDs.
--    ON DUPLICATE KEY UPDATE keeps the id stable and upgrades the name /
--    description to the canonical uppercase form used by the application.
--    This is safe whether the row already exists or not, and never creates
--    duplicates (the `name` column has a UNIQUE constraint, and we also pin
--    the primary key).
-- ----------------------------------------------------------------------------
INSERT INTO roles (id, name, description) VALUES
    (1, 'ADMIN',       'Standard platform administrator'),
    (2, 'SELLER',      'Can publish listings + act as buyer'),
    (3, 'USER',        'Standard user (buyer + seller)'),
    (4, 'SUPER_ADMIN', 'Full platform administration')
ON DUPLICATE KEY UPDATE
    name        = VALUES(name),
    description = VALUES(description);

-- ----------------------------------------------------------------------------
-- 2. Normalize any legacy lowercase role names that might still be present
--    in databases seeded before phase3_migrate.sql was applied.
--    (No-op if the names are already uppercase.)
--    We do NOT change the `id` — only the `name` and `description` columns.
-- ----------------------------------------------------------------------------
UPDATE roles SET name = 'ADMIN',       description = 'Standard platform administrator'     WHERE id = 1;
UPDATE roles SET name = 'SELLER',      description = 'Can publish listings + act as buyer' WHERE id = 2;
UPDATE roles SET name = 'USER',        description = 'Standard user (buyer + seller)'      WHERE id = 3;
UPDATE roles SET name = 'SUPER_ADMIN', description = 'Full platform administration'        WHERE id = 4;

-- ----------------------------------------------------------------------------
-- 3. Repair orphaned users (users.role_id pointing at a missing roles.id).
--    This can happen if a role was manually deleted. We point such users at
--    the USER role (id = 3), which is the safest default — they can still log
--    in, browse, favorite, and contact sellers. Admins can re-assign roles
--    from the admin panel afterwards.
--    Existing users with a valid role_id are NOT touched.
-- ----------------------------------------------------------------------------
UPDATE users
   SET role_id = 3
 WHERE role_id NOT IN (SELECT id FROM (SELECT id FROM roles) AS safe_roles);

-- ----------------------------------------------------------------------------
-- 4. Verification queries (safe to run, read-only):
--      SELECT id, name, description FROM roles ORDER BY id;
--      SELECT u.id, u.email, u.role_id, r.name AS role_name
--        FROM users u LEFT JOIN roles r ON r.id = u.role_id
--       WHERE r.id IS NULL;   -- should return zero orphaned rows
-- ----------------------------------------------------------------------------

-- End of fix_roles_seed.sql
-- ---------- END fix_roles_seed.sql ----------

-- ---------- BEGIN super_admin_permissions.sql ----------
-- ============================================================================
--  ISOKO RYACU — Super Admin Permissions Migration
--  =====================================================================
--  Adds fine-grained admin permissions + assistant super admin support.
--  Idempotent — safe to re-run.
-- ============================================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- 1. admin_permissions — per-user permission flags for admins/assistants
--    Each row stores a JSON array of permission keys for one user.
--    Super Admins implicitly have ALL permissions (no row needed).
--    Assistant Super Admins have a row with their granted permissions.
--    Normal Admins have a row with default admin permissions.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_permissions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL UNIQUE,
    is_assistant    TINYINT(1)   NOT NULL DEFAULT 0,
    permissions     TEXT         NOT NULL DEFAULT '[]',
    granted_by      INT UNSIGNED NULL,
    granted_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ap_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ap_grantor FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_ap_user (user_id),
    INDEX idx_ap_assistant (is_assistant)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 2. Add is_primary_super_admin flag to users table
--    Marks the original/primary super admin who cannot be removed.
--    Set to 1 for the first super admin account only.
-- ----------------------------------------------------------------------------
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_primary_super_admin TINYINT(1) NOT NULL DEFAULT 0;

-- Mark the existing super admin (ethiennemugisha35@gmail.com) as primary
UPDATE users SET is_primary_super_admin = 1
 WHERE email = 'ethiennemugisha35@gmail.com'
   AND is_primary_super_admin = 0;

-- End of super_admin_permissions.sql
-- ---------- END super_admin_permissions.sql ----------

-- ---------- BEGIN payment_provider_settings.sql ----------
-- Market-store payment provider configuration (admin-managed)
INSERT IGNORE INTO site_settings (setting_key, setting_value, description) VALUES
('payment_mtn_enabled','0','Enable MTN MoMo payments'),
('payment_mtn_environment','sandbox','MTN MoMo environment: sandbox or production'),
('payment_mtn_api_user','', 'Encrypted MTN API user'),
('payment_mtn_api_key','', 'Encrypted MTN API key'),
('payment_mtn_subscription_key','', 'Encrypted MTN subscription key'),
('payment_mtn_callback_url','', 'MTN callback URL'),
('payment_airtel_enabled','0','Enable Airtel Money payments'),
('payment_airtel_environment','sandbox','Airtel Money environment: sandbox or production'),
('payment_airtel_client_id','', 'Encrypted Airtel client ID'),
('payment_airtel_client_secret','', 'Encrypted Airtel client secret'),
('payment_airtel_callback_url','', 'Airtel callback URL'),
('payment_card_enabled','0','Enable card payments'),
('payment_card_provider','stripe','Card payment provider'),
('payment_card_publishable_key','', 'Card publishable key'),
('payment_card_secret_key','', 'Encrypted card secret key'),
('payment_card_webhook_secret','', 'Encrypted card webhook signing secret'),
('payment_card_callback_url','', 'Card webhook/callback URL'),
('payment_bank_enabled','0','Enable bank payment integration'),
('payment_bank_provider','', 'Bank payment provider name'),
('payment_bank_merchant_id','', 'Encrypted bank merchant ID'),
('payment_bank_api_key','', 'Encrypted bank API key'),
('payment_bank_api_secret','', 'Encrypted bank API secret'),
('payment_bank_callback_url','', 'Bank callback URL'),
('payment_crypto_enabled','0','Enable crypto payments'),
('payment_crypto_provider','', 'Crypto payment provider'),
('payment_crypto_api_key','', 'Encrypted crypto API key'),
('payment_crypto_api_secret','', 'Encrypted crypto API secret'),
('payment_crypto_webhook_secret','', 'Encrypted crypto webhook secret'),
('payment_crypto_callback_url','', 'Crypto callback URL'),
('payment_platform_fee_percent','5','Default marketplace platform/removal protection fee percentage');
-- ---------- END payment_provider_settings.sql ----------

-- ---------- BEGIN payment_methods_upgrade.sql ----------
-- ============================================================================
--  ISOKO RYACU — Payment Methods Upgrade Migration
--  Adds provider + verification status + masking columns to withdrawal_methods
--  Idempotent — safe to re-run.
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE withdrawal_methods
    ADD COLUMN IF NOT EXISTS provider            VARCHAR(40)  NULL AFTER type,
    ADD COLUMN IF NOT EXISTS verification_status ENUM('unverified','pending','verified','rejected','disabled') NOT NULL DEFAULT 'unverified' AFTER is_verified,
    ADD COLUMN IF NOT EXISTS status              ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER verification_status,
    ADD COLUMN IF NOT EXISTS country             VARCHAR(10)  NULL DEFAULT 'RW' AFTER status,
    ADD COLUMN IF NOT EXISTS metadata            TEXT         NULL AFTER country;

-- Migrate is_verified to verification_status
UPDATE withdrawal_methods SET verification_status = 'verified' WHERE is_verified = 1 AND verification_status = 'unverified';

-- Seed default payment providers in site_settings (admin-configurable)
INSERT IGNORE INTO site_settings (setting_key, setting_value, description) VALUES
('payment_momo_providers', '["MTN Mobile Money","Airtel Money"]', 'JSON list of enabled Mobile Money providers'),
('payment_momo_min_withdrawal', '1000', 'Minimum withdrawal amount in RWF'),
('payment_momo_max_withdrawal', '500000', 'Maximum withdrawal amount in RWF'),
('payment_momo_fee_percent', '1', 'Withdrawal fee percentage for Mobile Money'),
('payment_bank_min_withdrawal', '5000', 'Minimum withdrawal amount for bank transfers in RWF'),
('payment_bank_max_withdrawal', '5000000', 'Maximum withdrawal amount for bank transfers in RWF'),
('payment_bank_fee_percent', '0.5', 'Withdrawal fee percentage for bank transfers'),
('payment_crypto_enabled', '0', 'Whether crypto withdrawals are enabled (1=yes, 0=no)'),
('payment_crypto_providers', '[]', 'JSON list of enabled crypto providers/networks');

-- End of payment_methods_upgrade.sql
-- ---------- END payment_methods_upgrade.sql ----------

-- ---------- BEGIN user_presence_migration.sql ----------
-- Isoko Ryacu: real user presence + WhatsApp profile data
-- Run after schema.sql / existing migrations. Safe to re-run on MySQL 8+.
SET NAMES utf8mb4;
ALTER TABLE users ADD COLUMN IF NOT EXISTS whatsapp_number VARCHAR(40) NULL AFTER phone;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_seen_at DATETIME NULL AFTER updated_at;
CREATE INDEX IF NOT EXISTS idx_users_last_seen ON users(last_seen_at);
-- ---------- END user_presence_migration.sql ----------

-- ---------- BEGIN community_posts.sql ----------
-- Admin-managed community posts for Home page: events, announcements and notices.
CREATE TABLE IF NOT EXISTS community_posts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type ENUM('announcement','event','notice') NOT NULL DEFAULT 'announcement',
    title VARCHAR(180) NOT NULL,
    body TEXT NULL,
    event_date DATETIME NULL,
    location VARCHAR(180) NULL,
    cta_label VARCHAR(60) NULL,
    cta_url VARCHAR(500) NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_community_posts_home (is_published, display_order, event_date, created_at),
    CONSTRAINT fk_community_posts_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ---------- END community_posts.sql ----------

-- ---------- BEGIN youtube_videos.sql ----------
-- IsokoRyacu: admin-managed YouTube videos shown on the Home page.
CREATE TABLE IF NOT EXISTS youtube_videos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    description TEXT NULL,
    youtube_url VARCHAR(500) NOT NULL,
    youtube_video_id CHAR(11) NOT NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_youtube_published_order (is_published, display_order, created_at),
    CONSTRAINT fk_youtube_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ---------- END youtube_videos.sql ----------

-- ---------- BEGIN category_catalog_mega_upgrade.sql ----------
-- ISOKO RYACU — Mega Marketplace Category Catalog (72 categories / 497 subcategories)
-- Safe/idempotent: does not drop existing data.
-- Compatibility: older Market-store databases may not yet have the catalog translation keys.
-- Add them before the idempotent catalog inserts below.
SET NAMES utf8mb4;
ALTER TABLE categories ADD COLUMN IF NOT EXISTS name_key VARCHAR(80) NULL AFTER slug;
ALTER TABLE subcategories ADD COLUMN IF NOT EXISTS name_key VARCHAR(80) NULL AFTER slug;


INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Vehicles','vehicles','cat.vehicles','car','Marketplace category: Vehicles',NULL,1,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Motorcycles & Scooters','motorcycles-scooters','cat.motorcycles_scooters','bike','Marketplace category: Motorcycles & Scooters',NULL,2,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Vehicle Spare Parts','vehicle-spare-parts','cat.vehicle_spare_parts','settings','Marketplace category: Vehicle Spare Parts',NULL,3,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Phones & Tablets','phones-tablets','cat.phones_tablets','phone','Marketplace category: Phones & Tablets',NULL,4,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Computers & Laptops','computers-laptops','cat.computers_laptops','laptop','Marketplace category: Computers & Laptops',NULL,5,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Computer Accessories','computer-accessories','cat.computer_accessories','keyboard','Marketplace category: Computer Accessories',NULL,6,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('TV & Home Entertainment','tv-home-entertainment','cat.tv_home_entertainment','tv','Marketplace category: TV & Home Entertainment',NULL,7,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Cameras & Photography','cameras-photography','cat.cameras_photography','camera','Marketplace category: Cameras & Photography',NULL,8,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Audio & Speakers','audio-speakers','cat.audio_speakers','headphones','Marketplace category: Audio & Speakers',NULL,9,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Gaming','gaming','cat.gaming','gamepad','Marketplace category: Gaming',NULL,10,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Appliances','appliances','cat.appliances','plug','Marketplace category: Appliances',NULL,11,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Generators & Power','generators-power','cat.generators_power','zap','Marketplace category: Generators & Power',NULL,12,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Solar & Renewable Energy','solar-renewable-energy','cat.solar_renewable_energy','sun','Marketplace category: Solar & Renewable Energy',NULL,13,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Fashion - Men','fashion-men','cat.fashion_men','shirt','Marketplace category: Fashion - Men',NULL,14,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Fashion - Women','fashion-women','cat.fashion_women','shirt','Marketplace category: Fashion - Women',NULL,15,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Fashion - Kids','fashion-kids','cat.fashion_kids','shirt','Marketplace category: Fashion - Kids',NULL,16,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Shoes','shoes','cat.shoes','footprints','Marketplace category: Shoes',NULL,17,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Bags & Luggage','bags-luggage','cat.bags_luggage','briefcase','Marketplace category: Bags & Luggage',NULL,18,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Jewelry & Watches','jewelry-watches','cat.jewelry_watches','watch','Marketplace category: Jewelry & Watches',NULL,19,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Beauty & Personal Care','beauty-personal-care','cat.beauty_personal_care','sparkles','Marketplace category: Beauty & Personal Care',NULL,20,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Baby & Kids','baby-kids','cat.baby_kids','baby','Marketplace category: Baby & Kids',NULL,21,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Health & Wellness','health-wellness','cat.health_wellness','heart','Marketplace category: Health & Wellness',NULL,22,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Furniture','furniture','cat.furniture','sofa','Marketplace category: Furniture',NULL,23,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Home Decor','home-decor','cat.home_decor','home','Marketplace category: Home Decor',NULL,24,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Kitchen & Dining','kitchen-dining','cat.kitchen_dining','utensils','Marketplace category: Kitchen & Dining',NULL,25,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Home Appliances','home-appliances','cat.home_appliances','house','Marketplace category: Home Appliances',NULL,26,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Garden & Outdoor','garden-outdoor','cat.garden_outdoor','leaf','Marketplace category: Garden & Outdoor',NULL,27,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Building Materials','building-materials','cat.building_materials','bricks','Marketplace category: Building Materials',NULL,28,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Tools & Hardware','tools-hardware','cat.tools_hardware','wrench','Marketplace category: Tools & Hardware',NULL,29,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Plumbing','plumbing','cat.plumbing','droplets','Marketplace category: Plumbing',NULL,30,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Electrical Materials','electrical-materials','cat.electrical_materials','bolt','Marketplace category: Electrical Materials',NULL,31,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Paint & Finishing','paint-finishing','cat.paint_finishing','paintbrush','Marketplace category: Paint & Finishing',NULL,32,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Doors Windows & Roofing','doors-windows-roofing','cat.doors_windows_roofing','home','Marketplace category: Doors Windows & Roofing',NULL,33,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Land & Plots','land-plots','cat.land_plots','map','Marketplace category: Land & Plots',NULL,34,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Houses','houses','cat.houses','house','Marketplace category: Houses',NULL,35,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Apartments','apartments','cat.apartments','building','Marketplace category: Apartments',NULL,36,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Commercial Property','commercial-property','cat.commercial_property','building-2','Marketplace category: Commercial Property',NULL,37,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Office & Business Equipment','office-business-equipment','cat.office_business_equipment','briefcase','Marketplace category: Office & Business Equipment',NULL,38,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Agriculture','agriculture','cat.agriculture','sprout','Marketplace category: Agriculture',NULL,39,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Livestock','livestock','cat.livestock','cow','Marketplace category: Livestock',NULL,40,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Animal Feed','animal-feed','cat.animal_feed','wheat','Marketplace category: Animal Feed',NULL,41,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Seeds & Seedlings','seeds-seedlings','cat.seeds_seedlings','sprout','Marketplace category: Seeds & Seedlings',NULL,42,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Farm Tools & Equipment','farm-tools-equipment','cat.farm_tools_equipment','tractor','Marketplace category: Farm Tools & Equipment',NULL,43,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Fruits & Vegetables','fruits-vegetables','cat.fruits_vegetables','apple','Marketplace category: Fruits & Vegetables',NULL,44,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Food & Groceries','food-groceries','cat.food_groceries','shopping-basket','Marketplace category: Food & Groceries',NULL,45,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Drinks & Beverages','drinks-beverages','cat.drinks_beverages','cup-soda','Marketplace category: Drinks & Beverages',NULL,46,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Restaurants & Catering','restaurants-catering','cat.restaurants_catering','utensils','Marketplace category: Restaurants & Catering',NULL,47,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Office Supplies','office-supplies','cat.office_supplies','folder','Marketplace category: Office Supplies',NULL,48,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Books & Stationery','books-stationery','cat.books_stationery','book','Marketplace category: Books & Stationery',NULL,49,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('School Supplies','school-supplies','cat.school_supplies','graduation-cap','Marketplace category: School Supplies',NULL,50,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Sports & Fitness','sports-fitness','cat.sports_fitness','dumbbell','Marketplace category: Sports & Fitness',NULL,51,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Music Instruments','music-instruments','cat.music_instruments','music','Marketplace category: Music Instruments',NULL,52,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Toys & Games','toys-games','cat.toys_games','puzzle','Marketplace category: Toys & Games',NULL,53,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Pet Supplies','pet-supplies','cat.pet_supplies','paw','Marketplace category: Pet Supplies',NULL,54,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Event Equipment','event-equipment','cat.event_equipment','tent','Marketplace category: Event Equipment',NULL,55,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Wedding Supplies','wedding-supplies','cat.wedding_supplies','heart','Marketplace category: Wedding Supplies',NULL,56,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Photography & Media Services','photography-media-services','cat.photography_media_services','camera','Marketplace category: Photography & Media Services',NULL,57,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Transport Services','transport-services','cat.transport_services','truck','Marketplace category: Transport Services',NULL,58,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Delivery & Logistics','delivery-logistics','cat.delivery_logistics','package','Marketplace category: Delivery & Logistics',NULL,59,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Cleaning Services','cleaning-services','cat.cleaning_services','sparkles','Marketplace category: Cleaning Services',NULL,60,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Repair Services','repair-services','cat.repair_services','wrench','Marketplace category: Repair Services',NULL,61,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Construction Services','construction-services','cat.construction_services','hard-hat','Marketplace category: Construction Services',NULL,62,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Professional Services','professional-services','cat.professional_services','briefcase','Marketplace category: Professional Services',NULL,63,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Education & Training','education-training','cat.education_training','graduation-cap','Marketplace category: Education & Training',NULL,64,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('IT & Digital Services','it-digital-services','cat.it_digital_services','code','Marketplace category: IT & Digital Services',NULL,65,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Marketing & Creative Services','marketing-creative-services','cat.marketing_creative_services','megaphone','Marketplace category: Marketing & Creative Services',NULL,66,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Security Services','security-services','cat.security_services','shield','Marketplace category: Security Services',NULL,67,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Travel & Tourism','travel-tourism','cat.travel_tourism','plane','Marketplace category: Travel & Tourism',NULL,68,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Jobs & Opportunities','jobs-opportunities','cat.jobs_opportunities','users','Marketplace category: Jobs & Opportunities',NULL,69,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Industrial Equipment','industrial-equipment','cat.industrial_equipment','factory','Marketplace category: Industrial Equipment',NULL,70,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Business & Commercial','business-commercial','cat.business_commercial','store','Marketplace category: Business & Commercial',NULL,71,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;
INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('Other Products','other-products','cat.other_products','box','Marketplace category: Other Products',NULL,72,1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;

-- Vehicles
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Cars', 'cars', 'subcat.cars' FROM categories WHERE slug='vehicles' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'SUVs', 'suvs', 'subcat.suvs' FROM categories WHERE slug='vehicles' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Pickup Trucks', 'pickup-trucks', 'subcat.pickup-trucks' FROM categories WHERE slug='vehicles' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Trucks', 'trucks', 'subcat.trucks' FROM categories WHERE slug='vehicles' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Buses', 'buses', 'subcat.buses' FROM categories WHERE slug='vehicles' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Vans', 'vans', 'subcat.vans' FROM categories WHERE slug='vehicles' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Electric Cars', 'electric-cars', 'subcat.electric-cars' FROM categories WHERE slug='vehicles' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Car Accessories', 'car-accessories', 'subcat.car-accessories' FROM categories WHERE slug='vehicles' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Car Audio', 'car-audio', 'subcat.car-audio' FROM categories WHERE slug='vehicles' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Car Care', 'car-care', 'subcat.car-care' FROM categories WHERE slug='vehicles' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Commercial Vehicles', 'commercial-vehicles', 'subcat.commercial-vehicles' FROM categories WHERE slug='vehicles' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Motorcycles & Scooters
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Motorcycles', 'motorcycles', 'subcat.motorcycles' FROM categories WHERE slug='motorcycles-scooters' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Scooters', 'scooters', 'subcat.scooters' FROM categories WHERE slug='motorcycles-scooters' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Electric Motorcycles', 'electric-motorcycles', 'subcat.electric-motorcycles' FROM categories WHERE slug='motorcycles-scooters' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Motorcycle Helmets', 'motorcycle-helmets', 'subcat.motorcycle-helmets' FROM categories WHERE slug='motorcycles-scooters' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Motorcycle Accessories', 'motorcycle-accessories', 'subcat.motorcycle-accessories' FROM categories WHERE slug='motorcycles-scooters' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Vehicle Spare Parts
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Engine Parts', 'engine-parts', 'subcat.engine-parts' FROM categories WHERE slug='vehicle-spare-parts' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Body Parts', 'body-parts', 'subcat.body-parts' FROM categories WHERE slug='vehicle-spare-parts' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Tyres', 'tyres', 'subcat.tyres' FROM categories WHERE slug='vehicle-spare-parts' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Batteries', 'batteries', 'subcat.batteries' FROM categories WHERE slug='vehicle-spare-parts' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Brakes', 'brakes', 'subcat.brakes' FROM categories WHERE slug='vehicle-spare-parts' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Filters', 'filters', 'subcat.filters' FROM categories WHERE slug='vehicle-spare-parts' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Lights', 'lights', 'subcat.lights' FROM categories WHERE slug='vehicle-spare-parts' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Lubricants', 'lubricants', 'subcat.lubricants' FROM categories WHERE slug='vehicle-spare-parts' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Suspension Parts', 'suspension-parts', 'subcat.suspension-parts' FROM categories WHERE slug='vehicle-spare-parts' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Electrical Parts', 'electrical-parts', 'subcat.electrical-parts' FROM categories WHERE slug='vehicle-spare-parts' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Phones & Tablets
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Smartphones', 'smartphones', 'subcat.smartphones' FROM categories WHERE slug='phones-tablets' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Feature Phones', 'feature-phones', 'subcat.feature-phones' FROM categories WHERE slug='phones-tablets' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'iPhones', 'iphones', 'subcat.iphones' FROM categories WHERE slug='phones-tablets' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Android Phones', 'android-phones', 'subcat.android-phones' FROM categories WHERE slug='phones-tablets' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Tablets', 'tablets', 'subcat.tablets' FROM categories WHERE slug='phones-tablets' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Smart Watches', 'smart-watches', 'subcat.smart-watches' FROM categories WHERE slug='phones-tablets' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Phone Cases', 'phone-cases', 'subcat.phone-cases' FROM categories WHERE slug='phones-tablets' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Chargers', 'chargers', 'subcat.chargers' FROM categories WHERE slug='phones-tablets' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Power Banks', 'power-banks', 'subcat.power-banks' FROM categories WHERE slug='phones-tablets' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Screen Protectors', 'screen-protectors', 'subcat.screen-protectors' FROM categories WHERE slug='phones-tablets' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Computers & Laptops
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Laptops', 'laptops', 'subcat.laptops' FROM categories WHERE slug='computers-laptops' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Desktops', 'desktops', 'subcat.desktops' FROM categories WHERE slug='computers-laptops' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'All-in-One PCs', 'all-in-one-pcs', 'subcat.all-in-one-pcs' FROM categories WHERE slug='computers-laptops' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'MacBooks', 'macbooks', 'subcat.macbooks' FROM categories WHERE slug='computers-laptops' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Monitors', 'monitors', 'subcat.monitors' FROM categories WHERE slug='computers-laptops' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Servers', 'servers', 'subcat.servers' FROM categories WHERE slug='computers-laptops' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Mini PCs', 'mini-pcs', 'subcat.mini-pcs' FROM categories WHERE slug='computers-laptops' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Workstations', 'workstations', 'subcat.workstations' FROM categories WHERE slug='computers-laptops' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Computer Accessories
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Keyboards', 'keyboards', 'subcat.keyboards' FROM categories WHERE slug='computer-accessories' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Mice', 'mice', 'subcat.mice' FROM categories WHERE slug='computer-accessories' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Printers', 'printers', 'subcat.printers' FROM categories WHERE slug='computer-accessories' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Scanners', 'scanners', 'subcat.scanners' FROM categories WHERE slug='computer-accessories' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Webcams', 'webcams', 'subcat.webcams' FROM categories WHERE slug='computer-accessories' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'USB Devices', 'usb-devices', 'subcat.usb-devices' FROM categories WHERE slug='computer-accessories' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Hard Drives', 'hard-drives', 'subcat.hard-drives' FROM categories WHERE slug='computer-accessories' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'SSDs', 'ssds', 'subcat.ssds' FROM categories WHERE slug='computer-accessories' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'RAM', 'ram', 'subcat.ram' FROM categories WHERE slug='computer-accessories' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Routers', 'routers', 'subcat.routers' FROM categories WHERE slug='computer-accessories' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Networking', 'networking', 'subcat.networking' FROM categories WHERE slug='computer-accessories' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- TV & Home Entertainment
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Televisions', 'televisions', 'subcat.televisions' FROM categories WHERE slug='tv-home-entertainment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Smart TVs', 'smart-tvs', 'subcat.smart-tvs' FROM categories WHERE slug='tv-home-entertainment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Projectors', 'projectors', 'subcat.projectors' FROM categories WHERE slug='tv-home-entertainment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Streaming Devices', 'streaming-devices', 'subcat.streaming-devices' FROM categories WHERE slug='tv-home-entertainment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'DVD Players', 'dvd-players', 'subcat.dvd-players' FROM categories WHERE slug='tv-home-entertainment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'TV Mounts', 'tv-mounts', 'subcat.tv-mounts' FROM categories WHERE slug='tv-home-entertainment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Cameras & Photography
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Digital Cameras', 'digital-cameras', 'subcat.digital-cameras' FROM categories WHERE slug='cameras-photography' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'DSLR Cameras', 'dslr-cameras', 'subcat.dslr-cameras' FROM categories WHERE slug='cameras-photography' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Mirrorless Cameras', 'mirrorless-cameras', 'subcat.mirrorless-cameras' FROM categories WHERE slug='cameras-photography' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Lenses', 'lenses', 'subcat.lenses' FROM categories WHERE slug='cameras-photography' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Tripods', 'tripods', 'subcat.tripods' FROM categories WHERE slug='cameras-photography' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Camera Bags', 'camera-bags', 'subcat.camera-bags' FROM categories WHERE slug='cameras-photography' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Drones', 'drones', 'subcat.drones' FROM categories WHERE slug='cameras-photography' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Lighting', 'lighting', 'subcat.lighting' FROM categories WHERE slug='cameras-photography' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Audio & Speakers
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Bluetooth Speakers', 'bluetooth-speakers', 'subcat.bluetooth-speakers' FROM categories WHERE slug='audio-speakers' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Home Theater', 'home-theater', 'subcat.home-theater' FROM categories WHERE slug='audio-speakers' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Soundbars', 'soundbars', 'subcat.soundbars' FROM categories WHERE slug='audio-speakers' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Headphones', 'headphones', 'subcat.headphones' FROM categories WHERE slug='audio-speakers' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Earbuds', 'earbuds', 'subcat.earbuds' FROM categories WHERE slug='audio-speakers' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Microphones', 'microphones', 'subcat.microphones' FROM categories WHERE slug='audio-speakers' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Amplifiers', 'amplifiers', 'subcat.amplifiers' FROM categories WHERE slug='audio-speakers' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Mixers', 'mixers', 'subcat.mixers' FROM categories WHERE slug='audio-speakers' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Gaming
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'PlayStation', 'playstation', 'subcat.playstation' FROM categories WHERE slug='gaming' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Xbox', 'xbox', 'subcat.xbox' FROM categories WHERE slug='gaming' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Nintendo', 'nintendo', 'subcat.nintendo' FROM categories WHERE slug='gaming' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Gaming PCs', 'gaming-pcs', 'subcat.gaming-pcs' FROM categories WHERE slug='gaming' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Gaming Monitors', 'gaming-monitors', 'subcat.gaming-monitors' FROM categories WHERE slug='gaming' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Controllers', 'controllers', 'subcat.controllers' FROM categories WHERE slug='gaming' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Gaming Chairs', 'gaming-chairs', 'subcat.gaming-chairs' FROM categories WHERE slug='gaming' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Gaming Accessories', 'gaming-accessories', 'subcat.gaming-accessories' FROM categories WHERE slug='gaming' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Appliances
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Refrigerators', 'refrigerators', 'subcat.refrigerators' FROM categories WHERE slug='appliances' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Freezers', 'freezers', 'subcat.freezers' FROM categories WHERE slug='appliances' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Washing Machines', 'washing-machines', 'subcat.washing-machines' FROM categories WHERE slug='appliances' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Cookers', 'cookers', 'subcat.cookers' FROM categories WHERE slug='appliances' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Microwaves', 'microwaves', 'subcat.microwaves' FROM categories WHERE slug='appliances' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Blenders', 'blenders', 'subcat.blenders' FROM categories WHERE slug='appliances' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Irons', 'irons', 'subcat.irons' FROM categories WHERE slug='appliances' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Vacuum Cleaners', 'vacuum-cleaners', 'subcat.vacuum-cleaners' FROM categories WHERE slug='appliances' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Generators & Power
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Generators', 'generators', 'subcat.generators' FROM categories WHERE slug='generators-power' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Inverters', 'inverters', 'subcat.inverters' FROM categories WHERE slug='generators-power' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Batteries', 'batteries', 'subcat.batteries' FROM categories WHERE slug='generators-power' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'UPS', 'ups', 'subcat.ups' FROM categories WHERE slug='generators-power' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Voltage Stabilizers', 'voltage-stabilizers', 'subcat.voltage-stabilizers' FROM categories WHERE slug='generators-power' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Power Cables', 'power-cables', 'subcat.power-cables' FROM categories WHERE slug='generators-power' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Solar & Renewable Energy
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Solar Panels', 'solar-panels', 'subcat.solar-panels' FROM categories WHERE slug='solar-renewable-energy' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Solar Batteries', 'solar-batteries', 'subcat.solar-batteries' FROM categories WHERE slug='solar-renewable-energy' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Solar Inverters', 'solar-inverters', 'subcat.solar-inverters' FROM categories WHERE slug='solar-renewable-energy' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Solar Lights', 'solar-lights', 'subcat.solar-lights' FROM categories WHERE slug='solar-renewable-energy' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Charge Controllers', 'charge-controllers', 'subcat.charge-controllers' FROM categories WHERE slug='solar-renewable-energy' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Solar Water Heaters', 'solar-water-heaters', 'subcat.solar-water-heaters' FROM categories WHERE slug='solar-renewable-energy' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Fashion - Men
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Shirts', 'shirts', 'subcat.shirts' FROM categories WHERE slug='fashion-men' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'T-Shirts', 't-shirts', 'subcat.t-shirts' FROM categories WHERE slug='fashion-men' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Trousers', 'trousers', 'subcat.trousers' FROM categories WHERE slug='fashion-men' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Suits', 'suits', 'subcat.suits' FROM categories WHERE slug='fashion-men' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Jackets', 'jackets', 'subcat.jackets' FROM categories WHERE slug='fashion-men' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Underwear', 'underwear', 'subcat.underwear' FROM categories WHERE slug='fashion-men' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Traditional Wear', 'traditional-wear', 'subcat.traditional-wear' FROM categories WHERE slug='fashion-men' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Accessories', 'accessories', 'subcat.accessories' FROM categories WHERE slug='fashion-men' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Fashion - Women
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Dresses', 'dresses', 'subcat.dresses' FROM categories WHERE slug='fashion-women' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Tops', 'tops', 'subcat.tops' FROM categories WHERE slug='fashion-women' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Skirts', 'skirts', 'subcat.skirts' FROM categories WHERE slug='fashion-women' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Trousers', 'trousers', 'subcat.trousers' FROM categories WHERE slug='fashion-women' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Jumpsuits', 'jumpsuits', 'subcat.jumpsuits' FROM categories WHERE slug='fashion-women' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Traditional Wear', 'traditional-wear', 'subcat.traditional-wear' FROM categories WHERE slug='fashion-women' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Handbags', 'handbags', 'subcat.handbags' FROM categories WHERE slug='fashion-women' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Accessories', 'accessories', 'subcat.accessories' FROM categories WHERE slug='fashion-women' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Fashion - Kids
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Boys Clothing', 'boys-clothing', 'subcat.boys-clothing' FROM categories WHERE slug='fashion-kids' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Girls Clothing', 'girls-clothing', 'subcat.girls-clothing' FROM categories WHERE slug='fashion-kids' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Baby Clothing', 'baby-clothing', 'subcat.baby-clothing' FROM categories WHERE slug='fashion-kids' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'School Uniforms', 'school-uniforms', 'subcat.school-uniforms' FROM categories WHERE slug='fashion-kids' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Kids Shoes', 'kids-shoes', 'subcat.kids-shoes' FROM categories WHERE slug='fashion-kids' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Kids Accessories', 'kids-accessories', 'subcat.kids-accessories' FROM categories WHERE slug='fashion-kids' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Shoes
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Men Shoes', 'men-shoes', 'subcat.men-shoes' FROM categories WHERE slug='shoes' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Women Shoes', 'women-shoes', 'subcat.women-shoes' FROM categories WHERE slug='shoes' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Kids Shoes', 'kids-shoes', 'subcat.kids-shoes' FROM categories WHERE slug='shoes' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Sneakers', 'sneakers', 'subcat.sneakers' FROM categories WHERE slug='shoes' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Boots', 'boots', 'subcat.boots' FROM categories WHERE slug='shoes' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Sandals', 'sandals', 'subcat.sandals' FROM categories WHERE slug='shoes' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Formal Shoes', 'formal-shoes', 'subcat.formal-shoes' FROM categories WHERE slug='shoes' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Sports Shoes', 'sports-shoes', 'subcat.sports-shoes' FROM categories WHERE slug='shoes' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Bags & Luggage
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Backpacks', 'backpacks', 'subcat.backpacks' FROM categories WHERE slug='bags-luggage' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Handbags', 'handbags', 'subcat.handbags' FROM categories WHERE slug='bags-luggage' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Travel Bags', 'travel-bags', 'subcat.travel-bags' FROM categories WHERE slug='bags-luggage' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Suitcases', 'suitcases', 'subcat.suitcases' FROM categories WHERE slug='bags-luggage' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Laptop Bags', 'laptop-bags', 'subcat.laptop-bags' FROM categories WHERE slug='bags-luggage' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'School Bags', 'school-bags', 'subcat.school-bags' FROM categories WHERE slug='bags-luggage' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Wallets', 'wallets', 'subcat.wallets' FROM categories WHERE slug='bags-luggage' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Jewelry & Watches
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Watches', 'watches', 'subcat.watches' FROM categories WHERE slug='jewelry-watches' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Rings', 'rings', 'subcat.rings' FROM categories WHERE slug='jewelry-watches' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Necklaces', 'necklaces', 'subcat.necklaces' FROM categories WHERE slug='jewelry-watches' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Bracelets', 'bracelets', 'subcat.bracelets' FROM categories WHERE slug='jewelry-watches' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Earrings', 'earrings', 'subcat.earrings' FROM categories WHERE slug='jewelry-watches' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Wedding Jewelry', 'wedding-jewelry', 'subcat.wedding-jewelry' FROM categories WHERE slug='jewelry-watches' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Smart Watches', 'smart-watches', 'subcat.smart-watches' FROM categories WHERE slug='jewelry-watches' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Beauty & Personal Care
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Skincare', 'skincare', 'subcat.skincare' FROM categories WHERE slug='beauty-personal-care' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Hair Care', 'hair-care', 'subcat.hair-care' FROM categories WHERE slug='beauty-personal-care' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Makeup', 'makeup', 'subcat.makeup' FROM categories WHERE slug='beauty-personal-care' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Perfumes', 'perfumes', 'subcat.perfumes' FROM categories WHERE slug='beauty-personal-care' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Body Care', 'body-care', 'subcat.body-care' FROM categories WHERE slug='beauty-personal-care' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Men Grooming', 'men-grooming', 'subcat.men-grooming' FROM categories WHERE slug='beauty-personal-care' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Salon Equipment', 'salon-equipment', 'subcat.salon-equipment' FROM categories WHERE slug='beauty-personal-care' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Personal Care Devices', 'personal-care-devices', 'subcat.personal-care-devices' FROM categories WHERE slug='beauty-personal-care' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Baby & Kids
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Baby Furniture', 'baby-furniture', 'subcat.baby-furniture' FROM categories WHERE slug='baby-kids' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Baby Feeding', 'baby-feeding', 'subcat.baby-feeding' FROM categories WHERE slug='baby-kids' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Baby Strollers', 'baby-strollers', 'subcat.baby-strollers' FROM categories WHERE slug='baby-kids' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Car Seats', 'car-seats', 'subcat.car-seats' FROM categories WHERE slug='baby-kids' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Diapers', 'diapers', 'subcat.diapers' FROM categories WHERE slug='baby-kids' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Baby Care', 'baby-care', 'subcat.baby-care' FROM categories WHERE slug='baby-kids' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Kids Accessories', 'kids-accessories', 'subcat.kids-accessories' FROM categories WHERE slug='baby-kids' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Health & Wellness
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Fitness Equipment', 'fitness-equipment', 'subcat.fitness-equipment' FROM categories WHERE slug='health-wellness' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Medical Equipment', 'medical-equipment', 'subcat.medical-equipment' FROM categories WHERE slug='health-wellness' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Wellness Products', 'wellness-products', 'subcat.wellness-products' FROM categories WHERE slug='health-wellness' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Mobility Aids', 'mobility-aids', 'subcat.mobility-aids' FROM categories WHERE slug='health-wellness' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Massage Equipment', 'massage-equipment', 'subcat.massage-equipment' FROM categories WHERE slug='health-wellness' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Furniture
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Sofas', 'sofas', 'subcat.sofas' FROM categories WHERE slug='furniture' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Beds', 'beds', 'subcat.beds' FROM categories WHERE slug='furniture' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Mattresses', 'mattresses', 'subcat.mattresses' FROM categories WHERE slug='furniture' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Wardrobes', 'wardrobes', 'subcat.wardrobes' FROM categories WHERE slug='furniture' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Tables', 'tables', 'subcat.tables' FROM categories WHERE slug='furniture' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Chairs', 'chairs', 'subcat.chairs' FROM categories WHERE slug='furniture' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Desks', 'desks', 'subcat.desks' FROM categories WHERE slug='furniture' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Shelves', 'shelves', 'subcat.shelves' FROM categories WHERE slug='furniture' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Office Furniture', 'office-furniture', 'subcat.office-furniture' FROM categories WHERE slug='furniture' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Home Decor
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Curtains', 'curtains', 'subcat.curtains' FROM categories WHERE slug='home-decor' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Rugs', 'rugs', 'subcat.rugs' FROM categories WHERE slug='home-decor' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Carpets', 'carpets', 'subcat.carpets' FROM categories WHERE slug='home-decor' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Wall Art', 'wall-art', 'subcat.wall-art' FROM categories WHERE slug='home-decor' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Mirrors', 'mirrors', 'subcat.mirrors' FROM categories WHERE slug='home-decor' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Lighting Decor', 'lighting-decor', 'subcat.lighting-decor' FROM categories WHERE slug='home-decor' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Cushions', 'cushions', 'subcat.cushions' FROM categories WHERE slug='home-decor' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Clocks', 'clocks', 'subcat.clocks' FROM categories WHERE slug='home-decor' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Kitchen & Dining
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Cookware', 'cookware', 'subcat.cookware' FROM categories WHERE slug='kitchen-dining' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Dinner Sets', 'dinner-sets', 'subcat.dinner-sets' FROM categories WHERE slug='kitchen-dining' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Cutlery', 'cutlery', 'subcat.cutlery' FROM categories WHERE slug='kitchen-dining' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Kitchen Tools', 'kitchen-tools', 'subcat.kitchen-tools' FROM categories WHERE slug='kitchen-dining' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Storage Containers', 'storage-containers', 'subcat.storage-containers' FROM categories WHERE slug='kitchen-dining' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Dining Tables', 'dining-tables', 'subcat.dining-tables' FROM categories WHERE slug='kitchen-dining' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Water Dispensers', 'water-dispensers', 'subcat.water-dispensers' FROM categories WHERE slug='kitchen-dining' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Home Appliances
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Fans', 'fans', 'subcat.fans' FROM categories WHERE slug='home-appliances' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Air Conditioners', 'air-conditioners', 'subcat.air-conditioners' FROM categories WHERE slug='home-appliances' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Water Heaters', 'water-heaters', 'subcat.water-heaters' FROM categories WHERE slug='home-appliances' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Kitchen Appliances', 'kitchen-appliances', 'subcat.kitchen-appliances' FROM categories WHERE slug='home-appliances' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Cleaning Appliances', 'cleaning-appliances', 'subcat.cleaning-appliances' FROM categories WHERE slug='home-appliances' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Garden & Outdoor
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Garden Tools', 'garden-tools', 'subcat.garden-tools' FROM categories WHERE slug='garden-outdoor' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Plants', 'plants', 'subcat.plants' FROM categories WHERE slug='garden-outdoor' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Outdoor Furniture', 'outdoor-furniture', 'subcat.outdoor-furniture' FROM categories WHERE slug='garden-outdoor' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'BBQ Equipment', 'bbq-equipment', 'subcat.bbq-equipment' FROM categories WHERE slug='garden-outdoor' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Camping Equipment', 'camping-equipment', 'subcat.camping-equipment' FROM categories WHERE slug='garden-outdoor' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Outdoor Lighting', 'outdoor-lighting', 'subcat.outdoor-lighting' FROM categories WHERE slug='garden-outdoor' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Building Materials
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Cement', 'cement', 'subcat.cement' FROM categories WHERE slug='building-materials' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Bricks', 'bricks', 'subcat.bricks' FROM categories WHERE slug='building-materials' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Blocks', 'blocks', 'subcat.blocks' FROM categories WHERE slug='building-materials' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Sand', 'sand', 'subcat.sand' FROM categories WHERE slug='building-materials' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Gravel', 'gravel', 'subcat.gravel' FROM categories WHERE slug='building-materials' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Steel', 'steel', 'subcat.steel' FROM categories WHERE slug='building-materials' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Timber', 'timber', 'subcat.timber' FROM categories WHERE slug='building-materials' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Tiles', 'tiles', 'subcat.tiles' FROM categories WHERE slug='building-materials' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Gypsum', 'gypsum', 'subcat.gypsum' FROM categories WHERE slug='building-materials' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Insulation', 'insulation', 'subcat.insulation' FROM categories WHERE slug='building-materials' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Tools & Hardware
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Hand Tools', 'hand-tools', 'subcat.hand-tools' FROM categories WHERE slug='tools-hardware' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Power Tools', 'power-tools', 'subcat.power-tools' FROM categories WHERE slug='tools-hardware' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Drills', 'drills', 'subcat.drills' FROM categories WHERE slug='tools-hardware' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Grinders', 'grinders', 'subcat.grinders' FROM categories WHERE slug='tools-hardware' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Welding Equipment', 'welding-equipment', 'subcat.welding-equipment' FROM categories WHERE slug='tools-hardware' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Ladders', 'ladders', 'subcat.ladders' FROM categories WHERE slug='tools-hardware' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Fasteners', 'fasteners', 'subcat.fasteners' FROM categories WHERE slug='tools-hardware' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Locks', 'locks', 'subcat.locks' FROM categories WHERE slug='tools-hardware' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Plumbing
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Pipes', 'pipes', 'subcat.pipes' FROM categories WHERE slug='plumbing' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Fittings', 'fittings', 'subcat.fittings' FROM categories WHERE slug='plumbing' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Taps', 'taps', 'subcat.taps' FROM categories WHERE slug='plumbing' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Showers', 'showers', 'subcat.showers' FROM categories WHERE slug='plumbing' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Toilets', 'toilets', 'subcat.toilets' FROM categories WHERE slug='plumbing' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Water Tanks', 'water-tanks', 'subcat.water-tanks' FROM categories WHERE slug='plumbing' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Pumps', 'pumps', 'subcat.pumps' FROM categories WHERE slug='plumbing' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Drainage', 'drainage', 'subcat.drainage' FROM categories WHERE slug='plumbing' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Electrical Materials
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Cables', 'cables', 'subcat.cables' FROM categories WHERE slug='electrical-materials' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Switches', 'switches', 'subcat.switches' FROM categories WHERE slug='electrical-materials' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Sockets', 'sockets', 'subcat.sockets' FROM categories WHERE slug='electrical-materials' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Breakers', 'breakers', 'subcat.breakers' FROM categories WHERE slug='electrical-materials' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Distribution Boards', 'distribution-boards', 'subcat.distribution-boards' FROM categories WHERE slug='electrical-materials' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Bulbs', 'bulbs', 'subcat.bulbs' FROM categories WHERE slug='electrical-materials' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Electrical Tools', 'electrical-tools', 'subcat.electrical-tools' FROM categories WHERE slug='electrical-materials' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Paint & Finishing
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Wall Paint', 'wall-paint', 'subcat.wall-paint' FROM categories WHERE slug='paint-finishing' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Wood Paint', 'wood-paint', 'subcat.wood-paint' FROM categories WHERE slug='paint-finishing' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Metal Paint', 'metal-paint', 'subcat.metal-paint' FROM categories WHERE slug='paint-finishing' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Primers', 'primers', 'subcat.primers' FROM categories WHERE slug='paint-finishing' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Varnish', 'varnish', 'subcat.varnish' FROM categories WHERE slug='paint-finishing' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Brushes', 'brushes', 'subcat.brushes' FROM categories WHERE slug='paint-finishing' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Rollers', 'rollers', 'subcat.rollers' FROM categories WHERE slug='paint-finishing' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Wallpaper', 'wallpaper', 'subcat.wallpaper' FROM categories WHERE slug='paint-finishing' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Doors Windows & Roofing
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Doors', 'doors', 'subcat.doors' FROM categories WHERE slug='doors-windows-roofing' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Windows', 'windows', 'subcat.windows' FROM categories WHERE slug='doors-windows-roofing' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Roofing Sheets', 'roofing-sheets', 'subcat.roofing-sheets' FROM categories WHERE slug='doors-windows-roofing' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Roof Tiles', 'roof-tiles', 'subcat.roof-tiles' FROM categories WHERE slug='doors-windows-roofing' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Gutters', 'gutters', 'subcat.gutters' FROM categories WHERE slug='doors-windows-roofing' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Ceiling Materials', 'ceiling-materials', 'subcat.ceiling-materials' FROM categories WHERE slug='doors-windows-roofing' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Door Hardware', 'door-hardware', 'subcat.door-hardware' FROM categories WHERE slug='doors-windows-roofing' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Land & Plots
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Residential Land', 'residential-land', 'subcat.residential-land' FROM categories WHERE slug='land-plots' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Commercial Land', 'commercial-land', 'subcat.commercial-land' FROM categories WHERE slug='land-plots' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Agricultural Land', 'agricultural-land', 'subcat.agricultural-land' FROM categories WHERE slug='land-plots' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Plots', 'plots', 'subcat.plots' FROM categories WHERE slug='land-plots' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Farm Land', 'farm-land', 'subcat.farm-land' FROM categories WHERE slug='land-plots' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Houses
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Houses for Sale', 'houses-for-sale', 'subcat.houses-for-sale' FROM categories WHERE slug='houses' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Houses for Rent', 'houses-for-rent', 'subcat.houses-for-rent' FROM categories WHERE slug='houses' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Villas', 'villas', 'subcat.villas' FROM categories WHERE slug='houses' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Family Homes', 'family-homes', 'subcat.family-homes' FROM categories WHERE slug='houses' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Student Housing', 'student-housing', 'subcat.student-housing' FROM categories WHERE slug='houses' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Apartments
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Apartments for Sale', 'apartments-for-sale', 'subcat.apartments-for-sale' FROM categories WHERE slug='apartments' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Apartments for Rent', 'apartments-for-rent', 'subcat.apartments-for-rent' FROM categories WHERE slug='apartments' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Furnished Apartments', 'furnished-apartments', 'subcat.furnished-apartments' FROM categories WHERE slug='apartments' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Studio Apartments', 'studio-apartments', 'subcat.studio-apartments' FROM categories WHERE slug='apartments' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Luxury Apartments', 'luxury-apartments', 'subcat.luxury-apartments' FROM categories WHERE slug='apartments' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Commercial Property
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Shops', 'shops', 'subcat.shops' FROM categories WHERE slug='commercial-property' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Offices', 'offices', 'subcat.offices' FROM categories WHERE slug='commercial-property' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Warehouses', 'warehouses', 'subcat.warehouses' FROM categories WHERE slug='commercial-property' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Hotels', 'hotels', 'subcat.hotels' FROM categories WHERE slug='commercial-property' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Restaurants', 'restaurants', 'subcat.restaurants' FROM categories WHERE slug='commercial-property' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Commercial Buildings', 'commercial-buildings', 'subcat.commercial-buildings' FROM categories WHERE slug='commercial-property' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Office & Business Equipment
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Office Desks', 'office-desks', 'subcat.office-desks' FROM categories WHERE slug='office-business-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Office Chairs', 'office-chairs', 'subcat.office-chairs' FROM categories WHERE slug='office-business-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Printers', 'printers', 'subcat.printers' FROM categories WHERE slug='office-business-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Photocopiers', 'photocopiers', 'subcat.photocopiers' FROM categories WHERE slug='office-business-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Safes', 'safes', 'subcat.safes' FROM categories WHERE slug='office-business-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'POS Equipment', 'pos-equipment', 'subcat.pos-equipment' FROM categories WHERE slug='office-business-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Shredders', 'shredders', 'subcat.shredders' FROM categories WHERE slug='office-business-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Agriculture
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Farm Produce', 'farm-produce', 'subcat.farm-produce' FROM categories WHERE slug='agriculture' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Farm Equipment', 'farm-equipment', 'subcat.farm-equipment' FROM categories WHERE slug='agriculture' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Irrigation', 'irrigation', 'subcat.irrigation' FROM categories WHERE slug='agriculture' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Greenhouse Equipment', 'greenhouse-equipment', 'subcat.greenhouse-equipment' FROM categories WHERE slug='agriculture' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Fertilizers', 'fertilizers', 'subcat.fertilizers' FROM categories WHERE slug='agriculture' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Pesticides', 'pesticides', 'subcat.pesticides' FROM categories WHERE slug='agriculture' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Farm Tools', 'farm-tools', 'subcat.farm-tools' FROM categories WHERE slug='agriculture' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Livestock
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Cattle', 'cattle', 'subcat.cattle' FROM categories WHERE slug='livestock' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Goats', 'goats', 'subcat.goats' FROM categories WHERE slug='livestock' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Sheep', 'sheep', 'subcat.sheep' FROM categories WHERE slug='livestock' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Pigs', 'pigs', 'subcat.pigs' FROM categories WHERE slug='livestock' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Chickens', 'chickens', 'subcat.chickens' FROM categories WHERE slug='livestock' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Rabbits', 'rabbits', 'subcat.rabbits' FROM categories WHERE slug='livestock' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Fish', 'fish', 'subcat.fish' FROM categories WHERE slug='livestock' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Bees', 'bees', 'subcat.bees' FROM categories WHERE slug='livestock' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Dogs', 'dogs', 'subcat.dogs' FROM categories WHERE slug='livestock' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Cats', 'cats', 'subcat.cats' FROM categories WHERE slug='livestock' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Animal Feed
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Cattle Feed', 'cattle-feed', 'subcat.cattle-feed' FROM categories WHERE slug='animal-feed' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Poultry Feed', 'poultry-feed', 'subcat.poultry-feed' FROM categories WHERE slug='animal-feed' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Pig Feed', 'pig-feed', 'subcat.pig-feed' FROM categories WHERE slug='animal-feed' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Goat Feed', 'goat-feed', 'subcat.goat-feed' FROM categories WHERE slug='animal-feed' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Fish Feed', 'fish-feed', 'subcat.fish-feed' FROM categories WHERE slug='animal-feed' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Mineral Supplements', 'mineral-supplements', 'subcat.mineral-supplements' FROM categories WHERE slug='animal-feed' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Seeds & Seedlings
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Maize Seeds', 'maize-seeds', 'subcat.maize-seeds' FROM categories WHERE slug='seeds-seedlings' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Beans Seeds', 'beans-seeds', 'subcat.beans-seeds' FROM categories WHERE slug='seeds-seedlings' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Vegetable Seeds', 'vegetable-seeds', 'subcat.vegetable-seeds' FROM categories WHERE slug='seeds-seedlings' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Fruit Seedlings', 'fruit-seedlings', 'subcat.fruit-seedlings' FROM categories WHERE slug='seeds-seedlings' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Tree Seedlings', 'tree-seedlings', 'subcat.tree-seedlings' FROM categories WHERE slug='seeds-seedlings' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Flower Seeds', 'flower-seeds', 'subcat.flower-seeds' FROM categories WHERE slug='seeds-seedlings' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Farm Tools & Equipment
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Tractors', 'tractors', 'subcat.tractors' FROM categories WHERE slug='farm-tools-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Ploughs', 'ploughs', 'subcat.ploughs' FROM categories WHERE slug='farm-tools-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Sprayers', 'sprayers', 'subcat.sprayers' FROM categories WHERE slug='farm-tools-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Irrigation Equipment', 'irrigation-equipment', 'subcat.irrigation-equipment' FROM categories WHERE slug='farm-tools-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Harvesting Tools', 'harvesting-tools', 'subcat.harvesting-tools' FROM categories WHERE slug='farm-tools-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Hand Tools', 'hand-tools', 'subcat.hand-tools' FROM categories WHERE slug='farm-tools-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Animal Equipment', 'animal-equipment', 'subcat.animal-equipment' FROM categories WHERE slug='farm-tools-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Fruits & Vegetables
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Fresh Fruits', 'fresh-fruits', 'subcat.fresh-fruits' FROM categories WHERE slug='fruits-vegetables' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Vegetables', 'vegetables', 'subcat.vegetables' FROM categories WHERE slug='fruits-vegetables' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Potatoes', 'potatoes', 'subcat.potatoes' FROM categories WHERE slug='fruits-vegetables' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Tomatoes', 'tomatoes', 'subcat.tomatoes' FROM categories WHERE slug='fruits-vegetables' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Onions', 'onions', 'subcat.onions' FROM categories WHERE slug='fruits-vegetables' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Bananas', 'bananas', 'subcat.bananas' FROM categories WHERE slug='fruits-vegetables' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Avocados', 'avocados', 'subcat.avocados' FROM categories WHERE slug='fruits-vegetables' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Food & Groceries
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Rice', 'rice', 'subcat.rice' FROM categories WHERE slug='food-groceries' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Flour', 'flour', 'subcat.flour' FROM categories WHERE slug='food-groceries' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Sugar', 'sugar', 'subcat.sugar' FROM categories WHERE slug='food-groceries' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Cooking Oil', 'cooking-oil', 'subcat.cooking-oil' FROM categories WHERE slug='food-groceries' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Beans', 'beans', 'subcat.beans' FROM categories WHERE slug='food-groceries' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Pasta', 'pasta', 'subcat.pasta' FROM categories WHERE slug='food-groceries' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Canned Foods', 'canned-foods', 'subcat.canned-foods' FROM categories WHERE slug='food-groceries' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Spices', 'spices', 'subcat.spices' FROM categories WHERE slug='food-groceries' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Snacks', 'snacks', 'subcat.snacks' FROM categories WHERE slug='food-groceries' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Drinks & Beverages
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Water', 'water', 'subcat.water' FROM categories WHERE slug='drinks-beverages' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Juices', 'juices', 'subcat.juices' FROM categories WHERE slug='drinks-beverages' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Soft Drinks', 'soft-drinks', 'subcat.soft-drinks' FROM categories WHERE slug='drinks-beverages' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Coffee', 'coffee', 'subcat.coffee' FROM categories WHERE slug='drinks-beverages' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Tea', 'tea', 'subcat.tea' FROM categories WHERE slug='drinks-beverages' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Energy Drinks', 'energy-drinks', 'subcat.energy-drinks' FROM categories WHERE slug='drinks-beverages' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Restaurants & Catering
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Catering', 'catering', 'subcat.catering' FROM categories WHERE slug='restaurants-catering' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Restaurant Services', 'restaurant-services', 'subcat.restaurant-services' FROM categories WHERE slug='restaurants-catering' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Wedding Catering', 'wedding-catering', 'subcat.wedding-catering' FROM categories WHERE slug='restaurants-catering' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Corporate Catering', 'corporate-catering', 'subcat.corporate-catering' FROM categories WHERE slug='restaurants-catering' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Bakery', 'bakery', 'subcat.bakery' FROM categories WHERE slug='restaurants-catering' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Fast Food', 'fast-food', 'subcat.fast-food' FROM categories WHERE slug='restaurants-catering' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Office Supplies
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Paper', 'paper', 'subcat.paper' FROM categories WHERE slug='office-supplies' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Pens', 'pens', 'subcat.pens' FROM categories WHERE slug='office-supplies' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Files', 'files', 'subcat.files' FROM categories WHERE slug='office-supplies' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Printer Supplies', 'printer-supplies', 'subcat.printer-supplies' FROM categories WHERE slug='office-supplies' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Desk Accessories', 'desk-accessories', 'subcat.desk-accessories' FROM categories WHERE slug='office-supplies' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Office Storage', 'office-storage', 'subcat.office-storage' FROM categories WHERE slug='office-supplies' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Books & Stationery
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Books', 'books', 'subcat.books' FROM categories WHERE slug='books-stationery' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Notebooks', 'notebooks', 'subcat.notebooks' FROM categories WHERE slug='books-stationery' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Pens', 'pens', 'subcat.pens' FROM categories WHERE slug='books-stationery' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Art Supplies', 'art-supplies', 'subcat.art-supplies' FROM categories WHERE slug='books-stationery' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Calculators', 'calculators', 'subcat.calculators' FROM categories WHERE slug='books-stationery' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Calendars', 'calendars', 'subcat.calendars' FROM categories WHERE slug='books-stationery' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Bibles & Religious Books', 'bibles-and-religious-books', 'subcat.bibles-and-religious-books' FROM categories WHERE slug='books-stationery' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- School Supplies
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'School Bags', 'school-bags', 'subcat.school-bags' FROM categories WHERE slug='school-supplies' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Uniforms', 'uniforms', 'subcat.uniforms' FROM categories WHERE slug='school-supplies' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Textbooks', 'textbooks', 'subcat.textbooks' FROM categories WHERE slug='school-supplies' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Stationery', 'stationery', 'subcat.stationery' FROM categories WHERE slug='school-supplies' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Shoes', 'shoes', 'subcat.shoes' FROM categories WHERE slug='school-supplies' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Learning Materials', 'learning-materials', 'subcat.learning-materials' FROM categories WHERE slug='school-supplies' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Sports & Fitness
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Gym Equipment', 'gym-equipment', 'subcat.gym-equipment' FROM categories WHERE slug='sports-fitness' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Football', 'football', 'subcat.football' FROM categories WHERE slug='sports-fitness' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Basketball', 'basketball', 'subcat.basketball' FROM categories WHERE slug='sports-fitness' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Volleyball', 'volleyball', 'subcat.volleyball' FROM categories WHERE slug='sports-fitness' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Running', 'running', 'subcat.running' FROM categories WHERE slug='sports-fitness' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Cycling', 'cycling', 'subcat.cycling' FROM categories WHERE slug='sports-fitness' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Boxing', 'boxing', 'subcat.boxing' FROM categories WHERE slug='sports-fitness' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Fitness Wear', 'fitness-wear', 'subcat.fitness-wear' FROM categories WHERE slug='sports-fitness' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Music Instruments
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Guitars', 'guitars', 'subcat.guitars' FROM categories WHERE slug='music-instruments' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Pianos', 'pianos', 'subcat.pianos' FROM categories WHERE slug='music-instruments' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Keyboards', 'keyboards', 'subcat.keyboards' FROM categories WHERE slug='music-instruments' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Drums', 'drums', 'subcat.drums' FROM categories WHERE slug='music-instruments' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Violins', 'violins', 'subcat.violins' FROM categories WHERE slug='music-instruments' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Brass Instruments', 'brass-instruments', 'subcat.brass-instruments' FROM categories WHERE slug='music-instruments' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'DJ Equipment', 'dj-equipment', 'subcat.dj-equipment' FROM categories WHERE slug='music-instruments' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Toys & Games
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Educational Toys', 'educational-toys', 'subcat.educational-toys' FROM categories WHERE slug='toys-games' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Outdoor Toys', 'outdoor-toys', 'subcat.outdoor-toys' FROM categories WHERE slug='toys-games' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Board Games', 'board-games', 'subcat.board-games' FROM categories WHERE slug='toys-games' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Video Games', 'video-games', 'subcat.video-games' FROM categories WHERE slug='toys-games' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Dolls', 'dolls', 'subcat.dolls' FROM categories WHERE slug='toys-games' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Remote Control Toys', 'remote-control-toys', 'subcat.remote-control-toys' FROM categories WHERE slug='toys-games' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Pet Supplies
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Dog Supplies', 'dog-supplies', 'subcat.dog-supplies' FROM categories WHERE slug='pet-supplies' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Cat Supplies', 'cat-supplies', 'subcat.cat-supplies' FROM categories WHERE slug='pet-supplies' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Bird Supplies', 'bird-supplies', 'subcat.bird-supplies' FROM categories WHERE slug='pet-supplies' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Aquarium Supplies', 'aquarium-supplies', 'subcat.aquarium-supplies' FROM categories WHERE slug='pet-supplies' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Pet Food', 'pet-food', 'subcat.pet-food' FROM categories WHERE slug='pet-supplies' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Pet Grooming', 'pet-grooming', 'subcat.pet-grooming' FROM categories WHERE slug='pet-supplies' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Event Equipment
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Tents', 'tents', 'subcat.tents' FROM categories WHERE slug='event-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Chairs', 'chairs', 'subcat.chairs' FROM categories WHERE slug='event-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Tables', 'tables', 'subcat.tables' FROM categories WHERE slug='event-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Sound Systems', 'sound-systems', 'subcat.sound-systems' FROM categories WHERE slug='event-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Lighting', 'lighting', 'subcat.lighting' FROM categories WHERE slug='event-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Decor', 'decor', 'subcat.decor' FROM categories WHERE slug='event-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Generators', 'generators', 'subcat.generators' FROM categories WHERE slug='event-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Stage Equipment', 'stage-equipment', 'subcat.stage-equipment' FROM categories WHERE slug='event-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Wedding Supplies
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Wedding Decor', 'wedding-decor', 'subcat.wedding-decor' FROM categories WHERE slug='wedding-supplies' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Wedding Dresses', 'wedding-dresses', 'subcat.wedding-dresses' FROM categories WHERE slug='wedding-supplies' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Suits', 'suits', 'subcat.suits' FROM categories WHERE slug='wedding-supplies' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Flowers', 'flowers', 'subcat.flowers' FROM categories WHERE slug='wedding-supplies' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Wedding Chairs', 'wedding-chairs', 'subcat.wedding-chairs' FROM categories WHERE slug='wedding-supplies' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Wedding Cakes', 'wedding-cakes', 'subcat.wedding-cakes' FROM categories WHERE slug='wedding-supplies' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Invitations', 'invitations', 'subcat.invitations' FROM categories WHERE slug='wedding-supplies' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Photography & Media Services
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Photography', 'photography', 'subcat.photography' FROM categories WHERE slug='photography-media-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Videography', 'videography', 'subcat.videography' FROM categories WHERE slug='photography-media-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Editing', 'editing', 'subcat.editing' FROM categories WHERE slug='photography-media-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Livestreaming', 'livestreaming', 'subcat.livestreaming' FROM categories WHERE slug='photography-media-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Photo Booth', 'photo-booth', 'subcat.photo-booth' FROM categories WHERE slug='photography-media-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Drone Services', 'drone-services', 'subcat.drone-services' FROM categories WHERE slug='photography-media-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Transport Services
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Car Hire', 'car-hire', 'subcat.car-hire' FROM categories WHERE slug='transport-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Taxi Services', 'taxi-services', 'subcat.taxi-services' FROM categories WHERE slug='transport-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Bus Hire', 'bus-hire', 'subcat.bus-hire' FROM categories WHERE slug='transport-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Motorcycle Transport', 'motorcycle-transport', 'subcat.motorcycle-transport' FROM categories WHERE slug='transport-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Moving Services', 'moving-services', 'subcat.moving-services' FROM categories WHERE slug='transport-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Delivery & Logistics
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Courier', 'courier', 'subcat.courier' FROM categories WHERE slug='delivery-logistics' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Parcel Delivery', 'parcel-delivery', 'subcat.parcel-delivery' FROM categories WHERE slug='delivery-logistics' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Moving Logistics', 'moving-logistics', 'subcat.moving-logistics' FROM categories WHERE slug='delivery-logistics' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Freight', 'freight', 'subcat.freight' FROM categories WHERE slug='delivery-logistics' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Warehouse Services', 'warehouse-services', 'subcat.warehouse-services' FROM categories WHERE slug='delivery-logistics' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Cleaning Services
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Home Cleaning', 'home-cleaning', 'subcat.home-cleaning' FROM categories WHERE slug='cleaning-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Office Cleaning', 'office-cleaning', 'subcat.office-cleaning' FROM categories WHERE slug='cleaning-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Deep Cleaning', 'deep-cleaning', 'subcat.deep-cleaning' FROM categories WHERE slug='cleaning-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Laundry', 'laundry', 'subcat.laundry' FROM categories WHERE slug='cleaning-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Car Cleaning', 'car-cleaning', 'subcat.car-cleaning' FROM categories WHERE slug='cleaning-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Fumigation', 'fumigation', 'subcat.fumigation' FROM categories WHERE slug='cleaning-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Repair Services
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Phone Repair', 'phone-repair', 'subcat.phone-repair' FROM categories WHERE slug='repair-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Computer Repair', 'computer-repair', 'subcat.computer-repair' FROM categories WHERE slug='repair-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Appliance Repair', 'appliance-repair', 'subcat.appliance-repair' FROM categories WHERE slug='repair-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Vehicle Repair', 'vehicle-repair', 'subcat.vehicle-repair' FROM categories WHERE slug='repair-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Shoe Repair', 'shoe-repair', 'subcat.shoe-repair' FROM categories WHERE slug='repair-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Furniture Repair', 'furniture-repair', 'subcat.furniture-repair' FROM categories WHERE slug='repair-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Construction Services
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Masonry', 'masonry', 'subcat.masonry' FROM categories WHERE slug='construction-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Carpentry', 'carpentry', 'subcat.carpentry' FROM categories WHERE slug='construction-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Roofing', 'roofing', 'subcat.roofing' FROM categories WHERE slug='construction-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Plumbing Services', 'plumbing-services', 'subcat.plumbing-services' FROM categories WHERE slug='construction-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Electrical Services', 'electrical-services', 'subcat.electrical-services' FROM categories WHERE slug='construction-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Painting', 'painting', 'subcat.painting' FROM categories WHERE slug='construction-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Tiling', 'tiling', 'subcat.tiling' FROM categories WHERE slug='construction-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Professional Services
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Accounting', 'accounting', 'subcat.accounting' FROM categories WHERE slug='professional-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Legal Services', 'legal-services', 'subcat.legal-services' FROM categories WHERE slug='professional-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Consulting', 'consulting', 'subcat.consulting' FROM categories WHERE slug='professional-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Architecture', 'architecture', 'subcat.architecture' FROM categories WHERE slug='professional-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Engineering', 'engineering', 'subcat.engineering' FROM categories WHERE slug='professional-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'HR Services', 'hr-services', 'subcat.hr-services' FROM categories WHERE slug='professional-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Education & Training
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Tutoring', 'tutoring', 'subcat.tutoring' FROM categories WHERE slug='education-training' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Language Training', 'language-training', 'subcat.language-training' FROM categories WHERE slug='education-training' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Computer Training', 'computer-training', 'subcat.computer-training' FROM categories WHERE slug='education-training' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Vocational Training', 'vocational-training', 'subcat.vocational-training' FROM categories WHERE slug='education-training' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Online Courses', 'online-courses', 'subcat.online-courses' FROM categories WHERE slug='education-training' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- IT & Digital Services
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Web Development', 'web-development', 'subcat.web-development' FROM categories WHERE slug='it-digital-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Mobile Apps', 'mobile-apps', 'subcat.mobile-apps' FROM categories WHERE slug='it-digital-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Graphic Design', 'graphic-design', 'subcat.graphic-design' FROM categories WHERE slug='it-digital-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'IT Support', 'it-support', 'subcat.it-support' FROM categories WHERE slug='it-digital-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Cloud Services', 'cloud-services', 'subcat.cloud-services' FROM categories WHERE slug='it-digital-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Cybersecurity', 'cybersecurity', 'subcat.cybersecurity' FROM categories WHERE slug='it-digital-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Marketing & Creative Services
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Digital Marketing', 'digital-marketing', 'subcat.digital-marketing' FROM categories WHERE slug='marketing-creative-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Social Media', 'social-media', 'subcat.social-media' FROM categories WHERE slug='marketing-creative-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Branding', 'branding', 'subcat.branding' FROM categories WHERE slug='marketing-creative-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Printing', 'printing', 'subcat.printing' FROM categories WHERE slug='marketing-creative-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Content Creation', 'content-creation', 'subcat.content-creation' FROM categories WHERE slug='marketing-creative-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Advertising', 'advertising', 'subcat.advertising' FROM categories WHERE slug='marketing-creative-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Security Services
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Security Guards', 'security-guards', 'subcat.security-guards' FROM categories WHERE slug='security-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'CCTV Installation', 'cctv-installation', 'subcat.cctv-installation' FROM categories WHERE slug='security-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Alarm Systems', 'alarm-systems', 'subcat.alarm-systems' FROM categories WHERE slug='security-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Access Control', 'access-control', 'subcat.access-control' FROM categories WHERE slug='security-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Security Equipment', 'security-equipment', 'subcat.security-equipment' FROM categories WHERE slug='security-services' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Travel & Tourism
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Hotels', 'hotels', 'subcat.hotels' FROM categories WHERE slug='travel-tourism' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Tour Packages', 'tour-packages', 'subcat.tour-packages' FROM categories WHERE slug='travel-tourism' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Car Rental', 'car-rental', 'subcat.car-rental' FROM categories WHERE slug='travel-tourism' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Tour Guides', 'tour-guides', 'subcat.tour-guides' FROM categories WHERE slug='travel-tourism' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Airport Transfers', 'airport-transfers', 'subcat.airport-transfers' FROM categories WHERE slug='travel-tourism' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Travel Services', 'travel-services', 'subcat.travel-services' FROM categories WHERE slug='travel-tourism' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Jobs & Opportunities
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Full Time Jobs', 'full-time-jobs', 'subcat.full-time-jobs' FROM categories WHERE slug='jobs-opportunities' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Part Time Jobs', 'part-time-jobs', 'subcat.part-time-jobs' FROM categories WHERE slug='jobs-opportunities' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Internships', 'internships', 'subcat.internships' FROM categories WHERE slug='jobs-opportunities' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Freelance', 'freelance', 'subcat.freelance' FROM categories WHERE slug='jobs-opportunities' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Domestic Work', 'domestic-work', 'subcat.domestic-work' FROM categories WHERE slug='jobs-opportunities' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Skilled Jobs', 'skilled-jobs', 'subcat.skilled-jobs' FROM categories WHERE slug='jobs-opportunities' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Industrial Equipment
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Industrial Machines', 'industrial-machines', 'subcat.industrial-machines' FROM categories WHERE slug='industrial-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Compressors', 'compressors', 'subcat.compressors' FROM categories WHERE slug='industrial-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Generators', 'generators', 'subcat.generators' FROM categories WHERE slug='industrial-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Pumps', 'pumps', 'subcat.pumps' FROM categories WHERE slug='industrial-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Welding Machines', 'welding-machines', 'subcat.welding-machines' FROM categories WHERE slug='industrial-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Factory Equipment', 'factory-equipment', 'subcat.factory-equipment' FROM categories WHERE slug='industrial-equipment' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Business & Commercial
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Shop Equipment', 'shop-equipment', 'subcat.shop-equipment' FROM categories WHERE slug='business-commercial' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'POS Systems', 'pos-systems', 'subcat.pos-systems' FROM categories WHERE slug='business-commercial' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Restaurant Equipment', 'restaurant-equipment', 'subcat.restaurant-equipment' FROM categories WHERE slug='business-commercial' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Salon Equipment', 'salon-equipment', 'subcat.salon-equipment' FROM categories WHERE slug='business-commercial' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Retail Displays', 'retail-displays', 'subcat.retail-displays' FROM categories WHERE slug='business-commercial' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Wholesale Goods', 'wholesale-goods', 'subcat.wholesale-goods' FROM categories WHERE slug='business-commercial' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);

-- Other Products
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Miscellaneous', 'miscellaneous', 'subcat.miscellaneous' FROM categories WHERE slug='other-products' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Collectibles', 'collectibles', 'subcat.collectibles' FROM categories WHERE slug='other-products' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Handmade Products', 'handmade-products', 'subcat.handmade-products' FROM categories WHERE slug='other-products' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Second Hand', 'second-hand', 'subcat.second-hand' FROM categories WHERE slug='other-products' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, 'Other', 'other', 'subcat.other' FROM categories WHERE slug='other-products' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);
-- ---------- END category_catalog_mega_upgrade.sql ----------


-- ----------------------------------------------------------------------------
-- BLOB fallback storage — for installs where the web server user cannot
-- write to assets/uploads/ (a permissions problem on the live server, not
-- a code bug). Also self-applied at runtime if missing (see
-- ensure_profile_avatar_storage() / ensure_listing_image_blob_storage() in
-- includes/functions.php), so this is redundant-but-safe on a fresh import.
-- ----------------------------------------------------------------------------
ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_blob MEDIUMBLOB NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_mime VARCHAR(50) NULL;
ALTER TABLE listing_images ADD COLUMN IF NOT EXISTS image_blob MEDIUMBLOB NULL;
ALTER TABLE listing_images ADD COLUMN IF NOT EXISTS image_mime VARCHAR(50) NULL;

-- ----------------------------------------------------------------------------
-- Per-listing pickup/delivery + accepted payment methods — self-applied at
-- runtime if missing (see ensure_listing_delivery_payment_columns() in
-- includes/functions.php), included here too for a fresh import.
-- ----------------------------------------------------------------------------
ALTER TABLE listings ADD COLUMN IF NOT EXISTS pickup_available TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE listings ADD COLUMN IF NOT EXISTS delivery_available TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE listings ADD COLUMN IF NOT EXISTS accepted_payments VARCHAR(255) NULL;
