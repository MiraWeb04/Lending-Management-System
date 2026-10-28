<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require_once dirname(__DIR__) . '/includes/db_lending.php';

$byPaymentCollector = $conn->exec(
    "UPDATE payments p
     INNER JOIN users u ON u.user_id = p.collector_id
     SET p.collector_name = u.full_name
     WHERE p.collector_name = 'PayMongo QR Ph'
       AND TRIM(COALESCE(u.full_name, '')) <> ''"
);

$byTransaction = 0;
$tables = $conn->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'paymongo_qrph_transactions'"
)->fetchColumn();
if ((int)$tables > 0) {
    $byTransaction = $conn->exec(
        "UPDATE payments p
         INNER JOIN paymongo_qrph_transactions t ON t.payment_id = p.payment_id
         INNER JOIN users u ON u.user_id = COALESCE(NULLIF(t.collector_id, 0), t.initiated_by_user_id)
         SET p.collector_name = u.full_name,
             p.collector_id = COALESCE(p.collector_id, u.user_id)
         WHERE p.collector_name = 'PayMongo QR Ph'
           AND TRIM(COALESCE(u.full_name, '')) <> ''"
    );
}

echo 'updated_by_collector_id=' . (int)$byPaymentCollector . PHP_EOL;
echo 'updated_by_qrph_transaction=' . (int)$byTransaction . PHP_EOL;
