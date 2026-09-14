<?php
/**
 * pages/category.php — listings under one category (Phase 2: translated)
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

$slug = $_GET['slug'] ?? '';
if (!$slug) redirect(APP_URL . '/pages/categories.php');

$stmt = db()->prepare('SELECT id, name, name_key, slug, description FROM categories WHERE slug = ? LIMIT 1');
$stmt->execute([$slug]);
$cat = $stmt->fetch();
if (!$cat) { http_response_code(404); die(t('errors.category_not_found')); }

// Subcategories for this category
$subStmt = db()->prepare('SELECT id, name, name_key, slug FROM subcategories WHERE category_id = ? ORDER BY name');
$subStmt->execute([$cat['id']]);
$subcats = $subStmt->fetchAll();

$listStmt = db()->prepare(
    'SELECT l.id, l.title, l.slug, l.price, l.currency, l.listing_type, l.location,
            l.condition_state, l.availability, l.views_count, l.favorites_count, l.created_at,
            (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image,
            u.full_name AS seller_name, u.is_verified AS seller_verified
     FROM listings l
     INNER JOIN users u ON u.id = l.seller_id
     WHERE l.category_id = ? AND l.status = "active" AND l.availability = "available"
     ORDER BY l.is_featured DESC, l.created_at DESC'
);
$listStmt->execute([$cat['id']]);
$items = $listStmt->fetchAll();

$pageTitle = t_category($cat['name_key'] ?? null, $cat['name']);
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container section--tight">
    <div style="margin:14px 0 4px;">
        <?php echo back_button(APP_URL . '/pages/categories.php', t('buttons.back'), 'solid'); ?>
    </div>
    <div class="section__head">
        <div>
            <h1 class="section__title"><?php echo e(t_category($cat['name_key'] ?? null, $cat['name'])); ?></h1>
            <p class="section__sub"><?php echo e($cat['description']); ?></p>
        </div>
    </div>

    <?php if ($subcats): ?>
    <div style="margin-bottom:20px;">
        <h4 style="margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-mute);"><?php echo e(t('search.subcategory')); ?></h4>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a class="badge badge--brand" href="<?php echo APP_URL; ?>/pages/category.php?slug=<?php echo e($cat['slug']); ?>"><?php echo e(t('search.all')); ?></a>
            <?php foreach ($subcats as $s): ?>
                <a class="badge badge--neutral" href="<?php echo APP_URL; ?>/pages/explore.php?category=<?php echo e($cat['slug']); ?>&subcategory=<?php echo e($s['slug']); ?>"><?php echo e(t_category($s['name_key'] ?? null, $s['name'])); ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$items): ?>
        <div class="empty-state">
            <h3><?php echo e(t('empty.no_category_listings')); ?></h3>
            <p><?php echo e(t('empty.no_category_sub')); ?> <?php echo e(t_category($cat['name_key'] ?? null, $cat['name'])); ?>.</p>
            <a class="btn btn--primary" href="<?php echo APP_URL; ?>/pages/sell.php"><?php echo e(t('buttons.create_listing')); ?></a>
        </div>
    <?php else: ?>
        <div class="grid grid--4 grid--auto">
            <?php foreach ($items as $l): ?>
                <?php
                    $image = image_or_default($l['image'] ?? null, 'assets/images/placeholders/default.svg');
                    $typeBadge = $l['listing_type'] === 'rent'
                        ? '<span class="badge badge--rent">' . e(t('listings.for_rent')) . '</span>'
                        : '<span class="badge badge--brand">' . e(t('listings.for_sale')) . '</span>';
                ?>
                <a class="listing-card" href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$l['id']; ?>">
                    <div class="listing-card__media">
                        <img loading="lazy" src="<?php echo $image; ?>" alt="<?php echo e($l['title']); ?>">
                        <div class="listing-card__badges"><?php echo $typeBadge; ?></div>
                    </div>
                    <div class="listing-card__body">
                        <div class="listing-card__price"><?php echo e(format_price($l['price'], $l['currency'])); ?></div>
                        <div class="listing-card__title"><?php echo e($l['title']); ?></div>
                        <div class="listing-card__seller"><span class="avatar"><?php echo e(strtoupper(substr($l['seller_name'],0,1))); ?></span><span><?php echo e($l['seller_name']); ?></span></div>
                        <div class="listing-card__meta"><span><?php echo e($l['location'] ?? 'Rwanda'); ?></span><span><?php echo time_ago($l['created_at']); ?></span></div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
