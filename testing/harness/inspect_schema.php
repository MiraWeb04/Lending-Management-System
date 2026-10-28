<?php
/**
 * Read-only schema inspection. Prints metadata, never row contents.
 */
require_once dirname(__DIR__, 2) . '/lending_system/includes/env_lending.php';

$host = (string)lendingEnv('DB_HOST', 'localhost');
$user = (string)lendingEnv('DB_USER', 'root');
$pass = (string)lendingEnv('DB_PASSWORD', '');
$pdo = new PDO('mysql:host=' . $host . ';charset=utf8mb4', $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
$required = [
    'users', 'clients', 'loans', 'payments', 'expenses', 'notifications',
    'borrower_applications', 'loan_applications', 'loan_application_documents',
    'loan_application_reviews', 'loan_agreements', 'loan_releases',
    'loan_payment_schedules', 'password_resets', 'paymongo_qrph_transactions',
    'paymongo_qrph_webhook_events',
];

function schemaReport(PDO $pdo, string $database, array $required): array
{
    $tables = $pdo->query('SHOW TABLES FROM `' . $database . '`')->fetchAll(PDO::FETCH_COLUMN);
    $tables = array_map(static fn($t) => (string)$t, $tables);
    sort($tables);
    $missing = array_values(array_diff($required, $tables));
    $fk = $pdo->prepare(
        'SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL
         ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION'
    );
    $fk->execute([$database]);
    $foreignKeys = $fk->fetchAll(PDO::FETCH_ASSOC);
    $indexes = $pdo->prepare(
        'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (' . implode(',', array_fill(0, count($required), '?')) . ')
         GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE
         ORDER BY TABLE_NAME, INDEX_NAME'
    );
    $indexes->execute(array_merge([$database], $required));
    $paymongoColumns = $pdo->prepare(
        'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ("paymongo_qrph_transactions", "paymongo_qrph_webhook_events", "payments", "loans")
         ORDER BY TABLE_NAME, ORDINAL_POSITION'
    );
    $paymongoColumns->execute([$database]);
    return [
        'tables' => $tables,
        'missing_required' => $missing,
        'foreign_keys' => $foreignKeys,
        'indexes_on_required_tables' => $indexes->fetchAll(PDO::FETCH_ASSOC),
        'selected_columns' => $paymongoColumns->fetchAll(PDO::FETCH_ASSOC),
    ];
}

$report = [
    'engine' => $version,
    'migration_files' => [
        'database/migrations/20261014_create_paymongo_qrph_transactions.sql',
        'database/migrations/20261014_add_paymongo_qrph_webhooks.sql',
    ],
    'lending_management' => schemaReport($pdo, 'lending_management', $required),
    'frams_capstone_test' => schemaReport($pdo, 'frams_capstone_test', $required),
];
$before = json_decode((string)file_get_contents(dirname(__DIR__) . '/evidence/db_row_counts_before.json'), true);
$after = [];
foreach (array_keys($before['lending_management']) as $table) {
    $safe = str_replace('`', '', $table);
    $after[$safe] = (int)$pdo->query('SELECT COUNT(*) FROM `lending_management`.`' . $safe . '`')->fetchColumn();
}
$report['lending_management_row_counts_unchanged'] = $before['lending_management'] === $after;
$report['lending_management_row_count_differences'] = [];
foreach ($after as $table => $count) {
    $prior = $before['lending_management'][$table] ?? null;
    if ($prior !== $count) {
        $report['lending_management_row_count_differences'][$table] = ['before' => $prior, 'after' => $count];
    }
}
file_put_contents(dirname(__DIR__) . '/evidence/schema_inspection.json', json_encode($report, JSON_PRETTY_PRINT));
echo 'engine=' . $version . PHP_EOL;
echo 'production_missing=' . json_encode($report['lending_management']['missing_required']) . PHP_EOL;
echo 'test_missing=' . json_encode($report['frams_capstone_test']['missing_required']) . PHP_EOL;
echo 'production_fk_count=' . count($report['lending_management']['foreign_keys']) . PHP_EOL;
echo 'production_unchanged=' . ($report['lending_management_row_counts_unchanged'] ? 'yes' : 'no') . PHP_EOL;
echo 'differences=' . json_encode($report['lending_management_row_count_differences']) . PHP_EOL;
