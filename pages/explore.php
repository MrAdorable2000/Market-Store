<?php
/**
 * pages/explore.php
 * --------------------------------------------------------------------
 * Phase 2: Powerful search with filters, pagination, mobile drawer.
 *
 * URL params supported:
 *   ?q=              search keywords (natural language smart-parsed:
 *                    "houses in Kigali", "3 bedroom house", "cheap rental" …)
 *   ?type=sell|rent  listing type filter
 *   ?category=       category slug
 *   ?subcategory=     subcategory slug (Phase 2)
 *   ?location=        location text
 *   ?country=         country filter (Phase 2)
 *   ?province=        province filter (Phase 2)
 *   ?district=        district filter (Phase 2)
 *   ?min_price=       min price (Phase 2)
 *   ?max_price=       max price (Phase 2)
 *   ?condition=        condition (new/used/refurbished/for-parts) (Phase 2)
 *   ?availability=     availability (available/sold/rented) (Phase 2)
 *   ?date=             date posted (today/week/month) (Phase 2)
 *   ?bedrooms=         bedrooms (from smart search or explicit)
 *   ?sort=             newest|oldest|price_asc|price_desc|popular|relevance
 *   ?page=             pagination
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/smart_features.php';
listings_verified_ready();

// --- Read filter inputs ---
$q          = trim($_GET['q']          ?? '');
$type       = $_GET['type']             ?? '';
$catSlug    = $_GET['category']         ?? '';
$subcatSlug = $_GET['subcategory']       ?? '';
$location   = $_GET['location']         ?? '';
$country    = $_GET['country']          ?? '';
$province   = $_GET['province']         ?? '';
$district   = $_GET['district']         ?? '';
$minPrice   = (float)($_GET['min_price'] ?? 0);
$maxPrice   = (float)($_GET['max_price'] ?? 0);
$condition  = $_GET['condition']         ?? '';
$availability = $_GET['availability']    ?? 'available';
$datePosted = $_GET['date']             ?? '';
$sort       = $_GET['sort']             ?? 'relevance';
$page       = max(1, (int)($_GET['page'] ?? 1));

/* ---------- SMART SEARCH (natural language -> real filters) ----------
 * Explicit URL parameters always win; smart parsing only fills blanks.
 * Every parsed filter maps to a category/location that REALLY exists.
 */
$parsed = ['clean' => $q, 'tokens' => [], 'chips' => [],
            'filters' => ['type' => '', 'category' => '', 'location' => '', 'bedrooms' => 0, 'price_intent' => '']];
$bedrooms = (int) ($_GET['bedrooms'] ?? 0);
if ($q !== '') {
    $parsed = smart_search_parse($q);
    log_searched_term($q);                       // real search analytics
    if ($type === ''     && $parsed['filters']['type']     !== '') $type     = $parsed['filters']['type'];
    if ($catSlug === ''  && $parsed['filters']['category'] !== '') $catSlug = $parsed['filters']['category'];
    if ($location === '' && $parsed['filters']['location'] !== '') $location = $parsed['filters']['location'];
    if ($bedrooms === 0  && $parsed['filters']['bedrooms']  > 0)   $bedrooms = $parsed['filters']['bedrooms'];
    if ($sort === 'relevance' && $parsed['filters']['price_intent'] === 'asc')  $sort = 'price_asc';
    if ($sort === 'relevance' && $parsed['filters']['price_intent'] === 'desc') $sort = 'price_desc';
}

// --- Build WHERE clause dynamically ---
$where  = ['l.status = "active"'];
$params = [];

// Availability — default to "available" but allow any
if ($availability && $availability !== 'any') {
    $where[] = 'l.availability = ?';
    $params[] = $availability;
} else {
    $where[] = 'l.availability <> "expired"';
}

// Keyword search: smart-search tokens (OR) — when the parser turned every
// word into real filters the token list is empty and NO keyword filter is
// applied (the filters carry the intent). Raw-query fallback only when
// parsing produced neither tokens nor chips.
$keywords = $parsed['tokens'];
if ($q !== '' && !$keywords && !$parsed['chips']) {
    $keywords = [$q];
}
if ($keywords) {
    $kwWhere = [];
    foreach ($keywords as $kw) {
        $kwWhere[] = '(l.title LIKE ? OR l.description LIKE ? OR l.location LIKE ?)';
        $like = '%' . $kw . '%';
        array_push($params, $like, $like, $like);
    }
    $where[] = '(' . implode(' OR ', $kwWhere) . ')';
}
if ($type === 'sell' || $type === 'rent') {
    $where[] = 'l.listing_type = ?';
    $params[] = $type;
}
if ($bedrooms > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM listing_attributes la WHERE la.listing_id = l.id
               AND la.attr_key = \'bedrooms\' AND CAST(la.attr_value AS UNSIGNED) >= ?)';
    $params[] = $bedrooms;
}
if ($catSlug) {
    $where[] = 'c.slug = ?';
    $params[] = $catSlug;
}
if ($subcatSlug) {
    $where[] = 's.slug = ?';
    $params[] = $subcatSlug;
}
if ($location) {
    $where[] = '(l.location LIKE ? OR l.area LIKE ? OR l.district LIKE ?)';
    $params[] = '%' . $location . '%';
    $params[] = '%' . $location . '%';
    $params[] = '%' . $location . '%';
}
if ($country) { $where[] = 'l.country = ?'; $params[] = $country; }
if ($province) { $where[] = 'l.province = ?'; $params[] = $province; }
if ($district) { $where[] = 'l.district = ?'; $params[] = $district; }
if ($minPrice > 0) { $where[] = 'l.price >= ?'; $params[] = $minPrice; }
if ($maxPrice > 0) { $where[] = 'l.price <= ?'; $params[] = $maxPrice; }
if (in_array($condition, ['new','used','refurbished','for-parts'], true)) {
    $where[] = 'l.condition_state = ?';
    $params[] = $condition;
}
if ($datePosted === 'today') {
    $where[] = 'l.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)';
} elseif ($datePosted === 'week') {
    $where[] = 'l.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
} elseif ($datePosted === 'month') {
    $where[] = 'l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
}

$orderSql = match ($sort) {
    'newest'     => 'l.created_at DESC',
    'oldest'     => 'l.created_at ASC',
    'price_asc'  => 'l.price ASC',
    'price_desc' => 'l.price DESC',
    'popular'    => 'l.views_count DESC',
    default      => 'l.is_featured DESC, l.created_at DESC',
};

$perPage = (int) LISTINGS_PER_PAGE;
$offset  = ($page - 1) * $perPage;

$sql = 'SELECT l.id, l.title, l.slug, l.price, l.currency, l.listing_type, l.location,
               l.condition_state, l.availability, l.views_count, l.favorites_count,
               l.price_per_month, l.price_per_week, l.price_per_day,
               l.is_featured, l.is_verified, l.created_at,
               c.slug AS category_slug, c.name AS category_name, c.name_key AS category_name_key,
               (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image,
               u.full_name AS seller_name, u.is_verified AS seller_verified
        FROM listings l
        INNER JOIN categories c ON c.id = l.category_id
        LEFT JOIN subcategories s ON s.id = l.subcategory_id
        INNER JOIN users u ON u.id = l.seller_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY ' . $orderSql . '
        LIMIT ' . $perPage . ' OFFSET ' . $offset;

$stmt = db()->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll();

$countSql = 'SELECT COUNT(*) FROM listings l
             INNER JOIN categories c ON c.id = l.category_id
             LEFT JOIN subcategories s ON s.id = l.subcategory_id
             WHERE ' . implode(' AND ', $where);
$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

// Categories for sidebar
$allCats = db()->query('SELECT id, name, name_key, slug FROM categories WHERE parent_id IS NULL AND is_active = 1 ORDER BY display_order')->fetchAll();

// Real locations for the filter dropdown (from actual listing data)
$realLocations = [];
foreach (location_activity(15) as $locRow) {
    if ($locRow['city'] !== '') $realLocations[] = $locRow['city'];
}

$pageTitle = $q ? t('search.results_for') . ': ' . $q : t('search.explore');
$activePage = 'explore';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container section--tight" style="padding-top:24px;">
    <div style="margin:14px 0 4px;">
        <?php echo back_button(APP_URL . '/', t('buttons.back'), 'solid'); ?>
    </div>
    <div class="section__head" style="margin-bottom:18px;">
        <div>
            <h1 class="section__title" style="font-size:1.6rem;">
                <?php if ($q): ?><?php echo e(t('search.results_for')); ?> "<?php echo e($q); ?>"
                <?php elseif ($type === 'rent'): ?><?php echo e(t('search.rentals')); ?>
                <?php elseif ($type === 'sell'): ?><?php echo e(t('search.items_for_sale')); ?>
                <?php else: ?><?php echo e(t('search.explore')); ?><?php endif; ?>
            </h1>
            <p class="section__sub" style="margin-top:4px;">
                <?php echo $total; ?> <?php echo $total===1 ? e(t('search.found')) : e(t('search.found_plural')); ?>
                <?php echo $location ? e(t('search.in')) . ' ' . e($location) : ''; ?>.
            </p>
        </div>
        <div class="sortbar">
            <label for="sort" class="text-mute" style="font-size:13px;"><?php echo e(t('search.sort_by')); ?></label>
            <select id="sort" onchange="window.location.href=updateParam('sort', this.value)">
                <option value="relevance"   <?php echo $sort==='relevance'?'selected':''; ?>><?php echo e(t('search.relevance')); ?></option>
                <option value="newest"      <?php echo $sort==='newest'?'selected':''; ?>><?php echo e(t('search.newest')); ?></option>
                <option value="oldest"      <?php echo $sort==='oldest'?'selected':''; ?>><?php echo e(t('search.oldest')); ?></option>
                <option value="price_asc"   <?php echo $sort==='price_asc'?'selected':''; ?>><?php echo e(t('search.price_low')); ?></option>
                <option value="price_desc"  <?php echo $sort==='price_desc'?'selected':''; ?>><?php echo e(t('search.price_high')); ?></option>
                <option value="popular"     <?php echo $sort==='popular'?'selected':''; ?>><?php echo e(t('search.popular')); ?></option>
            </select>
        </div>
    </div>

    <!-- Smart search: what we understood (only shown when the parser detected real filters) -->
    <?php if ($q !== '' && $parsed['chips']): ?>
    <div class="smart-bar" role="status">
        <span class="smart-bar__label">
            <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path d="M12 2a4 4 0 0 1 4 4c1.7 0 3 1.6 3 3.5 0 .6-.2 1.2-.5 1.7.9.6 1.5 1.7 1.5 2.8 0 2-1.6 3.5-3.5 3.5A4 4 0 0 1 12 22a4 4 0 0 1-4.5-4.5C5.6 17.5 4 16 4 14c0-1.1.6-2.2 1.5-2.8-.3-.5-.5-1.1-.5-1.7C5 7.6 6.3 6 8 6a4 4 0 0 1 4-4z" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="M12 6v12M8.5 9.5 12 8l3.5 1.5" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
            <?php echo e(t('search.smart_understood')); ?>:
        </span>
        <?php foreach ($parsed['chips'] as $chip): ?>
            <?php
                // Each chip links to the pure filtered view (no query text)
                $chipUrl = APP_URL . '/pages/explore.php?';
                $chipParams = [];
                if ($chip['type'] === 'category')  $chipParams['category'] = $chip['value'];
                if ($chip['type'] === 'location')  $chipParams['location'] = $chip['value'];
                if ($chip['type'] === 'type')      $chipParams['type'] = $chip['value'];
                if ($chip['type'] === 'bedrooms')  $chipParams['bedrooms'] = $chip['value'];
                if ($chip['type'] === 'price')     $chipParams['sort'] = $chip['value'] === 'asc' ? 'price_asc' : 'price_desc';
            ?>
            <a class="smart-bar__chip" href="<?php echo e($chipUrl . http_build_query($chipParams)); ?>">
                <?php echo e($chip['label']); ?>
                <svg viewBox="0 0 24 24" width="12" height="12" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Mobile filter toggle button -->
    <button class="btn btn--secondary" id="filterToggle" style="display:none;margin-bottom:14px;">
        <?php echo e(t('search.filters')); ?> ▾
    </button>

    <div style="display:grid;grid-template-columns:240px 1fr;gap:20px;align-items:start;">
        <!-- Filters -->
        <aside class="filters" id="filterPanel" style="position:sticky;top:80px;">
            <h3><?php echo e(t('search.filters')); ?></h3>
            <form method="get" action="<?php echo APP_URL; ?>/pages/explore.php" id="filterForm">
                <input type="hidden" name="q" value="<?php echo e($q); ?>">
                <input type="hidden" name="sort" value="<?php echo e($sort); ?>">

                <div class="filters__group">
                    <h4><?php echo e(t('search.type')); ?></h4>
                    <label class="filters__option"><input type="radio" name="type" value="" <?php echo $type===''?'checked':''; ?>> <span><?php echo e(t('search.all')); ?></span></label>
                    <label class="filters__option"><input type="radio" name="type" value="sell" <?php echo $type==='sell'?'checked':''; ?>> <span><?php echo e(t('search.buy_tab')); ?></span></label>
                    <label class="filters__option"><input type="radio" name="type" value="rent" <?php echo $type==='rent'?'checked':''; ?>> <span><?php echo e(t('search.rent_tab')); ?></span></label>
                </div>

                <div class="filters__group">
                    <h4><?php echo e(t('search.category')); ?></h4>
                    <?php foreach ($allCats as $cat): ?>
                        <label class="filters__option">
                            <input type="radio" name="category" value="<?php echo e($cat['slug']); ?>" <?php echo $catSlug===$cat['slug']?'checked':''; ?>>
                            <span><?php echo e(t_category($cat['name_key'] ?? null, $cat['name'])); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="filters__group">
                    <h4><?php echo e(t('search.price_range')); ?></h4>
                    <div style="display:flex;gap:6px;">
                        <input type="number" name="min_price" placeholder="<?php echo e(t('search.min_price')); ?>" value="<?php echo $minPrice>0?e($minPrice):''; ?>" min="0" step="any" style="font-size:13px;">
                        <input type="number" name="max_price" placeholder="<?php echo e(t('search.max_price')); ?>" value="<?php echo $maxPrice>0?e($maxPrice):''; ?>" min="0" step="any" style="font-size:13px;">
                    </div>
                </div>

                <div class="filters__group">
                    <h4><?php echo e(t('search.condition')); ?></h4>
                    <label class="filters__option"><input type="radio" name="condition" value="" <?php echo $condition===''?'checked':''; ?>> <span><?php echo e(t('search.all')); ?></span></label>
                    <label class="filters__option"><input type="radio" name="condition" value="new" <?php echo $condition==='new'?'checked':''; ?>> <span><?php echo e(t('listings.condition_new')); ?></span></label>
                    <label class="filters__option"><input type="radio" name="condition" value="used" <?php echo $condition==='used'?'checked':''; ?>> <span><?php echo e(t('listings.condition_used')); ?></span></label>
                    <label class="filters__option"><input type="radio" name="condition" value="refurbished" <?php echo $condition==='refurbished'?'checked':''; ?>> <span><?php echo e(t('listings.condition_refurb')); ?></span></label>
                </div>

                <div class="filters__group">
                    <h4><?php echo e(t('search.availability')); ?></h4>
                    <label class="filters__option"><input type="radio" name="availability" value="available" <?php echo $availability==='available'?'checked':''; ?>> <span><?php echo e(t('listings.avail_available')); ?></span></label>
                    <label class="filters__option"><input type="radio" name="availability" value="sold" <?php echo $availability==='sold'?'checked':''; ?>> <span><?php echo e(t('listings.avail_sold')); ?></span></label>
                    <label class="filters__option"><input type="radio" name="availability" value="rented" <?php echo $availability==='rented'?'checked':''; ?>> <span><?php echo e(t('listings.avail_rented')); ?></span></label>
                    <label class="filters__option"><input type="radio" name="availability" value="any" <?php echo $availability==='any'?'checked':''; ?>> <span><?php echo e(t('search.all')); ?></span></label>
                </div>

                <div class="filters__group">
                    <h4><?php echo e(t('search.date_posted')); ?></h4>
                    <label class="filters__option"><input type="radio" name="date" value="" <?php echo $datePosted===''?'checked':''; ?>> <span><?php echo e(t('search.any_time')); ?></span></label>
                    <label class="filters__option"><input type="radio" name="date" value="today" <?php echo $datePosted==='today'?'checked':''; ?>> <span><?php echo e(t('search.today')); ?></span></label>
                    <label class="filters__option"><input type="radio" name="date" value="week" <?php echo $datePosted==='week'?'checked':''; ?>> <span><?php echo e(t('search.this_week')); ?></span></label>
                    <label class="filters__option"><input type="radio" name="date" value="month" <?php echo $datePosted==='month'?'checked':''; ?>> <span><?php echo e(t('search.this_month')); ?></span></label>
                </div>

                <div class="filters__group">
                    <h4><?php echo e(t('search.location')); ?></h4>
                    <select name="location">
                        <option value=""><?php echo e(t('search.location_all')); ?></option>
                        <?php foreach ($realLocations as $locName): ?>
                            <option value="<?php echo e($locName); ?>" <?php echo $location===$locName?'selected':''; ?>><?php echo e($locName); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn--primary btn--block"><?php echo e(t('buttons.apply')); ?></button>
                <a href="<?php echo APP_URL; ?>/pages/explore.php" class="btn btn--ghost btn--block" style="margin-top:6px;"><?php echo e(t('buttons.reset')); ?></a>
            </form>
        </aside>

        <!-- Results -->
        <div>
            <?php if (!$results): ?>
                <div class="empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="11" cy="11" r="7"/><path d="m21 21-4-4" stroke-linecap="round"/></svg>
                    <h3><?php echo e(t('empty.no_listings_search')); ?></h3>
                    <p><?php echo e(t('search.no_results_sub')); ?></p>
                    <?php if ($parsed['chips']): ?>
                        <p style="font-size:13px;margin-top:6px;">
                            <strong><?php echo e(t('search.smart_understood')); ?>:</strong>
                            <?php foreach ($parsed['chips'] as $chip): ?>
                                <span class="badge badge--brand" style="margin-left:5px;"><?php echo e($chip['label']); ?></span>
                            <?php endforeach; ?>
                        </p>
                        <?php if ($keywords): ?>
                            <p style="font-size:12.5px;color:var(--text-mute);"><?php echo e(t('search.keyword_note')); ?>: <?php echo e(implode(', ', $keywords)); ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                    <a class="btn btn--primary" href="<?php echo APP_URL; ?>/pages/explore.php"><?php echo e(t('buttons.clear_filters')); ?></a>
                </div>
            <?php else: ?>
                <div class="grid grid--3 grid--auto">
                    <?php foreach ($results as $l): ?>
                        <?php
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
                        ?>
                        <a class="listing-card" href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int) $l['id']; ?>">
                            <div class="listing-card__media">
                                <img loading="lazy" src="<?php echo $image; ?>" alt="<?php echo e($l['title']); ?>">
                                <div class="listing-card__badges"><?php echo $typeBadge; ?></div>
                                <?php if ($l['seller_verified'] ?? false): ?><i class="listing-card__vcheck" title="<?php echo e(t('trust.verified_seller')); ?>"><svg viewBox="0 0 24 24" width="10" height="10"><path d="M5 13l4 4L19 7" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg></i><?php endif; ?>
                                <button class="listing-card__fav" type="button" aria-label="<?php echo e(t('buttons.save')); ?>" data-fav-listing="<?php echo (int)$l['id']; ?>">
                                    <svg viewBox="0 0 24 24" width="16" height="16"><path d="M12 21s-7-4.5-9.5-9A4.5 4.5 0 0 1 12 5.5 4.5 4.5 0 0 1 21.5 12c-2.5 4.5-9.5 9-9.5 9z" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
                                </button>
                            </div>
                            <div class="listing-card__body">
                                <div class="listing-card__price"><?php echo e($price); ?></div>
                                <div class="listing-card__title"><?php echo e($l['title']); ?></div>
                                <div class="listing-card__seller"><span class="avatar"><?php echo e(strtoupper(substr($l['seller_name'],0,1))); ?></span><span><?php echo e($l['seller_name']); ?></span></div>
                                <div class="listing-card__meta"><span><?php echo e($l['location'] ?? 'Rwanda'); ?></span><span><?php echo time_ago($l['created_at']); ?></span></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                <nav class="pagination" aria-label="Pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo e(http_build_query(array_merge($_GET, ['page'=>$page-1]))); ?>">‹ <?php echo e(t('buttons.back')); ?></a>
                    <?php endif; ?>
                    <?php for ($i=1; $i<=$totalPages; $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="is-current"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?<?php echo e(http_build_query(array_merge($_GET, ['page'=>$i]))); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?php echo e(http_build_query(array_merge($_GET, ['page'=>$page+1]))); ?>"><?php echo e(t('common.view_all')); ?> ›</a>
                    <?php endif; ?>
                </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function updateParam(key, value) {
    var url = new URL(window.location.href);
    url.searchParams.set(key, value);
    url.searchParams.delete('page');
    return url.toString();
}
// Mobile filter drawer
(function () {
    var toggle = document.getElementById('filterToggle');
    var panel = document.getElementById('filterPanel');
    function checkMobile() {
        var isMobile = window.innerWidth <= 880;
        if (isMobile) {
            toggle.style.display = 'inline-flex';
            panel.style.position = 'static';
            panel.style.display = 'none';
            panel.classList.add('mobile-panel');
        } else {
            toggle.style.display = 'none';
            panel.style.display = 'block';
            panel.style.position = 'sticky';
            panel.classList.remove('mobile-panel');
        }
    }
    toggle.addEventListener('click', function () {
        if (panel.style.display === 'none') panel.style.display = 'block';
        else panel.style.display = 'none';
    });
    checkMobile();
    window.addEventListener('resize', checkMobile);
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php';
