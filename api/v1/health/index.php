<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/performance.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$started = microtime(true);
try {
    $pdo = db();
    $pdo->query('SELECT 1')->fetchColumn();
    $dbOk = true;
} catch (Throwable $e) {
    $dbOk = false;
}

// Server-log-only safety net; never affects this endpoint's response.
if ($dbOk) {
    warn_if_demo_credentials_active();
}


$ok = $dbOk;
http_response_code($ok ? 200 : 503);
echo json_encode([
    'ok' => $ok,
    'service' => 'isoko-ryacu',
    'environment' => APP_ENV,
    'database' => $dbOk ? 'ok' : 'unavailable',
    'latency_ms' => round((microtime(true) - $started) * 1000, 2),
    'timestamp' => gmdate('c'),
], JSON_UNESCAPED_SLASHES);
