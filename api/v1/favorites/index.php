<?php
/**
 * api/v1/favorites/index.php
 * --------------------------------------------------------------------
 * Favorites endpoint.
 *
 *   GET  /api/v1/favorites            → list current user's favorites
 *   POST /api/v1/favorites            → add favorite (JSON body: {listing_id})
 *   POST /api/v1/favorites?id=N&action=remove → remove favorite
 *   POST /api/v1/favorites?id=N&action=add    → add favorite (alternative)
 *
 * Returns JSON:
 *   { "success": true, "state": "saved|removed", "favorites_count": N, "message": "..." }
 *   { "success": false, "error": "..." }
 *
 * Works for both:
 *   - AJAX fetch from the listing cards (no redirect)
 *   - Regular form posts (will redirect back)
 */
require_once __DIR__ . '/../../../includes/auth.php';

/* Helpers (defined here so this file is self-contained) */
// NOTE: json_response()/json_input() themselves were missing here despite
// this comment - they're only defined in api/v1/index.php's router, but
// this file is called directly by the frontend (assets/js/favorites.js),
// bypassing the router entirely. Every real click of the favorite-heart
// icon site-wide sends X-Requested-With: XMLHttpRequest, which is_ajax()
// below correctly detects - routing every real request straight into a
// json_response() call that didn't exist, fatally crashing every single
// favorite/unfavorite attempt. Confirmed live before adding these.
if (!function_exists('json_response')) {
    function json_response($data, int $code = 200): void {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
if (!function_exists('json_input')) {
    function json_input(): array {
        $raw = file_get_contents('php://input');
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : $_POST;
    }
}
function is_ajax(): bool {
    return (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest')
        || (strtolower($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json');
}

// --- Auth check ---
if (!is_logged_in()) {
    if (is_ajax()) {
        json_response(['error' => t('errors.unauthorized')], 401);
    } else {
        flash_set('info', t('errors.unauthorized'));
        redirect(APP_URL . '/pages/login.php?next=' . urlencode($_SERVER['HTTP_REFERER'] ?? ''));
    }
}

$uid = (int) current_user()['id'];

// --- GET: list favorites ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = db()->prepare(
        'SELECT f.created_at, l.id AS listing_id, l.title, l.slug, l.price, l.currency, l.listing_type,
                (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image
         FROM favorites f
         INNER JOIN listings l ON l.id = f.listing_id
         WHERE f.user_id = ?
         ORDER BY f.created_at DESC'
    );
    $stmt->execute([$uid]);
    json_response(['count' => $stmt->rowCount(), 'data' => $stmt->fetchAll()]);
}

// --- POST: add OR remove favorite ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check: accept either the classic form field or the X-CSRF-Token
    // header the AJAX client (favorites.js) already sends. This closes a gap
    // where a cross-site form/link could toggle a favorite on behalf of a
    // logged-in user (the client-side token was being sent but never verified).
    if (!csrf_check_request()) {
        if (is_ajax()) {
            json_response(['error' => t('errors.invalid_token')], 403);
        } else {
            flash_set('error', t('errors.invalid_token'));
            redirect_back(APP_URL . '/pages/favorites.php');
        }
    }

    // Read listing_id from JSON body, URL param, or form
    $data = json_input();
    if (!isset($data['listing_id'])) {
        $data['listing_id'] = $_GET['id'] ?? $_POST['listing_id'] ?? null;
    }
    $listingId = (int)($data['listing_id'] ?? 0);
    if (!$listingId) json_response(['error' => 'listing_id is required'], 400);

    // Determine action: 'add' (default) or 'remove'
    $action = $_GET['action'] ?? $data['action'] ?? 'add';

    // Verify listing exists
    $check = db()->prepare('SELECT id FROM listings WHERE id = ? LIMIT 1');
    $check->execute([$listingId]);
    if (!$check->fetch()) {
        json_response(['error' => t('errors.listing_not_found')], 404);
    }

    // Check current favorite state
    $stmt = db()->prepare('SELECT 1 FROM favorites WHERE user_id = ? AND listing_id = ?');
    $stmt->execute([$uid, $listingId]);
    $isFav = (bool)$stmt->fetch();

    if ($action === 'remove' || ($action === 'toggle' && $isFav)) {
        db()->prepare('DELETE FROM favorites WHERE user_id = ? AND listing_id = ?')->execute([$uid, $listingId]);
        $newState = 'removed';
        $message = t('flash.favorite_removed');
    } else {
        try {
            db()->prepare('INSERT IGNORE INTO favorites (user_id, listing_id) VALUES (?, ?)')->execute([$uid, $listingId]);
            $newState = 'saved';
            $message = t('flash.favorite_added');
        } catch (Throwable $e) {
            json_response(['error' => t('errors.unknown')], 500);
        }
    }

    // Update the denormalized favorites_count on the listing
    db()->prepare('UPDATE listings SET favorites_count = (SELECT COUNT(*) FROM favorites WHERE listing_id = ?) WHERE id = ?')
        ->execute([$listingId, $listingId]);

    // Read back the new count
    $countStmt = db()->prepare('SELECT favorites_count FROM listings WHERE id = ?');
    $countStmt->execute([$listingId]);
    $newCount = (int)$countStmt->fetchColumn();

    if (is_ajax()) {
        json_response([
            'success' => true,
            'state' => $newState,
            'favorites_count' => $newCount,
            'message' => $message,
        ]);
    } else {
        // Non-AJAX: redirect back with a flash message
        flash_set($newState === 'saved' ? 'success' : 'info', $message);
        $referer = $_SERVER['HTTP_REFERER'] ?? (APP_URL . '/pages/favorites.php');
        redirect($referer);
    }
}

json_response(['error' => t('errors.method_not_allowed')], 405);
