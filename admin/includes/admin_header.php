<?php
$pageTitle = $pageTitle ?? 'Admin — MasakaMart';
$admin = current_admin();
$adminNavCounts = [
  'dashboard' => 0,
  'categories' => 0,
  'products' => 0,
  'inventory' => (int)$db->query(
    "SELECT COUNT(*) AS c FROM products WHERE status = 'active' AND stock_quantity <= low_stock_threshold"
  )->fetch()['c'],
  'orders' => (int)$db->query(
    "SELECT COUNT(*) AS c FROM orders WHERE order_status NOT IN ('delivered', 'cancelled')"
  )->fetch()['c'],
  'payments' => (int)$db->query(
    "SELECT COUNT(*) AS c FROM payments WHERE status IN ('pending','processing') OR needs_reconciliation = 1"
  )->fetch()['c'],
  'notifications' => 0,
  'delivery_zones' => 0,
  'baskets' => 0,
  'customers' => 0,
  'support_tickets' => (int)$db->query(
    "SELECT COUNT(*) AS c FROM support_tickets WHERE status = 'open'"
  )->fetch()['c'],
  'logout' => 0,
];

function admin_sidebar_link(string $path, string $label, string $badgeKey, array $counts): string
{
    $count = (int)($counts[$badgeKey] ?? 0);
    $badge = $count > 0 ? '<span class="admin-nav-badge">' . e((string)$count) . '</span>' : '';
    return '<a href="' . APP_URL . '/admin/' . $path . '">' . e($label) . $badge . '</a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?></title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Inter:wght@400;500;600;700;800&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=<?= filemtime(__DIR__ . '/../../assets/css/style.css') ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../../assets/css/admin.css') ?>">
<script src="<?= APP_URL ?>/assets/js/admin.js?v=<?= filemtime(__DIR__ . '/../../assets/js/admin.js') ?>"></script>
</head>
<body class="admin-body">
<div class="admin-layout">
  <aside class="admin-sidebar">
    <div class="admin-brand">MasakaMart <span>Admin</span></div>
    <nav>
      <a href="<?= APP_URL ?>/admin/dashboard.php">Dashboard</a>
      <a href="<?= APP_URL ?>/admin/categories.php">Categories</a>
      <a href="<?= APP_URL ?>/admin/products.php">Products</a>
      <?= admin_sidebar_link('inventory.php', 'Inventory', 'inventory', $adminNavCounts) ?>
      <?= admin_sidebar_link('orders.php', 'Orders', 'orders', $adminNavCounts) ?>
      <?= admin_sidebar_link('payments.php', 'Payments', 'payments', $adminNavCounts) ?>
      <a href="<?= APP_URL ?>/admin/reports.php">Reports &amp; Analytics</a>
      <a href="<?= APP_URL ?>/admin/notifications.php">Notifications</a>
      <a href="<?= APP_URL ?>/admin/push_test.php">Push Test</a>
      <a href="<?= APP_URL ?>/admin/hero_slider.php">Hero Slider</a>
      <a href="<?= APP_URL ?>/admin/delivery_settings.php">Delivery &amp; Pickup</a>
      <a href="<?= APP_URL ?>/admin/delivery_zones.php">Delivery Zones</a>
      <a href="<?= APP_URL ?>/admin/baskets.php">Shopping Baskets</a>
      <a href="<?= APP_URL ?>/admin/customers.php">Customers</a>
      <?= admin_sidebar_link('support_tickets.php', 'Support Tickets', 'support_tickets', $adminNavCounts) ?>
      <a href="<?= APP_URL ?>/admin/logout.php">Log Out</a>
    </nav>
  </aside>
  <div class="admin-main">
    <header class="admin-topbar">
      <div class="admin-topbar-left">
        <span>Logged in as <?= e($admin['full_name'] ?? '') ?></span>
      </div>
      <a href="<?= APP_URL ?>/index.php" target="_blank">View Storefront ↗</a>
    </header>
    <div class="admin-content">
        <?php if ($msg = flash('success')): ?><div class="flash flash-success"><?= e($msg) ?></div><?php endif; ?>
      <?php if ($msg = flash('error')): ?><div class="flash flash-error"><?= e($msg) ?></div><?php endif; ?>
