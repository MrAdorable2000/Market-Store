-- Isoko Ryacu: real user presence + WhatsApp profile data
-- Run after schema.sql / existing migrations. Safe to re-run on MySQL 8+.
SET NAMES utf8mb4;
ALTER TABLE users ADD COLUMN IF NOT EXISTS whatsapp_number VARCHAR(40) NULL AFTER phone;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_seen_at DATETIME NULL AFTER updated_at;
CREATE INDEX IF NOT EXISTS idx_users_last_seen ON users(last_seen_at);
