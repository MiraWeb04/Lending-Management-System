<?php
/**
 * CLI unit, integration, and local PayMongo handler tests.
 * Must be started with testing_evidence/_harness/env_prepend.php.
 */
if (!defined('FRAMS_TEST_PREPEND_LOADED') || lendingEnv('DB_NAME') !== 'frams_capstone_test') {
    fwrite(STDERR, "Refusing to run outside the isolated test database.\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
$app = $root . '/lending_system';
require_once $app . '/includes/loan_helpers.php';
require_once $app . '/includes/loan_eligibility_helpers.php';
require_once $app . '/includes/auth_lending.php';
require_once $app . '/includes/paymongo_qrph.php';
require_once $app . '/includes/paymongo_qrph_webhook.php';
require_once $app . '/includes/document_download_helpers.php';

if ((string)$conn->query('SELECT DATABASE()')->fetchColumn() !== 'frams_capstone_test') {
    fwrite(STDERR, "Connected database is not the isolated test database.\n");
    exit(1);
}

$manifest = json_decode((string)file_get_contents(__DIR__ . '/seed_manifest.json'), true);
$loanA = (int)$manifest['loans']['a']['loan_id'];
$loanB = (int)$manifest['loans']['b']['loan_id'];
$clientA = (int)$manifest['clients']['a'];
$clientB = (int)$manifest['clients']['b'];
$collectorA = (int)$manifest['users']['frams_test_collector'];
$collectorB = (int)$manifest['users']['frams_test_collector_b'];
$adminId = (int)$manifest['users']['frams_test_admin'];
$borrowerA = (int)$manifest['users']['frams_test_borrower'];
$borrowerB = (int)$manifest['users']['frams_test_borrower_b'];

$cases = [];
$findings = [];

function framsRedact(string $text): string
{
    $text = preg_replace('/(sk|pk)_(test|live)_[A-Za-z0-9_]+/', '[redacted-key]', $text) ?? $text;
    $text = preg_replace('/whsk_[A-Za-z0-9_]+/', '[redacted-webhook-secret]', $text) ?? $text;
    return $text;
}

function framsRecord(
    string $id,
    string $category,
    string $scenario,
    string $objective,
    string $preconditions,
    string $steps,
    string $data,
    string $expected,
    string $actual,
    string $status,
    string $evidence,
    string $remarks,
    string $source = 'executed PHP assertion'
): void {
    global $cases;
    $cases[] = [
        'id' => $id,
        'category' => $category,
        'scenario' => $scenario,
        'objective' => $objective,
        'preconditions' => $preconditions,
        'steps' => $steps,
        'test_data' => $data,
        'expected' => $expected,
        'actual' => framsRedact($actual),
        'status' => $status,
        'evidence' => $evidence,
        'remarks' => framsRedact($remarks),
        'source' => $source,
    ];
    echo $id . ' ' . $status . ' ' . $scenario . PHP_EOL;
}

function framsFinding(array $finding): void
{
    global $findings;
    $findings[] = $finding;
}

function framsNear(float $actual, float $expected): bool
{
    return abs($actual - $expected) < 0.001;
}

$evidenceLog = 'logs/cli_test_execution.log';

try {
    $rate = getStandardMonthlyInterestRatePercent();
    framsRecord('UT-001', 'Unit Testing', 'Standard monthly interest rate', 'Confirm the configured flat monthly rate.', 'Helpers loaded.', 'Call getStandardMonthlyInterestRatePercent().', 'none', '5.0 percent', (string)$rate, $rate === 5.0 ? 'PASS' : 'FAIL', $evidenceLog, '');

    $b = calculateLoanInterestBreakdown(10000, 5, 12);
    $ok = framsNear($b['monthly_interest'], 500) && framsNear($b['total_interest'], 6000) && framsNear($b['total_payable'], 16000);
    framsRecord('UT-002', 'Unit Testing', 'Twelve-month interest breakdown', 'Verify flat monthly interest on original principal.', 'Pure function.', 'calculateLoanInterestBreakdown(10000, 5, 12).', 'principal 10000, rate 5, term 12', 'monthly 500.00, interest 6000.00, payable 16000.00', json_encode($b), $ok ? 'PASS' : 'FAIL', $evidenceLog, 'Formula: monthly = principal x rate/100; total interest = monthly x months; payable = principal + total interest.');

    $b = calculateLoanInterestBreakdown(10000, 5, 3);
    $ok = framsNear($b['monthly_interest'], 500) && framsNear($b['total_interest'], 1500) && framsNear($b['total_payable'], 11500);
    framsRecord('UT-003', 'Unit Testing', 'Three-month interest breakdown', 'Verify the synthetic loan A terms.', 'Pure function.', 'calculateLoanInterestBreakdown(10000, 5, 3).', 'principal 10000, rate 5, term 3', 'monthly 500.00, interest 1500.00, payable 11500.00', json_encode($b), $ok ? 'PASS' : 'FAIL', $evidenceLog, '');

    $b = calculateLoanInterestBreakdown(0, 5, 6);
    $ok = framsNear($b['monthly_interest'], 0) && framsNear($b['total_interest'], 0) && framsNear($b['total_payable'], 0);
    framsRecord('UT-004', 'Unit Testing', 'Zero principal', 'Confirm a zero principal does not create interest.', 'Pure function.', 'calculateLoanInterestBreakdown(0, 5, 6).', 'principal 0', 'all amounts 0.00', json_encode($b), $ok ? 'PASS' : 'FAIL', $evidenceLog, '');

    $b = calculateLoanInterestBreakdown(-5000, 5, 3);
    $ok = framsNear($b['total_payable'], 0) && $b['total_payable'] >= 0;
    framsRecord('UT-005', 'Unit Testing', 'Negative principal', 'Confirm a negative principal cannot produce a negative payable.', 'Pure function.', 'calculateLoanInterestBreakdown(-5000, 5, 3).', 'principal -5000', 'payable is 0.00', json_encode($b), $ok ? 'PASS' : 'FAIL', $evidenceLog, 'The implementation clamps principal with max(0, amount) instead of throwing.');

    $b = calculateLoanInterestBreakdown(8000, -4, 6);
    $ok = framsNear($b['monthly_interest'], 0) && framsNear($b['total_payable'], 8000);
    framsRecord('UT-006', 'Unit Testing', 'Negative interest rate', 'Confirm a negative rate does not reduce principal.', 'Pure function.', 'calculateLoanInterestBreakdown(8000, -4, 6).', 'rate -4', 'interest 0.00 and payable 8000.00', json_encode($b), $ok ? 'PASS' : 'FAIL', $evidenceLog, 'Negative rates are clamped to zero.');

    $b = calculateLoanInterestBreakdown(10000, 5, 0);
    $charged = framsNear($b['total_interest'], 500) && framsNear($b['total_payable'], 10500);
    framsRecord('UT-007', 'Unit Testing', 'Zero loan duration', 'Confirm a zero-month term is not charged as a one-month loan.', 'Pure function.', 'calculateLoanInterestBreakdown(10000, 5, 0).', 'term 0', 'Invalid duration is rejected or does not create one month of interest.', json_encode($b), $charged ? 'FAIL' : 'PASS', $evidenceLog, 'max(1, term) coerces zero months to one month and charges interest of 500.00.');
    if ($charged) {
        framsFinding([
            'id' => 'F-001',
            'category' => 'Unit Testing',
            'component' => 'calculateLoanInterestBreakdown',
            'description' => 'A zero or negative loan term is coerced to one month, so the calculator charges one month of interest instead of rejecting the duration.',
            'severity' => 'Low',
            'evidence' => '01_Unit_Testing/logs/cli_test_execution.log',
            'reproduction' => 'Call calculateLoanInterestBreakdown(10000, 5, 0). Observed total interest 500.00 and total payable 10500.00.',
            'recommendation' => 'Reject a term less than 1 at the calculator and at the application form before interest is stored.',
            'retest' => 'Not retested. Application code was not changed.',
            'demonstrated' => 'Yes',
        ]);
    }

    $b = calculateLoanInterestBreakdown(10000, 5, -2);
    framsRecord('UT-008', 'Unit Testing', 'Negative loan duration', 'Confirm a negative term is not silently converted into a chargeable month.', 'Pure function.', 'calculateLoanInterestBreakdown(10000, 5, -2).', 'term -2', 'Invalid duration does not produce a one-month charge.', json_encode($b), framsNear($b['total_payable'], 10500) ? 'FAIL' : 'PASS', $evidenceLog, 'Same coercion as UT-007.');

    $b = calculateLoanInterestBreakdown(100.33, 5, 3);
    $ok = framsNear($b['monthly_interest'], 5.02) && framsNear($b['total_interest'], 15.05) && framsNear($b['total_payable'], 115.38);
    framsRecord('UT-009', 'Unit Testing', 'Decimal rounding', 'Verify half-up rounding to centavos after the unrounded calculation.', 'Pure function.', 'calculateLoanInterestBreakdown(100.33, 5, 3).', 'principal 100.33', 'monthly 5.02, interest 15.05, payable 115.38', json_encode($b), $ok ? 'PASS' : 'FAIL', $evidenceLog, 'Each returned field is rounded separately. Rounded monthly interest times months is 15.06, while total interest remains 15.05.');

    $b = calculateLoanInterestBreakdown(500000, 5, 12);
    $ok = framsNear($b['monthly_interest'], 25000) && framsNear($b['total_interest'], 300000) && framsNear($b['total_payable'], 800000);
    framsRecord('UT-010', 'Unit Testing', 'Policy maximum principal', 'Verify the upper configured loan amount.', 'Pure function.', 'calculateLoanInterestBreakdown(500000, 5, 12).', 'principal 500000', 'monthly 25000.00, interest 300000.00, payable 800000.00', json_encode($b), $ok ? 'PASS' : 'FAIL', $evidenceLog, '');

    framsRecord('UT-011', 'Unit Testing', 'Loan term label parsing', 'Parse a numeric term from a label.', 'Pure function.', 'parseLoanTermMonthsValue("12 months").', '"12 months"', '12', (string)parseLoanTermMonthsValue('12 months'), parseLoanTermMonthsValue('12 months') === 12 ? 'PASS' : 'FAIL', $evidenceLog, '');
    framsRecord('UT-012', 'Unit Testing', 'Non-numeric loan term', 'A term without digits returns zero so callers can treat it as missing.', 'Pure function.', 'parseLoanTermMonthsValue("abc").', '"abc"', '0', (string)parseLoanTermMonthsValue('abc'), parseLoanTermMonthsValue('abc') === 0 ? 'PASS' : 'FAIL', $evidenceLog, '');
    framsRecord('UT-013', 'Unit Testing', 'Numeric zero term parsing', 'Document how a numeric zero term is parsed.', 'Pure function.', 'parseLoanTermMonthsValue(0) and parseLoanTermMonthsValue("0").', '0', 'The parser returns 1 because numeric input uses max(1, value).', (string)parseLoanTermMonthsValue(0), parseLoanTermMonthsValue(0) === 1 ? 'PASS' : 'FAIL', $evidenceLog, 'This matches the coercion observed in UT-007.');

    $freqOk = normalizePaymentFrequencyKey('Semi-Monthly') === 'semi-monthly'
        && normalizePaymentFrequencyKey('Weekly collection') === 'weekly'
        && normalizePaymentFrequencyKey('') === 'monthly';
    framsRecord('UT-014', 'Unit Testing', 'Payment frequency normalization', 'Map frequency labels to schedule keys.', 'Pure function.', 'normalizePaymentFrequencyKey for semi-monthly, weekly, and blank.', 'three labels', 'semi-monthly, weekly, monthly', 'checked three labels', $freqOk ? 'PASS' : 'FAIL', $evidenceLog, '');

    $countsOk = getScheduleInstallmentCount('Monthly', 3) === 3
        && getScheduleInstallmentCount('Weekly', 3) === 12
        && getScheduleInstallmentCount('Semi-Monthly', 3) === 6
        && getScheduleInstallmentCount('Daily', 2) === 60;
    framsRecord('UT-015', 'Unit Testing', 'Installment counts', 'Confirm payment counts for each frequency.', 'Pure function.', 'getScheduleInstallmentCount for four frequencies.', 'term 3 or 2', '3, 12, 6, and 60', 'monthly 3, weekly 12, semi-monthly 6, daily 60', $countsOk ? 'PASS' : 'FAIL', $evidenceLog, 'Daily count without explicit dates is term months times 30.');

    $due = calculateLoanDueDate('2026-01-15', 'Monthly', 2);
    framsRecord('UT-016', 'Unit Testing', 'Monthly due date', 'Add one month per monthly installment.', 'Pure function.', 'calculateLoanDueDate("2026-01-15", "Monthly", 2).', 'start 2026-01-15, 2 months', '2026-03-15', $due, $due === '2026-03-15' ? 'PASS' : 'FAIL', $evidenceLog, '');

    $penaltiesOk = getPenaltyRatePercentForFrequency('Daily') === 1.0
        && getPenaltyRatePercentForFrequency('Weekly') === 2.0
        && getPenaltyRatePercentForFrequency('Semi-Monthly') === 3.0
        && getPenaltyRatePercentForFrequency('Monthly') === 5.0;
    framsRecord('UT-017', 'Unit Testing', 'Penalty rates by frequency', 'Confirm the frequency penalty percentages.', 'Pure function.', 'getPenaltyRatePercentForFrequency for four frequencies.', 'Daily, Weekly, Semi-Monthly, Monthly', '1, 2, 3, and 5 percent', '1, 2, 3, 5', $penaltiesOk ? 'PASS' : 'FAIL', $evidenceLog, '');

    $collect = calculateAmountToCollect(11500, 'Monthly', 3);
    framsRecord('UT-018', 'Unit Testing', 'Installment amount from total payable', 'Divide total payable by the number of payments.', 'Pure function.', 'calculateAmountToCollect(11500, Monthly, 3).', 'payable 11500, 3 months', '3833.33', (string)$collect, framsNear($collect, 3833.33) ? 'PASS' : 'FAIL', $evidenceLog, '');

    $collect = calculateAmountToCollect(11500, 'Monthly', 3, 4000);
    framsRecord('UT-019', 'Unit Testing', 'Scheduled installment override', 'Prefer an explicit schedule installment when supplied.', 'Pure function.', 'calculateAmountToCollect with schedule amount 4000.', 'schedule 4000', '4000.00', (string)$collect, framsNear($collect, 4000) ? 'PASS' : 'FAIL', $evidenceLog, '');

    $policyOk = lendingPasswordMeetsPolicy('Test@Frams1') === true
        && lendingPasswordMeetsPolicy('short') === false
        && lendingPasswordMeetsPolicy('NoSpecial1') === false;
    framsRecord('UT-020', 'Unit Testing', 'Password policy', 'Require 8 characters and a special character.', 'Pure function.', 'lendingPasswordMeetsPolicy for three passwords.', 'valid, short, no special character', 'true, false, false', 'valid accepted; short and alphanumeric rejected', $policyOk ? 'PASS' : 'FAIL', $evidenceLog, '');

    $hash = password_hash('Test@Frams1', PASSWORD_DEFAULT);
    $matchOk = passwordMatches('Test@Frams1', $hash) === true
        && passwordMatches('wrong', $hash) === false
        && passwordMatches('Test@Frams1', 'Test@Frams1') === false;
    framsRecord('UT-021', 'Unit Testing', 'Password verification', 'Accept only password_verify matches and reject plaintext equality.', 'Pure function.', 'passwordMatches against a hash and a plaintext copy.', 'synthetic password', 'hash matches; wrong password and plaintext storage do not', 'hash match true; plaintext comparison false', $matchOk ? 'PASS' : 'FAIL', $evidenceLog, '');

    $_SESSION['_csrf_token'] = 'token-a';
    $csrfOk = csrfValidate('token-a') === true && csrfValidate('token-b') === false && csrfValidate('') === false;
    framsRecord('UT-022', 'Unit Testing', 'CSRF token comparison', 'Accept only the exact session token.', 'Session active.', 'csrfValidate with matching, different, and empty tokens.', 'token-a / token-b / empty', 'true, false, false', 'matching accepted; mismatch and empty rejected', $csrfOk ? 'PASS' : 'FAIL', $evidenceLog, '');

    $body = '{"id":"evt_unit"}';
    $secret = 'unit-webhook-secret';
    $timestamp = (string)time();
    $goodSig = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    $header = 't=' . $timestamp . ',te=' . $goodSig;
    $validSig = lendingVerifyPaymongoWebhookSignature($body, $header, $secret, 'test');
    framsRecord('UT-023', 'Unit Testing', 'Valid webhook signature', 'Accept a current test-mode HMAC signature.', 'Synthetic secret, not the project secret.', 'lendingVerifyPaymongoWebhookSignature with te signature.', 'synthetic secret', 'true', $validSig ? 'true' : 'false', $validSig ? 'PASS' : 'FAIL', $evidenceLog, 'Local function test. No PayMongo request.');

    $badSig = lendingVerifyPaymongoWebhookSignature($body, 't=' . $timestamp . ',te=' . str_repeat('ab', 32), $secret, 'test');
    framsRecord('UT-024', 'Unit Testing', 'Invalid webhook signature', 'Reject a well-formed signature that does not match.', 'Synthetic secret.', 'Submit a 64-hex te value that is not the HMAC.', 'wrong te', 'false', $badSig ? 'true' : 'false', $badSig ? 'FAIL' : 'PASS', $evidenceLog, '');

    $missingSig = lendingVerifyPaymongoWebhookSignature($body, '', $secret, 'test');
    framsRecord('UT-025', 'Unit Testing', 'Missing webhook signature', 'Reject an empty signature header.', 'Synthetic secret.', 'Pass an empty signature header.', 'empty header', 'false', $missingSig ? 'true' : 'false', $missingSig ? 'FAIL' : 'PASS', $evidenceLog, '');

    $old = (string)(time() - 301);
    $oldSig = hash_hmac('sha256', $old . '.' . $body, $secret);
    $expired = lendingVerifyPaymongoWebhookSignature($body, 't=' . $old . ',te=' . $oldSig, $secret, 'test');
    framsRecord('UT-026', 'Unit Testing', 'Expired webhook timestamp', 'Reject a signature older than the 300-second tolerance.', 'Synthetic secret.', 'Use a timestamp 301 seconds old.', 'age 301 seconds', 'false', $expired ? 'true' : 'false', $expired ? 'FAIL' : 'PASS', $evidenceLog, '');

    $parsed = lendingParsePaymongoWebhookEvent('{');
    framsRecord('UT-027', 'Unit Testing', 'Malformed webhook JSON', 'Malformed JSON does not become an event.', 'Pure function.', 'lendingParsePaymongoWebhookEvent("{").', 'broken JSON', 'null', $parsed === null ? 'null' : 'parsed', $parsed === null ? 'PASS' : 'FAIL', $evidenceLog, '');

    $eventJson = json_encode([
        'data' => [
            'id' => 'evt_parse',
            'type' => 'event',
            'attributes' => [
                'type' => 'payment.paid',
                'livemode' => false,
                'data' => ['id' => 'pay_parse', 'type' => 'payment', 'attributes' => ['amount' => 100]],
            ],
        ],
    ]);
    $parsed = lendingParsePaymongoWebhookEvent($eventJson);
    $parseOk = is_array($parsed) && $parsed['id'] === 'evt_parse' && $parsed['type'] === 'payment.paid';
    framsRecord('UT-028', 'Unit Testing', 'Valid webhook event parse', 'Extract event id and type from a PayMongo-shaped payload.', 'Pure function.', 'Parse a minimal event document.', 'evt_parse / payment.paid', 'id and type extracted', $parseOk ? 'evt_parse payment.paid' : 'parse failed', $parseOk ? 'PASS' : 'FAIL', $evidenceLog, '');

    $match = lendingPaymongoWebhookMatchesTransaction(
        ['amount' => 2750, 'currency' => 'PHP'],
        ['amount' => 275000, 'currency' => 'PHP']
    );
    $mismatch = lendingPaymongoWebhookMatchesTransaction(
        ['amount' => 2750, 'currency' => 'PHP'],
        ['amount' => 100, 'currency' => 'PHP']
    );
    framsRecord('UT-029', 'Unit Testing', 'Webhook amount match', 'Compare centavos with the stored peso amount.', 'Pure function.', 'Match 2750.00 PHP to 275000 centavos and reject 100 centavos.', '275000 and 100', 'true then false', ($match ? 'match' : 'no-match') . '/' . ($mismatch ? 'match' : 'no-match'), ($match && !$mismatch) ? 'PASS' : 'FAIL', $evidenceLog, '');

    $safeUrl = lendingPaymongoQrphImageIsSafe('https://qr.paymongo.com/sample.png');
    $badUrl = lendingPaymongoQrphImageIsSafe('http://qr.paymongo.com/sample.png')
        || lendingPaymongoQrphImageIsSafe('https://evil.example/qr.png')
        || lendingPaymongoQrphImageIsSafe('javascript:alert(1)');
    framsRecord('UT-030', 'Unit Testing', 'QR image URL allow list', 'Accept only HTTPS PayMongo hosts and reject other URLs.', 'Pure function.', 'Check one PayMongo HTTPS URL and three unsafe URLs.', 'paymongo.com, http, other host, javascript', 'only the PayMongo HTTPS URL is safe', 'safe=' . ($safeUrl ? 'yes' : 'no') . ' unsafe-accepted=' . ($badUrl ? 'yes' : 'no'), ($safeUrl && !$badUrl) ? 'PASS' : 'FAIL', $evidenceLog, '');

    $payment = calculateProposedMonthlyLoanPayment(10000, 5, 12);
    framsRecord('UT-031', 'Unit Testing', 'Proposed monthly payment', 'Divide total payable by the term.', 'Pure function.', 'calculateProposedMonthlyLoanPayment(10000, 5, 12).', '10000 for 12 months', '1333.33', (string)$payment, framsNear($payment, 1333.33) ? 'PASS' : 'FAIL', $evidenceLog, '16000 / 12 = 1333.333..., rounded to 1333.33.');

    $maxPrincipal = calculateMaxPrincipalFromMonthlyPayment(1333.33, 5, 12);
    framsRecord('UT-032', 'Unit Testing', 'Maximum principal from payment', 'Invert the flat-interest factor.', 'Pure function.', 'calculateMaxPrincipalFromMonthlyPayment(1333.33, 5, 12).', 'payment 1333.33', 'approximately 9999.98', (string)$maxPrincipal, abs($maxPrincipal - 9999.98) < 0.02 ? 'PASS' : 'FAIL', $evidenceLog, 'Rounding of the forward payment prevents an exact 10000 reverse value.');

    $policy = lendingCreditPolicy();
    $clamped = clampLoanAmountToPolicy(100, $policy);
    framsRecord('UT-033', 'Unit Testing', 'Loan amount policy clamp', 'Raise an amount below the minimum to the policy minimum.', 'Policy from isolated env.', 'clampLoanAmountToPolicy(100).', '100', '3000.00', (string)$clamped, framsNear($clamped, 3000) ? 'PASS' : 'FAIL', $evidenceLog, '');

    $months = parseEmploymentLengthMonths('2 years');
    $monthsShort = parseEmploymentLengthMonths('2 months');
    framsRecord('UT-034', 'Unit Testing', 'Employment length parsing', 'Convert year and month phrases to months.', 'Pure function.', 'parseEmploymentLengthMonths for two phrases.', '2 years; 2 months', '24 and 2', (string)$months . ' and ' . (string)$monthsShort, ($months === 24 && $monthsShort === 2) ? 'PASS' : 'FAIL', $evidenceLog, '');

    $eligible = assessLoanEligibility([
        'monthly_gross_income' => 30000,
        'monthly_debt_payments' => 2000,
        'other_monthly_obligations' => 0,
        'employment_status' => 'Employed',
        'employment_length' => '2 years',
        'loan_amount' => 10000,
        'loan_term' => '12 months',
        'interest_rate' => 5,
    ]);
    framsRecord('UT-035', 'Unit Testing', 'Eligible affordability estimate', 'A request inside DTI and amount limits is Eligible.', 'Isolated test database for schema ensure.', 'assessLoanEligibility with income 30000 and loan 10000.', 'DTI inputs above', 'Eligible', (string)($eligible['status'] ?? ''), ($eligible['status'] ?? '') === 'Eligible' ? 'PASS' : 'FAIL', $evidenceLog, 'DTI after observed: ' . (string)($eligible['dti_after_percent'] ?? ''));

    $incomplete = assessLoanEligibility(['loan_amount' => 10000]);
    framsRecord('UT-036', 'Unit Testing', 'Incomplete eligibility input', 'Missing income and employment stay Not Eligible.', 'Isolated database.', 'assessLoanEligibility without income or employment.', 'loan amount only', 'Not Eligible and status_code incomplete', (string)($incomplete['status'] ?? '') . '/' . (string)($incomplete['status_code'] ?? ''), (($incomplete['status_code'] ?? '') === 'incomplete') ? 'PASS' : 'FAIL', $evidenceLog, '');

    $notEligible = assessLoanEligibility([
        'monthly_gross_income' => 10000,
        'monthly_debt_payments' => 0,
        'employment_status' => 'Employed',
        'employment_length' => '2 years',
        'loan_amount' => 50000,
        'loan_term' => '12 months',
        'interest_rate' => 5,
    ]);
    framsRecord('UT-037', 'Unit Testing', 'DTI above policy limit', 'A proposed payment that pushes DTI over 40 percent is Not Eligible.', 'Isolated database.', 'assessLoanEligibility for 50000 on 10000 income.', 'DTI case', 'Not Eligible', (string)($notEligible['status'] ?? '') . ' DTI ' . (string)($notEligible['dti_after_percent'] ?? ''), ($notEligible['status'] ?? '') === 'Not Eligible' ? 'PASS' : 'FAIL', $evidenceLog, '');

    $conditional = assessLoanEligibility([
        'monthly_gross_income' => 50000,
        'monthly_debt_payments' => 0,
        'employment_status' => 'Employed',
        'employment_length' => '1 month',
        'loan_amount' => 10000,
        'loan_term' => '12 months',
        'interest_rate' => 5,
    ]);
    framsRecord('UT-038', 'Unit Testing', 'Conditional eligibility', 'Affordable amount with short employment is Conditionally Eligible.', 'Isolated database.', 'assessLoanEligibility with one month of employment.', 'employment 1 month', 'Conditionally Eligible', (string)($conditional['status'] ?? ''), ($conditional['status'] ?? '') === 'Conditionally Eligible' ? 'PASS' : 'FAIL', $evidenceLog, '');

    $stepBlock = loanEligibilityBlocksStep2Progress([
        'employment_status' => 'Employed',
        'monthly_gross_income' => 10000,
        'monthly_debt_payments' => 5000,
        'other_monthly_obligations' => 0,
    ]);
    framsRecord('UT-039', 'Unit Testing', 'Existing debt blocks the wizard', 'Existing obligations above 40 percent DTI block step 3.', 'Pure policy function.', 'loanEligibilityBlocksStep2Progress.', 'debt 5000 of income 10000', 'a non-empty blocking message', $stepBlock === null ? 'no block' : 'blocked', $stepBlock !== null ? 'PASS' : 'FAIL', $evidenceLog, '');

    $upload = validateLoanDocumentUpload(['error' => UPLOAD_ERR_NO_FILE]);
    framsRecord('UT-040', 'Unit Testing', 'Missing upload file', 'A missing upload is rejected before storage.', 'Pure validator.', 'validateLoanDocumentUpload with UPLOAD_ERR_NO_FILE.', 'no file', 'ok false', (($upload['ok'] ?? true) === false) ? 'rejected' : 'accepted', ($upload['ok'] ?? true) === false ? 'PASS' : 'FAIL', $evidenceLog, (string)($upload['message'] ?? ''));

    $penalty = calculateOverduePenaltyDue($loanA, '2026-10-18');
    framsRecord('UT-041', 'Unit Testing', 'No penalty before the due date', 'Future installments do not accrue a penalty on the release date.', 'Synthetic loan A in the test database.', 'calculateOverduePenaltyDue for loan A as of 2026-10-18.', 'loan A', '0.00', (string)$penalty, framsNear($penalty, 0) ? 'PASS' : 'FAIL', $evidenceLog, '');

    $balance = calculateLoanBalanceSummary($loanA);
    $balanceOk = framsNear((float)$balance['total_payable'], 11500) && framsNear((float)$balance['remaining_balance'], 11500) && framsNear((float)$balance['total_paid'], 0);
    framsRecord('UT-042', 'Unit Testing', 'Initial loan balance', 'An unpaid synthetic loan reports the full payable as remaining.', 'Loan A has no payments.', 'calculateLoanBalanceSummary(loan A).', 'loan A', 'payable 11500, paid 0, remaining 11500', json_encode($balance), $balanceOk ? 'PASS' : 'FAIL', $evidenceLog, '');

    $threw = false;
    try {
        validatePaymentAmounts($loanA, 0, 0);
    } catch (InvalidArgumentException $e) {
        $threw = str_contains($e->getMessage(), 'greater than zero');
    }
    framsRecord('UT-043', 'Unit Testing', 'Zero payment amount', 'A zero payment is rejected.', 'Loan A.', 'validatePaymentAmounts(loan A, 0, 0).', 'amount 0', 'InvalidArgumentException', $threw ? 'rejected' : 'not rejected', $threw ? 'PASS' : 'FAIL', $evidenceLog, '');

    $threw = false;
    try {
        validatePaymentAmounts($loanA, -100, 0);
    } catch (InvalidArgumentException $e) {
        $threw = str_contains($e->getMessage(), 'greater than zero');
    }
    framsRecord('UT-044', 'Unit Testing', 'Negative payment amount', 'A negative payment is rejected and not stored.', 'Loan A.', 'validatePaymentAmounts(loan A, -100, 0).', 'amount -100', 'rejected as a non-positive amount', $threw ? 'rejected' : 'not rejected', $threw ? 'PASS' : 'FAIL', $evidenceLog, 'The validator clamps the input to zero and then rejects it.');

    $threw = false;
    $paymentsBefore = (int)$conn->query('SELECT COUNT(*) FROM payments')->fetchColumn();
    try {
        validatePaymentAmounts($loanA, 999999, 0);
    } catch (InvalidArgumentException $e) {
        $threw = str_contains($e->getMessage(), 'cannot exceed');
    }
    $paymentsAfter = (int)$conn->query('SELECT COUNT(*) FROM payments')->fetchColumn();
    framsRecord('UT-045', 'Unit Testing', 'Overpayment rejection', 'A payment above the amount due is rejected without a new payment row.', 'Loan A unpaid.', 'validatePaymentAmounts(loan A, 999999, 0).', 'amount 999999', 'exception and payment count unchanged', ($threw ? 'rejected' : 'accepted') . '; count ' . $paymentsBefore . ' to ' . $paymentsAfter, ($threw && $paymentsBefore === $paymentsAfter) ? 'PASS' : 'FAIL', $evidenceLog, '');
} catch (Throwable $e) {
    framsRecord('UT-ERR', 'Unit Testing', 'Unit suite execution', 'The unit assertions complete.', 'Isolated database.', 'Run the unit block.', 'n/a', 'no uncaught exception', framsRedact($e->getMessage()), 'FAIL', $evidenceLog, 'The unit block stopped early.');
}

try {
    $admin = getUserByUsernameOrEmail('frams_test_admin');
    $adminLogin = login('frams_test_admin', 'Test@Frams1');
    $adminOk = is_array($adminLogin) && isAdmin() && (int)$_SESSION['user_id'] === $adminId;
    framsRecord('IT-001', 'Integration Testing', 'Administrator login', 'An active administrator session is created from the synthetic account.', 'Seeded admin.', 'login("frams_test_admin", synthetic password).', 'frams_test_admin', 'session role Admin', $adminOk ? 'Admin session' : 'login failed', $adminOk ? 'PASS' : 'FAIL', $evidenceLog, '');
    logout();

    $collectorLogin = login('frams_test_collector', 'Test@Frams1');
    $collectorOk = is_array($collectorLogin) && isCollector() && !isAdmin();
    framsRecord('IT-002', 'Integration Testing', 'Collector login and role', 'Collector login does not grant administrator role.', 'Seeded collector.', 'login collector then check isCollector and isAdmin.', 'frams_test_collector', 'collector true, admin false', $collectorOk ? 'collector only' : 'unexpected role', $collectorOk ? 'PASS' : 'FAIL', $evidenceLog, '');
    $ownClient = ensureCollectorCanAccessClient($clientA, $collectorA);
    $otherClient = ensureCollectorCanAccessClient($clientB, $collectorA);
    framsRecord('IT-003', 'Integration Testing', 'Collector client scope', 'The collector can see only the assigned client.', 'Collector session.', 'ensureCollectorCanAccessClient for client A and client B.', 'collector A', 'true for A and false for B', 'A=' . ($ownClient ? 'yes' : 'no') . ' B=' . ($otherClient ? 'yes' : 'no'), ($ownClient && !$otherClient) ? 'PASS' : 'FAIL', $evidenceLog, '');
    $ownLoan = ensureCollectorCanAccessLoan($loanA, $collectorA);
    $otherLoan = ensureCollectorCanAccessLoan($loanB, $collectorA);
    framsRecord('IT-004', 'Integration Testing', 'Collector loan scope', 'The collector can see only the assigned loan.', 'Collector session.', 'ensureCollectorCanAccessLoan for loan A and loan B.', 'collector A', 'true for A and false for B', 'A=' . ($ownLoan ? 'yes' : 'no') . ' B=' . ($otherLoan ? 'yes' : 'no'), ($ownLoan && !$otherLoan) ? 'PASS' : 'FAIL', $evidenceLog, '');
    logout();

    $borrower = authenticateBorrowerLogin('frams_test_borrower', 'Test@Frams1');
    $borrowerStaff = login('frams_test_borrower', 'Test@Frams1');
    framsRecord('IT-005', 'Integration Testing', 'Borrower authentication boundary', 'Borrower credentials work for the borrower login and not the staff login.', 'Seeded borrower.', 'authenticateBorrowerLogin and login.', 'frams_test_borrower', 'borrower function returns the user; staff login returns false', (is_array($borrower) ? 'borrower-ok' : 'borrower-fail') . '/' . ($borrowerStaff === false ? 'staff-rejected' : 'staff-accepted'), (is_array($borrower) && $borrowerStaff === false) ? 'PASS' : 'FAIL', $evidenceLog, '');

    $inactive = login('frams_test_inactive', 'Test@Frams1');
    framsRecord('IT-006', 'Integration Testing', 'Inactive account', 'An inactive administrator cannot log in.', 'Seeded inactive admin.', 'login inactive account.', 'frams_test_inactive', 'false', $inactive === false ? 'rejected' : 'accepted', $inactive === false ? 'PASS' : 'FAIL', $evidenceLog, '');

    $badPassword = login('frams_test_admin', 'Wrong@Password1');
    framsRecord('IT-007', 'Integration Testing', 'Wrong password', 'A wrong password does not create a session.', 'Seeded admin.', 'login with a wrong password.', 'wrong password', 'false and logged out', ($badPassword === false && !isLoggedIn()) ? 'rejected' : 'accepted', ($badPassword === false && !isLoggedIn()) ? 'PASS' : 'FAIL', $evidenceLog, '');

    $linked = $conn->prepare('SELECT c.client_id, l.loan_id FROM clients c JOIN loans l ON l.client_id = c.client_id WHERE c.user_id = ? AND l.loan_number = ?');
    $linked->execute([$borrowerA, 'FRAMS-TEST-A']);
    $linkRow = $linked->fetch(PDO::FETCH_ASSOC);
    framsRecord('IT-008', 'Integration Testing', 'Borrower, client, and loan link', 'Loan A belongs to the synthetic borrower client.', 'Seed data.', 'Join clients and loans for borrower A.', 'FRAMS-TEST-A', 'one linked row', $linkRow ? ('client ' . $linkRow['client_id'] . ' loan ' . $linkRow['loan_id']) : 'missing', $linkRow ? 'PASS' : 'FAIL', $evidenceLog, '');

    $badFk = false;
    try {
        $conn->beginTransaction();
        $conn->prepare('INSERT INTO payments (loan_id, payment_date, amount_paid, payment_method) VALUES (?, CURDATE(), ?, ?)')->execute([999999, 10, 'Cash']);
        $conn->rollBack();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $badFk = true;
    }
    framsRecord('IT-009', 'Integration Testing', 'Invalid loan reference', 'A payment for a missing loan is not stored.', 'Test database.', 'Insert a payment with loan_id 999999 inside a transaction and roll back.', 'loan 999999', 'the insert fails or is rolled back and no row remains', ($badFk ? 'rejected' : 'inserted') . '; remaining ' . (int)$conn->query('SELECT COUNT(*) FROM payments WHERE loan_id = 999999')->fetchColumn(), ((int)$conn->query('SELECT COUNT(*) FROM payments WHERE loan_id = 999999')->fetchColumn() === 0) ? 'PASS' : 'FAIL', $evidenceLog, $badFk ? 'The database or statement rejected the reference, and the transaction was rolled back.' : 'No foreign key stopped the insert; the test transaction was rolled back.');

    $beforePaid = (float)$conn->query('SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE loan_id = ' . $loanA)->fetchColumn();
    $receipt = recordPaymentTransaction($loanA, '2026-10-18', 3833.33, 'FRAMS Test Collector', $collectorA, $clientA, 'Cash', null, 0, 'Synthetic CLI cash payment');
    $paymentRow = $conn->prepare('SELECT payment_method, amount_paid, loan_id, client_id, receipt_number FROM payments WHERE receipt_number = ?');
    $paymentRow->execute([$receipt]);
    $payment = $paymentRow->fetch(PDO::FETCH_ASSOC);
    $afterBalance = calculateLoanBalanceSummary($loanA);
    $cashOk = is_array($payment)
        && $payment['payment_method'] === 'Cash'
        && (int)$payment['loan_id'] === $loanA
        && (int)$payment['client_id'] === $clientA
        && str_starts_with((string)$receipt, 'RJRR-')
        && framsNear((float)$afterBalance['remaining_balance'], 7666.67);
    framsRecord('IT-010', 'Integration Testing', 'Cash payment posts to the loan', 'A valid cash payment stores method, loan, client, receipt, and reduced balance.', 'Loan A unpaid. Borrower email is null so no mail is sent.', 'recordPaymentTransaction for 3833.33 cash.', '3833.33 cash on loan A', 'receipt RJRR-*, method Cash, remaining 7666.67', 'receipt ' . (string)$receipt . ' balance ' . (string)$afterBalance['remaining_balance'], $cashOk ? 'PASS' : 'FAIL', $evidenceLog, 'Direct function call on the isolated database. Email was not sent because the synthetic client email is null.');

    $countBeforeReject = (int)$conn->query('SELECT COUNT(*) FROM payments WHERE loan_id = ' . $loanA)->fetchColumn();
    $rejected = false;
    try {
        recordPaymentTransaction($loanA, '2026-10-18', 0, 'FRAMS Test Collector', $collectorA, $clientA, 'Cash');
    } catch (InvalidArgumentException $e) {
        $rejected = true;
    }
    $countAfterReject = (int)$conn->query('SELECT COUNT(*) FROM payments WHERE loan_id = ' . $loanA)->fetchColumn();
    framsRecord('IT-011', 'Integration Testing', 'Rejected payment rolls back', 'A zero amount does not add a second payment.', 'Loan A already has one payment.', 'recordPaymentTransaction with amount 0.', 'amount 0', 'exception and same payment count', ($rejected ? 'rejected' : 'accepted') . '; count ' . $countBeforeReject . ' to ' . $countAfterReject, ($rejected && $countBeforeReject === $countAfterReject) ? 'PASS' : 'FAIL', $evidenceLog, '');

    $schedule = $conn->prepare('SELECT status FROM loan_payment_schedules WHERE release_id = ? ORDER BY installment_number');
    $schedule->execute([(int)$manifest['loans']['a']['release_id']]);
    $statuses = $schedule->fetchAll(PDO::FETCH_COLUMN);
    framsRecord('IT-012', 'Integration Testing', 'Schedule status after payment', 'The first installment becomes Paid and later installments stay Upcoming.', 'Cash payment in IT-010.', 'Read loan A schedule statuses.', 'loan A schedule', 'Paid, Upcoming, Upcoming', implode(', ', $statuses), $statuses === ['Paid', 'Upcoming', 'Upcoming'] ? 'PASS' : 'FAIL', $evidenceLog, '');

    login('frams_test_admin', 'Test@Frams1');
    $idBefore = session_id();
    logout();
    framsRecord('IT-013', 'Integration Testing', 'Logout clears the session', 'Logout removes the logged-in flag.', 'Admin session.', 'logout() after login.', 'admin session', 'isLoggedIn false', isLoggedIn() ? 'still logged in' : 'logged out', isLoggedIn() ? 'FAIL' : 'PASS', $evidenceLog, 'Session id before logout was regenerated by login; value not recorded.');
    unset($idBefore);
} catch (Throwable $e) {
    framsRecord('IT-ERR', 'Integration Testing', 'Integration suite execution', 'Integration assertions complete.', 'Isolated database.', 'Run the integration block.', 'n/a', 'no uncaught exception', framsRedact($e->getMessage()), 'FAIL', $evidenceLog, '');
}

try {
    $stored = $conn->prepare('SELECT password FROM users WHERE username = ?');
    $stored->execute(['frams_test_admin']);
    $storedHash = (string)$stored->fetchColumn();
    $hashOk = str_starts_with($storedHash, '$2y$') && !hash_equals($storedHash, 'Test@Frams1') && password_verify('Test@Frams1', $storedHash);
    framsRecord('SEC-001', 'Security Testing', 'Password storage', 'Synthetic passwords are stored with password_hash, not plaintext.', 'Seeded users.', 'Read the admin password column and verify it.', 'frams_test_admin', 'bcrypt hash that verifies', str_starts_with($storedHash, '$2y$') ? 'bcrypt hash verifies' : 'not bcrypt', $hashOk ? 'PASS' : 'FAIL', $evidenceLog, 'The hash value is not recorded.');

    $sqliUser = login("' OR '1'='1", 'Test@Frams1');
    framsRecord('SEC-002', 'Security Testing', 'Login SQL injection payload', 'A boolean payload is treated as a username and does not authenticate.', 'No matching username.', 'login() with a quote payload.', "username ' OR '1'='1", 'false', $sqliUser === false ? 'rejected' : 'authenticated', ($sqliUser === false && !isLoggedIn()) ? 'PASS' : 'FAIL', $evidenceLog, 'Prepared statement in getUserByUsernameOrEmail.');

    $xss = htmlspecialchars("<script>alert('x')</script>", ENT_QUOTES, 'UTF-8');
    framsRecord('SEC-003', 'Security Testing', 'Output encoding helper', 'htmlspecialchars encodes a script marker.', 'Pure function.', 'htmlspecialchars on a script marker.', 'script marker', 'encoded angle brackets', $xss, (str_contains($xss, '&lt;script&gt;') && !str_contains($xss, '<script>')) ? 'PASS' : 'FAIL', $evidenceLog, 'Page-level reflection is tested over HTTP.');

    $missing = csrfValidate('not-the-session-token');
    framsRecord('SEC-004', 'Security Testing', 'Wrong CSRF token', 'hash_equals rejects a token that is not in the session.', 'Session token exists.', 'csrfValidate with an incorrect token.', 'incorrect token', 'false', $missing ? 'accepted' : 'rejected', $missing ? 'FAIL' : 'PASS', $evidenceLog, '');
} catch (Throwable $e) {
    framsRecord('SEC-ERR', 'Security Testing', 'Security CLI block', 'Security CLI checks complete.', 'Isolated database.', 'Run the security CLI block.', 'n/a', 'no uncaught exception', framsRedact($e->getMessage()), 'FAIL', $evidenceLog, '');
}

function framsInsertPending(int $loanId, int $clientId, int $borrowerId, int $collectorId, string $intent, float $amount): int
{
    global $conn;
    $stmt = $conn->prepare(
        'INSERT INTO paymongo_qrph_transactions
            (internal_reference, payment_intent_id, loan_id, client_id, borrower_user_id, initiated_by_user_id, collector_id, selected_method, amount, penalty_paid, currency, status, provider_status, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, "PHP", "pending", "awaiting_next_action", DATE_ADD(NOW(), INTERVAL 30 MINUTE))'
    );
    $stmt->execute(['QRPH-TEST-' . substr($intent, -8), $intent, $loanId, $clientId, $borrowerId, $collectorId, $collectorId, 'E-Wallet', number_format($amount, 2, '.', '')]);
    return (int)$conn->lastInsertId();
}

function framsPaidEvent(string $eventId, string $paymentId, string $intentId, int $centavos, bool $livemode = false): array
{
    $json = json_encode([
        'data' => [
            'id' => $eventId,
            'type' => 'event',
            'attributes' => [
                'type' => 'payment.paid',
                'livemode' => $livemode,
                'data' => [
                    'id' => $paymentId,
                    'type' => 'payment',
                    'attributes' => [
                        'amount' => $centavos,
                        'currency' => 'PHP',
                        'status' => 'paid',
                        'livemode' => $livemode,
                        'payment_intent_id' => $intentId,
                        'source' => ['type' => 'qrph'],
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    $event = lendingParsePaymongoWebhookEvent($json);
    if (!is_array($event)) {
        throw new RuntimeException('Could not parse simulated event.');
    }
    return [$event, $json];
}

try {
    $paymentsBeforeApi = (int)$conn->query('SELECT COUNT(*) FROM payments WHERE loan_id = ' . $loanA)->fetchColumn();
    $apiFailedSafely = false;
    $apiDetail = '';
    try {
        lendingCreatePaymongoQrphTransaction($loanA, $collectorA, 'E-Wallet', 100, 0);
        $apiDetail = 'creation returned an id';
    } catch (Throwable $e) {
        $apiDetail = $e->getMessage();
        $apiFailedSafely = true;
    }
    $apiRow = $conn->query('SELECT status, provider_status FROM paymongo_qrph_transactions WHERE loan_id = ' . $loanA . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $paymentsAfterApi = (int)$conn->query('SELECT COUNT(*) FROM payments WHERE loan_id = ' . $loanA)->fetchColumn();
    $apiOk = $apiFailedSafely
        && $paymentsBeforeApi === $paymentsAfterApi
        && is_array($apiRow)
        && ($apiRow['status'] ?? '') === 'failed';
    framsRecord(
        'PM-001',
        'PayMongo Testing',
        'Invalid test key does not complete a payment',
        'A QR creation attempt with the isolated invalid test key fails closed.',
        'Test server keys are synthetic invalid test keys. Live keys are not loaded.',
        'Call lendingCreatePaymongoQrphTransaction for 100.00 on loan A.',
        'invalid sk_test key, amount 100',
        'exception, transaction status failed, payment count unchanged',
        $apiDetail . '; status=' . (string)($apiRow['status'] ?? 'none') . '; payments ' . $paymentsBeforeApi . ' to ' . $paymentsAfterApi,
        $apiOk ? 'PASS' : 'FAIL',
        $evidenceLog,
        'Real HTTP call to api.paymongo.com using an invalid test-mode key. This is not a completed test-mode payment and not a live charge.',
        'PayMongo API error with invalid test key'
    );

    $txCountBeforeZero = (int)$conn->query('SELECT COUNT(*) FROM paymongo_qrph_transactions')->fetchColumn();
    $zeroRejected = false;
    try {
        lendingCreatePaymongoQrphTransaction($loanA, $collectorA, 'E-Wallet', 0, 0);
    } catch (InvalidArgumentException $e) {
        $zeroRejected = str_contains($e->getMessage(), 'greater than zero');
    }
    $txCountAfterZero = (int)$conn->query('SELECT COUNT(*) FROM paymongo_qrph_transactions')->fetchColumn();
    framsRecord('PM-002', 'PayMongo Testing', 'Zero QR amount', 'A zero QR amount is rejected before a provider call.', 'Invalid key remains configured.', 'Create QR for amount 0.', 'amount 0', 'InvalidArgumentException and no new transaction row', ($zeroRejected ? 'rejected' : 'not rejected') . '; rows ' . $txCountBeforeZero . ' to ' . $txCountAfterZero, ($zeroRejected && $txCountBeforeZero === $txCountAfterZero) ? 'PASS' : 'FAIL', $evidenceLog, 'Local validation. No provider call because the amount fails first.', 'local validation');

    $cashMethodRejected = false;
    try {
        lendingCreatePaymongoQrphTransaction($loanA, $collectorA, 'Cash', 100, 0);
    } catch (InvalidArgumentException $e) {
        $cashMethodRejected = str_contains($e->getMessage(), 'Bank Transfer and E-Wallet');
    }
    framsRecord('PM-003', 'PayMongo Testing', 'Cash is not sent to QR Ph', 'Cash is rejected before QR creation.', 'Loan A.', 'Create QR with method Cash.', 'Cash', 'InvalidArgumentException', $cashMethodRejected ? 'rejected' : 'accepted', $cashMethodRejected ? 'PASS' : 'FAIL', $evidenceLog, 'Local validation.', 'local validation');

    $overRejected = false;
    $overDetail = '';
    try {
        lendingCreatePaymongoQrphTransaction($loanB, $collectorB, 'E-Wallet', 999999, 0);
    } catch (InvalidArgumentException $e) {
        $overRejected = str_contains($e->getMessage(), 'cannot exceed');
        $overDetail = $e->getMessage();
    } catch (Throwable $e) {
        $overDetail = $e->getMessage();
    }
    framsRecord('PM-004', 'PayMongo Testing', 'QR amount above balance', 'An amount above the loan due is rejected.', 'Loan B unpaid.', 'Create QR for 999999.', '999999', 'amount exception and no succeeded payment', $overRejected ? 'rejected' : $overDetail, $overRejected ? 'PASS' : 'FAIL', $evidenceLog, 'Local validation before the provider call.', 'local validation');

    $intent = 'pi_FRAMSTESTB001';
    $txId = framsInsertPending($loanB, $clientB, $borrowerB, $collectorB, $intent, 2750);
    [$event, $raw] = framsPaidEvent('evt_frams_paid_1', 'pay_FRAMSTESTB001', $intent, 275000, false);
    $result = lendingProcessPaymongoWebhookEvent($event, $raw);
    $txStatus = $conn->prepare('SELECT status, payment_id FROM paymongo_qrph_transactions WHERE id = ?');
    $txStatus->execute([$txId]);
    $txRow = $txStatus->fetch(PDO::FETCH_ASSOC);
    $posted = $conn->prepare('SELECT receipt_number, amount_paid, payment_method, paymongo_qrph_transaction_id FROM payments WHERE paymongo_qrph_transaction_id = ?');
    $posted->execute([$txId]);
    $postedRow = $posted->fetch(PDO::FETCH_ASSOC);
    $simOk = $result === 'processed'
        && ($txRow['status'] ?? '') === 'succeeded'
        && is_array($postedRow)
        && framsNear((float)$postedRow['amount_paid'], 2750)
        && (int)$postedRow['paymongo_qrph_transaction_id'] === $txId;
    framsRecord('PM-005', 'PayMongo Testing', 'Simulated paid webhook posts one receipt', 'A locally signed-equivalent paid event posts the matching loan once.', 'Pending synthetic transaction. Livemode false to match isolated test mode.', 'lendingProcessPaymongoWebhookEvent for payment.paid.', '2750.00 / 275000 centavos', 'processed, transaction succeeded, one payment of 2750.00', 'result ' . $result . '; status ' . (string)($txRow['status'] ?? '') . '; receipt ' . (string)($postedRow['receipt_number'] ?? ''), $simOk ? 'PASS' : 'FAIL', $evidenceLog, 'Local simulation. PayMongo did not deliver this webhook and no live payment was created.', 'local simulation');

    $paymentsForTx = (int)$conn->query('SELECT COUNT(*) FROM payments WHERE paymongo_qrph_transaction_id = ' . $txId)->fetchColumn();
    $duplicate = lendingProcessPaymongoWebhookEvent($event, $raw);
    $paymentsAfterDup = (int)$conn->query('SELECT COUNT(*) FROM payments WHERE paymongo_qrph_transaction_id = ' . $txId)->fetchColumn();
    framsRecord('PM-006', 'PayMongo Testing', 'Duplicate paid webhook', 'Replaying the same event id does not create a second payment.', 'PM-005 completed.', 'Process the same event again.', 'evt_frams_paid_1', 'duplicate and payment count stays 1', $duplicate . '; count ' . $paymentsForTx . ' to ' . $paymentsAfterDup, ($duplicate === 'duplicate' && $paymentsAfterDup === 1) ? 'PASS' : 'FAIL', $evidenceLog, 'Local simulation.', 'local simulation');

    $failedIntent = 'pi_FRAMSTESTB002';
    $failedTx = framsInsertPending($loanB, $clientB, $borrowerB, $collectorB, $failedIntent, 2750);
    $failedJson = json_encode([
        'data' => [
            'id' => 'evt_frams_failed_1',
            'type' => 'event',
            'attributes' => [
                'type' => 'payment.failed',
                'livemode' => false,
                'data' => [
                    'id' => 'pay_FRAMSFAIL001',
                    'type' => 'payment',
                    'attributes' => [
                        'amount' => 275000,
                        'currency' => 'PHP',
                        'status' => 'failed',
                        'livemode' => false,
                        'payment_intent_id' => $failedIntent,
                        'source' => ['type' => 'qrph'],
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    $failedEvent = lendingParsePaymongoWebhookEvent($failedJson);
    $failedResult = lendingProcessPaymongoWebhookEvent($failedEvent, $failedJson);
    $failedStatus = $conn->query('SELECT status FROM paymongo_qrph_transactions WHERE id = ' . $failedTx)->fetchColumn();
    $failedPayments = (int)$conn->query('SELECT COUNT(*) FROM payments WHERE paymongo_qrph_transaction_id = ' . $failedTx)->fetchColumn();
    framsRecord('PM-007', 'PayMongo Testing', 'Failed webhook does not post a receipt', 'payment.failed marks the attempt failed and does not insert a payment.', 'Second pending transaction on loan B.', 'Process payment.failed.', 'failed status', 'transaction failed and zero payments', 'result ' . $failedResult . '; status ' . (string)$failedStatus . '; payments ' . $failedPayments, ($failedStatus === 'failed' && $failedPayments === 0) ? 'PASS' : 'FAIL', $evidenceLog, 'Local simulation.', 'local simulation');

    $badIntent = 'pi_FRAMSTESTB003';
    $badTx = framsInsertPending($loanB, $clientB, $borrowerB, $collectorB, $badIntent, 2750);
    [$badEvent, $badRaw] = framsPaidEvent('evt_frams_mismatch_1', 'pay_FRAMSBAD001', $badIntent, 100, false);
    $badResult = lendingProcessPaymongoWebhookEvent($badEvent, $badRaw);
    $badStatus = (string)$conn->query('SELECT status FROM paymongo_qrph_transactions WHERE id = ' . $badTx)->fetchColumn();
    $badPayments = (int)$conn->query('SELECT COUNT(*) FROM payments WHERE paymongo_qrph_transaction_id = ' . $badTx)->fetchColumn();
    framsRecord('PM-008', 'PayMongo Testing', 'Mismatched paid amount', 'A paid event for the wrong centavo amount does not complete the loan.', 'Pending transaction for 2750.', 'Process payment.paid for 100 centavos.', '100 centavos', 'review_required and zero payments', 'result ' . $badResult . '; status ' . $badStatus . '; payments ' . $badPayments, ($badResult === 'review_required' && $badStatus === 'review_required' && $badPayments === 0) ? 'PASS' : 'FAIL', $evidenceLog, 'Local simulation. Loan B now has an unresolved review_required attempt.', 'local simulation');

    [$unknownEvent, $unknownRaw] = framsPaidEvent('evt_frams_unknown_1', 'pay_UNKNOWN001', 'pi_DOESNOTEXIST999', 275000, false);
    $unknownResult = lendingProcessPaymongoWebhookEvent($unknownEvent, $unknownRaw);
    framsRecord('PM-009', 'PayMongo Testing', 'Unknown payment intent', 'An event for an unknown intent does not create a payment.', 'No matching transaction.', 'Process payment.paid for pi_DOESNOTEXIST999.', 'unknown intent', 'ignored', $unknownResult, $unknownResult === 'ignored' ? 'PASS' : 'FAIL', $evidenceLog, 'Local simulation.', 'local simulation');

    $otherIntent = 'pi_FRAMSTESTB004';
    $otherTx = framsInsertPending($loanA, $clientA, $borrowerA, $collectorA, $otherIntent, 100);
    $otherJson = json_encode([
        'data' => [
            'id' => 'evt_frams_unsupported_1',
            'type' => 'event',
            'attributes' => [
                'type' => 'payment.refunded',
                'livemode' => false,
                'data' => [
                    'id' => 'pay_REFUND001',
                    'type' => 'payment',
                    'attributes' => [
                        'amount' => 10000,
                        'currency' => 'PHP',
                        'status' => 'refunded',
                        'livemode' => false,
                        'payment_intent_id' => $otherIntent,
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    $otherEvent = lendingParsePaymongoWebhookEvent($otherJson);
    $otherResult = lendingProcessPaymongoWebhookEvent($otherEvent, $otherJson);
    $otherStatus = (string)$conn->query('SELECT status FROM paymongo_qrph_transactions WHERE id = ' . $otherTx)->fetchColumn();
    framsRecord('PM-010', 'PayMongo Testing', 'Unsupported event type', 'An unsupported event leaves the pending transaction pending.', 'Pending 100.00 transaction on loan A.', 'Process payment.refunded.', 'payment.refunded', 'pending transaction unchanged', 'result ' . $otherResult . '; status ' . $otherStatus, ($otherStatus === 'pending') ? 'PASS' : 'FAIL', $evidenceLog, 'Local simulation. This pending row is closed afterward so loan A can accept a cash payment.', 'local simulation');
    $conn->prepare("UPDATE paymongo_qrph_transactions SET status = 'expired', provider_status = 'test_cleanup' WHERE id = ? AND status = 'pending'")->execute([$otherTx]);

    $liveBlocked = 'BLOCKED';
    framsRecord('PM-011', 'PayMongo Testing', 'Live PayMongo payment creation', 'Do not create a payment with the configured live secret key.', 'Project .env mode was observed as live during the read-only probe.', 'No live API request was sent.', 'live key not loaded into this process', 'No live charge is created.', 'No request was sent with the live key.', $liveBlocked, $evidenceLog, 'Blocked on purpose. The isolated process replaces live credentials with an invalid test key.', 'not executed against live PayMongo');

    framsRecord('PM-012', 'PayMongo Testing', 'External webhook delivery', 'PayMongo cannot be asked to deliver a webhook to this machine from this run.', 'No public tunnel was configured.', 'No external delivery was attempted.', 'local handler only', 'External delivery is not claimed.', 'Only local handler calls were executed.', 'BLOCKED', $evidenceLog, 'Local simulations are not PayMongo deliveries.', 'external delivery not available');
} catch (Throwable $e) {
    framsRecord('PM-ERR', 'PayMongo Testing', 'PayMongo CLI block', 'PayMongo CLI checks complete.', 'Isolated database.', 'Run the PayMongo block.', 'n/a', 'no uncaught exception', framsRedact($e->getMessage()), 'FAIL', $evidenceLog, '');
}

$outDir = dirname(__DIR__);
$grouped = [];
foreach ($cases as $case) {
    $grouped[$case['category']][] = $case;
}
file_put_contents($outDir . '/_harness/cli_cases.json', json_encode(['cases' => $cases, 'findings' => $findings], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$map = [
    'Unit Testing' => '01_Unit_Testing',
    'Integration Testing' => '02_Integration_Testing',
    'Security Testing' => '04_Security_Testing',
    'PayMongo Testing' => '06_PayMongo_Testing',
];
foreach ($map as $category => $folder) {
    $rows = $grouped[$category] ?? [];
    file_put_contents($outDir . '/' . $folder . '/test_results/cli_results.json', json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $lines = [];
    foreach ($rows as $row) {
        $lines[] = $row['id'] . "\t" . $row['status'] . "\t" . $row['scenario'] . "\t" . $row['actual'];
    }
    file_put_contents($outDir . '/' . $folder . '/logs/cli_test_execution.log', implode(PHP_EOL, $lines) . PHP_EOL);
}
file_put_contents($outDir . '/_harness/cli_findings.json', json_encode($findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$counts = array_count_values(array_column($cases, 'status'));
echo 'CLI_CASES=' . count($cases) . ' PASS=' . ($counts['PASS'] ?? 0) . ' FAIL=' . ($counts['FAIL'] ?? 0) . ' BLOCKED=' . ($counts['BLOCKED'] ?? 0) . PHP_EOL;
