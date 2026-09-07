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
        return $loanTerm;
    }

    if ($dateReleased && $dueDate) {
        $days = (int)(new DateTime($dateReleased))->diff(new DateTime($dueDate))->days;
        if ($days >= 55 && $days <= 65) {
            return $days . ' days';
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
    $releaseDateTime = new DateTimeImmutable($dateReleased);
    $termMonths = max(1, (int)$loanTermMonths);
    $normalizedFrequency = normalizePaymentFrequencyKey($paymentFrequency);
    $daysBetween = (int)$releaseDateTime->modify('+' . $termMonths . ' months')->diff($releaseDateTime)->days;

    $count = 0;
    switch ($normalizedFrequency) {
        case 'daily':
            $count = max(1, $daysBetween);
            break;
        case 'weekly':
            $count = max(1, (int)ceil($daysBetween / 7));
            break;
        case 'semi-monthly':
            $count = max(1, $termMonths * 2);
            break;
        case 'monthly':
        default:
            $count = max(1, $termMonths);
            break;
    }

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

function getNumberOfPayments(string $paymentFrequency, int $loanTermMonths, ?string $dateReleased = null, ?string $dueDate = null): int
{
    $loanTermMonths = max(1, $loanTermMonths);
    $normalized = normalizePaymentFrequencyKey($paymentFrequency);
    $durationDays = getLoanTermDurationDays($loanTermMonths, $dateReleased, $dueDate);

    switch ($normalized) {
        case 'daily':
            return max(1, $durationDays);
        case 'weekly':
            return max(1, $loanTermMonths * 4);
        case 'semi-monthly':
            return max(1, $loanTermMonths * 2);
        case 'monthly':
        default:
            return $loanTermMonths;
    }
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
