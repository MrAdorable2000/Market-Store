<?php
/**
 * Live presence endpoint for the public community list.
 * Returns online count plus each requested user's current/last-seen state.
 */
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    // Keep the currently logged-in visitor truly online while this page is open.
    $me = current_user();
    if ($me && !empty($me['id'])) {
        db()->prepare("UPDATE users SET last_seen_at = NOW() WHERE id = ? AND status = 'active'")
            ->execute([(int) $me['id']]);
    }

    $pdo = db();
    $onlineCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM users WHERE status = 'active' AND last_seen_at >= (NOW() - INTERVAL 5 MINUTE)"
    )->fetchColumn();

    $raw = trim((string) ($_GET['ids'] ?? ''));
    $ids = [];
    foreach (explode(',', $raw) as $value) {
        $id = (int) $value;
        if ($id > 0) $ids[] = $id;
    }
    $ids = array_values(array_unique($ids));

    $users = [];
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, last_seen_at
             FROM users
             WHERE status = 'active' AND id IN ($placeholders)"
        );
        $stmt->execute($ids);

        foreach ($stmt->fetchAll() as $row) {
            $lastSeen = $row['last_seen_at'] ?? null;
            $timestamp = $lastSeen ? strtotime($lastSeen) : false;
            $users[] = [
                'id' => (int) $row['id'],
                'online' => $timestamp !== false && $timestamp >= (time() - 300),
                'last_seen_at' => $lastSeen,
                'last_seen_ts' => $timestamp !== false ? $timestamp : null,
            ];
        }
    }

    echo json_encode([
        'online_count' => $onlineCount,
        'users' => $users,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'online_count' => 0,
        'users' => [],
        'error' => 'Presence unavailable',
    ], JSON_UNESCAPED_UNICODE);
}
