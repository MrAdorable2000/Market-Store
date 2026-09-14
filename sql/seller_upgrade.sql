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
