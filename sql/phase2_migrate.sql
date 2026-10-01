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
