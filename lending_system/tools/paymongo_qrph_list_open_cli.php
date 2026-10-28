<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require_once dirname(__DIR__) . '/includes/db_lending.php';

$loanId = isset($argv[1]) && ctype_digit($argv[1]) ? (int)$argv[1] : 0;
$sql = "SELECT id, loan_id, status, provider_status, payment_intent_id, internal_reference,
               expires_at, created_at, LEFT(COALESCE(failure_message,''), 80) AS failure_message
        FROM paymongo_qrph_transactions
        WHERE status IN ('pending', 'review_required')";
$params = [];
if ($loanId > 0) {
    $sql .= ' AND loan_id = ?';
    $params[] = $loanId;
}
$sql .= ' ORDER BY id DESC LIMIT 50';

$statement = $conn->prepare($sql);
$statement->execute($params);
foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
