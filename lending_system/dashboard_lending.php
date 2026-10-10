<?php
/**
 * Dashboard Page for Lending Management System
 */

// Include authentication functions
require_once 'includes/auth_lending.php';

// Require login to access this page
requireStaff();

// Get current user data
$user = getCurrentUser();

function dashboardFetchRow($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
}

function dashboardFetchRows($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

if (isCollector()) {
    header('Location: collector_dashboard_lending.php');
    exit;
}

// Include database connection
require_once 'includes/db_lending.php';
require_once 'includes/ui_helpers.php';
require_once 'includes/notification_ui_helpers.php';

ensureExpensesSchema();

// Get dashboard statistics

// Total Clients
$clientsQuery = "SELECT COUNT(*) as total FROM clients";
$totalClients = dashboardFetchRow($clientsQuery)['total'] ?? 0;

// Active Loans
$activeLoansQuery = "SELECT COUNT(*) as total FROM loans WHERE status = 'Active'";
$activeLoans = dashboardFetchRow($activeLoansQuery)['total'] ?? 0;

// Overdue Loans
$overdueLoansQuery = "SELECT COUNT(*) as total FROM loans WHERE status = 'Overdue'";
$overdueLoans = dashboardFetchRow($overdueLoansQuery)['total'] ?? 0;

// Paid Loans
$paidLoansQuery = "SELECT COUNT(*) as total FROM loans WHERE status = 'Paid'";
$paidLoans = dashboardFetchRow($paidLoansQuery)['total'] ?? 0;

// Total Collection Today
$todayDate = date('Y-m-d');
$todayCollectionQuery = "SELECT SUM(amount_paid) as total FROM payments WHERE payment_date = ?";
$todayCollection = dashboardFetchRow($todayCollectionQuery, [$todayDate])['total'] ?? 0;

// Total Payments
$totalPaymentsQuery = "SELECT SUM(amount_paid) as total FROM payments";
$totalPayments = dashboardFetchRow($totalPaymentsQuery)['total'] ?? 0;

// Total Expenses
$totalExpensesQuery = "SELECT SUM(amount) as total FROM expenses";
$totalExpenses = dashboardFetchRow($totalExpensesQuery)['total'] ?? 0;

// Expense breakdown by category
$expenseBreakdownQuery = "SELECT category, SUM(amount) AS total_amount FROM expenses GROUP BY category ORDER BY total_amount DESC";
$expenseBreakdownRows = dashboardFetchRows($expenseBreakdownQuery);
$expenseLabels = [];
$expenseData = [];
$expenseColors = [];
$categoryColorMap = [
    'Transportation' => '#2563eb',
    'Office Supplies' => '#f59e0b',
    'Utilities' => '#10b981',
    'Maintenance' => '#ef4444',
    'Salaries' => '#8b5cf6',
    'Miscellaneous' => '#6366f1'
];

foreach ($expenseBreakdownRows as $row) {
    $label = $row['category'] ?: 'Uncategorized';
    $expenseLabels[] = $label;
    $expenseData[] = (float)$row['total_amount'];
    $expenseColors[] = $categoryColorMap[$label] ?? '#6b7280';
}

$hasExpenseChartData = count($expenseBreakdownRows) > 0;

// Total Users
$totalUsersQuery = "SELECT COUNT(*) as total FROM users";
$totalUsers = dashboardFetchRow($totalUsersQuery)['total'] ?? 0;

ensureNotificationsSchema();
$unreadNotificationCount = getUnreadNotificationCount($user['user_id']);
$notifications = getUserNotifications($user['user_id'], 5);

// Recent Payments (last 5)
$recentPaymentsQuery = "
    SELECT p.payment_id, p.payment_date, p.amount_paid, p.collector_name, 
           c.first_name, c.last_name, l.loan_id
    FROM payments p
    JOIN loans l ON p.loan_id = l.loan_id
    JOIN clients c ON l.client_id = c.client_id
    ORDER BY p.payment_date DESC, p.payment_id DESC
    LIMIT 5
";
$recentPayments = dashboardFetchRows($recentPaymentsQuery);

$collectorMonitoringRows = dashboardFetchRows("
    SELECT COALESCE(NULLIF(TRIM(p.collector_name), ''), u.full_name, 'Unassigned') AS collector_name,
           SUM(CASE WHEN p.payment_date = CURDATE() THEN p.amount_paid + COALESCE(p.penalty_paid, 0) ELSE 0 END) AS today_total,
           SUM(CASE WHEN p.payment_date BETWEEN DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) AND CURDATE() THEN p.amount_paid + COALESCE(p.penalty_paid, 0) ELSE 0 END) AS week_total,
           SUM(CASE WHEN p.payment_date BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND CURDATE() THEN p.amount_paid + COALESCE(p.penalty_paid, 0) ELSE 0 END) AS month_total
    FROM payments p
    LEFT JOIN users u ON u.user_id = p.collector_id
    GROUP BY collector_name
    ORDER BY month_total DESC, week_total DESC
    LIMIT 10
");

// Overdue Loans (top 5)
$overdueLoansListQuery = "
    SELECT l.loan_id, l.loan_amount, l.total_payable, l.due_date, 
           c.first_name, c.last_name, c.contact
    FROM loans l
    JOIN clients c ON l.client_id = c.client_id
    WHERE l.status = 'Overdue'
    ORDER BY l.due_date ASC
    LIMIT 5
";
$overdueLoanslist = dashboardFetchRows($overdueLoansListQuery);

// Calculate total loan amount
$totalLoanAmountQuery = "SELECT SUM(loan_amount) as total FROM loans WHERE status = 'Active'";
$totalLoanAmount = dashboardFetchRow($totalLoanAmountQuery)['total'] ?? 0;

// Calculate total receivable amount
$totalReceivableQuery = "SELECT SUM(total_payable) as total FROM loans WHERE status = 'Active'";
$totalReceivable = dashboardFetchRow($totalReceivableQuery)['total'] ?? 0;

// Format currency values
$formattedTodayCollection = number_format($todayCollection, 2);
$formattedTotalLoanAmount = number_format($totalLoanAmount, 2);
$formattedTotalReceivable = number_format($totalReceivable, 2);

// Calculate expected profit
$expectedProfit = $totalReceivable - $totalLoanAmount;
$formattedExpectedProfit = number_format($expectedProfit, 2);

$totalLoans = (int)(dashboardFetchRow('SELECT COUNT(*) AS total FROM loans')['total'] ?? 0);
$totalReleasedAmount = (float)(dashboardFetchRow('SELECT COALESCE(SUM(loan_amount), 0) AS total FROM loans')['total'] ?? 0);
$totalCollectedAmount = (float)(dashboardFetchRow('SELECT COALESCE(SUM(amount_paid + COALESCE(penalty_paid, 0)), 0) AS total FROM payments')['total'] ?? 0);
$outstandingBalance = (float)(dashboardFetchRow("
    SELECT COALESCE(SUM(GREATEST(l.total_payable - COALESCE(p.total_paid, 0), 0)), 0) AS total
    FROM loans l
    LEFT JOIN (SELECT loan_id, SUM(amount_paid) AS total_paid FROM payments GROUP BY loan_id) p ON p.loan_id = l.loan_id
    WHERE l.status IN ('Active', 'Overdue')
")['total'] ?? 0);
$pendingApplications = (int)(dashboardFetchRow("SELECT COUNT(*) AS total FROM loan_applications WHERE status IN ('Pending Review', 'Documents Incomplete', 'Ready for Release', 'Agreement Accepted')")['total'] ?? 0);

$thisMonthStart = date('Y-m-01');
$lastMonthStart = date('Y-m-01', strtotime('-1 month'));
$lastMonthEnd = date('Y-m-t', strtotime('-1 month'));
$loansThisMonth = (int)(dashboardFetchRow('SELECT COUNT(*) AS total FROM loans WHERE date_released >= ?', [$thisMonthStart])['total'] ?? 0);
$loansLastMonth = (int)(dashboardFetchRow('SELECT COUNT(*) AS total FROM loans WHERE date_released BETWEEN ? AND ?', [$lastMonthStart, $lastMonthEnd])['total'] ?? 0);
$loanGrowthPercent = $loansLastMonth > 0 ? round((($loansThisMonth - $loansLastMonth) / $loansLastMonth) * 100, 1) : null;
$collectionRatePercent = $totalReleasedAmount > 0 ? round(($totalCollectedAmount / $totalReleasedAmount) * 100, 1) : null;

$loanStatusRows = dashboardFetchRows('SELECT status, COUNT(*) AS total FROM loans GROUP BY status ORDER BY total DESC');
$loanStatusLabels = [];
$loanStatusData = [];
$loanStatusColorsList = [];
$loanStatusColors = [
    'Active' => '#1e9c6e',
    'Overdue' => '#e03e4e',
    'Paid' => '#2b8cff',
];
foreach ($loanStatusRows as $row) {
    $label = trim((string)($row['status'] ?? 'Unknown'));
    $loanStatusLabels[] = $label;
    $loanStatusData[] = (int)($row['total'] ?? 0);
    $loanStatusColorsList[] = $loanStatusColors[$label] ?? '#64748b';
}
$hasLoanStatusChartData = array_sum($loanStatusData) > 0;
$loanStatusTotalCount = array_sum($loanStatusData);

$releaseTrendLabels = [];
$releaseTrendData = [];
$collectionTrendData = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $monthStart = $month . '-01';
    $monthEnd = date('Y-m-t', strtotime($monthStart));
    $releaseTrendLabels[] = date('M Y', strtotime($monthStart));
    $releaseTrendData[] = (float)(dashboardFetchRow('SELECT COALESCE(SUM(loan_amount), 0) AS total FROM loans WHERE date_released BETWEEN ? AND ?', [$monthStart, $monthEnd])['total'] ?? 0);
    $collectionTrendData[] = (float)(dashboardFetchRow('SELECT COALESCE(SUM(amount_paid + COALESCE(penalty_paid, 0)), 0) AS total FROM payments WHERE payment_date BETWEEN ? AND ?', [$monthStart, $monthEnd])['total'] ?? 0);
}
$hasReleaseTrendData = array_sum($releaseTrendData) > 0;
$hasCollectionTrendData = array_sum($collectionTrendData) > 0;

$recentApplications = dashboardFetchRows("
    SELECT id, borrower_name, loan_amount, status, submitted_at
    FROM loan_applications
    ORDER BY submitted_at DESC
    LIMIT 5
");

$collectionLabelsJSON = json_encode($releaseTrendLabels);
$collectionDataJSON = json_encode($collectionTrendData);
$releaseDataJSON = json_encode($releaseTrendData);
$expenseLabelsJSON = json_encode($expenseLabels);
$expenseDataJSON = json_encode($expenseData);
$expenseColorsJSON = json_encode($expenseColors);
$loanStatusLabelsJSON = json_encode($loanStatusLabels);
$loanStatusDataJSON = json_encode($loanStatusData);
$loanStatusColorsJSON = json_encode($loanStatusColorsList ?? []);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/lending_assets.php'; lendingRenderPwaMeta(); ?>
    <title>Dashboard - Lending Management System</title>
    <!-- Bootstrap CSS -->
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <!-- Chart.js -->
    <script src="assets/vendor/chartjs/chart.umd.min.js"></script>
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/lending_styles.css">
    <link rel="stylesheet" href="css/pwa.css">
</head>
<body>
    <?php include 'includes/nav_lending.php'; ?>

    <div class="app-content">
        <div class="dashboard-header">
            <div>
                <div class="dashboard-breadcrumb"><a href="dashboard_lending.php">Home</a> / Dashboard</div>
                <h1 class="page-title mb-1"><i class="fas fa-gauge-high me-2"></i>Executive Dashboard</h1>
                <p class="page-subtitle mb-0">Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>. Real-time portfolio and collection insights.</p>
            </div>
            <div class="quick-actions">
                <a href="loan_applications_lending.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-file-signature me-1"></i> Applications</a>
                <a href="payments_lending.php?add=true" class="btn btn-primary btn-sm"><i class="fas fa-cash-register me-1"></i> Record Payment</a>
            </div>
        </div>

        <div class="kpi-grid">
            <div class="kpi-card kpi-card--primary">
                <div class="kpi-card__top"><div class="kpi-card__label">Total Loans</div><div class="kpi-card__icon"><i class="fas fa-layer-group"></i></div></div>
                <div class="kpi-card__value"><?php echo number_format($totalLoans); ?></div>
                <div class="kpi-card__meta"><?php if ($loanGrowthPercent !== null): ?><span class="kpi-card__delta <?php echo $loanGrowthPercent < 0 ? 'is-negative' : ''; ?>"><?php echo ($loanGrowthPercent >= 0 ? '+' : '') . $loanGrowthPercent; ?>%</span> released vs last month · <?php endif; ?><?php echo number_format($totalClients); ?> clients</div>
            </div>
            <div class="kpi-card kpi-card--success">
                <div class="kpi-card__top"><div class="kpi-card__label">Active Loans</div><div class="kpi-card__icon"><i class="fas fa-hand-holding-dollar"></i></div></div>
                <div class="kpi-card__value"><?php echo number_format($activeLoans); ?></div>
                <div class="kpi-card__meta"><?php echo number_format($paidLoans); ?> fully paid</div>
            </div>
            <div class="kpi-card kpi-card--info">
                <div class="kpi-card__top"><div class="kpi-card__label">Total Released</div><div class="kpi-card__icon"><i class="fas fa-sack-dollar"></i></div></div>
                <div class="kpi-card__value">₱<?php echo number_format($totalReleasedAmount, 0); ?></div>
                <div class="kpi-card__meta">Principal released across portfolio</div>
            </div>
            <div class="kpi-card kpi-card--success">
                <div class="kpi-card__top"><div class="kpi-card__label">Total Collected</div><div class="kpi-card__icon"><i class="fas fa-money-check-dollar"></i></div></div>
                <div class="kpi-card__value">₱<?php echo number_format($totalCollectedAmount, 0); ?></div>
                <div class="kpi-card__meta">Today: ₱<?php echo $formattedTodayCollection; ?></div>
            </div>
            <div class="kpi-card kpi-card--warning">
                <div class="kpi-card__top"><div class="kpi-card__label">Outstanding Balance</div><div class="kpi-card__icon"><i class="fas fa-scale-balanced"></i></div></div>
                <div class="kpi-card__value">₱<?php echo number_format($outstandingBalance, 0); ?></div>
                <div class="kpi-card__meta">Receivable: ₱<?php echo $formattedTotalReceivable; ?></div>
            </div>
            <div class="kpi-card kpi-card--danger">
                <div class="kpi-card__top"><div class="kpi-card__label">Overdue Loans</div><div class="kpi-card__icon"><i class="fas fa-triangle-exclamation"></i></div></div>
                <div class="kpi-card__value"><?php echo number_format($overdueLoans); ?></div>
                <div class="kpi-card__meta">Requires follow-up</div>
            </div>
            <div class="kpi-card kpi-card--info">
                <div class="kpi-card__top"><div class="kpi-card__label">Fully Paid Loans</div><div class="kpi-card__icon"><i class="fas fa-circle-check"></i></div></div>
                <div class="kpi-card__value"><?php echo number_format($paidLoans); ?></div>
                <div class="kpi-card__meta">Completed loan accounts</div>
            </div>
            <div class="kpi-card kpi-card--warning">
                <div class="kpi-card__top"><div class="kpi-card__label">Pending Applications</div><div class="kpi-card__icon"><i class="fas fa-file-circle-plus"></i></div></div>
                <div class="kpi-card__value"><?php echo number_format($pendingApplications); ?></div>
                <div class="kpi-card__meta"><?php echo number_format($unreadNotificationCount); ?> unread notifications</div>
            </div>
        </div>

        <section class="dashboard-section" aria-labelledby="dashboardAnalyticsHeading">
            <div class="dashboard-section__head">
                <div>
                    <h2 class="dashboard-section__title" id="dashboardAnalyticsHeading">Portfolio analytics</h2>
                    <div class="dashboard-section__hint">Live trends from releases, collections, and loan status<?php if ($collectionRatePercent !== null): ?> · <?php echo $collectionRatePercent; ?>% collected vs total released<?php endif; ?></div>
                </div>
                <span class="chart-badge"><i class="fas fa-calendar-days" aria-hidden="true"></i> Last 6 months</span>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-xl-8">
                    <div class="chart-panel">
                        <div class="chart-panel__header">
                            <div>
                                <h6 class="m-0 fw-bold">Collection vs release performance</h6>
                                <small class="text-muted">Monthly cash-in compared to principal released</small>
                            </div>
                            <span class="chart-badge">Trend</span>
                        </div>
                        <div class="chart-panel__body chart-panel__body--tight">
                            <?php if ($hasCollectionTrendData || $hasReleaseTrendData): ?>
                                <div class="chart-canvas-shell" style="height:min(320px, 42vh)"><canvas id="portfolioTrendChart" aria-label="Collection versus release trend chart"></canvas></div>
                            <?php else: ?>
                                <div class="chart-empty"><div><i class="fas fa-chart-line"></i><div>No data available for this period.</div></div></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="chart-panel">
                        <div class="chart-panel__header">
                            <div>
                                <h6 class="m-0 fw-bold">Loan status distribution</h6>
                                <small class="text-muted"><?php echo number_format($loanStatusTotalCount); ?> loans in portfolio</small>
                            </div>
                        </div>
                        <div class="chart-panel__body chart-panel__body--tight">
                            <?php if ($hasLoanStatusChartData): ?>
                                <div class="chart-canvas-shell chart-canvas-shell--small" style="height:min(320px, 42vh)"><canvas id="loanStatusChart" aria-label="Loan status distribution chart"></canvas></div>
                            <?php else: ?>
                                <div class="chart-empty"><div><i class="fas fa-chart-pie"></i><div>No data available for this period.</div></div></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-xl-6">
                    <div class="chart-panel">
                        <div class="chart-panel__header">
                            <div><h6 class="m-0 fw-bold">Monthly collections</h6><small class="text-muted">Payment volume by month</small></div>
                            <span class="chart-badge">Bar</span>
                        </div>
                        <div class="chart-panel__body">
                            <?php if ($hasCollectionTrendData): ?>
                                <div class="chart-canvas-shell" style="height:min(280px, 38vh)"><canvas id="collectionTrendChart" aria-label="Monthly collections chart"></canvas></div>
                            <?php else: ?>
                                <div class="chart-empty"><div><i class="fas fa-coins"></i><div>No payments recorded yet.</div></div></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-xl-6">
                    <div class="chart-panel">
                        <div class="chart-panel__header">
                            <div><h6 class="m-0 fw-bold">Expense breakdown</h6><small class="text-muted">Operating expenses by category</small></div>
                            <span class="chart-badge">Share</span>
                        </div>
                        <div class="chart-panel__body">
                            <?php if ($hasExpenseChartData): ?>
                                <div class="chart-canvas-shell chart-canvas-shell--small" style="height:min(280px, 38vh)"><canvas id="expenseBreakdownChart" aria-label="Expense breakdown chart"></canvas></div>
                            <?php else: ?>
                                <div class="chart-empty"><div><i class="fas fa-receipt"></i><div>No expense records available.</div></div></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="dashboard-section" aria-labelledby="dashboardOperationsHeading">
            <div class="dashboard-section__head">
                <h2 class="dashboard-section__title" id="dashboardOperationsHeading">Operations &amp; risk</h2>
                <span class="dashboard-section__hint">Financial position and accounts needing attention</span>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-lg-5">
                    <div class="panel-card panel-card--flush h-100">
                        <div class="chart-panel__header">
                            <h6 class="m-0 fw-bold"><i class="fas fa-wallet me-2 text-primary"></i>Financial summary</h6>
                        </div>
                        <div class="chart-panel__body">
                            <ul class="finance-summary-list">
                                <li><span>Active principal</span><strong>₱<?php echo $formattedTotalLoanAmount; ?></strong></li>
                                <li><span>Active receivable</span><strong>₱<?php echo $formattedTotalReceivable; ?></strong></li>
                                <li><span>Total expenses (all time)</span><strong>₱<?php echo number_format((float)$totalExpenses, 2); ?></strong></li>
                                <li><span>Today's collection</span><strong>₱<?php echo $formattedTodayCollection; ?></strong></li>
                                <li class="is-highlight"><span>Expected interest profit (active)</span><strong>₱<?php echo $formattedExpectedProfit; ?></strong></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="panel-card panel-card--flush h-100">
                        <div class="chart-panel__header table-panel__toolbar">
                            <h6 class="m-0 fw-bold"><i class="fas fa-triangle-exclamation me-2 text-danger"></i>Overdue loans</h6>
                            <a href="loans_lending.php?status=Overdue" class="btn btn-sm btn-outline-primary">View all</a>
                        </div>
                        <div class="chart-panel__body pt-0 px-0 pb-0">
                            <?php if (count($overdueLoanslist) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 data-table">
                                    <thead>
                                        <tr>
                                            <th>Client</th>
                                            <th>Loan</th>
                                            <th>Amount</th>
                                            <th>Due</th>
                                            <th>Contact</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($overdueLoanslist as $loan): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']); ?></td>
                                            <td><a href="loan_details_lending.php?id=<?php echo (int)$loan['loan_id']; ?>">#<?php echo (int)$loan['loan_id']; ?></a></td>
                                            <td class="table-amount">₱<?php echo number_format((float)$loan['loan_amount'], 2); ?></td>
                                            <td><?php echo renderLoanStatusPill('Overdue'); ?> <span class="small text-muted d-block"><?php echo date('M d, Y', strtotime($loan['due_date'])); ?></span></td>
                                            <td><?php echo htmlspecialchars($loan['contact']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="p-4"><?php echo renderEmptyState('fa-circle-check', 'No overdue loans', 'All active accounts are current on follow-up.'); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel-card panel-card--flush mb-4">
                <div class="chart-panel__header table-panel__toolbar">
                    <div>
                        <h6 class="m-0 fw-bold"><i class="fas fa-user-tie me-2 text-primary"></i>Collector collection monitoring</h6>
                        <small class="text-muted">Today, week-to-date, and month-to-date totals</small>
                    </div>
                    <a href="reports_lending.php" class="btn btn-sm btn-outline-primary">Full reports</a>
                </div>
                <div class="chart-panel__body pt-0 px-0 pb-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 data-table">
                            <thead>
                                <tr>
                                    <th>Collector</th>
                                    <th class="text-end">Today</th>
                                    <th class="text-end">This week</th>
                                    <th class="text-end">This month</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($collectorMonitoringRows) === 0): ?>
                                    <tr><td colspan="4"><div class="py-4"><?php echo renderEmptyState('fa-hand-holding-dollar', 'No collections recorded yet'); ?></div></td></tr>
                                <?php else: ?>
                                    <?php foreach ($collectorMonitoringRows as $index => $collectorRow): ?>
                                        <tr<?php echo $index === 0 ? ' class="data-table__highlight"' : ''; ?>>
                                            <td class="fw-semibold"><?php echo htmlspecialchars((string)($collectorRow['collector_name'] ?? 'Unassigned')); ?><?php if ($index === 0): ?> <span class="badge bg-primary-subtle text-primary ms-1">Top month</span><?php endif; ?></td>
                                            <td class="text-end table-amount">₱<?php echo number_format((float)($collectorRow['today_total'] ?? 0), 2); ?></td>
                                            <td class="text-end table-amount">₱<?php echo number_format((float)($collectorRow['week_total'] ?? 0), 2); ?></td>
                                            <td class="text-end table-amount">₱<?php echo number_format((float)($collectorRow['month_total'] ?? 0), 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <section class="dashboard-section" aria-labelledby="dashboardActivityHeading">
            <div class="dashboard-section__head">
                <h2 class="dashboard-section__title" id="dashboardActivityHeading">Recent activity</h2>
                <span class="dashboard-section__hint">Latest payments, applications, and alerts</span>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-xl-7">
                    <div class="panel-card panel-card--flush h-100">
                        <div class="chart-panel__header table-panel__toolbar">
                            <h6 class="m-0 fw-bold"><i class="fas fa-clock-rotate-left me-2"></i>Recent payments</h6>
                            <a href="payments_lending.php" class="btn btn-sm btn-outline-primary">View all</a>
                        </div>
                        <div class="chart-panel__body pt-0 px-0 pb-0">
                            <?php if (count($recentPayments) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 data-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Client</th>
                                            <th>Loan</th>
                                            <th>Amount</th>
                                            <th>Collector</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentPayments as $payment): ?>
                                        <tr>
                                            <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></td>
                                            <td><a href="loan_details_lending.php?id=<?php echo (int)$payment['loan_id']; ?>">#<?php echo (int)$payment['loan_id']; ?></a></td>
                                            <td class="table-amount">₱<?php echo number_format((float)$payment['amount_paid'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($payment['collector_name']); ?></td>
                                            <td class="text-end">
                                                <a href="payments_lending.php?view=<?php echo (int)$payment['payment_id']; ?>" class="btn btn-light border table-action-btn" title="View payment"><i class="fas fa-eye"></i></a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="p-4"><?php echo renderEmptyState('fa-receipt', 'No recent payments', 'Recorded collections will appear here.'); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-xl-5">
                    <div class="panel-card panel-card--flush h-100">
                        <div class="chart-panel__header table-panel__toolbar">
                            <h6 class="m-0 fw-bold"><i class="fas fa-bell me-2"></i>Notifications</h6>
                            <a href="notifications_lending.php" class="btn btn-sm btn-outline-primary">Inbox</a>
                        </div>
                        <div class="chart-panel__body">
                            <?php if (count($notifications) === 0): ?>
                                <?php echo renderEmptyState('fa-bell-slash', 'No notifications', 'You are all caught up.'); ?>
                            <?php else: ?>
                                <div class="notification-feed">
                                    <?php foreach ($notifications as $notification): ?>
                                        <?php echo renderNotificationFeedItem($notification, ['truncate' => 90]); ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-xl-7">
                    <div class="panel-card panel-card--flush h-100">
                        <div class="chart-panel__header table-panel__toolbar">
                            <h6 class="m-0 fw-bold"><i class="fas fa-file-signature me-2"></i>Recent loan applications</h6>
                            <a href="loan_applications_lending.php" class="btn btn-sm btn-outline-primary">View all</a>
                        </div>
                        <div class="chart-panel__body pt-0 px-0 pb-0">
                            <?php if (count($recentApplications) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0 data-table">
                                        <thead><tr><th>Borrower</th><th>Amount</th><th>Status</th><th>Submitted</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($recentApplications as $application): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string)($application['borrower_name'] ?? 'Borrower')); ?></td>
                                                <td class="table-amount">₱<?php echo number_format((float)($application['loan_amount'] ?? 0), 2); ?></td>
                                                <td><?php echo renderApplicationStatusPill((string)($application['status'] ?? 'Pending')); ?></td>
                                                <td><?php echo !empty($application['submitted_at']) ? date('M d, Y', strtotime((string)$application['submitted_at'])) : '—'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="p-4"><?php echo renderEmptyState('fa-folder-open', 'No loan applications found'); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-xl-5">
                    <div class="panel-card panel-card--flush h-100">
                        <div class="chart-panel__header"><h6 class="m-0 fw-bold"><i class="fas fa-bolt me-2 text-warning"></i>Quick actions</h6></div>
                        <div class="chart-panel__body">
                            <div class="quick-actions-grid">
                                <a class="quick-action-tile" href="clients_lending.php"><i class="fas fa-users"></i><span>Manage clients</span></a>
                                <a class="quick-action-tile" href="loans_lending.php"><i class="fas fa-hand-holding-dollar"></i><span>View loans</span></a>
                                <a class="quick-action-tile" href="loan_release_lending.php"><i class="fas fa-file-contract"></i><span>Release loan</span></a>
                                <a class="quick-action-tile" href="payments_lending.php?add=true"><i class="fas fa-cash-register"></i><span>Record payment</span></a>
                                <a class="quick-action-tile" href="reports_lending.php"><i class="fas fa-chart-line"></i><span>Open reports</span></a>
                                <a class="quick-action-tile" href="notifications_lending.php"><i class="fas fa-bell"></i><span>Notifications</span></a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>

<?php include 'includes/nav_footer_lending.php'; ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof Chart === 'undefined' || !window.LendingCharts) return;

            Chart.register(LendingCharts.centerTextPlugin);

            const base = LendingCharts.baseOptions();
            const labels = <?php echo $collectionLabelsJSON; ?>;

            const portfolioCanvas = document.getElementById('portfolioTrendChart');
            if (portfolioCanvas) {
                new Chart(portfolioCanvas.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [
                            LendingCharts.lineAreaDataset('Collected', <?php echo $collectionDataJSON; ?>, '#155bd9', 'rgba(21, 91, 217, 0.14)'),
                            LendingCharts.lineAreaDataset('Released', <?php echo $releaseDataJSON; ?>, '#1e9c6e', 'rgba(30, 156, 110, 0.12)')
                        ]
                    },
                    options: Object.assign({}, base, {
                        plugins: Object.assign({}, base.plugins, { legend: { display: true, position: 'bottom' } })
                    })
                });
            }

            const collectionCanvas = document.getElementById('collectionTrendChart');
            if (collectionCanvas) {
                new Chart(collectionCanvas.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Collections',
                            data: <?php echo $collectionDataJSON; ?>,
                            backgroundColor: 'rgba(21, 91, 217, 0.82)',
                            hoverBackgroundColor: '#155bd9',
                            borderRadius: 10,
                            borderSkipped: false,
                            maxBarThickness: 42
                        }]
                    },
                    options: base
                });
            }

            const statusCanvas = document.getElementById('loanStatusChart');
            if (statusCanvas) {
                const statusOptions = LendingCharts.doughnutOptions(<?php echo (int)$loanStatusTotalCount; ?>);
                new Chart(statusCanvas.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: <?php echo $loanStatusLabelsJSON; ?>,
                        datasets: [{
                            data: <?php echo $loanStatusDataJSON; ?>,
                            backgroundColor: <?php echo $loanStatusColorsJSON; ?>,
                            borderWidth: 3,
                            borderColor: '#ffffff',
                            hoverOffset: 8
                        }]
                    },
                    options: statusOptions
                });
            }

            const expenseCanvas = document.getElementById('expenseBreakdownChart');
            if (expenseCanvas) {
                const expenseTotal = (<?php echo $expenseDataJSON; ?> || []).reduce(function (sum, value) { return sum + Number(value || 0); }, 0);
                const expenseOptions = LendingCharts.doughnutOptions(expenseTotal > 0 ? ('₱' + expenseTotal.toLocaleString('en-PH', { maximumFractionDigits: 0 })) : '0');
                new Chart(expenseCanvas.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: <?php echo $expenseLabelsJSON; ?>,
                        datasets: [{
                            data: <?php echo $expenseDataJSON; ?>,
                            backgroundColor: <?php echo $expenseColorsJSON; ?>,
                            borderWidth: 3,
                            borderColor: '#ffffff',
                            hoverOffset: 8
                        }]
                    },
                    options: expenseOptions
                });
            }
        });
    </script>
</body>
</html>
