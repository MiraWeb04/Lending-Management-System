<?php
/**
 * Records HTTP outcomes for PT-01 through PT-12 against the isolated server.
 * Does not change the lending_management database.
 */
$base = 'http://127.0.0.1:8099';
$pdo = new PDO('mysql:host=localhost;dbname=frams_capstone_test;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$out = [];

function call_http(string $url, string $method = 'GET', ?string $body = null, ?string $cookie = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
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
    $header = substr($raw, 0, $headerSize);
    $respBody = substr($raw, $headerSize);
    $location = '';
    if (preg_match('/^Location:\s*(\S+)/mi', $header, $m)) {
        $location = $m[1];
    }
    return ['status' => $status, 'location' => $location, 'body' => $respBody];
}

function login_as(string $base, string $page, string $username, string $password, string $cookie): array
{
    @unlink($cookie);
    return call_http($base . '/' . $page, 'POST', http_build_query([
        'username' => $username,
        'password' => $password,
    ]), $cookie, ['Content-Type: application/x-www-form-urlencoded']);
}

$anon = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'frams_pt_anon.txt';
@unlink($anon);
$staffPages = [
    'dashboard_lending.php',
    'collector_dashboard_lending.php',
    'payments_lending.php',
    'reports_lending.php',
];
$borrowerPages = [
    'borrower_dashboard_lending.php',
    'borrower_my_loan_lending.php',
    'borrower_payment_history_lending.php',
];
$out['pt01'] = [];
foreach (array_merge($staffPages, $borrowerPages) as $page) {
    $res = call_http($base . '/' . $page, 'GET', null, $anon);
    $out['pt01'][$page] = [
        'status' => $res['status'],
        'location' => $res['location'],
        'shows_loan_number' => str_contains($res['body'], 'FRAMS-TEST-'),
        'shows_username' => str_contains($res['body'], 'frams_test_'),
    ];
}

$badCookie = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'frams_pt_bad.txt';
$bad = login_as($base, 'login_lending.php', 'frams_test_admin', 'Wrong@Password1', $badCookie);
$inactive = login_as($base, 'login_lending.php', 'frams_test_inactive', 'Test@Frams1', $badCookie);
$out['pt02'] = [
    'wrong' => ['status' => $bad['status'], 'location' => $bad['location'], 'message' => (preg_match('/alert[^>]*>(.*?)<\/div>/s', $bad['body'], $m) ? trim(strip_tags($m[1])) : '')],
    'inactive' => ['status' => $inactive['status'], 'location' => $inactive['location'], 'message' => (preg_match('/alert[^>]*>(.*?)<\/div>/s', $inactive['body'], $m) ? trim(strip_tags($m[1])) : '')],
];

$borrowerCookie = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'frams_pt_borrower.txt';
login_as($base, 'borrower_login_lending.php', 'frams_test_borrower', 'Test@Frams1', $borrowerCookie);
$out['pt03'] = [];
foreach (['users_lending.php', 'reports_lending.php', 'loan_release_lending.php', 'expenses_lending.php', 'payments_lending.php'] as $page) {
    $res = call_http($base . '/' . $page, 'GET', null, $borrowerCookie);
    $out['pt03'][$page] = [
        'status' => $res['status'],
        'location' => $res['location'],
        'shows_admin_user' => str_contains($res['body'], 'frams_test_admin'),
        'shows_add_user' => str_contains($res['body'], 'Add New User'),
    ];
}

$collectorCookie = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'frams_pt_collector.txt';
login_as($base, 'login_lending.php', 'frams_test_collector', 'Test@Frams1', $collectorCookie);
$out['pt04'] = [];
foreach (['users_lending.php', 'reports_lending.php', 'borrower_approval_lending.php', 'loan_release_lending.php'] as $page) {
    $res = call_http($base . '/' . $page, 'GET', null, $collectorCookie);
    $out['pt04'][$page] = [
        'status' => $res['status'],
        'location' => $res['location'],
        'shows_add_user' => str_contains($res['body'], 'Add New User'),
    ];
}

$before = [
    'users' => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'payments' => (int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn(),
    'expenses' => (int)$pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn(),
    'releases' => (int)$pdo->query('SELECT COUNT(*) FROM loan_releases')->fetchColumn(),
    'applications' => (int)$pdo->query('SELECT COUNT(*) FROM borrower_applications')->fetchColumn(),
];
$adminCookie = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'frams_pt_admin.txt';
login_as($base, 'login_lending.php', 'frams_test_admin', 'Test@Frams1', $adminCookie);
$posts = [
    'registration' => call_http($base . '/registration_lending.php', 'POST', http_build_query([
        'first_name' => 'Probe', 'last_name' => 'Token', 'username' => 'pt05_should_not_save', 'email' => 'pt05@example.invalid', 'password' => 'Test@Frams1', 'confirm_password' => 'Test@Frams1',
    ]), $anon, ['Content-Type: application/x-www-form-urlencoded']),
    'payment' => call_http($base . '/payments_lending.php', 'POST', http_build_query([
        'add_payment' => '1', 'loan_id' => '29', 'amount_paid' => '1', 'payment_method' => 'Cash',
    ]), $adminCookie, ['Content-Type: application/x-www-form-urlencoded']),
    'expense' => call_http($base . '/expenses_lending.php', 'POST', http_build_query([
        'add_expense' => '1', 'description' => 'PT05 should not save', 'amount' => '1',
    ]), $adminCookie, ['Content-Type: application/x-www-form-urlencoded']),
    'user' => call_http($base . '/users_lending.php', 'POST', http_build_query([
        'add_user' => '1', 'username' => 'pt05_should_not_save', 'full_name' => 'PT05', 'password' => 'Test@Frams1', 'role' => 'Collector',
    ]), $adminCookie, ['Content-Type: application/x-www-form-urlencoded']),
    'release' => call_http($base . '/loan_release_lending.php', 'POST', http_build_query([
        'release_loan' => '1', 'application_id' => '1',
    ]), $adminCookie, ['Content-Type: application/x-www-form-urlencoded']),
];
$after = [
    'users' => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'payments' => (int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn(),
    'expenses' => (int)$pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn(),
    'releases' => (int)$pdo->query('SELECT COUNT(*) FROM loan_releases')->fetchColumn(),
    'applications' => (int)$pdo->query('SELECT COUNT(*) FROM borrower_applications')->fetchColumn(),
    'probe_user' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE username = 'pt05_should_not_save'")->fetchColumn(),
];
$out['pt05'] = [
    'before' => $before,
    'after' => $after,
    'responses' => [],
];
foreach ($posts as $name => $res) {
    $out['pt05']['responses'][$name] = [
        'status' => $res['status'],
        'location' => $res['location'],
        'title' => (preg_match('/<title>(.*?)<\/title>/s', $res['body'], $m) ? trim($m[1]) : ''),
        'heading' => (preg_match('/<h4>(.*?)<\/h4>/s', $res['body'], $m) ? trim(strip_tags($m[1])) : ''),
    ];
}

$quoteLogin = login_as($base, 'login_lending.php', "frams_test_admin' OR '1'='1", 'Test@Frams1', $badCookie);
$quoteSearch = call_http($base . '/users_lending.php?search=' . rawurlencode("' OR '1'='1"), 'GET', null, $adminCookie);
preg_match_all('/frams_test_[a-z0-9_]+/', $quoteSearch['body'], $foundUsers);
$out['pt06'] = [
    'login_status' => $quoteLogin['status'],
    'login_location' => $quoteLogin['location'],
    'login_message' => (preg_match('/Invalid username or password/', $quoteLogin['body']) ? 'Invalid username or password' : 'other'),
    'search_status' => $quoteSearch['status'],
    'search_users' => array_values(array_unique($foundUsers[0] ?? [])),
];

$marker = '<b>pt07marker</b>';
$markerSearch = call_http($base . '/users_lending.php?search=' . rawurlencode($marker), 'GET', null, $adminCookie);
$out['pt07'] = [
    'status' => $markerSearch['status'],
    'raw_marker_in_html' => str_contains($markerSearch['body'], $marker),
    'encoded_marker_in_html' => str_contains($markerSearch['body'], htmlspecialchars($marker, ENT_QUOTES, 'UTF-8')),
    'users_listed' => (bool)preg_match_all('/frams_test_[a-z0-9_]+/', $markerSearch['body'], $markerUsers) ? array_values(array_unique($markerUsers[0])) : [],
];

$receipt = call_http($base . '/payments_lending.php?view=44', 'GET', null, $borrowerCookie);
$doc = call_http($base . '/download_loan_document_lending.php?path=' . rawurlencode('uploads/loan_documents/other-borrower-proof.pdf'), 'GET', null, $borrowerCookie);
$out['pt08'] = [
    'receipt_status' => $receipt['status'],
    'receipt_location' => $receipt['location'],
    'receipt_body_has_borrower_b' => str_contains($receipt['body'], 'BorrowerB') || str_contains($receipt['body'], 'RJRR-20261018-000002'),
    'document_status' => $doc['status'],
    'document_body' => trim(strip_tags($doc['body'])),
];

$out['pt09'] = [];
foreach ([
    'client_details_lending.php?id=21',
    'loan_details_lending.php?id=30',
    'payments_lending.php?view=44',
] as $page) {
    $res = call_http($base . '/' . $page, 'GET', null, $collectorCookie);
    $out['pt09'][$page] = [
        'status' => $res['status'],
        'location' => $res['location'],
        'snippet' => trim(preg_replace('/\s+/', ' ', strip_tags($res['body']))),
    ];
    $out['pt09'][$page]['snippet'] = substr($out['pt09'][$page]['snippet'], 0, 240);
}

file_put_contents(__DIR__ . '/pt_http_evidence.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
