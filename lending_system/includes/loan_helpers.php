<?php

function parseLoanTermMonthsValue($loanTerm): int
{
    if (is_numeric($loanTerm)) {
        return max(1, (int)$loanTerm);
    }

    if (preg_match('/(\d+)/', (string)$loanTerm, $matches)) {
        return max(1, (int)$matches[1]);
    }

    return 0;
}

function getStandardMonthlyInterestRatePercent(): float
{
    return 5.0;
}

function calculateLoanInterestBreakdown(float $loanAmount, float $monthlyInterestRatePercent, int $loanTermMonths): array
{
    $principal = max(0.0, $loanAmount);
    $monthlyRatePercent = max(0.0, $monthlyInterestRatePercent);
    $termMonths = max(1, $loanTermMonths);
    $monthlyInterest = $principal * ($monthlyRatePercent / 100);
    $totalInterest = $monthlyInterest * $termMonths;
    $collectablePayment = $principal + $totalInterest;

    return [
        'monthly_interest' => round($monthlyInterest, 2),
        'total_interest' => round($totalInterest, 2),
        'total_payable' => round($collectablePayment, 2),
    ];
}

function resolveLoanTermMonths(?string $loanTerm, ?string $dateReleased, ?string $dueDate): int
{
    $termMonths = parseLoanTermMonthsValue($loanTerm ?? '');
    if ($termMonths > 0) {
        return $termMonths;
    }

    if ($dateReleased && $dueDate) {
        $start = new DateTime($dateReleased);
        $end = new DateTime($dueDate);
        $days = (int)$start->diff($end)->days;
        if ($days > 0) {
            return max(1, (int)round($days / 30));
        }
    }

    return 12;
}

function resolvePaymentFrequency(?string $loanFrequency, ?string $releaseFrequency, ?string $dateReleased = null, ?string $dueDate = null): string
{
    $frequency = trim((string)($loanFrequency ?: $releaseFrequency ?: ''));
    if ($frequency !== '') {
        return $frequency;
    }

    if ($dateReleased && $dueDate) {
        $days = (int)(new DateTime($dateReleased))->diff(new DateTime($dueDate))->days;
        if ($days >= 55 && $days <= 65) {
            return 'Daily';
        }
    }

    return 'Monthly';
}

function formatLoanTermLabel(?string $loanTerm, ?string $dateReleased = null, ?string $dueDate = null): string
{
    $loanTerm = trim((string)($loanTerm ?? ''));
    if ($loanTerm !== '') {
        $normalizedTerm = strtolower($loanTerm);
        if (in_array($normalizedTerm, ['58 days', '60 days', '58 day', '60 day'], true)) {
            return '2 months';
        }
        return $loanTerm;
    }

    if ($dateReleased && $dueDate) {
        $days = (int)(new DateTime($dateReleased))->diff(new DateTime($dueDate))->days;
        if ($days >= 55 && $days <= 65) {
            return '2 months';
        }
        if ($days > 0) {
            $months = max(1, (int)round($days / 30));
            return $months . ' month' . ($months === 1 ? '' : 's');
        }
    }

    return 'N/A';
}

function normalizePaymentFrequencyKey(string $paymentFrequency): string
{
    $normalized = strtolower(trim($paymentFrequency));

    if ($normalized === '') {
        return 'monthly';
    }

    if (strpos($normalized, 'daily') !== false) {
        return 'daily';
    }

    if (strpos($normalized, 'weekly') !== false) {
        return 'weekly';
    }

    if (strpos($normalized, 'semi') !== false || strpos($normalized, '15 days') !== false || strpos($normalized, 'half of the month') !== false) {
        return 'semi-monthly';
    }

    return 'monthly';
}

function formatPaymentFrequencyLabel(string $paymentFrequency): string
{
    $normalized = normalizePaymentFrequencyKey($paymentFrequency);

    switch ($normalized) {
        case 'daily':
            return 'Daily';
        case 'weekly':
            return 'Weekly';
        case 'semi-monthly':
            return 'Semi-Monthly';
        case 'monthly':
        default:
            return 'Monthly';
    }
}

function getLoanTermDurationDays(int $loanTermMonths, ?string $dateReleased = null, ?string $dueDate = null): int
{
    if ($dateReleased && $dueDate) {
        try {
            $start = new DateTime($dateReleased);
            $end = new DateTime($dueDate);
            $days = (int)$start->diff($end)->days;
            return max(1, $days);
        } catch (Exception $e) {
            // If the dates are invalid, fall back to a month-based estimate.
        }
    }

    return max(1, $loanTermMonths * 30);
}

function calculateLoanDueDate(string $dateReleased, string $paymentFrequency, int $loanTermMonths): string
{
    $termMonths = max(1, (int)$loanTermMonths);
    $normalizedFrequency = normalizePaymentFrequencyKey($paymentFrequency);
    $count = getScheduleInstallmentCount($paymentFrequency, $termMonths);

    $currentDate = new DateTime($dateReleased);
    for ($i = 1; $i <= $count; $i++) {
        if ($normalizedFrequency === 'daily') {
            $currentDate->modify('+1 day');
        } elseif ($normalizedFrequency === 'weekly') {
            $currentDate->modify('+7 days');
        } elseif ($normalizedFrequency === 'semi-monthly') {
            $currentDate->modify('+15 days');
        } else {
            $currentDate->modify('+1 month');
        }
    }

    return $currentDate->format('Y-m-d');
}

function getScheduleInstallmentCount(string $paymentFrequency, int $loanTermMonths, ?string $dateReleased = null, ?string $dueDate = null): int
{
    $loanTermMonths = max(1, $loanTermMonths);
    $normalized = normalizePaymentFrequencyKey($paymentFrequency);

    switch ($normalized) {
        case 'daily':
            if ($dateReleased && $dueDate) {
                try {
                    $start = new DateTimeImmutable($dateReleased);
                    $end = new DateTimeImmutable($dueDate);
                    if ($end >= $start) {
                        $daySpan = (int)$start->diff($end)->days;
                        return max(1, $daySpan + 1);
                    }
                } catch (Exception $e) {
                    // Fall back to month-based estimate below.
                }
            }
            return max(1, $loanTermMonths * 30);
        case 'weekly':
            // Four payments per month (e.g. 3 months => 12 weekly payments).
            return max(1, $loanTermMonths * 4);
        case 'semi-monthly':
            // Twice per month (e.g. 3 months => 6 payments).
            return max(1, $loanTermMonths * 2);
        case 'monthly':
        default:
            return max(1, $loanTermMonths);
    }
}

function getNumberOfPayments(string $paymentFrequency, int $loanTermMonths, ?string $dateReleased = null, ?string $dueDate = null): int
{
    return getScheduleInstallmentCount($paymentFrequency, $loanTermMonths, $dateReleased, $dueDate);
}

function getPenaltyRatePercentForFrequency(string $paymentFrequency): float
{
    switch (normalizePaymentFrequencyKey($paymentFrequency)) {
        case 'daily':
            return 1.0;
        case 'weekly':
            return 2.0;
        case 'semi-monthly':
            return 3.0;
        case 'monthly':
        default:
            return 5.0;
    }
}

function calculateOverduePenaltyDue(int $loanId, ?string $asOfDate = null): float
{
    require_once __DIR__ . '/db_lending.php';

    $loan = executeQuery(
        'SELECT l.release_id, l.payment_frequency, r.payment_frequency AS release_frequency
         FROM loans l
         LEFT JOIN loan_releases r ON r.id = l.release_id
         WHERE l.loan_id = ? LIMIT 1',
        [$loanId]
    )->fetch(PDO::FETCH_ASSOC);

    if (!$loan || empty($loan['release_id'])) {
        return 0.0;
    }

    $frequency = resolvePaymentFrequency($loan['payment_frequency'] ?? null, $loan['release_frequency'] ?? null);
    $ratePercent = getPenaltyRatePercentForFrequency($frequency);
    $releaseId = (int)$loan['release_id'];
    $asOfDate = $asOfDate ?? date('Y-m-d');

    $scheduleRows = executeQuery(
        'SELECT installment_number, total_amount_due, due_date, status
         FROM loan_payment_schedules
         WHERE release_id = ?
         ORDER BY installment_number ASC',
        [$releaseId]
    )->fetchAll(PDO::FETCH_ASSOC);

    $paidPenalties = (float)(executeQuery(
        'SELECT COALESCE(SUM(penalty_paid), 0) FROM payments WHERE loan_id = ?',
        [$loanId]
    )->fetchColumn() ?? 0);

    $penaltyCredit = $paidPenalties;
    $outstanding = 0.0;

    foreach ($scheduleRows as $row) {
        $dueDate = trim((string)($row['due_date'] ?? ''));
        $status = trim((string)($row['status'] ?? ''));
        if ($dueDate === '' || $dueDate >= $asOfDate) {
            continue;
        }

        $installmentPenalty = round((float)($row['total_amount_due'] ?? 0) * ($ratePercent / 100), 2);
        if ($installmentPenalty <= 0) {
            continue;
        }

        $applied = min($penaltyCredit, $installmentPenalty);
        $penaltyCredit = round($penaltyCredit - $applied, 2);

        if (strcasecmp($status, 'Paid') !== 0) {
            $outstanding += round($installmentPenalty - $applied, 2);
        }
    }

    return max(0.0, round($outstanding, 2));
}

function validatePaymentAmounts(int $loanId, float $totalReceived, float $penaltyPaid): void
{
    $penaltyPaid = max(0.0, round($penaltyPaid, 2));
    $totalReceived = max(0.0, round($totalReceived, 2));

    if ($totalReceived <= 0) {
        throw new InvalidArgumentException('Payment amount must be greater than zero.');
    }

    if ($penaltyPaid > $totalReceived + 0.009) {
        throw new InvalidArgumentException('Penalty portion cannot exceed total payment received.');
    }

    $summary = calculateLoanBalanceSummary($loanId);
    $maxPenalty = (float)($summary['penalty_due'] ?? 0);
    $effectivePenalty = min($penaltyPaid, $maxPenalty);
    $installmentPortion = round($totalReceived - $effectivePenalty, 2);

    if ($totalReceived > ($summary['total_amount_due'] ?? 0) + 0.009) {
        throw new InvalidArgumentException(
            'Payment cannot exceed the total amount due of ₱' . number_format((float)($summary['total_amount_due'] ?? 0), 2) . ' (balance + penalties).'
        );
    }

    if ($installmentPortion > ($summary['remaining_balance'] ?? 0) + 0.009) {
        throw new InvalidArgumentException(
            'Installment portion cannot exceed the remaining loan balance of ₱' . number_format((float)($summary['remaining_balance'] ?? 0), 2) . '.'
        );
    }
}

function calculateLoanBalanceSummary(int $loanId): array
{
    require_once __DIR__ . '/db_lending.php';

    $loan = executeQuery('SELECT total_payable FROM loans WHERE loan_id = ? LIMIT 1', [$loanId])->fetch(PDO::FETCH_ASSOC);
    if (!$loan) {
        return [
            'total_payable' => 0.0,
            'total_paid' => 0.0,
            'penalty_paid' => 0.0,
            'remaining_balance' => 0.0,
            'penalty_due' => 0.0,
            'total_amount_due' => 0.0,
        ];
    }

    $paymentSummary = executeQuery(
        'SELECT COALESCE(SUM(amount_paid), 0) AS total_paid, COALESCE(SUM(penalty_paid), 0) AS penalty_paid FROM payments WHERE loan_id = ?',
        [$loanId]
    )->fetch(PDO::FETCH_ASSOC);

    $totalPayable = (float)($loan['total_payable'] ?? 0);
    $totalPaid = (float)($paymentSummary['total_paid'] ?? 0);
    $penaltyPaid = (float)($paymentSummary['penalty_paid'] ?? 0);
    $penaltyDue = calculateOverduePenaltyDue($loanId);
    $remainingBalance = max(0.0, round($totalPayable - $totalPaid, 2));

    return [
        'total_payable' => $totalPayable,
        'total_paid' => $totalPaid,
        'penalty_paid' => $penaltyPaid,
        'remaining_balance' => $remainingBalance,
        'penalty_due' => $penaltyDue,
        'total_amount_due' => round($remainingBalance + $penaltyDue, 2),
    ];
}

/**
 * Prefill for "Total Payment Received": one installment when current; balance + penalties when overdue.
 */
function calculateSuggestedPaymentReceived(int $loanId, ?array $balanceSummary = null): float
{
    require_once __DIR__ . '/db_lending.php';

    $summary = $balanceSummary ?? calculateLoanBalanceSummary($loanId);
    $penaltyDue = (float)($summary['penalty_due'] ?? 0);
    $remainingBalance = (float)($summary['remaining_balance'] ?? 0);
    $totalAmountDue = (float)($summary['total_amount_due'] ?? $remainingBalance);

    if ($penaltyDue > 0.009) {
        return round($totalAmountDue, 2);
    }

    $loan = executeQuery(
        'SELECT release_id, daily_payment FROM loans WHERE loan_id = ? LIMIT 1',
        [$loanId]
    )->fetch(PDO::FETCH_ASSOC);

    if (!$loan) {
        return round(max(0.0, min($remainingBalance, $totalAmountDue)), 2);
    }

    $installmentAmount = 0.0;
    $releaseId = (int)($loan['release_id'] ?? 0);
    if ($releaseId > 0) {
        $nextRow = executeQuery(
            'SELECT total_amount_due FROM loan_payment_schedules
             WHERE release_id = ? AND status <> ?
             ORDER BY installment_number ASC
             LIMIT 1',
            [$releaseId, 'Paid']
        )->fetch(PDO::FETCH_ASSOC);
        if ($nextRow) {
            $installmentAmount = (float)($nextRow['total_amount_due'] ?? 0);
        }
    }

    if ($installmentAmount <= 0) {
        $installmentAmount = (float)($loan['daily_payment'] ?? 0);
    }

    if ($installmentAmount <= 0 && $releaseId > 0) {
        $firstRow = executeQuery(
            'SELECT total_amount_due FROM loan_payment_schedules WHERE release_id = ? ORDER BY installment_number ASC LIMIT 1',
            [$releaseId]
        )->fetch(PDO::FETCH_ASSOC);
        $installmentAmount = (float)($firstRow['total_amount_due'] ?? 0);
    }

    if ($installmentAmount <= 0) {
        return round(max(0.0, $remainingBalance), 2);
    }

    return round(min($installmentAmount, $remainingBalance), 2);
}

function fetchPenaltyCollectionStats(string $startDate, string $endDate): array
{
    require_once __DIR__ . '/db_lending.php';

    $lifetimeRow = executeQuery('SELECT COALESCE(SUM(penalty_paid), 0) AS total, COUNT(*) AS tx_count FROM payments WHERE penalty_paid > 0')->fetch(PDO::FETCH_ASSOC) ?: [];
    $periodRow = executeQuery(
        'SELECT COALESCE(SUM(penalty_paid), 0) AS total, COUNT(*) AS tx_count FROM payments WHERE penalty_paid > 0 AND DATE(payment_date) BETWEEN ? AND ?',
        [$startDate, $endDate]
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'lifetime_collected' => (float)($lifetimeRow['total'] ?? 0),
        'lifetime_transactions' => (int)($lifetimeRow['tx_count'] ?? 0),
        'period_collected' => (float)($periodRow['total'] ?? 0),
        'period_transactions' => (int)($periodRow['tx_count'] ?? 0),
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function fetchPenaltyCollectionsInPeriod(string $startDate, string $endDate, int $limit = 200): array
{
    require_once __DIR__ . '/db_lending.php';

    $limit = max(1, min(500, $limit));
    $stmt = executeQuery(
        'SELECT p.payment_id, p.payment_date, p.penalty_paid, p.amount_paid, p.receipt_number, p.collector_name,
                l.loan_id, l.loan_number, c.client_id, c.first_name, c.last_name
         FROM payments p
         INNER JOIN loans l ON l.loan_id = p.loan_id
         INNER JOIN clients c ON c.client_id = l.client_id
         WHERE p.penalty_paid > 0 AND DATE(p.payment_date) BETWEEN ? AND ?
         ORDER BY p.payment_date DESC, p.payment_id DESC
         LIMIT ' . (int)$limit,
        [$startDate, $endDate]
    );

    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

/**
 * Active loans with overdue penalty still due.
 *
 * @return array<int, array<string, mixed>>
 */
function fetchBorrowersWithOutstandingPenalties(float $minimumDue = 0.01): array
{
    require_once __DIR__ . '/db_lending.php';

    $minimumDue = max(0.0, $minimumDue);
    $loanRows = executeQuery(
        "SELECT l.loan_id, l.loan_number, l.status, l.client_id, l.payment_frequency, l.date_released, l.due_date,
                c.first_name, c.last_name, c.contact,
                r.payment_frequency AS release_payment_frequency
         FROM loans l
         INNER JOIN clients c ON c.client_id = l.client_id
         LEFT JOIN loan_releases r ON r.id = l.release_id
         WHERE LOWER(TRIM(l.status)) NOT IN ('paid', 'completed', 'closed', 'cancelled', 'canceled')
         ORDER BY c.last_name, c.first_name, l.loan_id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $rows = [];
    foreach ($loanRows as $loanRow) {
        $loanId = (int)($loanRow['loan_id'] ?? 0);
        if ($loanId <= 0) {
            continue;
        }

        $summary = calculateLoanBalanceSummary($loanId);
        $penaltyDue = (float)($summary['penalty_due'] ?? 0);
        if ($penaltyDue + 0.0001 < $minimumDue) {
            continue;
        }

        $frequency = resolvePaymentFrequency(
            $loanRow['payment_frequency'] ?? null,
            $loanRow['release_payment_frequency'] ?? null,
            $loanRow['date_released'] ?? null,
            $loanRow['due_date'] ?? null
        );

        $rows[] = array_merge($loanRow, [
            'penalty_due' => $penaltyDue,
            'penalty_paid_total' => (float)($summary['penalty_paid'] ?? 0),
            'remaining_balance' => (float)($summary['remaining_balance'] ?? 0),
            'penalty_rate_percent' => getPenaltyRatePercentForFrequency($frequency),
        ]);
    }

    usort($rows, static function (array $a, array $b): int {
        return ($b['penalty_due'] <=> $a['penalty_due']) ?: strcmp((string)($a['last_name'] ?? ''), (string)($b['last_name'] ?? ''));
    });

    return $rows;
}

function allocatePaymentInterestPortion(int $loanId, float $installmentAmount): float
{
    require_once __DIR__ . '/db_lending.php';

    $loan = executeQuery('SELECT release_id FROM loans WHERE loan_id = ? LIMIT 1', [$loanId])->fetch(PDO::FETCH_ASSOC);
    if (!$loan || empty($loan['release_id'])) {
        return 0.0;
    }

    $scheduleRow = executeQuery(
        'SELECT interest_amount, total_amount_due FROM loan_payment_schedules
         WHERE release_id = ? AND status != ?
         ORDER BY installment_number ASC LIMIT 1',
        [(int)$loan['release_id'], 'Paid']
    )->fetch(PDO::FETCH_ASSOC);

    if (!$scheduleRow) {
        return 0.0;
    }

    $installmentDue = (float)($scheduleRow['total_amount_due'] ?? 0);
    if ($installmentDue <= 0 || $installmentAmount <= 0) {
        return 0.0;
    }

    $ratio = min(1.0, $installmentAmount / $installmentDue);
    return round((float)($scheduleRow['interest_amount'] ?? 0) * $ratio, 2);
}

function calculateAmountToCollect(float $totalPayable, string $paymentFrequency, int $loanTermMonths, ?float $scheduleInstallmentAmount = null, ?string $dateReleased = null, ?string $dueDate = null): float
{
    if ($scheduleInstallmentAmount !== null && $scheduleInstallmentAmount > 0) {
        return round($scheduleInstallmentAmount, 2);
    }

    $numberOfPayments = getNumberOfPayments($paymentFrequency, $loanTermMonths, $dateReleased, $dueDate);

    return $numberOfPayments > 0 ? round($totalPayable / $numberOfPayments, 2) : 0.0;
}

function resolveLoanDisplayStatus(array $loan): string
{
    $loanId = (int)($loan['loan_id'] ?? 0);
    $totalPayable = (float)($loan['total_payable'] ?? 0);
    $dueDate = $loan['due_date'] ?? null;
    $today = new DateTimeImmutable('today');

    if ($loanId > 0) {
        $paymentSummary = executeQuery('SELECT COALESCE(SUM(amount_paid), 0) AS total_paid FROM payments WHERE loan_id = ?', [$loanId])->fetch(PDO::FETCH_ASSOC);
        $totalPaid = (float)($paymentSummary['total_paid'] ?? 0);

        if ($totalPayable > 0 && $totalPaid >= $totalPayable) {
            return 'Paid';
        }
    }

    if ($loanId > 0) {
        $loanRelease = executeQuery('SELECT release_id FROM loans WHERE loan_id = ? LIMIT 1', [$loanId])->fetch(PDO::FETCH_ASSOC);
        if (!empty($loanRelease['release_id'])) {
            $overdueInstallments = (int)(executeQuery(
                'SELECT COUNT(*) FROM loan_payment_schedules WHERE release_id = ? AND status = ?',
                [(int)$loanRelease['release_id'], 'Overdue']
            )->fetchColumn() ?? 0);
            if ($overdueInstallments > 0) {
                return 'Overdue';
            }
        }
    }

    if ($dueDate && $today > new DateTimeImmutable($dueDate)) {
        return 'Overdue';
    }

    return 'Active';
}

function enrichLoanCollectionFields(array $loan): array
{
    // If a schedule final due date was provided (from queries), prefer it as the loan due_date
    if (!empty($loan['schedule_final_due'])) {
        $loan['due_date'] = $loan['schedule_final_due'];
    }

    $paymentFrequency = resolvePaymentFrequency(
        $loan['payment_frequency'] ?? null,
        $loan['release_payment_frequency'] ?? null,
        $loan['date_released'] ?? null,
        $loan['due_date'] ?? null
    );

    $loanTermMonths = resolveLoanTermMonths(
        $loan['loan_term'] ?? null,
        $loan['date_released'] ?? null,
        $loan['due_date'] ?? null
    );

    $storedEstimateAmount = isset($loan['daily_payment']) && $loan['daily_payment'] !== null && $loan['daily_payment'] !== ''
        ? (float)$loan['daily_payment']
        : 0.0;

    $scheduleAmount = isset($loan['schedule_installment_amount']) && $loan['schedule_installment_amount'] !== null
        ? (float)$loan['schedule_installment_amount']
        : null;

    $loan['payment_frequency_display'] = formatPaymentFrequencyLabel($paymentFrequency);
    $loan['loan_term_display'] = formatLoanTermLabel(
        $loan['loan_term'] ?? null,
        $loan['date_released'] ?? null,
        $loan['due_date'] ?? null
    );

    $loan['amount_to_collect'] = $storedEstimateAmount > 0
        ? round($storedEstimateAmount, 2)
        : calculateAmountToCollect(
            (float)($loan['total_payable'] ?? 0),
            $paymentFrequency,
            $loanTermMonths,
            $scheduleAmount,
            $loan['date_released'] ?? null,
            $loan['due_date'] ?? null
        );
    $loan['display_status'] = resolveLoanDisplayStatus($loan);

    return $loan;
}

function getFinalScheduleDueDate($releaseId): ?string
{
    $releaseId = (int)$releaseId;
    if ($releaseId <= 0) {
        return null;
    }

    $row = executeQuery('SELECT MAX(due_date) AS final_due FROM loan_payment_schedules WHERE release_id = ? LIMIT 1', [$releaseId])->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['final_due'])) {
        return $row['final_due'];
    }

    return null;
}

function fetchBorrowerLoanHistoryForUser(int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    return executeQuery(
        'SELECT l.*, r.loan_number, r.payment_frequency, r.released_at AS release_date, r.application_id, r.borrower_user_id
         FROM loans l
         INNER JOIN clients c ON c.client_id = l.client_id
         LEFT JOIN loan_releases r ON r.id = l.release_id
         LEFT JOIN loan_applications la ON la.id = r.application_id
         WHERE c.user_id = ? OR r.borrower_user_id = ? OR la.user_id = ?
         ORDER BY l.date_released DESC, l.loan_id DESC',
        [$userId, $userId, $userId]
    )->fetchAll(PDO::FETCH_ASSOC);
}

function borrowerUserHasReleasedLoan(int $userId): bool
{
    return count(fetchBorrowerLoanHistoryForUser($userId)) > 0;
}

function fetchBorrowerLatestLoanApplication(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $row = executeQuery(
        'SELECT * FROM loan_applications WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [$userId]
    )->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Application progress belongs on Loan Status until funds are released.
 */
function borrowerHasTrackableLoanApplication(int $userId): bool
{
    $application = fetchBorrowerLatestLoanApplication($userId);
    if (!$application) {
        return false;
    }

    $status = strtolower(trim((string)($application['status'] ?? '')));
    return !in_array($status, ['released', 'completed', 'cancelled', 'canceled'], true);
}

function borrowerLatestApplicationIsNewerThanLoan(?array $application, ?array $latestLoan): bool
{
    if (!$application || empty($application['id'])) {
        return false;
    }
    if (!$latestLoan) {
        return true;
    }

    $applicationId = (int)$application['id'];
    $loanApplicationId = (int)($latestLoan['application_id'] ?? 0);
    if ($loanApplicationId > 0) {
        return $applicationId > $loanApplicationId;
    }

    $applicationSubmittedAt = strtotime((string)($application['submitted_at'] ?? $application['created_at'] ?? '')) ?: 0;
    $loanReleasedAt = strtotime((string)($latestLoan['date_released'] ?? $latestLoan['release_date'] ?? '')) ?: 0;

    return $applicationSubmittedAt > $loanReleasedAt;
}

/**
 * My Loan portal vs Loan Status (application tracking).
 */
function borrowerShouldUseMyLoanPortal(int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    if (borrowerHasActiveUnpaidLoan($userId)) {
        return true;
    }

    if (borrowerHasTrackableLoanApplication($userId)) {
        return false;
    }

    return borrowerUserHasReleasedLoan($userId);
}

function borrowerHasActiveUnpaidLoan(int $userId): bool
{
    foreach (fetchBorrowerLoanHistoryForUser($userId) as $loan) {
        $status = strtolower(trim((string)($loan['status'] ?? '')));
        if (in_array($status, ['paid', 'completed', 'closed', 'cancelled', 'canceled'], true)) {
            continue;
        }

        $loanId = (int)($loan['loan_id'] ?? 0);
        if ($loanId <= 0) {
            continue;
        }

        $totalPayable = (float)($loan['total_payable'] ?? 0);
        $totalPaid = (float)(executeQuery('SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE loan_id = ?', [$loanId])->fetchColumn() ?: 0);
        if ($totalPayable > 0 && $totalPaid + 0.009 >= $totalPayable) {
            continue;
        }

        return true;
    }

    return false;
}

function borrowerCanApplyForNewLoan(int $userId): bool
{
    return !borrowerHasActiveUnpaidLoan($userId);
}

/**
 * Repayment record for a borrower who already received a loan and paid in this system.
 * Returns null for new borrowers and for borrowers who have not paid anything yet.
 *
 * @return array{note: string, loan_count: int, paid_in_full: int, total_paid: float, total_payable: float, penalty_paid: float, overdue_installments: int}|null
 */
function buildBorrowerInternalCreditHistory(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $loanCount = 0;
    $paidInFull = 0;
    $totalPaid = 0.0;
    $totalPayable = 0.0;
    $penaltyPaid = 0.0;
    $overdueInstallments = 0;

    foreach (fetchBorrowerLoanHistoryForUser($userId) as $loan) {
        $status = strtolower(trim((string)($loan['status'] ?? '')));
        if (in_array($status, ['cancelled', 'canceled'], true)) {
            continue;
        }

        $loanId = (int)($loan['loan_id'] ?? 0);
        if ($loanId <= 0) {
            continue;
        }

        $paidRow = executeQuery(
            'SELECT COALESCE(SUM(amount_paid), 0) AS total_paid, COALESCE(SUM(penalty_paid), 0) AS penalty_paid
             FROM payments WHERE loan_id = ?',
            [$loanId]
        )->fetch(PDO::FETCH_ASSOC);
        $paid = (float)($paidRow['total_paid'] ?? 0);
        if ($paid <= 0.009) {
            continue;
        }

        $payable = (float)($loan['total_payable'] ?? 0);
        $loanCount++;
        $totalPaid += $paid;
        $totalPayable += $payable;
        $penaltyPaid += (float)($paidRow['penalty_paid'] ?? 0);

        $fullyPaid = in_array($status, ['paid', 'completed', 'closed'], true)
            || ($payable > 0 && $paid + 0.009 >= $payable);
        if ($fullyPaid) {
            $paidInFull++;
        }

        $releaseId = (int)($loan['release_id'] ?? 0);
        if ($releaseId > 0) {
            $overdueInstallments += (int)(executeQuery(
                'SELECT COUNT(*) FROM loan_payment_schedules WHERE release_id = ? AND status = ?',
                [$releaseId, 'Overdue']
            )->fetchColumn() ?: 0);
        }
    }

    if ($loanCount === 0 || $totalPaid <= 0.009) {
        return null;
    }

    $loanWord = $loanCount === 1 ? 'previous loan' : 'previous loans';
    $summary = $loanCount . ' ' . $loanWord . ' in this system';
    if ($paidInFull === $loanCount) {
        $summary .= $loanCount === 1 ? ', fully paid' : ', all fully paid';
    } elseif ($paidInFull === 0) {
        $summary .= $loanCount === 1 ? ', not yet fully paid' : ', none fully paid';
    } else {
        $summary .= ', ' . $paidInFull . ' of ' . $loanCount . ' fully paid';
    }

    $parts = [
        $summary,
        'Collected ₱' . number_format($totalPaid, 2) . ' of ₱' . number_format($totalPayable, 2),
    ];
    if ($overdueInstallments > 0) {
        $parts[] = $overdueInstallments . ' overdue installment' . ($overdueInstallments === 1 ? '' : 's');
    } else {
        $parts[] = 'No overdue installments';
    }
    if ($penaltyPaid > 0.009) {
        $parts[] = 'Penalties paid ₱' . number_format($penaltyPaid, 2);
    } else {
        $parts[] = 'No penalties paid';
    }

    $note = implode('. ', $parts) . '.';
    if (function_exists('mb_strlen') && mb_strlen($note) > 255) {
        $note = mb_substr($note, 0, 252) . '...';
    } elseif (strlen($note) > 255) {
        $note = substr($note, 0, 252) . '...';
    }

    return [
        'note' => $note,
        'loan_count' => $loanCount,
        'paid_in_full' => $paidInFull,
        'total_paid' => round($totalPaid, 2),
        'total_payable' => round($totalPayable, 2),
        'penalty_paid' => round($penaltyPaid, 2),
        'overdue_installments' => $overdueInstallments,
    ];
}

function getBorrowerActiveLoanMonthlyObligation(int $userId): float
{
    if ($userId <= 0) {
        return 0.0;
    }

    $total = 0.0;
    foreach (fetchBorrowerLoanHistoryForUser($userId) as $loanRow) {
        $status = strtolower(trim((string)($loanRow['status'] ?? '')));
        if (in_array($status, ['paid', 'completed', 'closed', 'cancelled', 'canceled'], true)) {
            continue;
        }

        $loanId = (int)($loanRow['loan_id'] ?? 0);
        if ($loanId <= 0) {
            continue;
        }

        $totalPayable = (float)($loanRow['total_payable'] ?? 0);
        $totalPaid = (float)(executeQuery('SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE loan_id = ?', [$loanId])->fetchColumn() ?: 0);
        if ($totalPayable > 0 && $totalPaid + 0.009 >= $totalPayable) {
            continue;
        }

        $enriched = enrichLoanCollectionFields($loanRow);
        $total += (float)($enriched['amount_to_collect'] ?? 0);
    }

    return round(max(0.0, $total), 2);
}

function lendingMandatoryApplicationDocumentNames(): array
{
    return [
        'Valid Government ID',
        'Proof of Income',
        'Proof of Billing',
        'Selfie Holding Valid ID',
        'Additional Supporting Documents',
    ];
}

/**
 * @param array<int, array<string, mixed>> $documents Rows from loan_application_documents
 * @return array{
 *     uploaded_count: int,
 *     all_uploaded_verified: bool,
 *     has_rejected: bool,
 *     has_pending_uploaded: bool,
 *     all_mandatory_verified: bool,
 *     ready_for_under_review: bool
 * }
 */
function analyzeLoanApplicationDocumentVerification(array $documents): array
{
    $uploadedCount = 0;
    $hasRejected = false;
    $hasPendingUploaded = false;
    $allUploadedVerified = true;

    $mandatoryNames = array_map('strtolower', lendingMandatoryApplicationDocumentNames());
    $mandatoryState = [];
    foreach ($mandatoryNames as $mandatoryName) {
        $mandatoryState[$mandatoryName] = ['has_path' => false, 'verified' => false];
    }

    foreach ($documents as $document) {
        $documentName = strtolower(trim((string)($document['document_name'] ?? '')));
        $path = trim((string)($document['document_path'] ?? ''));
        $status = strtolower(trim((string)($document['verification_status'] ?? 'pending review')));

        if (isset($mandatoryState[$documentName])) {
            $mandatoryState[$documentName]['has_path'] = $path !== '';
            $mandatoryState[$documentName]['verified'] = $status === 'verified';
        }

        if ($path === '') {
            continue;
        }

        $uploadedCount++;
        if ($status === 'verified') {
            continue;
        }
        if ($status === 'rejected') {
            $hasRejected = true;
            $allUploadedVerified = false;
            continue;
        }

        $hasPendingUploaded = true;
        $allUploadedVerified = false;
    }

    if ($uploadedCount === 0) {
        $allUploadedVerified = false;
    }

    $allMandatoryVerified = true;
    foreach ($mandatoryState as $mandatory) {
        if (!$mandatory['has_path'] || !$mandatory['verified']) {
            $allMandatoryVerified = false;
            break;
        }
    }

    return [
        'uploaded_count' => $uploadedCount,
        'all_uploaded_verified' => $allUploadedVerified,
        'has_rejected' => $hasRejected,
        'has_pending_uploaded' => $hasPendingUploaded,
        'all_mandatory_verified' => $allMandatoryVerified,
        'ready_for_under_review' => $allMandatoryVerified && $allUploadedVerified && !$hasRejected,
    ];
}

function loanApplicationHasBeenReleased(int $applicationId): bool
{
    if ($applicationId <= 0) {
        return false;
    }

    $status = strtolower(trim((string)(executeQuery('SELECT status FROM loan_applications WHERE id = ? LIMIT 1', [$applicationId])->fetchColumn() ?: '')));
    if (in_array($status, ['released', 'completed'], true)) {
        return true;
    }

    return (bool)executeQuery('SELECT 1 FROM loan_releases WHERE application_id = ? LIMIT 1', [$applicationId])->fetchColumn();
}

/**
 * @param array<string, mixed> $application
 * @param array<int, array<string, mixed>> $documents
 * @param array<string, mixed>|null $agreementRow
 * @return array{current_index: int, display_status: string, current_step_label: string}
 */
function resolveBorrowerApplicationProgress(array $application, array $documents, ?array $agreementRow = null, bool $loanReleased = false): array
{
    $statusSteps = [
        'Application Submitted',
        'Documents Verified',
        'Under Review',
        'Approved or Rejected',
        'Loan Agreement',
        'Loan Released',
    ];

    $applicationStatus = trim((string)($application['status'] ?? 'Pending Review'));
    $statusKey = strtolower($applicationStatus);
    $documentAnalysis = analyzeLoanApplicationDocumentVerification($documents);
    $displayStatus = $applicationStatus;
    $index = 1;

    if ($loanReleased || in_array($statusKey, ['released', 'completed'], true)) {
        $index = 6;
    } elseif (in_array($statusKey, ['waiting for loan agreement', 'agreement accepted', 'ready for release'], true)) {
        $index = 5;
    } elseif ($statusKey === 'approved' || $statusKey === 'rejected') {
        $index = 4;
    } elseif ($statusKey === 'under review') {
        $index = 3;
    } elseif ($statusKey === 'documents incomplete' || $documentAnalysis['has_rejected']) {
        $index = 2;
        if ($statusKey !== 'documents incomplete') {
            $displayStatus = 'Documents Incomplete';
        }
    } elseif ($documentAnalysis['ready_for_under_review']) {
        $index = 3;
        if ($statusKey === 'pending review') {
            $displayStatus = 'Under Review';
        }
    } elseif ($documentAnalysis['uploaded_count'] > 0) {
        $index = 2;
        if ($documentAnalysis['all_uploaded_verified']) {
            $displayStatus = 'Documents Verified';
        }
    } else {
        $index = 1;
    }

    if ($agreementRow && !empty($agreementRow['id'])) {
        $index = max($index, 5);
        if (in_array($statusKey, ['pending review', 'under review', 'documents incomplete'], true)) {
            $displayStatus = 'Waiting for Loan Agreement';
        }
    }

    $index = max(1, min(count($statusSteps), $index));

    return [
        'current_index' => $index,
        'display_status' => $displayStatus,
        'current_step_label' => $statusSteps[$index - 1],
    ];
}

function syncLoanApplicationStatusAfterDocumentVerification(int $applicationId): void
{
    if ($applicationId <= 0) {
        return;
    }

    $application = executeQuery('SELECT status FROM loan_applications WHERE id = ? LIMIT 1', [$applicationId])->fetch(PDO::FETCH_ASSOC);
    if (!$application) {
        return;
    }

    $statusKey = strtolower(trim((string)($application['status'] ?? '')));
    if (!in_array($statusKey, ['pending review', 'documents incomplete', 'under review'], true)) {
        return;
    }

    $documents = executeQuery(
        'SELECT document_name, document_path, verification_status FROM loan_application_documents WHERE application_id = ?',
        [$applicationId]
    )->fetchAll(PDO::FETCH_ASSOC);
    $documentAnalysis = analyzeLoanApplicationDocumentVerification($documents);

    if ($documentAnalysis['ready_for_under_review']) {
        executeQuery(
            "UPDATE loan_applications SET status = 'Under Review' WHERE id = ? AND status IN ('Pending Review', 'Documents Incomplete')",
            [$applicationId]
        );
        return;
    }

    if ($documentAnalysis['has_rejected']) {
        executeQuery(
            "UPDATE loan_applications SET status = 'Documents Incomplete' WHERE id = ? AND status IN ('Pending Review', 'Under Review')",
            [$applicationId]
        );
    }
}
