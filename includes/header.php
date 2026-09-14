<?php
/**
 * includes/header.php
 * --------------------------------------------------------------------
 * Reusable <head> + top of page.  Pages set $pageTitle and $pageDescription
 * before including this file:
 *
 *   $pageTitle = 'Home';
 *   $pageDescription = 'Discover Anything.';
 *   require_once __DIR__ . '/../includes/header.php';
 *   // ... page content ...
 *   require_once __DIR__ . '/../includes/footer.php';
 * --------------------------------------------------------------------
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/auth.php';

$pageTitle       = $pageTitle       ?? APP_NAME;
$pageDescription = $pageDescription ?? APP_TAGLINE;
$activePage      = $activePage      ?? '';
$currentUser     = current_user();
?>
<!DOCTYPE html>
<html lang="<?php echo e(current_lang()); ?>" data-theme="light" data-app-url="<?php echo e(APP_URL); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0ea5a4">
    <meta name="description" content="<?php echo e($pageDescription); ?>">
    <meta name="generator" content="<?php echo e(APP_NAME . ' ' . APP_VERSION); ?>">
    <!-- CSRF token for AJAX (favorites, reports, rental requests) -->
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <!-- Logged-in flag so JS can require auth before AJAX calls -->
    <meta name="user-logged-in" content="<?php echo $currentUser ? '1' : '0'; ?>">
    <meta name="session-idle-timeout" content="<?php echo $currentUser ? (int) IDLE_SESSION_TIMEOUT : 0; ?>">
    <meta name="session-idle-heartbeat" content="<?php echo $currentUser ? (int) IDLE_SESSION_HEARTBEAT : 0; ?>">

    <title><?php echo e($pageTitle); ?> · <?php echo e(APP_NAME); ?></title>

    <!-- Official IsokoRyacu logo favicon (falls back to the old photo icon) -->
    <?php if (logo_asset('favicon.ico') !== ''): ?>
    <link rel="icon" href="<?php echo e(logo_asset('favicon.ico')); ?>" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo e(logo_asset('favicon-32.png')); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo e(logo_asset('favicon-16.png')); ?>">
    <link rel="apple-touch-icon" href="<?php echo e(logo_asset('apple-touch-icon.png')); ?>">
    <?php else: ?>
    <link rel="icon" href="<?php echo real_image('favicon', 'favicon'); ?>" type="image/jpeg">
    <?php endif; ?>

    <!-- Fonts: Inter (UI/body) + Playfair Display (headline serif, already
         referenced in --font-serif but never actually loaded before) +
         Caveat (used sparingly for one handwritten accent in the hero) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:wght@700;800;900&family=Caveat:wght@600;700&display=swap" rel="stylesheet">

    <!-- Styles -->
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/components.css">
    <?php if (!empty($extraCss)) echo $extraCss; ?>

    <!-- Inline early theme (prevents flash of wrong theme) -->
    <script>
      (function () {
        try {
          var t = localStorage.getItem('isoko-theme');
          if (t) {
            document.documentElement.setAttribute('data-theme', t);
          } else if (window.matchMedia
                     && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            // No stored choice yet: follow the visitor's OS preference
            document.documentElement.setAttribute('data-theme', 'dark');
          }
        } catch (e) {}
      })();
    </script>
</head>
<body class="page <?php echo e($activePage); ?>">
    <!-- Skip link for accessibility -->
    <a href="#main" class="skip-link"><?php echo e(t("common.skip_content")); ?></a>

    <!-- Flash messages — floating toast notifications, auto-dismiss after 2s -->
    <div class="toast-stack" id="toastStack" aria-live="polite" aria-atomic="true">
    <?php foreach (flash_get() as $flash):
        $flashType = $flash['type'];
        $isWelcomeFlash = ($flashType === 'success' && $currentUser && isset($flash['message'])
            && $flash['message'] === t('flash.welcome_back', ['name' => $currentUser['full_name'] ?? '']));
        $flashIcon = match($flashType) {
            'success' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
            'error'   => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
            'warning' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>',
            'info'    => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>',
            default   => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>',
        };
    ?>
        <div class="toast toast--<?php echo e($flashType); ?><?php echo $isWelcomeFlash ? ' toast--welcome' : ''; ?>" role="alert">
            <span class="toast__icon"><?php echo $flashIcon; ?></span>
            <span class="toast__text"><?php echo e($flash['message']); ?></span>
            <button class="toast__close" aria-label="<?php echo e(t("common.dismiss")); ?>" type="button">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
            <span class="toast__progress"></span>
        </div>
    <?php endforeach; ?>
    </div>

    <?php if (($activePage ?? '') !== 'admin'): ?>
        <?php require_once __DIR__ . '/navbar.php'; ?>
    <?php endif; ?>

    <main id="main" class="main">
