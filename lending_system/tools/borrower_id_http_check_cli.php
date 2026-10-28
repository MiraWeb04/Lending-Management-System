<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require_once dirname(__DIR__) . '/includes/borrower_registration_helpers.php';

$base = 'http://127.0.0.1/lending_system/lending_system';
$token = substr(bin2hex(random_bytes(4)), 0, 8);
$failures = [];

function httpCheck(bool $ok, string $label, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    if (!$ok) {
        $failures[] = $label;
    }
}

function jpegBytes(): string
{
    $image = imagecreatetruecolor(120, 80);
    $color = imagecolorallocate($image, 40, 90, 140);
    imagefilledrectangle($image, 0, 0, 119, 79, $color);
    ob_start();
    imagejpeg($image, null, 90);
    imagedestroy($image);
    return (string)ob_get_clean();
}

function pngBytes(): string
{
    $image = imagecreatetruecolor(120, 80);
    $color = imagecolorallocate($image, 40, 140, 90);
    imagefilledrectangle($image, 0, 0, 119, 79, $color);
    ob_start();
    imagepng($image);
    imagedestroy($image);
    return (string)ob_get_clean();
}

$cookie = tempnam(sys_get_temp_dir(), 'frams');
$jpg = tempnam(sys_get_temp_dir(), 'idjpg');
$png = tempnam(sys_get_temp_dir(), 'idpng');
$bad = tempnam(sys_get_temp_dir(), 'idbad');
file_put_contents($jpg, jpegBytes());
file_put_contents($png, pngBytes());
file_put_contents($bad, "<?php echo 'no';");

function request(string $url, string $cookie, array $post = null, array $files = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($post !== null) {
        $payload = $post;
        foreach ($files as $field => $file) {
            $payload[$field] = new CURLFile($file['path'], $file['type'], $file['name']);
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return ['status' => 0, 'headers' => '', 'body' => $error];
    }
    $split = strpos($raw, "\r\n\r\n");
    return [
        'status' => $status,
        'headers' => substr($raw, 0, $split === false ? 0 : $split),
        'body' => substr($raw, $split === false ? 0 : $split + 4),
    ];
}

function fields(string $token, string $suffix): array
{
    return [
        'first_name' => 'Idhttp',
        'middle_name' => '',
        'last_name' => 'Case' . $suffix . $token,
        'dob' => '1992-08-20',
        'gender' => 'Male',
        'civil_status' => 'Single',
        'nationality' => 'Filipino',
        'mobile_number' => '0918123' . substr($token, 0, 4),
        'email' => 'idhttp' . strtolower($suffix) . $token . '@example.com',
        'address' => '88 Sample Road, Manila',
        'username' => 'idhttp' . strtolower($suffix) . $token,
        'password' => 'TestPass1!',
        'confirm_password' => 'TestPass1!',
        'government_id_type' => 'UMID',
        'government_id_number' => 'U' . $suffix . $token,
        'consent' => '1',
    ];
}

try {
    $page = request($base . '/registration_lending.php', $cookie);
    httpCheck($page['status'] === 200 && str_contains($page['body'], 'Upload a clear image of your valid ID. Make sure your name, photo, and identification details are readable.'), 'Registration page shows the ID upload instruction', 'HTTP ' . $page['status']);
    httpCheck(str_contains($page['body'], 'name="government_id_image"') && str_contains($page['body'], '>ID Type<') && str_contains($page['body'], '>ID Number<'), 'Registration page contains ID type, number, and file fields');
    preg_match('/name="_csrf_token" value="([^"]+)"/', $page['body'], $csrfMatch);
    $csrf = $csrfMatch[1] ?? '';
    httpCheck($csrf !== '', 'Registration page issued a CSRF token');

    $missing = request($base . '/registration_lending.php', $cookie, fields($token, 'M') + ['_csrf_token' => $csrf]);
    httpCheck($missing['status'] === 200 && str_contains($missing['body'], 'Please upload a clear image of your valid ID.'), 'HTTP registration without an ID image is rejected', 'HTTP ' . $missing['status']);

    preg_match('/name="_csrf_token" value="([^"]+)"/', $missing['body'], $csrfMatch);
    $csrf = $csrfMatch[1] ?? $csrf;
    $disguised = request($base . '/registration_lending.php', $cookie, fields($token, 'D') + ['_csrf_token' => $csrf], [
        'government_id_image' => ['path' => $bad, 'type' => 'image/jpeg', 'name' => 'id.jpg'],
    ]);
    httpCheck($disguised['status'] === 200 && !str_contains($disguised['headers'], 'borrower_registration_success'), 'HTTP disguised file is rejected', 'HTTP ' . $disguised['status']);

    preg_match('/name="_csrf_token" value="([^"]+)"/', $disguised['body'], $csrfMatch);
    $csrf = $csrfMatch[1] ?? $csrf;
    $accepted = request($base . '/registration_lending.php', $cookie, fields($token, 'J') + ['_csrf_token' => $csrf], [
        'government_id_image' => ['path' => $jpg, 'type' => 'image/jpeg', 'name' => 'valid-id.jpg'],
    ]);
    httpCheck($accepted['status'] === 302 && str_contains($accepted['headers'], 'borrower_registration_success_lending.php'), 'HTTP valid JPG registration is accepted', 'HTTP ' . $accepted['status']);

    global $conn;
    $lookup = $conn->prepare('SELECT id, user_id, government_id_type, government_id_number, government_id_image_path, status FROM borrower_applications WHERE username = ?');
    $lookup->execute(['idhttpj' . $token]);
    $row = $lookup->fetch(PDO::FETCH_ASSOC);
    httpCheck((bool)$row && $row['government_id_type'] === 'UMID' && $row['status'] === 'Pending Approval', 'HTTP registration saved the ID number on that borrower');
    $imagePath = $row ? borrowerIdAbsolutePath((string)$row['government_id_image_path']) : null;
    httpCheck($imagePath !== null && (new finfo(FILEINFO_MIME_TYPE))->file($imagePath) === 'image/jpeg', 'HTTP upload stored a real JPEG outside the site');

    if ($row) {
        $blocked = request($base . '/view_borrower_id_lending.php?id=' . (int)$row['id'], tempnam(sys_get_temp_dir(), 'nocookie'));
        httpCheck($blocked['status'] === 403, 'Fresh anonymous request cannot read the new ID image', 'HTTP ' . $blocked['status']);
    }

    $page2 = request($base . '/registration_lending.php', $cookie);
    preg_match('/name="_csrf_token" value="([^"]+)"/', $page2['body'], $csrfMatch);
    $pngPost = request($base . '/registration_lending.php', $cookie, fields($token, 'P') + ['_csrf_token' => ($csrfMatch[1] ?? '')], [
        'government_id_image' => ['path' => $png, 'type' => 'image/png', 'name' => 'valid-id.png'],
    ]);
    httpCheck($pngPost['status'] === 302 && str_contains($pngPost['headers'], 'borrower_registration_success_lending.php'), 'HTTP valid PNG registration is accepted', 'HTTP ' . $pngPost['status']);

    $webpProbe = request($base . '/registration_lending.php', $cookie);
    $offersWebp = str_contains($webpProbe['body'], 'const allowWebp = true');
    httpCheck(true, 'Running web server image formats', $offersWebp ? 'JPG, PNG, and WEBP' : 'JPG and PNG until Apache reloads the GD extension');
} finally {
    $cleanup = $conn->prepare('SELECT id, user_id, government_id_image_path FROM borrower_applications WHERE username LIKE ?');
    $cleanup->execute(['idhttp%' . $token]);
    foreach ($cleanup->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!empty($row['government_id_image_path'])) {
            $path = borrowerIdAbsolutePath((string)$row['government_id_image_path']);
            if ($path) {
                borrowerIdDeleteOwnedFile($path);
            }
        }
        $conn->prepare('DELETE FROM notifications WHERE user_id = ? OR message LIKE ?')->execute([(int)$row['user_id'], '%' . $token . '%']);
        $conn->prepare('DELETE FROM borrower_applications WHERE id = ?')->execute([(int)$row['id']]);
        $conn->prepare('DELETE FROM users WHERE user_id = ?')->execute([(int)$row['user_id']]);
    }
    $conn->prepare('DELETE FROM notifications WHERE message LIKE ?')->execute(['%' . $token . '%']);
    foreach ([$cookie, $jpg, $png, $bad] as $temp) {
        if (is_string($temp) && is_file($temp)) {
            @unlink($temp);
        }
    }
}

echo count($failures) . ' failed.' . PHP_EOL;
exit($failures === [] ? 0 : 1);
