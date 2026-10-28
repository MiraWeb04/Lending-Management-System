<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require_once dirname(__DIR__) . '/includes/db_lending.php';
require_once dirname(__DIR__) . '/includes/paymongo_qrph_webhook.php';

$manualClose = in_array('--manual', $argv, true);
$loanId = 0;
foreach ($argv as $index => $argument) {
    if ($index === 0 || $argument === '--manual') {
        continue;
    }
    if (ctype_digit($argument)) {
        $loanId = (int)$argument;
        break;
    }
}
if ($loanId <= 0) {
    fwrite(STDERR, "Usage: php paymongo_qrph_prepare_loan_cli.php [--manual] <loan_id>\n");
    exit(1);
}

try {
    if ($manualClose) {
        lendingPrepareLoanForManualPaymentRecording($loanId);
    } else {
        lendingPrepareLoanForNewPaymongoQrph($loanId);
    }
    echo 'prepare_ok loan_id=' . $loanId . ($manualClose ? ' mode=manual' : ' mode=new_qrph') . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
