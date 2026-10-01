<?php
/**
 * includes/language_selector.php
 * --------------------------------------------------------------------
 * Renders the language selector dropdown. Include in the navbar.
 *
 * Two modes:
 *   $variant = 'inline'  → pill button + dropdown (used in navbar actions)
 *   $variant = 'mobile' → larger button (used in mobile drawer)
 */
$variant = $variant ?? 'inline';   // safe default — fixes undefined-variable warning
$current = current_lang_meta();
$langs = available_langs();
$currentCode = $current['code'];
?>

<div class="lang-switcher" data-variant="<?php echo e($variant); ?>">
    <button type="button" class="lang-switcher__btn" aria-haspopup="true" aria-expanded="false" aria-label="<?php echo e(t('common.language')); ?>">
        <span class="flag"><?php echo e($current['flag']); ?></span>
        <span class="name"><?php echo e($current['native']); ?></span>
        <svg class="chevron" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </button>
    <div class="lang-switcher__menu" role="menu" aria-label="<?php echo e(t('common.language')); ?>">
        <?php foreach ($langs as $code => $meta): ?>
            <a href="<?php echo e(lang_switch_url($code)); ?>"
               class="lang-switcher__item <?php echo $code === $currentCode ? 'is-active' : ''; ?>"
               role="menuitem"
               data-lang="<?php echo e($code); ?>">
                <span class="flag"><?php echo e($meta['flag']); ?></span>
                <span class="label">
                    <span class="native"><?php echo e($meta['native']); ?></span>
                    <span class="english"><?php echo e($meta['name']); ?></span>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
