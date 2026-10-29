<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$user = is_logged_in() ? current_user() : null;
$userId = is_logged_in() ? (int)($user['user_id'] ?? 0) : 0;
$errors = [];
$success = null;

if (is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'profile') {
    require_csrf();
    $fullName = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim(strtolower($_POST['email'] ?? ''));

    if ($fullName === '') $errors[] = 'Full name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';

    if (empty($errors)) {
        $dupStmt = $db->prepare("SELECT user_id FROM users WHERE email = :email AND user_id != :uid");
        $dupStmt->execute(['email' => $email, 'uid' => $userId]);
        if ($dupStmt->fetch()) $errors[] = 'That email is already used by another account.';
    }

    if (empty($errors)) {
        $stmt = $db->prepare("UPDATE users SET full_name = :name, phone = :phone, email = :email WHERE user_id = :uid");
        $stmt->execute(['name' => $fullName, 'phone' => $phone, 'email' => $email, 'uid' => $userId]);
        $_SESSION['user']['full_name'] = $fullName;
        $_SESSION['user']['email'] = $email;
        $_SESSION['user']['phone'] = $phone;
        $success = 'Profile updated successfully.';
        $user = $_SESSION['user'];
    }
}

if (is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'password') {
    require_csrf();
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $stmt = $db->prepare("SELECT password_hash FROM users WHERE user_id = :uid");
    $stmt->execute(['uid' => $userId]);
    $hash = $stmt->fetch()['password_hash'];

    if (!password_verify($current, $hash)) {
        $errors[] = 'Current password is incorrect.';
    } elseif (strlen($new) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $errors[] = 'New passwords do not match.';
    } else {
        $db->prepare("UPDATE users SET password_hash = :hash WHERE user_id = :uid")
           ->execute(['hash' => password_hash($new, PASSWORD_DEFAULT), 'uid' => $userId]);
        $success = 'Password changed successfully.';
    }
}

if (is_logged_in()) {
    $stmt = $db->prepare("SELECT * FROM users WHERE user_id = :uid");
    $stmt->execute(['uid' => $userId]);
    $user = $stmt->fetch();
}

$pageTitle = 'My Profile — MasakaMart';
require_once __DIR__ . '/../includes/header.php';
?>
<section class="container section katale-profile-page">
  <div class="page-header-row">
    <?= katale_global_back_button(APP_URL . '/index.php') ?>
    <h1>Profile</h1>
    <div class="header-spacer"></div>
  </div>
  <?php if ($errors): ?><div class="flash flash-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($success): ?><div class="flash flash-success"><?= e($success) ?></div><?php endif; ?>

  <?php if (!is_logged_in()): ?>
    <div class="katale-profile-header">
      <div class="katale-profile-avatar katale-profile-avatar-guest">M</div>
      <div class="katale-profile-details">
        <h1>Welcome to MasakaMart</h1>
        <p class="katale-profile-meta"><?= e(APP_TAGLINE) ?></p>
      </div>
    </div>

    <div class="katale-profile-actions">
      <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-cta">Login</a>
      <a href="<?= APP_URL ?>/auth/register.php" class="btn btn-outline">Create Account</a>
    </div>

    <div class="katale-profile-section">
      <h2>Shop</h2>
      <div class="katale-profile-links">
        <a class="katale-profile-link" href="<?= APP_URL ?>/products.php">
          <span class="katale-profile-link-main">
            <span class="katale-profile-link-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7.5h16"/><path d="M6 7.5V18a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7.5"/><path d="M9 11.5h6"/></svg>
            </span>
            <span class="katale-profile-link-copy"><strong>All Products</strong><small>Browse fresh essentials</small></span>
          </span>
          <span class="katale-profile-link-arrow">›</span>
        </a>
        <a class="katale-profile-link" href="<?= APP_URL ?>/index.php#baskets">
          <span class="katale-profile-link-main">
            <span class="katale-profile-link-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8.5h18l-1.5 11h-15L3 8.5Z"/><path d="M8 8.5V6.8A4 4 0 0 1 12 3a4 4 0 0 1 4 3.8v1.7"/></svg>
            </span>
            <span class="katale-profile-link-copy"><strong>Ready-Made Baskets</strong><small>Popular market bundles</small></span>
          </span>
          <span class="katale-profile-link-arrow">›</span>
        </a>
      </div>
    </div>

    <div class="katale-profile-section">
      <h2>Support</h2>
      <div class="katale-profile-links">
        <a class="katale-profile-link" href="<?= APP_URL ?>/support/new_ticket.php">
          <span class="katale-profile-link-main">
            <span class="katale-profile-link-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7.5A2.5 2.5 0 0 1 6.5 5h11A2.5 2.5 0 0 1 20 7.5v8A2.5 2.5 0 0 1 17.5 18H8l-4 3v-3.5A2.5 2.5 0 0 1 4 15.5v-8Z"/></svg>
            </span>
            <span class="katale-profile-link-copy"><strong>Contact / Support</strong><small>We’re here to help</small></span>
          </span>
          <span class="katale-profile-link-arrow">›</span>
        </a>
        <a class="katale-profile-link" href="<?= APP_URL ?>/policies/delivery.php">
          <span class="katale-profile-link-main">
            <span class="katale-profile-link-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7.5h12v9H3z"/><path d="M15 10h3l3 3v3.5h-6"/><circle cx="8" cy="17.5" r="1.5"/><circle cx="18" cy="17.5" r="1.5"/></svg>
            </span>
            <span class="katale-profile-link-copy"><strong>Delivery Policy</strong><small>Service areas and timing</small></span>
          </span>
          <span class="katale-profile-link-arrow">›</span>
        </a>
        <a class="katale-profile-link" href="<?= APP_URL ?>/policies/returns.php">
          <span class="katale-profile-link-main">
            <span class="katale-profile-link-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8V4h4"/><path d="M5 4c2-2 5-2 7 0l7 7"/><path d="M19 16v4h-4"/><path d="M19 20c-2 2-5 2-7 0L5 13"/></svg>
            </span>
            <span class="katale-profile-link-copy"><strong>Returns &amp; Refunds</strong><small>Easy terms and support</small></span>
          </span>
          <span class="katale-profile-link-arrow">›</span>
        </a>
      </div>
    </div>

    <div class="katale-profile-section">
      <h2>Legal</h2>
      <div class="katale-profile-links">
        <a class="katale-profile-link" href="<?= APP_URL ?>/policies/privacy.php">
          <span class="katale-profile-link-main">
            <span class="katale-profile-link-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.5 5 6v5c0 4 2.7 7.7 7 9.5 4.3-1.8 7-5.5 7-9.5v-5l-7-2.5Z"/><path d="M9.5 12.5 11 14l3.5-4"/></svg>
            </span>
            <span class="katale-profile-link-copy"><strong>Privacy Policy</strong></span>
          </span>
          <span class="katale-profile-link-arrow">›</span>
        </a>
        <a class="katale-profile-link" href="<?= APP_URL ?>/policies/terms.php">
          <span class="katale-profile-link-main">
            <span class="katale-profile-link-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4.5h8l4 4V18a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6.5a2 2 0 0 1 2-2Z"/><path d="M15 4.5V9h4"/><path d="M8 12h8M8 15h8"/></svg>
            </span>
            <span class="katale-profile-link-copy"><strong>Terms &amp; Conditions</strong></span>
          </span>
          <span class="katale-profile-link-arrow">›</span>
        </a>
      </div>
    </div>
  <?php else: ?>
    <div class="katale-profile-header">
      <div class="katale-profile-avatar"><?= e(substr($user['full_name'] ?? 'K', 0, 1)) ?></div>
      <div class="katale-profile-details">
        <h1><?= e($user['full_name'] ?? 'Customer') ?></h1>
        <p class="katale-profile-meta"><?= e($user['email'] ?? '') ?></p>
        <p class="katale-profile-meta"><?= e($user['phone'] ?? 'No phone number added yet') ?></p>
      </div>
      <a href="#profile-details" class="btn btn-outline btn-sm">Edit Profile</a>
    </div>

    <div class="katale-profile-section">
      <h2>My MasakaMart</h2>
      <div class="katale-profile-links">
        <a class="katale-profile-link" href="<?= APP_URL ?>/orders/order_history.php">
          <span class="katale-profile-link-main">
            <span class="katale-profile-link-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4.5h10l3 3V18a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6.5a2 2 0 0 1 2-2Z"/><path d="M14 4.5V9h4"/><path d="M8 12h8M8 15h8"/></svg>
            </span>
            <span class="katale-profile-link-copy"><strong>My Orders</strong><small>Track your purchases</small></span>
          </span>
          <span class="katale-profile-link-arrow">›</span>
        </a>
        <a class="katale-profile-link" href="<?= APP_URL ?>/cart/cart.php">
          <span class="katale-profile-link-main">
            <span class="katale-profile-link-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="19" r="1.5"/><circle cx="18" cy="19" r="1.5"/><path d="M3 4h2l2.5 9.2a1 1 0 0 0 1 .8h8.8a1 1 0 0 0 1-.8L19 7H7"/></svg>
            </span>
            <span class="katale-profile-link-copy"><strong>My Cart</strong><small>Review your basket</small></span>
          </span>
          <span class="katale-profile-link-arrow">›</span>
        </a>
        <a class="katale-profile-link" href="<?= APP_URL ?>/customer/wishlist.php">
          <span class="katale-profile-link-main">
            <span class="katale-profile-link-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20.2 4.7 12.8a4.3 4.3 0 0 1 6.1-6.1L12 7l1.2-1.3a4.3 4.3 0 1 1 6.1 6.1L12 20.2Z"/></svg>
            </span>
            <span class="katale-profile-link-copy"><strong>Wishlist</strong><small>Saved favourites</small></span>
          </span>
          <span class="katale-profile-link-arrow">›</span>
        </a>
        <a class="katale-profile-link" href="<?= APP_URL ?>/index.php#baskets">
          <span class="katale-profile-link-main">
            <span class="katale-profile-link-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8.5h18l-1.5 11h-15L3 8.5Z"/><path d="M8 8.5V6.8A4 4 0 0 1 12 3a4 4 0 0 1 4 3.8v1.7"/></svg>
            </span>
            <span class="katale-profile-link-copy"><strong>Ready-Made Baskets</strong><small>Quick household bundles</small></span>
          </span>
          <span class="katale-profile-link-arrow">›</span>
        </a>
      </div>
    </div>

    <div class="katale-profile-section" id="profile-details">
      <h2>Account Information</h2>
      <form method="post" class="auth-form">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="profile">
        <label>Full Name</label>
        <input type="text" name="full_name" value="<?= e($user['full_name'] ?? '') ?>" required>
        <label>Phone</label>
        <input type="text" name="phone" value="<?= e($user['phone'] ?? '') ?>">
        <label>Email</label>
        <input type="email" name="email" value="<?= e($user['email'] ?? '') ?>" required>
        <button type="submit" class="btn btn-cta">Save Changes</button>
      </form>

      <div class="katale-profile-contact">
        <form method="post" class="auth-form">
          <?= csrf_field() ?>
          <input type="hidden" name="form" value="password">
          <h2>Change Password</h2>
          <label>Current Password</label>
          <div class="password-field">
            <input type="password" name="current_password" id="currentPassword" required>
            <button type="button" class="password-toggle" data-toggle-password="currentPassword" aria-label="Show password">Show</button>
          </div>
          <label>New Password</label>
          <div class="password-field">
            <input type="password" name="new_password" id="newPassword" minlength="8" required>
            <button type="button" class="password-toggle" data-toggle-password="newPassword" aria-label="Show password">Show</button>
          </div>
          <label>Confirm New Password</label>
          <div class="password-field">
            <input type="password" name="confirm_password" id="confirmPassword" minlength="8" required>
            <button type="button" class="password-toggle" data-toggle-password="confirmPassword" aria-label="Show password">Show</button>
          </div>
          <button type="submit" class="btn">Change Password</button>
        </form>
      </div>
    </div>

    <div class="katale-profile-section">
      <h2>Notifications</h2>
      <div class="katale-profile-links">
        <button type="button" id="katalePushToggle" class="katale-profile-link" style="width:100%;text-align:left;background:none;border:none;cursor:pointer;">
          <span class="katale-profile-link-main">
            <span class="katale-profile-link-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            </span>
            <span class="katale-profile-link-copy"><strong id="katalePushToggleLabel">Turn on order notifications</strong><small>Get notified on this device when your payment or order status changes</small></span>
          </span>
          <span class="katale-profile-link-arrow">›</span>
        </button>
      </div>
    </div>

    <div class="katale-profile-section">
      <h2>Shop</h2>
      <div class="katale-profile-links">
        <a class="katale-profile-link" href="<?= APP_URL ?>/products.php">
          <span class="katale-profile-link-main">
            <span class="katale-profile-link-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7.5h16"/><path d="M6 7.5V18a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7.5"/><path d="M9 11.5h6"/></svg>
            </span>
            <span class="katale-profile-link-copy"><strong>All Products</strong><small>Browse the market</small></span>
          </span>
          <span class="katale-profile-link-arrow">›</span>
        </a>
        <a class="katale-profile-link" href="<?= APP_URL ?>/index.php#baskets">
          <span class="katale-profile-link-main">
            <span class="katale-profile-link-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8.5h18l-1.5 11h-15L3 8.5Z"/><path d="M8 8.5V6.8A4 4 0 0 1 12 3a4 4 0 0 1 4 3.8v1.7"/></svg>
            </span>
            <span class="katale-profile-link-copy"><strong>Ready-Made Baskets</strong><small>Curated bundles</small></span>
          </span>
          <span class="katale-profile-link-arrow">›</span>
        </a>
      </div>
    </div>

    <div class="katale-profile-section">
      <h2>Support</h2>
      <div class="katale-profile-links">
        <a class="katale-profile-link" href="<?= APP_URL ?>/support/new_ticket.php">
          <span class="katale-profile-link-main">
            <span class="katale-profile-link-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7.5A2.5 2.5 0 0 1 6.5 5h11A2.5 2.5 0 0 1 20 7.5v8A2.5 2.5 0 0 1 17.5 18H8l-4 3v-3.5A2.5 2.5 0 0 1 4 15.5v-8Z"/></svg>
            </span>
            <span class="katale-profile-link-copy"><strong>Contact / Support</strong><small>Need help?</small></span>
          </span>
          <span class="katale-profile-link-arrow">›</span>
        </a>
        <a class="katale-profile-link" href="<?= APP_URL ?>/policies/delivery.php">
          <span class="katale-profile-link-main">
            <span class="katale-profile-link-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7.5h12v9H3z"/><path d="M15 10h3l3 3v3.5h-6"/><circle cx="8" cy="17.5" r="1.5"/><circle cx="18" cy="17.5" r="1.5"/></svg>
            </span>
            <span class="katale-profile-link-copy"><strong>Delivery Policy</strong><small>Service areas</small></span>
          </span>
          <span class="katale-profile-link-arrow">›</span>
        </a>
        <a class="katale-profile-link" href="<?= APP_URL ?>/policies/returns.php">
          <span class="katale-profile-link-main">
            <span class="katale-profile-link-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8V4h4"/><path d="M5 4c2-2 5-2 7 0l7 7"/><path d="M19 16v4h-4"/><path d="M19 20c-2 2-5 2-7 0L5 13"/></svg>
            </span>
            <span class="katale-profile-link-copy"><strong>Returns &amp; Refunds</strong><small>Fast support</small></span>
          </span>
          <span class="katale-profile-link-arrow">›</span>
        </a>
      </div>
    </div>

    <div class="katale-profile-section">
      <h2>Legal</h2>
      <div class="katale-profile-links">
        <a class="katale-profile-link" href="<?= APP_URL ?>/policies/privacy.php">
          <span class="katale-profile-link-main">
            <span class="katale-profile-link-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.5 5 6v5c0 4 2.7 7.7 7 9.5 4.3-1.8 7-5.5 7-9.5v-5l-7-2.5Z"/><path d="M9.5 12.5 11 14l3.5-4"/></svg>
            </span>
            <span class="katale-profile-link-copy"><strong>Privacy Policy</strong></span>
          </span>
          <span class="katale-profile-link-arrow">›</span>
        </a>
        <a class="katale-profile-link" href="<?= APP_URL ?>/policies/terms.php">
          <span class="katale-profile-link-main">
            <span class="katale-profile-link-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4.5h8l4 4V18a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6.5a2 2 0 0 1 2-2Z"/><path d="M15 4.5V9h4"/><path d="M8 12h8M8 15h8"/></svg>
            </span>
            <span class="katale-profile-link-copy"><strong>Terms &amp; Conditions</strong></span>
          </span>
          <span class="katale-profile-link-arrow">›</span>
        </a>
      </div>
    </div>

    <div class="katale-profile-section katale-profile-account-section">
      <h2>Account</h2>
      <form method="post" action="<?= APP_URL ?>/auth/logout.php" class="katale-logout-form">
        <?= csrf_field() ?>
        <button type="submit" class="btn katale-logout-btn">Logout</button>
      </form>
    </div>
  <?php endif; ?>

  <div class="katale-profile-about">
    <h3>MasakaMart</h3>
    <p><strong><?= e(APP_TAGLINE) ?></strong></p>
    <p>Based in Masaka, Uganda. Fresh local food and everyday market essentials, delivered across Kampala and beyond.</p>
  </div>

  <p class="katale-profile-copyright">© <?= date('Y') ?> MasakaMart. All rights reserved.</p>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
