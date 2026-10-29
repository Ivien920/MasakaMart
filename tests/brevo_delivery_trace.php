<?php
/**
 * CLI / localhost: trace Brevo account, senders, recent SMTP activity, suppression.
 * Never prints API keys or SMTP passwords.
 */
$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$localAllowed = in_array($remoteAddress, ['127.0.0.1', '::1'], true) || preg_match('/^127\.0\.0\./', (string)$remoteAddress) === 1;
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server' && !$localAllowed) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/brevo_api.php';

header('Content-Type: application/json; charset=utf-8');

$recipient = trim((string)($_GET['email'] ?? ''));
if ($recipient === '' && PHP_SAPI === 'cli' && isset($argv[1])) {
    $recipient = trim($argv[1]);
}

$report = [
    'katale_from_email' => katale_mask_email((string)SMTP_FROM_EMAIL),
    'katale_smtp_username' => katale_mask_email((string)SMTP_USERNAME),
    'brevo_api_configured' => katale_brevo_api_key() !== '',
    'account' => null,
    'senders' => null,
    'blocked_contacts_sample' => null,
    'contact' => null,
    'recent_smtp_emails' => null,
    'errors' => [],
];

$account = katale_brevo_request('GET', '/account');
if ($account['ok']) {
    $body = $account['body'] ?? [];
    $report['account'] = [
        'company_name' => $body['companyName'] ?? null,
        'plan_type' => $body['plan'][0]['type'] ?? ($body['plan']['type'] ?? null),
        'credits' => $body['plan'][0]['credits'] ?? null,
        'credits_type' => $body['plan'][0]['creditsType'] ?? null,
    ];
} else {
    $report['errors'][] = 'account: ' . ($account['error'] ?? 'unknown');
}

$senders = katale_brevo_request('GET', '/senders');
if ($senders['ok']) {
    $list = [];
    foreach ($senders['body']['senders'] ?? [] as $sender) {
        $list[] = [
            'email' => katale_mask_email((string)($sender['email'] ?? '')),
            'name' => $sender['name'] ?? null,
            'active' => $sender['active'] ?? null,
            'verified' => $sender['verified'] ?? null,
        ];
    }
    $report['senders'] = $list;
} else {
    $report['errors'][] = 'senders: ' . ($senders['error'] ?? 'unknown');
}

$blocked = katale_brevo_request('GET', '/smtp/blockedContacts', ['limit' => 10, 'offset' => 0]);
if ($blocked['ok']) {
    $rows = [];
    foreach ($blocked['body']['contacts'] ?? [] as $row) {
        $rows[] = [
            'email' => katale_mask_email((string)($row['email'] ?? '')),
            'reason' => $row['reason'] ?? null,
            'blocked_at' => $row['blockedAt'] ?? null,
        ];
    }
    $report['blocked_contacts_sample'] = $rows;
} else {
    $report['errors'][] = 'blockedContacts: ' . ($blocked['error'] ?? 'unknown');
}

if ($recipient !== '' && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    $contact = katale_brevo_request('GET', '/contacts/' . rawurlencode($recipient));
    if ($contact['ok']) {
        $c = $contact['body'] ?? [];
        $report['contact'] = [
            'email' => katale_mask_email((string)($c['email'] ?? $recipient)),
            'email_blacklisted' => $c['emailBlacklisted'] ?? null,
            'sms_blacklisted' => $c['smsBlacklisted'] ?? null,
            'list_ids' => $c['listIds'] ?? [],
        ];
    } else {
        $report['contact'] = ['email' => katale_mask_email($recipient), 'found' => false, 'note' => $contact['error'] ?? 'not found'];
    }

    $emails = katale_brevo_request('GET', '/smtp/emails', [
        'email' => $recipient,
        'sort' => 'desc',
        'limit' => 15,
    ]);
    if ($emails['ok']) {
        $items = [];
        foreach ($emails['body']['transactionalEmails'] ?? [] as $msg) {
            $items[] = [
                'uuid' => $msg['uuid'] ?? null,
                'subject' => $msg['subject'] ?? null,
                'from' => isset($msg['from']) ? katale_mask_email((string)$msg['from']) : null,
                'date' => $msg['date'] ?? null,
                'template_id' => $msg['templateId'] ?? null,
            ];
        }
        $report['recent_smtp_emails'] = $items;
    } else {
        $report['errors'][] = 'smtp/emails: ' . ($emails['error'] ?? 'unknown');
    }
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
