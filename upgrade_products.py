from pathlib import Path
root=Path('/tmp/msnext')

# Migration
(root/'sql/product_management_upgrade.sql').write_text(r'''-- ISOKO RYACU — Product Management Upgrade
-- Safe to run after seller_upgrade.sql. Uses existing listings as the product entity.
SET NAMES utf8mb4;

ALTER TABLE listings
    ADD COLUMN IF NOT EXISTS published_at TIMESTAMP NULL AFTER is_published,
    ADD COLUMN IF NOT EXISTS archived_at TIMESTAMP NULL AFTER published_at;

ALTER TABLE listings
    MODIFY COLUMN status ENUM('pending','active','rejected','expired','draft','archived') NOT NULL DEFAULT 'pending';

CREATE INDEX IF NOT EXISTS idx_listings_catalog ON listings(status, is_published, availability, is_featured, created_at);
CREATE INDEX IF NOT EXISTS idx_listings_seller_state ON listings(seller_id, status, is_published, availability);

-- Existing active products remain visible. New products still require admin approval.
UPDATE listings SET is_published = 1, published_at = COALESCE(published_at, created_at)
WHERE status = 'active' AND is_published = 1 AND published_at IS NULL;
''', encoding='utf-8')

# Sell page: add SKU/stock fields and persist them
p=root/'pages/sell.php'; s=p.read_text()
s=s.replace("$price         = (float)($_POST['price'] ?? 0);\n        $currency", "$price         = (float)($_POST['price'] ?? 0);\n        $sku           = trim($_POST['sku'] ?? '');\n        $stockQuantity = max(0, (int)($_POST['stock_quantity'] ?? 1));\n        $currency")
s=s.replace("if ($price < 0)   $errors[] = t('errors.price_negative');", "if ($price < 0)   $errors[] = t('errors.price_negative');\n        if (mb_strlen($sku) > 60) $errors[] = 'SKU must be 60 characters or fewer.';\n        if ($listingType === 'sell' && $stockQuantity < 1) $errors[] = 'Stock quantity must be at least 1 for products for sale.';")
s=s.replace("(seller_id, category_id, subcategory_id, title, slug, description,\n                     listing_type, price, currency,", "(seller_id, category_id, subcategory_id, title, slug, description,\n                     listing_type, price, currency, sku, stock_quantity, is_published,")
s=s.replace("VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \"pending\")", "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
old="""                current_user()['id'], $categoryId, $subcategoryId,
                $title, $slug, $description,
                $listingType, $price, $currency,
                $pricePerDay, $pricePerWeek, $pricePerMonth, $deposit, $rentalTerms,
                $location, $country ?: null, $province ?: null, $district ?: null, $area ?: null,
                $condition, $availability,
            ]);"""
new="""                current_user()['id'], $categoryId, $subcategoryId,
                $title, $slug, $description,
                $listingType, $price, $currency, $sku ?: null, $listingType === 'sell' ? $stockQuantity : 0, 1,
                $pricePerDay, $pricePerWeek, $pricePerMonth, $deposit, $rentalTerms,
                $location, $country ?: null, $province ?: null, $district ?: null, $area ?: null,
                $condition, $availability,
            ]);"""
if old not in s: print('sell execute block not found')
else: s=s.replace(old,new)
needle='''        <div class="form-row">\n            <div class="form-group">\n                <label for="price"><?php echo e(t('form.price')); ?> (<?php echo e(t('form.currency')); ?>)</label>\n                <input type="number" id="price" name="price" min="0" step="any"\n                       value="<?php echo old('price'); ?>" placeholder="9500000">\n            </div>\n        </div>'''
# actual form-row has listing type + price, so insert after its closing before rental comment
marker='''        </div>\n\n        <!-- Rental fields (hidden unless listing_type === 'rent') -->'''
insert='''        </div>\n\n        <!-- Product inventory -->\n        <div class="form-row">\n            <div class="form-group">\n                <label for="sku">SKU <span class="text-mute">(optional)</span></label>\n                <input type="text" id="sku" name="sku" maxlength="60" value="<?php echo old('sku'); ?>" placeholder="e.g. PHONE-001">\n            </div>\n            <div class="form-group">\n                <label for="stock_quantity">Stock quantity</label>\n                <input type="number" id="stock_quantity" name="stock_quantity" min="1" step="1" value="<?php echo old('stock_quantity') ?: '1'; ?>" placeholder="1">\n                <div class="form-hint">For products for sale. Rentals can manage availability separately.</div>\n            </div>\n        </div>\n\n        <!-- Rental fields (hidden unless listing_type === 'rent') -->'''
if marker not in s: print('sell marker not found')
else: s=s.replace(marker,insert,1)
p.write_text(s)

# Seller listings: richer product management
p=root/'pages/seller/listings.php'; s=p.read_text()
s=s.replace('My Listings', 'My Products', 1).replace('New Listing', 'New Product')
s=s.replace("l.views_count, l.favorites_count, l.created_at, l.location, l.description,", "l.views_count, l.favorites_count, l.created_at, l.location, l.description,\n            l.sku, l.stock_quantity, l.reserved_quantity, l.low_stock_threshold, l.is_published, l.published_at,")
s=s.replace("$activeListings = count(array_filter($items, fn($r) => $r['status'] === 'active' && $r['availability'] === 'available'));", "$activeListings = count(array_filter($items, fn($r) => $r['status'] === 'active' && $r['availability'] === 'available' && (int)($r['is_published'] ?? 1) === 1));\n$draftProducts = count(array_filter($items, fn($r) => $r['status'] === 'draft'));\n$archivedProducts = count(array_filter($items, fn($r) => $r['status'] === 'archived'));\n$outOfStock = count(array_filter($items, fn($r) => $r['listing_type'] === 'sell' && (int)($r['stock_quantity'] ?? 0) <= (int)($r['reserved_quantity'] ?? 0)));" )
s=s.replace('Manage your products here.', 'Manage your products, inventory, visibility and sales status here.')
s=s.replace('''<?php echo $totalListings > 0 ? "{$totalListings} listing(s) • {$activeListings} active" : 'Manage your products, inventory, visibility and sales status here.'; ?>''', '''<?php echo $totalListings > 0 ? "{$totalListings} product(s) • {$activeListings} active • {$outOfStock} out of stock" : 'Manage your products, inventory, visibility and sales status here.'; ?>''')
# Insert inventory/status line after price
s=s.replace('''<div style="font-size:15px;font-weight:800;color:var(--brand-600);"><?php echo e(format_price($r['price'], $r['currency'])); ?></div>''', '''<div style="font-size:15px;font-weight:800;color:var(--brand-600);"><?php echo e(format_price($r['price'], $r['currency'])); ?></div>\n                        <div style="display:flex;gap:6px;flex-wrap:wrap;font-size:11px;color:var(--text-mute);">\n                            <?php if (!empty($r['sku'])): ?><span>SKU <?php echo e($r['sku']); ?></span><?php endif; ?>\n                            <?php if ($r['listing_type'] === 'sell'): ?><span>Stock <?php echo max(0, (int)$r['stock_quantity'] - (int)$r['reserved_quantity']); ?></span><?php endif; ?>\n                            <?php if (!(int)($r['is_published'] ?? 1)): ?><span class="udash__pill udash__pill--mute">Unpublished</span><?php endif; ?>\n                        </div>''')
# Status color archived/draft
s=s.replace("$statusColor = $r['status'] === 'active' ? 'good' : ($r['status'] === 'pending' ? 'warn' : 'bad');", "$statusColor = $r['status'] === 'active' ? 'good' : (in_array($r['status'], ['pending','draft'], true) ? 'warn' : ($r['status'] === 'archived' ? 'mute' : 'bad'));")
# Add Edit and visibility/archive actions before existing View button
old='''<a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$r['id']; ?>" class="btn btn--outline btn--sm" title="View">View</a>'''
new='''<a href="<?php echo APP_URL; ?>/pages/listing-details.php?id=<?php echo (int)$r['id']; ?>" class="btn btn--outline btn--sm" title="View">View</a>\n                            <a href="<?php echo APP_URL; ?>/pages/seller/edit-product.php?id=<?php echo (int)$r['id']; ?>" class="btn btn--outline btn--sm">Edit</a>\n                            <?php if ($r['status'] === 'active'): ?>\n                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/listings-action/index.php" style="display:inline;"><?php echo csrf_field(); ?><input type="hidden" name="listing_id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="action" value="<?php echo (int)($r['is_published'] ?? 1) ? 'unpublish' : 'publish'; ?>"><button type="submit" class="btn btn--outline btn--sm"><?php echo (int)($r['is_published'] ?? 1) ? 'Unpublish' : 'Publish'; ?></button></form>\n                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/listings-action/index.php" style="display:inline;"><?php echo csrf_field(); ?><input type="hidden" name="listing_id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="action" value="archive"><button type="submit" class="btn btn--outline btn--sm">Archive</button></form>\n                            <?php elseif ($r['status'] === 'archived'): ?>\n                                <form method="post" action="<?php echo APP_URL; ?>/api/v1/listings-action/index.php" style="display:inline;"><?php echo csrf_field(); ?><input type="hidden" name="listing_id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="action" value="restore"><button type="submit" class="btn btn--outline btn--sm">Restore</button></form>\n                            <?php endif; ?>'''
if old not in s: print('seller view button not found')
else: s=s.replace(old,new,1)
p.write_text(s)

# API listing actions: fetch title and add product actions
p=root/'api/v1/listings-action/index.php'; s=p.read_text()
s=s.replace("SELECT seller_id, status FROM listings", "SELECT seller_id, status, title FROM listings")
insert='''\n    case 'unpublish':\n        if (!is_logged_in() || current_user()['id'] != $l['seller_id']) {\n            $ok = false; $message = t('errors.not_owner');\n            break;\n        }\n        db()->prepare('UPDATE listings SET is_published = 0 WHERE id = ?')->execute([$listingId]);\n        $message = 'Product unpublished. It is hidden from the public marketplace.';\n        break;\n\n    case 'publish':\n        if (!is_logged_in() || current_user()['id'] != $l['seller_id']) {\n            $ok = false; $message = t('errors.not_owner');\n            break;\n        }\n        if ($l['status'] !== 'active') { $ok = false; $message = 'Only an approved active product can be published.'; break; }\n        db()->prepare('UPDATE listings SET is_published = 1, published_at = COALESCE(published_at, NOW()) WHERE id = ?')->execute([$listingId]);\n        $message = 'Product published.';\n        break;\n\n    case 'archive':\n        if (!is_logged_in() || current_user()['id'] != $l['seller_id']) {\n            $ok = false; $message = t('errors.not_owner');\n            break;\n        }\n        db()->prepare('UPDATE listings SET status = "archived", is_published = 0, archived_at = NOW() WHERE id = ?')->execute([$listingId]);\n        $message = 'Product archived.';\n        break;\n\n    case 'restore':\n        if (!is_logged_in() || current_user()['id'] != $l['seller_id']) {\n            $ok = false; $message = t('errors.not_owner');\n            break;\n        }\n        db()->prepare('UPDATE listings SET status = "active", is_published = 1, archived_at = NULL, published_at = COALESCE(published_at, NOW()) WHERE id = ?')->execute([$listingId]);\n        $message = 'Product restored and published.';\n        break;\n'''
s=s.replace("    case 'mark_sold':", insert+"\n    case 'mark_sold':")
p.write_text(s)

# Home: visibility + featured products
p=root/'pages/home.php'; s=p.read_text()
s=s.replace('WHERE l.status = "active" AND l.availability = "available"', 'WHERE l.status = "active" AND l.is_published = 1 AND l.availability = "available"')
# All relevant simple query occurrences in home
s=s.replace("WHERE l.status = 'active' AND l.availability = 'available'", "WHERE l.status = 'active' AND l.is_published = 1 AND l.availability = 'available'")
# Add featured data after categories
s=s.replace("$categories = fetch_categories_home(10);\n$trending", "$categories = fetch_categories_home(10);\n$featuredProducts = fetch_listings_home(null, 8, 'is_featured DESC, created_at DESC', true);\n$trending")
# Insert section before trending
marker='''<!-- =================================================================\n     3. TRENDING LISTINGS ("Ibiri Kurebwa Cyane")\n     ================================================================= -->'''
section='''<!-- =================================================================\n     3. FEATURED PRODUCTS\n     ================================================================= -->\n<?php if ($featuredProducts): ?>\n<section class="section section--compact">\n    <div class="container">\n        <div class="section__head section__head--compact">\n            <h2 class="section__title section__title--compact">Featured Products</h2>\n            <a class="section__link" href="<?php echo APP_URL; ?>/pages/explore.php?sort=featured">View all →</a>\n        </div>\n        <?php echo render_listing_grid_home($featuredProducts); ?>\n    </div>\n</section>\n<?php endif; ?>\n\n<!-- =================================================================\n     4. TRENDING LISTINGS ("Ibiri Kurebwa Cyane")\n     ================================================================= -->'''
if marker not in s: print('home marker not found')
else: s=s.replace(marker,section,1)
p.write_text(s)

# Create seller edit product page
edit=root/'pages/seller/edit-product.php'
edit.write_text(r'''<?php
/** Seller product editor — updates the existing listings product entity. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/seller_sidebar.php';
require_login();
$uid=(int)current_user()['id']; $id=(int)($_GET['id']??$_POST['id']??0);
if(!$id){ redirect(APP_URL.'/pages/seller/listings.php'); }
$stmt=db()->prepare('SELECT * FROM listings WHERE id=? AND seller_id=? LIMIT 1'); $stmt->execute([$id,$uid]); $product=$stmt->fetch();
if(!$product){ http_response_code(404); exit('Product not found.'); }
$errors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_check()) $errors[]='Invalid security token. Please try again.';
    else {
        $title=trim($_POST['title']??''); $description=trim($_POST['description']??'');
        $price=(float)($_POST['price']??0); $sku=trim($_POST['sku']??''); $stock=max(0,(int)($_POST['stock_quantity']??0));
        $condition=$_POST['condition_state']??$product['condition_state'];
        if(mb_strlen($title)<5) $errors[]='Product title must be at least 5 characters.';
        if(mb_strlen($title)>180) $errors[]='Product title is too long.';
        if(mb_strlen($description)<10) $errors[]='Description must be at least 10 characters.';
        if($price<0) $errors[]='Price cannot be negative.';
        if(mb_strlen($sku)>60) $errors[]='SKU must be 60 characters or fewer.';
        if($product['listing_type']==='sell' && $stock<0) $errors[]='Invalid stock quantity.';
        if(!in_array($condition,['new','used','refurbished','for-parts'],true)) $condition='used';
        if(!$errors){
            $stmt=db()->prepare('UPDATE listings SET title=?, description=?, price=?, sku=?, stock_quantity=?, condition_state=?, updated_at=CURRENT_TIMESTAMP WHERE id=? AND seller_id=?');
            $stmt->execute([$title,$description,$price,$sku?:null,$stock,$condition,$id,$uid]);
            flash_set('success','Product updated successfully.'); redirect(APP_URL.'/pages/seller/listings.php');
        }
        $product=array_merge($product,['title'=>$title,'description'=>$description,'price'=>$price,'sku'=>$sku,'stock_quantity'=>$stock,'condition_state'=>$condition]);
    }
}
$pageTitle='Edit Product'; $activePage='listings'; require_once __DIR__.'/../../includes/header.php';
seller_page_start('Edit Product',$uid,db());
?>
<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px"><div><h1 style="font-size:22px;margin:0;font-weight:800">Edit Product</h1><p style="margin:4px 0 0;color:var(--text-mute);font-size:13px">Update product information, price and inventory.</p></div><a class="btn btn--outline" href="<?php echo APP_URL; ?>/pages/seller/listings.php">Back to Products</a></div>
<?php if($errors): ?><div class="card" style="padding:12px 14px;margin-bottom:14px;border-color:#f0b4b4;color:#9b2c2c;background:#fff8f8"><strong>Please fix:</strong><ul style="margin:6px 0 0 18px"><?php foreach($errors as $e): ?><li><?php echo e($e); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" class="card" style="padding:20px;max-width:900px"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo $id; ?>">
<div class="form-group"><label>Product title</label><input type="text" name="title" maxlength="180" required value="<?php echo e($product['title']); ?>"></div>
<div class="form-row"><div class="form-group"><label>Price</label><input type="number" name="price" min="0" step="any" required value="<?php echo e($product['price']); ?>"></div><div class="form-group"><label>SKU</label><input type="text" name="sku" maxlength="60" value="<?php echo e($product['sku']??''); ?>" placeholder="Optional SKU"></div></div>
<div class="form-row"><div class="form-group"><label>Stock quantity</label><input type="number" name="stock_quantity" min="0" step="1" value="<?php echo (int)($product['stock_quantity']??0); ?>"></div><div class="form-group"><label>Condition</label><select name="condition_state"><?php foreach(['new'=>'New','used'=>'Used','refurbished'=>'Refurbished','for-parts'=>'For parts'] as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $product['condition_state']===$k?'selected':''; ?>><?php echo $v; ?></option><?php endforeach; ?></select></div></div>
<div class="form-group"><label>Description</label><textarea name="description" rows="8" required><?php echo e($product['description']); ?></textarea></div>
<div style="display:flex;gap:8px;justify-content:flex-end"><a class="btn btn--outline" href="<?php echo APP_URL; ?>/pages/seller/listings.php">Cancel</a><button class="btn btn--primary" type="submit">Save Changes</button></div>
</form>
<?php seller_page_end(); require_once __DIR__.'/../../includes/footer.php';
''', encoding='utf-8')

# Update README with run order
p=root/'README.md'; s=p.read_text()
block='''\n### Product Management Upgrade\nRun `sql/product_management_upgrade.sql` after `sql/seller_upgrade.sql`. It keeps `listings` as the single product entity and adds published/archive lifecycle metadata plus catalog indexes. Sellers can create/edit products, manage stock, publish/unpublish approved products, and archive/restore products without a marketplace removal fee.\n'''
if '### Product Management Upgrade' not in s: s += block
p.write_text(s)
