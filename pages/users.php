<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

function fetch_all_public_users(): array {
    $stmt = db()->query("SELECT u.id,u.full_name,u.email,u.phone,u.location,u.bio,u.whatsapp_number,u.avatar_path,u.is_verified,u.is_seller,u.last_seen_at,u.created_at,sp.business_name,sp.social_whatsapp AS seller_whatsapp
        FROM users u LEFT JOIN seller_profiles sp ON sp.user_id=u.id
        WHERE u.status='active'
        ORDER BY (u.last_seen_at >= (NOW() - INTERVAL 5 MINUTE)) DESC, COALESCE(u.last_seen_at,u.created_at) DESC,u.full_name ASC");
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['online'] = !empty($r['last_seen_at']) && strtotime($r['last_seen_at']) >= time() - 300;
        $r['whatsapp'] = trim((string)($r['whatsapp_number'] ?: $r['seller_whatsapp']));
    }
    unset($r);
    return $rows;
}
$users = fetch_all_public_users();
$pageTitle = 'People on Isoko Ryacu';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container section--tight community-directory">
    <div class="community-directory__hero">
        <div>
            <span class="community-users-eyebrow"><span class="community-users-eyebrow__dot"></span> ISOKO RYACU COMMUNITY</span>
            <h1 class="section__title">People on Isoko Ryacu</h1>
            <p class="community-directory__lead">Connect with real members, sellers and buyers using the marketplace.</p>
        </div>
        <div class="community-directory__count"><strong><?php echo count($users); ?></strong><span>active members</span></div>
    </div>

    <?php if ($users): ?>
    <div class="community-directory__list">
        <?php foreach ($users as $person):
            $avatar = !empty($person['avatar_path']) ? image_or_default($person['avatar_path']) : '';
            $initial = strtoupper(mb_substr($person['full_name'], 0, 1));
            $wa = preg_replace('/[^0-9]/', '', (string)$person['whatsapp']);
        ?>
        <article class="community-directory-row">
            <a class="community-directory-row__person" href="<?php echo APP_URL; ?>/pages/user-profile.php?id=<?php echo (int)$person['id']; ?>">
                <span class="community-directory-row__avatar-wrap">
                    <?php if ($avatar): ?><img class="community-directory-row__avatar" src="<?php echo e($avatar); ?>" alt="<?php echo e($person['full_name']); ?>" loading="lazy"><?php else: ?><span class="community-directory-row__avatar community-directory-row__avatar--initial"><?php echo e($initial); ?></span><?php endif; ?>
                    <span class="community-directory-row__status <?php echo $person['online'] ? 'is-online' : 'is-inactive'; ?>"></span>
                </span>
                <span class="community-directory-row__identity">
                    <span class="community-directory-row__name"><?php echo e($person['full_name']); ?><?php if ((int)$person['is_verified'] === 1): ?><span class="community-directory-row__verified">✓</span><?php endif; ?></span>
                    <span class="community-directory-row__sub"><?php echo $person['online'] ? 'Online now' : 'Inactive'; ?><?php if ($person['is_seller']): ?> <span>•</span> Seller<?php endif; ?><?php if ($person['location']): ?> <span>•</span> <?php echo e($person['location']); ?><?php endif; ?></span>
                </span>
            </a>
            <span class="community-directory-row__contact"><?php if ($person['phone']): ?><span class="community-directory-row__phone">☎ <?php echo e($person['phone']); ?></span><?php endif; ?><?php if ($wa): ?><a class="community-directory-row__wa" href="https://wa.me/<?php echo e($wa); ?>" target="_blank" rel="noopener noreferrer" title="WhatsApp"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 3.5A11.9 11.9 0 0 0 12.05 0C5.49 0 .15 5.34.15 11.9c0 2.1.55 4.15 1.6 5.96L.04 24l6.28-1.65a11.9 11.9 0 0 0 5.73 1.46h.01c6.56 0 11.9-5.34 11.9-11.9a11.9 11.9 0 0 0-3.46-8.41ZM12.06 21.8h-.01a9.9 9.9 0 0 1-5.05-1.38l-.36-.21-3.73.98 1-3.64-.23-.37a9.9 9.9 0 1 1 8.38 4.62Zm5.43-7.42c-.3-.15-1.77-.87-2.05-.97-.28-.1-.49-.15-.69.15-.2.3-.79.97-.97 1.17-.18.2-.36.22-.66.07-.3-.15-1.25-.46-2.38-1.47-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.61.14-.14.3-.36.44-.54.15-.18.2-.31.3-.51.1-.2.05-.38-.03-.53-.07-.15-.69-1.66-.95-2.28-.25-.6-.5-.52-.69-.53h-.59c-.2 0-.53.07-.81.38-.28.3-1.06 1.04-1.06 2.54s1.09 2.95 1.24 3.15c.15.2 2.14 3.27 5.19 4.59.73.32 1.3.51 1.75.65.74.24 1.41.2 1.94.12.59-.09 1.77-.72 2.02-1.41.25-.69.25-1.28.18-1.41-.07-.13-.28-.2-.58-.35Z"/></svg><span>WhatsApp</span></a><?php endif; ?><a class="community-directory-row__arrow" href="<?php echo APP_URL; ?>/pages/user-profile.php?id=<?php echo (int)$person['id']; ?>" aria-label="View profile"><svg viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></a></span>
        </article>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <div class="empty-state"><p>No active users to display yet.</p></div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
