<?php
// Expects $db, $cartCount to already be set by bootstrap.php
$categories = $db->query(
  "SELECT category_id, name, slug, image_path, description FROM categories WHERE is_active = 1 ORDER BY sort_order, name"
)->fetchAll();
$pageTitle = $pageTitle ?? APP_NAME . ' — ' . APP_TAGLINE;
$metaDescription = $metaDescription ?? 'MasakaMart — Fresh Food. Better Living. Based in Masaka, Uganda. Groceries and essentials delivered across Kampala and beyond.';
$wishlistCount = 0;
$wishlistProductIds = [];
if (is_logged_in()) {
  $uid = (int)current_user()['user_id'];
  $wishlistProductIds = get_user_wishlist_product_ids($db, $uid);
  $wishlistCount = count($wishlistProductIds);
}
$GLOBALS['katale_wishlist_product_ids'] = $wishlistProductIds;
$currentPage = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$isHomePage = $currentPage === 'index.php';
$isCategoriesPage = in_array($currentPage, ['products.php', 'product.php'], true);
$isCartPage = in_array($currentPage, ['cart.php', 'checkout.php', 'checkout_process.php'], true);
$isWishlistPage = in_array($currentPage, ['wishlist.php'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e($metaDescription) ?>">
<?php katale_render_seo_head(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/ui-refresh.css?v=<?= filemtime(__DIR__ . '/../assets/css/ui-refresh.css') ?>">
<link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/assets/images/favicon.svg">
</head>
<body>
<div class="promo-bar">Fresh groceries delivered fast — <strong>Masaka &amp; Kampala</strong></div>
<header class="site-header">
  <div class="header-top container">
    <button class="nav-toggle" id="navToggle" aria-label="Open menu" type="button">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
    </button>
    <a href="<?= APP_URL ?>/index.php" class="brand">
      <img src="<?= APP_URL ?>/assets/images/logo-badge.svg" alt="" class="brand-mark" width="44" height="44">
      <span class="brand-text"><span class="brand-name">Masaka<em>Mart</em></span><span class="brand-tagline"><?= e(APP_TAGLINE) ?></span></span>
    </a>
    <form class="search-form search-box" action="<?= APP_URL ?>/products.php" method="get" role="search">
      <span class="search-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
      </span>
      <input type="search" name="q" placeholder="Search for products..." value="<?= e($_GET['q'] ?? '') ?>" autocomplete="off" id="searchInput" aria-label="Search products">
      <button type="submit" class="search-submit" aria-label="Search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
      </button>
      <div class="search-suggestions" id="searchSuggestions"></div>
    </form>
    <div class="header-actions">
      <?php if (is_logged_in()):
        $userId = (int)current_user()['user_id'];
        $notificationCount = count_unread_notifications($db, $userId);
        $recentNotifications = fetch_recent_notifications($db, $userId, 5);
      ?>
      <div class="notification-wrap">
        <button type="button" class="notification-toggle" aria-label="Open notifications">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 3.5a4 4 0 0 1 4 4v2.2c0 1.1.38 2.16 1.08 3l1.05 1.3a1 1 0 0 1-.8 1.6H6.67a1 1 0 0 1-.8-1.6l1.05-1.3A4.7 4.7 0 0 0 8 9.7V7.5a4 4 0 0 1 4-4zm0 17a2.5 2.5 0 0 1-2.45-2h4.9A2.5 2.5 0 0 1 12 20.5z"/>
          </svg>
          <?php if ($notificationCount > 0): ?><span class="notification-badge"><?= (int)$notificationCount ?></span><?php endif; ?>
        </button>
        <div class="notification-panel" id="notificationPanel">
          <div class="notification-panel-header">
            <strong>Notifications</strong>
            <a href="<?= APP_URL ?>/notifications.php">View all</a>
          </div>
          <?php if (empty($recentNotifications)): ?>
            <div class="notification-item empty">No notifications yet.</div>
          <?php else: ?>
            <?php foreach ($recentNotifications as $n):
              $orderNum = notification_order_number_for($db, $n);
            ?>
              <a href="<?= e(notification_read_url((int)$n['notification_id'])) ?>" class="notification-item <?= (int)$n['is_read'] === 0 ? 'unread' : '' ?>">
                <div class="notification-item-brand"><?= e(notification_brand_label()) ?></div>
                <div class="notification-meta">
                  <span class="notification-icon"><?= e(notification_icon_for_type($n['type'])) ?></span>
                  <div class="notification-copy">
                    <span class="notification-type-label"><?= e(notification_type_label($n['type'])) ?></span>
                    <span class="notification-title"><?= e($n['title']) ?></span>
                    <?php if ($orderNum): ?><span class="notification-order-ref"><?= e($orderNum) ?></span><?php endif; ?>
                  </div>
                </div>
                <small><?= e(date('d M Y, H:i', strtotime($n['created_at']))) ?></small>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <a href="<?= APP_URL ?>/customer/wishlist.php" class="icon-link icon-link-svg wishlist-link" aria-label="Wishlist">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 20.2 4.7 12.8a4.3 4.3 0 0 1 6.1-6.1L12 7l1.2-1.3a4.3 4.3 0 1 1 6.1 6.1L12 20.2Z"/></svg>
        <span class="icon-link-label">Wishlist</span>
        <?php if ($wishlistCount > 0): ?><span class="header-badge header-badge-wishlist"><?= (int)$wishlistCount ?></span><?php endif; ?>
      </a>
      <a href="<?= APP_URL ?>/<?= is_logged_in() ? 'customer/profile.php' : 'auth/login.php' ?>" class="icon-link icon-link-svg profile-link" aria-label="<?= is_logged_in() ? 'Profile' : 'Account' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M19 20.5a7 7 0 0 0-14 0"/><circle cx="12" cy="8" r="4"/></svg>
        <span class="icon-link-label"><?= is_logged_in() ? e(explode(' ', current_user()['full_name'])[0]) : 'Account' ?></span>
      </a>
      <a href="<?= APP_URL ?>/cart/cart.php" class="icon-link icon-link-svg cart-link" aria-label="Cart">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="19" r="1.5"/><circle cx="18" cy="19" r="1.5"/><path d="M3 4h2l2.5 9.2a1 1 0 0 0 1 .8h8.8a1 1 0 0 0 1-.8L19 7H7"/></svg>
        <span class="icon-link-label">Cart</span>
        <span class="cart-count header-badge" id="cartCount"><?= (int)$cartCount ?></span>
      </a>
    </div>
  </div>
  <nav class="category-nav" id="categoryNav">
    <a href="<?= APP_URL ?>/products.php" class="<?= empty($_GET['category']) ? 'active' : '' ?>">All Products</a>
    <?php foreach ($categories as $cat): ?>
      <a href="<?= APP_URL ?>/products.php?category=<?= (int)$cat['category_id'] ?>"
         class="<?= (isset($_GET['category']) && (int)$_GET['category'] === (int)$cat['category_id']) ? 'active' : '' ?>">
        <?= e($cat['name']) ?>
      </a>
    <?php endforeach; ?>
  </nav>
</header>
<?php if ($msg = flash('success')): ?><div class="flash flash-success container"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msg = flash('error')): ?><div class="flash flash-error container"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msg = flash('notice')): ?><div class="flash flash-notice container"><?= e($msg) ?></div><?php endif; ?>
<main>
