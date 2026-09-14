<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../config/database.php';
require_login();
$id = (int)($_GET['id'] ?? 0);
if ($id < 1) { http_response_code(404); exit; }
try {
    $pdo = db();
    $st = $pdo->prepare('SELECT avatar_blob, avatar_mime FROM users WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['avatar_blob'])) { http_response_code(404); exit; }
    $mime = (string)($row['avatar_mime'] ?? 'image/jpeg');
    if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) $mime = 'image/jpeg';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($row['avatar_blob']));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    echo $row['avatar_blob'];
} catch (Throwable $e) {
    http_response_code(404);
}
