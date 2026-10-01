<?php
/**
 * index.php — Isoko Ryacu entry point
 * --------------------------------------------------------------------
 * Loads the homepage. All sections are defined in pages/home.php and
 * rendered inside the shared layout (header / footer).
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/config.php';   // index.php lives at project root, so no "../" needed

// Tell header what to render
$pageTitle       = 'Home';
$pageDescription = APP_TAGLINE;
$activePage      = 'home';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/pages/home.php';
require_once __DIR__ . '/includes/footer.php';
