<?php
/**
 * api/v1/messages-poll/index.php
 * --------------------------------------------------------------------
 * GET /api/v1/messages-poll?action=unread_count
 *   -> { count: N }  — used by the navbar badge on every page.
 *
 * GET /api/v1/messages-poll?action=conversation&conversation_id=X&after_id=Y
 *   -> { messages: [...], unread_count: N }
 *      New messages in conversation X with id > Y. Marks any of those
 *      messages sent by the other participant as read (same behavior
 *      as opening the conversation normally).
 *
 * Read-only polling endpoint (a GET that marks messages read as a side
 * effect, same convention already used when opening a conversation in
 * pages/messages.php) — no state-changing writes beyond that, so no
 * CSRF token is required, consistent with that existing page.
 */
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../config/database.php';

function json_response($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!current_user()) {
    json_response(['error' => 'Not authenticated'], 401);
}

$uid    = (int) current_user()['id'];
$pdo    = db();
$action = $_GET['action'] ?? '';

if ($action === 'unread_count') {
    json_response(['count' => unread_messages_count($uid)]);
}

if ($action === 'conversation') {
    $convId  = (int) ($_GET['conversation_id'] ?? 0);
    $afterId = (int) ($_GET['after_id'] ?? 0);

    if ($convId <= 0) {
        json_response(['error' => 'Missing conversation_id'], 400);
    }

    // Authorization: the requester must be a participant.
    $stmt = $pdo->prepare('SELECT * FROM conversations WHERE id = ? AND (user1_id = ? OR user2_id = ?)');
    $stmt->execute([$convId, $uid, $uid]);
    $conv = $stmt->fetch();
    if (!$conv) {
        json_response(['error' => 'Conversation not found'], 404);
    }

    $otherId = (int) $conv['user1_id'] === $uid ? (int) $conv['user2_id'] : (int) $conv['user1_id'];

    // Mark any new messages from the other participant as read.
    $pdo->prepare(
        'UPDATE messages SET is_read = 1, read_at = NOW()
         WHERE conversation_id = ? AND sender_id = ? AND is_read = 0'
    )->execute([$convId, $otherId]);

    $stmt = $pdo->prepare(
        'SELECT id, sender_id, body, created_at FROM messages
         WHERE conversation_id = ? AND id > ? ORDER BY created_at ASC'
    );
    $stmt->execute([$convId, $afterId]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($messages as &$m) {
        $m['id']        = (int) $m['id'];
        $m['sender_id'] = (int) $m['sender_id'];
        $m['is_mine']   = $m['sender_id'] === $uid;
    }
    unset($m);

    json_response([
        'messages'      => $messages,
        'unread_count'  => unread_messages_count($uid),
    ]);
}

json_response(['error' => 'Unknown action'], 400);
