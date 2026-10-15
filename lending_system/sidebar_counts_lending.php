<?php
/**
 * JSON feed for live sidebar action counts.
 */

require_once __DIR__ . '/includes/auth_lending.php';
require_once __DIR__ . '/includes/sidebar_counts.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}

echo json_encode([
    'ok' => true,
    'counts' => lendingSidebarActionCounts(),
]);
