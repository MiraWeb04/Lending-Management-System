<?php
// force_delete_loans_cli.php
// CLI script to delete loans and payments (use with caution).
require_once __DIR__ . '/../includes/db_lending.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);

$loanIds = [27,28,29,30];
$placeholders = implode(',', array_fill(0, count($loanIds), '?'));

try {
    echo "Connected to DB. Previewing records to delete...\n";

    $loansStmt = executeQuery("SELECT loan_id, loan_number, client_id, loan_amount, total_payable, date_released, due_date, status FROM loans WHERE loan_id IN ($placeholders)", $loanIds);
    $paymentsStmt = executeQuery("SELECT payment_id, loan_id, payment_date, amount_paid, collector_name FROM payments WHERE loan_id IN ($placeholders) ORDER BY payment_date DESC", $loanIds);

    $loans = $loansStmt ? $loansStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $payments = $paymentsStmt ? $paymentsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    echo "Loans found: " . count($loans) . "\n";
    foreach ($loans as $l) {
        echo "loan_id={$l['loan_id']} loan_number={$l['loan_number']} client_id={$l['client_id']} amount={$l['loan_amount']} status={$l['status']}\n";
    }

    echo "Payments found: " . count($payments) . "\n";
    foreach ($payments as $p) {
        echo "payment_id={$p['payment_id']} loan_id={$p['loan_id']} date={$p['payment_date']} amount={$p['amount_paid']} collector={$p['collector_name']}\n";
    }

    // Proceed with deletion
    echo "\nProceeding to delete payments then loans...\n";
    $conn->beginTransaction();

    $delPayments = $conn->prepare("DELETE FROM payments WHERE loan_id IN ($placeholders)");
    foreach ($loanIds as $i => $id) { $delPayments->bindValue($i+1, $id, PDO::PARAM_INT); }
    $delPayments->execute();
    $paymentsDeleted = $delPayments->rowCount();

    $delLoans = $conn->prepare("DELETE FROM loans WHERE loan_id IN ($placeholders)");
    foreach ($loanIds as $i => $id) { $delLoans->bindValue($i+1, $id, PDO::PARAM_INT); }
    $delLoans->execute();
    $loansDeleted = $delLoans->rowCount();

    $conn->commit();

    echo "Deleted payments: $paymentsDeleted\n";
    echo "Deleted loans: $loansDeleted\n";
    echo "Done.\n";
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
