<?php
/**
 * pages/seller/listings.php — Seller product management
 * --------------------------------------------------------------------
 * Shows the seller's listings in a professional card-grid layout with:
 *   - Product image, title, price, status badge, availability
 *   - Views, favorites, quality score
 *   - Actions: View, Edit, Mark Sold/Rented, Relist, Delete
 *   - Empty state when no listings
 *   - Consistent .udash design system (same as dashboard + other seller pages)
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/smart_features.php';
require_once __DIR__ . '/../../includes/seller_sidebar.php';
require_login();

$uid = (int) current_user()['id'];

// Some columns (sku, stock_quantity, reserved_quantity, low_stock_threshold,
// is_published, published_at) only exist after optional migrations have
// been applied. Select them only when present so this page still renders
// on a bare schema.sql install, instead of a fatal PDOException.
$listingsCols = db_table_columns(db(), 'listings');
$optionalCols = ['sku', 'stock_quantity', 'reserved_quantity', 'low_stock_threshold', 'is_published', 'published_at'];
$selectExtra = '';
foreach ($optionalCols as $col) {
    if (isset($listingsCols[$col])) {
        $selectExtra .= ", l.$col";
    }
}

$stmt = db()->prepare(
    'SELECT l.id, l.title, l.price, l.currency, l.listing_type, l.availability, l.status,
            l.views_count, l.favorites_count, l.created_at, l.location, l.description' . $selectExtra . ',
            l.subcategory_id, l.price_per_month, l.price_per_week, l.price_per_day,
            (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image,
            (SELECT COUNT(*) FROM listing_images li WHERE li.listing_id = l.id) AS images_count,
            (SELECT COUNT(*) FROM listing_attributes la WHERE la.listing_id = l.id) AS attrs_count
     FROM listings l
     WHERE l.seller_id = ?
     ORDER BY l.created_at DESC'
);
$stmt->execute([$uid]);
$items = $stmt->fetchAll();

// Listing Quality Score for every own listing
$sellerPhone = (string) (current_user()['phone'] ?? '');
$qualities = [];
foreach ($items as $r) {
    $r['seller_phone'] = $sellerPhone;
    $qualities[(int) $r['id']] = listing_quality_score($r);
}

// Count for header
$totalListings = count($items);
$activeListings = count(array_filter($items, fn($r) => $r['status'] === 'active' && $r['availability'] === 'available' && (int)($r['is_published'] ?? 1) === 1));
$draftProducts = count(array_filter($items, fn($r) => $r['status'] === 'draft'));
$archivedProducts = count(array_filter($items, fn($r) => $r['status'] === 'archived'));
$outOfStock = count(array_filter($items, fn($r) => $r['listing_type'] === 'sell' && (int)($r['stock_quantity'] ?? 0) <= (int)($r['reserved_quantity'] ?? 0)));

$pageTitle = 'My Products';
$activePage = 'dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>

<?php seller_page_start('My Listings', $uid, db()); ?>

    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
        <div>
            <h1 style="font-size:22px;font-weight:800;margin:0;letter-spacing:-.02em;">My Listings</h1>
            <p style="font-size:13px;color:var(--text-mute);margin:4px 0 0;">
                <?php echo $totalListings > 0 ? "{$totalListings} product(s) • {$activeListings} active • {$outOfStock} out of stock" : 'Manage your products, inventory, visibility and sales status here.'; ?>
            </p>
        </div>
        <a href="<?php echo APP_URL; ?>/pages/sell.php" class="btn btn--primary">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>
            New Product
        </a>
    </div>

    <?php if (!$items): ?>
        <div class="card" style="padding:48px 24px;text-align:center;">
            <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="var(--text-mute)" stroke-width="1.3" style="opacity:.4;margin-bottom:12px;"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/></svg>
            <h3 style="margin:0 0 6px;font-size:16px;color:var(--text);">No listings yet</h3>
            <p style="margin:0 0 16px;font-size:13.5px;color:var(--text-mute);">Create your first listing to start selling on Isoko Ryacu.</p>
            <a href="<?php echo APP_URL; ?>/pages/sell.php" class="btn btn--primary">Create Listing</a>
        </div>
    <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;">
            <?php foreach ($items as $r):
                $img = image_or_default($r['image']);
                $qq = $qualities[(int)$r['id']];
                $statusColor = $r['status'] === 'active' ? 'good' : (in_array($r['status'], ['pending','draft'], true) ? 'warn' : ($r['status'] === 'archived' ? 'mute' : 'bad'));
                $availColor = $r['availability'] === 'available' ? 'brand' : 'mute';
            ?>
                <div class="card" style="padding:0;overflow:hidden;display:flex;flex-direction:column;">
                    <!-- Image -->
                    <div style="position:relative;aspect-ratio:4/3;background:var(--bg-soft);overflow:hidden;">
                        <a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$r['id']; ?>">
                            <img src="<?php echo e($img); ?>" alt="<?php echo e($r['title']); ?>" style="width:100%;height:100%;object-fit:cover;" loading="lazy">
                        </a>
                        <span class="udash__pill udash__pill--<?php echo $statusColor; ?>" style="position:absolute;top:8px;left:8px;"><?php echo e($r['status']); ?></span>
                        <?php if ($r['availability'] !== 'available'): ?>
                            <span class="udash__pill udash__pill--<?php echo $availColor; ?>" style="position:absolute;top:8px;right:8px;"><?php echo e($r['availability']); ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- Body -->
                    <div style="padding:14px;flex:1;display:flex;flex-direction:column;gap:8px;">
                        <a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$r['id']; ?>" style="font-size:14px;font-weight:600;color:var(--text);text-decoration:none;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <?php echo e($r['title']); ?>
                        </a>
                        <div style="font-size:15px;font-weight:800;color:var(--brand-600);"><?php echo e(format_price($r['price'], $r['currency'])); ?></div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;font-size:11px;color:var(--text-mute);">
                            <?php if (!empty($r['sku'])): ?><span>SKU <?php echo e($r['sku']); ?></span><?php endif; ?>
                            <?php if ($r['listing_type'] === 'sell'): ?><span>Stock <?php echo max(0, (int)$r['stock_quantity'] - (int)$r['reserved_quantity']); ?></span><?php endif; ?>
                            <?php if (!(int)($r['is_published'] ?? 1)): ?><span class="udash__pill udash__pill--mute">Unpublished</span><?php endif; ?>
                        </div>

                        <!-- Stats -->
                        <div style="display:flex;gap:12px;font-size:11.5px;color:var(--text-mute);">
                            <span title="Views">👁 <?php echo (int)$r['views_count']; ?></span>
                            <span title="Favorites">♥ <?php echo (int)$r['favorites_count']; ?></span>
                            <span title="Images">📷 <?php echo (int)$r['images_count']; ?></span>
                            <span title="Posted"><?php echo e(time_ago($r['created_at'])); ?></span>
                        </div>

                        <!-- Quality score -->
                        <?php if (isset($qq['score'])): ?>
                        <div style="font-size:11px;color:var(--text-mute);">
                            Quality: <strong style="color:<?php echo $qq['score'] >= 70 ? 'var(--brand-600)' : ($qq['score'] >= 40 ? '#c4730a' : '#d9534a'); ?>"><?php echo (int)$qq['score']; ?>/100</strong>
                        </div>
                        <?php endif; ?>

                        <!-- Actions -->
                        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:auto;padding-top:8px;border-top:1px solid var(--bg-soft);">
                            <a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$r['id']; ?>" class="btn btn--outline btn--sm" title="View">View</a>
                            <a href="<?php echo APP_URL; ?>/pages/seller/edit-product.php?id=<?php echo (int)$r['id']; ?>" class="btn btn--outline btn--sm">Edit</a>
                            <?php if ($r['status'] === 'active'): ?>
                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/listings-action/index.php" style="display:inline;"><?php echo csrf_field(); ?><input type="hidden" name="listing_id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="action" value="<?php echo (int)($r['is_published'] ?? 1) ? 'unpublish' : 'publish'; ?>"><button type="submit" class="btn btn--outline btn--sm"><?php echo (int)($r['is_published'] ?? 1) ? 'Unpublish' : 'Publish'; ?></button></form>
                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/listings-action/index.php" style="display:inline;"><?php echo csrf_field(); ?><input type="hidden" name="listing_id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="action" value="archive"><button type="submit" class="btn btn--outline btn--sm">Archive</button></form>
                            <?php elseif ($r['status'] === 'archived'): ?>
                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/listings-action/index.php" style="display:inline;"><?php echo csrf_field(); ?><input type="hidden" name="listing_id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="action" value="restore"><button type="submit" class="btn btn--outline btn--sm">Restore</button></form>
                            <?php endif; ?>

                            <?php if ($r['status'] === 'active' && $r['availability'] === 'available'): ?>
                                <?php if ($r['listing_type'] === 'sell'): ?>
                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/listings-action/index.php" style="display:inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="listing_id" value="<?php echo (int)$r['id']; ?>">
                                    <input type="hidden" name="action" value="mark_sold">
                                    <button class="btn btn--ghost btn--sm" type="submit">Mark Sold</button>
                                </form>
                                <?php else: ?>
                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/listings-action/index.php" style="display:inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="listing_id" value="<?php echo (int)$r['id']; ?>">
                                    <input type="hidden" name="action" value="mark_rented">
                                    <button class="btn btn--ghost btn--sm" type="submit">Mark Rented</button>
                                </form>
                                <?php endif; ?>
                            <?php elseif ($r['availability'] !== 'available'): ?>
                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/listings-action/index.php" style="display:inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="listing_id" value="<?php echo (int)$r['id']; ?>">
                                    <input type="hidden" name="action" value="relist">
                                    <button class="btn btn--ghost btn--sm" type="submit">Relist</button>
                                </form>
                            <?php endif; ?>

                            <form method="post" action="<?php echo APP_URL; ?>/api/v1/listings-action/index.php" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this listing? This cannot be undone.');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="listing_id" value="<?php echo (int)$r['id']; ?>">
                                <input type="hidden" name="action" value="delete">
                                <button class="btn btn--ghost btn--sm" type="submit" style="color:#d9534a;" title="Delete">🗑</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php seller_page_end(); ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
