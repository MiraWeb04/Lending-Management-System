<?php
/**
 * Loan Details Page for Lending Management System
 */

// Include authentication functions
require_once 'includes/auth_lending.php';

// Require login to access this page
requireLogin();

// Get current user data
$user = getCurrentUser();

// Include database connection
require_once 'includes/db_lending.php';
require_once 'includes/loan_helpers.php';

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

// Calculate remaining balance
$remainingBalance = $loan['total_payable'] - $totalPaid;

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

// Process payment form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_payment'])) {
    if (!isAdmin() && !isCollector()) {
        http_response_code(403);
        exit('Only administrators and collectors can record payments.');
    }

    $paymentAmount = (float)$_POST['amount_paid'];
    $paymentDate = $_POST['payment_date'];
    $collectorName = $_POST['collector_name'];
    
    // Validate payment amount
    if ($paymentAmount <= 0) {
        $message = "Payment amount must be greater than zero.";
        $messageType = 'danger';
    } else {
        try {
            $clientId = (int)($loan['client_id'] ?? 0);
            $collectorId = isCollector() ? (int)$user['user_id'] : null;
            recordPaymentTransaction($loanId, $paymentDate, $paymentAmount, $collectorName, $collectorId, $clientId);

            header("Location: loan_details_lending.php?id=$loanId&message=Payment+recorded+successfully&type=success");
            exit;
        } catch (Exception $e) {
            $message = "Error recording payment: " . $e->getMessage();
            $messageType = 'danger';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Details - Lending Management System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/lending_styles.css">
</head>
<body>
    <?php include 'includes/nav_lending.php'; ?>

    <!-- Main Content -->
    <div class="container-fluid py-4">
        <div class="row mb-4">
            <div class="col-md-6">
                <h1 class="h3 mb-0 text-gray-800">
                    <i class="fas fa-file-invoice-dollar me-2"></i>Loan Details
                </h1>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="loans_lending.php">Loans</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Loan #<?php echo $loan['loan_id']; ?></li>
                    </ol>
                </nav>
            </div>
            <div class="col-md-6 text-md-end">
                <?php if ($loan['status'] !== 'Paid' && (isAdmin() || isCollector())): ?>
                <a href="payments_lending.php?add=true&loan=<?php echo $loanId; ?>&amount=<?php echo rawurlencode(number_format($scheduledInstallmentAmount, 2, '.', '')); ?>&borrower=<?php echo rawurlencode($loan['first_name'] . ' ' . $loan['last_name']); ?>" class="btn btn-success btn-rounded btn-action-hover me-2" title="Record Payment" aria-label="Record Payment">
                    <i class="fas fa-cash-register me-1"></i> Record Payment
                </a>
                <?php endif; ?>
                <a href="client_details_lending.php?id=<?php echo $loan['client_id']; ?>" class="btn btn-primary btn-rounded btn-action-hover" title="View Client">
                    <i class="fas fa-user me-1"></i> View Client
                </a>
            </div>
        </div>

        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <!-- Loan Status Card -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold"><i class="fas fa-info-circle me-2"></i>Loan Information</h6>
                <div>
                    <?php if (strcasecmp($loan['status'], 'Active') === 0): ?>
                        <span class="badge status-badge bg-success">Active</span>
                    <?php elseif (strcasecmp($loan['status'], 'Paid') === 0): ?>
                        <span class="badge status-badge bg-info">Paid</span>
                    <?php elseif (strcasecmp($loan['status'], 'Overdue') === 0): ?>
                        <span class="badge status-badge bg-danger">Overdue</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <!-- Loan Details -->
                    <div class="col-lg-6">
                        <h5 class="mb-3">Loan Details</h5>
                        <table class="table table-bordered">
                            <tr>
                                <th class="table-light" width="40%">Loan ID</th>
                                <td><?php echo $loan['loan_id']; ?></td>
                            </tr>
                            <tr>
                                <th class="table-light">Loan Amount</th>
                                <td>₱<?php echo $formattedLoanAmount; ?></td>
                            </tr>
                            <tr>
                                <th class="table-light">Loan Term</th>
                                <td><?php echo htmlspecialchars(formatLoanTermLabel($loan['loan_term'] ?? ($loan['release_loan_term'] ?? null), $loan['date_released'] ?? null, $loan['due_date'] ?? null)); ?></td>
                            </tr>
                            <tr>
                                <th class="table-light">Interest Rate</th>
                                <td><?php echo $loan['interest']; ?>%</td>
                            </tr>
                            <tr>
                                <th class="table-light">Total Payable</th>
                                <td>₱<?php echo $formattedTotalPayable; ?></td>
                            </tr>
                            <tr>
                                <th class="table-light">Payment Frequency</th>
                                <td><?php echo htmlspecialchars(formatPaymentFrequencyLabel($paymentFrequency)); ?></td>
                            </tr>
                            <tr>
                                <th class="table-light">Number of Payments</th>
                                <td><?php echo htmlspecialchars((string)$numberOfPayments); ?></td>
                            </tr>
                            <tr>
                                <th class="table-light">Estimated Payment</th>
                                <td>₱<?php echo $formattedScheduledPayment; ?></td>
                            </tr>
                            <tr>
                                <th class="table-light">First Payment Date</th>
                                <td><?php echo $firstPaymentDate ? htmlspecialchars(date('F d, Y', strtotime($firstPaymentDate))) : 'N/A'; ?></td>
                            </tr>
                            <tr>
                                <th class="table-light">Estimated Due Date</th>
                                <td><?php echo $estimatedDueDate ? htmlspecialchars(date('F d, Y', strtotime($estimatedDueDate))) : 'N/A'; ?></td>
                            </tr>
                            <tr>
                                <th class="table-light">Date Released</th>
                                <td><?php echo date('F d, Y', strtotime($loan['date_released'])); ?></td>
                            </tr>
                            <tr>
                                <th class="table-light">Status</th>
                                <td>
                                    <?php if (strcasecmp($loan['status'], 'Active') === 0): ?>
                                        <span class="badge status-badge bg-success">Active</span>
                                    <?php elseif (strcasecmp($loan['status'], 'Paid') === 0): ?>
                                        <span class="badge status-badge bg-info">Paid</span>
                                    <?php elseif (strcasecmp($loan['status'], 'Overdue') === 0): ?>
                                        <span class="badge status-badge bg-danger">Overdue</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- Client Details -->
                    <div class="col-lg-6">
                        <h5 class="mb-3">Client Information</h5>
                        <table class="table table-bordered">
                            <tr>
                                <th class="table-light" width="40%">Client Name</th>
                                <td>
                                    <a href="client_details_lending.php?id=<?php echo $loan['client_id']; ?>">
                                        <?php echo htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']); ?>
                                    </a>
                                </td>
                            </tr>
                            <tr>
                                <th class="table-light">Contact Number</th>
                                <td><?php echo htmlspecialchars($loan['contact']); ?></td>
                            </tr>
                            <tr>
                                <th class="table-light">Email</th>
                                <td><?php echo htmlspecialchars($loan['email'] ?? 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <th class="table-light">Address</th>
                                <td><?php echo htmlspecialchars($loan['address']); ?></td>
                            </tr>
                        </table>
                        
                        <!-- Payment Progress -->
                        <h5 class="mt-4 mb-3">Payment Progress</h5>
                        <div class="mb-2 d-flex justify-content-between">
                            <span>₱<?php echo $formattedTotalPaid; ?> of ₱<?php echo $formattedTotalPayable; ?> paid</span>
                            <span><?php echo number_format($progressPercentage, 1); ?>%</span>
                        </div>
                        <div class="progress mb-4" style="height: 20px;">
                            <div class="progress-bar <?php echo ($loan['status'] === 'Paid') ? 'bg-success' : (($paymentStatus === 'On Track') ? 'bg-info' : 'bg-warning'); ?>" 
                                 role="progressbar" 
                                 style="width: <?php echo $progressPercentage; ?>%" 
                                 aria-valuenow="<?php echo $progressPercentage; ?>" 
                                 aria-valuemin="0" 
                                 aria-valuemax="100">
                                <?php echo number_format($progressPercentage, 1); ?>%
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <div class="card bg-light">
                                    <div class="card-body py-2">
                                        <h6 class="card-title mb-1">Remaining Balance</h6>
                                        <p class="card-text text-danger mb-0 h5">₱<?php echo $formattedRemainingBalance; ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="card bg-light">
                                    <div class="card-body py-2">
                                        <h6 class="card-title mb-1">Payment Status</h6>
                                        <p class="card-text mb-0 h5 <?php echo ($paymentStatus === 'On Track') ? 'text-success' : 'text-warning'; ?>">
                                            <?php echo $paymentStatus; ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment History -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="m-0 font-weight-bold"><i class="fas fa-history me-2"></i>Payment History</h6>
            </div>
            <div class="card-body">
                <?php if (count($payments) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Payment ID</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Collector</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?php echo $payment['payment_id']; ?></td>
                                <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                <td>₱<?php echo number_format($payment['amount_paid'], 2); ?></td>
                                <td><?php echo htmlspecialchars($payment['collector_name']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="2" class="text-end">Total Paid:</th>
                                <th>₱<?php echo $formattedTotalPaid; ?></th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-receipt text-muted fa-3x mb-3"></i>
                    <p>No payment records found for this loan.</p>
                    <?php if ($loan['status'] !== 'Paid' && (isAdmin() || isCollector())): ?>
                    <a href="payments_lending.php?add=true&loan=<?php echo $loanId; ?>" class="btn btn-primary">
                        <i class="fas fa-plus me-1"></i> Record First Payment
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Loan Timeline -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="m-0 font-weight-bold"><i class="fas fa-calendar-alt me-2"></i>Loan Timeline</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <div class="card bg-light">
                            <div class="card-body text-center py-3">
                                <h6 class="card-title">Total Duration</h6>
                                <p class="card-text h4 mb-0"><?php echo $totalDays; ?> Days</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card bg-light">
                            <div class="card-body text-center py-3">
                                <h6 class="card-title">Days Elapsed</h6>
                                <p class="card-text h4 mb-0"><?php echo $daysElapsed; ?> Days</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card bg-light">
                            <div class="card-body text-center py-3">
                                <h6 class="card-title">Days Remaining</h6>
                                <p class="card-text h4 mb-0"><?php echo $daysRemaining; ?> Days</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Timeline Progress -->
                <div class="mt-4">
                    <div class="mb-2 d-flex justify-content-between">
                        <span>Released: <?php echo date('M d, Y', strtotime($loan['date_released'])); ?></span>
                        <span>Due: <?php echo date('M d, Y', strtotime($loan['due_date'])); ?></span>
                    </div>
                    <div class="progress" style="height: 20px;">
                        <?php $timeProgress = min(100, max(0, ($daysElapsed / $totalDays) * 100)); ?>
                        <div class="progress-bar bg-primary" 
                             role="progressbar" 
                             style="width: <?php echo $timeProgress; ?>%" 
                             aria-valuenow="<?php echo $timeProgress; ?>" 
                             aria-valuemin="0" 
                             aria-valuemax="100">
                            <?php echo number_format($timeProgress, 1); ?>%
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- Footer -->
<?php include 'includes/nav_footer_lending.php'; ?>

        <div class="container-fluid px-4">
            <div class="d-flex align-items-center justify-content-between small">
                <div class="text-muted">Copyright &copy; Lending Management System <?php echo date('Y'); ?></div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Form Validation Script -->
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
