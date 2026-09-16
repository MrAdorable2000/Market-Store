<?php
/**
 * pages/seller/withdrawals.php — Seller Withdrawal Management
 * --------------------------------------------------------------------
 * Seller can:
 *   - Add withdrawal methods (Mobile Money, Bank)
 *   - Request a withdrawal (debits wallet available balance atomically)
 *   - View withdrawal history with status tracking
 *
 * Uses the existing withdrawals + withdrawal_methods + wallets tables.
 * Authorization: seller can only see their own withdrawal methods + requests.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/wallet.php';
require_once __DIR__ . '/../../includes/seller_sidebar.php';
require_once __DIR__ . '/../../includes/payment.php';
require_login();

$uid = (int) current_user()['id'];
$pdo = db();

// --- Handle POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        flash_set('error', t('errors.invalid_token'));
    } else {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'add_method':
                $type = $_POST['type'] ?? '';
                $label = trim($_POST['label'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $bankName = trim($_POST['bank_name'] ?? '');
                $acctNumber = trim($_POST['account_number'] ?? '');
                $acctName = trim($_POST['account_name'] ?? '');

                if (!in_array($type, ['momo','bank'], true)) {
                    flash_set('error', 'Invalid method type.');
                } elseif (!$label) {
                    flash_set('error', 'Label is required.');
                } elseif ($type === 'momo' && !$phone) {
                    flash_set('error', 'Phone number is required for Mobile Money.');
                } elseif ($type === 'bank' && (!$bankName || !$acctNumber)) {
                    flash_set('error', 'Bank name and account number are required.');
                } else {
                    $pdo->prepare(
                        'INSERT INTO withdrawal_methods (user_id, type, label, phone, bank_name, account_number, account_name, is_preferred)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 0)'
                    )->execute([$uid, $type, $label, $phone ?: null, $bankName ?: null, $acctNumber ?: null, $acctName ?: null]);
                    flash_set('success', 'Withdrawal method added.');
                }
                break;

            case 'set_preferred':
                $methodId = (int)($_POST['method_id'] ?? 0);
                $pdo->prepare('UPDATE withdrawal_methods SET is_preferred = 0 WHERE user_id = ?')->execute([$uid]);
                $pdo->prepare('UPDATE withdrawal_methods SET is_preferred = 1 WHERE id = ? AND user_id = ?')->execute([$methodId, $uid]);
                flash_set('success', 'Preferred method updated.');
                break;

            case 'delete_method':
                $methodId = (int)($_POST['method_id'] ?? 0);
                $pdo->prepare('DELETE FROM withdrawal_methods WHERE id = ? AND user_id = ?')->execute([$methodId, $uid]);
                flash_set('success', 'Method removed.');
                break;

            case 'request_withdrawal':
                $methodId = (int)($_POST['method_id'] ?? 0);
                $amount = (float)($_POST['amount'] ?? 0);
                $minWithdrawal = 1000; // RWF 1000 minimum

                // Validate method ownership
                $stmt = $pdo->prepare('SELECT * FROM withdrawal_methods WHERE id = ? AND user_id = ?');
                $stmt->execute([$methodId, $uid]);
                $method = $stmt->fetch();
                if (!$method) {
                    flash_set('error', 'Invalid withdrawal method.');
                    break;
                }
                if ($amount < $minWithdrawal) {
                    flash_set('error', 'Minimum withdrawal is ' . format_price($minWithdrawal, 'RWF') . '.');
                    break;
                }

                $bal = wallet_balance($uid);
                if ($bal['available'] < $amount) {
                    flash_set('error', 'Insufficient available balance. You have ' . format_price($bal['available'], 'RWF') . '.');
                    break;
                }

                // Get wallet
                $wallet = get_or_create_wallet($uid);
                $walletId = (int)$wallet['id'];
                $ref = generate_withdrawal_reference();
                $fee = round($amount * 0.01, 2); // 1% withdrawal fee
                $net = $amount - $fee;

                // The balance check above reads outside any lock, so it's
                // only a fast-path/UX check — a second rapid request (e.g.
                // a double-click) can still race past it. wallet_withdraw()
                // re-validates atomically under a row lock and throws if
                // the balance is actually insufficient by then. Without
                // this try/catch, that exception would leave an orphaned
                // 'pending' withdrawal row with no corresponding wallet
                // debit behind it (bad: it looks like a legitimate request
                // waiting for admin approval, but no funds were ever held).
                try {
                    $pdo->beginTransaction();
                    // Create withdrawal record
                    $pdo->prepare(
                        'INSERT INTO withdrawals (withdrawal_reference, user_id, wallet_id, amount, fee, net_amount, currency, withdrawal_method_id, status)
                         VALUES (?, ?, ?, ?, ?, ?, "RWF", ?, "pending")'
                    )->execute([$ref, $uid, $walletId, $amount, $fee, $net, $methodId]);
                    $withdrawalId = (int)$pdo->lastInsertId();

                    // Debit wallet atomically (participates in this same
                    // transaction, since wallet_withdraw() only opens its
                    // own transaction when one isn't already active)
                    wallet_withdraw($uid, $amount, $withdrawalId, 'Withdrawal to ' . ($method['type']==='momo' ? 'Mobile Money' : 'Bank'));

                    $pdo->commit();
                    flash_set('success', 'Withdrawal request submitted. You will be notified once processed.');
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        try { $pdo->rollBack(); } catch (PDOException $rb) { /* ignore */ }
                    }
                    flash_set('error', 'Could not process withdrawal — your balance may have changed. Please refresh and try again.');
                }
                break;
        }
        redirect(APP_URL . '/pages/seller/withdrawals.php');
    }
}

// --- Load data ---
$walletBal = wallet_balance($uid);
$methods = $pdo->prepare('SELECT * FROM withdrawal_methods WHERE user_id = ? ORDER BY is_preferred DESC, created_at DESC');
$methods->execute([$uid]);
$methods = $methods->fetchAll();

$withdrawals = $pdo->prepare('SELECT w.*, wm.label AS method_label, wm.type AS method_type FROM withdrawals w INNER JOIN withdrawal_methods wm ON wm.id = w.withdrawal_method_id WHERE w.user_id = ? ORDER BY w.created_at DESC');
$withdrawals->execute([$uid]);
$withdrawals = $withdrawals->fetchAll();

$totalWithdrawn = 0;
foreach ($withdrawals as $w) {
    if (in_array($w['status'], ['completed','processing'], true)) $totalWithdrawn += (float)$w['amount'];
}

$pageTitle = 'Withdrawals';
$activePage = 'dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>

<?php seller_page_start('Withdrawals', $uid, $pdo); ?>

    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:20px;">
        <div>
            <h1 style="font-size:22px;font-weight:800;margin:0;letter-spacing:-.02em;">Withdrawals</h1>
            <p style="font-size:13px;color:var(--text-mute);margin:4px 0 0;">Withdraw your earnings to Mobile Money or Bank.</p>
        </div>
    </div>

    <!-- Balance cards -->
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px;" class="wd-stats">
        <div class="card" style="padding:16px;">
            <div style="font-size:11px;font-weight:700;color:var(--text-mute);text-transform:uppercase;letter-spacing:.06em;">Available</div>
            <div style="font-size:22px;font-weight:800;color:var(--brand-600);margin-top:4px;"><?php echo e(format_price($walletBal['available'], 'RWF')); ?></div>
        </div>
        <div class="card" style="padding:16px;">
            <div style="font-size:11px;font-weight:700;color:var(--text-mute);text-transform:uppercase;letter-spacing:.06em;">Total Withdrawn</div>
            <div style="font-size:22px;font-weight:800;color:var(--text);margin-top:4px;"><?php echo e(format_price($totalWithdrawn, 'RWF')); ?></div>
        </div>
        <div class="card" style="padding:16px;">
            <div style="font-size:11px;font-weight:700;color:var(--text-mute);text-transform:uppercase;letter-spacing:.06em;">Total Earnings</div>
            <div style="font-size:22px;font-weight:800;color:var(--text);margin-top:4px;"><?php echo e(format_price($walletBal['earnings'], 'RWF')); ?></div>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;" class="wd-layout">
        <!-- Left: Methods + Request -->
        <div>
            <!-- Request withdrawal -->
            <div class="card" style="padding:18px;margin-bottom:16px;">
                <h2 style="font-size:15px;font-weight:800;margin:0 0 12px;">Request Withdrawal</h2>
                <?php if ($walletBal['available'] < 1000): ?>
                    <p style="font-size:13px;color:var(--text-mute);margin:0;">Your available balance is insufficient for withdrawal. Minimum is RWF 1,000.</p>
                <?php elseif (empty($methods)): ?>
                    <p style="font-size:13px;color:var(--text-mute);margin:0 0 10px;">Add a withdrawal method first.</p>
                <?php else: ?>
                <form method="post" action="">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="request_withdrawal">
                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:5px;">Amount (RWF)</label>
                        <input type="number" name="amount" min="1000" max="<?php echo (int)$walletBal['available']; ?>" step="any" required placeholder="e.g. 5000" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-size:14px;">
                        <small style="font-size:11px;color:var(--text-mute);">Available: <?php echo e(format_price($walletBal['available'], 'RWF')); ?> • Fee: 1%</small>
                    </div>
                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:5px;">Method</label>
                        <select name="method_id" required style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-size:14px;background:#fff;">
                            <?php foreach ($methods as $m): ?>
                                <option value="<?php echo (int)$m['id']; ?>" <?php echo $m['is_preferred']?'selected':''; ?>><?php echo e($m['label']); ?> (<?php echo $m['type']==='momo'?'MoMo':'Bank'; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn--primary btn--block">Request Withdrawal</button>
                </form>
                <?php endif; ?>
            </div>

            <!-- Withdrawal methods -->
            <div class="card" style="padding:18px;margin-bottom:16px;">
                <h2 style="font-size:15px;font-weight:800;margin:0 0 12px;">Withdrawal Methods</h2>
                <?php if (empty($methods)): ?>
                    <p style="font-size:13px;color:var(--text-mute);margin:0 0 10px;">No methods added yet.</p>
                <?php else: ?>
                    <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px;">
                        <?php foreach ($methods as $m): ?>
                            <div style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--border);border-radius:9px;">
                                <span style="width:32px;height:32px;border-radius:8px;background:<?php echo $m['type']==='momo'?'#fff7e9':'#eff5ff'; ?>;color:<?php echo $m['type']==='momo'?'#c4730a':'#2563eb'; ?>;display:grid;place-items:center;flex:0 0 32px;font-size:14px;">
                                    <?php echo $m['type']==='momo'?'📱':'🏦'; ?>
                                </span>
                                <div style="flex:1;min-width:0;">
                                    <strong style="font-size:13px;color:var(--text);"><?php echo e($m['label']); ?></strong>
                                    <div style="font-size:11px;color:var(--text-mute);">
                                        <?php echo $m['type']==='momo' ? e($m['phone']) : e($m['bank_name'].' • '.$m['account_number']); ?>
                                    </div>
                                </div>
                                <?php if ((int)$m['is_preferred'] === 1): ?><span class="udash__pill udash__pill--brand">Preferred</span><?php endif; ?>
                                <form method="post" action="" style="display:inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_method">
                                    <input type="hidden" name="method_id" value="<?php echo (int)$m['id']; ?>">
                                    <button type="submit" style="background:none;border:0;color:var(--text-mute);cursor:pointer;font-size:14px;padding:4px;" title="Remove" onclick="return confirm('Remove this method?')">×</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Add method form -->
                <details>
                    <summary style="font-size:13px;font-weight:700;cursor:pointer;color:var(--brand-600);">+ Add new method</summary>
                    <form method="post" action="" style="margin-top:12px;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="add_method">
                        <div class="form-group" style="margin-bottom:10px;">
                            <label style="font-size:12.5px;font-weight:600;display:block;margin-bottom:4px;">Type</label>
                            <select name="type" id="wdType" required style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;" onchange="toggleWdFields()">
                                <option value="momo">Mobile Money</option>
                                <option value="bank">Bank Account</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:10px;">
                            <label style="font-size:12.5px;font-weight:600;display:block;margin-bottom:4px;">Label</label>
                            <input type="text" name="label" required placeholder="e.g. My MTN" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;">
                        </div>
                        <div class="form-group" id="wdPhoneGroup" style="margin-bottom:10px;">
                            <label style="font-size:12.5px;font-weight:600;display:block;margin-bottom:4px;">Phone Number</label>
                            <input type="text" name="phone" placeholder="+250788000000" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;">
                        </div>
                        <div id="wdBankGroup" style="display:none;">
                            <div class="form-group" style="margin-bottom:10px;">
                                <label style="font-size:12.5px;font-weight:600;display:block;margin-bottom:4px;">Bank Name</label>
                                <input type="text" name="bank_name" placeholder="Bank of Kigali" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;">
                            </div>
                            <div class="form-group" style="margin-bottom:10px;">
                                <label style="font-size:12.5px;font-weight:600;display:block;margin-bottom:4px;">Account Number</label>
                                <input type="text" name="account_number" placeholder="1234567890" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;">
                            </div>
                            <div class="form-group" style="margin-bottom:10px;">
                                <label style="font-size:12.5px;font-weight:600;display:block;margin-bottom:4px;">Account Name</label>
                                <input type="text" name="account_name" placeholder="John Doe" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;">
                            </div>
                        </div>
                        <button type="submit" class="btn btn--outline btn--sm">Add Method</button>
                    </form>
                </details>
            </div>
        </div>

        <!-- Right: History -->
        <div>
            <div class="card" style="padding:18px;">
                <h2 style="font-size:15px;font-weight:800;margin:0 0 12px;">Withdrawal History</h2>
                <?php if (empty($withdrawals)): ?>
                    <div style="text-align:center;padding:24px 12px;color:var(--text-mute);">
                        <svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.3" style="opacity:.4;margin-bottom:8px;"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4M3 5v14a2 2 0 0 0 2 2h16v-5M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
                        <p style="font-size:13px;margin:0;">No withdrawals yet.</p>
                    </div>
                <?php else: ?>
                    <div style="display:flex;flex-direction:column;gap:10px;">
                        <?php foreach ($withdrawals as $w):
                            $wsc = ['pending'=>'warn','processing'=>'blue','completed'=>'good','rejected'=>'bad','failed'=>'bad'][$w['status']] ?? 'mute';
                        ?>
                            <div style="padding:12px;border:1px solid var(--border);border-radius:10px;">
                                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:6px;">
                                    <strong style="font-size:13px;color:var(--text);"><?php echo e($w['withdrawal_reference']); ?></strong>
                                    <span class="udash__pill udash__pill--<?php echo $wsc; ?>"><?php echo e($w['status']); ?></span>
                                </div>
                                <div style="font-size:12px;color:var(--text-soft);margin-bottom:4px;">
                                    <?php echo e(format_price((float)$w['amount'], 'RWF')); ?> → <?php echo e($w['method_label']); ?> (<?php echo $w['method_type']==='momo'?'MoMo':'Bank'; ?>)
                                </div>
                                <div style="font-size:11px;color:var(--text-mute);">
                                    Fee: <?php echo e(format_price((float)$w['fee'], 'RWF')); ?> • Net: <?php echo e(format_price((float)$w['net_amount'], 'RWF')); ?> • <?php echo e(time_ago($w['created_at'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
@media (max-width:760px) { .wd-layout { grid-template-columns:1fr !important; } .wd-stats { grid-template-columns:1fr !important; } }
</style>
<script>
function toggleWdFields() {
    var type = document.getElementById('wdType').value;
    document.getElementById('wdPhoneGroup').style.display = type === 'momo' ? 'block' : 'none';
    document.getElementById('wdBankGroup').style.display = type === 'bank' ? 'block' : 'none';
}
</script>
<?php seller_page_end(); ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
