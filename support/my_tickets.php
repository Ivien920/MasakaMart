<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$stmt = $db->prepare("SELECT * FROM support_tickets WHERE user_id = :uid ORDER BY updated_at DESC");
$stmt->execute(['uid' => current_user()['user_id']]);
$tickets = $stmt->fetchAll();

$pageTitle = 'My Support Tickets — MasakaMart';
require_once __DIR__ . '/../includes/header.php';
?>
<section class="container section">
  <h1 class="page-title">My Support Tickets</h1>
  <?php if (empty($tickets)): ?>
    <p class="empty-state">You have no support tickets. <a href="<?= APP_URL ?>/support/new_ticket.php">Contact support →</a></p>
  <?php else: ?>
    <table class="data-table">
      <thead><tr><th>Subject</th><th>Status</th><th>Last Updated</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($tickets as $t): ?>
        <tr>
          <td><?= e($t['subject']) ?></td>
          <td><span class="status-pill"><?= e(ucfirst($t['status'])) ?></span></td>
          <td><?= date('d M Y H:i', strtotime($t['updated_at'])) ?></td>
          <td><a href="<?= APP_URL ?>/support/ticket_detail.php?id=<?= (int)$t['ticket_id'] ?>">View →</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
