<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

$db = Database::conn();

$stmt = $db->query('SELECT user_id, full_name, email FROM users WHERE email IS NOT NULL AND email <> "" ORDER BY user_id DESC LIMIT 1');
$user = $stmt->fetch();
if (!$user) {
    fwrite(STDERR, "No user email found in database; cannot test real notification email.\n");
    exit(1);
}

$result = send_email_notification(
    $db,
    (int)$user['user_id'],
    (string)$user['full_name'],
    'Katale SMTP verification',
    "This is a live SMTP verification message from the Katale application.\n\nIf you received it, the Brevo relay is working correctly.",
    APP_URL,
    0,
    'system'
);

if ($result) {
    echo "LIVE_EMAIL_SUCCESS\n";
    echo "To=" . $user['email'] . "\n";
    exit(0);
}

fwrite(STDERR, "LIVE_EMAIL_FAILED\n");
exit(1);
