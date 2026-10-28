<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require_once dirname(__DIR__) . '/includes/paymongo_qrph.php';

const PAYMONGO_WEBHOOK_REGISTER_REQUIRED_EVENTS = [
    'payment.paid',
    'payment.failed',
    'qrph.expired',
    'payment_intent.awaiting_payment_method',
];

function paymongoWebhookRegisterUsage(): void
{
    $script = basename(__FILE__);
    fwrite(STDERR, <<<TEXT
PayMongo webhook registration (QR Ph, test or live per PAYMONGO_MODE).

Usage:
  php lending_system/tools/{$script} --list
  php lending_system/tools/{$script} --url=https://<public-host>/lending_system/lending_system/paymongo_webhook_lending.php [--write-env]

Options:
  --list       List existing webhook endpoints (secrets are never printed).
  --url=URL    Register the handler URL if no matching endpoint exists (HTTPS, publicly reachable; not localhost).
  --write-env  After creating a webhook, set PAYMONGO_WEBHOOK_SECRET in the project .env (secret is not echoed).

TEXT);
}

function paymongoWebhookRegisterFail(string $message, int $exitCode = 1): never
{
    fwrite(STDERR, 'ERROR: ' . $message . PHP_EOL);
    exit($exitCode);
}

function paymongoWebhookRegisterInfo(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function paymongoWebhookRegisterMaskSecret(string $secret): string
{
    if ($secret === '') {
        return '(empty)';
    }
    if (strlen($secret) <= 12) {
        return str_repeat('*', strlen($secret));
    }

    return substr($secret, 0, 6) . '…' . substr($secret, -4);
}

function paymongoWebhookRegisterNormalizeUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    return rtrim($url, '/');
}

function paymongoWebhookRegisterUrlIsAllowed(string $url): bool
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if ($scheme !== 'https') {
        return false;
    }
    $host = strtolower((string)($parts['host'] ?? ''));
    if ($host === '') {
        return false;
    }
    $blockedHosts = [
        'localhost',
        '127.0.0.1',
        '0.0.0.0',
        '::1',
        '[::1]',
    ];
    if (in_array($host, $blockedHosts, true)) {
        return false;
    }
    if (str_ends_with($host, '.local') || str_ends_with($host, '.localhost')) {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $packed = @inet_pton($host);
        if ($packed !== false) {
            if ($host === '127.0.0.1' || $host === '::1') {
                return false;
            }
            // Private/reserved ranges
            if (str_starts_with($host, '10.')
                || str_starts_with($host, '192.168.')
                || preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $host) === 1) {
                return false;
            }
        }
    }

    return str_ends_with(paymongoWebhookRegisterNormalizeUrl($url), '/paymongo_webhook_lending.php');
}

function paymongoWebhookRegisterProjectEnvPath(): string
{
    $projectRoot = dirname(__DIR__, 2);
    if (is_file($projectRoot . '/.env')) {
        return $projectRoot . '/.env';
    }

    return dirname(__DIR__) . '/.env';
}

function paymongoWebhookRegisterWriteEnvSecret(string $webhookSecret): void
{
    if (!str_starts_with($webhookSecret, 'whsk_')) {
        paymongoWebhookRegisterFail('Refusing to write a webhook secret with an unexpected format.');
    }

    $path = paymongoWebhookRegisterProjectEnvPath();
    $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
    if ($lines === false) {
        paymongoWebhookRegisterFail('Could not read the .env file at ' . $path);
    }

    $updated = false;
    $found = false;
    foreach ($lines as $index => $line) {
        if (str_starts_with(trim($line), 'PAYMONGO_WEBHOOK_SECRET=')) {
            $found = true;
            $current = trim(substr($line, strlen('PAYMONGO_WEBHOOK_SECRET=')), " \t\"'");
            if ($current !== '' && hash_equals($current, $webhookSecret)) {
                paymongoWebhookRegisterInfo('PAYMONGO_WEBHOOK_SECRET is already set in .env (unchanged).');
                return;
            }
            $lines[$index] = 'PAYMONGO_WEBHOOK_SECRET=' . $webhookSecret;
            $updated = true;
            break;
        }
    }
    if (!$found) {
        if ($lines !== [] && trim((string)end($lines)) !== '') {
            $lines[] = '';
        }
        $lines[] = '# PayMongo test webhook signing secret (from webhook registration).';
        $lines[] = 'PAYMONGO_WEBHOOK_SECRET=' . $webhookSecret;
        $updated = true;
    }

    if (!$updated) {
        paymongoWebhookRegisterInfo('PAYMONGO_WEBHOOK_SECRET was not modified.');
        return;
    }

    $payload = implode(PHP_EOL, $lines) . PHP_EOL;
    if (file_put_contents($path, $payload, LOCK_EX) === false) {
        paymongoWebhookRegisterFail('Could not write PAYMONGO_WEBHOOK_SECRET to ' . $path);
    }

    paymongoWebhookRegisterInfo('Updated PAYMONGO_WEBHOOK_SECRET in ' . $path . ' (' . paymongoWebhookRegisterMaskSecret($webhookSecret) . ').');
}

function paymongoWebhookRegisterListWebhooks(): array
{
    $response = lendingPaymongoQrphRequest('GET', '/webhooks', [], lendingPaymongoSecretKey());
    $data = $response['data'] ?? [];
    return is_array($data) ? $data : [];
}

function paymongoWebhookRegisterEventsCoverRequired(array $subscribed): bool
{
    $subscribed = array_values(array_unique(array_map('strval', $subscribed)));
    foreach (PAYMONGO_WEBHOOK_REGISTER_REQUIRED_EVENTS as $required) {
        if (!in_array($required, $subscribed, true)) {
            return false;
        }
    }

    return true;
}

function paymongoWebhookRegisterFormatWebhookSummary(array $webhook): string
{
    $attributes = is_array($webhook['attributes'] ?? null) ? $webhook['attributes'] : [];
    $events = is_array($attributes['events'] ?? null) ? $attributes['events'] : [];
    sort($events);
    $livemode = ($attributes['livemode'] ?? null) === true ? 'live' : 'test';
    $status = (string)($attributes['status'] ?? 'unknown');
    $url = paymongoWebhookRegisterNormalizeUrl((string)($attributes['url'] ?? ''));
    $id = (string)($webhook['id'] ?? '');
    $eventsOk = paymongoWebhookRegisterEventsCoverRequired($events) ? 'events=ok' : 'events=incomplete';

    return $id . ' mode=' . $livemode . ' status=' . $status . ' ' . $eventsOk . ' url=' . $url;
}

function paymongoWebhookRegisterAssertPaymongoMode(): void
{
    $mode = lendingPaymongoConfiguredMode();
    if ($mode !== 'test' && $mode !== 'live') {
        paymongoWebhookRegisterFail('PAYMONGO_MODE must be test or live.');
    }
    try {
        lendingPaymongoSecretKey();
    } catch (Throwable $exception) {
        paymongoWebhookRegisterFail($exception->getMessage());
    }
}

$options = getopt('', ['list', 'url:', 'write-env', 'help']);
if (isset($options['help']) || $options === false) {
    paymongoWebhookRegisterUsage();
    exit(isset($options['help']) ? 0 : 1);
}

paymongoWebhookRegisterAssertPaymongoMode();

$listOnly = array_key_exists('list', $options);
$registerUrl = isset($options['url']) ? paymongoWebhookRegisterNormalizeUrl((string)$options['url']) : '';
$writeEnv = array_key_exists('write-env', $options);

if (!$listOnly && $registerUrl === '') {
    $appUrl = rtrim(trim((string)lendingEnv('APP_URL', '')), '/');
    if ($appUrl !== '' && str_starts_with(strtolower($appUrl), 'https://')) {
        $registerUrl = paymongoWebhookRegisterNormalizeUrl(
            $appUrl . '/lending_system/lending_system/paymongo_webhook_lending.php'
        );
        paymongoWebhookRegisterInfo('webhook_url_from_app_url=' . $registerUrl);
    }
}

if (!$listOnly && $registerUrl === '') {
    paymongoWebhookRegisterUsage();
    paymongoWebhookRegisterFail(
        'Provide --url=https://... or set APP_URL in .env to your public HTTPS site (no trailing slash). Localhost cannot receive PayMongo webhooks.'
    );
}

if ($registerUrl !== '' && !paymongoWebhookRegisterUrlIsAllowed($registerUrl)) {
    paymongoWebhookRegisterFail(
        'Webhook URL must be HTTPS, publicly reachable (not localhost or private IP), and end with /paymongo_webhook_lending.php.'
    );
}

try {
    $webhooks = paymongoWebhookRegisterListWebhooks();
} catch (Throwable $exception) {
    paymongoWebhookRegisterFail('Could not list PayMongo webhooks: ' . $exception->getMessage());
}

paymongoWebhookRegisterInfo('webhook_api=list_ok count=' . count($webhooks));

foreach ($webhooks as $webhook) {
    if (!is_array($webhook)) {
        continue;
    }
    paymongoWebhookRegisterInfo('webhook ' . paymongoWebhookRegisterFormatWebhookSummary($webhook));
}

if ($listOnly) {
    exit(0);
}

$matching = null;
foreach ($webhooks as $webhook) {
    if (!is_array($webhook)) {
        continue;
    }
    $attributes = is_array($webhook['attributes'] ?? null) ? $webhook['attributes'] : [];
    $wantsLive = lendingPaymongoExpectsLivemode();
    if ((($attributes['livemode'] ?? null) === true) !== $wantsLive) {
        continue;
    }
    $existingUrl = paymongoWebhookRegisterNormalizeUrl((string)($attributes['url'] ?? ''));
    if ($existingUrl === $registerUrl) {
        $matching = $webhook;
        break;
    }
}

if ($matching !== null) {
    $attributes = is_array($matching['attributes'] ?? null) ? $matching['attributes'] : [];
    $events = is_array($attributes['events'] ?? null) ? $attributes['events'] : [];
    paymongoWebhookRegisterInfo('result=already_registered id=' . (string)($matching['id'] ?? ''));
    if (!paymongoWebhookRegisterEventsCoverRequired($events)) {
        paymongoWebhookRegisterInfo(
            'action_required=Update this endpoint in the PayMongo Dashboard (or via PUT /v1/webhooks/{id}) to subscribe to: '
            . implode(', ', PAYMONGO_WEBHOOK_REGISTER_REQUIRED_EVENTS)
        );
    }
    paymongoWebhookRegisterInfo(
        'PAYMONGO_WEBHOOK_SECRET must match the signing secret shown in PayMongo for this endpoint. '
        . 'It is only returned when the webhook is first created; copy it from the Dashboard if missing from .env.'
    );
    exit(0);
}

try {
    $created = lendingPaymongoQrphRequest('POST', '/webhooks', [
        'data' => [
            'attributes' => [
                'url' => $registerUrl,
                'events' => PAYMONGO_WEBHOOK_REGISTER_REQUIRED_EVENTS,
            ],
        ],
    ], lendingPaymongoSecretKey());
} catch (Throwable $exception) {
    paymongoWebhookRegisterFail('Could not register webhook: ' . $exception->getMessage());
}

$webhook = is_array($created['data'] ?? null) ? $created['data'] : [];
$webhookId = (string)($webhook['id'] ?? '');
$attributes = is_array($webhook['attributes'] ?? null) ? $webhook['attributes'] : [];
$secret = trim((string)($attributes['secret_key'] ?? ''));

if ($webhookId === '' || !str_starts_with($secret, 'whsk_')) {
    paymongoWebhookRegisterFail('PayMongo created a webhook but did not return an ID and signing secret.');
}

paymongoWebhookRegisterInfo('result=created id=' . $webhookId);
paymongoWebhookRegisterInfo('webhook_url=' . $registerUrl);
paymongoWebhookRegisterInfo('webhook_secret=' . paymongoWebhookRegisterMaskSecret($secret));
paymongoWebhookRegisterInfo(
    'Add to .env: PAYMONGO_WEBHOOK_SECRET=' . paymongoWebhookRegisterMaskSecret($secret)
    . ' (use --write-env to persist the full value without printing it)'
);

if ($writeEnv) {
    paymongoWebhookRegisterWriteEnvSecret($secret);
} else {
    paymongoWebhookRegisterInfo('Run again with --write-env to store the full signing secret in .env securely.');
}
