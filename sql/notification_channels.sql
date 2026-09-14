-- Notification channels and delivery audit
INSERT INTO site_settings (setting_key, setting_value, description) VALUES
('notifications_email_enabled', '1', 'Enable email notifications where a valid email is available.'),
('notifications_email_from', '', 'Verified sender email used by PHP mail() when configured.'),
('notifications_sms_enabled', '0', 'Enable SMS notifications after configuring an SMS provider.'),
('sms_provider', 'twilio', 'SMS provider adapter. Currently supported: twilio.'),
('sms_twilio_from', '', 'Twilio sender number or approved sender ID.'),
('notification_sms_product_review', '1', 'Send SMS for product review submission/approval/rejection.'),
('notification_sms_order_updates', '1', 'Send SMS for important order status updates.'),
('notification_sms_payment_updates', '1', 'Send SMS for important payment events.')
ON DUPLICATE KEY UPDATE description=VALUES(description);

CREATE TABLE IF NOT EXISTS notification_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    channel VARCHAR(20) NOT NULL,
    event_key VARCHAR(80) NOT NULL,
    status VARCHAR(20) NOT NULL,
    detail VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_nd_user (user_id),
    INDEX idx_nd_event (event_key),
    INDEX idx_nd_created (created_at),
    CONSTRAINT fk_nd_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
