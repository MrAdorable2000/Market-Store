<?php
/**
 * includes/admin_topbar.php
 * --------------------------------------------------------------------
 * Premium IsokoRyacu admin topbar (Phase 5).
 * Pages set before including:
 *   $adminPageTitle    = 'Overview'
 *   $adminPageSubtitle = 'Platform-wide overview'
 *
 * Contains: sidebar toggle · page title · global search (works — the
 * listings page reads ?q=) · notifications dropdown (REAL events from
 * the database: pending listings, open reports, unread messages, rental
 * requests, new users) · messages icon with live unread count · profile
 * dropdown (profile / settings / website / sign out).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin_icons.php';

$user = current_user();

// ---- Live numbers for the topbar -------------------------------------------
$pendingCount   = (int) db()->query("SELECT COUNT(*) FROM listings WHERE status = 'pending'")->fetchColumn();
$openReports    = (int) db()->query("SELECT COUNT(*) FROM reports WHERE status IN ('open','reviewing')")->fetchColumn();
$unreadMessages = (int) db()->query("SELECT COUNT(*) FROM contact_requests WHERE is_read = 0")->fetchColumn();
$pendingRentals = (int) db()->query("SELECT COUNT(*) FROM rental_requests WHERE status = 'pending'")->fetchColumn();

// Latest timestamps so notification rows can show a real "time ago"
$lastPendingAt  = db()->query("SELECT created_at FROM listings WHERE status = 'pending' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
$lastReportAt   = db()->query("SELECT created_at FROM reports WHERE status IN ('open','reviewing') ORDER BY created_at DESC LIMIT 1")->fetchColumn();
$lastMessageAt  = db()->query("SELECT created_at FROM contact_requests WHERE is_read = 0 ORDER BY created_at DESC LIMIT 1")->fetchColumn();
$lastRentalAt   = db()->query("SELECT created_at FROM rental_requests WHERE status = 'pending' ORDER BY created_at DESC LIMIT 1")->fetchColumn();

$notifTotal = $pendingCount + $openReports + $unreadMessages + $pendingRentals;

/** One notification row (only rendered when the underlying count is real). */
function admin_notif_row(string $icon, string $tone, string $url, string $title, string $sub, $time): string
{
    $when = $time ? time_ago($time) : '';
    return '<a class="a-notify" href="' . e($url) . '">'
         . '<span class="a-notify__icon a-notify__icon--' . e($tone) . '">' . admin_icon($icon) . '</span>'
         . '<span class="a-notify__text"><strong>' . e($title) . '</strong><span>' . e($sub) . '</span></span>'
         . ($when ? '<time class="a-notify__time">' . e($when) . '</time>' : '')
         . '</a>';
}

$firstName = explode(' ', trim($user['full_name'] ?? '?'))[0] ?: '?';
$roleLabel = is_super_admin() ? t('admin.role_super_admin') : t('common.administrator');
?>
<header class="a-topbar">
    <button class="a-topbar__toggle" id="aSidebarToggle" type="button" aria-label="<?php echo e(t('admin.toggle_menu')); ?>">
        <?php echo admin_icon('menu'); ?>
    </button>

    <div class="a-topbar__title">
        <h1><?php echo e($adminPageTitle ?? t('admin.dash_title')); ?></h1>
        <p><?php echo e($adminPageSubtitle ?? t('admin.dash_sub')); ?></p>
    </div>

    <form class="a-search" action="<?php echo APP_URL; ?>/pages/admin/listings.php" method="get" role="search">
        <?php echo admin_icon('search'); ?>
        <input type="search" name="q" value="<?php echo e($_GET['q'] ?? ''); ?>"
               placeholder="<?php echo e(t('admin.search_placeholder')); ?>"
               aria-label="<?php echo e(t('buttons.search')); ?>">
    </form>

    <div class="a-topbar__actions">

        <!-- Theme: light / dark (same preference as the public website) -->
        <button type="button" class="a-iconbtn a-theme-toggle" id="aThemeToggle" aria-pressed="false"
                title="<?php echo e(t('admin.theme_toggle')); ?>" aria-label="<?php echo e(t('admin.theme_toggle')); ?>">
            <svg class="i-moon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
            <svg class="i-sun" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
        </button>

        <!-- Notifications: REAL events derived from live data -->
        <div class="a-drop">
            <button type="button" class="a-iconbtn a-drop__trigger" aria-expanded="false"
                    title="<?php echo e(t('admin.notifications')); ?>" aria-label="<?php echo e(t('admin.notifications')); ?>">
                <?php echo admin_icon('bell'); ?>
                <?php if ($notifTotal > 0): ?><span class="a-iconbtn__count"><?php echo $notifTotal > 99 ? '99+' : (int) $notifTotal; ?></span><?php endif; ?>
            </button>
            <div class="a-drop__panel">
                <div class="a-drop__head">
                    <strong><?php echo e(t('admin.notifications')); ?></strong>
                    <a href="<?php echo APP_URL; ?>/pages/admin/dashboard.php"><?php echo e(t('admin.view_all')); ?></a>
                </div>
                <div class="a-drop__body">
                    <?php
                    $rows = '';
                    if ($pendingCount > 0) {
                        $rows .= admin_notif_row('listings', '', APP_URL . '/pages/admin/listings.php?status=pending',
                            t('admin.notif_pending_title', ['count' => $pendingCount]),
                            t('admin.notif_pending_sub'), $lastPendingAt);
                    }
                    if ($openReports > 0) {
                        $rows .= admin_notif_row('reports', 'red', APP_URL . '/pages/admin/reports.php?status=open',
                            t('admin.notif_reports_title', ['count' => $openReports]),
                            t('admin.notif_reports_sub'), $lastReportAt);
                    }
                    if ($unreadMessages > 0) {
                        $rows .= admin_notif_row('messages', 'orange', APP_URL . '/pages/admin/messages.php',
                            t('admin.notif_messages_title', ['count' => $unreadMessages]),
                            t('admin.notif_messages_sub'), $lastMessageAt);
                    }
                    if ($pendingRentals > 0) {
                        $rows .= admin_notif_row('rentals', 'blue', APP_URL . '/pages/admin/rentals.php?status=pending',
                            t('admin.notif_rentals_title', ['count' => $pendingRentals]),
                            t('admin.notif_rentals_sub'), $lastRentalAt);
                    }
                    echo $rows !== ''
                        ? $rows
                        : '<div class="a-empty"><div class="a-empty__icon">' . admin_icon('check-circle') . '</div><h4>' . e(t('admin.all_clear_title')) . '</h4><p>' . e(t('admin.all_clear_sub')) . '</p></div>';
                    ?>
                </div>
                <a class="a-drop__foot" href="<?php echo APP_URL; ?>/pages/admin/dashboard.php"><?php echo e(t('admin.go_to_dashboard')); ?></a>
            </div>
        </div>

        <!-- Messages: live unread count -->
        <a href="<?php echo APP_URL; ?>/pages/admin/messages.php" class="a-iconbtn"
           title="<?php echo e(t('admin.nav_messages')); ?>" aria-label="<?php echo e(t('admin.nav_messages')); ?>">
            <?php echo admin_icon('messages'); ?>
            <?php if ($unreadMessages > 0): ?><span class="a-iconbtn__count"><?php echo $unreadMessages > 99 ? '99+' : (int) $unreadMessages; ?></span><?php endif; ?>
        </a>

        <!-- Red Sign Out button (always visible) -->
        <button type="button" class="a-iconbtn a-iconbtn--danger" title="<?php echo e(t('nav.sign_out')); ?>" aria-label="<?php echo e(t('nav.sign_out')); ?>"
                onclick="if(typeof confirmAction==='function'){confirmAction({type:'warning',title:'Sign out?',message:'Are you sure you want to sign out of your account?',confirmText:'Sign Out',cancelText:'Cancel',onConfirm:function(){window.location.href='<?php echo APP_URL; ?>/pages/logout.php';return true;}});return false;}window.location.href='<?php echo APP_URL; ?>/pages/logout.php';">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
        </button>

        <!-- Profile dropdown -->
        <div class="a-drop a-drop--profile">
            <button type="button" class="a-iconbtn a-topbar__user a-drop__trigger" aria-expanded="false" aria-haspopup="true">
                <span class="a-avatar a-avatar--sm"><?php echo e(strtoupper(substr($user['full_name'] ?? '?', 0, 1))); ?></span>
                <span class="a-topbar__user-info">
                    <strong><?php echo e($firstName); ?></strong>
                    <span class="a-topbar__user-role"><?php echo admin_icon(is_super_admin() ? 'shield' : 'user-check'); ?><?php echo e($roleLabel); ?></span>
                </span>
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" class="a-topbar__chev" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <div class="a-drop__panel a-drop__panel--profile" role="menu" aria-label="<?php echo e(t('admin.user_menu')); ?>">
                <!-- Identity card: avatar + name + email + role -->
                <div class="a-profilecard">
                    <span class="a-profilecard__avatar"><?php echo e(strtoupper(substr($user['full_name'] ?? '?', 0, 1))); ?></span>
                    <div class="a-profilecard__body">
                        <div class="a-profilecard__name"><?php echo e($user['full_name']); ?></div>
                        <div class="a-profilecard__mail"><?php echo e($user['email']); ?></div>
                        <span class="a-profilecard__role"><?php echo admin_icon(is_super_admin() ? 'shield' : 'user-check'); ?><?php echo e($roleLabel); ?></span>
                    </div>
                </div>

                <!-- Account section -->
                <div class="a-drop__section">
                    <span class="a-drop__section-label"><?php echo e(t('admin.section_account')); ?></span>
                    <div class="a-drop__menu">
                        <a href="<?php echo APP_URL; ?>/pages/profile.php" role="menuitem"><?php echo admin_icon('profile'); ?><span><?php echo e(t('admin.nav_my_profile')); ?></span></a>
                        <a href="<?php echo APP_URL; ?>/pages/admin/settings.php" role="menuitem"><?php echo admin_icon('settings'); ?><span><?php echo e(t('admin.nav_settings')); ?></span></a>
                    </div>
                </div>

                <!-- Navigate section -->
                <div class="a-drop__section">
                    <span class="a-drop__section-label"><?php echo e(t('admin.section_navigate')); ?></span>
                    <div class="a-drop__menu">
                        <a href="<?php echo APP_URL; ?>/" role="menuitem"><?php echo admin_icon('website'); ?><span><?php echo e(t('admin.view_website')); ?></span></a>
                    </div>
                </div>

                <!-- Sign out (separated, danger style) -->
                <div class="a-drop__section a-drop__section--footer">
                    <div class="a-drop__menu">
                        <a href="<?php echo APP_URL; ?>/pages/logout.php" role="menuitem" class="is-danger"><?php echo admin_icon('logout'); ?><span><?php echo e(t('nav.sign_out')); ?></span></a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>
