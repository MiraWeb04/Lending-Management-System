<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require_once dirname(__DIR__) . '/includes/db_lending.php';
require_once dirname(__DIR__) . '/includes/paymongo_qrph_webhook.php';

$transactionId = isset($argv[1]) && ctype_digit($argv[1]) ? (int)$argv[1] : 0;
if ($transactionId <= 0) {
    fwrite(STDERR, "Usage: php paymongo_qrph_reconcile_cli.php <transaction_id>\n");
    exit(1);
}

try {
    $result = lendingReconcilePaymongoQrphTransaction($transactionId);
    echo 'reconcile_result=' . $result . PHP_EOL;
    $row = $conn->prepare('SELECT id, loan_id, status, provider_status FROM paymongo_qrph_transactions WHERE id = ?');
    $row->execute([$transactionId]);
    echo json_encode($row->fetch(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
