<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require_once dirname(__DIR__) . '/includes/env_lending.php';

$root = dirname(__DIR__, 2);
$probeDatabase = 'lending_paymongo_migration_probe';
$host = (string)lendingEnv('DB_HOST', '127.0.0.1');
$user = (string)lendingEnv('DB_USER', 'root');
$password = (string)lendingEnv('DB_PASSWORD', '');
$dsn = 'mysql:host=' . $host . ';charset=utf8mb4';
$pdo = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $probeDatabase . '`');
$pdo->exec('USE `' . $probeDatabase . '`');
$pdo->exec('DROP TABLE IF EXISTS paymongo_qrph_webhook_events');
$pdo->exec('DROP TABLE IF EXISTS paymongo_qrph_transactions');
$pdo->exec('DROP TABLE IF EXISTS payments');
$pdo->exec(
    'CREATE TABLE payments (
        payment_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        reference_number VARCHAR(255) NULL
    ) ENGINE=InnoDB'
);

$files = [
    $root . '/database/migrations/20261014_create_paymongo_qrph_transactions.sql',
    $root . '/database/migrations/20261014_add_paymongo_qrph_webhooks.sql',
    $root . '/database/migrations/20261014_add_paymongo_qrph_webhooks.sql',
];

foreach ($files as $index => $file) {
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException('Could not read ' . $file);
    }
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql);
    $applied = 0;
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '' || str_starts_with($statement, '--')) {
            continue;
        }
        $pdo->exec($statement);
        $applied++;
    }
    echo 'Applied file ' . ($index + 1) . ' (' . basename($file) . ') statements=' . $applied . PHP_EOL;
}

$status = $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = " . $pdo->quote($probeDatabase) . "
       AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'status'"
)->fetchColumn();
$link = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = " . $pdo->quote($probeDatabase) . "
       AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'paymongo_qrph_transaction_id'"
)->fetchColumn();
$events = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE TABLE_SCHEMA = " . $pdo->quote($probeDatabase) . "
       AND TABLE_NAME = 'paymongo_qrph_webhook_events'"
)->fetchColumn();

echo 'status_enum=' . $status . PHP_EOL;
echo 'payments_link_column=' . $link . PHP_EOL;
echo 'webhook_table=' . $events . PHP_EOL;

$pdo->exec('DROP DATABASE `' . $probeDatabase . '`');
echo 'Dropped scratch database.' . PHP_EOL;
