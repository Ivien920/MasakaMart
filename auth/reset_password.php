<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$errors = [];
$tokenRow = $token !== '' ? katale_find_password_reset_user($db, $token) : null;

if ($token === '' || !$tokenRow) {
    $pageTitle = 'Reset Password — MasakaMart';
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <section class="container section auth-section">
      <div class="auth-card">
        <?php require __DIR__ . '/../includes/auth_brand.php'; ?>
        <h1>Reset link invalid</h1>
        <p class="auth-lead">This password reset link is invalid, expired, or has already been used.</p>
        <p class="auth-switch"><a href="<?= APP_URL ?>/auth/forgot_password.php">Request a new reset link</a></p>
      </div>
    </section>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $reqError = katale_password_meets_requirements($password);
    if ($reqError) {
        $errors[] = $reqError;
    } elseif ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    } else {
        $tokenRow = katale_find_password_reset_user($db, $token);
        if (!$tokenRow) {
            $errors[] = 'This reset link is no longer valid. Please request a new one.';
        } else {
            $userId = (int)$tokenRow['user_id'];
            $db->prepare('UPDATE users SET password_hash = :hash WHERE user_id = :uid')
                ->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'uid' => $userId]);
            katale_consume_password_reset_token($db, (int)$tokenRow['token_id'], $userId);
            flash('success', 'Your password has been updated. You can log in with your new password.');
            redirect(APP_URL . '/auth/login.php');
        }
    }
}

$pageTitle = 'Create New Password — MasakaMart';
require_once __DIR__ . '/../includes/header.php';
?>
<section class="container section auth-section">
  <div class="auth-card">
    <?php require __DIR__ . '/../includes/auth_brand.php'; ?>
    <h1>Create New Password</h1>
    <p class="auth-lead">Choose a strong password for your MasakaMart account.</p>

    <?php if ($errors): ?>
      <div class="flash flash-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <form method="post" class="auth-form">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <label>New Password</label>
      <div class="password-field">
        <input type="password" name="password" id="resetPassword" minlength="8" required autocomplete="new-password">
        <button type="button" class="password-toggle" data-toggle-password="resetPassword" aria-label="Show password">Show</button>
      </div>
      <p class="field-hint">At least 8 characters.</p>
      <label>Confirm Password</label>
      <div class="password-field">
        <input type="password" name="confirm_password" id="resetPasswordConfirm" minlength="8" required autocomplete="new-password">
        <button type="button" class="password-toggle" data-toggle-password="resetPasswordConfirm" aria-label="Show password">Show</button>
      </div>
      <button type="submit" class="btn btn-cta btn-block">Update Password</button>
    </form>
    <p class="auth-switch"><a href="<?= APP_URL ?>/auth/login.php">Back to Log In</a></p>
  </div>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
