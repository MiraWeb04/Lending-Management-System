<?php
$base = 'http://127.0.0.1:8099';
$cookie = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'frams_pt10_cookie.txt';
@unlink($cookie);

function req(string $url, ?array $post = null, ?string $cookie = null, bool $multipart = false): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $post : http_build_query($post));
    }
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $raw = is_string($raw) ? $raw : '';
    return [$status, substr($raw, $headerSize)];
}

req($base . '/borrower_login_lending.php', [
    'username' => 'frams_pt10_upload',
    'password' => 'Test@Frams1',
], $cookie);

$txt = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pt10-probe.txt';
file_put_contents($txt, 'pt10 text probe');
$big = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pt10-big.bin';
$fh = fopen($big, 'wb');
fseek($fh, (8 * 1024 * 1024) + 1024);
fwrite($fh, 'x');
fclose($fh);

$dir = 'C:/xampp/htdocs/lending_system/lending_system/uploads/loan_documents';
$before = array_flip(array_diff(scandir($dir), ['.', '..']));

$postTxt = [
    'step' => '4',
    'documents[0]' => new CURLFile($txt, 'text/plain', 'pt10-probe.txt'),
];
[$statusTxt, $bodyTxt] = req($base . '/borrower_loan_application_lending.php?step=4', $postTxt, $cookie, true);
echo "TRACE\n";
echo @file_get_contents(sys_get_temp_dir() . '/pt10_trace.txt');
echo "\n";
file_put_contents(__DIR__ . '/../05_Penetration_Testing/logs/pt10_txt_response.html', $bodyTxt);

$postBig = [
    'step' => '4',
    'documents[0]' => new CURLFile($big, 'application/octet-stream', 'pt10-big.bin'),
];
[$statusBig, $bodyBig] = req($base . '/borrower_loan_application_lending.php?step=4', $postBig, $cookie, true);
file_put_contents(__DIR__ . '/../05_Penetration_Testing/logs/pt10_big_response.html', $bodyBig);

$after = array_diff(scandir($dir), ['.', '..']);
$new = [];
foreach ($after as $name) {
    if (!isset($before[$name])) {
        $new[] = $name;
    }
}

function snippet(string $html): string
{
    $text = preg_replace('/\s+/', ' ', strip_tags($html));
    foreach (['Allowed file types', '8 MB', 'Invalid file', 'Upload failed', 'Please upload all required', 'pt10-probe', 'pt10-big'] as $needle) {
        $pos = stripos($text, $needle);
        if ($pos !== false) {
            return trim(substr($text, max(0, $pos - 40), 180));
        }
    }
    return 'no marker';
}

echo json_encode([
    'txt_status' => $statusTxt,
    'txt_len' => strlen($bodyTxt),
    'txt_snippet' => snippet($bodyTxt),
    'txt_has_allowed' => str_contains($bodyTxt, 'Allowed file types'),
    'txt_has_probe_name' => str_contains($bodyTxt, 'pt10-probe.txt'),
    'big_status' => $statusBig,
    'big_len' => strlen($bodyBig),
    'big_snippet' => snippet($bodyBig),
    'big_has_size' => str_contains($bodyBig, '8 MB'),
    'big_has_name' => str_contains($bodyBig, 'pt10-big.bin'),
    'new_files' => $new,
    'big_bytes' => filesize($big),
], JSON_PRETTY_PRINT), "\n";
