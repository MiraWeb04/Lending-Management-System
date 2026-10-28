<?php

require_once __DIR__ . '/paymongo_qrph.php';
require_once __DIR__ . '/loan_helpers.php';

function lendingStaffCanReconcilePaymongoQrph(int $loanId, int $staffUserId): bool
{
    if ($loanId <= 0 || $staffUserId <= 0) {
        return false;
    }
    if (!function_exists('isLoggedIn')
        || !function_exists('isAdmin')
        || !function_exists('isCollector')
        || !function_exists('ensureCollectorCanAccessLoan')) {
        return false;
    }
    if (!isLoggedIn() || (int)($_SESSION['user_id'] ?? 0) !== $staffUserId) {
        return false;
    }
    if (isAdmin()) {
        return true;
    }
    if (!isCollector()) {
        return false;
    }

    return ensureCollectorCanAccessLoan($loanId, $staffUserId);
}

function lendingPaymongoWebhookAuditUpdate(PDO $conn, string $eventId, ?int $transactionId, string $status, string $details): void
{
    $stmt = $conn->prepare(
        'UPDATE paymongo_qrph_webhook_events
         SET transaction_id = ?, processing_status = ?, details = ?, processed_at = NOW()
         WHERE event_id = ?'
    );
    $stmt->execute([$transactionId, $status, substr($details, 0, 1000), $eventId]);
}

function lendingPaymongoWebhookRetryPaymentEmail(PDO $conn, string $paymentIntentId): void
{
    if ($paymentIntentId === '') {
        return;
    }

    $statement = $conn->prepare(
        "SELECT t.loan_id, t.amount, p.receipt_number, l.loan_number, l.total_payable, l.status,
                CONCAT_WS(' ', c.first_name, c.last_name) AS borrower_name
         FROM paymongo_qrph_transactions t
         JOIN payments p ON p.payment_id = t.payment_id
         JOIN loans l ON l.loan_id = t.loan_id
         JOIN clients c ON c.client_id = l.client_id
         WHERE t.payment_intent_id = ? AND t.status = 'succeeded'
         LIMIT 1"
    );
    $statement->execute([$paymentIntentId]);
    $payment = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$payment || trim((string)($payment['receipt_number'] ?? '')) === '') {
        return;
    }

    try {
        sendRecordedPaymentEmail(
            (int)$payment['loan_id'],
            (float)$payment['amount'],
            (string)$payment['receipt_number'],
            $payment
        );
    } catch (Throwable $exception) {
        error_log('PayMongo repayment email retry failed after a duplicate event: ' . $exception->getMessage());
    }
}

function lendingPaymongoQrphStoredExpiryReached(array $transaction): bool
{
    global $conn;

    $expiresAt = trim((string)($transaction['expires_at'] ?? ''));
    if ($expiresAt === '') {
        return false;
    }

    if ($conn instanceof PDO) {
        $statement = $conn->prepare('SELECT (? <= NOW()) AS reached');
        $statement->execute([$expiresAt]);

        return (bool)$statement->fetchColumn();
    }

    $expiresTimestamp = strtotime($expiresAt);

    return $expiresTimestamp !== false && $expiresTimestamp <= time();
}

function lendingPaymongoWebhookMatchesTransaction(array $transaction, array $paymentAttributes): bool
{
    $expectedAmount = (int)round((float)$transaction['amount'] * 100);
    $actualAmount = $paymentAttributes['amount'] ?? null;
    $currency = strtoupper(trim((string)($paymentAttributes['currency'] ?? '')));
    $amountIsInteger = is_int($actualAmount)
        || (is_string($actualAmount) && ctype_digit($actualAmount));

    return $amountIsInteger
        && (int)$actualAmount === $expectedAmount
        && $currency === strtoupper((string)$transaction['currency'])
        && $currency === 'PHP';
}

function lendingPaymongoQrphInterpretIntent(array $transaction, array $intent): array
{
    $attributes = is_array($intent['attributes'] ?? null) ? $intent['attributes'] : [];
    $providerStatus = trim((string)($attributes['status'] ?? ''));
    $intentLivemode = $attributes['livemode'] ?? null;
    if (!is_bool($intentLivemode) || !lendingPaymongoProviderLivemodeMatchesConfiguration($intentLivemode)) {
        return ['action' => 'review', 'provider_status' => 'mode_mismatch_intent', 'provider_payment_id' => null];
    }

    $amount = $attributes['amount'] ?? null;
    $currency = strtoupper(trim((string)($attributes['currency'] ?? '')));
    $expectedAmount = (int)round((float)$transaction['amount'] * 100);
    $amountIsInteger = is_int($amount) || (is_string($amount) && ctype_digit($amount));
    $intentMatches = $amountIsInteger && (int)$amount === $expectedAmount && $currency === 'PHP';
    $payments = is_array($attributes['payments'] ?? null) ? $attributes['payments'] : [];
    $paidPayment = null;
    $failedPayment = false;

    foreach ($payments as $payment) {
        if (!is_array($payment)) {
            continue;
        }
        $paymentAttributes = is_array($payment['attributes'] ?? null) ? $payment['attributes'] : [];
        $paymentStatus = (string)($paymentAttributes['status'] ?? '');
        if (($payment['type'] ?? '') === 'payment' && $paymentStatus === 'failed') {
            $failedPayment = true;
        }
        if ($paidPayment !== null || ($payment['type'] ?? '') !== 'payment' || $paymentStatus !== 'paid') {
            continue;
        }
        $paymentLivemode = $paymentAttributes['livemode'] ?? null;
        if (($paymentAttributes['source']['type'] ?? '') === 'qrph'
            && is_bool($paymentLivemode)
            && lendingPaymongoProviderLivemodeMatchesConfiguration($paymentLivemode)
            && lendingPaymongoWebhookMatchesTransaction($transaction, $paymentAttributes)
            && trim((string)($payment['id'] ?? '')) !== '') {
            $paidPayment = $payment;
        }
    }

    if ($providerStatus === 'succeeded' || $paidPayment !== null) {
        if ($intentMatches && $providerStatus === 'succeeded' && $paidPayment !== null) {
            return [
                'action' => 'post',
                'provider_status' => 'succeeded',
                'provider_payment_id' => (string)$paidPayment['id'],
            ];
        }

        return ['action' => 'review', 'provider_status' => 'succeeded_unverified', 'provider_payment_id' => null];
    }

    $qrIssued = trim((string)($transaction['qr_image_url'] ?? '')) !== '';
    $expiryReached = lendingPaymongoQrphStoredExpiryReached($transaction);
    if ($qrIssued
        && $intentMatches
        && $expiryReached
        && $paidPayment === null
        && in_array($providerStatus, ['awaiting_payment_method', 'awaiting_next_action'], true)) {
        return ['action' => 'expire', 'provider_status' => 'expired', 'provider_payment_id' => null];
    }
    if ($qrIssued && $intentMatches && $failedPayment && $providerStatus === 'awaiting_payment_method') {
        return ['action' => 'fail', 'provider_status' => 'failed', 'provider_payment_id' => null];
    }

    return [
        'action' => 'pending',
        'provider_status' => $providerStatus !== '' ? $providerStatus : 'pending',
        'provider_payment_id' => null,
    ];
}

function lendingPaymongoWebhookCandidateIntentId(array $event): string
{
    $resource = is_array($event['resource'] ?? null) ? $event['resource'] : [];
    $attributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];
    $fromAttributes = trim((string)($attributes['payment_intent_id'] ?? ''));
    if (preg_match('/^pi_[A-Za-z0-9]+$/', $fromAttributes) === 1) {
        return $fromAttributes;
    }

    $resourceId = trim((string)($resource['id'] ?? ''));
    $eventType = (string)($event['type'] ?? '');
    if ((($resource['type'] ?? '') === 'payment_intent' || str_starts_with($eventType, 'payment_intent.'))
        && preg_match('/^pi_[A-Za-z0-9]+$/', $resourceId) === 1) {
        return $resourceId;
    }

    return '';
}

function lendingPaymongoQrphCollectorDisplayName(array $transaction): string
{
    $userId = (int)($transaction['collector_id'] ?? 0);
    if ($userId <= 0) {
        $userId = (int)($transaction['initiated_by_user_id'] ?? 0);
    }
    if ($userId <= 0) {
        return 'Unknown';
    }

    $row = executeQuery(
        'SELECT full_name, username FROM users WHERE user_id = ? LIMIT 1',
        [$userId]
    )->fetch(PDO::FETCH_ASSOC);
    $fullName = trim((string)($row['full_name'] ?? ''));
    if ($fullName !== '') {
        return $fullName;
    }

    $username = trim((string)($row['username'] ?? ''));
    return $username !== '' ? $username : 'Unknown';
}

function lendingPaymongoQrphPostLockedTransaction(array $transaction, string $providerPaymentId, string $eventId): string
{
    $collectorId = (int)($transaction['collector_id'] ?? 0);
    if ($collectorId <= 0) {
        $collectorId = (int)($transaction['initiated_by_user_id'] ?? 0);
    }
    $verificationNote = $eventId !== ''
        ? '; verified event ' . $eventId
        : '; verified by retrieving the Payment Intent';

    return recordPaymentTransaction(
        (int)$transaction['loan_id'],
        date('Y-m-d'),
        (float)$transaction['amount'],
        lendingPaymongoQrphCollectorDisplayName($transaction),
        $collectorId > 0 ? $collectorId : null,
        (int)$transaction['client_id'],
        (string)$transaction['selected_method'],
        $providerPaymentId,
        (float)$transaction['penalty_paid'],
        'PayMongo QR Ph ' . (string)$transaction['internal_reference'] . $verificationNote,
        (int)$transaction['id'],
        $providerPaymentId
    );
}

function lendingApplyPaymongoQrphDecision(PDO $conn, array $transaction, array $decision, string $eventId): array
{
    $transactionId = (int)$transaction['id'];
    $status = (string)$transaction['status'];
    $action = (string)($decision['action'] ?? 'pending');
    $providerStatus = substr((string)($decision['provider_status'] ?? ''), 0, 60);

    if ($action === 'post') {
        if ($status === 'succeeded') {
            $providerPaymentId = (string)($decision['provider_payment_id'] ?? '');
            $samePayment = $providerPaymentId !== ''
                && hash_equals((string)($transaction['provider_payment_id'] ?? ''), $providerPaymentId);
            if (!$samePayment) {
                $conn->prepare(
                    "UPDATE paymongo_qrph_transactions
                     SET status = 'review_required', provider_status = 'paid_conflict', updated_at = NOW()
                     WHERE id = ? AND status = 'succeeded'"
                )->execute([$transactionId]);
            }
            return ['result' => $samePayment ? 'ignored' : 'review_required', 'receipt' => null];
        }
        if (!in_array($status, ['pending', 'expired', 'failed'], true)) {
            return ['result' => 'review_required', 'receipt' => null];
        }
        try {
            validatePaymentAmounts(
                (int)$transaction['loan_id'],
                (float)$transaction['amount'],
                (float)$transaction['penalty_paid']
            );
        } catch (Throwable $exception) {
            $conn->prepare(
                "UPDATE paymongo_qrph_transactions
                 SET status = 'review_required', provider_status = 'paid_amount_requires_review', updated_at = NOW()
                 WHERE id = ? AND status IN ('pending', 'expired', 'failed')"
            )->execute([$transactionId]);
            return ['result' => 'review_required', 'receipt' => null];
        }

        $receiptNumber = lendingPaymongoQrphPostLockedTransaction(
            $transaction,
            (string)$decision['provider_payment_id'],
            $eventId
        );
        return ['result' => 'processed', 'receipt' => $receiptNumber];
    }

    if ($status !== 'pending') {
        return ['result' => 'ignored', 'receipt' => null];
    }

    if ($action === 'expire' || $action === 'fail' || $action === 'review') {
        $nextStatus = $action === 'expire' ? 'expired' : ($action === 'fail' ? 'failed' : 'review_required');
        $updated = $conn->prepare(
            "UPDATE paymongo_qrph_transactions
             SET status = ?, provider_status = ?, updated_at = NOW()
             WHERE id = ? AND status = 'pending'"
        );
        $updated->execute([$nextStatus, $providerStatus !== '' ? $providerStatus : $nextStatus, $transactionId]);
        return ['result' => $updated->rowCount() === 1 ? $nextStatus : 'ignored', 'receipt' => null];
    }

    if ($providerStatus !== '' && $providerStatus !== (string)($transaction['provider_status'] ?? '')) {
        $conn->prepare(
            "UPDATE paymongo_qrph_transactions SET provider_status = ?, updated_at = NOW() WHERE id = ? AND status = 'pending'"
        )->execute([$providerStatus, $transactionId]);
    }

    return ['result' => 'pending', 'receipt' => null];
}

function lendingSendPaymongoQrphReceiptEmail(int $loanId, float $amount, string $receiptNumber): void
{
    global $conn;

    if ($receiptNumber === '') {
        return;
    }

    $loanDetailsStmt = $conn->prepare(
        'SELECT l.loan_id, l.loan_number, l.total_payable, l.status,
                CONCAT_WS(" ", c.first_name, c.last_name) AS borrower_name
         FROM loans l JOIN clients c ON c.client_id = l.client_id
         WHERE l.loan_id = ? LIMIT 1'
    );
    $loanDetailsStmt->execute([$loanId]);
    $loanDetails = $loanDetailsStmt->fetch(PDO::FETCH_ASSOC);
    if (!$loanDetails) {
        return;
    }

    try {
        sendRecordedPaymentEmail($loanId, $amount, $receiptNumber, $loanDetails);
    } catch (Throwable $exception) {
        error_log('PayMongo repayment email failed after ledger commit: ' . $exception->getMessage());
    }
}

function lendingReconcilePaymongoQrphTransaction(int $transactionId): string
{
    global $conn;

    if ($transactionId <= 0 || !lendingPaymongoQrphTransactionsTableExists()) {
        return 'unavailable';
    }

    $existingStmt = $conn->prepare('SELECT * FROM paymongo_qrph_transactions WHERE id = ? LIMIT 1');
    $existingStmt->execute([$transactionId]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing || !in_array($existing['status'], ['pending', 'expired', 'failed'], true)) {
        return $existing ? (string)$existing['status'] : 'missing';
    }

    $intentId = trim((string)($existing['payment_intent_id'] ?? ''));
    if ($intentId === '') {
        if ((string)$existing['status'] !== 'pending' || trim((string)($existing['qr_image_url'] ?? '')) !== '') {
            return (string)$existing['status'];
        }
        $createdAt = strtotime((string)($existing['created_at'] ?? ''));
        if ($createdAt === false || $createdAt > time() - 120) {
            return 'pending';
        }

        $conn->beginTransaction();
        try {
            $lock = $conn->prepare('SELECT * FROM paymongo_qrph_transactions WHERE id = ? LIMIT 1 FOR UPDATE');
            $lock->execute([$transactionId]);
            $row = $lock->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row
                && $row['status'] === 'pending'
                && trim((string)($row['payment_intent_id'] ?? '')) === ''
                && trim((string)($row['qr_image_url'] ?? '')) === '') {
                $conn->prepare(
                    "UPDATE paymongo_qrph_transactions
                     SET status = 'failed', provider_status = 'creation_failed',
                         failure_message = 'The QR was not issued and no Payment Intent was stored.', updated_at = NOW()
                     WHERE id = ? AND status = 'pending'"
                )->execute([$transactionId]);
            }
            $conn->commit();
        } catch (Throwable $exception) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $exception;
        }

        return 'failed';
    }

    $intent = lendingPaymongoRetrievePaymentIntent($intentId);
    $decision = lendingPaymongoQrphInterpretIntent($existing, $intent);
    if ($decision['action'] === 'pending') {
        $providerStatus = substr((string)$decision['provider_status'], 0, 60);
        if ($existing['status'] === 'pending' && $providerStatus !== '' && $providerStatus !== (string)$existing['provider_status']) {
            $conn->prepare(
                "UPDATE paymongo_qrph_transactions SET provider_status = ?, updated_at = NOW() WHERE id = ? AND status = 'pending'"
            )->execute([$providerStatus, $transactionId]);
        }
        return (string)$existing['status'];
    }

    $conn->beginTransaction();
    $receiptNumber = null;
    $result = 'pending';
    try {
        $lockLoan = $conn->prepare('SELECT loan_id, client_id FROM loans WHERE loan_id = ? LIMIT 1 FOR UPDATE');
        $lockLoan->execute([(int)$existing['loan_id']]);
        $loan = $lockLoan->fetch(PDO::FETCH_ASSOC) ?: null;
        $lock = $conn->prepare('SELECT * FROM paymongo_qrph_transactions WHERE id = ? LIMIT 1 FOR UPDATE');
        $lock->execute([$transactionId]);
        $row = $lock->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            $conn->commit();
            return 'missing';
        }

        if ($decision['action'] === 'post' && (!$loan || (int)$loan['client_id'] !== (int)$row['client_id'])) {
            $conn->prepare(
                "UPDATE paymongo_qrph_transactions
                 SET status = 'review_required', provider_status = 'paid_borrower_mismatch', updated_at = NOW()
                 WHERE id = ? AND status IN ('pending', 'expired', 'failed')"
            )->execute([$transactionId]);
            $conn->commit();
            return 'review_required';
        }

        $applied = lendingApplyPaymongoQrphDecision($conn, $row, $decision, '');
        $result = (string)$applied['result'];
        $receiptNumber = $applied['receipt'];
        $conn->commit();
    } catch (Throwable $exception) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $exception;
    }

    if ($result === 'processed' && is_string($receiptNumber) && $receiptNumber !== '') {
        lendingSendPaymongoQrphReceiptEmail((int)$existing['loan_id'], (float)$existing['amount'], $receiptNumber);
    }

    return $result === 'processed' ? 'succeeded' : $result;
}

function lendingRefreshOpenPaymongoQrphTransactions(int $loanId): void
{
    global $conn;

    if ($loanId <= 0 || !lendingPaymongoQrphTransactionsTableExists()) {
        return;
    }

    $pending = $conn->prepare(
        "SELECT id FROM paymongo_qrph_transactions WHERE loan_id = ? AND status = 'pending' ORDER BY id ASC"
    );
    $pending->execute([$loanId]);
    foreach ($pending->fetchAll(PDO::FETCH_COLUMN) as $transactionId) {
        try {
            lendingReconcilePaymongoQrphTransaction((int)$transactionId);
        } catch (Throwable $exception) {
            error_log('PayMongo QR Ph refresh failed for transaction ' . (int)$transactionId . ': ' . $exception->getMessage());
        }
    }
}

function lendingSupersedePendingPaymongoQrphAttempt(int $transactionId, bool $forceClose = false, string $closeReason = ''): void
{
    global $conn;

    if ($transactionId <= 0 || !lendingPaymongoQrphTransactionsTableExists()) {
        return;
    }

    $statement = $conn->prepare('SELECT * FROM paymongo_qrph_transactions WHERE id = ? AND status = ? LIMIT 1');
    $statement->execute([$transactionId, 'pending']);
    $transaction = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$transaction) {
        return;
    }

    $intentId = trim((string)($transaction['payment_intent_id'] ?? ''));
    if ($intentId !== '') {
        try {
            $intent = lendingPaymongoRetrievePaymentIntent($intentId);
            $decision = lendingPaymongoQrphInterpretIntent($transaction, $intent);
            if ($decision['action'] === 'post') {
                lendingReconcilePaymongoQrphTransaction($transactionId);

                return;
            }
            if ($decision['action'] === 'review') {
                $providerStatus = substr((string)($decision['provider_status'] ?? 'review_required'), 0, 60);
                $conn->prepare(
                    "UPDATE paymongo_qrph_transactions
                     SET status = 'review_required', provider_status = ?, updated_at = NOW()
                     WHERE id = ? AND status = 'pending'"
                )->execute([$providerStatus, $transactionId]);

                return;
            }
        } catch (Throwable $exception) {
            error_log('PayMongo QR Ph supersede could not confirm provider status: ' . $exception->getMessage());
            if (!$forceClose && !lendingPaymongoQrphStoredExpiryReached($transaction)) {
                throw new RuntimeException(
                    'The previous PayMongo QR Ph attempt could not be confirmed with PayMongo. Try again in a moment or open the QR status page first.'
                );
            }
        }
    }

    $failureMessage = $closeReason !== ''
        ? $closeReason
        : 'Closed because a new PayMongo QR Ph payment was started for this loan.';

    $conn->prepare(
        "UPDATE paymongo_qrph_transactions
         SET status = 'expired', provider_status = 'superseded',
             failure_message = ?, updated_at = NOW()
         WHERE id = ? AND status = 'pending'"
    )->execute([$failureMessage, $transactionId]);
}

function lendingCloseOpenPaymongoQrphAttemptsForLoan(int $loanId, bool $forceClose, string $closeReason): void
{
    global $conn;

    if ($loanId <= 0 || !lendingPaymongoQrphTransactionsTableExists()) {
        return;
    }

    lendingRefreshOpenPaymongoQrphTransactions($loanId);

    $pending = $conn->prepare(
        "SELECT id FROM paymongo_qrph_transactions WHERE loan_id = ? AND status = 'pending' ORDER BY id ASC"
    );
    $pending->execute([$loanId]);
    foreach ($pending->fetchAll(PDO::FETCH_COLUMN) as $transactionId) {
        lendingSupersedePendingPaymongoQrphAttempt((int)$transactionId, $forceClose, $closeReason);
    }
}

function lendingPrepareLoanForNewPaymongoQrph(int $loanId): void
{
    lendingCloseOpenPaymongoQrphAttemptsForLoan(
        $loanId,
        false,
        'Closed because a new PayMongo QR Ph payment was started for this loan.'
    );
}

function lendingPrepareLoanForManualPaymentRecording(int $loanId): void
{
    lendingCloseOpenPaymongoQrphAttemptsForLoan(
        $loanId,
        true,
        'Closed because a manual payment was recorded for this loan.'
    );
}

function lendingProcessPaymongoWebhookEvent(array $event, string $rawBody): string
{
    global $conn;

    $eventId = (string)$event['id'];
    $eventType = (string)$event['type'];
    $resource = $event['resource'];
    $resourceAttributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];
    $paymentIntentId = trim((string)($resourceAttributes['payment_intent_id'] ?? ''));
    $providerPaymentId = trim((string)($resource['id'] ?? ''));
    $currency = trim((string)($resourceAttributes['currency'] ?? ''));
    $amountCentavos = is_numeric($resourceAttributes['amount'] ?? null)
        ? (int)$resourceAttributes['amount']
        : null;
    $providerStatus = trim((string)($resourceAttributes['status'] ?? ''));
    $livemode = is_bool($event['livemode']) ? (int)$event['livemode'] : null;
    $payloadHash = hash('sha256', $rawBody);
    $reconcileEventTypes = ['qrph.expired', 'payment_intent.awaiting_payment_method', 'payment_intent.succeeded'];
    $prefetchedIntent = null;
    if (in_array($eventType, $reconcileEventTypes, true) && $paymentIntentId === '') {
        $paymentIntentId = lendingPaymongoWebhookCandidateIntentId($event);
    }
    if (in_array($eventType, $reconcileEventTypes, true) && $paymentIntentId === '') {
        $methodId = trim((string)($resourceAttributes['payment_method_id'] ?? ''));
        if ($methodId === '' && ($resource['type'] ?? '') === 'payment_method') {
            $methodId = trim((string)($resource['id'] ?? ''));
        }
        if ($methodId !== '') {
            $methodLookup = $conn->prepare(
                'SELECT payment_intent_id FROM paymongo_qrph_transactions
                 WHERE payment_method_id = ? AND payment_intent_id IS NOT NULL LIMIT 1'
            );
            $methodLookup->execute([$methodId]);
            $foundIntentId = trim((string)$methodLookup->fetchColumn());
            if (preg_match('/^pi_[A-Za-z0-9]+$/', $foundIntentId) === 1) {
                $paymentIntentId = $foundIntentId;
            }
        }
    }
    if (in_array($eventType, $reconcileEventTypes, true) && $paymentIntentId !== '') {
        $prefetchedIntent = lendingPaymongoRetrievePaymentIntent($paymentIntentId);
    }

    if ($eventType === 'payment.paid' || ($prefetchedIntent !== null && (($prefetchedIntent['attributes']['status'] ?? '') === 'succeeded'))) {
        ensurePaymentReceiptColumns();
    }

    $conn->beginTransaction();
    try {
        $insertEvent = $conn->prepare(
            "INSERT IGNORE INTO paymongo_qrph_webhook_events
                (event_id, event_type, provider_payment_id, payment_intent_id, amount_centavos,
                 currency, provider_status, livemode, payload_sha256, signature_verified,
                 processing_status, details)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'ignored', 'Event received; not yet processed.')"
        );
        $insertEvent->execute([
            $eventId,
            $eventType,
            $providerPaymentId !== '' ? $providerPaymentId : null,
            $paymentIntentId !== '' ? $paymentIntentId : null,
            $amountCentavos,
            $currency !== '' ? $currency : null,
            $providerStatus !== '' ? $providerStatus : null,
            $livemode,
            $payloadHash,
        ]);

        if ($insertEvent->rowCount() === 0) {
            $conn->commit();
            if ($eventType === 'payment.paid') {
                lendingPaymongoWebhookRetryPaymentEmail($conn, $paymentIntentId);
            }
            return 'duplicate';
        }

        $transaction = null;
        $lockedLoan = null;
        if ($paymentIntentId !== '') {
            if ($eventType === 'payment.paid' || $prefetchedIntent !== null) {
                $lookupTransaction = $conn->prepare(
                    'SELECT loan_id FROM paymongo_qrph_transactions WHERE payment_intent_id = ? LIMIT 1'
                );
                $lookupTransaction->execute([$paymentIntentId]);
                $loanId = $lookupTransaction->fetchColumn();
                if ($loanId !== false) {
                    $lockLoan = $conn->prepare(
                        'SELECT loan_id, client_id FROM loans WHERE loan_id = ? LIMIT 1 FOR UPDATE'
                    );
                    $lockLoan->execute([(int)$loanId]);
                    $lockedLoan = $lockLoan->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                $findTransaction = $conn->prepare(
                    'SELECT * FROM paymongo_qrph_transactions WHERE payment_intent_id = ? LIMIT 1 FOR UPDATE'
                );
                $findTransaction->execute([$paymentIntentId]);
                $transaction = $findTransaction->fetch(PDO::FETCH_ASSOC) ?: null;
            } else {
                $findTransaction = $conn->prepare(
                    'SELECT * FROM paymongo_qrph_transactions WHERE payment_intent_id = ? LIMIT 1 FOR UPDATE'
                );
                $findTransaction->execute([$paymentIntentId]);
                $transaction = $findTransaction->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }

        if (!$transaction) {
            lendingPaymongoWebhookAuditUpdate($conn, $eventId, null, 'ignored', 'No internal transaction matched the provider identifiers.');
            $conn->commit();
            return 'ignored';
        }

        $transactionId = (int)$transaction['id'];
        lendingPaymongoWebhookAuditUpdate($conn, $eventId, $transactionId, 'ignored', 'Event was verified but did not change the transaction.');
        $eventLivemode = $livemode === 1;
        $resourceLivemode = ($resourceAttributes['livemode'] ?? null);
        $resourceLivemodeBool = is_bool($resourceLivemode) ? $resourceLivemode : null;
        if (!lendingPaymongoProviderLivemodeMatchesConfiguration($eventLivemode)
            || ($resourceLivemodeBool !== null && !lendingPaymongoProviderLivemodeMatchesConfiguration($resourceLivemodeBool))) {
            lendingPaymongoWebhookAuditUpdate(
                $conn,
                $eventId,
                $transactionId,
                'review_required',
                'The webhook event livemode does not match PAYMONGO_MODE on this server.'
            );
            $conn->commit();

            return 'review_required';
        }

        if ($eventType === 'payment.paid') {
            $isPaymentResource = ($resource['type'] ?? '') === 'payment';
            $isQrPhSource = (($resourceAttributes['source']['type'] ?? '') === 'qrph');
            $modeMatches = lendingPaymongoProviderLivemodeMatchesConfiguration($eventLivemode)
                && ($resourceLivemodeBool === null || lendingPaymongoProviderLivemodeMatchesConfiguration($resourceLivemodeBool));
            if (!$isPaymentResource
                || !$isQrPhSource
                || !$modeMatches
                || $providerPaymentId === ''
                || $paymentIntentId === ''
                || $providerStatus !== 'paid'
                || !lendingPaymongoWebhookMatchesTransaction($transaction, $resourceAttributes)) {
                lendingPaymongoWebhookAuditUpdate($conn, $eventId, $transactionId, 'review_required', 'Paid event failed resource, mode, status, or amount/currency verification.');
                if (in_array($transaction['status'], ['pending', 'expired', 'failed'], true)) {
                    $markReview = $conn->prepare(
                        "UPDATE paymongo_qrph_transactions SET status = 'review_required', provider_status = ?, updated_at = NOW()
                         WHERE id = ? AND status IN ('pending', 'expired', 'failed')"
                    );
                    $markReview->execute([$providerStatus !== '' ? $providerStatus : 'unverified_paid_event', $transactionId]);
                }
                $conn->commit();
                return 'review_required';
            }

            if ($transaction['status'] === 'succeeded') {
                $alreadyPosted = hash_equals((string)$transaction['provider_payment_id'], $providerPaymentId);
                lendingPaymongoWebhookAuditUpdate(
                    $conn,
                    $eventId,
                    $transactionId,
                    $alreadyPosted ? 'ignored' : 'review_required',
                    $alreadyPosted
                        ? 'Payment was already recorded for this transaction.'
                        : 'A different provider payment identifier was paid for an already completed transaction.'
                );
                $conn->commit();
                return $alreadyPosted ? 'duplicate' : 'review_required';
            }
            if (!in_array($transaction['status'], ['pending', 'expired', 'failed'], true)) {
                lendingPaymongoWebhookAuditUpdate($conn, $eventId, $transactionId, 'review_required', 'The matched internal transaction cannot accept another verified payment.');
                $conn->commit();
                return 'review_required';
            }

            $loan = $lockedLoan;
            if (!$loan || (int)$loan['client_id'] !== (int)$transaction['client_id']) {
                lendingPaymongoWebhookAuditUpdate($conn, $eventId, $transactionId, 'review_required', 'The transaction borrower no longer matches the loan.');
                $reviewTransaction = $conn->prepare(
                    "UPDATE paymongo_qrph_transactions SET status = 'review_required', provider_status = 'paid_borrower_mismatch', updated_at = NOW()
                     WHERE id = ? AND status IN ('pending', 'expired', 'failed')"
                );
                $reviewTransaction->execute([$transactionId]);
                $conn->commit();
                return 'review_required';
            }

            try {
                validatePaymentAmounts(
                    (int)$transaction['loan_id'],
                    (float)$transaction['amount'],
                    (float)$transaction['penalty_paid']
                );
            } catch (Throwable $exception) {
                lendingPaymongoWebhookAuditUpdate($conn, $eventId, $transactionId, 'review_required', 'The verified provider payment exceeds the current loan amount due.');
                $reviewTransaction = $conn->prepare(
                    "UPDATE paymongo_qrph_transactions SET status = 'review_required', provider_status = 'paid_amount_requires_review', updated_at = NOW()
                     WHERE id = ? AND status IN ('pending', 'expired', 'failed')"
                );
                $reviewTransaction->execute([$transactionId]);
                $conn->commit();
                return 'review_required';
            }

            $receiptNumber = lendingPaymongoQrphPostLockedTransaction($transaction, $providerPaymentId, $eventId);

            $conn->prepare(
                "UPDATE paymongo_qrph_webhook_events SET processing_status = 'processed', details = ?, processed_at = NOW()
                 WHERE event_id = ?"
            )->execute(['Repayment recorded with receipt ' . $receiptNumber . '.', $eventId]);
            $conn->commit();

            lendingSendPaymongoQrphReceiptEmail(
                (int)$transaction['loan_id'],
                (float)$transaction['amount'],
                (string)$receiptNumber
            );

            return 'processed';
        }

        if ($eventType === 'payment.failed') {
            if (($resource['type'] ?? '') !== 'payment'
                || $providerStatus !== 'failed'
                || !lendingPaymongoWebhookMatchesTransaction($transaction, $resourceAttributes)) {
                lendingPaymongoWebhookAuditUpdate($conn, $eventId, $transactionId, 'review_required', 'Failed event failed resource, status, or amount/currency verification.');
            } elseif ($transaction['status'] === 'pending') {
                $failed = $conn->prepare(
                    "UPDATE paymongo_qrph_transactions SET status = 'failed', provider_status = 'failed', updated_at = NOW()
                     WHERE id = ? AND status = 'pending'"
                );
                $failed->execute([$transactionId]);
                lendingPaymongoWebhookAuditUpdate($conn, $eventId, $transactionId, 'processed', 'The pending payment attempt was marked failed; no repayment was posted.');
            } else {
                lendingPaymongoWebhookAuditUpdate($conn, $eventId, $transactionId, 'ignored', 'The payment attempt is no longer pending; its status was not changed.');
            }
        } elseif (in_array($eventType, $reconcileEventTypes, true)) {
            if ($prefetchedIntent === null) {
                lendingPaymongoWebhookAuditUpdate(
                    $conn,
                    $eventId,
                    $transactionId,
                    'ignored',
                    'The verified event did not include a Payment Intent ID that matches this QR transaction. Status was not changed.'
                );
            } else {
                $decision = lendingPaymongoQrphInterpretIntent($transaction, $prefetchedIntent);
                if ($decision['action'] === 'post' && (!$lockedLoan || (int)$lockedLoan['client_id'] !== (int)$transaction['client_id'])) {
                    $conn->prepare(
                        "UPDATE paymongo_qrph_transactions
                         SET status = 'review_required', provider_status = 'paid_borrower_mismatch', updated_at = NOW()
                         WHERE id = ? AND status IN ('pending', 'expired', 'failed')"
                    )->execute([$transactionId]);
                    lendingPaymongoWebhookAuditUpdate($conn, $eventId, $transactionId, 'review_required', 'The retrieved payment no longer matches the loan borrower.');
                    $conn->commit();
                    return 'review_required';
                }
                $applied = lendingApplyPaymongoQrphDecision($conn, $transaction, $decision, $eventId);
                $appliedResult = (string)$applied['result'];
                $auditStatus = in_array($appliedResult, ['processed', 'expired', 'failed'], true)
                    ? ($appliedResult === 'expired' || $appliedResult === 'failed' ? 'processed' : 'processed')
                    : ($appliedResult === 'review_required' ? 'review_required' : 'ignored');
                $auditDetail = match ($appliedResult) {
                    'processed' => 'Repayment recorded with receipt ' . (string)$applied['receipt'] . '.',
                    'expired' => 'PayMongo Payment Intent returned to awaiting_payment_method after the QR validity window. No repayment was posted.',
                    'failed' => 'PayMongo reported a failed payment and no successful QR Ph payment. No repayment was posted.',
                    'review_required' => 'The retrieved Payment Intent could not be posted automatically.',
                    default => 'PayMongo still reports this QR as unresolved. Status was left pending.',
                };
                lendingPaymongoWebhookAuditUpdate($conn, $eventId, $transactionId, $auditStatus, $auditDetail);
                $conn->commit();
                if ($appliedResult === 'processed' && is_string($applied['receipt']) && $applied['receipt'] !== '') {
                    lendingSendPaymongoQrphReceiptEmail((int)$transaction['loan_id'], (float)$transaction['amount'], $applied['receipt']);
                }
                return match ($appliedResult) {
                    'processed', 'expired', 'failed' => 'processed',
                    'review_required' => 'review_required',
                    default => 'ignored',
                };
            }
        } else {
            lendingPaymongoWebhookAuditUpdate($conn, $eventId, $transactionId, 'ignored', 'Event type is not handled by the repayment webhook.');
        }

        $conn->commit();
        return 'processed';
    } catch (Throwable $exception) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $exception;
    }
}
