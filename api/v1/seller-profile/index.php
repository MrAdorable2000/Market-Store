<?php
/**
 * api/v1/seller-profile/index.php
 * --------------------------------------------------------------------
 * GET /api/v1/seller-profile?id=<user_id>
 *   Returns the seller's profile + their active listings.
 */
require_once __DIR__ . '/../../../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => t('errors.method_not_allowed')], 405);
}

$userId = (int)($_GET['id'] ?? 0);
if (!$userId) json_response(['error' => 'User id required'], 400);

// Seller base info
$stmt = db()->prepare(
    'SELECT u.id, u.full_name, u.email, u.phone, u.location, u.avatar_path, u.is_verified,
            u.created_at, u.bio, sp.*
     FROM users u
     LEFT JOIN seller_profiles sp ON sp.user_id = u.id
     WHERE u.id = ? LIMIT 1'
);
$stmt->execute([$userId]);
$seller = $stmt->fetch();
if (!$seller) json_response(['error' => 'Seller not found'], 404);

unset($seller['password_hash']);   // safety

// Listings
$listingsStmt = db()->prepare(
    'SELECT l.id, l.title, l.slug, l.price, l.currency, l.listing_type, l.location,
            l.created_at, l.views_count, l.favorites_count, l.availability,
            (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image
     FROM listings l
     WHERE l.seller_id = ? AND l.status = "active"
     ORDER BY l.created_at DESC LIMIT 20'
);
$listingsStmt->execute([$userId]);
$seller['listings'] = $listingsStmt->fetchAll();

json_response(['data' => $seller]);
