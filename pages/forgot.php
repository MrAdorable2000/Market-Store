<?php
/**
 * pages/forgot.php
 * --------------------------------------------------------------------
 * Password reset request. Generates a token (with 1-hour expiry) and,
 * because this is Phase 1 (no email service configured), shows the
 * reset link directly on screen so a developer/student can test the
 * full flow end-to-end.
 *
 * In production, replace the "show link" step with email sending.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$sent      = false;
$resetLink = null;
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = t('errors.invalid_token');
    } else {
        $email  = trim($_POST['email'] ?? '');
        $result = send_reset_token($email);
        if (!$result) {
            // For privacy, do not reveal that the email does not exist
            $sent = true;
        } else {
            $sent      = true;
            $resetLink = APP_URL . '/pages/reset.php?token=' . $result['token'];
        }
    }
}

$pageTitle = t('auth.forgot.title');
$activePage = 'auth';
$authImage = real_image('auth-login', 'house');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="auth-wrap" style="--auth-image: url('<?php echo e($authImage); ?>')">
    <?php echo back_button(APP_URL . '/pages/login.php', 'Back', 'glass'); ?>
    <div style="position:relative;z-index:2;width:100%;max-width:460px;display:flex;flex-direction:column;align-items:center;">
        <div class="auth-brand">
            <?php echo brand_mark_html(40); ?>
            <span>Isoko<span class="brand__accent">Ryacu</span></span>
        </div>
        <div class="auth-card">
            <span class="auth-card__eyebrow">
                <svg viewBox="0 0 24 24" width="14" height="14"><path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 0 0-12 0v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0v1a3 3 0 0 1-6 0v-1m6 0H10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Password reset
            </span>
            <h1><?php echo e(t('auth.forgot.title')); ?></h1>
            <p class="auth-sub"><?php echo e(t('auth.forgot.sub')); ?></p>

            <?php if ($error): ?>
                <div class="flash flash--error" role="alert"><?php echo e($error); ?></div>
            <?php endif; ?>

            <?php if ($sent): ?>
                <div class="flash flash--success" role="alert">
                    If an account exists for that email, a reset link has been generated.
                    <?php if ($resetLink && APP_DEBUG): ?>
                        <div style="margin-top:10px;font-size:13px;">
                            <strong>DEV MODE reset link:</strong>
                            <br><a href="<?php echo e($resetLink); ?>"><?php echo e($resetLink); ?></a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo APP_URL; ?>/pages/forgot.php" novalidate>
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label for="email"><?php echo e(t('form.email')); ?></label>
                    <input type="email" id="email" name="email" required autocomplete="email" placeholder="you@example.com">
                </div>
                <button type="submit" class="btn btn--primary btn--block btn--lg"><?php echo e(t('buttons.send_reset')); ?></button>
            </form>

            <p class="auth-alt"><?php echo e(t('auth.login.title')); ?> <a href="<?php echo APP_URL; ?>/pages/login.php"><?php echo e(t('buttons.sign_in')); ?></a></p>
        </div>
        <p class="auth-tagline"><?php echo e(t('auth.security_tagline')); ?></p>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
