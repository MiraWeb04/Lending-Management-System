<?php
/**
 * Client Details Page for Lending Management System
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

function displayValue($value) {
    $value = trim((string)$value);
    return $value !== '' ? htmlspecialchars($value) : 'Not Provided';
}

function displayCurrency($value) {
    if ($value === '' || $value === null) {
        return 'Not Provided';
    }
    return '₱' . number_format((float)$value, 2);
}

function ensureClientCollectorColumn() {
    global $conn;

    $columns = [];
    $columnStmt = $conn->query('SHOW COLUMNS FROM clients');
    foreach ($columnStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[$column['Field']] = true;
    }

    if (!isset($columns['collector_id'])) {
        $conn->exec('ALTER TABLE clients ADD COLUMN collector_id INT NULL DEFAULT NULL AFTER contact');
    }
}

ensureClientCollectorColumn();

// Initialize variables
$client = null;
$loans = [];
$activeLoans = [];
$paidLoans = [];
$overdueLoans = [];
$payments = [];
$message = '';
$messageType = '';

// Check if client ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: clients_lending.php');
    exit;
}

$clientId = (int)$_GET['id'];

if (isCollector() && !ensureCollectorCanAccessClient($clientId, $user['user_id'])) {
    denyCollectorAccess('You do not have permission to view this client.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_collector'])) {
    if (!isAdmin()) {
        $message = 'Only administrators can assign collectors.';
        $messageType = 'danger';
    } else {
        $collectorId = isset($_POST['collector_id']) && $_POST['collector_id'] !== '' ? (int)$_POST['collector_id'] : null;

        try {
            executeQuery('UPDATE clients SET collector_id = ? WHERE client_id = ?', [$collectorId, $clientId]);
            $message = 'Collector assignment updated successfully.';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Unable to update collector assignment.';
            $messageType = 'danger';
        }
    }
}

// Get client details
$clientQuery = "SELECT * FROM clients WHERE client_id = ? LIMIT 1";
$clientResult = executeQuery($clientQuery, [$clientId]);
if (!$clientResult) {
    header('Location: clients_lending.php');
    exit;
}
$client = $clientResult->fetch();

// Redirect if client not found
if (!$client) {
    header('Location: clients_lending.php');
    exit;
}

// Get borrower loan application details
$borrowerLoanApplication = null;
$clientUserId = isset($client['user_id']) && $client['user_id'] !== '' ? (int)$client['user_id'] : null;
$clientName = trim((string)($client['first_name'] ?? '') . ' ' . (string)($client['last_name'] ?? ''));
$clientEmail = trim((string)($client['email'] ?? ''));

if ($clientUserId !== null || $clientEmail !== '' || $clientName !== '') {
    $applicationWhere = [];
    $applicationParams = [];

    if ($clientUserId !== null) {
        $applicationWhere[] = '(user_id = ?)';
        $applicationParams[] = $clientUserId;
    }

    if ($clientEmail !== '') {
        $applicationWhere[] = '(email_address = ?)';
        $applicationParams[] = $clientEmail;
    }

    if ($clientName !== '') {
        $applicationWhere[] = '(borrower_name LIKE ?)';
        $applicationParams[] = '%' . $clientName . '%';
    }

    $applicationSql = 'SELECT * FROM loan_applications WHERE ' . implode(' OR ', $applicationWhere) . ' ORDER BY submitted_at DESC LIMIT 1';
    $applicationResult = executeQuery($applicationSql, $applicationParams);
    if ($applicationResult) {
        $borrowerLoanApplication = $applicationResult->fetch(PDO::FETCH_ASSOC);
    }
}

// Get client loans
$loansQuery = "SELECT * FROM loans WHERE client_id = ? ORDER BY date_released DESC";
$loansResult = executeQuery($loansQuery, [$clientId]);
$loans = $loansResult ? $loansResult->fetchAll() : [];

// Categorize loans by status
foreach ($loans as $loan) {
    if ($loan['status'] === 'Active') {
        $activeLoans[] = $loan;
    } elseif ($loan['status'] === 'Paid') {
        $paidLoans[] = $loan;
    } elseif ($loan['status'] === 'Overdue') {
        $overdueLoans[] = $loan;
    }
}

$activeCollectors = [];
$assignedCollector = null;

$collectorsQuery = "SELECT user_id, full_name, employee_id FROM users WHERE role = 'Collector' AND status = 'Active' ORDER BY full_name ASC";
$collectorsResult = executeQuery($collectorsQuery);
$activeCollectors = $collectorsResult ? $collectorsResult->fetchAll() : [];

if (!empty($client['collector_id'])) {
    $assignedCollectorStmt = executeQuery('SELECT user_id, full_name, employee_id FROM users WHERE user_id = ? AND role = ? AND status = ? LIMIT 1', [$client['collector_id'], 'Collector', 'Active']);
    if ($assignedCollectorStmt) {
        $assignedCollector = $assignedCollectorStmt->fetch();
    }
}

// Get recent payments
$paymentsQuery = "
    SELECT p.* 
    FROM payments p
    JOIN loans l ON p.loan_id = l.loan_id
    WHERE l.client_id = ?
    ORDER BY p.payment_date DESC, p.payment_id DESC
    LIMIT 10
";
$paymentsResult = executeQuery($paymentsQuery, [$clientId]);
$payments = $paymentsResult ? $paymentsResult->fetchAll() : [];

// Calculate total loan amount and total paid
$totalLoanAmount = 0;
$totalPaid = 0;
$totalOutstanding = 0;

foreach ($loans as $loan) {
    $totalLoanAmount += $loan['loan_amount'];
    
    // Get total paid for this loan
    $paidQuery = "SELECT SUM(amount_paid) as total FROM payments WHERE loan_id = ?";
    $paidResult = executeQuery($paidQuery, [$loan['loan_id']]);
    $paidAmount = $paidResult->fetch()['total'] ?? 0;
    
    $totalPaid += $paidAmount;
    
    if ($loan['status'] !== 'Paid') {
        $totalOutstanding += ($loan['total_payable'] - $paidAmount);
    }
}

// Format currency values
$formattedTotalLoanAmount = number_format($totalLoanAmount, 2);
$formattedTotalPaid = number_format($totalPaid, 2);
$formattedTotalOutstanding = number_format($totalOutstanding, 2);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Details - Lending Management System</title>
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
                    <i class="fas fa-user me-2"></i>Client Details
                </h1>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="clients_lending.php">Clients</a></li>
                        <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($client['first_name'] . ' ' . $client['last_name']); ?></li>
                    </ol>
                </nav>
            </div>
            <?php if (!isCollector()): ?>
            <div class="col-md-6 text-md-end">
                <a href="loans_lending.php?add=true&client=<?php echo $client['client_id']; ?>" class="btn btn-success me-2">
                    <i class="fas fa-plus me-1"></i> New Loan
                </a>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editClientModal">
                    <i class="fas fa-edit me-1"></i> Edit Client
                </button>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <!-- Client Information -->
        <div class="row mb-4">
            <div class="col-lg-4 mb-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 font-weight-bold"><i class="fas fa-info-circle me-2"></i>Client Information</h6>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-4">
                            <div class="display-1 text-primary mb-3">
                                <i class="fas fa-user-circle"></i>
                            </div>
                            <h4><?php echo htmlspecialchars($client['first_name'] . ' ' . $client['last_name']); ?></h4>
                            <p class="text-muted">Client ID: <?php echo $client['client_id']; ?></p>
                        </div>
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-phone me-2 text-primary"></i> Contact:</span>
                                <span><?php echo htmlspecialchars($client['contact']); ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-envelope me-2 text-primary"></i> Email:</span>
                                <span><?php echo htmlspecialchars($client['email'] ?? 'N/A'); ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-map-marker-alt me-2 text-primary"></i> Address:</span>
                                <span class="text-end"><?php echo htmlspecialchars($client['address']); ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-calendar-alt me-2 text-primary"></i> Registered:</span>
                                <span><?php echo date('M d, Y', strtotime($client['date_registered'])); ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-user-tie me-2 text-primary"></i> Assigned Collector:</span>
                                <span class="text-end"><?php echo !empty($assignedCollector) ? htmlspecialchars($assignedCollector['full_name']) : 'Unassigned'; ?></span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-lg-8 mb-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 font-weight-bold"><i class="fas fa-chart-pie me-2"></i>Loan Summary</h6>
                    </div>
                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-md-4 mb-3">
                                <div class="card bg-primary text-white">
                                    <div class="card-body py-3">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="mb-0">Total Loans</h6>
                                                <h3 class="mb-0 mt-2"><?php echo count($loans); ?></h3>
                                            </div>
                                            <div>
                                                <i class="fas fa-money-bill-wave fa-2x opacity-50"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="card bg-success text-white">
                                    <div class="card-body py-3">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="mb-0">Active Loans</h6>
                                                <h3 class="mb-0 mt-2"><?php echo count($activeLoans); ?></h3>
                                            </div>
                                            <div>
                                                <i class="fas fa-check-circle fa-2x opacity-50"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="card bg-danger text-white">
                                    <div class="card-body py-3">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="mb-0">Overdue Loans</h6>
                                                <h3 class="mb-0 mt-2"><?php echo count($overdueLoans); ?></h3>
                                            </div>
                                            <div>
                                                <i class="fas fa-exclamation-triangle fa-2x opacity-50"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>Financial Summary</th>
                                        <th class="text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>Total Loan Amount</strong></td>
                                        <td class="text-end">₱<?php echo $formattedTotalLoanAmount; ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Total Paid</strong></td>
                                        <td class="text-end">₱<?php echo $formattedTotalPaid; ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Outstanding Balance</strong></td>
                                        <td class="text-end text-danger">₱<?php echo $formattedTotalOutstanding; ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($borrowerLoanApplication): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="m-0 font-weight-bold"><i class="fas fa-file-signature me-2"></i>Borrower Loan Application Details</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="text-muted small">Loan Type</div>
                        <div class="fw-semibold"><?php echo displayValue($borrowerLoanApplication['loan_type'] ?? ''); ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Requested Amount</div>
                        <div class="fw-semibold"><?php echo displayCurrency($borrowerLoanApplication['loan_amount'] ?? ''); ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Loan Term</div>
                        <div class="fw-semibold"><?php echo displayValue($borrowerLoanApplication['loan_term'] ?? ''); ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Purpose of Loan</div>
                        <div class="fw-semibold"><?php echo displayValue($borrowerLoanApplication['loan_purpose'] ?? ''); ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Payment Frequency</div>
                        <div class="fw-semibold"><?php echo displayValue($borrowerLoanApplication['payment_frequency'] ?? ''); ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Number of Payments</div>
                        <div class="fw-semibold"><?php 
                            $numPayments = !empty($borrowerLoanApplication['number_of_payments']) ? (int)$borrowerLoanApplication['number_of_payments'] : 0;
                            $paymentFrequency = trim((string)($borrowerLoanApplication['payment_frequency'] ?? $borrowerLoanApplication['approved_payment_frequency'] ?? 'Monthly'));
                            $loanTermMonths = 0;

                            if (!$numPayments && !empty($borrowerLoanApplication['approved_loan_term'])) {
                                $termStr = trim((string)$borrowerLoanApplication['approved_loan_term']);
                                if (is_numeric($termStr)) {
                                    $loanTermMonths = (int)$termStr;
                                } elseif (preg_match('/(\d+)/', $termStr, $m)) {
                                    $loanTermMonths = (int)$m[1];
                                }
                            }
                            if (!$numPayments && $loanTermMonths === 0 && !empty($borrowerLoanApplication['loan_term'])) {
                                $termStr = trim((string)$borrowerLoanApplication['loan_term']);
                                if (is_numeric($termStr)) {
                                    $loanTermMonths = (int)$termStr;
                                } elseif (preg_match('/(\d+)/', $termStr, $m)) {
                                    $loanTermMonths = (int)$m[1];
                                }
                            }
                            if (!$numPayments && $loanTermMonths > 0) {
                                $numPayments = getNumberOfPayments($paymentFrequency, $loanTermMonths, $borrowerLoanApplication['approved_first_payment_date'] ?? null, $borrowerLoanApplication['approved_due_date'] ?? null);
                            }
                            echo $numPayments ? htmlspecialchars((string)$numPayments) : 'Not Available';
                        ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Estimated Installment</div>
                        <div class="fw-semibold"><?php 
                            $installmentAmount = null;
                            $paymentFrequency = trim((string)($borrowerLoanApplication['payment_frequency'] ?? $borrowerLoanApplication['approved_payment_frequency'] ?? 'Monthly'));
                            $paymentFrequencyDisplay = formatPaymentFrequencyLabel($paymentFrequency);
                            $loanTermMonths = 0;
                            $numberOfPayments = !empty($borrowerLoanApplication['number_of_payments']) ? (int)$borrowerLoanApplication['number_of_payments'] : 0;

                            // First, try to get Amount to Collect from loans table
                            if (!empty($loans)) {
                                $firstLoan = reset($loans);
                                if (!empty($firstLoan['amount_to_collect'])) {
                                    $installmentAmount = (float)$firstLoan['amount_to_collect'];
                                }
                            }

                            // If not found, try from stored values
                            if (!$installmentAmount && !empty($borrowerLoanApplication['estimated_payment'])) {
                                $installmentAmount = (float)$borrowerLoanApplication['estimated_payment'];
                            }
                            if (!$installmentAmount && !empty($borrowerLoanApplication['estimated_monthly_payment'])) {
                                $installmentAmount = (float)$borrowerLoanApplication['estimated_monthly_payment'];
                            }

                            if ($loanTermMonths === 0 && !empty($borrowerLoanApplication['approved_loan_term'])) {
                                $termStr = trim((string)$borrowerLoanApplication['approved_loan_term']);
                                $loanTermMonths = is_numeric($termStr) ? (int)$termStr : (preg_match('/(\d+)/', $termStr, $m) ? (int)$m[1] : 0);
                            }
                            if ($loanTermMonths === 0 && !empty($borrowerLoanApplication['loan_term'])) {
                                $termStr = trim((string)$borrowerLoanApplication['loan_term']);
                                $loanTermMonths = is_numeric($termStr) ? (int)$termStr : (preg_match('/(\d+)/', $termStr, $m) ? (int)$m[1] : 0);
                            }

                            if ($numberOfPayments <= 0 && $loanTermMonths > 0) {
                                $numberOfPayments = getNumberOfPayments($paymentFrequency, $loanTermMonths, $borrowerLoanApplication['approved_first_payment_date'] ?? null, $borrowerLoanApplication['approved_due_date'] ?? null);
                            }

                            if (!$installmentAmount && !empty($borrowerLoanApplication['approved_loan_amount']) && !empty($borrowerLoanApplication['approved_interest_rate']) && $numberOfPayments > 0) {
                                $P = (float)$borrowerLoanApplication['approved_loan_amount'];
                                $rate = (float)$borrowerLoanApplication['approved_interest_rate'];
                                $totalInterest = $P * ($rate / 100);
                                $totalRepayment = $P + ($totalInterest * max(1, $loanTermMonths));
                                $installmentAmount = calculateAmountToCollect($totalRepayment, $paymentFrequency, max(1, $loanTermMonths), null, $borrowerLoanApplication['approved_first_payment_date'] ?? null, $borrowerLoanApplication['approved_due_date'] ?? null);
                            }

                            if (!$installmentAmount && !empty($borrowerLoanApplication['requested_loan_amount']) && !empty($borrowerLoanApplication['loan_interest_rate']) && $numberOfPayments > 0) {
                                $P = (float)$borrowerLoanApplication['requested_loan_amount'];
                                $rate = (float)$borrowerLoanApplication['loan_interest_rate'];
                                $totalInterest = $P * ($rate / 100);
                                $totalRepayment = $P + ($totalInterest * max(1, $loanTermMonths));
                                $installmentAmount = calculateAmountToCollect($totalRepayment, $paymentFrequency, max(1, $loanTermMonths), null, $borrowerLoanApplication['approved_first_payment_date'] ?? null, $borrowerLoanApplication['approved_due_date'] ?? null);
                            }

                            echo $installmentAmount ? '₱' . number_format((float)$installmentAmount, 2) : 'Not Provided';
                        ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Estimated Due Date</div>
                        <div class="fw-semibold"><?php 
                            $dueDate = !empty($borrowerLoanApplication['estimated_due_date']) ? trim((string)$borrowerLoanApplication['estimated_due_date']) : '';
                            // If there is an existing released loan for this client, prefer the schedule's final due date
                            if (empty($dueDate) && !empty($loans)) {
                                foreach ($loans as $existingLoan) {
                                    $releaseId = !empty($existingLoan['release_id']) ? (int)$existingLoan['release_id'] : 0;
                                    if ($releaseId > 0) {
                                        $schedFinal = getFinalScheduleDueDate($releaseId);
                                        if ($schedFinal) {
                                            $dueDate = $schedFinal;
                                            break;
                                        }
                                    }
                                }
                            }
                            if (empty($dueDate) && !empty($borrowerLoanApplication['approved_loan_term'])) {
                                $termStr = trim((string)$borrowerLoanApplication['approved_loan_term']);
                                $termMonths = is_numeric($termStr) ? (int)$termStr : (preg_match('/(\d+)/', $termStr, $m) ? (int)$m[1] : 12);
                                if ($termMonths > 0) {
                                    $dueDateObj = new DateTime();
                                    $dueDateObj->modify("+{$termMonths} months");
                                    $dueDate = $dueDateObj->format('Y-m-d');
                                }
                            }
                            if (empty($dueDate) && !empty($borrowerLoanApplication['loan_term'])) {
                                $termStr = trim((string)$borrowerLoanApplication['loan_term']);
                                $termMonths = is_numeric($termStr) ? (int)$termStr : (preg_match('/(\d+)/', $termStr, $m) ? (int)$m[1] : 12);
                                if ($termMonths > 0) {
                                    $dueDateObj = new DateTime();
                                    $dueDateObj->modify("+{$termMonths} months");
                                    $dueDate = $dueDateObj->format('Y-m-d');
                                }
                            }
                            echo $dueDate ? htmlspecialchars($dueDate) : 'Not Provided';
                        ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="m-0 font-weight-bold"><i class="fas fa-user-plus me-2"></i>Assign Collector</h6>
            </div>
            <div class="card-body">
                <?php if (isAdmin()): ?>
                    <form action="client_details_lending.php?id=<?php echo $client['client_id']; ?>" method="post" class="row g-3 align-items-end">
                        <div class="col-md-8">
                            <label for="collectorSearch" class="form-label">Search active collectors</label>
                            <input type="text" class="form-control" id="collectorSearch" placeholder="Type collector name or employee ID" autocomplete="off" aria-label="Search collectors">
                            <div class="form-text">Type a name or employee ID to quickly find a collector.</div>
                            <select class="form-select mt-2" id="collectorSelect" name="collector_id">
                                <option value="">Unassigned</option>
                                <?php foreach ($activeCollectors as $collector): ?>
                                    <option value="<?php echo (int)$collector['user_id']; ?>" <?php echo !empty($client['collector_id']) && (int)$client['collector_id'] === (int)$collector['user_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($collector['full_name'] . ' (' . ($collector['employee_id'] ?? 'No ID') . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" name="assign_collector" class="btn btn-primary w-100">
                                <i class="fas fa-save me-2"></i>Save Assignment
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="alert alert-light mb-0">Only administrators can assign collectors to clients.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Loans and Payments Tabs -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <ul class="nav nav-tabs card-header-tabs" id="clientTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="loans-tab" data-bs-toggle="tab" data-bs-target="#loans" type="button" role="tab" aria-controls="loans" aria-selected="true">
                            <i class="fas fa-hand-holding-usd me-1"></i> Loans
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="payments-tab" data-bs-toggle="tab" data-bs-target="#payments" type="button" role="tab" aria-controls="payments" aria-selected="false">
                            <i class="fas fa-receipt me-1"></i> Payment History
                        </button>
                    </li>
                </ul>
            </div>
            <div class="card-body">
                <div class="tab-content" id="clientTabsContent">
                    <!-- Loans Tab -->
                    <div class="tab-pane fade show active" id="loans" role="tabpanel" aria-labelledby="loans-tab">
                        <?php if (count($loans) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Loan ID</th>
                                        <th>Amount</th>
                                        <th>Interest</th>
                                        <th>Total Payable</th>
                                        <th>Daily Payment</th>
                                        <th>Date Released</th>
                                        <th>Due Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($loans as $loan): ?>
                                    <tr>
                                        <td><?php echo $loan['loan_id']; ?></td>
                                        <td>₱<?php echo number_format($loan['loan_amount'], 2); ?></td>
                                        <td><?php echo $loan['interest']; ?>%</td>
                                        <td>₱<?php echo number_format($loan['total_payable'], 2); ?></td>
                                        <td>₱<?php echo number_format($loan['daily_payment'], 2); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($loan['date_released'])); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($loan['due_date'])); ?></td>
                                        <td>
                                            <?php if (strcasecmp($loan['status'], 'Active') === 0): ?>
                                                <span class="badge status-badge bg-success">Active</span>
                                            <?php elseif (strcasecmp($loan['status'], 'Paid') === 0): ?>
                                                <span class="badge status-badge bg-info">Paid</span>
                                            <?php elseif (strcasecmp($loan['status'], 'Overdue') === 0): ?>
                                                <span class="badge status-badge bg-danger">Overdue</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <a href="loan_details_lending.php?id=<?php echo $loan['loan_id']; ?>" class="btn btn-sm btn-info btn-rounded btn-action-hover" data-bs-toggle="tooltip" data-bs-placement="top" title="View Loan">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <?php if ($loan['status'] !== 'Paid'): ?>
                                                <a href="payments_lending.php?add=true&loan=<?php echo $loan['loan_id']; ?>&amount=<?php echo rawurlencode(number_format($loan['daily_payment'], 2, '.', '')); ?>&borrower=<?php echo rawurlencode($client['first_name'] . ' ' . $client['last_name']); ?>" class="btn btn-sm btn-success btn-rounded btn-action-hover" data-bs-toggle="tooltip" data-bs-placement="top" title="Record Payment">
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
                            <i class="fas fa-hand-holding-usd text-muted fa-3x mb-3"></i>
                            <p>No loans found for this client.</p>
                            <?php if (!isCollector()): ?>
                            <a href="loans_lending.php?add=true&client=<?php echo $client['client_id']; ?>" class="btn btn-primary">
                                <i class="fas fa-plus me-1"></i> Create New Loan
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Payments Tab -->
                    <div class="tab-pane fade" id="payments" role="tabpanel" aria-labelledby="payments-tab">
                        <?php if (count($payments) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Payment ID</th>
                                        <th>Loan ID</th>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Collector</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><?php echo $payment['payment_id']; ?></td>
                                        <td><?php echo $payment['loan_id']; ?></td>
                                        <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                        <td>₱<?php echo number_format($payment['amount_paid'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($payment['collector_name']); ?></td>
                                        <td>
                                            <a href="payments_lending.php?view=<?php echo $payment['payment_id']; ?>" class="btn btn-sm btn-info btn-rounded btn-action-hover" data-bs-toggle="tooltip" data-bs-placement="top" title="View Payment">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-receipt text-muted fa-3x mb-3"></i>
                            <p>No payment history found for this client.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!isCollector()): ?>
    <!-- Edit Client Modal -->
    <div class="modal fade" id="editClientModal" tabindex="-1" aria-labelledby="editClientModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="editClientModalLabel"><i class="fas fa-user-edit me-2"></i>Edit Client</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="clients_lending.php" method="post">
                    <input type="hidden" name="client_id" value="<?php echo $client['client_id']; ?>">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo htmlspecialchars($client['first_name']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo htmlspecialchars($client['last_name']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="contact" class="form-label">Contact Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="contact" name="contact" value="<?php echo htmlspecialchars($client['contact']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($client['email'] ?? ''); ?>">
                            </div>
                            <div class="col-12">
                                <label for="address" class="form-label">Address <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="address" name="address" rows="3" required><?php echo htmlspecialchars($client['address']); ?></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Client</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

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
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const searchInput = document.getElementById('collectorSearch');
            const collectorSelect = document.getElementById('collectorSelect');

            if (searchInput && collectorSelect) {
                searchInput.addEventListener('input', function () {
                    const query = this.value.toLowerCase();
                    Array.from(collectorSelect.options).forEach(function (option) {
                        const text = option.text.toLowerCase();
                        option.hidden = query !== '' && !text.includes(query);
                    });
                });
            }
        });
    </script>
</body>
</html>
