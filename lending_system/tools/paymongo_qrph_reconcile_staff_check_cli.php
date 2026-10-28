<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require_once dirname(__DIR__) . '/includes/auth_lending.php';
require_once dirname(__DIR__) . '/includes/paymongo_qrph_webhook.php';
require_once dirname(__DIR__) . '/includes/loan_helpers.php';

$failures = 0;

function staffCheck(bool $condition, string $label): void
{
    global $failures;
    if ($condition) {
        echo 'PASS: ' . $label . PHP_EOL;
        return;
    }
    $failures++;
    echo 'FAIL: ' . $label . PHP_EOL;
}

function staffCheckSession(string $role, int $userId): void
{
    $_SESSION['logged_in'] = true;
    $_SESSION['user_id'] = $userId;
    $_SESSION['role'] = $role;
    $_SESSION['username'] = 'staff_check_' . strtolower($role);
}

$paymentsSource = (string)file_get_contents(dirname(__DIR__) . '/payments_lending.php');
$reconcileSourceStart = strpos($paymentsSource, "isset(\$_POST['reconcile_qrph'])");
$reconcileSourceEnd = strpos($paymentsSource, 'Leave QR Ph and choose another payment method');
$reconcileSource = ($reconcileSourceStart !== false && $reconcileSourceEnd !== false)
    ? substr($paymentsSource, $reconcileSourceStart, $reconcileSourceEnd - $reconcileSourceStart)
    : '';

staffCheck(str_contains($paymentsSource, 'window.setInterval(checkProviderStatus, 5000);'), 'QR page still polls every five seconds');
staffCheck(str_contains($paymentsSource, 'lendingReconcilePaymongoQrphTransaction($transactionId)'), 'status poll still uses the existing reconcile function');
staffCheck($reconcileSource !== '' && str_contains($reconcileSource, 'lendingReconcilePaymongoQrphTransaction($reconcileTransactionId)'), 'staff check calls the existing reconcile function');
staffCheck($reconcileSource !== '' && !str_contains($reconcileSource, 'PAYMONGO_WEBHOOK_SECRET'), 'staff check does not require a webhook secret');
$pendingListStart = strpos($paymentsSource, 'Pending QR Ph payments');
$pendingListEnd = $pendingListStart === false ? false : strpos($paymentsSource, 'Payments list', $pendingListStart);
$pendingListSource = ($pendingListStart !== false && $pendingListEnd !== false)
    ? substr($paymentsSource, $pendingListStart, $pendingListEnd - $pendingListStart)
    : '';
staffCheck($pendingListSource !== '' && str_contains($pendingListSource, 'name="reconcile_qrph"'), 'payments page lists a per-transaction check action');
staffCheck($pendingListSource !== '' && !str_contains($pendingListSource, 'lendingReconcilePaymongoQrphTransaction'), 'listing pending QR rows does not call PayMongo');

$adminId = (int)$conn->query("SELECT user_id FROM users WHERE role = 'Admin' AND status = 'Active' ORDER BY user_id LIMIT 1")->fetchColumn();
$collectorId = (int)$conn->query("SELECT user_id FROM users WHERE role = 'Collector' AND status = 'Active' ORDER BY user_id LIMIT 1")->fetchColumn();
$borrowerId = (int)$conn->query("SELECT user_id FROM users WHERE role = 'Borrower' AND status = 'Active' ORDER BY user_id LIMIT 1")->fetchColumn();

$assignedLoanId = 0;
$unassignedLoanId = 0;
if ($collectorId > 0) {
    $assignedStatement = $conn->prepare(
        'SELECT l.loan_id
         FROM loans l
         JOIN clients c ON c.client_id = l.client_id
         WHERE c.collector_id = ? OR l.collector_id = ?
         ORDER BY l.loan_id DESC
         LIMIT 1'
    );
    $assignedStatement->execute([$collectorId, $collectorId]);
    $assignedLoanId = (int)$assignedStatement->fetchColumn();

    $unassignedStatement = $conn->prepare(
        'SELECT l.loan_id
         FROM loans l
         JOIN clients c ON c.client_id = l.client_id
         WHERE IFNULL(c.collector_id, 0) <> ? AND IFNULL(l.collector_id, 0) <> ?
         ORDER BY l.loan_id DESC
         LIMIT 1'
    );
    $unassignedStatement->execute([$collectorId, $collectorId]);
    $unassignedLoanId = (int)$unassignedStatement->fetchColumn();
}

if ($unassignedLoanId <= 0 || $unassignedLoanId === $assignedLoanId) {
    $unassignedLoanId = 2147483646;
}
staffCheck($adminId > 0 && $collectorId > 0 && $borrowerId > 0, 'active admin, collector, and borrower accounts exist for the authorization check');
staffCheck($assignedLoanId > 0, 'collector has an assigned loan for the authorization check');
staffCheck($unassignedLoanId > 0 && $unassignedLoanId !== $assignedLoanId, 'a loan outside that collector assignment is available for the denial check');

if ($adminId > 0 && $collectorId > 0 && $assignedLoanId > 0 && $unassignedLoanId !== $assignedLoanId) {
    staffCheckSession('Admin', $adminId);
    staffCheck(lendingStaffCanReconcilePaymongoQrph($assignedLoanId, $adminId), 'admin may check an assigned loan');
    staffCheck(lendingStaffCanReconcilePaymongoQrph($unassignedLoanId, $adminId), 'admin may check a loan assigned to someone else');
    staffCheck(!lendingStaffCanReconcilePaymongoQrph($assignedLoanId, $collectorId), 'admin session cannot check a loan by passing another user id');

    staffCheckSession('Collector', $collectorId);
    staffCheck(lendingStaffCanReconcilePaymongoQrph($assignedLoanId, $collectorId), 'collector may check an assigned loan');
    staffCheck(!lendingStaffCanReconcilePaymongoQrph($unassignedLoanId, $collectorId), 'collector cannot check a loan outside that assignment');

    staffCheckSession('Borrower', $borrowerId);
    staffCheck(!lendingStaffCanReconcilePaymongoQrph($assignedLoanId, $borrowerId), 'borrower cannot check a QR transaction');
    staffCheck(!lendingStaffCanReconcilePaymongoQrph($unassignedLoanId, $borrowerId), 'borrower cannot check another loan QR transaction');

    $_SESSION['logged_in'] = false;
    staffCheck(!lendingStaffCanReconcilePaymongoQrph($assignedLoanId, $adminId), 'logged-out session cannot check a QR transaction');
}

$configuredLivemode = lendingPaymongoExpectsLivemode();
$sampleTransaction = [
    'amount' => '125.00',
    'currency' => 'PHP',
    'qr_image_url' => 'data:image/png;base64,aaaa',
    'expires_at' => '2099-01-01 00:00:00',
    'provider_status' => 'awaiting_next_action',
];
$unpaidIntent = [
    'id' => 'pi_staffcheck',
    'type' => 'payment_intent',
    'attributes' => [
        'amount' => 12500,
        'currency' => 'PHP',
        'livemode' => $configuredLivemode,
        'status' => 'awaiting_next_action',
        'payments' => [],
    ],
];
staffCheck(
    lendingPaymongoQrphInterpretIntent($sampleTransaction, $unpaidIntent)['action'] === 'pending',
    'an unpaid Payment Intent stays pending'
);
$failedIntent = $unpaidIntent;
$failedIntent['attributes']['status'] = 'awaiting_payment_method';
$failedIntent['attributes']['payments'] = [[
    'id' => 'pay_staffcheck_failed',
    'type' => 'payment',
    'attributes' => ['amount' => 12500, 'currency' => 'PHP', 'status' => 'failed', 'livemode' => $configuredLivemode, 'source' => ['type' => 'qrph']],
]];
staffCheck(
    lendingPaymongoQrphInterpretIntent($sampleTransaction, $failedIntent)['action'] === 'fail',
    'a failed QR Ph payment is not posted'
);
$wrongAmountIntent = $unpaidIntent;
$wrongAmountIntent['attributes']['status'] = 'succeeded';
$wrongAmountIntent['attributes']['amount'] = 10000;
$wrongAmountIntent['attributes']['payments'] = [[
    'id' => 'pay_staffcheck_wrong',
    'type' => 'payment',
    'attributes' => [
        'amount' => 10000,
        'currency' => 'PHP',
        'status' => 'paid',
        'livemode' => $configuredLivemode,
        'source' => ['type' => 'qrph'],
    ],
]];
staffCheck(
    lendingPaymongoQrphInterpretIntent($sampleTransaction, $wrongAmountIntent)['action'] === 'review',
    'an amount mismatch stays in review'
);

$ledgerLoanStatement = $conn->query(
    "SELECT l.loan_id, l.client_id, l.status
     FROM loans l
     WHERE l.status <> 'Paid'
     ORDER BY l.loan_id DESC"
);
$ledgerLoan = null;
$ledgerAmount = 0.0;
foreach ($ledgerLoanStatement->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
    $summary = calculateLoanBalanceSummary((int)$candidate['loan_id']);
    $due = round((float)($summary['total_amount_due'] ?? 0), 2);
    $balance = round((float)($summary['remaining_balance'] ?? 0), 2);
    if ($due < 2 || $balance < 2) {
        continue;
    }
    $openQr = $conn->prepare(
        "SELECT COUNT(*) FROM paymongo_qrph_transactions WHERE loan_id = ? AND status IN ('pending', 'review_required')"
    );
    $openQr->execute([(int)$candidate['loan_id']]);
    if ((int)$openQr->fetchColumn() > 0) {
        continue;
    }
    $ledgerLoan = $candidate;
    $ledgerAmount = 1.0;
    break;
}

if (!$ledgerLoan) {
    staffCheck(false, 'found a loan with at least ₱2.00 due for the rolled-back posting check');
} else {
    $loanId = (int)$ledgerLoan['loan_id'];
    $paymentCountSql = 'SELECT COUNT(*) FROM payments WHERE loan_id = ?';
    $countStatement = $conn->prepare($paymentCountSql);
    $countStatement->execute([$loanId]);
    $countBefore = (int)$countStatement->fetchColumn();
    $statusBefore = (string)$conn->query('SELECT status FROM loans WHERE loan_id = ' . $loanId)->fetchColumn();
    $providerPaymentId = 'pay_localcheck' . bin2hex(random_bytes(6));
    $posted = false;

    $conn->beginTransaction();
    try {
        $insert = $conn->prepare(
            "INSERT INTO paymongo_qrph_transactions
                (internal_reference, loan_id, client_id, borrower_user_id, initiated_by_user_id, collector_id,
                 selected_method, amount, penalty_paid, currency, status, provider_status, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, 'E-Wallet', ?, 0.00, 'PHP', 'pending', 'awaiting_next_action', DATE_ADD(NOW(), INTERVAL 600 SECOND))"
        );
        $insert->execute([
            'QRPH-LOCAL' . strtoupper(bin2hex(random_bytes(6))),
            $loanId,
            (int)$ledgerLoan['client_id'],
            $borrowerId > 0 ? $borrowerId : $adminId,
            $adminId,
            $collectorId > 0 ? $collectorId : $adminId,
            number_format($ledgerAmount, 2, '.', ''),
        ]);
        $transactionId = (int)$conn->lastInsertId();
        $rowStatement = $conn->prepare('SELECT * FROM paymongo_qrph_transactions WHERE id = ? LIMIT 1');
        $rowStatement->execute([$transactionId]);
        $row = $rowStatement->fetch(PDO::FETCH_ASSOC);

        $firstPost = lendingApplyPaymongoQrphDecision($conn, $row, [
            'action' => 'post',
            'provider_status' => 'succeeded',
            'provider_payment_id' => $providerPaymentId,
        ], '');
        $rowStatement->execute([$transactionId]);
        $postedRow = $rowStatement->fetch(PDO::FETCH_ASSOC);
        $countStatement->execute([$loanId]);
        $countAfterPost = (int)$countStatement->fetchColumn();
        staffCheck($firstPost['result'] === 'processed' && $countAfterPost === $countBefore + 1, 'a verified QR decision posts one repayment');
        staffCheck((string)($postedRow['status'] ?? '') === 'succeeded', 'the QR row is succeeded after that post');

        $secondReconcile = lendingReconcilePaymongoQrphTransaction($transactionId);
        $countStatement->execute([$loanId]);
        $countAfterReconcile = (int)$countStatement->fetchColumn();
        staffCheck($secondReconcile === 'succeeded' && $countAfterReconcile === $countBefore + 1, 'checking the same succeeded QR again does not post a second repayment');

        $secondPost = lendingApplyPaymongoQrphDecision($conn, $postedRow, [
            'action' => 'post',
            'provider_status' => 'succeeded',
            'provider_payment_id' => $providerPaymentId,
        ], '');
        $countStatement->execute([$loanId]);
        $countAfterSecondPost = (int)$countStatement->fetchColumn();
        staffCheck($secondPost['result'] === 'ignored' && $countAfterSecondPost === $countBefore + 1, 'the same provider payment ID cannot create another ledger row');

        $cashCollectorName = 'Staff Check';
        recordPaymentTransaction($loanId, date('Y-m-d'), $ledgerAmount, $cashCollectorName, null, (int)$ledgerLoan['client_id'], 'Cash', null, 0, 'Rolled-back cash check');
        $countStatement->execute([$loanId]);
        $countAfterCash = (int)$countStatement->fetchColumn();
        staffCheck($countAfterCash === $countBefore + 2, 'cash recording still inserts a separate payment');
        $posted = true;
    } catch (Throwable $exception) {
        staffCheck(false, 'rolled-back posting check completed: ' . $exception->getMessage());
    } finally {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
    }

    $countStatement->execute([$loanId]);
    $countAfterRollback = (int)$countStatement->fetchColumn();
    $statusAfterRollback = (string)$conn->query('SELECT status FROM loans WHERE loan_id = ' . $loanId)->fetchColumn();
    $leftover = (int)$conn->query(
        "SELECT COUNT(*) FROM paymongo_qrph_transactions WHERE provider_payment_id = " . $conn->quote($providerPaymentId)
    )->fetchColumn();
    staffCheck($posted && $countAfterRollback === $countBefore, 'rolled-back check left the payment count unchanged');
    staffCheck($statusAfterRollback === $statusBefore, 'rolled-back check left the loan status unchanged');
    staffCheck($leftover === 0, 'rolled-back check left no QR transaction behind');
}

exit($failures > 0 ? 1 : 0);
