<?php
/**
 * Tool: Fix Loan Due Dates
 * Updates loans.due_date to match the maximum due_date from
 * loan_payment_schedules for the corresponding release_id.
 *
 * Usage (CLI):
 *   php fix_loan_due_dates.php
 *
 * Usage (web):
 *   Place the file in the tools/ folder and access via browser (admin only).
 */

require_once __DIR__ . '/../includes/auth_lending.php';
require_once __DIR__ . '/../includes/db_lending.php';

requireAdmin();

try {
    // Update loans due_date from schedules
    $updateSql = "UPDATE loans l
        JOIN (
            SELECT release_id, MAX(due_date) AS final_due
            FROM loan_payment_schedules
            GROUP BY release_id
        ) s ON l.release_id = s.release_id
        SET l.due_date = s.final_due
        WHERE l.release_id IS NOT NULL AND (l.due_date IS NULL OR l.due_date != s.final_due)";

    $stmt = executeQuery($updateSql, []);
    $affected = $stmt->rowCount();

    // Report specific loan
    $targetLoan = 'RJRR-LOAN-2026-000013';
    $loanRow = executeQuery('SELECT loan_id, loan_number, date_released, due_date, release_id FROM loans WHERE loan_number = ? LIMIT 1', [$targetLoan])->fetch(PDO::FETCH_ASSOC);

    header('Content-Type: text/plain; charset=utf-8');
    echo "Fix Loan Due Dates Tool\n";
    echo "----------------------\n";
    echo "Updated loans: " . (int)$affected . "\n\n";

    if ($loanRow) {
        echo "Loan: " . ($loanRow['loan_number'] ?? '') . " (ID: " . ($loanRow['loan_id'] ?? '') . ")\n";
        echo "Date Released: " . ($loanRow['date_released'] ?? 'N/A') . "\n";
        echo "Due Date: " . ($loanRow['due_date'] ?? 'N/A') . "\n";
        echo "Release ID: " . ($loanRow['release_id'] ?? 'N/A') . "\n";
        // show schedule final for that release
        if (!empty($loanRow['release_id'])) {
            $sched = executeQuery('SELECT MAX(due_date) AS final_due FROM loan_payment_schedules WHERE release_id = ? LIMIT 1', [(int)$loanRow['release_id']])->fetch(PDO::FETCH_ASSOC);
            echo "Schedule Final Due: " . ($sched['final_due'] ?? 'N/A') . "\n";
        }
    } else {
        echo "Loan {$targetLoan} not found in loans table.\n";
    }

    echo "\nDone.\n";
} catch (Exception $e) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Error: " . $e->getMessage();
}

