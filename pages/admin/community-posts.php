<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_icons.php';
require_once __DIR__ . '/../../includes/admin_ui.php';
require_once __DIR__ . '/../../includes/admin_log.php';
require_role('admin');
$pdo = db(); $notice=''; $error='';
$types = ['announcement'=>'Announcement','event'=>'Event','notice'=>'Notice'];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!csrf_check()) $error='Security check failed. Please refresh and try again.';
    else try {
        $action=$_POST['action']??''; $id=(int)($_POST['id']??0);
        if ($action==='save') {
            $type=(string)($_POST['type']??'announcement'); $title=trim((string)($_POST['title']??''));
            $body=trim((string)($_POST['body']??'')); $date=trim((string)($_POST['event_date']??''));
            $location=trim((string)($_POST['location']??'')); $ctaLabel=trim((string)($_POST['cta_label']??''));
            $ctaUrl=trim((string)($_POST['cta_url']??'')); $published=isset($_POST['is_published'])?1:0;
            $order=max(0,min(9999,(int)($_POST['display_order']??0)));
            if (!isset($types[$type])) throw new RuntimeException('Choose a valid post type.');
            if ($title==='' || mb_strlen($title)>180) throw new RuntimeException('Please enter a valid title.');
            if (mb_strlen($body)>5000) throw new RuntimeException('The post body is too long.');
            $dateSql=null;
            if ($date!=='') { $ts=strtotime($date); if($ts===false) throw new RuntimeException('Enter a valid date and time.'); $dateSql=date('Y-m-d H:i:s',$ts); }
            if ($ctaUrl!=='' && (!filter_var($ctaUrl,FILTER_VALIDATE_URL) || !preg_match('#^https?://#i',$ctaUrl))) throw new RuntimeException('Button URL must be a valid http(s) URL.');
            if ($id>0) {
                $st=$pdo->prepare('UPDATE community_posts SET type=?,title=?,body=?,event_date=?,location=?,cta_label=?,cta_url=?,is_published=?,display_order=? WHERE id=?');
                $st->execute([$type,$title,$body!==''?$body:null,$dateSql,$location!==''?$location:null,$ctaLabel!==''?$ctaLabel:null,$ctaUrl!==''?$ctaUrl:null,$published,$order,$id]);
                admin_log('community_posts','updated','Updated community post #'.$id); $notice='Post updated successfully.';
            } else {
                $st=$pdo->prepare('INSERT INTO community_posts (type,title,body,event_date,location,cta_label,cta_url,is_published,display_order,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
                $st->execute([$type,$title,$body!==''?$body:null,$dateSql,$location!==''?$location:null,$ctaLabel!==''?$ctaLabel:null,$ctaUrl!==''?$ctaUrl:null,$published,$order,(int)current_user()['id']]);
                admin_log('community_posts','created','Added community post #'.$pdo->lastInsertId()); $notice='Post saved successfully.';
            }
        } elseif ($action==='toggle' && $id>0) {
            $st=$pdo->prepare('UPDATE community_posts SET is_published=1-is_published WHERE id=?'); $st->execute([$id]); admin_log('community_posts','visibility_changed','Changed community post visibility #'.$id); $notice='Post visibility updated.';
        } elseif ($action==='delete' && $id>0) {
            $st=$pdo->prepare('DELETE FROM community_posts WHERE id=?'); $st->execute([$id]); admin_log('community_posts','deleted','Deleted community post #'.$id); $notice='Post deleted.';
        }
    } catch (Throwable $e) { $error=$e instanceof RuntimeException?$e->getMessage():'Unable to save this post. Please try again.'; }
}
$editId=(int)($_GET['edit']??0); $edit=null;
if($editId){$st=$pdo->prepare('SELECT * FROM community_posts WHERE id=?');$st->execute([$editId]);$edit=$st->fetch()?:null;}
$posts=$pdo->query('SELECT p.*,u.full_name creator_name FROM community_posts p LEFT JOIN users u ON u.id=p.created_by ORDER BY p.display_order ASC, COALESCE(p.event_date,p.created_at) ASC, p.created_at DESC')->fetchAll();
$published=(int)$pdo->query('SELECT COUNT(*) FROM community_posts WHERE is_published=1')->fetchColumn();
$pageTitle='Events & Announcements'; $activePage='admin'; $adminActivePage='community-posts';
$extraCss='<link rel="stylesheet" href="'.asset_url('assets/css/admin.css').'">'; $adminPageTitle='Events & Announcements'; $adminPageSubtitle='Post events, announcements and notices that appear on the Home page.';
require_once __DIR__.'/../../includes/header.php';
?>
<div class="a-shell" id="aShell"><?php require __DIR__.'/../../includes/admin_sidebar.php'; ?><div class="a-main"><?php require __DIR__.'/../../includes/admin_topbar.php'; ?><div class="a-content">
<div class="a-hero"><div><h2>Events &amp; Announcements</h2><p class="a-hero__sub">Create real updates for the marketplace Home page.</p></div><div class="a-hero__actions"><a class="a-btn" href="<?php echo APP_URL; ?>/" target="_blank" rel="noopener"><?php echo admin_icon('website'); ?><span>View website</span></a></div></div>
<?php if($notice):?><div class="a-alert a-alert--success"><?php echo e($notice);?></div><?php endif;?><?php if($error):?><div class="a-alert a-alert--danger"><?php echo e($error);?></div><?php endif;?>
<div class="a-kpis" style="grid-template-columns:repeat(2,minmax(0,1fr));"><div class="a-kpi"><div class="a-kpi__top"><span class="a-kpi__label">Published posts</span><span class="a-kpi__icon a-kpi__icon--green"><?php echo admin_icon('website');?></span></div><div class="a-kpi__value"><?php echo number_format($published);?></div><div class="a-kpi__meta"><span class="a-kpi__hint">Visible on Home page</span></div></div><div class="a-kpi"><div class="a-kpi__top"><span class="a-kpi__label">Total posts</span><span class="a-kpi__icon"><?php echo admin_icon('listings');?></span></div><div class="a-kpi__value"><?php echo number_format(count($posts));?></div><div class="a-kpi__meta"><span class="a-kpi__hint">Events, announcements &amp; notices</span></div></div></div>
<section class="a-panel"><div class="a-panel__head"><div><h3><?php echo $edit?'Edit post':'Create a post';?></h3><p>Keep Home updates short, useful and easy to scan.</p></div></div>
<form method="post" class="a-form"><?php echo csrf_field();?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int)($edit['id']??0);?>">
<div class="a-form__row"><div class="a-field"><label>Post type</label><select class="a-input" name="type"><?php foreach($types as $k=>$v):?><option value="<?php echo $k;?>" <?php echo (($edit['type']??'announcement')===$k)?'selected':'';?>><?php echo $v;?></option><?php endforeach;?></select></div><div class="a-field"><label>Title</label><input class="a-input" name="title" maxlength="180" required value="<?php echo e($edit['title']??'');?>" placeholder="e.g. Kigali Market Day 2026"></div></div>
<div class="a-form__row">
<div class="a-field"><label>Message</label><textarea class="a-input" name="body" rows="4" maxlength="5000" placeholder="Write the announcement or event details..."><?php echo e($edit['body']??'');?></textarea></div>
<div class="a-field"><label>Event date &amp; time <span class="cell-mute">(optional)</span></label><input class="a-input" type="datetime-local" name="event_date" value="<?php echo !empty($edit['event_date'])?e(date('Y-m-d\\TH:i',strtotime($edit['event_date']))):'';?>"><label style="margin-top:12px">Location <span class="cell-mute">(optional)</span></label><input class="a-input" name="location" maxlength="180" value="<?php echo e($edit['location']??'');?>" placeholder="Kigali Convention Centre"></div>
</div>
<div class="a-form__row">
<div class="a-field"><label>Button label <span class="cell-mute">(optional)</span></label><input class="a-input" name="cta_label" maxlength="60" value="<?php echo e($edit['cta_label']??'');?>" placeholder="Learn more"></div>
<div class="a-field"><label>Button URL <span class="cell-mute">(optional)</span></label><input class="a-input" name="cta_url" type="url" maxlength="500" value="<?php echo e($edit['cta_url']??'');?>" placeholder="https://..."></div>
</div>
<div class="a-form__row"><div class="a-field"><label>Display order</label><input class="a-input" name="display_order" type="number" min="0" max="9999" value="<?php echo (int)($edit['display_order']??0);?>"></div><div class="a-field"><label class="a-switch"><input type="checkbox" name="is_published" value="1" <?php echo (!$edit||(int)$edit['is_published']===1)?'checked':'';?>><span class="a-switch__track"></span><span class="a-switch__label">Publish on Home page</span></label></div></div>
<div class="a-rowactions"><button class="a-btn a-btn--accent" type="submit"><?php echo $edit?'Save changes':'Publish post';?></button><?php if($edit):?><a class="a-btn" href="<?php echo APP_URL;?>/pages/admin/community-posts.php">Cancel</a><?php endif;?></div>
</form></section>
<section class="a-panel"><div class="a-panel__head"><div><h3>Managed posts</h3><p>Only published posts appear in the Home-page updates panel.</p></div></div>
<div class="a-tablewrap"><table class="a-table"><thead><tr><th>Post</th><th>Type</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead><tbody>
<?php if(!$posts):?><tr><td colspan="5" class="cell-mute">No community posts yet.</td></tr><?php endif;?>
<?php foreach($posts as $post):?>
<tr><td data-label="Post"><div class="cell-primary"><?php echo e($post['title']);?></div><div class="cell-mute"><?php echo e(excerpt($post['body']??'',90));?></div></td>
<td data-label="Type"><?php echo e($types[$post['type']]??ucfirst($post['type']));?></td>
<td data-label="Status"><?php echo (int)$post['is_published']===1?'<span class="a-status a-status--active">Published</span>':'<span class="a-status a-status--neutral">Hidden</span>';?></td>
<td data-label="Date" class="cell-mute"><?php echo !empty($post['event_date'])?e(date('M j, Y g:i A',strtotime($post['event_date']))):e(time_ago($post['created_at']));?></td>
<td data-label="Actions"><div class="a-rowactions"><a class="a-btn a-btn--sm" href="?edit=<?php echo (int)$post['id'];?>">Edit</a><form method="post" style="display:contents"><?php echo csrf_field();?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?php echo (int)$post['id'];?>"><button class="a-btn a-btn--sm" type="submit"><?php echo (int)$post['is_published']===1?'Hide':'Publish';?></button></form><form method="post" style="display:contents" onsubmit="return confirm('Delete this post?');"><?php echo csrf_field();?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$post['id'];?>"><button class="a-btn a-btn--sm" type="submit">Delete</button></form></div></td></tr>
<?php endforeach;?></tbody></table></div></section>
</div></div></div>
