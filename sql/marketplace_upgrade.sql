-- ============================================================================
--  ISOKO RYACU — Marketplace Upgrade Migration (Phase 4)
--  =====================================================================
--  Adds the missing e-commerce infrastructure to the existing marketplace:
--    wallets, wallet_transactions, orders, order_items, payments,
--    withdrawals, withdrawal_methods, conversations, messages,
--    delivery_methods, delivery_tracking, addresses, pickup_points,
--    disputes, dispute_messages, refunds, audit_log.
--
--  DESIGN PRINCIPLES
--    - Idempotent (CREATE TABLE IF NOT EXISTS) — safe to re-run.
--    - Does NOT modify or drop any existing table.
--    - All monetary amounts stored as DECIMAL(14,2) for precision.
--    - Every balance-changing table has immutable transaction records.
--    - Foreign keys enforce referential integrity.
--    - Indexes on every column used in WHERE / JOIN / ORDER BY.
--
--  EXTERNAL DEPENDENCIES
--    Payment providers (Mobile Money, card, crypto) are NOT created here —
--    they are configured at runtime via site_settings and resolved through
--    the payment abstraction layer (includes/payment.php). No provider
--    credentials are hard-coded.
-- ============================================================================

SET NAMES utf8mb4;

-- ============================================================================
-- 1. WALLETS — one per user (created lazily on first transaction)
-- ============================================================================
CREATE TABLE IF NOT EXISTS wallets (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL UNIQUE,
    available_balance   DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    pending_balance     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    total_earnings      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    total_spending      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    currency            VARCHAR(8)    NOT NULL DEFAULT 'RWF',
    created_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_wallet_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 2. WALLET_TRANSACTIONS — immutable ledger of every balance change
--    Every deposit, payment, escrow hold, release, refund, withdrawal,
--    and adjustment creates a row here.  This is the source of truth for
--    the wallet balance — the `wallets` table is a denormalised cache that
--    MUST always equal the sum of these rows.
-- ============================================================================
CREATE TABLE IF NOT EXISTS wallet_transactions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wallet_id       INT UNSIGNED NOT NULL,
    type            ENUM('deposit','payment','escrow_hold','escrow_release','refund','withdrawal','adjustment') NOT NULL,
    amount          DECIMAL(14,2) NOT NULL,  -- positive = credit, negative = debit
    balance_after   DECIMAL(14,2) NOT NULL,  -- snapshot of available_balance after this tx
    currency        VARCHAR(8)    NOT NULL DEFAULT 'RWF',
    reference_type  VARCHAR(40)   NULL,      -- 'order', 'withdrawal', 'deposit', 'manual'
    reference_id    INT UNSIGNED  NULL,      -- id of the related order/withdrawal/etc
    description     VARCHAR(255)  NULL,
    created_by      INT UNSIGNED  NULL,      -- admin/user who initiated (NULL = system)
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wt_wallet FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE CASCADE,
    CONSTRAINT fk_wt_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_wt_wallet (wallet_id),
    INDEX idx_wt_type (type),
    INDEX idx_wt_reference (reference_type, reference_id),
    INDEX idx_wt_created (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 3. ADDRESSES — user shipping/pickup addresses
-- ============================================================================
CREATE TABLE IF NOT EXISTS addresses (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    label       VARCHAR(60)  NOT NULL DEFAULT 'Home',  -- Home, Work, etc.
    full_name   VARCHAR(120) NOT NULL,
    phone       VARCHAR(30)  NOT NULL,
    country     VARCHAR(60)  NOT NULL DEFAULT 'Rwanda',
    province    VARCHAR(60)  NULL,
    district    VARCHAR(60)  NULL,
    sector      VARCHAR(60)  NULL,
    street      VARCHAR(160) NULL,
    notes       VARCHAR(255) NULL,
    is_default  TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_addr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_addr_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 4. DELIVERY_METHODS — system-wide delivery options (configurable by admin)
-- ============================================================================
CREATE TABLE IF NOT EXISTS delivery_methods (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(40)  NOT NULL UNIQUE,   -- home_delivery, pickup_point, seller_pickup, express, scheduled
    name            VARCHAR(80)  NOT NULL,
    description     VARCHAR(255) NULL,
    base_fee        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    display_order   INT          NOT NULL DEFAULT 0,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dm_active (is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the default delivery methods (idempotent)
INSERT IGNORE INTO delivery_methods (code, name, description, base_fee, is_active, display_order) VALUES
('home_delivery',  'Home Delivery',    'Delivered to your address',                 2000, 1, 1),
('pickup_point',   'Pickup Point',     'Pick up from a nearby pickup point',         500, 1, 2),
('seller_pickup',  'Seller Pickup',    'Pick up directly from the seller',              0, 1, 3),
('express',        'Express Delivery', 'Same-day express delivery',                 5000, 1, 4),
('scheduled',      'Scheduled Delivery','Choose a delivery date and time',           2500, 1, 5);

-- ============================================================================
-- 5. PICKUP_POINTS — locations for pickup_point delivery method
-- ============================================================================
CREATE TABLE IF NOT EXISTS pickup_points (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    address     VARCHAR(255) NOT NULL,
    district    VARCHAR(60)  NULL,
    province    VARCHAR(60)  NULL,
    phone       VARCHAR(30)  NULL,
    latitude    DECIMAL(10,7) NULL,
    longitude   DECIMAL(10,7) NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pp_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 6. ORDERS — buyer purchase orders
-- ============================================================================
CREATE TABLE IF NOT EXISTS orders (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number        VARCHAR(20)   NOT NULL UNIQUE,   -- human-readable e.g. IR-20260910-0001
    buyer_id            INT UNSIGNED  NOT NULL,
    seller_id           INT UNSIGNED  NOT NULL,          -- seller of the (first) item; multi-seller orders split
    listing_id          INT UNSIGNED  NOT NULL,          -- one order = one listing (simpler model)
    delivery_method_id  INT UNSIGNED  NULL,
    delivery_address_id INT UNSIGNED  NULL,              -- copy of buyer's address (NULL for pickup)
    pickup_point_id     INT UNSIGNED  NULL,
    quantity            INT           NOT NULL DEFAULT 1,
    unit_price          DECIMAL(14,2) NOT NULL,          -- snapshot of listing price at purchase time
    subtotal            DECIMAL(14,2) NOT NULL,          -- unit_price * quantity
    delivery_fee        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    platform_fee        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    discount            DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    tax                 DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    grand_total         DECIMAL(14,2) NOT NULL,
    currency            VARCHAR(8)    NOT NULL DEFAULT 'RWF',
    status              ENUM('pending','confirmed','preparing','ready','shipped','out_for_delivery','delivered','completed','cancelled','disputed') NOT NULL DEFAULT 'pending',
    delivery_status     ENUM('pending','confirmed','preparing','ready','shipped','out_for_delivery','delivered','completed','cancelled') NOT NULL DEFAULT 'pending',
    payment_status      ENUM('unpaid','pending','paid','escrow_held','released','refunded','failed') NOT NULL DEFAULT 'unpaid',
    escrow_released     TINYINT(1)    NOT NULL DEFAULT 0,
    notes               TEXT          NULL,
    confirmed_at        TIMESTAMP     NULL,
    delivered_at        TIMESTAMP     NULL,
    completed_at        TIMESTAMP     NULL,
    cancelled_at        TIMESTAMP     NULL,
    created_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_buyer    FOREIGN KEY (buyer_id)           REFERENCES users(id)            ON DELETE CASCADE,
    CONSTRAINT fk_order_seller   FOREIGN KEY (seller_id)          REFERENCES users(id)            ON DELETE RESTRICT,
    CONSTRAINT fk_order_listing  FOREIGN KEY (listing_id)         REFERENCES listings(id)         ON DELETE RESTRICT,
    CONSTRAINT fk_order_dm       FOREIGN KEY (delivery_method_id) REFERENCES delivery_methods(id) ON DELETE SET NULL,
    CONSTRAINT fk_order_addr     FOREIGN KEY (delivery_address_id) REFERENCES addresses(id)       ON DELETE SET NULL,
    CONSTRAINT fk_order_pp       FOREIGN KEY (pickup_point_id)    REFERENCES pickup_points(id)    ON DELETE SET NULL,
    INDEX idx_order_buyer (buyer_id),
    INDEX idx_order_seller (seller_id),
    INDEX idx_order_listing (listing_id),
    INDEX idx_order_status (status),
    INDEX idx_order_payment (payment_status),
    INDEX idx_order_delivery (delivery_status),
    INDEX idx_order_created (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 7. PAYMENTS — every payment attempt (one order can have multiple if retried)
-- ============================================================================
CREATE TABLE IF NOT EXISTS payments (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_reference   VARCHAR(40)   NOT NULL UNIQUE,  -- internal reference e.g. PAY-20260910-0001
    order_id            INT UNSIGNED  NOT NULL,
    user_id             INT UNSIGNED  NOT NULL,          -- payer
    amount              DECIMAL(14,2) NOT NULL,
    currency            VARCHAR(8)    NOT NULL DEFAULT 'RWF',
    method              VARCHAR(40)   NOT NULL,          -- wallet, momo, card, bank, crypto
    provider            VARCHAR(40)   NOT NULL DEFAULT 'internal',  -- internal, mtn_momo, airtel_money, stripe, flutterwave, etc.
    provider_reference  VARCHAR(120)  NULL,              -- provider's transaction id (NULL until verified)
    status              ENUM('pending','processing','successful','failed','cancelled','refunded') NOT NULL DEFAULT 'pending',
    verified            TINYINT(1)    NOT NULL DEFAULT 0, -- 1 only after server-side verification
    verified_at         TIMESTAMP     NULL,
    failure_reason      VARCHAR(255)  NULL,
    metadata            TEXT          NULL,              -- JSON: provider-specific data
    created_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pay_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_pay_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    INDEX idx_pay_order (order_id),
    INDEX idx_pay_user (user_id),
    INDEX idx_pay_status (status),
    INDEX idx_pay_method (method),
    INDEX idx_pay_created (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 8. WITHDRAWAL_METHODS — user's saved withdrawal destinations
-- ============================================================================
CREATE TABLE IF NOT EXISTS withdrawal_methods (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    type        VARCHAR(20)  NOT NULL,          -- momo, bank, wallet
    label       VARCHAR(60)  NOT NULL,          -- "My MTN", "Bank of Kigali"
    -- Mobile Money fields
    phone       VARCHAR(30)  NULL,
    -- Bank fields
    bank_name   VARCHAR(80)  NULL,
    account_number VARCHAR(40) NULL,
    account_name   VARCHAR(120) NULL,
    -- Generic
    is_preferred TINYINT(1)  NOT NULL DEFAULT 0,
    is_verified  TINYINT(1)  NOT NULL DEFAULT 0,
    created_at   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_wm_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 9. WITHDRAWALS — seller withdrawal requests
-- ============================================================================
CREATE TABLE IF NOT EXISTS withdrawals (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    withdrawal_reference VARCHAR(30) NOT NULL UNIQUE,
    user_id             INT UNSIGNED NOT NULL,
    wallet_id           INT UNSIGNED NOT NULL,
    amount              DECIMAL(14,2) NOT NULL,
    fee                 DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    net_amount          DECIMAL(14,2) NOT NULL,
    currency            VARCHAR(8)    NOT NULL DEFAULT 'RWF',
    withdrawal_method_id INT UNSIGNED NOT NULL,
    status              ENUM('pending','processing','completed','rejected','failed') NOT NULL DEFAULT 'pending',
    provider_reference  VARCHAR(120)  NULL,
    admin_notes         VARCHAR(255)  NULL,
    processed_by        INT UNSIGNED  NULL,    -- admin who approved/rejected
    processed_at        TIMESTAMP     NULL,
    created_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_wd_user   FOREIGN KEY (user_id)              REFERENCES users(id)             ON DELETE CASCADE,
    CONSTRAINT fk_wd_wallet FOREIGN KEY (wallet_id)            REFERENCES wallets(id)          ON DELETE RESTRICT,
    CONSTRAINT fk_wd_method FOREIGN KEY (withdrawal_method_id) REFERENCES withdrawal_methods(id) ON DELETE RESTRICT,
    CONSTRAINT fk_wd_admin  FOREIGN KEY (processed_by)         REFERENCES users(id)            ON DELETE SET NULL,
    INDEX idx_wd_user (user_id),
    INDEX idx_wd_status (status),
    INDEX idx_wd_created (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 10. CONVERSATIONS — 1-to-1 buyer ↔ seller chat (optionally linked to order)
-- ============================================================================
CREATE TABLE IF NOT EXISTS conversations (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user1_id    INT UNSIGNED NOT NULL,   -- always the buyer
    user2_id    INT UNSIGNED NOT NULL,   -- always the seller
    listing_id  INT UNSIGNED NULL,       -- optional: conversation about a specific listing
    order_id    INT UNSIGNED NULL,       -- optional: conversation about a specific order
    last_message_at TIMESTAMP NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_conv_user1 FOREIGN KEY (user1_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_conv_user2 FOREIGN KEY (user2_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_conv_listing FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    CONSTRAINT fk_conv_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    UNIQUE KEY uq_conv (user1_id, user2_id, listing_id),  -- one conversation per buyer+seller+listing
    INDEX idx_conv_user1 (user1_id),
    INDEX idx_conv_user2 (user2_id),
    INDEX idx_conv_last (last_message_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 11. MESSAGES — individual chat messages
-- ============================================================================
CREATE TABLE IF NOT EXISTS messages (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    sender_id       INT UNSIGNED NOT NULL,
    body            TEXT NOT NULL,
    is_read         TINYINT(1)  NOT NULL DEFAULT 0,
    read_at         TIMESTAMP   NULL,
    created_at      TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_msg_conv   FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id)       REFERENCES users(id)          ON DELETE CASCADE,
    INDEX idx_msg_conv (conversation_id, created_at),
    INDEX idx_msg_unread (conversation_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 12. DELIVERY_TRACKING — per-order delivery status history
-- ============================================================================
CREATE TABLE IF NOT EXISTS delivery_tracking (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id    INT UNSIGNED NOT NULL,
    status      ENUM('pending','confirmed','preparing','ready','shipped','out_for_delivery','delivered','completed','cancelled') NOT NULL,
    note        VARCHAR(255) NULL,
    location    VARCHAR(160) NULL,
    created_by  INT UNSIGNED NULL,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dt_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_dt_user  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_dt_order (order_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 13. DISPUTES — buyer can open a dispute on an order
-- ============================================================================
CREATE TABLE IF NOT EXISTS disputes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dispute_number  VARCHAR(20)   NOT NULL UNIQUE,
    order_id        INT UNSIGNED  NOT NULL,
    opened_by       INT UNSIGNED  NOT NULL,    -- buyer
    against_user_id INT UNSIGNED  NOT NULL,    -- seller
    reason          VARCHAR(160)  NOT NULL,
    description     TEXT          NOT NULL,
    status          ENUM('open','under_review','waiting_buyer','waiting_seller','resolved','closed') NOT NULL DEFAULT 'open',
    resolution      ENUM('pending','refund_full','refund_partial','release_to_seller','split') NOT NULL DEFAULT 'pending',
    refund_amount   DECIMAL(14,2) NULL,
    admin_id        INT UNSIGNED  NULL,        -- admin handling the dispute
    resolved_at     TIMESTAMP     NULL,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_disp_order    FOREIGN KEY (order_id)        REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_disp_opener   FOREIGN KEY (opened_by)       REFERENCES users(id)  ON DELETE CASCADE,
    CONSTRAINT fk_disp_against  FOREIGN KEY (against_user_id) REFERENCES users(id)  ON DELETE CASCADE,
    CONSTRAINT fk_disp_admin    FOREIGN KEY (admin_id)        REFERENCES users(id)  ON DELETE SET NULL,
    INDEX idx_disp_order (order_id),
    INDEX idx_disp_status (status),
    INDEX idx_disp_created (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 14. DISPUTE_MESSAGES — communication within a dispute
-- ============================================================================
CREATE TABLE IF NOT EXISTS dispute_messages (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dispute_id  INT UNSIGNED NOT NULL,
    sender_id   INT UNSIGNED NOT NULL,
    body        TEXT NOT NULL,
    is_internal TINYINT(1) NOT NULL DEFAULT 0,  -- admin-only internal note
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dm_dispute FOREIGN KEY (dispute_id) REFERENCES disputes(id) ON DELETE CASCADE,
    CONSTRAINT fk_dm_sender  FOREIGN KEY (sender_id)  REFERENCES users(id)    ON DELETE CASCADE,
    INDEX idx_dm_dispute (dispute_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 15. REFUNDS — refund records (linked to payment + order)
-- ============================================================================
CREATE TABLE IF NOT EXISTS refunds (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    refund_reference VARCHAR(30)  NOT NULL UNIQUE,
    payment_id      INT UNSIGNED  NOT NULL,
    order_id        INT UNSIGNED  NOT NULL,
    amount          DECIMAL(14,2) NOT NULL,
    currency        VARCHAR(8)    NOT NULL DEFAULT 'RWF',
    reason          VARCHAR(255)  NOT NULL,
    status          ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    processed_by    INT UNSIGNED  NULL,
    dispute_id      INT UNSIGNED  NULL,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ref_pay    FOREIGN KEY (payment_id)   REFERENCES payments(id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_order  FOREIGN KEY (order_id)     REFERENCES orders(id)   ON DELETE CASCADE,
    CONSTRAINT fk_ref_admin  FOREIGN KEY (processed_by) REFERENCES users(id)   ON DELETE SET NULL,
    CONSTRAINT fk_ref_disp   FOREIGN KEY (dispute_id)   REFERENCES disputes(id) ON DELETE SET NULL,
    INDEX idx_ref_order (order_id),
    INDEX idx_ref_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 16. AUDIT_LOG — immutable trail of important actions
-- ============================================================================
CREATE TABLE IF NOT EXISTS audit_log (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,           -- who performed the action
    action      VARCHAR(60)  NOT NULL,       -- e.g. 'wallet.deposit', 'order.create', 'payment.verify'
    target_type VARCHAR(40)  NULL,           -- 'order', 'wallet', 'withdrawal', 'dispute'
    target_id   INT UNSIGNED NULL,
    metadata    TEXT          NULL,           -- JSON with action-specific data
    ip_address  VARCHAR(45)  NULL,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_action (action),
    INDEX idx_audit_target (target_type, target_id),
    INDEX idx_audit_created (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 17. CART — session-based cart (stored in DB so it survives across devices)
-- ============================================================================
CREATE TABLE IF NOT EXISTS cart_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    listing_id  INT UNSIGNED NOT NULL,
    quantity    INT          NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cart_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    CONSTRAINT fk_cart_listing FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    UNIQUE KEY uq_cart (user_id, listing_id),
    INDEX idx_cart_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- VERIFICATION — run these to confirm the migration applied correctly:
--   SELECT TABLE_NAME FROM information_schema.TABLES
--    WHERE TABLE_SCHEMA = 'isoko_ryacu' AND TABLE_NAME IN
--      ('wallets','wallet_transactions','orders','payments','withdrawals',
--       'conversations','messages','delivery_methods','delivery_tracking',
--       'addresses','pickup_points','disputes','refunds','audit_log','cart_items');
-- ============================================================================

-- End of marketplace_upgrade.sql
