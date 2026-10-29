<?php
$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$localAllowed = in_array($remoteAddress, ['127.0.0.1', '::1'], true);
if (PHP_SAPI !== 'cli' && !$localAllowed) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/brevo_api.php';

$uuid = trim((string)($_GET['uuid'] ?? ''));
if ($uuid === '' && PHP_SAPI === 'cli' && isset($argv[1])) {
    $uuid = trim($argv[1]);
}
if ($uuid === '') {
    echo json_encode(['error' => 'uuid required']);
    exit(1);
}

$detail = katale_brevo_request('GET', '/smtp/emails/' . rawurlencode($uuid));
header('Content-Type: application/json; charset=utf-8');
if (!$detail['ok']) {
    echo json_encode(['ok' => false, 'error' => $detail['error'], 'status' => $detail['status']], JSON_PRETTY_PRINT);
    exit;
}

$body = $detail['body'] ?? [];
$events = [];
foreach ($body['events'] ?? [] as $ev) {
    $events[] = [
        'name' => $ev['name'] ?? null,
        'time' => $ev['time'] ?? null,
        'reason' => $ev['reason'] ?? ($ev['message'] ?? null),
    ];
}

echo json_encode([
    'ok' => true,
    'uuid' => $uuid,
    'subject' => $body['subject'] ?? null,
    'from' => isset($body['from']) ? katale_mask_email((string)$body['from']) : null,
    'to' => isset($body['email']) ? katale_mask_email((string)$body['email']) : null,
    'events' => $events,
    'body' => $body['body'] ?? null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
