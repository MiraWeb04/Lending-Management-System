<?php
/**
 * Collector Dashboard for Lending Management System
 */

require_once 'includes/auth_lending.php';
requireStaff();

$user = getCurrentUser();
if (!isCollector()) {
    header('Location: dashboard_lending.php');
    exit;
}

require_once 'includes/db_lending.php';
require_once 'includes/loan_helpers.php';
require_once 'includes/ui_helpers.php';
require_once 'includes/notification_ui_helpers.php';
ensureNotificationsSchema();

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthLabel = date('F Y');
$todayDisplay = date('l, F j, Y');
$collectorId = (int)($user['user_id'] ?? 0);
$collectorName = (string)($user['full_name'] ?? '');

$assignedClientsQuery = "SELECT DISTINCT c.client_id, c.first_name, c.last_name, c.contact, c.email, c.address, c.date_registered FROM clients c LEFT JOIN loans l ON c.client_id = l.client_id WHERE c.collector_id = ? OR l.collector_id = ? ORDER BY c.last_name, c.first_name";
$assignedClients = executeQuery($assignedClientsQuery, [$collectorId, $collectorId])->fetchAll(PDO::FETCH_ASSOC);

$assignedLoansQuery = "SELECT l.loan_id, l.loan_number, l.client_id, c.first_name, c.last_name, c.contact, l.loan_amount, l.total_payable, l.status, l.date_released, l.due_date, l.release_id, COALESCE(p.total_paid, 0) AS total_paid, ROUND(l.total_payable - COALESCE(p.total_paid, 0), 2) AS remaining_balance, s.next_due_date, s.final_due_date FROM loans l JOIN clients c ON l.client_id = c.client_id LEFT JOIN (SELECT loan_id, SUM(amount_paid) AS total_paid FROM payments GROUP BY loan_id) p ON p.loan_id = l.loan_id LEFT JOIN (SELECT release_id, MIN(due_date) AS next_due_date, MAX(due_date) AS final_due_date FROM loan_payment_schedules WHERE status != 'Paid' GROUP BY release_id) s ON s.release_id = l.release_id WHERE (c.collector_id = ? OR l.collector_id = ?) AND COALESCE(p.total_paid, 0) < l.total_payable ORDER BY COALESCE(s.next_due_date, s.final_due_date, l.due_date) ASC";
$assignedLoans = array_map('enrichLoanCollectionFields', executeQuery($assignedLoansQuery, [$collectorId, $collectorId])->fetchAll(PDO::FETCH_ASSOC));

$collectionScheduleQuery = "SELECT c.first_name, c.last_name, l.loan_id, l.loan_number, s.installment_number, s.due_date, s.total_amount_due, s.status FROM loan_payment_schedules s JOIN loan_releases r ON r.id = s.release_id JOIN loans l ON l.release_id = r.id JOIN clients c ON c.client_id = l.client_id WHERE c.collector_id = ? AND s.status != 'Paid' AND s.due_date >= ? ORDER BY s.due_date ASC LIMIT 12";
$collectionSchedule = executeQuery($collectionScheduleQuery, [$collectorId, $today])->fetchAll(PDO::FETCH_ASSOC);

$dueTodayQuery = "SELECT c.first_name, c.last_name, l.loan_id, l.loan_number, s.installment_number, s.due_date, s.total_amount_due, s.status FROM loan_payment_schedules s JOIN loan_releases r ON r.id = s.release_id JOIN loans l ON l.release_id = r.id JOIN clients c ON c.client_id = l.client_id WHERE c.collector_id = ? AND s.status != 'Paid' AND s.due_date = ? ORDER BY c.last_name, c.first_name";
$dueTodaySchedule = executeQuery($dueTodayQuery, [$collectorId, $today])->fetchAll(PDO::FETCH_ASSOC);

$overdueScheduleQuery = "SELECT COUNT(*) FROM loan_payment_schedules s JOIN loan_releases r ON r.id = s.release_id JOIN loans l ON l.release_id = r.id JOIN clients c ON c.client_id = l.client_id WHERE c.collector_id = ? AND s.status = 'Overdue'";
$overdueInstallmentCount = (int)(executeQuery($overdueScheduleQuery, [$collectorId])->fetchColumn() ?? 0);

$paymentScopeSql = '(c.collector_id = ? OR l.collector_id = ?) AND p.collector_name = ?';

$todayCollected = (float)(executeQuery(
    "SELECT COALESCE(SUM(p.amount_paid + COALESCE(p.penalty_paid, 0)), 0) FROM payments p
     JOIN loans l ON l.loan_id = p.loan_id JOIN clients c ON c.client_id = l.client_id
     WHERE {$paymentScopeSql} AND DATE(p.payment_date) = ?",
    [$collectorId, $collectorId, $collectorName, $today]
)->fetchColumn() ?? 0);

$todayPaymentCount = (int)(executeQuery(
    "SELECT COUNT(*) FROM payments p
     JOIN loans l ON l.loan_id = p.loan_id JOIN clients c ON c.client_id = l.client_id
     WHERE {$paymentScopeSql} AND DATE(p.payment_date) = ?",
    [$collectorId, $collectorId, $collectorName, $today]
)->fetchColumn() ?? 0);

$mtdCollected = (float)(executeQuery(
    "SELECT COALESCE(SUM(p.amount_paid + COALESCE(p.penalty_paid, 0)), 0) FROM payments p
     JOIN loans l ON l.loan_id = p.loan_id JOIN clients c ON c.client_id = l.client_id
     WHERE {$paymentScopeSql} AND DATE(p.payment_date) BETWEEN ? AND ?",
    [$collectorId, $collectorId, $collectorName, $monthStart, $today]
)->fetchColumn() ?? 0);

$mtdPaymentCount = (int)(executeQuery(
    "SELECT COUNT(*) FROM payments p
     JOIN loans l ON l.loan_id = p.loan_id JOIN clients c ON c.client_id = l.client_id
     WHERE {$paymentScopeSql} AND DATE(p.payment_date) BETWEEN ? AND ?",
    [$collectorId, $collectorId, $collectorName, $monthStart, $today]
)->fetchColumn() ?? 0);

$pendingCollections = (int)(executeQuery(
    "SELECT COUNT(*) FROM loans l JOIN clients c ON l.client_id = c.client_id
     LEFT JOIN (SELECT loan_id, SUM(amount_paid) AS total_paid FROM payments GROUP BY loan_id) p ON l.loan_id = p.loan_id
     WHERE c.collector_id = ? AND l.status IN ('Active', 'Overdue') AND COALESCE(p.total_paid, 0) < l.total_payable",
    [$collectorId]
)->fetchColumn() ?? 0);

$overdueClients = (int)(executeQuery(
    "SELECT COUNT(DISTINCT c.client_id) FROM loans l
     JOIN clients c ON l.client_id = c.client_id
     LEFT JOIN (SELECT loan_id, SUM(amount_paid) AS total_paid FROM payments GROUP BY loan_id) p ON p.loan_id = l.loan_id
     WHERE c.collector_id = ?
       AND COALESCE(p.total_paid, 0) < l.total_payable
       AND (
         l.status = 'Overdue'
         OR EXISTS (
           SELECT 1 FROM loan_payment_schedules s
           WHERE s.release_id = l.release_id AND s.status = 'Overdue'
         )
       )",
    [$collectorId]
)->fetchColumn() ?? 0);

$assignedDueLoans = executeQuery(
    "SELECT l.loan_id, l.due_date, l.status FROM loans l JOIN clients c ON l.client_id = c.client_id WHERE c.collector_id = ? AND l.status = 'Active'",
    [$collectorId]
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($assignedDueLoans as $assignedDueLoan) {
    if ($assignedDueLoan['due_date'] === $today) {
        addUniqueNotification($collectorId, 'Assigned Loan Due Today', 'Loan #' . $assignedDueLoan['loan_id'] . ' is due today.');
    } elseif (strtotime($assignedDueLoan['due_date']) < strtotime($today)) {
        addUniqueNotification($collectorId, 'Assigned Loan Overdue', 'Loan #' . $assignedDueLoan['loan_id'] . ' is overdue.');
    }
}

$unreadNotificationCount = getUnreadNotificationCount($collectorId);
$collectorNotifications = getUserNotifications($collectorId, 5);

$activeLoanCount = 0;
$totalOutstanding = 0.0;
foreach ($assignedLoans as $loan) {
    $status = strcasecmp((string)($loan['status'] ?? ''), 'Active') === 0
        || strcasecmp((string)($loan['status'] ?? ''), 'Overdue') === 0;
    if (strcasecmp((string)($loan['status'] ?? ''), 'Active') === 0) {
        $activeLoanCount++;
    }
    if ($status) {
        $totalOutstanding += (float)($loan['remaining_balance'] ?? 0);
    }
}

$recentPaymentsQuery = "SELECT p.payment_id, p.payment_date, p.amount_paid, p.penalty_paid, p.receipt_number, p.payment_method, p.reference_number, c.first_name, c.last_name, l.loan_id FROM payments p JOIN loans l ON p.loan_id = l.loan_id JOIN clients c ON l.client_id = c.client_id WHERE c.collector_id = ? AND p.collector_name = ? ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT 10";
$recentPayments = executeQuery($recentPaymentsQuery, [$collectorId, $collectorName])->fetchAll(PDO::FETCH_ASSOC);

$dueTodayAmount = 0.0;
foreach ($dueTodaySchedule as $row) {
    $dueTodayAmount += (float)($row['total_amount_due'] ?? 0);
}

$collectionTrendLabels = [];
$collectionTrendValues = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $collectionTrendLabels[] = date('D', strtotime($day));
    $collectionTrendValues[] = (float)(executeQuery(
        "SELECT COALESCE(SUM(p.amount_paid + COALESCE(p.penalty_paid, 0)), 0) FROM payments p
         JOIN loans l ON l.loan_id = p.loan_id JOIN clients c ON c.client_id = l.client_id
         WHERE {$paymentScopeSql} AND DATE(p.payment_date) = ?",
        [$collectorId, $collectorId, $collectorName, $day]
    )->fetchColumn() ?? 0);
}
$hasTrendData = array_sum($collectionTrendValues) > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/lending_assets.php'; lendingRenderPwaMeta(); ?>
    <title>Collector Dashboard - Lending Management System</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <script src="assets/vendor/chartjs/chart.umd.min.js"></script>
    <link rel="stylesheet" href="css/lending_styles.css">
    <link rel="stylesheet" href="css/pwa.css">
</head>
<body>
    <?php include 'includes/nav_lending.php'; ?>

    <div class="app-content py-2">
        <section class="collector-dash-hero" aria-label="Collector welcome">
            <div class="collector-dash-hero__inner">
                <div>
                    <p class="collector-dash-hero__eyebrow">Field collections</p>
                    <h1 class="collector-dash-hero__title">Good day, <?php echo htmlspecialchars($user['full_name'] ?? 'Collector'); ?></h1>
                    <p class="collector-dash-hero__lead">Your route at a glance — prioritize dues, record payments, and stay on top of overdue accounts.</p>
                    <div class="collector-dash-hero__meta">
                        <span class="collector-dash-hero__date"><i class="fas fa-calendar-day" aria-hidden="true"></i> <?php echo htmlspecialchars($todayDisplay); ?></span>
                        <span class="role-badge">Collector</span>
                    </div>
                </div>
                <div class="collector-dash-hero__actions">
                    <div class="collector-stat-strip">
                        <div class="collector-stat-strip__item">
                            <div class="collector-stat-strip__label">Collected today</div>
                            <div class="collector-stat-strip__value collector-stat-strip__value--success">₱<?php echo number_format($todayCollected, 0); ?></div>
                        </div>
                        <div class="collector-stat-strip__item">
                            <div class="collector-stat-strip__label">Due today</div>
                            <div class="collector-stat-strip__value">₱<?php echo number_format($dueTodayAmount, 0); ?></div>
                        </div>
                        <div class="collector-stat-strip__item">
                            <div class="collector-stat-strip__label">Overdue slots</div>
                            <div class="collector-stat-strip__value collector-stat-strip__value--danger"><?php echo (int)$overdueInstallmentCount; ?></div>
                        </div>
                    </div>
                    <a href="payments_lending.php?add=true" class="btn btn-primary"><i class="fas fa-cash-register me-1"></i> Record payment</a>
                </div>
            </div>
        </section>

        <?php if ($overdueClients > 0 || $overdueInstallmentCount > 0): ?>
        <div class="collector-alert-banner" role="status">
            <p class="collector-alert-banner__text mb-0">
                <i class="fas fa-triangle-exclamation me-2 text-danger" aria-hidden="true"></i>
                <strong><?php echo (int)$overdueClients; ?></strong> borrower(s) with overdue loans
                <?php if ($overdueInstallmentCount > 0): ?>
                    · <strong><?php echo (int)$overdueInstallmentCount; ?></strong> overdue installment(s) on your route
                <?php endif; ?>
            </p>
            <a href="loans_lending.php?status=Overdue" class="btn btn-sm btn-outline-danger">View overdue</a>
        </div>
        <?php endif; ?>

        <section class="dashboard-section mb-4" aria-labelledby="collectorPortfolioHeading">
            <div class="dashboard-section__head">
                <h2 class="dashboard-section__title" id="collectorPortfolioHeading">Portfolio</h2>
                <span class="dashboard-section__hint">Live assignment totals</span>
            </div>
            <div class="kpi-grid">
                <div class="kpi-card kpi-card--primary">
                    <div class="kpi-card__top"><div class="kpi-card__label">Assigned clients</div><div class="kpi-card__icon"><i class="fas fa-users"></i></div></div>
                    <div class="kpi-card__value"><?php echo count($assignedClients); ?></div>
                    <div class="kpi-card__meta"><a href="clients_lending.php" class="text-decoration-none">Open client list</a></div>
                </div>
                <div class="kpi-card kpi-card--success">
                    <div class="kpi-card__top"><div class="kpi-card__label">Active loans</div><div class="kpi-card__icon"><i class="fas fa-hand-holding-dollar"></i></div></div>
                    <div class="kpi-card__value"><?php echo (int)$activeLoanCount; ?></div>
                    <div class="kpi-card__meta"><?php echo (int)$pendingCollections; ?> pending collection</div>
                </div>
                <div class="kpi-card kpi-card--warning">
                    <div class="kpi-card__top"><div class="kpi-card__label">Outstanding</div><div class="kpi-card__icon"><i class="fas fa-scale-balanced"></i></div></div>
                    <div class="kpi-card__value">₱<?php echo number_format($totalOutstanding, 0); ?></div>
                    <div class="kpi-card__meta">Open balances on route</div>
                </div>
                <div class="kpi-card kpi-card--danger">
                    <div class="kpi-card__top"><div class="kpi-card__label">Overdue clients</div><div class="kpi-card__icon"><i class="fas fa-triangle-exclamation"></i></div></div>
                    <div class="kpi-card__value"><?php echo (int)$overdueClients; ?></div>
                    <div class="kpi-card__meta"><?php echo (int)$unreadNotificationCount; ?> unread alert(s)</div>
                </div>
            </div>
        </section>

        <section class="dashboard-section mb-4" aria-labelledby="collectorPerformanceHeading">
            <div class="dashboard-section__head">
                <h2 class="dashboard-section__title" id="collectorPerformanceHeading">Your performance</h2>
                <span class="chart-badge"><i class="fas fa-calendar-check me-1" aria-hidden="true"></i> <?php echo htmlspecialchars($monthLabel); ?> (MTD)</span>
            </div>
            <div class="kpi-grid">
                <div class="kpi-card kpi-card--success">
                    <div class="kpi-card__top"><div class="kpi-card__label">Collected today</div><div class="kpi-card__icon"><i class="fas fa-sun"></i></div></div>
                    <div class="kpi-card__value">₱<?php echo number_format($todayCollected, 2); ?></div>
                    <div class="kpi-card__meta"><?php echo (int)$todayPaymentCount; ?> payment(s)</div>
                </div>
                <div class="kpi-card kpi-card--primary">
                    <div class="kpi-card__top"><div class="kpi-card__label">Collected (MTD)</div><div class="kpi-card__icon"><i class="fas fa-chart-line"></i></div></div>
                    <div class="kpi-card__value">₱<?php echo number_format($mtdCollected, 2); ?></div>
                    <div class="kpi-card__meta"><?php echo (int)$mtdPaymentCount; ?> payment(s) this month</div>
                </div>
                <div class="kpi-card kpi-card--info">
                    <div class="kpi-card__top"><div class="kpi-card__label">Due today</div><div class="kpi-card__icon"><i class="fas fa-bell"></i></div></div>
                    <div class="kpi-card__value"><?php echo count($dueTodaySchedule); ?></div>
                    <div class="kpi-card__meta">₱<?php echo number_format($dueTodayAmount, 2); ?> expected</div>
                </div>
                <div class="kpi-card kpi-card--warning">
                    <div class="kpi-card__top"><div class="kpi-card__label">Upcoming dues</div><div class="kpi-card__icon"><i class="fas fa-route"></i></div></div>
                    <div class="kpi-card__value"><?php echo count($collectionSchedule); ?></div>
                    <div class="kpi-card__meta">Next installments on route</div>
                </div>
            </div>
        </section>

        <nav class="collector-quick-actions" aria-label="Quick actions">
            <a href="clients_lending.php" class="quick-action-tile">
                <i class="fas fa-users"></i>
                <span>Clients</span>
            </a>
            <a href="loans_lending.php" class="quick-action-tile">
                <i class="fas fa-hand-holding-dollar"></i>
                <span>Loans</span>
            </a>
            <a href="payments_lending.php?add=true" class="quick-action-tile">
                <i class="fas fa-cash-register"></i>
                <span>Record payment</span>
            </a>
            <a href="notifications_lending.php" class="quick-action-tile">
                <i class="fas fa-bell"></i>
                <span>Alerts<?php if ($unreadNotificationCount > 0): ?> (<?php echo (int)$unreadNotificationCount; ?>)<?php endif; ?></span>
            </a>
        </nav>

        <div class="collector-dash-layout">
            <div class="collector-dash-main">
                <div class="chart-panel collector-trend-panel mb-4">
                    <div class="chart-panel__header">
                        <div>
                            <h3 class="h6 mb-0 fw-bold">7-day collections</h3>
                            <small class="text-muted">Your recorded payments by day</small>
                        </div>
                        <span class="chart-badge">This week</span>
                    </div>
                    <div class="chart-panel__body chart-panel__body--tight">
                        <?php if ($hasTrendData): ?>
                            <div class="chart-canvas-shell"><canvas id="collectorTrendChart" aria-label="Seven day collection trend"></canvas></div>
                        <?php else: ?>
                            <div class="chart-empty"><div><i class="fas fa-chart-column"></i><div>No collections in the last 7 days yet.</div></div></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="chart-panel collector-priority-panel mb-4">
                    <div class="chart-panel__header">
                        <h3 class="h6 mb-0 fw-bold">Assigned loans</h3>
                        <span class="chart-badge"><?php echo count($assignedLoans); ?> on route</span>
                    </div>
                    <div class="chart-panel__body chart-panel__body--tight px-0 pb-0">
                        <div class="table-responsive">
                            <table class="table data-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Loan</th>
                                        <th>Borrower</th>
                                        <th class="text-end">Balance</th>
                                        <th>Next due</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($assignedLoans) > 0): ?>
                                        <?php foreach ($assignedLoans as $loan): ?>
                                            <?php
                                            $displayDue = $loan['next_due_date'] ?? $loan['final_due_date'] ?? $loan['due_date'] ?? null;
                                            $displayStatus = (string)($loan['display_status'] ?? $loan['status'] ?? 'Active');
                                            $rowClass = '';
                                            if (strcasecmp($displayStatus, 'Overdue') === 0) {
                                                $rowClass = 'is-overdue';
                                            } elseif (!empty($displayDue) && $displayDue === $today) {
                                                $rowClass = 'is-due-today';
                                            }
                                            ?>
                                            <tr class="<?php echo $rowClass; ?>">
                                                <td><?php echo htmlspecialchars($loan['loan_number'] ?? ('LN-' . (int)$loan['loan_id'])); ?></td>
                                                <td><a href="client_details_lending.php?id=<?php echo (int)$loan['client_id']; ?>"><?php echo htmlspecialchars(trim(($loan['first_name'] ?? '') . ' ' . ($loan['last_name'] ?? ''))); ?></a></td>
                                                <td class="text-end">₱<?php echo number_format((float)($loan['remaining_balance'] ?? 0), 2); ?></td>
                                                <td><?php echo !empty($displayDue) ? date('M d, Y', strtotime($displayDue)) : '—'; ?></td>
                                                <td><?php echo renderLoanStatusPill($displayStatus); ?></td>
                                                <td class="text-end">
                                                    <a href="payments_lending.php?add=true&amp;loan=<?php echo (int)$loan['loan_id']; ?>" class="btn btn-sm btn-primary" title="Collect"><i class="fas fa-cash-register me-1"></i><span class="d-none d-xl-inline">Collect</span></a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="6"><?php echo renderEmptyState('fa-hand-holding-dollar', 'No assigned loans', 'Loans linked to your route will appear here.'); ?></td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <aside class="collector-sidebar-stack" aria-label="Today and recent activity">
                <div class="chart-panel panel-card--flush">
                    <div class="chart-panel__header table-panel__toolbar">
                        <h3 class="h6 mb-0 fw-bold"><i class="fas fa-bell me-2" aria-hidden="true"></i>Alerts</h3>
                        <a href="notifications_lending.php" class="btn btn-sm btn-outline-primary">Inbox</a>
                    </div>
                    <div class="chart-panel__body chart-panel__body--tight">
                        <?php if (count($collectorNotifications) === 0): ?>
                            <?php echo renderEmptyState('fa-bell-slash', 'No alerts', 'You are all caught up.'); ?>
                        <?php else: ?>
                            <div class="notification-feed">
                                <?php foreach ($collectorNotifications as $notification): ?>
                                    <?php echo renderNotificationFeedItem($notification, ['truncate' => 80]); ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="chart-panel collector-priority-panel">
                    <div class="chart-panel__header">
                        <h3 class="h6 mb-0 fw-bold">Due today</h3>
                        <span class="chart-badge"><?php echo count($dueTodaySchedule); ?> visit(s)</span>
                    </div>
                    <div class="chart-panel__body chart-panel__body--tight px-0 pb-0">
                        <div class="table-responsive">
                            <table class="table data-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Borrower</th>
                                        <th class="text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($dueTodaySchedule) > 0): ?>
                                        <?php foreach ($dueTodaySchedule as $schedule): ?>
                                            <tr class="is-due-today">
                                                <td>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars(trim(($schedule['first_name'] ?? '') . ' ' . ($schedule['last_name'] ?? ''))); ?></div>
                                                    <small class="text-muted">#<?php echo (int)($schedule['installment_number'] ?? 0); ?> · <?php echo htmlspecialchars($schedule['loan_number'] ?? ''); ?></small>
                                                </td>
                                                <td class="text-end fw-semibold">₱<?php echo number_format((float)($schedule['total_amount_due'] ?? 0), 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="2"><?php echo renderEmptyState('fa-mug-hot', 'Clear for today', 'No installments are due today on your route.'); ?></td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="chart-panel">
                    <div class="chart-panel__header">
                        <h3 class="h6 mb-0 fw-bold">Upcoming</h3>
                        <span class="chart-badge">Next on route</span>
                    </div>
                    <div class="chart-panel__body chart-panel__body--tight px-0 pb-0">
                        <div class="table-responsive">
                            <table class="table data-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Borrower</th>
                                        <th>Due</th>
                                        <th class="text-end">Amt</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($collectionSchedule) > 0): ?>
                                        <?php foreach ($collectionSchedule as $schedule): ?>
                                            <?php
                                            $schedClass = strcasecmp((string)($schedule['status'] ?? ''), 'Overdue') === 0 ? 'is-overdue' : '';
                                            ?>
                                            <tr class="<?php echo $schedClass; ?>">
                                                <td><?php echo htmlspecialchars(trim(($schedule['first_name'] ?? '') . ' ' . ($schedule['last_name'] ?? ''))); ?></td>
                                                <td><?php echo date('M d', strtotime($schedule['due_date'])); ?></td>
                                                <td class="text-end">₱<?php echo number_format((float)($schedule['total_amount_due'] ?? 0), 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="3"><?php echo renderEmptyState('fa-calendar-check', 'No upcoming dues', 'All caught up on upcoming installments.'); ?></td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="chart-panel">
                    <div class="chart-panel__header">
                        <h3 class="h6 mb-0 fw-bold">Recent collections</h3>
                        <a href="payments_lending.php" class="small text-decoration-none">View all</a>
                    </div>
                    <div class="chart-panel__body chart-panel__body--tight px-0 pb-0">
                        <div class="table-responsive">
                            <table class="table data-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Client</th>
                                        <th>Reference No.</th>
                                        <th class="text-end">Received</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($recentPayments) > 0): ?>
                                        <?php foreach ($recentPayments as $payment): ?>
                                            <?php $received = (float)($payment['amount_paid'] ?? 0) + (float)($payment['penalty_paid'] ?? 0); ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></div>
                                                    <small class="text-muted"><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars(lendingPaymentReferenceLabel($payment['payment_method'] ?? '', $payment['reference_number'] ?? '')); ?></td>
                                                <td class="text-end"><a href="payments_lending.php?view=<?php echo (int)$payment['payment_id']; ?>">₱<?php echo number_format($received, 2); ?></a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="3"><?php echo renderEmptyState('fa-receipt', 'No collections yet', 'Record a payment to see it here.'); ?></td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </div>

<?php include 'includes/nav_footer_lending.php'; ?>
<?php if ($hasTrendData): ?>
<script>
(function () {
    var ctx = document.getElementById('collectorTrendChart');
    if (!ctx || typeof Chart === 'undefined') return;
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($collectionTrendLabels, JSON_UNESCAPED_UNICODE); ?>,
            datasets: [{
                label: 'Collected (₱)',
                data: <?php echo json_encode($collectionTrendValues, JSON_UNESCAPED_UNICODE); ?>,
                backgroundColor: 'rgba(21, 91, 217, 0.55)',
                borderColor: 'rgba(21, 91, 217, 0.9)',
                borderWidth: 1,
                borderRadius: 8,
                maxBarThickness: 36
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            var v = ctx.parsed.y || 0;
                            return ' ₱' + v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        }
                    }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#52637a', font: { size: 11, weight: '600' } } },
                y: {
                    beginAtZero: true,
                    ticks: {
                        color: '#7a8aa7',
                        callback: function (v) { return '₱' + Number(v).toLocaleString(); }
                    },
                    grid: { color: 'rgba(15, 57, 116, 0.06)' }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>
</body>
</html>
