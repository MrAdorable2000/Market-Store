-- Isoko Ryacu — Product form compatibility migration
-- Safe to run after seller_upgrade.sql. No existing data is removed.
SET NAMES utf8mb4;
ALTER TABLE listings
    ADD COLUMN IF NOT EXISTS low_stock_threshold INT NOT NULL DEFAULT 5 AFTER reserved_quantity;
