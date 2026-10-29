<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$orderNumber = $_GET['order'] ?? '';
$stmt = $db->prepare("SELECT * FROM orders WHERE order_number = :num AND user_id = :uid");
$stmt->execute(['num' => $orderNumber, 'uid' => current_user()['user_id']]);
$order = $stmt->fetch();

if (!$order) {
    flash('error', 'Order not found.');
    redirect(APP_URL . '/orders/order_history.php');
}

$itemsStmt = $db->prepare("SELECT * FROM order_items WHERE order_id = :id");
$itemsStmt->execute(['id' => $order['order_id']]);
$items = $itemsStmt->fetchAll();

$payStmt = $db->prepare("SELECT * FROM payments WHERE order_id = :id ORDER BY payment_id DESC LIMIT 1");
$payStmt->execute(['id' => $order['order_id']]);
$payment = $payStmt->fetch();

$isPickup = ($order['delivery_method'] ?? '') === 'pickup';
$orderStatus = (string)$order['order_status'];
$payState = (string)$order['payment_status'];
$isCancelled = $orderStatus === 'cancelled';
$payFailed = in_array($payState, ['failed', 'cancelled'], true);

// Pickup orders never go out for delivery; "delivered" means collected.
$statuses = ['received','processing','preparing','ready','out_for_delivery','delivered'];
if ($isPickup) {
    $statuses = array_values(array_diff($statuses, ['out_for_delivery']));
}
$stepLabels = [
    'received' => 'Order placed',
    'processing' => 'Processing',
    'preparing' => 'Preparing your order',
    'ready' => $isPickup ? 'Ready for pickup' : 'Packed and ready',
    'out_for_delivery' => 'Out for delivery',
    'delivered' => $isPickup ? 'Picked up' : 'Delivered',
];
$stepHints = [
    'received' => 'We have received your order.',
    'processing' => 'We are confirming your items.',
    'preparing' => 'Your items are being picked and packed.',
    'ready' => $isPickup ? 'Your order is waiting at the pickup point.' : 'Your order is packed and waiting for a rider.',
    'out_for_delivery' => 'Your order is on its way to you.',
    'delivered' => $isPickup ? 'You have collected your order.' : 'Your order has arrived.',
];
$currentIdx = array_search($orderStatus, $statuses, true);
if ($currentIdx === false) {
    $currentIdx = 0;
}
$deliveredDone = $orderStatus === 'delivered';
$fmtDate = static function ($v): string {
    $t = $v ? strtotime((string)$v) : false;
    return $t ? date('d M Y, H:i', $t) : '';
};
$placedAt = $fmtDate($order['created_at'] ?? null);
$updatedAt = $fmtDate($order['updated_at'] ?? null);

$payLabels = ['pending' => 'Payment pending', 'paid' => 'Paid', 'failed' => 'Payment failed', 'cancelled' => 'Payment cancelled'];
$payLabel = $payLabels[$payState] ?? ucfirst($payState);
$currentLabel = $isCancelled ? 'Cancelled' : ($stepLabels[$statuses[$currentIdx]] ?? ucfirst($orderStatus));

$pageTitle = 'Order ' . $order['order_number'] . ' — MasakaMart';
require_once __DIR__ . '/../includes/header.php';
?>
<section class="container section order-detail-page">
  <div class="order-head">
    <div class="order-head-main">
      <p class="order-head-kicker">Order reference</p>
      <h1 class="page-title order-head-ref"><?= e($order['order_number']) ?></h1>
      <?php if ($placedAt !== ''): ?><p class="order-head-date">Placed <?= e($placedAt) ?></p><?php endif; ?>
    </div>
    <div class="order-head-status">
      <div class="order-badge-group">
        <span class="order-badge-label">Payment</span>
        <span class="order-badge pay-<?= e($payState) ?>"><?= e($payLabel) ?></span>
      </div>
      <div class="order-badge-group">
        <span class="order-badge-label"><?= $isPickup ? 'Pickup' : 'Delivery' ?></span>
        <span class="order-badge <?= $isCancelled ? 'pay-failed' : ($deliveredDone ? 'pay-paid' : 'ord-active') ?>"><?= e($currentLabel) ?></span>
      </div>
    </div>
  </div>

  <?php if ($payState === 'pending' && !$isCancelled): ?>
    <p class="order-notice notice-warning">Your payment has not been confirmed yet. We will start preparing your order once it is received. This can take a few minutes.</p>
  <?php elseif ($payFailed && !$isCancelled): ?>
    <p class="order-notice notice-danger">Your payment was not successful, so this order will not be processed. You can place the order again below.</p>
  <?php elseif ($isCancelled): ?>
    <p class="order-notice notice-danger">This order was cancelled.<?= $payFailed ? ' The payment was not completed.' : '' ?></p>
  <?php endif; ?>

  <ol class="track" aria-label="Order progress">
    <?php foreach ($statuses as $i => $s):
      if ($isCancelled) {
          $state = $i === 0 ? 'done' : 'upcoming';
      } elseif ($deliveredDone || $i < $currentIdx) {
          $state = 'done';
      } elseif ($i === $currentIdx) {
          $state = $payFailed ? 'failed' : 'current';
      } else {
          $state = 'upcoming';
      }
      if ($isCancelled && $i > 0) { continue; }
      $when = '';
      if ($s === 'received') {
          $when = $placedAt;
      } elseif ($i === $currentIdx && !$isCancelled && $updatedAt !== '') {
          $when = ($state === 'done' ? 'Completed ' : 'Updated ') . $updatedAt;
      }
      $mark = $state === 'done' ? '&#10003;' : ($state === 'failed' ? '!' : (string)($i + 1));
    ?>
      <li class="track-step is-<?= $state ?>"<?= $state === 'current' ? ' aria-current="step"' : '' ?>>
        <span class="track-dot" aria-hidden="true"><?= $mark ?></span>
        <div class="track-text">
          <strong><?= e($stepLabels[$s]) ?></strong>
          <?php if ($state === 'current' || $state === 'failed'): ?><span class="track-hint"><?= e($state === 'failed' ? 'On hold until payment is received.' : $stepHints[$s]) ?></span><?php endif; ?>
          <?php if ($when !== ''): ?><span class="track-time"><?= e($when) ?></span><?php endif; ?>
        </div>
      </li>
    <?php endforeach; ?>
    <?php if ($isCancelled): ?>
      <li class="track-step is-cancelled">
        <span class="track-dot" aria-hidden="true">&times;</span>
        <div class="track-text"><strong>Cancelled</strong><?php if ($updatedAt !== ''): ?><span class="track-time"><?= e($updatedAt) ?></span><?php endif; ?></div>
      </li>
    <?php endif; ?>
  </ol>

  <div class="table-scroll">
  <table class="data-table">
    <thead><tr><th>Product</th><th>Unit Price</th><th>Qty</th><th>Line Total</th></tr></thead>
    <tbody>
      <?php foreach ($items as $item): ?>
      <tr>
        <td><?= e($item['product_name']) ?> (<?= e($item['unit']) ?>)</td>
        <td>
          <?= money((float)$item['unit_price']) ?>
          <?php if ((float)$item['unit_price'] < (float)$item['original_unit_price']): ?>
            <span class="price-old"><?= money((float)$item['original_unit_price']) ?></span>
          <?php endif; ?>
        </td>
        <td><?= e((string)$item['quantity']) ?></td>
        <td><?= money((float)$item['line_total']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <div class="order-totals">
    <div><span>Subtotal</span><span><?= money((float)$order['items_subtotal']) ?></span></div>
    <div><span>Delivery Fee</span><span><?= money((float)$order['delivery_fee']) ?></span></div>
    <div class="summary-total"><span>Total</span><span><?= money((float)$order['grand_total']) ?></span></div>
  </div>

  <div class="order-meta">
    <?= katale_render_fulfillment_block($order) ?>
    <?php if ($payment): ?>
      <div class="payment-detail-block">
        <h2>Payment</h2>
        <div class="payment-detail-grid">
          <div>
            <span class="payment-detail-label">Method</span>
            <strong><?= e(katale_payment_method_label((string)($payment['provider'] ?: $payment['method']))) ?></strong>
          </div>
          <div>
            <span class="payment-detail-label">Amount</span>
            <strong><?= money((float)$payment['amount']) ?></strong>
          </div>
          <div>
            <span class="payment-detail-label">Payment Status</span>
            <span class="status-pill status-<?= e((string)$payment['status']) ?>"><?= e(strtoupper((string)$payment['status'])) ?></span>
          </div>
          <div>
            <span class="payment-detail-label">Order Status</span>
            <span class="status-pill status-<?= $order['payment_status'] === 'paid' ? 'paid' : 'pending' ?>"><?= e(strtoupper((string)$order['order_status'])) ?></span>
          </div>
          <div>
            <span class="payment-detail-label">Reference</span>
            <strong><?= e((string)$payment['transaction_reference']) ?></strong>
          </div>
          <?php if (!empty($payment['paid_at'])): ?>
          <div>
            <span class="payment-detail-label">Completed</span>
            <strong><?= e(date('d M Y H:i', strtotime((string)$payment['paid_at']))) ?></strong>
          </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <form action="<?= APP_URL ?>/orders/order_again.php" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="order_id" value="<?= (int)$order['order_id'] ?>">
    <button type="submit" class="btn">Order Again</button>
  </form>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
