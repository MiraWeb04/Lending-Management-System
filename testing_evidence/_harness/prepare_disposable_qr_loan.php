<?php
/**
 * Adds one unpaid disposable loan inside frams_capstone_test only.
 */
$sourceName = 'lending_management';
$testName = 'frams_capstone_test';
$root = dirname(__DIR__, 2);
require_once $root . '/lending_system/includes/env_lending.php';

$host = (string)lendingEnv('DB_HOST', 'localhost');
$user = (string)lendingEnv('DB_USER', 'root');
$pass = (string)lendingEnv('DB_PASSWORD', '');
$pdo = new PDO("mysql:host={$host};dbname={$testName};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$before = (int)$pdo->query('SELECT COUNT(*) FROM `' . $sourceName . '`.loans')->fetchColumn();
$paymentsBefore = (int)$pdo->query('SELECT COUNT(*) FROM `' . $sourceName . '`.payments')->fetchColumn();

$collector = (int)$pdo->query("SELECT user_id FROM users WHERE username = 'frams_test_collector'")->fetchColumn();
$borrower = (int)$pdo->query("SELECT user_id FROM users WHERE username = 'frams_test_borrower'")->fetchColumn();
$client = (int)$pdo->query('SELECT client_id FROM clients WHERE user_id = ' . $borrower)->fetchColumn();
if ($collector <= 0 || $borrower <= 0 || $client <= 0) {
    fwrite(STDERR, "Synthetic accounts are missing from the test database.\n");
    exit(1);
}

$existing = $pdo->query("SELECT loan_id FROM loans WHERE loan_number = 'FRAMS-DISP-01'")->fetchColumn();
if (!$existing) {
    $pdo->prepare(
        'INSERT INTO loan_applications (user_id, borrower_name, employment_status, monthly_gross_income, loan_amount, loan_term, status, payment_frequency)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$borrower, 'Synthetic BorrowerA', 'Employed', 30000, 3000, '1 month', 'Released', 'Monthly']);
    $applicationId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO loan_releases (application_id, borrower_user_id, collector_user_id, loan_number, loan_amount, interest_rate, loan_term, payment_frequency, release_status, released_at, released_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
    )->execute([$applicationId, $borrower, $collector, 'FRAMS-DISP-01', 3000, 5, '1 month', 'Monthly', 'Released', 'FRAMS Test Admin']);
    $releaseId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO loans (client_id, loan_amount, interest, total_payable, daily_payment, date_released, due_date, status, collector_id, loan_number, release_id, payment_frequency, loan_term, agreement_status, released_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$client, 3000, 150, 3150, 3150, '2026-10-18', '2026-11-18', 'Active', $collector, 'FRAMS-DISP-01', $releaseId, 'Monthly', '1 month', 'Accepted', 'FRAMS Test Admin']);
    $loanId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO loan_payment_schedules (release_id, installment_number, due_date, principal_amount, interest_amount, total_amount_due, status)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$releaseId, 1, '2026-11-18', 3000, 150, 3150, 'Upcoming']);
} else {
    $loanId = (int)$existing;
}

$after = (int)$pdo->query('SELECT COUNT(*) FROM `' . $sourceName . '`.loans')->fetchColumn();
$paymentsAfter = (int)$pdo->query('SELECT COUNT(*) FROM `' . $sourceName . '`.payments')->fetchColumn();
if ($before !== $after || $paymentsBefore !== $paymentsAfter) {
    fwrite(STDERR, "Source database changed. Aborting.\n");
    exit(1);
}

$paid = $pdo->prepare('SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE loan_id = ?');
$paid->execute([$loanId]);
echo 'DISP_LOAN=' . $loanId . ' PAID=' . $paid->fetchColumn() . ' SOURCE_LOANS=' . $after . PHP_EOL;
