<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/checkout/checkout.php');
}
require_csrf();

$termsVersion = '2026-10-29';
if (($_POST['terms_accepted'] ?? '') !== '1') {
    flash('error', 'Please read and accept the MasakaMart Terms and Conditions before placing your order.');
    redirect(APP_URL . '/checkout/checkout.php');
}

$user = current_user();
$customerName = trim($_POST['customer_name'] ?? '');
$customerEmail = trim($_POST['customer_email'] ?? '');
$deliveryMethod = ($_POST['delivery_method'] ?? 'delivery') === 'pickup' ? 'pickup' : 'delivery';
$deliveryLocationText = trim($_POST['delivery_location_text'] ?? '');
$manualLocationText = trim((string)($_POST['delivery_location_text_manual'] ?? ''));
$deliveryStreet = trim($_POST['delivery_street'] ?? '');
$deliveryLandmark = trim($_POST['delivery_landmark'] ?? '');
$deliveryLat = isset($_POST['delivery_latitude']) && $_POST['delivery_latitude'] !== '' ? (float)$_POST['delivery_latitude'] : null;
$deliveryLng = isset($_POST['delivery_longitude']) && $_POST['delivery_longitude'] !== '' ? (float)$_POST['delivery_longitude'] : null;
$deliveryInstructions = trim($_POST['delivery_instructions'] ?? '');
// The fee/distance below are NEVER trusted from the browser — they are
// recalculated from the submitted coordinates against the admin-configured
// pricing. The customer-facing address is also resolved server-side
// (reverse geocoding with a manual-text fallback) so what gets stored and
// shown is a readable place name, never raw coordinates.
$deliveryAddress = null;
$deliveryLocationName = null;
$deliveryDisplayLocation = '';
$paymentMethod = $_POST['payment_method'] ?? 'cash_on_delivery';

$legacyMethodMap = [
    'mtn_momo_sim' => 'mobile_money',
    'airtel_money_sim' => 'mobile_money',
    'card_sim' => 'card',
];
if (isset($legacyMethodMap[$paymentMethod])) {
    $paymentMethod = $legacyMethodMap[$paymentMethod];
}

$allowedMethods = ['mobile_money', 'cash_on_delivery', 'card'];
if (!in_array($paymentMethod, $allowedMethods, true)) {
    $paymentMethod = 'cash_on_delivery';
}

if ($paymentMethod === 'mobile_money' && katale_payment_requires_configuration()) {
    flash('error', katale_payment_error_message());
    redirect(APP_URL . '/checkout/checkout.php');
}

// ---- Mobile Money provider + phone validation (server side) ------------
// Runs BEFORE any order is created so invalid input never leaves an order
// behind. The number is normalized to E.164 and must match the selected
// network's official prefixes (MTN: 77/78/76/39, Airtel: 70/75/74).
$paymentProvider = '';
$paymentPhone = '';
if ($paymentMethod === 'mobile_money') {
    $paymentProvider = strtolower(trim($_POST['payment_provider'] ?? ''));
    $paymentPhoneRaw = trim($_POST['payment_phone'] ?? '');
    $preErrors = [];

    if (!in_array($paymentProvider, ['mtn', 'airtel'], true)) {
        $preErrors[] = 'Please select MTN Mobile Money or Airtel Money.';
    }

    $paymentPhone = PesaJetService::normalizePhoneNumber($paymentPhoneRaw) ?? '';
    if ($paymentPhone === '') {
        $preErrors[] = 'Please enter a valid Ugandan Mobile Money number, e.g. 0771234567.';
    } else {
        $detected = PesaJetService::providerFromPhone($paymentPhone);
        if ($detected === null) {
            $preErrors[] = 'That number is not on a supported MTN or Airtel Uganda network.';
        } elseif ($detected !== $paymentProvider) {
            $preErrors[] = 'That number belongs to ' . ucfirst($detected) . ', not ' . ucfirst($paymentProvider) . '. Please select the matching provider.';
        }
    }

    if (!empty($preErrors)) {
        foreach ($preErrors as $err) { flash('error', $err); }
        redirect(APP_URL . '/checkout/checkout.php');
    }
}


$phoneStmt = $db->prepare("SELECT phone FROM users WHERE user_id = :uid LIMIT 1");
$phoneStmt->execute(['uid' => $user['user_id']]);
$customerPhone = trim((string)($phoneStmt->fetch()['phone'] ?? ''));

$errors = [];
if ($customerName === '' || $customerPhone === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please provide valid customer information.';
}

$deliveryFee = 0.0;
$deliveryDistanceKm = null;
$pickupSnapshot = null;
$deliveryZoneId = (int)($_POST['delivery_zone_id'] ?? 0);
$deliveryMode = $_POST['delivery_mode'] ?? 'zone';

if ($deliveryMethod === 'delivery') {
    if (!katale_delivery_enabled($db)) {
        $errors[] = 'Delivery is currently unavailable. Please choose pickup instead.';
    } elseif ($deliveryMode === 'zone') {
        if ($deliveryZoneId <= 0) {
            $errors[] = 'Please select a valid delivery area / town.';
        } else {
            $zoneStmt = $db->prepare("SELECT zone_id, zone_name, delivery_fee FROM delivery_zones WHERE zone_id = :id AND is_active = 1 LIMIT 1");
            $zoneStmt->execute(['id' => $deliveryZoneId]);
            $deliveryZone = $zoneStmt->fetch();

            if (!$deliveryZone) {
                $errors[] = 'The selected delivery area is no longer active. Please choose another area or pickup.';
            } else {
                $deliveryFee = (float)$deliveryZone['delivery_fee'];
                $deliveryLocationText = trim((string)$deliveryZone['zone_name']);
                $deliveryLocationName = $deliveryLocationText;
                $deliveryAddress = trim(implode(', ', array_filter([$deliveryStreet, $deliveryLocationText])));
                $deliveryDisplayLocation = $deliveryAddress !== '' ? $deliveryAddress : $deliveryLocationText;
                $deliveryAddress = $deliveryDisplayLocation;
                $deliveryDistanceKm = 0.0;
            }
        }
    } else {
        if ($manualLocationText !== '' && $deliveryLocationText === '') {
            $deliveryLocationText = $manualLocationText;
        }
        if ($deliveryLocationText === '' && $deliveryStreet === '') {
            $deliveryLocationText = $manualLocationText;
        }
        if (!katale_valid_coordinates($deliveryLat, $deliveryLng)) {
            $resolved = katale_forward_geocode($db, trim($deliveryStreet . ' ' . $deliveryLocationText));
            if (katale_valid_coordinates($resolved['lat'] ?? null, $resolved['lng'] ?? null)) {
                $deliveryLat = (float)$resolved['lat'];
                $deliveryLng = (float)$resolved['lng'];
            } elseif ($manualLocationText !== '') {
                $resolved = katale_forward_geocode($db, $manualLocationText);
                if (katale_valid_coordinates($resolved['lat'] ?? null, $resolved['lng'] ?? null)) {
                    $deliveryLat = (float)$resolved['lat'];
                    $deliveryLng = (float)$resolved['lng'];
                    $deliveryLocationText = $manualLocationText;
                } else {
                    $errors[] = 'We could not read your location. Please tap "Use My Current Location" or type a valid area/town and try again.';
                }
            } else {
                $errors[] = 'We could not read your location. Please tap "Use My Current Location" or type a valid area/town and try again.';
            }
        }

        if (empty($errors)) {
            $quote = katale_quote_delivery($db, $deliveryLat, $deliveryLng);
            if (!$quote['ok']) {
                $errors[] = $quote['reason'] === 'out_of_range'
                    ? 'Sorry, this location is currently outside our delivery area. Please choose a pickup point or provide a location within our delivery area.'
                    : 'We could not calculate a delivery fee for that location. Please try again or choose pickup.';
            } else {
                $deliveryFee = $quote['fee'];
                $deliveryDistanceKm = $quote['distance_km'];
            }
        }

        if (empty($errors)) {
            $geo = katale_reverse_geocode($db, $deliveryLat, $deliveryLng);
            if (!empty($geo['ok']) && trim((string)$geo['display']) !== '') {
                $deliveryDisplayLocation = trim((string)$geo['display']);
                $deliveryLocationName = trim((string)$geo['name']) ?: null;
                if ($deliveryStreet !== '' && stripos($deliveryDisplayLocation, $deliveryStreet) === false) {
                    $deliveryDisplayLocation = $deliveryStreet . ', ' . $deliveryDisplayLocation;
                }
            } elseif (!katale_is_coordinate_string($deliveryLocationText)) {
                $deliveryDisplayLocation = trim(implode(', ', array_filter([$deliveryStreet, $deliveryLocationText])));
            }

            if ($deliveryDisplayLocation === '') {
                $errors[] = 'Please enter your area or a nearby landmark so we can label your delivery location.';
            } else {
                if ($deliveryLocationName === null) {
                    $firstSegment = trim(explode(',', $deliveryDisplayLocation)[0]);
                    $deliveryLocationName = ($firstSegment !== '' && !katale_is_coordinate_string($firstSegment)) ? $firstSegment : null;
                }
                $deliveryLocationText = $deliveryDisplayLocation;
                $deliveryAddress = $deliveryDisplayLocation;
            }
        }
    }
} else {
    $pickup = katale_pickup_location($db);
    if (!$pickup['enabled']) {
        $errors[] = 'Pickup is currently unavailable. Please choose delivery instead.';
    } else {
        $pickupSnapshot = $pickup;
        $deliveryFee = 0.0;
        $deliveryZoneId = null;
        $deliveryLat = null;
        $deliveryLng = null;
        $deliveryLocationText = null;
        $deliveryAddress = null;
        $deliveryLocationName = null;
        $deliveryLandmark = null;
    }
}

$data = katale_refresh_cart_pricing($db, $cart, $cartId);
$items = $data['items'];
if (empty($items)) {
    $errors[] = 'Your cart is empty.';
}

// Final server-side stock check right before committing the order.
foreach ($items as $item) {
    if (!(int)$item['is_available'] || $item['status'] !== 'active' || (float)$item['quantity'] > (float)$item['stock_quantity']) {
        $errors[] = "{$item['name']} is no longer available in the requested quantity.";
    }
}

try {
    $totalsPreview = katale_build_order_totals($items, $deliveryFee);
} catch (InvalidArgumentException $e) {
    $errors[] = 'Your cart contains invalid quantities or prices. Please refresh and try again.';
}

if (!empty($errors)) {
    foreach ($errors as $err) { flash('error', $err); }
    redirect(APP_URL . '/checkout/checkout.php');
}

try {
    $db->beginTransaction();

    $totals = katale_build_order_totals($items, $deliveryFee);
    $items = $totals['items'];
    $itemsSubtotal = $totals['items_subtotal'];
    $discountTotal = $totals['discount_total'];
    $deliveryFee = $totals['delivery_fee'];
    $grandTotal = $totals['grand_total'];
    $orderNumber = generate_order_number($db);

    $stmt = $db->prepare(
        "INSERT INTO orders (order_number, user_id, customer_name, customer_phone, customer_email,
                              delivery_method, delivery_zone_id, delivery_fee, delivery_address, delivery_instructions,
                              delivery_location_text, delivery_location_name, delivery_latitude, delivery_longitude, delivery_distance_km,
                              delivery_landmark,
                              pickup_location_name, pickup_location_address, pickup_instructions, pickup_phone,
                                  items_subtotal, discount_total, grand_total, payment_status, order_status,
                                  terms_version, terms_accepted_at)
         VALUES (:num, :uid, :name, :phone, :email, :dmethod, :zoneid, :fee, :addr, :instr,
                 :loctext, :locname, :lat, :lng, :dist,
                 :landmark,
                 :pname, :paddr, :pinstr, :pphone,
                         :subtotal, :discount, :total, 'pending', 'received', :terms_version, NOW())"
    );
    $stmt->execute([
        'num' => $orderNumber, 'uid' => $user['user_id'], 'name' => $customerName,
        'phone' => $customerPhone, 'email' => $customerEmail, 'dmethod' => $deliveryMethod,
        'zoneid' => $deliveryZoneId ?: null, 'fee' => $deliveryFee, 'addr' => $deliveryAddress ?: null,
        'instr' => $deliveryInstructions ?: null,
        'loctext' => $deliveryLocationText ?: null, 'locname' => $deliveryLocationName ?: null,
        'lat' => $deliveryLat, 'lng' => $deliveryLng, 'dist' => $deliveryDistanceKm,
        'landmark' => $deliveryLandmark ?: null,
        'pname' => $pickupSnapshot['name'] ?? null, 'paddr' => $pickupSnapshot['address'] ?? null,
        'pinstr' => $pickupSnapshot['instructions'] ?? null, 'pphone' => $pickupSnapshot['phone'] ?? null,
        'subtotal' => $itemsSubtotal, 'discount' => $discountTotal, 'total' => $grandTotal,
        'terms_version' => $termsVersion,
    ]);
    $orderId = (int)$db->lastInsertId();

    $itemStmt = $db->prepare(
        "INSERT INTO order_items (order_id, product_id, product_name, unit, quantity, unit_price, original_unit_price, line_total)
         VALUES (:oid, :pid, :name, :unit, :qty, :price, :orig, :total)"
    );
    foreach ($items as $item) {
        $itemStmt->execute([
            'oid' => $orderId, 'pid' => $item['product_id'], 'name' => $item['name'], 'unit' => $item['unit'],
            'qty' => $item['quantity'], 'price' => $item['effective_price'],
            'orig' => $item['regular_price'], 'total' => $item['line_total'],
        ]);
    }

    katale_deduct_order_stock($db, $orderId, $orderNumber, $items);

    $paymentStatus = 'pending';
    if ($paymentMethod === 'mobile_money') {
        // MasakaMart reference for PesaJet (MM-PAY-...) — stored locally and
        // sent to PesaJet as the merchant `reference`.
        $paymentReference = katale_payment_reference();
    } else {
        $paymentReference = generate_transaction_reference();
    }

    $payStmt = $db->prepare(
        "INSERT INTO payments (order_id, user_id, amount, currency, method, provider, phone_number,
                               transaction_reference, status)
         VALUES (:oid, :uid, :amount, 'UGX', :method, :provider, :phone, :ref, :status)"
    );
    $payStmt->execute([
        'oid' => $orderId,
        'uid' => (int)$user['user_id'],
        'amount' => $grandTotal,
        'method' => $paymentMethod,
        'provider' => $paymentMethod === 'mobile_money' ? $paymentProvider : null,
        'phone' => $paymentMethod === 'mobile_money' ? $paymentPhone : $customerPhone,
        'ref' => $paymentReference,
        'status' => $paymentStatus,
    ]);

    if ($paymentMethod === 'cash_on_delivery') {
        $db->prepare("UPDATE orders SET payment_status = 'pending' WHERE order_id = :id")->execute(['id' => $orderId]);
    }

    // For Mobile Money the "Order received" email/in-app notification is
    // DEFERRED until the payment is actually confirmed (see
    // katale_payment_notify_transition) — no success claims while pending.
    if ($paymentMethod !== 'mobile_money') {
        handle_order_status_change($db, $orderId, 'received');
    }

    $cart->clear($cartId);
    $db->commit();

    if ($paymentMethod === 'mobile_money') {
        // Real PesaJet request — sent only AFTER the order is safely
        // committed. Re-read the order row so the gateway amount matches DB.
        $orderRow = katale_order_for_payment($db, $orderNumber);
        if (!$orderRow || abs((float)$orderRow['grand_total'] - $grandTotal) > 0.01) {
            flash('error', 'We could not verify the order total before payment. Please contact support.');
            redirect(APP_URL . '/checkout/payment_status.php?order=' . urlencode($orderNumber));
        }
        $initiateResult = katale_initiate_payment($db, (array)$orderRow, [
            'provider' => $paymentProvider,
            'phone' => $paymentPhone,
        ]);

        if (!empty($initiateResult['ok'])) {
            flash('success', 'Payment request sent. Please check your phone and approve the Mobile Money payment request.');
        } else {
            flash('error', (string)($initiateResult['error'] ?: katale_payment_customer_error_message()));
        }

        redirect(APP_URL . '/checkout/payment_status.php?order=' . urlencode($orderNumber));
    }

    redirect(APP_URL . '/orders/order_confirmation.php?order=' . urlencode($orderNumber));

} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Checkout failed: ' . $e->getMessage());
    flash('error', 'We could not complete your order: ' . $e->getMessage());
    redirect(APP_URL . '/checkout/checkout.php');
}
