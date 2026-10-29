<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Exception.php';
require_once __DIR__ . '/../src/SMTP.php';
require_once __DIR__ . '/../src/PHPMailer.php';

use PHPMailer\PHPMailer\PHPMailer;

$mail = new PHPMailer(true);
$mail->isSMTP();
$mail->Host = SMTP_HOST;
$mail->Port = (int)SMTP_PORT;
$mail->SMTPAuth = true;
$mail->Username = SMTP_USERNAME;
$mail->Password = SMTP_PASSWORD;
$mail->SMTPSecure = SMTP_ENCRYPTION;
$mail->CharSet = 'UTF-8';
$mail->Timeout = 30;
$mail->SMTPDebug = 2;
$mail->Debugoutput = static function ($str, $level) { echo '[SMTP ' . $level . '] ' . trim((string)$str) . PHP_EOL; };
$mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
$mail->addAddress('nannyondo.cotlida@stud.umu.ac.ug', 'Katale Test');
$mail->Subject = 'Katale recipient validation';
$mail->Body = '<p>Recipient validation</p>';
$mail->AltBody = 'Recipient validation';

try {
    $mail->send();
    echo "RECIPIENT_SUCCESS\n";
    exit(0);
} catch (Throwable $e) {
    echo 'RECIPIENT_FAILURE:' . $e->getMessage() . PHP_EOL;
    exit(1);
}
