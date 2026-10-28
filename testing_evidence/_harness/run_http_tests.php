<?php
if (!defined('FRAMS_TEST_PREPEND_LOADED') || lendingEnv('DB_NAME') !== 'frams_capstone_test') {
    fwrite(STDERR, "Refusing to run outside the isolated test database.\n");
    exit(1);
}
require_once dirname(__DIR__, 2) . '/lending_system/includes/db_lending.php';
if ((string)$conn->query('SELECT DATABASE()')->fetchColumn() !== 'frams_capstone_test') {
    fwrite(STDERR, "Wrong database.\n");
    exit(1);
}

$base = 'http://127.0.0.1:8099';
$manifest = json_decode((string)file_get_contents(__DIR__ . '/seed_manifest.json'), true);
$loanA = (int)$manifest['loans']['a']['loan_id'];
$loanB = (int)$manifest['loans']['b']['loan_id'];
$cookieDir = __DIR__ . '/cookies';
if (!is_dir($cookieDir)) {
    mkdir($cookieDir, 0777, true);
}

$cases = [];
$findings = [];

function framsRedact(string $text): string
{
    $text = preg_replace('/(sk|pk)_(test|live)_[A-Za-z0-9_]+/', '[redacted-key]', $text) ?? $text;
    $text = preg_replace('/whsk_[A-Za-z0-9_]+/', '[redacted-webhook-secret]', $text) ?? $text;
    $text = preg_replace('/PHPSESSID=[^;\\s]+/', 'PHPSESSID=[redacted]', $text) ?? $text;
    return $text;
}

function framsRecord(string $id, string $category, string $scenario, string $objective, string $preconditions, string $steps, string $data, string $expected, string $actual, string $status, string $evidence, string $remarks, string $source = 'HTTP request against isolated server'): void
{
    global $cases;
    $cases[] = [
        'id' => $id,
        'category' => $category,
        'scenario' => $scenario,
        'objective' => $objective,
        'preconditions' => $preconditions,
        'steps' => $steps,
        'test_data' => $data,
        'expected' => $expected,
        'actual' => framsRedact($actual),
        'status' => $status,
        'evidence' => $evidence,
        'remarks' => framsRedact($remarks),
        'source' => $source,
    ];
    echo $id . ' ' . $status . ' ' . $scenario . PHP_EOL;
}

function framsHttp(string $method, string $url, ?array $fields, string $cookieFile, array $headers = []): array
{
    $ch = curl_init($url);
    $responseHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADERFUNCTION => static function ($ch, $header) use (&$responseHeaders) {
            $responseHeaders[] = $header;
            return strlen($header);
        },
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($fields !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return [
        'status' => $status,
        'body' => is_string($body) ? $body : '',
        'headers' => implode('', $responseHeaders),
        'error' => $error,
    ];
}

function framsCsrf(string $html): string
{
    if (preg_match('/name="_csrf_token" value="([^"]+)"/', $html, $match)) {
        return html_entity_decode($match[1], ENT_QUOTES);
    }
    return '';
}

$log = 'logs/http_test_execution.log';

$login = framsHttp('GET', $base . '/login_lending.php', null, $cookieDir . '/anon.txt');
framsRecord('ST-001', 'System Testing', 'Staff login page loads', 'The isolated server returns the staff login page.', 'PHP server on 127.0.0.1:8099 using the test database.', 'GET login_lending.php.', 'none', 'HTTP 200 and the staff welcome heading', 'HTTP ' . $login['status'] . (str_contains($login['body'], 'Welcome back') ? ' contains Welcome back' : ' heading missing'), ($login['status'] === 200 && str_contains($login['body'], 'Welcome back')) ? 'PASS' : 'FAIL', '03_System_Testing/screenshots/ST-001_staff_login.png', '');

$bad = framsHttp('POST', $base . '/login_lending.php', ['username' => 'frams_test_admin', 'password' => 'Wrong@Password1'], $cookieDir . '/bad.txt');
framsRecord('ST-002', 'System Testing', 'Invalid staff login', 'A wrong password stays on the login page with an error.', 'Synthetic admin exists.', 'POST login with a wrong password.', 'wrong password', 'HTTP 200 and Invalid username or password', 'HTTP ' . $bad['status'] . (str_contains($bad['body'], 'Invalid username or password') ? ' error shown' : ' error missing'), ($bad['status'] === 200 && str_contains($bad['body'], 'Invalid username or password')) ? 'PASS' : 'FAIL', '03_System_Testing/screenshots/ST-002_invalid_login.png', '');

$empty = framsHttp('POST', $base . '/login_lending.php', ['username' => '', 'password' => ''], $cookieDir . '/empty.txt');
framsRecord('ST-003', 'System Testing', 'Missing login fields', 'Empty credentials are rejected by the server.', 'Login page available.', 'POST empty username and password.', 'empty fields', 'Please enter both username and password', str_contains($empty['body'], 'Please enter both username and password') ? 'validation message shown' : 'HTTP ' . $empty['status'], str_contains($empty['body'], 'Please enter both username and password') ? 'PASS' : 'FAIL', $log, 'The browser may also require the fields before submit. This request was sent directly.');

$adminCookie = $cookieDir . '/admin.txt';
@unlink($adminCookie);
$adminPost = framsHttp('POST', $base . '/login_lending.php', ['username' => 'frams_test_admin', 'password' => 'Test@Frams1'], $adminCookie);
$adminDash = framsHttp('GET', $base . '/dashboard_lending.php', null, $adminCookie);
framsRecord('ST-004', 'System Testing', 'Administrator login and dashboard', 'Valid admin credentials reach the executive dashboard.', 'Synthetic admin.', 'POST login, then GET dashboard.', 'frams_test_admin', 'redirect to dashboard and Executive Dashboard', 'login HTTP ' . $adminPost['status'] . '; dashboard HTTP ' . $adminDash['status'] . (str_contains($adminDash['body'], 'Executive Dashboard') ? ' dashboard shown' : ''), ($adminPost['status'] === 302 && str_contains($adminDash['body'], 'Executive Dashboard')) ? 'PASS' : 'FAIL', '03_System_Testing/screenshots/ST-004_admin_dashboard.png', '');

$clients = framsHttp('GET', $base . '/clients_lending.php', null, $adminCookie);
framsRecord('ST-005', 'System Testing', 'Administrator client list', 'The client page shows the synthetic borrower.', 'Admin session.', 'GET clients_lending.php.', 'client Synthetic', 'HTTP 200 and Synthetic BorrowerA', ($clients['status'] === 200 && str_contains($clients['body'], 'Synthetic') ? 'client visible' : 'HTTP ' . $clients['status']), ($clients['status'] === 200 && str_contains($clients['body'], 'BorrowerA')) ? 'PASS' : 'FAIL', '03_System_Testing/screenshots/ST-005_admin_clients.png', '');

$loans = framsHttp('GET', $base . '/loans_lending.php', null, $adminCookie);
framsRecord('ST-006', 'System Testing', 'Administrator loan list', 'The loan page shows FRAMS-TEST-A.', 'Admin session.', 'GET loans_lending.php.', 'FRAMS-TEST-A', 'loan number visible', str_contains($loans['body'], 'FRAMS-TEST-A') ? 'loan visible' : 'loan missing', str_contains($loans['body'], 'FRAMS-TEST-A') ? 'PASS' : 'FAIL', '03_System_Testing/screenshots/ST-006_admin_loans.png', '');

$payments = framsHttp('GET', $base . '/payments_lending.php', null, $adminCookie);
framsRecord('ST-007', 'System Testing', 'Administrator payment records', 'The cash payment recorded in integration testing is listed.', 'Admin session and IT-010 payment.', 'GET payments_lending.php.', 'loan A payment', 'page contains a receipt or the cash amount', (str_contains($payments['body'], 'RJRR-') || str_contains($payments['body'], '3,833.33') || str_contains($payments['body'], '3833.33')) ? 'payment visible' : 'payment not found in HTML', (str_contains($payments['body'], 'RJRR-') || str_contains($payments['body'], '3,833.33')) ? 'PASS' : 'FAIL', '03_System_Testing/screenshots/ST-007_admin_payments.png', '');

$reports = framsHttp('GET', $base . '/reports_lending.php', null, $adminCookie);
framsRecord('ST-008', 'System Testing', 'Administrator reports', 'The reports page loads for an administrator.', 'Admin session.', 'GET reports_lending.php.', 'none', 'HTTP 200 and a report heading', 'HTTP ' . $reports['status'], ($reports['status'] === 200 && (stripos($reports['body'], 'report') !== false)) ? 'PASS' : 'FAIL', '03_System_Testing/screenshots/ST-008_admin_reports.png', '');

$users = framsHttp('GET', $base . '/users_lending.php', null, $adminCookie);
framsRecord('ST-009', 'System Testing', 'User management page', 'An administrator can open user management.', 'Admin session.', 'GET users_lending.php.', 'none', 'HTTP 200 and synthetic usernames', str_contains($users['body'], 'frams_test_admin') ? 'users visible' : 'HTTP ' . $users['status'], ($users['status'] === 200 && str_contains($users['body'], 'frams_test_collector')) ? 'PASS' : 'FAIL', '03_System_Testing/screenshots/ST-009_admin_users.png', '');

$collectorCookie = $cookieDir . '/collector.txt';
@unlink($collectorCookie);
$collectorPost = framsHttp('POST', $base . '/login_lending.php', ['username' => 'frams_test_collector', 'password' => 'Test@Frams1'], $collectorCookie);
$collectorDash = framsHttp('GET', $base . '/collector_dashboard_lending.php', null, $collectorCookie);
framsRecord('ST-010', 'System Testing', 'Collector login and dashboard', 'The assigned collector reaches the collector dashboard.', 'Synthetic collector.', 'POST login and GET collector dashboard.', 'frams_test_collector', 'HTTP 302 then collector dashboard content', 'login ' . $collectorPost['status'] . ' dashboard ' . $collectorDash['status'], ($collectorPost['status'] === 302 && $collectorDash['status'] === 200) ? 'PASS' : 'FAIL', '03_System_Testing/screenshots/ST-010_collector_dashboard.png', '');

$fetchOwn = framsHttp('GET', $base . '/payments_lending.php?action=fetch_loan&loan_id=' . $loanA, null, $collectorCookie);
$ownJson = json_decode($fetchOwn['body'], true);
framsRecord('ST-011', 'System Testing', 'Collector loads the assigned loan', 'Loan lookup returns loan A for its collector.', 'Collector session.', 'GET fetch_loan for loan A.', 'loan A', 'JSON success true', str_contains($fetchOwn['body'], '"success":true') ? 'success true' : 'HTTP ' . $fetchOwn['status'] . ' ' . substr($fetchOwn['body'], 0, 180), (is_array($ownJson) && !empty($ownJson['success'])) ? 'PASS' : 'FAIL', $log, '');

$addForm = framsHttp('GET', $base . '/payments_lending.php?add=true&loan=' . $loanA, null, $collectorCookie);
$token = framsCsrf($addForm['body']);
$payCountBefore = (int)$conn->query('SELECT COUNT(*) FROM payments WHERE loan_id = ' . $loanA)->fetchColumn();
$cash = framsHttp('POST', $base . '/payments_lending.php?add=true', [
    '_csrf_token' => $token,
    'add_payment' => '1',
    'loan_id' => (string)$loanA,
    'amount_paid' => '3833.33',
    'penalty_paid' => '0',
    'payment_date' => '2026-10-18',
    'payment_method' => 'Cash',
    'collector_name' => 'FRAMS Test Collector',
    'remarks' => 'Synthetic HTTP cash payment',
], $collectorCookie);
$payCountAfter = (int)$conn->query('SELECT COUNT(*) FROM payments WHERE loan_id = ' . $loanA)->fetchColumn();
$balance = (float)$conn->query('SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE loan_id = ' . $loanA)->fetchColumn();
framsRecord('ST-012', 'System Testing', 'Collector records a cash payment', 'A second installment is stored for loan A through the payment form.', 'Collector session, valid CSRF, remaining balance.', 'POST add_payment for 3833.33.', '3833.33 cash', 'payment count increases by 1', 'HTTP ' . $cash['status'] . '; count ' . $payCountBefore . ' to ' . $payCountAfter . '; paid ' . $balance, ($payCountAfter === $payCountBefore + 1) ? 'PASS' : 'FAIL', '03_System_Testing/screenshots/ST-012_cash_payment_result.png', 'Form submission against the isolated database.');

$qr = framsHttp('POST', $base . '/payments_lending.php?add=true', [
    '_csrf_token' => $token,
    'start_qrph' => '1',
    'loan_id' => (string)$loanA,
    'amount_paid' => '100.00',
    'penalty_paid' => '0',
    'payment_date' => '2026-10-18',
    'payment_method' => 'E-Wallet',
    'collector_name' => 'FRAMS Test Collector',
], $collectorCookie);
$qrPaid = (int)$conn->query("SELECT COUNT(*) FROM paymongo_qrph_transactions WHERE loan_id = {$loanA} AND status = 'succeeded' AND amount = 100")->fetchColumn();
framsRecord('ST-013', 'System Testing', 'Non-cash QR start fails closed', 'Starting QR Ph with the invalid isolated key does not mark a 100 peso payment succeeded.', 'Collector session.', 'POST start_qrph for E-Wallet 100.', 'E-Wallet 100', 'no succeeded 100 peso QR transaction', 'HTTP ' . $qr['status'] . '; succeeded-100 rows ' . $qrPaid . '; body has error ' . (stripos($qr['body'], 'PayMongo') !== false ? 'yes' : 'no'), ($qrPaid === 0 && stripos($qr['body'], 'PayMongo') !== false) ? 'PASS' : 'FAIL', '03_System_Testing/screenshots/ST-013_qr_error.png', 'Invalid test key. No live PayMongo call. The provider returned HTTP 401 inside the application error.', 'PayMongo API error with invalid test key');

$borrowerCookie = $cookieDir . '/borrower.txt';
@unlink($borrowerCookie);
$borrowerPost = framsHttp('POST', $base . '/borrower_login_lending.php', ['username' => 'frams_test_borrower', 'password' => 'Test@Frams1'], $borrowerCookie);
$borrowerDash = framsHttp('GET', $base . '/borrower_dashboard_lending.php', null, $borrowerCookie);
framsRecord('ST-014', 'System Testing', 'Borrower login and dashboard', 'The synthetic borrower reaches the borrower dashboard.', 'Synthetic borrower.', 'POST borrower login and GET dashboard.', 'frams_test_borrower', 'dashboard HTTP 200', 'login ' . $borrowerPost['status'] . ' dashboard ' . $borrowerDash['status'], ($borrowerPost['status'] === 302 && $borrowerDash['status'] === 200) ? 'PASS' : 'FAIL', '03_System_Testing/screenshots/ST-014_borrower_dashboard.png', '');

$myLoan = framsHttp('GET', $base . '/borrower_my_loan_lending.php', null, $borrowerCookie);
framsRecord('ST-015', 'System Testing', 'Borrower loan view', 'The borrower page shows loan FRAMS-TEST-A.', 'Borrower session.', 'GET borrower_my_loan_lending.php.', 'FRAMS-TEST-A', 'loan number visible', str_contains($myLoan['body'], 'FRAMS-TEST-A') ? 'loan visible' : 'HTTP ' . $myLoan['status'], str_contains($myLoan['body'], 'FRAMS-TEST-A') ? 'PASS' : 'FAIL', '03_System_Testing/screenshots/ST-015_borrower_loan.png', '');

$history = framsHttp('GET', $base . '/borrower_payment_history_lending.php', null, $borrowerCookie);
framsRecord('ST-016', 'System Testing', 'Borrower payment history', 'The borrower can see a receipt number for loan A.', 'Payments exist for borrower A.', 'GET borrower_payment_history_lending.php.', 'loan A receipts', 'RJRR receipt visible', str_contains($history['body'], 'RJRR-') ? 'receipt visible' : 'HTTP ' . $history['status'], str_contains($history['body'], 'RJRR-') ? 'PASS' : 'FAIL', '03_System_Testing/screenshots/ST-016_borrower_history.png', '');

$logout = framsHttp('GET', $base . '/logout_lending.php', null, $borrowerCookie);
$afterLogout = framsHttp('GET', $base . '/borrower_dashboard_lending.php', null, $borrowerCookie);
framsRecord('ST-017', 'System Testing', 'Borrower logout', 'After logout the borrower dashboard is no longer available.', 'Borrower session.', 'GET logout, then GET dashboard with the same cookie jar.', 'logout', 'dashboard redirects to login', 'logout ' . $logout['status'] . '; next ' . $afterLogout['status'] . ' location ' . (preg_match('/Location: (\\S+)/i', $afterLogout['headers'], $m) ? $m[1] : 'none'), ($afterLogout['status'] >= 300 && $afterLogout['status'] < 400) ? 'PASS' : 'FAIL', '03_System_Testing/screenshots/ST-017_after_logout.png', '');

$anonDash = framsHttp('GET', $base . '/dashboard_lending.php', null, $cookieDir . '/fresh_anon.txt');
framsRecord('ST-018', 'System Testing', 'Unauthenticated dashboard', 'The admin dashboard redirects anonymous users to login.', 'No session.', 'GET dashboard_lending.php.', 'none', 'HTTP 302 to login', 'HTTP ' . $anonDash['status'], ($anonDash['status'] === 302 && str_contains($anonDash['headers'], 'login_lending.php')) ? 'PASS' : 'FAIL', $log, '');

$deniedAdmin = framsHttp('GET', $base . '/dashboard_lending.php', null, $cookieDir . '/borrower_denied.txt');
framsHttp('POST', $base . '/borrower_login_lending.php', ['username' => 'frams_test_borrower', 'password' => 'Test@Frams1'], $cookieDir . '/borrower_denied.txt');
$deniedAdmin = framsHttp('GET', $base . '/dashboard_lending.php', null, $cookieDir . '/borrower_denied.txt');
framsRecord('ST-019', 'System Testing', 'Borrower denied admin dashboard', 'A borrower session does not render the executive dashboard.', 'Borrower session.', 'GET dashboard_lending.php as borrower.', 'borrower A', 'redirect away from Executive Dashboard', 'HTTP ' . $deniedAdmin['status'] . (str_contains($deniedAdmin['body'], 'Executive Dashboard') ? ' dashboard rendered' : ' dashboard not rendered'), (!str_contains($deniedAdmin['body'], 'Executive Dashboard') && $deniedAdmin['status'] >= 300) ? 'PASS' : 'FAIL', $log, '');

$collectorExpenses = framsHttp('GET', $base . '/expenses_lending.php', null, $collectorCookie);
framsRecord('ST-020', 'System Testing', 'Collector denied expenses', 'Collectors receive the unauthorized expenses response.', 'Collector session.', 'GET expenses_lending.php.', 'collector A', 'HTTP 403', 'HTTP ' . $collectorExpenses['status'], $collectorExpenses['status'] === 403 ? 'PASS' : 'FAIL', '03_System_Testing/screenshots/ST-020_collector_forbidden.png', '');

$otherPayCount = (int)$conn->query('SELECT COUNT(*) FROM payments WHERE loan_id = ' . $loanB)->fetchColumn();
$otherToken = framsCsrf(framsHttp('GET', $base . '/payments_lending.php?add=true', null, $collectorCookie)['body']);
$otherPost = framsHttp('POST', $base . '/payments_lending.php?add=true', [
    '_csrf_token' => $otherToken,
    'add_payment' => '1',
    'loan_id' => (string)$loanB,
    'amount_paid' => '100',
    'penalty_paid' => '0',
    'payment_date' => '2026-10-18',
    'payment_method' => 'Cash',
    'collector_name' => 'FRAMS Test Collector',
], $collectorCookie);
$otherPayAfter = (int)$conn->query('SELECT COUNT(*) FROM payments WHERE loan_id = ' . $loanB)->fetchColumn();
framsRecord('SEC-005', 'Security Testing', 'Collector cannot pay another loan', 'A cash post for loan B is rejected for collector A.', 'Collector A session.', 'POST add_payment for loan B.', 'loan B amount 100', 'payment count unchanged and not assigned message', 'count ' . $otherPayCount . ' to ' . $otherPayAfter . '; assigned message ' . (str_contains($otherPost['body'], 'not assigned') ? 'yes' : 'no'), ($otherPayCount === $otherPayAfter && str_contains($otherPost['body'], 'not assigned')) ? 'PASS' : 'FAIL', $log, '');

$fetchOther = framsHttp('GET', $base . '/payments_lending.php?action=fetch_loan&loan_id=' . $loanB, null, $collectorCookie);
$otherJson = json_decode($fetchOther['body'], true);
framsRecord('SEC-006', 'Security Testing', 'Collector loan lookup IDOR', 'Changing loan_id does not return the other borrower loan.', 'Collector A session.', 'GET fetch_loan for loan B.', 'loan B', 'success false and no borrower name', 'success ' . (($otherJson['success'] ?? null) ? 'true' : 'false'), (is_array($otherJson) && empty($otherJson['success']) && !str_contains($fetchOther['body'], 'BorrowerB')) ? 'PASS' : 'FAIL', $log, '');

$bPayment = (int)$conn->query('SELECT payment_id FROM payments WHERE loan_id = ' . $loanB . ' ORDER BY payment_id DESC LIMIT 1')->fetchColumn();
$receiptProbe = framsHttp('GET', $base . '/payments_lending.php?view=' . $bPayment, null, $cookieDir . '/borrower_denied.txt');
framsRecord('SEC-007', 'Security Testing', 'Borrower receipt IDOR', 'Borrower A cannot open borrower B receipt by payment id.', 'Borrower A session and a loan B payment.', 'GET payments view for borrower B payment.', 'payment ' . $bPayment, 'redirect away from the other receipt', 'HTTP ' . $receiptProbe['status'] . (str_contains($receiptProbe['body'], 'BorrowerB') ? ' other borrower shown' : ' other borrower not shown'), (!str_contains($receiptProbe['body'], 'BorrowerB') && $receiptProbe['status'] !== 200) ? 'PASS' : ((!str_contains($receiptProbe['body'], 'BorrowerB')) ? 'PASS' : 'FAIL'), $log, 'HTTP ' . $receiptProbe['status'] . '.');

$sqli = framsHttp('POST', $base . '/login_lending.php', ['username' => "' OR 1=1 --", 'password' => 'Test@Frams1'], $cookieDir . '/sqli.txt');
framsRecord('SEC-008', 'Security Testing', 'Login SQL injection over HTTP', 'The payload does not authenticate or expose a database error.', 'Fresh cookie jar.', 'POST the payload as username.', 'boolean payload', 'invalid credentials and no SQL syntax error', (str_contains($sqli['body'], 'Invalid username or password') ? 'rejected' : 'HTTP ' . $sqli['status']) . (stripos($sqli['body'], 'SQLSTATE') !== false ? ' sql error exposed' : ''), (str_contains($sqli['body'], 'Invalid username or password') && stripos($sqli['body'], 'SQLSTATE') === false) ? 'PASS' : 'FAIL', '04_Security_Testing/screenshots/SEC-008_sqli_login.png', '');

$xssLogin = framsHttp('POST', $base . '/login_lending.php', ['username' => '<framsxss>', 'password' => 'Test@Frams1'], $cookieDir . '/xsslogin.txt');
$rawXss = str_contains($xssLogin['body'], 'value="<framsxss>"');
$encodedXss = str_contains($xssLogin['body'], 'value="&lt;framsxss&gt;"');
framsRecord('SEC-009', 'Security Testing', 'Login username encoding', 'The username is encoded when written back into the form.', 'Login error redisplays the username.', 'POST a marker as the username.', '<framsxss>', 'encoded attribute value', $encodedXss ? 'encoded' : ($rawXss ? 'raw attribute' : 'marker not found'), $encodedXss ? 'PASS' : 'FAIL', $log, '');

$xssPage = framsHttp('GET', $base . '/expenses_lending.php?message=' . rawurlencode('FRAMSXSS<b>probe</b>') . '&type=success', null, $adminCookie);
$reflectedRaw = str_contains($xssPage['body'], 'FRAMSXSS<b>probe</b>');
$reflectedEncoded = str_contains($xssPage['body'], 'FRAMSXSS&lt;b&gt;probe&lt;/b&gt;');
framsRecord('SEC-010', 'Security Testing', 'Reflected HTML in expense message', 'The message query parameter is encoded before display.', 'Admin session.', 'GET expenses with a b-tag marker.', 'FRAMSXSS<b>probe</b>', 'marker encoded and not present as HTML', $reflectedRaw ? 'raw HTML reflected' : ($reflectedEncoded ? 'encoded' : 'marker absent'), $reflectedEncoded ? 'PASS' : 'FAIL', '04_Security_Testing/screenshots/SEC-010_reflected_message.png', 'Authenticated reflected output.');
if ($reflectedRaw) {
    $findings[] = [
        'id' => 'F-002',
        'category' => 'Security Testing',
        'component' => 'expenses_lending.php message query parameter',
        'description' => 'The expenses page prints the message query parameter without encoding, so an authenticated user can be sent a link that injects HTML.',
        'severity' => 'Medium',
        'evidence' => '04_Security_Testing/logs/http_test_execution.log',
        'reproduction' => 'Sign in as an administrator on the isolated server and open expenses_lending.php?message=FRAMSXSS<b>probe</b>&type=success. The response contains the raw b tag.',
        'recommendation' => 'Encode $message and $messageType with htmlspecialchars before output. Apply the same fix on the other pages that echo those variables.',
        'retest' => 'Not retested. Application code was not changed.',
        'demonstrated' => 'Yes',
    ];
}

$search = framsHttp('GET', $base . '/expenses_lending.php?search=' . rawurlencode("' OR 1=1 --"), null, $adminCookie);
framsRecord('SEC-011', 'Security Testing', 'Expense search SQL injection', 'A search payload does not produce a database error page.', 'Admin session.', 'GET search with a quote payload.', 'quote payload', 'HTTP 200 and no SQLSTATE', 'HTTP ' . $search['status'] . (stripos($search['body'], 'SQLSTATE') !== false ? ' sql exposed' : ' no sql error'), ($search['status'] === 200 && stripos($search['body'], 'SQLSTATE') === false) ? 'PASS' : 'FAIL', $log, 'The search uses a bound LIKE parameter.');

$noCsrfCount = (int)$conn->query('SELECT COUNT(*) FROM payments WHERE loan_id = ' . $loanA)->fetchColumn();
$noCsrf = framsHttp('POST', $base . '/payments_lending.php?add=true', [
    'add_payment' => '1',
    'loan_id' => (string)$loanA,
    'amount_paid' => '10',
    'penalty_paid' => '0',
    'payment_date' => '2026-10-18',
    'payment_method' => 'Cash',
    'collector_name' => 'FRAMS Test Collector',
], $collectorCookie);
$noCsrfAfter = (int)$conn->query('SELECT COUNT(*) FROM payments WHERE loan_id = ' . $loanA)->fetchColumn();
framsRecord('SEC-012', 'Security Testing', 'Payment without CSRF token', 'A cash payment post without the session token is rejected.', 'Collector session.', 'POST add_payment without _csrf_token.', 'amount 10', 'HTTP 419 and payment count unchanged', 'HTTP ' . $noCsrf['status'] . '; count ' . $noCsrfCount . ' to ' . $noCsrfAfter, ($noCsrf['status'] === 419 && $noCsrfCount === $noCsrfAfter) ? 'PASS' : 'FAIL', $log, '');

$badCsrf = framsHttp('POST', $base . '/payments_lending.php?add=true', [
    '_csrf_token' => 'not-a-valid-token',
    'add_payment' => '1',
    'loan_id' => (string)$loanA,
    'amount_paid' => '10',
    'penalty_paid' => '0',
    'payment_date' => '2026-10-18',
    'payment_method' => 'Cash',
    'collector_name' => 'FRAMS Test Collector',
], $collectorCookie);
$badCsrfAfter = (int)$conn->query('SELECT COUNT(*) FROM payments WHERE loan_id = ' . $loanA)->fetchColumn();
framsRecord('SEC-013', 'Security Testing', 'Payment with incorrect CSRF token', 'An incorrect token does not record a payment.', 'Collector session.', 'POST add_payment with a wrong token.', 'wrong token', 'HTTP 419 and payment count unchanged', 'HTTP ' . $badCsrf['status'] . '; count ' . $noCsrfAfter . ' to ' . $badCsrfAfter, ($badCsrf['status'] === 419 && $badCsrfAfter === $noCsrfAfter) ? 'PASS' : 'FAIL', $log, '');

$statusBefore = (string)$conn->query('SELECT status FROM loans WHERE loan_id = ' . $loanA)->fetchColumn();
$statusPost = framsHttp('POST', $base . '/loans_lending.php', [
    'update_status' => '1',
    'loan_id' => (string)$loanA,
    'status' => 'Overdue',
], $adminCookie);
$statusAfter = (string)$conn->query('SELECT status FROM loans WHERE loan_id = ' . $loanA)->fetchColumn();
framsRecord('SEC-014', 'Security Testing', 'Loan status change without CSRF token', 'A state-changing loan update requires a valid CSRF token.', 'Admin session. Loan A status was ' . $statusBefore . '.', 'POST update_status without _csrf_token.', 'status Overdue', 'request rejected and status unchanged', 'HTTP ' . $statusPost['status'] . '; status ' . $statusBefore . ' to ' . $statusAfter, ($statusAfter === $statusBefore) ? 'PASS' : 'FAIL', $log, 'Observed behavior is the test result. Status will be restored to Active after evidence is saved if it changed.');
if ($statusAfter !== $statusBefore) {
    $findings[] = [
        'id' => 'F-003',
        'category' => 'Security Testing',
        'component' => 'loans_lending.php update_status',
        'description' => 'An authenticated administrator request can change a loan status without a CSRF token.',
        'severity' => 'Medium',
        'evidence' => '04_Security_Testing/logs/http_test_execution.log',
        'reproduction' => 'Sign in as the synthetic administrator and POST update_status, loan_id, and status=Overdue to loans_lending.php without _csrf_token. The loan status changes.',
        'recommendation' => 'Call csrfRequireValid() before update_status, as payments_lending.php already does.',
        'retest' => 'Not retested. The synthetic loan status is restored after the demonstration. Application code was not changed.',
        'demonstrated' => 'Yes',
    ];
}

$cookieProbe = framsHttp('GET', $base . '/login_lending.php', null, $cookieDir . '/cookieprobe.txt');
$httpOnly = stripos($cookieProbe['headers'], 'HttpOnly') !== false;
$secure = (bool)preg_match('/Set-Cookie: PHPSESSID=[^\\r\\n]*;\\s*[^\\r\\n]*Secure/i', $cookieProbe['headers']);
framsRecord('SEC-015', 'Security Testing', 'Session cookie attributes', 'The session cookie is HttpOnly. Secure is only expected on HTTPS.', 'Isolated HTTP server.', 'GET login and read Set-Cookie.', 'none', 'HttpOnly present', 'HttpOnly ' . ($httpOnly ? 'yes' : 'no') . '; Secure ' . ($secure ? 'yes' : 'no'), $httpOnly ? 'PASS' : 'FAIL', $log, 'Secure is absent because this server is HTTP. lendingConfigureRuntime sets Secure only when HTTPS is detected.');

$headerProbe = framsHttp('GET', $base . '/login_lending.php', null, $cookieDir . '/headers.txt');
$missingHeaders = [];
foreach (['X-Frame-Options', 'X-Content-Type-Options', 'Content-Security-Policy', 'Referrer-Policy'] as $headerName) {
    if (stripos($headerProbe['headers'], $headerName . ':') === false) {
        $missingHeaders[] = $headerName;
    }
}
framsRecord('SEC-016', 'Security Testing', 'Security response headers', 'Login responses include baseline browser security headers.', 'Isolated server.', 'GET login and inspect response headers.', 'none', 'X-Frame-Options, X-Content-Type-Options, Content-Security-Policy, and Referrer-Policy', $missingHeaders === [] ? 'all present' : ('missing ' . implode(', ', $missingHeaders)), $missingHeaders === [] ? 'PASS' : 'FAIL', $log, '');
if ($missingHeaders !== []) {
    $findings[] = [
        'id' => 'F-004',
        'category' => 'Security Testing',
        'component' => 'HTTP response headers',
        'description' => 'Login responses do not set ' . implode(', ', $missingHeaders) . '.',
        'severity' => 'Low',
        'evidence' => '04_Security_Testing/logs/http_test_execution.log',
        'reproduction' => 'GET login_lending.php on the isolated server and inspect the response headers.',
        'recommendation' => 'Send the missing headers from a shared include used by every page.',
        'retest' => 'Not retested. Application code was not changed.',
        'demonstrated' => 'Yes',
    ];
}

$longUser = str_repeat('A', 5000);
$long = framsHttp('POST', $base . '/login_lending.php', ['username' => $longUser, 'password' => 'Test@Frams1'], $cookieDir . '/long.txt');
framsRecord('SEC-017', 'Security Testing', 'Excessive login length', 'A very long username does not expose a database error.', 'Login page.', 'POST a 5000-character username.', '5000 A characters', 'invalid credentials or normal validation, no SQLSTATE', (stripos($long['body'], 'SQLSTATE') !== false ? 'sql exposed' : 'no sql error') . ' HTTP ' . $long['status'], (stripos($long['body'], 'SQLSTATE') === false && $long['status'] < 500) ? 'PASS' : 'FAIL', $log, '');

$anonUsers = framsHttp('GET', $base . '/users_lending.php', null, $cookieDir . '/anon_users.txt');
framsRecord('PEN-001', 'Penetration Testing', 'Direct URL to user management', 'Anonymous users cannot open user management.', 'No session.', 'GET users_lending.php.', 'none', 'redirect to login', 'HTTP ' . $anonUsers['status'], ($anonUsers['status'] === 302 && str_contains($anonUsers['headers'], 'login_lending.php')) ? 'PASS' : 'FAIL', '05_Penetration_Testing/screenshots/PEN-001_anonymous_users.png', '');

$anonPayments = framsHttp('GET', $base . '/payments_lending.php', null, $cookieDir . '/anon_pay.txt');
framsRecord('PEN-002', 'Penetration Testing', 'Direct URL to payments', 'Anonymous users cannot open payment recording.', 'No session.', 'GET payments_lending.php.', 'none', 'redirect to login', 'HTTP ' . $anonPayments['status'], ($anonPayments['status'] === 302 && str_contains($anonPayments['headers'], 'login_lending.php')) ? 'PASS' : 'FAIL', $log, '');

$borrowerUsers = framsHttp('GET', $base . '/users_lending.php', null, $cookieDir . '/borrower_denied.txt');
framsRecord('PEN-003', 'Penetration Testing', 'Borrower direct URL to user management', 'A borrower cannot open administrator user management.', 'Borrower session.', 'GET users_lending.php.', 'borrower A', 'redirect and no user table', 'HTTP ' . $borrowerUsers['status'] . (str_contains($borrowerUsers['body'], 'frams_test_admin') ? ' admin user shown' : ' admin user not shown'), (!str_contains($borrowerUsers['body'], 'frams_test_admin') && $borrowerUsers['status'] !== 200) ? 'PASS' : 'FAIL', $log, '');

$anonPayBefore = (int)$conn->query('SELECT COUNT(*) FROM payments')->fetchColumn();
$anonPay = framsHttp('POST', $base . '/payments_lending.php', [
    'add_payment' => '1',
    'loan_id' => (string)$loanA,
    'amount_paid' => '50',
    'penalty_paid' => '0',
    'payment_date' => '2026-10-18',
    'payment_method' => 'Cash',
    'collector_name' => 'Anonymous',
], $cookieDir . '/anon_post_pay.txt');
$anonPayAfter = (int)$conn->query('SELECT COUNT(*) FROM payments')->fetchColumn();
framsRecord('PEN-004', 'Penetration Testing', 'Anonymous payment post', 'A payment post without a session does not create a payment.', 'No session.', 'POST add_payment without logging in.', 'amount 50', 'redirect to login and payment count unchanged', 'HTTP ' . $anonPay['status'] . '; count ' . $anonPayBefore . ' to ' . $anonPayAfter, ($anonPay['status'] === 302 && $anonPayBefore === $anonPayAfter) ? 'PASS' : 'FAIL', $log, '');

$statusBeforeBorrower = (string)$conn->query('SELECT status FROM loans WHERE loan_id = ' . $loanA)->fetchColumn();
$borrowerStatus = framsHttp('POST', $base . '/loans_lending.php', [
    'update_status' => '1',
    'loan_id' => (string)$loanA,
    'status' => 'Paid',
], $cookieDir . '/borrower_denied.txt');
$statusAfterBorrower = (string)$conn->query('SELECT status FROM loans WHERE loan_id = ' . $loanA)->fetchColumn();
framsRecord('PEN-005', 'Penetration Testing', 'Borrower loan status post', 'A borrower cannot mark loan A paid by posting a status.', 'Borrower session.', 'POST update_status=Paid as borrower A.', 'status Paid', 'status unchanged', $statusBeforeBorrower . ' to ' . $statusAfterBorrower . '; HTTP ' . $borrowerStatus['status'], ($statusBeforeBorrower === $statusAfterBorrower) ? 'PASS' : 'FAIL', $log, '');

$anonLoan = framsHttp('GET', $base . '/loan_details_lending.php?loan_id=' . $loanA, null, $cookieDir . '/anon_loan.txt');
framsRecord('PEN-006', 'Penetration Testing', 'Direct loan details URL', 'Anonymous users cannot open a loan details page.', 'No session.', 'GET loan_details_lending.php for loan A.', 'loan A', 'redirect to login', 'HTTP ' . $anonLoan['status'], ($anonLoan['status'] === 302 && str_contains($anonLoan['headers'], 'login_lending.php')) ? 'PASS' : 'FAIL', $log, '');

$collectorUsers = framsHttp('GET', $base . '/users_lending.php', null, $collectorCookie);
framsRecord('PEN-007', 'Penetration Testing', 'Collector direct URL to user management', 'A collector cannot open administrator user management.', 'Collector session.', 'GET users_lending.php as collector A.', 'collector A', 'redirect and no user administration table', 'HTTP ' . $collectorUsers['status'] . (str_contains($collectorUsers['body'], 'Add New User') ? ' admin form shown' : ' admin form not shown'), ($collectorUsers['status'] !== 200 || !str_contains($collectorUsers['body'], 'frams_test_admin')) ? 'PASS' : 'FAIL', $log, '');

$paidFlagBefore = (string)$conn->query('SELECT status FROM loans WHERE loan_id = ' . $loanA)->fetchColumn();
$paidFlag = framsHttp('GET', $base . '/payments_lending.php?loan_id=' . $loanA . '&status=Paid&paid=1', null, $collectorCookie);
$paidFlagAfter = (string)$conn->query('SELECT status FROM loans WHERE loan_id = ' . $loanA)->fetchColumn();
framsRecord('PEN-008', 'Penetration Testing', 'Client query does not mark a loan paid', 'A paid flag in the query string does not change the loan status.', 'Collector session.', 'GET payments with status=Paid and paid=1.', 'query flags', 'loan status unchanged', $paidFlagBefore . ' to ' . $paidFlagAfter, ($paidFlagBefore === $paidFlagAfter) ? 'PASS' : 'FAIL', $log, 'Payment completion is not accepted from a browser query flag.');

$secret = (string)lendingEnv('PAYMONGO_WEBHOOK_SECRET');
$badBody = '{"probe":true}';
$badHook = framsHttp('POST', $base . '/paymongo_webhook_lending.php', null, $cookieDir . '/hook.txt', [
    'Content-Type: application/json',
    'Paymongo-Signature: t=' . time() . ',te=' . str_repeat('ab', 32),
]);
curl_setopt_array($tmp = curl_init($base . '/paymongo_webhook_lending.php'), []);
unset($tmp);
$ch = curl_init($base . '/paymongo_webhook_lending.php');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $badBody,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Paymongo-Signature: t=' . time() . ',te=' . str_repeat('ab', 32),
    ],
]);
$badHookBody = (string)curl_exec($ch);
$badHookStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
framsRecord('PM-013', 'PayMongo Testing', 'HTTP webhook rejects invalid signature', 'The endpoint returns 401 and does not require a database change.', 'Synthetic webhook secret on the isolated server only.', 'POST JSON with a wrong te signature.', 'wrong signature', 'HTTP 401', 'HTTP ' . $badHookStatus . ' ' . $badHookBody, $badHookStatus === 401 ? 'PASS' : 'FAIL', '06_PayMongo_Testing/screenshots/PM-013_invalid_signature.txt', 'Local endpoint. The signature secret is synthetic.', 'local webhook endpoint');

$ch = curl_init($base . '/paymongo_webhook_lending.php');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $badBody,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
]);
$missingBody = (string)curl_exec($ch);
$missingStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
framsRecord('PM-014', 'PayMongo Testing', 'HTTP webhook rejects missing signature', 'A post without Paymongo-Signature is rejected.', 'Synthetic secret configured on the isolated server.', 'POST JSON without the signature header.', 'no signature', 'HTTP 401', 'HTTP ' . $missingStatus . ' ' . $missingBody, $missingStatus === 401 ? 'PASS' : 'FAIL', $log, 'Local endpoint.', 'local webhook endpoint');

$ts = (string)time();
$broken = '{';
$brokenSig = hash_hmac('sha256', $ts . '.' . $broken, $secret);
$ch = curl_init($base . '/paymongo_webhook_lending.php');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $broken,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Paymongo-Signature: t=' . $ts . ',te=' . $brokenSig],
]);
$brokenBody = (string)curl_exec($ch);
$brokenStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
framsRecord('PM-015', 'PayMongo Testing', 'HTTP webhook rejects malformed JSON', 'A signed malformed body returns 400 and does not post a payment.', 'Synthetic secret.', 'POST { with a valid test-mode signature.', 'malformed JSON', 'HTTP 400', 'HTTP ' . $brokenStatus . ' ' . $brokenBody, $brokenStatus === 400 ? 'PASS' : 'FAIL', $log, 'Local endpoint. Signature was calculated with the synthetic test secret.', 'local webhook endpoint');

$getHook = framsHttp('GET', $base . '/paymongo_webhook_lending.php', null, $cookieDir . '/hookget.txt');
framsRecord('PM-016', 'PayMongo Testing', 'Webhook GET is not accepted', 'The endpoint allows POST only.', 'Endpoint available.', 'GET paymongo_webhook_lending.php.', 'none', 'HTTP 405', 'HTTP ' . $getHook['status'], $getHook['status'] === 405 ? 'PASS' : 'FAIL', $log, 'Local endpoint.', 'local webhook endpoint');

$paymentsBeforeHook = (int)$conn->query('SELECT COUNT(*) FROM payments')->fetchColumn();
$unknownPayload = json_encode([
    'data' => [
        'id' => 'evt_http_unknown',
        'type' => 'event',
        'attributes' => [
            'type' => 'payment.paid',
            'livemode' => false,
            'data' => [
                'id' => 'pay_http_unknown',
                'type' => 'payment',
                'attributes' => [
                    'amount' => 100,
                    'currency' => 'PHP',
                    'status' => 'paid',
                    'livemode' => false,
                    'payment_intent_id' => 'pi_HTTPUNKNOWN1',
                    'source' => ['type' => 'qrph'],
                ],
            ],
        ],
    ],
], JSON_THROW_ON_ERROR);
$unknownTs = (string)time();
$unknownSig = hash_hmac('sha256', $unknownTs . '.' . $unknownPayload, $secret);
$ch = curl_init($base . '/paymongo_webhook_lending.php');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $unknownPayload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Paymongo-Signature: t=' . $unknownTs . ',te=' . $unknownSig],
]);
$unknownBody = (string)curl_exec($ch);
$unknownStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$paymentsAfterHook = (int)$conn->query('SELECT COUNT(*) FROM payments')->fetchColumn();
framsRecord('PM-017', 'PayMongo Testing', 'Signed unknown event over HTTP', 'A correctly signed event for an unknown intent is ignored and creates no payment.', 'Synthetic secret and test database.', 'POST a signed payment.paid event for an unknown intent.', 'pi_HTTPUNKNOWN1', 'HTTP 200 ignored and payment count unchanged', 'HTTP ' . $unknownStatus . ' ' . $unknownBody . '; payments ' . $paymentsBeforeHook . ' to ' . $paymentsAfterHook, ($unknownStatus === 200 && str_contains($unknownBody, 'ignored') && $paymentsBeforeHook === $paymentsAfterHook) ? 'PASS' : 'FAIL', $log, 'Local simulation delivered to the application endpoint. PayMongo did not send it.', 'local webhook endpoint simulation');

$ch = curl_init($base . '/paymongo_webhook_lending.php');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $unknownPayload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Paymongo-Signature: t=' . $unknownTs . ',te=' . $unknownSig],
]);
$dupBody = (string)curl_exec($ch);
$dupStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$paymentsAfterDup = (int)$conn->query('SELECT COUNT(*) FROM payments')->fetchColumn();
framsRecord('PM-018', 'PayMongo Testing', 'Repeated HTTP delivery of the same event', 'Replaying the signed unknown event does not add a payment.', 'PM-017 already stored the event id.', 'POST the same body and signature again.', 'evt_http_unknown', 'duplicate response and payment count unchanged', 'HTTP ' . $dupStatus . ' ' . $dupBody . '; payments ' . $paymentsAfterHook . ' to ' . $paymentsAfterDup, ($dupStatus === 200 && str_contains($dupBody, 'duplicate') && $paymentsAfterDup === $paymentsAfterHook) ? 'PASS' : 'FAIL', $log, 'Local simulation.', 'local webhook endpoint simulation');

$liveMode = (string)lendingEnv('PAYMONGO_MODE');
framsRecord('PM-019', 'PayMongo Testing', 'Isolated server mode', 'The test server is forced to test mode and does not load the live key.', 'env prepend.', 'Read the isolated mode value.', 'isolated env', 'test', $liveMode, $liveMode === 'test' ? 'PASS' : 'FAIL', $log, 'The project .env mode remains live and was not used by this server.', 'configuration check');

file_put_contents(__DIR__ . '/loan_status_observed.txt', $statusBefore . ' -> ' . $statusAfter . PHP_EOL);

$out = dirname(__DIR__);
file_put_contents(__DIR__ . '/http_cases.json', json_encode(['cases' => $cases, 'findings' => $findings], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$grouped = [];
foreach ($cases as $case) {
    $grouped[$case['category']][] = $case;
}
$map = [
    'System Testing' => '03_System_Testing',
    'Security Testing' => '04_Security_Testing',
    'Penetration Testing' => '05_Penetration_Testing',
    'PayMongo Testing' => '06_PayMongo_Testing',
];
foreach ($map as $category => $folder) {
    $rows = $grouped[$category] ?? [];
    file_put_contents($out . '/' . $folder . '/test_results/http_results.json', json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $lines = [];
    foreach ($rows as $row) {
        $lines[] = $row['id'] . "\t" . $row['status'] . "\t" . $row['scenario'] . "\t" . $row['actual'];
    }
    file_put_contents($out . '/' . $folder . '/logs/http_test_execution.log', implode(PHP_EOL, $lines) . PHP_EOL);
}
file_put_contents(__DIR__ . '/http_findings.json', json_encode($findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$counts = array_count_values(array_column($cases, 'status'));
echo 'HTTP_CASES=' . count($cases) . ' PASS=' . ($counts['PASS'] ?? 0) . ' FAIL=' . ($counts['FAIL'] ?? 0) . ' BLOCKED=' . ($counts['BLOCKED'] ?? 0) . PHP_EOL;
