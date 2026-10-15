<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require_once dirname(__DIR__) . '/includes/env_lending.php';
require_once dirname(__DIR__) . '/includes/paymongo_schema.php';

function paymongoMigrateUsage(): void
{
    $script = basename(__FILE__);
    fwrite(STDERR, <<<TXT
PayMongo QR Ph database setup (local XAMPP lending_management only).

Usage:
  php lending_system/tools/{$script} --inspect
  php lending_system/tools/{$script} --plan
  php lending_system/tools/{$script} --apply --backup-confirmed

Options:
  --inspect           Show connection target, validation, schema status, row counts (read-only).
  --plan              Print SQL / steps that would run (no changes).
  --apply             Apply idempotent migrations (requires --backup-confirmed).
  --backup-confirmed  You verified a backup of lending_management before --apply.

Configure DB_* in the project root .env (copy from .env.example). Never commit .env.

TXT);
}

$inspect = in_array('--inspect', $argv, true);
$plan = in_array('--plan', $argv, true);
$apply = in_array('--apply', $argv, true);
$backupConfirmed = in_array('--backup-confirmed', $argv, true);

if (!$inspect && !$plan && !$apply) {
    paymongoMigrateUsage();
    exit(1);
}

$host = (string)lendingEnv('DB_HOST', 'localhost');
$dbName = (string)lendingEnv('DB_NAME', lendingPaymongoExpectedDatabaseName());
$user = (string)lendingEnv('DB_USER', 'root');
$password = (string)lendingEnv('DB_PASSWORD', '');

try {
    $pdo = lendingPaymongoSchemaPdo($host, $dbName, $user, $password);
} catch (PDOException $exception) {
    fwrite(STDERR, "Connection failed: {$exception->getMessage()}\n");
    fwrite(STDERR, "Ensure XAMPP MySQL is running and .env DB_* matches your local lending_management database.\n");
    exit(1);
}

$validation = lendingPaymongoValidateMigrationTarget($pdo, $host, $dbName);

echo "Connection target\n";
echo "  DB_HOST: {$host}\n";
echo "  DB_NAME: {$dbName}\n";
echo "  Active:  {$validation['database']}\n";
echo '  Cursor Cloud runtime: ' . (lendingPaymongoIsCursorCloudRuntime() ? 'yes' : 'no') . "\n";

if ($inspect || $plan) {
    if (!$validation['ok']) {
        echo "\nValidation warnings (read-only inspect still shown):\n";
        foreach ($validation['errors'] as $error) {
            echo "  - {$error}\n";
        }
    }
}

if ($apply) {
    if (!$backupConfirmed) {
        fwrite(STDERR, "Refusing --apply without --backup-confirmed.\n");
        exit(1);
    }
    if (!$validation['ok']) {
        fwrite(STDERR, "Refusing --apply until the connection target is valid:\n");
        foreach ($validation['errors'] as $error) {
            fwrite(STDERR, "  - {$error}\n");
        }
        exit(1);
    }
}

$beforeCounts = [
    'loans' => lendingPaymongoSchemaCountRows($pdo, 'loans'),
    'payments' => lendingPaymongoSchemaCountRows($pdo, 'payments'),
];

if ($inspect) {
    echo "\nSchema inspection\n";
    $report = lendingPaymongoSchemaInspect($pdo);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    $verify = lendingPaymongoSchemaVerify($pdo);
    echo "\nVerification\n";
    foreach ($verify['checks'] as $label => $ok) {
        echo '  [' . ($ok ? 'PASS' : 'FAIL') . "] {$label}\n";
    }
    if (!$verify['ok']) {
        exit(1);
    }
    exit(0);
}

if ($plan) {
    echo "\nPlanned changes (idempotent; skips objects that already exist where supported)\n";
    echo "--- Migration file 1: database/migrations/20261014_create_paymongo_qrph_transactions.sql\n";
    echo "    (runs only if paymongo_qrph_transactions is missing)\n";
    echo "--- Migration file 2: database/migrations/20261014_add_paymongo_qrph_webhooks.sql\n";
    echo "    (always executed; uses IF NOT EXISTS / MODIFY for status enum)\n";
    $steps = lendingPaymongoSchemaPlan($pdo);
    foreach ($steps as $step) {
        echo $step . "\n";
    }
    echo "\nApprove by running on XAMPP after backup:\n";
    echo "  php lending_system/tools/paymongo_migrate_cli.php --apply --backup-confirmed\n";
    exit(0);
}

if ($apply) {
    echo "\nRow counts before apply — loans: {$beforeCounts['loans']}, payments: {$beforeCounts['payments']}\n";
    $applied = lendingPaymongoSchemaApply($pdo);
    echo "Applied migration steps:\n";
    foreach ($applied as $item) {
        echo "  - {$item}\n";
    }
    $afterCounts = [
        'loans' => lendingPaymongoSchemaCountRows($pdo, 'loans'),
        'payments' => lendingPaymongoSchemaCountRows($pdo, 'payments'),
    ];
    echo "Row counts after apply — loans: {$afterCounts['loans']}, payments: {$afterCounts['payments']}\n";
    if ($beforeCounts['loans'] !== $afterCounts['loans'] || $beforeCounts['payments'] !== $afterCounts['payments']) {
        fwrite(STDERR, "Unexpected change in loans/payments row counts.\n");
        exit(1);
    }
    $verify = lendingPaymongoSchemaVerify($pdo);
    foreach ($verify['checks'] as $label => $ok) {
        echo '  [' . ($ok ? 'PASS' : 'FAIL') . "] {$label}\n";
    }
    exit($verify['ok'] ? 0 : 1);
}

exit(0);
