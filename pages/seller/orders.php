<?php
/**
 * pages/seller/orders.php — Seller Order Management
 * --------------------------------------------------------------------
 * Shows all orders for the seller's products with:
 *   - Filter tabs by status (all, pending, confirmed, preparing, shipped, delivered, completed, cancelled)
 *   - Order detail with buyer info, product, payment, delivery tracking
 *   - Status update actions (preparing, ready, shipped, out_for_delivery, delivered)
 *   - Real DB queries scoped to the seller (authorization)
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/seller_sidebar.php';
require_login();

$uid = (int) current_user()['id'];
$pdo = db();

// --- Handle POST: update order status ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        flash_set('error', t('errors.invalid_token'));
    } else {
        $action = $_POST['action'] ?? '';
        $orderId = (int)($_POST['order_id'] ?? 0);

        // Verify the seller owns this order
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? AND seller_id = ?');
        $stmt->execute([$orderId, $uid]);
        $order = $stmt->fetch();

        if (!$order) {
            flash_set('error', 'Order not found or not authorized.');
        } else {
            switch ($action) {
                case 'update_status':
                    $newStatus = $_POST['new_status'] ?? '';
                    $allowed = ['preparing','ready','shipped','out_for_delivery','delivered'];
                    if (!in_array($newStatus, $allowed, true)) {
                        flash_set('error', 'Invalid status.');
                    } else {
                        $pdo->prepare("UPDATE orders SET delivery_status=?, status=?, updated_at=NOW() WHERE id=?")
                            ->execute([$newStatus, $newStatus, $orderId]);
                        $pdo->prepare("INSERT INTO delivery_tracking (order_id, status, note, created_by) VALUES (?, ?, 'Status updated by seller', ?)")
                            ->execute([$orderId, $newStatus, $uid]);
                        if ($newStatus === 'delivered') {
                            $pdo->prepare("UPDATE orders SET delivered_at=NOW() WHERE id=?")->execute([$orderId]);
                        }
                        // Notify buyer
                        $pdo->prepare("INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, 'order', 'Order updated', ?, ?)")
                            ->execute([(int)$order['buyer_id'], 'Your order status is now: ' . $newStatus, '/pages/orders.php?id=' . $orderId]);
                        flash_set('success', 'Order status updated to ' . $newStatus . '.');
                    }
                    break;
            }
        }
        redirect(APP_URL . '/pages/seller/orders.php' . (isset($_GET['status']) ? '?status=' . $_GET['status'] : ''));
    }
}

// --- Filter ---
$statusFilter = $_GET['status'] ?? 'all';
$validStatuses = ['all','pending','confirmed','preparing','ready','shipped','out_for_delivery','delivered','completed','cancelled','disputed'];
if (!in_array($statusFilter, $validStatuses, true)) $statusFilter = 'all';

$where = 'o.seller_id = ?';
$params = [$uid];
if ($statusFilter !== 'all') {
    $where .= ' AND o.status = ?';
    $params[] = $statusFilter;
}

$stmt = $pdo->prepare(
    "SELECT o.*, l.title AS listing_title,
            (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image,
            buyer.full_name AS buyer_name, buyer.email AS buyer_email,
            dm.name AS delivery_method_name
       FROM orders o
       INNER JOIN listings l ON l.id = o.listing_id
       INNER JOIN users buyer ON buyer.id = o.buyer_id
       LEFT JOIN delivery_methods dm ON dm.id = o.delivery_method_id
      WHERE $where
      ORDER BY o.created_at DESC"
);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// --- Counts for tabs ---
$countStmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM orders WHERE seller_id = ? GROUP BY status");
$countStmt->execute([$uid]);
$statusCounts = ['all' => 0];
foreach ($countStmt->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int)$row['cnt'];
    $statusCounts['all'] += (int)$row['cnt'];
}

$pageTitle = 'Seller Orders';
$activePage = 'dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>

<?php seller_page_start('Seller Orders', $uid, $pdo); ?>

    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
        <div>
            <h1 style="font-size:22px;font-weight:800;margin:0;letter-spacing:-.02em;">Orders</h1>
            <p style="font-size:13px;color:var(--text-mute);margin:4px 0 0;">Manage orders from buyers.</p>
        </div>
    </div>

    <!-- Filter tabs -->
    <div style="display:flex;gap:6px;margin-bottom:20px;flex-wrap:wrap;border-bottom:1px solid var(--border);">
        <a href="?status=all" class="seller-tab <?php echo $statusFilter==='all'?'is-active':''; ?>">All <span class="seller-tab__count"><?php echo $statusCounts['all'] ?? 0; ?></span></a>
        <?php
        $tabs = ['pending'=>'Pending','confirmed'=>'Confirmed','preparing'=>'Preparing','ready'=>'Ready','shipped'=>'Shipped','out_for_delivery'=>'Out for Delivery','delivered'=>'Delivered','completed'=>'Completed','cancelled'=>'Cancelled','disputed'=>'Disputed'];
        foreach ($tabs as $code => $label):
            $count = $statusCounts[$code] ?? 0;
            if ($count === 0 && $statusFilter !== $code) continue; // hide empty tabs
        ?>
            <a href="?status=<?php echo $code; ?>" class="seller-tab <?php echo $statusFilter===$code?'is-active':''; ?>"><?php echo $label; ?> <span class="seller-tab__count"><?php echo $count; ?></span></a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($orders)): ?>
        <div class="card" style="padding:48px 24px;text-align:center;">
            <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="var(--text-mute)" stroke-width="1.3" style="opacity:.4;margin-bottom:12px;"><path d="M16 16h.01M8 16h.01M3 9h18M5 5h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/></svg>
            <h3 style="margin:0 0 6px;font-size:16px;color:var(--text);">No orders yet</h3>
            <p style="margin:0 0 16px;font-size:13.5px;color:var(--text-mute);">When buyers purchase your products, orders will appear here.</p>
            <a href="<?php echo APP_URL; ?>/pages/sell.php" class="btn btn--primary">Create a Listing</a>
        </div>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:12px;">
            <?php foreach ($orders as $o):
                $sc = ['pending'=>'warn','confirmed'=>'brand','preparing'=>'brand','ready'=>'brand','shipped'=>'blue','out_for_delivery'=>'blue','delivered'=>'good','completed'=>'good','cancelled'=>'bad','disputed'=>'bad'][$o['status']] ?? 'mute';
            ?>
                <div class="card" style="padding:16px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
                    <img src="<?php echo e(image_or_default($o['image'] ?? null)); ?>" alt="" style="width:64px;height:64px;border-radius:10px;object-fit:cover;flex:0 0 64px;">
                    <div style="flex:1;min-width:200px;">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <strong style="font-size:14px;color:var(--text);"><?php echo e($o['listing_title']); ?></strong>
                            <span class="udash__pill udash__pill--<?php echo $sc; ?>"><?php echo e($o['status']); ?></span>
                            <?php $psColors = ['unpaid'=>'bad','pending'=>'warn','escrow_held'=>'brand','released'=>'good','refunded'=>'mute','failed'=>'bad']; ?>
                            <span class="udash__pill udash__pill--<?php echo $psColors[$o['payment_status']] ?? 'mute'; ?>"><?php echo e(str_replace('_',' ', $o['payment_status'])); ?></span>
                        </div>
                        <div style="font-size:12px;color:var(--text-mute);margin-top:4px;">
                            <?php echo e($o['order_number']); ?> • <?php echo e($o['buyer_name']); ?> • <?php echo e(time_ago($o['created_at'])); ?>
                        </div>
                        <div style="font-size:13px;font-weight:700;color:var(--brand-600);margin-top:3px;"><?php echo e(format_price((float)$o['grand_total'], $o['currency'])); ?></div>
                    </div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <a href="<?php echo APP_URL; ?>/pages/orders.php?id=<?php echo (int)$o['id']; ?>" class="btn btn--outline btn--sm">View Details</a>
                        <?php if (in_array($o['status'], ['confirmed','preparing','ready','shipped'], true)): ?>
                        <form method="post" action="" style="display:inline-flex;gap:4px;align-items:center;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                            <select name="new_status" style="padding:6px 10px;border:1px solid var(--border);border-radius:8px;font-size:12px;">
                                <option value="preparing" <?php echo $o['status']==='preparing'?'selected':''; ?>>Preparing</option>
                                <option value="ready" <?php echo $o['status']==='ready'?'selected':''; ?>>Ready</option>
                                <option value="shipped" <?php echo $o['status']==='shipped'?'selected':''; ?>>Shipped</option>
                                <option value="out_for_delivery" <?php echo $o['status']==='out_for_delivery'?'selected':''; ?>>Out for Delivery</option>
                                <option value="delivered" <?php echo $o['status']==='delivered'?'selected':''; ?>>Delivered</option>
                            </select>
                            <button type="submit" class="btn btn--primary btn--sm">Update</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
.seller-tab { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; font-size:13px; font-weight:600; color:var(--text-mute); text-decoration:none; border-bottom:2px solid transparent; margin-bottom:-1px; transition:all 150ms ease; }
.seller-tab:hover { color:var(--text); }
.seller-tab.is-active { color:var(--brand-600); border-bottom-color:var(--brand-500); }
.seller-tab__count { display:inline-flex; align-items:center; justify-content:center; min-width:18px; height:18px; padding:0 5px; border-radius:9px; background:var(--bg-soft); color:var(--text-mute); font-size:10px; font-weight:700; }
.seller-tab.is-active .seller-tab__count { background:var(--brand-50); color:var(--brand-700); }
@media (max-width:600px) { .seller-tab { padding:6px 10px; font-size:12px; } }
</style>
<?php seller_page_end(); ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
