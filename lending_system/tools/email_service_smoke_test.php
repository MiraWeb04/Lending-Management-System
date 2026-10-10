<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found'); }

require_once dirname(__DIR__) . '/includes/db_lending.php';
require_once dirname(__DIR__) . '/includes/loan_email_helpers.php';

$invalid = sendEmail('not-an-email', 'Invalid Recipient Test', '<p>Should fail validation.</p>', [], 'smtp_test', 'invalid-recipient');
echo 'Invalid recipient: ' . ($invalid['success'] ? 'unexpected success' : $invalid['message']) . PHP_EOL;

$missingConfig = sendEmail('test@example.com', 'Missing SMTP Test', '<p>Should fail when SMTP is not configured.</p>', [], 'smtp_test', 'missing-config-' . time());
echo 'Missing SMTP: ' . ($missingConfig['success'] ? 'unexpected success' : $missingConfig['message']);
if (!empty($missingConfig['log_id'])) {
    echo ' (log #' . (int)$missingConfig['log_id'] . ')';
}
echo PHP_EOL;

$logs = getRecentEmailNotifications(5);
echo 'Recent log rows: ' . count($logs) . PHP_EOL;
