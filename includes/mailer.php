<?php
/**
 * MasakaMart — Central SMTP mail transport (PHPMailer + Brevo).
 *
 * Provides a unified, reliable email delivery mechanism for MasakaMart.
 * Uses Brevo SMTP relay with PHPMailer, secure logging, and branded HTML templates.
 */

require_once __DIR__ . '/../config/config.php';
if (is_file(__DIR__ . '/functions.php')) {
    require_once __DIR__ . '/functions.php';
}

require_once __DIR__ . '/../src/Exception.php';
require_once __DIR__ . '/../src/SMTP.php';
require_once __DIR__ . '/../src/PHPMailer.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

if (!function_exists('e')) {
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('notification_email_value')) {
    function notification_email_value(string $key, string $default = ''): string
    {
        $constantMap = [];
        foreach ([
            'SMTP_HOST', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_PASSWORD',
            'SMTP_ENCRYPTION', 'SMTP_FROM_EMAIL', 'SMTP_FROM_NAME',
            'SMTP_REPLY_TO_EMAIL', 'ADMIN_EMAIL', 'BREVO_API_KEY'
        ] as $constantKey) {
            if (defined($constantKey)) {
                $constantMap[$constantKey] = constant($constantKey);
            }
        }

        if (array_key_exists($key, $constantMap) && (string)$constantMap[$key] !== '') {
            return (string)$constantMap[$key];
        }

        return katale_runtime_value($key, $default);
    }
}

if (!function_exists('katale_mask_email')) {
    function katale_mask_email(string $email): string
    {
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '[invalid-email]';
        }
        [$local, $domain] = explode('@', $email, 2);
        $len = strlen($local);
        $localMask = $len <= 2 ? str_repeat('*', $len) : substr($local, 0, 1) . '***' . substr($local, -1);
        return $localMask . '@' . $domain;
    }
}

if (!function_exists('katale_mail_encryption')) {
    function katale_mail_encryption(): string
    {
        $enc = strtolower(trim(notification_email_value('SMTP_ENCRYPTION', 'tls')));
        $port = (int)notification_email_value('SMTP_PORT', '587');

        if ($enc === 'ssl' || $enc === 'smtps' || $port === 465) {
            return PHPMailer::ENCRYPTION_SMTPS;
        }

        return PHPMailer::ENCRYPTION_STARTTLS;
    }
}

if (!function_exists('katale_from_address_invalid_for_delivery')) {
    function katale_from_address_invalid_for_delivery(): ?string
    {
        $from = strtolower(trim(notification_email_value('SMTP_FROM_EMAIL', '')));
        if ($from === '') {
            return 'SMTP_FROM_EMAIL is empty.';
        }
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return 'SMTP_FROM_EMAIL is not a valid email address.';
        }
        if (str_ends_with($from, '@smtp-brevo.com') || str_ends_with($from, '@smtp-relay.brevo.com')) {
            return 'SMTP_FROM_EMAIL must be a verified sender domain/address in Brevo, not the SMTP relay username.';
        }

        return null;
    }
}

if (!function_exists('katale_html_to_plain_text')) {
    /**
     * Converts an HTML email body into clean, human-readable plain text.
     */
    function katale_html_to_plain_text(string $html): string
    {
        // Replace structural tags with line breaks
        $text = preg_replace('/<(?:br|\/p|\/div|\/h[1-6]|\/tr)>/i', "\n", $html);
        $text = preg_replace('/<li[^>]*>/i', "  • ", $text);
        $text = preg_replace('/<\/(?:td|th)>/i', "    ", $text);
        // Strip remaining HTML tags
        $text = strip_tags($text);
        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        // Normalize line breaks and multiple empty lines
        $text = preg_replace("/[\r\n]+/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }
}

if (!function_exists('katale_format_mail_log_message')) {
    function katale_format_mail_log_message(?array $mailMeta): string
    {
        if (!is_array($mailMeta)) {
            return 'delivery_stage=smtp_accepted';
        }
        $parts = ['delivery_stage=' . ($mailMeta['stage'] ?? 'unknown')];
        if (!empty($mailMeta['trace_id'])) {
            $parts[] = 'trace_id=' . $mailMeta['trace_id'];
        }
        if (!empty($mailMeta['smtp_message_id'])) {
            $parts[] = 'smtp_message_id=' . $mailMeta['smtp_message_id'];
        }

        return implode('; ', $parts);
    }
}

if (!function_exists('katale_email_shell')) {
    /**
     * Mobile-friendly, high-compatibility branded email shell for MasakaMart.
     */
    function katale_email_shell(string $subject, string $body, string $preheader = ''): string
    {
        $brand = defined('APP_NAME') ? APP_NAME : 'MasakaMart';
        $tagline = defined('APP_TAGLINE') ? APP_TAGLINE : 'Fresh Food. Better Living.';
        $appUrl = defined('APP_URL') ? APP_URL : 'https://masakamart.ug';
        $fromEmail = notification_email_value('SMTP_FROM_EMAIL', 'support@masakamart.xo.je');
        $safeSubject = e($subject);
        $safePreheader = $preheader !== '' ? e($preheader) : $safeSubject;
        $currentYear = date('Y');

        return '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>' . $safeSubject . '</title>
</head>
<body style="margin:0; padding:0; background-color:#F7F9FA; font-family:-apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased; color:#102A43;">
  <!-- Preheader text for inbox preview -->
    <div style="display:none; font-size:1px; color:#F7F9FA; line-height:1px; max-height:0px; max-width:0px; opacity:0; overflow:hidden;">
    ' . $safePreheader . '
  </div>
    <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color:#F7F9FA; padding:24px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="max-width:620px; background-color:#FFFFFF; border:1px solid #DDE3E7; border-radius:12px; overflow:hidden; box-shadow:0 2px 8px rgba(8,43,76,0.06);">
          <!-- Header Banner -->
          <tr>
            <td style="background:#082B4C; padding:26px 32px; text-align:left;">
              <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0">
                <tr>
                  <td>
                    <a href="' . e($appUrl) . '" style="text-decoration:none; color:#FFFFFF;" target="_blank">
                      <span style="font-size:24px; font-weight:800; letter-spacing:0.04em; color:#FFFFFF; display:block;">' . e($brand) . '</span>
                      <span style="font-size:12px; color:#FFF1E8; letter-spacing:0.02em; display:block; margin-top:2px;">' . e($tagline) . '</span>
                    </a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <!-- Main Content -->
          <tr>
            <td style="padding:32px 32px 28px 32px; font-size:15px; line-height:1.6; color:#102A43;">
              ' . $body . '
            </td>
          </tr>
          <!-- Divider -->
          <tr>
            <td style="padding:0 32px;">
              <div style="border-top:1px solid #DDE3E7;"></div>
            </td>
          </tr>
          <!-- Footer -->
          <tr>
            <td style="padding:24px 32px; font-size:12px; line-height:1.5; color:#64748B; text-align:center;">
              <p style="margin:0 0 6px 0;"><strong>' . e($brand) . '</strong> &bull; Masaka, Uganda</p>
              <p style="margin:0 0 6px 0;">Need help? Contact us at <a href="mailto:' . e($fromEmail) . '" style="color:#FF6B00; text-decoration:none;">' . e($fromEmail) . '</a></p>
              <p style="margin:0; font-size:11px; color:#8A98A8;">&copy; ' . $currentYear . ' ' . e($brand) . '. All rights reserved. This is an automated transactional message.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
    }
}

if (!function_exists('sendEmail')) {
    /**
     * Central, reliable reusable email sending function.
     *
     * Conceptually equivalent to sendEmail(recipient, subject, htmlMessage, plainTextMessage)
     * Configures PHPMailer for Brevo SMTP, handles headers, encryption, and secure logging.
     *
     * @param string $recipient Recipient email address
     * @param string $subject Email subject line
     * @param string $htmlMessage HTML email body
     * @param string $plainTextMessage Plain-text fallback (auto-generated from HTML if empty)
     * @param string $recipientName Optional recipient display name
     * @param string|null $traceId Optional tracing identifier
     * @param string|null $replyToEmail Optional reply-to address
     * @param string|null $replyToName Optional reply-to display name
     * @return array{ok:bool,success:bool,stage:string,message:string,trace_id:?string,smtp_message_id:?string,error:?string}
     */
    function sendEmail(
        string $recipient,
        string $subject,
        string $htmlMessage,
        string $plainTextMessage = '',
        string $recipientName = '',
        ?string $traceId = null,
        ?string $replyToEmail = null,
        ?string $replyToName = null
    ): array {
        $fail = static function (string $stage, string $error, ?string $trace = null): array {
            $safeError = preg_replace('/\s+/', ' ', $error) ?: 'email send failed';
            error_log('[EMAIL] Send failed | stage=' . $stage . ' | error=' . $safeError);
            return [
                'ok' => false,
                'success' => false,
                'stage' => $stage,
                'message' => "We couldn't send the email right now. Please try again later.",
                'trace_id' => $trace,
                'smtp_message_id' => null,
                'error' => $safeError,
            ];
        };

        $recipient = trim($recipient);
        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return $fail('validation', 'invalid recipient address', $traceId);
        }

        if (!notification_mail_configured()) {
            return $fail('configuration', 'SMTP credentials are not configured', $traceId);
        }

        $fromInvalid = katale_from_address_invalid_for_delivery();
        if ($fromInvalid !== null) {
            return $fail('configuration', $fromInvalid, $traceId);
        }

        $fromAddress = notification_email_value('SMTP_FROM_EMAIL', '');
        $fromName = notification_email_value('SMTP_FROM_NAME', 'MasakaMart');
        $replyTo = $replyToEmail !== null && trim($replyToEmail) !== ''
            ? trim($replyToEmail)
            : notification_email_value('SMTP_REPLY_TO_EMAIL', $fromAddress);

        if (function_exists('notification_log_mail_attempt')) {
            notification_log_mail_attempt(katale_mask_email($recipient), $subject, katale_mask_email($fromAddress));
        }

        try {
            $mailer = new PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host = notification_email_value('SMTP_HOST', 'smtp-relay.brevo.com');
            $mailer->Port = (int)notification_email_value('SMTP_PORT', '587');
            $mailer->SMTPAuth = true;
            $mailer->Username = notification_email_value('SMTP_USERNAME', '');
            $mailer->Password = notification_email_value('SMTP_PASSWORD', '');
            $mailer->SMTPSecure = katale_mail_encryption();
            $mailer->CharSet = 'UTF-8';
            $mailer->Timeout = 25;
            $mailer->SMTPKeepAlive = false;

            // Use local CA bundle if present to guarantee SSL verification on Windows WAMP
            $caBundle = realpath(__DIR__ . '/../config/cacert.pem');
            if ($caBundle && is_file($caBundle)) {
                $mailer->SMTPOptions = [
                    'ssl' => [
                        'cafile' => $caBundle,
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                        'allow_self_signed' => false,
                    ],
                ];
            }

            $mailer->SMTPDebug = defined('MAIL_DEBUG') && MAIL_DEBUG ? 2 : 0;
            $mailer->Debugoutput = static function ($str, $level) {
                $msg = trim((string)$str);
                if ($msg === '') return;
                // Never log passwords or keys that might be echoed in SMTP debug
                $sanitized = preg_replace('/(PASS|AUTH LOGIN|xkeysib|xsmtpsib)[^\r\n]*/i', '$1 [REDACTED]', $msg);
                error_log('[SMTP ' . $level . '] ' . preg_replace('/\s+/', ' ', $sanitized));
            };

            $mailer->setFrom($fromAddress, $fromName);
            if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $mailer->addReplyTo($replyTo, $replyToName ?: $fromName);
            }

            $mailer->addAddress($recipient, $recipientName);
            $mailer->isHTML(true);

            // Sanitize subject against header injection
            $mailer->Subject = str_replace(["\r", "\n"], ' ', $subject);
            $mailer->Body = $htmlMessage;
            $mailer->AltBody = $plainTextMessage !== '' ? $plainTextMessage : katale_html_to_plain_text($htmlMessage);

            $mailer->addCustomHeader('X-Mailer', 'MasakaMart PHPMailer');
            if ($traceId !== null && $traceId !== '') {
                $mailer->addCustomHeader('X-MasakaMart-Trace-ID', $traceId);
            }

            $sent = $mailer->send();
            if ($sent !== true) {
                throw new RuntimeException($mailer->ErrorInfo ?: 'SMTP send() returned false.');
            }

            $messageId = $mailer->getLastMessageID() ?: null;
            error_log(
                'EMAIL SMTP ACCEPTED — To=' . katale_mask_email($recipient)
                . ' | Subject=' . $subject
                . ($traceId ? ' | Trace=' . $traceId : '')
                . ($messageId ? ' | Message-ID=' . $messageId : '')
            );

            return [
                'ok' => true,
                'success' => true,
                'stage' => 'smtp_accepted',
                'message' => 'Email accepted by SMTP server.',
                'trace_id' => $traceId,
                'smtp_message_id' => $messageId,
                'error' => null,
            ];
        } catch (PHPMailerException $e) {
            $safe = preg_replace('/\s+/', ' ', $e->getMessage());
            return $fail('smtp', $safe, $traceId);
        } catch (Throwable $e) {
            $safe = preg_replace('/\s+/', ' ', $e->getMessage());
            return $fail('smtp', $safe, $traceId);
        }
    }
}

if (!function_exists('katale_smtp_send')) {
    /**
     * Backward-compatible wrapper for existing katale_smtp_send calls.
     * Routes directly to central sendEmail.
     */
    function katale_smtp_send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $altBody,
        ?string $traceId = null,
        ?string $replyToEmail = null,
        ?string $replyToName = null
    ): array {
        return sendEmail($toEmail, $subject, $htmlBody, $altBody, $toName, $traceId, $replyToEmail, $replyToName);
    }
}

if (!function_exists('katale_send_email')) {
    /**
     * Project-named alias for sendEmail.
     */
    function katale_send_email(
        string $recipient,
        string $subject,
        string $htmlMessage,
        string $plainTextMessage = '',
        string $recipientName = '',
        ?string $traceId = null
    ): array {
        return sendEmail($recipient, $subject, $htmlMessage, $plainTextMessage, $recipientName, $traceId);
    }
}
