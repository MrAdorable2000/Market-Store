<?php
/**
 * includes/footer.php
 * --------------------------------------------------------------------
 * Phase 3: Compact, professional footer.
 * Columns: brand + language selector | Marketplace | Resources | Legal
 * Bottom: copyright + small social placeholders.
 */
?>
    </main>

    <footer class="footer footer--compact">
        <div class="footer__inner footer__inner--compact">
            <!-- Brand + language -->
            <div class="footer__col footer__col--brand">
                <div class="brand brand--footer">
                    <?php echo brand_mark_html(36); ?>
                    <span class="brand__text">Isoko<span class="brand__accent">Ryacu</span></span>
                </div>
                <p class="footer__tagline"><?php echo e(APP_TAGLINE); ?></p>
                <p class="footer__loc"><?php echo e(t('footer.made_for')); ?></p>

                <!-- Inline language selector (compact) -->
                <div class="footer__lang">
                    <?php $variant = 'inline'; require __DIR__ . '/language_selector.php'; ?>
                </div>

                <!-- Social placeholders -->
                <div class="footer__social">
                    <a href="#" aria-label="Facebook" title="Facebook">
                        <svg viewBox="0 0 24 24" width="16" height="16"><path d="M15 3h-3a4 4 0 0 0-4 4v3H5v4h3v7h4v-7h3l1-4h-4V7a1 1 0 0 1 1-1h3z" fill="currentColor"/></svg>
                    </a>
                    <a href="#" aria-label="Twitter" title="Twitter">
                        <svg viewBox="0 0 24 24" width="16" height="16"><path d="M22 5.8a8 8 0 0 1-2.4.7 4 4 0 0 0 1.8-2.2 8 8 0 0 1-2.6 1 4 4 0 0 0-6.9 3.6A11.4 11.4 0 0 1 3 4.5a4 4 0 0 0 1.2 5.4 4 4 0 0 1-1.8-.5 4 4 0 0 0 3.2 4 4 4 0 0 1-1.8.1 4 4 0 0 0 3.7 2.8A8 8 0 0 1 2 18a11.4 11.4 0 0 0 6 1.8c7.5 0 11.6-6.3 11.6-11.7v-.5a8 8 0 0 0 2-2z" fill="currentColor"/></svg>
                    </a>
                    <a href="#" aria-label="Instagram" title="Instagram">
                        <svg viewBox="0 0 24 24" width="16" height="16"><path d="M7 2h10a5 5 0 0 1 5 5v10a5 5 0 0 1-5 5H7a5 5 0 0 1-5-5V7a5 5 0 0 1 5-5zm0 2a3 3 0 0 0-3 3v10a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3V7a3 3 0 0 0-3-3zm5 3.5A4.5 4.5 0 1 1 7.5 12 4.5 4.5 0 0 1 12 7.5zm0 2A2.5 2.5 0 1 0 14.5 12 2.5 2.5 0 0 0 12 9.5zm5-3a1 1 0 1 1-1 1 1 1 0 0 1 1-1z" fill="currentColor"/></svg>
                    </a>
                </div>
            </div>

            <!-- Marketplace links -->
            <div class="footer__col">
                <h4><?php echo e(t('footer.marketplace')); ?></h4>
                <a href="<?php echo APP_URL; ?>/pages/explore.php"><?php echo e(t('footer.explore_listings')); ?></a>
                <a href="<?php echo APP_URL; ?>/pages/locations.php"><?php echo e(t('locations.title')); ?></a>
                <a href="<?php echo APP_URL; ?>/pages/categories.php"><?php echo e(t('footer.all_categories')); ?></a>
                <a href="<?php echo APP_URL; ?>/pages/explore.php?type=sell"><?php echo e(t('footer.buy')); ?></a>
                <a href="<?php echo APP_URL; ?>/pages/explore.php?type=rent"><?php echo e(t('footer.rent')); ?></a>
                <a href="<?php echo APP_URL; ?>/pages/sell.php"><?php echo e(t('footer.sell_item')); ?></a>
            </div>

            <!-- Resources -->
            <div class="footer__col">
                <h4><?php echo e(t('footer.resources')); ?></h4>
                <a href="<?php echo APP_URL; ?>/pages/about.php"><?php echo e(t('footer.about')); ?></a>
                <a href="<?php echo APP_URL; ?>/pages/contact.php"><?php echo e(t('footer.contact_us')); ?></a>
                <a href="<?php echo APP_URL; ?>/pages/safety.php"><?php echo e(t('footer.safety_tips')); ?></a>
                <a href="<?php echo APP_URL; ?>/pages/blog/post.php?slug=how-to-buy-safely"><?php echo e(t('footer.buying_guide')); ?></a>
                <a href="<?php echo APP_URL; ?>/pages/blog/post.php?slug=how-to-sell-online"><?php echo e(t('footer.selling_guide')); ?></a>
            </div>

            <!-- Account -->
            <div class="footer__col">
                <h4><?php echo e(t('footer.account')); ?></h4>
                <a href="<?php echo APP_URL; ?>/pages/login.php"><?php echo e(t('footer.login')); ?></a>
                <a href="<?php echo APP_URL; ?>/pages/register.php"><?php echo e(t('footer.register')); ?></a>
                <a href="<?php echo APP_URL; ?>/pages/favorites.php"><?php echo e(t('footer.favorites')); ?></a>
                <a href="<?php echo APP_URL; ?>/pages/profile.php"><?php echo e(t('nav.profile')); ?></a>
            </div>

            <!-- Legal -->
            <div class="footer__col">
                <h4><?php echo e(t('footer.legal')); ?></h4>
                <a href="<?php echo APP_URL; ?>/pages/terms.php"><?php echo e(t('footer.terms')); ?></a>
                <a href="<?php echo APP_URL; ?>/pages/privacy.php"><?php echo e(t('footer.privacy')); ?></a>
                <a href="<?php echo APP_URL; ?>/pages/safety.php"><?php echo e(t('footer.safety')); ?></a>
                <a href="<?php echo APP_URL; ?>/pages/contact.php"><?php echo e(t('footer.help')); ?></a>
            </div>
        </div>

        <div class="footer__bottom footer__bottom--compact">
            <span>© <?php echo date('Y'); ?> <?php echo e(APP_NAME); ?>. <?php echo e(t('footer.copyright')); ?></span>
            <span class="footer__legal">
                <a href="<?php echo APP_URL; ?>/pages/terms.php"><?php echo e(t('footer.terms')); ?></a>
                <a href="<?php echo APP_URL; ?>/pages/privacy.php"><?php echo e(t('footer.privacy')); ?></a>
                <a href="<?php echo APP_URL; ?>/pages/safety.php"><?php echo e(t('footer.safety')); ?></a>
            </span>
        </div>
    </footer>

    <!-- PWA install control. It only appears when the browser confirms the app can be installed. -->
    <button id="pwaInstallButton" class="pwa-install" type="button" hidden aria-label="Install Isoko Ryacu app">
        <img class="pwa-install__icon" src="<?php echo e(logo_asset('pwa-192.png')); ?>" alt="">
        <span>Install Isoko Ryacu</span>
        <span class="pwa-install__close" aria-hidden="true">×</span>
    </button>

    <!-- Scripts -->
    <script src="<?php echo e(asset_url('assets/js/main.js')); ?>" defer></script>
    <script src="<?php echo e(asset_url('assets/js/confirm-modal.js')); ?>" defer></script>
    <script src="<?php echo e(asset_url('assets/js/search.js')); ?>" defer></script>
    <script src="<?php echo e(asset_url('assets/js/favorites.js')); ?>" defer></script>

    <script>
    // Progressive Web App: registration + install prompt + update handling.
    (function () {
        var installButton = document.getElementById('pwaInstallButton');
        var deferredPrompt = null;
        var appInstalled = false;

        function showInstallButton() {
            if (!installButton || appInstalled) return;
            installButton.hidden = false;
            requestAnimationFrame(function () { installButton.classList.add('is-visible'); });
        }

        function hideInstallButton() {
            if (!installButton) return;
            installButton.classList.remove('is-visible');
            setTimeout(function () { installButton.hidden = true; }, 220);
        }

        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('<?php echo APP_URL; ?>/service-worker.js', {
                    scope: '<?php echo APP_URL; ?>/'
                }).then(function (registration) {
                    // Ask a waiting worker to activate when the user refreshes after an update.
                    if (registration.waiting) {
                        registration.waiting.postMessage({type: 'SKIP_WAITING'});
                    }
                }).catch(function () {
                    // PWA support is optional; the marketplace remains fully usable without it.
                });
            });
        }

        window.addEventListener('beforeinstallprompt', function (event) {
            event.preventDefault();
            deferredPrompt = event;
            showInstallButton();
        });

        window.addEventListener('appinstalled', function () {
            appInstalled = true;
            deferredPrompt = null;
            hideInstallButton();
        });

        if (installButton) {
            installButton.addEventListener('click', function () {
                if (!deferredPrompt) return;
                deferredPrompt.prompt();
                deferredPrompt.userChoice.finally(function () {
                    deferredPrompt = null;
                    hideInstallButton();
                });
            });

            installButton.querySelector('.pwa-install__close').addEventListener('click', function (event) {
                event.stopPropagation();
                hideInstallButton();
            });
        }
    })();
    </script>
</body>
</html>
