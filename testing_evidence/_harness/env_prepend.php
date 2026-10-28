<?php
/**
 * Isolated test runtime. Loaded only through PHP auto_prepend_file.
 * Forces the test database, disables outbound mail, and replaces PayMongo
 * credentials so the live keys in .env are never loaded by test processes.
 */
if (defined('FRAMS_TEST_PREPEND_LOADED')) {
    return;
}
define('FRAMS_TEST_PREPEND_LOADED', true);

function lendingLoadEnvFile(?string $path = null): array
{
    static $cache = null;
    if ($cache !== null && $path === null) {
        return $cache;
    }

    $values = [
        'APP_ENV' => 'local',
        'APP_URL' => 'http://127.0.0.1:8099',
        'APP_FORCE_HTTPS' => '0',
        'DB_HOST' => 'localhost',
        'DB_NAME' => 'frams_capstone_test',
        'DB_USER' => 'root',
        'DB_PASSWORD' => '',
        'LOAN_STATUS_SYNC_ON_REQUEST' => '0',
        'PAYMONGO_MODE' => 'test',
        'PAYMONGO_SECRET_KEY' => 'sk_test_frams_isolated_invalid_key',
        'PAYMONGO_PUBLIC_KEY' => 'pk_test_frams_isolated_invalid_key',
        'PAYMONGO_WEBHOOK_SECRET' => 'whsk_isolated_frams_test_only',
        'LENDING_MAX_DTI' => '40',
        'LENDING_MIN_LOAN' => '3000',
        'LENDING_MAX_LOAN' => '500000',
        'LENDING_MIN_EMPLOYMENT_MONTHS' => '3',
        'SMTP_HOST' => '127.0.0.1',
        'SMTP_PORT' => '1',
        'SMTP_SECURE' => '',
        'SMTP_AUTH' => 'false',
        'SMTP_USERNAME' => '',
        'SMTP_PASSWORD' => '',
        'SMTP_FROM_EMAIL' => 'frams-test@example.invalid',
        'SMTP_FROM_NAME' => 'FRAMS Isolated Test',
        'SMTP_TIMEOUT' => '1',
        'COMPANY_NAME' => 'FRAMS Isolated Test',
        'COMPANY_EMAIL' => 'frams-test@example.invalid',
    ];

    if ($path === null) {
        $cache = $values;
    }

    return $values;
}

function lendingEnv(string $key, mixed $default = null): mixed
{
    $env = lendingLoadEnvFile();
    $value = $env[$key] ?? null;
    if ($value === null || $value === '') {
        return $default;
    }

    return $value;
}

function lendingIsProduction(): bool
{
    return false;
}

function lendingRequestIsHttps(): bool
{
    return false;
}

function lendingConfigureRuntime(): void
{
    ini_set('display_errors', '1');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);

    if (PHP_SAPI === 'cli') {
        return;
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
