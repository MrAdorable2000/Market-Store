<?php
/**
 * api/v1/cart/index.php
 * --------------------------------------------------------------------
 * JSON API mirror of pages/cart.php, built for the mobile app — same
 * validation rules, same cart_items table, same ON DUPLICATE KEY
 * quantity-merge behavior. Nothing here changes pages/cart.php itself or
 * how the website's own cart page behaves; this is an additional, separate
 * entry point onto the same data.
 *
 *   GET  /api/v1/cart/index.php
 *     -> { subtotal, sellers: [{ seller_id, seller_name, subtotal, items: [...] }] }
 *
 *   POST /api/v1/cart/index.php   (JSON body or form-encoded)
 *     { "action": "add",    "listing_id": N, "quantity": N }
 *     { "action": "update", "cart_item_id": N, "quantity": N }
 *     { "action": "remove", "cart_item_id": N }
 *     { "action": "clear" }
 *   -> the same shape as GET, so the app can just replace its cart state
 *      with the response after every action.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../config/database.php';

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

if (!is_logged_in()) {
    json_response(['error' => 'Login required'], 401);
}

$uid = (int) current_user()['id'];
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_input();

    // CSRF: accept either the classic field or the X-CSRF-Token header —
    // same dual-path pattern already used for api/v1/favorites/index.php
    // and applied consistently across this audit.
    $csrfToken = (string) ($data['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals((string) ($_SESSION[CSRF_TOKEN_NAME] ?? ''), $csrfToken)) {
        json_response(['error' => 'Invalid or missing CSRF token'], 403);
    }

    $action = $data['action'] ?? '';
    switch ($action) {
        case 'add':
            $listingId = (int) ($data['listing_id'] ?? 0);
            $qty = max(1, (int) ($data['quantity'] ?? 1));
            if (!$listingId) json_response(['error' => 'listing_id is required'], 400);

            $stmt = $pdo->prepare(
                "SELECT id, seller_id, title, price, currency, status, availability, listing_type
                   FROM listings WHERE id = ?"
            );
            $stmt->execute([$listingId]);
            $listing = $stmt->fetch();
            if (!$listing || $listing['status'] !== 'active') {
                json_response(['error' => 'Listing not available'], 404);
            }
            if ($listing['listing_type'] !== 'sell') {
                json_response(['error' => 'This item is for rent, not for sale — use the rental request endpoint instead'], 400);
            }
            if ((int) $listing['seller_id'] === $uid) {
                json_response(['error' => 'You cannot buy your own listing'], 400);
            }
            $pdo->prepare(
                'INSERT INTO cart_items (user_id, listing_id, quantity) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE quantity = quantity + ?'
            )->execute([$uid, $listingId, $qty, $qty]);
            break;

        case 'update':
            $itemId = (int) ($data['cart_item_id'] ?? 0);
            $qty = max(1, min(99, (int) ($data['quantity'] ?? 1)));
            $pdo->prepare('UPDATE cart_items SET quantity = ? WHERE id = ? AND user_id = ?')
                ->execute([$qty, $itemId, $uid]);
            break;

        case 'remove':
            $itemId = (int) ($data['cart_item_id'] ?? 0);
            $pdo->prepare('DELETE FROM cart_items WHERE id = ? AND user_id = ?')
                ->execute([$itemId, $uid]);
            break;

        case 'clear':
            $pdo->prepare('DELETE FROM cart_items WHERE user_id = ?')->execute([$uid]);
            break;

        default:
            json_response(['error' => 'Unknown action'], 400);
    }
    // fall through to the same GET-shaped response below, so the client
    // always has the fresh cart state after any mutation.
}

$stmt = $pdo->prepare(
    "SELECT ci.id AS cart_id, ci.quantity,
            l.id AS listing_id, l.title, l.slug, l.price, l.currency, l.availability, l.status,
            l.seller_id,
            u.full_name AS seller_name,
            (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image
       FROM cart_items ci
       INNER JOIN listings l ON l.id = ci.listing_id
       INNER JOIN users u ON u.id = l.seller_id
      WHERE ci.user_id = ?
      ORDER BY ci.created_at DESC"
);
$stmt->execute([$uid]);
$cartItems = $stmt->fetchAll();

$bySeller = [];
$subtotal = 0.0;
foreach ($cartItems as $item) {
    $sid = (int) $item['seller_id'];
    if (!isset($bySeller[$sid])) {
        $bySeller[$sid] = [
            'seller_id' => $sid,
            'seller_name' => $item['seller_name'],
            'subtotal' => 0.0,
            'items' => [],
        ];
    }
    $lineTotal = (float) $item['price'] * (int) $item['quantity'];
    $bySeller[$sid]['items'][] = [
        'cart_item_id' => (int) $item['cart_id'],
        'listing_id' => (int) $item['listing_id'],
        'title' => $item['title'],
        'slug' => $item['slug'],
        'price' => (float) $item['price'],
        'currency' => $item['currency'],
        'quantity' => (int) $item['quantity'],
        'line_total' => $lineTotal,
        'available' => $item['status'] === 'active' && $item['availability'] === 'available',
        'image' => $item['image'],
    ];
    $bySeller[$sid]['subtotal'] += $lineTotal;
    $subtotal += $lineTotal;
}

json_response([
    'subtotal' => $subtotal,
    'currency' => 'RWF',
    'sellers' => array_values($bySeller),
]);
