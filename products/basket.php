<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$slug = $_GET['slug'] ?? '';
$stmt = $db->prepare("SELECT * FROM baskets WHERE slug = :slug AND is_active = 1");
$stmt->execute(['slug' => $slug]);
$basket = $stmt->fetch();

if (!$basket) {
    flash('error', 'Basket not found.');
    redirect(APP_URL . '/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $itemsStmt = $db->prepare("SELECT product_id, quantity FROM basket_items WHERE basket_id = :id");
    $itemsStmt->execute(['id' => $basket['basket_id']]);
    foreach ($itemsStmt->fetchAll() as $item) {
        $pStmt = $db->prepare("SELECT * FROM products WHERE product_id = :id AND status = 'active' AND is_available = 1");
        $pStmt->execute(['id' => $item['product_id']]);
        $product = $pStmt->fetch();
        if ($product && (float)$product['stock_quantity'] > 0) {
            $qty = min((float)$item['quantity'], (float)$product['stock_quantity']);
            $cart->addItem($cartId, (int)$item['product_id'], $qty);
        }
    }
    flash('success', 'Basket added to your cart.');
    redirect(APP_URL . '/cart/cart.php');
}

$itemsStmt = $db->prepare(
    "SELECT bi.quantity, p.* FROM basket_items bi JOIN products p ON p.product_id = bi.product_id
     WHERE bi.basket_id = :id"
);
$itemsStmt->execute(['id' => $basket['basket_id']]);
$basketItems = array_map(fn($p) => apply_pricing($db, $p), $itemsStmt->fetchAll());
$basketTotal = array_sum(array_map(fn($p) => $p['effective_price'] * (float)$p['quantity'], $basketItems));

$pageTitle = $basket['name'] . ' — MasakaMart';
require_once __DIR__ . '/../includes/header.php';
?>
<section class="container section">
  <h1 class="page-title"><?= e($basket['name']) ?></h1>
  <p><?= e($basket['description'] ?? '') ?></p>

  <table class="data-table">
    <thead><tr><th>Product</th><th>Quantity</th><th>Price</th></tr></thead>
    <tbody>
      <?php foreach ($basketItems as $item): ?>
      <tr>
        <td><?= e($item['name']) ?></td>
        <td><?= e((string)$item['quantity']) ?> <?= e($item['unit']) ?></td>
        <td><?= money($item['effective_price'] * (float)$item['quantity']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="summary-total"><span>Basket Total: <?= money($basketTotal) ?></span></p>

  <form method="post">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-cta">Add Basket to Cart</button>
  </form>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
