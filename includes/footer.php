</main>
<nav class="katale-bottom-nav" aria-label="Mobile navigation">
  <a href="<?= APP_URL ?>/index.php" class="katale-nav-item <?= basename($_SERVER['PHP_SELF'] ?? 'index.php') === 'index.php' ? 'is-active' : '' ?>" aria-label="Home" <?= basename($_SERVER['PHP_SELF'] ?? 'index.php') === 'index.php' ? 'aria-current="page"' : '' ?>>
    <span class="katale-nav-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20h14V9.5"/></svg>
    </span>
    <span class="katale-nav-label">Home</span>
  </a>
  <a href="<?= APP_URL ?>/products.php" class="katale-nav-item <?= in_array(basename($_SERVER['PHP_SELF'] ?? 'index.php'), ['products.php', 'product.php'], true) ? 'is-active' : '' ?>" aria-label="Categories" <?= in_array(basename($_SERVER['PHP_SELF'] ?? 'index.php'), ['products.php', 'product.php'], true) ? 'aria-current="page"' : '' ?>>
    <span class="katale-nav-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="4" rx="1.5"/><rect x="14" y="11" width="7" height="10" rx="1.5"/><rect x="3" y="12" width="7" height="9" rx="1.5"/></svg>
    </span>
    <span class="katale-nav-label">Categories</span>
  </a>
  <a href="<?= APP_URL ?>/cart/cart.php" class="katale-nav-item katale-nav-cart-item <?= in_array(basename($_SERVER['PHP_SELF'] ?? 'index.php'), ['cart.php', 'checkout.php', 'checkout_process.php'], true) ? 'is-active' : '' ?>" aria-label="Cart" <?= in_array(basename($_SERVER['PHP_SELF'] ?? 'index.php'), ['cart.php', 'checkout.php', 'checkout_process.php'], true) ? 'aria-current="page"' : '' ?>>
    <span class="katale-cart-button" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="19" r="1.5"/><circle cx="18" cy="19" r="1.5"/><path d="M3 4h2l2.5 9.2a1 1 0 0 0 1 .8h8.8a1 1 0 0 0 1-.8L19 7H7"/></svg>
      <?php if ((int)($cartCount ?? 0) > 0): ?><span class="katale-cart-badge" id="kataleMobileCartBadge"><?= (int)($cartCount ?? 0) ?></span><?php endif; ?>
    </span>
    <span class="katale-nav-label">Cart</span>
  </a>
  <a href="<?= APP_URL ?>/customer/wishlist.php" class="katale-nav-item <?= basename($_SERVER['PHP_SELF'] ?? 'index.php') === 'wishlist.php' ? 'is-active' : '' ?>" aria-label="Wishlist" <?= basename($_SERVER['PHP_SELF'] ?? 'index.php') === 'wishlist.php' ? 'aria-current="page"' : '' ?>>
    <span class="katale-nav-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20.2 4.7 12.8a4.3 4.3 0 0 1 6.1-6.1L12 7l1.2-1.3a4.3 4.3 0 1 1 6.1 6.1L12 20.2Z"/></svg>
    </span>
    <?php if ((int)($wishlistCount ?? 0) > 0): ?><span class="katale-wishlist-badge" id="kataleMobileWishlistBadge"><?= (int)($wishlistCount ?? 0) ?></span><?php endif; ?>
    <span class="katale-nav-label">Wishlist</span>
  </a>
  <a href="<?= APP_URL ?>/customer/profile.php" class="katale-nav-item <?= basename($_SERVER['PHP_SELF'] ?? 'index.php') === 'profile.php' ? 'is-active' : '' ?>" aria-label="Profile" <?= basename($_SERVER['PHP_SELF'] ?? 'index.php') === 'profile.php' ? 'aria-current="page"' : '' ?>>
    <span class="katale-nav-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 20.5a7 7 0 0 0-14 0"/><circle cx="12" cy="8" r="4"/></svg>
    </span>
    <span class="katale-nav-label">Profile</span>
  </a>
</nav>
<footer class="site-footer">
  <div class="container footer-grid">
    <div>
      <span class="brand-name">Masaka<span class="brand-accent">Mart</span></span>
      <p class="footer-tagline"><?= e(APP_TAGLINE) ?></p>
      <p>Based in Masaka, Uganda — fresh local food and everyday essentials, delivered to your door.</p>
      <a class="whatsapp-link whatsapp-icon-link footer-whatsapp" href="https://wa.me/256779860256" target="_blank" rel="noopener noreferrer" aria-label="Chat with MasakaMart on WhatsApp" title="Chat with MasakaMart on WhatsApp">
        <svg class="whatsapp-icon" viewBox="0 0 48 48" aria-hidden="true" focusable="false">
          <circle cx="24" cy="24" r="24" fill="#25D366"></circle>
          <path fill="#ffffff" d="M33.5 28.8c-.5-.3-2.9-1.5-3.4-1.7-.5-.2-.8-.3-1.1.2-.3.5-1.2 1.7-1.5 2.1-.3.4-.5.4-1 .1-.5-.3-2.1-.8-4-2.5-1.5-1.3-2.4-2.9-2.7-3.4-.3-.5 0-.8.2-1 .2-.2.5-.6.7-.8.2-.3.3-.5.5-.8.2-.3.1-.5 0-.8s-1.1-2.8-1.5-3.8c-.4-1-.8-.9-1.1-.9h-.9c-.3 0-.8.1-1.2.6s-1.6 1.5-1.6 3.7 1.6 4.4 1.8 4.7c.2.3 3.2 4.9 7.8 6.8 1.1.5 2 .8 2.7 1 .9.3 1.7.2 2.4.1.7-.1 2.1-.9 2.4-1.7.3-.8.3-1.5.2-1.7-.1-.1-.4-.2-.9-.4z"/>
        </svg>
        <span class="sr-only">Chat on WhatsApp</span>
      </a>
    </div>
    <div>
      <h4>Shop</h4>
      <a href="<?= APP_URL ?>/products.php">All Products</a>
      <a href="<?= APP_URL ?>/index.php#baskets">Ready-Made Baskets</a>
      <a href="<?= APP_URL ?>/cart/cart.php">My Cart</a>
    </div>
    <div>
      <h4>Support</h4>
      <a href="<?= APP_URL ?>/support/new_ticket.php">Contact / Support</a>
      <a href="<?= APP_URL ?>/policies/delivery.php">Delivery Policy</a>
      <a href="<?= APP_URL ?>/policies/returns.php">Returns &amp; Refunds</a>
    </div>
    <div>
      <h4>Legal</h4>
      <a href="<?= APP_URL ?>/policies/privacy.php">Privacy Policy</a>
      <a href="<?= APP_URL ?>/policies/terms.php">Terms &amp; Conditions</a>
    </div>
  </div>
  <p class="footer-bottom">&copy; <?= date('Y') ?> MasakaMart. All rights reserved. Developed by <a href="https://techspheresystems.org" target="_blank" rel="noopener noreferrer">TechSphere Systems</a>.</p>
</footer>
<div class="toast-stack" id="toastStack" aria-live="polite" aria-atomic="true"></div>
<?php katale_render_analytics_consent(); ?>
<input type="hidden" id="kataleCsrfToken" value="<?= e(csrf_token()) ?>">
<script>const APP_BASE_URL = '<?= APP_URL ?>';</script>
<script>window.KATALE_WISHLIST_IDS = <?= json_encode(array_values($wishlistProductIds ?? [])) ?>;</script>
<script>window.KATALE_LOGGED_IN = <?= is_logged_in() ? 'true' : 'false' ?>;</script>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= filemtime(__DIR__ . '/../assets/js/main.js') ?>"></script>
<?php if (is_logged_in() && defined('VAPID_PUBLIC_KEY') && VAPID_PUBLIC_KEY !== ''): ?>
<script>
  window.KATALE_VAPID_PUBLIC_KEY = <?= json_encode(VAPID_PUBLIC_KEY) ?>;
  window.KATALE_CSRF_TOKEN = document.getElementById('kataleCsrfToken').value;
</script>
<script src="<?= APP_URL ?>/assets/js/push.js?v=<?= filemtime(__DIR__ . '/../assets/js/push.js') ?>"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('katalePushToggle');
    if (!btn) return;
    var label = document.getElementById('katalePushToggleLabel');
    if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
      label.textContent = 'Order notifications are on';
    }
    btn.addEventListener('click', async function () {
      if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
        await katale_disablePush();
        label.textContent = 'Turn on order notifications';
        return;
      }
      var result = await katale_initPush();
      if (result.ok) {
        label.textContent = 'Order notifications are on';
      } else if (result.reason === 'denied') {
        label.textContent = 'Notifications blocked — enable them in your browser settings';
      } else if (result.reason === 'unsupported') {
        label.textContent = 'Not supported on this browser';
      }
    });
  });
</script>
<?php endif; ?>
</body>
</html>
