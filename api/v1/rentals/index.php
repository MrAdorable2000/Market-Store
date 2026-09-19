<?php
/** api/v1/rentals/index.php — GET (list) or POST (create request) */
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/auth.php';
// Self-contained json_input()/json_response() fallback — this endpoint is
// called directly by the frontend (pages/listing-details.php's rental
// request form posts straight here, not through api/v1/index.php), and
// neither function is defined anywhere in the auth.php/functions.php
// require chain. Without this, EVERY request here - GET or POST, valid or
// invalid - throws "Call to undefined function json_response()" before
// producing any response at all. Confirmed live: both listing existing
// rental requests and submitting a new one were completely broken.
// Matches the identical fix already applied to api/v1/categories/index.php
// and api/v1/listings/index.php earlier in this audit.
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
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) return $decoded;
        }
        return $_POST;
    }
}
require_login();
$uid = current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = db()->prepare(
        'SELECT r.id, r.start_date, r.end_date, r.status, r.created_at,
                l.id AS listing_id, l.title, l.price_per_day, l.price_per_week, l.price_per_month, l.currency
         FROM rental_requests r
         INNER JOIN listings l ON l.id = r.listing_id
         WHERE r.renter_id = ?
         ORDER BY r.created_at DESC'
    );
    $stmt->execute([$uid]);
    json_response(['count' => $stmt->rowCount(), 'data' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_input();

    // SECURITY: this endpoint accepts a plain HTML form POST (see the
    // rental request modal in pages/listing-details.php), not just JSON -
    // a plain form POST needs no CORS preflight, so without a CSRF check
    // any external site could silently submit a rental request on behalf
    // of a logged-in visitor via an auto-submitting cross-site form. There
    // was no csrf_check() anywhere in this file despite the form sending
    // the token; json_input() already falls back to $_POST for form
    // submissions, so the token is present in $data either way.
    $csrfToken = (string) ($data['_csrf'] ?? '');
    if (!hash_equals((string) ($_SESSION[CSRF_TOKEN_NAME] ?? ''), $csrfToken)) {
        json_response(['error' => 'Invalid or missing CSRF token'], 403);
    }

    $listingId = (int)($data['listing_id'] ?? 0);
    $start = $data['start_date'] ?? '';
    $end   = $data['end_date']   ?? '';
    $msg   = $data['message']     ?? '';
    if (!$listingId || !$start || !$end) json_response(['error' => 'listing_id, start_date, end_date required'], 400);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        json_response(['error' => 'Invalid date format (YYYY-MM-DD)'], 400);
    }
    // Neither the DB schema nor any earlier code path checked date order,
    // that the start date isn't already in the past, or that the target
    // listing is actually rentable and not the requester's own - a buyer
    // could previously submit end_date before start_date, or request their
    // own listing.
    $today = date('Y-m-d');
    if ($start < $today) json_response(['error' => 'Start date cannot be in the past'], 400);
    if ($end <= $start) json_response(['error' => 'End date must be after the start date'], 400);

    $listing = db()->prepare("SELECT id, seller_id, listing_type, status FROM listings WHERE id = ?");
    $listing->execute([$listingId]);
    $listing = $listing->fetch();
    if (!$listing) json_response(['error' => 'Listing not found'], 404);
    if ($listing['listing_type'] !== 'rent') json_response(['error' => 'This listing is not available for rent'], 400);
    if ($listing['status'] !== 'active') json_response(['error' => 'This listing is not currently active'], 400);
    if ((int) $listing['seller_id'] === (int) $uid) json_response(['error' => 'You cannot request your own listing'], 400);

    $stmt = db()->prepare(
        'INSERT INTO rental_requests (listing_id, renter_id, start_date, end_date, message)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$listingId, $uid, $start, $end, $msg]);
    json_response(['message' => 'Rental request sent', 'id' => (int) db()->lastInsertId()], 201);
}

json_response(['error' => 'Method not allowed'], 405);
