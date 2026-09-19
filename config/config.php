<?php
/**
 * config/config.php
 * --------------------------------------------------------------------
 * ISOKO RYACU — central configuration.
 *
 * Edit DB_NAME / DB_USER / DB_PASS to match your XAMPP MySQL setup.
 * Default XAMPP values are:  user = root, pass = (empty), host = localhost
 * --------------------------------------------------------------------
 */

declare(strict_types=1);

// --- Application ---
define('APP_NAME',       'Isoko Ryacu');
define('APP_TAGLINE',    'Discover Anything. Buy. Sell. Rent.');
// APP_URL is auto-detected from the request so the project works no matter
// what folder name you use in htdocs (e.g. "isoko-ryacu", "Market-store", etc.).
// Override by hardcoding a string below if you need a specific URL.
define('APP_URL',        app_url());

define('APP_VERSION',    '1.0.0');
define('APP_TIMEZONE',    'Africa/Kigali');

/**
 * Auto-detect the base URL of the project based on the request.
 *
 *   Examples:
 *     http://localhost/Market-store/             -> http://localhost/Market-store
 *     http://localhost/isoko-ryacu/pages/x.php   -> http://localhost/isoko-ryacu
 *     http://192.168.1.10:8080/isoko/            -> http://192.168.1.10:8080/isoko
 *
 * Returns an empty string if run from CLI (no request context).
 */
function app_url(): string
{
    // No request context (CLI / cron) — return empty so paths still work
    if (PHP_SAPI === 'cli' || !isset($_SERVER['SCRIPT_NAME'])) {
        return '';
    }

    // Determine protocol + host
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? 0) == 443);
    $protocol = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

    // Get the directory part of the entry script (e.g. "/Market-store" or "/Market-store/pages")
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));

    // Strip everything from the first /pages or /api path segment onward,
    // so we end up with just the project root - however deep the actual
    // endpoint file is nested under either (e.g. /api/v1/reports,
    // /api/v1/listings-action, /pages/admin, /pages/seller/withdrawals).
    //
    // NOTE: this used to be a hardcoded list of specific known subfolder
    // suffixes to strip (e.g. '/api/v1/auth', '/api/v1/admin-action') that
    // had to be kept in sync by hand with every endpoint directory added -
    // it was missing most of the actual api/v1/* folders (reports,
    // favorites, contact, listing-image, rentals, messages-poll,
    // categories, listings, users, seller-profile, health, admin-pulse,
    // search, profile), so APP_URL resolved too narrow (e.g.
    // "http://host/api/v1/reports" instead of "http://host") for any
    // request handled by one of those files - breaking any redirect or
    // link built from APP_URL from within that request. This regex needs
    // no such list and can't fall out of sync as new endpoints are added.
    if (preg_match('#^(.*?)/(pages|api)(/|$)#', $scriptDir, $m)) {
        $scriptDir = $m[1];
    }

    // Remove trailing slash (but keep "/" for root deployments — rare for this project)
    $scriptDir = rtrim($scriptDir, '/');
    if ($scriptDir === '') $scriptDir = '';   // deployed at domain root

    // Handle doubled folder names (e.g. /Market-store/Market-store).
    // This happens when the zip is extracted inside an existing folder with
    // the same name. Detect a repeated segment and collapse it to one.
    $parts = explode('/', ltrim($scriptDir, '/'));
    $cleanParts = [];
    $prev = '';
    foreach ($parts as $p) {
        if ($p === '' ) continue;
        // Skip if this segment is identical to the previous one (doubled folder)
        if (strtolower($p) === strtolower($prev)) continue;
        $cleanParts[] = $p;
        $prev = $p;
    }
    // A root deployment (Vercel typically serves the app straight from the
    // domain root, with no site subfolder at all) legitimately has zero
    // parts left here. Unconditionally prepending '/' below used to turn
    // that empty root back into '/', so every APP_URL-built link ended up
    // with a stray doubled slash (e.g. "http://host//pages/...") - mostly
    // silently normalized by browsers/servers, but not guaranteed to be,
    // and not what APP_URL is documented to return for a root deployment.
    $scriptDir = $cleanParts ? '/' . implode('/', $cleanParts) : '';

    return $protocol . '://' . $host . $scriptDir;
}

// --- Database (XAMPP defaults) ---
// Environment-aware database settings. Put real production secrets in the
// server environment (or a local .env file that is NEVER committed).
function env_value(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value !== false && $value !== '') return $value;
    return $default;
}

define('DB_HOST', env_value('ISOKO_DB_HOST', 'localhost'));
define('DB_PORT', (int) env_value('ISOKO_DB_PORT', '3306'));
define('DB_NAME', env_value('ISOKO_DB_NAME', 'isoko_ryacu'));
define('DB_USER', env_value('ISOKO_DB_USER', 'root'));
define('DB_PASS', env_value('ISOKO_DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

// --- Security ---
define('HASH_ALGO', PASSWORD_BCRYPT);
define('HASH_COST', 10);
define('SESSION_NAME', 'ISOKO_SESS');
define('CSRF_TOKEN_NAME', '_csrf');

// Idle-session security. A logged-in user is automatically signed out after
// this many seconds without activity. Keep this configurable for production.
define('IDLE_SESSION_TIMEOUT', 30 * 60); // 30 minutes
define('IDLE_SESSION_HEARTBEAT', 60);    // refresh while the user is active

// Login brute-force protection. Change these values if the administrator
// wants a different security policy.
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);

// --- File uploads ---
define('UPLOAD_DIR', __DIR__ . '/../assets/uploads/');
define('UPLOAD_URL',  APP_URL . '/assets/uploads/');
define('MAX_UPLOAD_BYTES', 5 * 1024 * 1024);   // 5MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/webp']);

// --- Defaults ---
define('DEFAULT_CURRENCY', 'RWF');
define('LISTINGS_PER_PAGE', 12);

// Performance / abuse-control knobs. APCu is optional; the app remains functional without it.
define('APPCACHE_DEFAULT_TTL', 60);
define('API_RATE_LIMIT_PER_MINUTE', 120);
define('API_RATE_LIMIT_WINDOW', 60);

// --- Environment ---
define('APP_ENV', env_value('ISOKO_ENV', 'local'));
define('APP_DEBUG', filter_var(env_value('ISOKO_DEBUG', APP_ENV === 'local' ? '1' : '0'), FILTER_VALIDATE_BOOL));

// --- Timezone ---
date_default_timezone_set(APP_TIMEZONE);

// --- Error reporting ---
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}
