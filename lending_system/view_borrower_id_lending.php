<?php
/**
 * Authenticated view of a borrower registration ID image.
 * Callers pass the application id. The storage path is never accepted from the request.
 */

require_once 'includes/auth_lending.php';
require_once 'includes/borrower_registration_helpers.php';

if (!isLoggedIn() || !userMayViewBorrowerIdImage(getCurrentUser())) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo 'Access denied.';
    exit;
}

$applicationId = (int)($_GET['id'] ?? 0);
if ($applicationId <= 0) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo 'ID image not found.';
    exit;
}

ensureBorrowerApplicationsTable();
$record = executeQuery(
    'SELECT government_id_image_path FROM borrower_applications WHERE id = ? LIMIT 1',
    [$applicationId]
);
$row = $record ? $record->fetch(PDO::FETCH_ASSOC) : false;
$storedName = trim((string)($row['government_id_image_path'] ?? ''));
$absolute = $storedName !== '' ? borrowerIdAbsolutePath($storedName) : null;

if ($absolute === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo 'ID image not found.';
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? (string)finfo_file($finfo, $absolute) : '';
if ($finfo) {
    finfo_close($finfo);
}

$allowed = borrowerIdMimeMap();
if (!isset($allowed[$mime])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo 'ID image not found.';
    exit;
}

$extension = $allowed[$mime];
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="valid-id.' . $extension . '"');
header('Content-Length: ' . (string)filesize($absolute));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
readfile($absolute);
exit;
