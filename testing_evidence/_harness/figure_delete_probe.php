<?php
$base = 'http://127.0.0.1:8099';
$cookie = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'frams_admin_delete.txt';
@unlink($cookie);
function call_raw(string $url, string $cookie, string $method = 'GET', ?string $body = null): array
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
    return [$status, is_string($raw) ? $raw : ''];
}
call_raw($base . '/login_lending.php', $cookie, 'POST', http_build_query([
    'username' => 'frams_test_admin',
    'password' => 'Test@Frams1',
]));
[$status, $raw] = call_raw($base . '/payments_lending.php?add=true&loan=31', $cookie);
preg_match('/name="_csrf_token" value="([a-f0-9]+)"/', $raw, $token);
[$deleteStatus, $deleteRaw] = call_raw($base . '/payments_lending.php', $cookie, 'POST', http_build_query([
    '_csrf_token' => $token[1] ?? '',
    'delete_payment' => '1',
    'payment_id' => '46',
    'loan_id' => '31',
]));
$refused = str_contains($deleteRaw, 'was not deleted');
$deleted = str_contains($deleteRaw, 'deleted successfully');
echo 'TOKEN=' . (($token[1] ?? '') !== '' ? 'yes' : 'no') . ' DELETE_HTTP=' . $deleteStatus . ' REFUSED=' . ($refused ? 'yes' : 'no') . ' DELETED=' . ($deleted ? 'yes' : 'no') . PHP_EOL;
require __DIR__ . '/env_prepend.php';
$pdo = new PDO('mysql:host=localhost;dbname=frams_capstone_test;charset=utf8mb4', 'root', '');
echo 'PAYMENTS=' . $pdo->query('SELECT COUNT(*) FROM payments WHERE payment_id = 46')->fetchColumn() . PHP_EOL;
echo 'TX=' . $pdo->query("SELECT status FROM paymongo_qrph_transactions WHERE internal_reference = 'QRPH-PM09-SIM'")->fetchColumn() . PHP_EOL;
echo 'SOURCE_PAYMENTS=' . $pdo->query('SELECT COUNT(*) FROM lending_management.payments')->fetchColumn() . PHP_EOL;
