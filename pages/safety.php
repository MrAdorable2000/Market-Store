<?php
/** pages/safety.php — translated safety center */
require_once __DIR__ . '/../includes/functions.php';
$pageTitle = t('safety.title');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container section--tight" style="max-width:780px;">
    <div style="margin:14px 0 4px;">
        <?php echo back_button(APP_URL . '/', '', 'solid'); ?>
    </div>
    <h1 class="section__title"><?php echo e(t('safety.title')); ?></h1>
    <p class="section__sub"><?php echo e(t('safety.sub')); ?></p>
    <div class="grid grid--2" style="margin-top:20px;">
        <a class="card card--hover" href="<?php echo APP_URL; ?>/pages/blog/post.php?slug=how-to-buy-safely">
            <h3><?php echo e(t('safety.buy_safely')); ?></h3>
            <p class="text-soft"><?php echo e(t('safety.buy_safely_sub')); ?></p>
        </a>
        <a class="card card--hover" href="<?php echo APP_URL; ?>/pages/blog/post.php?slug=avoiding-scams">
            <h3><?php echo e(t('safety.scams')); ?></h3>
            <p class="text-soft"><?php echo e(t('safety.scams_sub')); ?></p>
        </a>
        <a class="card card--hover" href="<?php echo APP_URL; ?>/pages/blog/post.php?slug=renting-property-guide">
            <h3><?php echo e(t('safety.renting')); ?></h3>
            <p class="text-soft"><?php echo e(t('safety.renting_sub')); ?></p>
        </a>
        <a class="card card--hover" href="<?php echo APP_URL; ?>/pages/blog/post.php?slug=how-to-sell-online">
            <h3><?php echo e(t('safety.selling')); ?></h3>
            <p class="text-soft"><?php echo e(t('safety.selling_sub')); ?></p>
        </a>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
