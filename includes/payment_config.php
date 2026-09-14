<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/** Securely store payment-provider credentials outside source code. */
function payment_config_key(): string
{
    $env = getenv('MARKETSTORE_PAYMENT_KEY');
    if (is_string($env) && strlen($env) >= 32) return hash('sha256', $env, true);

    $path = __DIR__ . '/../storage/payment.key';
    if (!is_file($path)) {
        if (!is_dir(dirname($path))) @mkdir(dirname($path), 0700, true);
        $bytes = random_bytes(32);
        @file_put_contents($path, $bytes, LOCK_EX);
        @chmod($path, 0600);
    }
    $bytes = @file_get_contents($path);
    if (!is_string($bytes) || strlen($bytes) < 32) {
        throw new RuntimeException('Payment encryption key is not available. Set MARKETSTORE_PAYMENT_KEY or create storage/payment.key.');
    }
    return hash('sha256', $bytes, true);
}

function payment_encrypt(string $plain): string
{
    if ($plain === '') return '';
    $key = payment_config_key();
    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 's1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('Unable to encrypt payment credential.');
    return 'g1:' . base64_encode($iv . $tag . $cipher);
}

function payment_decrypt(string $ciphertext): string
{
    if ($ciphertext === '') return '';
    $key = payment_config_key();
    [$version, $payload] = array_pad(explode(':', $ciphertext, 2), 2, '');
    $raw = base64_decode($payload, true);
    if ($raw === false) throw new RuntimeException('Invalid encrypted payment credential.');

    if ($version === 's1' && function_exists('sodium_crypto_secretbox_open')) {
        $nonceLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        $nonce = substr($raw, 0, $nonceLen);
        $box = substr($raw, $nonceLen);
        $plain = sodium_crypto_secretbox_open($box, $nonce, $key);
        if ($plain === false) throw new RuntimeException('Unable to decrypt payment credential.');
        return $plain;
    }
    if ($version === 'g1') {
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) throw new RuntimeException('Unable to decrypt payment credential.');
        return $plain;
    }
    throw new RuntimeException('Unsupported payment credential format.');
}

function payment_setting(string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string)$value;
}

function payment_secret(string $key): string
{
    $value = payment_setting($key, '');
    return $value === '' ? '' : payment_decrypt($value);
}

function payment_save_secret(string $key, string $value, string $description = ''): void
{
    $enc = payment_encrypt($value);
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), description=VALUES(description)');
    $stmt->execute([$key, $enc, $description]);
}

function payment_save_setting(string $key, string $value, string $description = ''): void
{
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), description=VALUES(description)');
    $stmt->execute([$key, $value, $description]);
}
