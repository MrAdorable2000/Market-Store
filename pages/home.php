<?php
/**
 * pages/home.php
 * --------------------------------------------------------------------
 * Phase 3: Compact, premium homepage.
 *   1. Hero (compact, with Kinyarwanda headline when rw is selected)
 *   2. Popular categories (8–10 cards)
 *   3. Trending listings (4–8 cards)
 *   4. Things to rent (rental section)
 *   5. Near you (location-based or fallback)
 *   6. Market insights (small, 3–4 stat cards)
 *   7. Market blog (3 featured articles)
 *   8. Single CTA band
 *   9. Footer (added by layout)
 *
 * Each section is intentionally compact — no repeated grids, no huge hero.
 * --------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/youtube.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/smart_features.php';


/* ---------- Super Admin homepage content overrides ----------
 * Stored in site_settings as home_<key>_<lang>. If no override exists,
 * the normal translation file remains the fallback.
 */
$homeOverrides = [];
try {
    $hs = db()->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'home_%'");
    foreach ($hs as $hr) $homeOverrides[$hr['setting_key']] = (string)$hr['setting_value'];
} catch (Throwable $e) { $homeOverrides = []; }
function home_copy(string $key, string $fallback): string {
    global $homeOverrides;
    $lang = function_exists('current_lang') ? current_lang() : 'en';
    $v = $homeOverrides['home_' . $key . '_' . $lang] ?? '';
    return trim($v) !== '' ? $v : $fallback;
}

/* ---------- Data fetchers ---------- */

function fetch_categories_home(int $limit = 10): array {
    $stmt = db()->prepare(
        'SELECT id, name, slug, name_key, icon,
                (SELECT COUNT(*) FROM listings l WHERE l.category_id = c.id AND l.status = "active") AS count
         FROM categories c
         WHERE c.parent_id IS NULL AND c.is_active = 1
         ORDER BY c.display_order ASC LIMIT ?'
    );
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

function fetch_listings_home(?string $type = null, int $limit = 8, string $orderBy = 'created_at DESC', bool $featuredOnly = false): array {
    $sql = 'SELECT l.id, l.title, l.slug, l.price, l.currency, l.listing_type,
                   l.location, l.condition_state, l.availability,
                   l.views_count, l.favorites_count, l.is_featured, l.is_verified, l.created_at,
                   l.price_per_month, l.price_per_week, l.price_per_day,
                   c.slug AS category_slug, c.name AS category_name, c.name_key AS category_name_key,
                   (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image,
                   u.full_name AS seller_name, u.is_verified AS seller_verified
            FROM listings l
            INNER JOIN categories c ON c.id = l.category_id
            INNER JOIN users u ON u.id = l.seller_id
            WHERE l.status = "active" AND l.is_published = 1 AND l.availability = "available"';
    $params = [];
    if ($type)         { $sql .= ' AND l.listing_type = ?'; $params[] = $type; }
    if ($featuredOnly) { $sql .= ' AND l.is_featured = 1'; }
    $sql .= ' ORDER BY ' . $orderBy . ' LIMIT ?';
    $params[] = $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function fetch_blog_home(int $limit = 3): array {
    $stmt = db()->prepare(
        'SELECT id, title, slug, excerpt, cover_path, category, views_count, published_at
         FROM blog_posts WHERE status = "published"
         ORDER BY published_at DESC LIMIT ?'
    );
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

/** Real public community users shown on the homepage.
 * Only active accounts are exposed; private credentials are never selected.
 */
function fetch_home_users(int $limit = 12): array {
    $stmt = db()->prepare(
        "SELECT u.id, u.full_name, u.phone, u.whatsapp_number, u.avatar_path, u.is_verified,
                u.is_seller, u.last_seen_at,
                sp.social_whatsapp AS seller_whatsapp
         FROM users u
         LEFT JOIN seller_profiles sp ON sp.user_id = u.id
         WHERE u.status = 'active'
         ORDER BY (u.last_seen_at >= (NOW() - INTERVAL 5 MINUTE)) DESC,
                  COALESCE(u.last_seen_at, u.created_at) DESC, u.full_name ASC
         LIMIT ?"
    );
    $stmt->execute([$limit]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['is_online'] = !empty($row['last_seen_at']) && strtotime($row['last_seen_at']) >= (time() - 300);
        $wa = trim((string)($row['whatsapp_number'] ?? ''));
        if ($wa === '') $wa = trim((string)($row['seller_whatsapp'] ?? ''));
        $row['whatsapp'] = $wa;
    }
    unset($row);
    return $rows;
}

/* ---------- Listing card renderer ---------- */

function render_listing_card_home(array $l): string {
    $image = image_or_default($l['image'] ?? null, 'assets/images/placeholders/default.svg');
    $price = $l['listing_type'] === 'rent'
        ? e(t('listings.from')) . ' ' . format_price($l['price_per_month'] ?? $l['price_per_week'] ?? $l['price_per_day'] ?? 0, $l['currency']) . e(t('listings.per_month'))
        : format_price($l['price'], $l['currency']);
    $typeBadge = $l['listing_type'] === 'rent'
        ? '<span class="badge badge--rent">' . e(t('listings.for_rent')) . '</span>'
        : '<span class="badge badge--brand">' . e(t('listings.for_sale')) . '</span>';
    if ((int) ($l['is_verified'] ?? 0) === 1) {
        $typeBadge .= '<span class="badge badge--verified" title="' . e(t('trust.verified_listing')) . '">'
            . '<svg viewBox="0 0 24 24" width="11" height="11" aria-hidden="true"><path d="M12 2l2.4 2.1 3.1-.5 1 3 2.9 1.2-.7 3.1L22 14l-2.4 2.4.4 3.2-3.1.6-1.9 2.6-3-.9-3 .9-1.9-2.6-3.1-.6.4-3.2L2 14l1.3-3.1L.5 7.8l2.9-1.2 1-3 3.1.5z" fill="currentColor"/></svg>'
            . e(t('trust.verified')) . '</span>';
    }
    $vcheck = ($l['seller_verified'] ?? false)
        ? '<i class="listing-card__vcheck" title="' . e(t('trust.verified_seller')) . '"><svg viewBox="0 0 24 24" width="10" height="10"><path d="M5 13l4 4L19 7" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg></i>'
        : '';
    return '<a class="listing-card" href="' . APP_URL . '/pages/listing-details.php?id=' . (int) $l['id'] . '">'
        . '<div class="listing-card__media">'
        . '  <img loading="lazy" src="' . $image . '" alt="' . e($l['title']) . '">'
        . '  <div class="listing-card__badges">' . $typeBadge . '</div>'
        . $vcheck
        . '  <button class="listing-card__fav" type="button" aria-label="' . e(t('buttons.save')) . '" data-fav-listing="' . (int)$l['id'] . '">'
        . '    <svg viewBox="0 0 24 24" width="16" height="16"><path d="M12 21s-7-4.5-9.5-9A4.5 4.5 0 0 1 12 5.5 4.5 4.5 0 0 1 21.5 12c-2.5 4.5-9.5 9-9.5 9z" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>'
        . '  </button>'
        . '</div>'
        . '<div class="listing-card__body">'
        . '  <div class="listing-card__price">' . e($price) . '</div>'
        . '  <div class="listing-card__title">' . e($l['title']) . '</div>'
        . '  <div class="listing-card__meta"><span>' . e($l['location'] ?? 'Rwanda') . '</span><span>' . time_ago($l['created_at']) . '</span></div>'
        . '</div></a>';
}

function render_listing_grid_home(array $listings, string $emptyKey = 'empty.no_listings'): string {
    if (!$listings) {
        return '<div class="empty-state"><p>' . e(t($emptyKey)) . '</p></div>';
    }
    return '<div class="grid grid--4 grid--auto">'
         . implode('', array_map('render_listing_card_home', $listings))
         . '</div>';
}

/* ---------- Fetch all data ---------- */

$categories = fetch_categories_home(10);
$featuredProducts = fetch_listings_home(null, 8, 'is_featured DESC, created_at DESC', true);
// Live rotating products: always pulled from real active marketplace listings.
$liveProducts = fetch_listings_home(null, 16, 'created_at DESC', false);
$trending   = fetch_listings_home(null, 8, 'views_count DESC', true);
if (count($trending) < 4) $trending = fetch_listings_home(null, 8, 'created_at DESC', false);
$rentals    = fetch_listings_home('rent', 4, 'views_count DESC', false);
if (!$rentals) $rentals = fetch_listings_home('rent', 4, 'created_at DESC', false);

// "Near you" — REAL location matching (Phase 6):
// logged-in users see listings from their own city; visitors see the most
// active city on the platform (computed from real listing data).
$viewerCity = '';
$viewer = current_user();
if ($viewer && !empty($viewer['location'])) {
    $viewerCity = trim(explode(',', (string) $viewer['location'])[0]);
}
$activeCities = location_activity(5);
$topCity = $activeCities ? $activeCities[0]['city'] : '';
$nearCity = $viewerCity;
if ($nearCity !== '') {
    $stmt = db()->prepare("SELECT COUNT(*) FROM listings WHERE status='active' AND (location LIKE ? OR district = ?)");
    $stmt->execute([$nearCity . '%', $nearCity]);
    if ((int) $stmt->fetchColumn() === 0) $nearCity = '';
}
if ($nearCity === '') { $nearCity = $topCity; }
$nearYou = [];
if ($nearCity !== '') {
    $stmt = db()->prepare("SELECT l.id, l.title, l.slug, l.price, l.currency, l.listing_type,
            l.location, l.condition_state, l.availability, l.views_count, l.favorites_count,
            l.is_featured, l.is_verified, l.created_at, l.price_per_month, l.price_per_week, l.price_per_day,
            c.slug AS category_slug, c.name AS category_name, c.name_key AS category_name_key,
            (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image,
            u.full_name AS seller_name, u.is_verified AS seller_verified
        FROM listings l INNER JOIN categories c ON c.id = l.category_id INNER JOIN users u ON u.id = l.seller_id
        WHERE l.status = 'active' AND l.is_published = 1 AND l.availability = 'available' AND (l.location LIKE ? OR l.district = ?)
        ORDER BY l.views_count DESC LIMIT 4");
    $stmt->execute([$nearCity . '%', $nearCity]);
    $nearYou = $stmt->fetchAll();
}

// Frequently favorited (real popularity — Phase 6)
$favorites = popular_favorited_listings(4);

// Recently viewed by this visitor (Phase 6)
$recentlyViewed = recently_viewed_listings(4);

// Recommended categories from this visitor's real activity (Phase 6)
$recCats = recommended_categories(4);

// REAL marketplace insights (Phase 4 — replaces the old demo cards)
$realInsights = marketplace_insights_real();
$newThisWeek  = (int) ($realInsights['new_week']['listings'] ?? 0);
$mostViewed   = $realInsights['most_viewed'][0] ?? null;
$rentalCount  = (int) $realInsights['totals']['listings'] ? (int) db()->query("SELECT COUNT(*) FROM listings WHERE listing_type='rent' AND status='active'")->fetchColumn() : 0;
$topCategory  = $realInsights['categories'][0] ?? null;

$blog       = fetch_blog_home(3);
$homeUsers  = fetch_home_users(60);

// Real admin-managed YouTube content for the reserved Home-page module.
$homeVideo = null;
try {
    $stmt = db()->query("SELECT id, title, description, youtube_video_id FROM youtube_videos WHERE is_published=1 ORDER BY display_order ASC, created_at DESC LIMIT 1");
    $homeVideo = $stmt->fetch() ?: null;
} catch (PDOException $e) {
    // Migration may not have been imported yet; Home continues to work normally.
}

$homePosts = [];
try {
    $stmt = db()->query("SELECT id, type, title, body, event_date, location, cta_label, cta_url FROM community_posts WHERE is_published=1 ORDER BY display_order ASC, COALESCE(event_date, created_at) ASC, created_at DESC LIMIT 4");
    $homePosts = $stmt->fetchAll();
} catch (PDOException $e) {
    // Optional migration; Home remains functional before import.
}

// Compact Home calendar: uses the same Admin-managed Events data as Community Updates.
$calendarYear = (int) date('Y');
$calendarMonth = (int) date('n');
$calendarMonthStart = sprintf('%04d-%02d-01', $calendarYear, $calendarMonth);
$calendarNextMonth = date('Y-m-d', strtotime($calendarMonthStart . ' +1 month'));
$calendarEvents = [];
try {
    $stmt = db()->prepare("SELECT id, title, event_date, location FROM community_posts
        WHERE is_published=1 AND type='event' AND event_date IS NOT NULL
          AND event_date >= ? AND event_date < ?
        ORDER BY event_date ASC LIMIT 31");
    $stmt->execute([$calendarMonthStart, $calendarNextMonth]);
    $calendarEvents = $stmt->fetchAll();
} catch (PDOException $e) {
    // Optional migration; Home remains functional before import.
}
$calendarEventsByDay = [];
foreach ($calendarEvents as $event) {
    $day = (int) date('j', strtotime($event['event_date']));
    $calendarEventsByDay[$day][] = $event;
}
$calendarDaysInMonth = (int) date('t', strtotime($calendarMonthStart));
$calendarFirstDow = (int) date('w', strtotime($calendarMonthStart)); // 0 = Sunday
$calendarUpcoming = array_slice($calendarEvents, 0, 3);

// REAL search suggestions (top listing titles + categories + live searches)
$suggestions = [];
foreach (array_slice($realInsights['most_viewed'], 0, 5) as $mv) {
    $suggestions[] = ['label' => $mv['title'], 'hint' => t_category($mv['category_name_key'] ?? null, $mv['category_name'] ?? '')];
}
foreach (top_searched_terms(4) as $ts) {
    $suggestions[] = ['label' => $ts['metric_value'], 'hint' => t('search.popular_search')];
}
foreach (array_slice($realInsights['locations'], 0, 3) as $loc) {
    $suggestions[] = ['label' => $loc['city'], 'hint' => t('search.location')];
}
$suggestionsJson = json_encode($suggestions, JSON_UNESCAPED_UNICODE);
?>

<script>
(function () {
  const grid = document.getElementById('community-users-grid');
  const onlineCount = document.querySelector('[data-online-count]');
  if (!grid) return;
  const ids = Array.from(grid.querySelectorAll('[data-user-id]')).map(el => el.dataset.userId);

  function relativeLastSeen(timestamp) {
    if (!timestamp) return 'No recent activity';
    const seconds = Math.max(0, Math.floor(Date.now() / 1000) - Number(timestamp));
    if (seconds < 60) return 'Active just now';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return 'Last seen ' + minutes + (minutes === 1 ? ' min ago' : ' mins ago');
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return 'Last seen ' + hours + (hours === 1 ? ' hour ago' : ' hours ago');
    const days = Math.floor(hours / 24);
    if (days < 30) return 'Last seen ' + days + (days === 1 ? ' day ago' : ' days ago');
    return 'Last seen a while ago';
  }

  async function refreshPresence() {
    try {
      const res = await fetch('<?php echo APP_URL; ?>/api/v1/users/online-status.php?ids=' + encodeURIComponent(ids.join(',')), {credentials:'same-origin', cache:'no-store'});
      if (!res.ok) return;
      const data = await res.json();
      if (onlineCount) onlineCount.textContent = String(Number(data.online_count || 0));
      (data.users || []).forEach(function (u) {
        const card = grid.querySelector('[data-user-id="' + CSS.escape(String(u.id)) + '"]');
        if (!card) return;
        const dot = card.querySelector('[data-presence-dot]');
        const label = card.querySelector('[data-presence-label]');
        const online = !!u.online;
        if (dot) {
          dot.classList.toggle('is-online', online);
          dot.classList.toggle('is-inactive', !online);
          dot.setAttribute('aria-label', online ? 'Online' : 'Inactive');
        }
        if (label) label.textContent = online ? 'Online now' : relativeLastSeen(u.last_seen_ts);
      });
    } catch (e) {}
  }
  refreshPresence();
  setInterval(refreshPresence, 30000);
})();
</script>

<!-- Real search suggestions for the smart search bar (Phase 9) -->
<script>window.ISOKO_SUGGESTIONS = <?php echo $suggestionsJson ?: '[]'; ?>;</script>

<!-- =================================================================
     1. HERO — full-bleed market photo with a curved teal divider,
        trust badges, and search (with category filter)
     ================================================================= -->
<?php
    $heroTitleRaw = home_copy('hero_title', t('hero.title'));
    // "Discover Anything. Buy. Sell. Rent." -> split into a quiet first
    // line and a colored second line at the first sentence break, if the
    // string has one; otherwise render it as a single line untouched.
    $heroTitleParts = preg_split('/(?<=\.)\s+/', $heroTitleRaw, 2);
    $heroTitleLine1 = $heroTitleParts[0] ?? $heroTitleRaw;
    $heroTitleLine2 = $heroTitleParts[1] ?? '';
?>
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
    <defs>
        <clipPath id="heroCurveClip" clipPathUnits="objectBoundingBox">
            <path d="M0.20,0 C0.09,0.30 0.09,0.70 0.20,1 L1,1 L1,0 Z"/>
        </clipPath>
    </defs>
</svg>
<section class="hero hero--bleed">
    <div class="hero__leaf-deco" aria-hidden="true">
        <svg viewBox="0 0 160 220" width="160" height="220"><path d="M10 210C-4 140 20 70 90 20c22 46 16 108-24 150-18 19-40 32-56 40Z" fill="var(--brand-300)"/><path d="M30 200C22 150 42 96 96 60c14 34 6 82-26 114-14 14-28 22-40 26Z" fill="var(--brand-500)"/></svg>
    </div>

    <div class="hero__media">
        <img src="<?php echo real_image('hero', 'house'); ?>" alt="African marketplace" loading="eager">
        <svg class="hero__media-curve" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
            <path d="M20,0 C9,30 9,70 20,100"/>
        </svg>
        <?php if ($heroTitleLine2 !== ''): ?>
        <p class="hero__caption">
            Rwanda<br>Grows Together
            <svg class="hero__caption-underline" viewBox="0 0 140 14" width="140" height="14" aria-hidden="true"><path d="M2 8c22-9 44-9 66-3s46 6 70-3" fill="none" stroke="var(--accent-400)" stroke-width="2.4" stroke-linecap="round"/></svg>
        </p>
        <?php endif; ?>
        <?php if (!empty($realInsights['totals']['listings'])): ?>
        <div class="hero__float hero__float--bot">
            <div class="hero__float-icon">
                <svg viewBox="0 0 24 24" width="18" height="18"><path d="M20 6 9 17l-5-5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </div>
            <div>
                <strong><?php echo e(number_format($realInsights['totals']['listings'])); ?>+ listings</strong>
                <span><?php echo e(number_format($realInsights['totals']['sellers'])); ?> verified sellers</span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="container">
        <div class="hero__panel">
            <span class="hero__eyebrow">
                <svg viewBox="0 0 24 24" width="14" height="14"><path d="M12 21s-7-4.5-9.5-9A4.5 4.5 0 0 1 12 5.5 4.5 4.5 0 0 1 21.5 12c-2.5 4.5-9.5 9-9.5 9z" fill="currentColor"/></svg>
                <?php echo e(home_copy('hero_eyebrow', t('hero.eyebrow'))); ?>
            </span>
            <h1 class="hero__title hero__title--bleed">
                <?php echo e($heroTitleLine1); ?><?php if ($heroTitleLine2 !== ''): ?><br><span class="accent"><?php echo e($heroTitleLine2); ?></span><?php endif; ?>
            </h1>
            <p class="hero__sub hero__sub--bleed"><?php echo e(home_copy('hero_subtitle', t('hero.subtitle'))); ?></p>

            <div class="hero__search">
                <form class="searchbar searchbar--hero-pro" action="<?php echo APP_URL; ?>/pages/explore.php" method="get" autocomplete="off">
                    <input type="hidden" name="type" value="">
                    <div class="searchbar__tabs" role="tablist">
                        <button type="button" class="searchbar__tab active" data-type=""><?php echo e(t('search.all')); ?></button>
                        <button type="button" class="searchbar__tab" data-type="sell"><?php echo e(t('search.buy_tab')); ?></button>
                        <button type="button" class="searchbar__tab" data-type="rent"><?php echo e(t('search.rent_tab')); ?></button>
                    </div>
                    <div class="searchbar__hero-fields">
                        <div class="searchbar__input">
                            <svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="m21 21-4-4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                            <input type="search" name="q" placeholder="<?php echo e(home_copy('hero_search_placeholder', t('hero.search_placeholder'))); ?>" aria-label="<?php echo e(t('buttons.search')); ?>">
                            <div class="searchbar__suggest"></div>
                        </div>
                        <div class="searchbar__location">
                            <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path d="M12 2a7 7 0 0 1 7 7c0 5-7 13-7 13S5 14 5 9a7 7 0 0 1 7-7z" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="9" r="2.5" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>
                            <select name="location" aria-label="<?php echo e(t('search.location')); ?>">
                                <option value=""><?php echo e(t('search.location_all')); ?></option>
                                <?php foreach (array_slice($activeCities, 0, 6) as $locRow): ?>
                                    <option value="<?php echo e($locRow['city']); ?>" <?php echo ($nearCity === $locRow['city']) ? 'selected' : ''; ?>><?php echo e($locRow['city']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="searchbar__location searchbar__category">
                            <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.6"/><rect x="14" y="3" width="7" height="7" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.6"/><rect x="3" y="14" width="7" height="7" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.6"/><rect x="14" y="14" width="7" height="7" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>
                            <select name="category" aria-label="<?php echo e(t('nav.categories')); ?>">
                                <option value=""><?php echo e(t('search.all_categories')); ?></option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo e($cat['slug']); ?>"><?php echo e($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn--primary searchbar__btn"><?php echo e(t('buttons.search')); ?> <span aria-hidden="true">→</span></button>
                    </div>
                </form>
            </div>

            <div class="hero__badges">
                <div class="hero__badge">
                    <span class="hero__badge-icon"><svg viewBox="0 0 24 24" width="18" height="18"><path d="M12 3l7 3v5c0 5-3 8.5-7 10-4-1.5-7-5-7-10V6z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="m9 12 2 2 4-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                    <div><strong>Safe Transactions</strong><span>Shop with confidence</span></div>
                </div>
                <div class="hero__badge">
                    <span class="hero__badge-icon"><svg viewBox="0 0 24 24" width="18" height="18"><path d="M2 17V8a1 1 0 0 1 1-1h9v10H3a1 1 0 0 1-1-1Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 10h4l4 4v3h-8z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><circle cx="6.5" cy="18" r="1.6" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="16.5" cy="18" r="1.6" fill="none" stroke="currentColor" stroke-width="1.6"/></svg></span>
                    <div><strong>Wide Selection</strong><span>Thousands of listings</span></div>
                </div>
                <div class="hero__badge">
                    <span class="hero__badge-icon"><svg viewBox="0 0 24 24" width="18" height="18"><path d="M4 13a8 8 0 0 1 16 0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><rect x="2.5" y="13" width="4" height="6" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="17.5" y="13" width="4" height="6" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.8"/></svg></span>
                    <div><strong>24/7 Support</strong><span>We're here to help</span></div>
                </div>
                <div class="hero__badge">
                    <span class="hero__badge-icon"><svg viewBox="0 0 24 24" width="18" height="18"><path d="M12 2a7 7 0 0 1 7 7c0 5-7 13-7 13S5 14 5 9a7 7 0 0 1 7-7z" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="9" r="2.5" fill="none" stroke="currentColor" stroke-width="1.8"/></svg></span>
                    <div><strong>Local &amp; National</strong><span>Kigali &amp; beyond</span></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- =================================================================
     2. POPULAR CATEGORIES (compact grid, 8–10 cards)
     ================================================================= -->
<section class="section section--compact browse-category-showcase">
    <div class="container">
        <div class="browse-category-showcase__head">
            <div class="browse-category-showcase__intro">
                <span class="browse-category-showcase__eyebrow">Browse by Category</span>
                <h2 class="section__title section__title--compact"><?php echo e(home_copy('browse_categories_title', t('sections.browse_cats'))); ?></h2>
                <p class="browse-category-showcase__sub">Find what you need, explore our wide range of products, properties, vehicles and more.</p>
            </div>
            <div class="browse-category-showcase__note" aria-hidden="true">
                <span>Everything you need</span>
                <strong>in one place</strong>
                <i></i>
            </div>
            <a class="browse-category-showcase__all" href="<?php echo APP_URL; ?>/pages/categories.php"><?php echo e(home_copy('see_all', t('sections.see_all'))); ?> <span>→</span></a>
        </div>

        <div class="browse-category-showcase__grid">
            <?php
            $categoryVisuals = [
                'vehicles'          => 'vehicle',
                'phones-electronics' => 'phone',
                'computers-machines' => 'laptop',
                'fashion'           => 'blog-sell',
                'homes-land'        => 'house',
                'furniture'         => 'sofa',
                'agriculture'       => 'agriculture',
                'event-equipment'   => 'tent',
                'services'          => 'service',
                'other-products'    => 'hero',
            ];
            $categoryTones = ['teal','orange','blue','violet','gold','green','lime','coral','indigo','slate'];
            $categoryIndex = 0;
            foreach ($categories as $cat):
                $categoryIndex++;
                $slug = (string)($cat['slug'] ?? '');
                $visual = $categoryVisuals[$slug] ?? 'hero';
                $tone = $categoryTones[($categoryIndex - 1) % count($categoryTones)];
                $count = (int)($cat['count'] ?? 0);
                $countLabel = $count === 1 ? 'listing' : 'listings';
            ?>
                <a class="browse-category-card browse-category-card--<?php echo e($tone); ?>" href="<?php echo APP_URL; ?>/pages/category.php?slug=<?php echo e($slug); ?>">
                    <img class="browse-category-card__image" loading="lazy" src="<?php echo e(real_image($visual, 'default')); ?>" alt="">
                    <span class="browse-category-card__shade"></span>
                    <span class="browse-category-card__topline">CATEGORY <b><?php echo str_pad((string)$categoryIndex, 2, '0', STR_PAD_LEFT); ?></b></span>
                    <span class="browse-category-card__content">
                        <strong class="browse-category-card__name"><?php echo e(t_category($cat['name_key'] ?? null, $cat['name'])); ?></strong>
                        <span class="browse-category-card__meta"><?php echo number_format($count); ?> <?php echo $countLabel; ?></span>
                    </span>
                    <span class="browse-category-card__action" aria-hidden="true">→</span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- =================================================================
     COMMUNITY USERS — compact scrollable listing
     ================================================================= -->
<section class="section section--compact community-users-section community-users-section--panel">
    <div class="container">
      <div class="community-users-half-layout">
        <div class="home-community-column home-community-column--left">
        <div class="community-users-half">
        <div class="community-users-heading community-users-heading--panel">
            <div>
                <span class="community-users-eyebrow"><span class="community-users-eyebrow__dot"></span> COMMUNITY</span>
                <div class="community-users-title-line">
                    <h2 class="section__title section__title--compact"><?php echo e(home_copy('community_title', 'People on Isoko Ryacu')); ?></h2>
                    <span class="community-online-sign" title="Users active in the last 5 minutes"><span class="community-online-sign__dot"></span><strong data-online-count><?php echo count(array_filter($homeUsers, static fn($u) => !empty($u['is_online']))); ?></strong><span>online</span></span>
                </div>
                <p class="community-users-panel__sub"><?php echo e(home_copy('community_subtitle', 'Browse members and open a profile to see more.')); ?></p>
            </div>
            <a class="community-users-view community-users-view--small" href="<?php echo APP_URL; ?>/pages/users.php">
                <span>View all</span>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6"/></svg>
            </a>
        </div>
        <?php if ($homeUsers): ?>
        <div class="community-users-panel" id="community-users-grid">
            <?php foreach ($homeUsers as $person):
                $avatar = !empty($person['avatar_path']) ? image_or_default($person['avatar_path']) : '';
                $initial = strtoupper(mb_substr($person['full_name'], 0, 1));
                $online = !empty($person['is_online']);
                $waDigits = preg_replace('/[^0-9]/', '', (string)$person['whatsapp']);
            ?>
            <article class="community-user-row" data-user-id="<?php echo (int)$person['id']; ?>">
                <a class="community-user-row__profile" href="<?php echo APP_URL; ?>/pages/user-profile.php?id=<?php echo (int)$person['id']; ?>">
                    <span class="community-user-row__avatar-wrap">
                        <?php if ($avatar): ?><img class="community-user-row__avatar" src="<?php echo e($avatar); ?>" alt="<?php echo e($person['full_name']); ?>" loading="lazy"><?php else: ?><span class="community-user-row__avatar community-user-row__avatar--initial"><?php echo e($initial); ?></span><?php endif; ?>
                        <span class="community-user-row__status <?php echo $online ? 'is-online' : 'is-inactive'; ?>" data-presence-dot></span>
                    </span>
                    <span class="community-user-row__identity">
                        <span class="community-user-row__name"><?php echo e($person['full_name']); ?><?php if ((int)$person['is_verified'] === 1): ?><span class="community-user-row__verified">✓</span><?php endif; ?></span>
                        <span class="community-user-row__meta"><span data-presence-label><?php echo $online ? 'Online now' : (!empty($person['last_seen_at']) ? 'Last seen ' . e(time_ago($person['last_seen_at'])) : 'No recent activity'); ?></span><?php if (!empty($person['is_seller'])): ?><span>• Seller</span><?php endif; ?></span>
                    </span>
                </a>
                <span class="community-user-row__actions">
                    <?php if ($waDigits): ?><a class="community-user-row__whatsapp" href="https://wa.me/<?php echo e($waDigits); ?>" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp <?php echo e($person['full_name']); ?>" title="WhatsApp"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 3.5A11.9 11.9 0 0 0 12.05 0C5.49 0 .15 5.34.15 11.9c0 2.1.55 4.15 1.6 5.96L.04 24l6.28-1.65a11.9 11.9 0 0 0 5.73 1.46h.01c6.56 0 11.9-5.34 11.9-11.9a11.9 11.9 0 0 0-3.46-8.41ZM12.06 21.8h-.01a9.9 9.9 0 0 1-5.05-1.38l-.36-.21-3.73.98 1-3.64-.23-.37a9.9 9.9 0 1 1 8.38 4.62Zm5.43-7.42c-.3-.15-1.77-.87-2.05-.97-.28-.1-.49-.15-.69.15-.2.3-.79.97-.97 1.17-.18.2-.36.22-.66.07-.3-.15-1.25-.46-2.38-1.47-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.61.14-.14.3-.36.44-.54.15-.18.2-.31.3-.51.1-.2.05-.38-.03-.53-.07-.15-.69-1.66-.95-2.28-.25-.6-.5-.52-.69-.53h-.59c-.2 0-.53.07-.81.38-.28.3-1.06 1.04-1.06 2.54s1.09 2.95 1.24 3.15c.15.2 2.14 3.27 5.19 4.59.73.32 1.3.51 1.75.65.74.24 1.41.2 1.94.12.59-.09 1.77-.72 2.02-1.41.25-.69.25-1.28.18-1.41-.07-.13-.28-.2-.58-.35Z"/></svg></a><?php endif; ?>
                    <a class="community-user-row__arrow" href="<?php echo APP_URL; ?>/pages/user-profile.php?id=<?php echo (int)$person['id']; ?>" aria-label="View profile"><svg viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></a>
                </span>
            </article>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="empty-state"><p>No active users to display yet.</p></div>
        <?php endif; ?>
        </div>
        <aside class="home-calendar-module" aria-label="Events calendar">
            <div class="home-calendar-module__head">
                <div>
                    <span class="home-calendar-module__eyebrow"><span class="home-calendar-module__dot"></span> CALENDAR</span>
                    <h3><?php echo e(date('F Y', strtotime($calendarMonthStart))); ?></h3>
                </div>
                <a class="home-calendar-module__manage" href="<?php echo APP_URL; ?>/pages/admin/community-posts.php">View events</a>
            </div>
            <div class="home-calendar">
                <div class="home-calendar__weekdays">
                    <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $weekday): ?><span><?php echo $weekday; ?></span><?php endforeach; ?>
                </div>
                <div class="home-calendar__days">
                    <?php for ($blank = 0; $blank < $calendarFirstDow; $blank++): ?><span class="home-calendar__day is-empty"></span><?php endfor; ?>
                    <?php for ($day = 1; $day <= $calendarDaysInMonth; $day++):
                        $hasEvent = !empty($calendarEventsByDay[$day]);
                        $isToday = ($day === (int)date('j'));
                    ?>
                        <span class="home-calendar__day <?php echo $hasEvent ? 'has-event' : ''; ?> <?php echo $isToday ? 'is-today' : ''; ?>" <?php echo $hasEvent ? 'title="'.e(count($calendarEventsByDay[$day])).' event(s)"' : ''; ?>>
                            <?php echo $day; ?><?php if ($hasEvent): ?><i></i><?php endif; ?>
                        </span>
                    <?php endfor; ?>
                </div>
            </div>
            <div class="home-calendar__upcoming">
                <div class="home-calendar__upcoming-title">Upcoming events</div>
                <?php if ($calendarUpcoming): foreach ($calendarUpcoming as $event): ?>
                    <a class="home-calendar-event" href="<?php echo APP_URL; ?>/pages/admin/community-posts.php?edit=<?php echo (int)$event['id']; ?>">
                        <span class="home-calendar-event__date"><strong><?php echo e(date('j', strtotime($event['event_date']))); ?></strong><small><?php echo e(date('M', strtotime($event['event_date']))); ?></small></span>
                        <span class="home-calendar-event__body"><strong><?php echo e($event['title']); ?></strong><small><?php echo e(date('g:i A', strtotime($event['event_date']))); ?><?php if (!empty($event['location'])): ?> · <?php echo e($event['location']); ?><?php endif; ?></small></span>
                    </a>
                <?php endforeach; else: ?>
                    <div class="home-calendar__empty">No events scheduled for this month.</div>
                <?php endif; ?>
            </div>
        </aside>
        </div>
        <div class="home-community-column home-community-column--right">
        <!-- Right half: real admin-managed YouTube video -->
        <aside class="home-youtube-module" aria-label="Featured YouTube video">
            <div class="home-youtube-module__head">
                <div>
                    <span class="home-youtube-module__eyebrow"><span class="home-youtube-module__dot"></span> FEATURED VIDEO</span>
                    <h3><?php echo e(home_copy('video_title', 'From Isoko Ryacu')); ?></h3>
                </div>
                <?php if (is_admin()): ?><a class="home-youtube-module__manage" href="<?php echo APP_URL; ?>/pages/admin/youtube.php">Manage</a><?php endif; ?>
            </div>
            <?php if ($homeVideo): ?>
                <div class="home-youtube-module__media">
                    <iframe src="<?php echo e(youtube_embed_url($homeVideo['youtube_video_id'])); ?>" title="<?php echo e($homeVideo['title']); ?>" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>
                </div>
                <div class="home-youtube-module__body">
                    <h4><?php echo e($homeVideo['title']); ?></h4>
                    <?php if (!empty($homeVideo['description'])): ?><p><?php echo e(excerpt($homeVideo['description'], 105)); ?></p><?php endif; ?>
                </div>
            <?php else: ?>
                <div class="home-youtube-module__empty">
                    <div class="home-youtube-module__play">▶</div>
                    <strong>No featured video yet</strong>
                    <span>An admin can add a YouTube video from the dashboard.</span>
                    <?php if (is_admin()): ?><a href="<?php echo APP_URL; ?>/pages/admin/youtube.php">Add YouTube video →</a><?php endif; ?>
                </div>
            <?php endif; ?>
        </aside>
        <aside class="home-updates-module" aria-label="Events and announcements">
            <div class="home-updates-module__head">
                <div><span class="home-updates-module__eyebrow"><span class="home-updates-module__dot"></span> COMMUNITY UPDATES</span><h3><?php echo e(home_copy('updates_title', 'Events & Announcements')); ?></h3></div>
                <?php if (is_admin()): ?><a href="<?php echo APP_URL; ?>/pages/admin/community-posts.php" class="home-updates-module__manage">Manage</a><?php endif; ?>
            </div>
            <div class="home-updates-list">
            <?php if ($homePosts): foreach ($homePosts as $post): ?>
                <article class="home-update-item">
                    <div class="home-update-item__icon <?php echo e($post['type']); ?>"><?php echo $post['type']==='event'?'EVENT':($post['type']==='notice'?'NOTICE':'NEWS'); ?></div>
                    <div class="home-update-item__body">
                        <div class="home-update-item__top"><span class="home-update-item__type"><?php echo e(ucfirst($post['type'])); ?></span><?php if (!empty($post['event_date'])): ?><time><?php echo e(date('M j, g:i A', strtotime($post['event_date']))); ?></time><?php endif; ?></div>
                        <h4><?php echo e($post['title']); ?></h4>
                        <?php if (!empty($post['body'])): ?><p><?php echo e(excerpt($post['body'], 92)); ?></p><?php endif; ?>
                        <?php if (!empty($post['location'])): ?><span class="home-update-item__location"><?php echo e($post['location']); ?></span><?php endif; ?>
                        <?php if (!empty($post['cta_url'])): ?><a href="<?php echo e($post['cta_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo e($post['cta_label'] ?: 'Learn more'); ?> →</a><?php endif; ?>
                    </div>
                </article>
            <?php endforeach; else: ?>
                <div class="home-updates-empty"><strong>No updates yet</strong><span>Events and announcements posted by Admin will appear here.</span><?php if (is_admin()): ?><a href="<?php echo APP_URL; ?>/pages/admin/community-posts.php">Create the first post →</a><?php endif; ?></div>
            <?php endif; ?>
            </div>
        </aside>

        </div>
      </div>
    </div>
</section>

<!-- =================================================================
     LIVE PRODUCTS CAROUSEL — REAL MARKETPLACE INVENTORY
     Automatically rotates through active products every ~1.5 seconds.
     ================================================================= -->
<?php if ($liveProducts): ?>
<section class="section section--compact home-live-products" aria-labelledby="live-products-title">
    <div class="container">
        <div class="section__head section__head--compact">
            <div>
                <span class="home-live-products__eyebrow"><span class="home-live-products__dot"></span> Live marketplace</span>
                <h2 id="live-products-title" class="section__title section__title--compact"><?php echo e(home_copy('live_products_title', 'Products from IsokoRyacu')); ?></h2>
            </div>
            <a class="section__link" href="<?php echo APP_URL; ?>/pages/explore.php"><?php echo e(home_copy('view_all', t('sections.view_all'))); ?> →</a>
        </div>

        <div class="home-live-products__viewport" data-live-carousel data-interval="1500">
            <div class="home-live-products__track">
                <?php foreach ($liveProducts as $product): ?>
                    <div class="home-live-products__slide">
                        <?php echo render_listing_card_home($product); ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (count($liveProducts) > 1): ?>
                <button class="home-live-products__nav home-live-products__nav--prev" type="button" aria-label="Previous products" data-live-prev>
                    <span aria-hidden="true">‹</span>
                </button>
                <button class="home-live-products__nav home-live-products__nav--next" type="button" aria-label="Next products" data-live-next>
                    <span aria-hidden="true">›</span>
                </button>
            <?php endif; ?>
        </div>

        <?php if (count($liveProducts) > 1): ?>
            <div class="home-live-products__progress" aria-hidden="true">
                <span class="home-live-products__progress-bar"></span>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<script>
(function () {
    var carousel = document.querySelector('[data-live-carousel]');
    if (!carousel) return;
    var track = carousel.querySelector('.home-live-products__track');
    var slides = Array.prototype.slice.call(carousel.querySelectorAll('.home-live-products__slide'));
    if (!track || slides.length < 2) return;

    var index = 0;
    var timer = null;
    var interval = Math.max(900, parseInt(carousel.dataset.interval || '1500', 10));
    var paused = false;
    var startX = 0;
    var deltaX = 0;

    function visibleCount() {
        if (window.innerWidth <= 640) return 1;
        if (window.innerWidth <= 980) return 2;
        return 4;
    }

    function maxIndex() {
        return Math.max(0, slides.length - visibleCount());
    }

    function update(animate) {
        index = Math.min(index, maxIndex());
        var gap = parseFloat(getComputedStyle(track).gap || '16') || 16;
        var width = slides[0].getBoundingClientRect().width;
        track.style.transition = animate ? 'transform .55s cubic-bezier(.22,.61,.36,1)' : 'none';
        track.style.transform = 'translate3d(-' + ((width + gap) * index) + 'px,0,0)';
        var bar = carousel.parentElement.querySelector('.home-live-products__progress-bar');
        if (bar) {
            var total = maxIndex() || 1;
            bar.style.width = (((index + 1) / (total + 1)) * 100) + '%';
        }
    }

    function next() {
        index = index >= maxIndex() ? 0 : index + 1;
        update(true);
    }
    function prev() {
        index = index <= 0 ? maxIndex() : index - 1;
        update(true);
    }
    function start() {
        clearInterval(timer);
        timer = setInterval(function () { if (!paused) next(); }, interval);
    }

    var nextBtn = carousel.querySelector('[data-live-next]');
    var prevBtn = carousel.querySelector('[data-live-prev]');
    if (nextBtn) nextBtn.addEventListener('click', function () { next(); start(); });
    if (prevBtn) prevBtn.addEventListener('click', function () { prev(); start(); });

    carousel.addEventListener('mouseenter', function () { paused = true; });
    carousel.addEventListener('mouseleave', function () { paused = false; });
    carousel.addEventListener('focusin', function () { paused = true; });
    carousel.addEventListener('focusout', function () { paused = false; });
    carousel.addEventListener('touchstart', function (e) {
        startX = e.changedTouches[0].clientX; deltaX = 0; paused = true;
    }, { passive: true });
    carousel.addEventListener('touchmove', function (e) {
        deltaX = e.changedTouches[0].clientX - startX;
    }, { passive: true });
    carousel.addEventListener('touchend', function () {
        if (Math.abs(deltaX) > 45) { deltaX < 0 ? next() : prev(); }
        paused = false; start();
    }, { passive: true });

    window.addEventListener('resize', function () {
        index = Math.min(index, maxIndex());
        update(false);
    });

    update(false);
    start();
})();
</script>

<!-- =================================================================
     3. FEATURED PRODUCTS
     ================================================================= -->
<?php if ($featuredProducts): ?>
<section class="section section--compact">
    <div class="container">
        <div class="section__head section__head--compact">
            <h2 class="section__title section__title--compact"><?php echo e(home_copy('featured_products_title', 'Featured Products')); ?></h2>
            <a class="section__link" href="<?php echo APP_URL; ?>/pages/explore.php?sort=featured">View all →</a>
        </div>
        <?php echo render_listing_grid_home($featuredProducts); ?>
    </div>
</section>
<?php endif; ?>

<!-- =================================================================
     4. TRENDING LISTINGS ("Ibiri Kurebwa Cyane")
     ================================================================= -->
<section class="section section--compact">
    <div class="container">
        <div class="section__head section__head--compact">
            <h2 class="section__title section__title--compact"><?php echo e(home_copy('trending_title', t('sections.trending'))); ?></h2>
            <a class="section__link" href="<?php echo APP_URL; ?>/pages/explore.php?sort=popular"><?php echo e(home_copy('view_all', t('sections.view_all'))); ?> →</a>
        </div>
        <?php echo render_listing_grid_home($trending); ?>
    </div>
</section>

<!-- =================================================================
     4. RENTAL SECTION ("Ibikodeshwa")
     ================================================================= -->
<section class="section section--compact">
    <div class="container">
        <div class="section__head section__head--compact">
            <h2 class="section__title section__title--compact"><?php echo e(home_copy('rent_title', t('sections.things_rent'))); ?></h2>
            <a class="section__link" href="<?php echo APP_URL; ?>/pages/explore.php?type=rent"><?php echo e(home_copy('all_rentals', t('sections.all_rentals'))); ?> →</a>
        </div>
        <?php echo render_listing_grid_home($rentals, 'empty.no_rentals'); ?>
    </div>
</section>

<!-- =================================================================
     5. NEAR YOU (real location-based — Phase 6)
     ================================================================= -->
<?php if ($nearYou): ?>
<section class="section section--compact">
    <div class="container">
        <div class="section__head section__head--compact">
            <h2 class="section__title section__title--compact"><?php echo e(t('sections.near_city', ['city' => $nearCity ?: t('sections.this_area')])); ?></h2>
            <a class="section__link" href="<?php echo APP_URL; ?>/pages/locations.php"><?php echo e(t('sections.browse_locations')); ?> →</a>
        </div>
        <?php echo render_listing_grid_home($nearYou); ?>
    </div>
</section>
<?php endif; ?>

<!-- =================================================================
     5b. FREQUENTLY FAVORITED (real favorites_count — Phase 6)
     ================================================================= -->
<?php if ($favorites): ?>
<section class="section section--compact">
    <div class="container">
        <div class="section__head section__head--compact">
            <h2 class="section__title section__title--compact"><?php echo e(t('sections.most_saved')); ?></h2>
            <a class="section__link" href="<?php echo APP_URL; ?>/pages/explore.php?sort=popular"><?php echo e(home_copy('view_all', t('sections.view_all'))); ?> →</a>
        </div>
        <?php echo render_listing_grid_home($favorites); ?>
    </div>
</section>
<?php endif; ?>

<!-- =================================================================
     5c. RECENTLY VIEWED (your own browsing history — Phase 6)
     ================================================================= -->
<?php if ($recentlyViewed): ?>
<section class="section section--compact">
    <div class="container">
        <div class="section__head section__head--compact">
            <h2 class="section__title section__title--compact"><?php echo e(t('sections.recently_viewed')); ?></h2>
        </div>
        <?php echo render_listing_grid_home($recentlyViewed); ?>
    </div>
</section>
<?php endif; ?>

<!-- =================================================================
     5d. RECOMMENDED CATEGORIES (from your real activity — Phase 6)
     ================================================================= -->
<?php if ($recCats): ?>
<section class="section section--compact home-reco-section">
    <div class="container">
        <div class="section__head section__head--compact">
            <div>
                <span class="home-section-kicker">Personal picks</span>
                <h2 class="section__title section__title--compact"><?php echo e(t('sections.recommended_cats')); ?></h2>
                <p class="section__sub">Categories selected from real marketplace activity.</p>
            </div>
            <a class="section__link" href="<?php echo APP_URL; ?>/pages/categories.php"><?php echo e(home_copy('see_all', t('sections.see_all'))); ?> →</a>
        </div>
        <div class="pro-cat-grid">
            <?php foreach ($recCats as $cat):
                $catSlug = strtolower((string)($cat['slug'] ?? ''));
                $catVisual = 'category';
                foreach ([
                    'vehicle'=>'vehicle','car'=>'vehicle','phone'=>'phone','electronic'=>'phone','computer'=>'laptop','laptop'=>'laptop',
                    'home'=>'house','land'=>'land','fashion'=>'fashion','motorcycle'=>'motorcycle','scooter'=>'motorcycle','agric'=>'agriculture','livestock'=>'agriculture'
                ] as $needle=>$visual) { if (strpos($catSlug,$needle)!==false) { $catVisual=$visual; break; } }
            ?>
                <a class="pro-cat-card" href="<?php echo APP_URL; ?>/pages/category.php?slug=<?php echo e($cat['slug']); ?>">
                    <div class="pro-cat-card__media"><img loading="lazy" src="<?php echo e(real_image($catVisual, 'default')); ?>" alt=""></div>
                    <div class="pro-cat-card__body">
                        <div><span class="pro-cat-card__name"><?php echo e(t_category($cat['name_key'] ?? null, $cat['name'])); ?></span><span class="pro-cat-card__count"><?php echo (int)$cat['listing_count']; ?> listings</span></div>
                        <span class="pro-cat-card__arrow">→</span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- =================================================================
     6. MARKET INSIGHTS (REAL data — Phase 4)
     ================================================================= -->
<section class="section section--compact home-insights-section">
    <div class="container">
        <div class="home-insights-panel">
            <div class="section__head section__head--compact home-insights-head">
                <div><span class="home-section-kicker home-section-kicker--light">Live marketplace</span><h2 class="section__title section__title--compact"><?php echo e(home_copy('market_insights_title', t('sections.market_insights'))); ?></h2><p class="section__sub"><?php echo e(home_copy('market_insights_sub', t('sections.live_insights_sub'))); ?></p></div>
                <a class="home-insights-link" href="<?php echo APP_URL; ?>/pages/market-insights.php"><?php echo e(home_copy('view_all', t('sections.view_all'))); ?> →</a>
            </div>
            <div class="pro-insight-grid">
                <div class="pro-insight-card"><div class="pro-insight-icon">✦</div><div><span>New arrivals</span><strong><?php echo number_format($newThisWeek); ?></strong><small>Listings this week</small></div></div>
                <a class="pro-insight-card" href="<?php echo $mostViewed ? APP_URL.'/pages/listing-details.php?id='.(int)$mostViewed['id'] : '#'; ?>"><div class="pro-insight-icon">↗</div><div><span>Most popular</span><strong class="pro-insight-title"><?php echo e($mostViewed ? excerpt($mostViewed['title'], 28) : 'No activity yet'); ?></strong><small><?php echo $mostViewed ? number_format((int)$mostViewed['views_count']).' views' : 'Real marketplace data'; ?></small></div></a>
                <div class="pro-insight-card"><div class="pro-insight-icon">⌂</div><div><span>Things to rent</span><strong><?php echo number_format($rentalCount); ?></strong><small>Active rental listings</small></div></div>
                <a class="pro-insight-card" href="<?php echo $topCategory ? APP_URL.'/pages/category.php?slug='.e($topCategory['slug']) : APP_URL.'/pages/categories.php'; ?>"><div class="pro-insight-icon">◆</div><div><span>Top category</span><strong class="pro-insight-title"><?php echo e($topCategory ? t_category($topCategory['name_key'] ?? null, $topCategory['name']) : 'No activity yet'); ?></strong><small><?php echo $topCategory ? number_format((int)$topCategory['views']).' views' : 'Browse categories'; ?></small></div></a>
            </div>
        </div>
    </div>
</section>

<!-- =================================================================
     7. MARKET BLOG (3 featured articles)
     ================================================================= -->
<section class="section section--compact">
    <div class="container">
        <div class="section__head section__head--compact">
            <h2 class="section__title section__title--compact"><?php echo e(home_copy('market_blog_title', t('sections.market_blog'))); ?></h2>
            <a class="section__link" href="<?php echo APP_URL; ?>/pages/blog/index.php"><?php echo e(home_copy('all_articles', t('sections.all_articles'))); ?> →</a>
        </div>
        <?php if (!$blog): ?>
            <div class="empty-state"><p><?php echo e(t('empty.no_articles')); ?></p></div>
        <?php else: ?>
        <div class="grid grid--3">
            <?php foreach ($blog as $post):
                $coverMap = [
                    'how-to-buy-safely' => 'blog-safety',
                    'how-to-sell-online' => 'blog-sell',
                    'renting-property-guide' => 'blog-rent',
                    'avoiding-scams' => 'blog-scam',
                    'choosing-used-laptop' => 'blog-laptop',
                ];
                $cover = $coverMap[$post['slug']] ?? 'blog-default';
            ?>
                <a class="blog-card card card--hover" href="<?php echo APP_URL; ?>/pages/blog/post.php?slug=<?php echo e($post['slug']); ?>">
                    <div class="blog-card__media">
                        <img loading="lazy" src="<?php echo real_image($cover, 'blog-default'); ?>" alt="">
                    </div>
                    <span class="blog-card__category"><?php echo e($post['category']); ?></span>
                    <h3 class="blog-card__title"><?php echo e($post['title']); ?></h3>
                    <p class="blog-card__excerpt"><?php echo excerpt($post['excerpt'], 110); ?></p>
                    <div class="blog-card__meta">
                        <span><?php echo (int) $post['views_count']; ?> <?php echo e(t('listings.views_count')); ?></span>
                        <span>· <?php echo time_ago($post['published_at']); ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- =================================================================
     8. SINGLE CTA ("Ufite icyo ugurisha cyangwa ukodesha?")
     ================================================================= -->
<section class="section section--compact">
    <div class="container">
        <div class="cta-band cta-band--compact">
            <h2><?php echo e(home_copy('cta_title', t('cta.title'))); ?></h2>
            <p><?php echo e(home_copy('cta_body', t('cta.body'))); ?></p>
            <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
                <a href="<?php echo APP_URL; ?>/pages/sell.php" class="btn btn--primary btn--lg"><?php echo e(home_copy('cta_sell_item', t('cta.sell_item'))); ?></a>
                <?php if (!is_logged_in()): ?>
                    <a href="<?php echo APP_URL; ?>/pages/register.php?type=seller" class="btn btn--secondary btn--lg"><?php echo e(home_copy('cta_register_seller', t('cta.register_as_seller'))); ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
