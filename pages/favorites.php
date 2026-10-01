<?php
/**
 * pages/favorites.php — saved listings + recently viewed (Phase 2: translated)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_login();
$uid = current_user()['id'];

// Saved favorites
$stmt = db()->prepare(
    'SELECT l.id, l.title, l.slug, l.price, l.currency, l.listing_type, l.location, l.created_at,
            (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image
     FROM favorites f
     INNER JOIN listings l ON l.id = f.listing_id
     WHERE f.user_id = ?
     ORDER BY f.created_at DESC'
);
$stmt->execute([$uid]);
$favs = $stmt->fetchAll();

// Recently viewed (logged-in users → DB; guests → cookie)
$recentlyViewed = [];
if ($uid) {
    $rvStmt = db()->prepare(
        'SELECT l.id, l.title, l.slug, l.price, l.currency, l.listing_type, l.location, l.created_at,
                (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image,
                rv.viewed_at
         FROM recently_viewed rv
         INNER JOIN listings l ON l.id = rv.listing_id
         WHERE rv.user_id = ? AND l.id NOT IN (SELECT listing_id FROM favorites WHERE user_id = ?)
         ORDER BY rv.viewed_at DESC LIMIT 8'
    );
    $rvStmt->execute([$uid, $uid]);
    $recentlyViewed = $rvStmt->fetchAll();
} else {
    // Guest — read cookie
    $cookieIds = array_filter(array_map('intval', explode(',', $_COOKIE['isoko_recent'] ?? '')));
    if ($cookieIds) {
        $placeholders = implode(',', array_fill(0, count($cookieIds), '?'));
        $rvStmt = db()->prepare(
            "SELECT l.id, l.title, l.slug, l.price, l.currency, l.listing_type, l.location, l.created_at,
                    (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image
             FROM listings l
             WHERE l.id IN ($placeholders)
             ORDER BY FIELD(l.id, $placeholders) LIMIT 8"
        );
        // Bind both sets of params
        $params = array_merge($cookieIds, $cookieIds);
        $rvStmt->execute($params);
        $recentlyViewed = $rvStmt->fetchAll();
    }
}

$pageTitle = t('nav.favorites');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container section--tight">
    <div style="margin:14px 0 4px;">
        <?php echo back_button(APP_URL . '/', t('buttons.back'), 'solid'); ?>
    </div>
    <div class="section__head">
        <div><h1 class="section__title"><?php echo e(t('nav.favorites')); ?></h1>
        <p class="section__sub"><?php echo e(t('empty.no_favorites_sub')); ?></p></div>
    </div>
    <?php if (!$favs): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 21s-7-4.5-9.5-9A4.5 4.5 0 0 1 12 5.5 4.5 4.5 0 0 1 21.5 12c-2.5 4.5-9.5 9-9.5 9z"/></svg>
            <h3><?php echo e(t('empty.no_favorites')); ?></h3>
            <p><?php echo e(t('empty.no_favorites_sub')); ?></p>
            <a class="btn btn--primary" href="<?php echo APP_URL; ?>/pages/explore.php"><?php echo e(t('buttons.browse_listings')); ?></a>
        </div>
    <?php else: ?>
        <div class="grid grid--4 grid--auto">
            <?php foreach ($favs as $l): ?>
                <a class="listing-card" href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$l['id']; ?>">
                    <div class="listing-card__media">
                        <img loading="lazy" src="<?php echo image_or_default($l['image']); ?>" alt="">
                        <div class="listing-card__badges">
                            <span class="badge badge--<?php echo $l['listing_type']==='rent'?'rent':'brand'; ?>"><?php echo $l['listing_type']==='rent'?e(t('listings.for_rent')):e(t('listings.for_sale')); ?></span>
                        </div>
                    </div>
                    <div class="listing-card__body">
                        <div class="listing-card__price"><?php echo e(format_price($l['price'], $l['currency'])); ?></div>
                        <div class="listing-card__title"><?php echo e($l['title']); ?></div>
                        <div class="listing-card__meta"><span><?php echo e($l['location'] ?? 'Rwanda'); ?></span><span><?php echo time_ago($l['created_at']); ?></span></div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($recentlyViewed): ?>
    <div style="margin-top:48px;">
        <div class="section__head">
            <h2 class="section__title"><?php echo e(t('listings.detail.recently_viewed')); ?></h2>
        </div>
        <div class="grid grid--4 grid--auto">
            <?php foreach ($recentlyViewed as $l): ?>
                <a class="listing-card" href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$l['id']; ?>">
                    <div class="listing-card__media">
                        <img loading="lazy" src="<?php echo image_or_default($l['image']); ?>" alt="">
                    </div>
                    <div class="listing-card__body">
                        <div class="listing-card__price"><?php echo e(format_price($l['price'], $l['currency'])); ?></div>
                        <div class="listing-card__title"><?php echo e($l['title']); ?></div>
                        <div class="listing-card__meta"><span><?php echo e($l['location'] ?? 'Rwanda'); ?></span><span><?php echo time_ago($l['created_at']); ?></span></div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
