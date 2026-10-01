<?php
// Temporary deployment diagnostic: remove after verifying production assets.
require_once __DIR__ . '/config/config.php';
header('Content-Type: text/plain; charset=utf-8');
$files = ['assets/css/style.css','assets/css/components.css','assets/css/admin.css','assets/js/main.js'];
foreach ($files as $f) {
    echo $f . ' | ' . (is_file(__DIR__.'/'.$f) ? 'OK' : 'MISSING') . " | " . asset_url($f) . "\n";
}
