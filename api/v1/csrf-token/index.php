<?php
/**
 * api/v1/csrf-token/index.php
 * --------------------------------------------------------------------
 * GET /api/v1/csrf-token/index.php -> { csrf_token: "..." }
 *
 * The website embeds the CSRF token directly in HTML forms
 * (includes/functions.php's csrf_field()), which a native mobile app has
 * no equivalent of — it needs to fetch the token once (right after login
 * works well, since csrf_token() ties it to the session either way) and
 * send it back as the X-CSRF-Token header on state-changing requests,
 * exactly like assets/js/favorites.js already does for the web app's own
 * AJAX calls.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['csrf_token' => csrf_token()], JSON_UNESCAPED_SLASHES);
