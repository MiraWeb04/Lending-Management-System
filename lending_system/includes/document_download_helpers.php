<?php
/**
 * Secure loan document paths and download authorization.
 */

function loanDocumentStorageDir(): string
{
    return dirname(__DIR__) . '/uploads/loan_documents';
}

function loanDocumentAllowedExtensions(): array
{
    return ['jpg', 'jpeg', 'png', 'pdf'];
}

function loanDocumentSanitizeRelativePath(string $relativePath): ?string
{
    $relativePath = str_replace('\\', '/', trim($relativePath));
    if ($relativePath === '' || str_contains($relativePath, '..')) {
        return null;
    }

    if (!preg_match('#^uploads/loan_documents/[a-zA-Z0-9._-]+$#', $relativePath)) {
        return null;
    }

    return $relativePath;
}

function loanDocumentAbsolutePath(string $relativePath): ?string
{
    $safe = loanDocumentSanitizeRelativePath($relativePath);
    if ($safe === null) {
        return null;
    }

    $absolute = dirname(__DIR__) . '/' . $safe;
    $realBase = realpath(loanDocumentStorageDir());
    $realFile = realpath($absolute);
    if ($realBase === false || $realFile === false || !str_starts_with($realFile, $realBase)) {
        return null;
    }

    return $realFile;
}

function loanDocumentUrl(string $relativePath): string
{
    $safe = loanDocumentSanitizeRelativePath($relativePath);
    if ($safe === null) {
        return '#';
    }

    return 'download_loan_document_lending.php?path=' . rawurlencode($safe);
}

function userCanDownloadLoanDocument(string $relativePath, array $user): bool
{
    if (!is_array($user) || empty($user['user_id'])) {
        return false;
    }

    $safe = loanDocumentSanitizeRelativePath($relativePath);
    if ($safe === null) {
        return false;
    }

    $role = trim((string)($user['role'] ?? ''));
    if (strcasecmp($role, 'Admin') === 0) {
        return true;
    }

    $userId = (int)$user['user_id'];

    if (strcasecmp($role, 'Collector') === 0) {
        $stmt = executeQuery(
            'SELECT 1 FROM loan_application_documents d
             JOIN loan_applications la ON la.id = d.application_id
             JOIN clients c ON c.user_id = la.user_id
             WHERE d.document_path = ? AND c.collector_id = ?
             LIMIT 1',
            [$safe, $userId]
        );
        if ($stmt && (bool)$stmt->fetchColumn()) {
            return true;
        }
        $stmt = executeQuery(
            'SELECT 1 FROM loan_application_documents d
             JOIN loan_applications la ON la.id = d.application_id
             JOIN loans l ON l.client_id IN (SELECT client_id FROM clients WHERE user_id = la.user_id)
             JOIN clients c ON c.client_id = l.client_id
             WHERE d.document_path = ? AND l.collector_id = ?
             LIMIT 1',
            [$safe, $userId]
        );
        return $stmt && (bool)$stmt->fetchColumn();
    }

    if (strcasecmp($role, 'Borrower') === 0) {
        $stmt = executeQuery(
            'SELECT 1 FROM loan_application_documents d
             JOIN loan_applications la ON la.id = d.application_id
             WHERE la.user_id = ? AND d.document_path = ?
             LIMIT 1',
            [$userId, $safe]
        );
        return $stmt && (bool)$stmt->fetchColumn();
    }

    return false;
}

function validateLoanDocumentUpload(array $file): array
{
    if (!isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'message' => 'No file uploaded.'];
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Upload failed. Please try again.'];
    }

    $maxBytes = 8 * 1024 * 1024;
    if ((int)($file['size'] ?? 0) > $maxBytes) {
        return ['ok' => false, 'message' => 'File must be 8 MB or smaller.'];
    }

    $original = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($ext, loanDocumentAllowedExtensions(), true)) {
        return ['ok' => false, 'message' => 'Allowed file types: JPG, PNG, PDF.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, (string)($file['tmp_name'] ?? '')) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    $allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];
    if ($mime !== '' && !in_array($mime, $allowedMimes, true)) {
        return ['ok' => false, 'message' => 'Invalid file type detected.'];
    }

    return ['ok' => true, 'extension' => $ext];
}
