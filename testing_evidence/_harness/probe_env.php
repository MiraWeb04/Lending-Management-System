<?php
/**
 * Read-only environment probe. Prints key presence, never secret values.
 */
require_once dirname(__DIR__, 2) . '/lending_system/includes/env_lending.php';

$keys = [
    'APP_ENV', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD',
    'PAYMONGO_MODE', 'PAYMONGO_SECRET_KEY', 'PAYMONGO_PUBLIC_KEY', 'PAYMONGO_WEBHOOK_SECRET',
    'SMTP_HOST', 'SMTP_PORT', 'LENDING_MAX_DTI', 'LENDING_MIN_LOAN', 'LENDING_MAX_LOAN',
];

foreach ($keys as $key) {
    $value = (string)lendingEnv($key, '');
    if ($value === '') {
        echo $key . "=empty\n";
        continue;
    }
    $sensitive = str_contains($key, 'PASSWORD') || str_contains($key, 'SECRET') || str_contains($key, 'KEY');
    if (!$sensitive) {
        echo $key . '=' . $value . "\n";
        continue;
    }
    $prefix = substr($value, 0, 7);
    $kind = 'set';
    if (str_starts_with($value, 'sk_test') || str_starts_with($value, 'pk_test') || str_starts_with($value, 'whsk_')) {
        $kind = 'test-like';
    }
    if (str_starts_with($value, 'sk_live') || str_starts_with($value, 'pk_live')) {
        $kind = 'LIVE';
    }
    echo $key . "=present len=" . strlen($value) . " prefix=" . $prefix . " kind=" . $kind . "\n";
}

$host = (string)lendingEnv('DB_HOST', 'localhost');
$db = (string)lendingEnv('DB_NAME', 'lending_management');
$user = (string)lendingEnv('DB_USER', 'root');
$pass = (string)lendingEnv('DB_PASSWORD', '');
$pdo = new PDO(
    'mysql:host=' . $host . ';dbname=' . $db . ';charset=utf8mb4',
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo 'SERVER=' . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
echo 'DATABASE=' . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo 'TABLE_COUNT=' . count($tables) . "\n";
foreach ($tables as $table) {
    $safe = str_replace('`', '', (string)$table);
    $count = (int)$pdo->query('SELECT COUNT(*) FROM `' . $safe . '`')->fetchColumn();
    echo 'TABLE ' . $safe . ' rows=' . $count . "\n";
}

$focus = ['users', 'clients', 'loans', 'payments', 'loan_releases', 'loan_payment_schedules', 'loan_applications'];
foreach ($focus as $table) {
    if (!in_array($table, $tables, true)) {
        echo "COLUMNS $table MISSING\n";
        continue;
    }
    $cols = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
    $parts = [];
    foreach ($cols as $col) {
        $parts[] = $col['Field'] . ':' . $col['Type'] . ':' . $col['Null'] . ':' . ($col['Key'] ?: '-') . ':' . ($col['Default'] === null ? 'NULL' : $col['Default']);
    }
    echo 'COLUMNS ' . $table . ' ' . implode('|', $parts) . "\n";
}
