<?php
/**
 * api/v1/admin-action/index.php
 * --------------------------------------------------------------------
 * POST /api/v1/admin-action
 *
 * Central, CSRF-protected, role-guarded endpoint for every administrative
 * action performed from the IsokoRyacu admin panel. Every successful (or
 * failed) action is recorded in the system_logs table.
 *
 * Required POST fields:  action  (+ action-specific fields)
 *
 * Actions:
 *   LISTINGS     listing_approve | listing_reject | listing_delete | listing_feature | listing_unfeature
 *                listing_verify | listing_unverify          (Trust & Verification)
 *   USERS        user_suspend | user_activate | user_verify | user_unverify | user_delete
 *   CATEGORIES   category_add | category_toggle
 *   MESSAGES     message_read | message_unread | message_delete | message_read_all
 *   RENTALS      rental_approve | rental_decline
 *   REVIEWS      review_delete
 *   REPORTS      report_status (status: reviewing|resolved|dismissed)
 *   SETTINGS     settings_save
 *
 * Guards on every action:
 *   - POST only            - CSRF token            - admin role
 *   - PDO prepared statements only, output never echoes raw SQL errors.
 * --------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/admin_log.php';
require_once __DIR__ . '/../../../includes/payment_config.php';
require_once __DIR__ . '/../../../includes/wallet.php';

/** Local JSON response helper (the API router is not loaded here). */
if (!function_exists('admin_json_response')) {
    function admin_json_response(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function admin_action_is_ajax(): bool
{
    return (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest')
        || (strtolower($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json');
}

/** Send feedback the way the caller understands (AJAX JSON or flash + redirect). */
function admin_action_finish(bool $ok, string $message, string $fallbackUrl): void
{
    if (admin_action_is_ajax()) {
        if ($ok) admin_json_response(['success' => true, 'message' => $message]);
        admin_json_response(['error' => $message], 403);
    }
    flash_set($ok ? 'success' : 'error', $message);
    redirect_back($fallbackUrl);
}

$backUrl = APP_URL . '/pages/admin/dashboard.php';

// --- Method guard -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (admin_action_is_ajax()) admin_json_response(['error' => t('errors.method_not_allowed')], 405);
    redirect($backUrl);
}

// --- Role guard: every action here requires ADMIN or SUPER_ADMIN -----------
require_role('admin');

// --- CSRF guard --------------------------------------------------------------
if (!csrf_check()) {
    admin_log('system', 'csrf_failed', 'Admin action rejected: invalid CSRF token', 'error');
    admin_action_finish(false, t('errors.invalid_token'), $backUrl);
}

$action = (string) ($_POST['action'] ?? '');
$pdo    = db();

/** Fetch one row helper (prepared). */
function admin_fetch(string $sql, array $params): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

switch ($action) {

    /* ==================================================================
     *  LISTINGS
     * ================================================================== */
    case 'listing_approve':
    case 'listing_reject': {
        $id = (int) ($_POST['listing_id'] ?? 0);
        $l = $id ? admin_fetch('SELECT id, title, seller_id, status FROM listings WHERE id = ?', [$id]) : null;
        if (!$l) { admin_action_finish(false, t('errors.listing_not_found'), $backUrl); }

        $newStatus = $action === 'listing_approve' ? 'active' : 'rejected';
        $listingsCols = db_table_columns($pdo, 'listings');
        if (isset($listingsCols['is_published'])) {
            // Bug fix: approving a listing previously only set status='active'
            // but never set is_published=1 — and the homepage's main listings
            // query requires both, so approved listings never actually showed
            // up there. Keep both in sync on every approve/reject.
            $ok = $pdo->prepare('UPDATE listings SET status = ?, is_published = ? WHERE id = ?')
                ->execute([$newStatus, $action === 'listing_approve' ? 1 : 0, $id]);
        } else {
            $ok = $pdo->prepare('UPDATE listings SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
        }

        if ($ok) {
            // Real notification to the seller — reuse existing notifications table.
            $pdo->prepare('INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)')
                ->execute([
                    (int) $l['seller_id'],
                    'system',
                    $action === 'listing_approve' ? t('flash.listing_approved') : t('flash.listing_rejected'),
                    $l['title'],
                    'pages/listing-details.php?id=' . $id,
                ]);
        }
        admin_log('listings', $action === 'listing_approve' ? 'listing_approved' : 'listing_rejected',
            'Listing "' . $l['title'] . '" -> ' . $newStatus, $ok ? 'success' : 'error');
        admin_action_finish($ok, $ok ? t('flash.listing_' . ($action === 'listing_approve' ? 'approved' : 'rejected')) : t('errors.unknown'), $backUrl);
    }

    case 'listing_delete': {
        $id = (int) ($_POST['listing_id'] ?? 0);
        $l = $id ? admin_fetch('SELECT id, title, seller_id FROM listings WHERE id = ?', [$id]) : null;
        if (!$l) { admin_action_finish(false, t('errors.listing_not_found'), $backUrl); }

        // orders.listing_id is ON DELETE RESTRICT on purpose (order/financial
        // history must survive a listing being removed) - so deleting any
        // listing that has ever been ordered throws an uncaught
        // PDOException with no try/catch here. Catch it and show a clear,
        // actionable message instead of a fatal error / blank page.
        try {
            $ok = $pdo->prepare('DELETE FROM listings WHERE id = ?')->execute([$id]); // cascades to images/attributes/favorites
            admin_log('listings', 'listing_deleted', 'Listing "' . $l['title'] . '" permanently removed', $ok ? 'success' : 'error');
            admin_action_finish($ok, $ok ? t('flash.listing_deleted') : t('errors.unknown'), $backUrl);
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000 || str_contains($e->getMessage(), 'fk_order_listing')) {
                admin_log('listings', 'listing_delete_blocked', 'Delete blocked: "' . $l['title'] . '" has existing orders', 'error');
                admin_action_finish(false, t('errors.listing_has_orders'), $backUrl);
            }
            throw $e;
        }
    }

    case 'listing_feature':
    case 'listing_unfeature': {
        $id = (int) ($_POST['listing_id'] ?? 0);
        $l = $id ? admin_fetch('SELECT id, title, is_featured FROM listings WHERE id = ?', [$id]) : null;
        if (!$l) { admin_action_finish(false, t('errors.listing_not_found'), $backUrl); }

        $ok = $pdo->prepare('UPDATE listings SET is_featured = ? WHERE id = ?')
            ->execute([$action === 'listing_feature' ? 1 : 0, $id]);
        admin_log('listings', $action === 'listing_feature' ? 'listing_featured' : 'listing_unfeatured',
            'Listing "' . $l['title'] . '" featured flag toggled', $ok ? 'success' : 'error');
        admin_action_finish($ok, t('admin.action_done'), $backUrl);
    }

    /* ==================================================================
     *  LISTINGS — TRUST & VERIFICATION (Phase 8)
     *  Verified Listing flag. Never applied automatically: admins decide.
     * ================================================================== */
    case 'listing_verify':
    case 'listing_unverify': {
        require_once __DIR__ . '/../../../includes/smart_features.php';
        listings_verified_ready();

        $id = (int) ($_POST['listing_id'] ?? 0);
        $l = $id ? admin_fetch('SELECT id, title, seller_id FROM listings WHERE id = ?', [$id]) : null;
        if (!$l) { admin_action_finish(false, t('errors.listing_not_found'), $backUrl); }

        $verifying = ($action === 'listing_verify');
        $ok = $pdo->prepare('UPDATE listings SET is_verified = ? WHERE id = ?')->execute([$verifying ? 1 : 0, $id]);

        if ($ok) {
            // Notify the seller about the real verification decision.
            $pdo->prepare('INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)')
                ->execute([
                    (int) $l['seller_id'],
                    'system',
                    $verifying ? t('trust.notif_verified_title') : t('trust.notif_unverified_title'),
                    $l['title'],
                    'pages/listing-details.php?id=' . $id,
                ]);
        }
        admin_log('listings', $verifying ? 'listing_verified' : 'listing_unverified',
            'Listing "' . $l['title'] . '" verification flag ' . ($verifying ? 'granted' : 'removed'), $ok ? 'success' : 'error');
        admin_action_finish($ok, t('admin.action_done'), $backUrl);
    }

    /* ==================================================================
     *  USERS — PERMANENT DELETE
     * ================================================================== */
    case 'user_delete': {
        $id=(int)($_POST['user_id']??0);
        $u=$id?admin_fetch('SELECT u.id,u.full_name,r.name AS role_name,u.is_primary_super_admin FROM users u INNER JOIN roles r ON r.id=u.role_id WHERE u.id=?',[$id]):null;
        if(!$u)admin_action_finish(false,t('errors.user_not_found'),$backUrl);
        $me=current_user();$targetRole=strtoupper((string)$u['role_name']);$meRole=strtoupper((string)($me['role_name']??''));
        if((int)$u['id']===(int)$me['id'])admin_action_finish(false,'You cannot delete your own account.',$backUrl);
        // Only the currently authenticated Super Admin may delete another Super Admin.
        // A Super Admin may delete every other account type (including ADMIN).
        if($targetRole==='SUPER_ADMIN'&&$meRole!=='SUPER_ADMIN')admin_action_finish(false,'Only the Super Admin can permanently delete a Super Admin account.',$backUrl);
        if($targetRole==='ADMIN'&&$meRole!=='SUPER_ADMIN')admin_action_finish(false,'Only the Super Admin can permanently delete an administrator.',$backUrl);
        try{permanently_delete_user($pdo,$id);admin_log('users','user_deleted','User "'.$u['full_name'].'" permanently deleted by '.($me['full_name']??'admin'),'success');admin_action_finish(true,'User deleted permanently.',$backUrl);}catch(Throwable $e){admin_log('users','user_delete_failed','Failed to delete user "'.$u['full_name'].'": '.$e->getMessage(),'error');admin_action_finish(false,'User could not be deleted. The database transaction was rolled back.',$backUrl);}
    }

    /* ==================================================================
     *  USERS
     * ================================================================== */
    case 'user_suspend':
    case 'user_activate': {
        $id = (int) ($_POST['user_id'] ?? 0);
        $u = $id ? admin_fetch('SELECT id, full_name, status, role_id FROM users WHERE id = ?', [$id]) : null;
        if (!$u) { admin_action_finish(false, t('errors.user_not_found'), $backUrl); }

        // Guard: admins may not suspend themselves or other admins/super admins.
        $me = current_user();
        $targetRole = strtoupper(role_name((int) $u['role_id']));
        if ((int) $u['id'] === (int) $me['id']) {
            admin_action_finish(false, t('admin.err_cannot_modify_self'), $backUrl);
        }
        if (in_array($targetRole, ['ADMIN', 'SUPER_ADMIN'], true)) {
            admin_action_finish(false, t('admin.err_cannot_modify_admin'), $backUrl);
        }

        $newStatus = $action === 'user_suspend' ? 'suspended' : 'active';
        $ok = $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
        admin_log('users', $action === 'user_suspend' ? 'user_suspended' : 'user_activated',
            'User "' . $u['full_name'] . '" -> ' . $newStatus, $ok ? 'success' : 'error');
        admin_action_finish($ok, t('admin.action_done'), $backUrl);
    }

    case 'user_verify':
    case 'user_unverify': {
        $id = (int) ($_POST['user_id'] ?? 0);
        $u = $id ? admin_fetch('SELECT id, full_name FROM users WHERE id = ?', [$id]) : null;
        if (!$u) { admin_action_finish(false, t('errors.user_not_found'), $backUrl); }

        $ok = $pdo->prepare('UPDATE users SET is_verified = ? WHERE id = ?')
            ->execute([$action === 'user_verify' ? 1 : 0, $id]);
        admin_log('users', $action === 'user_verify' ? 'user_verified' : 'user_unverified',
            'Verification flag changed for "' . $u['full_name'] . '"', $ok ? 'success' : 'error');
        admin_action_finish($ok, t('admin.action_done'), $backUrl);
    }

    /* ==================================================================
     *  CATEGORIES
     * ================================================================== */
    case 'category_add': {
        $name = trim((string) ($_POST['name'] ?? ''));
        $icon = trim((string) ($_POST['icon'] ?? ''));
        if ($name === '' || mb_strlen($name) < 2) {
            admin_action_finish(false, t('admin.err_category_name'), $backUrl);
        }
        $slug = slugify($name);
        if (admin_fetch('SELECT id FROM categories WHERE slug = ?', [$slug])) {
            admin_action_finish(false, t('admin.err_category_exists'), $backUrl);
        }
        $ok = $pdo->prepare('INSERT INTO categories (name, slug, icon, is_active) VALUES (?, ?, ?, 1)')
            ->execute([$name, $slug, $icon ?: null]);
        admin_log('categories', 'category_created', 'Category "' . $name . '" created', $ok ? 'success' : 'error');
        admin_action_finish($ok, t('admin.action_done'), $backUrl);
    }

    case 'category_toggle': {
        $id = (int) ($_POST['category_id'] ?? 0);
        $c = $id ? admin_fetch('SELECT id, name, is_active FROM categories WHERE id = ?', [$id]) : null;
        if (!$c) { admin_action_finish(false, t('admin.err_category_not_found'), $backUrl); }

        $ok = $pdo->prepare('UPDATE categories SET is_active = ? WHERE id = ?')
            ->execute([(int) $c['is_active'] === 1 ? 0 : 1, $id]);
        admin_log('categories', 'category_changed',
            'Category "' . $c['name'] . '" ' . ((int) $c['is_active'] === 1 ? 'hidden' : 'shown'), $ok ? 'success' : 'error');
        admin_action_finish($ok, t('admin.action_done'), $backUrl);
    }

    /* ==================================================================
     *  MESSAGES (contact requests — the marketplace messaging system)
     * ================================================================== */
    case 'message_read':
    case 'message_unread': {
        $id = (int) ($_POST['message_id'] ?? 0);
        $m = $id ? admin_fetch('SELECT id, name FROM contact_requests WHERE id = ?', [$id]) : null;
        if (!$m) { admin_action_finish(false, t('admin.err_message_not_found'), $backUrl); }

        $ok = $pdo->prepare('UPDATE contact_requests SET is_read = ? WHERE id = ?')
            ->execute([$action === 'message_read' ? 1 : 0, $id]);
        admin_log('messages', $action === 'message_read' ? 'message_marked_read' : 'message_marked_unread',
            'Message from "' . $m['name'] . '"', $ok ? 'success' : 'error');
        admin_action_finish($ok, t('admin.action_done'), $backUrl);
    }

    case 'message_delete': {
        $id = (int) ($_POST['message_id'] ?? 0);
        $m = $id ? admin_fetch('SELECT id, name FROM contact_requests WHERE id = ?', [$id]) : null;
        if (!$m) { admin_action_finish(false, t('admin.err_message_not_found'), $backUrl); }

        $ok = $pdo->prepare('DELETE FROM contact_requests WHERE id = ?')->execute([$id]);
        admin_log('messages', 'message_deleted', 'Message from "' . $m['name'] . '" deleted', $ok ? 'success' : 'error');
        admin_action_finish($ok, t('admin.action_done'), $backUrl);
    }

    case 'message_read_all': {
        $n = (int) $pdo->exec('UPDATE contact_requests SET is_read = 1 WHERE is_read = 0');
        admin_log('messages', 'messages_marked_all_read', $n . ' message(s) marked as read');
        admin_action_finish(true, t('admin.action_done'), $backUrl);
    }

    /* ==================================================================
     *  RENTAL REQUESTS
     * ================================================================== */
    case 'rental_approve':
    case 'rental_decline': {
        $id = (int) ($_POST['rental_id'] ?? 0);
        $r = $id ? admin_fetch(
            'SELECT rr.id, rr.renter_id, rr.status, l.title AS listing_title
             FROM rental_requests rr JOIN listings l ON l.id = rr.listing_id WHERE rr.id = ?', [$id]) : null;
        if (!$r) { admin_action_finish(false, t('admin.err_rental_not_found'), $backUrl); }

        $newStatus = $action === 'rental_approve' ? 'approved' : 'declined';
        $ok = $pdo->prepare('UPDATE rental_requests SET status = ? WHERE id = ?')->execute([$newStatus, $id]);

        if ($ok) {
            // Real notification to the renter.
            $pdo->prepare('INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)')
                ->execute([
                    (int) $r['renter_id'],
                    'rental',
                    $action === 'rental_approve' ? t('admin.notif_rental_approved') : t('admin.notif_rental_declined'),
                    $r['listing_title'],
                    'pages/favorites.php',
                ]);
        }
        admin_log('rentals', $action === 'rental_approve' ? 'rental_approved' : 'rental_declined',
            'Rental request for "' . $r['listing_title'] . '" -> ' . $newStatus, $ok ? 'success' : 'error');
        admin_action_finish($ok, t('admin.action_done'), $backUrl);
    }

    /* ==================================================================
     *  REVIEWS (moderation)
     * ================================================================== */
    case 'review_delete': {
        $id = (int) ($_POST['review_id'] ?? 0);
        $rv = $id ? admin_fetch(
            'SELECT rv.id, rv.rating, u.full_name AS reviewer
             FROM reviews rv JOIN users u ON u.id = rv.reviewer_id WHERE rv.id = ?', [$id]) : null;
        if (!$rv) { admin_action_finish(false, t('admin.err_review_not_found'), $backUrl); }

        $ok = $pdo->prepare('DELETE FROM reviews WHERE id = ?')->execute([$id]);
        admin_log('reviews', 'review_deleted',
            (int) $rv['rating'] . '-star review by "' . $rv['reviewer'] . '" removed', $ok ? 'success' : 'error');
        admin_action_finish($ok, t('admin.action_done'), $backUrl);
    }

    /* ==================================================================
     *  DISPUTES
     *  See docs/dispute-resolution-gap.md for context: the disputes
     *  table schema was fully designed but nothing resolved one until
     *  this action. Uses wallet_refund()/wallet_release() directly
     *  (not release_escrow_to_seller(), which is buyer-authorized and
     *  explicitly refuses disputed orders) since this runs under admin
     *  authorization instead.
     * ================================================================== */
    case 'dispute_resolve': {
        $disputeId  = (int) ($_POST['dispute_id'] ?? 0);
        $resolution = (string) ($_POST['resolution'] ?? '');
        $adminUid   = (int) current_user()['id'];

        if (!in_array($resolution, ['refund_full', 'release_to_seller', 'split'], true)) {
            admin_action_finish(false, t('errors.required_fields'), $backUrl);
        }

        $dispute = $disputeId ? admin_fetch(
            'SELECT d.*, o.id AS order_id, o.order_number, o.buyer_id, o.seller_id, o.grand_total,
                    o.status AS order_status, o.payment_status
             FROM disputes d JOIN orders o ON o.id = d.order_id WHERE d.id = ?',
            [$disputeId]
        ) : null;
        if (!$dispute) { admin_action_finish(false, t('errors.dispute_not_found'), $backUrl); }
        if (in_array($dispute['status'], ['resolved', 'closed'], true)) {
            admin_action_finish(false, t('errors.dispute_already_resolved'), $backUrl);
        }
        if ($dispute['payment_status'] !== 'escrow_held') {
            admin_action_finish(false, t('errors.dispute_no_escrow'), $backUrl);
        }

        $orderId   = (int) $dispute['order_id'];
        $buyerId   = (int) $dispute['buyer_id'];
        $sellerId  = (int) $dispute['seller_id'];
        $total     = (float) $dispute['grand_total'];
        $orderNum  = $dispute['order_number'];

        $buyerAmount = 0.0;
        $sellerAmount = 0.0;
        if ($resolution === 'refund_full') {
            $buyerAmount = $total;
        } elseif ($resolution === 'release_to_seller') {
            $sellerAmount = $total;
        } else { // split
            $buyerAmount = round((float) ($_POST['split_buyer_amount'] ?? -1), 2);
            if ($buyerAmount < 0 || $buyerAmount > $total) {
                admin_action_finish(false, t('errors.dispute_invalid_split'), $backUrl);
            }
            $sellerAmount = round($total - $buyerAmount, 2);
        }

        try {
            $pdo->beginTransaction();

            // Lock the order row for the duration of the resolution
            $pdo->prepare('SELECT id FROM orders WHERE id = ? FOR UPDATE')->execute([$orderId]);

            if ($buyerAmount > 0) {
                wallet_refund($buyerId, $buyerAmount, $orderId, 'Dispute ' . $dispute['dispute_number'] . ' resolution: refund');
            }
            if ($sellerAmount > 0) {
                wallet_release($sellerId, $sellerAmount, $orderId, 'Dispute ' . $dispute['dispute_number'] . ' resolution: release');
            }

            $newOrderStatus = $resolution === 'refund_full' ? 'cancelled' : 'completed';
            $pdo->prepare(
                "UPDATE orders SET status = ?, payment_status = 'released', escrow_released = 1,
                    completed_at = NOW(), updated_at = NOW() WHERE id = ?"
            )->execute([$newOrderStatus, $orderId]);

            $pdo->prepare(
                'UPDATE disputes SET status = "resolved", resolution = ?, refund_amount = ?, admin_id = ?, resolved_at = NOW() WHERE id = ?'
            )->execute([$resolution, $buyerAmount, $adminUid, $disputeId]);

            $pdo->prepare(
                "INSERT INTO delivery_tracking (order_id, status, note, created_by) VALUES (?, ?, ?, ?)"
            )->execute([$orderId, $newOrderStatus, 'Dispute resolved by admin: ' . $resolution, $adminUid]);

            $note = match ($resolution) {
                'refund_full' => 'The dispute for order ' . $orderNum . ' was resolved with a full refund to you.',
                'release_to_seller' => 'The dispute for order ' . $orderNum . ' was resolved in the seller\'s favor.',
                'split' => 'The dispute for order ' . $orderNum . ' was resolved with a split settlement.',
            };
            $pdo->prepare('INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, "order", "Dispute resolved", ?, ?)')
                ->execute([$buyerId, $note, '/pages/orders.php?id=' . $orderId]);
            $pdo->prepare('INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, "order", "Dispute resolved", ?, ?)')
                ->execute([$sellerId, $note, '/pages/orders.php?view=seller&id=' . $orderId]);

            $pdo->commit();
            admin_log('disputes', 'dispute_resolved',
                'Dispute ' . $dispute['dispute_number'] . ' resolved (' . $resolution . ') for order ' . $orderNum, 'success');
            admin_action_finish(true, t('admin.action_done'), $backUrl);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                try { $pdo->rollBack(); } catch (PDOException $rb) { /* ignore */ }
            }
            admin_log('disputes', 'dispute_resolve_failed',
                'Failed to resolve dispute ' . $dispute['dispute_number'], 'error');
            admin_action_finish(false, t('errors.unknown'), $backUrl);
        }
    }

    /* ==================================================================
     *  REPORTS
     * ================================================================== */
    case 'report_status': {
        $id = (int) ($_POST['report_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? '');
        if (!in_array($status, ['reviewing', 'resolved', 'dismissed'], true)) {
            admin_action_finish(false, t('errors.required_fields'), $backUrl);
        }
        $r = $id ? admin_fetch('SELECT id, reason, status FROM reports WHERE id = ?', [$id]) : null;
        if (!$r) { admin_action_finish(false, t('admin.err_report_not_found'), $backUrl); }

        $ok = $pdo->prepare('UPDATE reports SET status = ? WHERE id = ?')->execute([$status, $id]);
        admin_log('reports', 'report_' . $status,
            'Report "' . $r['reason'] . '" -> ' . $status, $ok ? 'success' : 'error');
        admin_action_finish($ok, t('admin.action_done'), $backUrl);
    }

    /* ==================================================================
     *  SETTINGS (site_settings table)
     * ================================================================== */
    case 'homepage_save': {
        if (!is_super_admin()) { admin_action_finish(false, 'Only Super Admin can edit homepage content.', APP_URL . '/pages/admin/homepage.php'); }
        $allowedHome = [''];
        $keys = ['hero_eyebrow','hero_title','hero_subtitle','hero_search_placeholder','browse_categories_title','featured_products_title','trending_title','rent_title','all_rentals','community_title','community_subtitle','video_title','updates_title','market_insights_title','market_insights_sub','market_blog_title','all_articles','cta_title','cta_body','cta_sell_item','cta_register_seller','see_all','view_all'];
        $langs = ['en','rw','fr','sw'];
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), description=VALUES(description)');
            $changed=[];
            foreach ($langs as $lang) foreach ($keys as $key) {
                $name='home_'.$key.'_'.$lang;
                if (!array_key_exists($name,$_POST)) continue;
                $value=trim((string)$_POST[$name]);
                $value=mb_substr($value,0,500);
                $stmt->execute([$name,$value,'Super Admin homepage content override']);
                $changed[]=$name;
            }
            $pdo->commit();
            admin_log('settings','homepage_content_changed','Updated homepage content: '.count($changed).' fields','success');
            admin_action_finish(true,'Homepage content saved successfully.',APP_URL.'/pages/admin/homepage.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            admin_log('settings','homepage_content_failed','Homepage content save failed','error');
            admin_action_finish(false,'Could not save homepage content. Please try again.',APP_URL.'/pages/admin/homepage.php');
        }
    }

    case 'settings_save': {
        $allowed = [
            'site_name'            => ['label' => 'Site name',            'max' => 120],
            'site_tagline'         => ['label' => 'Site tagline',         'max' => 200],
            'contact_email'        => ['label' => 'Contact email',        'max' => 160],
            'contact_phone'        => ['label' => 'Contact phone',         'max' => 40],
            'default_currency'     => ['label' => 'Default currency',      'max' => 8],
            'posts_per_page'       => ['label' => 'Listings per page',     'max' => 4, 'int' => true],
            'allow_guest_contact'  => ['label' => 'Allow guest contact',   'max' => 1, 'int' => true],
            'enable_dark_mode'     => ['label' => 'Enable dark mode',      'max' => 1, 'int' => true],
        ];

        // Only SUPER_ADMIN may manage payment provider credentials/settings.
        if (is_super_admin()) {
            $allowed['payment_platform_fee_percent'] = ['label' => 'Marketplace fee', 'max' => 8];
            $paymentPlainFields = [
                'payment_mtn_api_user','payment_mtn_api_key','payment_mtn_subscription_key',
                'payment_airtel_client_id','payment_airtel_client_secret',
                'payment_card_secret_key','payment_card_webhook_secret',
                'payment_bank_merchant_id','payment_bank_api_key','payment_bank_api_secret',
                'payment_crypto_api_key','payment_crypto_api_secret','payment_crypto_webhook_secret'
            ];
            $paymentNormalFields = [
                'payment_mtn_enabled','payment_mtn_environment','payment_mtn_callback_url',
                'payment_airtel_enabled','payment_airtel_environment','payment_airtel_callback_url',
                'payment_card_enabled','payment_card_provider','payment_card_publishable_key','payment_card_callback_url',
                'payment_bank_enabled','payment_bank_provider','payment_bank_callback_url',
                'payment_crypto_enabled','payment_crypto_provider','payment_crypto_callback_url'
            ];
            $secretDescriptions = [];
            foreach ($paymentPlainFields as $key) $secretDescriptions[$key] = 'Encrypted payment provider credential';
            foreach ($paymentNormalFields as $key) $allowed[$key] = ['label' => $key, 'max' => 500];
            $changedPayment = [];
            foreach ($paymentNormalFields as $key) {
                if (str_ends_with($key, '_enabled')) {
                    $raw = isset($_POST[$key]) ? '1' : '0';
                } elseif (!array_key_exists($key, $_POST)) {
                    continue;
                } else {
                    $raw = trim((string)$_POST[$key]);
                }
                if (str_ends_with($key, '_environment') && !in_array($raw, ['sandbox','production'], true)) continue;
                if ($key === 'payment_card_provider') $raw = mb_substr($raw, 0, 80);
                payment_save_setting($key, mb_substr($raw, 0, 500), 'Admin-configurable payment provider setting');
                $changedPayment[] = $key;
            }
            foreach ($paymentPlainFields as $key) {
                if (!array_key_exists($key, $_POST)) continue;
                $raw = trim((string)$_POST[$key]);
                if ($raw === '') continue; // blank means keep existing secret
                payment_save_secret($key, $raw, $secretDescriptions[$key]);
                $changedPayment[] = $key;
            }
            if ($changedPayment) admin_log('settings', 'payment_provider_settings_changed', 'Updated payment settings: ' . implode(', ', $changedPayment), 'success');
        }

        $changed = [];
        $stmt = $pdo->prepare('UPDATE site_settings SET setting_value = ? WHERE setting_key = ?');

        foreach ($allowed as $key => $meta) {
            if (!isset($_POST[$key])) continue;
            $raw = trim((string) $_POST[$key]);
            if ($meta['int'] ?? false) $raw = (((int) $raw) > 0) ? '1' : '0';
            $raw = mb_substr($raw, 0, $meta['max']);
            if ($raw === '') continue;
            if ($key === 'contact_email' && !filter_var($raw, FILTER_VALIDATE_EMAIL)) continue;
            if ($key === 'posts_per_page') { $raw = (string) max(1, min(48, (int) $raw)); }
            $stmt->execute([$raw, $key]);
            $changed[] = $meta['label'];
        }
        admin_log('settings', 'settings_changed', 'Updated: ' . ($changed ? implode(', ', $changed) : 'nothing'));
        admin_action_finish(true, t('admin.action_done'), $backUrl);
    }

    /* ==================================================================
     *  Unknown action
     * ================================================================== */
    default:
        admin_log('system', 'unknown_action', 'Unknown admin action "' . mb_substr($action, 0, 60) . '"', 'error');
        admin_action_finish(false, t('errors.required_fields'), $backUrl);
}
