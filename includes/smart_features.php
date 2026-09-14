<?php
/**
 * includes/smart_features.php
 * --------------------------------------------------------------------
 * IsokoRyacu smart-innovation engine (Phase 4 of the platform upgrade).
 *
 * EVERYTHING in this file is computed from REAL database rows — no fake
 * scores, no fabricated analytics. When the underlying data is missing the
 * helpers degrade gracefully (empty arrays / neutral scores).
 *
 * Contents:
 *   1. listings.is_verified column bootstrap (trust system, idempotent)
 *   2. Listing Quality Score        — 0..100 from real listing fields
 *   3. Marketplace Health score     — 0..100 from real platform activity
 *   4. Smart search parser          — "houses in Kigali" -> real filters
 *   5. Search-term analytics        — real searches logged to market_insights
 *   6. Recommendation queries       — nearby / recently viewed / popular
 *   7. Real marketplace insights    — views, favorites, categories, locations
 *
 * Dependencies: functions.php (e, t), config/database.php (db).
 * --------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../config/database.php';

/* =====================================================================
 * 1. TRUST SYSTEM BOOTSTRAP — listings.is_verified
 * =====================================================================
 * Kept in sync with sql/phase4_migrate.sql.  Checked at most once per
 * request; completely idempotent; never touches existing rows.
 */
function listings_verified_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $exists = db()->query("SHOW COLUMNS FROM listings LIKE 'is_verified'")->fetch();
        if (!$exists) {
            db()->exec('ALTER TABLE listings
                        ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER is_featured,
                        ADD INDEX idx_list_verified (is_verified)');
        }
        $ready = true;
    } catch (Exception $e) {
        $ready = false;
    }
    return $ready;
}

/* =====================================================================
 * 2. LISTING QUALITY SCORE (0–100)
 * =====================================================================
 * Factors + weights (total 100):
 *   Title quality      12   — length & substance
 *   Description        18   — depth of detail
 *   Category choice     8   — subcategory chosen = full marks
 *   Location           10   — real place name present
 *   Price              12   — sell: price > 0; rent: any period price > 0
 *   Images             20   — 3+ photos full, 2 -> 14, 1 -> 8, 0 -> 0
 *   Contact info        8   — seller phone on file
 *   Specificity        12   — listing_attributes richness
 *
 * The caller passes a row enriched with:
 *   images_count, attrs_count, seller_phone
 * (cheap sub-selects in the surrounding SQL).
 *
 * Returns:
 *   [
 *     'score'   => int 0-100,
 *     'percent' => int,
 *     'verdict' => 'excellent'|'good'|'fair'|'poor',
 *     'checks'  => [ ['key'=>..., 'ok'=>bool, 'partial'=>bool, 'points'=>int, 'max'=>int] ],
 *   ]
 */
function listing_quality_score(array $l): array
{
    $checks = [];
    $add = function (string $key, int $points, int $max) use (&$checks) {
        $checks[] = [
            'key'     => $key,
            'ok'      => $points >= $max,
            'partial' => $points > 0 && $points < $max,
            'points'  => $points,
            'max'     => $max,
        ];
    };

    // --- Title (12): >= 15 chars with 2+ words = strong; >= 8 = partial
    $title   = trim((string) ($l['title'] ?? ''));
    $tLen    = mb_strlen($title);
    $tWords  = count(array_filter(explode(' ', $title)));
    $add('quality.check.title', $tLen >= 15 && $tWords >= 2 ? 12 : ($tLen >= 8 ? 7 : 3), 12);

    // --- Description (18): >= 300 chars full; >= 150 -> 12; >= 60 -> 7
    $dLen = mb_strlen(trim((string) ($l['description'] ?? '')));
    $add('quality.check.description', $dLen >= 300 ? 18 : ($dLen >= 150 ? 12 : ($dLen >= 60 ? 7 : 2)), 18);

    // --- Category (8): subcategory chosen = full marks
    $add('quality.check.category', !empty($l['subcategory_id']) ? 8 : 5, 8);

    // --- Location (10): a real place string present
    $add('quality.check.location', !empty(trim((string) ($l['location'] ?? ''))) ? 10 : 0, 10);

    // --- Price (12): sell needs price > 0; rent needs any period price
    if (($l['listing_type'] ?? 'sell') === 'rent') {
        $priced = max((float)($l['price_per_month'] ?? 0), (float)($l['price_per_week'] ?? 0), (float)($l['price_per_day'] ?? 0));
        $add('quality.check.price', $priced > 0 ? 12 : 0, 12);
    } else {
        $add('quality.check.price', (float)($l['price'] ?? 0) > 0 ? 12 : 0, 12);
    }

    // --- Images (20)
    $imgs = (int) ($l['images_count'] ?? 0);
    $add('quality.check.images', $imgs >= 3 ? 20 : ($imgs === 2 ? 14 : ($imgs === 1 ? 8 : 0)), 20);

    // --- Contact (8): seller phone (email always exists via auth)
    $add('quality.check.contact', !empty(trim((string) ($l['seller_phone'] ?? ''))) ? 8 : 2, 8);

    // --- Specificity (12): listing_attributes richness
    $attrs = (int) ($l['attrs_count'] ?? 0);
    $add('quality.check.attributes', $attrs >= 4 ? 12 : ($attrs >= 2 ? 8 : ($attrs === 1 ? 4 : 0)), 12);

    $score = 0;
    $max   = 0;
    foreach ($checks as $c) { $score += $c['points']; $max += $c['max']; }
    $score  = (int) max(0, min(100, (int) round($score * 100 / max(1, $max))));

    $verdict = $score >= 85 ? 'excellent' : ($score >= 70 ? 'good' : ($score >= 50 ? 'fair' : 'poor'));

    return ['score' => $score, 'verdict' => $verdict, 'checks' => $checks];
}

/** Verdict label (translated) for a quality verdict key. */
function quality_verdict_label(string $verdict): string
{
    return t('quality.verdict.' . $verdict);
}

/** Compact colored chip HTML for a quality score (admin tables, seller lists). */
function quality_chip_html(int $score, string $verdict): string
{
    $tone = $verdict === 'excellent' ? 'ok' : ($verdict === 'good' ? 'good' : ($verdict === 'fair' ? 'warn' : 'bad'));
    return '<span class="q-chip q-chip--' . $tone . '" title="' . e(quality_verdict_label($verdict)) . ' ' . $score . '/100">'
         . '<i></i><b>' . $score . '</b><span>/100</span></span>';
}

/* =====================================================================
 * 3. MARKETPLACE HEALTH (0–100)
 * =====================================================================
 * Weighted components (total 100), every number a live DB aggregate:
 *   Active listings share   20   active / (all non-draft)
 *   Image coverage          15   listings with >= 1 photo
 *   Listing completeness    20   average Listing Quality Score
 *   Active users            10   users with status = active
 *   Reviews satisfaction    10   avg rating / 5
 *   Responsiveness          15   contact requests read + rentals decided
 *   Report pressure         10   10 - 2 per open report (min 0)
 */
function marketplace_health(): array
{
    $pdo  = db();
    $data = [];

    $data['total_listings']  = (int) $pdo->query("SELECT COUNT(*) FROM listings WHERE status <> 'draft'")->fetchColumn();
    $data['active_listings'] = (int) $pdo->query("SELECT COUNT(*) FROM listings WHERE status = 'active'")->fetchColumn();
    $data['no_image']        = (int) $pdo->query("SELECT COUNT(*) FROM listings l WHERE NOT EXISTS (SELECT 1 FROM listing_images li WHERE li.listing_id = l.id)")->fetchColumn();
    $data['total_users']     = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $data['active_users']    = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
    $data['avg_rating']      = (float) $pdo->query("SELECT COALESCE(AVG(rating),0) FROM reviews")->fetchColumn();
    $data['review_count']    = (int) $pdo->query("SELECT COUNT(*) FROM reviews")->fetchColumn();
    $data['open_reports']    = (int) $pdo->query("SELECT COUNT(*) FROM reports WHERE status IN ('open','reviewing')")->fetchColumn();
    $data['total_messages']  = (int) $pdo->query("SELECT COUNT(*) FROM contact_requests")->fetchColumn();
    $data['read_messages']   = (int) $pdo->query("SELECT COUNT(*) FROM contact_requests WHERE is_read = 1")->fetchColumn();
    $data['total_rentals']   = (int) $pdo->query("SELECT COUNT(*) FROM rental_requests")->fetchColumn();
    $data['pending_rentals'] = (int) $pdo->query("SELECT COUNT(*) FROM rental_requests WHERE status = 'pending'")->fetchColumn();
    $data['pending_listings']= (int) $pdo->query("SELECT COUNT(*) FROM listings WHERE status = 'pending'")->fetchColumn();
    $data['low_quality']     = (int) $pdo->query("SELECT COUNT(*) FROM listings l
        WHERE l.status = 'active'
          AND (l.description IS NULL OR CHAR_LENGTH(l.description) < 60
               OR l.location IS NULL OR l.location = ''
               OR NOT EXISTS (SELECT 1 FROM listing_images li WHERE li.listing_id = l.id))")->fetchColumn();

    // Average listing quality (same weights as listing_quality_score, aggregate form)
    $avgQuality = 0.0;
    if ($data['total_listings'] > 0) {
        $rows = $pdo->query("SELECT l.title, l.description, l.listing_type, l.price, l.location,
                l.subcategory_id, l.price_per_month, l.price_per_week, l.price_per_day,
                (SELECT COUNT(*) FROM listing_images li WHERE li.listing_id = l.id) AS images_count,
                (SELECT COUNT(*) FROM listing_attributes la WHERE la.listing_id = l.id) AS attrs_count,
                u.phone AS seller_phone
            FROM listings l INNER JOIN users u ON u.id = l.seller_id")->fetchAll();
        $sum = 0;
        foreach ($rows as $r) $sum += listing_quality_score($r)['score'];
        $avgQuality = $sum / count($rows);
    }
    $data['avg_quality'] = (int) round($avgQuality);

    // Weighted score
    $score  = 0.0;
    $score += $data['total_listings'] ? 20 * $data['active_listings'] / $data['total_listings'] : 20;
    $score += $data['total_listings'] ? 15 * ($data['total_listings'] - $data['no_image']) / $data['total_listings'] : 15;
    $score += 20 * $data['avg_quality'] / 100;
    $score += $data['total_users'] ? 10 * $data['active_users'] / $data['total_users'] : 10;
    $score += $data['review_count'] ? 10 * $data['avg_rating'] / 5 : 7;   // no reviews yet: neutral
    $readRate = $data['total_messages'] ? $data['read_messages'] / $data['total_messages'] : 1;
    $rentalDecided = $data['total_rentals'] ? ($data['total_rentals'] - $data['pending_rentals']) / $data['total_rentals'] : 1;
    $score += 15 * ($readRate * 0.6 + $rentalDecided * 0.4);
    $score += max(0, 10 - 2 * $data['open_reports']);
    $score  = (int) max(0, min(100, (int) round($score)));

    $verdict = $score >= 85 ? 'excellent' : ($score >= 70 ? 'good' : ($score >= 50 ? 'fair' : 'poor'));

    // Clickable recommendations — only for real, current issues
    $recs = [];
    $admin = APP_URL . '/pages/admin/';
    if ($data['pending_listings'] > 0) {
        $recs[] = ['sev' => 'warn', 'url' => $admin . 'listings.php?status=pending',
                   'text' => t('health.rec.pending', ['count' => $data['pending_listings']])];
    }
    if ($data['no_image'] > 0) {
        $recs[] = ['sev' => 'warn', 'url' => $admin . 'listings.php?flag=no_image',
                   'text' => t('health.rec.no_images', ['count' => $data['no_image']])];
    }
    if ($data['low_quality'] > 0) {
        $recs[] = ['sev' => 'warn', 'url' => $admin . 'listings.php?flag=low_quality',
                   'text' => t('health.rec.incomplete', ['count' => $data['low_quality']])];
    }
    if ($data['open_reports'] > 0) {
        $recs[] = ['sev' => 'warn', 'url' => $admin . 'reports.php',
                   'text' => t('health.rec.reports', ['count' => $data['open_reports']])];
    }
    if ($data['pending_rentals'] > 0) {
        $recs[] = ['sev' => 'info', 'url' => $admin . 'rentals.php',
                   'text' => t('health.rec.rentals', ['count' => $data['pending_rentals']])];
    }
    if ($data['total_messages'] && $data['read_messages'] < $data['total_messages']) {
        $recs[] = ['sev' => 'info', 'url' => $admin . 'messages.php',
                   'text' => t('health.rec.messages', ['count' => $data['total_messages'] - $data['read_messages']])];
    }
    if ($data['avg_quality'] < 70 && $data['total_listings'] > 0) {
        $recs[] = ['sev' => 'info', 'url' => $admin . 'analytics.php',
                   'text' => t('health.rec.quality', ['score' => $data['avg_quality']])];
    }
    if (!$recs) {
        $recs[] = ['sev' => 'ok', 'url' => $admin . 'analytics.php', 'text' => t('health.rec.all_clear')];
    }

    return ['score' => $score, 'verdict' => $verdict, 'data' => $data, 'recs' => $recs];
}

/* =====================================================================
 * 4. SMART SEARCH PARSER
 * =====================================================================
 * Turns natural phrases into real filters WITHOUT any external AI:
 *
 *   "houses in Kigali"        -> category homes-land + location Kigali
 *   "3 bedroom house"         -> category homes-land + bedrooms = 3
 *   "land in Musanze"         -> category homes-land + location Musanze
 *   "cheap rental"            -> type rent + sort price_asc
 *   "shops near Kigali"       -> location Kigali (+ free text "shops")
 *
 * Rules:
 *   - Only maps to categories/locations that REALLY exist in the DB.
 *   - Never breaks the existing plain search: unmatched words fall back
 *     to normal keyword matching.
 */
function smart_category_keywords(): array
{
    return [
        'homes-land'          => ['house', 'houses', 'home', 'homes', 'apartment', 'apartments', 'flat', 'flats',
                                  'land', 'plot', 'plots', 'property', 'properties', 'real estate', 'real-estate',
                                  'villa', 'villa', 'bedroom', 'bedrooms', 'office', 'offices', 'hostel'],
        'vehicles'            => ['car', 'cars', 'vehicle', 'vehicles', 'motorcycle', 'motorcycles', 'motorbike',
                                  'motorbikes', 'toyota', 'honda', 'hyundai', 'nissan', 'suzuki', 'bus', 'buses',
                                  'scooter', 'scooters', 'truck', 'trucks', 'pickup'],
        'phones-electronics'  => ['phone', 'phones', 'iphone', 'samsung', 'smartphone', 'smartphones', 'ipad',
                                  'tablet', 'tablets', 'tv', 'television', 'camera', 'cameras', 'electronics',
                                  'headphones', 'speaker'],
        'computers-machines'  => ['laptop', 'laptops', 'computer', 'computers', 'macbook', 'elitebook', 'notebook',
                                  'pc', 'printer', 'printers', 'generator', 'generators', 'machine', 'machines'],
        'furniture'           => ['sofa', 'sofas', 'couch', 'couches', 'table', 'tables', 'chair', 'chairs', 'bed',
                                  'beds', 'furniture', 'desk', 'desks', 'mattress', 'wardrobe', 'decor'],
        'agriculture'         => ['farm', 'farming', 'irrigation', 'pump', 'pumps', 'tractor', 'tractors', 'seeds',
                                  'livestock', 'cattle', 'goat', 'goats', 'produce', 'agriculture', 'harvest'],
        'event-equipment'     => ['tent', 'tents', 'event', 'events', 'sound system', 'soundsystem', 'wedding',
                                  'party', 'decoration', 'ceremony', 'conference'],
        'services'            => ['service', 'services', 'transport', 'transportation', 'plumbing', 'plumber',
                                  'cleaning', 'catering', 'electrical', 'electrician', 'repair', 'delivery',
                                  'pickup service', 'haircut', 'salon'],
        'construction'        => ['cement', 'bricks', 'brick', 'steel', 'construction', 'paint', 'paints', 'timber',
                                  'wood', 'sand', 'stones', 'roofing'],
        'fashion'             => ['clothes', 'clothing', 'shoes', 'shoe', 'bag', 'bags', 'fashion', 'dress', 'dresses',
                                  'shirt', 'shirts', 'watch', 'watches', 'jewelry'],
    ];
}

/** Real locations known to the marketplace (DISTINCT listing locations + district parts). */
function smart_known_locations(): array
{
    static $locs = null;
    if ($locs !== null) return $locs;
    $locs = [];
    try {
        foreach (db()->query("SELECT DISTINCT location FROM listings WHERE location IS NOT NULL AND location <> ''") as $r) {
            foreach (array_map('trim', explode(',', (string) $r['location'])) as $part) {
                if ($part !== '') $locs[mb_strtolower($part)] = $part;
            }
        }
    } catch (Exception $e) { /* degrade: empty map */ }
    return $locs;
}

/**
 * Parse a raw user query into structured filters.
 * Returns: clean string query, filters array, human "understood" chips.
 */
function smart_search_parse(string $raw): array
{
    $raw       = trim(mb_substr($raw, 0, 120));
    $lower     = mb_strtolower($raw);
    $filters   = ['type' => '', 'category' => '', 'location' => '', 'bedrooms' => 0, 'price_intent' => ''];
    $chips     = [];
    $consumed  = [];   // matched phrases removed from free text

    // --- Listing type intent (consume every matched intent word so the
    //     free-text remainder stays clean)
    $typeWords = ['rent', 'rental', 'rentals', 'rented', 'to rent', 'for rent', 'kodesha', 'ukodesha', 'gukodesha'];
    foreach ($typeWords as $tw) {
        if (preg_match('/\b' . preg_quote($tw, '/') . '\b/', $lower)) {
            $filters['type'] = 'rent';
            $consumed[] = $tw;
        }
    }
    if ($filters['type'] !== 'rent') {
        $sellWords = ['buy', 'sale', 'sell', 'for sale', 'to buy', 'gurisha', 'gusoma'];
        foreach ($sellWords as $sw) {
            if (preg_match('/\b' . preg_quote($sw, '/') . '\b/', $lower)) {
                $filters['type'] = 'sell';
                $consumed[] = $sw;
            }
        }
    }

    // --- Category keywords (longest phrase first)
    $catMap = smart_category_keywords();
    $phrases = [];
    foreach ($catMap as $slug => $words) {
        foreach ($words as $w) $phrases[] = [$w, $slug];
    }
    usort($phrases, static fn ($a, $b) => mb_strlen($b[0]) <=> mb_strlen($a[0]));
    foreach ($phrases as [$phrase, $slug]) {
        if ($filters['category'] !== '') break;
        if ($phrase === 'pickup' && $filters['type'] === '') { /* "pickup service" → services */ }
        if (str_contains($lower, $phrase)) {
            // Verify the category really exists before filtering on it
            $exists = db()->prepare('SELECT id FROM categories WHERE slug = ? AND is_active = 1');
            $exists->execute([$slug]);
            if ($exists->fetch()) {
                $filters['category'] = $slug;
                $consumed[] = $phrase;
            }
        }
    }

    // --- Location (only real locations from the DB)
    $locs = smart_known_locations();
    uksort($locs, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    foreach ($locs as $low => $orig) {
        if ($filters['location'] !== '') break;
        if (preg_match('/\b' . preg_quote($low, '/') . '\b/', $lower)) {
            $filters['location'] = $orig;
            $consumed[] = $low;
        }
    }

    // --- Bedrooms ("3 bedroom", "three-bedroom", "bedroom 4")
    $wordNums = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10];
    if (preg_match('/(\d+)\s*-?\s*bed(?:room)?s?\b/', $lower, $m)) {
        $filters['bedrooms'] = max(1, min(20, (int) $m[1]));
    } else {
        foreach ($wordNums as $w => $n) {
            if (preg_match('/\b' . $w . '\s*-?\s*bed(?:room)?s?\b/', $lower)) { $filters['bedrooms'] = $n; break; }
        }
    }
    if ($filters['bedrooms'] > 0) {
        $consumed[] = 'bedroom';
        if ($filters['category'] === '' ) {
            $exists = db()->prepare('SELECT id FROM categories WHERE slug = ? AND is_active = 1');
            $exists->execute(['homes-land']);
            if ($exists->fetch()) $filters['category'] = 'homes-land';
        }
    }

    // --- Price intent (consume the matched qualifier word)
    $cheapWords = ['cheap', 'cheaper', 'cheapest', 'affordable', 'budget', 'low price', 'inexpensive', 'bishyi'];
    $luxuryWords = ['luxury', 'luxurious', 'expensive', 'premium', 'high end', 'high-end'];
    foreach ($cheapWords as $cw) {
        if (preg_match('/\b' . preg_quote($cw, '/') . '\b/', $lower)) {
            $filters['price_intent'] = 'asc';
            $consumed[] = $cw;
        }
    }
    if ($filters['price_intent'] !== 'asc') {
        foreach ($luxuryWords as $lw) {
            if (preg_match('/\b' . preg_quote($lw, '/') . '\b/', $lower)) {
                $filters['price_intent'] = 'desc';
                $consumed[] = $lw;
            }
        }
    }

    // --- Free-text remainder (what plain search should still match)
    $remainder = ' ' . $lower . ' ';
    foreach ($consumed as $c) {
        $remainder = preg_replace('/\b' . preg_quote($c, '/') . '\b/', ' ', $remainder);
    }
    $remainder = preg_replace('/\b(in|near|at|around|for|to|with|and|the|a|an|of|my|me|find|show|looking|want|need)\b/', ' ', $remainder);
    $tokens = array_values(array_unique(array_filter(array_map('trim', explode(' ', trim(preg_replace('/\s+/', ' ', $remainder)))))));
    $tokens = array_slice($tokens, 0, 6);

    // --- Human-readable chips describing what we understood
    if ($filters['category']) {
        $c = db()->prepare('SELECT name FROM categories WHERE slug = ?');
        $c->execute([$filters['category']]);
        $name = $c->fetchColumn();
        if ($name) $chips[] = ['type' => 'category', 'label' => $name, 'value' => $filters['category']];
    }
    if ($filters['location']) $chips[] = ['type' => 'location', 'label' => $filters['location'], 'value' => $filters['location']];
    if ($filters['type'])     $chips[] = ['type' => 'type', 'label' => $filters['type'] === 'rent' ? t('listings.for_rent') : t('listings.for_sale'), 'value' => $filters['type']];
    if ($filters['bedrooms']) $chips[] = ['type' => 'bedrooms', 'label' => $filters['bedrooms'] . '+ ' . t('search.bedrooms'), 'value' => (string) $filters['bedrooms']];
    if ($filters['price_intent']) $chips[] = ['type' => 'price', 'label' => $filters['price_intent'] === 'asc' ? t('search.cheapest_first') : t('search.highest_first'), 'value' => $filters['price_intent']];

    return [
        'clean'   => implode(' ', $tokens),
        'filters' => $filters,
        'chips'   => $chips,
        'tokens'  => $tokens,
    ];
}

/* =====================================================================
 * 5. REAL SEARCH-TERM ANALYTICS
 * =====================================================================
 * Aggregates live searches into the EXISTING market_insights table
 * (metric_key = 'top_searched_term', period = 'live').  Demo rows keep
 * period = 'demo' so they stay clearly separated.  Idempotent + cheap.
 */
function log_searched_term(string $q): void
{
    $q = trim(mb_substr($q, 0, 80));
    if ($q === '' ) return;
    try {
        $pdo  = db();
        $find = $pdo->prepare("SELECT id, metric_count FROM market_insights
                               WHERE metric_key = 'top_searched_term' AND metric_value = ? AND period = 'live'");
        $find->execute([$q]);
        $row = $find->fetch();
        if ($row) {
            $pdo->prepare('UPDATE market_insights SET metric_count = metric_count + 1 WHERE id = ?')->execute([$row['id']]);
        } else {
            $pdo->prepare("INSERT INTO market_insights (metric_key, metric_value, metric_count, period)
                           VALUES ('top_searched_term', ?, 1, 'live')")->execute([$q]);
        }
    } catch (Exception $e) {
        // analytics must never break search
    }
}

/** Top REAL searches (period = 'live'), most searched first. */
function top_searched_terms(int $limit = 8): array
{
    try {
        $stmt = db()->prepare("SELECT metric_value, metric_count FROM market_insights
                               WHERE metric_key = 'top_searched_term' AND period = 'live'
                               ORDER BY metric_count DESC LIMIT " . max(1, min(20, $limit)));
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/* =====================================================================
 * 6. RECOMMENDATION QUERIES (public marketplace)
 * =====================================================================
 * All based on REAL behaviour already tracked by the platform:
 *   - listing views (views_count), favorites (favorites_count)
 *   - recently_viewed table (logged-in) / isoko_recent cookie (guests)
 *   - location text on listings
 * No invasive tracking, no new cookies, no external profiling.
 */

/** Listings near a given listing (same city/district part, exclude self). */
function nearby_listings(array $listing, int $limit = 4): array
{
    $loc = trim((string) ($listing['location'] ?? ''));
    if ($loc === '') return [];
    $city = explode(',', $loc)[0];
    $city = trim($city);
    if ($city === '') return [];
    $sql = 'SELECT l.id, l.title, l.slug, l.price, l.currency, l.listing_type, l.location,
                   l.price_per_month, l.price_per_week, l.price_per_day, l.created_at,
                   (SELECT image_path FROM listing_images li WHERE li.listing_id = l.id AND li.is_primary = 1 LIMIT 1) AS image
            FROM listings l
            WHERE l.id <> ? AND l.status = "active" AND l.availability = "available"
              AND (l.location LIKE ? OR l.district = ? OR l.area LIKE ?)
            ORDER BY l.views_count DESC
            LIMIT ' . max(1, min(8, $limit));
    $stmt = db()->prepare($sql);
    $stmt->execute([(int) $listing['id'], $city . '%', $city, $city . '%']);
    return $stmt->fetchAll();
}

/** Recently viewed listings for the current visitor (DB or cookie). */
function recently_viewed_listings(int $limit = 4, int $excludeId = 0): array
{
    $ids = [];
    $user = current_user();
    if ($user) {
        $stmt = db()->prepare('SELECT listing_id FROM recently_viewed WHERE user_id = ? ORDER BY viewed_at DESC LIMIT 12');
        $stmt->execute([(int) $user['id']]);
        foreach ($stmt->fetchAll() as $r) $ids[] = (int) $r['listing_id'];
    } elseif (!empty($_COOKIE['isoko_recent'])) {
        $ids = array_slice(array_filter(array_map('intval', explode(',', (string) $_COOKIE['isoko_recent']))), 0, 12);
    }
    $ids = array_values(array_diff($ids, [$excludeId]));
    if (!$ids) return [];
    $ids = array_slice($ids, 0, max(1, min(8, $limit)));

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = 'SELECT l.id, l.title, l.slug, l.price, l.currency, l.listing_type, l.location,
                   l.price_per_month, l.price_per_week, l.price_per_day, l.created_at,
                   (SELECT image_path FROM listing_images li WHERE li.listing_id = l.id AND li.is_primary = 1 LIMIT 1) AS image
            FROM listings l
            WHERE l.id IN (' . $placeholders . ') AND l.status = "active"';
    $order = [];
    foreach ($ids as $i => $id) $order[] = 'WHEN ' . (int) $id . ' THEN ' . $i;
    $sql .= ' ORDER BY CASE l.id ' . implode(' ', $order) . ' END';
    $stmt = db()->prepare($sql);
    $stmt->execute($ids);
    return $stmt->fetchAll();
}

/** Frequently favorited listings (real favorites_count), optional category scope. */
function popular_favorited_listings(int $limit = 4, int $categoryId = 0): array
{
    $sql = 'SELECT l.id, l.title, l.slug, l.price, l.currency, l.listing_type, l.location,
                   l.price_per_month, l.price_per_week, l.price_per_day, l.created_at,
                   l.favorites_count,
                   (SELECT image_path FROM listing_images li WHERE li.listing_id = l.id AND li.is_primary = 1 LIMIT 1) AS image
            FROM listings l
            WHERE l.status = "active" AND l.availability = "available" AND l.favorites_count > 0';
    $params = [];
    if ($categoryId > 0) { $sql .= ' AND l.category_id = ?'; $params[] = $categoryId; }
    $sql .= ' ORDER BY l.favorites_count DESC, l.views_count DESC LIMIT ' . max(1, min(8, $limit));
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Recommended categories for THIS visitor: categories of their recent views/favorites. */
function recommended_categories(int $limit = 4): array
{
    $user = current_user();
    $catIds = [];

    if ($user) {
        $stmt = db()->prepare('SELECT l.category_id, COUNT(*) n
            FROM recently_viewed rv JOIN listings l ON l.id = rv.listing_id
            WHERE rv.user_id = ? GROUP BY l.category_id ORDER BY n DESC LIMIT 3');
        $stmt->execute([(int) $user['id']]);
        foreach ($stmt->fetchAll() as $r) $catIds[] = (int) $r['category_id'];

        $stmt = db()->prepare('SELECT l.category_id, COUNT(*) n
            FROM favorites f JOIN listings l ON l.id = f.listing_id
            WHERE f.user_id = ? GROUP BY l.category_id ORDER BY n DESC LIMIT 3');
        $stmt->execute([(int) $user['id']]);
        foreach ($stmt->fetchAll() as $r) $catIds[] = (int) $r['category_id'];
    } elseif (!empty($_COOKIE['isoko_recent'])) {
        $ids = array_slice(array_filter(array_map('intval', explode(',', (string) $_COOKIE['isoko_recent']))), 0, 10);
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare("SELECT category_id FROM listings WHERE id IN ($in)");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $r) $catIds[] = (int) $r['category_id'];
        }
    }

    $catIds = array_values(array_unique($catIds));
    if (!$catIds) return [];

    $in    = implode(',', array_fill(0, count($catIds), '?'));
    $order = [];
    foreach ($catIds as $i => $id) $order[] = 'WHEN ' . (int) $id . ' THEN ' . $i;
    $stmt = db()->prepare("SELECT c.id, c.name, c.slug, c.name_key,
            (SELECT COUNT(*) FROM listings l WHERE l.category_id = c.id AND l.status = 'active') AS listing_count
        FROM categories c
        WHERE c.id IN ($in) AND c.is_active = 1
        ORDER BY CASE c.id " . implode(' ', $order) . " END
        LIMIT " . max(1, min(6, $limit)));
    $stmt->execute($catIds);
    return $stmt->fetchAll();
}

/** Real location activity for the location explorer + admin analytics. */
function location_activity(int $limit = 12): array
{
    $rows = db()->query(
        'SELECT TRIM(SUBSTRING_INDEX(l.location, ",", 1)) AS city,
                COUNT(*) AS listing_count,
                COALESCE(SUM(l.views_count), 0)   AS views,
                COALESCE(SUM(l.favorites_count),0) AS favorites,
                COALESCE(AVG(CASE WHEN l.listing_type = "sell" AND l.price > 0 THEN l.price END), 0) AS avg_price
         FROM listings l
         WHERE l.location IS NOT NULL AND l.location <> "" AND l.status <> "draft"
         GROUP BY city
         HAVING city <> ""
         ORDER BY listing_count DESC, views DESC
         LIMIT ' . max(1, min(30, $limit)))->fetchAll();
    return $rows;
}

/* =====================================================================
 * 7. REAL MARKETPLACE INSIGHTS
 * =====================================================================
 * Everything the public Insights page + admin analytics need, computed
 * from live tables.  Empty/zero results are returned as-is — the UI shows
 * honest empty states instead of fabricated numbers.
 */
function marketplace_insights_real(): array
{
    $pdo = db();

    $ins = [];
    $ins['totals'] = [
        'listings'      => (int) $pdo->query("SELECT COUNT(*) FROM listings WHERE status = 'active'")->fetchColumn(),
        'listings_all'  => (int) $pdo->query('SELECT COUNT(*) FROM listings')->fetchColumn(),
        'sellers'       => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_seller = 1')->fetchColumn(),
        'users'         => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'views'         => (int) $pdo->query('SELECT COALESCE(SUM(views_count),0) FROM listings')->fetchColumn(),
        'favorites'     => (int) $pdo->query('SELECT COALESCE(SUM(favorites_count),0) FROM listings')->fetchColumn(),
        'rentals'       => (int) $pdo->query('SELECT COUNT(*) FROM rental_requests')->fetchColumn(),
        'reviews'       => (int) $pdo->query('SELECT COUNT(*) FROM reviews')->fetchColumn(),
        'avg_rating'    => (float) $pdo->query('SELECT COALESCE(AVG(rating),0) FROM reviews')->fetchColumn(),
    ];

    // Real 7-day deltas
    $ins['new_week'] = [
        'users'    => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)')->fetchColumn(),
        'listings' => (int) $pdo->query('SELECT COUNT(*) FROM listings WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)')->fetchColumn(),
        'rentals'  => (int) $pdo->query('SELECT COUNT(*) FROM rental_requests WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)')->fetchColumn(),
        'reviews'  => (int) $pdo->query('SELECT COUNT(*) FROM reviews WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)')->fetchColumn(),
    ];

    // Most viewed listings (top 5)
    $ins['most_viewed'] = $pdo->query(
        'SELECT l.id, l.title, l.views_count, l.favorites_count, l.location, c.name AS category_name, c.name_key AS category_name_key
         FROM listings l INNER JOIN categories c ON c.id = l.category_id
         WHERE l.status = "active"
         ORDER BY l.views_count DESC LIMIT 5')->fetchAll();

    // Most favorited listings (top 5)
    $ins['most_favorited'] = $pdo->query(
        'SELECT l.id, l.title, l.views_count, l.favorites_count, l.location, c.name AS category_name, c.name_key AS category_name_key
         FROM listings l INNER JOIN categories c ON c.id = l.category_id
         WHERE l.status = "active" AND l.favorites_count > 0
         ORDER BY l.favorites_count DESC LIMIT 5')->fetchAll();

    // Most active categories (by real listing count + views)
    $ins['categories'] = $pdo->query(
        'SELECT c.id, c.name, c.slug, c.name_key,
                COUNT(l.id) AS listing_count,
                COALESCE(SUM(l.views_count), 0) AS views,
                COALESCE(SUM(l.favorites_count), 0) AS favorites
         FROM categories c
         LEFT JOIN listings l ON l.category_id = c.id AND l.status = "active"
         WHERE c.parent_id IS NULL AND c.is_active = 1
         GROUP BY c.id, c.name, c.slug, c.name_key
         HAVING listing_count > 0
         ORDER BY views DESC, listing_count DESC
         LIMIT 10')->fetchAll();

    // Rental request trends (status breakdown — real)
    $ins['rental_status'] = $pdo->query(
        "SELECT status, COUNT(*) n FROM rental_requests GROUP BY status")->fetchAll();
    $ins['rental_trend_rent'] = $pdo->query(
        "SELECT COUNT(*) n FROM rental_requests WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();

    // Listings with high demand (favorites-per-view ratio, min 300 views)
    $ins['high_demand'] = $pdo->query(
        'SELECT l.id, l.title, l.views_count, l.favorites_count,
                ROUND(100.0 * l.favorites_count / NULLIF(l.views_count,0), 1) AS demand_score
         FROM listings l
         WHERE l.status = "active" AND l.views_count >= 100 AND l.favorites_count > 0
         ORDER BY demand_score DESC LIMIT 5')->fetchAll();

    // Listings with declining activity: active 30+ days, below-average views,
    // zero favorites (real "needs attention" signal)
    $ins['declining'] = $pdo->query(
        'SELECT l.id, l.title, l.views_count, l.created_at,
                DATEDIFF(NOW(), l.created_at) AS age_days
         FROM listings l
         WHERE l.status = "active"
           AND l.created_at <= DATE_SUB(NOW(), INTERVAL 30 DAY)
           AND l.favorites_count = 0
           AND l.views_count < (SELECT COALESCE(AVG(views_count), 0) FROM listings)
         ORDER BY l.views_count ASC
         LIMIT 5')->fetchAll();

    // Top searched terms (REAL, period = live)
    $ins['top_searches'] = top_searched_terms(8);

    // Location activity
    $ins['locations'] = location_activity(10);

    return $ins;
}

/* SMART_FEATURES_DONE */


