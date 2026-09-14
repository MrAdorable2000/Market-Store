<?php
/**
 * api/v1/auth/activity.php
 * --------------------------------------------------------------------
 * Refreshes the authenticated session's inactivity timer while the user
 * is actively using an open page. This endpoint never extends a session
 * that has already expired.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth.php';

function activity_json($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    activity_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

// Require the same CSRF token already exposed to the site's authenticated UI.
$headerToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$sessionToken = (string) ($_SESSION[CSRF_TOKEN_NAME] ?? '');
if ($headerToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $headerToken)) {
    activity_json(['ok' => false, 'error' => 'Invalid security token'], 403);
}

$user = current_user();
if (!$user) {
    activity_json(['ok' => false, 'reason' => 'idle'], 401);
}

$_SESSION['_idle_last_activity'] = time();
activity_json(['ok' => true]);
