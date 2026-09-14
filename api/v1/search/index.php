<?php
/**
 * api/v1/search/index.php — Smart Search Autocomplete API
 * --------------------------------------------------------------------
 * GET ?q=<query>&limit=<n>
 *
 * Returns JSON with:
 *   products:  [{id, title, slug, price, currency, image, category_name, category_slug, listing_type, location}]
 *   categories:[{id, name, slug, icon}]
 *   sellers:   [{id, full_name, avatar_path, listing_count}]
 *   suggestion: "did you mean?" string (or null)
 *
 * Uses the existing listings + categories + users tables.
 * Server-side prepared statements — no SQL injection.
 * --------------------------------------------------------------------
 */
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$q = trim((string)($_GET['q'] ?? ''));
$limit = min(10, max(3, (int)($_GET['limit'] ?? 5)));

if (mb_strlen($q) < 2) {
    echo json_encode(['products' => [], 'categories' => [], 'sellers' => [], 'suggestion' => null, 'suggestion_type' => null]);
    exit;
}

$pdo = db();
$response = ['products' => [], 'categories' => [], 'sellers' => [], 'suggestion' => null, 'suggestion_type' => null];

try {
    $like = '%' . $q . '%';
    $prefixLike = $q . '%';

    // --- Products ---
    $stmt = $pdo->prepare(
        "SELECT l.id, l.title, l.slug, l.price, l.currency, l.listing_type, l.location,
                c.name AS category_name, c.slug AS category_slug,
                (SELECT image_path FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS image
           FROM listings l
           INNER JOIN categories c ON c.id = l.category_id
          WHERE l.status = 'active' AND l.availability = 'available'
            AND (l.title LIKE ? OR l.description LIKE ? OR c.name LIKE ?)
          ORDER BY
            CASE WHEN l.title LIKE ? THEN 0 ELSE 1 END,
            l.views_count DESC
          LIMIT ?"
    );
    $stmt->execute([$like, $like, $like, $prefixLike, $limit]);
    $response['products'] = $stmt->fetchAll();

    // Resolve listing photos exactly the same way as the rest of the system.
    // This is important for uploads stored as database BLOBs (db-blob:<id>),
    // which cannot be treated as a normal filesystem URL by browser JS.
    foreach ($response['products'] as &$product) {
        $product['image'] = image_or_default($product['image'] ?? null);
    }
    unset($product);

    // --- Categories ---
    $stmt = $pdo->prepare(
        "SELECT id, name, slug, icon
           FROM categories
          WHERE parent_id IS NULL AND is_active = 1
            AND name LIKE ?
          ORDER BY display_order, name
          LIMIT 3"
    );
    $stmt->execute([$like]);
    $response['categories'] = $stmt->fetchAll();

    // --- Sellers ---
    $stmt = $pdo->prepare(
        "SELECT u.id, u.full_name, u.avatar_path,
                (SELECT COUNT(*) FROM listings WHERE seller_id = u.id AND status='active') AS listing_count
           FROM users u
          WHERE (u.is_seller = 1 OR u.role_id IN (SELECT id FROM roles WHERE name IN ('SELLER','ADMIN','SUPER_ADMIN')))
            AND u.status = 'active'
            AND u.full_name LIKE ?
          ORDER BY listing_count DESC
          LIMIT 3"
    );
    $stmt->execute([$like]);
    $response['sellers'] = $stmt->fetchAll();

    // --- Smart "Did you mean?" ----------------------------------------
    // Only suggest when the current query is not already a useful match.
    // Compare the user's words against real product/category vocabulary,
    // rather than comparing the whole typo to an entire long title.
    if (empty($response['products']) && empty($response['categories'])) {
        $normalize = static function ($value) {
            $value = mb_strtolower(trim((string)$value), 'UTF-8');
            $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
            return trim(preg_replace('/\s+/u', ' ', $value));
        };

        $stmt = $pdo->prepare(
            "SELECT DISTINCT title
               FROM listings
              WHERE status = 'active'
                AND title IS NOT NULL
                AND TRIM(title) <> ''
              ORDER BY views_count DESC
              LIMIT 120"
        );
        $stmt->execute();
        $titles = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $stmt = $pdo->prepare(
            "SELECT DISTINCT name
               FROM categories
              WHERE is_active = 1
                AND name IS NOT NULL
                AND TRIM(name) <> ''
              ORDER BY display_order, name
              LIMIT 80"
        );
        $stmt->execute();
        $categoryNames = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $queryNorm = $normalize($q);
        $queryWords = array_values(array_filter(explode(' ', $queryNorm), static function ($w) {
            return mb_strlen($w, 'UTF-8') >= 2;
        }));

        $candidates = [];
        foreach ($categoryNames as $name) {
            $candidates[] = ['text' => $name, 'kind' => 'category'];
        }
        foreach ($titles as $title) {
            $candidates[] = ['text' => $title, 'kind' => 'product'];
        }

        $best = null;
        $bestScore = 9999;

        foreach ($candidates as $candidate) {
            $text = (string)$candidate['text'];
            $norm = $normalize($text);
            if ($norm === '' || mb_strlen($norm, 'UTF-8') < 3) continue;

            $words = array_values(array_filter(explode(' ', $norm), static function ($w) {
                return mb_strlen($w, 'UTF-8') >= 2;
            }));
            if (!$words) continue;

            // Best word-to-word typo distance catches cases such as:
            // "bedr" -> "bedroom", "iphne" -> "iphone", "toyta" -> "toyota".
            $wordScore = 9999;
            foreach ($queryWords as $qw) {
                foreach ($words as $cw) {
                    $d = levenshtein($qw, $cw);
                    $maxLen = max(strlen($qw), strlen($cw));
                    $allowed = $maxLen <= 4 ? 1 : ($maxLen <= 7 ? 2 : 3);
                    if ($d <= $allowed) {
                        $ratio = $maxLen ? ($d / $maxLen) : 1;
                        $score = ($d * 10) + ($ratio * 10);
                        if ($score < $wordScore) $wordScore = $score;
                    }
                }
            }

            // Full-query similarity is a secondary signal for multi-word searches.
            $distance = levenshtein($queryNorm, $norm);
            $maxLen = max(strlen($queryNorm), strlen($norm));
            $fullRatio = $maxLen ? ($distance / $maxLen) : 1;

            $score = $wordScore < 9999
                ? $wordScore + ($fullRatio * 4)
                : 9999;

            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        if ($best !== null && $bestScore <= 34) {
            $response['suggestion'] = $best['text'];
            $response['suggestion_type'] = $best['kind'];
        }
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Search temporarily unavailable']);
    exit;
}

echo json_encode($response);
