<?php
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { redirect(APP_URL . '/pages/users.php'); }
$st = db()->prepare("SELECT u.id,u.full_name,u.email,u.phone,u.whatsapp_number,u.location,u.bio,u.avatar_path,u.is_verified,u.is_seller,u.last_seen_at,u.created_at,sp.business_name,sp.social_whatsapp FROM users u LEFT JOIN seller_profiles sp ON sp.user_id=u.id WHERE u.id=? AND u.status='active' LIMIT 1");
$st->execute([$id]);
$u = $st->fetch();
if (!$u) { http_response_code(404); require __DIR__.'/404.php'; exit; }
$online = !empty($u['last_seen_at']) && strtotime($u['last_seen_at']) >= time()-300;
$wa = trim((string)($u['whatsapp_number'] ?: $u['social_whatsapp']));
$digits = preg_replace('/[^0-9]/','',$wa);
$avatar = $u['avatar_path'] ? image_or_default($u['avatar_path']) : '';
$cntStmt = db()->prepare('SELECT COUNT(*) FROM listings WHERE seller_id=? AND status="active"');
$cntStmt->execute([$id]);
$listingCount = (int)$cntStmt->fetchColumn();
$products = [];
if ((int)$u['is_seller'] === 1) {
    $prodStmt = db()->prepare('SELECT id,title,price,currency,image_path,location FROM listings WHERE seller_id=? AND status="active" ORDER BY created_at DESC LIMIT 6');
    $prodStmt->execute([$id]);
    $products = $prodStmt->fetchAll();
}
$pageTitle = $u['full_name'];
require_once __DIR__.'/../includes/header.php';
?>
<div class="container section--tight public-profile">
    <a class="public-profile__back" href="<?php echo APP_URL; ?>/pages/users.php">← People</a>
    <section class="public-profile__hero card">
        <div class="public-profile__cover"></div>
        <div class="public-profile__main">
            <div class="public-profile__avatar-wrap">
                <?php if ($avatar): ?><img class="public-profile__avatar" src="<?php echo e($avatar); ?>" alt="<?php echo e($u['full_name']); ?>"><?php else: ?><span class="public-profile__avatar public-profile__avatar--initial"><?php echo e(strtoupper(mb_substr($u['full_name'],0,1))); ?></span><?php endif; ?>
                <span class="public-profile__status <?php echo $online?'is-online':'is-inactive'; ?>"></span>
            </div>
            <div class="public-profile__identity">
                <div class="public-profile__name-row"><h1><?php echo e($u['full_name']); ?></h1><?php if ($u['is_verified']): ?><span class="public-profile__verified">✓ Verified</span><?php endif; ?></div>
                <div class="public-profile__presence"><span class="public-profile__presence-dot <?php echo $online?'is-online':'is-inactive'; ?>"></span><?php echo $online?'Online now':'Inactive'; ?><?php if ($u['is_seller']): ?> <span>• Seller</span><?php endif; ?></div>
                <?php if ($u['location']): ?><div class="public-profile__location">⌖ <?php echo e($u['location']); ?></div><?php endif; ?>
            </div>
            <div class="public-profile__actions">
                <?php if ($digits): ?><a class="btn btn--primary" href="https://wa.me/<?php echo e($digits); ?>" target="_blank" rel="noopener noreferrer">WhatsApp</a><?php endif; ?>
                <?php if ($u['phone']): ?><a class="btn btn--ghost" href="tel:<?php echo e($u['phone']); ?>">Call</a><?php endif; ?>
            </div>
        </div>
        <div class="public-profile__body">
            <?php if ($u['bio']): ?><div class="public-profile__about"><h2>About</h2><p><?php echo nl2br(e($u['bio'])); ?></p></div><?php endif; ?>
            <div class="public-profile__facts">
                <?php if ($u['is_seller']): ?><div><strong><?php echo $listingCount; ?></strong><span>Active products</span></div><?php endif; ?>
                <div><strong><?php echo e(date('M Y', strtotime($u['created_at']))); ?></strong><span>Member since</span></div>
                <?php if ($u['phone']): ?><div><strong><?php echo e($u['phone']); ?></strong><span>Contact</span></div><?php endif; ?>
                <?php if ($wa): ?><div><strong>WhatsApp</strong><span>Available</span></div><?php endif; ?>
            </div>
        </div>
    </section>

    <?php if ($products): ?>
    <section class="public-profile__products">
        <div class="public-profile__section-head"><div><span class="community-users-eyebrow">PRODUCTS</span><h2>Products from <?php echo e($u['full_name']); ?></h2></div><a href="<?php echo APP_URL; ?>/pages/explore.php?seller=<?php echo (int)$id; ?>">View all →</a></div>
        <div class="public-profile__product-grid">
            <?php foreach ($products as $product): $pimg = !empty($product['image_path']) ? image_or_default($product['image_path']) : ''; ?>
            <a class="public-profile__product" href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$product['id']; ?>">
                <div class="public-profile__product-image"><?php if ($pimg): ?><img src="<?php echo e($pimg); ?>" alt="<?php echo e($product['title']); ?>" loading="lazy"><?php else: ?><span>No image</span><?php endif; ?></div>
                <div class="public-profile__product-info"><strong><?php echo e($product['title']); ?></strong><span><?php echo e(number_format((float)$product['price'], 0)); ?> <?php echo e($product['currency'] ?? 'RWF'); ?></span></div>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
    </section>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
