<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$errors = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = trim(strtolower($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    $stmt = $db->prepare("SELECT * FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $errors[] = 'Incorrect email or password.';
    } elseif (!(int)$user['is_active']) {
        $errors[] = 'This account has been deactivated. Please contact support.';
    } else {
        $_SESSION['user'] = ['user_id' => $user['user_id'], 'full_name' => $user['full_name'], 'email' => $user['email']];
        $cart->mergeGuestCartIntoUser((int)$user['user_id']);

        $redirectTo = $_SESSION['redirect_after_login'] ?? (APP_URL . '/index.php');
        unset($_SESSION['redirect_after_login']);
        flash('success', 'Welcome back, ' . explode(' ', $user['full_name'])[0] . '!');
        redirect($redirectTo);
    }
}

$pageTitle = 'Log In — MasakaMart';
require_once __DIR__ . '/../includes/header.php';
?>
<section class="container section auth-section">
  <div class="auth-card">
    <?php require __DIR__ . '/../includes/auth_brand.php'; ?>
    <h1>Log In</h1>
    <?php if ($errors): ?>
      <div class="flash flash-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post" class="auth-form">
      <?= csrf_field() ?>
      <label>Email Address</label>
      <input type="email" name="email" value="<?= e($email) ?>" required autofocus>
      <label>Password</label>
      <div class="password-field">
        <input type="password" name="password" id="loginPassword" required autocomplete="current-password">
        <button type="button" class="password-toggle" data-toggle-password="loginPassword" aria-label="Show password">Show</button>
      </div>
      <p class="auth-forgot"><a href="<?= APP_URL ?>/auth/forgot_password.php">Forgot Password?</a></p>
      <button type="submit" class="btn btn-cta btn-block">Log In</button>
    </form>
    <p class="auth-switch">New to MasakaMart? <a href="<?= APP_URL ?>/auth/register.php">Create an account</a></p>
  </div>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
