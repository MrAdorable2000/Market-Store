<?php
/**
 * pages/register.php
 * --------------------------------------------------------------------
 * New account creation. On POST, validates input, hashes the password
 * with bcrypt, inserts via prepared statement, auto-logs the user in,
 * and redirects them to the correct dashboard for their role.
 *
 * ROLE DETERMINATION (server-side, no visible role selector on the form):
 *   /pages/register.php            → buyer registration (USER role)
 *   /pages/register.php?type=seller → seller registration (SELLER role)
 *
 * The ?type= parameter is read from the URL ONLY (never from $_POST), and is
 * validated against a server-side whitelist. Any other value (including
 * 'admin', 'super_admin', or random strings) silently defaults to 'buyer'.
 * This is the privilege-escalation guard: a browser cannot cause an admin
 * account to be created no matter what it sends.
 *
 * After successful registration the user is auto-logged-in and sent to:
 *   buyer  → homepage              (their browsing dashboard)
 *   seller → /pages/seller/dashboard.php
 *
 * UI: full-bleed African entrepreneur photo background with brand color
 *     overlay; centered glassmorphism form card on top. The card title
 *     changes to reflect whether this is a buyer or seller registration.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Already-logged-in users skip the form
if (is_logged_in()) {
    redirect(redirect_after_auth_url());
}

// --- Determine the registration context (buyer vs seller).
//     Priority: POST 'role' field > URL ?type= > default 'buyer'.
//     The hint is whitelisted to ['buyer','seller'] — anything else
//     (including 'admin','super_admin') silently becomes 'buyer'. This
//     is the privilege-escalation guard: a browser cannot cause an admin
//     account to be created no matter what it sends. register_user()
//     re-validates the hint against its own whitelist as a 2nd layer. ---
$rawType = (string)($_POST['role'] ?? $_GET['type'] ?? '');
$roleHint = in_array(strtolower($rawType), ['buyer', 'seller'], true)
    ? strtolower($rawType)
    : 'buyer';
$isSellerRegistration = ($roleHint === 'seller');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = t('errors.invalid_token');
    } else {
        // Build the data array for register_user(). The role_hint comes
        // from the SERVER-SIDE $roleHint variable (whitelisted above from
        // POST/GET), NOT directly from $_POST — so a modified POST body
        // cannot escalate privileges. register_user() re-validates.
        $result = register_user([
            'full_name' => $_POST['full_name'] ?? '',
            'email'     => $_POST['email'] ?? '',
            'phone'     => $_POST['phone'] ?? '',
            'location'  => $_POST['location'] ?? '',
            'password'  => $_POST['password'] ?? '',
            'role_hint' => $roleHint,
        ]);

        if (isset($result['error'])) {
            $errors[] = $result['error'];
            // Keep useful profile fields after validation, but never persist the email
            // back into the form/session where it can unexpectedly reappear.
            remember_old(['full_name', 'phone', 'location']);
        } else {
            // Registration successful — render a brief confirmation screen
            // that shows for 1.5 seconds, then auto-redirects to the login
            // page where the user logs in manually.
            //   REGISTER → confirmation screen (1.5s) → login form → dashboard
            $registeredName = $_POST['full_name'];
            $registeredEmail = $_POST['email'];
            $loginUrl = APP_URL . '/pages/login.php';

            $pageTitle = t('auth.register.success_title');
            $activePage = 'auth';
            require_once __DIR__ . '/../includes/header.php';
            ?>
            <div class="reg-done">
                <div class="reg-done__card">
                    <div class="reg-done__check">
                        <svg viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    </div>
                    <h1 class="reg-done__title"><?php echo e(t('auth.register.success_title')); ?></h1>
                    <p class="reg-done__sub"><?php echo e(t('flash.register_success', ['name' => $registeredName])); ?></p>
                    <p class="reg-done__email"><?php echo e($registeredEmail); ?></p>
                    <div class="reg-done__redirect">
                        <span class="reg-done__spinner"></span>
                        <span><?php echo e(t('auth.register.redirecting_to_login')); ?></span>
                    </div>
                    <a href="<?php echo e($loginUrl); ?>" class="btn btn--primary btn--block btn--lg reg-done__btn"><?php echo e(t('buttons.sign_in')); ?></a>
                </div>
                <div class="reg-done__progress"><div class="reg-done__progress-bar"></div></div>
            </div>
            <style>
            .reg-done {
                position: fixed; inset: 0; z-index: 9999;
                display: grid; place-items: center; padding: 24px;
                background: radial-gradient(circle at 50% 30%, #0a3d3a 0%, #0b1817 70%);
            }
            .reg-done__card {
                position: relative; z-index: 2;
                width: 100%; max-width: 420px;
                background: #fff; border-radius: 24px; padding: 40px 32px 32px;
                text-align: center;
                box-shadow: 0 24px 64px rgba(0,0,0,.3);
                animation: reg-done-in 500ms cubic-bezier(.34,1.56,.64,1);
            }
            .reg-done__check {
                width: 80px; height: 80px; border-radius: 50%;
                display: grid; place-items: center; margin: 0 auto 20px;
                background: linear-gradient(135deg, #14a594, #0c8478);
                color: #fff;
                box-shadow: 0 8px 24px rgba(20,165,148,.35), inset 0 1px 0 rgba(255,255,255,.2);
                animation: reg-done-pop 600ms cubic-bezier(.34,1.56,.64,1) 150ms both;
            }
            .reg-done__title {
                margin: 0 0 8px; font-size: 22px; font-weight: 800;
                color: #0d1f1d; letter-spacing: -.02em;
            }
            .reg-done__sub {
                margin: 0 0 4px; font-size: 14px; color: #496461; line-height: 1.5;
            }
            .reg-done__email {
                margin: 0 0 20px; font-size: 13px; color: #7c8f8d;
                font-weight: 600; word-break: break-all;
            }
            .reg-done__redirect {
                display: flex; align-items: center; justify-content: center; gap: 8px;
                font-size: 12.5px; color: #7c8f8d; margin-bottom: 16px;
            }
            .reg-done__spinner {
                width: 14px; height: 14px; border-radius: 50%;
                border: 2px solid #d2f5ec; border-top-color: #14a594;
                animation: reg-done-spin 700ms linear infinite;
            }
            .reg-done__btn { margin-top: 4px; }
            .reg-done__progress {
                position: fixed; bottom: 0; left: 0; right: 0; height: 4px;
                background: rgba(255,255,255,.08); z-index: 3;
            }
            .reg-done__progress-bar {
                height: 100%; width: 0;
                background: linear-gradient(90deg, #14a594, #3fc2a5);
                animation: reg-done-fill 1500ms linear forwards;
            }
            @keyframes reg-done-in { from { opacity: 0; transform: translateY(20px) scale(.96); } to { opacity: 1; transform: none; } }
            @keyframes reg-done-pop { 0% { transform: scale(0) rotate(-20deg); } 60% { transform: scale(1.15) rotate(5deg); } 100% { transform: scale(1) rotate(0); } }
            @keyframes reg-done-spin { to { transform: rotate(360deg); } }
            @keyframes reg-done-fill { from { width: 0; } to { width: 100%; } }
            @media (prefers-reduced-motion: reduce) {
                .reg-done__card, .reg-done__check, .reg-done__spinner, .reg-done__progress-bar { animation: none; }
                .reg-done__progress-bar { width: 100%; }
            }
            </style>
            <meta http-equiv="refresh" content="1.5;url=<?php echo e($loginUrl); ?>">
            <script>
            setTimeout(function () { window.location.replace(<?php echo json_encode($loginUrl); ?>); }, 1500);
            </script>
            <?php
            require_once __DIR__ . '/../includes/footer.php';
            exit;
        }
    }
}

// Resolve the real African entrepreneur photo for the background
$authImage = real_image('auth-register', 'house');

// Page title — always the same now (the role selector is on the form)
$titleKey = 'auth.register.title';
$subKey   = 'auth.register.sub';

$pageTitle = t($titleKey);
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
                <?php if ($isSellerRegistration): ?>
                    <svg viewBox="0 0 24 24" width="14" height="14"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="8.5" cy="7" r="4" fill="none" stroke="currentColor" stroke-width="2"/></svg>
                    <?php echo e(t('auth.register.seller_eyebrow')); ?>
                <?php else: ?>
                    <svg viewBox="0 0 24 24" width="14" height="14"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="8.5" cy="7" r="4" fill="none" stroke="currentColor" stroke-width="2"/></svg>
                    Register
                <?php endif; ?>
            </span>
            <h1><?php echo e(t($titleKey)); ?></h1>
            <p class="auth-sub"><?php echo e(t($subKey)); ?></p>

            <?php if ($errors): ?>
                <div class="flash flash--error" role="alert">
                    <ul style="margin:0;padding-left:18px;">
                        <?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form id="registerForm" method="post" action="<?php echo APP_URL; ?>/pages/register.php" autocomplete="off" novalidate>
                <?php echo csrf_field(); ?>

                <!-- Role selector: Buyer vs Seller (server-side whitelisted) -->
                <div class="form-group">
                    <label><?php echo e(t('auth.register.role_label')); ?></label>
                    <div class="role-selector" role="radiogroup" aria-label="<?php echo e(t('auth.register.role_label')); ?>">
                        <label class="role-card<?php echo !$isSellerRegistration ? ' is-selected' : ''; ?>">
                            <input type="radio" name="role" value="buyer" <?php echo !$isSellerRegistration ? 'checked' : ''; ?>>
                            <span class="role-card__icon">
                                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="9" cy="7" r="4"/><path d="M3 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/><path d="M16 3.13a4 4 0 0 1 0 7.75M22 21v-2a4 4 0 0 0-3-3.87" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                            <span class="role-card__body">
                                <strong><?php echo e(t('auth.register.role_buyer_title')); ?></strong>
                                <span><?php echo e(t('auth.register.role_buyer_desc')); ?></span>
                            </span>
                        </label>
                        <label class="role-card<?php echo $isSellerRegistration ? ' is-selected' : ''; ?>">
                            <input type="radio" name="role" value="seller" <?php echo $isSellerRegistration ? 'checked' : ''; ?>>
                            <span class="role-card__icon">
                                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 9l1-5h16l1 5M5 9v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9M9 13h6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                            <span class="role-card__body">
                                <strong><?php echo e(t('auth.register.role_seller_title')); ?></strong>
                                <span><?php echo e(t('auth.register.role_seller_desc')); ?></span>
                            </span>
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label for="full_name"><?php echo e(t('form.full_name')); ?></label>
                    <input type="text" id="full_name" name="full_name" required value="<?php echo old('full_name'); ?>" autocomplete="name" placeholder="Your full name">
                </div>
                <div class="form-group">
                    <label for="email"><?php echo e(t('form.email')); ?></label>
                    <input type="email" id="email" name="email" required value="" autocomplete="off" autocapitalize="none" spellcheck="false" readonly data-form-type="other" data-lpignore="true" onfocus="this.removeAttribute('readonly');" placeholder="you@example.com">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="phone"><?php echo e(t('form.phone_optional')); ?></label>
                        <input type="tel" id="phone" name="phone" value="<?php echo old('phone'); ?>" placeholder="+250 788 000 000" autocomplete="tel">
                    </div>
                    <div class="form-group">
                        <label for="location"><?php echo e(t('form.location_optional')); ?></label>
                        <input type="text" id="location" name="location" value="<?php echo old('location'); ?>" placeholder="Kigali, Rwanda" autocomplete="address-level2">
                    </div>
                </div>
                <div class="form-group">
                    <label for="password"><?php echo e(t('form.password')); ?></label>
                    <div class="input-with-action">
                        <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password" placeholder="At least 8 characters">
                        <button type="button" class="toggle" aria-label="Show password" onclick="
                            var i=document.getElementById('password');
                            i.type = i.type==='password' ? 'text' : 'password';
                            this.setAttribute('aria-label', i.type==='password' ? 'Show password' : 'Hide password');
                        ">
                            <svg viewBox="0 0 24 24" width="18" height="18"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>
                        </button>
                    </div>
                    <div class="form-hint"><?php echo e(t('form.password_hint_strong')); ?></div>
                </div>
                <div class="form-group">
                    <label for="password_confirm"><?php echo e(t('form.password_confirm')); ?></label>
                    <input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password" placeholder="Re-enter your password">
                </div>
                <div class="form-group">
                    <label class="filters__option" style="font-size:14px;line-height:1.4;">
                        <input type="checkbox" name="agree_terms" required>
                        <span><?php echo e(t('form.agree_terms')); ?></span>
                    </label>
                </div>
                <button type="submit" class="btn btn--primary btn--block btn--lg"><?php echo e(t('buttons.create_account')); ?></button>
            </form>

            <p class="auth-alt">
                <?php echo e(t('nav.login')); ?> Isoko Ryacu? <a href="<?php echo APP_URL; ?>/pages/login.php"><?php echo e(t('buttons.sign_in')); ?></a>
            </p>
        </div>

        <!-- Tagline below the card -->
        <p class="auth-tagline"><?php echo e(t('auth.seller_tagline')); ?></p>
    </div>
</div>

<style>
/* Role selector — professional toggle cards */
.role-selector {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
.role-card {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
    padding: 14px;
    border: 1.5px solid var(--border, #e7ebec);
    border-radius: 12px;
    cursor: pointer;
    transition: all 160ms cubic-bezier(.4,0,.2,1);
    background: var(--bg-card, #fff);
}
.role-card input[type="radio"] {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.role-card__icon {
    width: 36px; height: 36px;
    border-radius: 10px;
    display: grid; place-items: center;
    background: var(--bg-soft, #f6f8f9);
    color: var(--text-mute, #7c8f8d);
    transition: all 160ms ease;
}
.role-card__body { display: flex; flex-direction: column; gap: 2px; }
.role-card__body strong {
    font-size: 13.5px;
    font-weight: 600;
    color: var(--text, #0d1f1d);
}
.role-card__body span {
    font-size: 11.5px;
    color: var(--text-mute, #7c8f8d);
    line-height: 1.4;
}
.role-card:hover {
    border-color: var(--brand-400, #3fc2a5);
    background: var(--brand-50, #effcf9);
}
.role-card.is-selected {
    border-color: var(--brand-500, #14a594);
    background: var(--brand-50, #effcf9);
    box-shadow: 0 0 0 3px rgba(20,165,148,.1);
}
.role-card.is-selected .role-card__icon {
    background: var(--brand-500, #14a594);
    color: #fff;
}
@media (max-width: 420px) {
    .role-selector { grid-template-columns: 1fr; }
}
</style>
<script>
// Role selector: toggle the is-selected class on the role cards so the
// user gets immediate visual feedback when they pick "Buy" or "Sell".
// The radio inputs are visually hidden, so we drive the highlighted state
// from JS based on the radio's checked state.
(function () {
    var cards = document.querySelectorAll('.role-card');
    if (!cards.length) return;
    cards.forEach(function (card) {
        var input = card.querySelector('input[type="radio"]');
        if (!input) return;
        // Update on click (covers mouse + touch + keyboard activation)
        input.addEventListener('change', function () {
            cards.forEach(function (c) { c.classList.remove('is-selected'); });
            card.classList.add('is-selected');
        });
        // Also update on click of the label itself (some browsers fire
        // change before the class is moved, so this is belt-and-braces)
        card.addEventListener('click', function () {
            cards.forEach(function (c) { c.classList.remove('is-selected'); });
            card.classList.add('is-selected');
            input.checked = true;
        });
    });
})();
</script>
<script src="<?php echo APP_URL; ?>/assets/js/auth.js" defer></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
