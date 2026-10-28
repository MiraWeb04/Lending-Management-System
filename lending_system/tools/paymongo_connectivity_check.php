<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require_once dirname(__DIR__) . '/includes/paymongo_qrph.php';

function paymongoConfigFlag(string $key, callable $accepts): string
{
    $value = trim((string)lendingEnv($key, ''));
    if ($value === '') {
        return 'missing';
    }

    return $accepts($value) ? 'ok' : 'invalid_prefix';
}

$configuredMode = lendingPaymongoConfiguredMode();
echo 'PAYMONGO_MODE=' . $configuredMode . PHP_EOL;
$secretPrefix = $configuredMode === 'live' ? 'sk_live_' : 'sk_test_';
$publicPrefix = $configuredMode === 'live' ? 'pk_live_' : 'pk_test_';
echo 'PAYMONGO_SECRET_KEY=' . paymongoConfigFlag('PAYMONGO_SECRET_KEY', static fn (string $value): bool => str_starts_with($value, $secretPrefix)) . PHP_EOL;
echo 'PAYMONGO_PUBLIC_KEY=' . paymongoConfigFlag('PAYMONGO_PUBLIC_KEY', static fn (string $value): bool => str_starts_with($value, $publicPrefix)) . PHP_EOL;
echo 'PAYMONGO_WEBHOOK_SECRET=' . paymongoConfigFlag('PAYMONGO_WEBHOOK_SECRET', static fn (string $value): bool => str_starts_with($value, 'whsk_')) . PHP_EOL;

try {
    lendingPaymongoRetrievePaymentIntent('pi_connectivitycheckmissing');
    echo 'api_result=unexpected_success' . PHP_EOL;
    exit(1);
} catch (Throwable $exception) {
    $message = $exception->getMessage();
    if (str_contains($message, 'mode is not configured') || str_contains($message, 'mode requires')) {
        echo 'api_result=not_configured' . PHP_EOL;
        exit(1);
    }
    if (str_contains($message, 'could not be reached') || str_contains($message, 'invalid response')) {
        echo 'api_result=unreachable' . PHP_EOL;
        exit(1);
    }
    if (str_contains($message, '(HTTP 404)')) {
        echo 'api_result=authenticated_missing_intent' . PHP_EOL;
        exit(0);
    }
    if (str_contains($message, '(HTTP 401)')) {
        echo 'api_result=authentication_rejected' . PHP_EOL;
        exit(1);
    }

    echo 'api_result=rejected' . PHP_EOL;
    exit(1);
}
