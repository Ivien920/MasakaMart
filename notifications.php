<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$userId = (int)current_user()['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (isset($_POST['mark_read'])) {
        mark_notification_read($db, $userId, (int)$_POST['mark_read']);
        flash('success', 'Notification marked as read.');
    }
    if (isset($_POST['mark_all_read'])) {
        mark_notification_read($db, $userId);
        flash('success', 'All notifications marked as read.');
    }
    redirect(APP_URL . '/notifications.php');
}

$stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC");
$stmt->execute(['uid' => $userId]);
$notifications = $stmt->fetchAll();
$unreadCount = count_unread_notifications($db, $userId);

$pageTitle = 'Notifications — MasakaMart';
require_once __DIR__ . '/includes/header.php';
?>
<section class="container section notifications-page">
  <div class="notifications-page-header">
    <div>
      <h1 class="page-title">Notifications</h1>
      <p class="notifications-page-sub"><?= $unreadCount > 0 ? (int)$unreadCount . ' unread' : 'You are all caught up' ?></p>
    </div>
    <?php if ($unreadCount > 0): ?>
    <form method="post" class="notifications-markall">
      <?= csrf_field() ?>
      <input type="hidden" name="mark_all_read" value="1">
      <button type="submit" class="btn btn-outline">Mark all as read</button>
    </form>
    <?php endif; ?>
  </div>

  <?php if (empty($notifications)): ?>
    <div class="notif-empty">
      <span class="notif-empty-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15L6 16Z"/><path d="M10 21h4"/></svg></span>
      <h2>No notifications yet</h2>
      <p>Order updates, payment confirmations and MasakaMart announcements will appear here.</p>
      <a href="<?= APP_URL ?>/products.php" class="btn btn-sm">Start shopping</a>
    </div>
  <?php else: ?>
    <div class="notification-list notif-list">
      <?php foreach ($notifications as $notification):
        $isUnread = (int)$notification['is_read'] === 0;
        $orderNum = notification_order_number_for($db, $notification);
        $typeLabel = notification_type_label($notification['type']);
        $visual = notification_visual_for_type((string)$notification['type']);
        $nid = (int)$notification['notification_id'];
      ?>
        <article class="notif-item notif-<?= e($visual['tone']) ?> <?= $isUnread ? 'is-unread' : 'is-read' ?>">
          <span class="notif-icon" aria-hidden="true"><?= $visual['svg'] ?></span>
          <div class="notif-body">
            <div class="notif-head">
              <h3 class="notif-title"><?= e($notification['title']) ?></h3>
              <?php if ($isUnread): ?><span class="notif-dot" role="img" aria-label="Unread"></span><?php endif; ?>
            </div>
            <p class="notif-meta">
              <span><?= e($typeLabel) ?></span>
              <span aria-hidden="true">&middot;</span>
              <time datetime="<?= e(date('c', strtotime($notification['created_at']))) ?>"><?= e(date('d M Y, H:i', strtotime($notification['created_at']))) ?></time>
              <?php if ($orderNum): ?><span aria-hidden="true">&middot;</span><span>Order <?= e($orderNum) ?></span><?php endif; ?>
            </p>
            <p class="notif-message"><?= nl2br(e($notification['message'])) ?></p>
            <?php if (!empty($notification['link']) || $isUnread): ?>
            <div class="notif-actions">
              <?php if (!empty($notification['link'])): ?>
                <a href="<?= e(notification_read_url($nid)) ?>" class="btn btn-sm">View details</a>
              <?php endif; ?>
              <?php if ($isUnread): ?>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="mark_read" value="<?= $nid ?>">
                  <button type="submit" class="btn btn-sm btn-outline">Mark as read</button>
                </form>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
