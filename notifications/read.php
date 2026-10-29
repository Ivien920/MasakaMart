<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$userId = (int)current_user()['user_id'];
$notificationId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare('SELECT notification_id, link FROM notifications WHERE notification_id = :id AND user_id = :uid LIMIT 1');
$stmt->execute(['id' => $notificationId, 'uid' => $userId]);
$row = $stmt->fetch();

if ($row) {
    mark_notification_read($db, $userId, $notificationId);
    $dest = !empty($row['link']) ? (string)$row['link'] : (APP_URL . '/notifications.php');
    redirect($dest);
}

flash('error', 'Notification not found.');
redirect(APP_URL . '/notifications.php');
