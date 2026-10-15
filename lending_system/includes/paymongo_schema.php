<?php

declare(strict_types=1);

const LENDING_PAYMONGO_TXN_STATUSES = ['pending', 'failed', 'expired', 'succeeded', 'review_required'];

function lendingPaymongoExpectedDatabaseName(): string
{
    return 'lending_management';
}

function lendingPaymongoLocalDbHosts(): array
{
    return ['localhost', '127.0.0.1', '::1'];
}

function lendingPaymongoCoreLmsTables(): array
{
    return ['users', 'clients', 'loans', 'payments'];
}

function lendingPaymongoIsCursorCloudRuntime(): bool
{
    return getenv('CURSOR_BC_ID') !== false && getenv('CURSOR_BC_ID') !== '';
}

function lendingPaymongoSchemaPdo(string $host, string $dbName, string $user, string $password): PDO
{
    $dsn = "mysql:host={$host};dbname={$dbName};charset=utf8mb4";
    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function lendingPaymongoSchemaTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$table]);

    return (int)$stmt->fetchColumn() > 0;
}

function lendingPaymongoSchemaColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$table, $column]);

    return (int)$stmt->fetchColumn() > 0;
}

function lendingPaymongoSchemaIndexExists(PDO $pdo, string $table, string $indexName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
    );
    $stmt->execute([$table, $indexName]);

    return (int)$stmt->fetchColumn() > 0;
}

function lendingPaymongoSchemaForeignKeyExists(PDO $pdo, string $table, string $constraintName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.table_constraints
         WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ?
           AND constraint_type = \'FOREIGN KEY\''
    );
    $stmt->execute([$table, $constraintName]);

    return (int)$stmt->fetchColumn() > 0;
}

function lendingPaymongoSchemaEnumIncludes(PDO $pdo, string $table, string $column, array $requiredValues): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$table, $column]);
    $columnType = (string)$stmt->fetchColumn();
    if ($columnType === '') {
        return $requiredValues;
    }

    $missing = [];
    foreach ($requiredValues as $value) {
        if (stripos($columnType, "'" . $value . "'") === false) {
            $missing[] = $value;
        }
    }

    return $missing;
}

function lendingPaymongoSchemaCountRows(PDO $pdo, string $table): ?int
{
    if (!lendingPaymongoSchemaTableExists($pdo, $table)) {
        return null;
    }

    return (int)$pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')->fetchColumn();
}

/**
 * @return array{ok:bool, errors:string[], host:string, database:string}
 */
function lendingPaymongoValidateMigrationTarget(PDO $pdo, string $host, string $dbName): array
{
    $errors = [];
    $expectedDb = lendingPaymongoExpectedDatabaseName();

    if (strcasecmp($dbName, $expectedDb) !== 0) {
        $errors[] = 'DB_NAME must be "' . $expectedDb . '" (configured: "' . $dbName . '").';
    }

    $normalizedHost = strtolower(trim($host));
    if (!in_array($normalizedHost, lendingPaymongoLocalDbHosts(), true)) {
        $errors[] = 'DB_HOST must point to local XAMPP MySQL (localhost or 127.0.0.1). Configured host: "' . $host . '".';
    }

    $activeDb = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if (strcasecmp($activeDb, $expectedDb) !== 0) {
        $errors[] = 'Active connection database is "' . $activeDb . '", expected "' . $expectedDb . '".';
    }

    foreach (lendingPaymongoCoreLmsTables() as $table) {
        if (!lendingPaymongoSchemaTableExists($pdo, $table)) {
            $errors[] = 'Missing core LMS table `' . $table . '`. This does not look like your XAMPP lending database.';
        }
    }

    if (lendingPaymongoIsCursorCloudRuntime()) {
        $allowCloud = filter_var(getenv('LENDING_ALLOW_CLOUD_MIGRATE') ?: '0', FILTER_VALIDATE_BOOL);
        if (!$allowCloud) {
            $errors[] = 'Refusing to migrate on Cursor Cloud. Run paymongo_migrate_cli.php on your XAMPP machine against your backed-up database.';
        }
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'host' => $host,
        'database' => $activeDb,
    ];
}

/**
 * @return array<string, mixed>
 */
function lendingPaymongoSchemaInspect(PDO $pdo): array
{
    $txnStatuses = lendingPaymongoSchemaEnumIncludes(
        $pdo,
        'paymongo_qrph_transactions',
        'status',
        LENDING_PAYMONGO_TXN_STATUSES
    );

    return [
        'paymongo_qrph_transactions' => lendingPaymongoSchemaTableExists($pdo, 'paymongo_qrph_transactions'),
        'paymongo_qrph_webhook_events' => lendingPaymongoSchemaTableExists($pdo, 'paymongo_qrph_webhook_events'),
        'payments_paymongo_link_column' => lendingPaymongoSchemaColumnExists($pdo, 'payments', 'paymongo_qrph_transaction_id'),
        'payments_paymongo_link_index' => lendingPaymongoSchemaIndexExists($pdo, 'payments', 'uq_payments_paymongo_qrph_transaction_id'),
        'txn_provider_payment_id' => lendingPaymongoSchemaColumnExists($pdo, 'paymongo_qrph_transactions', 'provider_payment_id'),
        'txn_payment_id' => lendingPaymongoSchemaColumnExists($pdo, 'paymongo_qrph_transactions', 'payment_id'),
        'txn_paid_at' => lendingPaymongoSchemaColumnExists($pdo, 'paymongo_qrph_transactions', 'paid_at'),
        'txn_status_enum_complete' => $txnStatuses === [],
        'txn_missing_status_values' => $txnStatuses,
        'row_counts' => [
            'loans' => lendingPaymongoSchemaCountRows($pdo, 'loans'),
            'payments' => lendingPaymongoSchemaCountRows($pdo, 'payments'),
            'clients' => lendingPaymongoSchemaCountRows($pdo, 'clients'),
            'users' => lendingPaymongoSchemaCountRows($pdo, 'users'),
            'paymongo_qrph_transactions' => lendingPaymongoSchemaCountRows($pdo, 'paymongo_qrph_transactions'),
        ],
    ];
}

/**
 * @return string[]
 */
function lendingPaymongoSchemaPlan(PDO $pdo): array
{
    $plan = [];

    if (!lendingPaymongoSchemaTableExists($pdo, 'paymongo_qrph_transactions')) {
        $plan[] = '-- Create paymongo_qrph_transactions (from 20261014_create + webhook extensions)';
        $plan[] = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20261014_add_paymongo_qrph_webhooks.sql');
        $plan[] = '-- (Uses CREATE TABLE IF NOT EXISTS with full column set in migration step 2)';
    } else {
        $missingStatuses = lendingPaymongoSchemaEnumIncludes(
            $pdo,
            'paymongo_qrph_transactions',
            'status',
            LENDING_PAYMONGO_TXN_STATUSES
        );
        if ($missingStatuses !== []) {
            $plan[] = 'ALTER TABLE `paymongo_qrph_transactions` MODIFY COLUMN `status` ENUM(\''
                . implode('\',\'', LENDING_PAYMONGO_TXN_STATUSES)
                . '\') NOT NULL DEFAULT \'pending\';';
        }
        foreach ([
            'provider_payment_id' => 'VARCHAR(100) DEFAULT NULL AFTER `payment_method_id`',
            'payment_id' => 'INT DEFAULT NULL AFTER `provider_payment_id`',
            'paid_at' => 'DATETIME DEFAULT NULL AFTER `payment_id`',
        ] as $column => $definition) {
            if (!lendingPaymongoSchemaColumnExists($pdo, 'paymongo_qrph_transactions', $column)) {
                $plan[] = 'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `' . $column . '` ' . $definition . ';';
            }
        }
        foreach ([
            'uq_paymongo_qrph_provider_payment_id' => '(`provider_payment_id`)',
            'uq_paymongo_qrph_payment_id' => '(`payment_id`)',
        ] as $index => $cols) {
            if (!lendingPaymongoSchemaIndexExists($pdo, 'paymongo_qrph_transactions', $index)) {
                $plan[] = 'ALTER TABLE `paymongo_qrph_transactions` ADD UNIQUE INDEX `' . $index . '` ' . $cols . ';';
            }
        }
    }

    if (!lendingPaymongoSchemaTableExists($pdo, 'paymongo_qrph_webhook_events')) {
        $plan[] = 'CREATE TABLE `paymongo_qrph_webhook_events` (...); -- see migration file';
    }

    if (!lendingPaymongoSchemaColumnExists($pdo, 'payments', 'paymongo_qrph_transaction_id')) {
        $plan[] = 'ALTER TABLE `payments` ADD COLUMN `paymongo_qrph_transaction_id` BIGINT UNSIGNED DEFAULT NULL AFTER `reference_number`;';
    }
    if (!lendingPaymongoSchemaIndexExists($pdo, 'payments', 'uq_payments_paymongo_qrph_transaction_id')) {
        $plan[] = 'ALTER TABLE `payments` ADD UNIQUE INDEX `uq_payments_paymongo_qrph_transaction_id` (`paymongo_qrph_transaction_id`);';
    }

    if ($plan === [] && !lendingPaymongoSchemaTableExists($pdo, 'paymongo_qrph_transactions')) {
        $plan[] = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20261014_create_paymongo_qrph_transactions.sql');
    }

    return $plan;
}

function lendingPaymongoSchemaExec(PDO $pdo, string $sql, string $label): void
{
    try {
        $pdo->exec($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException($label . ' failed: ' . $exception->getMessage(), 0, $exception);
    }
}

function lendingPaymongoSchemaApply(PDO $pdo): array
{
    $applied = [];

    if (!lendingPaymongoSchemaTableExists($pdo, 'paymongo_qrph_transactions')) {
        $step1 = dirname(__DIR__, 2) . '/database/migrations/20261014_create_paymongo_qrph_transactions.sql';
        $sql = file_get_contents($step1);
        if ($sql === false) {
            throw new RuntimeException('Could not read ' . $step1);
        }
        lendingPaymongoSchemaExec($pdo, $sql, '20261014_create_paymongo_qrph_transactions.sql');
        $applied[] = basename($step1);
    }

    $step2 = dirname(__DIR__, 2) . '/database/migrations/20261014_add_paymongo_qrph_webhooks.sql';
    $step2Sql = file_get_contents($step2);
    if ($step2Sql === false) {
        throw new RuntimeException('Could not read ' . $step2);
    }
    lendingPaymongoSchemaExec($pdo, $step2Sql, '20261014_add_paymongo_qrph_webhooks.sql');
    $applied[] = basename($step2);

    if (lendingPaymongoSchemaTableExists($pdo, 'paymongo_qrph_webhook_events')
        && lendingPaymongoSchemaTableExists($pdo, 'paymongo_qrph_transactions')
        && !lendingPaymongoSchemaForeignKeyExists($pdo, 'paymongo_qrph_webhook_events', 'fk_paymongo_qrph_webhook_transaction')) {
        lendingPaymongoSchemaExec(
            $pdo,
            'ALTER TABLE `paymongo_qrph_webhook_events`
             ADD CONSTRAINT `fk_paymongo_qrph_webhook_transaction`
             FOREIGN KEY (`transaction_id`) REFERENCES `paymongo_qrph_transactions` (`id`)
             ON DELETE SET NULL',
            'fk_paymongo_qrph_webhook_transaction'
        );
        $applied[] = 'fk_paymongo_qrph_webhook_transaction (repair)';
    }

    return array_values(array_unique($applied));
}

/**
 * @return array{ok:bool, checks:array<string,bool>}
 */
function lendingPaymongoSchemaVerify(PDO $pdo): array
{
    $missingStatuses = lendingPaymongoSchemaEnumIncludes(
        $pdo,
        'paymongo_qrph_transactions',
        'status',
        LENDING_PAYMONGO_TXN_STATUSES
    );

    $checks = [
        'paymongo_qrph_transactions' => lendingPaymongoSchemaTableExists($pdo, 'paymongo_qrph_transactions'),
        'paymongo_qrph_webhook_events' => lendingPaymongoSchemaTableExists($pdo, 'paymongo_qrph_webhook_events'),
        'payments.paymongo_qrph_transaction_id' => lendingPaymongoSchemaColumnExists($pdo, 'payments', 'paymongo_qrph_transaction_id'),
        'payments.uq_paymongo_qrph_transaction_id' => lendingPaymongoSchemaIndexExists($pdo, 'payments', 'uq_payments_paymongo_qrph_transaction_id'),
        'txn.provider_payment_id' => lendingPaymongoSchemaColumnExists($pdo, 'paymongo_qrph_transactions', 'provider_payment_id'),
        'txn.payment_id' => lendingPaymongoSchemaColumnExists($pdo, 'paymongo_qrph_transactions', 'payment_id'),
        'txn.paid_at' => lendingPaymongoSchemaColumnExists($pdo, 'paymongo_qrph_transactions', 'paid_at'),
        'txn.status_enum' => $missingStatuses === [],
    ];

    return [
        'ok' => !in_array(false, $checks, true),
        'checks' => $checks,
        'missing_status_values' => $missingStatuses,
    ];
}
