<?php
/** pages/terms.php — translated */
require_once __DIR__ . '/../includes/functions.php';
$pageTitle = t('terms.title');
require_once __DIR__ . '/../includes/header.php';
?>
<article class="container section--tight" style="max-width:780px;">
    <div style="margin:14px 0 4px;">
        <?php echo back_button(APP_URL . '/', '', 'solid'); ?>
    </div>
    <h1 class="section__title"><?php echo e(t('terms.title')); ?></h1>
    <p class="text-mute"><?php echo e(t('terms.updated')); ?> <?php echo date('F Y'); ?></p>
    <p><?php echo e(t('terms.intro')); ?></p>
    <h2><?php echo e(t('terms.acceptable_use')); ?></h2>
    <p><?php echo e(t('terms.acceptable_use_body')); ?></p>
    <h2><?php echo e(t('terms.listings')); ?></h2>
    <p><?php echo e(t('terms.listings_body')); ?></p>
    <h2><?php echo e(t('terms.transactions')); ?></h2>
    <p><?php echo e(t('terms.transactions_body')); ?></p>
    <h2><?php echo e(t('terms.account_security')); ?></h2>
    <p><?php echo e(t('terms.account_security_body')); ?></p>
    <h2><?php echo e(t('terms.removal')); ?></h2>
    <p><?php echo e(t('terms.removal_body')); ?></p>
</article>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
