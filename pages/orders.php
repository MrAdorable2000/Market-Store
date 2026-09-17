<?php
/**
 * pages/orders.php — Order management for buyers and sellers
 * --------------------------------------------------------------------
 * ?view=buyer   (default) — shows orders the user placed as a buyer
 * ?view=seller  — shows orders the user received as a seller
 * ?id=N         — shows details of a specific order
 *
 * Buyer actions:  confirm received (releases escrow), cancel (if pending), open dispute
 * Seller actions: mark as preparing, ready, shipped, delivered
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/notification_service.php';
require_once __DIR__ . '/../includes/payment.php';
require_once __DIR__ . '/../includes/seller_sidebar.php';
require_login();

$uid = (int) current_user()['id'];
$pdo = db();
$view = ($_GET['view'] ?? 'buyer') === 'seller' ? 'seller' : 'buyer';
$orderId = (int)($_GET['id'] ?? 0);

// --- Handle POST actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        flash_set('error', t('errors.invalid_token'));
    } else {
        $action = $_POST['action'] ?? '';
        $targetOrderId = (int)($_POST['order_id'] ?? 0);

        // Verify the user is authorized for this order
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$targetOrderId]);
        $order = $stmt->fetch();
        if (!$order) {
            flash_set('error', 'Order not found.');
        } else {
            $isBuyer = (int)$order['buyer_id'] === $uid;
            $isSeller = (int)$order['seller_id'] === $uid;
            $isAdmin = is_admin();

            switch ($action) {
                case 'confirm_received':
                    if (!$isBuyer) { flash_set('error', 'Not authorized.'); break; }
                    try {
                        release_escrow_to_seller($targetOrderId, $uid);
                        flash_set('success', 'Order confirmed! Funds released to seller.');
                    } catch (Throwable $e) {
                        flash_set('error', $e->getMessage());
                    }
                    break;

                case 'cancel':
                    if (!$isBuyer || $order['status'] !== 'pending') { flash_set('error', 'Cannot cancel.'); break; }
                    try {
                        $pdo->beginTransaction();
                        // Refund the buyer if payment was already held
                        if ($order['payment_status'] === 'escrow_held') {
                            wallet_refund($uid, (float)$order['grand_total'], $targetOrderId, 'Order cancelled');
                        }
                        $pdo->prepare("UPDATE orders SET status='cancelled', payment_status='refunded', cancelled_at=NOW() WHERE id=?")->execute([$targetOrderId]);
                        // NOTE: this INSERT previously declared 4 columns but supplied only 3
                        // values (with the two literals in the wrong positions, and only one
                        // ? for two bound params) - it threw a column/parameter-count
                        // PDOException on every single call, after the refund and status
                        // update above had already committed (no transaction was wrapping
                        // this before). Fixed the column/value alignment and wrapped the
                        // whole action in one transaction so a failure here can no longer
                        // leave the order refunded-but-not-marked-cancelled.
                        $pdo->prepare("INSERT INTO delivery_tracking (order_id, status, note, created_by) VALUES (?, 'cancelled', 'Order cancelled by buyer', ?)")->execute([$targetOrderId, $uid]);
                        $pdo->commit();
                        flash_set('success', 'Order cancelled. Any held funds have been refunded.');
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            try { $pdo->rollBack(); } catch (PDOException $rb) { /* ignore */ }
                        }
                        flash_set('error', 'Could not cancel this order. Please try again.');
                    }
                    break;

                case 'update_status':
                    if (!$isSeller && !$isAdmin) { flash_set('error', 'Not authorized.'); break; }
                    $newStatus = $_POST['new_status'] ?? '';
                    $allowed = ['preparing','ready','shipped','out_for_delivery','delivered'];
                    if (!in_array($newStatus, $allowed, true)) { flash_set('error', 'Invalid status.'); break; }
                    $pdo->prepare("UPDATE orders SET delivery_status=?, status=?, updated_at=NOW() WHERE id=?")->execute([$newStatus, $newStatus, $targetOrderId]);
                    $pdo->prepare("INSERT INTO delivery_tracking (order_id, status, note, created_by) VALUES (?, ?, ?, ?)")->execute([$targetOrderId, $newStatus, 'Status updated by seller', $uid]);
                    // Notify buyer through in-app/email/SMS channels when configured.
                    notify_critical(
                        (int)$order['buyer_id'],
                        'order',
                        'Order updated',
                        'Your order status is now: ' . $newStatus . '.',
                        '/pages/orders.php?id=' . $targetOrderId,
                        'order_status_' . $newStatus
                    );
                    if ($newStatus === 'delivered') {
                        $pdo->prepare("UPDATE orders SET delivered_at=NOW() WHERE id=?")->execute([$targetOrderId]);
                    }
                    flash_set('success', 'Order status updated.');
                    break;

                case 'open_dispute':
                    if (!$isBuyer) { flash_set('error', 'Not authorized.'); break; }
                    $reason = trim($_POST['reason'] ?? '');
                    $description = trim($_POST['description'] ?? '');
                    if (!$reason || !$description) { flash_set('error', 'Reason and description required.'); break; }
                    $disputeNum = generate_dispute_number();
                    $pdo->prepare(
                        "INSERT INTO disputes (dispute_number, order_id, opened_by, against_user_id, reason, description)
                         VALUES (?, ?, ?, ?, ?, ?)"
                    )->execute([$disputeNum, $targetOrderId, $uid, (int)$order['seller_id'], $reason, $description]);
                    $pdo->prepare("UPDATE orders SET status='disputed' WHERE id=?")->execute([$targetOrderId]);
                    flash_set('success', 'Dispute opened. Our team will review it.');
                    break;
            }
        }
        redirect(APP_URL . '/pages/orders.php' . ($orderId ? '?id=' . $orderId : ($view === 'seller' ? '?view=seller' : '')));
    }
}

// --- Single order detail view ---
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
    if (!$order) {
        flash_set('error', 'Order not found.');
        redirect(APP_URL . '/pages/orders.php');
    }
    $isBuyer = (int)$order['buyer_id'] === $uid;
    $isSeller = (int)$order['seller_id'] === $uid;
    if (!$isBuyer && !$isSeller && !is_admin()) {
        flash_set('error', 'Not authorized.');
        redirect(APP_URL . '/pages/orders.php');
    }

    // Load delivery tracking history
    $stmt = $pdo->prepare("SELECT * FROM delivery_tracking WHERE order_id = ? ORDER BY created_at ASC");
    $stmt->execute([$orderId]);
    $tracking = $stmt->fetchAll();

    $pageTitle = 'Order ' . $order['order_number'];
    $activePage = 'dashboard';
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <?php seller_page_start('Order ' . $order['order_number'], $uid, $pdo); ?>
        <div style="margin-bottom:14px;"><?php echo back_button(APP_URL . '/pages/orders.php' . ($isSeller ? '?view=seller' : ''), 'Back to orders', 'solid'); ?></div>

        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
            <div>
                <h1 style="font-size:22px;font-weight:800;margin:0;letter-spacing:-.02em;"><?php echo e($order['order_number']); ?></h1>
                <p style="font-size:13px;color:var(--text-mute);margin:4px 0 0;">Placed <?php echo e(time_ago($order['created_at'])); ?></p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <?php
                $statusColors = ['pending'=>'warn','confirmed'=>'brand','preparing'=>'brand','ready'=>'brand','shipped'=>'blue','out_for_delivery'=>'blue','delivered'=>'good','completed'=>'good','cancelled'=>'bad','disputed'=>'bad'];
                $sc = $statusColors[$order['status']] ?? 'mute';
                ?>
                <span class="udash__pill udash__pill--<?php echo $sc; ?>"><?php echo e($order['status']); ?></span>
                <span class="udash__pill udash__pill--<?php echo $order['payment_status']==='escrow_held'?'warn':($order['payment_status']==='released'?'good':'mute'); ?>"><?php echo e(str_replace('_',' ', $order['payment_status'])); ?></span>
            </div>
        </div>

        <div class="card" style="padding:18px;margin-bottom:16px;">
            <div style="display:flex;gap:14px;align-items:center;">
                <img src="<?php echo e(image_or_default($order['image'] ?? null)); ?>" alt="" style="width:80px;height:80px;border-radius:10px;object-fit:cover;flex:0 0 80px;">
                <div style="flex:1;">
                    <a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$order['listing_id']; ?>" style="font-size:15px;font-weight:600;color:var(--text);text-decoration:none;"><?php echo e($order['title']); ?></a>
                    <div style="font-size:13px;color:var(--text-mute);margin-top:4px;">Qty: <?php echo (int)$order['quantity']; ?> × <?php echo e(format_price((float)$order['unit_price'], $order['currency'])); ?></div>
                </div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;" class="order-detail-grid">
            <div class="card" style="padding:16px;">
                <h3 style="font-size:13px;font-weight:700;color:var(--text-mute);text-transform:uppercase;letter-spacing:.06em;margin:0 0 10px;"><?php echo $isBuyer ? 'Seller' : 'Buyer'; ?></h3>
                <strong style="font-size:14px;"><?php echo e($isBuyer ? $order['seller_name'] : $order['buyer_name']); ?></strong>
                <div style="font-size:12px;color:var(--text-mute);margin-top:2px;"><?php echo e($isBuyer ? $order['seller_email'] : $order['buyer_email']); ?></div>
                <?php if ($isBuyer): ?>
                <a href="<?php echo APP_URL; ?>/pages/messages.php?with=<?php echo (int)$order['seller_id']; ?>&order=<?php echo (int)$order['id']; ?>" class="btn btn--outline btn--sm" style="margin-top:10px;">💬 Chat with seller</a>
                <?php endif; ?>
            </div>
            <div class="card" style="padding:16px;">
                <h3 style="font-size:13px;font-weight:700;color:var(--text-mute);text-transform:uppercase;letter-spacing:.06em;margin:0 0 10px;">Delivery</h3>
                <strong style="font-size:14px;"><?php echo e($order['delivery_method_name'] ?? 'N/A'); ?></strong>
                <?php if ($order['addr_name']): ?>
                    <div style="font-size:12px;color:var(--text-mute);margin-top:4px;">
                        <?php echo e($order['addr_name']); ?> • <?php echo e($order['addr_phone']); ?><br>
                        <?php echo e(implode(', ', array_filter([$order['street'],$order['sector'],$order['district'],$order['province'],$order['country']]))); ?>
                    </div>
                <?php elseif ($order['pickup_point_name']): ?>
                    <div style="font-size:12px;color:var(--text-mute);margin-top:4px;"><?php echo e($order['pickup_point_name']); ?> — <?php echo e($order['pickup_point_address']); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card" style="padding:18px;margin-top:16px;">
            <h3 style="font-size:15px;font-weight:800;margin:0 0 12px;">Payment Summary</h3>
            <div style="display:flex;justify-content:space-between;font-size:13.5px;color:var(--text-soft);margin-bottom:8px;"><span>Subtotal</span><strong><?php echo e(format_price((float)$order['subtotal'], $order['currency'])); ?></strong></div>
            <div style="display:flex;justify-content:space-between;font-size:13.5px;color:var(--text-soft);margin-bottom:8px;"><span>Delivery fee</span><strong><?php echo e(format_price((float)$order['delivery_fee'], $order['currency'])); ?></strong></div>
            <div style="display:flex;justify-content:space-between;font-size:13.5px;color:var(--text-soft);margin-bottom:8px;"><span>Platform fee</span><strong><?php echo e(format_price((float)$order['platform_fee'], $order['currency'])); ?></strong></div>
            <hr style="border:0;border-top:1px solid var(--border);margin:10px 0;">
            <div style="display:flex;justify-content:space-between;font-size:17px;font-weight:800;color:var(--text);"><span>Total</span><span><?php echo e(format_price((float)$order['grand_total'], $order['currency'])); ?></span></div>
            <?php if ($order['payment_reference']): ?>
                <div style="font-size:11.5px;color:var(--text-mute);margin-top:10px;">Payment ref: <?php echo e($order['payment_reference']); ?> • <?php echo e($order['payment_method']); ?> • <?php echo $order['verified'] ? '✓ Verified' : 'Unverified'; ?></div>
            <?php endif; ?>
        </div>

        <?php if (!empty($tracking)): ?>
        <div class="card" style="padding:18px;margin-top:16px;">
            <h3 style="font-size:15px;font-weight:800;margin:0 0 14px;">Delivery Tracking</h3>
            <div style="display:flex;flex-direction:column;gap:12px;">
                <?php foreach ($tracking as $t): ?>
                <div style="display:flex;gap:12px;align-items:flex-start;">
                    <div style="width:28px;height:28px;border-radius:50%;background:var(--brand-50);color:var(--brand-600);display:grid;place-items:center;flex:0 0 28px;">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    </div>
                    <div>
                        <strong style="font-size:13.5px;color:var(--text);text-transform:capitalize;"><?php echo e(str_replace('_',' ', $t['status'])); ?></strong>
                        <div style="font-size:12px;color:var(--text-mute);"><?php echo e($t['note']); ?> — <?php echo e(time_ago($t['created_at'])); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <?php if ($isBuyer && $order['status'] === 'pending'): ?>
        <div class="card" style="padding:18px;margin-top:16px;">
            <h3 style="font-size:15px;font-weight:800;margin:0 0 6px;">Cancel Order</h3>
            <p style="font-size:13px;color:var(--text-soft);margin:0 0 12px;">This order hasn't been confirmed by the seller yet. Cancelling now refunds any held funds immediately.</p>
            <form method="post" action="">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                <button type="submit" class="btn btn--outline">Cancel Order</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($isBuyer && $order['status'] === 'delivered' && !$order['escrow_released']): ?>
        <div class="card" style="padding:18px;margin-top:16px;border-color:var(--brand-200);background:var(--brand-50);">
            <h3 style="font-size:15px;font-weight:800;margin:0 0 6px;">Confirm Receipt</h3>
            <p style="font-size:13px;color:var(--text-soft);margin:0 0 12px;">Has the item been delivered to you? Confirm to release funds to the seller.</p>
            <form method="post" action="">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="confirm_received">
                <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                <button type="submit" class="btn btn--primary">✓ Confirm Received</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($isSeller && in_array($order['status'], ['confirmed','preparing','ready','shipped'], true)): ?>
        <div class="card" style="padding:18px;margin-top:16px;">
            <h3 style="font-size:15px;font-weight:800;margin:0 0 10px;">Update Order Status</h3>
            <form method="post" action="" style="display:flex;gap:8px;flex-wrap:wrap;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                <select name="new_status" style="padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;">
                    <option value="preparing" <?php echo $order['status']==='preparing'?'selected':''; ?>>Preparing</option>
                    <option value="ready" <?php echo $order['status']==='ready'?'selected':''; ?>>Ready for Pickup</option>
                    <option value="shipped" <?php echo $order['status']==='shipped'?'selected':''; ?>>Shipped</option>
                    <option value="out_for_delivery" <?php echo $order['status']==='out_for_delivery'?'selected':''; ?>>Out for Delivery</option>
                    <option value="delivered" <?php echo $order['status']==='delivered'?'selected':''; ?>>Delivered</option>
                </select>
                <button type="submit" class="btn btn--primary btn--sm">Update</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($isBuyer && in_array($order['status'], ['confirmed','preparing','ready','shipped','out_for_delivery','delivered'], true) && $order['status'] !== 'disputed'): ?>
        <div class="card" style="padding:18px;margin-top:16px;">
            <details>
                <summary style="font-size:14px;font-weight:700;cursor:pointer;color:var(--text);">⚠ Open a Dispute</summary>
                <form method="post" action="" style="margin-top:12px;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="open_dispute">
                    <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                    <div class="form-group"><label style="font-size:13px;font-weight:600;">Reason</label><input type="text" name="reason" required placeholder="e.g. Item not as described" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;"></div>
                    <div class="form-group"><label style="font-size:13px;font-weight:600;">Description</label><textarea name="description" required rows="3" placeholder="Explain the issue..." style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;resize:vertical;"></textarea></div>
                    <button type="submit" class="btn btn--outline btn--sm" style="color:#d9534a;border-color:#f4c6c6;">Submit Dispute</button>
                </form>
            </details>
        </div>
        <?php endif; ?>
    </div>
    <style>@media(max-width:600px){.order-detail-grid{grid-template-columns:1fr !important;}}</style>
    <?php seller_page_end(); ?>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// --- Order list view ---
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
$orders = $stmt->fetchAll();

$pageTitle = $view === 'seller' ? 'Received Orders' : 'My Orders';
$activePage = 'dashboard';
require_once __DIR__ . '/../includes/header.php';
?>
<?php seller_page_start($view === 'seller' ? 'Received Orders' : 'My Orders', $uid, $pdo); ?>

    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
        <h1 style="font-size:22px;font-weight:800;margin:0;letter-spacing:-.02em;"><?php echo $view === 'seller' ? 'Received Orders' : 'My Orders'; ?></h1>
        <div style="display:flex;gap:6px;">
            <a href="<?php echo APP_URL; ?>/pages/orders.php?view=buyer" class="btn btn--<?php echo $view==='buyer'?'primary':'secondary'; ?> btn--sm">As Buyer</a>
            <a href="<?php echo APP_URL; ?>/pages/orders.php?view=seller" class="btn btn--<?php echo $view==='seller'?'primary':'secondary'; ?> btn--sm">As Seller</a>
        </div>
    </div>

    <?php if (empty($orders)): ?>
        <div class="card" style="padding:48px 24px;text-align:center;">
            <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="var(--text-mute)" stroke-width="1.3" style="margin:0 auto 12px;display:block;opacity:.4;"><path d="M16 16h.01M8 16h.01M3 9h18M5 5h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/></svg>
            <h3 style="margin:0 0 6px;font-size:16px;color:var(--text);">No orders yet</h3>
            <p style="margin:0 0 16px;font-size:13.5px;color:var(--text-mute);">
                <?php echo $view === 'seller' ? 'When buyers order your listings, they will appear here.' : 'Start shopping to see your orders here.'; ?>
            </p>
            <?php if ($view === 'buyer'): ?>
                <a href="<?php echo APP_URL; ?>/pages/explore.php" class="btn btn--primary">Explore Marketplace</a>
            <?php else: ?>
                <a href="<?php echo APP_URL; ?>/pages/sell.php" class="btn btn--primary">Create a Listing</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:12px;">
            <?php foreach ($orders as $o):
                $sc = ['pending'=>'warn','confirmed'=>'brand','preparing'=>'brand','ready'=>'brand','shipped'=>'blue','out_for_delivery'=>'blue','delivered'=>'good','completed'=>'good','cancelled'=>'bad','disputed'=>'bad'][$o['status']] ?? 'mute';
            ?>
                <a href="<?php echo APP_URL; ?>/pages/orders.php?id=<?php echo (int)$o['id']; ?>" class="card" style="padding:14px;display:flex;gap:14px;align-items:center;text-decoration:none;color:inherit;transition:all 160ms;">
                    <img src="<?php echo e(image_or_default($o['image'] ?? null)); ?>" alt="" style="width:64px;height:64px;border-radius:10px;object-fit:cover;flex:0 0 64px;">
                    <div style="flex:1;min-width:0;">
                        <strong style="font-size:14px;color:var(--text);"><?php echo e($o['title']); ?></strong>
                        <div style="font-size:12px;color:var(--text-mute);margin-top:2px;">
                            <?php echo e($o['order_number']); ?> • <?php echo e(time_ago($o['created_at'])); ?> • <?php echo e($o['other_party_name']); ?>
                        </div>
                        <div style="font-size:13px;font-weight:700;color:var(--brand-600);margin-top:3px;"><?php echo e(format_price((float)$o['grand_total'], $o['currency'])); ?></div>
                    </div>
                    <div style="text-align:right;flex:0 0 auto;">
                        <span class="udash__pill udash__pill--<?php echo $sc; ?>"><?php echo e($o['status']); ?></span>
                        <div style="font-size:11px;color:var(--text-mute);margin-top:4px;"><?php echo e(str_replace('_',' ', $o['payment_status'])); ?></div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php seller_page_end(); ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
