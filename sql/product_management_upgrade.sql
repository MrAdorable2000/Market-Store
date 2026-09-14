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
