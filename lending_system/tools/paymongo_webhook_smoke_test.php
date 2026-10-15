<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require_once dirname(__DIR__) . '/includes/paymongo_qrph_webhook.php';

function assertPaymongoWebhookCheck(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
        exit(1);
    }
    echo 'PASS: ' . $label . PHP_EOL;
}

$secret = 'whsk_test_fixture_only';
$timestamp = '1781122334';
$body = json_encode([
    'data' => [
        'id' => 'evt_test_fixture',
        'type' => 'event',
        'attributes' => [
            'type' => 'payment.paid',
            'livemode' => false,
            'data' => [
                'id' => 'pay_test_fixture',
                'type' => 'payment',
                'attributes' => [
                    'status' => 'paid',
                    'payment_intent_id' => 'pi_test_fixture',
                    'amount' => 12500,
                    'currency' => 'PHP',
                    'livemode' => false,
                    'source' => ['type' => 'qrph'],
                ],
            ],
        ],
    ],
], JSON_THROW_ON_ERROR);
$signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
$header = 't=' . $timestamp . ',te=' . $signature . ',li=';

assertPaymongoWebhookCheck(
    lendingVerifyPaymongoWebhookSignature($body, $header, $secret),
    'accepts a valid test signature'
);
assertPaymongoWebhookCheck(
    !lendingVerifyPaymongoWebhookSignature($body, $header, 'whsk_wrong_fixture'),
    'rejects a signature from a different webhook secret'
);
assertPaymongoWebhookCheck(
    !lendingVerifyPaymongoWebhookSignature($body . ' ', $header, $secret),
    'rejects a body changed after signing'
);
assertPaymongoWebhookCheck(
    !lendingVerifyPaymongoWebhookSignature($body, 't=' . $timestamp . ',li=' . $signature, $secret),
    'requires the test-mode signature field'
);
assertPaymongoWebhookCheck(
    !lendingVerifyPaymongoWebhookSignature($body, 't=' . $timestamp . ',te=' . $signature, $secret),
    'requires the complete PayMongo signature header structure'
);

$event = lendingParsePaymongoWebhookEvent($body);
assertPaymongoWebhookCheck(
    $event !== null
        && $event['id'] === 'evt_test_fixture'
        && $event['type'] === 'payment.paid'
        && ($event['resource']['attributes']['payment_intent_id'] ?? '') === 'pi_test_fixture'
        && ($event['resource']['attributes']['source']['type'] ?? '') === 'qrph',
    'parses a payment.paid event and embedded payment resource'
);
assertPaymongoWebhookCheck(
    lendingParsePaymongoWebhookEvent('{invalid json') === null,
    'rejects malformed event JSON'
);

$failedEvent = json_encode([
    'data' => [
        'id' => 'evt_test_failed_fixture',
        'type' => 'event',
        'attributes' => [
            'type' => 'payment.failed',
            'livemode' => false,
            'data' => [
                'id' => 'pay_test_failed_fixture',
                'type' => 'payment',
                'attributes' => [
                    'status' => 'failed',
                    'payment_intent_id' => 'pi_test_fixture',
                    'amount' => 12500,
                    'currency' => 'PHP',
                    'livemode' => false,
                ],
            ],
        ],
    ],
], JSON_THROW_ON_ERROR);
$parsedFailedEvent = lendingParsePaymongoWebhookEvent($failedEvent);
assertPaymongoWebhookCheck(
    $parsedFailedEvent !== null
        && $parsedFailedEvent['type'] === 'payment.failed'
        && ($parsedFailedEvent['resource']['attributes']['status'] ?? '') === 'failed',
    'parses a documented payment.failed event without treating it as a repayment'
);

$expiredEvent = json_encode([
    'data' => [
        'id' => 'evt_test_expired_fixture',
        'type' => 'event',
        'attributes' => [
            'type' => 'qrph.expired',
            'livemode' => false,
            'data' => ['id' => 'qrph_test_fixture', 'type' => 'qrph'],
        ],
    ],
], JSON_THROW_ON_ERROR);
$parsedExpiredEvent = lendingParsePaymongoWebhookEvent($expiredEvent);
assertPaymongoWebhookCheck(
    $parsedExpiredEvent !== null
        && $parsedExpiredEvent['type'] === 'qrph.expired',
    'parses a QR expiry event for audited handling without inventing undocumented fields'
);

$transaction = ['amount' => '125.00', 'currency' => 'PHP'];
assertPaymongoWebhookCheck(
    lendingPaymongoWebhookMatchesTransaction($transaction, ['amount' => 12500, 'currency' => 'PHP']),
    'matches the exact stored amount in centavos and PHP currency'
);
assertPaymongoWebhookCheck(
    !lendingPaymongoWebhookMatchesTransaction($transaction, ['amount' => 12499, 'currency' => 'PHP']),
    'rejects a different payment amount'
);
assertPaymongoWebhookCheck(
    !lendingPaymongoWebhookMatchesTransaction($transaction, ['amount' => '12500.5', 'currency' => 'PHP']),
    'rejects a non-integer amount in centavos'
);
assertPaymongoWebhookCheck(
    !lendingPaymongoWebhookMatchesTransaction($transaction, ['amount' => 12500, 'currency' => 'USD']),
    'rejects a different currency'
);
