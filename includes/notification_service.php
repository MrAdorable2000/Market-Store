<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/payment_config.php';

/**
 * Central notification service.
 * Every important event creates an in-app notification and can optionally
 * fan out to email/SMS. Delivery failures never break the marketplace flow.
 */
function notification_setting(string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function notification_bool_setting(string $key, bool $default = false): bool
{
    return filter_var(notification_setting($key, $default ? '1' : '0'), FILTER_VALIDATE_BOOLEAN);
}

function normalize_rwanda_phone(string $phone): string
{
    $phone = preg_replace('/[^0-9+]/', '', trim($phone));
    if ($phone === '') return '';
    if (str_starts_with($phone, '00')) $phone = '+' . substr($phone, 2);
    if (str_starts_with($phone, '07') && strlen($phone) === 10) $phone = '+250' . substr($phone, 1);
    if (str_starts_with($phone, '7') && strlen($phone) === 9) $phone = '+250' . $phone;
    if (str_starts_with($phone, '2507') && strlen($phone) === 12) $phone = '+' . $phone;
    return $phone;
}

function notification_log_delivery(int $userId, string $channel, string $eventKey, string $status, string $detail = ''): void
{
    try {
        db()->prepare('INSERT INTO notification_deliveries (user_id, channel, event_key, status, detail) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $channel, $eventKey, $status, mb_substr($detail, 0, 500)]);
    } catch (Throwable $e) {
        // Delivery logging must never break the main application flow.
    }
}

function notification_send_email(array $user, string $subject, string $body, string $eventKey): bool
{
    if (!notification_bool_setting('notifications_email_enabled', true)) return false;
    $email = trim((string)($user['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

    $from = notification_setting('notifications_email_from', '');
    $headers = 'MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n';
    if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $headers .= 'From: Isoko Ryacu <' . $from . '>\r\n';
    }
    $ok = false;
    try { $ok = @mail($email, $subject, $body, $headers); } catch (Throwable $e) { $ok = false; }
    notification_log_delivery((int)$user['id'], 'email', $eventKey, $ok ? 'sent' : 'failed', $ok ? '' : 'mail() failed');
    return $ok;
}

function notification_send_sms(array $user, string $message, string $eventKey): bool
{
    if (!notification_bool_setting('notifications_sms_enabled', false)) return false;
    $phone = normalize_rwanda_phone((string)($user['phone'] ?? $user['whatsapp_number'] ?? ''));
    if ($phone === '') return false;

    $provider = strtolower(trim(notification_setting('sms_provider', 'twilio')));
    $ok = false;
    $detail = '';

    try {
        if ($provider === 'twilio') {
            $sid = payment_secret('sms_twilio_account_sid');
            $token = payment_secret('sms_twilio_auth_token');
            $from = notification_setting('sms_twilio_from', '');
            if ($sid === '' || $token === '' || $from === '') {
                $detail = 'Twilio SMS credentials/sender are not configured.';
            } elseif (function_exists('curl_init')) {
                $ch = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json');
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query(['To' => $phone, 'From' => $from, 'Body' => mb_substr($message, 0, 1500)]),
                    CURLOPT_USERPWD => $sid . ':' . $token,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 12,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                ]);
                $response = curl_exec($ch);
                $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);
                $ok = $http >= 200 && $http < 300;
                $detail = $ok ? 'Twilio accepted the SMS.' : ('HTTP ' . $http . ($err ? ': ' . $err : ''));
                if (!$ok && is_string($response) && strlen($response) < 400) $detail .= ' ' . $response;
            } else {
                $detail = 'PHP cURL is not enabled.';
            }
        } else {
            $detail = 'Unsupported SMS provider: ' . $provider;
        }
    } catch (Throwable $e) {
        $detail = $e->getMessage();
    }

    notification_log_delivery((int)$user['id'], 'sms', $eventKey, $ok ? 'sent' : 'failed', $detail);
    return $ok;
}

function notification_user(int $userId): ?array
{
    $stmt = db()->prepare('SELECT id, full_name, email, phone, whatsapp_number FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    return $u ?: null;
}

/**
 * Create one notification and optionally fan it out.
 * $channels can contain: in_app, email, sms. If omitted, defaults are used.
 */
function notify_user(int $userId, string $type, string $title, string $body = '', ?string $link = null, string $eventKey = 'system', array $channels = ['in_app', 'email']): void
{
    $pdo = db();
    try {
        if (in_array('in_app', $channels, true)) {
            $pdo->prepare('INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)')
                ->execute([$userId, $type, $title, $body, $link]);
            notification_log_delivery($userId, 'in_app', $eventKey, 'sent');
        }

        $user = notification_user($userId);
        if (!$user) return;

        if (in_array('email', $channels, true)) {
            notification_send_email($user, $title, trim($body), $eventKey);
        }
        if (in_array('sms', $channels, true)) {
            notification_send_sms($user, trim($title . ($body !== '' ? ': ' . $body : '')), $eventKey);
        }
    } catch (Throwable $e) {
        // Notification failure must never interrupt the originating action.
        error_log('Notification error [' . $eventKey . ']: ' . $e->getMessage());
    }
}

/** Convenience wrapper for critical events: in-app + email + SMS when enabled. */
function notify_critical(int $userId, string $type, string $title, string $body = '', ?string $link = null, string $eventKey = 'critical'): void
{
    $sms = true;
    if (str_starts_with($eventKey, 'product_review')) $sms = notification_bool_setting('notification_sms_product_review', true);
    elseif (str_starts_with($eventKey, 'order_')) $sms = notification_bool_setting('notification_sms_order_updates', true);
    elseif (str_starts_with($eventKey, 'payment_')) $sms = notification_bool_setting('notification_sms_payment_updates', true);
    notify_user($userId, $type, $title, $body, $link, $eventKey, $sms ? ['in_app', 'email', 'sms'] : ['in_app', 'email']);
}
