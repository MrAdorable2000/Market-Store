<?php
/** pages/contact.php — translated */
require_once __DIR__ . '/../includes/functions.php';
$pageTitle = t('contact.title');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container section--tight" style="max-width:640px;">
    <div style="margin:14px 0 4px;">
        <?php echo back_button(APP_URL . '/', '', 'solid'); ?>
    </div>
    <h1 class="section__title"><?php echo e(t('contact.title')); ?></h1>
    <p class="section__sub"><?php echo e(t('contact.sub')); ?></p>
    <form class="card" method="post" style="margin-top:20px;">
        <?php echo csrf_field(); ?>
        <div class="form-row">
            <div class="form-group"><label for="name"><?php echo e(t('contact.your_name')); ?></label><input type="text" id="name" name="name" required></div>
            <div class="form-group"><label for="email"><?php echo e(t('contact.your_email')); ?></label><input type="email" id="email" name="email" required></div>
        </div>
        <div class="form-group"><label for="subject"><?php echo e(t('contact.subject')); ?></label><input type="text" id="subject" name="subject" required></div>
        <div class="form-group"><label for="message"><?php echo e(t('contact.message')); ?></label><textarea id="message" name="message" rows="6" required></textarea></div>
        <button type="submit" class="btn btn--primary"><?php echo e(t('contact.send')); ?></button>
    </form>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
