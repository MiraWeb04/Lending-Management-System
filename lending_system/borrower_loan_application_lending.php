<?php
/**
 * Borrower Loan Application Page for RJ and RR Finance Services
 * (Also used by admin Create New Loan wizard via admin_create_loan_lending.php)
 */

require_once 'includes/auth_lending.php';
require_once 'includes/db_lending.php';
require_once 'includes/loan_helpers.php';
require_once 'includes/loan_email_helpers.php';
require_once 'includes/loan_eligibility_helpers.php';
require_once 'includes/loan_wizard_runtime.php';

$loanWizardConfig = $loanWizardConfig ?? loanWizardDefaultConfig('borrower');
$isAdminLoanWizard = (($loanWizardConfig['mode'] ?? 'borrower') === 'admin');

ensureLoanApplicationSchema();
ensureLoanEligibilitySchema();

if (!isLoggedIn()) {
    header('Location: borrower_login_lending.php');
    exit;
}

$user = getCurrentUser();
$adminClients = [];
$adminClientId = 0;

if ($isAdminLoanWizard) {
    requireStaff();
    if (isCollector()) {
        denyCollectorAccess('Collectors cannot create new loans.');
    }
    $adminClients = loanWizardLoadAdminClients();
    $adminClientId = (int)($_SESSION['admin_loan_client_id'] ?? 0);
    if (isset($_POST['client_id']) && (int)$_POST['client_id'] > 0) {
        $adminClientId = (int)$_POST['client_id'];
        $_SESSION['admin_loan_client_id'] = $adminClientId;
    }
    $borrowerApplication = $adminClientId > 0 ? (loanWizardHydrateClientProfile($adminClientId) ?: []) : [];
    $borrowerProfile = ['status' => 'Active'];
} else {
    if (!isBorrower()) {
        header('Location: ' . getDashboardRedirectUrl(getCurrentUser()));
        exit;
    }

    $borrowerProfileStmt = executeQuery('SELECT * FROM users WHERE user_id = ? LIMIT 1', [$user['user_id']]);
    $borrowerProfile = $borrowerProfileStmt ? $borrowerProfileStmt->fetch(PDO::FETCH_ASSOC) : false;
    $borrowerApplicationStmt = executeQuery('SELECT * FROM borrower_applications WHERE user_id = ? LIMIT 1', [$user['user_id']]);
    $borrowerApplication = $borrowerApplicationStmt ? ($borrowerApplicationStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

    if (!$borrowerProfile || strcasecmp((string)($borrowerProfile['status'] ?? ''), 'Active') !== 0) {
        header('Location: borrower_dashboard_lending.php');
        exit;
    }

}

if ($isAdminLoanWizard && $adminClientId > 0) {
    $borrowerApplication = loanWizardHydrateClientProfile($adminClientId) ?: $borrowerApplication;
}

$wizardEligibilityUserId = loanWizardEligibilityUserId($isAdminLoanWizard, $adminClientId, $borrowerApplication, $user);
$borrowerCanApplyForLoan = $wizardEligibilityUserId > 0
    ? borrowerCanApplyForNewLoan($wizardEligibilityUserId)
    : true;
$borrowerActiveLoanObligation = $wizardEligibilityUserId > 0
    ? getBorrowerActiveLoanMonthlyObligation($wizardEligibilityUserId)
    : 0.0;
$applicationBlocked = !$isAdminLoanWizard && $wizardEligibilityUserId > 0 && !$borrowerCanApplyForLoan;
$adminClientLoanBlocked = $isAdminLoanWizard && $adminClientId > 0 && $wizardEligibilityUserId > 0 && !$borrowerCanApplyForLoan;
$lwPage = (string)($loanWizardConfig['page_url'] ?? 'borrower_loan_application_lending.php');

$step = max(1, min(5, (int)($_GET['step'] ?? 1)));
$editMode = isset($_GET['edit']) || isset($_POST['edit_mode']);
$submitted = false;
$successMessage = '';
$error = '';
$documentErrorModalLabels = [];
$loanAmount = '';
$loanTerm = '';
$loanType = '';
$loanPurpose = '';
$employmentStatus = '';
$agencyOffice = '';
$position = '';
$employmentType = '';
$monthlySalary = '';
$yearsInService = '';
$officeAddress = '';
$officeContact = '';
$companyName = '';
$natureOfBusiness = '';
$yearsInBusiness = '';
$businessContact = '';
$profession = '';
$primaryClient = '';
$yearsExperience = '';
$ofwCountry = '';
$yearsAbroad = '';
$employerContactOfw = '';
$employerName = '';
$occupation = '';
$monthlyIncome = '';
$employmentLength = '';
$employerAddress = '';
$employerContact = '';
$businessName = '';
$businessAddress = '';
$pensionSource = '';
$monthlyPension = '';
$pensionContact = '';
$remarks = '';
$monthlyGrossIncome = '';
$monthlyNetIncome = '';
$monthlyDebtPayments = '';
$otherMonthlyObligations = '';
$numberOfDependents = '';
$collateralNote = '';
$estimatedDueDate = '';
$declaration = false;
$paymentFrequency = 'Monthly';
$numberOfPayments = 0;
$estimatedPayment = 0.0;
$estimatedMonthlyPayment = 0;
$estimatedInterest = 0;
$estimatedDueDate = '';
$dateReleased = date('Y-m-d');
$fullName = trim(($borrowerApplication['first_name'] ?? '') . ' ' . ($borrowerApplication['middle_name'] ?? '') . ' ' . ($borrowerApplication['last_name'] ?? ''));
$dob = trim((string)($borrowerApplication['date_of_birth'] ?? ''));
$gender = trim((string)($borrowerApplication['gender'] ?? ''));
$civilStatus = trim((string)($borrowerApplication['civil_status'] ?? ''));
$nationality = trim((string)($borrowerApplication['nationality'] ?? ''));
$address = trim((string)($borrowerApplication['complete_address'] ?? ''));
$mobileNumber = trim((string)($borrowerApplication['mobile_number'] ?? ''));
$email = trim((string)($borrowerApplication['email'] ?? ''));
$governmentIdType = trim((string)($borrowerApplication['government_id_type'] ?? ''));
$governmentIdNumber = trim((string)($borrowerApplication['government_id_number'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    loanWizardRememberPreviousApplication($loanWizardConfig, $wizardEligibilityUserId);
}
$loanWizardPrefilledFromHistory = !empty($_SESSION[lwPrefilledKey($loanWizardConfig)]);
$step1Summary = $_SESSION[lwSessionKey($loanWizardConfig, 1)] ?? [];
$step2Summary = $_SESSION[lwSessionKey($loanWizardConfig, 2)] ?? [];
$step3Summary = $_SESSION[lwSessionKey($loanWizardConfig, 3)] ?? [];
$step4Summary = $_SESSION[lwSessionKey($loanWizardConfig, 4)] ?? [];
$address = trim((string)($step1Summary['address'] ?? $address));
$mobileNumber = trim((string)($step1Summary['mobile_number'] ?? $mobileNumber));
$employmentStatus = trim((string)($step2Summary['employment_status'] ?? $employmentStatus));
$agencyOffice = trim((string)($step2Summary['agency_office'] ?? $agencyOffice));
$position = trim((string)($step2Summary['position'] ?? $position));
$employmentType = trim((string)($step2Summary['employment_type'] ?? $employmentType));
$monthlySalary = trim((string)($step2Summary['monthly_salary'] ?? $monthlySalary));
$yearsInService = trim((string)($step2Summary['years_in_service'] ?? $yearsInService));
$officeAddress = trim((string)($step2Summary['office_address'] ?? $officeAddress));
$officeContact = trim((string)($step2Summary['office_contact'] ?? $officeContact));
$companyName = trim((string)($step2Summary['company_name'] ?? $companyName));
$natureOfBusiness = trim((string)($step2Summary['nature_of_business'] ?? $natureOfBusiness));
$yearsInBusiness = trim((string)($step2Summary['years_in_business'] ?? $yearsInBusiness));
$businessContact = trim((string)($step2Summary['business_contact'] ?? $businessContact));
$profession = trim((string)($step2Summary['profession'] ?? $profession));
$primaryClient = trim((string)($step2Summary['primary_client'] ?? $primaryClient));
$yearsExperience = trim((string)($step2Summary['years_experience'] ?? $yearsExperience));
$ofwCountry = trim((string)($step2Summary['ofw_country'] ?? $ofwCountry));
$yearsAbroad = trim((string)($step2Summary['years_abroad'] ?? $yearsAbroad));
$employerContactOfw = trim((string)($step2Summary['employer_contact_ofw'] ?? $employerContactOfw));
$pensionSource = trim((string)($step2Summary['pension_source'] ?? $pensionSource));
$monthlyPension = trim((string)($step2Summary['monthly_pension'] ?? $monthlyPension));
$pensionContact = trim((string)($step2Summary['pension_contact'] ?? $pensionContact));
$employerName = trim((string)($step2Summary['employer_name'] ?? $employerName));
$occupation = trim((string)($step2Summary['occupation'] ?? $occupation));
$monthlyIncome = trim((string)($step2Summary['monthly_income'] ?? $monthlyIncome));
$employmentLength = trim((string)($step2Summary['employment_length'] ?? $employmentLength));
$employerAddress = trim((string)($step2Summary['employer_address'] ?? $employerAddress));
$employerContact = trim((string)($step2Summary['employer_contact'] ?? $employerContact));
$businessName = trim((string)($step2Summary['business_name'] ?? $businessName));
$businessAddress = trim((string)($step2Summary['business_address'] ?? $businessAddress));
$remarks = trim((string)($step2Summary['remarks'] ?? $remarks));
$monthlyGrossIncome = trim((string)($step2Summary['monthly_gross_income'] ?? $monthlyGrossIncome));
$monthlyNetIncome = trim((string)($step2Summary['monthly_net_income'] ?? $monthlyNetIncome));
$monthlyDebtPayments = trim((string)($step2Summary['monthly_debt_payments'] ?? $monthlyDebtPayments));
$otherMonthlyObligations = trim((string)($step2Summary['other_monthly_obligations'] ?? $otherMonthlyObligations));
$numberOfDependents = trim((string)($step2Summary['number_of_dependents'] ?? $numberOfDependents));
$collateralNote = trim((string)($step2Summary['collateral_note'] ?? $collateralNote));
$loanType = trim((string)($step3Summary['loan_type'] ?? $loanType));
$loanAmount = trim((string)($step3Summary['loan_amount'] ?? $loanAmount));
$loanTerm = trim((string)($step3Summary['loan_term'] ?? $loanTerm));
$loanPurpose = trim((string)($step3Summary['loan_purpose'] ?? $loanPurpose));
$paymentFrequency = trim((string)($step3Summary['payment_frequency'] ?? $paymentFrequency));
$numberOfPayments = isset($step3Summary['number_of_payments']) ? (int)$step3Summary['number_of_payments'] : $numberOfPayments;
$estimatedPayment = isset($step3Summary['estimated_payment']) ? (float)$step3Summary['estimated_payment'] : $estimatedPayment;
$estimatedMonthlyPayment = isset($step3Summary['estimated_monthly_payment']) ? (float)$step3Summary['estimated_monthly_payment'] : $estimatedMonthlyPayment;
$estimatedInterest = isset($step3Summary['estimated_interest']) ? (float)$step3Summary['estimated_interest'] : $estimatedInterest;
$estimatedDueDate = trim((string)($step3Summary['estimated_due_date'] ?? $estimatedDueDate));
$uploadedDocuments = loanApplicationNormalizeStep4Documents(
    $step4Summary !== [] ? $step4Summary : loanApplicationDefaultStep4Documents()
);

if (isset($_GET['submitted']) || !empty($_SESSION[lwSubmittedKey($loanWizardConfig)])) {
    $submitted = true;
    unset($_SESSION[lwSubmittedKey($loanWizardConfig)]);
}

function displayValue($value) {
    $value = trim((string)$value);
    return $value !== '' ? htmlspecialchars($value) : 'Not Provided';
}

function loanApplicationDocumentLabels(): array
{
    return [
        'Valid Government ID',
        'Proof of Income',
        'Proof of Billing',
        'Selfie Holding Valid ID',
        'Additional Supporting Documents',
    ];
}

function loanApplicationRequiredDocumentLabels(): array
{
    return loanApplicationDocumentLabels();
}

function loanApplicationDefaultStep4Documents(): array
{
    $documents = [];
    foreach (loanApplicationDocumentLabels() as $label) {
        $documents[] = [
            'label' => $label,
            'name' => '',
            'status' => 'Not Uploaded',
            'path' => null,
        ];
    }

    return $documents;
}

function loanApplicationNormalizeStep4Documents(array $documents): array
{
    $byLabel = [];
    foreach ($documents as $document) {
        if (!is_array($document)) {
            continue;
        }
        $label = trim((string)($document['label'] ?? ''));
        if ($label !== '') {
            $byLabel[$label] = $document;
        }
    }

    $normalized = [];
    foreach (loanApplicationDocumentLabels() as $label) {
        $row = $byLabel[$label] ?? [
            'label' => $label,
            'name' => '',
            'status' => 'Not Uploaded',
            'path' => null,
        ];
        $normalized[] = [
            'label' => $label,
            'name' => trim((string)($row['name'] ?? '')),
            'status' => trim((string)($row['status'] ?? 'Not Uploaded')),
            'path' => trim((string)($row['path'] ?? '')) ?: null,
        ];
    }

    return $normalized;
}

function loanApplicationDocumentIsUploaded(array $document): bool
{
    $status = trim((string)($document['status'] ?? ''));
    $path = trim((string)($document['path'] ?? ''));

    return in_array($status, ['Uploaded', 'Pending Review'], true) && $path !== '';
}

function loanApplicationMissingRequiredDocuments(array $documents): array
{
    $normalized = loanApplicationNormalizeStep4Documents($documents);
    $byLabel = [];
    foreach ($normalized as $document) {
        $byLabel[$document['label']] = $document;
    }

    $missing = [];
    foreach (loanApplicationRequiredDocumentLabels() as $label) {
        $document = $byLabel[$label] ?? null;
        if (!$document || !loanApplicationDocumentIsUploaded($document)) {
            $missing[] = $label;
        }
    }

    return $missing;
}

function loanApplicationRequiredDocumentsErrorMessage(array $missingLabels): string
{
    if ($missingLabels === []) {
        return 'Please upload all required documents before continuing.';
    }

    return 'Please upload all required documents before continuing: ' . implode(', ', $missingLabels) . '.';
}

function loanApplicationBlockForMissingDocuments(array $missingLabels): void
{
    global $step, $error, $documentErrorModalLabels;

    $step = 4;
    $documentErrorModalLabels = array_values($missingLabels);
    $error = loanApplicationRequiredDocumentsErrorMessage($missingLabels);
}

function getIncomeSummaryDetails($employmentStatus, $monthlyIncome, $monthlySalary, $monthlyPension) {
    $employmentStatus = trim((string)$employmentStatus);

    if ($employmentStatus === 'Private Employee') {
        return ['label' => 'Monthly Salary', 'value' => $monthlySalary];
    }

    if ($employmentStatus === 'Pensioner') {
        return ['label' => 'Monthly Pension', 'value' => $monthlyPension];
    }

    if ($employmentStatus === 'Self-Employed / Business Owner' || $employmentStatus === 'OFW') {
        return ['label' => 'Monthly Income', 'value' => $monthlyIncome];
    }

    return ['label' => 'Monthly Income', 'value' => $monthlyIncome !== '' ? $monthlyIncome : $monthlySalary];
}

function displayCurrency($value) {
    if ($value === '' || $value === null) {
        return 'Not Provided';
    }
    return '₱' . number_format((float)$value, 2);
}

function displayLabelValue($label, $value) {
    return '<div class="mb-3"><div class="text-secondary small mb-1">' . htmlspecialchars($label) . '</div><div class="fw-semibold">' . $value . '</div></div>';
}

function displayLoanSummaryText($value) {
    $value = trim((string)$value);
    return $value !== '' ? htmlspecialchars($value) : 'Not Provided';
}

function displayLoanSummaryCurrency($value) {
    if ($value === '' || $value === null || (float)$value <= 0) {
        return 'To be calculated upon approval';
    }
    return '₱' . number_format((float)$value, 2);
}

function displayLoanSummaryDate($value) {
    $value = trim((string)$value);
    return $value !== '' ? htmlspecialchars($value) : 'To be calculated upon approval';
}

function parseLoanTermMonths($loanTerm) {
    $loanTerm = trim((string)$loanTerm);
    if (preg_match('/^(\d+)/', $loanTerm, $matches)) {
        return (int)$matches[1];
    }
    return 0;
}

function calculateLoanEstimates(float $loanAmount, int $loanTermMonths): array {
    $monthlyInterestRatePercent = getStandardMonthlyInterestRatePercent();
    $interestBreakdown = calculateLoanInterestBreakdown($loanAmount, $monthlyInterestRatePercent, $loanTermMonths);
    $estimatedMonthlyPayment = $interestBreakdown['total_payable'] / max(1, $loanTermMonths);

    return [
        'monthly_interest_rate' => $monthlyInterestRatePercent / 100,
        'estimated_interest' => $interestBreakdown['total_interest'],
        'estimated_monthly_payment' => $estimatedMonthlyPayment,
    ];
}

function calculatePaymentSchedule(string $paymentFrequency, int $loanTermMonths, float $totalRepayment): array {
    $normalizedFrequency = normalizePaymentFrequencyKey($paymentFrequency);
    $numberOfPayments = getNumberOfPayments($paymentFrequency, $loanTermMonths);
    $paymentLabel = 'Estimated Monthly Payment';
    $estimatedDueDate = '';

    switch ($normalizedFrequency) {
        case 'daily':
            $paymentLabel = 'Estimated Daily Payment';
            break;
        case 'weekly':
            $paymentLabel = 'Estimated Weekly Payment';
            break;
        case 'semi-monthly':
            $paymentLabel = 'Estimated Semi-Monthly Payment';
            break;
        case 'monthly':
        default:
            $paymentLabel = 'Estimated Monthly Payment';
            break;
    }

    if ($loanTermMonths > 0) {
        $dueDate = new DateTime();
        $normalizedFrequency = normalizePaymentFrequencyKey($paymentFrequency);
        $numberOfPayments = max(1, $numberOfPayments);

        $paymentIntervals = max(0, $numberOfPayments - 1);
        switch ($normalizedFrequency) {
            case 'daily':
                $dueDate->modify('+' . $paymentIntervals . ' days');
                break;
            case 'weekly':
                $dueDate->modify('+' . ($paymentIntervals * 7) . ' days');
                break;
            case 'bi-weekly':
                $dueDate->modify('+' . ($paymentIntervals * 14) . ' days');
                break;
            case 'semi-monthly':
                $dueDate->modify('+' . ($paymentIntervals * 15) . ' days');
                break;
            case 'monthly':
            default:
                $dueDate->modify('+' . $loanTermMonths . ' months');
                break;
        }

        $estimatedDueDate = $dueDate->format('Y-m-d');
    }

    $estimatedPayment = $numberOfPayments > 0 ? $totalRepayment / $numberOfPayments : 0.0;

    return [
        'number_of_payments' => $numberOfPayments,
        'estimated_payment' => $estimatedPayment,
        'payment_label' => $paymentLabel,
        'estimated_due_date' => $estimatedDueDate,
    ];
}

function ensureLoanApplicationSchema() {
    global $conn;

    $conn->exec("CREATE TABLE IF NOT EXISTS loan_applications (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT DEFAULT NULL,
        borrower_name VARCHAR(200) DEFAULT NULL,
        date_of_birth DATE DEFAULT NULL,
        gender VARCHAR(30) DEFAULT NULL,
        civil_status VARCHAR(30) DEFAULT NULL,
        nationality VARCHAR(100) DEFAULT NULL,
        complete_address TEXT DEFAULT NULL,
        mobile_number VARCHAR(30) DEFAULT NULL,
        email_address VARCHAR(150) DEFAULT NULL,
        government_id_type VARCHAR(100) DEFAULT NULL,
        government_id_number VARCHAR(100) DEFAULT NULL,
        employment_status VARCHAR(80) DEFAULT NULL,
        agency_office VARCHAR(150) DEFAULT NULL,
        position VARCHAR(150) DEFAULT NULL,
        employment_type VARCHAR(100) DEFAULT NULL,
        monthly_income DECIMAL(12,2) DEFAULT NULL,
        years_in_service VARCHAR(80) DEFAULT NULL,
        office_address TEXT DEFAULT NULL,
        office_contact VARCHAR(30) DEFAULT NULL,
        employer_name VARCHAR(150) DEFAULT NULL,
        occupation VARCHAR(150) DEFAULT NULL,
        employment_length VARCHAR(80) DEFAULT NULL,
        employer_address TEXT DEFAULT NULL,
        employer_contact VARCHAR(30) DEFAULT NULL,
        business_name VARCHAR(150) DEFAULT NULL,
        business_address TEXT DEFAULT NULL,
        nature_of_business VARCHAR(150) DEFAULT NULL,
        years_in_business VARCHAR(80) DEFAULT NULL,
        business_contact VARCHAR(30) DEFAULT NULL,
        profession VARCHAR(150) DEFAULT NULL,
        primary_client VARCHAR(150) DEFAULT NULL,
        years_experience VARCHAR(80) DEFAULT NULL,
        ofw_country VARCHAR(100) DEFAULT NULL,
        years_abroad VARCHAR(80) DEFAULT NULL,
        employer_contact_ofw VARCHAR(30) DEFAULT NULL,
        pension_source VARCHAR(150) DEFAULT NULL,
        monthly_pension DECIMAL(12,2) DEFAULT NULL,
        pension_contact VARCHAR(30) DEFAULT NULL,
        loan_type VARCHAR(80) DEFAULT NULL,
        loan_amount DECIMAL(12,2) DEFAULT NULL,
        loan_term VARCHAR(50) DEFAULT NULL,
        payment_frequency VARCHAR(50) DEFAULT 'Monthly',
        number_of_payments INT DEFAULT NULL,
        estimated_payment DECIMAL(12,2) DEFAULT NULL,
        estimated_due_date DATE DEFAULT NULL,
        monthly_interest_rate DECIMAL(5,4) DEFAULT NULL,
        estimated_interest DECIMAL(12,2) DEFAULT NULL,
        estimated_monthly_payment DECIMAL(12,2) DEFAULT NULL,
        loan_purpose TEXT DEFAULT NULL,
        approved_loan_amount DECIMAL(12,2) DEFAULT NULL,
        approved_interest_rate DECIMAL(5,2) DEFAULT NULL,
        approved_monthly_payment DECIMAL(12,2) DEFAULT NULL,
        approved_loan_term VARCHAR(50) DEFAULT NULL,
        approved_first_payment_date DATE DEFAULT NULL,
        approved_due_date DATE DEFAULT NULL,
        approval_conditions TEXT DEFAULT NULL,
        remarks TEXT DEFAULT NULL,
        status VARCHAR(50) DEFAULT 'Pending Review',
        submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $requiredColumns = [
        'government_id_type' => "ALTER TABLE loan_applications ADD COLUMN government_id_type VARCHAR(100) DEFAULT NULL",
        'government_id_number' => "ALTER TABLE loan_applications ADD COLUMN government_id_number VARCHAR(100) DEFAULT NULL",
        'agency_office' => "ALTER TABLE loan_applications ADD COLUMN agency_office VARCHAR(150) DEFAULT NULL",
        'position' => "ALTER TABLE loan_applications ADD COLUMN position VARCHAR(150) DEFAULT NULL",
        'employment_type' => "ALTER TABLE loan_applications ADD COLUMN employment_type VARCHAR(100) DEFAULT NULL",
        'years_in_service' => "ALTER TABLE loan_applications ADD COLUMN years_in_service VARCHAR(80) DEFAULT NULL",
        'office_address' => "ALTER TABLE loan_applications ADD COLUMN office_address TEXT DEFAULT NULL",
        'office_contact' => "ALTER TABLE loan_applications ADD COLUMN office_contact VARCHAR(30) DEFAULT NULL",
        'employer_name' => "ALTER TABLE loan_applications ADD COLUMN employer_name VARCHAR(150) DEFAULT NULL",
        'occupation' => "ALTER TABLE loan_applications ADD COLUMN occupation VARCHAR(150) DEFAULT NULL",
        'employment_length' => "ALTER TABLE loan_applications ADD COLUMN employment_length VARCHAR(80) DEFAULT NULL",
        'employer_address' => "ALTER TABLE loan_applications ADD COLUMN employer_address TEXT DEFAULT NULL",
        'employer_contact' => "ALTER TABLE loan_applications ADD COLUMN employer_contact VARCHAR(30) DEFAULT NULL",
        'business_name' => "ALTER TABLE loan_applications ADD COLUMN business_name VARCHAR(150) DEFAULT NULL",
        'business_address' => "ALTER TABLE loan_applications ADD COLUMN business_address TEXT DEFAULT NULL",
        'nature_of_business' => "ALTER TABLE loan_applications ADD COLUMN nature_of_business VARCHAR(150) DEFAULT NULL",
        'years_in_business' => "ALTER TABLE loan_applications ADD COLUMN years_in_business VARCHAR(80) DEFAULT NULL",
        'business_contact' => "ALTER TABLE loan_applications ADD COLUMN business_contact VARCHAR(30) DEFAULT NULL",
        'profession' => "ALTER TABLE loan_applications ADD COLUMN profession VARCHAR(150) DEFAULT NULL",
        'primary_client' => "ALTER TABLE loan_applications ADD COLUMN primary_client VARCHAR(150) DEFAULT NULL",
        'years_experience' => "ALTER TABLE loan_applications ADD COLUMN years_experience VARCHAR(80) DEFAULT NULL",
        'ofw_country' => "ALTER TABLE loan_applications ADD COLUMN ofw_country VARCHAR(100) DEFAULT NULL",
        'years_abroad' => "ALTER TABLE loan_applications ADD COLUMN years_abroad VARCHAR(80) DEFAULT NULL",
        'employer_contact_ofw' => "ALTER TABLE loan_applications ADD COLUMN employer_contact_ofw VARCHAR(30) DEFAULT NULL",
        'pension_source' => "ALTER TABLE loan_applications ADD COLUMN pension_source VARCHAR(150) DEFAULT NULL",
        'monthly_pension' => "ALTER TABLE loan_applications ADD COLUMN monthly_pension DECIMAL(12,2) DEFAULT NULL",
        'pension_contact' => "ALTER TABLE loan_applications ADD COLUMN pension_contact VARCHAR(30) DEFAULT NULL",
        'loan_type' => "ALTER TABLE loan_applications ADD COLUMN loan_type VARCHAR(80) DEFAULT NULL",
        'loan_amount' => "ALTER TABLE loan_applications ADD COLUMN loan_amount DECIMAL(12,2) DEFAULT NULL",
        'loan_term' => "ALTER TABLE loan_applications ADD COLUMN loan_term VARCHAR(50) DEFAULT NULL",
        'loan_purpose' => "ALTER TABLE loan_applications ADD COLUMN loan_purpose TEXT DEFAULT NULL",
        'payment_frequency' => "ALTER TABLE loan_applications ADD COLUMN payment_frequency VARCHAR(50) DEFAULT 'Monthly'",
        'number_of_payments' => "ALTER TABLE loan_applications ADD COLUMN number_of_payments INT DEFAULT NULL",
        'estimated_payment' => "ALTER TABLE loan_applications ADD COLUMN estimated_payment DECIMAL(12,2) DEFAULT NULL",
        'estimated_due_date' => "ALTER TABLE loan_applications ADD COLUMN estimated_due_date DATE DEFAULT NULL",
        'monthly_interest_rate' => "ALTER TABLE loan_applications ADD COLUMN monthly_interest_rate DECIMAL(5,4) DEFAULT NULL",
        'estimated_interest' => "ALTER TABLE loan_applications ADD COLUMN estimated_interest DECIMAL(12,2) DEFAULT NULL",
        'estimated_monthly_payment' => "ALTER TABLE loan_applications ADD COLUMN estimated_monthly_payment DECIMAL(12,2) DEFAULT NULL",
        'approved_loan_amount' => "ALTER TABLE loan_applications ADD COLUMN approved_loan_amount DECIMAL(12,2) DEFAULT NULL",
        'approved_interest_rate' => "ALTER TABLE loan_applications ADD COLUMN approved_interest_rate DECIMAL(5,2) DEFAULT NULL",
        'approved_monthly_payment' => "ALTER TABLE loan_applications ADD COLUMN approved_monthly_payment DECIMAL(12,2) DEFAULT NULL",
        'approved_loan_term' => "ALTER TABLE loan_applications ADD COLUMN approved_loan_term VARCHAR(50) DEFAULT NULL",
        'approved_first_payment_date' => "ALTER TABLE loan_applications ADD COLUMN approved_first_payment_date DATE DEFAULT NULL",
        'approved_due_date' => "ALTER TABLE loan_applications ADD COLUMN approved_due_date DATE DEFAULT NULL",
        'approval_conditions' => "ALTER TABLE loan_applications ADD COLUMN approval_conditions TEXT DEFAULT NULL",
    ];

    foreach ($requiredColumns as $columnName => $alterSql) {
        $columnNameEscaped = str_replace("'", "''", $columnName);
        $columnCheck = $conn->query("SHOW COLUMNS FROM loan_applications LIKE '$columnNameEscaped'");
        if ($columnCheck->rowCount() === 0) {
            $conn->exec($alterSql);
        }
    }

    $conn->exec("CREATE TABLE IF NOT EXISTS loan_application_documents (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        application_id INT UNSIGNED NOT NULL,
        document_name VARCHAR(150) NOT NULL,
        document_path VARCHAR(255) DEFAULT NULL,
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        verification_status VARCHAR(50) DEFAULT 'Pending Review',
        rejection_reason TEXT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function storeLoanApplicationDocuments($applicationId) {
    global $loanWizardConfig;
    $storedDocuments = $_SESSION[lwSessionKey($loanWizardConfig, 4)] ?? [];
    if (!is_array($storedDocuments) || empty($storedDocuments)) {
        return;
    }

    foreach ($storedDocuments as $document) {
        $documentName = trim((string)($document['label'] ?? $document['name'] ?? 'Document'));
        $documentPath = trim((string)($document['path'] ?? ''));
        $status = trim((string)($document['status'] ?? 'Not Uploaded'));
        $verificationStatus = $status === 'Uploaded' || $status === 'Pending Review' ? 'Pending Review' : 'Missing';

        executeQuery('INSERT INTO loan_application_documents (application_id, document_name, document_path, verification_status) VALUES (?, ?, ?, ?)', [
            $applicationId,
            $documentName,
            $documentPath !== '' ? $documentPath : null,
            $verificationStatus
        ]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isAdminLoanWizard && isset($_POST['client_id']) && (int)$_POST['client_id'] > 0) {
        $adminClientId = (int)$_POST['client_id'];
        $_SESSION['admin_loan_client_id'] = $adminClientId;
    }

    if ($isAdminLoanWizard && $adminClientId > 0) {
        $borrowerApplication = loanWizardHydrateClientProfile($adminClientId) ?: $borrowerApplication;
    }
    $wizardEligibilityUserId = loanWizardEligibilityUserId($isAdminLoanWizard, $adminClientId, $borrowerApplication, $user);

    if (!$isAdminLoanWizard && $wizardEligibilityUserId > 0 && !borrowerCanApplyForNewLoan($wizardEligibilityUserId)) {
        header('Location: borrower_my_loan_lending.php');
        exit;
    }

    $step = max(1, min(5, (int)($_POST['step'] ?? 1)));
    $formAction = trim((string)($_POST['form_action'] ?? ''));
    $fullName = trim(($borrowerApplication['first_name'] ?? '') . ' ' . ($borrowerApplication['middle_name'] ?? '') . ' ' . ($borrowerApplication['last_name'] ?? ''));
    $dob = trim((string)($borrowerApplication['date_of_birth'] ?? ''));
    $gender = trim((string)($borrowerApplication['gender'] ?? ''));
    $civilStatus = trim((string)($borrowerApplication['civil_status'] ?? ''));
    $nationality = trim((string)($borrowerApplication['nationality'] ?? ''));
    $address = trim((string)($borrowerApplication['complete_address'] ?? ''));
    $mobileNumber = trim((string)($borrowerApplication['mobile_number'] ?? ''));
    $email = trim((string)($borrowerApplication['email'] ?? ''));
    $employmentStatus = trim($_POST['employment_status'] ?? '');
    $agencyOffice = trim($_POST['agency_office'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $employmentType = trim($_POST['employment_type'] ?? '');
    $monthlySalary = trim($_POST['monthly_salary'] ?? '');
    $yearsInService = trim($_POST['years_in_service'] ?? '');
    $officeAddress = trim($_POST['office_address'] ?? '');
    $officeContact = trim($_POST['office_contact'] ?? '');
    $companyName = trim($_POST['company_name'] ?? '');
    $natureOfBusiness = trim($_POST['nature_of_business'] ?? '');
    $yearsInBusiness = trim($_POST['years_in_business'] ?? '');
    $businessContact = trim($_POST['business_contact'] ?? '');
    $companyContact = trim($_POST['company_contact'] ?? '');
    if ($businessContact === '' && $companyContact !== '') {
        $businessContact = $companyContact;
    }
    $profession = trim($_POST['profession'] ?? '');
    $primaryClient = trim($_POST['primary_client'] ?? '');
    $yearsExperience = trim($_POST['years_experience'] ?? '');
    $ofwCountry = trim($_POST['ofw_country'] ?? '');
    $yearsAbroad = trim($_POST['years_abroad'] ?? '');
    $employerContactOfw = trim($_POST['employer_contact_ofw'] ?? '');
    $pensionSource = trim($_POST['pension_source'] ?? '');
    $monthlyPension = trim($_POST['monthly_pension'] ?? '');
    $pensionContact = trim($_POST['pension_contact'] ?? '');
    $employerName = trim($_POST['employer_name'] ?? '');
    $occupation = trim($_POST['occupation'] ?? '');
    $monthlyIncome = trim($_POST['monthly_income'] ?? '');
    $monthlySalary = trim($_POST['monthly_salary'] ?? '');
    $incomeValue = $monthlyIncome !== '' ? $monthlyIncome : $monthlySalary;
    $employmentLength = trim($_POST['employment_length'] ?? '');
    $employerAddress = trim($_POST['employer_address'] ?? '');
    $employerContact = trim($_POST['employer_contact'] ?? '');
    $businessName = trim($_POST['business_name'] ?? '');
    $businessAddress = trim($_POST['business_address'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $monthlyGrossIncome = trim($_POST['monthly_gross_income'] ?? '');
    $monthlyNetIncome = trim($_POST['monthly_net_income'] ?? '');
    $monthlyDebtPayments = trim($_POST['monthly_debt_payments'] ?? '');
    $otherMonthlyObligations = trim($_POST['other_monthly_obligations'] ?? '');
    $numberOfDependents = trim($_POST['number_of_dependents'] ?? '');
    $collateralNote = trim($_POST['collateral_note'] ?? '');
    $loanType = trim($_POST['loan_type'] ?? '');
    $loanAmount = trim($_POST['loan_amount'] ?? '');
    $loanTerm = trim($_POST['loan_term'] ?? '');
    $loanPurpose = trim($_POST['loan_purpose'] ?? '');
    $paymentFrequency = trim((string)($_POST['payment_frequency'] ?? ''));
    if ($paymentFrequency === '') {
        $paymentFrequency = trim((string)($step3Summary['payment_frequency'] ?? 'Monthly'));
    }
    $declaration = !empty($_POST['declaration']);

    $estimatedMonthlyPayment = 0.0;
    $estimatedInterest = 0.0;
    $estimatedPayment = 0.0;
    $estimatedDueDate = '';

    $loanAmountValue = is_numeric($loanAmount) ? (float)$loanAmount : 0.0;
    $loanTermMonths = parseLoanTermMonths($loanTerm);
    $loanEstimateValues = calculateLoanEstimates($loanAmountValue, $loanTermMonths);
    $monthlyInterestRate = $loanEstimateValues['monthly_interest_rate'];
    $estimatedInterest = $loanEstimateValues['estimated_interest'];
    $estimatedMonthlyPayment = $loanEstimateValues['estimated_monthly_payment'];

    $paymentSchedule = calculatePaymentSchedule($paymentFrequency, $loanTermMonths, $loanAmountValue + $estimatedInterest);
    $numberOfPayments = $paymentSchedule['number_of_payments'];
    $estimatedPayment = $paymentSchedule['estimated_payment'];
    $paymentLabel = $paymentSchedule['payment_label'];
    $estimatedDueDate = $paymentSchedule['estimated_due_date'];

    if ($step === 1 || $address !== '' || $mobileNumber !== '') {
        $step1Payload = [
            'address' => $address,
            'mobile_number' => $mobileNumber,
        ];
        if ($isAdminLoanWizard) {
            $step1Payload['client_id'] = $adminClientId;
        }
        $_SESSION[lwSessionKey($loanWizardConfig, 1)] = $step1Payload;
    }

    if ($step === 2 || $employmentStatus !== '' || $companyName !== '' || $businessName !== '') {
        $step2Payload = applyStep2EmploymentGrossIncomeSync([
            'employment_status' => $employmentStatus,
            'agency_office' => $agencyOffice,
            'position' => $position,
            'employment_type' => $employmentType,
            'monthly_salary' => $monthlySalary,
            'years_in_service' => $yearsInService,
            'office_address' => $officeAddress,
            'office_contact' => $officeContact,
            'company_name' => $companyName,
            'nature_of_business' => $natureOfBusiness,
            'years_in_business' => $yearsInBusiness,
            'business_contact' => $businessContact,
            'profession' => $profession,
            'primary_client' => $primaryClient,
            'years_experience' => $yearsExperience,
            'ofw_country' => $ofwCountry,
            'years_abroad' => $yearsAbroad,
            'employer_contact_ofw' => $employerContactOfw,
            'employer_name' => $employerName !== '' ? $employerName : $companyName,
            'occupation' => $occupation,
            'monthly_income' => $incomeValue,
            'employment_length' => $employmentLength,
            'employer_address' => $employerAddress,
            'employer_contact' => $employerContact,
            'business_name' => $businessName,
            'business_address' => $businessAddress,
            'pension_source' => $pensionSource,
            'monthly_pension' => $monthlyPension,
            'pension_contact' => $pensionContact,
            'remarks' => $remarks,
            'monthly_gross_income' => $monthlyGrossIncome,
            'monthly_net_income' => $monthlyNetIncome,
            'monthly_debt_payments' => $monthlyDebtPayments,
            'other_monthly_obligations' => $otherMonthlyObligations,
            'number_of_dependents' => $numberOfDependents,
            'collateral_note' => $collateralNote,
            'credit_history_note' => trim((string)((buildBorrowerInternalCreditHistory($wizardEligibilityUserId) ?? [])['note'] ?? '')),
        ]);
        $monthlyGrossIncome = trim((string)($step2Payload['monthly_gross_income'] ?? $monthlyGrossIncome));
        $_SESSION[lwSessionKey($loanWizardConfig, 2)] = $step2Payload;
    }

    $priorStep3 = lwGetStepSession($loanWizardConfig, 3);
    $dateReleased = trim((string)($_POST['date_released'] ?? ($priorStep3['date_released'] ?? date('Y-m-d'))));
    if ($step === 3 || $loanType !== '' || $loanAmount !== '' || $loanTerm !== '' || $loanPurpose !== '') {
        $step3Payload = [
            'loan_type' => $loanType,
            'loan_amount' => $loanAmount,
            'loan_term' => $loanTerm,
            'loan_term_months' => $loanTermMonths,
            'payment_frequency' => $paymentFrequency,
            'number_of_payments' => $numberOfPayments,
            'payment_label' => $paymentLabel,
            'estimated_interest' => $estimatedInterest,
            'estimated_payment' => $estimatedPayment,
            'estimated_monthly_payment' => $estimatedMonthlyPayment,
            'loan_purpose' => $loanPurpose,
            'estimated_due_date' => $estimatedDueDate,
        ];
        if ($isAdminLoanWizard) {
            $step3Payload['date_released'] = $dateReleased;
        }
        $_SESSION[lwSessionKey($loanWizardConfig, 3)] = $step3Payload;
    }

    $documentLabels = loanApplicationDocumentLabels();
    $normalizedStep4BeforeUpload = loanApplicationNormalizeStep4Documents($_SESSION[lwSessionKey($loanWizardConfig, 4)] ?? []);
    if ($step === 4) {
        $step4Documents = [];
        $uploadDir = __DIR__ . '/uploads/loan_documents';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        foreach ($documentLabels as $index => $label) {
            $existing = $normalizedStep4BeforeUpload[$index] ?? ['label' => $label, 'name' => '', 'status' => 'Not Uploaded', 'path' => null];
            $uploadedName = trim((string)($_FILES['documents']['name'][$index] ?? ''));
            $tmpName = trim((string)($_FILES['documents']['tmp_name'][$index] ?? ''));
            $existingPath = trim((string)($existing['path'] ?? ''));
            $documentPath = $existingPath !== '' ? $existingPath : null;
            $hasUpload = $uploadedName !== '' && is_uploaded_file($tmpName);
            $status = trim((string)($existing['status'] ?? 'Not Uploaded'));
            $name = trim((string)($existing['name'] ?? ''));

            if ($hasUpload) {
                require_once __DIR__ . '/includes/document_download_helpers.php';
                $filePayload = [
                    'name' => $uploadedName,
                    'tmp_name' => $tmpName,
                    'error' => (int)($_FILES['documents']['error'][$index] ?? UPLOAD_ERR_OK),
                    'size' => (int)($_FILES['documents']['size'][$index] ?? 0),
                ];
                $validation = validateLoanDocumentUpload($filePayload);
                if (empty($validation['ok'])) {
                    $status = 'Not Uploaded';
                    $name = $uploadedName;
                    if ($error === '') {
                        $error = (string)($validation['message'] ?? 'One or more files could not be uploaded. Use JPG, PNG, or PDF within the size limit.');
                    }
                } else {
                    $ext = (string)($validation['extension'] ?? 'bin');
                    $destinationFileName = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $destinationPath = $uploadDir . '/' . $destinationFileName;
                    if (move_uploaded_file($tmpName, $destinationPath)) {
                        $documentPath = 'uploads/loan_documents/' . $destinationFileName;
                        $status = 'Pending Review';
                        $name = $uploadedName;
                    } else {
                        $status = 'Not Uploaded';
                        $name = $uploadedName;
                    }
                }
            } elseif (!loanApplicationDocumentIsUploaded($existing)) {
                $status = 'Not Uploaded';
            }

            $step4Documents[] = [
                'label' => $label,
                'name' => $name,
                'status' => $status,
                'path' => $documentPath,
            ];
        }

        $_SESSION[lwSessionKey($loanWizardConfig, 4)] = $step4Documents;
        $uploadedDocuments = loanApplicationNormalizeStep4Documents($step4Documents);
        $step4Summary = $uploadedDocuments;
    }

    $requireWizardDocuments = !empty($loanWizardConfig['require_documents']);

    if ($step === 5 && $formAction === 'submit') {
        if (!$declaration) {
            $step = 5;
            $error = 'Please confirm the declaration before submitting the loan application.';
        } elseif ($isAdminLoanWizard) {
            if ($requireWizardDocuments) {
                $missingRequiredDocuments = loanApplicationMissingRequiredDocuments($_SESSION[lwSessionKey($loanWizardConfig, 4)] ?? []);
                if ($missingRequiredDocuments !== []) {
                    loanApplicationBlockForMissingDocuments($missingRequiredDocuments);
                }
            }
            if ($error === '') {
                $step3Summary = $_SESSION[lwSessionKey($loanWizardConfig, 3)] ?? [];
                $step1Summary = $_SESSION[lwSessionKey($loanWizardConfig, 1)] ?? [];
                $clientId = $adminClientId > 0 ? $adminClientId : (int)($step1Summary['client_id'] ?? 0);
                if ($clientId <= 0) {
                    $step = 1;
                    $error = 'Please select a client in Step 1 before submitting the loan application.';
                } else {
                    $clientProfile = loanWizardHydrateClientProfile($clientId) ?: [];
                    $clientUserId = loanWizardEligibilityUserId(true, $clientId, $clientProfile, $user);
                    if ($clientUserId <= 0 || !borrowerCanApplyForNewLoan($clientUserId)) {
                        $step = 5;
                        $error = 'This client already has an active loan that is not fully paid. A new loan cannot be created until that loan is settled.';
                    } else {
                        $step2ForSubmit = enrichWizardStep2ForBorrowerEligibility(
                            $_SESSION[lwSessionKey($loanWizardConfig, 2)] ?? [],
                            $clientUserId
                        );
                        $step3ForSubmit = $_SESSION[lwSessionKey($loanWizardConfig, 3)] ?? [];
                        $submitAssessment = assessLoanEligibility(
                            buildLoanEligibilityInputFromWizard($step2ForSubmit, $step3ForSubmit, getStandardMonthlyInterestRatePercent())
                        );
                        $submitBlockMessage = loanEligibilityBlocksWizardProgress($submitAssessment);
                        if ($submitBlockMessage !== null) {
                            $step = 5;
                            $error = $submitBlockMessage;
                        }
                    }
                    if ($error === '') {
                        $borrowerApplication = $clientProfile;
                        $fullName = trim(implode(' ', array_filter([
                            trim((string)($clientProfile['first_name'] ?? '')),
                            trim((string)($clientProfile['middle_name'] ?? '')),
                            trim((string)($clientProfile['last_name'] ?? '')),
                        ], static fn($part) => $part !== '')));
                        $dob = trim((string)($clientProfile['date_of_birth'] ?? ''));
                        $gender = trim((string)($clientProfile['gender'] ?? ''));
                        $civilStatus = trim((string)($clientProfile['civil_status'] ?? ''));
                        $nationality = trim((string)($clientProfile['nationality'] ?? ''));
                        $email = trim((string)($clientProfile['email'] ?? $email));
                        $governmentIdType = trim((string)($clientProfile['government_id_type'] ?? $governmentIdType));
                        $governmentIdNumber = trim((string)($clientProfile['government_id_number'] ?? $governmentIdNumber));
                        $wizardEligibilityUserId = $clientUserId;
                    }
                }
            }
        } else {
            if (!borrowerCanApplyForNewLoan($wizardEligibilityUserId)) {
                $step = 5;
                $error = 'You already have an active loan that is not fully paid. You cannot submit a new application until that loan is settled.';
            } else {
            $step2ForSubmit = enrichWizardStep2ForBorrowerEligibility(
                $_SESSION[lwSessionKey($loanWizardConfig, 2)] ?? [],
                $wizardEligibilityUserId
            );
            $step3ForSubmit = $_SESSION[lwSessionKey($loanWizardConfig, 3)] ?? [];
            $submitAssessment = assessLoanEligibility(
                buildLoanEligibilityInputFromWizard($step2ForSubmit, $step3ForSubmit, getStandardMonthlyInterestRatePercent())
            );
            $submitBlockMessage = loanEligibilityBlocksWizardProgress($submitAssessment);
            if ($submitBlockMessage !== null) {
                $step = 5;
                $error = $submitBlockMessage;
            }
            }
        }

        if ($error === '') {
            $missingRequiredDocuments = loanApplicationMissingRequiredDocuments($_SESSION[lwSessionKey($loanWizardConfig, 4)] ?? []);
            if ($missingRequiredDocuments !== []) {
                loanApplicationBlockForMissingDocuments($missingRequiredDocuments);
            } else {
            $step1Summary = $_SESSION[lwSessionKey($loanWizardConfig, 1)] ?? [];
            $step2Summary = $_SESSION[lwSessionKey($loanWizardConfig, 2)] ?? [];
            $step3Summary = $_SESSION[lwSessionKey($loanWizardConfig, 3)] ?? [];
            $step4Summary = $_SESSION[lwSessionKey($loanWizardConfig, 4)] ?? [];

            $address = trim((string)($step1Summary['address'] ?? $address));
            $mobileNumber = trim((string)($step1Summary['mobile_number'] ?? $mobileNumber));

            $employmentStatus = trim((string)($step2Summary['employment_status'] ?? $employmentStatus));
            $agencyOffice = trim((string)($step2Summary['agency_office'] ?? $agencyOffice));
            $position = trim((string)($step2Summary['position'] ?? $position));
            $employmentType = trim((string)($step2Summary['employment_type'] ?? $employmentType));
            $monthlySalary = trim((string)($step2Summary['monthly_salary'] ?? $monthlySalary));
            $yearsInService = trim((string)($step2Summary['years_in_service'] ?? $yearsInService));
            $officeAddress = trim((string)($step2Summary['office_address'] ?? $officeAddress));
            $officeContact = trim((string)($step2Summary['office_contact'] ?? $officeContact));
            $companyName = trim((string)($step2Summary['company_name'] ?? $companyName));
            $natureOfBusiness = trim((string)($step2Summary['nature_of_business'] ?? $natureOfBusiness));
            $yearsInBusiness = trim((string)($step2Summary['years_in_business'] ?? $yearsInBusiness));
            $businessContact = trim((string)($step2Summary['business_contact'] ?? $businessContact));
            $profession = trim((string)($step2Summary['profession'] ?? $profession));
            $primaryClient = trim((string)($step2Summary['primary_client'] ?? $primaryClient));
            $yearsExperience = trim((string)($step2Summary['years_experience'] ?? $yearsExperience));
            $ofwCountry = trim((string)($step2Summary['ofw_country'] ?? $ofwCountry));
            $yearsAbroad = trim((string)($step2Summary['years_abroad'] ?? $yearsAbroad));
            $employerContactOfw = trim((string)($step2Summary['employer_contact_ofw'] ?? $employerContactOfw));
            $employerName = trim((string)($step2Summary['employer_name'] ?? $employerName));
            $occupation = trim((string)($step2Summary['occupation'] ?? $occupation));
            $monthlyIncome = trim((string)($step2Summary['monthly_income'] ?? $monthlyIncome));
            $employmentLength = trim((string)($step2Summary['employment_length'] ?? $employmentLength));
            $employerAddress = trim((string)($step2Summary['employer_address'] ?? $employerAddress));
            $employerContact = trim((string)($step2Summary['employer_contact'] ?? $employerContact));
            $businessName = trim((string)($step2Summary['business_name'] ?? $businessName));
            $businessAddress = trim((string)($step2Summary['business_address'] ?? $businessAddress));
            $pensionSource = trim((string)($step2Summary['pension_source'] ?? $pensionSource));
            $monthlyPension = trim((string)($step2Summary['monthly_pension'] ?? $monthlyPension));
            $pensionContact = trim((string)($step2Summary['pension_contact'] ?? $pensionContact));
            $remarks = trim((string)($step2Summary['remarks'] ?? $remarks));
            $incomeValue = $monthlyIncome !== '' ? $monthlyIncome : $monthlySalary;
            if ($incomeValue === '' && $monthlyPension !== '') {
                $incomeValue = $monthlyPension;
            }
            $financialFields = loanApplicationFinancialFieldsFromStep2($step2Summary, $wizardEligibilityUserId);

            $loanType = trim((string)($step3Summary['loan_type'] ?? $loanType));
            $loanAmount = trim((string)($step3Summary['loan_amount'] ?? $loanAmount));
            $loanTerm = trim((string)($step3Summary['loan_term'] ?? $loanTerm));
            $loanPurpose = trim((string)($step3Summary['loan_purpose'] ?? $loanPurpose));
            $paymentFrequency = trim((string)($step3Summary['payment_frequency'] ?? $paymentFrequency));
            $numberOfPayments = isset($step3Summary['number_of_payments']) ? (int)$step3Summary['number_of_payments'] : $numberOfPayments;
            $estimatedPayment = isset($step3Summary['estimated_payment']) ? (float)$step3Summary['estimated_payment'] : $estimatedPayment;
            $estimatedDueDate = trim((string)($step3Summary['estimated_due_date'] ?? $estimatedDueDate));

            $conn->exec("CREATE TABLE IF NOT EXISTS loan_applications (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT DEFAULT NULL,
                borrower_name VARCHAR(200) DEFAULT NULL,
                date_of_birth DATE DEFAULT NULL,
                gender VARCHAR(30) DEFAULT NULL,
                civil_status VARCHAR(30) DEFAULT NULL,
                nationality VARCHAR(100) DEFAULT NULL,
                complete_address TEXT DEFAULT NULL,
                mobile_number VARCHAR(30) DEFAULT NULL,
                email_address VARCHAR(150) DEFAULT NULL,
                government_id_type VARCHAR(100) DEFAULT NULL,
                government_id_number VARCHAR(100) DEFAULT NULL,
                employment_status VARCHAR(80) DEFAULT NULL,
                agency_office VARCHAR(150) DEFAULT NULL,
                position VARCHAR(150) DEFAULT NULL,
                employment_type VARCHAR(100) DEFAULT NULL,
                monthly_income DECIMAL(12,2) DEFAULT NULL,
                years_in_service VARCHAR(80) DEFAULT NULL,
                office_address TEXT DEFAULT NULL,
                office_contact VARCHAR(30) DEFAULT NULL,
                employer_name VARCHAR(150) DEFAULT NULL,
                occupation VARCHAR(150) DEFAULT NULL,
                employment_length VARCHAR(80) DEFAULT NULL,
                employer_address TEXT DEFAULT NULL,
                employer_contact VARCHAR(30) DEFAULT NULL,
                business_name VARCHAR(150) DEFAULT NULL,
                business_address TEXT DEFAULT NULL,
                nature_of_business VARCHAR(150) DEFAULT NULL,
                years_in_business VARCHAR(80) DEFAULT NULL,
                business_contact VARCHAR(30) DEFAULT NULL,
                profession VARCHAR(150) DEFAULT NULL,
                primary_client VARCHAR(150) DEFAULT NULL,
                years_experience VARCHAR(80) DEFAULT NULL,
                ofw_country VARCHAR(100) DEFAULT NULL,
                years_abroad VARCHAR(80) DEFAULT NULL,
                employer_contact_ofw VARCHAR(30) DEFAULT NULL,
                pension_source VARCHAR(150) DEFAULT NULL,
                monthly_pension DECIMAL(12,2) DEFAULT NULL,
                pension_contact VARCHAR(30) DEFAULT NULL,
                loan_type VARCHAR(80) DEFAULT NULL,
                loan_amount DECIMAL(12,2) DEFAULT NULL,
                loan_term VARCHAR(50) DEFAULT NULL,
                loan_purpose TEXT DEFAULT NULL,
                approved_loan_amount DECIMAL(12,2) DEFAULT NULL,
                approved_interest_rate DECIMAL(5,2) DEFAULT NULL,
                approved_monthly_payment DECIMAL(12,2) DEFAULT NULL,
                approved_loan_term VARCHAR(50) DEFAULT NULL,
                approved_first_payment_date DATE DEFAULT NULL,
                approved_due_date DATE DEFAULT NULL,
                approval_conditions TEXT DEFAULT NULL,
                remarks TEXT DEFAULT NULL,
                status VARCHAR(50) DEFAULT 'Pending Review',
                submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $applicationData = [
                'user_id' => $wizardEligibilityUserId > 0 ? $wizardEligibilityUserId : null,
                'borrower_name' => $fullName,
                'date_of_birth' => $dob !== '' ? $dob : null,
                'gender' => $gender,
                'civil_status' => $civilStatus,
                'nationality' => $nationality,
                'complete_address' => $address,
                'mobile_number' => $mobileNumber,
                'email_address' => $email,
                'government_id_type' => $governmentIdType,
                'government_id_number' => $governmentIdNumber,
                'employment_status' => $employmentStatus,
                'agency_office' => $agencyOffice,
                'position' => $position,
                'employment_type' => $employmentType,
                'monthly_income' => $incomeValue !== '' ? $incomeValue : null,
                'years_in_service' => $yearsInService,
                'office_address' => $officeAddress,
                'office_contact' => $officeContact,
                'employer_name' => $employerName,
                'occupation' => $occupation,
                'employment_length' => $employmentLength,
                'employer_address' => $employerAddress,
                'employer_contact' => $employerContact,
                'business_name' => $businessName,
                'business_address' => $businessAddress,
                'nature_of_business' => $natureOfBusiness,
                'years_in_business' => $yearsInBusiness,
                'business_contact' => $businessContact,
                'profession' => $profession,
                'primary_client' => $primaryClient,
                'years_experience' => $yearsExperience,
                'ofw_country' => $ofwCountry,
                'years_abroad' => $yearsAbroad,
                'employer_contact_ofw' => $employerContactOfw,
                'pension_source' => $pensionSource,
                'monthly_pension' => $monthlyPension !== '' ? $monthlyPension : null,
                'pension_contact' => $pensionContact,
                'loan_type' => $loanType,
                'loan_amount' => $loanAmount !== '' ? $loanAmount : null,
                'loan_term' => $loanTerm,
                'payment_frequency' => $paymentFrequency,
                'number_of_payments' => $numberOfPayments > 0 ? $numberOfPayments : null,
                'estimated_payment' => $estimatedPayment > 0 ? $estimatedPayment : null,
                'estimated_due_date' => $estimatedDueDate !== '' ? $estimatedDueDate : null,
                'monthly_interest_rate' => $monthlyInterestRate,
                'estimated_interest' => $estimatedInterest,
                'estimated_monthly_payment' => $estimatedMonthlyPayment,
                'loan_purpose' => $loanPurpose,
                'approved_loan_amount' => null,
                'approved_interest_rate' => null,
                'approved_monthly_payment' => null,
                'approved_loan_term' => null,
                'approved_first_payment_date' => null,
                'approved_due_date' => null,
                'approval_conditions' => null,
                'remarks' => $remarks,
                'status' => 'Pending Review',
            ];
            $applicationData = array_merge($applicationData, $financialFields);

            $columns = array_keys($applicationData);
            $placeholders = implode(', ', array_fill(0, count($applicationData), '?'));
            $stmt = $conn->prepare('INSERT INTO loan_applications (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')');
            $stmt->execute(array_values($applicationData));

            $applicationId = (int)$conn->lastInsertId();
            if ($applicationId > 0) {
                storeLoanApplicationDocuments($applicationId);
                $step2ForPersist = enrichWizardStep2ForBorrowerEligibility(
                    $_SESSION[lwSessionKey($loanWizardConfig, 2)] ?? [],
                    $wizardEligibilityUserId
                );
                $step3ForPersist = $_SESSION[lwSessionKey($loanWizardConfig, 3)] ?? [];
                $eligibilityAssessment = assessLoanEligibility(
                    buildLoanEligibilityInputFromWizard($step2ForPersist, $step3ForPersist, getStandardMonthlyInterestRatePercent())
                );
                persistLoanEligibilityAssessment($applicationId, $eligibilityAssessment);
            }

            if ($isAdminLoanWizard) {
                if ($applicationId > 0) {
                    $submittedApplication = executeQuery('SELECT * FROM loan_applications WHERE id = ? LIMIT 1', [$applicationId])->fetch(PDO::FETCH_ASSOC);
                    if ($submittedApplication) {
                        try {
                            sendLoanApplicationSubmittedEmail($submittedApplication, $applicationId);
                        } catch (Throwable $mailException) {
                        }
                    }
                    if ($wizardEligibilityUserId > 0) {
                        addNotification($wizardEligibilityUserId, 'Loan Application Submitted', 'Your loan application #' . $applicationId . ' was submitted and is pending review.');
                    }
                }
                $borrowerLabel = trim($fullName) !== '' ? trim($fullName) : 'The client';
                notifyAdmins('New Loan Application', "{$borrowerLabel}'s loan application was submitted by staff and is ready for review.");
                $_SESSION['loan_review_message'] = $borrowerLabel . "'s loan application was recorded. Continue review, agreement, and release from this list.";
                $_SESSION['loan_review_type'] = 'success';
                lwClearWizardSessions($loanWizardConfig);
                unset($_SESSION['admin_loan_client_id']);
                header('Location: loan_applications_lending.php');
                exit;
            }

            notifyAdmins('New Loan Application', "{$fullName} submitted a new loan application requiring review.");

            unset($_SESSION[lwSessionKey($loanWizardConfig, 1)], $_SESSION[lwSessionKey($loanWizardConfig, 2)], $_SESSION[lwSessionKey($loanWizardConfig, 3)], $_SESSION[lwSessionKey($loanWizardConfig, 4)]);

            $_SESSION[lwSubmittedKey($loanWizardConfig)] = true;
            header('Location: ' . $lwPage . '?submitted=1');
            exit;
            }
        }
    } elseif ($editMode && $formAction === 'legacy_submit') {
        $step1Summary = $_SESSION[lwSessionKey($loanWizardConfig, 1)] ?? [];
        $step2Summary = $_SESSION[lwSessionKey($loanWizardConfig, 2)] ?? [];
        $step3Summary = $_SESSION[lwSessionKey($loanWizardConfig, 3)] ?? [];
        $step4Summary = $_SESSION[lwSessionKey($loanWizardConfig, 4)] ?? [];

        $address = trim((string)($step1Summary['address'] ?? $address));
        $mobileNumber = trim((string)($step1Summary['mobile_number'] ?? $mobileNumber));

        $employmentStatus = trim((string)($step2Summary['employment_status'] ?? $employmentStatus));
        $agencyOffice = trim((string)($step2Summary['agency_office'] ?? $agencyOffice));
        $position = trim((string)($step2Summary['position'] ?? $position));
        $employmentType = trim((string)($step2Summary['employment_type'] ?? $employmentType));
        $monthlySalary = trim((string)($step2Summary['monthly_salary'] ?? $monthlySalary));
        $yearsInService = trim((string)($step2Summary['years_in_service'] ?? $yearsInService));
        $officeAddress = trim((string)($step2Summary['office_address'] ?? $officeAddress));
        $officeContact = trim((string)($step2Summary['office_contact'] ?? $officeContact));
        $companyName = trim((string)($step2Summary['company_name'] ?? $companyName));
        $natureOfBusiness = trim((string)($step2Summary['nature_of_business'] ?? $natureOfBusiness));
        $yearsInBusiness = trim((string)($step2Summary['years_in_business'] ?? $yearsInBusiness));
        $businessContact = trim((string)($step2Summary['business_contact'] ?? $businessContact));
        $profession = trim((string)($step2Summary['profession'] ?? $profession));
        $primaryClient = trim((string)($step2Summary['primary_client'] ?? $primaryClient));
        $yearsExperience = trim((string)($step2Summary['years_experience'] ?? $yearsExperience));
        $ofwCountry = trim((string)($step2Summary['ofw_country'] ?? $ofwCountry));
        $yearsAbroad = trim((string)($step2Summary['years_abroad'] ?? $yearsAbroad));
        $employerContactOfw = trim((string)($step2Summary['employer_contact_ofw'] ?? $employerContactOfw));
        $employerName = trim((string)($step2Summary['employer_name'] ?? $employerName));
        $occupation = trim((string)($step2Summary['occupation'] ?? $occupation));
        $monthlyIncome = trim((string)($step2Summary['monthly_income'] ?? $monthlyIncome));
        $employmentLength = trim((string)($step2Summary['employment_length'] ?? $employmentLength));
        $employerAddress = trim((string)($step2Summary['employer_address'] ?? $employerAddress));
        $employerContact = trim((string)($step2Summary['employer_contact'] ?? $employerContact));
        $businessName = trim((string)($step2Summary['business_name'] ?? $businessName));
        $businessAddress = trim((string)($step2Summary['business_address'] ?? $businessAddress));
        $pensionSource = trim((string)($step2Summary['pension_source'] ?? $pensionSource));
        $monthlyPension = trim((string)($step2Summary['monthly_pension'] ?? $monthlyPension));
        $pensionContact = trim((string)($step2Summary['pension_contact'] ?? $pensionContact));
        $remarks = trim((string)($step2Summary['remarks'] ?? $remarks));
        $incomeValue = $monthlyIncome !== '' ? $monthlyIncome : $monthlySalary;
        if ($incomeValue === '' && $monthlyPension !== '') {
            $incomeValue = $monthlyPension;
        }
        $financialFields = loanApplicationFinancialFieldsFromStep2($step2Summary, $wizardEligibilityUserId);

        $loanType = trim((string)($step3Summary['loan_type'] ?? $loanType));
        $loanAmount = trim((string)($step3Summary['loan_amount'] ?? $loanAmount));
        $loanTerm = trim((string)($step3Summary['loan_term'] ?? $loanTerm));
        $loanPurpose = trim((string)($step3Summary['loan_purpose'] ?? $loanPurpose));

        $conn->exec("CREATE TABLE IF NOT EXISTS loan_applications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            borrower_name VARCHAR(200) DEFAULT NULL,
            date_of_birth DATE DEFAULT NULL,
            gender VARCHAR(30) DEFAULT NULL,
            civil_status VARCHAR(30) DEFAULT NULL,
            nationality VARCHAR(100) DEFAULT NULL,
            complete_address TEXT DEFAULT NULL,
            mobile_number VARCHAR(30) DEFAULT NULL,
            email_address VARCHAR(150) DEFAULT NULL,
            government_id_type VARCHAR(100) DEFAULT NULL,
            government_id_number VARCHAR(100) DEFAULT NULL,
            employment_status VARCHAR(80) DEFAULT NULL,
            agency_office VARCHAR(150) DEFAULT NULL,
            position VARCHAR(150) DEFAULT NULL,
            employment_type VARCHAR(100) DEFAULT NULL,
            monthly_income DECIMAL(12,2) DEFAULT NULL,
            years_in_service VARCHAR(80) DEFAULT NULL,
            office_address TEXT DEFAULT NULL,
            office_contact VARCHAR(30) DEFAULT NULL,
            employer_name VARCHAR(150) DEFAULT NULL,
            occupation VARCHAR(150) DEFAULT NULL,
            employment_length VARCHAR(80) DEFAULT NULL,
            employer_address TEXT DEFAULT NULL,
            employer_contact VARCHAR(30) DEFAULT NULL,
            business_name VARCHAR(150) DEFAULT NULL,
            business_address TEXT DEFAULT NULL,
            nature_of_business VARCHAR(150) DEFAULT NULL,
            years_in_business VARCHAR(80) DEFAULT NULL,
            business_contact VARCHAR(30) DEFAULT NULL,
            profession VARCHAR(150) DEFAULT NULL,
            primary_client VARCHAR(150) DEFAULT NULL,
            years_experience VARCHAR(80) DEFAULT NULL,
            ofw_country VARCHAR(100) DEFAULT NULL,
            years_abroad VARCHAR(80) DEFAULT NULL,
            employer_contact_ofw VARCHAR(30) DEFAULT NULL,
            pension_source VARCHAR(150) DEFAULT NULL,
            monthly_pension DECIMAL(12,2) DEFAULT NULL,
            pension_contact VARCHAR(30) DEFAULT NULL,
            loan_type VARCHAR(80) DEFAULT NULL,
            loan_amount DECIMAL(12,2) DEFAULT NULL,
            loan_term VARCHAR(50) DEFAULT NULL,
            loan_purpose TEXT DEFAULT NULL,
            approved_loan_amount DECIMAL(12,2) DEFAULT NULL,
            approved_interest_rate DECIMAL(5,2) DEFAULT NULL,
            approved_monthly_payment DECIMAL(12,2) DEFAULT NULL,
            approved_loan_term VARCHAR(50) DEFAULT NULL,
            approved_first_payment_date DATE DEFAULT NULL,
            approved_due_date DATE DEFAULT NULL,
            approval_conditions TEXT DEFAULT NULL,
            remarks TEXT DEFAULT NULL,
            status VARCHAR(50) DEFAULT 'Pending Review',
            submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $applicationData = [
            'user_id' => $user['user_id'],
            'borrower_name' => $fullName,
            'date_of_birth' => $dob,
            'gender' => $gender,
            'civil_status' => $civilStatus,
            'nationality' => $nationality,
            'complete_address' => $address,
            'mobile_number' => $mobileNumber,
            'email_address' => $email,
            'government_id_type' => $governmentIdType,
            'government_id_number' => $governmentIdNumber,
            'employment_status' => $employmentStatus,
            'agency_office' => $agencyOffice,
            'position' => $position,
            'employment_type' => $employmentType,
            'monthly_income' => $incomeValue !== '' ? $incomeValue : null,
            'years_in_service' => $yearsInService,
            'office_address' => $officeAddress,
            'office_contact' => $officeContact,
            'employer_name' => $employerName,
            'occupation' => $occupation,
            'employment_length' => $employmentLength,
            'employer_address' => $employerAddress,
            'employer_contact' => $employerContact,
            'business_name' => $businessName,
            'business_address' => $businessAddress,
            'nature_of_business' => $natureOfBusiness,
            'years_in_business' => $yearsInBusiness,
            'business_contact' => $businessContact,
            'profession' => $profession,
            'primary_client' => $primaryClient,
            'years_experience' => $yearsExperience,
            'ofw_country' => $ofwCountry,
            'years_abroad' => $yearsAbroad,
            'employer_contact_ofw' => $employerContactOfw,
            'pension_source' => $pensionSource,
            'monthly_pension' => $monthlyPension !== '' ? $monthlyPension : null,
            'pension_contact' => $pensionContact,
            'loan_type' => $loanType,
            'loan_amount' => $loanAmount !== '' ? $loanAmount : null,
            'loan_term' => $loanTerm,
            'payment_frequency' => $paymentFrequency,
            'number_of_payments' => $numberOfPayments > 0 ? $numberOfPayments : null,
            'estimated_payment' => $estimatedPayment > 0 ? $estimatedPayment : null,
            'estimated_due_date' => $estimatedDueDate !== '' ? $estimatedDueDate : null,
            'monthly_interest_rate' => $monthlyInterestRate,
            'estimated_interest' => $estimatedInterest,
            'estimated_monthly_payment' => $estimatedMonthlyPayment,
            'loan_purpose' => $loanPurpose,
            'approved_loan_amount' => null,
            'approved_interest_rate' => null,
            'approved_monthly_payment' => null,
            'approved_loan_term' => null,
            'approved_first_payment_date' => null,
            'approved_due_date' => null,
            'approval_conditions' => null,
            'remarks' => $remarks,
            'status' => 'Pending Review',
        ];
        $applicationData = array_merge($applicationData, $financialFields);

        $columns = array_keys($applicationData);
        $placeholders = implode(', ', array_fill(0, count($applicationData), '?'));
        $stmt = $conn->prepare('INSERT INTO loan_applications (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')');
        $stmt->execute(array_values($applicationData));

        $applicationId = (int)$conn->lastInsertId();
        if ($applicationId > 0) {
            storeLoanApplicationDocuments($applicationId);
            $step2ForPersist = enrichWizardStep2ForBorrowerEligibility(
                $_SESSION[lwSessionKey($loanWizardConfig, 2)] ?? [],
                $wizardEligibilityUserId
            );
            $step3ForPersist = $_SESSION[lwSessionKey($loanWizardConfig, 3)] ?? [];
            $eligibilityAssessment = assessLoanEligibility(
                buildLoanEligibilityInputFromWizard($step2ForPersist, $step3ForPersist, getStandardMonthlyInterestRatePercent())
            );
            persistLoanEligibilityAssessment($applicationId, $eligibilityAssessment);
        }

        notifyAdmins('New Loan Application', "{$fullName} submitted a new loan application requiring review.");
        $submittedApplication = executeQuery('SELECT * FROM loan_applications WHERE id = ? LIMIT 1', [$applicationId])->fetch(PDO::FETCH_ASSOC);
        if ($submittedApplication) {
            sendLoanApplicationSubmittedEmail($submittedApplication, $applicationId);
        }

        unset($_SESSION[lwSessionKey($loanWizardConfig, 1)], $_SESSION[lwSessionKey($loanWizardConfig, 2)], $_SESSION[lwSessionKey($loanWizardConfig, 3)], $_SESSION[lwSessionKey($loanWizardConfig, 4)]);

        $_SESSION[lwSubmittedKey($loanWizardConfig)] = true;
        header('Location: ' . $lwPage . '?submitted=1');
        exit;
    } elseif ($editMode && $formAction === 'save') {
        $skipEligibility = !empty($loanWizardConfig['skip_eligibility_blocks']);
        if (!$skipEligibility && $step === 2 && $wizardEligibilityUserId > 0) {
            $step2ForEligibility = enrichWizardStep2ForBorrowerEligibility(
                $_SESSION[lwSessionKey($loanWizardConfig, 2)] ?? [],
                $wizardEligibilityUserId
            );
            $step2BlockMessage = loanEligibilityBlocksStep2Progress($step2ForEligibility);
            if ($step2BlockMessage !== null) {
                $error = $step2BlockMessage;
            }
        } elseif (!$skipEligibility && $step === 3 && $wizardEligibilityUserId > 0) {
            $step2ForEligibility = enrichWizardStep2ForBorrowerEligibility(
                $_SESSION[lwSessionKey($loanWizardConfig, 2)] ?? [],
                $wizardEligibilityUserId
            );
            $step3ForEligibility = $_SESSION[lwSessionKey($loanWizardConfig, 3)] ?? [];
            $eligibilityForProgress = assessLoanEligibility(
                buildLoanEligibilityInputFromWizard($step2ForEligibility, $step3ForEligibility, getStandardMonthlyInterestRatePercent())
            );
            $eligibilityBlockMessage = loanEligibilityBlocksWizardProgress($eligibilityForProgress);
            if ($eligibilityBlockMessage !== null) {
                $error = $eligibilityBlockMessage;
            }
        }

        if ($error === '') {
            if ($step === 4 && $requireWizardDocuments) {
                $missingRequiredDocuments = loanApplicationMissingRequiredDocuments($_SESSION[lwSessionKey($loanWizardConfig, 4)] ?? []);
                if ($missingRequiredDocuments !== []) {
                    loanApplicationBlockForMissingDocuments($missingRequiredDocuments);
                } else {
                    $step = 5;
                    $editMode = false;
                    $successMessage = 'Your changes were saved. Review the updated section below.';
                }
            } else {
                $step = 5;
                $editMode = false;
                $successMessage = 'Your changes were saved. Review the updated section below.';
            }
        }
    } elseif ($step < 5) {
        $skipEligibility = !empty($loanWizardConfig['skip_eligibility_blocks']);
        if ($step === 2 && !$skipEligibility && $wizardEligibilityUserId > 0) {
            $step2ForEligibility = enrichWizardStep2ForBorrowerEligibility(
                $_SESSION[lwSessionKey($loanWizardConfig, 2)] ?? [],
                $wizardEligibilityUserId
            );
            $step2BlockMessage = loanEligibilityBlocksStep2Progress($step2ForEligibility);
            if ($step2BlockMessage !== null) {
                $step = 2;
                $error = $step2BlockMessage;
            } else {
                $step++;
            }
        } elseif ($step === 3 && !$skipEligibility && $wizardEligibilityUserId > 0) {
            $step2ForEligibility = enrichWizardStep2ForBorrowerEligibility(
                $_SESSION[lwSessionKey($loanWizardConfig, 2)] ?? [],
                $wizardEligibilityUserId
            );
            $step3ForEligibility = $_SESSION[lwSessionKey($loanWizardConfig, 3)] ?? [];
            $eligibilityForProgress = assessLoanEligibility(
                buildLoanEligibilityInputFromWizard($step2ForEligibility, $step3ForEligibility, getStandardMonthlyInterestRatePercent())
            );
            $eligibilityBlockMessage = loanEligibilityBlocksWizardProgress($eligibilityForProgress);
            if ($eligibilityBlockMessage !== null) {
                $step = 3;
                $error = $eligibilityBlockMessage;
            } else {
                $step++;
            }
        } elseif ($step === 4 && $requireWizardDocuments) {
            $missingRequiredDocuments = loanApplicationMissingRequiredDocuments($_SESSION[lwSessionKey($loanWizardConfig, 4)] ?? []);
            if ($missingRequiredDocuments !== []) {
                loanApplicationBlockForMissingDocuments($missingRequiredDocuments);
            } else {
                $step++;
            }
        } elseif ($step === 1 && $isAdminLoanWizard && $adminClientId <= 0) {
            $step = 1;
            $error = 'Please select a client to continue.';
        } elseif ($step === 1 && $isAdminLoanWizard && $wizardEligibilityUserId > 0 && !borrowerCanApplyForNewLoan($wizardEligibilityUserId)) {
            $step = 1;
            $error = 'This client already has an active loan that is not fully paid. Choose another client or settle the current loan before continuing.';
        } else {
            $step++;
        }
    }

    if ($isAdminLoanWizard && $adminClientId > 0) {
        $borrowerApplication = loanWizardHydrateClientProfile($adminClientId) ?: $borrowerApplication;
    }
    $wizardEligibilityUserId = loanWizardEligibilityUserId($isAdminLoanWizard, $adminClientId, $borrowerApplication, $user);
    $borrowerCanApplyForLoan = $wizardEligibilityUserId > 0
        ? borrowerCanApplyForNewLoan($wizardEligibilityUserId)
        : true;
    $borrowerActiveLoanObligation = $wizardEligibilityUserId > 0
        ? getBorrowerActiveLoanMonthlyObligation($wizardEligibilityUserId)
        : 0.0;
    $applicationBlocked = !$isAdminLoanWizard && $wizardEligibilityUserId > 0 && !$borrowerCanApplyForLoan;
    $adminClientLoanBlocked = $isAdminLoanWizard && $adminClientId > 0 && $wizardEligibilityUserId > 0 && !$borrowerCanApplyForLoan;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !$submitted && $step === 5 && !empty($loanWizardConfig['require_documents'])) {
    $missingRequiredDocuments = loanApplicationMissingRequiredDocuments($_SESSION[lwSessionKey($loanWizardConfig, 4)] ?? []);
    if ($missingRequiredDocuments !== []) {
        $step = 4;
        if ($error === '') {
            loanApplicationBlockForMissingDocuments($missingRequiredDocuments);
        }
    }
}

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
    && !$submitted
    && !$applicationBlocked
    && $wizardEligibilityUserId > 0
    && empty($loanWizardConfig['skip_eligibility_blocks'])
) {
    $wizardStepGate = gateBorrowerWizardStep($step, $loanWizardConfig, $wizardEligibilityUserId);
    $step = (int)$wizardStepGate['step'];
    if ($wizardStepGate['message'] !== '' && $error === '') {
        $error = $wizardStepGate['message'];
    }
}

if ($isAdminLoanWizard && $adminClientId > 0) {
    $borrowerApplication = loanWizardHydrateClientProfile($adminClientId) ?: $borrowerApplication;
    $fullName = trim(($borrowerApplication['first_name'] ?? '') . ' ' . ($borrowerApplication['middle_name'] ?? '') . ' ' . ($borrowerApplication['last_name'] ?? ''));
    $dob = trim((string)($borrowerApplication['date_of_birth'] ?? ''));
    $gender = trim((string)($borrowerApplication['gender'] ?? ''));
    $civilStatus = trim((string)($borrowerApplication['civil_status'] ?? ''));
    $nationality = trim((string)($borrowerApplication['nationality'] ?? ''));
    $email = trim((string)($borrowerApplication['email'] ?? ''));
    $governmentIdType = trim((string)($borrowerApplication['government_id_type'] ?? ''));
    $governmentIdNumber = trim((string)($borrowerApplication['government_id_number'] ?? ''));
}

$step4Summary = $_SESSION[lwSessionKey($loanWizardConfig, 4)] ?? [];
$uploadedDocuments = loanApplicationNormalizeStep4Documents(
    $step4Summary !== [] ? $step4Summary : loanApplicationDefaultStep4Documents()
);

$step2Summary = $_SESSION[lwSessionKey($loanWizardConfig, 2)] ?? [];
$employmentStatus = trim((string)($step2Summary['employment_status'] ?? $employmentStatus));
$agencyOffice = trim((string)($step2Summary['agency_office'] ?? $agencyOffice));
$position = trim((string)($step2Summary['position'] ?? $position));
$employmentType = trim((string)($step2Summary['employment_type'] ?? $employmentType));
$monthlySalary = trim((string)($step2Summary['monthly_salary'] ?? $monthlySalary));
$yearsInService = trim((string)($step2Summary['years_in_service'] ?? $yearsInService));
$officeAddress = trim((string)($step2Summary['office_address'] ?? $officeAddress));
$officeContact = trim((string)($step2Summary['office_contact'] ?? $officeContact));
$companyName = trim((string)($step2Summary['company_name'] ?? $companyName));
$natureOfBusiness = trim((string)($step2Summary['nature_of_business'] ?? $natureOfBusiness));
$yearsInBusiness = trim((string)($step2Summary['years_in_business'] ?? $yearsInBusiness));
$businessContact = trim((string)($step2Summary['business_contact'] ?? $businessContact));
$profession = trim((string)($step2Summary['profession'] ?? $profession));
$primaryClient = trim((string)($step2Summary['primary_client'] ?? $primaryClient));
$yearsExperience = trim((string)($step2Summary['years_experience'] ?? $yearsExperience));
$ofwCountry = trim((string)($step2Summary['ofw_country'] ?? $ofwCountry));
$yearsAbroad = trim((string)($step2Summary['years_abroad'] ?? $yearsAbroad));
$employerContactOfw = trim((string)($step2Summary['employer_contact_ofw'] ?? $employerContactOfw));
$employerName = trim((string)($step2Summary['employer_name'] ?? $employerName));
$occupation = trim((string)($step2Summary['occupation'] ?? $occupation));
$monthlyIncome = trim((string)($step2Summary['monthly_income'] ?? $monthlyIncome));
$employmentLength = trim((string)($step2Summary['employment_length'] ?? $employmentLength));
$employerAddress = trim((string)($step2Summary['employer_address'] ?? $employerAddress));
$employerContact = trim((string)($step2Summary['employer_contact'] ?? $employerContact));
$businessName = trim((string)($step2Summary['business_name'] ?? $businessName));
$businessAddress = trim((string)($step2Summary['business_address'] ?? $businessAddress));
$pensionSource = trim((string)($step2Summary['pension_source'] ?? $pensionSource));
$monthlyPension = trim((string)($step2Summary['monthly_pension'] ?? $monthlyPension));
$pensionContact = trim((string)($step2Summary['pension_contact'] ?? $pensionContact));
$remarks = trim((string)($step2Summary['remarks'] ?? $remarks));
$monthlyGrossIncome = trim((string)($step2Summary['monthly_gross_income'] ?? $monthlyGrossIncome));
$monthlyGrossIncome = trim((string)(applyStep2EmploymentGrossIncomeSync([
    'monthly_gross_income' => $monthlyGrossIncome,
    'monthly_income' => $monthlyIncome,
    'monthly_salary' => $monthlySalary,
    'monthly_pension' => $monthlyPension,
])['monthly_gross_income'] ?? $monthlyGrossIncome));
$monthlyNetIncome = trim((string)($step2Summary['monthly_net_income'] ?? $monthlyNetIncome));
$monthlyDebtPayments = trim((string)($step2Summary['monthly_debt_payments'] ?? $monthlyDebtPayments));
$otherMonthlyObligations = trim((string)($step2Summary['other_monthly_obligations'] ?? $otherMonthlyObligations));
$numberOfDependents = trim((string)($step2Summary['number_of_dependents'] ?? $numberOfDependents));
$collateralNote = trim((string)($step2Summary['collateral_note'] ?? $collateralNote));
$borrowerCreditHistory = $wizardEligibilityUserId > 0 ? buildBorrowerInternalCreditHistory($wizardEligibilityUserId) : null;
$creditHistoryNote = is_array($borrowerCreditHistory) ? trim((string)$borrowerCreditHistory['note']) : '';
$step3Summary = $_SESSION[lwSessionKey($loanWizardConfig, 3)] ?? [];
$loanType = trim((string)($step3Summary['loan_type'] ?? $loanType));
$loanAmount = trim((string)($step3Summary['loan_amount'] ?? $loanAmount));
$loanTerm = trim((string)($step3Summary['loan_term'] ?? $loanTerm));
$loanPurpose = trim((string)($step3Summary['loan_purpose'] ?? $loanPurpose));
$paymentFrequency = trim((string)($step3Summary['payment_frequency'] ?? $paymentFrequency));
$dateReleased = trim((string)($step3Summary['date_released'] ?? date('Y-m-d')));
$numberOfPayments = isset($step3Summary['number_of_payments']) ? (int)$step3Summary['number_of_payments'] : 0;
$estimatedPayment = isset($step3Summary['estimated_payment']) ? (float)$step3Summary['estimated_payment'] : 0;
$estimatedMonthlyPayment = isset($step3Summary['estimated_monthly_payment']) ? (float)$step3Summary['estimated_monthly_payment'] : 0;
$estimatedInterest = isset($step3Summary['estimated_interest']) ? (float)$step3Summary['estimated_interest'] : 0;
$estimatedDueDate = trim((string)($step3Summary['estimated_due_date'] ?? $estimatedDueDate));
$loanAmountValue = is_numeric($loanAmount) ? (float)$loanAmount : 0.0;
$loanTermMonths = parseLoanTermMonths($loanTerm);
$paymentSchedule = calculatePaymentSchedule($paymentFrequency, $loanTermMonths, $loanAmountValue + $estimatedInterest);
$paymentLabel = $paymentSchedule['payment_label'];

$step2ReviewTitle = $employmentStatus === 'Self-Employed / Business Owner'
    ? 'Business & Income Information'
    : 'Employment Information';
$step2ReviewIcon = $employmentStatus === 'Self-Employed / Business Owner' ? 'fa-store' : 'fa-briefcase';
$incomeSummary = getIncomeSummaryDetails($employmentStatus, $monthlyIncome, $monthlySalary, $monthlyPension);

$wizardSteps = [
    1 => ['short' => 'Personal', 'title' => 'Personal information', 'desc' => 'Verify your identity and contact details from registration.', 'icon' => 'fa-user-circle'],
    2 => ['short' => 'Employment', 'title' => 'Employment & income', 'desc' => 'Share your work or income source so we can evaluate your application.', 'icon' => 'fa-briefcase'],
    3 => ['short' => 'Loan', 'title' => 'Loan details', 'desc' => 'Choose your loan product, amount, term, and payment schedule.', 'icon' => 'fa-hand-holding-dollar'],
    4 => ['short' => 'Documents', 'title' => 'Requirements', 'desc' => 'Upload clear copies of IDs and supporting documents.', 'icon' => 'fa-cloud-arrow-up'],
    5 => ['short' => 'Review', 'title' => 'Review & submit', 'desc' => 'Confirm all details, then submit to our loan officers.', 'icon' => 'fa-clipboard-check'],
];
$wizardTips = [
    1 => 'Profile fields from registration are locked for security. Update your address or mobile number if anything changed.',
    2 => 'Select the employment type that matches your primary income. Only the relevant fields will appear.',
    3 => 'Payment estimates are illustrative (5% monthly). Final rates and schedules are confirmed after approval.',
    4 => 'Use well-lit photos or PDF scans. Blurry or cropped documents may delay verification.',
    5 => 'Use Edit on any section to go back. You must accept the declaration before submitting.',
];
$wizardProgressPct = (int)round(($step / 5) * 100);
$reviewDocsUploaded = count(array_filter($uploadedDocuments, static function ($doc) {
    return in_array(trim((string)($doc['status'] ?? '')), ['Uploaded', 'Pending Review'], true);
}));
$step2SummaryForEligibility = $step2Summary;
if ($wizardEligibilityUserId > 0) {
    $step2SummaryForEligibility = enrichWizardStep2ForBorrowerEligibility($step2Summary, $wizardEligibilityUserId);
}
$loanEligibilityPreview = assessLoanEligibility(
    buildLoanEligibilityInputFromWizard($step2SummaryForEligibility, $step3Summary, getStandardMonthlyInterestRatePercent())
);
$step3AffordabilityBlock = loanEligibilityBlocksWizardProgress($loanEligibilityPreview);
$step2AffordabilitySummary = summarizeStep2Affordability($step2SummaryForEligibility);
$step2AffordabilityBlock = loanEligibilityBlocksStep2Progress($step2SummaryForEligibility);
$profileInitials = '';
foreach (preg_split('/\s+/', trim($fullName)) as $namePart) {
    if ($namePart !== '') {
        $profileInitials .= strtoupper(substr($namePart, 0, 1));
    }
}
$profileInitials = substr($profileInitials, 0, 2) ?: 'BR';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/lending_assets.php'; lendingRenderPwaMeta(); ?>
    <title>Loan Application - RJ and RR Finance Services</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="css/lending_styles.css">
    <link rel="stylesheet" href="css/pwa.css">
</head>
<body class="loan-wizard-page">
    <?php include 'includes/nav_lending.php'; ?>

    <div class="loan-wizard-shell<?php echo (!$submitted && $step === 5) ? ' loan-wizard-shell--review' : ''; ?>">
        <header class="loan-wizard-hero">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="collector-hero__logo">
                        <img src="images/logo.png" alt="RJ and RR Finance Services logo">
                    </div>
                    <div>
                        <h1 class="loan-wizard-hero__title"><i class="fas fa-file-signature me-2 text-primary"></i><?php echo htmlspecialchars($loanWizardConfig['hero_title'] ?? 'Loan application'); ?></h1>
                        <p class="loan-wizard-hero__lead"><?php echo htmlspecialchars($loanWizardConfig['hero_lead'] ?? 'Five guided steps — save progress as you go and submit when you are ready.'); ?></p>
                    </div>
                </div>
                <a href="<?php echo htmlspecialchars($loanWizardConfig['back_url'] ?? 'borrower_dashboard_lending.php'); ?>" class="btn btn-outline-primary btn-sm"><i class="fas fa-arrow-left me-1"></i> <?php echo $isAdminLoanWizard ? 'Back to Loans' : 'Dashboard'; ?></a>
            </div>
        </header>

        <?php if ($applicationBlocked): ?>
            <div class="card shadow-sm border-0">
                <div class="card-body text-center py-5 px-4">
                    <div class="login-logo mx-auto mb-3">
                        <img src="images/logo.png" alt="RJ and RR Finance Services logo">
                    </div>
                    <h3 class="fw-bold text-primary mb-2">New application unavailable</h3>
                    <p class="text-muted mb-4">You already have an active loan that is not fully paid. Please complete your current loan before applying for another one.</p>
                    <div class="d-flex justify-content-center gap-2 flex-wrap">
                        <a href="borrower_my_loan_lending.php" class="btn btn-primary"><i class="fas fa-file-invoice-dollar me-2"></i>View My Loan</a>
                        <a href="borrower_dashboard_lending.php" class="btn btn-outline-primary">Back to Dashboard</a>
                    </div>
                </div>
            </div>
        <?php elseif ($submitted): ?>
            <div class="loan-wizard-success">
                <div class="login-logo mx-auto mb-3">
                    <img src="images/logo.png" alt="RJ and RR Finance Services logo">
                </div>
                <h3 class="fw-bold text-primary mb-2">Application submitted</h3>
                <p class="text-muted mb-4">Your application is with our loan officers. We will notify you when the review is complete.</p>
                <div class="alert alert-success rounded-4 d-inline-block text-start">You can track status anytime from your borrower dashboard.</div>
                <div class="d-flex justify-content-center gap-2 flex-wrap mt-4">
                    <a href="borrower_loan_status_lending.php" class="btn btn-primary">View loan status</a>
                    <a href="borrower_dashboard_lending.php" class="btn btn-outline-primary">Return to dashboard</a>
                </div>
            </div>

            <div class="modal fade" id="applicationSubmittedModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Application Sent</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body text-start">
                            <p>Your loan application has been successfully submitted.</p>
                            <p>You will receive a notification once a loan officer has reviewed it.</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Okay</button>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="loan-wizard-progress-mobile">
                <div class="d-flex justify-content-between align-items-center">
                    <strong class="small text-primary"><?php echo htmlspecialchars($wizardSteps[$step]['title']); ?></strong>
                    <span class="small text-muted">Step <?php echo (int)$step; ?>/5</span>
                </div>
                <div class="loan-wizard-progress-mobile__bar" aria-hidden="true">
                    <div class="loan-wizard-progress-mobile__fill" style="width: <?php echo $wizardProgressPct; ?>%;"></div>
                </div>
            </div>

            <?php if (($error !== '' && $documentErrorModalLabels === []) || $successMessage !== ''): ?>
            <div class="loan-wizard-alerts">
                <?php if ($error !== '' && $documentErrorModalLabels === []): ?>
                    <div class="alert alert-danger rounded-4 mb-2"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if ($successMessage !== ''): ?>
                    <div class="alert alert-success rounded-4 mb-0"><?php echo htmlspecialchars($successMessage); ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($loanWizardPrefilledFromHistory) && !$submitted): ?>
            <div class="alert alert-info rounded-4 mb-3">
                <?php if ($isAdminLoanWizard): ?>
                    This client's previous loan details are filled in from the last application. You can edit any step before submitting.
                <?php else: ?>
                    Your previous loan details are filled in. You can edit any step before submitting.
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" class="row loan-wizard-layout g-4<?php echo $step === 5 ? ' is-review-step' : ''; ?>">
                <?php if ($step !== 5): ?>
                <aside class="col-lg-4 loan-wizard-aside" aria-label="Application progress">
                    <nav class="loan-wizard-stepper" aria-label="Steps">
                        <div class="loan-wizard-stepper__label">Your progress</div>
                        <?php for ($i = 1; $i <= 5; $i++):
                            $meta = $wizardSteps[$i];
                            $stateClass = $i < $step ? 'is-complete' : ($i === $step ? 'is-current' : 'is-upcoming');
                            $stepHref = $i < $step ? $lwPage . '?step=' . $i : '#';
                            $tag = $i < $step ? 'a' : 'div';
                        ?>
                            <<?php echo $tag; ?> class="loan-wizard-step <?php echo $stateClass; ?>"<?php echo $tag === 'a' ? ' href="' . htmlspecialchars($stepHref, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
                                <div class="loan-wizard-step__marker" aria-hidden="true">
                                    <?php if ($i < $step): ?>
                                        <i class="fas fa-check"></i>
                                    <?php else: ?>
                                        <?php echo (int)$i; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="loan-wizard-step__text">
                                    <strong><?php echo htmlspecialchars($meta['title']); ?></strong>
                                    <span><?php echo htmlspecialchars($meta['short']); ?></span>
                                </div>
                            </<?php echo $tag; ?>>
                        <?php endfor; ?>
                    </nav>
                    <div class="loan-wizard-tip">
                        <div class="loan-wizard-tip__title"><i class="fas fa-lightbulb"></i> Tip</div>
                        <?php echo htmlspecialchars($wizardTips[$step] ?? ''); ?>
                    </div>
                </aside>
                <?php endif; ?>

                <div class="<?php echo $step === 5 ? 'col-12' : 'col-lg-8'; ?> loan-wizard-main">
                <input type="hidden" name="step" value="<?php echo $step; ?>">
                <?php if ($editMode): ?>
                    <input type="hidden" name="edit_mode" value="1">
                <?php endif; ?>
                <?php if ($step === 1): ?>
                        <div class="card shadow-sm loan-wizard-panel">
                            <div class="card-header bg-white py-3">
                                <div class="loan-wizard-panel__head">
                                    <div>
                                        <h2 class="loan-wizard-panel__title"><i class="fas <?php echo $wizardSteps[1]['icon']; ?> me-2 text-primary"></i><?php echo htmlspecialchars($wizardSteps[1]['title']); ?></h2>
                                        <p class="loan-wizard-panel__subtitle"><?php echo htmlspecialchars($wizardSteps[1]['desc']); ?></p>
                                    </div>
                                    <span class="loan-wizard-panel__badge">Step 1 of 5</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if ($isAdminLoanWizard): ?>
                                <div class="row g-3 mb-3">
                                    <div class="col-12">
                                        <label class="form-label">Client <span class="required-star">*</span></label>
                                        <select class="form-select" name="client_id" id="adminLoanClientSelect" required>
                                            <option value="">Select client</option>
                                            <?php foreach ($adminClients as $clientRow): ?>
                                                <option value="<?php echo (int)$clientRow['client_id']; ?>" data-can-apply="<?php echo !empty($clientRow['can_apply_for_new_loan']) ? '1' : '0'; ?>" <?php echo $adminClientId === (int)$clientRow['client_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars(trim(($clientRow['first_name'] ?? '') . ' ' . ($clientRow['last_name'] ?? ''))); ?>
                                                    <?php echo empty($clientRow['can_apply_for_new_loan']) ? ' (active unpaid loan)' : ''; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="loan-wizard-field-note mt-1">Choose the borrower this loan is for. Submitting records a loan application so you can review, approve, and release it the same way as a borrower application.</div>
                                        <div id="adminClientActiveLoanAlert" class="alert alert-warning alert-permanent rounded-4 mt-3 mb-0<?php echo $adminClientLoanBlocked ? '' : ' d-none'; ?>" role="alert">
                                            <strong>Cannot create a new loan for this client.</strong>
                                            They already have an active loan that is not fully paid. Select a different client or settle the current loan first.
                                            <?php if ($adminClientId > 0): ?>
                                                <div class="mt-2">
                                                    <a href="client_details_lending.php?id=<?php echo (int)$adminClientId; ?>" class="alert-link">View client record</a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <div class="loan-wizard-profile-strip">
                                    <div class="loan-wizard-profile-strip__avatar" aria-hidden="true"><?php echo htmlspecialchars($profileInitials); ?></div>
                                    <div>
                                        <p class="loan-wizard-profile-strip__name"><?php echo htmlspecialchars(trim($fullName) !== '' ? trim($fullName) : 'Select a client'); ?></p>
                                        <p class="loan-wizard-profile-strip__meta"><i class="fas fa-shield-halved me-1"></i> <?php echo $isAdminLoanWizard ? 'Client profile · update contact fields below if needed' : 'Registered profile · update contact fields below if needed'; ?></p>
                                    </div>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Full Name</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars(trim(($borrowerApplication['first_name'] ?? '') . ' ' . ($borrowerApplication['middle_name'] ?? '') . ' ' . ($borrowerApplication['last_name'] ?? ''))); ?>" disabled>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Date of Birth</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($borrowerApplication['date_of_birth'] ?? ''); ?>" disabled>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Gender</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($borrowerApplication['gender'] ?? ''); ?>" disabled>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Civil Status</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($borrowerApplication['civil_status'] ?? ''); ?>" disabled>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Nationality</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($borrowerApplication['nationality'] ?? ''); ?>" disabled>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Complete Address</label>
                                        <input type="text" class="form-control" name="address" value="<?php echo htmlspecialchars($address); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Mobile Number</label>
                                        <input type="text" class="form-control" name="mobile_number" value="<?php echo htmlspecialchars($mobileNumber); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Email Address</label>
                                        <input type="email" class="form-control" value="<?php echo htmlspecialchars($borrowerApplication['email'] ?? ''); ?>" disabled>
                                    </div>
                                </div>
                            </div>
                        </div>
                <?php elseif ($step === 2): ?>
                        <div class="card shadow-sm loan-wizard-panel">
                            <div class="card-header bg-white py-3">
                                <div class="loan-wizard-panel__head">
                                    <div>
                                        <h2 class="loan-wizard-panel__title"><i class="fas <?php echo $wizardSteps[2]['icon']; ?> me-2 text-primary"></i><?php echo htmlspecialchars($wizardSteps[2]['title']); ?></h2>
                                        <p class="loan-wizard-panel__subtitle"><?php echo htmlspecialchars($wizardSteps[2]['desc']); ?></p>
                                    </div>
                                    <span class="loan-wizard-panel__badge">Step 2 of 5</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label">Employment Status <span class="required-star">*</span></label>
                                        <select class="form-select rounded-3" name="employment_status" id="employmentStatus">
                                            <option value="">Select Employment Status</option>
                                            <option value="Government Employee" <?php echo $employmentStatus === 'Government Employee' ? 'selected' : ''; ?>>Government Employee</option>
                                            <option value="Private Employee" <?php echo $employmentStatus === 'Private Employee' ? 'selected' : ''; ?>>Private Employee</option>
                                            <option value="Self-Employed / Business Owner" <?php echo $employmentStatus === 'Self-Employed / Business Owner' ? 'selected' : ''; ?>>Self-Employed / Business Owner</option>
                                            <option value="OFW" <?php echo $employmentStatus === 'OFW' ? 'selected' : ''; ?>>OFW</option>
                                            <option value="Pensioner" <?php echo $employmentStatus === 'Pensioner' ? 'selected' : ''; ?>>Pensioner</option>
                                        </select>
                                        <?php if ($employmentStatus === ''): ?>
                                        <p class="employment-helper-text mt-2 mb-0">Select your employment type — the matching fields will appear below.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div id="employmentDetails" class="<?php echo $employmentStatus === '' ? 'is-empty' : ''; ?>">
                                    <div class="dynamic-section dynamic-field-group <?php echo $employmentStatus === 'Government Employee' ? 'active' : ''; ?>" data-status="Government Employee" aria-hidden="<?php echo $employmentStatus === 'Government Employee' ? 'false' : 'true'; ?>">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label">Government Agency / Office <span class="required-star">*</span></label>
                                                <input type="text" class="form-control rounded-3" name="agency_office" value="<?php echo htmlspecialchars($agencyOffice); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Position <span class="required-star">*</span></label>
                                                <input type="text" class="form-control rounded-3" name="position" value="<?php echo htmlspecialchars($position); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Employment Status <span class="required-star">*</span></label>
                                                <select class="form-select rounded-3" name="employment_type">
                                                    <option value="">Select</option>
                                                    <option value="Permanent" <?php echo $employmentType === 'Permanent' ? 'selected' : ''; ?>>Permanent</option>
                                                    <option value="Casual" <?php echo $employmentType === 'Casual' ? 'selected' : ''; ?>>Casual</option>
                                                    <option value="Contractual" <?php echo $employmentType === 'Contractual' ? 'selected' : ''; ?>>Contractual</option>
                                                    <option value="Job Order" <?php echo $employmentType === 'Job Order' ? 'selected' : ''; ?>>Job Order</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Monthly Salary <span class="required-star">*</span></label>
                                                <input type="number" class="form-control rounded-3 js-employment-income-field" name="monthly_salary" min="0" step="100" value="<?php echo htmlspecialchars($monthlySalary); ?>" placeholder="0.00">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Years in Service <span class="required-star">*</span></label>
                                                <input type="text" class="form-control rounded-3" name="years_in_service" value="<?php echo htmlspecialchars($yearsInService); ?>" placeholder="e.g. 5 years">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Office Address <span class="required-star">*</span></label>
                                                <input type="text" class="form-control rounded-3" name="office_address" value="<?php echo htmlspecialchars($officeAddress); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Office Contact Number <span class="required-star">*</span></label>
                                                <input type="text" class="form-control rounded-3" name="office_contact" value="<?php echo htmlspecialchars($officeContact); ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="dynamic-section dynamic-field-group <?php echo $employmentStatus === 'Private Employee' ? 'active' : ''; ?>" data-status="Private Employee" aria-hidden="<?php echo $employmentStatus === 'Private Employee' ? 'false' : 'true'; ?>">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label">Company Name <span class="required-star">*</span></label>
                                                <input type="text" class="form-control rounded-3" name="company_name" value="<?php echo htmlspecialchars($companyName); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Position <span class="required-star">*</span></label>
                                                <input type="text" class="form-control rounded-3" name="position" value="<?php echo htmlspecialchars($position); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Employment Status <span class="required-star">*</span></label>
                                                <select class="form-select rounded-3" name="employment_type">
                                                    <option value="">Select</option>
                                                    <option value="Regular" <?php echo $employmentType === 'Regular' ? 'selected' : ''; ?>>Regular</option>
                                                    <option value="Probationary" <?php echo $employmentType === 'Probationary' ? 'selected' : ''; ?>>Probationary</option>
                                                    <option value="Contractual" <?php echo $employmentType === 'Contractual' ? 'selected' : ''; ?>>Contractual</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Monthly Salary <span class="required-star">*</span></label>
                                                <input type="number" class="form-control rounded-3 js-employment-income-field" name="monthly_salary" min="0" step="100" value="<?php echo htmlspecialchars($monthlySalary); ?>" placeholder="0.00">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Length of Employment <span class="required-star">*</span></label>
                                                <input type="text" class="form-control rounded-3" name="employment_length" value="<?php echo htmlspecialchars($employmentLength); ?>" placeholder="e.g. 5 years">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Company Address <span class="required-star">*</span></label>
                                                <input type="text" class="form-control rounded-3" name="employer_address" value="<?php echo htmlspecialchars($employerAddress); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Company Contact Number <span class="required-star">*</span></label>
                                                <input type="text" class="form-control rounded-3" name="company_contact" value="<?php echo htmlspecialchars($businessContact); ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="dynamic-section dynamic-field-group <?php echo $employmentStatus === 'Self-Employed / Business Owner' ? 'active' : ''; ?>" data-status="Self-Employed / Business Owner" aria-hidden="<?php echo $employmentStatus === 'Self-Employed / Business Owner' ? 'false' : 'true'; ?>">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label">Business Name <span class="required-star">*</span></label>
                                                <input type="text" class="form-control rounded-3" name="business_name" value="<?php echo htmlspecialchars($businessName); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Nature of Business <span class="required-star">*</span></label>
                                                <input type="text" class="form-control rounded-3" name="nature_of_business" value="<?php echo htmlspecialchars($natureOfBusiness); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Business Address <span class="required-star">*</span></label>
                                                <input type="text" class="form-control rounded-3" name="business_address" value="<?php echo htmlspecialchars($businessAddress); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Monthly Income <span class="required-star">*</span></label>
                                                <input type="number" class="form-control rounded-3 js-employment-income-field" name="monthly_income" min="0" step="100" value="<?php echo htmlspecialchars($monthlyIncome); ?>" placeholder="0.00">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Years in Business <span class="required-star">*</span></label>
                                                <input type="text" class="form-control rounded-3" name="years_in_business" value="<?php echo htmlspecialchars($yearsInBusiness); ?>" placeholder="e.g. 3 years">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Business Contact Number <span class="required-star">*</span></label>
                                                <input type="text" class="form-control rounded-3" name="business_contact" value="<?php echo htmlspecialchars($businessContact); ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="dynamic-section dynamic-field-group <?php echo $employmentStatus === 'OFW' ? 'active' : ''; ?>" data-status="OFW" aria-hidden="<?php echo $employmentStatus === 'OFW' ? 'false' : 'true'; ?>">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label">Employer Name <span class="required-star">*</span></label>
                                                <input type="text" class="form-control rounded-3" name="employer_name" value="<?php echo htmlspecialchars($employerName); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Country of Employment <span class="required-star">*</span></label>
                                                <input type="text" class="form-control rounded-3" name="ofw_country" value="<?php echo htmlspecialchars($ofwCountry); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Job Position <span class="required-star">*</span></label>
                                                <input type="text" class="form-control rounded-3" name="position" value="<?php echo htmlspecialchars($position); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Monthly Income <span class="required-star">*</span></label>
                                                <input type="number" class="form-control rounded-3 js-employment-income-field" name="monthly_income" min="0" step="100" value="<?php echo htmlspecialchars($monthlyIncome); ?>" placeholder="0.00">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Years Working Abroad <span class="required-star">*</span></label>
                                                <input type="text" class="form-control rounded-3" name="years_abroad" value="<?php echo htmlspecialchars($yearsAbroad); ?>" placeholder="e.g. 2 years">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Employer Contact <span class="text-secondary">(Optional)</span></label>
                                                <input type="text" class="form-control rounded-3" name="employer_contact_ofw" value="<?php echo htmlspecialchars($employerContactOfw); ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="dynamic-section dynamic-field-group <?php echo $employmentStatus === 'Pensioner' ? 'active' : ''; ?>" data-status="Pensioner" aria-hidden="<?php echo $employmentStatus === 'Pensioner' ? 'false' : 'true'; ?>">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label">Pension Source <span class="required-star">*</span></label>
                                                <input type="text" class="form-control rounded-3" name="pension_source" value="<?php echo htmlspecialchars($pensionSource); ?>" placeholder="e.g. SSS, GSIS">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Monthly Pension <span class="required-star">*</span></label>
                                                <input type="number" class="form-control rounded-3 js-employment-income-field" name="monthly_pension" min="0" step="100" value="<?php echo htmlspecialchars($monthlyPension); ?>" placeholder="0.00">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Contact Number <span class="required-star">*</span></label>
                                                <input type="text" class="form-control rounded-3" name="pension_contact" value="<?php echo htmlspecialchars($pensionContact); ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="loan-wizard-financial-panel">
                                    <h3 class="loan-wizard-financial-panel__title"><i class="fas fa-chart-pie me-2 text-primary"></i>Financial assessment (for eligibility estimate)</h3>
                                    <p class="small text-muted mb-3">These figures help calculate debt-to-income (DTI). They do not automatically approve your loan; an officer will review your application.</p>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Monthly gross income</label>
                                            <input type="number" class="form-control rounded-3" name="monthly_gross_income" id="monthlyGrossIncomeInput" min="0" step="100" value="<?php echo htmlspecialchars($monthlyGrossIncome); ?>" placeholder="Before tax/deductions" data-auto-synced="<?php echo $monthlyGrossIncome !== '' ? '1' : '0'; ?>">
                                            <div class="form-text">Filled automatically from your employment income above. Adjust only if your gross differs (e.g. before tax).</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Monthly net income <span class="text-secondary">(optional)</span></label>
                                            <input type="number" class="form-control rounded-3" name="monthly_net_income" min="0" step="100" value="<?php echo htmlspecialchars($monthlyNetIncome); ?>" placeholder="Take-home pay">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Existing monthly debt payments</label>
                                            <input type="number" class="form-control rounded-3" name="monthly_debt_payments" id="monthlyDebtPaymentsInput" min="0" step="100" value="<?php echo htmlspecialchars($monthlyDebtPayments !== '' ? $monthlyDebtPayments : '0'); ?>" placeholder="Loans, credit cards, etc.">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Other recurring obligations</label>
                                            <input type="number" class="form-control rounded-3" name="other_monthly_obligations" id="otherMonthlyObligationsInput" min="0" step="100" value="<?php echo htmlspecialchars($otherMonthlyObligations !== '' ? $otherMonthlyObligations : '0'); ?>" placeholder="Rent, support, etc.">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Number of dependents <span class="text-secondary">(optional)</span></label>
                                            <input type="number" class="form-control rounded-3" name="number_of_dependents" min="0" step="1" value="<?php echo htmlspecialchars($numberOfDependents); ?>">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Collateral <span class="text-secondary">(optional)</span></label>
                                            <input type="text" class="form-control rounded-3" name="collateral_note" maxlength="255" value="<?php echo htmlspecialchars($collateralNote); ?>" placeholder="Describe collateral if applicable">
                                        </div>
                                        <?php if ($creditHistoryNote !== ''): ?>
                                        <div class="col-12">
                                            <label class="form-label" for="creditHistoryNote">Credit history</label>
                                            <textarea id="creditHistoryNote" class="form-control rounded-3 bg-light" name="credit_history_note" rows="3" maxlength="255" readonly><?php echo htmlspecialchars($creditHistoryNote); ?></textarea>
                                            <div class="form-text">
                                                <?php if ($isAdminLoanWizard): ?>
                                                    Filled automatically from this client's previous loans and payments in this system. It appears only after the client has already borrowed and paid here.
                                                <?php else: ?>
                                                    Filled automatically from loans you already paid in this system. You cannot edit it.
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($borrowerActiveLoanObligation > 0): ?>
                                        <p class="small text-muted mt-3 mb-0">
                                            <?php if ($isAdminLoanWizard): ?>
                                                This client&rsquo;s current RJ and RR loan payment(s) (<?php echo formatMoneyPhp($borrowerActiveLoanObligation); ?>/month) are included in the debt-to-income estimate below, in addition to declared debt.
                                            <?php else: ?>
                                                Your current RJ and RR loan payment(s) (<?php echo formatMoneyPhp($borrowerActiveLoanObligation); ?>/month) are included in the debt-to-income estimate below, in addition to what you declare above.
                                            <?php endif; ?>
                                        </p>
                                    <?php endif; ?>
                                    <?php
                                    $step2DtiVisible = is_array($step2AffordabilitySummary);
                                    $step2MaxDti = $step2DtiVisible
                                        ? (float)$step2AffordabilitySummary['max_dti_percent']
                                        : (float)lendingCreditPolicy()['max_dti_percent'];
                                    ?>
                                    <div id="step2DtiEstimate"
                                         class="alert <?php echo $step2AffordabilityBlock !== null ? 'alert-warning' : 'alert-info'; ?> small mt-3 mb-0<?php echo $step2DtiVisible ? '' : ' d-none'; ?>"
                                         data-max-dti="<?php echo htmlspecialchars((string)$step2MaxDti); ?>"
                                         data-system-debt="<?php echo htmlspecialchars((string)$borrowerActiveLoanObligation); ?>">
                                        <strong>DTI before loan (estimate):</strong>
                                        <span id="step2DtiPercent"><?php echo $step2DtiVisible ? number_format((float)$step2AffordabilitySummary['dti_before_percent'], 1) : '0.0'; ?></span>%
                                        (limit <span id="step2DtiLimit"><?php echo number_format($step2MaxDti, 1); ?></span>%).
                                        Room for a new loan payment: <span id="step2DtiRoom"><?php echo $step2DtiVisible ? formatMoneyPhp((float)$step2AffordabilitySummary['room_for_new_loan_payment']) : formatMoneyPhp(0); ?></span>/month.
                                        <span id="step2DtiBlock"<?php echo $step2AffordabilityBlock !== null ? '' : ' class="d-none"'; ?>> You cannot continue to loan details until existing debt is within the guideline or income/debt figures are corrected.</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                <?php elseif ($step === 3): ?>
                        <div class="card shadow-sm loan-wizard-panel">
                            <div class="card-header bg-white py-3">
                                <div class="loan-wizard-panel__head">
                                    <div>
                                        <h2 class="loan-wizard-panel__title"><i class="fas <?php echo $wizardSteps[3]['icon']; ?> me-2 text-primary"></i><?php echo htmlspecialchars($wizardSteps[3]['title']); ?></h2>
                                        <p class="loan-wizard-panel__subtitle"><?php echo htmlspecialchars($wizardSteps[3]['desc']); ?></p>
                                    </div>
                                    <span class="loan-wizard-panel__badge">Step 3 of 5</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Loan Type</label>
                                        <select class="form-select" name="loan_type">
                                            <option value="">Select</option>
                                            <option value="Salary Loan" <?php echo $loanType === 'Salary Loan' ? 'selected' : ''; ?>>Salary Loan</option>
                                            <option value="Business Loan" <?php echo $loanType === 'Business Loan' ? 'selected' : ''; ?>>Business Loan</option>
                                            <option value="Emergency Loan" <?php echo $loanType === 'Emergency Loan' ? 'selected' : ''; ?>>Emergency Loan</option>
                                            <option value="Vehicle Financing" <?php echo $loanType === 'Vehicle Financing' ? 'selected' : ''; ?>>Vehicle Financing</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Loan Amount</label>
                                        <input id="loanAmountInput" type="number" class="form-control" name="loan_amount" min="1000" step="1000" placeholder="10000" value="<?php echo htmlspecialchars($loanAmount); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Loan Term</label>
                                        <select id="loanTermInput" class="form-select" name="loan_term">
                                            <option value="">Select</option>
                                            <option value="3 Months" <?php echo $loanTerm === '3 Months' ? 'selected' : ''; ?>>3 Months</option>
                                            <option value="6 Months" <?php echo $loanTerm === '6 Months' ? 'selected' : ''; ?>>6 Months</option>
                                            <option value="12 Months" <?php echo $loanTerm === '12 Months' ? 'selected' : ''; ?>>12 Months</option>
                                            <option value="24 Months" <?php echo $loanTerm === '24 Months' ? 'selected' : ''; ?>>24 Months</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Payment Frequency</label>
                                        <select id="paymentFrequencyInput" class="form-select" name="payment_frequency">
                                            <option value="Daily" <?php echo $paymentFrequency === 'Daily' ? 'selected' : ''; ?>>Daily</option>
                                            <option value="Weekly" <?php echo $paymentFrequency === 'Weekly' ? 'selected' : ''; ?>>Weekly</option>
                                            <option value="Every Half of the Month" <?php echo $paymentFrequency === 'Every Half of the Month' ? 'selected' : ''; ?>>Every Half of the Month (Semi-Monthly)</option>
                                            <option value="Monthly" <?php echo $paymentFrequency === 'Monthly' ? 'selected' : ''; ?>>Monthly</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Purpose of Loan</label>
                                        <input type="text" class="form-control" name="loan_purpose" placeholder="e.g. Medical expenses" value="<?php echo htmlspecialchars($loanPurpose); ?>">
                                    </div>
                                </div>
                                <div class="loan-estimate-grid">
                                    <div class="loan-estimate-card">
                                        <div class="loan-estimate-card__label">Requested amount</div>
                                        <div id="requestedAmountValue" class="loan-estimate-card__value">₱<?php echo number_format((float)($loanAmount ?? 0), 2); ?></div>
                                    </div>
                                    <div class="loan-estimate-card">
                                        <div id="estimatedPaymentTitle" class="loan-estimate-card__label"><?php echo htmlspecialchars($paymentLabel); ?></div>
                                        <div id="estimatedPaymentValue" class="loan-estimate-card__value">₱<?php echo number_format((float)$estimatedPayment, 2); ?></div>
                                    </div>
                                    <div class="loan-estimate-card">
                                        <div class="loan-estimate-card__label">Estimated interest</div>
                                        <div id="estimatedInterestValue" class="loan-estimate-card__value">₱<?php echo number_format((float)$estimatedInterest, 2); ?></div>
                                    </div>
                                </div>
                                <p class="loan-estimate-disclaimer mb-0">Estimates assume a 5% monthly rate for planning only. Final loan terms are confirmed after officer review and approval.</p>
                                <?php if (($loanEligibilityPreview['status_code'] ?? '') !== 'incomplete'): ?>
                                    <div class="alert <?php echo $step3AffordabilityBlock !== null ? 'alert-warning' : 'alert-info'; ?> small mt-3 mb-0">
                                        <strong>Affordability estimate:</strong>
                                        Maximum loan based on your declared income and debts (DTI limit):
                                        <?php echo formatMoneyPhp((float)($loanEligibilityPreview['maximum_eligible_loan_amount'] ?? 0)); ?>.
                                        <?php if ($step3AffordabilityBlock !== null): ?>
                                            Your current request cannot proceed to document upload until the amount fits within this limit (or you update income/debt on Step 2).
                                        <?php else: ?>
                                            You may continue to upload documents if your requested amount stays at or below this estimate.
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-secondary small mt-3 mb-0">
                                        Complete <strong>Step 2</strong> (employment, income, and debt fields) so we can check whether your requested amount is affordable before document upload.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                <?php elseif ($step === 4): ?>
                        <div class="card shadow-sm loan-wizard-panel">
                            <div class="card-header bg-white py-3">
                                <div class="loan-wizard-panel__head">
                                    <div>
                                        <h2 class="loan-wizard-panel__title"><i class="fas <?php echo $wizardSteps[4]['icon']; ?> me-2 text-primary"></i><?php echo htmlspecialchars($wizardSteps[4]['title']); ?></h2>
                                        <p class="loan-wizard-panel__subtitle"><?php echo htmlspecialchars($wizardSteps[4]['desc']); ?></p>
                                    </div>
                                    <span class="loan-wizard-panel__badge">Step 4 of 5</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php
                                $requiredDocumentLabels = loanApplicationRequiredDocumentLabels();
                                $uploadedByLabel = [];
                                foreach ($uploadedDocuments as $uploadedDocumentRow) {
                                    $uploadedByLabel[trim((string)($uploadedDocumentRow['label'] ?? ''))] = $uploadedDocumentRow;
                                }
                                ?>
                                <p class="small text-muted mb-3">Upload a file for every document below, including Additional Supporting Documents. A card stays light green until a file is added, then turns green.</p>
                                <div class="loan-upload-grid">
                                    <?php foreach (loanApplicationDocumentLabels() as $doc):
                                        $isRequired = in_array($doc, $requiredDocumentLabels, true);
                                        $docRow = $uploadedByLabel[$doc] ?? null;
                                        $isUploaded = $docRow && loanApplicationDocumentIsUploaded($docRow);
                                        $fileName = trim((string)($docRow['name'] ?? ''));
                                    ?>
                                        <div class="loan-upload-tile<?php echo $isUploaded ? ' is-uploaded' : ''; ?>" data-label="<?php echo htmlspecialchars($doc, ENT_QUOTES, 'UTF-8'); ?>" data-required="<?php echo $isRequired ? '1' : '0'; ?>" data-persisted="<?php echo $isUploaded ? '1' : '0'; ?>">
                                            <div class="loan-upload-tile__head">
                                                <p class="loan-upload-tile__title">
                                                    <?php echo htmlspecialchars($doc); ?>
                                                    <?php if ($isRequired): ?><span class="required-star">*</span><?php endif; ?>
                                                </p>
                                                <div class="loan-upload-tile__icon"><i class="fas <?php echo $isUploaded ? 'fa-circle-check' : 'fa-file-arrow-up'; ?>"></i></div>
                                            </div>
                                            <div class="loan-upload-tile__status<?php echo $isUploaded ? ' loan-upload-tile__status--ok' : ''; ?>">
                                                <?php if ($isUploaded): ?>
                                                    <i class="fas fa-circle-check me-1"></i> Uploaded<?php echo $fileName !== '' ? ': ' . htmlspecialchars($fileName) : ''; ?>
                                                <?php else: ?>
                                                    <i class="fas fa-circle me-1"></i> Not uploaded yet
                                                <?php endif; ?>
                                            </div>
                                            <input type="file" class="form-control" name="documents[]" accept=".jpg,.jpeg,.png,.pdf">
                                            <div class="small text-muted mt-2">JPG, PNG, or PDF<?php echo $isUploaded ? ' · choose a file to replace' : ''; ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                <?php elseif ($step === 5): ?>
                        <div class="card shadow-sm loan-wizard-panel h-100">
                            <div class="card-header bg-white py-3">
                                <div class="loan-wizard-panel__head">
                                    <div>
                                        <h2 class="loan-wizard-panel__title"><i class="fas <?php echo $wizardSteps[5]['icon']; ?> me-2 text-primary"></i><?php echo htmlspecialchars($wizardSteps[5]['title']); ?></h2>
                                        <p class="loan-wizard-panel__subtitle"><?php echo htmlspecialchars($wizardSteps[5]['desc']); ?></p>
                                    </div>
                                    <span class="loan-wizard-panel__badge">Step 5 of 5</span>
                                </div>
                            </div>
                            <div class="card-body loan-wizard-review-section p-0">
                                <article class="review-landscape">
                                    <header class="review-landscape__hero">
                                        <div class="review-landscape__hero-text">
                                            <p class="review-landscape__eyebrow">Application preview</p>
                                            <h3 class="review-landscape__title"><?php echo htmlspecialchars(trim($fullName)); ?></h3>
                                            <p class="review-landscape__subtitle"><?php echo date('F j, Y'); ?> · <?php echo htmlspecialchars($employmentStatus !== '' ? $employmentStatus : 'Employment pending'); ?></p>
                                        </div>
                                        <div class="review-landscape__kpis">
                                            <div class="review-landscape__kpi">
                                                <span class="review-landscape__kpi-label">Requested</span>
                                                <strong><?php echo displayCurrency($loanAmount); ?></strong>
                                            </div>
                                            <div class="review-landscape__kpi">
                                                <span class="review-landscape__kpi-label"><?php echo htmlspecialchars($paymentLabel); ?></span>
                                                <strong><?php echo displayCurrency($estimatedPayment); ?></strong>
                                            </div>
                                            <div class="review-landscape__kpi">
                                                <span class="review-landscape__kpi-label">Term</span>
                                                <strong><?php echo displayLoanSummaryText($loanTerm); ?></strong>
                                            </div>
                                            <div class="review-landscape__kpi">
                                                <span class="review-landscape__kpi-label">Documents</span>
                                                <strong><?php echo (int)$reviewDocsUploaded; ?> / <?php echo count($uploadedDocuments); ?></strong>
                                            </div>
                                        </div>
                                    </header>

                                    <div class="review-landscape__grid">
                                    <section class="review-landscape__panel">
                                        <div class="review-landscape__panel-head">
                                            <div><i class="fas fa-user-circle text-primary me-2"></i><span class="fw-bold">Personal</span></div>
                                        </div>
                                        <div class="review-landscape__fields">
                                                <?php echo displayLabelValue('Full Name', displayValue($fullName)); ?>
                                                <?php echo displayLabelValue('Date of Birth', displayValue($dob)); ?>
                                                <?php echo displayLabelValue('Gender', displayValue($gender)); ?>
                                                <?php echo displayLabelValue('Civil Status', displayValue($civilStatus)); ?>
                                                <?php echo displayLabelValue('Mobile Number', displayValue($mobileNumber)); ?>
                                                <?php echo displayLabelValue('Email Address', displayValue($email)); ?>
                                                <?php echo displayLabelValue('Complete Address', displayValue($address)); ?>
                                                <?php echo displayLabelValue('Valid ID Type', displayValue($governmentIdType)); ?>
                                                <?php echo displayLabelValue('Valid ID Number', displayValue($governmentIdNumber)); ?>
                                        </div>
                                    </section>
                                    <section class="review-landscape__panel">
                                        <div class="review-landscape__panel-head">
                                            <div><i class="fas <?php echo $step2ReviewIcon; ?> text-primary me-2"></i><span class="fw-bold"><?php echo htmlspecialchars($step2ReviewTitle); ?></span></div>
                                            <a href="<?php echo htmlspecialchars($lwPage, ENT_QUOTES, 'UTF-8'); ?>?step=2&edit=1" class="btn btn-sm btn-link">Edit</a>
                                        </div>
                                        <div class="review-landscape__fields review-landscape__fields--employment">
                                                <div class="row g-2 w-100 m-0">
                                                    <div class="col-12">
                                                        <div class="border rounded-4 p-3 bg-light-subtle">
                                                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                                                <div>
                                                                    <div class="text-secondary small fw-semibold">Employment Status</div>
                                                                    <div class="fw-semibold"><?php echo displayValue($employmentStatus); ?></div>
                                                                </div>
                                                                <?php if ($employmentStatus !== ''): ?>
                                                                    <span class="badge bg-primary-subtle text-primary rounded-pill"><?php echo displayValue($employmentStatus); ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <?php if ($creditHistoryNote !== ''): ?>
                                                        <div class="col-12">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Credit history</div>
                                                                <div class="fw-semibold"><?php echo displayValue($creditHistoryNote); ?></div>
                                                                <div class="small text-muted mt-1">Filled automatically from previous loans and payments in this system.</div>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($employmentStatus === 'Government Employee'): ?>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Government Agency / Office</div>
                                                                <div class="fw-semibold"><?php echo displayValue($agencyOffice); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Position</div>
                                                                <div class="fw-semibold"><?php echo displayValue($position); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Employment Type</div>
                                                                <div class="fw-semibold"><?php echo displayValue($employmentType); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Monthly Salary</div>
                                                                <div class="fw-semibold"><?php echo displayCurrency($monthlySalary); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Years in Service</div>
                                                                <div class="fw-semibold"><?php echo displayValue($yearsInService); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Office Address</div>
                                                                <div class="fw-semibold"><?php echo displayValue($officeAddress); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-12">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Office Contact Number</div>
                                                                <div class="fw-semibold"><?php echo displayValue($officeContact); ?></div>
                                                            </div>
                                                        </div>
                                                    <?php elseif ($employmentStatus === 'Private Employee'): ?>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Company Name</div>
                                                                <div class="fw-semibold"><?php echo displayValue($companyName); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Position</div>
                                                                <div class="fw-semibold"><?php echo displayValue($position); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Employment Type</div>
                                                                <div class="fw-semibold"><?php echo displayValue($employmentType); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Monthly Salary</div>
                                                                <div class="fw-semibold"><?php echo displayCurrency($monthlySalary); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Length of Employment</div>
                                                                <div class="fw-semibold"><?php echo displayValue($employmentLength); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Company Address</div>
                                                                <div class="fw-semibold"><?php echo displayValue($employerAddress); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-12">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Company Contact Number</div>
                                                                <div class="fw-semibold"><?php echo displayValue($businessContact); ?></div>
                                                            </div>
                                                        </div>
                                                    <?php elseif ($employmentStatus === 'Self-Employed / Business Owner'): ?>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Business Name</div>
                                                                <div class="fw-semibold"><?php echo displayValue($businessName); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Nature of Business</div>
                                                                <div class="fw-semibold"><?php echo displayValue($natureOfBusiness); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Monthly Income</div>
                                                                <div class="fw-semibold"><?php echo displayCurrency($monthlyIncome); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Years in Business</div>
                                                                <div class="fw-semibold"><?php echo displayValue($yearsInBusiness); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Business Address</div>
                                                                <div class="fw-semibold"><?php echo displayValue($businessAddress); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Business Contact Number</div>
                                                                <div class="fw-semibold"><?php echo displayValue($businessContact); ?></div>
                                                            </div>
                                                        </div>
                                                    <?php elseif ($employmentStatus === 'OFW'): ?>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Employer Name</div>
                                                                <div class="fw-semibold"><?php echo displayValue($employerName); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Country of Employment</div>
                                                                <div class="fw-semibold"><?php echo displayValue($ofwCountry); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Job Position</div>
                                                                <div class="fw-semibold"><?php echo displayValue($position); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Monthly Income</div>
                                                                <div class="fw-semibold"><?php echo displayCurrency($monthlyIncome); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Years Working Abroad</div>
                                                                <div class="fw-semibold"><?php echo displayValue($yearsAbroad); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Employer Contact Number</div>
                                                                <div class="fw-semibold"><?php echo displayValue($employerContactOfw); ?></div>
                                                            </div>
                                                        </div>
                                                    <?php elseif ($employmentStatus === 'Pensioner'): ?>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Pension Source</div>
                                                                <div class="fw-semibold"><?php echo displayValue($pensionSource); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Monthly Pension</div>
                                                                <div class="fw-semibold"><?php echo displayCurrency($monthlyPension); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-12">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Contact Number</div>
                                                                <div class="fw-semibold"><?php echo displayValue($pensionContact); ?></div>
                                                            </div>
                                                        </div>
                                                    <?php elseif ($employmentStatus === 'Unemployed'): ?>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Source of Income</div>
                                                                <div class="fw-semibold"><?php echo displayValue($occupation); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Estimated Household Income</div>
                                                                <div class="fw-semibold"><?php echo displayCurrency($monthlyIncome); ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-12">
                                                            <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                                <div class="text-secondary small fw-semibold mb-1">Remarks</div>
                                                                <div class="fw-semibold"><?php echo displayValue($remarks); ?></div>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="col-12">
                                                            <div class="text-secondary">No employment details have been provided yet.</div>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                    </section>
                                    <section class="review-landscape__panel review-landscape__panel--loan">
                                        <div class="review-landscape__panel-head">
                                            <div><i class="fas fa-hand-holding-usd text-primary me-2"></i><span class="fw-bold">Loan details</span></div>
                                            <a href="<?php echo htmlspecialchars($lwPage, ENT_QUOTES, 'UTF-8'); ?>?step=3&edit=1" class="btn btn-sm btn-link">Edit</a>
                                        </div>
                                        <div class="review-landscape__loan-stack">
                                            <?php
                                            $loanReviewRows = [
                                                ['Loan type', displayLoanSummaryText($loanType)],
                                                ['Requested amount', displayCurrency($loanAmount)],
                                                ['Loan term', displayLoanSummaryText($loanTerm)],
                                                ['Purpose', displayLoanSummaryText($loanPurpose)],
                                                ['Payment frequency', displayValue($paymentFrequency)],
                                                ['Number of payments', $numberOfPayments > 0 ? htmlspecialchars((string)$numberOfPayments) : 'Not Available'],
                                                [$paymentLabel, displayCurrency($estimatedPayment)],
                                                ['Estimated interest', displayCurrency($estimatedInterest)],
                                                ['Estimated due date', $estimatedDueDate !== '' ? htmlspecialchars($estimatedDueDate) : 'Not Available'],
                                            ];
                                            foreach ($loanReviewRows as $loanRow):
                                                [$loanLabel, $loanValue] = $loanRow;
                                            ?>
                                            <div class="review-landscape__loan-row">
                                                <span class="review-landscape__loan-label"><?php echo htmlspecialchars($loanLabel); ?></span>
                                                <span class="review-landscape__loan-value"><?php echo $loanValue; ?></span>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </section>
                                    <section class="review-landscape__panel">
                                        <div class="review-landscape__panel-head">
                                            <div><i class="fas fa-file-alt text-primary me-2"></i><span class="fw-bold">Requirements</span></div>
                                            <a href="<?php echo htmlspecialchars($lwPage, ENT_QUOTES, 'UTF-8'); ?>?step=4&edit=1" class="btn btn-sm btn-link">Edit</a>
                                        </div>
                                        <ul class="review-landscape__doc-list">
                                                <?php foreach ($uploadedDocuments as $document): ?>
                                                    <?php $isUploaded = in_array(trim((string)($document['status'] ?? '')), ['Uploaded', 'Pending Review'], true); ?>
                                                    <li class="review-landscape__doc-item">
                                                        <span class="review-landscape__doc-name"><?php echo htmlspecialchars($document['label']); ?></span>
                                                        <span class="review-landscape__doc-status <?php echo $isUploaded ? 'is-ok' : 'is-missing'; ?>"><?php echo $isUploaded ? 'Uploaded' : 'Missing'; ?></span>
                                                    </li>
                                                <?php endforeach; ?>
                                        </ul>
                                    </section>
                                    </div>
                                </article>
                                <div class="loan-assessment-card-wrap mt-3">
                                    <?php echo renderLoanEligibilityAssessmentHtml($loanEligibilityPreview); ?>
                                </div>
                                <div class="review-landscape__declare form-check mt-0 p-3">
                                    <input class="form-check-input" type="checkbox" id="declaration" name="declaration" value="1" <?php echo $declaration ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="declaration">I certify that all information and uploaded documents are true, authentic, and complete. I understand that providing false information may result in the rejection of my loan application.</label>
                                </div>
                            </div>
                        </div>
                <?php endif; ?>

                <div class="loan-wizard-footer">
                    <?php if ($editMode): ?>
                        <a href="<?php echo htmlspecialchars($lwPage, ENT_QUOTES, 'UTF-8'); ?>?step=5" class="btn btn-outline-secondary"><i class="fas fa-times me-1"></i> Cancel</a>
                        <button type="submit" name="form_action" value="save" class="btn btn-primary ms-auto">Save changes</button>
                    <?php else: ?>
                        <?php if ($step > 1): ?>
                            <a href="<?php echo htmlspecialchars($lwPage, ENT_QUOTES, 'UTF-8'); ?>?step=<?php echo max(1, $step - 1); ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i> Previous</a>
                        <?php else: ?>
                            <span class="small text-muted">Complete each step to continue</span>
                        <?php endif; ?>
                        <?php if ($step < 5): ?>
                            <button type="submit" class="btn btn-primary ms-auto">Continue <i class="fas fa-arrow-right ms-1"></i></button>
                        <?php else: ?>
                            <button type="submit" name="form_action" value="submit" class="btn btn-primary ms-auto" id="submitButton" disabled><i class="fas fa-paper-plane me-1"></i> <?php echo htmlspecialchars($loanWizardConfig['submit_label'] ?? 'Submit application'); ?></button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <div class="modal fade" id="missingDocumentsModal" tabindex="-1" aria-labelledby="missingDocumentsModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="missingDocumentsModalTitle">Required documents missing</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Upload every required file before continuing to the next step. You are still missing:</p>
                    <ul class="mb-0" id="missingDocumentsList">
                        <?php foreach ($documentErrorModalLabels as $missingDocumentLabel): ?>
                            <li><?php echo htmlspecialchars($missingDocumentLabel); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Okay</button>
                </div>
            </div>
        </div>
    </div>

<?php include 'includes/nav_footer_lending.php'; ?>
    <script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="assets/pwa.js" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const employmentStatus = document.getElementById('employmentStatus');
            const sections = document.querySelectorAll('.dynamic-section');
            const declarationCheckbox = document.getElementById('declaration');
            const submitButton = document.getElementById('submitButton');

            const employmentDetails = document.getElementById('employmentDetails');

            function updateSectionVisibility() {
                const selected = employmentStatus ? employmentStatus.value : '';
                sections.forEach(section => {
                    const isActive = section.getAttribute('data-status') === selected;
                    section.classList.toggle('active', isActive);
                    section.setAttribute('aria-hidden', isActive ? 'false' : 'true');
                    const inputs = section.querySelectorAll('input, select, textarea');
                    inputs.forEach(field => {
                        field.disabled = !isActive;
                    });
                });
                if (employmentDetails) {
                    employmentDetails.classList.toggle('is-empty', selected === '');
                }
            }

            const monthlyGrossIncomeInput = document.getElementById('monthlyGrossIncomeInput');

            function readActiveEmploymentIncome() {
                const activeSection = document.querySelector('.dynamic-field-group.active');
                if (!activeSection) {
                    return '';
                }
                const incomeField = activeSection.querySelector('[name="monthly_income"]:not([disabled])');
                const salaryField = activeSection.querySelector('[name="monthly_salary"]:not([disabled])');
                const pensionField = activeSection.querySelector('[name="monthly_pension"]:not([disabled])');
                if (incomeField && incomeField.value !== '') {
                    return incomeField.value;
                }
                if (salaryField && salaryField.value !== '') {
                    return salaryField.value;
                }
                if (pensionField && pensionField.value !== '') {
                    return pensionField.value;
                }
                return '';
            }

            function syncMonthlyGrossFromEmployment(force) {
                if (!monthlyGrossIncomeInput) {
                    return;
                }
                const autoSynced = monthlyGrossIncomeInput.dataset.autoSynced !== '0';
                if (!force && !autoSynced) {
                    return;
                }
                const employmentIncome = readActiveEmploymentIncome();
                if (employmentIncome === '') {
                    return;
                }
                monthlyGrossIncomeInput.value = employmentIncome;
                monthlyGrossIncomeInput.dataset.autoSynced = '1';
            }

            const step2DtiEstimate = document.getElementById('step2DtiEstimate');
            const monthlyDebtPaymentsInput = document.getElementById('monthlyDebtPaymentsInput');
            const otherMonthlyObligationsInput = document.getElementById('otherMonthlyObligationsInput');

            function readMoneyInput(field) {
                if (!field || field.disabled) {
                    return 0;
                }
                const amount = parseFloat(field.value);
                return Number.isFinite(amount) && amount > 0 ? amount : 0;
            }

            function formatEstimateMoney(amount) {
                return '₱' + amount.toLocaleString('en-PH', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }

            function refreshStep2DtiEstimate() {
                if (!step2DtiEstimate) {
                    return;
                }
                let income = readMoneyInput(monthlyGrossIncomeInput);
                if (income <= 0) {
                    const employmentIncome = parseFloat(readActiveEmploymentIncome());
                    income = Number.isFinite(employmentIncome) && employmentIncome > 0 ? employmentIncome : 0;
                }
                if (income <= 0) {
                    step2DtiEstimate.classList.add('d-none');
                    return;
                }

                const declaredDebt = readMoneyInput(monthlyDebtPaymentsInput) + readMoneyInput(otherMonthlyObligationsInput);
                const systemDebt = parseFloat(step2DtiEstimate.dataset.systemDebt);
                const totalDebt = declaredDebt + (Number.isFinite(systemDebt) && systemDebt > 0 ? systemDebt : 0);
                const maxDti = parseFloat(step2DtiEstimate.dataset.maxDti);
                const limit = Number.isFinite(maxDti) && maxDti > 0 ? maxDti : 40;
                const dtiBefore = (totalDebt / income) * 100;
                const room = Math.max(0, (income * (limit / 100)) - totalDebt);
                const blocked = dtiBefore > limit + 0.001 || room <= 0.009;

                const percentNode = document.getElementById('step2DtiPercent');
                const limitNode = document.getElementById('step2DtiLimit');
                const roomNode = document.getElementById('step2DtiRoom');
                const blockNode = document.getElementById('step2DtiBlock');
                if (percentNode) {
                    percentNode.textContent = dtiBefore.toFixed(1);
                }
                if (limitNode) {
                    limitNode.textContent = limit.toFixed(1);
                }
                if (roomNode) {
                    roomNode.textContent = formatEstimateMoney(room);
                }
                if (blockNode) {
                    blockNode.classList.toggle('d-none', !blocked);
                }
                step2DtiEstimate.classList.toggle('alert-warning', blocked);
                step2DtiEstimate.classList.toggle('alert-info', !blocked);
                step2DtiEstimate.classList.remove('d-none');
            }

            if (monthlyGrossIncomeInput) {
                monthlyGrossIncomeInput.addEventListener('input', function () {
                    monthlyGrossIncomeInput.dataset.autoSynced = '0';
                    refreshStep2DtiEstimate();
                });
            }

            if (employmentStatus) {
                employmentStatus.addEventListener('change', function () {
                    updateSectionVisibility();
                    syncMonthlyGrossFromEmployment(true);
                    refreshStep2DtiEstimate();
                });
                updateSectionVisibility();
                syncMonthlyGrossFromEmployment(monthlyGrossIncomeInput ? monthlyGrossIncomeInput.value === '' : true);
            }

            const adminClientSelect = document.getElementById('adminLoanClientSelect');
            const adminClientActiveLoanAlert = document.getElementById('adminClientActiveLoanAlert');
            const wizardContinueButton = document.querySelector('.loan-wizard-footer button[type="submit"]');
            const wizardStepInput = document.querySelector('input[name="step"]');

            function updateAdminClientActiveLoanState() {
                if (!adminClientSelect || !adminClientActiveLoanAlert) {
                    return;
                }
                const selectedOption = adminClientSelect.options[adminClientSelect.selectedIndex];
                const isBlocked = selectedOption && selectedOption.dataset.canApply === '0';
                adminClientActiveLoanAlert.classList.toggle('d-none', !isBlocked);
                if (wizardContinueButton && wizardStepInput && wizardStepInput.value === '1') {
                    wizardContinueButton.disabled = isBlocked;
                }
            }

            if (adminClientSelect) {
                adminClientSelect.addEventListener('change', function () {
                    updateAdminClientActiveLoanState();
                    const clientId = adminClientSelect.value;
                    const baseUrl = <?php echo json_encode($lwPage, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                    const params = new URLSearchParams(window.location.search);
                    params.set('step', '1');
                    if (clientId) {
                        params.set('client', clientId);
                    } else {
                        params.delete('client');
                        params.set('client', '0');
                    }
                    window.location.href = baseUrl + '?' + params.toString();
                });
                updateAdminClientActiveLoanState();
            }

            document.querySelectorAll('.js-employment-income-field').forEach(function (field) {
                field.addEventListener('input', function () {
                    syncMonthlyGrossFromEmployment(true);
                    refreshStep2DtiEstimate();
                });
            });

            [monthlyDebtPaymentsInput, otherMonthlyObligationsInput].forEach(function (field) {
                if (!field) {
                    return;
                }
                field.addEventListener('input', refreshStep2DtiEstimate);
            });
            refreshStep2DtiEstimate();

            if (declarationCheckbox && submitButton) {
                declarationCheckbox.addEventListener('change', function () {
                    submitButton.disabled = !this.checked;
                });
                submitButton.disabled = !declarationCheckbox.checked;
            }

            const loanAmountInput = document.getElementById('loanAmountInput');
            const loanTermInput = document.getElementById('loanTermInput');
            const paymentFrequencyInput = document.getElementById('paymentFrequencyInput');
            const requestedAmountValue = document.getElementById('requestedAmountValue');
            const estimatedPaymentTitle = document.getElementById('estimatedPaymentTitle');
            const estimatedPaymentValue = document.getElementById('estimatedPaymentValue');
            const estimatedInterestValue = document.getElementById('estimatedInterestValue');

            function formatPeso(value) {
                return new Intl.NumberFormat('en-PH', {
                    style: 'currency',
                    currency: 'PHP',
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }).format(value);
            }

            function parseTermMonths(termValue) {
                if (typeof termValue !== 'string') {
                    return 0;
                }
                const match = termValue.match(/^(\d+)/);
                return match ? parseInt(match[1], 10) : 0;
            }

            function getPaymentSchedule(paymentFrequency, loanTermMonths, totalRepayment) {
                let numberOfPayments = 0;
                let paymentLabel = 'Estimated Monthly Payment';

                switch (paymentFrequency) {
                    case 'Daily':
                        numberOfPayments = Math.max(1, loanTermMonths * 30);
                        paymentLabel = 'Estimated Daily Payment';
                        break;
                    case 'Weekly':
                        numberOfPayments = Math.max(1, loanTermMonths * 4);
                        paymentLabel = 'Estimated Weekly Payment';
                        break;
                    case 'Every Half of the Month':
                        numberOfPayments = Math.max(1, loanTermMonths * 2);
                        paymentLabel = 'Estimated Semi-Monthly Payment';
                        break;
                    case 'Monthly':
                    default:
                        numberOfPayments = Math.max(1, loanTermMonths);
                        paymentLabel = 'Estimated Monthly Payment';
                        break;
                }

                const estimatedPayment = numberOfPayments > 0 ? totalRepayment / numberOfPayments : 0;
                return { numberOfPayments, estimatedPayment, paymentLabel };
            }

            function updateLoanEstimates() {
                const loanAmount = parseFloat(loanAmountInput ? loanAmountInput.value : 0) || 0;
                const loanTermMonths = parseTermMonths(loanTermInput ? loanTermInput.value : '');
                const paymentFrequency = paymentFrequencyInput ? paymentFrequencyInput.value : 'Monthly';
                const monthlyInterest = loanAmount > 0 && loanTermMonths > 0 ? loanAmount * 0.05 : 0;
                const totalInterest = monthlyInterest * loanTermMonths;
                const totalRepayment = loanAmount + totalInterest;
                const schedule = getPaymentSchedule(paymentFrequency, loanTermMonths, totalRepayment);

                if (requestedAmountValue) {
                    requestedAmountValue.textContent = formatPeso(loanAmount);
                }
                if (estimatedInterestValue) {
                    estimatedInterestValue.textContent = formatPeso(loanAmount > 0 && loanTermMonths > 0 ? totalInterest : 0);
                }
                if (estimatedPaymentTitle) {
                    estimatedPaymentTitle.textContent = schedule.paymentLabel;
                }
                if (estimatedPaymentValue) {
                    estimatedPaymentValue.textContent = formatPeso(loanAmount > 0 && loanTermMonths > 0 ? schedule.estimatedPayment : 0);
                }
            }

            if (loanAmountInput) {
                loanAmountInput.addEventListener('input', updateLoanEstimates);
            }
            if (loanTermInput) {
                loanTermInput.addEventListener('change', updateLoanEstimates);
            }
            if (paymentFrequencyInput) {
                paymentFrequencyInput.addEventListener('change', updateLoanEstimates);
            }
            updateLoanEstimates();

            const missingDocumentsModalEl = document.getElementById('missingDocumentsModal');
            const missingDocumentsList = document.getElementById('missingDocumentsList');
            const serverMissingDocuments = <?php echo json_encode(array_values($documentErrorModalLabels), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

            function showMissingDocumentsModal(labels) {
                if (!missingDocumentsModalEl || !missingDocumentsList || !labels.length) {
                    return;
                }
                missingDocumentsList.innerHTML = '';
                labels.forEach(function (label) {
                    const item = document.createElement('li');
                    item.textContent = label;
                    missingDocumentsList.appendChild(item);
                });
                bootstrap.Modal.getOrCreateInstance(missingDocumentsModalEl).show();
            }

            document.querySelectorAll('.loan-upload-tile input[type="file"]').forEach(function (input) {
                input.addEventListener('change', function () {
                    const tile = input.closest('.loan-upload-tile');
                    if (!tile) {
                        return;
                    }
                    const status = tile.querySelector('.loan-upload-tile__status');
                    const icon = tile.querySelector('.loan-upload-tile__icon i');
                    const chosen = input.files && input.files.length > 0;
                    const persisted = tile.dataset.persisted === '1';
                    if (chosen) {
                        tile.classList.add('is-uploaded');
                        if (status) {
                            status.classList.add('loan-upload-tile__status--ok');
                            status.textContent = '';
                            const mark = document.createElement('i');
                            mark.className = 'fas fa-circle-check me-1';
                            status.appendChild(mark);
                            status.appendChild(document.createTextNode(' Selected: ' + input.files[0].name));
                        }
                        if (icon) {
                            icon.className = 'fas fa-circle-check';
                        }
                    } else if (!persisted) {
                        tile.classList.remove('is-uploaded');
                        if (status) {
                            status.classList.remove('loan-upload-tile__status--ok');
                            status.textContent = '';
                            const mark = document.createElement('i');
                            mark.className = 'fas fa-circle me-1';
                            status.appendChild(mark);
                            status.appendChild(document.createTextNode(' Not uploaded yet'));
                        }
                        if (icon) {
                            icon.className = 'fas fa-file-arrow-up';
                        }
                    }
                });
            });

            const loanWizardForm = document.querySelector('form.loan-wizard-layout');
            if (loanWizardForm) {
                loanWizardForm.addEventListener('submit', function (event) {
                    const stepInput = loanWizardForm.querySelector('input[name="step"]');
                    if (!stepInput || stepInput.value !== '4') {
                        return;
                    }
                    const missing = [];
                    loanWizardForm.querySelectorAll('.loan-upload-tile[data-required="1"]').forEach(function (tile) {
                        const input = tile.querySelector('input[type="file"]');
                        const persisted = tile.dataset.persisted === '1';
                        const chosen = input && input.files && input.files.length > 0;
                        if (!persisted && !chosen) {
                            missing.push(tile.dataset.label || 'Document');
                        }
                    });
                    if (missing.length > 0) {
                        event.preventDefault();
                        showMissingDocumentsModal(missing);
                    }
                });
            }

            if (serverMissingDocuments.length > 0) {
                showMissingDocumentsModal(serverMissingDocuments);
            }

            <?php if ($submitted): ?>
                var submittedModalEl = document.getElementById('applicationSubmittedModal');
                if (submittedModalEl) {
                    var submittedModal = new bootstrap.Modal(submittedModalEl);
                    submittedModal.show();
                }
            <?php endif; ?>
        });
    </script>
</body>
</html>
