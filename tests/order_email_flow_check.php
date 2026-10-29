<?php
$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$localAllowed = in_array($remoteAddress, ['127.0.0.1', '::1'], true) || preg_match('/^127\.0\.0\./', (string)$remoteAddress) === 1;
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server' && !$localAllowed) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../includes/bootstrap.php';

$order = $db->query('SELECT order_id FROM orders ORDER BY order_id DESC LIMIT 1')->fetch();
if (!$order) {
    echo "NO_ORDERS\n";
    exit(1);
}

$orderId = (int)$order['order_id'];
handle_order_status_change($db, $orderId, 'received');

$log = $db->prepare(
    'SELECT nl.channel, nl.status
     FROM notification_logs nl
     JOIN orders o ON o.user_id = nl.user_id
     WHERE o.order_id = :oid
     ORDER BY nl.log_id DESC
     LIMIT 4'
);
$log->execute(['oid' => $orderId]);
foreach ($log->fetchAll() as $row) {
    echo $row['channel'] . ':' . $row['status'] . PHP_EOL;
}
