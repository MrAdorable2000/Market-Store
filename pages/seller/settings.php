<?php
/**
 * pages/seller/settings.php — Seller Settings & Profile Configuration
 * --------------------------------------------------------------------
 * Lets a seller configure:
 *   - Business name + description (shown on their public seller profile)
 *   - Social links (Facebook, Twitter, Instagram, WhatsApp)
 *   - Response time (shown to buyers)
 *   - Preferred categories (categories they sell in most)
 *   - Shop visibility (pause/resume selling)
 *
 * Also shows a "profile completion" progress bar so sellers know what
 * to fill in to attract more buyers.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/seller_sidebar.php';
require_login();

$uid = (int) current_user()['id'];
$pdo = db();

// Ensure seller_profiles row exists
$pdo->prepare('INSERT IGNORE INTO seller_profiles (user_id) VALUES (?)')->execute([$uid]);

// --- Handle POST ---
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = t('errors.invalid_token');
    } else {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'update_business':
                $businessName = trim($_POST['business_name'] ?? '');
                $businessDesc = trim($_POST['business_description'] ?? '');
                $responseTime = (int)($_POST['response_time_hours'] ?? 1);
                if ($responseTime < 1) $responseTime = 1;
                if ($responseTime > 72) $responseTime = 72;

                $pdo->prepare(
                    'UPDATE seller_profiles SET business_name = ?, business_description = ?, response_time_hours = ?, updated_at = NOW() WHERE user_id = ?'
                )->execute([$businessName ?: null, $businessDesc ?: null, $responseTime, $uid]);
                flash_set('success', 'Business profile updated successfully.');
                redirect(APP_URL . '/pages/seller/settings.php');
                break;

            case 'update_social':
                $fb = trim($_POST['social_facebook'] ?? '');
                $tw = trim($_POST['social_twitter'] ?? '');
                $ig = trim($_POST['social_instagram'] ?? '');
                $wa = trim($_POST['social_whatsapp'] ?? '');
                $pdo->prepare(
                    'UPDATE seller_profiles SET social_facebook = ?, social_twitter = ?, social_instagram = ?, social_whatsapp = ?, updated_at = NOW() WHERE user_id = ?'
                )->execute([$fb ?: null, $tw ?: null, $ig ?: null, $wa ?: null, $uid]);
                flash_set('success', 'Social links updated successfully.');
                redirect(APP_URL . '/pages/seller/settings.php');
                break;

            case 'update_categories':
                // Save preferred categories as a JSON in site_settings (user-specific)
                $cats = $_POST['preferred_categories'] ?? [];
                $cats = array_map('intval', $cats);
                $cats = array_filter($cats, fn($c) => $c > 0);
                // Store in seller_profiles.business_description? No — use a separate setting.
                // We'll store it as a user setting in the users table bio field or a new column.
                // For now, store as JSON in seller_profiles.social_facebook is wrong.
                // Let's use site_settings with a user-specific key.
                $key = 'seller_preferred_cats_' . $uid;
                $value = json_encode($cats);
                $pdo->prepare(
                    'INSERT INTO site_settings (setting_key, setting_value, description) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()'
                )->execute([$key, $value, 'Preferred categories for seller ' . $uid, $value]);
                flash_set('success', 'Preferred categories updated.');
                redirect(APP_URL . '/pages/seller/settings.php');
                break;

            case 'update_account':
                $fullName = trim($_POST['full_name'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $location = trim($_POST['location'] ?? '');
                $bio = trim($_POST['bio'] ?? '');
                if (mb_strlen($fullName) < 3) {
                    $errors[] = 'Name must be at least 3 characters.';
                } else {
                    $pdo->prepare(
                        'UPDATE users SET full_name = ?, phone = ?, location = ?, bio = ?, updated_at = NOW() WHERE id = ?'
                    )->execute([$fullName, $phone ?: null, $location ?: null, $bio ?: null, $uid]);
                    // Update session
                    $_SESSION['user']['full_name'] = $fullName;
                    flash_set('success', 'Account information updated.');
                    redirect(APP_URL . '/pages/seller/settings.php');
                }
                break;

            case 'upload_avatar':
                // Profile picture upload — secure server-side validation
                if (empty($_FILES['avatar']) || $_FILES['avatar']['error'] === UPLOAD_ERR_NO_FILE) {
                    $errors[] = 'Please select an image to upload.';
                    break;
                }
                $file = $_FILES['avatar'];
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = 'Upload failed. Please try again.';
                    break;
                }
                if ($file['size'] > MAX_UPLOAD_BYTES) {
                    $errors[] = 'Image is too large. Maximum size is ' . round(MAX_UPLOAD_BYTES / 1024 / 1024, 1) . 'MB.';
                    break;
                }
                // Server-side MIME type check (never trust the filename)
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
                $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
                if (!in_array($mime, $allowedMimes, true)) {
                    $errors[] = 'Invalid image format. Only JPG, PNG, and WEBP are allowed.';
                    break;
                }
                // Verify it's actually a valid image
                $imgInfo = @getimagesize($file['tmp_name']);
                if (!$imgInfo) {
                    $errors[] = 'The file is not a valid image.';
                    break;
                }
                // Extension mapping
                $ext = match ($mime) {
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                    default      => 'jpg',
                };
                // Generate safe unique filename
                $safeName = 'avatar_' . $uid . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $relPath = 'assets/uploads/' . $safeName;
                $absPath = __DIR__ . '/../../' . $relPath;
                // Ensure upload directory exists
                if (!is_dir(dirname($absPath))) {
                    mkdir(dirname($absPath), 0755, true);
                }
                // Delete old avatar if it was an uploaded file
                $oldAvatar = $userData['avatar_path'] ?? '';
                if ($oldAvatar && str_starts_with($oldAvatar, 'assets/uploads/')) {
                    $oldAbs = __DIR__ . '/../../' . $oldAvatar;
                    if (file_exists($oldAbs)) @unlink($oldAbs);
                }
                // Move the uploaded file
                if (!move_uploaded_file($file['tmp_name'], $absPath)) {
                    $errors[] = 'Unable to save the image. Please try again.';
                    break;
                }
                // Try to optimize (resize to max 400x400)
                optimize_image_inplace($absPath);
                // Update DB
                $pdo->prepare('UPDATE users SET avatar_path = ?, updated_at = NOW() WHERE id = ?')->execute([$relPath, $uid]);
                $_SESSION['user']['avatar'] = $relPath;
                // Reload userData so the page shows the new image immediately
                $userData['avatar_path'] = $relPath;
                flash_set('success', 'Profile photo updated successfully.');
                redirect(APP_URL . '/pages/seller/settings.php');
                break;

            case 'remove_avatar':
                $oldAvatar = $userData['avatar_path'] ?? '';
                if ($oldAvatar && str_starts_with($oldAvatar, 'assets/uploads/')) {
                    $oldAbs = __DIR__ . '/../../' . $oldAvatar;
                    if (file_exists($oldAbs)) @unlink($oldAbs);
                }
                $pdo->prepare('UPDATE users SET avatar_path = NULL, updated_at = NOW() WHERE id = ?')->execute([$uid]);
                $_SESSION['user']['avatar'] = null;
                $userData['avatar_path'] = null;
                flash_set('success', 'Profile photo removed.');
                redirect(APP_URL . '/pages/seller/settings.php');
                break;

            case 'update_theme':
                $theme = $_POST['theme'] ?? 'light';
                if (!in_array($theme, ['light', 'dark', 'system'], true)) $theme = 'light';
                // Store in DB (user_preferences via site_settings)
                $key = 'user_theme_' . $uid;
                $pdo->prepare(
                    'INSERT INTO site_settings (setting_key, setting_value, description) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()'
                )->execute([$key, $theme, 'Theme preference for user ' . $uid, $theme]);
                flash_set('success', 'Theme preference saved.');
                redirect(APP_URL . '/pages/seller/settings.php');
                break;
        }
    }
}

// --- Load seller profile ---
$stmt = $pdo->prepare('SELECT * FROM seller_profiles WHERE user_id = ?');
$stmt->execute([$uid]);
$profile = $stmt->fetch();

// --- Load user ---
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$uid]);
$userData = $stmt->fetch();

// --- Load categories ---
$cats = $pdo->query("SELECT id, name, slug, icon FROM categories WHERE parent_id IS NULL AND is_active = 1 ORDER BY display_order, name")->fetchAll();

// --- Load preferred categories ---
$key = 'seller_preferred_cats_' . $uid;
$stmt = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ?');
$stmt->execute([$key]);
$preferredRaw = $stmt->fetchColumn();
$preferredCats = $preferredRaw ? json_decode($preferredRaw, true) : [];

// --- Load theme preference ---
$themeKey = 'user_theme_' . $uid;
$stmt = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ?');
$stmt->execute([$themeKey]);
$userTheme = $stmt->fetchColumn() ?: 'system';

// --- Profile completion calculation ---
$completion = 0;
$total = 6;
if (!empty($userData['full_name'])) $completion++;
if (!empty($userData['phone'])) $completion++;
if (!empty($userData['location'])) $completion++;
if (!empty($profile['business_name'])) $completion++;
if (!empty($profile['business_description'])) $completion++;
if (!empty($profile['social_whatsapp']) || !empty($profile['social_facebook'])) $completion++;
$completionPct = round(($completion / $total) * 100);

$pageTitle = 'Seller Settings';
$activePage = 'dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>

<?php seller_page_start('Seller Settings', $uid, $pdo); ?>
    <div style="margin-bottom:14px;"><?php echo back_button(APP_URL . '/pages/dashboard.php', 'Back to dashboard', 'solid'); ?></div>

    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:24px;">
        <div>
            <h1 style="font-size:24px;font-weight:800;margin:0;letter-spacing:-.02em;">Seller Settings</h1>
            <p style="font-size:13.5px;color:var(--text-mute);margin:5px 0 0;">Configure your seller profile, business info, and preferences.</p>
        </div>
        <a href="<?php echo APP_URL; ?>/pages/seller-profile.php?id=<?php echo (int)$uid; ?>" class="btn btn--outline btn--sm" target="_blank">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:5px;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
            View Public Profile
        </a>
    </div>

    <?php if ($errors): ?>
        <div class="toast toast--error" role="alert" style="position:relative;top:auto;right:auto;max-width:100%;margin-bottom:16px;">
            <span class="toast__icon"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg></span>
            <span class="toast__text"><?php echo e($errors[0]); ?></span>
        </div>
    <?php endif; ?>

    <!-- Profile completion bar -->
    <div class="card" style="padding:18px 20px;margin-bottom:20px;background:linear-gradient(135deg,var(--brand-50),#fff);border-color:var(--brand-200);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
            <div>
                <strong style="font-size:14px;color:var(--text);">Profile Completion</strong>
                <span style="font-size:12.5px;color:var(--text-mute);margin-left:8px;"><?php echo $completionPct; ?>% complete</span>
            </div>
            <span style="font-size:20px;font-weight:800;color:var(--brand-600);"><?php echo $completionPct; ?>%</span>
        </div>
        <div style="height:8px;background:var(--bg-soft);border-radius:10px;overflow:hidden;">
            <div style="height:100%;width:<?php echo $completionPct; ?>%;background:linear-gradient(90deg,var(--brand-500),var(--brand-400));border-radius:10px;transition:width 500ms ease;"></div>
        </div>
        <?php if ($completionPct < 100): ?>
        <p style="font-size:12px;color:var(--text-mute);margin:10px 0 0;">Complete your profile to attract more buyers and build trust.</p>
        <?php else: ?>
        <p style="font-size:12px;color:var(--brand-600);margin:10px 0 0;font-weight:600;">✓ Your profile is complete! Buyers can trust you.</p>
        <?php endif; ?>
    </div>

    <!-- Profile Picture -->
    <div class="card" style="padding:20px;margin-bottom:16px;">
        <h2 style="font-size:15px;font-weight:800;margin:0 0 4px;">Profile Picture</h2>
        <p style="font-size:12.5px;color:var(--text-mute);margin:0 0 16px;">Upload a professional photo. JPG, PNG, or WEBP. Max 5MB.</p>
        <div style="display:flex;gap:20px;align-items:center;flex-wrap:wrap;">
            <!-- Avatar preview -->
            <div style="position:relative;width:96px;height:96px;flex:0 0 96px;">
                <?php if (!empty($userData['avatar_path'])): ?>
                    <img src="<?php echo APP_URL . '/' . ltrim($userData['avatar_path'], '/'); ?>?v=<?php echo time(); ?>"
                         alt="Profile picture"
                         style="width:96px;height:96px;border-radius:50%;object-fit:cover;border:3px solid var(--border);">
                <?php else: ?>
                    <div style="width:96px;height:96px;border-radius:50%;background:linear-gradient(135deg,var(--brand-500),var(--brand-700));color:#fff;display:grid;place-items:center;font-size:36px;font-weight:800;border:3px solid var(--border);">
                        <?php echo e(strtoupper(substr($userData['full_name'] ?? '?', 0, 1))); ?>
                    </div>
                <?php endif; ?>
            </div>
            <div style="flex:1;min-width:200px;">
                <form method="post" action="" enctype="multipart/form-data" id="avatarForm">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="upload_avatar">
                    <label class="btn btn--primary btn--sm" style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                        Change Photo
                        <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="document.getElementById('avatarForm').submit()">
                    </label>
                    <?php if (!empty($userData['avatar_path'])): ?>
                    <form method="post" action="" style="display:inline;margin-left:8px;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="remove_avatar">
                        <button type="submit" class="btn btn--ghost btn--sm" style="color:#d9534a;" onclick="return confirm('Remove your profile photo?')">Remove Photo</button>
                    </form>
                    <?php endif; ?>
                </form>
                <p style="font-size:11.5px;color:var(--text-mute);margin:8px 0 0;">Recommended: 400×400px square image.</p>
            </div>
        </div>
    </div>

    <!-- Appearance / Dark Mode -->
    <div class="card" style="padding:20px;margin-bottom:16px;">
        <h2 style="font-size:15px;font-weight:800;margin:0 0 4px;">Appearance</h2>
        <p style="font-size:12.5px;color:var(--text-mute);margin:0 0 16px;">Choose how the marketplace looks for you.</p>
        <form method="post" action="" id="themeForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_theme">
            <input type="hidden" name="theme" id="themeInput" value="<?php echo e($userTheme); ?>">
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button type="button" class="theme-btn <?php echo $userTheme==='light'?'is-selected':''; ?>" onclick="setTheme('light')" aria-pressed="<?php echo $userTheme==='light'?'true':'false'; ?>">
                    <span style="font-size:18px;">☀️</span>
                    <span>Light</span>
                </button>
                <button type="button" class="theme-btn <?php echo $userTheme==='dark'?'is-selected':''; ?>" onclick="setTheme('dark')" aria-pressed="<?php echo $userTheme==='dark'?'true':'false'; ?>">
                    <span style="font-size:18px;">🌙</span>
                    <span>Dark</span>
                </button>
                <button type="button" class="theme-btn <?php echo $userTheme==='system'?'is-selected':''; ?>" onclick="setTheme('system')" aria-pressed="<?php echo $userTheme==='system'?'true':'false'; ?>">
                    <span style="font-size:18px;">💻</span>
                    <span>System</span>
                </button>
            </div>
            <button type="submit" class="btn btn--primary btn--sm" style="margin-top:14px;">Save Theme Preference</button>
        </form>
    </div>

    <!-- Account Information -->
    <div class="card" style="padding:20px;margin-bottom:16px;">
        <h2 style="font-size:15px;font-weight:800;margin:0 0 4px;">Account Information</h2>
        <p style="font-size:12.5px;color:var(--text-mute);margin:0 0 16px;">Your personal details shown to buyers.</p>
        <form method="post" action="">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_account">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;" class="settings-grid">
                <div class="form-group">
                    <label style="font-size:13px;font-weight:600;color:var(--text-soft);display:block;margin-bottom:5px;">Full Name</label>
                    <input type="text" name="full_name" value="<?php echo e($userData['full_name']); ?>" required style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-size:13.5px;">
                </div>
                <div class="form-group">
                    <label style="font-size:13px;font-weight:600;color:var(--text-soft);display:block;margin-bottom:5px;">Phone</label>
                    <input type="tel" name="phone" value="<?php echo e($userData['phone'] ?? ''); ?>" placeholder="+250 788 000 000" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-size:13.5px;">
                </div>
                <div class="form-group">
                    <label style="font-size:13px;font-weight:600;color:var(--text-soft);display:block;margin-bottom:5px;">Location</label>
                    <input type="text" name="location" value="<?php echo e($userData['location'] ?? ''); ?>" placeholder="Kigali, Rwanda" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-size:13.5px;">
                </div>
                <div class="form-group">
                    <label style="font-size:13px;font-weight:600;color:var(--text-soft);display:block;margin-bottom:5px;">Bio (short)</label>
                    <input type="text" name="bio" value="<?php echo e($userData['bio'] ?? ''); ?>" placeholder="Trusted seller since 2024" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-size:13.5px;">
                </div>
            </div>
            <button type="submit" class="btn btn--primary btn--sm" style="margin-top:8px;">Save Changes</button>
        </form>
    </div>

    <!-- Business Profile -->
    <div class="card" style="padding:20px;margin-bottom:16px;">
        <h2 style="font-size:15px;font-weight:800;margin:0 0 4px;">Business Profile</h2>
        <p style="font-size:12.5px;color:var(--text-mute);margin:0 0 16px;">Your shop/business info shown on your public seller page.</p>
        <form method="post" action="">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_business">
            <div class="form-group" style="margin-bottom:14px;">
                <label style="font-size:13px;font-weight:600;color:var(--text-soft);display:block;margin-bottom:5px;">Business Name</label>
                <input type="text" name="business_name" value="<?php echo e($profile['business_name'] ?? ''); ?>" placeholder="e.g. Aline Electronics & Motors" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-size:13.5px;">
            </div>
            <div class="form-group" style="margin-bottom:14px;">
                <label style="font-size:13px;font-weight:600;color:var(--text-soft);display:block;margin-bottom:5px;">Business Description</label>
                <textarea name="business_description" rows="3" placeholder="Tell buyers about your business..." style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-size:13.5px;resize:vertical;font-family:inherit;"><?php echo e($profile['business_description'] ?? ''); ?></textarea>
            </div>
            <div class="form-group" style="margin-bottom:14px;">
                <label style="font-size:13px;font-weight:600;color:var(--text-soft);display:block;margin-bottom:5px;">Typical Response Time (hours)</label>
                <select name="response_time_hours" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-size:13.5px;background:#fff;">
                    <option value="1" <?php echo (int)($profile['response_time_hours'] ?? 1) === 1 ? 'selected' : ''; ?>>Within 1 hour</option>
                    <option value="3" <?php echo (int)($profile['response_time_hours'] ?? 1) === 3 ? 'selected' : ''; ?>>Within 3 hours</option>
                    <option value="6" <?php echo (int)($profile['response_time_hours'] ?? 1) === 6 ? 'selected' : ''; ?>>Within 6 hours</option>
                    <option value="12" <?php echo (int)($profile['response_time_hours'] ?? 1) === 12 ? 'selected' : ''; ?>>Within 12 hours</option>
                    <option value="24" <?php echo (int)($profile['response_time_hours'] ?? 1) === 24 ? 'selected' : ''; ?>>Within 24 hours</option>
                    <option value="48" <?php echo (int)($profile['response_time_hours'] ?? 1) === 48 ? 'selected' : ''; ?>>Within 2 days</option>
                </select>
            </div>
            <button type="submit" class="btn btn--primary btn--sm">Save Business Profile</button>
        </form>
    </div>

    <!-- Social Links -->
    <div class="card" style="padding:20px;margin-bottom:16px;">
        <h2 style="font-size:15px;font-weight:800;margin:0 0 4px;">Social & Contact Links</h2>
        <p style="font-size:12.5px;color:var(--text-mute);margin:0 0 16px;">Let buyers reach you on their preferred platform.</p>
        <form method="post" action="">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_social">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;" class="settings-grid">
                <div class="form-group">
                    <label style="font-size:13px;font-weight:600;color:var(--text-soft);display:block;margin-bottom:5px;">WhatsApp</label>
                    <input type="text" name="social_whatsapp" value="<?php echo e($profile['social_whatsapp'] ?? ''); ?>" placeholder="+250788000000" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-size:13.5px;">
                </div>
                <div class="form-group">
                    <label style="font-size:13px;font-weight:600;color:var(--text-soft);display:block;margin-bottom:5px;">Facebook</label>
                    <input type="url" name="social_facebook" value="<?php echo e($profile['social_facebook'] ?? ''); ?>" placeholder="https://facebook.com/yourpage" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-size:13.5px;">
                </div>
                <div class="form-group">
                    <label style="font-size:13px;font-weight:600;color:var(--text-soft);display:block;margin-bottom:5px;">Instagram</label>
                    <input type="url" name="social_instagram" value="<?php echo e($profile['social_instagram'] ?? ''); ?>" placeholder="https://instagram.com/yourhandle" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-size:13.5px;">
                </div>
                <div class="form-group">
                    <label style="font-size:13px;font-weight:600;color:var(--text-soft);display:block;margin-bottom:5px;">Twitter / X</label>
                    <input type="url" name="social_twitter" value="<?php echo e($profile['social_twitter'] ?? ''); ?>" placeholder="https://twitter.com/yourhandle" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-size:13.5px;">
                </div>
            </div>
            <button type="submit" class="btn btn--primary btn--sm" style="margin-top:8px;">Save Social Links</button>
        </form>
    </div>

    <!-- Preferred Categories -->
    <div class="card" style="padding:20px;margin-bottom:16px;">
        <h2 style="font-size:15px;font-weight:800;margin:0 0 4px;">Preferred Categories</h2>
        <p style="font-size:12.5px;color:var(--text-mute);margin:0 0 16px;">Select the categories you sell in most. This helps buyers find you.</p>
        <form method="post" action="">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_categories">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px;">
                <?php foreach ($cats as $c): 
                    $isPreferred = in_array((int)$c['id'], $preferredCats, true);
                ?>
                    <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1.5px solid <?php echo $isPreferred ? 'var(--brand-500)' : 'var(--border)'; ?>;border-radius:9px;cursor:pointer;background:<?php echo $isPreferred ? 'var(--brand-50)' : '#fff'; ?>;transition:all 150ms ease;">
                        <input type="checkbox" name="preferred_categories[]" value="<?php echo (int)$c['id']; ?>" <?php echo $isPreferred ? 'checked' : ''; ?> style="accent-color:var(--brand-500);">
                        <span style="font-size:13px;font-weight:500;color:var(--text);"><?php echo e($c['name']); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn--primary btn--sm" style="margin-top:14px;">Save Categories</button>
        </form>
    </div>

    <!-- Seller Stats Summary -->
    <div class="card" style="padding:20px;">
        <h2 style="font-size:15px;font-weight:800;margin:0 0 14px;">Your Seller Stats</h2>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;" class="seller-stats-grid">
            <?php
            $totalListings = (int) $pdo->query("SELECT COUNT(*) FROM listings WHERE seller_id = $uid")->fetchColumn();
            $totalViews = (int) $pdo->query("SELECT COALESCE(SUM(views_count),0) FROM listings WHERE seller_id = $uid")->fetchColumn();
            $totalFavs = (int) $pdo->query("SELECT COALESCE(SUM(favorites_count),0) FROM listings WHERE seller_id = $uid")->fetchColumn();
            $ratingAvg = $profile['rating_average'] ?? 0;
            $ratingCount = $profile['rating_count'] ?? 0;
            ?>
            <div style="text-align:center;padding:14px;background:var(--bg-soft);border-radius:10px;">
                <div style="font-size:22px;font-weight:800;color:var(--text);"><?php echo $totalListings; ?></div>
                <div style="font-size:11px;color:var(--text-mute);margin-top:2px;">Listings</div>
            </div>
            <div style="text-align:center;padding:14px;background:var(--bg-soft);border-radius:10px;">
                <div style="font-size:22px;font-weight:800;color:var(--text);"><?php echo number_format($totalViews); ?></div>
                <div style="font-size:11px;color:var(--text-mute);margin-top:2px;">Total Views</div>
            </div>
            <div style="text-align:center;padding:14px;background:var(--bg-soft);border-radius:10px;">
                <div style="font-size:22px;font-weight:800;color:var(--text);"><?php echo $totalFavs; ?></div>
                <div style="font-size:11px;color:var(--text-mute);margin-top:2px;">Favorites</div>
            </div>
            <div style="text-align:center;padding:14px;background:var(--bg-soft);border-radius:10px;">
                <div style="font-size:22px;font-weight:800;color:var(--accent-500);"><?php echo number_format((float)$ratingAvg, 1); ?> ★</div>
                <div style="font-size:11px;color:var(--text-mute);margin-top:2px;"><?php echo $ratingCount; ?> reviews</div>
            </div>
        </div>
    </div>
</div>

<style>
@media (max-width: 600px) {
    .settings-grid { grid-template-columns: 1fr !important; }
    .seller-stats-grid { grid-template-columns: 1fr 1fr !important; }
}
</style>
<?php seller_page_end(); ?>
<script>
function setTheme(theme) {
    document.getElementById('themeInput').value = theme;
    document.querySelectorAll('.theme-btn').forEach(function(b) {
        b.classList.remove('is-selected');
        b.setAttribute('aria-pressed', 'false');
    });
    event.currentTarget.classList.add('is-selected');
    event.currentTarget.setAttribute('aria-pressed', 'true');
    // Apply theme immediately for preview
    var html = document.documentElement;
    if (theme === 'system') {
        try { localStorage.removeItem('isoko-theme'); } catch(e) {}
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            html.setAttribute('data-theme', 'dark');
        } else {
            html.setAttribute('data-theme', 'light');
        }
    } else {
        html.setAttribute('data-theme', theme);
        try { localStorage.setItem('isoko-theme', theme); } catch(e) {}
    }
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
