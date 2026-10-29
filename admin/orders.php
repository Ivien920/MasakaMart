<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
require_admin();

$search = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';

$where = "1=1";
$params = [];
if ($search !== '') {
    $where .= " AND (order_number LIKE :s OR customer_name LIKE :s2 OR customer_phone LIKE :s3)";
    $params['s'] = '%' . $search . '%'; $params['s2'] = '%' . $search . '%'; $params['s3'] = '%' . $search . '%';
}
if ($statusFilter !== '') {
    $where .= " AND order_status = :status";
    $params['status'] = $statusFilter;
}

$stmt = $db->prepare("SELECT * FROM orders WHERE $where ORDER BY created_at DESC LIMIT 200");
$stmt->execute($params);
$orders = $stmt->fetchAll();

$statuses = ['received','processing','preparing','ready','out_for_delivery','delivered','cancelled'];

$pageTitle = 'Orders — MasakaMart Admin';
require_once __DIR__ . '/includes/admin_header.php';
?>
<h1>Order Management</h1>
<div class="admin-panel">
  <form method="get" class="admin-search-form">
    <input type="text" name="q" placeholder="Search order #, name, phone..." value="<?= e($search) ?>">
    <select name="status">
      <option value="">All Statuses</option>
      <?php foreach ($statuses as $s): ?>
        <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_',' ',$s))) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-sm">Filter</button>
  </form>

  <?php if (empty($orders)): ?>
    <p class="empty-state-sm">No orders found.</p>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Order #</th><th>Customer</th><th>Date</th><th>Total</th><th>Payment</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($orders as $o): ?>
        <tr>
          <td><?= e($o['order_number']) ?></td>
          <td><?= e($o['customer_name']) ?><br><small><?= e($o['customer_phone']) ?></small></td>
          <td><?= date('d M Y', strtotime($o['created_at'])) ?></td>
          <td><?= money((float)$o['grand_total']) ?></td>
          <td><span class="status-pill status-<?= e($o['payment_status']) ?>"><?= e(ucfirst($o['payment_status'])) ?></span></td>
          <td><span class="status-pill"><?= e(ucwords(str_replace('_',' ',$o['order_status']))) ?></span></td>
          <td><a href="<?= APP_URL ?>/admin/order_detail.php?id=<?= (int)$o['order_id'] ?>">View →</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
