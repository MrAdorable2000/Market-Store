<?php
/**
 * pages/checkout.php — Checkout flow
 * --------------------------------------------------------------------
 * Cart → Delivery Method → Address → Payment → Order Review → Confirm
 *
 * Creates one order per seller (multi-seller checkout). Each order:
 *   1. INSERT into orders (status=pending, payment_status=unpaid)
 *   2. INSERT into payments (status=pending)
 *   3. If paying by wallet: process immediately (escrow hold, status=confirmed)
 *   4. If paying by external provider: redirect to provider (architecture only)
 *   5. Clear the purchased cart items
 *
 * SECURITY: Prices are read from the DB (never from $_POST). The buyer's
 * wallet balance is checked server-side. Escrow is held atomically.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/payment.php';
require_login();

$uid = (int) current_user()['id'];
$pdo = db();

// --- Load cart items (grouped by seller) ---
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
    flash_set('info', 'Your cart is empty.');
    redirect(APP_URL . '/pages/cart.php');
}

// Group by seller
$groups = [];
foreach ($cartItems as $item) {
    $sid = (int)$item['seller_id'];
    if (!isset($groups[$sid])) $groups[$sid] = ['seller_id' => $sid, 'seller_name' => $item['seller_name'], 'items' => [], 'subtotal' => 0];
    $groups[$sid]['items'][] = $item;
    $groups[$sid]['subtotal'] += (float)$item['price'] * (int)$item['quantity'];
}

// Load delivery methods
$deliveryMethods = $pdo->query("SELECT * FROM delivery_methods WHERE is_active = 1 ORDER BY display_order")->fetchAll();

// Load user's saved addresses
$stmt = $pdo->prepare("SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, created_at DESC");
$stmt->execute([$uid]);
$addresses = $stmt->fetchAll();

// Load wallet balance
$walletBal = wallet_balance($uid);

// --- Handle checkout POST ---
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = t('errors.invalid_token');
    } else {
        $deliveryMethodId = (int)($_POST['delivery_method_id'] ?? 0);
        $addressId = (int)($_POST['address_id'] ?? 0);
        $pickupPointId = (int)($_POST['pickup_point_id'] ?? 0);
        $paymentMethod = $_POST['payment_method'] ?? 'wallet';
        $notes = trim($_POST['notes'] ?? '');

        // Validate delivery method
        $dm = null;
        foreach ($deliveryMethods as $m) {
            if ((int)$m['id'] === $deliveryMethodId) { $dm = $m; break; }
        }
        if (!$dm) $errors[] = 'Please select a delivery method.';

        // Validate address for home delivery
        if ($dm && in_array($dm['code'], ['home_delivery', 'express', 'scheduled'], true)) {
            if (!$addressId) $errors[] = 'Please select a delivery address.';
        }

        // Validate payment method
        $provider = get_payment_provider($paymentMethod);
        if (!$provider || !$provider->isConfigured()) {
            $errors[] = 'Selected payment method is not available.';
        }

        if (empty($errors)) {
            // Process each seller group as a separate order
            $pdo->beginTransaction();
            try {
                $deliveryFee = (float)($dm['base_fee'] ?? 0);
                $totalOrders = 0;
                $totalPaid = 0.0;
                $insufficientBalance = false;

                // First, check if wallet has enough for ALL orders combined
                if ($paymentMethod === 'wallet') {
                    $grandTotalAll = 0;
                    foreach ($groups as $g) {
                        $platformFee = round($g['subtotal'] * 0.05, 2); // 5% platform fee
                        $grandTotalAll += $g['subtotal'] + $deliveryFee + $platformFee;
                    }
                    if ($walletBal['available'] < $grandTotalAll) {
                        $insufficientBalance = true;
                        $errors[] = 'Insufficient wallet balance. You need ' . format_price($grandTotalAll, 'RWF') . ' but have ' . format_price($walletBal['available'], 'RWF') . '. Please deposit funds first.';
                    }
                }

                if (!$insufficientBalance) {
                    foreach ($groups as $g) {
                        $sellerId = $g['seller_id'];
                        $platformFee = round($g['subtotal'] * 0.05, 2); // 5% platform fee, on the seller's whole cart subtotal

                        // IMPORTANT: this schema is one-order-per-listing (orders.listing_id is
                        // singular, there is no order_items table), but a buyer can have several
                        // *different* listings from the same seller in their cart at once
                        // (cart_items has UNIQUE(user_id, listing_id), not per-seller). Previously
                        // this loop only ever recorded $g['items'][0] while still charging the
                        // wallet for every item in the group — the buyer was billed correctly but
                        // every item after the first silently vanished from the order/seller view.
                        // Fix: create one order per listing. The seller's single delivery fee is
                        // applied once (to the first order in the group) rather than once per
                        // item, so the buyer isn't double-charged for one shipment; the platform
                        // fee is distributed per item so each order's numbers reconcile on their
                        // own (unit_price × quantity + its share of fees = its grand_total).
                        $itemCount = count($g['items']);
                        foreach ($g['items'] as $idx => $item) {
                            $listingId  = (int) $item['listing_id'];
                            $quantity   = (int) $item['quantity'];
                            $unitPrice  = (float) $item['price'];
                            $itemSubtotal = $unitPrice * $quantity;
                            $itemPlatformFee = round($itemSubtotal * 0.05, 2);
                            $itemDeliveryFee = ($idx === 0) ? $deliveryFee : 0.0; // one shipment per seller, not per item
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
                            $orderId = (int)$pdo->lastInsertId();

                            // Create payment record
                            $paymentId = create_payment($orderId, $uid, $itemGrandTotal, $paymentMethod);

                            // If wallet payment, process immediately
                            if ($paymentMethod === 'wallet') {
                                $result = $provider->initiate($paymentId, $itemGrandTotal, 'RWF');
                                if ($result['status'] !== 'successful') {
                                    throw new RuntimeException('Wallet payment failed: ' . ($result['error'] ?? 'unknown'));
                                }
                                $totalPaid += $itemGrandTotal;
                            }

                            $totalOrders++;
                        }
                    }

                    // Clear cart items that were purchased
                    $pdo->prepare('DELETE FROM cart_items WHERE user_id = ?')->execute([$uid]);

                    $pdo->commit();
                    flash_set('success', $totalOrders . ' order(s) placed successfully!');
                    redirect(APP_URL . '/pages/orders.php');
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    try { $pdo->rollBack(); } catch (PDOException $rb) { /* ignore */ }
                }
                $errors[] = 'Checkout failed: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Checkout';
$activePage = 'checkout';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container" style="max-width:960px;padding:24px 20px 48px;">
    <div style="margin-bottom:14px;"><?php echo back_button(APP_URL . '/pages/cart.php', 'Back to cart', 'solid'); ?></div>
    <h1 style="font-size:24px;font-weight:800;margin:0 0 20px;letter-spacing:-.02em;">Checkout</h1>

    <?php if ($errors): ?>
        <div class="flash flash--error" role="alert">
            <span class="flash__icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg></span>
            <span class="flash__text"><?php echo e($errors[0]); ?></span>
        </div>
    <?php endif; ?>

    <form method="post" action="" id="checkoutForm">
        <?php echo csrf_field(); ?>

        <div style="display:grid;grid-template-columns:1fr 340px;gap:24px;align-items:start;" class="checkout-layout">
            <div>
                <!-- Order items summary -->
                <div class="card" style="padding:18px;margin-bottom:16px;">
                    <h3 style="margin:0 0 12px;font-size:15px;font-weight:800;">Order Items (<?php echo count($cartItems); ?>)</h3>
                    <?php foreach ($groups as $g): ?>
                        <div style="padding:10px 0;border-bottom:1px solid var(--bg-soft);">
                            <div style="font-size:12px;font-weight:700;color:var(--text-mute);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;">From: <?php echo e($g['seller_name']); ?></div>
                            <?php foreach ($g['items'] as $item): ?>
                                <div style="display:flex;justify-content:space-between;font-size:13.5px;color:var(--text-soft);padding:3px 0;">
                                    <span><?php echo e($item['title']); ?> × <?php echo (int)$item['quantity']; ?></span>
                                    <strong style="color:var(--text);"><?php echo e(format_price((float)$item['price'] * (int)$item['quantity'], $item['currency'])); ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Delivery method -->
                <div class="card" style="padding:18px;margin-bottom:16px;">
                    <h3 style="margin:0 0 12px;font-size:15px;font-weight:800;">Delivery Method</h3>
                    <div style="display:grid;gap:8px;">
                        <?php foreach ($deliveryMethods as $dm): ?>
                            <label style="display:flex;align-items:center;gap:10px;padding:12px 14px;border:1.5px solid var(--border);border-radius:10px;cursor:pointer;transition:all 160ms;" class="delivery-option">
                                <input type="radio" name="delivery_method_id" value="<?php echo (int)$dm['id']; ?>" required style="accent-color:var(--brand-500);">
                                <div style="flex:1;">
                                    <strong style="font-size:13.5px;color:var(--text);"><?php echo e($dm['name']); ?></strong>
                                    <div style="font-size:12px;color:var(--text-mute);"><?php echo e($dm['description']); ?></div>
                                </div>
                                <span style="font-size:13px;font-weight:700;color:var(--brand-600);"><?php echo $dm['base_fee'] > 0 ? e(format_price((float)$dm['base_fee'], 'RWF')) : 'Free'; ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Address (shown only for home delivery) -->
                <div class="card" id="addressCard" style="padding:18px;margin-bottom:16px;display:none;">
                    <h3 style="margin:0 0 12px;font-size:15px;font-weight:800;">Delivery Address</h3>
                    <?php if (empty($addresses)): ?>
                        <p style="font-size:13px;color:var(--text-mute);margin:0 0 10px;">No saved addresses. Please add one on your profile.</p>
                    <?php else: ?>
                        <div style="display:grid;gap:8px;">
                            <?php foreach ($addresses as $addr): ?>
                                <label style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border:1.5px solid var(--border);border-radius:10px;cursor:pointer;">
                                    <input type="radio" name="address_id" value="<?php echo (int)$addr['id']; ?>" style="accent-color:var(--brand-500);margin-top:2px;">
                                    <div style="flex:1;">
                                        <strong style="font-size:13.5px;color:var(--text);"><?php echo e($addr['label']); ?> — <?php echo e($addr['full_name']); ?></strong>
                                        <div style="font-size:12px;color:var(--text-mute);margin-top:2px;">
                                            <?php
                                            $addrParts = [];
                                            if ($addr['street']) $addrParts[] = $addr['street'];
                                            if ($addr['sector']) $addrParts[] = $addr['sector'];
                                            if ($addr['district']) $addrParts[] = $addr['district'];
                                            if ($addr['province']) $addrParts[] = $addr['province'];
                                            if ($addr['country']) $addrParts[] = $addr['country'];
                                            echo e($addr['phone']) . ' • ' . e(implode(', ', $addrParts));
                                            ?>
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Payment method -->
                <div class="card" style="padding:18px;margin-bottom:16px;">
                    <h3 style="margin:0 0 12px;font-size:15px;font-weight:800;">Payment Method</h3>
                    <label style="display:flex;align-items:center;gap:10px;padding:12px 14px;border:1.5px solid var(--brand-200);border-radius:10px;cursor:pointer;background:var(--brand-50);">
                        <input type="radio" name="payment_method" value="wallet" checked style="accent-color:var(--brand-500);">
                        <div style="flex:1;">
                            <strong style="font-size:13.5px;color:var(--text);">Wallet</strong>
                            <div style="font-size:12px;color:var(--text-mute);">Available: <?php echo e(format_price($walletBal['available'], 'RWF')); ?></div>
                        </div>
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="var(--brand-600)" stroke-width="1.6"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4M3 5v14a2 2 0 0 0 2 2h16v-5M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
                    </label>
                    <p style="font-size:11.5px;color:var(--text-mute);margin:10px 0 0;line-height:1.5;">
                        Mobile Money, card, and bank transfer will be available once payment providers are configured by the admin.
                    </p>
                </div>

                <!-- Notes -->
                <div class="card" style="padding:18px;margin-bottom:16px;">
                    <h3 style="margin:0 0 8px;font-size:15px;font-weight:800;">Order Notes (optional)</h3>
                    <textarea name="notes" rows="2" placeholder="Any special instructions for the seller..." style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font-size:13.5px;font-family:inherit;resize:vertical;"></textarea>
                </div>
            </div>

            <!-- Summary -->
            <aside style="position:sticky;top:80px;">
                <div class="card" style="padding:20px;">
                    <h3 style="margin:0 0 14px;font-size:15px;font-weight:800;">Summary</h3>
                    <?php
                    $totalSubtotal = 0; $totalDelivery = 0; $totalPlatform = 0; $grandTotal = 0;
                    foreach ($groups as $g) {
                        $platformFee = round($g['subtotal'] * 0.05, 2);
                        $deliveryFee = 2000; // estimated default; updated by JS when delivery method changes
                        $totalSubtotal += $g['subtotal'];
                        $totalDelivery += $deliveryFee;
                        $totalPlatform += $platformFee;
                        $grandTotal += $g['subtotal'] + $deliveryFee + $platformFee;
                    }
                    ?>
                    <div style="display:flex;justify-content:space-between;font-size:13px;color:var(--text-soft);margin-bottom:8px;">
                        <span>Subtotal</span><strong style="color:var(--text);"><?php echo e(format_price($totalSubtotal, 'RWF')); ?></strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:13px;color:var(--text-soft);margin-bottom:8px;">
                        <span>Delivery (est.)</span><strong style="color:var(--text);" id="deliveryFeeDisplay"><?php echo e(format_price($totalDelivery, 'RWF')); ?></strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:13px;color:var(--text-soft);margin-bottom:8px;">
                        <span>Platform fee (5%)</span><strong style="color:var(--text);"><?php echo e(format_price($totalPlatform, 'RWF')); ?></strong>
                    </div>
                    <hr style="border:0;border-top:1px solid var(--border);margin:12px 0;">
                    <div style="display:flex;justify-content:space-between;font-size:18px;font-weight:800;color:var(--text);">
                        <span>Total</span><span id="grandTotal"><?php echo e(format_price($grandTotal, 'RWF')); ?></span>
                    </div>
                    <button type="submit" class="btn btn--primary btn--block btn--lg" style="margin-top:16px;">Confirm & Pay</button>
                    <p style="font-size:11px;color:var(--text-mute);margin:10px 0 0;text-align:center;line-height:1.4;">
                        By confirming, you agree to our Terms. Payment is held in escrow until you confirm delivery.
                    </p>
                </div>
            </aside>
        </div>
    </form>
</div>

<style>
@media (max-width: 760px) {
    .checkout-layout { grid-template-columns: 1fr !important; }
    .checkout-layout aside { position: static !important; }
}
.delivery-option:has(input:checked) { border-color: var(--brand-500) !important; background: var(--brand-50) !important; }
</style>
<script>
// Show/hide address card based on delivery method selection
document.querySelectorAll('input[name="delivery_method_id"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        var code = this.parentElement.querySelector('span').textContent;
        var addressCard = document.getElementById('addressCard');
        // Show address card for home_delivery, express, scheduled
        var needsAddress = ['1','4','5'].includes(this.value); // IDs from DB
        addressCard.style.display = needsAddress ? 'block' : 'none';
        // Update delivery fee display
        var feeText = this.parentElement.querySelector('span:last-child').textContent;
        document.getElementById('deliveryFeeDisplay').textContent = feeText === 'Free' ? 'RWF 0' : feeText;
    });
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
