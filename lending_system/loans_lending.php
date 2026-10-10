<?php
/**
 * Loans Management Page for Lending Management System
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
require_once 'includes/report_export_helpers.php';

$periodModeLabels = array_merge(['all' => 'All releases'], lendingPeriodModeLabels());
$periodMode = trim((string)($_GET['period_mode'] ?? 'all'));
if (!isset($periodModeLabels[$periodMode])) {
    $periodMode = 'all';
}

if ($periodMode === 'all') {
    $todayIso = date('Y-m-d');
    $startDate = $todayIso;
    $endDate = $todayIso;
    $reportDate = $todayIso;
    $reportMonth = date('Y-m');
    $reportYear = (int)date('Y');
    $periodLabel = 'All releases';
} else {
    $periodResolved = resolveReportPeriodRange($periodMode, $_GET);
    $periodMode = $periodResolved['period_mode'];
    $startDate = $periodResolved['start_date'];
    $endDate = $periodResolved['end_date'];
    $reportDate = $periodResolved['report_date'];
    $reportMonth = $periodResolved['report_month'];
    $reportYear = $periodResolved['report_year'];
    $periodLabel = $periodResolved['period_label'];
}
$yearOptions = lendingYearOptions();

// Initialize variables
$message = '';
$messageType = '';
$loans = [];
$clients = [];
$addLoan = false;
$selectedClientId = null;
$searchTerm = '';
$statusFilter = '';

$collectorUserId = (int)($user['user_id'] ?? 0);

if (isCollector() && isset($_GET['add']) && $_GET['add'] === 'true') {
    denyCollectorAccess('Collectors cannot create new loans.');
}

// Get all clients for dropdown with current highest-priority loan status
$statusSubquery = "CASE
                         WHEN SUM(l.status = 'Active') > 0 THEN 'Active Loan'
                         WHEN SUM(l.status = 'Overdue') > 0 THEN 'Overdue Loan'
                         WHEN SUM(l.status = 'Paid') > 0 THEN 'Paid Loan'
                         ELSE ''
                     END AS loan_status_label";
if (isCollector()) {
    $clientsQuery = "SELECT c.client_id, c.first_name, c.last_name, $statusSubquery
                     FROM clients c
                     LEFT JOIN loans l ON l.client_id = c.client_id
                     WHERE c.collector_id = ?
                     GROUP BY c.client_id
                     ORDER BY c.first_name, c.last_name";
    $clientsResult = executeQuery($clientsQuery, [$collectorUserId]);
} else {
    $clientsQuery = "SELECT c.client_id, c.first_name, c.last_name, $statusSubquery
                     FROM clients c
                     LEFT JOIN loans l ON l.client_id = c.client_id
                     GROUP BY c.client_id
                     ORDER BY c.first_name, c.last_name";
    $clientsResult = executeQuery($clientsQuery);
}
$clients = $clientsResult->fetchAll();

// Admin create loan uses the full 5-step wizard (same as borrower application)
if (isset($_GET['add']) && $_GET['add'] === 'true' && !isCollector()) {
    $redirectUrl = 'admin_create_loan_lending.php';
    if (isset($_GET['client']) && is_numeric($_GET['client'])) {
        $redirectUrl .= '?client=' . (int)$_GET['client'];
    }
    header('Location: ' . $redirectUrl);
    exit;
}

// Process loan form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isCollector()) {
        $message = 'Collectors can only view assigned loans.';
        $messageType = 'danger';
    } else {
    
    // Update loan status
    if (isset($_POST['update_status'])) {
        $loanId = (int)$_POST['loan_id'];
        $newStatus = $_POST['status'];
        
        // Validate status
        $validStatuses = ['Active', 'Paid', 'Overdue'];
        if (in_array($newStatus, $validStatuses)) {
            $updateQuery = "UPDATE loans SET status = ? WHERE loan_id = ?";
            $params = [$newStatus, $loanId];
            
            try {
                executeQuery($updateQuery, $params);
                $message = "Loan #$loanId status has been updated to $newStatus.";
                $messageType = 'success';
            } catch (Exception $e) {
                $message = "Error updating loan status: " . $e->getMessage();
                $messageType = 'danger';
            }
        } else {
            $message = "Invalid status provided.";
            $messageType = 'danger';
        }
    }
    }
}

// Initialize search and filter variables
$searchTerm = '';
$statusFilter = '';

// Handle search and filtering
if (isset($_GET['search'])) {
    $searchTerm = trim($_GET['search']);
}

if (isset($_GET['status']) && in_array($_GET['status'], ['Active', 'Paid', 'Overdue'])) {
    $statusFilter = $_GET['status'];
}

// Build query based on search and filter
$loansQuery = "
        SELECT l.*, c.first_name, c.last_name, r.loan_term, r.payment_frequency AS release_payment_frequency,
            lps.total_amount_due AS schedule_installment_amount,
            lps2.final_due_date AS schedule_final_due
    FROM loans l
    JOIN clients c ON l.client_id = c.client_id
    LEFT JOIN loan_releases r ON l.release_id = r.id
    LEFT JOIN loan_payment_schedules lps ON lps.release_id = l.release_id AND lps.installment_number = 1
        LEFT JOIN (SELECT release_id, MAX(due_date) AS final_due_date FROM loan_payment_schedules GROUP BY release_id) lps2 ON lps2.release_id = l.release_id
    WHERE 1=1
";

$queryParams = [];

if (isCollector()) {
    $loansQuery .= " AND (c.collector_id = ? OR l.collector_id = ?)";
    $queryParams[] = $collectorUserId;
    $queryParams[] = $collectorUserId;
}

// Default list shows all releases (like Clients). Date window applies only when a period is chosen.
$applyReleaseDateFilter = ($periodMode !== 'all' && $statusFilter !== 'Overdue');
if ($applyReleaseDateFilter) {
    $loansQuery .= " AND l.date_released BETWEEN ? AND ?";
    $queryParams[] = $startDate;
    $queryParams[] = $endDate;
}

if (!empty($searchTerm)) {
    $loansQuery .= " AND (c.first_name LIKE ? OR c.last_name LIKE ? OR CONCAT(c.first_name, ' ', c.last_name) LIKE ? OR l.loan_id LIKE ?)"; 
    $searchParam = "%$searchTerm%";
    $queryParams = array_merge($queryParams, [$searchParam, $searchParam, $searchParam, $searchParam]);
}

$loansQuery .= " ORDER BY l.date_released DESC";

// Execute the query
$loansResult = executeQuery($loansQuery, $queryParams);
$loans = array_map('enrichLoanCollectionFields', $loansResult->fetchAll());

if (!empty($statusFilter)) {
    $loans = array_values(array_filter($loans, fn($loan) => strcasecmp($loan['display_status'], $statusFilter) === 0));
}

$statusCounts = ['Active' => 0, 'Paid' => 0, 'Overdue' => 0];
foreach ($loans as $loan) {
    $statusCounts[$loan['display_status']] = ($statusCounts[$loan['display_status']] ?? 0) + 1;
}

$activeCount = $statusCounts['Active'] ?? 0;
$paidCount = $statusCounts['Paid'] ?? 0;
$overdueCount = $statusCounts['Overdue'] ?? 0;
$totalCount = $activeCount + $paidCount + $overdueCount;

// Calculate total loan amount and receivables
$statsQuery = "SELECT 
                COUNT(*) as loan_count,
                SUM(l.loan_amount) as total_loan_amount,
                SUM(l.total_payable) as total_receivable,
                SUM(CASE WHEN l.status != 'Paid' THEN l.total_payable ELSE 0 END) as outstanding_receivable
              FROM loans l
              JOIN clients c ON l.client_id = c.client_id
              WHERE 1=1";
$statsParams = [];
if ($applyReleaseDateFilter) {
    $statsQuery .= " AND l.date_released BETWEEN ? AND ?";
    $statsParams[] = $startDate;
    $statsParams[] = $endDate;
}
if (isCollector()) {
    $statsQuery .= " AND (c.collector_id = ? OR l.collector_id = ?)";
    $statsParams[] = $collectorUserId;
    $statsParams[] = $collectorUserId;
}
$statsResult = executeQuery($statsQuery, $statsParams);
$stats = $statsResult->fetch();

$releasedInPeriodCount = (int)($stats['loan_count'] ?? 0);
$totalLoanAmount = $stats['total_loan_amount'] ?? 0;
$totalReceivable = $stats['total_receivable'] ?? 0;
$outstandingReceivable = $stats['outstanding_receivable'] ?? 0;

$collectionsQuery = "SELECT COALESCE(SUM(p.amount_paid + COALESCE(p.penalty_paid, 0)), 0) AS total_collected
                     FROM payments p
                     JOIN loans l ON l.loan_id = p.loan_id
                     JOIN clients c ON c.client_id = l.client_id
                     WHERE 1=1";
$collectionsParams = [];
if ($applyReleaseDateFilter) {
    $collectionsQuery .= " AND p.payment_date BETWEEN ? AND ?";
    $collectionsParams[] = $startDate;
    $collectionsParams[] = $endDate;
}
if (isCollector()) {
    $collectionsQuery .= " AND (c.collector_id = ? OR l.collector_id = ?)";
    $collectionsParams[] = $collectorUserId;
    $collectionsParams[] = $collectorUserId;
}
$collectedInPeriod = (float)(executeQuery($collectionsQuery, $collectionsParams)->fetchColumn() ?? 0);

// Format currency values
$formattedTotalLoanAmount = number_format($totalLoanAmount, 2);
$formattedTotalReceivable = number_format($totalReceivable, 2);
$formattedOutstandingReceivable = number_format($outstandingReceivable, 2);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/lending_assets.php'; lendingRenderPwaMeta(); ?>
    <title>Loans Management - Lending Management System</title>
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
                <div class="dashboard-breadcrumb"><a href="<?php echo isCollector() ? 'collector_dashboard_lending.php' : 'dashboard_lending.php'; ?>">Home</a> / Loans</div>
                <h1 class="page-title mb-1"><i class="fas fa-hand-holding-dollar me-2"></i>Loans Management</h1>
                <p class="page-subtitle mb-0">
                    <?php if ($periodMode === 'all'): ?>
                        <?php if (isCollector()): ?>
                            View assigned loans, check balances, and record payments from one place.
                        <?php else: ?>
                            Manage loan releases, outstanding balances, and status across the portfolio.
                        <?php endif; ?>
                    <?php else: ?>
                        <?php echo htmlspecialchars($periodModeLabels[$periodMode] ?? 'Period'); ?>: <?php echo htmlspecialchars($periodLabel); ?> — filtered by release date.
                    <?php endif; ?>
                </p>
            </div>
            <?php if (!isCollector()): ?>
            <div class="quick-actions">
                <a href="admin_create_loan_lending.php" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i> New Loan</a>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <?php if ($statusFilter === 'Overdue'): ?>
        <div class="alert alert-warning mb-3" role="status">
            <i class="fas fa-triangle-exclamation me-2"></i>
            Showing <strong>all overdue loans</strong><?php echo isCollector() ? ' on your route' : ''; ?>. Release-date filter is skipped while status is Overdue.
        </div>
        <?php endif; ?>

        <?php
        $periodFilterFormAction = 'loans_lending.php';
        $periodFilterFormId = 'loans-period-filter-form';
        $periodFilterHidden = array_filter([
            'search' => $searchTerm,
            'status' => $statusFilter,
        ], fn($v) => $v !== '' && $v !== null);
        include 'includes/lending_period_filter.php';
        ?>

        <div class="kpi-grid">
            <div class="kpi-card kpi-card--primary">
                <div class="kpi-card__top"><div class="kpi-card__label"><?php echo $periodMode === 'all' ? 'Total released' : 'Released (period)'; ?></div><div class="kpi-card__icon"><i class="fas fa-layer-group"></i></div></div>
                <div class="kpi-card__value"><?php echo (int)$releasedInPeriodCount; ?></div>
                <div class="kpi-card__meta"><?php echo (int)$totalCount; ?> after status filter</div>
            </div>
            <div class="kpi-card kpi-card--success">
                <div class="kpi-card__top"><div class="kpi-card__label">Active</div><div class="kpi-card__icon"><i class="fas fa-circle-check"></i></div></div>
                <div class="kpi-card__value"><?php echo (int)$activeCount; ?></div>
                <div class="kpi-card__meta">Currently collecting</div>
            </div>
            <div class="kpi-card kpi-card--danger">
                <div class="kpi-card__top"><div class="kpi-card__label">Overdue</div><div class="kpi-card__icon"><i class="fas fa-triangle-exclamation"></i></div></div>
                <div class="kpi-card__value"><?php echo (int)$overdueCount; ?></div>
                <div class="kpi-card__meta">Requires follow-up</div>
            </div>
            <div class="kpi-card kpi-card--info">
                <div class="kpi-card__top"><div class="kpi-card__label">Fully Paid</div><div class="kpi-card__icon"><i class="fas fa-clipboard-check"></i></div></div>
                <div class="kpi-card__value"><?php echo (int)$paidCount; ?></div>
                <div class="kpi-card__meta">Closed loans</div>
            </div>
        </div>

        <div class="kpi-grid mb-4">
            <div class="kpi-card kpi-card--primary">
                <div class="kpi-card__top"><div class="kpi-card__label">Total Released</div><div class="kpi-card__icon"><i class="fas fa-peso-sign"></i></div></div>
                <div class="kpi-card__value">₱<?php echo $formattedTotalLoanAmount; ?></div>
                <div class="kpi-card__meta">Principal released in period</div>
            </div>
            <div class="kpi-card kpi-card--success">
                <div class="kpi-card__top"><div class="kpi-card__label">Total Receivable</div><div class="kpi-card__icon"><i class="fas fa-chart-line"></i></div></div>
                <div class="kpi-card__value">₱<?php echo $formattedTotalReceivable; ?></div>
                <div class="kpi-card__meta">Payable on period releases</div>
            </div>
            <div class="kpi-card kpi-card--warning">
                <div class="kpi-card__top"><div class="kpi-card__label">Outstanding</div><div class="kpi-card__icon"><i class="fas fa-scale-unbalanced"></i></div></div>
                <div class="kpi-card__value">₱<?php echo $formattedOutstandingReceivable; ?></div>
                <div class="kpi-card__meta">Open balance (period releases)</div>
            </div>
            <div class="kpi-card kpi-card--info">
                <div class="kpi-card__top"><div class="kpi-card__label"><?php echo $applyReleaseDateFilter ? 'Collected (period)' : 'Collected (all time)'; ?></div><div class="kpi-card__icon"><i class="fas fa-hand-holding-dollar"></i></div></div>
                <div class="kpi-card__value">₱<?php echo number_format($collectedInPeriod, 2); ?></div>
                <div class="kpi-card__meta">All payments in range</div>
            </div>
        </div>

        <div class="panel-card panel-card--flush mb-4">
            <div class="chart-panel__header">
                <h6 class="m-0 fw-bold"><i class="fas fa-search me-2 text-primary"></i>Search &amp; status</h6>
            </div>
            <div class="chart-panel__body pt-0">
                <form action="loans_lending.php" method="get" class="row g-3 align-items-end">
                    <input type="hidden" name="period_mode" value="<?php echo htmlspecialchars($periodMode, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="report_date" value="<?php echo htmlspecialchars($reportDate, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="report_month" value="<?php echo htmlspecialchars($reportMonth, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="report_year" value="<?php echo (int)$reportYear; ?>">
                    <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="col-lg-6">
                        <label class="form-label small text-muted mb-2">Client or Loan ID</label>
                        <div class="input-group modern-input-group">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control" name="search" placeholder="Search by client name or loan ID" value="<?php echo htmlspecialchars($searchTerm); ?>">
                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-search me-1"></i>Search
                            </button>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label small text-muted mb-2">Status</label>
                        <select class="form-select modern-select" name="status" onchange="this.form.submit()">
                            <option value="">All Statuses</option>
                            <option value="Active" <?php echo ($statusFilter === 'Active') ? 'selected' : ''; ?>>Active</option>
                            <option value="Paid" <?php echo ($statusFilter === 'Paid') ? 'selected' : ''; ?>>Paid</option>
                            <option value="Overdue" <?php echo ($statusFilter === 'Overdue') ? 'selected' : ''; ?>>Overdue</option>
                        </select>
                    </div>
                    <div class="col-lg-2">
                        <a href="loans_lending.php" class="btn btn-outline-secondary w-100 modern-reset-btn">Reset all</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="panel-card panel-card--flush mb-4">
            <div class="chart-panel__header">
                <h6 class="m-0 fw-bold"><i class="fas fa-list me-2 text-primary"></i><?php echo $periodMode === 'all' ? 'All loans' : 'Loans released in period'; ?></h6>
                <span class="text-muted small"><?php echo count($loans); ?> shown · <?php echo htmlspecialchars($periodLabel); ?></span>
            </div>
            <div class="chart-panel__body pt-0 px-0 pb-0">
                <?php if (count($loans) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 data-table">
                        <thead>
                            <tr>
                                <th>Loan ID</th>
                                <th>Borrower</th>
                                <th>Released</th>
                                <th>Loan Amount</th>
                                <th>Frequency</th>
                                <th>Installment</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($loans as $loan): ?>
                            <tr>
                                <td><?php echo $loan['loan_id']; ?></td>
                                <td>
                                    <a href="client_details_lending.php?id=<?php echo $loan['client_id']; ?>">
                                        <?php echo htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']); ?>
                                    </a>
                                </td>
                                <td><?php echo !empty($loan['date_released']) ? date('M d, Y', strtotime($loan['date_released'])) : '—'; ?></td>
                                <td>₱<?php echo number_format($loan['loan_amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($loan['payment_frequency_display']); ?></td>
                                <td>₱<?php echo number_format($loan['amount_to_collect'], 2); ?></td>
                                <td><?php echo date('M d, Y', strtotime($loan['due_date'])); ?></td>
                                <td><?php echo renderLoanStatusPill((string)$loan['display_status']); ?></td>
                                <td>
                                    <div class="btn-group" role="group">
                                        <a href="loan_details_lending.php?id=<?php echo $loan['loan_id']; ?>" class="btn btn-sm btn-info btn-rounded btn-action-hover" data-bs-toggle="tooltip" data-bs-placement="top" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if ($loan['status'] !== 'Paid'): ?>
                                        <a href="payments_lending.php?add=true&loan=<?php echo $loan['loan_id']; ?>&amount=<?php echo rawurlencode(number_format($loan['amount_to_collect'], 2, '.', '')); ?>&borrower=<?php echo rawurlencode($loan['first_name'] . ' ' . $loan['last_name']); ?>" class="btn btn-sm btn-success btn-rounded btn-action-hover" data-bs-toggle="tooltip" data-bs-placement="top" title="Record Payment">
                                            <i class="fas fa-cash-register"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="p-4">
                    <?php echo renderEmptyState('fa-folder-open', 'No loans found', !empty($searchTerm) || !empty($statusFilter) ? 'Try adjusting your search or filter criteria.' : 'Create a loan to get started.'); ?>
                    <div class="text-center mt-3">
                    <?php if (!empty($searchTerm) || !empty($statusFilter)): ?>
                    <a href="loans_lending.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-redo me-1"></i> Reset Filters</a>
                    <?php elseif (!isCollector()): ?>
                    <a href="admin_create_loan_lending.php" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i> Create New Loan</a>
                    <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
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
