<?php
/**
 * Authoritative PesaJet expiry processor.
 *
 * Run every 2-5 minutes from a server cron task:
 *   php tools/process_payment_expirations.php
 *
 * This is deliberately CLI-only. It never assumes that an unanswered prompt
 * failed: each due transaction is checked with PesaJet first, and only a
 * confirmed paid/failed/expired/cancelled response changes local state.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only' . PHP_EOL);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/PesaJetService.php';
require_once __DIR__ . '/../includes/inventory_service.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/payment_service.php';

try {
    $summary = katale_process_due_payment_expirations(Database::conn());
    echo json_encode($summary, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    error_log('[payment-expiry-cron] ' . $e->getMessage());
    fwrite(STDERR, "Payment expiry processing failed. See the server error log.\n");
    exit(1);
}
