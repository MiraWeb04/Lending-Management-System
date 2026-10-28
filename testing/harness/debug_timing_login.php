<?php
$base = 'http://127.0.0.1:8099';
$cookie = dirname(__DIR__) . '/evidence/http_cookies/timing_probe.txt';
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
    CURLOPT_TIMEOUT => 30,
]);
$body = (string)curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo 'login_status=' . $code . PHP_EOL;
echo 'invalid=' . (str_contains($body, 'Invalid username or password') ? 'yes' : 'no') . PHP_EOL;
echo 'both=' . (str_contains($body, 'Please enter both username and password') ? 'yes' : 'no') . PHP_EOL;
echo 'location=' . (preg_match('/Location: (\\S+)/', $body, $m) ? $m[1] : 'none') . PHP_EOL;
$measured = [];
for ($i = 0; $i < 5; $i++) {
    foreach (['clients_lending.php', 'reports_lending.php', 'dashboard_lending.php'] as $page) {
        $start = hrtime(true);
        $ch = curl_init($base . '/' . $page);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $cookie,
            CURLOPT_COOKIEFILE => $cookie,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 60,
        ]);
        curl_exec($ch);
        $pageCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ms = round((hrtime(true) - $start) / 1e6, 2);
        $measured[$page][] = ['status' => $pageCode, 'ms' => $ms];
        echo $page . ' sample=' . ($i + 1) . ' status=' . $pageCode . ' ms=' . $ms . PHP_EOL;
    }
}
file_put_contents(dirname(__DIR__) . '/evidence/authenticated_timings.json', json_encode([
    'login_status' => $code,
    'samples' => $measured,
    'note' => 'Five sequential samples after one synthetic administrator login on the isolated server.',
], JSON_PRETTY_PRINT));
