<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
require_admin();

$totalSales = (float)$db->query("SELECT COALESCE(SUM(grand_total),0) AS t FROM orders WHERE payment_status = 'paid'")->fetch()['t'];
$todaySales = (float)$db->query("SELECT COALESCE(SUM(grand_total),0) AS t FROM orders WHERE payment_status = 'paid' AND DATE(created_at) = CURDATE()")->fetch()['t'];
$totalOrders = (int)$db->query("SELECT COUNT(*) AS c FROM orders")->fetch()['c'];
$pendingOrders = (int)$db->query("SELECT COUNT(*) AS c FROM orders WHERE order_status NOT IN ('delivered','cancelled')")->fetch()['c'];
$completedOrders = (int)$db->query("SELECT COUNT(*) AS c FROM orders WHERE order_status = 'delivered'")->fetch()['c'];
$totalCustomers = (int)$db->query("SELECT COUNT(*) AS c FROM users")->fetch()['c'];
$totalProducts = (int)$db->query("SELECT COUNT(*) AS c FROM products WHERE status = 'active'")->fetch()['c'];
$lowStock = $db->query(
    "SELECT name, stock_quantity, unit, low_stock_threshold FROM products
     WHERE status = 'active' AND stock_quantity <= low_stock_threshold ORDER BY stock_quantity ASC LIMIT 10"
)->fetchAll();

$salesByDay = $db->query(
    "SELECT DATE(created_at) AS d, SUM(grand_total) AS total FROM orders
     WHERE payment_status = 'paid' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
     GROUP BY DATE(created_at) ORDER BY d"
)->fetchAll();

$popularCategories = $db->query(
    "SELECT c.name, COUNT(oi.order_item_id) AS n FROM order_items oi
     JOIN products p ON p.product_id = oi.product_id
     JOIN categories c ON c.category_id = p.category_id
     JOIN orders o ON o.order_id = oi.order_id AND o.payment_status = 'paid'
     GROUP BY c.category_id ORDER BY n DESC LIMIT 5"
)->fetchAll();

$pageTitle = 'Dashboard — MasakaMart Admin';
require_once __DIR__ . '/includes/admin_header.php';
?>
<h1>Dashboard</h1>

<div class="stat-grid">
  <div class="stat-card"><span class="stat-label">Total Sales</span><span class="stat-value"><?= money($totalSales) ?></span></div>
  <div class="stat-card"><span class="stat-label">Today's Sales</span><span class="stat-value"><?= money($todaySales) ?></span></div>
  <div class="stat-card"><span class="stat-label">Total Orders</span><span class="stat-value"><?= $totalOrders ?></span></div>
  <div class="stat-card"><span class="stat-label">Pending Orders</span><span class="stat-value"><?= $pendingOrders ?></span></div>
  <div class="stat-card"><span class="stat-label">Completed Orders</span><span class="stat-value"><?= $completedOrders ?></span></div>
  <div class="stat-card"><span class="stat-label">Customers</span><span class="stat-value"><?= $totalCustomers ?></span></div>
  <div class="stat-card"><span class="stat-label">Active Products</span><span class="stat-value"><?= $totalProducts ?></span></div>
</div>

<div class="admin-grid-2">
  <div class="admin-panel">
    <h2>Sales — Last 14 Days</h2>
    <?php if (empty($salesByDay)): ?>
      <p class="empty-state-sm">No paid orders yet.</p>
    <?php else: ?>
      <canvas id="salesChart" height="200"></canvas>
    <?php endif; ?>
  </div>
  <div class="admin-panel">
    <h2>Top Categories by Orders</h2>
    <?php if (empty($popularCategories)): ?>
      <p class="empty-state-sm">No order activity yet.</p>
    <?php else: ?>
      <canvas id="categoryChart" height="200"></canvas>
    <?php endif; ?>
  </div>
</div>

<div class="admin-panel">
  <h2>Low Stock Products</h2>
  <?php if (empty($lowStock)): ?>
    <p class="empty-state-sm">No products are currently low on stock.</p>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Product</th><th>Stock</th><th>Threshold</th></tr></thead>
      <tbody>
        <?php foreach ($lowStock as $p): ?>
        <tr><td><?= e($p['name']) ?></td><td class="text-warning"><?= e((string)$p['stock_quantity']) ?> <?= e($p['unit']) ?></td><td><?= e((string)$p['low_stock_threshold']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>
<script>
<?php if (!empty($salesByDay)): ?>
new Chart(document.getElementById('salesChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode(array_map(fn($r) => date('d M', strtotime($r['d'])), $salesByDay)) ?>,
    datasets: [{ label: 'Sales (UGX)', data: <?= json_encode(array_map(fn($r) => (float)$r['total'], $salesByDay)) ?>, borderColor: '#e8532b', backgroundColor: 'rgba(232,83,43,0.15)', fill: true, tension: 0.3 }]
  },
  options: { plugins: { legend: { display: false } } }
});
<?php endif; ?>
<?php if (!empty($popularCategories)): ?>
new Chart(document.getElementById('categoryChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_map(fn($r) => $r['name'], $popularCategories)) ?>,
    datasets: [{ label: 'Order Items', data: <?= json_encode(array_map(fn($r) => (int)$r['n'], $popularCategories)) ?>, backgroundColor: '#1c1c1c' }]
  },
  options: { plugins: { legend: { display: false } } }
});
<?php endif; ?>
</script>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
