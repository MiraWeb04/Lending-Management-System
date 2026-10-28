<?php
/**
 * Loan-term validation checks. These do not replace FC-01 through FC-11.
 *
 * Run with the isolated prepend:
 * php -d auto_prepend_file=testing_evidence/_harness/env_prepend.php testing/harness/run_fc_term_validation.php
 *
 * No borrower rows are inserted or updated.
 */

if (!defined('FRAMS_TEST_PREPEND_LOADED')) {
    fwrite(STDERR, "Refusing to run without the isolated test prepend.\n");
    exit(1);
}

require_once dirname(__DIR__, 2) . '/lending_system/includes/db_lending.php';
require_once dirname(__DIR__, 2) . '/lending_system/includes/loan_helpers.php';
require_once dirname(__DIR__, 2) . '/lending_system/includes/loan_eligibility_helpers.php';
require_once dirname(__DIR__, 2) . '/lending_system/includes/agreement_helpers.php';

global $conn;
$connected = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
if ($connected !== 'frams_capstone_test') {
    fwrite(STDERR, "Refusing to run. Connected database is not the isolated test database.\n");
    exit(1);
}

function ltExtractFunction(string $source, string $name): string
{
    $needle = 'function ' . $name;
    $pos = strpos($source, $needle);
    if ($pos === false) {
        throw new RuntimeException('Function not found: ' . $name);
    }
    $brace = strpos($source, '{', $pos);
    $depth = 0;
    $length = strlen($source);
    for ($i = $brace; $i < $length; $i++) {
        $char = $source[$i];
        if ($char === '{') {
            $depth++;
        } elseif ($char === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $pos, $i - $pos + 1);
            }
        }
    }
    throw new RuntimeException('Unclosed function: ' . $name);
}

$releaseSource = file_get_contents(dirname(__DIR__, 2) . '/lending_system/loan_release_lending.php');
eval(ltExtractFunction($releaseSource, 'buildPaymentSchedule'));

function ltCounts(PDO $conn): array
{
    $counts = [];
    foreach (['clients', 'loan_applications', 'loan_agreements', 'loans', 'loan_releases', 'payments'] as $table) {
        $counts[$table] = (int)$conn->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }
    return $counts;
}

function ltRecord(array &$results, string $id, string $expected, $actual, bool $pass, string $explanation): void
{
    $results[] = [
        'id' => $id,
        'expected' => $expected,
        'actual' => $actual,
        'status' => $pass ? 'PASS' : 'FAIL',
        'explanation' => $explanation,
    ];
}

function ltEligibility(string $term): array
{
    return assessLoanEligibility([
        'monthly_gross_income' => 30000,
        'employment_status' => 'Employed',
        'employment_length' => '12 months',
        'loan_amount' => 10000,
        'loan_term' => $term,
        'payment_frequency' => 'Monthly',
    ]);
}

$before = ltCounts($conn);
$results = [];
$message = loanTermValidationMessage();

$valid = resolveApplicationLoanTerm('12 months', '');
$validInterest = $valid['valid']
    ? calculateLoanInterestBreakdown(10000, getStandardMonthlyInterestRatePercent(), $valid['months'])
    : null;
ltRecord(
    $results,
    'LT-01',
    'Approved term "12 months" is kept. Payable on 10,000.00 is 16,000.00. No invented term.',
    [
        'label' => $valid['label'],
        'months' => $valid['months'],
        'valid' => $valid['valid'],
        'total_payable' => $validInterest['total_payable'] ?? null,
    ],
    $valid['valid'] && $valid['label'] === '12 months' && $valid['months'] === 12 && ($validInterest['total_payable'] ?? null) === 16000.0,
    'An explicit approved term is parsed and priced. The 12 comes from the stored term, not from a blank-term default.'
);

$missingChoice = resolveApplicationLoanTerm('', '');
$missingEligibility = ltEligibility('');
$missingBlocked = loanEligibilityBlocksWizardProgress($missingEligibility);
$missingAgreement = null;
try {
    generateLoanAgreementText([
        'approved_loan_term' => '',
        'loan_term' => '',
        'approved_loan_amount' => 10000,
        'borrower_name' => 'Synthetic Borrower',
        'payment_frequency' => 'Monthly',
    ]);
    $missingAgreement = 'generated';
} catch (InvalidArgumentException $e) {
    $missingAgreement = $e->getMessage();
}
ltRecord(
    $results,
    'LT-02',
    'Blank approved and requested terms are invalid. Eligibility lists Loan term. Agreement generation throws the term message.',
    [
        'choice_valid' => $missingChoice['valid'],
        'choice_months' => $missingChoice['months'],
        'message' => $missingChoice['message'],
        'eligibility_status' => $missingEligibility['status'] ?? null,
        'missing_fields' => $missingEligibility['missing_fields'] ?? null,
        'wizard_message' => $missingBlocked,
        'agreement' => $missingAgreement,
    ],
    !$missingChoice['valid']
        && $missingChoice['months'] === 0
        && $missingChoice['message'] === $message
        && ($missingEligibility['status'] ?? '') === 'Not Eligible'
        && in_array('Loan term', $missingEligibility['missing_fields'] ?? [], true)
        && is_string($missingBlocked)
        && strpos($missingBlocked, 'Loan term') !== false
        && $missingAgreement === $message,
    'A missing term stays missing. The agreement helper does not substitute 12 months.'
);

$zeroChoice = resolveApplicationLoanTerm('0 months', '12 Months');
$zeroEligibility = ltEligibility('0');
ltRecord(
    $results,
    'LT-03',
    'An explicit zero-month term is invalid and does not fall back to a requested 12-month term.',
    [
        'label' => $zeroChoice['label'],
        'months' => $zeroChoice['months'],
        'valid' => $zeroChoice['valid'],
        'message' => $zeroChoice['message'],
        'eligibility_status' => $zeroEligibility['status'] ?? null,
        'missing_fields' => $zeroEligibility['missing_fields'] ?? null,
    ],
    !$zeroChoice['valid']
        && $zeroChoice['months'] === 0
        && $zeroChoice['label'] === '0 months'
        && $zeroChoice['message'] === $message
        && ($zeroEligibility['status'] ?? '') === 'Not Eligible'
        && in_array('Loan term', $zeroEligibility['missing_fields'] ?? [], true),
    'Zero is an entered term. The requested 12-month value is not used in its place.'
);

$negativeChoice = resolveApplicationLoanTerm('-3', '12 Months');
$negativeLabel = resolveApplicationLoanTerm('-3 months', '');
$negativeEligibility = ltEligibility('-3');
ltRecord(
    $results,
    'LT-04',
    'A negative term is invalid, including a label such as "-3 months".',
    [
        'numeric_months' => $negativeChoice['months'],
        'numeric_valid' => $negativeChoice['valid'],
        'label_months' => $negativeLabel['months'],
        'label_valid' => $negativeLabel['valid'],
        'eligibility_status' => $negativeEligibility['status'] ?? null,
        'missing_fields' => $negativeEligibility['missing_fields'] ?? null,
    ],
    !$negativeChoice['valid']
        && $negativeChoice['months'] === -3
        && !$negativeLabel['valid']
        && $negativeLabel['months'] === -3
        && ($negativeEligibility['status'] ?? '') === 'Not Eligible'
        && in_array('Loan term', $negativeEligibility['missing_fields'] ?? [], true),
    'Negative terms are rejected. They are not clamped to 1 or 12 months.'
);

$different = resolveApplicationLoanTerm('6 Months', '24 Months');
$differentInterest = $different['valid']
    ? calculateLoanInterestBreakdown(10000, getStandardMonthlyInterestRatePercent(), $different['months'])
    : null;
ltRecord(
    $results,
    'LT-05',
    'Approved "6 Months" wins over requested "24 Months". Payable on 10,000.00 is 13,000.00.',
    [
        'label' => $different['label'],
        'months' => $different['months'],
        'total_interest' => $differentInterest['total_interest'] ?? null,
        'total_payable' => $differentInterest['total_payable'] ?? null,
    ],
    $different['valid']
        && $different['label'] === '6 Months'
        && $different['months'] === 6
        && ($differentInterest['total_interest'] ?? null) === 3000.0
        && ($differentInterest['total_payable'] ?? null) === 13000.0,
    'The approved term is priced. The requested term does not replace it, and 24 months would have produced 22,000.00 payable.'
);

$agreement = generateLoanAgreementText([
    'approved_loan_term' => '6 Months',
    'loan_term' => '24 Months',
    'approved_loan_amount' => 10000,
    'loan_amount' => 10000,
    'borrower_name' => 'Synthetic Borrower',
    'payment_frequency' => 'Monthly',
    'loan_purpose' => 'Synthetic check',
]);
$agreementAgain = generateLoanAgreementText([
    'approved_loan_term' => '6 Months',
    'loan_term' => '24 Months',
    'approved_loan_amount' => 10000,
    'loan_amount' => 10000,
    'borrower_name' => 'Synthetic Borrower',
    'payment_frequency' => 'Monthly',
    'loan_purpose' => 'Synthetic check',
]);
ltRecord(
    $results,
    'LT-06',
    'Agreement text shows "6 Months", regular installment 2,166.66, final installment 2,166.70, and does not show the requested 24-month term.',
    [
        'contains_approved_term' => strpos($agreement, 'Loan Term: 6 Months') !== false,
        'contains_requested_term' => strpos($agreement, '24 Months') !== false,
        'contains_installment' => strpos($agreement, 'Installment Amount: ₱2,166.66') !== false,
        'contains_final_installment' => strpos($agreement, 'Final Installment Amount: ₱2,166.70') !== false,
        'stable' => $agreement === $agreementAgain,
    ],
    strpos($agreement, 'Loan Term: 6 Months') !== false
        && strpos($agreement, '24 Months') === false
        && strpos($agreement, 'Installment Amount: ₱2,166.66') !== false
        && strpos($agreement, 'Final Installment Amount: ₱2,166.70') !== false
        && $agreement === $agreementAgain,
    'The agreement is built from the approved term. The installment lines use the shared centavo split, which sums to 13,000.00. Repeating the call does not change the text and does not write a row.'
);

$releaseInvalid = resolveApplicationLoanTerm('', '');
$releaseText = resolveApplicationLoanTerm('Select', '12 Months');
$releaseThrew = false;
$releaseException = '';
try {
    buildPaymentSchedule(10000, 5, $releaseInvalid['months'], 'Monthly', '2026-01-15', 1);
} catch (InvalidArgumentException $e) {
    $releaseThrew = true;
    $releaseException = $e->getMessage();
}
$releaseSourceBlocks = strpos($releaseSource, 'resolveApplicationLoanTerm') !== false
    && strpos($releaseSource, 'This application has no valid loan term.') !== false
    && strpos($releaseSource, "'12 months'") === false;
ltRecord(
    $results,
    'LT-07',
    'A blank or non-numeric term does not release. The schedule function rejects the parsed term, and the release page has no 12-month substitute.',
    [
        'blank_valid' => $releaseInvalid['valid'],
        'blank_months' => $releaseInvalid['months'],
        'select_valid' => $releaseText['valid'],
        'select_months' => $releaseText['months'],
        'schedule_rejected' => $releaseThrew,
        'schedule_message' => $releaseException,
        'release_source_blocks_default' => $releaseSourceBlocks,
    ],
    !$releaseInvalid['valid']
        && !$releaseText['valid']
        && $releaseText['months'] === 0
        && $releaseThrew
        && $releaseException === 'Loan term must be at least 1 month.'
        && $releaseSourceBlocks,
    'Release uses the same term choice as approval. "Select" is not a term, and the page does not store 12 months for it.'
);

$historicalMonths = resolveLoanTermMonths('', '2026-01-15', '2027-01-15');
$historicalLabel = formatLoanTermLabel('12 months', '2026-01-15', '2027-01-15');
$historicalInterest = calculateLoanInterestBreakdown(10000, 5, 12);
$datedBlankAgreement = null;
try {
    generateLoanAgreementText([
        'approved_loan_term' => '',
        'loan_term' => '',
        'approved_loan_amount' => 10000,
        'borrower_name' => 'Synthetic Borrower',
        'payment_frequency' => 'Monthly',
        'approved_first_payment_date' => '2026-01-15',
        'approved_due_date' => '2027-01-15',
    ]);
    $datedBlankAgreement = 'generated';
} catch (InvalidArgumentException $e) {
    $datedBlankAgreement = $e->getMessage();
}
$storedStyle = generateLoanAgreementText([
    'approved_loan_term' => '12 months',
    'loan_term' => '12 months',
    'approved_loan_amount' => 10000,
    'borrower_name' => 'Synthetic Borrower',
    'payment_frequency' => 'Monthly',
    'loan_purpose' => 'Existing valid term',
]);
ltRecord(
    $results,
    'LT-08',
    'A stored 12-month term still prices 16,000.00 and prints that term. A blank term with dates is not turned into an agreement. Released-loan display may still estimate months from dates.',
    [
        'display_months_from_dates' => $historicalMonths,
        'display_label' => $historicalLabel,
        'valid_payable' => $historicalInterest['total_payable'],
        'blank_with_dates' => $datedBlankAgreement,
        'stored_term_line' => strpos($storedStyle, 'Loan Term: 12 months') !== false,
        'stored_installment' => strpos($storedStyle, 'Installment Amount: ₱1,333.33') !== false,
    ],
    $historicalLabel === '12 months'
        && $historicalInterest['total_payable'] === 16000.0
        && $datedBlankAgreement === $message
        && strpos($storedStyle, 'Loan Term: 12 months') !== false
        && strpos($storedStyle, 'Installment Amount: ₱1,333.33') !== false
        && $historicalMonths === 12,
    'Existing valid terms are unchanged. Date-based display remains only in resolveLoanTermMonths(), which agreement generation does not use.'
);

$after = ltCounts($conn);
$unchanged = $before === $after;

$pass = 0;
$fail = 0;
foreach ($results as $result) {
    if ($result['status'] === 'PASS') {
        $pass++;
    } else {
        $fail++;
    }
}

$payload = [
    'executed_at' => date('c'),
    'php_version' => PHP_VERSION,
    'database' => $connected,
    'writes' => 'none',
    'isolated_table_counts_unchanged' => $unchanged,
    'counts_before' => $before,
    'counts_after' => $after,
    'counts' => ['executed' => count($results), 'passed' => $pass, 'failed' => $fail],
    'results' => $results,
];

$evidenceDir = dirname(__DIR__) . '/evidence';
if (!is_dir($evidenceDir)) {
    mkdir($evidenceDir, 0777, true);
}
file_put_contents($evidenceDir . '/fc_term_validation_results.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo json_encode([
    'executed' => count($results),
    'passed' => $pass,
    'failed' => $fail,
    'isolated_table_counts_unchanged' => $unchanged,
    'database' => $connected,
], JSON_PRETTY_PRINT) . PHP_EOL;

if (!$unchanged || $fail > 0) {
    exit(1);
}
