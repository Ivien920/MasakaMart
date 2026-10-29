<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
require_admin();

$ticketId = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT t.*, u.full_name, u.email FROM support_tickets t JOIN users u ON u.user_id = t.user_id WHERE t.ticket_id = :id");
$stmt->execute(['id' => $ticketId]);
$ticket = $stmt->fetch();
if (!$ticket) { flash('error', 'Ticket not found.'); redirect(APP_URL . '/admin/support_tickets.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $message = trim($_POST['message'] ?? '');
    $newStatus = $_POST['status'] ?? $ticket['status'];

    if ($message !== '') {
        $db->prepare("INSERT INTO support_messages (ticket_id, sender_type, sender_id, message) VALUES (:tid, 'admin', :aid, :msg)")
           ->execute(['tid' => $ticketId, 'aid' => current_admin()['admin_id'], 'msg' => $message]);
        $newStatus = $newStatus === 'closed' ? 'closed' : 'responded';
    }
    $db->prepare("UPDATE support_tickets SET status = :s, updated_at = NOW() WHERE ticket_id = :id")->execute(['s' => $newStatus, 'id' => $ticketId]);
    redirect(APP_URL . '/admin/ticket_detail.php?id=' . $ticketId);
}

$msgStmt = $db->prepare("SELECT * FROM support_messages WHERE ticket_id = :id ORDER BY created_at ASC");
$msgStmt->execute(['id' => $ticketId]);
$messages = $msgStmt->fetchAll();

$pageTitle = $ticket['subject'] . ' — MasakaMart Admin';
require_once __DIR__ . '/includes/admin_header.php';
?>
<h1><?= e($ticket['subject']) ?></h1>
<p>From: <?= e($ticket['full_name']) ?> (<?= e($ticket['email']) ?>)</p>

<div class="admin-panel">
  <div class="ticket-thread">
    <?php foreach ($messages as $m): ?>
      <div class="ticket-message ticket-message-<?= e($m['sender_type']) ?>">
        <strong><?= $m['sender_type'] === 'admin' ? 'MasakaMart Support (You)' : e($ticket['full_name']) ?></strong>
        <p><?= nl2br(e($m['message'])) ?></p>
        <time><?= date('d M Y H:i', strtotime($m['created_at'])) ?></time>
      </div>
    <?php endforeach; ?>
  </div>

  <form method="post" class="admin-form">
    <?= csrf_field() ?>
    <textarea name="message" rows="3" placeholder="Type your reply..."></textarea>
    <label>Status</label>
    <select name="status">
      <option value="open" <?= $ticket['status'] === 'open' ? 'selected' : '' ?>>Open</option>
      <option value="responded" <?= $ticket['status'] === 'responded' ? 'selected' : '' ?>>Responded</option>
      <option value="closed" <?= $ticket['status'] === 'closed' ? 'selected' : '' ?>>Closed</option>
    </select>
    <button type="submit" class="btn btn-cta">Send / Update</button>
  </form>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
