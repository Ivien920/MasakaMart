<?php
/**
 * POST endpoint called by assets/js/push.js after the browser creates a
 * push subscription. Requires login + CSRF token; stores/updates one row
 * per unique endpoint (a device can re-subscribe without creating dupes).
 */

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
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login_required']);
    exit;
}

// This endpoint receives a JSON body (not a form POST), so csrf_verify()'s
// $_POST check does not apply — the token travels inside the JSON instead
// and is checked the same way csrf_verify() does (constant-time compare).
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$submittedToken = (string)($data['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

$endpoint = trim((string)($data['endpoint'] ?? ''));
$p256dh = trim((string)($data['keys']['p256dh'] ?? ''));
$auth = trim((string)($data['keys']['auth'] ?? ''));

if ($endpoint === '' || $p256dh === '' || $auth === '' || strlen($endpoint) > 512) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_subscription']);
    exit;
}

$userId = (int)current_user()['user_id'];
$db = Database::conn();

try {
    $stmt = $db->prepare(
        "INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent, last_used_at)
         VALUES (:uid, :endpoint, :p256dh, :auth, :ua, NOW())
         ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            p256dh = VALUES(p256dh),
            auth = VALUES(auth),
            user_agent = VALUES(user_agent),
            last_used_at = NOW()"
    );
    $stmt->execute([
        'uid' => $userId,
        'endpoint' => $endpoint,
        'p256dh' => $p256dh,
        'auth' => $auth,
        'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('[push_subscribe] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
