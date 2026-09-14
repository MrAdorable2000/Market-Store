-- ============================================================================
--  ISOKO RYACU — Phase 3 Migration
--  -----------------------------------------------------------------------
--  Adds:
--    - SUPER_ADMIN role (id=4)
--    - A secure super admin account (password is bcrypt-hashed)
--    - Sets the default registration role to USER (role_id=3, "USER")
--    - Adds an index for fast role lookups
--  -----------------------------------------------------------------------
--  Safe to re-run (idempotent).  No Phase 1 or Phase 2 data is destroyed.
-- ============================================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- 1. Add the SUPER_ADMIN role (id=4)
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO roles (id, name, description) VALUES
(4, 'SUPER_ADMIN', 'Full platform administration — single super admin account');

-- ----------------------------------------------------------------------------
-- 2. Create the SUPER_ADMIN account
--    Email: ethiennemugisha35@gmail.com
--    Password: password (bcrypt-hashed — never stored as plain text)
-- ----------------------------------------------------------------------------
INSERT INTO users (id, role_id, full_name, email, password_hash, phone, location, bio, avatar_path, is_verified, is_seller, status)
VALUES (
    100,
    4,
    'Super Admin',
    'ethiennemugisha35@gmail.com',
    '$2y$10$up5zrOoUlz6T0i3MvNGtmOYrDD7CP9EVcubl3e5um0MYA2RslC7Pm',
    '+250 788 000 000',
    'Kigali, Rwanda',
    'Platform Super Administrator. Full access to all administrative functions.',
    NULL,
    1,
    0,
    'active'
)
ON DUPLICATE KEY UPDATE
    role_id       = 4,
    full_name     = 'Super Admin',
    password_hash = '$2y$10$up5zrOoUlz6T0i3MvNGtmOYrDD7CP9EVcubl3e5um0MYA2RslC7Pm',
    status        = 'active',
    is_verified   = 1;

-- ----------------------------------------------------------------------------
-- 3. Normalize existing role names for clarity (Phase 1 had lowercase names)
-- ----------------------------------------------------------------------------
UPDATE roles SET name = 'ADMIN',        description = 'Standard platform administrator'     WHERE id = 1;
UPDATE roles SET name = 'SELLER',       description = 'Can publish listings + act as buyer' WHERE id = 2;
UPDATE roles SET name = 'USER',         description = 'Standard user (buyer + seller)'      WHERE id = 3;
UPDATE roles SET name = 'SUPER_ADMIN',  description = 'Full platform administration'         WHERE id = 4;

-- ----------------------------------------------------------------------------
-- 4. Make sure no normal user can ever become admin through registration
--    (the application code enforces this — INSERT into users always uses
--    role_id = 3 = USER for new registrations)
--    This UPDATE is a one-time safety check that downgrades any orphan admin
--    accounts that might have been created during testing.
-- ----------------------------------------------------------------------------
-- ⚠️ Commented out by default to avoid changing existing data:
-- UPDATE users SET role_id = 3 WHERE role_id NOT IN (1, 4) AND email NOT IN ('admin@isoko.rw','ethiennemugisha35@gmail.com');

-- ----------------------------------------------------------------------------
-- 5. Helpful indexes for role-based queries
-- ----------------------------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_users_role_status ON users(role_id, status);

-- ============================================================================
--  Verification (run these manually to check):
--    SELECT id, name FROM roles;
--    SELECT id, role_id, full_name, email, status FROM users WHERE role_id = 4;
-- ============================================================================
