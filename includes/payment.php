<?php
/**
 * includes/payment.php
 * --------------------------------------------------------------------
 * Payment abstraction layer for Isoko Ryacu marketplace.
 *
 * DESIGN:
 *   - A PaymentProvider interface defines the contract.
 *   - WalletPaymentProvider handles internal wallet-to-wallet payments
 *     (works immediately, no external API needed).
 *   - External providers (MTN MoMo, Airtel Money, Stripe, Flutterwave,
 *     crypto) are registered via config and resolved by method code.
 *     They are NOT hard-coded — each is a drop-in class that implements
 *     the interface and reads its credentials from site_settings.
 *
 * SECURITY:
 *   - No payment is ever marked 'successful' based on a frontend claim.
 *   - Every payment creates a `payments` row with status='pending'.
 *   - The provider verifies server-side and updates the status.
 *   - Only after verification does the wallet get credited / escrow held.
 *   - The `verified` flag is set to 1 ONLY by the server-side verify callback.
 *
 * ESCROW FLOW:
 *   1. Buyer initiates payment → payments row created (pending)
 *   2. Provider processes (wallet = instant; external = redirect/callback)
 *   3. verify_payment() is called server-side → status=successful, verified=1
 *   4. wallet_hold() debits buyer's wallet, order.payment_status=escrow_held
 *   5. When seller fulfills + buyer confirms → wallet_release() credits seller
 *   6. If dispute → funds stay held until admin resolves
 * --------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/wallet.php';

/**
 * Payment provider interface — every provider implements this.
 */
interface PaymentProvider
{
    /** Unique code for this provider (e.g. 'wallet', 'mtn_momo', 'stripe'). */
    public function code(): string;

    /** Human-readable name. */
    public function name(): string;

    /** Whether this provider is ready (has credentials configured). */
    public function isConfigured(): bool;

    /**
     * Initiate a payment. Returns an array with at minimum:
     *   ['status' => 'pending'|'successful'|'failed', 'provider_reference' => '...', 'redirect_url' => '...']
     *
     * For wallet payments, this completes immediately.
     * For external providers, this starts the flow and returns a redirect/checkout URL.
     */
    public function initiate(int $paymentId, float $amount, string $currency, array $metadata = []): array;

    /**
     * Verify a payment server-side. Called after the provider redirects back
     * or via a webhook. Returns true only if the provider confirms the payment.
     */
    public function verify(int $paymentId): bool;
}

/**
 * Wallet payment provider — internal wallet-to-wallet.
 * Works immediately, no external API needed.
 */
class WalletPaymentProvider implements PaymentProvider
{
    public function code(): string { return 'wallet'; }
    public function name(): string { return 'Wallet'; }
    public function isConfigured(): bool { return true; }

    public function initiate(int $paymentId, float $amount, string $currency, array $metadata = []): array
    {
        $pdo = db();
        // NOTE: checkout.php wraps its whole multi-order loop in one outer
        // transaction and calls initiate() from inside it. PDO does not
        // support nested transactions, so unconditionally calling
        // beginTransaction() here throws "There is already an active
        // transaction" and every wallet checkout fails. Match the safe
        // pattern already used in wallet.php's _wallet_apply_change(): only
        // start/commit/roll back the transaction if this call is the one
        // that opened it.
        $startedTransaction = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }
        try {
            // Lock the payment row
            $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch();
            if (!$payment) throw new RuntimeException('Payment not found: ' . $paymentId);
            if ($payment['status'] !== 'pending') {
                throw new RuntimeException('Payment already processed: ' . $paymentId);
            }

            $userId = (int) $payment['user_id'];
            $orderId = (int) $payment['order_id'];
            $balance = wallet_balance($userId);

            if ($balance['available'] < $amount) {
                // Insufficient funds — mark as failed
                $pdo->prepare(
                    "UPDATE payments SET status = 'failed', failure_reason = 'Insufficient wallet balance', updated_at = NOW() WHERE id = ?"
                )->execute([$paymentId]);
                if ($startedTransaction) $pdo->commit();
                return ['status' => 'failed', 'provider_reference' => null, 'error' => 'Insufficient wallet balance'];
            }

            // Debit the buyer's wallet (escrow hold)
            wallet_hold($userId, $amount, $orderId, 'Payment for order #' . $orderId);

            // Mark payment as successful + verified
            $providerRef = 'WALLET-' . $paymentId . '-' . time();
            $pdo->prepare(
                "UPDATE payments
                 SET status = 'successful', verified = 1, verified_at = NOW(),
                     provider_reference = ?, provider = 'internal', updated_at = NOW()
                 WHERE id = ?"
            )->execute([$providerRef, $paymentId]);

            // Update the order payment status to escrow_held
            $pdo->prepare(
                "UPDATE orders SET payment_status = 'escrow_held', updated_at = NOW() WHERE id = ?"
            )->execute([$orderId]);

            // Create a delivery tracking entry (order confirmed)
            $pdo->prepare(
                "INSERT INTO delivery_tracking (order_id, status, note, created_by) VALUES (?, 'confirmed', 'Payment received', ?)"
            )->execute([$orderId, $userId]);

            $pdo->prepare(
                "UPDATE orders SET status = 'confirmed', delivery_status = 'confirmed', confirmed_at = NOW(), updated_at = NOW() WHERE id = ?"
            )->execute([$orderId]);

            if ($startedTransaction) $pdo->commit();

            // Notify the seller
            $seller = $pdo->prepare('SELECT seller_id FROM orders WHERE id = ?');
            $seller->execute([$orderId]);
            $sellerId = (int) $seller->fetchColumn();
            if ($sellerId) {
                $pdo->prepare(
                    'INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)'
                )->execute([
                    $sellerId,
                    'order',
                    'New order received',
                    'You have received a new order. Confirm and prepare the item.',
                    '/pages/orders.php?view=seller&id=' . $orderId,
                ]);
            }

            return ['status' => 'successful', 'provider_reference' => $providerRef];
        } catch (Throwable $e) {
            // Only roll back if this call opened the transaction — if we're
            // nested inside checkout.php's outer transaction, rolling back
            // here would resolve it prematurely; let the exception propagate
            // so the outer caller can roll back the whole checkout atomically.
            if ($startedTransaction && $pdo->inTransaction()) {
                try { $pdo->rollBack(); } catch (PDOException $rb) { /* ignore */ }
            }
            throw $e;
        }
    }

    public function verify(int $paymentId): bool
    {
        // Wallet payments are verified synchronously during initiate()
        $stmt = db()->prepare('SELECT verified FROM payments WHERE id = ?');
        $stmt->execute([$paymentId]);
        return (bool) $stmt->fetchColumn();
    }
}

/**
 * Get the registered payment providers.
 * External providers are only included if their credentials are configured
 * in site_settings. The wallet provider is always available.
 *
 * @return PaymentProvider[]
 */
function get_payment_providers(): array
{
    $providers = ['wallet' => new WalletPaymentProvider()];

    // External providers — registered here only if configured.
    // Each reads its credentials from site_settings at runtime.
    // To enable a provider, set its credentials in site_settings via the
    // admin panel, then uncomment the registration here.

    // $momo = new MtnMoMoProvider();
    // if ($momo->isConfigured()) $providers['mtn_momo'] = $momo;

    // $airtel = new AirtelMoneyProvider();
    // if ($airtel->isConfigured()) $providers['airtel_money'] = $airtel;

    // $stripe = new StripeProvider();
    // if ($stripe->isConfigured()) $providers['stripe'] = $stripe;

    return $providers;
}

/**
 * Get a single payment provider by code.
 */
function get_payment_provider(string $code): ?PaymentProvider
{
    $providers = get_payment_providers();
    return $providers[$code] ?? null;
}

/**
 * Generate a unique payment reference.
 */
function generate_payment_reference(): string
{
    return 'PAY-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

/**
 * Generate a unique order number.
 */
function generate_order_number(): string
{
    return 'IR-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

/**
 * Generate a unique withdrawal reference.
 */
function generate_withdrawal_reference(): string
{
    return 'WD-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

/**
 * Generate a unique dispute number.
 */
function generate_dispute_number(): string
{
    return 'DSP-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

/**
 * Generate a unique refund reference.
 */
function generate_refund_reference(): string
{
    return 'REF-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

/**
 * Create a payment record for an order.
 *
 * @param int    $orderId
 * @param int    $userId   Payer
 * @param float  $amount
 * @param string $method   wallet|momo|card|bank|crypto
 * @return int   Payment ID
 */
function create_payment(int $orderId, int $userId, float $amount, string $method): int
{
    $pdo = db();
    $ref = generate_payment_reference();
    $pdo->prepare(
        'INSERT INTO payments (payment_reference, order_id, user_id, amount, method, provider, status)
         VALUES (?, ?, ?, ?, ?, ?, "pending")'
    )->execute([
        $ref, $orderId, $userId, round($amount, 2), $method,
        $method === 'wallet' ? 'internal' : $method,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Release escrow funds to the seller after the buyer confirms receipt.
 * This is the final step in the order lifecycle.
 *
 * @param int $orderId
 * @param int $buyerId  (for authorization)
 * @return bool
 */
function release_escrow_to_seller(int $orderId, int $buyerId): bool
{
    $pdo = db();
    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }
    try {
        // Lock the order row
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order) throw new RuntimeException('Order not found');
        if ((int) $order['buyer_id'] !== $buyerId) throw new RuntimeException('Not authorized');
        if ($order['escrow_released']) throw new RuntimeException('Escrow already released');
        if ($order['payment_status'] !== 'escrow_held') throw new RuntimeException('No escrow to release');
        if ($order['status'] === 'disputed') throw new RuntimeException('Cannot release — order is disputed');

        $sellerId = (int) $order['seller_id'];
        $amount = (float) $order['grand_total'];

        // Release funds to seller's wallet
        wallet_release($sellerId, $amount, $orderId, 'Escrow released for order #' . $order['order_number']);

        // Mark order as completed + escrow released
        $pdo->prepare(
            "UPDATE orders
             SET escrow_released = 1, payment_status = 'released',
                 status = 'completed', delivery_status = 'completed',
                 completed_at = NOW(), updated_at = NOW()
             WHERE id = ?"
        )->execute([$orderId]);

        // Delivery tracking entry
        $pdo->prepare(
            "INSERT INTO delivery_tracking (order_id, status, note, created_by) VALUES (?, 'completed', 'Order completed and funds released', ?)"
        )->execute([$orderId, $buyerId]);

        // Notify the seller
        $pdo->prepare(
            'INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $sellerId, 'payment', 'Funds released',
            'Funds for order ' . $order['order_number'] . ' have been released to your wallet.',
            '/pages/orders.php?view=seller&id=' . $orderId,
        ]);

        if ($startedTransaction) $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (PDOException $rb) { /* ignore */ }
        }
        throw $e;
    }
}
