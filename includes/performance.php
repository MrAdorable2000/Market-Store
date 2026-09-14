<?php
declare(strict_types=1);

/** Optional APCu application cache. Falls back to no-op when APCu is unavailable. */
function app_cache_get(string $key, &$success = null)
{
    if (function_exists('apcu_fetch')) {
        $value = apcu_fetch('isoko:' . $key, $ok);
        $success = $ok;
        return $value;
    }
    $success = false;
    return null;
}

function app_cache_set(string $key, mixed $value, int $ttl = 60): bool
{
    if (function_exists('apcu_store')) return (bool) apcu_store('isoko:' . $key, $value, max(1, $ttl));
    return false;
}

function app_cache_delete(string $key): bool
{
    if (function_exists('apcu_delete')) return (bool) apcu_delete('isoko:' . $key);
    return false;
}

function request_is_api(): bool
{
    $path = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    return str_contains($path, '/api/');
}

/**
 * Lightweight API rate limiter. Uses APCu when available; otherwise it is
 * deliberately disabled so a missing cache extension never adds DB load.
 * Returns true when the request is allowed.
 */
function api_rate_limit(string $bucket, int $maxRequests = 120, int $windowSeconds = 60): bool
{
    if (!function_exists('apcu_fetch') || !function_exists('apcu_store')) return true;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user = function_exists('current_user') ? (int)($_SESSION['user_id'] ?? 0) : 0;
    $key = 'rl:' . hash('sha256', $bucket . '|' . $ip . '|' . $user);
    $now = time();
    $entry = apcu_fetch($key, $ok);
    if (!$ok || !is_array($entry) || ($entry['expires'] ?? 0) <= $now) {
        apcu_store($key, ['count' => 1, 'expires' => $now + $windowSeconds], $windowSeconds);
        return true;
    }
    if ((int)$entry['count'] >= $maxRequests) return false;
    $entry['count']++;
    apcu_store($key, $entry, max(1, (int)$entry['expires'] - $now));
    return true;
}

function send_json_error(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
