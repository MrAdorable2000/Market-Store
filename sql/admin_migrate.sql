-- ============================================================================
--  ISOKO RYACU — Admin upgrade migration (Phase 5: premium admin panel)
--  ------------------------------------------------------------------
--  Adds the system_logs table used by the admin System Logs page.
--
--  HOW TO APPLY (choose ONE of the two options):
--
--  Option A (phpMyAdmin / mysql CLI):
--      Import this file into your existing `isoko_ryacu` database:
--          mysql -u root -p isoko_ryacu < sql/admin_migrate.sql
--
--  Option B (automatic):
--      No action needed. The application creates this table automatically
--      (idempotent CREATE TABLE IF NOT EXISTS in includes/admin_log.php)
--      the first time an admin page or admin action runs.
--
--  SAFE: this file only creates ONE new table. It does not alter, drop or
--  modify any existing table, and it does not touch existing data.
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS system_logs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NULL,               -- admin who performed the action
    user_name       VARCHAR(120)  NULL,              -- denormalised name (survives user deletion)
    module          VARCHAR(40)   NOT NULL,          -- listings | users | categories | messages | reports | rentals | reviews | settings | auth | system
    action          VARCHAR(60)   NOT NULL,          -- e.g. listing_approved, user_suspended
    status          ENUM('success','error') NOT NULL DEFAULT 'success',
    details         VARCHAR(500)  NULL,              -- human-readable summary (no sensitive data)
    ip_address      VARCHAR(45)   NULL,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_log_created (created_at DESC),
    INDEX idx_log_module  (module),
    INDEX idx_log_user    (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- End of admin_migrate.sql
