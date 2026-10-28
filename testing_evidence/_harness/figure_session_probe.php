<?php
$base = 'http://127.0.0.1:8099';
$cookie = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'frams_admin_probe.txt';
@unlink($cookie);

function call_once(string $url, string $cookie, string $method = 'GET', ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    }
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $raw = is_string($raw) ? $raw : '';
    $location = '';
    if (preg_match('/^Location:\s*(\S+)/mi', $raw, $match)) {
        $location = $match[1];
    }
    $hasPayments = str_contains($raw, 'Payments Management');
    $hasToken = preg_match('/name="_csrf_token" value="([a-f0-9]+)"/', $raw, $token) === 1;
    return [$status, $location, $hasPayments, $hasToken, $token[1] ?? ''];
}

[$loginStatus, $loginLocation] = array_slice(call_once($base . '/login_lending.php', $cookie, 'POST', http_build_query([
    'username' => 'frams_test_admin',
    'password' => 'Test@Frams1',
])), 0, 2);
[$pageStatus, $pageLocation, $hasPayments, $hasToken, $token] = call_once($base . '/payments_lending.php', $cookie);
[$deleteStatus, $deleteLocation, $deleteHasPayments, $deleteHasToken] = call_once($base . '/payments_lending.php', $cookie, 'POST', http_build_query([
    '_csrf_token' => $token,
    'delete_payment' => '1',
    'payment_id' => '46',
    'loan_id' => '31',
]));
$borrowerCookie = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'frams_borrower_probe.txt';
@unlink($borrowerCookie);
$bch = curl_init($base . '/borrower_login_lending.php');
curl_setopt_array($bch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['username' => 'frams_test_borrower', 'password' => 'Test@Frams1']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_COOKIEJAR => $borrowerCookie,
    CURLOPT_COOKIEFILE => $borrowerCookie,
]);
curl_exec($bch);
curl_close($bch);
$pch = curl_init($base . '/payments_lending.php');
curl_setopt_array($pch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'start_qrph' => '1',
        'loan_id' => '31',
        'payment_method' => 'E-Wallet',
        'amount_paid' => '50',
        'penalty_paid' => '0',
    ]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_COOKIEJAR => $borrowerCookie,
    CURLOPT_COOKIEFILE => $borrowerCookie,
]);
$praw = curl_exec($pch);
$pstatus = (int)curl_getinfo($pch, CURLINFO_RESPONSE_CODE);
curl_close($pch);
$plocation = '';
if (is_string($praw) && preg_match('/^Location:\s*(\S+)/mi', $praw, $pmatch)) {
    $plocation = $pmatch[1];
}
echo 'ADMIN_LOGIN=' . $loginStatus . ' LOC=' . $loginLocation . PHP_EOL;
echo 'ADMIN_PAGE=' . $pageStatus . ' PAYMENTS=' . ($hasPayments ? 'yes' : 'no') . ' TOKEN=' . ($hasToken ? 'yes' : 'no') . PHP_EOL;
echo 'DELETE=' . $deleteStatus . ' LOC=' . $deleteLocation . ' PAGE=' . ($deleteHasPayments ? 'yes' : 'no') . PHP_EOL;
echo 'BORROWER_POST=' . $pstatus . ' LOC=' . $plocation . PHP_EOL;
