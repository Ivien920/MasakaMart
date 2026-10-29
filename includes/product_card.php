<?php
/** Expects $p (a product row already run through apply_pricing()) in scope. */
$img = $p['image_path'] ? UPLOAD_URL . e($p['image_path']) : APP_URL . '/assets/images/placeholder.svg';
$outOfStock = ((float)$p['stock_quantity'] <= 0) || !(int)$p['is_available'];
$lowStock = !$outOfStock && (float)$p['stock_quantity'] <= 5;
$productId = (int)$p['product_id'];
$shortDesc = trim(strip_tags($p['description'] ?? ''));
if ($shortDesc !== '') {
  $shortDesc = mb_strlen($shortDesc) > 56 ? mb_substr($shortDesc, 0, 53) . '…' : $shortDesc;
}
$wishlistIds = $GLOBALS['katale_wishlist_product_ids'] ?? [];
$isWishlisted = in_array($productId, $wishlistIds, true);
?>
<article class="product-card" data-product-id="<?= $productId ?>" data-product-name="<?= e($p['name']) ?>" data-product-price="<?= e((string)(float)$p['effective_price']) ?>" data-product-category="<?= e($p['category_name'] ?? '') ?>">
  <div class="product-card-img-wrap">
    <a href="<?= APP_URL ?>/product.php?slug=<?= e($p['slug']) ?>" class="product-card-img" aria-label="View <?= e($p['name']) ?>">
      <img src="<?= $img ?>" alt="<?= e($p['name']) ?>" loading="lazy" width="800" height="800">
      <?php if (!empty($p['discount_active'])): ?>
        <span class="badge badge-discount"><?= e($p['discount_label']) ?></span>
      <?php endif; ?>
      <?php if ($outOfStock): ?>
        <span class="badge badge-outofstock">Out of stock</span>
      <?php endif; ?>
    </a>
    <button type="button"
            class="wishlist-heart-btn <?= $isWishlisted ? 'is-active' : '' ?>"
            data-wishlist-toggle="<?= $productId ?>"
            aria-pressed="<?= $isWishlisted ? 'true' : 'false' ?>"
            aria-label="<?= $isWishlisted ? 'Remove from wishlist' : 'Add to wishlist' ?>">
      <span class="wishlist-heart-icon" aria-hidden="true"><?= $isWishlisted ? '♥' : '♡' ?></span>
    </button>
  </div>
  <div class="product-card-body">
    <a href="<?= APP_URL ?>/product.php?slug=<?= e($p['slug']) ?>" class="product-name"><?= e($p['name']) ?></a>
    <?php if ($shortDesc !== ''): ?><p class="product-card-desc"><?= e($shortDesc) ?></p><?php endif; ?>
    <div class="product-price">
      <span class="price-now"><?= money($p['effective_price']) ?></span>
      <?php if (!empty($p['discount_active'])): ?>
        <span class="price-old"><?= money((float)$p['regular_price']) ?></span>
      <?php endif; ?>
    </div>
    <div class="product-stock-row">
      <?php if ($outOfStock): ?>
        <span class="badge-stock badge-out">Out of stock</span>
      <?php elseif ($lowStock): ?>
        <span class="badge-stock badge-low">Low stock</span>
      <?php else: ?>
        <span class="badge-stock badge-in">✓ In stock</span>
      <?php endif; ?>
    </div>
    <div class="product-card-footer">
      <form class="add-to-cart-form" data-product-id="<?= $productId ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="product_id" value="<?= $productId ?>">
        <input type="hidden" name="quantity" value="1">
        <button type="submit" class="btn btn-primary btn-add-cart" <?= $outOfStock ? 'disabled' : '' ?>>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="9" cy="19" r="1.5"/><circle cx="18" cy="19" r="1.5"/><path d="M3 4h2l2.5 9.2a1 1 0 0 0 1 .8h8.8a1 1 0 0 0 1-.8L19 7H7"/></svg>
          Add to Cart
        </button>
      </form>
    </div>
  </div>
</article>
