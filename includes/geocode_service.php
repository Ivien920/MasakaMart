<?php
/**
 * MasakaMart — Human-readable delivery locations (reverse/forward geocoding).
 *
 * The stored coordinates (delivery_latitude / delivery_longitude) remain the
 * technical source of truth for distance calculation, delivery pricing,
 * location verification and delivery operations. This service only converts
 * between coordinates and readable place names for CUSTOMER-FACING DISPLAY.
 *
 * Provider: OpenStreetMap Nominatim — keyless, so there is never a
 * credential that could leak. The HTTP call always happens SERVER-SIDE; the
 * browser only ever talks to our own endpoints (delivery_quote.php), never
 * to the geocoder. Responses are cached in the geocode_cache table so a
 * single lookup serves the live quote, the order write and every later
 * order view, which also keeps us well inside Nominatim's
 * 1-request-per-second usage policy.
 *
 * Every public function degrades gracefully: when the service is
 * unreachable, rate-limited, or returns something unexpected, callers get
 * ['ok' => false] and fall back to the customer's manually entered
 * location. Technical errors are written to the server error log only and
 * are NEVER surfaced to customers or admin screens.
 */

if (!function_exists('ensure_geocode_tables')) {
    /**
     * Creates the geocode cache table and guarantees the customer-facing
     * location columns on `orders`. Additive + idempotent, following the
     * same ensure_* convention as notifications/password-reset tables.
     */
    function ensure_geocode_tables(PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS geocode_cache (
                cache_key VARCHAR(64) NOT NULL PRIMARY KEY,
                kind ENUM('reverse','forward') NOT NULL,
                result_json TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_geocode_cache_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Guarded ALTERs: a restricted DB user must never break page loads —
        // if a column cannot be added here, sql/migrations adds it instead.
        try {
            $hasName = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
                                     AND COLUMN_NAME = 'delivery_location_name'")->fetchColumn();
            if (!(int)$hasName) {
                $db->exec("ALTER TABLE orders ADD COLUMN delivery_location_name VARCHAR(150) NULL AFTER delivery_location_text");
            }
        } catch (Throwable $e) {
            error_log('geocode: could not ensure orders.delivery_location_name: ' . $e->getMessage());
        }
        try {
            $hasLandmark = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
                                         AND COLUMN_NAME = 'delivery_landmark'")->fetchColumn();
            if (!(int)$hasLandmark) {
                $db->exec("ALTER TABLE orders ADD COLUMN delivery_landmark VARCHAR(255) NULL AFTER delivery_instructions");
            }
        } catch (Throwable $e) {
            error_log('geocode: could not ensure orders.delivery_landmark: ' . $e->getMessage());
        }
    }
}

if (!function_exists('katale_is_coordinate_string')) {
    /**
     * True when a "location" is actually raw GPS coordinates (the old
     * display format, e.g. "-0.3476, 31.0606" or
     * "My current location (-0.3557, 31.7445)"). Such text must never be
     * the primary location shown to a customer or admin.
     */
    function katale_is_coordinate_string(?string $text): bool
    {
        $text = trim((string)$text);
        if ($text === '') {
            return false;
        }
        // A lat,lng pair with decimal degrees (optionally wrapped in text).
        if (preg_match('/-?\d{1,3}\.\d+\s*,\s*-?\d{1,3}\.\d+/', $text)) {
            return true;
        }
        // Explicit coordinate wording, e.g. "Latitude: -0.3476".
        return (bool)preg_match('/\b(?:lat|lng|latitude|longitude)\s*[:=]\s*-?\d/i', $text);
    }
}

if (!function_exists('katale_geocode_cache_get')) {
    function katale_geocode_cache_get(PDO $db, string $key): ?array
    {
        try {
            $stmt = $db->prepare("SELECT result_json, created_at FROM geocode_cache WHERE cache_key = :k");
            $stmt->execute(['k' => $key]);
            $row = $stmt->fetch();
            if ($row === false) {
                return null;
            }
            $result = json_decode((string)$row['result_json'], true);
            if (!is_array($result)) {
                return null;
            }
            // Successful lookups are cached for months; failures only for a
            // couple of minutes so a transient outage recovers quickly.
            $ttlSeconds = !empty($result['ok']) ? 180 * 86400 : 120;
            if (strtotime((string)$row['created_at']) < time() - $ttlSeconds) {
                $db->prepare("DELETE FROM geocode_cache WHERE cache_key = :k")->execute(['k' => $key]);
                return null;
            }
            return $result;
        } catch (Throwable $e) {
            return null; // cache is an optimisation — never fatal
        }
    }
}

if (!function_exists('katale_geocode_cache_put')) {
    function katale_geocode_cache_put(PDO $db, string $key, string $kind, array $result): void
    {
        try {
            $stmt = $db->prepare(
                "INSERT INTO geocode_cache (cache_key, kind, result_json) VALUES (:k, :kind, :j)
                 ON DUPLICATE KEY UPDATE kind = VALUES(kind), result_json = VALUES(result_json), created_at = CURRENT_TIMESTAMP"
            );
            $stmt->execute(['k' => $key, 'kind' => $kind, 'j' => json_encode($result, JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) {
            // Caching failure only costs an extra lookup later.
        }
    }
}

if (!function_exists('katale_geocode_throttle')) {
    /**
     * Serialises outbound geocoding calls to at most ~1 per second across
     * all concurrent requests (Nominatim usage policy), while waiting at
     * most ~2 seconds before proceeding regardless.
     */
    function katale_geocode_throttle(): void
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'masakamart_geocode_throttle.lock';
        $giveUpAt = microtime(true) + 2.0;
        while (true) {
            $fh = @fopen($file, 'c+');
            if ($fh === false) {
                return; // cannot throttle in this environment — proceed
            }
            @flock($fh, LOCK_EX);
            $last = trim((string)stream_get_contents($fh));
            $now = microtime(true);
            if ($last === '' || ($now - (float)$last) >= 1.05) {
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, (string)$now);
                fflush($fh);
                @flock($fh, LOCK_UN);
                fclose($fh);
                return;
            }
            @flock($fh, LOCK_UN);
            fclose($fh);
            if ($now >= $giveUpAt) {
                return; // bounded wait — a possible 429 degrades to fallback
            }
            usleep((int)((1.05 - ($now - (float)$last)) * 1000000));
        }
    }
}

if (!function_exists('katale_geocode_ca_bundle')) {
    /**
     * Path to a CA bundle for verifying the geocoding provider's HTTPS
     * certificate. If PHP already has one configured (curl.cainfo /
     * openssl.cafile) that wins; otherwise we fall back to the bundle
     * shipped in config/cacert.pem, because some WAMP/Windows installs
     * ship no CA bundle at all (every HTTPS call then fails with
     * "unable to get local issuer certificate"). Verification is never
     * disabled — we only supply the trust store.
     */
    function katale_geocode_ca_bundle(): string
    {
        static $bundle = false;
        if ($bundle !== false) {
            return $bundle;
        }
        $bundle = '';
        $configured = trim((string)ini_get('curl.cainfo'));
        if ($configured === '') {
            $configured = trim((string)ini_get('openssl.cafile'));
        }
        if ($configured === '') {
            $candidate = __DIR__ . '/../config/cacert.pem';
            if (is_file($candidate)) {
                $bundle = $candidate;
            }
        }
        return $bundle;
    }
}

if (!function_exists('katale_geocode_http_get')) {
    /**
     * GET a JSON resource server-side. Returns the decoded array, or null on
     * any transport/HTTP/parse problem. Errors go to the server log only —
     * callers must treat null as "geocoding unavailable".
     */
    function katale_geocode_http_get(string $url): ?array
    {
        $userAgent = 'MasakaMart/1.0 (' . (defined('APP_URL') && APP_URL !== '' ? APP_URL : 'MasakaMart') . '; delivery location display)';
        $body = false;
        $status = 0;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_USERAGENT      => $userAgent,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);
            $caBundle = katale_geocode_ca_bundle();
            if ($caBundle !== '') {
                curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
            }
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($body === false) {
                error_log('geocode: transport error: ' . (string)curl_error($ch));
            }
            curl_close($ch);
        } else {
            $httpOptions = [
                'method'          => 'GET',
                'header'          => "Accept: application/json\r\nUser-Agent: {$userAgent}\r\n",
                'timeout'         => 6,
                'ignore_errors'   => true,
                'follow_location' => 1,
            ];
            $caBundle = katale_geocode_ca_bundle();
            $context = ['http' => $httpOptions];
            if ($caBundle !== '') {
                $context['ssl'] = ['cafile' => $caBundle, 'verify_peer' => true, 'verify_peer_name' => true];
            }
            $ctx = stream_context_create($context);
            $body = @file_get_contents($url, false, $ctx);
            foreach (($http_response_header ?? []) as $i => $h) {
                if ($i === 0 && preg_match('#HTTP/\S+\s+(\d+)#', $h, $m)) {
                    $status = (int)$m[1];
                }
            }
        }

        if ($body === false || ($status !== 0 && $status !== 200)) {
            error_log('geocode: request failed (HTTP ' . $status . ')');
            return null;
        }
        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            error_log('geocode: unexpected response payload');
            return null;
        }
        return $data;
    }
}

if (!function_exists('katale_geocode_build_display')) {
    /**
     * Turns a Nominatim `address` object into a short, human-readable place
     * string following the display priority:
     *   street/road + area + city + country  →  area + city + country
     *   → city + region + country  →  first useful parts of the raw name.
     * Technical geocoding fields (ISO codes, postal codes, …) are never shown.
     *
     * @return array{display:string,name:string}
     */
    function katale_geocode_build_display(array $address, string $fallbackDisplayName = ''): array
    {
        $pick = static function (array $address, array $keys): string {
            foreach ($keys as $key) {
                $value = trim((string)($address[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
            return '';
        };

        $country = $pick($address, ['country']);
        $state   = $pick($address, ['state', 'region', 'province']);
        $city    = $pick($address, ['town', 'city', 'municipality', 'county', 'state_district']);
        $area    = $pick($address, ['neighbourhood', 'suburb', 'village', 'hamlet', 'quarter', 'borough', 'city_district']);
        $road    = $pick($address, ['road', 'pedestrian', 'footway', 'residential', 'cycleway']);

        $parts = [];
        $push = static function (string $value) use (&$parts): void {
            if ($value === '') {
                return;
            }
            foreach ($parts as $existing) {
                if (strcasecmp($existing, $value) === 0) {
                    return;
                }
            }
            $parts[] = $value;
        };

        if ($road !== '' && $area !== '' && $city !== '' && strcasecmp($area, $city) !== 0) {
            // e.g. Kampala Road, Nyendo, Masaka, Uganda
            $push($road);
            $push($area);
            $push($city);
        } elseif ($area !== '' && $city !== '' && strcasecmp($area, $city) !== 0) {
            // e.g. Nyendo, Masaka, Uganda
            $push($area);
            $push($city);
        } elseif ($road !== '' && $city !== '') {
            $push($road);
            $push($city);
        } elseif ($road !== '' && $area !== '') {
            $push($road);
            $push($area);
        } elseif ($area !== '') {
            $push($area);
            $push($pick($address, ['county']) ?: $state);
        } elseif ($city !== '') {
            // e.g. Masaka, Central Region, Uganda
            $push($city);
            $push($state);
        } elseif ($road !== '') {
            $push($road);
        }

        if (empty($parts) && $fallbackDisplayName !== '') {
            // Last resort: the first few human parts of the provider's own
            // display name (already ordered most → least specific).
            foreach (explode(',', $fallbackDisplayName) as $chunk) {
                $chunk = trim($chunk);
                if ($chunk === '' || preg_match('/^\d+$/', $chunk)) {
                    continue;
                }
                $push($chunk);
                if (count($parts) >= 3) {
                    break;
                }
            }
        }

        if ($country !== '') {
            $push($country);
        }

        $display = implode(', ', array_slice($parts, 0, 5));
        $name = $area !== '' ? $area : ($city !== '' ? $city : ($road !== '' ? $road : ($parts[0] ?? '')));

        return ['display' => $display, 'name' => $name];
    }
}

if (!function_exists('katale_reverse_geocode')) {
    /**
     * Coordinates → readable place name (for display only; distance and fee
     * calculation never go through this function).
     *
     * @return array{ok:bool,display:string,name:string}
     */
    function katale_reverse_geocode(PDO $db, ?float $lat, ?float $lng): array
    {
        $fail = ['ok' => false, 'display' => '', 'name' => ''];
        if ($lat === null || $lng === null
            || !is_finite($lat) || !is_finite($lng)
            || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180
            || ($lat === 0.0 && $lng === 0.0)) {
            return $fail;
        }

        // Round to ~1m precision so every nearby quote shares one cache row.
        $latR = round($lat, 5);
        $lngR = round($lng, 5);
        $key = 'rev:' . $latR . ',' . $lngR;

        $cached = katale_geocode_cache_get($db, $key);
        if ($cached !== null) {
            return $cached;
        }

        $url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1'
            . '&accept-language=en&zoom=18'
            . '&lat=' . rawurlencode((string)$latR)
            . '&lon=' . rawurlencode((string)$lngR);

        katale_geocode_throttle();
        $data = katale_geocode_http_get($url);

        $result = $fail;
        if (is_array($data) && !isset($data['error']) && isset($data['address']) && is_array($data['address'])) {
            $built = katale_geocode_build_display($data['address'], (string)($data['display_name'] ?? ''));
            if ($built['display'] !== '') {
                $result = ['ok' => true, 'display' => $built['display'], 'name' => $built['name']];
            }
        }

        // Failures are cached too (short TTL) so an outage degrades quickly
        // instead of hammering the provider on every request.
        katale_geocode_cache_put($db, $key, 'reverse', $result);
        return $result;
    }
}

if (!function_exists('katale_forward_geocode')) {
    /**
     * Readable place/area text → coordinates. Used when a customer provides
     * only a manually typed location: the coordinates feed the unchanged
     * distance/delivery-fee calculation, and the returned display keeps the
     * stored label human-readable.
     *
     * @return array{ok:bool,lat:?float,lng:?float,display:string,name:string}
     */
    function katale_forward_geocode(PDO $db, string $query): array
    {
        $fail = ['ok' => false, 'lat' => null, 'lng' => null, 'display' => '', 'name' => ''];
        $query = trim(preg_replace('/\s+/', ' ', (string)$query) ?? '');
        if ($query === '' || mb_strlen($query) < 2 || mb_strlen($query) > 140 || katale_is_coordinate_string($query)) {
            return $fail;
        }

        $key = 'fwd:' . md5(mb_strtolower($query));
        $cached = katale_geocode_cache_get($db, $key);
        if ($cached !== null) {
            return $cached;
        }

        $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&addressdetails=1'
            . '&accept-language=en&q=' . rawurlencode($query);

        katale_geocode_throttle();
        $data = katale_geocode_http_get($url);

        $result = $fail;
        if (is_array($data) && isset($data[0]) && is_array($data[0])) {
            $first = $data[0];
            $lat = isset($first['lat']) ? (float)$first['lat'] : null;
            $lng = isset($first['lon']) ? (float)$first['lon'] : null;
            $coordsOk = $lat !== null && $lng !== null
                && is_finite($lat) && is_finite($lng)
                && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180
                && !($lat === 0.0 && $lng === 0.0);
            if ($coordsOk) {
                $built = katale_geocode_build_display(
                    is_array($first['address'] ?? null) ? $first['address'] : [],
                    (string)($first['display_name'] ?? '')
                );
                $result = [
                    'ok' => true,
                    'lat' => $lat,
                    'lng' => $lng,
                    // Prefer the geocoded display; otherwise keep the
                    // customer's own readable text (never coordinates).
                    'display' => $built['display'] !== '' ? $built['display'] : $query,
                    'name' => $built['name'] !== '' ? $built['name'] : $query,
                ];
            }
        }

        katale_geocode_cache_put($db, $key, 'forward', $result);
        return $result;
    }
}
