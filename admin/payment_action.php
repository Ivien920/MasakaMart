<?php
/**
 * POST /admin/payment_action.php — the ONLY endpoint that changes a payment
 * status on an administrator's behalf.
 *
 * Server-side controls, in order:
 *   1. admin session required            (require_admin)
 *   2. POST only
 *   3. CSRF token verified               (require_csrf)
 *   4. the admin id is re-checked against the database inside the service
 *      (active account + known role) — the session copy is not trusted
 *   5. the decision runs in one DB transaction with audit logging
 * The browser only ever receives a redirect with a flash message, so a status
 * can never be "changed" on the client alone.
 */
require_once __DIR__ . '/includes/admin_bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
require_csrf();

$admin = current_admin();
$adminId = (int)($admin['admin_id'] ?? 0);
$paymentId = (int)($_POST['payment_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$note = (string)($_POST['note'] ?? '');

$lookup = $db->prepare("SELECT order_id FROM payments WHERE payment_id = :pid LIMIT 1");
$lookup->execute(['pid' => $paymentId]);
$orderIdForReturn = (int)($lookup->fetch()['order_id'] ?? 0);

switch ((string)($_POST['return'] ?? 'detail')) {
    case 'list':
        $returnUrl = APP_URL . '/admin/payments.php';
        break;
    case 'order':
        $returnUrl = $orderIdForReturn > 0
            ? APP_URL . '/admin/order_detail.php?id=' . $orderIdForReturn
            : APP_URL . '/admin/payments.php';
        break;
    default:
        $returnUrl = $paymentId > 0
            ? APP_URL . '/admin/payment_detail.php?id=' . $paymentId
            : APP_URL . '/admin/payments.php';
}

if ($paymentId <= 0 || $orderIdForReturn <= 0) {
    flash('error', 'Payment not found.');
    redirect(APP_URL . '/admin/payments.php');
}

switch ($action) {
    case 'mark_paid':
    case 'mark_failed':
    case 'mark_pending':
        $result = katale_admin_set_payment_status(
            $db,
            $paymentId,
            substr($action, 5),
            $adminId,
            $note,
            [
                'transaction_reference' => (string)($_POST['transaction_reference'] ?? ''),
                'acknowledge_override' => !empty($_POST['acknowledge_override']),
            ]
        );
        break;

    case 'verify':
        $result = katale_admin_verify_payment($db, $paymentId, $adminId);
        break;

    case 'resolve':
        $result = katale_admin_resolve_reconciliation($db, $paymentId, $adminId, $note);
        break;

    default:
        $result = ['ok' => false, 'error' => 'Unknown action.'];
}

if (!empty($result['ok'])) {
    flash('success', (string)($result['message'] ?? 'Done.'));
} else {
    flash('error', (string)($result['error'] ?? 'The action could not be completed.'));
}
redirect($returnUrl);
