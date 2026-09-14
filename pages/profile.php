<?php
/**
 * Isoko Ryacu — Unified account profile
 * Works for USER, SELLER, ADMIN and SUPER_ADMIN.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

require_login();
$uid = (int) current_user()['id'];
$pdo = db();
ensure_profile_avatar_storage($pdo);
$errors = [];

/* Optional-table/column-safe query helpers keep this page compatible with
 * databases that have not yet installed every marketplace upgrade. */
$scalar = static function (string $sql, array $params = [], $fallback = 0) use ($pdo) {
    try { $st = $pdo->prepare($sql); $st->execute($params); $v = $st->fetchColumn(); return $v === false || $v === null ? $fallback : $v; }
    catch (Throwable $e) { return $fallback; }
};
$rows = static function (string $sql, array $params = []) use ($pdo): array {
    try { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchAll() ?: []; }
    catch (Throwable $e) { return []; }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = t('errors.invalid_token');
    } else {
        $action = $_POST['action'] ?? '';
        try {
            switch ($action) {
                case 'update_account':
                    $fullName = trim((string)($_POST['full_name'] ?? ''));
                    $phone = trim((string)($_POST['phone'] ?? ''));
                    $location = trim((string)($_POST['location'] ?? ''));
                    $bio = trim((string)($_POST['bio'] ?? ''));
                    $whatsapp = trim((string)($_POST['whatsapp_number'] ?? ''));
                    if (mb_strlen($fullName) < 3) $errors[] = t('errors.name_short');
                    if (mb_strlen($fullName) > 120) $errors[] = 'Name is too long.';
                    if (!$errors) {
                        $pdo->prepare('UPDATE users SET full_name=?, phone=?, whatsapp_number=?, location=?, bio=?, updated_at=NOW() WHERE id=?')
                            ->execute([$fullName, $phone ?: null, $whatsapp ?: null, $location ?: null, $bio ?: null, $uid]);
                        $_SESSION['user']['full_name'] = $fullName;
                        flash_set('success', t('flash.profile_updated'));
                        redirect(APP_URL . '/pages/profile.php#profile');
                    }
                    break;

                case 'upload_avatar':
                    // Store profile photos in MySQL as a fallback that does not
                    // depend on Apache being allowed to write into assets/uploads.
                    if (empty($_FILES['avatar'])) {
                        $errors[] = 'Please select a profile photo.';
                        break;
                    }
                    $file = $_FILES['avatar'];
                    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
                    $uploadErrors = [
                        UPLOAD_ERR_INI_SIZE   => 'The selected photo is larger than the PHP upload limit on this server.',
                        UPLOAD_ERR_FORM_SIZE  => 'The selected photo is larger than the form upload limit.',
                        UPLOAD_ERR_PARTIAL    => 'The photo upload was interrupted. Please try again.',
                        UPLOAD_ERR_NO_FILE    => 'Please select a profile photo.',
                        UPLOAD_ERR_NO_TMP_DIR => 'PHP temporary upload folder is missing or unavailable.',
                        UPLOAD_ERR_CANT_WRITE => 'PHP cannot write the temporary uploaded file. Check PHP upload_tmp_dir permissions.',
                        UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the photo upload.',
                    ];
                    if ($uploadError !== UPLOAD_ERR_OK) {
                        $errors[] = $uploadErrors[$uploadError] ?? 'The photo upload failed. Please try again.';
                        break;
                    }
                    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                        $errors[] = 'The uploaded photo could not be verified by the server.';
                        break;
                    }
                    if ((int)$file['size'] <= 0) {
                        $errors[] = 'The selected photo is empty.';
                        break;
                    }
                    if ((int)$file['size'] > MAX_UPLOAD_BYTES) {
                        $errors[] = 'Image is too large. Maximum size is ' . round(MAX_UPLOAD_BYTES / 1024 / 1024, 1) . ' MB.';
                        break;
                    }
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
                    if ($finfo) finfo_close($finfo);
                    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
                    $imageInfo = @getimagesize($file['tmp_name']);
                    if (!in_array($mime, $allowed, true) || !$imageInfo) {
                        $errors[] = 'Invalid image. Please use a real JPG, PNG, or WebP photo.';
                        break;
                    }

                    $blob = file_get_contents($file['tmp_name']);
                    $storedMime = (string)$mime;
                    // Prefer a normalized JPEG blob when GD is available.
                    if ($blob !== false && function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
                        $src = @imagecreatefromstring($blob);
                        if ($src) {
                            $max = 1200;
                            $w = imagesx($src); $h = imagesy($src);
                            $scale = ($w > $max || $h > $max) ? min($max / $w, $max / $h) : 1;
                            $nw = max(1, (int)round($w * $scale));
                            $nh = max(1, (int)round($h * $scale));
                            $dst = imagecreatetruecolor($nw, $nh);
                            imagealphablending($dst, true); imagesavealpha($dst, false);
                            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
                            ob_start();
                            $ok = @imagejpeg($dst, null, 86);
                            $jpeg = ob_get_clean();
                            imagedestroy($dst); imagedestroy($src);
                            if ($ok && $jpeg !== false && $jpeg !== '') {
                                $blob = $jpeg;
                                $storedMime = 'image/jpeg';
                            }
                        }
                    }
                    if ($blob === false || $blob === '') {
                        $errors[] = 'The server could not read the selected photo.';
                        break;
                    }

                    try {
                        $pdo->beginTransaction();
                        $hasBlob = false;
                        try {
                            $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'avatar_blob'");
                            $hasBlob = (bool)$check->fetch(PDO::FETCH_ASSOC);
                        } catch (Throwable $e) {}
                        if ($hasBlob) {
                            $pdo->prepare('UPDATE users SET avatar_blob=?, avatar_mime=?, avatar_path=NULL, updated_at=NOW() WHERE id=?')
                                ->execute([$blob, $storedMime, $uid]);
                        } else {
                            // Last-resort file storage for installations where ALTER TABLE is unavailable.
                            $uploadDir = __DIR__ . '/../assets/uploads/';
                            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
                            if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
                                throw new RuntimeException('The database could not add avatar storage and the file upload folder is not writable.');
                            }
                            $fileBase = 'avatar_' . $uid . '_' . bin2hex(random_bytes(8));
                            $relPath = 'assets/uploads/' . $fileBase . '.jpg';
                            $absPath = __DIR__ . '/../' . $relPath;
                            if (@file_put_contents($absPath, $blob) === false) throw new RuntimeException('Could not save profile photo file.');
                            $pdo->prepare('UPDATE users SET avatar_path=?, updated_at=NOW() WHERE id=?')->execute([$relPath, $uid]);
                        }
                        $pdo->commit();
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $errors[] = APP_DEBUG ? ('Profile photo could not be saved: ' . $e->getMessage()) : 'Profile photo could not be saved. Please try again.';
                        break;
                    }
                    $_SESSION['user']['avatar'] = 'api/v1/profile/avatar.php?id=' . $uid;
                    $_SESSION['user']['avatar_path'] = null;
                    flash_set('success', t('flash.avatar_updated'));
                    redirect(APP_URL . '/pages/profile.php#profile');
                    break;

                case 'remove_avatar':
                    $oldAvatar = (string)$scalar('SELECT avatar_path FROM users WHERE id=?', [$uid], '');
                    $pdo->prepare('UPDATE users SET avatar_path=NULL, avatar_blob=NULL, avatar_mime=NULL, updated_at=NOW() WHERE id=?')->execute([$uid]);
                    if ($oldAvatar && str_starts_with($oldAvatar, 'assets/uploads/')) { $oldAbs = __DIR__ . '/../' . $oldAvatar; if (is_file($oldAbs)) @unlink($oldAbs); }
                    $_SESSION['user']['avatar'] = null;
                    flash_set('success', t('flash.avatar_removed'));
                    redirect(APP_URL . '/pages/profile.php#profile');
                    break;

                case 'update_email':
                    $email = trim((string)($_POST['new_email'] ?? ''));
                    $password = (string)($_POST['current_password_email'] ?? '');
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = t('errors.email_invalid');
                    $hash = (string)$scalar('SELECT password_hash FROM users WHERE id=?', [$uid], '');
                    if (!$errors && !password_verify($password, $hash)) $errors[] = t('errors.password_incorrect');
                    if (!$errors && $scalar('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1', [$email,$uid], null) !== null) $errors[] = t('errors.email_taken');
                    if (!$errors) {
                        $pdo->prepare('UPDATE users SET email=?, updated_at=NOW() WHERE id=?')->execute([$email,$uid]);
                        $_SESSION['user']['email'] = $email;
                        flash_set('success', t('flash.email_updated'));
                        redirect(APP_URL . '/pages/profile.php#security');
                    }
                    break;

                case 'update_password':
                    $current = (string)($_POST['current_password'] ?? '');
                    $new = (string)($_POST['new_password'] ?? '');
                    $confirm = (string)($_POST['confirm_password'] ?? '');
                    $hash = (string)$scalar('SELECT password_hash FROM users WHERE id=?', [$uid], '');
                    if (!password_verify($current, $hash)) $errors[] = t('errors.password_incorrect');
                    elseif (strlen($new) < 8) $errors[] = t('errors.password_short');
                    elseif ($new !== $confirm) $errors[] = t('errors.password_mismatch');
                    else {
                        $newHash = password_hash($new, HASH_ALGO, ['cost'=>HASH_COST]);
                        $pdo->prepare('UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?')->execute([$newHash,$uid]);
                        flash_set('success', t('flash.password_changed'));
                        logout_user(); redirect(APP_URL . '/pages/login.php');
                    }
                    break;
            }
        } catch (Throwable $e) {
            $errors[] = 'We could not save your changes. Please try again.';
        }
    }
}

$uStmt = $pdo->prepare('SELECT u.*, r.name AS role_name FROM users u LEFT JOIN roles r ON r.id=u.role_id WHERE u.id=? LIMIT 1');
$uStmt->execute([$uid]);
$user = $uStmt->fetch() ?: current_user();
$role = strtoupper((string)($user['role_name'] ?? current_user()['role_name'] ?? 'USER'));
$isSeller = $role === 'SELLER' || $role === 'ADMIN' || $role === 'SUPER_ADMIN' || (int)($user['is_seller'] ?? 0) === 1;
$isAdmin = in_array($role, ['ADMIN','SUPER_ADMIN'], true);
$isSuper = $role === 'SUPER_ADMIN';
$avatar = '';
try {
    $hasAvatarBlob = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'avatar_blob'")->fetch(PDO::FETCH_ASSOC);
    if ($hasAvatarBlob) {
        $blobExists = (int)$scalar('SELECT CASE WHEN avatar_blob IS NULL OR OCTET_LENGTH(avatar_blob)=0 THEN 0 ELSE 1 END FROM users WHERE id=?', [$uid], 0);
        if ($blobExists) $avatar = APP_URL . '/api/v1/profile/avatar.php?id=' . $uid;
    }
} catch (Throwable $e) {}
if (!$avatar && !empty($user['avatar_path'])) $avatar = image_or_default($user['avatar_path']);
$initial = strtoupper(mb_substr((string)($user['full_name'] ?? 'U'), 0, 1));

$activeListings = (int)$scalar("SELECT COUNT(*) FROM listings WHERE seller_id=? AND status='active'", [$uid]);
$totalListings = (int)$scalar('SELECT COUNT(*) FROM listings WHERE seller_id=?', [$uid]);
$favorites = (int)$scalar('SELECT COUNT(*) FROM favorites WHERE user_id=?', [$uid]);
$unreadNotifications = (int)$scalar('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0', [$uid]);
$messages = (int)$scalar('SELECT COUNT(*) FROM contact_requests WHERE seller_id=?', [$uid]);
$rentals = (int)$scalar('SELECT COUNT(*) FROM rental_requests WHERE renter_id=?', [$uid]);
$views = (int)$scalar('SELECT COALESCE(SUM(views_count),0) FROM listings WHERE seller_id=?', [$uid]);
$ordersBought = (int)$scalar('SELECT COUNT(*) FROM orders WHERE buyer_id=?', [$uid]);
$ordersSold = (int)$scalar('SELECT COUNT(*) FROM orders WHERE seller_id=?', [$uid]);
$completedSales = (int)$scalar("SELECT COUNT(*) FROM orders WHERE seller_id=? AND status IN ('delivered','completed')", [$uid]);
$buyerSpend = (float)$scalar("SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE buyer_id=? AND status NOT IN ('cancelled','disputed')", [$uid], 0);
$sellerRevenue = (float)$scalar("SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE seller_id=? AND status IN ('delivered','completed')", [$uid], 0);
$reviewCount = (int)$scalar('SELECT COUNT(*) FROM reviews WHERE reviewer_id=?', [$uid]);
$reviewsReceived = (int)$scalar('SELECT COUNT(*) FROM reviews WHERE seller_id=?', [$uid]);
$walletBalance = (float)$scalar('SELECT COALESCE(available_balance,0) FROM wallets WHERE user_id=? LIMIT 1', [$uid], 0);

$recentListings = $rows("SELECT id,title,price,currency,status,views_count,created_at FROM listings WHERE seller_id=? ORDER BY created_at DESC LIMIT 4", [$uid]);
$recentOrders = $rows("SELECT id,order_number,grand_total,currency,status,created_at FROM orders WHERE buyer_id=? ORDER BY created_at DESC LIMIT 4", [$uid]);
$recentSales = $rows("SELECT id,order_number,grand_total,currency,status,created_at FROM orders WHERE seller_id=? ORDER BY created_at DESC LIMIT 4", [$uid]);

$avatarFilled = false;
try { $avatarFilled = (int)$scalar("SELECT CASE WHEN (avatar_blob IS NOT NULL AND OCTET_LENGTH(avatar_blob)>0) OR (avatar_path IS NOT NULL AND avatar_path!='') THEN 1 ELSE 0 END FROM users WHERE id=?", [$uid], 0) === 1; } catch (Throwable $e) {}
$fields = ['full_name','email','phone','location','bio'];
$filled = 0;
foreach ($fields as $f) {
    if (trim((string)($user[$f] ?? '')) !== '') $filled++;
}
if ($avatarFilled) $filled++;
$profileProgress = (int)round(($filled / (count($fields) + 1)) * 100);

$pageTitle = t('nav.profile');
$pageDescription = 'Manage your Isoko Ryacu account, profile, security and activity.';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.profile-page{max-width:1180px;margin:0 auto;padding:28px 20px 70px}
.profile-back{display:inline-flex;align-items:center;gap:7px;color:var(--text-muted,var(--muted,#64748b));text-decoration:none;font-size:14px;font-weight:700;margin:2px 0 18px}
.profile-grid{display:grid;grid-template-columns:285px minmax(0,1fr);gap:22px;align-items:start}
.profile-card{background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:22px;box-shadow:0 10px 35px rgba(15,23,42,.06)}
.profile-side{position:sticky;top:18px;padding:18px}
.profile-avatar{width:94px;height:94px;border-radius:50%;object-fit:cover;display:grid;place-items:center;background:linear-gradient(135deg,var(--brand,#0ea5a4),#f59e0b);color:#fff;font-size:34px;font-weight:800;border:4px solid rgba(14,165,164,.12)}
.profile-avatar-wrap{text-align:center;padding:8px 4px 18px;border-bottom:1px solid var(--border,#e2e8f0)}
.profile-name{font-size:19px;font-weight:800;margin:12px 0 4px;color:var(--text,#0f172a)}
.profile-email{font-size:12px;color:var(--muted,#64748b);overflow-wrap:anywhere}
.role-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;background:rgba(245,158,11,.12);color:#b45309;font-size:11px;font-weight:800;margin-top:10px;text-transform:uppercase;letter-spacing:.04em}
.profile-nav{display:grid;gap:5px;margin-top:16px}
.profile-nav a{display:flex;align-items:center;gap:11px;padding:11px 12px;border-radius:12px;color:var(--text,#334155);text-decoration:none;font-weight:700;font-size:13px}
.profile-nav a:hover,.profile-nav a.active{background:rgba(14,165,164,.1);color:var(--brand,#0f9f9d)}
.profile-nav svg{width:18px;height:18px;flex:none}
.profile-progress{margin-top:16px;padding:14px;border-radius:14px;background:rgba(14,165,164,.06)}
.progress-head{display:flex;justify-content:space-between;font-size:12px;font-weight:800;margin-bottom:8px}.progress-bar{height:7px;border-radius:9px;background:#e2e8f0;overflow:hidden}.progress-bar span{display:block;height:100%;width:<?php echo $profileProgress; ?>%;background:var(--brand,#0ea5a4);border-radius:9px}
.profile-main{min-width:0}.profile-hero{padding:25px;position:relative;overflow:hidden;background:linear-gradient(135deg,rgba(14,165,164,.12),rgba(245,158,11,.08));}
.profile-hero:after{content:"";position:absolute;width:230px;height:230px;border-radius:50%;right:-90px;top:-120px;background:rgba(14,165,164,.1)}
.hero-row{display:flex;justify-content:space-between;gap:18px;align-items:center;position:relative;z-index:1}.hero-copy h1{font-size:29px;line-height:1.1;margin:0 0 7px}.hero-copy p{margin:0;color:var(--muted,#64748b);font-size:14px}.hero-actions{display:flex;gap:8px;flex-wrap:wrap}.profile-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:0;border-radius:11px;padding:10px 14px;font-weight:800;font-size:13px;text-decoration:none;cursor:pointer}.profile-btn.primary{background:var(--brand,#0ea5a4);color:#fff}.profile-btn.outline{background:#fff;color:var(--text,#334155);border:1px solid var(--border,#dbe4ea)}
.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:16px}.profile-stat{padding:16px;border-radius:17px}.profile-stat .icon{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:rgba(14,165,164,.1);color:var(--brand,#0ea5a4);margin-bottom:10px}.profile-stat .label{font-size:11px;color:var(--muted,#64748b);font-weight:700}.profile-stat .value{font-size:22px;font-weight:850;margin-top:3px}.profile-stat .sub{font-size:10px;color:var(--muted,#64748b);margin-top:3px}
.section-card{padding:21px;margin-top:16px}.section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px}.section-head h2{font-size:18px;margin:0 0 4px}.section-head p{font-size:12px;color:var(--muted,#64748b);margin:0}.section-link{font-size:12px;font-weight:800;color:var(--brand,#0f9f9d);text-decoration:none;white-space:nowrap}
.quick-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.quick{padding:14px;border:1px solid var(--border,#e2e8f0);border-radius:15px;text-decoration:none;color:inherit;transition:.18s}.quick:hover{transform:translateY(-2px);border-color:rgba(14,165,164,.4);box-shadow:0 8px 22px rgba(15,23,42,.06)}.quick strong{display:block;font-size:13px}.quick span{display:block;color:var(--muted,#64748b);font-size:11px;margin-top:4px}.quick-icon{width:36px;height:36px;border-radius:11px;background:rgba(14,165,164,.1);color:var(--brand,#0ea5a4);display:grid;place-items:center;margin-bottom:9px}
.form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}.form-full{grid-column:1/-1}.profile-page .form-group{margin:0}.profile-page label{font-size:12px;font-weight:800;display:block;margin-bottom:6px}.profile-page input,.profile-page textarea{width:100%;box-sizing:border-box;border:1px solid var(--border,#dbe4ea);border-radius:11px;padding:11px 12px;background:var(--surface,#fff);color:var(--text,#0f172a);font:inherit;font-size:13px;outline:none}.profile-page input:focus,.profile-page textarea:focus{border-color:var(--brand,#0ea5a4);box-shadow:0 0 0 3px rgba(14,165,164,.1)}.form-actions{margin-top:14px;display:flex;justify-content:flex-end;gap:8px}.avatar-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.upload-label{cursor:pointer}
.data-list{display:grid;gap:9px}.data-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px;border:1px solid var(--border,#e2e8f0);border-radius:13px}.data-main{min-width:0}.data-main strong{display:block;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.data-main span{font-size:11px;color:var(--muted,#64748b)}.data-side{text-align:right;white-space:nowrap}.data-side strong{font-size:13px}.status-pill{display:inline-flex;padding:5px 8px;border-radius:999px;background:rgba(16,185,129,.11);color:#047857;font-size:10px;font-weight:800;text-transform:capitalize}.status-pill.pending{background:rgba(245,158,11,.12);color:#b45309}.status-pill.cancelled,.status-pill.disputed{background:rgba(239,68,68,.1);color:#b91c1c}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:16px}.security-note{padding:12px;border-radius:12px;background:rgba(14,165,164,.07);font-size:12px;color:var(--muted,#64748b);margin-top:12px}.danger-box{border-color:rgba(239,68,68,.25)}.danger-box h2{color:#b91c1c}
.profile-error{padding:13px 15px;border-radius:13px;background:rgba(239,68,68,.08);color:#b91c1c;margin-top:16px;font-size:13px}.profile-photo-form{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.profile-photo-preview{width:58px;height:58px;border-radius:50%;object-fit:cover}
@media(max-width:900px){.profile-grid{grid-template-columns:1fr}.profile-side{position:static}.profile-nav{grid-template-columns:repeat(3,1fr)}.stat-grid{grid-template-columns:repeat(2,1fr)}.quick-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){.profile-page{padding:18px 12px 50px}.hero-row{align-items:flex-start;flex-direction:column}.hero-actions{width:100%}.hero-actions .profile-btn{flex:1}.profile-nav{grid-template-columns:repeat(2,1fr)}.stat-grid,.form-grid,.two-col,.quick-grid{grid-template-columns:1fr}.profile-hero{padding:20px}.hero-copy h1{font-size:24px}.section-card{padding:16px}.data-row{align-items:flex-start}.data-side{white-space:normal}}
</style>

<div class="profile-page">
    <a class="profile-back" href="<?php echo e(APP_URL); ?>/">← <?php echo e(t('buttons.back_home')); ?></a>

    <div class="profile-grid">
        <aside class="profile-card profile-side">
            <div class="profile-avatar-wrap">
                <?php if ($avatar): ?><img class="profile-avatar" src="<?php echo e($avatar); ?>?v=<?php echo time(); ?>" alt="<?php echo e($user['full_name']); ?>">
                <?php else: ?><div class="profile-avatar" aria-hidden="true"><?php echo e($initial); ?></div><?php endif; ?>
                <div class="profile-name"><?php echo e($user['full_name']); ?></div>
                <div class="profile-email"><?php echo e($user['email']); ?></div>
                <span class="role-pill">● <?php echo e(str_replace('_',' ', $role)); ?></span>
            </div>
            <nav class="profile-nav" aria-label="Profile navigation">
                <a class="active" href="#overview">⌂ <span>Overview</span></a>
                <a href="#profile">◉ <span>Personal profile</span></a>
                <a href="#activity">▣ <span>Activity</span></a>
                <a href="#security">▣ <span>Security</span></a>
                <a href="<?php echo APP_URL; ?>/pages/notifications.php">♢ <span>Notifications<?php if($unreadNotifications): ?> (<?php echo $unreadNotifications; ?>)<?php endif; ?></span></a>
                <a href="<?php echo APP_URL; ?>/pages/favorites.php">♡ <span>Favorites</span></a>
            </nav>
            <div class="profile-progress">
                <div class="progress-head"><span>Profile completion</span><span><?php echo $profileProgress; ?>%</span></div>
                <div class="progress-bar"><span></span></div>
                <div style="font-size:10px;color:var(--muted,#64748b);margin-top:7px">Complete your profile to build trust with other marketplace users.</div>
            </div>
        </aside>

        <main class="profile-main">
            <section id="overview" class="profile-card profile-hero">
                <div class="hero-row">
                    <div class="hero-copy">
                        <h1>Welcome back, <?php echo e($user['full_name']); ?> 👋</h1>
                        <p>Your account center for Isoko Ryacu. Manage your identity, activity and security in one place.</p>
                    </div>
                    <div class="hero-actions">
                        <?php if ($isSeller): ?><a class="profile-btn primary" href="<?php echo APP_URL; ?>/pages/sell.php">＋ Sell an item</a><?php endif; ?>
                        <?php if ($isAdmin): ?><a class="profile-btn outline" href="<?php echo APP_URL; ?>/pages/admin/dashboard.php">Control center</a><?php endif; ?>
                        <?php if (!$isAdmin): ?><a class="profile-btn outline" href="<?php echo APP_URL; ?>/pages/dashboard.php">My dashboard</a><?php endif; ?>
                    </div>
                </div>
            </section>

            <div class="stat-grid">
                <div class="profile-card profile-stat"><div class="icon">◈</div><div class="label">Active listings</div><div class="value"><?php echo number_format($activeListings); ?></div><div class="sub"><?php echo number_format($totalListings); ?> total</div></div>
                <div class="profile-card profile-stat"><div class="icon">♡</div><div class="label">Favorites</div><div class="value"><?php echo number_format($favorites); ?></div><div class="sub">Items you saved</div></div>
                <div class="profile-card profile-stat"><div class="icon">♧</div><div class="label">Orders</div><div class="value"><?php echo number_format($ordersBought); ?></div><div class="sub">Purchases made</div></div>
                <div class="profile-card profile-stat"><div class="icon">●</div><div class="label">Notifications</div><div class="value"><?php echo number_format($unreadNotifications); ?></div><div class="sub">Unread updates</div></div>
            </div>

            <?php if ($errors): ?><div class="profile-error" role="alert"><strong>We couldn't save your changes.</strong><ul style="margin:6px 0 0;padding-left:18px"><?php foreach($errors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

            <section class="profile-card section-card" aria-labelledby="quick-title">
                <div class="section-head"><div><h2 id="quick-title">Quick access</h2><p>Jump directly to the parts of Isoko Ryacu you use most.</p></div></div>
                <div class="quick-grid">
                    <a class="quick" href="<?php echo APP_URL; ?>/pages/orders.php"><div class="quick-icon">▣</div><strong>My orders</strong><span><?php echo number_format($ordersBought); ?> purchases</span></a>
                    <a class="quick" href="<?php echo APP_URL; ?>/pages/favorites.php"><div class="quick-icon">♡</div><strong>Saved items</strong><span><?php echo number_format($favorites); ?> favorites</span></a>
                    <a class="quick" href="<?php echo APP_URL; ?>/pages/notifications.php"><div class="quick-icon">♢</div><strong>Notifications</strong><span><?php echo number_format($unreadNotifications); ?> unread</span></a>
                    <?php if ($isSeller): ?><a class="quick" href="<?php echo APP_URL; ?>/pages/seller/dashboard.php"><div class="quick-icon">◆</div><strong>Seller dashboard</strong><span><?php echo number_format($ordersSold); ?> sales/orders</span></a><?php endif; ?>
                    <?php if ($isSeller): ?><a class="quick" href="<?php echo APP_URL; ?>/pages/seller/listings.php"><div class="quick-icon">◈</div><strong>My listings</strong><span><?php echo number_format($activeListings); ?> active listings</span></a><?php endif; ?>
                    <?php if ($isAdmin): ?><a class="quick" href="<?php echo APP_URL; ?>/pages/admin/users.php"><div class="quick-icon">♙</div><strong>User management</strong><span>Manage marketplace accounts</span></a><?php endif; ?>
                    <?php if ($isSuper): ?><a class="quick" href="<?php echo APP_URL; ?>/pages/admin/super-admins.php"><div class="quick-icon">★</div><strong>Super Admin</strong><span>Platform-wide controls</span></a><?php endif; ?>
                </div>
            </section>

            <section id="activity" class="two-col">
                <div class="profile-card section-card">
                    <div class="section-head"><div><h2>Account activity</h2><p>Your marketplace numbers at a glance.</p></div></div>
                    <div class="data-list">
                        <div class="data-row"><div class="data-main"><strong>Rental requests</strong><span>Requests you have made</span></div><div class="data-side"><strong><?php echo number_format($rentals); ?></strong></div></div>
                        <div class="data-row"><div class="data-main"><strong>Messages</strong><span>Seller contact requests</span></div><div class="data-side"><strong><?php echo number_format($messages); ?></strong></div></div>
                        <div class="data-row"><div class="data-main"><strong>Reviews written</strong><span>Your marketplace feedback</span></div><div class="data-side"><strong><?php echo number_format($reviewCount); ?></strong></div></div>
                        <?php if ($isSeller): ?><div class="data-row"><div class="data-main"><strong>Profile views</strong><span>Total views on your listings</span></div><div class="data-side"><strong><?php echo number_format($views); ?></strong></div></div><?php endif; ?>
                    </div>
                </div>
                <div class="profile-card section-card">
                    <div class="section-head"><div><h2><?php echo $isSeller ? 'Seller performance' : 'Buyer summary'; ?></h2><p>Useful account performance information.</p></div></div>
                    <div class="data-list">
                        <?php if ($isSeller): ?>
                        <div class="data-row"><div class="data-main"><strong>Completed sales</strong><span>Delivered or completed orders</span></div><div class="data-side"><strong><?php echo number_format($completedSales); ?></strong></div></div>
                        <div class="data-row"><div class="data-main"><strong>Revenue</strong><span>Completed order value</span></div><div class="data-side"><strong><?php echo number_format($sellerRevenue,0); ?> RWF</strong></div></div>
                        <div class="data-row"><div class="data-main"><strong>Reviews received</strong><span>Customer feedback</span></div><div class="data-side"><strong><?php echo number_format($reviewsReceived); ?></strong></div></div>
                        <?php else: ?>
                        <div class="data-row"><div class="data-main"><strong>Purchases</strong><span>Orders placed</span></div><div class="data-side"><strong><?php echo number_format($ordersBought); ?></strong></div></div>
                        <div class="data-row"><div class="data-main"><strong>Total spending</strong><span>Non-cancelled orders</span></div><div class="data-side"><strong><?php echo number_format($buyerSpend,0); ?> RWF</strong></div></div>
                        <div class="data-row"><div class="data-main"><strong>Wallet balance</strong><span>Available marketplace wallet</span></div><div class="data-side"><strong><?php echo number_format($walletBalance,0); ?> RWF</strong></div></div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <?php if ($recentListings): ?>
            <section class="profile-card section-card">
                <div class="section-head"><div><h2>Recent listings</h2><p>Your latest marketplace listings.</p></div><a class="section-link" href="<?php echo APP_URL; ?>/pages/seller/listings.php">Manage listings →</a></div>
                <div class="data-list">
                    <?php foreach($recentListings as $item): ?><div class="data-row"><div class="data-main"><strong><?php echo e($item['title']); ?></strong><span><?php echo e(date('d M Y', strtotime($item['created_at']))); ?> · <?php echo number_format((int)$item['views_count']); ?> views</span></div><div class="data-side"><strong><?php echo number_format((float)$item['price'],0); ?> <?php echo e($item['currency'] ?? 'RWF'); ?></strong><br><span class="status-pill <?php echo e((string)$item['status']); ?>"><?php echo e((string)$item['status']); ?></span></div></div><?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($recentOrders): ?>
            <section class="profile-card section-card">
                <div class="section-head"><div><h2>Recent orders</h2><p>Your latest purchases.</p></div><a class="section-link" href="<?php echo APP_URL; ?>/pages/orders.php">View all →</a></div>
                <div class="data-list">
                    <?php foreach($recentOrders as $item): ?><div class="data-row"><div class="data-main"><strong><?php echo e($item['order_number']); ?></strong><span><?php echo e(date('d M Y · H:i', strtotime($item['created_at']))); ?></span></div><div class="data-side"><strong><?php echo number_format((float)$item['grand_total'],0); ?> <?php echo e($item['currency'] ?? 'RWF'); ?></strong><br><span class="status-pill <?php echo e((string)$item['status']); ?>"><?php echo e((string)$item['status']); ?></span></div></div><?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <section id="profile" class="profile-card section-card">
                <div class="section-head"><div><h2>Personal profile</h2><p>Keep your identity and contact information up to date.</p></div></div>
                <div style="margin-bottom:18px" class="profile-photo-form">
                    <?php if($avatar): ?><img class="profile-photo-preview" src="<?php echo e($avatar); ?>?v=<?php echo time(); ?>" alt="Profile photo"><?php else: ?><div class="profile-photo-preview profile-avatar" style="font-size:20px"><?php echo e($initial); ?></div><?php endif; ?>
                    <div><strong style="font-size:13px">Profile photo</strong><div style="font-size:11px;color:var(--muted,#64748b);margin-top:3px">JPG, PNG or WebP · <?php echo number_format(MAX_UPLOAD_BYTES / 1048576, 1); ?> MB max</div></div>
                    <form method="post" enctype="multipart/form-data" id="avatarForm" class="avatar-actions" style="margin-left:auto"><?php echo csrf_field(); ?><input type="hidden" name="action" value="upload_avatar"><label class="profile-btn outline upload-label">Upload<input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" hidden onchange="this.form.submit()"></label></form>
                    <?php if($avatar): ?><form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="remove_avatar"><button class="profile-btn outline" type="submit">Remove</button></form><?php endif; ?>
                </div>
                <form method="post">
                    <?php echo csrf_field(); ?><input type="hidden" name="action" value="update_account">
                    <div class="form-grid">
                        <div class="form-group"><label for="full_name">Full name</label><input id="full_name" name="full_name" value="<?php echo e($user['full_name']); ?>" maxlength="120" required></div>
                        <div class="form-group"><label for="phone">Phone</label><input id="phone" name="phone" type="tel" value="<?php echo e($user['phone'] ?? ''); ?>" placeholder="+250 78 000 0000"></div>
                        <div class="form-group"><label for="whatsapp_number">WhatsApp</label><input id="whatsapp_number" name="whatsapp_number" type="tel" value="<?php echo e($user['whatsapp_number'] ?? ''); ?>" placeholder="+250 78 000 0000"></div>
                        <div class="form-group"><label for="location">Location</label><input id="location" name="location" value="<?php echo e($user['location'] ?? ''); ?>" placeholder="Kigali, Rwanda"></div>
                        <div class="form-group form-full"><label for="bio">About you</label><textarea id="bio" name="bio" rows="4" maxlength="3000" placeholder="Tell other marketplace users a little about yourself..."><?php echo e($user['bio'] ?? ''); ?></textarea></div>
                    </div>
                    <div class="form-actions"><button class="profile-btn primary" type="submit">Save profile</button></div>
                </form>
            </section>

            <section id="security" class="profile-card section-card">
                <div class="section-head"><div><h2>Account & security</h2><p>Protect your login and keep your account details current.</p></div></div>
                <div class="two-col">
                    <form method="post"><div class="form-group"><label for="new_email">Email address</label><input id="new_email" type="email" name="new_email" value="<?php echo e($user['email']); ?>" required></div><div class="form-group" style="margin-top:12px"><label for="current_password_email">Current password</label><input id="current_password_email" type="password" name="current_password_email" required></div><?php echo csrf_field(); ?><input type="hidden" name="action" value="update_email"><div class="form-actions"><button class="profile-btn outline" type="submit">Update email</button></div></form>
                    <form method="post"><div class="form-group"><label for="current_password">Current password</label><input id="current_password" type="password" name="current_password" required></div><div class="form-group" style="margin-top:12px"><label for="new_password">New password</label><input id="new_password" type="password" name="new_password" minlength="8" required placeholder="At least 8 characters"></div><div class="form-group" style="margin-top:12px"><label for="confirm_password">Confirm new password</label><input id="confirm_password" type="password" name="confirm_password" minlength="8" required></div><?php echo csrf_field(); ?><input type="hidden" name="action" value="update_password"><div class="form-actions"><button class="profile-btn primary" type="submit">Change password</button></div></form>
                </div>
                <div class="security-note">For your safety, changing your password signs you out of this session. You will need to sign in again using the new password.</div>
            </section>

            <section class="profile-card section-card">
                <div class="section-head"><div><h2>Account information</h2><p>System information about your Isoko Ryacu account.</p></div></div>
                <div class="data-list">
                    <div class="data-row"><div class="data-main"><strong>Account role</strong><span>Your permissions are controlled by your assigned role.</span></div><div class="data-side"><span class="status-pill"><?php echo e(str_replace('_',' ', $role)); ?></span></div></div>
                    <div class="data-row"><div class="data-main"><strong>Verification</strong><span><?php echo !empty($user['is_verified']) ? 'Your account is verified.' : 'Your account is not verified yet.'; ?></span></div><div class="data-side"><span class="status-pill <?php echo empty($user['is_verified']) ? 'pending' : ''; ?>"><?php echo !empty($user['is_verified']) ? 'Verified' : 'Pending'; ?></span></div></div>
                    <div class="data-row"><div class="data-main"><strong>Account status</strong><span>Current account access state.</span></div><div class="data-side"><span class="status-pill <?php echo e((string)($user['status'] ?? 'active')); ?>"><?php echo e((string)($user['status'] ?? 'active')); ?></span></div></div>
                    <?php
                    // Some older installations do not have users.created_at. Never let
                    // a missing/invalid date break the entire profile page.
                    $memberSinceRaw = $user['created_at'] ?? null;
                    $memberSinceTs = $memberSinceRaw ? strtotime((string)$memberSinceRaw) : false;
                    $memberSinceLabel = $memberSinceTs !== false ? date('d M Y', $memberSinceTs) : '—';
                    ?>
                    <div class="data-row"><div class="data-main"><strong>Member since</strong><span>Joined Isoko Ryacu</span></div><div class="data-side"><strong><?php echo e($memberSinceLabel); ?></strong></div></div>
                </div>
            </section>
        </main>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
