<?php
/**
 * Administrator payment control for MasakaMart.
 *
 * An authorised administrator has FINAL operational authority over a payment:
 * they can mark it PAID, FAILED or PENDING (even when the PesaJet callback is
 * missing or late), verify it against PesaJet, and resolve reconciliation
 * conflicts. Automatic provider results still update the system, but they can
 * never silently overwrite an administrator's decision — a disagreement is
 * flagged (payments.needs_reconciliation) for review instead.
 *
 * Every decision runs in ONE database transaction that updates, together:
 *   payments row  +  orders row  +  stock (restore / reinstate)  +
 *   payment_status_history  +  order_admin_actions (the audit log).
 * If any step fails, nothing is committed. Customer notifications are sent
 * only after the commit and can never undo or fail a decision.
 *
 * Authorisation is checked here, server-side, against the database (active
 * admin, known role) — the browser, the session copy of the admin row and the
 * form fields are never trusted. CSRF is enforced by the HTTP endpoint
 * (admin/payment_action.php) before any function here is called.
 */

if (!function_exists('katale_admin_authorize')) {
    /** Fresh DB check that this admin id is an active, authorised administrator. */
    function katale_admin_authorize(PDO $db, int $adminId): ?array
    {
        if ($adminId <= 0) {
            return null;
        }
        $stmt = $db->prepare("SELECT admin_id, full_name, role, is_active FROM admins WHERE admin_id = :id LIMIT 1");
        $stmt->execute(['id' => $adminId]);
        $row = $stmt->fetch();
        if (!$row || (int)$row['is_active'] !== 1 || !in_array((string)$row['role'], ['admin', 'super_admin'], true)) {
            return null;
        }
        return $row;
    }
}

if (!function_exists('katale_admin_audit_insert')) {
    /** Strict audit insert — used INSIDE the decision transaction (failure rolls everything back). */
    function katale_admin_audit_insert(PDO $db, int $orderId, ?int $paymentId, int $adminId, string $actionType, array $f): void
    {
        $db->prepare(
            "INSERT INTO order_admin_actions
                (order_id, payment_id, admin_id, action_type, previous_order_status, new_order_status,
                 previous_payment_status, new_payment_status, note, transaction_reference)
             VALUES (:oid, :pid, :aid, :type, :pos, :nos, :pps, :nps, :note, :ref)"
        )->execute([
            'oid' => $orderId,
            'pid' => $paymentId,
            'aid' => $adminId,
            'type' => $actionType,
            'pos' => $f['previous_order_status'] ?? null,
            'nos' => $f['new_order_status'] ?? null,
            'pps' => $f['previous_payment_status'] ?? null,
            'nps' => $f['new_payment_status'] ?? null,
            'note' => isset($f['note']) ? substr((string)$f['note'], 0, 500) : null,
            'ref' => $f['transaction_reference'] ?? null,
        ]);
    }
}

if (!function_exists('katale_admin_set_payment_status')) {
    /**
     * Administrator decision: mark a payment PAID, FAILED or PENDING.
     *
     * @param string $target 'paid' | 'failed' | 'pending'
     * @param string $note   Required reason / verification note (min 10 chars)
     * @param array  $opts   transaction_reference?: string (evidence, optional)
     *                       acknowledge_override?: bool (admin ticked the box
     *                       confirming they are overriding a provider result
     *                       or reinstating a cancelled order)
     * @return array{ok:bool, message?:string, error?:string, needs_ack?:bool, already?:bool}
     */
    function katale_admin_set_payment_status(PDO $db, int $paymentId, string $target, int $adminId, string $note, array $opts = []): array
    {
        $target = strtolower(trim($target));
        if (!in_array($target, ['paid', 'failed', 'pending'], true)) {
            return ['ok' => false, 'error' => 'Unknown payment decision.'];
        }

        $admin = katale_admin_authorize($db, $adminId);
        if (!$admin) {
            return ['ok' => false, 'error' => 'You are not authorised to change payment statuses.'];
        }

        $note = trim($note);
        if (mb_strlen($note) < 10) {
            return ['ok' => false, 'error' => 'Please give a reason or verification note (at least 10 characters). It is saved in the audit log.'];
        }
        if (mb_strlen($note) > 500) {
            return ['ok' => false, 'error' => 'The note is too long (500 characters maximum).'];
        }

        $ref = trim((string)($opts['transaction_reference'] ?? ''));
        if ($ref !== '' && !preg_match('/^[A-Za-z0-9._\-\/ ]{4,100}$/', $ref)) {
            return ['ok' => false, 'error' => 'The transaction reference format is invalid.'];
        }
        $ack = !empty($opts['acknowledge_override']);

        katale_ensure_payment_control_schema($db);

        $notify = null; // set inside the transaction, executed after commit

        try {
            $db->beginTransaction();

            $payStmt = $db->prepare("SELECT * FROM payments WHERE payment_id = :pid FOR UPDATE");
            $payStmt->execute(['pid' => $paymentId]);
            $payment = $payStmt->fetch();
            if (!$payment) {
                $db->rollBack();
                return ['ok' => false, 'error' => 'Payment not found.'];
            }
            $orderId = (int)$payment['order_id'];

            $ordStmt = $db->prepare("SELECT * FROM orders WHERE order_id = :oid FOR UPDATE");
            $ordStmt->execute(['oid' => $orderId]);
            $order = $ordStmt->fetch();
            if (!$order) {
                $db->rollBack();
                return ['ok' => false, 'error' => 'The order for this payment no longer exists.'];
            }

            $prevPay = (string)$payment['status'];
            $prevOrderStatus = (string)$order['order_status'];
            $decision = (string)($payment['admin_decision'] ?? '');
            $type = katale_payment_confirmation_type($payment);
            $orderClosed = $prevOrderStatus === 'cancelled' || (string)($order['inventory_state'] ?? 'deducted') === 'restored';
            $dispatched = in_array($prevOrderStatus, ['out_for_delivery', 'delivered'], true);
            $orderNo = (string)$order['order_number'];

            // ---- No-ops / duplicate protection ------------------------------
            if ($target === 'paid' && $prevPay === 'paid') {
                $db->rollBack();
                return ['ok' => false, 'already' => true, 'error' => "This payment is already marked PAID ({$type['label']}). It will not be processed as paid a second time."];
            }
            if ($target === 'failed' && $prevPay === 'failed' && $decision === 'failed') {
                $db->rollBack();
                return ['ok' => false, 'already' => true, 'error' => 'This payment is already marked FAILED by an administrator.'];
            }
            if ($target === 'pending' && in_array($prevPay, ['pending', 'processing'], true) && $decision === 'pending') {
                $db->rollBack();
                return ['ok' => false, 'already' => true, 'error' => 'This payment is already held as PENDING for review.'];
            }

            // ---- Safeguards -------------------------------------------------
            if ($target === 'failed' && $dispatched) {
                $db->rollBack();
                return ['ok' => false, 'error' => 'This order is already out for delivery or delivered, so it cannot be cancelled by marking the payment failed. Resolve it through delivery/returns first.'];
            }
            if ($prevPay === 'paid' && $type['key'] === 'provider_confirmed' && in_array($target, ['failed', 'pending'], true) && !$ack) {
                $db->rollBack();
                return ['ok' => false, 'needs_ack' => true, 'error' => 'PesaJet confirmed this payment. Tick the box to confirm you are deliberately overriding the provider.'];
            }
            if (in_array($target, ['paid', 'pending'], true) && $orderClosed && !$ack) {
                $db->rollBack();
                return ['ok' => false, 'needs_ack' => true, 'error' => 'This order is cancelled and its stock was released. Tick the box to confirm you want to reinstate it and take the stock back.'];
            }

            // ---- Apply ------------------------------------------------------
            $sets = [
                'admin_decision = :dec',
                'admin_decision_by = :aid',
                'admin_decision_at = NOW()',
                'admin_decision_note = :dnote',
            ];
            $params = ['dec' => $target, 'aid' => $adminId, 'dnote' => $note, 'pid' => $paymentId];
            $newOrderStatus = $prevOrderStatus;
            $stockMsg = '';

            if ((int)($payment['needs_reconciliation'] ?? 0) === 1) {
                $sets[] = 'needs_reconciliation = 0';
                $sets[] = 'reconciliation_resolved_at = NOW()';
                $sets[] = 'reconciliation_resolved_by = :raid';
                $params['raid'] = $adminId;
            }

            if ($target === 'paid' || $target === 'pending') {
                if ($orderClosed) {
                    $reserve = katale_reserve_order_stock_locked($db, $orderId, $adminId);
                    if (!$reserve['ok']) {
                        $db->rollBack();
                        return ['ok' => false, 'error' => $reserve['error'] ?? 'Could not reinstate the order stock.'];
                    }
                    if ($prevOrderStatus === 'cancelled') {
                        $newOrderStatus = 'received';
                    }
                    $stockMsg = ' The order was reinstated and its stock taken back.';
                }
            }

            if ($target === 'paid') {
                $sets[] = "status = 'paid'";
                $sets[] = 'paid_at = COALESCE(paid_at, NOW())';
                $sets[] = 'failure_reason = NULL';
                $sets[] = "verification_source = 'manual'";
                $sets[] = 'manual_verified_by = :mby';
                $sets[] = 'manual_verified_at = NOW()';
                $sets[] = 'manual_note = :mnote';
                $params['mby'] = $adminId;
                $params['mnote'] = $note;
                if ($ref !== '') {
                    $sets[] = 'provider_reference = COALESCE(provider_reference, :pref)';
                    $params['pref'] = $ref;
                }
                $orderPay = 'paid';
            } elseif ($target === 'failed') {
                $sets[] = "status = 'failed'";
                $sets[] = 'failure_reason = :freason';
                $sets[] = 'paid_at = NULL';
                $sets[] = 'verification_source = NULL';
                $params['freason'] = substr($note, 0, 255);
                $orderPay = 'failed';
                $newOrderStatus = 'cancelled';
                $restored = katale_restore_order_stock_locked($db, $orderId, 'order_failed', $adminId);
                $stockMsg = $restored ? ' Stock was released back to inventory.' : '';
            } else { // pending
                $sets[] = "status = 'pending'";
                $sets[] = 'paid_at = NULL';
                $sets[] = 'failure_reason = NULL';
                $sets[] = 'verification_source = NULL';
                $orderPay = 'pending';
            }

            $db->prepare('UPDATE payments SET ' . implode(', ', $sets) . ' WHERE payment_id = :pid')->execute($params);

            $db->prepare(
                "UPDATE orders SET payment_status = :ps, order_status = :os, needs_admin_review = 0 WHERE order_id = :oid"
            )->execute(['ps' => $orderPay, 'os' => $newOrderStatus, 'oid' => $orderId]);

            katale_payment_history_add(
                $db, $paymentId, $orderId, $prevPay, $target, 'admin_decision', $adminId, null,
                $note . ($ref !== '' ? " [ref: {$ref}]" : ''), true
            );
            katale_admin_audit_insert($db, $orderId, $paymentId, $adminId, 'payment_mark_' . $target, [
                'previous_order_status' => $prevOrderStatus,
                'new_order_status' => $newOrderStatus,
                'previous_payment_status' => $prevPay,
                'new_payment_status' => $target,
                'note' => $note,
                'transaction_reference' => $ref !== '' ? $ref : null,
            ]);

            $db->commit();

            $notify = [
                'target' => $target,
                'order_id' => $orderId,
                'order_number' => $orderNo,
                'user_id' => (int)($order['user_id'] ?? 0),
                'method' => (string)$payment['method'],
                'payment_row' => $payment,
                'order_newly_cancelled' => ($newOrderStatus === 'cancelled' && $prevOrderStatus !== 'cancelled'),
                'prev_pay' => $prevPay,
            ];
            $labels = ['paid' => 'PAID (admin-confirmed)', 'failed' => 'FAILED (admin-marked)', 'pending' => 'PENDING (held for review)'];
            $result = ['ok' => true, 'message' => "Payment for order {$orderNo} marked {$labels[$target]}.{$stockMsg}"];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            katale_payment_log('admin_decision_error', ['payment_id' => $paymentId, 'target' => $target, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'The change could not be saved and nothing was changed. Please try again.'];
        }

        // ---- After commit: log + customer notifications (never fatal) ---------
        katale_payment_log('admin_payment_decision', [
            'payment_id' => $paymentId, 'order_id' => $notify['order_id'], 'admin_id' => $adminId,
            'from' => $notify['prev_pay'], 'to' => $target,
        ]);
        try {
            $db->prepare("INSERT INTO admin_activity_logs (admin_id, action, details) VALUES (:a, :act, :d)")
                ->execute(['a' => $adminId, 'act' => 'payment_mark_' . $target, 'd' => "Payment #{$paymentId} ({$notify['order_number']}) {$notify['prev_pay']} -> {$target}"]);
        } catch (Throwable $ignore) {
        }
        katale_admin_notify_customer($db, $notify);

        return $result;
    }
}

if (!function_exists('katale_admin_notify_customer')) {
    /** Customer-facing notifications for an admin decision. Best-effort; never throws. */
    function katale_admin_notify_customer(PDO $db, array $n): void
    {
        try {
            $userId = (int)$n['user_id'];
            $orderId = (int)$n['order_id'];
            $no = (string)$n['order_number'];
            $link = APP_URL . '/orders/order_detail.php?order=' . urlencode($no);

            $inApp = static function (string $title, string $message, string $type) use ($db, $userId, $orderId, $link): void {
                if ($userId > 0 && user_allows_notification($db, $userId, $type, 'in_app')) {
                    $id = create_in_app_notification($db, $userId, $title, $message, $type, $orderId, $link, 'in_app');
                    log_notification_delivery($db, $id, $userId, 'in_app', 'delivered');
                }
            };

            if ($n['target'] === 'paid') {
                // Same single-fire path the gateway uses: in-app "Payment
                // received" + the order-confirmation email for mobile money.
                katale_payment_notify_transition($db, (array)$n['payment_row'], 'paid');
            } elseif ($n['target'] === 'failed') {
                $inApp('Payment failed', "We could not confirm payment for order #{$no}, so the order has been cancelled. Please place a new order or contact support.", 'payment_failed');
                if (!empty($n['order_newly_cancelled'])) {
                    handle_order_status_change($db, $orderId, 'cancelled');
                }
            } else {
                $inApp('Payment under review', "Your payment for order #{$no} is being reviewed. We will update you as soon as it is confirmed.", 'payment_review');
            }
        } catch (Throwable $e) {
            katale_payment_log('admin_notify_error', ['error' => $e->getMessage()]);
        }
    }
}

if (!function_exists('katale_admin_verify_payment')) {
    /**
     * "Verify Payment": ask PesaJet for the transaction's record, validate it
     * against OUR payment row (transaction id, amount, currency, reference)
     * and apply the result through the same path as a webhook. Never changes
     * anything on a network error or a record that does not match.
     *
     * @param object|null $service Injected provider client (tests); defaults to PesaJetService
     * @return array{ok:bool, message?:string, error?:string, outcome:string}
     */
    function katale_admin_verify_payment(PDO $db, int $paymentId, int $adminId, ?object $service = null): array
    {
        if (!katale_admin_authorize($db, $adminId)) {
            return ['ok' => false, 'outcome' => 'unauthorised', 'error' => 'You are not authorised to verify payments.'];
        }
        katale_ensure_payment_control_schema($db);

        $stmt = $db->prepare("SELECT * FROM payments WHERE payment_id = :pid LIMIT 1");
        $stmt->execute(['pid' => $paymentId]);
        $payment = $stmt->fetch();
        if (!$payment) {
            return ['ok' => false, 'outcome' => 'not_found', 'error' => 'Payment not found.'];
        }
        $orderId = (int)$payment['order_id'];
        $prev = (string)$payment['status'];

        $record = static function (string $result, string $note, ?string $newStatus = null) use ($db, $payment, $orderId, $adminId, $prev, $paymentId): void {
            try {
                $db->prepare("UPDATE payments SET provider_verification_result = :r WHERE payment_id = :pid")
                    ->execute(['r' => $result, 'pid' => $paymentId]);
            } catch (Throwable $ignore) {
            }
            katale_log_order_admin_action($db, $orderId, $adminId, 'verify_payment', [
                'payment_id' => $paymentId,
                'previous_payment_status' => $prev,
                'new_payment_status' => $newStatus ?? $prev,
                'note' => $note,
                'transaction_reference' => $payment['transaction_reference'] ?? null,
            ]);
        };

        $txn = trim((string)($payment['pesajet_transaction_id'] ?? ''));
        if ($txn === '') {
            $record('no_transaction', 'Verification not possible: no PesaJet transaction id on this payment.');
            return ['ok' => false, 'outcome' => 'no_transaction', 'error' => 'This payment has no PesaJet transaction id (the request may never have reached PesaJet), so it cannot be checked with the provider. Use Mark as PAID or FAILED with a verification note instead.'];
        }
        if ($service === null) {
            if (!katale_payment_is_configured()) {
                return ['ok' => false, 'outcome' => 'not_configured', 'error' => katale_payment_error_message()];
            }
            $service = new PesaJetService();
        }

        $response = $service->getPayment($txn);
        if (empty($response['ok']) || !is_array($response['data'] ?? null)) {
            $record('unreachable', 'PesaJet could not be reached (' . (string)($response['error_code'] ?? 'UNKNOWN') . '). Nothing changed.');
            return ['ok' => false, 'outcome' => 'unreachable', 'error' => 'PesaJet could not be reached, so nothing was changed. Try again shortly, or use a manual decision with a verification note.'];
        }

        $data = $response['data'];
        $remoteStatus = isset($data['status']) ? (string)$data['status'] : null;
        $internal = PesaJetService::mapStatus($remoteStatus);
        $amountMatches = isset($data['amount']) && is_numeric($data['amount']) && abs((float)$data['amount'] - (float)$payment['amount']) <= 0.01;
        $currencyMatches = !isset($data['currency']) || strtoupper((string)$data['currency']) === strtoupper((string)($payment['currency'] ?: 'UGX'));
        $txnMatches = !isset($data['transactionId']) || hash_equals($txn, (string)$data['transactionId']);
        $refMatches = !isset($data['reference']) || hash_equals((string)$payment['transaction_reference'], (string)$data['reference']);

        if (!$amountMatches || !$currencyMatches || !$txnMatches || !$refMatches) {
            // The provider's record does not describe OUR transaction: change nothing, flag it.
            try {
                $db->prepare(
                    "UPDATE payments SET provider_verification_result = 'mismatch', needs_reconciliation = 1,
                            reconciliation_note = :n, reconciliation_flagged_at = NOW(),
                            reconciliation_resolved_at = NULL, reconciliation_resolved_by = NULL
                      WHERE payment_id = :pid"
                )->execute([
                    'n' => 'PesaJet record does not match this payment (amount/currency/reference/transaction id differ).',
                    'pid' => $paymentId,
                ]);
            } catch (Throwable $ignore) {
            }
            katale_log_order_admin_action($db, $orderId, $adminId, 'verify_payment', [
                'payment_id' => $paymentId, 'previous_payment_status' => $prev, 'new_payment_status' => $prev,
                'note' => 'Provider record mismatch — flagged for reconciliation. Nothing changed.',
                'transaction_reference' => $payment['transaction_reference'] ?? null,
            ]);
            katale_payment_log('admin_verify_mismatch', [
                'payment_id' => $paymentId, 'amount_ok' => $amountMatches, 'currency_ok' => $currencyMatches,
                'txn_ok' => $txnMatches, 'ref_ok' => $refMatches,
            ]);
            return ['ok' => false, 'outcome' => 'mismatch', 'error' => 'The PesaJet record does not match this payment (amount, currency, reference or transaction id differ). Nothing was changed; the payment was flagged for reconciliation.'];
        }
        if ($internal === null) {
            $record('unknown_status', 'PesaJet returned an unrecognised status "' . substr((string)$remoteStatus, 0, 30) . '". Nothing changed.');
            return ['ok' => false, 'outcome' => 'unknown_status', 'error' => 'PesaJet returned a status this system does not recognise, so nothing was changed.'];
        }

        $changed = katale_apply_payment_status($db, $payment, $internal, [
            'source' => 'admin_verify',
            'remote_status' => $remoteStatus,
            'pesajet_transaction_id' => $txn,
            'provider_reference' => !empty($data['providerReference']) ? (string)$data['providerReference'] : null,
            'failure_reason' => !empty($data['failureReason']) ? (string)$data['failureReason'] : null,
        ]);
        if (katale_payment_last_apply_error() !== null) {
            return ['ok' => false, 'outcome' => 'db_error', 'error' => 'The provider result could not be saved. Nothing was changed; please try again.'];
        }

        $stmt->execute(['pid' => $paymentId]);
        $fresh = $stmt->fetch() ?: $payment;
        $new = (string)$fresh['status'];
        $flagged = (int)($fresh['needs_reconciliation'] ?? 0) === 1;
        $upper = strtoupper((string)$remoteStatus);

        if (in_array($internal, ['pending', 'processing'], true)) {
            $outcome = 'still_pending';
            $msg = "PesaJet still shows this transaction as {$upper}. Nothing was changed.";
        } elseif ($changed) {
            $outcome = 'applied';
            $msg = "PesaJet reports {$upper}. The payment and order were updated.";
        } elseif ($flagged) {
            $outcome = 'conflict';
            $msg = "PesaJet reports {$upper}, which disagrees with the current status ({$new}). It was NOT overwritten — the payment is flagged for reconciliation. Review it and decide.";
        } else {
            $outcome = 'confirmed';
            $msg = "PesaJet reports {$upper}, which matches the current status. Verification recorded.";
        }

        katale_log_order_admin_action($db, $orderId, $adminId, 'verify_payment', [
            'payment_id' => $paymentId, 'previous_payment_status' => $prev, 'new_payment_status' => $new,
            'note' => "Provider status {$upper}; outcome: {$outcome}",
            'transaction_reference' => $payment['transaction_reference'] ?? null,
        ]);

        return ['ok' => true, 'outcome' => $outcome, 'message' => $msg];
    }
}

if (!function_exists('katale_admin_resolve_reconciliation')) {
    /** Record that an administrator reviewed a flagged conflict and accepts the current state. */
    function katale_admin_resolve_reconciliation(PDO $db, int $paymentId, int $adminId, string $note): array
    {
        if (!katale_admin_authorize($db, $adminId)) {
            return ['ok' => false, 'error' => 'You are not authorised to resolve reconciliation flags.'];
        }
        $note = trim($note);
        if (mb_strlen($note) < 10) {
            return ['ok' => false, 'error' => 'Please explain how the conflict was resolved (at least 10 characters).'];
        }
        katale_ensure_payment_control_schema($db);

        try {
            $db->beginTransaction();
            $stmt = $db->prepare("SELECT * FROM payments WHERE payment_id = :pid FOR UPDATE");
            $stmt->execute(['pid' => $paymentId]);
            $payment = $stmt->fetch();
            if (!$payment) {
                $db->rollBack();
                return ['ok' => false, 'error' => 'Payment not found.'];
            }
            if ((int)($payment['needs_reconciliation'] ?? 0) !== 1) {
                $db->rollBack();
                return ['ok' => false, 'already' => true, 'error' => 'This payment has no open reconciliation flag.'];
            }
            $orderId = (int)$payment['order_id'];
            $db->prepare(
                "UPDATE payments SET needs_reconciliation = 0, reconciliation_resolved_at = NOW(), reconciliation_resolved_by = :a WHERE payment_id = :pid"
            )->execute(['a' => $adminId, 'pid' => $paymentId]);
            $db->prepare("UPDATE orders SET needs_admin_review = 0 WHERE order_id = :oid")->execute(['oid' => $orderId]);
            katale_payment_history_add($db, $paymentId, $orderId, (string)$payment['status'], (string)$payment['status'], 'admin_decision', $adminId, null, 'Reconciliation resolved: ' . $note, true);
            katale_admin_audit_insert($db, $orderId, $paymentId, $adminId, 'resolve_reconciliation', [
                'previous_payment_status' => (string)$payment['status'],
                'new_payment_status' => (string)$payment['status'],
                'note' => $note,
                'transaction_reference' => $payment['transaction_reference'] ?? null,
            ]);
            $db->commit();
            return ['ok' => true, 'message' => 'Reconciliation flag resolved and recorded in the audit log.'];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            katale_payment_log('admin_resolve_error', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'The flag could not be resolved. Nothing was changed.'];
        }
    }
}

if (!function_exists('katale_admin_payment_timeline')) {
    /**
     * Everything the details page shows as "payment history": status changes
     * from every source, the admin audit log, and provider webhook deliveries.
     *
     * @return array{history:array, actions:array, webhooks:array}
     */
    function katale_admin_payment_timeline(PDO $db, array $payment): array
    {
        $paymentId = (int)$payment['payment_id'];
        $out = ['history' => [], 'actions' => [], 'webhooks' => []];
        try {
            $s = $db->prepare(
                "SELECT h.*, a.full_name AS admin_name FROM payment_status_history h
                   LEFT JOIN admins a ON a.admin_id = h.admin_id
                  WHERE h.payment_id = :pid ORDER BY h.history_id DESC LIMIT 100"
            );
            $s->execute(['pid' => $paymentId]);
            $out['history'] = $s->fetchAll();

            $s = $db->prepare(
                "SELECT x.*, a.full_name AS admin_name FROM order_admin_actions x
                   LEFT JOIN admins a ON a.admin_id = x.admin_id
                  WHERE x.payment_id = :pid ORDER BY x.action_id DESC LIMIT 100"
            );
            $s->execute(['pid' => $paymentId]);
            $out['actions'] = $s->fetchAll();

            $s = $db->prepare(
                "SELECT webhook_event_id, transaction_id, event, status, processed, duplicate_count, created_at
                   FROM payment_webhook_events WHERE reference = :ref OR transaction_id = :txn
                  ORDER BY webhook_event_id DESC LIMIT 50"
            );
            $s->execute(['ref' => (string)$payment['transaction_reference'], 'txn' => (string)($payment['pesajet_transaction_id'] ?? '')]);
            $out['webhooks'] = $s->fetchAll();
        } catch (Throwable $e) {
            katale_payment_log('timeline_error', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);
        }
        return $out;
    }
}

if (!function_exists('katale_admin_payment_ack_text')) {
    /**
     * When a decision needs an explicit "I understand" tick-box, the sentence
     * to show next to it ('' when none is needed). Mirrors the server-side
     * safeguards in katale_admin_set_payment_status(), which remain the
     * authority — this only drives the dialog.
     *
     * @param array $p payment row merged with orders.order_status / orders.inventory_state
     */
    function katale_admin_payment_ack_text(array $p, string $target): string
    {
        $type = katale_payment_confirmation_type($p);
        $closed = (string)($p['order_status'] ?? '') === 'cancelled' || (string)($p['inventory_state'] ?? 'deducted') === 'restored';
        if ($target === 'paid' || $target === 'pending') {
            if ($closed) {
                return 'This order is cancelled and its stock was released. I understand this reinstates the order and takes the stock back.';
            }
        }
        if (in_array($target, ['failed', 'pending'], true) && (string)$p['status'] === 'paid' && $type['key'] === 'provider_confirmed') {
            return 'PesaJet confirmed this payment. I understand I am deliberately overriding the payment provider.';
        }
        return '';
    }
}

if (!function_exists('katale_admin_payment_allowed_actions')) {
    /** Which decision buttons make sense for a payment in its current state. */
    function katale_admin_payment_allowed_actions(array $p): array
    {
        $status = (string)$p['status'];
        $decision = (string)($p['admin_decision'] ?? '');
        $dispatched = in_array((string)($p['order_status'] ?? ''), ['out_for_delivery', 'delivered'], true);
        return [
            'mark_paid' => $status !== 'paid',
            'mark_failed' => !($status === 'failed' && $decision === 'failed') && !$dispatched,
            'mark_pending' => !(in_array($status, ['pending', 'processing'], true) && $decision === 'pending'),
            'verify' => trim((string)($p['pesajet_transaction_id'] ?? '')) !== '',
            'resolve' => (int)($p['needs_reconciliation'] ?? 0) === 1,
        ];
    }
}
