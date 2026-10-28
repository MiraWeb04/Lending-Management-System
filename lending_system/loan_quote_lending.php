<?php

require_once 'includes/loan_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$principal = $_GET['principal'] ?? null;
$months = $_GET['months'] ?? null;
if (!is_numeric($principal) || !is_numeric($months) || (int)$months != (float)$months) {
    http_response_code(422);
    echo json_encode(['error' => 'Enter a loan amount and a whole number of months.']);
    exit;
}

try {
    $quote = buildPublicLoanQuote((float)$principal, getStandardMonthlyInterestRatePercent(), (int)$months);
    echo json_encode($quote);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
}
