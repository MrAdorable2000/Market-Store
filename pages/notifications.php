<?php
/**
 * pages/notifications.php — Professional in-app notifications center
 * --------------------------------------------------------------------
 * Features:
 *   - Filter tabs: All / Unread / Read
 *   - Type icons with color-coded backgrounds
 *   - Mark as read (individual + mark all)
 *   - Empty state with professional illustration
 *   - Responsive, polished design
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/seller_sidebar.php';

require_login();
$uid = (int) current_user()['id'];

// --- Handle POST actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        flash_set('error', t('errors.invalid_token'));
        redirect(APP_URL . '/pages/notifications.php');
    }
    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'mark_read':
            $notifId = (int)($_POST['notification_id'] ?? 0);
            if ($notifId) {
                db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')->execute([$notifId, $uid]);
            }
            break;
        case 'mark_all_read':
            db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$uid]);
            flash_set('success', 'All notifications marked as read.');
            break;
        case 'delete':
            $notifId = (int)($_POST['notification_id'] ?? 0);
            if ($notifId) {
                db()->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?')->execute([$notifId, $uid]);
            }
            break;
    }
    redirect(APP_URL . '/pages/notifications.php');
}

// --- Filter ---
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'unread', 'read'], true)) $filter = 'all';

// --- Load notifications ---
$where = 'user_id = ?';
$params = [$uid];
if ($filter === 'unread') { $where .= ' AND is_read = 0'; }
elseif ($filter === 'read') { $where .= ' AND is_read = 1'; }

$stmt = db()->prepare("SELECT * FROM notifications WHERE $where ORDER BY created_at DESC LIMIT 50");
$stmt->execute($params);
$notes = $stmt->fetchAll();

// --- Counts for tabs ---
$allCount = (int) db()->query("SELECT COUNT(*) FROM notifications WHERE user_id = $uid")->fetchColumn();
$unreadCount = (int) db()->query("SELECT COUNT(*) FROM notifications WHERE user_id = $uid AND is_read = 0")->fetchColumn();
$readCount = $allCount - $unreadCount;

$pageTitle = t('nav.notifications');
$activePage = 'dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<?php seller_page_start(t('nav.notifications'), $uid, db()); ?>

    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:20px;">
        <div>
            <h1 style="font-size:24px;font-weight:800;margin:0;letter-spacing:-.02em;color:var(--text);">Notifications</h1>
            <p style="font-size:13.5px;color:var(--text-mute);margin:5px 0 0;">
                <?php echo $unreadCount > 0 ? "You have <strong style=\"color:var(--brand-600);\">{$unreadCount} unread</strong> notification(s)." : "You're all caught up!"; ?>
            </p>
        </div>
        <?php if ($unreadCount > 0): ?>
        <form method="post" action="">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="mark_all_read">
            <button type="submit" class="btn btn--outline btn--sm">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:5px;"><path d="M20 6 9 17l-5-5"/></svg>
                Mark all as read
            </button>
        </form>
        <?php endif; ?>
    </div>

    <!-- Filter tabs -->
    <div style="display:flex;gap:6px;margin-bottom:20px;border-bottom:1px solid var(--border);padding-bottom:0;" class="notif-tabs">
        <a href="?filter=all" class="notif-tab <?php echo $filter==='all'?'is-active':''; ?>">
            All <span class="notif-tab__count"><?php echo $allCount; ?></span>
        </a>
        <a href="?filter=unread" class="notif-tab <?php echo $filter==='unread'?'is-active':''; ?>">
            Unread <?php if ($unreadCount > 0): ?><span class="notif-tab__count notif-tab__count--unread"><?php echo $unreadCount; ?></span><?php endif; ?>
        </a>
        <a href="?filter=read" class="notif-tab <?php echo $filter==='read'?'is-active':''; ?>">
            Read <span class="notif-tab__count"><?php echo $readCount; ?></span>
        </a>
    </div>

    <!-- Notifications list -->
    <?php if (empty($notes)): ?>
        <!-- Empty state -->
        <div class="card" style="padding:48px 32px;text-align:center;">
            <div style="width:80px;height:80px;border-radius:50%;background:var(--brand-50);display:grid;place-items:center;margin:0 auto 18px;">
                <svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="var(--brand-500)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.7 21a2 2 0 0 1-3.4 0"/>
                </svg>
            </div>
            <h3 style="font-size:17px;font-weight:700;color:var(--text);margin:0 0 6px;">
                <?php echo $filter === 'unread' ? 'No unread notifications' : ($filter === 'read' ? 'No read notifications' : 'No notifications yet'); ?>
            </h3>
            <p style="font-size:13.5px;color:var(--text-mute);margin:0 0 20px;line-height:1.5;max-width:380px;margin-left:auto;margin-right:auto;">
                <?php echo $filter === 'unread' ? "You've read everything. New notifications about your orders, messages, and listings will appear here." : "When you receive orders, messages, or updates about your listings, you'll see them here."; ?>
            </p>
            <a href="<?php echo APP_URL; ?>/pages/explore.php" class="btn btn--primary">Explore Marketplace</a>
        </div>
    <?php else: ?>
        <!-- Notifications -->
        <div style="display:flex;flex-direction:column;gap:8px;">
            <?php foreach ($notes as $n):
                // Determine icon + color based on notification type
                $typeIcons = [
                    'order'      => ['icon' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 16h.01M8 16h.01M3 9h18M5 5h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/></svg>', 'color' => 'brand'],
                    'payment'    => ['icon' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4M3 5v14a2 2 0 0 0 2 2h16v-5M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>', 'color' => 'green'],
                    'message'    => ['icon' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>', 'color' => 'blue'],
                    'favorite'   => ['icon' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-4.5 9.5-9A4.5 4.5 0 0 0 12 5.5 4.5 4.5 0 0 0 2.5 12c2.5 4.5 9.5 9 9.5 9z"/></svg>', 'color' => 'orange'],
                    'rental'     => ['icon' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>', 'color' => 'blue'],
                    'system'     => ['icon' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>', 'color' => 'mute'],
                    'review'     => ['icon' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>', 'color' => 'orange'],
                    'default'    => ['icon' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>', 'color' => 'mute'],
                ];
                $ti = $typeIcons[$n['type']] ?? $typeIcons['default'];
                $colorMap = [
                    'brand'  => ['bg' => 'var(--brand-50)', 'fg' => 'var(--brand-600)'],
                    'green'  => ['bg' => '#ecfdf3', 'fg' => '#058555'],
                    'blue'   => ['bg' => '#eff5ff', 'fg' => '#2563eb'],
                    'orange' => ['bg' => '#fff7e9', 'fg' => '#c4730a'],
                    'mute'   => ['bg' => 'var(--bg-soft)', 'fg' => 'var(--text-mute)'],
                ];
                $c = $colorMap[$ti['color']];
            ?>
                <div class="notif-item <?php echo !$n['is_read'] ? 'is-unread' : ''; ?>" style="background:<?php echo !$n['is_read'] ? 'var(--brand-50)' : 'var(--bg-card)'; ?>;">
                    <div class="notif-item__icon" style="background:<?php echo $c['bg']; ?>;color:<?php echo $c['fg']; ?>;">
                        <?php echo $ti['icon']; ?>
                    </div>
                    <div class="notif-item__body">
                        <div class="notif-item__head">
                            <strong><?php echo e($n['title']); ?></strong>
                            <?php if (!$n['is_read']): ?><span class="notif-item__dot"></span><?php endif; ?>
                        </div>
                        <?php if ($n['body']): ?>
                            <p><?php echo e($n['body']); ?></p>
                        <?php endif; ?>
                        <div class="notif-item__meta">
                            <span class="notif-item__type"><?php echo e($n['type']); ?></span>
                            <span>•</span>
                            <span><?php echo e(time_ago($n['created_at'])); ?></span>
                        </div>
                    </div>
                    <div class="notif-item__actions">
                        <?php if ($n['link']): ?>
                            <a href="<?php echo APP_URL . '/' . ltrim($n['link'], '/'); ?>" class="notif-item__view" title="View">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17L17 7M7 7h10v10"/></svg>
                            </a>
                        <?php endif; ?>
                        <?php if (!$n['is_read']): ?>
                            <form method="post" action="" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="notification_id" value="<?php echo (int)$n['id']; ?>">
                                <button type="submit" class="notif-item__action" title="Mark as read">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                </button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="" style="display:inline;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="notification_id" value="<?php echo (int)$n['id']; ?>">
                            <button type="submit" class="notif-item__action notif-item__action--danger" title="Delete">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<style>
/* Filter tabs */
.notif-tabs { flex-wrap: wrap; }
.notif-tab {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 10px 14px;
    font-size: 13.5px; font-weight: 600;
    color: var(--text-mute);
    text-decoration: none;
    border-bottom: 2px solid transparent;
    transition: all 160ms ease;
    margin-bottom: -1px;
}
.notif-tab:hover { color: var(--text); }
.notif-tab.is-active { color: var(--brand-600); border-bottom-color: var(--brand-500); }
.notif-tab__count {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 20px; height: 20px; padding: 0 6px;
    border-radius: 10px;
    background: var(--bg-soft);
    color: var(--text-mute);
    font-size: 11px; font-weight: 700;
}
.notif-tab__count--unread { background: var(--brand-500); color: #fff; }

/* Notification items */
.notif-item {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 14px 16px;
    border: 1px solid var(--border);
    border-radius: 12px;
    transition: all 160ms ease;
}
.notif-item:hover { box-shadow: 0 2px 8px rgba(15,41,38,.06); border-color: var(--brand-200); }
.notif-item.is-unread { border-left: 3px solid var(--brand-500); }
.notif-item__icon {
    flex: 0 0 36px; width: 36px; height: 36px; border-radius: 10px;
    display: grid; place-items: center;
}
.notif-item__body { flex: 1; min-width: 0; }
.notif-item__head { display: flex; align-items: center; gap: 8px; margin-bottom: 2px; }
.notif-item__head strong { font-size: 13.5px; font-weight: 700; color: var(--text); }
.notif-item__dot { width: 8px; height: 8px; border-radius: 50%; background: var(--brand-500); flex: 0 0 8px; }
.notif-item__body p { margin: 0 0 6px; font-size: 12.5px; color: var(--text-soft); line-height: 1.5; }
.notif-item__meta { display: flex; align-items: center; gap: 6px; font-size: 11.5px; color: var(--text-mute); }
.notif-item__type { text-transform: uppercase; letter-spacing: .05em; font-weight: 700; }
.notif-item__actions { display: flex; align-items: center; gap: 4px; flex: 0 0 auto; }
.notif-item__view, .notif-item__action {
    width: 30px; height: 30px; border-radius: 8px;
    display: grid; place-items: center;
    border: 1px solid var(--border);
    background: var(--bg-card);
    color: var(--text-mute);
    cursor: pointer;
    text-decoration: none;
    transition: all 150ms ease;
}
.notif-item__view:hover { color: var(--brand-600); border-color: var(--brand-300); background: var(--brand-50); }
.notif-item__action:hover { color: var(--brand-600); border-color: var(--brand-300); background: var(--brand-50); }
.notif-item__action--danger:hover { color: #d9534a; border-color: #f4c6c6; background: #fef3f2; }

@media (max-width: 600px) {
    .notif-item { flex-wrap: wrap; }
    .notif-item__actions { width: 100%; justify-content: flex-end; margin-top: 4px; }
    .notif-tab { padding: 8px 12px; font-size: 13px; }
}
</style>
<?php seller_page_end(); ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
