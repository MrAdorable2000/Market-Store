<?php
/**
 * pages/cart.php — Shopping cart
 * --------------------------------------------------------------------
 * Session-authenticated cart stored in the `cart_items` table so it
 * survives across devices. Each cart item references a listing.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_login();

$uid = (int) current_user()['id'];
$pdo = db();

// --- Handle POST actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        flash_set('error', t('errors.invalid_token'));
        redirect(APP_URL . '/pages/cart.php');
    }
    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'add':
            $listingId = (int)($_POST['listing_id'] ?? 0);
            $qty = max(1, (int)($_POST['quantity'] ?? 1));
            if ($listingId) {
                // Verify the listing exists, is active, and is for sale (not rent)
                $stmt = $pdo->prepare("SELECT id, seller_id, title, price, currency, status, availability, listing_type FROM listings WHERE id = ?");
                $stmt->execute([$listingId]);
                $listing = $stmt->fetch();
                if (!$listing || $listing['status'] !== 'active') {
                    flash_set('error', 'Listing not available.');
                    redirect(APP_URL . '/pages/listing-details.php?id=' . $listingId);
                }
                if ($listing['listing_type'] !== 'sell') {
                    flash_set('error', 'This item is for rent, not for sale. Use the rental request button instead.');
                    redirect(APP_URL . '/pages/listing-details.php?id=' . $listingId);
                }
                if ((int)$listing['seller_id'] === $uid) {
                    flash_set('error', 'You cannot buy your own listing.');
                    redirect(APP_URL . '/pages/listing-details.php?id=' . $listingId);
                }
                // Insert or update cart item
                $pdo->prepare(
                    'INSERT INTO cart_items (user_id, listing_id, quantity) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE quantity = quantity + ?'
                )->execute([$uid, $listingId, $qty, $qty]);
                flash_set('success', 'Added to cart.');
            }
            break;
        case 'update':
            $itemId = (int)($_POST['cart_item_id'] ?? 0);
            $qty = max(1, (int)($_POST['quantity'] ?? 1));
            $pdo->prepare('UPDATE cart_items SET quantity = ? WHERE id = ? AND user_id = ?')->execute([$qty, $itemId, $uid]);
            break;
        case 'remove':
            $itemId = (int)($_POST['cart_item_id'] ?? 0);
            $pdo->prepare('DELETE FROM cart_items WHERE id = ? AND user_id = ?')->execute([$itemId, $uid]);
            break;
        case 'clear':
            $pdo->prepare('DELETE FROM cart_items WHERE user_id = ?')->execute([$uid]);
            break;
    }
    redirect(APP_URL . '/pages/cart.php');
}

// --- Load cart items ---
$stmt = $pdo->prepare(
    "SELECT ci.id AS cart_id, ci.quantity,
            l.id AS listing_id, l.title, l.slug, l.price, l.currency, l.availability, l.status,
            l.seller_id,
            u.full_name AS seller_name,
            (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image
       FROM cart_items ci
       INNER JOIN listings l ON l.id = ci.listing_id
       INNER JOIN users u ON u.id = l.seller_id
      WHERE ci.user_id = ?
      ORDER BY ci.created_at DESC"
);
$stmt->execute([$uid]);
$cartItems = $stmt->fetchAll();

// Group by seller (each seller = one potential order)
$cartBySeller = [];
$subtotal = 0.0;
foreach ($cartItems as $item) {
    $sid = (int)$item['seller_id'];
    if (!isset($cartBySeller[$sid])) {
        $cartBySeller[$sid] = ['seller_name' => $item['seller_name'], 'seller_id' => $sid, 'items' => [], 'subtotal' => 0];
    }
    $itemTotal = (float)$item['price'] * (int)$item['quantity'];
    $cartBySeller[$sid]['items'][] = $item;
    $cartBySeller[$sid]['subtotal'] += $itemTotal;
    $subtotal += $itemTotal;
}

$pageTitle = 'Shopping Cart';
$activePage = 'cart';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container" style="max-width:960px;padding:24px 20px 48px;">
    <div style="margin-bottom:14px;"><?php echo back_button(APP_URL . '/', 'Continue shopping', 'solid'); ?></div>
    <h1 style="font-size:24px;font-weight:800;margin:0 0 20px;letter-spacing:-.02em;">Shopping Cart</h1>

    <?php if (empty($cartItems)): ?>
        <div class="card" style="padding:48px 24px;text-align:center;">
            <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="var(--text-mute)" stroke-width="1.3" style="margin:0 auto 12px;display:block;opacity:.4;"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/></svg>
            <h3 style="margin:0 0 6px;font-size:16px;color:var(--text);">Your cart is empty</h3>
            <p style="margin:0 0 16px;font-size:13.5px;color:var(--text-mute);">Browse the marketplace and add items you like.</p>
            <a href="<?php echo APP_URL; ?>/pages/explore.php" class="btn btn--primary">Explore Marketplace</a>
        </div>
    <?php else: ?>
        <div style="display:grid;grid-template-columns:1fr 320px;gap:24px;align-items:start;" class="cart-layout">
            <!-- Cart items -->
            <div>
                <?php foreach ($cartBySeller as $group): ?>
                    <div class="card" style="padding:18px;margin-bottom:16px;">
                        <div style="font-size:12px;font-weight:700;color:var(--text-mute);text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px;">
                            Seller: <?php echo e($group['seller_name']); ?>
                        </div>
                        <?php foreach ($group['items'] as $item):
                            $img = image_or_default($item['image'] ?? null);
                            $itemTotal = (float)$item['price'] * (int)$item['quantity'];
                            $unavailable = ($item['status'] !== 'active' || $item['availability'] !== 'available');
                        ?>
                            <div style="display:flex;gap:14px;align-items:center;padding:12px 0;border-bottom:1px solid var(--bg-soft);" class="cart-row">
                                <img src="<?php echo e($img); ?>" alt="" style="width:72px;height:72px;border-radius:10px;object-fit:cover;flex:0 0 72px;background:var(--bg-soft);">
                                <div style="flex:1;min-width:0;">
                                    <a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$item['listing_id']; ?>" style="font-size:14px;font-weight:600;color:var(--text);text-decoration:none;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                        <?php echo e($item['title']); ?>
                                    </a>
                                    <div style="font-size:13px;color:var(--brand-600);font-weight:700;margin-top:3px;"><?php echo e(format_price($item['price'], $item['currency'])); ?></div>
                                    <?php if ($unavailable): ?>
                                        <span style="display:inline-block;margin-top:4px;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;background:#fce5e3;color:#d9534a;">Unavailable</span>
                                    <?php endif; ?>
                                    <form method="post" action="" style="display:inline-flex;align-items:center;gap:6px;margin-top:8px;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="cart_item_id" value="<?php echo (int)$item['cart_id']; ?>">
                                        <input type="number" name="quantity" value="<?php echo (int)$item['quantity']; ?>" min="1" max="99" style="width:56px;padding:5px 8px;border:1px solid var(--border);border-radius:8px;font-size:13px;" onchange="this.form.submit()">
                                    </form>
                                </div>
                                <div style="text-align:right;">
                                    <div style="font-size:14px;font-weight:700;color:var(--text);"><?php echo e(format_price($itemTotal, $item['currency'])); ?></div>
                                    <form method="post" action="" style="margin-top:8px;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="remove">
                                        <input type="hidden" name="cart_item_id" value="<?php echo (int)$item['cart_id']; ?>">
                                        <button type="submit" style="background:none;border:0;color:var(--text-mute);cursor:pointer;font-size:12px;text-decoration:underline;padding:4px;">Remove</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div style="text-align:right;padding-top:10px;font-size:13px;color:var(--text-mute);">
                            Subtotal from this seller: <strong style="color:var(--text);"><?php echo e(format_price($group['subtotal'], 'RWF')); ?></strong>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Order summary -->
            <aside style="position:sticky;top:80px;">
                <div class="card" style="padding:20px;">
                    <h3 style="margin:0 0 14px;font-size:15px;font-weight:800;">Order Summary</h3>
                    <div style="display:flex;justify-content:space-between;font-size:13.5px;color:var(--text-soft);margin-bottom:8px;">
                        <span>Subtotal</span><strong style="color:var(--text);"><?php echo e(format_price($subtotal, 'RWF')); ?></strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:13.5px;color:var(--text-soft);margin-bottom:8px;">
                        <span>Delivery</span><span style="color:var(--text-mute);">Calculated at checkout</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:13.5px;color:var(--text-soft);margin-bottom:8px;">
                        <span>Platform fee</span><span style="color:var(--text-mute);">Calculated at checkout</span>
                    </div>
                    <hr style="border:0;border-top:1px solid var(--border);margin:12px 0;">
                    <div style="display:flex;justify-content:space-between;font-size:16px;font-weight:800;color:var(--text);">
                        <span>Estimated total</span><span><?php echo e(format_price($subtotal, 'RWF')); ?></span>
                    </div>
                    <a href="<?php echo APP_URL; ?>/pages/checkout.php" class="btn btn--primary btn--block btn--lg" style="margin-top:16px;">Proceed to Checkout</a>
                    <form method="post" action="" style="margin-top:10px;text-align:center;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="clear">
                        <button type="submit" style="background:none;border:0;color:var(--text-mute);cursor:pointer;font-size:12px;text-decoration:underline;">Clear cart</button>
                    </form>
                </div>
            </aside>
        </div>
    <?php endif; ?>
</div>

<style>
@media (max-width: 760px) {
    .cart-layout { grid-template-columns: 1fr !important; }
    .cart-layout aside { position: static !important; }
    .cart-row { flex-wrap: wrap; }
}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
