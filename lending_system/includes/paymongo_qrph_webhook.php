<?php

require_once __DIR__ . '/paymongo_qrph.php';
require_once __DIR__ . '/loan_helpers.php';

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

    if ($eventType === 'payment.paid') {
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
            if ($eventType === 'payment.paid') {
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
        if ($livemode !== 0) {
            lendingPaymongoWebhookAuditUpdate($conn, $eventId, $transactionId, 'review_required', 'Live-mode events are not accepted while PayMongo is configured for test mode.');
            $conn->commit();
            return 'review_required';
        }

        if ($eventType === 'payment.paid') {
            $isPaymentResource = ($resource['type'] ?? '') === 'payment';
            $isQrPhSource = (($resourceAttributes['source']['type'] ?? '') === 'qrph');
            $isTestMode = $livemode === 0
                && (($resourceAttributes['livemode'] ?? false) === false);
            if (!$isPaymentResource
                || !$isQrPhSource
                || !$isTestMode
                || $providerPaymentId === ''
                || $paymentIntentId === ''
                || $providerStatus !== 'paid'
                || !lendingPaymongoWebhookMatchesTransaction($transaction, $resourceAttributes)) {
                lendingPaymongoWebhookAuditUpdate($conn, $eventId, $transactionId, 'review_required', 'Paid event failed resource, test-mode, status, or amount/currency verification.');
                if ($transaction['status'] === 'pending') {
                    $markReview = $conn->prepare(
                        "UPDATE paymongo_qrph_transactions SET status = 'review_required', provider_status = ?, updated_at = NOW()
                         WHERE id = ? AND status = 'pending'"
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
            if ($transaction['status'] !== 'pending') {
                lendingPaymongoWebhookAuditUpdate($conn, $eventId, $transactionId, 'review_required', 'The matched internal transaction is not pending.');
                $conn->commit();
                return 'review_required';
            }

            $loan = $lockedLoan;
            if (!$loan || (int)$loan['client_id'] !== (int)$transaction['client_id']) {
                lendingPaymongoWebhookAuditUpdate($conn, $eventId, $transactionId, 'review_required', 'The transaction borrower no longer matches the loan.');
                $reviewTransaction = $conn->prepare(
                    "UPDATE paymongo_qrph_transactions SET status = 'review_required', provider_status = 'paid_borrower_mismatch', updated_at = NOW()
                     WHERE id = ? AND status = 'pending'"
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
                     WHERE id = ? AND status = 'pending'"
                );
                $reviewTransaction->execute([$transactionId]);
                $conn->commit();
                return 'review_required';
            }

            $receiptNumber = recordPaymentTransaction(
                (int)$transaction['loan_id'],
                date('Y-m-d'),
                (float)$transaction['amount'],
                'PayMongo QR Ph',
                null,
                (int)$transaction['client_id'],
                (string)$transaction['selected_method'],
                $providerPaymentId,
                (float)$transaction['penalty_paid'],
                'PayMongo QR Ph ' . (string)$transaction['internal_reference'] . '; verified event ' . $eventId,
                $transactionId,
                $providerPaymentId
            );

            $conn->prepare(
                "UPDATE paymongo_qrph_webhook_events SET processing_status = 'processed', details = ?, processed_at = NOW()
                 WHERE event_id = ?"
            )->execute(['Repayment recorded with receipt ' . $receiptNumber . '.', $eventId]);
            $conn->commit();

            $loanDetailsStmt = $conn->prepare(
                'SELECT l.loan_id, l.loan_number, l.total_payable, l.status,
                        CONCAT_WS(" ", c.first_name, c.last_name) AS borrower_name
                 FROM loans l JOIN clients c ON c.client_id = l.client_id
                 WHERE l.loan_id = ? LIMIT 1'
            );
            $loanDetailsStmt->execute([(int)$transaction['loan_id']]);
            $loanDetails = $loanDetailsStmt->fetch(PDO::FETCH_ASSOC);
            if ($loanDetails) {
                try {
                    sendRecordedPaymentEmail(
                        (int)$transaction['loan_id'],
                        (float)$transaction['amount'],
                        (string)$receiptNumber,
                        $loanDetails
                    );
                } catch (Throwable $exception) {
                    error_log('PayMongo repayment email failed after ledger commit: ' . $exception->getMessage());
                }
            }

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
        } elseif ($eventType === 'qrph.expired') {
            lendingPaymongoWebhookAuditUpdate(
                $conn,
                $eventId,
                $transactionId,
                'ignored',
                'PayMongo does not document a qrph.expired webhook resource shape in the current official event reference; transaction status was not changed.'
            );
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
