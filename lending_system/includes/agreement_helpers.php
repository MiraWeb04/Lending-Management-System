<?php
// agreement_helpers.php
require_once __DIR__ . '/db_lending.php';
require_once __DIR__ . '/loan_helpers.php';

function createLoanAgreementForApplication($applicationId, $generatedBy = null, $agreementText = null) {
    global $conn;
    $application = executeQuery('SELECT user_id, loan_amount, loan_term, approved_interest_rate, approved_monthly_payment, approved_first_payment_date, approved_due_date FROM loan_applications WHERE id = ? LIMIT 1', [$applicationId])->fetch(PDO::FETCH_ASSOC);
    if (!$application) return false;

    $borrowerId = (int)($application['user_id'] ?? 0);
    $lenderName = trim((string)($generatedBy ?? 'Administrator'));
    if ($lenderName === '') {
        $lenderName = 'Administrator';
    }
    $stmt = $conn->prepare('INSERT INTO loan_agreements (application_id, borrower_user_id, agreement_status, agreement_text, lender_agreement_status, lender_signed_by, lender_signed_at, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$applicationId, $borrowerId, 'Generated', $agreementText, 'Accepted', $lenderName]);
    return $conn->lastInsertId();
}

/**
 * Create or update the loan agreement and record lender acceptance in one step
 * (admin "Approve & Generate Agreement" counts as lender acceptance).
 */
function ensureLoanAgreementGeneratedWithLenderAcceptance(int $applicationId, array $applicationRow, string $lenderSignedBy): bool
{
    if ($applicationId <= 0) {
        return false;
    }

    $lenderSignedBy = trim($lenderSignedBy);
    if ($lenderSignedBy === '') {
        $lenderSignedBy = 'Administrator';
    }

    $existing = executeQuery('SELECT id FROM loan_agreements WHERE application_id = ? ORDER BY id DESC LIMIT 1', [$applicationId])->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        $agreementText = generateLoanAgreementText($applicationRow);
        executeQuery(
            'INSERT INTO loan_agreements (application_id, borrower_user_id, agreement_status, agreement_text, lender_agreement_status, lender_signed_by, lender_signed_at, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $applicationId,
                $applicationRow['user_id'] ?? null,
                'Generated',
                $agreementText,
                'Accepted',
                $lenderSignedBy,
            ]
        );
        return true;
    }

    return recordLenderAgreementAcceptance($applicationId, $lenderSignedBy);
}

function recordLenderAgreementAcceptance(int $applicationId, string $signedBy): bool
{
    global $conn;
    $signedBy = trim($signedBy);
    if ($applicationId <= 0 || $signedBy === '') {
        return false;
    }

    $agreement = executeQuery('SELECT id FROM loan_agreements WHERE application_id = ? ORDER BY id DESC LIMIT 1', [$applicationId])->fetch(PDO::FETCH_ASSOC);
    if (!$agreement) {
        return false;
    }

    executeQuery(
        'UPDATE loan_agreements SET lender_agreement_status = ?, lender_signed_by = ?, lender_signed_at = NOW() WHERE id = ?',
        ['Accepted', $signedBy, (int)$agreement['id']]
    );

    return true;
}

/**
 * Admin agreement generation counts as lender acceptance; repair older rows still marked Pending.
 */
function repairGeneratedAgreementLenderAcceptance(int $applicationId): bool
{
    if ($applicationId <= 0) {
        return false;
    }

    $agreement = executeQuery(
        'SELECT id, agreement_status, lender_agreement_status, lender_signed_by FROM loan_agreements WHERE application_id = ? ORDER BY id DESC LIMIT 1',
        [$applicationId]
    )->fetch(PDO::FETCH_ASSOC);

    if (!$agreement || strcasecmp((string)($agreement['agreement_status'] ?? ''), 'Generated') !== 0) {
        return false;
    }

    if (strcasecmp((string)($agreement['lender_agreement_status'] ?? ''), 'Accepted') === 0) {
        return true;
    }

    $signedBy = trim((string)($agreement['lender_signed_by'] ?? ''));
    if ($signedBy === '') {
        $application = executeQuery('SELECT reviewed_by FROM loan_applications WHERE id = ? LIMIT 1', [$applicationId])->fetch(PDO::FETCH_ASSOC);
        $signedBy = trim((string)($application['reviewed_by'] ?? ''));
    }
    if ($signedBy === '') {
        $signedBy = 'RJ and RR Finance Services';
    }

    return recordLenderAgreementAcceptance($applicationId, $signedBy);
}

function isLenderAgreementAccepted(?array $agreementRow): bool
{
    if (!$agreementRow) {
        return false;
    }

    if (strcasecmp((string)($agreementRow['lender_agreement_status'] ?? ''), 'Accepted') === 0) {
        return true;
    }

    return strcasecmp((string)($agreementRow['agreement_status'] ?? ''), 'Generated') === 0;
}

function generateLoanAgreementText(array $applicationRow): string {
    $loanAmountValue = (float)($applicationRow['approved_loan_amount'] ?? $applicationRow['loan_amount'] ?? 0);
    $loanTerm = trim((string)($applicationRow['approved_loan_term'] ?? $applicationRow['loan_term'] ?? '12'));
    $loanTermMonths = resolveLoanTermMonths($loanTerm, $applicationRow['approved_first_payment_date'] ?? null, $applicationRow['approved_due_date'] ?? null);
    $interestRateValue = getStandardMonthlyInterestRatePercent();
    $paymentFrequencyValue = trim((string)($applicationRow['payment_frequency'] ?? $applicationRow['approved_payment_frequency'] ?? 'Monthly'));
    $numberOfPayments = getNumberOfPayments($paymentFrequencyValue, $loanTermMonths, $applicationRow['approved_first_payment_date'] ?? null, $applicationRow['approved_due_date'] ?? null);
    $interestBreakdown = calculateLoanInterestBreakdown($loanAmountValue, $interestRateValue, $loanTermMonths);
    $installmentAmount = $numberOfPayments > 0 ? number_format($interestBreakdown['total_payable'] / $numberOfPayments, 2) : '0.00';
    $loanAmount = number_format($loanAmountValue, 2);
    $interestRate = number_format($interestRateValue, 2);
    $paymentFrequency = formatPaymentFrequencyLabel($paymentFrequencyValue);
    $borrowerName = trim((string)($applicationRow['borrower_name'] ?? $applicationRow['full_name'] ?? 'Borrower'));
    $loanPurpose = trim((string)($applicationRow['loan_purpose'] ?? 'Loan financing'));
    $firstPaymentDate = trim((string)($applicationRow['approved_first_payment_date'] ?? ''));
    $dueDate = trim((string)($applicationRow['approved_due_date'] ?? ''));

    $agreementText = "Loan Agreement for {$borrowerName}\n";
    $agreementText .= "Loan Amount: ₱{$loanAmount}\n";
    $agreementText .= "Loan Term: {$loanTerm}\n";
    $agreementText .= "Interest Rate: {$interestRate}%\n";
    $agreementText .= "Payment Frequency: {$paymentFrequency}\n";
    $agreementText .= "Number of Payments: {$numberOfPayments}\n";
    $agreementText .= "Installment Amount: ₱{$installmentAmount}\n";
    $agreementText .= "Loan Purpose: {$loanPurpose}\n";
    if ($firstPaymentDate !== '') {
        $agreementText .= "First Payment Date: {$firstPaymentDate}\n";
    }
    if ($dueDate !== '') {
        $agreementText .= "Expected Due Date: {$dueDate}\n";
    }
    $agreementText .= "\nBy accepting this agreement, the borrower agrees to the terms above and authorizes RJ and RR Finance Services to proceed with loan release upon acceptance.\n";

    return $agreementText;
}
