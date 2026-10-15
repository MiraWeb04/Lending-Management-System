<?php
chdir(dirname(__DIR__));
session_start();

require_once 'includes/db_lending.php';

$user = executeQuery(
    "SELECT user_id, username, full_name, email, role FROM users WHERE status = 'Active' AND role IN ('Admin', 'Collector') ORDER BY FIELD(role, 'Admin', 'Collector') LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    fwrite(STDERR, "No staff user\n");
    exit(1);
}

$_SESSION['user_id'] = $user['user_id'];
$_SESSION['username'] = $user['username'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['email'] = $user['email'] ?? '';
$_SESSION['role'] = $user['role'];
$_SESSION['logged_in'] = true;
$_SESSION['user'] = $user;

$_GET = [
    'add' => 'true',
    'loan' => '19',
    'amount' => '5750.00',
    'borrower' => 'Maria Santos',
];
$_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();
include 'payments_lending.php';
$html = ob_get_clean();

if (!preg_match('/id="amount_paid"[^>]*value="([^"]*)"/', $html, $amountMatch)
    && !preg_match('/value="([^"]*)"[^>]*id="amount_paid"/', $html, $amountMatch)) {
    fwrite(STDERR, "amount input not found\n");
    exit(1);
}

preg_match('/id="penalty_paid"[^>]*value="([^"]*)"/', $html, $penaltyMatch);
preg_match('/id="total_due_label"[^>]*>([^<]*)</', $html, $dueMatch);
preg_match('/id="remaining_balance_label"[^>]*>([^<]*)</', $html, $balanceMatch);
preg_match('/id="penalty_due_label"[^>]*>([^<]*)</', $html, $penaltyDueMatch);
preg_match('/data-collect-full="([^"]*)"/', $html, $flagMatch);

echo json_encode([
    'amount_paid' => $amountMatch[1] ?? null,
    'penalty_paid' => $penaltyMatch[1] ?? null,
    'remaining_balance' => isset($balanceMatch[1]) ? trim(html_entity_decode($balanceMatch[1])) : null,
    'penalty_due' => isset($penaltyDueMatch[1]) ? trim(html_entity_decode($penaltyDueMatch[1])) : null,
    'total_amount_due' => isset($dueMatch[1]) ? trim(html_entity_decode($dueMatch[1])) : null,
    'collect_full_flag' => $flagMatch[1] ?? null,
    'still_shows_installment' => str_contains($amountMatch[1] ?? '', '5750') || str_contains($amountMatch[1] ?? '', '5749'),
], JSON_PRETTY_PRINT), PHP_EOL;
