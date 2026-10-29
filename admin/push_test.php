<?php
/**
 * Admin-only diagnostic: send yourself one real push notification.
 *
 * HOW TO ACTUALLY TEST WEB PUSH END TO END:
 *   1. Log in as a customer in your browser, open Profile, tap
 *      "Turn on order notifications", accept the browser permission
 *      prompt. This calls notifications/push_subscribe.php and stores
 *      a row in push_subscriptions for your account.
 *   2. Log in as admin in a SEPARATE browser/tab, open this page
 *      (/admin/push_test.php), enter that customer's email, submit.
 *   3. You should see a real OS-level notification appear within a few
 *      seconds. If nothing appears, the result box below shows exactly
 *      what PesaJet... no — what WebPushService reported, so you can
 *      tell me the precise stage that failed.
 *
 * This page proves whether the crypto/delivery path genuinely works
 * against a real push service — nothing about push can be confirmed
 * without this manual step.
 */

require_once __DIR__ . '/includes/admin_bootstrap.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/WebPushService.php';

$result = null;
$lookupEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $lookupEmail = trim((string)($_POST['email'] ?? ''));

    if (!WebPushService::configured()) {
        $result = ['ok' => false, 'summary' => 'VAPID keys are not configured (VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY empty, or PHP < 8.1 so openssl_pkey_derive is missing).'];
    } else {
        $db = Database::conn();
        $stmt = $db->prepare("SELECT user_id FROM users WHERE email = :e LIMIT 1");
        $stmt->execute(['e' => $lookupEmail]);
        $user = $stmt->fetch();

        if (!$user) {
            $result = ['ok' => false, 'summary' => 'No user found with that email.'];
        } else {
            $subStmt = $db->prepare("SELECT push_subscription_id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = :uid");
            $subStmt->execute(['uid' => (int)$user['user_id']]);
            $subs = $subStmt->fetchAll();

            if (!$subs) {
                $result = ['ok' => false, 'summary' => 'That user has no push subscription yet — ask them to tap "Turn on order notifications" in Profile first.'];
            } else {
                $outcomes = [];
                foreach ($subs as $sub) {
                    $r = WebPushService::send($sub, 'MasakaMart — Test notification', 'This is a test push from the admin panel.', APP_URL . '/customer/profile.php');
                    $outcomes[] = 'Subscription #' . $sub['push_subscription_id'] . ': ' . ($r['ok'] ? 'sent (HTTP ' . $r['http_status'] . ')' : 'FAILED — ' . $r['error']);
                }
                $result = ['ok' => true, 'summary' => implode("\n", $outcomes)];
            }
        }
    }
}

require_once __DIR__ . '/includes/admin_header.php';
?>
<h1>Push Notification Test</h1>
<div class="admin-panel">
  <p>Send one real test push to a specific customer's subscribed device(s). See the comment at the top of this file for the full manual test procedure — this cannot be verified any other way.</p>
  <form method="post" class="admin-form">
    <?= csrf_field() ?>
    <label>Customer email</label>
    <input type="email" name="email" required value="<?= e($lookupEmail) ?>">
    <button type="submit" class="btn">Send test push</button>
  </form>

  <?php if ($result !== null): ?>
    <hr>
    <h2><?= $result['ok'] ? 'Attempted' : 'Could not attempt' ?></h2>
    <pre style="white-space:pre-wrap;background:var(--surface-alt);padding:12px;border-radius:8px;"><?= e($result['summary']) ?></pre>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
