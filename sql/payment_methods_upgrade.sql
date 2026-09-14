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
