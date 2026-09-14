-- ============================================================================
--  ISOKO RYACU — Super Admin Permissions Migration
--  =====================================================================
--  Adds fine-grained admin permissions + assistant super admin support.
--  Idempotent — safe to re-run.
-- ============================================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- 1. admin_permissions — per-user permission flags for admins/assistants
--    Each row stores a JSON array of permission keys for one user.
--    Super Admins implicitly have ALL permissions (no row needed).
--    Assistant Super Admins have a row with their granted permissions.
--    Normal Admins have a row with default admin permissions.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_permissions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL UNIQUE,
    is_assistant    TINYINT(1)   NOT NULL DEFAULT 0,
    permissions     TEXT         NOT NULL DEFAULT '[]',
    granted_by      INT UNSIGNED NULL,
    granted_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ap_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ap_grantor FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_ap_user (user_id),
    INDEX idx_ap_assistant (is_assistant)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 2. Add is_primary_super_admin flag to users table
--    Marks the original/primary super admin who cannot be removed.
--    Set to 1 for the first super admin account only.
-- ----------------------------------------------------------------------------
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_primary_super_admin TINYINT(1) NOT NULL DEFAULT 0;

-- Mark the existing super admin (ethiennemugisha35@gmail.com) as primary
UPDATE users SET is_primary_super_admin = 1
 WHERE email = 'ethiennemugisha35@gmail.com'
   AND is_primary_super_admin = 0;

-- End of super_admin_permissions.sql
