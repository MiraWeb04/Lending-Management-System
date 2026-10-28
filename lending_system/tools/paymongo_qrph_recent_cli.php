<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require_once dirname(__DIR__) . '/includes/db_lending.php';

$rows = $conn->query(
    "SELECT t.id, t.loan_id, t.status, t.amount, t.internal_reference, t.payment_intent_id,
            t.provider_payment_id, t.payment_id, t.provider_status, t.created_at, t.updated_at
     FROM paymongo_qrph_transactions t
     ORDER BY t.id DESC
     LIMIT 15"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
