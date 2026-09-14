<?php
/**
 * pages/seller/payment-methods.php — Seller Payment Methods
 * --------------------------------------------------------------------
 * Lets sellers configure how they receive money from withdrawals.
 *
 * Features:
 *   - Add Mobile Money (MTN, Airtel) or Bank Account methods
 *   - Masked account identifiers (e.g. 07******123, ****4582)
 *   - Set default method (only one at a time)
 *   - Verification status badges (unverified, pending, verified, rejected)
 *   - Enable/disable methods
 *   - Remove methods (blocked if used in pending withdrawal)
 *   - Admin-configured providers via site_settings
 *
 * Security:
 *   - Server-side authorization: WHERE user_id = ? on every query
 *   - CSRF on all POST actions
 *   - Input validation (phone format, account number format)
 *   - Duplicate prevention (same provider + account)
 *   - Never stores PINs, OTPs, passwords, or private keys
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/seller_sidebar.php';
require_login();

$uid = (int) current_user()['id'];
$pdo = db();

// --- Load admin-configured settings ---
$settings = [];
$stmt = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('payment_momo_providers','payment_momo_min_withdrawal','payment_momo_max_withdrawal','payment_momo_fee_percent','payment_bank_min_withdrawal','payment_bank_max_withdrawal','payment_bank_fee_percent','payment_crypto_enabled','payment_crypto_providers')");
foreach ($stmt->fetchAll() as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$momoProviders = json_decode($settings['payment_momo_providers'] ?? '["MTN Mobile Money","Airtel Money"]', true) ?? ['MTN Mobile Money','Airtel Money'];
$momoMin = (float)($settings['payment_momo_min_withdrawal'] ?? 1000);
$momoMax = (float)($settings['payment_momo_max_withdrawal'] ?? 500000);
$momoFee = (float)($settings['payment_momo_fee_percent'] ?? 1);
$bankMin = (float)($settings['payment_bank_min_withdrawal'] ?? 5000);
$bankMax = (float)($settings['payment_bank_max_withdrawal'] ?? 5000000);
$bankFee = (float)($settings['payment_bank_fee_percent'] ?? 0.5);
$cryptoEnabled = (int)($settings['payment_crypto_enabled'] ?? 0);

// --- Helper: mask account identifier ---
function mask_identifier(string $type, ?string $value): string {
    if (!$value) return '—';
    if ($type === 'momo') {
        // Phone: show first 2 and last 3 digits
        if (strlen($value) <= 5) return $value;
        return substr($value, 0, 3) . str_repeat('*', max(1, strlen($value) - 6)) . substr($value, -3);
    }
    if ($type === 'bank') {
        // Account number: show last 4
        if (strlen($value) <= 4) return $value;
        return str_repeat('*', strlen($value) - 4) . substr($value, -4);
    }
    return $value;
}

// --- Handle POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        flash_set('error', t('errors.invalid_token'));
        redirect(APP_URL . '/pages/seller/payment-methods.php');
    }

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'add':
            $type = $_POST['type'] ?? '';
            $label = trim($_POST['label'] ?? '');
            $provider = trim($_POST['provider'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $bankName = trim($_POST['bank_name'] ?? '');
            $acctNumber = trim($_POST['account_number'] ?? '');
            $acctName = trim($_POST['account_name'] ?? '');
            $setAsDefault = (int)($_POST['set_default'] ?? 0);

            // Validate
            $errors = [];
            if (!in_array($type, ['momo', 'bank'], true)) $errors[] = 'Invalid method type.';
            if (!$label) $errors[] = 'A label is required (e.g. "My MTN").';
            if ($type === 'momo') {
                if (!$provider) $errors[] = 'Please select a provider.';
                if (!in_array($provider, $momoProviders, true)) $errors[] = 'Invalid provider selected.';
                if (!$phone) $errors[] = 'Phone number is required.';
                if (!preg_match('/^\+?\d{8,15}$/', preg_replace('/\s+/', '', $phone))) $errors[] = 'Invalid phone number format.';
            } elseif ($type === 'bank') {
                if (!$bankName) $errors[] = 'Bank name is required.';
                if (!$acctNumber) $errors[] = 'Account number is required.';
                if (strlen($acctNumber) < 5) $errors[] = 'Account number seems too short.';
                if (!$acctName) $errors[] = 'Account holder name is required.';
            }

            // Duplicate check
            if (empty($errors)) {
                if ($type === 'momo') {
                    $dup = $pdo->prepare('SELECT id FROM withdrawal_methods WHERE user_id = ? AND type = ? AND provider = ? AND phone = ?');
                    $dup->execute([$uid, $type, $provider, $phone]);
                } else {
                    $dup = $pdo->prepare('SELECT id FROM withdrawal_methods WHERE user_id = ? AND type = ? AND bank_name = ? AND account_number = ?');
                    $dup->execute([$uid, $type, $bankName, $acctNumber]);
                }
                if ($dup->fetch()) $errors[] = 'This payment method already exists.';
            }

            if ($errors) {
                flash_set('error', $errors[0]);
            } else {
                // If set as default, clear other defaults first
                if ($setAsDefault) {
                    $pdo->prepare('UPDATE withdrawal_methods SET is_preferred = 0 WHERE user_id = ?')->execute([$uid]);
                }

                $pdo->prepare(
                    'INSERT INTO withdrawal_methods (user_id, type, label, provider, phone, bank_name, account_number, account_name, is_preferred, verification_status, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "pending", "active")'
                )->execute([
                    $uid, $type, $label,
                    $type === 'momo' ? $provider : null,
                    $type === 'momo' ? $phone : null,
                    $type === 'bank' ? $bankName : null,
                    $type === 'bank' ? $acctNumber : null,
                    $type === 'bank' ? $acctName : null,
                    $setAsDefault ? 1 : 0,
                ]);

                // Audit log
                try {
                    $pdo->prepare('INSERT INTO audit_log (user_id, action, target_type, metadata) VALUES (?, "payment_method.add", "withdrawal_method", ?)')
                        ->execute([$uid, json_encode(['type' => $type, 'label' => $label])]);
                } catch (PDOException $e) {}

                flash_set('success', 'Payment method added successfully.' . ($setAsDefault ? ' Set as default.' : ''));
            }
            redirect(APP_URL . '/pages/seller/payment-methods.php');
            break;

        case 'set_default':
            $methodId = (int)($_POST['method_id'] ?? 0);
            $pdo->prepare('UPDATE withdrawal_methods SET is_preferred = 0 WHERE user_id = ?')->execute([$uid]);
            $pdo->prepare('UPDATE withdrawal_methods SET is_preferred = 1 WHERE id = ? AND user_id = ?')->execute([$methodId, $uid]);
            flash_set('success', 'Default payment method updated.');
            redirect(APP_URL . '/pages/seller/payment-methods.php');
            break;

        case 'toggle_status':
            $methodId = (int)($_POST['method_id'] ?? 0);
            $pdo->prepare('UPDATE withdrawal_methods SET status = IF(status = "active", "inactive", "active") WHERE id = ? AND user_id = ?')->execute([$methodId, $uid]);
            flash_set('success', 'Payment method status updated.');
            redirect(APP_URL . '/pages/seller/payment-methods.php');
            break;

        case 'remove':
            $methodId = (int)($_POST['method_id'] ?? 0);
            // Check if method is used in pending withdrawal
            $check = $pdo->prepare('SELECT id FROM withdrawals WHERE withdrawal_method_id = ? AND status IN ("pending","processing") LIMIT 1');
            $check->execute([$methodId]);
            if ($check->fetch()) {
                flash_set('error', 'Cannot remove this method — it is used in a pending withdrawal.');
            } else {
                $pdo->prepare('DELETE FROM withdrawal_methods WHERE id = ? AND user_id = ?')->execute([$methodId, $uid]);
                flash_set('success', 'Payment method removed.');
            }
            redirect(APP_URL . '/pages/seller/payment-methods.php');
            break;
    }
}

// --- Load existing methods ---
$stmt = $pdo->prepare('SELECT * FROM withdrawal_methods WHERE user_id = ? ORDER BY is_preferred DESC, created_at DESC');
$stmt->execute([$uid]);
$methods = $stmt->fetchAll();

$hasDefault = false;
foreach ($methods as $m) { if ((int)$m['is_preferred'] === 1) { $hasDefault = true; break; } }

$pageTitle = 'Payment Methods';
$activePage = 'dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>

<?php seller_page_start('Payment Methods', $uid, $pdo); ?>

    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
        <div>
            <h1 style="font-size:22px;font-weight:800;margin:0;letter-spacing:-.02em;">Payment Methods</h1>
            <p style="font-size:13px;color:var(--text-mute);margin:4px 0 0;">Configure how you receive your earnings from withdrawals.</p>
        </div>
        <button type="button" class="btn btn--primary" onclick="document.getElementById('addMethodModal').style.display='block'">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>
            Add Payment Method
        </button>
    </div>

    <!-- Existing methods -->
    <?php if (empty($methods)): ?>
        <div class="card" style="padding:48px 24px;text-align:center;">
            <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="var(--text-mute)" stroke-width="1.3" style="opacity:.4;margin-bottom:12px;"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
            <h3 style="margin:0 0 6px;font-size:16px;color:var(--text);">No payment methods yet</h3>
            <p style="margin:0 0 16px;font-size:13.5px;color:var(--text-mute);">Add a payment method to receive your earnings via withdrawals.</p>
            <button type="button" class="btn btn--primary" onclick="document.getElementById('addMethodModal').style.display='block'">+ Add Payment Method</button>
        </div>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:14px;">
            <?php foreach ($methods as $m):
                $maskedId = mask_identifier($m['type'], $m['type'] === 'momo' ? $m['phone'] : $m['account_number']);
                $vsColors = ['unverified'=>'warn','pending'=>'warn','verified'=>'good','rejected'=>'bad','disabled'=>'mute'];
                $vsColor = $vsColors[$m['verification_status']] ?? 'mute';
                $providerLabel = $m['type'] === 'momo' ? ($m['provider'] ?? 'Mobile Money') : ($m['bank_name'] ?? 'Bank');
                $iconBg = $m['type'] === 'momo' ? '#fff7e9' : '#eff5ff';
                $iconFg = $m['type'] === 'momo' ? '#c4730a' : '#2563eb';
                $iconText = $m['type'] === 'momo' ? '📱' : '🏦';
                $isActive = $m['status'] === 'active';
            ?>
                <div class="card" style="padding:16px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;<?php echo !$isActive ? 'opacity:.55;' : ''; ?>">
                    <!-- Icon -->
                    <div style="width:44px;height:44px;border-radius:11px;background:<?php echo $iconBg; ?>;color:<?php echo $iconFg; ?>;display:grid;place-items:center;font-size:20px;flex:0 0 44px;">
                        <?php echo $iconText; ?>
                    </div>

                    <!-- Info -->
                    <div style="flex:1;min-width:180px;">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <strong style="font-size:14px;color:var(--text);"><?php echo e($m['label']); ?></strong>
                            <?php if ((int)$m['is_preferred'] === 1): ?>
                                <span class="udash__pill udash__pill--brand">Default</span>
                            <?php endif; ?>
                            <?php if (!$isActive): ?>
                                <span class="udash__pill udash__pill--mute">Inactive</span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:12.5px;color:var(--text-soft);margin-top:3px;">
                            <?php echo e($providerLabel); ?> • <?php echo e($maskedId); ?>
                        </div>
                        <div style="margin-top:4px;">
                            <span class="udash__pill udash__pill--<?php echo $vsColor; ?>">
                                <?php
                                $vsIcons = ['unverified'=>'○','pending'=>'⏳','verified'=>'✓','rejected'=>'✕','disabled'=>'⏸'];
                                echo ($vsIcons[$m['verification_status']] ?? '○') . ' ' . e(str_replace('_',' ', $m['verification_status']));
                                ?>
                            </span>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <?php if ((int)$m['is_preferred'] !== 1 && $isActive): ?>
                            <form method="post" action="" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="set_default">
                                <input type="hidden" name="method_id" value="<?php echo (int)$m['id']; ?>">
                                <button type="submit" class="btn btn--outline btn--sm">Set Default</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="" style="display:inline;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="method_id" value="<?php echo (int)$m['id']; ?>">
                            <button type="submit" class="btn btn--ghost btn--sm"><?php echo $isActive ? 'Disable' : 'Enable'; ?></button>
                        </form>
                        <form method="post" action="" style="display:inline;" onsubmit="return confirm('Remove this payment method?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="method_id" value="<?php echo (int)$m['id']; ?>">
                            <button type="submit" class="btn btn--ghost btn--sm" style="color:#d9534a;">Remove</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Info note -->
        <div style="margin-top:16px;padding:14px 16px;background:var(--bg-soft);border-radius:10px;font-size:12.5px;color:var(--text-mute);line-height:1.5;">
            <strong style="color:var(--text-soft);">ℹ About verification:</strong>
            New payment methods start as "pending" and are reviewed by our team. Once verified, you'll see a green ✓ badge.
            Only verified methods can be used for withdrawals. Account numbers are masked for your security.
        </div>
    <?php endif; ?>
</div>

<!-- Add Payment Method Modal -->
<div id="addMethodModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);padding:20px;overflow-y:auto;" onclick="if(event.target===this)this.style.display='none'">
    <div style="max-width:480px;margin:auto;background:#fff;border-radius:16px;padding:28px;box-shadow:0 24px 64px rgba(0,0,0,.2);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h2 style="font-size:18px;font-weight:800;margin:0;">Add Payment Method</h2>
            <button type="button" onclick="document.getElementById('addMethodModal').style.display='none'" style="background:none;border:0;font-size:22px;cursor:pointer;color:var(--text-mute);">×</button>
        </div>
        <p style="font-size:13px;color:var(--text-mute);margin:0 0 18px;">How would you like to receive your earnings?</p>

        <!-- Method type selector -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px;">
            <label class="pm-type-card" data-type="momo" onclick="selectPmType('momo')" style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:16px;border:2px solid var(--border);border-radius:12px;cursor:pointer;transition:all 160ms;">
                <span style="font-size:24px;">📱</span>
                <strong style="font-size:13px;">Mobile Money</strong>
                <span style="font-size:11px;color:var(--text-mute);">MTN, Airtel</span>
            </label>
            <label class="pm-type-card" data-type="bank" onclick="selectPmType('bank')" style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:16px;border:2px solid var(--border);border-radius:12px;cursor:pointer;transition:all 160ms;">
                <span style="font-size:24px;">🏦</span>
                <strong style="font-size:13px;">Bank Account</strong>
                <span style="font-size:11px;color:var(--text-mute);">Bank transfer</span>
            </label>
        </div>

        <!-- Form -->
        <form method="post" action="" id="pmForm" style="display:none;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="type" id="pmType" value="">

            <!-- Common: label -->
            <div class="form-group" style="margin-bottom:14px;">
                <label style="font-size:13px;font-weight:600;display:block;margin-bottom:5px;">Label <span style="color:var(--text-mute);font-weight:400;">(e.g. "My MTN")</span></label>
                <input type="text" name="label" required placeholder="My MTN" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-size:14px;">
            </div>

            <!-- MoMo fields -->
            <div id="pmMomoFields" style="display:none;">
                <div class="form-group" style="margin-bottom:14px;">
                    <label style="font-size:13px;font-weight:600;display:block;margin-bottom:5px;">Provider</label>
                    <select name="provider" id="pmProvider" required style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-size:14px;background:#fff;">
                        <option value="">Select provider...</option>
                        <?php foreach ($momoProviders as $p): ?>
                            <option value="<?php echo e($p); ?>"><?php echo e($p); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:14px;">
                    <label style="font-size:13px;font-weight:600;display:block;margin-bottom:5px;">Phone Number <span style="color:var(--text-mute);font-weight:400;">(MoMo account)</span></label>
                    <input type="text" name="phone" placeholder="+250 788 000 000" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-size:14px;">
                    <small style="font-size:11px;color:var(--text-mute);">We never ask for your PIN or password.</small>
                </div>
            </div>

            <!-- Bank fields -->
            <div id="pmBankFields" style="display:none;">
                <div class="form-group" style="margin-bottom:14px;">
                    <label style="font-size:13px;font-weight:600;display:block;margin-bottom:5px;">Bank Name</label>
                    <input type="text" name="bank_name" placeholder="Bank of Kigali" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-size:14px;">
                </div>
                <div class="form-group" style="margin-bottom:14px;">
                    <label style="font-size:13px;font-weight:600;display:block;margin-bottom:5px;">Account Number</label>
                    <input type="text" name="account_number" placeholder="01234567890" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-size:14px;">
                </div>
                <div class="form-group" style="margin-bottom:14px;">
                    <label style="font-size:13px;font-weight:600;display:block;margin-bottom:5px;">Account Holder Name</label>
                    <input type="text" name="account_name" placeholder="John Doe" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-size:14px;">
                </div>
            </div>

            <!-- Set as default -->
            <div class="form-group" style="margin-bottom:18px;">
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                    <input type="checkbox" name="set_default" value="1" style="accent-color:var(--brand-500);">
                    <span>Set as default withdrawal method</span>
                </label>
            </div>

            <button type="submit" class="btn btn--primary btn--block btn--lg">Save Payment Method</button>
        </form>
    </div>
</div>

<style>
.pm-type-card:hover { border-color: var(--brand-400) !important; background: var(--brand-50) !important; }
.pm-type-card.is-selected { border-color: var(--brand-500) !important; background: var(--brand-50) !important; box-shadow: 0 0 0 3px rgba(20,165,148,.1); }
</style>
<script>
function selectPmType(type) {
    document.querySelectorAll('.pm-type-card').forEach(function(c) { c.classList.remove('is-selected'); });
    document.querySelector('[data-type="' + type + '"]').classList.add('is-selected');
    document.getElementById('pmType').value = type;
    document.getElementById('pmForm').style.display = 'block';
    document.getElementById('pmMomoFields').style.display = type === 'momo' ? 'block' : 'none';
    document.getElementById('pmBankFields').style.display = type === 'bank' ? 'block' : 'none';
    // Set required attributes
    var momoFields = document.getElementById('pmMomoFields').querySelectorAll('input,select');
    var bankFields = document.getElementById('pmBankFields').querySelectorAll('input,select');
    momoFields.forEach(function(f) { f.required = (type === 'momo'); });
    bankFields.forEach(function(f) { f.required = (type === 'bank'); });
}
</script>
<?php seller_page_end(); ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
