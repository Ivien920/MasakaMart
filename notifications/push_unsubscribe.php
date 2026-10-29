<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

if (!is_logged_in()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$submittedToken = (string)($data['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

$endpoint = trim((string)($data['endpoint'] ?? ''));
if ($endpoint === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_endpoint']);
    exit;
}

$userId = (int)current_user()['user_id'];
$db = Database::conn();

try {
    // A subscription can only be removed by the account that owns it.
    $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = :e AND user_id = :uid")
        ->execute(['e' => $endpoint, 'uid' => $userId]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('[push_unsubscribe] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
