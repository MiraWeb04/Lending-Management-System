<?php
/**
 * Borrower Loan Application Page for RJ and RR Finance Services
 */

require_once 'includes/auth_lending.php';
require_once 'includes/db_lending.php';
require_once 'includes/loan_helpers.php';
ensureLoanApplicationSchema();

if (!isLoggedIn()) {
    header('Location: borrower_login_lending.php');
    exit;
}

if (!isBorrower()) {
    header('Location: ' . getDashboardRedirectUrl(getCurrentUser()));
    exit;
}

$user = getCurrentUser();

$borrowerProfileStmt = executeQuery('SELECT * FROM users WHERE user_id = ? LIMIT 1', [$user['user_id']]);
$borrowerProfile = $borrowerProfileStmt ? $borrowerProfileStmt->fetch(PDO::FETCH_ASSOC) : false;
$borrowerApplicationStmt = executeQuery('SELECT * FROM borrower_applications WHERE user_id = ? LIMIT 1', [$user['user_id']]);
$borrowerApplication = $borrowerApplicationStmt ? ($borrowerApplicationStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

if (!$borrowerProfile || strcasecmp((string)($borrowerProfile['status'] ?? ''), 'Active') !== 0) {
    header('Location: borrower_dashboard_lending.php');
    exit;
}

$step = max(1, min(5, (int)($_GET['step'] ?? 1)));
$editMode = isset($_GET['edit']) || isset($_POST['edit_mode']);
$submitted = false;
$successMessage = '';
$error = '';
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
$estimatedDueDate = '';
$declaration = false;
$paymentFrequency = 'Monthly';
$numberOfPayments = 0;
$estimatedPayment = 0.0;
$estimatedMonthlyPayment = 0;
$estimatedInterest = 0;
$estimatedDueDate = '';
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
$step1Summary = $_SESSION['loan_application_step1'] ?? [];
$step2Summary = $_SESSION['loan_application_step2'] ?? [];
$step3Summary = $_SESSION['loan_application_step3'] ?? [];
$step4Summary = $_SESSION['loan_application_step4'] ?? [];
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
$uploadedDocuments = $step4Summary ?: [
    ['label' => 'Valid Government ID', 'name' => '', 'status' => 'Not Uploaded'],
    ['label' => 'Proof of Income', 'name' => '', 'status' => 'Not Uploaded'],
    ['label' => 'Proof of Billing', 'name' => '', 'status' => 'Not Uploaded'],
    ['label' => 'Selfie Holding Valid ID', 'name' => '', 'status' => 'Not Uploaded'],
    ['label' => 'Additional Supporting Documents', 'name' => '', 'status' => 'Not Uploaded'],
];

if (isset($_GET['submitted']) || !empty($_SESSION['loan_application_submitted'])) {
    $submitted = true;
    unset($_SESSION['loan_application_submitted']);
}

function displayValue($value) {
    $value = trim((string)$value);
    return $value !== '' ? htmlspecialchars($value) : 'Not Provided';
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
    $monthlyInterestRate = 0.05;
    $monthlyInterest = $loanAmount * $monthlyInterestRate;
    $estimatedInterest = $loanTermMonths > 0 ? $monthlyInterest * $loanTermMonths : 0.0;
    $estimatedMonthlyPayment = $loanTermMonths > 0 ? ($loanAmount + $estimatedInterest) / $loanTermMonths : 0.0;

    return [
        'monthly_interest_rate' => $monthlyInterestRate,
        'estimated_interest' => $estimatedInterest,
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

        switch ($normalizedFrequency) {
            case 'daily':
                $dueDate->modify('+' . $numberOfPayments . ' days');
                break;
            case 'weekly':
                $dueDate->modify('+' . ($numberOfPayments * 7) . ' days');
                break;
            case 'bi-weekly':
                $dueDate->modify('+' . ($numberOfPayments * 14) . ' days');
                break;
            case 'semi-monthly':
                $dueDate->modify('+' . ($numberOfPayments * 15) . ' days');
                break;
            case 'monthly':
            default:
                $dueDate->modify('+'.$loanTermMonths.' months');
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
    $storedDocuments = $_SESSION['loan_application_step4'] ?? [];
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
        $_SESSION['loan_application_step1'] = [
            'address' => $address,
            'mobile_number' => $mobileNumber,
        ];
    }

    if ($step === 2 || $employmentStatus !== '' || $companyName !== '' || $businessName !== '') {
        $_SESSION['loan_application_step2'] = [
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
            'employer_name' => $employerName,
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
        ];
    }

    if ($step === 3 || $loanType !== '' || $loanAmount !== '' || $loanTerm !== '' || $loanPurpose !== '') {
        $_SESSION['loan_application_step3'] = [
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
    }

    $documentLabels = ['Valid Government ID','Proof of Income','Proof of Billing','Selfie Holding Valid ID','Additional Supporting Documents'];
    if (!empty($_FILES['documents']['name']) && is_array($_FILES['documents']['name'])) {
        $step4Documents = [];
        $uploadDir = __DIR__ . '/uploads/loan_documents';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        foreach ($documentLabels as $index => $label) {
            $uploadedName = trim((string)($_FILES['documents']['name'][$index] ?? ''));
            $tmpName = trim((string)($_FILES['documents']['tmp_name'][$index] ?? ''));
            $existingPath = trim((string)($step4Summary[$index]['path'] ?? ''));
            $documentPath = $existingPath !== '' ? $existingPath : null;
            $hasUpload = $uploadedName !== '' && is_uploaded_file($tmpName);
            $status = $hasUpload ? 'Pending Review' : ($step4Summary[$index]['status'] ?? 'Not Uploaded');
            $name = $hasUpload ? $uploadedName : ($step4Summary[$index]['name'] ?? '');

            if ($hasUpload) {
                $destinationFileName = time() . '_' . bin2hex(random_bytes(8)) . '_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', basename($uploadedName));
                $destinationPath = $uploadDir . '/' . $destinationFileName;
                if (move_uploaded_file($tmpName, $destinationPath)) {
                    $documentPath = 'uploads/loan_documents/' . $destinationFileName;
                    $status = 'Pending Review';
                }
            }

            $step4Documents[] = [
                'label' => $label,
                'name' => $name,
                'status' => $status,
                'path' => $documentPath,
            ];
        }
        $_SESSION['loan_application_step4'] = $step4Documents;
        $uploadedDocuments = $step4Documents;
    }

    if ($step === 5 && $formAction === 'submit') {
        if (!$declaration) {
            $step = 5;
            $error = 'Please confirm the declaration before submitting your loan application.';
        } else {
            $step1Summary = $_SESSION['loan_application_step1'] ?? [];
            $step2Summary = $_SESSION['loan_application_step2'] ?? [];
            $step3Summary = $_SESSION['loan_application_step3'] ?? [];
            $step4Summary = $_SESSION['loan_application_step4'] ?? [];

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

            $columns = array_keys($applicationData);
            $placeholders = implode(', ', array_fill(0, count($applicationData), '?'));
            $stmt = $conn->prepare('INSERT INTO loan_applications (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')');
            $stmt->execute(array_values($applicationData));

            $applicationId = (int)$conn->lastInsertId();
            if ($applicationId > 0) {
                storeLoanApplicationDocuments($applicationId);
            }

            notifyAdmins('New Loan Application', "{$fullName} submitted a new loan application requiring review.");

            unset($_SESSION['loan_application_step1'], $_SESSION['loan_application_step2'], $_SESSION['loan_application_step3'], $_SESSION['loan_application_step4']);

            $_SESSION['loan_application_submitted'] = true;
            header('Location: borrower_loan_application_lending.php?submitted=1');
            exit;
        }
    } elseif ($editMode) {
        $step1Summary = $_SESSION['loan_application_step1'] ?? [];
        $step2Summary = $_SESSION['loan_application_step2'] ?? [];
        $step3Summary = $_SESSION['loan_application_step3'] ?? [];
        $step4Summary = $_SESSION['loan_application_step4'] ?? [];

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

        $columns = array_keys($applicationData);
        $placeholders = implode(', ', array_fill(0, count($applicationData), '?'));
        $stmt = $conn->prepare('INSERT INTO loan_applications (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')');
        $stmt->execute(array_values($applicationData));

        $applicationId = (int)$conn->lastInsertId();
        if ($applicationId > 0) {
            storeLoanApplicationDocuments($applicationId);
        }

        notifyAdmins('New Loan Application', "{$fullName} submitted a new loan application requiring review.");

        unset($_SESSION['loan_application_step1'], $_SESSION['loan_application_step2'], $_SESSION['loan_application_step3'], $_SESSION['loan_application_step4']);

        $_SESSION['loan_application_submitted'] = true;
        header('Location: borrower_loan_application_lending.php?submitted=1');
        exit;
    } elseif ($editMode) {
        $step = 5;
        $editMode = false;
        $successMessage = 'Your changes were saved. Review the updated section below.';
    } elseif ($step < 5) {
        $step++;
    }
}

$step2Summary = $_SESSION['loan_application_step2'] ?? [];
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
$step3Summary = $_SESSION['loan_application_step3'] ?? [];
$loanType = trim((string)($step3Summary['loan_type'] ?? $loanType));
$loanAmount = trim((string)($step3Summary['loan_amount'] ?? $loanAmount));
$loanTerm = trim((string)($step3Summary['loan_term'] ?? $loanTerm));
$loanPurpose = trim((string)($step3Summary['loan_purpose'] ?? $loanPurpose));
$paymentFrequency = trim((string)($step3Summary['payment_frequency'] ?? $paymentFrequency));
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Application - RJ and RR Finance Services</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/lending_styles.css">
    <style>
        .dynamic-section {
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            transition: max-height 0.35s ease, opacity 0.35s ease, transform 0.35s ease;
            transform: translateY(-10px);
            pointer-events: none;
        }
        .dynamic-section.active {
            max-height: 2000px;
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }
        .dynamic-field-group {
            border: 1px solid rgba(21, 91, 217, 0.12);
            border-radius: 1rem;
            padding: 1rem 1.15rem;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(28, 45, 72, 0.05);
        }
        .dynamic-field-group .form-label {
            font-weight: 600;
        }
        .dynamic-field-group .required-star {
            color: var(--danger);
            margin-left: 2px;
        }
        .employment-helper-text {
            font-size: 0.92rem;
            color: var(--text-secondary);
            margin-bottom: 0.75rem;
        }
        #employmentDetails {
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <?php include 'includes/nav_lending.php'; ?>

    <div class="container-fluid py-4">
        <div class="page-hero collector-hero">
            <div class="d-flex align-items-center gap-3">
                <div class="collector-hero__logo">
                    <img src="images/logo.png" alt="RJ and RR Finance Services logo">
                </div>
                <div>
                    <h1 class="page-title"><i class="fas fa-file-signature me-2"></i>Loan Application</h1>
                    <p class="page-subtitle">Apply for financing with a secure and streamlined online experience.</p>
                </div>
            </div>
        </div>

        <?php if ($submitted): ?>
            <div class="card shadow-sm border-0">
                <div class="card-body text-center py-5">
                    <div class="login-logo mx-auto mb-3">
                        <img src="images/logo.png" alt="RJ and RR Finance Services logo">
                    </div>
                    <h3 class="fw-bold text-primary mb-2">Loan Application Submitted Successfully</h3>
                    <p class="text-muted mb-4">Your application has been received by RJ and RR Finance Services. Our loan officers will review your application.</p>
                    <div class="alert alert-success rounded-4">You will receive a notification once your application has been reviewed.</div>
                    <div class="d-flex justify-content-center gap-2 flex-wrap mt-3">
                        <a href="borrower_loan_status_lending.php" class="btn btn-primary">View Loan Status</a>
                        <a href="borrower_dashboard_lending.php" class="btn btn-outline-primary rounded-pill">Return to Dashboard</a>
                    </div>
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
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                        <div>
                            <h5 class="fw-bold mb-1">Application Progress</h5>
                            <p class="text-muted mb-0">Step <?php echo $step; ?> of 5</p>
                        </div>
                        <div class="text-primary fw-semibold"><?php echo [
                            1 => 'Personal Information',
                            2 => 'Employment Information',
                            3 => 'Loan Information',
                            4 => 'Requirements',
                            5 => 'Review & Submit'
                        ][$step]; ?></div>
                    </div>
                    <div class="progress" style="height: 10px;">
                        <div class="progress-bar" role="progressbar" style="width: <?php echo ($step / 5) * 100; ?>%"></div>
                    </div>
                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger rounded-4 mt-3 mb-0">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($successMessage !== ''): ?>
                        <div class="alert alert-success rounded-4 mt-3 mb-0">
                            <?php echo htmlspecialchars($successMessage); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <form method="post" enctype="multipart/form-data" class="row g-4">
                <input type="hidden" name="step" value="<?php echo $step; ?>">
                <?php if ($editMode): ?>
                    <input type="hidden" name="edit_mode" value="1">
                <?php endif; ?>
                <?php if ($step === 1): ?>
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white py-3">
                                <h6 class="m-0 fw-bold"><i class="fas fa-user me-2"></i>Step 1 – Personal Information</h6>
                            </div>
                            <div class="card-body">
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
                    </div>
                <?php elseif ($step === 2): ?>
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white py-3">
                                <h6 class="m-0 fw-bold"><i class="fas fa-briefcase me-2"></i>Step 2 – Employment Information</h6>
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
                                        <p class="employment-helper-text mt-2">Select your current employment type to display the appropriate information.</p>
                                    </div>
                                </div>

                                <div id="employmentDetails" class="mt-3">
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
                                                <input type="number" class="form-control rounded-3" name="monthly_salary" min="0" step="100" value="<?php echo htmlspecialchars($monthlySalary); ?>" placeholder="0.00">
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
                                                <input type="number" class="form-control rounded-3" name="monthly_salary" min="0" step="100" value="<?php echo htmlspecialchars($monthlySalary); ?>" placeholder="0.00">
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
                                                <input type="number" class="form-control rounded-3" name="monthly_income" min="0" step="100" value="<?php echo htmlspecialchars($monthlyIncome); ?>" placeholder="0.00">
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
                                                <input type="number" class="form-control rounded-3" name="monthly_income" min="0" step="100" value="<?php echo htmlspecialchars($monthlyIncome); ?>" placeholder="0.00">
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
                                                <input type="number" class="form-control rounded-3" name="monthly_pension" min="0" step="100" value="<?php echo htmlspecialchars($monthlyPension); ?>" placeholder="0.00">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Contact Number <span class="required-star">*</span></label>
                                                <input type="text" class="form-control rounded-3" name="pension_contact" value="<?php echo htmlspecialchars($pensionContact); ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php elseif ($step === 3): ?>
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white py-3">
                                <h6 class="m-0 fw-bold"><i class="fas fa-hand-holding-usd me-2"></i>Step 3 – Loan Information</h6>
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
                                <div class="row g-3 mt-3">
                                    <div class="col-md-4">
                                        <div class="consent-card h-100">
                                            <div class="text-muted small">Requested Amount</div>
                                            <div id="requestedAmountValue" class="fw-semibold">₱<?php echo number_format((float)($loanAmount ?? 0), 2); ?></div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="consent-card h-100">
                                            <div id="estimatedPaymentTitle" class="text-muted small"><?php echo htmlspecialchars($paymentLabel); ?></div>
                                            <div id="estimatedPaymentValue" class="fw-semibold">₱<?php echo number_format((float)$estimatedPayment, 2); ?></div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="consent-card h-100">
                                            <div class="text-muted small">Estimated Interest</div>
                                            <div id="estimatedInterestValue" class="fw-semibold">₱<?php echo number_format((float)$estimatedInterest, 2); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-3 text-muted small">Estimated values are based on a fixed 5% monthly interest rate and are for reference only. Final loan terms are subject to approval.</div>
                            </div>
                        </div>
                    </div>
                <?php elseif ($step === 4): ?>
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white py-3">
                                <h6 class="m-0 fw-bold"><i class="fas fa-cloud-upload-alt me-2"></i>Step 4 – Requirements</h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <?php foreach (['Valid Government ID','Proof of Income','Proof of Billing','Selfie Holding Valid ID','Additional Supporting Documents'] as $doc): ?>
                                        <div class="col-md-6">
                                            <div class="consent-card h-100">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($doc); ?></div>
                                                    <i class="fas fa-file-alt text-primary"></i>
                                                </div>
                                                <input type="file" class="form-control" name="documents[]" accept=".jpg,.jpeg,.png,.pdf">
                                                <div class="small text-muted mt-2">Accepted formats: JPG, PNG, PDF</div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                            </div>
                        </div>
                    </div>
                <?php elseif ($step === 5): ?>
                    <div class="col-12">
                        <div class="card shadow-sm h-100">
                            <div class="card-header bg-white py-3">
                                <h6 class="m-0 fw-bold"><i class="fas fa-clipboard-check me-2"></i>Step 5 – Review & Submit</h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-4">
                                    <div class="col-lg-6">
                                        <div class="card border-0 shadow-sm h-100">
                                            <div class="card-body d-flex flex-column">
                                                <div class="d-flex align-items-center justify-content-between mb-3">
                                                    <div class="d-flex align-items-center"><i class="fas fa-user-circle fa-lg text-primary me-2"></i><span class="fw-semibold">Personal Information</span></div>
                                                    <a href="borrower_loan_application_lending.php?step=1&edit=1" class="btn btn-sm btn-outline-primary">✏ Edit</a>
                                                </div>
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
                                        </div>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="card border-0 shadow-sm h-100">
                                            <div class="card-body d-flex flex-column">
                                                <div class="d-flex align-items-center justify-content-between mb-3">
                                                    <div class="d-flex align-items-center">
                                                        <i class="fas <?php echo $step2ReviewIcon; ?> fa-lg text-primary me-2"></i>
                                                        <span class="fw-semibold"><?php echo $step2ReviewTitle; ?></span>
                                                    </div>
                                                    <a href="borrower_loan_application_lending.php?step=2&edit=1" class="btn btn-sm btn-outline-primary">✏ Edit</a>
                                                </div>
                                                <div class="row g-3">
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
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="card border-0 shadow-sm h-100">
                                            <div class="card-body d-flex flex-column">
                                                <div class="d-flex align-items-center justify-content-between mb-3">
                                                    <div class="d-flex align-items-center"><i class="fas fa-hand-holding-usd fa-lg text-primary me-2"></i><span class="fw-semibold">Loan Details</span></div>
                                                    <a href="borrower_loan_application_lending.php?step=3&edit=1" class="btn btn-sm btn-outline-primary">✏ Edit</a>
                                                </div>
                                                <div class="row g-3">
                                                    <div class="col-md-6">
                                                        <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                                <i class="fas fa-file-invoice-dollar text-primary"></i>
                                                                <span class="text-secondary small fw-semibold">Loan Type</span>
                                                            </div>
                                                            <div class="fw-semibold"><?php echo displayLoanSummaryText($loanType); ?></div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                                <i class="fas fa-money-bill-wave text-primary"></i>
                                                                <span class="text-secondary small fw-semibold">Requested Amount</span>
                                                            </div>
                                                            <div class="fw-semibold"><?php echo displayCurrency($loanAmount); ?></div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                                <i class="fas fa-calendar-alt text-primary"></i>
                                                                <span class="text-secondary small fw-semibold">Loan Term</span>
                                                            </div>
                                                            <div class="fw-semibold"><?php echo displayLoanSummaryText($loanTerm); ?></div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                                <i class="fas fa-bullseye text-primary"></i>
                                                                <span class="text-secondary small fw-semibold">Purpose</span>
                                                            </div>
                                                            <div class="fw-semibold"><?php echo displayLoanSummaryText($loanPurpose); ?></div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                                <i class="fas fa-calendar-alt text-primary"></i>
                                                                <span class="text-secondary small fw-semibold">Payment Frequency</span>
                                                            </div>
                                                            <div class="fw-semibold"><?php echo displayValue($paymentFrequency); ?></div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                                <i class="fas fa-list-ol text-primary"></i>
                                                                <span class="text-secondary small fw-semibold">Number of Payments</span>
                                                            </div>
                                                            <div class="fw-semibold"><?php echo $numberOfPayments > 0 ? htmlspecialchars((string)$numberOfPayments) : 'Not Available'; ?></div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-12">
                                                        <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                                <i class="fas fa-credit-card text-primary"></i>
                                                                <span class="text-secondary small fw-semibold"><?php echo htmlspecialchars($paymentLabel); ?></span>
                                                            </div>
                                                            <div class="fw-semibold"><?php echo displayCurrency($estimatedPayment); ?></div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-12">
                                                        <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                                <i class="fas fa-chart-line text-primary"></i>
                                                                <span class="text-secondary small fw-semibold">Estimated Interest</span>
                                                            </div>
                                                            <div class="fw-semibold"><?php echo displayCurrency($estimatedInterest); ?></div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-12">
                                                        <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                                <i class="fas fa-calendar-check text-primary"></i>
                                                                <span class="text-secondary small fw-semibold">Estimated Due Date</span>
                                                            </div>
                                                            <div class="fw-semibold"><?php echo $estimatedDueDate !== '' ? htmlspecialchars($estimatedDueDate) : 'Not Available'; ?></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="card border-0 shadow-sm h-100">
                                            <div class="card-body d-flex flex-column">
                                                <div class="d-flex align-items-center justify-content-between mb-3">
                                                    <div class="d-flex align-items-center"><i class="fas fa-file-alt fa-lg text-primary me-2"></i><span class="fw-semibold">Uploaded Requirements</span></div>
                                                    <a href="borrower_loan_application_lending.php?step=4&edit=1" class="btn btn-sm btn-outline-primary">✏ Edit</a>
                                                </div>
                                                <?php foreach ($uploadedDocuments as $document): ?>
                                                    <?php $isUploaded = in_array(trim((string)($document['status'] ?? '')), ['Uploaded', 'Pending Review'], true); ?>
                                                    <div class="mb-3">
                                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                                            <div class="text-secondary small"><?php echo htmlspecialchars($document['label']); ?></div>
                                                            <span class="fw-semibold text-<?php echo $isUploaded ? 'success' : 'danger'; ?>"><?php echo $isUploaded ? '✔ Uploaded' : '❌ Not Uploaded'; ?></span>
                                                        </div>
                                                        <?php if (!empty($document['name'])): ?>
                                                            <div class="small text-muted"><?php echo htmlspecialchars($document['name']); ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row g-4 mt-3">
                                    <div class="col-12">
                                        <div class="card border-0 shadow-sm h-100">
                                            <div class="card-body d-flex flex-column">
                                                <div class="d-flex align-items-center justify-content-between mb-3">
                                                    <div class="d-flex align-items-center"><i class="fas fa-chart-pie fa-lg text-primary me-2"></i><span class="fw-semibold">Application Summary</span></div>
                                                </div>
                                                <div class="row g-3">
                                                    <div class="col-sm-6">
                                                        <?php echo displayLabelValue('Applicant Name', displayValue($fullName)); ?>
                                                    </div>
                                                    <div class="col-sm-6">
                                                        <?php echo displayLabelValue('Loan Type', displayValue($loanType)); ?>
                                                    </div>
                                                    <div class="col-sm-6">
                                                        <?php echo displayLabelValue('Loan Amount', displayCurrency($loanAmount)); ?>
                                                    </div>
                                                    <div class="col-sm-6">
                                                        <?php echo displayLabelValue('Employment Status', displayValue($employmentStatus)); ?>
                                                    </div>
                                                    <div class="col-sm-6">
                                                        <?php echo displayLabelValue($incomeSummary['label'], $incomeSummary['value'] !== '' ? displayCurrency($incomeSummary['value']) : 'Not Provided'); ?>
                                                    </div>
                                                    <div class="col-sm-6">
                                                        <?php echo displayLabelValue('Documents Uploaded', count(array_filter($uploadedDocuments, fn($doc) => in_array(trim((string)($doc['status'] ?? '')), ['Uploaded', 'Pending Review'], true)))); ?>
                                                    </div>
                                                    <div class="col-sm-6">
                                                        <?php echo displayLabelValue('Date of Application', date('F j, Y')); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" id="declaration" name="declaration" value="1" <?php echo $declaration ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="declaration">I certify that all information and uploaded documents are true, authentic, and complete. I understand that providing false information may result in the rejection of my loan application.</label>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="col-12 d-flex justify-content-between flex-wrap gap-2">
                    <?php if ($editMode): ?>
                        <a href="borrower_loan_application_lending.php?step=5" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" name="form_action" value="save" class="btn btn-primary">Save Changes</button>
                    <?php else: ?>
                        <?php if ($step > 1): ?>
                            <a href="borrower_loan_application_lending.php?step=<?php echo max(1, $step - 1); ?>" class="btn btn-outline-secondary">Previous</a>
                        <?php else: ?>
                            <span></span>
                        <?php endif; ?>
                        <?php if ($step < 5): ?>
                            <button type="submit" class="btn btn-primary">Next <i class="fas fa-arrow-right ms-2"></i></button>
                        <?php else: ?>
                            <button type="submit" name="form_action" value="submit" class="btn btn-primary" id="submitButton" disabled>Submit Loan Application</button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const employmentStatus = document.getElementById('employmentStatus');
            const sections = document.querySelectorAll('.dynamic-section');
            const declarationCheckbox = document.getElementById('declaration');
            const submitButton = document.getElementById('submitButton');

            function updateSectionVisibility() {
                const selected = employmentStatus.value;
                sections.forEach(section => {
                    const isActive = section.getAttribute('data-status') === selected;
                    section.classList.toggle('active', isActive);
                    section.setAttribute('aria-hidden', isActive ? 'false' : 'true');
                    const inputs = section.querySelectorAll('input, select, textarea');
                    inputs.forEach(field => {
                        field.disabled = !isActive;
                    });
                });
            }

            if (employmentStatus) {
                employmentStatus.addEventListener('change', updateSectionVisibility);
                updateSectionVisibility();
            }

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
                const durationDays = Math.max(1, loanTermMonths * 30);

                switch (paymentFrequency) {
                    case 'Daily':
                        numberOfPayments = durationDays;
                        paymentLabel = 'Estimated Daily Payment';
                        break;
                    case 'Weekly':
                        numberOfPayments = Math.max(1, Math.ceil(durationDays / 7));
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
                const monthlyInterest = loanAmount * 0.05;
                const totalInterest = loanAmount > 0 && loanTermMonths > 0 ? monthlyInterest * loanTermMonths : 0;
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
