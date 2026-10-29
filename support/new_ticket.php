<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
  $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $orderNumber = trim($_POST['order_number'] ?? '');
    $orderId = null;

  if ($subject === '' || $message === '') $errors[] = 'Please tell us the reason for this ticket and what happened.';

    if ($orderNumber !== '') {
        $stmt = $db->prepare("SELECT order_id FROM orders WHERE order_number = :num AND user_id = :uid");
        $stmt->execute(['num' => $orderNumber, 'uid' => current_user()['user_id']]);
        $order = $stmt->fetch();
        if (!$order) {
            $errors[] = 'Order number not found on your account.';
        } else {
            $orderId = $order['order_id'];
        }
    }

    if (empty($errors)) {
        $db->beginTransaction();
        $stmt = $db->prepare("INSERT INTO support_tickets (user_id, order_id, subject) VALUES (:uid, :oid, :subj)");
        $stmt->execute(['uid' => current_user()['user_id'], 'oid' => $orderId, 'subj' => $subject]);
        $ticketId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO support_messages (ticket_id, sender_type, sender_id, message) VALUES (:tid, 'customer', :uid, :msg)")
           ->execute(['tid' => $ticketId, 'uid' => current_user()['user_id'], 'msg' => $message]);
        $db->commit();
        flash('success', 'Your support ticket has been submitted. We will respond soon.');
        redirect(APP_URL . '/support/my_tickets.php');
    }
}

$pageTitle = 'Contact Support — MasakaMart';
require_once __DIR__ . '/../includes/header.php';
?>
<section class="container section auth-section">
  <div class="auth-card">
    <h1>Contact Support</h1>
    <p>Start with the reason for the ticket, then describe the issue like a live conversation. You can also reach us directly on WhatsApp.</p>
    <a class="whatsapp-link whatsapp-icon-link" href="https://wa.me/256779860256" target="_blank" rel="noopener noreferrer" aria-label="Chat with MasakaMart on WhatsApp" title="Chat with MasakaMart on WhatsApp">
      <svg class="whatsapp-icon" viewBox="0 0 48 48" aria-hidden="true" focusable="false">
        <circle cx="24" cy="24" r="24" fill="#25D366"></circle>
        <path fill="#ffffff" d="M33.5 28.8c-.5-.3-2.9-1.5-3.4-1.7-.5-.2-.8-.3-1.1.2-.3.5-1.2 1.7-1.5 2.1-.3.4-.5.4-1 .1-.5-.3-2.1-.8-4-2.5-1.5-1.3-2.4-2.9-2.7-3.4-.3-.5 0-.8.2-1 .2-.2.5-.6.7-.8.2-.3.3-.5.5-.8.2-.3.1-.5 0-.8s-1.1-2.8-1.5-3.8c-.4-1-.8-.9-1.1-.9h-.9c-.3 0-.8.1-1.2.6s-1.6 1.5-1.6 3.7 1.6 4.4 1.8 4.7c.2.3 3.2 4.9 7.8 6.8 1.1.5 2 .8 2.7 1 .9.3 1.7.2 2.4.1.7-.1 2.1-.9 2.4-1.7.3-.8.3-1.5.2-1.7-.1-.1-.4-.2-.9-.4z"/>
      </svg>
      <span class="sr-only">Chat on WhatsApp</span>
    </a>
    <?php if ($errors): ?><div class="flash flash-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" class="auth-form">
      <?= csrf_field() ?>
      <label>Ticket Reason / Topic</label>
      <input type="text" name="subject" placeholder="Example: Missing item, wrong delivery, payment issue" required>
      <label>Related Order Number (optional)</label>
      <input type="text" name="order_number" placeholder="KT-10001">
      <label>First Message</label>
      <textarea name="message" rows="5" placeholder="Tell us what happened and how we can help..." required></textarea>
      <button type="submit" class="btn btn-cta btn-block">Submit Ticket</button>
    </form>
  </div>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
