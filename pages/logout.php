<?php
/**
 * pages/logout.php
 * --------------------------------------------------------------------
 * Destroys the session and redirects to homepage.
 * Admin sign-outs are recorded in the system log.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_log.php';

// Log the admin sign-out BEFORE the session is destroyed
// (admin_log reads the current user from the session).
if (is_logged_in() && is_admin()) {
    $u = current_user();
    admin_log('auth', 'admin_logout', 'Admin "' . ($u['full_name'] ?? 'unknown') . '" signed out');
}

logout_user();
flash_set('info', t('flash.signed_out'));
redirect(APP_URL . '/');
