<?php
/**
 * pages/locations.php
 * --------------------------------------------------------------------
 * Browse the marketplace by LOCATION (Phase 7 — lightweight map view).
 *
 * Listings store location as real text ("Kigali, Kicukiro", "Musanze"…).
 * No GPS coordinates exist in the database — and per the platform rules
 * we do NOT invent them.  Instead this page offers an honest, zero-dependency
 * "location map": pins grouped by REAL Rwandan province, arranged
 * geographically north → south, sized by real listing counts.  Every pin
 * and card links to a real filtered listing view.
 *
 * (Deliberately no external map library, no tiles, no API keys — one page,
 * real data, works offline.)
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/smart_features.php';

// Real location activity (city-level, from actual listings)
$locations = location_activity(20);
if (!$locations) {
    // No location data at all — honest empty page
    $pageTitle = t('locations.title');
    $activePage = 'explore';
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <div class="container section--tight">
        <?php echo back_button(APP_URL . '/', t('buttons.back'), 'solid'); ?>
        <div class="empty-state" style="margin-top:40px;">
            <svg viewBox="0 0 24 24" width="42" height="42" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M12 2a7 7 0 0 1 7 7c0 5-7 13-7 13S5 14 5 9a7 7 0 0 1 7-7z"/><circle cx="12" cy="9" r="2.5"/></svg>
            <h3><?php echo e(t('locations.empty_title')); ?></h3>
            <p><?php echo e(t('locations.empty_sub')); ?></p>
            <a class="btn btn--primary" href="<?php echo APP_URL; ?>/pages/explore.php"><?php echo e(t('search.explore')); ?></a>
        </div>
    </div>
    <?php require_once __DIR__ . '/../includes/footer.php'; exit;
}

// Group real cities by actual Rwandan province (geographic fact, not invention)
$provinceOf = static function (string $city): string {
    $c = mb_strtolower($city);
    if ($c === 'kigali' || str_starts_with($c, 'kigali')) return 'kigali';
    if (in_array($c, ['musanze', 'rubavu', 'gicumbi', 'burera', 'rongi', 'nyabihu'], true)) return 'northern';
    if (in_array($c, ['huye', 'muhanga', 'nyanza', 'gisagara', 'nyaruguru', 'huye', 'nyamagabe', 'kamonyi', 'ruhango'], true)) return 'southern';
    if (in_array($c, ['rwamagana', 'nyagatare', 'gatsibo', 'kayonza', 'kirehe', 'ngoma', 'bugesera'], true)) return 'eastern';
    if (in_array($c, ['karongi', 'rusizi', 'nyamasheke', 'ruhzavu', 'nyabihu', 'ngororero', 'rambura'], true)) return 'western';
    return 'other';
};
$zones = [
    'northern' => ['label' => t('locations.zone_northern'), 'cities' => []],
    'western'  => ['label' => t('locations.zone_western'),  'cities' => []],
    'kigali'   => ['label' => t('locations.zone_kigali'),   'cities' => []],
    'eastern'  => ['label' => t('locations.zone_eastern'),  'cities' => []],
    'southern' => ['label' => t('locations.zone_southern'), 'cities' => []],
    'other'    => ['label' => t('locations.zone_other'),    'cities' => []],
];
foreach ($locations as $loc) {
    $z = $provinceOf((string) $loc['city']);
    $zones[$z]['cities'][] = $loc;
}
$zones = array_filter($zones, static fn ($z) => $z['cities']);
$maxCityCount = max(1, (int) $locations[0]['listing_count']);

// Top listings in the most active location (for the highlight panel)
$topCity = $locations[0]['city'];
$spotStmt = db()->prepare('SELECT l.id, l.title, l.slug, l.price, l.currency, l.listing_type, l.location,
        l.price_per_month, l.price_per_week, l.price_per_day, l.created_at, l.views_count,
        (SELECT image_path FROM listing_images li WHERE li.listing_id = l.id AND li.is_primary = 1 LIMIT 1) AS image
    FROM listings l
    WHERE l.status = "active" AND (l.location LIKE ? OR l.district = ?)
    ORDER BY l.views_count DESC LIMIT 4');
$spotStmt->execute([$topCity . '%', $topCity]);
$spotlight = $spotStmt->fetchAll();

$pageTitle = t('locations.title');
$activePage = 'explore';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container section--tight" style="padding-top:24px;">
    <div style="margin:14px 0 4px;">
        <?php echo back_button(APP_URL . '/', t('buttons.back'), 'solid'); ?>
    </div>
    <div class="section__head" style="margin-bottom:14px;">
        <div>
            <h1 class="section__title" style="font-size:1.6rem;"><?php echo e(t('locations.title')); ?></h1>
            <p class="section__sub" style="margin-top:4px;"><?php echo e(t('locations.sub')); ?></p>
        </div>
        <a class="btn btn--outline" href="<?php echo APP_URL; ?>/pages/explore.php"><?php echo e(t('search.explore')); ?></a>
    </div>

    <!-- ============ LOCATION MAP (province-grouped pin board) ============ -->
    <div class="locmap" role="region" aria-label="<?php echo e(t('locations.map_label')); ?>">
        <div class="locmap__grid">
            <?php foreach ($zones as $key => $zone): ?>
            <div class="locmap__zone locmap__zone--<?php echo e($key); ?>">
                <span class="locmap__zone-label"><?php echo e($zone['label']); ?></span>
                <div class="locmap__pins">
                    <?php foreach ($zone['cities'] as $loc): ?>
                        <?php
                            $size = $loc['listing_count'] >= 6 ? 'lg' : ($loc['listing_count'] >= 3 ? 'md' : 'sm');
                        ?>
                        <a class="locmap__pin locmap__pin--<?php echo $size; ?>"
                           href="<?php echo APP_URL; ?>/pages/explore.php?location=<?php echo urlencode((string) $loc['city']); ?>"
                           title="<?php echo e($loc['city']); ?> — <?php echo (int) $loc['listing_count']; ?> <?php echo e(t('common.listings')); ?>">
                            <svg viewBox="0 0 24 24" width="<?php echo $size === 'lg' ? 16 : 13; ?>" height="<?php echo $size === 'lg' ? 16 : 13; ?>" aria-hidden="true"><path d="M12 2a7 7 0 0 1 7 7c0 5-7 13-7 13S5 14 5 9a7 7 0 0 1 7-7z" fill="currentColor" stroke="#fff" stroke-width="1.2"/><circle cx="12" cy="9" r="2.6" fill="#fff"/></svg>
                            <b><?php echo e($loc['city']); ?></b>
                            <i><?php echo (int) $loc['listing_count']; ?></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="locmap__note"><?php echo e(t('locations.map_note')); ?></p>
    </div>

    <!-- ============ LOCATION CARDS (real stats) ============ -->
    <div class="section__head" style="margin-top:28px;">
        <h2 class="section__title"><?php echo e(t('locations.browse_cities')); ?></h2>
    </div>
    <div class="grid grid--3 grid--auto">
        <?php foreach ($locations as $loc): ?>
            <?php
                $avg = (float) $loc['avg_price'];
                $pct = (int) round(100 * (int) $loc['listing_count'] / $maxCityCount);
            ?>
            <a class="card card--hover loc-card" href="<?php echo APP_URL; ?>/pages/explore.php?location=<?php echo urlencode((string) $loc['city']); ?>">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span class="loc-card__icon">
                        <svg viewBox="0 0 24 24" width="18" height="18"><path d="M12 2a7 7 0 0 1 7 7c0 5-7 13-7 13S5 14 5 9a7 7 0 0 1 7-7z" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="9" r="2.5" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>
                    </span>
                    <div style="min-width:0;">
                        <h3 style="margin:0;font-size:16px;"><?php echo e($loc['city']); ?></h3>
                        <span class="text-mute" style="font-size:12.5px;"><?php echo e(t('locations.view_listings')); ?> →</span>
                    </div>
                    <b style="margin-left:auto;font-size:15px;color:var(--brand-700);"><?php echo (int) $loc['listing_count']; ?></b>
                </div>
                <div class="loc-card__bar"><span style="width:<?php echo $pct; ?>%"></span></div>
                <div class="loc-card__meta">
                    <span>👁 <?php echo number_format((int) $loc['views']); ?></span>
                    <span>❤ <?php echo number_format((int) $loc['favorites']); ?></span>
                    <?php if ($avg > 0): ?><span>· <?php echo e(t('locations.avg_price')); ?> <?php echo format_price($avg); ?></span><?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- ============ SPOTLIGHT: most active location ============ -->
    <?php if ($spotlight): ?>
    <div style="margin-top:40px;">
        <div class="section__head">
            <h2 class="section__title"><?php echo e(t('locations.spotlight', ['city' => $topCity])); ?></h2>
            <a class="section__link" href="<?php echo APP_URL; ?>/pages/explore.php?location=<?php echo urlencode($topCity); ?>"><?php echo e(t('sections.view_all')); ?> →</a>
        </div>
        <div class="grid grid--4 grid--auto">
            <?php foreach ($spotlight as $s): ?>
                <a class="listing-card" href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int) $s['id']; ?>">
                    <div class="listing-card__media">
                        <img loading="lazy" src="<?php echo image_or_default($s['image']); ?>" alt="">
                    </div>
                    <div class="listing-card__body">
                        <div class="listing-card__price"><?php echo $s['listing_type'] === 'rent' ? e(t('listings.from')) . ' ' . format_price($s['price_per_month'] ?? $s['price_per_week'] ?? $s['price_per_day'] ?? 0, $s['currency']) . e(t('listings.per_month')) : e(format_price($s['price'], $s['currency'])); ?></div>
                        <div class="listing-card__title"><?php echo e($s['title']); ?></div>
                        <div class="listing-card__meta"><span><?php echo e($s['location'] ?? 'Rwanda'); ?></span><span><?php echo time_ago($s['created_at']); ?></span></div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
