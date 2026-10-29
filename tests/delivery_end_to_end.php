<?php
/**
 * Send a traced message, then query Brevo transactional logs when BREVO_API_KEY is set.
 */
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (PHP_SAPI !== 'cli' && !in_array($remote, ['127.0.0.1', '::1'], true)) {
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
    $to = 'ivienroes0@gmail.com';
}

$traceId = 'KT-E2E-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
$subject = 'KATAle EMAIL DELIVERY TEST — ' . date('Y-m-d H:i:s');
$html = '<p>This is a Katale transactional email delivery test.</p><p>Trace: ' . e($traceId) . '</p>';
$result = katale_smtp_send($to, 'Delivery Test', $subject, $html, strip_tags($html), $traceId);

$report = [
    'katale' => [
        'trace_id' => $traceId,
        'subject' => $subject,
        'to' => katale_mask_email($to),
        'from' => katale_mask_email((string)SMTP_FROM_EMAIL),
        'phpmailer' => $result,
    ],
    'brevo' => null,
    'recipient_inbox' => 'not_checked_from_server',
];

if (katale_brevo_api_key() !== '') {
    sleep(3);
    $list = katale_brevo_request('GET', '/smtp/emails', ['email' => $to, 'sort' => 'desc', 'limit' => 10]);
    if ($list['ok']) {
        $match = null;
        foreach ($list['body']['transactionalEmails'] ?? [] as $row) {
            if (($row['subject'] ?? '') === $subject) {
                $match = $row;
                break;
            }
        }
        if ($match && !empty($match['uuid'])) {
            $detail = katale_brevo_request('GET', '/smtp/emails/' . rawurlencode((string)$match['uuid']));
            $events = [];
            if ($detail['ok']) {
                foreach ($detail['body']['events'] ?? [] as $ev) {
                    $events[] = [
                        'name' => $ev['name'] ?? null,
                        'time' => $ev['time'] ?? null,
                        'reason' => $ev['reason'] ?? ($ev['message'] ?? null),
                    ];
                }
            }
            $report['brevo'] = [
                'uuid' => $match['uuid'],
                'subject' => $match['subject'] ?? null,
                'events' => $events,
            ];
        } else {
            $report['brevo'] = ['note' => 'Message not found in Brevo transactional list yet', 'list_count' => count($list['body']['transactionalEmails'] ?? [])];
        }
    } else {
        $report['brevo'] = ['error' => $list['error'] ?? 'list failed'];
    }
} else {
    $report['brevo'] = [
        'error' => 'BREVO_API_KEY (xkeysib-...) not configured — add to C:\\wamp64\\katale_local.env.php to query delivery events.',
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
