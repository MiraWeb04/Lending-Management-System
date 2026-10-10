<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found'); }
require_once __DIR__ . '/../includes/db_lending.php';
require_once __DIR__ . '/../includes/loan_helpers.php';
$id = (int)($argv[1] ?? 19);
$summary = calculateLoanBalanceSummary($id);
print_r($summary);
$sched = executeQuery("SELECT installment_number, due_date, status, total_amount_due FROM loan_payment_schedules WHERE release_id = (SELECT release_id FROM loans WHERE loan_id = ?) ORDER BY installment_number", [$id])->fetchAll(PDO::FETCH_ASSOC);
print_r($sched);
