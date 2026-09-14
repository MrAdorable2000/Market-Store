<?php
/**
 * pages/admin/listings.php
 * --------------------------------------------------------------------
 * IsokoRyacu listing management (Phase 5).
 *
 * Features (all real, all working):
 *   - Live search (title / seller / category) — the topbar search lands here
 *   - Status filter chips with real counts (all / pending / active / rejected)
 *   - Type filter (sell / rent)
 *   - Pagination (15 per page)
 *   - Real listing thumbnails with image fallback
 *   - Approve / reject (pending), feature toggle, delete with modal confirm
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_icons.php';
require_once __DIR__ . '/../../includes/admin_ui.php';
require_once __DIR__ . '/../../includes/smart_features.php';
listings_verified_ready();
require_role('admin');

$pdo = db();

/* ---------- Filters (validated against whitelists) ---------- */
$statusFilter = (string) ($_GET['status'] ?? '');
if (!in_array($statusFilter, ['pending', 'active', 'rejected', 'expired', 'draft'], true)) $statusFilter = '';
$typeFilter   = (string) ($_GET['type'] ?? '');
if (!in_array($typeFilter, ['sell', 'rent'], true)) $typeFilter = '';
$q            = trim((string) ($_GET['q'] ?? ''));
$page         = max(1, (int) ($_GET['page'] ?? 1));
$perPage      = 15;

/* ---------- Issue flags (health recommendations link here) ---------- */
$flagFilter = (string) ($_GET['flag'] ?? '');
if (!in_array($flagFilter, ['no_image', 'low_quality', 'verified'], true)) $flagFilter = '';

/* ---------- Counts for the chips (one query) ---------- */
$counts = ['all' => 0, 'pending' => 0, 'active' => 0, 'rejected' => 0];
foreach ($pdo->query("SELECT status, COUNT(*) n FROM listings GROUP BY status") as $row) {
    $counts['all'] += (int) $row['n'];
    if (isset($counts[$row['status']])) $counts[$row['status']] = (int) $row['n'];
}

/* ---------- WHERE assembly (prepared, no injection) ---------- */
$where  = [];
$params = [];
if ($statusFilter !== '') { $where[] = 'l.status = ?'; $params[] = $statusFilter; }
if ($typeFilter !== '')   { $where[] = 'l.listing_type = ?'; $params[] = $typeFilter; }
if ($q !== '') {
    $where[] = '(l.title LIKE ? OR u.full_name LIKE ? OR c.name LIKE ? OR l.location LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($flagFilter === 'no_image') {
    $where[] = 'NOT EXISTS (SELECT 1 FROM listing_images li WHERE li.listing_id = l.id)';
} elseif ($flagFilter === 'low_quality') {
    $where[] = '(l.description IS NULL OR CHAR_LENGTH(l.description) < 60 OR l.location IS NULL OR l.location = \'\'
                 OR NOT EXISTS (SELECT 1 FROM listing_images li WHERE li.listing_id = l.id))';
} elseif ($flagFilter === 'verified') {
    $where[] = 'l.is_verified = 1';
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

/* ---------- Total for pagination ---------- */
$stmt = $pdo->prepare("SELECT COUNT(*)
    FROM listings l
    INNER JOIN users u ON u.id = l.seller_id
    INNER JOIN categories c ON c.id = l.category_id" . $whereSql);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$pages = (int) ceil($total / $perPage);
if ($page > $pages && $pages > 0) $page = $pages;
$offset = ($page - 1) * $perPage;

/* ---------- Rows (with everything the Quality Score needs) ---------- */
$stmt = $pdo->prepare("SELECT l.id, l.title, l.slug, l.price, l.currency, l.listing_type,
        l.availability, l.status, l.is_featured, l.is_verified, l.description, l.location,
        l.subcategory_id, l.price_per_month, l.price_per_week, l.price_per_day,
        l.created_at, l.views_count, l.favorites_count,
        u.full_name AS seller_name, u.is_verified AS seller_verified, u.phone AS seller_phone,
        c.name AS category_name, c.name_key AS category_name_key,
        (SELECT COUNT(*) FROM listing_images li WHERE li.listing_id = l.id) AS images_count,
        (SELECT COUNT(*) FROM listing_attributes la WHERE la.listing_id = l.id) AS attrs_count,
        (SELECT image_path FROM listing_images li WHERE li.listing_id = l.id AND li.is_primary = 1 LIMIT 1) AS image
    FROM listings l
    INNER JOIN users u ON u.id = l.seller_id
    INNER JOIN categories c ON c.id = l.category_id" . $whereSql . "
    ORDER BY l.created_at DESC
    LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$listings = $stmt->fetchAll();

// Pre-compute real quality scores for this page of rows
$qualities = [];
foreach ($listings as $r) {
    $qualities[(int) $r['id']] = listing_quality_score($r);
}

$keep = array_filter([
    'q' => $q !== '' ? $q : null,
    'status' => $statusFilter ?: null,
    'type' => $typeFilter ?: null,
    'flag' => $flagFilter ?: null,
]);
$baseUrl = APP_URL . '/pages/admin/listings.php';
$qs = static function (array $extra = []) use ($baseUrl, $keep): string {
    $params = array_merge($keep, $extra);
    $params = array_filter($params, static fn ($v) => $v !== null && $v !== '');
    return $baseUrl . ($params ? '?' . http_build_query($params) : '');
};

/* ---------- Page setup ---------- */
$pageTitle         = t('admin.nav_listings');
$activePage        = 'admin';
$adminActivePage   = 'listings';
$extraCss          = '<link rel="stylesheet" href="' . APP_URL . '/assets/css/admin.css">';
$adminPageTitle    = t('admin.nav_listings');
$adminPageSubtitle = t('admin.listings_sub');
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="a-shell" id="aShell">
<?php require __DIR__ . '/../../includes/admin_sidebar.php'; ?>
<div class="a-main">
<?php require __DIR__ . '/../../includes/admin_topbar.php'; ?>
<div class="a-content">

<!-- Hero -->
<div class="a-hero">
    <div>
        <h2><?php echo e(t('admin.nav_listings')); ?></h2>
        <p class="a-hero__sub"><?php echo e(t('admin.listings_sub')); ?></p>
    </div>
    <div class="a-hero__actions">
        <a class="a-btn a-btn--accent" href="<?php echo APP_URL; ?>/pages/sell.php"><?php echo admin_icon('plus'); ?><span><?php echo e(t('admin.quick_add_listing')); ?></span></a>
    </div>
</div>

<!-- Search + filters -->
<section class="a-panel" style="padding:15px 18px;">
    <div class="a-panel__tools" style="width:100%;">
        <form class="a-search" style="max-width:380px; flex:1 1 260px; margin:0;" method="get" action="<?php echo $baseUrl; ?>" role="search">
            <?php echo admin_icon('search'); ?>
            <?php foreach ($keep as $k => $v): if ($k === 'q') continue; ?>
                <input type="hidden" name="<?php echo e($k); ?>" value="<?php echo e($v); ?>">
            <?php endforeach; ?>
            <input type="search" name="q" value="<?php echo e($q); ?>" placeholder="<?php echo e(t('admin.search_listings_placeholder')); ?>">
        </form>
        <div class="a-filters">
            <a class="a-chip <?php echo $statusFilter === '' && $flagFilter === '' ? 'is-active' : ''; ?>" href="<?php echo e($qs(['status' => null, 'flag' => null, 'page' => null])); ?>"><?php echo e(t('search.all')); ?> <b><?php echo number_format($counts['all']); ?></b></a>
            <a class="a-chip <?php echo $statusFilter === 'pending' ? 'is-active' : ''; ?>" href="<?php echo e($qs(['status' => 'pending', 'flag' => null, 'page' => null])); ?>"><?php echo e(t('admin.stat_pending_review')); ?> <b><?php echo number_format($counts['pending']); ?></b></a>
            <a class="a-chip <?php echo $statusFilter === 'active' ? 'is-active' : ''; ?>" href="<?php echo e($qs(['status' => 'active', 'flag' => null, 'page' => null])); ?>"><?php echo e(t('admin.stat_active_listings')); ?> <b><?php echo number_format($counts['active']); ?></b></a>
            <a class="a-chip <?php echo $statusFilter === 'rejected' ? 'is-active' : ''; ?>" href="<?php echo e($qs(['status' => 'rejected', 'flag' => null, 'page' => null])); ?>"><?php echo e(t('seller.rejected')); ?> <b><?php echo number_format($counts['rejected']); ?></b></a>
            <span style="width:1px; height:22px; background:var(--a-line);"></span>
            <a class="a-chip <?php echo $typeFilter === 'sell' ? 'is-active' : ''; ?>" href="<?php echo e($qs(['type' => $typeFilter === 'sell' ? null : 'sell', 'page' => null])); ?>"><?php echo e(t('listings.for_sale')); ?></a>
            <a class="a-chip <?php echo $typeFilter === 'rent' ? 'is-active' : ''; ?>" href="<?php echo e($qs(['type' => $typeFilter === 'rent' ? null : 'rent', 'page' => null])); ?>"><?php echo e(t('listings.for_rent')); ?></a>
            <span style="width:1px; height:22px; background:var(--a-line);"></span>
            <a class="a-chip <?php echo $flagFilter === 'no_image' ? 'is-active' : ''; ?>" href="<?php echo e($qs(['flag' => $flagFilter === 'no_image' ? null : 'no_image', 'page' => null])); ?>" title="<?php echo e(t('admin.flag_no_image_hint')); ?>"><?php echo e(t('admin.flag_no_image')); ?></a>
            <a class="a-chip <?php echo $flagFilter === 'low_quality' ? 'is-active' : ''; ?>" href="<?php echo e($qs(['flag' => $flagFilter === 'low_quality' ? null : 'low_quality', 'page' => null])); ?>" title="<?php echo e(t('admin.flag_low_quality_hint')); ?>"><?php echo e(t('admin.flag_low_quality')); ?></a>
            <a class="a-chip <?php echo $flagFilter === 'verified' ? 'is-active' : ''; ?>" href="<?php echo e($qs(['flag' => $flagFilter === 'verified' ? null : 'verified', 'page' => null])); ?>"><?php echo e(t('trust.verified')); ?></a>
        </div>
    </div>
</section>

<!-- Table -->
<section class="a-panel">
    <div class="a-panel__head">
        <div>
            <h3><?php echo e(t('admin.panel_recent_listings')); ?></h3>
            <p><?php echo number_format($total); ?> <?php echo e(t('admin.listings_count')); ?><?php echo $q !== '' ? ' · "' . e($q) . '"' : ''; ?></p>
        </div>
        <?php if ($q !== '' || $statusFilter || $typeFilter): ?>
            <a class="a-panel__link" href="<?php echo $baseUrl; ?>"><?php echo e(t('admin.clear_filters')); ?></a>
        <?php endif; ?>
    </div>

    <?php if (!$listings): ?>
        <?php echo admin_empty_state('image', $q !== '' ? t('common.no_results') : t('admin.empty_no_listings'), t('admin.empty_no_listings_sub')); ?>
    <?php else: ?>
    <div class="a-tablewrap">
        <table class="a-table">
            <thead><tr>
                <th><?php echo e(t('admin.col_title')); ?></th>
                <th><?php echo e(t('admin.col_seller')); ?></th>
                <th><?php echo e(t('admin.col_category')); ?></th>
                <th><?php echo e(t('admin.col_type')); ?></th>
                <th><?php echo e(t('admin.col_price')); ?></th>
                <th><?php echo e(t('quality.listing_quality')); ?></th>
                <th><?php echo e(t('admin.col_status')); ?></th>
                <th><?php echo e(t('listings.detail.views')); ?></th>
                <th><?php echo e(t('admin.col_posted')); ?></th>
                <th><?php echo e(t('admin.col_actions')); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($listings as $r): ?>
                <tr>
                    <td data-label="<?php echo e(t('admin.col_title')); ?>">
                        <span class="a-table__listing">
                            <img class="a-table__thumb" loading="lazy" src="<?php echo e(image_or_default($r['image'] ?? null)); ?>" alt="">
                            <span style="min-width:0;">
                                <span class="a-table__listing-title"><a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int) $r['id']; ?>"><?php echo e($r['title']); ?></a></span>
                                <span class="a-table__listing-sub"><?php echo $r['location'] ? e($r['location']) . ' · ' : ''; ?><?php echo (int) $r['favorites_count']; ?> ★ <?php echo e(t('admin.stat_favorites')); ?></span>
                            </span>
                        </span>
                    </td>
                    <td data-label="<?php echo e(t('admin.col_seller')); ?>" class="cell-mute"><?php echo e($r['seller_name']); ?><?php echo $r['seller_verified'] ? ' ✓' : ''; ?></td>
                    <td data-label="<?php echo e(t('admin.col_category')); ?>" class="cell-mute"><?php echo e(t_category($r['category_name_key'] ?? null, $r['category_name'])); ?></td>
                    <td data-label="<?php echo e(t('admin.col_type')); ?>">
                        <span class="a-status a-status--<?php echo $r['listing_type'] === 'rent' ? 'info' : 'sell'; ?>"><?php echo $r['listing_type'] === 'rent' ? e(t('listings.for_rent')) : e(t('listings.for_sale')); ?></span>
                    </td>
                    <td data-label="<?php echo e(t('admin.col_price')); ?>" class="cell-num cell-primary"><?php echo e(format_price($r['price'], $r['currency'])); ?></td>
                    <td data-label="<?php echo e(t('quality.listing_quality')); ?>">
                        <?php $qq = $qualities[(int) $r['id']]; ?>
                        <?php echo quality_chip_html($qq['score'], $qq['verdict']); ?>
                    </td>
                    <td data-label="<?php echo e(t('admin.col_status')); ?>">
                        <span class="a-status a-status--<?php echo e($r['status']); ?>"><?php echo e(t('admin.status_' . $r['status'])); ?></span>
                        <?php if ((int) $r['is_featured'] === 1): ?><span class="a-status a-status--featured"><?php echo e(t('admin.featured')); ?></span><?php endif; ?>
                        <?php if ((int) $r['is_verified'] === 1): ?><span class="a-status a-status--verified"><?php echo admin_icon('check'); ?><?php echo e(t('trust.verified')); ?></span><?php endif; ?>
                    </td>
                    <td data-label="<?php echo e(t('listings.detail.views')); ?>" class="cell-num"><?php echo number_format((int) $r['views_count']); ?></td>
                    <td data-label="<?php echo e(t('admin.col_posted')); ?>" class="cell-mute"><?php echo e(time_ago($r['created_at'])); ?></td>
                    <td data-label="<?php echo e(t('admin.col_actions')); ?>">
                        <div class="a-rowactions">
                            <?php if ($r['status'] === 'pending'): ?>
                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php" style="display:contents;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="listing_approve">
                                    <input type="hidden" name="listing_id" value="<?php echo (int) $r['id']; ?>">
                                    <button class="a-btn a-btn--primary a-btn--sm" type="submit" title="<?php echo e(t('buttons.approve')); ?>"><?php echo admin_icon('check'); ?></button>
                                </form>
                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php" style="display:contents;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="listing_reject">
                                    <input type="hidden" name="listing_id" value="<?php echo (int) $r['id']; ?>">
                                    <button class="a-btn a-btn--sm" type="submit" title="<?php echo e(t('buttons.reject')); ?>"><?php echo admin_icon('close-x'); ?></button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php" style="display:contents;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="<?php echo (int) $r['is_featured'] === 1 ? 'listing_unfeature' : 'listing_feature'; ?>">
                                    <input type="hidden" name="listing_id" value="<?php echo (int) $r['id']; ?>">
                                    <button class="a-btn a-btn--sm" type="submit" title="<?php echo (int) $r['is_featured'] === 1 ? e(t('admin.unfeature')) : e(t('admin.feature')); ?>" style="<?php echo (int) $r['is_featured'] === 1 ? 'color:var(--a-orange-deep); border-color:var(--a-orange);' : ''; ?>"><?php echo admin_icon('star'); ?></button>
                                </form>
                            <?php endif; ?>
                            <!-- Trust: verify / unverify listing (Phase 8) -->
                            <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php" style="display:contents;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="<?php echo (int) $r['is_verified'] === 1 ? 'listing_unverify' : 'listing_verify'; ?>">
                                <input type="hidden" name="listing_id" value="<?php echo (int) $r['id']; ?>">
                                <button class="a-btn a-btn--sm" type="submit" title="<?php echo (int) $r['is_verified'] === 1 ? e(t('trust.unverify_listing')) : e(t('trust.verify_listing')); ?>" style="<?php echo (int) $r['is_verified'] === 1 ? 'color:var(--a-teal-deep); border-color:var(--a-teal); background:var(--a-teal-soft);' : ''; ?>"><?php echo admin_icon('user-check'); ?></button>
                            </form>
                            <a class="a-btn a-btn--sm" href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int) $r['id']; ?>" title="<?php echo e(t('buttons.view')); ?>"><?php echo admin_icon('eye'); ?></a>
                            <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php" style="display:contents;"
                                  data-confirm="<?php echo e(t('admin.delete_listing_confirm', ['title' => $r['title']])); ?>"
                                  data-confirm-title="<?php echo e(t('admin.delete_listing_title')); ?>"
                                  data-confirm-label="<?php echo e(t('buttons.delete')); ?>">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="listing_delete">
                                <input type="hidden" name="listing_id" value="<?php echo (int) $r['id']; ?>">
                                <button class="a-btn a-btn--danger a-btn--sm" type="submit" title="<?php echo e(t('buttons.delete')); ?>"><?php echo admin_icon('trash'); ?></button>
                            </form>
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
