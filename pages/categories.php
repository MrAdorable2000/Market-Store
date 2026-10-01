<?php
/**
 * pages/categories.php — grid of all top-level categories (Phase 2: translated)
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

$catStmt = db()->query(
    'SELECT c.id, c.name, c.name_key, c.slug, c.description,
            (SELECT COUNT(*) FROM listings l WHERE l.category_id = c.id AND l.status = "active") AS count
     FROM categories c
     WHERE c.parent_id IS NULL AND c.is_active = 1
     ORDER BY c.display_order'
);
$cats = $catStmt->fetchAll();

$pageTitle = t('nav.categories');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container section">
    <div style="margin:14px 0 4px;">
        <?php echo back_button(APP_URL . '/', t('buttons.back'), 'solid'); ?>
    </div>
    <div class="section__head">
        <div>
            <h1 class="section__title"><?php echo e(t('nav.categories')); ?></h1>
            <p class="section__sub"><?php echo count($cats); ?> <?php echo e(t('nav.categories')); ?>.</p>
        </div>
    </div>
    <div class="grid grid--3 grid--auto">
        <?php foreach ($cats as $cat): ?>
            <a class="card card--hover" href="<?php echo APP_URL; ?>/pages/category.php?slug=<?php echo e($cat['slug']); ?>" style="display:flex;flex-direction:column;gap:6px;">
                <h3 style="margin:0;"><?php echo e(t_category($cat['name_key'] ?? null, $cat['name'])); ?></h3>
                <p class="text-soft" style="font-size:14px;margin:0;"><?php echo e($cat['description']); ?></p>
                <span class="badge badge--brand" style="margin-top:6px;"><?php echo (int)$cat['count']; ?> <?php echo e(t('common.listings')); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
