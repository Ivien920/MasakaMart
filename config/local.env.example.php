<?php
/**
 * PHP-array env file example. Copy outside the web root
 * (e.g. C:\wamp64\katale_local.env.php) and fill in values, OR use the
 * simpler plain-text `.env` in the project root (copy .env.example).
 * This file is an example only — do not put real credentials in the
 * repository.
 */
$katale_env = [
    // Environment / URLs
    'APP_ENV' => 'local',
    // 'APP_URL' => 'http://localhost/katale',  // auto-detected when omitted
    // 'APP_URL' => 'https://masakamart.xo.je', // production: set explicitly

    // Database (local WAMP/XAMPP defaults shown)
    // 'DB_HOST' => 'localhost',
    // 'DB_NAME' => 'katale_db',
    // 'DB_USER' => 'root',
    // 'DB_PASS' => '',

    'SMTP_HOST' => 'smtp-relay.brevo.com',
    'SMTP_PORT' => '587',
    'SMTP_USERNAME' => 'your-login@smtp-brevo.com',
    'SMTP_PASSWORD' => 'your-brevo-smtp-key',
    'SMTP_ENCRYPTION' => 'tls',
    // Must be a sender verified in Brevo — NOT your @smtp-brevo.com login.
    'SMTP_FROM_EMAIL' => 'verified-sender@yourdomain.com',
    'SMTP_FROM_NAME' => 'KATALE',
    // Optional: Brevo REST API key (xkeysib-...) for delivery tracing in tests/brevo_delivery_trace.php
    'BREVO_API_KEY' => '',

    // PesaJet payment credentials — fill in the values from your PesaJet
    // merchant dashboard (API & Webhooks). Keep this file OUTSIDE the web
    // root (e.g. C:\wamp64\katale_local.env.php) and never commit it.
    // Rotating credentials later = edit these values only; no code changes.
    'PESAJET_API_KEY' => '',                 // sent as the X-API-Key header
    'PESAJET_API_SECRET' => '',              // server-side only — never exposed
    'PESAJET_WEBHOOK_SECRET' => '',          // whsec_... for X-Webhook-Signature
    'PESAJET_MERCHANT_ID' => '',
    'PESAJET_BASE_URL' => 'https://payments.pesajet.com',

    // 'testing' or 'live' — the real API always decides the outcome.
    'PAYMENT_ENVIRONMENT' => 'testing',
    // true = log endpoint/HTTP status/sanitized responses (never secrets).
    'PESAJET_DEBUG' => 'true',

    // Payment provider URLs (default to APP_URL-based paths when omitted)
    // 'PAYMENT_RETURN_URL' => '',
    // 'PAYMENT_SUCCESS_URL' => '',
    // 'PAYMENT_FAILURE_URL' => '',
    // 'PAYMENT_CANCEL_URL' => '',
    // 'PAYMENT_CALLBACK_URL' => '',
    // 'PAYMENT_WEBHOOK_URL' => '',
];
