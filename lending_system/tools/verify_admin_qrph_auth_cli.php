<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../includes/db_lending.php';
require_once __DIR__ . '/../includes/auth_lending.php';
require_once __DIR__ . '/../includes/paymongo_qrph.php';

/** @var PDO $conn */
global $conn;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function setSessionRole(string $role, int $userId): void
{
    $_SESSION['logged_in'] = true;
    $_SESSION['user_id'] = $userId;
    $_SESSION['role'] = $role;
    $_SESSION['username'] = 'test_' . strtolower($role);
}

$adminId = (int)$conn->query("SELECT user_id FROM users WHERE role = 'Admin' AND status = 'Active' ORDER BY user_id LIMIT 1")->fetchColumn();
$collectorId = (int)$conn->query("SELECT user_id FROM users WHERE role = 'Collector' AND status = 'Active' ORDER BY user_id LIMIT 1")->fetchColumn();
$borrowerId = (int)$conn->query("SELECT user_id FROM users WHERE role = 'Borrower' AND status = 'Active' ORDER BY user_id LIMIT 1")->fetchColumn();
$loanId = (int)$conn->query("SELECT loan_id FROM loans WHERE status IN ('Active','Overdue') ORDER BY loan_id DESC LIMIT 1")->fetchColumn();

$results = [];

setSessionRole('Admin', $adminId);
$results['admin_can_initiate'] = canInitiatePaymongoQrph();

setSessionRole('Collector', $collectorId);
$results['collector_can_initiate'] = canInitiatePaymongoQrph();

setSessionRole('Borrower', $borrowerId);
$results['borrower_can_initiate'] = canInitiatePaymongoQrph();

$paidBefore = null;
$pendingCreated = false;
$pendingError = null;
if ($loanId > 0 && $adminId > 0) {
    $paidBefore = (float)$conn->query('SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE loan_id = ' . $loanId)->fetchColumn();
    setSessionRole('Admin', $adminId);
    try {
        lendingCreatePaymongoQrphTransaction($loanId, $adminId, 'E-Wallet', '1.00', '0');
        $pendingCreated = true;
    } catch (Throwable $e) {
        $pendingError = $e->getMessage();
    }
    $paidAfter = (float)$conn->query('SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE loan_id = ' . $loanId)->fetchColumn();
    $results['pending_balance_unchanged'] = abs($paidBefore - $paidAfter) < 0.001;
    if ($pendingCreated) {
        $open = $conn->query(
            "SELECT id, status FROM paymongo_qrph_transactions WHERE loan_id = {$loanId} ORDER BY id DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        $results['last_qrph_status'] = $open['status'] ?? null;
        if ($open && ($open['status'] ?? '') === 'pending') {
            $conn->exec('DELETE FROM paymongo_qrph_transactions WHERE id = ' . (int)$open['id']);
        }
    }
}

$results['loan_id_used'] = $loanId;
$results['pending_api_attempt'] = $pendingCreated ? 'created' : ('skipped: ' . ($pendingError ?? 'unknown'));

echo json_encode($results, JSON_PRETTY_PRINT) . PHP_EOL;

$pass = $results['admin_can_initiate']
    && $results['collector_can_initiate']
    && !$results['borrower_can_initiate']
    && (!isset($results['pending_balance_unchanged']) || $results['pending_balance_unchanged']);

exit($pass ? 0 : 1);
