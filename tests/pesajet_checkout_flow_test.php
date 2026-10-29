<?php
/**
 * PesaJet mobile-money checkout flow test (CLI) — exercises the REAL
 * checkout path end-to-end against a running local server:
 *
 *   1. register/login a unique test user, add a product to the cart
 *   2. invalid phone        → bounced back, NO order created
 *   3. provider/phone mismatch → bounced back, NO order created
 *   4. valid input          → exactly ONE order + ONE payment row created,
 *                             redirected to the payment status page
 *   5. payment status page  → honest state, NEVER claims success while
 *                             unconfirmed; poll endpoint returns JSON
 *   6. retry                → reuses the SAME payment row (attempt_count++),
 *                             never a second order/payment
 *   7. duplicate checkout   → cart already cleared → no second order
 *   8. no false notifications
 *
 * The PesaJet API call inside step 4 hits the REAL endpoint; the response
 * from PesaJet (e.g. 401 for a bogus test key) decides the recorded status.
 * Nothing is simulated. Use a bogus key in the TEMP test env or your real
 * test credentials — either way no assertion depends on a successful payment.
 *
 * Usage:
 *   php -S localhost:8199 -t <parent-of-katale>
 *   php tests/pesajet_checkout_flow_test.php http://localhost:8199/katale
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

require dirname(__DIR__) . '/config/config.php';

$base = rtrim($argv[1] ?? 'http://localhost:8199/katale', '/');
$GLOBALS['ck'] = [];
$GLOBALS['R'] = [];

function C(string $n, bool $ok, string $d = ''): void
{
    $GLOBALS['R'][] = '[' . ($ok ? 'PASS' : 'FAIL') . '] ' . $n . ' ' . $d;
}

function http(string $url, string $method = 'GET', ?array $body = null, int $timeout = 60): array
{
    $hdr = "Accept: text/html\r\nConnection: close\r\n";
    if ($GLOBALS['ck']) {
        $pairs = [];
        foreach ($GLOBALS['ck'] as $k => $v) { $pairs[] = $k . '=' . $v; }
        $hdr .= 'Cookie: ' . implode('; ', $pairs) . "\r\n";
    }
    $content = $body === null ? '' : http_build_query($body);
    if ($content !== '') { $hdr .= "Content-Type: application/x-www-form-urlencoded\r\n"; }
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => $hdr, 'content' => $content,
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => $timeout,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $hs = $http_response_header ?? [];
    $code = 0; $loc = '';
    foreach ($hs as $i => $h) {
        if ($i === 0 && preg_match('#HTTP/\S+\s+(\d+)#', $h, $m)) { $code = (int)$m[1]; continue; }
        $p = strpos($h, ':');
        if ($p === false) { continue; }
        $k = strtolower(trim(substr($h, 0, $p)));
        $v = trim(substr($h, $p + 1));
        if ($k === 'location') { $loc = $v; }
        if ($k === 'set-cookie') {
            $kv = explode(';', $v, 2)[0];
            if (strpos($kv, '=') !== false) {
                [$a, $b] = explode('=', $kv, 2);
                $GLOBALS['ck'][trim($a)] = trim($b);
            }
        }
    }
    return ['code' => $code, 'loc' => $loc, 'body' => (string)$resp];
}

function tkn(string $page): string
{
    return preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $page, $m) ? $m[1] : '';
}

$pdo = null;
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS, [PDO::ATTR_TIMEOUT => 10]);
} catch (Throwable $e) {
    echo 'DB unavailable: ' . $e->getMessage(), PHP_EOL;
}

$em = 'pesajettest' . substr(md5(uniqid('', true)), 0, 6) . '@example.com';
$like = "LIKE 'pesajettest%@example.com'";

function count_orders(PDO $pdo, string $like): int
{
    $st = $pdo->query("SELECT COUNT(*) FROM orders WHERE customer_email $like");
    return (int)$st->fetchColumn();
}

// ---- 1. Register, login, cart -----------------------------------------
$g = http($base . '/auth/register.php');
$r = http($base . '/auth/register.php', 'POST', [
    'full_name' => 'PesaJet Flow Test', 'email' => $em, 'phone' => '0771234567',
    'password' => 'TestPass123!', 'confirm_password' => 'TestPass123!',
    'csrf_token' => tkn($g['body']),
]);
C('register', $r['code'] === 302, 'code=' . $r['code']);
if ($pdo) {
    try {
        $pdo->prepare("INSERT INTO user_notification_preferences (user_id, order_updates_email)
                       SELECT user_id, 0 FROM users WHERE email = ?
                       ON DUPLICATE KEY UPDATE order_updates_email = 0")->execute([$em]);
    } catch (Throwable $e) { /* preference only */ }
}
$h = http($base . '/index.php');
$tk = tkn($h['body']);
$r = http($base . '/cart/cart_action.php', 'POST', ['action' => 'add', 'product_id' => 1, 'quantity' => 1, 'csrf_token' => $tk]);
C('cart-add', $r['code'] === 200 && str_starts_with(trim($r['body']), '{'), substr($r['body'], 0, 60));

$ckPage = http($base . '/checkout/checkout.php');
C('checkout-page', $ckPage['code'] === 200, 'code=' . $ckPage['code']);
$formTkn = tkn($ckPage['body']);
$ordersBefore = $pdo ? count_orders($pdo, $like) : -1;

$basePost = [
    'customer_name' => 'PesaJet Flow Test', 'customer_email' => $em,
    'delivery_method' => 'pickup', 'payment_method' => 'mobile_money',
    'declared_subtotal' => '0', 'csrf_token' => $formTkn,
];

// ---- 2. Invalid phone → bounced, no order ------------------------------
$r = http($base . '/checkout/checkout_process.php', 'POST', $basePost + [
    'payment_provider' => 'mtn', 'payment_phone' => '123',
]);
C('invalid-phone rejected', $r['code'] === 302 && $r['loc'] === $base . '/checkout/checkout.php', 'code=' . $r['code'] . ' loc=' . $r['loc']);
C('invalid-phone no order', $pdo ? count_orders($pdo, $like) === $ordersBefore : false);

// ---- 3. Provider/phone mismatch → bounced, no order --------------------
$r = http($base . '/checkout/checkout_process.php', 'POST', $basePost + [
    'payment_provider' => 'mtn', 'payment_phone' => '0751234567', // Airtel prefix
]);
C('provider-mismatch rejected', $r['code'] === 302 && $r['loc'] === $base . '/checkout/checkout.php', 'code=' . $r['code'] . ' loc=' . $r['loc']);
C('provider-mismatch no order', $pdo ? count_orders($pdo, $like) === $ordersBefore : false);

// ---- 4. Valid input → ONE order + ONE payment, redirect to status page -
$r = http($base . '/checkout/checkout_process.php', 'POST', $basePost + [
    'payment_provider' => 'mtn', 'payment_phone' => '0771234567',
], 150);
$okLoc = str_starts_with($r['loc'], $base . '/checkout/payment_status.php?order=');
C('valid checkout redirects to payment status', $r['code'] === 302 && $okLoc, 'code=' . $r['code'] . ' loc=' . $r['loc']);
$ordersAfter = $pdo ? count_orders($pdo, $like) : -1;
C('exactly one order created', $ordersAfter === $ordersBefore + 1, "before=$ordersBefore after=$ordersAfter");

$orderNumber = '';
if (preg_match('/order=([A-Za-z0-9\-]+)$/', $r['loc'], $m)) { $orderNumber = urldecode($m[1]); }

$payment = null; $orderRow = null;
if ($pdo && $orderNumber !== '') {
    $st = $pdo->prepare('SELECT * FROM orders WHERE order_number = ? AND customer_email ' . $like);
    $st->execute([$orderNumber]);
    $orderRow = $st->fetch();
    C('order exists', (bool)$orderRow, $orderNumber);
    if ($orderRow) {
        $st = $pdo->prepare('SELECT * FROM payments WHERE order_id = ? ORDER BY payment_id DESC LIMIT 1');
        $st->execute([(int)$orderRow['order_id']]);
        $payment = $st->fetch();
        C('payment row exists', (bool)$payment);
        if ($payment) {
            C('payment method=mobile_money', $payment['method'] === 'mobile_money', $payment['method']);
            C('payment provider=mtn', $payment['provider'] === 'mtn', (string)$payment['provider']);
            C('payment phone normalized +256771234567', $payment['phone_number'] === '+256771234567', (string)$payment['phone_number']);
            C('reference MM-PAY-* (MasakaMart ref)', str_starts_with((string)$payment['transaction_reference'], 'MM-PAY-'), (string)$payment['transaction_reference']);
            C('amount matches server-side order total', (float)$payment['amount'] === (float)$orderRow['grand_total'], $payment['amount'] . ' vs ' . $orderRow['grand_total']);
            C('user ownership recorded', (int)$payment['user_id'] === (int)$orderRow['user_id']);
            C('status never paid without confirmation', $payment['status'] !== 'paid', $payment['status']);
            C('order payment_status not paid', $orderRow['payment_status'] !== 'paid', $orderRow['payment_status']);
            C('one payment row only', (int)$pdo->query('SELECT COUNT(*) FROM payments WHERE order_id = ' . (int)$orderRow['order_id'])->fetchColumn() === 1);
        }
    }
}

// ---- 5. Payment status page: honest state, no false success -----------
if ($orderNumber !== '') {
    $sp = http($base . '/checkout/payment_status.php?order=' . urlencode($orderNumber));
    C('payment status page 200', $sp['code'] === 200, 'code=' . $sp['code']);
    C('never claims success while unconfirmed', strpos($sp['body'], 'Payment Successful') === false);
    $state = $payment ? (in_array($payment['status'], ['pending', 'processing'], true) && $payment['pesajet_transaction_id'] ? 'awaiting' : 'error') : 'error';
    C('page shows honest pending/failed heading',
        (strpos($sp['body'], 'Payment Pending') !== false || strpos($sp['body'], 'Payment Failed') !== false),
        'expected state=' . $state);

    // Poll endpoint (authenticated JSON) — must never report paid.
    $poll = http($base . '/checkout/payment_status_ajax.php?order=' . urlencode($orderNumber));
    $jd = json_decode($poll['body'], true);
    C('poll endpoint 200 JSON', $poll['code'] === 200 && is_array($jd), 'code=' . $poll['code'] . ' ' . substr($poll['body'], 0, 80));
    C('poll never reports paid unconfirmed', is_array($jd) && ($jd['state'] ?? '') !== 'paid', 'state=' . ($jd['state'] ?? 'n/a'));

    // ---- 6. Retry reuses the SAME payment row ----
    if ($pdo && $payment) {
        $attemptBefore = (int)$payment['attempt_count'];
        $r = http($base . '/checkout/payment_status.php', 'POST', [
            'order' => $orderNumber, 'action' => 'retry',
            'payment_provider' => 'mtn', 'payment_phone' => '0771234567',
            'csrf_token' => tkn($sp['body']),
        ], 150);
        C('retry redirects back to status page', $r['code'] === 302 && str_starts_with($r['loc'], $base . '/checkout/payment_status.php'), 'code=' . $r['code'] . ' loc=' . $r['loc']);
        $st = $pdo->prepare('SELECT * FROM payments WHERE payment_id = ?');
        $st->execute([(int)$payment['payment_id']]);
        $after = $st->fetch();
        C('retry reuses same payment row', (bool)$after, 'payment_id=' . (int)$payment['payment_id']);
        C('retry increments attempt_count', $after && (int)$after['attempt_count'] > $attemptBefore, ($after['attempt_count'] ?? '?') . " > $attemptBefore");
        C('retry creates no second order', count_orders($pdo, $like) === $ordersBefore + 1);
        C('still never paid without confirmation', $after && $after['status'] !== 'paid', $after['status'] ?? '?');

        // ---- 7. Duplicate checkout POST (cart already cleared) ----
        $r = http($base . '/checkout/checkout_process.php', 'POST', $basePost + [
            'payment_provider' => 'mtn', 'payment_phone' => '0771234567',
        ]);
        C('duplicate checkout creates no order', count_orders($pdo, $like) === $ordersBefore + 1, 'code=' . $r['code'] . ' loc=' . $r['loc']);

        // ---- 8. No false notifications ----
        $st = $pdo->prepare("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id = n.user_id WHERE u.email = ? AND n.type = 'payment_received'");
        $st->execute([$em]);
        C('no payment_received notification without confirmation', (int)$st->fetchColumn() === 0);
        $st = $pdo->prepare("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id = n.user_id WHERE u.email = ? AND n.type = 'order_received'");
        $st->execute([$em]);
        C('order confirmation notification deferred until paid', (int)$st->fetchColumn() === 0);
    }
}

// ---- Cleanup test data from the dev database ----
try {
    if (!$pdo) { throw new RuntimeException('no db'); }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec("DELETE oi FROM order_items oi JOIN orders o ON o.order_id = oi.order_id WHERE o.customer_email $like");
    $pdo->exec("DELETE pm FROM payments pm JOIN orders o ON o.order_id = pm.order_id WHERE o.customer_email $like");
    $pdo->exec("DELETE n FROM notifications n JOIN users u ON u.user_id = n.user_id WHERE u.email $like");
    $pdo->exec("DELETE nl FROM notification_logs nl JOIN users u ON u.user_id = nl.user_id WHERE u.email $like");
    $pdo->exec("DELETE p FROM user_notification_preferences p JOIN users u ON u.user_id = p.user_id WHERE u.email $like");
    $pdo->exec("DELETE FROM orders WHERE customer_email $like");
    $st = $pdo->prepare("DELETE FROM users WHERE email $like");
    $st->execute();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    C('cleanup', true, 'users=' . $st->rowCount());
} catch (Throwable $e) {
    C('cleanup', false, substr($e->getMessage(), 0, 140));
}

$f = 0;
foreach ($GLOBALS['R'] as $line) {
    echo $line, "\n";
    if (str_starts_with($line, '[FAIL]')) { $f++; }
}
echo '==== TOTAL=' . count($GLOBALS['R']) . " FAILED=$f ====\n";
exit($f ? 1 : 0);


