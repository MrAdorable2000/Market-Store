<?php
/**
 * api/v1/checkout/index.php
 * --------------------------------------------------------------------
 * JSON API mirror of pages/checkout.php for the mobile app. This is a
 * transcription, not a rewrite — every validation rule, the exact
 * per-item order-splitting fix (one order per listing, delivery fee only
 * on the first item per seller, platform fee per item), the wallet
 * pre-check, and the transaction/rollback structure are copied verbatim
 * from the already-audited and live-tested pages/checkout.php. Nothing
 * about the money-handling logic is new here — only the response format
 * (JSON instead of flash+redirect) and the summary GET.
 *
 *   GET  /api/v1/checkout/index.php
 *     -> { sellers: [...], delivery_methods: [...], addresses: [...],
 *          wallet_balance, grand_total_estimate }
 *
 *   POST /api/v1/checkout/index.php
 *     { "delivery_method_id": N, "address_id": N|null,
 *       "pickup_point_id": N|null, "payment_method": "wallet", "notes": "" }
 *     -> { "message": "N order(s) placed", "order_count": N } on success
 *     -> { "error": "..." } with an appropriate status code on failure
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/wallet.php';
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

// --- Load cart items (grouped by seller) — identical query to
// pages/checkout.php, including the same eligibility filter (active,
// available, sell-type only). ---
$stmt = $pdo->prepare(
    "SELECT ci.id AS cart_id, ci.quantity,
            l.id AS listing_id, l.title, l.slug, l.price, l.currency,
            l.seller_id, l.status, l.availability, l.listing_type,
            u.full_name AS seller_name
       FROM cart_items ci
       INNER JOIN listings l ON l.id = ci.listing_id
       INNER JOIN users u ON u.id = l.seller_id
      WHERE ci.user_id = ?
        AND l.status = 'active' AND l.availability = 'available' AND l.listing_type = 'sell'
      ORDER BY ci.created_at DESC"
);
$stmt->execute([$uid]);
$cartItems = $stmt->fetchAll();

if (empty($cartItems)) {
    json_response(['error' => 'Your cart is empty'], 400);
}

$groups = [];
foreach ($cartItems as $item) {
    $sid = (int) $item['seller_id'];
    if (!isset($groups[$sid])) {
        $groups[$sid] = ['seller_id' => $sid, 'seller_name' => $item['seller_name'], 'items' => [], 'subtotal' => 0];
    }
    $groups[$sid]['items'][] = $item;
    $groups[$sid]['subtotal'] += (float) $item['price'] * (int) $item['quantity'];
}

$deliveryMethods = $pdo->query("SELECT * FROM delivery_methods WHERE is_active = 1 ORDER BY display_order")->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, created_at DESC");
$stmt->execute([$uid]);
$addresses = $stmt->fetchAll();

$walletBal = wallet_balance($uid);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $summary = [];
    foreach ($groups as $g) {
        $summary[] = [
            'seller_id' => $g['seller_id'],
            'seller_name' => $g['seller_name'],
            'subtotal' => $g['subtotal'],
            'items' => array_map(fn ($i) => [
                'listing_id' => (int) $i['listing_id'],
                'title' => $i['title'],
                'price' => (float) $i['price'],
                'quantity' => (int) $i['quantity'],
            ], $g['items']),
        ];
    }
    json_response([
        'sellers' => $summary,
        'delivery_methods' => array_map(fn ($m) => [
            'id' => (int) $m['id'],
            'code' => $m['code'],
            'label' => $m['label'] ?? $m['code'],
            'base_fee' => (float) $m['base_fee'],
        ], $deliveryMethods),
        'addresses' => array_map(fn ($a) => [
            'id' => (int) $a['id'],
            'label' => $a['label'] ?? null,
            'line1' => $a['line1'] ?? null,
            'is_default' => (bool) ($a['is_default'] ?? false),
        ], $addresses),
        'wallet_balance' => (float) $walletBal['available'],
        'currency' => 'RWF',
    ]);
}

// --- POST: process checkout — logic below is a verbatim transcription of
// pages/checkout.php's POST handler (see that file's comments for the
// full history of the order-splitting bug this structure fixes). ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$data = json_input();
$csrfToken = (string) ($data['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!hash_equals((string) ($_SESSION[CSRF_TOKEN_NAME] ?? ''), $csrfToken)) {
    json_response(['error' => 'Invalid or missing CSRF token'], 403);
}

$deliveryMethodId = (int) ($data['delivery_method_id'] ?? 0);
$addressId = (int) ($data['address_id'] ?? 0);
$pickupPointId = (int) ($data['pickup_point_id'] ?? 0);
$paymentMethod = $data['payment_method'] ?? 'wallet';
$notes = trim((string) ($data['notes'] ?? ''));

$dm = null;
foreach ($deliveryMethods as $m) {
    if ((int) $m['id'] === $deliveryMethodId) { $dm = $m; break; }
}
if (!$dm) json_response(['error' => 'Please select a delivery method'], 400);

if (in_array($dm['code'], ['home_delivery', 'express', 'scheduled'], true) && !$addressId) {
    json_response(['error' => 'Please select a delivery address'], 400);
}

$provider = get_payment_provider($paymentMethod);
if (!$provider || !$provider->isConfigured()) {
    json_response(['error' => 'Selected payment method is not available'], 400);
}

$pdo->beginTransaction();
try {
    $deliveryFee = (float) ($dm['base_fee'] ?? 0);
    $totalOrders = 0;
    $insufficientBalance = false;

    if ($paymentMethod === 'wallet') {
        $grandTotalAll = 0;
        foreach ($groups as $g) {
            $platformFee = round($g['subtotal'] * 0.05, 2);
            $grandTotalAll += $g['subtotal'] + $deliveryFee + $platformFee;
        }
        if ($walletBal['available'] < $grandTotalAll) {
            $insufficientBalance = true;
            json_response([
                'error' => 'Insufficient wallet balance',
                'required' => $grandTotalAll,
                'available' => $walletBal['available'],
            ], 400);
        }
    }

    if (!$insufficientBalance) {
        foreach ($groups as $g) {
            $sellerId = $g['seller_id'];
            foreach ($g['items'] as $idx => $item) {
                $listingId = (int) $item['listing_id'];
                $quantity = (int) $item['quantity'];
                $unitPrice = (float) $item['price'];
                $itemSubtotal = $unitPrice * $quantity;
                $itemPlatformFee = round($itemSubtotal * 0.05, 2);
                $itemDeliveryFee = ($idx === 0) ? $deliveryFee : 0.0;
                $itemGrandTotal = $itemSubtotal + $itemPlatformFee + $itemDeliveryFee;
                $orderNumber = generate_order_number();

                $stmt = $pdo->prepare(
                    'INSERT INTO orders
                        (order_number, buyer_id, seller_id, listing_id, delivery_method_id,
                         delivery_address_id, pickup_point_id, quantity, unit_price,
                         subtotal, delivery_fee, platform_fee, grand_total, currency,
                         status, payment_status, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "RWF", "pending", "unpaid", ?)'
                );
                $stmt->execute([
                    $orderNumber, $uid, $sellerId, $listingId, $deliveryMethodId,
                    $addressId ?: null, $pickupPointId ?: null,
                    $quantity, $unitPrice, $itemSubtotal, $itemDeliveryFee, $itemPlatformFee, $itemGrandTotal, $notes,
                ]);
                $orderId = (int) $pdo->lastInsertId();

                $paymentId = create_payment($orderId, $uid, $itemGrandTotal, $paymentMethod);

                if ($paymentMethod === 'wallet') {
                    $result = $provider->initiate($paymentId, $itemGrandTotal, 'RWF');
                    if ($result['status'] !== 'successful') {
                        throw new RuntimeException('Wallet payment failed: ' . ($result['error'] ?? 'unknown'));
                    }
                }
                $totalOrders++;
            }
        }

        $pdo->prepare('DELETE FROM cart_items WHERE user_id = ?')->execute([$uid]);
        $pdo->commit();
        json_response(['message' => $totalOrders . ' order(s) placed successfully', 'order_count' => $totalOrders], 201);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (PDOException $rb) { /* ignore */ }
    }
    json_response(['error' => 'Checkout failed: ' . $e->getMessage()], 500);
}
