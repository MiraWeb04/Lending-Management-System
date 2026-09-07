<?php
/**
 * Borrower My Loan page - shows released loan details and repayment schedule.
 */

require_once 'includes/auth_lending.php';
require_once 'includes/db_lending.php';
require_once 'includes/loan_helpers.php';

if (!isLoggedIn() || !isBorrower()) {
    header('Location: borrower_login_lending.php');
    exit;
}

$user = getCurrentUser();

$client = executeQuery('SELECT client_id, first_name, last_name, contact, email FROM clients WHERE user_id = ? LIMIT 1', [$user['user_id']])->fetch(PDO::FETCH_ASSOC);

$loan = null;
$loanHistory = [];
$loanPayments = [];
$paymentSummary = ['total_paid' => 0, 'payment_count' => 0];
$collector = null;
$nextDueDate = null;
$remainingBalance = 0;
$paymentSchedule = [];
$totalCalculatedInstallments = 0;
$remainingInstallments = 0;
$today = new DateTimeImmutable('today');

if ($client) {
    $loanHistory = executeQuery(
        'SELECT l.*, r.loan_number, r.payment_frequency, r.released_at AS release_date, r.application_id,
                (SELECT la.loan_purpose FROM loan_applications la WHERE la.id = r.application_id LIMIT 1) AS loan_purpose,
                (SELECT COALESCE(SUM(p.amount_paid), 0) FROM payments p WHERE p.loan_id = l.loan_id) AS total_paid,
                (SELECT MAX(p.payment_date) FROM payments p WHERE p.loan_id = l.loan_id LIMIT 1) AS fully_paid_date
         FROM loans l
         INNER JOIN clients c ON c.client_id = l.client_id
         LEFT JOIN loan_releases r ON l.release_id = r.id
         LEFT JOIN loan_applications la ON la.id = r.application_id
         WHERE c.user_id = ? OR r.borrower_user_id = ? OR la.user_id = ?
         ORDER BY l.date_released DESC, l.loan_id DESC',
        [$user['user_id'], $user['user_id'], $user['user_id']]
    )->fetchAll(PDO::FETCH_ASSOC);

    $activeStatuses = ['active', 'overdue', 'approved', 'released', 'disbursed', 'pending', 'under review'];
    $completedStatuses = ['paid', 'completed', 'closed'];

    foreach ($loanHistory as $loanCandidate) {
        $candidateStatus = strtolower(trim((string)($loanCandidate['status'] ?? '')));
        if (!in_array($candidateStatus, $completedStatuses, true) && ($candidateStatus === '' || in_array($candidateStatus, $activeStatuses, true))) {
            $loan = $loanCandidate;
            break;
        }
    }

    if ($loan) {
        $paymentSummary = executeQuery('SELECT COALESCE(SUM(amount_paid), 0) AS total_paid, COUNT(*) AS payment_count FROM payments WHERE loan_id = ?', [$loan['loan_id']])->fetch(PDO::FETCH_ASSOC);
        $paymentSummary['total_paid'] = (float)($paymentSummary['total_paid'] ?? 0);
        $paymentSummary['payment_count'] = (int)($paymentSummary['payment_count'] ?? 0);
        $remainingBalance = max(0, (float)$loan['total_payable'] - $paymentSummary['total_paid']);

        $loanPayments = executeQuery('SELECT payment_date, amount_paid, payment_id FROM payments WHERE loan_id = ? ORDER BY payment_date ASC, payment_id ASC', [$loan['loan_id']])->fetchAll(PDO::FETCH_ASSOC);
        $paymentSchedule = executeQuery('SELECT installment_number, due_date, principal_amount, interest_amount, total_amount_due, status FROM loan_payment_schedules WHERE release_id = ? ORDER BY installment_number ASC', [(int)$loan['release_id']])->fetchAll(PDO::FETCH_ASSOC);
        $totalCalculatedInstallments = count($paymentSchedule);
        // Calculate remaining installments based on unpaid schedule entries.
        $remainingInstallments = 0;
        if (!empty($paymentSchedule)) {
            foreach ($paymentSchedule as $s) {
                $status = strtolower(trim((string)($s['status'] ?? '')));
                if ($status !== 'paid') {
                    $remainingInstallments++;
                }
            }
        }

        if (!empty($paymentSchedule)) {
            $loan['due_date'] = $paymentSchedule[count($paymentSchedule) - 1]['due_date'];
        }

        $nextDueDateRow = executeQuery('SELECT due_date FROM loan_payment_schedules WHERE release_id = ? AND status != ? ORDER BY due_date ASC LIMIT 1', [(int)$loan['release_id'], 'Paid'])->fetch(PDO::FETCH_ASSOC);
        $nextDueDate = $nextDueDateRow ? $nextDueDateRow['due_date'] : null;

        if (!empty($loan['collector_id'])) {
            $collector = executeQuery('SELECT user_id, full_name, email FROM users WHERE user_id = ? LIMIT 1', [(int)$loan['collector_id']])->fetch(PDO::FETCH_ASSOC);
        }
    }
}

function determineScheduleStatus(array $schedule, array &$loanPayments, DateTimeImmutable $today): string {
    $dueDate = (new DateTimeImmutable($schedule['due_date']))->setTime(0, 0, 0);
    $totalDue = (float)($schedule['total_amount_due'] ?? 0);
    $paidAmount = 0.0;
    $paidDate = null;
    $paymentIndex = 0;

    while ($paymentIndex < count($loanPayments) && $paidAmount < $totalDue) {
        $payment = $loanPayments[$paymentIndex];
        $paymentAmount = (float)($payment['amount_paid'] ?? 0);

        if ($paymentAmount <= 0) {
            $paymentIndex++;
            continue;
        }

        $amountToApply = min($paymentAmount, $totalDue - $paidAmount);
        $paidAmount += $amountToApply;

        if ($paidAmount >= $totalDue && $paidDate === null && !empty($payment['payment_date'])) {
            $paidDate = (new DateTimeImmutable($payment['payment_date']))->setTime(0, 0, 0);
        }

        $remainingPaymentAmount = $paymentAmount - $amountToApply;
        if ($remainingPaymentAmount > 0) {
            $loanPayments[$paymentIndex]['amount_paid'] = $remainingPaymentAmount;
        } else {
            $paymentIndex++;
        }
    }

    if ($paidAmount >= $totalDue) {
        if ($paidDate) {
            return $paidDate <= $dueDate ? 'Paid' : 'Overdue';
        }

        return 'Paid';
    }

    return $today <= $dueDate ? 'Pending' : 'Overdue';
}

function formatCurrency($value) {
    return '₱' . number_format((float)$value, 2);
}

function formatDate($date) {
    return $date ? date('M d, Y', strtotime($date)) : 'N/A';
}

function statusBadgeClass(string $status): string {
    $status = strtolower(trim($status));

    if ($status === 'paid') {
        return 'bg-success';
    }

    if ($status === 'overdue') {
        return 'bg-danger';
    }

    if ($status === 'closed' || $status === 'completed') {
        return 'bg-secondary';
    }

    return 'bg-info text-dark';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Loan - RJ and RR Finance Services</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/lending_styles.css">
</head>
<body>
    <?php include 'includes/nav_lending.php'; ?>

    <div class="container-fluid py-4">
        <div class="page-hero collector-hero mb-4">
            <div class="d-flex align-items-center gap-3">
                <div class="collector-hero__logo">
                    <img src="images/logo.png" alt="RJ and RR Finance Services logo">
                </div>
                <div>
                    <h1 class="page-title"><i class="fas fa-file-invoice-dollar me-2"></i>My Loan</h1>
                    <p class="page-subtitle">View your active loan details, payment summary, and repayment schedule.</p>
                </div>
            </div>
        </div>

        <?php if (!$client || !$loan): ?>
            <div class="card shadow-sm">
                <div class="card-body text-center py-5">
                    <div class="login-logo mx-auto mb-3">
                        <img src="images/logo.png" alt="RJ and RR Finance Services logo">
                    </div>
                    <h4 class="fw-bold text-primary">No Active Loan Found</h4>
                    <p class="text-muted mb-4">You currently do not have an active released loan linked to your account. You can still review your loan history below.</p>
                    <div class="d-flex justify-content-center gap-3 flex-wrap">
                        <a href="borrower_loan_status_lending.php" class="btn btn-outline-primary">Loan Status</a>
                        <a href="borrower_loan_application_lending.php" class="btn btn-primary">Apply for Loan</a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-4 mb-4">
                <div class="col-xl-8">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
                            <div>
                                <h5 class="m-0 fw-bold">Loan Overview</h5>
                                <p class="text-muted mb-0">Your latest released loan and repayment details.</p>
                            </div>
                            <span class="badge bg-<?php echo strtolower($loan['status'] === 'Paid' ? 'success' : ($loan['status'] === 'Overdue' ? 'danger' : 'info')); ?> text-white fs-6 py-2 px-3"><?php echo htmlspecialchars($loan['status']); ?></span>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="consent-card h-100">
                                        <div class="text-muted small">Loan Number</div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($loan['loan_number'] ?? 'N/A'); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="consent-card h-100">
                                        <div class="text-muted small">Payment Frequency</div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars(!empty($loan['payment_frequency']) ? formatPaymentFrequencyLabel($loan['payment_frequency']) : ($loan['daily_payment'] ? 'Daily' : 'N/A')); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="consent-card h-100">
                                        <div class="text-muted small">Released Amount</div>
                                        <div class="fw-semibold"><?php echo formatCurrency($loan['loan_amount']); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="consent-card h-100">
                                        <div class="text-muted small">Total Payable</div>
                                        <div class="fw-semibold"><?php echo formatCurrency($loan['total_payable']); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="consent-card h-100">
                                        <div class="text-muted small">Amount Paid</div>
                                        <div class="fw-semibold"><?php echo formatCurrency($paymentSummary['total_paid']); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="consent-card h-100">
                                        <div class="text-muted small">Remaining Balance</div>
                                        <div class="fw-semibold"><?php echo formatCurrency($remainingBalance); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="consent-card h-100">
                                        <div class="text-muted small">Loan Released On</div>
                                        <div class="fw-semibold"><?php echo formatDate($loan['date_released'] ?? $loan['release_date']); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="consent-card h-100">
                                        <div class="text-muted small">Loan Due Date</div>
                                        <div class="fw-semibold"><?php echo formatDate($loan['due_date']); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="consent-card h-100">
                                        <div class="text-muted small">Next Payment Due</div>
                                        <div class="fw-semibold"><?php echo $nextDueDate ? formatDate($nextDueDate) : 'All payments completed'; ?></div>
                                    </div>
                                </div>
                                <?php if ($collector): ?>
                                    <div class="col-md-6">
                                        <div class="consent-card h-100">
                                            <div class="text-muted small">Assigned Collector</div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($collector['full_name'] ?? 'N/A'); ?></div>
                                            <div class="text-muted small mt-1"><?php echo htmlspecialchars($collector['email'] ?? ''); ?></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white py-3">
                            <h6 class="m-0 fw-bold"><i class="fas fa-chart-pie me-2"></i>Payment Summary</h6>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <div class="text-muted small">Payments Recorded</div>
                                <div class="fw-semibold"><?php echo $paymentSummary['payment_count']; ?></div>
                            </div>
                            <div class="mb-3">
                                <div class="text-muted small">Latest Loan Status</div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($loan['status']); ?></div>
                            </div>
                            <div class="mb-3">
                                <div class="text-muted small">Remaining Installments</div>
                                <div class="fw-semibold"><?php echo $remainingInstallments; ?></div>
                            </div>
                            <a href="payments_lending.php" class="btn btn-primary w-100">View Payment History</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="m-0 fw-bold"><i class="fas fa-calendar-alt me-2"></i>Repayment Schedule</h6>
                            <p class="text-muted mb-0">The schedule created during loan release is shown below.</p>
                        </div>
                        <span class="badge bg-secondary text-white">Total <?php echo count($paymentSchedule); ?> installments</span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Due Date</th>
                                    <th>Principal</th>
                                    <th>Interest</th>
                                    <th>Total Due</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($paymentSchedule)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted">No repayment schedule found for this loan.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($paymentSchedule as $schedule): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($schedule['installment_number']); ?></td>
                                            <td><?php echo formatDate($schedule['due_date']); ?></td>
                                            <td><?php echo formatCurrency($schedule['principal_amount']); ?></td>
                                            <td><?php echo formatCurrency($schedule['interest_amount']); ?></td>
                                            <td><?php echo formatCurrency($schedule['total_amount_due']); ?></td>
                                            <td>
                                                <span class="badge <?php echo strtolower(trim($schedule['status'])) === 'paid' ? 'bg-success' : (strtolower(trim($schedule['status'])) === 'overdue' ? 'bg-danger' : 'bg-info text-dark'); ?>">
                                                    <?php echo htmlspecialchars($schedule['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h6 class="m-0 fw-bold"><i class="fas fa-history me-2"></i>Loan History</h6>
                        <p class="text-muted mb-0">Browse all your logged loans, from newest to oldest. Select a previous loan to open its complete details.</p>
                    </div>
                    <span class="badge bg-secondary text-white"><?php echo count($loanHistory); ?> record<?php echo count($loanHistory) === 1 ? '' : 's'; ?></span>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($loanHistory)): ?>
                    <div class="p-4 text-center text-muted">No loan history found.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Loan ID</th>
                                    <th>Loan Amount</th>
                                    <th>Loan Purpose</th>
                                    <th>Approval Date</th>
                                    <th>Due Date</th>
                                    <th>Fully Paid Date</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($loanHistory as $historyLoan): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)($historyLoan['loan_id'] ?? 'N/A')); ?></td>
                                        <td><?php echo formatCurrency($historyLoan['loan_amount'] ?? 0); ?></td>
                                        <td><?php echo htmlspecialchars(trim((string)($historyLoan['loan_purpose'] ?? 'N/A'))); ?></td>
                                        <td><?php echo formatDate($historyLoan['date_released'] ?? $historyLoan['release_date'] ?? null); ?></td>
                                        <td><?php echo formatDate($historyLoan['due_date'] ?? null); ?></td>
                                        <td><?php echo formatDate($historyLoan['fully_paid_date'] ?? null); ?></td>
                                        <td><span class="badge <?php echo statusBadgeClass((string)($historyLoan['status'] ?? '')); ?>"><?php echo htmlspecialchars((string)($historyLoan['status'] ?? 'N/A')); ?></span></td>
                                        <td class="text-end">
                                            <a href="loan_details_lending.php?id=<?php echo (int)($historyLoan['loan_id'] ?? 0); ?>" class="btn btn-sm btn-outline-primary">View Details</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/lending.js"></script>
</body>
</html>
