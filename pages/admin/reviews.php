<?php
/**
 * pages/admin/reviews.php
 * --------------------------------------------------------------------
 * IsokoRyacu review moderation (Phase 5) — NEW PAGE.
 *
 * The reviews table already existed (public seller profiles show them).
 * This page gives administrators a real moderation console:
 *
 *   - KPI strip: total reviews / average rating / 5-star / 1-star
 *   - Table: reviewer, seller, rating stars, comment, date, action
 *   - Delete review (modal confirm, CSRF-guarded, logged)
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_icons.php';
require_once __DIR__ . '/../../includes/admin_ui.php';
require_role('admin');

$pdo = db();

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;

/* KPIs (real) */
$reviewCount = (int) $pdo->query("SELECT COUNT(*) FROM reviews")->fetchColumn();
$avgRating   = (float) $pdo->query("SELECT COALESCE(AVG(rating),0) FROM reviews")->fetchColumn();
$fiveStar    = (int) $pdo->query("SELECT COUNT(*) FROM reviews WHERE rating = 5")->fetchColumn();
$oneStar     = (int) $pdo->query("SELECT COUNT(*) FROM reviews WHERE rating = 1")->fetchColumn();

/* Rows */
$total = $reviewCount;
$pages = (int) ceil($total / $perPage);
if ($page > $pages && $pages > 0) $page = $pages;
$offset = ($page - 1) * $perPage;

$reviews = $pdo->query("SELECT rv.id, rv.rating, rv.comment, rv.created_at,
        reviewer.full_name AS reviewer_name,
        seller.full_name AS seller_name, seller.id AS seller_id,
        l.title AS listing_title, l.id AS listing_id
    FROM reviews rv
    INNER JOIN users reviewer ON reviewer.id = rv.reviewer_id
    INNER JOIN users seller ON seller.id = rv.seller_id
    LEFT JOIN listings l ON l.id = rv.listing_id
    ORDER BY rv.created_at DESC
    LIMIT $perPage OFFSET $offset")->fetchAll();

$baseUrl = APP_URL . '/pages/admin/reviews.php';

/** Star row renderer. */
function admin_stars(int $rating): string
{
    $out = '<span class="a-stars">';
    for ($i = 1; $i <= 5; $i++) {
        $out .= '<svg viewBox="0 0 24 24" fill="' . ($i <= $rating ? 'currentColor' : 'none') . '" stroke="currentColor" stroke-width="1.6"><path d="M12 3.5 14.3 8l5 .7-3.6 3.5.9 5-4.6-2.4-4.6 2.4.9-5L4.7 8.7l5-.7z"/></svg>';
    }
    return $out . '</span>';
}

/* ---------- Page setup ---------- */
$pageTitle         = t('admin.nav_reviews');
$activePage        = 'admin';
$adminActivePage   = 'reviews';
$extraCss          = '<link rel="stylesheet" href="' . APP_URL . '/assets/css/admin.css">';
$adminPageTitle    = t('admin.nav_reviews');
$adminPageSubtitle = t('admin.reviews_sub');
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="a-shell" id="aShell">
<?php require __DIR__ . '/../../includes/admin_sidebar.php'; ?>
<div class="a-main">
<?php require __DIR__ . '/../../includes/admin_topbar.php'; ?>
<div class="a-content">

<div class="a-hero">
    <div>
        <h2><?php echo e(t('admin.nav_reviews')); ?></h2>
        <p class="a-hero__sub"><?php echo e(t('admin.reviews_sub')); ?></p>
    </div>
</div>

<div class="a-kpis">
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label"><?php echo e(t('admin.stat_reviews')); ?></span><span class="a-kpi__icon a-kpi__icon--orange"><?php echo admin_icon('reviews'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($reviewCount); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo e(t('admin.reviews_total_sub')); ?></span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label"><?php echo e(t('admin.avg_rating')); ?></span><span class="a-kpi__icon a-kpi__icon--green"><?php echo admin_icon('star'); ?></span></div>
        <div class="a-kpi__value"><?php echo $reviewCount ? number_format($avgRating, 1) : '—'; ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo e(t('admin.out_of_five')); ?></span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label"><?php echo e(t('admin.five_star')); ?></span><span class="a-kpi__icon"><?php echo admin_icon('check'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($fiveStar); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo e(t('admin.five_star_sub')); ?></span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label"><?php echo e(t('admin.one_star')); ?></span><span class="a-kpi__icon a-kpi__icon--red"><?php echo admin_icon('reports'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($oneStar); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo e(t('admin.one_star_sub')); ?></span></div>
    </div>
</div>

<section class="a-panel">
    <div class="a-panel__head">
        <div>
            <h3><?php echo e(t('admin.review_moderation')); ?></h3>
            <p><?php echo number_format($total); ?> <?php echo e(t('admin.reviews_count')); ?></p>
        </div>
    </div>

    <?php if (!$reviews): ?>
        <?php echo admin_empty_state('reviews', t('admin.empty_no_reviews'), t('admin.empty_no_reviews_sub')); ?>
    <?php else: ?>
    <div class="a-tablewrap">
        <table class="a-table">
            <thead><tr>
                <th><?php echo e(t('admin.col_reviewer')); ?></th>
                <th><?php echo e(t('admin.col_seller')); ?></th>
                <th><?php echo e(t('admin.col_rating')); ?></th>
                <th><?php echo e(t('admin.col_comment')); ?></th>
                <th><?php echo e(t('common.listing')); ?></th>
                <th><?php echo e(t('admin.col_posted')); ?></th>
                <th><?php echo e(t('admin.col_actions')); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($reviews as $r): ?>
                <tr>
                    <td data-label="<?php echo e(t('admin.col_reviewer')); ?>">
                        <span class="a-table__user">
                            <span class="a-avatar a-avatar--sm"><?php echo e(strtoupper(substr($r['reviewer_name'], 0, 1))); ?></span>
                            <span class="a-table__listing-title"><?php echo e($r['reviewer_name']); ?></span>
                        </span>
                    </td>
                    <td data-label="<?php echo e(t('admin.col_seller')); ?>">
                        <a href="<?php echo APP_URL; ?>/pages/seller-profile.php?id=<?php echo (int) $r['seller_id']; ?>"><?php echo e($r['seller_name']); ?></a>
                    </td>
                    <td data-label="<?php echo e(t('admin.col_rating')); ?>"><?php echo admin_stars((int) $r['rating']); ?></td>
                    <td data-label="<?php echo e(t('admin.col_comment')); ?>" class="cell-mute" style="max-width:300px;"><?php echo e($r['comment'] ? excerpt($r['comment'], 100) : '—'); ?></td>
                    <td data-label="<?php echo e(t('common.listing')); ?>">
                        <?php if ($r['listing_id']): ?>
                            <a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int) $r['listing_id']; ?>"><?php echo e($r['listing_title']); ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td data-label="<?php echo e(t('admin.col_posted')); ?>" class="cell-mute"><?php echo e(time_ago($r['created_at'])); ?></td>
                    <td data-label="<?php echo e(t('admin.col_actions')); ?>">
                        <div class="a-rowactions">
                            <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php" style="display:contents;"
                                  data-confirm="<?php echo e(t('admin.review_delete_confirm', ['name' => $r['reviewer_name']])); ?>"
                                  data-confirm-title="<?php echo e(t('admin.review_delete_title')); ?>"
                                  data-confirm-label="<?php echo e(t('buttons.delete')); ?>">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="review_delete">
                                <input type="hidden" name="review_id" value="<?php echo (int) $r['id']; ?>">
                                <button class="a-btn a-btn--danger a-btn--sm" type="submit" title="<?php echo e(t('buttons.delete')); ?>"><?php echo admin_icon('trash'); ?></button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php echo admin_pagination($total, $perPage, $page, $baseUrl); ?>
    <?php endif; ?>
</section>

</div><!-- /a-content -->
<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>
