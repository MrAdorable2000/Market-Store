<?php
/**
 * includes/admin_footer.php
 * --------------------------------------------------------------------
 * Compact IsokoRyacu admin footer (Phase 5).
 *
 * Admin pages include this INSTEAD of includes/footer.php, so the public
 * website footer (marketplace links, blog columns…) never appears inside
 * the admin area. The admin shell keeps the public brand identity but is
 * a fully self-contained layout with its own scripts.
 *
 * Expected page contract (what this file closes):
 *   <div class="a-shell" id="aShell">        ← opened by the page
 *     <aside class="a-sidebar">…</aside>     ← includes/admin_sidebar.php
 *     <div class="a-main">                   ← opened by the page
 *       <header class="a-topbar">…</header>  ← includes/admin_topbar.php
 *       <div class="a-content">…</div>       ← closed by the page
 *       [this file] a-footer, close a-main, close a-shell, close main
 *
 * Language switching inside the admin area is preserved through the same
 * ?lang=xx mechanism used by the public site.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/i18n.php';
$langMeta = current_lang_meta();
$langs    = available_langs();
?>
    <footer class="a-footer">
        <span class="a-footer__brand">
            <?php echo brand_mark_html(22); ?>
            <span><?php echo e(t('admin.footer_line', ['year' => date('Y'), 'app' => APP_NAME])); ?></span>
        </span>

        <span class="a-footer__lang" style="display:inline-flex; align-items:center; gap:6px;">
            <?php foreach ($langs as $code => $meta): ?>
                <a href="<?php echo e(lang_switch_url($code)); ?>"
                   title="<?php echo e($meta['native']); ?>"
                   style="font-size:11px; font-weight:800; padding:4px 9px; border-radius:16px; text-decoration:none;
                          <?php echo $code === $langMeta['code'] ? 'background:var(--a-teal-soft); color:var(--a-teal-deep);' : 'color:var(--a-muted);'; ?>">
                    <?php echo e(strtoupper($code)); ?>
                </a>
            <?php endforeach; ?>
        </span>

        <span>
            <a href="<?php echo APP_URL; ?>/"><?php echo e(t('admin.view_website')); ?></a>
            &nbsp;·&nbsp;
            <a href="<?php echo APP_URL; ?>/pages/logout.php"><?php echo e(t('nav.sign_out')); ?></a>
        </span>
    </footer>
    </div><!-- /a-main -->
</div><!-- /a-shell -->
</main>

<!-- Admin scripts only — no duplicate JS from the public site -->
<script src="<?php echo e(asset_url('assets/js/admin.js')); ?>" defer></script>
</body>
</html>
