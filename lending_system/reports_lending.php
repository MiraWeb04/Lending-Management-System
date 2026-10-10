<?php
/**
 * Reports & Analytics Module for Lending Management System
 */

require_once 'includes/auth_lending.php';
requireStaff();
requireAdmin();
require_once 'includes/db_lending.php';
require_once 'includes/ui_helpers.php';
require_once 'includes/report_export_helpers.php';
require_once 'includes/invoice_helpers.php';

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

$periodMode = trim((string)($_GET['period_mode'] ?? 'monthly'));
$collectorId = trim($_GET['collector_id'] ?? '');
$clientId = trim($_GET['client_id'] ?? '');

$periodResolved = resolveReportPeriodRange($periodMode, $_GET);
$periodMode = $periodResolved['period_mode'];
$startDate = $periodResolved['start_date'];
$endDate = $periodResolved['end_date'];
$reportDate = $periodResolved['report_date'];
$reportMonth = $periodResolved['report_month'];
$reportYear = $periodResolved['report_year'];
$chartGranularity = $periodResolved['chart_granularity'];
$periodLabel = $periodResolved['period_label'];

$periodModeLabels = [
    'daily' => 'Daily',
    'monthly' => 'Monthly',
    'yearly' => 'Yearly',
    'custom' => 'Custom range',
];

$reportsBaseQuery = buildReportsFilterQuery([
    'type' => $reportType,
    'period_mode' => $periodMode,
    'report_date' => $reportDate,
    'report_month' => $reportMonth,
    'report_year' => $reportYear,
    'start_date' => $startDate,
    'end_date' => $endDate,
    'collector_id' => $collectorId,
    'client_id' => $clientId,
]);

$yearOptions = range((int)date('Y'), (int)date('Y') - 12);

function formatCurrency($value) {
    return "\u{20B1}" . number_format((float)$value, 2);
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

$trendSeries = buildReportTrendSeries($startDate, $endDate, $chartGranularity);
$trendLabels = $trendSeries['labels'];
$trendCollections = $trendSeries['collections'];
$trendExpenses = $trendSeries['expenses'];

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

$recentPaymentParams = [$startDate, $endDate];
$recentPaymentCollectorSql = '';
if ($collectorId !== '' && ($reportType === 'payment_reports' || $reportType === 'collector_performance')) {
    $recentPaymentCollectorSql = ' AND c.collector_id = ?';
    $recentPaymentParams[] = $collectorId;
}

$recentPayments = executeQuery(
    'SELECT p.payment_id, p.receipt_number, p.payment_date, p.amount_paid, p.collector_name, l.loan_number, CONCAT(c.first_name, " ", c.last_name) as borrower_name
     FROM payments p
     JOIN loans l ON p.loan_id = l.loan_id
     JOIN clients c ON l.client_id = c.client_id
     WHERE p.payment_date BETWEEN ? AND ?' . $recentPaymentCollectorSql . '
     ORDER BY p.payment_date DESC, p.payment_id DESC
     LIMIT 25',
    $recentPaymentParams
)->fetchAll(PDO::FETCH_ASSOC);

$borrowerFilterSql = '';
$borrowerParams = [$startDate, $endDate];
if ($clientId !== '') {
    $borrowerFilterSql = 'WHERE c.client_id = ?';
    $borrowerParams[] = $clientId;
}

$borrowerData = executeQuery(
    'SELECT c.client_id,
            CONCAT(c.first_name, " ", c.last_name) as borrower_name,
            COALESCE(loans.loan_count, 0) as loan_count,
            COALESCE(loans.total_borrowed, 0) as total_borrowed,
            COALESCE(loans.total_payable, 0) as total_payable,
            COALESCE(pay_all.total_paid_all, 0) as total_paid_all,
            COALESCE(pay_period.total_paid_period, 0) as total_paid_period,
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
         SELECT l.client_id, COALESCE(SUM(p.amount_paid), 0) as total_paid_all
         FROM payments p
         JOIN loans l ON p.loan_id = l.loan_id
         GROUP BY l.client_id
     ) pay_all ON pay_all.client_id = c.client_id
     LEFT JOIN (
         SELECT l.client_id, COALESCE(SUM(p.amount_paid), 0) as total_paid_period
         FROM payments p
         JOIN loans l ON p.loan_id = l.loan_id
         WHERE p.payment_date BETWEEN ? AND ?
         GROUP BY l.client_id
     ) pay_period ON pay_period.client_id = c.client_id
     ' . $borrowerFilterSql . '
     ORDER BY loans.total_borrowed DESC
     LIMIT 50',
    $borrowerParams
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($borrowerData as &$borrower) {
    $borrower['outstanding_balance'] = max(0, (float)$borrower['total_payable'] - (float)$borrower['total_paid_all']);
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

$topBorrowerName = $borrowerData[0]['borrower_name'] ?? 'N/A';
$avgPerCollector = $totalCollectors > 0 ? $totalCollected / $totalCollectors : 0;

$exportContext = [
    'report_label' => $reportTypes[$reportType],
    'start_date' => $startDate,
    'end_date' => $endDate,
    'period_label' => $periodLabel,
    'period_mode' => $periodMode,
    'chart_granularity' => $chartGranularity,
    'monthly_labels' => $trendLabels,
    'monthly_collection' => $trendCollections,
    'monthly_expense' => $trendExpenses,
    'loan_status' => $loanStatus,
    'total_borrowers' => $totalBorrowers,
    'total_collectors' => $totalCollectors,
    'total_loans' => $totalLoans,
    'active_loans' => $activeLoans,
    'overdue_loans' => $overdueLoans,
    'paid_loans' => $paidLoans,
    'total_collected_fmt' => formatCurrency($totalCollected),
    'total_disbursed_fmt' => formatCurrency($totalDisbursed),
    'total_expenses_fmt' => formatCurrency($totalExpenses),
    'net_income_fmt' => formatCurrency($netIncome),
    'profit_margin_fmt' => round($profitMargin, 1) . '%',
    'total_outstanding_fmt' => formatCurrency($totalOutstanding),
    'payment_summary' => $paymentSummary,
    'payment_summary_avg_fmt' => formatCurrency($paymentSummary['average'] ?? 0),
    'payments_by_collector' => $paymentsByCollector,
    'recent_payments' => $recentPayments,
    'borrower_data' => $borrowerData,
    'collector_performance' => $collectorPerformance,
    'expense_breakdown' => $expenseBreakdown,
    'avg_per_collector_fmt' => formatCurrency($avgPerCollector),
];

$exportPayload = buildReportExportPayload($reportType, $exportContext);

if (isset($_GET['export']) && (string)$_GET['export'] === 'pdf') {
    streamLendingReportPdf($exportPayload, $reportType);
    exit;
}

$exportPdfHref = 'reports_lending.php?' . buildReportsFilterQuery([
    'type' => $reportType,
    'period_mode' => $periodMode,
    'report_date' => $reportDate,
    'report_month' => $reportMonth,
    'report_year' => $reportYear,
    'start_date' => $startDate,
    'end_date' => $endDate,
    'collector_id' => $collectorId,
    'client_id' => $clientId,
]) . '&export=pdf';

$chartData = [
    'months' => $trendLabels,
    'collections' => $trendCollections,
    'expenses' => $trendExpenses,
    'granularity' => $chartGranularity,
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
    <?php require_once __DIR__ . '/includes/lending_assets.php'; lendingRenderPwaMeta(); ?>
    <title>Reports & Analytics - Lending Management System</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <script src="assets/vendor/chartjs/chart.umd.min.js"></script>
    <link rel="stylesheet" href="css/lending_styles.css">
    <link rel="stylesheet" href="css/pwa.css">
</head>
<body>
    <?php include 'includes/nav_lending.php'; ?>
    <div class="app-content py-2">
        <div class="reports-hero no-print">
            <div>
                <h1 class="reports-hero__title"><i class="fas fa-chart-pie me-2 text-primary"></i>Reports &amp; Analytics</h1>
                <p class="reports-hero__subtitle">Focused admin reports for portfolio health, collections, borrower exposure, collector performance, and cashflow ? filtered by your selected date range.</p>
            </div>
            <div class="reports-hero__actions d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="printReport()"><i class="fas fa-print me-1"></i> Print</button>
                <a href="<?php echo htmlspecialchars($exportPdfHref, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary btn-sm" id="btn-export-report"><i class="fas fa-file-export me-1"></i> Export Report</a>
            </div>
        </div>

        <nav class="mb-4 no-print">
            <ul class="nav nav-pills reports-type-nav flex-wrap gap-2">
                <?php foreach ($reportTypes as $type => $label): ?>
                    <li class="nav-item">
                        <a class="nav-link<?php echo $reportType === $type ? ' active' : ''; ?>" href="reports_lending.php?<?php echo htmlspecialchars(buildReportsFilterQuery([
                            'type' => $type,
                            'period_mode' => $periodMode,
                            'report_date' => $reportDate,
                            'report_month' => $reportMonth,
                            'report_year' => $reportYear,
                            'start_date' => $startDate,
                            'end_date' => $endDate,
                            'collector_id' => $collectorId,
                            'client_id' => $clientId,
                        ]), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($label); ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <div class="reports-filter-card no-print">
            <div class="card-body p-4">
                <form action="reports_lending.php" method="get" class="row g-3 align-items-end lending-period-filter" id="reports-filter-form">
                    <input type="hidden" name="type" value="<?php echo htmlspecialchars($reportType); ?>">
                    <div class="col-lg-3 col-md-4">
                        <label class="form-label fw-semibold" for="period_mode">Period</label>
                        <select class="form-select" name="period_mode" id="period_mode">
                            <?php foreach ($periodModeLabels as $modeKey => $modeLabel): ?>
                                <option value="<?php echo htmlspecialchars($modeKey, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $periodMode === $modeKey ? ' selected' : ''; ?>><?php echo htmlspecialchars($modeLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-3 col-md-4 reports-period-field" data-period-field="daily">
                        <label class="form-label" for="report_date">Day</label>
                        <input type="date" class="form-control" id="report_date" name="report_date" value="<?php echo htmlspecialchars($reportDate, ENT_QUOTES, 'UTF-8'); ?>" max="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="col-lg-3 col-md-4 reports-period-field" data-period-field="monthly">
                        <label class="form-label" for="report_month">Month</label>
                        <input type="month" class="form-control" id="report_month" name="report_month" value="<?php echo htmlspecialchars($reportMonth, ENT_QUOTES, 'UTF-8'); ?>" max="<?php echo date('Y-m'); ?>">
                    </div>

                    <div class="col-lg-3 col-md-4 reports-period-field" data-period-field="yearly">
                        <label class="form-label" for="report_year">Year</label>
                        <select class="form-select" id="report_year" name="report_year">
                            <?php foreach ($yearOptions as $yearOption): ?>
                                <option value="<?php echo (int)$yearOption; ?>"<?php echo (int)$reportYear === (int)$yearOption ? ' selected' : ''; ?>><?php echo (int)$yearOption; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-4 col-md-6 reports-period-field" data-period-field="custom">
                        <label class="form-label">Custom range</label>
                        <div class="row g-2">
                            <div class="col-6">
                                <input type="date" class="form-control" name="start_date" id="custom_start_date" value="<?php echo htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8'); ?>" max="<?php echo date('Y-m-d'); ?>" aria-label="From date">
                            </div>
                            <div class="col-6">
                                <input type="date" class="form-control" name="end_date" id="custom_end_date" value="<?php echo htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8'); ?>" max="<?php echo date('Y-m-d'); ?>" aria-label="To date">
                            </div>
                        </div>
                    </div>

                    <?php if ($reportType === 'payment_reports' || $reportType === 'collector_performance'): ?>
                        <div class="col-lg-3 col-md-4">
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
                        <div class="col-lg-3 col-md-4">
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
                    <div class="col-lg-2 col-md-3 ms-lg-auto">
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i> Apply</button>
                    </div>
                    <div class="col-12 lending-period-showing">Showing: <strong><?php echo htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                </form>
            </div>
        </div>

        <div id="reports-export-root" class="reports-export-document">
        <?php if ($reportType === 'dashboard'): ?>
            <section class="dashboard-section" aria-labelledby="reportDashboardKpi">
                <div class="dashboard-section__head">
                    <h2 class="dashboard-section__title" id="reportDashboardKpi">Executive snapshot</h2>
                    <span class="dashboard-section__hint"><?php echo htmlspecialchars($periodModeLabels[$periodMode] ?? 'Period'); ?> view ? <?php echo htmlspecialchars($periodLabel); ?></span>
                </div>
                <div class="kpi-grid mb-4">
                    <?php
                    echo renderReportKpi('primary', 'fa-users', 'Borrowers', formatNumber($totalBorrowers), 'Registered accounts');
                    echo renderReportKpi('info', 'fa-file-invoice-dollar', 'Active loans', formatNumber($activeLoans), 'Currently in repayment');
                    echo renderReportKpi('danger', 'fa-triangle-exclamation', 'Overdue', formatNumber($overdueLoans), 'Needs follow-up');
                    echo renderReportKpi('success', 'fa-hand-holding-dollar', 'Collections', formatCurrency($totalCollected), 'Received in period');
                    echo renderReportKpi('primary', 'fa-money-bill-transfer', 'Disbursed', formatCurrency($totalDisbursed), 'Released in period');
                    echo renderReportKpi('warning', 'fa-receipt', 'Expenses', formatCurrency($totalExpenses), 'Operating spend');
                    echo renderReportKpi('success', 'fa-chart-line', 'Net income', formatCurrency($netIncome), 'Collections - expenses');
                    echo renderReportKpi('info', 'fa-scale-balanced', 'Outstanding', formatCurrency($totalOutstanding), 'Active & overdue loans');
                    ?>
                </div>
            </section>
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="chart-panel h-100">
                        <div class="chart-panel__header">
                            <h3 class="h6 mb-0 fw-bold">Collections vs expenses</h3>
                            <span class="chart-badge"><?php echo htmlspecialchars(ucfirst($chartGranularity), ENT_QUOTES, 'UTF-8'); ?> trend</span>
                        </div>
                        <div class="chart-panel__body">
                            <canvas id="trendChart" height="320"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="chart-panel h-100">
                        <div class="chart-panel__header">
                            <h3 class="h6 mb-0 fw-bold">Loan status mix</h3>
                            <span class="chart-badge">Portfolio</span>
                        </div>
                        <div class="chart-panel__body">
                            <canvas id="statusChart" height="320"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($reportType === 'loan_reports'): ?>
            <div class="kpi-grid mb-4">
                <?php
                echo renderReportKpi('primary', 'fa-layer-group', 'Total loans', formatNumber($totalLoans), 'All statuses');
                echo renderReportKpi('info', 'fa-spinner', 'Active', formatNumber($activeLoans), formatCurrency($loanStatus['Active']['payable'] ?? 0) . ' payable');
                echo renderReportKpi('danger', 'fa-clock', 'Overdue', formatNumber($overdueLoans), 'At-risk accounts');
                echo renderReportKpi('success', 'fa-circle-check', 'Paid', formatNumber($paidLoans), 'Closed loans');
                echo renderReportKpi('warning', 'fa-wallet', 'Outstanding', formatCurrency($totalOutstanding), 'Remaining receivable');
                ?>
            </div>
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="chart-panel h-100">
                        <div class="chart-panel__header">
                            <h3 class="h6 mb-0 fw-bold">Status distribution</h3>
                        </div>
                        <div class="chart-panel__body">
                            <canvas id="loanStatusChart" height="360"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="chart-panel h-100">
                        <div class="chart-panel__header">
                            <h3 class="h6 mb-0 fw-bold">Portfolio breakdown</h3>
                        </div>
                        <div class="chart-panel__body chart-panel__body--tight reports-table-wrap">
                            <table class="table data-table mb-0" id="report-primary-table">
                                <thead>
                                    <tr>
                                        <th>Status</th>
                                        <th class="text-end">Count</th>
                                        <th class="text-end">Principal</th>
                                        <th class="text-end">Payable</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($loanStatus as $status => $data): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($status); ?></td>
                                        <td class="text-end"><?php echo formatNumber($data['count']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($data['amount']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($data['payable']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($reportType === 'payment_reports'): ?>
            <div class="kpi-grid mb-4" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                <?php
                echo renderReportKpi('primary', 'fa-list-check', 'Payments', formatNumber($paymentSummary['count'] ?? 0), 'Transactions in period');
                echo renderReportKpi('success', 'fa-coins', 'Collected', formatCurrency($paymentSummary['total'] ?? 0), 'Total cash in');
                echo renderReportKpi('info', 'fa-calculator', 'Average', formatCurrency($paymentSummary['average'] ?? 0), 'Per transaction');
                ?>
            </div>
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="chart-panel h-100">
                        <div class="chart-panel__header"><h3 class="h6 mb-0 fw-bold">By collector</h3></div>
                        <div class="chart-panel__body chart-panel__body--tight reports-table-wrap">
                            <table class="table data-table mb-0" id="report-primary-table">
                                <thead>
                                    <tr>
                                        <th>Collector</th>
                                        <th class="text-end">Collected</th>
                                        <th class="text-center">Count</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($paymentsByCollector)): ?>
                                        <tr><td colspan="3"><?php echo renderEmptyState('fa-user-clock', 'No collections', 'No payments matched this period or collector filter.'); ?></td></tr>
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
                    <div class="chart-panel h-100">
                        <div class="chart-panel__header"><h3 class="h6 mb-0 fw-bold">Payment ledger</h3></div>
                        <div class="chart-panel__body chart-panel__body--tight reports-table-wrap">
                            <table class="table data-table mb-0" id="report-secondary-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Receipt</th>
                                        <th>Borrower</th>
                                        <th class="text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentPayments)): ?>
                                        <tr><td colspan="4"><?php echo renderEmptyState('fa-receipt', 'No payments', 'No payments in the selected date range.'); ?></td></tr>
                                    <?php else: ?>
                                        <?php foreach ($recentPayments as $payment): ?>
                                            <tr>
                                                <td><?php echo formatShortDate($payment['payment_date']); ?></td>
                                                <td><a href="payments_lending.php?view=<?php echo (int)$payment['payment_id']; ?>"><?php echo htmlspecialchars($payment['receipt_number']); ?></a></td>
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
            <div class="kpi-grid mb-4">
                <?php
                echo renderReportKpi('primary', 'fa-users', 'Borrowers', formatNumber($totalBorrowers), 'Registered clients');
                echo renderReportKpi('info', 'fa-file-invoice', 'Active loans', formatNumber($activeLoans), 'Open accounts');
                echo renderReportKpi('warning', 'fa-crown', 'Top exposure', htmlspecialchars($topBorrowerName), 'Highest principal borrowed');
                echo renderReportKpi('danger', 'fa-scale-unbalanced', 'Outstanding', formatCurrency($totalOutstanding), 'Portfolio-wide balance due');
                ?>
            </div>
            <div class="chart-panel">
                <div class="chart-panel__header"><h3 class="h6 mb-0 fw-bold">Borrower exposure</h3></div>
                <div class="chart-panel__body chart-panel__body--tight reports-table-wrap">
                    <table class="table data-table mb-0" id="report-primary-table">
                        <thead>
                            <tr>
                                <th>Borrower</th>
                                <th class="text-center">Loans</th>
                                <th class="text-end">Borrowed</th>
                                <th class="text-end">Outstanding</th>
                                <th class="text-end">Collected (period)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($borrowerData)): ?>
                                <tr><td colspan="5"><?php echo renderEmptyState('fa-users', 'No borrowers', 'Adjust filters or add client records.'); ?></td></tr>
                            <?php else: ?>
                                <?php foreach ($borrowerData as $borrower): ?>
                                    <tr>
                                        <td><a href="client_details_lending.php?id=<?php echo (int)$borrower['client_id']; ?>"><?php echo htmlspecialchars($borrower['borrower_name']); ?></a></td>
                                        <td class="text-center"><?php echo formatNumber($borrower['loan_count']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($borrower['total_borrowed']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($borrower['outstanding_balance']); ?></td>
                                        <td class="text-end"><?php echo formatCurrency($borrower['total_paid_period']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php elseif ($reportType === 'collector_performance'): ?>
            <div class="kpi-grid mb-4" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                <?php
                echo renderReportKpi('primary', 'fa-user-tie', 'Collectors', formatNumber($totalCollectors), 'Team size');
                echo renderReportKpi('success', 'fa-hand-holding-dollar', 'Collected', formatCurrency($totalCollected), 'In selected period');
                echo renderReportKpi('info', 'fa-chart-simple', 'Avg / collector', formatCurrency($avgPerCollector), 'Period average');
                ?>
            </div>
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="chart-panel h-100">
                        <div class="chart-panel__header"><h3 class="h6 mb-0 fw-bold">Performance table</h3></div>
                        <div class="chart-panel__body chart-panel__body--tight reports-table-wrap">
                            <table class="table data-table mb-0" id="report-primary-table">
                                <thead>
                                    <tr>
                                        <th>Collector</th>
                                        <th class="text-end">Collected</th>
                                        <th class="text-center">Payments</th>
                                        <th class="text-center">Active</th>
                                        <th class="text-center">Overdue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($collectorPerformance)): ?>
                                        <tr><td colspan="5"><?php echo renderEmptyState('fa-user-clock', 'No data', 'No collector activity for this period.'); ?></td></tr>
                                    <?php else: ?>
                                        <?php foreach ($collectorPerformance as $collector): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($collector['collector_name']); ?></td>
                                                <td class="text-end"><?php echo formatCurrency($collector['total_collected']); ?></td>
                                                <td class="text-center"><?php echo formatNumber($collector['payment_count']); ?></td>
                                                <td class="text-center"><?php echo formatNumber($collector['active_loans']); ?></td>
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
                    <div class="chart-panel h-100">
                        <div class="chart-panel__header"><h3 class="h6 mb-0 fw-bold">Collections ranking</h3></div>
                        <div class="chart-panel__body">
                            <canvas id="collectorChart" height="340"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($reportType === 'financial_summary'): ?>
            <div class="kpi-grid mb-4" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                <?php
                echo renderReportKpi('success', 'fa-arrow-trend-up', 'Collections', formatCurrency($totalCollected), 'Cash in (period)');
                echo renderReportKpi('warning', 'fa-receipt', 'Expenses', formatCurrency($totalExpenses), 'Cash out (period)');
                echo renderReportKpi('primary', 'fa-sack-dollar', 'Net income', formatCurrency($netIncome), round($profitMargin, 1) . '% margin');
                echo renderReportKpi('info', 'fa-money-bill-transfer', 'Disbursed', formatCurrency($totalDisbursed), 'New releases in period');
                ?>
            </div>
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="chart-panel h-100">
                        <div class="chart-panel__header"><h3 class="h6 mb-0 fw-bold">Cashflow trend</h3></div>
                        <div class="chart-panel__body">
                            <canvas id="financialTrendChart" height="360"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="chart-panel h-100">
                        <div class="chart-panel__header"><h3 class="h6 mb-0 fw-bold">Expense breakdown</h3></div>
                        <div class="chart-panel__body chart-panel__body--tight reports-table-wrap">
                            <table class="table data-table mb-0" id="report-primary-table">
                                <thead>
                                    <tr>
                                        <th>Description</th>
                                        <th class="text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($expenseBreakdown)): ?>
                                        <tr><td colspan="2"><?php echo renderEmptyState('fa-receipt', 'No expenses', 'No expense entries in this period.'); ?></td></tr>
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
        <?php endif; ?>
        </div><!-- #reports-export-root -->
    </div>

    <?php include 'includes/nav_footer_lending.php'; ?>
    <script>
        const chartData = <?php echo json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const pesoSign = '\u20B1';

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
                                        return context.dataset.label + ': ' + pesoSign + Number(context.raw).toLocaleString('en-PH', { minimumFractionDigits: 2 });
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
                                    callback: function(value) { return pesoSign + Number(value).toLocaleString('en-PH'); }
                                }
                            }
                        }
                    }
                });
            }

            if (document.getElementById('statusChart')) {
                new Chart(document.getElementById('statusChart'), {
                    type: 'doughnut',
                    data: {
                        labels: chartData.status_labels,
                        datasets: [{
                            data: chartData.status_counts,
                            backgroundColor: ['#2563eb', '#ef4444', '#22c55e', '#94a3b8'],
                            hoverOffset: 8,
                            borderWidth: 0
                        }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        cutout: '62%',
                        plugins: {
                            legend: { position: 'bottom', labels: { usePointStyle: true, padding: 16 } }
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
                                    callback: function(value) { return pesoSign + value.toLocaleString(); }
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
                                        return context.dataset.label + ': ' + pesoSign + Number(context.raw).toLocaleString('en-PH', { minimumFractionDigits: 2 });
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
                                    callback: function(value) { return pesoSign + Number(value).toLocaleString('en-PH'); }
                                }
                            }
                        }
                    }
                });
            }
        });

        function printReport() {
            window.print();
        }

        (function initReportsPeriodFilter() {
            var modeSelect = document.getElementById('period_mode');
            var fields = document.querySelectorAll('.reports-period-field');
            if (!modeSelect || !fields.length) {
                return;
            }

            function syncPeriodFields() {
                var mode = modeSelect.value || 'monthly';
                fields.forEach(function (el) {
                    var match = el.getAttribute('data-period-field') === mode;
                    el.classList.toggle('is-active', match);
                    el.querySelectorAll('input, select').forEach(function (input) {
                        input.disabled = !match;
                    });
                });
            }

            modeSelect.addEventListener('change', syncPeriodFields);
            syncPeriodFields();
        })();
    </script>
</body>
</html>
