<?php
/**
 * Borrower Payment History page showing payments recorded by assigned collector.
 */

require_once 'includes/auth_lending.php';
require_once 'includes/db_lending.php';
require_once 'includes/loan_helpers.php';
require_once 'includes/ui_helpers.php';
ensurePaymentReceiptColumns();

if (!isLoggedIn() || !isBorrower()) {
    header('Location: borrower_login_lending.php');
    exit;
}

$user = getCurrentUser();

$currentLoan = null;
$loanHistory = fetchBorrowerLoanHistoryForUser((int)$user['user_id']);
$settledStatuses = ['paid', 'completed', 'closed', 'cancelled', 'canceled'];
foreach ($loanHistory as $candidate) {
    $candidateStatus = strtolower(trim((string)($candidate['status'] ?? '')));
    $payable = (float)($candidate['total_payable'] ?? 0);
    $paid = (float)(executeQuery(
        'SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE loan_id = ?',
        [(int)($candidate['loan_id'] ?? 0)]
    )->fetchColumn() ?: 0);
    $settled = in_array($candidateStatus, $settledStatuses, true)
        || ($payable > 0 && $paid + 0.009 >= $payable);
    if (!$settled) {
        $currentLoan = $candidate;
        break;
    }
}
if (!$currentLoan && $loanHistory !== []) {
    $currentLoan = $loanHistory[0];
}

$paymentRows = [];
if ($currentLoan && (int)($currentLoan['loan_id'] ?? 0) > 0) {
    $paymentRows = executeQuery(
        'SELECT p.payment_id, p.receipt_number, p.payment_date, p.amount_paid, p.collector_name, p.payment_method, p.reference_number, p.loan_id, p.receipt_generated_at, l.total_payable, l.loan_amount
         FROM payments p
         JOIN loans l ON p.loan_id = l.loan_id
         WHERE p.loan_id = ?
         ORDER BY p.payment_date DESC, p.payment_id DESC',
        [(int)$currentLoan['loan_id']]
    )->fetchAll(PDO::FETCH_ASSOC);
}

function formatCurrency($value) {
    return '₱' . number_format((float)$value, 2);
}

function formatDate($date) {
    return $date ? date('M d, Y', strtotime($date)) : 'N/A';
}

function getRemainingBalance($totalPayable, $amountPaid) {
    return max(0, (float)$totalPayable - (float)$amountPaid);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/lending_assets.php'; lendingRenderPwaMeta(); ?>
    <title>Payment History - RJ and RR Finance Services</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="css/lending_styles.css">
    <link rel="stylesheet" href="css/pwa.css">
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
                    <h1 class="page-title"><i class="fas fa-history me-2"></i>Payment History</h1>
                    <p class="page-subtitle">Payments collected on your current loan appear here after an administrator or collector records them. You can view, print, or download the receipt for each collection.</p>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0 overflow-hidden">
            <div class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
                <div>
                    <h5 class="m-0 fw-bold">Payment History</h5>
                    <p class="text-muted mb-0">Only collections recorded for your current loan are listed here.</p>
                </div>
                <span class="badge bg-info text-white py-2 px-3">Total Records: <?php echo count($paymentRows); ?></span>
            </div>
            <div class="card-body">
                <div class="payment-history-table-wrap shadow-sm rounded-4 border border-light-subtle">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 payment-history-table">
                            <thead>
                                <tr>
                                    <th>Receipt Number</th>
                                    <th>Payment Date</th>
                                    <th class="text-end">Amount Paid</th>
                                    <th>Payment Method</th>
                                    <th>Reference No.</th>
                                    <th class="text-end">Remaining Balance</th>
                                    <th>Collector Name</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($paymentRows)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted">No payments have been collected for this loan yet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($paymentRows as $payment): ?>
                                        <tr>
                                            <td class="fw-semibold text-primary"><?php echo htmlspecialchars($payment['receipt_number'] ?: 'RJRR-' . str_pad($payment['payment_id'], 6, '0', STR_PAD_LEFT)); ?></td>
                                            <td><?php echo formatDate($payment['payment_date']); ?></td>
                                            <td class="text-end fw-semibold"><?php echo formatCurrency($payment['amount_paid']); ?></td>
                                            <td><?php echo htmlspecialchars($payment['payment_method'] ?? 'Cash'); ?></td>
                                            <td><?php echo htmlspecialchars(lendingPaymentReferenceLabel($payment['payment_method'] ?? '', $payment['reference_number'] ?? '')); ?></td>
                                            <td class="text-end"><?php echo formatCurrency(getRemainingBalance($payment['total_payable'], $payment['amount_paid'])); ?></td>
                                            <td><?php echo htmlspecialchars($payment['collector_name']); ?></td>
                                            <td class="text-center">
                                                <div class="d-flex justify-content-center gap-2">
                                                    <a href="payments_lending.php?view=<?php echo $payment['payment_id']; ?>" class="btn btn-icon btn-outline-info" data-bs-toggle="tooltip" data-bs-placement="top" title="View Receipt">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="assets/pwa.js" defer></script>
    <script src="assets/lending.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>
</body>
</html>
