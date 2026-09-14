<?php
/**
 * Seller Product Creation — Isoko Ryacu
 * Uses the existing `listings` table as the marketplace Product entity.
 * Supports sell/rent, inventory, category-aware attributes, photos and drafts.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/seller_sidebar.php';
require_once __DIR__ . '/../includes/notification_service.php';
require_login();

$pdo = db();
start_session();

// One-time submission token — separate from the CSRF token — to guard
// against duplicate product creation from a double-click, a resubmit
// from two open tabs, or a network retry resending the same POST. The
// JS submit handler already disables the buttons after one click, but
// that is a UI nicety only; this is the real, server-side guarantee.
if (empty($_SESSION['sell_form_token'])) {
    $_SESSION['sell_form_token'] = bin2hex(random_bytes(16));
}
$sellFormToken = $_SESSION['sell_form_token'];

// -----------------------------------------------------------------------------
// Runtime compatibility guard
// -----------------------------------------------------------------------------
// The project has several optional seller/product migrations. A common XAMPP
// failure mode is having an older database while the PHP files are newer.
// In that case the INSERT can fail before the seller ever sees a useful error.
// We therefore detect optional columns at runtime and only write columns that
// actually exist. Core columns (already present in schema.sql) remain required.
function sell_table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
    $cols = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cols[(string)$row['Field']] = true;
    }
    return $cache[$table] = $cols;
}

ensure_listing_delivery_payment_columns($pdo);
$listingsCols = sell_table_columns($pdo, 'listings');
$categoriesCols = sell_table_columns($pdo, 'categories');
$subcategoriesCols = sell_table_columns($pdo, 'subcategories');

$catSelect = 'id, name, slug' . (isset($categoriesCols['name_key']) ? ', name_key' : '');
$subSelect = 'id, name, slug' . (isset($subcategoriesCols['name_key']) ? ', name_key' : '');
$cats = $pdo->query(
    "SELECT $catSelect
     FROM categories
     WHERE is_active = 1 AND parent_id IS NULL
     ORDER BY display_order, name"
)->fetchAll();

// If a database was seeded with only child/nested categories, keep the form usable
// rather than showing an almost-empty selector. The catalog migration restores the
// standard top-level catalogue; this fallback also prevents a blank form on older DBs.
if (!$cats) {
    $cats = $pdo->query(
        "SELECT $catSelect
         FROM categories
         WHERE is_active = 1
         ORDER BY display_order, name"
    )->fetchAll();
}
$subcatsByCat = [];
foreach ($cats as $c) {
    $stmt = $pdo->prepare("SELECT $subSelect FROM subcategories WHERE category_id = ? ORDER BY name");
    $stmt->execute([(int)$c['id']]);
    $subcatsByCat[(int)$c['id']] = $stmt->fetchAll();
}

$errors = [];
$uploadedPaths = [];
$submissionSuccess = '';
$submissionSuccessListingId = 0;
$formAction = $_POST['form_action'] ?? 'publish';
$isDraft = $formAction === 'draft';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = t('errors.invalid_token');
    } elseif (empty($_POST['form_token']) || !hash_equals($_SESSION['sell_form_token'] ?? '', (string) $_POST['form_token'])) {
        // The token in this request doesn't match the one currently held
        // in the session, which means it was already consumed by an
        // earlier request (a duplicate/double-click/resubmit). Reject it
        // without touching the database — the earlier request already
        // handled the real submission.
        $errors[] = 'This form was already submitted. Please check My Products before submitting again.';
    } else {
        // Rotate the token immediately so a second request carrying the
        // same (now-stale) token can never pass this check again — even
        // one that was queued behind this one on the same session.
        $_SESSION['sell_form_token'] = bin2hex(random_bytes(16));
        $sellFormToken = $_SESSION['sell_form_token'];

        $title         = trim((string)($_POST['title'] ?? ''));
        $description   = trim((string)($_POST['description'] ?? ''));
        $categoryId    = (int)($_POST['category'] ?? 0);
        $subcategoryId = (int)($_POST['subcategory'] ?? 0) ?: null;
        $listingType   = in_array($_POST['listing_type'] ?? '', ['sell', 'rent'], true) ? $_POST['listing_type'] : 'sell';
        $price         = (float)($_POST['price'] ?? 0);
        $sku           = trim((string)($_POST['sku'] ?? ''));
        $stockQuantity = max(0, (int)($_POST['stock_quantity'] ?? 0));
        $lowStock      = max(0, (int)($_POST['low_stock_threshold'] ?? 5));
        $currency      = strtoupper(trim((string)($_POST['currency'] ?? DEFAULT_CURRENCY)));
        $condition     = in_array($_POST['condition_state'] ?? '', ['new','used','refurbished','for-parts'], true) ? $_POST['condition_state'] : 'used';
        $country       = trim((string)($_POST['country'] ?? 'Rwanda'));
        $province      = trim((string)($_POST['province'] ?? ''));
        $district      = trim((string)($_POST['district'] ?? ''));
        $sector        = trim((string)($_POST['sector'] ?? ''));
        $area          = trim((string)($_POST['area'] ?? ''));
        $address       = trim((string)($_POST['address'] ?? ''));
        $parts         = array_filter([$address, $area, $sector, $district, $province, $country]);
        $location      = implode(', ', $parts);
        $pickupAvailable   = isset($_POST['pickup_available']) ? 1 : 0;
        $deliveryAvailable = isset($_POST['delivery_available']) ? 1 : 0;
        $acceptedPayments  = implode(',', array_intersect((array)($_POST['accepted_payments'] ?? []), ['cash', 'momo', 'bank']));

        $pricePerDay   = $listingType === 'rent' ? max(0, (float)($_POST['price_per_day'] ?? 0)) : null;
        $pricePerWeek  = $listingType === 'rent' ? max(0, (float)($_POST['price_per_week'] ?? 0)) : null;
        $pricePerMonth = $listingType === 'rent' ? max(0, (float)($_POST['price_per_month'] ?? 0)) : null;
        $deposit       = $listingType === 'rent' ? max(0, (float)($_POST['deposit'] ?? 0)) : null;
        $rentalTerms   = $listingType === 'rent' ? trim((string)($_POST['rental_terms'] ?? '')) : null;

        // Core validation.
        if (mb_strlen($title) < 5) $errors[] = 'Product name must be at least 5 characters.';
        if (mb_strlen($title) > 180) $errors[] = 'Product name must be 180 characters or fewer.';
        if (!$isDraft && mb_strlen($description) < 20) $errors[] = 'Add a useful product description (at least 20 characters).';
        if ($isDraft && $description !== '' && mb_strlen($description) < 10) $errors[] = 'Draft description must be at least 10 characters or leave it empty.';
        if (!$categoryId) $errors[] = 'Please choose a category.';
        if ($price < 0) $errors[] = 'Price cannot be negative.';
        if ($price > 999999999999.99) $errors[] = 'Price is too large.';
        if (!preg_match('/^[A-Z0-9._-]{2,8}$/', $currency)) $errors[] = 'Currency must be a valid short code such as RWF.';
        if (mb_strlen($sku) > 60 || ($sku !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $sku))) $errors[] = 'SKU may contain letters, numbers, dots, dashes and underscores only.';
        if ($listingType === 'sell' && !$isDraft && $price <= 0) $errors[] = 'Price is required for a product for sale.';
        if ($listingType === 'sell' && !$isDraft && $stockQuantity < 1) $errors[] = 'Stock quantity must be at least 1 for a product for sale.';
        if ($listingType === 'rent' && !$isDraft && !$pricePerDay && !$pricePerWeek && !$pricePerMonth) $errors[] = 'Add at least one rental price (day, week or month).';
        if (!$isDraft && !$country) $errors[] = 'Country is required.';
        if (!$isDraft && !$pickupAvailable && !$deliveryAvailable) $errors[] = 'Select at least one option for how buyers can get this item (pickup or delivery).';
        if (!$isDraft && $acceptedPayments === '') $errors[] = 'Select at least one accepted payment method.';

        // Category and subcategory integrity.
        $category = null;
        if ($categoryId) {
            $stmt = $pdo->prepare('SELECT id, name, slug FROM categories WHERE id = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$categoryId]);
            $category = $stmt->fetch();
            if (!$category) $errors[] = 'Selected category is not available.';
        }
        if ($subcategoryId && $categoryId) {
            $stmt = $pdo->prepare('SELECT id FROM subcategories WHERE id = ? AND category_id = ? LIMIT 1');
            $stmt->execute([$subcategoryId, $categoryId]);
            if (!$stmt->fetch()) $errors[] = 'Selected subcategory does not belong to the chosen category.';
        }

        // SKU is unique per seller when provided, but only if the current
        // database has the seller/SKU migration applied.
        if ($sku !== '' && isset($listingsCols['sku'])) {
            $stmt = $pdo->prepare('SELECT id FROM listings WHERE seller_id = ? AND sku = ? LIMIT 1');
            $stmt->execute([(int)current_user()['id'], $sku]);
            if ($stmt->fetch()) $errors[] = 'That SKU is already used by one of your products.';
        }

        // Published products need at least one image. Drafts may be saved without one.
        if (!$errors && !$isDraft) {
            $uploadedPaths = handle_image_upload('images', 'product_' . time(), true);
            if (isset($uploadedPaths['error'])) {
                $errors[] = $uploadedPaths['error'];
                $uploadedPaths = [];
            }
            if (count($uploadedPaths) > 8) {
                $errors[] = 'You can upload up to 8 product photos.';
                foreach (array_slice($uploadedPaths, 8) as $extra) {
                    if (is_string($extra)) @unlink(__DIR__ . '/../' . $extra);
                }
                $uploadedPaths = array_slice($uploadedPaths, 0, 8);
            }
            if (!$uploadedPaths) $errors[] = 'Add at least one product photo before publishing.';
        }

        if (!$errors) {
            $slug = slugify($title) . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
            // Products go live immediately — no admin review queue. Drafts
            // remain unpublished (and unlisted) until the seller submits them.
            $status = $isDraft ? 'draft' : 'active';
            $published = $isDraft ? 0 : 1;
            $inventory = $listingType === 'sell' ? $stockQuantity : 0;

            try {
                $pdo->beginTransaction();

                // Build the INSERT from columns that really exist in this DB.
                // This makes the seller form compatible with schema.sql-only
                // installations as well as fully migrated installations.
                $candidate = [
                    'seller_id' => (int)current_user()['id'],
                    'category_id' => $categoryId,
                    'subcategory_id' => $subcategoryId,
                    'title' => $title,
                    'slug' => $slug,
                    'description' => $description,
                    'listing_type' => $listingType,
                    'price' => $price,
                    'currency' => $currency,
                    'sku' => $sku !== '' ? $sku : null,
                    'stock_quantity' => $inventory,
                    'low_stock_threshold' => $lowStock,
                    'reserved_quantity' => 0,
                    'is_published' => $published,
                    'price_per_day' => $pricePerDay,
                    'price_per_week' => $pricePerWeek,
                    'price_per_month' => $pricePerMonth,
                    'deposit' => $deposit,
                    'rental_terms' => $rentalTerms,
                    'location' => $location ?: null,
                    'country' => $country ?: null,
                    'province' => $province ?: null,
                    'district' => $district ?: null,
                    'area' => $area ?: null,
                    'condition_state' => $condition,
                    'availability' => 'available',
                    'status' => $status,
                    'pickup_available' => $pickupAvailable,
                    'delivery_available' => $deliveryAvailable,
                    'accepted_payments' => $acceptedPayments !== '' ? $acceptedPayments : null,
                ];
                $insertData = [];
                foreach ($candidate as $column => $value) {
                    if (isset($listingsCols[$column])) $insertData[$column] = $value;
                }

                $columns = array_keys($insertData);
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                $sql = 'INSERT INTO listings (' . implode(', ', array_map(fn($c) => '`' . $c . '`', $columns)) . ') VALUES (' . $placeholders . ')';
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_values($insertData));
                $listingId = (int)$pdo->lastInsertId();

                foreach ($uploadedPaths as $i => $img) {
                    if (is_array($img)) {
                        // Filesystem write failed for this photo (e.g. an
                        // uploads/ permissions problem), so it was kept as
                        // raw bytes instead — persist it as a database BLOB
                        // rather than losing the photo or failing the sale.
                        ensure_listing_image_blob_storage($pdo);
                        $pdo->prepare('INSERT INTO listing_images (listing_id, image_path, is_primary, display_order, image_blob, image_mime) VALUES (?, ?, ?, ?, ?, ?)')
                            ->execute([$listingId, 'db-blob:pending', $i === 0 ? 1 : 0, $i, $img['blob'], $img['mime']]);
                        $imgId = (int) $pdo->lastInsertId();
                        $pdo->prepare('UPDATE listing_images SET image_path = ? WHERE id = ?')
                            ->execute(['db-blob:' . $imgId, $imgId]);
                    } else {
                        $pdo->prepare('INSERT INTO listing_images (listing_id, image_path, is_primary, display_order) VALUES (?, ?, ?, ?)')
                            ->execute([$listingId, $img, $i === 0 ? 1 : 0, $i]);
                    }
                }

                // Store optional product details using the existing attribute system.
                // IMPORTANT: generic fields (brand/model/color/size) can overlap with
                // category-specific fields (e.g. Vehicles -> model). The UNIQUE KEY on
                // (listing_id, attr_key) must never abort the whole product transaction.
                $seenAttrs = [];
                foreach ($_POST as $key => $value) {
                    if (!str_starts_with($key, 'attr_key_')) continue;
                    $idx = substr($key, 9);
                    $attrName = trim((string)$value);
                    $attrValue = trim((string)($_POST['attr_value_' . $idx] ?? ''));
                    if ($attrName && $attrValue && preg_match('/^[a-z0-9_]{1,80}$/', $attrName) && !isset($seenAttrs[$attrName])) {
                        $pdo->prepare(
                            'INSERT INTO listing_attributes (listing_id, attr_key, attr_value) VALUES (?, ?, ?)
                             ON DUPLICATE KEY UPDATE attr_value = VALUES(attr_value)'
                        )->execute([$listingId, $attrName, mb_substr($attrValue, 0, 255)]);
                        $seenAttrs[$attrName] = true;
                    }
                }
                // Preserve the extra address line without changing the existing listings schema.
                if ($sector !== '') {
                    $pdo->prepare('INSERT INTO listing_attributes (listing_id, attr_key, attr_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE attr_value = VALUES(attr_value)')
                        ->execute([$listingId, 'sector', mb_substr($sector, 0, 255)]);
                }

                if (!is_admin() && !(bool)(current_user()['is_seller'] ?? false)) {
                    $pdo->prepare('UPDATE users SET is_seller = 1 WHERE id = ?')->execute([(int)current_user()['id']]);
                    // seller_profiles belongs to the optional seller migration.
                    // Never roll back a valid product just because that table is
                    // missing on an older installation.
                    try {
                        $pdo->prepare('INSERT IGNORE INTO seller_profiles (user_id) VALUES (?)')->execute([(int)current_user()['id']]);
                    } catch (Throwable $ignored) {}
                    $_SESSION['user']['is_seller'] = 1;
                }

                $pdo->commit();

                if ($isDraft) {
                    notify_user(
                        (int)current_user()['id'],
                        'system',
                        'Product draft saved',
                        'Your product "' . $title . '" was saved as a draft. You can continue editing it anytime.',
                        '/pages/seller/listings.php',
                        'product_draft_saved',
                        ['in_app']
                    );
                    flash_set('success', 'Product draft saved successfully.');
                } else {
                    // Seller notification is secondary to the database transaction.
                    // A notification/table problem must NEVER make a successfully
                    // created product look like it failed.
                    try {
                        notify_user(
                            (int)current_user()['id'],
                            'system',
                            'Product is now live',
                            'Your product "' . $title . '" was published and is now visible to buyers.',
                            '/pages/seller/listings.php',
                            'product_published',
                            ['in_app']
                        );
                    } catch (Throwable $ignored) {}
                    // Admin notification is informational only — products are no
                    // longer gated behind review, so this is just visibility for
                    // moderation purposes, not an action request.
                    try {
                        $admins = $pdo->query("SELECT id FROM users WHERE role_id IN (SELECT id FROM roles WHERE UPPER(name) IN ('ADMIN','SUPER_ADMIN')) AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($admins as $adminId) {
                            try {
                                notify_user(
                                    (int)$adminId,
                                    'system',
                                    'New product published',
                                    '"' . $title . '" was published by ' . (string)current_user()['full_name'] . '.',
                                    '/pages/admin/listings.php?id=' . $listingId,
                                    'product_published_admin_fyi',
                                    ['in_app']
                                );
                            } catch (Throwable $ignored) {}
                        }
                    } catch (Throwable $ignored) {}
                    flash_set('success', 'Product published successfully. It is now live on the marketplace.');
                }
                // Do not depend on an HTTP Location header here. Show a clear success
                // confirmation first, then navigate with JavaScript. This also works
                // on XAMPP setups where another included file may already have emitted
                // output and PHP cannot send a Location header.
                $submissionSuccess = $isDraft
                    ? 'Product draft saved successfully.'
                    : 'Product published successfully — it\'s live now.';
                $submissionSuccessListingId = $listingId;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                foreach ($uploadedPaths as $path) {
                    if (is_string($path)) @unlink(__DIR__ . '/../' . $path);
                }
                $errors[] = APP_DEBUG ? 'Product could not be saved: ' . $e->getMessage() : 'Product could not be saved. Please try again.';
            }
        }
    }
}

// Keep entered values after validation/database errors so the seller does not
// have to retype the entire product. Uploaded files cannot be repopulated by
// browsers for security reasons, so only text/select fields are remembered.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) {
    remember_old([
        'title','description','category','subcategory','listing_type','price','currency','sku',
        'stock_quantity','low_stock_threshold','condition_state','price_per_day','price_per_week',
        'price_per_month','deposit','rental_terms','country','province','district','sector','area','address',
        'pickup_available','delivery_available'
    ]);
    if (isset($_POST['accepted_payments'])) {
        $_SESSION['_old']['accepted_payments'] = implode(',', (array) $_POST['accepted_payments']);
    }
}

$pageTitle = 'Create Product';
$activePage = 'dashboard';
require_once __DIR__ . '/../includes/header.php';
seller_page_start('Create Product', (int)current_user()['id'], $pdo);
?>

<style>
.product-create{max-width:1080px;margin:0 auto;padding-bottom:36px}
.product-hero{display:flex;justify-content:space-between;gap:24px;align-items:flex-end;margin-bottom:20px}
.product-hero h1{margin:0;font-size:30px;letter-spacing:-.02em;color:var(--text)}
.product-hero p{margin:7px 0 0;color:var(--text-mute);max-width:680px;line-height:1.55}
.product-steps{display:flex;gap:7px;flex-wrap:wrap;margin:18px 0 22px}
.product-step{padding:7px 11px;border:1px solid var(--border);background:var(--bg-card);border-radius:999px;font-size:11px;font-weight:700;color:var(--text-mute)}
.product-step span{display:inline-grid;place-items:center;width:18px;height:18px;margin-right:5px;border-radius:50%;background:var(--bg-soft);color:var(--text-soft)}
.product-card{background:var(--bg-card);border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow-sm);margin-bottom:16px;overflow:hidden}
.product-card__head{padding:17px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;gap:15px;align-items:center}
.product-card__head h2{font-size:15px;margin:0;color:var(--text)}
.product-card__head p{font-size:11.5px;color:var(--text-mute);margin:4px 0 0}
.product-card__body{padding:20px}
.product-grid{display:grid;grid-template-columns:1.35fr .65fr;gap:15px}
.product-grid--equal{grid-template-columns:1fr 1fr}
.product-field--full{grid-column:1/-1}
.product-field label{margin-bottom:7px}
.product-field input,.product-field select,.product-field textarea{background:var(--bg);border-radius:11px}
.product-field textarea{min-height:145px}
.product-help{font-size:11.5px;color:var(--text-mute);margin-top:6px;line-height:1.45}
.product-required{color:var(--accent-500)}
.product-media{border:1.5px dashed var(--border-strong);border-radius:14px;padding:22px;text-align:center;background:var(--bg-soft);cursor:pointer;transition:.2s}
.product-media:hover,.product-media.is-drag{border-color:var(--brand-500);background:var(--brand-50)}
.product-media__icon{width:46px;height:46px;margin:0 auto 9px;border-radius:13px;background:var(--bg-card);display:grid;place-items:center;color:var(--brand-600);border:1px solid var(--border)}
.product-media strong{display:block;font-size:13px;color:var(--text)}
.product-media small{display:block;margin-top:4px;color:var(--text-mute);font-size:11px}
.product-previews{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:12px}
.product-photo-error{margin-top:10px;padding:10px 14px;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;font-size:13px;font-weight:600}
.btn.is-loading{opacity:.7;pointer-events:none;position:relative}
.product-preview{position:relative;aspect-ratio:1;border-radius:10px;overflow:hidden;border:1px solid var(--border);background:var(--bg-soft)}
.product-preview img{width:100%;height:100%;object-fit:cover}
.product-preview__primary{position:absolute;left:5px;bottom:5px;padding:3px 6px;border-radius:6px;background:rgba(0,0,0,.65);color:#fff;font-size:9px;font-weight:700}
.product-badge{display:inline-flex;padding:5px 9px;border-radius:999px;background:var(--brand-50);color:var(--brand-700);font-size:10px;font-weight:800}
.product-success{display:flex;align-items:flex-start;gap:15px;margin:0 0 18px;padding:18px 20px;border:1px solid rgba(13,148,136,.25);background:var(--bg-card);border-radius:16px;box-shadow:var(--shadow-sm)}
.product-success__icon{width:42px;height:42px;flex:0 0 42px;border-radius:50%;display:grid;place-items:center;background:var(--brand-50);color:var(--brand-700);font-size:22px;font-weight:900}
.product-success__content strong{display:block;font-size:16px;color:var(--text)}
.product-success__content p{margin:5px 0 12px;color:var(--text-mute);font-size:12px;line-height:1.5}
.product-success__actions{display:flex;gap:8px;flex-wrap:wrap}
.product-actions{position:sticky;bottom:12px;z-index:10;display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 14px;background:rgba(255,255,255,.94);backdrop-filter:blur(12px);border:1px solid var(--border);border-radius:15px;box-shadow:var(--shadow-md)}
.product-actions__hint{font-size:11px;color:var(--text-mute)}
.product-actions__buttons{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.product-actions button{cursor:pointer}
.product-rental{padding:14px;border-radius:12px;background:var(--bg-soft);border:1px solid var(--border);margin-top:4px}
.product-attrs{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.product-attrs .form-group{margin:0}
.product-check{display:flex;align-items:center;gap:8px;padding:11px 12px;border:1px solid var(--border);border-radius:11px;background:var(--bg)}
.product-check input{width:auto}
.product-check-row{display:flex;flex-wrap:wrap;gap:10px}
.product-check-row .product-check{cursor:pointer;font-size:13.5px;flex:1 1 170px}
@media(max-width:760px){.product-hero{align-items:flex-start;flex-direction:column}.product-grid,.product-grid--equal,.product-attrs{grid-template-columns:1fr}.product-field--full{grid-column:auto}.product-previews{grid-template-columns:repeat(3,1fr)}.product-actions{position:static;flex-direction:column;align-items:stretch}.product-actions__buttons{justify-content:stretch}.product-actions__buttons .btn{flex:1}}
@media(max-width:480px){.product-previews{grid-template-columns:repeat(2,1fr)}.product-card__body{padding:15px}}
</style>

<div class="product-create">
    <div class="product-hero">
        <div>
            <div class="product-badge">SELLER CENTER · PRODUCT</div>
            <h1>Create a Product</h1>
            <p>Give buyers the information they need to trust your product. Complete the important details, add clear photos, then publish it live.</p>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="flash flash--error" role="alert">
            <strong>Please check the following:</strong>
            <ul style="margin:7px 0 0;padding-left:18px;line-height:1.6;"><?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    <?php if ($submissionSuccess): ?>
        <div class="product-success" role="status" aria-live="polite">
            <div class="product-success__icon">✓</div>
            <div class="product-success__content">
                <strong><?php echo e($submissionSuccess); ?></strong>
                <p><?php echo $isDraft ? 'You can continue editing it from My Products.' : 'Your product is live and visible to buyers right now.'; ?></p>
                <div class="product-success__actions">
                    <a class="btn btn--primary" href="<?php echo APP_URL; ?>/pages/seller/listings.php">View My Products</a>
                    <a class="btn btn--outline" href="<?php echo APP_URL; ?>/pages/sell.php">Create Another Product</a>
                    <a class="btn btn--outline" href="<?php echo rtrim(APP_URL, '/'); ?>/">Back to Homepage</a>
                </div>
            </div>
        </div>
        <script>
        (function(){
            setTimeout(function(){ window.location.replace(<?php echo json_encode(rtrim(APP_URL, '/') . '/'); ?>); }, 2800);
        })();
        </script>
    <?php endif; ?>

    <div class="product-steps" aria-label="Product creation steps">
        <div class="product-step"><span>1</span>Product info</div>
        <div class="product-step"><span>2</span>Price & stock</div>
        <div class="product-step"><span>3</span>Details</div>
        <div class="product-step"><span>4</span>Photos</div>
        <div class="product-step"><span>5</span>Location</div>
        <div class="product-step"><span>6</span>Review</div>
    </div>

    <form id="productForm" method="post" action="<?php echo APP_URL; ?>/pages/sell.php" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="form_token" value="<?php echo e($sellFormToken); ?>">
        <input type="hidden" name="form_action" id="formAction" value="publish">

        <section class="product-card">
            <div class="product-card__head"><div><h2>Product information</h2><p>Start with the basic information buyers will see first.</p></div><span class="product-badge">Required</span></div>
            <div class="product-card__body">
                <div class="product-grid">
                    <div class="product-field product-field--full form-group">
                        <label for="title">Product name <span class="product-required">*</span></label>
                        <input type="text" id="title" name="title" maxlength="180" required value="<?php echo old('title'); ?>" placeholder="e.g. Toyota Corolla 2018">
                        <div class="product-help">Use a clear name buyers can search for. Avoid ALL CAPS and unnecessary symbols.</div>
                    </div>
                    <div class="product-field form-group">
                        <label for="category">Category <span class="product-required">*</span></label>
                        <select id="category" name="category" required>
                            <option value="">Select a category</option>
                            <?php foreach ($cats as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>" data-slug="<?php echo e($c['slug']); ?>" <?php echo (int)old('category') === (int)$c['id'] ? 'selected' : ''; ?>><?php echo e(t_category($c['name_key'] ?? null, $c['name'])); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="product-field form-group">
                        <label for="subcategory">Subcategory</label>
                        <select id="subcategory" name="subcategory"><option value="">Select after choosing category</option></select>
                    </div>
                    <div class="product-field form-group">
                        <label for="listing_type">Selling method <span class="product-required">*</span></label>
                        <select id="listing_type" name="listing_type">
                            <option value="sell" <?php echo old('listing_type') !== 'rent' ? 'selected' : ''; ?>>Sell</option>
                            <option value="rent" <?php echo old('listing_type') === 'rent' ? 'selected' : ''; ?>>Rent</option>
                        </select>
                    </div>
                    <div class="product-field form-group">
                        <label for="condition_state">Condition</label>
                        <select id="condition_state" name="condition_state">
                            <option value="new" <?php echo old('condition_state') === 'new' ? 'selected' : ''; ?>>New</option>
                            <option value="used" <?php echo old('condition_state') === 'used' || !old('condition_state') ? 'selected' : ''; ?>>Used</option>
                            <option value="refurbished" <?php echo old('condition_state') === 'refurbished' ? 'selected' : ''; ?>>Refurbished</option>
                            <option value="for-parts" <?php echo old('condition_state') === 'for-parts' ? 'selected' : ''; ?>>For parts / repair</option>
                        </select>
                    </div>
                </div>
            </div>
        </section>

        <section class="product-card">
            <div class="product-card__head"><div><h2>Price & inventory</h2><p>Control how much the product costs and how many units are available.</p></div></div>
            <div class="product-card__body">
                <div class="product-grid--equal product-grid">
                    <div class="product-field form-group"><label for="price">Price <span class="product-required">*</span></label><input type="number" id="price" name="price" min="0" step="0.01" value="<?php echo old('price'); ?>" placeholder="9500000"></div>
                    <div class="product-field form-group"><label for="currency">Currency</label><select id="currency" name="currency"><option value="RWF" <?php echo strtoupper(old('currency') ?: DEFAULT_CURRENCY) === 'RWF' ? 'selected' : ''; ?>>RWF — Rwandan Franc</option><option value="USD" <?php echo strtoupper(old('currency')) === 'USD' ? 'selected' : ''; ?>>USD — US Dollar</option></select></div>
                    <div class="product-field form-group"><label for="sku">SKU <span style="font-weight:400;color:var(--text-mute)">(optional)</span></label><input type="text" id="sku" name="sku" maxlength="60" value="<?php echo old('sku'); ?>" placeholder="PHONE-001"></div>
                    <div class="product-field form-group"><label for="stock_quantity">Stock quantity</label><input type="number" id="stock_quantity" name="stock_quantity" min="0" step="1" value="<?php echo old('stock_quantity') !== '' ? old('stock_quantity') : '1'; ?>" placeholder="1"><div class="product-help">For products for sale. When stock reaches 0, the product becomes out of stock.</div></div>
                    <div class="product-field form-group"><label for="low_stock_threshold">Low-stock alert at</label><input type="number" id="low_stock_threshold" name="low_stock_threshold" min="0" step="1" value="<?php echo old('low_stock_threshold') !== '' ? old('low_stock_threshold') : '5'; ?>"><div class="product-help">Used by the Seller Center to warn you before stock runs out.</div></div>
                </div>
                <div id="rentalFields" class="product-rental" style="display:none">
                    <div style="font-weight:700;font-size:13px;margin-bottom:12px">Rental pricing</div>
                    <div class="product-grid--equal product-grid">
                        <div class="product-field form-group"><label>Price per day</label><input type="number" name="price_per_day" min="0" step="0.01" value="<?php echo old('price_per_day'); ?>" placeholder="25000"></div>
                        <div class="product-field form-group"><label>Price per week</label><input type="number" name="price_per_week" min="0" step="0.01" value="<?php echo old('price_per_week'); ?>" placeholder="150000"></div>
                        <div class="product-field form-group"><label>Price per month</label><input type="number" name="price_per_month" min="0" step="0.01" value="<?php echo old('price_per_month'); ?>" placeholder="500000"></div>
                        <div class="product-field form-group"><label>Security deposit</label><input type="number" name="deposit" min="0" step="0.01" value="<?php echo old('deposit'); ?>" placeholder="200000"></div>
                        <div class="product-field product-field--full form-group"><label>Rental terms</label><textarea name="rental_terms" rows="3" placeholder="Minimum rental period, deposit rules, return conditions..."><?php echo old('rental_terms'); ?></textarea></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="product-card">
            <div class="product-card__head"><div><h2>Product details</h2><p>Extra details help buyers understand exactly what they are buying.</p></div><span style="font-size:11px;color:var(--text-mute)">Category-aware</span></div>
            <div class="product-card__body">
                <div id="extraAttrs" class="product-attrs"></div>
                <div id="genericAttrs" class="product-attrs">
                    <div class="product-field form-group"><label>Brand</label><input type="text" name="attr_value_generic_brand" placeholder="e.g. Samsung, Toyota, Nike"></div>
                    <input type="hidden" name="attr_key_generic_brand" value="brand">
                    <div class="product-field form-group"><label>Model / version</label><input type="text" name="attr_value_generic_model" placeholder="e.g. Galaxy S24, Corolla XLE"></div>
                    <input type="hidden" name="attr_key_generic_model" value="model">
                    <div class="product-field form-group"><label>Color</label><input type="text" name="attr_value_generic_color" placeholder="e.g. Black, White"></div>
                    <input type="hidden" name="attr_key_generic_color" value="color">
                    <div class="product-field form-group"><label>Size</label><input type="text" name="attr_value_generic_size" placeholder="e.g. XL, 42, 128GB"></div>
                    <input type="hidden" name="attr_key_generic_size" value="size">
                </div>
                <div class="product-field product-field--full form-group" style="margin-top:16px">
                    <label for="description">Description <span class="product-required">*</span></label>
                    <textarea id="description" name="description" maxlength="10000" placeholder="Describe condition, features, what's included, defects, warranty and anything buyers should know..."><?php echo old('description'); ?></textarea>
                    <div class="product-help"><span id="descriptionCount">0</span>/10,000 characters</div>
                </div>
            </div>
        </section>

        <section class="product-card">
            <div class="product-card__head"><div><h2>Product photos</h2><p>Use clear, real photos. The first photo becomes the main product image.</p></div><span class="product-badge">Up to 8</span></div>
            <div class="product-card__body">
                <label class="product-media" id="dropZone" for="images">
                    <div class="product-media__icon"><svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-4.5-4.5L7 20"/></svg></div>
                    <strong>Click to choose photos or drag them here</strong>
                    <small>JPG, PNG or WebP · max 5MB each · up to 8 photos</small>
                </label>
                <input type="file" id="images" name="images[]" accept="image/jpeg,image/png,image/webp" multiple aria-required="true" class="sr-only-file-input" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;">
                <div id="photoError" class="product-photo-error" role="alert" style="display:none"></div>
                <div id="photoPreview" class="product-previews"></div>
            </div>
        </section>

        <section class="product-card">
            <div class="product-card__head"><div><h2>Location & delivery context</h2><p>Help buyers understand where the product is available.</p></div></div>
            <div class="product-card__body">
                <div class="product-grid--equal product-grid">
                    <div class="product-field form-group"><label for="country">Country</label><input id="country" name="country" value="<?php echo old('country') ?: 'Rwanda'; ?>" placeholder="Rwanda"></div>
                    <div class="product-field form-group"><label for="province">Province / Region</label><input id="province" name="province" value="<?php echo old('province'); ?>" placeholder="Kigali City / Eastern Province"></div>
                    <div class="product-field form-group"><label for="district">District</label><input id="district" name="district" value="<?php echo old('district'); ?>" placeholder="Kicukiro"></div>
                    <div class="product-field form-group"><label for="sector">Sector</label><input id="sector" name="sector" value="<?php echo old('sector'); ?>" placeholder="Kanombe"></div>
                    <div class="product-field form-group"><label for="area">Area / Cell</label><input id="area" name="area" value="<?php echo old('area'); ?>" placeholder="Kabeza"></div>
                    <div class="product-field form-group"><label for="address">Pickup / location note</label><input id="address" name="address" value="<?php echo old('address'); ?>" placeholder="Near a landmark or pickup point"></div>
                </div>
            </div>
        </section>

        <section class="product-card">
            <div class="product-card__head"><div><h2>Delivery &amp; payment</h2><p>Let buyers know how they can get the item and pay for it.</p></div></div>
            <div class="product-card__body">
                <div class="product-field product-field--full form-group">
                    <label>How can buyers get this?</label>
                    <div class="product-check-row">
                        <label class="product-check"><input type="checkbox" name="pickup_available" value="1" <?php echo old('pickup_available', '1') ? 'checked' : ''; ?>> Available for pickup</label>
                        <label class="product-check"><input type="checkbox" name="delivery_available" value="1" <?php echo old('delivery_available') ? 'checked' : ''; ?>> I can deliver to the buyer</label>
                    </div>
                </div>
                <div class="product-field product-field--full form-group">
                    <label>Accepted payment methods</label>
                    <div class="product-check-row">
                        <?php $oldPayments = explode(',', old('accepted_payments', 'cash')); ?>
                        <label class="product-check"><input type="checkbox" name="accepted_payments[]" value="cash" <?php echo in_array('cash', $oldPayments, true) ? 'checked' : ''; ?>> Cash</label>
                        <label class="product-check"><input type="checkbox" name="accepted_payments[]" value="momo" <?php echo in_array('momo', $oldPayments, true) ? 'checked' : ''; ?>> Mobile Money</label>
                        <label class="product-check"><input type="checkbox" name="accepted_payments[]" value="bank" <?php echo in_array('bank', $oldPayments, true) ? 'checked' : ''; ?>> Bank Transfer</label>
                    </div>
                </div>
            </div>
        </section>

        <div class="product-actions">
            <div class="product-actions__hint"><strong>Before submitting:</strong> check price, stock, category, location and photos.</div>
            <div class="product-actions__buttons">
                <button type="submit" id="btnSaveDraft" class="btn btn--outline btn--lg" onclick="document.getElementById('formAction').value='draft'">Save Draft</button>
                <button type="submit" id="btnPublish" class="btn btn--primary btn--lg" onclick="document.getElementById('formAction').value='publish'">Publish Product</button>
            </div>
        </div>
    </form>
</div>

<script>
(function(){
    const subcats = <?php echo json_encode($subcatsByCat, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
    const category = document.getElementById('category');
    const subcategory = document.getElementById('subcategory');
    const listingType = document.getElementById('listing_type');
    const rental = document.getElementById('rentalFields');
    const images = document.getElementById('images');
    const preview = document.getElementById('photoPreview');
    const dropZone = document.getElementById('dropZone');
    const desc = document.getElementById('description');
    const descCount = document.getElementById('descriptionCount');
    const extra = document.getElementById('extraAttrs');
    const generic = document.getElementById('genericAttrs');
    const oldSub = <?php echo json_encode((string)old('subcategory')); ?>;

    function updateSubcats(){
        const id = category.value;
        const rows = subcats[id] || [];
        subcategory.innerHTML = '<option value="">' + (rows.length ? 'Select a subcategory' : 'No subcategories available') + '</option>';
        rows.forEach(function(s){
            const o=document.createElement('option'); o.value=s.id; o.textContent=s.name;
            if(String(s.id)===String(oldSub)) o.selected=true;
            subcategory.appendChild(o);
        });
        renderCategoryAttrs();
    }
    function hiddenKey(key,index){ return '<input type="hidden" name="attr_key_'+index+'" value="'+key+'">'; }
    function field(key,label,placeholder,type){
        return '<div class="product-field form-group"><label>'+label+'</label><input type="'+(type||'text')+'" name="attr_value_'+key+'" placeholder="'+placeholder+'">'+hiddenKey(key,field.idx++)+'</div>';
    }
    function renderCategoryAttrs(){
        const selected=category.options[category.selectedIndex];
        const slug=selected ? selected.dataset.slug : '';
        let attrs=[]; field.idx=100;
        if(slug==='vehicles') attrs=[['make','Make','e.g. Toyota'],['model','Model','e.g. Corolla'],['year','Year','e.g. 2018','number'],['mileage','Mileage (km)','e.g. 95000','number'],['fuel','Fuel type','e.g. Petrol'],['transmission','Transmission','e.g. Automatic']];
        else if(slug==='homes-land') attrs=[['property_type','Property type','e.g. House / Land'],['bedrooms','Bedrooms','e.g. 3','number'],['bathrooms','Bathrooms','e.g. 2','number'],['area_sqm','Area (sqm)','e.g. 180','number'],['furnished','Furnished','Yes / No'],['parking','Parking','e.g. 2 cars']];
        else if(slug==='phones-electronics') attrs=[['storage','Storage','e.g. 128GB'],['ram','RAM','e.g. 8GB'],['warranty','Warranty','e.g. 6 months']];
        else if(slug==='computers-machines') attrs=[['processor','Processor','e.g. Core i7'],['ram','RAM','e.g. 16GB'],['storage','Storage','e.g. 512GB SSD'],['warranty','Warranty','e.g. 1 year']];
        else if(slug==='agriculture') attrs=[['breed','Breed / variety','e.g. Friesian'],['age','Age','e.g. 2 years'],['weight','Weight','e.g. 350kg']];
        extra.innerHTML='';
        attrs.forEach(function(a){ extra.insertAdjacentHTML('beforeend',field(a[0],a[1],a[2],a[3])); });
        extra.style.display=attrs.length?'grid':'none';
        generic.style.display='grid';
    }
    function toggleRental(){ rental.style.display=listingType.value==='rent'?'block':'none'; }
    function updateCount(){ descCount.textContent=desc.value.length.toLocaleString(); }
    function renderFiles(fileList){
        preview.innerHTML='';
        Array.from(fileList).slice(0,8).forEach(function(file,i){
            if(!file.type.startsWith('image/')) return;
            const url=URL.createObjectURL(file);
            const box=document.createElement('div'); box.className='product-preview';
            box.innerHTML='<img src="'+url+'" alt="Product photo '+(i+1)+'"><span class="product-preview__primary">'+(i===0?'MAIN PHOTO':'PHOTO '+(i+1))+'</span>';
            preview.appendChild(box);
        });
    }
    category.addEventListener('change',updateSubcats); listingType.addEventListener('change',toggleRental); desc.addEventListener('input',updateCount); images.addEventListener('change',function(){renderFiles(images.files); clearPhotoError();});
    ['dragenter','dragover'].forEach(function(ev){dropZone.addEventListener(ev,function(e){e.preventDefault();dropZone.classList.add('is-drag')})});
    ['dragleave','drop'].forEach(function(ev){dropZone.addEventListener(ev,function(e){e.preventDefault();dropZone.classList.remove('is-drag')})});
    dropZone.addEventListener('drop',function(e){ if(e.dataTransfer.files.length){ try { images.files=e.dataTransfer.files; } catch(_){} renderFiles(e.dataTransfer.files); clearPhotoError(); }});
    updateSubcats(); toggleRental(); updateCount();

    // ------------------------------------------------------------------
    // Submission handling.
    // ------------------------------------------------------------------
    // The photo <input type="file" required> used to be hidden with
    // opacity:0 + pointer-events:none + a 1px box. Native browser
    // constraint-validation ("Please select a file") anchors its bubble to
    // the target element's bounding box — on a 1x1px invisible element that
    // bubble can end up effectively invisible or mispositioned. In that
    // situation clicking "Submit for Review" without a photo did nothing
    // visible: the browser silently blocked the submit and showed no error
    // the seller could see. That was the root cause of "nothing happens
    // when I click Add Product". We now validate explicitly in JS (with a
    // clearly visible message) instead of relying on that native bubble.
    var photoError = document.getElementById('photoError');
    var form = document.getElementById('productForm');
    var btnDraft = document.getElementById('btnSaveDraft');
    var btnPublish = document.getElementById('btnPublish');

    function clearPhotoError(){ photoError.style.display='none'; photoError.textContent=''; }
    function showPhotoError(msg){
        photoError.textContent = msg;
        photoError.style.display = 'block';
        dropZone.scrollIntoView({behavior:'smooth', block:'center'});
    }

    var isSubmitting = false;
    form.addEventListener('submit', function(e){
        var action = document.getElementById('formAction').value;
        var isDraftSubmit = action === 'draft';

        // Photos are required to publish a product (drafts may be saved
        // without one) — validated here explicitly and visibly, and also
        // re-validated server-side in pages/sell.php regardless.
        if (!isDraftSubmit && images.files.length === 0) {
            e.preventDefault();
            showPhotoError('Please add at least one product photo before publishing.');
            return;
        }
        if (images.files.length > 8) {
            e.preventDefault();
            showPhotoError('You can upload up to 8 product photos.');
            return;
        }

        // Prevent duplicate/double submissions (e.g. double-click, slow
        // network) and give clear visual feedback that the form is working
        // rather than appearing "stuck" with no response.
        if (isSubmitting) { e.preventDefault(); return; }
        isSubmitting = true;
        clearPhotoError();
        [btnDraft, btnPublish].forEach(function(b){ b.disabled = true; b.classList.add('is-loading'); });
        var activeBtn = isDraftSubmit ? btnDraft : btnPublish;
        activeBtn.textContent = isDraftSubmit ? 'Saving…' : 'Submitting…';
    });
})();
</script>
<?php seller_page_end(); ?>
<?php require_once __DIR__ . '/../includes/footer.php';
