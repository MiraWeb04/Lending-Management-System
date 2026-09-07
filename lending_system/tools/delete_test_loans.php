<?php
// delete_test_loans.php
// Safe cleanup tool to preview and delete test loans+payments (only for admin users).

require_once __DIR__ . '/../includes/auth_lending.php';
require_once __DIR__ . '/../includes/db_lending.php';

// Require login and admin
requireLogin();
$user = getCurrentUser();
if (!isAdmin()) {
    http_response_code(403);
    echo "<h2>Forbidden</h2><p>You must be an administrator to run this tool.</p>";
    exit;
}

$loanIds = [27,28,29,30];
$placeholders = implode(',', array_fill(0, count($loanIds), '?'));

// Preview queries
$loansSql = "SELECT loan_id, loan_number, client_id, loan_amount, total_payable, amount_to_collect, date_released, due_date, status FROM loans WHERE loan_id IN ($placeholders)";
$paymentsSql = "SELECT payment_id, loan_id, payment_date, amount_paid, collector_name, remarks FROM payments WHERE loan_id IN ($placeholders) ORDER BY payment_date DESC";

$loansStmt = executeQuery($loansSql, $loanIds);
$paymentsStmt = executeQuery($paymentsSql, $loanIds);
$loans = $loansStmt ? $loansStmt->fetchAll(PDO::FETCH_ASSOC) : [];
$payments = $paymentsStmt ? $paymentsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm']) && $_POST['confirm'] === '1') {
    try {
        global $conn;
        $conn->beginTransaction();

        // Delete payments
        $delPaymentsSql = "DELETE FROM payments WHERE loan_id IN ($placeholders)";
        $delPaymentsStmt = $conn->prepare($delPaymentsSql);
        foreach ($loanIds as $i => $id) { $delPaymentsStmt->bindValue($i+1, $id, PDO::PARAM_INT); }
        $delPaymentsStmt->execute();
        $paymentsDeleted = $delPaymentsStmt->rowCount();

        // Delete loans
        $delLoansSql = "DELETE FROM loans WHERE loan_id IN ($placeholders)";
        $delLoansStmt = $conn->prepare($delLoansSql);
        foreach ($loanIds as $i => $id) { $delLoansStmt->bindValue($i+1, $id, PDO::PARAM_INT); }
        $delLoansStmt->execute();
        $loansDeleted = $delLoansStmt->rowCount();

        $conn->commit();

        echo "<h2>Deletion complete</h2>";
        echo "<p>Payments deleted: " . (int)$paymentsDeleted . "</p>";
        echo "<p>Loans deleted: " . (int)$loansDeleted . "</p>";
        echo "<p><a href=\"../loans_lending.php\">Back to Loans</a></p>";
        exit;
    } catch (Throwable $e) {
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        echo "<h2>Error</h2><pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
        exit;
    }
}

// HTML preview
?><!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Delete Test Loans (Preview)</title>
    <link href="/lending_system/css/lending_styles.css" rel="stylesheet">
</head>
<body style="font-family:Segoe UI,Arial,sans-serif;padding:20px;">
<h1>Preview: Loans and Payments to be deleted</h1>
<p>Logged in as: <?php echo htmlspecialchars($user['full_name'] ?? 'Unknown'); ?></p>
<h2>Loans (IDs: <?php echo implode(',', $loanIds); ?>)</h2>
<?php if (count($loans) === 0): ?>
    <div style="color:#a00;">No matching loans found.</div>
<?php else: ?>
    <table border="1" cellpadding="6" cellspacing="0">
        <thead><tr><th>loan_id</th><th>loan_number</th><th>client_id</th><th>loan_amount</th><th>total_payable</th><th>amount_to_collect</th><th>date_released</th><th>due_date</th><th>status</th></tr></thead>
        <tbody>
            <?php foreach ($loans as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['loan_id']); ?></td>
                    <td><?php echo htmlspecialchars($row['loan_number'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['client_id']); ?></td>
                    <td><?php echo htmlspecialchars($row['loan_amount']); ?></td>
                    <td><?php echo htmlspecialchars($row['total_payable']); ?></td>
                    <td><?php echo htmlspecialchars($row['amount_to_collect']); ?></td>
                    <td><?php echo htmlspecialchars($row['date_released']); ?></td>
                    <td><?php echo htmlspecialchars($row['due_date']); ?></td>
                    <td><?php echo htmlspecialchars($row['status']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>Payments linked to these loans</h2>
<?php if (count($payments) === 0): ?>
    <div>No payments found for these loans.</div>
<?php else: ?>
    <table border="1" cellpadding="6" cellspacing="0">
        <thead><tr><th>payment_id</th><th>loan_id</th><th>payment_date</th><th>amount_paid</th><th>collector_name</th><th>remarks</th></tr></thead>
        <tbody>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td><?php echo htmlspecialchars($p['payment_id']); ?></td>
                    <td><?php echo htmlspecialchars($p['loan_id']); ?></td>
                    <td><?php echo htmlspecialchars($p['payment_date']); ?></td>
                    <td><?php echo htmlspecialchars($p['amount_paid']); ?></td>
                    <td><?php echo htmlspecialchars($p['collector_name']); ?></td>
                    <td><?php echo htmlspecialchars($p['remarks']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<form method="post" onsubmit="return confirm('This will permanently delete the listed payments and loans. Type YES to confirm: ' + (prompt('Type YES to confirm deletion') || ''));">
    <input type="hidden" name="confirm" value="1">
    <button type="submit" style="background:#c62828;color:#fff;padding:10px 16px;border:none;border-radius:6px;">Confirm and Delete</button>
    <a href="../loans_lending.php" style="margin-left:12px;">Cancel</a>
</form>

</body>
</html>
