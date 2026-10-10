<?php
/**
 * Seed 3 active borrowers with overdue installments for penalty collection testing.
 *
 * Usage:
 *   php tools/seed_overdue_penalty_test_cli.php --confirm
 *
 * Default login for each test borrower: password "Test123!"
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this script from the command line only.\n");
    exit(1);
}

$argv = $_SERVER['argv'] ?? [];
if (!in_array('--confirm', $argv, true)) {
    fwrite(STDERR, "Creates 3 Borrower accounts with overdue loans (for penalty testing).\n");
    fwrite(STDERR, "Re-run with: php tools/seed_overdue_penalty_test_cli.php --confirm\n");
    exit(1);
}

require_once __DIR__ . '/../includes/db_lending.php';
require_once __DIR__ . '/../includes/loan_helpers.php';

/** @var PDO $conn */
global $conn;

$collectorId = (int)($conn->query("SELECT user_id FROM users WHERE role = 'Collector' AND status = 'Active' ORDER BY user_id ASC LIMIT 1")->fetchColumn() ?: 0);
if ($collectorId <= 0) {
    fwrite(STDERR, "No active Collector found. Create a collector account first.\n");
    exit(1);
}

$passwordHash = password_hash('Test123!', PASSWORD_DEFAULT);
$rate = getStandardMonthlyInterestRatePercent();

$profiles = [
    [
        'username' => 'overdue_maria',
        'email' => 'overdue.maria@test.local',
        'mobile' => '09171000001',
        'first_name' => 'Maria',
        'last_name' => 'Santos',
        'loan_number' => 'RJRR-LOAN-2026-900001',
        'loan_amount' => 15000.0,
        'term_months' => 3,
        'payment_frequency' => 'Monthly',
        'overdue_installments' => 2,
    ],
    [
        'username' => 'overdue_juan',
        'email' => 'overdue.juan@test.local',
        'mobile' => '09171000002',
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'loan_number' => 'RJRR-LOAN-2026-900002',
        'loan_amount' => 10000.0,
        'term_months' => 3,
        'payment_frequency' => 'Weekly',
        'overdue_installments' => 4,
    ],
    [
        'username' => 'overdue_ana',
        'email' => 'overdue.ana@test.local',
        'mobile' => '09171000003',
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'loan_number' => 'RJRR-LOAN-2026-900003',
        'loan_amount' => 12000.0,
        'term_months' => 3,
        'payment_frequency' => 'Semi-Monthly',
        'overdue_installments' => 3,
    ],
];

function scheduleDueDatePast(int $installmentNumber, string $frequencyKey): string
{
    $today = new DateTimeImmutable('today');
    $daysBack = match ($frequencyKey) {
        'daily' => 30 - $installmentNumber,
        'weekly' => 56 - ($installmentNumber * 7),
        'semi-monthly' => 75 - ($installmentNumber * 15),
        default => 120 - ($installmentNumber * 30),
    };
    $daysBack = max(5, $daysBack);

    return $today->modify('-' . $daysBack . ' days')->format('Y-m-d');
}

function buildSeedInstallments(
    int $releaseId,
    float $loanAmount,
    float $interestRate,
    int $termMonths,
    string $paymentFrequency,
    int $overdueCount
): array {
    $frequencyKey = normalizePaymentFrequencyKey($paymentFrequency);
    $count = getScheduleInstallmentCount($paymentFrequency, $termMonths);
    $overdueCount = min($overdueCount, $count);

    $principalAmount = $loanAmount / max(1, $count);
    $breakdown = calculateLoanInterestBreakdown($loanAmount, $interestRate, $termMonths);
    $interestAmount = $breakdown['total_interest'] / max(1, $count);
    $installmentTotal = round($principalAmount + $interestAmount, 2);

    $rows = [];
    for ($i = 1; $i <= $count; $i++) {
        $isOverdue = $i <= $overdueCount;
        $rows[] = [
            'release_id' => $releaseId,
            'installment_number' => $i,
            'due_date' => $isOverdue
                ? scheduleDueDatePast($i, $frequencyKey)
                : (new DateTimeImmutable('today'))->modify('+14 days')->format('Y-m-d'),
            'principal_amount' => round($principalAmount, 2),
            'interest_amount' => round($interestAmount, 2),
            'total_amount_due' => $installmentTotal,
            'status' => $isOverdue ? 'Overdue' : 'Upcoming',
        ];
    }

    return $rows;
}

echo "=== Seed overdue borrowers (penalty test) ===\n\n";

try {
    $conn->beginTransaction();

    foreach ($profiles as $profile) {
        $existing = executeQuery('SELECT user_id FROM users WHERE username = ? LIMIT 1', [$profile['username']])->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            echo "Skip {$profile['username']} — username already exists (user_id {$existing['user_id']}).\n";
            continue;
        }

        $userId = (int)insert('users', [
            'username' => $profile['username'],
            'password' => $passwordHash,
            'full_name' => $profile['first_name'] . ' ' . $profile['last_name'],
            'email' => $profile['email'],
            'contact_number' => $profile['mobile'],
            'role' => 'Borrower',
            'status' => 'Active',
            'date_created' => date('Y-m-d H:i:s'),
        ]);

        executeQuery(
            'INSERT INTO borrower_applications (user_id, first_name, last_name, mobile_number, email, complete_address, username, status, verification_status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $userId,
                $profile['first_name'],
                $profile['last_name'],
                $profile['mobile'],
                $profile['email'],
                '123 Test Street, Cebu City',
                $profile['username'],
                'Active',
                'Approved',
            ]
        );

        $clientId = (int)insert('clients', [
            'user_id' => $userId,
            'first_name' => $profile['first_name'],
            'last_name' => $profile['last_name'],
            'address' => '123 Test Street, Cebu City',
            'contact' => $profile['mobile'],
            'email' => $profile['email'],
            'collector_id' => $collectorId,
            'date_registered' => date('Y-m-d', strtotime('-4 months')),
        ]);

        $loanAmount = (float)$profile['loan_amount'];
        $termMonths = (int)$profile['term_months'];
        executeQuery(
            'INSERT INTO loan_applications (user_id, borrower_name, mobile_number, email_address, complete_address, loan_amount, loan_term, payment_frequency, status, agreement_status, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $userId,
                $profile['first_name'] . ' ' . $profile['last_name'],
                $profile['mobile'],
                $profile['email'],
                '123 Test Street, Cebu City',
                $loanAmount,
                $termMonths . ' Months',
                $profile['payment_frequency'],
                'Released',
                'Accepted',
            ]
        );
        $applicationId = (int)$conn->lastInsertId();
        $breakdown = calculateLoanInterestBreakdown($loanAmount, $rate, $termMonths);
        $totalPayable = $breakdown['total_payable'];
        $releaseDate = (new DateTimeImmutable('today'))->modify('-4 months')->format('Y-m-d');

        executeQuery(
            'INSERT INTO loan_releases (application_id, borrower_user_id, collector_user_id, loan_number, loan_amount, interest_rate, loan_term, payment_frequency, release_status, released_at, released_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)',
            [
                $applicationId,
                $userId,
                $collectorId,
                $profile['loan_number'],
                $loanAmount,
                $rate,
                $termMonths . ' Months',
                $profile['payment_frequency'],
                'Released',
                'Seed CLI',
            ]
        );
        $releaseId = (int)$conn->lastInsertId();

        $installments = buildSeedInstallments(
            $releaseId,
            $loanAmount,
            $rate,
            $termMonths,
            $profile['payment_frequency'],
            (int)$profile['overdue_installments']
        );
        $finalDueDate = $installments[count($installments) - 1]['due_date'];
        $scheduledPayment = (float)$installments[0]['total_amount_due'];

        executeQuery(
            'INSERT INTO loans (client_id, loan_amount, interest, total_payable, daily_payment, date_released, due_date, status, collector_id, loan_number, release_id, payment_frequency, agreement_status, released_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $clientId,
                $loanAmount,
                $rate,
                $totalPayable,
                $scheduledPayment,
                $releaseDate,
                $finalDueDate,
                'Overdue',
                $collectorId,
                $profile['loan_number'],
                $releaseId,
                $profile['payment_frequency'],
                'Accepted',
                'Seed CLI',
            ]
        );
        $loanId = (int)$conn->lastInsertId();

        foreach ($installments as $row) {
            executeQuery(
                'INSERT INTO loan_payment_schedules (release_id, installment_number, due_date, principal_amount, interest_amount, total_amount_due, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $row['release_id'],
                    $row['installment_number'],
                    $row['due_date'],
                    $row['principal_amount'],
                    $row['interest_amount'],
                    $row['total_amount_due'],
                    $row['status'],
                ]
            );
        }

        $penaltyDue = calculateOverduePenaltyDue($loanId);
        $penaltyRate = getPenaltyRatePercentForFrequency($profile['payment_frequency']);

        echo sprintf(
            "Created %s %s | user: %s / Test123! | loan_id: %d | %s | overdue inst: %d | penalty due: %s (rate %s%%)\n",
            $profile['first_name'],
            $profile['last_name'],
            $profile['username'],
            $loanId,
            $profile['loan_number'],
            (int)$profile['overdue_installments'],
            '₱' . number_format($penaltyDue, 2),
            number_format($penaltyRate, 1)
        );
    }

    $conn->commit();
    echo "\nDone. Record payments at Payments with a \"Penalty portion\" to test collection.\n";
    echo "Penalties report: penalties_lending.php\n";
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}
