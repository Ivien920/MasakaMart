<?php
/**
 * MasakaMart — Delivery & Pickup service.
 *
 * Every function here that computes a fee or distance is meant to be the
 * SINGLE source of truth: checkout_process.php calls it server-side to get
 * the authoritative amount, and checkout/delivery_quote.php calls the same
 * functions to show the customer a live preview before they submit. The
 * customer's browser never gets to say what the fee is — only what
 * location they want quoted.
 */

// Human-readable place names (display only) come from the geocoding
// service; distance/fee calculation always keeps using raw coordinates.
require_once __DIR__ . '/geocode_service.php';

if (!function_exists('katale_get_setting')) {
    function katale_get_setting(PDO $db, string $key, string $default = ''): string
    {
        static $cache = [];
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $stmt = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = :k");
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch();
        $cache[$key] = $row !== false ? (string)$row['setting_value'] : $default;
        return $cache[$key];
    }
}

if (!function_exists('katale_set_setting')) {
    function katale_set_setting(PDO $db, string $key, string $value): void
    {
        $stmt = $db->prepare(
            "INSERT INTO app_settings (setting_key, setting_value) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $stmt->execute(['k' => $key, 'v' => $value]);
    }
}

/** @return array{name:string,lat:float,lng:float} */
if (!function_exists('katale_delivery_origin')) {
    function katale_delivery_origin(PDO $db): array
    {
        return [
            'name' => katale_get_setting($db, 'delivery_origin_name', 'Masaka Main Market'),
            'lat' => (float)katale_get_setting($db, 'delivery_origin_lat', '0'),
            'lng' => (float)katale_get_setting($db, 'delivery_origin_lng', '0'),
        ];
    }
}

/** @return array{name:string,address:string,lat:float,lng:float,instructions:string,phone:string,enabled:bool} */
if (!function_exists('katale_pickup_location')) {
    function katale_pickup_location(PDO $db): array
    {
        return [
            'name' => katale_get_setting($db, 'pickup_location_name', 'MasakaMart Pickup Point'),
            'address' => katale_get_setting($db, 'pickup_location_address', ''),
            'lat' => (float)katale_get_setting($db, 'pickup_lat', '0'),
            'lng' => (float)katale_get_setting($db, 'pickup_lng', '0'),
            'instructions' => katale_get_setting($db, 'pickup_instructions', ''),
            'phone' => katale_get_setting($db, 'pickup_phone', ''),
            'enabled' => katale_get_setting($db, 'pickup_enabled', '1') === '1',
        ];
    }
}

if (!function_exists('katale_delivery_enabled')) {
    function katale_delivery_enabled(PDO $db): bool
    {
        return katale_get_setting($db, 'delivery_enabled', '1') === '1';
    }
}

/** Great-circle distance in kilometres between two lat/lng points. */
if (!function_exists('katale_haversine_km')) {
    function katale_haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadiusKm * $c;
    }
}

if (!function_exists('katale_valid_coordinates')) {
    function katale_valid_coordinates(?float $lat, ?float $lng): bool
    {
        return $lat !== null && $lng !== null
            && $lat >= -90.0 && $lat <= 90.0
            && $lng >= -180.0 && $lng <= 180.0
            && !($lat === 0.0 && $lng === 0.0); // (0,0) is "null island" — always a missing/placeholder value here
    }
}

/**
 * Authoritative fee calculation for a given distance. Returns null when
 * the distance exceeds the configured maximum (caller must reject the
 * order in that case) or when delivery is disabled entirely.
 */
if (!function_exists('katale_calculate_delivery_fee')) {
    function katale_calculate_delivery_fee(PDO $db, float $distanceKm): ?float
    {
        if (!katale_delivery_enabled($db)) {
            return null;
        }

        $maxEnabled = katale_get_setting($db, 'delivery_max_distance_enabled', '1') === '1';
        $maxKm = (float)katale_get_setting($db, 'delivery_max_distance_km', '15');
        if ($maxEnabled && $distanceKm > $maxKm) {
            return null;
        }

        $model = katale_get_setting($db, 'delivery_pricing_model', 'bracket');

        if ($model === 'per_km') {
            $base = (float)katale_get_setting($db, 'delivery_base_fee', '0');
            $perKm = (float)katale_get_setting($db, 'delivery_price_per_km', '0');
            return round($base + ($distanceKm * $perKm), 2);
        }

        // Bracket model: find the bracket the distance falls in. Brackets
        // are admin-defined and may not cover every distance up to the
        // configured max — if none matches, treat it as out of range
        // rather than silently charging UGX 0.
        $stmt = $db->prepare(
            "SELECT fee FROM delivery_fee_brackets
             WHERE :d >= min_km AND (max_km IS NULL OR :d2 < max_km)
             ORDER BY min_km ASC LIMIT 1"
        );
        $stmt->execute(['d' => $distanceKm, 'd2' => $distanceKm]);
        $row = $stmt->fetch();
        return $row !== false ? round((float)$row['fee'], 2) : null;
    }
}

/**
 * Full server-side quote for a delivery destination: distance + fee, or an
 * error reason ('out_of_range' | 'disabled' | 'invalid_location') instead.
 *
 * @return array{ok:bool,distance_km?:float,fee?:float,reason?:string}
 */
/**
 * Resolves the human-readable location shown for an order — customer order
 * pages, the admin order view and order emails all share this so the three
 * never drift apart.
 *
 * Priority: stored readable location text → reverse-geocoded place name
 * derived from the stored coordinates (upgrades orders saved before
 * readable names existed) → stored readable address → stored short place
 * name. Raw GPS coordinates are NEVER returned as the primary location.
 */
if (!function_exists('katale_order_display_location')) {
    function katale_order_display_location(array $order, ?PDO $db = null): string
    {
        $text = trim((string)($order['delivery_location_text'] ?? ''));
        if ($text !== '' && !katale_is_coordinate_string($text)) {
            return $text;
        }

        $latRaw = $order['delivery_latitude'] ?? null;
        $lngRaw = $order['delivery_longitude'] ?? null;
        $lat = ($latRaw !== null && $latRaw !== '') ? (float)$latRaw : null;
        $lng = ($lngRaw !== null && $lngRaw !== '') ? (float)$lngRaw : null;
        if ($lat !== null && $lng !== null && katale_valid_coordinates($lat, $lng)) {
            try {
                $geo = katale_reverse_geocode($db ?? Database::conn(), $lat, $lng);
                if (!empty($geo['ok']) && trim((string)$geo['display']) !== '') {
                    return trim((string)$geo['display']);
                }
            } catch (Throwable $e) {
                // Geocoding is display-only — it must never break an order page.
            }
        }

        $address = trim((string)($order['delivery_address'] ?? ''));
        if ($address !== '' && !katale_is_coordinate_string($address)) {
            return $address;
        }

        $name = trim((string)($order['delivery_location_name'] ?? ''));
        if ($name !== '' && !katale_is_coordinate_string($name)) {
            return $name;
        }

        return '';
    }
}

/**
 * Renders the fulfillment summary block shown on order detail pages
 * (customer + admin share this so the two never drift apart). Orders
 * placed before this feature existed have no distance/pickup snapshot
 * data — those render as "Legacy Order" rather than blank/broken fields.
 *
 * The primary location is always the readable place name with a 📍 pin.
 * Raw coordinates are only ever exposed inside the collapsed
 * "Technical Location Details" section for delivery operations.
 *
 * @param string $context 'customer' (default) or 'admin'
 */
if (!function_exists('katale_render_fulfillment_block')) {
    function katale_render_fulfillment_block(array $order, string $context = 'customer', ?PDO $db = null): string
    {
        $isPickup = ($order['delivery_method'] ?? '') === 'pickup';
        $hasModernData = $isPickup
            ? !empty($order['pickup_location_name'])
            : (isset($order['delivery_distance_km']) && $order['delivery_distance_km'] !== null);

        ob_start();
        if (!$hasModernData) {
            echo '<p class="fulfillment-legacy-badge">Legacy Order — placed before distance-based delivery/pickup tracking.</p>';
        }

        if ($isPickup) {
            echo '<p><strong>Fulfillment:</strong> Pickup</p>';
            if (!empty($order['pickup_location_name'])) {
                echo '<p><strong>Pickup Point:</strong> 📍 ' . e($order['pickup_location_name']) . '</p>';
            }
            if (!empty($order['pickup_location_address'])) {
                echo '<p><strong>Address:</strong> ' . e($order['pickup_location_address']) . '</p>';
            }
            if (!empty($order['pickup_instructions'])) {
                echo '<p><strong>Instructions:</strong> ' . e($order['pickup_instructions']) . '</p>';
            }
            echo '<p><strong>Pickup Fee:</strong> ' . e(money(0.0)) . '</p>';
            return ob_get_clean();
        }

        echo '<p><strong>Fulfillment:</strong> Delivery</p>';

        // Readable place first — raw coordinates are never the primary display.
        $locationLabel = katale_order_display_location($order, $db);
        if ($locationLabel !== '') {
            $locationKey = $context === 'admin' ? 'Customer Location' : 'Delivery Location';
            echo '<p><strong>' . $locationKey . ':</strong> 📍 ' . e($locationLabel) . '</p>';
        }

        $landmark = trim((string)($order['delivery_landmark'] ?? ''));
        if ($landmark !== '') {
            echo '<p><strong>Landmark:</strong> ' . e($landmark) . '</p>';
        }

        if (isset($order['delivery_distance_km']) && $order['delivery_distance_km'] !== null) {
            echo '<p><strong>Distance:</strong> ' . e(number_format((float)$order['delivery_distance_km'], 1)) . ' km</p>';
        }
        if (!empty($order['delivery_instructions'])) {
            echo '<p><strong>Instructions:</strong> ' . e($order['delivery_instructions']) . '</p>';
        }
        echo '<p><strong>Delivery Fee:</strong> ' . e(money((float)($order['delivery_fee'] ?? 0))) . '</p>';

        // Coordinates remain available for delivery operations, verification
        // and future maps — but only inside a collapsed technical section.
        $lat = (string)($order['delivery_latitude'] ?? '');
        $lng = (string)($order['delivery_longitude'] ?? '');
        if ($lat !== '' && $lng !== '') {
            echo '<details class="technical-location-details">'
                . '<summary>Technical Location Details</summary>'
                . '<p class="technical-location-coords">Latitude: ' . e($lat) . '<br>Longitude: ' . e($lng) . '</p>'
                . '</details>';
        }

        return ob_get_clean();
    }
}

if (!function_exists('katale_quote_delivery')) {
    function katale_quote_delivery(PDO $db, float $lat, float $lng): array
    {
        if (!katale_delivery_enabled($db)) {
            return ['ok' => false, 'reason' => 'disabled'];
        }
        if (!katale_valid_coordinates($lat, $lng)) {
            return ['ok' => false, 'reason' => 'invalid_location'];
        }

        $origin = katale_delivery_origin($db);
        $distanceKm = round(katale_haversine_km($origin['lat'], $origin['lng'], $lat, $lng), 2);
        $fee = katale_calculate_delivery_fee($db, $distanceKm);

        if ($fee === null) {
            return ['ok' => false, 'reason' => 'out_of_range', 'distance_km' => $distanceKm];
        }

        return ['ok' => true, 'distance_km' => $distanceKm, 'fee' => $fee];
    }
}
