<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}

$action = $_POST['action'] ?? 'add';
$productId = (int)($_POST['product_id'] ?? 0);
$quantity = (float)($_POST['quantity'] ?? 1);

if (($action === 'add' && $quantity < 1) || ($action === 'update' && $quantity < 0) ||
    (in_array($action, ['add', 'update'], true) && floor($quantity) !== $quantity)) {
    echo json_encode(['ok' => false, 'error' => 'Quantity must be a whole number.']);
    exit;
}

$stmt = $db->prepare("SELECT * FROM products WHERE product_id = :id AND status = 'active'");
$stmt->execute(['id' => $productId]);
$product = $stmt->fetch();

if (!$product) {
    echo json_encode(['ok' => false, 'error' => 'Product not found.']);
    exit;
}

if (!(int)$product['is_available'] || (float)$product['stock_quantity'] <= 0) {
    echo json_encode(['ok' => false, 'error' => 'This product is currently unavailable.']);
    exit;
}

switch ($action) {
    case 'add':
        $qtyToAdd = $quantity;
        if ($qtyToAdd > (float)$product['stock_quantity']) {
            echo json_encode(['ok' => false, 'error' => "Only {$product['stock_quantity']} {$product['unit']} available."]);
            exit;
        }
        $cart->addItem($cartId, $productId, $qtyToAdd);
        break;

    case 'update':
        if ($quantity > (float)$product['stock_quantity']) {
            echo json_encode(['ok' => false, 'error' => "Only {$product['stock_quantity']} {$product['unit']} available."]);
            exit;
        }
        $cart->setQuantity($cartId, $productId, $quantity);
        break;

    case 'remove':
        $cart->removeItem($cartId, $productId);
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
        exit;
}

$data = $cart->getItemsWithPricing($cartId);
echo json_encode([
    'ok' => true,
    'cart_count' => $cart->countItems($cartId),
    'subtotal' => $data['subtotal'],
    'subtotal_formatted' => money($data['subtotal']),
]);
