<?php
/**
 * Loans Management Page for Lending Management System
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

// Check if we're adding a new loan
if (isset($_GET['add']) && $_GET['add'] === 'true') {
    $addLoan = true;
    
    // Check if client is pre-selected
    if (isset($_GET['client']) && is_numeric($_GET['client'])) {
        $selectedClientId = (int)$_GET['client'];
    }
}

// Process loan form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isCollector()) {
        $message = 'Collectors can only view assigned loans.';
        $messageType = 'danger';
    } else {
    // Add new loan
    if (isset($_POST['add_loan'])) {
        $clientId = (int)$_POST['client_id'];
        $loanAmount = (float)$_POST['loan_amount'];
        $interest = (float)$_POST['interest'];
        $dateReleased = $_POST['date_released'];
        $paymentFrequency = trim((string)($_POST['payment_frequency'] ?? 'Monthly'));
        $loanTermInput = trim((string)($_POST['loan_term'] ?? ''));
        $loanTermMonths = resolveLoanTermMonths($loanTermInput !== '' ? $loanTermInput : null, $dateReleased, null);
        if ($loanTermMonths < 1) {
            $loanTermMonths = 12;
        }
        
        // Calculate due date using the selected repayment frequency and loan term
        $dueDate = calculateLoanDueDate($dateReleased, $paymentFrequency, $loanTermMonths);
        
        // Calculate total payable and installment amount based on the selected schedule
        $totalPayable = $loanAmount + ($loanAmount * ($interest / 100));
        $collectionAmount = calculateAmountToCollect($totalPayable, $paymentFrequency, $loanTermMonths, null, $dateReleased, $dueDate);
        
        try {
            try {
                $conn->exec("ALTER TABLE loans ADD COLUMN loan_term VARCHAR(50) DEFAULT NULL");
            } catch (Exception $e) {
            }

            // Insert new loan
            $insertQuery = "INSERT INTO loans (client_id, loan_amount, interest, total_payable, daily_payment, date_released, due_date, status, payment_frequency, loan_term) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?)";
            $params = [$clientId, $loanAmount, $interest, $totalPayable, $collectionAmount, $dateReleased, $dueDate, $paymentFrequency, $loanTermInput !== '' ? $loanTermInput : '12'];
            executeQuery($insertQuery, $params);
            global $conn;
$loanId = $conn->lastInsertId();
;
            $message = "Loan #$loanId has been successfully created.";
            $messageType = 'success';
            
            // Redirect to loan details
            header("Location: loan_details_lending.php?id=$loanId&message=$message&type=$messageType");
            exit;
        } catch (Exception $e) {
            $message = "Error creating loan: " . $e->getMessage();
            $messageType = 'danger';
        }
    }
    
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
    $loansQuery .= " AND c.collector_id = ?";
    $queryParams[] = $collectorUserId;
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
                SUM(l.loan_amount) as total_loan_amount,
                SUM(l.total_payable) as total_receivable,
                SUM(CASE WHEN l.status != 'Paid' THEN l.total_payable ELSE 0 END) as outstanding_receivable
              FROM loans l
              JOIN clients c ON l.client_id = c.client_id";
$statsParams = [];
if (isCollector()) {
    $statsQuery .= " WHERE c.collector_id = ?";
    $statsParams[] = $collectorUserId;
}
$statsResult = executeQuery($statsQuery, $statsParams);
$stats = $statsResult->fetch();

$totalLoanAmount = $stats['total_loan_amount'] ?? 0;
$totalReceivable = $stats['total_receivable'] ?? 0;
$outstandingReceivable = $stats['outstanding_receivable'] ?? 0;

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
    <title>Loans Management - Lending Management System</title>
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
        <?php if ($addLoan && !isCollector()): ?>
        <!-- Add Loan Form -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-white py-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-plus-circle me-2 text-primary"></i>Create New Loan</h5>
                            <a href="loans_lending.php" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-arrow-left me-1"></i> Back to Loans
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <form action="loans_lending.php" method="post" class="needs-validation" novalidate>
                            <input type="hidden" name="add_loan" value="1">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="client_id" class="form-label">Client <span class="text-danger">*</span></label>
                                    <select class="form-select" id="client_id" name="client_id" required>
                                        <option value="">Select Client</option>
                                        <?php foreach ($clients as $client): ?>
                                        <?php
                                            $loanStatusLabel = trim((string)($client['loan_status_label'] ?? ''));
                                            $displayName = htmlspecialchars($client['first_name'] . ' ' . $client['last_name'] . ($loanStatusLabel !== '' ? ' (' . $loanStatusLabel . ')' : ''));
                                        ?>
                                        <option value="<?php echo $client['client_id']; ?>" <?php echo ($selectedClientId == $client['client_id']) ? 'selected' : ''; ?>>
                                            <?php echo $displayName; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Please select a client.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="loan_amount" class="form-label">Loan Amount <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" class="form-control" id="loan_amount" name="loan_amount" min="1" step="0.01" required>
                                        <div class="invalid-feedback">Please enter a valid loan amount.</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label for="interest" class="form-label">Interest Rate (%) <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="interest" name="interest" min="0" step="0.01" value="5" required>
                                        <span class="input-group-text">%</span>
                                        <div class="invalid-feedback">Please enter a valid interest rate.</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label for="date_released" class="form-label">Date Released <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="date_released" name="date_released" value="<?php echo date('Y-m-d'); ?>" required>
                                    <div class="invalid-feedback">Please select a release date.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="payment_frequency" class="form-label">Payment Frequency <span class="text-danger">*</span></label>
                                    <select class="form-select" id="payment_frequency" name="payment_frequency" required>
                                        <option value="Daily">Daily</option>
                                        <option value="Weekly">Weekly</option>
                                        <option value="Semi-Monthly">Semi-Monthly</option>
                                        <option value="Monthly" selected>Monthly</option>
                                    </select>
                                    <div class="invalid-feedback">Please select a payment frequency.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="loan_term" class="form-label">Loan Term <span class="text-danger">*</span></label>
                                    <select class="form-select" id="loan_term" name="loan_term" required>
                                        <option value="3">3 months</option>
                                        <option value="6">6 months</option>
                                        <option value="12" selected>12 months</option>
                                        <option value="24">24 months</option>
                                    </select>
                                    <div class="invalid-feedback">Please select a loan term.</div>
                                </div>
                                <div class="col-12 mt-4">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <strong>Note:</strong> The system will automatically calculate the following:
                                        <ul class="mb-0 mt-2">
                                            <li>Final due date based on the selected payment schedule and loan term</li>
                                            <li>Total Payable Amount (Loan Amount + Interest)</li>
                                            <li>Installment amount based on the selected repayment schedule</li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="col-12 mt-3">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i> Create Loan
                                    </button>
                                    <a href="loans_lending.php" class="btn btn-secondary ms-2">
                                        <i class="fas fa-times me-1"></i> Cancel
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- Loans List -->
        <div class="page-hero">
            <div>
                <h1 class="page-title">
                    <i class="fas fa-hand-holding-usd me-2"></i>Loans Management
                </h1>
                <p class="page-subtitle">Review active, overdue, and paid loans from a single, polished workspace.</p>
            </div>
            <?php if (!isCollector()): ?>
            <div class="page-header-actions">
                <a href="loans_lending.php?add=true" class="btn btn-primary">
                    <i class="fas fa-plus me-1"></i> New Loan
                </a>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <!-- Loan Statistics -->
        <div class="row mb-4">
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card metric-card shadow-sm h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="metric-label">Total Loans</div>
                            <div class="metric-value"><?php echo $totalCount; ?></div>
                        </div>
                        <div class="metric-icon bg-primary-subtle text-primary">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card metric-card shadow-sm h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="metric-label">Active Loans</div>
                            <div class="metric-value"><?php echo $activeCount; ?></div>
                        </div>
                        <div class="metric-icon bg-success-subtle text-success">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card metric-card shadow-sm h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="metric-label">Overdue Loans</div>
                            <div class="metric-value"><?php echo $overdueCount; ?></div>
                        </div>
                        <div class="metric-icon bg-danger-subtle text-danger">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card metric-card shadow-sm h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="metric-label">Paid Loans</div>
                            <div class="metric-value"><?php echo $paidCount; ?></div>
                        </div>
                        <div class="metric-icon bg-info-subtle text-info">
                            <i class="fas fa-clipboard-check"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Financial Summary -->
        <div class="row mb-4">
            <div class="col-md-4 mb-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 font-weight-bold">Total Loan Amount</h6>
                    </div>
                    <div class="card-body">
                        <div class="text-center">
                            <h3 class="text-primary">₱<?php echo $formattedTotalLoanAmount; ?></h3>
                            <p class="text-muted mb-0">Principal amount of all loans</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 font-weight-bold">Total Receivable</h6>
                    </div>
                    <div class="card-body">
                        <div class="text-center">
                            <h3 class="text-success">₱<?php echo $formattedTotalReceivable; ?></h3>
                            <p class="text-muted mb-0">Principal + Interest of all loans</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 font-weight-bold">Outstanding Receivable</h6>
                    </div>
                    <div class="card-body">
                        <div class="text-center">
                            <h3 class="text-danger">₱<?php echo $formattedOutstandingReceivable; ?></h3>
                            <p class="text-muted mb-0">Amount yet to be collected</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search and Filter -->
        <div class="card shadow-sm mb-4 modern-panel">
            <div class="card-header bg-white py-3 border-0">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <h6 class="m-0 fw-bold"><i class="fas fa-search me-2 text-primary"></i>Search & Filter Loans</h6>
                    <span class="modern-panel__hint">Refine your view in seconds</span>
                </div>
            </div>
            <div class="card-body">
                <form action="loans_lending.php" method="get" class="row g-3 align-items-end">
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
                        <a href="loans_lending.php" class="btn btn-outline-secondary w-100 modern-reset-btn">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Loans Table -->
        <div class="card shadow-sm mb-4 modern-panel">
            <div class="card-header bg-white py-3 border-0">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <h6 class="m-0 fw-bold"><i class="fas fa-list me-2 text-primary"></i>Loans List</h6>
                    <span class="modern-panel__hint">Responsive record view</span>
                </div>
            </div>
            <div class="card-body">
                <?php if (count($loans) > 0): ?>
                <div class="loan-records-table-container">
                    <table class="table table-hover loan-records-table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Loan ID</th>
                                <th>Borrower</th>
                                <th>Loan Amount</th>
                                <th>Payment Frequency</th>
                                <th>Payment to be Collected</th>
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
                                <td>₱<?php echo number_format($loan['loan_amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($loan['payment_frequency_display']); ?></td>
                                <td>₱<?php echo number_format($loan['amount_to_collect'], 2); ?></td>
                                <td><?php echo date('M d, Y', strtotime($loan['due_date'])); ?></td>
                                <td>
                                    <?php if (strcasecmp($loan['display_status'], 'Active') === 0): ?>
                                        <span class="badge status-badge bg-success">Active</span>
                                    <?php elseif (strcasecmp($loan['display_status'], 'Paid') === 0): ?>
                                        <span class="badge status-badge bg-info">Paid</span>
                                    <?php elseif (strcasecmp($loan['display_status'], 'Overdue') === 0): ?>
                                        <span class="badge status-badge bg-danger">Overdue</span>
                                    <?php endif; ?>
                                </td>
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
                <div class="text-center py-5">
                    <i class="fas fa-search text-muted fa-3x mb-3"></i>
                    <p class="lead">No loans found</p>
                    <?php if (!empty($searchTerm) || !empty($statusFilter)): ?>
                    <p>Try adjusting your search or filter criteria</p>
                    <a href="loans_lending.php" class="btn btn-outline-secondary">
                        <i class="fas fa-redo me-1"></i> Reset Filters
                    </a>
                    <?php else: ?>
                    <?php if (!isCollector()): ?>
                    <a href="loans_lending.php?add=true" class="btn btn-primary">
                        <i class="fas fa-plus me-1"></i> Create New Loan
                    </a>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
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
