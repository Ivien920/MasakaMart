<?php
$required = ['SMTP_HOST','SMTP_PORT','SMTP_USERNAME','SMTP_PASSWORD','SMTP_ENCRYPTION','SMTP_FROM_EMAIL','SMTP_FROM_NAME'];
foreach ($required as $key) {
    $value = getenv($key);
    if ($value === false || trim((string)$value) === '') {
        fwrite(STDERR, "MISSING_ENV:$key\n");
        exit(1);
    }
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Exception.php';
require_once __DIR__ . '/../src/SMTP.php';
require_once __DIR__ . '/../src/PHPMailer.php';

use PHPMailer\PHPMailer\PHPMailer;

$mail = new PHPMailer(true);
$mail->SMTPDebug = 2;
$mail->Debugoutput = static function ($str, $level) {
    $prefix = '[SMTP ' . $level . ']';
    $trimmed = trim((string)$str);
    if ($trimmed !== '') {
        echo $prefix . ' ' . $trimmed . PHP_EOL;
    }
};
$mail->isSMTP();
$mail->Host = SMTP_HOST;
$mail->Port = (int)SMTP_PORT;
$mail->SMTPAuth = true;
$mail->Username = SMTP_USERNAME;
$mail->Password = SMTP_PASSWORD;
$mail->SMTPSecure = SMTP_ENCRYPTION;
$mail->CharSet = 'UTF-8';
$mail->Timeout = 30;
$mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
$mail->addAddress('ivienroes0@gmail.com', 'Katale Debug Recipient');
$mail->Subject = 'Katale Brevo SMTP Test';
$mail->Body = '<p>This is a live Brevo SMTP debug message from the Katale application.</p>';
$mail->AltBody = 'This is a live Brevo SMTP debug message from the Katale application.';

try {
    $sent = $mail->send();
    echo 'SMTP_RESULT:' . ($sent ? 'SUCCESS' : 'FALSE') . PHP_EOL;
    exit($sent ? 0 : 1);
} catch (Throwable $e) {
    echo 'SMTP_EXCEPTION:' . $e->getMessage() . PHP_EOL;
    exit(1);
}
