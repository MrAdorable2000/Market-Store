<?php
/**
 * pages/admin/super-dashboard.php — Super Admin Control Center
 * --------------------------------------------------------------------
 * Enhanced dashboard with full marketplace visibility:
 *   - Users, sellers, buyers, admins breakdown
 *   - Orders, revenue, pending withdrawals
 *   - Disputes, pending verifications
 *   - 7-day revenue chart
 *   - Recent activity feed
 *
 * SECURITY: require_super_admin() — only SUPER_ADMIN role can access.
 * Normal ADMINs are blocked (403).
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_icons.php';
require_once __DIR__ . '/../../includes/admin_ui.php';
require_once __DIR__ . '/../../includes/wallet.php';
require_super_admin();

$pdo = db();
$uid = (int) current_user()['id'];

// --- Real marketplace stats ---
$stats = [
    'total_users'       => (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'active_users'      => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn(),
    'suspended_users'   => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status='suspended'")->fetchColumn(),
    'sellers'           => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE is_seller=1 OR role_id IN (SELECT id FROM roles WHERE name IN ('SELLER','ADMIN','SUPER_ADMIN'))")->fetchColumn(),
    'buyers'            => (int) $pdo->query("SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id=u.role_id WHERE r.name='USER'")->fetchColumn(),
    'admins'            => (int) $pdo->query("SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id=u.role_id WHERE r.name IN ('ADMIN','SUPER_ADMIN')")->fetchColumn(),
    'products'          => (int) $pdo->query("SELECT COUNT(*) FROM listings")->fetchColumn(),
    'active_products'   => (int) $pdo->query("SELECT COUNT(*) FROM listings WHERE status='active'")->fetchColumn(),
    'pending_products'  => (int) $pdo->query("SELECT COUNT(*) FROM listings WHERE status='pending'")->fetchColumn(),
];

// Order stats (safe if table doesn't exist)
$orderStats = ['total' => 0, 'pending' => 0, 'completed' => 0, 'cancelled' => 0, 'revenue' => 0, 'disputes' => 0];
try {
    $orderStats['total'] = (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $orderStats['pending'] = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending','confirmed','preparing','ready','shipped','out_for_delivery')")->fetchColumn();
    $orderStats['completed'] = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status='completed'")->fetchColumn();
    $orderStats['cancelled'] = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status='cancelled'")->fetchColumn();
    $orderStats['revenue'] = (float) $pdo->query("SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE status='completed'")->fetchColumn();
    $orderStats['disputes'] = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status='disputed'")->fetchColumn();
} catch (PDOException $e) {}

// Withdrawal stats
$wdStats = ['pending' => 0, 'completed' => 0, 'total_amount' => 0];
try {
    $wdStats['pending'] = (int) $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status='pending'")->fetchColumn();
    $wdStats['completed'] = (int) $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status='completed'")->fetchColumn();
    $wdStats['total_amount'] = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE status='completed'")->fetchColumn();
} catch (PDOException $e) {}

// Payment method verifications pending
$pendingVerifications = 0;
try {
    $pendingVerifications = (int) $pdo->query("SELECT COUNT(*) FROM withdrawal_methods WHERE verification_status='pending'")->fetchColumn();
} catch (PDOException $e) {}

// 7-day revenue chart
$revenueData = [];
try {
    $stmt = $pdo->query("SELECT DATE(created_at) AS d, COALESCE(SUM(grand_total),0) AS total FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 DAY) AND status='completed' GROUP BY DATE(created_at) ORDER BY d");
    $rawRev = $stmt->fetchAll();
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $found = 0;
        foreach ($rawRev as $r) { if ($r['d'] === $date) { $found = (float)$r['total']; break; } }
        $revenueData[] = ['day' => date('D', strtotime($date)), 'value' => $found];
    }
} catch (PDOException $e) {
    for ($i = 6; $i >= 0; $i--) $revenueData[] = ['day' => date('D', strtotime("-$i days")), 'value' => 0];
}

// Recent users
$recentUsers = $pdo->query("SELECT u.id, u.full_name, u.email, u.status, u.created_at, r.name AS role_name FROM users u INNER JOIN roles r ON r.id=u.role_id ORDER BY u.created_at DESC LIMIT 5")->fetchAll();

// Recent orders
$recentOrders = [];
try {
    $recentOrders = $pdo->query("SELECT o.order_number, o.grand_total, o.currency, o.status, o.created_at, l.title AS listing_title, buyer.full_name AS buyer_name, seller.full_name AS seller_name FROM orders o INNER JOIN listings l ON l.id=o.listing_id INNER JOIN users buyer ON buyer.id=o.buyer_id INNER JOIN users seller ON seller.id=o.seller_id ORDER BY o.created_at DESC LIMIT 5")->fetchAll();
} catch (PDOException $e) {}

$pageTitle = 'Super Admin Dashboard';
$activePage = 'admin';
$adminActivePage = 'dashboard';
$extraCss = '<link rel="stylesheet" href="' . APP_URL . '/assets/css/admin.css">';
$adminPageTitle = 'Super Admin Control Center';
$adminPageSubtitle = 'Full marketplace overview with real-time data';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="a-shell" id="aShell">
<?php require __DIR__ . '/../../includes/admin_sidebar.php'; ?>
<div class="a-main">
<?php require __DIR__ . '/../../includes/admin_topbar.php'; ?>
<div class="a-content">

<div class="a-hero">
    <div>
        <h2>Super Admin Control Center</h2>
        <p class="a-hero__sub">Full marketplace overview with real-time data</p>
    </div>
    <div class="a-hero__actions">
        <a class="a-btn a-btn--accent" href="<?php echo APP_URL; ?>/pages/admin/super-users.php"><?php echo admin_icon('users'); ?><span>Manage Users</span></a>
    </div>
</div>

<!-- KPI Grid -->
<div class="a-kpis">
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label">Total Users</span><span class="a-kpi__icon"><?php echo admin_icon('users'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($stats['total_users']); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo $stats['active_users']; ?> active · <?php echo $stats['suspended_users']; ?> suspended</span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label">Sellers</span><span class="a-kpi__icon a-kpi__icon--green"><?php echo admin_icon('seller'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($stats['sellers']); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo $stats['buyers']; ?> buyers</span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label">Products</span><span class="a-kpi__icon a-kpi__icon--orange"><?php echo admin_icon('listings'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($stats['products']); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo $stats['active_products']; ?> active · <?php echo $stats['pending_products']; ?> pending</span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label">Total Orders</span><span class="a-kpi__icon"><?php echo admin_icon('orders'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($orderStats['total']); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo $orderStats['pending']; ?> pending · <?php echo $orderStats['completed']; ?> completed</span></div>
    </div>
</div>

<div class="a-kpis" style="margin-top:16px;">
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label">Total Revenue</span><span class="a-kpi__icon a-kpi__icon--green"><?php echo admin_icon('revenue'); ?></span></div>
        <div class="a-kpi__value"><?php echo format_price($orderStats['revenue'], 'RWF'); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint">From completed orders</span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label">Pending Withdrawals</span><span class="a-kpi__icon a-kpi__icon--orange"><?php echo admin_icon('wallet'); ?></span></div>
        <div class="a-kpi__value"><?php echo $wdStats['pending']; ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo $wdStats['completed']; ?> completed</span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label">Open Disputes</span><span class="a-kpi__icon a-kpi__icon--danger"><?php echo admin_icon('reports'); ?></span></div>
        <div class="a-kpi__value"><?php echo $orderStats['disputes']; ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint">Requires attention</span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label">Pending Verifications</span><span class="a-kpi__icon"><?php echo admin_icon('verify'); ?></span></div>
        <div class="a-kpi__value"><?php echo $pendingVerifications; ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint">Payment methods to review</span></div>
    </div>
</div>

<!-- Revenue Chart -->
<section class="a-panel" style="margin-top:18px;">
    <div class="a-panel__head">
        <div><h3>Revenue — Last 7 Days</h3></div>
    </div>
    <div class="a-bar-chart" style="height:180px;">
        <?php $maxRev = max(array_column($revenueData, 'value')); ?>
        <?php if ($maxRev == 0) $maxRev = 1; ?>
        <?php foreach ($revenueData as $rd): ?>
        <div class="a-bar-chart__col">
            <div class="a-bar-chart__bar" style="height:<?php echo max(3, ($rd['value'] / $maxRev) * 100); ?>%;"><?php echo $rd['value'] > 0 ? number_format($rd['value']/1000,0).'K' : ''; ?></div>
            <div class="a-bar-chart__label"><?php echo e($rd['day']); ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- Two-column: Recent Users + Recent Orders -->
<div class="a-grid" style="margin-top:18px;">
    <!-- Recent Users -->
    <section class="a-panel">
        <div class="a-panel__head">
            <div><h3>Recent Users</h3></div>
            <a class="a-panel__link" href="<?php echo APP_URL; ?>/pages/admin/super-users.php">View all →</a>
        </div>
        <div class="a-table-wrap">
            <table class="a-table">
                <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th></tr></thead>
                <tbody>
                    <?php foreach ($recentUsers as $u): ?>
                    <tr>
                        <td class="cell-primary"><?php echo e($u['full_name']); ?></td>
                        <td class="cell-mute"><?php echo e($u['email']); ?></td>
                        <td><?php echo e($u['role_name']); ?></td>
                        <td><span class="a-status a-status--<?php echo $u['status']==='active'?'active':'suspended'; ?>"><?php echo e($u['status']); ?></span></td>
                        <td class="cell-mute"><?php echo time_ago($u['created_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Recent Orders -->
    <section class="a-panel">
        <div class="a-panel__head">
            <div><h3>Recent Orders</h3></div>
        </div>
        <?php if (empty($recentOrders)): ?>
            <div class="a-empty">
                <h4>No orders yet</h4>
                <p>Orders will appear here when buyers start purchasing.</p>
            </div>
        <?php else: ?>
            <div class="a-table-wrap">
                <table class="a-table">
                    <thead><tr><th>Order</th><th>Product</th><th>Buyer</th><th>Amount</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($recentOrders as $o): ?>
                        <tr>
                            <td class="cell-primary"><?php echo e($o['order_number']); ?></td>
                            <td class="cell-mute"><?php echo e($o['listing_title']); ?></td>
                            <td class="cell-mute"><?php echo e($o['buyer_name']); ?></td>
                            <td><?php echo e(format_price((float)$o['grand_total'], $o['currency'])); ?></td>
                            <td><span class="a-status a-status--<?php echo $o['status']==='completed'?'active':($o['status']==='cancelled'?'suspended':'pending'); ?>"><?php echo e($o['status']); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

</div><!-- /.a-content -->
</div><!-- /.a-main -->
</div><!-- /.a-shell -->
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
