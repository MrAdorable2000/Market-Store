<?php
/**
 * includes/functions.php
 * --------------------------------------------------------------------
 * Shared helpers used everywhere in Isoko Ryacu.
 *
 *   e($v)                       — output-escape (XSS protection)
 *   slugify($text)              — URL-safe slugs
 *   csrf_token() / csrf_check() — CSRF token helpers
 *   redirect($url)              — header redirect then exit
 *   old($key, $default)         — repopulate forms after error
 *   flash_set / flash_get        — one-shot session messages
 *   format_price($n,$cur)       — pretty price with currency
 *   time_ago($timestamp)        — "3 days ago"
 *   image_or_default($path)     — fallback to SVG placeholder
 *   excerpt($text, $len)        — trimmed text
 * --------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/performance.php';

// Apply a lightweight per-IP/API mutation limit when APCu is available.
// GET health/search endpoints are intentionally not blocked here.
if (request_is_api() && in_array(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    if (!api_rate_limit('api-mutations', API_RATE_LIMIT_PER_MINUTE, API_RATE_LIMIT_WINDOW)) {
        send_json_error(429, 'Too many requests. Please try again shortly.');
    }
}

/**
 * Start session if not started (used by auth + flash).
 */
function start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443,
        ]);
        session_start();
    }
}

/**
 * Output-escape a value for safe HTML display.
 * Use EVERY time user input is rendered in HTML.
 */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Convert a string to a URL-safe slug.
 */
function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', $text);
    $text = trim($text, '-');
    return $text ?: 'item-' . time();
}

/**
 * Get-or-create a CSRF token for the current session.
 */
function csrf_token(): string
{
    start_session();
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Render a hidden CSRF input for forms.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . csrf_token() . '">';
}

/**
 * Validate a submitted CSRF token.
 */
function csrf_check(): bool
{
    start_session();
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    return is_string($token) && !empty($_SESSION[CSRF_TOKEN_NAME])
        && hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

/**
 * Redirect to a URL and stop execution.
 */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * Repopulate form fields after a failed submission.
 */
function old(string $key, $default = ''): string
{
    return e($_SESSION['_old'][$key] ?? $default);
}

function remember_old(array $fields): void
{
    start_session();
    $_SESSION['_old'] = [];
    foreach ($fields as $k) {
        if (isset($_POST[$k])) $_SESSION['_old'][$k] = $_POST[$k];
    }
}

function clear_old(): void
{
    start_session();
    unset($_SESSION['_old']);
}

/**
 * Flash messages: one-shot session notifications.
 */
function flash_set(string $type, string $message): void
{
    start_session();
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function flash_get(): array
{
    start_session();
    $flashes = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $flashes;
}

/**
 * Format a price with thousands separator and currency.
 */
function format_price($amount, string $currency = 'RWF'): string
{
    $amount = (float) $amount;
    $formatted = number_format($amount, ($amount && $amount < 1000 ? 2 : 0), '.', ',');
    return $formatted . ' ' . e($currency);
}

/**
 * Human-readable "time ago" string — translated via t().
 * Appears on every listing card, so translation is critical.
 */
function time_ago($datetime): string
{
    $timestamp = is_numeric($datetime) ? (int) $datetime : strtotime((string) $datetime);
    $diff = time() - $timestamp;
    if ($diff < 0) $diff = 0;
    if ($diff < 60)    return t('time.just_now');
    if ($diff < 3600)  return floor($diff / 60) . ' ' . t('time.min_ago');
    if ($diff < 86400) return floor($diff / 3600) . ' ' . t('time.hr_ago');
    if ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' ' . ($days === 1 ? t('time.day_ago') : t('time.days_ago'));
    }
    if ($diff < 2592000) return date('M j, Y', $timestamp);
    // For older dates, just show the date
    return date('M j, Y', $timestamp);
}

/**
 * Return the image path if it exists, otherwise a default placeholder.
 * Used to avoid broken images everywhere a listing/user has no image.
 *
 * Real photographs live in assets/images/real/<name>.jpg (downloaded from
 * properly licensed stock sources). SVG fallbacks live in
 * assets/images/placeholders/<name>.svg.
 *
 * If $path is null, we look for a real photo named $default (without the
 * .svg extension) — if it exists, use it; otherwise fall back to the SVG.
 */
function ensure_profile_avatar_storage(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    try {
        $cols = [];
        $q = $pdo->query("SHOW COLUMNS FROM users");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $cols[strtolower((string)$row['Field'])] = true;
        if (!isset($cols['avatar_blob'])) {
            $pdo->exec("ALTER TABLE users ADD COLUMN avatar_blob MEDIUMBLOB NULL");
        }
        if (!isset($cols['avatar_mime'])) {
            $pdo->exec("ALTER TABLE users ADD COLUMN avatar_mime VARCHAR(50) NULL");
        }
    } catch (Throwable $e) {
        // Existing installations may not allow ALTER TABLE. The normal file
        // avatar path remains available as a fallback.
    }
    $done = true;
}

function ensure_listing_image_blob_storage(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    try {
        $cols = [];
        $q = $pdo->query("SHOW COLUMNS FROM listing_images");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $cols[strtolower((string)$row['Field'])] = true;
        if (!isset($cols['image_blob'])) {
            $pdo->exec("ALTER TABLE listing_images ADD COLUMN image_blob MEDIUMBLOB NULL");
        }
        if (!isset($cols['image_mime'])) {
            $pdo->exec("ALTER TABLE listing_images ADD COLUMN image_mime VARCHAR(50) NULL");
        }
    } catch (Throwable $e) {
        // Existing installations may not allow ALTER TABLE. Filesystem
        // storage remains available as the primary path either way.
    }
    $done = true;
}

function ensure_listing_delivery_payment_columns(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    try {
        $cols = [];
        $q = $pdo->query("SHOW COLUMNS FROM listings");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $cols[strtolower((string)$row['Field'])] = true;
        if (!isset($cols['pickup_available'])) {
            $pdo->exec("ALTER TABLE listings ADD COLUMN pickup_available TINYINT(1) NOT NULL DEFAULT 1");
        }
        if (!isset($cols['delivery_available'])) {
            $pdo->exec("ALTER TABLE listings ADD COLUMN delivery_available TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!isset($cols['accepted_payments'])) {
            $pdo->exec("ALTER TABLE listings ADD COLUMN accepted_payments VARCHAR(255) NULL");
        }
    } catch (Throwable $e) {
        // Best-effort — the listing still saves fine without these columns,
        // the form values are simply not persisted until this succeeds.
    }
    $done = true;
}

function image_or_default(?string $path, string $default = 'assets/images/placeholders/default.svg'): string
{
    if ($path) {
        if (str_starts_with($path, 'http')) return $path;     // external (allowed for known CDN paths)
        // A listing photo that could not be written to disk (e.g. an
        // uploads/ directory permissions problem) is stored as a database
        // BLOB instead, marked with this sentinel path. Route it to the
        // endpoint that streams the BLOB back out.
        if (str_starts_with($path, 'db-blob:')) {
            $id = (int) substr($path, 8);
            return APP_URL . '/api/v1/listing-image/?id=' . $id;
        }
        return APP_URL . '/' . ltrim($path, '/');
    }
    // No specific path → try a real photo named after the default's stem
    $stem = preg_replace('/\.svg$/', '', basename($default));
    $realPath = __DIR__ . '/../assets/images/real/' . $stem . '.jpg';
    if (file_exists($realPath)) {
        return APP_URL . '/assets/images/real/' . $stem . '.jpg';
    }
    return APP_URL . '/' . $default;
}

/**
 * Resolve a named visual asset (e.g. "vehicle", "blog-safety", "hero") to a
 * real photo if available, otherwise an SVG placeholder.  Used for category
 * icons, blog covers, hero images, and seller avatars.
 *
 * Looks in:
 *   1. assets/images/real/<name>.jpg  (preferred — real African photographs)
 *   2. assets/images/placeholders/<name>.svg (fallback)
 */
function real_image(string $name, string $fallback = 'default'): string
{
    $realPath = __DIR__ . '/../assets/images/real/' . $name . '.jpg';
    if (file_exists($realPath)) {
        return APP_URL . '/assets/images/real/' . $name . '.jpg';
    }
    return APP_URL . '/assets/images/placeholders/' . $fallback . '.svg';
}

/**
 * Resolve an official IsokoRyacu logo asset (generated from the uploaded
 * MarketStoreLogo.png — never redesigned, colors untouched).
 *
 * Files live in assets/images/logo/:
 *   logo-icon.png        squircle icon only (transparent corners)
 *   logo.png             full lockup icon + wordmark (transparent bg)
 *   logo-wordmark.png    wordmark only
 *   favicon.ico / favicon-32.png / apple-touch-icon.png
 *
 * Returns '' when the file is missing so callers can fall back to the
 * original "IR" gradient mark (no broken images ever).
 */
function logo_asset(string $name = 'logo-icon.png'): string
{
    $path = __DIR__ . '/../assets/images/logo/' . $name;
    if (is_file($path)) {
        return APP_URL . '/assets/images/logo/' . $name;
    }
    return '';
}

/**
 * Render the brand mark: the official logo icon when available, otherwise the
 * original gradient "IR" mark. Used by navbar, footer, admin sidebar, auth pages.
 */
function brand_mark_html(int $size = 38): string
{
    $logo = logo_asset();
    if ($logo !== '') {
        return '<span class="brand__mark brand__mark--img" aria-hidden="true" style="width:' . $size . 'px;height:' . $size . 'px;">'
             . '<img src="' . e($logo) . '" alt="" width="' . $size . '" height="' . $size . '"></span>';
    }
    return '<span class="brand__mark" aria-hidden="true">IR</span>';
}

/**
 * Render a back button.
 *
 *  - Uses history.back() if there's a same-origin referrer (so the user
 *    returns to wherever they came from within Isoko Ryacu).
 *  - Otherwise falls back to $fallbackUrl (default = homepage).
 *
 * Variants:
 *   'glass' → frosted glass pill (for dark/photo backgrounds, e.g. auth pages)
 *   'solid' → solid card pill (for light backgrounds, e.g. content pages)
 *   'inline' → small solid pill (for breadcrumbs / rows)
 *
 * @param string $fallbackUrl URL to use when there's no same-origin referrer
 * @param string $label        Visible label (default: translated "Back")
 * @param string $variant      'glass' | 'solid' | 'inline'
 */
function back_button(string $fallbackUrl = '', string $label = '', string $variant = 'solid'): string
{
    // Default label is translated "Back" — ensures back buttons are localized
    // everywhere they appear (listing details, blog posts, auth pages, etc.)
    if ($label === '') $label = t('buttons.back');
    $fallbackUrl = $fallbackUrl ?: (APP_URL . '/');
    $class = 'back-btn back-btn--' . $variant;
    // The inline onclick uses history.back() only if the previous page is
    // from the same origin (prevents leaving the site if the user opened the
    // page in a new tab). Otherwise the href fallback is used.
    $onclick = "if(history.length>1 && (document.referrer==='' || document.referrer.indexOf(location.origin)===0)){history.back();return false;}";
    return '<a href="' . e($fallbackUrl) . '" class="' . $class . '" onclick="' . $onclick . '">'
         . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
         . e($label)
         . '</a>';
}

/**
 * Short excerpt for cards.
 */
function excerpt(?string $text, int $len = 140): string
{
    if (!$text) return '';
    $text = strip_tags($text);
    if (mb_strlen($text) <= $len) return e($text);
    return e(mb_substr($text, 0, $len - 1) . '…');
}

/**
 * Get the client's IP address (best-effort).
 */
function client_ip(): string
{
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Check if the current request is HTTPS.
 */
function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? 0) == 443);
}

/**
 * Handle a multi-file image upload from a form field.
 *
 *   $field      — the $_FILES field name (e.g. 'images')
 *   $prefix     — filename prefix for stored files (e.g. 'listing_12345')
 *
 * Returns:
 *   On success: array of relative paths (e.g. ['assets/uploads/l_123_abc.jpg', ...])
 *   On failure:  ['error' => 'Message']
 *
 * Validates:
 *   - File type (image/jpeg, image/png, image/webp only)
 *   - File size (max MAX_UPLOAD_BYTES = 5MB each)
 *   - Upload errors
 *
 * On success, also:
 *   - Generates a safe random filename
 *   - Resizes large images to max 1280px wide using Pillow/PHP GD
 *   - Re-encodes as JPEG quality 82 to optimize size
 *   - Saves to assets/uploads/
 */

/**
 * Returns the set of column names that actually exist on a table, so
 * callers can defend against optional migrations not having been run
 * yet (e.g. listings.sku, listings.stock_quantity, categories.name_key
 * are only added by later migration files, not the base schema.sql).
 * Cached per-request per table since it never changes mid-request.
 *
 * @return array<string,true> map of column name => true
 */
function db_table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
    $cols = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cols[(string) $row['Field']] = true;
    }
    return $cache[$table] = $cols;
}

function handle_image_upload(string $field, string $prefix = 'img', bool $allowBlobFallback = false): array
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'][0] === UPLOAD_ERR_NO_FILE) {
        return ['error' => 'No file uploaded.'];
    }

    $files = $_FILES[$field];
    $count = count($files['name']);
    $paths = [];

    // Clean up any files this call already saved before failing on a later
    // one in the same batch — otherwise a bad 3rd photo out of 5 would
    // leave the first 2 as orphaned files on disk. Blob-fallback entries
    // (arrays, not strings) have nothing on disk to clean up.
    $cleanupAndFail = function (string $message) use (&$paths): array {
        foreach ($paths as $p) {
            if (is_string($p)) @unlink(__DIR__ . '/../' . $p);
        }
        return ['error' => $message];
    };

    for ($i = 0; $i < $count; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            // Skip silently — but if it's the only file, return error
            if ($count === 1) return $cleanupAndFail(t('errors.upload_failed'));
            continue;
        }

        // Validate size
        if ($files['size'][$i] > MAX_UPLOAD_BYTES) {
            return $cleanupAndFail(t('errors.upload_size'));
        }

        // Validate MIME type (real check, not just the client-provided one)
        $tmpPath = $files['tmp_name'][$i];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);
        if (!in_array($mime, ALLOWED_IMAGE_TYPES, true)) {
            return $cleanupAndFail(t('errors.upload_type'));
        }

        // Generate safe filename
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'jpg',
        };
        $safeName = $prefix . '_' . $i . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $relPath  = 'assets/uploads/' . $safeName;
        $absPath = __DIR__ . '/../' . $relPath;

        // Move + optimize. @-suppressed: a failure here (commonly an
        // uploads/ directory permissions problem on the live server) is
        // fully handled below — the raw PHP warning must never leak into
        // the page.
        if (!@move_uploaded_file($tmpPath, $absPath)) {
            if (!$allowBlobFallback) {
                return $cleanupAndFail(t('errors.upload_failed'));
            }
            // The filesystem write failed. Rather than fail the whole
            // product submission, fall back to storing the image as a
            // database BLOB — the same fallback already used for profile
            // avatars. $tmpPath is PHP's own upload temp file and is
            // always writable, so it's validated/optimized in place.
            if (optimize_image_inplace($tmpPath) === false) {
                return $cleanupAndFail(t('errors.upload_type'));
            }
            $blobData = @file_get_contents($tmpPath);
            if ($blobData === false || $blobData === '') {
                return $cleanupAndFail(t('errors.upload_failed'));
            }
            $paths[] = ['blob' => $blobData, 'mime' => 'image/jpeg'];
            continue;
        }

        // Re-encode through GD. This is not just an optimization: GD must
        // actually be able to decode the file as a real image of the type
        // it claims to be. A file that fooled the MIME sniff but isn't a
        // genuine, decodable image fails here and must be rejected outright
        // — never silently kept on disk and recorded as a product photo.
        // (null means optimization was skipped for an environment reason —
        // e.g. this GD build lacks a codec — not a problem with the file
        // itself, so the already-validated file is still accepted.)
        if (optimize_image_inplace($absPath) === false) {
            @unlink($absPath);
            return $cleanupAndFail(t('errors.upload_type'));
        }

        $paths[] = $relPath;
    }
    return $paths;
}

/**
 * Optimize an uploaded image in-place using PHP GD (always available in XAMPP).
 * - Resizes so the longest side is at most 1280px
 * - Re-encodes as JPEG quality 82
 * - Original file is replaced.
 *
 * Return value is tri-state:
 *   true  — decoded, verified, and re-encoded successfully.
 *   false — the file is not a genuine, decodable image of its claimed
 *           type. The caller should reject and delete it.
 *   null  — could not attempt optimization due to an environment
 *           limitation (GD missing, or this GD build lacks a codec for
 *           the format), NOT a problem with the file itself. The file
 *           already passed getimagesize()'s structural check, so the
 *           caller should accept it as-is rather than reject a
 *           legitimate photo over a server configuration limit.
 */
function optimize_image_inplace(string $path): ?bool
{
    if (!file_exists($path)) return false;

    // getimagesize() parses real image file headers and needs no GD, so
    // this check applies regardless of what codecs the server has.
    $info = @getimagesize($path);
    if (!$info) return false;
    [$w, $h, $type] = $info;

    if (!function_exists('imagecreatefromjpeg')) return null;
    if ($type === IMAGETYPE_WEBP && !function_exists('imagecreatefromwebp')) return null;

    $src = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG  => @imagecreatefrompng($path),
        IMAGETYPE_WEBP => @imagecreatefromwebp($path),
        default        => false,
    };
    if (!$src) return false;

    $max = 1280;
    if ($w > $max || $h > $max) {
        $ratio = min($max / $w, $max / $h);
        $newW = max(1, (int)round($w * $ratio));
        $newH = max(1, (int)round($h * $ratio));
        $dst = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagedestroy($src);
        $src = $dst;
    }

    $ok = @imagejpeg($src, $path, 85);
    imagedestroy($src);
    return $ok;
}

/** Permanently delete a user and account-owned data safely. */
function permanently_delete_user(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) throw new InvalidArgumentException('Invalid user id.');
    $pdo->beginTransaction();
    try {
        $rows=$pdo->query("SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='users' ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
        $groups=[];
        foreach($rows as $row){$t=(string)$row['TABLE_NAME'];$c=(string)$row['CONSTRAINT_NAME'];$col=(string)$row['COLUMN_NAME'];if(preg_match('/^[A-Za-z0-9_]+$/',$t)&&preg_match('/^[A-Za-z0-9_]+$/',$c)&&preg_match('/^[A-Za-z0-9_]+$/',$col))$groups[$t][$c][]=$col;}
        foreach($groups as $t=>$cs)foreach($cs as $c=>$cols){$q=$pdo->prepare("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME=? LIMIT 1");$q->execute([$c]);$rule=strtoupper((string)$q->fetchColumn());if(!in_array($rule,['RESTRICT','NO ACTION'],true))continue;$w=[];$v=[];foreach($cols as $col){$w[]="`$col`=?";$v[]=$userId;}if($w)$pdo->prepare("DELETE FROM `$t` WHERE ".implode(' AND ',$w))->execute($v);}
        $q=$pdo->prepare('DELETE FROM users WHERE id=?');$q->execute([$userId]);if($q->rowCount()!==1)throw new RuntimeException('User not found or already deleted.');$pdo->commit();return true;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

// Phase 2: internationalization — load at the end of functions.php so all
// helper functions (start_session, e, etc.) are defined before i18n uses them.
require_once __DIR__ . '/i18n.php';
