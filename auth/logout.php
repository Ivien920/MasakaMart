<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
}

$flashMessages = $_SESSION['flash'] ?? [];
$sessionId = $_SESSION['katale_session_id'] ?? bin2hex(random_bytes(16));

$_SESSION = [
    'flash' => $flashMessages,
    'katale_session_id' => $sessionId,
];
$_SESSION['flash']['success'] = 'You have been logged out.';

session_regenerate_id(true);
redirect(APP_URL . '/index.php');
