<?php
require_once __DIR__ . '/../config/config.php';

$expected = ['SMTP_HOST', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_ENCRYPTION', 'SMTP_FROM_EMAIL', 'SMTP_FROM_NAME'];
$missing = [];
foreach ($expected as $key) {
    $value = defined($key) ? constant($key) : '';
    if ($key === 'SMTP_PORT') {
        if ((string)$value === '' || (int)$value <= 0) {
            $missing[] = $key;
        }
        continue;
    }

    if (trim((string)$value) === '') {
        $missing[] = $key;
        continue;
    }

    if (in_array($key, ['SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_FROM_EMAIL'], true)
        && preg_match('/^(?:your[-_]|replace[-_]?me|placeholder|<.*>)/i', trim((string)$value)) === 1) {
        $missing[] = $key;
        continue;
    }

}

$requiredForSend = ['SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_FROM_EMAIL'];
$missingRequired = array_values(array_intersect($requiredForSend, $missing));

header('Content-Type: application/json');
echo json_encode([
    'status' => empty($missingRequired) ? 'ready' : 'missing',
    'missing' => $missingRequired,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
