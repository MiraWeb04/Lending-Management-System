<?php
/**
 * Independent financial-computation checks for FC-01 through FC-10.
 * Expected amounts are calculated in this file with BCMath.
 * Application functions are called only to obtain the actual result.
 *
 * Run with the isolated prepend so the connection is frams_capstone_test:
 * php -d auto_prepend_file=testing_evidence/_harness/env_prepend.php testing/harness/run_fc_validation.php
 */

if (!defined('FRAMS_TEST_PREPEND_LOADED')) {
    fwrite(STDERR, "Refusing to run without the isolated test prepend.\n");
    exit(1);
}

require_once dirname(__DIR__, 2) . '/lending_system/includes/db_lending.php';
require_once dirname(__DIR__, 2) . '/lending_system/includes/loan_helpers.php';
require_once dirname(__DIR__, 2) . '/lending_system/includes/loan_eligibility_helpers.php';

global $conn;
$connected = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
if ($connected !== 'frams_capstone_test') {
    fwrite(STDERR, "Refusing to run. Connected database is not the isolated test database.\n");
    exit(1);
}

function fcExtractFunction(string $source, string $name): string
{
    $needle = 'function ' . $name;
    $pos = strpos($source, $needle);
    if ($pos === false) {
        throw new RuntimeException('Function not found: ' . $name);
    }
    $brace = strpos($source, '{', $pos);
    $depth = 0;
    $length = strlen($source);
    for ($i = $brace; $i < $length; $i++) {
        $char = $source[$i];
        if ($char === '{') {
            $depth++;
        } elseif ($char === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $pos, $i - $pos + 1);
            }
        }
    }
    throw new RuntimeException('Unclosed function: ' . $name);
}

$releaseSource = file_get_contents(dirname(__DIR__, 2) . '/lending_system/loan_release_lending.php');
eval(fcExtractFunction($releaseSource, 'buildPaymentSchedule'));

function fcRoundHalfUp(string $value): string
{
    $negative = strncmp($value, '-', 1) === 0;
    $absolute = ltrim($value, '+-');
    if (strpos($absolute, '.') === false) {
        $absolute .= '.00000000';
    }
    $rounded = bcadd($absolute, '0.005', 2);
    return $negative ? '-' . $rounded : $rounded;
}

function fcInterest(string $principal, string $ratePercent, int $months): array
{
    $monthly = bcdiv(bcmul($principal, $ratePercent, 12), '100', 12);
    $totalInterest = bcmul($monthly, (string)$months, 12);
    $payable = bcadd($principal, $totalInterest, 12);

    return [
        'monthly_interest' => fcRoundHalfUp($monthly),
        'total_interest' => fcRoundHalfUp($totalInterest),
        'total_payable' => fcRoundHalfUp($payable),
        'steps' => [
            'monthly_interest_unrounded' => $monthly,
            'total_interest_unrounded' => $totalInterest,
            'total_payable_unrounded' => $payable,
        ],
    ];
}

function fcInstallmentCount(string $frequency, int $months): int
{
    switch ($frequency) {
        case 'daily':
            return $months * 30;
        case 'weekly':
            return $months * 4;
        case 'semi-monthly':
            return $months * 2;
        default:
            return $months;
    }
}

function fcSchedule(string $principal, string $ratePercent, int $months, string $frequency, string $releaseDate): array
{
    $interest = fcInterest($principal, $ratePercent, $months);
    $count = fcInstallmentCount($frequency, $months);
    $principalCents = (int) bcmul(fcRoundHalfUp($principal), '100', 0);
    $interestCents = (int) bcmul($interest['total_interest'], '100', 0);
    $payableCents = (int) bcmul($interest['total_payable'], '100', 0);
    $principalBase = intdiv($principalCents, $count);
    $interestBase = intdiv($interestCents, $count);
    $cursor = new DateTimeImmutable($releaseDate);
    $rows = [];
    $usedPrincipal = 0;
    $usedInterest = 0;
    $usedPayable = 0;
    for ($i = 1; $i <= $count; $i++) {
        if ($frequency === 'daily') {
            $cursor = $cursor->modify('+1 day');
        } elseif ($frequency === 'weekly') {
            $cursor = $cursor->modify('+7 days');
        } elseif ($frequency === 'semi-monthly') {
            $cursor = $cursor->modify('+15 days');
        } else {
            $cursor = $cursor->modify('+1 month');
        }
        $last = $i === $count;
        if ($last) {
            $principalRow = $principalCents - $usedPrincipal;
            $interestRow = $interestCents - $usedInterest;
            $payableRow = $payableCents - $usedPayable;
        } else {
            $principalRow = $principalBase;
            $interestRow = $interestBase;
            $payableRow = $principalRow + $interestRow;
        }
        $usedPrincipal += $principalRow;
        $usedInterest += $interestRow;
        $usedPayable += $payableRow;
        $rows[] = [
            'installment_number' => $i,
            'due_date' => $cursor->format('Y-m-d'),
            'principal_amount' => bcdiv((string)$principalRow, '100', 2),
            'interest_amount' => bcdiv((string)$interestRow, '100', 2),
            'total_amount_due' => bcdiv((string)$payableRow, '100', 2),
        ];
    }
    $rows[$count - 1]['due_date'] = (new DateTimeImmutable($releaseDate))->modify('+' . $months . ' months')->format('Y-m-d');
    $sum = '0.00';
    foreach ($rows as $row) {
        $sum = bcadd($sum, $row['total_amount_due'], 2);
    }

    return [
        'interest' => $interest,
        'count' => $count,
        'rows' => $rows,
        'sum' => $sum,
        'reconciling_last_total' => bcsub($interest['total_payable'], bcsub($sum, $rows[$count - 1]['total_amount_due'], 2), 2),
    ];
}

function fcProposedPayment(string $principal, string $ratePercent, int $months): array
{
    $interest = fcInterest($principal, $ratePercent, $months);
    $payment = fcRoundHalfUp(bcdiv($interest['total_payable'], (string)$months, 12));

    return ['interest' => $interest, 'monthly_payment' => $payment];
}

function fcDti(string $obligations, string $gross): string
{
    return fcRoundHalfUp(bcmul(bcdiv($obligations, $gross, 12), '100', 12));
}

function fcAffordable(string $gross, string $existingDebt, string $ratePercent, int $months): array
{
    $maxDebtPayment = bcdiv(bcmul($gross, '40', 12), '100', 12);
    $room = bcsub($maxDebtPayment, $existingDebt, 12);
    if (bccomp($room, '0', 12) < 0) {
        $room = '0';
    }
    $factor = bcadd('1', bcdiv(bcmul($ratePercent, (string)$months, 12), '100', 12), 12);
    $raw = bcdiv(bcmul($room, (string)$months, 12), $factor, 12);
    $rounded = fcRoundHalfUp($raw);
    $clamped = $rounded;
    if (bccomp($clamped, '3000.00', 2) < 0) {
        $clamped = '3000.00';
    }
    if (bccomp($clamped, '500000.00', 2) > 0) {
        $clamped = '500000.00';
    }

    return [
        'room_for_new_payment' => fcRoundHalfUp($room),
        'raw_principal' => $rounded,
        'clamped_principal' => $clamped,
    ];
}

function fcStatus(string $dtiAfter, string $requested, string $rawPrincipal, int $employmentMonths, string $minimumLoan = '3000.00'): string
{
    if (bccomp($dtiAfter, '40.00', 2) === 1) {
        return 'Not Eligible';
    }
    $aboveAffordable = bccomp($requested, bcadd($rawPrincipal, '0.01', 2), 2) === 1;
    $belowMinimum = bccomp($requested, $minimumLoan, 2) === -1;
    $shortEmployment = $employmentMonths < 3;
    if ($aboveAffordable || $belowMinimum || $shortEmployment) {
        return 'Conditionally Eligible';
    }

    return 'Eligible';
}

function fcMoney(float $value): string
{
    return number_format($value, 2, '.', '');
}

function fcDiff(string $actual, string $expected): string
{
    return bcsub($actual, $expected, 2);
}

function fcCase(array $row): array
{
    return $row;
}

$results = [];
$today = date('Y-m-d');

$interestCases = [
    ['FC-01a', 'Standard 12-month interest', '10000.00', '5', 12],
    ['FC-01b', 'Three-month interest', '10000.00', '5', 3],
    ['FC-01c', 'One-month interest', '8000.00', '5', 1],
];
foreach ($interestCases as [$id, $purpose, $principal, $rate, $months]) {
    $expected = fcInterest($principal, $rate, $months);
    $actual = calculateLoanInterestBreakdown((float)$principal, (float)$rate, $months);
    $actualMonthly = fcMoney((float)$actual['monthly_interest']);
    $actualInterest = fcMoney((float)$actual['total_interest']);
    $actualPayable = fcMoney((float)$actual['total_payable']);
    $diffs = [
        'monthly_interest' => fcDiff($actualMonthly, $expected['monthly_interest']),
        'total_interest' => fcDiff($actualInterest, $expected['total_interest']),
        'total_payable' => fcDiff($actualPayable, $expected['total_payable']),
    ];
    $pass = $diffs['monthly_interest'] === '0.00' && $diffs['total_interest'] === '0.00' && $diffs['total_payable'] === '0.00';
    $results[] = fcCase([
        'id' => $id,
        'feature' => 'FC-01',
        'purpose' => $purpose,
        'inputs' => ['principal' => $principal, 'monthly_rate_percent' => $rate, 'months' => $months],
        'formula' => 'monthly = P x (r/100); total interest = monthly x n; payable = P + total interest; each figure half-up to centavos',
        'steps' => $expected['steps'],
        'expected' => [
            'monthly_interest' => $expected['monthly_interest'],
            'total_interest' => $expected['total_interest'],
            'total_payable' => $expected['total_payable'],
        ],
        'actual' => [
            'monthly_interest' => $actualMonthly,
            'total_interest' => $actualInterest,
            'total_payable' => $actualPayable,
        ],
        'difference' => $diffs,
        'status' => $pass ? 'PASS' : 'FAIL',
        'explanation' => $pass
            ? 'The application result matches the flat formula after half-up rounding to centavos.'
            : 'The application result does not match the independent flat-formula result.',
        'source' => 'lending_system/includes/loan_helpers.php calculateLoanInterestBreakdown lines 21-35',
    ]);
}

$scheduleExpectations = [
    ['FC-02a', 'Monthly schedule', 'monthly', 'Monthly'],
    ['FC-02b', 'Weekly schedule', 'weekly', 'Weekly'],
];
foreach ($scheduleExpectations as [$id, $purpose, $key, $label]) {
    $expected = fcSchedule('10000.00', '5', 3, $key, '2026-01-15');
    $actualRows = buildPaymentSchedule(10000, 5, 3, $label, '2026-01-15', 0);
    $actualSum = '0.00';
    $rowDiffs = [];
    foreach ($actualRows as $index => $row) {
        $actualSum = bcadd($actualSum, fcMoney((float)$row['total_amount_due']), 2);
        $expectedRow = $expected['rows'][$index];
        $rowDiffs[] = [
            'installment' => $index + 1,
            'due_difference_days' => (strtotime($row['due_date']) - strtotime($expectedRow['due_date'])) / 86400,
            'total_difference' => fcDiff(fcMoney((float)$row['total_amount_due']), $expectedRow['total_amount_due']),
            'actual_due' => $row['due_date'],
            'expected_due' => $expectedRow['due_date'],
            'actual_total' => fcMoney((float)$row['total_amount_due']),
            'expected_total' => $expectedRow['total_amount_due'],
        ];
    }
    $datePass = true;
    $amountPass = true;
    foreach ($rowDiffs as $diff) {
        if ((int)$diff['due_difference_days'] !== 0) {
            $datePass = false;
        }
        if ($diff['total_difference'] !== '0.00') {
            $amountPass = false;
        }
    }
    $payableDiff = fcDiff($actualSum, $expected['interest']['total_payable']);
    $results[] = fcCase([
        'id' => $id,
        'feature' => 'FC-02',
        'purpose' => $purpose,
        'inputs' => ['principal' => '10000.00', 'monthly_rate_percent' => '5', 'months' => 3, 'frequency' => $label, 'release_date' => '2026-01-15'],
        'formula' => 'Payable from FC-01. Count is n, n x 4, n x 2, or n x 30. Each row divides principal and rounded total interest evenly and rounds each component half-up. The final due date is the release date plus n calendar months.',
        'expected' => [
            'total_payable' => $expected['interest']['total_payable'],
            'installment_count' => $expected['count'],
            'first_total' => $expected['rows'][0]['total_amount_due'],
            'last_due' => $expected['rows'][count($expected['rows']) - 1]['due_date'],
            'schedule_sum' => $expected['sum'],
        ],
        'actual' => [
            'installment_count' => count($actualRows),
            'first_total' => fcMoney((float)$actualRows[0]['total_amount_due']),
            'last_due' => $actualRows[count($actualRows) - 1]['due_date'],
            'schedule_sum' => $actualSum,
        ],
        'difference' => [
            'schedule_sum_versus_payable' => $payableDiff,
            'rows' => $rowDiffs,
        ],
        'status' => ($datePass && $amountPass && count($actualRows) === $expected['count']) ? 'PASS' : 'FAIL',
        'explanation' => ($datePass && $amountPass)
            ? 'Installment amounts and due dates match the documented even split and final-date rule. The schedule sum is compared with total payable in FC-05.'
            : 'An installment amount or due date differs from the independent schedule.',
        'source' => 'lending_system/loan_release_lending.php buildPaymentSchedule lines 177-221',
    ]);
}

$engine = fcProposedPayment('100000.00', '5', 12);
$publicQuote = buildPublicLoanQuote(100000, 5, 12);
$indexSource = file_get_contents(dirname(__DIR__, 2) . '/lending_system/index.php');
$indexStillAmortizes = strpos($indexSource, 'Math.pow') !== false || strpos($indexSource, 'rate / 12') !== false;
$publicInstallment = fcMoney((float)$publicQuote['installment']);
$publicPayable = fcMoney((float)$publicQuote['total_payable']);
$results[] = fcCase([
    'id' => 'FC-02c',
    'feature' => 'FC-02',
    'purpose' => 'Public calculator compared with the flat installment',
    'inputs' => ['principal' => '100000.00', 'labeled_rate_percent' => '5', 'months' => 12],
    'formula' => 'The public quote uses calculateLoanInterestBreakdown and puts leftover centavos on the final installment. Installment shown is the regular row. Total payable = principal + total interest.',
    'expected' => [
        'installment' => '13333.33',
        'final_installment' => '13333.37',
        'total_payable' => $engine['interest']['total_payable'],
        'monthly_interest' => '5000.00',
        'total_interest' => '60000.00',
        'page_uses_flat_quote' => true,
    ],
    'actual' => [
        'installment' => $publicInstallment,
        'final_installment' => fcMoney((float)$publicQuote['final_installment']),
        'total_payable' => $publicPayable,
        'monthly_interest' => fcMoney((float)$publicQuote['monthly_interest']),
        'total_interest' => fcMoney((float)$publicQuote['total_interest']),
        'page_uses_flat_quote' => !$indexStillAmortizes,
    ],
    'difference' => ['installment' => fcDiff($publicInstallment, '13333.33'), 'total_payable' => fcDiff($publicPayable, $engine['interest']['total_payable'])],
    'status' => (
        !$indexStillAmortizes
        && $publicInstallment === '13333.33'
        && fcMoney((float)$publicQuote['final_installment']) === '13333.37'
        && $publicPayable === '160000.00'
        && fcMoney((float)$publicQuote['monthly_interest']) === '5000.00'
        && fcMoney((float)$publicQuote['total_interest']) === '60000.00'
    ) ? 'PASS' : 'FAIL',
    'explanation' => 'index.php now requests loan_quote_lending.php, which calls the same flat-interest function as loan release. The amortizing expression is no longer in the page.',
    'source' => 'lending_system/includes/loan_helpers.php buildPublicLoanQuote; lending_system/index.php; lending_system/loan_quote_lending.php',
]);

function fcSourceCounts(PDO $source): array
{
    $counts = [];
    $tables = $source->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $safe = str_replace('`', '', (string)$table);
        $counts[$safe] = (int)$source->query('SELECT COUNT(*) FROM `' . $safe . '`')->fetchColumn();
    }
    ksort($counts);
    return $counts;
}

$sourceHost = '127.0.0.1';
$sourceUser = 'root';
$sourcePass = '';
$sourceName = 'lending_management';
$envFile = dirname(__DIR__, 2) . '/lending_system/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES) as $envLine) {
        $envLine = trim($envLine);
        if ($envLine === '' || $envLine[0] === '#' || strpos($envLine, '=') === false) {
            continue;
        }
        [$envKey, $envValue] = explode('=', $envLine, 2);
        $envValue = trim($envValue, " \t\"'");
        if ($envKey === 'DB_HOST' && $envValue !== '') {
            $sourceHost = $envValue;
        } elseif ($envKey === 'DB_USER' && $envValue !== '') {
            $sourceUser = $envValue;
        } elseif ($envKey === 'DB_PASSWORD') {
            $sourcePass = $envValue;
        } elseif ($envKey === 'DB_NAME' && $envValue !== '') {
            $sourceName = $envValue;
        }
    }
}
if ($sourceName !== 'lending_management') {
    fwrite(STDERR, "Source database name is not the original application database. Aborting.\n");
    exit(1);
}
$source = new PDO('mysql:host=' . $sourceHost . ';dbname=' . $sourceName . ';charset=utf8mb4', $sourceUser, $sourcePass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$sourceBefore = fcSourceCounts($source);

$collectorId = (int)$conn->query("SELECT user_id FROM users WHERE username = 'frams_test_collector' LIMIT 1")->fetchColumn();
$clientId = (int)$conn->query("SELECT client_id FROM clients WHERE first_name = 'Synthetic' AND last_name = 'BorrowerA' LIMIT 1")->fetchColumn();
$borrowerId = (int)$conn->query("SELECT user_id FROM users WHERE username = 'frams_test_borrower' LIMIT 1")->fetchColumn();
if ($collectorId <= 0 || $clientId <= 0 || $borrowerId <= 0) {
    fwrite(STDERR, "Isolated synthetic collector, borrower, or client is missing.\n");
    exit(1);
}

function fcMakeLoan(PDO $conn, int $borrowerId, int $collectorId, int $clientId, string $loanNumber, string $principal, string $interest, string $payable, string $installment, string $frequency, string $due, array $schedule): int
{
    $conn->prepare('INSERT INTO loan_applications (user_id, borrower_name, employment_status, monthly_gross_income, loan_amount, loan_term, status, payment_frequency) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$borrowerId, 'Synthetic BorrowerA', 'Employed', 30000, $principal, '3 months', 'Released', $frequency]);
    $applicationId = (int)$conn->lastInsertId();
    $conn->prepare('INSERT INTO loan_releases (application_id, borrower_user_id, collector_user_id, loan_number, loan_amount, interest_rate, loan_term, payment_frequency, release_status, released_at, released_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)')
        ->execute([$applicationId, $borrowerId, $collectorId, $loanNumber, $principal, 5, '3 months', $frequency, 'Released', 'FRAMS Test Admin']);
    $releaseId = (int)$conn->lastInsertId();
    $conn->prepare('INSERT INTO loans (client_id, loan_amount, interest, total_payable, daily_payment, date_released, due_date, status, collector_id, loan_number, release_id, payment_frequency, loan_term, agreement_status, released_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$clientId, $principal, $interest, $payable, $installment, '2026-10-01', $due, 'Active', $collectorId, $loanNumber, $releaseId, $frequency, '3 months', 'Accepted', 'FRAMS Test Admin']);
    $loanId = (int)$conn->lastInsertId();
    $number = 1;
    $scheduleStmt = $conn->prepare('INSERT INTO loan_payment_schedules (release_id, installment_number, due_date, principal_amount, interest_amount, total_amount_due, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($schedule as $row) {
        $scheduleStmt->execute([$releaseId, $number, $row['due'], $row['principal'], $row['interest'], $row['total'], 'Upcoming']);
        $number++;
    }
    return $loanId;
}

function fcPaymentCount(PDO $conn, int $loanId): int
{
    $stmt = $conn->prepare('SELECT COUNT(*) FROM payments WHERE loan_id = ?');
    $stmt->execute([$loanId]);
    return (int)$stmt->fetchColumn();
}

function fcStoredPayment(PDO $conn, int $loanId): array
{
    $stmt = $conn->prepare('SELECT amount_paid, penalty_paid, interest_paid FROM payments WHERE loan_id = ? ORDER BY payment_id ASC');
    $stmt->execute([$loanId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$partialLoan = fcMakeLoan(
    $conn,
    $borrowerId,
    $collectorId,
    $clientId,
    'FRAMS-FC-03',
    '10000.00',
    '1500.00',
    '11500.00',
    '3833.33',
    'Monthly',
    '2027-01-01',
    [
        ['due' => '2026-11-01', 'principal' => '3333.33', 'interest' => '500.00', 'total' => '3833.33'],
        ['due' => '2026-12-01', 'principal' => '3333.33', 'interest' => '500.00', 'total' => '3833.33'],
        ['due' => '2027-01-01', 'principal' => '3333.34', 'interest' => '500.00', 'total' => '3833.34'],
    ]
);
$beforePartial = fcPaymentCount($conn, $partialLoan);
$receiptPartial = recordPaymentTransaction($partialLoan, $today, 2000, 'FRAMS Test Collector', $collectorId, $clientId, 'Cash', 'FC-03', 0);
$partialSummary = calculateLoanBalanceSummary($partialLoan);
$partialRows = fcStoredPayment($conn, $partialLoan);
$results[] = fcCase([
    'id' => 'FC-03',
    'feature' => 'FC-03',
    'purpose' => 'Partial payment reduces the scheduled balance by the amount applied to the loan',
    'inputs' => ['total_payable' => '11500.00', 'payment_received' => '2000.00', 'penalty_portion' => '0.00', 'loan_number' => 'FRAMS-FC-03'],
    'formula' => 'Remaining balance = total payable - sum of payments.amount_paid. A penalty of zero leaves the full receipt on amount_paid.',
    'expected' => ['amount_paid' => '2000.00', 'penalty_paid' => '0.00', 'remaining_balance' => '9500.00', 'payment_rows_added' => 1],
    'actual' => [
        'amount_paid' => fcMoney((float)$partialRows[0]['amount_paid']),
        'penalty_paid' => fcMoney((float)$partialRows[0]['penalty_paid']),
        'remaining_balance' => fcMoney((float)$partialSummary['remaining_balance']),
        'payment_rows_added' => fcPaymentCount($conn, $partialLoan) - $beforePartial,
        'receipt_issued' => $receiptPartial !== null && $receiptPartial !== '',
    ],
    'difference' => [
        'remaining_balance' => fcDiff(fcMoney((float)$partialSummary['remaining_balance']), '9500.00'),
    ],
    'status' => (
        fcMoney((float)$partialRows[0]['amount_paid']) === '2000.00'
        && fcMoney((float)$partialRows[0]['penalty_paid']) === '0.00'
        && fcMoney((float)$partialSummary['remaining_balance']) === '9500.00'
    ) ? 'PASS' : 'FAIL',
    'explanation' => 'The receipt total is stored in amount_paid and the remaining balance is total payable minus that amount.',
    'source' => 'lending_system/includes/db_lending.php recordPaymentTransaction lines 448-466; loan_helpers.php calculateLoanBalanceSummary lines 340-344',
]);

$yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
$penaltyLoan = fcMakeLoan(
    $conn,
    $borrowerId,
    $collectorId,
    $clientId,
    'FRAMS-FC-04',
    '9523.81',
    '476.19',
    '10000.00',
    '10000.00',
    'Monthly',
    '2027-01-20',
    [
        ['due' => $yesterday, 'principal' => '9523.81', 'interest' => '476.19', 'total' => '10000.00'],
    ]
);
$penaltyBefore = calculateLoanBalanceSummary($penaltyLoan);
$penaltyDueBefore = fcMoney((float)$penaltyBefore['penalty_due']);
$receiptPenalty = recordPaymentTransaction($penaltyLoan, $today, 2500, 'FRAMS Test Collector', $collectorId, $clientId, 'Cash', 'FC-04', 500);
$penaltyAfter = calculateLoanBalanceSummary($penaltyLoan);
$penaltyRows = fcStoredPayment($conn, $penaltyLoan);
$expectedPenaltyDue = '500.00';
$allocationMatches = fcMoney((float)$penaltyRows[0]['amount_paid']) === '2000.00'
    && fcMoney((float)$penaltyRows[0]['penalty_paid']) === '500.00'
    && fcMoney((float)$penaltyAfter['remaining_balance']) === '8000.00'
    && $penaltyDueBefore === $expectedPenaltyDue;
$results[] = fcCase([
    'id' => 'FC-04',
    'feature' => 'FC-04',
    'purpose' => 'A 2500 receipt with a 500 penalty reduces the scheduled balance by 2000',
    'inputs' => [
        'total_payment_received' => '2500.00',
        'scheduled_component' => '2000.00',
        'penalty_component' => '500.00',
        'installment_total' => '10000.00',
        'installment_due' => $yesterday,
        'frequency' => 'Monthly',
        'loan_number' => 'FRAMS-FC-04',
    ],
    'formula' => 'Monthly overdue penalty = installment total x 5 / 100. Stored scheduled amount = receipt - penalty designated, limited to the penalty still due. Remaining balance = total payable - sum of amount_paid.',
    'steps' => ['independent_penalty' => '10000.00 x 0.05 = 500.00', 'independent_remaining' => '10000.00 - 2000.00 = 8000.00'],
    'expected' => [
        'penalty_due_before' => '500.00',
        'amount_paid' => '2000.00',
        'penalty_paid' => '500.00',
        'remaining_balance' => '8000.00',
    ],
    'actual' => [
        'penalty_due_before' => $penaltyDueBefore,
        'amount_paid' => fcMoney((float)$penaltyRows[0]['amount_paid']),
        'penalty_paid' => fcMoney((float)$penaltyRows[0]['penalty_paid']),
        'remaining_balance' => fcMoney((float)$penaltyAfter['remaining_balance']),
        'penalty_due_after' => fcMoney((float)$penaltyAfter['penalty_due']),
        'receipt_issued' => $receiptPenalty !== null && $receiptPenalty !== '',
    ],
    'difference' => [
        'amount_paid' => fcDiff(fcMoney((float)$penaltyRows[0]['amount_paid']), '2000.00'),
        'remaining_balance' => fcDiff(fcMoney((float)$penaltyAfter['remaining_balance']), '8000.00'),
    ],
    'status' => $allocationMatches ? 'PASS' : 'FAIL',
    'explanation' => $allocationMatches
        ? 'The scheduled balance decreased by 2000.00. The 500.00 penalty was stored in penalty_paid and was not subtracted from total payable.'
        : 'The stored split or remaining balance does not match separate allocation of 2000.00 and 500.00.',
    'source' => 'lending_system/includes/loan_helpers.php calculateOverduePenaltyDue lines 265-285; db_lending.php lines 448-466',
]);

$roundExpected = fcInterest('100.33', '5', 3);
$roundActual = calculateLoanInterestBreakdown(100.33, 5.0, 3);
$roundedMonthlyTimesTerm = bcmul($roundExpected['monthly_interest'], '3', 2);
$results[] = fcCase([
    'id' => 'FC-05a',
    'feature' => 'FC-05',
    'purpose' => 'Interest rounding for a principal that does not land on a whole centavo',
    'inputs' => ['principal' => '100.33', 'monthly_rate_percent' => '5', 'months' => 3],
    'formula' => 'monthly = 100.33 x 0.05 = 5.0165, half-up 5.02. total interest = 15.0495, half-up 15.05. payable = 115.3795, half-up 115.38. Rounded monthly interest times months is 15.06 and is not the formula used.',
    'steps' => $roundExpected['steps'],
    'expected' => [
        'monthly_interest' => $roundExpected['monthly_interest'],
        'total_interest' => $roundExpected['total_interest'],
        'total_payable' => $roundExpected['total_payable'],
        'rounded_monthly_times_months' => $roundedMonthlyTimesTerm,
    ],
    'actual' => [
        'monthly_interest' => fcMoney((float)$roundActual['monthly_interest']),
        'total_interest' => fcMoney((float)$roundActual['total_interest']),
        'total_payable' => fcMoney((float)$roundActual['total_payable']),
    ],
    'difference' => [
        'total_interest_versus_unrounded_product' => fcDiff(fcMoney((float)$roundActual['total_interest']), $roundExpected['total_interest']),
        'total_interest_versus_rounded_monthly_times_months' => fcDiff(fcMoney((float)$roundActual['total_interest']), $roundedMonthlyTimesTerm),
    ],
    'status' => fcMoney((float)$roundActual['total_interest']) === $roundExpected['total_interest'] ? 'PASS' : 'FAIL',
    'explanation' => 'The function matches half-up rounding of the unrounded products. Displaying 5.02 and multiplying by 3 would show 15.06, which is 0.01 more than the stored total interest.',
    'source' => 'lending_system/includes/loan_helpers.php lines 26-33',
]);

$monthlySchedule = fcSchedule('10000.00', '5', 3, 'monthly', '2026-01-15');
$monthlyActual = buildPaymentSchedule(10000, 5, 3, 'Monthly', '2026-01-15', 0);
$monthlySum = '0.00';
foreach ($monthlyActual as $row) {
    $monthlySum = bcadd($monthlySum, fcMoney((float)$row['total_amount_due']), 2);
}
$results[] = fcCase([
    'id' => 'FC-05b',
    'feature' => 'FC-05',
    'purpose' => 'Whether installment rounding is adjusted so the rows equal total payable',
    'inputs' => ['principal' => '10000.00', 'months' => 3, 'frequency' => 'Monthly'],
    'formula' => 'Earlier rows use the truncated centavo share. The last monthly installment receives the remainder, 3833.34, so the rows sum to 11500.00.',
    'expected' => [
        'even_split_sum' => $monthlySchedule['sum'],
        'reconciling_schedule_sum' => '11500.00',
        'reconciling_last_installment' => $monthlySchedule['reconciling_last_total'],
    ],
    'actual' => [
        'schedule_sum' => $monthlySum,
        'last_installment' => fcMoney((float)$monthlyActual[count($monthlyActual) - 1]['total_amount_due']),
    ],
    'difference' => [
        'actual_sum_minus_payable' => fcDiff($monthlySum, '11500.00'),
        'last_installment_minus_reconciling_amount' => fcDiff(fcMoney((float)$monthlyActual[count($monthlyActual) - 1]['total_amount_due']), $monthlySchedule['reconciling_last_total']),
    ],
    'status' => $monthlySum === '11500.00' ? 'PASS' : 'FAIL',
    'explanation' => 'The three installments now sum to 11500.00. The last installment is 3833.34.',
    'source' => 'lending_system/loan_release_lending.php lines 185-210',
]);

$weeklySchedule = fcSchedule('10000.00', '5', 3, 'weekly', '2026-01-15');
$weeklyActual = buildPaymentSchedule(10000, 5, 3, 'Weekly', '2026-01-15', 0);
$weeklySum = '0.00';
foreach ($weeklyActual as $row) {
    $weeklySum = bcadd($weeklySum, fcMoney((float)$row['total_amount_due']), 2);
}
$results[] = fcCase([
    'id' => 'FC-05c',
    'feature' => 'FC-05',
    'purpose' => 'Weekly installment rounding against total payable',
    'inputs' => ['principal' => '10000.00', 'months' => 3, 'frequency' => 'Weekly', 'count' => 12],
    'formula' => '10000 / 12 = 833.333... half-up 833.33. 1500 / 12 = 125.00. Row total 958.33. Twelve rows = 11499.96. Reconciling last row would be 958.37.',
    'expected' => ['reconciling_schedule_sum' => '11500.00', 'even_split_sum' => $weeklySchedule['sum']],
    'actual' => ['schedule_sum' => $weeklySum, 'last_due' => $weeklyActual[count($weeklyActual) - 1]['due_date']],
    'difference' => ['actual_sum_minus_payable' => fcDiff($weeklySum, '11500.00')],
    'status' => $weeklySum === '11500.00' ? 'PASS' : 'FAIL',
    'explanation' => 'The twelve weekly installments now sum to 11500.00. The final due date remains the release date plus three calendar months.',
    'source' => 'lending_system/loan_release_lending.php lines 185-218',
]);

function fcEligibility(float $gross, float $debt, float $amount, int $months, string $employmentLength): array
{
    return assessLoanEligibility([
        'monthly_gross_income' => $gross,
        'monthly_net_income' => round($gross * 0.9, 2),
        'monthly_debt_payments' => $debt,
        'other_monthly_obligations' => 0,
        'employment_status' => 'Employed',
        'employment_length' => $employmentLength,
        'loan_amount' => $amount,
        'loan_term' => (string)$months,
        'interest_rate' => 5,
    ]);
}

$dtiCases = [
    ['FC-06a', 'DTI exactly 40 percent', 10000, 0, 30000, '40.00', 'Eligible'],
    ['FC-06b', 'DTI below 40 percent', 10000, 0, 29000, '38.67', 'Eligible'],
    ['FC-06c', 'DTI above 40 percent', 10000, 0, 31000, '41.33', 'Not Eligible'],
];
foreach ($dtiCases as [$id, $purpose, $gross, $debt, $amount, $expectedDti, $expectedStatus]) {
    $proposal = fcProposedPayment(number_format($amount, 2, '.', ''), '5', 12);
    $actual = fcEligibility((float)$gross, (float)$debt, (float)$amount, 12, '12 months');
    $actualDti = fcMoney((float)$actual['dti_after_percent']);
    $actualStatus = (string)$actual['status'];
    $results[] = fcCase([
        'id' => $id,
        'feature' => 'FC-06',
        'purpose' => $purpose,
        'inputs' => ['gross_income' => number_format($gross, 2, '.', ''), 'existing_debt' => '0.00', 'requested_principal' => number_format($amount, 2, '.', ''), 'months' => 12, 'employment' => '12 months'],
        'formula' => 'Payment = round(total payable / 12, 2). DTI = round((existing debt + payment) / gross x 100, 2). Above 40.00 is Not Eligible.',
        'steps' => [
            'total_payable' => $proposal['interest']['total_payable'],
            'monthly_payment' => $proposal['monthly_payment'],
            'dti_arithmetic' => $proposal['monthly_payment'] . ' / ' . number_format($gross, 2, '.', '') . ' x 100',
        ],
        'expected' => ['dti_after_percent' => $expectedDti, 'status' => $expectedStatus, 'monthly_payment' => $proposal['monthly_payment']],
        'actual' => ['dti_after_percent' => $actualDti, 'status' => $actualStatus, 'monthly_payment' => fcMoney((float)$actual['proposed_monthly_payment'])],
        'difference' => ['dti_after_percent' => fcDiff($actualDti, $expectedDti)],
        'expected_status' => $expectedStatus,
        'actual_status' => $actualStatus,
        'status' => ($actualDti === $expectedDti && $actualStatus === $expectedStatus) ? 'PASS' : 'FAIL',
        'explanation' => $actualStatus === $expectedStatus
            ? 'The classification matches the 40 percent boundary.'
            : 'The DTI figure or classification differs from the independent calculation.',
        'source' => 'lending_system/includes/loan_eligibility_helpers.php lines 74-83 and 178-227',
    ]);
}

$affordable = fcAffordable('20000.00', '0.00', '5', 12);
$amountCases = [
    ['FC-07a', 'Requested principal equal to the affordable principal', 60000, 'Eligible'],
    ['FC-07b', 'Requested principal below the affordable principal', 45000, 'Eligible'],
    ['FC-07c', 'Requested principal above the affordable principal while displayed DTI remains 40.00', 60000.50, 'Conditionally Eligible'],
];
foreach ($amountCases as [$id, $purpose, $amount, $expectedStatus]) {
    $amountText = number_format($amount, 2, '.', '');
    $proposal = fcProposedPayment($amountText, '5', 12);
    $dti = fcDti($proposal['monthly_payment'], '20000.00');
    $actual = fcEligibility(20000, 0, (float)$amount, 12, '12 months');
    $actualMax = fcMoney((float)$actual['maximum_eligible_loan_amount']);
    $actualStatus = (string)$actual['status'];
    $pass = $actualMax === $affordable['raw_principal']
        && $actualStatus === $expectedStatus
        && fcMoney((float)$actual['dti_after_percent']) === $dti;
    $results[] = fcCase([
        'id' => $id,
        'feature' => 'FC-07',
        'purpose' => $purpose,
        'inputs' => ['gross_income' => '20000.00', 'existing_debt' => '0.00', 'requested_principal' => $amountText, 'months' => 12],
        'formula' => 'Room = 20000 x 0.40 = 8000. Raw principal = (8000 x 12) / 1.60 = 60000.00. DTI uses the proposed payment for the requested amount.',
        'steps' => ['affordable_principal' => $affordable['raw_principal'], 'monthly_payment' => $proposal['monthly_payment'], 'dti_after' => $dti],
        'expected' => ['maximum_affordable_principal' => $affordable['raw_principal'], 'dti_after_percent' => $dti, 'status' => $expectedStatus],
        'actual' => [
            'maximum_eligible_loan_amount' => $actualMax,
            'dti_after_percent' => fcMoney((float)$actual['dti_after_percent']),
            'status' => $actualStatus,
        ],
        'difference' => ['maximum_principal' => fcDiff($actualMax, $affordable['raw_principal'])],
        'expected_status' => $expectedStatus,
        'actual_status' => $actualStatus,
        'status' => $pass ? 'PASS' : 'FAIL',
        'explanation' => $pass
            ? 'The reported maximum and classification match the affordable-principal formula for an amount above the 3000 minimum.'
            : 'The reported maximum or classification does not match the affordable-principal formula.',
        'source' => 'lending_system/includes/loan_eligibility_helpers.php calculateMaxPrincipalFromMonthlyPayment lines 85-98 and assessLoanEligibility lines 182-220',
    ]);
}

$clamp = fcAffordable('10000.00', '3900.00', '5', 12);
$clampActual = fcEligibility(10000, 3900, 750, 12, '12 months');
$clampRequest = fcEligibility(10000, 3900, 3000, 12, '12 months');
$results[] = fcCase([
    'id' => 'FC-07d',
    'feature' => 'FC-07',
    'purpose' => 'Affordable principal below the 3000 minimum',
    'inputs' => ['gross_income' => '10000.00', 'existing_debt' => '3900.00', 'months' => 12, 'requested_for_boundary' => '750.00', 'requested_clamped_figure' => '3000.00'],
    'formula' => 'Room = 10000 x 0.40 - 3900 = 100. Raw principal = (100 x 12) / 1.60 = 750.00. The code then raises that result to the 3000 minimum.',
    'steps' => $clamp,
    'expected' => [
        'dti_safe_principal' => '750.00',
        'status_at_750' => 'Not Eligible',
        'dti_at_3000' => '43.00',
        'status_at_3000' => 'Not Eligible',
    ],
    'actual' => [
        'maximum_eligible_loan_amount' => fcMoney((float)$clampActual['maximum_eligible_loan_amount']),
        'status_at_750' => (string)$clampActual['status'],
        'dti_at_750' => fcMoney((float)$clampActual['dti_after_percent']),
        'status_at_3000' => (string)$clampRequest['status'],
        'dti_at_3000' => fcMoney((float)$clampRequest['dti_after_percent']),
    ],
    'difference' => [
        'reported_maximum_minus_dti_safe_principal' => fcDiff(fcMoney((float)$clampActual['maximum_eligible_loan_amount']), '750.00'),
    ],
    'expected_status' => 'Not Eligible at 750.00 because 750.00 is below the 3000.00 product minimum; Not Eligible at 3000.00',
    'actual_status' => (string)$clampActual['status'] . ' at 750.00; ' . (string)$clampRequest['status'] . ' at 3000.00',
    'status' => (
        fcMoney((float)$clampActual['maximum_eligible_loan_amount']) === '750.00'
        && (string)$clampActual['status'] === 'Not Eligible'
        && fcMoney((float)$clampActual['dti_after_percent']) === '40.00'
        && (string)$clampRequest['status'] === 'Not Eligible'
        && fcMoney((float)$clampRequest['dti_after_percent']) === '43.00'
    ) ? 'PASS' : 'FAIL',
    'explanation' => 'The reported maximum stays at the DTI-safe principal of 750.00. It is not raised to 3000.00. A borrower who cannot afford the minimum product is Not Eligible, and a 3000.00 request on this income is also Not Eligible at 43.00 percent DTI.',
    'source' => 'lending_system/includes/loan_eligibility_helpers.php clampLoanAmountToPolicy lines 100-106',
]);

$combined = fcProposedPayment('31000.00', '5', 12);
$combinedDti = fcDti($combined['monthly_payment'], '10000.00');
$combinedAffordable = fcAffordable('10000.00', '0.00', '5', 12);
$combinedActual = fcEligibility(10000, 0, 31000, 12, '12 months');
$results[] = fcCase([
    'id' => 'FC-08',
    'feature' => 'FC-08',
    'purpose' => 'Requested amount exceeds both the 40 percent DTI limit and the affordable principal',
    'inputs' => ['gross_income' => '10000.00', 'existing_debt' => '0.00', 'requested_principal' => '31000.00', 'months' => 12],
    'formula' => 'Affordable principal = (4000 x 12) / 1.60 = 30000.00. Payment on 31000 = 4133.33. DTI = 41.33. DTI above 40 takes precedence over Conditionally Eligible.',
    'steps' => ['affordable_principal' => $combinedAffordable['raw_principal'], 'monthly_payment' => $combined['monthly_payment'], 'dti_after' => $combinedDti],
    'expected' => ['status' => 'Not Eligible', 'dti_after_percent' => '41.33', 'maximum_affordable_principal' => '30000.00'],
    'actual' => [
        'status' => (string)$combinedActual['status'],
        'dti_after_percent' => fcMoney((float)$combinedActual['dti_after_percent']),
        'maximum_eligible_loan_amount' => fcMoney((float)$combinedActual['maximum_eligible_loan_amount']),
    ],
    'difference' => ['dti_after_percent' => fcDiff(fcMoney((float)$combinedActual['dti_after_percent']), '41.33')],
    'expected_status' => 'Not Eligible',
    'actual_status' => (string)$combinedActual['status'],
    'status' => ((string)$combinedActual['status'] === 'Not Eligible' && fcMoney((float)$combinedActual['dti_after_percent']) === '41.33') ? 'PASS' : 'FAIL',
    'explanation' => 'Both limits are exceeded. The application classifies the request as Not Eligible rather than Conditionally Eligible.',
    'source' => 'lending_system/includes/loan_eligibility_helpers.php lines 215-220',
]);

$multiLoan = fcMakeLoan(
    $conn,
    $borrowerId,
    $collectorId,
    $clientId,
    'FRAMS-FC-09',
    '10000.00',
    '1500.00',
    '11500.00',
    '3833.33',
    'Monthly',
    '2027-04-01',
    [
        ['due' => '2027-02-01', 'principal' => '3333.33', 'interest' => '500.00', 'total' => '3833.33'],
        ['due' => '2027-03-01', 'principal' => '3333.33', 'interest' => '500.00', 'total' => '3833.33'],
        ['due' => '2027-04-01', 'principal' => '3333.34', 'interest' => '500.00', 'total' => '3833.34'],
    ]
);
recordPaymentTransaction($multiLoan, $today, 2000, 'FRAMS Test Collector', $collectorId, $clientId, 'Cash', 'FC-09A', 0);
recordPaymentTransaction($multiLoan, $today, 3000, 'FRAMS Test Collector', $collectorId, $clientId, 'Cash', 'FC-09B', 0);
$multiSummary = calculateLoanBalanceSummary($multiLoan);
$multiRows = fcStoredPayment($conn, $multiLoan);
$multiPaid = '0.00';
foreach ($multiRows as $row) {
    $multiPaid = bcadd($multiPaid, fcMoney((float)$row['amount_paid']), 2);
}
$results[] = fcCase([
    'id' => 'FC-09',
    'feature' => 'FC-09',
    'purpose' => 'Remaining balance after two payments',
    'inputs' => ['total_payable' => '11500.00', 'first_payment' => '2000.00', 'second_payment' => '3000.00', 'loan_number' => 'FRAMS-FC-09'],
    'formula' => 'Remaining = 11500.00 - (2000.00 + 3000.00) = 6500.00.',
    'expected' => ['payment_count' => 2, 'amount_paid_sum' => '5000.00', 'remaining_balance' => '6500.00'],
    'actual' => ['payment_count' => count($multiRows), 'amount_paid_sum' => $multiPaid, 'remaining_balance' => fcMoney((float)$multiSummary['remaining_balance'])],
    'difference' => ['remaining_balance' => fcDiff(fcMoney((float)$multiSummary['remaining_balance']), '6500.00')],
    'status' => ($multiPaid === '5000.00' && fcMoney((float)$multiSummary['remaining_balance']) === '6500.00' && count($multiRows) === 2) ? 'PASS' : 'FAIL',
    'explanation' => 'Two separate payment rows were stored and the balance function subtracted both from total payable.',
    'source' => 'lending_system/includes/loan_helpers.php calculateLoanBalanceSummary lines 335-344',
]);

$edgeLoan = fcMakeLoan(
    $conn,
    $borrowerId,
    $collectorId,
    $clientId,
    'FRAMS-FC-10',
    '2000.00',
    '0.00',
    '2000.00',
    '2000.00',
    'Monthly',
    '2027-05-01',
    [
        ['due' => '2027-05-01', 'principal' => '2000.00', 'interest' => '0.00', 'total' => '2000.00'],
    ]
);
$edgeCountBefore = fcPaymentCount($conn, $edgeLoan);
$rejected = [];
foreach ([['zero', 0.0], ['negative', -25.0], ['overpayment', 2000.01]] as [$label, $amount]) {
    try {
        recordPaymentTransaction($edgeLoan, $today, $amount, 'FRAMS Test Collector', $collectorId, $clientId, 'Cash', 'FC-10-' . $label, 0);
        $rejected[$label] = 'accepted';
    } catch (Throwable $e) {
        $rejected[$label] = 'rejected';
    }
}
$edgeCountAfterRejects = fcPaymentCount($conn, $edgeLoan);
recordPaymentTransaction($edgeLoan, $today, 2000, 'FRAMS Test Collector', $collectorId, $clientId, 'Cash', 'FC-10-exact', 0);
$edgeSummary = calculateLoanBalanceSummary($edgeLoan);
$edgeStatus = (string)$conn->query('SELECT status FROM loans WHERE loan_id = ' . (int)$edgeLoan)->fetchColumn();
$zeroInterest = calculateLoanInterestBreakdown(0, 5, 12);
$edgePaymentPass = $rejected['zero'] === 'rejected'
    && $rejected['negative'] === 'rejected'
    && $rejected['overpayment'] === 'rejected'
    && $edgeCountAfterRejects === $edgeCountBefore;
$results[] = fcCase([
    'id' => 'FC-10a',
    'feature' => 'FC-10',
    'purpose' => 'Zero, negative, and over-limit payments are rejected',
    'inputs' => ['loan_payable' => '2000.00', 'amounts' => ['0.00', '-25.00', '2000.01'], 'loan_number' => 'FRAMS-FC-10'],
    'formula' => 'A payment must be greater than zero, and the installment portion cannot exceed the remaining balance.',
    'expected' => ['zero_payment' => 'rejected', 'negative_payment' => 'rejected', 'overpayment' => 'rejected', 'rows_added' => 0],
    'actual' => ['zero_payment' => $rejected['zero'], 'negative_payment' => $rejected['negative'], 'overpayment' => $rejected['overpayment'], 'rows_added' => $edgeCountAfterRejects - $edgeCountBefore],
    'difference' => ['rows_added' => (string)($edgeCountAfterRejects - $edgeCountBefore)],
    'status' => $edgePaymentPass ? 'PASS' : 'FAIL',
    'explanation' => $edgePaymentPass
        ? 'All three invalid amounts were rejected and no payment row was added.'
        : 'An invalid amount was accepted or a payment row was added.',
    'source' => 'lending_system/includes/loan_helpers.php validatePaymentAmounts lines 288-316',
]);
$results[] = fcCase([
    'id' => 'FC-10b',
    'feature' => 'FC-10',
    'purpose' => 'Exact payment of the remaining balance',
    'inputs' => ['total_payable' => '2000.00', 'payment' => '2000.00'],
    'formula' => 'Remaining = 2000.00 - 2000.00 = 0.00. Status becomes Paid when amount paid reaches total payable.',
    'expected' => ['remaining_balance' => '0.00', 'status' => 'Paid'],
    'actual' => ['remaining_balance' => fcMoney((float)$edgeSummary['remaining_balance']), 'status' => $edgeStatus],
    'difference' => ['remaining_balance' => fcDiff(fcMoney((float)$edgeSummary['remaining_balance']), '0.00')],
    'expected_status' => 'Paid',
    'actual_status' => $edgeStatus,
    'status' => (fcMoney((float)$edgeSummary['remaining_balance']) === '0.00' && $edgeStatus === 'Paid') ? 'PASS' : 'FAIL',
    'explanation' => 'The exact payment cleared the synthetic balance and the loan status became Paid.',
    'source' => 'lending_system/includes/db_lending.php updateLoanStatusForLoan lines 226-227',
]);
$results[] = fcCase([
    'id' => 'FC-10c',
    'feature' => 'FC-10',
    'purpose' => 'Zero principal',
    'inputs' => ['principal' => '0.00', 'monthly_rate_percent' => '5', 'months' => 12],
    'formula' => '0 x 0.05 = 0. Total payable = 0.',
    'expected' => ['total_payable' => '0.00'],
    'actual' => ['total_payable' => fcMoney((float)$zeroInterest['total_payable'])],
    'difference' => ['total_payable' => fcDiff(fcMoney((float)$zeroInterest['total_payable']), '0.00')],
    'status' => fcMoney((float)$zeroInterest['total_payable']) === '0.00' ? 'PASS' : 'FAIL',
    'explanation' => 'A zero principal produces a zero payable.',
    'source' => 'lending_system/includes/loan_helpers.php lines 23-28',
]);
$negativeRejected = false;
$negativeMessage = '';
try {
    calculateLoanInterestBreakdown(-5000, 5, 1);
} catch (InvalidArgumentException $e) {
    $negativeRejected = true;
    $negativeMessage = $e->getMessage();
}
$negativeScheduleRejected = false;
try {
    buildPaymentSchedule(-5000, 5, 1, 'Monthly', '2026-01-15', 0);
} catch (InvalidArgumentException $e) {
    $negativeScheduleRejected = true;
}
$results[] = fcCase([
    'id' => 'FC-10d',
    'feature' => 'FC-10',
    'purpose' => 'Negative principal',
    'inputs' => ['principal' => '-5000.00', 'monthly_rate_percent' => '5', 'months' => 1],
    'formula' => 'A negative principal is rejected. It is not converted to zero and it is not priced with the flat formula.',
    'expected' => ['rejected' => true, 'schedule_rejected' => true],
    'actual' => ['rejected' => $negativeRejected, 'schedule_rejected' => $negativeScheduleRejected, 'message' => $negativeMessage],
    'difference' => ['rejected' => $negativeRejected ? '0' : '1'],
    'status' => ($negativeRejected && $negativeScheduleRejected) ? 'PASS' : 'FAIL',
    'explanation' => ($negativeRejected && $negativeScheduleRejected)
        ? 'The interest function and the schedule builder both rejected the negative principal.'
        : 'The negative principal was accepted by the interest function or the schedule builder.',
    'source' => 'lending_system/includes/loan_helpers.php calculateLoanInterestBreakdown',
]);
$zeroTermRejected = false;
$zeroTermMessage = '';
try {
    calculateLoanInterestBreakdown(10000, 5, 0);
} catch (InvalidArgumentException $e) {
    $zeroTermRejected = true;
    $zeroTermMessage = $e->getMessage();
}
$zeroScheduleRejected = false;
try {
    buildPaymentSchedule(10000, 5, 0, 'Monthly', '2026-01-15', 0);
} catch (InvalidArgumentException $e) {
    $zeroScheduleRejected = true;
}
$results[] = fcCase([
    'id' => 'FC-10e',
    'feature' => 'FC-10',
    'purpose' => 'Zero-month term',
    'inputs' => ['principal' => '10000.00', 'monthly_rate_percent' => '5', 'months' => 0],
    'formula' => 'A term below 1 month is rejected. It is not replaced with one month of interest.',
    'expected' => ['rejected' => true, 'schedule_rejected' => true],
    'actual' => ['rejected' => $zeroTermRejected, 'schedule_rejected' => $zeroScheduleRejected, 'message' => $zeroTermMessage],
    'difference' => ['rejected' => $zeroTermRejected ? '0' : '1'],
    'status' => ($zeroTermRejected && $zeroScheduleRejected) ? 'PASS' : 'FAIL',
    'explanation' => ($zeroTermRejected && $zeroScheduleRejected)
        ? 'A zero-month term was rejected by the interest function and the schedule builder, so no 500.00 interest charge was created.'
        : 'A zero-month term was accepted.',
    'source' => 'lending_system/includes/loan_helpers.php calculateLoanInterestBreakdown',
]);
$negativeTermRejected = false;
try {
    calculateLoanInterestBreakdown(10000, 5, -3);
} catch (InvalidArgumentException $e) {
    $negativeTermRejected = true;
}
$validTerm = calculateLoanInterestBreakdown(10000, 5, 12);
$results[] = fcCase([
    'id' => 'FC-10f',
    'feature' => 'FC-10',
    'purpose' => 'Negative term is rejected and a 12-month term still prices at 5 percent',
    'inputs' => ['negative_months' => -3, 'valid_principal' => '10000.00', 'valid_months' => 12],
    'formula' => 'Term must be at least 1 month. A valid 12-month loan remains principal 10000, interest 6000, payable 16000.',
    'expected' => ['negative_term_rejected' => true, 'valid_total_payable' => '16000.00'],
    'actual' => ['negative_term_rejected' => $negativeTermRejected, 'valid_total_payable' => fcMoney((float)$validTerm['total_payable'])],
    'difference' => ['valid_total_payable' => fcDiff(fcMoney((float)$validTerm['total_payable']), '16000.00')],
    'status' => ($negativeTermRejected && fcMoney((float)$validTerm['total_payable']) === '16000.00') ? 'PASS' : 'FAIL',
    'explanation' => 'The negative term throws. The valid 12-month calculation is unchanged.',
    'source' => 'lending_system/includes/loan_helpers.php calculateLoanInterestBreakdown',
]);
$maxLimitActual = fcEligibility(2000000, 0, 600000, 12, '12 months');
$results[] = fcCase([
    'id' => 'FC-07e',
    'feature' => 'FC-07',
    'purpose' => 'Requested principal above the 500000 maximum loan',
    'inputs' => ['gross_income' => '2000000.00', 'existing_debt' => '0.00', 'requested_principal' => '600000.00', 'months' => 12],
    'formula' => 'The affordable principal is capped at the 500000 product maximum. A larger request is not eligible for the amount requested.',
    'expected' => ['maximum_eligible_loan_amount' => '500000.00', 'status' => 'Conditionally Eligible'],
    'actual' => [
        'maximum_eligible_loan_amount' => fcMoney((float)$maxLimitActual['maximum_eligible_loan_amount']),
        'status' => (string)$maxLimitActual['status'],
        'dti_after_percent' => fcMoney((float)$maxLimitActual['dti_after_percent']),
    ],
    'difference' => ['maximum_eligible_loan_amount' => fcDiff(fcMoney((float)$maxLimitActual['maximum_eligible_loan_amount']), '500000.00')],
    'expected_status' => 'Conditionally Eligible',
    'actual_status' => (string)$maxLimitActual['status'],
    'status' => (
        fcMoney((float)$maxLimitActual['maximum_eligible_loan_amount']) === '500000.00'
        && (string)$maxLimitActual['status'] === 'Conditionally Eligible'
    ) ? 'PASS' : 'FAIL',
    'explanation' => 'The maximum stays at 500000.00. The 600000.00 request is above that cap and is Conditionally Eligible rather than approved.',
    'source' => 'lending_system/includes/loan_eligibility_helpers.php clampLoanAmountToPolicy and assessLoanEligibility',
]);

$outstandingSql = 'SELECT ' . outstandingReceivableSelectSql() . ' AS outstanding_balance
    FROM loans l
    ' . outstandingReceivableJoinSql() . '
    WHERE l.loan_id = ?';
$outstandingStmt = $conn->prepare($outstandingSql);
$outstandingStmt->execute([$partialLoan]);
$partialOutstanding = fcMoney((float)$outstandingStmt->fetchColumn());
$outstandingStmt->execute([$edgeLoan]);
$paidOutstanding = fcMoney((float)$outstandingStmt->fetchColumn());
$results[] = fcCase([
    'id' => 'FC-11',
    'feature' => 'FC-11',
    'purpose' => 'Portfolio outstanding uses remaining scheduled balance',
    'inputs' => ['partial_loan' => 'FRAMS-FC-03', 'paid_loan' => 'FRAMS-FC-10'],
    'formula' => 'Outstanding = total payable - sum of amount_paid for loans that are not Paid. Penalty amounts are not subtracted. A Paid loan contributes 0.',
    'expected' => ['partial_outstanding' => '9500.00', 'paid_outstanding' => '0.00'],
    'actual' => ['partial_outstanding' => $partialOutstanding, 'paid_outstanding' => $paidOutstanding],
    'difference' => [
        'partial_outstanding' => fcDiff($partialOutstanding, '9500.00'),
        'paid_outstanding' => fcDiff($paidOutstanding, '0.00'),
    ],
    'status' => ($partialOutstanding === '9500.00' && $paidOutstanding === '0.00') ? 'PASS' : 'FAIL',
    'explanation' => 'The loans-page outstanding expression subtracts amount_paid and excludes the Paid loan. It does not subtract penalty_paid.',
    'source' => 'lending_system/includes/loan_helpers.php outstandingReceivableSelectSql; lending_system/loans_lending.php',
]);

$sourceAfter = fcSourceCounts($source);
$sourceUnchanged = $sourceBefore === $sourceAfter;

$pass = 0;
$fail = 0;
foreach ($results as $result) {
    if ($result['status'] === 'PASS') {
        $pass++;
    } elseif ($result['status'] === 'FAIL') {
        $fail++;
    }
}

$payload = [
    'executed_at' => date('c'),
    'php_version' => PHP_VERSION,
    'database' => $connected,
    'source_database_unchanged' => $sourceUnchanged,
    'source_table_count' => count($sourceBefore),
    'tolerance' => '0.00 currency units after half-up rounding to two decimal places',
    'counts' => ['executed' => count($results), 'passed' => $pass, 'failed' => $fail, 'blocked' => 0],
    'results' => $results,
];

$evidenceDir = dirname(__DIR__) . '/evidence';
$reportDir = dirname(__DIR__) . '/reports';
if (!is_dir($evidenceDir)) {
    mkdir($evidenceDir, 0777, true);
}
file_put_contents($evidenceDir . '/fc_validation_results.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$csv = fopen($reportDir . '/fc_computation_results.csv', 'wb');
fputcsv($csv, ['test_id', 'feature', 'purpose', 'expected', 'actual', 'difference', 'expected_status', 'actual_status', 'result']);
foreach ($results as $result) {
    fputcsv($csv, [
        $result['id'],
        $result['feature'],
        $result['purpose'],
        json_encode($result['expected'], JSON_UNESCAPED_SLASHES),
        json_encode($result['actual'], JSON_UNESCAPED_SLASHES),
        json_encode($result['difference'], JSON_UNESCAPED_SLASHES),
        $result['expected_status'] ?? '',
        $result['actual_status'] ?? '',
        $result['status'],
    ]);
}
fclose($csv);

echo json_encode([
    'executed' => count($results),
    'passed' => $pass,
    'failed' => $fail,
    'source_database_unchanged' => $sourceUnchanged,
    'database' => $connected,
], JSON_PRETTY_PRINT) . PHP_EOL;

if (!$sourceUnchanged) {
    fwrite(STDERR, "Source database row counts changed. Investigate before using this run.\n");
    exit(2);
}
