<?php
/**
 * KATALE — HTTP smoke test (CLI).
 * Verifies rendered URLs, redirects, AJAX endpoints and the auth/order flows
 * against a running server. Usage:
 *   php -S localhost:8199 -t <parent-of-katale>
 *   php tests/http_smoke_test.php http://localhost:8199/katale
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
$base = rtrim($argv[1] ?? 'http://localhost:8199/katale', '/');
$GLOBALS['ck'] = [];
$GLOBALS['R'] = [];
require dirname(__DIR__) . '/config/config.php';
$pdo = null;
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS, [PDO::ATTR_TIMEOUT => 10]);
} catch (Throwable $e) {
    echo 'DB unavailable: ' . $e->getMessage(), PHP_EOL;
}

function C($n, $c, $d = ''): void
{
    $GLOBALS['R'][] = '[' . ($c ? 'PASS' : 'FAIL') . '] ' . $n . ' ' . $d;
}

function http(string $url, string $method = 'GET', ?array $body = null, int $timeout = 30, string $extraHdr = ''): array
{
    $hdr = "Accept: text/html\r\nConnection: close\r\n" . $extraHdr;
    if ($GLOBALS['ck']) {
        $pairs = [];
        foreach ($GLOBALS['ck'] as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        $hdr .= 'Cookie: ' . implode('; ', $pairs) . "\r\n";
    }
    $content = $body === null ? '' : http_build_query($body);
    if ($content !== '') {
        $hdr .= "Content-Type: application/x-www-form-urlencoded\r\n";
    }
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => $hdr, 'content' => $content,
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => $timeout,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $hs = $http_response_header ?? [];
    $code = 0;
    $loc = '';
    foreach ($hs as $i => $h) {
        if ($i === 0 && preg_match('#HTTP/\S+\s+(\d+)#', $h, $m)) {
            $code = (int) $m[1];
            continue;
        }
        $p = strpos($h, ':');
        if ($p === false) {
            continue;
        }
        $k = strtolower(trim(substr($h, 0, $p)));
        $v = trim(substr($h, $p + 1));
        if ($k === 'location') {
            $loc = $v;
        }
        if ($k === 'set-cookie') {
            $kv = explode(';', $v, 2)[0];
            if (strpos($kv, '=') !== false) {
                [$a, $b] = explode('=', $kv, 2);
                $GLOBALS['ck'][trim($a)] = trim($b);
            }
        }
    }
    if ($code === 0) {
        // No parseable response from the stream wrapper — retry once with a
        // hand-built raw socket request so a single bad exchange cannot fail
        // the whole run.
        return http_raw($url, $method, $body, $timeout);
    }
    return ['code' => $code, 'loc' => $loc, 'body' => (string) $resp];
}

function http_raw(string $url, string $method, ?array $body, int $timeout): array
{
    $pu = parse_url($url);
    $hostHdr = $pu['host'] . (isset($pu['port']) ? ':' . $pu['port'] : '');
    $path = ($pu['path'] ?? '/') . (isset($pu['query']) ? '?' . $pu['query'] : '');
    $reqBody = ($body === null) ? '' : http_build_query($body);
    $ch = '';
    if ($GLOBALS['ck']) {
        $pairs = [];
        foreach ($GLOBALS['ck'] as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        $ch = 'Cookie: ' . implode('; ', $pairs) . "\r\n";
    }
    $req = $method . ' ' . $path . " HTTP/1.1\r\nHost: " . $hostHdr
        . "\r\nAccept: */*\r\nConnection: close\r\n"
        . ($reqBody !== '' ? "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($reqBody) . "\r\n" : '')
        . $ch . "\r\n" . $reqBody;
    $fp = @fsockopen($pu['host'], (int) ($pu['port'] ?? 80), $errno, $errstr, 10);
    if (!$fp) {
        return ['code' => 0, 'loc' => '', 'body' => 'connect error: ' . $errstr, 'rawhead' => ''];
    }
    stream_set_timeout($fp, max(5, min($timeout, 60)));
    fwrite($fp, $req);
    $raw = '';
    while (!feof($fp)) {
        $chunk = fread($fp, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $raw .= $chunk;
    }
    $meta = stream_get_meta_data($fp);
    fclose($fp);
    $parts = explode("\r\n\r\n", $raw, 2);
    $head = $parts[0] ?? '';
    $respBody = $parts[1] ?? '';
    $code = 0;
    $loc = '';
    foreach (explode("\r\n", $head) as $i => $line) {
        if ($i === 0 && preg_match('#HTTP/\S+\s+(\d+)#', $line, $m)) {
            $code = (int) $m[1];
            continue;
        }
        if (stripos($line, 'Location:') === 0) {
            $loc = trim(substr($line, 9));
        }
        if (stripos($line, 'Set-Cookie:') === 0) {
            $kv = explode(';', substr($line, 11), 2)[0];
            if (strpos($kv, '=') !== false) {
                [$a2, $b2] = explode('=', $kv, 2);
                $GLOBALS['ck'][trim($a2)] = trim($b2);
            }
        }
    }
    if ($code === 0 && !empty($meta['timed_out'])) {
        $respBody = 'read timeout';
    }
    return ['code' => $code, 'loc' => $loc, 'body' => $respBody, 'rawhead' => $head];
}

function tkn(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    return preg_match('/id="kataleCsrfToken" value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

// ---- Static pages & assets ----
$pages = ['/index.php' => 'home', '/products.php' => 'products', '/cart/cart.php' => 'cart',
    '/auth/login.php' => 'login', '/auth/register.php' => 'register', '/auth/forgot_password.php' => 'forgot',
    '/admin/login.php' => 'admin_login', '/assets/css/style.css' => 'css', '/assets/js/main.js' => 'js',
    '/assets/images/placeholder.svg' => 'svg', '/policies/privacy.php' => 'privacy'];
foreach ($pages as $p => $n) {
    $r = http($base . $p);
    C($n, $r['code'] === 200, 'code=' . $r['code']);
}
$home = http($base . '/index.php')['body'];
C('no-legacy-localhost-links', substr_count($home, 'http://localhost/') === 0,
    'n=' . substr_count($home, 'http://localhost/'));
C('app-base-url-global', strpos($home, "const APP_BASE_URL = '{$base}'") !== false);
if (preg_match('/product\.php\?slug=([a-z0-9\-]+)/', $home, $m)) {
    $r = http($base . '/product.php?slug=' . $m[1]);
    C('product-detail', $r['code'] === 200, 'slug=' . $m[1]);
} else {
    C('product-detail', false, 'no product links found');
}
$al = http($base . '/admin/login.php');
C('admin-assets-use-app-url', strpos($al['body'], $base . '/assets/css/style.css') !== false);

// ---- Guest redirects must point at APP_URL ----
foreach ([['admin-dashboard-guest', '/admin/dashboard.php', '/admin/login.php'],
    ['wishlist-guest', '/customer/wishlist.php', '/auth/login.php'],
    ['notifications-guest', '/notifications.php', '/auth/login.php'],
    ['checkout-guest', '/checkout/checkout.php', '/auth/login.php']] as $t) {
    $r = http($base . $t[1]);
    C($t[0], $r['code'] === 302 && $r['loc'] === $base . $t[2], 'code=' . $r['code'] . ' loc=' . $r['loc']);
}
$r = http($base . '/notifications_poll.php');
$jb = trim($r['body']);
C('notifications-poll-json', $r['code'] === 200 && str_starts_with($jb, '{'), substr($jb, 0, 50));
$r = http($base . '/products/search_suggest.php?q=ma');
$jb = trim($r['body']);
C('search-suggest-json', $r['code'] === 200 && (str_starts_with($jb, '[') || str_starts_with($jb, '{')),
    'code=' . $r['code'] . ' ' . substr($jb, 0, 50));

// ---- Register / logout / login ----
$GLOBALS['ck'] = []; // fresh session: no redirect_after_login from guest probes
$g = http($base . '/auth/register.php');
$em = 'urltest' . substr(md5(uniqid('', true)), 0, 6) . '@example.com';
$r = http($base . '/auth/register.php', 'POST', ['full_name' => 'URL Test Customer', 'phone' => '0772123456',
    'email' => $em, 'password' => 'TestPass123!', 'confirm_password' => 'TestPass123!',
    'csrf_token' => tkn($g['body'])], 40);
$ok = $r['code'] === 302 && $r['loc'] === $base . '/index.php';
$er = '';
if (!$ok && $r['code'] === 200 && preg_match('/flash flash-error[^>]*>(.*?)<\/div>/s', $r['body'], $m)) {
    $er = trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
}
C('register-post', $ok, 'code=' . $r['code'] . ' loc=' . $r['loc'] . ' ' . $er);
$h = http($base . '/index.php');
C('register-session-active', str_contains($h['body'], '/customer/profile.php'));
$r = http($base . '/auth/logout.php');
C('logout-redirect', $r['code'] === 302 && $r['loc'] === $base . '/index.php', 'code=' . $r['code'] . ' loc=' . $r['loc']);
$h = http($base . '/index.php');
C('logout-clears-session', str_contains($h['body'], '/auth/login.php'));
$g = http($base . '/auth/login.php');
$r = http($base . '/auth/login.php', 'POST', ['email' => $em, 'password' => 'TestPass123!', 'csrf_token' => tkn($g['body'])]);
C('login-post', $r['code'] === 302 && $r['loc'] === $base . '/index.php', 'code=' . $r['code'] . ' loc=' . $r['loc']);
$h = http($base . '/index.php');
C('login-session-active', str_contains($h['body'], '/customer/profile.php'));

// ---- Cart AJAX → checkout → order placement ----
$tk = tkn($h['body']);
// Skip the synchronous order email (SMTP) for the test user so the order
// request is not held open by an outbound SMTP connection. Uses the app's
// own notification-preference mechanism (order_updates_email = 0).
if ($pdo) {
    try {
        $pdo->prepare("INSERT INTO user_notification_preferences (user_id, order_updates_email)
                       SELECT user_id, 0 FROM users WHERE email = ?
                       ON DUPLICATE KEY UPDATE order_updates_email = 0")->execute([$em]);
        $chk = $pdo->prepare("SELECT p.order_updates_email FROM user_notification_preferences p
                              JOIN users u ON u.user_id = p.user_id WHERE u.email = ?");
        $chk->execute([$em]);
        $val = $chk->fetchColumn();
        C('order-email-pref-skip', $val !== false && (int) $val === 0, 'value=' . var_export($val, true));
    } catch (Throwable $e) {
        C('order-email-pref-skip', false, substr($e->getMessage(), 0, 120));
    }
} else {
    C('order-email-pref-skip', false, 'no db');
}
$r = http($base . '/cart/cart_action.php', 'POST', ['action' => 'add', 'product_id' => 1, 'quantity' => 1, 'csrf_token' => $tk]);
$jb = trim($r['body']);
C('cart-ajax-add', $r['code'] === 200 && str_starts_with($jb, '{'), substr($jb, 0, 90));
$ckPage = http($base . '/checkout/checkout.php');
foreach ($GLOBALS['ck'] as $ckn => $ckv) {
    C('jar-' . $ckn, preg_match('/^[A-Za-z0-9,.-]+$/', $ckv) === 1, 'hex=' . bin2hex($ckv));
}
if ($ckPage['code'] === 200) {
    $act = preg_match('/action="([^"]*checkout_process[^"]*)"/', $ckPage['body'], $m) ? $m[1] : '';
    C('checkout-form-action', $act === $base . '/checkout/checkout_process.php',
        'action=' . $act . ' len=' . strlen($ckPage['body'])
        . ' hasForm=' . (strpos($ckPage['body'], 'checkout_process') !== false ? 'Y' : 'N'));
    $t0 = microtime(true);
    $r = http($base . '/checkout/checkout_process.php', 'POST', [
        'customer_name' => 'URL Test Customer', 'customer_email' => $em,
        'delivery_method' => 'pickup', 'payment_method' => 'cash_on_delivery',
        'declared_subtotal' => '0', 'csrf_token' => tkn($ckPage['body']),
    ], 150);
    $okLoc = str_starts_with($r['loc'], $base . '/orders/order_confirmation.php?order=');
    C('order-redirect-confirmation', $r['code'] === 302 && $okLoc,
        'code=' . $r['code'] . ' loc=' . $r['loc'] . ' elapsed=' . round(microtime(true) - $t0, 1) . 's');
    if ($okLoc) {
        $cf = http($r['loc']);
        C('order-confirmation-page', $cf['code'] === 200, 'code=' . $cf['code']);
        preg_match('/order=(KT-[0-9]+)/', $r['loc'], $m);
        $on = $m[1] ?? '';
        $hi = http($base . '/orders/order_history.php');
        C('order-history', $hi['code'] === 200 && str_contains($hi['body'], $on), 'order=' . $on);
        $dt = http($base . '/orders/order_detail.php?order=' . $on);
        C('order-detail', $dt['code'] === 200, 'code=' . $dt['code']);
    }
} else {
    C('checkout-form-action', false, 'code=' . $ckPage['code'] . ' loc=' . $ckPage['loc']);
}
$nl = http($base . '/notifications.php');
C('notifications-page-logged-in', $nl['code'] === 200, 'code=' . $nl['code']);
$r = http($base . '/customer/wishlist_toggle.php', 'POST', ['product_id' => 1, 'csrf_token' => $tk],
    30, "X-Requested-With: XMLHttpRequest\r\n");
$jb = trim($r['body']);
C('wishlist-toggle-endpoint', $r['code'] === 200 && str_starts_with($jb, '{'),
    'code=' . $r['code'] . ' ' . substr($jb, 0, 100));

// ---- Forgot password (nonexistent account: endpoint tested, no email sent) ----
$g = http($base . '/auth/forgot_password.php');
$r = http($base . '/auth/forgot_password.php', 'POST',
    ['email' => 'nouser' . substr(md5(uniqid('', true)), 0, 8) . '@example.com', 'csrf_token' => tkn($g['body'])], 40);
C('forgot-password-endpoint', $r['code'] === 200 && str_contains($r['body'], 'If an account exists'), 'code=' . $r['code']);

// ---- Cleanup test users from the dev database ----
try {
    if (!$pdo) {
        throw new RuntimeException('database connection unavailable');
    }
    $st = $pdo->prepare("DELETE FROM users WHERE email LIKE 'urltest%@example.com'");
    $st->execute();
    C('cleanup-test-users', true, 'rows=' . $st->rowCount());
} catch (Throwable $e) {
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $like = "LIKE 'urltest%@example.com'";
        $pdo->exec("DELETE oi FROM order_items oi JOIN orders o ON o.order_id = oi.order_id WHERE o.customer_email $like");
        $pdo->exec("DELETE pm FROM payments pm JOIN orders o ON o.order_id = pm.order_id WHERE o.customer_email $like");
        $pdo->exec("DELETE wl FROM wishlist_items wl JOIN wishlists w ON w.wishlist_id = wl.wishlist_id JOIN users u ON u.user_id = w.user_id WHERE u.email $like");
        $pdo->exec("DELETE w FROM wishlists w JOIN users u ON u.user_id = w.user_id WHERE u.email $like");
        $pdo->exec("DELETE n FROM notifications n JOIN users u ON u.user_id = n.user_id WHERE u.email $like");
        $pdo->exec("DELETE nl FROM notification_logs nl JOIN users u ON u.user_id = nl.user_id WHERE u.email $like");
        $pdo->exec("DELETE p FROM user_notification_preferences p JOIN users u ON u.user_id = p.user_id WHERE u.email $like");
        $pdo->exec("DELETE t FROM password_reset_tokens t JOIN users u ON u.user_id = t.user_id WHERE u.email $like");
        $n = $pdo->exec("DELETE FROM orders WHERE customer_email $like");
        $st = $pdo->prepare("DELETE FROM users WHERE email LIKE 'urltest%@example.com'");
        $st->execute();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        C('cleanup-test-users', true, 'rows=' . $st->rowCount() . ' orders=' . $n . ' (fk-off fallback)');
    } catch (Throwable $e2) {
        C('cleanup-test-users', false, substr($e2->getMessage(), 0, 140));
    }
}

$f = 0;
foreach ($GLOBALS['R'] as $line) {
    echo $line, "\n";
    if (str_starts_with($line, '[FAIL]')) {
        $f++;
    }
}
echo '==== TOTAL=' . count($GLOBALS['R']) . " FAILED=$f ====\n";
exit($f ? 1 : 0);