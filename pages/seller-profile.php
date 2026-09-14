<?php
/**
 * pages/seller-profile.php
 * --------------------------------------------------------------------
 * Public seller profile page (Phase 2).
 *
 *   ?id=<user_id>
 *
 * Shows:
 *   - Seller avatar, name, verified badge, location, member since
 *   - Rating (from seller_profiles + reviews)
 *   - Stats (listings count, total sales, response rate)
 *   - All active listings
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$userId = (int)($_GET['id'] ?? 0);
if (!$userId) { http_response_code(404); die(t('errors.listing_not_found')); }

// Load seller base + profile
$stmt = db()->prepare(
    'SELECT u.id, u.full_name, u.email, u.phone, u.location, u.avatar_path,
            u.is_verified, u.is_seller, u.created_at, u.bio,
            sp.business_name, sp.business_description, sp.response_rate,
            sp.response_time_hours, sp.rating_average, sp.rating_count,
            sp.total_sales, sp.total_rentals, sp.verified_at,
            sp.social_facebook, sp.social_twitter, sp.social_instagram, sp.social_whatsapp
     FROM users u
     LEFT JOIN seller_profiles sp ON sp.user_id = u.id
     WHERE u.id = ? AND u.status = "active" LIMIT 1'
);
$stmt->execute([$userId]);
$seller = $stmt->fetch();
if (!$seller) { http_response_code(404); die(t('errors.seller_not_found')); }

// Stats
$statsStmt = db()->prepare(
    'SELECT
        (SELECT COUNT(*) FROM listings WHERE seller_id = ? AND status = "active" AND availability = "available") AS active_count,
        (SELECT COUNT(*) FROM listings WHERE seller_id = ? AND availability = "sold") AS sold_count,
        (SELECT COUNT(*) FROM listings WHERE seller_id = ? AND availability = "rented") AS rented_count,
        (SELECT COALESCE(SUM(views_count), 0) FROM listings WHERE seller_id = ?) AS total_views,
        (SELECT COALESCE(SUM(favorites_count), 0) FROM listings WHERE seller_id = ?) AS total_favorites'
);
$statsStmt->execute([$userId, $userId, $userId, $userId, $userId]);
$stats = $statsStmt->fetch();

// Seller's listings
$listingsStmt = db()->prepare(
    'SELECT l.id, l.title, l.slug, l.price, l.currency, l.listing_type, l.location,
            l.condition_state, l.availability, l.views_count, l.favorites_count, l.created_at,
            (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image
     FROM listings l
     WHERE l.seller_id = ? AND l.status = "active"
     ORDER BY l.created_at DESC LIMIT 24'
);
$listingsStmt->execute([$userId]);
$listings = $listingsStmt->fetchAll();

// Reviews (recent 5)
$reviewsStmt = db()->prepare(
    'SELECT r.rating, r.comment, r.created_at, u.full_name AS reviewer_name
     FROM reviews r
     INNER JOIN users u ON u.id = r.reviewer_id
     WHERE r.seller_id = ?
     ORDER BY r.created_at DESC LIMIT 5'
);
$reviewsStmt->execute([$userId]);
$reviews = $reviewsStmt->fetchAll();

$pageTitle = $seller['full_name'];
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container section--tight" style="max-width:1100px;">
    <div style="margin:14px 0 4px;">
        <?php echo back_button(APP_URL . '/', t('buttons.back'), 'solid'); ?>
    </div>

    <!-- Header card -->
    <div class="card" style="margin-bottom:24px;background:linear-gradient(135deg,var(--brand-50),transparent),var(--bg-card);">
        <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
            <img src="<?php echo $seller['avatar_path'] ? image_or_default($seller['avatar_path']) : real_image('seller-eric', 'avatar-default'); ?>" alt="<?php echo e($seller['full_name']); ?>" style="width:84px;height:84px;border-radius:50%;object-fit:cover;flex-shrink:0;border:3px solid var(--brand-300);">
            <div style="flex:1;min-width:200px;">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <h1 style="margin:0;font-size:1.7rem;font-family:var(--font-serif);">
                        <?php echo e($seller['business_name'] ?: $seller['full_name']); ?>
                    </h1>
                    <?php if ($seller['is_verified']): ?>
                        <span class="verified" style="font-size:14px;">
                            <svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7" fill="none" stroke="currentColor" stroke-width="2.4"/></svg>
                            <?php echo e(t('listings.detail.verified')); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div style="color:var(--text-soft);font-size:14px;margin-top:4px;">
                    📍 <?php echo e($seller['location'] ?? 'Rwanda'); ?> ·
                    <?php echo e(t('listings.detail.member_since')); ?> <?php echo e(date('M Y', strtotime($seller['created_at']))); ?>
                </div>
                <?php if ($seller['rating_count'] > 0): ?>
                    <div style="margin-top:6px;">
                        <span style="color:var(--accent-500);font-weight:600;">★ <?php echo e(number_format((float)$seller['rating_average'], 2)); ?></span>
                        <span class="text-mute"> (<?php echo (int)$seller['rating_count']; ?> <?php echo e(t('common.reviews')); ?>)</span>
                    </div>
                <?php endif; ?>
                <?php if ($seller['bio']): ?>
                    <p style="margin:10px 0 0;color:var(--text-soft);font-size:14px;max-width:60ch;"><?php echo e($seller['bio']); ?></p>
                <?php endif; ?>
            </div>
            <div style="display:flex;flex-direction:column;gap:8px;">
                <?php if (is_logged_in() && current_user()['id'] !== $userId): ?>
                    <a class="btn btn--primary" href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$listings[0]['id']; ?>" data-modal-open="contactSellerModal">
                        <?php echo e(t('buttons.contact')); ?>
                    </a>
                <?php endif; ?>
                <?php if ($seller['social_whatsapp']): ?>
                    <a class="btn btn--ghost" href="https://wa.me/<?php echo e(preg_replace('/[^0-9]/', '', $seller['social_whatsapp'])); ?>" target="_blank" rel="noopener">WhatsApp</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Stats grid -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:28px;" class="sp-stats">
        <div style="display:flex;align-items:center;gap:12px;padding:16px;background:var(--bg-card);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow-sm);">
            <div style="width:42px;height:42px;border-radius:11px;display:grid;place-items:center;background:var(--brand-50);color:var(--brand-700);flex:0 0 42px;">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/></svg>
            </div>
            <div>
                <div style="font-size:22px;font-weight:800;color:var(--text);font-variant-numeric:tabular-nums;"><?php echo (int)$stats['active_count']; ?></div>
                <div style="font-size:11.5px;color:var(--text-mute);"><?php echo e(t('seller.active_listings')); ?></div>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:12px;padding:16px;background:var(--bg-card);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow-sm);">
            <div style="width:42px;height:42px;border-radius:11px;display:grid;place-items:center;background:#ecfdf3;color:#058555;flex:0 0 42px;">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 6 9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </div>
            <div>
                <div style="font-size:22px;font-weight:800;color:var(--text);font-variant-numeric:tabular-nums;"><?php echo (int)$stats['sold_count']; ?></div>
                <div style="font-size:11.5px;color:var(--text-mute);"><?php echo e(t('seller.sold')); ?></div>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:12px;padding:16px;background:var(--bg-card);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow-sm);">
            <div style="width:42px;height:42px;border-radius:11px;display:grid;place-items:center;background:#eff5ff;color:#2563eb;flex:0 0 42px;">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
            </div>
            <div>
                <div style="font-size:22px;font-weight:800;color:var(--text);font-variant-numeric:tabular-nums;"><?php echo (int)$stats['rented_count']; ?></div>
                <div style="font-size:11.5px;color:var(--text-mute);"><?php echo e(t('seller.rented')); ?></div>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:12px;padding:16px;background:var(--bg-card);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow-sm);">
            <div style="width:42px;height:42px;border-radius:11px;display:grid;place-items:center;background:#fff7e9;color:#c4730a;flex:0 0 42px;">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
            </div>
            <div>
                <div style="font-size:22px;font-weight:800;color:var(--text);font-variant-numeric:tabular-nums;"><?php echo number_format((int)$stats['total_views']); ?></div>
                <div style="font-size:11.5px;color:var(--text-mute);"><?php echo e(t('seller.total_views')); ?></div>
            </div>
        </div>
    </div>

    <!-- Listings -->
    <div style="margin-bottom:32px;">
        <div class="section__head">
            <h2 class="section__title"><?php echo e(t('seller.my_listings')); ?> (<?php echo count($listings); ?>)</h2>
        </div>
        <?php if (!$listings): ?>
            <div class="empty-state">
                <h3><?php echo e(t('empty.no_listings')); ?></h3>
            </div>
        <?php else: ?>
            <div class="grid grid--4 grid--auto">
                <?php foreach ($listings as $l): ?>
                    <a class="listing-card" href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$l['id']; ?>">
                        <div class="listing-card__media">
                            <img loading="lazy" src="<?php echo image_or_default($l['image']); ?>" alt="<?php echo e($l['title']); ?>">
                            <div class="listing-card__badges">
                                <span class="badge badge--<?php echo $l['listing_type']==='rent'?'rent':'brand'; ?>">
                                    <?php echo $l['listing_type']==='rent' ? e(t('listings.for_rent')) : e(t('listings.for_sale')); ?>
                                </span>
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
    </div>

    <!-- Reviews -->
    <?php if ($reviews): ?>
    <div>
        <div class="section__head">
            <h2 class="section__title"><?php echo e(t("common.reviews")); ?> (<?php echo count($reviews); ?>)</h2>
        </div>
        <div style="display:flex;flex-direction:column;gap:12px;">
            <?php foreach ($reviews as $r): ?>
                <div class="card">
                    <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                        <strong><?php echo e($r['reviewer_name']); ?></strong>
                        <span style="color:var(--accent-500);">★ <?php echo (int)$r['rating']; ?>/5</span>
                    </div>
                    <p style="margin:6px 0 4px;color:var(--text-soft);"><?php echo e($r['comment']); ?></p>
                    <small class="text-mute"><?php echo time_ago($r['created_at']); ?></small>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<style>
@media (max-width: 700px) {
    .sp-stats { grid-template-columns: 1fr 1fr !important; }
}
@media (max-width: 400px) {
    .sp-stats { grid-template-columns: 1fr !important; }
}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
