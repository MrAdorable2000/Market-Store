<?php
/**
 * api/v1/messages/index.php
 * --------------------------------------------------------------------
 * JSON API mirror of pages/messages.php for the mobile app — same
 * authorization checks (every query already scopes by "this user is
 * user1 or user2 of this conversation"), same get_or_create_conversation()
 * function, same read-marking logic. pages/messages.php itself is
 * untouched.
 *
 *   GET  /api/v1/messages/index.php
 *     -> { conversations: [...] }  (list, with last message + unread count)
 *
 *   GET  /api/v1/messages/index.php?conv=N
 *     -> { conversation: {...}, messages: [...] }  (also marks the other
 *        party's messages as read, exactly like opening the conversation
 *        on the website does)
 *
 *   GET  /api/v1/messages/index.php?with=N&listing=N&order=N
 *     -> { conversation_id: N }  (gets or creates the conversation, same
 *        as pages/messages.php's ?with= redirect, but returns the id
 *        instead of redirecting, since a mobile client just wants it)
 *
 *   POST /api/v1/messages/index.php
 *     { "conversation_id": N, "body": "..." }
 *     -> { "message": "Sent" }
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/wallet.php'; // get_or_create_conversation()
require_once __DIR__ . '/../../../includes/notification_service.php'; // notify_user()

if (!function_exists('json_response')) {
    function json_response($data, int $code = 200): void {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
if (!function_exists('json_input')) {
    function json_input(): array {
        $raw = file_get_contents('php://input');
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : $_POST;
    }
}

if (!is_logged_in()) {
    json_response(['error' => 'Login required'], 401);
}

$uid = (int) current_user()['id'];
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_input();
    $csrfToken = (string) ($data['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals((string) ($_SESSION[CSRF_TOKEN_NAME] ?? ''), $csrfToken)) {
        json_response(['error' => 'Invalid or missing CSRF token'], 403);
    }

    $convId = (int) ($data['conversation_id'] ?? 0);
    $body = trim((string) ($data['body'] ?? ''));
    if (!$convId || !$body) {
        json_response(['error' => 'conversation_id and body are required'], 400);
    }

    // Verify the user is part of this conversation — identical guard to
    // pages/messages.php's POST handler.
    $stmt = $pdo->prepare('SELECT * FROM conversations WHERE id = ? AND (user1_id = ? OR user2_id = ?)');
    $stmt->execute([$convId, $uid, $uid]);
    $conv = $stmt->fetch();
    if (!$conv) json_response(['error' => 'Conversation not found'], 404);

    $pdo->prepare('INSERT INTO messages (conversation_id, sender_id, body) VALUES (?, ?, ?)')
        ->execute([$convId, $uid, $body]);
    // Capture this immediately — notify_user() below does its own INSERT
    // (into notifications), which would otherwise overwrite what
    // lastInsertId() reports before we get to read it.
    $newMessageId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE conversations SET last_message_at = NOW() WHERE id = ?')->execute([$convId]);

    $otherId = (int) $conv['user1_id'] === $uid ? (int) $conv['user2_id'] : (int) $conv['user1_id'];
    notify_user($otherId, 'message', 'New message', substr($body, 0, 120), '/pages/messages.php?conv=' . $convId, 'new_message');

    json_response(['message' => 'Sent', 'message_id' => $newMessageId], 201);
}

// --- GET ?with=N: get-or-create a conversation ---
if (isset($_GET['with'])) {
    $otherUserId = (int) $_GET['with'];
    if (!$otherUserId || $otherUserId === $uid) {
        json_response(['error' => 'Invalid recipient'], 400);
    }
    $listingId = isset($_GET['listing']) ? (int) $_GET['listing'] : null;
    $orderId = isset($_GET['order']) ? (int) $_GET['order'] : null;
    $convId = get_or_create_conversation($uid, $otherUserId, $listingId, $orderId);
    json_response(['conversation_id' => $convId]);
}

// --- GET ?conv=N: single conversation + messages ---
if (isset($_GET['conv'])) {
    $convId = (int) $_GET['conv'];
    $stmt = $pdo->prepare(
        'SELECT c.*, u1.full_name AS user1_name, u1.id AS user1_id, u2.full_name AS user2_name, u2.id AS user2_id
         FROM conversations c
         INNER JOIN users u1 ON u1.id = c.user1_id
         INNER JOIN users u2 ON u2.id = c.user2_id
         WHERE c.id = ? AND (c.user1_id = ? OR c.user2_id = ?)'
    );
    $stmt->execute([$convId, $uid, $uid]);
    $conv = $stmt->fetch();
    if (!$conv) json_response(['error' => 'Conversation not found'], 404);

    $otherId = (int) $conv['user1_id'] === $uid ? (int) $conv['user2_id'] : (int) $conv['user1_id'];
    $pdo->prepare(
        'UPDATE messages SET is_read = 1, read_at = NOW() WHERE conversation_id = ? AND sender_id = ? AND is_read = 0'
    )->execute([$convId, $otherId]);

    $stmt = $pdo->prepare('SELECT * FROM messages WHERE conversation_id = ? ORDER BY created_at ASC');
    $stmt->execute([$convId]);

    json_response([
        'conversation' => $conv,
        'messages' => $stmt->fetchAll(),
    ]);
}

// --- GET (no params): conversation list ---
$stmt = $pdo->prepare(
    "SELECT c.*,
            CASE WHEN c.user1_id = ? THEN u2.full_name ELSE u1.full_name END AS other_name,
            CASE WHEN c.user1_id = ? THEN u2.id ELSE u1.id END AS other_id,
            (SELECT body FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) AS last_message,
            (SELECT created_at FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) AS last_msg_at,
            (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id AND sender_id != ? AND is_read = 0) AS unread
       FROM conversations c
       INNER JOIN users u1 ON u1.id = c.user1_id
       INNER JOIN users u2 ON u2.id = c.user2_id
      WHERE c.user1_id = ? OR c.user2_id = ?
      ORDER BY c.last_message_at DESC"
);
$stmt->execute([$uid, $uid, $uid, $uid, $uid]);
json_response(['conversations' => $stmt->fetchAll()]);
