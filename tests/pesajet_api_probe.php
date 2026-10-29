<?php
/**
 * PesaJet integration probe + unit tests (CLI only).
 *
 * Usage:
 *   php tests/pesajet_api_probe.php          # config + pure-function tests
 *   php tests/pesajet_api_probe.php --live   # ALSO calls the real, money-free
 *                                             # GET /payments/preview endpoint
 *                                             # to validate credentials/base URL
 *
 * NEVER prints secrets — only whether they are set (masked).
 * The --live probe moves no money and creates no transactions.
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/includes/PesaJetService.php';

$pass = 0;
$fail = 0;
function T(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else { $fail++; echo "[FAIL] $name $detail\n"; }
}

echo "== Configuration ==\n";
T('PESAJET_BASE_URL set', trim((string)PESAJET_BASE_URL) !== '', PESAJET_BASE_URL);
echo '     API key: ' . (trim((string)PESAJET_API_KEY) !== '' ? '[set]' : '[missing]') . "\n";
echo '     API secret: ' . (trim((string)PESAJET_API_SECRET) !== '' ? '[set]' : '[missing]') . "\n";
echo '     Webhook secret: ' . (trim((string)PESAJET_WEBHOOK_SECRET) !== '' ? '[set]' : '[missing]') . "\n";
echo '     PAYMENT_ENVIRONMENT: ' . PAYMENT_ENVIRONMENT . "\n";
echo '     PESAJET_DEBUG: ' . (PESAJET_DEBUG ? 'true' : 'false') . "\n";

echo "\n== Phone normalization ==\n";
T('0771234567 → +256771234567', PesaJetService::normalizePhoneNumber('0771234567') === '+256771234567');
T('+256771234567 → +256771234567', PesaJetService::normalizePhoneNumber('+256771234567') === '+256771234567');
T('256771234567 → +256771234567', PesaJetService::normalizePhoneNumber('256771234567') === '+256771234567');
T('771234567 → +256771234567', PesaJetService::normalizePhoneNumber('771234567') === '+256771234567');
T('0771 234 567 (spaces) → +256771234567', PesaJetService::normalizePhoneNumber('0771 234 567') === '+256771234567');
T('garbage rejected', PesaJetService::normalizePhoneNumber('abc') === null);
T('too short rejected', PesaJetService::normalizePhoneNumber('0771') === null);
T('foreign number rejected', PesaJetService::normalizePhoneNumber('+254712345678') === null);

echo "\n== Provider detection ==\n";
T('0771… is MTN', PesaJetService::providerFromPhone('+256771234567') === 'mtn');
T('0751… is Airtel', PesaJetService::providerFromPhone('+256751234567') === 'airtel');
T('0701… is Airtel', PesaJetService::providerFromPhone('+256701234567') === 'airtel');
T('0781… is MTN', PesaJetService::providerFromPhone('+256781234567') === 'mtn');
T('unknown prefix rejected', PesaJetService::providerFromPhone('+256711234567') === null);

echo "\n== Status mapping (official PesaJet values only) ==\n";
T('PENDING → pending', PesaJetService::mapStatus('PENDING') === 'pending');
T('PROCESSING → processing', PesaJetService::mapStatus('PROCESSING') === 'processing');
T('COMPLETED → paid', PesaJetService::mapStatus('COMPLETED') === 'paid');
T('FAILED → failed', PesaJetService::mapStatus('FAILED') === 'failed');
T('EXPIRED → expired', PesaJetService::mapStatus('EXPIRED') === 'expired');
T('CANCELLED → cancelled', PesaJetService::mapStatus('CANCELLED') === 'cancelled');
T('unknown status → null (never guessed)', PesaJetService::mapStatus('SOMETHING_ELSE') === null);
T('empty status → null', PesaJetService::mapStatus('') === null);

echo "\n== Webhook signature verification (HMAC-SHA256 + hash_equals) ==\n";
$testSecret = 'whsec_unit_test_secret';
$body = json_encode([
    'event' => 'payment.completed',
    'transactionId' => 'txn_test_123',
    'status' => 'COMPLETED',
    'amount' => 25000,
    'currency' => 'UGX',
    'provider' => 'MTN',
    'reference' => 'MM-PAY-20260924-TEST1',
    'timestamp' => '2026-09-24T10:00:00.000Z',
]);
$goodSig = hash_hmac('sha256', $body, $testSecret);

T('valid signature accepted', PesaJetService::verifyWebhookSignature($body, $goodSig, $testSecret) === true);
T('wrong secret rejected', PesaJetService::verifyWebhookSignature($body, $goodSig, 'whsec_wrong') === false);
T('tampered body rejected', PesaJetService::verifyWebhookSignature(str_replace('25000', '99999', $body), $goodSig, $testSecret) === false);
T('forged signature rejected', PesaJetService::verifyWebhookSignature($body, str_repeat('a', 64), $testSecret) === false);
T('empty signature rejected', PesaJetService::verifyWebhookSignature($body, '', $testSecret) === false);
T('empty secret rejected', PesaJetService::verifyWebhookSignature($body, $goodSig, '') === false);
T('uppercase hex signature accepted (case-insensitive hex)',
    PesaJetService::verifyWebhookSignature($body, strtoupper($goodSig), $testSecret) === true);

// Official verifier quirk: body carrying a top-level "signature" field is
// hashed WITHOUT that field.
$bodyWithSig = json_encode(['event' => 'webhook.test', 'transactionId' => 'txn_1', 'signature' => 'ignored']);
$unsignedPart = json_encode(['event' => 'webhook.test', 'transactionId' => 'txn_1']);
$quirkSig = hash_hmac('sha256', $unsignedPart, $testSecret);
T('body with top-level "signature" field verified (official quirk)',
    PesaJetService::verifyWebhookSignature($bodyWithSig, $quirkSig, $testSecret) === true);

echo "\n== Optional live API probe (money-free) ==\n";
if (in_array('--live', $argv, true)) {
    if (trim((string)PESAJET_API_KEY) === '') {
        T('live probe skipped — API key not configured', false);
    } else {
        $service = new PesaJetService();
        $result = $service->previewFee(1000, 'mtn');
        T('GET /payments/preview reachable (HTTP ' . ($result['http_code'] ?: 'n/a') . ')',
            $result['http_code'] > 0,
            'error=' . (string)$result['error']);
        if ($result['ok']) {
            echo '       Credentials + base URL accepted by PesaJet.' . "\n";
        }
    }
} else {
    echo "     (skipped — run with --live to validate credentials against the real API)\n";
}

echo "\n== Result: $pass passed, $fail failed ==\n";
exit($fail > 0 ? 1 : 0);

