<?php
require_once __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    echo json_encode(['count' => 0, 'items' => []]);
    exit;
}

$userId = (int)current_user()['user_id'];
$items = [];
$notifications = fetch_recent_notifications($db, $userId, 8);
foreach ($notifications as $n) {
    $orderNum = notification_order_number_for($db, $n);
    $items[] = [
        'id' => (int)$n['notification_id'],
        'brand' => notification_brand_label(),
        'type_label' => notification_type_label($n['type']),
        'title' => $n['title'],
        'message' => $n['message'],
        'date' => date('d M Y, H:i', strtotime($n['created_at'])),
        'link' => notification_read_url((int)$n['notification_id']),
        'icon' => notification_icon_for_type($n['type']),
        'is_read' => (int)$n['is_read'],
        'order_number' => $orderNum,
    ];
}

echo json_encode(['count' => count_unread_notifications($db, $userId), 'items' => $items]);
