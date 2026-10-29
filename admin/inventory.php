<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
require_admin();

$products = $db->query(
    "SELECT p.*, c.name AS category_name FROM products p JOIN categories c ON c.category_id = p.category_id
     WHERE p.status = 'active' ORDER BY (p.stock_quantity <= p.low_stock_threshold) DESC, p.stock_quantity ASC"
)->fetchAll();

$pageTitle = 'Inventory — MasakaMart Admin';
require_once __DIR__ . '/includes/admin_header.php';
?>
<h1>Inventory</h1>
<div class="admin-panel">
  <div class="table-scroll">
  <table class="data-table">
    <thead><tr><th>Product</th><th>Category</th><th>Stock</th><th>Low-Stock Threshold</th><th>Availability</th><th>Last Update</th></tr></thead>
    <tbody>
      <?php foreach ($products as $p): $low = (float)$p['stock_quantity'] <= (float)$p['low_stock_threshold']; ?>
      <tr class="<?= $low ? 'row-low-stock' : '' ?>">
        <td><a href="<?= APP_URL ?>/admin/products.php?edit=<?= (int)$p['product_id'] ?>"><?= e($p['name']) ?></a></td>
        <td><?= e($p['category_name']) ?></td>
        <td class="<?= $low ? 'text-warning' : '' ?>"><?= e((string)$p['stock_quantity']) ?> <?= e($p['unit']) ?><?= $low ? ' ⚠️' : '' ?></td>
        <td><?= e((string)$p['low_stock_threshold']) ?></td>
        <td><?= $p['is_available'] ? 'Available' : 'Unavailable' ?></td>
        <td><?= date('d M Y H:i', strtotime($p['updated_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
