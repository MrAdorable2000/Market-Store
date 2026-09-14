<?php
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
            $listingsCols=db_table_columns(db(),'listings');
            $set=['title=?','description=?','price=?','condition_state=?'];
            $params=[$title,$description,$price,$condition];
            if(isset($listingsCols['sku'])){ $set[]='sku=?'; $params[]=$sku?:null; }
            if(isset($listingsCols['stock_quantity'])){ $set[]='stock_quantity=?'; $params[]=$stock; }
            $set[]='updated_at=CURRENT_TIMESTAMP';
            $params[]=$id; $params[]=$uid;
            $stmt=db()->prepare('UPDATE listings SET '.implode(', ',$set).' WHERE id=? AND seller_id=?');
            $stmt->execute($params);
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
