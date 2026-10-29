<?php
/**
 * PesaJet webhook endpoint — POST checkout/payment_webhook.php
 *
 * Registered in the PesaJet dashboard as PAYMENT_WEBHOOK_URL (resolved in
 * config/config.php). Implements the official reference flow:
 *
 *   1. Read the RAW request body (php://input) BEFORE any JSON decoding.
 *   2. Verify X-Webhook-Signature = hex(HMAC-SHA256(raw_body, secret))
 *      in constant time with hash_equals(). Invalid → 401, nothing processed.
 *   3. Validate the payload structure (event, transactionId, status, amount,
 *      currency, reference) and reconcile it against the LOCAL transaction
 *      (amount, currency, reference must match — an unrelated webhook can
 *      never touch another order).
 *   4. Idempotency: payment_webhook_events has UNIQUE(transaction_id, event)
 *      so retried deliveries are acknowledged without side effects.
 *   5. Apply the status exactly once via katale_apply_payment_status()
 *      (updates payment + order + fires notifications exactly once).
 *   6. Respond 200 quickly (PesaJet retries on 4xx/5xx with backoff).
 *
 * No CSRF check here — this is a server-to-server endpoint authenticated by
 * the HMAC signature instead. Never logs secrets or webhook payloads raw.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/PesaJetService.php';
require_once __DIR__ . '/../includes/payment_service.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function pesajet_webhook_respond(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pesajet_webhook_respond(405, ['error' => 'Method not allowed']);
}

// ---- 1. RAW body first -------------------------------------------------
$rawPayload = file_get_contents('php://input');
if (!is_string($rawPayload) || $rawPayload === '') {
    pesajet_webhook_respond(400, ['error' => 'Empty payload']);
}
if (strlen($rawPayload) > 262144) { // 256 KB sanity limit
    pesajet_webhook_respond(413, ['error' => 'Payload too large']);
}

$signature = (string)($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '');

// ---- 2. Signature verification (constant time) -------------------------
if (!PesaJetService::webhookConfigured()) {
    // Never process webhooks when the signing secret is missing.
    katale_payment_log('webhook_rejected', ['reason' => 'webhook_secret_not_configured']);
    pesajet_webhook_respond(503, ['error' => 'Webhook is not configured']);
}

if (!PesaJetService::verifyWebhookSignature($rawPayload, $signature)) {
    katale_payment_log('webhook_signature_invalid', [
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'payload_length' => strlen($rawPayload),
        'has_signature_header' => $signature !== '',
    ]);
    pesajet_webhook_respond(401, ['error' => 'Unauthorized']);
}

// ---- 3. Parse the (now authenticated) payload --------------------------
$event = json_decode($rawPayload, true);
if (!is_array($event)) {
    katale_payment_log('webhook_malformed', ['reason' => 'invalid_json']);
    pesajet_webhook_respond(400, ['error' => 'Malformed payload']);
}

$eventType = trim((string)($event['event'] ?? ''));
$transactionId = trim((string)($event['transactionId'] ?? ''));

if ($eventType === '' || $transactionId === '') {
    katale_payment_log('webhook_malformed', ['reason' => 'missing_required_fields']);
    pesajet_webhook_respond(400, ['error' => 'Missing required fields']);
}

if (PESAJET_DEBUG) {
    katale_payment_log('webhook_received', [
        'event' => $eventType,
        'transaction_id' => $transactionId,
        'signature_valid' => true,
    ]);
}

// ---- 4. Diagnostic ping from the PesaJet dashboard ---------------------
if ($eventType === 'webhook.test') {
    katale_payment_log('webhook_test_ping', ['transaction_id' => $transactionId]);
    pesajet_webhook_respond(200, ['received' => true, 'event' => $eventType]);
}

if (!in_array($eventType, ['payment.completed', 'payment.failed', 'payment.expired'], true)) {
    // Acknowledge unknown/future event types so PesaJet does not retry
    // for 24h — but record that we did not act on them.
    katale_payment_log('webhook_event_ignored', ['event' => $eventType, 'transaction_id' => $transactionId]);
    pesajet_webhook_respond(200, ['received' => true, 'ignored' => true]);
}

// ---- 5. Validate required business fields ------------------------------
$remoteStatus = trim((string)($event['status'] ?? ''));
$amount = $event['amount'] ?? null;
$currency = strtoupper(trim((string)($event['currency'] ?? '')));
$reference = trim((string)($event['reference'] ?? ''));
$providerReference = trim((string)($event['providerReference'] ?? ''));
$failureReason = isset($event['failureReason']) ? trim((string)$event['failureReason']) : null;

if ($remoteStatus === '' || $amount === null || !is_numeric($amount) || $reference === '') {
    katale_payment_log('webhook_malformed', ['reason' => 'missing_business_fields', 'event' => $eventType]);
    pesajet_webhook_respond(400, ['error' => 'Missing required fields']);
}

$internalStatus = PesaJetService::mapStatus($remoteStatus);
if ($internalStatus === null) {
    katale_payment_log('webhook_unknown_status', ['status' => $remoteStatus, 'event' => $eventType]);
    pesajet_webhook_respond(400, ['error' => 'Unknown status']);
}

// Event/status coherence — never let one event apply another's status.
$expectedStatus = ['payment.completed' => 'paid', 'payment.failed' => 'failed', 'payment.expired' => 'expired'][$eventType];
if ($internalStatus !== $expectedStatus) {
    katale_payment_log('webhook_event_status_mismatch', [
        'event' => $eventType, 'status' => $remoteStatus, 'mapped' => $internalStatus,
    ]);
    pesajet_webhook_respond(400, ['error' => 'Event/status mismatch']);
}

// ---- 6. Find OUR transaction (never trust the webhook alone) -----------
$db = Database::conn();

$stmt = $db->prepare(
    "SELECT * FROM payments
      WHERE pesajet_transaction_id = :txn
         OR transaction_reference = :ref
      ORDER BY payment_id DESC LIMIT 1"
);
$stmt->execute(['txn' => $transactionId, 'ref' => $reference]);
$payment = $stmt->fetch();

if (!$payment) {
    // Signature was valid but no local transaction matches — record and
    // acknowledge (retrying can never make it match).
    katale_payment_log('webhook_no_matching_transaction', [
        'event' => $eventType, 'transaction_id' => $transactionId, 'reference' => $reference,
    ]);
    pesajet_webhook_respond(200, ['received' => true, 'ignored' => true]);
}

// If we already track a DIFFERENT PesaJet transaction for this payment,
// this webhook refers to something else — never touch the row.
$storedTxn = trim((string)($payment['pesajet_transaction_id'] ?? ''));
if ($storedTxn !== '' && !hash_equals($storedTxn, $transactionId)) {
    katale_payment_log('webhook_transaction_conflict', [
        'payment_id' => (int)$payment['payment_id'],
        'event' => $eventType,
    ]);
    pesajet_webhook_respond(409, ['error' => 'Transaction mismatch']);
}

// Reference must be exactly ours — a webhook for another order/merchant
// reference must never affect this payment.
if (!hash_equals((string)$payment['transaction_reference'], $reference)) {
    katale_payment_log('webhook_reference_mismatch', [
        'payment_id' => (int)$payment['payment_id'], 'event' => $eventType,
    ]);
    pesajet_webhook_respond(409, ['error' => 'Reference mismatch']);
}

// Amount must match the server-side payment amount exactly (within 1 UGX
// cent of tolerance for DECIMAL rounding) and the currency must be UGX.
if ($currency !== 'UGX') {
    katale_payment_log('webhook_currency_mismatch', ['currency' => $currency, 'payment_id' => (int)$payment['payment_id']]);
    pesajet_webhook_respond(409, ['error' => 'Currency mismatch']);
}
if (abs((float)$amount - (float)$payment['amount']) > 0.01) {
    katale_payment_log('webhook_amount_mismatch', [
        'payment_id' => (int)$payment['payment_id'],
        'expected' => (float)$payment['amount'],
        'received' => (float)$amount,
    ]);
    pesajet_webhook_respond(409, ['error' => 'Amount mismatch']);
}

// ---- 7. Idempotency: claim this (transactionId, event) exactly once ----
// payment_webhook_events.transaction_id/event is UNIQUE — a retried
// delivery fails this INSERT and is acknowledged without any side effect
// (no duplicate orders, stock moves, emails or notifications).
$sanitizedPayload = katale_payment_log_sanitize($event);
$recordId = 0;
try {
    $ins = $db->prepare(
        "INSERT INTO payment_webhook_events (transaction_id, event, status, reference, payload, processed)
         VALUES (:txn, :event, :status, :ref, :payload, 0)"
    );
    $ins->execute([
        'txn' => $transactionId,
        'event' => $eventType,
        'status' => $remoteStatus,
        'ref' => $reference,
        'payload' => json_encode($sanitizedPayload, JSON_UNESCAPED_SLASHES),
    ]);
    $recordId = (int)$db->lastInsertId();
} catch (PDOException $pe) {
    $isDuplicate = (string)$pe->getCode() === '23000'
        || stripos($pe->getMessage(), 'Duplicate entry') !== false;
    if ($isDuplicate) {
        try {
            $db->prepare(
                "UPDATE payment_webhook_events
                    SET duplicate_count = duplicate_count + 1
                  WHERE transaction_id = :txn AND event = :event"
            )->execute(['txn' => $transactionId, 'event' => $eventType]);
        } catch (Throwable $ignore) {
            // The duplicate counter is best-effort; the UNIQUE key already
            // guaranteed no duplicate processing.
        }
        katale_payment_log('webhook_duplicate_ignored', [
            'event' => $eventType, 'transaction_id' => $transactionId,
        ]);
        pesajet_webhook_respond(200, ['received' => true, 'duplicate' => true]);
    }
    throw $pe; // real DB failure → 500 so PesaJet retries later
}

// ---- 8. Apply the status (payment + order + notifications, exactly once)
$applyContext = [
    'source' => 'webhook',
    'pesajet_transaction_id' => $transactionId,
    'provider_reference' => $providerReference !== '' ? $providerReference : null,
    'webhook_event' => $eventType,
    'remote_status' => $remoteStatus,
];
if ($failureReason !== null && $failureReason !== '' && $internalStatus !== 'paid') {
    $applyContext['failure_reason'] = $failureReason;
}

$changed = katale_apply_payment_status($db, $payment, $internalStatus, $applyContext);

// A DATABASE failure while applying (not a harmless no-op) must not be
// acknowledged: release the idempotency claim and answer 500 so PesaJet
// retries the delivery instead of the payment result being lost.
if (!$changed && katale_payment_last_apply_error() !== null) {
    try {
        $db->prepare("DELETE FROM payment_webhook_events WHERE webhook_event_id = :id")->execute(['id' => $recordId]);
    } catch (Throwable $ignore) {
        katale_payment_log('webhook_claim_release_failed', ['record_id' => $recordId]);
    }
    katale_payment_log('webhook_apply_failed_retry_requested', [
        'event' => $eventType, 'transaction_id' => $transactionId, 'payment_id' => (int)$payment['payment_id'],
    ]);
    pesajet_webhook_respond(500, ['error' => 'Temporary processing error']);
}

try {
    $db->prepare("UPDATE payment_webhook_events SET processed = 1 WHERE webhook_event_id = :id")
        ->execute(['id' => $recordId]);
} catch (Throwable $ignore) {
    katale_payment_log('webhook_processed_flag_error', ['record_id' => $recordId]);
}

katale_payment_log('webhook_processed', [
    'event' => $eventType,
    'transaction_id' => $transactionId,
    'status' => $internalStatus,
    'payment_id' => (int)$payment['payment_id'],
    'order_id' => (int)$payment['order_id'],
    'transitioned' => $changed,
]);

// ---- 9. Fast, plain 200 acknowledgement --------------------------------
pesajet_webhook_respond(200, [
    'received' => true,
    'event' => $eventType,
    'transactionId' => $transactionId,
    'duplicate' => false,
]);


