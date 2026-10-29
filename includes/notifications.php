<?php
/**
 * KATALE — Notification helpers for in-app + email messaging.
 */

require_once __DIR__ . '/mailer.php';

if (!function_exists('notification_email_value')) {
    function notification_email_value(string $key, string $default = ''): string
    {
        $constantMap = [];
        foreach (['SMTP_HOST', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_ENCRYPTION', 'SMTP_FROM_EMAIL', 'SMTP_FROM_NAME'] as $constantKey) {
            if (defined($constantKey)) {
                $constantMap[$constantKey] = constant($constantKey);
            }
        }

        if (array_key_exists($key, $constantMap) && (string)$constantMap[$key] !== '') {
            return (string)$constantMap[$key];
        }

        $value = getenv($key);
        return ($value !== false && $value !== '') ? $value : $default;
    }
}

if (!function_exists('notification_log_mail_attempt')) {
    function notification_log_mail_attempt(string $to, string $subject, string $from): void
    {
        error_log('[MAIL] To=' . $to . ' | From=' . $from . ' | Subject=' . $subject);
    }
}

if (!function_exists('notification_status_label')) {
    function notification_status_label(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
    }
}

if (!function_exists('notification_mail_configured')) {
    function notification_mail_configured(): bool
    {
        $host = trim(notification_email_value('SMTP_HOST', ''));
        $port = (int)notification_email_value('SMTP_PORT', '0');
        $username = trim(notification_email_value('SMTP_USERNAME', ''));
        $password = trim(notification_email_value('SMTP_PASSWORD', ''));
        $encryption = strtolower(trim(notification_email_value('SMTP_ENCRYPTION', '')));
        $fromEmail = trim(notification_email_value('SMTP_FROM_EMAIL', ''));
        $placeholder = static fn (string $value): bool => $value === ''
            || preg_match('/^(?:your[-_]|replace[-_]?me|placeholder|<.*>)/i', $value) === 1;

        return !$placeholder($host)
            && $port > 0
            && !$placeholder($username)
            && !$placeholder($password)
            && in_array($encryption, ['tls', 'ssl', 'smtps'], true)
            && !$placeholder($fromEmail);
    }
}

if (!function_exists('notification_icon_for_type')) {
    function notification_icon_for_type(string $type): string
    {
        $icons = [
            'order_received' => '📦',
            'order_confirmed' => '✅',
            'order_processing' => '🛒',
            'order_ready' => '📦',
            'order_out_for_delivery' => '🚚',
            'order_delivered' => '🎉',
            'order_cancelled' => '⚠️',
            'payment_received' => '💳',
            'payment_failed' => '❌',
            'payment_review' => '🕵️',
            'promotion' => '🏷️',
            'announcement' => '📣',
            'system' => '🔔',
            'admin_message' => '✉️',
        ];

        return $icons[$type] ?? '🔔';
    }
}

if (!function_exists('ensure_notification_tables')) {
    function ensure_notification_tables(PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS notifications (
              notification_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              user_id INT UNSIGNED NOT NULL,
              title VARCHAR(180) NOT NULL,
              message TEXT NOT NULL,
              type VARCHAR(60) NOT NULL DEFAULT 'system',
              order_id INT UNSIGNED NULL,
              link VARCHAR(255) NULL,
              channel ENUM('in_app','email','both') NOT NULL DEFAULT 'both',
              is_read TINYINT(1) NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              read_at DATETIME NULL,
              INDEX idx_notifications_user_new (user_id, is_read, created_at),
              INDEX idx_notifications_order (order_id),
              FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
            ) ENGINE=InnoDB
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS notification_campaigns (
              campaign_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              title VARCHAR(180) NOT NULL,
              message TEXT NOT NULL,
              link VARCHAR(255) NULL,
              target_type ENUM('all','users','groups') NOT NULL DEFAULT 'all',
              channel ENUM('in_app','email','both') NOT NULL DEFAULT 'both',
              created_by INT UNSIGNED NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_campaigns_created (created_at),
              FOREIGN KEY (created_by) REFERENCES admins(admin_id) ON DELETE SET NULL
            ) ENGINE=InnoDB
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS notification_logs (
              log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              notification_id BIGINT UNSIGNED NULL,
              user_id INT UNSIGNED NOT NULL,
              channel ENUM('in_app','email') NOT NULL,
              status ENUM('queued','smtp_accepted','delivered','failed') NOT NULL DEFAULT 'queued',
              sent_at DATETIME NULL,
              error_message TEXT NULL,
              INDEX idx_notification_logs_user (user_id, sent_at),
              FOREIGN KEY (notification_id) REFERENCES notifications(notification_id) ON DELETE CASCADE,
              FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
            ) ENGINE=InnoDB
        ");

        try {
            $db->exec("ALTER TABLE notification_logs MODIFY status ENUM('queued','smtp_accepted','delivered','failed','sent') NOT NULL DEFAULT 'queued'");
            $db->exec("UPDATE notification_logs SET status = 'smtp_accepted' WHERE channel = 'email' AND status = 'sent'");
            $db->exec("ALTER TABLE notification_logs MODIFY status ENUM('queued','smtp_accepted','delivered','failed') NOT NULL DEFAULT 'queued'");
        } catch (Throwable $e) {
            // Migration may already be applied.
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS user_notification_preferences (
              preference_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              user_id INT UNSIGNED NOT NULL,
              order_updates_email TINYINT(1) NOT NULL DEFAULT 1,
              order_updates_in_app TINYINT(1) NOT NULL DEFAULT 1,
              promotional_email TINYINT(1) NOT NULL DEFAULT 1,
              promotional_in_app TINYINT(1) NOT NULL DEFAULT 1,
              system_email TINYINT(1) NOT NULL DEFAULT 1,
              system_in_app TINYINT(1) NOT NULL DEFAULT 1,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uniq_user_pref (user_id),
              FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
            ) ENGINE=InnoDB
        ");

        $db->exec("
            INSERT INTO user_notification_preferences (user_id, order_updates_email, order_updates_in_app, promotional_email, promotional_in_app, system_email, system_in_app)
            SELECT u.user_id, 1, 1, 1, 1, 1, 1
            FROM users u
            LEFT JOIN user_notification_preferences p ON p.user_id = u.user_id
            WHERE p.preference_id IS NULL
        ");
    }
}

if (!function_exists('get_notification_preferences')) {
    function get_notification_preferences(PDO $db, int $userId): array
    {
        $stmt = $db->prepare("SELECT * FROM user_notification_preferences WHERE user_id = :uid LIMIT 1");
        $stmt->execute(['uid' => $userId]);
        $prefs = $stmt->fetch();
        if (!$prefs) {
            $db->prepare("INSERT INTO user_notification_preferences (user_id) VALUES (:uid)")->execute(['uid' => $userId]);
            $stmt = $db->prepare("SELECT * FROM user_notification_preferences WHERE user_id = :uid LIMIT 1");
            $stmt->execute(['uid' => $userId]);
            $prefs = $stmt->fetch();
        }
        return $prefs ?: [];
    }
}

if (!function_exists('user_allows_notification')) {
    function user_allows_notification(PDO $db, int $userId, string $type, string $channel): bool
    {
        $prefs = get_notification_preferences($db, $userId);
        if (empty($prefs)) {
            return true;
        }

        $orderTypes = ['order_received', 'order_confirmed', 'order_processing', 'order_ready', 'order_out_for_delivery', 'order_delivered', 'order_cancelled'];
        $promoTypes = ['promotion', 'announcement'];
        $systemTypes = ['system', 'admin_message'];

        if (in_array($type, $orderTypes, true)) {
            return $channel === 'email' ? (int)($prefs['order_updates_email'] ?? 1) === 1 : (int)($prefs['order_updates_in_app'] ?? 1) === 1;
        }

        if (in_array($type, $promoTypes, true)) {
            return $channel === 'email' ? (int)($prefs['promotional_email'] ?? 1) === 1 : (int)($prefs['promotional_in_app'] ?? 1) === 1;
        }

        if (in_array($type, $systemTypes, true)) {
            return $channel === 'email' ? (int)($prefs['system_email'] ?? 1) === 1 : (int)($prefs['system_in_app'] ?? 1) === 1;
        }

        return true;
    }
}

if (!function_exists('create_in_app_notification')) {
    function create_in_app_notification(PDO $db, int $userId, string $title, string $message, string $type = 'system', ?int $orderId = null, ?string $link = null, string $channel = 'in_app'): int
    {
        $stmt = $db->prepare("
            INSERT INTO notifications (user_id, title, message, type, order_id, link, channel, is_read)
            VALUES (:uid, :title, :message, :type, :order_id, :link, :channel, 0)
        ");
        $stmt->execute([
            'uid' => $userId,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'order_id' => $orderId,
            'link' => $link,
            'channel' => $channel,
        ]);

        $notificationId = (int)$db->lastInsertId();

        // Device push is best-effort and fully independent of everything
        // else here: it can never fail the in-app notification that was
        // already committed above, and one dead subscription can never
        // block delivery to the user's other devices.
        katale_send_web_push($db, $userId, $title, $message, $link);

        return $notificationId;
    }
}

if (!function_exists('katale_send_web_push')) {
    function katale_send_web_push(PDO $db, int $userId, string $title, string $message, ?string $link = null): void
    {
        if ($userId <= 0 || !defined('VAPID_PUBLIC_KEY') || VAPID_PUBLIC_KEY === '') {
            return;
        }

        try {
            require_once __DIR__ . '/WebPushService.php';
            if (!WebPushService::configured()) {
                return;
            }

            $stmt = $db->prepare("SELECT push_subscription_id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = :uid");
            $stmt->execute(['uid' => $userId]);
            $subscriptions = $stmt->fetchAll();

            foreach ($subscriptions as $sub) {
                $result = WebPushService::send($sub, 'MasakaMart — ' . $title, $message, $link);
                if (!$result['ok'] && $result['expired']) {
                    // The push service itself says this subscription is gone
                    // (device unsubscribed / uninstalled) — clean it up.
                    $db->prepare("DELETE FROM push_subscriptions WHERE push_subscription_id = :id")
                        ->execute(['id' => $sub['push_subscription_id']]);
                } elseif (!$result['ok']) {
                    error_log('[webpush] delivery failed for subscription ' . $sub['push_subscription_id'] . ': ' . ($result['error'] ?? 'unknown'));
                }
            }
        } catch (Throwable $e) {
            // Never let a push failure affect the caller — the in-app
            // notification and payment/order state are already committed.
            error_log('[webpush] unexpected error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('log_notification_delivery')) {
    function log_notification_delivery(PDO $db, int $notificationId, int $userId, string $channel, string $status, ?string $errorMessage = null): void
    {
        $stmt = $db->prepare("
            INSERT INTO notification_logs (notification_id, user_id, channel, status, sent_at, error_message)
            VALUES (:notif, :uid, :channel, :status, NOW(), :error)
        ");
        $stmt->execute([
            'notif' => $notificationId,
            'uid' => $userId,
            'channel' => $channel,
            'status' => $status,
            'error' => $errorMessage,
        ]);
    }
}

if (!function_exists('build_order_status_message')) {
    function build_order_status_message(array $order, string $status): array
    {
        $orderNumber = $order['order_number'] ?? 'KT-00000';
        $link = APP_URL . '/orders/order_detail.php?order=' . urlencode($orderNumber);
        $isPickup = ($order['delivery_method'] ?? 'delivery') === 'pickup';

        // Fulfillment context appended to the plain status line — kept out
        // of the $title/base $message above so every existing notification
        // type/title stays exactly as before, just with one more sentence.
        $fulfillmentLine = '';
        if ($isPickup) {
            $pickupName = trim((string)($order['pickup_location_name'] ?? ''));
            if ($pickupName !== '') {
                $fulfillmentLine = " Pickup at {$pickupName}.";
            }
        } elseif (!empty($order['delivery_distance_km'])) {
            $fulfillmentLine = ' Delivery fee: ' . money((float)$order['delivery_fee']) . '.';
        }

        $map = [
            'received' => [
                'title' => 'Order received',
                'message' => "Your order #{$orderNumber} has been successfully placed." . $fulfillmentLine,
                'type' => 'order_received',
            ],
            'processing' => [
                'title' => 'Order being processed',
                'message' => "Your order #{$orderNumber} is now being prepared.",
                'type' => 'order_processing',
            ],
            'preparing' => [
                'title' => 'Order preparing',
                'message' => "Your order #{$orderNumber} is being prepared for dispatch.",
                'type' => 'order_processing',
            ],
            'ready' => $isPickup ? [
                'title' => 'Ready for pickup',
                'message' => "Your order #{$orderNumber} is ready for pickup." . $fulfillmentLine,
                'type' => 'order_ready',
            ] : [
                'title' => 'Ready for delivery',
                'message' => "Your order #{$orderNumber} is ready for delivery.",
                'type' => 'order_ready',
            ],
            'out_for_delivery' => [
                'title' => 'Out for delivery',
                'message' => "Your order #{$orderNumber} is out for delivery.",
                'type' => 'order_out_for_delivery',
            ],
            'delivered' => $isPickup ? [
                'title' => 'Order picked up',
                'message' => "Your order #{$orderNumber} has been picked up. Thank you for shopping with MasakaMart.",
                'type' => 'order_delivered',
            ] : [
                'title' => 'Order delivered',
                'message' => "Your order #{$orderNumber} has been delivered. Thank you for shopping with MasakaMart.",
                'type' => 'order_delivered',
            ],
            'cancelled' => [
                'title' => 'Order cancelled',
                'message' => "Your order #{$orderNumber} has been cancelled.",
                'type' => 'order_cancelled',
            ],
        ];

        $item = $map[$status] ?? [
            'title' => 'Order update',
            'message' => "Your order #{$orderNumber} has been updated.",
            'type' => 'system',
        ];

        return ['title' => $item['title'], 'message' => $item['message'], 'type' => $item['type'], 'link' => $link];
    }
}

if (!function_exists('katale_order_fulfillment_email_block')) {
    /** Renders the Fulfillment/Delivery/Pickup summary block for order emails. */
    function katale_order_fulfillment_email_block(array $order): string
    {
        $row = static function (string $label, string $value): string {
            return '<p style="margin:4px 0 0; color:#102A43;"><strong>' . e($label) . ':</strong> ' . e($value) . '</p>';
        };

        if (($order['delivery_method'] ?? '') === 'pickup') {
            $html = $row('Fulfillment', 'Pickup');
            if (!empty($order['pickup_location_name'])) {
                $html .= $row('Pickup Location', (string)$order['pickup_location_name']);
            }
            if (!empty($order['pickup_location_address'])) {
                $html .= $row('Pickup Address', (string)$order['pickup_location_address']);
            }
            if (!empty($order['pickup_instructions'])) {
                $html .= $row('Instructions', (string)$order['pickup_instructions']);
            }
            $html .= $row('Pickup Fee', money(0.0));
            return $html;
        }

        $html = $row('Fulfillment', 'Delivery');
        $displayLocation = katale_order_display_location($order);
        if ($displayLocation !== '') {
            $html .= $row('Delivery Location', $displayLocation);
        }
        $emailLandmark = trim((string)($order['delivery_landmark'] ?? ''));
        if ($emailLandmark !== '') {
            $html .= $row('Landmark', $emailLandmark);
        }
        if (isset($order['delivery_distance_km']) && $order['delivery_distance_km'] !== null) {
            $html .= $row('Distance', number_format((float)$order['delivery_distance_km'], 1) . ' km');
        }
        $html .= $row('Delivery Fee', money((float)($order['delivery_fee'] ?? 0)));
        return $html;
    }
}

if (!function_exists('send_admin_new_order_email')) {
    /**
     * Sends an admin alert whenever a new customer order is placed.
     */
    function send_admin_new_order_email(PDO $db, array $order): bool
    {
        $adminEmail = trim(notification_email_value('ADMIN_EMAIL', ''));
        if ($adminEmail === '') {
            $adminStmt = $db->query("SELECT email FROM admins WHERE is_active = 1 ORDER BY (role = 'super_admin') DESC, admin_id ASC LIMIT 1");
            $adminRow = $adminStmt ? $adminStmt->fetch() : null;
            $adminEmail = trim((string)($adminRow['email'] ?? ''));
        }

        if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $orderNumber = $order['order_number'] ?? 'KT-00000';
        $customerName = trim((string)($order['customer_name'] ?? 'Customer'));
        $customerPhone = trim((string)($order['customer_phone'] ?? ''));
        $customerEmail = trim((string)($order['customer_email'] ?? ''));
        $grandTotal = money((float)($order['grand_total'] ?? 0));
        $adminUrl = APP_URL . '/admin/order_detail.php?id=' . (int)($order['order_id'] ?? 0);

        $subject = "MasakaMart Admin — New Order Placed (#{$orderNumber})";
        $body = '<h2 style="margin:0 0 16px 0; color:#102A43; font-size:20px; font-weight:700;">New Customer Order Placed</h2>
            <p style="margin:0 0 14px 0; color:#102A43; font-size:15px; line-height:1.6;">A new order <strong>#' . e($orderNumber) . '</strong> has been submitted on MasakaMart.</p>
            <div style="background:#FFF1E8; border:1px solid #DDE3E7; border-radius:8px; padding:18px 20px; margin:18px 0; font-size:14px; line-height:1.6;">
              <p style="margin:0 0 6px 0; color:#102A43;"><strong>Customer Name:</strong> ' . e($customerName) . '</p>
              <p style="margin:0 0 6px 0; color:#102A43;"><strong>Phone:</strong> ' . e($customerPhone) . '</p>
              <p style="margin:0 0 6px 0; color:#102A43;"><strong>Email:</strong> ' . e($customerEmail) . '</p>
              <p style="margin:0 0 6px 0; color:#102A43;"><strong>Total Amount:</strong> <span style="font-weight:bold; color:#FF6B00; font-size:16px;">' . e($grandTotal) . '</span></p>
              <p style="margin:0; color:#102A43;"><strong>Payment Status:</strong> ' . e(ucwords(str_replace('_', ' ', (string)($order['payment_status'] ?? 'pending')))) . '</p>
                </div>
                <div style="margin:16px 0;">
                  ' . katale_order_fulfillment_email_block($order) . '
                </div>
                <div style="margin:26px 0;">
                  <a href="' . e($adminUrl) . '" style="display:inline-block; background-color:#FF6B00; color:#FFFFFF; padding:12px 24px; border-radius:8px; text-decoration:none; font-weight:bold; font-size:14px;" target="_blank">Open Order in Admin</a>
                </div>';

        $html = katale_email_shell($subject, $body, "New order #{$orderNumber} placed for {$grandTotal} by {$customerName}");
        $alt = "MasakaMart Admin — New Order Placed\n\nOrder: #{$orderNumber}\nCustomer: {$customerName} ({$customerPhone}, {$customerEmail})\nTotal: {$grandTotal}\nView in admin: {$adminUrl}\n";

        $res = sendEmail($adminEmail, $subject, $html, $alt, 'MasakaMart Admin', 'admin-new-order-' . $orderNumber);
        return ($res['ok'] ?? false) === true;
    }
}

if (!function_exists('send_order_notification_email')) {
    function send_order_notification_email(PDO $db, array $order, string $status): bool
    {
        $customerName = trim((string)($order['customer_name'] ?? 'Customer'));
        $customerEmail = trim((string)($order['customer_email'] ?? ''));
        if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $userId = (int)($order['user_id'] ?? 0);
        $prefs = $userId ? get_notification_preferences($db, $userId) : [];
        if ($userId && !empty($prefs) && !((int)($prefs['order_updates_email'] ?? 1) === 1)) {
            return false;
        }

        $orderNumber = (string)($order['order_number'] ?? 'KT-00000');
        $isPickup = ($order['delivery_method'] ?? 'delivery') === 'pickup';

        $itemsStmt = $db->prepare("SELECT * FROM order_items WHERE order_id = :oid");
        $itemsStmt->execute(['oid' => $order['order_id']]);
        $items = $itemsStmt->fetchAll();

        $itemsHtml = '';
        $itemsText = '';
        foreach ($items as $item) {
            $pName = (string)$item['product_name'];
            $qty = (string)$item['quantity'];
            $unit = trim((string)($item['unit'] ?? ''));
            $qtyDisplay = $unit !== '' ? "{$qty} {$unit}" : $qty;
            $lineTotal = money((float)$item['line_total']);

            $itemsHtml .= '<tr>
              <td style="padding:10px 8px; border-bottom:1px solid #DDE3E7; color:#102A43;">' . e($pName) . '</td>
              <td style="padding:10px 8px; border-bottom:1px solid #DDE3E7; text-align:center; color:#64748B;">' . e($qtyDisplay) . '</td>
              <td style="padding:10px 8px; border-bottom:1px solid #DDE3E7; text-align:right; font-weight:600; color:#102A43;">' . e($lineTotal) . '</td>
            </tr>';
            $itemsText .= "• {$pName} x {$qtyDisplay} — {$lineTotal}\n";
        }

        $subtotal = money((float)($order['items_subtotal'] ?? 0));
        $deliveryFee = money((float)($order['delivery_fee'] ?? 0));
        $discountTotal = (float)($order['discount_total'] ?? 0);
        $grandTotal = money((float)($order['grand_total'] ?? 0));
        $payMethod = ucwords(str_replace('_', ' ', (string)($order['payment_method'] ?? 'cash_on_delivery')));
        $payStatus = ucwords(str_replace('_', ' ', (string)($order['payment_status'] ?? 'pending')));

        $financialRows = '<tr><td colspan="2" style="padding:8px 8px; text-align:right; color:#64748B;">Subtotal:</td><td style="padding:8px 8px; text-align:right; color:#102A43;">' . e($subtotal) . '</td></tr>';
        $financialRows .= '<tr><td colspan="2" style="padding:8px 8px; text-align:right; color:#64748B;">Fulfillment (' . ($isPickup ? 'Pickup' : 'Delivery') . '):</td><td style="padding:8px 8px; text-align:right; color:#102A43;">' . e($deliveryFee) . '</td></tr>';
        if ($discountTotal > 0) {
            $financialRows .= '<tr><td colspan="2" style="padding:8px 8px; text-align:right; color:#D92D20;">Discount:</td><td style="padding:8px 8px; text-align:right; color:#D92D20;">-' . e(money($discountTotal)) . '</td></tr>';
        }
        $financialRows .= '<tr style="border-top:2px solid #FF6B00;"><td colspan="2" style="padding:12px 8px; text-align:right; font-weight:bold; font-size:16px; color:#102A43;">Total Amount:</td><td style="padding:12px 8px; text-align:right; font-weight:bold; font-size:16px; color:#FF6B00;">' . e($grandTotal) . '</td></tr>';

        switch ($status) {
            case 'received':
                $subject = "MasakaMart — Order Confirmation #{$orderNumber}";
                $intro = "<p style=\"margin:0 0 14px 0;\">Hello " . e($customerName) . ",</p><p style=\"margin:0 0 14px 0;\">Thank you for your order! We have successfully received your order <strong>#{$orderNumber}</strong> and it is now being reviewed by our team.</p>";
                break;
            case 'confirmed':
                $subject = "MasakaMart — Order #{$orderNumber} Confirmed";
                $intro = "<p style=\"margin:0 0 14px 0;\">Hello " . e($customerName) . ",</p><p style=\"margin:0 0 14px 0;\">Great news! Your order <strong>#{$orderNumber}</strong> has been confirmed and scheduled for fulfillment.</p>";
                break;
            case 'processing':
            case 'preparing':
                $subject = "MasakaMart — Order #{$orderNumber} Is Being Prepared";
                $intro = "<p style=\"margin:0 0 14px 0;\">Hello " . e($customerName) . ",</p><p style=\"margin:0 0 14px 0;\">Your order <strong>#{$orderNumber}</strong> is currently being freshly picked, packed, and prepared by our store team.</p>";
                break;
            case 'ready':
                $subject = "MasakaMart — Order #{$orderNumber} Is Ready" . ($isPickup ? ' for Pickup' : ' for Delivery');
                $intro = $isPickup
                    ? "<p style=\"margin:0 0 14px 0;\">Hello " . e($customerName) . ",</p><p style=\"margin:0 0 14px 0;\">Your order <strong>#{$orderNumber}</strong> is packaged and ready for pickup at our Masaka location.</p>"
                    : "<p style=\"margin:0 0 14px 0;\">Hello " . e($customerName) . ",</p><p style=\"margin:0 0 14px 0;\">Your order <strong>#{$orderNumber}</strong> has been prepared and is ready for dispatch.</p>";
                break;
            case 'out_for_delivery':
                $subject = "MasakaMart — Order #{$orderNumber} Out for Delivery";
                $intro = "<p style=\"margin:0 0 14px 0;\">Hello " . e($customerName) . ",</p><p style=\"margin:0 0 14px 0;\">Exciting news! Your order <strong>#{$orderNumber}</strong> is on the way. Our delivery team is heading to your delivery location.</p>";
                break;
            case 'delivered':
                $subject = "MasakaMart — Order #{$orderNumber} " . ($isPickup ? 'Picked Up' : 'Delivered');
                $intro = "<p style=\"margin:0 0 14px 0;\">Hello " . e($customerName) . ",</p><p style=\"margin:0 0 14px 0;\">Your order <strong>#{$orderNumber}</strong> has been " . ($isPickup ? 'picked up' : 'delivered') . ". Thank you for shopping with MasakaMart! We hope to serve you again soon.</p>";
                break;
            case 'cancelled':
                $subject = "MasakaMart — Order #{$orderNumber} Cancelled";
                $intro = "<p style=\"margin:0 0 14px 0;\">Hello " . e($customerName) . ",</p><p style=\"margin:0 0 14px 0;\">Your order <strong>#{$orderNumber}</strong> has been cancelled. If you have questions or believe this was done in error, please contact our support team.</p>";
                break;
            default:
                $subject = "MasakaMart — Order #{$orderNumber} Update";
                $intro = "<p style=\"margin:0 0 14px 0;\">Hello " . e($customerName) . ",</p><p style=\"margin:0 0 14px 0;\">There is an update on your order <strong>#{$orderNumber}</strong>.</p>";
                break;
        }

        $ctaLink = APP_URL . '/orders/order_detail.php?order=' . urlencode($orderNumber);
        $statusLabel = notification_status_label($status);

        $bodyHtml = '<h2 style="margin:0 0 14px 0; color:#102A43; font-size:22px; font-weight:700;">' . e($subject) . '</h2>
            <div style="font-size:15px; color:#102A43; line-height:1.6;">
                  ' . $intro . '
                </div>
                                <div style="background:#FFF1E8; border:1px solid #DDE3E7; border-radius:8px; padding:18px 20px; margin:20px 0; font-size:14px; line-height:1.6;">
                                    <p style="margin:0 0 6px 0; color:#102A43;"><strong>Order Number:</strong> ' . e($orderNumber) . '</p>
                                    <p style="margin:0 0 6px 0; color:#102A43;"><strong>Current Status:</strong> <span style="display:inline-block; background:#E7F7EE; color:#1B9A59; font-weight:700; padding:2px 10px; border-radius:12px; font-size:12px;">' . e($statusLabel) . '</span></p>
                                    <p style="margin:0 0 6px 0; color:#102A43;"><strong>Payment:</strong> ' . e($payMethod) . ' (' . e($payStatus) . ')</p>
                  ' . katale_order_fulfillment_email_block($order) . '
                </div>

                <h3 style="margin:24px 0 10px 0; font-size:16px; color:#102A43; font-weight:700;">Items in Your Order</h3>
                <table style="width:100%; border-collapse:collapse; margin-top:8px; font-size:14px;">
                  <thead>
                                        <tr style="background:#EEF1F3; text-align:left;">
                                            <th style="padding:10px 8px; color:#64748B; font-weight:600;">Item</th>
                                            <th style="padding:10px 8px; color:#64748B; font-weight:600; text-align:center;">Qty</th>
                                            <th style="padding:10px 8px; color:#64748B; font-weight:600; text-align:right;">Total</th>
                    </tr>
                  </thead>
                  <tbody>' . $itemsHtml . '</tbody>
                  <tfoot>' . $financialRows . '</tfoot>
                </table>

                <div style="margin:30px 0 10px 0; text-align:left;">
                  <a href="' . e($ctaLink) . '" style="display:inline-block; background-color:#FF6B00; color:#FFFFFF; padding:14px 28px; border-radius:8px; text-decoration:none; font-weight:bold; font-size:14px; letter-spacing:0.02em;" target="_blank">View Live Order Details</a>
                </div>';

        $html = katale_email_shell($subject, $bodyHtml, "Order #{$orderNumber}: Status is {$statusLabel}. Total {$grandTotal}.");
        $alt = "{$subject}\n\nHello {$customerName},\n\nOrder #{$orderNumber} status: {$statusLabel}\n\nItems:\n{$itemsText}\nSubtotal: {$subtotal}\nFulfillment: {$deliveryFee}\nTotal: {$grandTotal}\nPayment: {$payMethod} ({$payStatus})\n\nView details: {$ctaLink}\n";

        $traceId = 'order-' . $orderNumber . '-' . $status . '-' . date('YmdHis');
        $result = sendEmail($customerEmail, $subject, $html, $alt, $customerName, $traceId);
        $GLOBALS['__katale_last_mail_result__'] = $result;

        return ($result['ok'] ?? false) === true;
    }
}

if (!function_exists('send_admin_broadcast')) {
    function send_admin_broadcast(PDO $db, int $adminId, array $recipientIds, string $title, string $message, string $channel, ?string $link = null): array
    {
        $campaignStmt = $db->prepare("
            INSERT INTO notification_campaigns (title, message, link, target_type, channel, created_by)
            VALUES (:title, :message, :link, :target, :channel, :admin)
        ");
        $campaignStmt->execute([
            'title' => $title,
            'message' => $message,
            'link' => $link,
            'target' => count($recipientIds) > 0 ? 'users' : 'all',
            'channel' => $channel,
            'admin' => $adminId,
        ]);
        $campaignId = (int)$db->lastInsertId();

        $sent = 0;
        $emailSentCount = 0;
        $emailFailedCount = 0;
        $inAppSent = 0;
        $users = [];

        if (count($recipientIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($recipientIds), '?'));
            $userStmt = $db->prepare("SELECT user_id, full_name, email FROM users WHERE user_id IN ($placeholders)");
            $userStmt->execute($recipientIds);
            $users = $userStmt->fetchAll();
        } else {
            $userStmt = $db->query("SELECT user_id, full_name, email FROM users WHERE is_active = 1");
            $users = $userStmt->fetchAll();
        }

        foreach ($users as $user) {
            $notificationId = create_in_app_notification($db, (int)$user['user_id'], $title, $message, 'admin_message', null, $link, $channel === 'email' ? 'in_app' : $channel);

            if ($channel === 'email' || $channel === 'both') {
                $singleEmailOk = false;
                if (filter_var($user['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                    $singleEmailOk = send_email_notification($db, (int)$user['user_id'], $user['full_name'], $title, $message, $link, $notificationId, 'admin_message');
                }
                $mailMeta = $GLOBALS['__katale_last_mail_result__'] ?? null;
                if (!$singleEmailOk) {
                    $emailFailedCount++;
                    $err = is_array($mailMeta) ? ($mailMeta['error'] ?? 'Email dispatch failed') : 'Email dispatch failed';
                    log_notification_delivery($db, $notificationId, (int)$user['user_id'], 'email', 'failed', $err);
                } else {
                    $emailSentCount++;
                    $note = katale_format_mail_log_message($mailMeta);
                    log_notification_delivery($db, $notificationId, (int)$user['user_id'], 'email', 'smtp_accepted', $note);
                }
            }

            if ($channel === 'in_app' || $channel === 'both') {
                $inAppSent++;
                log_notification_delivery($db, $notificationId, (int)$user['user_id'], 'in_app', 'delivered');
            }
        }

        $sent = $channel === 'email' ? $emailSentCount : $inAppSent;

        try {
            $db->prepare("UPDATE notification_campaigns SET sent_count = :s, failed_count = :f WHERE campaign_id = :cid")
                ->execute(['s' => $sent, 'f' => $emailFailedCount, 'cid' => $campaignId]);
        } catch (Throwable $e) {
            // Optional columns in schema
        }

        return [
            'campaign_id' => $campaignId,
            'sent' => $sent,
            'failed' => $emailFailedCount,
            'email_sent' => $emailSentCount,
            'email_failed' => $emailFailedCount,
            'in_app_sent' => $inAppSent,
        ];
    }
}

if (!function_exists('send_email_notification')) {
    function send_email_notification(PDO $db, int $userId, string $customerName, string $title, string $message, ?string $link, int $notificationId, string $type = 'system'): bool
    {
        $userStmt = $db->prepare("SELECT email FROM users WHERE user_id = :uid LIMIT 1");
        $userStmt->execute(['uid' => $userId]);
        $user = $userStmt->fetch();
        $to = $user['email'] ?? '';
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $brand = defined('APP_NAME') ? APP_NAME : 'MasakaMart';
        $subject = $brand . ' — ' . $title;
        $renderedLink = $link ?: APP_URL;
        $messageHtml = '<p style="margin:0 0 14px 0; color:#102A43; font-size:15px; line-height:1.6;">' . nl2br(e($message)) . '</p>';
        if ($link) {
            $messageHtml .= '<div style="margin:24px 0;"><a href="' . e($renderedLink) . '" style="display:inline-block; background-color:#FF6B00; color:#FFFFFF; padding:12px 24px; border-radius:8px; text-decoration:none; font-weight:bold; font-size:14px;" target="_blank">View Details</a></div>';
        }

        $bodyHtml = '<h2 style="margin:0 0 14px 0; color:#102A43; font-size:22px; font-weight:700;">' . e($title) . '</h2>
            <p style="margin:0 0 14px 0; color:#102A43; font-size:15px;">Hello ' . e($customerName) . ',</p>
                ' . $messageHtml;
        $html = katale_email_shell($subject, $bodyHtml, $title);
        $alt = "{$title}\n\nHello {$customerName},\n\n{$message}\n\n" . ($link ? "Link: {$renderedLink}\n" : "");

        $result = sendEmail($to, $subject, $html, $alt, $customerName, 'notif-' . $notificationId);
        $GLOBALS['__katale_last_mail_result__'] = $result;

        return ($result['ok'] ?? false) === true;
    }
}

if (!function_exists('mark_notification_read')) {
    function mark_notification_read(PDO $db, int $userId, ?int $notificationId = null): void
    {
        if ($notificationId !== null) {
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE notification_id = :id AND user_id = :uid");
            $stmt->execute(['id' => $notificationId, 'uid' => $userId]);
            return;
        }

        $stmt = $db->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = :uid AND is_read = 0");
        $stmt->execute(['uid' => $userId]);
    }
}

if (!function_exists('fetch_recent_notifications')) {
    function fetch_recent_notifications(PDO $db, int $userId, int $limit = 8): array
    {
        $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT :limit");
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

if (!function_exists('count_unread_notifications')) {
    function count_unread_notifications(PDO $db, int $userId): int
    {
        $stmt = $db->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id = :uid AND is_read = 0");
        $stmt->execute(['uid' => $userId]);
        return (int)($stmt->fetch()['c'] ?? 0);
    }
}

if (!function_exists('handle_order_status_change')) {
    function handle_order_status_change(PDO $db, int $orderId, string $newStatus): void
    {
        $orderStmt = $db->prepare("SELECT * FROM orders WHERE order_id = :oid LIMIT 1");
        $orderStmt->execute(['oid' => $orderId]);
        $order = $orderStmt->fetch();
        if (!$order) {
            return;
        }

        $messageData = build_order_status_message($order, $newStatus);
        $userId = (int)($order['user_id'] ?? 0);

        // 1. In-app notification
        if ($userId > 0 && user_allows_notification($db, $userId, $messageData['type'], 'in_app')) {
            $notificationId = create_in_app_notification(
                $db,
                $userId,
                $messageData['title'],
                $messageData['message'],
                $messageData['type'],
                (int)$orderId,
                $messageData['link'],
                'in_app'
            );
            log_notification_delivery($db, $notificationId, $userId, 'in_app', 'delivered');
        }

        // 2. Customer Email notification for all order lifecycle events
        $lifecycleEmailStatuses = ['received', 'confirmed', 'processing', 'preparing', 'ready', 'out_for_delivery', 'delivered', 'cancelled'];
        if (in_array($newStatus, $lifecycleEmailStatuses, true)) {
            $allowEmail = ($userId <= 0) || user_allows_notification($db, $userId, $messageData['type'], 'email');
            if ($allowEmail && !empty($order['customer_email'])) {
                $sent = send_order_notification_email($db, $order, $newStatus);
                $mailMeta = $GLOBALS['__katale_last_mail_result__'] ?? null;

                if ($userId > 0) {
                    $lastStmt = $db->prepare("SELECT notification_id FROM notifications WHERE user_id = :uid AND order_id = :oid ORDER BY notification_id DESC LIMIT 1");
                    $lastStmt->execute(['uid' => $userId, 'oid' => $orderId]);
                    $last = $lastStmt->fetch();
                    $notificationId = $last ? (int)$last['notification_id'] : 0;
                    if ($notificationId > 0) {
                        if ($sent) {
                            log_notification_delivery($db, $notificationId, $userId, 'email', 'smtp_accepted', katale_format_mail_log_message($mailMeta));
                        } else {
                            $err = is_array($mailMeta) ? ($mailMeta['error'] ?? 'SMTP send failed') : 'SMTP send failed';
                            log_notification_delivery($db, $notificationId, $userId, 'email', 'failed', $err);
                        }
                    }
                }
            }
        }

        // 3. Admin Email notification when an order is first received
        if ($newStatus === 'received') {
            try {
                send_admin_new_order_email($db, $order);
            } catch (Throwable $e) {
                error_log('[ADMIN_EMAIL] Failed to send new order alert: ' . $e->getMessage());
            }
        }
    }
}

if (!function_exists('notification_visual_for_type')) {
    /**
     * Small consistent line-icon + tone for a notification type (used by notifications.php).
     * Returns ['tone' => success|danger|warning|info|neutral, 'svg' => inline <svg> markup].
     */
    function notification_visual_for_type(string $type): array
    {
        $paths = [
            'box'    => '<path d="M21 8 12 3 3 8v8l9 5 9-5V8Z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
            'check'  => '<circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.8 2.8L16 9.5"/>',
            'truck'  => '<path d="M3 6h11v10H3zM14 9h4l3 3v4h-7"/><circle cx="7" cy="17.5" r="1.7"/><circle cx="17" cy="17.5" r="1.7"/>',
            'card'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h3"/>',
            'alert'  => '<path d="M12 4 2.5 20h19L12 4Z"/><path d="M12 10v4M12 17v.5"/>',
            'clock'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'tag'    => '<path d="M3 12V4h8l10 10-8 8L3 12Z"/><circle cx="7.5" cy="8.5" r="1.2"/>',
            'bell'   => '<path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15L6 16Z"/><path d="M10 21h4"/>',
            'mail'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        ];
        $map = [
            'order_received' => ['info', 'box'], 'order_confirmed' => ['success', 'check'],
            'order_processing' => ['info', 'box'], 'order_ready' => ['info', 'box'],
            'order_out_for_delivery' => ['info', 'truck'], 'order_delivered' => ['success', 'check'],
            'order_cancelled' => ['danger', 'alert'],
            'payment_received' => ['success', 'card'], 'payment_failed' => ['danger', 'alert'],
            'payment_review' => ['warning', 'clock'],
            'promotion' => ['neutral', 'tag'], 'announcement' => ['neutral', 'bell'],
            'system' => ['neutral', 'bell'], 'admin_message' => ['neutral', 'mail'],
        ];
        [$tone, $key] = $map[$type] ?? ['neutral', 'bell'];
        $svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[$key] . '</svg>';

        return ['tone' => $tone, 'svg' => $svg];
    }
}

if (!function_exists('notification_brand_label')) {
    function notification_brand_label(): string
    {
        return 'MasakaMart';
    }
}

if (!function_exists('notification_type_label')) {
    function notification_type_label(string $type): string
    {
        $labels = [
            'order_received' => 'Order Received',
            'order_confirmed' => 'Order Confirmed',
            'order_processing' => 'Order Processing',
            'order_ready' => 'Ready for Delivery',
            'order_out_for_delivery' => 'Out for Delivery',
            'order_delivered' => 'Delivered',
            'order_cancelled' => 'Order Cancelled',
            'payment_received' => 'Payment Update',
            'payment_failed' => 'Payment Update',
            'payment_review' => 'Payment Under Review',
            'promotion' => 'Promotion',
            'announcement' => 'Important Update',
            'system' => 'Important Update',
            'admin_message' => 'Important Update',
        ];

        return $labels[$type] ?? 'Notification';
    }
}

if (!function_exists('notification_order_number_for')) {
    function notification_order_number_for(PDO $db, array $notification): ?string
    {
        $orderId = (int)($notification['order_id'] ?? 0);
        if ($orderId <= 0) {
            return null;
        }
        static $cache = [];
        if (isset($cache[$orderId])) {
            return $cache[$orderId];
        }
        $stmt = $db->prepare('SELECT order_number FROM orders WHERE order_id = :oid LIMIT 1');
        $stmt->execute(['oid' => $orderId]);
        $num = $stmt->fetch()['order_number'] ?? null;
        $cache[$orderId] = $num ? (string)$num : null;

        return $cache[$orderId];
    }
}

if (!function_exists('notification_read_url')) {
    function notification_read_url(int $notificationId): string
    {
        return APP_URL . '/notifications/read.php?id=' . $notificationId;
    }
}

if (!function_exists('save_user_preferences')) {
    function save_user_preferences(PDO $db, int $userId, array $prefs): void
    {
        $stmt = $db->prepare("SELECT preference_id FROM user_notification_preferences WHERE user_id = :uid LIMIT 1");
        $stmt->execute(['uid' => $userId]);
        if ($stmt->fetch()) {
            $db->prepare("UPDATE user_notification_preferences SET
                order_updates_email = :order_email,
                order_updates_in_app = :order_in_app,
                promotional_email = :promo_email,
                promotional_in_app = :promo_in_app,
                system_email = :system_email,
                system_in_app = :system_in_app,
                updated_at = NOW()
                WHERE user_id = :uid")->execute([
                    'uid' => $userId,
                    'order_email' => isset($prefs['order_updates_email']) ? (int)$prefs['order_updates_email'] : 1,
                    'order_in_app' => isset($prefs['order_updates_in_app']) ? (int)$prefs['order_updates_in_app'] : 1,
                    'promo_email' => isset($prefs['promotional_email']) ? (int)$prefs['promotional_email'] : 1,
                    'promo_in_app' => isset($prefs['promotional_in_app']) ? (int)$prefs['promotional_in_app'] : 1,
                    'system_email' => isset($prefs['system_email']) ? (int)$prefs['system_email'] : 1,
                    'system_in_app' => isset($prefs['system_in_app']) ? (int)$prefs['system_in_app'] : 1,
            ]);
        } else {
            $db->prepare("INSERT INTO user_notification_preferences (user_id, order_updates_email, order_updates_in_app, promotional_email, promotional_in_app, system_email, system_in_app)
                VALUES (:uid, :order_email, :order_in_app, :promo_email, :promo_in_app, :system_email, :system_in_app)")->execute([
                    'uid' => $userId,
                    'order_email' => isset($prefs['order_updates_email']) ? (int)$prefs['order_updates_email'] : 1,
                    'order_in_app' => isset($prefs['order_updates_in_app']) ? (int)$prefs['order_updates_in_app'] : 1,
                    'promo_email' => isset($prefs['promotional_email']) ? (int)$prefs['promotional_email'] : 1,
                    'promo_in_app' => isset($prefs['promotional_in_app']) ? (int)$prefs['promotional_in_app'] : 1,
                    'system_email' => isset($prefs['system_email']) ? (int)$prefs['system_email'] : 1,
                    'system_in_app' => isset($prefs['system_in_app']) ? (int)$prefs['system_in_app'] : 1,
            ]);
        }
    }
}
