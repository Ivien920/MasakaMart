<?php
/**
 * Run ONCE from the command line to generate a VAPID keypair for Web Push:
 *
 *   php tools/generate_vapid_keys.php
 *
 * Requires PHP compiled with OpenSSL support for the prime256v1 (P-256)
 * elliptic curve, and PHP 8.1+ (openssl_pkey_derive, used by
 * WebPushService.php at send-time, was added in PHP 8.1). Run
 * `php -v` on your host first — if it's older than 8.1, ask your host to
 * upgrade, or push notifications will silently stay disabled.
 *
 * Do NOT run this over the web (it must not be reachable as a URL) — it is
 * meant for SSH/CLI use only, and prints your PRIVATE key to the terminal.
 * Copy both output lines into your .env file, then delete your terminal
 * scrollback/history if anyone else can read it.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script is CLI-only and must not be run over the web.');
}

if (!extension_loaded('openssl')) {
    fwrite(STDERR, "The OpenSSL PHP extension is not available. Ask your host to enable it.\n");
    exit(1);
}

$config = [
    'curve_name' => 'prime256v1',
    'private_key_type' => OPENSSL_KEYTYPE_EC,
];

$res = openssl_pkey_new($config);
if ($res === false) {
    fwrite(STDERR, "Could not generate an EC key. Your PHP/OpenSSL build may not support prime256v1.\n" . openssl_error_string() . "\n");
    exit(1);
}

$details = openssl_pkey_get_details($res);
if ($details === false || !isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
    fwrite(STDERR, "Could not read EC key details from this OpenSSL build.\n");
    exit(1);
}

function b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

// Uncompressed EC public key point: 0x04 || X (32 bytes) || Y (32 bytes).
$x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
$y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
$d = str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT);

$publicKey = b64url("\x04" . $x . $y);
$privateKey = b64url($d);

echo "Add these two lines to your .env file, then set PAYMENT/other secrets\n";
echo "as usual — never commit this file or paste it anywhere public:\n\n";
echo "VAPID_PUBLIC_KEY=" . $publicKey . "\n";
echo "VAPID_PRIVATE_KEY=" . $privateKey . "\n\n";
echo "The PUBLIC key also needs to go into assets/js/push.js (there is a\n";
echo "clearly marked VAPID_PUBLIC_KEY placeholder near the top of that file)\n";
echo "so the browser can create a subscription. The public key is safe to\n";
echo "expose in JavaScript; the private key must never leave the server.\n";
