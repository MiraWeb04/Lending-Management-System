<?php
/**
 * Collector Dashboard for Lending Management System
 */

require_once 'includes/auth_lending.php';
requireLogin();

$user = getCurrentUser();
if (!isCollector()) {
    header('Location: dashboard_lending.php');
    exit;
}

require_once 'includes/db_lending.php';
ensureNotificationsSchema();

$assignedClientsQuery = "SELECT DISTINCT c.client_id, c.first_name, c.last_name, c.contact, c.email, c.address, c.date_registered FROM clients c LEFT JOIN loans l ON c.client_id = l.client_id WHERE c.collector_id = ? OR l.collector_id = ? ORDER BY c.last_name, c.first_name";
$assignedClientsResult = executeQuery($assignedClientsQuery, [$user['user_id'], $user['user_id']]);
$assignedClients = $assignedClientsResult->fetchAll(PDO::FETCH_ASSOC);

$assignedLoansQuery = "SELECT l.loan_id, l.loan_number, l.client_id, c.first_name, c.last_name, c.contact, c.email, c.address, l.loan_amount, l.total_payable, l.status, l.date_released, l.due_date, l.release_id, COALESCE(p.total_paid, 0) AS total_paid, ROUND(l.total_payable - COALESCE(p.total_paid, 0), 2) AS remaining_balance, s.next_due_date, s.final_due_date FROM loans l JOIN clients c ON l.client_id = c.client_id LEFT JOIN (SELECT loan_id, SUM(amount_paid) AS total_paid FROM payments GROUP BY loan_id) p ON p.loan_id = l.loan_id LEFT JOIN (SELECT release_id, MIN(due_date) AS next_due_date, MAX(due_date) AS final_due_date FROM loan_payment_schedules WHERE status != 'Paid' GROUP BY release_id) s ON s.release_id = l.release_id WHERE l.collector_id = ? ORDER BY COALESCE(s.next_due_date, s.final_due_date, l.due_date) ASC";
$assignedLoansResult = executeQuery($assignedLoansQuery, [$user['user_id']]);
$assignedLoans = $assignedLoansResult->fetchAll(PDO::FETCH_ASSOC);

$collectionScheduleQuery = "SELECT c.first_name, c.last_name, l.loan_number, s.installment_number, s.due_date, s.total_amount_due, s.status FROM loan_payment_schedules s JOIN loan_releases r ON r.id = s.release_id JOIN loans l ON l.release_id = r.id JOIN clients c ON c.client_id = l.client_id WHERE c.collector_id = ? ORDER BY s.due_date ASC LIMIT 10";
$collectionScheduleResult = executeQuery($collectionScheduleQuery, [$user['user_id']]);
$collectionSchedule = $collectionScheduleResult->fetchAll(PDO::FETCH_ASSOC);

$today = date('Y-m-d');
$paymentsTodayQuery = "SELECT COUNT(*) AS total FROM payments p JOIN loans l ON p.loan_id = l.loan_id JOIN clients c ON l.client_id = c.client_id WHERE c.collector_id = ? AND p.payment_date = ? AND p.collector_name = ?";
$paymentsTodayResult = executeQuery($paymentsTodayQuery, [$user['user_id'], $today, $user['full_name'] ?? '']);
$paymentsCollectedToday = (int)$paymentsTodayResult->fetchColumn();

$pendingCollectionsQuery = "SELECT COUNT(*) AS total FROM loans l JOIN clients c ON l.client_id = c.client_id LEFT JOIN (SELECT loan_id, SUM(amount_paid) AS total_paid FROM payments GROUP BY loan_id) p ON l.loan_id = p.loan_id WHERE c.collector_id = ? AND l.status IN ('Active', 'Overdue') AND COALESCE(p.total_paid, 0) < l.total_payable";
$pendingCollectionsResult = executeQuery($pendingCollectionsQuery, [$user['user_id']]);
$pendingCollections = (int)$pendingCollectionsResult->fetchColumn();

$overdueClientsQuery = "SELECT COUNT(DISTINCT c.client_id) AS total FROM loans l JOIN clients c ON l.client_id = c.client_id WHERE c.collector_id = ? AND l.status = 'Overdue'";
$overdueClientsResult = executeQuery($overdueClientsQuery, [$user['user_id']]);
$overdueClients = (int)$overdueClientsResult->fetchColumn();

$assignedDueLoans = executeQuery("SELECT l.loan_id, l.due_date, l.status FROM loans l JOIN clients c ON l.client_id = c.client_id WHERE c.collector_id = ? AND l.status = 'Active'", [$user['user_id']])->fetchAll(PDO::FETCH_ASSOC);
foreach ($assignedDueLoans as $assignedDueLoan) {
    if ($assignedDueLoan['due_date'] === date('Y-m-d')) {
        addUniqueNotification($user['user_id'], 'Assigned Loan Due Today', 'Loan #' . $assignedDueLoan['loan_id'] . ' is due today.');
    } elseif (strtotime($assignedDueLoan['due_date']) < strtotime(date('Y-m-d'))) {
        addUniqueNotification($user['user_id'], 'Assigned Loan Overdue', 'Loan #' . $assignedDueLoan['loan_id'] . ' is overdue.');
    }
}

$unreadNotificationCount = getUnreadNotificationCount($user['user_id']);
$notifications = getUserNotifications($user['user_id'], 5);

$activeLoanCount = 0;
foreach ($assignedLoans as $loan) {
    if (strcasecmp((string)($loan['status'] ?? ''), 'Active') === 0) {
        $activeLoanCount++;
    }
}

$recentPaymentsQuery = "SELECT p.payment_id, p.payment_date, p.amount_paid, p.collector_name, c.first_name, c.last_name, l.loan_id FROM payments p JOIN loans l ON p.loan_id = l.loan_id JOIN clients c ON l.client_id = c.client_id WHERE c.collector_id = ? AND p.collector_name = ? ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT 10";
$recentPaymentsResult = executeQuery($recentPaymentsQuery, [$user['user_id'], $user['full_name'] ?? '']);
$recentPayments = $recentPaymentsResult->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Collector Dashboard - Lending Management System</title>
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
                    <img src="images/logo.png" alt="Lending Management System logo">
                </div>
                <div>
                    <h1 class="page-title"><i class="fas fa-user-tie me-2"></i>Collector Dashboard</h1>
                    <p class="page-subtitle">Welcome back, <?php echo htmlspecialchars($user['full_name'] ?? 'Collector'); ?>. Manage your assigned clients, loans, and collections with a premium workflow.</p>
                </div>
            </div>
            <div class="collector-hero__pill-wrap">
                <span class="collector-hero__chip"><i class="fas fa-shield-alt me-2"></i>Secure operations</span>
                <span class="collector-hero__chip collector-hero__chip--accent"><i class="fas fa-bolt me-2"></i>Live portfolio view</span>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="card metric-card shadow-sm h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="metric-label">Assigned Clients</div>
                            <div class="metric-value"><?php echo count($assignedClients); ?></div>
                        </div>
                        <div class="metric-icon bg-primary-subtle text-primary"><i class="fas fa-users"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="card metric-card shadow-sm h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="metric-label">Active Loans</div>
                            <div class="metric-value"><?php echo $activeLoanCount; ?></div>
                        </div>
                        <div class="metric-icon bg-success-subtle text-success"><i class="fas fa-hand-holding-usd"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="card metric-card shadow-sm h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="metric-label">Collected Today</div>
                            <div class="metric-value"><?php echo $paymentsCollectedToday; ?></div>
                        </div>
                        <div class="metric-icon bg-info-subtle text-info"><i class="fas fa-money-bill-wave"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="card metric-card shadow-sm h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="metric-label">Pending</div>
                            <div class="metric-value"><?php echo $pendingCollections; ?></div>
                        </div>
                        <div class="metric-icon bg-warning-subtle text-warning"><i class="fas fa-clock"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="card metric-card shadow-sm h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="metric-label">Overdue</div>
                            <div class="metric-value"><?php echo $overdueClients; ?></div>
                        </div>
                        <div class="metric-icon bg-danger-subtle text-danger"><i class="fas fa-exclamation-triangle"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-xl-4">
                <div class="card collector-quick-card shadow-sm h-100">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <div>
                            <h6 class="collector-quick-card__title"><i class="fas fa-users me-2"></i>Manage Clients</h6>
                            <p class="collector-quick-card__text">Keep track of your assigned borrowers and account activity.</p>
                        </div>
                        <a href="clients_lending.php" class="btn btn-sm btn-outline-primary" data-bs-toggle="tooltip" data-bs-placement="top" title="View All Clients"><i class="fas fa-arrow-right"></i></a>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card collector-quick-card shadow-sm h-100">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <div>
                            <h6 class="collector-quick-card__title"><i class="fas fa-hand-holding-usd me-2"></i>Review Loans</h6>
                            <p class="collector-quick-card__text">Track outstanding obligations and due dates with clear visibility.</p>
                        </div>
                        <a href="loans_lending.php" class="btn btn-sm btn-outline-primary" data-bs-toggle="tooltip" data-bs-placement="top" title="View All Loans"><i class="fas fa-arrow-right"></i></a>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card collector-quick-card shadow-sm h-100">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <div>
                            <h6 class="collector-quick-card__title"><i class="fas fa-cash-register me-2"></i>Record Payments</h6>
                            <p class="collector-quick-card__text">Process new payments and keep every receipt professionally organized.</p>
                        </div>
                        <a href="payments_lending.php" class="btn btn-sm btn-outline-primary" data-bs-toggle="tooltip" data-bs-placement="top" title="Record New Payment"><i class="fas fa-arrow-right"></i></a>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="m-0 font-weight-bold"><i class="fas fa-users me-2"></i>Assigned Clients</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Contact</th>
                                <th>Email</th>
                                <th>Registered</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($assignedClients) > 0): ?>
                                <?php foreach ($assignedClients as $client): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($client['first_name'] . ' ' . $client['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($client['contact']); ?></td>
                                        <td><?php echo htmlspecialchars($client['email'] ?? 'N/A'); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($client['date_registered'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-muted">No clients have been assigned to you yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 font-weight-bold"><i class="fas fa-hand-holding-usd me-2"></i>Assigned Loans</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Loan No.</th>
                                        <th>Borrower</th>
                                        <th>Contact</th>
                                        <th>Loan Amount</th>
                                        <th>Remaining Balance</th>
                                        <th>Next Due</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($assignedLoans) > 0): ?>
                                        <?php foreach ($assignedLoans as $loan): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($loan['loan_number'] ?? ('LN-' . (int)$loan['loan_id'])); ?></td>
                                                <td><?php echo htmlspecialchars(($loan['first_name'] ?? '') . ' ' . ($loan['last_name'] ?? '')); ?></td>
                                                <td><?php echo htmlspecialchars($loan['contact'] ?? 'N/A'); ?></td>
                                                <td>₱<?php echo number_format((float)($loan['loan_amount'] ?? 0), 2); ?></td>
                                                <td>₱<?php echo number_format((float)($loan['remaining_balance'] ?? 0), 2); ?></td>
                                                <td><?php
                                                    $displayDue = null;
                                                    if (!empty($loan['next_due_date'])) {
                                                        $displayDue = $loan['next_due_date'];
                                                    } elseif (!empty($loan['final_due_date'])) {
                                                        $displayDue = $loan['final_due_date'];
                                                    } else {
                                                        $displayDue = $loan['due_date'];
                                                    }
                                                    echo !empty($displayDue) ? date('M d, Y', strtotime($displayDue)) : 'N/A';
                                                ?></td>
                                                <td>
                                                    <?php if (strcasecmp((string)$loan['status'], 'Active') === 0): ?>
                                                        <span class="badge status-badge bg-success">Active</span>
                                                    <?php elseif (strcasecmp((string)$loan['status'], 'Overdue') === 0): ?>
                                                        <span class="badge status-badge bg-danger">Overdue</span>
                                                    <?php else: ?>
                                                        <span class="badge status-badge bg-info"><?php echo htmlspecialchars($loan['status']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-muted">No loan assignments found for your account.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 font-weight-bold"><i class="fas fa-calendar-check me-2"></i>Collection Schedule</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Borrower</th>
                                        <th>Due Date</th>
                                        <th>Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($collectionSchedule) > 0): ?>
                                        <?php foreach ($collectionSchedule as $schedule): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars(($schedule['first_name'] ?? '') . ' ' . ($schedule['last_name'] ?? '')); ?></td>
                                                <td><?php echo date('M d, Y', strtotime($schedule['due_date'])); ?></td>
                                                <td>₱<?php echo number_format((float)($schedule['total_amount_due'] ?? 0), 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="3" class="text-center py-4 text-muted">No collection schedule has been generated yet.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 font-weight-bold"><i class="fas fa-receipt me-2"></i>Recent Collections</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Client</th>
                                        <th>Amount</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($recentPayments) > 0): ?>
                                        <?php foreach ($recentPayments as $payment): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></td>
                                                <td>₱<?php echo number_format($payment['amount_paid'], 2); ?></td>
                                                <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="3" class="text-center py-4 text-muted">No collections recorded for you yet.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/lending.js"></script>
</body>
</html>
