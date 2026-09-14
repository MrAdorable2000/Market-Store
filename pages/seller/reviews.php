<?php
/**
 * pages/seller/reviews.php — Reviews received on seller's products
 * --------------------------------------------------------------------
 * Shows:
 *   - Average rating + rating distribution
 *   - List of reviews on the seller's listings
 *   - Filter by rating (1-5 stars)
 *   - Real DB queries from the reviews table
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/seller_sidebar.php';
require_login();

$uid = (int) current_user()['id'];
$pdo = db();

// --- Rating filter ---
$ratingFilter = (int)($_GET['rating'] ?? 0);
if ($ratingFilter < 0 || $ratingFilter > 5) $ratingFilter = 0;

// --- Average rating + distribution ---
$ratingStats = $pdo->prepare(
    "SELECT
        AVG(r.rating) AS avg_rating,
        COUNT(*) AS total_reviews,
        SUM(CASE WHEN r.rating = 5 THEN 1 ELSE 0 END) AS five,
        SUM(CASE WHEN r.rating = 4 THEN 1 ELSE 0 END) AS four,
        SUM(CASE WHEN r.rating = 3 THEN 1 ELSE 0 END) AS three,
        SUM(CASE WHEN r.rating = 2 THEN 1 ELSE 0 END) AS two,
        SUM(CASE WHEN r.rating = 1 THEN 1 ELSE 0 END) AS one
       FROM reviews r
       INNER JOIN listings l ON l.id = r.listing_id
      WHERE l.seller_id = ?"
);
$ratingStats->execute([$uid]);
$stats = $ratingStats->fetch();
$avgRating = $stats ? round((float)$stats['avg_rating'], 1) : 0;
$totalReviews = $stats ? (int)$stats['total_reviews'] : 0;

// --- Load reviews ---
$where = 'l.seller_id = ?';
$params = [$uid];
if ($ratingFilter > 0) {
    $where .= ' AND r.rating = ?';
    $params[] = $ratingFilter;
}

$stmt = $pdo->prepare(
    "SELECT r.*, l.title AS listing_title, l.id AS listing_id,
            reviewer.full_name AS reviewer_name
       FROM reviews r
       INNER JOIN listings l ON l.id = r.listing_id
       INNER JOIN users reviewer ON reviewer.id = r.reviewer_id
      WHERE $where
      ORDER BY r.created_at DESC"
);
$stmt->execute($params);
$reviews = $stmt->fetchAll();

$pageTitle = 'Reviews';
$activePage = 'dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>

<?php seller_page_start('Reviews', $uid, $pdo); ?>
    <h1 style="font-size:22px;font-weight:800;margin:0 0 20px;letter-spacing:-.02em;">Reviews</h1>

    <!-- Rating summary -->
    <div class="card" style="padding:20px;margin-bottom:20px;display:flex;gap:24px;align-items:center;flex-wrap:wrap;">
        <div style="text-align:center;flex:0 0 auto;">
            <div style="font-size:42px;font-weight:800;color:var(--text);line-height:1;"><?php echo number_format($avgRating, 1); ?></div>
            <div style="font-size:18px;color:var(--accent-500);margin:4px 0;">
                <?php for ($i = 1; $i <= 5; $i++): echo $i <= round($avgRating) ? '★' : '☆'; endfor; ?>
            </div>
            <div style="font-size:12px;color:var(--text-mute);"><?php echo $totalReviews; ?> review(s)</div>
        </div>
        <div style="flex:1;min-width:200px;">
            <?php
            $dist = [['5', (int)($stats['five'] ?? 0)], ['4', (int)($stats['four'] ?? 0)], ['3', (int)($stats['three'] ?? 0)], ['2', (int)($stats['two'] ?? 0)], ['1', (int)($stats['one'] ?? 0)]];
            foreach ($dist as [$star, $count]):
                $pct = $totalReviews > 0 ? round(($count / $totalReviews) * 100) : 0;
            ?>
                <div style="display:flex;align-items:center;gap:8px;font-size:12px;margin-bottom:4px;">
                    <span style="flex:0 0 30px;color:var(--text-mute);"><?php echo $star; ?>★</span>
                    <div style="flex:1;height:6px;background:var(--bg-soft);border-radius:6px;overflow:hidden;">
                        <div style="height:100%;width:<?php echo $pct; ?>%;background:var(--accent-500);border-radius:6px;"></div>
                    </div>
                    <span style="flex:0 0 30px;text-align:right;color:var(--text-mute);"><?php echo $count; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Filter -->
    <div style="display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap;">
        <a href="?rating=0" class="btn btn--<?php echo $ratingFilter===0?'primary':'secondary'; ?> btn--sm">All</a>
        <?php for ($s = 5; $s >= 1; $s--): ?>
            <a href="?rating=<?php echo $s; ?>" class="btn btn--<?php echo $ratingFilter===$s?'primary':'secondary'; ?> btn--sm"><?php echo $s; ?> ★</a>
        <?php endfor; ?>
    </div>

    <!-- Reviews list -->
    <?php if (empty($reviews)): ?>
        <div class="card" style="padding:48px 24px;text-align:center;">
            <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="var(--text-mute)" stroke-width="1.3" style="opacity:.4;margin-bottom:12px;"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
            <h3 style="margin:0 0 6px;font-size:16px;color:var(--text);">No reviews yet</h3>
            <p style="margin:0;font-size:13.5px;color:var(--text-mute);">When buyers review your products after purchase, reviews will appear here.</p>
        </div>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:12px;">
            <?php foreach ($reviews as $r): ?>
                <div class="card" style="padding:16px;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:8px;">
                        <div>
                            <strong style="font-size:13.5px;color:var(--text);"><?php echo e($r['reviewer_name']); ?></strong>
                            <div style="font-size:11.5px;color:var(--text-mute);margin-top:2px;">on <a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$r['listing_id']; ?>" style="color:var(--brand-600);text-decoration:none;"><?php echo e($r['listing_title']); ?></a></div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-size:14px;color:var(--accent-500);">
                                <?php for ($i = 1; $i <= 5; $i++): echo $i <= (int)$r['rating'] ? '★' : '☆'; endfor; ?>
                            </div>
                            <small style="font-size:11px;color:var(--text-mute);"><?php echo e(time_ago($r['created_at'])); ?></small>
                        </div>
                    </div>
                    <?php if ($r['comment']): ?>
                        <p style="margin:0;font-size:13px;color:var(--text-soft);line-height:1.5;"><?php echo e($r['comment']); ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php seller_page_end(); ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
