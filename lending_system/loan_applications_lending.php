<?php
/**
 * Admin Loan Applications Review Page for RJ and RR Finance Services
 */

require_once 'includes/auth_lending.php';
requireAdmin();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'includes/db_lending.php';
require_once 'includes/agreement_helpers.php';
require_once 'includes/loan_helpers.php';
ensureLoanApplicationSchema();
ensureLoanAgreementsSchema();

$user = getCurrentUser();

$message = $_SESSION['loan_review_message'] ?? '';
$messageType = $_SESSION['loan_review_type'] ?? '';
unset($_SESSION['loan_review_message'], $_SESSION['loan_review_type']);

function getLoanApplicationStatusBadge($status) {
    $status = trim((string)$status);
    switch (strtolower($status)) {
        case 'approved':
            return 'bg-success';
        case 'rejected':
            return 'bg-danger';
        case 'documents incomplete':
            return 'bg-warning text-dark';
        case 'under review':
        case 'waiting for agreement':
        case 'ready for release':
            return 'bg-info text-dark';
        default:
            return 'bg-secondary';
    }
}

function parseLoanTermMonthsForApproval($loanTerm) {
    if (is_numeric($loanTerm)) {
        return max(1, (int)$loanTerm);
    }
    if (preg_match('/(\d+)/', (string)$loanTerm, $matches)) {
        return max(1, (int)$matches[1]);
    }
    return 12;
}

function calculatePaymentScheduleForReview(string $paymentFrequency, int $loanTermMonths, float $totalRepayment): array {
    $numberOfPayments = getNumberOfPayments($paymentFrequency, $loanTermMonths);
    $estimatedPayment = $numberOfPayments > 0 ? round($totalRepayment / $numberOfPayments, 2) : 0.0;
    $estimatedDueDate = '';

    if ($loanTermMonths > 0) {
        $dueDate = new DateTime();
        $normalizedFrequency = normalizePaymentFrequencyKey($paymentFrequency);
        $numberOfPayments = max(1, $numberOfPayments);

        switch ($normalizedFrequency) {
            case 'daily':
                $dueDate->modify('+' . max(0, $numberOfPayments - 1) . ' days');
                break;
            case 'weekly':
                $dueDate->modify('+' . max(0, ($numberOfPayments - 1) * 7) . ' days');
                break;
            case 'bi-weekly':
                $dueDate->modify('+' . max(0, ($numberOfPayments - 1) * 14) . ' days');
                break;
            case 'semi-monthly':
                $dueDate->modify('+' . max(0, ($numberOfPayments - 1) * 15) . ' days');
                break;
            case 'monthly':
            default:
                $dueDate->modify('+' . max(0, $loanTermMonths - 1) . ' months');
                break;
        }

        $estimatedDueDate = $dueDate->format('Y-m-d');
    }

    return [
        'number_of_payments' => $numberOfPayments,
        'estimated_payment' => $estimatedPayment,
        'estimated_due_date' => $estimatedDueDate,
    ];
}

function getLoanApplicationStep3Summary(array $application): array {
    $loanAmount = isset($application['loan_amount']) && $application['loan_amount'] !== '' && $application['loan_amount'] !== null ? (float)$application['loan_amount'] : 0.0;
    $loanTerm = trim((string)($application['loan_term'] ?? ''));
    $paymentFrequency = formatPaymentFrequencyLabel(trim((string)($application['payment_frequency'] ?? 'Monthly')));
    $loanTermMonths = parseLoanTermMonthsForApproval($loanTerm);

    if (isset($application['loan_interest_rate']) && is_numeric($application['loan_interest_rate'])) {
        $estimatedInterestRate = (float)$application['loan_interest_rate'];
    } elseif (isset($application['monthly_interest_rate']) && is_numeric($application['monthly_interest_rate'])) {
        // monthly_interest_rate is stored as decimal fraction (e.g. 0.05 for 5%)
        $estimatedInterestRate = (float)$application['monthly_interest_rate'] * 100.0;
    } else {
        $estimatedInterestRate = 5.0;
    }
    $estimatedInterest = $loanTermMonths > 0 ? $loanAmount * ($estimatedInterestRate / 100) * $loanTermMonths : 0.0;
    $totalRepayment = $loanAmount > 0 && $loanTermMonths > 0 ? $loanAmount + $estimatedInterest : 0.0;

    $numberOfPayments = $loanTermMonths > 0 ? getNumberOfPayments($paymentFrequency, $loanTermMonths) : 0;
    $estimatedPayment = $numberOfPayments > 0 ? round($totalRepayment / $numberOfPayments, 2) : 0.0;
    $estimatedMonthlyPayment = isset($application['estimated_monthly_payment']) && $application['estimated_monthly_payment'] !== '' && $application['estimated_monthly_payment'] !== null
        ? (float)$application['estimated_monthly_payment']
        : 0.0;
    $estimatedDueDate = trim((string)($application['estimated_due_date'] ?? ''));

    if ($loanAmount > 0 && $loanTermMonths > 0 && $estimatedDueDate === '') {
        $schedule = calculatePaymentScheduleForReview($paymentFrequency, $loanTermMonths, $totalRepayment);
        $estimatedDueDate = $schedule['estimated_due_date'];
    }

    return [
        'payment_frequency' => $paymentFrequency,
        'number_of_payments' => $numberOfPayments,
        'estimated_payment' => $estimatedPayment,
        'estimated_monthly_payment' => $estimatedMonthlyPayment,
        'estimated_due_date' => $estimatedDueDate,
    ];
}

function displayValue($value) {
    $value = trim((string)$value);
    return $value !== '' ? htmlspecialchars($value) : 'Not Provided';
}

function displayCurrency($value) {
    if ($value === '' || $value === null) {
        return 'Not Provided';
    }
    return '₱' . number_format((float)$value, 2);
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
        employment_status VARCHAR(80) DEFAULT NULL,
        employer_name VARCHAR(150) DEFAULT NULL,
        occupation VARCHAR(150) DEFAULT NULL,
        monthly_income DECIMAL(12,2) DEFAULT NULL,
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
        remarks TEXT DEFAULT NULL,
        payment_frequency VARCHAR(50) DEFAULT 'Monthly',
        number_of_payments INT DEFAULT NULL,
        estimated_payment DECIMAL(12,2) DEFAULT NULL,
        estimated_due_date DATE DEFAULT NULL,
        monthly_interest_rate DECIMAL(5,4) DEFAULT NULL,
        estimated_interest DECIMAL(12,2) DEFAULT NULL,
        estimated_monthly_payment DECIMAL(12,2) DEFAULT NULL,
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
        'remarks' => "ALTER TABLE loan_applications ADD COLUMN remarks TEXT DEFAULT NULL",
        'payment_frequency' => "ALTER TABLE loan_applications ADD COLUMN payment_frequency VARCHAR(50) DEFAULT 'Monthly'",
        'number_of_payments' => "ALTER TABLE loan_applications ADD COLUMN number_of_payments INT DEFAULT NULL",
        'estimated_payment' => "ALTER TABLE loan_applications ADD COLUMN estimated_payment DECIMAL(12,2) DEFAULT NULL",
        'estimated_due_date' => "ALTER TABLE loan_applications ADD COLUMN estimated_due_date DATE DEFAULT NULL",
        'monthly_interest_rate' => "ALTER TABLE loan_applications ADD COLUMN monthly_interest_rate DECIMAL(5,4) DEFAULT NULL",
        'estimated_interest' => "ALTER TABLE loan_applications ADD COLUMN estimated_interest DECIMAL(12,2) DEFAULT NULL",
        'estimated_monthly_payment' => "ALTER TABLE loan_applications ADD COLUMN estimated_monthly_payment DECIMAL(12,2) DEFAULT NULL",
        'credit_evaluation' => "ALTER TABLE loan_applications ADD COLUMN credit_evaluation TEXT DEFAULT NULL",
        'risk_assessment' => "ALTER TABLE loan_applications ADD COLUMN risk_assessment TEXT DEFAULT NULL",
        'internal_notes' => "ALTER TABLE loan_applications ADD COLUMN internal_notes TEXT DEFAULT NULL",
        'reviewed_by' => "ALTER TABLE loan_applications ADD COLUMN reviewed_by VARCHAR(150) DEFAULT NULL",
        'reviewed_at' => "ALTER TABLE loan_applications ADD COLUMN reviewed_at DATETIME DEFAULT NULL",
        'rejection_reason' => "ALTER TABLE loan_applications ADD COLUMN rejection_reason TEXT DEFAULT NULL",
        'agreement_status' => "ALTER TABLE loan_applications ADD COLUMN agreement_status VARCHAR(50) DEFAULT 'Pending'"
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

    $conn->exec("CREATE TABLE IF NOT EXISTS loan_application_reviews (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        application_id INT UNSIGNED NOT NULL,
        decision VARCHAR(50) NOT NULL,
        review_note TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(150) DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT DEFAULT NULL,
        title VARCHAR(150) NOT NULL,
        message TEXT DEFAULT NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

ensureLoanApplicationSchema();

function createClientAndPendingLoanForApplication($application, $adminName) {
    if (!$application || empty($application['borrower_name'])) {
        return null;
    }

    $clientEmail = trim((string)($application['email_address'] ?? ''));
    $clientContact = trim((string)($application['mobile_number'] ?? ''));
    $borrowerFullName = trim((string)$application['borrower_name']);
    $nameParts = preg_split('/\s+/', $borrowerFullName, 2);
    $firstName = trim((string)($nameParts[0] ?? ''));
    $lastName = trim((string)($nameParts[1] ?? ''));

    $clientLookup = executeQuery('SELECT client_id FROM clients WHERE (user_id = ? AND user_id IS NOT NULL) OR (email = ? AND email != "") OR (contact = ? AND contact != "") LIMIT 1', [
        $application['user_id'] ?? null,
        $clientEmail,
        $clientContact
    ])->fetch(PDO::FETCH_ASSOC);

    $clientData = [
        'first_name' => $firstName ?: 'Borrower',
        'last_name' => $lastName,
        'address' => trim((string)($application['complete_address'] ?? '')),
        'contact' => $clientContact,
        'email' => $clientEmail,
        'date_registered' => date('Y-m-d')
    ];

    if (!empty($application['user_id'])) {
        $clientData['user_id'] = $application['user_id'];
    }

    if ($clientLookup) {
        $clientId = (int)$clientLookup['client_id'];
        update('clients', $clientData, 'client_id = ?', [$clientId]);
    } else {
        $clientId = (int)insert('clients', $clientData);
    }

    if (!$clientId) {
        return null;
    }

    $loanAmount = $application['approved_loan_amount'] !== null ? (float)$application['approved_loan_amount'] : (float)($application['loan_amount'] ?? 0);
    $interestRate = $application['approved_interest_rate'] !== null ? (float)$application['approved_interest_rate'] : 5.0;
    $loanTerm = trim((string)($application['approved_loan_term'] ?: $application['loan_term'] ?? '12'));
    $termMonths = parseLoanTermMonthsForApproval($loanTerm);
    $dateReleased = date('Y-m-d');
    $dueDate = date('Y-m-d', strtotime($dateReleased . ' + ' . $termMonths . ' month'));

    // Calculate per requested formula:
    // Total Interest = Approved Loan Amount × (Approved Interest Rate / 100)
    // Total Interest for Loan = Total Interest × Approved Loan Term
    // Total Repayment = Approved Loan Amount + Total Interest for Loan
    // Monthly Payment = Total Repayment ÷ Approved Loan Term
    $totalInterest = $loanAmount * ($interestRate / 100);
    $totalInterestForLoan = $totalInterest * $termMonths;
    $totalRepayment = $loanAmount + $totalInterestForLoan;
    $monthlyPayment = $termMonths > 0 ? round($totalRepayment / $termMonths, 2) : round($totalRepayment, 2);

    $totalPayable = $totalRepayment;
    $paymentFrequency = resolvePaymentFrequency(
        $application['payment_frequency'] ?? null,
        null,
        $dateReleased,
        $dueDate
    );
    $dailyPayment = calculateAmountToCollect($totalPayable, $paymentFrequency, $termMonths);

    $existingLoan = executeQuery('SELECT loan_id FROM loans WHERE client_id = ? AND loan_amount = ? AND date_released = ? LIMIT 1', [$clientId, $loanAmount, $dateReleased])->fetch(PDO::FETCH_ASSOC);
    if ($existingLoan) {
        return $existingLoan['loan_id'];
    }

    $loanId = (int)insert('loans', [
        'client_id' => $clientId,
        'loan_amount' => $loanAmount,
        'interest' => $interestRate,
        'total_payable' => $totalPayable,
        'daily_payment' => $dailyPayment,
        'date_released' => $dateReleased,
        'due_date' => $dueDate,
        'status' => 'Pending Release',
        'payment_frequency' => $paymentFrequency,
    ]);

    return $loanId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');
    $applicationId = (int)($_POST['application_id'] ?? 0);
    $documentId = (int)($_POST['document_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    if ($documentId > 0 && in_array($action, ['approve_document', 'reject_document'], true)) {
        $documentUpdate = $action === 'approve_document' ? 'Verified' : 'Rejected';
        $docReason = $action === 'reject_document' ? $reason : null;

        if ($action === 'reject_document' && $reason === '') {
            $_SESSION['loan_review_message'] = 'Please enter a rejection reason for the document.';
            $_SESSION['loan_review_type'] = 'danger';
            header('Location: loan_applications_lending.php');
            exit;
        }

        executeQuery('UPDATE loan_application_documents SET verification_status = ?, rejection_reason = ? WHERE id = ?', [$documentUpdate, $docReason, $documentId]);
        $_SESSION['loan_review_message'] = 'Document review updated.';
        $_SESSION['loan_review_type'] = 'success';
        header('Location: loan_applications_lending.php');
        exit;
    }

    if ($applicationId > 0 && $action === 'generate_agreement') {
        $applicationRow = executeQuery('SELECT * FROM loan_applications WHERE id = ? LIMIT 1', [$applicationId])->fetch(PDO::FETCH_ASSOC);
        if (!$applicationRow) {
            $_SESSION['loan_review_message'] = 'Application not found.';
            $_SESSION['loan_review_type'] = 'danger';
            header('Location: loan_applications_lending.php');
            exit;
        }

        $existingAgreement = executeQuery('SELECT id FROM loan_agreements WHERE application_id = ? ORDER BY id DESC LIMIT 1', [$applicationId])->fetch(PDO::FETCH_ASSOC);
        if (!$existingAgreement) {
            $agreementText = generateLoanAgreementText($applicationRow);
            executeQuery('INSERT INTO loan_agreements (application_id, borrower_user_id, agreement_status, agreement_text, created_at) VALUES (?, ?, ?, ?, NOW())', [
                $applicationId,
                $applicationRow['user_id'] ?? null,
                'Generated',
                $agreementText
            ]);
        }

        executeQuery('UPDATE loan_applications SET status = ?, agreement_status = ? WHERE id = ?', ['Ready for Release', 'Generated', $applicationId]);
        if (!empty($applicationRow['user_id'])) {
            executeQuery('INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)', [
                $applicationRow['user_id'],
                'Loan Agreement Ready',
                'A loan agreement has been prepared for your application. Please review and accept it.'
            ]);
        }

        $_SESSION['loan_review_message'] = 'Loan agreement has been generated and borrower notified.';
        $_SESSION['loan_review_type'] = 'success';
        header('Location: loan_applications_lending.php');
        exit;
    }

    if ($applicationId > 0 && in_array($action, ['approve', 'reject', 'request_documents'], true)) {
        if ($action === 'reject' && $reason === '' && $remarks === '') {
            $_SESSION['loan_review_message'] = 'Please enter a rejection reason.';
            $_SESSION['loan_review_type'] = 'danger';
            header('Location: loan_applications_lending.php');
            exit;
        }

        if ($action === 'reject' && $reason === '' && $remarks !== '') {
            $reason = $remarks;
        }

        $approvedLoanAmount = trim($_POST['approved_loan_amount'] ?? '');
        $approvedInterestRate = trim($_POST['approved_interest_rate'] ?? '');
        $approvedMonthlyPayment = trim($_POST['approved_monthly_payment'] ?? '');
        $approvedLoanTerm = trim($_POST['approved_loan_term'] ?? '');
        $approvedFirstPaymentDate = trim($_POST['approved_first_payment_date'] ?? '');
        $approvedDueDate = trim($_POST['approved_due_date'] ?? '');

        // fetch existing application details (term fallback) before server-side recompute
        $applicationRow = executeQuery('SELECT user_id, loan_term, payment_frequency, approved_first_payment_date, approved_due_date FROM loan_applications WHERE id = ? LIMIT 1', [$applicationId])->fetch(PDO::FETCH_ASSOC);

        $borrowerPaymentFrequency = trim((string)($applicationRow['payment_frequency'] ?? 'Monthly'));

        // Recompute payment amount server-side using the borrower's saved Step 3 payment frequency
        $P = is_numeric($approvedLoanAmount) ? (float)$approvedLoanAmount : 0.0;
        $rate = is_numeric($approvedInterestRate) ? (float)$approvedInterestRate : 0.0;
        $termMonths = parseLoanTermMonthsForApproval($approvedLoanTerm ?: ($applicationRow['loan_term'] ?? '12'));
        $numberOfPayments = $termMonths > 0 ? getNumberOfPayments($borrowerPaymentFrequency, $termMonths) : 0;
        if ($P > 0 && $rate >= 0 && $termMonths > 0) {
            $totalInterest = $P * ($rate / 100);
            $totalInterestForLoan = $totalInterest * $termMonths;
            $totalRepayment = $P + $totalInterestForLoan;
            $serverInstallment = $numberOfPayments > 0 ? round($totalRepayment / $numberOfPayments, 2) : null;
            $approvedMonthlyPayment = $serverInstallment !== null ? (string)$serverInstallment : '';
        } else {
            $approvedMonthlyPayment = '';
        }

        if ($approvedFirstPaymentDate === '' && !empty($applicationRow['approved_first_payment_date'])) {
            $approvedFirstPaymentDate = trim((string)$applicationRow['approved_first_payment_date']);
        }

        if ($approvedDueDate === '' && $approvedFirstPaymentDate !== '' && $termMonths > 0) {
            $firstPayment = new DateTimeImmutable($approvedFirstPaymentDate);
            $normalizedFrequency = normalizePaymentFrequencyKey($borrowerPaymentFrequency);
            switch ($normalizedFrequency) {
                case 'daily':
                    $approvedDueDate = $firstPayment->modify('+' . max(1, $numberOfPayments - 1) . ' days')->format('Y-m-d');
                    break;
                case 'weekly':
                    $approvedDueDate = $firstPayment->modify('+' . max(1, $numberOfPayments - 1) . ' days')->format('Y-m-d');
                    break;
                case 'semi-monthly':
                    $approvedDueDate = $firstPayment->modify('+' . max(1, $numberOfPayments - 1) . ' days')->format('Y-m-d');
                    break;
                case 'monthly':
                default:
                    $approvedDueDate = $firstPayment->modify('+' . (max(1, $termMonths) - 1) . ' months')->format('Y-m-d');
                    break;
            }
        }
        if (!$applicationRow) {
            $_SESSION['loan_review_message'] = 'Application record not found.';
            $_SESSION['loan_review_type'] = 'danger';
            header('Location: loan_applications_lending.php');
            exit;
        }

        $status = '';
        $reviewNote = '';
        switch ($action) {
            case 'approve':
                $status = 'Ready for Release';
                // prepare auto-generated remark
                $termMonthsForRemark = parseLoanTermMonthsForApproval($approvedLoanTerm ?: ($applicationRow['loan_term'] ?? '12'));
                $amt = is_numeric($approvedLoanAmount) ? number_format((float)$approvedLoanAmount, 2) : '0.00';
                $rateFmt = is_numeric($approvedInterestRate) ? rtrim(rtrim(number_format((float)$approvedInterestRate, 2), '0'), '.') : '0';
                $monthlyFmt = is_numeric($approvedMonthlyPayment) ? number_format((float)$approvedMonthlyPayment, 2) : '0.00';
                $autoReviewNote = "Approved: Amount ₱{$amt}, Interest {$rateFmt}% , Term {$termMonthsForRemark} months, Estimated Installment ₱{$monthlyFmt}";
                if (!empty($approvedFirstPaymentDate)) $autoReviewNote .= ', First Payment ' . $approvedFirstPaymentDate;
                if (!empty($approvedDueDate)) $autoReviewNote .= ', Due ' . $approvedDueDate;
                $reviewNote = $remarks !== '' ? $remarks : $autoReviewNote;
                break;
            case 'reject':
                $status = 'Rejected';
                $reviewNote = $reason;
                break;
            case 'request_documents':
                $status = 'Documents Incomplete';
                $reviewNote = $remarks !== '' ? $remarks : 'Additional documents are required.';
                break;
        }

        $numberOfPaymentsForReview = $termMonths > 0 ? getNumberOfPayments($borrowerPaymentFrequency, $termMonths) : null;
        $totalRepaymentForReview = ($P > 0 && $rate >= 0 && $termMonths > 0) ? $P + ($P * ($rate / 100) * $termMonths) : null;
        $estimatedPaymentForReview = $numberOfPaymentsForReview > 0 && $totalRepaymentForReview !== null ? round($totalRepaymentForReview / $numberOfPaymentsForReview, 2) : null;

        executeQuery('UPDATE loan_applications SET status = ?, remarks = ?, reviewed_by = ?, reviewed_at = NOW(), rejection_reason = ?, approved_loan_amount = ?, approved_interest_rate = ?, approved_monthly_payment = ?, approved_loan_term = ?, approved_first_payment_date = ?, approved_due_date = ?, payment_frequency = ?, number_of_payments = ?, estimated_payment = ?, estimated_due_date = ? WHERE id = ?', [$status, $reviewNote, $user['full_name'] ?? $user['username'] ?? 'Admin', $action === 'reject' ? $reason : null, $approvedLoanAmount !== '' ? $approvedLoanAmount : null, $approvedInterestRate !== '' ? $approvedInterestRate : null, $approvedMonthlyPayment !== '' ? $approvedMonthlyPayment : null, $approvedLoanTerm !== '' ? $approvedLoanTerm : null, $approvedFirstPaymentDate !== '' ? $approvedFirstPaymentDate : null, $approvedDueDate !== '' ? $approvedDueDate : null, $borrowerPaymentFrequency !== '' ? $borrowerPaymentFrequency : null, $numberOfPaymentsForReview, $estimatedPaymentForReview, $approvedDueDate !== '' ? $approvedDueDate : null, $applicationId]);

        executeQuery('INSERT INTO loan_application_reviews (application_id, decision, review_note, created_by) VALUES (?, ?, ?, ?)', [$applicationId, ucfirst(str_replace('_', ' ', $action)), $reviewNote, $user['full_name'] ?? $user['username'] ?? 'Admin']);

        $title = '';
        $messageText = '';
        if ($action === 'approve') {
            $title = 'Application Approved';
            $messageText = 'Your loan application has been approved. A loan agreement has been generated and the borrower has been notified.';

            $applicationRow = executeQuery('SELECT * FROM loan_applications WHERE id = ? LIMIT 1', [$applicationId])->fetch(PDO::FETCH_ASSOC);
            if ($applicationRow) {
                $loanId = createClientAndPendingLoanForApplication($applicationRow, $user['full_name'] ?? $user['username'] ?? 'Admin');
                if ($loanId) {
                    $messageText .= ' A loan record has been created and is pending release.';
                }

                $existingAgreement = executeQuery('SELECT id FROM loan_agreements WHERE application_id = ? ORDER BY id DESC LIMIT 1', [$applicationId])->fetch(PDO::FETCH_ASSOC);
                if (!$existingAgreement) {
                    $agreementText = generateLoanAgreementText($applicationRow);
                    executeQuery('INSERT INTO loan_agreements (application_id, borrower_user_id, agreement_status, agreement_text, created_at) VALUES (?, ?, ?, ?, NOW())', [
                        $applicationRow['id'],
                        $applicationRow['user_id'] ?? null,
                        'Generated',
                        $agreementText
                    ]);
                }
            }
        } elseif ($action === 'reject') {
            $title = 'Application Rejected';
            $messageText = 'Your loan application was rejected. Reason: ' . $reason;
        } else {
            $title = 'Documents Requested';
            $messageText = $reviewNote;
        }

        if ($applicationRow['user_id']) {
            executeQuery('INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)', [$applicationRow['user_id'], $title, $messageText]);
        }

        $_SESSION['loan_review_message'] = 'Application status updated successfully.';
        $_SESSION['loan_review_type'] = 'success';
        header('Location: loan_applications_lending.php');
        exit;
    }
}

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$where = '1=1';
$params = [];

if ($search !== '') {
    $where .= ' AND (la.borrower_name LIKE ? OR la.loan_type LIKE ? OR la.loan_purpose LIKE ? OR la.email_address LIKE ?)';
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

if ($statusFilter !== '') {
    $where .= ' AND la.status = ?';
    $params[] = $statusFilter;
}

$countStmt = executeQuery("SELECT COUNT(*) AS total FROM loan_applications la LEFT JOIN users u ON u.user_id = la.user_id WHERE $where", $params);
$totalApplications = $countStmt !== false ? (int)$countStmt->fetchColumn() : 0;
$totalPages = max(1, (int)ceil($totalApplications / $perPage));

$applicationsStmt = executeQuery(
    "SELECT la.*, la.borrower_name AS borrower_name, u.full_name AS borrower_full_name, u.status AS borrower_status, u.user_id AS borrower_user_id, (SELECT agreement_status FROM loan_agreements WHERE application_id = la.id ORDER BY id DESC LIMIT 1) AS agreement_status_value FROM loan_applications la LEFT JOIN users u ON u.user_id = la.user_id WHERE $where ORDER BY la.submitted_at DESC LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);
$applications = $applicationsStmt !== false ? $applicationsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
$applications = is_array($applications) ? $applications : [];

foreach ($applications as &$application) {
    $documentsStmt = executeQuery('SELECT * FROM loan_application_documents WHERE application_id = ? ORDER BY uploaded_at DESC', [(int)$application['id']]);
    $documents = $documentsStmt !== false ? $documentsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $application['documents'] = is_array($documents) ? $documents : [];
}
unset($application);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Applications - RJ and RR Finance Services</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/lending_styles.css">
</head>
<body>
    <?php include 'includes/nav_lending.php'; ?>

    <div class="container-fluid py-4">
        <div class="page-hero">
            <div>
                <h1 class="page-title"><i class="fas fa-file-signature me-2"></i>Loan Applications</h1>
                <p class="page-subtitle">Review submitted borrower loan applications, request documents, and manage approvals in one place.</p>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?php echo htmlspecialchars($messageType === 'success' ? 'success' : 'danger'); ?> rounded-4"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label">Search</label>
                        <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search borrower, loan type, purpose...">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="">All statuses</option>
                            <?php
                                $statusOptions = [
                                    'Pending Review' => 'Pending Review',
                                    'Rejected' => 'Rejected',
                                    'Ready for Release' => 'Ready for Release'
                                ];
                                foreach ($statusOptions as $statusValue => $statusLabel):
                            ?>
                                <option value="<?php echo htmlspecialchars($statusValue); ?>" <?php echo $statusFilter === $statusValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($statusLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-2"></i>Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Application No.</th>
                                <th>Borrower Name</th>
                                <th>Loan Type</th>
                                <th>Requested Amount</th>
                                <th>Loan Term</th>
                                <th>Date Submitted</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($applications) > 0): ?>
                                <?php foreach ($applications as $application): ?>
                                    <tr>
                                        <td>#<?php echo (int)$application['id']; ?></td>
                                        <td><?php echo htmlspecialchars(trim((string)($application['borrower_name'] ?? $application['borrower_full_name'] ?? 'N/A'))); ?></td>
                                        <?php
                                        $listLoanType = trim((string)($application['loan_type'] ?? $application['requested_type'] ?? $application['requested_loan_type'] ?? ''));
                                        $listLoanAmount = $application['loan_amount'] ?? $application['requested_amount'] ?? $application['requested_loan_amount'] ?? null;
                                        $listLoanTerm = trim((string)($application['loan_term'] ?? $application['requested_term'] ?? $application['requested_loan_term'] ?? ''));
                                        ?>
                                        <td><?php echo displayValue($listLoanType); ?></td>
                                        <td><?php echo displayCurrency($listLoanAmount); ?></td>
                                        <td><?php echo displayValue($listLoanTerm); ?></td>
                                        <td><?php echo htmlspecialchars(!empty($application['submitted_at']) ? date('M d, Y', strtotime($application['submitted_at'])) : '—'); ?></td>
                                        <td><span class="badge <?php echo getLoanApplicationStatusBadge($application['status'] ?? 'Pending Review'); ?>"><?php echo htmlspecialchars($application['status'] ?? 'Pending Review'); ?></span></td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-2">
                                                <?php if (trim((string)$application['status']) === 'Ready for Release'): ?>
                                                    <a href="loan_release_lending.php?view_id=<?php echo (int)$application['id']; ?>" class="btn btn-sm btn-success">Release Loan</a>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo (int)$application['id']; ?>">View</button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">No loan applications found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="loan_applications_lending.php?search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>

    <?php foreach ($applications as $application): ?>
        <?php $modalPaymentFrequency = trim((string)($application['payment_frequency'] ?? 'Monthly')); ?>
        <div class="modal fade" id="viewModal<?php echo (int)$application['id']; ?>" tabindex="-1" aria-hidden="true" data-payment-frequency="<?php echo htmlspecialchars($modalPaymentFrequency); ?>">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-file-alt me-2"></i>Loan Application Review</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-4">
                            <div class="col-lg-6">
                                <div class="card shadow-sm h-100">
                                    <div class="card-header bg-white">
                                        <h6 class="m-0 fw-bold"><i class="fas fa-user me-2"></i>Borrower Information</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row g-3">
                                            <div class="col-md-6"><div class="text-muted small">Full Name</div><div class="fw-semibold"><?php echo htmlspecialchars(trim((string)($application['borrower_name'] ?? $application['borrower_full_name'] ?? 'N/A'))); ?></div></div>
                                            <div class="col-md-6"><div class="text-muted small">Birthday</div><div class="fw-semibold"><?php echo !empty($application['date_of_birth']) ? htmlspecialchars(date('M d, Y', strtotime($application['date_of_birth']))) : '—'; ?></div></div>
                                            <div class="col-md-6"><div class="text-muted small">Gender</div><div class="fw-semibold"><?php echo htmlspecialchars($application['gender'] ?? '—'); ?></div></div>
                                            <div class="col-md-6"><div class="text-muted small">Contact Number</div><div class="fw-semibold"><?php echo htmlspecialchars($application['mobile_number'] ?? '—'); ?></div></div>
                                            <div class="col-12"><div class="text-muted small">Address</div><div class="fw-semibold"><?php echo htmlspecialchars($application['complete_address'] ?? '—'); ?></div></div>
                                            <div class="col-12"><div class="text-muted small">Email</div><div class="fw-semibold"><?php echo htmlspecialchars($application['email_address'] ?? '—'); ?></div></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <?php
                                $employmentStatus = trim((string)($application['employment_status'] ?? ''));
                                $agencyOffice = trim((string)($application['agency_office'] ?? ''));
                                $position = trim((string)($application['position'] ?? $application['job_position'] ?? ''));
                                $employmentType = trim((string)($application['employment_type'] ?? ''));
                                $monthlyIncome = trim((string)($application['monthly_income'] ?? $application['monthly_salary'] ?? $application['income_amount'] ?? ''));
                                $yearsInService = trim((string)($application['years_in_service'] ?? ''));
                                $officeAddress = trim((string)($application['office_address'] ?? ''));
                                $officeContact = trim((string)($application['office_contact'] ?? ''));
                                $companyName = trim((string)($application['company_name'] ?? $application['employer_name'] ?? ''));
                                $employerName = trim((string)($application['employer_name'] ?? $companyName));
                                $occupation = trim((string)($application['occupation'] ?? ''));
                                $employmentLength = trim((string)($application['employment_length'] ?? ''));
                                $employerAddress = trim((string)($application['employer_address'] ?? ''));
                                $employerContact = trim((string)($application['employer_contact'] ?? ''));
                                $businessName = trim((string)($application['business_name'] ?? ''));
                                $natureOfBusiness = trim((string)($application['nature_of_business'] ?? ''));
                                $yearsInBusiness = trim((string)($application['years_in_business'] ?? ''));
                                $businessContact = trim((string)($application['business_contact'] ?? $application['employer_contact'] ?? ''));
                                $businessAddress = trim((string)($application['business_address'] ?? ''));
                                $ofwCountry = trim((string)($application['ofw_country'] ?? ''));
                                $yearsAbroad = trim((string)($application['years_abroad'] ?? ''));
                                $employerContactOfw = trim((string)($application['employer_contact_ofw'] ?? ''));
                                $pensionSource = trim((string)($application['pension_source'] ?? ''));
                                $monthlyPension = trim((string)($application['monthly_pension'] ?? ''));
                                $pensionContact = trim((string)($application['pension_contact'] ?? ''));
                                $remarks = trim((string)($application['remarks'] ?? ''));
                                $step2ReviewTitle = $employmentStatus === 'Self-Employed / Business Owner' ? 'Business & Income Information' : 'Employment Information';
                                $step2ReviewIcon = $employmentStatus === 'Self-Employed / Business Owner' ? 'fa-store' : 'fa-briefcase';
                                ?>
                                <div class="card shadow-sm h-100">
                                    <div class="card-header bg-white">
                                        <h6 class="m-0 fw-bold"><i class="fas <?php echo htmlspecialchars($step2ReviewIcon); ?> me-2"></i><?php echo htmlspecialchars($step2ReviewTitle); ?></h6>
                                    </div>
                                    <div class="card-body">
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
                                                        <div class="fw-semibold"><?php echo displayCurrency($monthlyIncome); ?></div>
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
                                                        <div class="fw-semibold"><?php echo displayCurrency($monthlyIncome); ?></div>
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
                                            <?php else: ?>
                                                <div class="col-12">
                                                    <div class="text-secondary">No employment details are available for this application yet.</div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="card shadow-sm">
                                    <div class="card-header bg-white">
                                        <h6 class="m-0 fw-bold"><i class="fas fa-hand-holding-usd me-2"></i>Loan Details</h6>
                                    </div>
                                    <div class="card-body">
                                        <?php
                                        $loanType = trim((string)($application['loan_type'] ?? ''));
                                        $loanAmount = trim((string)($application['loan_amount'] ?? $application['requested_amount'] ?? ''));
                                        $loanTerm = trim((string)($application['loan_term'] ?? ''));
                                        $loanPurpose = trim((string)($application['loan_purpose'] ?? $application['loan_purpose_details'] ?? ''));
                                        $loanReviewDetails = getLoanApplicationStep3Summary($application);
                                        $rawPaymentFrequency = trim((string)($application['payment_frequency'] ?? $loanReviewDetails['payment_frequency'] ?? 'Monthly'));
                                        $paymentFrequency = formatPaymentFrequencyLabel($rawPaymentFrequency);
                                        $numberOfPayments = !empty($application['number_of_payments']) ? (int)$application['number_of_payments'] : (isset($loanReviewDetails['number_of_payments']) ? (int)$loanReviewDetails['number_of_payments'] : 0);
                                        $estimatedPayment = isset($loanReviewDetails['estimated_payment']) ? (float)$loanReviewDetails['estimated_payment'] : 0.0;
                                        $estimatedMonthlyPayment = isset($loanReviewDetails['estimated_monthly_payment']) ? (float)$loanReviewDetails['estimated_monthly_payment'] : 0.0;
                                        $estimatedDueDate = trim((string)($loanReviewDetails['estimated_due_date'] ?? ''));

                                        // Determine installment label
                                        $estimatedPaymentLabel = 'Estimated ' . $paymentFrequency . ' Payment';

                                        // Try to fetch Amount to Collect from loans table
                                        $loanAmountToCollect = null;
                                        if (!empty($application['user_id']) || !empty($loanAmount)) {
                                            $loanQuery = "SELECT amount_to_collect FROM loans WHERE ";
                                            $loanParams = [];
                                            if (!empty($application['user_id'])) {
                                                $loanQuery .= "user_id = ? OR client_id IN (SELECT client_id FROM clients WHERE user_id = ?)";
                                                $loanParams[] = $application['user_id'];
                                                $loanParams[] = $application['user_id'];
                                            } else {
                                                $loanQuery .= "1=0";
                                            }
                                            $loanQuery .= " ORDER BY date_released DESC LIMIT 1";
                                            $loanResult = executeQuery($loanQuery, $loanParams);
                                            if ($loanResult) {
                                                $loanRow = $loanResult->fetch(PDO::FETCH_ASSOC);
                                                if ($loanRow && !empty($loanRow['amount_to_collect'])) {
                                                    $loanAmountToCollect = (float)$loanRow['amount_to_collect'];
                                                }
                                            }
                                        }

                                        $approvedLoanAmount = $application['approved_loan_amount'] ?? $loanAmount;
                                        $approvedInterestRate = $application['approved_interest_rate'] ?? '5.00';
                                        $approvedLoanTerm = $application['approved_loan_term'] ?? $loanTerm;
                                        $approvedMonthlyPayment = $application['approved_monthly_payment'] ?? ($estimatedPayment > 0 ? $estimatedPayment : ($estimatedMonthlyPayment > 0 ? $estimatedMonthlyPayment : ''));
                                        $approvedFirstPaymentDate = $application['approved_first_payment_date'] ?? '';
                                        $approvedDueDate = $application['approved_due_date'] ?? '';

                                        $interestEstimate = 0.0;
                                        $termMonthsForEstimate = parseLoanTermMonthsForApproval($loanTerm);
                                        // Prefer explicit loan_interest_rate (percent), otherwise use monthly_interest_rate (fraction)
                                        if (!empty($loanAmount) && $termMonthsForEstimate > 0) {
                                            if (isset($application['loan_interest_rate']) && is_numeric($application['loan_interest_rate'])) {
                                                $ratePercent = (float)$application['loan_interest_rate'];
                                            } elseif (isset($application['monthly_interest_rate']) && is_numeric($application['monthly_interest_rate'])) {
                                                $ratePercent = (float)$application['monthly_interest_rate'] * 100.0;
                                            } else {
                                                $ratePercent = 0.0;
                                            }

                                            if ($ratePercent > 0) {
                                                $interestEstimate = (float)$loanAmount * ($ratePercent / 100.0) * $termMonthsForEstimate;
                                            }
                                        }
                                        ?>
                                        <div class="row g-3">
                                            <div class="col-md-6"><div class="text-muted small">Loan Type</div><div class="fw-semibold"><?php echo displayValue($loanType); ?></div></div>
                                            <div class="col-md-6"><div class="text-muted small">Requested Amount</div><div class="fw-semibold"><?php echo displayCurrency($loanAmount); ?></div></div>
                                            <div class="col-md-6"><div class="text-muted small">Loan Term</div><div class="fw-semibold"><?php echo displayValue($loanTerm); ?></div></div>
                                            <div class="col-md-6"><div class="text-muted small">Purpose of Loan</div><div class="fw-semibold"><?php echo displayValue($loanPurpose); ?></div></div>
                                            <div class="col-md-6"><div class="text-muted small">Payment Frequency</div><div class="fw-semibold"><?php echo displayValue($paymentFrequency); ?></div></div>
                                            <div class="col-md-6">
                                                <div class="text-muted small">Number of Payments</div>
                                                <div class="fw-semibold"><?php 
                                                    $numPayments = !empty($numberOfPayments) ? (int)$numberOfPayments : null;
                                                    if (!$numPayments) {
                                                        $termMonths = parseLoanTermMonthsForApproval($approvedLoanTerm ?: $loanTerm);
                                                        if ($termMonths > 0) {
                                                            $numPayments = getNumberOfPayments($paymentFrequency, $termMonths);
                                                        }
                                                    }
                                                    echo $numPayments ? htmlspecialchars((string)$numPayments) : 'Not Available';
                                                ?></div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="text-muted small"><?php echo htmlspecialchars($estimatedPaymentLabel); ?></div>
                                                <div class="fw-semibold"><?php 
                                                    $installmentPayment = null;
                                                    
                                                    if (!empty($loanAmountToCollect)) {
                                                        $installmentPayment = $loanAmountToCollect;
                                                    }

                                                    if (!$installmentPayment && !empty($estimatedPayment)) {
                                                        $installmentPayment = (float)$estimatedPayment;
                                                    }
                                                    if (!$installmentPayment && !empty($estimatedMonthlyPayment)) {
                                                        $installmentPayment = (float)$estimatedMonthlyPayment;
                                                    }
                                                    if (!$installmentPayment && !empty($approvedMonthlyPayment)) {
                                                        $installmentPayment = (float)$approvedMonthlyPayment;
                                                    }

                                                    $frequencyForCalc = $paymentFrequency !== '' ? $paymentFrequency : 'Monthly';
                                                    $termMonths = parseLoanTermMonthsForApproval($loanTerm);
                                                    if (!$installmentPayment && !empty($application['approved_loan_amount']) && !empty($application['approved_interest_rate']) && $termMonths > 0) {
                                                        $P = (float)$application['approved_loan_amount'];
                                                        $rate = (float)$application['approved_interest_rate'];
                                                        $totalInterest = $P * ($rate / 100);
                                                        $totalRepayment = $P + ($totalInterest * $termMonths);
                                                        $installmentPayment = calculateAmountToCollect($totalRepayment, $frequencyForCalc, $termMonths, null, $approvedFirstPaymentDate ?: null, $approvedDueDate ?: null);
                                                    }

                                                    if (!$installmentPayment && !empty($loanAmount) && !empty($application['loan_interest_rate']) && $termMonths > 0) {
                                                        $P = (float)$loanAmount;
                                                        $rate = (float)$application['loan_interest_rate'];
                                                        $totalInterest = $P * ($rate / 100);
                                                        $totalRepayment = $P + ($totalInterest * $termMonths);
                                                        $installmentPayment = calculateAmountToCollect($totalRepayment, $frequencyForCalc, $termMonths, null, $approvedFirstPaymentDate ?: null, $approvedDueDate ?: null);
                                                    }

                                                    echo $installmentPayment ? '₱' . number_format((float)$installmentPayment, 2) : 'Not Provided';
                                                ?></div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="text-muted small">Estimated Interest</div>
                                                <div class="fw-semibold">₱<?php echo number_format($interestEstimate, 2); ?></div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="text-muted small">Estimated Due Date</div>
                                                <div class="fw-semibold"><?php 
                                                    $dueDate = !empty($estimatedDueDate) ? trim((string)$estimatedDueDate) : (!empty($approvedDueDate) ? trim((string)$approvedDueDate) : '');
                                                    if (empty($dueDate) && !empty($approvedLoanTerm)) {
                                                        $termStr = trim((string)$approvedLoanTerm);
                                                        $termMonths = is_numeric($termStr) ? (int)$termStr : (preg_match('/(\d+)/', $termStr, $m) ? (int)$m[1] : 12);
                                                        if ($termMonths > 0) {
                                                            $dueDateObj = new DateTime();
                                                            $dueDateObj->modify("+{$termMonths} months");
                                                            $dueDate = $dueDateObj->format('Y-m-d');
                                                        }
                                                    }
                                                    if (empty($dueDate) && !empty($loanTerm)) {
                                                        $termStr = trim((string)$loanTerm);
                                                        $termMonths = is_numeric($termStr) ? (int)$termStr : (preg_match('/(\d+)/', $termStr, $m) ? (int)$m[1] : 12);
                                                        if ($termMonths > 0) {
                                                            $dueDateObj = new DateTime();
                                                            $dueDateObj->modify("+{$termMonths} months");
                                                            $dueDate = $dueDateObj->format('Y-m-d');
                                                        }
                                                    }
                                                    echo $dueDate ? htmlspecialchars($dueDate) : 'Not Provided';
                                                ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="card shadow-sm">
                                    <div class="card-header bg-white">
                                        <h6 class="m-0 fw-bold"><i class="fas fa-file-upload me-2"></i>Uploaded Documents</h6>
                                    </div>
                                    <div class="card-body">
                                        <?php if (!empty($application['documents'])): ?>
                                            <div class="list-group">
                                                <?php foreach ($application['documents'] as $document): ?>
                                                    <div class="list-group-item d-flex flex-column flex-lg-row justify-content-between gap-2">
                                                        <div>
                                                            <div class="fw-semibold"><?php echo htmlspecialchars($document['document_name'] ?? 'Document'); ?></div>
                                                            <div class="small text-muted">Status: <?php echo htmlspecialchars($document['verification_status'] ?? 'Pending Review'); ?></div>
                                                        </div>
                                                        <div class="d-flex flex-wrap gap-2">
                                                            <?php $documentPath = trim((string)($document['document_path'] ?? '')); ?>
                                                            <?php if ($documentPath !== ''): ?>
                                                                <a href="#" class="btn btn-sm btn-outline-primary view-document-btn" data-bs-toggle="modal" data-bs-target="#documentViewerModal" data-parent="#viewModal<?php echo (int)$application['id']; ?>" data-path="<?php echo htmlspecialchars($documentPath); ?>" data-name="<?php echo htmlspecialchars($document['document_name'] ?? 'Document'); ?>"><i class="fas fa-eye me-1"></i>View</a>
                                                                <a href="<?php echo htmlspecialchars($documentPath); ?>" class="btn btn-sm btn-outline-secondary" download><i class="fas fa-download me-1"></i>Download</a>
                                                            <?php else: ?>
                                                                <span class="btn btn-sm btn-outline-secondary disabled"><i class="fas fa-eye me-1"></i>View</span>
                                                                <span class="btn btn-sm btn-outline-secondary disabled"><i class="fas fa-download me-1"></i>Download</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-muted">No uploaded documents available for this application yet.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="card shadow-sm">
                                    <div class="card-header bg-white">
                                        <h6 class="m-0 fw-bold"><i class="fas fa-comments me-2"></i>Admin Review</h6>
                                    </div>
                                    <div class="card-body">
                                        <form method="post">
                                            <input type="hidden" name="application_id" value="<?php echo (int)$application['id']; ?>">
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label class="form-label">Approved Loan Amount</label>
                                                    <input type="number" step="0.01" min="0" class="form-control" name="approved_loan_amount" value="<?php echo htmlspecialchars($approvedLoanAmount ?? ''); ?>" placeholder="e.g. 50000">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Approved Interest Rate (%)</label>
                                                    <input type="number" step="0.01" min="0" class="form-control" name="approved_interest_rate" value="<?php echo htmlspecialchars($approvedInterestRate ?? ''); ?>" placeholder="e.g. 2.5">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Approved Installment Amount</label>
                                                    <input type="number" step="0.01" min="0" class="form-control" name="approved_monthly_payment" value="<?php echo htmlspecialchars($approvedMonthlyPayment ?? ''); ?>" placeholder="Auto-calculated" readonly>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Approved Loan Term</label>
                                                    <input type="text" class="form-control" name="approved_loan_term" value="<?php echo htmlspecialchars($approvedLoanTerm ?? ''); ?>" placeholder="e.g. 12 months">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">First Payment Date</label>
                                                    <input type="date" class="form-control" name="approved_first_payment_date" value="<?php echo htmlspecialchars($approvedFirstPaymentDate ?? ''); ?>">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Estimated Due Date</label>
                                                    <input type="date" class="form-control" name="approved_due_date" value="<?php echo htmlspecialchars($approvedDueDate ?? ''); ?>">
                                                </div>
                                                <!-- Admin-only note fields removed; remarks are auto-generated by the system -->
                                                <div class="col-12">
                                                    <label class="form-label">Remarks</label>
                                                    <textarea class="form-control" name="remarks" rows="3" placeholder="Remarks to appear in the borrower notification"><?php echo htmlspecialchars($application['remarks'] ?? ''); ?></textarea>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label">Rejection Reason</label>
                                                    <textarea class="form-control" name="reason" rows="3" placeholder="Required only when rejecting the application"></textarea>
                                                </div>
                                            </div>
                                            <div class="d-flex flex-wrap gap-2 mt-4">
                                                <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#generateAgreementModal" data-application-id="<?php echo (int)$application['id']; ?>"><i class="fas fa-file-contract me-2"></i>Create Agreement</button>
                                                <button type="submit" name="action" value="reject" class="btn btn-danger"><i class="fas fa-times me-2"></i>Reject Application</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Generate Agreement Confirmation Modal -->
    <div class="modal fade" id="generateAgreementModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="action" value="generate_agreement">
                    <input type="hidden" name="application_id" id="generateAgreementApplicationId" value="">
                    <div class="modal-header">
                        <h5 class="modal-title">Approve Loan Application</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>By clicking <strong>Approve & Generate Agreement</strong>, you confirm that this loan application has been reviewed and approved. The Loan Agreement will be automatically generated using the approved application details and made available to the borrower for electronic acceptance.</p>
                        <p>Please ensure that all applicant information, loan amount, repayment terms, and other loan details are accurate before proceeding.</p>
                        <p class="fw-semibold">Do you want to continue?</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Approve & Generate Agreement</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const agreementModal = document.getElementById('generateAgreementModal');
            if (!agreementModal) {
                return;
            }

            const applicationIdInput = document.getElementById('generateAgreementApplicationId');
            const triggerButtons = document.querySelectorAll('[data-bs-target="#generateAgreementModal"]');

            triggerButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    if (applicationIdInput) {
                        applicationIdInput.value = button.getAttribute('data-application-id') || '';
                    }
                });
            });

            agreementModal.addEventListener('hidden.bs.modal', function () {
                if (applicationIdInput) {
                    applicationIdInput.value = '';
                }
            });
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Document viewer modal -->
    <div class="modal fade" id="documentViewerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file"></i> <span id="documentViewerTitle">Document</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="documentViewerBody" style="min-height:200px;">
                    <div class="text-center text-muted">Loading document...</div>
                </div>
                <div class="modal-footer">
                    <a id="documentViewerDownload" href="#" class="btn btn-secondary" download><i class="fas fa-download me-1"></i>Download</a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function(){
            var activeParentSelector = null;
            function isImage(ext) {
                return ['jpg','jpeg','png','gif','webp','bmp'].indexOf(ext) !== -1;
            }
            function isPDF(ext) { return ext === 'pdf'; }

            document.querySelectorAll('.view-document-btn').forEach(function(btn){
                btn.addEventListener('click', function(e){
                    e.preventDefault();
                    var path = btn.getAttribute('data-path') || '';
                    var name = btn.getAttribute('data-name') || 'Document';
                    var parent = btn.getAttribute('data-parent') || '';
                    var titleEl = document.getElementById('documentViewerTitle');
                    var bodyEl = document.getElementById('documentViewerBody');
                    var dlEl = document.getElementById('documentViewerDownload');

                    // hide parent modal (if provided) so the viewer appears cleanly
                    if (parent) {
                        activeParentSelector = parent;
                        var parentEl = document.querySelector(parent);
                        if (parentEl) {
                            var parentInstance = bootstrap.Modal.getOrCreateInstance(parentEl);
                            parentInstance.hide();
                        }
                    }

                    titleEl.textContent = name;
                    dlEl.href = path || '#';
                    if (!path) {
                        bodyEl.innerHTML = '<div class="text-center text-muted">No file path available for this document.</div>';
                        return;
                    }

                    var lower = path.split('.').pop().toLowerCase();
                    if (isImage(lower)) {
                        bodyEl.innerHTML = '<div class="text-center"><img src="'+path+'" alt="'+name+'" class="img-fluid" /></div>';
                    } else if (isPDF(lower)) {
                        bodyEl.innerHTML = '<div style="height:70vh;"><iframe src="'+path+'" frameborder="0" style="width:100%;height:100%;"></iframe></div>';
                    } else {
                        bodyEl.innerHTML = '<div class="text-center">Cannot preview this file type. You can download it instead.<br><a href="'+path+'" class="btn btn-primary mt-3" download>Download</a></div>';
                    }
                });
            });

            var docModalEl = document.getElementById('documentViewerModal');
            if (docModalEl) {
                docModalEl.addEventListener('hidden.bs.modal', function(){
                    if (activeParentSelector) {
                        var p = document.querySelector(activeParentSelector);
                        if (p) {
                            var pInstance = bootstrap.Modal.getOrCreateInstance(p);
                            pInstance.show();
                        }
                        activeParentSelector = null;
                    }
                });
            }

            // Auto-calculate payment amount inside each application modal based on the borrower's saved payment frequency
            function parseTermToMonths(term) {
                if (!term) return 12;
                var s = String(term).trim();
                var m = s.match(/(\d+)/);
                if (!m) return 12;
                var num = parseInt(m[1], 10) || 12;
                if (/year|yr|y\b/i.test(s)) {
                    return num * 12;
                }
                return num; // assume months when not specified
            }

            function normalizePaymentFrequency(paymentFrequency) {
                if (!paymentFrequency) return 'monthly';
                var freq = String(paymentFrequency).toLowerCase();
                if (freq.indexOf('daily') !== -1) return 'daily';
                if (freq.indexOf('weekly') !== -1) return 'weekly';
                if (freq.indexOf('semi') !== -1 || freq.indexOf('15 days') !== -1 || freq.indexOf('half of the month') !== -1) return 'semi-monthly';
                return 'monthly';
            }

            function getPaymentCount(paymentFrequency, months) {
                var normalized = normalizePaymentFrequency(paymentFrequency);
                var durationDays = Math.max(1, months * 30);

                switch (normalized) {
                    case 'daily':
                        return durationDays;
                        case 'weekly':
                        return Math.max(1, months * 4);
                    case 'semi-monthly':
                        return Math.max(1, months * 2);
                    case 'monthly':
                    default:
                        return Math.max(1, months);
                }
            }

            document.querySelectorAll('input[name="approved_loan_amount"]').forEach(function(amountEl){
                var modalEl = amountEl.closest('.modal');
                if (!modalEl) return;
                var interestEl = modalEl.querySelector('input[name="approved_interest_rate"]');
                var termEl = modalEl.querySelector('input[name="approved_loan_term"]');
                var monthlyEl = modalEl.querySelector('input[name="approved_monthly_payment"]');
                if (!monthlyEl) return;

                function computeMonthly() {
                    var P = parseFloat(amountEl.value) || 0;
                    var rate = parseFloat(interestEl && interestEl.value ? interestEl.value : 0) || 0;
                    var months = parseTermToMonths(termEl && termEl.value ? termEl.value : '12');
                    var paymentFrequency = modalEl.getAttribute('data-payment-frequency') || 'Monthly';
                    if (P <= 0 || rate < 0 || months <= 0) { monthlyEl.value = ''; return; }

                    var totalInterest = P * (rate / 100);
                    var totalInterestForLoan = totalInterest * months;
                    var totalRepayment = P + totalInterestForLoan;
                    var paymentCount = getPaymentCount(paymentFrequency, months);
                    var installmentAmount = totalRepayment / paymentCount;
                    monthlyEl.value = isFinite(installmentAmount) ? installmentAmount.toFixed(2) : '';
                }

                [amountEl, interestEl, termEl].forEach(function(el){ if (el) el.addEventListener('input', computeMonthly); });
                // initial compute in case fields are prefilled
                computeMonthly();

                // First payment date and estimated due date handling
                var firstPaymentEl = modalEl.querySelector('input[name="approved_first_payment_date"]');
                var dueEl = modalEl.querySelector('input[name="approved_due_date"]');

                function addMonths(dateObj, months) {
                    var d = new Date(dateObj.getTime());
                    var day = d.getDate();
                    d.setMonth(d.getMonth() + months);
                    // handle month overflow
                    if (d.getDate() < day) {
                        // set to last day of previous month
                        d.setDate(0);
                    }
                    return d;
                }

                function addDays(dateObj, days) {
                    var d = new Date(dateObj.getTime());
                    d.setDate(d.getDate() + days);
                    return d;
                }

                function formatDateYYYYMMDD(d) {
                    var y = d.getFullYear();
                    var m = (d.getMonth() + 1).toString().padStart(2,'0');
                    var day = d.getDate().toString().padStart(2,'0');
                    return y + '-' + m + '-' + day;
                }

                function getDefaultFirstPaymentDate(startDate, paymentFrequency) {
                    var d = new Date(startDate.getTime());
                    switch (paymentFrequency) {
                        case 'Daily':
                            d = addDays(d, 1);
                            break;
                        case 'Weekly':
                            d = addDays(d, 7);
                            break;
                        case 'Every Half of the Month':
                            d = addDays(d, 15);
                            break;
                        case 'Monthly':
                        default:
                            d = addMonths(d, 1);
                            break;
                    }
                    return d;
                }

                function computeDueDate() {
                    if (!firstPaymentEl || !dueEl || !termEl) return;
                    var fp = firstPaymentEl.value;
                    if (!fp) return;
                    var paymentFrequency = modalEl.getAttribute('data-payment-frequency') || 'Monthly';
                    var months = parseTermToMonths(termEl.value || '12');
                    var start = new Date(fp + 'T00:00:00');
                    if (isNaN(start.getTime())) return;

                    var paymentCount = getPaymentCount(paymentFrequency, months);
                    if (paymentCount <= 0) return;

                    var end;
                    switch (normalizePaymentFrequency(paymentFrequency)) {
                        case 'daily':
                            end = addDays(start, paymentCount - 1);
                            break;
                        case 'weekly':
                            end = addDays(start, (paymentCount - 1) * 7);
                            break;
                        case 'semi-monthly':
                            end = addDays(start, (paymentCount - 1) * 15);
                            break;
                        case 'monthly':
                        default:
                            end = addMonths(start, paymentCount - 1);
                            break;
                    }

                    dueEl.value = formatDateYYYYMMDD(end);
                    // update remarks when due date changes
                    generateRemarks();
                }

                if (termEl) termEl.addEventListener('input', function(){ computeMonthly(); computeDueDate(); generateRemarks(); });
                if (firstPaymentEl) firstPaymentEl.addEventListener('input', function(){ computeDueDate(); generateRemarks(); });

                // generate remarks based on current approved values
                function generateRemarks() {
                    var remarksEl = modalEl.querySelector('textarea[name="remarks"]');
                    if (!remarksEl) return;
                    var amt = parseFloat(amountEl.value) || 0;
                    var rateVal = parseFloat(interestEl && interestEl.value ? interestEl.value : 0) || 0;
                    var months = parseTermToMonths(termEl && termEl.value ? termEl.value : '12');
                    var monthlyVal = parseFloat(monthlyEl.value) || 0;
                    var firstDate = firstPaymentEl ? firstPaymentEl.value : '';
                    var dueDateVal = dueEl ? dueEl.value : '';

                    if (amt <= 0 || months <= 0) {
                        // clear remarks if incomplete
                        // but do not overwrite if user manually typed something
                        if (!remarksEl.dataset.userEdited) remarksEl.value = '';
                        return;
                    }

                    var amtFmt = amt.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
                    var rateFmt = isFinite(rateVal) ? (Math.round((rateVal + Number.EPSILON) * 100) / 100).toString() : '0';
                    var monthlyFmt = monthlyVal.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
                    var paymentFrequency = modalEl.getAttribute('data-payment-frequency') || 'Monthly';
                    var auto = 'Approved: Amount ₱' + amtFmt + ', Interest ' + rateFmt + '%, Term ' + months + ' months, Payment Frequency ' + paymentFrequency + ', Estimated Payment ₱' + monthlyFmt;
                    if (firstDate) auto += ', First Payment ' + firstDate;
                    if (dueDateVal) auto += ', Due ' + dueDateVal;

                    // if admin edited remarks manually, don't override
                    if (!remarksEl.dataset.userEdited) {
                        remarksEl.value = auto;
                    }
                }

                // mark remarks as user-edited if changed by admin
                var remarksField = modalEl.querySelector('textarea[name="remarks"]');
                if (remarksField) {
                    remarksField.addEventListener('input', function(){
                        remarksField.dataset.userEdited = remarksField.value.trim() !== '';
                    });
                }

                // When the modal is shown, default first payment to tomorrow if empty
                try {
                    var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
                    modalEl.addEventListener('shown.bs.modal', function(){
                        if (firstPaymentEl && (!firstPaymentEl.value || firstPaymentEl.value.trim() === '')) {
                            var t = new Date();
                            var paymentFrequency = modalEl.getAttribute('data-payment-frequency') || 'Monthly';
                            var defaultFirstPayment = getDefaultFirstPaymentDate(t, paymentFrequency);
                            firstPaymentEl.value = formatDateYYYYMMDD(defaultFirstPayment);
                        }
                        // compute on show
                        computeMonthly();
                        computeDueDate();
                        generateRemarks();
                    });
                } catch (e) {
                    // ignore if bootstrap not available for this modal
                }
            });
        })();
    </script>
</body>
</html>
