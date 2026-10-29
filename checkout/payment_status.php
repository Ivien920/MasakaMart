<?php
/**
 * Payment status page — pending / success / failure for a MasakaMart order.
 *
 * Reached after checkout initiates a PesaJet Mobile Money request, or via
 * "View Order" links. One page, three honest states:
 *   - paid      → confirmation (only after webhook/API-confirmed payment)
 *   - awaiting  → request sent, waiting for phone approval + confirmation
 *   - error     → failed/expired/never confirmed → safe retry (same order)
 *
 * The payment amount always comes from the server-side order row; the page
 * never trusts anything sent by the browser.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$user = current_user();
$orderNumber = trim((string)($_GET['order'] ?? $_POST['order'] ?? ''));
$backUrl = APP_URL . '/orders/order_history.php';

/* ---------------- Retry (POST, CSRF-protected) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)($_POST['action'] ?? '');

    $order = katale_order_for_payment($db, $orderNumber, (int)$user['user_id']);
    if (!$order) {
        flash('error', 'Order not found.');
        redirect($backUrl);
    }

    if ($action === 'retry') {
        $result = katale_initiate_payment($db, $order, [
            'provider' => (string)($_POST['payment_provider'] ?? ''),
            'phone' => (string)($_POST['payment_phone'] ?? ''),
        ]);

        if ($result['ok']) {
            flash('success', 'Payment request sent. Please check your phone and approve the Mobile Money payment request.');
        } else {
            // Validation errors carry actionable messages; everything else
            // is the generic customer-facing error (details stay in logs).
            flash('error', (string)($result['error'] ?: katale_payment_customer_error_message()));
        }
    }

    redirect(APP_URL . '/checkout/payment_status.php?order=' . urlencode($order['order_number']));
}

/* ---------------- Page state ---------------- */
$order = katale_order_for_payment($db, $orderNumber, (int)$user['user_id']);
if (!$order) {
    flash('error', 'Order not found.');
    redirect($backUrl);
}

$payment = katale_get_order_payment($db, (int)$order['order_id']);
$state = katale_payment_page_state($order, $payment);
$amount = (float)$order['grand_total'];
$orderNumber = (string)$order['order_number'];

$providerKey = 'mtn';
if ($payment && in_array((string)($payment['provider'] ?? ''), ['mtn', 'airtel'], true)) {
    $providerKey = (string)$payment['provider'];
}
$providerLabel = katale_payment_method_label($providerKey);
$phonePrefill = (string)($payment['phone_number'] ?? '');
if ($phonePrefill === '') {
    $phonePrefill = (string)($order['customer_phone'] ?? '');
}

$pageTitle = 'Payment — MasakaMart';
if ($state === 'paid') {
  $itemStmt = $db->prepare('SELECT product_id, product_name AS name, quantity, unit_price AS effective_price FROM order_items WHERE order_id = :oid ORDER BY order_item_id');
  $itemStmt->execute(['oid' => (int)$order['order_id']]);
  $purchaseItems = [];
  foreach ($itemStmt->fetchAll() as $item) {
    $purchaseItems[] = [
      'item_id' => (string)(int)$item['product_id'],
      'item_name' => (string)$item['name'],
      'price' => (float)$item['effective_price'],
      'quantity' => (float)$item['quantity'],
      'currency' => APP_CURRENCY,
    ];
  }
  $GLOBALS['seoPurchase'] = ['transaction_id' => (string)$order['order_number'], 'value' => (float)$order['grand_total'], 'currency' => APP_CURRENCY, 'items' => $purchaseItems];
}
require_once __DIR__ . '/../includes/header.php';
?>
<section class="container section confirmation-section">
<?php if ($state === 'paid'): ?>
  <!-- ============ PAYMENT SUCCESSFUL (spec §14) ============ -->
  <div class="confirmation-box payment-status-card payment-status-paid">
    <div class="payment-status-icon" aria-hidden="true">✅</div>
    <h1>Payment Successful</h1>
    <p>Your payment of:</p>
    <p class="payment-amount"><?= money($amount) ?></p>
    <p>has been received.</p>
    <p class="order-number">Order Number: <strong>#<?= e($orderNumber) ?></strong></p>
    <p>Your order has been confirmed.</p>
    <div class="confirmation-actions">
      <a href="<?= APP_URL ?>/orders/order_detail.php?order=<?= e(urlencode($orderNumber)) ?>" class="btn btn-cta">View Order</a>
      <a href="<?= APP_URL ?>/products.php" class="btn btn-outline">Continue Shopping</a>
    </div>
  </div>

<?php elseif ($state === 'awaiting'): ?>
  <!-- ============ PAYMENT PENDING (spec §16) ============ -->
  <div class="confirmation-box payment-status-card payment-status-awaiting"
       data-poll-url="<?= APP_URL ?>/checkout/payment_status_ajax.php?order=<?= e(urlencode($orderNumber)) ?>">
    <div class="payment-status-icon" aria-hidden="true">⏳</div>
    <h1>Payment Pending</h1>
    <p>A Mobile Money payment request has been sent to your phone.</p>
    <p>Please approve the request on your phone.</p>
    <p>We are waiting for confirmation.</p>
    <p class="payment-amount"><?= money($amount) ?></p>
    <p>
      <span class="status-pill status-<?= e((string)$payment['status']) ?>" id="paymentStatusPill">
        <?= e(ucfirst((string)$payment['status'])) ?>
      </span>
      <span class="field-hint-inline"><?= e($providerLabel) ?></span>
    </p>
    <button type="button" class="btn btn-cta btn-block" id="checkStatusButton">Check Payment Status</button>
    <p class="field-hint payment-security-note">Secure Mobile Money payment powered by PesaJet.</p>
  </div>

<?php elseif ($state === 'error'): ?>
  <!-- ============ PAYMENT FAILED (spec §15) ============ -->
  <div class="confirmation-box payment-status-card payment-status-failed">
    <div class="payment-status-icon" aria-hidden="true">⚠️</div>
    <h1>Payment Failed</h1>
    <p>We could not complete your Mobile Money payment.</p>
    <p>Please check your phone number/balance and try again.</p>
    <p class="payment-amount"><?= money($amount) ?></p>
    <?php if ($payment): ?>
      <p><span class="status-pill status-<?= e((string)$payment['status']) ?>"><?= e(ucfirst((string)$payment['status'])) ?></span></p>
    <?php endif; ?>

    <?php if (katale_payment_is_configured() && $order['payment_status'] !== 'paid' && $order['order_status'] !== 'cancelled'): ?>
    <form method="post" class="payment-retry-form" id="retryPaymentForm">
      <?= csrf_field() ?>
      <input type="hidden" name="order" value="<?= e($orderNumber) ?>">
      <input type="hidden" name="action" value="retry">

      <fieldset class="checkout-block payment-fieldset">
        <legend>Payment Method</legend>
        <label class="radio-option provider-option">
          <input type="radio" name="payment_provider" value="mtn" <?= $providerKey === 'mtn' ? 'checked' : '' ?>> MTN Mobile Money
        </label>
        <label class="radio-option provider-option">
          <input type="radio" name="payment_provider" value="airtel" <?= $providerKey === 'airtel' ? 'checked' : '' ?>> Airtel Money
        </label>

        <label for="retryPhone">Mobile Money Number</label>
        <input type="tel" id="retryPhone" name="payment_phone" inputmode="tel"
               placeholder="+256 7XX XXX XXX" value="<?= e($phonePrefill) ?>" required>
      </fieldset>

      <button type="submit" class="btn btn-cta btn-block" id="retryPayButton">Try Again</button>
      <p class="field-hint payment-security-note">Secure Mobile Money payment powered by PesaJet.</p>
    </form>
    <?php endif; ?>

    <a href="<?= APP_URL ?>/orders/order_detail.php?order=<?= e(urlencode($orderNumber)) ?>" class="btn btn-outline btn-block">View Order</a>
  </div>

<?php else: ?>
  <!-- ============ CANCELLED ============ -->
  <div class="confirmation-box payment-status-card">
    <div class="payment-status-icon" aria-hidden="true">🚫</div>
    <h1>Order Cancelled</h1>
    <p>This order was cancelled, so no payment can be made for it.</p>
    <div class="confirmation-actions">
      <a href="<?= APP_URL ?>/products.php" class="btn btn-cta">Continue Shopping</a>
    </div>
  </div>
<?php endif; ?>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
  // ---- Pending state: "Check Payment Status" (authenticated server poll) ----
  var pollCard = document.querySelector('.payment-status-awaiting');
  var checkBtn = document.getElementById('checkStatusButton');
  var checking = false;

  function pollStatus() {
    if (checking || !pollCard) return;
    checking = true;
    if (checkBtn) { checkBtn.disabled = true; checkBtn.textContent = 'Checking…'; }

    fetch(pollCard.getAttribute('data-poll-url'), {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        // Terminal states → reload so the server renders the real page.
        if (data && data.state && data.state !== 'awaiting') {
          window.location.reload();
          return;
        }
        var pill = document.getElementById('paymentStatusPill');
        if (pill && data.payment_status) {
          pill.textContent = data.payment_status.charAt(0).toUpperCase() + data.payment_status.slice(1);
          pill.className = 'status-pill status-' + data.payment_status;
        }
        if (checkBtn) { checkBtn.disabled = false; checkBtn.textContent = 'Check Payment Status'; }
        checking = false;
      })
      .catch(function () {
        if (checkBtn) { checkBtn.disabled = false; checkBtn.textContent = 'Check Payment Status'; }
        checking = false;
      });
  }

  if (checkBtn) { checkBtn.addEventListener('click', pollStatus); }

  // ---- Retry form: processing state, disable double submit ----
  var retryForm = document.getElementById('retryPaymentForm');
  var retryBtn = document.getElementById('retryPayButton');
  if (retryForm && retryBtn) {
    retryForm.addEventListener('submit', function () {
      retryBtn.disabled = true;
      retryBtn.textContent = 'Processing payment…';
    });
  }
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>



