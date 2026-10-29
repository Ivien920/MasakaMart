<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
require_admin();

$errors = [];
$success = null;
$admin = current_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (strlen($new) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $errors[] = 'Passwords do not match.';
    } else {
        $db->prepare("UPDATE admins SET password_hash = :hash, must_change_password = 0 WHERE admin_id = :id")
           ->execute(['hash' => password_hash($new, PASSWORD_DEFAULT), 'id' => $admin['admin_id']]);
        log_admin_activity($db, 'changed_password');
        flash('success', 'Password updated.');
        redirect(APP_URL . '/admin/dashboard.php');
    }
}

$pageTitle = 'Change Password — MasakaMart Admin';
require_once __DIR__ . '/includes/admin_header.php';
?>
<h1>Set a New Password</h1>
<p>For security, please set your own password before continuing.</p>
<?php if ($errors): ?><div class="flash flash-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" class="admin-form">
  <?= csrf_field() ?>
  <label>New Password</label>
  <input type="password" name="new_password" minlength="8" required>
  <label>Confirm Password</label>
  <input type="password" name="confirm_password" minlength="8" required>
  <button type="submit" class="btn btn-cta">Save Password</button>
</form>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
