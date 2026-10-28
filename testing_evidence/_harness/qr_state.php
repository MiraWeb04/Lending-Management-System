<?php
require __DIR__ . '/env_prepend.php';
$pdo = new PDO('mysql:host=localhost;dbname=frams_capstone_test;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$loanId = (int)($argv[1] ?? 31);
echo "LOAN {$loanId}\n";
$paid = $pdo->prepare('SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE loan_id = ?');
$paid->execute([$loanId]);
$status = $pdo->prepare('SELECT status, total_payable FROM loans WHERE loan_id = ?');
$status->execute([$loanId]);
$loan = $status->fetch(PDO::FETCH_ASSOC);
echo 'STATUS=' . $loan['status'] . ' PAYABLE=' . $loan['total_payable'] . ' PAID=' . $paid->fetchColumn() . PHP_EOL;
$rows = $pdo->prepare('SELECT id, amount, status, provider_status, CASE WHEN qr_image_url IS NULL OR qr_image_url = "" THEN 0 ELSE 1 END AS has_qr FROM paymongo_qrph_transactions WHERE loan_id = ? ORDER BY id');
$rows->execute([$loanId]);
foreach ($rows as $row) {
    echo implode(' | ', $row) . PHP_EOL;
}
echo 'SOURCE_PAYMENTS=' . $pdo->query('SELECT COUNT(*) FROM lending_management.payments')->fetchColumn() . PHP_EOL;
echo 'SOURCE_LOANS=' . $pdo->query('SELECT COUNT(*) FROM lending_management.loans')->fetchColumn() . PHP_EOL;
