<?php
/**
 * PesaJet webhook endpoint HTTP tests (CLI).
 *
 * Verifies against a running local server that:
 *   - a correctly signed webhook.test ping is accepted (200)
 *   - invalid / missing / tampered signatures are rejected (401)
 *   - malformed payloads are rejected (400) — AFTER signature checks
 *   - a signed payment event for an unknown reference is acknowledged but
 *     touches NOTHING (200, ignored)
 *   - non-POST methods are rejected (405)
 *
 * None of these create orders or mark anything paid — safe for any env.
 *
 * Usage:
 *   php -S localhost:8199 -t <parent-of-katale>
 *   php tests/pesajet_webhook_http_test.php http://localhost:8199/katale
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/includes/PesaJetService.php';

$base = rtrim($argv[1] ?? 'http://localhost:8199/katale', '/');
$webhookUrl = $base . '/checkout/payment_webhook.php';

$pass = 0;
$fail = 0;
function T(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else { $fail++; echo "[FAIL] $name $detail\n"; }
}

function post_webhook(string $url, string $body, string $signature): array
{
    $headers = "Content-Type: application/json\r\nConnection: close\r\n";
    if ($signature !== '') {
        $headers .= 'X-Webhook-Signature: ' . $signature . "\r\n";
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => $headers,
        'content' => $body,
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 20,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#HTTP/\S+\s+(\d+)#', $h, $m)) { $code = (int)$m[1]; }
    }
    return ['code' => $code, 'body' => (string)$resp];
}

if (trim((string)PESAJET_WEBHOOK_SECRET) === '') {
    echo "PESAJET_WEBHOOK_SECRET is not configured — set it in the environment file first.\n";
    exit(1);
}

$secret = (string)PESAJET_WEBHOOK_SECRET;

// ---- 1. Correctly signed diagnostic ping → 200 ------------------------
$ping = json_encode([
    'event' => 'webhook.test',
    'transactionId' => 'txn_test_ping_' . time(),
    'status' => 'COMPLETED',
    'amount' => 5000,
    'currency' => 'UGX',
    'provider' => 'SANDBOX',
    'reference' => 'TEST-PING-001',
    'timestamp' => gmdate('c'),
]);
$r = post_webhook($webhookUrl, $ping, hash_hmac('sha256', $ping, $secret));
T('signed webhook.test → 200', $r['code'] === 200, 'got ' . $r['code'] . ' ' . $r['body']);

// ---- 2. Invalid signature → 401 ---------------------------------------
$r = post_webhook($webhookUrl, $ping, hash_hmac('sha256', $ping, 'whsec_wrong_secret'));
T('invalid signature → 401', $r['code'] === 401, 'got ' . $r['code']);

// ---- 3. Missing signature → 401 ---------------------------------------
$r = post_webhook($webhookUrl, $ping, '');
T('missing signature → 401', $r['code'] === 401, 'got ' . $r['code']);

// ---- 4. Valid signature but malformed JSON → 400 -----------------------
$malformed = '{"event": "webhook.test", broken';
$r = post_webhook($webhookUrl, $malformed, hash_hmac('sha256', $malformed, $secret));
T('signed malformed JSON → 400', $r['code'] === 400, 'got ' . $r['code']);

// ---- 5. Valid signature, missing required fields → 400 -----------------
$missing = json_encode(['event' => 'payment.completed']);
$r = post_webhook($webhookUrl, $missing, hash_hmac('sha256', $missing, $secret));
T('signed payload missing fields → 400', $r['code'] === 400, 'got ' . $r['code']);

// ---- 6. Signed payment event for an UNKNOWN reference → 200, ignored ---
// (No local transaction matches, so nothing can be modified.)
$unknown = json_encode([
    'event' => 'payment.completed',
    'transactionId' => 'txn_unknown_' . time(),
    'status' => 'COMPLETED',
    'amount' => 123456,
    'currency' => 'UGX',
    'provider' => 'MTN',
    'reference' => 'MM-PAY-19990101-NOMATCH',
    'timestamp' => gmdate('c'),
]);
$r = post_webhook($webhookUrl, $unknown, hash_hmac('sha256', $unknown, $secret));
T('signed unknown reference → 200 (acknowledged, ignored)', $r['code'] === 200, 'got ' . $r['code'] . ' ' . $r['body']);
$decoded = json_decode($r['body'], true);
T('unknown reference flagged ignored', is_array($decoded) && (($decoded['ignored'] ?? false) === true), $r['body']);

// ---- 7. Event/status incoherence → 400 --------------------------------
$mismatch = json_encode([
    'event' => 'payment.completed',
    'transactionId' => 'txn_mismatch_' . time(),
    'status' => 'FAILED', // event says completed, status says failed
    'amount' => 1000,
    'currency' => 'UGX',
    'provider' => 'MTN',
    'reference' => 'MM-PAY-19990101-MISMATCH',
    'timestamp' => gmdate('c'),
]);
$r = post_webhook($webhookUrl, $mismatch, hash_hmac('sha256', $mismatch, $secret));
T('event/status mismatch → 400', $r['code'] === 400, 'got ' . $r['code']);

// ---- 8. GET → 405 ------------------------------------------------------
$ctx = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 10]]);
@file_get_contents($webhookUrl, false, $ctx);
$code = 0;
foreach ($http_response_header ?? [] as $h) {
    if (preg_match('#HTTP/\S+\s+(\d+)#', $h, $m)) { $code = (int)$m[1]; }
}
T('GET on webhook → 405', $code === 405, 'got ' . $code);

echo "\n== Result: $pass passed, $fail failed ==\n";
exit($fail > 0 ? 1 : 0);

