<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'toggle') {
        $db->prepare("UPDATE users SET is_active = 1 - is_active WHERE user_id = :id")->execute(['id' => (int)$_POST['user_id']]);
        log_admin_activity($db, 'toggle_customer', (string)$_POST['user_id']);
        flash('success', 'Customer status updated.');
    }
    redirect(APP_URL . '/admin/customers.php');
}

$search = trim($_GET['q'] ?? '');
$where = $search !== '' ? "WHERE full_name LIKE :s OR email LIKE :s2 OR phone LIKE :s3" : "";
$sql = "SELECT u.*, (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.user_id) AS order_count
        FROM users u $where ORDER BY u.created_at DESC";
$stmt = $db->prepare($sql);
if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt->execute(['s' => $like, 's2' => $like, 's3' => $like]);
} else {
    $stmt->execute();
}
$customers = $stmt->fetchAll();

$pageTitle = 'Customers — MasakaMart Admin';
require_once __DIR__ . '/includes/admin_header.php';
?>
<h1>Customers</h1>
<div class="admin-panel">
  <form method="get" class="admin-search-form">
    <input type="text" name="q" placeholder="Search name, email, phone..." value="<?= e($search) ?>">
    <button type="submit" class="btn btn-sm">Search</button>
  </form>
  <?php if (empty($customers)): ?>
    <p class="empty-state-sm">No customers found.</p>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Registered</th><th>Orders</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($customers as $c): ?>
        <tr>
          <td><?= e($c['full_name']) ?></td>
          <td><?= e($c['email']) ?></td>
          <td><?= e($c['phone']) ?></td>
          <td><?= date('d M Y', strtotime($c['created_at'])) ?></td>
          <td><?= (int)$c['order_count'] ?></td>
          <td><span class="status-pill status-<?= $c['is_active'] ? 'paid' : 'failed' ?>"><?= $c['is_active'] ? 'Active' : 'Deactivated' ?></span></td>
          <td>
            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="user_id" value="<?= (int)$c['user_id'] ?>">
              <button type="submit" class="link-btn"><?= $c['is_active'] ? 'Deactivate' : 'Activate' ?></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
