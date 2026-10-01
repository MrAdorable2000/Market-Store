<?php
/**
 * pages/admin/categories.php
 * --------------------------------------------------------------------
 * IsokoRyacu category management (Phase 5).
 *
 * Features (all real, all working):
 *   - Grid of marketplace categories with real listing counts
 *   - Show / hide a category on the public site (is_active toggle)
 *   - Add a new category (name + slug auto-generated, CSRF-guarded)
 *   - Link to the public category page
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_icons.php';
require_once __DIR__ . '/../../includes/admin_ui.php';
require_role('admin');

$pdo = db();

$cats = $pdo->query("SELECT c.id, c.name, c.name_key, c.slug, c.display_order, c.is_active, c.icon,
        (SELECT COUNT(*) FROM listings l WHERE l.category_id = c.id) AS count,
        (SELECT COUNT(*) FROM listings l WHERE l.category_id = c.id AND l.status = 'active') AS active_count
    FROM categories c
    WHERE c.parent_id IS NULL
    ORDER BY c.display_order, c.name")->fetchAll();

$totalCats     = count($cats);
$activeCats    = count(array_filter($cats, static fn ($c) => (int) $c['is_active'] === 1));
$totalMappings = (int) $pdo->query("SELECT COUNT(*) FROM listings WHERE category_id IS NOT NULL")->fetchColumn();

/* ---------- Page setup ---------- */
$pageTitle         = t('admin.nav_categories');
$activePage        = 'admin';
$adminActivePage   = 'categories';
$extraCss          = '<link rel="stylesheet" href="' . asset_url('assets/css/admin.css') . '">';
$adminPageTitle    = t('admin.nav_categories');
$adminPageSubtitle = t('admin.categories_sub');
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="a-shell" id="aShell">
<?php require __DIR__ . '/../../includes/admin_sidebar.php'; ?>
<div class="a-main">
<?php require __DIR__ . '/../../includes/admin_topbar.php'; ?>
<div class="a-content">

<div class="a-hero">
    <div>
        <h2><?php echo e(t('admin.nav_categories')); ?></h2>
        <p class="a-hero__sub"><?php echo e(t('admin.categories_sub')); ?></p>
    </div>
    <div class="a-hero__actions">
        <button type="button" class="a-btn a-btn--accent" onclick="document.getElementById('aCatModal').classList.add('is-open')">
            <?php echo admin_icon('plus'); ?><span><?php echo e(t('admin.new_category')); ?></span>
        </button>
    </div>
</div>

<div class="a-kpis" style="grid-template-columns:repeat(3,minmax(0,1fr));">
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label"><?php echo e(t('admin.categories_total')); ?></span><span class="a-kpi__icon"><?php echo admin_icon('categories'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($totalCats); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo e(t('admin.categories_total_sub')); ?></span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label"><?php echo e(t('admin.categories_visible')); ?></span><span class="a-kpi__icon a-kpi__icon--green"><?php echo admin_icon('eye'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($activeCats); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo e(t('admin.categories_hidden')); ?>: <?php echo number_format($totalCats - $activeCats); ?></span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label"><?php echo e(t('admin.categories_mapped')); ?></span><span class="a-kpi__icon a-kpi__icon--orange"><?php echo admin_icon('listings'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($totalMappings); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo e(t('admin.categories_mapped_sub')); ?></span></div>
    </div>
</div>

<section class="a-panel">
    <div class="a-panel__head">
        <div>
            <h3><?php echo e(t('admin.nav_categories')); ?></h3>
            <p><?php echo number_format($totalCats); ?> <?php echo e(t('nav.categories')); ?></p>
        </div>
    </div>

    <?php if (!$cats): ?>
        <?php echo admin_empty_state('categories', t('admin.empty_no_categories'), t('admin.empty_no_categories_sub')); ?>
    <?php else: ?>
    <div class="a-tablewrap">
        <table class="a-table">
            <thead><tr>
                <th><?php echo e(t('admin.col_name')); ?></th>
                <th><?php echo e(t('admin.col_slug')); ?></th>
                <th><?php echo e(t('admin.col_listings')); ?></th>
                <th><?php echo e(t('admin.col_active')); ?></th>
                <th><?php echo e(t('admin.col_actions')); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($cats as $c): ?>
                <tr>
                    <td data-label="<?php echo e(t('admin.col_name')); ?>" class="cell-primary"><?php echo e(t_category($c['name_key'] ?? null, $c['name'])); ?></td>
                    <td data-label="<?php echo e(t('admin.col_slug')); ?>" class="cell-mute"><code style="font-size:11px; background:var(--a-card-soft); padding:3px 8px; border-radius:7px;"><?php echo e($c['slug']); ?></code></td>
                    <td data-label="<?php echo e(t('admin.col_listings')); ?>" class="cell-num"><?php echo number_format((int) $c['count']); ?> <span class="cell-mute">(<?php echo (int) $c['active_count']; ?> <?php echo e(t('admin.status_active')); ?>)</span></td>
                    <td data-label="<?php echo e(t('admin.col_active')); ?>">
                        <?php if ((int) $c['is_active'] === 1): ?>
                            <span class="a-status a-status--active"><?php echo e(t('admin.category_visible')); ?></span>
                        <?php else: ?>
                            <span class="a-status a-status--neutral"><?php echo e(t('admin.category_hidden')); ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?php echo e(t('admin.col_actions')); ?>">
                        <div class="a-rowactions">
                            <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php" style="display:contents;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="category_toggle">
                                <input type="hidden" name="category_id" value="<?php echo (int) $c['id']; ?>">
                                <button class="a-btn a-btn--sm" type="submit">
                                    <?php echo (int) $c['is_active'] === 1 ? e(t('admin.hide_action')) : e(t('admin.show_action')); ?>
                                </button>
                            </form>
                            <a class="a-btn a-btn--sm" href="<?php echo APP_URL; ?>/pages/category.php?slug=<?php echo e($c['slug']); ?>"><?php echo e(t('buttons.view')); ?></a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

</div><!-- /a-content -->

<!-- ============ Add category modal ============ -->
<div class="a-modal" id="aCatModal" role="dialog" aria-modal="true">
    <div class="a-modal__backdrop" onclick="document.getElementById('aCatModal').classList.remove('is-open')"></div>
    <div class="a-modal__box" style="width:min(440px,100%);">
        <div class="a-modal__head">
            <span class="a-modal__icon a-modal__icon--teal"><?php echo admin_icon('categories'); ?></span>
            <div>
                <div class="a-modal__title"><?php echo e(t('admin.new_category')); ?></div>
                <p class="a-modal__sub"><?php echo e(t('admin.new_category_sub')); ?></p>
            </div>
            <button type="button" class="a-modal__close" onclick="document.getElementById('aCatModal').classList.remove('is-open')" aria-label="<?php echo e(t('common.dismiss')); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 6 6 18M6 6l12 12" stroke-linecap="round"/></svg>
            </button>
        </div>
        <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php" class="a-modal__body">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="category_add">
            <div class="a-form">
                <div class="a-field">
                    <label for="catName"><?php echo e(t('admin.col_name')); ?></label>
                    <input class="a-input" id="catName" name="name" required minlength="2" maxlength="120" placeholder="<?php echo e(t('admin.category_name_placeholder')); ?>">
                    <p class="a-help"><?php echo e(t('admin.category_name_help')); ?></p>
                </div>
            </div>
            <div class="a-modal__foot" style="padding:18px 0 0;">
                <button type="button" class="a-btn" onclick="document.getElementById('aCatModal').classList.remove('is-open')"><?php echo e(t('admin.cancel')); ?></button>
                <button type="submit" class="a-btn a-btn--primary"><?php echo e(t('admin.create_category')); ?></button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>
