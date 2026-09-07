<?php
/**
 * Payments Management Page for Lending Management System
 */

// Include authentication functions
require_once 'includes/auth_lending.php';

// Require login to access this page
requireLogin();

// Get current user data
$user = getCurrentUser();

// Include database connection
require_once 'includes/db_lending.php';

// Allow borrowers to view a receipt directly via ?view=<id>
// but otherwise keep them redirected away from the full payments management UI.
if (isBorrower()) {
    if (!(isset($_GET['view']) && is_numeric($_GET['view']))) {
        header('Location: borrower_payment_history_lending.php');
        exit;
    }
}

// AJAX endpoint for loan detail lookup
if (isset($_GET['action']) && $_GET['action'] === 'fetch_loan' && isset($_GET['loan_id']) && is_numeric($_GET['loan_id'])) {
    $loanId = (int)$_GET['loan_id'];
    $loanQuery = "
        SELECT l.loan_id,
               l.loan_number,
               l.loan_amount,
               l.interest,
               l.total_payable,
               l.due_date,
               l.status,
               l.client_id,
               c.first_name,
               c.last_name,
               COALESCE(SUM(p.amount_paid), 0) AS amount_paid,
               (l.total_payable - COALESCE(SUM(p.amount_paid), 0)) AS remaining_balance
        FROM loans l
        JOIN clients c ON l.client_id = c.client_id
        LEFT JOIN payments p ON l.loan_id = p.loan_id
        WHERE l.loan_id = ?
    ";
    if (isCollector()) {
        $loanQuery .= ' AND (c.collector_id = ? OR l.collector_id = ?)';
        $loanResult = executeQuery($loanQuery . ' GROUP BY l.loan_id LIMIT 1', [$loanId, $user['user_id'], $user['user_id']]);
    } else {
        $loanResult = executeQuery($loanQuery . ' GROUP BY l.loan_id LIMIT 1', [$loanId]);
    }
    $loan = $loanResult->fetch();

    header('Content-Type: application/json');
    echo json_encode([
        'success' => $loan ? true : false,
        'loan' => $loan ?: null,
    ]);
    exit;
}

function pdfEscape($text) {
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string)$text);
}

function buildReceiptPdf($receiptData) {
    $lines = [
        'RJ and RR Finance Services',
        'Official Payment Receipt',
        '',
        'Receipt Number: ' . ($receiptData['receipt_number'] ?? ''),
        'Transaction Date: ' . ($receiptData['payment_date'] ?? ''),
        '',
        'Borrower Name: ' . ($receiptData['borrower_name'] ?? ''),
        'Client ID: ' . ($receiptData['client_id'] ?? ''),
        'Loan Number: ' . ($receiptData['loan_id'] ?? ''),
        'Collector Name: ' . ($receiptData['collector_name'] ?? ''),
        'Payment Method: ' . ($receiptData['payment_method'] ?? 'Cash'),
        '',
        'Previous Balance: ' . ($receiptData['previous_balance'] ?? ''),
        'Amount Paid: ' . ($receiptData['amount_paid'] ?? ''),
        'Remaining Balance: ' . ($receiptData['remaining_balance'] ?? ''),
        'Interest Paid: ' . ($receiptData['interest_paid'] ?? ''),
        'Penalty Paid: ' . ($receiptData['penalty_paid'] ?? ''),
        'Total Payment Received: ' . ($receiptData['total_payment_received'] ?? ''),
        '',
        'Payment Method: ' . ($receiptData['payment_method'] ?? 'Cash'),
        '',
        'Status: PAID'
    ];

    $content = '';
    $y = 760;
    foreach ($lines as $line) {
        $content .= "BT /F1 10 Tf 50 $y Td (" . pdfEscape($line) . ") Tj ET\n";
        $y -= 13;
    }

    $objects = [];
    $objects[] = "1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj";
    $objects[] = "2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj";
    $objects[] = "3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >>endobj";
    $objects[] = "4 0 obj<< /Length 0 >>stream\n$content\nendstream\nendobj";
    $objects[] = "5 0 obj<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>endobj";
    $objects[] = "6 0 obj<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>endobj";

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    $pos = strlen($pdf);
    foreach ($objects as $index => $object) {
        $offsets[$index] = $pos;
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\n";
        $pos = strlen($pdf);
    }

    $xrefStart = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    foreach ($objects as $index => $object) {
        $pdf .= str_pad($offsets[$index], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }

    $pdf .= "trailer<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xrefStart . "\n";
    $pdf .= "%%EOF";

    return $pdf;
}

// Handle direct viewing/downloading of a specific payment receipt via ?view=
if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $viewId = (int)$_GET['view'];

    // Fetch payment with loan and client info to validate ownership
    $paymentQuery = "
        SELECT p.*, l.loan_id, l.loan_amount, l.total_payable, l.status,
               c.client_id, c.first_name, c.last_name, c.user_id as client_user_id, c.contact
        FROM payments p
        JOIN loans l ON p.loan_id = l.loan_id
        JOIN clients c ON l.client_id = c.client_id
        WHERE p.payment_id = ?
        LIMIT 1
    ";
    $paymentResult = executeQuery($paymentQuery, [$viewId]);
    $viewPayment = $paymentResult->fetch(PDO::FETCH_ASSOC);

    // If borrower, ensure they own this payment
    if (isBorrower() && $viewPayment) {
        $owned = false;
        $clientId = (int)($viewPayment['client_id'] ?? 0);
        $clientUserId = $viewPayment['client_user_id'] ?? null;
        if (!empty($clientUserId) && $clientUserId == ($user['user_id'] ?? 0)) {
            $owned = true;
        } else {
            // fallback: check client record linked to this user
            $clientRow = executeQuery('SELECT client_id FROM clients WHERE user_id = ? LIMIT 1', [($user['user_id'] ?? 0)])->fetch(PDO::FETCH_ASSOC);
            if ($clientRow && (int)$clientRow['client_id'] === $clientId) {
                $owned = true;
            }
        }

        if (!$owned) {
            header('Location: borrower_payment_history_lending.php');
            exit;
        }
    }

    if ($viewPayment && isset($_GET['download']) && $_GET['download'] == '1') {
        $paidQuery = "SELECT COALESCE(SUM(amount_paid), 0) AS total_paid FROM payments WHERE loan_id = ?";
        $paidResult = executeQuery($paidQuery, [(int)($viewPayment['loan_id'] ?? 0)]);
        $totalPaidToDate = (float)($paidResult->fetch()['total_paid'] ?? 0);

        $loanOutstanding = max(0, (float)($viewPayment['total_payable'] ?? 0) - $totalPaidToDate);
        $receiptData = [
            'receipt_number' => $viewPayment['receipt_number'] ?? '',
            'payment_date' => date('Y-m-d H:i:s', strtotime($viewPayment['payment_date'])),
            'borrower_name' => trim(($viewPayment['first_name'] ?? '') . ' ' . ($viewPayment['last_name'] ?? '')),
            'client_id' => $viewPayment['client_id'] ?? '',
            'loan_id' => $viewPayment['loan_id'] ?? '',
            'collector_name' => $viewPayment['collector_name'] ?? ($user['full_name'] ?? 'Unknown'),
            'payment_method' => $viewPayment['payment_method'] ?? 'Cash',
            'previous_balance' => '₱' . number_format(max(0, (float)($viewPayment['total_payable'] ?? 0) - $totalPaidToDate + (float)($viewPayment['amount_paid'] ?? 0)), 2),
            'amount_paid' => '₱' . number_format((float)($viewPayment['amount_paid'] ?? 0), 2),
            'remaining_balance' => '₱' . number_format(max(0, $loanOutstanding), 2),
            'interest_paid' => '₱0.00',
            'penalty_paid' => '₱0.00',
            'total_payment_received' => '₱' . number_format((float)($viewPayment['amount_paid'] ?? 0), 2),
        ];
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="receipt-' . ($viewPayment['payment_id'] ?? '0') . '.pdf"');
        echo buildReceiptPdf($receiptData);
        exit;
    }
}

ensurePaymentReceiptColumns();

// Initialize variables
$message = '';
$messageType = '';
$payments = [];
$loans = [];
$addPayment = false;
$selectedLoanId = null;
$searchTerm = '';
$dateFilter = '';
$viewPayment = $viewPayment ?? null;
$paymentMethodValue = '';
$referenceNumberValue = '';
$remarksValue = '';
$amountPaidValue = '';
$borrowerNameValue = '';
$collectorNameValue = $user['full_name'] ?? '';

$collectorUserId = (int)($user['user_id'] ?? 0);

// Get all active loans for dropdown
if (isCollector()) {
    $loansQuery = "
        SELECT l.loan_id, l.loan_number, l.client_id, l.loan_amount, l.interest, l.total_payable, l.status, l.due_date,
               c.first_name, c.last_name,
               COALESCE((SELECT SUM(p.amount_paid) FROM payments p WHERE p.loan_id = l.loan_id), 0) AS amount_paid
        FROM loans l
        JOIN clients c ON l.client_id = c.client_id
        WHERE (c.collector_id = ? OR l.collector_id = ?) AND l.status != 'Paid'
        ORDER BY l.loan_number DESC, l.loan_id DESC
    ";
    $loansResult = executeQuery($loansQuery, [$collectorUserId, $collectorUserId]);
} else {
    $loansQuery = "
        SELECT l.loan_id, l.loan_number, l.client_id, l.loan_amount, l.interest, l.total_payable, l.status, l.due_date,
               c.first_name, c.last_name,
               COALESCE((SELECT SUM(p.amount_paid) FROM payments p WHERE p.loan_id = l.loan_id), 0) AS amount_paid
        FROM loans l
        JOIN clients c ON l.client_id = c.client_id
        WHERE l.status != 'Paid'
        ORDER BY l.loan_number DESC, l.loan_id DESC
    ";
    $loansResult = executeQuery($loansQuery);
}
$loans = $loansResult->fetchAll();

// Check if we're adding a new payment
if (isset($_GET['add']) && $_GET['add'] === 'true') {
    $addPayment = true;
    
    // Check if loan is pre-selected
    if (isset($_GET['loan']) && is_numeric($_GET['loan'])) {
        $selectedLoanId = (int)$_GET['loan'];
    }

    if (isset($_GET['amount']) && is_numeric($_GET['amount'])) {
        $amountPaidValue = number_format((float)$_GET['amount'], 2, '.', '');
    }

    if (isset($_GET['borrower']) && trim($_GET['borrower']) !== '') {
        $borrowerNameValue = trim($_GET['borrower']);
    }

    
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ============================
    // Add Payment
    // ============================
    if (isset($_POST['add_payment'])) {
        $addPayment = true;
        if (isset($_POST['loan_id']) && is_numeric($_POST['loan_id'])) {
            $selectedLoanId = (int)$_POST['loan_id'];
        }

        $loanId = isset($_POST['loan_id']) ? (int)$_POST['loan_id'] : null;
        $paymentAmount = isset($_POST['amount_paid']) ? (float)$_POST['amount_paid'] : 0;
        $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
        $collectorName = trim($_POST['collector_name'] ?? ($user['full_name'] ?? 'Unknown'));
        $paymentMethod = trim($_POST['payment_method'] ?? '');
        $remarksValue = trim($_POST['remarks'] ?? '');
        $amountPaidValue = $_POST['amount_paid'] ?? '';
        $paymentMethodValue = $paymentMethod;
        $collectorNameValue = $collectorName;

        if ($loanId !== null && $loanId > 0) {
            $loanQuery = "SELECT loan_id, client_id, total_payable, status FROM loans WHERE loan_id = ? LIMIT 1";
            $loanResult = executeQuery($loanQuery, [$loanId]);
            $loan = $loanResult->fetch();
        } else {
            $loan = false;
        }

        if (empty($paymentMethod)) {
            $message = "Payment method is required.";
            $messageType = 'danger';
        } elseif ($paymentAmount <= 0) {
            $message = "Payment amount must be greater than zero.";
            $messageType = 'danger';
        } elseif (!$loan) {
            $message = "Invalid loan selected.";
            $messageType = 'danger';
        } else {
            $paidQuery = "SELECT COALESCE(SUM(amount_paid), 0) as total FROM payments WHERE loan_id = ?";
            $paidResult = executeQuery($paidQuery, [$loanId]);
            $totalPaid = (float)($paidResult->fetch()['total'] ?? 0);
            $remainingBalance = max(0, (float)$loan['total_payable'] - $totalPaid);

            if ($paymentAmount > $remainingBalance) {
                $message = "Payment amount cannot exceed the remaining balance of ₱" . number_format($remainingBalance, 2) . ".";
                $messageType = 'danger';
            } else {
                try {
                    $collectorId = isCollector() ? (int)$user['user_id'] : null;
                    $clientId = (int)($loan['client_id'] ?? 0);
                    recordPaymentTransaction($loanId, $paymentDate, $paymentAmount, $collectorName, $collectorId, $clientId, $paymentMethod);

                    $message = "Payment of ₱" . number_format($paymentAmount, 2) . " has been recorded successfully.";
                    $messageType = 'success';

                    header("Location: payments_lending.php?message=" . urlencode($message) . "&type=$messageType");
                    exit;
                } catch (Exception $e) {
                    $message = "Error recording payment: " . $e->getMessage();
                    $messageType = 'danger';
                }
            }
        }
    }

    // ============================
    // Delete Payment
    // ============================
    if (isset($_POST['delete_payment'])) {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $loanId = (int)($_POST['loan_id'] ?? 0);

        if ($paymentId > 0 && $loanId > 0) {
            $paymentQuery = "SELECT amount_paid FROM payments WHERE payment_id = ? LIMIT 1";
            $paymentResult = executeQuery($paymentQuery, [$paymentId]);
            $payment = $paymentResult->fetch();

            if ($payment) {
                try {
                    $deleteQuery = "DELETE FROM payments WHERE payment_id = ?";
                    executeQuery($deleteQuery, [$paymentId]);

                    $loanQuery = "SELECT total_payable FROM loans WHERE loan_id = ? LIMIT 1";
                    $loanResult = executeQuery($loanQuery, [$loanId]);
                    $loan = $loanResult->fetch();

                    if ($loan) {
                        $paidQuery = "SELECT SUM(amount_paid) as total FROM payments WHERE loan_id = ?";
                        $paidResult = executeQuery($paidQuery, [$loanId]);
                        $totalPaid = $paidResult->fetch()['total'] ?? 0;

                        if ($totalPaid < $loan['total_payable']) {
                            $updateQuery = "UPDATE loans SET status = 'Active' WHERE loan_id = ? AND status = 'Paid'";
                            executeQuery($updateQuery, [$loanId]);
                        }
                    }

                    $message = "Payment has been deleted successfully.";
                    $messageType = 'success';
                    header("Location: payments_lending.php?message=" . urlencode($message) . "&type=$messageType");
                    exit;
                } catch (Exception $e) {
                    $message = "Error deleting payment: " . $e->getMessage();
                    $messageType = 'danger';
                }
            } else {
                $message = "Payment not found.";
                $messageType = 'danger';
            }
        }
    }
}

// Handle search and filtering
if (isset($_GET['search'])) {
    $searchTerm = trim($_GET['search']);
}

if (isset($_GET['date'])) {
    $dateFilter = trim($_GET['date']);
}

// Check for messages passed via URL
if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = $_GET['message'];
    $messageType = $_GET['type'];
}

// Build query based on search and filter
$paymentsQuery = "
    SELECT p.*, l.loan_id, c.first_name, c.last_name 
    FROM payments p
    JOIN loans l ON p.loan_id = l.loan_id
    JOIN clients c ON l.client_id = c.client_id
    WHERE 1=1
";

$queryParams = [];

if (isCollector()) {
    $paymentsQuery .= " AND c.collector_id = ?";
    $queryParams[] = $collectorUserId;
}

if (!empty($searchTerm)) {
    $paymentsQuery .= " AND (c.first_name LIKE ? OR c.last_name LIKE ? OR CONCAT(c.first_name, ' ', c.last_name) LIKE ? OR p.collector_name LIKE ? OR p.payment_id LIKE ?)"; 
    $searchParam = "%$searchTerm%";
    $queryParams = array_merge($queryParams, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
}

if (!empty($dateFilter)) {
    $paymentsQuery .= " AND DATE(p.payment_date) = ?";
    $queryParams[] = $dateFilter;
}

$paymentsQuery .= " ORDER BY p.payment_date DESC, p.payment_id DESC";

// Execute the query
$paymentsResult = executeQuery($paymentsQuery, $queryParams);
$payments = $paymentsResult->fetchAll();

// Get today's date for default filter
$today = date('Y-m-d');

// Get today's total collection
$todayCollectionQuery = "SELECT SUM(p.amount_paid) as total FROM payments p JOIN loans l ON p.loan_id = l.loan_id JOIN clients c ON l.client_id = c.client_id WHERE DATE(p.payment_date) = ?";
$todayCollectionParams = [$today];
if (isCollector()) {
    $todayCollectionQuery .= " AND c.collector_id = ?";
    $todayCollectionParams[] = $collectorUserId;
}
$todayCollectionResult = executeQuery($todayCollectionQuery, $todayCollectionParams);
$todayCollection = $todayCollectionResult->fetch()['total'] ?? 0;

// Get total collection for all time
$totalCollectionQuery = "SELECT SUM(p.amount_paid) as total FROM payments p JOIN loans l ON p.loan_id = l.loan_id JOIN clients c ON l.client_id = c.client_id";
$totalCollectionParams = [];
if (isCollector()) {
    $totalCollectionQuery .= " WHERE c.collector_id = ?";
    $totalCollectionParams[] = $collectorUserId;
}
$totalCollectionResult = executeQuery($totalCollectionQuery, $totalCollectionParams);
$totalCollection = $totalCollectionResult->fetch()['total'] ?? 0;

// Format currency values
$formattedTodayCollection = number_format($todayCollection, 2);
$formattedTotalCollection = number_format($totalCollection, 2);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments Management - Lending Management System</title>
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
        <?php if ($viewPayment): ?>
        <!-- View Payment Details -->
        <div class="row mb-4">
            <div class="col-md-6">
                <h1 class="h3 mb-0 text-gray-800">
                    <i class="fas fa-receipt me-2"></i>Payment Details
                </h1>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="payments_lending.php">Payments</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Payment #<?php echo $viewPayment['payment_id']; ?></li>
                    </ol>
                </nav>
            </div>
            <div class="col-md-6 text-md-end">
                <a href="payments_lending.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Back to Payments
                </a>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-10 mx-auto">
                <div class="card shadow-sm mb-4 border-0">
                    <div class="card-header bg-primary text-white py-3 d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="m-0 fw-bold"><i class="fas fa-file-invoice-dollar me-2"></i>Official Payment Receipt</h6>
                            <small class="opacity-75">Professional utility-style receipt</small>
                        </div>
                            <?php if (isCollector() || isAdmin()): ?>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-light btn-sm" onclick="(typeof printReceipt === 'function' ? printReceipt() : window.print())"><i class="fas fa-print me-1"></i> Print Receipt</button>
                                <a href="payments_lending.php?view=<?php echo $viewPayment['payment_id']; ?>&download=1" class="btn btn-outline-light btn-sm"><i class="fas fa-download me-1"></i> Download PDF</a>
                            </div>
                            <?php endif; ?>
                    </div>
                    <div class="card-body p-4 receipt-paper">
                        <div class="receipt-shell p-4 border border-2 rounded-3">
                            <div class="row align-items-center mb-4 border-bottom border-2 pb-3">
                                <div class="col-md-8">
                                    <div class="d-flex align-items-center mb-2">
                                        <div class="me-3" style="width:54px;height:54px;border-radius:18px;overflow:hidden;border:2px solid rgba(21,91,217,.14);box-shadow:0 10px 24px rgba(21,91,217,.16);background:#fff;display:grid;place-items:center;">
                                            <img src="images/logo.png" alt="Lending Management System logo" style="width:100%;height:100%;object-fit:cover;">
                                        </div>
                                        <div>
                                            <h3 class="mb-0 fw-bold text-primary">RJ and RR Finance Services</h3>
                                            <p class="mb-0 text-muted">Official Payment Receipt</p>
                                        </div>
                                    </div>
                                    <p class="mb-1"><i class="fas fa-map-marker-alt me-2 text-primary"></i> Purok San Francisco, Poblacion, Sominot, ZDS</p>
                                    <p class="mb-1"><i class="fas fa-phone me-2 text-primary"></i> +63 9817074262</p>
                                    <p class="mb-0"><i class="fas fa-envelope me-2 text-primary"></i> RJ&RRservices@gmail.com</p>
                                </div>
                                <div class="col-md-4 text-md-end">
                                    <div class="border rounded-3 p-3 bg-light">
                                        <p class="mb-1 fw-bold text-primary">Receipt Number</p>
                                        <h5 class="mb-0 fw-bold"><?php echo htmlspecialchars($viewPayment['receipt_number'] ?? 'RJRR-000000'); ?></h5>
                                        <p class="mb-0 mt-2 text-muted">Transaction Date: <?php echo date('Y-m-d H:i:s', strtotime($viewPayment['payment_date'])); ?></p>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <div class="border rounded-3 p-3 h-100">
                                        <h6 class="fw-bold text-primary mb-3"><i class="fas fa-user me-2"></i>Borrower Information</h6>
                                        <p class="mb-2"><strong>Borrower Name:</strong> <?php echo htmlspecialchars(trim(($viewPayment['first_name'] ?? '') . ' ' . ($viewPayment['last_name'] ?? ''))); ?></p>
                                        <p class="mb-2"><strong>Client ID:</strong> <?php echo htmlspecialchars($viewPayment['client_id'] ?? 'N/A'); ?></p>
                                        <p class="mb-2"><strong>Loan Number:</strong> <?php echo htmlspecialchars($viewPayment['loan_id'] ?? 'N/A'); ?></p>
                                        <p class="mb-0"><strong>Collector Name:</strong> <?php echo htmlspecialchars($viewPayment['collector_name'] ?? 'Unknown'); ?></p>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="border rounded-3 p-3 h-100">
                                        <h6 class="fw-bold text-primary mb-3"><i class="fas fa-clipboard-check me-2"></i>Transaction Details</h6>
                                        <p class="mb-2"><strong>Receipt Number:</strong> <?php echo htmlspecialchars($viewPayment['receipt_number'] ?? 'RJRR-000000'); ?></p>
                                        <p class="mb-2"><strong>Payment Date:</strong> <?php echo date('F d, Y', strtotime($viewPayment['payment_date'])); ?></p>
                                        <p class="mb-2"><strong>Recorded By:</strong> <?php echo htmlspecialchars($viewPayment['collector_name'] ?? 'Unknown'); ?></p>
                                        <p class="mb-0"><strong>Payment Method:</strong> <?php echo htmlspecialchars($viewPayment['payment_method'] ?? 'Cash'); ?></p>
                                    </div>
                                </div>
                            </div>

                            <div class="table-responsive mb-4">
                                <table class="table table-bordered mb-0 receipt-table">
                                    <thead class="table-primary">
                                        <tr>
                                            <th class="py-3">Payment Details</th>
                                            <th class="text-end py-3">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Previous Balance</td>
                                            <td class="text-end">₱<?php echo number_format(max(0, (float)($viewPayment['total_payable'] ?? 0) - (float)($viewPayment['amount_paid'] ?? 0)), 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Amount Paid</td>
                                            <td class="text-end">₱<?php echo number_format((float)($viewPayment['amount_paid'] ?? 0), 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Remaining Balance</td>
                                            <td class="text-end">₱<?php echo number_format(max(0, (float)($viewPayment['total_payable'] ?? 0) - (float)($viewPayment['amount_paid'] ?? 0)), 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Interest Paid</td>
                                            <td class="text-end">₱0.00</td>
                                        </tr>
                                        <tr>
                                            <td>Penalty Paid</td>
                                            <td class="text-end">₱0.00</td>
                                        </tr>
                                        <tr class="table-light fw-bold">
                                            <td>Total Payment Received</td>
                                            <td class="text-end">₱<?php echo number_format((float)($viewPayment['amount_paid'] ?? 0), 2); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <div class="border rounded-3 p-3">
                                        <p class="mb-1"><strong>Status:</strong> <span class="badge bg-success">PAID</span></p>
                                        <p class="mb-0"><strong>Payment Method:</strong> <?php echo htmlspecialchars($viewPayment['payment_method'] ?? 'Cash'); ?></p>
                                    </div>
                                </div>
                                <div class="col-md-6 text-md-end">
                                    <div class="border rounded-3 p-3">
                                        <p class="mb-1"><strong>Loan Status:</strong> <?php echo htmlspecialchars($viewPayment['status'] ?? 'Active'); ?></p>
                                        <p class="mb-0"><strong>Recorded On:</strong> <?php echo date('F d, Y', strtotime($viewPayment['payment_date'])); ?></p>
                                    </div>
                                </div>
                            </div>

                            <div class="row mt-4">
                                <div class="col-md-4 text-center">
                                    <p class="mb-2 border-top border-2 pt-3"><strong>Borrower's Signature</strong></p>
                                </div>
                                <div class="col-md-4 text-center">
                                    <p class="mb-2 border-top border-2 pt-3"><strong>Collector's Signature</strong></p>
                                </div>
                                <div class="col-md-4 text-center">
                                    <p class="mb-2 border-top border-2 pt-3"><strong>Authorized Representative</strong></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php elseif ($addPayment): ?>
        <!-- Add Payment Form -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-white py-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-plus-circle me-2 text-primary"></i>Record New Payment</h5>
                            <a href="payments_lending.php" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-arrow-left me-1"></i> Back to Payments
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($message)): ?>
                        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                            <?php echo $message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (count($loans) > 0): ?>
                        <form action="payments_lending.php" method="post" class="needs-validation" novalidate>
                            <input type="hidden" name="add_payment" value="1">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="loan_id" class="form-label">Select Loan / Borrower <span class="text-danger">*</span></label>
                                    <select class="form-select" id="loan_id" name="loan_id" required>
                                        <option value="">Select Loan / Borrower</option>
                                        <?php foreach ($loans as $loan): ?>
                                        <option value="<?php echo $loan['loan_id']; ?>" 
                                            data-loan-number="<?php echo htmlspecialchars($loan['loan_number'] ?? $loan['loan_id']); ?>"
                                            data-interest="<?php echo htmlspecialchars((float)($loan['interest'] ?? 0)); ?>"
                                            data-loan-amount="<?php echo htmlspecialchars(number_format((float)($loan['loan_amount'] ?? 0), 2, '.', '')); ?>"
                                            data-total-payable="<?php echo htmlspecialchars(number_format((float)($loan['total_payable'] ?? 0), 2, '.', '')); ?>"
                                            data-collect-amount="<?php echo htmlspecialchars(number_format((float)($loan['amount_to_collect'] ?? 0), 2, '.', '')); ?>"
                                            data-borrower-name="<?php echo htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']); ?>"
                                            data-amount-paid="<?php echo htmlspecialchars(number_format((float)($loan['amount_paid'] ?? 0), 2, '.', '')); ?>"
                                            data-remaining-balance="<?php echo htmlspecialchars(number_format((float)max(0, ($loan['total_payable'] ?? 0) - ($loan['amount_paid'] ?? 0)), 2, '.', '')); ?>"
                                            data-due-date="<?php echo htmlspecialchars($loan['due_date']); ?>"
                                            data-status="<?php echo htmlspecialchars($loan['status']); ?>"
                                            <?php echo ($selectedLoanId == $loan['loan_id']) ? 'selected' : ''; ?>>
                                            Loan #<?php echo htmlspecialchars($loan['loan_number'] ?? $loan['loan_id']); ?> - <?php echo htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']); ?> (₱<?php echo number_format($loan['loan_amount'], 2); ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text mt-2" id="borrower_label" style="display:none;color:#333;font-weight:500;"></div>
                                    <div class="invalid-feedback">Please select a loan.</div>
                                </div>
                                <div class="col-12 mt-4">
                                    <h5 class="mb-3">Payment Information</h5>
                                </div>
                                <div class="col-md-6">
                                    <label for="amount_paid" class="form-label">Payment Amount <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" class="form-control" id="amount_paid" name="amount_paid" min="0.01" step="0.01" value="<?php echo htmlspecialchars($amountPaidValue); ?>" required>
                                        <div class="invalid-feedback">Please enter a valid payment amount.</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label for="payment_method" class="form-label">Payment Method <span class="text-danger">*</span></label>
                                    <select class="form-select" id="payment_method" name="payment_method" required>
                                        <option value="">Select Method</option>
                                        <option value="Cash" <?php echo ($paymentMethodValue === 'Cash') ? 'selected' : ''; ?>>Cash</option>
                                        <option value="Bank Transfer" <?php echo ($paymentMethodValue === 'Bank Transfer') ? 'selected' : ''; ?>>Bank Transfer</option>
                                        <option value="E-Wallet" <?php echo ($paymentMethodValue === 'E-Wallet') ? 'selected' : ''; ?>>E-Wallet</option>
                                    </select>
                                    <div class="invalid-feedback">Please select a payment method.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="payment_date" class="form-label">Payment Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="payment_date" name="payment_date" value="<?php echo htmlspecialchars($paymentDate ?? date('Y-m-d')); ?>" required>
                                    <div class="invalid-feedback">Please select a payment date.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="collector_name" class="form-label">Collector Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="collector_name" name="collector_name" value="<?php echo htmlspecialchars($collectorNameValue); ?>" required>
                                    <div class="invalid-feedback">Please enter the collector's name.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="remarks" class="form-label">Remarks / Notes</label>
                                    <textarea class="form-control" id="remarks" name="remarks" rows="2" placeholder="Optional payment notes"><?php echo htmlspecialchars($remarksValue); ?></textarea>
                                </div>
                                <div class="col-12 mt-3">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i> Record Payment
                                    </button>
                                    <a href="payments_lending.php" class="btn btn-secondary ms-2">
                                        <i class="fas fa-times me-1"></i> Cancel
                                    </a>
                                </div>
                            </div>
                        </form>
                        <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            There are no active loans in the system. You need to <a href="loans_lending.php?add=true">create a loan</a> before recording payments.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- Payments List -->
        <div class="page-hero">
            <div>
                <h1 class="page-title">
                    <i class="fas fa-cash-register me-2"></i>Payments Management
                </h1>
                <p class="page-subtitle">Monitor collections and review official receipts with a professional finance workflow.</p>
            </div>
            <div class="page-header-actions">
                <a href="payments_lending.php?add=true" class="btn btn-primary">
                    <i class="fas fa-plus me-1"></i> Record Payment
                </a>
            </div>
        </div>

        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <!-- Payment Statistics -->
        <div class="row mb-4">
            <div class="col-md-6 mb-4">
                <div class="card metric-card shadow-sm h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="metric-label">Today's Collection</div>
                            <div class="metric-value">₱<?php echo $formattedTodayCollection; ?></div>
                        </div>
                        <div class="metric-icon bg-success-subtle text-success">
                            <i class="fas fa-calendar-day"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 mb-4">
                <div class="card metric-card shadow-sm h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="metric-label">Total Collection</div>
                            <div class="metric-value">₱<?php echo $formattedTotalCollection; ?></div>
                        </div>
                        <div class="metric-icon bg-primary-subtle text-primary">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search and Filter -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="m-0 font-weight-bold"><i class="fas fa-search me-2"></i>Search & Filter Payments</h6>
            </div>
            <div class="card-body">
                <form action="payments_lending.php" method="get" class="row g-3">
                    <div class="col-md-5">
                        <div class="input-group">
                            <input type="text" class="form-control" name="search" placeholder="Search by client name or collector" value="<?php echo htmlspecialchars($searchTerm); ?>">
                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="input-group">
                            <span class="input-group-text">Date</span>
                            <input type="date" class="form-control" name="date" value="<?php echo $dateFilter; ?>">
                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-filter"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <a href="payments_lending.php" class="btn btn-secondary w-100">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Payments Table -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="m-0 font-weight-bold"><i class="fas fa-list me-2"></i>Payments List</h6>
            </div>
            <div class="card-body">
                <?php if (count($payments) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Payment ID</th>
                                <th>Date</th>
                                <th>Client</th>
                                <th>Loan ID</th>
                                <th>Amount</th>
                                <th>Collector</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?php echo $payment['payment_id']; ?></td>
                                <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?>
                                </td>
                                <td>
                                    <a href="loan_details_lending.php?id=<?php echo $payment['loan_id']; ?>">
                                        #<?php echo $payment['loan_id']; ?>
                                    </a>
                                </td>
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
                    <i class="fas fa-search text-muted fa-3x mb-3"></i>
                    <p class="lead">No payments found</p>
                    <?php if (!empty($searchTerm) || !empty($dateFilter)): ?>
                    <p>Try adjusting your search or filter criteria</p>
                    <a href="payments_lending.php" class="btn btn-outline-secondary">
                        <i class="fas fa-redo me-1"></i> Reset Filters
                    </a>
                    <?php else: ?>
                    <a href="payments_lending.php?add=true" class="btn btn-primary">
                        <i class="fas fa-plus me-1"></i> Record New Payment
                    </a>
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

    (function () {
        var loanSelect = document.getElementById('loan_id');
        var form = document.querySelector('.needs-validation');
        var amountPaidInput = document.getElementById('amount_paid');
        var paymentDateInput = document.getElementById('payment_date');
        var paymentMethodSelect = document.getElementById('payment_method');
        var currentDate = '<?php echo date('Y-m-d'); ?>';

        function setDateToday() {
            if (paymentDateInput) {
                paymentDateInput.value = currentDate;
            }
        }


        setDateToday();

        function enableAmountInput() {
            if (!amountPaidInput) {
                return;
            }
            amountPaidInput.removeAttribute('disabled');
            amountPaidInput.removeAttribute('readonly');
            amountPaidInput.style.pointerEvents = 'auto';
            amountPaidInput.style.zIndex = '1';
        }

        function updateAmountAndBorrowerFromLoanSelection() {
            if (!loanSelect) {
                return;
            }
            var selectedOption = loanSelect.options[loanSelect.selectedIndex];
            var collectAmount = selectedOption?.dataset?.collectAmount;
            var borrowerName = selectedOption?.dataset?.borrowerName;
            var borrowerLabel = document.getElementById('borrower_label');

            if (borrowerLabel && borrowerName) {
                borrowerLabel.style.display = 'block';
                borrowerLabel.textContent = borrowerName;
            } else if (borrowerLabel) {
                borrowerLabel.style.display = 'none';
                borrowerLabel.textContent = '';
            }

            if (collectAmount && amountPaidInput && (amountPaidInput.value === '' || amountPaidInput.value === '0')) {
                amountPaidInput.value = parseFloat(collectAmount).toFixed(2);
            }
        }

        function applyUrlPrefill() {
            try {
                var params = new URLSearchParams(window.location.search);
                var loanParam = params.get('loan');
                var amountParam = params.get('amount');
                var borrowerParam = params.get('borrower');

                if (loanParam && loanSelect) {
                    // attempt to set select value and trigger change
                    loanSelect.value = loanParam;
                }

                // Enable and update fields based on selection
                if (loanSelect && loanSelect.value) {
                    enableAmountInput();
                    updateAmountAndBorrowerFromLoanSelection();
                }

                if (amountParam && amountPaidInput && (amountPaidInput.value === '' || amountPaidInput.value === '0')) {
                    amountPaidInput.value = parseFloat(amountParam).toFixed(2);
                }

                if (borrowerParam) {
                    var bl = document.getElementById('borrower_label');
                    if (bl) {
                        bl.style.display = 'block';
                        bl.textContent = decodeURIComponent(borrowerParam);
                    }
                }
            } catch (e) {
                // ignore malformed URL params
                console.error(e && e.message);
            }
        }

        if (loanSelect) {
            loanSelect.addEventListener('change', function () {
                enableAmountInput();
                updateAmountAndBorrowerFromLoanSelection();
            });
            // Apply URL-driven prefill on load
            applyUrlPrefill();
        }

        if (paymentMethodSelect) {
            // no reference number behavior required
        }

        if (form) {
            form.addEventListener('submit', function (event) {
                if (!loanSelect.value) {
                    loanSelect.setCustomValidity('Please select a loan.');
                } else {
                    loanSelect.setCustomValidity('');
                }

                if (amountPaidInput) {
                    var enteredPayment = parseFloat(amountPaidInput.value);

                    if (isNaN(enteredPayment) || enteredPayment <= 0) {
                        amountPaidInput.setCustomValidity('Please enter a valid payment amount.');
                    } else {
                        amountPaidInput.setCustomValidity('');
                    }
                }

                if (!paymentMethodSelect.value) {
                    paymentMethodSelect.setCustomValidity('Please select a payment method.');
                } else {
                    paymentMethodSelect.setCustomValidity('');
                }


                if (paymentDateInput && !paymentDateInput.value) {
                    paymentDateInput.setCustomValidity('Payment date is required.');
                } else if (paymentDateInput) {
                    paymentDateInput.setCustomValidity('');
                }

                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }

                form.classList.add('was-validated');
            }, false);
        }
    })()
    </script>
</body>
</html>
