<?php
/** api/v1/listings/index.php — GET /api/v1/listings, optionally ?id= */
declare(strict_types=1);
// Self-contained requires — see api/v1/categories/index.php for why.
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';
if (!function_exists('json_response')) {
    function json_response($data, int $code = 200): void {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $stmt = db()->prepare(
        'SELECT l.*, c.name AS category_name, c.slug AS category_slug,
                u.full_name AS seller_name, u.is_verified AS seller_verified, u.location AS seller_location
         FROM listings l
         INNER JOIN categories c ON c.id = l.category_id
         INNER JOIN users u ON u.id = l.seller_id
         WHERE l.id = ? AND l.status = "active" LIMIT 1'
    );
    $stmt->execute([$id]);
    $listing = $stmt->fetch();
    if (!$listing) json_response(['error' => 'Listing not found'], 404);

    // Attach images and attributes
    $imgs = db()->prepare('SELECT image_path, is_primary, display_order FROM listing_images WHERE listing_id = ? ORDER BY is_primary DESC, display_order');
    $imgs->execute([$id]);
    $listing['images'] = $imgs->fetchAll();

    $attrs = db()->prepare('SELECT attr_key, attr_value FROM listing_attributes WHERE listing_id = ?');
    $attrs->execute([$id]);
    $listing['attributes'] = $attrs->fetchAll();

    json_response(['data' => $listing]);
}

// List with filters
$q        = trim($_GET['q']         ?? '');
$type     = $_GET['type']           ?? '';
$catSlug  = $_GET['category']       ?? '';
$location = $_GET['location']       ?? '';
$sort     = $_GET['sort']           ?? 'newest';
$limit    = min(50, max(1, (int)($_GET['limit'] ?? 12)));

$where  = ['l.status = "active"', 'l.availability = "available"'];
$params = [];
if ($q !== '')            { $where[] = '(l.title LIKE ? OR l.description LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
if ($type === 'sell' || $type === 'rent') { $where[] = 'l.listing_type = ?'; $params[] = $type; }
if ($catSlug)             { $where[] = 'c.slug = ?'; $params[] = $catSlug; }
if ($location)            { $where[] = 'l.location LIKE ?'; $params[] = "%$location%"; }

$orderSql = match ($sort) {
    'price_asc'  => 'l.price ASC',
    'price_desc' => 'l.price DESC',
    'popular'    => 'l.views_count DESC',
    default      => 'l.created_at DESC',
};

$sql = 'SELECT l.id, l.title, l.slug, l.price, l.currency, l.listing_type, l.location,
               l.condition_state, l.availability, l.views_count, l.favorites_count, l.created_at,
               c.slug AS category_slug, c.name AS category_name,
               (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image,
               u.full_name AS seller_name, u.is_verified AS seller_verified
        FROM listings l
        INNER JOIN categories c ON c.id = l.category_id
        INNER JOIN users u ON u.id = l.seller_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY ' . $orderSql . ' LIMIT ' . $limit;

$stmt = db()->prepare($sql);
$stmt->execute($params);
json_response([
    'count' => $stmt->rowCount(),
    'data'  => $stmt->fetchAll(),
]);
