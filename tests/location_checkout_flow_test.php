<?php
/**
 * KATALE — readable delivery location HTTP flow test (CLI).
 *
 * Walks the complete customer journey against a running server:
 *   location selection → reverse geocoding → distance → delivery fee →
 *   checkout → order creation → customer order view → admin order view,
 * plus the manual-only (no GPS) path, the fee-manipulation attempt, and
 * the pickup UI staying intact.
 *
 * Usage: php tests/location_checkout_flow_test.php http://localhost/now
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
$base = rtrim($argv[1] ?? 'http://localhost/now', '/');
$GLOBALS['ck'] = [];
$GLOBALS['R'] = [];
require dirname(__DIR__) . '/config/config.php';
$pdo = null;
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS, [PDO::ATTR_TIMEOUT => 10]);
} catch (Throwable $e) {
    echo 'DB unavailable: ' . $e->getMessage(), PHP_EOL;
}

function C(string $n, bool $c, string $d = ''): void
{
    $GLOBALS['R'][] = '[' . ($c ? 'PASS' : 'FAIL') . '] ' . $n . ' ' . $d;
}

function http(string $url, string $method = 'GET', ?array $body = null, int $timeout = 60): array
{
    $hdr = "Accept: text/html,application/json\r\nConnection: close\r\n";
    if ($GLOBALS['ck']) {
        $pairs = [];
        foreach ($GLOBALS['ck'] as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        $hdr .= 'Cookie: ' . implode('; ', $pairs) . "\r\n";
    }
    $content = $body === null ? '' : http_build_query($body);
    if ($content !== '') {
        $hdr .= "Content-Type: application/x-www-form-urlencoded\r\n";
    }
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => $hdr, 'content' => $content,
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => $timeout,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $hs = $http_response_header ?? [];
    $code = 0;
    $loc = '';
    foreach ($hs as $i => $h) {
        if ($i === 0 && preg_match('#HTTP/\S+\s+(\d+)#', $h, $m)) {
            $code = (int)$m[1];
            continue;
        }
        $p = strpos($h, ':');
        if ($p === false) {
            continue;
        }
        $k = strtolower(trim(substr($h, 0, $p)));
        $v = trim(substr($h, $p + 1));
        if ($k === 'location') {
            $loc = $v;
        }
        if ($k === 'set-cookie') {
            $kv = explode(';', $v, 2)[0];
            if (strpos($kv, '=') !== false) {
                [$a, $b] = explode('=', $kv, 2);
                $GLOBALS['ck'][trim($a)] = trim($b);
            }
        }
    }
    return ['code' => $code, 'loc' => $loc, 'body' => (string)$resp];
}

function tkn(string $html): string
{
    return preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

function coord_like(string $text): bool
{
    return (bool)preg_match('/-?\d{1,3}\.\d+\s*,\s*-?\d{1,3}\.\d+/', $text);
}

// ---- Setup: temp customer + temp admin (cleaned up at the end) --------
$rand = substr(md5(uniqid('', true)), 0, 6);
$customerEmail = 'loctest' . $rand . '@example.com';
$adminEmail = 'locadmin' . $rand . '@example.com';
$adminPass = 'AdminPass123!';

$g = http($base . '/auth/register.php');
$r = http($base . '/auth/register.php', 'POST', ['full_name' => 'Location Flow Customer', 'phone' => '0772123456',
    'email' => $customerEmail, 'password' => 'TestPass123!', 'confirm_password' => 'TestPass123!',
    'csrf_token' => tkn($g['body'])], 40);
C('register-customer', $r['code'] === 302 && str_contains($r['loc'], '/index.php'), 'code=' . $r['code']);

if ($pdo) {
    $pdo->prepare("INSERT INTO user_notification_preferences (user_id, order_updates_email)
                   SELECT user_id, 0 FROM users WHERE email = ? ON DUPLICATE KEY UPDATE order_updates_email = 0")
        ->execute([$customerEmail]);
    $pdo->prepare("INSERT INTO admins (full_name, email, password_hash, role, must_change_password)
                   VALUES (?, ?, ?, 'admin', 0)")
        ->execute(['Location Flow Admin', $adminEmail, password_hash($adminPass, PASSWORD_DEFAULT)]);
    C('setup-admin-created', true, $adminEmail);
} else {
    C('setup-admin-created', false, 'no db');
}

// ---- Cart + checkout page UI ------------------------------------------
$h = http($base . '/index.php');
$tk = tkn($h['body']);
$r = http($base . '/cart/cart_action.php', 'POST', ['action' => 'add', 'product_id' => 1, 'quantity' => 1, 'csrf_token' => $tk]);
C('cart-ajax-add', $r['code'] === 200 && str_starts_with(trim($r['body']), '{'), substr(trim($r['body']), 0, 80));

$ckPage = http($base . '/checkout/checkout.php');
C('checkout-page', $ckPage['code'] === 200, 'code=' . $ckPage['code']);
$ckBody = $ckPage['body'];
C('ui-location-display-box', str_contains($ckBody, 'id="locationDisplay"') && str_contains($ckBody, 'id="locationDisplayText"'));
C('ui-change-location-btn', str_contains($ckBody, 'id="changeLocationBtn"') && str_contains($ckBody, 'Change Location'));
C('ui-use-my-current-location', str_contains($ckBody, 'Use My Current Location'));
C('ui-manual-area-town', str_contains($ckBody, 'Area / Town') && str_contains($ckBody, 'id="deliveryLocationText"'));
C('ui-manual-street', str_contains($ckBody, 'Street / Road') && str_contains($ckBody, 'name="delivery_street"'));
C('ui-manual-landmark', str_contains($ckBody, 'Landmark (optional)') && str_contains($ckBody, 'name="delivery_landmark"'));
C('ui-coords-still-captured', str_contains($ckBody, 'id="deliveryLat"') && str_contains($ckBody, 'id="deliveryLng"'),
    'hidden coordinate fields kept for distance/fee');
C('ui-no-coordinate-fill', !str_contains($ckBody, 'My current location (') && !str_contains($ckBody, 'toFixed(4)'),
    'old raw-coordinate display removed');
C('ui-pickup-card-kept', str_contains($ckBody, 'Pick up my order'));

// ---- Quote endpoint: typed address (forward geocoding) -----------------
$r = http($base . '/checkout/delivery_quote.php?address=' . urlencode('Nyendo, Masaka, Uganda'));
$q = json_decode($r['body'], true);
C('quote-address-ok', is_array($q) && !empty($q['ok']), substr($r['body'], 0, 200));
if (is_array($q) && !empty($q['ok'])) {
    C('quote-address-readable-display', !empty($q['display_location']) && !coord_like($q['display_location']),
        (string)$q['display_location']);
    C('quote-address-has-coords', is_numeric($q['lat'] ?? null) && is_numeric($q['lng'] ?? null),
        'lat=' . ($q['lat'] ?? '') . ' lng=' . ($q['lng'] ?? ''));
    C('quote-address-has-fee', isset($q['distance_km'], $q['fee']) && $q['distance_km'] > 0 && $q['fee'] > 0,
        'km=' . $q['distance_km'] . ' fee=' . $q['fee']);
}

// ---- Quote endpoint: GPS coordinates (unchanged math) ------------------
$r = http($base . '/checkout/delivery_quote.php?lat=-0.3556815&lng=31.7445445');
$q2 = json_decode($r['body'], true);
C('quote-coords-ok', is_array($q2) && !empty($q2['ok']), substr($r['body'], 0, 200));
if (is_array($q2) && !empty($q2['ok'])) {
    C('quote-coords-fee-unchanged', abs($q2['distance_km'] - 2.17) < 0.05 && abs($q2['fee'] - 2500) < 0.01,
        'km=' . $q2['distance_km'] . ' fee=' . $q2['fee']);
    C('quote-coords-readable-display', !empty($q2['display_location']) && !coord_like($q2['display_location']),
        (string)$q2['display_location']);
    C('quote-coords-no-technical-leak', !str_contains($r['body'], 'Nominatim') && !str_contains($r['body'], 'geocoding failed'));
}

// ---- Quote endpoint: unresolvable address → friendly failure -----------
$r = http($base . '/checkout/delivery_quote.php?address=' . urlencode('zzzz not a real place 98765'));
$q3 = json_decode($r['body'], true);
C('quote-bad-address-rejected', is_array($q3) && ($q3['ok'] ?? true) === false && ($q3['reason'] ?? '') === 'invalid_location',
    substr($r['body'], 0, 160));
C('quote-bad-address-no-technical-leak', !str_contains($r['body'], 'Nominatim') && !str_contains($r['body'], 'HTTP')
    && !str_contains($r['body'], '429') && !str_contains($r['body'], 'error'));

// ---- Delivery order: GPS coords + street/landmark + spoofed fee --------
// The POST deliberately includes fake fee/distance fields: the server must
// ignore them and charge the bracket price for the submitted coordinates.
$ckPage = http($base . '/checkout/checkout.php');
$post = [
    'customer_name' => 'Location Flow Customer', 'customer_email' => $customerEmail,
    'delivery_method' => 'delivery', 'payment_method' => 'cash_on_delivery',
    'delivery_location_text' => 'Nyendo, Masaka',
    'delivery_street' => 'Kampala Road',
    'delivery_landmark' => 'Near Nyendo Market',
    'delivery_instructions' => 'Call me when you arrive.',
    'delivery_latitude' => '-0.3556815', 'delivery_longitude' => '31.7445445',
    'delivery_fee' => '1', 'delivery_distance_km' => '0', 'grand_total' => '1', // spoofed — must be ignored
    'csrf_token' => tkn($ckPage['body']),
];
$r = http($base . '/checkout/checkout_process.php', 'POST', $post, 90);
$orderUrl = '';
if (preg_match('/order=(KT-[0-9]+)/', $r['loc'], $m)) {
    $orderUrl = $m[1];
}
C('delivery-order-created', $r['code'] === 302 && $orderUrl !== '', 'code=' . $r['code'] . ' loc=' . $r['loc']);

$orderRow = null;
if ($pdo && $orderUrl !== '') {
    $st = $pdo->prepare("SELECT * FROM orders WHERE order_number = ?");
    $st->execute([$orderUrl]);
    $orderRow = $st->fetch();
}
C('delivery-order-row-found', $orderRow !== false && $orderRow !== null);

if ($orderRow) {
    $text = (string)$orderRow['delivery_location_text'];
    C('db-readable-location-text', $text !== '' && !coord_like($text), $text);
    C('db-location-name', trim((string)$orderRow['delivery_location_name']) !== '', (string)$orderRow['delivery_location_name']);
    C('db-landmark', $orderRow['delivery_landmark'] === 'Near Nyendo Market', (string)$orderRow['delivery_landmark']);
    C('db-instructions', $orderRow['delivery_instructions'] === 'Call me when you arrive.', (string)$orderRow['delivery_instructions']);
    C('db-coords-stored', abs($orderRow['delivery_latitude'] - (-0.3556815)) < 0.0001
        && abs($orderRow['delivery_longitude'] - 31.7445445) < 0.0001,
        'lat=' . $orderRow['delivery_latitude'] . ' lng=' . $orderRow['delivery_longitude']);
    C('db-server-side-fee', abs($orderRow['delivery_fee'] - 2500) < 0.01 && abs($orderRow['delivery_distance_km'] - 2.17) < 0.05,
        'fee=' . $orderRow['delivery_fee'] . ' (spoofed 1 ignored) km=' . $orderRow['delivery_distance_km']);
    C('db-grand-total-includes-fee', abs($orderRow['grand_total'] - ($orderRow['items_subtotal'] + 2500)) < 0.01,
        'total=' . $orderRow['grand_total']);
}

// ---- Customer order view ------------------------------------------------
if ($orderUrl !== '') {
    $od = http($base . '/orders/order_detail.php?order=' . $orderUrl);
    $page = $od['body'];
    C('customer-order-page', $od['code'] === 200, 'code=' . $od['code']);
    C('customer-shows-pin-location', str_contains($page, 'Delivery Location:</strong> 📍')
        && !coord_like(explode('Technical Location Details', $page)[0]),
        '📍 readable location is primary');
    C('customer-shows-landmark', str_contains($page, 'Landmark:</strong> Near Nyendo Market'));
    C('customer-shows-distance', str_contains($page, 'Distance:</strong> 2.2 km'));
    C('customer-shows-fee', str_contains($page, 'Delivery Fee:</strong>'));
    C('customer-technical-details-collapsed', str_contains($page, '<details class="technical-location-details">')
        && str_contains($page, 'Technical Location Details')
        && str_contains($page, 'Latitude: -0.3556815'));
    $detailsPos = strpos($page, '<details class="technical-location-details">');
    $beforeDetails = $detailsPos !== false ? substr($page, 0, $detailsPos) : $page;
    C('customer-coords-not-primary', !str_contains($beforeDetails, '-0.3556815') && !str_contains($beforeDetails, '31.7445445'));
}

// ---- Out-of-range coordinates are rejected server-side -----------------
// Kampala-area coordinates (~120 km away) must be refused even though the
// request also spoofs fee fields — no order may be created.
$ordersBefore = 0;
if ($pdo) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE customer_email = ?");
    $st->execute([$customerEmail]);
    $ordersBefore = (int)$st->fetchColumn();
}
$r = http($base . '/cart/cart_action.php', 'POST', ['action' => 'add', 'product_id' => 1, 'quantity' => 1, 'csrf_token' => tkn(http($base . '/index.php')['body'])]);
$ckPage = http($base . '/checkout/checkout.php');
$r = http($base . '/checkout/checkout_process.php', 'POST', [
    'customer_name' => 'Location Flow Customer', 'customer_email' => $customerEmail,
    'delivery_method' => 'delivery', 'payment_method' => 'cash_on_delivery',
    'delivery_location_text' => 'Kampala CBD',
    'delivery_latitude' => '0.3136', 'delivery_longitude' => '32.5825',
    'delivery_fee' => '1', 'csrf_token' => tkn($ckPage['body']),
], 90);
C('out-of-range-rejected', $r['code'] === 302 && str_contains($r['loc'], '/checkout/checkout.php'),
    'code=' . $r['code'] . ' loc=' . $r['loc']);
$flashPage = http($base . '/checkout/checkout.php');
C('out-of-range-friendly-message', str_contains($flashPage['body'], 'outside our delivery area'));
if ($pdo) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE customer_email = ?");
    $st->execute([$customerEmail]);
    C('out-of-range-no-order-created', (int)$st->fetchColumn() === $ordersBefore,
        'orders=' . $st->fetchColumn() . ' before=' . $ordersBefore);
}

// ---- Manual-only order: typed location, no GPS coordinates -------------
// The server must forward-geocode the text itself and compute distance/fee.
$ckPage = http($base . '/checkout/checkout.php');
$r = http($base . '/checkout/checkout_process.php', 'POST', [
    'customer_name' => 'Location Flow Customer', 'customer_email' => $customerEmail,
    'delivery_method' => 'delivery', 'payment_method' => 'cash_on_delivery',
    'delivery_location_text' => 'Nyendo, Masaka',
    'delivery_landmark' => 'Near the taxi park',
    'csrf_token' => tkn($ckPage['body']),
], 90);
$manualOrder = preg_match('/order=(KT-[0-9]+)/', $r['loc'], $m) ? $m[1] : '';
C('manual-only-order-created', $r['code'] === 302 && $manualOrder !== '', 'code=' . $r['code'] . ' loc=' . $r['loc']);
$manualRow = null;
if ($pdo && $manualOrder !== '') {
    $st = $pdo->prepare("SELECT * FROM orders WHERE order_number = ?");
    $st->execute([$manualOrder]);
    $manualRow = $st->fetch();
}
if ($manualRow) {
    $mLat = $manualRow['delivery_latitude'] !== null ? (float)$manualRow['delivery_latitude'] : null;
    $mLng = $manualRow['delivery_longitude'] !== null ? (float)$manualRow['delivery_longitude'] : null;
    C('manual-only-has-coords', $mLat !== null && $mLng !== null && !($mLat === 0.0 && $mLng === 0.0),
        'lat=' . $manualRow['delivery_latitude'] . ' lng=' . $manualRow['delivery_longitude']);
    C('manual-only-distance-fee', $manualRow['delivery_distance_km'] > 0 && $manualRow['delivery_fee'] > 0,
        'km=' . $manualRow['delivery_distance_km'] . ' fee=' . $manualRow['delivery_fee']);
    C('manual-only-readable-text', !coord_like((string)$manualRow['delivery_location_text'])
        && trim((string)$manualRow['delivery_location_text']) !== '', (string)$manualRow['delivery_location_text']);
    C('manual-only-landmark', $manualRow['delivery_landmark'] === 'Near the taxi park', (string)$manualRow['delivery_landmark']);
}

// ---- Admin order view ---------------------------------------------------
$GLOBALS['ck'] = []; // fresh session: admin session separate from customer
$al = http($base . '/admin/login.php');
$r = http($base . '/admin/login.php', 'POST', ['email' => $adminEmail, 'password' => $adminPass,
    'csrf_token' => tkn($al['body'])], 40);
C('admin-login', $r['code'] === 302 && str_contains($r['loc'], '/admin/dashboard.php'), 'code=' . $r['code'] . ' loc=' . $r['loc']);

if ($orderRow) {
    $ao = http($base . '/admin/order_detail.php?id=' . (int)$orderRow['order_id']);
    $apage = $ao['body'];
    C('admin-order-page', $ao['code'] === 200, 'code=' . $ao['code']);
    C('admin-shows-fulfillment-delivery', str_contains($apage, 'Fulfillment:</strong> Delivery'));
    C('admin-customer-location-pin', str_contains($apage, 'Customer Location:</strong> 📍')
        && str_contains($apage, (string)$orderRow['delivery_location_text']),
        (string)$orderRow['delivery_location_text']);
    C('admin-shows-landmark', str_contains($apage, 'Landmark:</strong> Near Nyendo Market'));
    C('admin-shows-distance', str_contains($apage, 'Distance:</strong> 2.2 km'));
    C('admin-shows-fee', str_contains($apage, 'Delivery Fee:</strong>'));
    C('admin-technical-details-collapsed', str_contains($apage, '<details class="technical-location-details">')
        && str_contains($apage, 'Latitude: -0.3556815'));
    $aDetailsPos = strpos($apage, '<details class="technical-location-details">');
    $aBefore = $aDetailsPos !== false ? substr($apage, 0, $aDetailsPos) : $apage;
    C('admin-coords-not-primary', !str_contains($aBefore, '-0.3556815') && !str_contains($aBefore, '31.7445445'));
}

// ---- Legacy order (KT-10013) renders readable location on admin view ----
if ($pdo) {
    $st = $pdo->prepare("SELECT order_id FROM orders WHERE order_number = 'KT-10013'");
    $st->execute();
    $legacyId = $st->fetchColumn();
    if ($legacyId) {
        $lo = http($base . '/admin/order_detail.php?id=' . (int)$legacyId);
        $lpage = $lo['body'];
        C('legacy-admin-page', $lo['code'] === 200, 'code=' . $lo['code']);
        C('legacy-admin-readable-location', str_contains($lpage, 'Customer Location:</strong> 📍')
            && !str_contains($lpage, 'My current location (-'),
            'legacy coordinate label replaced by a place name');
    }
}

// ---- Cleanup: remove every trace of this test ---------------------------
try {
    if (!$pdo) {
        throw new RuntimeException('database connection unavailable');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec("DELETE pm FROM payments pm JOIN orders o ON pm.order_id = o.order_id WHERE o.customer_email LIKE 'loctest$rand%'");
    $pdo->exec("DELETE oi FROM order_items oi JOIN orders o ON oi.order_id = o.order_id WHERE o.customer_email LIKE 'loctest$rand%'");
    $n = $pdo->exec("DELETE FROM orders WHERE customer_email LIKE 'loctest$rand%'");
    $pdo->exec("DELETE FROM admins WHERE email = '$adminEmail'");
    $pdo->exec("DELETE nl FROM notifications nl JOIN users u ON nl.user_id = u.user_id WHERE u.email LIKE 'loctest$rand%'");
    $pdo->exec("DELETE wl FROM wishlist_items wl JOIN wishlists w ON wl.wishlist_id = w.wishlist_id JOIN users u ON w.user_id = u.user_id WHERE u.email LIKE 'loctest$rand%'");
    $pdo->exec("DELETE FROM wishlists WHERE user_id IN (SELECT user_id FROM users WHERE email LIKE 'loctest$rand%')");
    $pdo->exec("DELETE FROM user_notification_preferences WHERE user_id IN (SELECT user_id FROM users WHERE email LIKE 'loctest$rand%')");
    $pdo->exec("DELETE ci FROM cart_items ci JOIN carts c ON ci.cart_id = c.cart_id WHERE c.user_id IN (SELECT user_id FROM users WHERE email LIKE 'loctest$rand%')");
    $pdo->exec("DELETE FROM carts WHERE user_id IN (SELECT user_id FROM users WHERE email LIKE 'loctest$rand%')");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'loctest$rand%'");
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    C('cleanup', true, 'orders=' . $n);
} catch (Throwable $e) {
    C('cleanup', false, substr($e->getMessage(), 0, 140));
}

$f = 0;
foreach ($GLOBALS['R'] as $line) {
    echo $line, "\n";
    if (str_starts_with($line, '[FAIL]')) {
        $f++;
    }
}
echo '==== TOTAL=' . count($GLOBALS['R']) . " FAILED=$f ====\n";
exit($f ? 1 : 0);

