<?php
/**
 * HTTP checks against the isolated server at 127.0.0.1:8099.
 * The server must be started with the isolated test prepend.
 */
$base = 'http://127.0.0.1:8099';
$root = dirname(__DIR__, 2);
$manifest = json_decode((string)file_get_contents($root . '/testing_evidence/_harness/seed_manifest.json'), true);
$loanId = (int)$manifest['loans']['a']['loan_id'];
$password = 'Test@Frams1';
$cookieDir = dirname(__DIR__) . '/evidence/http_cookies';
if (!is_dir($cookieDir)) {
    mkdir($cookieDir, 0777, true);
}
$results = [];

function http_call(string $method, string $url, ?array $fields, string $cookie, array $headers = [], ?string $raw = null): array
{
    $ch = curl_init($url);
    $responseHeaders = [];
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADERFUNCTION => static function ($ch, $header) use (&$responseHeaders) {
            $responseHeaders[] = $header;
            return strlen($header);
        },
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($raw !== null) {
        $options[CURLOPT_POSTFIELDS] = $raw;
    } elseif ($fields !== null) {
        $options[CURLOPT_POSTFIELDS] = http_build_query($fields);
    }
    curl_setopt_array($ch, $options);
    $start = hrtime(true);
    $body = curl_exec($ch);
    $elapsed = (hrtime(true) - $start) / 1e6;
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return [
        'status' => $status,
        'body' => is_string($body) ? $body : '',
        'headers' => implode('', $responseHeaders),
        'ms' => round($elapsed, 2),
        'error' => $error,
    ];
}

function record(string $id, string $objective, string $expected, string $actual, string $status, string $notes = ''): void
{
    global $results;
    $results[] = [
        'id' => $id,
        'objective' => $objective,
        'expected' => $expected,
        'actual' => $actual,
        'status' => $status,
        'notes' => $notes,
        'executed_at' => date('c'),
        'evidence' => 'testing/evidence/http_checks.json',
    ];
    echo $id . ' ' . $status . PHP_EOL;
}

$loginCookie = $cookieDir . '/fresh.txt';
@unlink($loginCookie);
$login = http_call('GET', $base . '/login_lending.php', null, $loginCookie);
record(
    'HTTP-01',
    'Staff login page loads on the isolated server',
    'HTTP 200 and a login form',
    'HTTP ' . $login['status'] . ' in ' . $login['ms'] . ' ms; form=' . (str_contains($login['body'], 'name="username"') ? 'yes' : 'no'),
    ($login['status'] === 200 && str_contains($login['body'], 'name="username"')) ? 'PASS' : 'FAIL'
);
$headerBlob = strtolower($login['headers']);
$missing = [];
foreach (['x-frame-options', 'content-security-policy', 'x-content-type-options'] as $header) {
    if (!str_contains($headerBlob, $header . ':')) {
        $missing[] = $header;
    }
}
record(
    'HTTP-02',
    'Response includes common browser hardening headers',
    'X-Frame-Options, Content-Security-Policy, and X-Content-Type-Options are present',
    $missing === [] ? 'all present' : 'missing ' . implode(', ', $missing),
    $missing === [] ? 'PASS' : 'FAIL',
    'Observed on the isolated login response. This is not a claim about a production web-server configuration.'
);

$empty = http_call('POST', $base . '/login_lending.php', ['username' => '', 'password' => ''], $cookieDir . '/empty.txt');
record('HTTP-03', 'Login with missing credentials', 'Validation message and no redirect', 'HTTP ' . $empty['status'] . ' message=' . (str_contains($empty['body'], 'Please enter both username and password') ? 'shown' : 'absent'), ($empty['status'] === 200 && str_contains($empty['body'], 'Please enter both username and password')) ? 'PASS' : 'FAIL');

$bad = http_call('POST', $base . '/login_lending.php', ['username' => 'frams_test_admin', 'password' => 'wrong-password'], $cookieDir . '/bad.txt');
record('HTTP-04', 'Login with a wrong password', 'HTTP 200 and Invalid username or password', 'HTTP ' . $bad['status'] . ' error=' . (str_contains($bad['body'], 'Invalid username or password') ? 'shown' : 'absent'), ($bad['status'] === 200 && str_contains($bad['body'], 'Invalid username or password')) ? 'PASS' : 'FAIL');

$sqli = http_call('POST', $base . '/login_lending.php', ['username' => "admin' OR '1'='1", 'password' => 'wrong-password'], $cookieDir . '/sqli.txt');
record('HTTP-05', 'Login identifier containing SQL metacharacters', 'Rejected as an ordinary failed login', 'HTTP ' . $sqli['status'] . ' error=' . (str_contains($sqli['body'], 'Invalid username or password') ? 'shown' : 'absent') . ' location=' . (preg_match('/Location:/i', $sqli['headers']) ? 'yes' : 'no'), ($sqli['status'] === 200 && str_contains($sqli['body'], 'Invalid username or password')) ? 'PASS' : 'FAIL', 'One login request on the isolated server.');

$xss = http_call('POST', $base . '/login_lending.php', ['username' => '<script>alert(1)</script>', 'password' => 'wrong-password'], $cookieDir . '/xss.txt');
$rawScript = str_contains($xss['body'], '<script>alert(1)</script>');
$escaped = str_contains($xss['body'], '&lt;script&gt;');
record('HTTP-06', 'Login page escapes a markup username', 'The markup is escaped and the login error is shown', 'raw=' . ($rawScript ? 'yes' : 'no') . ' escaped=' . ($escaped ? 'yes' : 'no'), (!$rawScript && $escaped) ? 'PASS' : 'FAIL', 'Checked only the staff login field.');

$anon = http_call('GET', $base . '/users_lending.php', null, $cookieDir . '/anon.txt');
record('HTTP-07', 'Unauthenticated user-management request', 'Redirect to the staff login', 'HTTP ' . $anon['status'] . ' location_login=' . (str_contains($anon['headers'], 'login_lending.php') ? 'yes' : 'no'), ($anon['status'] === 302 && str_contains($anon['headers'], 'login_lending.php')) ? 'PASS' : 'FAIL');

function role_login(string $username, string $password, string $cookie): array
{
    global $base;
    @unlink($cookie);
    return http_call('POST', $base . '/login_lending.php', ['username' => $username, 'password' => $password], $cookie);
}

$adminCookie = $cookieDir . '/admin.txt';
$adminLogin = role_login('frams_test_admin', $password, $adminCookie);
$adminDash = http_call('GET', $base . '/dashboard_lending.php', null, $adminCookie);
record('HTTP-08', 'Administrator login and dashboard', 'Login redirects and the dashboard returns HTTP 200', 'login HTTP ' . $adminLogin['status'] . ' dashboard HTTP ' . $adminDash['status'] . ' executive=' . (str_contains($adminDash['body'], 'Executive') || str_contains($adminDash['body'], 'Dashboard') ? 'yes' : 'no'), ($adminLogin['status'] === 302 && $adminDash['status'] === 200) ? 'PASS' : 'FAIL');

$collectorCookie = $cookieDir . '/collector.txt';
$collectorLogin = role_login('frams_test_collector', $password, $collectorCookie);
$collectorDash = http_call('GET', $base . '/collector_dashboard_lending.php', null, $collectorCookie);
$collectorExpenses = http_call('GET', $base . '/expenses_lending.php', null, $collectorCookie);
record('HTTP-09', 'Collector login and expenses denial', 'Collector dashboard opens and expenses returns HTTP 403', 'login ' . $collectorLogin['status'] . ' dashboard ' . $collectorDash['status'] . ' expenses ' . $collectorExpenses['status'], ($collectorLogin['status'] === 302 && $collectorDash['status'] === 200 && $collectorExpenses['status'] === 403) ? 'PASS' : 'FAIL');

$borrowerCookie = $cookieDir . '/borrower.txt';
@unlink($borrowerCookie);
$borrowerLogin = http_call('POST', $base . '/borrower_login_lending.php', ['username' => 'frams_test_borrower', 'password' => $password], $borrowerCookie);
$borrowerDash = http_call('GET', $base . '/borrower_dashboard_lending.php', null, $borrowerCookie);
$borrowerUsers = http_call('GET', $base . '/users_lending.php', null, $borrowerCookie);
record('HTTP-10', 'Borrower login and administrator-page denial', 'Borrower dashboard opens and user management does not render the admin user table', 'login ' . $borrowerLogin['status'] . ' dashboard ' . $borrowerDash['status'] . ' users ' . $borrowerUsers['status'], ($borrowerLogin['status'] === 302 && $borrowerDash['status'] === 200 && $borrowerUsers['status'] !== 200) ? 'PASS' : 'FAIL');

$paymentsBefore = null;
require_once $root . '/testing_evidence/_harness/env_prepend.php';
require_once $root . '/lending_system/includes/db_lending.php';
if ((string)$conn->query('SELECT DATABASE()')->fetchColumn() !== 'frams_capstone_test') {
    fwrite(STDERR, "HTTP check connected to the wrong database.\n");
    exit(1);
}
$paymentsBefore = (int)$conn->query('SELECT COUNT(*) FROM payments')->fetchColumn();
$csrfPost = http_call('POST', $base . '/payments_lending.php', [
    'loan_id' => $loanId,
    'amount_paid' => '1.00',
    'payment_date' => '2026-10-19',
    'payment_method' => 'Cash',
], $collectorCookie);
$paymentsAfter = (int)$conn->query('SELECT COUNT(*) FROM payments')->fetchColumn();
record('HTTP-11', 'Collector payment post without a CSRF token', 'HTTP 419 and the payment count is unchanged', 'HTTP ' . $csrfPost['status'] . ' payments ' . $paymentsBefore . ' to ' . $paymentsAfter, ($csrfPost['status'] === 419 && $paymentsBefore === $paymentsAfter) ? 'PASS' : 'FAIL', 'No payment was stored.');

$logout = http_call('GET', $base . '/logout_lending.php', null, $adminCookie);
$afterLogout = http_call('GET', $base . '/dashboard_lending.php', null, $adminCookie);
record('HTTP-12', 'Logout ends the administrator session', 'Dashboard request after logout redirects to login', 'logout ' . $logout['status'] . ' next ' . $afterLogout['status'] . ' login=' . (str_contains($afterLogout['headers'], 'login_lending.php') ? 'yes' : 'no'), ($afterLogout['status'] === 302 && str_contains($afterLogout['headers'], 'login_lending.php')) ? 'PASS' : 'FAIL');

$webhookBody = '{"simulation":true,"id":"evt_http_local"}';
$badWebhook = http_call('POST', $base . '/paymongo_webhook_lending.php', null, $cookieDir . '/hook.txt', ['Content-Type: application/json', 'Paymongo-Signature: t=1,te=abcd'], $webhookBody);
$getWebhook = http_call('GET', $base . '/paymongo_webhook_lending.php', null, $cookieDir . '/hookget.txt');
record('HTTP-13', 'Local webhook endpoint rejects an invalid signature', 'HTTP 401 and invalid_signature. This request is a local simulation.', 'HTTP ' . $badWebhook['status'] . ' body ' . substr($badWebhook['body'], 0, 180), ($badWebhook['status'] === 401 && str_contains($badWebhook['body'], 'invalid_signature')) ? 'PASS' : 'FAIL', 'PayMongo did not send this request.');
record('HTTP-14', 'Webhook endpoint rejects GET', 'HTTP 405', 'HTTP ' . $getWebhook['status'], $getWebhook['status'] === 405 ? 'PASS' : 'FAIL');

$samples = [];
for ($i = 0; $i < 5; $i++) {
    $sample = http_call('GET', $base . '/login_lending.php', null, $cookieDir . '/timing_login.txt');
    $samples['login_ms'][] = $sample['ms'];
}
$adminCookie2 = $cookieDir . '/admin_timing.txt';
$timingLogin = role_login('frams_test_admin', $password, $adminCookie2);
$samples['timing_login_status'] = $timingLogin['status'];
for ($i = 0; $i < 5; $i++) {
    $sample = http_call('GET', $base . '/clients_lending.php', null, $adminCookie2);
    $samples['clients_ms'][] = $sample['ms'];
    $samples['clients_status'][] = $sample['status'];
}
for ($i = 0; $i < 3; $i++) {
    $sample = http_call('GET', $base . '/reports_lending.php', null, $adminCookie2);
    $samples['reports_ms'][] = $sample['ms'];
    $samples['reports_status'][] = $sample['status'];
}
$avg = static function (array $values): float {
    return round(array_sum($values) / count($values), 2);
};
record(
    'HTTP-15',
    'Single-user page timing on the isolated server',
    'Five login samples, five client-list samples, and three report samples, with status 200',
    json_encode([
        'timing_login_status' => $samples['timing_login_status'],
        'login_ms' => $samples['login_ms'],
        'login_avg_ms' => $avg($samples['login_ms']),
        'clients_ms' => $samples['clients_ms'],
        'clients_avg_ms' => $avg($samples['clients_ms']),
        'clients_status' => $samples['clients_status'],
        'reports_ms' => $samples['reports_ms'],
        'reports_avg_ms' => $avg($samples['reports_ms']),
        'reports_status' => $samples['reports_status'],
    ]),
    (min($samples['clients_status']) === 200 && min($samples['reports_status']) === 200) ? 'PASS' : 'FAIL',
    'Sequential requests from one client. Not a concurrent load test.'
);

$counts = ['PASS' => 0, 'FAIL' => 0, 'BLOCKED' => 0];
foreach ($results as $row) {
    $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
}
file_put_contents(dirname(__DIR__) . '/evidence/http_checks.json', json_encode([
    'server' => $base,
    'database' => 'frams_capstone_test',
    'finished_at' => date('c'),
    'counts' => $counts,
    'results' => $results,
], JSON_PRETTY_PRINT));
echo 'HTTP counts ' . json_encode($counts) . PHP_EOL;
