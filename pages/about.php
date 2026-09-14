<?php
/** pages/about.php — translated About page */
require_once __DIR__ . '/../includes/functions.php';

$pageTitle = t('about.title');
require_once __DIR__ . '/../includes/header.php';
?>
<article class="container section--tight" style="max-width:780px;">
    <div style="margin:14px 0 4px;">
        <?php echo back_button(APP_URL . '/', '', 'solid'); ?>
    </div>
    <h1 class="section__title"><?php echo e(t('about.title')); ?></h1>
    <p class="text-soft" style="font-size:16px;line-height:1.7;"><?php echo e(t('about.intro')); ?></p>

    <h2 style="margin-top:28px;"><?php echo e(t('about.mission_title')); ?></h2>
    <p style="line-height:1.7;"><?php echo e(t('about.mission_body')); ?></p>

    <h2 style="margin-top:24px;"><?php echo e(t('about.values_title')); ?></h2>
    <p style="line-height:1.7;"><?php echo e(t('about.values_body')); ?></p>
</article>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
