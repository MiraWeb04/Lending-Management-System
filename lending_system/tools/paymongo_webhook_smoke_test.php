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
$timestamp = (string)time();
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
    'requires the test-mode te signature field when PAYMONGO_MODE is test'
);
$liveSignature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
$liveHeader = 't=' . $timestamp . ',te=,li=' . $liveSignature;
assertPaymongoWebhookCheck(
    lendingVerifyPaymongoWebhookSignature($body, $liveHeader, $secret, 'live'),
    'accepts a valid live signature when verified in live mode'
);
assertPaymongoWebhookCheck(
    !lendingVerifyPaymongoWebhookSignature($body, 't=' . $timestamp . ',te=notahexsignature', $secret),
    'rejects a malformed test signature value'
);
assertPaymongoWebhookCheck(
    !lendingVerifyPaymongoWebhookSignature($body, 't=' . $timestamp . ',te=,li=', $secret, 'live'),
    'rejects a live webhook without a live li signature'
);
$staleTimestamp = (string)(time() - 3600);
$staleSignature = hash_hmac('sha256', $staleTimestamp . '.' . $body, $secret);
assertPaymongoWebhookCheck(
    !lendingVerifyPaymongoWebhookSignature($body, 't=' . $staleTimestamp . ',te=' . $staleSignature . ',li=', $secret),
    'rejects a correctly signed webhook older than five minutes'
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

$pendingQr = [
    'amount' => '125.00',
    'currency' => 'PHP',
    'qr_image_url' => 'data:image/png;base64,aaaa',
    'expires_at' => date('Y-m-d H:i:s', time() - 60),
    'provider_status' => 'awaiting_next_action',
];
$awaitingIntent = [
    'id' => 'pi_test_fixture',
    'type' => 'payment_intent',
    'attributes' => [
        'amount' => 12500,
        'currency' => 'PHP',
        'livemode' => false,
        'status' => 'awaiting_payment_method',
        'payments' => [],
    ],
];
assertPaymongoWebhookCheck(
    lendingPaymongoQrphInterpretIntent($pendingQr, $awaitingIntent)['action'] === 'expire',
    'expires a QR after the stored window when PayMongo reports awaiting_payment_method'
);
$awaitingNextActionIntent = $awaitingIntent;
$awaitingNextActionIntent['attributes']['status'] = 'awaiting_next_action';
assertPaymongoWebhookCheck(
    lendingPaymongoQrphInterpretIntent($pendingQr, $awaitingNextActionIntent)['action'] === 'expire',
    'expires a QR after the stored window when PayMongo still reports awaiting_next_action'
);
$stillValid = $pendingQr;
$stillValid['expires_at'] = date('Y-m-d H:i:s', time() + 600);
assertPaymongoWebhookCheck(
    lendingPaymongoQrphInterpretIntent($stillValid, $awaitingIntent)['action'] === 'pending',
    'does not expire a QR before the stored validity window'
);
$noQr = $pendingQr;
$noQr['qr_image_url'] = '';
assertPaymongoWebhookCheck(
    lendingPaymongoQrphInterpretIntent($noQr, $awaitingIntent)['action'] === 'pending',
    'does not expire a transaction that never received a QR image'
);
$paidIntent = $awaitingIntent;
$paidIntent['attributes']['status'] = 'succeeded';
$paidIntent['attributes']['payments'] = [[
    'id' => 'pay_test_fixture',
    'type' => 'payment',
    'attributes' => [
        'amount' => 12500,
        'currency' => 'PHP',
        'status' => 'paid',
        'livemode' => false,
        'source' => ['type' => 'qrph'],
    ],
]];
$paidDecision = lendingPaymongoQrphInterpretIntent($pendingQr, $paidIntent);
assertPaymongoWebhookCheck(
    $paidDecision['action'] === 'post' && $paidDecision['provider_payment_id'] === 'pay_test_fixture',
    'posts only a succeeded test-mode QR Ph payment with the stored amount'
);
$wrongAmount = $paidIntent;
$wrongAmount['attributes']['amount'] = 10000;
$wrongAmount['attributes']['payments'][0]['attributes']['amount'] = 10000;
assertPaymongoWebhookCheck(
    lendingPaymongoQrphInterpretIntent($pendingQr, $wrongAmount)['action'] === 'review',
    'requires review when the retrieved payment amount does not match'
);
$liveIntent = $paidIntent;
$liveIntent['attributes']['livemode'] = true;
assertPaymongoWebhookCheck(
    lendingPaymongoQrphInterpretIntent($pendingQr, $liveIntent)['action'] === 'review',
    'requires review when a live Payment Intent is retrieved while PAYMONGO_MODE is test'
);
$failedIntent = $awaitingIntent;
$failedIntent['attributes']['payments'] = [[
    'id' => 'pay_test_failed_fixture',
    'type' => 'payment',
    'attributes' => ['amount' => 12500, 'currency' => 'PHP', 'status' => 'failed', 'livemode' => false],
]];
$failedBeforeExpiry = $stillValid;
assertPaymongoWebhookCheck(
    lendingPaymongoQrphInterpretIntent($failedBeforeExpiry, $failedIntent)['action'] === 'fail',
    'marks a failed provider payment without posting a repayment'
);

$intentEvent = [
    'type' => 'payment_intent.awaiting_payment_method',
    'resource' => ['id' => 'pi_documentedfixture', 'type' => 'payment_intent', 'attributes' => ['status' => 'awaiting_payment_method']],
];
assertPaymongoWebhookCheck(
    lendingPaymongoWebhookCandidateIntentId($intentEvent) === 'pi_documentedfixture',
    'reads the Payment Intent ID from a documented payment_intent event'
);
$undocumentedExpiry = [
    'type' => 'qrph.expired',
    'resource' => ['id' => 'qr_undocumented_fixture', 'type' => 'qrph', 'attributes' => ['status' => 'expired']],
];
assertPaymongoWebhookCheck(
    lendingPaymongoWebhookCandidateIntentId($undocumentedExpiry) === '',
    'does not invent a Payment Intent ID for a QR expiry payload that has none'
);
