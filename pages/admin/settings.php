<?php
/**
 * pages/admin/settings.php
 * --------------------------------------------------------------------
 * IsokoRyacu platform settings (Phase 5) — NEW PAGE.
 *
 * Reads and writes the EXISTING `site_settings` table (the one the
 * seed already populated). Only whitelisted keys can be changed, every
 * save is CSRF-guarded and recorded in the system logs.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_icons.php';
require_once __DIR__ . '/../../includes/payment_config.php';
require_role('admin');

// Payment credentials are a SUPER_ADMIN-only responsibility.
$canManagePayments = is_super_admin();

$pdo = db();

/* Load current values (real rows from site_settings) */
$settings = [];
foreach ($pdo->query('SELECT setting_key, setting_value, description FROM site_settings') as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

/* ---------- Page setup ---------- */
$pageTitle         = t('admin.nav_settings');
$activePage        = 'admin';
$adminActivePage   = 'settings';
$extraCss          = '<link rel="stylesheet" href="' . APP_URL . '/assets/css/admin.css">';
$adminPageTitle    = t('admin.nav_settings');
$adminPageSubtitle = t('admin.settings_sub');
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="a-shell" id="aShell">
<?php require __DIR__ . '/../../includes/admin_sidebar.php'; ?>
<div class="a-main">
<?php require __DIR__ . '/../../includes/admin_topbar.php'; ?>
<div class="a-content">

<div class="a-hero">
    <div>
        <h2><?php echo e(t('admin.nav_settings')); ?></h2>
        <p class="a-hero__sub"><?php echo e(t('admin.settings_sub')); ?></p>
    </div>
    <div class="a-hero__actions">
        <a class="a-btn" href="<?php echo APP_URL; ?>/pages/profile.php"><?php echo admin_icon('profile'); ?><span><?php echo e(t('admin.nav_my_profile')); ?></span></a>
    </div>
</div>

<form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="settings_save">

    <!-- Identity -->
    <section class="a-panel">
        <div class="a-panel__head">
            <div>
                <h3><?php echo e(t('admin.settings_identity')); ?></h3>
                <p><?php echo e(t('admin.settings_identity_sub')); ?></p>
            </div>
        </div>
        <div class="a-form">
            <div class="a-form__row">
                <div class="a-field">
                    <label for="site_name"><?php echo e(t('admin.settings_site_name')); ?></label>
                    <input class="a-input" id="site_name" name="site_name" required maxlength="120"
                           value="<?php echo e($settings['site_name'] ?? APP_NAME); ?>">
                    <p class="a-help"><?php echo e(t('admin.settings_site_name_help')); ?></p>
                </div>
                <div class="a-field">
                    <label for="site_tagline"><?php echo e(t('admin.settings_tagline')); ?></label>
                    <input class="a-input" id="site_tagline" name="site_tagline" maxlength="200"
                           value="<?php echo e($settings['site_tagline'] ?? APP_TAGLINE); ?>">
                    <p class="a-help"><?php echo e(t('admin.settings_tagline_help')); ?></p>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact -->
    <section class="a-panel">
        <div class="a-panel__head">
            <div>
                <h3><?php echo e(t('admin.settings_contact')); ?></h3>
                <p><?php echo e(t('admin.settings_contact_sub')); ?></p>
            </div>
        </div>
        <div class="a-form">
            <div class="a-form__row">
                <div class="a-field">
                    <label for="contact_email"><?php echo e(t('admin.settings_email')); ?></label>
                    <input class="a-input" id="contact_email" name="contact_email" type="email" maxlength="160"
                           value="<?php echo e($settings['contact_email'] ?? ''); ?>" placeholder="support@isoko.rw">
                    <p class="a-help"><?php echo e(t('admin.settings_email_help')); ?></p>
                </div>
                <div class="a-field">
                    <label for="contact_phone"><?php echo e(t('admin.settings_phone')); ?></label>
                    <input class="a-input" id="contact_phone" name="contact_phone" maxlength="40"
                           value="<?php echo e($settings['contact_phone'] ?? ''); ?>" placeholder="+250 788 000 000">
                </div>
            </div>
        </div>
    </section>

    <!-- Marketplace behaviour -->
    <section class="a-panel">
        <div class="a-panel__head">
            <div>
                <h3><?php echo e(t('admin.settings_marketplace')); ?></h3>
                <p><?php echo e(t('admin.settings_marketplace_sub')); ?></p>
            </div>
        </div>
        <div class="a-form">
            <div class="a-form__row">
                <div class="a-field">
                    <label for="default_currency"><?php echo e(t('admin.settings_currency')); ?></label>
                    <input class="a-input" id="default_currency" name="default_currency" maxlength="8"
                           value="<?php echo e($settings['default_currency'] ?? DEFAULT_CURRENCY); ?>" placeholder="RWF">
                    <p class="a-help"><?php echo e(t('admin.settings_currency_help')); ?></p>
                </div>
                <div class="a-field">
                    <label for="posts_per_page"><?php echo e(t('admin.settings_per_page')); ?></label>
                    <input class="a-input" id="posts_per_page" name="posts_per_page" type="number" min="1" max="48"
                           value="<?php echo e($settings['posts_per_page'] ?? LISTINGS_PER_PAGE); ?>">
                    <p class="a-help"><?php echo e(t('admin.settings_per_page_help')); ?></p>
                </div>
            </div>
            <div class="a-form__row">
                <label class="a-switch">
                    <input type="checkbox" name="allow_guest_contact" value="1" <?php echo (($settings['allow_guest_contact'] ?? '1') === '1') ? 'checked' : ''; ?>>
                    <span class="a-switch__track"></span>
                    <span class="a-switch__label"><?php echo e(t('admin.settings_guest_contact')); ?></span>
                </label>
                <label class="a-switch">
                    <input type="checkbox" name="enable_dark_mode" value="1" <?php echo (($settings['enable_dark_mode'] ?? '1') === '1') ? 'checked' : ''; ?>>
                    <span class="a-switch__track"></span>
                    <span class="a-switch__label"><?php echo e(t('admin.settings_dark_mode')); ?></span>
                </label>
            </div>
        </div>
    </section>

<?php if ($canManagePayments): ?>
<!-- Payment Providers -->
<section class="a-panel" id="payment-providers">
    <div class="a-panel__head">
        <div>
            <h3>Payment Providers &amp; API</h3>
            <p>Configure live payment providers without editing source code. Secrets are encrypted before storage.</p>
        </div>
        <span class="a-badge">SUPER ADMIN</span>
    </div>

    <?php
    $providerCards = [
        'mtn' => ['label'=>'MTN MoMo','prefix'=>'payment_mtn','fields'=>[
            ['api_user','API User','secret'], ['api_key','API Key','secret'], ['subscription_key','Subscription Key','secret'], ['callback_url','Callback URL','text']]],
        'airtel' => ['label'=>'Airtel Money','prefix'=>'payment_airtel','fields'=>[
            ['client_id','Client ID','secret'], ['client_secret','Client Secret','secret'], ['callback_url','Callback URL','text']]],
        'card' => ['label'=>'Card Payments','prefix'=>'payment_card','fields'=>[
            ['publishable_key','Publishable Key','text'], ['secret_key','Secret Key','secret'], ['webhook_secret','Webhook Signing Secret','secret'], ['callback_url','Webhook URL','text']]],
        'bank' => ['label'=>'Bank Payments','prefix'=>'payment_bank','fields'=>[
            ['provider','Provider Name','text'], ['merchant_id','Merchant ID','secret'], ['api_key','API Key','secret'], ['api_secret','API Secret','secret'], ['callback_url','Callback URL','text']]],
        'crypto' => ['label'=>'Crypto Payments','prefix'=>'payment_crypto','fields'=>[
            ['provider','Provider Name','text'], ['api_key','API Key','secret'], ['api_secret','API Secret','secret'], ['webhook_secret','Webhook Secret','secret'], ['callback_url','Callback URL','text']]],
    ];
    ?>
    <div class="a-form">
        <div class="a-form__row">
            <div class="a-field">
                <label for="payment_platform_fee_percent">Default Marketplace Fee (%)</label>
                <input class="a-input" id="payment_platform_fee_percent" name="payment_platform_fee_percent" type="number" min="0" max="100" step="0.01" value="<?php echo e($settings['payment_platform_fee_percent'] ?? '5'); ?>">
                <p class="a-help">Default is 5%. Change only when necessary.</p>
            </div>
            <div class="a-field">
                <label>Security</label>
                <p class="a-help">API secrets are encrypted at rest. Never place API keys in GitHub, JavaScript, HTML or logs.</p>
            </div>
        </div>

        <?php foreach ($providerCards as $code => $card):
            $prefix = $card['prefix'];
            $enabled = (($settings[$prefix.'_enabled'] ?? '0') === '1');
            $environment = $settings[$prefix.'_environment'] ?? 'sandbox';
        ?>
        <div class="a-panel" style="margin:16px 0 0; border:1px solid var(--a-border);">
            <div class="a-panel__head">
                <div><h3><?php echo e($card['label']); ?></h3><p>Provider credentials and callback configuration.</p></div>
                <label class="a-switch">
                    <input type="checkbox" name="<?php echo e($prefix); ?>_enabled" value="1" <?php echo $enabled ? 'checked' : ''; ?>>
                    <span class="a-switch__track"></span><span class="a-switch__label">Enabled</span>
                </label>
            </div>
            <div class="a-form">
                <?php if ($code === 'mtn' || $code === 'airtel'): ?>
                <div class="a-field">
                    <label>Environment</label>
                    <select class="a-input" name="<?php echo e($prefix); ?>_environment">
                        <option value="sandbox" <?php echo $environment==='sandbox'?'selected':''; ?>>Sandbox</option>
                        <option value="production" <?php echo $environment==='production'?'selected':''; ?>>Production</option>
                    </select>
                </div>
                <?php endif; ?>
                <div class="a-form__row">
                <?php foreach ($card['fields'] as [$field,$label,$kind]):
                    $key=$prefix.'_'.$field;
                    $isSecret=($kind==='secret');
                    $hasSecret=$isSecret && !empty($settings[$key]);
                ?>
                    <div class="a-field">
                        <label for="<?php echo e($key); ?>"><?php echo e($label); ?></label>
                        <input class="a-input" id="<?php echo e($key); ?>" name="<?php echo e($key); ?>" type="<?php echo $isSecret?'password':'text'; ?>" maxlength="500" value="<?php echo $isSecret ? '' : e($settings[$key] ?? ''); ?>" placeholder="<?php echo $hasSecret ? '••••••••  (configured — leave blank to keep)' : ''; ?>" autocomplete="new-password">
                        <?php if ($isSecret): ?><p class="a-help"><?php echo $hasSecret ? 'Configured. Leave blank to keep the current secret.' : 'Not configured yet.'; ?></p><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="a-panel" style="margin:16px 0 0;">
            <div class="a-panel__head"><div><h3>Go-Live Checklist</h3><p>Enable a provider only after its merchant account, HTTPS callback and production credentials are ready.</p></div></div>
            <ul style="margin:0;padding-left:20px;line-height:1.8;color:var(--a-muted);">
                <li>Use Sandbox first and complete successful payment + webhook tests.</li>
                <li>Production callbacks must use HTTPS and validate provider signatures.</li>
                <li>Never trust a frontend success message; payment status must be verified server-side.</li>
                <li>Keep idempotency and reconciliation enabled for every external provider.</li>
            </ul>
        </div>
    </div>
</section>
<?php else: ?>
<section class="a-panel">
    <div class="a-panel__head"><div><h3>Payment Providers</h3><p>Payment API credentials can only be configured by the Super Admin.</p></div></div>
</section>
<?php endif; ?>

    <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:4px;">
        <a class="a-btn" href="<?php echo APP_URL; ?>/pages/admin/settings.php"><?php echo e(t('admin.cancel')); ?></a>
        <button class="a-btn a-btn--primary" type="submit"><?php echo admin_icon('check'); ?><span><?php echo e(t('admin.save_settings')); ?></span></button>
    </div>
</form>

<!-- System info (read-only, real values) -->
<section class="a-panel">
    <div class="a-panel__head">
        <div>
            <h3><?php echo e(t('admin.settings_system_info')); ?></h3>
            <p><?php echo e(t('admin.settings_system_info_sub')); ?></p>
        </div>
    </div>
    <dl class="a-msgdetail__kv" style="max-width:520px;">
        <dt><?php echo e(t('admin.settings_app_version')); ?></dt><dd><?php echo e(APP_VERSION); ?></dd>
        <dt>PHP</dt><dd><?php echo e(PHP_VERSION); ?></dd>
        <dt>MySQL</dt><dd><?php echo e($pdo->getAttribute(PDO::ATTR_SERVER_VERSION)); ?></dd>
        <dt><?php echo e(t('admin.settings_timezone')); ?></dt><dd><?php echo e(APP_TIMEZONE); ?> (<?php echo e(date('H:i')); ?>)</dd>
        <dt><?php echo e(t('admin.settings_db_name')); ?></dt><dd><?php echo e(DB_NAME); ?></dd>
    </dl>
</section>

</div><!-- /a-content -->
<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>
