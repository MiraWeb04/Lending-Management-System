<?php
require __DIR__ . '/env_prepend.php';
$pdo = new PDO('mysql:host=localhost;dbname=frams_capstone_test;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$loanId = 29;
$collector = (int)$pdo->query("SELECT user_id FROM users WHERE username = 'frams_test_collector'")->fetchColumn();
$client = (int)$pdo->query('SELECT client_id FROM loans WHERE loan_id = 29')->fetchColumn();
$borrower = (int)$pdo->query("SELECT user_id FROM users WHERE username = 'frams_test_borrower'")->fetchColumn();
$pdo->prepare(
    'INSERT INTO paymongo_qrph_transactions
        (internal_reference, payment_intent_id, loan_id, client_id, borrower_user_id, initiated_by_user_id, collector_id, selected_method, amount, penalty_paid, currency, status, provider_status, expires_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, "PHP", "pending", "awaiting_next_action", DATE_ADD(NOW(), INTERVAL 30 MINUTE))'
)->execute(['QRPH-PM08-OPEN', 'pi_frams_unconfirmed_29', $loanId, $client, $borrower, $collector, $collector, 'E-Wallet', '100.00']);
echo 'PENDING_ID=' . $pdo->lastInsertId() . PHP_EOL;
echo 'SOURCE_PAYMENTS=' . $pdo->query('SELECT COUNT(*) FROM lending_management.payments')->fetchColumn() . PHP_EOL;
