<?php
/**
 * PesaJet REST API client for MasakaMart.
 *
 * Thin, dependency-free (cURL) wrapper around the official PesaJet API:
 *   Base   : https://payments.pesajet.com/api/v1
 *   Auth   : X-API-Key header (official docs — the API secret is NEVER sent
 *            to the browser and never logged)
 *   POST   /payments            — create a mobile-money collection prompt
 *   GET    /payments/{txnId}    — read a transaction's live status
 *   GET    /payments/preview    — fee preview (safe credential probe)
 *
 * Webhook verification follows the official reference implementation:
 *   X-Webhook-Signature = hex(HMAC-SHA256(raw_body, webhook_secret)),
 *   compared in constant time with hash_equals().
 *
 * All credentials come from environment configuration (config/config.php),
 * so rotating them requires no code change.
 */

if (!function_exists('katale_payment_log')) {
    /**
     * Sanitized payment logger. Writes to the PHP error log only.
     * NEVER pass secrets (API key/secret, webhook secret, passwords) into
     * $context — phone numbers are masked automatically.
     */
    function katale_payment_log(string $event, array $context = []): void
    {
        $clean = [];
        foreach ($context as $key => $value) {
            $lk = strtolower((string)$key);
            if (in_array($lk, ['api_key', 'apikey', 'api_secret', 'apisecret', 'secret', 'webhook_secret', 'password', 'authorization'], true)) {
                $clean[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = katale_payment_log_sanitize($value);
                continue;
            }
            $clean[$key] = $value;
        }

        error_log('[pesajet][' . $event . '] ' . json_encode($clean, JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('katale_payment_log_sanitize')) {
    /** Recursively masks phone numbers inside a payload before logging. */
    function katale_payment_log_sanitize(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $out[$key] = katale_payment_log_sanitize($value);
                continue;
            }
            $lk = strtolower((string)$key);
            if (in_array($lk, ['phonenumber', 'phone', 'phone_number', 'msisdn'], true) && is_string($value)) {
                $out[$key] = PesaJetService::maskPhone($value);
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }
}

class PesaJetService
{
    /** Official PesaJet status → internal payment status. */
    public const STATUS_MAP = [
        'PENDING'    => 'pending',
        'PROCESSING' => 'processing',
        'COMPLETED'  => 'paid',
        'FAILED'     => 'failed',
        'EXPIRED'    => 'expired',
        'CANCELLED'  => 'cancelled',
    ];

    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $base = rtrim((string)PESAJET_BASE_URL, '/');
        // Accept both "https://host" and "https://host/api/v1" forms.
        if (!str_ends_with($base, '/api/v1')) {
            $base .= '/api/v1';
        }
        $this->baseUrl = $base;
        $this->apiKey = (string)PESAJET_API_KEY;
    }

    /** True when the API key + base URL are configured (no simulation ever). */
    public static function isConfigured(): bool
    {
        return trim((string)PESAJET_API_KEY) !== '' && trim((string)PESAJET_BASE_URL) !== '';
    }

    /** True when webhook signatures can be verified. */
    public static function webhookConfigured(): bool
    {
        return trim((string)PESAJET_WEBHOOK_SECRET) !== '';
    }

    /* ------------------------------------------------------------------ */
    /* Phone / status helpers                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Normalize a Ugandan mobile number to E.164 ("+256771234567").
     * Accepts 0771234567 / +256771234567 / 256771234567 / 771234567 forms.
     * Returns null when the input is not a plausible UG MSISDN.
     */
    public static function normalizePhoneNumber(string $raw): ?string
    {
        $digits = preg_replace('/[\s\-().]/', '', trim($raw));
        if ($digits === '' || !preg_match('/^\+?\d{7,15}$/', $digits)) {
            return null;
        }

        if (str_starts_with($digits, '+')) {
            $digits = substr($digits, 1);
        }

        if (preg_match('/^0\d{9}$/', $digits)) {            // 0771234567
            $digits = '256' . substr($digits, 1);
        } elseif (preg_match('/^256\d{9}$/', $digits)) {    // 256771234567
            // already international
        } elseif (preg_match('/^7\d{8}$/', $digits)) {      // 771234567 (no trunk 0)
            $digits = '256' . $digits;
        } else {
            return null;
        }

        if (!preg_match('/^256\d{9}$/', $digits)) {
            return null;
        }

        return '+' . $digits;
    }

    /**
     * Detect the mobile-money network from a normalized UG number using the
     * official prefix rules (MTN: 77/78/76/39, Airtel: 70/75/74).
     * Returns 'mtn', 'airtel' or null.
     */
    public static function providerFromPhone(string $e164): ?string
    {
        if (!preg_match('/^\+256\d{9}$/', $e164)) {
            return null;
        }
        $prefix = substr($e164, 4, 2); // digits after +256
        if (in_array($prefix, ['77', '78', '76', '39'], true)) {
            return 'mtn';
        }
        if (in_array($prefix, ['70', '75', '74'], true)) {
            return 'airtel';
        }
        return null;
    }

    /** Mask a phone number for logs/debug ("+256771234567" → "********4567"). */
    public static function maskPhone(string $phone): string
    {
        $phone = trim($phone);
        $len = strlen($phone);
        if ($len <= 4) {
            return $phone === '' ? '' : '****';
        }
        return str_repeat('*', $len - 4) . substr($phone, -4);
    }

    /**
     * Map a PesaJet transaction status to the internal payment status.
     * Returns null for unknown values (callers must not guess).
     */
    public static function mapStatus(?string $remote): ?string
    {
        if (!is_string($remote) || $remote === '') {
            return null;
        }
        return self::STATUS_MAP[strtoupper($remote)] ?? null;
    }

    /* ------------------------------------------------------------------ */
    /* Webhook signature verification                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Constant-time HMAC-SHA256 verification of the X-Webhook-Signature
     * header against the RAW request body (read BEFORE any JSON decoding).
     *
     * Matches the official PesaJet reference implementation, including its
     * fallback where a top-level "signature" field is excluded from the
     * signed payload.
     */
    public static function verifyWebhookSignature(string $rawBody, string $signature, ?string $secret = null): bool
    {
        $secret = $secret ?? (string)PESAJET_WEBHOOK_SECRET;
        $signature = trim($signature);

        if ($secret === '' || $signature === '' || $rawBody === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);
        if (hash_equals(strtolower($expected), strtolower($signature))) {
            return true;
        }

        // Official verifier quirk: if the JSON body carries a "signature"
        // field, it is removed before hashing.
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded) && array_key_exists('signature', $decoded)) {
            unset($decoded['signature']);
            $reencoded = json_encode($decoded);
            if (is_string($reencoded)) {
                $expected = hash_hmac('sha256', $reencoded, $secret);
                if (hash_equals(strtolower($expected), strtolower($signature))) {
                    return true;
                }
            }
        }

        return false;
    }

    /* ------------------------------------------------------------------ */
    /* API calls                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Create a mobile-money collection prompt (POST /payments).
     *
     * @param array $params {
     *     @type int         $amount         Positive whole-shilling amount
     *     @type string      $currency       ISO code, normally UGX
     *     @type string      $phoneNumber    E.164, e.g. +256771234567
     *     @type string      $provider       'mtn' | 'airtel'
     *     @type string      $reference      MasakaMart reference (MM-PAY-...)
     *     @type string|null $description    Optional description
     *     @type array|null  $metadata       Optional metadata
     *     @type string|null $idempotencyKey Optional Idempotency-Key header
     * }
     * @return array{ok:bool, http_code:int, data:array|null, error:string|null, error_code:string|null}
     */
    public function createPayment(array $params): array
    {
        $body = [
            'type'        => 'COLLECTION',
            'amount'      => (int)($params['amount'] ?? 0),
            'currency'    => (string)($params['currency'] ?? 'UGX'),
            'phoneNumber' => (string)($params['phoneNumber'] ?? ''),
            'provider'    => (string)($params['provider'] ?? ''),
            'reference'   => (string)($params['reference'] ?? ''),
        ];
        if (!empty($params['description'])) {
            $body['description'] = (string)$params['description'];
        }
        if (!empty($params['metadata']) && is_array($params['metadata'])) {
            $body['metadata'] = $params['metadata'];
        }

        $headers = [];
        if (!empty($params['idempotencyKey'])) {
            $headers['Idempotency-Key'] = (string)$params['idempotencyKey'];
        }

        return $this->request('POST', '/payments', $body, $headers);
    }

    /** Read a transaction's live status (GET /payments/{transactionId}). */
    public function getPayment(string $transactionId): array
    {
        $transactionId = trim($transactionId);
        if ($transactionId === '' || !preg_match('/^[A-Za-z0-9_\-]+$/', $transactionId)) {
            return ['ok' => false, 'http_code' => 0, 'data' => null, 'error' => 'Invalid transaction id', 'error_code' => 'INVALID_ARGUMENT'];
        }
        return $this->request('GET', '/payments/' . rawurlencode($transactionId));
    }

    /**
     * Fee preview (GET /payments/preview). Moves no money — used as a safe
     * credential/base-URL probe (tests/pesajet_api_probe.php).
     */
    public function previewFee(int $amount, string $provider): array
    {
        $query = http_build_query([
            'amount'   => $amount,
            'provider' => $provider,
            'type'     => 'COLLECTION',
        ]);
        return $this->request('GET', '/payments/preview?' . $query);
    }

    /* ------------------------------------------------------------------ */
    /* Low-level request                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Perform an API request. Server-to-server only; never callable from
     * the browser. Errors are returned as data — no secrets are included.
     *
     * @return array{ok:bool, http_code:int, data:array|null, error:string|null, error_code:string|null}
     */
    private function request(string $method, string $path, ?array $body = null, array $extraHeaders = []): array
    {
        $url = $this->baseUrl . $path;

        if ($this->apiKey === '') {
            katale_payment_log('api_not_configured', ['endpoint' => $url]);
            return ['ok' => false, 'http_code' => 0, 'data' => null, 'error' => 'PesaJet API key is not configured', 'error_code' => 'NOT_CONFIGURED'];
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-Key: ' . $this->apiKey, // official authentication header
        ];
        foreach ($extraHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            $encoded = json_encode($body);
            if ($encoded === false) {
                curl_close($ch);
                return ['ok' => false, 'http_code' => 0, 'data' => null, 'error' => 'Failed to encode request body', 'error_code' => 'ENCODE_ERROR'];
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
        }

        $start = microtime(true);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $elapsedMs = (int)round((microtime(true) - $start) * 1000);

        if ($raw === false || $errno !== 0) {
            katale_payment_log('api_request_error', [
                'method'     => $method,
                'endpoint'   => $url,
                'curl_errno' => $errno,
                'error'      => $error,
                'elapsed_ms' => $elapsedMs,
            ]);
            $code = in_array($errno, [CURLE_OPERATION_TIMEOUTED, CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST], true)
                ? 'TIMEOUT' : 'NETWORK_ERROR';
            return ['ok' => false, 'http_code' => 0, 'data' => null, 'error' => 'Network request failed', 'error_code' => $code];
        }

        $data = null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $data = is_array($decoded) ? $decoded : ['message' => $raw];
        }

        $ok = $httpCode >= 200 && $httpCode < 300;
        $result = [
            'ok'         => $ok,
            'http_code'  => $httpCode,
            'data'       => $data,
            'error'      => $ok ? null : (string)(($data['message'] ?? $data['error'] ?? null) ?: ('HTTP ' . $httpCode)),
            'error_code' => $ok ? null : (string)($data['errorCode'] ?? null ?: 'HTTP_' . $httpCode),
        ];

        // Debug trace (sanitized — phone numbers masked, no credentials).
        if (PESAJET_DEBUG) {
            katale_payment_log('api_request', [
                'method'     => $method,
                'endpoint'   => $url,
                'http_code'  => $httpCode,
                'elapsed_ms' => $elapsedMs,
                'reference'  => $body['reference'] ?? null,
                'ok'         => $ok,
                'response'   => $ok
                    ? katale_payment_log_sanitize((array)$data)
                    : ['error' => $result['error'], 'errorCode' => $result['error_code']],
            ]);
        }

        return $result;
    }
}


