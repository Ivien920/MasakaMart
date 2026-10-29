<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$pageTitle = 'Your Cart — MasakaMart';
$data = $cart->getItemsWithPricing($cartId);
$items = $data['items'];
$subtotal = $data['subtotal'];
$GLOBALS['seoCartItems'] = $items;
$GLOBALS['seoCartValue'] = $subtotal;

require_once __DIR__ . '/../includes/header.php';
?>
<section class="container section cart-page">
  <div class="page-header-row cart-page-header">
    <?= katale_global_back_button(APP_URL . '/products.php') ?>
    <h1 class="page-title">Your Cart</h1>
    <div class="header-spacer"></div>
    <?php if (!empty($items)): ?>
      <button type="button" class="icon-btn-muted cart-clear-hint" aria-label="Remove items individually using delete" title="Remove items using the trash icon on each product">🗑</button>
    <?php endif; ?>
  </div>

  <?php if (empty($items)): ?>
    <div class="empty-state-card">
      <div class="empty-state-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="19" r="1.5"/><circle cx="18" cy="19" r="1.5"/><path d="M3 4h2l2.5 9.2a1 1 0 0 0 1 .8h8.8a1 1 0 0 0 1-.8L19 7H7"/></svg>
      </div>
      <h2>Your cart is empty</h2>
      <p>Discover fresh products and add them to your cart.</p>
      <a href="<?= APP_URL ?>/products.php" class="btn btn-primary">Start Shopping</a>
    </div>
  <?php else: ?>
    <div class="cart-layout">
      <div class="cart-items" id="cartTable">
        <?php foreach ($items as $item):
          $img = $item['image_path'] ? UPLOAD_URL . e($item['image_path']) : APP_URL . '/assets/images/placeholder.svg';
        ?>
        <article class="cart-item" data-product-id="<?= (int)$item['product_id'] ?>">
          <a href="<?= APP_URL ?>/product.php?slug=<?= e($item['slug']) ?>" class="cart-item-img">
            <img src="<?= $img ?>" alt="<?= e($item['name']) ?>" width="80" height="80" loading="lazy">
          </a>
          <div class="cart-item-body">
            <a href="<?= APP_URL ?>/product.php?slug=<?= e($item['slug']) ?>" class="cart-item-name"><?= e($item['name']) ?></a>
            <div class="cart-item-unit"><?= e($item['unit']) ?></div>
            <div class="cart-item-price cart-price"><?= money($item['effective_price']) ?></div>
            <div class="cart-qty-controls">
              <button type="button" class="qty-btn qty-minus" data-product-id="<?= (int)$item['product_id'] ?>" aria-label="Decrease quantity">−</button>
              <input type="number" class="cart-qty-input" min="1" step="1"
                     value="<?= e((string)$item['quantity']) ?>"
                     max="<?= e((string)$item['stock_quantity']) ?>"
                     data-product-id="<?= (int)$item['product_id'] ?>"
                     aria-label="Quantity">
              <button type="button" class="qty-btn qty-plus" data-product-id="<?= (int)$item['product_id'] ?>" aria-label="Increase quantity">+</button>
            </div>
          </div>
          <div class="cart-item-side">
            <div class="cart-line-total"><?= money($item['line_total']) ?></div>
            <button type="button" class="cart-remove-btn" data-product-id="<?= (int)$item['product_id'] ?>" aria-label="Remove item">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
            </button>
          </div>
        </article>
        <?php endforeach; ?>
      </div>

      <aside class="cart-summary card">
        <h2>Order Summary</h2>
        <div class="summary-row"><span>Subtotal</span><span id="cartSubtotal"><?= money($subtotal) ?></span></div>
        <div class="summary-row summary-muted"><span>Delivery Fee</span><span>At checkout</span></div>
        <div class="summary-row summary-total"><span>Total</span><span id="cartGrandPreview"><?= money($subtotal) ?></span></div>
        <p class="summary-note">Delivery fee is calculated at checkout based on your zone.</p>
        <a href="<?= APP_URL ?>/checkout/checkout.php" class="btn btn-primary btn-block">Proceed to Checkout →</a>
        <a href="<?= APP_URL ?>/products.php" class="btn btn-secondary btn-block">Continue Shopping</a>
      </aside>
    </div>

    <div class="table-scroll cart-table-fallback" hidden aria-hidden="true">
      <table class="cart-table">
        <tbody></tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<input type="hidden" id="csrfToken" value="<?= e(csrf_token()) ?>">
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
