<?php
/**
 * pages/dashboard.php — Unified, role-aware user dashboard
 * --------------------------------------------------------------------
 * ONE dashboard for every user. Adapts to the user's capabilities:
 *
 *   - Buyers (USER role, is_seller=0) see buyer activity:
 *     favorites, rental requests, messages, notifications, recommendations.
 *
 *   - Sellers (SELLER role OR is_seller=1) see buyer activity PLUS seller
 *     activity: my listings, listing stats, buyer messages, etc.
 *
 *   - A buyer can become a seller WITHOUT creating a new account — the
 *     "Start selling" CTA links to /pages/sell.php, which sets is_seller=1
 *     and creates a seller_profiles row (existing project architecture).
 *
 * Every statistic is pulled from the real database. Every button links to
 * a real existing page. Empty states are professional and actionable.
 *
 * Security: require_login() + every query is scoped to the current user's
 * id via prepared statements. No user can see another user's private data.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/wallet.php';

require_login();

$uid  = (int) current_user()['id'];
$user = current_user();
$roleName  = strtoupper($user['role_name'] ?? '');
$canSell   = ((int)($user['is_seller'] ?? 0) === 1) || in_array($roleName, ['SELLER','ADMIN','SUPER_ADMIN'], true);
$firstName = explode(' ', trim($user['full_name'] ?? '?'))[0] ?: '?';

/* ----------------------------------------------------------------
 *  QUERIES — efficient, indexed, scoped to the current user
 * ---------------------------------------------------------------- */

// --- Wallet balance (safe if table doesn't exist yet) ---
$walletBal = wallet_balance($uid);

// --- Order counts (buyer + seller) ---
$buyerOrderCount = 0; $sellerOrderCount = 0;
try {
    $stmt = db()->prepare("SELECT COUNT(*) FROM orders WHERE buyer_id = ?");
    $stmt->execute([$uid]); $buyerOrderCount = (int) $stmt->fetchColumn();
    if ($canSell) {
        $stmt = db()->prepare("SELECT COUNT(*) FROM orders WHERE seller_id = ?");
        $stmt->execute([$uid]); $sellerOrderCount = (int) $stmt->fetchColumn();
    }
} catch (PDOException $e) { /* orders table might not exist yet */ }

// --- Unread messages (chat) ---
$unreadMsgs = unread_message_count($uid);

// --- Buyer-side stats ---
$stmt = db()->prepare("SELECT COUNT(*) FROM favorites WHERE user_id = ?");
$stmt->execute([$uid]); $favCount = (int) $stmt->fetchColumn();

$stmt = db()->prepare("SELECT COUNT(*) FROM rental_requests WHERE renter_id = ?");
$stmt->execute([$uid]); $rentalCount = (int) $stmt->fetchColumn();

$stmt = db()->prepare("SELECT COUNT(*) FROM contact_requests WHERE sender_id = ?");
$stmt->execute([$uid]); $msgSentCount = (int) $stmt->fetchColumn();

$stmt = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$uid]); $unreadNotif = (int) $stmt->fetchColumn();

// --- Seller-side stats (only if seller-capable) ---
$myListings = $approvedListings = $pendingListings = $rejectedListings = 0;
$totalViews = $favReceived = $buyerMsgCount = 0;
if ($canSell) {
    $stmt = db()->prepare("SELECT COUNT(*) FROM listings WHERE seller_id = ?");
    $stmt->execute([$uid]); $myListings = (int) $stmt->fetchColumn();

    $stmt = db()->prepare("SELECT COUNT(*) FROM listings WHERE seller_id = ? AND status='active'");
    $stmt->execute([$uid]); $approvedListings = (int) $stmt->fetchColumn();

    $stmt = db()->prepare("SELECT COUNT(*) FROM listings WHERE seller_id = ? AND status='pending'");
    $stmt->execute([$uid]); $pendingListings = (int) $stmt->fetchColumn();

    $stmt = db()->prepare("SELECT COUNT(*) FROM listings WHERE seller_id = ? AND status='rejected'");
    $stmt->execute([$uid]); $rejectedListings = (int) $stmt->fetchColumn();

    $stmt = db()->prepare("SELECT COALESCE(SUM(views_count),0) FROM listings WHERE seller_id = ?");
    $stmt->execute([$uid]); $totalViews = (int) $stmt->fetchColumn();

    $stmt = db()->prepare("SELECT COALESCE(SUM(favorites_count),0) FROM listings WHERE seller_id = ?");
    $stmt->execute([$uid]); $favReceived = (int) $stmt->fetchColumn();

    $stmt = db()->prepare("SELECT COUNT(*) FROM contact_requests WHERE seller_id = ?");
    $stmt->execute([$uid]); $buyerMsgCount = (int) $stmt->fetchColumn();
}

// --- Recent favorites (buyer) ---
$stmt = db()->prepare(
    "SELECT l.id, l.title, l.slug, l.price, l.currency, l.listing_type, l.location,
            l.availability, l.status,
            (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image,
            c.name AS category_name
       FROM favorites f
       INNER JOIN listings l ON l.id = f.listing_id
       INNER JOIN categories c ON c.id = l.category_id
      WHERE f.user_id = ?
      ORDER BY f.created_at DESC
      LIMIT 6"
);
$stmt->execute([$uid]); $favorites = $stmt->fetchAll();

// --- My listings (seller) ---
$myListingsRows = [];
if ($canSell) {
    $stmt = db()->prepare(
        "SELECT l.id, l.title, l.slug, l.price, l.currency, l.listing_type,
                l.availability, l.status, l.views_count, l.favorites_count, l.created_at,
                (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image,
                c.name AS category_name
           FROM listings l
           INNER JOIN categories c ON c.id = l.category_id
          WHERE l.seller_id = ?
          ORDER BY l.created_at DESC
          LIMIT 6"
    );
    $stmt->execute([$uid]); $myListingsRows = $stmt->fetchAll();
}

// --- My rental requests (buyer perspective) ---
$stmt = db()->prepare(
    "SELECT r.id, r.start_date, r.end_date, r.status, r.created_at,
            l.id AS listing_id, l.title, l.slug, l.price, l.currency,
            (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image
       FROM rental_requests r
       INNER JOIN listings l ON l.id = r.listing_id
      WHERE r.renter_id = ?
      ORDER BY r.created_at DESC
      LIMIT 5"
);
$stmt->execute([$uid]); $rentals = $stmt->fetchAll();

// --- Messages (buyer-sent + seller-received) ---
$msgWhere = $canSell ? '(cr.sender_id = ? OR cr.seller_id = ?)' : 'cr.sender_id = ?';
$msgSql =
    "SELECT cr.id, cr.name, cr.email, cr.message, cr.is_read, cr.created_at,
            cr.listing_id, l.title, l.slug,
            CASE WHEN cr.seller_id = ? THEN 'received' ELSE 'sent' END AS direction
       FROM contact_requests cr
       INNER JOIN listings l ON l.id = cr.listing_id
      WHERE " . $msgWhere . "
      ORDER BY cr.created_at DESC
      LIMIT 6";
if ($canSell) {
    $stmt = db()->prepare($msgSql);
    $stmt->execute([$uid, $uid, $uid]);
} else {
    $stmt = db()->prepare($msgSql);
    $stmt->execute([$uid, $uid]);
}
$messages = $stmt->fetchAll();

// --- Notifications ---
$stmt = db()->prepare(
    "SELECT id, type, title, body, link, is_read, created_at
       FROM notifications
      WHERE user_id = ?
      ORDER BY created_at DESC
      LIMIT 5"
);
$stmt->execute([$uid]); $notifications = $stmt->fetchAll();

// --- Recommended listings (featured + most viewed) ---
$stmt = db()->query(
    "SELECT l.id, l.title, l.slug, l.price, l.currency, l.listing_type, l.location,
            (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image,
            c.name AS category_name
       FROM listings l
       INNER JOIN categories c ON c.id = l.category_id
      WHERE l.status = 'active' AND l.availability = 'available'
      ORDER BY l.is_featured DESC, l.views_count DESC
      LIMIT 4"
);
$recommended = $stmt->fetchAll();

// --- Contextual welcome message ---
if ($canSell && $pendingListings > 0) {
    $welcomeSub = t('dash.welcome_pending', ['count' => $pendingListings]);
} elseif ($favCount > 0) {
    $welcomeSub = t('dash.welcome_favs', ['count' => $favCount]);
} elseif ($unreadNotif > 0) {
    $welcomeSub = t('dash.welcome_notif', ['count' => $unreadNotif]);
} else {
    $welcomeSub = t('dash.welcome_default');
}

$pageTitle = t('dash.title');
$activePage = 'dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="udash">
    <!-- ============ SIDEBAR ============ -->
    <aside class="udash__sidebar" id="udashSidebar">
        <div class="udash__profile">
            <span class="udash__avatar"><?php echo e(strtoupper(substr($user['full_name'], 0, 1))); ?></span>
            <div class="udash__profile-info">
                <h4><?php echo e($user['full_name']); ?></h4>
                <span class="udash__caps">
                    <?php if ($canSell): ?>
                        <?php echo e(t('dash.cap_buyer_seller')); ?>
                    <?php else: ?>
                        <?php echo e(t('dash.cap_buyer')); ?>
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <nav class="udash__nav">
            <div class="udash__nav-section"><?php echo e(t('dash.nav_main')); ?></div>
            <a href="<?php echo APP_URL; ?>/pages/dashboard.php" class="udash__nav-link is-active">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                <span><?php echo e(t('dash.nav_dashboard')); ?></span>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/explore.php" class="udash__nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="11" cy="11" r="7"/><path d="m21 21-4-4" stroke-linecap="round"/></svg>
                <span><?php echo e(t('dash.nav_marketplace')); ?></span>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/cart.php" class="udash__nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/></svg>
                <span>Cart</span>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/orders.php" class="udash__nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M16 16h.01M8 16h.01M3 9h18M5 5h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/></svg>
                <span>Orders</span>
                <?php if ($buyerOrderCount > 0): ?><span class="udash__nav-badge"><?php echo $buyerOrderCount; ?></span><?php endif; ?>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/messages.php" class="udash__nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <span>Messages</span>
                <?php if ($unreadMsgs > 0): ?><span class="udash__nav-badge"><?php echo $unreadMsgs; ?></span><?php endif; ?>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/favorites.php" class="udash__nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 21s7-4.5 9.5-9A4.5 4.5 0 0 0 12 5.5 4.5 4.5 0 0 0 2.5 12c2.5 4.5 9.5 9 9.5 9z"/></svg>
                <span><?php echo e(t('dash.nav_favorites')); ?></span>
                <?php if ($favCount > 0): ?><span class="udash__nav-badge"><?php echo $favCount; ?></span><?php endif; ?>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/notifications.php" class="udash__nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/></svg>
                <span><?php echo e(t('dash.nav_notifications')); ?></span>
                <?php if ($unreadNotif > 0): ?><span class="udash__nav-badge"><?php echo $unreadNotif; ?></span><?php endif; ?>
            </a>

            <?php if ($canSell): ?>
            <div class="udash__nav-section"><?php echo e(t('dash.nav_selling')); ?></div>
            <a href="<?php echo APP_URL; ?>/pages/seller/listings.php" class="udash__nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/></svg>
                <span><?php echo e(t('dash.nav_my_listings')); ?></span>
                <?php if ($myListings > 0): ?><span class="udash__nav-badge"><?php echo $myListings; ?></span><?php endif; ?>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/sell.php" class="udash__nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>
                <span><?php echo e(t('dash.nav_create_listing')); ?></span>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/seller/messages.php" class="udash__nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <span><?php echo e(t('dash.nav_buyer_messages')); ?></span>
                <?php if ($buyerMsgCount > 0): ?><span class="udash__nav-badge"><?php echo $buyerMsgCount; ?></span><?php endif; ?>
            </a>
            <?php if (in_array($roleName, ['ADMIN','SUPER_ADMIN'], true)): ?>
            <a href="<?php echo APP_URL; ?>/pages/admin/dashboard.php" class="udash__nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 2 2 22h20L12 2z"/><path d="M12 9v4"/></svg>
                <span><?php echo e(t('dash.nav_admin')); ?></span>
            </a>
            <?php endif; ?>
            <?php endif; ?>

            <div class="udash__nav-section"><?php echo e(t('dash.nav_account')); ?></div>
            <a href="<?php echo APP_URL; ?>/pages/profile.php" class="udash__nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                <span><?php echo e(t('dash.nav_profile')); ?></span>
            </a>
            <?php if ($canSell): ?>
            <a href="<?php echo APP_URL; ?>/pages/seller/settings.php" class="udash__nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>
                <span>Seller Settings</span>
            </a>
            <?php endif; ?>
            <a href="<?php echo APP_URL; ?>/pages/wallet.php" class="udash__nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4M3 5v14a2 2 0 0 0 2 2h16v-5M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
                <span>Wallet</span>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/logout.php" class="udash__nav-link udash__nav-link--danger">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                <span><?php echo e(t('dash.nav_logout')); ?></span>
            </a>
        </nav>
    </aside>

    <!-- ============ MAIN ============ -->
    <main class="udash__main">
        <!-- Top bar (brand + mobile menu toggle + contextual) -->
        <div class="udash__topbar">
            <a href="<?php echo APP_URL; ?>/" class="udash__brand" title="<?php echo e(t('nav.home')); ?>">
                <?php echo brand_mark_html(28); ?>
                <span>Isoko<span class="brand__accent">Ryacu</span></span>
            </a>
            <button class="udash__menu-toggle" id="udashMenuToggle" type="button" aria-label="Menu">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18" stroke-linecap="round"/></svg>
            </button>
            <div class="udash__topbar-actions">
                <a href="<?php echo APP_URL; ?>/pages/notifications.php" class="udash__iconbtn" title="<?php echo e(t('dash.nav_notifications')); ?>">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/></svg>
                    <?php if ($unreadNotif > 0): ?><span class="udash__iconbtn-dot"></span><?php endif; ?>
                </a>
                <a href="<?php echo APP_URL; ?>/pages/seller/messages.php" class="udash__iconbtn" title="<?php echo e(t('dash.messages')); ?>">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <?php $totalMsg = $msgSentCount + $buyerMsgCount; if ($totalMsg > 0): ?><span class="udash__iconbtn-dot"></span><?php endif; ?>
                </a>
                <button class="udash__iconbtn udash__iconbtn--danger" type="button" title="<?php echo e(t('dash.nav_logout')); ?>" aria-label="<?php echo e(t('dash.nav_logout')); ?>"
                        onclick="if(typeof confirmAction==='function'){confirmAction({type:'warning',title:'Sign out?',message:'Are you sure you want to sign out of your account?',confirmText:'Sign Out',cancelText:'Cancel',onConfirm:function(){window.location.href='<?php echo APP_URL; ?>/pages/logout.php';return true;}});return false;}window.location.href='<?php echo APP_URL; ?>/pages/logout.php';">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                </button>
                <?php if ($canSell): ?>
                <a href="<?php echo APP_URL; ?>/pages/sell.php" class="btn btn--primary btn--sm">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>
                    <?php echo e(t('dash.create_listing')); ?>
                </a>
                <?php else: ?>
                <a href="<?php echo APP_URL; ?>/pages/sell.php" class="btn btn--primary btn--sm">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" stroke-linecap="round" stroke-linejoin="round"/><circle cx="8.5" cy="7" r="4"/></svg>
                    <?php echo e(t('dash.start_selling')); ?>
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Welcome -->
        <div class="udash__welcome">
            <div>
                <h1><?php echo e(t('dash.welcome_back', ['name' => $firstName])); ?> 👋</h1>
                <p><?php echo e($welcomeSub); ?></p>
            </div>
            <a href="<?php echo APP_URL; ?>/pages/explore.php" class="btn btn--outline btn--sm udash__welcome-cta">
                <?php echo e(t('dash.explore')); ?>
            </a>
        </div>

        <!-- Start selling banner (only for non-sellers) -->
        <?php if (!$canSell): ?>
        <div class="udash__banner">
            <div class="udash__banner-text">
                <h3><?php echo e(t('dash.banner_title')); ?></h3>
                <p><?php echo e(t('dash.banner_sub')); ?></p>
            </div>
            <a href="<?php echo APP_URL; ?>/pages/sell.php" class="btn btn--primary">
                <?php echo e(t('dash.start_selling')); ?> →
            </a>
        </div>
        <?php endif; ?>

        <!-- Wallet card (prominent, full-width) -->
        <a href="<?php echo APP_URL; ?>/pages/wallet.php" class="udash__wallet-card">
            <div class="udash__wallet-left">
                <div class="udash__wallet-label">Wallet Balance</div>
                <div class="udash__wallet-amount"><?php echo e(format_price($walletBal['available'], $walletBal['currency'])); ?></div>
                <div class="udash__wallet-sub">
                    <span>Pending: <strong><?php echo e(format_price($walletBal['pending'], $walletBal['currency'])); ?></strong></span>
                    <?php if ($canSell): ?>
                    <span>Earnings: <strong><?php echo e(format_price($walletBal['earnings'], $walletBal['currency'])); ?></strong></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="udash__wallet-right">
                <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4M3 5v14a2 2 0 0 0 2 2h16v-5M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
            </div>
        </a>

        <!-- Summary cards -->
        <div class="udash__stats">
            <!-- Buyer stats (always shown) -->
            <a href="<?php echo APP_URL; ?>/pages/favorites.php" class="udash__stat">
                <div class="udash__stat-icon udash__stat-icon--brand">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 21s7-4.5 9.5-9A4.5 4.5 0 0 0 12 5.5 4.5 4.5 0 0 0 2.5 12c2.5 4.5 9.5 9 9.5 9z"/></svg>
                </div>
                <div class="udash__stat-body">
                    <div class="udash__stat-value"><?php echo $favCount; ?></div>
                    <div class="udash__stat-label"><?php echo e(t('dash.stat_favorites')); ?></div>
                </div>
            </a>
            <a href="#rentals" class="udash__stat">
                <div class="udash__stat-icon udash__stat-icon--blue">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                </div>
                <div class="udash__stat-body">
                    <div class="udash__stat-value"><?php echo $rentalCount; ?></div>
                    <div class="udash__stat-label"><?php echo e(t('dash.stat_rentals')); ?></div>
                </div>
            </a>
            <a href="#messages" class="udash__stat">
                <div class="udash__stat-icon udash__stat-icon--orange">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                </div>
                <div class="udash__stat-body">
                    <div class="udash__stat-value"><?php echo $msgSentCount + $buyerMsgCount; ?></div>
                    <div class="udash__stat-label"><?php echo e(t('dash.stat_messages')); ?></div>
                </div>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/notifications.php" class="udash__stat">
                <div class="udash__stat-icon udash__stat-icon--green">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/></svg>
                </div>
                <div class="udash__stat-body">
                    <div class="udash__stat-value"><?php echo $unreadNotif; ?></div>
                    <div class="udash__stat-label"><?php echo e(t('dash.stat_notifications')); ?></div>
                </div>
            </a>

            <!-- Seller stats (only if seller-capable) -->
            <?php if ($canSell): ?>
            <a href="<?php echo APP_URL; ?>/pages/seller/listings.php" class="udash__stat">
                <div class="udash__stat-icon udash__stat-icon--brand">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/></svg>
                </div>
                <div class="udash__stat-body">
                    <div class="udash__stat-value"><?php echo $myListings; ?></div>
                    <div class="udash__stat-label"><?php echo e(t('dash.stat_total_listings')); ?></div>
                </div>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/seller/listings.php?status=active" class="udash__stat">
                <div class="udash__stat-icon udash__stat-icon--green">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 6 9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div class="udash__stat-body">
                    <div class="udash__stat-value"><?php echo $approvedListings; ?></div>
                    <div class="udash__stat-label"><?php echo e(t('dash.stat_approved')); ?></div>
                </div>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/seller/listings.php?status=pending" class="udash__stat">
                <div class="udash__stat-icon udash__stat-icon--orange">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                </div>
                <div class="udash__stat-body">
                    <div class="udash__stat-value"><?php echo $pendingListings; ?></div>
                    <div class="udash__stat-label"><?php echo e(t('dash.stat_pending')); ?></div>
                </div>
            </a>
            <div class="udash__stat" style="cursor:default;">
                <div class="udash__stat-icon udash__stat-icon--blue">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                </div>
                <div class="udash__stat-body">
                    <div class="udash__stat-value"><?php echo $totalViews; ?></div>
                    <div class="udash__stat-label"><?php echo e(t('dash.stat_views')); ?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Two-column layout: main panels + sidebar -->
        <div class="udash__grid">
            <div class="udash__col-main">

                <!-- MY LISTINGS (sellers only) -->
                <?php if ($canSell): ?>
                <section class="udash__panel">
                    <div class="udash__panel-head">
                        <h2><?php echo e(t('dash.my_listings')); ?></h2>
                        <a href="<?php echo APP_URL; ?>/pages/seller/listings.php" class="udash__panel-link"><?php echo e(t('dash.see_all')); ?> →</a>
                    </div>
                    <?php if (!$myListingsRows): ?>
                        <div class="udash__empty">
                            <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.3"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/></svg>
                            <h4><?php echo e(t('dash.no_listings')); ?></h4>
                            <p><?php echo e(t('dash.no_listings_sub')); ?></p>
                            <a href="<?php echo APP_URL; ?>/pages/sell.php" class="btn btn--primary btn--sm"><?php echo e(t('dash.create_listing')); ?></a>
                        </div>
                    <?php else: ?>
                        <div class="udash__listing-grid">
                            <?php foreach ($myListingsRows as $l):
                                $img = image_or_default($l['image'] ?? null);
                                $statusClass = ['active'=>'good','pending'=>'warn','rejected'=>'bad','expired'=>'mute','draft'=>'mute'][$l['status']] ?? 'mute';
                            ?>
                                <div class="udash__listing-card">
                                    <div class="udash__listing-img">
                                        <img src="<?php echo e($img); ?>" alt="<?php echo e($l['title']); ?>" loading="lazy">
                                        <span class="udash__listing-status udash__listing-status--<?php echo $statusClass; ?>"><?php echo e($l['status']); ?></span>
                                    </div>
                                    <div class="udash__listing-body">
                                        <strong><?php echo e($l['title']); ?></strong>
                                        <span class="udash__listing-price"><?php echo e(format_price($l['price'], $l['currency'])); ?></span>
                                        <div class="udash__listing-meta">
                                            <span>👁 <?php echo (int)$l['views_count']; ?></span>
                                            <span>♥ <?php echo (int)$l['favorites_count']; ?></span>
                                            <span><?php echo e(time_ago($l['created_at'])); ?></span>
                                        </div>
                                    </div>
                                    <div class="udash__listing-actions">
                                        <a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$l['id']; ?>" class="udash__mini-btn" title="<?php echo e(t('dash.view')); ?>">👁</a>
                                        <a href="<?php echo APP_URL; ?>/pages/sell.php?edit=<?php echo (int)$l['id']; ?>" class="udash__mini-btn" title="<?php echo e(t('dash.edit')); ?>">✎</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
                <?php endif; ?>

                <!-- MY FAVORITES -->
                <section class="udash__panel" id="favorites">
                    <div class="udash__panel-head">
                        <h2><?php echo e(t('dash.my_favorites')); ?></h2>
                        <?php if ($favorites): ?>
                            <a href="<?php echo APP_URL; ?>/pages/favorites.php" class="udash__panel-link"><?php echo e(t('dash.see_all')); ?> →</a>
                        <?php endif; ?>
                    </div>
                    <?php if (!$favorites): ?>
                        <div class="udash__empty">
                            <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.3"><path d="M12 21s7-4.5 9.5-9A4.5 4.5 0 0 0 12 5.5 4.5 4.5 0 0 0 2.5 12c2.5 4.5 9.5 9 9.5 9z"/></svg>
                            <h4><?php echo e(t('dash.no_favorites')); ?></h4>
                            <p><?php echo e(t('dash.no_favorites_sub')); ?></p>
                            <a href="<?php echo APP_URL; ?>/pages/explore.php" class="btn btn--primary btn--sm"><?php echo e(t('dash.explore_marketplace')); ?></a>
                        </div>
                    <?php else: ?>
                        <div class="udash__fav-grid">
                            <?php foreach ($favorites as $f):
                                $img = image_or_default($f['image'] ?? null);
                            ?>
                                <a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$f['id']; ?>" class="udash__fav-card">
                                    <div class="udash__fav-img">
                                        <img src="<?php echo e($img); ?>" alt="<?php echo e($f['title']); ?>" loading="lazy">
                                        <span class="udash__fav-type"><?php echo $f['listing_type']==='rent' ? e(t('dash.for_rent')) : e(t('dash.for_sale')); ?></span>
                                    </div>
                                    <div class="udash__fav-body">
                                        <strong><?php echo e($f['title']); ?></strong>
                                        <span class="udash__fav-price"><?php echo e(format_price($f['price'], $f['currency'])); ?></span>
                                        <span class="udash__fav-cat"><?php echo e($f['category_name']); ?></span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- MY RENTAL REQUESTS -->
                <section class="udash__panel" id="rentals">
                    <div class="udash__panel-head"><h2><?php echo e(t('dash.my_rentals')); ?></h2></div>
                    <?php if (!$rentals): ?>
                        <div class="udash__empty udash__empty--compact">
                            <p><?php echo e(t('dash.no_rentals')); ?></p>
                        </div>
                    <?php else: ?>
                        <div class="udash__table-wrap">
                            <table class="udash__table">
                                <thead><tr>
                                    <th><?php echo e(t('dash.col_listing')); ?></th>
                                    <th><?php echo e(t('dash.col_dates')); ?></th>
                                    <th><?php echo e(t('dash.col_status')); ?></th>
                                    <th><?php echo e(t('dash.col_requested')); ?></th>
                                </tr></thead>
                                <tbody>
                                    <?php foreach ($rentals as $r):
                                        $sc = ['pending'=>'warn','approved'=>'good','declined'=>'bad','cancelled'=>'mute','completed'=>'blue'][$r['status']] ?? 'mute';
                                    ?>
                                    <tr>
                                        <td><a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$r['listing_id']; ?>" class="udash__link"><?php echo e($r['title']); ?></a></td>
                                        <td class="udash__muted"><?php echo e(date('M j, Y', strtotime($r['start_date']))); ?> → <?php echo e(date('M j, Y', strtotime($r['end_date']))); ?></td>
                                        <td><span class="udash__pill udash__pill--<?php echo $sc; ?>"><?php echo e($r['status']); ?></span></td>
                                        <td class="udash__muted"><?php echo e(time_ago($r['created_at'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- MESSAGES -->
                <section class="udash__panel" id="messages">
                    <div class="udash__panel-head">
                        <h2><?php echo e(t('dash.messages')); ?></h2>
                        <?php if ($canSell): ?>
                            <a href="<?php echo APP_URL; ?>/pages/seller/messages.php" class="udash__panel-link"><?php echo e(t('dash.see_all')); ?> →</a>
                        <?php endif; ?>
                    </div>
                    <?php if (!$messages): ?>
                        <div class="udash__empty udash__empty--compact">
                            <p><?php echo e(t('dash.no_messages')); ?></p>
                        </div>
                    <?php else: ?>
                        <ul class="udash__msg-list">
                            <?php foreach ($messages as $m): ?>
                                <li class="udash__msg <?php echo !$m['is_read'] ? 'udash__msg--unread' : ''; ?>">
                                    <div class="udash__msg-head">
                                        <strong><?php echo e($m['direction']==='received' ? t('dash.msg_from') : t('dash.msg_to')); ?>: <a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$m['listing_id']; ?>" class="udash__link"><?php echo e($m['title']); ?></a></strong>
                                        <small><?php echo e(time_ago($m['created_at'])); ?></small>
                                    </div>
                                    <p><?php echo e($m['message']); ?></p>
                                    <?php if (!$m['is_read']): ?><span class="udash__pill udash__pill--brand"><?php echo e(t('dash.unread')); ?></span><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>

            </div>

            <!-- RIGHT SIDEBAR -->
            <aside class="udash__col-side">

                <!-- NOTIFICATIONS -->
                <section class="udash__panel">
                    <div class="udash__panel-head">
                        <h2><?php echo e(t('dash.notifications')); ?></h2>
                        <a href="<?php echo APP_URL; ?>/pages/notifications.php" class="udash__panel-link"><?php echo e(t('dash.view_all')); ?></a>
                    </div>
                    <?php if (!$notifications): ?>
                        <div class="udash__empty udash__empty--compact">
                            <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.3"><path d="M20 6 9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <p><?php echo e(t('dash.all_clear')); ?></p>
                        </div>
                    <?php else: ?>
                        <ul class="udash__notif-list">
                            <?php foreach ($notifications as $n): ?>
                                <li class="udash__notif <?php echo !$n['is_read'] ? 'udash__notif--unread' : ''; ?>">
                                    <a href="<?php echo e($n['link'] ?: APP_URL . '/pages/notifications.php'); ?>" class="udash__notif-link">
                                        <span class="udash__notif-type"><?php echo e($n['type']); ?></span>
                                        <strong><?php echo e($n['title']); ?></strong>
                                        <?php if ($n['body']): ?><span><?php echo e($n['body']); ?></span><?php endif; ?>
                                        <small><?php echo e(time_ago($n['created_at'])); ?></small>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>

                <!-- RECOMMENDED -->
                <section class="udash__panel">
                    <div class="udash__panel-head"><h2><?php echo e(t('dash.recommended')); ?></h2></div>
                    <?php if (!$recommended): ?>
                        <div class="udash__empty udash__empty--compact"><p><?php echo e(t('dash.no_recommendations')); ?></p></div>
                    <?php else: ?>
                        <ul class="udash__rec-list">
                            <?php foreach ($recommended as $r):
                                $img = image_or_default($r['image'] ?? null);
                            ?>
                                <li>
                                    <a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$r['id']; ?>">
                                        <img src="<?php echo e($img); ?>" alt="" loading="lazy">
                                        <div>
                                            <strong><?php echo e($r['title']); ?></strong>
                                            <span class="udash__rec-price"><?php echo e(format_price($r['price'], $r['currency'])); ?></span>
                                            <span class="udash__rec-loc"><?php echo e($r['location'] ?? ''); ?></span>
                                        </div>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>

                <!-- PROFILE MINI -->
                <section class="udash__panel udash__profile-mini">
                    <div class="udash__panel-head"><h2><?php echo e(t('dash.profile')); ?></h2></div>
                    <div class="udash__profile-mini-body">
                        <span class="udash__avatar udash__avatar--lg"><?php echo e(strtoupper(substr($user['full_name'], 0, 1))); ?></span>
                        <h3><?php echo e($user['full_name']); ?></h3>
                        <p class="udash__profile-email"><?php echo e($user['email']); ?></p>
                        <div class="udash__profile-caps">
                            <span class="udash__pill udash__pill--brand"><?php echo e(t('dash.cap_buyer')); ?></span>
                            <?php if ($canSell): ?><span class="udash__pill udash__pill--good"><?php echo e(t('dash.cap_seller')); ?></span><?php endif; ?>
                        </div>
                        <a href="<?php echo APP_URL; ?>/pages/profile.php" class="btn btn--outline btn--sm udash__profile-edit"><?php echo e(t('dash.edit_profile')); ?></a>
                    </div>
                </section>

            </aside>
        </div>
    </main>
</div>

<!-- Mobile sidebar backdrop -->
<div class="udash__backdrop" id="udashBackdrop"></div>

<style>
/* ============ UNIFIED DASHBOARD — professional design system ============ */
/* Inter font (Linear/Stripe/Vercel standard) */
@import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap");

/* Hide the public navbar + bottom nav + footer + skip-link on the dashboard
   page so the dashboard has its OWN chrome (sidebar + topbar) and doesn't
   end up with a cluttered double-header. Same pattern the admin pages use
   via body.admin. */
body.page.dashboard .navbar,
body.page.dashboard .bottom-nav,
body.page.dashboard .footer,
body.page.dashboard .skip-link { display: none !important; }
body.page.dashboard { background: #fafafa; padding: 0 !important; }
body.page.dashboard .main { margin: 0 !important; padding: 0 !important; min-height: 100vh; }

.udash {
    --u-font: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    --u-bg: #fafafa;
    --u-surface: #ffffff;
    --u-border: #eceef0;
    --u-border-soft: #f3f4f6;
    --u-text: #0d1f1d;
    --u-text-soft: #496461;
    --u-text-mute: #8a9b98;
    --u-brand: #14a594;
    --u-brand-dark: #0c8478;
    --u-brand-soft: #effcf9;
    --u-accent: #ec9416;
    --u-shadow-xs: 0 1px 2px rgba(15,41,38,.04);
    --u-shadow-sm: 0 1px 3px rgba(15,41,38,.06), 0 1px 2px rgba(15,41,38,.04);
    --u-shadow-md: 0 4px 12px rgba(15,41,38,.07), 0 2px 4px rgba(15,41,38,.04);
    --u-shadow-lg: 0 12px 32px rgba(15,41,38,.10), 0 4px 8px rgba(15,41,38,.05);
    --u-shadow-xl: 0 24px 56px rgba(15,41,38,.14), 0 8px 16px rgba(15,41,38,.06);
    --u-radius: 10px;
    --u-radius-lg: 14px;
    --u-radius-xl: 18px;
    --u-transition: 160ms cubic-bezier(.4,0,.2,1);
    font-family: var(--u-font);
    display: grid;
    grid-template-columns: 264px minmax(0, 1fr);
    min-height: 100vh;
    background: var(--u-bg);
    color: var(--u-text);
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

/* ============ SIDEBAR ============ */
.udash__sidebar {
    position: sticky; top: 0; align-self: start;
    height: 100vh; overflow-y: auto;
    background: #0a2a28;
    color: #fff;
    padding: 20px 16px;
    z-index: 100;
    border-right: 1px solid rgba(255,255,255,.06);
}
.udash__sidebar::-webkit-scrollbar { width: 4px; }
.udash__sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,.12); border-radius: 10px; }
.udash__sidebar::-webkit-scrollbar-track { background: transparent; }

.udash__profile {
    display: flex; gap: 12px; align-items: center;
    padding: 14px; margin-bottom: 20px;
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.06);
    border-radius: var(--u-radius-lg);
    transition: var(--u-transition);
}
.udash__profile:hover { background: rgba(255,255,255,.07); }
.udash__avatar {
    width: 40px; height: 40px;
    border-radius: 11px;
    display: grid; place-items: center;
    background: linear-gradient(135deg, var(--u-brand), var(--u-brand-dark));
    color: #fff; font-weight: 700; font-size: 14px;
    flex: 0 0 40px;
    letter-spacing: -.02em;
    box-shadow: 0 2px 8px rgba(20,165,148,.3), inset 0 1px 0 rgba(255,255,255,.2);
}
.udash__avatar--lg {
    width: 72px; height: 72px; font-size: 24px; flex: 0 0 72px;
    margin: 0 auto 12px;
    border-radius: 18px;
}
.udash__profile-info { min-width: 0; }
.udash__profile-info h4 {
    margin: 0; font-size: 14px; font-weight: 600; color: #fff;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.udash__caps {
    display: inline-block; margin-top: 4px;
    font-size: 10.5px; font-weight: 500;
    color: rgba(255,255,255,.55);
    letter-spacing: .01em;
}

.udash__nav-section {
    font-size: 10.5px; font-weight: 600;
    letter-spacing: .09em; text-transform: uppercase;
    color: rgba(255,255,255,.35);
    padding: 16px 12px 6px;
}
.udash__nav-link {
    display: flex; align-items: center; gap: 11px;
    padding: 9px 12px;
    border-radius: 9px;
    color: rgba(255,255,255,.68);
    text-decoration: none;
    font-size: 13.5px; font-weight: 500;
    margin: 1px 0;
    transition: var(--u-transition);
    position: relative;
}
.udash__nav-link svg { width: 18px; height: 18px; flex: 0 0 auto; stroke-width: 1.7; }
.udash__nav-link:hover {
    color: #fff;
    background: rgba(255,255,255,.06);
}
.udash__nav-link.is-active {
    color: #fff;
    background: linear-gradient(135deg, var(--u-brand), var(--u-brand-dark));
    box-shadow: 0 4px 12px rgba(20,165,148,.25), inset 0 1px 0 rgba(255,255,255,.15);
    font-weight: 600;
}
.udash__nav-link.is-active::before {
    content: ""; position: absolute; left: -16px; top: 50%; transform: translateY(-50%);
    width: 3px; height: 20px; background: var(--u-accent); border-radius: 0 3px 3px 0;
}
.udash__nav-link--danger { color: rgba(255,170,160,.7); }
.udash__nav-link--danger:hover { background: rgba(217,83,74,.12); color: #ffc4bd; }
.udash__nav-badge {
    margin-left: auto; min-width: 19px; height: 19px;
    padding: 0 6px;
    display: inline-flex; align-items: center; justify-content: center;
    border-radius: 10px;
    background: var(--u-accent); color: #fff;
    font-size: 10px; font-weight: 700;
    box-shadow: 0 2px 6px rgba(236,148,22,.35);
}

/* ============ MAIN ============ */
.udash__main { padding: 0; min-width: 0; }
.udash__topbar {
    display: flex; align-items: center; gap: 14px;
    padding: 14px 28px;
    background: rgba(255,255,255,.85);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--u-border);
    position: sticky; top: 0; z-index: 50;
}
.udash__menu-toggle {
    display: none;
    width: 38px; height: 38px;
    border: 1px solid var(--u-border);
    background: var(--u-surface);
    border-radius: 10px;
    align-items: center; justify-content: center;
    cursor: pointer; color: var(--u-text);
    transition: var(--u-transition);
}
.udash__menu-toggle:hover { background: var(--u-bg); border-color: var(--u-text-mute); }

/* Brand link in the topbar (replaces the hidden public navbar's logo) */
.udash__brand {
    display: flex; align-items: center; gap: 9px;
    text-decoration: none; color: var(--u-text);
    font-size: 17px; font-weight: 700; letter-spacing: -.02em;
    margin-right: 8px;
}
.udash__brand:hover { color: var(--u-brand-dark); }
.udash__brand .brand__mark { margin: 0; }
.udash__brand .brand__accent { color: var(--u-accent); }
.udash__topbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }
.udash__iconbtn {
    width: 38px; height: 38px;
    display: grid; place-items: center;
    border: 1px solid var(--u-border);
    background: var(--u-surface);
    border-radius: 10px;
    color: var(--u-text-soft);
    text-decoration: none; position: relative;
    transition: var(--u-transition);
}
.udash__iconbtn:hover {
    color: var(--u-brand-dark);
    border-color: var(--u-brand);
    background: var(--u-brand-soft);
    transform: translateY(-1px);
    box-shadow: var(--u-shadow-sm);
}
.udash__iconbtn-dot {
    position: absolute; top: 7px; right: 8px;
    width: 8px; height: 8px;
    background: var(--u-accent);
    border: 2px solid var(--u-surface);
    border-radius: 50%;
    box-shadow: 0 0 0 2px rgba(236,148,22,.25);
}

/* ============ WELCOME ============ */
.udash__welcome {
    display: flex; align-items: flex-end; justify-content: space-between;
    gap: 16px; flex-wrap: wrap;
    padding: 32px 28px 8px;
}
.udash__welcome h1 {
    margin: 0; font-size: 28px; font-weight: 700;
    color: var(--u-text); letter-spacing: -.028em;
    line-height: 1.2;
}
.udash__welcome p { margin: 8px 0 0; color: var(--u-text-mute); font-size: 14px; font-weight: 400; }
.udash__welcome-cta { white-space: nowrap; }

/* ============ START SELLING BANNER ============ */
.udash__banner {
    display: flex; align-items: center; justify-content: space-between;
    gap: 16px; flex-wrap: wrap;
    margin: 20px 28px 0;
    padding: 22px 26px;
    background: linear-gradient(135deg, var(--u-brand-soft) 0%, #fff 100%);
    border: 1px solid #c4ead9;
    border-radius: var(--u-radius-xl);
    position: relative; overflow: hidden;
}
.udash__banner::before {
    content: ""; position: absolute; top: -50%; right: -10%;
    width: 240px; height: 240px;
    background: radial-gradient(circle, rgba(20,165,148,.08), transparent 70%);
    pointer-events: none;
}
.udash__banner h3 { margin: 0 0 4px; font-size: 16px; font-weight: 700; color: var(--u-text); letter-spacing: -.015em; }
.udash__banner p { margin: 0; font-size: 13.5px; color: var(--u-text-soft); line-height: 1.5; }

/* ============ WALLET CARD ============ */
.udash__wallet-card {
    display: flex; align-items: center; justify-content: space-between;
    gap: 16px; padding: 22px 26px; margin: 20px 28px 0;
    background: linear-gradient(135deg, #0a3d3a 0%, #0c5a54 100%);
    color: #fff; border-radius: var(--u-radius-xl);
    text-decoration: none; box-shadow: 0 8px 24px rgba(10,61,58,.2);
    transition: var(--u-transition); position: relative; overflow: hidden;
}
.udash__wallet-card::before {
    content: ""; position: absolute; top: -40%; right: -5%;
    width: 200px; height: 200px; border-radius: 50%;
    background: radial-gradient(circle, rgba(20,165,148,.2), transparent 70%);
    pointer-events: none;
}
.udash__wallet-card:hover { transform: translateY(-2px); box-shadow: 0 12px 32px rgba(10,61,58,.3); }
.udash__wallet-left { position: relative; z-index: 1; }
.udash__wallet-label { font-size: 12px; font-weight: 600; color: rgba(255,255,255,.65); text-transform: uppercase; letter-spacing: .06em; }
.udash__wallet-amount { font-size: 30px; font-weight: 800; letter-spacing: -.025em; margin: 4px 0 8px; font-variant-numeric: tabular-nums; }
.udash__wallet-sub { display: flex; gap: 16px; font-size: 12px; color: rgba(255,255,255,.7); flex-wrap: wrap; }
.udash__wallet-sub strong { color: #fff; font-variant-numeric: tabular-nums; }
.udash__wallet-right { position: relative; z-index: 1; opacity: .5; }

/* ============ STAT CARDS ============ */
.udash__stats {
    display: grid; grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px; padding: 24px 28px 0;
}
.udash__stat {
    display: flex; align-items: center; gap: 14px;
    padding: 18px;
    background: var(--u-surface);
    border: 1px solid var(--u-border);
    border-radius: var(--u-radius-lg);
    text-decoration: none; color: inherit;
    transition: var(--u-transition);
    position: relative;
}
.udash__stat:hover {
    transform: translateY(-2px);
    box-shadow: var(--u-shadow-md);
    border-color: #d4e7e1;
}
.udash__stat-icon {
    width: 44px; height: 44px;
    border-radius: 12px;
    display: grid; place-items: center;
    flex: 0 0 44px;
    transition: var(--u-transition);
}
.udash__stat:hover .udash__stat-icon { transform: scale(1.05); }
.udash__stat-icon--brand { background: var(--u-brand-soft); color: var(--u-brand-dark); }
.udash__stat-icon--blue { background: #eff5ff; color: #2563eb; }
.udash__stat-icon--orange { background: #fff7e9; color: #c4730a; }
.udash__stat-icon--green { background: #ecfdf3; color: #058555; }
.udash__stat-body { min-width: 0; }
.udash__stat-value {
    font-size: 24px; font-weight: 700; color: var(--u-text);
    letter-spacing: -.025em; line-height: 1.1;
    font-variant-numeric: tabular-nums;
}
.udash__stat-label { font-size: 11.5px; color: var(--u-text-mute); margin-top: 3px; font-weight: 500; }

/* ============ GRID LAYOUT ============ */
.udash__grid {
    display: grid;
    grid-template-columns: minmax(0, 1.7fr) minmax(300px, 1fr);
    gap: 20px;
    padding: 24px 28px 48px;
    align-items: start;
}

/* ============ PANELS ============ */
.udash__panel {
    background: var(--u-surface);
    border: 1px solid var(--u-border);
    border-radius: var(--u-radius-xl);
    padding: 22px;
    margin-bottom: 20px;
    box-shadow: var(--u-shadow-xs);
    transition: box-shadow var(--u-transition);
}
.udash__panel:hover { box-shadow: var(--u-shadow-sm); }
.udash__panel-head {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; margin-bottom: 18px;
}
.udash__panel-head h2 {
    margin: 0; font-size: 15px; font-weight: 700;
    color: var(--u-text); letter-spacing: -.01em;
}
.udash__panel-link {
    font-size: 12.5px; font-weight: 600;
    color: var(--u-brand-dark); text-decoration: none;
    transition: var(--u-transition);
}
.udash__panel-link:hover { color: var(--u-brand); }

/* ============ EMPTY STATES ============ */
.udash__empty {
    text-align: center; padding: 36px 20px;
    color: var(--u-text-mute);
}
.udash__empty svg { color: #c8d4d1; margin-bottom: 12px; }
.udash__empty h4 { margin: 0 0 6px; font-size: 14px; font-weight: 600; color: var(--u-text-soft); }
.udash__empty p { margin: 0 0 16px; font-size: 13px; line-height: 1.5; }
.udash__empty--compact { padding: 24px 16px; }
.udash__empty--compact p { margin: 0; }

/* ============ LISTING CARDS ============ */
.udash__listing-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
.udash__listing-card {
    border: 1px solid var(--u-border);
    border-radius: var(--u-radius-lg);
    overflow: hidden;
    background: var(--u-surface);
    position: relative;
    transition: var(--u-transition);
}
.udash__listing-card:hover {
    box-shadow: var(--u-shadow-md);
    border-color: #d4e7e1;
    transform: translateY(-2px);
}
.udash__listing-img { position: relative; aspect-ratio: 4/3; background: var(--u-bg); overflow: hidden; }
.udash__listing-img img { width: 100%; height: 100%; object-fit: cover; transition: transform 250ms ease; }
.udash__listing-card:hover .udash__listing-img img { transform: scale(1.04); }
.udash__listing-status {
    position: absolute; top: 10px; left: 10px;
    padding: 4px 10px; border-radius: 20px;
    font-size: 10px; font-weight: 700;
    text-transform: capitalize; color: #fff;
    backdrop-filter: blur(8px);
    box-shadow: 0 2px 8px rgba(0,0,0,.15);
}
.udash__listing-status--good { background: rgba(5,133,85,.92); }
.udash__listing-status--warn { background: rgba(196,115,10,.92); }
.udash__listing-status--bad { background: rgba(217,83,74,.92); }
.udash__listing-status--mute { background: rgba(90,107,104,.92); }
.udash__listing-body { padding: 12px 14px 10px; }
.udash__listing-body strong {
    display: block; font-size: 13.5px; font-weight: 600; color: var(--u-text);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    letter-spacing: -.005em;
}
.udash__listing-price {
    display: block; font-size: 13px; color: var(--u-brand-dark);
    font-weight: 700; margin-top: 4px;
    font-variant-numeric: tabular-nums;
}
.udash__listing-meta {
    display: flex; gap: 12px;
    font-size: 11px; color: var(--u-text-mute);
    margin-top: 8px; font-weight: 500;
}
.udash__listing-actions { display: flex; gap: 6px; padding: 0 14px 12px; }
.udash__mini-btn {
    width: 30px; height: 30px;
    display: grid; place-items: center;
    border: 1px solid var(--u-border);
    border-radius: 8px;
    background: var(--u-surface);
    text-decoration: none; font-size: 13px;
    transition: var(--u-transition);
}
.udash__mini-btn:hover {
    border-color: var(--u-brand);
    background: var(--u-brand-soft);
    transform: translateY(-1px);
}

/* ============ FAVORITE CARDS ============ */
.udash__fav-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
.udash__fav-card {
    border: 1px solid var(--u-border);
    border-radius: var(--u-radius-lg);
    overflow: hidden;
    text-decoration: none; color: inherit;
    transition: var(--u-transition);
    background: var(--u-surface);
}
.udash__fav-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--u-shadow-md);
    border-color: #d4e7e1;
}
.udash__fav-img { position: relative; aspect-ratio: 4/3; background: var(--u-bg); overflow: hidden; }
.udash__fav-img img { width: 100%; height: 100%; object-fit: cover; transition: transform 250ms ease; }
.udash__fav-card:hover .udash__fav-img img { transform: scale(1.04); }
.udash__fav-type {
    position: absolute; top: 10px; left: 10px;
    padding: 4px 10px; border-radius: 20px;
    font-size: 10px; font-weight: 700;
    background: rgba(255,255,255,.95);
    color: var(--u-brand-dark);
    backdrop-filter: blur(8px);
    box-shadow: 0 2px 6px rgba(0,0,0,.08);
}
.udash__fav-body { padding: 12px 14px; }
.udash__fav-body strong {
    display: block; font-size: 13.5px; font-weight: 600; color: var(--u-text);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.udash__fav-price {
    display: block; font-size: 13px; color: var(--u-brand-dark);
    font-weight: 700; margin-top: 4px;
    font-variant-numeric: tabular-nums;
}
.udash__fav-cat { display: block; font-size: 11px; color: var(--u-text-mute); margin-top: 3px; font-weight: 500; }

/* ============ TABLE ============ */
.udash__table-wrap { overflow-x: auto; margin: -4px; padding: 4px; }
.udash__table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 520px; }
.udash__table th {
    text-align: left; padding: 10px 14px;
    font-size: 10.5px; font-weight: 600;
    text-transform: uppercase; letter-spacing: .07em;
    color: var(--u-text-mute);
    border-bottom: 1px solid var(--u-border);
    background: var(--u-bg);
}
.udash__table td {
    padding: 13px 14px;
    border-bottom: 1px solid var(--u-border-soft);
    color: var(--u-text-soft);
}
.udash__table tr:last-child td { border-bottom: 0; }
.udash__table tbody tr { transition: background var(--u-transition); }
.udash__table tbody tr:hover { background: var(--u-brand-soft); }
.udash__link { color: var(--u-brand-dark); font-weight: 600; text-decoration: none; transition: var(--u-transition); }
.udash__link:hover { color: var(--u-brand); text-decoration: underline; }
.udash__muted { color: var(--u-text-mute); font-size: 12px; font-variant-numeric: tabular-nums; }
.udash__pill {
    display: inline-flex; align-items: center;
    padding: 3px 10px; border-radius: 20px;
    font-size: 10.5px; font-weight: 700;
    text-transform: capitalize; letter-spacing: .01em;
}
.udash__pill--good { background: #ecfdf3; color: #058555; }
.udash__pill--warn { background: #fff7e9; color: #c4730a; }
.udash__pill--bad { background: #fef3f2; color: #d9534a; }
.udash__pill--mute { background: var(--u-bg); color: var(--u-text-mute); }
.udash__pill--blue { background: #eff5ff; color: #2563eb; }
.udash__pill--brand { background: var(--u-brand-soft); color: var(--u-brand-dark); }

/* ============ MESSAGES ============ */
.udash__msg-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 10px; }
.udash__msg {
    padding: 14px 16px;
    border: 1px solid var(--u-border);
    border-radius: var(--u-radius);
    background: var(--u-surface);
    transition: var(--u-transition);
}
.udash__msg:hover { border-color: #d4e7e1; box-shadow: var(--u-shadow-xs); }
.udash__msg--unread { background: var(--u-brand-soft); border-color: #c4ead9; }
.udash__msg-head {
    display: flex; justify-content: space-between; align-items: flex-start;
    gap: 8px; margin-bottom: 6px;
}
.udash__msg-head strong { font-size: 13px; font-weight: 600; color: var(--u-text); }
.udash__msg-head small { font-size: 11px; color: var(--u-text-mute); white-space: nowrap; font-variant-numeric: tabular-nums; }
.udash__msg p { margin: 0 0 6px; font-size: 12.5px; color: var(--u-text-soft); line-height: 1.55; }

/* ============ NOTIFICATIONS ============ */
.udash__notif-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 2px; }
.udash__notif { border-radius: 9px; transition: background var(--u-transition); }
.udash__notif--unread { background: var(--u-brand-soft); }
.udash__notif-link { display: block; padding: 11px 13px; text-decoration: none; color: inherit; border-radius: 9px; }
.udash__notif-link:hover { background: rgba(20,165,148,.08); }
.udash__notif-link strong { display: block; font-size: 12.5px; font-weight: 600; color: var(--u-text); margin: 4px 0 3px; }
.udash__notif-link span { display: block; font-size: 12px; color: var(--u-text-soft); line-height: 1.4; }
.udash__notif-link small { display: block; font-size: 10.5px; color: var(--u-text-mute); margin-top: 5px; font-variant-numeric: tabular-nums; }
.udash__notif-type {
    display: inline-block; font-size: 9.5px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .08em;
    color: var(--u-brand-dark);
}

/* ============ RECOMMENDED ============ */
.udash__rec-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 12px; }
.udash__rec-list a {
    display: flex; gap: 12px; text-decoration: none; color: inherit;
    align-items: center; padding: 4px; border-radius: 10px;
    transition: background var(--u-transition);
}
.udash__rec-list a:hover { background: var(--u-bg); }
.udash__rec-list img {
    width: 56px; height: 56px; border-radius: 11px;
    object-fit: cover; flex: 0 0 56px;
    background: var(--u-bg);
    border: 1px solid var(--u-border);
}
.udash__rec-list strong {
    display: block; font-size: 12.5px; font-weight: 600; color: var(--u-text);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.udash__rec-price {
    display: block; font-size: 12px; color: var(--u-brand-dark);
    font-weight: 700; margin-top: 2px;
    font-variant-numeric: tabular-nums;
}
.udash__rec-loc { display: block; font-size: 11px; color: var(--u-text-mute); margin-top: 2px; }

/* ============ PROFILE MINI ============ */
.udash__profile-mini-body { text-align: center; padding: 8px 0; }
.udash__profile-mini-body h3 { margin: 0; font-size: 15px; font-weight: 700; color: var(--u-text); letter-spacing: -.01em; }
.udash__profile-email {
    margin: 4px 0 14px; font-size: 12px; color: var(--u-text-mute);
    word-break: break-all; font-variant-numeric: tabular-nums;
}
.udash__profile-caps { display: flex; gap: 6px; justify-content: center; flex-wrap: wrap; margin-bottom: 16px; }
.udash__profile-edit { width: 100%; }

.udash__backdrop { display: none; }

/* ============ RESPONSIVE ============ */
@media (max-width: 1200px) {
    .udash { grid-template-columns: 240px minmax(0, 1fr); }
    .udash__stats { grid-template-columns: repeat(4, minmax(0, 1fr)); }
}
@media (max-width: 960px) {
    .udash { grid-template-columns: 1fr; }
    .udash__sidebar {
        position: fixed; left: -280px; top: 0; width: 270px;
        height: 100vh; transition: left 250ms cubic-bezier(.4,0,.2,1); z-index: 200;
        box-shadow: var(--u-shadow-xl);
    }
    .udash__sidebar.is-open { left: 0; }
    .udash__menu-toggle { display: flex; }
    .udash__backdrop.is-open {
        display: block; position: fixed; inset: 0;
        background: rgba(10,42,40,.5);
        backdrop-filter: blur(4px);
        z-index: 150;
        animation: udash-fade-in 200ms ease;
    }
    .udash__grid { grid-template-columns: 1fr; }
    .udash__stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .udash__listing-grid, .udash__fav-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    /* On tablet, hide the brand wordmark (keep logo icon) to save space */
    .udash__brand span:last-child { display: none; }
    .udash__brand { margin-right: 0; }
}
@media (max-width: 560px) {
    .udash__stats { grid-template-columns: 1fr 1fr; gap: 10px; padding: 18px 18px 0; }
    .udash__stat { padding: 14px; gap: 12px; }
    .udash__stat-value { font-size: 20px; }
    .udash__stat-icon { width: 40px; height: 40px; flex: 0 0 40px; }
    .udash__welcome { padding: 24px 18px 6px; }
    .udash__welcome h1 { font-size: 23px; }
    .udash__banner { margin: 16px 18px 0; padding: 18px; }
    .udash__grid { padding: 18px 18px 36px; }
    .udash__panel { padding: 18px; }
    .udash__listing-grid, .udash__fav-grid { grid-template-columns: 1fr; }
    .udash__topbar { padding: 12px 18px; }
}
@keyframes udash-fade-in { from { opacity: 0; } to { opacity: 1; } }

/* Focus visible for accessibility */
.udash__nav-link:focus-visible,
.udash__stat:focus-visible,
.udash__iconbtn:focus-visible,
.udash__panel-link:focus-visible,
.udash__link:focus-visible {
    outline: 2px solid var(--u-brand);
    outline-offset: 2px;
    border-radius: 9px;
}
</style>

<script>
// Mobile sidebar toggle
(function(){
    var toggle = document.getElementById('udashMenuToggle');
    var sidebar = document.getElementById('udashSidebar');
    var backdrop = document.getElementById('udashBackdrop');
    function open(){ sidebar.classList.add('is-open'); backdrop.classList.add('is-open'); }
    function close(){ sidebar.classList.remove('is-open'); backdrop.classList.remove('is-open'); }
    if (toggle) toggle.addEventListener('click', function(){ sidebar.classList.contains('is-open') ? close() : open(); });
    if (backdrop) backdrop.addEventListener('click', close);
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') close(); });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
