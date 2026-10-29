<?php
/**
 * Brevo REST helpers for delivery tracing (no credentials in output).
 */

if (!function_exists('katale_brevo_api_key')) {
    function katale_brevo_api_key(): string
    {
        if (defined('BREVO_API_KEY') && trim((string)BREVO_API_KEY) !== '') {
            return trim((string)BREVO_API_KEY);
        }
        $key = katale_runtime_value('BREVO_API_KEY', '');
        if ($key !== '') {
            return $key;
        }
        $smtpPass = defined('SMTP_PASSWORD') ? (string)SMTP_PASSWORD : '';
        if (str_starts_with($smtpPass, 'xkeysib-')) {
            return $smtpPass;
        }

        return '';
    }
}

if (!function_exists('katale_brevo_request')) {
    /**
     * @return array{ok:bool,status:int,body:?array,error:?string}
     */
    function katale_brevo_request(string $method, string $path, ?array $query = null): array
    {
        $apiKey = katale_brevo_api_key();
        if ($apiKey === '') {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'BREVO_API_KEY not configured (SMTP keys cannot query the REST API).'];
        }

        $url = 'https://api.brevo.com/v3' . $path;
        if ($query !== null && $query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'api-key: ' . $apiKey,
            ],
        ];
        $caPath = realpath(__DIR__ . '/../config/cacert.pem');
        if ($caPath && is_file($caPath)) {
            $curlOpts[CURLOPT_CAINFO] = $caPath;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, $curlOpts);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'status' => $status, 'body' => null, 'error' => $curlErr ?: 'curl failed'];
        }

        $decoded = json_decode($raw, true);
        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'status' => $status, 'body' => is_array($decoded) ? $decoded : [], 'error' => null];
        }

        $message = is_array($decoded) ? ($decoded['message'] ?? $decoded['code'] ?? 'HTTP ' . $status) : 'HTTP ' . $status;
        return ['ok' => false, 'status' => $status, 'body' => is_array($decoded) ? $decoded : null, 'error' => (string)$message];
    }
}

if (!function_exists('katale_mask_email')) {
    function katale_mask_email(string $email): string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '[invalid]';
        }
        [$local, $domain] = explode('@', $email, 2);
        $localMask = strlen($local) <= 2 ? '**' : substr($local, 0, 1) . '***' . substr($local, -1);

        return $localMask . '@' . $domain;
    }
}
