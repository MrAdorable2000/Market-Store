<?php
/**
 * pages/admin/reports.php
 * --------------------------------------------------------------------
 * IsokoRyacu report center (Phase 5).
 *
 * Features:
 *   - Status filter chips with real counts (all / open / reviewing /
 *     resolved / dismissed)
 *   - KPI strip: open, reviewing, resolved, dismissed
 *   - Report details (reason, full details text, listing, reporter)
 *   - Working actions: start review, resolve, dismiss (CSRF-guarded)
 *   - Direct link to the reported listing
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_icons.php';
require_once __DIR__ . '/../../includes/admin_ui.php';
require_role('admin');

$pdo = db();

/* ---------- Filters ---------- */
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? '')));
if (!in_array($statusFilter, ['open', 'reviewing', 'resolved', 'dismissed'], true)) $statusFilter = '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;

/* ---------- Counts ---------- */
$counts = ['open' => 0, 'reviewing' => 0, 'resolved' => 0, 'dismissed' => 0];
foreach ($pdo->query("SELECT status, COUNT(*) n FROM reports GROUP BY status") as $row) {
    if (isset($counts[$row['status']])) $counts[$row['status']] = (int) $row['n'];
}
$totalAll = array_sum($counts);

/* ---------- Rows ---------- */
$whereSql  = $statusFilter ? ' WHERE r.status = ?' : '';
$params    = $statusFilter ? [$statusFilter] : [];
$stmt = $pdo->prepare("SELECT COUNT(*) FROM reports r" . $whereSql);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$pages = (int) ceil($total / $perPage);
if ($page > $pages && $pages > 0) $page = $pages;
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT r.id, r.reason, r.details, r.status, r.created_at,
        l.title AS listing_title, l.id AS listing_id, l.status AS listing_status,
        u.full_name AS reporter_name, u.email AS reporter_email
    FROM reports r
    LEFT JOIN listings l ON l.id = r.listing_id
    LEFT JOIN users u ON u.id = r.reporter_id" . $whereSql . "
    ORDER BY FIELD(r.status, 'open', 'reviewing', 'resolved', 'dismissed'), r.created_at DESC
    LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$reports = $stmt->fetchAll();

$baseUrl = APP_URL . '/pages/admin/reports.php';
$keep = ['status' => $statusFilter ?: null];
$keep = array_filter($keep);

/* ---------- Page setup ---------- */
$pageTitle         = t('admin.nav_reports');
$activePage        = 'admin';
$adminActivePage   = 'reports';
$extraCss          = '<link rel="stylesheet" href="' . asset_url('assets/css/admin.css') . '">';
$adminPageTitle    = t('admin.nav_reports');
$adminPageSubtitle = t('admin.reports_sub');
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="a-shell" id="aShell">
<?php require __DIR__ . '/../../includes/admin_sidebar.php'; ?>
<div class="a-main">
<?php require __DIR__ . '/../../includes/admin_topbar.php'; ?>
<div class="a-content">

<div class="a-hero">
    <div>
        <h2><?php echo e(t('admin.report_center_title')); ?></h2>
        <p class="a-hero__sub"><?php echo e(t('admin.reports_sub')); ?></p>
    </div>
</div>

<!-- KPI strip -->
<div class="a-kpis">
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label"><?php echo e(t('admin.stat_open_reports')); ?></span><span class="a-kpi__icon a-kpi__icon--red"><?php echo admin_icon('reports'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($counts['open']); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo e(t('admin.needs_attention')); ?></span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label"><?php echo e(t('admin.status_reviewing')); ?></span><span class="a-kpi__icon a-kpi__icon--amber"><?php echo admin_icon('clock'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($counts['reviewing']); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo e(t('admin.report_being_reviewed')); ?></span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label"><?php echo e(t('admin.status_resolved')); ?></span><span class="a-kpi__icon a-kpi__icon--green"><?php echo admin_icon('check-circle'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($counts['resolved']); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo e(t('admin.report_handled')); ?></span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label"><?php echo e(t('admin.status_dismissed')); ?></span><span class="a-kpi__icon"><?php echo admin_icon('close-x'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($counts['dismissed']); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo e(t('admin.report_dismissed_sub')); ?></span></div>
    </div>
</div>

<section class="a-panel">
    <div class="a-panel__head">
        <div>
            <h3><?php echo e(t('admin.panel_reports_title')); ?></h3>
            <p><?php echo number_format($total); ?> <?php echo e(t('admin.reports_count')); ?></p>
        </div>
        <div class="a-panel__tools">
            <div class="a-filters">
                <a class="a-chip <?php echo $statusFilter === '' ? 'is-active' : ''; ?>" href="<?php echo $baseUrl; ?>"><?php echo e(t('search.all')); ?> <b><?php echo number_format($totalAll); ?></b></a>
                <a class="a-chip <?php echo $statusFilter === 'open' ? 'is-active' : ''; ?>" href="<?php echo $baseUrl; ?>?status=open"><?php echo e(t('admin.status_open')); ?> <b><?php echo number_format($counts['open']); ?></b></a>
                <a class="a-chip <?php echo $statusFilter === 'reviewing' ? 'is-active' : ''; ?>" href="<?php echo $baseUrl; ?>?status=reviewing"><?php echo e(t('admin.status_reviewing')); ?> <b><?php echo number_format($counts['reviewing']); ?></b></a>
                <a class="a-chip <?php echo $statusFilter === 'resolved' ? 'is-active' : ''; ?>" href="<?php echo $baseUrl; ?>?status=resolved"><?php echo e(t('admin.status_resolved')); ?> <b><?php echo number_format($counts['resolved']); ?></b></a>
                <a class="a-chip <?php echo $statusFilter === 'dismissed' ? 'is-active' : ''; ?>" href="<?php echo $baseUrl; ?>?status=dismissed"><?php echo e(t('admin.status_dismissed')); ?> <b><?php echo number_format($counts['dismissed']); ?></b></a>
            </div>
        </div>
    </div>

    <?php if (!$reports): ?>
        <?php echo admin_empty_state('check-circle', t('admin.empty_no_reports'), t('admin.empty_no_reports_sub')); ?>
    <?php else: ?>
    <div class="a-tablewrap">
        <table class="a-table">
            <thead><tr>
                <th><?php echo e(t('listings.detail.report_reason')); ?></th>
                <th><?php echo e(t('listings.detail.report_details')); ?></th>
                <th><?php echo e(t('common.listing')); ?></th>
                <th><?php echo e(t('common.reviewer')); ?></th>
                <th><?php echo e(t('admin.col_status')); ?></th>
                <th><?php echo e(t('admin.col_posted')); ?></th>
                <th><?php echo e(t('admin.col_actions')); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($reports as $r): ?>
                <tr>
                    <td data-label="<?php echo e(t('listings.detail.report_reason')); ?>" class="cell-primary"><?php echo e($r['reason']); ?></td>
                    <td data-label="<?php echo e(t('listings.detail.report_details')); ?>" class="cell-mute" style="max-width:260px;"><?php echo e($r['details'] ? excerpt($r['details'], 90) : '—'); ?></td>
                    <td data-label="<?php echo e(t('common.listing')); ?>" class="cell-mute">
                        <?php if ($r['listing_id']): ?>
                            <a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int) $r['listing_id']; ?>"><?php echo e($r['listing_title']); ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td data-label="<?php echo e(t('common.reviewer')); ?>" class="cell-mute"><?php echo e($r['reporter_name'] ?? t('common.guest')); ?></td>
                    <td data-label="<?php echo e(t('admin.col_status')); ?>">
                        <span class="a-status a-status--<?php echo e($r['status']); ?>"><?php echo e(t('admin.status_' . $r['status'])); ?></span>
                    </td>
                    <td data-label="<?php echo e(t('admin.col_posted')); ?>" class="cell-mute"><?php echo e(time_ago($r['created_at'])); ?></td>
                    <td data-label="<?php echo e(t('admin.col_actions')); ?>">
                        <div class="a-rowactions">
                            <?php if ($r['status'] === 'open'): ?>
                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php" style="display:contents;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="report_status">
                                    <input type="hidden" name="status" value="reviewing">
                                    <input type="hidden" name="report_id" value="<?php echo (int) $r['id']; ?>">
                                    <button class="a-btn a-btn--sm" type="submit"><?php echo e(t('admin.start_review')); ?></button>
                                </form>
                            <?php endif; ?>
                            <?php if (in_array($r['status'], ['open', 'reviewing'], true)): ?>
                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php" style="display:contents;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="report_status">
                                    <input type="hidden" name="status" value="resolved">
                                    <input type="hidden" name="report_id" value="<?php echo (int) $r['id']; ?>">
                                    <button class="a-btn a-btn--primary a-btn--sm" type="submit"><?php echo e(t('admin.resolve')); ?></button>
                                </form>
                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php" style="display:contents;"
                                      data-confirm="<?php echo e(t('admin.dismiss_confirm')); ?>"
                                      data-confirm-title="<?php echo e(t('admin.dismiss_title')); ?>"
                                      data-confirm-label="<?php echo e(t('admin.dismiss_action')); ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="report_status">
                                    <input type="hidden" name="status" value="dismissed">
                                    <input type="hidden" name="report_id" value="<?php echo (int) $r['id']; ?>">
                                    <button class="a-btn a-btn--sm" type="submit"><?php echo e(t('admin.dismiss_action')); ?></button>
                                </form>
                            <?php else: ?>
                                <span class="cell-mute">—</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php echo admin_pagination($total, $perPage, $page, $baseUrl, $keep); ?>
    <?php endif; ?>
</section>

</div><!-- /a-content -->
<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>
