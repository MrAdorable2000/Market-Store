<?php
/**
 * pages/404.php — Professional 404 error page
 * Used when a page is not found. Matches the Isoko Ryacu brand.
 */
require_once __DIR__ . '/../includes/functions.php';
$pageTitle = 'Page Not Found';
$activePage = '';
require_once __DIR__ . '/../includes/header.php';
?>
<div style="min-height:70vh;display:grid;place-items:center;padding:40px 20px;">
    <div style="text-align:center;max-width:480px;">
        <div style="font-size:120px;font-weight:800;line-height:1;color:var(--brand-500);letter-spacing:-.05em;margin-bottom:8px;">404</div>
        <h1 style="font-size:24px;font-weight:800;color:var(--text);margin:0 0 10px;letter-spacing:-.02em;">Page Not Found</h1>
        <p style="font-size:14px;color:var(--text-mute);margin:0 0 24px;line-height:1.6;">
            The page you're looking for doesn't exist or has been moved.
            Let's get you back on track.
        </p>
        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
            <a href="<?php echo APP_URL; ?>/" class="btn btn--primary">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:5px;"><path d="M3 12l9-9 9 9M5 10v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V10"/></svg>
                Back to Home
            </a>
            <a href="<?php echo APP_URL; ?>/pages/explore.php" class="btn btn--outline">Explore Marketplace</a>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
