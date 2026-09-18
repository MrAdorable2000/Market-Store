<?php
/**
 * api/v1/reports/index.php
 * --------------------------------------------------------------------
 * POST /api/v1/reports — submit a report for a listing.
 *
 * Required: listing_id, reason
 * Optional: details, reporter_id (auto-filled if logged in)
 */
require_once __DIR__ . '/../../../includes/auth.php';

function is_ajax_report(): bool {
    return (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest')
        || (strtolower($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => t('errors.method_not_allowed')], 405);
}

if (!csrf_check()) {
    if (is_ajax_report()) json_response(['error' => t('errors.invalid_token')], 403);
    flash_set('error', t('errors.invalid_token'));
    redirect($_SERVER['HTTP_REFERER'] ?? APP_URL . '/');
}

$listingId  = (int)($_POST['listing_id'] ?? 0);
$reason      = trim($_POST['reason'] ?? '');
$details     = trim($_POST['details'] ?? '');
// SECURITY: reporter_id must never come from client input. A logged-out
// visitor previously could POST an arbitrary reporter_id directly to this
// endpoint (bypassing the form, which only ever renders that hidden field
// when is_logged_in() is true) and have a report - visible to admins
// together with that user's real name and email via the reports.reporter_id
// join in pages/admin/reports.php - falsely attributed to any real user
// whose id they guessed. The only legitimate source of truth for "who is
// reporting" is the server-side session; a logged-out report is always
// anonymous (null), never a claimed identity.
$reporterId  = is_logged_in() ? (int) current_user()['id'] : null;

if (!$listingId || !$reason) {
    if (is_ajax_report()) json_response(['error' => t('errors.report_reason_required')], 400);
    flash_set('error', t('errors.report_reason_required'));
    redirect($_SERVER['HTTP_REFERER'] ?? APP_URL . '/');
}

// Verify listing exists
$check = db()->prepare('SELECT id FROM listings WHERE id = ?');
$check->execute([$listingId]);
if (!$check->fetch()) {
    if (is_ajax_report()) json_response(['error' => t('errors.listing_not_found')], 404);
    flash_set('error', t('errors.listing_not_found'));
    redirect($_SERVER['HTTP_REFERER'] ?? APP_URL . '/');
}

// Insert report
db()->prepare(
    'INSERT INTO reports (reporter_id, listing_id, reason, details, status)
     VALUES (?, ?, ?, ?, "open")'
)->execute([$reporterId, $listingId, $reason, $details ?: null]);

// Notify admins
$admins = db()->query("SELECT id FROM users WHERE role_id = 1")->fetchAll();
foreach ($admins as $a) {
    db()->prepare('INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)')
        ->execute([
            $a['id'],
            'system',
            t('buttons.report'),
            $reason . ($details ? ': ' . mb_substr($details, 0, 80) : ''),
            'pages/admin/reports.php',
        ]);
}

if (is_ajax_report()) {
    json_response(['success' => true, 'message' => t('flash.report_sent')]);
} else {
    flash_set('success', t('flash.report_sent'));
    redirect(APP_URL . '/pages/listing-details.php?id=' . $listingId);
}
