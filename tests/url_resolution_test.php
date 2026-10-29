<?php
/**
 * KATALE — APP_URL resolution test (CLI).
 * Verifies config/config.php resolves the application URL correctly for
 * local dev, production domains, sub-folder installs, reverse proxies,
 * explicit env overrides and the CLI fallback.
 * Usage: php tests/url_resolution_test.php
 */

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (PHP_SAPI !== 'cli' && !in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('This test is restricted to CLI / localhost only.');
}

$root = dirname(__DIR__);
$webRoot = dirname($root);
$base = '/' . basename($root);
$envFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'katale_url_test.env';

$scenarios = [
    'local-subfolder' => [
        'server' => ['HTTP_HOST' => 'localhost', 'DOCUMENT_ROOT' => $webRoot,
            'SCRIPT_NAME' => $base . '/index.php', 'SCRIPT_FILENAME' => $root . '/index.php'],
        'env' => "APP_URL=\n",
        'expect' => 'http://localhost' . $base,
    ],
    'local-subfolder-no-docroot' => [
        'server' => ['HTTP_HOST' => 'localhost',
            'SCRIPT_NAME' => $base . '/admin/login.php', 'SCRIPT_FILENAME' => $root . '/admin/login.php'],
        'env' => "APP_URL=\n",
        'expect' => 'http://localhost' . $base,
    ],
    'local-port' => [
        'server' => ['HTTP_HOST' => 'localhost:8080', 'DOCUMENT_ROOT' => $webRoot,
            'SCRIPT_NAME' => $base . '/index.php', 'SCRIPT_FILENAME' => $root . '/index.php'],
        'env' => "APP_URL=\n",
        'expect' => 'http://localhost:8080' . $base,
    ],
    'prod-webroot-https' => [
        'server' => ['HTTP_HOST' => 'shop.example.com', 'HTTPS' => 'on', 'DOCUMENT_ROOT' => $root,
            'SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => $root . '/index.php'],
        'env' => "APP_URL=\n",
        'expect' => 'https://shop.example.com',
    ],
    'prod-subfolder-proxy' => [
        'server' => ['HTTP_HOST' => 'shop.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https',
            'DOCUMENT_ROOT' => $webRoot, 'SCRIPT_NAME' => $base . '/admin/login.php',
            'SCRIPT_FILENAME' => $root . '/admin/login.php'],
        'env' => "APP_URL=\n",
        'expect' => 'https://shop.example.com' . $base,
    ],
    'explicit-env-override' => [
        'server' => ['HTTP_HOST' => 'evil.example.net', 'DOCUMENT_ROOT' => $webRoot,
            'SCRIPT_NAME' => $base . '/index.php', 'SCRIPT_FILENAME' => $root . '/index.php'],
        'env' => "APP_URL=https://forced.example.com\n",
        'expect' => 'https://forced.example.com',
    ],
    'cli-fallback' => [
        'server' => [],
        'env' => "APP_URL=\n",
        'expect' => 'http://localhost/' . basename($root),
    ],
    'production-warning-localhost' => [
        'server' => ['HTTP_HOST' => 'localhost', 'DOCUMENT_ROOT' => $webRoot,
            'SCRIPT_NAME' => $base . '/index.php', 'SCRIPT_FILENAME' => $root . '/index.php'],
        'env' => "APP_ENV=production\nAPP_URL=\n",
        'expect' => 'http://localhost' . $base,
        'expect_warning' => true,
    ],
];

if (($argv[1] ?? '') === '--run') {
    $name = $argv[2] ?? '';
    $expected = $argv[3] ?? '';
    if (!isset($scenarios[$name])) {
        fwrite(STDERR, "Unknown scenario: {$name}\n");
        exit(2);
    }
    $scenario = $scenarios[$name];

    // Clear inherited URL variables for deterministic runs.
    putenv('APP_URL');
    unset($_ENV['APP_URL'], $_SERVER['APP_URL']);
    foreach (['HTTP_HOST', 'SERVER_NAME', 'HTTPS', 'DOCUMENT_ROOT', 'SCRIPT_NAME', 'SCRIPT_FILENAME', 'HTTP_X_FORWARDED_PROTO', 'SERVER_PORT'] as $key) {
        unset($_SERVER[$key]);
    }
    foreach ($scenario['server'] as $key => $value) {
        $_SERVER[$key] = $value;
    }
    $_SERVER['KATALE_ENV_FILE'] = $envFile;

    $warnFile = '';
    if (!empty($scenario['expect_warning'])) {
        $warnFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'katale_url_test_warn.log';
        @unlink($warnFile);
        ini_set('log_errors', '1');
        ini_set('error_log', $warnFile);
    }

    require dirname(__DIR__) . '/config/config.php';

    $failures = [];
    if (APP_URL !== $expected) {
        $failures[] = 'APP_URL=' . APP_URL . ' (expected ' . $expected . ')';
    }
    if (PAYMENT_CALLBACK_URL !== APP_URL . '/checkout/payment_callback.php') {
        $failures[] = 'PAYMENT_CALLBACK_URL=' . PAYMENT_CALLBACK_URL;
    }
    if (!empty($scenario['expect_warning'])) {
        $logged = ($warnFile !== '' && is_file($warnFile)) ? (string) file_get_contents($warnFile) : '';
        if (strpos($logged, 'APP_ENV=production but APP_URL') === false) {
            $failures[] = 'expected production localhost warning was not logged';
        }
    }

    if ($failures) {
        fwrite(STDERR, implode('; ', $failures) . "\n");
        exit(1);
    }
    echo APP_URL . "\n";
    exit(0);
}

if (!function_exists('exec')) {
    fwrite(STDERR, "exec() is disabled; run this test from a terminal.\n");
    exit(2);
}

$php = PHP_BINARY;
$failed = 0;
foreach ($scenarios as $name => $scenario) {
    file_put_contents($envFile, $scenario['env']);
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__FILE__) . ' --run '
        . escapeshellarg($name) . ' ' . escapeshellarg($scenario['expect']);
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    $ok = $code === 0;
    if (!$ok) {
        $failed++;
    }
    printf("[%s] %-30s %s\n", $ok ? 'PASS' : 'FAIL', $name, trim(implode(' | ', $out)));
}
@unlink($envFile);

echo $failed === 0
    ? 'OK — all ' . count($scenarios) . " scenarios passed.\n"
    : "FAILED — {$failed} scenario(s).\n";
exit($failed === 0 ? 0 : 1);