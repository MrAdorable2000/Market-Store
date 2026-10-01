<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_icons.php';
require_role('super_admin');
$pdo=db();
$langs=['en'=>'English','rw'=>'Kinyarwanda','fr'=>'Français','sw'=>'Kiswahili'];
$fields=[
'hero_eyebrow'=>['Hero eyebrow','Small label above the main headline'],
'hero_title'=>['Hero title','Main homepage headline'],
'hero_subtitle'=>['Hero subtitle','Main supporting text'],
'hero_search_placeholder'=>['Search placeholder','Text shown inside homepage search'],
'browse_categories_title'=>['Categories title','Popular categories section heading'],
'featured_products_title'=>['Featured products title','Featured products heading'],
'trending_title'=>['Trending title','Trending listings heading'],
'rent_title'=>['Rent title','Things to rent heading'],
'all_rentals'=>['Rent link','Link text for all rentals'],
'community_title'=>['Community title','People/community section heading'],
'community_subtitle'=>['Community subtitle','Supporting text under community heading'],
'video_title'=>['Video title','Featured video heading'],
'updates_title'=>['Updates title','Events and announcements heading'],
'market_insights_title'=>['Market insights title','Market insights heading'],
'market_insights_sub'=>['Market insights subtitle','Supporting text for market insights'],
'market_blog_title'=>['Blog title','Market blog heading'],
'all_articles'=>['Articles link','Link text for all articles'],
'cta_title'=>['CTA title','Bottom call-to-action heading'],
'cta_body'=>['CTA body','Bottom call-to-action text'],
'cta_sell_item'=>['Sell CTA','Sell button text'],
'cta_register_seller'=>['Seller CTA','Register seller button text'],
'see_all'=>['See all link','General section link text'],
'view_all'=>['View all link','General view-all link text'],
];
$vals=[]; $q=$pdo->query("SELECT setting_key,setting_value FROM site_settings WHERE setting_key LIKE 'home_%'"); foreach($q as $r)$vals[$r['setting_key']]=$r['setting_value'];
$pageTitle='Homepage Editor'; $activePage='admin'; $adminActivePage='homepage'; $extraCss='<link rel="stylesheet" href="'.asset_url('assets/css/admin.css').'">'; $adminPageTitle=$pageTitle; $adminPageSubtitle='Super Admin — control the public homepage content'; require __DIR__.'/../../includes/header.php';
?>
<div class="a-shell" id="aShell">
<?php require __DIR__.'/../../includes/admin_sidebar.php'; ?><div class="a-main"><?php require __DIR__.'/../../includes/admin_topbar.php'; ?><div class="a-content">
<div class="a-hero"><div><h2>Homepage Editor</h2><p class="a-hero__sub">Change the text visitors see on the IsokoRyacu homepage without editing PHP files.</p></div><div class="a-hero__actions"><a class="a-btn" href="<?php echo APP_URL; ?>/pages/home.php" target="_blank">View website ↗</a></div></div>
<div class="a-panel"><div class="a-panel__head"><div><h3>Homepage content</h3><p>Edit each supported language. Blank fields use the normal system translation.</p></div><span class="a-badge">SUPER ADMIN</span></div>
<form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php">
<?php echo csrf_field(); ?><input type="hidden" name="action" value="homepage_save">
<?php foreach($langs as $code=>$name): ?>
<details class="a-panel" style="margin:16px 0" <?php echo $code==='en'?'open':''; ?>><summary style="cursor:pointer;font-weight:800;padding:4px"><?php echo e($name); ?> (<?php echo e(strtoupper($code)); ?>)</summary><div class="a-form" style="padding-top:18px">
<?php foreach($fields as $key=>$meta): $setting='home_'.$key.'_'.$code; ?>
<div class="a-field"><label for="<?php echo e($setting); ?>"><?php echo e($meta[0]); ?></label><input class="a-input" id="<?php echo e($setting); ?>" name="<?php echo e($setting); ?>" maxlength="500" value="<?php echo e($vals[$setting]??''); ?>" placeholder="<?php echo e($meta[1]); ?>"><p class="a-help"><?php echo e($meta[1]); ?></p></div>
<?php endforeach; ?></div></details>
<?php endforeach; ?>
<div class="a-hero__actions" style="margin-top:20px"><button class="a-btn a-btn--primary" type="submit">Save homepage content</button><a class="a-btn" href="<?php echo APP_URL; ?>/pages/home.php" target="_blank">Preview homepage</a></div>
</form></div>
</div></div></div>
