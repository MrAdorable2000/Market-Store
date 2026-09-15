<?php
/** api/v1/categories/index.php — GET /api/v1/categories */
declare(strict_types=1);
// Self-contained requires so this endpoint also works if a web server's
// directory-index resolution reaches this file directly (bypassing the
// api/v1/index.php router) — confirmed to happen under both Apache
// DirectoryIndex and FrankenPHP's static file server for a bare directory
// URL. require_once makes this a no-op when the router already loaded them.
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

$slug = $_GET['slug'] ?? '';
if ($slug) {
    $stmt = db()->prepare(
        'SELECT c.*, (SELECT COUNT(*) FROM listings l WHERE l.category_id = c.id AND l.status = "active") AS listings_count
         FROM categories c WHERE c.slug = ? LIMIT 1'
    );
    $stmt->execute([$slug]);
    $cat = $stmt->fetch();
    if (!$cat) json_response(['error' => 'Category not found'], 404);
    json_response(['data' => $cat]);
}

$limit  = min(50, (int)($_GET['limit'] ?? 50));
$stmt = db()->prepare(
    'SELECT c.id, c.name, c.slug, c.description, c.display_order,
            (SELECT COUNT(*) FROM listings l WHERE l.category_id = c.id AND l.status = "active") AS listings_count
     FROM categories c
     WHERE c.parent_id IS NULL AND c.is_active = 1
     ORDER BY c.display_order ASC LIMIT ?'
);
$stmt->execute([$limit]);
json_response([
    'count' => $stmt->rowCount(),
    'data'  => $stmt->fetchAll(),
]);
