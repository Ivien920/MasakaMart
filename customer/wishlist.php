<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/catalogue.php';
require_login();

$userId = current_user()['user_id'];
$stmt = $db->prepare(
    "SELECT p.* FROM wishlist_items wi
     JOIN wishlists w ON w.wishlist_id = wi.wishlist_id
     JOIN products p ON p.product_id = wi.product_id
     WHERE w.user_id = :uid
     ORDER BY wi.added_at DESC"
);
$stmt->execute(['uid' => $userId]);
$products = array_map(fn($p) => apply_pricing($db, $p), $stmt->fetchAll());

$pageTitle = 'My Wishlist — MasakaMart';
require_once __DIR__ . '/../includes/header.php';
?>
<section class="container section">
  <h1 class="page-title">My Wishlist</h1>
  <?php if (empty($products)): ?>
    <p class="empty-state">Your wishlist is empty. <a href="<?= APP_URL ?>/products.php">Browse products →</a></p>
  <?php else: ?>
    <div class="product-grid">
      <?php foreach ($products as $p): include __DIR__ . '/../includes/product_card.php'; endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
