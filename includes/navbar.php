<?php
/**
 * includes/navbar.php
 * --------------------------------------------------------------------
 * Responsive navigation bar (Phase 2: fully translated).
 * Desktop: logo + primary nav + language selector + auth area.
 * Mobile: hamburger toggle, slide-in drawer (incl. language), bottom nav.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
// i18n is loaded via functions.php

$user = current_user();
?>
<header class="navbar" id="siteNavbar">
    <div class="navbar__inner">
        <!-- Brand -->
        <a href="<?php echo APP_URL; ?>/" class="brand" aria-label="Isoko Ryacu home">
            <?php echo brand_mark_html(36); ?>
            <span class="brand__text">Isoko<span class="brand__accent">Ryacu</span></span>
        </a>

        <!-- Desktop nav -->
        <nav class="nav nav--primary" aria-label="<?php echo e(t('nav.categories')); ?>">
            <a href="<?php echo APP_URL; ?>/" class="nav__link"><?php echo e(t('nav.home')); ?></a>
            <a href="<?php echo APP_URL; ?>/pages/explore.php" class="nav__link"><?php echo e(t('nav.explore')); ?></a>
            <a href="<?php echo APP_URL; ?>/pages/categories.php" class="nav__link"><?php echo e(t('nav.categories')); ?></a>
            <a href="<?php echo APP_URL; ?>/pages/explore.php?type=sell" class="nav__link"><?php echo e(t('nav.buy')); ?></a>
            <a href="<?php echo APP_URL; ?>/pages/explore.php?type=rent" class="nav__link"><?php echo e(t('nav.rent')); ?></a>
            <a href="<?php echo APP_URL; ?>/pages/sell.php" class="nav__link nav__link--accent"><?php echo e(t('nav.sell')); ?></a>
            <a href="<?php echo APP_URL; ?>/pages/market-insights.php" class="nav__link"><?php echo e(t('nav.insights')); ?></a>
            <a href="<?php echo APP_URL; ?>/pages/blog/index.php" class="nav__link"><?php echo e(t('nav.blog')); ?></a>
        </nav>

        <!-- Right-side actions -->
        <div class="nav nav--actions">
            <!-- Language selector -->
            <?php require __DIR__ . '/language_selector.php'; ?>

            <button class="icon-btn" id="themeToggle" type="button" aria-label="<?php echo e(t('nav.toggle_theme')); ?>" title="<?php echo e(t('nav.toggle_theme')); ?>">
                <svg class="i-moon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
                <svg class="i-sun" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
            </button>

            <a href="<?php echo APP_URL; ?>/pages/favorites.php" class="icon-btn" aria-label="<?php echo e(t('nav.favorites')); ?>" title="<?php echo e(t('nav.favorites')); ?>">
                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
                    <path d="M12 21s-7-4.5-9.5-9A4.5 4.5 0 0 1 12 5.5 4.5 4.5 0 0 1 21.5 12c-2.5 4.5-9.5 9-9.5 9z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                </svg>
            </a>

            <a href="<?php echo APP_URL; ?>/pages/notifications.php" class="icon-btn nav__bell" aria-label="<?php echo e(t('nav.notifications')); ?>" title="<?php echo e(t('nav.notifications')); ?>">
                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
                    <path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span class="nav__bell-dot" aria-hidden="true"></span>
            </a>

            <?php if ($user): ?>
                <?php
                // Role-aware "My dashboard" link — every role gets its own dashboard.
                $rn = strtoupper($user['role_name'] ?? '');
                $dashUrl = match ($rn) {
                    'ADMIN', 'SUPER_ADMIN' => APP_URL . '/pages/admin/dashboard.php',
                    default                => APP_URL . '/pages/dashboard.php',
                };
                ?>
                <div class="dropdown">
                    <button class="dropdown__trigger" aria-haspopup="true" aria-expanded="false" type="button">
                        <span class="avatar"><?php echo e(strtoupper(substr($user['full_name'], 0, 1))); ?></span>
                        <span class="dropdown__name"><?php echo e(explode(' ', $user['full_name'])[0]); ?></span>
                        <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <div class="dropdown__menu" role="menu">
                        <a role="menuitem" href="<?php echo e($dashUrl); ?>" style="font-weight:700;color:var(--brand-600);">
                            <?php echo e(t('nav.my_dashboard')); ?>
                        </a>
                        <a role="menuitem" href="<?php echo APP_URL; ?>/pages/profile.php"><?php echo e(t('nav.profile')); ?></a>
                        <a role="menuitem" href="<?php echo APP_URL; ?>/pages/favorites.php"><?php echo e(t('nav.favorites')); ?></a>
                        <?php if (in_array($rn, ['SELLER', 'ADMIN', 'SUPER_ADMIN'], true)): ?>
                            <a role="menuitem" href="<?php echo APP_URL; ?>/pages/sell.php"><?php echo e(t('nav.sell_item')); ?></a>
                        <?php else: ?>
                            <a role="menuitem" href="<?php echo APP_URL; ?>/pages/register.php?type=seller"><?php echo e(t('nav.become_seller')); ?></a>
                        <?php endif; ?>
                        <?php if (in_array($rn, ['ADMIN', 'SUPER_ADMIN'], true)): ?>
                            <a role="menuitem" href="<?php echo APP_URL; ?>/pages/admin/dashboard.php"><?php echo e(t('nav.admin_dash')); ?></a>
                        <?php endif; ?>
                        <hr>
                        <a role="menuitem" class="dropdown__logout" href="<?php echo APP_URL; ?>/pages/logout.php"><?php echo e(t('nav.sign_out')); ?></a>
                    </div>
                </div>
            <?php else: ?>
                <a href="<?php echo APP_URL; ?>/pages/login.php" class="btn btn--ghost"><?php echo e(t('nav.login')); ?></a>
                <a href="<?php echo APP_URL; ?>/pages/register.php" class="btn btn--primary"><?php echo e(t('nav.register')); ?></a>
            <?php endif; ?>

            <!-- Mobile hamburger -->
            <button class="nav__toggle" id="navToggle" type="button" aria-label="<?php echo e(t('nav.categories')); ?>" aria-controls="mobileNav">
                <span></span><span></span><span></span>
            </button>
        </div>
    </div>

    <!-- Mobile drawer -->
    <div class="mobile-nav" id="mobileNav" hidden>
        <nav aria-label="Mobile">
            <a href="<?php echo APP_URL; ?>/" class="mobile-nav__link"><?php echo e(t('nav.home')); ?></a>
            <a href="<?php echo APP_URL; ?>/pages/explore.php" class="mobile-nav__link"><?php echo e(t('nav.explore')); ?></a>
            <a href="<?php echo APP_URL; ?>/pages/categories.php" class="mobile-nav__link"><?php echo e(t('nav.categories')); ?></a>
            <a href="<?php echo APP_URL; ?>/pages/explore.php?type=sell" class="mobile-nav__link"><?php echo e(t('nav.buy')); ?></a>
            <a href="<?php echo APP_URL; ?>/pages/explore.php?type=rent" class="mobile-nav__link"><?php echo e(t('nav.rent')); ?></a>
            <a href="<?php echo APP_URL; ?>/pages/sell.php" class="mobile-nav__link mobile-nav__link--accent"><?php echo e(t('nav.sell_item')); ?></a>
            <a href="<?php echo APP_URL; ?>/pages/market-insights.php" class="mobile-nav__link"><?php echo e(t('nav.insights')); ?></a>
            <a href="<?php echo APP_URL; ?>/pages/blog/index.php" class="mobile-nav__link"><?php echo e(t('nav.blog')); ?></a>
            <a href="<?php echo APP_URL; ?>/pages/favorites.php" class="mobile-nav__link"><?php echo e(t('nav.favorites')); ?></a>
            <a href="<?php echo APP_URL; ?>/pages/notifications.php" class="mobile-nav__link"><?php echo e(t('nav.notifications')); ?></a>
            <hr>
            <!-- Language selector in mobile drawer -->
            <?php $variant = 'mobile'; require __DIR__ . '/language_selector.php'; ?>
            <?php if (!$user): ?>
                <hr>
                <a href="<?php echo APP_URL; ?>/pages/login.php" class="mobile-nav__link"><?php echo e(t('nav.login')); ?></a>
                <a href="<?php echo APP_URL; ?>/pages/register.php" class="mobile-nav__link"><?php echo e(t('nav.register')); ?></a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<!-- Mobile bottom navigation -->
<nav class="bottom-nav" aria-label="Quick actions">
    <a href="<?php echo APP_URL; ?>/" class="bottom-nav__item">
        <svg viewBox="0 0 24 24" width="22" height="22"><path d="M3 12 12 3l9 9v9a1 1 0 0 1-1 1h-5v-6h-4v6H4a1 1 0 0 1-1-1z" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>
        <span><?php echo e(t('nav.home')); ?></span>
    </a>
    <a href="<?php echo APP_URL; ?>/pages/explore.php" class="bottom-nav__item">
        <svg viewBox="0 0 24 24" width="22" height="22"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="m21 21-4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
        <span><?php echo e(t('nav.explore')); ?></span>
    </a>
    <a href="<?php echo APP_URL; ?>/pages/sell.php" class="bottom-nav__item bottom-nav__item--accent">
        <span class="bottom-nav__plus">+</span>
        <span><?php echo e(t('nav.sell')); ?></span>
    </a>
    <a href="<?php echo APP_URL; ?>/pages/favorites.php" class="bottom-nav__item">
        <svg viewBox="0 0 24 24" width="22" height="22"><path d="M12 21s-7-4.5-9.5-9A4.5 4.5 0 0 1 12 5.5 4.5 4.5 0 0 1 21.5 12c-2.5 4.5-9.5 9-9.5 9z" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>
        <span><?php echo e(t('nav.saved')); ?></span>
    </a>
    <a href="<?php echo APP_URL; ?>/pages/profile.php" class="bottom-nav__item">
        <svg viewBox="0 0 24 24" width="22" height="22"><circle cx="12" cy="8" r="4" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M4 21a8 8 0 0 1 16 0" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>
        <span><?php echo e(t('nav.me')); ?></span>
    </a>
</nav>
