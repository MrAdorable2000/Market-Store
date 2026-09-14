<?php
/** api/v1/categories/index.php — GET /api/v1/categories */
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
