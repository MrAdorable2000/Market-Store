<?php
/**
 * pages/buyer/dashboard.php — overview for buyers (USER role)
 * --------------------------------------------------------------------
 * Every logged-in buyer gets their own dashboard, just like sellers and
 * admins. This page consolidates everything a buyer cares about:
 *
 *   - KPI cards: favorites, rental requests, messages sent, unread notifications
 *   - "My favorites" panel — listings the user has saved
 *   - "My rental requests" panel — status of every rental the user has booked
 *   - "My messages" panel — contact requests the user has sent to sellers
 *   - "Recommended for you" panel — featured + recently active listings
 *   - Quick actions: browse, search, become a seller, edit profile
 *
 * The page is read-only for the buyer (no admin/seller actions exposed).
 * Sellers and admins who land here are shown a link back to their own
 * dashboard so they're never stranded.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

require_login();
$uid = (int) current_user()['id'];

// --- KPI counts -----------------------------------------------------------
$stmt = db()->prepare("SELECT COUNT(*) FROM favorites WHERE user_id = ?");
$stmt->execute([$uid]);
$favCount = (int) $stmt->fetchColumn();

$stmt = db()->prepare("SELECT COUNT(*) FROM rental_requests WHERE renter_id = ?");
$stmt->execute([$uid]);
$rentalCount = (int) $stmt->fetchColumn();

$stmt = db()->prepare("SELECT COUNT(*) FROM contact_requests WHERE sender_id = ?");
$stmt->execute([$uid]);
$messageCount = (int) $stmt->fetchColumn();

$stmt = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$uid]);
$unreadNotif = (int) $stmt->fetchColumn();

// --- My favorites (latest 6, with primary image + price) ------------------
$stmt = db()->prepare(
    "SELECT l.id, l.title, l.slug, l.price, l.currency, l.listing_type,
            l.location, l.availability, l.status,
            (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image,
            c.slug AS category_slug, c.name AS category_name
       FROM favorites f
       INNER JOIN listings l ON l.id = f.listing_id
       INNER JOIN categories c ON c.id = l.category_id
      WHERE f.user_id = ?
        AND l.status = 'active'
      ORDER BY f.created_at DESC
      LIMIT 6"
);
$stmt->execute([$uid]);
$favorites = $stmt->fetchAll();

// --- My rental requests (latest 5) ---------------------------------------
$stmt = db()->prepare(
    "SELECT r.id, r.start_date, r.end_date, r.status, r.created_at,
            l.id AS listing_id, l.title, l.slug, l.price, l.currency,
            (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image
       FROM rental_requests r
       INNER JOIN listings l ON l.id = r.listing_id
      WHERE r.renter_id = ?
      ORDER BY r.created_at DESC
      LIMIT 5"
);
$stmt->execute([$uid]);
$rentals = $stmt->fetchAll();

// --- My messages to sellers (latest 5) -----------------------------------
$stmt = db()->prepare(
    "SELECT cr.id, cr.name, cr.message, cr.is_read, cr.created_at,
            l.id AS listing_id, l.title, l.slug
       FROM contact_requests cr
       INNER JOIN listings l ON l.id = cr.listing_id
      WHERE cr.sender_id = ?
      ORDER BY cr.created_at DESC
      LIMIT 5"
);
$stmt->execute([$uid]);
$messages = $stmt->fetchAll();

// --- Recommended for you (featured active listings, 4) --------------------
$stmt = db()->prepare(
    "SELECT l.id, l.title, l.slug, l.price, l.currency, l.listing_type,
            l.location, l.condition_state,
            (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image,
            c.slug AS category_slug, c.name AS category_name
       FROM listings l
       INNER JOIN categories c ON c.id = l.category_id
      WHERE l.status = 'active' AND l.availability = 'available'
      ORDER BY l.is_featured DESC, l.views_count DESC
      LIMIT 4"
);
$stmt->execute();
$recommended = $stmt->fetchAll();

$pageTitle = t('buyer.dash_title');
$activePage = 'dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container" style="padding-top:24px;padding-bottom:48px;">

    <!-- Back button -->
    <div style="margin-bottom:14px;">
        <?php echo back_button(APP_URL . '/', t('buttons.back_home'), 'solid'); ?>
    </div>

    <!-- Welcome header + quick actions -->
    <div class="section__head" style="margin-bottom:24px;">
        <div>
            <h1 class="section__title"><?php echo e(t('buyer.welcome', ['name' => explode(' ', current_user()['full_name'])[0]])); ?> 👋</h1>
            <p class="section__sub"><?php echo e(t('buyer.dash_sub')); ?></p>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a href="<?php echo APP_URL; ?>/pages/explore.php" class="btn btn--primary">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-3px;margin-right:6px;"><circle cx="11" cy="11" r="7"/><path d="m21 21-4-4" stroke-linecap="round"/></svg>
                <?php echo e(t('buyer.explore_listings')); ?>
            </a>
            <a href="<?php echo APP_URL; ?>/pages/favorites.php" class="btn btn--secondary">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-3px;margin-right:6px;"><path d="M12 21s7-4.5 9.5-9A4.5 4.5 0 0 0 12 5.5 4.5 4.5 0 0 0 2.5 12c2.5 4.5 9.5 9 9.5 9z"/></svg>
                <?php echo e(t('buyer.my_favorites')); ?>
            </a>
        </div>
    </div>

    <?php
    // If a seller/admin lands here, show a friendly link to their own dashboard
    $rn = strtoupper(current_user()['role_name'] ?? '');
    if (in_array($rn, ['SELLER','ADMIN','SUPER_ADMIN'], true)):
    ?>
    <div class="flash flash--info" role="status" style="margin-bottom:20px;">
        <?php if ($rn === 'SELLER'): ?>
            <a href="<?php echo APP_URL; ?>/pages/seller/dashboard.php" style="font-weight:700;"><?php echo e(t('buyer.go_seller_dash')); ?> →</a>
        <?php else: ?>
            <a href="<?php echo APP_URL; ?>/pages/admin/dashboard.php" style="font-weight:700;"><?php echo e(t('buyer.go_admin_dash')); ?> →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- KPI cards -->
    <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:28px;" class="buyer-kpis">
        <a href="<?php echo APP_URL; ?>/pages/favorites.php" class="stat" style="text-decoration:none;display:block;">
            <div class="stat__label"><?php echo e(t('buyer.kpi_favorites')); ?></div>
            <div class="stat__value"><?php echo $favCount; ?></div>
            <div class="stat__delta"><?php echo e(t('buyer.kpi_favorites_sub')); ?></div>
        </a>
        <a href="#rentals" class="stat" style="text-decoration:none;display:block;">
            <div class="stat__label"><?php echo e(t('buyer.kpi_rentals')); ?></div>
            <div class="stat__value"><?php echo $rentalCount; ?></div>
            <div class="stat__delta"><?php echo e(t('buyer.kpi_rentals_sub')); ?></div>
        </a>
        <a href="#messages" class="stat" style="text-decoration:none;display:block;">
            <div class="stat__label"><?php echo e(t('buyer.kpi_messages')); ?></div>
            <div class="stat__value"><?php echo $messageCount; ?></div>
            <div class="stat__delta"><?php echo e(t('buyer.kpi_messages_sub')); ?></div>
        </a>
        <a href="<?php echo APP_URL; ?>/pages/notifications.php" class="stat" style="text-decoration:none;display:block;">
            <div class="stat__label"><?php echo e(t('buyer.kpi_notifications')); ?></div>
            <div class="stat__value"><?php echo $unreadNotif; ?></div>
            <div class="stat__delta"><?php echo e(t('buyer.kpi_notifications_sub')); ?></div>
        </a>
    </div>

    <div style="display:grid;grid-template-columns:minmax(0,1.6fr) minmax(280px,1fr);gap:24px;align-items:start;" class="buyer-grid">

        <!-- LEFT COLUMN: favorites + rentals + messages -->
        <div>

            <!-- My favorites -->
            <div class="card" style="padding:22px;margin-bottom:22px;">
                <div class="section__head section__head--compact" style="margin-bottom:16px;">
                    <h2 style="font-size:17px;margin:0;"><?php echo e(t('buyer.fav_panel_title')); ?></h2>
                    <?php if ($favorites): ?>
                        <a class="section__link" href="<?php echo APP_URL; ?>/pages/favorites.php"><?php echo e(t('buyer.see_all')); ?> →</a>
                    <?php endif; ?>
                </div>
                <?php if (!$favorites): ?>
                    <div style="text-align:center;padding:28px 12px;color:var(--text-mute);">
                        <svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.4" style="opacity:.5;margin-bottom:8px;"><path d="M12 21s7-4.5 9.5-9A4.5 4.5 0 0 0 12 5.5 4.5 4.5 0 0 0 2.5 12c2.5 4.5 9.5 9 9.5 9z"/></svg>
                        <p style="margin:0 0 10px;font-size:14px;"><?php echo e(t('buyer.no_favorites')); ?></p>
                        <a href="<?php echo APP_URL; ?>/pages/explore.php" class="btn btn--outline btn--sm"><?php echo e(t('buyer.browse_now')); ?></a>
                    </div>
                <?php else: ?>
                    <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;" class="buyer-fav-grid">
                        <?php foreach ($favorites as $f):
                            $img = image_or_default($f['image'] ?? null, 'assets/images/placeholders/default.svg');
                            $url = APP_URL . '/pages/listing-details.php?id=' . (int)$f['id'];
                        ?>
                            <a href="<?php echo e($url); ?>" class="card card--hover" style="padding:0;overflow:hidden;text-decoration:none;display:flex;flex-direction:column;">
                                <div style="aspect-ratio:4/3;background:var(--bg-soft);overflow:hidden;">
                                    <img src="<?php echo e($img); ?>" alt="<?php echo e($f['title']); ?>" style="width:100%;height:100%;object-fit:cover;" loading="lazy">
                                </div>
                                <div style="padding:12px;">
                                    <strong style="display:block;font-size:13px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo e($f['title']); ?></strong>
                                    <span style="display:block;font-size:12px;color:var(--text-mute);margin-top:3px;"><?php echo e(format_price($f['price'], $f['currency'])); ?></span>
                                    <span style="display:inline-block;margin-top:6px;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;background:var(--brand-50);color:var(--brand-700);"><?php echo $f['listing_type'] === 'rent' ? e(t('listings.for_rent')) : e(t('listings.for_sale')); ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- My rental requests -->
            <div class="card" id="rentals" style="padding:22px;margin-bottom:22px;">
                <div class="section__head section__head--compact" style="margin-bottom:16px;">
                    <h2 style="font-size:17px;margin:0;"><?php echo e(t('buyer.rentals_panel_title')); ?></h2>
                </div>
                <?php if (!$rentals): ?>
                    <p style="color:var(--text-mute);font-size:14px;margin:0;"><?php echo e(t('buyer.no_rentals')); ?></p>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                    <table class="dash__table" style="width:100%;border-collapse:collapse;font-size:13px;min-width:560px;">
                        <thead>
                            <tr style="border-bottom:1px solid var(--border);">
                                <th style="text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-mute);"><?php echo e(t('buyer.col_listing')); ?></th>
                                <th style="text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-mute);"><?php echo e(t('buyer.col_dates')); ?></th>
                                <th style="text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-mute);"><?php echo e(t('buyer.col_status')); ?></th>
                                <th style="text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-mute);"><?php echo e(t('buyer.col_requested')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rentals as $r): ?>
                                <tr style="border-bottom:1px solid var(--bg-soft);">
                                    <td style="padding:12px;"><a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$r['listing_id']; ?>" style="color:var(--brand-600);font-weight:600;text-decoration:none;"><?php echo e($r['title']); ?></a></td>
                                    <td style="padding:12px;color:var(--text-soft);"><?php echo e(date('M j, Y', strtotime($r['start_date']))); ?> → <?php echo e(date('M j, Y', strtotime($r['end_date']))); ?></td>
                                    <td style="padding:12px;">
                                        <?php
                                        $statusClass = [
                                            'pending'   => 'background:#fff5dc;color:#cc8507;',
                                            'approved'  => 'background:#eaf9f2;color:#159362;',
                                            'declined'  => 'background:#fff0ef;color:#d75b50;',
                                            'cancelled' => 'background:#f1f4f3;color:#7a8a87;',
                                            'completed' => 'background:#edf5ff;color:#4d83d7;',
                                        ][$r['status']] ?? 'background:var(--bg-soft);color:var(--text-mute);';
                                        ?>
                                        <span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:10.5px;font-weight:800;text-transform:capitalize;<?php echo $statusClass; ?>"><?php echo e($r['status']); ?></span>
                                    </td>
                                    <td style="padding:12px;color:var(--text-mute);font-size:12px;"><?php echo e(time_ago($r['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- My messages to sellers -->
            <div class="card" id="messages" style="padding:22px;">
                <div class="section__head section__head--compact" style="margin-bottom:16px;">
                    <h2 style="font-size:17px;margin:0;"><?php echo e(t('buyer.messages_panel_title')); ?></h2>
                </div>
                <?php if (!$messages): ?>
                    <p style="color:var(--text-mute);font-size:14px;margin:0;"><?php echo e(t('buyer.no_messages')); ?></p>
                <?php else: ?>
                    <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:10px;">
                        <?php foreach ($messages as $m): ?>
                            <li style="padding:14px 16px;border:1px solid var(--border);border-radius:12px;background:var(--bg-card);">
                                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:6px;">
                                    <strong style="font-size:13.5px;color:var(--text);"><?php echo e(t('buyer.msg_to')); ?>: <a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$m['listing_id']; ?>" style="color:var(--brand-600);text-decoration:none;"><?php echo e($m['title']); ?></a></strong>
                                    <small style="color:var(--text-mute);font-size:11.5px;white-space:nowrap;"><?php echo time_ago($m['created_at']); ?></small>
                                </div>
                                <p style="margin:0;color:var(--text-soft);font-size:13px;line-height:1.5;"><?php echo e($m['message']); ?></p>
                                <?php if (!$m['is_read']): ?>
                                    <span style="display:inline-block;margin-top:6px;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;background:var(--brand-50);color:var(--brand-700);"><?php echo e(t('buyer.msg_unread')); ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

        </div>

        <!-- RIGHT COLUMN: quick actions + recommended -->
        <aside>

            <!-- Quick actions -->
            <div class="card" style="padding:20px;margin-bottom:22px;">
                <h2 style="font-size:15px;margin:0 0 14px;"><?php echo e(t('buyer.quick_actions')); ?></h2>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <a href="<?php echo APP_URL; ?>/pages/explore.php" class="card card--hover" style="padding:14px;text-align:center;text-decoration:none;color:var(--text);">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="var(--brand-600)" stroke-width="1.6" style="margin:0 auto 6px;display:block;"><circle cx="11" cy="11" r="7"/><path d="m21 21-4-4" stroke-linecap="round"/></svg>
                        <span style="font-size:12px;font-weight:600;"><?php echo e(t('buyer.qa_browse')); ?></span>
                    </a>
                    <a href="<?php echo APP_URL; ?>/pages/categories.php" class="card card--hover" style="padding:14px;text-align:center;text-decoration:none;color:var(--text);">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="var(--brand-600)" stroke-width="1.6" style="margin:0 auto 6px;display:block;"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                        <span style="font-size:12px;font-weight:600;"><?php echo e(t('buyer.qa_categories')); ?></span>
                    </a>
                    <a href="<?php echo APP_URL; ?>/pages/favorites.php" class="card card--hover" style="padding:14px;text-align:center;text-decoration:none;color:var(--text);">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="var(--brand-600)" stroke-width="1.6" style="margin:0 auto 6px;display:block;"><path d="M12 21s7-4.5 9.5-9A4.5 4.5 0 0 0 12 5.5 4.5 4.5 0 0 0 2.5 12c2.5 4.5 9.5 9 9.5 9z"/></svg>
                        <span style="font-size:12px;font-weight:600;"><?php echo e(t('buyer.qa_favorites')); ?></span>
                    </a>
                    <a href="<?php echo APP_URL; ?>/pages/notifications.php" class="card card--hover" style="padding:14px;text-align:center;text-decoration:none;color:var(--text);">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="var(--brand-600)" stroke-width="1.6" style="margin:0 auto 6px;display:block;"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/></svg>
                        <span style="font-size:12px;font-weight:600;"><?php echo e(t('buyer.qa_notifications')); ?></span>
                    </a>
                    <?php if (!is_admin()): ?>
                    <a href="<?php echo APP_URL; ?>/pages/register.php?type=seller" class="card card--hover" style="padding:14px;text-align:center;text-decoration:none;color:var(--text);grid-column:span 2;background:linear-gradient(135deg,var(--brand-50),var(--bg-card));border-color:var(--brand-200);">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="var(--brand-600)" stroke-width="1.6" style="margin:0 auto 6px;display:block;"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" stroke-linecap="round" stroke-linejoin="round"/><circle cx="8.5" cy="7" r="4"/></svg>
                        <span style="font-size:12.5px;font-weight:700;color:var(--brand-700);"><?php echo e(t('buyer.qa_become_seller')); ?></span>
                    </a>
                    <?php endif; ?>
                    <a href="<?php echo APP_URL; ?>/pages/profile.php" class="card card--hover" style="padding:14px;text-align:center;text-decoration:none;color:var(--text);grid-column:span 2;">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="var(--brand-600)" stroke-width="1.6" style="margin:0 auto 6px;display:block;"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                        <span style="font-size:12px;font-weight:600;"><?php echo e(t('buyer.qa_profile')); ?></span>
                    </a>
                </div>
            </div>

            <!-- Recommended for you -->
            <div class="card" style="padding:20px;">
                <h2 style="font-size:15px;margin:0 0 14px;"><?php echo e(t('buyer.recommended_title')); ?></h2>
                <?php if (!$recommended): ?>
                    <p style="color:var(--text-mute);font-size:13px;margin:0;"><?php echo e(t('buyer.no_recommendations')); ?></p>
                <?php else: ?>
                    <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:12px;">
                        <?php foreach ($recommended as $r):
                            $img = image_or_default($r['image'] ?? null, 'assets/images/placeholders/default.svg');
                            $url = APP_URL . '/pages/listing-details.php?id=' . (int)$r['id'];
                        ?>
                            <li>
                                <a href="<?php echo e($url); ?>" style="display:flex;gap:10px;text-decoration:none;color:inherit;align-items:center;">
                                    <img src="<?php echo e($img); ?>" alt="" style="width:56px;height:56px;border-radius:10px;object-fit:cover;flex:0 0 56px;background:var(--bg-soft);">
                                    <div style="min-width:0;flex:1;">
                                        <strong style="display:block;font-size:13px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo e($r['title']); ?></strong>
                                        <span style="display:block;font-size:12px;color:var(--brand-600);font-weight:600;margin-top:2px;"><?php echo e(format_price($r['price'], $r['currency'])); ?></span>
                                        <span style="display:block;font-size:11px;color:var(--text-mute);margin-top:2px;"><?php echo e($r['location'] ?? ''); ?></span>
                                    </div>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

        </aside>
    </div>
</div>

<style>
/* Responsive: stack the two-column grid on small screens */
@media (max-width: 900px) {
    .buyer-grid { grid-template-columns: 1fr !important; }
    .buyer-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
    .buyer-fav-grid { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
}
@media (max-width: 520px) {
    .buyer-kpis { grid-template-columns: 1fr !important; }
    .buyer-fav-grid { grid-template-columns: 1fr !important; }
}
</style>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
