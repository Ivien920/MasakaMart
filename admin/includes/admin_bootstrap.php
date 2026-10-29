<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pricing.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/PesaJetService.php';
require_once __DIR__ . '/../../includes/payment_service.php';
require_once __DIR__ . '/../../includes/inventory_service.php';
require_once __DIR__ . '/../../includes/order_totals.php';
require_once __DIR__ . '/../../includes/delivery_service.php';

$db = Database::conn();
ensure_notification_tables($db);
katale_ensure_payment_control_schema($db);

function log_admin_activity(PDO $db, string $action, string $details = ''): void
{
    $admin = current_admin();
    if (!$admin) return;
    $stmt = $db->prepare("INSERT INTO admin_activity_logs (admin_id, action, details) VALUES (:aid, :action, :details)");
    $stmt->execute(['aid' => $admin['admin_id'], 'action' => $action, 'details' => $details]);
}
