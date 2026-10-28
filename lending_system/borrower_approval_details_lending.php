<?php
/**
 * Admin JSON for Borrower Approval → View Details.
 * Returns registration fields only. Passwords and ID storage paths are never included.
 */

require_once 'includes/auth_lending.php';
require_once 'includes/borrower_registration_helpers.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

if (!isLoggedIn() || !userMayViewBorrowerIdImage(getCurrentUser())) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'You do not have permission to view borrower details.',
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}

$applicationId = (int)($_GET['id'] ?? 0);
ensureBorrowerApplicationsTable();
$detail = buildBorrowerApprovalDetail($applicationId);
$status = (int)($detail['status'] ?? 500);
unset($detail['status']);

http_response_code($status > 0 ? $status : 500);
$encoded = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if ($encoded === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Borrower information could not be retrieved.']);
    exit;
}

echo $encoded;
exit;
