<?php
/** api/v1/rentals/index.php — GET (list) or POST (create request) */
require_once __DIR__ . '/../../../includes/auth.php';
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
    $listingId = (int)($data['listing_id'] ?? 0);
    $start = $data['start_date'] ?? '';
    $end   = $data['end_date']   ?? '';
    $msg   = $data['message']     ?? '';
    if (!$listingId || !$start || !$end) json_response(['error' => 'listing_id, start_date, end_date required'], 400);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        json_response(['error' => 'Invalid date format (YYYY-MM-DD)'], 400);
    }

    $stmt = db()->prepare(
        'INSERT INTO rental_requests (listing_id, renter_id, start_date, end_date, message)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$listingId, $uid, $start, $end, $msg]);
    json_response(['message' => 'Rental request sent', 'id' => (int) db()->lastInsertId()], 201);
}

json_response(['error' => 'Method not allowed'], 405);
