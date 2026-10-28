<?php
$base = 'http://127.0.0.1:8099';
$cookie = dirname(__DIR__) . '/evidence/http_cookies/debug.txt';
@unlink($cookie);
$ch = curl_init($base . '/login_lending.php');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['username' => 'frams_test_admin', 'password' => 'Test@Frams1']),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_COOKIEJAR => $cookie,
    CURLOPT_COOKIEFILE => $cookie,
    CURLOPT_FOLLOWLOCATION => false,
]);
$login = curl_exec($ch);
$loginCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$ch = curl_init($base . '/clients_lending.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_COOKIEJAR => $cookie,
    CURLOPT_COOKIEFILE => $cookie,
    CURLOPT_FOLLOWLOCATION => false,
]);
$clients = curl_exec($ch);
$clientsCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$loginHead = strtok((string)$login, "\r\n\r\n");
$clientHead = strtok((string)$clients, "\r\n\r\n");
$loginHead = preg_replace('/PHPSESSID=[^;\\s]+/', 'PHPSESSID=[redacted]', $loginHead);
$clientHead = preg_replace('/PHPSESSID=[^;\\s]+/', 'PHPSESSID=[redacted]', $clientHead);
echo "login=$loginCode\n$loginHead\n---\nclients=$clientsCode\n$clientHead\n";
