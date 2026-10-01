<?php
/**
 * pages/admin/analytics.php
 * --------------------------------------------------------------------
 * IsokoRyacu reports & analytics (Phase 5).
 *
 * Every chart is built from REAL database rows. When a chart has no data
 * in the selected period, it renders an honest empty state.
 *
 * Charts:
 *   1. New listings over time (bar chart)   — period filter aware
 *   2. New users over time (SVG line chart) — period filter aware
 *   3. Listings by category (progress bars)
 *   4. Reports by status (donut + legend)
 *   5. Reviews activity + average rating (KPIs)
 *   6. Top viewed listings (real listing images)
 *   7. Activity by location (Phase 4/7 — real listing locations)
 *   8. Listing Quality Score overview (Phase 3 — real distribution)
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_icons.php';
require_once __DIR__ . '/../../includes/admin_ui.php';
require_once __DIR__ . '/../../includes/smart_features.php';
require_role('admin');

$pdo = db();

/* ---------- Period filter ---------- */
$periods = ['today' => 0, '7d' => 6, '30d' => 29, '365d' => 364];
$periodKey = $_GET['period'] ?? '30d';
if (!array_key_exists($periodKey, $periods)) $periodKey = '30d';
$daysBack = $periods[$periodKey];
$days = $daysBack + 1;

/** Fill a per-day series with zeroed missing days. */
function admin_daily_series(string $sql, array $params, int $daysBack): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $map = [];
    foreach ($stmt->fetchAll() as $r) $map[$r['d']] = (int) $r['n'];
    $out = [];
    for ($i = $daysBack; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $out[] = ['d' => $d, 'n' => $map[$d] ?? 0];
    }
    return $out;
}

$listSeries = admin_daily_series(
    "SELECT DATE(created_at) d, COUNT(*) n FROM listings WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY) GROUP BY DATE(created_at)",
    [$daysBack], $daysBack);
$userSeries = admin_daily_series(
    "SELECT DATE(created_at) d, COUNT(*) n FROM users WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY) GROUP BY DATE(created_at)",
    [$daysBack], $daysBack);

$listTotal = array_sum(array_column($listSeries, 'n'));
$userTotal = array_sum(array_column($userSeries, 'n'));
$listMax   = max(1, max(array_column($listSeries, 'n') ?: [1]));
$userMax   = max(1, max(array_column($userSeries, 'n') ?: [1]));

/* SVG line chart geometry for the users series */
$W = 600; $H = 190; $padX = 10; $padTop = 16; $padBottom = 26;
$innerW = $W - 2 * $padX;
$innerH = $H - $padTop - $padBottom;
$points = [];
foreach ($userSeries as $i => $p) {
    $x = $padX + ($days > 1 ? ($i / ($days - 1)) * $innerW : $innerW / 2);
    $y = $padTop + $innerH - ($p['n'] / $userMax) * $innerH;
    $points[] = [$x, $y, $p];
}
$polyline  = implode(' ', array_map(static fn ($pt) => round($pt[0], 1) . ',' . round($pt[1], 1), $points));
$polylinePoints = implode(' L', array_map(static fn ($pt) => round($pt[0], 1) . ',' . round($pt[1], 1), $points));
$areaPath  = 'M' . $padX . ',' . ($padTop + $innerH) . ' L' . $polylinePoints . ' L' . ($W - $padX) . ',' . ($padTop + $innerH) . ' Z';
$dots = '';
foreach (array_filter($points, static fn ($pt) => $pt[2]['n'] > 0) as $pt) {
    $dots .= '<circle class="data-dot" cx="' . round($pt[0], 1) . '" cy="' . round($pt[1], 1) . '" r="3.4"><title>' . $pt[2]['n'] . ' — ' . $pt[2]['d'] . '</title></circle>';
}
$labelStep = max(1, (int) ceil($days / 10));

/* Category distribution */
$categories = $pdo->query("SELECT c.id, c.name, c.name_key, COUNT(l.id) n
    FROM categories c LEFT JOIN listings l ON l.category_id = c.id
    WHERE c.parent_id IS NULL AND c.is_active = 1
    GROUP BY c.id, c.name, c.name_key ORDER BY n DESC LIMIT 8")->fetchAll();
$maxCat = max(1, (int) ($categories[0]['n'] ?? 0));
$catTotal = array_sum(array_column($categories, 'n'));

/* Reports by status (donut) */
$repCounts = ['open' => 0, 'reviewing' => 0, 'resolved' => 0, 'dismissed' => 0];
foreach ($pdo->query("SELECT status, COUNT(*) n FROM reports GROUP BY status") as $r) {
    if (isset($repCounts[$r['status']])) $repCounts[$r['status']] = (int) $r['n'];
}
$repTotal = array_sum($repCounts);
$donut = '';
$offset = 0;
$donutColors = ['open' => 'var(--a-red)', 'reviewing' => 'var(--a-amber)', 'resolved' => 'var(--a-green)', 'dismissed' => 'var(--a-muted)'];
foreach ($repCounts as $st => $n) {
    if ($repTotal === 0 || $n === 0) continue;
    $pct = $n / $repTotal * 100;
    $donut .= "$donutColors[$st] $offset% " . ($offset + $pct) . '%,';
    $offset += $pct;
}
$donut = rtrim($donut, ',') ?: 'var(--a-card-soft) 0 100%';

/* Reviews */
$reviewCount = (int) $pdo->query("SELECT COUNT(*) FROM reviews")->fetchColumn();
$avgRating   = (float) $pdo->query("SELECT COALESCE(AVG(rating),0) FROM reviews")->fetchColumn();
$distRows = $pdo->query("SELECT rating, COUNT(*) n FROM reviews GROUP BY rating ORDER BY rating DESC")->fetchAll();
$dist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
foreach ($distRows as $r) $dist[(int) $r['rating']] = (int) $r['n'];
$distMax = max(1, ...array_values($dist));

/* Top viewed listings (real images) */
$topListings = $pdo->query("SELECT l.id, l.title, l.views_count, l.favorites_count, l.price, l.currency,
    (SELECT image_path FROM listing_images li WHERE li.listing_id=l.id AND li.is_primary=1 LIMIT 1) image,
    c.name category_name, c.name_key category_name_key
    FROM listings l JOIN categories c ON c.id=l.category_id
    ORDER BY l.views_count DESC LIMIT 5")->fetchAll();

/* Global KPIs */
$views     = (int) $pdo->query("SELECT COALESCE(SUM(views_count),0) FROM listings")->fetchColumn();
$favorites = (int) $pdo->query("SELECT COALESCE(SUM(favorites_count),0) FROM listings")->fetchColumn();
$sellCount = (int) $pdo->query("SELECT COUNT(*) FROM listings WHERE listing_type='sell'")->fetchColumn();
$rentCount = (int) $pdo->query("SELECT COUNT(*) FROM listings WHERE listing_type='rent'")->fetchColumn();
$totalValue= (float) $pdo->query("SELECT COALESCE(SUM(price),0) FROM listings WHERE status='active'")->fetchColumn();

/* Activity by location (Phase 4/7 — real listing locations) */
$locations = location_activity(8);
$maxLocCount = max(1, (int) ($locations[0]['listing_count'] ?? 0));

/* Listing Quality Score distribution (Phase 3 — real per-listing scores) */
$qualityRows = $pdo->query("SELECT l.title, l.description, l.listing_type, l.price, l.location,
        l.subcategory_id, l.price_per_month, l.price_per_week, l.price_per_day,
        (SELECT COUNT(*) FROM listing_images li WHERE li.listing_id = l.id) AS images_count,
        (SELECT COUNT(*) FROM listing_attributes la WHERE la.listing_id = l.id) AS attrs_count,
        u.phone AS seller_phone
    FROM listings l INNER JOIN users u ON u.id = l.seller_id")->fetchAll();
$qBuckets = ['excellent' => 0, 'good' => 0, 'fair' => 0, 'poor' => 0];
$qSum = 0;
foreach ($qualityRows as $qr) {
    $qs = listing_quality_score($qr);
    $qBuckets[$qs['verdict']]++;
    $qSum += $qs['score'];
}
$qTotal = count($qualityRows);
$qAvg = $qTotal ? (int) round($qSum / $qTotal) : 0;
$qMax = max(1, ...array_values($qBuckets));

/* ---------- Page setup ---------- */
$pageTitle         = t('admin.nav_analytics');
$activePage        = 'admin';
$adminActivePage   = 'analytics';
$extraCss          = '<link rel="stylesheet" href="' . asset_url('assets/css/admin.css') . '">';
$adminPageTitle    = t('admin.nav_analytics');
$adminPageSubtitle = t('admin.analytics_page_sub');
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="a-shell" id="aShell">
<?php require __DIR__ . '/../../includes/admin_sidebar.php'; ?>
<div class="a-main">
<?php require __DIR__ . '/../../includes/admin_topbar.php'; ?>
<div class="a-content">

<div class="a-hero">
    <div>
        <h2><?php echo e(t('admin.nav_analytics')); ?></h2>
        <p class="a-hero__sub"><?php echo e(t('admin.analytics_page_sub')); ?></p>
    </div>
    <div class="a-hero__actions">
        <div class="a-filters">
            <a class="a-chip <?php echo $periodKey === 'today' ? 'is-active' : ''; ?>" href="?period=today"><?php echo e(t('admin.period_today')); ?></a>
            <a class="a-chip <?php echo $periodKey === '7d' ? 'is-active' : ''; ?>" href="?period=7d"><?php echo e(t('admin.period_7d')); ?></a>
            <a class="a-chip <?php echo $periodKey === '30d' ? 'is-active' : ''; ?>" href="?period=30d"><?php echo e(t('admin.period_30d')); ?></a>
            <a class="a-chip <?php echo $periodKey === '365d' ? 'is-active' : ''; ?>" href="?period=365d"><?php echo e(t('admin.period_year')); ?></a>
        </div>
    </div>
</div>

<!-- Global KPIs -->
<div class="a-kpis">
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label"><?php echo e(t('admin.total_views')); ?></span><span class="a-kpi__icon a-kpi__icon--blue"><?php echo admin_icon('eye'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($views); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo e(t('admin.across_all_listings')); ?></span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label"><?php echo e(t('admin.stat_favorites')); ?></span><span class="a-kpi__icon a-kpi__icon--red"><?php echo admin_icon('heart'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($favorites); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo e(t('admin.saved_listings')); ?></span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label"><?php echo e(t('admin.revenue_label')); ?></span><span class="a-kpi__icon a-kpi__icon--green"><?php echo admin_icon('dollar'); ?></span></div>
        <div class="a-kpi__value" style="font-size:21px;"><?php echo e(format_price($totalValue, 'RWF')); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo e(t('admin.revenue_sub')); ?></span></div>
    </div>
    <div class="a-kpi">
        <div class="a-kpi__top"><span class="a-kpi__label"><?php echo e(t('admin.sell_rent_split')); ?></span><span class="a-kpi__icon a-kpi__icon--orange"><?php echo admin_icon('trend-flat'); ?></span></div>
        <div class="a-kpi__value"><?php echo number_format($sellCount); ?> / <?php echo number_format($rentCount); ?></div>
        <div class="a-kpi__meta"><span class="a-kpi__hint"><?php echo e(t('listings.for_sale')); ?> · <?php echo e(t('listings.for_rent')); ?></span></div>
    </div>
</div>

<!-- Listings over time -->
<section class="a-panel">
    <div class="a-panel__head">
        <div>
            <h3><?php echo e(t('admin.chart_listings_title')); ?></h3>
            <p><?php echo e(t('admin.chart_period_sub', ['count' => number_format($listTotal), 'days' => $days])); ?></p>
        </div>
    </div>
    <?php if ($listTotal === 0): ?>
        <?php echo admin_empty_state('analytics', t('admin.empty_no_data_title'), t('admin.empty_analytics_sub')); ?>
    <?php else: ?>
    <div class="a-chartbar-zone" style="height:<?php echo $days > 60 ? 190 : 215; ?>px;">
        <?php $step = $days > 14 ? max(1, (int) ceil($days / 14)) : 1; ?>
        <?php foreach ($listSeries as $i => $p): ?>
            <?php $h = $p['n'] ? max(6, round(($p['n'] / $listMax) * 100)) : 2.5; ?>
            <div class="a-chartbar-col">
                <div class="a-chartbar" style="height:<?php echo $h; ?>%" title="<?php echo (int) $p['n']; ?> — <?php echo e($p['d']); ?>"><span class="a-chartbar__value"><?php echo (int) $p['n']; ?></span></div>
                <span class="a-chartbar-label"><?php echo ($i % $step === 0 || $i === $days - 1) ? e(date($days > 70 ? 'M' : 'd', strtotime($p['d']))) : ''; ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<!-- Users over time + reports donut -->
<div class="a-grid">
    <section class="a-panel">
        <div class="a-panel__head">
            <div>
                <h3><?php echo e(t('admin.chart_users_title')); ?></h3>
                <p><?php echo e(t('admin.chart_period_sub', ['count' => number_format($userTotal), 'days' => $days])); ?></p>
            </div>
        </div>
        <?php if ($userTotal === 0): ?>
            <?php echo admin_empty_state('users', t('admin.empty_no_data_title'), t('admin.empty_users_sub')); ?>
        <?php else: ?>
        <svg class="a-linechart" viewBox="0 0 <?php echo $W; ?> <?php echo $H; ?>" preserveAspectRatio="none" role="img" aria-label="<?php echo e(t('admin.chart_users_title')); ?>">
            <defs>
                <linearGradient id="aLineGrad" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#17b3a8" stop-opacity=".28"/>
                    <stop offset="100%" stop-color="#17b3a8" stop-opacity="0"/>
                </linearGradient>
            </defs>
            <?php for ($g = 0; $g <= 3; $g++): ?>
                <line class="grid-line" x1="<?php echo $padX; ?>" x2="<?php echo $W - $padX; ?>" y1="<?php echo $padTop + ($innerH / 3) * $g; ?>" y2="<?php echo $padTop + ($innerH / 3) * $g; ?>"/>
            <?php endfor; ?>
            <path class="data-area" d="<?php echo e($areaPath); ?>"/>
            <polyline class="data-line" points="<?php echo e($polyline); ?>"/>
            <?php echo $dots; ?>
        </svg>
        <div style="display:flex; justify-content:space-between; padding:4px 10px 0;">
            <span class="a-chartbar-label"><?php echo e(date('M j', strtotime($userSeries[0]['d']))); ?></span>
            <span class="a-chartbar-label"><?php echo e(date('M j', strtotime(end($userSeries)['d']))); ?></span>
        </div>
        <?php endif; ?>
    </section>

    <section class="a-panel">
        <div class="a-panel__head">
            <div>
                <h3><?php echo e(t('admin.chart_reports_title')); ?></h3>
                <p><?php echo e(t('admin.chart_reports_sub')); ?></p>
            </div>
        </div>
        <?php if ($repTotal === 0): ?>
            <?php echo admin_empty_state('check-circle', t('admin.empty_no_reports'), t('admin.empty_no_reports_sub')); ?>
        <?php else: ?>
        <div class="a-charts">
            <div class="a-donut" style="background:conic-gradient(<?php echo e($donut); ?>);">
                <div class="a-donut__center"><strong><?php echo number_format($repTotal); ?></strong><span><?php echo e(t('admin.nav_reports')); ?></span></div>
            </div>
            <div class="a-legend">
                <?php foreach ($repCounts as $st => $n): ?>
                <div class="a-legend__item"><i style="background:<?php echo e($donutColors[$st]); ?>"></i><?php echo e(t('admin.status_' . $st)); ?><b><?php echo number_format($n); ?></b></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </section>
</div>

<!-- Category distribution + reviews -->
<div class="a-grid">
    <section class="a-panel">
        <div class="a-panel__head">
            <div>
                <h3><?php echo e(t('admin.panel_top_categories')); ?></h3>
                <p><?php echo number_format($catTotal); ?> <?php echo e(t('admin.listings_count')); ?></p>
            </div>
            <a class="a-panel__link" href="<?php echo APP_URL; ?>/pages/admin/categories.php"><?php echo e(t('admin.manage')); ?></a>
        </div>
        <?php if (!$categories): ?>
            <?php echo admin_empty_state('categories', t('admin.empty_no_categories')); ?>
        <?php else: foreach ($categories as $cat): $pct = min(100, round(((int) $cat['n'] / $maxCat) * 100)); ?>
            <div class="a-progress">
                <div class="a-progress__top"><span><?php echo e(t_category($cat['name_key'] ?? null, $cat['name'])); ?></span><span><?php echo (int) $cat['n']; ?></span></div>
                <div class="a-progress__track"><span class="a-progress__fill" style="width:<?php echo $pct; ?>%"></span></div>
            </div>
        <?php endforeach; endif; ?>
    </section>

    <section class="a-panel">
        <div class="a-panel__head">
            <div>
                <h3><?php echo e(t('admin.chart_reviews_title')); ?></h3>
                <p><?php echo $reviewCount > 0 ? e(t('admin.avg_rating')) . ' <b>' . number_format($avgRating, 1) . '/5</b>' : e(t('admin.empty_no_reviews')); ?></p>
            </div>
            <a class="a-panel__link" href="<?php echo APP_URL; ?>/pages/admin/reviews.php"><?php echo e(t('admin.manage')); ?></a>
        </div>
        <?php if ($reviewCount === 0): ?>
            <?php echo admin_empty_state('reviews', t('admin.empty_no_reviews'), t('admin.empty_no_reviews_sub')); ?>
        <?php else: ?>
            <?php foreach ($dist as $stars => $n): ?>
            <div class="a-progress">
                <div class="a-progress__top">
                    <span class="a-stars"><?php for ($s = 1; $s <= 5; $s++): ?><svg viewBox="0 0 24 24" fill="<?php echo $s <= $stars ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="1.6"><path d="M12 3.5 14.3 8l5 .7-3.6 3.5.9 5-4.6-2.4-4.6 2.4.9-5L4.7 8.7l5-.7z"/></svg><?php endfor; ?></span>
                    <span><?php echo (int) $n; ?></span>
                </div>
                <div class="a-progress__track"><span class="a-progress__fill a-progress__fill--accent" style="width:<?php echo round(($n / $distMax) * 100); ?>%"></span></div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</div>

<!-- Top viewed listings -->
<section class="a-panel">
    <div class="a-panel__head">
        <div>
            <h3><?php echo e(t('admin.panel_top_products')); ?></h3>
            <p><?php echo e(t('admin.panel_top_products_sub')); ?></p>
        </div>
        <a class="a-panel__link" href="<?php echo APP_URL; ?>/pages/admin/listings.php"><?php echo e(t('admin.view_all')); ?> →</a>
    </div>
    <?php if (!$topListings): ?>
        <?php echo admin_empty_state('image', t('admin.empty_no_listings')); ?>
    <?php else: ?>
    <div class="a-tablewrap" style="border:0;">
        <table class="a-table" style="min-width:0;">
            <tbody>
            <?php foreach ($topListings as $l): ?>
                <tr>
                    <td style="width:54px;"><img class="a-table__thumb" loading="lazy" src="<?php echo e(image_or_default($l['image'] ?? null)); ?>" alt=""></td>
                    <td data-label="<?php echo e(t('admin.col_title')); ?>">
                        <span class="a-table__listing-title"><a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int) $l['id']; ?>"><?php echo e($l['title']); ?></a></span>
                        <span class="a-table__listing-sub"><?php echo e(t_category($l['name_key'] ?? null, $l['category_name'])); ?></span>
                    </td>
                    <td data-label="<?php echo e(t('listings.detail.views')); ?>" class="cell-num cell-primary"><?php echo number_format((int) $l['views_count']); ?> <?php echo e(t('blog.views')); ?></td>
                    <td data-label="<?php echo e(t('admin.stat_favorites')); ?>" class="cell-num"><?php echo number_format((int) $l['favorites_count']); ?></td>
                    <td data-label="<?php echo e(t('admin.col_price')); ?>" class="cell-num"><?php echo e(format_price($l['price'], $l['currency'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<!-- Activity by location + Listing Quality overview (Phases 3/4/7) -->
<div class="a-grid">
    <section class="a-panel">
        <div class="a-panel__head">
            <div>
                <h3><?php echo e(t('admin.panel_location_activity')); ?></h3>
                <p><?php echo e(t('admin.panel_location_sub')); ?></p>
            </div>
            <a class="a-panel__link" href="<?php echo APP_URL; ?>/pages/locations.php"><?php echo e(t('admin.view_all')); ?> &rarr;</a>
        </div>
        <?php if (!$locations): ?>
            <?php echo admin_empty_state('image', t('admin.empty_no_locations'), t('admin.empty_no_locations_sub')); ?>
        <?php else: ?>
            <?php foreach ($locations as $loc): $pct = (int) round(100 * (int) $loc['listing_count'] / $maxLocCount); ?>
            <div class="a-loc__row">
                <span class="a-loc__name">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a7 7 0 0 1 7 7c0 5-7 13-7 13S5 14 5 9a7 7 0 0 1 7-7z" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="9" r="2.5" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>
                    <?php echo e($loc['city']); ?>
                </span>
                <span class="a-loc__bar"><span style="width:<?php echo $pct; ?>%"></span></span>
                <span class="a-loc__meta"><?php echo (int) $loc['listing_count']; ?> <?php echo e(t('common.listings')); ?> &middot; <?php echo number_format((int) $loc['views']); ?> <?php echo e(t('listings.views_count')); ?></span>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <section class="a-panel">
        <div class="a-panel__head">
            <div>
                <h3><?php echo e(t('admin.panel_quality_title')); ?></h3>
                <p><?php echo e(t('admin.panel_quality_sub', ['avg' => $qAvg, 'count' => $qTotal])); ?></p>
            </div>
            <a class="a-panel__link" href="<?php echo APP_URL; ?>/pages/admin/listings.php"><?php echo e(t('admin.manage')); ?> &rarr;</a>
        </div>
        <?php if ($qTotal === 0): ?>
            <?php echo admin_empty_state('image', t('admin.empty_no_listings')); ?>
        <?php else: foreach ($qBuckets as $verdict => $n): if ($n === 0) continue; $pctq = (int) round(100 * $n / $qMax); ?>
            <div class="a-progress">
                <div class="a-progress__top">
                    <span><?php echo e(quality_verdict_label($verdict)); ?></span><span><?php echo (int) $n; ?></span>
                </div>
                <div class="a-progress__track"><span class="a-progress__fill" style="width:<?php echo $pctq; ?>%"></span></div>
            </div>
            <?php endforeach; ?>
            <p style="margin:14px 0 0;font-size:12.5px;color:var(--a-text-soft);">
                <?php echo e(t('admin.panel_quality_legend')); ?>
            </p>
        <?php endif; ?>
    </section>
</div>

</div><!-- /a-content -->
<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>
