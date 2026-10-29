<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

echo "STEP1\n";
var_dump(SMTP_HOST, SMTP_PORT, SMTP_USERNAME, SMTP_ENCRYPTION, SMTP_FROM_EMAIL, SMTP_FROM_NAME);
echo "configured=" . (notification_mail_configured() ? 'true' : 'false') . "\n";

$db = Database::conn();
echo "STEP2\n";
$stmt = $db->query('SELECT user_id, full_name, email FROM users WHERE email IS NOT NULL AND email <> "" ORDER BY user_id DESC LIMIT 1');
$user = $stmt->fetch();
var_dump($user);

if (!$user) {
    echo "NO_USER\n";
    exit(1);
}

echo "STEP3\n";
$result = send_email_notification(
    $db,
    (int)$user['user_id'],
    (string)$user['full_name'],
    'Katale app helper debug',
    'Live debug from Katale helper.',
    APP_URL,
    0,
    'system'
);

echo "STEP4 result=" . ($result ? 'true' : 'false') . "\n";
exit($result ? 0 : 1);
