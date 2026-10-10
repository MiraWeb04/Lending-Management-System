<?php
/**
 * Load project .env (shared with mail_config).
 */

if (!function_exists('lendingLoadEnvFile')) {
    function lendingLoadEnvFile(?string $path = null): array
    {
        static $cache = null;
        if ($cache !== null && $path === null) {
            return $cache;
        }

        if ($path === null) {
            $projectRoot = dirname(__DIR__, 2);
            $path = is_file($projectRoot . '/.env') ? $projectRoot . '/.env' : dirname(__DIR__) . '/.env';
        }

        $values = [];
        if (is_file($path) && is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if ($value !== '' && (($value[0] ?? '') === '"' || ($value[0] ?? '') === "'")) {
                    $value = trim($value, "\"'");
                }
                $values[$key] = $value;
            }
        }

        if ($path === null || $cache === null) {
            $cache = $values;
        }

        return $values;
    }

    function lendingEnv(string $key, mixed $default = null): mixed
    {
        $env = lendingLoadEnvFile();
        $value = $env[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return $value;
    }

    function lendingIsProduction(): bool
    {
        $env = strtolower(trim((string)lendingEnv('APP_ENV', 'local')));
        return $env === 'production' || $env === 'prod';
    }

    function lendingRequestIsHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        $forwarded = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return $forwarded === 'https';
    }

    /**
     * Production error display, HTTPS redirect, and session cookie flags.
     * Call this before session_start().
     */
    function lendingConfigureRuntime(): void
    {
        $isProd = lendingIsProduction();
        ini_set('display_errors', $isProd ? '0' : '1');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);

        if (PHP_SAPI === 'cli') {
            return;
        }

        $https = lendingRequestIsHttps();
        $forceHttps = filter_var(lendingEnv('APP_FORCE_HTTPS', '0'), FILTER_VALIDATE_BOOL);
        if ($forceHttps && !$https && !empty($_SERVER['HTTP_HOST'])) {
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            header('Location: https://' . $_SERVER['HTTP_HOST'] . $uri, true, 301);
            exit;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $https,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }
}
