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
