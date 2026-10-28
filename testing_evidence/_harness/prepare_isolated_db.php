<?php
/**
 * Creates frams_capstone_test from the structure of lending_management.
 * Does not copy, update, or delete rows in lending_management.
 */
$sourceName = 'lending_management';
$testName = 'frams_capstone_test';
if ($testName === $sourceName) {
    fwrite(STDERR, "Refusing to use the source database as the test database.\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require_once $root . '/lending_system/includes/env_lending.php';

$host = (string)lendingEnv('DB_HOST', 'localhost');
$user = (string)lendingEnv('DB_USER', 'root');
$pass = (string)lendingEnv('DB_PASSWORD', '');
$configured = (string)lendingEnv('DB_NAME', $sourceName);
if ($configured !== $sourceName) {
    fwrite(STDERR, "Configured database name is not the expected source database. Aborting.\n");
    exit(1);
}

$pdo = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$before = [];
$tables = $pdo->query('SHOW TABLES FROM `' . $sourceName . '`')->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    $safe = str_replace('`', '', (string)$table);
    $before[$safe] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $sourceName . '`.`' . $safe . '`')->fetchColumn();
}

$pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $testName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo->exec('USE `' . $testName . '`');
$existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($existing as $table) {
    $safe = str_replace('`', '', (string)$table);
    $pdo->exec('DROP TABLE IF EXISTS `' . $safe . '`');
}
foreach ($tables as $table) {
    $safe = str_replace('`', '', (string)$table);
    $create = $pdo->query('SHOW CREATE TABLE `' . $sourceName . '`.`' . $safe . '`')->fetch(PDO::FETCH_ASSOC);
    $sql = $create['Create Table'] ?? $create['Create View'] ?? '';
    if ($sql === '') {
        throw new RuntimeException('Could not read structure for ' . $safe);
    }
    $pdo->exec($sql);
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$after = [];
foreach ($tables as $table) {
    $safe = str_replace('`', '', (string)$table);
    $after[$safe] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $sourceName . '`.`' . $safe . '`')->fetchColumn();
}
if ($before !== $after) {
    fwrite(STDERR, "Source database row counts changed during structure clone. Aborting seed.\n");
    exit(1);
}

$password = 'Test@Frams1';
$hash = password_hash($password, PASSWORD_DEFAULT);
$insertUser = $pdo->prepare(
    'INSERT INTO users (username, password, full_name, email, role, status) VALUES (?, ?, ?, NULL, ?, ?)'
);
$accounts = [
    ['frams_test_admin', 'FRAMS Test Admin', 'Admin', 'Active'],
    ['frams_test_collector', 'FRAMS Test Collector', 'Collector', 'Active'],
    ['frams_test_collector_b', 'FRAMS Test Collector B', 'Collector', 'Active'],
    ['frams_test_borrower', 'FRAMS Test Borrower', 'Borrower', 'Active'],
    ['frams_test_borrower_b', 'FRAMS Test Borrower B', 'Borrower', 'Active'],
    ['frams_test_inactive', 'FRAMS Test Inactive', 'Admin', 'Inactive'],
];
$ids = [];
foreach ($accounts as [$username, $fullName, $role, $status]) {
    $insertUser->execute([$username, $hash, $fullName, $role, $status]);
    $ids[$username] = (int)$pdo->lastInsertId();
}

$insertClient = $pdo->prepare(
    'INSERT INTO clients (user_id, first_name, last_name, address, contact, email, collector_id, date_registered)
     VALUES (?, ?, ?, ?, ?, NULL, ?, CURDATE())'
);
$insertClient->execute([
    $ids['frams_test_borrower'], 'Synthetic', 'BorrowerA', 'Test Address A', '09000000001', $ids['frams_test_collector'],
]);
$clientA = (int)$pdo->lastInsertId();
$insertClient->execute([
    $ids['frams_test_borrower_b'], 'Synthetic', 'BorrowerB', 'Test Address B', '09000000002', $ids['frams_test_collector_b'],
]);
$clientB = (int)$pdo->lastInsertId();

$insertApp = $pdo->prepare(
    'INSERT INTO loan_applications (user_id, borrower_name, employment_status, monthly_gross_income, loan_amount, loan_term, status, payment_frequency)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$insertRelease = $pdo->prepare(
    'INSERT INTO loan_releases (application_id, borrower_user_id, collector_user_id, loan_number, loan_amount, interest_rate, loan_term, payment_frequency, release_status, released_at, released_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
);
$insertLoan = $pdo->prepare(
    'INSERT INTO loans (client_id, loan_amount, interest, total_payable, daily_payment, date_released, due_date, status, collector_id, loan_number, release_id, payment_frequency, loan_term, agreement_status, released_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$insertSchedule = $pdo->prepare(
    'INSERT INTO loan_payment_schedules (release_id, installment_number, due_date, principal_amount, interest_amount, total_amount_due, status)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);

$makeLoan = function (array $spec) use ($pdo, $insertApp, $insertRelease, $insertLoan, $insertSchedule, $ids): array {
    $insertApp->execute([
        $spec['borrower_id'],
        $spec['borrower_name'],
        'Employed',
        30000,
        $spec['principal'],
        $spec['term_label'],
        'Released',
        'Monthly',
    ]);
    $applicationId = (int)$pdo->lastInsertId();
    $insertRelease->execute([
        $applicationId,
        $spec['borrower_id'],
        $spec['collector_id'],
        $spec['loan_number'],
        $spec['principal'],
        5,
        $spec['term_label'],
        'Monthly',
        'Released',
        'FRAMS Test Admin',
    ]);
    $releaseId = (int)$pdo->lastInsertId();
    $insertLoan->execute([
        $spec['client_id'],
        $spec['principal'],
        $spec['total_interest'],
        $spec['total_payable'],
        $spec['installment'],
        '2026-10-18',
        $spec['final_due'],
        'Active',
        $spec['collector_id'],
        $spec['loan_number'],
        $releaseId,
        'Monthly',
        $spec['term_label'],
        'Accepted',
        'FRAMS Test Admin',
    ]);
    $loanId = (int)$pdo->lastInsertId();
    $installmentNumber = 1;
    foreach ($spec['schedule'] as $row) {
        $insertSchedule->execute([
            $releaseId,
            $installmentNumber,
            $row['due'],
            $row['principal'],
            $row['interest'],
            $row['total'],
            'Upcoming',
        ]);
        $installmentNumber++;
    }

    return ['loan_id' => $loanId, 'release_id' => $releaseId, 'application_id' => $applicationId, 'client_id' => $spec['client_id']];
};

$loanA = $makeLoan([
    'borrower_id' => $ids['frams_test_borrower'],
    'collector_id' => $ids['frams_test_collector'],
    'client_id' => $clientA,
    'borrower_name' => 'Synthetic BorrowerA',
    'loan_number' => 'FRAMS-TEST-A',
    'principal' => 10000,
    'total_interest' => 1500,
    'total_payable' => 11500,
    'installment' => 3833.33,
    'term_label' => '3 months',
    'final_due' => '2027-01-18',
    'schedule' => [
        ['due' => '2026-11-18', 'principal' => 3333.33, 'interest' => 500.00, 'total' => 3833.33],
        ['due' => '2026-12-18', 'principal' => 3333.33, 'interest' => 500.00, 'total' => 3833.33],
        ['due' => '2027-01-18', 'principal' => 3333.34, 'interest' => 500.00, 'total' => 3833.34],
    ],
]);
$loanB = $makeLoan([
    'borrower_id' => $ids['frams_test_borrower_b'],
    'collector_id' => $ids['frams_test_collector_b'],
    'client_id' => $clientB,
    'borrower_name' => 'Synthetic BorrowerB',
    'loan_number' => 'FRAMS-TEST-B',
    'principal' => 5000,
    'total_interest' => 500,
    'total_payable' => 5500,
    'installment' => 2750,
    'term_label' => '2 months',
    'final_due' => '2026-12-18',
    'schedule' => [
        ['due' => '2026-11-18', 'principal' => 2500, 'interest' => 250, 'total' => 2750],
        ['due' => '2026-12-18', 'principal' => 2500, 'interest' => 250, 'total' => 2750],
    ],
]);

$finalSource = [];
foreach ($tables as $table) {
    $safe = str_replace('`', '', (string)$table);
    $finalSource[$safe] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $sourceName . '`.`' . $safe . '`')->fetchColumn();
}
if ($finalSource !== $before) {
    fwrite(STDERR, "Source database row counts changed during seed. Investigate before continuing.\n");
    exit(1);
}

$testCounts = [];
foreach ($tables as $table) {
    $safe = str_replace('`', '', (string)$table);
    $testCounts[$safe] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $testName . '`.`' . $safe . '`')->fetchColumn();
}

$manifest = [
    'source_database' => $sourceName,
    'test_database' => $testName,
    'source_row_counts_before' => $before,
    'source_row_counts_after' => $finalSource,
    'source_unchanged' => true,
    'test_row_counts' => $testCounts,
    'users' => $ids,
    'clients' => ['a' => $clientA, 'b' => $clientB],
    'loans' => ['a' => $loanA, 'b' => $loanB],
    'password_note' => 'Synthetic password is stored only in this harness, not printed here.',
];
file_put_contents(__DIR__ . '/seed_manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
echo "Isolated database ready. Source unchanged. Test users=" . count($ids) . " loans=2\n";
