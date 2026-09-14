<?php
/**
 * api/v1/admin-pulse/index.php
 * --------------------------------------------------------------------
 * GET /api/v1/admin-pulse?since=<ISO-timestamp>
 *
 * Lightweight real-time activity feed for the admin panel (Phase 10).
 *
 * Design (deliberately minimal — no websockets, no external services):
 *   - Read-only: counts of NEW users / listings / rental requests /
 *     reports / reviews / messages since the caller's last check
 *     (or the last 24 hours on first call).
 *   - Also returns the live badge counters the sidebar + topbar show,
 *     so a page left open stays fresh without manual reloads.
 *   - Called via AJAX polling from assets/js/admin.js every 30 seconds,
 *     only while an admin tab is visible (document.visibilityState).
 *
 * Guards:
 *   - ADMIN / SUPER_ADMIN session required (403 otherwise)
 *   - Read-only queries (no state changes, so CSRF is not applicable);
 *     output is pure JSON, nothing user-controlled is echoed raw.
 * --------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/smart_features.php';

header('Cache-Control: no-store');

function pulse_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// --- Role guard: pulse data is admin-only -------------------------------
$me = current_user();
if (!$me || !in_array(strtoupper($me['role_name'] ?? ''), ['ADMIN', 'SUPER_ADMIN'], true)) {
    pulse_json(['error' => 'forbidden'], 403);
}

// --- Window: everything created since `since` (default: last 24 hours) --
$sinceRaw = (string) ($_GET['since'] ?? '');
$since = date('Y-m-d H:i:s', strtotime('-24 hours'));
if ($sinceRaw !== '' && ($ts = strtotime($sinceRaw)) !== false) {
    // Cap the window at 7 days so a stale tab can't trigger heavy queries
    $since = date('Y-m-d H:i:s', max($ts, time() - 7 * 86400));
}

$pdo = db();
listings_verified_ready();

/** Count rows created after $since in a table (prepared, no injection). */
function pulse_count(PDO $pdo, string $table, string $since): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE created_at >= ?");
    $stmt->execute([$since]);
    return (int) $stmt->fetchColumn();
}

$events = [
    'users'    => pulse_count($pdo, 'users', $since),
    'listings' => pulse_count($pdo, 'listings', $since),
    'rentals'  => pulse_count($pdo, 'rental_requests', $since),
    'reports'  => pulse_count($pdo, 'reports', $since),
    'reviews'  => pulse_count($pdo, 'reviews', $since),
    'messages' => pulse_count($pdo, 'contact_requests', $since),
];

// Live badge counters (same numbers the sidebar renders server-side)
$badges = [
    'pending'   => (int) $pdo->query("SELECT COUNT(*) FROM listings WHERE status = 'pending'")->fetchColumn(),
    'reports'   => (int) $pdo->query("SELECT COUNT(*) FROM reports WHERE status IN ('open','reviewing')")->fetchColumn(),
    'messages'  => (int) $pdo->query("SELECT COUNT(*) FROM contact_requests WHERE is_read = 0")->fetchColumn(),
    'rentals'   => (int) $pdo->query("SELECT COUNT(*) FROM rental_requests WHERE status = 'pending'")->fetchColumn(),
];

// Latest combined activity (newest first, small LIMIT, real rows only)
$recent = $pdo->query(
    "(SELECT id, 'listing' AS kind, title AS label, created_at FROM listings ORDER BY created_at DESC LIMIT 5)
     UNION ALL
     (SELECT id, 'user' AS kind, full_name AS label, created_at FROM users ORDER BY created_at DESC LIMIT 5)
     UNION ALL
     (SELECT rr.id, 'rental' AS kind, CONCAT('Rental request — ', l.title) AS label, rr.created_at
        FROM rental_requests rr LEFT JOIN listings l ON l.id = rr.listing_id
        ORDER BY rr.created_at DESC LIMIT 5)
     UNION ALL
     (SELECT id, 'report' AS kind, reason AS label, created_at FROM reports ORDER BY created_at DESC LIMIT 5)
     UNION ALL
     (SELECT id, 'message' AS kind, CONCAT('Message from ', name) AS label, created_at FROM contact_requests ORDER BY created_at DESC LIMIT 5)
     UNION ALL
     (SELECT id, 'review' AS kind, CONCAT(rating, '-star review') AS label, created_at FROM reviews ORDER BY created_at DESC LIMIT 5)
     ORDER BY created_at DESC LIMIT 8"
)->fetchAll();

pulse_json([
    'ok'      => true,
    'now'     => date('c'),
    'since'   => $since,
    'events'  => $events,
    'badges'  => $badges,
    'recent'  => $recent,
]);
