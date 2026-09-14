<?php
/**
 * pages/listing-details.php
 * --------------------------------------------------------------------
 * Phase 2: Full listing details with translations, working favorites,
 * contact form, rental request, report modal, recently viewed tracking.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/smart_features.php';
listings_verified_ready();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); die(t('errors.listing_not_found')); }

// Increment views
db()->prepare('UPDATE listings SET views_count = views_count + 1 WHERE id = ?')->execute([$id]);

// Track recently viewed (logged-in users → DB, guests → cookie)
$userId = current_user()['id'] ?? 0;
if ($userId) {
    db()->prepare('INSERT INTO recently_viewed (user_id, listing_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE viewed_at = CURRENT_TIMESTAMP')
        ->execute([$userId, $id]);
} else {
    $cookie = $_COOKIE['isoko_recent'] ?? '';
    $ids = array_filter(array_map('intval', explode(',', $cookie)));
    $ids = array_diff($ids, [$id]);
    array_unshift($ids, $id);
    $ids = array_slice($ids, 0, 10);
    setcookie('isoko_recent', implode(',', $ids), [
        'expires' => time() + 60 * 60 * 24 * 30,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Main listing
$stmt = db()->prepare(
    'SELECT l.*, c.name AS category_name, c.slug AS category_slug, c.name_key AS category_name_key,
            u.id AS seller_id, u.full_name AS seller_name, u.is_verified AS seller_verified,
            u.location AS seller_location, u.created_at AS seller_joined, u.avatar_path AS seller_avatar
     FROM listings l
     INNER JOIN categories c ON c.id = l.category_id
     INNER JOIN users u ON u.id = l.seller_id
     WHERE l.id = ? LIMIT 1'
);
$stmt->execute([$id]);
$l = $stmt->fetch();
if (!$l) { http_response_code(404); die(t('errors.listing_not_found')); }

// Seller profile (rating, response rate, etc.)
$spStmt = db()->prepare('SELECT * FROM seller_profiles WHERE user_id = ?');
$spStmt->execute([$l['seller_id']]);
$sellerProfile = $spStmt->fetch();

// Images
$imgStmt = db()->prepare('SELECT * FROM listing_images WHERE listing_id = ? ORDER BY is_primary DESC, display_order');
$imgStmt->execute([$id]);
$images = $imgStmt->fetchAll();
if (!$images) $images = [['image_path' => 'assets/images/placeholders/default.svg', 'is_primary' => 1]];

// Attributes
$attrStmt = db()->prepare('SELECT attr_key, attr_value FROM listing_attributes WHERE listing_id = ?');
$attrStmt->execute([$id]);
$attrs = $attrStmt->fetchAll();

// Is this listing in current user's favorites?
$isFav = false;
if (is_logged_in()) {
    $favStmt = db()->prepare('SELECT 1 FROM favorites WHERE user_id = ? AND listing_id = ?');
    $favStmt->execute([current_user()['id'], $id]);
    $isFav = (bool)$favStmt->fetch();
}

// Similar listings (same category, exclude self, 4 results)
$simStmt = db()->prepare(
    'SELECT l.id, l.title, l.slug, l.price, l.currency, l.listing_type, l.location,
            l.price_per_month, l.price_per_week, l.price_per_day, l.created_at, l.is_verified,
            (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image
     FROM listings l
     WHERE l.category_id = ? AND l.id <> ? AND l.status = "active" AND l.availability = "available"
     ORDER BY l.created_at DESC LIMIT 4'
);
$simStmt->execute([$l['category_id'], $id]);
$similar = $simStmt->fetchAll();

// Nearby listings — same city/district, ranked by real views (Phase 6)
$nearby = nearby_listings($l, 4);

// Recently viewed by this visitor (Phase 6 — DB for members, cookie for guests)
$recentlyViewed = recently_viewed_listings(4, $id);

// Listing Quality Score — computed from this listing's real fields (Phase 3)
$qualityRow = db()->prepare('SELECT l.*, u.phone AS seller_phone,
        (SELECT COUNT(*) FROM listing_images li WHERE li.listing_id = l.id) AS images_count,
        (SELECT COUNT(*) FROM listing_attributes la WHERE la.listing_id = l.id) AS attrs_count
    FROM listings l INNER JOIN users u ON u.id = l.seller_id WHERE l.id = ?');
$qualityRow->execute([$id]);
$quality = listing_quality_score($qualityRow->fetch() ?: $l);
$isOwner = is_logged_in() && (int) current_user()['id'] === (int) $l['seller_id'];

// "You may also like" — different category, same listing type
$likeStmt = db()->prepare(
    'SELECT l.id, l.title, l.slug, l.price, l.currency, l.listing_type, l.location,
            l.price_per_month, l.price_per_week, l.price_per_day, l.created_at,
            (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image
     FROM listings l
     WHERE l.category_id <> ? AND l.id <> ? AND l.listing_type = ? AND l.status = "active" AND l.availability = "available"
     ORDER BY l.created_at DESC LIMIT 4'
);
$likeStmt->execute([$l['category_id'], $id, $l['listing_type']]);
$youMayLike = $likeStmt->fetchAll();

$pageTitle = $l['title'];
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container section--tight">
    <!-- Back button + Breadcrumb -->
    <div style="display:flex;align-items:center;gap:12px;margin:14px 0 12px;flex-wrap:wrap;">
        <?php echo back_button(APP_URL . '/pages/explore.php', t('buttons.back'), 'solid'); ?>
        <nav class="text-mute" style="font-size:13px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;" aria-label="Breadcrumb">
            <a href="<?php echo APP_URL; ?>/"><?php echo e(t('listings.detail.breadcrumb_home')); ?></a> <span>›</span>
            <a href="<?php echo APP_URL; ?>/pages/category.php?slug=<?php echo e($l['category_slug']); ?>"><?php echo e(t_category($l['category_name_key'] ?? null, $l['category_name'])); ?></a> <span>›</span>
            <span><?php echo e($l['title']); ?></span>
        </nav>
    </div>

    <div style="display:grid;grid-template-columns:1.4fr 1fr;gap:32px;align-items:start;">
        <!-- Gallery -->
        <div class="gallery">
            <div class="gallery__main" style="aspect-ratio:4/3;border-radius:16px;overflow:hidden;border:1px solid var(--border);">
                <img src="<?php echo image_or_default($images[0]['image_path']); ?>" alt="<?php echo e($l['title']); ?>">
            </div>
            <?php if (count($images) > 1): ?>
            <div style="display:flex;gap:8px;margin-top:8px;overflow-x:auto;">
                <?php foreach ($images as $i => $img): ?>
                    <button class="gallery__thumb <?php echo $i===0?'active':''; ?>" data-src="<?php echo image_or_default($img['image_path']); ?>" style="width:80px;height:60px;border-radius:8px;border:2px solid <?php echo $i===0?'var(--brand-500)':'var(--border)'; ?>;padding:0;background:none;flex-shrink:0;">
                        <img loading="lazy" src="<?php echo image_or_default($img['image_path']); ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:6px;">
                    </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Summary box -->
        <div class="card" style="position:sticky;top:80px;">
            <div style="display:flex;gap:6px;margin-bottom:8px;flex-wrap:wrap;">
                <span class="badge badge--<?php echo $l['listing_type']==='rent'?'rent':'brand'; ?>">
                    <?php echo $l['listing_type']==='rent' ? e(t('listings.for_rent')) : e(t('listings.for_sale')); ?>
                </span>
                <?php if ($l['is_featured']): ?><span class="badge badge--accent"><?php echo e(t('listings.featured')); ?></span><?php endif; ?>
                <?php if ((int) ($l['is_verified'] ?? 0) === 1): ?>
                <span class="badge badge--verified" title="<?php echo e(t('trust.verified_listing_hint')); ?>">
                    <svg viewBox="0 0 24 24" width="12" height="12" aria-hidden="true"><path d="M12 2l2.4 2.1 3.1-.5 1 3 2.9 1.2-.7 3.1L22 14l-2.4 2.4.4 3.2-3.1.6-1.9 2.6-3-.9-3 .9-1.9-2.6-3.1-.6.4-3.2L2 14l1.3-3.1L.5 7.8l2.9-1.2 1-3 3.1.5z" fill="currentColor"/></svg>
                    <?php echo e(t('trust.verified_listing')); ?>
                </span>
                <?php endif; ?>
                <span class="badge badge--<?php echo $l['availability']==='available'?'success':'neutral'; ?>">
                    <?php
                        $availKey = [
                            'available' => 'listings.avail_available',
                            'sold' => 'listings.avail_sold',
                            'rented' => 'listings.avail_rented',
                            'reserved' => 'listings.avail_reserved',
                        ][$l['availability']] ?? 'listings.avail_available';
                        echo e(t($availKey));
                    ?>
                </span>
            </div>
            <h1 style="font-size:1.5rem;margin:0 0 6px;"><?php echo e($l['title']); ?></h1>
            <div style="font-family:var(--font-serif);font-size:1.8rem;color:var(--brand-700);font-weight:700;margin-bottom:14px;">
                <?php
                    if ($l['listing_type']==='rent') {
                        echo e(t('listings.from')) . ' ' . format_price($l['price_per_month'] ?? $l['price_per_week'] ?? $l['price_per_day'] ?? 0, $l['currency']) . e(t('listings.per_month'));
                    } else echo format_price($l['price'], $l['currency']);
                ?>
            </div>

            <?php if ($l['listing_type']==='rent'): ?>
                <div class="card" style="background:var(--bg-soft);padding:14px;margin-bottom:14px;">
                    <div style="display:flex;justify-content:space-between;font-size:14px;">
                        <span><?php echo e(t('listings.detail.per_day')); ?></span><strong><?php echo e(format_price($l['price_per_day'] ?? 0, $l['currency'])); ?></strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:14px;">
                        <span><?php echo e(t('listings.detail.per_week')); ?></span><strong><?php echo e(format_price($l['price_per_week'] ?? 0, $l['currency'])); ?></strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:14px;">
                        <span><?php echo e(t('listings.detail.per_month')); ?></span><strong><?php echo e(format_price($l['price_per_month'] ?? 0, $l['currency'])); ?></strong>
                    </div>
                    <?php if ($l['deposit']): ?>
                    <div style="display:flex;justify-content:space-between;font-size:14px;margin-top:6px;border-top:1px solid var(--border);padding-top:6px;">
                        <span><?php echo e(t('listings.detail.deposit')); ?></span><strong><?php echo e(format_price($l['deposit'], $l['currency'])); ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px;font-size:14px;">
                <div>📍 <strong><?php echo e(t('listings.detail.location')); ?>:</strong> <?php echo e($l['location'] ?? 'Rwanda'); ?></div>
                <div>📦 <strong><?php echo e(t('listings.detail.condition')); ?>:</strong>
                    <?php
                        $condKey = [
                            'new' => 'listings.condition_new',
                            'used' => 'listings.condition_used',
                            'refurbished' => 'listings.condition_refurb',
                            'for-parts' => 'listings.condition_parts',
                        ][$l['condition_state'] ?? 'used'] ?? 'listings.condition_used';
                        echo e(t($condKey));
                    ?>
                </div>
                <div>📅 <strong><?php echo e(t('listings.detail.posted')); ?>:</strong> <?php echo e(time_ago($l['created_at'])); ?></div>
                <div>👁 <strong><?php echo e(t('listings.detail.views')); ?>:</strong> <?php echo (int)$l['views_count']; ?></div>
            </div>

            <?php
                $pickupOk = !array_key_exists('pickup_available', $l) || (int) $l['pickup_available'] === 1;
                $deliveryOk = !empty($l['delivery_available']);
                $paymentsRaw = array_filter(explode(',', (string) ($l['accepted_payments'] ?? '')));
                $paymentLabels = ['cash' => 'Cash', 'momo' => 'Mobile Money', 'bank' => 'Bank Transfer'];
            ?>
            <?php if ($pickupOk || $deliveryOk || $paymentsRaw): ?>
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
                <?php if ($pickupOk): ?><span class="chip chip--soft">🤝 Pickup available</span><?php endif; ?>
                <?php if ($deliveryOk): ?><span class="chip chip--soft">🚚 Delivery available</span><?php endif; ?>
                <?php foreach ($paymentsRaw as $pm): if (!isset($paymentLabels[$pm])) continue; ?>
                    <span class="chip chip--soft">💳 <?php echo e($paymentLabels[$pm]); ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <?php if (is_logged_in()): ?>
                    <button class="btn btn--primary" type="button" data-modal-open="contactModal">
                        <?php echo e(t('buttons.contact')); ?>
                    </button>
                    <?php if ($l['listing_type']==='rent'): ?>
                        <button class="btn btn--secondary" type="button" data-modal-open="rentalModal">
                            <?php echo e(t('buttons.request_rental')); ?>
                        </button>
                    <?php endif; ?>
                <?php else: ?>
                    <a class="btn btn--primary" href="<?php echo APP_URL; ?>/pages/login.php?next=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>">
                        <?php echo e(t('buttons.sign_in_contact')); ?>
                    </a>
                <?php endif; ?>
                <button class="btn btn--secondary listing-card__fav <?php echo $isFav ? 'is-saved' : ''; ?>"
                        type="button"
                        data-fav-listing="<?php echo (int)$id; ?>"
                        aria-label="<?php echo e(t('buttons.save')); ?>">
                    <svg viewBox="0 0 24 24" width="16" height="16"><path d="M12 21s-7-4.5-9.5-9A4.5 4.5 0 0 1 12 5.5 4.5 4.5 0 0 1 21.5 12c-2.5 4.5-9.5 9-9.5 9z" fill="<?php echo $isFav ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="1.8"/></svg>
                    <?php echo $isFav ? e(t('buttons.saved')) : e(t('buttons.save')); ?>
                </button>
                <button class="btn btn--ghost" type="button" onclick="navigator.share?.({title:'<?php echo e($l['title']); ?>',url:window.location.href}).catch(()=>navigator.clipboard.writeText(window.location.href));">
                    <?php echo e(t('buttons.share')); ?>
                </button>
                <button class="btn btn--ghost" type="button" data-modal-open="reportModal">
                    <?php echo e(t('buttons.report')); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Description + attributes -->
    <div style="display:grid;grid-template-columns:1.4fr 1fr;gap:32px;margin-top:32px;align-items:start;">
        <div>
            <h2><?php echo e(t('listings.detail.description')); ?></h2>
            <p style="white-space:pre-wrap;line-height:1.7;"><?php echo e($l['description']); ?></p>

            <?php if ($l['rental_terms']): ?>
                <h3 style="margin-top:24px;"><?php echo e(t('listings.detail.rental_terms')); ?></h3>
                <p><?php echo e($l['rental_terms']); ?></p>
            <?php endif; ?>
        </div>
        <div>
            <div class="card">
                <h3 style="margin-top:0;"><?php echo e(t('listings.detail.details')); ?></h3>
                <?php if ($attrs): ?>
                    <table style="width:100%;font-size:14px;border-collapse:collapse;">
                        <?php foreach ($attrs as $a): ?>
                            <tr>
                                <td style="padding:6px 0;color:var(--text-mute);"><?php echo e(ucwords(str_replace('_', ' ', $a['attr_key']))); ?></td>
                                <td style="padding:6px 0;font-weight:600;"><?php echo e($a['attr_value']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php else: ?>
                    <p class="text-mute" style="font-size:14px;"><?php echo e(t('listings.detail.no_details')); ?></p>
                <?php endif; ?>
            </div>

            <?php if ($isOwner): ?>
            <!-- Listing Quality Score (visible to the owner — Phase 3) -->
            <div class="quality-panel" style="margin-top:20px;">
                <div class="quality-panel__head">
                    <span class="quality-ring" aria-hidden="true">
                        <?php
                            $r = 36; $circ = 2 * M_PI * $r;
                            $tone = $quality['verdict'] === 'excellent' ? '#0d8a53' : ($quality['verdict'] === 'good' ? '#0ea5a4' : ($quality['verdict'] === 'fair' ? '#e08a12' : '#c34d4d'));
                        ?>
                        <svg width="84" height="84" viewBox="0 0 84 84">
                            <circle cx="42" cy="42" r="<?php echo $r; ?>" fill="none" stroke="var(--border)" stroke-width="8"/>
                            <circle cx="42" cy="42" r="<?php echo $r; ?>" fill="none" stroke="<?php echo $tone; ?>" stroke-width="8"
                                    stroke-linecap="round" stroke-dasharray="<?php echo e(number_format($circ * $quality['score'] / 100, 2, '.', '')) . ' ' . e(number_format($circ, 2, '.', '')); ?>"/>
                        </svg>
                        <span class="quality-ring__num"><?php echo (int) $quality['score']; ?><small>/ 100</small></span>
                    </span>
                    <div style="min-width:180px;">
                        <h3 class="quality-panel__title"><?php echo e(t('quality.listing_quality')); ?></h3>
                        <p class="quality-panel__verdict"><?php echo e(quality_verdict_label($quality['verdict'])); ?> · <?php echo e(t('quality.owner_hint')); ?></p>
                    </div>
                </div>
                <div class="quality-panel__body">
                    <?php foreach ($quality['checks'] as $chk): ?>
                        <?php $ck = 'quality.check.' . substr($chk['key'], strlen('quality.check.')); ?>
                        <div class="quality-check<?php echo $chk['ok'] ? '' : ' quality-check--warn'; ?>">
                            <span class="quality-check__icon">
                                <?php if ($chk['ok']): ?>
                                    <svg viewBox="0 0 24 24" width="11" height="11"><path d="M5 13l4 4L19 7" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <?php else: ?>
                                    <svg viewBox="0 0 24 24" width="11" height="11"><path d="M12 6v6M12 16.5v.5" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                                <?php endif; ?>
                            </span>
                            <span class="quality-check__text">
                                <b><?php echo e(t($chk['key'])); ?></b>
                                <small><?php echo $chk['ok'] ? e(t('quality.pass')) : e(t('quality.improve')); ?> · <?php echo (int) $chk['points']; ?>/<?php echo (int) $chk['max']; ?></small>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Seller card -->
    <div class="card" style="margin-top:32px;">
        <h3 style="margin-top:0;"><?php echo e(t('listings.detail.seller')); ?></h3>
        <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
            <a href="<?php echo APP_URL; ?>/pages/seller-profile.php?id=<?php echo (int)$l['seller_id']; ?>" style="display:flex;align-items:center;gap:12px;text-decoration:none;">
                <img src="<?php echo $l['seller_avatar'] ? image_or_default($l['seller_avatar']) : real_image('seller-eric', 'avatar-default'); ?>" alt="<?php echo e($l['seller_name']); ?>" style="width:48px;height:48px;border-radius:50%;object-fit:cover;">
                <div>
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                        <strong style="color:var(--text);"><?php echo e($l['seller_name']); ?></strong>
                        <?php if ($l['seller_verified']): ?>
                            <span class="verified"><svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7" fill="none" stroke="currentColor" stroke-width="2.4"/></svg><?php echo e(t('listings.detail.verified')); ?></span>
                        <?php endif; ?>
                        <?php if ($sellerProfile && $sellerProfile['verified_at'] && $l['seller_verified']): ?>
                            <span class="badge badge--verified"><?php echo e(t('trust.trusted_owner')); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="text-mute" style="font-size:13px;">📍 <?php echo e($l['seller_location'] ?? 'Rwanda'); ?> · <?php echo e(t('listings.detail.member_since')); ?> <?php echo e(date('M Y', strtotime($l['seller_joined']))); ?></div>
                    <?php if ($sellerProfile && $sellerProfile['rating_count'] > 0): ?>
                        <div style="font-size:13px;color:var(--accent-500);">★ <?php echo e(number_format((float)$sellerProfile['rating_average'], 2)); ?> (<?php echo (int)$sellerProfile['rating_count']; ?> <?php echo e(t('common.reviews')); ?>)</div>
                    <?php endif; ?>
                </div>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/seller-profile.php?id=<?php echo (int)$l['seller_id']; ?>" class="btn btn--outline" style="margin-left:auto;">
                <?php echo e(t('listings.detail.view_seller')); ?>
            </a>
        </div>
    </div>

    <!-- Similar listings -->
    <?php if ($similar): ?>
    <div style="margin-top:48px;">
        <div class="section__head">
            <h2 class="section__title"><?php echo e(t('listings.detail.similar')); ?></h2>
        </div>
        <div class="grid grid--4 grid--auto">
            <?php foreach ($similar as $s): ?>
                <a class="listing-card" href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$s['id']; ?>">
                    <div class="listing-card__media">
                        <img loading="lazy" src="<?php echo image_or_default($s['image']); ?>" alt="">
                        <?php if ((int) ($s['is_verified'] ?? 0) === 1): ?><i class="listing-card__vcheck" title="<?php echo e(t('trust.verified_listing')); ?>"><svg viewBox="0 0 24 24" width="10" height="10"><path d="M5 13l4 4L19 7" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg></i><?php endif; ?>
                    </div>
                    <div class="listing-card__body">
                        <div class="listing-card__price"><?php echo $s['listing_type'] === 'rent' ? e(t('listings.from')) . ' ' . format_price($s['price_per_month'] ?? $s['price_per_week'] ?? $s['price_per_day'] ?? 0, $s['currency']) . e(t('listings.per_month')) : e(format_price($s['price'], $s['currency'])); ?></div>
                        <div class="listing-card__title"><?php echo e($s['title']); ?></div>
                        <div class="listing-card__meta"><span><?php echo e($s['location'] ?? 'Rwanda'); ?></span><span><?php echo time_ago($s['created_at']); ?></span></div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- You may also like -->
    <?php if ($youMayLike): ?>
    <div style="margin-top:48px;">
        <div class="section__head">
            <h2 class="section__title"><?php echo e(t('sections.you_may_like')); ?></h2>
        </div>
        <div class="grid grid--4 grid--auto">
            <?php foreach ($youMayLike as $s): ?>
                <a class="listing-card" href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$s['id']; ?>">
                    <div class="listing-card__media">
                        <img loading="lazy" src="<?php echo image_or_default($s['image']); ?>" alt="">
                    </div>
                    <div class="listing-card__body">
                        <div class="listing-card__price"><?php echo $s['listing_type'] === 'rent' ? e(t('listings.from')) . ' ' . format_price($s['price_per_month'] ?? $s['price_per_week'] ?? $s['price_per_day'] ?? 0, $s['currency']) . e(t('listings.per_month')) : e(format_price($s['price'], $s['currency'])); ?></div>
                        <div class="listing-card__title"><?php echo e($s['title']); ?></div>
                        <div class="listing-card__meta"><span><?php echo e($s['location'] ?? 'Rwanda'); ?></span><span><?php echo time_ago($s['created_at']); ?></span></div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- More in this location (nearby — real same-city listings, Phase 6) -->
    <?php if ($nearby): ?>
    <div style="margin-top:48px;">
        <div class="section__head">
            <h2 class="section__title"><?php echo e(t('sections.near_location', ['location' => trim(explode(',', (string) $l['location'])[0]) ?: t('sections.this_area')])); ?></h2>
            <a class="section__link" href="<?php echo APP_URL; ?>/pages/explore.php?location=<?php echo urlencode(trim(explode(',', (string) $l['location'])[0])); ?>"><?php echo e(t('sections.view_all')); ?> &rarr;</a>
        </div>
        <div class="grid grid--4 grid--auto">
            <?php foreach ($nearby as $s): ?>
                <a class="listing-card" href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$s['id']; ?>">
                    <div class="listing-card__media">
                        <img loading="lazy" src="<?php echo image_or_default($s['image']); ?>" alt="">
                    </div>
                    <div class="listing-card__body">
                        <div class="listing-card__price"><?php echo $s['listing_type'] === 'rent' ? e(t('listings.from')) . ' ' . format_price($s['price_per_month'] ?? $s['price_per_week'] ?? $s['price_per_day'] ?? 0, $s['currency']) . e(t('listings.per_month')) : e(format_price($s['price'], $s['currency'])); ?></div>
                        <div class="listing-card__title"><?php echo e($s['title']); ?></div>
                        <div class="listing-card__meta"><span><?php echo e($s['location'] ?? 'Rwanda'); ?></span><span><?php echo time_ago($s['created_at']); ?></span></div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recently viewed by you (Phase 6) -->
    <?php if ($recentlyViewed): ?>
    <div style="margin-top:48px;">
        <div class="section__head">
            <h2 class="section__title"><?php echo e(t('sections.recently_viewed')); ?></h2>
        </div>
        <div class="grid grid--4 grid--auto">
            <?php foreach ($recentlyViewed as $s): ?>
                <a class="listing-card" href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$s['id']; ?>">
                    <div class="listing-card__media">
                        <img loading="lazy" src="<?php echo image_or_default($s['image']); ?>" alt="">
                    </div>
                    <div class="listing-card__body">
                        <div class="listing-card__price"><?php echo $s['listing_type'] === 'rent' ? e(t('listings.from')) . ' ' . format_price($s['price_per_month'] ?? $s['price_per_week'] ?? $s['price_per_day'] ?? 0, $s['currency']) . e(t('listings.per_month')) : e(format_price($s['price'], $s['currency'])); ?></div>
                        <div class="listing-card__title"><?php echo e($s['title']); ?></div>
                        <div class="listing-card__meta"><span><?php echo e($s['location'] ?? 'Rwanda'); ?></span><span><?php echo time_ago($s['created_at']); ?></span></div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- =============== MODALS =============== -->

<!-- Contact seller modal -->
<?php if (is_logged_in()): ?>
<dialog class="modal" id="contactModal" style="border:0;padding:0;background:transparent;max-width:500px;width:90vw;">
    <form method="post" action="<?php echo APP_URL; ?>/api/v1/contact/index.php">
        <div class="card" style="padding:24px;">
            <h3 style="margin-top:0;"><?php echo e(t('listings.detail.contact_form')); ?></h3>
            <p class="text-soft" style="font-size:14px;margin:0 0 18px;"><?php echo e(t('listings.detail.contact_sub')); ?></p>
            <input type="hidden" name="listing_id" value="<?php echo (int)$id; ?>">
            <input type="hidden" name="seller_id" value="<?php echo (int)$l['seller_id']; ?>">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label for="c_name"><?php echo e(t('listings.detail.your_name')); ?></label>
                <input type="text" id="c_name" name="name" required value="<?php echo e(current_user()['full_name']); ?>">
            </div>
            <div class="form-group">
                <label for="c_email"><?php echo e(t('listings.detail.your_email')); ?></label>
                <input type="email" id="c_email" name="email" required value="<?php echo e(current_user()['email']); ?>">
            </div>
            <div class="form-group">
                <label for="c_phone"><?php echo e(t('listings.detail.your_phone')); ?></label>
                <input type="tel" id="c_phone" name="phone">
            </div>
            <div class="form-group">
                <label for="c_msg"><?php echo e(t('listings.detail.your_message')); ?></label>
                <textarea id="c_msg" name="message" rows="4" required placeholder="I'm interested in this listing. Is it still available?"></textarea>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn--primary"><?php echo e(t('buttons.send_message')); ?></button>
                <button type="button" class="btn btn--ghost" data-modal-close="contactModal"><?php echo e(t('buttons.cancel')); ?></button>
            </div>
        </div>
    </form>
</dialog>

<!-- Rental request modal (rent listings only) -->
<?php if ($l['listing_type']==='rent'): ?>
<dialog class="modal" id="rentalModal" style="border:0;padding:0;background:transparent;max-width:500px;width:90vw;">
    <form method="post" action="<?php echo APP_URL; ?>/api/v1/rentals/index.php">
        <div class="card" style="padding:24px;">
            <h3 style="margin-top:0;"><?php echo e(t('listings.detail.rental_request')); ?></h3>
            <p class="text-soft" style="font-size:14px;margin:0 0 18px;"><?php echo e(t('listings.detail.rental_sub')); ?></p>
            <input type="hidden" name="listing_id" value="<?php echo (int)$id; ?>">
            <?php echo csrf_field(); ?>
            <div class="form-row">
                <div class="form-group">
                    <label for="r_start"><?php echo e(t('listings.detail.start_date')); ?></label>
                    <input type="date" id="r_start" name="start_date" required>
                </div>
                <div class="form-group">
                    <label for="r_end"><?php echo e(t('listings.detail.end_date')); ?></label>
                    <input type="date" id="r_end" name="end_date" required>
                </div>
            </div>
            <div class="form-group">
                <label for="r_msg"><?php echo e(t('listings.detail.your_message')); ?></label>
                <textarea id="r_msg" name="message" rows="3" placeholder="Any questions for the seller?"></textarea>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn--primary"><?php echo e(t('buttons.request_rental')); ?></button>
                <button type="button" class="btn btn--ghost" data-modal-close="rentalModal"><?php echo e(t('buttons.cancel')); ?></button>
            </div>
        </div>
    </form>
</dialog>
<?php endif; ?>
<?php endif; // end is_logged_in ?>

<!-- Report listing modal -->
<dialog class="modal" id="reportModal" style="border:0;padding:0;background:transparent;max-width:500px;width:90vw;">
    <form method="post" action="<?php echo APP_URL; ?>/api/v1/reports/index.php">
        <div class="card" style="padding:24px;">
            <h3 style="margin-top:0;"><?php echo e(t('listings.detail.report_listing')); ?></h3>
            <p class="text-soft" style="font-size:14px;margin:0 0 18px;"><?php echo e(t('listings.detail.report_sub')); ?></p>
            <input type="hidden" name="listing_id" value="<?php echo (int)$id; ?>">
            <?php if (is_logged_in()): ?>
                <input type="hidden" name="reporter_id" value="<?php echo (int)current_user()['id']; ?>">
            <?php endif; ?>
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label for="r_reason"><?php echo e(t('listings.detail.report_reason')); ?> *</label>
                <select id="r_reason" name="reason" required>
                    <option value=""><?php echo e(t('form.select')); ?></option>
                    <option value="<?php echo e(t('listings.detail.report_reasons.scam')); ?>"><?php echo e(t('listings.detail.report_reasons.scam')); ?></option>
                    <option value="<?php echo e(t('listings.detail.report_reasons.prohibited')); ?>"><?php echo e(t('listings.detail.report_reasons.prohibited')); ?></option>
                    <option value="<?php echo e(t('listings.detail.report_reasons.misleading')); ?>"><?php echo e(t('listings.detail.report_reasons.misleading')); ?></option>
                    <option value="<?php echo e(t('listings.detail.report_reasons.duplicate')); ?>"><?php echo e(t('listings.detail.report_reasons.duplicate')); ?></option>
                    <option value="<?php echo e(t('listings.detail.report_reasons.other')); ?>"><?php echo e(t('listings.detail.report_reasons.other')); ?></option>
                </select>
            </div>
            <div class="form-group">
                <label for="r_details"><?php echo e(t('listings.detail.report_details')); ?></label>
                <textarea id="r_details" name="details" rows="3"></textarea>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn--primary"><?php echo e(t('buttons.send')); ?></button>
                <button type="button" class="btn btn--ghost" data-modal-close="reportModal"><?php echo e(t('buttons.cancel')); ?></button>
            </div>
        </div>
    </form>
</dialog>

<script>
// Modal open/close (using <dialog> element where supported, fallback to display:none)
document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-modal-open');
        var modal = document.getElementById(id);
        if (modal && modal.tagName === 'DIALOG' && typeof modal.showModal === 'function') {
            modal.showModal();
        } else if (modal) {
            modal.style.display = 'block';
        }
    });
});
document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-modal-close');
        var modal = document.getElementById(id);
        if (modal && modal.tagName === 'DIALOG' && typeof modal.close === 'function') {
            modal.close();
        } else if (modal) {
            modal.style.display = 'none';
        }
    });
});
// Close on backdrop click
document.querySelectorAll('dialog.modal').forEach(function (modal) {
    modal.addEventListener('click', function (ev) {
        if (ev.target === modal) {
            if (typeof modal.close === 'function') modal.close();
            else modal.style.display = 'none';
        }
    });
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php';
