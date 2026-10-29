<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$orderNumber = $_GET['order'] ?? '';
$stmt = $db->prepare("SELECT * FROM orders WHERE order_number = :num AND user_id = :uid");
$stmt->execute(['num' => $orderNumber, 'uid' => current_user()['user_id']]);
$order = $stmt->fetch();

if (!$order) {
    flash('error', 'Order not found.');
    redirect(APP_URL . '/index.php');
}

$pageTitle = 'Order Confirmed — MasakaMart';
require_once __DIR__ . '/../includes/header.php';
?>
<section class="container section confirmation-section">
  <div class="confirmation-box">
    <h1>✅ Order Placed!</h1>
    <p>Thank you, <?= e(explode(' ', $order['customer_name'])[0]) ?>. Your order has been received.</p>
    <p class="order-number">Order Number: <strong><?= e($order['order_number']) ?></strong></p>
    <p>Total: <strong><?= money((float)$order['grand_total']) ?></strong></p>
    <p>Payment status: <strong><?= e(ucfirst($order['payment_status'])) ?></strong></p>
    <p>Delivery method: <strong><?= $order['delivery_method'] === 'pickup' ? 'Market Pickup' : 'Home Delivery' ?></strong></p>
    <div class="confirmation-actions">
      <a href="<?= APP_URL ?>/orders/order_detail.php?order=<?= e($order['order_number']) ?>" class="btn">View Order Details</a>
      <a href="<?= APP_URL ?>/products.php" class="btn btn-outline">Continue Shopping</a>
    </div>
  </div>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
