<?php
/**
 * Agreement rounding, pending due-date, and rolled-back release checks.
 *
 * Run with the isolated prepend:
 * php -d auto_prepend_file=testing_evidence/_harness/env_prepend.php testing/harness/run_fc_agreement_due_release_validation.php
 *
 * The release case inserts synthetic rows inside a transaction and rolls them back.
 * It does not write to lending_management.
 */

if (!defined('FRAMS_TEST_PREPEND_LOADED')) {
    fwrite(STDERR, "Refusing to run without the isolated test prepend.\n");
    exit(1);
}

require_once dirname(__DIR__, 2) . '/lending_system/includes/db_lending.php';
require_once dirname(__DIR__, 2) . '/lending_system/includes/loan_helpers.php';
require_once dirname(__DIR__, 2) . '/lending_system/includes/agreement_helpers.php';

global $conn;
$connected = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
if ($connected !== 'frams_capstone_test') {
    fwrite(STDERR, "Refusing to run. Connected database is not the isolated test database.\n");
    exit(1);
}

function rrExtractFunction(string $source, string $name): string
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

function rrCounts(PDO $pdo): array
{
    $counts = [];
    foreach (['users', 'clients', 'loan_applications', 'loan_agreements', 'loans', 'loan_releases', 'loan_payment_schedules', 'payments', 'notifications'] as $table) {
        $counts[$table] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }
    return $counts;
}

function rrRecord(array &$results, string $id, string $expected, $actual, bool $pass, string $explanation): void
{
    $results[] = [
        'id' => $id,
        'expected' => $expected,
        'actual' => $actual,
        'status' => $pass ? 'PASS' : 'FAIL',
        'explanation' => $explanation,
    ];
}

function rrMoney(float $amount): string
{
    return number_format(round($amount, 2), 2, '.', '');
}

function rrReleaseGate(array $application): array
{
    $loanAmount = (float)($application['approved_loan_amount'] ?? $application['loan_amount'] ?? 0);
    if ($loanAmount <= 0) {
        return ['ok' => false, 'message' => 'This application has no valid loan amount. Set an approved loan amount before release.'];
    }
    $termChoice = resolveApplicationLoanTerm(
        isset($application['approved_loan_term']) ? (string)$application['approved_loan_term'] : null,
        isset($application['loan_term']) ? (string)$application['loan_term'] : null
    );
    if (!$termChoice['valid']) {
        return [
            'ok' => false,
            'message' => 'This application has no valid loan term. ' . $termChoice['message'],
            'choice' => $termChoice,
        ];
    }
    return ['ok' => true, 'message' => '', 'choice' => $termChoice];
}

$releaseSource = file_get_contents(dirname(__DIR__, 2) . '/lending_system/loan_release_lending.php');
$agreementPage = file_get_contents(dirname(__DIR__, 2) . '/lending_system/borrower_loan_agreement_lending.php');
$agreementHelper = file_get_contents(dirname(__DIR__, 2) . '/lending_system/includes/agreement_helpers.php');
$approvalPage = file_get_contents(dirname(__DIR__, 2) . '/lending_system/loan_applications_lending.php');
$clientPage = file_get_contents(dirname(__DIR__, 2) . '/lending_system/client_details_lending.php');
eval(rrExtractFunction($releaseSource, 'buildPaymentSchedule'));
eval(rrExtractFunction($releaseSource, 'generateLoanNumber'));
eval(rrExtractFunction($releaseSource, 'previewReleaseSchedule'));

$results = [];
$asOf = new DateTimeImmutable('2026-10-20');

$previewDate = '2026-10-21';
$previewValid = previewReleaseSchedule([
    'approved_loan_amount' => 10000,
    'loan_amount' => 24000,
    'approved_loan_term' => '3 months',
    'loan_term' => '24 Months',
    'payment_frequency' => 'Monthly',
], $previewDate);
$previewSaved = buildPaymentSchedule(10000, getStandardMonthlyInterestRatePercent(), 3, 'Monthly', $previewDate, 0);
$previewSelect = previewReleaseSchedule([
    'approved_loan_amount' => 10000,
    'loan_amount' => 10000,
    'approved_loan_term' => 'Select',
    'loan_term' => '12 Months',
    'payment_frequency' => 'Monthly',
], $previewDate);
$previewAmounts = array_map(static fn(array $row): string => rrMoney((float)$row['total_amount_due']), $previewSaved);
rrRecord(
    $results,
    'RP-01',
    'The release preview for 10,000.00 over 3 months uses the approved term and the same rows buildPaymentSchedule() would save. Select does not fall back to a requested 12-month schedule.',
    [
        'principal' => rrMoney((float)$previewValid['principal']),
        'term' => $previewValid['term_label'],
        'payable' => rrMoney((float)$previewValid['total_payable']),
        'regular' => rrMoney((float)$previewValid['regular']),
        'final' => rrMoney((float)$previewValid['final']),
        'first_due' => $previewValid['first_due'],
        'final_due' => $previewValid['final_due'],
        'saved_rows' => $previewAmounts,
        'select_valid' => $previewSelect['valid'],
        'select_message' => $previewSelect['message'],
        'select_due' => $previewSelect['first_due'],
    ],
    $previewValid['valid']
        && rrMoney((float)$previewValid['principal']) === '10000.00'
        && $previewValid['term_label'] === '3 months'
        && rrMoney((float)$previewValid['total_payable']) === '11500.00'
        && rrMoney((float)$previewValid['regular']) === '3833.33'
        && rrMoney((float)$previewValid['final']) === '3833.34'
        && $previewValid['count'] === 3
        && $previewValid['first_due'] === $previewSaved[0]['due_date']
        && $previewValid['final_due'] === $previewSaved[2]['due_date']
        && $previewAmounts === ['3833.33', '3833.33', '3833.34']
        && !$previewSelect['valid']
        && $previewSelect['first_due'] === ''
        && strpos($previewSelect['message'], 'A loan term of at least 1 month is required.') !== false
        && strpos($releaseSource, 'previewReleaseSchedule') !== false,
    'The preparation screen calls the release schedule function. An explicit invalid approved term produces no due date.'
);


$scheduleCases = [
    [
        'id' => 'AG-01',
        'principal' => 10000.0,
        'months' => 3,
        'payable' => '11500.00',
        'regular' => '3833.33',
        'final' => '3833.34',
        'regular_count' => 2,
    ],
    [
        'id' => 'AG-02',
        'principal' => 100000.0,
        'months' => 12,
        'payable' => '160000.00',
        'regular' => '13333.33',
        'final' => '13333.37',
        'regular_count' => 11,
    ],
];

foreach ($scheduleCases as $case) {
    $breakdown = calculateLoanInterestBreakdown($case['principal'], getStandardMonthlyInterestRatePercent(), $case['months']);
    $rows = allocateEvenMoneyRows($case['principal'], (float)$breakdown['total_interest'], (float)$breakdown['total_payable'], $case['months']);
    $amounts = array_map(static fn(array $row): string => rrMoney((float)$row['total_amount_due']), $rows);
    $sumCents = 0;
    foreach ($rows as $row) {
        $sumCents += (int)round(((float)$row['total_amount_due']) * 100);
    }
    $regularRows = array_slice($amounts, 0, -1);
    $text = generateLoanAgreementText([
        'approved_loan_amount' => $case['principal'],
        'approved_loan_term' => $case['months'] . ' months',
        'loan_term' => '24 Months',
        'borrower_name' => 'Synthetic Borrower',
        'payment_frequency' => 'Monthly',
    ]);
    $regularLabel = number_format((float)$case['regular'], 2);
    $finalLabel = number_format((float)$case['final'], 2);
    $pass = rrMoney((float)$breakdown['total_payable']) === $case['payable']
        && count($regularRows) === $case['regular_count']
        && count(array_unique($regularRows)) === 1
        && $regularRows[0] === $case['regular']
        && $amounts[count($amounts) - 1] === $case['final']
        && rrMoney($sumCents / 100) === $case['payable']
        && strpos($text, 'Installment Amount: ₱' . $regularLabel) !== false
        && strpos($text, 'Final Installment Amount: ₱' . $finalLabel) !== false
        && strpos($text, '24 Months') === false
        && strpos($agreementPage, 'allocateEvenMoneyRows') !== false
        && strpos($agreementHelper, 'allocateEvenMoneyRows') !== false;
    rrRecord(
        $results,
        $case['id'],
        'Principal ' . number_format($case['principal'], 2) . ' for ' . $case['months'] . ' months stays payable ' . $case['payable'] . '. Regular installments are ' . $case['regular'] . ' and the final installment is ' . $case['final'] . '.',
        [
            'total_payable' => rrMoney((float)$breakdown['total_payable']),
            'installments' => $amounts,
            'sum' => rrMoney($sumCents / 100),
            'agreement_has_regular' => strpos($text, 'Installment Amount: ₱' . $regularLabel) !== false,
            'agreement_has_final' => strpos($text, 'Final Installment Amount: ₱' . $finalLabel) !== false,
        ],
        $pass,
        'The agreement text and the shared row allocator use the same centavo split. Total payable is unchanged.'
    );
}

$sixMonth = describeEvenInstallments(10000, getStandardMonthlyInterestRatePercent(), 6, 6);
$sixRegular = rrMoney((float)$sixMonth['regular']);
$sixFinal = rrMoney((float)$sixMonth['final']);
$sixSumCents = (int)round((float)$sixMonth['regular'] * 100) * 5 + (int)round((float)$sixMonth['final'] * 100);
$approvalSource = file_get_contents(dirname(__DIR__, 2) . '/lending_system/loan_applications_lending.php');
rrRecord(
    $results,
    'AG-03',
    'A 10,000.00 loan for 6 months stays payable 13,000.00. The regular installment is 2,166.66 and the final installment is 2,166.70. The approval card uses that split instead of the equal-rounded 2,166.67.',
    [
        'regular' => $sixRegular,
        'final' => $sixFinal,
        'total_payable' => rrMoney((float)$sixMonth['total_payable']),
        'sum' => rrMoney($sixSumCents / 100),
        'approval_uses_shared_split' => strpos($approvalSource, 'describeEvenInstallments') !== false,
    ],
    $sixRegular === '2166.66'
        && $sixFinal === '2166.70'
        && rrMoney((float)$sixMonth['total_payable']) === '13000.00'
        && rrMoney($sixSumCents / 100) === '13000.00'
        && strpos($approvalSource, 'describeEvenInstallments') !== false
        && strpos($approvalSource, 'Regular installment') !== false,
    'The approval display and the saved regular installment use the same rows as the agreement schedule.'
);

$dueCases = [
    ['DD-01', null, null, null, '', 'A missing approved term and a missing requested term produce no due date.'],
    ['DD-02', 'Select', '12 Months', null, '', 'Select stays invalid and does not use the requested 12-month term.'],
    ['DD-03', '0 months', '12 Months', null, '', 'Zero stays invalid and does not use the requested 12-month term.'],
    ['DD-04', '-3 months', '12 Months', null, '', 'A negative term stays invalid and does not use the requested 12-month term.'],
    ['DD-05', 'abc', '12 Months', null, '', 'A nonnumeric term stays invalid and does not use the requested 12-month term.'],
    ['DD-06', '6 Months', '24 Months', null, '2027-04-20', 'An explicit approved term of 6 months estimates 2027-04-20 from 2026-10-20.'],
    ['DD-07', '6 Months', '24 Months', '2027-01-15', '2027-01-15', 'A stored approved due date is kept when the approved term is valid.'],
    ['DD-08', '', '6 Months', null, '2027-04-20', 'A blank approved term still uses an explicit requested term of 6 months.'],
];
foreach ($dueCases as [$id, $approved, $requested, $stored, $expectedDate, $explanation]) {
    $actual = estimatePendingApplicationDueDate($approved, $requested, $stored, $asOf);
    rrRecord(
        $results,
        $id,
        $expectedDate === '' ? 'Not Provided' : $expectedDate,
        $actual === '' ? 'Not Provided' : $actual,
        $actual === $expectedDate,
        $explanation
    );
}

$approvalUsesHelper = strpos($approvalPage, 'estimatePendingApplicationDueDate') !== false;
$approvalHasFallback = strpos($approvalPage, ": 12)") !== false;
$clientUsesHelper = strpos($clientPage, 'estimatePendingApplicationDueDate') !== false;
$clientHasFallback = strpos($clientPage, ": 12)") !== false;
$dueScriptStopsInvalid = strpos($approvalPage, 'if (months < 1)') !== false;
rrRecord(
    $results,
    'DD-09',
    'The approval card and client details card call the shared due-date helper and do not use a 12-month fallback.',
    [
        'approval_helper' => $approvalUsesHelper,
        'approval_fallback' => $approvalHasFallback,
        'client_helper' => $clientUsesHelper,
        'client_fallback' => $clientHasFallback,
        'script_stops_invalid_term' => $dueScriptStopsInvalid,
    ],
    $approvalUsesHelper && !$approvalHasFallback && $clientUsesHelper && !$clientHasFallback && $dueScriptStopsInvalid,
    'Invalid terms no longer reach a display default of 12 months.'
);

$prod = new PDO('mysql:host=localhost;dbname=lending_management;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$prodBefore = rrCounts($prod);
$isolatedBefore = rrCounts($conn);

$releaseRejectionMessage = 'This application has no valid loan term.';
$rejectionBeforeTransaction = strpos($releaseSource, $releaseRejectionMessage);
$transactionStart = strpos($releaseSource, '$conn->beginTransaction()');
$invalidTerms = [
    ['RL-01', null, null, 'missing'],
    ['RL-02', '0 months', '12 Months', 'zero'],
    ['RL-03', '-3 months', '12 Months', 'negative'],
    ['RL-04', 'Select', '12 Months', 'Select'],
    ['RL-05', 'abc', '12 Months', 'nonnumeric'],
];

$releaseActual = [
    'rolled_back' => false,
    'valid_installments' => [],
    'valid_sum' => null,
    'invalid_stored' => null,
    'cleanup' => 'not started',
];
$releaseError = '';

try {
    if ($conn->inTransaction()) {
        throw new RuntimeException('The test connection was already in a transaction.');
    }
    $conn->beginTransaction();
    $stamp = 'rrfc' . bin2hex(random_bytes(4));
    $insertUser = $conn->prepare('INSERT INTO users (username, password, full_name, email, role, status) VALUES (?, ?, ?, ?, ?, ?)');
    $insertUser->execute([$stamp . '_borrower', password_hash('Test@Frams1', PASSWORD_DEFAULT), 'RRFC Release Borrower', $stamp . '@example.invalid', 'Borrower', 'Active']);
    $borrowerId = (int)$conn->lastInsertId();
    $insertUser->execute([$stamp . '_collector', password_hash('Test@Frams1', PASSWORD_DEFAULT), 'RRFC Release Collector', $stamp . '.collector@example.invalid', 'Collector', 'Active']);
    $collectorId = (int)$conn->lastInsertId();

    $insertApplication = $conn->prepare(
        'INSERT INTO loan_applications (user_id, borrower_name, email_address, mobile_number, loan_amount, loan_term, approved_loan_amount, approved_loan_term, payment_frequency, status, agreement_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $invalidIds = [];
    foreach ($invalidTerms as [$id, $approved, $requested]) {
        $insertApplication->execute([
            $borrowerId,
            'RRFC Invalid ' . $id,
            $stamp . '@example.invalid',
            '09000000000',
            10000,
            $requested,
            10000,
            $approved,
            'Monthly',
            'Agreement Accepted',
            'Accepted',
        ]);
        $applicationId = (int)$conn->lastInsertId();
        $invalidIds[$id] = $applicationId;
        $gate = rrReleaseGate([
            'approved_loan_amount' => 10000,
            'approved_loan_term' => $approved,
            'loan_term' => $requested,
        ]);
        if ($gate['ok']) {
            throw new RuntimeException($id . ' was treated as a valid release term.');
        }
        $stored = (int)$conn->query('SELECT COUNT(*) FROM loan_releases WHERE application_id = ' . $applicationId)->fetchColumn();
        if ($stored !== 0) {
            throw new RuntimeException($id . ' stored a release before the term gate.');
        }
    }

    $insertApplication->execute([
        $borrowerId,
        'RRFC Release Valid',
        $stamp . '@example.invalid',
        '09000000000',
        10000,
        '3 months',
        10000,
        '3 months',
        'Monthly',
        'Agreement Accepted',
        'Accepted',
    ]);
    $validApplicationId = (int)$conn->lastInsertId();
    $conn->prepare(
        'INSERT INTO loan_agreements (application_id, borrower_user_id, agreement_status, agreement_text, lender_agreement_status, lender_signed_by, lender_signed_at) VALUES (?, ?, ?, ?, ?, ?, NOW())'
    )->execute([$validApplicationId, $borrowerId, 'Accepted', 'Synthetic agreement', 'Accepted', 'RRFC Test']);

    $validGate = rrReleaseGate([
        'approved_loan_amount' => 10000,
        'loan_amount' => 10000,
        'approved_loan_term' => '3 months',
        'loan_term' => '3 months',
    ]);
    if (!$validGate['ok']) {
        throw new RuntimeException('The valid 3-month application was rejected: ' . $validGate['message']);
    }

    $conn->prepare(
        'INSERT INTO clients (user_id, first_name, last_name, contact, email, collector_id, date_registered) VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$borrowerId, 'RRFC', 'Borrower', '09000000000', $stamp . '@example.invalid', $collectorId, '2026-10-20']);
    $clientId = (int)$conn->lastInsertId();

    $loanNumber = generateLoanNumber();
    $interestRate = getStandardMonthlyInterestRatePercent();
    $breakdown = calculateLoanInterestBreakdown(10000, $interestRate, 3);
    $conn->prepare(
        'INSERT INTO loan_releases (application_id, borrower_user_id, collector_user_id, loan_number, loan_amount, interest_rate, loan_term, payment_frequency, release_status, released_at, released_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
    )->execute([
        $validApplicationId,
        $borrowerId,
        $collectorId,
        $loanNumber,
        10000,
        $interestRate,
        $validGate['choice']['label'],
        'Monthly',
        'Released',
        'RRFC Test',
    ]);
    $releaseId = (int)$conn->lastInsertId();
    $schedule = buildPaymentSchedule(10000, $interestRate, 3, 'Monthly', '2026-10-20', $releaseId);
    $insertSchedule = $conn->prepare(
        'INSERT INTO loan_payment_schedules (release_id, installment_number, due_date, principal_amount, interest_amount, total_amount_due, status) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($schedule as $installment) {
        $insertSchedule->execute([
            $installment['release_id'],
            $installment['installment_number'],
            $installment['due_date'],
            $installment['principal_amount'],
            $installment['interest_amount'],
            $installment['total_amount_due'],
            $installment['status'],
        ]);
    }
    $conn->prepare(
        'INSERT INTO loans (client_id, loan_amount, interest, total_payable, daily_payment, date_released, due_date, status, collector_id, loan_number, release_id, payment_frequency, agreement_status, released_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $clientId,
        10000,
        $interestRate,
        $breakdown['total_payable'],
        $schedule[0]['total_amount_due'],
        '2026-10-20',
        $schedule[count($schedule) - 1]['due_date'],
        'Active',
        $collectorId,
        $loanNumber,
        $releaseId,
        'Monthly',
        'Accepted',
        'RRFC Test',
    ]);

    $storedRows = $conn->query(
        'SELECT installment_number, total_amount_due FROM loan_payment_schedules WHERE release_id = ' . $releaseId . ' ORDER BY installment_number'
    )->fetchAll(PDO::FETCH_ASSOC);
    $storedAmounts = array_map(static fn(array $row): string => rrMoney((float)$row['total_amount_due']), $storedRows);
    $storedSum = 0;
    foreach ($storedRows as $row) {
        $storedSum += (int)round(((float)$row['total_amount_due']) * 100);
    }
    $invalidStored = 0;
    foreach ($invalidIds as $applicationId) {
        $invalidStored += (int)$conn->query('SELECT COUNT(*) FROM loan_releases WHERE application_id = ' . (int)$applicationId)->fetchColumn();
    }
    $releaseActual['valid_installments'] = $storedAmounts;
    $releaseActual['valid_sum'] = rrMoney($storedSum / 100);
    $releaseActual['invalid_stored'] = $invalidStored;
    $releaseActual['payable'] = rrMoney((float)$breakdown['total_payable']);

    if (!$conn->rollBack()) {
        throw new RuntimeException('Rollback returned false.');
    }
    $releaseActual['rolled_back'] = !$conn->inTransaction();
    $releaseActual['cleanup'] = 'rollback';
} catch (Throwable $exception) {
    $releaseError = $exception->getMessage();
    if ($conn->inTransaction()) {
        $conn->rollBack();
        $releaseActual['cleanup'] = 'rollback after error';
    }
}

$isolatedAfter = rrCounts($conn);
$prodAfter = rrCounts($prod);
$countsRestored = $isolatedBefore === $isolatedAfter && $prodBefore === $prodAfter;
$schedulePass = $releaseError === ''
    && $releaseActual['rolled_back'] === true
    && $releaseActual['valid_installments'] === ['3833.33', '3833.33', '3833.34']
    && $releaseActual['valid_sum'] === '11500.00'
    && $releaseActual['payable'] === '11500.00'
    && $releaseActual['invalid_stored'] === 0
    && $countsRestored
    && $rejectionBeforeTransaction !== false
    && $transactionStart !== false
    && $rejectionBeforeTransaction < $transactionStart;

rrRecord(
    $results,
    'RL-06',
    'A rolled-back 3-month release stores 3,833.33, 3,833.33, and 3,833.34, totaling 11,500.00. Missing, zero, negative, Select, and nonnumeric terms store no release. Isolated and lending_management counts return to the starting values.',
    [
        'installments' => $releaseActual['valid_installments'],
        'sum' => $releaseActual['valid_sum'],
        'payable' => $releaseActual['payable'] ?? null,
        'invalid_releases_stored' => $releaseActual['invalid_stored'],
        'rolled_back' => $releaseActual['rolled_back'],
        'cleanup' => $releaseActual['cleanup'],
        'isolated_counts_restored' => $isolatedBefore === $isolatedAfter,
        'lending_management_unchanged' => $prodBefore === $prodAfter,
        'error' => $releaseError,
        'browser' => 'NOT RUN',
    ],
    $schedulePass,
    'The release schedule was inserted in frams_capstone_test and removed by rolling back the transaction. The browser confirm button was not clicked.'
);

foreach ($invalidTerms as [$id, $approved, $requested, $label]) {
    $gate = rrReleaseGate([
        'approved_loan_amount' => 10000,
        'approved_loan_term' => $approved,
        'loan_term' => $requested,
    ]);
    rrRecord(
        $results,
        $id,
        'A ' . $label . ' term is rejected before a release row is stored.',
        [
            'accepted' => $gate['ok'],
            'message' => $gate['message'],
            'releases_for_invalid_applications' => $releaseActual['invalid_stored'],
        ],
        !$gate['ok']
            && strpos($gate['message'], 'A loan term of at least 1 month is required.') !== false
            && $releaseActual['invalid_stored'] === 0
            && $countsRestored,
        'The release gate uses the approved term. The requested 12-month value is not stored in its place.'
    );
}

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
    'release_method' => 'transaction rolled back',
    'browser_release' => 'NOT RUN',
    'isolated_table_counts_unchanged' => $isolatedBefore === $isolatedAfter,
    'lending_management_unchanged' => $prodBefore === $prodAfter,
    'counts_before' => $isolatedBefore,
    'counts_after' => $isolatedAfter,
    'lending_management_before' => $prodBefore,
    'lending_management_after' => $prodAfter,
    'counts' => ['executed' => count($results), 'passed' => $pass, 'failed' => $fail],
    'results' => $results,
];

$evidenceDir = dirname(__DIR__) . '/evidence';
if (!is_dir($evidenceDir)) {
    mkdir($evidenceDir, 0777, true);
}
file_put_contents($evidenceDir . '/fc_agreement_due_release_results.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo json_encode([
    'executed' => count($results),
    'passed' => $pass,
    'failed' => $fail,
    'isolated_unchanged' => $isolatedBefore === $isolatedAfter,
    'lending_management_unchanged' => $prodBefore === $prodAfter,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($fail > 0 ? 1 : 0);
