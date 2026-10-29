<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/catalogue.php';

$slug = $_GET['slug'] ?? '';
$stmt = $db->prepare("SELECT p.*, c.name AS category_name, c.category_id
                       FROM products p JOIN categories c ON c.category_id = p.category_id
                       WHERE p.slug = :slug AND p.status = 'active'");
$stmt->execute(['slug' => $slug]);
$product = $stmt->fetch();

if (!$product) {
    http_response_code(404);
    require_once __DIR__ . '/includes/header.php';
    echo '<section class="container section"><p class="empty-state">Product not found. It may have been removed.</p></section>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

log_product_view($db, (int)$product['product_id']);
$product = apply_pricing($db, $product);
$outOfStock = ((float)$product['stock_quantity'] <= 0) || !(int)$product['is_available'];

// Reviews
$ratingStmt = $db->prepare(
    "SELECT AVG(rating) AS avg_rating, COUNT(*) AS total FROM reviews WHERE product_id = :pid"
);
$ratingStmt->execute(['pid' => $product['product_id']]);
$ratingInfo = $ratingStmt->fetch();

$reviewsStmt = $db->prepare(
    "SELECT r.rating, r.comment, r.created_at, u.full_name
     FROM reviews r JOIN users u ON u.user_id = r.user_id
     WHERE r.product_id = :pid ORDER BY r.created_at DESC LIMIT 20"
);
$reviewsStmt->execute(['pid' => $product['product_id']]);
$reviews = $reviewsStmt->fetchAll();

// Can the current user review? Must have a paid order containing this product with no existing review.
$canReview = false;
if (is_logged_in()) {
    $eligStmt = $db->prepare(
        "SELECT oi.order_item_id FROM order_items oi
         JOIN orders o ON o.order_id = oi.order_id
         LEFT JOIN reviews r ON r.order_item_id = oi.order_item_id
         WHERE o.user_id = :uid AND oi.product_id = :pid AND o.payment_status = 'paid' AND r.review_id IS NULL
         LIMIT 1"
    );
    $eligStmt->execute(['uid' => current_user()['user_id'], 'pid' => $product['product_id']]);
    $canReview = (bool)$eligStmt->fetch();
}

// Related products — same category
$relStmt = $db->prepare(
    "SELECT * FROM products WHERE category_id = :cid AND product_id != :pid AND status = 'active'
     ORDER BY view_count DESC LIMIT 4"
);
$relStmt->execute(['cid' => $product['category_id'], 'pid' => $product['product_id']]);
$related = array_map(fn($p) => apply_pricing($db, $p), $relStmt->fetchAll());

$pageTitle = $product['name'] . ' — MasakaMart';
$metaDescription = katale_seo_description($product['description'] ?? '');
$GLOBALS['seoProduct'] = $product;
require_once __DIR__ . '/includes/header.php';
$img = $product['image_path'] ? UPLOAD_URL . e($product['image_path']) : APP_URL . '/assets/images/placeholder.svg';
$wishlistButtonTitle = $GLOBALS['katale_wishlist_product_ids'] ?? [];
$isProductWishlisted = in_array((int)$product['product_id'], $wishlistButtonTitle, true);
?>
<section class="container section product-detail">
  <div class="page-header-row">
    <?= katale_global_back_button(APP_URL . '/products.php') ?>
    <h1>Product Details</h1>
    <div class="header-spacer"></div>
    <?php if (is_logged_in()): ?>
      <button type="button" class="global-back-button wishlist-toggle-header" data-wishlist-toggle="<?= (int)$product['product_id'] ?>" aria-label="<?= $isProductWishlisted ? 'Remove from wishlist' : 'Add to wishlist' ?>" aria-pressed="<?= $isProductWishlisted ? 'true' : 'false' ?>">
        <span aria-hidden="true"><?= $isProductWishlisted ? '♥' : '♡' ?></span>
      </button>
    <?php endif; ?>
  </div>

  <div class="product-detail-grid">
    <div class="product-detail-img">
      <img src="<?= $img ?>" alt="<?= e($product['name']) ?>" width="1200" height="1200" loading="eager">
      <?php if ($product['discount_active']): ?><span class="badge badge-discount"><?= e($product['discount_label']) ?></span><?php endif; ?>
    </div>

    <div class="product-detail-info">
      <h1><?= e($product['name']) ?></h1>
      <p class="product-detail-category"><?= e($product['category_name']) ?></p>

      <div class="rating-summary">
        <?php if ($ratingInfo['total'] > 0): ?>
          ⭐ <?= number_format((float)$ratingInfo['avg_rating'], 1) ?> (<?= (int)$ratingInfo['total'] ?> review<?= $ratingInfo['total'] == 1 ? '' : 's' ?>)
        <?php else: ?>
          No reviews yet
        <?php endif; ?>
      </div>

      <div class="product-price product-price-lg">
        <span class="price-now"><?= money($product['effective_price']) ?></span>
        <?php if ($product['discount_active']): ?>
          <span class="price-old"><?= money((float)$product['regular_price']) ?></span>
        <?php endif; ?>
        <span class="price-unit">/ <?= e($product['unit']) ?></span>
      </div>

      <p class="product-description"><?= nl2br(e($product['description'] ?? 'No description provided.')) ?></p>

      <p class="stock-status">
        <?php if ($outOfStock): ?>
          <strong class="text-danger">Out of stock</strong>
        <?php elseif ((float)$product['stock_quantity'] <= (float)$product['low_stock_threshold']): ?>
          <strong class="text-warning">Only <?= rtrim(rtrim((string)$product['stock_quantity'], '0'), '.') ?> <?= e($product['unit']) ?> available</strong>
        <?php else: ?>
          <strong class="text-success">In stock</strong>
        <?php endif; ?>
      </p>

      <ul class="product-feature-list" aria-label="Product highlights">
        <li>Fresh &amp; Natural</li>
        <li>Local Source</li>
        <li>Fast Delivery</li>
      </ul>

      <form class="add-to-cart-form product-detail-form" data-product-id="<?= (int)$product['product_id'] ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="product_id" value="<?= (int)$product['product_id'] ?>">
        <label for="qty">Quantity (<?= e($product['unit']) ?>)</label>
        <input type="number" id="qty" name="quantity" value="1" min="1" step="1" <?= $outOfStock ? 'disabled' : '' ?>>
        <button type="submit" class="btn btn-cta btn-primary btn-add-cart-detail" <?= $outOfStock ? 'disabled' : '' ?>>
          <?= $outOfStock ? 'Unavailable' : 'Add to Cart' ?>
        </button>
      </form>

      <dl class="product-meta-list">
        <div><dt>Category</dt><dd><?= e($product['category_name']) ?></dd></div>
        <div><dt>Unit</dt><dd><?= e($product['unit']) ?></dd></div>
        <div><dt>Availability</dt><dd><?= $outOfStock ? 'Out of stock' : 'In stock' ?></dd></div>
      </dl>

      <?php if (is_logged_in()): ?>
        <form action="<?= APP_URL ?>/customer/wishlist_toggle.php" method="post" class="wishlist-form">
          <?= csrf_field() ?>
          <input type="hidden" name="product_id" value="<?= (int)$product['product_id'] ?>">
          <button type="submit" class="btn btn-outline">♡ Add to Wishlist</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <section class="reviews-section">
    <h2 class="section-title">Reviews</h2>

    <?php if ($canReview): ?>
      <form action="<?= APP_URL ?>/customer/submit_review.php" method="post" class="review-form">
        <?= csrf_field() ?>
        <input type="hidden" name="product_id" value="<?= (int)$product['product_id'] ?>">
        <label>Your Rating</label>
        <select name="rating" required>
          <option value="5">★★★★★ Excellent</option>
          <option value="4">★★★★ Good</option>
          <option value="3">★★★ Average</option>
          <option value="2">★★ Poor</option>
          <option value="1">★ Very Poor</option>
        </select>
        <textarea name="comment" placeholder="Share your experience with this product (optional)" maxlength="1000"></textarea>
        <button type="submit" class="btn">Submit Review</button>
      </form>
    <?php elseif (is_logged_in()): ?>
      <p class="empty-state-sm">Only customers who purchased this product can leave a review.</p>
    <?php endif; ?>

    <?php if (empty($reviews)): ?>
      <p class="empty-state-sm">No reviews yet — be the first to review this product after purchasing it.</p>
    <?php else: ?>
      <div class="review-list">
        <?php foreach ($reviews as $r): ?>
          <div class="review-item">
            <div class="review-header">
              <strong><?= e($r['full_name']) ?></strong>
              <span><?= str_repeat('★', (int)$r['rating']) . str_repeat('☆', 5 - (int)$r['rating']) ?></span>
              <time><?= date('d M Y', strtotime($r['created_at'])) ?></time>
            </div>
            <?php if (!empty($r['comment'])): ?><p><?= nl2br(e($r['comment'])) ?></p><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <?php if (!empty($related)): ?>
    <section class="section">
      <h2 class="section-title">Related Products</h2>
      <div class="product-grid">
        <?php foreach ($related as $p): include __DIR__ . '/includes/product_card.php'; endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
