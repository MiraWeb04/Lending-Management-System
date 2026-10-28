<?php
require __DIR__ . '/env_prepend.php';
require dirname(__DIR__, 2) . '/lending_system/includes/paymongo_qrph.php';

$base = 'http://127.0.0.1:8099';
$pdo = new PDO('mysql:host=localhost;dbname=frams_capstone_test;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

function http_call(string $url, string $method = 'GET', ?string $body = null, array $headers = [], ?string $cookie = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($cookie !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
    }
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $raw = is_string($raw) ? $raw : '';
    return [$status, substr($raw, $headerSize)];
}

function csrf_from(string $html): string
{
    if (preg_match('/name="_csrf_token" value="([a-f0-9]+)"/', $html, $match)) {
        return $match[1];
    }
    return '';
}

$collector = (int)$pdo->query("SELECT user_id FROM users WHERE username = 'frams_test_collector'")->fetchColumn();
$client = (int)$pdo->query('SELECT client_id FROM loans WHERE loan_id = 31')->fetchColumn();
$borrower = (int)$pdo->query("SELECT user_id FROM users WHERE username = 'frams_test_borrower'")->fetchColumn();
$pdo->prepare(
    'INSERT INTO paymongo_qrph_transactions
        (internal_reference, payment_intent_id, loan_id, client_id, borrower_user_id, initiated_by_user_id, collector_id, selected_method, amount, penalty_paid, currency, status, provider_status, expires_at)
     VALUES (?, ?, 31, ?, ?, ?, ?, "E-Wallet", "100.00", 0, "PHP", "pending", "awaiting_next_action", DATE_ADD(NOW(), INTERVAL 30 MINUTE))'
)->execute(['QRPH-PM09-SIM', 'pi_frams_sim_disp31', $client, $borrower, $collector, $collector]);
$transactionId = (int)$pdo->lastInsertId();

$beforePaid = $pdo->query('SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE loan_id = 31')->fetchColumn();
$payload = json_encode([
    'data' => [
        'id' => 'evt_frams_pm09_once',
        'type' => 'event',
        'attributes' => [
            'type' => 'payment.paid',
            'livemode' => false,
            'data' => [
                'id' => 'pay_frams_pm09_once',
                'type' => 'payment',
                'attributes' => [
                    'amount' => 10000,
                    'currency' => 'PHP',
                    'status' => 'paid',
                    'livemode' => false,
                    'payment_intent_id' => 'pi_frams_sim_disp31',
                    'source' => ['type' => 'qrph'],
                ],
            ],
        ],
    ],
], JSON_THROW_ON_ERROR);
$secret = (string)lendingEnv('PAYMONGO_WEBHOOK_SECRET', '');
$sign = static function (string $body, int $timestamp) use ($secret): string {
    return 't=' . $timestamp . ',te=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
};
$now = time();
[$status1, $body1] = http_call($base . '/paymongo_webhook_lending.php', 'POST', $payload, [
    'Content-Type: application/json',
    'Paymongo-Signature: ' . $sign($payload, $now),
]);
[$status2, $body2] = http_call($base . '/paymongo_webhook_lending.php', 'POST', $payload, [
    'Content-Type: application/json',
    'Paymongo-Signature: ' . $sign($payload, $now),
]);
[$statusMissing, $bodyMissing] = http_call($base . '/paymongo_webhook_lending.php', 'POST', $payload, [
    'Content-Type: application/json',
]);
[$statusBad, $bodyBad] = http_call($base . '/paymongo_webhook_lending.php', 'POST', $payload, [
    'Content-Type: application/json',
    'Paymongo-Signature: t=' . $now . ',te=' . str_repeat('ab', 32),
]);
$old = $now - 600;
[$statusOld, $bodyOld] = http_call($base . '/paymongo_webhook_lending.php', 'POST', $payload, [
    'Content-Type: application/json',
    'Paymongo-Signature: ' . $sign($payload, $old),
]);

$tx = $pdo->query('SELECT status FROM paymongo_qrph_transactions WHERE id = ' . $transactionId)->fetchColumn();
$payments = $pdo->query('SELECT payment_id, receipt_number, amount_paid, payment_method, reference_number FROM payments WHERE loan_id = 31')->fetchAll(PDO::FETCH_ASSOC);
$paid = $pdo->query('SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE loan_id = 31')->fetchColumn();
$loanStatus = $pdo->query('SELECT status FROM loans WHERE loan_id = 31')->fetchColumn();

$cookie = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'frams_borrower_cookie.txt';
@unlink($cookie);
http_call($base . '/borrower_login_lending.php', 'POST', http_build_query([
    'username' => 'frams_test_borrower',
    'password' => 'Test@Frams1',
]), ['Content-Type: application/x-www-form-urlencoded'], $cookie);
[$borrowerPageStatus, $borrowerPage] = http_call($base . '/payments_lending.php?add=true&loan=31', 'GET', null, [], $cookie);
$borrowerLocation = '';
if (preg_match('/Location:\s*(\S+)/i', $borrowerPage, $locationMatch)) {
    $borrowerLocation = $locationMatch[1];
}
$profile = http_call($base . '/profile_lending.php', 'GET', null, [], $cookie);
$token = csrf_from($profile[1]);
[$borrowerPostStatus, $borrowerPostBody] = http_call($base . '/payments_lending.php', 'POST', http_build_query([
    '_csrf_token' => $token,
    'start_qrph' => '1',
    'loan_id' => '31',
    'payment_method' => 'E-Wallet',
    'amount_paid' => '100',
    'penalty_paid' => '0',
]), ['Content-Type: application/x-www-form-urlencoded'], $cookie);

$adminCookie = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'frams_admin_cookie.txt';
@unlink($adminCookie);
http_call($base . '/login_lending.php', 'POST', http_build_query([
    'username' => 'frams_test_admin',
    'password' => 'Test@Frams1',
]), ['Content-Type: application/x-www-form-urlencoded'], $adminCookie);
[$adminGetStatus, $adminHtml] = http_call($base . '/payments_lending.php', 'GET', null, [], $adminCookie);
$adminToken = csrf_from($adminHtml);
$paymentId = (int)($payments[0]['payment_id'] ?? 0);
[$deleteStatus, $deleteBody] = http_call($base . '/payments_lending.php', 'POST', http_build_query([
    '_csrf_token' => $adminToken,
    'delete_payment' => '1',
    'payment_id' => (string)$paymentId,
    'loan_id' => '31',
]), ['Content-Type: application/x-www-form-urlencoded'], $adminCookie);
$afterDelete = $pdo->query('SELECT COUNT(*) FROM payments WHERE loan_id = 31')->fetchColumn();
$txAfter = $pdo->query('SELECT status FROM paymongo_qrph_transactions WHERE id = ' . $transactionId)->fetchColumn();
$hasDeleteControl = str_contains($adminHtml, 'delete_payment') || str_contains($adminHtml, 'fa-trash');

$out = [
    'pm09_first_http' => $status1,
    'pm09_first_body' => $body1,
    'pm10_repeat_http' => $status2,
    'pm10_repeat_body' => $body2,
    'pm11_missing_http' => $statusMissing,
    'pm11_missing_body' => $bodyMissing,
    'pm11_bad_http' => $statusBad,
    'pm11_bad_body' => $bodyBad,
    'pm11_old_http' => $statusOld,
    'pm11_old_body' => $bodyOld,
    'transaction_status' => $tx,
    'loan_status' => $loanStatus,
    'paid_before' => $beforePaid,
    'paid_after' => $paid,
    'payments' => $payments,
    'borrower_get_status' => $borrowerPageStatus,
    'borrower_get_is_history' => str_contains($borrowerPage, 'Payment History') || str_contains($borrowerPage, 'borrower_payment_history'),
    'borrower_post_status' => $borrowerPostStatus,
    'borrower_post_body' => trim(strip_tags($borrowerPostBody)),
    'admin_page_has_delete_control' => $hasDeleteControl,
    'delete_http' => $deleteStatus,
    'delete_message' => str_contains($deleteBody, 'was not deleted') ? 'refused' : 'other',
    'payments_after_delete' => $afterDelete,
    'transaction_after_delete' => $txAfter,
    'source_payments' => (int)$pdo->query('SELECT COUNT(*) FROM lending_management.payments')->fetchColumn(),
    'source_loans' => (int)$pdo->query('SELECT COUNT(*) FROM lending_management.loans')->fetchColumn(),
];
file_put_contents(__DIR__ . '/figure_http_results.json', json_encode($out, JSON_PRETTY_PRINT));
echo 'WROTE figure_http_results.json TX=' . $tx . ' PAID=' . $paid . ' DELETE=' . $out['delete_message'] . PHP_EOL;
