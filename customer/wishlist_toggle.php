<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
    || str_contains($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Invalid method']);
        exit;
    }
    redirect(APP_URL . '/index.php');
}

if (!is_logged_in()) {
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'login_required' => true,
            'message' => 'Please log in to save items to your wishlist.',
            'login_url' => APP_URL . '/auth/login.php',
            'register_url' => APP_URL . '/auth/register.php',
        ]);
        exit;
    }
    $_SESSION['redirect_after_login'] = $_SERVER['HTTP_REFERER'] ?? (APP_URL . '/index.php');
    flash('notice', 'Please log in to manage your wishlist.');
    redirect(APP_URL . '/auth/login.php');
}

require_csrf();

$userId = (int)current_user()['user_id'];
$productId = (int)($_POST['product_id'] ?? 0);

try {
    $result = toggle_user_wishlist_product($db, $userId, $productId);
} catch (Throwable $e) {
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Could not update wishlist.']);
        exit;
    }
    flash('error', 'Could not update wishlist.');
    redirect($_SERVER['HTTP_REFERER'] ?? (APP_URL . '/index.php'));
}

if ($wantsJson) {
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'wishlisted' => $result['wishlisted'],
        'wishlist_count' => $result['wishlist_count'],
        'message' => $result['wishlisted'] ? 'Added to your wishlist.' : 'Removed from your wishlist.',
    ]);
    exit;
}

flash('success', $result['wishlisted'] ? 'Added to your wishlist.' : 'Removed from your wishlist.');
redirect($_SERVER['HTTP_REFERER'] ?? (APP_URL . '/index.php'));
