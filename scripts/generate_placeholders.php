<?php
/**
 * scripts/generate_placeholders.php
 * --------------------------------------------------------------------
 * Creates a consistent set of branded SVG placeholders used throughout
 * the project. Run from the CLI:
 *
 *     php scripts/generate_placeholders.php
 *
 * Outputs go to assets/images/placeholders/. The placeholders are
 * generated locally and shipped with the project so the UI never
 * shows broken images during development.
 * --------------------------------------------------------------------
 */

$dir = __DIR__ . '/../assets/images/placeholders';
if (!is_dir($dir)) mkdir($dir, 0777, true);

// Palette
$brand   = '#14a594';
$brandDk = '#0c8478';
$accent  = '#ec9416';
$light   = '#effcf9';

// A helper: build an SVG with a colored gradient, an icon glyph, and a label.
function svg(string $label, string $icon, string $grad1, string $grad2): string {
    $icon = htmlspecialchars($icon);
    $label = htmlspecialchars($label);
    return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 480 360" width="480" height="360" role="img" aria-label="$label">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="$grad1"/>
      <stop offset="1" stop-color="$grad2"/>
    </linearGradient>
    <pattern id="p" x="0" y="0" width="40" height="40" patternUnits="userSpaceOnUse">
      <path d="M0 40L40 0" stroke="rgba(255,255,255,.07)" stroke-width="2"/>
    </pattern>
  </defs>
  <rect width="480" height="360" fill="url(#g)"/>
  <rect width="480" height="360" fill="url(#p)"/>
  <g fill="none" stroke="#fff" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" transform="translate(180 110)">
    $icon
  </g>
  <text x="240" y="290" text-anchor="middle"
        font-family="Inter, Segoe UI, sans-serif" font-size="22" font-weight="600" fill="#ffffff">$label</text>
</svg>
SVG;
}

$set = [
    'favicon'         => ['Isoko Ryacu', '<circle cx="60" cy="60" r="50"/><path d="M60 30v60M30 60h60"/>', $brand, $brandDk],
    'default'         => ['No image', '<rect x="20" y="20" width="80" height="80" rx="14"/><circle cx="60" cy="50" r="12"/><path d="M30 80l25-22 18 14 16-12"/>', $light, '#d2f5ec'],
    'vehicle'         => ['Vehicle', '<path d="M10 50h100l-15-30H25L10 50z"/><circle cx="32" cy="62" r="10"/><circle cx="88" cy="62" r="10"/>', '#3fc2a5', '#0c8478'],
    'motorcycle'      => ['Motorcycle', '<circle cx="28" cy="62" r="14"/><circle cx="92" cy="62" r="14"/><path d="M28 62h40l-12-22h28M40 40h18"/>', '#3fc2a5', '#0c8478'],
    'phone'           => ['Smartphone', '<rect x="35" y="10" width="50" height="100" rx="10"/><rect x="42" y="22" width="36" height="60" rx="2"/><circle cx="60" cy="95" r="5"/>', '#6fd8c0', '#0ea5a4'],
    'tablet'          => ['Tablet', '<rect x="15" y="10" width="90" height="100" rx="10"/><rect x="22" y="20" width="76" height="72" rx="2"/><circle cx="60" cy="100" r="4"/>', '#6fd8c0', '#0ea5a4'],
    'laptop'          => ['Laptop', '<rect x="15" y="20" width="90" height="60" rx="6"/><rect x="20" y="25" width="80" height="50"/><path d="M5 88h110l8 10H-3z"/>', '#3fc2a5', '#0c8478'],
    'apartment'       => ['Apartment', '<rect x="20" y="20" width="80" height="80"/><rect x="30" y="35" width="14" height="14"/><rect x="56" y="35" width="14" height="14"/><rect x="30" y="60" width="14" height="14"/><rect x="56" y="60" width="14" height="14"/>', '#f5b54a', '#c4730a'],
    'house'           => ['House', '<path d="M20 50L60 15l40 35"/><rect x="30" y="50" width="60" height="50"/><rect x="50" y="70" width="20" height="30"/>', '#f5b54a', '#c4730a'],
    'land'            => ['Land', '<path d="M10 80h100v15H10z"/><path d="M10 70l15-10 15 10 15-15 20 15 15-15 15 15"/><circle cx="80" cy="35" r="10"/>', '#3fc2a5', '#0c8478'],
    'sofa'            => ['Furniture', '<path d="M10 50h100v25H10z"/><path d="M15 50V30h90v20"/><rect x="10" y="75" width="10" height="20"/><rect x="100" y="75" width="10" height="20"/>', '#3fc2a5', '#0c8478'],
    'tent'            => ['Event tent', '<path d="M10 80L60 15l50 65"/><path d="M30 80l30-40 30 40"/>', '#6fd8c0', '#0ea5a4'],
    'agriculture'     => ['Agriculture', '<path d="M60 100V40"/><path d="M60 50c-15-5-25 0-25 15 15 5 25 0 25-15zM60 60c15-5 25 0 25 15-15 5-25 0-25-15z"/>', '#3fc2a5', '#0c8478'],
    'service'         => ['Service', '<circle cx="60" cy="60" r="22"/><path d="M60 30v-10M60 90v-10M30 60h-10M90 60h-10"/>', '#6fd8c0', '#0ea5a4'],
    'blog-safety'     => ['Safety first', '<path d="M60 15l35 15v25c0 25-15 40-35 50-20-10-35-25-35-50V30z"/><path d="M45 60l10 12 25-25"/>', $brand, $brandDk],
    'blog-sell'       => ['Sell smarter', '<rect x="20" y="40" width="80" height="50" rx="6"/><path d="M40 40V20h40v20"/><circle cx="50" cy="65" r="5"/><circle cx="70" cy="65" r="5"/>', '#3fc2a5', '#0c8478'],
    'blog-rent'       => ['Renting guide', '<rect x="20" y="40" width="80" height="60"/><path d="M20 40L60 10l40 30"/><rect x="42" y="60" width="36" height="40"/><path d="M42 75h36M42 85h36"/>', '#f5b54a', '#c4730a'],
    'blog-scam'       => ['Avoid scams', '<path d="M60 15l45 80H15z"/><rect x="55" y="45" width="10" height="30" rx="5"/><circle cx="60" cy="83" r="4"/>', '#ec9416', '#c4730a'],
    'blog-laptop'     => ['Choosing a laptop', '<rect x="15" y="25" width="90" height="55" rx="6"/><rect x="22" y="32" width="76" height="42"/><path d="M5 88h110l8 10H-3z"/>', '#6fd8c0', '#0ea5a4'],
    'blog-default'    => ['Isoko Ryacu blog', '<rect x="15" y="25" width="90" height="55" rx="6"/><path d="M5 88h110l8 10H-3z"/><circle cx="38" cy="48" r="6"/><path d="M30 75l18-18 14 14 16-12 16 16"/>', $brand, $brandDk],
    'avatar-default'  => ['User', '<circle cx="60" cy="45" r="22"/><path d="M22 100c4-22 76-22 80 0"/>', $brand, $brandDk],
];

$count = 0;
foreach ($set as $name => [$label, $icon, $g1, $g2]) {
    $path = $dir . '/' . $name . '.svg';
    file_put_contents($path, svg($label, $icon, $g1, $g2));
    $count++;
    echo "  ✓ $name.svg\n";
}

echo "\nGenerated $count placeholders in $dir\n";
