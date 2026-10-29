<?php
/**
 * Send one uniquely identifiable delivery test (localhost/CLI only).
 */
$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$localAllowed = in_array($remoteAddress, ['127.0.0.1', '::1'], true);
if (PHP_SAPI !== 'cli' && !$localAllowed) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/brevo_api.php';

$to = trim((string)($_GET['to'] ?? ''));
if ($to === '' && PHP_SAPI === 'cli' && isset($argv[1])) {
    $to = trim($argv[1]);
}
if ($to === '') {
    $to = trim((string)($db->query("SELECT email FROM users WHERE email <> '' ORDER BY user_id DESC LIMIT 1")->fetch()['email'] ?? ''));
}

if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['error' => 'valid to= email required']);
    exit(1);
}

$traceId = 'KT-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
$subject = 'KATALE EMAIL DELIVERY TEST — ' . date('Y-m-d H:i:s');
$html = '<p>This is a Katale transactional email delivery test.</p><p><strong>Trace ID:</strong> ' . htmlspecialchars($traceId, ENT_QUOTES, 'UTF-8') . '</p>';
$alt = "This is a Katale transactional email delivery test.\nTrace ID: {$traceId}\n";

$result = katale_smtp_send($to, 'Delivery Test', $subject, $html, $alt, $traceId);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'trace_id' => $traceId,
    'to' => katale_mask_email($to),
    'from' => katale_mask_email((string)SMTP_FROM_EMAIL),
    'phpmailer' => $result,
    'note' => 'smtp_accepted means Brevo SMTP accepted the message for processing, not inbox delivery.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
