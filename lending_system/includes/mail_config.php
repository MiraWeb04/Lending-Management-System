<?php

require_once __DIR__ . '/env_lending.php';
$envValue = static function (string $key, mixed $default = null): mixed {
    return lendingEnv($key, $default);
};

return [
    'smtp_host' => (string)$envValue('SMTP_HOST', 'smtp.gmail.com'),
    'smtp_port' => (int)$envValue('SMTP_PORT', 587),
    'smtp_secure' => (string)$envValue('SMTP_SECURE', 'tls'),
    'smtp_auth' => filter_var($envValue('SMTP_AUTH', true), FILTER_VALIDATE_BOOL),
    'smtp_user' => $envValue('SMTP_USERNAME'),
    'smtp_pass' => $envValue('SMTP_PASSWORD'),
    'smtp_timeout' => (int)$envValue('SMTP_TIMEOUT', 20),
    'from_email' => (string)$envValue('SMTP_FROM_EMAIL', 'no-reply@lending.local'),
    'from_name' => (string)$envValue('SMTP_FROM_NAME', 'RJ and RR Finance Services'),
    'show_reset_link' => false,
];
