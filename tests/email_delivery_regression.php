<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/notifications.php';

$requiredFunctions = [
    'notification_mail_configured',
    'send_order_notification_email',
    'send_email_notification',
];

foreach ($requiredFunctions as $functionName) {
    if (!function_exists($functionName)) {
        fwrite(STDERR, "Missing required function: {$functionName}\n");
        exit(1);
    }
}

if (!is_string(SMTP_HOST) || trim(SMTP_HOST) === '') {
    fwrite(STDERR, "SMTP_HOST is empty\n");
    exit(1);
}

echo "Email notification contracts are present and SMTP host is configured.\n";
exit(0);
