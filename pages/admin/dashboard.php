<?php
/**
 * pages/admin/dashboard.php
 * --------------------------------------------------------------------
 * IsokoRyacu admin control center (Phase 5).
 * EVERY number, chart and widget on this page is computed live from the
 * real database — no fabricated data, no fake trends. When a section has
 * no data it renders a proper empty state instead of an empty box.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_icons.php';
require_once __DIR__ . '/../../includes/smart_features.php';
require_role('admin');

$pdo  = db();
$user = current_user();
$firstName = explode(' ', trim($user['full_name'] ?? 'Admin'))[0] ?: 'Admin';

/* ============ MARKETPLACE HEALTH (Phase 5 — real weighted score) ===== */
$health = marketplace_health();
$healthTone = ['excellent' => 'var(--a-green)', 'good' => 'var(--a-teal-deep)', 'fair' => 'var(--a-amber)', 'poor' => 'var(--a-red)'][$health['verdict']] ?? 'var(--a-teal)';
$hr = 48; $hcirc = 2 * M_PI * $hr;

/* ============ HERO GREETING (time-of-day, Africa/Kigali) ============ */
$hour = (int) date('G');
if ($hour < 12)      $greeting = t('admin.greet_morning');
elseif ($hour < 17)  $greeting = t('admin.greet_afternoon');
else                 $greeting = t('admin.greet_evening');

/* ============ KPI SECTION — all real counts ========================= */
$stats = [
    'users'          => (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'listings'       => (int) $pdo->query("SELECT COUNT(*) FROM listings")->fetchColumn(),
    'active_listings'=> (int) $pdo->query("SELECT COUNT(*) FROM listings WHERE status='active' AND availability='available'")->fetchColumn(),
    'pending'        => (int) $pdo->query("SELECT COUNT(*) FROM listings WHERE status='pending'")->fetchColumn(),
    'rentals'        => (int) $pdo->query("SELECT COUNT(*) FROM rental_requests")->fetchColumn(),
    'rentals_pending'=> (int) $pdo->query("SELECT COUNT(*) FROM rental_requests WHERE status='pending'")->fetchColumn(),
    'reviews'        => (int) $pdo->query("SELECT COUNT(*) FROM reviews")->fetchColumn(),
    'reports_open'   => (int) $pdo->query("SELECT COUNT(*) FROM reports WHERE status IN ('open','reviewing')")->fetchColumn(),
    'messages'       => (int) $pdo->query("SELECT COUNT(*) FROM contact_requests")->fetchColumn(),
    'unread'         => (int) $pdo->query("SELECT COUNT(*) FROM contact_requests WHERE is_read=0")->fetchColumn(),
    'views'          => (int) $pdo->query("SELECT COALESCE(SUM(views_count),0) FROM listings")->fetchColumn(),
    'favorites'      => (int) $pdo->query("SELECT COALESCE(SUM(favorites_count),0) FROM listings")->fetchColumn(),
    'avg_rating'     => (float) $pdo->query("SELECT COALESCE(AVG(rating),0) FROM reviews")->fetchColumn(),
];

/* Real trend deltas: last 7 days vs the 7 days before that. */
function admin_delta_7d(string $table): array
{
    $row = db()->query("SELECT
        SUM(created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY))  AS this_week,
        SUM(created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
            AND created_at < DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS prev_week
        FROM $table")->fetch();
    $now  = (int) ($row['this_week'] ?? 0);
    $prev = (int) ($row['prev_week'] ?? 0);
    if ($prev > 0)  return ['n' => $now, 'dir' => $now >= $prev ? 'up' : 'down', 'pct' => round((($now - $prev) / $prev) * 100)];
    return ['n' => $now, 'dir' => $now > 0 ? 'up' : 'flat', 'pct' => $now > 0 ? 100 : 0];
}
$deltaUsers    = admin_delta_7d('users');
$deltaListings = admin_delta_7d('listings');
$deltaReviews  = admin_delta_7d('reviews');
$deltaMessages = admin_delta_7d('contact_requests');

/** KPI trend chip HTML (real data only). */
function admin_delta_chip(array $d): string
{
    if ($d['n'] === 0 && $d['dir'] === 'flat') return '';
    $cls = $d['dir'] === 'up' ? '' : ($d['dir'] === 'down' ? ' a-kpi__delta--down' : ' a-kpi__delta--flat');
    $icon = $d['dir'] === 'up' ? 'trend-up' : ($d['dir'] === 'down' ? 'trend-down' : 'trend-flat');
    return '<span class="a-kpi__delta' . $cls . '">' . admin_icon($icon) . '+'
         . (int) $d['n'] . '</span>';
}

/* ============ ANALYTICS — period filter (real data) ================== */
$periods = [
    'today' => 0, '7d' => 6, '30d' => 29, '365d' => 364,
];
$periodKey = $_GET['period'] ?? '7d';
if (!array_key_exists($periodKey, $periods)) $periodKey = '7d';
$daysBack = $periods[$periodKey];
$periodDays = $daysBack + 1;

// New listings per day (fills missing days with 0 — honest chart)
$rows = $pdo->prepare("SELECT DATE(created_at) d, COUNT(*) n FROM listings
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY) GROUP BY DATE(created_at)");
$rows->execute([$daysBack]);
$map = [];
foreach ($rows->fetchAll() as $r) $map[$r['d']] = (int) $r['n'];
$series = [];
$maxN = 1;
for ($i = $daysBack; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $n = $map[$d] ?? 0;
    $maxN = max($maxN, $n);
    $series[] = ['d' => $d, 'n' => $n];
}
$totalNewListings = array_sum(array_column($series, 'n'));

// New users + new messages within the period (real counts)
$usersInPeriodStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)");
$usersInPeriodStmt->execute([$daysBack]);
$usersInPeriod = (int) $usersInPeriodStmt->fetchColumn();
$messagesInPeriodStmt = $pdo->prepare("SELECT COUNT(*) FROM contact_requests WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)");
$messagesInPeriodStmt->execute([$daysBack]);
$messagesInPeriod = (int) $messagesInPeriodStmt->fetchColumn();
$rentalsInPeriodStmt = $pdo->prepare("SELECT COUNT(*) FROM rental_requests WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)");
$rentalsInPeriodStmt->execute([$daysBack]);
$rentalsInPeriod = (int) $rentalsInPeriodStmt->fetchColumn();

// Category distribution (active listings only)
$categories = $pdo->query("SELECT c.id, c.name, c.name_key, COUNT(l.id) listing_count
    FROM categories c LEFT JOIN listings l ON l.category_id=c.id AND l.status='active'
    WHERE c.parent_id IS NULL AND c.is_active=1
    GROUP BY c.id, c.name, c.name_key ORDER BY listing_count DESC LIMIT 6")->fetchAll();
$maxCategory = max(1, (int) ($categories[0]['listing_count'] ?? 0));

/* ============ WIDGET DATA ============================================ */
// Recent listings (with real images + owner + status)
$recentListings = $pdo->query("SELECT l.id,l.title,l.price,l.currency,l.location,l.availability,l.status,l.listing_type,l.created_at,l.views_count,
    (SELECT image_path FROM listing_images li WHERE li.listing_id=l.id AND li.is_primary=1 LIMIT 1) image,
    c.name category_name,c.name_key category_name_key,u.full_name seller_name
    FROM listings l JOIN categories c ON c.id=l.category_id JOIN users u ON u.id=l.seller_id
    ORDER BY l.created_at DESC LIMIT 5")->fetchAll();

// Pending approvals
$pending = $pdo->query("SELECT l.id,l.title,l.price,l.currency,l.created_at,u.full_name seller_name,c.name category_name
    FROM listings l JOIN users u ON u.id=l.seller_id JOIN categories c ON c.id=l.category_id
    WHERE l.status='pending' ORDER BY l.created_at DESC LIMIT 4")->fetchAll();

// Recent users
$recentUsers = $pdo->query("SELECT u.id,u.full_name,u.email,u.status,u.created_at,u.is_verified,r.name role_name
    FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.created_at DESC LIMIT 5")->fetchAll();

// Recent messages (unread first)
$messages = $pdo->query("SELECT c.id,c.name,c.message,c.created_at,c.is_read,l.title listing_title,l.id listing_id
    FROM contact_requests c JOIN listings l ON l.id=c.listing_id
    ORDER BY c.is_read ASC, c.created_at DESC LIMIT 4")->fetchAll();

// Rental requests (pending first)
$rentals = $pdo->query("SELECT rr.id, rr.start_date, rr.end_date, rr.status, rr.created_at,
    u.full_name renter_name, l.title listing_title
    FROM rental_requests rr JOIN users u ON u.id=rr.renter_id JOIN listings l ON l.id=rr.listing_id
    ORDER BY FIELD(rr.status,'pending','approved','declined','cancelled','completed'), rr.created_at DESC LIMIT 4")->fetchAll();

// Open reports
$reports = $pdo->query("SELECT r.id,r.reason,r.status,r.created_at,l.title listing_title
    FROM reports r LEFT JOIN listings l ON l.id=r.listing_id
    WHERE r.status IN ('open','reviewing') ORDER BY r.created_at DESC LIMIT 4")->fetchAll();

/* ============ PAGE SETUP ============================================= */
$pageTitle        = t('admin.dash_title');
$pageDescription  = t('admin.dash_sub');
$activePage       = 'admin';
$adminActivePage  = 'dashboard';
$extraCss         = '<link rel="stylesheet" href="' . asset_url('assets/css/admin.css') . '">';
$adminPageTitle   = t('admin.nav_overview');
$adminPageSubtitle= t('admin.dash_sub');
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="a-shell" id="aShell">
<?php require __DIR__ . '/../../includes/admin_sidebar.php'; ?>
<div class="a-main">
<?php require __DIR__ . '/../../includes/admin_topbar.php'; ?>
<div class="a-content">

<!-- ================= GREETING HERO ================= -->
<section class="a-greeting">
    <h2><?php echo e($greeting); ?>, <em><?php echo e($firstName); ?></em></h2>
    <p><?php echo e(t('admin.dash_hero_sub')); ?></p>
    <div class="a-greeting__actions">
        <a class="a-btn a-btn--accent" href="<?php echo APP_URL; ?>/pages/sell.php"><?php echo admin_icon('plus'); ?><span><?php echo e(t('admin.quick_add_listing')); ?></span></a>
        <?php if ($stats['pending'] > 0): ?>
        <a class="a-btn a-btn--glass" href="<?php echo APP_URL; ?>/pages/admin/listings.php?status=pending"><?php echo admin_icon('check-circle'); ?><span><?php echo e(t('admin.review_pending', ['count' => $stats['pending']])); ?></span></a>
        <?php endif; ?>
        <a class="a-btn a-btn--glass" href="<?php echo APP_URL; ?>/pages/admin/analytics.php"><?php echo admin_icon('analytics'); ?><span><?php echo e(t('admin.nav_analytics')); ?></span></a>
        <a class="a-btn a-btn--glass" href="<?php echo APP_URL; ?>/pages/profile.php"><?php echo admin_icon('profile'); ?><span><?php echo e(t('admin.nav_my_profile')); ?></span></a>
    </div>
    <div class="a-greeting__stats">
        <span class="a-greeting__stat"><?php echo admin_icon('clock'); ?><?php echo e(date('D, M j, Y')); ?></span>
        <span class="a-greeting__stat"><?php echo admin_icon('eye'); ?><?php echo number_format($stats['views']); ?> <?php echo e(t('admin.total_views')); ?></span>
        <span class="a-greeting__stat"><?php echo admin_icon('heart'); ?><?php echo number_format($stats['favorites']); ?> <?php echo e(t('admin.stat_favorites')); ?></span>
        <span class="a-greeting__stat"><?php echo admin_icon('star'); ?><b><?php echo $stats['reviews'] ? number_format($stats['avg_rating'], 1) : '—'; ?></b> <?php echo e(t('admin.avg_rating')); ?></span>
    </div>
</section>

<!-- ================= KPI CARDS (real data + real deltas) ================= -->
<div class="a-kpis">
    <div class="a-kpi">
        <div class="a-kpi__top">
            <span class="a-kpi__label"><?php echo e(t('admin.stat_total_users')); ?></span>
            <span class="a-kpi__icon"><?php echo admin_icon('users'); ?></span>
        </div>
        <div class="a-kpi__value"><?php echo number_format($stats['users']); ?></div>
        <div class="a-kpi__meta"><?php echo admin_delta_chip($deltaUsers); ?>
            <span class="a-kpi__hint"><?php echo e(t('admin.new_this_week')); ?></span>
        </div>
    </div>

    <div class="a-kpi">
        <div class="a-kpi__top">
            <span class="a-kpi__label"><?php echo e(t('admin.stat_total_listings')); ?></span>
            <span class="a-kpi__icon a-kpi__icon--orange"><?php echo admin_icon('listings'); ?></span>
        </div>
        <div class="a-kpi__value"><?php echo number_format($stats['listings']); ?></div>
        <div class="a-kpi__meta"><?php echo admin_delta_chip($deltaListings); ?>
            <span class="a-kpi__hint"><b><?php echo number_format($stats['active_listings']); ?></b> <?php echo e(t('admin.active_available')); ?></span>
        </div>
    </div>

    <div class="a-kpi">
        <div class="a-kpi__top">
            <span class="a-kpi__label"><?php echo e(t('admin.stat_pending_review')); ?></span>
            <span class="a-kpi__icon a-kpi__icon--amber"><?php echo admin_icon('clock'); ?></span>
        </div>
        <div class="a-kpi__value"><?php echo number_format($stats['pending']); ?></div>
        <div class="a-kpi__meta">
            <?php if ($stats['pending'] > 0): ?>
                <a class="a-kpi__hint" href="<?php echo APP_URL; ?>/pages/admin/listings.php?status=pending"><?php echo e(t('admin.review_queue')); ?></a>
            <?php else: ?>
                <span class="a-kpi__delta a-kpi__delta--flat"><?php echo admin_icon('check'); ?><?php echo e(t('admin.all_clear_title')); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="a-kpi">
        <div class="a-kpi__top">
            <span class="a-kpi__label"><?php echo e(t('admin.stat_open_reports')); ?></span>
            <span class="a-kpi__icon a-kpi__icon--red"><?php echo admin_icon('reports'); ?></span>
        </div>
        <div class="a-kpi__value"><?php echo number_format($stats['reports_open']); ?></div>
        <div class="a-kpi__meta">
            <?php if ($stats['reports_open'] > 0): ?>
                <a class="a-kpi__hint" href="<?php echo APP_URL; ?>/pages/admin/reports.php"><?php echo e(t('admin.open_report_center')); ?></a>
            <?php else: ?>
                <span class="a-kpi__delta a-kpi__delta--flat"><?php echo admin_icon('check'); ?><?php echo e(t('admin.nothing_to_review')); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="a-kpi">
        <div class="a-kpi__top">
            <span class="a-kpi__label"><?php echo e(t('admin.stat_rentals')); ?></span>
            <span class="a-kpi__icon a-kpi__icon--blue"><?php echo admin_icon('rentals'); ?></span>
        </div>
        <div class="a-kpi__value"><?php echo number_format($stats['rentals']); ?></div>
        <div class="a-kpi__meta">
            <?php if ($stats['rentals_pending'] > 0): ?>
                <span class="a-kpi__delta a-kpi__delta--flat"><?php echo (int) $stats['rentals_pending']; ?> <?php echo e(t('admin.pending_lowercase')); ?></span>
                <a class="a-kpi__hint" href="<?php echo APP_URL; ?>/pages/admin/rentals.php"><?php echo e(t('admin.manage')); ?></a>
            <?php else: ?>
                <span class="a-kpi__hint"><?php echo e(t('admin.no_pending_rentals')); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="a-kpi">
        <div class="a-kpi__top">
            <span class="a-kpi__label"><?php echo e(t('admin.stat_messages')); ?></span>
            <span class="a-kpi__icon a-kpi__icon--green"><?php echo admin_icon('messages'); ?></span>
        </div>
        <div class="a-kpi__value"><?php echo number_format($stats['messages']); ?></div>
        <div class="a-kpi__meta"><?php echo admin_delta_chip($deltaMessages); ?>
            <span class="a-kpi__hint"><?php echo $stats['unread'] > 0 ? '<b>' . (int) $stats['unread'] . '</b> ' . e(t('admin.unread_lowercase')) : e(t('admin.all_read')); ?></span>
        </div>
    </div>

    <div class="a-kpi">
        <div class="a-kpi__top">
            <span class="a-kpi__label"><?php echo e(t('admin.stat_reviews')); ?></span>
            <span class="a-kpi__icon a-kpi__icon--orange"><?php echo admin_icon('reviews'); ?></span>
        </div>
        <div class="a-kpi__value"><?php echo number_format($stats['reviews']); ?></div>
        <div class="a-kpi__meta"><?php echo admin_delta_chip($deltaReviews); ?>
            <span class="a-kpi__hint"><?php echo e(t('admin.avg_rating')); ?> <b><?php echo $stats['reviews'] ? number_format($stats['avg_rating'], 1) : '—'; ?>/5</b></span>
        </div>
    </div>

    <div class="a-kpi">
        <div class="a-kpi__top">
            <span class="a-kpi__label"><?php echo e(t('admin.stat_favorites')); ?></span>
            <span class="a-kpi__icon"><?php echo admin_icon('heart'); ?></span>
        </div>
        <div class="a-kpi__value"><?php echo number_format($stats['favorites']); ?></div>
        <div class="a-kpi__meta">
            <span class="a-kpi__hint"><?php echo number_format($stats['views']); ?> <?php echo e(t('admin.total_views')); ?></span>
        </div>
    </div>
</div>

<!-- ================= MARKETPLACE HEALTH (Phase 5, real score) ================= -->
<section class="a-panel" style="margin-top:18px;">
    <div class="a-panel__head">
        <div>
            <h3><?php echo e(t('health.title')); ?></h3>
            <p><?php echo e(t('health.subtitle')); ?></p>
        </div>
        <span class="a-live"><i class="a-live__dot" aria-hidden="true"></i><?php echo e(t('health.live_now')); ?></span>
    </div>
    <div class="a-health">
        <div class="a-health__score">
            <span class="a-health__ring" role="img" aria-label="<?php echo e(t('health.title')); ?> <?php echo (int) $health['score']; ?>">
                <svg width="108" height="108" viewBox="0 0 108 108">
                    <circle cx="54" cy="54" r="<?php echo $hr; ?>" fill="none" stroke="var(--a-line)" stroke-width="9"/>
                    <circle cx="54" cy="54" r="<?php echo $hr; ?>" fill="none" stroke="<?php echo $healthTone; ?>" stroke-width="9" stroke-linecap="round"
                            stroke-dasharray="<?php echo e(number_format($hcirc * $health['score'] / 100, 2, '.', '')) . ' ' . e(number_format($hcirc, 2, '.', '')); ?>"/>
                </svg>
                <span class="a-health__ring-num"><?php echo (int) $health['score']; ?><small>%</small></span>
            </span>
            <div style="min-width:0;">
                <p class="a-health__label"><?php echo e(strtoupper(APP_NAME)); ?> <?php echo e(t('health.word_health')); ?></p>
                <p class="a-health__verdict a-health__verdict--<?php echo e($health['verdict']); ?>"><?php echo e(quality_verdict_label($health['verdict'])); ?></p>
                <div class="a-health__metrics">
                    <span class="a-health__metric"><?php echo e(t('health.metric_active')); ?> <?php echo (int) $health['data']['active_listings']; ?>/<?php echo (int) $health['data']['total_listings']; ?></span>
                    <span class="a-health__metric"><?php echo e(t('health.metric_quality')); ?> <?php echo (int) $health['data']['avg_quality']; ?>%</span>
                    <span class="a-health__metric"><?php echo e(t('health.metric_users')); ?> <?php echo (int) $health['data']['active_users']; ?>/<?php echo (int) $health['data']['total_users']; ?></span>
                </div>
            </div>
        </div>
        <div class="a-health__recs">
            <p class="a-health__recs-head"><?php echo e(t('health.recommendations')); ?></p>
            <?php foreach (array_slice($health['recs'], 0, 5) as $rec): ?>
                <a class="a-health__rec a-health__rec--<?php echo e($rec['sev']); ?>" href="<?php echo e($rec['url']); ?>">
                    <i class="a-health__rec-dot" aria-hidden="true"></i>
                    <span><?php echo e($rec['text']); ?></span>
                    <span class="a-health__rec-arrow" aria-hidden="true"><?php echo admin_icon('chevron-r'); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ================= ANALYTICS (period filter, real data) ================= -->
<section class="a-panel">
    <div class="a-panel__head">
        <div>
            <h3><?php echo e(t('admin.analytics_title')); ?></h3>
            <p><?php echo e(t('admin.analytics_sub', ['count' => number_format($totalNewListings), 'days' => $periodDays])); ?></p>
        </div>
        <div class="a-panel__tools">
            <div class="a-filters">
                <a class="a-chip <?php echo $periodKey === 'today' ? 'is-active' : ''; ?>" href="?period=today"><?php echo e(t('admin.period_today')); ?></a>
                <a class="a-chip <?php echo $periodKey === '7d' ? 'is-active' : ''; ?>" href="?period=7d"><?php echo e(t('admin.period_7d')); ?></a>
                <a class="a-chip <?php echo $periodKey === '30d' ? 'is-active' : ''; ?>" href="?period=30d"><?php echo e(t('admin.period_30d')); ?></a>
                <a class="a-chip <?php echo $periodKey === '365d' ? 'is-active' : ''; ?>" href="?period=365d"><?php echo e(t('admin.period_year')); ?></a>
            </div>
            <a class="a-panel__link" href="<?php echo APP_URL; ?>/pages/admin/analytics.php"><?php echo e(t('admin.deep_analytics')); ?></a>
        </div>
    </div>

    <?php if ($totalNewListings === 0): ?>
        <div class="a-empty">
            <div class="a-empty__icon"><?php echo admin_icon('analytics'); ?></div>
            <h4><?php echo e(t('admin.empty_no_data_title')); ?></h4>
            <p><?php echo e(t('admin.empty_analytics_sub')); ?></p>
        </div>
    <?php else: ?>
    <div class="a-chartbar-zone" style="height:<?php echo $periodDays > 60 ? 190 : 215; ?>px;">
        <?php
        $step = 1;
        if ($periodDays > 14) $step = max(1, (int) ceil($periodDays / 14)); // thin out labels on long ranges
        foreach ($series as $i => $point):
            $height = $point['n'] ? max(6, round(($point['n'] / $maxN) * 100)) : 2.5;
            $showLabel = ($i % $step === 0) || $i === $periodDays - 1;
        ?>
        <div class="a-chartbar-col">
            <div class="a-chartbar" style="height:<?php echo $height; ?>%" title="<?php echo (int) $point['n']; ?> — <?php echo e($point['d']); ?>">
                <span class="a-chartbar__value"><?php echo (int) $point['n']; ?></span>
            </div>
            <span class="a-chartbar-label"><?php echo $showLabel ? e(date($periodDays > 70 ? 'M' : 'd', strtotime($point['d']))) : ''; ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-top:12px;">
        <span class="a-kpi__hint"><b><?php echo number_format($usersInPeriod); ?></b> <?php echo e(t('admin.new_users_in_period')); ?></span>
        <span class="a-kpi__hint"><b><?php echo number_format($messagesInPeriod); ?></b> <?php echo e(t('admin.new_messages_in_period')); ?></span>
        <span class="a-kpi__hint"><b><?php echo number_format($rentalsInPeriod); ?></b> <?php echo e(t('admin.new_rentals_in_period')); ?></span>
    </div>
</section>

<!-- ================= QUICK ACTIONS (all real destinations) ================= -->
<section class="a-panel">
    <div class="a-panel__head">
        <div>
            <h3><?php echo e(t('admin.quick_actions')); ?></h3>
            <p><?php echo e(t('admin.quick_actions_sub')); ?></p>
        </div>
    </div>
    <div class="a-quickactions">
        <a class="a-quickaction" href="<?php echo APP_URL; ?>/pages/sell.php"><span class="a-quickaction__icon a-quickaction__icon--orange"><?php echo admin_icon('plus'); ?></span><?php echo e(t('admin.quick_add_listing')); ?></a>
        <a class="a-quickaction" href="<?php echo APP_URL; ?>/pages/admin/listings.php?status=pending"><span class="a-quickaction__icon"><?php echo admin_icon('check-circle'); ?></span><?php echo e(t('admin.stat_pending_review')); ?></a>
        <a class="a-quickaction" href="<?php echo APP_URL; ?>/pages/admin/users.php"><span class="a-quickaction__icon"><?php echo admin_icon('users'); ?></span><?php echo e(t('admin.quick_view_users')); ?></a>
        <a class="a-quickaction" href="<?php echo APP_URL; ?>/pages/admin/categories.php"><span class="a-quickaction__icon"><?php echo admin_icon('categories'); ?></span><?php echo e(t('admin.nav_categories')); ?></a>
        <a class="a-quickaction" href="<?php echo APP_URL; ?>/pages/admin/messages.php"><span class="a-quickaction__icon"><?php echo admin_icon('messages'); ?></span><?php echo e(t('admin.nav_messages')); ?></a>
        <a class="a-quickaction" href="<?php echo APP_URL; ?>/pages/admin/rentals.php"><span class="a-quickaction__icon"><?php echo admin_icon('rentals'); ?></span><?php echo e(t('admin.nav_rentals')); ?></a>
        <a class="a-quickaction" href="<?php echo APP_URL; ?>/pages/admin/reports.php"><span class="a-quickaction__icon"><?php echo admin_icon('reports'); ?></span><?php echo e(t('admin.quick_view_reports')); ?></a>
        <a class="a-quickaction" href="<?php echo APP_URL; ?>/pages/admin/analytics.php"><span class="a-quickaction__icon"><?php echo admin_icon('analytics'); ?></span><?php echo e(t('admin.quick_view_analytics')); ?></a>
    </div>
</section>

<!-- ================= RECENT LISTINGS + CATEGORIES ================= -->
<div class="a-grid">
    <section class="a-panel">
        <div class="a-panel__head">
            <div>
                <h3><?php echo e(t('admin.panel_recent_listings')); ?></h3>
                <p><?php echo e(t('admin.panel_recent_listings_sub')); ?></p>
            </div>
            <a class="a-panel__link" href="<?php echo APP_URL; ?>/pages/admin/listings.php"><?php echo e(t('admin.view_all')); ?> →</a>
        </div>
        <?php if (!$recentListings): ?>
            <div class="a-empty">
                <div class="a-empty__icon"><?php echo admin_icon('image'); ?></div>
                <h4><?php echo e(t('admin.empty_no_listings')); ?></h4>
                <p><?php echo e(t('admin.empty_no_listings_sub')); ?></p>
            </div>
        <?php else: ?>
            <div class="a-tablewrap" style="border:0;">
            <table class="a-table" style="min-width:0;">
                <tbody>
                <?php foreach ($recentListings as $l): ?>
                    <tr>
                        <td style="width:54px; padding:8px 0 8px 4px;">
                            <img class="a-table__thumb" loading="lazy" src="<?php echo e(image_or_default($l['image'] ?? null)); ?>" alt="">
                        </td>
                        <td data-label="<?php echo e(t('admin.col_title')); ?>">
                            <div class="a-table__listing-title">
                                <a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int) $l['id']; ?>"><?php echo e($l['title']); ?></a>
                            </div>
                            <div class="a-table__listing-sub"><?php echo e($l['seller_name']); ?> · <?php echo e(t_category($l['category_name_key'] ?? null, $l['category_name'])); ?><?php echo $l['location'] ? ' · ' . e($l['location']) : ''; ?></div>
                        </td>
                        <td data-label="<?php echo e(t('admin.col_price')); ?>" class="cell-num cell-primary"><?php echo e(format_price($l['price'], $l['currency'])); ?></td>
                        <td data-label="<?php echo e(t('admin.col_status')); ?>">
                            <span class="a-status a-status--<?php echo e($l['status']); ?>"><?php echo e(t('admin.status_' . $l['status'])); ?></span>
                        </td>
                        <td data-label="<?php echo e(t('admin.col_posted')); ?>" class="cell-mute"><?php echo e(time_ago($l['created_at'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="a-panel">
        <div class="a-panel__head">
            <div>
                <h3><?php echo e(t('admin.panel_top_categories')); ?></h3>
                <p><?php echo e(t('admin.panel_top_categories_sub')); ?></p>
            </div>
            <a class="a-panel__link" href="<?php echo APP_URL; ?>/pages/admin/categories.php"><?php echo e(t('admin.manage')); ?></a>
        </div>
        <?php if (!$categories): ?>
            <div class="a-empty"><div class="a-empty__icon"><?php echo admin_icon('categories'); ?></div><h4><?php echo e(t('admin.empty_no_categories')); ?></h4></div>
        <?php else: foreach ($categories as $cat): $pct = min(100, round(((int) $cat['listing_count'] / $maxCategory) * 100)); ?>
            <div class="a-progress">
                <div class="a-progress__top">
                    <span><?php echo e(t_category($cat['name_key'] ?? null, $cat['name'])); ?></span>
                    <span><?php echo (int) $cat['listing_count']; ?></span>
                </div>
                <div class="a-progress__track"><span class="a-progress__fill" style="width:<?php echo $pct; ?>%"></span></div>
            </div>
        <?php endforeach; endif; ?>
    </section>
</div>

<!-- ================= APPROVAL QUEUE + ACTIVITY ================= -->
<div class="a-grid a-grid--wide-left">
    <section class="a-panel">
        <div class="a-panel__head">
            <div>
                <h3><?php echo e(t('admin.panel_pending_title')); ?></h3>
                <p><?php echo e(t('admin.panel_pending_sub')); ?></p>
            </div>
            <a class="a-panel__link" href="<?php echo APP_URL; ?>/pages/admin/listings.php?status=pending"><?php echo e(t('admin.view_queue')); ?> →</a>
        </div>
        <?php if (!$pending): ?>
            <div class="a-empty">
                <div class="a-empty__icon"><?php echo admin_icon('check-circle'); ?></div>
                <h4><?php echo e(t('admin.empty_no_pending')); ?></h4>
                <p><?php echo e(t('admin.empty_no_pending_sub')); ?></p>
            </div>
        <?php else: ?>
            <div class="a-tablewrap">
            <table class="a-table" style="min-width:560px;">
                <thead><tr>
                    <th><?php echo e(t('admin.col_title')); ?></th>
                    <th><?php echo e(t('admin.col_seller')); ?></th>
                    <th><?php echo e(t('admin.col_price')); ?></th>
                    <th><?php echo e(t('admin.col_posted')); ?></th>
                    <th><?php echo e(t('admin.col_actions')); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($pending as $p): ?>
                    <tr>
                        <td class="cell-primary"><a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int) $p['id']; ?>"><?php echo e($p['title']); ?></a></td>
                        <td class="cell-mute"><?php echo e($p['seller_name']); ?></td>
                        <td class="cell-num"><?php echo e(format_price($p['price'], $p['currency'])); ?></td>
                        <td class="cell-mute"><?php echo e(time_ago($p['created_at'])); ?></td>
                        <td>
                            <div class="a-rowactions">
                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php" style="display:contents;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="listing_approve">
                                    <input type="hidden" name="listing_id" value="<?php echo (int) $p['id']; ?>">
                                    <button class="a-btn a-btn--primary a-btn--sm" type="submit"><?php echo e(t('buttons.approve')); ?></button>
                                </form>
                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php" style="display:contents;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="listing_reject">
                                    <input type="hidden" name="listing_id" value="<?php echo (int) $p['id']; ?>">
                                    <button class="a-btn a-btn--sm" type="submit"><?php echo e(t('buttons.reject')); ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="a-panel">
        <div class="a-panel__head">
            <div>
                <h3><?php echo e(t('admin.panel_recent_users')); ?></h3>
                <p><?php echo e(t('admin.panel_recent_users_sub')); ?></p>
            </div>
            <a class="a-panel__link" href="<?php echo APP_URL; ?>/pages/admin/users.php"><?php echo e(t('admin.view_all')); ?> →</a>
        </div>
        <?php if (!$recentUsers): ?>
            <div class="a-empty"><div class="a-empty__icon"><?php echo admin_icon('users'); ?></div><h4><?php echo e(t('admin.empty_no_users')); ?></h4></div>
        <?php else: ?>
            <div class="a-timeline">
                <?php foreach ($recentUsers as $u): ?>
                <div class="a-timeline__item">
                    <span class="a-timeline__dot"><?php echo admin_icon('user-check'); ?></span>
                    <div style="min-width:0;">
                        <p class="a-timeline__title">
                            <a href="<?php echo APP_URL; ?>/pages/seller-profile.php?id=<?php echo (int) $u['id']; ?>"><?php echo e($u['full_name']); ?></a>
                        </p>
                        <div class="a-timeline__meta"><?php echo e($u['email']); ?> · <?php echo e(time_ago($u['created_at'])); ?></div>
                    </div>
                    <span class="a-status a-status--<?php echo e($u['status']); ?>" style="margin-left:auto;"><?php echo e(t('admin.status_' . $u['status'])); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<!-- ================= MESSAGES + RENTALS ================= -->
<div class="a-grid--equal a-grid">
    <section class="a-panel">
        <div class="a-panel__head">
            <div>
                <h3><?php echo e(t('admin.nav_messages')); ?></h3>
                <p><?php echo $stats['unread'] > 0 ? '<b style="color:var(--a-orange-deep);">' . (int) $stats['unread'] . '</b> ' . e(t('admin.unread_lowercase')) : e(t('admin.all_read')); ?></p>
            </div>
            <a class="a-panel__link" href="<?php echo APP_URL; ?>/pages/admin/messages.php"><?php echo e(t('admin.open_inbox')); ?> →</a>
        </div>
        <?php if (!$messages): ?>
            <div class="a-empty">
                <div class="a-empty__icon"><?php echo admin_icon('inbox'); ?></div>
                <h4><?php echo e(t('admin.empty_no_messages')); ?></h4>
                <p><?php echo e(t('admin.empty_no_messages_sub')); ?></p>
            </div>
        <?php else: foreach ($messages as $m): ?>
            <div class="a-msg <?php echo !$m['is_read'] ? 'a-msg--unread' : ''; ?>">
                <span class="a-msg__avatar"><?php echo e(strtoupper(substr($m['name'], 0, 1))); ?></span>
                <div class="a-msg__body">
                    <div class="a-msg__top"><strong><?php echo e($m['name']); ?></strong><time><?php echo e(time_ago($m['created_at'])); ?></time></div>
                    <p class="a-msg__subject"><?php echo e(t('admin.about_listing')); ?>: <?php echo e($m['listing_title']); ?></p>
                    <p class="a-msg__preview"><?php echo e($m['message']); ?></p>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </section>

    <section class="a-panel">
        <div class="a-panel__head">
            <div>
                <h3><?php echo e(t('admin.nav_rentals')); ?></h3>
                <p><?php echo $stats['rentals_pending'] > 0 ? (int) $stats['rentals_pending'] . ' ' . e(t('admin.pending_lowercase')) : e(t('admin.no_pending_rentals')); ?></p>
            </div>
            <a class="a-panel__link" href="<?php echo APP_URL; ?>/pages/admin/rentals.php"><?php echo e(t('admin.manage')); ?> →</a>
        </div>
        <?php if (!$rentals): ?>
            <div class="a-empty">
                <div class="a-empty__icon"><?php echo admin_icon('rentals'); ?></div>
                <h4><?php echo e(t('admin.empty_no_rentals')); ?></h4>
                <p><?php echo e(t('admin.empty_no_rentals_sub')); ?></p>
            </div>
        <?php else: ?>
            <div class="a-timeline">
                <?php foreach ($rentals as $r): ?>
                <div class="a-timeline__item">
                    <span class="a-timeline__dot a-timeline__dot--blue"><?php echo admin_icon('rentals'); ?></span>
                    <div style="min-width:0;">
                        <p class="a-timeline__title"><?php echo e($r['renter_name']); ?></p>
                        <div class="a-timeline__meta"><?php echo e($r['listing_title']); ?> · <?php echo e(date('M j', strtotime($r['start_date']))); ?> → <?php echo e(date('M j', strtotime($r['end_date']))); ?></div>
                    </div>
                    <span class="a-status a-status--<?php echo e($r['status']); ?>" style="margin-left:auto;"><?php echo e(t('admin.status_' . $r['status'])); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<!-- ================= OPEN REPORTS ================= -->
<?php if ($reports): ?>
<section class="a-panel">
    <div class="a-panel__head">
        <div>
            <h3><?php echo e(t('admin.panel_reports_title')); ?></h3>
            <p><?php echo e(t('admin.panel_reports_sub')); ?></p>
        </div>
        <a class="a-panel__link" href="<?php echo APP_URL; ?>/pages/admin/reports.php"><?php echo e(t('admin.open_report_center')); ?> →</a>
    </div>
    <div class="a-tablewrap">
        <table class="a-table">
            <thead><tr>
                <th><?php echo e(t('listings.detail.report_reason')); ?></th>
                <th><?php echo e(t('common.listing')); ?></th>
                <th><?php echo e(t('admin.col_status')); ?></th>
                <th><?php echo e(t('admin.col_posted')); ?></th>
                <th><?php echo e(t('admin.col_actions')); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($reports as $r): ?>
                <tr>
                    <td class="cell-primary"><?php echo e($r['reason']); ?></td>
                    <td class="cell-mute"><?php echo $r['listing_title'] ? e($r['listing_title']) : '—'; ?></td>
                    <td><span class="a-status a-status--<?php echo e($r['status']); ?>"><?php echo e(t('admin.status_' . $r['status'])); ?></span></td>
                    <td class="cell-mute"><?php echo e(time_ago($r['created_at'])); ?></td>
                    <td>
                        <div class="a-rowactions">
                            <?php if ($r['status'] === 'open'): ?>
                            <form method="post" action="<?php echo APP_URL; ?>/api/v1/admin-action/index.php" style="display:contents;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="report_status">
                                <input type="hidden" name="status" value="resolved">
                                <input type="hidden" name="report_id" value="<?php echo (int) $r['id']; ?>">
                                <button class="a-btn a-btn--primary a-btn--sm" type="submit"><?php echo e(t('admin.resolve')); ?></button>
                            </form>
                            <?php else: ?>
                            <a class="a-btn a-btn--sm" href="<?php echo APP_URL; ?>/pages/admin/reports.php"><?php echo e(t('admin.manage')); ?></a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

</div><!-- /a-content -->
<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>
