<?php
/**
 * KATALE — Central application configuration.
 *
 * Copy this file's values into your own environment. Never commit real
 * credentials to a public repository — for a real deployment, load these
 * from environment variables instead of hardcoding them here.
 */

// ---- App settings ------------------------------------------------------
define('APP_NAME', 'MasakaMart');
define('APP_TAGLINE', 'Fresh Food. Better Living.');
define('APP_LOCATION', 'Masaka, Uganda');
define('APP_CURRENCY', 'UGX');
define('APP_TIMEZONE', 'Africa/Kampala');

// ---- Runtime environment (.env / process environment) -------------------
// Database credentials, APP_ENV/APP_URL, SMTP and payment settings are
// resolved below from the environment (see .env.example) with local-safe
// defaults, so the same code runs on localhost and on the live domain.
// Values come from the running PHP environment and, optionally, from an env
// file. Never commit real credentials to the repository or expose them to
// the browser.
$kataleLocalEnvFiles = array_values(array_filter([
    $_SERVER['KATALE_ENV_FILE'] ?? '',
    $_ENV['KATALE_ENV_FILE'] ?? '',
    getenv('KATALE_ENV_FILE') ?: '',
    __DIR__ . '/../.env.php',
    __DIR__ . '/../.env',
    dirname(__DIR__, 2) . '\\katale_local.env.php',
    dirname(__DIR__, 2) . '\\katale_local.env',
    'C:\\wamp64\\katale_local.env.php',
    'C:\\wamp64\\katale_local.env',
    'C:/wamp64/katale_local.env.php',
    'C:/wamp64/katale_local.env',
    'C:\\wamp64\\www\\katale_local.env.php',
    'C:\\wamp64\\www\\katale_local.env',
], static fn ($envFile) => is_string($envFile) && $envFile !== ''));

$kataleRuntimeEnv = [];
foreach ($kataleLocalEnvFiles as $envFile) {
    if (!is_file($envFile) || !is_readable($envFile)) {
        continue;
    }

    $fileValues = [];
    $head = (string) file_get_contents($envFile, false, null, 0, 128);
    if (str_contains($head, '<?')) {
        // PHP-style env file: <?php $katale_env = [ 'KEY' => 'value' ];
        $katale_env = [];
        require $envFile;
        if (is_array($katale_env)) {
            $fileValues = $katale_env;
        }
    } else {
        // Plain dotenv file: KEY=value lines (see .env.example).
        $lines = file($envFile, FILE_IGNORE_NEW_LINES);
        foreach ($lines ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $eqPos = strpos($line, '=');
            if ($eqPos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eqPos));
            $value = trim(substr($line, $eqPos + 1));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
                $value = substr($value, 1, -1);
            } elseif (($hashPos = strpos($value, ' #')) !== false) {
                $value = rtrim(substr($value, 0, $hashPos));
            }
            $fileValues[$key] = $value;
        }
    }

    // Merge: earlier (higher-priority) files win per key; later files only
    // fill in values that are still missing. This way a small .env with just
    // APP_URL does not shadow a separate SMTP/PesaJet credentials file.
    foreach ($fileValues as $key => $value) {
        if (!array_key_exists($key, $kataleRuntimeEnv)) {
            $kataleRuntimeEnv[$key] = $value;
        }
    }
}

$GLOBALS['kataleRuntimeEnv'] = $kataleRuntimeEnv;

// ---- Base URL helpers ----------------------------------------------------
// Detects the URL path the app is installed under ("/katale", "", "/subdir")
// from the current script location, so the same code works at the web root
// or in a sub-folder.
if (!function_exists('katale_detect_base_path')) {
    function katale_detect_base_path(): string
    {
        // Primary: project root relative to the web server's document root.
        $appRoot = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
        $docRoot = rtrim(str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
        if ($docRoot !== '' && $appRoot !== '' && stripos($appRoot, $docRoot) === 0) {
            $rest = substr($appRoot, strlen($docRoot));
            if ($rest === '' || $rest[0] === '/') {
                return $rest;
            }
        }

        // Fallback: derive the base path from the current script's URL path.
        $scriptFilename = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
        $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        if ($scriptFilename === '' || $scriptName === '') {
            return '';
        }

        $scriptDirFs = str_replace('\\', '/', dirname($scriptFilename));
        if (strncasecmp($scriptDirFs, $appRoot, strlen($appRoot)) !== 0) {
            return '';
        }

        $relDir = substr($scriptDirFs, strlen($appRoot)); // '', '/admin', ...
        $scriptDirUrl = str_replace('\\', '/', dirname($scriptName));
        if (str_ends_with($scriptName, '/')) {
            $scriptDirUrl = rtrim(str_replace('\\', '/', $scriptName), '/') ?: '/';
        }
        $base = strlen($scriptDirUrl) >= strlen($relDir)
            ? substr($scriptDirUrl, 0, strlen($scriptDirUrl) - strlen($relDir))
            : $scriptDirUrl;
        $base = trim($base, '/');

        return $base === '' ? '' : '/' . $base;
    }
}

if (!function_exists('katale_resolve_app_url')) {
    function katale_resolve_app_url(): string
    {
        // 1. Explicit configuration always wins (set APP_URL in the env file
        //    for production so the canonical domain never depends on headers).
        $configured = katale_runtime_value('APP_URL', '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        // 2. Auto-detect from the current request — works on localhost and on
        //    the live domain without any configuration.
        $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
        if ($host !== '' && preg_match('/^[a-z0-9.\-\[\]:]+$/i', $host) === 1) {
            $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
            $isHttps = $forwardedProto === 'https'
                || (isset($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off')
                || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

            return ($isHttps ? 'https' : 'http') . '://' . $host . katale_detect_base_path();
        }

        // 3. Last resort (CLI with no env file, e.g. `php create_admin.php`
        //    during initial local setup). Production must set APP_URL.
        return 'http://localhost/' . basename(dirname(__DIR__));
    }
}

function katale_runtime_value(string $key, string $default = ''): string
{
    static $aliases = [
        'SMTP_HOST' => ['BREVO_SMTP_HOST', 'MAIL_HOST'],
        'SMTP_PORT' => ['BREVO_SMTP_PORT', 'MAIL_PORT'],
        'SMTP_USERNAME' => ['BREVO_SMTP_USERNAME', 'SMTP_USER', 'BREVO_SMTP_USER', 'MAIL_USERNAME'],
        'SMTP_PASSWORD' => ['BREVO_SMTP_PASSWORD', 'SMTP_PASS', 'BREVO_SMTP_KEY', 'BREVO_API_KEY', 'MAIL_PASSWORD'],
        'SMTP_ENCRYPTION' => ['BREVO_SMTP_ENCRYPTION', 'MAIL_ENCRYPTION'],
        'SMTP_FROM_EMAIL' => ['MAIL_FROM_ADDRESS', 'MAIL_FROM_EMAIL', 'BREVO_FROM_EMAIL', 'SMTP_FROM'],
        'SMTP_FROM_NAME' => ['MAIL_FROM_NAME', 'BREVO_FROM_NAME'],
        'SMTP_REPLY_TO_EMAIL' => ['MAIL_REPLY_TO', 'REPLY_TO_EMAIL', 'REPLY_TO', 'SMTP_REPLY_TO'],
        'ADMIN_EMAIL' => ['NOTIFICATION_ADMIN_EMAIL', 'STORE_ADMIN_EMAIL', 'ADMIN_NOTIFICATION_EMAIL'],
    ];

    $keys = [$key];
    if (isset($aliases[$key])) {
        $keys = array_merge($keys, $aliases[$key]);
    }

    foreach ($keys as $lookupKey) {
        // Secure local file wins over stale shell/Apache environment variables (WAMP dev).
        if (isset($GLOBALS['kataleRuntimeEnv']) && is_array($GLOBALS['kataleRuntimeEnv'])) {
            $localValue = $GLOBALS['kataleRuntimeEnv'][$lookupKey] ?? null;
            if (is_string($localValue) && trim($localValue) !== '') {
                return trim($localValue);
            }
        }

        $value = $_ENV[$lookupKey] ?? $_SERVER[$lookupKey] ?? getenv($lookupKey);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    if (defined('KATALE_ENV_' . strtoupper($key))) {
        $constantValue = constant('KATALE_ENV_' . strtoupper($key));
        if (is_string($constantValue) && trim($constantValue) !== '') {
            return trim($constantValue);
        }
    }

    return $default;
}

// ---- Base URL / environment resolution ----------------------------------
// APP_URL is resolved once here; every link, redirect, email link, password
// reset link, asset URL and the frontend APP_BASE_URL global derive from it.
define('APP_ENV', katale_runtime_value('APP_ENV', 'local'));
define('APP_URL', katale_resolve_app_url());

if (APP_ENV === 'production' && preg_match('#^https?://(localhost|127\.0\.0\.1)([/:]|$)#i', APP_URL) === 1) {
    error_log('[katale] APP_ENV=production but APP_URL=' . APP_URL . ' — set APP_URL in the environment file to the live domain.');
}

// ---- Database credentials (env-overridable; local WAMP/XAMPP defaults) --
define('DB_HOST', katale_runtime_value('DB_HOST', 'localhost'));
define('DB_NAME', katale_runtime_value('DB_NAME', 'katale_db'));
define('DB_USER', katale_runtime_value('DB_USER', 'root'));
define('DB_PASS', katale_runtime_value('DB_PASS', '')); // set the real value only in the .env file — never hardcode it here
define('DB_CHARSET', katale_runtime_value('DB_CHARSET', 'utf8mb4'));

// ---- Payment provider (PesaJet) return/callback URLs ---------------------
// Browser/server URLs the payment gateway will use to send the customer and
// the gateway back to MasakaMart. Derived from APP_URL so they can never
// point at localhost once APP_URL is correct; each one can be overridden in
// the environment file when the provider requires an exact registered
// value. PAYMENT_WEBHOOK_URL must match the endpoint registered in the
// PesaJet merchant dashboard (default: checkout/payment_webhook.php).
define('PAYMENT_RETURN_URL', katale_runtime_value('PAYMENT_RETURN_URL', APP_URL . '/checkout/checkout.php'));
define('PAYMENT_SUCCESS_URL', katale_runtime_value('PAYMENT_SUCCESS_URL', APP_URL . '/orders/order_history.php'));
define('PAYMENT_FAILURE_URL', katale_runtime_value('PAYMENT_FAILURE_URL', APP_URL . '/checkout/checkout.php'));
define('PAYMENT_CANCEL_URL', katale_runtime_value('PAYMENT_CANCEL_URL', APP_URL . '/checkout/checkout.php'));
// Server-to-server endpoints — implemented handlers for the live PesaJet
// integration. Register these exact URLs with the provider when going live;
// override here only if different paths are required.
define('PAYMENT_CALLBACK_URL', katale_runtime_value('PAYMENT_CALLBACK_URL', APP_URL . '/checkout/payment_callback.php'));
define('PAYMENT_WEBHOOK_URL', katale_runtime_value('PAYMENT_WEBHOOK_URL', APP_URL . '/checkout/payment_webhook.php'));

// ---- SMTP / Email settings ----------------------------------------------
define('SMTP_HOST', katale_runtime_value('SMTP_HOST', 'smtp-relay.brevo.com'));
define('SMTP_PORT', (int)katale_runtime_value('SMTP_PORT', '587'));
define('SMTP_USERNAME', katale_runtime_value('SMTP_USERNAME', ''));
define('SMTP_PASSWORD', katale_runtime_value('SMTP_PASSWORD', ''));
define('SMTP_ENCRYPTION', katale_runtime_value('SMTP_ENCRYPTION', 'tls'));
define('SMTP_FROM_EMAIL', katale_runtime_value('SMTP_FROM_EMAIL', ''));
define('SMTP_FROM_NAME', katale_runtime_value('SMTP_FROM_NAME', 'MasakaMart'));
define('SMTP_REPLY_TO_EMAIL', katale_runtime_value('SMTP_REPLY_TO_EMAIL', SMTP_FROM_EMAIL));
define('ADMIN_EMAIL', katale_runtime_value('ADMIN_EMAIL', ''));
define('BREVO_API_KEY', katale_runtime_value('BREVO_API_KEY', ''));

// ---- PesaJet payment credentials -----------------------------------------
// NEVER commit real values — set them in a git-ignored environment file
// (see .env.example / config/local.env.example.php). Rotating credentials
// is a config-file edit only; no application code changes are required.
// PESAJET_API_KEY      — dashboard "API Key" (sent as the X-API-Key header).
// PESAJET_API_SECRET   — kept server-side only; never sent to the browser.
// PESAJET_WEBHOOK_SECRET — whsec_... signing secret used to verify the
//                        X-Webhook-Signature HMAC-SHA256 header; never
//                        exposed to the browser or written to logs.
define('PESAJET_API_KEY', katale_runtime_value('PESAJET_API_KEY', ''));
define('PESAJET_API_SECRET', katale_runtime_value('PESAJET_API_SECRET', ''));
define('PESAJET_WEBHOOK_SECRET', katale_runtime_value('PESAJET_WEBHOOK_SECRET', ''));
define('PESAJET_MERCHANT_ID', katale_runtime_value('PESAJET_MERCHANT_ID', ''));
define('PESAJET_BASE_URL', katale_runtime_value('PESAJET_BASE_URL', 'https://payments.pesajet.com'));

// Explicit test/live switch — the app never silently simulates payments in
// either mode; the real PesaJet API response always decides the outcome.
// 'testing' or 'live' (informational + gating for debug tooling).
define('PAYMENT_ENVIRONMENT', katale_runtime_value('PAYMENT_ENVIRONMENT', 'testing'));

// Safe payment debugging: logs endpoint/HTTP status/sanitized responses.
// NEVER logs API keys, API secrets or the webhook secret. Set to 'false'
// (or remove) before production.
define('PESAJET_DEBUG', strtolower(katale_runtime_value('PESAJET_DEBUG', 'false')) === 'true');

// ---- Web Push (VAPID) ------------------------------------------------------
// Generate once with `php tools/generate_vapid_keys.php` and paste both
// values into the environment file. Never commit or expose the private key.
// Push silently no-ops (never breaks any other feature) until these are set.
define('VAPID_PUBLIC_KEY', katale_runtime_value('VAPID_PUBLIC_KEY', ''));
define('VAPID_PRIVATE_KEY', katale_runtime_value('VAPID_PRIVATE_KEY', ''));
define('VAPID_SUBJECT', katale_runtime_value('VAPID_SUBJECT', 'mailto:support@masakamart.xo.je'));

// ---- Google Analytics 4 -------------------------------------------------
// Set via the environment (GA4_MEASUREMENT_ID) or paste a real Measurement
// ID here (e.g. 'G-XXXXXXXXXX'). Leave empty and GA4 will simply not load —
// no fake ID is ever inserted.
define('GA4_MEASUREMENT_ID', katale_runtime_value('GA4_MEASUREMENT_ID', 'G-7CK8BZZXKV'));
define('GA4_ANALYTICS_CONSENT', strtolower(katale_runtime_value('GA4_ANALYTICS_CONSENT', 'true')) !== 'false');
define('SEO_SITE_URL', rtrim(katale_runtime_value('SEO_SITE_URL', 'https://masakamart.xo.je'), '/'));
define('SEARCH_CONSOLE_VERIFICATION', katale_runtime_value('SEARCH_CONSOLE_VERIFICATION', ''));

// ---- File upload limits --------------------------------------------------
define('UPLOAD_DIR', __DIR__ . '/../uploads/products/');
define('UPLOAD_URL', APP_URL . '/uploads/products/');
define('MAX_UPLOAD_BYTES', 2 * 1024 * 1024); // 2MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/webp']);

// ---- Session / security --------------------------------------------------
define('SESSION_NAME', 'katale_session');

date_default_timezone_set(APP_TIMEZONE);

// Never display raw PHP errors to end users. Log them instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

session_name(SESSION_NAME);
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'secure'   => str_starts_with(APP_URL, 'https://'),
    'samesite' => 'Lax',
]);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Every browser tab/guest gets a stable session id we can key guest carts on.
if (empty($_SESSION['katale_session_id'])) {
    $_SESSION['katale_session_id'] = bin2hex(random_bytes(16));
}
