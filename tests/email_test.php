<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

header('Content-Type: application/json; charset=utf-8');

$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$allowedLocal = ['127.0.0.1', '::1'];
$localAllowed = in_array($remoteAddress, $allowedLocal, true) || preg_match('/^127\.0\.0\./', (string)$remoteAddress) === 1;
$isCli = PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server';

if (!$isCli && !$localAllowed) {
    http_response_code(403);
    echo json_encode([
        'status' => 'forbidden',
        'message' => 'This email test endpoint is restricted to localhost only.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$db = Database::conn();
$config = [
    'SMTP_HOST' => defined('SMTP_HOST') ? SMTP_HOST : '',
    'SMTP_PORT' => defined('SMTP_PORT') ? (string)SMTP_PORT : '',
    'SMTP_ENCRYPTION' => defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : '',
    'SMTP_USERNAME' => (defined('SMTP_USERNAME') && SMTP_USERNAME !== '') ? '[CONFIGURED]' : '',
    'SMTP_FROM_EMAIL' => (defined('SMTP_FROM_EMAIL') && SMTP_FROM_EMAIL !== '') ? '[CONFIGURED]' : '',
    'SMTP_FROM_NAME' => defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : '',
    'SMTP_PASSWORD' => defined('SMTP_PASSWORD') ? (SMTP_PASSWORD === '' ? '' : '[SET]') : '',
];

if (!isset($_GET['send']) || $_GET['send'] !== '1') {
    echo json_encode([
        'status' => 'ready',
        'config_loaded' => notification_mail_configured(),
        'smtp' => $config,
        'hint' => 'Send via /tests/email_test.php?send=1 from localhost to trigger the real app email helper.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$user = $db->query("SELECT user_id, full_name, email FROM users WHERE email IS NOT NULL AND email <> '' ORDER BY user_id DESC LIMIT 1")->fetch();
if (!$user) {
    http_response_code(500);
    echo json_encode([
        'status' => 'failed',
        'message' => 'No active user email address exists to use for the test.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$mailResult = null;
$sent = send_email_notification(
    $db,
    (int)$user['user_id'],
    (string)$user['full_name'],
    'Katale application SMTP verification',
    "This is a live test email from the Katale application using the same local SMTP configuration as the normal browser flow.\n\nIf you received this in your inbox, the app email path is working.",
    APP_URL,
    0,
    'system'
);
$mailResult = $GLOBALS['__katale_last_mail_result__'] ?? null;

if ($sent) {
    echo json_encode([
        'status' => 'EMAIL SMTP ACCEPTED',
        'delivery_stage' => 'smtp_accepted',
        'inbox_delivery' => 'not_verified',
        'recipient' => $user['email'],
        'config_loaded' => notification_mail_configured(),
        'from_invalid' => katale_from_address_invalid_for_delivery(),
        'phpmailer' => $mailResult,
        'smtp' => $config,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(500);
echo json_encode([
    'status' => 'EMAIL FAILED',
    'delivery_stage' => is_array($mailResult) ? ($mailResult['stage'] ?? 'failed') : 'failed',
    'recipient' => $user['email'],
    'config_loaded' => notification_mail_configured(),
    'from_invalid' => katale_from_address_invalid_for_delivery(),
    'phpmailer' => $mailResult,
    'smtp' => $config,
    'message' => is_array($mailResult) ? ($mailResult['error'] ?? 'SMTP send failed') : 'SMTP send failed',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
