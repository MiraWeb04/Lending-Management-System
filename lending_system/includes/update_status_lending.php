<?php
/**
 * Mark installment rows and loan accounts Paid or Overdue.
 * Used by the daily cron script and, when enabled, by LOAN_STATUS_SYNC_ON_REQUEST.
 */

function syncLendingLoanStatuses(): array
{
    $counts = ['checked' => 0, 'paid' => 0, 'overdue' => 0, 'active' => 0];
    $stmt = executeQuery("SELECT loan_id FROM loans WHERE status IN ('Active', 'Overdue')");
    if (!$stmt) {
        return $counts;
    }

    $loanIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $counts['checked'] = count($loanIds);

    foreach ($loanIds as $loanId) {
        $loanId = (int)$loanId;
        if ($loanId <= 0) {
            continue;
        }

        recalculateLoanPaymentSchedule($loanId);
        updateLoanStatusForLoan($loanId);

        $statusStmt = executeQuery('SELECT status FROM loans WHERE loan_id = ? LIMIT 1', [$loanId]);
        $status = strtolower(trim((string)($statusStmt ? $statusStmt->fetchColumn() : '')));
        if ($status === 'paid') {
            $counts['paid']++;
        } elseif ($status === 'overdue') {
            $counts['overdue']++;
        } elseif ($status === 'active') {
            $counts['active']++;
        }
    }

    return $counts;
}
