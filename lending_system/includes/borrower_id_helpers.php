<?php
/**
 * Valid-ID upload validation and private storage for borrower registration.
 * Images are stored outside the web root when the server allows it, and are
 * served only through an authenticated admin endpoint.
 */

require_once __DIR__ . '/env_lending.php';
require_once __DIR__ . '/db_lending.php';

function borrowerIdMaxBytes(): int
{
    return 5 * 1024 * 1024;
}

function borrowerIdTypeOptions(): array
{
    return [
        "Driver's License",
        'Passport',
        'UMID',
        'SSS/GSIS',
    ];
}

function borrowerIdWebpSupported(): bool
{
    return defined('IMAGETYPE_WEBP')
        && function_exists('imagecreatefromwebp')
        && function_exists('imagewebp');
}

function borrowerIdAcceptAttribute(): string
{
    $types = ['image/jpeg', 'image/png'];
    if (borrowerIdWebpSupported()) {
        $types[] = 'image/webp';
    }

    return implode(',', $types);
}

function borrowerIdFormatLabel(): string
{
    $formats = 'JPG, JPEG, or PNG';
    if (borrowerIdWebpSupported()) {
        $formats .= ', or WEBP';
    }

    return $formats . ' up to 5 MB.';
}

function borrowerIdMimeMap(): array
{
    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];
    if (borrowerIdWebpSupported()) {
        $map['image/webp'] = 'webp';
    }

    return $map;
}

function borrowerIdExtensionAliases(): array
{
    $aliases = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
    ];
    if (borrowerIdWebpSupported()) {
        $aliases['image/webp'] = ['webp'];
    }

    return $aliases;
}

function validateBorrowerIdType(string $type): string
{
    if ($type === '' || !in_array($type, borrowerIdTypeOptions(), true)) {
        return 'Please select a supported ID type.';
    }

    return '';
}

function validateBorrowerIdNumber(string $number): string
{
    $number = trim(preg_replace('/\s+/', ' ', $number) ?? '');
    if ($number === '') {
        return 'Please enter the ID number.';
    }
    if (strlen($number) > 100) {
        return 'The ID number must be 100 characters or fewer.';
    }
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 .\/-]{1,99}$/', $number)) {
        return 'Please enter a valid ID number.';
    }

    return '';
}

function borrowerIdDocumentRoot(): string
{
    $docRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if (is_string($docRoot) && $docRoot !== '') {
        return $docRoot;
    }

    $htdocs = dirname(__DIR__, 3);
    $real = realpath($htdocs);

    return $real !== false ? $real : $htdocs;
}

function borrowerIdPathIsInside(string $path, string $directory): bool
{
    $path = rtrim(str_replace('\\', '/', strtolower($path)), '/');
    $directory = rtrim(str_replace('\\', '/', strtolower($directory)), '/');
    if ($path === '' || $directory === '') {
        return false;
    }

    return $path === $directory || str_starts_with($path, $directory . '/');
}

function borrowerIdCandidateDirs(): array
{
    $candidates = [];
    $configured = trim((string)lendingEnv('BORROWER_ID_STORAGE_DIR', ''));
    if ($configured !== '') {
        $candidates[] = $configured;
    }

    $docRoot = borrowerIdDocumentRoot();
    $parent = dirname($docRoot);
    if ($parent !== $docRoot && strlen(rtrim(str_replace('\\', '/', $parent), '/')) > 2) {
        $candidates[] = $parent . DIRECTORY_SEPARATOR . 'frams_private' . DIRECTORY_SEPARATOR . 'borrower_ids';
    }

    $candidates[] = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'frams_private' . DIRECTORY_SEPARATOR . 'borrower_ids';
    $candidates[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'borrower_ids';

    $unique = [];
    foreach ($candidates as $candidate) {
        $normalized = rtrim(str_replace('\\', '/', $candidate), '/');
        if ($normalized === '' || isset($unique[$normalized])) {
            continue;
        }
        $unique[$normalized] = $candidate;
    }

    return array_values($unique);
}

function borrowerIdPrepareDir(string $dir): bool
{
    if ($dir === '' || str_contains($dir, "\0")) {
        return false;
    }
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }
    $real = realpath($dir);
    if ($real === false || !is_writable($real)) {
        return false;
    }

    $probe = $real . DIRECTORY_SEPARATOR . '.write_probe';
    if (@file_put_contents($probe, 'ok') === false) {
        return false;
    }
    @unlink($probe);

    $htaccess = $real . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\nDeny from all\nOptions -Indexes\n");
    }

    return true;
}

function borrowerIdStorageDir(): string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $fallback = null;
    foreach (borrowerIdCandidateDirs() as $candidate) {
        if (!borrowerIdPrepareDir($candidate)) {
            continue;
        }
        $real = realpath($candidate);
        if ($real === false) {
            continue;
        }
        if (!borrowerIdPathIsWebAccessible($real)) {
            $resolved = $real;
            return $resolved;
        }
        if ($fallback === null) {
            $fallback = $real;
        }
    }

    if ($fallback === null) {
        throw new RuntimeException('Unable to prepare secure ID storage.');
    }

    $resolved = $fallback;
    return $resolved;
}

function borrowerIdPathIsWebAccessible(string $path): bool
{
    if (borrowerIdPathIsInside($path, borrowerIdDocumentRoot())) {
        return true;
    }

    $normalized = str_replace('\\', '/', strtolower($path));

    return str_contains($normalized, '/htdocs/') || str_contains($normalized, '/www/');
}

function borrowerIdStorageIsOutsideWebRoot(): bool
{
    try {
        return !borrowerIdPathIsWebAccessible(borrowerIdStorageDir());
    } catch (Throwable $e) {
        return false;
    }
}

function borrowerIdFilenamePattern(): string
{
    return '/^id_[a-f0-9]{32}\.(jpg|png|webp)$/';
}

function borrowerIdUploadIsAcceptable(array $file): bool
{
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        return false;
    }
    if (is_uploaded_file($tmp)) {
        return true;
    }

    if (PHP_SAPI !== 'cli' || empty($file['cli_fixture'])) {
        return false;
    }

    $real = realpath($tmp);
    $temp = realpath(sys_get_temp_dir());
    if ($real === false || $temp === false) {
        return false;
    }

    return borrowerIdPathIsInside($real, $temp);
}

function validateBorrowerIdUpload(array $file): array
{
    if (!isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'message' => 'Please upload a clear image of your valid ID.'];
    }

    $error = (int)$file['error'];
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'message' => 'The ID image must be 5 MB or smaller.'];
    }
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'The ID image could not be uploaded. Please try again.'];
    }
    if (!borrowerIdUploadIsAcceptable($file)) {
        return ['ok' => false, 'message' => 'The ID image upload is invalid.'];
    }

    $tmp = (string)$file['tmp_name'];
    $actualSize = (int)filesize($tmp);
    $reportedSize = (int)($file['size'] ?? 0);
    if ($actualSize <= 0 || $reportedSize < 0) {
        return ['ok' => false, 'message' => 'The uploaded ID image is empty.'];
    }
    if ($actualSize > borrowerIdMaxBytes() || $reportedSize > borrowerIdMaxBytes()) {
        return ['ok' => false, 'message' => 'The ID image must be 5 MB or smaller.'];
    }

    $originalName = (string)($file['name'] ?? '');
    if ($originalName === '' || str_contains($originalName, "\0") || str_contains($originalName, '/') || str_contains($originalName, '\\')) {
        return ['ok' => false, 'message' => 'This file type is not allowed.'];
    }

    $dangerous = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'exe', 'js', 'html', 'htm', 'svg', 'cgi', 'asp', 'aspx', 'htaccess'];
    $parts = explode('.', strtolower($originalName));
    foreach (array_slice($parts, 1) as $part) {
        if (in_array($part, $dangerous, true)) {
            return ['ok' => false, 'message' => 'This file type is not allowed.'];
        }
    }

    if (!function_exists('finfo_open')) {
        return ['ok' => false, 'message' => 'The ID image could not be uploaded. Please try again.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string)finfo_file($finfo, $tmp) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    $mimeMap = borrowerIdMimeMap();
    if (!isset($mimeMap[$mime])) {
        $allowed = borrowerIdWebpSupported() ? 'JPG, PNG, or WEBP' : 'JPG or PNG';
        return ['ok' => false, 'message' => 'Upload a ' . $allowed . ' image of your valid ID.'];
    }

    $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
    $aliases = borrowerIdExtensionAliases();
    if ($extension === '' || !in_array($extension, $aliases[$mime], true)) {
        return ['ok' => false, 'message' => 'The file extension does not match the detected image type.'];
    }

    $imageInfo = @getimagesize($tmp);
    if ($imageInfo === false) {
        return ['ok' => false, 'message' => 'The uploaded file is not a valid image. Please upload a clear photo of your ID.'];
    }

    $typeMap = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
    ];
    if (defined('IMAGETYPE_WEBP')) {
        $typeMap[IMAGETYPE_WEBP] = 'image/webp';
    }
    $imageMime = $typeMap[$imageInfo[2] ?? -1] ?? '';
    if ($imageMime === '' || $imageMime !== $mime) {
        return ['ok' => false, 'message' => 'The uploaded file content does not match a supported ID image.'];
    }

    $width = (int)($imageInfo[0] ?? 0);
    $height = (int)($imageInfo[1] ?? 0);
    if ($width < 40 || $height < 40) {
        return ['ok' => false, 'message' => 'The ID image is too small to read. Upload a clearer photo of your ID.'];
    }

    $loader = match ($mime) {
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
        default => '',
    };
    if ($loader === '' || !function_exists($loader)) {
        $contents = file_get_contents($tmp);
        if ($contents === false || preg_match('/<\?(php|=)/i', $contents) === 1 || stripos($contents, '<script') !== false) {
            return ['ok' => false, 'message' => 'The uploaded file content does not match a supported ID image.'];
        }
    }

    return ['ok' => true, 'mime' => $mime, 'extension' => $mimeMap[$mime]];
}

function borrowerIdDeleteOwnedFile(string $path): void
{
    $real = realpath($path);
    if ($real === false || !is_file($real)) {
        return;
    }

    try {
        $base = realpath(borrowerIdStorageDir());
    } catch (Throwable $e) {
        return;
    }
    if ($base === false || !borrowerIdPathIsInside($real, $base)) {
        return;
    }
    if (!preg_match(borrowerIdFilenamePattern(), basename($real))) {
        return;
    }

    @unlink($real);
}

function borrowerIdWriteSanitizedImage(string $source, string $dest, string $mime): bool
{
    $writers = [
        'image/jpeg' => ['imagecreatefromjpeg', 'imagejpeg'],
        'image/png' => ['imagecreatefrompng', 'imagepng'],
        'image/webp' => ['imagecreatefromwebp', 'imagewebp'],
    ];
    if (!isset($writers[$mime])) {
        return false;
    }

    [$loader, $saver] = $writers[$mime];
    if (!function_exists($loader) || !function_exists($saver)) {
        $contents = file_get_contents($source);
        if ($contents === false || preg_match('/<\?(php|=)/i', $contents) === 1 || stripos($contents, '<script') !== false) {
            return false;
        }

        return @copy($source, $dest);
    }

    $image = @$loader($source);
    if ($image === false) {
        return false;
    }

    if ($mime === 'image/png') {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $saved = @imagepng($image, $dest, 6);
    } elseif ($mime === 'image/webp') {
        $saved = @$saver($image, $dest, 90);
    } else {
        $saved = @$saver($image, $dest, 90);
    }
    imagedestroy($image);

    if (!$saved || !is_file($dest) || filesize($dest) <= 0) {
        return false;
    }

    $info = @getimagesize($dest);
    return $info !== false;
}

function persistBorrowerIdImage(string $sourcePath, string $mime): array
{
    $extension = borrowerIdMimeMap()[$mime] ?? '';
    if ($extension === '') {
        return ['ok' => false, 'message' => 'Upload a JPG or PNG image of your valid ID.'];
    }

    try {
        $dir = borrowerIdStorageDir();
    } catch (Throwable $e) {
        error_log('persistBorrowerIdImage storage: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'The ID image could not be saved. Please try again.'];
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $name = 'id_' . bin2hex(random_bytes(16)) . '.' . $extension;
        if (!preg_match(borrowerIdFilenamePattern(), $name)) {
            continue;
        }
        $dest = $dir . DIRECTORY_SEPARATOR . $name;
        if (is_file($dest)) {
            continue;
        }

        $handle = @fopen($dest, 'xb');
        if ($handle === false) {
            continue;
        }
        fclose($handle);

        if (!borrowerIdWriteSanitizedImage($sourcePath, $dest, $mime)) {
            borrowerIdDeleteOwnedFile($dest);
            return ['ok' => false, 'message' => 'The uploaded ID image is corrupted or could not be processed.'];
        }

        return ['ok' => true, 'filename' => $name, 'path' => $dest];
    }

    return ['ok' => false, 'message' => 'The ID image could not be saved. Please try again.'];
}

function borrowerIdAbsolutePath(string $storedName): ?string
{
    $storedName = trim($storedName);
    if ($storedName === '' || str_contains($storedName, '/') || str_contains($storedName, '\\') || str_contains($storedName, '..')) {
        return null;
    }
    if (!preg_match(borrowerIdFilenamePattern(), $storedName)) {
        return null;
    }

    try {
        $base = realpath(borrowerIdStorageDir());
    } catch (Throwable $e) {
        return null;
    }
    if ($base === false) {
        return null;
    }

    $candidate = $base . DIRECTORY_SEPARATOR . $storedName;
    $real = realpath($candidate);
    if ($real === false || !is_file($real) || !is_readable($real)) {
        return null;
    }
    if (!borrowerIdPathIsInside($real, $base)) {
        return null;
    }

    return $real;
}

function userMayViewBorrowerIdImage(?array $user): bool
{
    if (!is_array($user)) {
        return false;
    }

    return trim((string)($user['role'] ?? '')) === 'Admin';
}

function borrowerFormatDisplayDate(?string $value, bool $withTime = false): string
{
    $value = trim((string)$value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return '';
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return '';
    }

    return date($withTime ? 'M d, Y g:i A' : 'M d, Y', $timestamp);
}

function buildBorrowerApprovalDetail(int $applicationId): array
{
    if ($applicationId <= 0) {
        return ['ok' => false, 'error' => 'Borrower record not found.', 'status' => 404];
    }

    $stmt = executeQuery(
        'SELECT
            ba.id,
            ba.first_name,
            ba.middle_name,
            ba.last_name,
            ba.date_of_birth,
            ba.gender,
            ba.civil_status,
            ba.nationality,
            ba.mobile_number,
            ba.email,
            ba.complete_address,
            ba.username AS application_username,
            ba.government_id_type,
            ba.government_id_number,
            ba.government_id_image_path,
            ba.government_id_uploaded_at,
            ba.consent_accepted,
            ba.status AS application_status,
            ba.verification_status,
            ba.rejection_reason,
            ba.approved_by,
            ba.approved_at,
            ba.created_at,
            u.username AS account_username,
            u.status AS account_status,
            u.date_created AS account_created_at
         FROM borrower_applications ba
         LEFT JOIN users u ON u.user_id = ba.user_id
         WHERE ba.id = ?
         LIMIT 1',
        [$applicationId]
    );

    if ($stmt === false) {
        return ['ok' => false, 'error' => 'Borrower information could not be retrieved.', 'status' => 500];
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'error' => 'Borrower record not found.', 'status' => 404];
    }

    $accountUsername = trim((string)($row['account_username'] ?? ''));
    $registeredUsername = trim((string)($row['application_username'] ?? ''));
    $username = $accountUsername !== '' ? $accountUsername : $registeredUsername;
    $accountStatus = trim((string)($row['account_status'] ?? ''));
    if ($accountStatus === '') {
        $accountStatus = trim((string)($row['application_status'] ?? ''));
    }
    $registeredAt = borrowerFormatDisplayDate((string)($row['account_created_at'] ?? ''), true);
    if ($registeredAt === '') {
        $registeredAt = borrowerFormatDisplayDate((string)($row['created_at'] ?? ''), true);
    }

    $consentRaw = $row['consent_accepted'];
    if ($consentRaw === null || $consentRaw === '') {
        $consent = '';
    } else {
        $consent = (int)$consentRaw === 1 ? 'Accepted' : 'Not accepted';
    }

    $storedImage = trim((string)($row['government_id_image_path'] ?? ''));
    $imagePath = $storedImage !== '' ? borrowerIdAbsolutePath($storedImage) : null;
    if ($imagePath !== null) {
        $image = [
            'available' => true,
            'message' => '',
        ];
    } elseif ($storedImage !== '') {
        $image = [
            'available' => false,
            'message' => 'An ID image was recorded for this borrower, but the file could not be retrieved.',
        ];
    } else {
        $image = [
            'available' => false,
            'message' => 'No ID image was uploaded with this registration.',
        ];
    }

    $additional = [
        ['label' => 'Consent to Terms', 'value' => $consent],
        ['label' => 'Verification Status', 'value' => trim((string)($row['verification_status'] ?? ''))],
    ];
    $rejectionReason = trim((string)($row['rejection_reason'] ?? ''));
    if ($rejectionReason !== '') {
        $additional[] = ['label' => 'Rejection Reason', 'value' => $rejectionReason, 'wide' => true];
    }
    $approvedBy = trim((string)($row['approved_by'] ?? ''));
    if ($approvedBy !== '') {
        $additional[] = ['label' => 'Reviewed By', 'value' => $approvedBy];
    }
    $approvedAt = borrowerFormatDisplayDate((string)($row['approved_at'] ?? ''), true);
    if ($approvedAt !== '') {
        $additional[] = ['label' => 'Reviewed At', 'value' => $approvedAt];
    }

    $fullName = trim(preg_replace('/\s+/', ' ', trim((string)$row['first_name']) . ' ' . trim((string)$row['middle_name']) . ' ' . trim((string)$row['last_name'])) ?? '');

    return [
        'ok' => true,
        'status' => 200,
        'borrower' => [
            'id' => (int)$row['id'],
            'full_name' => $fullName,
            'sections' => [
                [
                    'title' => 'Personal Information',
                    'icon' => 'fa-user',
                    'fields' => [
                        ['label' => 'First Name', 'value' => trim((string)($row['first_name'] ?? ''))],
                        ['label' => 'Middle Name', 'value' => trim((string)($row['middle_name'] ?? ''))],
                        ['label' => 'Last Name', 'value' => trim((string)($row['last_name'] ?? ''))],
                        ['label' => 'Date of Birth', 'value' => borrowerFormatDisplayDate((string)($row['date_of_birth'] ?? ''))],
                        ['label' => 'Gender', 'value' => trim((string)($row['gender'] ?? ''))],
                        ['label' => 'Civil Status', 'value' => trim((string)($row['civil_status'] ?? ''))],
                        ['label' => 'Nationality', 'value' => trim((string)($row['nationality'] ?? ''))],
                    ],
                ],
                [
                    'title' => 'Contact Information',
                    'icon' => 'fa-address-book',
                    'fields' => [
                        ['label' => 'Mobile Number', 'value' => trim((string)($row['mobile_number'] ?? ''))],
                        ['label' => 'Email Address', 'value' => trim((string)($row['email'] ?? ''))],
                        ['label' => 'Complete Address', 'value' => trim((string)($row['complete_address'] ?? '')), 'wide' => true],
                    ],
                ],
                [
                    'title' => 'Account Information',
                    'icon' => 'fa-key',
                    'fields' => [
                        ['label' => 'Username', 'value' => $username],
                        ['label' => 'Account Registration Date', 'value' => $registeredAt],
                        ['label' => 'Account Status', 'value' => $accountStatus],
                    ],
                ],
                [
                    'title' => 'Identification Information',
                    'icon' => 'fa-id-card',
                    'fields' => [
                        ['label' => 'ID Type', 'value' => trim((string)($row['government_id_type'] ?? ''))],
                        ['label' => 'ID Number', 'value' => trim((string)($row['government_id_number'] ?? ''))],
                        ['label' => 'ID Image Uploaded', 'value' => borrowerFormatDisplayDate((string)($row['government_id_uploaded_at'] ?? ''), true)],
                    ],
                    'image' => $image,
                ],
                [
                    'title' => 'Additional Borrower Information',
                    'icon' => 'fa-clipboard-list',
                    'fields' => $additional,
                ],
            ],
        ],
    ];
}
