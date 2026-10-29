<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/brevo_api.php';

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (PHP_SAPI !== 'cli' && !in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Forbidden');
}

$key = katale_brevo_api_key();
if ($key === '') {
    $key = SMTP_PASSWORD;
}

$ch = curl_init('https://api.brevo.com/v3/account');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => [
        'accept: application/json',
        'api-key: ' . $key,
    ],
]);
$raw = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

header('Content-Type: application/json');
$usable = $code >= 200 && $code < 300;
$message = 'REST API requires BREVO_API_KEY (xkeysib-...) in katale_local.env.php';
if ($curlErr !== '') {
    $message = 'curl error: ' . $curlErr;
} elseif ($usable) {
    $message = 'REST API works with configured key';
} elseif ($code === 401 || $code === 403) {
    $message = 'Configured key is not a valid Brevo REST API key (SMTP xsmtpsib- keys cannot read transactional logs).';
}

echo json_encode([
    'http_status' => $code,
    'api_usable' => $usable,
    'message' => $message,
], JSON_PRETTY_PRINT);
