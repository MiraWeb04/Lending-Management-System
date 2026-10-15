<?php
/**
 * Daily loan status sync. Marks past-due installments Overdue and fully
 * paid loans Paid. Schedule this once per day; page requests do not run it
 * unless LOAN_STATUS_SYNC_ON_REQUEST=1.
 *
 *   php lending_system/tools/sync_loan_status_cli.php
 *
 * Linux cron, every day at 12:05 AM:
 *   5 0 * * * php /path/to/lending_system/lending_system/tools/sync_loan_status_cli.php
 *
 * Windows Task Scheduler:
 *   Program: C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\lending_system\lending_system\tools\sync_loan_status_cli.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require_once dirname(__DIR__) . '/includes/db_lending.php';
require_once dirname(__DIR__) . '/includes/update_status_lending.php';

$counts = syncLendingLoanStatuses();

echo 'Loan status sync complete.' . PHP_EOL;
echo 'Checked: ' . (int)$counts['checked'] . PHP_EOL;
echo 'Active: ' . (int)$counts['active'] . PHP_EOL;
echo 'Overdue: ' . (int)$counts['overdue'] . PHP_EOL;
echo 'Paid: ' . (int)$counts['paid'] . PHP_EOL;
