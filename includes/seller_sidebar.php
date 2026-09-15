<?php
/**
 * includes/seller_sidebar.php — Shared seller sidebar
 * --------------------------------------------------------------------
 * Rendered on EVERY seller page so the navigation never disappears.
 *
 * Active state is determined from the current script path — no JS needed,
 * survives page refresh, and works on desktop + mobile.
 *
 * Usage (at the top of any seller page, after require_login):
 *   require_once __DIR__ . '/../includes/seller_sidebar.php';
 *   render_seller_sidebar($uid, $pdo);
 *
 * Or more commonly, pages include this file and call the function
 * after their data queries but before the page-specific HTML.
 */

/**
 * Determine if a nav link should be marked active based on the current URL.
 */
function seller_nav_is_active(string $urlPath): bool
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $script = str_replace('\\', '/', $script);
    // Extract the relative path after the project root
    // e.g. "/Market-store/pages/seller/orders.php" → "pages/seller/orders.php"
    if (preg_match('#/(pages/seller/.+\.php)$#', $script, $m)) {
        $current = $m[1];
    } elseif (preg_match('#/(pages/.+\.php)$#', $script, $m)) {
        $current = $m[1];
    } else {
        $current = basename($script);
    }

    // Match against the URL path
    $target = ltrim(parse_url($urlPath, PHP_URL_PATH), '/');
    // Handle APP_URL prefix — just compare basenames
    $targetFile = basename($urlPath);

    // Special: wallet.php is outside seller/ but is in the Finance section
    if ($targetFile === 'wallet.php' && basename($current) === 'wallet.php') return true;
    if ($targetFile === 'sell.php' && basename($current) === 'sell.php') return true;

    return basename($current) === $targetFile;
}

/**
 * Render the seller sidebar. Call this inside the <div class="udash"> wrapper.
 */
function render_seller_sidebar(int $uid, $pdo): void
{
    // Fetch unread counts (safe — wrapped in try/catch)
    $chatUnread = 0; $pendingOrders = 0; $unreadNotif = 0; $myListings = 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages m INNER JOIN conversations c ON c.id = m.conversation_id WHERE c.user2_id = ? AND m.sender_id != ? AND m.is_read = 0");
        $stmt->execute([$uid, $uid]);
        $chatUnread = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {}
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE seller_id = ? AND status IN ('pending','confirmed','preparing','ready','shipped','out_for_delivery')");
        $stmt->execute([$uid]);
        $pendingOrders = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {}
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$uid]);
        $unreadNotif = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {}
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM listings WHERE seller_id = ?");
        $stmt->execute([$uid]);
        $myListings = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {}

    $user = current_user();
    $isSellerSidebar = true; // marker for pages to know sidebar is rendered
    ?>
    <aside class="udash__sidebar" id="udashSidebar">
        <div class="udash__profile">
            <?php if (!empty($user['avatar'])): ?>
                <img src="<?php echo APP_URL . '/' . ltrim($user['avatar'], '/'); ?>" alt="" style="width:40px;height:40px;border-radius:11px;object-fit:cover;flex:0 0 40px;">
            <?php else: ?>
                <span class="udash__avatar"><?php echo e(strtoupper(substr($user['full_name'] ?? '?', 0, 1))); ?></span>
            <?php endif; ?>
            <div class="udash__profile-info">
                <h4><?php echo e($user['full_name'] ?? 'Seller'); ?></h4>
                <span class="udash__caps">Seller Account</span>
            </div>
        </div>

        <nav class="udash__nav" aria-label="Seller navigation">
            <div class="udash__nav-section">Main</div>
            <a href="<?php echo APP_URL; ?>/pages/seller/dashboard.php" class="udash__nav-link<?php echo seller_nav_is_active('/pages/seller/dashboard.php') ? ' is-active' : ''; ?>" aria-current="<?php echo seller_nav_is_active('/pages/seller/dashboard.php') ? 'page' : 'false'; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                <span>Overview</span>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/sell.php" class="udash__nav-link<?php echo seller_nav_is_active('/pages/sell.php') ? ' is-active' : ''; ?>" aria-current="<?php echo seller_nav_is_active('/pages/sell.php') ? 'page' : 'false'; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>
                <span>New Product</span>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/seller/listings.php" class="udash__nav-link<?php echo seller_nav_is_active('/pages/seller/listings.php') ? ' is-active' : ''; ?>" aria-current="<?php echo seller_nav_is_active('/pages/seller/listings.php') ? 'page' : 'false'; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/></svg>
                <span>My Products</span>
                <?php if ($myListings > 0): ?><span class="udash__nav-badge"><?php echo $myListings; ?></span><?php endif; ?>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/seller/messages.php" class="udash__nav-link<?php echo seller_nav_is_active('/pages/seller/messages.php') ? ' is-active' : ''; ?>" aria-current="<?php echo seller_nav_is_active('/pages/seller/messages.php') ? 'page' : 'false'; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <span>Messages</span>
                <?php if ($chatUnread > 0): ?><span class="udash__nav-badge"><?php echo $chatUnread; ?></span><?php endif; ?>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/seller/orders.php" class="udash__nav-link<?php echo seller_nav_is_active('/pages/seller/orders.php') ? ' is-active' : ''; ?>" aria-current="<?php echo seller_nav_is_active('/pages/seller/orders.php') ? 'page' : 'false'; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M16 16h.01M8 16h.01M3 9h18M5 5h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/></svg>
                <span>Orders</span>
                <?php if ($pendingOrders > 0): ?><span class="udash__nav-badge"><?php echo $pendingOrders; ?></span><?php endif; ?>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/seller/reviews.php" class="udash__nav-link<?php echo seller_nav_is_active('/pages/seller/reviews.php') ? ' is-active' : ''; ?>" aria-current="<?php echo seller_nav_is_active('/pages/seller/reviews.php') ? 'page' : 'false'; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                <span>Reviews</span>
            </a>

            <div class="udash__nav-section">Finance</div>
            <a href="<?php echo APP_URL; ?>/pages/wallet.php" class="udash__nav-link<?php echo seller_nav_is_active('/pages/wallet.php') ? ' is-active' : ''; ?>" aria-current="<?php echo seller_nav_is_active('/pages/wallet.php') ? 'page' : 'false'; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4M3 5v14a2 2 0 0 0 2 2h16v-5M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
                <span>Wallet</span>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/seller/withdrawals.php" class="udash__nav-link<?php echo seller_nav_is_active('/pages/seller/withdrawals.php') ? ' is-active' : ''; ?>" aria-current="<?php echo seller_nav_is_active('/pages/seller/withdrawals.php') ? 'page' : 'false'; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                <span>Withdrawals</span>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/seller/payment-methods.php" class="udash__nav-link<?php echo seller_nav_is_active('/pages/seller/payment-methods.php') ? ' is-active' : ''; ?>" aria-current="<?php echo seller_nav_is_active('/pages/seller/payment-methods.php') ? 'page' : 'false'; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                <span>Payment Methods</span>
            </a>

            <div class="udash__nav-section">Account</div>
            <a href="<?php echo APP_URL; ?>/pages/seller/settings.php" class="udash__nav-link<?php echo seller_nav_is_active('/pages/seller/settings.php') ? ' is-active' : ''; ?>" aria-current="<?php echo seller_nav_is_active('/pages/seller/settings.php') ? 'page' : 'false'; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>
                <span>Seller Settings</span>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/notifications.php" class="udash__nav-link<?php echo seller_nav_is_active('/pages/notifications.php') ? ' is-active' : ''; ?>" aria-current="<?php echo seller_nav_is_active('/pages/notifications.php') ? 'page' : 'false'; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/></svg>
                <span>Notifications</span>
                <?php if ($unreadNotif > 0): ?><span class="udash__nav-badge"><?php echo $unreadNotif; ?></span><?php endif; ?>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/logout.php" class="udash__nav-link udash__nav-link--danger" onclick="confirmAction({type:'warning',title:'Sign out?',message:'Are you sure you want to sign out of your account?',confirmText:'Sign Out',cancelText:'Cancel',onConfirm:function(){window.location.href='<?php echo APP_URL; ?>/pages/logout.php';return true;}});return false;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                <span>Sign out</span>
            </a>
        </nav>
    </aside>
    <?php
}

/**
 * Render the opening of a seller page: .udash wrapper + sidebar + .udash__main + topbar.
 * Call this AFTER header.php is included, BEFORE page-specific content.
 */
function seller_page_start(string $title, int $uid, $pdo): void
{
    require_once __DIR__ . '/seller_sidebar.php';
    ?>
<div class="udash">
    <?php render_seller_sidebar($uid, $pdo); ?>
    <main class="udash__main">
        <div class="udash__topbar">
            <a href="<?php echo APP_URL; ?>/" class="udash__brand" title="Home">
                <?php echo brand_mark_html(28); ?>
                <span>Isoko<span class="brand__accent">Ryacu</span></span>
            </a>
            <button class="udash__menu-toggle" id="udashMenuToggle" type="button" aria-label="Toggle menu" aria-expanded="false">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18" stroke-linecap="round"/></svg>
            </button>
            <div class="udash__topbar-actions">
                <button class="udash__iconbtn" id="udashThemeToggle" type="button" aria-label="Toggle theme" title="Toggle dark mode">
                    <svg class="i-moon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
                    <svg class="i-sun" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                </button>
                <button class="udash__iconbtn" type="button" title="Sign out" aria-label="Sign out" onclick="if(typeof confirmAction==='function'){confirmAction({type:'warning',title:'Sign out?',message:'Are you sure you want to sign out of your account?',confirmText:'Sign Out',cancelText:'Cancel',onConfirm:function(){window.location.href='<?php echo APP_URL; ?>/pages/logout.php';return true;}});return false;}window.location.href='<?php echo APP_URL; ?>/pages/logout.php';">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                </button>
                <a href="<?php echo APP_URL; ?>/pages/sell.php" class="btn btn--primary btn--sm">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>
                    New Listing
                </a>
            </div>
        </div>
        <div style="padding:24px 28px 48px;max-width:1000px;">
    <?php
}

/**
 * Render the closing of a seller page: close .udash__main + .udash + backdrop + JS.
 * Call this BEFORE footer.php is included.
 */
function seller_page_end(): void
{
    ?>
        </div><!-- /padding wrapper -->
    </main>
</div><!-- /.udash -->
<div class="udash__backdrop" id="udashBackdrop"></div>
<script>
(function(){
    var toggle = document.getElementById('udashMenuToggle');
    var sidebar = document.getElementById('udashSidebar');
    var backdrop = document.getElementById('udashBackdrop');
    if (toggle && sidebar) {
        function open(){ sidebar.classList.add('is-open'); if (backdrop) backdrop.classList.add('is-open'); toggle.setAttribute('aria-expanded','true'); }
        function close(){ sidebar.classList.remove('is-open'); if (backdrop) backdrop.classList.remove('is-open'); toggle.setAttribute('aria-expanded','false'); }
        toggle.addEventListener('click', function(){ sidebar.classList.contains('is-open') ? close() : open(); });
        if (backdrop) backdrop.addEventListener('click', close);
        document.addEventListener('keydown', function(e){ if (e.key === 'Escape') close(); });
    }
    // Theme toggle in seller topbar
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
    <?php
}
