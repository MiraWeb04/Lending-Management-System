<?php
/**
 * Loan Details Page for Lending Management System
 */

// Include authentication functions
require_once 'includes/auth_lending.php';

// Require login to access this page
requireStaff();

// Get current user data
$user = getCurrentUser();

// Include database connection
require_once 'includes/db_lending.php';
require_once 'includes/loan_helpers.php';
require_once 'includes/ui_helpers.php';

// Initialize variables
$loan = null;
$client = null;
$payments = [];
$message = '';
$messageType = '';

// Check if loan ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: loans_lending.php');
    exit;
}

$loanId = (int)$_GET['id'];

if (isCollector() && !ensureCollectorCanAccessLoan($loanId, $user['user_id'])) {
    denyCollectorAccess('You do not have permission to view this loan.');
}

// Check for messages passed via URL
if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = $_GET['message'];
    $messageType = $_GET['type'];
}

// Get loan details with client information
$loanQuery = "
    SELECT l.*, c.first_name, c.last_name, c.contact, c.email, c.address, r.payment_frequency AS release_payment_frequency, r.loan_term AS release_loan_term
    FROM loans l
    JOIN clients c ON l.client_id = c.client_id
    LEFT JOIN loan_releases r ON r.id = l.release_id
    LEFT JOIN loan_applications la ON la.id = r.application_id
    WHERE l.loan_id = ?";
$loanParams = [$loanId];

if (isBorrower()) {
    $loanQuery .= " AND (c.user_id = ? OR r.borrower_user_id = ? OR la.user_id = ?)";
    $loanParams[] = $user['user_id'];
    $loanParams[] = $user['user_id'];
    $loanParams[] = $user['user_id'];
}

$loanQuery .= "
    LIMIT 1
";
$loanResult = executeQuery($loanQuery, $loanParams);
$loan = $loanResult->fetch(PDO::FETCH_ASSOC);

// Redirect if loan not found
if (!$loan) {
    header('Location: loans_lending.php');
    exit;
}

$paymentScheduleQuery = "SELECT MIN(due_date) AS first_payment_date, MAX(due_date) AS final_due_date FROM loan_payment_schedules WHERE release_id = ? LIMIT 1";
$paymentScheduleResult = executeQuery($paymentScheduleQuery, [(int)($loan['release_id'] ?? 0)]);
$paymentScheduleInfo = $paymentScheduleResult->fetch(PDO::FETCH_ASSOC);

$paymentFrequency = resolvePaymentFrequency(
    $loan['payment_frequency'] ?? null,
    $loan['release_payment_frequency'] ?? null,
    $loan['date_released'] ?? null,
    $loan['due_date'] ?? null
);
$loanTermMonths = resolveLoanTermMonths(
    $loan['loan_term'] ?? ($loan['release_loan_term'] ?? null),
    $loan['date_released'] ?? null,
    $loan['due_date'] ?? null
);
$storedEstimateAmount = isset($loan['daily_payment']) && $loan['daily_payment'] !== null && $loan['daily_payment'] !== ''
    ? (float)$loan['daily_payment']
    : 0.0;
$scheduledInstallmentAmount = $storedEstimateAmount > 0
    ? round($storedEstimateAmount, 2)
    : calculateAmountToCollect(
        (float)($loan['total_payable'] ?? 0),
        $paymentFrequency,
        $loanTermMonths,
        null,
        $loan['date_released'] ?? null,
        $loan['due_date'] ?? null
    );
$numberOfPayments = getNumberOfPayments(
    $paymentFrequency,
    $loanTermMonths,
    $loan['date_released'] ?? null,
    $loan['due_date'] ?? null
);
$firstPaymentDate = trim((string)($paymentScheduleInfo['first_payment_date'] ?? ''));
$estimatedDueDate = trim((string)($paymentScheduleInfo['final_due_date'] ?? $loan['due_date'] ?? ''));

// Get payment history for this loan
$paymentsQuery = "SELECT * FROM payments WHERE loan_id = ? ORDER BY payment_date DESC, payment_id DESC";
$paymentsResult = executeQuery($paymentsQuery, [$loanId]);
$payments = $paymentsResult->fetchAll();

// Calculate total paid amount
$totalPaid = 0;
foreach ($payments as $payment) {
    $totalPaid += $payment['amount_paid'];
}

$balanceSummary = calculateLoanBalanceSummary($loanId);
$remainingBalance = (float)($balanceSummary['remaining_balance'] ?? ($loan['total_payable'] - $totalPaid));
$penaltyDue = (float)($balanceSummary['penalty_due'] ?? 0);
$interestBreakdown = calculateLoanInterestBreakdown(
    (float)($loan['loan_amount'] ?? 0),
    getStandardMonthlyInterestRatePercent(),
    $loanTermMonths
);
$penaltyRatePercent = getPenaltyRatePercentForFrequency($paymentFrequency);

// Calculate payment progress percentage
$progressPercentage = ($totalPaid / $loan['total_payable']) * 100;
$progressPercentage = min(100, max(0, $progressPercentage)); // Ensure between 0-100

// Format currency values
$formattedLoanAmount = number_format($loan['loan_amount'], 2);
$formattedTotalPayable = number_format($loan['total_payable'], 2);
$formattedScheduledPayment = number_format($scheduledInstallmentAmount, 2);
$formattedTotalPaid = number_format($totalPaid, 2);
$formattedRemainingBalance = number_format($remainingBalance, 2);

// Calculate days elapsed and days remaining
$dateReleased = new DateTime($loan['date_released']);
$dueDate = new DateTime($loan['due_date']);
$today = new DateTime();

$totalDays = $dateReleased->diff($dueDate)->days;
$daysElapsed = $dateReleased->diff($today)->days;
$daysElapsed = max(0, min($daysElapsed, $totalDays)); // Ensure between 0 and totalDays
$daysRemaining = max(0, $totalDays - $daysElapsed);

// Calculate expected payment by now based on the borrower's saved payment frequency
$expectedPayment = 0.0;
if ($numberOfPayments > 0) {
    switch (normalizePaymentFrequencyKey($paymentFrequency)) {
        case 'daily':
            $expectedPayment = $scheduledInstallmentAmount * max(0, $daysElapsed);
            break;
        case 'weekly':
            $expectedPayment = $scheduledInstallmentAmount * max(0, (int)floor($daysElapsed / 7));
            break;
        case 'semi-monthly':
            $expectedPayment = $scheduledInstallmentAmount * max(0, (int)floor($daysElapsed / 15));
            break;
        case 'monthly':
        default:
            $expectedPayment = $scheduledInstallmentAmount * max(0, (int)floor($daysElapsed / 30));
            break;
    }
}
$paymentStatus = ($totalPaid >= $expectedPayment) ? 'On Track' : 'Behind';

$paymentFormDefaults = [
    'amount_paid' => number_format(calculateSuggestedPaymentReceived($loanId, $balanceSummary), 2, '.', ''),
    'penalty_paid' => number_format($penaltyDue > 0 ? $penaltyDue : 0, 2, '.', ''),
    'payment_date' => date('Y-m-d'),
    'payment_method' => 'Cash',
    'collector_name' => trim((string)($user['full_name'] ?? $user['username'] ?? 'Unknown')),
    'remarks' => '',
];

// Process payment form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_payment'])) {
    csrfRequireValid();

    if (!isAdmin() && !isCollector()) {
        http_response_code(403);
        exit('Only administrators and collectors can record payments.');
    }

    $paymentAmount = (float)($_POST['amount_paid'] ?? 0);
    $penaltyPaid = (float)($_POST['penalty_paid'] ?? 0);
    $paymentDate = trim((string)($_POST['payment_date'] ?? date('Y-m-d')));
    $collectorName = isCollector()
        ? trim((string)($user['full_name'] ?? $user['username'] ?? 'Unknown'))
        : trim((string)($_POST['collector_name'] ?? ''));
    $paymentMethod = trim((string)($_POST['payment_method'] ?? ''));
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    $paymentFormDefaults = [
        'amount_paid' => (string)($_POST['amount_paid'] ?? ''),
        'penalty_paid' => (string)($_POST['penalty_paid'] ?? '0'),
        'payment_date' => $paymentDate,
        'payment_method' => $paymentMethod,
        'collector_name' => $collectorName,
        'remarks' => $remarks,
    ];

    if ($collectorName === '') {
        $message = 'Collector name is required.';
        $messageType = 'danger';
    } elseif ($paymentMethod === '') {
        $message = 'Payment method is required.';
        $messageType = 'danger';
    } elseif ($paymentAmount <= 0) {
        $message = 'Payment amount must be greater than zero.';
        $messageType = 'danger';
    } else {
        $balanceSummary = calculateLoanBalanceSummary($loanId);
        $remainingBalance = (float)($balanceSummary['remaining_balance'] ?? 0);
        $penaltyDue = (float)($balanceSummary['penalty_due'] ?? 0);
        $maxTotalPayment = (float)($balanceSummary['total_amount_due'] ?? $remainingBalance);

        $effectivePenalty = min($penaltyPaid, $penaltyDue);
        if ($penaltyPaid > $paymentAmount + 0.009) {
            $message = 'Penalty portion cannot exceed total payment received.';
            $messageType = 'danger';
        } elseif ($paymentAmount > $maxTotalPayment + 0.009) {
            $message = 'Payment amount cannot exceed the total amount due of ₱' . number_format($maxTotalPayment, 2) . ' (balance + penalties).';
            $messageType = 'danger';
        } elseif (($paymentAmount - $effectivePenalty) > $remainingBalance + 0.009) {
            $message = 'Installment portion cannot exceed the remaining loan balance of ₱' . number_format($remainingBalance, 2) . '.';
            $messageType = 'danger';
        } else {
            try {
                $clientId = (int)($loan['client_id'] ?? 0);
                $collectorId = isCollector() ? (int)$user['user_id'] : null;
                recordPaymentTransaction($loanId, $paymentDate, $paymentAmount, $collectorName, $collectorId, $clientId, $paymentMethod, null, $penaltyPaid, $remarks);

                header('Location: loan_details_lending.php?id=' . $loanId . '&message=' . urlencode('Payment recorded successfully.') . '&type=success');
                exit;
            } catch (Exception $e) {
                $message = 'Error recording payment: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
}

$totalPenaltyPaid = 0.0;
foreach ($payments as $paymentRow) {
    $totalPenaltyPaid += (float)($paymentRow['penalty_paid'] ?? 0);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/lending_assets.php'; lendingRenderPwaMeta(); ?>
    <title>Loan Details - Lending Management System</title>
    <!-- Bootstrap CSS -->
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/lending_styles.css">
    <link rel="stylesheet" href="css/pwa.css">
</head>
<body>
    <?php include 'includes/nav_lending.php'; ?>

    <div class="app-content py-2">
        <div class="dashboard-header">
            <div>
                <div class="dashboard-breadcrumb"><a href="loans_lending.php">Loans</a> / Loan #<?php echo (int)$loan['loan_id']; ?></div>
                <h1 class="page-title mb-1"><i class="fas fa-file-invoice-dollar me-2"></i>Loan Overview</h1>
                <p class="page-subtitle mb-0"><?php echo htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']); ?> · <?php echo renderLoanStatusPill((string)$loan['status']); ?></p>
            </div>
            <div class="quick-actions">
                <?php if ($loan['status'] !== 'Paid' && (isAdmin() || isCollector())): ?>
                <a href="payments_lending.php?add=true&loan=<?php echo $loanId; ?>&amount=<?php echo rawurlencode(number_format($scheduledInstallmentAmount, 2, '.', '')); ?>&borrower=<?php echo rawurlencode($loan['first_name'] . ' ' . $loan['last_name']); ?>" class="btn btn-success btn-sm"><i class="fas fa-cash-register me-1"></i> Record Payment</a>
                <?php endif; ?>
                <a href="client_details_lending.php?id=<?php echo $loan['client_id']; ?>" class="btn btn-outline-primary btn-sm"><i class="fas fa-user me-1"></i> Borrower Profile</a>
            </div>
        </div>

        <div class="kpi-grid mb-4">
            <div class="kpi-card kpi-card--primary">
                <div class="kpi-card__top"><div class="kpi-card__label">Principal</div><div class="kpi-card__icon"><i class="fas fa-peso-sign"></i></div></div>
                <div class="kpi-card__value">₱<?php echo $formattedLoanAmount; ?></div>
            </div>
            <div class="kpi-card kpi-card--info">
                <div class="kpi-card__top"><div class="kpi-card__label">Total Payable</div><div class="kpi-card__icon"><i class="fas fa-calculator"></i></div></div>
                <div class="kpi-card__value">₱<?php echo $formattedTotalPayable; ?></div>
            </div>
            <div class="kpi-card kpi-card--success">
                <div class="kpi-card__top"><div class="kpi-card__label">Amount Paid</div><div class="kpi-card__icon"><i class="fas fa-circle-check"></i></div></div>
                <div class="kpi-card__value">₱<?php echo $formattedTotalPaid; ?></div>
                <div class="kpi-card__meta"><?php echo number_format($progressPercentage, 1); ?>% complete</div>
            </div>
            <div class="kpi-card kpi-card--warning">
                <div class="kpi-card__top"><div class="kpi-card__label">Remaining</div><div class="kpi-card__icon"><i class="fas fa-scale-unbalanced"></i></div></div>
                <div class="kpi-card__value">₱<?php echo $formattedRemainingBalance; ?></div>
                <?php if ($penaltyDue > 0): ?><div class="kpi-card__meta">Penalty due ₱<?php echo number_format($penaltyDue, 2); ?></div><?php endif; ?>
            </div>
        </div>

        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <div class="loan-overview-grid mb-4">
            <div class="panel-card panel-card--flush">
                <div class="chart-panel__header"><h6 class="m-0 fw-bold"><i class="fas fa-file-contract me-2"></i>Loan Information</h6></div>
                <div class="chart-panel__body">
                        <table class="detail-table">
                            <tr>
                                <th>Loan ID</th>
                                <td>#<?php echo $loan['loan_id']; ?></td>
                            </tr>
                            <tr>
                                <th>Loan Term</th>
                                <td><?php echo htmlspecialchars(formatLoanTermLabel($loan['loan_term'] ?? ($loan['release_loan_term'] ?? null), $loan['date_released'] ?? null, $loan['due_date'] ?? null)); ?></td>
                            </tr>
                            <tr>
                                <th>Interest Rate</th>
                                <td><?php echo $loan['interest']; ?>% per month</td>
                            </tr>
                            <tr>
                                <th>Monthly Interest</th>
                                <td>₱<?php echo number_format($interestBreakdown['monthly_interest'], 2); ?></td>
                            </tr>
                            <tr>
                                <th>Total Interest</th>
                                <td>₱<?php echo number_format($interestBreakdown['total_interest'], 2); ?></td>
                            </tr>
                            <tr>
                                <th>Overdue Penalty Due</th>
                                <td>₱<?php echo number_format($penaltyDue, 2); ?> <span class="text-muted small">(<?php echo number_format($penaltyRatePercent, 2); ?>% · <?php echo htmlspecialchars(formatPaymentFrequencyLabel($paymentFrequency)); ?>)</span></td>
                            </tr>
                            <tr>
                                <th>Payment Frequency</th>
                                <td><?php echo htmlspecialchars(formatPaymentFrequencyLabel($paymentFrequency)); ?></td>
                            </tr>
                            <tr>
                                <th>Installments</th>
                                <td><?php echo htmlspecialchars((string)$numberOfPayments); ?> · ₱<?php echo $formattedScheduledPayment; ?> each</td>
                            </tr>
                            <tr>
                                <th>First Payment</th>
                                <td><?php echo $firstPaymentDate ? htmlspecialchars(date('F d, Y', strtotime($firstPaymentDate))) : 'N/A'; ?></td>
                            </tr>
                            <tr>
                                <th>Final Due Date</th>
                                <td><?php echo $estimatedDueDate ? htmlspecialchars(date('F d, Y', strtotime($estimatedDueDate))) : 'N/A'; ?></td>
                            </tr>
                            <tr>
                                <th>Date Released</th>
                                <td><?php echo date('F d, Y', strtotime($loan['date_released'])); ?></td>
                            </tr>
                            <tr>
                                <th>Status</th>
                                <td><?php echo renderLoanStatusPill((string)$loan['status']); ?></td>
                            </tr>
                        </table>
                </div>
            </div>

            <div class="panel-card panel-card--flush">
                <div class="chart-panel__header"><h6 class="m-0 fw-bold"><i class="fas fa-user me-2"></i>Borrower &amp; Progress</h6></div>
                <div class="chart-panel__body">
                        <table class="detail-table mb-4">
                            <tr>
                                <th>Borrower</th>
                                <td>
                                    <a href="client_details_lending.php?id=<?php echo $loan['client_id']; ?>">
                                        <?php echo htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']); ?>
                                    </a>
                                </td>
                            </tr>
                            <tr>
                                <th>Contact</th>
                                <td><?php echo htmlspecialchars($loan['contact']); ?></td>
                            </tr>
                            <tr>
                                <th>Email</th>
                                <td><?php echo htmlspecialchars($loan['email'] ?? 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <th>Address</th>
                                <td><?php echo htmlspecialchars($loan['address']); ?></td>
                            </tr>
                        </table>

                        <h6 class="fw-bold mb-2">Payment Progress</h6>
                        <div class="mb-2 d-flex justify-content-between">
                            <span>₱<?php echo $formattedTotalPaid; ?> of ₱<?php echo $formattedTotalPayable; ?> paid</span>
                            <span><?php echo number_format($progressPercentage, 1); ?>%</span>
                        </div>
                        <div class="progress-track mb-2" aria-hidden="true">
                            <div class="progress-track__fill" style="width: <?php echo $progressPercentage; ?>%;"></div>
                        </div>
                        <div class="small text-muted mb-4">Payment status: <strong><?php echo htmlspecialchars($paymentStatus); ?></strong> · Remaining ₱<?php echo $formattedRemainingBalance; ?><?php if ($penaltyDue > 0): ?> · Penalty due ₱<?php echo number_format($penaltyDue, 2); ?><?php endif; ?></div>
                        
                        <div class="stat-mini-grid">
                            <div class="stat-mini">
                                <div class="stat-mini__label">Performance</div>
                                <div class="stat-mini__value <?php echo ($paymentStatus === 'On Track') ? 'text-success' : 'text-warning'; ?>"><?php echo htmlspecialchars($paymentStatus); ?></div>
                            </div>
                            <div class="stat-mini">
                                <div class="stat-mini__label">Days Elapsed</div>
                                <div class="stat-mini__value"><?php echo (int)$daysElapsed; ?></div>
                            </div>
                            <div class="stat-mini">
                                <div class="stat-mini__label">Days Remaining</div>
                                <div class="stat-mini__value"><?php echo (int)$daysRemaining; ?></div>
                            </div>
                        </div>
                </div>
            </div>
        </div>

        <?php if ($loan['status'] !== 'Paid' && (isAdmin() || isCollector())): ?>
        <div class="panel-card panel-card--flush mb-4">
            <div class="chart-panel__header"><h6 class="m-0 fw-bold"><i class="fas fa-cash-register me-2"></i>Record Payment</h6></div>
            <div class="chart-panel__body">
                <div class="alert alert-light border small mb-3">
                    <div><strong>Remaining balance:</strong> ₱<?php echo $formattedRemainingBalance; ?></div>
                    <div><strong>Penalty due:</strong> ₱<?php echo number_format($penaltyDue, 2); ?> <span class="text-muted">(<?php echo number_format($penaltyRatePercent, 2); ?>% rate)</span></div>
                    <div><strong>Total amount due:</strong> ₱<?php echo number_format((float)($balanceSummary['total_amount_due'] ?? 0), 2); ?></div>
                </div>
                <form action="loan_details_lending.php?id=<?php echo (int)$loanId; ?>" method="post" class="needs-validation" novalidate>
                    <input type="hidden" name="add_payment" value="1">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="amount_paid" class="form-label">Total Payment Received <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" class="form-control" id="amount_paid" name="amount_paid" min="0.01" step="0.01" value="<?php echo htmlspecialchars($paymentFormDefaults['amount_paid']); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="penalty_paid" class="form-label">Penalty Portion</label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" class="form-control" id="penalty_paid" name="penalty_paid" min="0" step="0.01" value="<?php echo htmlspecialchars($paymentFormDefaults['penalty_paid']); ?>">
                            </div>
                            <div class="form-text">Overdue penalty due: ₱<?php echo number_format($penaltyDue, 2); ?>. Prefilled for overdue loans; adjust if needed or increase total payment when the borrower pays more.</div>
                        </div>
                        <div class="col-md-4">
                            <label for="payment_method" class="form-label">Payment Method <span class="text-danger">*</span></label>
                            <select class="form-select" id="payment_method" name="payment_method" required>
                                <option value="">Select method</option>
                                <option value="Cash" <?php echo $paymentFormDefaults['payment_method'] === 'Cash' ? 'selected' : ''; ?>>Cash</option>
                                <option value="Bank Transfer" <?php echo $paymentFormDefaults['payment_method'] === 'Bank Transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                <option value="E-Wallet" <?php echo $paymentFormDefaults['payment_method'] === 'E-Wallet' ? 'selected' : ''; ?>>E-Wallet</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="payment_date" class="form-label">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="payment_date" name="payment_date" value="<?php echo htmlspecialchars($paymentFormDefaults['payment_date']); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label for="collector_name" class="form-label">Collector Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="collector_name" name="collector_name" value="<?php echo htmlspecialchars($paymentFormDefaults['collector_name']); ?>" required>
                        </div>
                        <div class="col-12">
                            <label for="remarks" class="form-label">Remarks / Notes</label>
                            <textarea class="form-control" id="remarks" name="remarks" rows="2" placeholder="Optional payment notes"><?php echo htmlspecialchars($paymentFormDefaults['remarks']); ?></textarea>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i> Record Payment</button>
                            <a href="payments_lending.php?add=true&amp;loan=<?php echo (int)$loanId; ?>" class="btn btn-outline-secondary ms-2">Open full payments form</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="panel-card panel-card--flush mb-4">
            <div class="chart-panel__header"><h6 class="m-0 fw-bold"><i class="fas fa-clock-rotate-left me-2"></i>Payment History</h6></div>
            <div class="chart-panel__body pt-0 px-0 pb-0">
                <?php if (count($payments) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Penalty</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th>Collector</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                <td class="fw-semibold">₱<?php echo number_format((float)$payment['amount_paid'], 2); ?></td>
                                <td>₱<?php echo number_format((float)($payment['penalty_paid'] ?? 0), 2); ?></td>
                                <td><?php echo htmlspecialchars((string)($payment['payment_method'] ?? 'Cash')); ?></td>
                                <td><?php echo htmlspecialchars((string)($payment['reference_number'] ?? '—')); ?></td>
                                <td><?php echo htmlspecialchars($payment['collector_name']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th class="text-end">Total installment paid</th>
                                <th>₱<?php echo $formattedTotalPaid; ?></th>
                                <th class="text-end">Penalties collected</th>
                                <th colspan="3">₱<?php echo number_format($totalPenaltyPaid, 2); ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php else: ?>
                <div class="p-4">
                    <?php echo renderEmptyState('fa-receipt', 'No payments recorded yet', 'Payments will appear here once collections are posted.'); ?>
                    <?php if ($loan['status'] !== 'Paid' && (isAdmin() || isCollector())): ?>
                    <a href="payments_lending.php?add=true&loan=<?php echo $loanId; ?>" class="btn btn-primary">
                        <i class="fas fa-plus me-1"></i> Record First Payment
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel-card panel-card--flush mb-4">
            <div class="chart-panel__header"><h6 class="m-0 fw-bold"><i class="fas fa-calendar-days me-2"></i>Loan Timeline</h6></div>
            <div class="chart-panel__body">
                <div class="stat-mini-grid mb-3">
                    <div class="stat-mini"><div class="stat-mini__label">Duration</div><div class="stat-mini__value"><?php echo (int)$totalDays; ?> days</div></div>
                    <div class="stat-mini"><div class="stat-mini__label">Released</div><div class="stat-mini__value" style="font-size:0.95rem;"><?php echo date('M d, Y', strtotime($loan['date_released'])); ?></div></div>
                    <div class="stat-mini"><div class="stat-mini__label">Due</div><div class="stat-mini__value" style="font-size:0.95rem;"><?php echo date('M d, Y', strtotime($loan['due_date'])); ?></div></div>
                </div>
                <?php $timeProgress = min(100, max(0, ($daysElapsed / max(1, $totalDays)) * 100)); ?>
                <div class="mb-2 d-flex justify-content-between small text-muted"><span>Term progress</span><span><?php echo number_format($timeProgress, 1); ?>%</span></div>
                <div class="progress-track" role="progressbar" aria-valuenow="<?php echo $timeProgress; ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-track__fill" style="width: <?php echo $timeProgress; ?>%;"></div>
                </div>
            </div>
        </div>
    </div>

<?php include 'includes/nav_footer_lending.php'; ?>
    <script>
    (function () {
        'use strict'
        
        // Fetch all forms we want to apply validation styles to
        var forms = document.querySelectorAll('.needs-validation')
        
        // Loop over them and prevent submission
        Array.prototype.slice.call(forms).forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!form.checkValidity()) {
                    event.preventDefault()
                    event.stopPropagation()
                }
                
                form.classList.add('was-validated')
            }, false)
        })
    })()
    </script>
</body>
</html>
