<?php
/**
 * Read-only environment probe. Prints engine and schema metadata only.
 * Does not print passwords, API keys, or borrower rows.
 */
require_once dirname(__DIR__, 2) . '/lending_system/includes/env_lending.php';

$host = (string)lendingEnv('DB_HOST', 'localhost');
$user = (string)lendingEnv('DB_USER', 'root');
$pass = (string)lendingEnv('DB_PASSWORD', '');
$name = (string)lendingEnv('DB_NAME', '');

$pdo = new PDO('mysql:host=' . $host . ';charset=utf8mb4', $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
$comment = (string)$pdo->query("SHOW VARIABLES LIKE 'version_comment'")->fetch(PDO::FETCH_NUM)[1];
$dbs = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);

$mode = (string)lendingEnv('PAYMONGO_MODE', '');
$secret = (string)lendingEnv('PAYMONGO_SECRET_KEY', '');
$public = (string)lendingEnv('PAYMONGO_PUBLIC_KEY', '');
$webhook = (string)lendingEnv('PAYMONGO_WEBHOOK_SECRET', '');

$prefix = static function (string $value): string {
    if ($value === '') {
        return 'empty';
    }
    if (preg_match('/^(sk|pk)_(test|live)_/', $value, $m)) {
        return $m[1] . '_' . $m[2] . '_present';
    }
    return 'present_unrecognized_prefix';
};

echo 'php=' . PHP_VERSION . PHP_EOL;
echo 'db_version=' . $version . PHP_EOL;
echo 'db_comment=' . $comment . PHP_EOL;
echo 'configured_db_name=' . $name . PHP_EOL;
echo 'password_configured=' . ($pass !== '' ? 'yes' : 'no') . PHP_EOL;
echo 'databases=' . implode(', ', $dbs) . PHP_EOL;
echo 'paymongo_mode=' . ($mode !== '' ? $mode : 'empty') . PHP_EOL;
echo 'paymongo_secret=' . $prefix($secret) . PHP_EOL;
echo 'paymongo_public=' . $prefix($public) . PHP_EOL;
echo 'paymongo_webhook_secret=' . ($webhook === '' ? 'empty' : 'present') . PHP_EOL;

function tableCounts(PDO $pdo, string $database): array
{
    $safe = str_replace('`', '', $database);
    $exists = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ' . $pdo->quote($safe))->fetchColumn();
    if ($exists !== 1) {
        return [];
    }
    $tables = $pdo->query('SHOW TABLES FROM `' . $safe . '`')->fetchAll(PDO::FETCH_COLUMN);
    $counts = [];
    foreach ($tables as $table) {
        $table = str_replace('`', '', (string)$table);
        $counts[$table] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $safe . '`.`' . $table . '`')->fetchColumn();
    }
    ksort($counts);
    return $counts;
}

$snapshot = [
    'captured_at' => date('c'),
    'lending_management' => tableCounts($pdo, 'lending_management'),
    'frams_capstone_test' => tableCounts($pdo, 'frams_capstone_test'),
];
$out = dirname(__DIR__) . '/evidence/db_row_counts_before.json';
if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0777, true);
}
file_put_contents($out, json_encode($snapshot, JSON_PRETTY_PRINT));
echo 'snapshot_tables_lending=' . count($snapshot['lending_management']) . PHP_EOL;
echo 'snapshot_tables_test=' . count($snapshot['frams_capstone_test']) . PHP_EOL;
echo 'snapshot_written=testing/evidence/db_row_counts_before.json' . PHP_EOL;
