<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$data = $cart->getItemsWithPricing($cartId);
$items = $data['items'];
$subtotal = $data['subtotal'];

if (empty($items)) {
    flash('notice', 'Your cart is empty.');
    redirect(APP_URL . '/products.php');
}

// Server-side stock re-validation before allowing checkout to proceed.
$stockErrors = [];
foreach ($items as $item) {
    if (!(int)$item['is_available'] || $item['status'] !== 'active') {
        $stockErrors[] = $item['name'] . ' is no longer available.';
    } elseif ((float)$item['quantity'] > (float)$item['stock_quantity']) {
        $stockErrors[] = "Only {$item['stock_quantity']} {$item['unit']} of {$item['name']} available.";
    }
}

$pickupLocation = katale_pickup_location($db);
$deliveryIsEnabled = katale_delivery_enabled($db);
$user = current_user();
$phoneStmt = $db->prepare("SELECT phone FROM users WHERE user_id = :uid LIMIT 1");
$phoneStmt->execute(['uid' => $user['user_id']]);
$savedPhone = (string)($phoneStmt->fetch()['phone'] ?? '');
$activeZones = $db->query("SELECT zone_id, zone_name, delivery_fee FROM delivery_zones WHERE is_active = 1 ORDER BY zone_name")->fetchAll();
$defaultZoneFee = 0.0;
if (!empty($activeZones)) {
    $defaultZoneFee = (float)$activeZones[0]['delivery_fee'];
}

$pageTitle = 'Checkout — MasakaMart';
$GLOBALS['seoCheckoutItems'] = $items;
$GLOBALS['seoCheckoutValue'] = $subtotal;
require_once __DIR__ . '/../includes/header.php';
?>
<section class="container section page-shell">
  <div class="page-header-row">
    <?= katale_global_back_button(APP_URL . '/cart/cart.php') ?>
    <h1>Checkout</h1>
    <div class="header-spacer"></div>
  </div>

  <?php if (!empty($stockErrors)): ?>
    <div class="flash flash-error">
      <ul><?php foreach ($stockErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
      <p><a href="<?= APP_URL ?>/cart/cart.php">Return to cart to fix this →</a></p>
    </div>
  <?php else: ?>

  <form action="<?= APP_URL ?>/checkout/checkout_process.php" method="post" class="checkout-layout" id="checkoutForm">
    <?= csrf_field() ?>
    <div class="checkout-main">
      <fieldset class="checkout-block">
        <legend>Customer Information</legend>
        <label>Full Name</label>
        <input type="text" name="customer_name" value="<?= e($user['full_name']) ?>" required>
        <label>Email</label>
        <input type="email" name="customer_email" value="<?= e($user['email']) ?>" required>
        <label>Phone</label>
        <input type="text" name="customer_phone" value="<?= e($savedPhone) ?>" readonly aria-readonly="true">
        <p class="field-hint">We use the phone number saved on your account when you signed up.</p>
      </fieldset>

      <fieldset class="checkout-block">
        <legend>Where should we fulfill your order?</legend>

        <div class="fulfillment-toggle">
          <label class="fulfillment-card">
            <input type="radio" name="delivery_method" value="delivery" checked <?= $deliveryIsEnabled ? '' : 'disabled' ?>>
            <span class="fulfillment-card-icon">🚚</span>
            <span class="fulfillment-card-label">Deliver to my location</span>
          </label>
          <label class="fulfillment-card">
            <input type="radio" name="delivery_method" value="pickup" <?= $deliveryIsEnabled ? '' : 'checked' ?> <?= $pickupLocation['enabled'] ? '' : 'disabled' ?>>
            <span class="fulfillment-card-icon">📍</span>
            <span class="fulfillment-card-label">Pick up my order</span>
          </label>
        </div>
        <?php if (!$deliveryIsEnabled): ?><p class="field-hint">Delivery is temporarily unavailable — pickup only for now.</p><?php endif; ?>

        <div id="deliveryFields">
          <div class="fulfillment-toggle" style="margin-bottom: 12px;">
            <label class="fulfillment-card">
              <input type="radio" name="delivery_mode" value="zone" checked>
              <span class="fulfillment-card-icon">📍</span>
              <span class="fulfillment-card-label">Use a delivery zone</span>
            </label>
            <label class="fulfillment-card">
              <input type="radio" name="delivery_mode" value="location">
              <span class="fulfillment-card-icon">📡</span>
              <span class="fulfillment-card-label">Use my current location</span>
            </label>
          </div>

          <div id="zoneDeliveryFields">
            <label>Area / Town</label>
            <?php if (empty($activeZones)): ?>
              <p class="flash flash-error">No delivery zones are currently active. Please choose pickup instead.</p>
            <?php else: ?>
              <select name="delivery_zone_id" id="deliveryZoneSelect">
                <option value="">Select your delivery area</option>
                <?php foreach ($activeZones as $zone): ?>
                  <option value="<?= (int)$zone['zone_id'] ?>" data-fee="<?= (float)$zone['delivery_fee'] ?>" <?= ($zone['zone_id'] == ($activeZones[0]['zone_id'] ?? 0)) ? 'selected' : '' ?>><?= e($zone['zone_name']) ?> — <?= money((float)$zone['delivery_fee']) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="hidden" name="delivery_location_text" id="deliveryLocationText" value="<?= e($activeZones[0]['zone_name'] ?? '') ?>">
              <input type="hidden" name="delivery_zone_fee" id="deliveryZoneFee" value="<?= (float)($activeZones[0]['delivery_fee'] ?? 0) ?>">
            <?php endif; ?>
            <div class="delivery-quote-box">
              <div class="summary-row"><span>Area / Town</span><span id="quoteZoneName"><?= !empty($activeZones) ? e($activeZones[0]['zone_name']) : '—' ?></span></div>
              <div class="summary-row"><span>Delivery Fee</span><span id="quoteFee"><?= !empty($activeZones) ? money((float)$activeZones[0]['delivery_fee']) : money(0) ?></span></div>
            </div>
          </div>

          <div id="locationDeliveryFields" hidden>
            <div id="locationDisplay" class="location-display" hidden>
              <span class="location-pin" aria-hidden="true">📍</span>
              <span id="locationDisplayText"></span>
            </div>
            <div class="location-actions">
              <button type="button" id="useMyLocationBtn" class="btn btn-cta use-location-btn">📡 Use My Current Location</button>
              <button type="button" id="changeLocationBtn" class="link-btn" hidden>Change Location</button>
            </div>

            <div id="manualLocationFields">
              <label>Area / Town</label>
              <input type="text" name="delivery_location_text_manual" id="deliveryLocationTextManual" placeholder="e.g. Nyendo, Masaka">
              <label>Street / Road (optional)</label>
              <input type="text" name="delivery_street" id="deliveryStreet" placeholder="e.g. Kampala Road">
              <label>Landmark (optional)</label>
              <input type="text" name="delivery_landmark" id="deliveryLandmark" placeholder="e.g. Near Nyendo Market">
            </div>

            <input type="hidden" name="delivery_latitude" id="deliveryLat">
            <input type="hidden" name="delivery_longitude" id="deliveryLng">
            <p id="locationStatus" class="field-hint">Tap "Use My Current Location" or type your area/town to let the system search and calculate your delivery fee.</p>

            <div id="deliveryQuoteBox" class="delivery-quote-box" hidden>
              <div class="summary-row"><span>Distance from the delivery point</span><span id="quoteDistance">—</span></div>
              <div class="summary-row"><span>Delivery Fee</span><span id="quoteFeeDynamic">—</span></div>
            </div>
            <p id="deliveryOutOfRange" class="flash flash-error" hidden>Sorry, this location is currently outside our delivery area. Please choose pickup or provide a location within our delivery area.</p>
          </div>

          <label>Delivery Instructions (optional)</label>
          <textarea name="delivery_instructions" placeholder="e.g. Call me when you arrive, gate code..."></textarea>
        </div>

        <div id="pickupFields" hidden>
          <div class="delivery-quote-box">
            <strong><?= e($pickupLocation['name']) ?></strong>
            <p><?= e($pickupLocation['address']) ?></p>
            <?php if ($pickupLocation['instructions']): ?><p class="field-hint"><?= e($pickupLocation['instructions']) ?></p><?php endif; ?>
            <?php if ($pickupLocation['phone']): ?><p class="field-hint">Contact: <?= e($pickupLocation['phone']) ?></p><?php endif; ?>
            <div class="summary-row"><span>Pickup Fee</span><span>UGX 0</span></div>
          </div>
        </div>
      </fieldset>

      <fieldset class="checkout-block">
        <legend>Payment Method</legend>
        <?php if (katale_payment_requires_configuration()): ?>
          <p class="checkout-payment-note">Payment is currently unavailable because the PesaJet configuration is not complete.</p>
        <?php endif; ?>
        <label class="radio-option">
          <input type="radio" name="payment_method" value="mobile_money" <?= katale_payment_requires_configuration() ? '' : 'checked' ?>> Mobile Money (PesaJet)
        </label>
        <label class="radio-option">
          <input type="radio" name="payment_method" value="cash_on_delivery" <?= katale_payment_requires_configuration() ? 'checked' : '' ?>> Cash on Delivery
        </label>
        <label class="radio-option"><input type="radio" name="payment_method" value="card"> Card Payment</label>

        <div id="mobileMoneyFields" class="payment-provider-fields" hidden>
          <p class="payment-subhead">Choose how you want to pay</p>
          <div class="provider-toggle">
            <label class="provider-chip">
              <input type="radio" name="payment_provider" value="mtn" checked>
              <span>MTN Mobile Money</span>
            </label>
            <label class="provider-chip">
              <input type="radio" name="payment_provider" value="airtel">
              <span>Airtel Money</span>
            </label>
          </div>

          <label for="paymentPhone">Mobile Money Number</label>
          <input type="tel" id="paymentPhone" name="payment_phone" inputmode="tel"
                 autocomplete="tel" placeholder="+256 7XX XXX XXX"
                 value="<?= e($savedPhone) ?>">
          <p class="field-hint">Enter the number that will receive the payment prompt, e.g. 0771234567.</p>

          <div class="payment-amount-row">
            <span>Amount to Pay</span>
            <strong id="payAmountLabel"><?= money($subtotal) ?></strong>
          </div>
          <p class="payment-security-note">Secure Mobile Money payment powered by PesaJet.</p>
        </div>
      </fieldset>
    </div>

    <aside class="cart-summary">
      <h2>Order Summary</h2>
      <?php foreach ($items as $item): ?>
        <div class="summary-row"><span><?= e($item['name']) ?> × <?= e((string)$item['quantity']) ?></span><span><?= money($item['line_total']) ?></span></div>
      <?php endforeach; ?>
      <div class="summary-row"><span>Subtotal</span><span id="sumSubtotal"><?= money($subtotal) ?></span></div>
      <div class="summary-row"><span>Delivery Fee</span><span id="sumDelivery"><?= money($defaultZoneFee) ?></span></div>
      <div class="summary-row summary-total"><span>Total</span><span id="sumTotal"><?= money($subtotal + $defaultZoneFee) ?></span></div>
      <input type="hidden" name="declared_subtotal" value="<?= (float)$subtotal ?>">
      <label class="checkout-terms-acceptance">
        <input type="checkbox" name="terms_accepted" value="1" required>
        <span>I have read and agree to the <a href="<?= APP_URL ?>/policies/terms.php" target="_blank" rel="noopener noreferrer">MasakaMart Terms and Conditions</a>, including the Delivery and Returns &amp; Refunds Policies.</span>
      </label>
      <button type="submit" class="btn btn-cta btn-block" id="placeOrderButton"><?= katale_payment_requires_configuration() ? 'Place Order' : 'Pay ' . money($subtotal + $defaultZoneFee) ?></button>
      <p class="payment-security-note checkout-pay-note">Secure Mobile Money payment powered by PesaJet.</p>
    </aside>
  </form>
  <?php endif; ?>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const deliveryFields = document.getElementById('deliveryFields');
  const pickupFields = document.getElementById('pickupFields');
  const radios = document.querySelectorAll('input[name="delivery_method"]');
  const deliveryModeRadios = document.querySelectorAll('input[name="delivery_mode"]');
  const subtotal = <?= (float)$subtotal ?>;
  const form = document.getElementById('checkoutForm');
  const placeOrderButton = document.getElementById('placeOrderButton');
  const zoneSelect = document.getElementById('deliveryZoneSelect');
  const zoneFeeInput = document.getElementById('deliveryZoneFee');
  const zoneNameInput = document.getElementById('deliveryLocationText');
  const quoteFee = document.getElementById('quoteFee');
  const quoteZoneName = document.getElementById('quoteZoneName');
  const sumDelivery = document.getElementById('sumDelivery');
  const sumTotal = document.getElementById('sumTotal');
  const payAmountLabel = document.getElementById('payAmountLabel');
  const zoneDeliveryFields = document.getElementById('zoneDeliveryFields');
  const locationDeliveryFields = document.getElementById('locationDeliveryFields');
  const locationText = document.getElementById('deliveryLocationTextManual');
  const locationDisplay = document.getElementById('locationDisplay');
  const locationDisplayText = document.getElementById('locationDisplayText');
  const locationStatus = document.getElementById('locationStatus');
  const quoteBox = document.getElementById('deliveryQuoteBox');
  const quoteDistance = document.getElementById('quoteDistance');
  const quoteFeeDynamic = document.getElementById('quoteFeeDynamic');
  const outOfRangeMsg = document.getElementById('deliveryOutOfRange');
  const useMyLocationBtn = document.getElementById('useMyLocationBtn');
  const changeLocationBtn = document.getElementById('changeLocationBtn');
  const manualLocationFields = document.getElementById('manualLocationFields');
  const latField = document.getElementById('deliveryLat');
  const lngField = document.getElementById('deliveryLng');
  let lastFee = zoneFeeInput ? Number(zoneFeeInput.value || 0) : 0;
  let quoteRequestId = 0;
  let hasQuote = false;
  let currentCanonicalLocation = '';
  let gpsResolvePending = false;
  let submitting = false;

  function formatUgx(n) {
    return 'UGX ' + Number(n).toLocaleString();
  }

  function showReadableLocation(display) {
    currentCanonicalLocation = display;
    locationDisplay.hidden = false;
    locationDisplayText.textContent = display;
  }

  function showManualLocationEntry(focus) {
    manualLocationFields.hidden = false;
    changeLocationBtn.hidden = true;
    if (focus && locationText) locationText.focus();
  }

  function syncDeliveryMode() {
    const isPickup = document.querySelector('input[name="delivery_method"]:checked').value === 'pickup';
    const selectedMode = document.querySelector('input[name="delivery_mode"]:checked')?.value || 'zone';
    const isZoneMode = selectedMode === 'zone';
    if (zoneDeliveryFields) zoneDeliveryFields.hidden = !isZoneMode;
    if (locationDeliveryFields) locationDeliveryFields.hidden = isZoneMode || isPickup;
    if (zoneSelect) zoneSelect.required = !isPickup && isZoneMode;
    if (locationText) locationText.required = !isPickup && !isZoneMode;
    if (isPickup) {
      if (zoneSelect) zoneSelect.removeAttribute('required');
      if (locationText) locationText.removeAttribute('required');
    }
    if (isZoneMode) {
      syncZoneFee();
    } else {
      const fee = lastFee || 0;
      const displayFee = isPickup ? 0 : fee;
      if (sumDelivery) sumDelivery.textContent = formatUgx(displayFee);
      if (sumTotal) sumTotal.textContent = formatUgx(subtotal + displayFee);
      if (payAmountLabel) payAmountLabel.textContent = formatUgx(subtotal + displayFee);
      if (placeOrderButton && !submitting) {
        const checkedPayment = document.querySelector('input[name="payment_method"]:checked');
        const isMobile = checkedPayment && checkedPayment.value === 'mobile_money';
        placeOrderButton.textContent = isMobile ? ('Pay ' + formatUgx(subtotal + displayFee)) : 'Place Order';
      }
    }
  }

  function syncZoneFee() {
    if (!zoneSelect) return;

    const selected = zoneSelect.options[zoneSelect.selectedIndex];
    const fee = selected && selected.dataset.fee ? Number(selected.dataset.fee) : 0;
    lastFee = fee;
    if (zoneFeeInput) zoneFeeInput.value = String(fee);
    if (zoneNameInput && selected) zoneNameInput.value = selected.textContent.replace(/\s*—\s*UGX\s*.*$/, '').trim();
    if (quoteFee) quoteFee.textContent = formatUgx(fee);
    if (quoteZoneName && selected) quoteZoneName.textContent = selected.textContent.replace(/\s*—\s*UGX\s*.*$/, '').trim();

    const isPickup = document.querySelector('input[name="delivery_method"]:checked').value === 'pickup';
    const displayFee = isPickup ? 0 : fee;
    if (sumDelivery) sumDelivery.textContent = formatUgx(displayFee);
    if (sumTotal) sumTotal.textContent = formatUgx(subtotal + displayFee);
    if (placeOrderButton && !submitting) {
      const checkedPayment = document.querySelector('input[name="payment_method"]:checked');
      const isMobile = checkedPayment && checkedPayment.value === 'mobile_money';
      placeOrderButton.textContent = isMobile ? ('Pay ' + formatUgx(subtotal + displayFee)) : 'Place Order';
      placeOrderButton.disabled = isPickup ? false : !zoneSelect.value;
    }
    if (payAmountLabel) payAmountLabel.textContent = formatUgx(subtotal + displayFee);
  }

  function syncPaymentUI() {
    const checked = document.querySelector('input[name="payment_method"]:checked');
    const isMobile = !!checked && checked.value === 'mobile_money';
    const payFields = document.getElementById('mobileMoneyFields');
    const paymentPhone = document.getElementById('paymentPhone');
    if (payFields) payFields.hidden = !isMobile;
    if (paymentPhone) {
      if (isMobile) paymentPhone.setAttribute('required', 'required');
      else paymentPhone.removeAttribute('required');
    }
    if (placeOrderButton && !submitting) {
      const mode = document.querySelector('input[name="delivery_mode"]:checked')?.value || 'zone';
      const fee = (mode === 'zone' && zoneSelect && zoneSelect.value) ? Number(zoneSelect.options[zoneSelect.selectedIndex]?.dataset.fee || 0) : lastFee;
      const displayFee = document.querySelector('input[name="delivery_method"]:checked').value === 'pickup' ? 0 : fee;
      placeOrderButton.textContent = isMobile ? ('Pay ' + formatUgx(subtotal + displayFee)) : 'Place Order';
    }
  }

  function fetchQuote() {
    const isPickup = document.querySelector('input[name="delivery_method"]:checked').value === 'pickup';
    const selectedMode = document.querySelector('input[name="delivery_mode"]:checked')?.value || 'zone';
    if (isPickup || selectedMode === 'zone') return;

    const typed = (locationText ? locationText.value.trim() : '');
    const hasCoords = latField.value !== '' && lngField.value !== '';
    let url = null;
    if (hasCoords && (typed === '' || typed === currentCanonicalLocation)) {
      url = '/checkout/delivery_quote.php?lat=' + encodeURIComponent(latField.value) + '&lng=' + encodeURIComponent(lngField.value);
    } else if (typed !== '') {
      url = '/checkout/delivery_quote.php?address=' + encodeURIComponent(typed);
      gpsResolvePending = false;
    }
    if (!url) return;

    const myRequestId = ++quoteRequestId;
    fetch(APP_BASE_URL + url)
      .then(r => r.json())
      .then(function (result) {
        if (myRequestId !== quoteRequestId) return;
        if (result.ok) {
          if (result.lat != null) latField.value = result.lat;
          if (result.lng != null) lngField.value = result.lng;
          if (result.display_location) {
            showReadableLocation(result.display_location);
            if (gpsResolvePending) {
              gpsResolvePending = false;
              locationText.value = result.display_location;
              manualLocationFields.hidden = true;
              changeLocationBtn.hidden = false;
              locationStatus.textContent = 'Location detected — your distance and delivery fee are below. You can still add a landmark or instructions.';
            }
          } else if (gpsResolvePending) {
            gpsResolvePending = false;
            showManualLocationEntry(false);
            locationStatus.textContent = "We couldn't find a name for this location — please type your area/town (and a landmark if you can) below.";
          } else if (typed && typed !== currentCanonicalLocation) {
            showReadableLocation(typed);
          }
          quoteBox.hidden = false;
          outOfRangeMsg.hidden = true;
          quoteDistance.textContent = result.distance_km + ' km';
          quoteFeeDynamic.textContent = formatUgx(result.fee);
          lastFee = result.fee;
          hasQuote = true;
        } else {
          quoteBox.hidden = true;
          lastFee = 0;
          hasQuote = false;
          gpsResolvePending = false;
          if (result.reason === 'out_of_range') {
            outOfRangeMsg.hidden = false;
            locationStatus.textContent = '';
          } else if (result.reason === 'invalid_location') {
            outOfRangeMsg.hidden = true;
            locationStatus.textContent = "We couldn't find that location — check the spelling, add your area/town, or use My Current Location.";
          }
        }
        syncPaymentUI();
      })
      .catch(function () {
        locationStatus.textContent = 'Could not reach the server to calculate your delivery fee — please try again.';
      });
  }

  if (locationText) {
    let debounceTimer;
    locationText.addEventListener('input', function () {
      latField.value = '';
      lngField.value = '';
      currentCanonicalLocation = '';
      locationDisplay.hidden = true;
      hasQuote = false;
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(fetchQuote, 600);
    });
  }

  if (useMyLocationBtn) {
    useMyLocationBtn.addEventListener('click', function () {
      if (!navigator.geolocation) {
        locationStatus.textContent = 'Your browser does not support location detection — please type your area/town above.';
        showManualLocationEntry(true);
        return;
      }
      locationStatus.textContent = 'Detecting your location…';
      navigator.geolocation.getCurrentPosition(function (pos) {
        latField.value = pos.coords.latitude;
        lngField.value = pos.coords.longitude;
        locationText.value = '';
        currentCanonicalLocation = '';
        gpsResolvePending = true;
        locationStatus.textContent = 'Finding the name of your location…';
        fetchQuote();
      }, function () {
        locationStatus.textContent = 'Location permission denied — please type your area/town above instead.';
        showManualLocationEntry(true);
      }, { timeout: 10000 });
    });
  }

  if (changeLocationBtn) {
    changeLocationBtn.addEventListener('click', function () {
      showManualLocationEntry(true);
    });
  }

  if (zoneSelect) zoneSelect.addEventListener('change', syncZoneFee);
  deliveryModeRadios.forEach(r => r.addEventListener('change', syncDeliveryMode));
  radios.forEach(r => r.addEventListener('change', function () {
    const isPickup = document.querySelector('input[name="delivery_method"]:checked').value === 'pickup';
    deliveryFields.style.display = isPickup ? 'none' : 'block';
    pickupFields.hidden = !isPickup;
    syncDeliveryMode();
  }));
  document.querySelectorAll('input[name="payment_method"]').forEach(function (r) { r.addEventListener('change', syncPaymentUI); });

  if (form && placeOrderButton) {
    form.addEventListener('submit', function (e) {
      if (submitting) { e.preventDefault(); return; }
      const paymentMethod = document.querySelector('input[name="payment_method"]:checked');
      const isMobile = !!paymentMethod && paymentMethod.value === 'mobile_money';
      const paymentPhone = document.getElementById('paymentPhone');
      if (isMobile && paymentPhone && !paymentPhone.value.trim()) {
        e.preventDefault();
        paymentPhone.focus();
        return;
      }
      submitting = true;
      placeOrderButton.disabled = true;
      placeOrderButton.textContent = isMobile ? 'Processing payment...' : 'Processing…';
    });
  }

  syncDeliveryMode();
  syncPaymentUI();
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
