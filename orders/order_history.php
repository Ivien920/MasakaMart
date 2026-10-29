<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$stmt = $db->prepare("SELECT * FROM orders WHERE user_id = :uid ORDER BY created_at DESC");
$stmt->execute(['uid' => current_user()['user_id']]);
$orders = $stmt->fetchAll();

$itemCounts = [];
if ($orders) {
    $ids = array_column($orders, 'order_id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $countStmt = $db->prepare("SELECT order_id, COUNT(*) AS cnt FROM order_items WHERE order_id IN ($placeholders) GROUP BY order_id");
    $countStmt->execute($ids);
    foreach ($countStmt->fetchAll() as $row) {
        $itemCounts[(int)$row['order_id']] = (int)$row['cnt'];
    }
}

$pageTitle = 'My Orders — MasakaMart';
require_once __DIR__ . '/../includes/header.php';
?>
<section class="container section orders-page">
  <div class="page-header-row">
    <?= katale_global_back_button(APP_URL . '/customer/profile.php') ?>
    <h1 class="page-title">My Orders</h1>
    <div class="header-spacer"></div>
  </div>

  <?php if (empty($orders)): ?>
    <div class="empty-state-card">
      <div class="empty-state-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h16l-1 13H5L4 7Z"/><path d="M9 11v6M15 11v6M10 7V4h4v3"/></svg>
      </div>
      <h2>No orders yet</h2>
      <p>When you place an order, it will appear here.</p>
      <a href="<?= APP_URL ?>/products.php" class="btn btn-primary">Start Shopping</a>
    </div>
  <?php else: ?>
    <div class="order-tabs" role="tablist" aria-label="Filter orders">
      <button type="button" class="order-tab is-active" data-order-filter="all" role="tab" aria-selected="true">All</button>
      <button type="button" class="order-tab" data-order-filter="processing" role="tab" aria-selected="false">Processing</button>
      <button type="button" class="order-tab" data-order-filter="shipped" role="tab" aria-selected="false">Shipped</button>
      <button type="button" class="order-tab" data-order-filter="delivered" role="tab" aria-selected="false">Delivered</button>
    </div>

    <div class="order-cards">
      <?php foreach ($orders as $o):
        $statusSlug = strtolower(str_replace(' ', '_', $o['order_status']));
        $filterBucket = 'processing';
        if (str_contains($statusSlug, 'deliver')) {
          $filterBucket = 'delivered';
        } elseif (str_contains($statusSlug, 'ship') || str_contains($statusSlug, 'out_for')) {
          $filterBucket = 'shipped';
        } elseif (str_contains($statusSlug, 'cancel')) {
          $filterBucket = 'processing';
        }
        $itemCount = $itemCounts[(int)$o['order_id']] ?? 0;
      ?>
      <article class="order-card card" data-order-status="<?= e($filterBucket) ?>">
        <div class="order-card-top">
          <div>
            <span class="order-number">#<?= e($o['order_number']) ?></span>
            <time class="order-date" datetime="<?= e(date('c', strtotime($o['created_at']))) ?>"><?= date('d M Y', strtotime($o['created_at'])) ?></time>
          </div>
          <span class="status-pill status-<?= e($o['payment_status']) ?>"><?= e(ucwords(str_replace('_', ' ', $o['order_status']))) ?></span>
        </div>
        <div class="order-card-meta">
          <span><?= (int)$itemCount ?> item<?= $itemCount === 1 ? '' : 's' ?></span>
          <span class="order-card-total"><?= money((float)$o['grand_total']) ?></span>
        </div>
        <div class="order-card-actions">
          <span class="status-pill status-<?= e($o['payment_status']) ?> payment-pill"><?= e(ucfirst($o['payment_status'])) ?></span>
          <a href="<?= APP_URL ?>/orders/order_detail.php?order=<?= e($o['order_number']) ?>" class="btn btn-secondary btn-sm">Track Order</a>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
