<?php
require_once dirname(__DIR__, 2) . '/lending_system/includes/env_lending.php';
$pdo = new PDO('mysql:host=localhost;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$before = json_decode((string)file_get_contents(__DIR__ . '/seed_manifest.json'), true);
$mismatch = 0;
foreach ($before['source_row_counts_before'] as $table => $count) {
    $now = (int)$pdo->query('SELECT COUNT(*) FROM `lending_management`.`' . str_replace('`', '', $table) . '`')->fetchColumn();
    if ($now !== (int)$count) {
        echo "MISMATCH {$table} was {$count} now {$now}\n";
        $mismatch++;
    }
}
$beforeStatus = (string)$pdo->query("SELECT status FROM frams_capstone_test.loans WHERE loan_number = 'FRAMS-TEST-A'")->fetchColumn();
$pdo->exec("UPDATE frams_capstone_test.loans SET status = 'Active' WHERE loan_number = 'FRAMS-TEST-A'");
$afterStatus = (string)$pdo->query("SELECT status FROM frams_capstone_test.loans WHERE loan_number = 'FRAMS-TEST-A'")->fetchColumn();
echo "SOURCE_MISMATCHES={$mismatch}\n";
echo "LOAN_A {$beforeStatus} -> {$afterStatus}\n";
file_put_contents(__DIR__ . '/source_verification.txt', "SOURCE_MISMATCHES={$mismatch}\nLOAN_A_TEST_DB {$beforeStatus} restored to {$afterStatus}\n");
