<?php
/**
 * pages/reset.php
 * --------------------------------------------------------------------
 * Final step of the password reset flow: validate the token, accept a
 * new password, hash it with bcrypt, and clear the token.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$token  = $_GET['token']  ?? '';
$error  = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = t('errors.invalid_token');
    } else {
        $token        = $_POST['token'] ?? $token;
        $newPassword  = (string)($_POST['password'] ?? '');
        $confirmPass  = (string)($_POST['password_confirm'] ?? '');
        if ($newPassword !== $confirmPass) {
            $error = t('errors.password_mismatch');
        } elseif (strlen($newPassword) < 8) {
            $error = t('errors.password_short');
        } elseif (!reset_password($token, $newPassword)) {
            $error = t('errors.reset_invalid');
        } else {
            $success = true;
            flash_set('success', t('flash.password_changed'));
            redirect(APP_URL . '/pages/login.php');
        }
    }
}

// On GET, validate the token so we can show a friendly error early
$user = $token ? verify_reset_token($token) : null;
if (!$user && !$success) {
    $error = $error ?: t('errors.reset_invalid');
}

$pageTitle = t('auth.reset.title');
$activePage = 'auth';
$authImage = real_image('auth-login', 'house');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="auth-wrap" style="--auth-image: url('<?php echo e($authImage); ?>')">
    <?php echo back_button(APP_URL . '/pages/forgot.php', 'Back', 'glass'); ?>
    <div style="position:relative;z-index:2;width:100%;max-width:460px;display:flex;flex-direction:column;align-items:center;">
        <div class="auth-brand">
            <?php echo brand_mark_html(40); ?>
            <span>Isoko<span class="brand__accent">Ryacu</span></span>
        </div>
        <div class="auth-card">
            <span class="auth-card__eyebrow">
                <svg viewBox="0 0 24 24" width="14" height="14"><path d="M19 11H5m14 0a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2m14 0V7a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                New password
            </span>
            <h1><?php echo e(t('auth.reset.title')); ?></h1>
            <p class="auth-sub"><?php echo e(t('auth.reset.sub')); ?></p>

            <?php if ($error): ?>
                <div class="flash flash--error" role="alert"><?php echo e($error); ?></div>
                <a href="<?php echo APP_URL; ?>/pages/forgot.php" class="btn btn--secondary btn--block"><?php echo e(t('buttons.request_new_link')); ?></a>
            <?php else: ?>
                <form method="post" action="<?php echo APP_URL; ?>/pages/reset.php" novalidate>
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="token" value="<?php echo e($token); ?>">
                    <div class="form-group">
                        <label for="password"><?php echo e(t('auth.reset.eyebrow')); ?></label>
                        <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password" placeholder="At least 8 characters">
                    </div>
                    <div class="form-group">
                        <label for="password_confirm"><?php echo e(t('form.password_confirm')); ?></label>
                        <input type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password" placeholder="Re-enter new password">
                    </div>
                    <button type="submit" class="btn btn--primary btn--block btn--lg"><?php echo e(t('buttons.update_password')); ?></button>
                </form>
            <?php endif; ?>
        </div>
        <p class="auth-tagline"><?php echo e(t('auth.security_tagline')); ?></p>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
