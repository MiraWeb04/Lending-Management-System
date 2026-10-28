<?php
/**
 * Focused payment regressions. Loan/payment writes are rolled back and
 * webhook fixtures are cleaned up in the isolated FRAMS test database.
 */
if (!defined('FRAMS_TEST_PREPEND_LOADED') || lendingEnv('DB_NAME') !== 'frams_capstone_test') {
    fwrite(STDERR, "Refusing to run outside the isolated test database.\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
$app = $root . '/lending_system';
require_once $app . '/includes/db_lending.php';
require_once $app . '/includes/loan_helpers.php';
require_once $app . '/includes/paymongo_qrph.php';
require_once $app . '/includes/paymongo_qrph_webhook.php';
require_once $app . '/includes/auth_lending.php';

if ((string)$conn->query('SELECT DATABASE()')->fetchColumn() !== 'frams_capstone_test') {
    fwrite(STDERR, "Connected database is not the isolated test database.\n");
    exit(1);
}

ensurePaymentReceiptColumns();
$manifest = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/testing_evidence/_harness/seed_manifest.json'), true);
$candidateLoanIds = [
    (int)($manifest['loans']['a']['loan_id'] ?? 0),
    (int)($manifest['loans']['b']['loan_id'] ?? 0),
];
$loanId = 0;
$otherLoanId = 0;
$summary = [];
foreach ($candidateLoanIds as $candidateId) {
    if ($candidateId <= 0) {
        continue;
    }
    $candidateSummary = calculateLoanBalanceSummary($candidateId);
    if ((float)$candidateSummary['remaining_balance'] > 0) {
        $loanId = $candidateId;
        $summary = $candidateSummary;
        break;
    }
}
foreach ($candidateLoanIds as $candidateId) {
    if ($candidateId > 0 && $candidateId !== $loanId) {
        $otherLoanId = $candidateId;
        break;
    }
}

$results = [];

function framsPaymentTest(string $name, callable $test): void
{
    global $conn, $results;

    $conn->exec('SAVEPOINT payment_regression_case');
    try {
        $test();
        $results[] = ['case' => $name, 'status' => 'PASS', 'details' => 'Assertions passed.'];
        echo 'PASS ' . $name . PHP_EOL;
    } catch (Throwable $exception) {
        $results[] = ['case' => $name, 'status' => 'FAIL', 'details' => $exception->getMessage()];
        echo 'FAIL ' . $name . ': ' . $exception->getMessage() . PHP_EOL;
    } finally {
        $conn->exec('ROLLBACK TO SAVEPOINT payment_regression_case');
        $conn->exec('RELEASE SAVEPOINT payment_regression_case');
    }
}

function framsExpectInvalidPayment(callable $operation, string $messagePart): void
{
    try {
        $operation();
    } catch (InvalidArgumentException $exception) {
        if (stripos($exception->getMessage(), $messagePart) !== false) {
            return;
        }
        throw new RuntimeException('Unexpected validation message: ' . $exception->getMessage());
    }
    throw new RuntimeException('Expected payment validation to reject the input.');
}

if ($loanId <= 0 || $otherLoanId <= 0) {
    fwrite(STDERR, "Synthetic loans with an outstanding balance are unavailable in frams_capstone_test.\n");
    exit(2);
}

$conn->beginTransaction();
try {
    framsPaymentTest('valid loan payment and receipt total', static function () use ($loanId): void {
        $balance = calculateLoanBalanceSummary($loanId);
        $amount = min(25.00, (float)$balance['remaining_balance']);
        if ($amount <= 0) {
            throw new RuntimeException('No outstanding balance is available for the valid-payment test.');
        }
        $receipt = recordPaymentTransaction(
            $loanId,
            date('Y-m-d'),
            number_format($amount, 2, '.', ''),
            'FRAMS Payment Regression',
            null,
            null,
            'Cash',
            null,
            '0.00',
            'Regression transaction'
        );
        $payment = executeQuery('SELECT amount_paid, penalty_paid, receipt_number FROM payments WHERE receipt_number = ?', [$receipt])->fetch(PDO::FETCH_ASSOC);
        if (!$payment
            || round((float)$payment['amount_paid'], 2) !== round($amount, 2)
            || (float)$payment['penalty_paid'] !== 0.0
            || (string)$payment['receipt_number'] !== $receipt) {
            throw new RuntimeException('Payment portion, penalty, or receipt was not stored as expected.');
        }
    });

    framsPaymentTest('valid payment with outstanding penalty', static function () use ($loanId): void {
        global $conn;
        $firstSchedule = executeQuery(
            'SELECT id FROM loan_payment_schedules WHERE release_id = (SELECT release_id FROM loans WHERE loan_id = ?) ORDER BY installment_number LIMIT 1',
            [$loanId]
        )->fetchColumn();
        if (!$firstSchedule) {
            throw new RuntimeException('No synthetic installment is available.');
        }
        executeQuery(
            'UPDATE loan_payment_schedules SET due_date = ?, status = ? WHERE id = ?',
            [date('Y-m-d', strtotime('-1 day')), 'Upcoming', (int)$firstSchedule]
        );
        $balance = calculateLoanBalanceSummary($loanId);
        $penalty = min(0.01, (float)$balance['penalty_due']);
        if ($penalty <= 0) {
            throw new RuntimeException('The synthetic loan did not produce an outstanding penalty.');
        }
        $loanPart = min(10.00, (float)$balance['remaining_balance']);
        if ($loanPart <= 0) {
            throw new RuntimeException('No outstanding loan balance is available for the penalty test.');
        }
        $amount = round($penalty + $loanPart, 2);
        $receipt = recordPaymentTransaction(
            $loanId,
            date('Y-m-d'),
            number_format($amount, 2, '.', ''),
            'FRAMS Payment Regression',
            null,
            null,
            'Cash',
            null,
            number_format($penalty, 2, '.', ''),
            'Regression transaction'
        );
        $payment = executeQuery('SELECT amount_paid, penalty_paid FROM payments WHERE receipt_number = ?', [$receipt])->fetch(PDO::FETCH_ASSOC);
        if (!$payment
            || round((float)$payment['amount_paid'] + (float)$payment['penalty_paid'], 2) !== $amount
            || round((float)$payment['penalty_paid'], 2) !== $penalty) {
            throw new RuntimeException('Stored loan and penalty portions do not equal total received.');
        }
    });

    framsPaymentTest('payment equal to remaining balance', static function () use ($loanId): void {
        $balance = calculateLoanBalanceSummary($loanId);
        if ((float)$balance['remaining_balance'] <= 0) {
            throw new RuntimeException('No loan balance is available for the equality test.');
        }
        $amount = round((float)$balance['remaining_balance'], 2);
        $receipt = recordPaymentTransaction(
            $loanId,
            date('Y-m-d'),
            number_format($amount, 2, '.', ''),
            'FRAMS Payment Regression',
            null,
            null,
            'Cash',
            null,
            '0.00',
            'Regression exact-balance transaction'
        );
        $payment = executeQuery('SELECT amount_paid, penalty_paid FROM payments WHERE receipt_number = ?', [$receipt])->fetch(PDO::FETCH_ASSOC);
        if (!$payment
            || round((float)$payment['amount_paid'], 2) !== $amount
            || round((float)$payment['penalty_paid'], 2) !== 0.0) {
            throw new RuntimeException('The exact remaining balance was not stored as the loan-payment portion.');
        }
    });

    framsPaymentTest('receipt balances are cumulative as of each payment', static function () use ($loanId): void {
        $balance = calculateLoanBalanceSummary($loanId);
        $firstAmount = min(10.00, round((float)$balance['remaining_balance'] / 2, 2));
        $secondAmount = min(10.00, round((float)$balance['remaining_balance'] - $firstAmount, 2));
        if ($firstAmount <= 0 || $secondAmount <= 0) {
            throw new RuntimeException('At least two centavos of loan balance are required for the cumulative-receipt test.');
        }
        $paymentDate = date('Y-m-d');
        $priorPaid = (float)executeQuery(
            'SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE loan_id = ? AND payment_date <= ?',
            [$loanId, $paymentDate]
        )->fetchColumn();
        $receipts = [];
        foreach ([$firstAmount, $secondAmount] as $amount) {
            $receipts[] = recordPaymentTransaction(
                $loanId,
                $paymentDate,
                number_format($amount, 2, '.', ''),
                'FRAMS Payment Regression',
                null,
                null,
                'Cash',
                null,
                '0.00',
                'Regression cumulative receipt'
            );
        }
        $rows = executeQuery(
            'SELECT p.payment_id, p.amount_paid, l.total_payable,
                    (SELECT COALESCE(SUM(p2.amount_paid), 0)
                     FROM payments p2
                     WHERE p2.loan_id = p.loan_id
                       AND (p2.payment_date < p.payment_date
                            OR (p2.payment_date = p.payment_date AND p2.payment_id <= p.payment_id))) AS total_loan_paid_to_date
             FROM payments p
             JOIN loans l ON l.loan_id = p.loan_id
             WHERE p.receipt_number IN (?, ?)
             ORDER BY p.payment_id',
            $receipts
        )->fetchAll(PDO::FETCH_ASSOC);
        $expectedPaid = $priorPaid;
        foreach ($rows as $index => $row) {
            $expectedPaid += $index === 0 ? $firstAmount : $secondAmount;
            if (round((float)$row['total_loan_paid_to_date'], 2) !== round($expectedPaid, 2)) {
                throw new RuntimeException('Cumulative loan balance does not include payments through the receipt date and ID.');
            }
            $remaining = max(0.0, round((float)$row['total_payable'] - $expectedPaid, 2));
            if ($remaining !== round(max(0.0, (float)$row['total_payable'] - (float)$row['total_loan_paid_to_date']), 2)) {
                throw new RuntimeException('Receipt remaining balance does not match its cumulative loan-payment portion.');
            }
        }
        if (count($rows) !== 2) {
            throw new RuntimeException('Both synthetic receipt records were not found.');
        }
    });

    foreach ([
        'exceeds remaining loan balance' => [static function () use ($loanId): void {
            $firstSchedule = executeQuery(
                'SELECT id FROM loan_payment_schedules WHERE release_id = (SELECT release_id FROM loans WHERE loan_id = ?) ORDER BY installment_number LIMIT 1',
                [$loanId]
            )->fetchColumn();
            if ($firstSchedule) {
                executeQuery(
                    'UPDATE loan_payment_schedules SET due_date = ?, status = ? WHERE id = ?',
                    [date('Y-m-d', strtotime('-1 day')), 'Upcoming', (int)$firstSchedule]
                );
            }
            $balance = calculateLoanBalanceSummary($loanId);
            validatePaymentAmounts($loanId, round((float)$balance['remaining_balance'] + 0.01, 2), 0);
        }, 'Installment portion'],
        'negative total' => [static function () use ($loanId): void { validatePaymentAmounts($loanId, '-1.00', '0.00'); }, 'negative'],
        'zero total' => [static function () use ($loanId): void { validatePaymentAmounts($loanId, '0.00', '0.00'); }, 'greater than zero'],
        'nonnumeric total' => [static function () use ($loanId): void { validatePaymentAmounts($loanId, 'not-money', '0.00'); }, 'valid finite'],
        'non-finite total' => [static function () use ($loanId): void { validatePaymentAmounts($loanId, INF, 0); }, 'valid finite'],
        'non-finite penalty' => [static function () use ($loanId): void { validatePaymentAmounts($loanId, '10.00', NAN); }, 'valid finite'],
        'negative penalty' => [static function () use ($loanId): void { validatePaymentAmounts($loanId, '10.00', '-0.01'); }, 'negative'],
        'penalty exceeds total' => [static function () use ($loanId): void { validatePaymentAmounts($loanId, '10.00', '10.01'); }, 'cannot exceed total'],
        'sub-cent amount' => [static function () use ($loanId): void { validatePaymentAmounts($loanId, '10.001', '0.00'); }, 'two decimal places'],
    ] as $name => [$operation, $expectedMessage]) {
        framsPaymentTest($name, static function () use ($operation, $expectedMessage): void {
            framsExpectInvalidPayment($operation, $expectedMessage);
        });
    }

    framsPaymentTest('penalty exceeds outstanding penalty due', static function () use ($loanId): void {
        global $conn;
        $firstSchedule = executeQuery(
            'SELECT id FROM loan_payment_schedules WHERE release_id = (SELECT release_id FROM loans WHERE loan_id = ?) ORDER BY installment_number LIMIT 1',
            [$loanId]
        )->fetchColumn();
        if (!$firstSchedule) {
            throw new RuntimeException('No synthetic installment is available.');
        }
        executeQuery(
            'UPDATE loan_payment_schedules SET due_date = ?, status = ? WHERE id = ?',
            [date('Y-m-d', strtotime('-1 day')), 'Upcoming', (int)$firstSchedule]
        );
        $balance = calculateLoanBalanceSummary($loanId);
        $excessivePenalty = round((float)$balance['penalty_due'] + 0.01, 2);
        framsExpectInvalidPayment(
            static function () use ($loanId, $excessivePenalty): void {
                validatePaymentAmounts($loanId, $excessivePenalty, $excessivePenalty);
            },
            'outstanding penalty due'
        );
    });

    framsPaymentTest('payment deletion validates ownership and recalculates status', static function () use ($loanId, $otherLoanId): void {
        $beforeOtherStatus = executeQuery('SELECT status FROM loans WHERE loan_id = ?', [$otherLoanId])->fetchColumn();
        $beforeOtherSchedule = executeQuery(
            'SELECT id, status FROM loan_payment_schedules WHERE release_id = (SELECT release_id FROM loans WHERE loan_id = ?) ORDER BY installment_number',
            [$otherLoanId]
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $beforeSchedule = executeQuery(
            'SELECT id, status FROM loan_payment_schedules WHERE release_id = (SELECT release_id FROM loans WHERE loan_id = ?) ORDER BY installment_number',
            [$loanId]
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $balanceBefore = calculateLoanBalanceSummary($loanId);
        $receipt = recordPaymentTransaction(
            $loanId,
            date('Y-m-d'),
            number_format((float)$balanceBefore['remaining_balance'], 2, '.', ''),
            'FRAMS Payment Regression',
            null,
            null,
            'Cash',
            null,
            '0.00',
            'Regression deletion transaction'
        );
        $payment = executeQuery('SELECT payment_id FROM payments WHERE receipt_number = ?', [$receipt])->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            throw new RuntimeException('The synthetic payment was not recorded.');
        }
        framsExpectInvalidPayment(
            static function () use ($payment, $otherLoanId): void {
                deletePaymentTransaction((int)$payment['payment_id'], $otherLoanId);
            },
            'not found'
        );
        if ((int)executeQuery('SELECT COUNT(*) FROM payments WHERE payment_id = ? AND loan_id = ?', [(int)$payment['payment_id'], $loanId])->fetchColumn() !== 1) {
            throw new RuntimeException('Payment was changed by an incorrect-loan deletion attempt.');
        }
        deletePaymentTransaction((int)$payment['payment_id'], $loanId);
        if ((int)executeQuery('SELECT COUNT(*) FROM payments WHERE payment_id = ?', [(int)$payment['payment_id']])->fetchColumn() !== 0) {
            throw new RuntimeException('The authorized deletion did not remove the manual test payment.');
        }
        $afterOtherStatus = executeQuery('SELECT status FROM loans WHERE loan_id = ?', [$otherLoanId])->fetchColumn();
        if ($afterOtherStatus !== $beforeOtherStatus) {
            throw new RuntimeException('Deleting a payment changed another loan status.');
        }
        $summary = calculateLoanBalanceSummary($loanId);
        if (round((float)$summary['remaining_balance'], 2) !== round((float)$balanceBefore['remaining_balance'], 2)) {
            throw new RuntimeException('The loan balance was not restored after deletion.');
        }
        $afterSchedule = executeQuery(
            'SELECT id, status FROM loan_payment_schedules WHERE release_id = (SELECT release_id FROM loans WHERE loan_id = ?) ORDER BY installment_number',
            [$loanId]
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($afterSchedule !== $beforeSchedule) {
            throw new RuntimeException('The loan schedule was not restored after deleting the synthetic payment.');
        }
        $afterLoanStatus = executeQuery('SELECT status FROM loans WHERE loan_id = ?', [$loanId])->fetchColumn();
        $loanRow = executeQuery('SELECT total_payable, due_date FROM loans WHERE loan_id = ?', [$loanId])->fetch(PDO::FETCH_ASSOC);
        $totalPaid = (float)executeQuery('SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE loan_id = ?', [$loanId])->fetchColumn();
        $overdueCount = (int)executeQuery(
            'SELECT COUNT(*) FROM loan_payment_schedules WHERE release_id = (SELECT release_id FROM loans WHERE loan_id = ?) AND status = ?',
            [$loanId, 'Overdue']
        )->fetchColumn();
        $expectedStatus = $totalPaid >= (float)$loanRow['total_payable'] && (float)$loanRow['total_payable'] > 0
            ? 'Paid'
            : (($overdueCount > 0
                || (strtotime((string)$loanRow['due_date']) < strtotime(date('Y-m-d')) && $totalPaid < (float)$loanRow['total_payable']))
                ? 'Overdue'
                : 'Active');
        if ($afterLoanStatus !== $expectedStatus) {
            throw new RuntimeException('Expected loan status ' . $expectedStatus . ' after deletion, got ' . (string)$afterLoanStatus . '.');
        }
        $afterOtherSchedule = executeQuery(
            'SELECT id, status FROM loan_payment_schedules WHERE release_id = (SELECT release_id FROM loans WHERE loan_id = ?) ORDER BY installment_number',
            [$otherLoanId]
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($afterOtherSchedule !== $beforeOtherSchedule) {
            throw new RuntimeException('Deleting this payment changed another loan schedule.');
        }
    });

    framsPaymentTest('unauthorized deletion is blocked by endpoint authorization', static function (): void {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/lending_system/payments_lending.php');
        $deleteBranch = strpos($source, 'if (isset($_POST[\'delete_payment\']))');
        $authorization = $deleteBranch === false ? false : strpos($source, "if (!isAdmin())", $deleteBranch);
        $originalSession = $_SESSION;
        $_SESSION['logged_in'] = true;
        $_SESSION['role'] = 'Collector';
        $collectorDenied = !isAdmin();
        $_SESSION['role'] = 'Borrower';
        $borrowerDenied = !isAdmin();
        $_SESSION['role'] = 'Admin';
        $adminAllowed = isAdmin();
        $_SESSION = $originalSession;
        if ($authorization === false
            || $authorization > $deleteBranch + 300
            || !$collectorDenied
            || !$borrowerDenied
            || !$adminAllowed) {
            throw new RuntimeException('The administrator-only deletion guard is missing or not applied to the delete handler.');
        }
    });

    framsPaymentTest('duplicate manual form submission token is consumed once', static function (): void {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/lending_system/payments_lending.php');
        if (strpos($source, 'name="payment_submission_token"') === false
            || strpos($source, 'hash_equals($storedToken, $submittedToken)') === false
            || strpos($source, 'unset($_SESSION[\'payment_submission_token\'])') === false) {
            throw new RuntimeException('The one-time payment submission token is not wired to the form handler.');
        }
        $detailSource = (string)file_get_contents(dirname(__DIR__, 2) . '/lending_system/loan_details_lending.php');
        if (strpos($detailSource, 'csrfField()') === false
            || strpos($detailSource, 'name="payment_submission_token"') === false
            || strpos($detailSource, 'hash_equals($storedToken, $submittedToken)') === false
            || strpos($detailSource, 'unset($_SESSION[\'loan_details_payment_submission_token\'])') === false) {
            throw new RuntimeException('The loan-detail form is missing CSRF or one-time submission-token protection.');
        }
    });
} finally {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
}

$duplicateEventId = 'evt_payment_regression_' . bin2hex(random_bytes(8));
$internalReference = 'QRPH-REG-' . strtoupper(bin2hex(random_bytes(6)));
$paymentIntentId = 'pi_' . bin2hex(random_bytes(8));
$providerPaymentId = 'pay_' . bin2hex(random_bytes(8));
$qrTransactionId = null;
$duplicateEventPassed = false;
$duplicateEventDetails = 'Assertions passed.';
try {
    $loanDetails = executeQuery(
        'SELECT l.client_id, c.user_id AS borrower_user_id FROM loans l JOIN clients c ON c.client_id = l.client_id WHERE l.loan_id = ? LIMIT 1',
        [$loanId]
    )->fetch(PDO::FETCH_ASSOC);
    if (!$loanDetails || (int)$loanDetails['borrower_user_id'] <= 0) {
        throw new RuntimeException('The synthetic loan has no borrower account for the PayMongo event test.');
    }
    $amount = min(1.00, (float)calculateLoanBalanceSummary($loanId)['remaining_balance']);
    $amountCents = (int)round($amount * 100);
    if ($amountCents <= 0) {
        throw new RuntimeException('The synthetic loan has no centavo balance for the PayMongo event test.');
    }
    $insertTransaction = $conn->prepare(
        "INSERT INTO paymongo_qrph_transactions
            (internal_reference, payment_intent_id, loan_id, client_id, borrower_user_id,
             initiated_by_user_id, collector_id, selected_method, amount, penalty_paid,
             currency, status, provider_status, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'Bank Transfer', ?, 0.00, 'PHP', 'pending', 'awaiting_next_action', DATE_ADD(NOW(), INTERVAL 30 MINUTE))"
    );
    $collectorId = (int)($manifest['users']['frams_test_collector'] ?? 0);
    $insertTransaction->execute([
        $internalReference,
        $paymentIntentId,
        $loanId,
        (int)$loanDetails['client_id'],
        (int)$loanDetails['borrower_user_id'],
        $collectorId,
        $collectorId,
        number_format($amountCents / 100, 2, '.', ''),
    ]);
    $qrTransactionId = (int)$conn->lastInsertId();
    $rawBody = json_encode([
        'data' => [
            'id' => $duplicateEventId,
            'type' => 'event',
            'attributes' => [
                'type' => 'payment.paid',
                'livemode' => false,
                'data' => [
                    'id' => $providerPaymentId,
                    'type' => 'payment',
                    'attributes' => [
                        'status' => 'paid',
                        'payment_intent_id' => $paymentIntentId,
                        'amount' => $amountCents,
                        'currency' => 'PHP',
                        'livemode' => false,
                        'source' => ['type' => 'qrph'],
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    $event = lendingParsePaymongoWebhookEvent($rawBody);
    if ($event === null || lendingProcessPaymongoWebhookEvent($event, $rawBody) !== 'processed') {
        throw new RuntimeException('The verified local paid event did not post the synthetic payment.');
    }
    if (lendingProcessPaymongoWebhookEvent($event, $rawBody) !== 'duplicate') {
        throw new RuntimeException('A repeated paid event was not identified as a duplicate.');
    }
    $posted = executeQuery(
        'SELECT t.payment_id, COUNT(p.payment_id) AS payment_count
         FROM paymongo_qrph_transactions t
         LEFT JOIN payments p ON p.payment_id = t.payment_id
         WHERE t.id = ?
         GROUP BY t.id, t.payment_id',
        [$qrTransactionId]
    )->fetch(PDO::FETCH_ASSOC);
    if (!$posted || (int)$posted['payment_id'] <= 0 || (int)$posted['payment_count'] !== 1) {
        throw new RuntimeException('The duplicate paid event created a missing or duplicate payment record.');
    }
    try {
        deletePaymentTransaction((int)$posted['payment_id'], $loanId);
    } catch (RuntimeException $exception) {
        if (stripos($exception->getMessage(), 'PayMongo') === false) {
            throw $exception;
        }
    }
    if ((int)executeQuery('SELECT COUNT(*) FROM payments WHERE payment_id = ?', [(int)$posted['payment_id']])->fetchColumn() !== 1) {
        throw new RuntimeException('The linked successful PayMongo payment was deleted.');
    }
    $duplicateEventPassed = true;
} catch (Throwable $exception) {
    $duplicateEventDetails = $exception->getMessage();
} finally {
    try {
        $conn->beginTransaction();
        $cleanupEvent = $conn->prepare('DELETE FROM paymongo_qrph_webhook_events WHERE event_id = ?');
        $cleanupEvent->execute([$duplicateEventId]);
        if ($qrTransactionId !== null) {
            $paymentId = $conn->prepare('SELECT payment_id FROM paymongo_qrph_transactions WHERE id = ?');
            $paymentId->execute([$qrTransactionId]);
            $linkedPaymentId = $paymentId->fetchColumn();
            if ($linkedPaymentId !== false && $linkedPaymentId !== null) {
                $deletePayment = $conn->prepare('DELETE FROM payments WHERE payment_id = ?');
                $deletePayment->execute([(int)$linkedPaymentId]);
            }
            $deleteTransaction = $conn->prepare('DELETE FROM paymongo_qrph_transactions WHERE id = ? AND internal_reference = ?');
            $deleteTransaction->execute([$qrTransactionId, $internalReference]);
            recalculateLoanPaymentSchedule($loanId);
            updateLoanStatusForLoan($loanId);
        }
        $conn->commit();
    } catch (Throwable $cleanupException) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $duplicateEventPassed = false;
        $duplicateEventDetails = 'Test cleanup failed: ' . $cleanupException->getMessage();
    }
}
$results[] = [
    'case' => 'duplicate PayMongo webhook event is ignored',
    'status' => $duplicateEventPassed ? 'PASS' : 'FAIL',
    'details' => $duplicateEventDetails,
];
echo ($duplicateEventPassed ? 'PASS ' : 'FAIL ') . 'duplicate PayMongo webhook event is ignored'
    . ($duplicateEventPassed ? '' : ': ' . $duplicateEventDetails) . PHP_EOL;

$failedEventId = 'evt_payment_failed_regression_' . bin2hex(random_bytes(8));
$failedInternalReference = 'QRPH-FAIL-' . strtoupper(bin2hex(random_bytes(6)));
$failedIntentId = 'pi_' . bin2hex(random_bytes(8));
$failedProviderPaymentId = 'pay_' . bin2hex(random_bytes(8));
$failedTransactionId = null;
$failedEventPassed = false;
$failedEventDetails = 'Assertions passed.';
try {
    $loanDetails = executeQuery(
        'SELECT l.client_id, c.user_id AS borrower_user_id FROM loans l JOIN clients c ON c.client_id = l.client_id WHERE l.loan_id = ? LIMIT 1',
        [$loanId]
    )->fetch(PDO::FETCH_ASSOC);
    if (!$loanDetails || (int)$loanDetails['borrower_user_id'] <= 0) {
        throw new RuntimeException('The synthetic loan has no borrower account for the failed-event test.');
    }
    $amount = min(1.00, (float)calculateLoanBalanceSummary($loanId)['remaining_balance']);
    $amountCents = (int)round($amount * 100);
    if ($amountCents <= 0) {
        throw new RuntimeException('The synthetic loan has no centavo balance for the failed-event test.');
    }
    $insertTransaction = $conn->prepare(
        "INSERT INTO paymongo_qrph_transactions
            (internal_reference, payment_intent_id, loan_id, client_id, borrower_user_id,
             initiated_by_user_id, collector_id, selected_method, amount, penalty_paid,
             currency, status, provider_status, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'Bank Transfer', ?, 0.00, 'PHP', 'pending', 'awaiting_next_action', DATE_ADD(NOW(), INTERVAL 30 MINUTE))"
    );
    $collectorId = (int)($manifest['users']['frams_test_collector'] ?? 0);
    $insertTransaction->execute([
        $failedInternalReference,
        $failedIntentId,
        $loanId,
        (int)$loanDetails['client_id'],
        (int)$loanDetails['borrower_user_id'],
        $collectorId,
        $collectorId,
        number_format($amountCents / 100, 2, '.', ''),
    ]);
    $failedTransactionId = (int)$conn->lastInsertId();
    $failedRawBody = json_encode([
        'data' => [
            'id' => $failedEventId,
            'type' => 'event',
            'attributes' => [
                'type' => 'payment.failed',
                'livemode' => false,
                'data' => [
                    'id' => $failedProviderPaymentId,
                    'type' => 'payment',
                    'attributes' => [
                        'status' => 'failed',
                        'payment_intent_id' => $failedIntentId,
                        'amount' => $amountCents,
                        'currency' => 'PHP',
                        'livemode' => false,
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    $failedEvent = lendingParsePaymongoWebhookEvent($failedRawBody);
    if ($failedEvent === null || lendingProcessPaymongoWebhookEvent($failedEvent, $failedRawBody) !== 'processed') {
        throw new RuntimeException('The local failed event was not recorded as processed.');
    }
    $storedFailure = executeQuery(
        'SELECT t.status, t.payment_id, COUNT(p.payment_id) AS payment_count
         FROM paymongo_qrph_transactions t
         LEFT JOIN payments p ON p.payment_id = t.payment_id
         WHERE t.id = ?
         GROUP BY t.id, t.status, t.payment_id',
        [$failedTransactionId]
    )->fetch(PDO::FETCH_ASSOC);
    if (!$storedFailure
        || $storedFailure['status'] !== 'failed'
        || $storedFailure['payment_id'] !== null
        || (int)$storedFailure['payment_count'] !== 0) {
        throw new RuntimeException('A failed provider payment was posted or left in a misleading state.');
    }
    $failedEventPassed = true;
} catch (Throwable $exception) {
    $failedEventDetails = $exception->getMessage();
} finally {
    try {
        $conn->beginTransaction();
        $cleanupEvent = $conn->prepare('DELETE FROM paymongo_qrph_webhook_events WHERE event_id = ?');
        $cleanupEvent->execute([$failedEventId]);
        if ($failedTransactionId !== null) {
            $paymentId = $conn->prepare('SELECT payment_id FROM paymongo_qrph_transactions WHERE id = ?');
            $paymentId->execute([$failedTransactionId]);
            $linkedPaymentId = $paymentId->fetchColumn();
            if ($linkedPaymentId !== false && $linkedPaymentId !== null) {
                $deletePayment = $conn->prepare('DELETE FROM payments WHERE payment_id = ?');
                $deletePayment->execute([(int)$linkedPaymentId]);
                recalculateLoanPaymentSchedule($loanId);
                updateLoanStatusForLoan($loanId);
            }
            $deleteTransaction = $conn->prepare('DELETE FROM paymongo_qrph_transactions WHERE id = ? AND internal_reference = ?');
            $deleteTransaction->execute([$failedTransactionId, $failedInternalReference]);
        }
        $conn->commit();
    } catch (Throwable $cleanupException) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $failedEventPassed = false;
        $failedEventDetails = 'Test cleanup failed: ' . $cleanupException->getMessage();
    }
}
$results[] = [
    'case' => 'failed PayMongo event creates no payment',
    'status' => $failedEventPassed ? 'PASS' : 'FAIL',
    'details' => $failedEventDetails,
];
echo ($failedEventPassed ? 'PASS ' : 'FAIL ') . 'failed PayMongo event creates no payment'
    . ($failedEventPassed ? '' : ': ' . $failedEventDetails) . PHP_EOL;

$paymongoCases = [
    'invalid PayMongo webhook signature' => static function (): bool {
        $body = '{"data":{"id":"evt_regression","type":"event","attributes":{"type":"payment.paid"}}}';
        $timestamp = (string)time();
        $valid = hash_hmac('sha256', $timestamp . '.' . $body, 'whsk_isolated_frams_test_only');
        return !lendingVerifyPaymongoWebhookSignature($body, 't=' . $timestamp . ',te=' . $valid, 'whsk_wrong_test_secret');
    },
    'failed PayMongo event does not describe a paid transaction' => static function (): bool {
        $body = json_encode([
            'data' => [
                'id' => 'evt_failed_regression',
                'type' => 'event',
                'attributes' => [
                    'type' => 'payment.failed',
                    'livemode' => false,
                    'data' => [
                        'id' => 'pay_failed_regression',
                        'type' => 'payment',
                        'attributes' => ['status' => 'failed'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $event = lendingParsePaymongoWebhookEvent($body);
        return $event !== null
            && $event['type'] === 'payment.failed'
            && ($event['resource']['attributes']['status'] ?? '') === 'failed';
    },
    'unpaid PayMongo intent remains pending' => static function (): bool {
        $transaction = [
            'amount' => '125.00',
            'currency' => 'PHP',
            'qr_image_url' => '',
            'expires_at' => date('Y-m-d H:i:s', time() + 600),
        ];
        $intent = [
            'attributes' => [
                'amount' => 12500,
                'currency' => 'PHP',
                'livemode' => false,
                'status' => 'awaiting_payment_method',
                'payments' => [],
            ],
        ];
        return lendingPaymongoQrphInterpretIntent($transaction, $intent)['action'] === 'pending';
    },
];
foreach ($paymongoCases as $name => $case) {
    $passed = $case();
    $results[] = ['case' => $name, 'status' => $passed ? 'PASS' : 'FAIL', 'details' => $passed ? 'Assertions passed.' : 'Assertion failed.'];
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
}

$leftoverFixtures = (int)$conn->query(
    "SELECT
        (SELECT COUNT(*) FROM paymongo_qrph_transactions WHERE internal_reference LIKE 'QRPH-REG-%' OR internal_reference LIKE 'QRPH-FAIL-%')
        + (SELECT COUNT(*) FROM paymongo_qrph_webhook_events WHERE event_id LIKE 'evt_payment_regression_%' OR event_id LIKE 'evt_payment_failed_regression_%')
        + (SELECT COUNT(*) FROM payments WHERE remarks IN ('Regression transaction', 'Regression deletion transaction', 'Regression exact-balance transaction', 'Regression cumulative receipt'))"
)->fetchColumn();
$cleanupPassed = $leftoverFixtures === 0;
$results[] = [
    'case' => 'isolated regression fixtures cleaned up',
    'status' => $cleanupPassed ? 'PASS' : 'FAIL',
    'details' => $cleanupPassed ? 'No regression rows remain.' : $leftoverFixtures . ' temporary test rows remain.',
];
echo ($cleanupPassed ? 'PASS ' : 'FAIL ') . 'isolated regression fixtures cleaned up'
    . ($cleanupPassed ? '' : ': ' . $leftoverFixtures . ' temporary test rows remain.') . PHP_EOL;

$failed = count(array_filter($results, static fn(array $result): bool => $result['status'] === 'FAIL'));
echo 'Payment regression total: ' . count($results) . '; passed: ' . (count($results) - $failed) . '; failed: ' . $failed . PHP_EOL;
exit($failed === 0 ? 0 : 1);
