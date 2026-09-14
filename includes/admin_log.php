<?php
/**
 * includes/admin_log.php
 * --------------------------------------------------------------------
 * System-log helper for the IsokoRyacu admin area.
 *
 *   admin_log($module, $action, $details, $status)
 *
 * Writes meaningful, real actions (never fabricated entries) into the
 * `system_logs` table so administrators can audit everything that happens
 * in the control center: approvals, rejections, deletions, user status
 * changes, category changes, report handling, review moderation, settings
 * changes and admin logins/logouts.
 *
 * The table is created automatically (idempotent) on first use, so the
 * platform keeps working even before sql/admin_migrate.sql is imported.
 * No sensitive information (passwords, hashes, tokens) is ever stored.
 * --------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../config/database.php';

/**
 * Ensure the system_logs table exists (idempotent, matches
 * sql/admin_migrate.sql exactly so both paths stay in sync).
 */
function admin_log_ensure_table(): void
{
    static $ensured = false;
    if ($ensured) return;

    try {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS system_logs (
                id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id         INT UNSIGNED NULL,
                user_name       VARCHAR(120)  NULL,
                module          VARCHAR(40)   NOT NULL,
                action          VARCHAR(60)   NOT NULL,
                status          ENUM(\'success\',\'error\') NOT NULL DEFAULT \'success\',
                details         VARCHAR(500)  NULL,
                ip_address      VARCHAR(45)   NULL,
                created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_log_created (created_at DESC),
                INDEX idx_log_module  (module),
                INDEX idx_log_user    (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $ensured = true;
    } catch (PDOException $e) {
        // Never break the admin flow because of logging — fail silently.
        // (The dedicated System Logs page surfaces migration problems in
        // a friendly way instead.)
    }
}

/**
 * Record an admin action in the system log.
 *
 * @param string $module  listings|users|categories|messages|reports|rentals|reviews|settings|auth|system
 * @param string $action  machine-ish action name, e.g. 'listing_approved'
 * @param string $details human-readable summary shown in the logs table
 * @param string $status  'success' | 'error'
 */
function admin_log(string $module, string $action, string $details = '', string $status = 'success'): void
{
    try {
        admin_log_ensure_table();

        $user = function_exists('current_user') ? current_user() : null;

        db()->prepare(
            'INSERT INTO system_logs (user_id, user_name, module, action, status, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $user['id'] ?? null,
            $user['full_name'] ?? null,
            mb_substr($module, 0, 40),
            mb_substr($action, 0, 60),
            $status === 'error' ? 'error' : 'success',
            mb_substr($details, 0, 500),
            function_exists('client_ip') ? client_ip() : null,
        ]);
    } catch (Throwable $e) {
        // Logging must never break the request.
    }
}
