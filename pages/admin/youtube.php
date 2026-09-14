<?php
/** Admin-managed YouTube videos. Videos remain hosted by YouTube; the platform stores only the URL/metadata. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_icons.php';
require_once __DIR__ . '/../../includes/admin_ui.php';
require_once __DIR__ . '/../../includes/admin_log.php';
require_once __DIR__ . '/../../includes/youtube.php';
require_role('admin');

$pdo = db();
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'save') {
                $id = (int)($_POST['id'] ?? 0);
                $title = trim((string)($_POST['title'] ?? ''));
                $description = trim((string)($_POST['description'] ?? ''));
                $url = trim((string)($_POST['youtube_url'] ?? ''));
                $videoId = youtube_extract_id($url);
                $published = isset($_POST['is_published']) ? 1 : 0;
                $order = max(0, min(9999, (int)($_POST['display_order'] ?? 0)));

                if ($title === '' || mb_strlen($title) > 180) throw new RuntimeException('Please enter a valid video title.');
                if (!$videoId) throw new RuntimeException('Please enter a valid YouTube video URL.');

                if ($id > 0) {
                    $stmt = $pdo->prepare('UPDATE youtube_videos SET title=?, description=?, youtube_url=?, youtube_video_id=?, is_published=?, display_order=? WHERE id=?');
                    $stmt->execute([$title, $description !== '' ? $description : null, $url, $videoId, $published, $order, $id]);
                    admin_log('youtube', 'updated', 'Updated YouTube video #' . $id);
                    $notice = 'YouTube video updated successfully.';
                } else {
                    $stmt = $pdo->prepare('INSERT INTO youtube_videos (title,description,youtube_url,youtube_video_id,is_published,display_order,created_by) VALUES (?,?,?,?,?,?,?)');
                    $stmt->execute([$title, $description !== '' ? $description : null, $url, $videoId, $published, $order, (int)current_user()['id']]);
                    admin_log('youtube', 'created', 'Added YouTube video #' . (int)$pdo->lastInsertId());
                    $notice = 'YouTube video added successfully.';
                }
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    $stmt = $pdo->prepare('DELETE FROM youtube_videos WHERE id=?');
                    $stmt->execute([$id]);
                    admin_log('youtube', 'deleted', 'Deleted YouTube video #' . $id);
                    $notice = 'YouTube video deleted.';
                }
            } elseif ($action === 'toggle') {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $pdo->prepare('UPDATE youtube_videos SET is_published = 1 - is_published WHERE id=?');
                $stmt->execute([$id]);
                admin_log('youtube', 'visibility_changed', 'Changed YouTube video visibility #' . $id);
                $notice = 'Video visibility updated.';
            }
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to save the YouTube video. Please try again.';
        }
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$editVideo = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM youtube_videos WHERE id=?');
    $stmt->execute([$editId]);
    $editVideo = $stmt->fetch() ?: null;
}

$videos = $pdo->query('SELECT y.*, u.full_name creator_name FROM youtube_videos y LEFT JOIN users u ON u.id=y.created_by ORDER BY y.display_order ASC, y.created_at DESC')->fetchAll();
$publishedCount = (int)$pdo->query('SELECT COUNT(*) FROM youtube_videos WHERE is_published=1')->fetchColumn();

$pageTitle = 'YouTube Videos';
$activePage = 'admin';
$adminActivePage = 'youtube';
$extraCss = '<link rel="stylesheet" href="' . APP_URL . '/assets/css/admin.css">';
$adminPageTitle = 'YouTube Videos';
$adminPageSubtitle = 'Manage videos displayed beside the community section on the Home page.';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="a-shell" id="aShell">
<?php require __DIR__ . '/../../includes/admin_sidebar.php'; ?>
<div class="a-main">
<?php require __DIR__ . '/../../includes/admin_topbar.php'; ?>
<div class="a-content">

<div class="a-hero">
    <div><h2>YouTube Videos</h2><p class="a-hero__sub">Add a YouTube link and control what appears on the Home page.</p></div>
    <div class="a-hero__actions"><a class="a-btn" href="<?php echo APP_URL; ?>/" target="_blank" rel="noopener"><?php echo admin_icon('website'); ?><span>View website</span></a></div>
</div>

<?php if ($notice): ?><div class="a-alert a-alert--success"><?php echo e($notice); ?></div><?php endif; ?>
<?php if ($error): ?><div class="a-alert a-alert--danger"><?php echo e($error); ?></div><?php endif; ?>

<div class="a-kpis" style="grid-template-columns:repeat(2,minmax(0,1fr));">
    <div class="a-kpi"><div class="a-kpi__top"><span class="a-kpi__label">Published videos</span><span class="a-kpi__icon a-kpi__icon--green"><?php echo admin_icon('website'); ?></span></div><div class="a-kpi__value"><?php echo number_format($publishedCount); ?></div><div class="a-kpi__meta"><span class="a-kpi__hint">Visible on Home page</span></div></div>
    <div class="a-kpi"><div class="a-kpi__top"><span class="a-kpi__label">Total videos</span><span class="a-kpi__icon"><?php echo admin_icon('listings'); ?></span></div><div class="a-kpi__value"><?php echo number_format(count($videos)); ?></div><div class="a-kpi__meta"><span class="a-kpi__hint">YouTube links managed here</span></div></div>
</div>

<section class="a-panel">
    <div class="a-panel__head"><div><h3><?php echo $editVideo ? 'Edit YouTube video' : 'Add YouTube video'; ?></h3><p>Paste the normal YouTube watch URL. The video stays hosted on YouTube.</p></div></div>
    <form method="post" class="a-form">
        <?php echo csrf_field(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int)($editVideo['id'] ?? 0); ?>">
        <div class="a-form__row"><div class="a-field"><label>Video title</label><input class="a-input" name="title" maxlength="180" required value="<?php echo e($editVideo['title'] ?? ''); ?>" placeholder="Welcome to Isoko Ryacu"></div><div class="a-field"><label>YouTube URL</label><input class="a-input" name="youtube_url" type="url" maxlength="500" required value="<?php echo e($editVideo['youtube_url'] ?? ''); ?>" placeholder="https://www.youtube.com/watch?v=..."></div></div>
        <div class="a-form__row"><div class="a-field"><label>Description <span class="cell-mute">(optional)</span></label><textarea class="a-input" name="description" rows="3" maxlength="2000" placeholder="Short message shown with the video..."><?php echo e($editVideo['description'] ?? ''); ?></textarea></div><div class="a-field"><label>Display order</label><input class="a-input" name="display_order" type="number" min="0" max="9999" value="<?php echo (int)($editVideo['display_order'] ?? 0); ?>"><label class="a-switch" style="margin-top:12px"><input type="checkbox" name="is_published" value="1" <?php echo (!$editVideo || (int)$editVideo['is_published'] === 1) ? 'checked' : ''; ?>><span class="a-switch__track"></span><span class="a-switch__label">Publish on Home page</span></label></div></div>
        <div class="a-rowactions"><button class="a-btn a-btn--accent" type="submit"><?php echo $editVideo ? 'Save changes' : 'Add video'; ?></button><?php if ($editVideo): ?><a class="a-btn" href="<?php echo APP_URL; ?>/pages/admin/youtube.php">Cancel</a><?php endif; ?></div>
    </form>
</section>

<section class="a-panel"><div class="a-panel__head"><div><h3>Managed videos</h3><p>Published videos are shown in the reserved Home-page space.</p></div></div>
<div class="a-tablewrap"><table class="a-table"><thead><tr><th>Video</th><th>Status</th><th>Order</th><th>Added</th><th>Actions</th></tr></thead><tbody>
<?php if (!$videos): ?><tr><td colspan="5" class="cell-mute">No YouTube videos have been added yet.</td></tr><?php endif; ?>
<?php foreach ($videos as $v): ?><tr><td data-label="Video"><div class="cell-primary"><?php echo e($v['title']); ?></div><div class="cell-mute" style="font-size:11px;max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo e($v['youtube_url']); ?></div></td><td data-label="Status"><?php echo (int)$v['is_published'] === 1 ? '<span class="a-status a-status--active">Published</span>' : '<span class="a-status a-status--neutral">Hidden</span>'; ?></td><td data-label="Order"><?php echo (int)$v['display_order']; ?></td><td data-label="Added" class="cell-mute"><?php echo e(time_ago($v['created_at'])); ?></td><td data-label="Actions"><div class="a-rowactions"><a class="a-btn a-btn--sm" href="?edit=<?php echo (int)$v['id']; ?>">Edit</a><form method="post" style="display:contents"><?php echo csrf_field(); ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?php echo (int)$v['id']; ?>"><button class="a-btn a-btn--sm" type="submit"><?php echo (int)$v['is_published'] === 1 ? 'Hide' : 'Publish'; ?></button></form><form method="post" style="display:contents" onsubmit="return confirm('Delete this YouTube video?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$v['id']; ?>"><button class="a-btn a-btn--sm" type="submit">Delete</button></form></div></td></tr><?php endforeach; ?>
</tbody></table></div></section>

</div></div></div>
