<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(APP_URL . '/index.php');
require_csrf();

$userId = current_user()['user_id'];
$productId = (int)($_POST['product_id'] ?? 0);
$rating = max(1, min(5, (int)($_POST['rating'] ?? 0)));
$comment = trim($_POST['comment'] ?? '');

// Must have a paid order item for this product with no existing review — prevents fake reviews.
$eligStmt = $db->prepare(
    "SELECT oi.order_item_id FROM order_items oi
     JOIN orders o ON o.order_id = oi.order_id
     LEFT JOIN reviews r ON r.order_item_id = oi.order_item_id
     WHERE o.user_id = :uid AND oi.product_id = :pid AND o.payment_status = 'paid' AND r.review_id IS NULL
     LIMIT 1"
);
$eligStmt->execute(['uid' => $userId, 'pid' => $productId]);
$eligible = $eligStmt->fetch();

if (!$eligible) {
    flash('error', 'Only customers who purchased this product can leave a review.');
} else {
    $stmt = $db->prepare(
        "INSERT INTO reviews (product_id, user_id, order_item_id, rating, comment) VALUES (:pid, :uid, :oiid, :rating, :comment)"
    );
    $stmt->execute([
        'pid' => $productId, 'uid' => $userId, 'oiid' => $eligible['order_item_id'],
        'rating' => $rating, 'comment' => $comment !== '' ? $comment : null,
    ]);
    flash('success', 'Thank you for your review!');
}

redirect($_SERVER['HTTP_REFERER'] ?? (APP_URL . '/index.php'));
