<?php
/**
 * api/v1/index.php
 * --------------------------------------------------------------------
 * REST API entry point and router.
 *
 * The API is decoupled from the website presentation layer so that a
 * future Android or cross-platform app can reuse the same backend.
 *
 * Routes (Phase 1 implementation):
 *   GET  /api/v1/categories           → list all categories
 *   GET  /api/v1/categories?slug=...   → single category
 *   GET  /api/v1/listings              → list listings (filters same as explore)
 *   GET  /api/v1/listings?id=...        → single listing with images + attributes
 *   POST /api/v1/auth/register         → create user (JSON body)
 *   POST /api/v1/auth/login             → email + password → user token stub
 *   POST /api/v1/auth/logout            → invalidate session
 *   GET  /api/v1/favorites              → list current user's favorites
 *   POST /api/v1/favorites              → add favorite (JSON body)
 *   GET  /api/v1/rentals                → list rental requests
 *   POST /api/v1/rentals                → create rental request
 *   GET  /api/v1/users/me                → current user profile
 *
 * All responses are JSON.  Always sets Content-Type: application/json.
 * --------------------------------------------------------------------
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

// SECURITY: this API is session/cookie-authenticated (see includes/auth.php),
// so it must never send a wildcard Access-Control-Allow-Origin — combined
// with credentialed requests that is an easy path to cross-site data
// exposure. Reflect only the app's own origin (browsers already block
// credentialed cross-origin reads without a matching ACAO, so this simply
// stops the header from ever claiming to allow everyone).
$__requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($__requestOrigin !== '' && $__requestOrigin === rtrim(APP_URL, '/')) {
    header('Access-Control-Allow-Origin: ' . $__requestOrigin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Parse the path after /api/v1/
$path  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base  = '/api/v1';
$parts = substr($path, strpos($path, $base) + strlen($base));
$parts = trim($parts, '/');
$seg   = explode('/', $parts);

$resource = $seg[0] ?? '';
$id       = $seg[1] ?? '';

function json_response($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $_POST;
}

// Route the request
switch ($resource) {
    case '':
        json_response([
            'name'      => APP_NAME . ' REST API',
            'version'   => 'v1',
            'endpoints' => [
                'GET  /categories', 'GET /categories/{slug}',
                'GET  /listings',   'GET /listings/{id}',
                'POST /auth/register', 'POST /auth/login', 'POST /auth/logout',
                'GET  /favorites', 'POST /favorites',
                'GET  /rentals', 'POST /rentals',
                'GET  /users/me',
            ],
            'docs' => 'See api/v1/README.md (Phase 5)',
        ]);

    case 'categories':
        require __DIR__ . '/categories/index.php';
        break;

    case 'listings':
        require __DIR__ . '/listings/index.php';
        break;

    case 'favorites':
        require __DIR__ . '/favorites/index.php';
        break;

    case 'rentals':
        require __DIR__ . '/rentals/index.php';
        break;

    case 'reports':
        require __DIR__ . '/reports/index.php';
        break;

    case 'contact':
        require __DIR__ . '/contact/index.php';
        break;

    case 'users':
        require __DIR__ . '/users/index.php';
        break;

    case 'seller-profile':
        require __DIR__ . '/seller-profile/index.php';
        break;

    case 'listings-action':
        // Action endpoint: approve/reject/delete/mark-sold/mark-rented/relist
        require __DIR__ . '/listings-action/index.php';
        break;

    case 'auth':
        $action = $seg[1] ?? '';
        $file = __DIR__ . '/auth/' . $action . '.php';
        if ($action && file_exists($file)) require $file;
        else json_response(['error' => 'Unknown auth action'], 404);
        break;

    default:
        json_response(['error' => 'Unknown resource: ' . $resource], 404);
}
