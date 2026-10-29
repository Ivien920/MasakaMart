<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$email = '';
$sentMessage = null;
$sentMessageType = 'success';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = trim(strtolower($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (empty($errors)) {
        $stmt = $db->prepare('SELECT user_id, full_name, email FROM users WHERE email = :email AND is_active = 1 LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
        if ($user) {
            $tokenData = katale_create_password_reset_token($db, (int)$user['user_id']);
            if ($tokenData) {
                $resetUrl = APP_URL . '/auth/reset_password.php?token=' . urlencode($tokenData['token']);
                $emailSent = katale_send_password_reset_email((string)$user['email'], (string)$user['full_name'], $resetUrl, $tokenData['expires_at']);
                if (!$emailSent) {
                    error_log('[AUTH] Password reset email dispatch failed for ' . katale_mask_email((string)$user['email']));
                }
            } else {
                error_log('[AUTH] Failed to generate password reset token for user_id=' . (int)$user['user_id']);
            }
        }

        // Email enumeration protection: always return a safe, identical confirmation message
        $sentMessage = 'If an account exists for this email address, a password reset link has been sent. Please check your inbox and spam folder shortly.';
        $sentMessageType = 'success';
    }
}

$pageTitle = 'Forgot Password — MasakaMart';
require_once __DIR__ . '/../includes/header.php';
?>
<section class="container section auth-section">
  <div class="auth-card">
    <?php require __DIR__ . '/../includes/auth_brand.php'; ?>
    <h1>Forgot Password?</h1>
    <p class="auth-lead">Enter your email and we will send you a secure link to reset your password.</p>

    <?php if ($errors): ?>
      <div class="flash flash-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <?php if ($sentMessage): ?>
      <div class="flash flash-<?= e($sentMessageType) ?>"><?= e($sentMessage) ?></div>
      <p class="auth-switch"><a href="<?= APP_URL ?>/auth/login.php">Back to Log In</a></p>
    <?php else: ?>
      <form method="post" class="auth-form">
        <?= csrf_field() ?>
        <label>Email Address</label>
        <input type="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="email">
        <button type="submit" class="btn btn-cta btn-block">Send Reset Link</button>
      </form>
      <p class="auth-switch"><a href="<?= APP_URL ?>/auth/login.php">Back to Log In</a></p>
    <?php endif; ?>
  </div>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
