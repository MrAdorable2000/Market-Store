<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
$q=trim($_GET['q']??''); $category=trim($_GET['category']??'');
$sql='SELECT id,title,slug,excerpt,cover_path,category,views_count,published_at FROM blog_posts WHERE status="published"'; $params=[];
if($q){$sql.=' AND (title LIKE ? OR excerpt LIKE ? OR body LIKE ?)';$params=["%$q%","%$q%","%$q%"];}
if($category){$sql.=' AND category=?';$params[]=$category;}
$sql.=' ORDER BY published_at DESC, created_at DESC'; $st=db()->prepare($sql);$st->execute($params);$posts=$st->fetchAll();
$cats=db()->query('SELECT DISTINCT category FROM blog_posts WHERE status="published" AND category IS NOT NULL AND category<>"" ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);
function blog_public_cover(?string $path,string $fallback='blog-default'): string { if($path && preg_match('#^assets/uploads/[A-Za-z0-9._/-]+$#',$path) && is_file(__DIR__.'/../../'.$path)) return APP_URL.'/'.$path; return real_image($fallback,'blog-default'); }
$pageTitle=t('sections.market_blog'); require_once __DIR__.'/../../includes/header.php';
?>
<div class="blog-page">
<div class="container section--tight">
<div class="blog-breadcrumb"><?php echo back_button(APP_URL.'/','', 'solid'); ?></div>
<div class="blog-hero"><div><span class="blog-eyebrow">ISOKORYACU JOURNAL</span><h1>From the market blog</h1><p>Practical ideas, buying guides and marketplace stories to help you buy, sell and rent with confidence.</p></div><div class="blog-search"><form method="get"><input type="search" name="q" value="<?php echo e($q);?>" placeholder="Search articles..." aria-label="Search articles"><button class="btn btn--primary" type="submit">Search</button></form></div></div>
<?php if($cats):?><div class="blog-filters"><a class="blog-filter <?php echo $category===''?'is-active':'';?>" href="<?php echo APP_URL;?>/pages/blog/index.php">All</a><?php foreach($cats as $c):?><a class="blog-filter <?php echo $category===$c?'is-active':'';?>" href="<?php echo APP_URL;?>/pages/blog/index.php?category=<?php echo urlencode($c);?>"><?php echo e($c);?></a><?php endforeach;?></div><?php endif;?>
<?php if(!$posts):?><div class="blog-empty"><div class="blog-empty__icon">✦</div><h2>No articles yet</h2><p>New guides and marketplace stories will appear here soon.</p><a class="btn btn--primary" href="<?php echo APP_URL;?>/">Explore the marketplace</a></div>
<?php else: ?><div class="blog-grid"><?php foreach($posts as $i=>$p):?><a class="blog-card-pro <?php echo $i===0?'blog-card-pro--featured':'';?>" href="<?php echo APP_URL;?>/pages/blog/post.php?slug=<?php echo e($p['slug']);?>"><div class="blog-card-pro__media"><img loading="lazy" src="<?php echo e(blog_public_cover($p['cover_path']??null));?>" alt="<?php echo e($p['title']);?>"><span><?php echo e($p['category']??'Marketplace');?></span></div><div class="blog-card-pro__body"><div class="blog-card-pro__meta"><span><?php echo time_ago($p['published_at']);?></span><span>•</span><span><?php echo number_format((int)$p['views_count']);?> views</span></div><h2><?php echo e($p['title']);?></h2><p><?php echo e(excerpt($p['excerpt']??'',170));?></p><div class="blog-read">Read article <span>→</span></div></div></a><?php endforeach;?></div><?php endif;?>
</div></div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>