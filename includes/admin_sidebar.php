<?php
/**
 * includes/admin_sidebar.php
 * --------------------------------------------------------------------
 * Premium IsokoRyacu admin sidebar (Phase 5).
 *
 * Before including, pages set:  $adminActivePage = 'dashboard' | 'listings' | ...
 *
 * Structure:
 *   MAIN            Dashboard · Listings · Categories
 *   PEOPLE          Users
 *   ACTIVITY        Rental Requests · Reviews · Reports
 *   COMMUNICATION   Messages
 *   INSIGHTS        Reports & Analytics
 *   SYSTEM          System Logs · Settings
 *   (bottom)        View website · Sign out
 *
 * Every badge is a REAL count pulled from the database on page load.
 * Desktop: collapsible to an icon rail (choice persisted in localStorage).
 * Mobile (<=900px): off-canvas drawer with backdrop.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin_icons.php';

$user = current_user();
if (!$user) return;

// ---- Real badge counts (single cheap query each, only on admin pages) ----
$badgePending   = (int) db()->query("SELECT COUNT(*) FROM listings WHERE status = 'pending'")->fetchColumn();
$badgeReports   = (int) db()->query("SELECT COUNT(*) FROM reports WHERE status IN ('open','reviewing')")->fetchColumn();
$badgeMessages  = (int) db()->query("SELECT COUNT(*) FROM contact_requests WHERE is_read = 0")->fetchColumn();
$badgeRentals   = (int) db()->query("SELECT COUNT(*) FROM rental_requests WHERE status = 'pending'")->fetchColumn();

/** Build one nav link. */
function admin_nav_link(array $item, string $activeKey): string
{
    $active = ($activeKey === $item['key']) ? ' is-active' : '';
    $badge  = '';
    if (!empty($item['badge'])) {
        $badge = '<span class="a-nav__badge' . ($item['badge_quiet'] ?? false ? ' a-nav__badge--quiet' : '') . '">'
               . (int) $item['badge'] . '</span>';
    }
    return '<a href="' . e($item['url']) . '" class="a-nav__link' . $active . '" title="' . e($item['label']) . '">'
         . admin_icon($item['icon'])
         . '<span>' . e($item['label']) . '</span>'
         . $badge
         . '</a>';
}

$navGroups = [
    'main' => [
        'label' => t('admin.nav_section_main'),
        'items' => [
            ['key' => 'dashboard',  'url' => APP_URL . '/pages/admin/dashboard.php',  'label' => t('admin.nav_overview'),   'icon' => 'dashboard'],
            ['key' => 'listings',   'url' => APP_URL . '/pages/admin/listings.php',   'label' => t('admin.nav_listings'),   'icon' => 'listings',  'badge' => $badgePending],
            ['key' => 'categories', 'url' => APP_URL . '/pages/admin/categories.php', 'label' => t('admin.nav_categories'), 'icon' => 'categories'],
        ],
    ],
    'people' => [
        'label' => t('admin.nav_section_people'),
        'items' => [
            ['key' => 'users', 'url' => APP_URL . '/pages/admin/users.php', 'label' => t('admin.nav_users'), 'icon' => 'users'],
        ],
    ],
    'activity' => [
        'label' => t('admin.nav_section_activity'),
        'items' => [
            ['key' => 'rentals', 'url' => APP_URL . '/pages/admin/rentals.php', 'label' => t('admin.nav_rentals'), 'icon' => 'rentals', 'badge' => $badgeRentals],
            ['key' => 'reviews', 'url' => APP_URL . '/pages/admin/reviews.php', 'label' => t('admin.nav_reviews'), 'icon' => 'reviews'],
            ['key' => 'reports', 'url' => APP_URL . '/pages/admin/reports.php', 'label' => t('admin.nav_reports'), 'icon' => 'reports', 'badge' => $badgeReports],
        ],
    ],
    'communication' => [
        'label' => t('admin.nav_section_communication'),
        'items' => [
            ['key' => 'messages', 'url' => APP_URL . '/pages/admin/messages.php', 'label' => t('admin.nav_messages'), 'icon' => 'messages', 'badge' => $badgeMessages],
        ],
    ],
    'insights' => [
        'label' => t('admin.nav_section_insights'),
        'items' => [
            ['key' => 'analytics', 'url' => APP_URL . '/pages/admin/analytics.php', 'label' => t('admin.nav_analytics'), 'icon' => 'analytics'],
        ],
    ],
    'system' => [
        'label' => t('admin.nav_section_system'),
        'items' => [
            ['key' => 'logs',     'url' => APP_URL . '/pages/admin/logs.php',     'label' => t('admin.nav_logs'),     'icon' => 'logs'],
            ['key' => 'youtube',  'url' => APP_URL . '/pages/admin/youtube.php',  'label' => 'YouTube Videos', 'icon' => 'website'],
            ['key' => 'community-posts', 'url' => APP_URL . '/pages/admin/community-posts.php', 'label' => 'Events & Announcements', 'icon' => 'website'],
            ['key' => 'settings', 'url' => APP_URL . '/pages/admin/settings.php', 'label' => t('admin.nav_settings'), 'icon' => 'settings'],
        ],
    ],
];

// --- Super Admin section (only visible to SUPER_ADMIN role) ---
if (is_super_admin()) {
    // Pending withdrawal count for badge
    $badgeWithdrawals = 0;
    try { $badgeWithdrawals = (int) db()->query("SELECT COUNT(*) FROM withdrawals WHERE status='pending'")->fetchColumn(); } catch (PDOException $e) {}

    // Pending verification count
    $badgeVerifications = 0;
    try { $badgeVerifications = (int) db()->query("SELECT COUNT(*) FROM withdrawal_methods WHERE verification_status='pending'")->fetchColumn(); } catch (PDOException $e) {}

    $navGroups = array_merge([
        'super' => [
            'label' => '⚡ SUPER ADMIN',
            'items' => [
                ['key' => 'super-dashboard', 'url' => APP_URL . '/pages/admin/super-dashboard.php', 'label' => 'Control Center', 'icon' => 'dashboard'],
                ['key' => 'super-users',     'url' => APP_URL . '/pages/admin/super-users.php',     'label' => 'User Management', 'icon' => 'users'],
                ['key' => 'super-admins',    'url' => APP_URL . '/pages/admin/super-admins.php',    'label' => 'Administrators', 'icon' => 'shield'],
                ['key' => 'homepage',       'url' => APP_URL . '/pages/admin/homepage.php',       'label' => 'Homepage Editor', 'icon' => 'website'],
            ],
        ],
    ], $navGroups);

    // Add super-admin items to system section
    $navGroups['system']['items'][] = ['key' => 'super-withdrawals', 'url' => APP_URL . '/pages/admin/super-withdrawals.php', 'label' => 'Withdrawals', 'icon' => 'wallet', 'badge' => $badgeWithdrawals];
}

$activeKey = $adminActivePage ?? '';
$firstName = explode(' ', trim($user['full_name'] ?? '?'))[0] ?: '?';
$roleLabel = is_super_admin() ? t('admin.role_super_admin') : t('common.administrator');

// Always resolve the latest avatar directly from the database so the admin
// sidebar shows the user's real profile photo immediately after an upload,
// even when the current session still contains an older avatar value.
$sideAvatar = '';
try {
    $uidForAvatar = (int)($user['id'] ?? 0);
    $avatarStmt = db()->prepare('SELECT avatar_path, avatar_blob FROM users WHERE id = ? LIMIT 1');
    $avatarStmt->execute([$uidForAvatar]);
    $avatarRow = $avatarStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($avatarRow['avatar_blob'])) {
        $sideAvatar = APP_URL . '/api/v1/profile/avatar.php?id=' . $uidForAvatar;
    } elseif (!empty($avatarRow['avatar_path'])) {
        $sideAvatar = image_or_default((string)$avatarRow['avatar_path']);
    }
} catch (Throwable $e) {
    $sideAvatar = !empty($user['avatar']) ? image_or_default((string)$user['avatar']) : '';
}
?>
<aside class="a-sidebar" id="aSidebar" aria-label="<?php echo e(t('admin.sidebar_label')); ?>">
    <div class="a-sidebar__brand">
        <?php echo brand_mark_html(40); ?>
        <span class="a-sidebar__brand-text">
            Isoko<span class="brand__accent">Ryacu</span>
            <span class="a-sidebar__brand-sub"><?php echo e(t('admin.brand_control_center')); ?></span>
        </span>
    </div>

    <a class="a-sideuser a-sideuser--link" href="<?php echo APP_URL; ?>/pages/profile.php" title="<?php echo e(t('admin.nav_my_profile')); ?> — <?php echo e($user['full_name']); ?>">
        <span class="a-sideuser__avatar<?php echo $sideAvatar ? ' a-sideuser__avatar--photo' : ''; ?>" aria-hidden="true">
            <?php if ($sideAvatar): ?>
                <img src="<?php echo e($sideAvatar); ?><?php echo strpos($sideAvatar, '?') === false ? '?v=' . time() : '&v=' . time(); ?>" alt="<?php echo e($user['full_name'] ?? 'User'); ?>" loading="eager">
            <?php else: ?>
                <?php echo e(strtoupper(substr($user['full_name'] ?? '?', 0, 1))); ?>
            <?php endif; ?>
            <i class="a-sideuser__status" aria-hidden="true"></i>
        </span>
        <span class="a-sideuser__body">
            <span class="a-sideuser__name"><?php echo e($user['full_name']); ?></span>
            <span class="a-sideuser__badge">
                <?php echo admin_icon(is_super_admin() ? 'shield' : 'user-check'); ?>
                <?php echo e($roleLabel); ?>
            </span>
        </span>
    </a>

    <nav class="a-nav">
        <?php foreach ($navGroups as $group): ?>
            <div class="a-nav__section"><?php echo e($group['label']); ?></div>
            <?php foreach ($group['items'] as $item): ?>
                <?php echo admin_nav_link($item, $activeKey); ?>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>

    <div class="a-sidefoot">
        <a href="<?php echo APP_URL; ?>/" class="a-nav__link" title="<?php echo e(t('admin.view_website')); ?>">
            <?php echo admin_icon('website'); ?>
            <span><?php echo e(t('admin.view_website')); ?></span>
        </a>
        <a href="<?php echo APP_URL; ?>/pages/logout.php" class="a-nav__link" title="<?php echo e(t('nav.sign_out')); ?>" onclick="if(typeof confirmAction==='function'){confirmAction({type:'warning',title:'Sign out?',message:'Are you sure you want to sign out of your account?',confirmText:'Sign Out',cancelText:'Cancel',onConfirm:function(){window.location.href='<?php echo APP_URL; ?>/pages/logout.php';return true;}});return false;}">
            <?php echo admin_icon('logout'); ?>
            <span><?php echo e(t('nav.sign_out')); ?></span>
        </a>
    </div>
</aside>
<div class="a-sidebar__backdrop" id="aBackdrop"></div>
