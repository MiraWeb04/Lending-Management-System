<?php
/**
 * Reports & Analytics Module for Lending Management System
 */

require_once 'includes/auth_lending.php';
requireLogin();
requireAdmin();
require_once 'includes/db_lending.php';

$reportTypes = [
    'dashboard' => 'Dashboard Analytics',
    'loan_reports' => 'Loan Reports',
    'payment_reports' => 'Payment Reports',
    'borrower_reports' => 'Borrower Reports',
    'collector_performance' => 'Collector Performance',
    'financial_summary' => 'Financial Summary',
];

$reportType = $_GET['type'] ?? 'dashboard';
if (!isset($reportTypes[$reportType])) {
    $reportType = 'dashboard';
}

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$collectorId = trim($_GET['collector_id'] ?? '');
$clientId = trim($_GET['client_id'] ?? '');

if (!strtotime($startDate)) {
    $startDate = date('Y-m-01');
}
if (!strtotime($endDate)) {
    $endDate = date('Y-m-d');
}

function formatCurrency($value) {
    return '₱' . number_format((float)$value, 2);
}

function formatNumber($value) {
    return number_format((float)$value);
}

function formatShortDate($value) {
    return $value ? date('M d, Y', strtotime($value)) : 'N/A';
}

function reportFetchRows($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function reportFetchRow($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
}

$totalBorrowers = reportFetchRow("SELECT COUNT(*) as total FROM users WHERE role = 'Borrower'")['total'] ?? 0;
$totalCollectors = reportFetchRow("SELECT COUNT(*) as total FROM users WHERE role = 'Collector'")['total'] ?? 0;
$totalLoans = reportFetchRow("SELECT COUNT(*) as total FROM loans")['total'] ?? 0;
$activeLoans = reportFetchRow("SELECT COUNT(*) as total FROM loans WHERE status = 'Active'")['total'] ?? 0;
$overdueLoans = reportFetchRow("SELECT COUNT(*) as total FROM loans WHERE status = 'Overdue'")['total'] ?? 0;
$paidLoans = reportFetchRow("SELECT COUNT(*) as total FROM loans WHERE status = 'Paid'")['total'] ?? 0;

$totalCollected = reportFetchRow(
    'SELECT COALESCE(SUM(amount_paid), 0) as total FROM payments WHERE payment_date BETWEEN ? AND ?',
    [$startDate, $endDate]
)['total'] ?? 0;

$totalExpenses = executeQuery(
    'SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE date BETWEEN ? AND ?',
    [$startDate, $endDate]
)->fetch()['total'] ?? 0;

$totalDisbursed = executeQuery(
    'SELECT COALESCE(SUM(loan_amount), 0) as total FROM loans WHERE date_released BETWEEN ? AND ?',
    [$startDate, $endDate]
)->fetch()['total'] ?? 0;

$totalReceivable = executeQuery(
    "SELECT COALESCE(SUM(total_payable), 0) as total FROM loans WHERE status IN ('Active','Overdue')"
)->fetch()['total'] ?? 0;

$totalOutstanding = executeQuery(
    'SELECT COALESCE(SUM(l.total_payable - COALESCE(p.total_paid, 0)), 0) as total
     FROM loans l
     LEFT JOIN (
         SELECT loan_id, SUM(amount_paid) as total_paid
         FROM payments
         GROUP BY loan_id
     ) p ON l.loan_id = p.loan_id
     WHERE l.status IN ("Active", "Overdue")',
    []
)->fetch()['total'] ?? 0;

$netIncome = $totalCollected - $totalExpenses;
$profitMargin = $totalCollected > 0 ? ($netIncome / $totalCollected) * 100 : 0;

$loanStatusRows = executeQuery(
    'SELECT status, COUNT(*) as count, COALESCE(SUM(loan_amount), 0) as amount, COALESCE(SUM(total_payable), 0) as payable
     FROM loans
     GROUP BY status'
)->fetchAll();

$loanStatus = [
    'Active' => ['count' => 0, 'amount' => 0, 'payable' => 0],
    'Overdue' => ['count' => 0, 'amount' => 0, 'payable' => 0],
    'Paid' => ['count' => 0, 'amount' => 0, 'payable' => 0],
];
foreach ($loanStatusRows as $row) {
    $status = $row['status'];
    if (isset($loanStatus[$status])) {
        $loanStatus[$status]['count'] = (int)$row['count'];
        $loanStatus[$status]['amount'] = (float)$row['amount'];
        $loanStatus[$status]['payable'] = (float)$row['payable'];
    }
}

$monthlyLabels = [];
$monthlyCollection = [];
$monthlyExpense = [];

$chartStart = new DateTime($startDate);
$chartEnd = new DateTime($endDate);
$chartEnd->modify('first day of next month');
$interval = new DateInterval('P1M');
$monthPeriod = new DatePeriod($chartStart, $interval, $chartEnd);
foreach ($monthPeriod as $month) {
    $label = $month->format('M Y');
    $monthlyLabels[] = $label;
    $monthlyCollection[$label] = 0;
    $monthlyExpense[$label] = 0;
}

$monthlyPayments = reportFetchRows(
    'SELECT DATE_FORMAT(payment_date, "%b %Y") as label, COALESCE(SUM(amount_paid), 0) as total
     FROM payments
     WHERE payment_date BETWEEN ? AND ?
     GROUP BY YEAR(payment_date), MONTH(payment_date)
     ORDER BY YEAR(payment_date), MONTH(payment_date)',
    [$startDate, $endDate]
);
foreach ($monthlyPayments as $row) {
    if (array_key_exists($row['label'], $monthlyCollection)) {
        $monthlyCollection[$row['label']] = (float)$row['total'];
    }
}

$monthlyExpenses = reportFetchRows(
    'SELECT DATE_FORMAT(date, "%b %Y") as label, COALESCE(SUM(amount), 0) as total
     FROM expenses
     WHERE date BETWEEN ? AND ?
     GROUP BY YEAR(date), MONTH(date)
     ORDER BY YEAR(date), MONTH(date)',
    [$startDate, $endDate]
);
foreach ($monthlyExpenses as $row) {
    if (array_key_exists($row['label'], $monthlyExpense)) {
        $monthlyExpense[$row['label']] = (float)$row['total'];
    }
}

$paymentSummary = executeQuery(
    'SELECT COUNT(*) as count, COALESCE(SUM(amount_paid), 0) as total, COALESCE(AVG(amount_paid), 0) as average
     FROM payments
     WHERE payment_date BETWEEN ? AND ?',
    [$startDate, $endDate]
)->fetch(PDO::FETCH_ASSOC);

$collectorFilterSql = '';
$collectorParams = [$startDate, $endDate];
if ($collectorId !== '') {
    $collectorFilterSql = ' AND u.user_id = ?';
    $collectorParams[] = $collectorId;
}

$paymentsByCollector = executeQuery(
    'SELECT u.user_id, u.full_name as collector_name, COALESCE(SUM(p.amount_paid), 0) as total_collected, COUNT(p.payment_id) as payments_count
     FROM users u
     LEFT JOIN clients c ON c.collector_id = u.user_id
     LEFT JOIN loans l ON l.client_id = c.client_id
     LEFT JOIN payments p ON p.loan_id = l.loan_id AND p.payment_date BETWEEN ? AND ?
     WHERE u.role = "Collector"' . $collectorFilterSql . '
     GROUP BY u.user_id, u.full_name
     ORDER BY total_collected DESC
     LIMIT 12',
    $collectorParams
)->fetchAll(PDO::FETCH_ASSOC);

$recentPayments = executeQuery(
    'SELECT p.payment_id, p.receipt_number, p.payment_date, p.amount_paid, p.collector_name, l.loan_number, CONCAT(c.first_name, " ", c.last_name) as borrower_name
     FROM payments p
     JOIN loans l ON p.loan_id = l.loan_id
     JOIN clients c ON l.client_id = c.client_id
     ORDER BY p.payment_date DESC, p.payment_id DESC
     LIMIT 10'
)->fetchAll(PDO::FETCH_ASSOC);

$borrowerFilterSql = '';
$borrowerParams = [$startDate, $endDate];
if ($clientId !== '') {
    $borrowerFilterSql = 'WHERE c.client_id = ?';
    array_unshift($borrowerParams, $clientId);
}

$borrowerData = executeQuery(
    'SELECT c.client_id,
            CONCAT(c.first_name, " ", c.last_name) as borrower_name,
            COALESCE(loans.loan_count, 0) as loan_count,
            COALESCE(loans.total_borrowed, 0) as total_borrowed,
            COALESCE(loans.total_payable, 0) as total_payable,
            COALESCE(payments.total_paid, 0) as total_paid,
            COALESCE(loans.active_loans, 0) as active_loans,
            COALESCE(loans.overdue_loans, 0) as overdue_loans,
            COALESCE(loans.paid_loans, 0) as paid_loans
     FROM clients c
     LEFT JOIN (
         SELECT client_id,
                COUNT(*) as loan_count,
                COALESCE(SUM(loan_amount), 0) as total_borrowed,
                COALESCE(SUM(total_payable), 0) as total_payable,
                SUM(CASE WHEN status = "Active" THEN 1 ELSE 0 END) as active_loans,
                SUM(CASE WHEN status = "Overdue" THEN 1 ELSE 0 END) as overdue_loans,
                SUM(CASE WHEN status = "Paid" THEN 1 ELSE 0 END) as paid_loans
         FROM loans
         GROUP BY client_id
     ) loans ON loans.client_id = c.client_id
     LEFT JOIN (
         SELECT l.client_id, COALESCE(SUM(p.amount_paid), 0) as total_paid
         FROM payments p
         JOIN loans l ON p.loan_id = l.loan_id
         WHERE p.payment_date BETWEEN ? AND ?
         GROUP BY l.client_id
     ) payments ON payments.client_id = c.client_id
     ' . $borrowerFilterSql . '
     ORDER BY loans.total_borrowed DESC
     LIMIT 20',
    $borrowerParams
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($borrowerData as &$borrower) {
    $borrower['remaining_balance'] = max(0, $borrower['total_payable'] - $borrower['total_paid']);
}
unset($borrower);

$collectorPerformance = executeQuery(
    'SELECT u.user_id,
            u.full_name as collector_name,
            COALESCE(stats.total_collected, 0) as total_collected,
            COALESCE(stats.payment_count, 0) as payment_count,
            COALESCE(stats.active_loans, 0) as active_loans,
            COALESCE(stats.overdue_loans, 0) as overdue_loans
     FROM users u
     LEFT JOIN (
         SELECT c.collector_id,
                COALESCE(SUM(p.amount_paid), 0) as total_collected,
                COUNT(p.payment_id) as payment_count,
                SUM(CASE WHEN l.status = "Active" THEN 1 ELSE 0 END) as active_loans,
                SUM(CASE WHEN l.status = "Overdue" THEN 1 ELSE 0 END) as overdue_loans
         FROM clients c
         LEFT JOIN loans l ON l.client_id = c.client_id
         LEFT JOIN payments p ON p.loan_id = l.loan_id AND p.payment_date BETWEEN ? AND ?
         GROUP BY c.collector_id
     ) stats ON stats.collector_id = u.user_id
     WHERE u.role = "Collector"
     ORDER BY stats.total_collected DESC
     LIMIT 15',
    [$startDate, $endDate]
)->fetchAll(PDO::FETCH_ASSOC);

$expenseBreakdown = executeQuery(
    'SELECT description, COALESCE(SUM(amount), 0) as total
     FROM expenses
     WHERE date BETWEEN ? AND ?
     GROUP BY description
     ORDER BY total DESC
     LIMIT 8',
    [$startDate, $endDate]
)->fetchAll(PDO::FETCH_ASSOC);

$topBorrowers = array_slice($borrowerData, 0, 10);

$chartData = [
    'months' => array_values($monthlyLabels),
    'collections' => array_values($monthlyCollection),
    'expenses' => array_values($monthlyExpense),
    'status_labels' => array_values(array_filter(array_keys($loanStatus), function ($status) use ($loanStatus) { return $loanStatus[$status]['count'] > 0; })),
    'status_counts' => array_values(array_map(function ($status) use ($loanStatus) { return $loanStatus[$status]['count']; }, array_filter(array_keys($loanStatus), function ($status) use ($loanStatus) { return $loanStatus[$status]['count'] > 0; }))),
    'collector_labels' => array_column($paymentsByCollector, 'collector_name'),
    'collector_values' => array_column($paymentsByCollector, 'total_collected'),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - Lending Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="css/lending_styles.css">
    <style>
        .analytics-nav .nav-link { padding: 0.75rem 1rem; border-radius: 0.95rem; color: var(--text-secondary); font-weight: 600; }
        .analytics-nav .nav-link.active { background: rgba(21, 91, 217, 0.12); color: var(--primary); }
        .dashboard-metric { border-radius: 1.25rem; background: linear-gradient(135deg, #ffffff 0%, #f7faff 100%); border: 1px solid rgba(15, 57, 116, 0.08); }
        .dashboard-metric .metric-label { color: var(--text-secondary); letter-spacing: 0.08em; text-transform: uppercase; font-size: 0.78rem; font-weight: 700; }
        .dashboard-metric .metric-value { font-size: 1.75rem; font-weight: 800; color: var(--text-primary); }
    </style>
</head>
<body>
    <?php include 'includes/nav_lending.php'; ?>
    <div class="container-fluid py-4">
        <div class="row mb-4">
            <div class="col-lg-8">
                <h1 class="h2 fw-bold mb-2"><i class="fas fa-chart-line me-2 text-primary"></i>Reports & Analytics</h1>
                <p class="text-muted mb-0">Admin analytics, loan performance, payment oversight, borrower insights, and financial summaries in one centralized dashboard.</p>
            </div>
            <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
                <div class="btn-toolbar justify-content-lg-end gap-2" role="toolbar">
                    <button type="button" class="btn btn-light" onclick="window.print()"><i class="fas fa-print me-1"></i> Print</button>
                    <button type="button" class="btn btn-outline-danger" onclick="exportToPDF()"><i class="fas fa-file-pdf me-1"></i> PDF</button>
                    <button type="button" class="btn btn-outline-success" onclick="exportToExcel()"><i class="fas fa-file-excel me-1"></i> Excel</button>
                </div>
            </div>
        </div>

        <nav class="mb-4">
            <ul class="nav nav-pills analytics-nav flex-wrap gap-2">
                <?php foreach ($reportTypes as $type => $label): ?>
                    <li class="nav-item">
                        <a class="nav-link<?php echo $reportType === $type ? ' active' : ''; ?>" href="reports_lending.php?type=<?php echo $type; ?>&start_date=<?php echo htmlspecialchars($startDate); ?>&end_date=<?php echo htmlspecialchars($endDate); ?><?php echo $collectorId !== '' ? '&collector_id=' . urlencode($collectorId) : ''; ?><?php echo $clientId !== '' ? '&client_id=' . urlencode($clientId) : ''; ?>"><?php echo htmlspecialchars($label); ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <form action="reports_lending.php" method="get" class="row g-3 align-items-end">
                    <input type="hidden" name="type" value="<?php echo htmlspecialchars($reportType); ?>">
                    <div class="col-md-3">
                        <label class="form-label">From</label>
                        <input type="date" class="form-control" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">To</label>
                        <input type="date" class="form-control" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
                    </div>
                    <?php if ($reportType === 'payment_reports' || $reportType === 'collector_performance'): ?>
                        <div class="col-md-3">
                            <label class="form-label">Collector</label>
                            <select class="form-select" name="collector_id">
                                <option value="">All Collectors</option>
                                <?php $collectors = executeQuery('SELECT user_id, full_name FROM users WHERE role = ? AND status = ? ORDER BY full_name', ['Collector', 'Active'])->fetchAll(PDO::FETCH_ASSOC); ?>
                                <?php foreach ($collectors as $collector): ?>
                                    <option value="<?php echo $collector['user_id']; ?>"<?php echo $collectorId === (string)$collector['user_id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars($collector['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <?php if ($reportType === 'borrower_reports'): ?>
                        <div class="col-md-3">
                            <label class="form-label">Borrower</label>
                            <select class="form-select" name="client_id">
                                <option value="">All Borrowers</option>
                                <?php $borrowers = executeQuery('SELECT client_id, CONCAT(first_name, " ", last_name) as borrower_name FROM clients ORDER BY last_name, first_name')->fetchAll(PDO::FETCH_ASSOC); ?>
                                <?php foreach ($borrowers as $borrower): ?>
                                    <option value="<?php echo $borrower['client_id']; ?>"<?php echo $clientId === (string)$borrower['client_id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars($borrower['borrower_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="col-md-<?php echo ($reportType === 'payment_reports' || $reportType === 'collector_performance' || $reportType === 'borrower_reports') ? '3' : '6'; ?>">
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i> Apply Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($reportType === 'dashboard'): ?>
            <div class="row g-4 mb-4">
                <div class="col-xl-3 col-md-6">
                    <div class="card dashboard-metric shadow-sm h-100 p-4">
                        <div class="metric-label">Borrowers</div>
                        <div class="metric-value"><?php echo formatNumber($totalBorrowers); ?></div>
                        <small class="text-muted">Registered borrower accounts</small>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card dashboard-metric shadow-sm h-100 p-4">
                        <div class="metric-label">Active Loans</div>
                        <div class="metric-value"><?php echo formatNumber($activeLoans); ?></div>
                        <small class="text-muted">Loans currently in repayment</small>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card dashboard-metric shadow-sm h-100 p-4">
                        <div class="metric-label">Overdue Loans</div>
                        <div class="metric-value"><?php echo formatNumber($overdueLoans); ?></div>
                        <small class="text-muted">Accounts needing attention</small>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card dashboard-metric shadow-sm h-100 p-4">
                        <div class="metric-label">Collections</div>
                        <div class="metric-value"><?php echo formatCurrency($totalCollected); ?></div>
                        <small class="text-muted">Cash received in date range</small>
                    </div>
                </div>
            </div>
            <div class="row g-4 mb-4">
                <div class="col-xl-3 col-md-6">
                    <div class="card dashboard-metric shadow-sm h-100 p-4">
                        <div class="metric-label">Disbursed</div>
                        <div class="metric-value"><?php echo formatCurrency($totalDisbursed); ?></div>
                        <small class="text-muted">Loans released in date range</small>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card dashboard-metric shadow-sm h-100 p-4">
                        <div class="metric-label">Expenses</div>
                        <div class="metric-value"><?php echo formatCurrency($totalExpenses); ?></div>
                        <small class="text-muted">Operational spend in date range</small>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card dashboard-metric shadow-sm h-100 p-4">
                        <div class="metric-label">Net Income</div>
                        <div class="metric-value"><?php echo formatCurrency($netIncome); ?></div>
                        <small class="text-muted">Collections minus expenses</small>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card dashboard-metric shadow-sm h-100 p-4">
                        <div class="metric-label">Profit Margin</div>
                        <div class="metric-value"><?php echo round($profitMargin, 1); ?>%</div>
                        <small class="text-muted">Performance over date range</small>
                    </div>
                </div>
            </div>
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-light py-3 border-bottom">
                            <h5 class="mb-0">Collection vs Expenses Trend</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="trendChart" style="min-height: 320px;"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-light py-3 border-bottom">
                            <h5 class="mb-0">Loan Status Mix</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="statusChart" style="min-height: 320px;"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($reportType === 'loan_reports'): ?>
            <div class="row g-4 mb-4">
                <?php foreach ($loanStatus as $status => $data): ?>
                    <div class="col-md-4">
                        <div class="card shadow-sm h-100 p-4">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <div class="text-uppercase text-muted fs-7 fw-bold"><?php echo $status; ?> Loans</div>
                                    <div class="fs-3 fw-bold"><?php echo formatNumber($data['count']); ?></div>
                                </div>
                                <div class="badge <?php echo $status === 'Overdue' ? 'bg-danger' : ($status === 'Paid' ? 'bg-success' : 'bg-info'); ?> text-white px-3 py-2">
                                    ₱<?php echo number_format($data['amount'], 0); ?>
                                </div>
                            </div>
                            <p class="text-muted mb-0">Total payable across <?php echo strtolower($status); ?> loans.</p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-light py-3 border-bottom">
                            <h5 class="mb-0">Loan Status Distribution</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="loanStatusChart" style="min-height: 370px;"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-light py-3 border-bottom">
                            <h5 class="mb-0">Key Loan Metrics</h5>
                        </div>
                        <div class="card-body">
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                    Total Loans
                                    <span class="fw-bold"><?php echo formatNumber($totalLoans); ?></span>
                                </li>
                                <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                    Active Loans
                                    <span class="fw-bold"><?php echo formatNumber($activeLoans); ?></span>
                                </li>
                                <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                    Paid Loans
                                    <span class="fw-bold"><?php echo formatNumber($paidLoans); ?></span>
                                </li>
                                <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                    Overdue Loans
                                    <span class="fw-bold"><?php echo formatNumber($overdueLoans); ?></span>
                                </li>
                                <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                    Outstanding Receivable
                                    <span class="fw-bold"><?php echo formatCurrency($totalOutstanding); ?></span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($reportType === 'payment_reports'): ?>
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 p-4">
                        <div class="metric-label">Total Payments</div>
                        <div class="metric-value"><?php echo formatNumber($paymentSummary['count']); ?></div>
                        <small class="text-muted">Payments recorded in range.</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 p-4">
                        <div class="metric-label">Collected</div>
                        <div class="metric-value"><?php echo formatCurrency($paymentSummary['total']); ?></div>
                        <small class="text-muted">Total amount collected.</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 p-4">
                        <div class="metric-label">Average Payment</div>
                        <div class="metric-value"><?php echo formatCurrency($paymentSummary['average']); ?></div>
                        <small class="text-muted">Average payment size.</small>
                    </div>
                </div>
            </div>
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-light py-3 border-bottom">
                            <h5 class="mb-0">Collector Collections</h5>
                        </div>
                        <div class="card-body table-responsive">
                            <table class="table table-borderless mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Collector</th>
                                        <th class="text-end">Collected</th>
                                        <th class="text-center">Payments</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($paymentsByCollector)): ?>
                                        <tr><td colspan="3" class="text-center text-muted py-4">No collector collection data available.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($paymentsByCollector as $collector): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($collector['collector_name']); ?></td>
                                                <td class="text-end"><?php echo formatCurrency($collector['total_collected']); ?></td>
                                                <td class="text-center"><?php echo formatNumber($collector['payments_count']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-light py-3 border-bottom">
                            <h5 class="mb-0">Recent Payments</h5>
                        </div>
                        <div class="card-body table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Receipt</th>
                                        <th>Borrower</th>
                                        <th class="text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentPayments)): ?>
                                        <tr><td colspan="4" class="text-center text-muted py-4">No recent payment records found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($recentPayments as $payment): ?>
                                            <tr>
                                                <td><?php echo formatShortDate($payment['payment_date']); ?></td>
                                                <td><?php echo htmlspecialchars($payment['receipt_number']); ?></td>
                                                <td><?php echo htmlspecialchars($payment['borrower_name']); ?></td>
                                                <td class="text-end"><?php echo formatCurrency($payment['amount_paid']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($reportType === 'borrower_reports'): ?>
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="card shadow-sm h-100 p-4">
                        <div class="metric-label">Borrowers</div>
                        <div class="metric-value"><?php echo formatNumber($totalBorrowers); ?></div>
                        <small class="text-muted">Total registered borrowers.</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card shadow-sm h-100 p-4">
                        <div class="metric-label">Active Loans</div>
                        <div class="metric-value"><?php echo formatNumber($activeLoans); ?></div>
                        <small class="text-muted">Borrowers with open loans.</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card shadow-sm h-100 p-4">
                        <div class="metric-label">Top Borrower</div>
                        <div class="metric-value"><?php echo htmlspecialchars($topBorrowers[0]['borrower_name'] ?? 'N/A'); ?></div>
                        <small class="text-muted">Highest loan volume.</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card shadow-sm h-100 p-4">
                        <div class="metric-label">Outstanding Balance</div>
                        <div class="metric-value"><?php echo formatCurrency($totalOutstanding); ?></div>
                        <small class="text-muted">Total borrower balance due.</small>
                    </div>
                </div>
            </div>
            <div class="card shadow-sm mb-4">
                <div class="card-body table-responsive">
                    <table class="table table-striped mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Borrower</th>
                                <th class="text-center">Loans</th>
                                <th class="text-end">Borrowed</th>
                                <th class="text-end">Paid</th>
                                <th class="text-end">Remaining</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($borrowerData)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No borrower data available.</td></tr>
                            <?php else: ?>
                                <?php foreach ($borrowerData as $borrower): ?>
                                    <tr>
                                        <td><a href="client_details_lending.php?id=<?php echo $borrower['client_id']; ?>"><?php echo htmlspecialchars($borrower['borrower_name']); ?></a></td>
                                        <td class="text-center"><?php echo formatNumber($borrower['loan_count']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($borrower['total_borrowed']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($borrower['total_paid']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($borrower['remaining_balance']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php elseif ($reportType === 'collector_performance'): ?>
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 p-4">
                        <div class="metric-label">Collectors</div>
                        <div class="metric-value"><?php echo formatNumber($totalCollectors); ?></div>
                        <small class="text-muted">Active collector team.</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 p-4">
                        <div class="metric-label">Total Collected</div>
                        <div class="metric-value"><?php echo formatCurrency($totalCollected); ?></div>
                        <small class="text-muted">Collections in selected range.</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 p-4">
                        <div class="metric-label">Avg Per Collector</div>
                        <div class="metric-value"><?php echo formatCurrency($totalCollectors ? $totalCollected / $totalCollectors : 0); ?></div>
                        <small class="text-muted">Average collector intake.</small>
                    </div>
                </div>
            </div>
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-light py-3 border-bottom">
                            <h5 class="mb-0">Collector Performance</h5>
                        </div>
                        <div class="card-body table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Collector</th>
                                        <th class="text-end">Collected</th>
                                        <th class="text-center">Payments</th>
                                        <th class="text-center">Overdue Loans</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($collectorPerformance)): ?>
                                        <tr><td colspan="4" class="text-center text-muted py-4">No performance data found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($collectorPerformance as $collector): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($collector['collector_name']); ?></td>
                                                <td class="text-end"><?php echo formatCurrency($collector['total_collected']); ?></td>
                                                <td class="text-center"><?php echo formatNumber($collector['payment_count']); ?></td>
                                                <td class="text-center"><?php echo formatNumber($collector['overdue_loans']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-light py-3 border-bottom">
                            <h5 class="mb-0">Top Collections by Collector</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="collectorChart" style="min-height: 340px;"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($reportType === 'financial_summary'): ?>
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 p-4">
                        <div class="metric-label">Revenue</div>
                        <div class="metric-value"><?php echo formatCurrency($totalCollected); ?></div>
                        <small class="text-muted">Collection activity.</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 p-4">
                        <div class="metric-label">Expenses</div>
                        <div class="metric-value"><?php echo formatCurrency($totalExpenses); ?></div>
                        <small class="text-muted">Operating spend in range.</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm h-100 p-4">
                        <div class="metric-label">Net Income</div>
                        <div class="metric-value"><?php echo formatCurrency($netIncome); ?></div>
                        <small class="text-muted">Revenue minus expenses.</small>
                    </div>
                </div>
            </div>
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-light py-3 border-bottom">
                            <h5 class="mb-0">Cashflow Trend</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="financialTrendChart" style="min-height: 360px;"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-light py-3 border-bottom">
                            <h5 class="mb-0">Expense Breakdown</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-borderless mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Description</th>
                                            <th class="text-end">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($expenseBreakdown)): ?>
                                            <tr><td colspan="2" class="text-center text-muted py-4">No expense records found.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($expenseBreakdown as $expense): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($expense['description']); ?></td>
                                                    <td class="text-end"><?php echo formatCurrency($expense['total']); ?></td>
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
        <?php endif; ?>
    </div>

    <?php include 'includes/nav_footer_lending.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const chartData = <?php echo json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('trendChart')) {
                new Chart(document.getElementById('trendChart'), {
                    type: 'line',
                    data: {
                        labels: chartData.months,
                        datasets: [
                            {
                                label: 'Collections',
                                data: chartData.collections,
                                borderColor: '#1d4ed8',
                                backgroundColor: '#1d4ed8',
                                tension: 0.25,
                                fill: false,
                                borderWidth: 3,
                                pointRadius: 3,
                                pointHoverRadius: 6,
                                pointBackgroundColor: '#ffffff',
                                pointBorderWidth: 2
                            },
                            {
                                label: 'Expenses',
                                data: chartData.expenses,
                                borderColor: '#dc2626',
                                backgroundColor: '#dc2626',
                                tension: 0.25,
                                fill: false,
                                borderWidth: 3,
                                pointRadius: 3,
                                pointHoverRadius: 6,
                                pointBackgroundColor: '#ffffff',
                                pointBorderWidth: 2
                            }
                        ]
                    },
                    options: {
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false
                        },
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: { usePointStyle: true, padding: 18 }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.dataset.label + ': ₱' + Number(context.raw).toLocaleString('en-PH', { minimumFractionDigits: 2 });
                                    }
                                }
                            }
                        },
                        scales: {
                            x: { grid: { display: false } },
                            y: {
                                beginAtZero: true,
                                grid: { color: 'rgba(148, 163, 184, 0.18)' },
                                ticks: {
                                    callback: function(value) { return '₱' + Number(value).toLocaleString('en-PH'); }
                                }
                            }
                        }
                    }
                });
            }

            if (document.getElementById('loanStatusChart')) {
                new Chart(document.getElementById('loanStatusChart'), {
                    type: 'doughnut',
                    data: {
                        labels: chartData.status_labels,
                        datasets: [{
                            data: chartData.status_counts,
                            backgroundColor: ['#2563eb', '#ef4444', '#22c55e'],
                            hoverOffset: 6
                        }]
                    },
                    options: { maintainAspectRatio: false }
                });
            }

            if (document.getElementById('collectorChart')) {
                new Chart(document.getElementById('collectorChart'), {
                    type: 'bar',
                    data: {
                        labels: chartData.collector_labels,
                        datasets: [{
                            label: 'Collected',
                            data: chartData.collector_values,
                            backgroundColor: 'rgba(37, 99, 235, 0.85)'
                        }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                ticks: {
                                    callback: function(value) { return '₱' + value.toLocaleString(); }
                                }
                            }
                        }
                    }
                });
            }

            if (document.getElementById('financialTrendChart')) {
                new Chart(document.getElementById('financialTrendChart'), {
                    type: 'line',
                    data: {
                        labels: chartData.months,
                        datasets: [
                            {
                                label: 'Collections',
                                data: chartData.collections,
                                borderColor: '#047857',
                                backgroundColor: '#047857',
                                tension: 0.25,
                                fill: false,
                                borderWidth: 3,
                                pointRadius: 3,
                                pointHoverRadius: 6,
                                pointBackgroundColor: '#ffffff',
                                pointBorderWidth: 2
                            },
                            {
                                label: 'Expenses',
                                data: chartData.expenses,
                                borderColor: '#c2410c',
                                backgroundColor: '#c2410c',
                                tension: 0.25,
                                fill: false,
                                borderWidth: 3,
                                pointRadius: 3,
                                pointHoverRadius: 6,
                                pointBackgroundColor: '#ffffff',
                                pointBorderWidth: 2
                            }
                        ]
                    },
                    options: {
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false
                        },
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: { usePointStyle: true, padding: 18 }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.dataset.label + ': ₱' + Number(context.raw).toLocaleString('en-PH', { minimumFractionDigits: 2 });
                                    }
                                }
                            }
                        },
                        scales: {
                            x: { grid: { display: false } },
                            y: {
                                beginAtZero: true,
                                grid: { color: 'rgba(148, 163, 184, 0.18)' },
                                ticks: {
                                    callback: function(value) { return '₱' + Number(value).toLocaleString('en-PH'); }
                                }
                            }
                        }
                    }
                });
            }
        });

        function exportToCSV() {
            const table = document.querySelector('table');
            if (!table) {
                alert('No data available to export');
                return;
            }
            const rows = Array.from(table.rows);
            const csv = rows.map(row => Array.from(row.cells).map(cell => '"' + cell.innerText.replace(/"/g, '""') + '"').join(',')).join('\r\n');
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'analytics_report_' + new Date().toISOString().slice(0, 10) + '.csv';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        function exportToExcel() {
            const table = document.querySelector('table');
            if (!table) {
                alert('No data available to export');
                return;
            }
            const workbook = XLSX.utils.table_to_book(table, { sheet: 'Report' });
            XLSX.writeFile(workbook, 'analytics_report_' + new Date().toISOString().slice(0, 10) + '.xlsx');
        }

        function exportToPDF() {
            const element = document.querySelector('.container-fluid');
            if (!element) {
                alert('No content to export');
                return;
            }
            html2pdf().from(element).set({
                margin: 10,
                filename: 'analytics_report_' + new Date().toISOString().slice(0, 10) + '.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2 },
                jsPDF: { orientation: 'landscape', unit: 'mm', format: 'a4' }
            }).save();
        }
    </script>
</body>
</html>
