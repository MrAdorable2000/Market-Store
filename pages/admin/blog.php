<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_icons.php';
require_once __DIR__ . '/../../includes/admin_log.php';
require_role('admin');

$pdo=db(); $notice=''; $error='';
function blog_cover_url(?string $path): string {
    if ($path && preg_match('#^assets/uploads/[A-Za-z0-9._/-]+$#', $path) && is_file(__DIR__.'/../../'.$path)) return APP_URL.'/'.$path;
    return real_image('blog-default','blog-default');
}
function blog_slug(string $title): string { $s=trim(preg_replace('/[^a-z0-9]+/i','-',strtolower($title)),'-'); return $s ?: 'article'; }

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_check()) $error='Security check failed. Please refresh and try again.';
    else try{
        $action=$_POST['action']??''; $id=(int)($_POST['id']??0);
        if($action==='save'){
            $title=trim((string)($_POST['title']??'')); $category=trim((string)($_POST['category']??''));
            $excerpt=trim((string)($_POST['excerpt']??'')); $body=trim((string)($_POST['body']??''));
            $status=in_array($_POST['status']??'draft',['draft','published','archived'],true)?$_POST['status']:'draft';
            if($title==='' || mb_strlen($title)>220) throw new RuntimeException('Enter a valid article title.');
            if($body==='') throw new RuntimeException('Article content cannot be empty.');
            if(mb_strlen($excerpt)>400) throw new RuntimeException('Excerpt is too long.');
            $slug=blog_slug($title); $base=$slug; $n=2;
            while(true){$q=$pdo->prepare('SELECT id FROM blog_posts WHERE slug=? AND id<>? LIMIT 1');$q->execute([$slug,$id]);if(!$q->fetch())break;$slug=$base.'-'.$n++;}
            $cover=null;
            if(!empty($_FILES['cover']['name'])){
                $uploadDir=__DIR__.'/../../assets/uploads'; if(!is_dir($uploadDir) && !@mkdir($uploadDir,0775,true)) throw new RuntimeException('The upload folder is not writable. Check assets/uploads permissions.');
                $_FILES['cover']['name']=[$_FILES['cover']['name']]; $_FILES['cover']['type']=[$_FILES['cover']['type']]; $_FILES['cover']['tmp_name']=[$_FILES['cover']['tmp_name']]; $_FILES['cover']['error']=[$_FILES['cover']['error']]; $_FILES['cover']['size']=[$_FILES['cover']['size']];
                $up=handle_image_upload('cover','blog_'.date('YmdHis'));
                if(isset($up['error'])) throw new RuntimeException($up['error']);
                $cover=$up[0]??null;
            }
            $publishedAt=$status==='published'?date('Y-m-d H:i:s'):null;
            if($id){
                $old=$pdo->prepare('SELECT cover_path FROM blog_posts WHERE id=?');$old->execute([$id]);$old=$old->fetch();
                if(!$cover) $cover=$old['cover_path']??null;
                $st=$pdo->prepare('UPDATE blog_posts SET title=?,slug=?,excerpt=?,body=?,cover_path=?,category=?,status=?,published_at=CASE WHEN ?="published" THEN COALESCE(published_at, NOW()) ELSE NULL END WHERE id=?');
                $st->execute([$title,$slug,$excerpt!==''?$excerpt:null,$body,$cover,$category!==''?$category:null,$status,$status,$id]); admin_log('blog','updated','Updated article #'.$id,'success'); $notice='Article updated successfully.';
            } else {
                $st=$pdo->prepare('INSERT INTO blog_posts(author_id,title,slug,excerpt,body,cover_path,category,status,published_at) VALUES(?,?,?,?,?,?,?,?,?)');
                $st->execute([(int)current_user()['id'],$title,$slug,$excerpt!==''?$excerpt:null,$body,$cover,$category!==''?$category:null,$status,$publishedAt]); admin_log('blog','created','Created article #'.$pdo->lastInsertId(),'success'); $notice=$status==='published'?'Article published successfully.':'Draft saved successfully.';
            }
        } elseif($action==='toggle' && $id){$pdo->prepare("UPDATE blog_posts SET status=CASE WHEN status='published' THEN 'draft' ELSE 'published' END, published_at=CASE WHEN status='published' THEN NULL ELSE COALESCE(published_at,NOW()) END WHERE id=?")->execute([$id]);admin_log('blog','visibility_changed','Toggled article #'.$id,'success');$notice='Article visibility updated.';
        } elseif($action==='delete' && $id){$st=$pdo->prepare('DELETE FROM blog_posts WHERE id=?');$st->execute([$id]);admin_log('blog','deleted','Deleted article #'.$id,'success');$notice='Article deleted permanently.';}
    }catch(Throwable $e){$error=$e instanceof RuntimeException?$e->getMessage():'Unable to save the article. Please try again.';}
}
$editId=(int)($_GET['edit']??0);$edit=null;if($editId){$st=$pdo->prepare('SELECT * FROM blog_posts WHERE id=?');$st->execute([$editId]);$edit=$st->fetch()?:null;}
$posts=$pdo->query('SELECT p.*,u.full_name author_name FROM blog_posts p LEFT JOIN users u ON u.id=p.author_id ORDER BY p.created_at DESC')->fetchAll();
$published=(int)$pdo->query("SELECT COUNT(*) FROM blog_posts WHERE status='published'")->fetchColumn();
$drafts=(int)$pdo->query("SELECT COUNT(*) FROM blog_posts WHERE status='draft'")->fetchColumn();
$pageTitle='Blog Management';$adminActivePage='blog';$extraCss='<link rel="stylesheet" href="'.APP_URL.'/assets/css/admin.css">';$adminPageTitle='Blog Management';$adminPageSubtitle='Create polished marketplace guides, stories and updates.';
require_once __DIR__.'/../../includes/header.php';
?>
<div class="a-shell" id="aShell"><?php require __DIR__.'/../../includes/admin_sidebar.php';?><div class="a-main"><?php require __DIR__.'/../../includes/admin_topbar.php';?><div class="a-content">
<div class="a-hero"><div><h2>Blog Management</h2><p class="a-hero__sub">Publish useful, trustworthy content that makes IsokoRyacu feel active and professional.</p></div><div class="a-hero__actions"><a class="a-btn" href="<?php echo APP_URL;?>/pages/blog/index.php" target="_blank" rel="noopener"><?php echo admin_icon('website');?><span>View blog</span></a></div></div>
<?php if($notice):?><div class="a-alert a-alert--success"><?php echo e($notice);?></div><?php endif;?><?php if($error):?><div class="a-alert a-alert--danger"><?php echo e($error);?></div><?php endif;?>
<div class="a-kpis" style="grid-template-columns:repeat(3,minmax(0,1fr));"><div class="a-kpi"><div class="a-kpi__top"><span class="a-kpi__label">Published</span><span class="a-kpi__icon a-kpi__icon--green"><?php echo admin_icon('website');?></span></div><div class="a-kpi__value"><?php echo number_format($published);?></div><div class="a-kpi__meta"><span class="a-kpi__hint">Live on the website</span></div></div><div class="a-kpi"><div class="a-kpi__top"><span class="a-kpi__label">Drafts</span><span class="a-kpi__icon"><?php echo admin_icon('edit');?></span></div><div class="a-kpi__value"><?php echo number_format($drafts);?></div><div class="a-kpi__meta"><span class="a-kpi__hint">Still being prepared</span></div></div><div class="a-kpi"><div class="a-kpi__top"><span class="a-kpi__label">Total articles</span><span class="a-kpi__icon"><?php echo admin_icon('listings');?></span></div><div class="a-kpi__value"><?php echo number_format(count($posts));?></div><div class="a-kpi__meta"><span class="a-kpi__hint">All statuses</span></div></div></div>
<section class="a-panel"><div class="a-panel__head"><div><h3><?php echo $edit?'Edit article':'Create article';?></h3><p>Use a strong title, a short summary and practical content.</p></div></div>
<form method="post" enctype="multipart/form-data" class="a-form"><?php echo csrf_field();?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int)($edit['id']??0);?>">
<div class="a-form__row"><div class="a-field"><label>Title</label><input class="a-input" name="title" maxlength="220" required value="<?php echo e($edit['title']??'');?>
