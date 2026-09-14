<?php
/**
 * pages/login.php
 * --------------------------------------------------------------------
 * Email + password login. On success, redirects to the correct dashboard
 * for the user's role via the centralized redirect_after_auth_url() helper
 * (shared with register.php so login and registration use the SAME routing
 * logic).
 *
 * UI: full-bleed African photo background with brand color overlay;
 *     centered glassmorphism form card on top.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_log.php';

// Already-logged-in users skip the form
if (is_logged_in()) {
    redirect(redirect_after_auth_url());
}

// Show the registration success banner if the user was just redirected from
// the registration page (they must log in to continue).
$justRegistered = !empty($_GET['registered']);
$idleSignedOut = (($_GET['reason'] ?? '') === 'idle');

$error = '';
$successMsg = '';
if ($justRegistered) {
    $successMsg = t('flash.register_success_login');
} elseif ($idleSignedOut) {
    $successMsg = t('flash.idle_signed_out');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = t('errors.invalid_token');
    } else {
        $email    = trim($_POST['email']    ?? '');
        $password = (string)($_POST['password'] ?? '');
        if (login_user($email, $password)) {
            $user = current_user();

            // Record admin sign-ins in the system log (admins only,
            // so the log stays focused on the control center).
            if (in_array(strtoupper($user['role_name'] ?? ''), ['ADMIN', 'SUPER_ADMIN'], true)) {
                admin_log('auth', 'admin_login', 'Admin "' . $user['full_name'] . '" signed in');
            }

            flash_set('success', t('flash.welcome_back', ['name' => $user['full_name']]));

            // Centralized role-based redirect:
            //   ADMIN / SUPER_ADMIN → /pages/admin/dashboard.php
            //   SELLER              → /pages/seller/dashboard.php
            //   USER (buyer)        → /  (homepage)
            // An explicit ?next= (same-app) URL overrides the role default,
            // so deep-links like /pages/favorites.php still work.
            $next = $_POST['next'] ?? $_GET['next'] ?? '';
            redirect(redirect_after_auth_url($user, $next));
        } else {
            $feedback = $_SESSION['_login_feedback'] ?? ['type' => 'invalid', 'remaining' => null];
            if (($feedback['type'] ?? '') === 'locked') {
                $error = t('errors.login_locked', ['minutes' => (int)($feedback['minutes'] ?? 15)]);
            } elseif (isset($feedback['remaining']) && $feedback['remaining'] !== null) {
                $error = t('errors.login_failed_attempts', ['remaining' => (int)$feedback['remaining']]);
            } else {
                $error = t('errors.login_failed');
            }
            // Do not keep the submitted email in session: authentication pages should
            // open with an empty email field and let the visitor enter the account.
            clear_old();
        }
    }
}

// Resolve the real African photo for the background
$authImage = real_image('auth-login', 'house');

$pageTitle = t('auth.login.title');
$activePage = 'auth';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="auth-wrap" style="--auth-image: url('<?php echo e($authImage); ?>')">
    <?php echo back_button(APP_URL . '/', 'Back', 'glass'); ?>
    <div style="position:relative;z-index:2;width:100%;max-width:460px;display:flex;flex-direction:column;align-items:center;">
        <!-- Brand wordmark above the card -->
        <div class="auth-brand">
            <?php echo brand_mark_html(40); ?>
            <span>Isoko<span class="brand__accent">Ryacu</span></span>
        </div>

        <!-- Frosted glass form card -->
        <div class="auth-card">
            <span class="auth-card__eyebrow">
                <svg viewBox="0 0 24 24" width="14" height="14"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l-5-5 5-5M5 12h12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Sign in
            </span>
            <h1><?php echo e(t('auth.login.title')); ?></h1>
            <p class="auth-sub"><?php echo e(t('auth.login.sub')); ?></p>

            <?php if ($successMsg): ?>
                <div class="reg-success" role="status">
                    <div class="reg-success__icon">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    </div>
                    <div class="reg-success__body">
                        <strong><?php echo e(t('auth.register.success_title')); ?></strong>
                        <span><?php echo e($successMsg); ?></span>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="auth-error" role="alert" aria-live="assertive">
                    <span class="auth-error__mark" aria-hidden="true">!</span>
                    <span><?php echo e($error); ?></span>
                </div>
            <?php endif; ?>

            <form id="loginForm" method="post" action="<?php echo APP_URL; ?>/pages/login.php" autocomplete="off" novalidate>
                <?php echo csrf_field(); ?>
                <?php if (!empty($_GET['next'])): ?>
                    <input type="hidden" name="next" value="<?php echo e($_GET['next']); ?>">
                <?php endif; ?>
                <div class="form-group">
                    <label for="email"><?php echo e(t('form.email')); ?></label>
                    <input type="email" id="email" name="email" required value="" autocomplete="off" autocapitalize="none" spellcheck="false" readonly data-form-type="other" data-lpignore="true" onfocus="this.removeAttribute('readonly');" placeholder="you@example.com">
                </div>
                <div class="form-group">
                    <label for="password"><?php echo e(t('form.password')); ?></label>
                    <div class="input-with-action">
                        <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="Enter your password">
                        <button type="button" class="toggle" aria-label="Show password" onclick="
                            var i=document.getElementById('password');
                            i.type = i.type==='password' ? 'text' : 'password';
                            this.setAttribute('aria-label', i.type==='password' ? 'Show password' : 'Hide password');
                        ">
                            <svg viewBox="0 0 24 24" width="18" height="18"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>
                        </button>
                    </div>
                </div>
                <div class="form-group" style="display:flex;justify-content:space-between;align-items:center;">
                    <label class="filters__option" style="margin:0;">
                        <input type="checkbox" name="remember" value="1"> <span><?php echo e(t('form.remember_me')); ?></span>
                    </label>
                    <a href="<?php echo APP_URL; ?>/pages/forgot.php" style="font-size:13px;font-weight:600;"><?php echo e(t('form.forgot_password')); ?></a>
                </div>
                <button type="submit" class="btn btn--primary btn--block btn--lg"><?php echo e(t('auth.login.eyebrow')); ?></button>
            </form>

            <p class="auth-alt"><?php echo e(t('nav.login')); ?> Isoko Ryacu? <a href="<?php echo APP_URL; ?>/pages/register.php"><?php echo e(t('buttons.create_account')); ?></a></p>
        </div>

        <!-- Tagline below the card -->
        <p class="auth-tagline"><?php echo e(t('auth.brand_tagline')); ?></p>
    </div>
</div>

<style>
/* Registration success banner — polished, professional confirmation */
.auth-error {
    display:flex;
    align-items:center;
    gap:10px;
    width:100%;
    box-sizing:border-box;
    margin:0 0 18px;
    padding:12px 14px;
    border:1px solid #f1b8b8;
    border-left:4px solid #d84b4b;
    border-radius:12px;
    background:linear-gradient(135deg,#fff7f7 0%,#fff 100%);
    color:#8f2424;
    font-size:13px;
    line-height:1.45;
    font-weight:600;
    box-shadow:0 4px 14px rgba(170,45,45,.08);
    animation:auth-error-in 260ms ease-out;
}
.auth-error__mark {
    flex:0 0 22px;
    width:22px;
    height:22px;
    display:grid;
    place-items:center;
    border-radius:50%;
    background:#d84b4b;
    color:#fff;
    font-size:14px;
    font-weight:800;
}
@keyframes auth-error-in {
    from { opacity:0; transform:translateY(-5px); }
    to { opacity:1; transform:translateY(0); }
}
@media (prefers-reduced-motion: reduce) {
    .auth-error { animation:none; }
}

.reg-success {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px 16px;
    margin-bottom: 18px;
    background: linear-gradient(135deg, var(--brand-50, #effcf9) 0%, #ffffff 100%);
    border: 1px solid var(--brand-200, #a8ead9);
    border-left: 4px solid var(--brand-500, #14a594);
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(20,165,148,.08);
    animation: reg-success-in 400ms cubic-bezier(.4,0,.2,1);
}
.reg-success__icon {
    flex: 0 0 32px;
    width: 32px; height: 32px;
    border-radius: 50%;
    display: grid; place-items: center;
    background: var(--brand-500, #14a594);
    color: #fff;
    box-shadow: 0 2px 8px rgba(20,165,148,.3), inset 0 1px 0 rgba(255,255,255,.2);
    animation: reg-success-pop 500ms cubic-bezier(.34,1.56,.64,1) 100ms both;
}
.reg-success__body {
    flex: 1;
    min-width: 0;
    padding-top: 2px;
}
.reg-success__body strong {
    display: block;
    font-size: 13.5px;
    font-weight: 700;
    color: var(--text, #0d1f1d);
    letter-spacing: -.005em;
    margin-bottom: 2px;
}
.reg-success__body span {
    display: block;
    font-size: 12.5px;
    color: var(--text-soft, #496461);
    line-height: 1.5;
}
@keyframes reg-success-in {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
}
@keyframes reg-success-pop {
    0%   { transform: scale(0); }
    60%  { transform: scale(1.15); }
    100% { transform: scale(1); }
}
@media (prefers-reduced-motion: reduce) {
    .reg-success, .reg-success__icon { animation: none; }
}
</style>
<script src="<?php echo APP_URL; ?>/assets/js/auth.js" defer></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
