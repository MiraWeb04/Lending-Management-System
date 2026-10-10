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
require_once 'includes/loan_helpers.php';
require_once 'includes/invoice_helpers.php';
require_once 'includes/ui_helpers.php';
require_once 'includes/report_export_helpers.php';

// Allow borrowers to view a receipt directly via ?view=<id>
// but otherwise keep them redirected away from the full payments management UI.
if (isBorrower()) {
    if (!(isset($_GET['view']) && is_numeric($_GET['view']))) {
        header('Location: borrower_payment_history_lending.php');
        exit;
    }
} else {
    requireStaff();
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
               l.date_released,
               l.payment_frequency,
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

    if ($loan) {
        $balanceSummary = calculateLoanBalanceSummary((int)$loan['loan_id']);
        $loan = array_merge($loan, $balanceSummary);
        $loan['suggested_payment_received'] = calculateSuggestedPaymentReceived((int)$loan['loan_id'], $balanceSummary);
        $loan['penalty_rate_percent'] = getPenaltyRatePercentForFrequency(
            resolvePaymentFrequency($loan['payment_frequency'] ?? null, null, $loan['date_released'] ?? null, $loan['due_date'] ?? null)
        );
    }

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
    ];
    if (!empty($receiptData['remarks'])) {
        $lines[] = 'Remarks: ' . $receiptData['remarks'];
    }
    $lines[] = '';
    $lines[] = 'Status: PAID';

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
    } elseif (isCollector() && $viewPayment && !ensureCollectorCanAccessPayment($viewId, (int)($user['user_id'] ?? 0))) {
        http_response_code(403);
        echo 'You do not have access to this receipt.';
        exit;
    }

    if ($viewPayment && isset($_GET['download']) && $_GET['download'] == '1') {
        $paidQuery = "SELECT COALESCE(SUM(amount_paid), 0) AS total_paid FROM payments WHERE loan_id = ?";
        $paidResult = executeQuery($paidQuery, [(int)($viewPayment['loan_id'] ?? 0)]);
        $totalPaidToDate = (float)($paidResult->fetch()['total_paid'] ?? 0);

        $loanOutstanding = max(0, (float)($viewPayment['total_payable'] ?? 0) - $totalPaidToDate);
        $interestPaidValue = (float)($viewPayment['interest_paid'] ?? 0);
        $penaltyPaidValue = (float)($viewPayment['penalty_paid'] ?? 0);
        $totalReceived = (float)($viewPayment['amount_paid'] ?? 0) + $penaltyPaidValue;
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
            'interest_paid' => '₱' . number_format($interestPaidValue, 2),
            'penalty_paid' => '₱' . number_format($penaltyPaidValue, 2),
            'total_payment_received' => '₱' . number_format($totalReceived, 2),
            'remarks' => trim((string)($viewPayment['remarks'] ?? '')),
        ];
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="receipt-' . ($viewPayment['payment_id'] ?? '0') . '.pdf"');
        echo buildReceiptPdf($receiptData);
        exit;
    }
}

$invoicePreviousBalance = 0.0;
$invoiceRemainingBalance = 0.0;
$invoiceAmountPaid = 0.0;
$invoiceInterestPaid = 0.0;
$invoicePenaltyPaid = 0.0;
$invoiceTotalReceived = 0.0;
if (!empty($viewPayment)) {
    $invoicePaidResult = executeQuery(
        'SELECT COALESCE(SUM(amount_paid), 0) AS total_paid FROM payments WHERE loan_id = ?',
        [(int)($viewPayment['loan_id'] ?? 0)]
    );
    $invoiceTotalPaidToDate = (float)($invoicePaidResult->fetch()['total_paid'] ?? 0);
    $invoiceTotalPayable = (float)($viewPayment['total_payable'] ?? 0);
    $invoiceAmountPaid = (float)($viewPayment['amount_paid'] ?? 0);
    $invoiceInterestPaid = (float)($viewPayment['interest_paid'] ?? 0);
    $invoicePenaltyPaid = (float)($viewPayment['penalty_paid'] ?? 0);
    $invoicePreviousBalance = max(0, $invoiceTotalPayable - $invoiceTotalPaidToDate + $invoiceAmountPaid);
    $invoiceRemainingBalance = max(0, $invoiceTotalPayable - $invoiceTotalPaidToDate);
    $invoiceTotalReceived = $invoiceAmountPaid + $invoicePenaltyPaid;
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
$periodMode = trim((string)($_GET['period_mode'] ?? 'monthly'));
$periodResolved = resolveReportPeriodRange($periodMode, $_GET);
$periodMode = $periodResolved['period_mode'];
$startDate = $periodResolved['start_date'];
$endDate = $periodResolved['end_date'];
$reportDate = $periodResolved['report_date'];
$reportMonth = $periodResolved['report_month'];
$reportYear = $periodResolved['report_year'];
$periodLabel = $periodResolved['period_label'];
$periodModeLabels = lendingPeriodModeLabels();
$yearOptions = lendingYearOptions();
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
               l.date_released, l.payment_frequency,
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
               l.date_released, l.payment_frequency,
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
foreach ($loans as &$loanRow) {
    $loanRow['balance_summary'] = calculateLoanBalanceSummary((int)$loanRow['loan_id']);
    $loanRow['suggested_payment_received'] = calculateSuggestedPaymentReceived(
        (int)$loanRow['loan_id'],
        $loanRow['balance_summary']
    );
    $loanRow['penalty_rate_percent'] = getPenaltyRatePercentForFrequency(
        resolvePaymentFrequency(
            $loanRow['payment_frequency'] ?? null,
            null,
            $loanRow['date_released'] ?? null,
            $loanRow['due_date'] ?? null
        )
    );
}
unset($loanRow);

$selectedLoanSummary = null;
$penaltyPaidValue = isset($_POST['penalty_paid']) ? (string)$_POST['penalty_paid'] : '0';

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

    if (count($loans) === 1 && !$selectedLoanId) {
        $selectedLoanId = (int)$loans[0]['loan_id'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequireValid();

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
        $penaltyPaid = isset($_POST['penalty_paid']) ? (float)$_POST['penalty_paid'] : 0;
        $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
        $collectorName = isCollector()
            ? trim((string)($user['full_name'] ?? 'Unknown'))
            : trim($_POST['collector_name'] ?? ($user['full_name'] ?? 'Unknown'));
        $paymentMethod = trim($_POST['payment_method'] ?? '');
        $remarksValue = trim($_POST['remarks'] ?? '');
        $amountPaidValue = $_POST['amount_paid'] ?? '';
        $penaltyPaidValue = isset($_POST['penalty_paid']) ? (string)$_POST['penalty_paid'] : '0';
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
        } elseif (isCollector() && !ensureCollectorCanAccessLoan($loanId, (int)($user['user_id'] ?? 0))) {
            $message = 'You are not assigned to this loan.';
            $messageType = 'danger';
        } else {
            $balanceSummary = calculateLoanBalanceSummary($loanId);
            $remainingBalance = (float)($balanceSummary['remaining_balance'] ?? 0);
            $penaltyDue = (float)($balanceSummary['penalty_due'] ?? 0);
            $maxTotalPayment = (float)($balanceSummary['total_amount_due'] ?? $remainingBalance);

            $effectivePenalty = min($penaltyPaid, $penaltyDue);
            if ($paymentAmount <= 0) {
                $message = 'Payment amount must be greater than zero.';
                $messageType = 'danger';
            } elseif ($penaltyPaid > $paymentAmount + 0.009) {
                $message = 'Penalty portion cannot exceed total payment received.';
                $messageType = 'danger';
            } elseif ($paymentAmount > $maxTotalPayment + 0.009) {
                $message = 'Payment amount cannot exceed the total amount due of ₱' . number_format($maxTotalPayment, 2) . ' (balance + penalties).';
                $messageType = 'danger';
            } elseif (($paymentAmount - $effectivePenalty) > $remainingBalance + 0.009) {
                $message = 'Installment portion cannot exceed the remaining loan balance of ₱' . number_format($remainingBalance, 2) . '.';
                $messageType = 'danger';
            } else {
                try {
                    $collectorId = isCollector() ? (int)$user['user_id'] : null;
                    $clientId = (int)($loan['client_id'] ?? 0);
                    recordPaymentTransaction($loanId, $paymentDate, $paymentAmount, $collectorName, $collectorId, $clientId, $paymentMethod, null, $penaltyPaid, $remarksValue);

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
        if (!isAdmin()) {
            http_response_code(403);
            echo 'Only administrators can delete payments.';
            exit;
        }

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

if ($addPayment && $selectedLoanId) {
    foreach ($loans as $loanRow) {
        if ((int)$loanRow['loan_id'] === (int)$selectedLoanId) {
            $selectedLoanSummary = $loanRow['balance_summary'] ?? calculateLoanBalanceSummary((int)$selectedLoanId);
            break;
        }
    }
    if ($selectedLoanSummary && !isset($_POST['add_payment'])) {
        if ($amountPaidValue === '' || $amountPaidValue === '0') {
            $suggested = calculateSuggestedPaymentReceived((int)$selectedLoanId, $selectedLoanSummary);
            $amountPaidValue = number_format($suggested, 2, '.', '');
        }
        if (!isset($_POST['penalty_paid'])) {
            $penaltyPaidValue = number_format((float)($selectedLoanSummary['penalty_due'] ?? 0), 2, '.', '');
        }
    }
}

// Handle search and filtering
if (isset($_GET['search'])) {
    $searchTerm = trim($_GET['search']);
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

$paymentsQuery .= " AND DATE(p.payment_date) BETWEEN ? AND ?";
$queryParams[] = $startDate;
$queryParams[] = $endDate;

$paymentsQuery .= " ORDER BY p.payment_date DESC, p.payment_id DESC";

// Execute the query
$paymentsResult = executeQuery($paymentsQuery, $queryParams);
$payments = $paymentsResult->fetchAll();

$periodStatsQuery = "SELECT COALESCE(SUM(p.amount_paid), 0) AS total, COUNT(*) AS payment_count FROM payments p JOIN loans l ON p.loan_id = l.loan_id JOIN clients c ON l.client_id = c.client_id WHERE DATE(p.payment_date) BETWEEN ? AND ?";
$periodStatsParams = [$startDate, $endDate];
if (isCollector()) {
    $periodStatsQuery .= " AND c.collector_id = ?";
    $periodStatsParams[] = $collectorUserId;
}
$periodStatsRow = executeQuery($periodStatsQuery, $periodStatsParams)->fetch() ?: [];
$periodCollection = (float)($periodStatsRow['total'] ?? 0);
$periodPaymentCount = (int)($periodStatsRow['payment_count'] ?? 0);

// Get total collection for all time
$totalCollectionQuery = "SELECT SUM(p.amount_paid) as total FROM payments p JOIN loans l ON p.loan_id = l.loan_id JOIN clients c ON l.client_id = c.client_id";
$totalCollectionParams = [];
if (isCollector()) {
    $totalCollectionQuery .= " WHERE c.collector_id = ?";
    $totalCollectionParams[] = $collectorUserId;
}
$totalCollectionResult = executeQuery($totalCollectionQuery, $totalCollectionParams);
$totalCollection = $totalCollectionResult->fetch()['total'] ?? 0;

$formattedPeriodCollection = number_format($periodCollection, 2);
$formattedTotalCollection = number_format($totalCollection, 2);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/lending_assets.php'; lendingRenderPwaMeta(); ?>
    <title>Payments Management - Lending Management System</title>
    <!-- Bootstrap CSS -->
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/lending_styles.css">
    <link rel="stylesheet" href="css/admin_pages.css">
    <link rel="stylesheet" href="css/pwa.css">
</head>
<body>
    <?php include 'includes/nav_lending.php'; ?>

    <!-- Main Content -->
    <div class="app-content py-2">
        <?php if ($viewPayment): ?>
        <?php
        $borrowerDisplayName = trim(($viewPayment['first_name'] ?? '') . ' ' . ($viewPayment['last_name'] ?? ''));
        $receiptNumberDisplay = $viewPayment['receipt_number'] ?? 'RJRR-000000';
        $paymentMethodDisplay = $viewPayment['payment_method'] ?? 'Cash';
        $paymentDateLong = date('F d, Y', strtotime($viewPayment['payment_date']));
        $paymentDateTime = date('M d, Y · g:i A', strtotime($viewPayment['payment_date']));
        $backPaymentsUrl = isBorrower() ? 'borrower_payment_history_lending.php' : 'payments_lending.php';
        ?>
        <div class="invoice-page mb-4">
            <div class="invoice-toolbar no-print">
                <div>
                    <h1 class="invoice-toolbar__title"><i class="fas fa-file-invoice me-2 text-primary"></i>Payment Invoice</h1>
                    <nav aria-label="breadcrumb" class="mt-1">
                        <ol class="breadcrumb mb-0 small">
                            <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars($backPaymentsUrl, ENT_QUOTES, 'UTF-8'); ?>">Payments</a></li>
                            <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($receiptNumberDisplay, ENT_QUOTES, 'UTF-8'); ?></li>
                        </ol>
                    </nav>
                </div>
                <div class="invoice-toolbar__actions">
                    <a href="<?php echo htmlspecialchars($backPaymentsUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-arrow-left me-1"></i> Back
                    </a>
                    <button type="button" class="btn btn-primary btn-sm" onclick="(typeof printReceipt === 'function' ? printReceipt() : window.print())">
                        <i class="fas fa-print me-1"></i> Print
                    </button>
                    <?php if (isCollector() || isAdmin()): ?>
                    <a href="payments_lending.php?view=<?php echo (int)$viewPayment['payment_id']; ?>&download=1" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-download me-1"></i> Download PDF
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="receipt-paper">
                <article class="invoice-document receipt-shell" aria-label="Official payment invoice">
                    <div class="invoice-document__accent" aria-hidden="true"></div>
                    <div class="invoice-document__body">
                        <?php
                        echo renderInvoiceDocumentHeader(
                            'Payment Receipt',
                            $receiptNumberDisplay,
                            'Issued ' . $paymentDateTime
                        );
                        ?>

                        <div class="invoice-parties">
                            <div class="invoice-party">
                                <p class="invoice-party__label">Bill to</p>
                                <p class="invoice-party__name"><?php echo htmlspecialchars($borrowerDisplayName, ENT_QUOTES, 'UTF-8'); ?></p>
                                <dl>
                                    <dt>Client ID</dt>
                                    <dd><?php echo htmlspecialchars((string)($viewPayment['client_id'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?></dd>
                                    <dt>Loan account</dt>
                                    <dd>#<?php echo htmlspecialchars((string)($viewPayment['loan_id'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?></dd>
                                    <?php if (!empty($viewPayment['contact'])): ?>
                                    <dt>Contact</dt>
                                    <dd><?php echo htmlspecialchars((string)$viewPayment['contact'], ENT_QUOTES, 'UTF-8'); ?></dd>
                                    <?php endif; ?>
                                </dl>
                            </div>
                            <div class="invoice-party">
                                <p class="invoice-party__label">Payment details</p>
                                <p class="invoice-party__name"><?php echo htmlspecialchars($paymentMethodDisplay, ENT_QUOTES, 'UTF-8'); ?></p>
                                <dl>
                                    <dt>Payment date</dt>
                                    <dd><?php echo htmlspecialchars($paymentDateLong, ENT_QUOTES, 'UTF-8'); ?></dd>
                                    <dt>Recorded by</dt>
                                    <dd><?php echo htmlspecialchars((string)($viewPayment['collector_name'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8'); ?></dd>
                                    <dt>Loan status</dt>
                                    <dd><?php echo htmlspecialchars((string)($viewPayment['status'] ?? 'Active'), ENT_QUOTES, 'UTF-8'); ?></dd>
                                </dl>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="invoice-line-items receipt-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Description</th>
                                        <th scope="col">Amount (PHP)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>
                                            Balance before this payment
                                            <span class="invoice-line-items__desc-muted">Outstanding on loan prior to transaction</span>
                                        </td>
                                        <td><?php echo invoiceMoney($invoicePreviousBalance); ?></td>
                                    </tr>
                                    <tr>
                                        <td>
                                            Principal / installment payment
                                            <span class="invoice-line-items__desc-muted">Amount applied to loan balance</span>
                                        </td>
                                        <td><?php echo invoiceMoney($invoiceAmountPaid); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Interest portion</td>
                                        <td><?php echo invoiceMoney($invoiceInterestPaid); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Penalty / late fees</td>
                                        <td><?php echo invoiceMoney($invoicePenaltyPaid); ?></td>
                                    </tr>
                                    <tr class="invoice-line-items__total">
                                        <td>Remaining balance after payment</td>
                                        <td><?php echo invoiceMoney($invoiceRemainingBalance); ?></td>
                                    </tr>
                                    <tr class="invoice-line-items__grand">
                                        <td>Total received this transaction</td>
                                        <td><?php echo invoiceMoney($invoiceTotalReceived); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="invoice-bottom">
                            <div class="invoice-notes">
                                <p><span class="invoice-status-chip"><i class="fas fa-check-circle" aria-hidden="true"></i> Payment recorded</span></p>
                                <p>This document serves as official proof of payment for the loan account listed above. Please retain for your records. For questions, contact <?php echo htmlspecialchars(invoiceBrand()['phone'], ENT_QUOTES, 'UTF-8'); ?>.</p>
                                <?php if (!empty($viewPayment['reference_number'])): ?>
                                <p><strong>Reference:</strong> <?php echo htmlspecialchars((string)$viewPayment['reference_number'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($viewPayment['remarks'])): ?>
                                <p><strong>Remarks:</strong> <?php echo htmlspecialchars((string)$viewPayment['remarks'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="invoice-totals">
                                <div class="invoice-totals__row">
                                    <span>Previous balance</span>
                                    <span><?php echo invoiceMoney($invoicePreviousBalance); ?></span>
                                </div>
                                <div class="invoice-totals__row invoice-totals__row--emphasis">
                                    <span>Amount paid</span>
                                    <span><?php echo invoiceMoney($invoiceAmountPaid); ?></span>
                                </div>
                                <div class="invoice-totals__row">
                                    <span>Penalties</span>
                                    <span><?php echo invoiceMoney($invoicePenaltyPaid); ?></span>
                                </div>
                                <div class="invoice-totals__row invoice-totals__row--grand">
                                    <span>Total received</span>
                                    <span><?php echo invoiceMoney($invoiceTotalReceived); ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="invoice-signatures">
                            <div class="invoice-signature">
                                <div class="invoice-signature__line"></div>
                                <p class="invoice-signature__label">Borrower</p>
                            </div>
                            <div class="invoice-signature">
                                <div class="invoice-signature__line"></div>
                                <p class="invoice-signature__label">Collector</p>
                            </div>
                            <div class="invoice-signature">
                                <div class="invoice-signature__line"></div>
                                <p class="invoice-signature__label">Authorized signatory</p>
                            </div>
                        </div>

                        <footer class="invoice-footer">
                            <strong><?php echo htmlspecialchars(invoiceBrand()['legal_name'], ENT_QUOTES, 'UTF-8'); ?></strong> · Computer-generated invoice · <?php echo htmlspecialchars($receiptNumberDisplay, ENT_QUOTES, 'UTF-8'); ?>
                        </footer>
                    </div>
                </article>
            </div>
        </div>

        <?php elseif ($addPayment): ?>
        <?php
        echo renderAdminDashboardHeader(
            'Record Payment',
            'Apply a collection to an active loan account and issue an official receipt.',
            'fa-hand-holding-dollar',
            'Payments',
            '<a href="payments_lending.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back to Payments</a>'
        );
        ?>

        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <?php if (count($loans) > 0): ?>
        <div class="record-payment-shell">
            <form action="payments_lending.php" method="post" class="needs-validation record-payment-form" id="payment-record-form" novalidate>
                <?php echo csrfField(); ?>
                <input type="hidden" name="add_payment" value="1">

                <div class="row g-4">
                    <div class="col-lg-8">
                        <section class="record-payment-section">
                            <header class="record-payment-section__head">
                                <span class="record-payment-section__icon"><i class="fas fa-file-invoice-dollar"></i></span>
                                <div>
                                    <h2 class="record-payment-section__title">Loan account</h2>
                                    <p class="record-payment-section__desc">Choose the borrower loan you are collecting for</p>
                                </div>
                            </header>
                            <div class="record-payment-section__body">
                                <label for="loan_id" class="form-label">Select loan / borrower <span class="record-payment-required">*</span></label>
                                <select class="form-select form-select-lg rounded-3" id="loan_id" name="loan_id" required>
                                    <option value="">Select loan / borrower</option>
                                    <?php foreach ($loans as $loan):
                                        $loanBal = $loan['balance_summary'] ?? [];
                                        $loanPenaltyDue = (float)($loanBal['penalty_due'] ?? 0);
                                        $loanRemaining = (float)($loanBal['remaining_balance'] ?? max(0, ($loan['total_payable'] ?? 0) - ($loan['amount_paid'] ?? 0)));
                                        $loanTotalDue = (float)($loanBal['total_amount_due'] ?? $loanRemaining);
                                        $loanSuggestedPayment = (float)($loan['suggested_payment_received'] ?? $loanTotalDue);
                                        $loanPenaltyRate = (float)($loan['penalty_rate_percent'] ?? 0);
                                    ?>
                                    <option value="<?php echo $loan['loan_id']; ?>"
                                        data-loan-number="<?php echo htmlspecialchars($loan['loan_number'] ?? $loan['loan_id']); ?>"
                                        data-interest="<?php echo htmlspecialchars((float)($loan['interest'] ?? 0)); ?>"
                                        data-loan-amount="<?php echo htmlspecialchars(number_format((float)($loan['loan_amount'] ?? 0), 2, '.', '')); ?>"
                                        data-total-payable="<?php echo htmlspecialchars(number_format((float)($loan['total_payable'] ?? 0), 2, '.', '')); ?>"
                                        data-collect-amount="<?php echo htmlspecialchars(number_format($loanSuggestedPayment, 2, '.', '')); ?>"
                                        data-suggested-payment="<?php echo htmlspecialchars(number_format($loanSuggestedPayment, 2, '.', '')); ?>"
                                        data-borrower-name="<?php echo htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']); ?>"
                                        data-amount-paid="<?php echo htmlspecialchars(number_format((float)($loan['amount_paid'] ?? 0), 2, '.', '')); ?>"
                                        data-remaining-balance="<?php echo htmlspecialchars(number_format($loanRemaining, 2, '.', '')); ?>"
                                        data-penalty-due="<?php echo htmlspecialchars(number_format($loanPenaltyDue, 2, '.', '')); ?>"
                                        data-total-amount-due="<?php echo htmlspecialchars(number_format($loanTotalDue, 2, '.', '')); ?>"
                                        data-penalty-rate="<?php echo htmlspecialchars(number_format($loanPenaltyRate, 2, '.', '')); ?>"
                                        data-due-date="<?php echo htmlspecialchars($loan['due_date']); ?>"
                                        data-status="<?php echo htmlspecialchars($loan['status']); ?>"
                                        <?php echo ($selectedLoanId == $loan['loan_id']) ? 'selected' : ''; ?>>
                                        Loan #<?php echo htmlspecialchars($loan['loan_number'] ?? $loan['loan_id']); ?> — <?php echo htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']); ?> (₱<?php echo number_format($loan['loan_amount'], 2); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="record-payment-borrower-chip mt-2" id="borrower_label" style="display:none;" aria-live="polite"></div>
                                <div class="invalid-feedback">Please select a loan.</div>
                            </div>
                        </section>

                        <section class="record-payment-section">
                            <header class="record-payment-section__head">
                                <span class="record-payment-section__icon"><i class="fas fa-coins"></i></span>
                                <div>
                                    <h2 class="record-payment-section__title">Payment amounts</h2>
                                    <p class="record-payment-section__desc">Totals prefilled from installment or overdue balance — adjust if needed</p>
                                </div>
                            </header>
                            <div class="record-payment-section__body row g-3">
                                <div class="col-md-6">
                                    <label for="amount_paid" class="form-label">Total payment received <span class="record-payment-required">*</span></label>
                                    <div class="input-group input-group-lg has-validation">
                                        <span class="input-group-text rounded-start-3">₱</span>
                                        <input type="number" class="form-control rounded-end-3" id="amount_paid" name="amount_paid" min="0.01" step="0.01" value="<?php echo htmlspecialchars($amountPaidValue); ?>" required>
                                        <div class="invalid-feedback">Please enter a valid payment amount.</div>
                                    </div>
                                    <div class="form-text">Prefilled with <strong>one installment</strong> when current, or <strong>balance plus penalties</strong> when overdue.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="penalty_paid" class="form-label">Penalty portion</label>
                                    <div class="input-group input-group-lg">
                                        <span class="input-group-text rounded-start-3">₱</span>
                                        <input type="number" class="form-control rounded-end-3" id="penalty_paid" name="penalty_paid" min="0" step="0.01" value="<?php echo htmlspecialchars($penaltyPaidValue); ?>">
                                    </div>
                                    <?php
                                    $hintPenaltyDue = $selectedLoanSummary ? (float)($selectedLoanSummary['penalty_due'] ?? 0) : 0;
                                    ?>
                                    <div class="form-text" id="penalty_due_hint">Overdue penalty due: <?php echo '₱' . number_format($hintPenaltyDue, 2); ?>. Prefilled when the loan is overdue; you may adjust it or raise the total payment if the borrower pays more.</div>
                                </div>
                            </div>
                        </section>

                        <section class="record-payment-section">
                            <header class="record-payment-section__head">
                                <span class="record-payment-section__icon"><i class="fas fa-receipt"></i></span>
                                <div>
                                    <h2 class="record-payment-section__title">Collection details</h2>
                                    <p class="record-payment-section__desc">Method, date, and collector on this transaction</p>
                                </div>
                            </header>
                            <div class="record-payment-section__body row g-3">
                                <div class="col-md-6">
                                    <label for="payment_method" class="form-label">Payment method <span class="record-payment-required">*</span></label>
                                    <select class="form-select form-select-lg rounded-3" id="payment_method" name="payment_method" required>
                                        <option value="">Select method</option>
                                        <option value="Cash" <?php echo ($paymentMethodValue === 'Cash') ? 'selected' : ''; ?>>Cash</option>
                                        <option value="Bank Transfer" <?php echo ($paymentMethodValue === 'Bank Transfer') ? 'selected' : ''; ?>>Bank Transfer</option>
                                        <option value="E-Wallet" <?php echo ($paymentMethodValue === 'E-Wallet') ? 'selected' : ''; ?>>E-Wallet</option>
                                    </select>
                                    <div class="invalid-feedback">Please select a payment method.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="payment_date" class="form-label">Payment date <span class="record-payment-required">*</span></label>
                                    <input type="date" class="form-control form-control-lg rounded-3" id="payment_date" name="payment_date" value="<?php echo htmlspecialchars($paymentDate ?? date('Y-m-d')); ?>" required>
                                    <div class="invalid-feedback">Please select a payment date.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="collector_name" class="form-label">Collector name <span class="record-payment-required">*</span></label>
                                    <div class="input-group input-group-lg">
                                        <span class="input-group-text rounded-start-3"><i class="fas fa-user-check text-primary"></i></span>
                                        <input type="text" class="form-control rounded-end-3" id="collector_name" name="collector_name" value="<?php echo htmlspecialchars($collectorNameValue); ?>" required>
                                    </div>
                                    <div class="invalid-feedback">Please enter the collector's name.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="remarks" class="form-label">Remarks / notes</label>
                                    <textarea class="form-control rounded-3" id="remarks" name="remarks" rows="2" placeholder="Optional payment notes"><?php echo htmlspecialchars($remarksValue); ?></textarea>
                                </div>
                            </div>
                        </section>
                    </div>

                    <div class="col-lg-4">
                        <aside class="record-payment-aside" aria-label="Loan balance summary">
                            <div class="record-payment-summary record-payment-summary--empty" id="loan_balance_panel" style="<?php echo $selectedLoanSummary ? '' : 'display:none;'; ?>">
                                <div class="record-payment-summary__head">
                                    <i class="fas fa-chart-line"></i>
                                    <span>Loan snapshot</span>
                                </div>
                                <ul class="record-payment-summary__list">
                                    <li>
                                        <span>Remaining balance</span>
                                        <strong id="remaining_balance_label"><?php echo $selectedLoanSummary ? '₱' . number_format((float)($selectedLoanSummary['remaining_balance'] ?? 0), 2) : '₱0.00'; ?></strong>
                                    </li>
                                    <li>
                                        <span>Penalty due</span>
                                        <strong id="penalty_due_label"><?php echo $selectedLoanSummary ? '₱' . number_format((float)($selectedLoanSummary['penalty_due'] ?? 0), 2) : '₱0.00'; ?></strong>
                                    </li>
                                    <li class="record-payment-summary__highlight">
                                        <span>Total amount due</span>
                                        <strong id="total_due_label"><?php echo $selectedLoanSummary ? '₱' . number_format((float)($selectedLoanSummary['total_amount_due'] ?? 0), 2) : '₱0.00'; ?></strong>
                                    </li>
                                    <li>
                                        <span>Penalty rate</span>
                                        <strong id="penalty_rate_label"><?php
                                            if ($selectedLoanSummary && $selectedLoanId) {
                                                foreach ($loans as $loanRow) {
                                                    if ((int)$loanRow['loan_id'] === (int)$selectedLoanId) {
                                                        echo htmlspecialchars((string)($loanRow['penalty_rate_percent'] ?? 0)) . '%';
                                                        break;
                                                    }
                                                }
                                            } else {
                                                echo '0%';
                                            }
                                        ?></strong>
                                    </li>
                                </ul>
                            </div>
                            <p class="record-payment-aside__placeholder small text-muted mb-0" id="loan_balance_placeholder" style="<?php echo $selectedLoanSummary ? 'display:none;' : ''; ?>">Select a loan to view balance, penalties, and suggested collection amount.</p>
                        </aside>
                    </div>
                </div>

                <footer class="record-payment-actions">
                    <p class="record-payment-actions__note mb-0 d-none d-md-block"><span class="record-payment-required">*</span> Required fields</p>
                    <div class="record-payment-actions__buttons">
                        <a href="payments_lending.php" class="btn btn-light border">
                            <i class="fas fa-times me-1"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-primary btn-lg px-4 shadow-sm">
                            <i class="fas fa-save me-2"></i> Record Payment
                        </button>
                    </div>
                </footer>
            </form>
        </div>
        <?php else: ?>
        <div class="panel-card panel-card--flush">
            <div class="chart-panel__body">
                <div class="alert alert-warning mb-0">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    There are no active loans in the system. You need to <a href="loans_lending.php?add=true">create a loan</a> before recording payments.
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <!-- Payments List -->
        <?php
        echo renderAdminDashboardHeader(
            'Payments Management',
            'Monitor collections and review official receipts with a professional finance workflow.',
            'fa-cash-register',
            'Payments',
            '<a href="payments_lending.php?add=true" class="btn btn-primary"><i class="fas fa-plus me-1"></i> Record Payment</a>'
        );
        ?>

        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <?php
        $periodFilterFormAction = 'payments_lending.php';
        $periodFilterFormId = 'payments-period-filter-form';
        $periodFilterHidden = array_filter(['search' => $searchTerm], fn($v) => $v !== '' && $v !== null);
        include 'includes/lending_period_filter.php';
        ?>

        <div class="kpi-grid mb-4">
            <div class="kpi-card kpi-card--success">
                <div class="kpi-card__top"><div class="kpi-card__label">Collected (period)</div><div class="kpi-card__icon"><i class="fas fa-hand-holding-dollar"></i></div></div>
                <div class="kpi-card__value">₱<?php echo $formattedPeriodCollection; ?></div>
                <div class="kpi-card__meta"><?php echo htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div class="kpi-card kpi-card--primary">
                <div class="kpi-card__top"><div class="kpi-card__label">Payments (period)</div><div class="kpi-card__icon"><i class="fas fa-receipt"></i></div></div>
                <div class="kpi-card__value"><?php echo number_format($periodPaymentCount); ?></div>
                <div class="kpi-card__meta">Transactions in range</div>
            </div>
            <div class="kpi-card kpi-card--info">
                <div class="kpi-card__top"><div class="kpi-card__label">All-time collection</div><div class="kpi-card__icon"><i class="fas fa-money-bill-wave"></i></div></div>
                <div class="kpi-card__value">₱<?php echo $formattedTotalCollection; ?></div>
                <div class="kpi-card__meta">Lifetime recorded payments</div>
            </div>
        </div>

        <div class="panel-card panel-card--flush mb-4">
            <div class="chart-panel__header">
                <h6 class="m-0 fw-bold"><i class="fas fa-search me-2 text-primary"></i>Search payments</h6>
            </div>
            <div class="chart-panel__body pt-0">
                <form action="payments_lending.php" method="get" class="row g-3 align-items-end">
                    <input type="hidden" name="period_mode" value="<?php echo htmlspecialchars($periodMode, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="report_date" value="<?php echo htmlspecialchars($reportDate, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="report_month" value="<?php echo htmlspecialchars($reportMonth, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="report_year" value="<?php echo (int)$reportYear; ?>">
                    <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="col-lg-10">
                        <label class="form-label small text-muted mb-2">Client, collector, or payment ID</label>
                        <div class="input-group modern-input-group">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control" name="search" placeholder="Search by client name or collector" value="<?php echo htmlspecialchars($searchTerm); ?>">
                            <button class="btn btn-primary" type="submit"><i class="fas fa-search me-1"></i> Search</button>
                        </div>
                    </div>
                    <div class="col-lg-2">
                        <a href="payments_lending.php" class="btn btn-outline-secondary w-100 modern-reset-btn">Reset all</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="panel-card panel-card--flush mb-4">
            <div class="chart-panel__header">
                <h6 class="m-0 fw-bold"><i class="fas fa-list me-2 text-primary"></i>Payments list</h6>
            </div>
            <div class="chart-panel__body pt-0">
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
                                <th>Method</th>
                                <th>Reference No.</th>
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
                                <td><?php echo htmlspecialchars((string)($payment['payment_method'] ?? 'Cash')); ?></td>
                                <td><?php echo htmlspecialchars(lendingPaymentReferenceLabel($payment['payment_method'] ?? '', $payment['reference_number'] ?? '')); ?></td>
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
                <?php
                echo renderEmptyState(
                    'fa-search',
                    'No payments found',
                    !empty($searchTerm)
                        ? 'Try adjusting your search or period filter.'
                        : 'Record a payment to see it listed for the selected period.'
                );
                if (!empty($searchTerm)) {
                    echo '<div class="text-center pb-4"><a href="payments_lending.php" class="btn btn-outline-secondary"><i class="fas fa-redo me-1"></i> Reset filters</a></div>';
                } else {
                    echo '<div class="text-center pb-4"><a href="payments_lending.php?add=true" class="btn btn-primary"><i class="fas fa-plus me-1"></i> Record payment</a></div>';
                }
                ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

<?php include 'includes/nav_footer_lending.php'; ?>
    <script>
    (function () {
        'use strict';

        function initPaymentRecordForm() {
            var loanSelect = document.getElementById('loan_id');
            var form = document.getElementById('payment-record-form');
            if (!form || !loanSelect) {
                return;
            }

            var amountPaidInput = document.getElementById('amount_paid');
            var penaltyPaidInput = document.getElementById('penalty_paid');
            var paymentDateInput = document.getElementById('payment_date');
            var paymentMethodSelect = document.getElementById('payment_method');
            var collectorNameInput = document.getElementById('collector_name');
            var currentDate = '<?php echo date('Y-m-d'); ?>';
            var userEditedAmount = amountPaidInput && amountPaidInput.value !== '' && amountPaidInput.value !== '0';
            var userEditedPenalty = penaltyPaidInput && penaltyPaidInput.value !== '' && penaltyPaidInput.value !== '0';

            if (paymentDateInput && !paymentDateInput.value) {
                paymentDateInput.value = currentDate;
            }

            function formatCurrency(value) {
                var amount = parseFloat(value || 0);
                return '₱' + amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            function loanSnapshotFromOption(option) {
                if (!option || !option.value) {
                    return null;
                }
                return {
                    remaining_balance: parseFloat(option.dataset.remainingBalance || 0),
                    penalty_due: parseFloat(option.dataset.penaltyDue || 0),
                    total_amount_due: parseFloat(option.dataset.totalAmountDue || 0),
                    suggested_payment_received: parseFloat(option.dataset.suggestedPayment || option.dataset.collectAmount || 0),
                    penalty_rate_percent: parseFloat(option.dataset.penaltyRate || 0)
                };
            }

            function updateLoanBalancePanel(loan) {
                var panel = document.getElementById('loan_balance_panel');
                var placeholder = document.getElementById('loan_balance_placeholder');
                if (!panel || !loan) {
                    if (panel) {
                        panel.style.display = 'none';
                    }
                    if (placeholder) {
                        placeholder.style.display = '';
                    }
                    return;
                }
                panel.style.display = 'block';
                if (placeholder) {
                    placeholder.style.display = 'none';
                }
                document.getElementById('remaining_balance_label').textContent = formatCurrency(loan.remaining_balance);
                document.getElementById('penalty_due_label').textContent = formatCurrency(loan.penalty_due);
                document.getElementById('total_due_label').textContent = formatCurrency(loan.total_amount_due);
                document.getElementById('penalty_rate_label').textContent = (loan.penalty_rate_percent || 0) + '%';
                var penaltyHint = document.getElementById('penalty_due_hint');
                if (penaltyHint) {
                    penaltyHint.textContent = 'Overdue penalty due: ' + formatCurrency(loan.penalty_due) + '. Prefilled when the loan is overdue; you may adjust it or raise the total payment if the borrower pays more.';
                }
            }

            function applyLoanSnapshot(loan, options) {
                options = options || {};
                if (!loan) {
                    updateLoanBalancePanel(null);
                    if (penaltyPaidInput && options.resetPenalty) {
                        penaltyPaidInput.value = '0.00';
                    }
                    return;
                }
                updateLoanBalancePanel(loan);
                var penaltyDue = parseFloat(loan.penalty_due || 0);
                if (penaltyPaidInput && (options.forcePenalty || !userEditedPenalty)) {
                    penaltyPaidInput.value = (penaltyDue > 0 ? penaltyDue : 0).toFixed(2);
                }
                var suggestedTotal = parseFloat(
                    loan.suggested_payment_received != null ? loan.suggested_payment_received : loan.total_amount_due || 0
                );
                if (amountPaidInput && (options.forceAmount || !userEditedAmount)) {
                    if (!isNaN(suggestedTotal) && suggestedTotal > 0) {
                        amountPaidInput.value = suggestedTotal.toFixed(2);
                    }
                }
            }

            function updateAmountAndBorrowerFromLoanSelection(options) {
                options = options || {};
                var selectedOption = loanSelect.options[loanSelect.selectedIndex];
                var borrowerName = selectedOption && selectedOption.dataset ? selectedOption.dataset.borrowerName : '';
                var borrowerLabel = document.getElementById('borrower_label');
                var loanId = loanSelect.value;

                if (borrowerLabel && borrowerName) {
                    borrowerLabel.style.display = 'block';
                    borrowerLabel.textContent = borrowerName;
                } else if (borrowerLabel) {
                    borrowerLabel.style.display = 'none';
                    borrowerLabel.textContent = '';
                }

                loanSelect.setCustomValidity(loanId ? '' : 'Please select a loan.');

                if (!loanId) {
                    applyLoanSnapshot(null, { resetPenalty: true });
                    return;
                }

                applyLoanSnapshot(loanSnapshotFromOption(selectedOption), {
                    forceAmount: !!options.forceAmount,
                    forcePenalty: !!options.forcePenalty
                });

                fetch('payments_lending.php?action=fetch_loan&loan_id=' + encodeURIComponent(loanId), {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('Loan lookup failed');
                        }
                        return response.json();
                    })
                    .then(function (payload) {
                        if (!payload.success || !payload.loan) {
                            return;
                        }
                        applyLoanSnapshot(payload.loan, {
                            forceAmount: !!options.forceAmount,
                            forcePenalty: !!options.forcePenalty
                        });
                    })
                    .catch(function () {
                        /* option data-* snapshot already applied */
                    });
            }

            function applyUrlPrefill() {
                try {
                    var params = new URLSearchParams(window.location.search);
                    var loanParam = params.get('loan');
                    var amountParam = params.get('amount');
                    var borrowerParam = params.get('borrower');

                    if (loanParam) {
                        loanSelect.value = loanParam;
                    }

                    if (loanSelect.value) {
                        updateAmountAndBorrowerFromLoanSelection({ forceAmount: !amountParam, forcePenalty: true });
                    }

                    if (amountParam && amountPaidInput) {
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
                    console.error(e && e.message);
                }
            }

            loanSelect.addEventListener('change', function () {
                userEditedAmount = false;
                userEditedPenalty = false;
                updateAmountAndBorrowerFromLoanSelection({ forceAmount: true, forcePenalty: true });
            });

            if (amountPaidInput) {
                amountPaidInput.addEventListener('input', function () {
                    userEditedAmount = true;
                });
            }
            if (penaltyPaidInput) {
                penaltyPaidInput.addEventListener('input', function () {
                    userEditedPenalty = true;
                });
            }

            applyUrlPrefill();
            if (loanSelect.value && !window.location.search.match(/[?&]loan=/)) {
                updateAmountAndBorrowerFromLoanSelection({ forcePenalty: false, forceAmount: false });
            }

            form.addEventListener('submit', function (event) {
                loanSelect.setCustomValidity(loanSelect.value ? '' : 'Please select a loan.');

                if (amountPaidInput) {
                    var enteredPayment = parseFloat(amountPaidInput.value);
                    amountPaidInput.setCustomValidity(
                        (isNaN(enteredPayment) || enteredPayment <= 0)
                            ? 'Please enter a valid payment amount.'
                            : ''
                    );
                }

                if (paymentMethodSelect) {
                    paymentMethodSelect.setCustomValidity(
                        paymentMethodSelect.value ? '' : 'Please select a payment method.'
                    );
                }

                if (paymentDateInput) {
                    paymentDateInput.setCustomValidity(
                        paymentDateInput.value ? '' : 'Payment date is required.'
                    );
                }

                if (collectorNameInput) {
                    var collectorName = (collectorNameInput.value || '').trim();
                    collectorNameInput.setCustomValidity(
                        collectorName ? '' : 'Please enter the collector\'s name.'
                    );
                }

                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }

                form.classList.add('was-validated');
            }, false);
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initPaymentRecordForm);
        } else {
            initPaymentRecordForm();
        }
    })();
    </script>
</body>
</html>
