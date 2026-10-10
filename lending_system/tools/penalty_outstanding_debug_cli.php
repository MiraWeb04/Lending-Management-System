<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found'); }
require_once __DIR__ . '/../includes/db_lending.php';
require_once __DIR__ . '/../includes/loan_helpers.php';

$loans = executeQuery("SELECT loan_id, loan_number, status, release_id FROM loans ORDER BY loan_id")->fetchAll(PDO::FETCH_ASSOC);
echo "All loans penalty check:\n";
foreach ($loans as $l) {
    $id = (int)$l['loan_id'];
    $summary = calculateLoanBalanceSummary($id);
    $due = (float)$summary['penalty_due'];
    $paid = (float)$summary['penalty_paid'];
    if ($due > 0 || $paid > 0 || stripos($l['status'], 'overdue') !== false) {
        echo "#{$id} {$l['loan_number']} status={$l['status']} release={$l['release_id']} penalty_due={$due} penalty_paid={$paid}\n";
        if (!empty($l['release_id'])) {
            $sched = executeQuery(
                'SELECT installment_number, due_date, status, total_amount_due FROM loan_payment_schedules WHERE release_id = ? ORDER BY installment_number',
                [(int)$l['release_id']]
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($sched as $s) {
                echo "  inst {$s['installment_number']} {$s['due_date']} {$s['status']} amt={$s['total_amount_due']}\n";
            }
        }
    }
}
$rows = fetchBorrowersWithOutstandingPenalties();
echo "\nfetchBorrowersWithOutstandingPenalties count=" . count($rows) . " total=" . array_sum(array_column($rows, 'penalty_due')) . "\n";
