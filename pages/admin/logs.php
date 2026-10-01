<?php
/**
 * pages/admin/logs.php
 * --------------------------------------------------------------------
 * IsokoRyacu system logs (Phase 5) — NEW PAGE.
 *
 * Shows ONLY actions that really happened — every row is written by
 * admin_log() from the admin actions endpoint, listing actions, message
 * actions, report handling, review moderation, settings changes and
 * admin logins/logouts. Nothing is fabricated.
 *
 * Features:
 *   - Search (user, action, details)
 *   - Module filter chips (real counts)
 *   - Status filter (success / error)
 *   - Date range (from / to)
 *   - Pagination
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_icons.php';
require_once __DIR__ . '/../../includes/admin_ui.php';
require_once __DIR__ . '/../../includes/admin_log.php';
require_role('admin');

$pdo = db();

/* Make sure the table exists (matches sql/admin_migrate.sql). */
admin_log_ensure_table();

/* Does the table exist (migration applied / auto-created)? */
$tableExists = false;
try {
    $pdo->query('SELECT 1 FROM system_logs LIMIT 1');
    $tableExists = true;
} catch (PDOException $e) {
    $tableExists = false;
}

/* ---------- Filters ---------- */
$q       = trim((string) ($_GET['q'] ?? ''));
$module  = strtolower(trim((string) ($_GET['module'] ?? '')));
$statusF = strtolower(trim((string) ($_GET['status'] ?? '')));
$from    = trim((string) ($_GET['from'] ?? ''));
$to      = trim((string) ($_GET['to'] ?? ''));
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;

$validModules = [];
$moduleCounts = [];
$totalLogs = 0;

if ($tableExists) {
    foreach ($pdo->query("SELECT module, COUNT(*) n FROM system_logs GROUP BY module ORDER BY n DESC") as $row) {
        $validModules[$row['module']] = (int) $row['n'];
    }
    $totalLogs = (int) $pdo->query("SELECT COUNT(*) FROM system_logs")->fetchColumn();
    $moduleCounts = $validModules;
    if (!isset($validModules[$module])) $module = '';
    if (!in_array($statusF, ['success', 'error'], true)) $statusF = '';
    if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '';
    if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = '';
}

/* ---------- WHERE ---------- */
$where  = [];
$params = [];
if ($tableExists) {
    if ($module !== '')   { $where[] = 'module = ?';  $params[] = $module; }
    if ($statusF !== '')  { $where[] = 'status = ?';  $params[] = $statusF; }
    if ($from !== '')     { $where[] = 'created_at >= ?'; $params[] = $from . ' 00:00:00'; }
    if ($to !== '')       { $where[] = 'created_at <= ?'; $params[] = $to . ' 23:59:59'; }
    if ($q !== '') {
        $where[] = '(user_name LIKE ? OR action LIKE ? OR details LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

/* ---------- Rows ---------- */
$logs = [];
$total = 0;
if ($tableExists) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM system_logs" . $whereSql);
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();
    $pages = (int) ceil($total / $perPage);
    if ($page > $pages && $pages > 0) $page = $pages;
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare("SELECT id, user_id, user_name, module, action, status, details, ip_address, created_at
        FROM system_logs" . $whereSql . "
        ORDER BY created_at DESC
        LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
}

$baseUrl = APP_URL . '/pages/admin/logs.php';
$keep = array_filter([
    'q' => $q !== '' ? $q : null,
    'module' => $module ?: null,
    'status' => $statusF ?: null,
    'from' => $from ?: null,
    'to' => $to ?: null,
], static fn ($v) => $v !== null);

/** Human label for a module key. */
function admin_module_label(string $m): string
{
    $map = [
        'listings' => 'admin.log_module_listings', 'users' => 'admin.log_module_users',
        'categories' => 'admin.log_module_categories', 'messages' => 'admin.log_module_messages',
        'reports' => 'admin.log_module_reports', 'rentals' => 'admin.log_module_rentals',
        'reviews' => 'admin.log_module_reviews', 'settings' => 'admin.log_module_settings',
        'auth' => 'admin.log_module_auth', 'system' => 'admin.log_module_system',
    ];
    return t($map[$m] ?? 'admin.log_module_system');
}

/* ---------- Page setup ---------- */
$pageTitle         = t('admin.nav_logs');
$activePage        = 'admin';
$adminActivePage   = 'logs';
$extraCss          = '<link rel="stylesheet" href="' . asset_url('assets/css/admin.css') . '">';
$adminPageTitle    = t('admin.nav_logs');
$adminPageSubtitle = t('admin.logs_sub');
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="a-shell" id="aShell">
<?php require __DIR__ . '/../../includes/admin_sidebar.php'; ?>
<div class="a-main">
<?php require __DIR__ . '/../../includes/admin_topbar.php'; ?>
<div class="a-content">

<div class="a-hero">
    <div>
        <h2><?php echo e(t('admin.nav_logs')); ?></h2>
        <p class="a-hero__sub"><?php echo e(t('admin.logs_sub')); ?></p>
    </div>
    <div class="a-hero__actions">
        <span class="a-kpi__hint" style="align-self:center;"><?php echo number_format($totalLogs); ?> <?php echo e(t('admin.log_entries')); ?></span>
    </div>
</div>

<?php if (!$tableExists): ?>
    <!-- Migration not applied and auto-create failed (rare) — friendly guidance -->
    <section class="a-panel">
        <?php echo admin_empty_state('logs', t('admin.logs_missing_title'), t('admin.logs_missing_sub')); ?>
        <div style="text-align:center; margin-top:12px;">
            <code style="font-size:11.5px; background:var(--a-card-soft); border:1px solid var(--a-line); padding:8px 14px; border-radius:9px;">mysql -u root -p isoko_ryacu &lt; sql/admin_migrate.sql</code>
        </div>
    </section>
<?php else: ?>

<!-- Filters -->
<section class="a-panel" style="padding:15px 18px;">
    <div class="a-panel__tools" style="width:100%;">
        <form class="a-search" style="max-width:320px; flex:1 1 240px; margin:0;" method="get" action="<?php echo $baseUrl; ?>" role="search">
            <?php echo admin_icon('search'); ?>
            <?php foreach ($keep as $k => $v): if ($k === 'q') continue; ?>
                <input type="hidden" name="<?php echo e($k); ?>" value="<?php echo e($v); ?>">
            <?php endforeach; ?>
            <input type="search" name="q" value="<?php echo e($q); ?>" placeholder="<?php echo e(t('admin.search_logs_placeholder')); ?>">
        </form>
        <form method="get" action="<?php echo $baseUrl; ?>" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <?php foreach ($keep as $k => $v): if (in_array($k, ['from', 'to'], true)) continue; ?>
                <input type="hidden" name="<?php echo e($k); ?>" value="<?php echo e($v); ?>">
            <?php endforeach; ?>
            <input class="a-input" type="date" name="from" value="<?php echo e($from); ?>" title="<?php echo e(t('admin.log_from')); ?>" style="width:150px;">
            <input class="a-input" type="date" name="to" value="<?php echo e($to); ?>" title="<?php echo e(t('admin.log_to')); ?>" style="width:150px;">
            <button class="a-btn a-btn--sm" type="submit"><?php echo admin_icon('calendar'); ?><span><?php echo e(t('admin.log_apply')); ?></span></button>
        </form>
    </div>
    <div class="a-filters" style="margin-top:11px;">
        <a class="a-chip <?php echo $module === '' ? 'is-active' : ''; ?>" href="<?php echo e($baseUrl . ($q ? '?q=' . urlencode($q) : '')); ?>"><?php echo e(t('search.all')); ?> <b><?php echo number_format($totalLogs); ?></b></a>
        <?php foreach ($moduleCounts as $mKey => $mCount): ?>
            <a class="a-chip <?php echo $module === $mKey ? 'is-active' : ''; ?>" href="<?php echo $baseUrl; ?>?<?php echo http_build_query(array_filter(array_merge($keep, ['module' => $mKey, 'page' => null]))); ?>"><?php echo e(admin_module_label($mKey)); ?> <b><?php echo number_format($mCount); ?></b></a>
        <?php endforeach; ?>
        <span style="width:1px; height:22px; background:var(--a-line);"></span>
        <a class="a-chip <?php echo $statusF === 'success' ? 'is-active' : ''; ?>" href="<?php echo $baseUrl; ?>?<?php echo http_build_query(array_filter(array_merge($keep, ['status' => $statusF === 'success' ? null : 'success', 'page' => null]))); ?>"><?php echo e(t('admin.log_success')); ?></a>
        <a class="a-chip <?php echo $statusF === 'error' ? 'is-active' : ''; ?>" href="<?php echo $baseUrl; ?>?<?php echo http_build_query(array_filter(array_merge($keep, ['status' => $statusF === 'error' ? null : 'error', 'page' => null]))); ?>"><?php echo e(t('admin.log_error')); ?></a>
    </div>
</section>

<section class="a-panel">
    <div class="a-panel__head">
        <div>
            <h3><?php echo e(t('admin.log_activity_title')); ?></h3>
            <p><?php echo number_format($total); ?> <?php echo e(t('admin.log_entries')); ?><?php echo $q !== '' ? ' · "' . e($q) . '"' : ''; ?></p>
        </div>
        <?php if ($q !== '' || $module || $statusF || $from || $to): ?>
            <a class="a-panel__link" href="<?php echo $baseUrl; ?>"><?php echo e(t('admin.clear_filters')); ?></a>
        <?php endif; ?>
    </div>

    <?php if (!$logs): ?>
        <?php echo admin_empty_state('logs', t('admin.empty_no_logs'), t('admin.empty_no_logs_sub')); ?>
    <?php else: ?>
    <div class="a-tablewrap">
        <table class="a-table">
            <thead><tr>
                <th><?php echo e(t('admin.col_datetime')); ?></th>
                <th><?php echo e(t('admin.col_user')); ?></th>
                <th><?php echo e(t('admin.log_module')); ?></th>
                <th><?php echo e(t('admin.log_action')); ?></th>
                <th><?php echo e(t('admin.col_status')); ?></th>
                <th><?php echo e(t('admin.log_details')); ?></th>
                <th><?php echo e(t('admin.log_ip')); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td data-label="<?php echo e(t('admin.col_datetime')); ?>" class="cell-mute" style="white-space:nowrap;"><?php echo e(date('M j, Y · H:i', strtotime($log['created_at']))); ?></td>
                    <td data-label="<?php echo e(t('admin.col_user')); ?>" class="cell-primary">
                        <?php if ($log['user_id']): ?>
                            <a href="<?php echo APP_URL; ?>/pages/seller-profile.php?id=<?php echo (int) $log['user_id']; ?>"><?php echo e($log['user_name'] ?? '—'); ?></a>
                        <?php else: ?>
                            <?php echo e($log['user_name'] ?? t('common.guest')); ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?php echo e(t('admin.log_module')); ?>">
                        <span class="a-status a-status--neutral"><?php echo e(admin_module_label($log['module'])); ?></span>
                    </td>
                    <td data-label="<?php echo e(t('admin.log_action')); ?>"><code style="font-size:11px; background:var(--a-card-soft); padding:3px 8px; border-radius:7px;"><?php echo e($log['action']); ?></code></td>
                    <td data-label="<?php echo e(t('admin.col_status')); ?>">
                        <span class="a-status a-status--<?php echo e($log['status']); ?>"><?php echo e($log['status'] === 'success' ? t('admin.log_success') : t('admin.log_error')); ?></span>
                    </td>
                    <td data-label="<?php echo e(t('admin.log_details')); ?>" class="cell-mute" style="max-width:280px;"><?php echo e($log['details'] ?: '—'); ?></td>
                    <td data-label="<?php echo e(t('admin.log_ip')); ?>" class="cell-mute"><?php echo e($log['ip_address'] ?: '—'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php echo admin_pagination($total, $perPage, $page, $baseUrl, $keep); ?>
    <?php endif; ?>
</section>
<?php endif; ?>

</div><!-- /a-content -->
<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>
