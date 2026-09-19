<?php
/**
 * api/v1/contact/index.php
 * --------------------------------------------------------------------
 * POST /api/v1/contact — send a message to a listing's seller.
 */
require_once __DIR__ . '/../../../includes/auth.php';

// Self-contained json_response() - see the identical fix and full
// reasoning in api/v1/favorites/index.php and api/v1/rentals/index.php
// from this same audit. Latent here (the plain-form-POST caller,
// pages/listing-details.php, never reaches json_response() since
// is_ajax_contact() is false for it) rather than currently live, but
// fixed for consistency and to prevent silently reintroducing the crash.
if (!function_exists('json_response')) {
    function json_response($data, int $code = 200): void {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function is_ajax_contact(): bool {
    return (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest')
        || (strtolower($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => t('errors.method_not_allowed')], 405);
}

if (!csrf_check()) {
    if (is_ajax_contact()) json_response(['error' => t('errors.invalid_token')], 403);
    flash_set('error', t('errors.invalid_token'));
    redirect_back(APP_URL . '/');
}

$listingId = (int)($_POST['listing_id'] ?? 0);
$sellerId  = (int)($_POST['seller_id'] ?? 0);
$name       = trim($_POST['name'] ?? '');
$email      = trim($_POST['email'] ?? '');
$phone      = trim($_POST['phone'] ?? '');
$message    = trim($_POST['message'] ?? '');

if (!$listingId || !$sellerId || !$name || !$email || !$message) {
    if (is_ajax_contact()) json_response(['error' => t('errors.required_fields')], 400);
    flash_set('error', t('errors.required_fields'));
    redirect_back(APP_URL . '/');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    if (is_ajax_contact()) json_response(['error' => t('errors.email_invalid')], 400);
    flash_set('error', t('errors.email_invalid'));
    redirect_back(APP_URL . '/');
}

$senderId = is_logged_in() ? current_user()['id'] : null;
db()->prepare(
    'INSERT INTO contact_requests (listing_id, sender_id, seller_id, name, email, phone, message)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
)->execute([$listingId, $senderId, $sellerId, $name, $email, $phone ?: null, $message]);

db()->prepare(
    'INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)'
)->execute([
    $sellerId,
    'message',
    t('listings.detail.contact_form'),
    $name . ': ' . mb_substr($message, 0, 100),
    'pages/seller/messages.php',
]);

if (is_ajax_contact()) {
    json_response(['success' => true, 'message' => t('flash.contact_sent')]);
} else {
    flash_set('success', t('flash.contact_sent'));
    redirect(APP_URL . '/pages/listing-details.php?id=' . $listingId);
}
