<?php
/**
 * api/v1/listings-action/index.php
 * --------------------------------------------------------------------
 * POST /api/v1/listings-action
 *
 * Used by the seller dashboard and admin dashboard to:
 *   - mark listing as sold / rented / available
 *   - delete a listing
 *   - approve / reject (admin only)
 *
 * Required POST fields:
 *   listing_id, action
 *
 * Actions:
 *   mark_sold      — availability = 'sold'
 *   mark_rented    — availability = 'rented'
 *   relist         — availability = 'available'
 *   delete         — soft delete (set status = 'rejected') — hard delete could
 *                    cascade to favorites etc.
 *   approve        — admin only: status = 'active'
 *   reject         — admin only: status = 'rejected'
 *   resolve_report — admin only: marks a report resolved (needs report_id)
 */
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/admin_log.php';
require_once __DIR__ . '/../../../includes/notification_service.php';

/**
 * Local JSON response helper. The REST router (api/v1/index.php) is NOT
 * loaded on this endpoint, so the function must be defined here — this
 * also fixes a latent fatal error on the AJAX path.
 */
if (!function_exists('json_response')) {
    function json_response($data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function is_ajax_action(): bool {
    return (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest')
        || (strtolower($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => t('errors.method_not_allowed')], 405);
}

if (!csrf_check()) {
    if (is_ajax_action()) json_response(['error' => t('errors.invalid_token')], 403);
    flash_set('error', t('errors.invalid_token'));
    redirect_back(APP_URL . '/');
}

$listingId = (int)($_POST['listing_id'] ?? 0);
$action    = $_POST['action'] ?? '';
if (!$listingId || !$action) {
    if (is_ajax_action()) json_response(['error' => t('errors.required_fields')], 400);
    flash_set('error', t('errors.required_fields'));
    redirect_back(APP_URL . '/');
}

// Verify listing exists
$stmt = db()->prepare('SELECT seller_id, status, title FROM listings WHERE id = ? LIMIT 1');
$stmt->execute([$listingId]);
$l = $stmt->fetch();
if (!$l) {
    if (is_ajax_action()) json_response(['error' => t('errors.listing_not_found')], 404);
    flash_set('error', t('errors.listing_not_found'));
    redirect_back(APP_URL . '/');
}

$ok = true;
$message = '';

switch ($action) {

    case 'unpublish':
        if (!is_logged_in() || current_user()['id'] != $l['seller_id']) {
            $ok = false; $message = t('errors.not_owner');
            break;
        }
        db()->prepare('UPDATE listings SET is_published = 0 WHERE id = ?')->execute([$listingId]);
        $message = 'Product unpublished. It is hidden from the public marketplace.';
        break;

    case 'publish':
        if (!is_logged_in() || current_user()['id'] != $l['seller_id']) {
            $ok = false; $message = t('errors.not_owner');
            break;
        }
        if ($l['status'] !== 'active') { $ok = false; $message = 'Only an approved active product can be published.'; break; }
        db()->prepare('UPDATE listings SET is_published = 1, published_at = COALESCE(published_at, NOW()) WHERE id = ?')->execute([$listingId]);
        $message = 'Product published.';
        break;

    case 'archive':
        if (!is_logged_in() || current_user()['id'] != $l['seller_id']) {
            $ok = false; $message = t('errors.not_owner');
            break;
        }
        db()->prepare('UPDATE listings SET status = "archived", is_published = 0, archived_at = NOW() WHERE id = ?')->execute([$listingId]);
        $message = 'Product archived.';
        break;

    case 'restore':
        if (!is_logged_in() || current_user()['id'] != $l['seller_id']) {
            $ok = false; $message = t('errors.not_owner');
            break;
        }
        db()->prepare('UPDATE listings SET status = "active", is_published = 1, archived_at = NULL, published_at = COALESCE(published_at, NOW()) WHERE id = ?')->execute([$listingId]);
        $message = 'Product restored and published.';
        break;

    case 'mark_sold':
        if (!is_logged_in() || current_user()['id'] != $l['seller_id']) {
            $ok = false; $message = t('errors.not_owner');
            break;
        }
        db()->prepare('UPDATE listings SET availability = "sold" WHERE id = ?')->execute([$listingId]);
        $message = t('flash.listing_marked_sold');
        break;

    case 'mark_rented':
        if (!is_logged_in() || current_user()['id'] != $l['seller_id']) {
            $ok = false; $message = t('errors.not_owner');
            break;
        }
        db()->prepare('UPDATE listings SET availability = "rented" WHERE id = ?')->execute([$listingId]);
        $message = t('flash.listing_marked_rented');
        break;

    case 'relist':
        if (!is_logged_in() || current_user()['id'] != $l['seller_id']) {
            $ok = false; $message = t('errors.not_owner');
            break;
        }
        db()->prepare('UPDATE listings SET availability = "available" WHERE id = ?')->execute([$listingId]);
        $message = t('flash.listing_relisted');
        break;

    case 'delete':
        if (!is_logged_in() || (current_user()['id'] != $l['seller_id'] && !is_admin())) {
            $ok = false; $message = t('errors.not_owner');
            break;
        }
        // orders.listing_id is ON DELETE RESTRICT on purpose (order/financial
        // history must survive a listing being removed) - see the identical
        // fix and reasoning in api/v1/admin-action/index.php's listing_delete
        // case. Without this try/catch, a seller (or admin, via this same
        // endpoint) deleting any listing that had ever been ordered would hit
        // an uncaught PDOException instead of a clean message.
        try {
            db()->prepare('DELETE FROM listings WHERE id = ?')->execute([$listingId]);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'fk_order_listing')) {
                $ok = false;
                $message = t('errors.listing_has_orders');
                break;
            }
            throw $e;
        }
        if (is_admin() && current_user()['id'] != $l['seller_id']) {
            admin_log('listings', 'listing_deleted', 'Listing "' . $l['title'] . '" deleted by admin');
        }
        $message = t('flash.listing_deleted');
        break;

    case 'approve':
        if (!is_logged_in() || !is_admin()) {
            $ok = false; $message = t('errors.access_denied');
            break;
        }
        db()->prepare('UPDATE listings SET status = "active" WHERE id = ?')->execute([$listingId]);
        notify_critical(
            (int)$l['seller_id'],
            'system',
            'Product approved',
            'Your product "' . (string)$l['title'] . '" has been approved and is now live on Isoko Ryacu.',
            '/pages/listing-details.php?id=' . $listingId,
            'product_review_approved'
        );
        admin_log('listings', 'listing_approved', 'Listing "' . $l['title'] . '" approved');
        $message = t('flash.listing_approved');
        break;

    case 'reject':
        if (!is_logged_in() || !is_admin()) {
            $ok = false; $message = t('errors.access_denied');
            break;
        }
        db()->prepare('UPDATE listings SET status = "rejected" WHERE id = ?')->execute([$listingId]);
        notify_critical(
            (int)$l['seller_id'],
            'system',
            'Product needs changes',
            'Your product "' . (string)$l['title'] . '" was not approved. Please open My Products, review the Admin feedback, and update it before resubmitting.',
            '/pages/seller/listings.php',
            'product_review_rejected'
        );
        admin_log('listings', 'listing_rejected', 'Listing "' . $l['title'] . '" rejected');
        $message = t('flash.listing_rejected');
        break;

    case 'resolve_report':
        if (!is_logged_in() || !is_admin()) {
            $ok = false; $message = t('errors.access_denied');
            break;
        }
        $reportId = (int)($_POST['report_id'] ?? 0);
        if (!$reportId) { $ok = false; $message = 'Report id required'; break; }
        db()->prepare('UPDATE reports SET status = "resolved" WHERE id = ?')->execute([$reportId]);
        admin_log('reports', 'report_resolved', 'Report #' . $reportId . ' resolved');
        $message = t('flash.report_resolved');
        break;

    default:
        $ok = false;
        $message = 'Unknown action: ' . $action;
}

if (!$ok) {
    if (is_ajax_action()) {
        json_response(['error' => $message], 403);
    } else {
        flash_set('error', $message);
    }
} else {
    if (is_ajax_action()) {
        json_response(['success' => true, 'message' => $message]);
    } else {
        flash_set('success', $message);
    }
}

// Redirect back
redirect_back(APP_URL . '/pages/seller/dashboard.php');
