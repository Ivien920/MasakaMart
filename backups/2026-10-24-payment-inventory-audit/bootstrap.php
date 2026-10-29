<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/pricing.php';
require_once __DIR__ . '/Cart.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/wishlist.php';
require_once __DIR__ . '/password_reset.php';
require_once __DIR__ . '/PesaJetService.php';
require_once __DIR__ . '/payment_service.php';
require_once __DIR__ . '/order_totals.php';
require_once __DIR__ . '/inventory_service.php';
require_once __DIR__ . '/geocode_service.php';
require_once __DIR__ . '/delivery_service.php';
require_once __DIR__ . '/seo.php';

$db = Database::conn();
ensure_notification_tables($db);
ensure_password_reset_table($db);
ensure_geocode_tables($db);
ensure_inventory_order_columns($db);
$cart = new Cart($db);
$cartId = $cart->getOrCreateCartId();
$cartCount = $cart->countItems($cartId);
