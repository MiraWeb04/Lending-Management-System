<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require_once dirname(__DIR__) . '/includes/env_lending.php';
require_once dirname(__DIR__) . '/includes/paymongo_schema.php';
require_once dirname(__DIR__) . '/includes/db_lending.php';
require_once dirname(__DIR__) . '/includes/paymongo_qrph_webhook.php';
require_once dirname(__DIR__) . '/includes/loan_helpers.php';

function integrationAssert(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
        exit(1);
    }
    echo 'PASS: ' . $label . PHP_EOL;
}

$host = (string)lendingEnv('DB_HOST', 'localhost');
$dbName = (string)lendingEnv('DB_NAME', lendingPaymongoExpectedDatabaseName());
$user = (string)lendingEnv('DB_USER', 'root');
$password = (string)lendingEnv('DB_PASSWORD', '');

try {
    $pdo = lendingPaymongoSchemaPdo($host, $dbName, $user, $password);
} catch (PDOException $exception) {
    fwrite(STDERR, 'Connection failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

if (!filter_var(lendingEnv('PAYMONGO_INTEGRATION_TEST', '0'), FILTER_VALIDATE_BOOL)) {
    fwrite(STDERR, "Set PAYMONGO_INTEGRATION_TEST=1 in .env to run DB integration tests on your local XAMPP copy.\n");
    exit(1);
}

$validation = lendingPaymongoValidateMigrationTarget($pdo, $host, $dbName);
if (!$validation['ok']) {
    fwrite(STDERR, "Refusing integration tests until the target is your local XAMPP lending_management database:\n");
    foreach ($validation['errors'] as $error) {
        fwrite(STDERR, '  - ' . $error . PHP_EOL);
    }
    exit(1);
}

$verify = lendingPaymongoSchemaVerify($pdo);
if (!$verify['ok']) {
    fwrite(STDERR, "PayMongo schema is incomplete. Run paymongo_migrate_cli.php --apply --backup-confirmed first.\n");
    exit(1);
}

$loanId = (int)lendingEnv('PAYMONGO_TEST_LOAN_ID', '0');
if ($loanId <= 0) {
    $loanRow = $pdo->query(
        "SELECT l.loan_id FROM loans l
         WHERE l.status IN ('Active', 'Overdue')
         ORDER BY l.loan_id ASC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $loanId = (int)($loanRow['loan_id'] ?? 0);
}

if ($loanId <= 0) {
    fwrite(STDERR, "No suitable Active/Overdue loan found. Set PAYMONGO_TEST_LOAN_ID in .env.\n");
    exit(1);
}

$loanDetails = executeQuery(
    'SELECT l.loan_id, l.client_id, l.status,
            COALESCE(c.user_id, 0) AS borrower_user_id
     FROM loans l
     JOIN clients c ON c.client_id = l.client_id
     WHERE l.loan_id = ?
     LIMIT 1',
    [$loanId]
)->fetch(PDO::FETCH_ASSOC);

if (!$loanDetails || (int)$loanDetails['borrower_user_id'] <= 0) {
    fwrite(STDERR, "Loan {$loanId} is missing a linked borrower user.\n");
    exit(1);
}

$balance = calculateLoanBalanceSummary($loanId);
$amountDue = (float)($balance['total_amount_due'] ?? 0);
if ($amountDue <= 0) {
    fwrite(STDERR, "Loan {$loanId} has no amount due; pick another loan via PAYMONGO_TEST_LOAN_ID.\n");
    exit(1);
}

$testAmount = min(100.0, $amountDue);
$amountCentavos = (int)round($testAmount * 100);
$internalRef = 'INTTEST-' . strtoupper(bin2hex(random_bytes(4)));
$intentId = 'pi_inttest_' . bin2hex(random_bytes(6));
$eventIdPaid = 'evt_inttest_paid_' . bin2hex(random_bytes(4));
$providerPaymentId = 'pay_inttest_' . bin2hex(random_bytes(4));

$paymentsBefore = (int)$pdo->query('SELECT COUNT(*) FROM payments WHERE loan_id = ' . (int)$loanId)->fetchColumn();

$pdo->prepare(
    "INSERT INTO paymongo_qrph_transactions
        (internal_reference, payment_intent_id, loan_id, client_id, borrower_user_id,
         initiated_by_user_id, collector_id, selected_method, amount, penalty_paid,
         currency, status, provider_status, expires_at)
     VALUES (?, ?, ?, ?, ?, ?, NULL, 'Bank Transfer', ?, 0, 'PHP', 'pending', 'awaiting_payment',
             DATE_ADD(NOW(), INTERVAL 1 HOUR))"
)->execute([
    $internalRef,
    $intentId,
    $loanId,
    (int)$loanDetails['client_id'],
    (int)$loanDetails['borrower_user_id'],
    (int)$loanDetails['borrower_user_id'],
    number_format($testAmount, 2, '.', ''),
]);

$txnId = (int)$pdo->lastInsertId();

$paidBody = json_encode([
    'data' => [
        'id' => $eventIdPaid,
        'type' => 'event',
        'attributes' => [
            'type' => 'payment.paid',
            'livemode' => false,
            'data' => [
                'id' => $providerPaymentId,
                'type' => 'payment',
                'attributes' => [
                    'status' => 'paid',
                    'payment_intent_id' => $intentId,
                    'amount' => $amountCentavos,
                    'currency' => 'PHP',
                    'livemode' => false,
                    'source' => ['type' => 'qrph'],
                ],
            ],
        ],
    ],
], JSON_THROW_ON_ERROR);

$paidEvent = lendingParsePaymongoWebhookEvent($paidBody);
integrationAssert($paidEvent !== null, 'parses synthetic payment.paid event');

$result = lendingProcessPaymongoWebhookEvent($paidEvent, $paidBody);
integrationAssert($result === 'processed', 'payment.paid posts repayment via recordPaymentTransaction');

$paymentsAfterPaid = (int)$pdo->query('SELECT COUNT(*) FROM payments WHERE loan_id = ' . (int)$loanId)->fetchColumn();
integrationAssert($paymentsAfterPaid === $paymentsBefore + 1, 'payment history gains exactly one row');

$txnRow = $pdo->prepare('SELECT status, payment_id FROM paymongo_qrph_transactions WHERE id = ?');
$txnRow->execute([$txnId]);
$txn = $txnRow->fetch(PDO::FETCH_ASSOC);
integrationAssert(($txn['status'] ?? '') === 'succeeded', 'transaction status is succeeded');
integrationAssert((int)($txn['payment_id'] ?? 0) > 0, 'transaction links to payments.payment_id');

$linkStmt = $pdo->prepare(
    'SELECT paymongo_qrph_transaction_id FROM payments WHERE payment_id = ? LIMIT 1'
);
$linkStmt->execute([(int)$txn['payment_id']]);
integrationAssert((int)$linkStmt->fetchColumn() === $txnId, 'payments.paymongo_qrph_transaction_id links back to QR transaction');

$duplicateResult = lendingProcessPaymongoWebhookEvent($paidEvent, $paidBody);
integrationAssert($duplicateResult === 'duplicate', 'duplicate webhook does not double-post');

$paymentsAfterDuplicate = (int)$pdo->query('SELECT COUNT(*) FROM payments WHERE loan_id = ' . (int)$loanId)->fetchColumn();
integrationAssert($paymentsAfterDuplicate === $paymentsAfterPaid, 'payment count unchanged after duplicate webhook');

$intentFailed = 'pi_inttest_fail_' . bin2hex(random_bytes(4));
$internalFail = 'INTTEST-FAIL-' . strtoupper(bin2hex(random_bytes(4)));
$pdo->prepare(
    "INSERT INTO paymongo_qrph_transactions
        (internal_reference, payment_intent_id, loan_id, client_id, borrower_user_id,
         initiated_by_user_id, selected_method, amount, penalty_paid, currency, status,
         provider_status, expires_at)
     VALUES (?, ?, ?, ?, ?, ?, 'E-Wallet', ?, 0, 'PHP', 'pending', 'awaiting_payment',
             DATE_ADD(NOW(), INTERVAL 1 HOUR))"
)->execute([
    $internalFail,
    $intentFailed,
    $loanId,
    (int)$loanDetails['client_id'],
    (int)$loanDetails['borrower_user_id'],
    (int)$loanDetails['borrower_user_id'],
    number_format($testAmount, 2, '.', ''),
]);

$failedBody = json_encode([
    'data' => [
        'id' => 'evt_inttest_fail_' . bin2hex(random_bytes(4)),
        'type' => 'event',
        'attributes' => [
            'type' => 'payment.failed',
            'livemode' => false,
            'data' => [
                'id' => 'pay_inttest_fail_' . bin2hex(random_bytes(4)),
                'type' => 'payment',
                'attributes' => [
                    'status' => 'failed',
                    'payment_intent_id' => $intentFailed,
                    'amount' => $amountCentavos,
                    'currency' => 'PHP',
                    'livemode' => false,
                ],
            ],
        ],
    ],
], JSON_THROW_ON_ERROR);
$failedEvent = lendingParsePaymongoWebhookEvent($failedBody);
integrationAssert($failedEvent !== null, 'parses synthetic payment.failed event');

$failedResult = lendingProcessPaymongoWebhookEvent($failedEvent, $failedBody);
integrationAssert(in_array($failedResult, ['processed', 'ignored'], true), 'payment.failed handled without new repayment');

$paymentsAfterFailed = (int)$pdo->query('SELECT COUNT(*) FROM payments WHERE loan_id = ' . (int)$loanId)->fetchColumn();
integrationAssert($paymentsAfterFailed === $paymentsAfterPaid, 'payment.failed does not add payment rows');

$failedTxn = $pdo->prepare('SELECT status FROM paymongo_qrph_transactions WHERE internal_reference = ?');
$failedTxn->execute([$internalFail]);
integrationAssert(($failedTxn->fetchColumn() ?: '') === 'failed', 'failed attempt marked failed on transaction');

echo "\nIntegration tests completed for loan_id={$loanId}. Test payment remains on the loan; remove manually if this was production data.\n";
echo "Created internal references: {$internalRef}, {$internalFail}\n";
