-- Isoko Ryacu — Account Security Upgrade
-- Run once after the existing schema/migrations.
-- Safe for MySQL 8 / MariaDB used by XAMPP.

ALTER TABLE users
    ADD COLUMN login_failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN locked_until DATETIME NULL,
    ADD COLUMN last_login_at DATETIME NULL;

CREATE INDEX idx_users_locked_until ON users (locked_until);
