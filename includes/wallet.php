<?php
/**
 * includes/wallet.php
 * --------------------------------------------------------------------
 * Wallet system for Isoko Ryacu marketplace.
 *
 *   get_or_create_wallet($userId)   — returns the user's wallet (creates if missing)
 *   wallet_balance($userId)         — ['available','pending','earnings','spending']
 *   wallet_deposit($userId, $amount, $description, $refType, $refId)
 *   wallet_hold($userId, $amount, $orderId, $description)   — move available → pending (escrow)
 *   wallet_release($userId, $amount, $orderId, $description) — move pending → available (seller gets paid)
 *   wallet_refund($userId, $amount, $orderId, $description)  — reverse a hold back to buyer
 *   wallet_withdraw($userId, $amount, $withdrawalId, $description) — debit available
 *   wallet_adjust($userId, $amount, $reason)  — admin manual adjustment
 *   wallet_transactions($userId, $limit)       — transaction history
 *
 * SECURITY:
 *   - Every balance change is wrapped in a DB transaction with SELECT ... FOR UPDATE
 *     on the wallet row, so concurrent requests cannot cause race conditions.
 *   - Every change creates an immutable wallet_transactions row with a balance_after
 *     snapshot, so the ledger is always reconstructable.
 *   - Amounts are validated as positive DECIMALs. Negative deposits/withdrawals are rejected.
 *   - The function never trusts a client-supplied balance — it always reads the
 *     authoritative value from the DB inside the lock.
 * --------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../config/database.php';

/**
 * Get the user's wallet, creating it if it doesn't exist yet.
 * Uses INSERT ... ON DUPLICATE KEY UPDATE so it's safe under concurrency.
 */
function get_or_create_wallet(int $userId): array
{
    $pdo = db();
    $pdo->prepare(
        'INSERT IGNORE INTO wallets (user_id) VALUES (?)'
    )->execute([$userId]);

    $stmt = $pdo->prepare('SELECT * FROM wallets WHERE user_id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: throw new RuntimeException('Wallet not found for user ' . $userId);
}

/**
 * Return the wallet balances for a user.
 * If the wallet doesn't exist yet, returns all zeros (no wallet created).
 */
function wallet_balance(int $userId): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT available_balance, pending_balance, total_earnings, total_spending, currency
         FROM wallets WHERE user_id = ?'
    );
    $stmt->execute([$userId]);
    $w = $stmt->fetch();
    if (!$w) {
        return ['available' => 0.00, 'pending' => 0.00, 'earnings' => 0.00, 'spending' => 0.00, 'currency' => 'RWF'];
    }
    return [
        'available' => (float) $w['available_balance'],
        'pending'   => (float) $w['pending_balance'],
        'earnings'  => (float) $w['total_earnings'],
        'spending'  => (float) $w['total_spending'],
        'currency'  => $w['currency'],
    ];
}

/**
 * Internal: apply a balance change inside a locked transaction.
 *
 * @param int    $userId
 * @param float  $amount         Positive = credit, negative = debit
 * @param string $type           deposit|payment|escrow_hold|escrow_release|refund|withdrawal|adjustment
 * @param string $description
 * @param string $referenceType  order|withdrawal|deposit|manual
 * @param int    $referenceId
 * @param int    $createdBy      user who initiated (NULL = system)
 * @return array  The updated wallet row
 * @throws PDOException|RuntimeException
 */
function _wallet_apply_change(
    int $userId,
    float $amount,
    string $type,
    string $description,
    ?string $referenceType = null,
    ?int $referenceId = null,
    ?int $createdBy = null
): array {
    $pdo = db();
    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }
    try {
        // Ensure the wallet exists, then lock the row for update
        $pdo->prepare('INSERT IGNORE INTO wallets (user_id) VALUES (?)')->execute([$userId]);
        $stmt = $pdo->prepare('SELECT * FROM wallets WHERE user_id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $wallet = $stmt->fetch();
        if (!$wallet) {
            throw new RuntimeException('Wallet not found after INSERT IGNORE');
        }

        $available = (float) $wallet['available_balance'];
        $pending   = (float) $wallet['pending_balance'];
        $earnings  = (float) $wallet['total_earnings'];
        $spending  = (float) $wallet['total_spending'];
        $currency  = $wallet['currency'];

        // Apply the balance change based on the transaction type
        switch ($type) {
            case 'deposit':
            case 'adjustment':
                // Credit to available
                if ($amount < 0 && $available + $amount < 0) {
                    throw new RuntimeException('Insufficient available balance for debit');
                }
                $available += $amount;
                break;

            case 'payment':
                // Debit from available (buyer pays)
                if ($available - abs($amount) < 0) {
                    throw new RuntimeException('Insufficient balance for payment');
                }
                $available -= abs($amount);
                $spending  += abs($amount);
                $amount = -abs($amount); // store as negative
                break;

            case 'escrow_hold':
                // Move from buyer's available → pending (seller's pending)
                // For the BUYER's wallet: available decreases, pending stays (it's the seller's pending)
                if ($available - abs($amount) < 0) {
                    throw new RuntimeException('Insufficient balance for escrow hold');
                }
                $available -= abs($amount);
                $spending  += abs($amount);
                $amount = -abs($amount);
                break;

            case 'escrow_release':
                // Credit to seller's available (funds released from escrow)
                $available += abs($amount);
                $earnings  += abs($amount);
                $amount = abs($amount);
                break;

            case 'refund':
                // Credit back to buyer's available (escrow reversed)
                $available += abs($amount);
                $spending  -= abs($amount);
                if ($spending < 0) $spending = 0;
                $amount = abs($amount);
                break;

            case 'withdrawal':
                // Debit from available
                if ($available - abs($amount) < 0) {
                    throw new RuntimeException('Insufficient balance for withdrawal');
                }
                $available -= abs($amount);
                $amount = -abs($amount);
                break;

            default:
                throw new RuntimeException('Unknown wallet transaction type: ' . $type);
        }

        // Update the wallet row (still locked)
        $pdo->prepare(
            'UPDATE wallets SET available_balance = ?, pending_balance = ?, total_earnings = ?, total_spending = ? WHERE user_id = ?'
        )->execute([
            round($available, 2),
            round($pending, 2),
            round($earnings, 2),
            round($spending, 2),
            $userId,
        ]);

        // Insert the immutable transaction record with balance_after snapshot
        $pdo->prepare(
            'INSERT INTO wallet_transactions (wallet_id, type, amount, balance_after, currency, reference_type, reference_id, description, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $wallet['id'],
            $type,
            round($amount, 2),
            round($available, 2),
            $currency,
            $referenceType,
            $referenceId,
            $description,
            $createdBy,
        ]);

        if ($startedTransaction) {
            $pdo->commit();
        }

        return [
            'id'              => $wallet['id'],
            'user_id'         => $userId,
            'available'       => round($available, 2),
            'pending'         => round($pending, 2),
            'earnings'        => round($earnings, 2),
            'spending'        => round($spending, 2),
            'currency'        => $currency,
        ];
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (PDOException $rb) { /* ignore */ }
        }
        throw $e;
    }
}

/**
 * Deposit funds into a user's wallet (e.g. from a payment provider or admin top-up).
 */
function wallet_deposit(int $userId, float $amount, string $description, ?string $refType = null, ?int $refId = null, ?int $createdBy = null): array
{
    if ($amount <= 0) throw new InvalidArgumentException('Deposit amount must be positive');
    return _wallet_apply_change($userId, $amount, 'deposit', $description, $refType, $refId, $createdBy);
}

/**
 * Hold funds in escrow (buyer's available → debited, seller's pending → credited later).
 * This debits the buyer's wallet. The seller's pending balance is tracked via
 * the order's payment_status = 'escrow_held'.
 */
function wallet_hold(int $userId, float $amount, int $orderId, string $description = ''): array
{
    if ($amount <= 0) throw new InvalidArgumentException('Hold amount must be positive');
    $desc = $description ?: 'Escrow hold for order #' . $orderId;
    return _wallet_apply_change($userId, $amount, 'escrow_hold', $desc, 'order', $orderId);
}

/**
 * Release escrowed funds to the seller's available balance.
 * This credits the seller's wallet. The order's escrow_released flag prevents
 * double release.
 */
function wallet_release(int $sellerId, float $amount, int $orderId, string $description = ''): array
{
    if ($amount <= 0) throw new InvalidArgumentException('Release amount must be positive');
    $desc = $description ?: 'Escrow release for order #' . $orderId;
    return _wallet_apply_change($sellerId, $amount, 'escrow_release', $desc, 'order', $orderId);
}

/**
 * Refund escrowed funds back to the buyer.
 * This credits the buyer's wallet (reversing the original hold).
 */
function wallet_refund(int $buyerId, float $amount, int $orderId, string $description = ''): array
{
    if ($amount <= 0) throw new InvalidArgumentException('Refund amount must be positive');
    $desc = $description ?: 'Refund for order #' . $orderId;
    return _wallet_apply_change($buyerId, $amount, 'refund', $desc, 'order', $orderId);
}

/**
 * Withdraw funds from the user's available balance.
 * The withdrawal request record is created separately in the withdrawals table;
 * this function only moves the money once the withdrawal is approved.
 */
function wallet_withdraw(int $userId, float $amount, int $withdrawalId, string $description = ''): array
{
    if ($amount <= 0) throw new InvalidArgumentException('Withdrawal amount must be positive');
    $desc = $description ?: 'Withdrawal #' . $withdrawalId;
    return _wallet_apply_change($userId, $amount, 'withdrawal', $desc, 'withdrawal', $withdrawalId);
}

/**
 * Admin manual adjustment (credit or debit).
 */
function wallet_adjust(int $userId, float $amount, string $reason, ?int $adminId = null): array
{
    return _wallet_apply_change($userId, $amount, 'adjustment', $reason, 'manual', null, $adminId);
}

/**
 * Get the transaction history for a user's wallet.
 */
function wallet_transactions(int $userId, int $limit = 50, int $offset = 0): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT wt.* FROM wallet_transactions wt
         INNER JOIN wallets w ON w.id = wt.wallet_id
         WHERE w.user_id = ?
         ORDER BY wt.created_at DESC
         LIMIT ? OFFSET ?'
    );
    $stmt->execute([$userId, $limit, $offset]);
    return $stmt->fetchAll();
}

/**
 * Get the count of unread messages for a user (across all conversations).
 */
function unread_message_count(int $userId): int
{
    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM messages m
             INNER JOIN conversations c ON c.id = m.conversation_id
             WHERE m.sender_id != ? AND m.is_read = 0
               AND (c.user1_id = ? OR c.user2_id = ?)'
        );
        $stmt->execute([$userId, $userId, $userId]);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0; // table might not exist yet
    }
}

/**
 * Get or create a conversation between two users about a listing.
 */
function get_or_create_conversation(int $buyerId, int $sellerId, ?int $listingId = null, ?int $orderId = null): int
{
    $pdo = db();
    // Check for existing conversation
    $stmt = $pdo->prepare(
        'SELECT id FROM conversations
         WHERE user1_id = ? AND user2_id = ? AND listing_id <=> ? LIMIT 1'
    );
    $stmt->execute([$buyerId, $sellerId, $listingId]);
    $conv = $stmt->fetch();
    if ($conv) return (int) $conv['id'];

    // Create new
    $stmt = $pdo->prepare(
        'INSERT INTO conversations (user1_id, user2_id, listing_id, order_id) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$buyerId, $sellerId, $listingId, $orderId]);
    return (int) $pdo->lastInsertId();
}
