<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$errors = [];
$old = ['full_name' => '', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $old['full_name'] = trim($_POST['full_name'] ?? '');
    $old['email'] = trim(strtolower($_POST['email'] ?? ''));
    $old['phone'] = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($old['full_name'] === '') $errors[] = 'Full name is required.';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
    if (!preg_match('/^\+?[0-9]{9,15}$/', preg_replace('/\s+/', '', $old['phone']))) {
        $errors[] = 'Please enter a valid phone number, e.g. 0772123456.';
    }
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        $stmt = $db->prepare("SELECT user_id FROM users WHERE email = :email");
        $stmt->execute(['email' => $old['email']]);
        if ($stmt->fetch()) {
            $errors[] = 'An account with this email already exists. Please log in instead.';
        }
    }

    if (empty($errors)) {
        $stmt = $db->prepare(
            "INSERT INTO users (full_name, email, phone, password_hash) VALUES (:name, :email, :phone, :hash)"
        );
        $stmt->execute([
            'name' => $old['full_name'],
            'email' => $old['email'],
            'phone' => $old['phone'],
            'hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        $userId = (int)$db->lastInsertId();

        $_SESSION['user'] = ['user_id' => $userId, 'full_name' => $old['full_name'], 'email' => $old['email']];
        $cart->mergeGuestCartIntoUser($userId);

        $welcomeSent = katale_send_registration_email($old['email'], $old['full_name']);
        if (!$welcomeSent) {
            error_log('[EMAIL] registration welcome email failed for ' . substr($old['email'], 0, 2) . '***');
        }

        $redirectTo = $_SESSION['redirect_after_login'] ?? (APP_URL . '/index.php');
        unset($_SESSION['redirect_after_login']);
        flash('success', 'Welcome to MasakaMart, ' . explode(' ', $old['full_name'])[0] . '!');
        redirect($redirectTo);
    }
}

$pageTitle = 'Create Account — MasakaMart';
require_once __DIR__ . '/../includes/header.php';
?>
<section class="container section auth-section">
  <div class="auth-card">
    <?php require __DIR__ . '/../includes/auth_brand.php'; ?>
    <h1>Create Your Account</h1>
    <?php if ($errors): ?>
      <div class="flash flash-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post" class="auth-form">
      <?= csrf_field() ?>
      <label>Full Name</label>
      <input type="text" name="full_name" value="<?= e($old['full_name']) ?>" required>
      <label>Phone Number</label>
      <input type="text" name="phone" placeholder="0772123456" value="<?= e($old['phone']) ?>" required>
      <label>Email Address</label>
      <input type="email" name="email" value="<?= e($old['email']) ?>" required>
      <label>Password</label>
      <div class="password-field">
        <input type="password" name="password" id="registerPassword" minlength="8" required>
        <button type="button" class="password-toggle" data-toggle-password="registerPassword" aria-label="Show password">Show</button>
      </div>
      <label>Confirm Password</label>
      <div class="password-field">
        <input type="password" name="confirm_password" id="registerConfirmPassword" minlength="8" required>
        <button type="button" class="password-toggle" data-toggle-password="registerConfirmPassword" aria-label="Show password">Show</button>
      </div>
      <p class="auth-terms">By creating an account, you acknowledge the <a href="<?= APP_URL ?>/policies/terms.php">MasakaMart Terms and Conditions</a> and <a href="<?= APP_URL ?>/policies/privacy.php">Privacy Policy</a>. Order acceptance is confirmed separately at checkout.</p>
      <button type="submit" class="btn btn-cta btn-block">Create Account</button>
    </form>
    <p class="auth-switch">Already have an account? <a href="<?= APP_URL ?>/auth/login.php">Log in</a></p>
  </div>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
