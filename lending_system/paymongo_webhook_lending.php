<?php

require_once __DIR__ . '/includes/env_lending.php';
require_once __DIR__ . '/includes/paymongo_qrph.php';
require_once __DIR__ . '/includes/paymongo_qrph_webhook.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function lendingPaymongoWebhookRespond(int $statusCode, string $status): never
{
    http_response_code($statusCode);
    echo json_encode(['received' => $statusCode >= 200 && $statusCode < 300, 'status' => $status]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    lendingPaymongoWebhookRespond(405, 'method_not_allowed');
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 262144) {
    lendingPaymongoWebhookRespond(413, 'payload_too_large');
}

$rawBody = file_get_contents('php://input', false, null, 0, 262145);
if ($rawBody === false || $rawBody === '' || strlen($rawBody) > 262144) {
    lendingPaymongoWebhookRespond(400, 'invalid_payload');
}

$mode = lendingPaymongoConfiguredMode();
if ($mode !== 'test' && $mode !== 'live') {
    error_log('PayMongo webhook endpoint requires PAYMONGO_MODE=test or PAYMONGO_MODE=live.');
    lendingPaymongoWebhookRespond(503, 'paymongo_mode_invalid');
}

$webhookSecret = trim((string)lendingEnv('PAYMONGO_WEBHOOK_SECRET', ''));
$signatureHeader = trim((string)($_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? ''));
if ($webhookSecret === '') {
    error_log('PayMongo webhook secret is not configured.');
    lendingPaymongoWebhookRespond(503, 'webhook_not_configured');
}
if (!lendingVerifyPaymongoWebhookSignature($rawBody, $signatureHeader, $webhookSecret)) {
    lendingPaymongoWebhookRespond(401, 'invalid_signature');
}

$event = lendingParsePaymongoWebhookEvent($rawBody);
if ($event === null) {
    lendingPaymongoWebhookRespond(400, 'invalid_event');
}

require_once __DIR__ . '/includes/db_lending.php';

try {
    ensurePaymentReceiptColumns();
    $result = lendingProcessPaymongoWebhookEvent($event, $rawBody);
} catch (Throwable $exception) {
    error_log('PayMongo webhook processing failed: ' . $exception->getMessage());
    lendingPaymongoWebhookRespond(500, 'processing_failed');
}

lendingPaymongoWebhookRespond(200, $result);
