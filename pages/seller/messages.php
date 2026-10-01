<?php
/**
 * pages/seller/messages.php — Real marketplace chat for sellers
 * --------------------------------------------------------------------
 * Two-panel layout:
 *   LEFT  — conversation list (search, unread badges, last message preview)
 *   RIGHT — active conversation (message history + send box)
 *
 * Uses the existing `conversations` + `messages` tables (from
 * marketplace_upgrade.sql). The seller only sees conversations where
 * they are user2_id (the seller). Authorization is enforced server-side
 * on every conversation access.
 *
 * Linked conversations:
 *   - Product conversations: listing_id is set, shows product thumbnail
 *   - Order conversations: order_id is set, shows order number + status
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/wallet.php';
require_once __DIR__ . '/../../includes/seller_sidebar.php';
require_once __DIR__ . '/../../includes/notification_service.php';
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
            // Authorization: seller must be user2_id in this conversation
            $stmt = $pdo->prepare('SELECT * FROM conversations WHERE id = ? AND user2_id = ?');
            $stmt->execute([$convId, $uid]);
            $conv = $stmt->fetch();
            if ($conv) {
                $pdo->prepare('INSERT INTO messages (conversation_id, sender_id, body) VALUES (?, ?, ?)')
                    ->execute([$convId, $uid, $body]);
                $pdo->prepare('UPDATE conversations SET last_message_at = NOW() WHERE id = ?')
                    ->execute([$convId]);
                // Notify the buyer in-app/email; SMS is intentionally reserved for critical events.
                notify_user(
                    (int)$conv['user1_id'],
                    'message',
                    'New message from seller',
                    mb_strlen($body) > 80 ? mb_substr($body, 0, 80) . '…' : $body,
                    '/pages/messages.php?conv=' . $convId,
                    'message_received',
                    ['in_app', 'email']
                );
            }
        }
    }
    redirect(APP_URL . '/pages/seller/messages.php?conv=' . $convId);
}

// --- Load active conversation (if specified) ---
$activeConv = null;
if (isset($_GET['conv'])) {
    $convId = (int)$_GET['conv'];
    $stmt = $pdo->prepare(
        "SELECT c.*,
                u1.full_name AS buyer_name, u1.id AS buyer_id, u1.avatar_path AS buyer_avatar,
                l.title AS listing_title, l.id AS listing_id, l.slug AS listing_slug, l.price AS listing_price, l.currency AS listing_currency,
                (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS listing_image,
                o.order_number, o.status AS order_status
           FROM conversations c
           INNER JOIN users u1 ON u1.id = c.user1_id
           LEFT JOIN listings l ON l.id = c.listing_id
           LEFT JOIN orders o ON o.id = c.order_id
          WHERE c.id = ? AND c.user2_id = ?"
    );
    $stmt->execute([$convId, $uid]);
    $activeConv = $stmt->fetch();
    if ($activeConv) {
        // Mark messages from the buyer as read
        $pdo->prepare(
            'UPDATE messages SET is_read = 1, read_at = NOW()
             WHERE conversation_id = ? AND sender_id != ? AND is_read = 0'
        )->execute([$convId, $uid]);

        // Load messages (last 50 — pagination-ready)
        $stmt = $pdo->prepare(
            'SELECT * FROM messages WHERE conversation_id = ? ORDER BY created_at ASC LIMIT 50'
        );
        $stmt->execute([$convId]);
        $activeConv['messages'] = $stmt->fetchAll();
    }
}

// --- Load conversation list for the seller ---
$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $stmt = $pdo->prepare(
        "SELECT c.*,
                u1.full_name AS buyer_name, u1.id AS buyer_id,
                l.title AS listing_title,
                (SELECT body FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) AS last_message,
                (SELECT created_at FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) AS last_msg_at,
                (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id AND sender_id = c.user1_id AND is_read = 0) AS unread
           FROM conversations c
           INNER JOIN users u1 ON u1.id = c.user1_id
           LEFT JOIN listings l ON l.id = c.listing_id
          WHERE c.user2_id = ? AND (u1.full_name LIKE ? OR l.title LIKE ?)
          ORDER BY c.last_message_at DESC"
    );
    $like = '%' . $search . '%';
    $stmt->execute([$uid, $like, $like]);
} else {
    $stmt = $pdo->prepare(
        "SELECT c.*,
                u1.full_name AS buyer_name, u1.id AS buyer_id,
                l.title AS listing_title,
                (SELECT body FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) AS last_message,
                (SELECT created_at FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) AS last_msg_at,
                (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id AND sender_id = c.user1_id AND is_read = 0) AS unread
           FROM conversations c
           INNER JOIN users u1 ON u1.id = c.user1_id
           LEFT JOIN listings l ON l.id = c.listing_id
          WHERE c.user2_id = ?
          ORDER BY c.last_message_at DESC"
    );
    $stmt->execute([$uid]);
}
$conversations = $stmt->fetchAll();

// Total unread for the header badge
$totalUnread = 0;
foreach ($conversations as $c) $totalUnread += (int)$c['unread'];

$pageTitle = 'Seller Messages';
$activePage = 'dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>

<?php seller_page_start('Messages', $uid, $pdo); ?>

    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
        <div>
            <h1 style="font-size:22px;font-weight:800;margin:0;letter-spacing:-.02em;">Messages</h1>
            <p style="font-size:13px;color:var(--text-mute);margin:4px 0 0;">
                <?php echo $totalUnread > 0 ? "<strong style=\"color:var(--brand-600);\">{$totalUnread} unread</strong> conversation(s)" : 'You\'re all caught up!'; ?>
            </p>
        </div>
    </div>

    <div class="chat-shell" style="display:grid;grid-template-columns:320px 1fr;gap:16px;height:600px;background:var(--bg-card);border:1px solid var(--border);border-radius:16px;overflow:hidden;">

        <!-- LEFT: Conversation list -->
        <div style="border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden;">
            <!-- Search -->
            <div style="padding:12px;border-bottom:1px solid var(--border);">
                <form method="get" action="" style="position:relative;">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-mute);"><circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/></svg>
                    <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Search conversations..." style="width:100%;padding:9px 12px 9px 36px;border:1px solid var(--border);border-radius:20px;font-size:13px;outline:none;background:var(--bg-soft);">
                </form>
            </div>

            <!-- List -->
            <div style="flex:1;overflow-y:auto;" id="convList">
                <?php if (empty($conversations)): ?>
                    <div style="padding:32px 16px;text-align:center;color:var(--text-mute);">
                        <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.3" style="opacity:.4;margin-bottom:10px;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        <p style="font-size:13px;margin:0;">No conversations yet.</p>
                        <p style="font-size:12px;margin:6px 0 0;">When buyers message you about your listings, they'll appear here.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($conversations as $c): ?>
                        <a href="<?php echo APP_URL; ?>/pages/seller/messages.php?conv=<?php echo (int)$c['id']; ?>"
                           style="display:flex;gap:11px;padding:13px 14px;border-bottom:1px solid var(--bg-soft);text-decoration:none;color:inherit;transition:background 150ms;<?php echo $activeConv && (int)$activeConv['id']===(int)$c['id'] ? 'background:var(--brand-50);' : ''; ?>"
                           onmouseover="this.style.background='<?php echo $activeConv && (int)$activeConv['id']===(int)$c['id'] ? 'var(--brand-50)' : 'var(--bg-soft)'; ?>'"
                           onmouseout="this.style.background='<?php echo $activeConv && (int)$activeConv['id']===(int)$c['id'] ? 'var(--brand-50)' : 'transparent'; ?>'">

                            <span style="width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--brand-500),var(--brand-700));color:#fff;display:grid;place-items:center;font-weight:700;font-size:14px;flex:0 0 38px;">
                                <?php echo e(strtoupper(substr($c['buyer_name'], 0, 1))); ?>
                            </span>
                            <div style="flex:1;min-width:0;">
                                <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;">
                                    <strong style="font-size:13.5px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo e($c['buyer_name']); ?></strong>
                                    <?php if ($c['last_msg_at']): ?>
                                        <small style="font-size:10.5px;color:var(--text-mute);white-space:nowrap;flex:0 0 auto;"><?php echo e(date('M j', strtotime($c['last_msg_at']))); ?></small>
                                    <?php endif; ?>
                                </div>
                                <?php if ($c['listing_title']): ?>
                                    <div style="font-size:11px;color:var(--brand-600);font-weight:600;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">📦 <?php echo e($c['listing_title']); ?></div>
                                <?php endif; ?>
                                <div style="font-size:12px;color:var(--text-mute);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                    <?php echo e($c['last_message'] ?? 'No messages yet'); ?>
                                </div>
                            </div>
                            <?php if ((int)$c['unread'] > 0): ?>
                                <span style="background:var(--accent-500);color:#fff;font-size:10px;font-weight:800;padding:2px 7px;border-radius:20px;flex:0 0 auto;align-self:center;"><?php echo (int)$c['unread']; ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT: Active conversation -->
        <div style="display:flex;flex-direction:column;overflow:hidden;">
            <?php if (!$activeConv): ?>
                <div style="flex:1;display:grid;place-items:center;color:var(--text-mute);text-align:center;padding:24px;">
                    <div>
                        <svg viewBox="0 0 24 24" width="56" height="56" fill="none" stroke="currentColor" stroke-width="1.2" style="opacity:.3;margin-bottom:14px;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        <h3 style="font-size:16px;margin:0 0 6px;color:var(--text-soft);">Select a conversation</h3>
                        <p style="font-size:13px;margin:0;">Choose a conversation from the left to start chatting with buyers.</p>
                    </div>
                </div>
            <?php else: ?>
                <!-- Chat header -->
                <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;background:var(--bg-card);">
                    <span style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--brand-500),var(--brand-700));color:#fff;display:grid;place-items:center;font-weight:700;font-size:15px;">
                        <?php echo e(strtoupper(substr($activeConv['buyer_name'], 0, 1))); ?>
                    </span>
                    <div style="flex:1;min-width:0;">
                        <strong style="font-size:14.5px;color:var(--text);"><?php echo e($activeConv['buyer_name']); ?></strong>
                        <?php if ($activeConv['listing_title']): ?>
                            <div style="font-size:12px;color:var(--text-mute);">About: <a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$activeConv['listing_id']; ?>" style="color:var(--brand-600);text-decoration:none;font-weight:600;"><?php echo e($activeConv['listing_title']); ?></a></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($activeConv['order_number']): ?>
                        <a href="<?php echo APP_URL; ?>/pages/orders.php?id=<?php echo (int)$activeConv['order_id']; ?>" class="udash__pill udash__pill--brand" style="text-decoration:none;">
                            Order <?php echo e($activeConv['order_number']); ?>
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Product context card (if listing) -->
                <?php if ($activeConv['listing_title'] && $activeConv['listing_image']): ?>
                <div style="padding:10px 18px;border-bottom:1px solid var(--bg-soft);background:var(--bg-soft);display:flex;gap:10px;align-items:center;">
                    <img src="<?php echo e(image_or_default($activeConv['listing_image'], 'assets/images/placeholders/default.svg')); ?>" alt="" style="width:44px;height:44px;border-radius:8px;object-fit:cover;flex:0 0 44px;">
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:12.5px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo e($activeConv['listing_title']); ?></div>
                        <div style="font-size:12px;color:var(--brand-600);font-weight:700;"><?php echo e(format_price((float)$activeConv['listing_price'], $activeConv['listing_currency'])); ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Messages -->
                <div style="flex:1;overflow-y:auto;padding:16px 18px;display:flex;flex-direction:column;gap:8px;background:var(--bg-soft);" id="chatMessages">
                    <?php if (empty($activeConv['messages'])): ?>
                        <div style="text-align:center;color:var(--text-mute);font-size:13px;padding:20px;">No messages yet. Say hello! 👋</div>
                    <?php else: foreach ($activeConv['messages'] as $msg):
                        $isMine = (int)$msg['sender_id'] === $uid;
                    ?>
                        <div style="display:flex;justify-content:<?php echo $isMine ? 'flex-end' : 'flex-start'; ?>;">
                            <div style="max-width:72%;padding:9px 14px;border-radius:14px;<?php echo $isMine ? 'background:var(--brand-500);color:#fff;border-bottom-right-radius:4px;' : 'background:#fff;color:var(--text);border:1px solid var(--border);border-bottom-left-radius:4px;'; ?>">
                                <div style="font-size:13.5px;line-height:1.45;"><?php echo e($msg['body']); ?></div>
                                <div style="font-size:10px;<?php echo $isMine ? 'color:rgba(255,255,255,.7);' : 'color:var(--text-mute);'; ?>margin-top:3px;text-align:right;display:flex;align-items:center;gap:4px;justify-content:flex-end;">
                                    <?php echo e(date('g:i a', strtotime($msg['created_at']))); ?>
                                    <?php if ($isMine && $msg['is_read']): ?>
                                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>

                <!-- Send box -->
                <form method="post" action="" style="padding:12px 18px;border-top:1px solid var(--border);display:flex;gap:8px;background:var(--bg-card);">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="conversation_id" value="<?php echo (int)$activeConv['id']; ?>">
                    <input type="text" name="body" placeholder="Type your reply..." required style="flex:1;padding:11px 16px;border:1px solid var(--border);border-radius:22px;font-size:13.5px;outline:none;background:var(--bg-soft);" autofocus>
                    <button type="submit" class="btn btn--primary" style="border-radius:22px;padding:11px 20px;">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
                        Send
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
@media (max-width: 760px) {
    .chat-shell { grid-template-columns: 1fr !important; height: auto !important; }
    .chat-shell > div:first-child { max-height: 240px; border-right: 0 !important; border-bottom: 1px solid var(--border); }
    .chat-shell > div:last-child { height: 500px; }
}
</style>
<script>
// Auto-scroll to bottom of chat
var chatBox = document.getElementById('chatMessages');
if (chatBox) chatBox.scrollTop = chatBox.scrollHeight;
</script>
<?php seller_page_end(); ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
