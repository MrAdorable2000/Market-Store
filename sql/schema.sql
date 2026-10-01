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
