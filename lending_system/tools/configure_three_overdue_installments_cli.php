<?php
/**
 * Ensure two existing test borrowers each have one active loan with EXACTLY three
 * overdue installments (per FRAMS schedule status rules).
 *
 * Usage:
 *   php tools/configure_three_overdue_installments_cli.php --confirm
 *
 * Default borrowers: overdue_maria, overdue_juan (password Test123! if seeded earlier).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this script from the command line only.\n");
    exit(1);
}

$argv = $_SERVER['argv'] ?? [];
if (!in_array('--confirm', $argv, true)) {
    fwrite(STDERR, "Configures overdue_maria and overdue_juan with 3 overdue installments each.\n");
    fwrite(STDERR, "Re-run with: php tools/configure_three_overdue_installments_cli.php --confirm\n");
    exit(1);
}

require_once __DIR__ . '/../includes/db_lending.php';
require_once __DIR__ . '/../includes/loan_helpers.php';

/** @var PDO $conn */
global $conn;

const TARGET_USERNAMES = ['overdue_maria', 'overdue_juan'];
const OVERDUE_INSTALLMENT_TARGET = 3;

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
        $futureDays = 14 + (($i - $overdueCount - 1) * 7);
        $rows[] = [
            'release_id' => $releaseId,
            'installment_number' => $i,
            'due_date' => $isOverdue
                ? scheduleDueDatePast($i, $frequencyKey)
                : (new DateTimeImmutable('today'))->modify('+' . max(7, $futureDays) . ' days')->format('Y-m-d'),
            'principal_amount' => round($principalAmount, 2),
            'interest_amount' => round($interestAmount, 2),
            'total_amount_due' => $installmentTotal,
            'status' => $isOverdue ? 'Overdue' : 'Upcoming',
        ];
    }

    return $rows;
}

function countOverdueInstallments(PDO $conn, int $releaseId): int
{
    $stmt = $conn->prepare("SELECT COUNT(*) FROM loan_payment_schedules WHERE release_id = ? AND status = 'Overdue'");
    $stmt->execute([$releaseId]);

    return (int)$stmt->fetchColumn();
}

function findActiveLoanForClient(PDO $conn, int $clientId): ?array
{
    $stmt = $conn->prepare(
        "SELECT l.loan_id, l.release_id, l.status, l.loan_number
         FROM loans l
         WHERE l.client_id = ? AND l.status IN ('Active', 'Overdue') AND l.release_id IS NOT NULL AND l.release_id > 0
         ORDER BY l.loan_id DESC
         LIMIT 1"
    );
    $stmt->execute([$clientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function applyExactlyThreeOverdueToLoan(PDO $conn, int $loanId, int $releaseId, string $paymentFrequency, int $termMonths): void
{
    $frequencyKey = normalizePaymentFrequencyKey($paymentFrequency);
    $count = getScheduleInstallmentCount($paymentFrequency, $termMonths);
    if ($count < OVERDUE_INSTALLMENT_TARGET) {
        throw new RuntimeException("Loan {$loanId} schedule has only {$count} installments; need at least " . OVERDUE_INSTALLMENT_TARGET . '.');
    }

    $schedStmt = $conn->prepare(
        'SELECT id, installment_number FROM loan_payment_schedules WHERE release_id = ? ORDER BY installment_number ASC'
    );
    $schedStmt->execute([$releaseId]);
    $rows = $schedStmt->fetchAll(PDO::FETCH_ASSOC);

    $update = $conn->prepare('UPDATE loan_payment_schedules SET due_date = ?, status = ? WHERE id = ?');
    foreach ($rows as $row) {
        $num = (int)$row['installment_number'];
        $isOverdueSlot = $num <= OVERDUE_INSTALLMENT_TARGET;
        $dueDate = $isOverdueSlot
            ? scheduleDueDatePast($num, $frequencyKey)
            : (new DateTimeImmutable('today'))->modify('+' . (14 + ($num - OVERDUE_INSTALLMENT_TARGET) * 7) . ' days')->format('Y-m-d');
        $status = $isOverdueSlot ? 'Overdue' : 'Upcoming';
        $update->execute([$dueDate, $status, (int)$row['id']]);
    }

    recalculateLoanPaymentSchedule($loanId);
    updateLoanStatusForLoan($loanId);

    $overdue = countOverdueInstallments($conn, $releaseId);
    if ($overdue !== OVERDUE_INSTALLMENT_TARGET) {
        throw new RuntimeException("Loan {$loanId}: expected " . OVERDUE_INSTALLMENT_TARGET . " overdue installments after sync, got {$overdue}.");
    }
}

function nextSeedLoanNumber(PDO $conn): string
{
    $year = date('Y');
    $stmt = $conn->prepare(
        "SELECT MAX(CAST(SUBSTRING_INDEX(loan_number, '-', -1) AS UNSIGNED)) FROM loan_releases WHERE loan_number LIKE ?"
    );
    $stmt->execute(["RJRR-LOAN-{$year}-%"]);
    $maxSeq = (int)($stmt->fetchColumn() ?: 0);
    $next = max($maxSeq + 1, 900201);

    return sprintf('RJRR-LOAN-%s-%06d', $year, $next);
}

$profilesByUsername = [
    'overdue_maria' => [
        'loan_amount' => 15000.0,
        'term_months' => 3,
        'payment_frequency' => 'Monthly',
    ],
    'overdue_juan' => [
        'loan_amount' => 10000.0,
        'term_months' => 3,
        'payment_frequency' => 'Weekly',
    ],
];

$collectorId = (int)($conn->query("SELECT user_id FROM users WHERE role = 'Collector' AND status = 'Active' ORDER BY user_id ASC LIMIT 1")->fetchColumn() ?: 0);
if ($collectorId <= 0) {
    fwrite(STDERR, "No active Collector found.\n");
    exit(1);
}

$rate = getStandardMonthlyInterestRatePercent();
$results = [];

echo "=== Configure 3 overdue installments (2 borrowers) ===\n\n";

try {
    $conn->beginTransaction();

    foreach (TARGET_USERNAMES as $username) {
        $userRow = executeQuery(
            'SELECT u.user_id, u.status AS user_status, c.client_id, c.first_name, c.last_name
             FROM users u
             JOIN clients c ON c.user_id = u.user_id
             WHERE u.username = ? AND u.role = ? LIMIT 1',
            [$username, 'Borrower']
        )->fetch(PDO::FETCH_ASSOC);

        if (!$userRow) {
            throw new RuntimeException("Borrower username not found: {$username}");
        }
        if (strcasecmp((string)$userRow['user_status'], 'Active') !== 0) {
            throw new RuntimeException("Borrower {$username} is not Active.");
        }

        $clientId = (int)$userRow['client_id'];
        $userId = (int)$userRow['user_id'];
        $profile = $profilesByUsername[$username] ?? null;
        if ($profile === null) {
            throw new RuntimeException("Missing profile config for {$username}.");
        }

        $activeLoan = findActiveLoanForClient($conn, $clientId);
        if ($activeLoan !== null) {
            $loanId = (int)$activeLoan['loan_id'];
            $releaseId = (int)$activeLoan['release_id'];
            $overdueBefore = countOverdueInstallments($conn, $releaseId);
            if ($overdueBefore === OVERDUE_INSTALLMENT_TARGET) {
                echo "OK {$username}: loan_id {$loanId} already has " . OVERDUE_INSTALLMENT_TARGET . " overdue installments.\n";
            } else {
                applyExactlyThreeOverdueToLoan(
                    $conn,
                    $loanId,
                    $releaseId,
                    (string)$profile['payment_frequency'],
                    (int)$profile['term_months']
                );
                echo "Updated {$username}: loan_id {$loanId} (was {$overdueBefore} overdue -> " . OVERDUE_INSTALLMENT_TARGET . ").\n";
            }
            $loanIdUsed = $loanId;
            $releaseIdUsed = $releaseId;
        } else {
            $loanNumber = nextSeedLoanNumber($conn);
            $loanAmount = (float)$profile['loan_amount'];
            $termMonths = (int)$profile['term_months'];
            $paymentFrequency = (string)$profile['payment_frequency'];
            $mobile = executeQuery('SELECT contact FROM clients WHERE client_id = ? LIMIT 1', [$clientId])->fetchColumn() ?: '09170000000';
            $email = executeQuery('SELECT email FROM users WHERE user_id = ? LIMIT 1', [$userId])->fetchColumn() ?: "{$username}@test.local";

            executeQuery(
                'INSERT INTO loan_applications (user_id, borrower_name, mobile_number, email_address, complete_address, loan_amount, loan_term, payment_frequency, status, agreement_status, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $userId,
                    trim($userRow['first_name'] . ' ' . $userRow['last_name']),
                    $mobile,
                    $email,
                    '123 Test Street, Cebu City',
                    $loanAmount,
                    $termMonths . ' Months',
                    $paymentFrequency,
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
                    $loanNumber,
                    $loanAmount,
                    $rate,
                    $termMonths . ' Months',
                    $paymentFrequency,
                    'Released',
                    'Configure CLI',
                ]
            );
            $releaseIdUsed = (int)$conn->lastInsertId();

            $installments = buildSeedInstallments(
                $releaseIdUsed,
                $loanAmount,
                $rate,
                $termMonths,
                $paymentFrequency,
                OVERDUE_INSTALLMENT_TARGET
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
                    $loanNumber,
                    $releaseIdUsed,
                    $paymentFrequency,
                    'Accepted',
                    'Configure CLI',
                ]
            );
            $loanIdUsed = (int)$conn->lastInsertId();

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

            recalculateLoanPaymentSchedule($loanIdUsed);
            updateLoanStatusForLoan($loanIdUsed);

            $overdueAfter = countOverdueInstallments($conn, $releaseIdUsed);
            if ($overdueAfter !== OVERDUE_INSTALLMENT_TARGET) {
                throw new RuntimeException("New loan {$loanIdUsed} for {$username} has {$overdueAfter} overdue installments.");
            }
            echo "Created {$username}: loan_id {$loanIdUsed} | {$loanNumber} | overdue inst: " . OVERDUE_INSTALLMENT_TARGET . "\n";
        }

        $verifyStmt = $conn->prepare(
            "SELECT s.installment_number, s.due_date, s.total_amount_due, s.status
             FROM loan_payment_schedules s
             WHERE s.release_id = ? AND s.status = 'Overdue'
             ORDER BY s.installment_number"
        );
        $verifyStmt->execute([$releaseIdUsed]);
        $overdueRows = $verifyStmt->fetchAll(PDO::FETCH_ASSOC);
        $overdueTotal = 0.0;
        $dueDates = [];
        foreach ($overdueRows as $or) {
            $overdueTotal += (float)$or['total_amount_due'];
            $dueDates[] = $or['due_date'];
        }

        $loanStatus = (string)(executeQuery('SELECT status FROM loans WHERE loan_id = ? LIMIT 1', [$loanIdUsed])->fetchColumn() ?: '');
        $results[] = [
            'borrower' => trim($userRow['first_name'] . ' ' . $userRow['last_name']),
            'username' => $username,
            'user_status' => $userRow['user_status'],
            'loan_id' => $loanIdUsed,
            'loan_status' => $loanStatus,
            'overdue_count' => count($overdueRows),
            'due_dates' => $dueDates,
            'overdue_amount' => round($overdueTotal, 2),
        ];
    }

    $conn->commit();
    echo "\nVerification snapshot:\n";
    echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}
