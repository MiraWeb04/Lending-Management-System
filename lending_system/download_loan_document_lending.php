<?php
/**
 * Authenticated download for loan KYC documents.
 */

require_once 'includes/auth_lending.php';
requireLogin();
require_once 'includes/document_download_helpers.php';

$user = getCurrentUser();
$relative = trim((string)($_GET['path'] ?? ''));

if (!userCanDownloadLoanDocument($relative, $user ?? [])) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$absolute = loanDocumentAbsolutePath($relative);
if ($absolute === null || !is_readable($absolute)) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

$ext = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'pdf' => 'application/pdf',
    default => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($absolute) . '"');
header('Content-Length: ' . (string)filesize($absolute));
header('X-Content-Type-Options: nosniff');
readfile($absolute);
exit;
