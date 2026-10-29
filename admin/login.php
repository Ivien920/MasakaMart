<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';

if (current_admin()) {
    redirect(APP_URL . '/admin/dashboard.php');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = trim(strtolower($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    $stmt = $db->prepare("SELECT * FROM admins WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        $errors[] = 'Incorrect email or password.';
    } elseif (!(int)$admin['is_active']) {
        $errors[] = 'This admin account has been deactivated.';
    } else {
        $_SESSION['admin'] = [
            'admin_id' => $admin['admin_id'],
            'full_name' => $admin['full_name'],
            'email' => $admin['email'],
            'role' => $admin['role'],
        ];
        $db->prepare("UPDATE admins SET last_login_at = NOW() WHERE admin_id = :id")->execute(['id' => $admin['admin_id']]);
        log_admin_activity($db, 'login');

        if ((int)$admin['must_change_password']) {
            redirect(APP_URL . '/admin/change_password.php');
        }
        redirect(APP_URL . '/admin/dashboard.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login — MasakaMart</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body class="admin-login-body">
  <div class="auth-card admin-login-card">
    <h1>MasakaMart Admin</h1>
    <?php if ($errors): ?><div class="flash flash-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" class="auth-form">
      <?= csrf_field() ?>
      <label>Email</label>
      <input type="email" name="email" required autofocus>
      <label>Password</label>
      <input type="password" name="password" required>
      <button type="submit" class="btn btn-cta btn-block">Log In</button>
    </form>
  </div>
</body>
</html>
