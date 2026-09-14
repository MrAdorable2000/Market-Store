<?php
/**
 * pages/admin/rentals.php
 * --------------------------------------------------------------------
 * IsokoRyacu rental request management (Phase 5) — NEW PAGE.
 *
 * The rental_requests table already existed (created by the public
 * listing-details flow). This page gives administrators a real console:
 *
 *   - KPI strip: total / pending / approved / declined (real counts)
 *   - Status filter chips
 *   - Table: renter, listing, date range, deposit, message, status, age
 *   - Approve / decline actions (CSRF-guarded, notify the renter,
 *     recorded in system logs)
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
if (!in_array($statusFilter, ['pending', 'approved', 'declined', 'cancelled', 'completed'], true)) $statusFilter = '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;

/* ---------- Counts ---------- */
$counts = ['pending' => 0, 'approved' => 0, 'declined' => 0, 'cancelled' => 0, 'completed' => 0];
foreach ($pdo->query("SELECT status, COUNT(*) n FROM rental_requests GROUP BY status") as $row) {
    if (isset($counts[$row['status']])) $counts[$row['status']] = (int) $row['n'];
}
$totalAll = array_sum($counts);

/* ---------- Rows ---------- */
$whereSql = $statusFilter ? ' WHERE rr.status = ?' : '';
$params   = $statusFilter ? [$statusFilter] : [];
$stmt = $pdo->prepare("SELECT COUNT(*) FROM rental_requests rr" . $whereSql);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$pages = (int) ceil($total / $perPage);
if ($page > $pages && $pages > 0) $page = $pages;
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT rr.id, rr.start_date, rr.end_date, rr.deposit_amount, rr.message, rr.status, rr.created_at,
        u.full_name AS renter_name, u.email AS renter_email,
        l.id AS listing_id, l.title AS listing_title, l.currency,
        seller.full_name AS seller_name
    FROM rental_requests rr
    INNER JOIN users u ON u.id = rr.renter_id
    INNER JOIN listings l ON l.id = rr.listing_id
    INNER JOIN users seller ON seller.id = l.seller_id" . $whereSql . "
    ORDER BY FIELD(rr.status, 'pending', 'approved', 'declined', 'cancelled', 'completed'), rr.created_at DESC
    LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rentals = $stmt->fetchAll();

$baseUrl = APP_URL . '/pages/admin/rentals.php';
$keep = array_filter(['status' => $statusFilter ?: null]);

/* ---------- Page setup ---------- */
$pageTitle         = t('admin.nav_rentals');
$activePage        = 'admin';
$adminActivePage   = 'rentals';
$extraCss          = '<link rel="stylesheet" href="' . APP_URL . '/assets/css/admin.css">';
$adminPageTitle    = t('admin.nav_rentals');
$adminPageSubtitle = t('admin.rentals_sub');
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="a-shell" id="aShell">
<?php require __DIR__ . '/../../includes/admin_sidebar.php'; ?>
<div class="a-main">
<?php require __DIR__ . '/../../includes/admin_topbar.php'; ?>
<div class="a-content">

<div class="a-hero">
    <div>
        <h2><?php echo e(t('admin.nav_rentals')); ?></h2>
        <p class="a-hero__sub"><?php echo e(t('admin.rentals_sub')); ?></p>
    </div>
</div>

<div class="a-kpis">
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label"><?php echo e(t('admin.stat_rentals')); ?></span><span class="a-kpi__icon"><?php echo admin_icon('rentals'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($totalAll); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo e(t('admin.rentals_total_sub')); ?></span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label"><?php echo e(t('admin.status_pending')); ?></span><span class="a-kpi__icon a-kpi__icon--amber"><?php echo admin_icon('clock'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($counts['pending']); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo e(t('admin.needs_attention')); ?></span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label"><?php echo e(t('admin.status_approved')); ?></span><span class="a-kpi__icon a-kpi__icon--green"><?php echo admin_icon('check'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($counts['approved']); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo e(t('admin.rentals_approved_sub')); ?></span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label"><?php echo e(t('admin.status_declined')); ?></span><span class="a-kpi__icon a-kpi__icon--red"><?php echo admin_icon('close-x'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($counts['declined']); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo e(t('admin.rentals_declined_sub')); ?></span></div>
    </div>
</div>

<section class="a-panel">
    <div class="a-panel__head">
        <div>
            <h3><?php echo e(t('admin.rental_queue')); ?></h3>
            <p><?php echo number_format($total); ?> <?php echo e(t('admin.requests_count')); ?></p>
        </div>
        <div class="a-panel__tools">
            <div class="a-filters">
                <a class="a-chip <?php echo $statusFilter === '' ? 'is-active' : ''; ?>" href="<?php echo $baseUrl; ?>"><?php echo e(t('search.all')); ?> <b><?php echo number_format($totalAll); ?></b></a>
                <?php foreach ($counts as $st => $n): ?>
                    <a class="a-chip <?php echo $statusFilter === $st ? 'is-active' : ''; ?>" href="<?php echo $baseUrl; ?>?status=<?php echo e($st); ?>"><?php echo e(t('admin.status_' . $st)); ?> <b><?php echo number_format($n); ?></b></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php if (!$rentals): ?>
        <?php echo admin_empty_state('rentals', t('admin.empty_no_rentals'), t('admin.empty_no_rentals_sub')); ?>
    <?php else: ?>
    <div class="a-tablewrap">
        <table class="a-table">
            <thead><tr>
                <th><?php echo e(t('admin.col_renter')); ?></th>
                <th><?php echo e(t('common.listing')); ?></th>
                <th><?php echo e(t('admin.col_period')); ?></th>
                <th><?php echo e(t('admin.col_deposit')); ?></th>
                <th><?php echo e(t('admin.col_message')); ?></th>
                <th><?php echo e(t('admin.col_status')); ?></th>
                <th><?php echo e(t('admin.col_requested')); ?></th>
                <th><?php echo e(t('admin.col_actions')); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rentals as $r): ?>
                <tr>
                    <td data-label="<?php echo e(t('admin.col_renter')); ?>">
                        <span class="a-table__user">
                            <span class="a-avatar a-avatar--sm"><?php echo e(strtoupper(substr($r['renter_name'], 0, 1))); ?></span>
                            <span style="min-width:0;">
                                <span class="a-table__listing-title"><?php echo e($r['renter_name']); ?></span>
                                <span class="a-table__listing-sub"><?php echo e($r['renter_email']); ?></span>
                            </span>
                        </span>
                    </td>
                    <td data-label="<?php echo e(t('common.listing')); ?>">
                        <a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int) $r['listing_id']; ?>"><?php echo e($r['listing_title']); ?></a>
                        <div class="a-table__listing-sub"><?php echo e(t('admin.col_seller')); ?>: <?php echo e($r['seller_name']); ?></div>
                    </td>
                    <td data-label="<?php echo e(t('admin.col_period')); ?>" class="cell-mute"><?php echo e(date('M j, Y', strtotime($r['start_date']))); ?> → <?php echo e(date('M j, Y', strtotime($r['end_date']))); ?></td>
                    <td data-label="<?php echo e(t('admin.col_deposit')); ?>" class="cell-num"><?php echo $r['deposit_amount'] ? e(format_price($r['deposit_amount'], $r['currency'])) : '—'; ?></td>
                    <td data-label="<?php echo e(t('admin.col_message')); ?>" class="cell-mute" style="max-width:240px;"><?php echo e($r['message'] ? excerpt($r['message'], 80) : '—'); ?></td>
                    <td data-label="<?php echo e(t('admin.col_status')); ?>">
                        <span class="a-status a-status--<?php echo e($r['status']); ?>"><?php echo e(t('admin.status_' . $r['status'])); ?></span>
                    </td>
                    <td data-label="<?php echo e(t('admin.col_requested')); ?>" class="cell-mute"><?php echo e(time_ago($r['created_at'])); ?></td>
                    <td data-label="<?php echo e(t('admin.col_actions')); ?>">
                        <div class="a-rowactions">
                            <?php if ($r['status'] === 'pending'): ?>
                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php" style="display:contents;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="rental_approve">
                                    <input type="hidden" name="rental_id" value="<?php echo (int) $r['id']; ?>">
                                    <button class="a-btn a-btn--primary a-btn--sm" type="submit"><?php echo e(t('buttons.approve')); ?></button>
                                </form>
                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php" style="display:contents;"
                                      data-confirm="<?php echo e(t('admin.rental_decline_confirm')); ?>"
                                      data-confirm-title="<?php echo e(t('admin.rental_decline_title')); ?>"
                                      data-confirm-label="<?php echo e(t('buttons.reject')); ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="rental_decline">
                                    <input type="hidden" name="rental_id" value="<?php echo (int) $r['id']; ?>">
                                    <button class="a-btn a-btn--sm" type="submit"><?php echo e(t('buttons.reject')); ?></button>
                                </form>
                            <?php else: ?>
                                <a class="a-btn a-btn--sm" href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int) $r['listing_id']; ?>"><?php echo e(t('buttons.view')); ?></a>
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
