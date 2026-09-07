<?php
// agreement_helpers.php
require_once __DIR__ . '/db_lending.php';
require_once __DIR__ . '/loan_helpers.php';

function createLoanAgreementForApplication($applicationId, $generatedBy = null, $agreementText = null) {
    global $conn;
    $application = executeQuery('SELECT user_id, loan_amount, loan_term, approved_interest_rate, approved_monthly_payment, approved_first_payment_date, approved_due_date FROM loan_applications WHERE id = ? LIMIT 1', [$applicationId])->fetch(PDO::FETCH_ASSOC);
    if (!$application) return false;

    $borrowerId = (int)($application['user_id'] ?? 0);
    $stmt = $conn->prepare('INSERT INTO loan_agreements (application_id, borrower_user_id, agreement_status, agreement_text, created_at) VALUES (?, ?, ?, ?, NOW())');
    $stmt->execute([$applicationId, $borrowerId, 'Generated', $agreementText]);
    return $conn->lastInsertId();
}

function generateLoanAgreementText(array $applicationRow): string {
    $loanAmount = number_format((float)($applicationRow['loan_amount'] ?? $applicationRow['approved_loan_amount'] ?? 0), 2);
    $loanTerm = trim((string)($applicationRow['loan_term'] ?? $applicationRow['approved_loan_term'] ?? '12'));
    $interestRate = number_format((float)($applicationRow['approved_interest_rate'] ?? 0), 2);
    $paymentFrequency = formatPaymentFrequencyLabel(trim((string)($applicationRow['payment_frequency'] ?? 'Monthly')));
    $numberOfPayments = isset($applicationRow['number_of_payments']) ? max(1, (int)$applicationRow['number_of_payments']) : 0;
    if ($numberOfPayments === 0) {
        if (is_numeric($loanTerm)) {
            $numberOfPayments = max(1, (int)$loanTerm);
        } elseif (preg_match('/(\d+)/', $loanTerm, $matches)) {
            $numberOfPayments = max(1, (int)$matches[1]);
        }
    }
    $totalPayable = (float)($applicationRow['total_payable'] ?? 0);
    $installmentAmount = $numberOfPayments > 0 ? number_format($totalPayable / $numberOfPayments, 2) : '0.00';
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
