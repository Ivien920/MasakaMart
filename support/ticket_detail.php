<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$ticketId = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM support_tickets WHERE ticket_id = :id AND user_id = :uid");
$stmt->execute(['id' => $ticketId, 'uid' => current_user()['user_id']]);
$ticket = $stmt->fetch();
if (!$ticket) { flash('error', 'Ticket not found.'); redirect(APP_URL . '/support/my_tickets.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $message = trim($_POST['message'] ?? '');
    if ($message !== '' && $ticket['status'] !== 'closed') {
        $db->prepare("INSERT INTO support_messages (ticket_id, sender_type, sender_id, message) VALUES (:tid, 'customer', :uid, :msg)")
           ->execute(['tid' => $ticketId, 'uid' => current_user()['user_id'], 'msg' => $message]);
        $db->prepare("UPDATE support_tickets SET status = 'open', updated_at = NOW() WHERE ticket_id = :id")->execute(['id' => $ticketId]);
        redirect(APP_URL . '/support/ticket_detail.php?id=' . $ticketId);
    }
}

$msgStmt = $db->prepare("SELECT * FROM support_messages WHERE ticket_id = :id ORDER BY created_at ASC");
$msgStmt->execute(['id' => $ticketId]);
$messages = $msgStmt->fetchAll();

$pageTitle = 'Ticket: ' . $ticket['subject'] . ' — MasakaMart';
require_once __DIR__ . '/../includes/header.php';
?>
<section class="container section">
  <h1 class="page-title"><?= e($ticket['subject']) ?></h1>
  <span class="status-pill"><?= e(ucfirst($ticket['status'])) ?></span>
  <div class="ticket-thread">
    <?php foreach ($messages as $m): ?>
      <div class="ticket-message ticket-message-<?= e($m['sender_type']) ?>">
        <strong><?= $m['sender_type'] === 'admin' ? 'MasakaMart Support' : 'You' ?></strong>
        <p><?= nl2br(e($m['message'])) ?></p>
        <time><?= date('d M Y H:i', strtotime($m['created_at'])) ?></time>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if ($ticket['status'] !== 'closed'): ?>
    <form method="post" class="auth-form">
      <?= csrf_field() ?>
      <textarea name="message" rows="3" placeholder="Type a reply..." required></textarea>
      <button type="submit" class="btn">Send</button>
    </form>
  <?php else: ?>
    <p class="empty-state-sm">This ticket is closed.</p>
  <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
