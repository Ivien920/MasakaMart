<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
require_admin();

$users = $db->query("SELECT user_id, full_name, email FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll();
$history = $db->query("SELECT c.*, a.full_name AS admin_name FROM notification_campaigns c LEFT JOIN admins a ON a.admin_id = c.created_by ORDER BY c.created_at DESC LIMIT 20")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $recipients = $_POST['recipients'] ?? 'all';
    $selected = $_POST['selected_users'] ?? [];
    $title = trim((string)($_POST['title'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));
    $link = trim((string)($_POST['link'] ?? ''));
    $channel = in_array($_POST['channel'] ?? '', ['in_app', 'email', 'both'], true) ? $_POST['channel'] : 'both';

    $errors = [];
    if ($title === '' || $message === '') {
        $errors[] = 'Title and message are required.';
    }
    if ($recipients === 'selected' && empty($selected)) {
        $errors[] = 'Please select at least one user.';
    }

    if (empty($errors)) {
        $recipientIds = [];
        if ($recipients === 'selected') {
            foreach ($selected as $uid) {
                $recipientIds[] = (int)$uid;
            }
        }
        $result = send_admin_broadcast($db, (int)current_admin()['admin_id'], $recipientIds, $title, $message, $channel, $link ?: null);
        if (($channel === 'email' || $channel === 'both') && (int)$result['email_failed'] > 0 && (int)$result['email_sent'] === 0) {
            flash('error', 'In-app notifications may have been created, but all email deliveries failed. Check the PHP error log for [EMAIL] Send failed entries.');
        } elseif ((int)$result['email_failed'] > 0) {
          flash('error', 'Notification created. Email accepted for ' . $result['email_sent'] . ' recipients; ' . $result['email_failed'] . ' email attempts failed.');
        } elseif ($channel === 'email') {
          flash('success', 'Email accepted for ' . $result['email_sent'] . ' recipients.');
        } elseif ($channel === 'both') {
          flash('success', 'Notification created for ' . $result['in_app_sent'] . ' recipients; email accepted for ' . $result['email_sent'] . '.');
        } else {
          flash('success', 'Notification created for ' . $result['in_app_sent'] . ' recipients.');
        }
        redirect(APP_URL . '/admin/notifications.php');
    }

    foreach ($errors as $err) {
        flash('error', $err);
    }
}

$pageTitle = 'Notifications — MasakaMart Admin';
require_once __DIR__ . '/includes/admin_header.php';
?>
<h1>Send Notification</h1>

<form method="post" class="admin-form" onsubmit="return confirm('You are about to send this notification. Continue?');">
  <?= csrf_field() ?>
  <label>Recipients</label>
  <select name="recipients" id="notificationRecipients">
    <option value="all">All users</option>
    <option value="selected">Selected users</option>
  </select>

  <div id="userSelection" style="display:none; margin-top:10px;">
    <?php foreach ($users as $user): ?>
      <label style="display:flex; gap:8px; align-items:center; margin-bottom:6px;">
        <input type="checkbox" name="selected_users[]" value="<?= (int)$user['user_id'] ?>">
        <?= e($user['full_name']) ?> (<?= e($user['email']) ?>)
      </label>
    <?php endforeach; ?>
  </div>

  <label>Title</label>
  <input type="text" name="title" maxlength="180" required>

  <label>Message</label>
  <textarea name="message" rows="5" required></textarea>

  <label>Optional Link</label>
  <input type="url" name="link" placeholder="https://example.com/promotion">

  <label>Channel</label>
  <select name="channel">
    <option value="in_app">In-App Only</option>
    <option value="email">Email Only</option>
    <option value="both" selected>Both</option>
  </select>

  <button type="submit" class="btn btn-cta">Send Notification</button>
</form>

<hr>
<div class="admin-panel">
  <h2>Notification History</h2>
  <?php if (empty($history)): ?>
    <p class="empty-state-sm">No notifications sent yet.</p>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr><th>Date</th><th>Title</th><th>Recipients</th><th>Channel</th><th>Status</th></tr>
      </thead>
      <tbody>
        <?php foreach ($history as $item): ?>
          <tr>
            <td><?= e(date('d M Y', strtotime($item['created_at']))) ?></td>
            <td><?= e($item['title']) ?></td>
            <td><?= e($item['target_type'] === 'all' ? 'All Users' : 'Selected Users') ?></td>
            <td><?= e(ucfirst($item['channel'])) ?></td>
            <td><span class="status-pill status-paid">Created</span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<script>
  const owner = document.getElementById('notificationRecipients');
  const userSelection = document.getElementById('userSelection');
  if (owner) {
    owner.addEventListener('change', function () {
      userSelection.style.display = this.value === 'selected' ? 'block' : 'none';
    });
  }
</script>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
