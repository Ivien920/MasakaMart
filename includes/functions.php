<?php
/**
 * KATALE — General helper functions.
 */

/** Escape output to prevent XSS. Use this around every echo of user/db data. */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/** Format a number as KATALE's currency, e.g. "UGX 4,000". */
function money(float $amount): string
{
    return APP_CURRENCY . ' ' . number_format($amount, 0);
}

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

/** Redirect and stop execution. */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/** Store a one-time flash message shown on the next page load. */
function flash(string $key, ?string $message = null)
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }
    if (!empty($_SESSION['flash'][$key])) {
        $msg = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $msg;
    }
    return null;
}

/** CSRF token helpers — every state-changing form must include this token. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function require_csrf(): void
{
    if (!csrf_verify()) {
        http_response_code(403);
        die('Security check failed. Please go back, refresh the page, and try again.');
    }
}

/** ---------------- Customer auth helpers ---------------- */

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '/';
        flash('notice', 'Please log in or create an account to continue with your order.');
        redirect(APP_URL . '/auth/login.php');
    }
}

/** ---------------- Admin auth helpers ---------------- */

function current_admin(): ?array
{
    return $_SESSION['admin'] ?? null;
}

function require_admin(): void
{
    if (empty($_SESSION['admin'])) {
        redirect(APP_URL . '/admin/login.php');
    }
}

/** ---------------- Misc ---------------- */

function session_id_key(): string
{
    return $_SESSION['katale_session_id'];
}

function generate_order_number(PDO $db): string
{
    // KT-10001, KT-10002, ... derived from the last numeric order id.
    $stmt = $db->query("SELECT MAX(order_id) AS max_id FROM orders");
    $maxId = (int)($stmt->fetch()['max_id'] ?? 0);
    return 'KT-' . (10000 + $maxId + 1);
}

function generate_transaction_reference(): string
{
    return 'TXN-' . strtoupper(bin2hex(random_bytes(6)));
}

function katale_global_back_button(string $fallback = ''): string
{
    $fallbackHref = $fallback !== '' ? $fallback : APP_URL . '/index.php';
    return '<button type="button" class="global-back-button" data-back-link="1" data-back-fallback="' . e($fallbackHref) . '" aria-label="Go back">'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>'
        . '</button>';
}
