<?php
/**
 * pages/seller/dashboard.php — Seller Center Overview
 * --------------------------------------------------------------------
 * Real data from DB: listings, orders, wallet, earnings, messages,
 * reviews, category distribution, 7-day sales chart.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/wallet.php';

require_login();
$uid = (int) current_user()['id'];
$pdo = db();

// --- Product stats ---
function _seller_scalar(PDO $pdo, string $sql, int $uid)
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$uid]);
    return $stmt->fetchColumn();
}
$myListings  = (int) _seller_scalar($pdo, "SELECT COUNT(*) FROM listings WHERE seller_id = ?", $uid);
$myActive    = (int) _seller_scalar($pdo, "SELECT COUNT(*) FROM listings WHERE seller_id = ? AND status='active'", $uid);
$mySold      = (int) _seller_scalar($pdo, "SELECT COUNT(*) FROM listings WHERE seller_id = ? AND availability='sold'", $uid);
$myViews     = (int) _seller_scalar($pdo, "SELECT COALESCE(SUM(views_count),0) FROM listings WHERE seller_id = ?", $uid);
$myFavorites = (int) _seller_scalar($pdo, "SELECT COALESCE(SUM(favorites_count),0) FROM listings WHERE seller_id = ?", $uid);

// --- Order stats (safe if orders table doesn't exist) ---
$myOrders = $pendingOrders = $completedOrders = $cancelledOrders = 0;
$todaySales = $monthSales = $totalSales = 0;
try {
    $myOrders = (int) _seller_scalar($pdo, "SELECT COUNT(*) FROM orders WHERE seller_id = ?", $uid);
    $pendingOrders = (int) _seller_scalar($pdo, "SELECT COUNT(*) FROM orders WHERE seller_id = ? AND status IN ('pending','confirmed','preparing','ready','shipped','out_for_delivery')", $uid);
    $completedOrders = (int) _seller_scalar($pdo, "SELECT COUNT(*) FROM orders WHERE seller_id = ? AND status = 'completed'", $uid);
    $cancelledOrders = (int) _seller_scalar($pdo, "SELECT COUNT(*) FROM orders WHERE seller_id = ? AND status = 'cancelled'", $uid);
    $todaySales = (float) _seller_scalar($pdo, "SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE seller_id = ? AND DATE(created_at) = CURDATE() AND status != 'cancelled'", $uid);
    $monthSales = (float) _seller_scalar($pdo, "SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE seller_id = ? AND MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW()) AND status != 'cancelled'", $uid);
    $totalSales = (float) _seller_scalar($pdo, "SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE seller_id = ? AND status = 'completed'", $uid);
} catch (PDOException $e) { /* orders table might not exist */ }

// --- Wallet balance ---
$walletBal = wallet_balance($uid);

// --- 7-day sales chart data ---
$salesData = [];
try {
    $stmt = $pdo->prepare(
        "SELECT DATE(created_at) AS d, COALESCE(SUM(grand_total),0) AS total
           FROM orders
          WHERE seller_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 6 DAY) AND status != 'cancelled'
          GROUP BY DATE(created_at) ORDER BY d"
    );
    $stmt->execute([$uid]);
    $rawSales = $stmt->fetchAll();
    // Fill in missing days with 0
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $found = 0;
        foreach ($rawSales as $r) { if ($r['d'] === $date) { $found = (float)$r['total']; break; } }
        $salesData[] = ['day' => date('D', strtotime($date)), 'value' => $found];
    }
} catch (PDOException $e) {
    for ($i = 6; $i >= 0; $i--) { $salesData[] = ['day' => date('D', strtotime("-$i days")), 'value' => 0]; }
}

// --- Average rating ---
$avgRating = 0; $reviewCount = 0;
try {
    $stmt = $pdo->prepare("SELECT AVG(r.rating) AS avg, COUNT(*) AS cnt FROM reviews r INNER JOIN listings l ON l.id = r.listing_id WHERE l.seller_id = ?");
    $stmt->execute([$uid]);
    $rData = $stmt->fetch();
    if ($rData) { $avgRating = round((float)$rData['avg'], 1); $reviewCount = (int)$rData['cnt']; }
} catch (PDOException $e) {}

// --- Unread messages ---
$chatUnread = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages m INNER JOIN conversations c ON c.id = m.conversation_id WHERE c.user2_id = ? AND m.sender_id != ? AND m.is_read = 0");
    $stmt->execute([$uid, $uid]);
    $chatUnread = (int) $stmt->fetchColumn();
} catch (PDOException $e) {}

// --- Unread notifications ---
$unreadNotif = (int) _seller_scalar($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0", $uid);

// --- Recent listings ---
$stmt = $pdo->prepare('SELECT id, title, price, currency, listing_type, availability, status, views_count, favorites_count, created_at FROM listings WHERE seller_id = ? ORDER BY created_at DESC LIMIT 5');
$stmt->execute([$uid]);
$recent = $stmt->fetchAll();

// --- Recent orders ---
$recentOrders = [];
try {
    $stmt = $pdo->prepare(
        "SELECT o.order_number, o.grand_total, o.currency, o.status, o.created_at,
                l.title AS listing_title, buyer.full_name AS buyer_name
           FROM orders o
           INNER JOIN listings l ON l.id = o.listing_id
           INNER JOIN users buyer ON buyer.id = o.buyer_id
          WHERE o.seller_id = ?
          ORDER BY o.created_at DESC LIMIT 5"
    );
    $stmt->execute([$uid]);
    $recentOrders = $stmt->fetchAll();
} catch (PDOException $e) {}

// --- Products by Category ---
$catStmt = $pdo->prepare(
    "SELECT c.name, COUNT(l.id) AS count
       FROM categories c
       LEFT JOIN listings l ON l.category_id = c.id AND l.seller_id = ?
      WHERE c.parent_id IS NULL AND c.is_active = 1
      GROUP BY c.id, c.name HAVING count > 0 ORDER BY count DESC LIMIT 6"
);
$catStmt->execute([$uid]);
$categoryStats = $catStmt->fetchAll();

// --- Recent conversations ---
$chatConvs = [];
try {
    $stmt = $pdo->prepare(
        "SELECT c.id, u1.full_name AS buyer_name, l.title AS listing_title,
                (SELECT body FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) AS last_message,
                (SELECT created_at FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) AS last_msg_at,
                (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id AND sender_id = c.user1_id AND is_read = 0) AS unread
           FROM conversations c
           INNER JOIN users u1 ON u1.id = c.user1_id
           LEFT JOIN listings l ON l.id = c.listing_id
          WHERE c.user2_id = ?
          ORDER BY c.last_message_at DESC LIMIT 4"
    );
    $stmt->execute([$uid]);
    $chatConvs = $stmt->fetchAll();
} catch (PDOException $e) {}

$pageTitle = 'Seller Dashboard';
$activePage = 'dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="udash">
    <aside class="udash__sidebar" id="udashSidebar">
        <div class="udash__profile">
            <span class="udash__avatar"><?php echo e(strtoupper(substr(current_user()['full_name'],0,1))); ?></span>
            <div class="udash__profile-info">
                <h4><?php echo e(current_user()['full_name']); ?></h4>
                <span class="udash__caps"><?php echo e(t("common.seller_account")); ?></span>
            </div>
        </div>
        <nav class="udash__nav">
            <div class="udash__nav-section">Main</div>
            <a href="<?php echo APP_URL; ?>/pages/seller/dashboard.php" class="udash__nav-link is-active">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                <span>Overview</span>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/sell.php" class="udash__nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>
                <span>New Listing</span>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/seller/listings.php" class="udash__nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/></svg>
                <span>My Listings</span>
                <?php if ($myListings > 0): ?><span class="udash__nav-badge"><?php echo $myListings; ?></span><?php endif; ?>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/seller/messages.php" class="udash__nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <span>Messages</span>
                <?php if ($chatUnread > 0): ?><span class="udash__nav-badge"><?php echo $chatUnread; ?></span><?php endif; ?>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/seller/orders.php" class="udash__nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M16 16h.01M8 16h.01M3 9h18M5 5h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/></svg>
                <span>Orders</span>
                <?php if ($pendingOrders > 0): ?><span class="udash__nav-badge"><?php echo $pendingOrders; ?></span><?php endif; ?>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/seller/reviews.php" class="udash__nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                <span>Reviews</span>
            </a>
            <div class="udash__nav-section">Finance</div>
            <a href="<?php echo APP_URL; ?>/pages/wallet.php" class="udash__nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4M3 5v14a2 2 0 0 0 2 2h16v-5M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
                <span>Wallet</span>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/seller/withdrawals.php" class="udash__nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                <span>Withdrawals</span>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/seller/payment-methods.php" class="udash__nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                <span>Payment Methods</span>
            </a>
            <div class="udash__nav-section">Account</div>
            <a href="<?php echo APP_URL; ?>/pages/seller/settings.php" class="udash__nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>
                <span>Seller Settings</span>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/logout.php" class="udash__nav-link udash__nav-link--danger" onclick="if(typeof confirmAction==='function'){confirmAction({type:'warning',title:'Sign out?',message:'Are you sure you want to sign out of your account?',confirmText:'Sign Out',cancelText:'Cancel',onConfirm:function(){window.location.href='<?php echo APP_URL; ?>/pages/logout.php';return true;}});return false;}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                <span>Sign out</span>
            </a>
        </nav>
    </aside>

    <main class="udash__main">
        <!-- Topbar -->
        <div class="udash__topbar">
            <a href="<?php echo APP_URL; ?>/" class="udash__brand">
                <?php echo brand_mark_html(28); ?>
                <span>Isoko<span class="brand__accent">Ryacu</span></span>
            </a>
            <button class="udash__menu-toggle" id="udashMenuToggle" type="button" aria-label="Menu">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18" stroke-linecap="round"/></svg>
            </button>
            <div class="udash__topbar-actions">
                <button class="udash__iconbtn" id="udashThemeToggle" type="button" aria-label="Toggle theme" title="Toggle dark mode">
                    <svg class="i-moon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
                    <svg class="i-sun" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                </button>
                <a href="<?php echo APP_URL; ?>/pages/seller/messages.php" class="udash__iconbtn" title="Messages">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <?php if ($chatUnread > 0): ?><span class="udash__iconbtn-dot"></span><?php endif; ?>
                </a>
                <button class="udash__iconbtn" type="button" title="Sign out" aria-label="Sign out" onclick="if(typeof confirmAction==='function'){confirmAction({type:'warning',title:'Sign out?',message:'Are you sure you want to sign out of your account?',confirmText:'Sign Out',cancelText:'Cancel',onConfirm:function(){window.location.href='<?php echo APP_URL; ?>/pages/logout.php';return true;}});return false;}window.location.href='<?php echo APP_URL; ?>/pages/logout.php';">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                </button>
                <a href="<?php echo APP_URL; ?>/pages/sell.php" class="btn btn--primary btn--sm">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>
                    New Listing
                </a>
            </div>
        </div>

        <!-- Welcome -->
        <div class="udash__welcome">
            <div>
                <h1>Welcome back, <?php echo e(explode(' ', current_user()['full_name'])[0]); ?> 👋</h1>
                <p><?php echo $myActive > 0 ? "You have {$myActive} active listing(s)." : "Start selling by creating your first listing!"; ?></p>
            </div>
            <a href="<?php echo APP_URL; ?>/pages/sell.php" class="btn btn--primary">+ Create Listing</a>
        </div>

        <!-- Stats -->
        <div class="udash__stats">
            <div class="udash__stat" style="cursor:default;">
                <div class="udash__stat-icon udash__stat-icon--brand"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/></svg></div>
                <div class="udash__stat-body"><div class="udash__stat-value"><?php echo $myListings; ?></div><div class="udash__stat-label">Total Listings</div></div>
            </div>
            <div class="udash__stat" style="cursor:default;">
                <div class="udash__stat-icon udash__stat-icon--green"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 6 9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                <div class="udash__stat-body"><div class="udash__stat-value"><?php echo $myActive; ?></div><div class="udash__stat-label">Active</div></div>
            </div>
            <div class="udash__stat" style="cursor:default;">
                <div class="udash__stat-icon udash__stat-icon--blue"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg></div>
                <div class="udash__stat-body"><div class="udash__stat-value"><?php echo number_format($myViews); ?></div><div class="udash__stat-label">Total Views</div></div>
            </div>
            <div class="udash__stat" style="cursor:default;">
                <div class="udash__stat-icon udash__stat-icon--orange"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 21s7-4.5 9.5-9A4.5 4.5 0 0 0 12 5.5 4.5 4.5 0 0 0 2.5 12c2.5 4.5 9.5 9 9.5 9z"/></svg></div>
                <div class="udash__stat-body"><div class="udash__stat-value"><?php echo $myFavorites; ?></div><div class="udash__stat-label">Favorites</div></div>
            </div>
        </div>

        <!-- Sales + Wallet stats -->
        <div class="udash__stats" style="margin-top:14px;">
            <a href="<?php echo APP_URL; ?>/pages/seller/orders.php" class="udash__stat">
                <div class="udash__stat-icon udash__stat-icon--brand"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 16h.01M8 16h.01M3 9h18M5 5h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/></svg></div>
                <div class="udash__stat-body"><div class="udash__stat-value"><?php echo $myOrders; ?></div><div class="udash__stat-label">Total Orders</div></div>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/seller/orders.php?status=confirmed" class="udash__stat">
                <div class="udash__stat-icon udash__stat-icon--orange"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></div>
                <div class="udash__stat-body"><div class="udash__stat-value"><?php echo $pendingOrders; ?></div><div class="udash__stat-label">Pending Orders</div></div>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/wallet.php" class="udash__stat">
                <div class="udash__stat-icon udash__stat-icon--green"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4M3 5v14a2 2 0 0 0 2 2h16v-5M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg></div>
                <div class="udash__stat-body"><div class="udash__stat-value"><?php echo e(format_price($walletBal['available'], 'RWF')); ?></div><div class="udash__stat-label">Available Balance</div></div>
            </a>
            <div class="udash__stat" style="cursor:default;">
                <div class="udash__stat-icon udash__stat-icon--blue"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg></div>
                <div class="udash__stat-body"><div class="udash__stat-value"><?php echo $avgRating > 0 ? number_format($avgRating,1).'★' : '—'; ?></div><div class="udash__stat-label">Avg Rating (<?php echo $reviewCount; ?>)</div></div>
            </div>
        </div>

        <!-- 7-day sales chart -->
        <div class="udash__panel" style="margin:20px 28px 0;">
            <div class="udash__panel-head">
                <h2>Sales — Last 7 Days</h2>
                <div style="font-size:13px;color:var(--text-mute);">Today: <strong style="color:var(--brand-600);"><?php echo e(format_price($todaySales, 'RWF')); ?></strong> • This Month: <strong><?php echo e(format_price($monthSales, 'RWF')); ?></strong></div>
            </div>
            <div style="display:flex;align-items:flex-end;gap:8px;height:140px;padding:12px 4px 0;border-bottom:1px solid var(--border);">
                <?php foreach ($salesData as $sd):
                    $maxVal = max(array_column($salesData, 'value'));
                    $barHeight = $maxVal > 0 ? max(4, ($sd['value'] / $maxVal) * 100) : 4;
                ?>
                    <div style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:6px;height:100%;">
                        <div style="width:100%;max-width:36px;height:<?php echo $barHeight; ?>%;min-height:4px;border-radius:6px 6px 3px 3px;background:linear-gradient(180deg,var(--brand-500),var(--brand-700));transition:height 300ms ease;" title="<?php echo e(format_price($sd['value'], 'RWF')); ?>"></div>
                        <div style="font-size:10px;color:var(--text-mute);font-weight:600;"><?php echo e($sd['day']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Two-column: listings + messages -->
        <div class="udash__grid">
            <div class="udash__col-main">
                <!-- Recent orders -->
                <?php if (!empty($recentOrders)): ?>
                <section class="udash__panel">
                    <div class="udash__panel-head">
                        <h2>Recent Orders</h2>
                        <a href="<?php echo APP_URL; ?>/pages/seller/orders.php" class="udash__panel-link">See all →</a>
                    </div>
                    <div class="udash__table-wrap">
                        <table class="udash__table">
                            <thead><tr><th>Order</th><th>Buyer</th><th>Product</th><th>Total</th><th>Status</th><th>Date</th></tr></thead>
                            <tbody>
                                <?php foreach ($recentOrders as $ro): $sc = ['pending'=>'warn','confirmed'=>'brand','preparing'=>'brand','ready'=>'brand','shipped'=>'blue','out_for_delivery'=>'blue','delivered'=>'good','completed'=>'good','cancelled'=>'bad','disputed'=>'bad'][$ro['status']] ?? 'mute'; ?>
                                    <tr>
                                        <td><a href="<?php echo APP_URL; ?>/pages/orders.php?id=" class="udash__link"><?php echo e($ro['order_number']); ?></a></td>
                                        <td><?php echo e($ro['buyer_name']); ?></td>
                                        <td><?php echo e($ro['listing_title']); ?></td>
                                        <td><?php echo e(format_price((float)$ro['grand_total'], $ro['currency'])); ?></td>
                                        <td><span class="udash__pill udash__pill--<?php echo $sc; ?>"><?php echo e($ro['status']); ?></span></td>
                                        <td class="udash__muted"><?php echo e(time_ago($ro['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
                <?php endif; ?>

                <!-- Recent listings -->
                <section class="udash__panel">
                    <div class="udash__panel-head">
                        <h2>Recent Listings</h2>
                        <a href="<?php echo APP_URL; ?>/pages/seller/listings.php" class="udash__panel-link">See all →</a>
                    </div>
                    <?php if (!$recent): ?>
                        <div class="udash__empty">
                            <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.3"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/></svg>
                            <h4>No listings yet</h4>
                            <p>Create your first listing to start selling.</p>
                            <a href="<?php echo APP_URL; ?>/pages/sell.php" class="btn btn--primary btn--sm">Create Listing</a>
                        </div>
                    <?php else: ?>
                        <div class="udash__table-wrap">
                            <table class="udash__table">
                                <thead><tr><th>Title</th><th>Type</th><th>Price</th><th>Status</th><th>Views</th><th>Posted</th></tr></thead>
                                <tbody>
                                    <?php foreach ($recent as $r): ?>
                                        <tr>
                                            <td><a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$r['id']; ?>" class="udash__link"><?php echo e($r['title']); ?></a></td>
                                            <td><span class="udash__pill <?php echo $r['listing_type']==='rent'?'udash__pill--blue':'udash__pill--brand'; ?>"><?php echo $r['listing_type']==='rent'?'Rent':'Sale'; ?></span></td>
                                            <td><?php echo e(format_price($r['price'], $r['currency'])); ?></td>
                                            <td><span class="udash__pill <?php echo $r['status']==='active'?'udash__pill--good':($r['status']==='pending'?'udash__pill--warn':'udash__pill--mute'); ?>"><?php echo e($r['status']); ?></span></td>
                                            <td class="udash__muted"><?php echo (int)$r['views_count']; ?></td>
                                            <td class="udash__muted"><?php echo e(time_ago($r['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- Products by Category -->
                <section class="udash__panel">
                    <div class="udash__panel-head"><h2>Products by Category</h2></div>
                    <?php if (empty($categoryStats)): ?>
                        <div class="udash__empty udash__empty--compact">
                            <p>No category data yet. Your listings will be counted by category here.</p>
                        </div>
                    <?php else: ?>
                        <div style="display:flex;flex-direction:column;gap:10px;">
                            <?php foreach ($categoryStats as $cs):
                                $maxCount = (int)$categoryStats[0]['count'];
                                $pct = $maxCount > 0 ? round(((int)$cs['count'] / $maxCount) * 100) : 0;
                            ?>
                                <div>
                                    <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;">
                                        <strong style="color:var(--text);"><?php echo e($cs['name']); ?></strong>
                                        <span style="color:var(--text-mute);"><?php echo (int)$cs['count']; ?></span>
                                    </div>
                                    <div style="height:7px;background:var(--bg-soft);border-radius:10px;overflow:hidden;">
                                        <div style="height:100%;width:<?php echo $pct; ?>%;background:linear-gradient(90deg,var(--brand-500),var(--brand-400));border-radius:10px;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <!-- Right column: messages -->
            <aside class="udash__col-side">
                <section class="udash__panel">
                    <div class="udash__panel-head">
                        <h2>Messages</h2>
                        <?php if ($chatUnread > 0): ?><span class="udash__pill udash__pill--warn"><?php echo $chatUnread; ?> unread</span><?php endif; ?>
                    </div>
                    <?php if (!empty($chatConvs)): ?>
                        <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:8px;">
                            <?php foreach ($chatConvs as $c): ?>
                                <li>
                                    <a href="<?php echo APP_URL; ?>/pages/seller/messages.php?conv=<?php echo (int)$c['id']; ?>" style="display:flex;gap:10px;text-decoration:none;color:inherit;padding:8px;border-radius:8px;transition:background 150ms;" onmouseover="this.style.background='var(--bg-soft)'" onmouseout="this.style.background='transparent'">
                                        <span style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--brand-500),var(--brand-700));color:#fff;display:grid;place-items:center;font-weight:700;font-size:12px;flex:0 0 32px;">
                                            <?php echo e(strtoupper(substr($c['buyer_name'], 0, 1))); ?>
                                        </span>
                                        <div style="flex:1;min-width:0;">
                                            <div style="display:flex;justify-content:space-between;gap:6px;">
                                                <strong style="font-size:12.5px;color:var(--text);"><?php echo e($c['buyer_name']); ?></strong>
                                                <small style="font-size:10.5px;color:var(--text-mute);white-space:nowrap;"><?php echo $c['last_msg_at'] ? e(time_ago($c['last_msg_at'])) : ''; ?></small>
                                            </div>
                                            <div style="font-size:11.5px;color:var(--text-mute);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;">
                                                <?php echo e($c['last_message'] ?? 'No messages'); ?>
                                            </div>
                                        </div>
                                        <?php if ((int)$c['unread'] > 0): ?>
                                            <span style="background:var(--accent-500);color:#fff;font-size:9px;font-weight:800;padding:2px 6px;border-radius:20px;flex:0 0 auto;align-self:center;"><?php echo (int)$c['unread']; ?></span>
                                        <?php endif; ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="<?php echo APP_URL; ?>/pages/seller/messages.php" class="udash__panel-link" style="display:block;text-align:center;margin-top:12px;">View all messages →</a>
                    <?php elseif (!empty($contactMsgs)): ?>
                        <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:8px;">
                            <?php foreach ($contactMsgs as $m): ?>
                                <li style="padding:8px;border-radius:8px;background:var(--bg-soft);">
                                    <strong style="font-size:12.5px;color:var(--text);"><?php echo e($m['name']); ?></strong>
                                    <p style="margin:2px 0 0;font-size:11.5px;color:var(--text-mute);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo e($m['message']); ?></p>
                                    <small style="font-size:10px;color:var(--text-mute);"><?php echo e(time_ago($m['created_at'])); ?></small>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="udash__empty udash__empty--compact">
                            <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.3"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                            <p>No messages yet</p>
                        </div>
                    <?php endif; ?>
                </section>
            </aside>
        </div>
    </main>
</div>

<!-- Mobile sidebar backdrop -->
<div class="udash__backdrop" id="udashBackdrop"></div>

<style>
.udash { display:grid; grid-template-columns:264px minmax(0,1fr); min-height:100vh; background:#fafafa; font-family:"Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
.udash__sidebar { position:sticky; top:0; align-self:start; height:100vh; overflow-y:auto; background:linear-gradient(180deg,#0a3d3a 0%,#082826 100%); color:#fff; padding:20px 16px; z-index:100; }
.udash__profile { display:flex; gap:12px; align-items:center; padding:14px; border:1px solid rgba(255,255,255,.06); background:rgba(255,255,255,.04); border-radius:14px; margin-bottom:20px; }
.udash__avatar { width:40px; height:40px; border-radius:11px; display:grid; place-items:center; background:linear-gradient(135deg,var(--brand-500),var(--brand-700)); color:#fff; font-weight:700; font-size:14px; flex:0 0 40px; }
.udash__profile-info { min-width:0; }
.udash__profile-info h4 { margin:0; font-size:14px; font-weight:600; color:#fff; }
.udash__caps { display:inline-block; margin-top:4px; font-size:10.5px; color:rgba(255,255,255,.55); }
.udash__nav-section { font-size:10.5px; font-weight:600; letter-spacing:.09em; text-transform:uppercase; color:rgba(255,255,255,.35); padding:16px 12px 6px; }
.udash__nav-link { display:flex; align-items:center; gap:11px; padding:9px 12px; border-radius:9px; color:rgba(255,255,255,.68); text-decoration:none; font-size:13.5px; font-weight:500; margin:1px 0; transition:.18s; }
.udash__nav-link svg { width:18px; height:18px; flex:0 0 auto; }
.udash__nav-link:hover { color:#fff; background:rgba(255,255,255,.06); }
.udash__nav-link.is-active { color:#fff; background:linear-gradient(135deg,var(--brand-500),var(--brand-600)); box-shadow:0 4px 12px rgba(20,165,148,.25); }
.udash__nav-link--danger { color:rgba(255,170,160,.7); }
.udash__nav-link--danger:hover { background:rgba(217,83,74,.12); color:#ffc4bd; }
.udash__nav-badge { margin-left:auto; min-width:19px; height:19px; padding:0 6px; display:inline-flex; align-items:center; justify-content:center; border-radius:10px; background:var(--accent-500); color:#fff; font-size:10px; font-weight:700; }
.udash__main { padding:0; min-width:0; }
.udash__topbar { display:flex; align-items:center; gap:14px; padding:14px 28px; background:rgba(255,255,255,.85); backdrop-filter:blur(12px); border-bottom:1px solid var(--border); position:sticky; top:0; z-index:50; }
.udash__brand { display:flex; align-items:center; gap:9px; text-decoration:none; color:var(--text); font-size:17px; font-weight:700; margin-right:8px; }
.udash__brand .brand__accent { color:var(--accent-500); }
.udash__menu-toggle { display:none; width:38px; height:38px; border:1px solid var(--border); background:var(--bg-card); border-radius:10px; align-items:center; justify-content:center; cursor:pointer; color:var(--text); }
.udash__topbar-actions { margin-left:auto; display:flex; align-items:center; gap:8px; }
.udash__iconbtn { width:38px; height:38px; display:grid; place-items:center; border:1px solid var(--border); background:var(--bg-card); border-radius:10px; color:var(--text-soft); text-decoration:none; position:relative; }
.udash__iconbtn-dot { position:absolute; top:7px; right:8px; width:8px; height:8px; background:var(--accent-500); border:2px solid var(--bg-card); border-radius:50%; }
.udash__welcome { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; flex-wrap:wrap; padding:32px 28px 8px; }
.udash__welcome h1 { margin:0; font-size:28px; font-weight:700; color:var(--text); letter-spacing:-.028em; }
.udash__welcome p { margin:8px 0 0; color:var(--text-mute); font-size:14px; }
.udash__stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; padding:24px 28px 0; }
.udash__stat { display:flex; align-items:center; gap:14px; padding:18px; background:var(--bg-card); border:1px solid var(--border); border-radius:14px; transition:.18s; }
.udash__stat-icon { width:44px; height:44px; border-radius:12px; display:grid; place-items:center; flex:0 0 44px; }
.udash__stat-icon--brand { background:var(--brand-50); color:var(--brand-700); }
.udash__stat-icon--blue { background:#eff5ff; color:#2563eb; }
.udash__stat-icon--orange { background:#fff7e9; color:#c4730a; }
.udash__stat-icon--green { background:#ecfdf3; color:#058555; }
.udash__stat-value { font-size:24px; font-weight:700; color:var(--text); }
.udash__stat-label { font-size:11.5px; color:var(--text-mute); margin-top:3px; }
.udash__grid { display:grid; grid-template-columns:minmax(0,1.7fr) minmax(280px,1fr); gap:20px; padding:24px 28px 48px; align-items:start; }
.udash__panel { background:var(--bg-card); border:1px solid var(--border); border-radius:14px; padding:20px; margin-bottom:20px; }
.udash__panel-head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:16px; }
.udash__panel-head h2 { margin:0; font-size:15px; font-weight:800; color:var(--text); }
.udash__panel-link { font-size:12.5px; font-weight:600; color:var(--brand-600); text-decoration:none; }
.udash__empty { text-align:center; padding:28px 16px; color:var(--text-mute); }
.udash__empty svg { color:var(--border-strong); margin-bottom:8px; }
.udash__empty h4 { margin:0 0 4px; font-size:14px; color:var(--text-soft); }
.udash__empty p { margin:0 0 12px; font-size:13px; }
.udash__empty--compact { padding:20px 12px; }
.udash__table-wrap { overflow-x:auto; }
.udash__table { width:100%; border-collapse:collapse; font-size:13px; min-width:520px; }
.udash__table th { text-align:left; padding:10px 12px; font-size:10.5px; text-transform:uppercase; letter-spacing:.06em; color:var(--text-mute); border-bottom:1px solid var(--border); background:var(--bg-soft); }
.udash__table td { padding:12px; border-bottom:1px solid var(--bg-soft); color:var(--text-soft); }
.udash__table tr:last-child td { border-bottom:0; }
.udash__table tbody tr:hover { background:var(--brand-50); }
.udash__link { color:var(--brand-600); font-weight:600; text-decoration:none; }
.udash__muted { color:var(--text-mute); font-size:12px; }
.udash__pill { display:inline-flex; align-items:center; padding:3px 10px; border-radius:20px; font-size:10.5px; font-weight:700; text-transform:capitalize; }
.udash__pill--good { background:#ecfdf3; color:#058555; }
.udash__pill--warn { background:#fff7e9; color:#c4730a; }
.udash__pill--bad { background:#fef3f2; color:#d9534a; }
.udash__pill--mute { background:var(--bg-soft); color:var(--text-mute); }
.udash__pill--blue { background:#eff5ff; color:#2563eb; }
.udash__pill--brand { background:var(--brand-50); color:var(--brand-700); }
.udash__backdrop { display:none; }
@media (max-width:960px) {
    .udash { grid-template-columns:1fr; }
    .udash__sidebar { position:fixed; left:-280px; top:0; width:270px; height:100vh; transition:left .25s ease; z-index:200; }
    .udash__sidebar.is-open { left:0; }
    .udash__menu-toggle { display:flex; }
    .udash__backdrop.is-open { display:block; position:fixed; inset:0; background:rgba(10,42,40,.5); z-index:150; }
    .udash__grid { grid-template-columns:1fr; }
    .udash__stats { grid-template-columns:repeat(2,1fr); }
}
@media (max-width:560px) {
    .udash__stats { grid-template-columns:1fr 1fr; gap:10px; padding:18px 18px 0; }
    .udash__welcome { padding:24px 18px 6px; }
    .udash__welcome h1 { font-size:23px; }
    .udash__grid { padding:18px 18px 36px; }
    .udash__topbar { padding:12px 18px; }
}
</style>
<script>
(function(){
    var toggle = document.getElementById('udashMenuToggle');
    var sidebar = document.getElementById('udashSidebar');
    var backdrop = document.getElementById('udashBackdrop');
    if (toggle && sidebar) {
        function open(){ sidebar.classList.add('is-open'); if (backdrop) backdrop.classList.add('is-open'); }
        function close(){ sidebar.classList.remove('is-open'); if (backdrop) backdrop.classList.remove('is-open'); }
        toggle.addEventListener('click', function(){ sidebar.classList.contains('is-open') ? close() : open(); });
        if (backdrop) backdrop.addEventListener('click', close);
        document.addEventListener('keydown', function(e){ if (e.key === 'Escape') close(); });
    }
    // Theme toggle
    var themeBtn = document.getElementById('udashThemeToggle');
    if (themeBtn) {
        themeBtn.addEventListener('click', function () {
            var html = document.documentElement;
            var next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', next);
            try { localStorage.setItem('isoko-theme', next); } catch (e) {}
        });
    }
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
