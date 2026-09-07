<?php
/**
 * Borrower Loan Agreement Page for RJ and RR Finance Services
 */

require_once 'includes/auth_lending.php';
require_once 'includes/db_lending.php';
require_once 'includes/loan_helpers.php';
require_once 'includes/agreement_helpers.php';
ensureLoanAgreementsSchema();
ensureNotificationsSchema();

if (!isLoggedIn()) {
    header('Location: borrower_login_lending.php');
    exit;
}

if (!isBorrower()) {
    header('Location: ' . getDashboardRedirectUrl(getCurrentUser()));
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user = getCurrentUser();

$application = executeQuery('SELECT * FROM loan_applications WHERE user_id = ? ORDER BY id DESC LIMIT 1', [(int)$user['user_id']])->fetch(PDO::FETCH_ASSOC);
$agreementRow = null;
if ($application) {
    $agreementRow = executeQuery('SELECT * FROM loan_agreements WHERE application_id = ? ORDER BY id DESC LIMIT 1', [(int)$application['id']])->fetch(PDO::FETCH_ASSOC);
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');
    $signature = trim($_POST['signature'] ?? '');
    $declineReason = trim($_POST['decline_reason'] ?? '');

    if (!$application) {
        $message = 'No loan application is available for agreement.';
        $messageType = 'danger';
    } elseif (!in_array(strtolower((string)($application['status'] ?? '')), ['approved', 'waiting for loan agreement', 'agreement accepted', 'agreement declined'], true)) {
        $message = 'Your loan agreement is not available yet because your application has not been approved or generated.';
        $messageType = 'warning';
    } elseif ($action === 'accept') {
        if ($signature === '') {
            $message = 'Please enter your full name as your digital signature.';
            $messageType = 'danger';
        } else {
            $savedAgreementText = $agreementRow['agreement_text'] ?? generateLoanAgreementText($application);
            executeQuery('INSERT INTO loan_agreements (application_id, borrower_user_id, agreement_status, agreement_text, signed_by, signed_at) VALUES (?, ?, ?, ?, ?, NOW())', [(int)$application['id'], (int)$user['user_id'], 'Accepted', $savedAgreementText, $signature]);
            executeQuery('UPDATE loan_applications SET status = ?, remarks = ? WHERE id = ?', ['Agreement Accepted', 'Borrower accepted the loan agreement.', (int)$application['id']]);
            addNotification((int)$user['user_id'], 'Loan Agreement Accepted', 'You have successfully accepted the loan agreement.');
            notifyAdmins('Borrower Accepted Agreement', 'A borrower has accepted the loan agreement and the loan is ready for release.');
            $message = 'Congratulations! Your loan agreement has been accepted.';
            $messageType = 'success';
        }
    } elseif ($action === 'decline') {
        if ($declineReason === '') {
            $message = 'Please provide a reason for declining the agreement.';
            $messageType = 'danger';
        } else {
            $savedAgreementText = $agreementRow['agreement_text'] ?? generateLoanAgreementText($application);
            executeQuery('INSERT INTO loan_agreements (application_id, borrower_user_id, agreement_status, agreement_text, decline_reason, signed_at) VALUES (?, ?, ?, ?, ?, NOW())', [(int)$application['id'], (int)$user['user_id'], 'Declined', $savedAgreementText, $declineReason]);
            executeQuery('UPDATE loan_applications SET status = ?, remarks = ? WHERE id = ?', ['Agreement Declined', $declineReason, (int)$application['id']]);
            addNotification((int)$user['user_id'], 'Loan Agreement Declined', 'You declined the loan agreement. Reason: ' . $declineReason);
            notifyAdmins('Borrower Declined Agreement', 'A borrower declined the loan agreement. Reason: ' . $declineReason);
            $message = 'Your agreement decision has been recorded.';
            $messageType = 'warning';
        }
    }
}

$paymentFrequency = 'Monthly';
$paymentFrequencyDisplay = 'Monthly';
$loanTermLabel = '12';
$loanTermMonths = 12;
$loanAmount = 0.0;
$interestRate = 5.0;
$totalInterest = 0.0;
$totalInterestForLoan = 0.0;
$totalRepayment = 0.0;
$scheduledInstallmentAmount = 0.0;
$numberOfPayments = 12;
$totalPayable = 0.0;
$loanReleaseDate = date('M d, Y', strtotime('+3 days'));
$dueDateSchedule = date('M d, Y', strtotime('+30 days'));
$installments = [];

if ($application) {
    $paymentFrequency = trim((string)($application['payment_frequency'] ?? $application['approved_payment_frequency'] ?? 'Monthly'));
    $paymentFrequencyDisplay = formatPaymentFrequencyLabel($paymentFrequency);
    $loanTermLabel = trim((string)($application['approved_loan_term'] ?? $application['loan_term'] ?? '12'));
    $loanTermMonths = resolveLoanTermMonths($loanTermLabel, $application['approved_first_payment_date'] ?? null, $application['approved_due_date'] ?? null);

    $loanAmount = (float)($application['approved_loan_amount'] ?? $application['loan_amount'] ?? 0);
    $interestRate = (float)($application['approved_interest_rate'] ?? 5.0);
    $totalInterest = $loanAmount * ($interestRate / 100);
    $totalInterestForLoan = $totalInterest * max(1, $loanTermMonths);
    $totalRepayment = $loanAmount + $totalInterestForLoan;
    $numberOfPayments = getNumberOfPayments($paymentFrequency, $loanTermMonths, $application['approved_first_payment_date'] ?? null, $application['approved_due_date'] ?? null);
    $scheduledInstallmentAmount = $numberOfPayments > 0 ? round($totalRepayment / $numberOfPayments, 2) : 0.0;
    $totalPayable = $totalRepayment;
    $loanReleaseDate = !empty($application['approved_first_payment_date']) ? date('M d, Y', strtotime($application['approved_first_payment_date'])) : date('M d, Y', strtotime('+3 days'));
    $dueDateSchedule = !empty($application['approved_due_date']) ? date('M d, Y', strtotime($application['approved_due_date'])) : date('M d, Y', strtotime("+{$loanTermMonths} month"));

    $scheduleStart = !empty($application['approved_first_payment_date']) ? new DateTime($application['approved_first_payment_date']) : new DateTime('+30 days');
    for ($i = 1; $i <= max(1, $numberOfPayments); $i++) {
        $dueDate = (clone $scheduleStart);
        $normalizedFrequency = normalizePaymentFrequencyKey($paymentFrequency);
        switch ($normalizedFrequency) {
            case 'daily':
                $dueDate->modify('+' . ($i - 1) . ' day');
                break;
            case 'weekly':
                $dueDate->modify('+' . (($i - 1) * 7) . ' day');
                break;
            case 'bi-weekly':
                $dueDate->modify('+' . (($i - 1) * 14) . ' day');
                break;
            case 'semi-monthly':
                $dueDate->modify('+' . (($i - 1) * 15) . ' day');
                break;
            case 'monthly':
            default:
                if ($i > 1) {
                    $dueDate->modify('+' . ($i - 1) . ' month');
                }
                break;
        }

        $principalAmount = $numberOfPayments > 0 ? round($loanAmount / $numberOfPayments, 2) : 0.0;
        $interestAmountPerInstallment = $numberOfPayments > 0 ? round($totalInterestForLoan / $numberOfPayments, 2) : 0.0;

        $installments[] = [
            'number' => $i,
            'due_date' => $dueDate->format('M d, Y'),
            'principal' => number_format($principalAmount, 2),
            'interest' => number_format($interestAmountPerInstallment, 2),
            'total_due' => number_format($scheduledInstallmentAmount, 2),
            'status' => 'Upcoming'
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Agreement - RJ and RR Finance Services</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/lending_styles.css">
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
                    <h1 class="page-title"><i class="fas fa-file-contract me-2"></i>Loan Agreement</h1>
                    <p class="page-subtitle">Review the loan terms, sign digitally, and accept the agreement securely.</p>
                </div>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?php echo htmlspecialchars($messageType === 'success' ? 'success' : ($messageType === 'warning' ? 'warning' : 'danger')); ?> rounded-4"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if (!$application || !in_array((string)($application['status'] ?? ''), ['Approved', 'Waiting for Loan Agreement', 'Agreement Accepted', 'Agreement Declined'], true)): ?>
            <div class="card shadow-sm">
                <div class="card-body text-center py-5">
                    <div class="login-logo mx-auto mb-3">
                        <img src="images/logo.png" alt="RJ and RR Finance Services logo">
                    </div>
                    <h4 class="fw-bold text-primary">Loan Agreement Not Available Yet</h4>
                    <p class="text-muted mb-4">Your loan agreement will become available after your application is approved by the administrator.</p>
                    <a href="borrower_dashboard_lending.php" class="btn btn-primary">Return to Dashboard</a>
                </div>
            </div>
        <?php else: ?>
            <?php
                $agreementText = "<p>This Loan Agreement is entered into between <strong>RJ and RR Finance Services</strong> (the Lender) and <strong>" . htmlspecialchars($application['borrower_name'] ?? 'Borrower') . "</strong> (the Borrower). The Borrower agrees to borrow the principal amount of ₱" . number_format($loanAmount, 2) . ".</p>";
                $agreementText .= "<p>The loan term is <strong>" . htmlspecialchars($loanTermLabel) . "</strong> at a fixed interest rate of <strong>" . number_format($interestRate, 2) . "%</strong>. Total interest for the loan is ₱" . number_format($totalInterestForLoan, 2) . ", making the total repayment amount ₱" . number_format($totalRepayment, 2) . ".</p>";
                $agreementText .= "<p>Payment Frequency: <strong>" . htmlspecialchars($paymentFrequencyDisplay) . "</strong>. Total number of payments is <strong>" . htmlspecialchars((string)$numberOfPayments) . "</strong>.</p>";
                $agreementText .= "<p>Each installment payment is ₱" . number_format($scheduledInstallmentAmount, 2) . " per " . htmlspecialchars(strtolower($paymentFrequencyDisplay)) . " installment. Payments must be made according to the agreed schedule until the loan is fully repaid.</p>";
                $agreementText .= "<p>By accepting this agreement, the Borrower confirms that all applicant information and loan details are accurate.</p>";
            ?>
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center">
                        <div>
                            <h5 class="fw-bold mb-1">RJ and RR Finance Services</h5>
                            <div class="text-muted">Loan Agreement</div>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <button type="button" class="btn btn-outline-primary"><i class="fas fa-download me-2"></i>Download Agreement</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-2"></i>Print Agreement</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-xl-8">
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h6 class="m-0 fw-bold"><i class="fas fa-info-circle me-2"></i>Loan Information</h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6"><div class="consent-card h-100"><div class="text-muted small">Loan Number</div><div class="fw-semibold">#<?php echo (int)$application['id']; ?></div></div></div>
                                <div class="col-md-6"><div class="consent-card h-100"><div class="text-muted small">Borrower Name</div><div class="fw-semibold"><?php echo htmlspecialchars($application['borrower_name'] ?? 'N/A'); ?></div></div></div>
                                <div class="col-md-6"><div class="consent-card h-100"><div class="text-muted small">Loan Type</div><div class="fw-semibold"><?php echo htmlspecialchars($application['loan_type'] ?? 'N/A'); ?></div></div></div>
                                <div class="col-md-6"><div class="consent-card h-100"><div class="text-muted small">Loan Amount</div><div class="fw-semibold">₱<?php echo number_format((float)($application['loan_amount'] ?? 0), 2); ?></div></div></div>
                                <div class="col-md-6"><div class="consent-card h-100"><div class="text-muted small">Interest Rate</div><div class="fw-semibold"><?php echo number_format($interestRate, 2); ?>%</div></div></div>
                                <div class="col-md-6"><div class="consent-card h-100"><div class="text-muted small">Loan Term</div><div class="fw-semibold"><?php echo htmlspecialchars($application['loan_term'] ?? 'N/A'); ?></div></div></div>
                                <div class="col-md-6"><div class="consent-card h-100"><div class="text-muted small">Payment Frequency</div><div class="fw-semibold"><?php echo htmlspecialchars($paymentFrequencyDisplay); ?></div></div></div>
                                <div class="col-md-6"><div class="consent-card h-100"><div class="text-muted small">Installment Amount</div><div class="fw-semibold">₱<?php echo number_format($scheduledInstallmentAmount, 2); ?></div></div></div>
                                <div class="col-md-6"><div class="consent-card h-100"><div class="text-muted small">Total Payable Amount</div><div class="fw-semibold">₱<?php echo number_format($totalPayable, 2); ?></div></div></div>
                                <div class="col-md-6"><div class="consent-card h-100"><div class="text-muted small">Loan Release Date (Estimated)</div><div class="fw-semibold"><?php echo htmlspecialchars($loanReleaseDate); ?></div></div></div>
                                <div class="col-md-6"><div class="consent-card h-100"><div class="text-muted small">Due Date Schedule</div><div class="fw-semibold"><?php echo htmlspecialchars($dueDateSchedule); ?></div></div></div>
                            </div>
                        </div>
                    </div>

                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h6 class="m-0 fw-bold"><i class="fas fa-scroll me-2"></i>Terms & Conditions</h6>
                        </div>
                        <div class="card-body">
                            <div class="border rounded-4 p-3" style="max-height: 320px; overflow-y: auto;">
                                <?php if (!empty($agreementRow['agreement_text'])): ?>
                                    <div class="mb-3"><strong>Generated Loan Agreement</strong></div>
                                    <div class="small text-muted">This agreement was prepared by the administrator and contains your approved loan terms.</div>
                                    <div class="mt-3 agreement-text" style="white-space: pre-wrap;"><?php echo htmlspecialchars($agreementRow['agreement_text']); ?></div>
                                <?php else: ?>
                                    <h6 class="fw-bold">Borrower Responsibilities</h6>
                                    <p class="small text-muted">The borrower shall make payments on time and keep all information updated.</p>
                                    <h6 class="fw-bold">Payment Schedule</h6>
                                    <p class="small text-muted">Repayments shall be made according to the agreed installment schedule.</p>
                                    <h6 class="fw-bold">Interest</h6>
                                    <p class="small text-muted">Interest shall be charged based on the disclosed rate and applied to the outstanding balance.</p>
                                    <h6 class="fw-bold">Late Payment Penalties</h6>
                                    <p class="small text-muted">Late payments may incur penalties and may affect the borrower’s standing.</p>
                                    <h6 class="fw-bold">Early Payment Policy</h6>
                                    <p class="small text-muted">The borrower may settle the loan early subject to applicable fees and notice.</p>
                                    <h6 class="fw-bold">Default Policy</h6>
                                    <p class="small text-muted">Defaulting on the loan may result in further collection action as permitted by law.</p>
                                    <h6 class="fw-bold">Privacy Policy</h6>
                                    <p class="small text-muted">The company will safeguard borrower information in accordance with applicable policies.</p>
                                    <h6 class="fw-bold">Collection Policy</h6>
                                    <p class="small text-muted">The company may contact the borrower using the provided contact information for account management purposes.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h6 class="m-0 fw-bold"><i class="fas fa-pencil-alt me-2"></i>Digital Agreement</h6>
                        </div>
                        <div class="card-body">
                            <form method="post">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="read_agreement" required>
                                    <label class="form-check-label" for="read_agreement">I have read and understood the Loan Agreement.</label>
                                </div>
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" id="agree_terms" required>
                                    <label class="form-check-label" for="agree_terms">I agree to all Terms and Conditions.</label>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Signed By</label>
                                    <input type="text" class="form-control" name="signature" placeholder="Type your full name as digital signature" required>
                                </div>
                                <div class="alert alert-light border rounded-4">
                                    <div class="fw-semibold mb-2">Digital Signature</div>
                                    <div class="small text-muted">Your signature will be recorded as the borrower’s legal acknowledgment.</div>
                                </div>
                                <div class="d-flex flex-wrap gap-2 mt-4">
                                    <button type="submit" name="action" value="accept" class="btn btn-success" id="acceptAgreementBtn" disabled><i class="fas fa-check me-2"></i>Accept Loan Agreement</button>
                                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#declineModal"><i class="fas fa-times me-2"></i>Decline Agreement</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4">
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h6 class="m-0 fw-bold"><i class="fas fa-wallet me-2"></i>Payment Summary</h6>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between mb-2"><span>Loan Amount</span><strong>₱<?php echo number_format($loanAmount, 2); ?></strong></div>
                            <div class="d-flex justify-content-between mb-2"><span>Interest</span><strong>₱<?php echo number_format($totalInterest, 2); ?></strong></div>
                            <div class="d-flex justify-content-between mb-2"><span>Payment Frequency</span><strong><?php echo htmlspecialchars($paymentFrequencyDisplay); ?></strong></div>
                            <div class="d-flex justify-content-between mb-2"><span>Installment Amount</span><strong>₱<?php echo number_format($scheduledInstallmentAmount, 2); ?></strong></div>
                            <div class="d-flex justify-content-between"><span>Total Amount Payable</span><strong>₱<?php echo number_format($totalPayable, 2); ?></strong></div>
                        </div>
                    </div>

                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h6 class="m-0 fw-bold"><i class="fas fa-calendar-alt me-2"></i>Repayment Schedule</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Due Date</th>
                                            <th>Total Due</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($installments as $installment): ?>
                                            <tr>
                                                <td><?php echo (int)$installment['number']; ?></td>
                                                <td><?php echo htmlspecialchars($installment['due_date']); ?></td>
                                                <td>₱<?php echo htmlspecialchars($installment['total_due']); ?></td>
                                                <td><span class="badge bg-secondary">Upcoming</span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="modal fade" id="declineModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-times-circle me-2"></i>Decline Loan Agreement</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <label class="form-label">Reason for declining</label>
                        <textarea class="form-control" name="decline_reason" rows="4" required></textarea>
                        <input type="hidden" name="action" value="decline">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Confirm Decline</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const readAgreement = document.getElementById('read_agreement');
            const agreeTerms = document.getElementById('agree_terms');
            const signatureInput = document.querySelector('input[name="signature"]');
            const acceptButton = document.getElementById('acceptAgreementBtn');

            function toggleAcceptButton() {
                const canSubmit = readAgreement && agreeTerms && signatureInput && readAgreement.checked && agreeTerms.checked && signatureInput.value.trim() !== '';
                if (acceptButton) {
                    acceptButton.disabled = !canSubmit;
                }
            }

            [readAgreement, agreeTerms, signatureInput].forEach(function (field) {
                if (field) {
                    field.addEventListener('change', toggleAcceptButton);
                    field.addEventListener('input', toggleAcceptButton);
                }
            });
        });
    </script>
</body>
</html>
