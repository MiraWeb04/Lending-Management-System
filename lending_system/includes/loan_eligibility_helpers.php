<?php
/**
 * Automated loan eligibility estimates (underwriting review aid — not final approval).
 */

require_once __DIR__ . '/env_lending.php';
require_once __DIR__ . '/loan_helpers.php';

function ensureLoanEligibilitySchema(): void
{
    global $conn;

    $columns = [
        'monthly_gross_income' => "ALTER TABLE loan_applications ADD COLUMN monthly_gross_income DECIMAL(12,2) DEFAULT NULL AFTER monthly_income",
        'monthly_net_income' => "ALTER TABLE loan_applications ADD COLUMN monthly_net_income DECIMAL(12,2) DEFAULT NULL AFTER monthly_gross_income",
        'monthly_debt_payments' => "ALTER TABLE loan_applications ADD COLUMN monthly_debt_payments DECIMAL(12,2) DEFAULT NULL AFTER monthly_net_income",
        'other_monthly_obligations' => "ALTER TABLE loan_applications ADD COLUMN other_monthly_obligations DECIMAL(12,2) DEFAULT NULL AFTER monthly_debt_payments",
        'number_of_dependents' => "ALTER TABLE loan_applications ADD COLUMN number_of_dependents INT UNSIGNED DEFAULT NULL AFTER other_monthly_obligations",
        'credit_history_note' => "ALTER TABLE loan_applications ADD COLUMN credit_history_note VARCHAR(255) DEFAULT NULL AFTER number_of_dependents",
        'collateral_note' => "ALTER TABLE loan_applications ADD COLUMN collateral_note VARCHAR(255) DEFAULT NULL AFTER credit_history_note",
        'eligibility_status' => "ALTER TABLE loan_applications ADD COLUMN eligibility_status VARCHAR(40) DEFAULT NULL AFTER collateral_note",
        'eligibility_assessment_json' => "ALTER TABLE loan_applications ADD COLUMN eligibility_assessment_json LONGTEXT DEFAULT NULL AFTER eligibility_status",
        'eligibility_assessed_at' => "ALTER TABLE loan_applications ADD COLUMN eligibility_assessed_at DATETIME DEFAULT NULL AFTER eligibility_assessment_json",
    ];

    foreach ($columns as $sql) {
        try {
            $conn->exec($sql);
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'duplicate column') === false) {
                error_log('Eligibility schema: ' . $e->getMessage());
            }
        }
    }
}

function lendingCreditPolicy(): array
{
    return [
        'max_dti_percent' => (float)lendingEnv('LENDING_MAX_DTI', 40),
        'min_loan_amount' => (float)lendingEnv('LENDING_MIN_LOAN', 3000),
        'max_loan_amount' => (float)lendingEnv('LENDING_MAX_LOAN', 500000),
        'min_employment_months' => (int)lendingEnv('LENDING_MIN_EMPLOYMENT_MONTHS', 3),
        'monthly_interest_rate_percent' => getStandardMonthlyInterestRatePercent(),
        'disclaimer' => 'This is an automated eligibility estimate for reviewer use only. It is not a final lending decision or automatic approval.',
    ];
}

function parseEmploymentLengthMonths(?string $employmentLength, ?string $yearsInService = null, ?string $yearsInBusiness = null): ?int
{
    $combined = trim(implode(' ', array_filter([
        (string)$employmentLength,
        (string)$yearsInService,
        (string)$yearsInBusiness,
    ])));

    if ($combined === '') {
        return null;
    }

    if (preg_match('/(\d+(?:\.\d+)?)\s*(year|yr|yrs)/i', $combined, $m)) {
        return (int)round((float)$m[1] * 12);
    }
    if (preg_match('/(\d+(?:\.\d+)?)\s*(month|mo)/i', $combined, $m)) {
        return (int)round((float)$m[1]);
    }
    if (preg_match('/^(\d+)$/', $combined, $m)) {
        return (int)$m[1] >= 3 ? (int)$m[1] * 12 : (int)$m[1];
    }

    return null;
}

function calculateProposedMonthlyLoanPayment(float $principal, float $monthlyInterestRatePercent, int $loanTermMonths): float
{
    if ($principal <= 0 || $loanTermMonths <= 0) {
        return 0.0;
    }

    $breakdown = calculateLoanInterestBreakdown($principal, $monthlyInterestRatePercent, $loanTermMonths);

    return round($breakdown['total_payable'] / $loanTermMonths, 2);
}

function calculateMaxPrincipalFromMonthlyPayment(float $maxMonthlyPayment, float $monthlyInterestRatePercent, int $loanTermMonths): float
{
    if ($maxMonthlyPayment <= 0 || $loanTermMonths <= 0) {
        return 0.0;
    }

    $totalPayable = $maxMonthlyPayment * $loanTermMonths;
    $factor = 1 + (($monthlyInterestRatePercent / 100) * $loanTermMonths);
    if ($factor <= 0) {
        return 0.0;
    }

    return round($totalPayable / $factor, 2);
}

function clampLoanAmountToPolicy(float $amount, array $policy): float
{
    $min = (float)($policy['min_loan_amount'] ?? 0);
    $max = (float)($policy['max_loan_amount'] ?? PHP_FLOAT_MAX);

    return round(max($min, min($max, $amount)), 2);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function assessLoanEligibility(array $input): array
{
    ensureLoanEligibilitySchema();
    $policy = lendingCreditPolicy();

    $missing = [];
    $gross = isset($input['monthly_gross_income']) && $input['monthly_gross_income'] !== ''
        ? (float)$input['monthly_gross_income']
        : null;
    $net = isset($input['monthly_net_income']) && $input['monthly_net_income'] !== ''
        ? (float)$input['monthly_net_income']
        : null;
    $incomeFallback = isset($input['monthly_income']) && $input['monthly_income'] !== ''
        ? (float)$input['monthly_income']
        : null;

    if ($gross === null || $gross <= 0) {
        if ($incomeFallback !== null && $incomeFallback > 0) {
            $gross = $incomeFallback;
            $input['_income_note'] = 'Monthly gross income was not provided; monthly income field was used for DTI.';
        } else {
            $missing[] = 'Monthly gross income (or monthly income)';
        }
    }

    $employmentStatus = trim((string)($input['employment_status'] ?? ''));
    if ($employmentStatus === '') {
        $missing[] = 'Employment status';
    }

    $requestedAmount = isset($input['loan_amount']) && $input['loan_amount'] !== ''
        ? (float)$input['loan_amount']
        : 0.0;
    if ($requestedAmount <= 0) {
        $missing[] = 'Requested loan amount';
    }

    $loanTermRaw = $input['loan_term'] ?? $input['loan_term_months'] ?? '';
    $termMonths = parseLoanTermMonthsValue($loanTermRaw);
    if ($termMonths <= 0) {
        $missing[] = 'Loan term';
    }

    $existingDebt = (float)($input['monthly_debt_payments'] ?? 0);
    $otherObligations = (float)($input['other_monthly_obligations'] ?? 0);
    $totalExistingDebt = max(0, $existingDebt + $otherObligations);

    $rate = (float)($input['interest_rate'] ?? $policy['monthly_interest_rate_percent']);
    $maxDti = (float)$policy['max_dti_percent'];

    $reasons = [];
    $documentsNeeded = [];
    $status = 'Not Eligible';

    if (count($missing) > 0) {
        return [
            'status' => 'Not Eligible',
            'status_code' => 'incomplete',
            'missing_fields' => $missing,
            'reasons' => ['Required financial information is incomplete. Do not guess missing values.'],
            'documents_or_info_required' => array_merge($missing, ['Supporting income documents', 'Valid ID', 'Proof of income']),
            'policy' => $policy,
            'disclaimer' => $policy['disclaimer'],
        ];
    }

    $proposedPayment = calculateProposedMonthlyLoanPayment($requestedAmount, $rate, $termMonths);
    $dtiBefore = $gross > 0 ? round(($totalExistingDebt / $gross) * 100, 2) : 0.0;
    $dtiAfter = $gross > 0 ? round((($totalExistingDebt + $proposedPayment) / $gross) * 100, 2) : 0.0;

    $maxTotalDebtPayment = $gross * ($maxDti / 100);
    $maxAffordablePayment = max(0, $maxTotalDebtPayment - $totalExistingDebt);
    $maxPrincipalRaw = calculateMaxPrincipalFromMonthlyPayment($maxAffordablePayment, $rate, $termMonths);
    $maxEligibleLoan = clampLoanAmountToPolicy($maxPrincipalRaw, $policy);

    $eligibleForRequested = min($requestedAmount, $maxEligibleLoan);
    $eligibleForRequested = clampLoanAmountToPolicy($eligibleForRequested, $policy);

    $employmentMonths = parseEmploymentLengthMonths(
        $input['employment_length'] ?? null,
        $input['years_in_service'] ?? null,
        $input['years_in_business'] ?? null
    );

    if ($net !== null && $net > 0 && $net > $gross) {
        $reasons[] = 'Net income exceeds gross income; please verify income figures.';
        $documentsNeeded[] = 'Updated proof of income';
    }

    if ($employmentMonths !== null && $employmentMonths < (int)$policy['min_employment_months']) {
        $reasons[] = 'Employment or business length is below the minimum ' . (int)$policy['min_employment_months'] . ' month policy guideline.';
        $documentsNeeded[] = 'Certificate of employment or business registration showing tenure';
    } elseif ($employmentMonths === null) {
        $documentsNeeded[] = 'Employment tenure or length of business operation';
    }

    if ($requestedAmount < (float)$policy['min_loan_amount']) {
        $reasons[] = 'Requested amount is below the minimum loan limit of ₱' . number_format((float)$policy['min_loan_amount'], 2) . '.';
    }
    if ($requestedAmount > (float)$policy['max_loan_amount']) {
        $reasons[] = 'Requested amount exceeds the maximum loan limit of ₱' . number_format((float)$policy['max_loan_amount'], 2) . '.';
    }

    if ($dtiAfter > $maxDti + 0.001) {
        $reasons[] = 'Debt-to-income after the proposed loan (' . number_format($dtiAfter, 1) . '%) exceeds the maximum ' . number_format($maxDti, 1) . '% limit.';
        $status = 'Not Eligible';
    } elseif ($requestedAmount > $maxEligibleLoan + 0.01) {
        $status = 'Conditionally Eligible';
        $reasons[] = 'Requested amount exceeds the estimated maximum affordable loan of ₱' . number_format($maxEligibleLoan, 2) . '; a lower amount may be considered.';
    } elseif (count($reasons) > 0) {
        $status = 'Conditionally Eligible';
        $reasons[] = 'Affordability metrics are within DTI limits pending verification of submitted documents.';
    } else {
        $status = 'Eligible';
        $reasons[] = 'Affordability estimate is within configured DTI and loan amount limits; subject to document verification and manual underwriting.';
    }

    if (!empty($input['_income_note'])) {
        $reasons[] = (string)$input['_income_note'];
    }

    $creditHistoryNote = trim((string)($input['credit_history_note'] ?? ''));
    if ($creditHistoryNote !== '') {
        $reasons[] = 'Credit history from previous loans in this system: ' . $creditHistoryNote;
    }

    $eligiblePayment = calculateProposedMonthlyLoanPayment($eligibleForRequested, $rate, $termMonths);
    $eligibleDtiAfter = $gross > 0
        ? round((($totalExistingDebt + $eligiblePayment) / $gross) * 100, 2)
        : 0.0;

    return [
        'status' => $status,
        'status_code' => strtolower(str_replace(' ', '_', $status)),
        'monthly_gross_income' => round($gross, 2),
        'monthly_net_income' => $net !== null ? round($net, 2) : null,
        'existing_monthly_debt' => round($totalExistingDebt, 2),
        'max_dti_percent' => $maxDti,
        'max_affordable_monthly_payment' => round($maxAffordablePayment, 2),
        'requested_loan_amount' => round($requestedAmount, 2),
        'estimated_eligible_loan_amount' => round($eligibleForRequested, 2),
        'maximum_eligible_loan_amount' => round($maxEligibleLoan, 2),
        'proposed_monthly_payment' => round($proposedPayment, 2),
        'estimated_monthly_payment' => round($eligiblePayment, 2),
        'dti_before_percent' => $dtiBefore,
        'dti_after_percent' => $dtiAfter,
        'dti_after_eligible_percent' => $eligibleDtiAfter,
        'loan_term_months' => $termMonths,
        'loan_term_label' => trim((string)$loanTermRaw),
        'interest_rate_percent' => $rate,
        'employment_status' => $employmentStatus,
        'employment_months_estimated' => $employmentMonths,
        'reasons' => $reasons,
        'missing_fields' => [],
        'documents_or_info_required' => array_values(array_unique($documentsNeeded)),
        'policy' => $policy,
        'disclaimer' => $policy['disclaimer'],
    ];
}

/**
 * @param array<string, mixed> $application
 */
/**
 * @param array<string, mixed> $step2
 * @param array<string, mixed> $step3
 */
function buildLoanEligibilityInputFromWizard(array $step2, array $step3, ?float $interestRate = null): array
{
    $monthlyIncome = trim((string)($step2['monthly_income'] ?? ''));
    $monthlySalary = trim((string)($step2['monthly_salary'] ?? ''));
    $monthlyPension = trim((string)($step2['monthly_pension'] ?? ''));
    $incomeFallback = $monthlyIncome !== ''
        ? $monthlyIncome
        : ($monthlySalary !== '' ? $monthlySalary : $monthlyPension);

    return [
        'monthly_gross_income' => $step2['monthly_gross_income'] ?? null,
        'monthly_net_income' => $step2['monthly_net_income'] ?? null,
        'monthly_income' => $incomeFallback,
        'monthly_debt_payments' => $step2['monthly_debt_payments'] ?? 0,
        'other_monthly_obligations' => $step2['other_monthly_obligations'] ?? 0,
        'employment_status' => $step2['employment_status'] ?? '',
        'employment_length' => $step2['employment_length'] ?? '',
        'years_in_service' => $step2['years_in_service'] ?? '',
        'years_in_business' => $step2['years_in_business'] ?? '',
        'loan_amount' => $step3['loan_amount'] ?? 0,
        'loan_term' => $step3['loan_term'] ?? '',
        'interest_rate' => $interestRate ?? getStandardMonthlyInterestRatePercent(),
        'number_of_dependents' => $step2['number_of_dependents'] ?? null,
        'collateral_note' => $step2['collateral_note'] ?? '',
        'credit_history_note' => $step2['credit_history_note'] ?? '',
    ];
}

/**
 * @param array<string, mixed> $step2
 * @return array<string, mixed>
 */
function loanApplicationFinancialFieldsFromStep2(array $step2, int $userId = 0): array
{
    ensureLoanEligibilitySchema();
    if ($userId > 0) {
        $history = buildBorrowerInternalCreditHistory($userId);
        $step2['credit_history_note'] = is_array($history) ? (string)$history['note'] : '';
    }

    $nullableDecimal = static function ($value) {
        $value = trim((string)$value);
        return $value !== '' ? $value : null;
    };
    $dependents = trim((string)($step2['number_of_dependents'] ?? ''));
    $creditHistoryNote = trim((string)($step2['credit_history_note'] ?? ''));

    return [
        'monthly_gross_income' => $nullableDecimal($step2['monthly_gross_income'] ?? ''),
        'monthly_net_income' => $nullableDecimal($step2['monthly_net_income'] ?? ''),
        'monthly_debt_payments' => $nullableDecimal($step2['monthly_debt_payments'] ?? '') ?? 0,
        'other_monthly_obligations' => $nullableDecimal($step2['other_monthly_obligations'] ?? '') ?? 0,
        'number_of_dependents' => $dependents !== '' ? (int)$dependents : null,
        'collateral_note' => trim((string)($step2['collateral_note'] ?? '')) ?: null,
        'credit_history_note' => $creditHistoryNote !== '' ? $creditHistoryNote : null,
    ];
}

function buildLoanEligibilityInputFromApplication(array $application): array
{
    $monthlyIncome = $application['monthly_income'] ?? null;
    if (($monthlyIncome === null || $monthlyIncome === '') && !empty($application['monthly_pension'])) {
        $monthlyIncome = $application['monthly_pension'];
    }

    return [
        'monthly_gross_income' => $application['monthly_gross_income'] ?? null,
        'monthly_net_income' => $application['monthly_net_income'] ?? null,
        'monthly_income' => $monthlyIncome,
        'monthly_debt_payments' => $application['monthly_debt_payments'] ?? 0,
        'other_monthly_obligations' => $application['other_monthly_obligations'] ?? 0,
        'employment_status' => $application['employment_status'] ?? '',
        'employment_length' => $application['employment_length'] ?? '',
        'years_in_service' => $application['years_in_service'] ?? '',
        'years_in_business' => $application['years_in_business'] ?? '',
        'loan_amount' => $application['loan_amount'] ?? 0,
        'loan_term' => $application['loan_term'] ?? '',
        'interest_rate' => $application['monthly_interest_rate'] ?? getStandardMonthlyInterestRatePercent(),
        'number_of_dependents' => $application['number_of_dependents'] ?? null,
        'collateral_note' => $application['collateral_note'] ?? '',
        'credit_history_note' => $application['credit_history_note'] ?? '',
    ];
}

function persistLoanEligibilityAssessment(int $applicationId, array $assessment): void
{
    ensureLoanEligibilitySchema();
    executeQuery(
        'UPDATE loan_applications SET eligibility_status = ?, eligibility_assessment_json = ?, eligibility_assessed_at = NOW() WHERE id = ?',
        [
            (string)($assessment['status'] ?? ''),
            json_encode($assessment, JSON_UNESCAPED_UNICODE),
            $applicationId,
        ]
    );
}

function formatMoneyPhp(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

/**
 * @param array<string, mixed> $step2
 */
function resolveStep2GrossIncome(array $step2): ?float
{
    $grossRaw = trim((string)($step2['monthly_gross_income'] ?? ''));
    if ($grossRaw !== '' && (float)$grossRaw > 0) {
        return (float)$grossRaw;
    }

    $monthlyIncome = trim((string)($step2['monthly_income'] ?? ''));
    $monthlySalary = trim((string)($step2['monthly_salary'] ?? ''));
    $monthlyPension = trim((string)($step2['monthly_pension'] ?? ''));
    $fallback = $monthlyIncome !== ''
        ? $monthlyIncome
        : ($monthlySalary !== '' ? $monthlySalary : $monthlyPension);

    if ($fallback !== '' && (float)$fallback > 0) {
        return (float)$fallback;
    }

    return null;
}

/**
 * @param array<string, mixed> $step2
 * @return array<string, mixed>
 */
function applyStep2EmploymentGrossIncomeSync(array $step2): array
{
    if (trim((string)($step2['monthly_gross_income'] ?? '')) !== '') {
        return $step2;
    }

    $gross = resolveStep2GrossIncome($step2);
    if ($gross === null || $gross <= 0) {
        return $step2;
    }

    $formatted = abs($gross - round($gross)) < 0.001
        ? (string)(int)round($gross)
        : rtrim(rtrim(number_format($gross, 2, '.', ''), '0'), '.');
    $step2['monthly_gross_income'] = $formatted;

    return $step2;
}

/**
 * Step 2 → 3: block when income/debt already fails affordability (before loan amount is chosen).
 *
 * @param array<string, mixed> $step2
 */
function loanEligibilityBlocksStep2Progress(array $step2): ?string
{
    $policy = lendingCreditPolicy();
    $maxDti = (float)$policy['max_dti_percent'];

    $employmentStatus = trim((string)($step2['employment_status'] ?? ''));
    if ($employmentStatus === '') {
        return 'Please select your employment status before continuing to loan details.';
    }

    $gross = resolveStep2GrossIncome($step2);
    if ($gross === null || $gross <= 0) {
        return 'Please enter your monthly income (employment section or gross income below) so we can check affordability before you choose a loan amount.';
    }

    $debt = max(0.0, (float)($step2['monthly_debt_payments'] ?? 0) + (float)($step2['other_monthly_obligations'] ?? 0));
    $dtiBefore = round(($debt / $gross) * 100, 2);
    $maxTotalPayment = $gross * ($maxDti / 100);
    $roomForNewLoan = $maxTotalPayment - $debt;

    if ($dtiBefore > $maxDti + 0.001) {
        return 'Your existing monthly obligations (' . formatMoneyPhp($debt) . ') are '
            . number_format($dtiBefore, 1) . '% of your income, which exceeds the '
            . number_format($maxDti, 1) . '% affordability guideline. You cannot proceed to loan details until debt is lower or income figures are corrected.';
    }

    if ($roomForNewLoan <= 0.009) {
        return 'Your existing obligations already use the maximum '
            . number_format($maxDti, 1) . '% of income allowed for debt payments ('
            . formatMoneyPhp($maxTotalPayment) . ' per month). There is no room for an additional loan. Update your income or debt information if something was entered incorrectly.';
    }

    return null;
}

/**
 * @param array<string, mixed> $step2
 * @return array{monthly_gross_income: float, existing_monthly_debt: float, dti_before_percent: float, max_dti_percent: float, max_affordable_monthly_payment: float, room_for_new_loan_payment: float}|null
 */
function summarizeStep2Affordability(array $step2): ?array
{
    $gross = resolveStep2GrossIncome($step2);
    if ($gross === null || $gross <= 0) {
        return null;
    }

    $policy = lendingCreditPolicy();
    $maxDti = (float)$policy['max_dti_percent'];
    $debt = max(0.0, (float)($step2['monthly_debt_payments'] ?? 0) + (float)($step2['other_monthly_obligations'] ?? 0));
    $maxTotalPayment = $gross * ($maxDti / 100);

    return [
        'monthly_gross_income' => round($gross, 2),
        'existing_monthly_debt' => round($debt, 2),
        'dti_before_percent' => round(($debt / $gross) * 100, 2),
        'max_dti_percent' => $maxDti,
        'max_affordable_monthly_payment' => round($maxTotalPayment, 2),
        'room_for_new_loan_payment' => round(max(0, $maxTotalPayment - $debt), 2),
    ];
}

/**
 * Returns an error message when the borrower must not advance past loan details (step 3); null if OK.
 */
function loanEligibilityBlocksWizardProgress(array $assessment): ?string
{
    if (($assessment['status_code'] ?? '') === 'incomplete') {
        $missing = $assessment['missing_fields'] ?? [];
        $list = count($missing) > 0 ? implode(', ', $missing) : 'required income and loan details';

        return 'We cannot verify an affordable loan amount yet. Please complete Step 2 (Employment & income), including income and debt information, then adjust your loan amount: ' . $list . '.';
    }

    $requested = (float)($assessment['requested_loan_amount'] ?? 0);
    $maxEligible = (float)($assessment['maximum_eligible_loan_amount'] ?? 0);
    $maxDti = (float)($assessment['max_dti_percent'] ?? 40);
    $dtiAfter = (float)($assessment['dti_after_percent'] ?? 0);
    $policy = $assessment['policy'] ?? lendingCreditPolicy();
    $minLoan = (float)($policy['min_loan_amount'] ?? 0);
    $maxLoan = (float)($policy['max_loan_amount'] ?? PHP_FLOAT_MAX);

    if ($requested > 0 && $requested < $minLoan) {
        return 'The minimum loan amount is ' . formatMoneyPhp($minLoan) . '. Please increase your requested amount.';
    }

    if ($requested > $maxLoan) {
        return 'The maximum loan amount is ' . formatMoneyPhp($maxLoan) . '. Please lower your requested amount.';
    }

    if ($dtiAfter > $maxDti + 0.001) {
        return 'This loan amount is not affordable based on your declared income and debts (estimated DTI '
            . number_format($dtiAfter, 1) . '%, limit ' . number_format($maxDti, 1) . '%). '
            . 'Maximum estimated amount: ' . formatMoneyPhp($maxEligible) . '. Please lower the loan amount or choose a shorter term.';
    }

    if ($requested > $maxEligible + 0.01) {
        return 'Based on your income and existing obligations, the maximum estimated loan amount is '
            . formatMoneyPhp($maxEligible) . '. Please reduce your requested amount before uploading documents.';
    }

    if ($maxEligible <= 0 && $requested > 0) {
        return 'Based on your declared income and debts, no loan amount fits within the affordability guidelines. Please update your income/debt information on Step 2 or request a lower amount.';
    }

    return null;
}

function loanAssessmentStatusModifier(string $status): string
{
    return match ($status) {
        'Eligible' => 'success',
        'Conditionally Eligible' => 'warning',
        default => 'danger',
    };
}

function loanAssessmentStatusIcon(string $status): string
{
    return match ($status) {
        'Eligible' => 'fa-circle-check',
        'Conditionally Eligible' => 'fa-triangle-exclamation',
        default => 'fa-circle-xmark',
    };
}

function loanAssessmentRenderMetricTile(string $label, string $value, string $icon = 'fa-chart-line'): string
{
    return '<div class="loan-assessment-metric">'
        . '<div class="loan-assessment-metric__icon" aria-hidden="true"><i class="fas ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i></div>'
        . '<div class="loan-assessment-metric__body">'
        . '<div class="loan-assessment-metric__label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<div class="loan-assessment-metric__value">' . $value . '</div>'
        . '</div></div>';
}

function loanAssessmentRenderDtiMeter(string $label, float $value, float $maxDti): string
{
    $maxDti = max(1.0, $maxDti);
    $fill = min(100, max(0, ($value / $maxDti) * 100));
    $level = 'ok';
    if ($value > $maxDti) {
        $level = 'high';
    } elseif ($value > ($maxDti * 0.85)) {
        $level = 'watch';
    }

    return '<div class="loan-assessment-dti">'
        . '<div class="loan-assessment-dti__top">'
        . '<span class="loan-assessment-dti__label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>'
        . '<span class="loan-assessment-dti__value">' . number_format($value, 2) . '%</span>'
        . '</div>'
        . '<div class="loan-assessment-dti__track" role="presentation">'
        . '<div class="loan-assessment-dti__fill loan-assessment-dti__fill--' . $level . '" style="width:' . number_format($fill, 2, '.', '') . '%"></div>'
        . '</div>'
        . '<div class="loan-assessment-dti__hint">Policy max DTI: ' . number_format($maxDti, 1) . '%</div>'
        . '</div>';
}

function loanAssessmentRenderListPanel(string $title, string $icon, array $items, string $tone = 'neutral'): string
{
    if (count($items) === 0) {
        return '';
    }

    $html = '<div class="loan-assessment-note loan-assessment-note--' . htmlspecialchars($tone, ENT_QUOTES, 'UTF-8') . '">';
    $html .= '<div class="loan-assessment-note__title"><i class="fas ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . ' me-2"></i>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>';
    $html .= '<ul class="loan-assessment-note__list mb-0">';
    foreach ($items as $item) {
        $html .= '<li>' . htmlspecialchars((string)$item, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $html .= '</ul></div>';

    return $html;
}

function loanAssessmentRenderAdminMetaBar(array $options, array $assessment): string
{
    if (($options['context'] ?? '') !== 'admin') {
        return '';
    }

    $applicationId = (int)($options['application_id'] ?? 0);
    $borrowerName = htmlspecialchars(trim((string)($options['borrower_name'] ?? 'Borrower')), ENT_QUOTES, 'UTF-8');
    $applicationStatus = htmlspecialchars(trim((string)($options['application_status'] ?? 'Pending Review')), ENT_QUOTES, 'UTF-8');
    $assessedAtRaw = trim((string)($options['eligibility_assessed_at'] ?? ''));
    $assessedLabel = $assessedAtRaw !== ''
        ? date('M d, Y · g:i A', strtotime($assessedAtRaw))
        : 'Recalculated on open';

    $requested = (float)($assessment['requested_loan_amount'] ?? 0);
    $maxEligible = (float)($assessment['maximum_eligible_loan_amount'] ?? 0);
    $gap = $requested - $maxEligible;
    $gapClass = 'is-neutral';
    $gapText = 'Within eligible range';
    if ($gap > 0.009) {
        $gapClass = 'is-over';
        $gapText = 'Over eligible by ' . formatMoneyPhp($gap);
    } elseif ($maxEligible > 0 && $requested > 0 && $requested <= $maxEligible) {
        $gapClass = 'is-within';
        $gapText = 'Within eligible capacity';
    }

    $html = '<div class="loan-assessment-admin-meta">';
    $html .= '<div class="loan-assessment-admin-meta__intro">';
    $html .= '<span class="loan-assessment-admin-meta__eyebrow">Admin underwriting panel</span>';
    $html .= '<strong class="loan-assessment-admin-meta__title">' . $borrowerName . '</strong>';
    $html .= '<span class="loan-assessment-admin-meta__subtitle">Application #' . $applicationId . ' · ' . $applicationStatus . '</span>';
    $html .= '</div>';
    $html .= '<div class="loan-assessment-admin-meta__pills">';
    $html .= '<span class="loan-assessment-admin-pill"><i class="fas fa-clock me-1"></i>' . htmlspecialchars($assessedLabel, ENT_QUOTES, 'UTF-8') . '</span>';
    $html .= '<span class="loan-assessment-admin-pill loan-assessment-admin-pill--' . $gapClass . '"><i class="fas fa-scale-balanced me-1"></i>' . htmlspecialchars($gapText, ENT_QUOTES, 'UTF-8') . '</span>';
    $html .= '<span class="loan-assessment-admin-pill"><i class="fas fa-robot me-1"></i>Automated estimate</span>';
    $html .= '</div></div>';

    return $html;
}

function loanAssessmentWrapInCard(string $bodyHtml, string $statusLabel, string $modifier, array $options = []): string
{
    $statusLabel = htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8');
    $modifier = htmlspecialchars($modifier, ENT_QUOTES, 'UTF-8');
    $isAdmin = (($options['context'] ?? '') === 'admin');
    $assessment = is_array($options['assessment'] ?? null) ? $options['assessment'] : [];
    $adminClass = $isAdmin ? ' loan-assessment-card--admin' : '';

    if ($isAdmin) {
        $bodyHtml = loanAssessmentRenderAdminMetaBar($options, $assessment) . $bodyHtml;
    }

    $subtitle = $isAdmin
        ? 'Reviewer decision support · not an automatic approval'
        : 'Automated underwriting estimate · reviewer aid only';

    $orbs = $isAdmin
        ? '<div class="loan-assessment-card__orbs" aria-hidden="true"><span class="loan-assessment-card__orb"></span><span class="loan-assessment-card__orb"></span><span class="loan-assessment-card__orb"></span></div>'
        : '';

    $card = '<div class="card loan-assessment-card loan-assessment-card--' . $modifier . $adminClass . ' h-100">'
        . '<div class="loan-assessment-card__accent" aria-hidden="true"></div>'
        . '<div class="loan-assessment-card__mesh" aria-hidden="true"></div>'
        . $orbs
        . '<div class="card-header loan-assessment-card__header">'
        . '<div class="loan-assessment-card__header-grid">'
        . '<div class="loan-assessment-card__brand">'
        . '<span class="loan-assessment-card__mark"><i class="fas fa-shield-halved" aria-hidden="true"></i></span>'
        . '<div class="loan-assessment-card__heading">'
        . '<h6 class="loan-assessment-card__title mb-0">Loan assessment</h6>'
        . '<p class="loan-assessment-card__subtitle mb-0">' . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . '</p>'
        . '</div></div>'
        . '<span class="loan-assessment__status-chip loan-assessment__status-chip--' . $modifier . '">' . $statusLabel . '</span>'
        . '</div></div>'
        . '<div class="card-body loan-assessment-card__body">' . $bodyHtml . '</div>'
        . '</div>';

    if ($isAdmin) {
        return '<div class="loan-assessment-admin-shell loan-assessment-admin-shell--active">' . $card . '</div>';
    }

    return $card;
}

function loanAssessmentBlockHead(string $title, string $subtitle = ''): string
{
    $html = '<div class="loan-assessment__block-head">';
    $html .= '<div class="loan-assessment__block-head-copy">';
    $html .= '<h6 class="loan-assessment__block-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h6>';
    if ($subtitle !== '') {
        $html .= '<p class="loan-assessment__block-subtitle">' . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    $html .= '</div><span class="loan-assessment__block-line" aria-hidden="true"></span></div>';

    return $html;
}

function loanAssessmentRenderAmountCompareBar(float $requested, float $maxEligible): string
{
    if ($requested <= 0 && $maxEligible <= 0) {
        return '';
    }

    $cap = max($requested, $maxEligible, 1.0);
    $requestedPct = min(100, ($requested / $cap) * 100);
    $eligiblePct = min(100, ($maxEligible / $cap) * 100);
    $state = $requested > $maxEligible + 0.009 ? 'over' : ($maxEligible > 0 ? 'within' : 'neutral');

    $html = '<div class="loan-assessment-compare loan-assessment-compare--' . htmlspecialchars($state, ENT_QUOTES, 'UTF-8') . '">';
    $html .= '<div class="loan-assessment-compare__head">';
    $html .= '<span class="loan-assessment-compare__title"><i class="fas fa-chart-column me-2"></i>Requested vs eligible capacity</span>';
    $html .= '<span class="loan-assessment-compare__legend">';
    $html .= '<span class="loan-assessment-compare__key loan-assessment-compare__key--req">Requested</span>';
    $html .= '<span class="loan-assessment-compare__key loan-assessment-compare__key--cap">Max eligible</span>';
    $html .= '</span></div>';
    $html .= '<div class="loan-assessment-compare__track" role="img" aria-label="Requested amount compared to maximum eligible amount">';
    $html .= '<div class="loan-assessment-compare__fill loan-assessment-compare__fill--cap" style="width:' . number_format($eligiblePct, 2, '.', '') . '%"></div>';
    $html .= '<div class="loan-assessment-compare__fill loan-assessment-compare__fill--req" style="width:' . number_format($requestedPct, 2, '.', '') . '%"></div>';
    $html .= '<div class="loan-assessment-compare__marker loan-assessment-compare__marker--cap" style="left:' . number_format($eligiblePct, 2, '.', '') . '%" title="Max eligible"></div>';
    $html .= '</div>';
    $html .= '<div class="loan-assessment-compare__amounts">';
    $html .= '<span><strong>' . formatMoneyPhp($requested) . '</strong> requested</span>';
    $html .= '<span><strong>' . formatMoneyPhp($maxEligible) . '</strong> max eligible</span>';
    $html .= '</div></div>';

    return $html;
}

function loanAssessmentRenderOutcomeBanner(string $status, string $modifier): string
{
    $icon = loanAssessmentStatusIcon($status);
    $modifier = htmlspecialchars($modifier, ENT_QUOTES, 'UTF-8');
    $status = htmlspecialchars($status, ENT_QUOTES, 'UTF-8');

    return '<div class="loan-assessment-outcome loan-assessment-outcome--' . $modifier . '">'
        . '<div class="loan-assessment-outcome__glow" aria-hidden="true"></div>'
        . '<div class="loan-assessment-outcome__icon" aria-hidden="true"><i class="fas ' . $icon . '"></i></div>'
        . '<div class="loan-assessment-outcome__copy">'
        . '<span class="loan-assessment-outcome__eyebrow">Underwriting result</span>'
        . '<strong class="loan-assessment-outcome__status">' . $status . '</strong>'
        . '</div></div>';
}

function loanAssessmentRenderKpi(string $label, string $value, string $icon, string $extraClass = '', string $meta = ''): string
{
    $extraClass = trim($extraClass);
    $class = 'loan-assessment-kpi' . ($extraClass !== '' ? ' ' . $extraClass : '');

    return '<article class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">'
        . '<div class="loan-assessment-kpi__icon" aria-hidden="true"><i class="fas ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i></div>'
        . '<div class="loan-assessment-kpi__content">'
        . '<span class="loan-assessment-kpi__label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>'
        . '<span class="loan-assessment-kpi__value">' . $value . '</span>'
        . ($meta !== '' ? '<span class="loan-assessment-kpi__meta">' . htmlspecialchars($meta, ENT_QUOTES, 'UTF-8') . '</span>' : '')
        . '</div></article>';
}

function loanAssessmentRenderDisclaimerBar(string $disclaimerHtml): string
{
    if ($disclaimerHtml === '') {
        return '';
    }

    return '<div class="loan-assessment__disclaimer-bar"><i class="fas fa-circle-info me-2" aria-hidden="true"></i>' . $disclaimerHtml . '</div>';
}

function renderLoanEligibilityAssessmentHtml(array $assessment, array $options = []): string
{
    $options['assessment'] = $assessment;
    $disclaimer = htmlspecialchars((string)($assessment['disclaimer'] ?? ''), ENT_QUOTES, 'UTF-8');

    if (($assessment['status_code'] ?? '') === 'incomplete') {
        $missing = $assessment['missing_fields'] ?? [];
        $inner = '<div class="loan-assessment loan-assessment--danger loan-eligibility-panel loan-eligibility-panel--danger loan-assessment--nested">';
        $inner .= loanAssessmentRenderDisclaimerBar($disclaimer);
        $inner .= loanAssessmentRenderListPanel('Missing information', 'fa-circle-exclamation', $missing, 'danger');
        $inner .= loanAssessmentRenderListPanel(
            'Next steps for reviewer',
            'fa-folder-open',
            (array)($assessment['documents_or_info_required'] ?? []),
            'neutral'
        );
        $inner .= '</div>';

        return loanAssessmentWrapInCard($inner, 'Incomplete', 'danger', $options);
    }

    $status = (string)($assessment['status'] ?? 'Unknown');
    $modifier = loanAssessmentStatusModifier($status);
    $maxDti = (float)($assessment['max_dti_percent'] ?? 40);
    $dtiBefore = (float)($assessment['dti_before_percent'] ?? 0);
    $dtiAfter = (float)($assessment['dti_after_percent'] ?? 0);
    $requested = (float)($assessment['requested_loan_amount'] ?? 0);
    $maxEligible = (float)($assessment['maximum_eligible_loan_amount'] ?? 0);
    $eligibleEstimate = (float)($assessment['estimated_eligible_loan_amount'] ?? 0);
    $termLabel = htmlspecialchars((string)($assessment['loan_term_label'] ?? ''), ENT_QUOTES, 'UTF-8');
    $termMonths = (int)($assessment['loan_term_months'] ?? 0);

    $isAdmin = (($options['context'] ?? '') === 'admin');
    $html = '<div class="loan-assessment loan-assessment--' . $modifier . ' loan-eligibility-panel loan-eligibility-panel--' . $modifier . ' loan-assessment--nested' . ($isAdmin ? ' loan-assessment--admin-vivid' : '') . '">';
    $html .= loanAssessmentRenderOutcomeBanner($status, $modifier);
    if ($isAdmin) {
        $html .= loanAssessmentRenderAmountCompareBar($requested, $maxEligible);
    }
    $html .= loanAssessmentRenderDisclaimerBar($disclaimer);

    $html .= '<div class="loan-assessment__block">';
    $html .= loanAssessmentBlockHead('Key figures', 'Requested amount vs eligible capacity');
    $html .= '<div class="loan-assessment__kpis">';
    $html .= loanAssessmentRenderKpi('Requested loan', formatMoneyPhp($requested), 'fa-file-invoice-dollar', 'loan-assessment-kpi--featured loan-assessment-kpi--' . $modifier);
    $html .= loanAssessmentRenderKpi('Max eligible', formatMoneyPhp($maxEligible), 'fa-chart-line');
    $html .= loanAssessmentRenderKpi('Estimated eligible', formatMoneyPhp($eligibleEstimate), 'fa-circle-check');
    $html .= loanAssessmentRenderKpi('DTI after loan', number_format($dtiAfter, 2) . '%', 'fa-percent', 'loan-assessment-kpi--dti', 'Policy max ' . number_format($maxDti, 1) . '%');
    $html .= '</div></div>';

    $html .= '<div class="loan-assessment__block">';
    $html .= loanAssessmentBlockHead('Debt-to-income', 'Compared with policy maximum');
    $html .= '<div class="loan-assessment__dti-grid">';
    $html .= loanAssessmentRenderDtiMeter('DTI before loan', $dtiBefore, $maxDti);
    $html .= loanAssessmentRenderDtiMeter('DTI after requested loan', $dtiAfter, $maxDti);
    $html .= '</div></div>';

    $html .= '<div class="loan-assessment__block">';
    $html .= loanAssessmentBlockHead('Detailed breakdown', 'Income, payment, and term inputs');
    $html .= '<div class="loan-assessment__sections">';
    $html .= '<section class="loan-assessment-section">';
    $html .= '<h6 class="loan-assessment-section__title"><i class="fas fa-wallet me-2"></i>Income &amp; affordability</h6>';
    $html .= '<div class="loan-assessment-section__grid">';
    $html .= loanAssessmentRenderMetricTile('Monthly gross income', formatMoneyPhp((float)($assessment['monthly_gross_income'] ?? 0)), 'fa-money-bill-wave');
    $html .= loanAssessmentRenderMetricTile('Existing monthly debt', formatMoneyPhp((float)($assessment['existing_monthly_debt'] ?? 0)), 'fa-credit-card');
    $html .= loanAssessmentRenderMetricTile('Max affordable payment', formatMoneyPhp((float)($assessment['max_affordable_monthly_payment'] ?? 0)), 'fa-hand-holding-dollar');
    $html .= '</div></section>';

    $html .= '<section class="loan-assessment-section">';
    $html .= '<h6 class="loan-assessment-section__title"><i class="fas fa-file-invoice-dollar me-2"></i>Loan request</h6>';
    $html .= '<div class="loan-assessment-section__grid">';
    $html .= loanAssessmentRenderMetricTile('Proposed monthly payment', formatMoneyPhp((float)($assessment['proposed_monthly_payment'] ?? 0)), 'fa-calendar-check');
    $html .= loanAssessmentRenderMetricTile('Payment at eligible amount', formatMoneyPhp((float)($assessment['estimated_monthly_payment'] ?? 0)), 'fa-calculator');
    $html .= loanAssessmentRenderMetricTile('Loan term', $termLabel . ($termMonths > 0 ? ' <span class="text-muted">(' . $termMonths . ' mo)</span>' : ''), 'fa-hourglass-half');
    $html .= loanAssessmentRenderMetricTile('Interest rate', number_format((float)($assessment['interest_rate_percent'] ?? 0), 2) . '% <span class="text-muted">monthly</span>', 'fa-percent');
    $html .= '</div></section>';
    $html .= '</div></div>';

    $html .= '<div class="loan-assessment__notes">';
    $html .= loanAssessmentRenderListPanel('Assessment notes', 'fa-comment-dots', (array)($assessment['reasons'] ?? []), 'neutral');
    $html .= loanAssessmentRenderListPanel('Documents / info required', 'fa-folder-open', (array)($assessment['documents_or_info_required'] ?? []), 'warning');
    $html .= '</div>';

    $html .= '</div>';

    return loanAssessmentWrapInCard($html, $status, $modifier, $options);
}

function wizardStepSession(array $wizardConfig, int $step): array
{
    $key = ($wizardConfig['session_prefix'] ?? 'loan_application') . '_step' . $step;

    return $_SESSION[$key] ?? [];
}

/**
 * Include active unpaid loan installment(s) in affordability (declared debt + system loans).
 *
 * @param array<string, mixed> $step2
 * @return array<string, mixed>
 */
function enrichWizardStep2ForBorrowerEligibility(array $step2, int $userId): array
{
    if ($userId <= 0) {
        return $step2;
    }

    $history = buildBorrowerInternalCreditHistory($userId);
    $step2['credit_history_note'] = is_array($history) ? (string)$history['note'] : '';

    $systemDebt = getBorrowerActiveLoanMonthlyObligation($userId);
    if ($systemDebt <= 0) {
        return $step2;
    }

    $declared = (float)($step2['monthly_debt_payments'] ?? 0);
    $step2['monthly_debt_payments'] = round($declared + $systemDebt, 2);
    $step2['_active_loan_monthly_obligation'] = $systemDebt;

    return $step2;
}

function borrowerWizardMaxAccessibleStep(array $wizardConfig, int $userId): int
{
    if ($userId > 0 && !borrowerCanApplyForNewLoan($userId)) {
        return 1;
    }

    $step1 = wizardStepSession($wizardConfig, 1);
    if ($step1 === []) {
        return 1;
    }

    $step2Raw = wizardStepSession($wizardConfig, 2);
    if ($step2Raw === [] || trim((string)($step2Raw['employment_status'] ?? '')) === '') {
        return 2;
    }

    $step2 = enrichWizardStep2ForBorrowerEligibility($step2Raw, $userId);
    if (loanEligibilityBlocksStep2Progress($step2) !== null) {
        return 2;
    }

    $step3 = wizardStepSession($wizardConfig, 3);
    $assessment = assessLoanEligibility(
        buildLoanEligibilityInputFromWizard($step2, $step3, getStandardMonthlyInterestRatePercent())
    );
    if (loanEligibilityBlocksWizardProgress($assessment) !== null) {
        return 3;
    }

    if (!empty($wizardConfig['require_documents'])) {
        $missing = loanApplicationMissingRequiredDocuments(wizardStepSession($wizardConfig, 4));
        if ($missing !== []) {
            return 4;
        }
    }

    return 5;
}

function gateBorrowerWizardStep(int $requestedStep, array $wizardConfig, int $userId): array
{
    $requestedStep = max(1, min(5, $requestedStep));
    $maxStep = borrowerWizardMaxAccessibleStep($wizardConfig, $userId);
    if ($requestedStep <= $maxStep) {
        return ['step' => $requestedStep, 'message' => ''];
    }

    return [
        'step' => $maxStep,
        'message' => 'Please complete affordability and required steps before continuing. Your existing loan payment(s) and declared income/debt are used to determine eligibility.',
    ];
}
