<?php
/**
 * Payment status poll (JSON) — GET checkout/payment_status_ajax.php?order=KT-10006
 *
 * Used by the "Check Payment Status" button on payment_status.php.
 *
 * Security:
 *   - requires a logged-in customer who OWNS the order (server-side check)
 *   - never accepts an amount/status/anything else from the client
 *   - when the local row is still awaiting confirmation, the server itself
 *     queries the real PesaJet API (GET /payments/{transactionId}, X-API-Key)
 *     and runs the same idempotent apply path as the webhook. The webhook
 *     remains the primary confirmation channel; this poll only mirrors the
 *     authenticated server-to-server response.
 *   - returns only internal statuses — never credentials or API payloads.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function payment_poll_respond(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    payment_poll_respond(405, ['error' => 'Method not allowed']);
}
if (!is_logged_in()) {
    payment_poll_respond(401, ['error' => 'Authentication required']);
}

$user = current_user();
$orderNumber = trim((string)($_GET['order'] ?? ''));
$order = katale_order_for_payment($db, $orderNumber, (int)$user['user_id']);
if (!$order) {
    payment_poll_respond(404, ['error' => 'Order not found']);
}

$payment = katale_get_order_payment($db, (int)$order['order_id']);
$state = katale_payment_page_state($order, $payment);

// Only poll PesaJet when we are genuinely waiting on a remote transaction.
if (
    $payment
    && in_array((string)$payment['status'], ['pending', 'processing'], true)
    && trim((string)($payment['pesajet_transaction_id'] ?? '')) !== ''
    && katale_payment_is_configured()
) {
    $service = new PesaJetService();
    $response = $service->getPayment((string)$payment['pesajet_transaction_id']);

    if ($response['ok'] && is_array($response['data'])) {
        $data = $response['data'];
        $remoteStatus = isset($data['status']) ? (string)$data['status'] : null;
        $internal = PesaJetService::mapStatus($remoteStatus);

        // Reconcile the response against OUR local transaction before use.
        $amountMatches = isset($data['amount']) && abs((float)$data['amount'] - (float)$payment['amount']) <= 0.01;
        $currencyMatches = !isset($data['currency']) || strtoupper((string)$data['currency']) === 'UGX';
        $txnMatches = !isset($data['transactionId'])
            || hash_equals((string)$payment['pesajet_transaction_id'], (string)$data['transactionId']);

        if ($internal !== null && $amountMatches && $currencyMatches && $txnMatches) {
            katale_apply_payment_status($db, $payment, $internal, [
                'source' => 'status_poll',
                'remote_status' => $remoteStatus,
                'pesajet_transaction_id' => (string)$payment['pesajet_transaction_id'],
                'provider_reference' => isset($data['providerReference']) && $data['providerReference'] ? (string)$data['providerReference'] : null,
                'failure_reason' => isset($data['failureReason']) && $data['failureReason'] ? (string)$data['failureReason'] : null,
            ]);
        } else {
            katale_payment_log('status_poll_rejected', [
                'payment_id' => (int)$payment['payment_id'],
                'remote_status' => $remoteStatus,
                'amount_matches' => $amountMatches,
                'currency_matches' => $currencyMatches,
                'txn_matches' => $txnMatches,
            ]);
        }
    } else {
        katale_payment_log('status_poll_api_error', [
            'payment_id' => (int)$payment['payment_id'],
            'error_code' => $response['error_code'] ?? 'UNKNOWN',
        ]);
    }

    // Re-read fresh state after the poll.
    $order = katale_order_for_payment($db, $orderNumber, (int)$user['user_id']);
    $payment = katale_get_order_payment($db, (int)$order['order_id']);
    $state = katale_payment_page_state($order, $payment);
}

payment_poll_respond(200, [
    'state' => $state,
    'payment_status' => $payment ? (string)$payment['status'] : null,
    'order_status' => (string)$order['order_status'],
    'order_payment_status' => (string)$order['payment_status'],
]);
