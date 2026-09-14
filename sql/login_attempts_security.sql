-- Isoko Ryacu - login attempt / brute-force protection
-- Default policy: 5 failed password attempts -> 15 minute temporary lock.
-- Safe for an existing users table on MySQL/MariaDB versions supporting
-- ADD COLUMN IF NOT EXISTS. The application also auto-migrates these columns
-- when login is first used.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS failed_login_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN IF NOT EXISTS login_locked_until DATETIME NULL AFTER failed_login_attempts;

CREATE INDEX IF NOT EXISTS idx_users_login_locked_until ON users (login_locked_until);
