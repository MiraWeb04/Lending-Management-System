<?php

function loanWizardDefaultConfig(string $mode = 'borrower'): array
{
    if ($mode === 'admin') {
        return [
            'mode' => 'admin',
            'session_prefix' => 'admin_loan_application',
            'page_url' => 'admin_create_loan_lending.php',
            'back_url' => 'loans_lending.php',
            'hero_title' => 'Create New Loan',
            'hero_lead' => 'Five guided steps — the same application a borrower submits. After you submit, continue review, agreement, and release from Loan Applications.',
            'submit_label' => 'Submit application',
            'skip_eligibility_blocks' => false,
            'require_documents' => true,
        ];
    }

    return [
        'mode' => 'borrower',
        'session_prefix' => 'loan_application',
        'page_url' => 'borrower_loan_application_lending.php',
        'back_url' => 'borrower_dashboard_lending.php',
        'hero_title' => 'Loan application',
        'hero_lead' => 'Five guided steps — save progress as you go and submit when you are ready.',
        'submit_label' => 'Submit application',
        'skip_eligibility_blocks' => false,
        'require_documents' => true,
    ];
}

function lwSessionKey(array $config, int $step): string
{
    return $config['session_prefix'] . '_step' . $step;
}

function lwSubmittedKey(array $config): string
{
    return $config['session_prefix'] . '_submitted';
}

function lwPrefilledKey(array $config): string
{
    return $config['session_prefix'] . '_prefilled';
}

function lwPageUrl(array $config, array $query = []): string
{
    $base = (string)($config['page_url'] ?? '');
    if ($query === []) {
        return $base;
    }

    return $base . '?' . http_build_query($query);
}

function lwGetStepSession(array $config, int $step): array
{
    return $_SESSION[lwSessionKey($config, $step)] ?? [];
}

function lwClearWizardSessions(array $config): void
{
    unset(
        $_SESSION[lwSessionKey($config, 1)],
        $_SESSION[lwSessionKey($config, 2)],
        $_SESSION[lwSessionKey($config, 3)],
        $_SESSION[lwSessionKey($config, 4)],
        $_SESSION[lwSubmittedKey($config)],
        $_SESSION[lwPrefilledKey($config)]
    );
}

function loanWizardLoadAdminClients(): array
{
    $result = executeQuery('SELECT client_id, user_id, first_name, last_name FROM clients ORDER BY first_name, last_name');
    $rows = $result ? $result->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($rows as &$row) {
        $uid = (int)($row['user_id'] ?? 0);
        $row['can_apply_for_new_loan'] = $uid > 0 ? borrowerCanApplyForNewLoan($uid) : true;
    }
    unset($row);

    return $rows;
}

function loanWizardHydrateClientProfile(int $clientId): ?array
{
    if ($clientId <= 0) {
        return null;
    }

    $row = executeQuery(
        'SELECT c.*, ba.first_name AS ba_first_name, ba.middle_name, ba.last_name AS ba_last_name, ba.date_of_birth, ba.gender, ba.civil_status, ba.nationality, ba.complete_address AS ba_address, ba.mobile_number AS ba_mobile, ba.email AS ba_email, ba.government_id_type, ba.government_id_number
         FROM clients c
         LEFT JOIN borrower_applications ba ON ba.user_id = c.user_id
         WHERE c.client_id = ?
         LIMIT 1',
        [$clientId]
    )->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $firstName = trim((string)($row['ba_first_name'] ?? $row['first_name'] ?? ''));
    $lastName = trim((string)($row['ba_last_name'] ?? $row['last_name'] ?? ''));
    $middleName = trim((string)($row['middle_name'] ?? ''));

    return [
        'client_id' => $clientId,
        'user_id' => (int)($row['user_id'] ?? 0),
        'first_name' => $firstName,
        'middle_name' => $middleName,
        'last_name' => $lastName,
        'date_of_birth' => trim((string)($row['date_of_birth'] ?? '')),
        'gender' => trim((string)($row['gender'] ?? '')),
        'civil_status' => trim((string)($row['civil_status'] ?? '')),
        'nationality' => trim((string)($row['nationality'] ?? '')),
        'complete_address' => trim((string)($row['ba_address'] ?? $row['address'] ?? '')),
        'mobile_number' => trim((string)($row['ba_mobile'] ?? $row['contact'] ?? '')),
        'email' => trim((string)($row['ba_email'] ?? $row['email'] ?? '')),
        'government_id_type' => trim((string)($row['government_id_type'] ?? '')),
        'government_id_number' => trim((string)($row['government_id_number'] ?? '')),
    ];
}

function loanWizardEligibilityUserId(bool $isAdminLoanWizard, int $adminClientId, array $borrowerApplication, array $user): int
{
    if (!$isAdminLoanWizard) {
        return (int)($user['user_id'] ?? 0);
    }

    $fromProfile = (int)($borrowerApplication['user_id'] ?? 0);
    if ($fromProfile > 0) {
        return $fromProfile;
    }

    if ($adminClientId <= 0) {
        return 0;
    }

    $userId = executeQuery('SELECT user_id FROM clients WHERE client_id = ? LIMIT 1', [$adminClientId])->fetchColumn();

    return (int)($userId ?: 0);
}

function loanWizardFormatStoredNumber($value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    if (!is_numeric($value)) {
        return trim((string)$value);
    }

    $number = (float)$value;
    if (abs($number) < 0.00001) {
        return '0';
    }
    if (abs($number - round($number)) < 0.001) {
        return (string)(int)round($number);
    }

    return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
}

function loanWizardPreviousStep4Documents(int $applicationId): array
{
    if ($applicationId <= 0) {
        return [];
    }

    $result = executeQuery(
        'SELECT document_name, document_path FROM loan_application_documents WHERE application_id = ? ORDER BY id ASC',
        [$applicationId]
    );
    $rows = $result ? $result->fetchAll(PDO::FETCH_ASSOC) : [];
    $byLabel = [];
    foreach ($rows as $row) {
        $label = trim((string)($row['document_name'] ?? ''));
        $path = trim((string)($row['document_path'] ?? ''));
        if ($label === '' || $path === '') {
            continue;
        }
        $byLabel[$label] = [
            'label' => $label,
            'name' => basename(str_replace('\\', '/', $path)),
            'status' => 'Pending Review',
            'path' => $path,
        ];
    }

    if ($byLabel === []) {
        return [];
    }

    $labels = function_exists('loanApplicationDocumentLabels')
        ? loanApplicationDocumentLabels()
        : [
            'Valid Government ID',
            'Proof of Income',
            'Proof of Billing',
            'Selfie Holding Valid ID',
            'Additional Supporting Documents',
        ];
    $documents = [];
    foreach ($labels as $label) {
        $documents[] = $byLabel[$label] ?? [
            'label' => $label,
            'name' => '',
            'status' => 'Not Uploaded',
            'path' => null,
        ];
    }

    return $documents;
}

/**
 * Copy the borrower's latest submitted application into an empty wizard.
 * Saved values stay editable; continuing a step stores any changes.
 */
function loanWizardRememberPreviousApplication(array $config, int $userId): void
{
    if ($userId <= 0 || !function_exists('fetchBorrowerLatestLoanApplication')) {
        return;
    }

    for ($step = 1; $step <= 4; $step++) {
        if (lwGetStepSession($config, $step) !== []) {
            return;
        }
    }

    $application = fetchBorrowerLatestLoanApplication($userId);
    if (!$application) {
        return;
    }

    $text = static function (string $key) use ($application): string {
        $value = $application[$key] ?? '';
        return trim((string)($value ?? ''));
    };
    $money = static function (string $key) use ($application): string {
        return loanWizardFormatStoredNumber($application[$key] ?? '');
    };

    $employmentStatus = $text('employment_status');
    $monthlyIncome = $money('monthly_income');
    $monthlySalary = '';
    $companyName = '';
    $employerName = $text('employer_name');
    if (in_array($employmentStatus, ['Government Employee', 'Private Employee'], true)) {
        $monthlySalary = $monthlyIncome;
    }
    if ($employmentStatus === 'Private Employee') {
        $companyName = $employerName;
    }

    $step1 = [];
    if ($text('complete_address') !== '') {
        $step1['address'] = $text('complete_address');
    }
    if ($text('mobile_number') !== '') {
        $step1['mobile_number'] = $text('mobile_number');
    }
    if (($config['mode'] ?? '') === 'admin') {
        $clientId = (int)($_SESSION['admin_loan_client_id'] ?? 0);
        if ($clientId > 0) {
            $step1['client_id'] = $clientId;
        }
    }

    $step2 = [
        'employment_status' => $employmentStatus,
        'agency_office' => $text('agency_office'),
        'position' => $text('position'),
        'employment_type' => $text('employment_type'),
        'monthly_salary' => $monthlySalary,
        'years_in_service' => $text('years_in_service'),
        'office_address' => $text('office_address'),
        'office_contact' => $text('office_contact'),
        'company_name' => $companyName,
        'nature_of_business' => $text('nature_of_business'),
        'years_in_business' => $text('years_in_business'),
        'business_contact' => $text('business_contact'),
        'profession' => $text('profession'),
        'primary_client' => $text('primary_client'),
        'years_experience' => $text('years_experience'),
        'ofw_country' => $text('ofw_country'),
        'years_abroad' => $text('years_abroad'),
        'employer_contact_ofw' => $text('employer_contact_ofw'),
        'employer_name' => $employerName,
        'occupation' => $text('occupation'),
        'monthly_income' => $monthlyIncome,
        'employment_length' => $text('employment_length'),
        'employer_address' => $text('employer_address'),
        'employer_contact' => $text('employer_contact'),
        'business_name' => $text('business_name'),
        'business_address' => $text('business_address'),
        'pension_source' => $text('pension_source'),
        'monthly_pension' => $money('monthly_pension'),
        'pension_contact' => $text('pension_contact'),
        'remarks' => $text('remarks'),
        'monthly_gross_income' => $money('monthly_gross_income'),
        'monthly_net_income' => $money('monthly_net_income'),
        'monthly_debt_payments' => $money('monthly_debt_payments'),
        'other_monthly_obligations' => $money('other_monthly_obligations'),
        'number_of_dependents' => $text('number_of_dependents'),
        'collateral_note' => $text('collateral_note'),
    ];

    $step3 = [
        'loan_type' => $text('loan_type'),
        'loan_amount' => $money('loan_amount'),
        'loan_term' => $text('loan_term'),
        'loan_purpose' => $text('loan_purpose'),
        'payment_frequency' => $text('payment_frequency') !== '' ? $text('payment_frequency') : 'Monthly',
        'number_of_payments' => (int)($application['number_of_payments'] ?? 0),
        'estimated_payment' => (float)($application['estimated_payment'] ?? 0),
        'estimated_monthly_payment' => (float)($application['estimated_monthly_payment'] ?? 0),
        'estimated_interest' => (float)($application['estimated_interest'] ?? 0),
        'estimated_due_date' => $text('estimated_due_date'),
    ];

    $step4 = loanWizardPreviousStep4Documents((int)($application['id'] ?? 0));
    $hasHistory = $employmentStatus !== ''
        || $step3['loan_type'] !== ''
        || $step3['loan_amount'] !== ''
        || $step4 !== [];
    if (!$hasHistory && $step1 === []) {
        return;
    }

    if ($step1 !== []) {
        $_SESSION[lwSessionKey($config, 1)] = $step1;
    }
    if ($hasHistory) {
        $_SESSION[lwSessionKey($config, 2)] = $step2;
        $_SESSION[lwSessionKey($config, 3)] = $step3;
    }
    if ($step4 !== []) {
        $_SESSION[lwSessionKey($config, 4)] = $step4;
    }
    $_SESSION[lwPrefilledKey($config)] = true;
}

