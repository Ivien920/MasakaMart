<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
require_admin();

$orderId = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $newStatus = $_POST['order_status'] ?? '';
    $allowed = ['received','processing','preparing','ready','out_for_delivery','delivered','cancelled'];
    if (in_array($newStatus, $allowed, true)) {
        $db->prepare("UPDATE orders SET order_status = :s WHERE order_id = :id")->execute(['s' => $newStatus, 'id' => $orderId]);
        handle_order_status_change($db, $orderId, $newStatus);
        log_admin_activity($db, 'update_order_status', "Order #$orderId -> $newStatus");
        flash('success', 'Order status updated.');
    }
    redirect(APP_URL . '/admin/order_detail.php?id=' . $orderId);
}

$stmt = $db->prepare("SELECT * FROM orders WHERE order_id = :id");
$stmt->execute(['id' => $orderId]);
$order = $stmt->fetch();
if (!$order) { flash('error', 'Order not found.'); redirect(APP_URL . '/admin/orders.php'); }

$itemsStmt = $db->prepare("SELECT * FROM order_items WHERE order_id = :id");
$itemsStmt->execute(['id' => $orderId]);
$items = $itemsStmt->fetchAll();

$payStmt = $db->prepare("SELECT * FROM payments WHERE order_id = :id ORDER BY payment_id DESC");
$payStmt->execute(['id' => $orderId]);
$payments = $payStmt->fetchAll();

$pageTitle = 'Order ' . $order['order_number'] . ' — MasakaMart Admin';
require_once __DIR__ . '/includes/admin_header.php';
?>
<h1>Order <?= e($order['order_number']) ?></h1>

<div class="admin-grid-2">
  <div class="admin-panel">
    <h2>Customer</h2>
    <p><?= e($order['customer_name']) ?><br><?= e($order['customer_phone']) ?><br><?= e($order['customer_email']) ?></p>
    <h2>Fulfillment</h2>
    <?= katale_render_fulfillment_block($order, 'admin', $db) ?>
    <h2>Payment</h2>
    <?php if (empty($payments)): ?>
      <p>No payment record yet.</p>
    <?php endif; ?>
    <?php foreach ($payments as $p): ?>
      <div class="admin-payment-entry">
        <p>
          <?= e(katale_payment_method_label((string)($p['provider'] ?: $p['method']))) ?> — <?= money((float)$p['amount']) ?><br>
          Payment status:
          <span class="status-pill status-<?= e($p['status']) ?>"><?= e(strtoupper((string)$p['status'])) ?></span>
          &nbsp; Order payment: <span class="status-pill status-<?= e($order['payment_status'] === 'paid' ? 'paid' : 'pending') ?>"><?= e(strtoupper((string)$order['payment_status'])) ?></span>
        </p>
        <p class="admin-payment-meta">
          <strong>Reference:</strong> <?= e($p['transaction_reference']) ?><br>
          <?php if (!empty($p['pesajet_transaction_id'])): ?>
            <strong>PesaJet txn:</strong> <?= e($p['pesajet_transaction_id']) ?><br>
          <?php endif; ?>
          <?php if (!empty($p['provider_reference'])): ?>
            <strong>Provider ref:</strong> <?= e($p['provider_reference']) ?><br>
          <?php endif; ?>
          <?php if (!empty($p['webhook_status'])): ?>
            <strong>Webhook:</strong> <?= e($p['webhook_status']) ?><?= !empty($p['webhook_received_at']) ? ' at ' . e(date('d M Y H:i', strtotime($p['webhook_received_at']))) : '' ?><br>
          <?php endif; ?>
          <?php if (!empty($p['failure_reason'])): ?>
            <strong>Failure:</strong> <?= e($p['failure_reason']) ?><br>
          <?php endif; ?>
          <strong>Date:</strong> <?= e(date('d M Y H:i', strtotime($p['created_at']))) ?>
          <?php if (!empty($p['paid_at'])): ?>
            &nbsp;·&nbsp; <strong>Completed:</strong> <?= e(date('d M Y H:i', strtotime($p['paid_at']))) ?>
          <?php endif; ?>
          <?php if ((int)($p['attempt_count'] ?? 0) > 0): ?>
            &nbsp;·&nbsp; <strong>Attempts:</strong> <?= (int)$p['attempt_count'] ?>
          <?php endif; ?>
        </p>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="admin-panel">
    <h2>Update Status</h2>
    <form method="post" class="inline-form">
      <?= csrf_field() ?>
      <?php
        $isPickupOrder = $order['delivery_method'] === 'pickup';
        // Pickup orders never go "out for delivery" — the wording of the
        // shared statuses is adjusted per fulfillment method instead of
        // adding a parallel status enum.
        $statusOptions = $isPickupOrder
            ? ['received' => 'Order Received', 'processing' => 'Processing', 'preparing' => 'Preparing Order', 'ready' => 'Ready for Pickup', 'delivered' => 'Picked Up', 'cancelled' => 'Cancelled']
            : ['received' => 'Order Received', 'processing' => 'Processing', 'preparing' => 'Preparing Order', 'ready' => 'Ready for Delivery', 'out_for_delivery' => 'Out for Delivery', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'];
      ?>
      <select name="order_status">
        <?php foreach ($statusOptions as $s => $label): ?>
          <option value="<?= $s ?>" <?= $order['order_status'] === $s ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn">Update</button>
    </form>
  </div>
</div>

<div class="admin-panel">
  <h2>Items</h2>
  <div class="table-scroll">
  <table class="data-table">
    <thead><tr><th>Product</th><th>Unit Price</th><th>Qty</th><th>Line Total</th></tr></thead>
    <tbody>
      <?php foreach ($items as $item): ?>
      <tr><td><?= e($item['product_name']) ?></td><td><?= money((float)$item['unit_price']) ?></td><td><?= e((string)$item['quantity']) ?> <?= e($item['unit']) ?></td><td><?= money((float)$item['line_total']) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <div class="order-totals">
    <div><span>Subtotal</span><span><?= money((float)$order['items_subtotal']) ?></span></div>
    <div><span>Delivery Fee</span><span><?= money((float)$order['delivery_fee']) ?></span></div>
    <div class="summary-total"><span>Total</span><span><?= money((float)$order['grand_total']) ?></span></div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
