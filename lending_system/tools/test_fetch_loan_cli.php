<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found'); }
require_once __DIR__ . '/../includes/db_lending.php';
require_once __DIR__ . '/../includes/loan_helpers.php';
$loanId = 19;
$loanQuery = "
    SELECT l.loan_id,
           l.loan_number,
           l.loan_amount,
           l.interest,
           l.total_payable,
           l.due_date,
           l.date_released,
           l.payment_frequency,
           l.status,
           l.client_id,
           c.first_name,
           c.last_name,
           COALESCE(SUM(p.amount_paid), 0) AS amount_paid,
           (l.total_payable - COALESCE(SUM(p.amount_paid), 0)) AS remaining_balance
    FROM loans l
    JOIN clients c ON l.client_id = c.client_id
    LEFT JOIN payments p ON l.loan_id = p.loan_id
    WHERE l.loan_id = ?
    GROUP BY l.loan_id LIMIT 1
";
$loanResult = executeQuery($loanQuery, [$loanId]);
$loan = $loanResult->fetch(PDO::FETCH_ASSOC);
var_export($loan !== false);
echo "\n";
if ($loan) {
    $balanceSummary = calculateLoanBalanceSummary((int)$loan['loan_id']);
    echo json_encode(array_merge($loan, $balanceSummary), JSON_PRETTY_PRINT);
}
