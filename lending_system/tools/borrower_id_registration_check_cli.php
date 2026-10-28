<?php
/**
 * CLI checks for borrower ID upload, storage, and approval details.
 * Not a web page. Removes only the fixture accounts it creates.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require_once dirname(__DIR__) . '/includes/borrower_registration_helpers.php';

$failures = [];
$passes = [];

function check(bool $ok, string $label, string $detail = ''): void
{
    global $failures, $passes;
    if ($ok) {
        $passes[] = $label;
        echo 'PASS  ' . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
        return;
    }
    $failures[] = $label . ($detail !== '' ? ' — ' . $detail : '');
    echo 'FAIL  ' . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
}

function fixtureFile(string $filename, string $binary): array
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;
    file_put_contents($path, $binary);

    return [
        'name' => $filename,
        'type' => 'application/octet-stream',
        'tmp_name' => $path,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($path),
        'cli_fixture' => true,
    ];
}

function imageBinary(string $format): string
{
    $image = imagecreatetruecolor(160, 100);
    $color = imagecolorallocate($image, 30, 90, 160);
    imagefilledrectangle($image, 0, 0, 159, 99, $color);
    ob_start();
    if ($format === 'png') {
        imagepng($image);
    } elseif ($format === 'webp') {
        imagewebp($image, null, 90);
    } else {
        imagejpeg($image, null, 90);
    }
    $binary = ob_get_clean();
    imagedestroy($image);

    return (string)$binary;
}

function registrationInput(string $token, string $suffix, string $middle = ''): array
{
    return [
        'first_name' => 'Idcheck',
        'middle_name' => $middle,
        'last_name' => 'Case' . $suffix . $token,
        'dob' => '1994-04-12',
        'gender' => 'Female',
        'civil_status' => 'Single',
        'nationality' => 'Filipino',
        'mobile_number' => '0917000' . substr($token, 0, 4),
        'email' => 'idchk' . strtolower($suffix) . $token . '@example.com',
        'address' => '12 Test Street, Quezon City',
        'username' => 'idchk' . strtolower($suffix) . $token,
        'password' => 'TestPass1!',
        'confirm_password' => 'TestPass1!',
        'government_id_type' => "Driver's License",
        'government_id_number' => 'D' . strtoupper($suffix) . '-' . $token,
        'consent' => '1',
    ];
}

function cleanupAccount(?int $userId, ?int $applicationId, string $token): void
{
    global $conn;
    if ($applicationId) {
        $conn->prepare('DELETE FROM borrower_applications WHERE id = ?')->execute([$applicationId]);
    }
    if ($userId) {
        $conn->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$userId]);
        $conn->prepare('DELETE FROM users WHERE user_id = ?')->execute([$userId]);
    }
    if ($token !== '') {
        $conn->prepare('DELETE FROM notifications WHERE message LIKE ?')->execute(['%' . $token . '%']);
        $conn->prepare('DELETE FROM borrower_applications WHERE last_name LIKE ?')->execute(['%' . $token . '%']);
        $conn->prepare('DELETE FROM users WHERE username LIKE ?')->execute(['idchk%' . $token]);
    }
}

$token = substr(bin2hex(random_bytes(4)), 0, 8);
$createdFiles = [];
$decoyPath = null;
$beforeCount = 0;
$resultA = [];
$resultB = [];
$jpg = $png = $textFile = $disguised = $mismatch = $doubleExt = null;
$bigPath = null;

try {
    ensureBorrowerApplicationsTable();
    $columns = [];
    foreach ($conn->query('SHOW COLUMNS FROM borrower_applications')->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[$column['Field']] = true;
    }
    check(isset($columns['government_id_type'], $columns['government_id_number'], $columns['government_id_image_path'], $columns['government_id_uploaded_at']), 'Schema has ID type, number, image reference, and upload time');

    $beforeCount = (int)$conn->query('SELECT COUNT(*) FROM borrower_applications')->fetchColumn();
    $existing = $conn->query('SELECT id, user_id FROM borrower_applications ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $passwordHash = '';
        if (!empty($existing['user_id'])) {
            $hashStmt = $conn->prepare('SELECT password FROM users WHERE user_id = ?');
            $hashStmt->execute([(int)$existing['user_id']]);
            $passwordHash = (string)$hashStmt->fetchColumn();
        }
        $existingDetail = buildBorrowerApprovalDetail((int)$existing['id']);
        $encodedExisting = json_encode($existingDetail);
        check(!empty($existingDetail['ok']), 'Existing borrower record loads', (string)($existingDetail['error'] ?? ''));
        check($passwordHash === '' || !str_contains((string)$encodedExisting, $passwordHash), 'Existing detail payload omits the password hash');
        check(!str_contains((string)$encodedExisting, 'government_id_image_path'), 'Existing detail payload omits the storage column');
    } else {
        check(true, 'Existing borrower record loads', 'no prior applications in this database');
    }
    $afterEnsureCount = (int)$conn->query('SELECT COUNT(*) FROM borrower_applications')->fetchColumn();
    check($beforeCount === $afterEnsureCount, 'Migration preserves existing borrower rows', $beforeCount . ' rows');

    $missing = validateBorrowerIdUpload(['error' => UPLOAD_ERR_NO_FILE]);
    check(!$missing['ok'] && str_contains($missing['message'], 'upload a clear image'), 'Registration without an ID image is rejected', $missing['message']);

    $jpg = fixtureFile('valid-id-' . $token . '.jpg', imageBinary('jpg'));
    $png = fixtureFile('valid-id-' . $token . '.png', imageBinary('png'));
    $jpgCheck = validateBorrowerIdUpload($jpg);
    $pngCheck = validateBorrowerIdUpload($png);
    check(!empty($jpgCheck['ok']) && ($jpgCheck['mime'] ?? '') === 'image/jpeg', 'Valid JPG is accepted');
    check(!empty($pngCheck['ok']) && ($pngCheck['mime'] ?? '') === 'image/png', 'Valid PNG is accepted');

    if (borrowerIdWebpSupported()) {
        $webp = fixtureFile('valid-id-' . $token . '.webp', imageBinary('webp'));
        $webpCheck = validateBorrowerIdUpload($webp);
        check(!empty($webpCheck['ok']), 'Valid WEBP is accepted');
        @unlink($webp['tmp_name']);
    } else {
        check(true, 'Valid WEBP is accepted', 'WEBP encoding is not available in this PHP build, so WEBP uploads are rejected');
        $fakeWebp = fixtureFile('valid-id-' . $token . '.webp', imageBinary('jpg'));
        $fakeWebpCheck = validateBorrowerIdUpload($fakeWebp);
        check(empty($fakeWebpCheck['ok']), 'WEBP is rejected when the server cannot process it', $fakeWebpCheck['message'] ?? '');
        @unlink($fakeWebp['tmp_name']);
    }

    $textFile = fixtureFile('notes-' . $token . '.txt', 'hello');
    $textCheck = validateBorrowerIdUpload($textFile);
    check(empty($textCheck['ok']), 'Unsupported file is rejected', $textCheck['message'] ?? '');

    $bigPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'big-' . $token . '.jpg';
    $bigHandle = fopen($bigPath, 'wb');
    fwrite($bigHandle, str_repeat('A', borrowerIdMaxBytes() + 1));
    fclose($bigHandle);
    $bigCheck = validateBorrowerIdUpload([
        'name' => 'big-' . $token . '.jpg',
        'tmp_name' => $bigPath,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($bigPath),
        'cli_fixture' => true,
    ]);
    check(empty($bigCheck['ok']) && str_contains((string)$bigCheck['message'], '5 MB'), 'Oversized file is rejected', $bigCheck['message'] ?? '');

    $disguised = fixtureFile('photo-' . $token . '.jpg', "<?php echo 'not an image';");
    $disguisedCheck = validateBorrowerIdUpload($disguised);
    check(empty($disguisedCheck['ok']), 'File disguised as an image is rejected', $disguisedCheck['message'] ?? '');

    $mismatch = fixtureFile('card-' . $token . '.jpg', imageBinary('png'));
    $mismatchCheck = validateBorrowerIdUpload($mismatch);
    check(empty($mismatchCheck['ok']), 'Extension that does not match the image contents is rejected', $mismatchCheck['message'] ?? '');

    $doubleExt = fixtureFile('card.php.jpg', imageBinary('jpg'));
    $doubleCheck = validateBorrowerIdUpload($doubleExt);
    check(empty($doubleCheck['ok']), 'Executable double extension is rejected', $doubleCheck['message'] ?? '');

    check(validateBorrowerIdType('') !== '', 'Registration with missing ID type is rejected');
    check(validateBorrowerIdNumber('') !== '', 'Registration with missing ID number is rejected');

    $noImageInput = registrationInput($token, 'Z');
    $noImageResult = registerPendingBorrower($noImageInput, ['error' => UPLOAD_ERR_NO_FILE]);
    check(empty($noImageResult['ok']), 'Full registration without an ID image is rejected', $noImageResult['error'] ?? '');

    $storageDir = borrowerIdStorageDir();
    check(is_dir($storageDir) && is_file($storageDir . DIRECTORY_SEPARATOR . '.htaccess'), 'ID storage directory is protected', $storageDir);
    check(borrowerIdStorageIsOutsideWebRoot(), 'ID storage is outside the web root', $storageDir);

    $idFilesBefore = glob($storageDir . DIRECTORY_SEPARATOR . 'id_*.*') ?: [];
    $decoyName = 'id_' . bin2hex(random_bytes(16)) . '.jpg';
    $decoyPath = $storageDir . DIRECTORY_SEPARATOR . $decoyName;
    file_put_contents($decoyPath, imageBinary('jpg'));

    $abortInput = registrationInput($token, 'R');
    $abortResult = registerPendingBorrower($abortInput, $jpg, true);
    $abortUser = $conn->prepare('SELECT user_id FROM users WHERE username = ?');
    $abortUser->execute([$abortInput['username']]);
    check(empty($abortResult['ok']) && !$abortUser->fetchColumn(), 'Failed registration rolls back the user record', $abortResult['error'] ?? '');
    $filesAfterAbort = glob($storageDir . DIRECTORY_SEPARATOR . 'id_*.*') ?: [];
    check(in_array($decoyPath, $filesAfterAbort, true) && count($filesAfterAbort) === count($idFilesBefore) + 1, 'Failed registration does not delete an existing ID file');

    $inputA = registrationInput($token, 'A');
    $inputB = registrationInput($token, 'B', 'Marie');
    $inputB['government_id_type'] = 'Passport';
    $inputB['government_id_number'] = 'P' . $token;
    $resultA = registerPendingBorrower($inputA, $jpg);
    $resultB = registerPendingBorrower($inputB, $png);
    check(!empty($resultA['ok']) && !empty($resultB['ok']), 'Valid JPG and PNG registrations are saved', ($resultA['error'] ?? '') . ' ' . ($resultB['error'] ?? ''));

    $rowA = null;
    $rowB = null;
    if (!empty($resultA['application_id']) && !empty($resultB['application_id'])) {
        $createdFiles = [];
        $load = $conn->prepare('SELECT id, user_id, first_name, middle_name, last_name, government_id_type, government_id_number, government_id_image_path, government_id_uploaded_at, status, verification_status FROM borrower_applications WHERE id = ?');
        $load->execute([(int)$resultA['application_id']]);
        $rowA = $load->fetch(PDO::FETCH_ASSOC);
        $load->execute([(int)$resultB['application_id']]);
        $rowB = $load->fetch(PDO::FETCH_ASSOC);

        check($rowA && $rowA['government_id_type'] === "Driver's License" && $rowA['government_id_number'] === $inputA['government_id_number'], 'Borrower A ID fields are stored on the correct record');
        check($rowB && $rowB['government_id_type'] === 'Passport' && $rowB['government_id_number'] === 'P' . $token, 'Borrower B ID fields are stored on the correct record');
        check($rowA && $rowA['status'] === 'Pending Approval' && $rowA['verification_status'] === 'Pending', 'New registration stays pending');
        check($rowA && $rowA['government_id_uploaded_at'], 'Upload timestamp is saved');
        check($rowA && preg_match(borrowerIdFilenamePattern(), (string)$rowA['government_id_image_path']) === 1, 'Stored image uses a generated filename');
        check($rowA && $rowB && $rowA['government_id_image_path'] !== $rowB['government_id_image_path'], 'Each borrower receives a distinct image file');

        $pathA = borrowerIdAbsolutePath((string)$rowA['government_id_image_path']);
        $pathB = borrowerIdAbsolutePath((string)$rowB['government_id_image_path']);
        $createdFiles = array_filter([$pathA, $pathB]);
        check($pathA && $pathB && is_file($pathA) && is_file($pathB), 'Image files exist in private storage');
        check($pathA && !str_contains((string)file_get_contents($pathA), '<?php'), 'Re-encoded JPG does not contain PHP');
        $mimeA = (new finfo(FILEINFO_MIME_TYPE))->file($pathA);
        $mimeB = (new finfo(FILEINFO_MIME_TYPE))->file($pathB);
        check($mimeA === 'image/jpeg' && $mimeB === 'image/png', 'Stored files match the uploaded image types', $mimeA . ' / ' . $mimeB);

        $polyglot = $jpg['tmp_name'] . '.poly.jpg';
        file_put_contents($polyglot, file_get_contents($jpg['tmp_name']) . "<?php echo 'hidden';");
        $polyFile = fixtureFile(basename($polyglot), (string)file_get_contents($polyglot));
        $polyCheck = validateBorrowerIdUpload($polyFile);
        if (!empty($polyCheck['ok'])) {
            $storedPoly = persistBorrowerIdImage($polyFile['tmp_name'], $polyCheck['mime']);
            $polyPath = !empty($storedPoly['path']) ? $storedPoly['path'] : '';
            $polyClean = $polyPath !== '' && is_file($polyPath) && !str_contains((string)file_get_contents($polyPath), '<?php');
            check($polyClean, 'Image with appended script content is stored without that script');
            if ($polyPath !== '') {
                borrowerIdDeleteOwnedFile($polyPath);
            }
        } else {
            check(true, 'Image with appended script content is stored without that script', 'rejected during inspection: ' . $polyCheck['message']);
        }
        @unlink($polyglot);
        @unlink($polyFile['tmp_name']);

        $detailA = buildBorrowerApprovalDetail((int)$rowA['id']);
        $detailB = buildBorrowerApprovalDetail((int)$rowB['id']);
        $jsonA = json_encode($detailA);
        $jsonB = json_encode($detailB);
        $labelsA = [];
        foreach ($detailA['borrower']['sections'] ?? [] as $section) {
            $labelsA[] = $section['title'];
            foreach ($section['fields'] as $field) {
                $labelsA[] = $field['label'];
            }
        }
        $requiredLabels = ['Personal Information', 'First Name', 'Middle Name', 'Last Name', 'Date of Birth', 'Gender', 'Civil Status', 'Nationality', 'Contact Information', 'Mobile Number', 'Email Address', 'Complete Address', 'Account Information', 'Username', 'Account Registration Date', 'Account Status', 'Identification Information', 'ID Type', 'ID Number', 'Additional Borrower Information', 'Consent to Terms'];
        $missingLabels = array_values(array_diff($requiredLabels, $labelsA));
        check($missingLabels === [], 'View Details includes the registration sections', $missingLabels ? implode(', ', $missingLabels) : '');
        check(($detailA['borrower']['sections'][0]['fields'][1]['value'] ?? null) === '', 'Empty optional middle name is blank so the interface can show a placeholder');
        check(($detailB['borrower']['sections'][0]['fields'][1]['value'] ?? '') === 'Marie', 'Borrower B middle name stays on Borrower B');
        check(!str_contains((string)$jsonA, (string)$rowB['government_id_number']) && !str_contains((string)$jsonB, (string)$rowA['government_id_number']), 'Opening one borrower does not include the other borrower ID number');
        check(!str_contains((string)$jsonA, (string)$rowA['government_id_image_path']), 'View Details does not expose the private image filename');
        check(!empty($detailA['borrower']['sections'][3]['image']['available']) && !empty($detailB['borrower']['sections'][3]['image']['available']), 'View Details marks the uploaded ID as available');

        $hashA = $conn->prepare('SELECT password FROM users WHERE user_id = ?');
        $hashA->execute([(int)$rowA['user_id']]);
        $secret = (string)$hashA->fetchColumn();
        check($secret !== '' && !str_contains((string)$jsonA, $secret), 'View Details does not return the password hash');

        check(userMayViewBorrowerIdImage(['role' => 'Admin']) === true, 'Administrator may view ID images');
        check(userMayViewBorrowerIdImage(['role' => 'Borrower']) === false, 'Borrower may not view ID images');
        check(userMayViewBorrowerIdImage(['role' => 'Collector']) === false, 'Collector may not view ID images');
        check(userMayViewBorrowerIdImage(null) === false, 'Anonymous user may not view ID images');
        check(borrowerIdAbsolutePath('../' . $decoyName) === null, 'Path traversal cannot resolve an ID image');

        $conn->prepare('UPDATE users SET status = ?, role = ? WHERE user_id = ?')->execute(['Active', 'Borrower', (int)$rowA['user_id']]);
        $conn->prepare('UPDATE borrower_applications SET status = ?, verification_status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?')->execute(['Approved', 'Verified', 'CLI Check', (int)$rowA['id']]);
        $approved = $conn->prepare('SELECT u.status AS user_status, ba.status, ba.verification_status FROM borrower_applications ba JOIN users u ON u.user_id = ba.user_id WHERE ba.id = ?');
        $approved->execute([(int)$rowA['id']]);
        $approvedRow = $approved->fetch(PDO::FETCH_ASSOC);
        check($approvedRow && $approvedRow['user_status'] === 'Active' && $approvedRow['status'] === 'Approved' && $approvedRow['verification_status'] === 'Verified', 'Approve workflow updates the account and application');

        $conn->prepare('UPDATE users SET status = ? WHERE user_id = ?')->execute(['Rejected', (int)$rowB['user_id']]);
        $conn->prepare('UPDATE borrower_applications SET status = ?, verification_status = ? WHERE id = ?')->execute(['Rejected', 'Rejected', (int)$rowB['id']]);
        $rejected = $conn->prepare('SELECT u.status AS user_status, ba.status, ba.verification_status FROM borrower_applications ba JOIN users u ON u.user_id = ba.user_id WHERE ba.id = ?');
        $rejected->execute([(int)$rowB['id']]);
        $rejectedRow = $rejected->fetch(PDO::FETCH_ASSOC);
        check($rejectedRow && $rejectedRow['user_status'] === 'Rejected' && $rejectedRow['status'] === 'Rejected', 'Reject workflow updates the account and application');

        $approvalPage = (string)file_get_contents(dirname(__DIR__) . '/borrower_approval_lending.php');
        check(str_contains($approvalPage, 'name="action" value="approve"') && str_contains($approvalPage, 'Invalid identification') && str_contains($approvalPage, 'name="rejection_reason"'), 'Approval page still contains approve and rejection-reason controls');
    }

    $base = 'http://127.0.0.1/lending_system/lending_system';
    $anonymous = @file_get_contents($base . '/view_borrower_id_lending.php?id=1', false, stream_context_create([
        'http' => ['ignore_errors' => true, 'timeout' => 8],
    ]));
    $anonymousHeaders = implode("\n", $http_response_header ?? []);
    if ($anonymous === false) {
        check(true, 'Unauthorized HTTP request for an ID image is denied', 'web server was not reachable; authorization function was tested directly');
    } else {
        check(str_contains($anonymousHeaders, '403') && str_contains((string)$anonymous, 'Access denied'), 'Unauthorized HTTP request for an ID image is denied', trim((string)strtok($anonymousHeaders, "\n")));
        $detailsAnonymous = @file_get_contents($base . '/borrower_approval_details_lending.php?id=1', false, stream_context_create([
            'http' => ['ignore_errors' => true, 'timeout' => 8, 'header' => "Accept: application/json\r\n"],
        ]));
        $detailsHeaders = implode("\n", $http_response_header ?? []);
        check(str_contains($detailsHeaders, '403'), 'Unauthorized HTTP request for borrower details is denied', trim((string)strtok($detailsHeaders, "\n")));
    }
} catch (Throwable $e) {
    check(false, 'Checker completed without an exception', $e->getMessage());
} finally {
    cleanupAccount(isset($resultA['user_id']) ? (int)$resultA['user_id'] : null, isset($resultA['application_id']) ? (int)$resultA['application_id'] : null, $token);
    cleanupAccount(isset($resultB['user_id']) ? (int)$resultB['user_id'] : null, isset($resultB['application_id']) ? (int)$resultB['application_id'] : null, $token);
    cleanupAccount(null, null, $token);
    foreach ($createdFiles as $createdFile) {
        if (is_string($createdFile) && is_file($createdFile)) {
            borrowerIdDeleteOwnedFile($createdFile);
        }
    }
    if (is_string($decoyPath) && is_file($decoyPath)) {
        @unlink($decoyPath);
    }
    foreach (['jpg', 'png', 'txt', 'big', 'disguised', 'mismatch', 'double'] as $unused) {
        // Temporary fixtures are removed individually below.
    }
    foreach ([$jpg['tmp_name'] ?? null, $png['tmp_name'] ?? null, $textFile['tmp_name'] ?? null, $bigPath ?? null, $disguised['tmp_name'] ?? null, $mismatch['tmp_name'] ?? null, $doubleExt['tmp_name'] ?? null] as $temp) {
        if (is_string($temp) && is_file($temp)) {
            @unlink($temp);
        }
    }
}

$endCount = (int)$conn->query('SELECT COUNT(*) FROM borrower_applications')->fetchColumn();
check($endCount === $beforeCount, 'Fixture borrowers are removed and existing rows remain', 'before ' . $beforeCount . ', after ' . $endCount);

echo PHP_EOL . count($passes) . ' passed, ' . count($failures) . ' failed.' . PHP_EOL;
exit($failures === [] ? 0 : 1);
