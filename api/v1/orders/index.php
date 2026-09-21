<?php
/**
 * api/v1/orders/index.php
 * --------------------------------------------------------------------
 * JSON API mirror of pages/orders.php for the mobile app — a
 * transcription of the same authorization checks and action handlers
 * (including the malformed-SQL and missing-transaction fixes already
 * applied there earlier in this audit), not a rewrite.
 *
 *   GET  /api/v1/orders/index.php?view=buyer|seller
 *     -> { orders: [...] }  (list)
 *   GET  /api/v1/orders/index.php?id=N
 *     -> { order: {...}, tracking: [...] }  (detail)
 *
 *   POST /api/v1/orders/index.php
 *     { "action": "confirm_received", "order_id": N }
 *     { "action": "cancel", "order_id": N }
 *     { "action": "update_status", "order_id": N, "new_status": "shipped" }
 *     { "action": "open_dispute", "order_id": N, "reason": "...", "description": "..." }
 *     { "action": "submit_review", "order_id": N, "rating": 1-5, "comment": "..." }
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/wallet.php';
require_once __DIR__ . '/../../../includes/notification_service.php';
require_once __DIR__ . '/../../../includes/payment.php';

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
    $csrfToken = (string) ($data['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals((string) ($_SESSION[CSRF_TOKEN_NAME] ?? ''), $csrfToken)) {
        json_response(['error' => 'Invalid or missing CSRF token'], 403);
    }

    $action = $data['action'] ?? '';
    $targetOrderId = (int) ($data['order_id'] ?? 0);

    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$targetOrderId]);
    $order = $stmt->fetch();
    if (!$order) json_response(['error' => 'Order not found'], 404);

    $isBuyer = (int) $order['buyer_id'] === $uid;
    $isSeller = (int) $order['seller_id'] === $uid;
    $isAdmin = is_admin();

    switch ($action) {
        case 'confirm_received':
            if (!$isBuyer) json_response(['error' => 'Not authorized'], 403);
            try {
                release_escrow_to_seller($targetOrderId, $uid);
                json_response(['message' => 'Order confirmed — funds released to seller']);
            } catch (Throwable $e) {
                json_response(['error' => $e->getMessage()], 400);
            }
            break;

        case 'cancel':
            if (!$isBuyer || $order['status'] !== 'pending') {
                json_response(['error' => 'Cannot cancel this order'], 400);
            }
            try {
                $pdo->beginTransaction();
                if ($order['payment_status'] === 'escrow_held') {
                    wallet_refund($uid, (float) $order['grand_total'], $targetOrderId, 'Order cancelled');
                }
                $pdo->prepare("UPDATE orders SET status='cancelled', payment_status='refunded', cancelled_at=NOW() WHERE id=?")
                    ->execute([$targetOrderId]);
                $pdo->prepare("INSERT INTO delivery_tracking (order_id, status, note, created_by) VALUES (?, 'cancelled', 'Order cancelled by buyer', ?)")
                    ->execute([$targetOrderId, $uid]);
                $pdo->commit();
                json_response(['message' => 'Order cancelled — any held funds have been refunded']);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    try { $pdo->rollBack(); } catch (PDOException $rb) { /* ignore */ }
                }
                json_response(['error' => 'Could not cancel this order'], 500);
            }
            break;

        case 'update_status':
            if (!$isSeller && !$isAdmin) json_response(['error' => 'Not authorized'], 403);
            $newStatus = $data['new_status'] ?? '';
            $allowed = ['preparing', 'ready', 'shipped', 'out_for_delivery', 'delivered'];
            if (!in_array($newStatus, $allowed, true)) json_response(['error' => 'Invalid status'], 400);

            $pdo->prepare("UPDATE orders SET delivery_status=?, status=?, updated_at=NOW() WHERE id=?")
                ->execute([$newStatus, $newStatus, $targetOrderId]);
            $pdo->prepare("INSERT INTO delivery_tracking (order_id, status, note, created_by) VALUES (?, ?, ?, ?)")
                ->execute([$targetOrderId, $newStatus, 'Status updated by seller', $uid]);
            notify_critical(
                (int) $order['buyer_id'], 'order', 'Order updated',
                'Your order status is now: ' . $newStatus . '.',
                '/pages/orders.php?id=' . $targetOrderId, 'order_status_' . $newStatus
            );
            if ($newStatus === 'delivered') {
                $pdo->prepare("UPDATE orders SET delivered_at=NOW() WHERE id=?")->execute([$targetOrderId]);
            }
            json_response(['message' => 'Order status updated']);
            break;

        case 'open_dispute':
            if (!$isBuyer) json_response(['error' => 'Not authorized'], 403);
            $reason = trim((string) ($data['reason'] ?? ''));
            $description = trim((string) ($data['description'] ?? ''));
            if (!$reason || !$description) json_response(['error' => 'Reason and description required'], 400);

            $disputeNum = generate_dispute_number();
            $pdo->prepare(
                "INSERT INTO disputes (dispute_number, order_id, opened_by, against_user_id, reason, description)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$disputeNum, $targetOrderId, $uid, (int) $order['seller_id'], $reason, $description]);
            $pdo->prepare("UPDATE orders SET status='disputed' WHERE id=?")->execute([$targetOrderId]);
            json_response(['message' => 'Dispute opened — our team will review it', 'dispute_number' => $disputeNum]);
            break;

        case 'submit_review':
            if (!$isBuyer) json_response(['error' => 'Not authorized'], 403);
            if (!in_array($order['status'], ['delivered', 'completed'], true)) {
                json_response(['error' => 'You can only review an order after it has been delivered'], 400);
            }
            $rating = (int) ($data['rating'] ?? 0);
            $comment = trim((string) ($data['comment'] ?? ''));
            if ($rating < 1 || $rating > 5) json_response(['error' => 'Choose a rating from 1 to 5'], 400);

            try {
                $pdo->prepare(
                    'INSERT INTO reviews (reviewer_id, seller_id, listing_id, rating, comment) VALUES (?, ?, ?, ?, ?)'
                )->execute([$uid, (int) $order['seller_id'], (int) $order['listing_id'], $rating, $comment !== '' ? $comment : null]);
                json_response(['message' => 'Thanks — your review has been posted']);
            } catch (PDOException $e) {
                if ((int) $e->getCode() === 23000) {
                    json_response(['error' => "You've already reviewed this order"], 409);
                }
                throw $e;
            }
            break;

        default:
            json_response(['error' => 'Unknown action'], 400);
    }
}

// --- GET: single order detail ---
$orderId = (int) ($_GET['id'] ?? 0);
if ($orderId) {
    $stmt = $pdo->prepare(
        "SELECT o.*, l.title, l.slug,
                (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image,
                dm.name AS delivery_method_name, dm.code AS delivery_method_code,
                addr.full_name AS addr_name, addr.phone AS addr_phone, addr.street, addr.sector, addr.district, addr.province, addr.country,
                pp.name AS pickup_point_name, pp.address AS pickup_point_address,
                buyer.full_name AS buyer_name, buyer.email AS buyer_email,
                seller.full_name AS seller_name, seller.email AS seller_email,
                p.payment_reference, p.method AS payment_method, p.provider_reference, p.status AS payment_status_detail, p.verified
           FROM orders o
           INNER JOIN listings l ON l.id = o.listing_id
           INNER JOIN users buyer ON buyer.id = o.buyer_id
           INNER JOIN users seller ON seller.id = o.seller_id
           LEFT JOIN delivery_methods dm ON dm.id = o.delivery_method_id
           LEFT JOIN addresses addr ON addr.id = o.delivery_address_id
           LEFT JOIN pickup_points pp ON pp.id = o.pickup_point_id
           LEFT JOIN payments p ON p.order_id = o.id AND p.status = 'successful'
          WHERE o.id = ?"
    );
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) json_response(['error' => 'Order not found'], 404);

    $isBuyer = (int) $order['buyer_id'] === $uid;
    $isSeller = (int) $order['seller_id'] === $uid;
    if (!$isBuyer && !$isSeller && !is_admin()) {
        json_response(['error' => 'Not authorized'], 403);
    }

    $stmt = $pdo->prepare("SELECT * FROM delivery_tracking WHERE order_id = ? ORDER BY created_at ASC");
    $stmt->execute([$orderId]);
    $tracking = $stmt->fetchAll();

    $reviewStmt = $pdo->prepare('SELECT id FROM reviews WHERE reviewer_id = ? AND seller_id = ? AND listing_id = ?');
    $reviewStmt->execute([$uid, (int) $order['seller_id'], (int) $order['listing_id']]);

    json_response([
        'order' => $order,
        'tracking' => $tracking,
        'is_buyer' => $isBuyer,
        'is_seller' => $isSeller,
        'already_reviewed' => (bool) $reviewStmt->fetch(),
    ]);
}

// --- GET: order list ---
$view = ($_GET['view'] ?? 'buyer') === 'seller' ? 'seller' : 'buyer';
$where = $view === 'seller' ? 'o.seller_id = ?' : 'o.buyer_id = ?';
$stmt = $pdo->prepare(
    "SELECT o.*, l.title,
            (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image,
            other.full_name AS other_party_name
       FROM orders o
       INNER JOIN listings l ON l.id = o.listing_id
       INNER JOIN users other ON other.id = " . ($view === 'seller' ? 'o.buyer_id' : 'o.seller_id') . "
      WHERE $where
      ORDER BY o.created_at DESC"
);
$stmt->execute([$uid]);
json_response(['orders' => $stmt->fetchAll()]);
