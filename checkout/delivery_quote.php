<?php
/**
 * Read-only preview: given a lat/lng (or a manually typed location), returns
 * the distance and fee the customer would be charged plus the human-readable
 * place name to display instead of raw coordinates. This is for checkout UX
 * only — it mutates nothing, so it does not need CSRF protection, but it DOES
 * require login to avoid being an open, unauthenticated distance-calculation
 * utility. checkout_process.php independently recalculates the real fee
 * server-side from the submitted coordinates before creating any order —
 * this endpoint is never trusted as the source of the charged amount.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'reason' => 'login_required']);
    exit;
}

$lat = (isset($_GET['lat']) && $_GET['lat'] !== '') ? (float)$_GET['lat'] : null;
$lng = (isset($_GET['lng']) && $_GET['lng'] !== '') ? (float)$_GET['lng'] : null;
$address = trim((string)($_GET['address'] ?? ''));

// A manually entered location is resolved to coordinates SERVER-side, so a
// customer who never taps "Use My Current Location" still gets a quote —
// and the coordinates driving the fee always come from our own geocoder.
if ($address !== '' && !katale_valid_coordinates($lat, $lng)) {
    $resolved = katale_forward_geocode($db, $address);
    if (!empty($resolved['ok'])) {
        $lat = (float)$resolved['lat'];
        $lng = (float)$resolved['lng'];
    }
}

if (!katale_valid_coordinates($lat, $lng)) {
    echo json_encode(['ok' => false, 'reason' => 'invalid_location']);
    exit;
}

$quote = katale_quote_delivery($db, $lat, $lng);

// Best-effort readable place name for display only. It never affects the
// quote, and failures never surface technical details to the customer.
$display = '';
$name = '';
$geo = katale_reverse_geocode($db, $lat, $lng);
if (!empty($geo['ok'])) {
    $display = trim((string)$geo['display']);
    $name = trim((string)$geo['name']);
}
if ($display === '' && $address !== '' && !katale_is_coordinate_string($address)) {
    // Geocoding unavailable — fall back to the customer's own readable text.
    $display = $address;
}

$quote['display_location'] = $display !== '' ? $display : null;
$quote['location_name'] = $name !== '' ? $name : null;
$quote['lat'] = $lat;
$quote['lng'] = $lng;
echo json_encode($quote);
