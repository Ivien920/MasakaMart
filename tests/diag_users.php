<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
$db = Database::conn();
$stmt = $db->query('SELECT user_id, full_name, email, is_active FROM users ORDER BY user_id DESC LIMIT 20');
$rows = $stmt->fetchAll();
if (empty($rows)) {
    echo "NO_USERS\n";
    exit(1);
}
foreach ($rows as $row) {
    echo $row['user_id'] . '|' . ($row['full_name'] ?? '') . '|' . ($row['email'] ?? '') . '|' . ($row['is_active'] ?? '') . PHP_EOL;
}
