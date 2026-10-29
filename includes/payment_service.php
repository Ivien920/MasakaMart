<?php
/**
 * Katale payment abstraction layer — real PesaJet integration.
 *
 * Keeps the storefront checkout cleanly separated from the payment gateway:
 *   - PesaJetService (includes/PesaJetService.php) talks to the REST API.
 *   - This file owns payment rows, the internal status model, the single
 *     idempotent status-application path shared by the webhook and the
 *     authenticated status poll, and the customer notifications that must
 *     fire exactly once per transition.
 *
 * Credentials/environment come from config/config.php (env file) — rotating
 * them never requires a code change. No payment is ever simulated.
 */

if (!function_exists('katale_payment_service')) {
    function katale_payment_service(): array
    {
        $configured = PesaJetService::isConfigured();
        return [
            'provider' => 'pesajet',
            'enabled' => $configured,
            // Read-only view for diagnostics — these values never reach the
            // browser (callers must not expose them in output).
            'api_key_set' => trim((string)PESAJET_API_KEY) !== '',
            'webhook_secret_set' => trim((string)PESAJET_WEBHOOK_SECRET) !== '',
            'base_url' => PESAJET_BASE_URL,
            'environment' => PAYMENT_ENVIRONMENT,
            // Absolute URLs for the payment flow — resolved centrally in
            // config/config.php (derived from APP_URL, env-overridable).
            'return_url' => PAYMENT_RETURN_URL,
            'success_url' => PAYMENT_SUCCESS_URL,
            'failure_url' => PAYMENT_FAILURE_URL,
            'cancel_url' => PAYMENT_CANCEL_URL,
            'callback_url' => PAYMENT_CALLBACK_URL,
            'webhook_url' => PAYMENT_WEBHOOK_URL,
        ];
    }
}

if (!function_exists('katale_payment_method_label')) {
    function katale_payment_method_label(string $method): string
    {
        $labels = [
            'mobile_money' => 'Mobile Money',
            'mtn' => 'MTN Mobile Money',
            'airtel' => 'Airtel Money',
            'mtn_momo' => 'MTN MoMo',
            'airtel_money' => 'Airtel Money',
            'cash_on_delivery' => 'Cash on Delivery',
            'card' => 'Card',
            'card_sim' => 'Card',
            'mtn_momo_sim' => 'MTN Mobile Money',
            'airtel_money_sim' => 'Airtel Money',
            'pesajet_mobile_money' => 'PesaJet Mobile Money',
        ];

        return $labels[$method] ?? ucfirst(str_replace('_', ' ', $method));
    }
}

if (!function_exists('katale_payment_status_options')) {
    /** Internal payment statuses (see sql/migrations/2026-09-24_pesajet_payments.sql). */
    function katale_payment_status_options(): array
    {
        return ['pending', 'processing', 'paid', 'failed', 'expired', 'cancelled'];
    }
}

if (!function_exists('katale_payment_is_configured')) {
    function katale_payment_is_configured(): bool
    {
        return PesaJetService::isConfigured();
    }
}

if (!function_exists('katale_payment_requires_configuration')) {
    function katale_payment_requires_configuration(): bool
    {
        return !katale_payment_is_configured();
    }
}

if (!function_exists('katale_payment_error_message')) {
    function katale_payment_error_message(): string
    {
        return 'Mobile Money payment is unavailable right now because the PesaJet configuration is not yet complete.';
    }
}

if (!function_exists('katale_payment_customer_error_message')) {
    /** Generic customer-facing payment error — technical detail stays in logs. */
    function katale_payment_customer_error_message(): string
    {
        return 'Something went wrong while processing your payment. Please try again.';
    }
}


if (!function_exists('katale_payment_is_configured')) {
    function katale_payment_is_configured(): bool
    {
        return (bool) katale_payment_service()['enabled'];
    }
}

if (!function_exists('katale_payment_requires_configuration')) {
    function katale_payment_requires_configuration(): bool
    {
        return !katale_payment_is_configured();
    }
}

if (!function_exists('katale_payment_error_message')) {
    function katale_payment_error_message(): string
    {
        return 'Payment is unavailable right now because the PesaJet configuration is not yet complete.';
    }
}

/* ------------------------------------------------------------------ */
/* Payment row helpers                                                 */
/* ------------------------------------------------------------------ */

if (!function_exists('katale_payment_reference')) {
    /**
     * Unique MasakaMart payment reference, e.g. "MM-PAY-20260924-ABC123".
     * Stored locally AND sent to PesaJet as the merchant `reference`.
     * (Distinct from PesaJet's own transactionId — never conflated.)
     */
    function katale_payment_reference(): string
    {
        return 'MM-PAY-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }
}

if (!function_exists('katale_get_order_payment')) {
    /** Latest payment row for an order (or null). */
    function katale_get_order_payment(PDO $db, int $orderId): ?array
    {
        $stmt = $db->prepare(
            "SELECT * FROM payments WHERE order_id = :oid ORDER BY payment_id DESC LIMIT 1"
        );
        $stmt->execute(['oid' => $orderId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}

if (!function_exists('katale_order_for_payment')) {
    /**
     * Load an order for the payment flow, enforcing ownership when a user
     * id is supplied (a payment can never touch another customer's order).
     */
    function katale_order_for_payment(PDO $db, string $orderNumber, ?int $userId = null): ?array
    {
        if ($orderNumber === '') {
            return null;
        }
        if ($userId !== null) {
            $stmt = $db->prepare("SELECT * FROM orders WHERE order_number = :num AND user_id = :uid LIMIT 1");
            $stmt->execute(['num' => $orderNumber, 'uid' => $userId]);
        } else {
            $stmt = $db->prepare("SELECT * FROM orders WHERE order_number = :num LIMIT 1");
            $stmt->execute(['num' => $orderNumber]);
        }
        $row = $stmt->fetch();
        return $row ?: null;
    }
}

if (!function_exists('katale_create_payment_request')) {
    /**
     * Ensure a payment row exists for an order (created during checkout).
     * The amount is always the order's server-side grand_total.
     */
    function katale_create_payment_request(PDO $db, int $orderId, float $amount, string $method, string $customerPhone, string $currency = 'UGX'): array
    {
        $existing = katale_get_order_payment($db, $orderId);
        if ($existing) {
            return $existing;
        }

        $reference = katale_payment_reference();
        $stmt = $db->prepare(
            "INSERT INTO payments (order_id, amount, currency, method, phone_number, transaction_reference, status)
             VALUES (:order_id, :amount, :currency, :method, :phone, :reference, 'pending')"
        );
        $stmt->execute([
            'order_id' => $orderId,
            'amount' => $amount,
            'currency' => $currency,
            'method' => $method,
            'phone' => $customerPhone,
            'reference' => $reference,
        ]);

        return katale_get_order_payment($db, $orderId);
    }
}

if (!function_exists('katale_mark_payment_status')) {
    /**
     * Thin wrapper around the idempotent status application path.
     * Kept for compatibility with callers that only know order ids.
     */
    function katale_mark_payment_status(PDO $db, int $orderId, string $status, ?string $reference = null): bool
    {
        $payment = katale_get_order_payment($db, $orderId);
        if (!$payment) {
            return false;
        }
        if ($reference !== null && $reference !== $payment['transaction_reference']) {
            return false; // never re-point a payment at another reference
        }
        return katale_apply_payment_status($db, $payment, $status, ['source' => 'internal']);
    }
}

/* ------------------------------------------------------------------ */
/* Payment initiation (real PesaJet API)                               */
/* ------------------------------------------------------------------ */

if (!function_exists('katale_payment_throttled')) {
    /**
     * Lightweight per-order rate limit for payment initiation attempts:
     * max 5 requests per 10 minutes, kept in the session (single-server
     * hosting). Doubles as duplicate-click protection alongside CSRF.
     */
    function katale_payment_throttled(int $orderId): bool
    {
        $now = time();
        $log = $_SESSION['katale_payment_attempts'][$orderId] ?? [];
        $log = array_values(array_filter($log, static fn ($t) => ($now - $t) < 600));
        if (count($log) >= 5) {
            $_SESSION['katale_payment_attempts'][$orderId] = $log;
            return true;
        }
        $log[] = $now;
        $_SESSION['katale_payment_attempts'][$orderId] = $log;
        return false;
    }
}

if (!function_exists('katale_initiate_payment')) {
    /**
     * Send a real Mobile Money payment request to PesaJet for an order.
     *
     * The amount ALWAYS comes from the server-side order row — nothing from
     * the browser is trusted. The PesaJet call happens outside any DB
     * transaction; the local row is prepared first (one row per order,
     * attempt_count increments) so refreshes/retries can never create
     * duplicate orders or payment records.
     *
     * @param array $order  Order row (already ownership-checked by caller)
     * @param array $input  ['provider' => 'mtn'|'airtel', 'phone' => raw string]
     * @return array{ok:bool, state:string, reference:string, error_code?:string, error?:string}
     */
    function katale_initiate_payment(PDO $db, array $order, array $input): array
    {
        $orderId = (int)$order['order_id'];
        $reference = null;

        if (!katale_payment_is_configured()) {
            return ['ok' => false, 'state' => 'unconfigured', 'reference' => '', 'error_code' => 'NOT_CONFIGURED', 'error' => katale_payment_error_message()];
        }

        $provider = strtolower(trim((string)($input['provider'] ?? '')));
        if (!in_array($provider, ['mtn', 'airtel'], true)) {
            return ['ok' => false, 'state' => 'error', 'reference' => '', 'error_code' => 'INVALID_PROVIDER', 'error' => 'Please choose MTN Mobile Money or Airtel Money.'];
        }

        $phone = PesaJetService::normalizePhoneNumber((string)($input['phone'] ?? ''));
        if ($phone === null) {
            return ['ok' => false, 'state' => 'error', 'reference' => '', 'error_code' => 'INVALID_PHONE', 'error' => 'Please enter a valid Ugandan mobile number, e.g. 0771234567.'];
        }

        $detected = PesaJetService::providerFromPhone($phone);
        if ($detected === null) {
            return ['ok' => false, 'state' => 'error', 'reference' => '', 'error_code' => 'UNSUPPORTED_PHONE', 'error' => 'That number is not on a supported MTN or Airtel Uganda network.'];
        }
        if ($detected !== $provider) {
            return ['ok' => false, 'state' => 'error', 'reference' => '', 'error_code' => 'PROVIDER_MISMATCH', 'error' => 'That number belongs to ' . ucfirst($detected) . ', not ' . ucfirst($provider) . '. Please select the matching provider.'];
        }

        // Order state guards — never charge a paid or cancelled order.
        if ((string)$order['payment_status'] === 'paid') {
            return ['ok' => false, 'state' => 'paid', 'reference' => '', 'error_code' => 'ALREADY_PAID', 'error' => 'This order has already been paid.'];
        }
        if ((string)$order['order_status'] === 'cancelled') {
            return ['ok' => false, 'state' => 'cancelled', 'reference' => '', 'error_code' => 'ORDER_CANCELLED', 'error' => 'This order has been cancelled.'];
        }

        $amount = (float)$order['grand_total']; // server-side total only
        if ($amount < 1) {
            return ['ok' => false, 'state' => 'error', 'reference' => '', 'error_code' => 'INVALID_AMOUNT', 'error' => 'Invalid order amount.'];
        }

        // In-flight guard: a request for this order was accepted by PesaJet
        // moments ago and is still unresolved. A double-click, refresh or
        // second tab must NOT send a second prompt to the customer's phone —
        // just report the existing request. (Age measured by the database
        // clock so PHP/MySQL timezone differences cannot matter.)
        $inflight = katale_get_order_payment($db, $orderId);
        if ($inflight
            && in_array((string)$inflight['status'], ['pending', 'processing'], true)
            && trim((string)($inflight['pesajet_transaction_id'] ?? '')) !== ''
            && (string)($inflight['phone_number'] ?? '') === $phone
            && (string)($inflight['provider'] ?? '') === $provider
        ) {
            $ageStmt = $db->prepare("SELECT TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS age FROM payments WHERE payment_id = :pid");
            $ageStmt->execute(['pid' => (int)$inflight['payment_id']]);
            $age = $ageStmt->fetch();
            if ($age && $age['age'] !== null && (int)$age['age'] >= 0 && (int)$age['age'] < 120) {
                katale_payment_log('duplicate_initiation_suppressed', ['order_id' => $orderId, 'reference' => $inflight['transaction_reference']]);
                return [
                    'ok' => true,
                    'state' => (string)$inflight['status'],
                    'reference' => (string)$inflight['transaction_reference'],
                    'transaction_id' => (string)$inflight['pesajet_transaction_id'],
                    'duplicate' => true,
                ];
            }
        }

        if (katale_payment_throttled($orderId)) {
            return ['ok' => false, 'state' => 'error', 'reference' => '', 'error_code' => 'RATE_LIMITED', 'error' => 'Too many payment attempts. Please wait a few minutes and try again.'];
        }

        // --- Prepare / update the local row inside a short transaction ----
        try {
            $db->beginTransaction();

            $payment = katale_get_order_payment($db, $orderId);
            if ($payment) {
                // Reuse the same row: no duplicate payment records on retry.
                $reference = (string)$payment['transaction_reference'];
                $attempt = (int)$payment['attempt_count'] + 1;
                $stmt = $db->prepare(
                    "UPDATE payments
                        SET method = 'mobile_money', provider = :prov, phone_number = :phone,
                            currency = 'UGX', amount = :amount, user_id = :uid,
                            attempt_count = :attempt
                      WHERE payment_id = :pid"
                );
                $stmt->execute([
                    'prov' => $provider, 'phone' => $phone, 'amount' => $amount,
                    'uid' => (int)$order['user_id'], 'attempt' => $attempt, 'pid' => (int)$payment['payment_id'],
                ]);
            } else {
                $reference = katale_payment_reference();
                $attempt = 1;
                $stmt = $db->prepare(
                    "INSERT INTO payments
                        (order_id, user_id, amount, currency, method, provider, phone_number,
                         transaction_reference, status, attempt_count)
                     VALUES (:oid, :uid, :amount, 'UGX', 'mobile_money', :prov, :phone, :ref, 'pending', 1)"
                );
                $stmt->execute([
                    'oid' => $orderId, 'uid' => (int)$order['user_id'], 'amount' => $amount,
                    'prov' => $provider, 'phone' => $phone, 'ref' => $reference,
                ]);
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            katale_payment_log('init_db_error', ['order_id' => $orderId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'state' => 'error', 'reference' => $reference ?? '', 'error_code' => 'DB_ERROR', 'error' => katale_payment_customer_error_message()];
        }

        $payment = katale_get_order_payment($db, $orderId);
        if (!$payment) {
            return ['ok' => false, 'state' => 'error', 'reference' => $reference, 'error_code' => 'DB_ERROR', 'error' => katale_payment_customer_error_message()];
        }

        // --- Real API call (server-to-server) ----------------------------
        katale_payment_log('payment_request_initiated', [
            'order_id' => $orderId,
            'reference' => $reference,
            'provider' => $provider,
            'amount' => $amount,
            'attempt' => $attempt,
        ]);

        $service = new PesaJetService();
        $response = $service->createPayment([
            'amount' => (int)round($amount),
            'currency' => 'UGX',
            'phoneNumber' => $phone,
            'provider' => $provider,
            'reference' => $reference,
            'description' => 'MasakaMart order ' . $order['order_number'],
            'metadata' => ['orderNumber' => $order['order_number']],
            'idempotencyKey' => $reference . '-A' . $attempt,
        ]);

        if ($response['ok'] && is_array($response['data'])) {
            $data = $response['data'];
            $remoteStatus = isset($data['status']) ? (string)$data['status'] : null;
            $internal = PesaJetService::mapStatus($remoteStatus) ?? 'processing';

            // A create response is authoritative only when any echoed order
            // values agree with the server-calculated payment record. Never
            // promote a payment from an unrelated or malformed response.
            $amountMatches = !isset($data['amount']) || abs((float)$data['amount'] - (float)$payment['amount']) <= 0.01;
            $currencyMatches = !isset($data['currency']) || strtoupper((string)$data['currency']) === 'UGX';
            $referenceMatches = !isset($data['reference']) || hash_equals($reference, (string)$data['reference']);
            if (!$amountMatches || !$currencyMatches || !$referenceMatches) {
                katale_payment_log('api_response_rejected', [
                    'order_id' => $orderId,
                    'reference' => $reference,
                    'amount_matches' => $amountMatches,
                    'currency_matches' => $currencyMatches,
                    'reference_matches' => $referenceMatches,
                ]);
                return ['ok' => false, 'state' => 'pending', 'reference' => $reference, 'error_code' => 'RESPONSE_MISMATCH', 'error' => katale_payment_customer_error_message()];
            }

            $sanitized = katale_payment_log_sanitize($data);
            // Store terminal responses as processing first, then use the
            // single transition path below so order status, inventory and
            // notifications cannot diverge from payment status.
            $storedStatus = in_array($internal, ['paid', 'failed', 'expired', 'cancelled'], true) ? 'processing' : $internal;
            $stmt = $db->prepare(
                "UPDATE payments
                    SET status = :status,
                        pesajet_transaction_id = COALESCE(:txn, pesajet_transaction_id),
                        provider_reference = COALESCE(:pref, provider_reference),
                        api_response = :resp,
                        failure_reason = NULL
                  WHERE payment_id = :pid"
            );
            $stmt->execute([
                'status' => $storedStatus,
                'txn' => isset($data['transactionId']) ? (string)$data['transactionId'] : null,
                'pref' => isset($data['providerReference']) ? (string)$data['providerReference'] : null,
                'resp' => json_encode($sanitized, JSON_UNESCAPED_SLASHES),
                'pid' => (int)$payment['payment_id'],
            ]);

            katale_payment_log('api_request_accepted', [
                'order_id' => $orderId,
                'reference' => $reference,
                'remote_status' => $remoteStatus,
                'internal_status' => $internal,
            ]);

            if (in_array($internal, ['paid', 'failed', 'expired', 'cancelled'], true)) {
                $freshPayment = katale_get_order_payment($db, $orderId);
                if ($freshPayment) {
                    katale_apply_payment_status($db, $freshPayment, $internal, [
                        'source' => 'initiation_response',
                        'remote_status' => $remoteStatus,
                        'pesajet_transaction_id' => isset($data['transactionId']) ? (string)$data['transactionId'] : null,
                        'provider_reference' => isset($data['providerReference']) ? (string)$data['providerReference'] : null,
                        'failure_reason' => isset($data['failureReason']) ? (string)$data['failureReason'] : null,
                    ]);
                }
            }

            return [
                'ok' => true,
                'state' => $internal,
                'reference' => $reference,
                'transaction_id' => isset($data['transactionId']) ? (string)$data['transactionId'] : '',
            ];
        }

        // --- API failure: record it safely, expose only a generic message --
        $errorCode = (string)($response['error_code'] ?? 'UNKNOWN');
        $networkFailure = in_array($errorCode, ['NETWORK_ERROR', 'TIMEOUT'], true);

        // On network/timeout the remote request may still have landed —
        // keep the row 'pending' (unknown) so a late webhook can still be
        // matched by reference; definite API errors become 'failed'.
        $newStatus = $networkFailure ? 'pending' : 'failed';
        $stmt = $db->prepare(
            "UPDATE payments SET status = :status, failure_reason = :reason WHERE payment_id = :pid"
        );
        $stmt->execute([
            'status' => $newStatus,
            'reason' => substr($errorCode . ($networkFailure ? ' (request incomplete)' : ''), 0, 255),
            'pid' => (int)$payment['payment_id'],
        ]);

        katale_payment_log('api_request_failed', [
            'order_id' => $orderId,
            'reference' => $reference,
            'error_code' => $errorCode,
            'http_code' => $response['http_code'] ?? 0,
            'recorded_status' => $newStatus,
        ]);

        return [
            'ok' => false,
            'state' => $newStatus,
            'reference' => $reference,
            'error_code' => $errorCode,
            'error' => katale_payment_customer_error_message(),
        ];
    }
}

/* ------------------------------------------------------------------ */
/* Idempotent status application (webhook + status poll + internal)    */
/* ------------------------------------------------------------------ */

if (!function_exists('katale_payment_provider_sources')) {
    /** Sources whose status comes from PesaJet itself (callback, poll, cron, verify button). */
    function katale_payment_provider_sources(): array
    {
        return ['webhook', 'status_poll', 'expiry_poll', 'initiation_response', 'admin_verify'];
    }
}

if (!function_exists('katale_ensure_payment_control_schema')) {
    /**
     * Idempotent self-heal for the columns/tables added by
     * sql/migrations/2026-09-24, 2026-10-24 and 2026-10-27. Running the SQL
     * migrations by hand is still recommended; this only guarantees that a
     * missed migration can never make a payment callback fail or an admin
     * action go un-audited. One SHOW COLUMNS per request, DDL only if needed.
     */
    function katale_ensure_payment_control_schema(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            $have = [];
            foreach ($db->query('SHOW COLUMNS FROM payments')->fetchAll() as $col) {
                $have[(string)$col['Field']] = true;
            }
            $columns = [
                'verification_source' => "ENUM('gateway','manual') NULL",
                'manual_verified_by' => 'INT UNSIGNED NULL',
                'manual_verified_at' => 'DATETIME NULL',
                'manual_note' => 'VARCHAR(500) NULL',
                'admin_decision' => "ENUM('paid','failed','pending') NULL",
                'admin_decision_by' => 'INT UNSIGNED NULL',
                'admin_decision_at' => 'DATETIME NULL',
                'admin_decision_note' => 'VARCHAR(500) NULL',
                'provider_status' => 'VARCHAR(40) NULL',
                'provider_verified_at' => 'DATETIME NULL',
                'provider_verification_result' => 'VARCHAR(40) NULL',
                'needs_reconciliation' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'reconciliation_note' => 'VARCHAR(500) NULL',
                'reconciliation_flagged_at' => 'DATETIME NULL',
                'reconciliation_resolved_at' => 'DATETIME NULL',
                'reconciliation_resolved_by' => 'INT UNSIGNED NULL',
            ];
            foreach ($columns as $name => $definition) {
                if (!isset($have[$name])) {
                    try {
                        $db->exec("ALTER TABLE payments ADD COLUMN {$name} {$definition}");
                    } catch (PDOException $e) {
                        if (stripos($e->getMessage(), 'Duplicate column') === false) {
                            error_log('[payments] schema ensure failed for ' . $name . ': ' . $e->getMessage());
                        }
                    }
                }
            }

            $db->exec(
                "CREATE TABLE IF NOT EXISTS payment_status_history (
                    history_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    payment_id INT UNSIGNED NOT NULL,
                    order_id INT UNSIGNED NOT NULL,
                    previous_status VARCHAR(20) NULL,
                    new_status VARCHAR(20) NOT NULL,
                    source VARCHAR(30) NOT NULL,
                    admin_id INT UNSIGNED NULL,
                    provider_status VARCHAR(40) NULL,
                    note VARCHAR(500) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_psh_payment (payment_id, created_at),
                    INDEX idx_psh_order (order_id),
                    FOREIGN KEY (payment_id) REFERENCES payments(payment_id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $db->exec(
                "CREATE TABLE IF NOT EXISTS order_admin_actions (
                    action_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    order_id INT UNSIGNED NOT NULL,
                    payment_id INT UNSIGNED NULL,
                    admin_id INT UNSIGNED NOT NULL,
                    action_type VARCHAR(40) NOT NULL,
                    previous_order_status VARCHAR(40) NULL,
                    new_order_status VARCHAR(40) NULL,
                    previous_payment_status VARCHAR(40) NULL,
                    new_payment_status VARCHAR(40) NULL,
                    note VARCHAR(500) NULL,
                    transaction_reference VARCHAR(100) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
                    FOREIGN KEY (payment_id) REFERENCES payments(payment_id) ON DELETE SET NULL,
                    FOREIGN KEY (admin_id) REFERENCES admins(admin_id) ON DELETE CASCADE,
                    INDEX idx_order_admin_actions_order (order_id),
                    INDEX idx_order_admin_actions_type (action_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            if (function_exists('ensure_inventory_order_columns')) {
                ensure_inventory_order_columns($db);
            }
        } catch (Throwable $e) {
            error_log('[payments] schema ensure skipped: ' . $e->getMessage());
        }
    }
}

if (!function_exists('katale_payment_history_add')) {
    /**
     * Append one row to payment_status_history. With $strict = true (admin
     * decisions, inside their transaction) a failure propagates so the whole
     * decision rolls back; otherwise it is best-effort and only logged.
     */
    function katale_payment_history_add(
        PDO $db,
        int $paymentId,
        int $orderId,
        ?string $previous,
        string $new,
        string $source,
        ?int $adminId = null,
        ?string $providerStatus = null,
        ?string $note = null,
        bool $strict = false
    ): void {
        try {
            $db->prepare(
                "INSERT INTO payment_status_history
                    (payment_id, order_id, previous_status, new_status, source, admin_id, provider_status, note)
                 VALUES (:pid, :oid, :prev, :new, :src, :aid, :pstat, :note)"
            )->execute([
                'pid' => $paymentId,
                'oid' => $orderId,
                'prev' => $previous,
                'new' => $new,
                'src' => substr($source, 0, 30),
                'aid' => $adminId,
                'pstat' => $providerStatus !== null ? substr($providerStatus, 0, 40) : null,
                'note' => $note !== null ? substr($note, 0, 500) : null,
            ]);
        } catch (Throwable $e) {
            if ($strict) {
                throw $e;
            }
            katale_payment_log('history_write_failed', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);
        }
    }
}

if (!function_exists('katale_payment_confirmation_type')) {
    /**
     * The four categories the administrator must be able to tell apart:
     *   provider_confirmed | admin_confirmed | admin_failed | pending_verification
     * plus 'provider_failed' (failure reported by PesaJet, not an admin) and
     * 'cancelled'. Derived from stored facts only — never from the browser.
     *
     * @return array{key:string,label:string}
     */
    function katale_payment_confirmation_type(array $payment): array
    {
        $status = (string)($payment['status'] ?? 'pending');
        $decision = (string)($payment['admin_decision'] ?? '');

        if ($status === 'paid') {
            if ((string)($payment['verification_source'] ?? '') === 'manual') {
                return ['key' => 'admin_confirmed', 'label' => 'Admin-confirmed payment'];
            }
            return ['key' => 'provider_confirmed', 'label' => 'Provider-confirmed payment'];
        }
        if (in_array($status, ['failed', 'expired', 'cancelled'], true)) {
            if ($decision === 'failed') {
                return ['key' => 'admin_failed', 'label' => 'Admin-marked failure'];
            }
            return $status === 'cancelled'
                ? ['key' => 'cancelled', 'label' => 'Cancelled']
                : ['key' => 'provider_failed', 'label' => 'Provider-reported ' . $status];
        }
        return ['key' => 'pending_verification', 'label' => 'Pending verification'];
    }
}

if (!function_exists('katale_apply_payment_status')) {
    /**
     * The single path for applying a PROVIDER-SOURCED (or system) payment
     * status to the payment row, the parent order and — exactly once per
     * transition — the customer notifications. Shared by:
     *   - checkout/payment_webhook.php      (HMAC-verified PesaJet webhooks)
     *   - checkout/payment_status_ajax.php  (authenticated API status poll)
     *   - tools/process_payment_expirations.php (cron)
     *   - the admin "Verify Payment" button (source = admin_verify)
     * Administrator decisions go through katale_admin_set_payment_status()
     * (includes/payment_admin_service.php), which has its own atomic path.
     *
     * Guarantees:
     *   - Concurrent calls are serialised with a row lock, so duplicate
     *     callbacks can never double-fire emails or notifications.
     *   - An administrator's PAID/FAILED decision is never silently
     *     overwritten by a provider result. If the provider disagrees, the
     *     payment is flagged needs_reconciliation for administrator review
     *     and the admin decision stands.
     *   - A PAID payment is never downgraded by provider/system sources; a
     *     contradicting provider result is flagged the same way.
     *   - Amount/currency/reference validation happens in the CALLER.
     *
     * On a database error the function returns false and
     * katale_payment_last_apply_error() returns the message, so callers (the
     * webhook) can tell "nothing to do" apart from "failed — retry later".
     *
     * @param array $payment  Payment row from the caller
     * @param string $newStatus One of katale_payment_status_options()
     * @param array $ctx {pesajet_transaction_id?, provider_reference?,
     *                   failure_reason?, webhook_event?, remote_status?, source?}
     * @return bool true when a status transition was actually applied
     */
    function katale_apply_payment_status(PDO $db, array $payment, string $newStatus, array $ctx = []): bool
    {
        $GLOBALS['katale_payment_apply_error'] = null;

        if (!in_array($newStatus, katale_payment_status_options(), true)) {
            katale_payment_log('status_invalid', ['payment_id' => $payment['payment_id'] ?? null, 'status' => $newStatus]);
            return false;
        }

        katale_ensure_payment_control_schema($db);

        $paymentId = (int)($payment['payment_id'] ?? 0);
        $orderId = (int)($payment['order_id'] ?? 0);
        $oldStatus = (string)($payment['status'] ?? '');
        $source = (string)($ctx['source'] ?? 'unknown');
        $isProvider = in_array($source, katale_payment_provider_sources(), true);
        $isAdminSource = str_starts_with($source, 'admin');
        $remoteStatus = isset($ctx['remote_status']) && $ctx['remote_status'] !== ''
            ? strtoupper((string)$ctx['remote_status']) : strtoupper($newStatus);

        try {
            $db->beginTransaction();

            $lock = $db->prepare("SELECT * FROM payments WHERE payment_id = :pid FOR UPDATE");
            $lock->execute(['pid' => $paymentId]);
            $current = $lock->fetch();
            if (!$current) {
                $db->rollBack();
                return false;
            }

            $currentStatus = (string)$current['status'];
            $decision = (string)($current['admin_decision'] ?? '');

            // What the provider (if this is a provider result) says, recorded
            // on the row whether or not the status itself ends up changing.
            $observe = [];
            $observeParams = [];
            if ($isProvider) {
                $observe[] = 'provider_status = :pstat';
                $observe[] = 'provider_verified_at = NOW()';
                $observe[] = 'provider_verification_result = :pres';
                $observeParams['pstat'] = substr($remoteStatus, 0, 40);
                $observeParams['pres'] = in_array($newStatus, ['pending', 'processing'], true)
                    ? 'still_pending' : 'confirmed_' . $newStatus;
            }
            if (!empty($ctx['webhook_event'])) {
                $observe[] = 'webhook_status = :wstatus';
                $observe[] = 'webhook_received_at = NOW()';
                $observeParams['wstatus'] = substr((string)$ctx['webhook_event'] . ($remoteStatus !== '' ? ':' . $remoteStatus : ''), 0, 40);
            }

            // ---- Administrator decisions and PAID are not overwritten -----
            $conflict = null;
            $blocked = false;
            if (!$isAdminSource && in_array($decision, ['paid', 'failed'], true)) {
                $blocked = true;
                if ($decision === 'failed' && $newStatus === 'paid') {
                    $conflict = 'Provider reported PAID after an administrator marked this payment FAILED.';
                } elseif ($decision === 'paid' && in_array($newStatus, ['failed', 'expired', 'cancelled'], true)) {
                    $conflict = 'Provider reported ' . strtoupper($newStatus) . ' after an administrator confirmed this payment PAID.';
                }
            } elseif ($currentStatus === 'paid' && $newStatus !== 'paid') {
                $blocked = true;
                if ($isProvider && in_array($newStatus, ['failed', 'expired', 'cancelled'], true)) {
                    $conflict = 'Provider reported ' . strtoupper($newStatus) . ' after this payment was already confirmed PAID.';
                }
            }

            if ($blocked) {
                $sets = $observe;
                $params = $observeParams + ['pid' => $paymentId];
                if ($conflict !== null && (int)($current['needs_reconciliation'] ?? 0) === 0) {
                    $sets[] = 'needs_reconciliation = 1';
                    $sets[] = 'reconciliation_note = :rnote';
                    $sets[] = 'reconciliation_flagged_at = NOW()';
                    $sets[] = 'reconciliation_resolved_at = NULL';
                    $sets[] = 'reconciliation_resolved_by = NULL';
                    $params['rnote'] = substr($conflict, 0, 500);
                }
                if ($sets) {
                    $db->prepare('UPDATE payments SET ' . implode(', ', $sets) . ' WHERE payment_id = :pid')->execute($params);
                }
                if ($conflict !== null) {
                    $db->prepare("UPDATE orders SET needs_admin_review = 1 WHERE order_id = :oid")->execute(['oid' => $orderId]);
                    katale_payment_history_add(
                        $db, $paymentId, $orderId, $currentStatus, $currentStatus, $source, null,
                        $remoteStatus, 'CONFLICT FLAGGED: ' . $conflict
                    );
                }
                $db->commit();
                katale_payment_log($conflict !== null ? 'payment_conflict_flagged' : 'status_downgrade_blocked', [
                    'payment_id' => $paymentId, 'from' => $currentStatus, 'to' => $newStatus,
                    'admin_decision' => $decision, 'source' => $source,
                ]);
                return false;
            }

            $changed = $currentStatus !== $newStatus;

            $sets = $observe;
            $params = $observeParams + ['status' => $newStatus, 'pid' => $paymentId];
            array_unshift($sets, 'status = :status');

            if (!empty($ctx['pesajet_transaction_id']) && empty($current['pesajet_transaction_id'])) {
                $sets[] = 'pesajet_transaction_id = :txn';
                $params['txn'] = (string)$ctx['pesajet_transaction_id'];
            }
            if (!empty($ctx['provider_reference'])) {
                $sets[] = 'provider_reference = COALESCE(:pref, provider_reference)';
                $params['pref'] = (string)$ctx['provider_reference'];
            }
            if (isset($ctx['failure_reason']) && in_array($newStatus, ['failed', 'expired'], true)) {
                $sets[] = 'failure_reason = :reason';
                $params['reason'] = substr((string)$ctx['failure_reason'], 0, 255);
            }
            if ($newStatus === 'paid') {
                $sets[] = 'paid_at = COALESCE(paid_at, NOW())';
                $sets[] = 'failure_reason = NULL';
                if (empty($current['verification_source'])) {
                    $sets[] = "verification_source = 'gateway'";
                }
            }
            // A provider answer resolves an admin "hold for review".
            if ($isProvider && $decision === 'pending' && !in_array($newStatus, ['pending', 'processing'], true)) {
                $sets[] = 'admin_decision = NULL';
            }

            $db->prepare('UPDATE payments SET ' . implode(', ', $sets) . ' WHERE payment_id = :pid')->execute($params);

            // Collapsed order-side payment status (orders.payment_status has
            // no 'expired'/'processing' — expired stays unsettled so the
            // customer can still retry).
            $orderPayStatus = 'pending';
            if ($newStatus === 'paid') {
                $orderPayStatus = 'paid';
            } elseif ($newStatus === 'failed') {
                $orderPayStatus = 'failed';
            } elseif ($newStatus === 'cancelled') {
                $orderPayStatus = 'cancelled';
            }

            $db->prepare(
                "UPDATE orders SET payment_status = :s WHERE order_id = :oid AND payment_status <> :s2"
            )->execute(['s' => $orderPayStatus, 's2' => $orderPayStatus, 'oid' => $orderId]);

            if ($changed) {
                katale_payment_history_add(
                    $db, $paymentId, $orderId, $currentStatus, $newStatus, $source, null,
                    $isProvider ? $remoteStatus : null,
                    isset($ctx['failure_reason']) ? (string)$ctx['failure_reason'] : null
                );
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $GLOBALS['katale_payment_apply_error'] = $e->getMessage();
            katale_payment_log('status_apply_error', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);
            return false;
        }

        if ($changed) {
            katale_payment_log('payment_status_changed', [
                'payment_id' => $paymentId,
                'order_id' => $orderId,
                'reference' => $payment['transaction_reference'] ?? null,
                'from' => $currentStatus,
                'to' => $newStatus,
                'source' => $source,
            ]);
            katale_payment_sync_inventory($db, $orderId, $newStatus, $ctx);
            // Fires EXACTLY once per transition (row lock held above).
            katale_payment_notify_transition($db, (array)$current, $newStatus);
        }

        return $changed;
    }
}

if (!function_exists('katale_payment_last_apply_error')) {
    /** Database error from the last katale_apply_payment_status() call, or null. */
    function katale_payment_last_apply_error(): ?string
    {
        $err = $GLOBALS['katale_payment_apply_error'] ?? null;
        return is_string($err) ? $err : null;
    }
}

if (!function_exists('katale_payment_sync_inventory')) {
    function katale_payment_sync_inventory(PDO $db, int $orderId, string $paymentStatus, array $ctx = []): void
    {
        if (!function_exists('katale_restore_order_stock')) {
            return;
        }

        if ($paymentStatus === 'paid') {
            $orderStmt = $db->prepare("SELECT inventory_state FROM orders WHERE order_id = :oid LIMIT 1");
            $orderStmt->execute(['oid' => $orderId]);
            $order = $orderStmt->fetch();
            if ($order && (string)($order['inventory_state'] ?? '') === 'restored') {
                $db->prepare("UPDATE orders SET needs_admin_review = 1 WHERE order_id = :oid")
                    ->execute(['oid' => $orderId]);
                katale_payment_log('paid_after_stock_released', ['order_id' => $orderId]);
            }
            return;
        }

        if (in_array($paymentStatus, ['failed', 'expired', 'cancelled'], true)) {
            $reason = $paymentStatus === 'failed' ? 'order_failed' : 'order_cancelled';
            katale_restore_order_stock($db, $orderId, $reason, isset($ctx['admin_id']) ? (int)$ctx['admin_id'] : null);
        }
    }
}

if (!function_exists('katale_payment_expiry_minutes')) {
    function katale_payment_expiry_minutes(): int
    {
        return 10;
    }
}

if (!function_exists('katale_process_stale_payment')) {
    /**
     * After ~10 minutes without payment, query PesaJet when possible and
     * apply authoritative status. Does not treat API/network errors as failure.
     *
     * @return array{processed:bool,status?:string,reason?:string}
     */
    function katale_process_stale_payment(PDO $db, array $payment): array
    {
        $paymentId = (int)$payment['payment_id'];
        $orderId = (int)$payment['order_id'];
        $status = (string)$payment['status'];

        if (in_array($status, ['paid', 'failed', 'expired', 'cancelled'], true)) {
            return ['processed' => false, 'reason' => 'terminal'];
        }

        $createdAt = strtotime((string)($payment['updated_at'] ?? $payment['created_at'] ?? ''));
        if ($createdAt === false || (time() - $createdAt) < (katale_payment_expiry_minutes() * 60)) {
            return ['processed' => false, 'reason' => 'not_due'];
        }

        $txnId = trim((string)($payment['pesajet_transaction_id'] ?? ''));
        if ($txnId === '' || !katale_payment_is_configured()) {
            return ['processed' => false, 'reason' => 'no_remote_txn'];
        }

        $service = new PesaJetService();
        $response = $service->getPayment($txnId);
        if (!$response['ok'] || !is_array($response['data'])) {
            katale_payment_log('expiry_poll_api_error', [
                'payment_id' => $paymentId,
                'error_code' => $response['error_code'] ?? 'UNKNOWN',
            ]);
            return ['processed' => false, 'reason' => 'gateway_unreachable'];
        }

        $data = $response['data'];
        $internal = PesaJetService::mapStatus(isset($data['status']) ? (string)$data['status'] : null);
        $amountMatches = isset($data['amount']) && abs((float)$data['amount'] - (float)$payment['amount']) <= 0.01;
        $currencyMatches = !isset($data['currency']) || strtoupper((string)$data['currency']) === 'UGX';

        if ($internal === null || !$amountMatches || !$currencyMatches) {
            katale_payment_log('expiry_poll_rejected', ['payment_id' => $paymentId, 'remote_status' => $data['status'] ?? null]);
            return ['processed' => false, 'reason' => 'validation_failed'];
        }

        if (!in_array($internal, ['failed', 'expired', 'cancelled', 'paid'], true)) {
            return ['processed' => false, 'reason' => 'still_pending'];
        }

        $changed = katale_apply_payment_status($db, $payment, $internal, [
            'source' => 'expiry_poll',
            'remote_status' => isset($data['status']) ? (string)$data['status'] : null,
            'pesajet_transaction_id' => $txnId,
            'failure_reason' => isset($data['failureReason']) ? (string)$data['failureReason'] : null,
        ]);

        return ['processed' => $changed, 'status' => $internal];
    }
}

if (!function_exists('katale_process_due_payment_expirations')) {
    /** Batch processor for cron/CLI and lightweight web triggers. */
    function katale_process_due_payment_expirations(PDO $db, int $limit = 50): array
    {
        $minutes = katale_payment_expiry_minutes();
        $stmt = $db->prepare(
            "SELECT p.* FROM payments p
              JOIN orders o ON o.order_id = p.order_id
             WHERE p.status IN ('pending','processing')
               AND o.payment_status <> 'paid'
               AND o.order_status <> 'cancelled'
               AND (p.admin_decision IS NULL OR p.admin_decision <> 'pending')
               AND p.updated_at <= (NOW() - INTERVAL {$minutes} MINUTE)
             ORDER BY p.updated_at ASC
             LIMIT :lim"
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $summary = ['checked' => 0, 'updated' => 0, 'skipped' => 0];
        foreach ($stmt->fetchAll() as $payment) {
            $summary['checked']++;
            $result = katale_process_stale_payment($db, $payment);
            if (!empty($result['processed'])) {
                $summary['updated']++;
            } else {
                $summary['skipped']++;
            }
        }
        return $summary;
    }
}

if (!function_exists('katale_log_order_admin_action')) {
    function katale_log_order_admin_action(
        PDO $db,
        int $orderId,
        int $adminId,
        string $actionType,
        array $fields = []
    ): void {
        try {
            $db->prepare(
                "INSERT INTO order_admin_actions
                    (order_id, payment_id, admin_id, action_type, previous_order_status, new_order_status,
                     previous_payment_status, new_payment_status, note, transaction_reference)
                 VALUES
                    (:oid, :pid, :aid, :type, :pos, :nos, :pps, :nps, :note, :ref)"
            )->execute([
                'oid' => $orderId,
                'pid' => $fields['payment_id'] ?? null,
                'aid' => $adminId,
                'type' => $actionType,
                'pos' => $fields['previous_order_status'] ?? null,
                'nos' => $fields['new_order_status'] ?? null,
                'pps' => $fields['previous_payment_status'] ?? null,
                'nps' => $fields['new_payment_status'] ?? null,
                'note' => isset($fields['note']) ? substr((string)$fields['note'], 0, 500) : null,
                'ref' => $fields['transaction_reference'] ?? null,
            ]);
        } catch (PDOException $e) {
            error_log('[order_admin_actions] log failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('katale_admin_cancel_order')) {
    function katale_admin_cancel_order(PDO $db, int $orderId, int $adminId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'error' => 'Cancellation reason is required.'];
        }

        try {
            // Downstream helpers own their transactions; PDO/MySQL does not
            // support nesting an outer cancellation transaction.
            $orderStmt = $db->prepare("SELECT * FROM orders WHERE order_id = :oid LIMIT 1");
            $orderStmt->execute(['oid' => $orderId]);
            $order = $orderStmt->fetch();
            if (!$order) {
                return ['ok' => false, 'error' => 'Order not found.'];
            }
            if ((string)$order['order_status'] === 'cancelled') {
                return ['ok' => true, 'already' => true];
            }
            if ((string)$order['payment_status'] === 'paid') {
                return ['ok' => false, 'error' => 'Paid orders cannot be cancelled here — use a refund workflow.'];
            }

            $prevOrderStatus = (string)$order['order_status'];
            $prevPayStatus = (string)$order['payment_status'];

            $payment = katale_get_order_payment($db, $orderId);
            if ($payment && (string)$payment['status'] !== 'paid') {
                $changed = katale_apply_payment_status($db, $payment, 'cancelled', [
                    'source' => 'admin_cancel',
                    'admin_id' => $adminId,
                    'failure_reason' => substr($reason, 0, 255),
                ]);
                $freshPayment = katale_get_order_payment($db, $orderId);
                if (!$changed && (string)($freshPayment['status'] ?? '') === 'paid') {
                    return ['ok' => false, 'error' => 'Payment was confirmed while cancellation was being processed. Use the refund workflow.'];
                }
                $db->prepare("UPDATE orders SET order_status = 'cancelled', payment_status = 'cancelled' WHERE order_id = :oid")
                    ->execute(['oid' => $orderId]);
            } else {
                katale_restore_order_stock($db, $orderId, 'order_cancelled', $adminId);
            }

            if (!$payment) {
                $db->prepare("UPDATE orders SET order_status = 'cancelled', payment_status = 'cancelled' WHERE order_id = :oid")
                    ->execute(['oid' => $orderId]);
            }

            katale_log_order_admin_action($db, $orderId, $adminId, 'cancel_order', [
                'payment_id' => $payment['payment_id'] ?? null,
                'previous_order_status' => $prevOrderStatus,
                'new_order_status' => 'cancelled',
                'previous_payment_status' => $prevPayStatus,
                'new_payment_status' => 'cancelled',
                'note' => $reason,
            ]);
            handle_order_status_change($db, $orderId, 'cancelled');

            return ['ok' => true];
        } catch (Throwable $e) {
            error_log('[admin_cancel_order] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not cancel the order.'];
        }
    }
}

if (!function_exists('katale_admin_mark_payment_paid')) {
    /**
     * Backwards-compatible order-level entry point (older callers). It now
     * delegates to the atomic administrator decision path so there is exactly
     * one implementation of "admin marks a payment PAID".
     */
    function katale_admin_mark_payment_paid(
        PDO $db,
        int $orderId,
        int $adminId,
        string $reason,
        string $transactionReference = ''
    ): array {
        $payment = katale_get_order_payment($db, $orderId);
        if (!$payment) {
            return ['ok' => false, 'error' => 'No payment record exists for this order.'];
        }
        return katale_admin_set_payment_status($db, (int)$payment['payment_id'], 'paid', $adminId, $reason, [
            'transaction_reference' => $transactionReference,
        ]);
    }
}

if (!function_exists('katale_customer_cancel_order')) {
    function katale_customer_cancel_order(PDO $db, int $orderId, int $userId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'error' => 'Please provide a cancellation reason.'];
        }

        $stmt = $db->prepare("SELECT * FROM orders WHERE order_id = :oid AND user_id = :uid LIMIT 1");
        $stmt->execute(['oid' => $orderId, 'uid' => $userId]);
        $order = $stmt->fetch();
        if (!$order) {
            return ['ok' => false, 'error' => 'Order not found.'];
        }
        if ((string)$order['payment_status'] === 'paid') {
            return ['ok' => false, 'error' => 'Paid orders cannot be cancelled online — contact support.'];
        }
        if ((string)$order['order_status'] === 'cancelled') {
            return ['ok' => true, 'already' => true];
        }
        if (!in_array((string)$order['order_status'], ['received'], true)) {
            return ['ok' => false, 'error' => 'This order can no longer be cancelled online.'];
        }

        $payment = katale_get_order_payment($db, $orderId);
        if ($payment && in_array((string)$payment['status'], ['processing'], true) && trim((string)($payment['pesajet_transaction_id'] ?? '')) !== '') {
            return ['ok' => false, 'error' => 'A payment request is in progress. Wait for it to finish or fail before cancelling.'];
        }

        try {
            if ($payment && (string)$payment['status'] !== 'paid') {
                $changed = katale_apply_payment_status($db, $payment, 'cancelled', [
                    'source' => 'customer_cancel',
                    'failure_reason' => substr($reason, 0, 255),
                ]);
                $freshPayment = katale_get_order_payment($db, $orderId);
                if (!$changed && (string)($freshPayment['status'] ?? '') === 'paid') {
                    return ['ok' => false, 'error' => 'Payment was confirmed while cancellation was being processed. Contact support for a refund.'];
                }
                $db->prepare("UPDATE orders SET order_status = 'cancelled', payment_status = 'cancelled' WHERE order_id = :oid")
                    ->execute(['oid' => $orderId]);
            } else {
                katale_restore_order_stock($db, $orderId, 'order_cancelled');
                $db->prepare("UPDATE orders SET order_status = 'cancelled', payment_status = 'cancelled' WHERE order_id = :oid")
                    ->execute(['oid' => $orderId]);
            }
            handle_order_status_change($db, $orderId, 'cancelled');
            return ['ok' => true];
        } catch (Throwable $e) {
            error_log('[customer_cancel_order] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not cancel your order. Please try again or contact support.'];
        }
    }
}

/* ------------------------------------------------------------------ */
/* Customer notifications on payment transitions                       */
/* ------------------------------------------------------------------ */

if (!function_exists('katale_payment_notify_transition')) {
    /**
     * In-site notification + the existing MasakaMart order-confirmation
     * email, sent only when a payment transition happens for the first time.
     *
     * - paid    → "Payment received for order #X" in-app + (mobile money only)
     *             the existing order-confirmation email flow via
     *             handle_order_status_change('received'). Cash-on-delivery
     *             orders are already notified at checkout — never twice.
     * - failed / expired → "Payment for order #X failed. Please try again."
     *   in-app only (no success email is ever sent for non-paid states).
     */
    function katale_payment_notify_transition(PDO $db, array $payment, string $newStatus): void
    {
        $orderId = (int)$payment['order_id'];
        $orderStmt = $db->prepare("SELECT * FROM orders WHERE order_id = :oid LIMIT 1");
        $orderStmt->execute(['oid' => $orderId]);
        $order = $orderStmt->fetch();
        if (!$order) {
            return;
        }

        $userId = (int)($order['user_id'] ?? 0);
        $orderNumber = (string)$order['order_number'];
        $link = APP_URL . '/orders/order_detail.php?order=' . urlencode($orderNumber);

        if ($newStatus === 'paid') {
            $title = 'Payment received';
            $message = "Payment received for order #{$orderNumber}.";
            $type = 'payment_received';

            if ($userId > 0 && user_allows_notification($db, $userId, $type, 'in_app')) {
                $notificationId = create_in_app_notification($db, $userId, $title, $message, $type, $orderId, $link, 'in_app');
                log_notification_delivery($db, $notificationId, $userId, 'in_app', 'delivered');
            }

            // Existing order-confirmation workflow — only for payments that
            // were awaited (mobile money); COD was notified at checkout.
            if ((string)$payment['method'] === 'mobile_money' && (string)$order['order_status'] !== 'cancelled') {
                handle_order_status_change($db, $orderId, 'received');
            }
            return;
        }

        if (in_array($newStatus, ['failed', 'expired'], true)) {
            $title = 'Payment failed';
            $message = "Payment for order #{$orderNumber} failed. Please try again.";
            $type = 'payment_failed';

            if ($userId > 0 && user_allows_notification($db, $userId, $type, 'in_app')) {
                $notificationId = create_in_app_notification($db, $userId, $title, $message, $type, $orderId, $link, 'in_app');
                log_notification_delivery($db, $notificationId, $userId, 'in_app', 'delivered');
            }
        }
    }
}

/* ------------------------------------------------------------------ */
/* Payment page state (drives the customer-facing UI)                 */
/* ------------------------------------------------------------------ */

if (!function_exists('katale_payment_page_state')) {
    /**
     * Derive the customer-facing payment state for an order:
     *   'paid'      → confirmation screen
     *   'awaiting'  → request sent, waiting for the customer's approval
     *                 and/or webhook confirmation (never claimed successful)
     *   'error'     → failed / expired / no confirmed request → retry
     *   'cancelled' → order or payment cancelled (no retry)
     */
    function katale_payment_page_state(array $order, ?array $payment): string
    {
        if ((string)$order['payment_status'] === 'paid') {
            return 'paid';
        }
        if ((string)$order['order_status'] === 'cancelled') {
            return 'cancelled';
        }
        if (!$payment) {
            return 'error';
        }

        $status = (string)$payment['status'];
        if ($status === 'paid') {
            return 'paid';
        }
        if ($status === 'cancelled') {
            return 'cancelled';
        }
        if ($status === 'failed' || $status === 'expired') {
            return 'error';
        }

        // pending / processing: awaiting only if PesaJet actually accepted
        // the request (we have its transaction id).
        $hasRemote = trim((string)($payment['pesajet_transaction_id'] ?? '')) !== '';
        return $hasRemote ? 'awaiting' : 'error';
    }
}

require_once __DIR__ . '/payment_admin_service.php';
