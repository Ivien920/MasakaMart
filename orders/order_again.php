<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(APP_URL . '/orders/order_history.php');
require_csrf();

$orderId = (int)($_POST['order_id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM orders WHERE order_id = :id AND user_id = :uid");
$stmt->execute(['id' => $orderId, 'uid' => current_user()['user_id']]);
$order = $stmt->fetch();

if (!$order) {
    flash('error', 'Order not found.');
    redirect(APP_URL . '/orders/order_history.php');
}

$itemsStmt = $db->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = :id");
$itemsStmt->execute(['id' => $orderId]);

$skipped = 0;
foreach ($itemsStmt->fetchAll() as $item) {
    $pStmt = $db->prepare("SELECT * FROM products WHERE product_id = :id AND status = 'active' AND is_available = 1");
    $pStmt->execute(['id' => $item['product_id']]);
    $product = $pStmt->fetch();
    if (!$product || (float)$product['stock_quantity'] <= 0) {
        $skipped++;
        continue;
    }
    $qty = min((float)$item['quantity'], (float)$product['stock_quantity']);
    $cart->addItem($cartId, (int)$item['product_id'], $qty);
}

flash('success', $skipped > 0
    ? "Items added to your cart. $skipped item(s) were unavailable and skipped."
    : 'Items added to your cart.');
redirect(APP_URL . '/cart/cart.php');
