<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
require_admin();

$statusFilter = $_GET['status'] ?? '';
$where = $statusFilter !== '' ? "WHERE t.status = :status" : "";
$sql = "SELECT t.*, u.full_name, u.email FROM support_tickets t JOIN users u ON u.user_id = t.user_id $where ORDER BY t.updated_at DESC";
$stmt = $db->prepare($sql);
if ($statusFilter !== '') $stmt->execute(['status' => $statusFilter]); else $stmt->execute();
$tickets = $stmt->fetchAll();

$pageTitle = 'Support Tickets — MasakaMart Admin';
require_once __DIR__ . '/includes/admin_header.php';
?>
<h1>Support Tickets</h1>
<div class="admin-panel">
  <form method="get" class="admin-search-form">
    <select name="status" onchange="this.form.submit()">
      <option value="">All</option>
      <option value="open" <?= $statusFilter === 'open' ? 'selected' : '' ?>>Open</option>
      <option value="responded" <?= $statusFilter === 'responded' ? 'selected' : '' ?>>Responded</option>
      <option value="closed" <?= $statusFilter === 'closed' ? 'selected' : '' ?>>Closed</option>
    </select>
  </form>
  <?php if (empty($tickets)): ?>
    <p class="empty-state-sm">No tickets found.</p>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Subject</th><th>Customer</th><th>Status</th><th>Last Updated</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($tickets as $t): ?>
        <tr>
          <td><?= e($t['subject']) ?></td>
          <td><?= e($t['full_name']) ?><br><small><?= e($t['email']) ?></small></td>
          <td><span class="status-pill"><?= e(ucfirst($t['status'])) ?></span></td>
          <td><?= date('d M Y H:i', strtotime($t['updated_at'])) ?></td>
          <td><a href="<?= APP_URL ?>/admin/ticket_detail.php?id=<?= (int)$t['ticket_id'] ?>">View →</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
