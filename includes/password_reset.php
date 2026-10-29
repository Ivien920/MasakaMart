<?php
/**
 * KATALE — Password reset tokens and email (uses katale_smtp_send).
 */

if (!function_exists('ensure_password_reset_table')) {
    function ensure_password_reset_table(PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS password_reset_tokens (
              token_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              user_id INT UNSIGNED NOT NULL,
              token_hash CHAR(64) NOT NULL,
              expires_at DATETIME NOT NULL,
              used_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_password_reset_hash (token_hash),
              INDEX idx_password_reset_user (user_id),
              FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
            ) ENGINE=InnoDB
        ");
    }
}

if (!function_exists('katale_password_meets_requirements')) {
    function katale_password_meets_requirements(string $password): ?string
    {
        if (strlen($password) < 8) {
            return 'Password must be at least 8 characters.';
        }

        return null;
    }
}

if (!function_exists('katale_create_password_reset_token')) {
    /**
     * @return array{token:string,expires_at:string}|null
     */
    function katale_create_password_reset_token(PDO $db, int $userId): ?array
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);

        $db->prepare('DELETE FROM password_reset_tokens WHERE user_id = :uid AND (used_at IS NOT NULL OR expires_at < NOW())')
            ->execute(['uid' => $userId]);

        $stmt = $db->prepare('
            INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
            VALUES (:uid, :hash, :exp)
        ');
        $stmt->execute(['uid' => $userId, 'hash' => $hash, 'exp' => $expiresAt]);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }
}

if (!function_exists('katale_find_password_reset_user')) {
    function katale_find_password_reset_user(PDO $db, string $plainToken): ?array
    {
        $plainToken = trim($plainToken);
        if ($plainToken === '' || !preg_match('/^[a-f0-9]{64}$/i', $plainToken)) {
            return null;
        }

        $hash = hash('sha256', $plainToken);
        $stmt = $db->prepare("
            SELECT t.token_id, t.user_id, t.expires_at, t.used_at, u.email, u.full_name, u.is_active
            FROM password_reset_tokens t
            JOIN users u ON u.user_id = t.user_id
            WHERE t.token_hash = :hash
            LIMIT 1
        ");
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        if ($row['used_at'] !== null) {
            return null;
        }
        if (strtotime((string)$row['expires_at']) < time()) {
            return null;
        }
        if (!(int)$row['is_active']) {
            return null;
        }

        return $row;
    }
}

if (!function_exists('katale_consume_password_reset_token')) {
    function katale_consume_password_reset_token(PDO $db, int $tokenId, int $userId): void
    {
        $db->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE token_id = :tid AND user_id = :uid')
            ->execute(['tid' => $tokenId, 'uid' => $userId]);
        $db->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL')
            ->execute(['uid' => $userId]);
    }
}

if (!function_exists('katale_send_registration_email')) {
    function katale_send_registration_email(string $toEmail, string $customerName): bool
    {
        $subject = 'Welcome to MasakaMart — Account Confirmation';
        $brandName = defined('APP_NAME') ? APP_NAME : 'MasakaMart';
        $storeUrl = defined('APP_URL') ? APP_URL : 'https://masakamart.ug';
        $supportEmail = notification_email_value('SMTP_FROM_EMAIL', 'support@masakamart.xo.je');

        $body = '<h2 style="margin:0 0 16px 0; color:#102A43; font-size:22px; font-weight:700;">Welcome to ' . e($brandName) . ', ' . e($customerName) . '!</h2>
            <p style="margin:0 0 14px 0; color:#102A43; font-size:15px; line-height:1.6;">Your account has been created successfully. We are delighted to have you with us.</p>
            <p style="margin:0 0 20px 0; color:#102A43; font-size:15px; line-height:1.6;">You can now order fresh groceries, quality foodstuff, and daily household essentials delivered right to your doorstep or ready for pickup in Masaka.</p>
                <div style="margin:26px 0; text-align:left;">
                  <a href="' . e($storeUrl) . '" style="display:inline-block; background-color:#FF6B00; color:#FFFFFF; padding:14px 28px; border-radius:8px; text-decoration:none; font-weight:bold; font-size:15px; letter-spacing:0.02em;" target="_blank">Start Shopping Now</a>
                </div>
                                <div style="background:#FFF1E8; border:1px solid #DDE3E7; border-radius:8px; padding:16px 20px; margin-top:24px;">
                                    <p style="margin:0 0 6px 0; font-size:13px; font-weight:bold; color:#102A43;">Quick Account Tips:</p>
                                    <ul style="margin:0; padding-left:20px; font-size:13px; color:#64748B; line-height:1.6;">
                    <li>Keep your delivery addresses saved for faster checkout.</li>
                    <li>Track your live order statuses and notifications anytime.</li>
                    <li>Questions or issues? Reach out to <a href="mailto:' . e($supportEmail) . '" style="color:#FF6B00; text-decoration:underline;">' . e($supportEmail) . '</a>.</li>
                  </ul>
                </div>';
        $html = katale_email_shell($subject, $body, 'Welcome to MasakaMart! Your account has been registered successfully.');
        $alt = "Welcome to {$brandName}, {$customerName}!\n\nYour account has been created successfully. Visit {$storeUrl} to start shopping.\n\nNeed assistance? Contact support at {$supportEmail}.\n";

        $result = sendEmail($toEmail, $subject, $html, $alt, $customerName, 'welcome-' . date('YmdHis'));
        $GLOBALS['__katale_last_mail_result__'] = $result;
        return ($result['ok'] ?? false) === true;
    }
}

if (!function_exists('katale_send_password_reset_email')) {
    function katale_send_password_reset_email(string $toEmail, string $customerName, string $resetUrl, string $expiresAt): bool
    {
        $subject = 'MasakaMart — Reset Your Password';
        $brandName = defined('APP_NAME') ? APP_NAME : 'MasakaMart';
        $expiryTimestamp = strtotime($expiresAt);
        $expiryText = $expiryTimestamp ? date('d M Y \a\t H:i', $expiryTimestamp) : $expiresAt;

        $body = '<h2 style="margin:0 0 16px 0; color:#102A43; font-size:22px; font-weight:700;">Password Reset Request</h2>
            <p style="margin:0 0 14px 0; color:#102A43; font-size:15px; line-height:1.6;">Hello ' . e($customerName) . ',</p>
            <p style="margin:0 0 14px 0; color:#102A43; font-size:15px; line-height:1.6;">We received a request to reset the password for your ' . e($brandName) . ' account. Click the button below to choose a new, secure password:</p>
                <div style="margin:26px 0; text-align:left;">
                  <a href="' . e($resetUrl) . '" style="display:inline-block; background-color:#FF6B00; color:#FFFFFF; padding:14px 28px; border-radius:8px; text-decoration:none; font-weight:bold; font-size:15px; letter-spacing:0.02em;" target="_blank">Reset My Password</a>
                </div>
                                <p style="margin:0 0 14px 0; font-size:13px; color:#64748B; line-height:1.5;">If the button above does not work, copy and paste the following secure link into your web browser:</p>
                                <p style="margin:0 0 20px 0; word-break:break-all; font-size:12px; color:#FF6B00; background:#FFF1E8; padding:10px 14px; border-radius:6px; border:1px solid #DDE3E7;">' . e($resetUrl) . '</p>
                                <div style="background:#FFF1E8; border-left:4px solid #F59E0B; padding:14px 16px; border-radius:4px; margin-top:20px;">
                                    <p style="margin:0 0 4px 0; font-size:13px; font-weight:bold; color:#102A43;">Important Security Notice:</p>
                                    <p style="margin:0; font-size:13px; color:#102A43; line-height:1.5;">
                    This password reset link will expire on <strong>' . e($expiryText) . '</strong> and can only be used once. If you did not request a password reset, please ignore this email; your account remains secure and your password will not be changed.
                  </p>
                </div>';

        $html = katale_email_shell($subject, $body, 'MasakaMart Password Reset Request: Follow this link to reset your account password.');
        $alt = "Hello {$customerName},\n\nA password reset request was received for your {$brandName} account.\n\nReset your password using the link below:\n{$resetUrl}\n\nThis link expires on {$expiryText} and can only be used once.\n\nIf you did not request this change, you can safely ignore this email.\n";

        $result = sendEmail($toEmail, $subject, $html, $alt, $customerName, 'pwd-reset-' . date('YmdHis'));
        $GLOBALS['__katale_last_mail_result__'] = $result;
        return ($result['ok'] ?? false) === true;
    }
}
