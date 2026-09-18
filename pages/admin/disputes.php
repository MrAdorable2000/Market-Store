<?php
/**
 * pages/admin/disputes.php
 * --------------------------------------------------------------------
 * Admin dispute resolution.
 *
 * Closes the gap documented in docs/dispute-resolution-gap.md: buyers
 * could already open a dispute (pages/orders.php, action=open_dispute),
 * but nothing could ever resolve one, permanently freezing the order's
 * escrowed funds. Resolution logic itself lives in the dispute_resolve
 * admin action (api/v1/admin-action/index.php) — this page is the list +
 * resolve form.
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
if (!in_array($statusFilter, ['open', 'under_review', 'resolved', 'closed'], true)) $statusFilter = '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;

/* ---------- Counts ---------- */
$counts = ['open' => 0, 'under_review' => 0, 'resolved' => 0, 'closed' => 0];
foreach ($pdo->query("SELECT status, COUNT(*) n FROM disputes GROUP BY status") as $row) {
    if (isset($counts[$row['status']])) $counts[$row['status']] = (int) $row['n'];
    else $counts['open'] += (int) $row['n']; // waiting_buyer/waiting_seller roll into "open" for this simple view
}
$totalAll = array_sum($counts);

/* ---------- Rows ---------- */
$whereSql = $statusFilter ? ' WHERE d.status = ?' : '';
$params   = $statusFilter ? [$statusFilter] : [];
$stmt = $pdo->prepare("SELECT COUNT(*) FROM disputes d" . $whereSql);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$pages = (int) ceil($total / $perPage);
if ($page > $pages && $pages > 0) $page = $pages;
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT d.id, d.dispute_number, d.reason, d.description, d.status, d.resolution,
        d.refund_amount, d.created_at, d.resolved_at,
        o.id AS order_id, o.order_number, o.grand_total, o.payment_status,
        buyer.full_name AS buyer_name, seller.full_name AS seller_name
    FROM disputes d
    JOIN orders o ON o.id = d.order_id
    JOIN users buyer ON buyer.id = d.opened_by
    JOIN users seller ON seller.id = d.against_user_id" . $whereSql . "
    ORDER BY FIELD(d.status, 'open', 'under_review', 'waiting_buyer', 'waiting_seller', 'resolved', 'closed'), d.created_at DESC
    LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$disputes = $stmt->fetchAll();

$baseUrl = APP_URL . '/pages/admin/disputes.php';
$keep = array_filter(['status' => $statusFilter ?: null]);

$pageTitle         = 'Disputes';
$activePage        = 'admin';
$adminActivePage   = 'disputes';
$extraCss          = '<link rel="stylesheet" href="' . APP_URL . '/assets/css/admin.css">';
$adminPageTitle    = 'Disputes';
$adminPageSubtitle = 'Resolve buyer-opened disputes and release or refund held funds.';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="a-shell" id="aShell">
<?php require __DIR__ . '/../../includes/admin_sidebar.php'; ?>
<div class="a-main">
<?php require __DIR__ . '/../../includes/admin_topbar.php'; ?>
<div class="a-content">

<div class="a-hero">
    <div>
        <h2>Disputes</h2>
        <p class="a-hero__sub">Resolve buyer-opened disputes and release or refund held funds.</p>
    </div>
</div>

<div class="a-kpis">
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label">Open</span><span class="a-kpi__icon a-kpi__icon--red"><?php echo admin_icon('reports'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($counts['open']); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint">Needs attention</span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label">Under review</span><span class="a-kpi__icon a-kpi__icon--amber"><?php echo admin_icon('clock'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($counts['under_review']); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint">Being investigated</span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label">Resolved</span><span class="a-kpi__icon a-kpi__icon--green"><?php echo admin_icon('check-circle'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($counts['resolved']); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint">Funds settled</span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label">Closed</span><span class="a-kpi__icon"><?php echo admin_icon('close-x'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($counts['closed']); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint">No action taken</span></div>
    </div>
</div>

<section class="a-panel">
    <div class="a-panel__head">
        <div>
            <h3>All Disputes</h3>
            <p><?php echo number_format($total); ?> dispute<?php echo $total === 1 ? '' : 's'; ?></p>
        </div>
        <div class="a-panel__tools">
            <div class="a-filters">
                <a class="a-chip <?php echo $statusFilter === '' ? 'is-active' : ''; ?>" href="<?php echo $baseUrl; ?>">All <b><?php echo number_format($totalAll); ?></b></a>
                <a class="a-chip <?php echo $statusFilter === 'open' ? 'is-active' : ''; ?>" href="<?php echo $baseUrl; ?>?status=open">Open <b><?php echo number_format($counts['open']); ?></b></a>
                <a class="a-chip <?php echo $statusFilter === 'resolved' ? 'is-active' : ''; ?>" href="<?php echo $baseUrl; ?>?status=resolved">Resolved <b><?php echo number_format($counts['resolved']); ?></b></a>
            </div>
        </div>
    </div>

    <?php if (!$disputes): ?>
        <?php echo admin_empty_state('check-circle', 'No disputes', 'Nothing here yet — disputed orders will show up in this list.'); ?>
    <?php else: ?>
    <div class="a-tablewrap">
        <table class="a-table">
            <thead><tr>
                <th>Dispute</th>
                <th>Order</th>
                <th>Buyer / Seller</th>
                <th>Reason</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Opened</th>
                <th>Resolve</th>
            </tr></thead>
            <tbody>
            <?php foreach ($disputes as $d): ?>
                <tr>
                    <td data-label="Dispute" class="cell-primary"><?php echo e($d['dispute_number']); ?></td>
                    <td data-label="Order" class="cell-mute">
                        <a href="<?php echo APP_URL; ?>/pages/orders.php?id=<?php echo (int) $d['order_id']; ?>"><?php echo e($d['order_number']); ?></a>
                    </td>
                    <td data-label="Buyer / Seller" class="cell-mute"><?php echo e($d['buyer_name']); ?> / <?php echo e($d['seller_name']); ?></td>
                    <td data-label="Reason" class="cell-mute" style="max-width:220px;"><?php echo e($d['reason']); ?><br><span style="font-size:12px;color:var(--a-mute);"><?php echo e(excerpt($d['description'], 80)); ?></span></td>
                    <td data-label="Amount" class="cell-mute"><?php echo number_format((float)$d['grand_total'], 0); ?> RWF</td>
                    <td data-label="Status">
                        <span class="a-status a-status--<?php echo $d['status'] === 'resolved' ? 'resolved' : 'open'; ?>"><?php echo e($d['status']); ?></span>
                        <?php if ($d['status'] === 'resolved'): ?><br><span style="font-size:12px;color:var(--a-mute);"><?php echo e($d['resolution']); ?></span><?php endif; ?>
                    </td>
                    <td data-label="Opened" class="cell-mute"><?php echo e(time_ago($d['created_at'])); ?></td>
                    <td data-label="Resolve">
                        <?php if ($d['status'] !== 'resolved' && $d['status'] !== 'closed' && $d['payment_status'] === 'escrow_held'): ?>
                            <div class="a-rowactions" style="flex-direction:column;align-items:stretch;gap:6px;">
                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php"
                                      data-confirm="Refund the full amount to the buyer?" data-confirm-title="Refund buyer">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="dispute_resolve">
                                    <input type="hidden" name="dispute_id" value="<?php echo (int) $d['id']; ?>">
                                    <input type="hidden" name="resolution" value="refund_full">
                                    <button class="a-btn a-btn--sm" type="submit">Refund buyer</button>
                                </form>
                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php"
                                      data-confirm="Release the full amount to the seller?" data-confirm-title="Release to seller">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="dispute_resolve">
                                    <input type="hidden" name="dispute_id" value="<?php echo (int) $d['id']; ?>">
                                    <input type="hidden" name="resolution" value="release_to_seller">
                                    <button class="a-btn a-btn--sm" type="submit">Release to seller</button>
                                </form>
                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php" style="display:flex;gap:4px;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="dispute_resolve">
                                    <input type="hidden" name="dispute_id" value="<?php echo (int) $d['id']; ?>">
                                    <input type="hidden" name="resolution" value="split">
                                    <input type="number" name="split_buyer_amount" min="0" max="<?php echo (float)$d['grand_total']; ?>" step="1" placeholder="To buyer" style="width:90px;padding:5px 8px;border:1px solid var(--a-line);border-radius:6px;font-size:12px;">
                                    <button class="a-btn a-btn--sm a-btn--primary" type="submit">Split</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <span class="cell-mute">—</span>
                        <?php endif; ?>
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
