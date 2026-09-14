<?php
/** pages/privacy.php — translated */
require_once __DIR__ . '/../includes/functions.php';
$pageTitle = t('privacy.title');
require_once __DIR__ . '/../includes/header.php';
?>
<article class="container section--tight" style="max-width:780px;">
    <div style="margin:14px 0 4px;">
        <?php echo back_button(APP_URL . '/', '', 'solid'); ?>
    </div>
    <h1 class="section__title"><?php echo e(t('privacy.title')); ?></h1>
    <p class="text-mute"><?php echo e(t('terms.updated')); ?> <?php echo date('F Y'); ?></p>
    <p><?php echo e(t('privacy.intro')); ?></p>
    <h2><?php echo e(t('privacy.collect')); ?></h2>
    <p><?php echo e(t('privacy.collect_body')); ?></p>
    <h2><?php echo e(t('privacy.use')); ?></h2>
    <p><?php echo e(t('privacy.use_body')); ?></p>
    <h2><?php echo e(t('privacy.dont')); ?></h2>
    <p><?php echo e(t('privacy.dont_body')); ?></p>
    <h2><?php echo e(t('privacy.choices')); ?></h2>
    <p><?php echo e(t('privacy.choices_body')); ?></p>
</article>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
