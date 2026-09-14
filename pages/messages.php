<?php
/**
 * pages/messages.php — Buyer ↔ Seller chat
 * --------------------------------------------------------------------
 * 1-to-1 conversations linked to listings/orders.
 *
 *   ?with=N          — open/create conversation with user N
 *   ?order=N         — link conversation to order N
 *   ?listing=N       — link conversation to listing N
 *   ?conv=N          — open conversation N
 *
 * Shows conversation list on the left, active conversation on the right.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/seller_sidebar.php';
require_login();

$uid = (int) current_user()['id'];
$pdo = db();

// --- Handle POST: send message ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        flash_set('error', t('errors.invalid_token'));
    } else {
        $convId = (int)($_POST['conversation_id'] ?? 0);
        $body = trim($_POST['body'] ?? '');
        if ($convId && $body) {
            // Verify the user is part of this conversation
            $stmt = $pdo->prepare('SELECT * FROM conversations WHERE id = ? AND (user1_id = ? OR user2_id = ?)');
            $stmt->execute([$convId, $uid, $uid]);
            $conv = $stmt->fetch();
            if ($conv) {
                $pdo->prepare('INSERT INTO messages (conversation_id, sender_id, body) VALUES (?, ?, ?)')->execute([$convId, $uid, $body]);
                $pdo->prepare('UPDATE conversations SET last_message_at = NOW() WHERE id = ?')->execute([$convId]);
                // Mark as read by the sender (they just sent it)
                // The other party's unread count will be calculated dynamically
            }
        }
    }
    redirect(APP_URL . '/pages/messages.php?conv=' . $convId);
}

// --- Open or create a conversation ---
$activeConv = null;
if (isset($_GET['with'])) {
    $otherUserId = (int)$_GET['with'];
    if ($otherUserId && $otherUserId !== $uid) {
        $listingId = isset($_GET['listing']) ? (int)$_GET['listing'] : null;
        $orderId = isset($_GET['order']) ? (int)$_GET['order'] : null;
        // Determine who is buyer (user1) and who is seller (user2)
        // The current user is user1, the other is user2
        $convId = get_or_create_conversation($uid, $otherUserId, $listingId, $orderId);
        redirect(APP_URL . '/pages/messages.php?conv=' . $convId);
    }
} elseif (isset($_GET['conv'])) {
    $convId = (int)$_GET['conv'];
    $stmt = $pdo->prepare(
        'SELECT c.*, u1.full_name AS user1_name, u1.id AS user1_id, u2.full_name AS user2_name, u2.id AS user2_id
         FROM conversations c
         INNER JOIN users u1 ON u1.id = c.user1_id
         INNER JOIN users u2 ON u2.id = c.user2_id
         WHERE c.id = ? AND (c.user1_id = ? OR c.user2_id = ?)'
    );
    $stmt->execute([$convId, $uid, $uid]);
    $activeConv = $stmt->fetch();
    if ($activeConv) {
        // Mark messages from the other party as read
        $otherId = (int)$activeConv['user1_id'] === $uid ? (int)$activeConv['user2_id'] : (int)$activeConv['user1_id'];
        $pdo->prepare(
            'UPDATE messages SET is_read = 1, read_at = NOW()
             WHERE conversation_id = ? AND sender_id = ? AND is_read = 0'
        )->execute([$convId, $otherId]);

        // Load messages
        $stmt = $pdo->prepare('SELECT * FROM messages WHERE conversation_id = ? ORDER BY created_at ASC');
        $stmt->execute([$convId]);
        $activeConv['messages'] = $stmt->fetchAll();
    }
}

// --- Load conversation list ---
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
$conversations = $stmt->fetchAll();

$pageTitle = 'Messages';
$activePage = 'dashboard';
require_once __DIR__ . '/../includes/header.php';
?>
<?php seller_page_start('Messages', $uid, $pdo); ?>
    <h1 style="font-size:22px;font-weight:800;margin:0 0 20px;letter-spacing:-.02em;">Messages</h1>

    <div style="display:grid;grid-template-columns:300px 1fr;gap:16px;height:600px;" class="chat-layout">
        <!-- Conversation list -->
        <div class="card" style="padding:0;overflow:hidden;display:flex;flex-direction:column;">
            <div style="padding:14px 16px;border-bottom:1px solid var(--border);">
                <strong style="font-size:14px;">Conversations</strong>
            </div>
            <div style="overflow-y:auto;flex:1;">
                <?php if (empty($conversations)): ?>
                    <div style="padding:24px 16px;text-align:center;color:var(--text-mute);font-size:13px;">
                        No conversations yet. Start chatting from a product page!
                    </div>
                <?php else: ?>
                    <?php foreach ($conversations as $c): ?>
                        <a href="<?php echo APP_URL; ?>/pages/messages.php?conv=<?php echo (int)$c['id']; ?>"
                           style="display:block;padding:12px 16px;border-bottom:1px solid var(--bg-soft);text-decoration:none;color:inherit;transition:background 160ms;<?php echo $activeConv && (int)$activeConv['id']===(int)$c['id'] ? 'background:var(--brand-50);' : ''; ?>"
                           onmouseover="this.style.background='var(--bg-soft)'"
                           onmouseout="this.style.background='<?php echo $activeConv && (int)$activeConv['id']===(int)$c['id'] ? "var(--brand-50)" : "transparent"; ?>'">
                            <div style="display:flex;justify-content:space-between;align-items:center;">
                                <strong style="font-size:13.5px;color:var(--text);"><?php echo e($c['other_name']); ?></strong>
                                <?php if ((int)$c['unread'] > 0): ?>
                                    <span style="background:var(--accent-500);color:#fff;font-size:10px;font-weight:800;padding:2px 7px;border-radius:20px;"><?php echo (int)$c['unread']; ?></span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:12px;color:var(--text-mute);margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                <?php echo e($c['last_message'] ?? 'No messages yet'); ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Active conversation -->
        <div class="card" style="padding:0;overflow:hidden;display:flex;flex-direction:column;">
            <?php if (!$activeConv): ?>
                <div style="flex:1;display:grid;place-items:center;color:var(--text-mute);text-align:center;padding:24px;">
                    <div>
                        <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.3" style="opacity:.4;margin-bottom:12px;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        <h3 style="font-size:15px;margin:0 0 6px;">Select a conversation</h3>
                        <p style="font-size:13px;margin:0;">Choose a conversation from the left, or start a new one from a product page.</p>
                    </div>
                </div>
            <?php else:
                $otherName = (int)$activeConv['user1_id'] === $uid ? $activeConv['user2_name'] : $activeConv['user1_name'];
            ?>
                <div style="padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;">
                    <span style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--brand-500),var(--brand-700));color:#fff;display:grid;place-items:center;font-weight:700;font-size:14px;"><?php echo e(strtoupper(substr($otherName,0,1))); ?></span>
                    <strong style="font-size:14px;"><?php echo e($otherName); ?></strong>
                </div>
                <div style="flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:8px;background:var(--bg-soft);" id="chatMessages">
                    <?php if (empty($activeConv['messages'])): ?>
                        <div style="text-align:center;color:var(--text-mute);font-size:13px;padding:20px;">No messages yet. Say hello! 👋</div>
                    <?php else: foreach ($activeConv['messages'] as $msg):
                        $isMine = (int)$msg['sender_id'] === $uid;
                    ?>
                        <div style="display:flex;justify-content:<?php echo $isMine ? 'flex-end' : 'flex-start'; ?>;">
                            <div style="max-width:70%;padding:10px 14px;border-radius:14px;<?php echo $isMine ? 'background:var(--brand-500);color:#fff;border-bottom-right-radius:4px;' : 'background:#fff;color:var(--text);border:1px solid var(--border);border-bottom-left-radius:4px;'; ?>">
                                <div style="font-size:13.5px;line-height:1.4;"><?php echo e($msg['body']); ?></div>
                                <div style="font-size:10px;<?php echo $isMine ? 'color:rgba(255,255,255,.7);' : 'color:var(--text-mute);'; ?>margin-top:3px;text-align:right;"><?php echo e(date('M j, g:i a', strtotime($msg['created_at']))); ?></div>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
                <form method="post" action="" style="padding:12px 16px;border-top:1px solid var(--border);display:flex;gap:8px;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="conversation_id" value="<?php echo (int)$activeConv['id']; ?>">
                    <input type="text" name="body" placeholder="Type a message..." required style="flex:1;padding:10px 14px;border:1px solid var(--border);border-radius:22px;font-size:13.5px;outline:none;" autofocus>
                    <button type="submit" class="btn btn--primary" style="border-radius:22px;padding:10px 18px;">Send</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

<style>
@media (max-width: 700px) {
    .chat-layout { grid-template-columns: 1fr !important; height: auto !important; }
    .chat-layout > div:first-child { max-height: 200px; }
    .chat-layout > div:last-child { height: 500px; }
}
</style>
<script>
// Auto-scroll to bottom of chat
var chatBox = document.getElementById('chatMessages');
if (chatBox) chatBox.scrollTop = chatBox.scrollHeight;
</script>
<?php seller_page_end(); ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
