-- ============================================================================
--  ISOKO RYACU — Idempotent roles seed / repair migration
--  ------------------------------------------------------------------
--  PURPOSE
--    Fixes the foreign-key violation reported on user registration:
--      SQLSTATE[23000]: Integrity constraint violation: 1452
--      Cannot add or update a child row: a foreign key constraint fails
--      (`isoko_ryacu`.`users`, CONSTRAINT `fk_users_role`
--       FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`))
--
--  ROOT CAUSE
--    includes/auth.php historically inserted new users with a hardcoded
--    `role_id = 3`.  When the `roles` table is empty (e.g. after re-importing
--    schema.sql without re-running seed.sql) or when only phase3_migrate.sql
--    has been applied, no row with id = 3 exists, so the INSERT violates the
--    fk_users_role constraint.
--
--  WHAT THIS MIGRATION DOES
--    1. Ensures the four canonical roles exist with their canonical IDs:
--         1  ADMIN        — standard platform administrator
--         2  SELLER       — can publish listings + act as buyer
--         3  USER         — standard user (buyer + seller)  ← default registration role
--         4  SUPER_ADMIN  — full platform administration
--       (These names match the uppercase names already used by
--        includes/auth.php::require_role() and pages/admin/users.php.)
--    2. Normalizes legacy lowercase role names ('admin', 'seller', 'buyer')
--       to the uppercase names the application expects, WITHOUT changing IDs.
--    3. Repairs any orphaned users.role_id values (caused by manual deletes)
--       by pointing them at the USER role (id = 3) — safe default that
--       preserves their ability to log in.
--
--  WHAT THIS MIGRATION DOES NOT DO
--    - Does NOT drop the roles table.
--    - Does NOT delete existing users, listings, images, or other data.
--    - Does NOT disable FOREIGN_KEY_CHECKS.
--    - Does NOT remove or weaken the fk_users_role constraint.
--    - Does NOT create duplicate roles (uses INSERT ... ON DUPLICATE KEY UPDATE).
--
--  SAFE TO RE-RUN
--    Every statement is idempotent. Running this file 1, 2, or N times
--    produces the exact same final state.
--
--  HOW TO APPLY
--    Option A — phpMyAdmin:  Import this file into the `isoko_ryacu` database.
--    Option B — mysql CLI:
--        mysql -u root -p isoko_ryacu < sql/fix_roles_seed.sql
-- ============================================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- 1. Ensure the four canonical roles exist with their canonical IDs.
--    ON DUPLICATE KEY UPDATE keeps the id stable and upgrades the name /
--    description to the canonical uppercase form used by the application.
--    This is safe whether the row already exists or not, and never creates
--    duplicates (the `name` column has a UNIQUE constraint, and we also pin
--    the primary key).
-- ----------------------------------------------------------------------------
INSERT INTO roles (id, name, description) VALUES
    (1, 'ADMIN',       'Standard platform administrator'),
    (2, 'SELLER',      'Can publish listings + act as buyer'),
    (3, 'USER',        'Standard user (buyer + seller)'),
    (4, 'SUPER_ADMIN', 'Full platform administration')
ON DUPLICATE KEY UPDATE
    name        = VALUES(name),
    description = VALUES(description);

-- ----------------------------------------------------------------------------
-- 2. Normalize any legacy lowercase role names that might still be present
--    in databases seeded before phase3_migrate.sql was applied.
--    (No-op if the names are already uppercase.)
--    We do NOT change the `id` — only the `name` and `description` columns.
-- ----------------------------------------------------------------------------
UPDATE roles SET name = 'ADMIN',       description = 'Standard platform administrator'     WHERE id = 1;
UPDATE roles SET name = 'SELLER',      description = 'Can publish listings + act as buyer' WHERE id = 2;
UPDATE roles SET name = 'USER',        description = 'Standard user (buyer + seller)'      WHERE id = 3;
UPDATE roles SET name = 'SUPER_ADMIN', description = 'Full platform administration'        WHERE id = 4;

-- ----------------------------------------------------------------------------
-- 3. Repair orphaned users (users.role_id pointing at a missing roles.id).
--    This can happen if a role was manually deleted. We point such users at
--    the USER role (id = 3), which is the safest default — they can still log
--    in, browse, favorite, and contact sellers. Admins can re-assign roles
--    from the admin panel afterwards.
--    Existing users with a valid role_id are NOT touched.
-- ----------------------------------------------------------------------------
UPDATE users
   SET role_id = 3
 WHERE role_id NOT IN (SELECT id FROM (SELECT id FROM roles) AS safe_roles);

-- ----------------------------------------------------------------------------
-- 4. Verification queries (safe to run, read-only):
--      SELECT id, name, description FROM roles ORDER BY id;
--      SELECT u.id, u.email, u.role_id, r.name AS role_name
--        FROM users u LEFT JOIN roles r ON r.id = u.role_id
--       WHERE r.id IS NULL;   -- should return zero orphaned rows
-- ----------------------------------------------------------------------------

-- End of fix_roles_seed.sql
