<?php

require_once __DIR__ . '/env_lending.php';
require_once __DIR__ . '/loan_helpers.php';

const LENDING_PAYMONGO_QRPH_EXPIRY_SECONDS = 1800;
const LENDING_PAYMONGO_WEBHOOK_TOLERANCE_SECONDS = 300;

function lendingPaymongoConfiguredMode(): string
{
    $mode = strtolower(trim((string)lendingEnv('PAYMONGO_MODE', '')));

    return $mode === 'live' ? 'live' : 'test';
}

function lendingPaymongoExpectsLivemode(): bool
{
    return lendingPaymongoConfiguredMode() === 'live';
}

function lendingPaymongoProviderLivemodeMatchesConfiguration(?bool $providerLivemode): bool
{
    if ($providerLivemode === null) {
        return false;
    }

    return lendingPaymongoExpectsLivemode() ? $providerLivemode : !$providerLivemode;
}

function lendingPaymongoSecretKey(): string
{
    $secretKey = trim((string)lendingEnv('PAYMONGO_SECRET_KEY', ''));
    if (lendingPaymongoConfiguredMode() === 'live') {
        if (!str_starts_with($secretKey, 'sk_live_')) {
            throw new RuntimeException('PayMongo live mode requires a valid live secret key (sk_live_).');
        }
    } elseif (!str_starts_with($secretKey, 'sk_test_')) {
        throw new RuntimeException('PayMongo test mode requires a valid test secret key (sk_test_).');
    }

    return $secretKey;
}

function lendingPaymongoPublicKey(): string
{
    $publicKey = trim((string)lendingEnv('PAYMONGO_PUBLIC_KEY', ''));
    if (lendingPaymongoConfiguredMode() === 'live') {
        if (!str_starts_with($publicKey, 'pk_live_')) {
            throw new RuntimeException('PayMongo live mode requires a valid live public key (pk_live_).');
        }
    } elseif (!str_starts_with($publicKey, 'pk_test_')) {
        throw new RuntimeException('PayMongo test mode requires a valid test public key (pk_test_).');
    }

    return $publicKey;
}

function lendingPaymongoQrphRequest(string $method, string $path, array $payload, string $apiKey): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for PayMongo payments.');
    }

    $curl = curl_init('https://api.paymongo.com/v1/' . ltrim($path, '/'));
    if ($curl === false) {
        throw new RuntimeException('Could not initialize a secure PayMongo request.');
    }

    $httpMethod = strtoupper($method);
    $curlOptions = [
        CURLOPT_CUSTOMREQUEST => $httpMethod,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($apiKey . ':'),
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($httpMethod !== 'GET') {
        $curlOptions[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
    }
    curl_setopt_array($curl, $curlOptions);

    $responseBody = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($responseBody === false) {
        error_log('PayMongo QR Ph request failed: ' . $curlError);
        throw new RuntimeException('PayMongo could not be reached. No payment was recorded.');
    }

    try {
        $response = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        error_log('PayMongo QR Ph returned invalid JSON (HTTP ' . $httpStatus . ').');
        throw new RuntimeException('PayMongo returned an invalid response. No payment was recorded.');
    }

    if ($httpStatus < 200 || $httpStatus >= 300 || !is_array($response)) {
        $providerErrors = $response['errors'] ?? [];
        $messages = [];
        foreach ($providerErrors as $providerError) {
            $detail = trim((string)($providerError['detail'] ?? $providerError['code'] ?? ''));
            if ($detail !== '') {
                $messages[] = $detail;
            }
        }
        $providerMessage = $messages !== [] ? implode(' ', $messages) : 'The provider rejected the request.';
        error_log('PayMongo QR Ph API error (HTTP ' . $httpStatus . '): ' . $providerMessage);
        $action = $httpMethod === 'GET' ? 'confirm the QR payment' : 'create the QR payment';
        throw new RuntimeException('PayMongo could not ' . $action . ' (HTTP ' . $httpStatus . '). ' . $providerMessage);
    }

    return $response;
}

function lendingPaymongoQrphImageIsSafe(string $imageUrl): bool
{
    if (preg_match('#^data:image/(png|jpeg|jpg|webp);base64,[A-Za-z0-9+/=]+$#i', $imageUrl) === 1) {
        return true;
    }

    $parts = parse_url($imageUrl);
    $host = strtolower((string)($parts['host'] ?? ''));
    return ($parts['scheme'] ?? '') === 'https'
        && ($host === 'paymongo.com' || str_ends_with($host, '.paymongo.com'))
        && !isset($parts['user'])
        && !isset($parts['pass']);
}

function lendingPaymongoQrphTransactionsTableExists(): bool
{
    global $conn;

    $statement = $conn->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $statement->execute(['paymongo_qrph_transactions']);
    return (int)$statement->fetchColumn() > 0;
}

function lendingAssertNoOpenPaymongoQrphTransaction(PDO $connection, int $loanId, ?int $exceptTransactionId = null): void
{
    if (!lendingPaymongoQrphTransactionsTableExists()) {
        return;
    }

    $sql = "SELECT id, status, internal_reference FROM paymongo_qrph_transactions
            WHERE loan_id = ? AND status IN ('pending', 'review_required')";
    $parameters = [$loanId];
    if ($exceptTransactionId !== null) {
        $sql .= ' AND id <> ?';
        $parameters[] = $exceptTransactionId;
    }
    $sql .= ' LIMIT 1 FOR UPDATE';

    $statement = $connection->prepare($sql);
    $statement->execute($parameters);
    $openAttempt = $statement->fetch(PDO::FETCH_ASSOC);
    if ($openAttempt) {
        $transactionId = (int)$openAttempt['id'];
        $status = (string)$openAttempt['status'];
        $reference = trim((string)$openAttempt['internal_reference']);
        $detail = $status === 'review_required'
            ? 'An earlier QR Ph attempt needs administrator review before another payment can be recorded.'
            : 'Finish or refresh the open QR Ph attempt before starting another one. If the QR was not paid, open the QR status page and wait until PayMongo marks it expired.';
        throw new RuntimeException(
            'This loan already has an unresolved PayMongo QR Ph attempt'
            . ($reference !== '' ? ' (' . $reference . ')' : '')
            . '. ' . $detail
            . ' Open payments_lending.php?qrph=' . $transactionId . ' to check its status.'
        );
    }
}

function lendingVerifyPaymongoWebhookSignature(string $rawBody, string $signatureHeader, string $webhookSecret, ?string $signatureMode = null): bool
{
    if ($rawBody === '' || $signatureHeader === '' || $webhookSecret === '') {
        return false;
    }

    $signatureParts = [];
    foreach (explode(',', $signatureHeader) as $component) {
        $pair = explode('=', trim($component), 2);
        if (count($pair) === 2) {
            $signatureParts[trim($pair[0])] = trim($pair[1]);
        }
    }

    $timestamp = $signatureParts['t'] ?? '';
    if ($timestamp === '' || !ctype_digit($timestamp)) {
        return false;
    }

    $ageSeconds = abs(time() - (int)$timestamp);
    if ($ageSeconds > LENDING_PAYMONGO_WEBHOOK_TOLERANCE_SECONDS) {
        return false;
    }

    $expectedSignature = hash_hmac('sha256', $timestamp . '.' . $rawBody, $webhookSecret);
    $configuredMode = $signatureMode ?? lendingPaymongoConfiguredMode();
    if ($configuredMode === 'live') {
        $liveSignature = strtolower($signatureParts['li'] ?? '');

        return preg_match('/^[a-f0-9]{64}$/', $liveSignature) === 1
            && hash_equals($expectedSignature, $liveSignature);
    }

    $testSignature = strtolower($signatureParts['te'] ?? '');

    return preg_match('/^[a-f0-9]{64}$/', $testSignature) === 1
        && hash_equals($expectedSignature, $testSignature);
}

function lendingPaymongoTestModeSecretKey(): string
{
    return lendingPaymongoSecretKey();
}

function lendingPaymongoRetrievePaymentIntent(string $paymentIntentId): array
{
    if (!preg_match('/^pi_[A-Za-z0-9]+$/', $paymentIntentId)) {
        throw new InvalidArgumentException('PayMongo did not provide a usable Payment Intent ID.');
    }

    $response = lendingPaymongoQrphRequest(
        'GET',
        '/payment_intents/' . rawurlencode($paymentIntentId),
        [],
        lendingPaymongoSecretKey()
    );
    $intent = $response['data'] ?? null;
    if (!is_array($intent) || ($intent['type'] ?? '') !== 'payment_intent') {
        throw new RuntimeException('PayMongo did not return a Payment Intent.');
    }

    return $intent;
}

function lendingParsePaymongoWebhookEvent(string $rawBody): ?array
{
    try {
        $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return null;
    }

    if (!is_array($event)) {
        return null;
    }

    $eventData = $event['data'] ?? null;
    $attributes = is_array($eventData) ? ($eventData['attributes'] ?? null) : null;
    if (!is_array($eventData) || ($eventData['type'] ?? '') !== 'event' || !is_array($attributes)) {
        return null;
    }

    $eventId = trim((string)($eventData['id'] ?? ''));
    $eventType = trim((string)($attributes['type'] ?? ''));
    if ($eventId === '' || $eventType === '') {
        return null;
    }

    return [
        'id' => $eventId,
        'type' => $eventType,
        'livemode' => $attributes['livemode'] ?? null,
        'resource' => is_array($attributes['data'] ?? null) ? $attributes['data'] : [],
        'raw' => $event,
    ];
}

function lendingCreatePaymongoQrphTransaction(
    int $loanId,
    int $collectorUserId,
    string $paymentMethodLabel,
    mixed $requestedAmount,
    mixed $requestedPenalty
): int {
    global $conn;

    if (!in_array($paymentMethodLabel, ['Bank Transfer', 'E-Wallet'], true)) {
        throw new InvalidArgumentException('Dynamic QR Ph is available for Bank Transfer and E-Wallet only.');
    }

    if ($loanId <= 0 || $collectorUserId <= 0) {
        throw new InvalidArgumentException('Select a valid loan before creating a QR payment.');
    }

    $initiator = executeQuery(
        'SELECT role, status FROM users WHERE user_id = ? LIMIT 1',
        [$collectorUserId]
    )->fetch(PDO::FETCH_ASSOC);
    if (!$initiator || !isActiveAccount($initiator)) {
        throw new RuntimeException('Only authorized staff can initiate PayMongo QR Ph payments.');
    }
    $initiatorRole = trim((string)($initiator['role'] ?? ''));
    $initiatorIsCollector = strcasecmp($initiatorRole, 'Collector') === 0;
    $initiatorIsAdmin = strcasecmp($initiatorRole, 'Admin') === 0;
    if (!$initiatorIsCollector && !$initiatorIsAdmin) {
        throw new RuntimeException('Only authorized staff can initiate PayMongo QR Ph payments.');
    }

    $secretKey = lendingPaymongoSecretKey();
    $publicKey = lendingPaymongoPublicKey();

    if (!is_numeric($requestedAmount) || !is_finite((float)$requestedAmount)) {
        throw new InvalidArgumentException('Enter a valid payment amount.');
    }
    $rawAmount = (float)$requestedAmount;
    $amount = round($rawAmount, 2);
    if ($rawAmount < 0 || abs($rawAmount - $amount) > 0.000001) {
        throw new InvalidArgumentException('Payment amount must be nonnegative and cannot have more than two decimal places.');
    }
    $amountCentavos = (int)round($amount * 100);
    if ($amountCentavos <= 0) {
        throw new InvalidArgumentException('Payment amount must be greater than zero.');
    }
    $amount = $amountCentavos / 100;

    if (lendingPaymongoConfiguredMode() === 'live') {
        $liveMaxAmount = trim((string)lendingEnv('PAYMONGO_LIVE_MAX_AMOUNT', ''));
        if ($liveMaxAmount !== '' && is_numeric($liveMaxAmount) && is_finite((float)$liveMaxAmount)) {
            $liveMax = round((float)$liveMaxAmount, 2);
            if ($liveMax > 0 && $amount > $liveMax + 0.009) {
                throw new InvalidArgumentException(
                    'Live QR Ph is capped at ₱' . number_format($liveMax, 2)
                    . ' while PAYMONGO_LIVE_MAX_AMOUNT is set. Lower the amount or remove the cap after go-live testing.'
                );
            }
        }
    }

    if (!is_numeric($requestedPenalty) || !is_finite((float)$requestedPenalty)) {
        throw new InvalidArgumentException('Enter a valid penalty portion.');
    }
    $rawPenalty = (float)$requestedPenalty;
    $penaltyPaid = round($rawPenalty, 2);
    if (abs($rawPenalty - $penaltyPaid) > 0.000001) {
        throw new InvalidArgumentException('Penalty portion cannot have more than two decimal places.');
    }
    if ($penaltyPaid < 0 || $penaltyPaid > $amount) {
        throw new InvalidArgumentException('Penalty portion must be between zero and the payment amount.');
    }

    if ($initiatorIsCollector) {
        $assignmentCheck = executeQuery(
            'SELECT l.loan_id FROM loans l
             JOIN clients c ON l.client_id = c.client_id
             WHERE l.loan_id = ? AND (c.collector_id = ? OR l.collector_id = ?)
             LIMIT 1',
            [$loanId, $collectorUserId, $collectorUserId]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$assignmentCheck) {
            throw new RuntimeException('You are not assigned to this loan.');
        }
    }

    require_once __DIR__ . '/paymongo_qrph_webhook.php';
    lendingPrepareLoanForNewPaymongoQrph($loanId);

    $conn->beginTransaction();
    try {
        $loanLock = $conn->prepare('SELECT loan_id FROM loans WHERE loan_id = ? LIMIT 1 FOR UPDATE');
        $loanLock->execute([$loanId]);
        if (!$loanLock->fetchColumn()) {
            throw new InvalidArgumentException('The selected loan does not exist.');
        }
        lendingAssertNoOpenPaymongoQrphTransaction($conn, $loanId);

        $loan = executeQuery(
            'SELECT l.loan_id, l.loan_number, l.status, l.client_id, c.first_name, c.last_name,
                    COALESCE(c.user_id, lr.borrower_user_id, la.user_id) AS borrower_user_id,
                    u.role AS borrower_role, u.status AS borrower_status
             FROM loans l
             JOIN clients c ON c.client_id = l.client_id
             LEFT JOIN loan_releases lr ON lr.id = l.release_id
             LEFT JOIN loan_applications la ON la.id = lr.application_id
             LEFT JOIN users u ON u.user_id = COALESCE(c.user_id, lr.borrower_user_id, la.user_id)
             WHERE l.loan_id = ?
             LIMIT 1',
            [$loanId]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$loan) {
            throw new InvalidArgumentException('The selected loan does not exist.');
        }

        $borrowerAccount = [
            'user_id' => $loan['borrower_user_id'] ?? null,
            'role' => $loan['borrower_role'] ?? '',
            'status' => $loan['borrower_status'] ?? '',
        ];
        if ((int)($loan['borrower_user_id'] ?? 0) <= 0
            || !userIsBorrowerAccount($borrowerAccount)
            || !isActiveAccount($borrowerAccount)) {
            throw new InvalidArgumentException('The selected loan does not have a valid active borrower account.');
        }
        if (strcasecmp(trim((string)($loan['status'] ?? '')), 'Paid') === 0) {
            throw new InvalidArgumentException('This loan has already been paid.');
        }

        $balance = calculateLoanBalanceSummary($loanId);
        $remainingBalance = round((float)($balance['remaining_balance'] ?? 0), 2);
        $penaltyDue = round((float)($balance['penalty_due'] ?? 0), 2);
        $totalAmountDue = round((float)($balance['total_amount_due'] ?? 0), 2);
        if ($remainingBalance <= 0 && $penaltyDue <= 0) {
            throw new InvalidArgumentException('There is no outstanding amount due for this loan.');
        }
        if ($amount > $totalAmountDue + 0.009) {
            throw new InvalidArgumentException('Payment cannot exceed the current total amount due of ₱' . number_format($totalAmountDue, 2) . '.');
        }
        if ($penaltyPaid > $penaltyDue + 0.009) {
            throw new InvalidArgumentException('Penalty portion cannot exceed the current penalty due of ₱' . number_format($penaltyDue, 2) . '.');
        }
        if (($amount - min($penaltyPaid, $penaltyDue)) > $remainingBalance + 0.009) {
            throw new InvalidArgumentException('The loan portion cannot exceed the current outstanding balance of ₱' . number_format($remainingBalance, 2) . '.');
        }

        $internalReference = 'QRPH-' . strtoupper(bin2hex(random_bytes(8)));
        $insert = $conn->prepare(
            "INSERT INTO paymongo_qrph_transactions
                (internal_reference, loan_id, client_id, borrower_user_id, initiated_by_user_id, collector_id,
                 selected_method, amount, penalty_paid, currency, status, provider_status, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PHP', 'pending', 'initializing', DATE_ADD(NOW(), INTERVAL ? SECOND))"
        );
        try {
            $insert->execute([
                $internalReference,
                $loanId,
                (int)$loan['client_id'],
                (int)$loan['borrower_user_id'],
                $collectorUserId,
                $collectorUserId,
                $paymentMethodLabel,
                number_format($amount, 2, '.', ''),
                number_format($penaltyPaid, 2, '.', ''),
                LENDING_PAYMONGO_QRPH_EXPIRY_SECONDS,
            ]);
        } catch (PDOException $exception) {
            error_log('Could not create pending PayMongo QR Ph transaction: ' . $exception->getMessage());
            throw new RuntimeException('Could not store the pending PayMongo QR Ph transaction. Confirm the migration has been applied.', 0, $exception);
        }
    } catch (Throwable $exception) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $exception;
    }
    $transactionId = (int)$conn->lastInsertId();
    $conn->commit();
    $createdIntentId = null;

    try {
        $loanReference = trim((string)($loan['loan_number'] ?? ''));
        if ($loanReference === '') {
            $loanReference = 'Loan #' . $loanId;
        }
        $borrowerName = trim((string)$loan['first_name'] . ' ' . (string)$loan['last_name']);

        $intentResponse = lendingPaymongoQrphRequest('POST', '/payment_intents', [
            'data' => [
                'attributes' => [
                    'amount' => $amountCentavos,
                    'currency' => 'PHP',
                    'payment_method_allowed' => ['qrph'],
                    'description' => 'Loan repayment ' . $loanReference . ' - ' . $borrowerName,
                    'metadata' => [
                        'internal_reference' => $internalReference,
                    ],
                ],
            ],
        ], $secretKey);
        $intent = $intentResponse['data'] ?? [];
        $intentId = trim((string)($intent['id'] ?? ''));
        $createdIntentId = $intentId !== '' ? $intentId : null;
        $clientKey = trim((string)($intent['attributes']['client_key'] ?? ''));
        if ($intentId === '' || $clientKey === '') {
            throw new RuntimeException('PayMongo did not return the required Payment Intent fields.');
        }

        $methodResponse = lendingPaymongoQrphRequest('POST', '/payment_methods', [
            'data' => [
                'attributes' => [
                    'type' => 'qrph',
                    'expiry_seconds' => LENDING_PAYMONGO_QRPH_EXPIRY_SECONDS,
                ],
            ],
        ], $publicKey);
        $paymentMethodId = trim((string)($methodResponse['data']['id'] ?? ''));
        if ($paymentMethodId === '') {
            throw new RuntimeException('PayMongo did not return a QR Ph Payment Method ID.');
        }

        $attachResponse = lendingPaymongoQrphRequest('POST', '/payment_intents/' . rawurlencode($intentId) . '/attach', [
            'data' => [
                'attributes' => [
                    'payment_method' => $paymentMethodId,
                    'client_key' => $clientKey,
                ],
            ],
        ], $publicKey);
        $attachedIntent = $attachResponse['data'] ?? [];
        $intentStatus = trim((string)($attachedIntent['attributes']['status'] ?? ''));
        $imageUrl = trim((string)($attachedIntent['attributes']['next_action']['code']['image_url'] ?? ''));
        if ($intentStatus !== 'awaiting_next_action' || $imageUrl === '' || !lendingPaymongoQrphImageIsSafe($imageUrl)) {
            throw new RuntimeException('PayMongo did not return a valid QR Ph image for an awaiting payment.');
        }

        $update = $conn->prepare(
            "UPDATE paymongo_qrph_transactions
             SET payment_intent_id = ?, payment_method_id = ?, provider_status = ?, qr_image_url = ?,
                 expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND), updated_at = NOW()
             WHERE id = ? AND status = 'pending'"
        );
        $update->execute([
            $intentId,
            $paymentMethodId,
            $intentStatus,
            $imageUrl,
            LENDING_PAYMONGO_QRPH_EXPIRY_SECONDS,
            $transactionId,
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The QR payment could not be saved because the transaction was no longer pending.');
        }

        return $transactionId;
    } catch (Throwable $exception) {
        $failure = $conn->prepare(
            "UPDATE paymongo_qrph_transactions
             SET status = 'failed', provider_status = 'creation_failed', payment_intent_id = COALESCE(?, payment_intent_id),
                 failure_message = ?, updated_at = NOW()
             WHERE id = ? AND status = 'pending' AND qr_image_url IS NULL"
        );
        $failure->execute([
            $createdIntentId,
            mb_substr($exception->getMessage(), 0, 1000),
            $transactionId,
        ]);
        throw $exception;
    }
}

function lendingPaymentIsLinkedToPaymongoQrph(int $paymentId): bool
{
    global $conn;

    if ($paymentId <= 0 || !lendingPaymongoQrphTransactionsTableExists()) {
        return false;
    }

    $columnExists = static function (string $table, string $column) use ($conn): bool {
        $statement = $conn->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([$table, $column]);
        return (int)$statement->fetchColumn() > 0;
    };

    if ($columnExists('paymongo_qrph_transactions', 'payment_id')) {
        $linkedTransaction = $conn->prepare(
            'SELECT id FROM paymongo_qrph_transactions WHERE payment_id = ? LIMIT 1'
        );
        $linkedTransaction->execute([$paymentId]);
        if ($linkedTransaction->fetchColumn()) {
            return true;
        }
    }

    if (!$columnExists('payments', 'paymongo_qrph_transaction_id')) {
        return false;
    }

    $linkedPayment = $conn->prepare(
        'SELECT paymongo_qrph_transaction_id FROM payments WHERE payment_id = ? LIMIT 1'
    );
    $linkedPayment->execute([$paymentId]);
    return (int)$linkedPayment->fetchColumn() > 0;
}
