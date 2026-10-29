<?php
/**
 * KATALE — readable location display test (CLI).
 *
 * Covers the reverse-geocoding display feature at the function level:
 * coordinate-string detection, reverse/forward geocoding (network + DB
 * cache), distance/fee calculations staying unchanged, display resolution
 * for legacy + modern orders, and the shared fulfillment block in customer
 * and admin contexts (pickup untouched).
 *
 * Usage: php tests/location_display_test.php
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

require_once __DIR__ . '/../includes/bootstrap.php';

$GLOBALS['F'] = 0;
function C(string $name, bool $ok, string $detail = ''): void
{
    if (!$ok) {
        $GLOBALS['F']++;
    }
    echo '[' . ($ok ? 'PASS' : 'FAIL') . '] ' . $name . ($detail !== '' ? ' ' . $detail : '') . "\n";
}

// ---- 1. Coordinate-string detection -----------------------------------
C('detects-raw-coordinates', katale_is_coordinate_string('-0.3476, 31.0606'));
C('detects-legacy-gps-label', katale_is_coordinate_string('My current location (-0.3557, 31.7445)'));
C('detects-lat-wording', katale_is_coordinate_string('Latitude: -0.3476'));
C('readable-not-coordinates', !katale_is_coordinate_string('Nyendo, Masaka, Uganda'));
C('empty-not-coordinates', !katale_is_coordinate_string(''));

// ---- 2. Reverse geocoding (network first run, DB-cached afterwards) ----
$geo = katale_reverse_geocode($db, -0.3556815, 31.7445445);
C('reverse-geocode-ok', !empty($geo['ok']) && trim((string)$geo['display']) !== '', json_encode($geo, JSON_UNESCAPED_UNICODE));
C('reverse-geocode-readable', !empty($geo['ok']) && !katale_is_coordinate_string($geo['display']), (string)($geo['display'] ?? ''));
C('reverse-geocode-has-name', !empty($geo['ok']) && trim((string)$geo['name']) !== '', (string)($geo['name'] ?? ''));
$cacheRows = (int)$db->query("SELECT COUNT(*) FROM geocode_cache WHERE kind = 'reverse'")->fetchColumn();
C('reverse-cache-written', $cacheRows > 0, 'rows=' . $cacheRows);

// Invalid coordinates must fail fast without any provider call.
C('reverse-invalid-rejected', empty(katale_reverse_geocode($db, null, null)['ok']));
C('reverse-null-island-rejected', empty(katale_reverse_geocode($db, 0.0, 0.0)['ok']));

// ---- 3. Forward geocoding (manual location → coordinates) --------------
$fwd = katale_forward_geocode($db, 'Nyendo, Masaka, Uganda');
C('forward-geocode-ok', !empty($fwd['ok']) && $fwd['lat'] !== null && $fwd['lng'] !== null, json_encode($fwd));
if (!empty($fwd['ok'])) {
    $kmFromMasaka = katale_haversine_km((float)$fwd['lat'], (float)$fwd['lng'], -0.3392, 31.7341);
    C('forward-near-masaka', $kmFromMasaka < 60, sprintf('%.1f km from Masaka Main Market', $kmFromMasaka));
    C('forward-display-readable', !katale_is_coordinate_string($fwd['display']), $fwd['display']);
}
C('forward-rejects-coordinates', empty(katale_forward_geocode($db, '-0.3476, 31.0606')['ok']));
C('forward-rejects-empty', empty(katale_forward_geocode($db, '   ')['ok']));

// ---- 4. Distance + delivery fee stay exactly as before -----------------
$quote = katale_quote_delivery($db, -0.3556815, 31.7445445);
C('quote-ok', !empty($quote['ok']), json_encode($quote));
if (!empty($quote['ok'])) {
    // KT-10013 was placed with these coordinates before this feature:
    // 2.17 km → bracket 0-3km → UGX 2,500. Must be unchanged.
    C('quote-distance-unchanged', abs($quote['distance_km'] - 2.17) < 0.05, 'km=' . $quote['distance_km']);
    C('quote-fee-unchanged', abs($quote['fee'] - 2500) < 0.01, 'fee=' . $quote['fee']);
}

// ---- 5. Display resolution per order -----------------------------------
// Legacy order: stored text IS coordinates → must resolve to a place name.
$legacy = $db->query("SELECT * FROM orders WHERE order_number = 'KT-10013'")->fetch();
if ($legacy !== false) {
    $legacyLabel = katale_order_display_location($legacy, $db);
    C('legacy-order-readable-label', $legacyLabel !== '' && !katale_is_coordinate_string($legacyLabel), $legacyLabel);
} else {
    C('legacy-order-exists', false, 'KT-10013 not found');
}

// Modern order: stored readable text wins immediately (no network needed).
$modern = [
    'delivery_method' => 'delivery',
    'delivery_location_text' => 'Nyendo, Masaka, Uganda',
    'delivery_location_name' => 'Nyendo',
    'delivery_address' => 'Nyendo, Masaka, Uganda',
    'delivery_latitude' => -0.3476,
    'delivery_longitude' => 31.0606,
    'delivery_distance_km' => 3.4,
    'delivery_fee' => 2500,
    'delivery_instructions' => 'Call me when you arrive.',
    'delivery_landmark' => 'Near Nyendo Market',
];
C('modern-label-used', katale_order_display_location($modern, $db) === 'Nyendo, Masaka, Uganda');

// Coordinate-style text with stored coordinates → reverse-geocoded (cached).
$coordRow = $modern;
$coordRow['delivery_location_text'] = '-0.3476, 31.0606';
$coordRow['delivery_address'] = '';
$coordRow['delivery_location_name'] = '';
$coordLabel = katale_order_display_location($coordRow, $db);
C('coord-text-upgraded', $coordLabel !== '' && !katale_is_coordinate_string($coordLabel), $coordLabel);

// Nothing readable at all → empty string (caller omits the row), never coords.
$nothing = ['delivery_location_text' => null, 'delivery_address' => null, 'delivery_location_name' => null,
    'delivery_latitude' => null, 'delivery_longitude' => null];
C('nothing-readable-empty', katale_order_display_location($nothing, $db) === '');

// ---- 6. Shared fulfillment block (customer + admin) --------------------
$block = katale_render_fulfillment_block($modern, 'customer', $db);
C('block-has-pin', str_contains($block, '📍'));
C('block-customer-label', str_contains($block, 'Delivery Location:</strong> 📍 Nyendo, Masaka, Uganda'));
C('block-has-landmark', str_contains($block, 'Landmark:</strong> Near Nyendo Market'));
C('block-has-distance', str_contains($block, 'Distance:</strong> 3.4 km'));
C('block-has-instructions', str_contains($block, 'Instructions:</strong> Call me when you arrive.'));
C('block-has-fee', str_contains($block, 'Delivery Fee:</strong>'));
C('block-has-technical-details', str_contains($block, 'Technical Location Details'));
$beforeDetails = explode('<details', $block)[0];
C('block-coords-not-primary', !str_contains($beforeDetails, '-0.3476') && !str_contains($beforeDetails, '31.0606'));
C('block-coords-inside-details', str_contains($block, 'Latitude: -0.3476') && str_contains($block, 'Longitude: 31.0606'));

$adminBlock = katale_render_fulfillment_block($modern, 'admin', $db);
C('block-admin-label', str_contains($adminBlock, 'Customer Location:</strong> 📍 Nyendo, Masaka, Uganda'));

$legacyBlock = katale_render_fulfillment_block($legacy ?: $modern, 'admin', $db);
C('legacy-block-readable', !str_contains(explode('<details', $legacyBlock)[0], 'My current location (-'),
    substr(strip_tags(str_replace(['<p>', '</p>'], ' ', $legacyBlock)), 0, 140));

// ---- 7. Pickup must remain unchanged -----------------------------------
$pickupOrder = [
    'delivery_method' => 'pickup',
    'pickup_location_name' => 'MasakaMart Main Pickup Point',
    'pickup_location_address' => 'Masaka Main Market, Masaka',
    'pickup_instructions' => 'Collect your order from the MasakaMart pickup point.',
    'pickup_phone' => '0779860256',
    'delivery_fee' => 0,
    'delivery_distance_km' => null,
    'delivery_location_text' => null,
];
$pb = katale_render_fulfillment_block($pickupOrder, 'customer', $db);
C('pickup-block-unchanged', str_contains($pb, 'Fulfillment:</strong> Pickup')
    && str_contains($pb, 'Pickup Point:</strong> 📍 MasakaMart Main Pickup Point')
    && str_contains($pb, 'Pickup Fee:</strong> ' . e(money(0.0))));
C('pickup-block-no-delivery-rows', !str_contains($pb, 'Distance:') && !str_contains($pb, 'Delivery Fee:'));
C('pickup-block-no-technical-details', !str_contains($pb, 'Technical Location Details'));

// ---- 8. Order email block ----------------------------------------------
$emailBlock = katale_order_fulfillment_email_block($modern);
C('email-readable-location', str_contains($emailBlock, 'Nyendo, Masaka, Uganda'));
C('email-landmark', str_contains($emailBlock, 'Landmark'));
C('email-no-raw-coords', !str_contains($emailBlock, '-0.3476') && !str_contains($emailBlock, '31.0606'));
C('email-distance-fee', str_contains($emailBlock, '3.4 km') && str_contains($emailBlock, '2,500'));

echo '==== FAILED=' . $GLOBALS['F'] . " ====\n";
exit($GLOBALS['F'] ? 1 : 0);
