<?php
/**
 * pages/admin/messages.php
 * --------------------------------------------------------------------
 * IsokoRyacu admin message center (Phase 5).
 *
 * The marketplace's real messaging system is `contact_requests`
 * (messages buyers/guests send about listings — the same system the
 * seller area and listing-details page use). This page is the admin's
 * polished inbox on top of that existing architecture:
 *
 *   - Inbox with read / unread visual states + unread badge
 *   - Search by sender, email, message text or listing title
 *   - Filter chips: All / Unread / Read
 *   - Open a message -> full detail (sender, contact info, related
 *     listing, dates, complete body) in a professional modal
 *   - Mark as read / unread, Mark all as read, Delete (with confirm)
 *   - KPI strip with real counts (total, unread, today, this week)
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_icons.php';
require_once __DIR__ . '/../../includes/admin_ui.php';
require_role('admin');

$pdo = db();

/* ---------- Stats (real counts) ---------- */
$statTotal  = (int) $pdo->query("SELECT COUNT(*) FROM contact_requests")->fetchColumn();
$statUnread = (int) $pdo->query("SELECT COUNT(*) FROM contact_requests WHERE is_read = 0")->fetchColumn();
$statRead   = $statTotal - $statUnread;
$statToday  = (int) $pdo->query("SELECT COUNT(*) FROM contact_requests WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$statWeek   = (int) $pdo->query("SELECT COUNT(*) FROM contact_requests WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();

/* ---------- Search + read filter ---------- */
$q       = trim((string) ($_GET['q'] ?? ''));
$readF   = (string) ($_GET['filter'] ?? '');
if (!in_array($readF, ['unread', 'read'], true)) $readF = '';
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;

$where  = [];
$params = [];
if ($readF === 'unread') { $where[] = 'c.is_read = 0'; }
if ($readF === 'read')   { $where[] = 'c.is_read = 1'; }
if ($q !== '') {
    $where[] = '(c.name LIKE ? OR c.email LIKE ? OR c.message LIKE ? OR l.title LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT COUNT(*)
    FROM contact_requests c INNER JOIN listings l ON l.id = c.listing_id" . $whereSql);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$pages = (int) ceil($total / $perPage);
if ($page > $pages && $pages > 0) $page = $pages;
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT c.id, c.name, c.email, c.phone, c.message, c.is_read, c.created_at,
        l.id AS listing_id, l.title AS listing_title, l.price, l.currency,
        seller.full_name AS seller_name
    FROM contact_requests c
    INNER JOIN listings l ON l.id = c.listing_id
    INNER JOIN users seller ON seller.id = l.seller_id" . $whereSql . "
    ORDER BY c.is_read ASC, c.created_at DESC
    LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$messages = $stmt->fetchAll();

$baseUrl = APP_URL . '/pages/admin/messages.php';
$keep = array_filter(['q' => $q !== '' ? $q : null, 'filter' => $readF ?: null], static fn ($v) => $v !== null);

/* ---------- Page setup ---------- */
$pageTitle         = t('admin.nav_messages');
$activePage        = 'admin';
$adminActivePage   = 'messages';
$extraCss          = '<link rel="stylesheet" href="' . APP_URL . '/assets/css/admin.css">';
$adminPageTitle    = t('admin.nav_messages');
$adminPageSubtitle = t('admin.messages_sub');
require_once __DIR__ . '/../../includes/header.php';

/** Render one message row (used for both the list and the modal trigger). */
function admin_message_row(array $m): string
{
    $id = (int) $m['id'];
    $unread = (int) $m['is_read'] === 0;
    ob_start(); ?>
    <div class="a-msg <?php echo $unread ? 'a-msg--unread' : ''; ?>">
        <span class="a-msg__avatar"><?php echo e(strtoupper(substr($m['name'], 0, 1))); ?></span>
        <div class="a-msg__body">
            <div class="a-msg__top">
                <strong><?php echo e($m['name']); ?></strong>
                <?php if ($unread): ?><span class="a-status a-status--unread"><?php echo e(t('admin.msg_unread')); ?></span><?php endif; ?>
                <time><?php echo e(date('M j, Y · H:i', strtotime($m['created_at']))); ?> (<?php echo e(time_ago($m['created_at'])); ?>)</time>
            </div>
            <p class="a-msg__subject"><?php echo e(t('admin.about_listing')); ?>: <a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int) $m['listing_id']; ?>"><?php echo e($m['listing_title']); ?></a></p>
            <p class="a-msg__preview"><?php echo e($m['message']); ?></p>
        </div>
        <div class="a-msg__actions">
            <button type="button" class="a-btn a-btn--sm" title="<?php echo e(t('admin.msg_open')); ?>"
                    onclick="adminOpenMessage(<?php echo $id; ?>)"><?php echo admin_icon('eye'); ?></button>
            <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php" style="display:contents;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="<?php echo $unread ? 'message_read' : 'message_unread'; ?>">
                <input type="hidden" name="message_id" value="<?php echo $id; ?>">
                <button class="a-btn a-btn--sm" type="submit" title="<?php echo $unread ? e(t('admin.msg_mark_read')) : e(t('admin.msg_mark_unread')); ?>">
                    <?php echo $unread ? admin_icon('check') : admin_icon('refresh'); ?>
                </button>
            </form>
            <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php" style="display:contents;"
                  data-confirm="<?php echo e(t('admin.msg_delete_confirm')); ?>"
                  data-confirm-title="<?php echo e(t('admin.msg_delete_title')); ?>"
                  data-confirm-label="<?php echo e(t('buttons.delete')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="message_delete">
                <input type="hidden" name="message_id" value="<?php echo $id; ?>">
                <button class="a-btn a-btn--danger a-btn--sm" type="submit" title="<?php echo e(t('buttons.delete')); ?>"><?php echo admin_icon('trash'); ?></button>
            </form>
        </div>
    </div>
    <?php return (string) ob_get_clean();
}
?>
<div class="a-shell" id="aShell">
<?php require __DIR__ . '/../../includes/admin_sidebar.php'; ?>
<div class="a-main">
<?php require __DIR__ . '/../../includes/admin_topbar.php'; ?>
<div class="a-content">

<div class="a-hero">
    <div>
        <h2><?php echo e(t('admin.msg_center_title')); ?></h2>
        <p class="a-hero__sub"><?php echo e(t('admin.messages_sub')); ?></p>
    </div>
    <div class="a-hero__actions">
        <?php if ($statUnread > 0): ?>
        <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="message_read_all">
            <button class="a-btn a-btn--primary" type="submit"><?php echo admin_icon('check'); ?><span><?php echo e(t('admin.msg_mark_all_read')); ?> (<?php echo (int) $statUnread; ?>)</span></button>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- KPI strip -->
<div class="a-kpis">
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label"><?php echo e(t('admin.msg_total')); ?></span><span class="a-kpi__icon"><?php echo admin_icon('inbox'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($statTotal); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo e(t('admin.msg_total_sub')); ?></span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label"><?php echo e(t('admin.msg_unread')); ?></span><span class="a-kpi__icon a-kpi__icon--amber"><?php echo admin_icon('mail'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($statUnread); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo e(t('admin.needs_attention')); ?></span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label"><?php echo e(t('admin.msg_today')); ?></span><span class="a-kpi__icon a-kpi__icon--green"><?php echo admin_icon('calendar'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($statToday); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo e(t('admin.msg_today_sub')); ?></span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label"><?php echo e(t('admin.msg_week')); ?></span><span class="a-kpi__icon a-kpi__icon--blue"><?php echo admin_icon('clock'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($statWeek); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo e(t('admin.msg_week_sub')); ?></span></div>
    </div>
</div>

<!-- Inbox -->
<section class="a-panel">
    <div class="a-panel__head">
        <div>
            <h3><?php echo e(t('admin.msg_inbox')); ?></h3>
            <p><?php echo number_format($total); ?> <?php echo e(t('admin.messages_count')); ?><?php echo $q !== '' ? ' · "' . e($q) . '"' : ''; ?></p>
        </div>
        <div class="a-panel__tools">
            <form class="a-search" style="max-width:300px; margin:0;" method="get" action="<?php echo $baseUrl; ?>" role="search">
                <?php echo admin_icon('search'); ?>
                <?php if ($readF): ?><input type="hidden" name="filter" value="<?php echo e($readF); ?>"><?php endif; ?>
                <input type="search" name="q" value="<?php echo e($q); ?>" placeholder="<?php echo e(t('admin.search_messages_placeholder')); ?>">
            </form>
            <div class="a-filters">
                <a class="a-chip <?php echo $readF === '' ? 'is-active' : ''; ?>" href="<?php echo $baseUrl; ?><?php echo $q ? '?q=' . urlencode($q) : ''; ?>"><?php echo e(t('search.all')); ?> <b><?php echo number_format($statTotal); ?></b></a>
                <a class="a-chip <?php echo $readF === 'unread' ? 'is-active' : ''; ?>" href="<?php echo $baseUrl; ?>?<?php echo http_build_query(array_filter(['filter' => 'unread', 'q' => $q ?: null])); ?>"><?php echo e(t('admin.msg_unread')); ?> <b><?php echo number_format($statUnread); ?></b></a>
                <a class="a-chip <?php echo $readF === 'read' ? 'is-active' : ''; ?>" href="<?php echo $baseUrl; ?>?<?php echo http_build_query(array_filter(['filter' => 'read', 'q' => $q ?: null])); ?>"><?php echo e(t('admin.msg_read')); ?> <b><?php echo number_format($statRead); ?></b></a>
            </div>
        </div>
    </div>

    <?php if (!$messages): ?>
        <?php echo admin_empty_state('inbox', $q !== '' ? t('common.no_results') : t('admin.empty_no_messages'), t('admin.empty_no_messages_sub')); ?>
    <?php else: ?>
        <?php foreach ($messages as $m) echo admin_message_row($m); ?>
        <?php echo admin_pagination($total, $perPage, $page, $baseUrl, $keep); ?>
    <?php endif; ?>
</section>

</div><!-- /a-content -->

<!-- ============ Message detail modal (server-rendered, JS toggles) ============ -->
<div class="a-modal" id="aMsgModal" role="dialog" aria-modal="true">
    <div class="a-modal__backdrop" onclick="adminCloseMessage()"></div>
    <div class="a-modal__box" style="width:min(560px,100%);">
        <div class="a-modal__head">
            <span class="a-modal__icon a-modal__icon--teal"><?php echo admin_icon('mail'); ?></span>
            <div style="min-width:0;">
                <div class="a-modal__title" id="aMsgTitle">—</div>
                <p class="a-modal__sub" id="aMsgTime">—</p>
            </div>
            <button type="button" class="a-modal__close" onclick="adminCloseMessage()" aria-label="<?php echo e(t('common.dismiss')); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 6 6 18M6 6l12 12" stroke-linecap="round"/></svg>
            </button>
        </div>
        <div class="a-modal__body">
            <div class="a-msgdetail__meta">
                <span class="a-status a-status--unread" id="aMsgState"><?php echo e(t('admin.msg_unread')); ?></span>
                <span class="a-status a-status--neutral" id="aMsgListingBadge">—</span>
            </div>
            <dl class="a-msgdetail__kv">
                <dt><?php echo e(t('admin.msg_from')); ?></dt><dd id="aMsgFrom">—</dd>
                <dt><?php echo e(t('admin.col_email')); ?></dt><dd id="aMsgEmail">—</dd>
                <dt><?php echo e(t('admin.col_phone')); ?></dt><dd id="aMsgPhone">—</dd>
                <dt><?php echo e(t('admin.msg_to_seller')); ?></dt><dd id="aMsgSeller">—</dd>
                <dt><?php echo e(t('common.listing')); ?></dt><dd id="aMsgListing">—</dd>
            </dl>
            <div class="a-msgdetail__body" id="aMsgBody" style="margin-top:14px;">—</div>
            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:16px;" id="aMsgActions"></div>
        </div>
    </div>
</div>

<script>
/* Message detail modal — data injected by PHP (real rows only). */
window.ADMIN_MESSAGES = <?php echo json_encode(array_map(static function (array $m): array {
    return [
        'id'       => (int) $m['id'],
        'name'     => $m['name'],
        'email'    => $m['email'],
        'phone'    => $m['phone'] ?: '',
        'message'  => $m['message'],
        'unread'   => (int) $m['is_read'] === 0,
        'time'     => date('M j, Y · H:i', strtotime($m['created_at'])),
        'ago'      => time_ago($m['created_at']),
        'listing'  => $m['listing_title'],
        'listingUrl' => APP_URL . '/pages/listing-details.php?id=' . (int) $m['listing_id'],
        'seller'   => $m['seller_name'],
        'price'    => format_price($m['price'], $m['currency']),
    ];
}, $messages), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

var msgModal = document.getElementById('aMsgModal');
function adminOpenMessage(id) {
    var m = (window.ADMIN_MESSAGES || []).find(function (x) { return x.id === id; });
    if (!m) return;
    document.getElementById('aMsgTitle').textContent  = m.name;
    document.getElementById('aMsgTime').textContent   = m.time + ' (' + m.ago + ')';
    document.getElementById('aMsgFrom').textContent   = m.name;
    document.getElementById('aMsgEmail').textContent  = m.email;
    document.getElementById('aMsgPhone').textContent  = m.phone || '—';
    document.getElementById('aMsgSeller').textContent = m.seller;
    document.getElementById('aMsgBody').textContent   = m.message;

    var state = document.getElementById('aMsgState');
    state.textContent = m.unread ? <?php echo json_encode(t('admin.msg_unread')); ?> : <?php echo json_encode(t('admin.msg_read')); ?>;
    state.className = 'a-status ' + (m.unread ? 'a-status--unread' : 'a-status--read');

    var lb = document.getElementById('aMsgListingBadge');
    lb.innerHTML = '';
    var a = document.createElement('a');
    a.href = m.listingUrl;
    a.textContent = m.listing + ' — ' + m.price;
    a.style.cssText = 'color:inherit;font-weight:700;text-decoration:none;';
    lb.className = 'a-status a-status--sell';
    lb.appendChild(a);
    document.getElementById('aMsgListing').textContent = m.listing + ' (' + m.price + ')';

    /* actions: mark read/unread + delete (real CSRF-guarded forms) */
    var csrf = document.querySelector('meta[name="csrf-token"]').content;
    var act  = document.getElementById('aMsgActions');
    act.innerHTML = '';
    if (m.unread) {
        act.appendChild(postForm('message_read', m.id, <?php echo json_encode(t('admin.msg_mark_read')); ?>, 'a-btn a-btn--primary a-btn--sm'));
    } else {
        act.appendChild(postForm('message_unread', m.id, <?php echo json_encode(t('admin.msg_mark_unread')); ?>, 'a-btn a-btn--sm'));
    }
    act.appendChild(postForm('message_delete', m.id, <?php echo json_encode(t('buttons.delete')); ?>, 'a-btn a-btn--danger a-btn--sm', true));

    msgModal.classList.add('is-open');
    document.body.style.overflow = 'hidden';
}
function adminCloseMessage() {
    msgModal.classList.remove('is-open');
    document.body.style.overflow = '';
}
function postForm(action, id, label, cls, confirmIt) {
    var f = document.createElement('form');
    f.method = 'post';
    f.action = <?php echo json_encode(APP_URL . '/api/v1/admin-action/index.php'); ?>;
    if (confirmIt) {
        f.setAttribute('data-confirm', <?php echo json_encode(t('admin.msg_delete_confirm')); ?>);
        f.setAttribute('data-confirm-title', <?php echo json_encode(t('admin.msg_delete_title')); ?>);
        f.setAttribute('data-confirm-label', <?php echo json_encode(t('buttons.delete')); ?>);
    }
    [['action', action], ['message_id', id], ['_csrf', csrfToken()]].forEach(function (p) {
        var i = document.createElement('input');
        i.type = 'hidden'; i.name = p[0]; i.value = p[1];
        f.appendChild(i);
    });
    var b = document.createElement('button');
    b.type = 'submit'; b.className = cls; b.textContent = label;
    f.appendChild(b);
    return f;
}
function csrfToken() { return document.querySelector('meta[name="csrf-token"]').content; }
document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') adminCloseMessage();
});
</script>

<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>
