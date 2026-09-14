<?php
/**
 * pages/wallet.php — User wallet
 * --------------------------------------------------------------------
 * Shows: available balance, pending balance, total earnings, total spending,
 *        transaction history (deposit, payment, escrow, refund, withdrawal).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/seller_sidebar.php';
require_login();

$uid = (int) current_user()['id'];
$walletBal = wallet_balance($uid);
$transactions = wallet_transactions($uid, 50);

$pageTitle = 'My Wallet';
$activePage = 'dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<?php seller_page_start('My Wallet', $uid, db()); ?>
    <h1 style="font-size:24px;font-weight:800;margin:0 0 20px;letter-spacing:-.02em;">My Wallet</h1>

    <!-- Balance cards -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px;" class="wallet-stats">
        <div class="card" style="padding:18px;">
            <div style="font-size:11px;font-weight:700;color:var(--text-mute);text-transform:uppercase;letter-spacing:.06em;">Available</div>
            <div style="font-size:22px;font-weight:800;color:var(--brand-600);margin-top:6px;font-variant-numeric:tabular-nums;"><?php echo e(format_price($walletBal['available'], $walletBal['currency'])); ?></div>
        </div>
        <div class="card" style="padding:18px;">
            <div style="font-size:11px;font-weight:700;color:var(--text-mute);text-transform:uppercase;letter-spacing:.06em;">Pending</div>
            <div style="font-size:22px;font-weight:800;color:var(--accent-500);margin-top:6px;font-variant-numeric:tabular-nums;"><?php echo e(format_price($walletBal['pending'], $walletBal['currency'])); ?></div>
        </div>
        <div class="card" style="padding:18px;">
            <div style="font-size:11px;font-weight:700;color:var(--text-mute);text-transform:uppercase;letter-spacing:.06em;">Earnings</div>
            <div style="font-size:22px;font-weight:800;color:var(--text);margin-top:6px;font-variant-numeric:tabular-nums;"><?php echo e(format_price($walletBal['earnings'], $walletBal['currency'])); ?></div>
        </div>
        <div class="card" style="padding:18px;">
            <div style="font-size:11px;font-weight:700;color:var(--text-mute);text-transform:uppercase;letter-spacing:.06em;">Spending</div>
            <div style="font-size:22px;font-weight:800;color:var(--text);margin-top:6px;font-variant-numeric:tabular-nums;"><?php echo e(format_price($walletBal['spending'], $walletBal['currency'])); ?></div>
        </div>
    </div>

    <!-- Transaction history -->
    <div class="card" style="padding:20px;">
        <h2 style="font-size:16px;font-weight:800;margin:0 0 14px;">Transaction History</h2>
        <?php if (empty($transactions)): ?>
            <div style="text-align:center;padding:32px 16px;color:var(--text-mute);">
                <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.3" style="opacity:.4;margin-bottom:8px;"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4M3 5v14a2 2 0 0 0 2 2h16v-5M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
                <p style="font-size:13px;margin:0;">No transactions yet. Your wallet activity will appear here.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:560px;">
                    <thead>
                        <tr style="border-bottom:1px solid var(--border);">
                            <th style="text-align:left;padding:10px 12px;font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-mute);">Type</th>
                            <th style="text-align:left;padding:10px 12px;font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-mute);">Description</th>
                            <th style="text-align:right;padding:10px 12px;font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-mute);">Amount</th>
                            <th style="text-align:right;padding:10px 12px;font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-mute);">Balance</th>
                            <th style="text-align:left;padding:10px 12px;font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-mute);">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $tx):
                            $isCredit = (float)$tx['amount'] >= 0;
                            $typeColors = [
                                'deposit' => 'good', 'payment' => 'bad', 'escrow_hold' => 'warn',
                                'escrow_release' => 'good', 'refund' => 'good', 'withdrawal' => 'bad', 'adjustment' => 'mute',
                            ];
                            $tc = $typeColors[$tx['type']] ?? 'mute';
                        ?>
                            <tr style="border-bottom:1px solid var(--bg-soft);">
                                <td style="padding:11px 12px;">
                                    <span class="udash__pill udash__pill--<?php echo $tc; ?>"><?php echo e(str_replace('_',' ', $tx['type'])); ?></span>
                                </td>
                                <td style="padding:11px 12px;color:var(--text-soft);"><?php echo e($tx['description'] ?? '—'); ?></td>
                                <td style="padding:11px 12px;text-align:right;font-weight:700;color:<?php echo $isCredit ? 'var(--brand-600)' : 'var(--text)'; ?>;font-variant-numeric:tabular-nums;">
                                    <?php echo $isCredit ? '+' : ''; ?><?php echo e(format_price(abs((float)$tx['amount']), $tx['currency'])); ?>
                                </td>
                                <td style="padding:11px 12px;text-align:right;color:var(--text-mute);font-variant-numeric:tabular-nums;"><?php echo e(format_price((float)$tx['balance_after'], $tx['currency'])); ?></td>
                                <td style="padding:11px 12px;color:var(--text-mute);font-size:12px;white-space:nowrap;"><?php echo e(date('M j, Y g:i a', strtotime($tx['created_at']))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<style>
@media (max-width: 700px) {
    .wallet-stats { grid-template-columns: 1fr 1fr !important; }
}
</style>
<?php seller_page_end(); ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
