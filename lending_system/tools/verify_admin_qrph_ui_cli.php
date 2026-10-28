<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$base = $argv[1] ?? 'http://localhost/lending_system/lending_system';
$base = rtrim($base, '/');

function httpSession(string $base, string $username, string $password, string $cookieFile): array
{
    @unlink($cookieFile);
    $loginPage = curl_init($base . '/login_lending.php');
    curl_setopt_array($loginPage, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
    ]);
    $loginHtml = (string)curl_exec($loginPage);
    curl_close($loginPage);

    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $loginHtml, $csrfMatch)) {
        return ['ok' => false, 'error' => 'CSRF token not found on login page'];
    }

    $loginPost = curl_init($base . '/login_lending.php');
    curl_setopt_array($loginPost, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'username' => $username,
            'password' => $password,
            'csrf_token' => $csrfMatch[1],
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $loginResponse = (string)curl_exec($loginPost);
    $loginCode = (int)curl_getinfo($loginPost, CURLINFO_HTTP_CODE);
    curl_close($loginPost);

    if ($loginCode !== 302) {
        return ['ok' => false, 'error' => 'Login failed HTTP ' . $loginCode];
    }

    $paymentsGet = curl_init($base . '/payments_lending.php?add=true');
    curl_setopt_array($paymentsGet, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
    ]);
    $body = (string)curl_exec($paymentsGet);
    $code = (int)curl_getinfo($paymentsGet, CURLINFO_HTTP_CODE);
    curl_close($paymentsGet);

    return ['ok' => true, 'status' => $code, 'body' => $body];
}

require_once __DIR__ . '/../includes/db_lending.php';
/** @var PDO $conn */
global $conn;

$admin = $conn->query("SELECT username FROM users WHERE role = 'Admin' AND status = 'Active' ORDER BY user_id ASC LIMIT 1")->fetchColumn();
$collector = $conn->query("SELECT username FROM users WHERE role = 'Collector' AND status = 'Active' ORDER BY user_id ASC LIMIT 1")->fetchColumn();
if (!$admin || !$collector) {
    fwrite(STDERR, "Need active Admin and Collector users.\n");
    exit(1);
}

$cookieDir = sys_get_temp_dir();
$results = [];

foreach (['Admin' => (string)$admin, 'Collector' => (string)$collector] as $role => $username) {
    $cookie = $cookieDir . '/frams_qrph_verify_' . strtolower($role) . '.txt';
    $session = httpSession($base, $username, 'Test123!', $cookie);
    if (!$session['ok']) {
        $results[$role] = ['pass' => false, 'detail' => $session['error']];
        continue;
    }
    $body = $session['body'];
    $hasButton = str_contains($body, 'id="start-qrph-payment"')
        && str_contains($body, 'Create Dynamic QR Ph')
        && str_contains($body, 'name="start_qrph"');
    $hasRecord = str_contains($body, 'Record Payment');
    $results[$role] = [
        'pass' => $session['status'] === 200 && $hasButton && $hasRecord,
        'http' => $session['status'],
        'qr_button' => $hasButton,
        'record_payment' => $hasRecord,
        'username' => $username,
    ];
}

echo json_encode(['base' => $base, 'results' => $results], JSON_PRETTY_PRINT) . PHP_EOL;
exit(array_reduce($results, static fn($c, $r) => $c && !empty($r['pass']), true) ? 0 : 1);
