<?php
/**
 * Independent FRAMS verification. Refuses to run unless the isolated
 * test database is selected. Does not read or write lending_management.
 */
if (!defined('FRAMS_TEST_PREPEND_LOADED') || lendingEnv('DB_NAME') !== 'frams_capstone_test') {
    fwrite(STDERR, "Refusing to run outside the isolated test database.\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
$app = $root . '/lending_system';
require_once $app . '/includes/db_lending.php';
require_once $app . '/includes/loan_helpers.php';
require_once $app . '/includes/loan_eligibility_helpers.php';
require_once $app . '/includes/auth_lending.php';
require_once $app . '/includes/csrf_lending.php';
require_once $app . '/includes/paymongo_qrph.php';
require_once $app . '/includes/paymongo_qrph_webhook.php';

if ((string)$conn->query('SELECT DATABASE()')->fetchColumn() !== 'frams_capstone_test') {
    fwrite(STDERR, "Connected database is not the isolated test database.\n");
    exit(1);
}

$results = [];
$started = date('c');

function framsRedact(string $text): string
{
    $text = preg_replace('/(sk|pk)_(test|live)_[A-Za-z0-9_]+/', '[redacted-key]', $text) ?? $text;
    $text = preg_replace('/whsk_[A-Za-z0-9_]+/', '[redacted-webhook-secret]', $text) ?? $text;
    $text = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[redacted-email]', $text) ?? $text;
    return $text;
}

function framsNear(float $actual, float $expected, float $tolerance = 0.001): bool
{
    return abs($actual - $expected) <= $tolerance;
}

function framsBcRound(string $value): string
{
    if (!function_exists('bcadd')) {
        return number_format(round((float)$value, 2), 2, '.', '');
    }
    $negative = str_starts_with($value, '-');
    $value = ltrim($value, '+-');
    if (!str_contains($value, '.')) {
        $value .= '.000';
    }
    [$whole, $frac] = explode('.', $value, 2);
    $frac = str_pad(substr($frac . '000', 0, 3), 3, '0');
    $keep = substr($frac, 0, 2);
    $next = (int)$frac[2];
    if ($next >= 5) {
        $keep = str_pad((string)((int)$keep + 1), 2, '0', STR_PAD_LEFT);
        if (strlen($keep) > 2) {
            $whole = bcadd($whole, '1', 0);
            $keep = substr($keep, -2);
        }
    }
    $rounded = $whole . '.' . $keep;
    return $negative ? '-' . $rounded : $rounded;
}

function framsIndependentInterest(string $principal, string $ratePercent, int $months): array
{
    $rate = bcdiv($ratePercent, '100', 8);
    $monthly = bcmul($principal, $rate, 8);
    $totalInterest = bcmul($monthly, (string)$months, 8);
    $payable = bcadd($principal, $totalInterest, 8);
    return [
        'monthly_interest' => (float)framsBcRound($monthly),
        'total_interest' => (float)framsBcRound($totalInterest),
        'total_payable' => (float)framsBcRound($payable),
    ];
}

function framsLoadBuildPaymentSchedule(string $app): void
{
    if (function_exists('buildPaymentSchedule')) {
        return;
    }
    $lines = file($app . '/loan_release_lending.php');
    $start = null;
    foreach ($lines as $index => $line) {
        if (str_starts_with($line, 'function buildPaymentSchedule(')) {
            $start = $index;
            break;
        }
    }
    if ($start === null) {
        throw new RuntimeException('buildPaymentSchedule was not found.');
    }
    $body = '';
    $depth = 0;
    $started = false;
    for ($i = $start; $i < count($lines); $i++) {
        $body .= $lines[$i];
        $depth += substr_count($lines[$i], '{') - substr_count($lines[$i], '}');
        if (str_contains($lines[$i], '{')) {
            $started = true;
        }
        if ($started && $depth === 0) {
            break;
        }
    }
    eval($body);
}

function framsRecord(array $row): void
{
    global $results;
    $row['actual'] = framsRedact((string)($row['actual'] ?? ''));
    $row['notes'] = framsRedact((string)($row['notes'] ?? ''));
    $row['executed_at'] = date('c');
    $results[] = $row;
    echo $row['id'] . ' ' . $row['status'] . ' ' . $row['feature'] . PHP_EOL;
}

function framsMoneyCase(string $id, string $feature, string $pre, string $inputs, array $expected, array $actual, string $notes = ''): void
{
    $diff = [
        'monthly_interest' => round($actual['monthly_interest'] - $expected['monthly_interest'], 2),
        'total_interest' => round($actual['total_interest'] - $expected['total_interest'], 2),
        'total_payable' => round($actual['total_payable'] - $expected['total_payable'], 2),
    ];
    $pass = $diff['monthly_interest'] == 0.0 && $diff['total_interest'] == 0.0 && $diff['total_payable'] == 0.0;
    framsRecord([
        'id' => $id,
        'category' => 'Financial',
        'feature' => $feature,
        'preconditions' => $pre,
        'inputs' => $inputs,
        'expected' => json_encode($expected),
        'actual' => json_encode($actual),
        'difference' => json_encode($diff),
        'status' => $pass ? 'PASS' : 'FAIL',
        'evidence' => 'testing/evidence/verification_results.json',
        'notes' => $notes,
    ]);
}

framsLoadBuildPaymentSchedule($app);

$cases = [
    ['FIN-01', 'Monthly interest, 12-month term', '10000', '5', 12],
    ['FIN-02', 'Monthly interest, 3-month term', '10000', '5', 3],
    ['FIN-03', 'Policy maximum principal', '500000', '5', 12],
    ['FIN-04', 'Rounding of a non-terminating product', '100.33', '5', 3],
    ['FIN-05', 'One-month term', '8000', '5', 1],
];
foreach ($cases as [$id, $feature, $principal, $rate, $months]) {
    $expected = framsIndependentInterest($principal, $rate, $months);
    $actual = calculateLoanInterestBreakdown((float)$principal, (float)$rate, $months);
    framsMoneyCase($id, $feature, 'Pure function. No database write.', "principal {$principal}, monthly rate {$rate}, months {$months}", $expected, $actual, 'Independent expected value uses BCMath half-up rounding of principal x (rate/100), times months, then principal plus total interest.');
}

$zero = calculateLoanInterestBreakdown(0, 5, 6);
framsMoneyCase('FIN-06', 'Zero principal', 'Pure function.', 'principal 0, rate 5, months 6', ['monthly_interest' => 0.0, 'total_interest' => 0.0, 'total_payable' => 0.0], $zero, 'A zero principal produces no interest.');

$negativePrincipal = calculateLoanInterestBreakdown(-5000, 5, 3);
framsRecord([
    'id' => 'FIN-07',
    'category' => 'Financial',
    'feature' => 'Negative principal',
    'preconditions' => 'Pure function.',
    'inputs' => 'principal -5000, rate 5, months 3',
    'expected' => 'Rejected as invalid, or a result that does not invent a positive loan.',
    'actual' => json_encode($negativePrincipal),
    'difference' => 'Clamped to zero instead of rejected.',
    'status' => ($negativePrincipal['total_payable'] == 0.0) ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'The payable is not negative. The function clamps with max(0, amount) and does not raise an error.',
]);

$negativeRate = calculateLoanInterestBreakdown(8000, -4, 6);
$expectedNegativeRate = framsIndependentInterest('8000', '0', 6);
framsMoneyCase('FIN-08', 'Negative interest rate', 'Pure function.', 'principal 8000, rate -4, months 6', $expectedNegativeRate, $negativeRate, 'Negative rates are clamped to zero before the formula runs, so the payable equals principal.');

$zeroTerm = calculateLoanInterestBreakdown(10000, 5, 0);
$zeroTermCharged = framsNear($zeroTerm['total_interest'], 500) && framsNear($zeroTerm['total_payable'], 10500);
framsRecord([
    'id' => 'FIN-09',
    'category' => 'Financial',
    'feature' => 'Zero loan term',
    'preconditions' => 'Pure function.',
    'inputs' => 'principal 10000, rate 5, months 0',
    'expected' => 'Invalid term is rejected and does not create one month of interest.',
    'actual' => json_encode($zeroTerm),
    'difference' => $zeroTermCharged ? 'Charged 500.00 interest by coercing the term to 1.' : 'Did not charge a one-month loan.',
    'status' => $zeroTermCharged ? 'FAIL' : 'PASS',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'calculateLoanInterestBreakdown uses max(1, term).',
]);

$negativeTerm = calculateLoanInterestBreakdown(10000, 5, -3);
framsRecord([
    'id' => 'FIN-10',
    'category' => 'Financial',
    'feature' => 'Negative loan term',
    'preconditions' => 'Pure function.',
    'inputs' => 'principal 10000, rate 5, months -3',
    'expected' => 'Invalid term is rejected and does not create one month of interest.',
    'actual' => json_encode($negativeTerm),
    'difference' => framsNear($negativeTerm['total_payable'], 10500) ? 'Charged a one-month payable of 10500.00.' : json_encode($negativeTerm),
    'status' => framsNear($negativeTerm['total_payable'], 10500) ? 'FAIL' : 'PASS',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'Same coercion as FIN-09.',
]);

$large = calculateLoanInterestBreakdown(1000000000000, 5, 12);
$largeExpected = framsIndependentInterest('1000000000000', '5', 12);
framsMoneyCase('FIN-11', 'Large but exactly representable principal', 'Pure function. Not a production loan.', 'principal 1000000000000, rate 5, months 12', $largeExpected, $large, 'This checks float behavior. It does not create a loan.');

$parsed = parseLoanTermMonthsValue('12 months');
$parsedBad = parseLoanTermMonthsValue('monthly');
$parsedZero = parseLoanTermMonthsValue(0);
framsRecord([
    'id' => 'FIN-12',
    'category' => 'Financial',
    'feature' => 'Loan term parsing',
    'preconditions' => 'Pure function.',
    'inputs' => '"12 months", "monthly", numeric 0',
    'expected' => '12, 0, and 1',
    'actual' => json_encode(['label' => $parsed, 'non_numeric' => $parsedBad, 'numeric_zero' => $parsedZero]),
    'difference' => ($parsed === 12 && $parsedBad === 0 && $parsedZero === 1) ? '0' : 'parser mismatch',
    'status' => ($parsed === 12 && $parsedBad === 0 && $parsedZero === 1) ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'A numeric zero is coerced to 1. A non-numeric string returns 0, and the interest function later coerces that 0 to 1.',
]);

$rateOk = getStandardMonthlyInterestRatePercent() === 5.0;
framsRecord([
    'id' => 'FIN-13',
    'category' => 'Financial',
    'feature' => 'Documented monthly rate constant',
    'preconditions' => 'Helper loaded.',
    'inputs' => 'getStandardMonthlyInterestRatePercent()',
    'expected' => '5.0',
    'actual' => (string)getStandardMonthlyInterestRatePercent(),
    'difference' => $rateOk ? '0' : 'rate is not 5',
    'status' => $rateOk ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'The constant is 5 percent of the original principal per month. Payment frequency does not change this rate.',
]);

$freqSame = true;
$freqActual = [];
foreach (['Monthly', 'Weekly', 'Semi-Monthly', 'Daily'] as $frequency) {
    $count = getScheduleInstallmentCount($frequency, 3);
    $breakdown = calculateLoanInterestBreakdown(10000, 5, 3);
    $freqActual[$frequency] = ['installments' => $count, 'total_payable' => $breakdown['total_payable']];
    if (!framsNear($breakdown['total_payable'], 11500)) {
        $freqSame = false;
    }
}
framsRecord([
    'id' => 'FIN-14',
    'category' => 'Financial',
    'feature' => 'Interest is independent of payment frequency',
    'preconditions' => 'Pure functions. No loan row.',
    'inputs' => 'principal 10000, rate 5, term 3, frequencies Monthly Weekly Semi-Monthly Daily',
    'expected' => 'Documented formula: total payable stays 11500.00 for every frequency. Installment counts are 3, 12, 6, and 90.',
    'actual' => json_encode($freqActual),
    'difference' => $freqSame ? '0 on total payable' : 'payable changed with frequency',
    'status' => ($freqSame && $freqActual['Monthly']['installments'] === 3 && $freqActual['Weekly']['installments'] === 12 && $freqActual['Semi-Monthly']['installments'] === 6 && $freqActual['Daily']['installments'] === 90) ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'This passes the documented flat formula. It does not prove that charging a full 5 percent per month is appropriate for a daily or weekly product.',
]);

$schedule = buildPaymentSchedule(10000, 5, 3, 'Monthly', '2026-01-15', 0);
$scheduleSum = 0.0;
foreach ($schedule as $row) {
    $scheduleSum += (float)$row['total_amount_due'];
}
$scheduleSum = round($scheduleSum, 2);
$schedulePass = count($schedule) === 3
    && $schedule[0]['due_date'] === '2026-02-15'
    && $schedule[2]['due_date'] === '2026-04-15'
    && framsNear($scheduleSum, 11500);
framsRecord([
    'id' => 'FIN-15',
    'category' => 'Financial',
    'feature' => 'Monthly installment schedule',
    'preconditions' => 'Actual buildPaymentSchedule function loaded from loan_release_lending.php. No database insert.',
    'inputs' => 'principal 10000, rate 5, term 3, Monthly, release 2026-01-15',
    'expected' => '3 installments due 2026-02-15, 2026-03-15, 2026-04-15, and the installment totals sum to 11500.00.',
    'actual' => json_encode(['count' => count($schedule), 'sum' => $scheduleSum, 'rows' => $schedule]),
    'difference' => json_encode(['sum_minus_payable' => round($scheduleSum - 11500, 2)]),
    'status' => $schedulePass ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'Each installment is rounded on its own. The generator does not put the remainder on the last installment.',
]);

$weekly = buildPaymentSchedule(10000, 5, 3, 'Weekly', '2026-01-15', 0);
$weeklySum = 0.0;
foreach ($weekly as $row) {
    $weeklySum += (float)$row['total_amount_due'];
}
$weeklySum = round($weeklySum, 2);
$weeklyGaps = [];
for ($i = 1; $i < count($weekly); $i++) {
    $weeklyGaps[] = (int)(new DateTime($weekly[$i - 1]['due_date']))->diff(new DateTime($weekly[$i]['due_date']))->days;
}
$weeklyCadenceOk = $weeklyGaps !== [] && count(array_unique($weeklyGaps)) === 1 && $weeklyGaps[0] === 7;
framsRecord([
    'id' => 'FIN-16',
    'category' => 'Financial',
    'feature' => 'Weekly installment schedule',
    'preconditions' => 'Actual buildPaymentSchedule. No database insert.',
    'inputs' => 'principal 10000, rate 5, term 3, Weekly, release 2026-01-15',
    'expected' => '12 installments, each 7 days apart, summing to 11500.00.',
    'actual' => json_encode(['count' => count($weekly), 'sum' => $weeklySum, 'gaps' => $weeklyGaps, 'first' => $weekly[0]['due_date'] ?? null, 'last' => $weekly[count($weekly) - 1]['due_date'] ?? null]),
    'difference' => json_encode(['sum_minus_payable' => round($weeklySum - 11500, 2), 'cadence_ok' => $weeklyCadenceOk]),
    'status' => (count($weekly) === 12 && framsNear($weeklySum, 11500) && $weeklyCadenceOk) ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'The loop steps 7 days, then replaces only the final due date with the calendar term end.',
]);

$daily = buildPaymentSchedule(10000, 5, 1, 'Daily', '2026-01-15', 0);
$dailySum = 0.0;
foreach ($daily as $row) {
    $dailySum += (float)$row['total_amount_due'];
}
$dailySum = round($dailySum, 2);
$dailyLastLoop = (new DateTime('2026-01-15'))->modify('+' . count($daily) . ' days')->format('Y-m-d');
framsRecord([
    'id' => 'FIN-17',
    'category' => 'Financial',
    'feature' => 'Daily installment schedule',
    'preconditions' => 'Actual buildPaymentSchedule. No database insert.',
    'inputs' => 'principal 10000, rate 5, term 1, Daily, release 2026-01-15',
    'expected' => 'Daily count is term months times 30. Installment totals sum to the one-month payable of 10500.00, and due dates stay one day apart.',
    'actual' => json_encode(['count' => count($daily), 'sum' => $dailySum, 'last' => $daily[count($daily) - 1]['due_date'] ?? null, 'last_if_unmodified' => $dailyLastLoop]),
    'difference' => json_encode(['sum_minus_payable' => round($dailySum - 10500, 2)]),
    'status' => (count($daily) === 30 && framsNear($dailySum, 10500) && ($daily[count($daily) - 1]['due_date'] ?? '') === $dailyLastLoop) ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'Interest is still one month at 5 percent, not a daily rate. The final due date is overwritten with release date plus one calendar month.',
]);

$penaltyRates = [
    'Daily' => getPenaltyRatePercentForFrequency('Daily'),
    'Weekly' => getPenaltyRatePercentForFrequency('Weekly'),
    'Semi-Monthly' => getPenaltyRatePercentForFrequency('Semi-Monthly'),
    'Monthly' => getPenaltyRatePercentForFrequency('Monthly'),
];
framsRecord([
    'id' => 'FIN-18',
    'category' => 'Financial',
    'feature' => 'Penalty rate by frequency',
    'preconditions' => 'Pure function.',
    'inputs' => 'Daily, Weekly, Semi-Monthly, Monthly',
    'expected' => '{"Daily":1,"Weekly":2,"Semi-Monthly":3,"Monthly":5}',
    'actual' => json_encode($penaltyRates),
    'difference' => ($penaltyRates == ['Daily' => 1.0, 'Weekly' => 2.0, 'Semi-Monthly' => 3.0, 'Monthly' => 5.0]) ? '0' : 'rate mismatch',
    'status' => ($penaltyRates == ['Daily' => 1.0, 'Weekly' => 2.0, 'Semi-Monthly' => 3.0, 'Monthly' => 5.0]) ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'Penalty is a percent of the overdue installment total, not a percent of remaining principal.',
]);

$homePrincipal = 100000.0;
$homeMonths = 12;
$homeRate = 0.05;
$homeJs = $homePrincipal * ($homeRate / 12) / (1 - pow(1 + $homeRate / 12, -$homeMonths));
$homeBackend = calculateLoanInterestBreakdown($homePrincipal, 5, $homeMonths);
$homeBackendInstallment = $homeBackend['total_payable'] / $homeMonths;
framsRecord([
    'id' => 'FIN-19',
    'category' => 'Financial',
    'feature' => 'Public calculator versus loan engine',
    'preconditions' => 'The public formula was read from index.php and evaluated independently. The loan engine function was called separately.',
    'inputs' => 'principal 100000, displayed rate 5, term 12',
    'expected' => 'Both calculators use monthly interest = principal x 0.05 and installment = total payable / months.',
    'actual' => json_encode([
        'public_formula_result' => round($homeJs, 2),
        'engine_monthly_installment' => round($homeBackendInstallment, 2),
        'engine_total_payable' => $homeBackend['total_payable'],
    ]),
    'difference' => json_encode(['public_minus_engine' => round($homeJs - $homeBackendInstallment, 2)]),
    'status' => framsNear($homeJs, $homeBackendInstallment, 0.01) ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'index.php labels the field as percent per month, then applies an amortizing formula that divides that rate by 12 again.',
]);

$proposed = calculateProposedMonthlyLoanPayment(10000, 5, 12);
$proposedExpected = round(16000 / 12, 2);
framsRecord([
    'id' => 'FIN-20',
    'category' => 'Financial',
    'feature' => 'Proposed monthly payment',
    'preconditions' => 'Pure function.',
    'inputs' => 'principal 10000, rate 5, term 12',
    'expected' => (string)$proposedExpected,
    'actual' => (string)$proposed,
    'difference' => (string)round($proposed - $proposedExpected, 2),
    'status' => framsNear($proposed, $proposedExpected) ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => '16000 / 12 = 1333.333..., rounded half-up to 1333.33.',
]);

$maxPrincipal = calculateMaxPrincipalFromMonthlyPayment(1333.33, 5, 12);
framsRecord([
    'id' => 'FIN-21',
    'category' => 'Financial',
    'feature' => 'Maximum principal from a rounded payment',
    'preconditions' => 'Pure function.',
    'inputs' => 'max monthly payment 1333.33, rate 5, term 12',
    'expected' => '9999.98 because the forward payment was already rounded',
    'actual' => (string)$maxPrincipal,
    'difference' => (string)round($maxPrincipal - 9999.98, 2),
    'status' => framsNear($maxPrincipal, 9999.98) ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'Independent reverse formula: (payment x months) / (1 + rate/100 x months) = 15999.96 / 1.6 = 9999.975, rounded to 9999.98.',
]);

$eligibleInput = [
    'monthly_gross_income' => 30000,
    'monthly_net_income' => 25000,
    'monthly_debt_payments' => 0,
    'other_monthly_obligations' => 0,
    'employment_status' => 'Employed',
    'employment_length' => '2 years',
    'loan_amount' => 10000,
    'loan_term' => '12 months',
    'interest_rate' => 5,
];
$eligible = assessLoanEligibility($eligibleInput);
framsRecord([
    'id' => 'FIN-22',
    'category' => 'Financial',
    'feature' => 'Eligible affordability case',
    'preconditions' => 'Isolated database only. Assessment may add missing eligibility columns on the test database.',
    'inputs' => 'gross 30000, debt 0, employed 24 months, request 10000, term 12, rate 5',
    'expected' => 'Eligible. Proposed payment 1333.33. DTI after 4.44. Maximum eligible principal 90000.00.',
    'actual' => json_encode([
        'status' => $eligible['status'] ?? null,
        'proposed' => $eligible['proposed_monthly_payment'] ?? null,
        'dti_after' => $eligible['dti_after_percent'] ?? null,
        'max_eligible' => $eligible['maximum_eligible_loan_amount'] ?? null,
    ]),
    'difference' => (($eligible['status'] ?? '') === 'Eligible' && framsNear((float)($eligible['dti_after_percent'] ?? 0), 4.44) && framsNear((float)($eligible['maximum_eligible_loan_amount'] ?? 0), 90000)) ? '0' : 'eligibility mismatch',
    'status' => (($eligible['status'] ?? '') === 'Eligible' && framsNear((float)($eligible['dti_after_percent'] ?? 0), 4.44) && framsNear((float)($eligible['maximum_eligible_loan_amount'] ?? 0), 90000)) ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'DTI after = 1333.33 / 30000 x 100 = 4.444..., rounded to 4.44. Maximum principal = (30000 x 0.40 x 12) / 1.6 = 90000.',
]);

$boundary = assessLoanEligibility([
    'monthly_gross_income' => 10000,
    'monthly_net_income' => 9000,
    'monthly_debt_payments' => 0,
    'other_monthly_obligations' => 0,
    'employment_status' => 'Employed',
    'employment_length' => '2 years',
    'loan_amount' => 30000,
    'loan_term' => '12 months',
    'interest_rate' => 5,
]);
framsRecord([
    'id' => 'FIN-23',
    'category' => 'Financial',
    'feature' => 'Debt-to-income exactly at 40 percent',
    'preconditions' => 'Isolated database. No production borrower.',
    'inputs' => 'gross 10000, debt 0, request 30000, term 12, rate 5',
    'expected' => 'Eligible. Payment 4000.00. DTI after 40.00. The comparison allows equality within 0.001.',
    'actual' => json_encode(['status' => $boundary['status'] ?? null, 'dti_after' => $boundary['dti_after_percent'] ?? null, 'proposed' => $boundary['proposed_monthly_payment'] ?? null]),
    'difference' => ((($boundary['status'] ?? '') === 'Eligible') && framsNear((float)($boundary['dti_after_percent'] ?? -1), 40.0)) ? '0' : 'boundary mismatch',
    'status' => ((($boundary['status'] ?? '') === 'Eligible') && framsNear((float)($boundary['dti_after_percent'] ?? -1), 40.0)) ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'Payable = 30000 + 18000 = 48000. Payment = 4000. DTI = 40.00.',
]);

$overDti = assessLoanEligibility([
    'monthly_gross_income' => 10000,
    'monthly_net_income' => 9000,
    'monthly_debt_payments' => 0,
    'other_monthly_obligations' => 0,
    'employment_status' => 'Employed',
    'employment_length' => '2 years',
    'loan_amount' => 31000,
    'loan_term' => '12 months',
    'interest_rate' => 5,
]);
framsRecord([
    'id' => 'FIN-24',
    'category' => 'Financial',
    'feature' => 'Debt-to-income above 40 percent',
    'preconditions' => 'Isolated database. No production borrower.',
    'inputs' => 'gross 10000, debt 0, request 31000, term 12, rate 5',
    'expected' => 'Not Eligible. Payment 4133.33. DTI after 41.33.',
    'actual' => json_encode(['status' => $overDti['status'] ?? null, 'dti_after' => $overDti['dti_after_percent'] ?? null, 'proposed' => $overDti['proposed_monthly_payment'] ?? null]),
    'difference' => ((($overDti['status'] ?? '') === 'Not Eligible') && framsNear((float)($overDti['dti_after_percent'] ?? 0), 41.33)) ? '0' : 'status or DTI mismatch',
    'status' => ((($overDti['status'] ?? '') === 'Not Eligible') && framsNear((float)($overDti['dti_after_percent'] ?? 0), 41.33)) ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'Payable = 31000 + 18600 = 49600. Payment = 4133.333... rounded to 4133.33.',
]);

$unaffordable = assessLoanEligibility([
    'monthly_gross_income' => 5000,
    'monthly_net_income' => 4500,
    'monthly_debt_payments' => 1900,
    'other_monthly_obligations' => 0,
    'employment_status' => 'Employed',
    'employment_length' => '2 years',
    'loan_amount' => 3000,
    'loan_term' => '12 months',
    'interest_rate' => 5,
]);
$reportedMax = (float)($unaffordable['maximum_eligible_loan_amount'] ?? -1);
framsRecord([
    'id' => 'FIN-25',
    'category' => 'Financial',
    'feature' => 'Affordable principal below the policy minimum',
    'preconditions' => 'Isolated database. No production borrower.',
    'inputs' => 'gross 5000, existing debt 1900, request 3000, term 12, rate 5, max DTI 40',
    'expected' => 'Maximum affordable principal is 750.00. The reported maximum must not be raised to the 3000 policy minimum.',
    'actual' => json_encode(['status' => $unaffordable['status'] ?? null, 'max_eligible' => $reportedMax, 'dti_after' => $unaffordable['dti_after_percent'] ?? null, 'max_payment' => $unaffordable['max_affordable_monthly_payment'] ?? null]),
    'difference' => (string)round($reportedMax - 750, 2),
    'status' => framsNear($reportedMax, 750) ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'Room for a new payment is 100.00. Principal = 100 x 12 / 1.6 = 750. clampLoanAmountToPolicy then applies the 3000 minimum.',
]);

$incomplete = assessLoanEligibility(['employment_status' => 'Employed']);
framsRecord([
    'id' => 'FIN-26',
    'category' => 'Financial',
    'feature' => 'Incomplete eligibility input',
    'preconditions' => 'Isolated database.',
    'inputs' => 'employment status only',
    'expected' => 'Not Eligible with status_code incomplete',
    'actual' => json_encode(['status' => $incomplete['status'] ?? null, 'code' => $incomplete['status_code'] ?? null]),
    'difference' => (($incomplete['status_code'] ?? '') === 'incomplete') ? '0' : 'not incomplete',
    'status' => (($incomplete['status'] ?? '') === 'Not Eligible' && ($incomplete['status_code'] ?? '') === 'incomplete') ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'Missing income and loan amount are not guessed.',
]);

$shortJob = assessLoanEligibility([
    'monthly_gross_income' => 30000,
    'monthly_debt_payments' => 0,
    'employment_status' => 'Employed',
    'employment_length' => '1 month',
    'loan_amount' => 10000,
    'loan_term' => '12 months',
    'interest_rate' => 5,
]);
framsRecord([
    'id' => 'FIN-27',
    'category' => 'Financial',
    'feature' => 'Employment below the three-month guideline',
    'preconditions' => 'Isolated database.',
    'inputs' => 'otherwise affordable 10000 loan with employment length of 1 month',
    'expected' => 'Conditionally Eligible',
    'actual' => (string)($shortJob['status'] ?? ''),
    'difference' => (($shortJob['status'] ?? '') === 'Conditionally Eligible') ? '0' : 'status mismatch',
    'status' => (($shortJob['status'] ?? '') === 'Conditionally Eligible') ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'Affordability can pass while tenure still blocks a clean Eligible result.',
]);

function framsInsertSyntheticLoan(PDO $conn, array $spec): array
{
    $appStmt = $conn->prepare('INSERT INTO loan_applications (user_id, borrower_name, employment_status, monthly_gross_income, loan_amount, loan_term, status, payment_frequency) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $appStmt->execute([$spec['borrower_id'], $spec['name'], 'Employed', 30000, $spec['principal'], $spec['term_label'], 'Released', $spec['frequency']]);
    $applicationId = (int)$conn->lastInsertId();
    $releaseStmt = $conn->prepare('INSERT INTO loan_releases (application_id, borrower_user_id, collector_user_id, loan_number, loan_amount, interest_rate, loan_term, payment_frequency, release_status, released_at, released_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)');
    $releaseStmt->execute([$applicationId, $spec['borrower_id'], $spec['collector_id'], $spec['loan_number'], $spec['principal'], 5, $spec['term_label'], $spec['frequency'], 'Released', 'FRAMS Verification']);
    $releaseId = (int)$conn->lastInsertId();
    $loanStmt = $conn->prepare('INSERT INTO loans (client_id, loan_amount, interest, total_payable, daily_payment, date_released, due_date, status, collector_id, loan_number, release_id, payment_frequency, loan_term, agreement_status, released_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $loanStmt->execute([
        $spec['client_id'], $spec['principal'], $spec['interest'], $spec['payable'], $spec['installment'],
        $spec['released'], $spec['final_due'], 'Active', $spec['collector_id'], $spec['loan_number'], $releaseId,
        $spec['frequency'], $spec['term_label'], 'Accepted', 'FRAMS Verification',
    ]);
    $loanId = (int)$conn->lastInsertId();
    $scheduleStmt = $conn->prepare('INSERT INTO loan_payment_schedules (release_id, installment_number, due_date, principal_amount, interest_amount, total_amount_due, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $n = 1;
    foreach ($spec['schedule'] as $row) {
        $scheduleStmt->execute([$releaseId, $n, $row['due'], $row['principal'], $row['interest'], $row['total'], $row['status']]);
        $n++;
    }
    return ['loan_id' => $loanId, 'release_id' => $releaseId, 'client_id' => $spec['client_id']];
}

$manifest = json_decode((string)file_get_contents($root . '/testing_evidence/_harness/seed_manifest.json'), true);
$borrowerId = (int)$manifest['users']['frams_test_borrower'];
$collectorId = (int)$manifest['users']['frams_test_collector'];
$clientId = (int)$manifest['clients']['a'];

$partial = framsInsertSyntheticLoan($conn, [
    'borrower_id' => $borrowerId,
    'collector_id' => $collectorId,
    'client_id' => $clientId,
    'name' => 'Synthetic Partial',
    'loan_number' => 'FRAMS-VERIFY-PARTIAL',
    'principal' => 10000,
    'interest' => 1500,
    'payable' => 11500,
    'installment' => 3833.33,
    'term_label' => '3 months',
    'frequency' => 'Monthly',
    'released' => '2026-10-19',
    'final_due' => '2027-01-19',
    'schedule' => [
        ['due' => '2026-11-19', 'principal' => 3333.33, 'interest' => 500, 'total' => 3833.33, 'status' => 'Upcoming'],
        ['due' => '2026-12-19', 'principal' => 3333.33, 'interest' => 500, 'total' => 3833.33, 'status' => 'Upcoming'],
        ['due' => '2027-01-19', 'principal' => 3333.34, 'interest' => 500, 'total' => 3833.34, 'status' => 'Upcoming'],
    ],
]);
$beforePayments = (int)$conn->query('SELECT COUNT(*) FROM payments WHERE loan_id = ' . (int)$partial['loan_id'])->fetchColumn();
$receiptPartial = recordPaymentTransaction($partial['loan_id'], '2026-10-19', 2000, 'FRAMS Test Collector', $collectorId, $clientId, 'Cash', 'VERIFY-PARTIAL', 0, 'Synthetic partial payment');
$summaryPartial = calculateLoanBalanceSummary($partial['loan_id']);
$partialRow = $conn->query('SELECT amount_paid, penalty_paid FROM payments WHERE loan_id = ' . (int)$partial['loan_id'])->fetch(PDO::FETCH_ASSOC);
$partialStatuses = $conn->query('SELECT status FROM loan_payment_schedules WHERE release_id = ' . (int)$partial['release_id'] . ' ORDER BY installment_number')->fetchAll(PDO::FETCH_COLUMN);
$partialPass = $receiptPartial && str_starts_with((string)$receiptPartial, 'RJRR-')
    && framsNear((float)$summaryPartial['remaining_balance'], 9500)
    && framsNear((float)$partialRow['amount_paid'], 2000)
    && framsNear((float)$partialRow['penalty_paid'], 0)
    && $partialStatuses === ['Upcoming', 'Upcoming', 'Upcoming'];
framsRecord([
    'id' => 'FIN-28',
    'category' => 'Financial',
    'feature' => 'Partial payment and remaining scheduled balance',
    'preconditions' => 'Synthetic loan in frams_capstone_test. Client email is null, so no borrower email is sent.',
    'inputs' => 'total payable 11500, cash payment 2000, penalty 0',
    'expected' => 'Receipt RJRR-*, amount_paid 2000.00, penalty_paid 0.00, remaining 9500.00, all installments still Upcoming.',
    'actual' => json_encode(['receipt' => $receiptPartial, 'summary' => $summaryPartial, 'row' => $partialRow, 'statuses' => $partialStatuses, 'payments_before' => $beforePayments]),
    'difference' => (string)round((float)$summaryPartial['remaining_balance'] - 9500, 2),
    'status' => $partialPass ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'A partial installment does not receive a Partial status. It stays Upcoming until the installment total is covered or the due date passes.',
]);

$over = framsInsertSyntheticLoan($conn, [
    'borrower_id' => $borrowerId,
    'collector_id' => $collectorId,
    'client_id' => $clientId,
    'name' => 'Synthetic Overpay',
    'loan_number' => 'FRAMS-VERIFY-OVER',
    'principal' => 10000,
    'interest' => 1500,
    'payable' => 11500,
    'installment' => 3833.33,
    'term_label' => '3 months',
    'frequency' => 'Monthly',
    'released' => '2026-10-19',
    'final_due' => '2027-01-19',
    'schedule' => [
        ['due' => '2026-11-19', 'principal' => 3333.33, 'interest' => 500, 'total' => 3833.33, 'status' => 'Upcoming'],
        ['due' => '2026-12-19', 'principal' => 3333.33, 'interest' => 500, 'total' => 3833.33, 'status' => 'Upcoming'],
        ['due' => '2027-01-19', 'principal' => 3333.34, 'interest' => 500, 'total' => 3833.34, 'status' => 'Upcoming'],
    ],
]);
$overBefore = (int)$conn->query('SELECT COUNT(*) FROM payments WHERE loan_id = ' . (int)$over['loan_id'])->fetchColumn();
$overError = '';
try {
    recordPaymentTransaction($over['loan_id'], '2026-10-19', 12000, 'FRAMS Test Collector', $collectorId, $clientId, 'Cash', 'VERIFY-OVER', 0, 'Synthetic overpayment');
    $overError = 'no exception';
} catch (Throwable $e) {
    $overError = $e->getMessage();
}
$overAfter = (int)$conn->query('SELECT COUNT(*) FROM payments WHERE loan_id = ' . (int)$over['loan_id'])->fetchColumn();
framsRecord([
    'id' => 'FIN-29',
    'category' => 'Financial',
    'feature' => 'Overpayment rejection',
    'preconditions' => 'Synthetic loan with payable 11500 and no penalty due.',
    'inputs' => 'cash payment 12000',
    'expected' => 'Exception, and the payment count stays 0.',
    'actual' => json_encode(['error' => $overError, 'before' => $overBefore, 'after' => $overAfter]),
    'difference' => (string)($overAfter - $overBefore),
    'status' => ($overBefore === 0 && $overAfter === 0 && $overError !== 'no exception') ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'The system rejects amounts above remaining balance plus penalty. It does not store an overpayment credit.',
]);

$zeroPayError = '';
try {
    validatePaymentAmounts($partial['loan_id'], 0, 0);
    $zeroPayError = 'no exception';
} catch (Throwable $e) {
    $zeroPayError = $e->getMessage();
}
$negativePayError = '';
try {
    validatePaymentAmounts($partial['loan_id'], -25, 0);
    $negativePayError = 'no exception';
} catch (Throwable $e) {
    $negativePayError = $e->getMessage();
}
framsRecord([
    'id' => 'FIN-30',
    'category' => 'Financial',
    'feature' => 'Zero and negative payment amounts',
    'preconditions' => 'Validator called against the synthetic partial loan. No new payment row is expected.',
    'inputs' => '0 and -25',
    'expected' => 'Both amounts are rejected.',
    'actual' => json_encode(['zero' => $zeroPayError, 'negative' => $negativePayError]),
    'difference' => '0',
    'status' => ($zeroPayError !== 'no exception' && $negativePayError !== 'no exception') ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'A negative amount is clamped to zero and then rejected as non-positive.',
]);

$penaltyLoan = framsInsertSyntheticLoan($conn, [
    'borrower_id' => $borrowerId,
    'collector_id' => $collectorId,
    'client_id' => $clientId,
    'name' => 'Synthetic Penalty',
    'loan_number' => 'FRAMS-VERIFY-PENALTY',
    'principal' => 2000,
    'interest' => 0,
    'payable' => 2000,
    'installment' => 1000,
    'term_label' => '2 months',
    'frequency' => 'Monthly',
    'released' => '2026-07-01',
    'final_due' => '2026-09-01',
    'schedule' => [
        ['due' => '2026-08-01', 'principal' => 1000, 'interest' => 0, 'total' => 1000, 'status' => 'Upcoming'],
        ['due' => '2026-09-01', 'principal' => 1000, 'interest' => 0, 'total' => 1000, 'status' => 'Upcoming'],
    ],
]);
$penaltyDue = calculateOverduePenaltyDue($penaltyLoan['loan_id'], '2026-10-19');
framsRecord([
    'id' => 'FIN-31',
    'category' => 'Financial',
    'feature' => 'Overdue penalty calculation',
    'preconditions' => 'Synthetic monthly loan. Two unpaid installments of 1000.00 were due before 2026-10-19.',
    'inputs' => 'monthly penalty rate 5 percent of each installment total',
    'expected' => '50.00 + 50.00 = 100.00',
    'actual' => (string)$penaltyDue,
    'difference' => (string)round($penaltyDue - 100, 2),
    'status' => framsNear($penaltyDue, 100) ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'Penalty uses the installment total_amount_due, not the original principal. The stored schedule status is still Upcoming until a payment or status sync rewrites it.',
]);

$receiptPenalty = recordPaymentTransaction($penaltyLoan['loan_id'], '2026-10-19', 80, 'FRAMS Test Collector', $collectorId, $clientId, 'Cash', 'VERIFY-PENALTY', 80, 'Synthetic penalty portion');
$penaltySummary = calculateLoanBalanceSummary($penaltyLoan['loan_id']);
$penaltyStored = $conn->query('SELECT amount_paid, penalty_paid FROM payments WHERE loan_id = ' . (int)$penaltyLoan['loan_id'])->fetch(PDO::FETCH_ASSOC);
$penaltyPass = framsNear((float)$penaltyStored['amount_paid'], 0)
    && framsNear((float)$penaltyStored['penalty_paid'], 80)
    && framsNear((float)$penaltySummary['remaining_balance'], 2000)
    && framsNear((float)$penaltySummary['penalty_due'], 20);
framsRecord([
    'id' => 'FIN-32',
    'category' => 'Financial',
    'feature' => 'Penalty separated from scheduled repayment',
    'preconditions' => 'Same synthetic overdue loan. Payment is cash in the test database.',
    'inputs' => 'cash received 80, of which 80 is designated penalty',
    'expected' => 'amount_paid 0.00, penalty_paid 80.00, scheduled remaining stays 2000.00, penalty still due 20.00.',
    'actual' => json_encode(['receipt' => $receiptPenalty, 'stored' => $penaltyStored, 'summary' => $penaltySummary]),
    'difference' => json_encode([
        'remaining_minus_2000' => round((float)$penaltySummary['remaining_balance'] - 2000, 2),
        'penalty_due_minus_20' => round((float)$penaltySummary['penalty_due'] - 20, 2),
    ]),
    'status' => $penaltyPass ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'payments.amount_paid stores only the scheduled portion. Cash received is amount_paid plus penalty_paid.',
]);

$splitLoan = framsInsertSyntheticLoan($conn, [
    'borrower_id' => $borrowerId,
    'collector_id' => $collectorId,
    'client_id' => $clientId,
    'name' => 'Synthetic Split',
    'loan_number' => 'FRAMS-VERIFY-SPLIT',
    'principal' => 1000,
    'interest' => 0,
    'payable' => 1000,
    'installment' => 1000,
    'term_label' => '1 month',
    'frequency' => 'Monthly',
    'released' => '2026-08-01',
    'final_due' => '2026-09-01',
    'schedule' => [
        ['due' => '2026-09-01', 'principal' => 1000, 'interest' => 0, 'total' => 1000, 'status' => 'Upcoming'],
    ],
]);
$splitReceipt = recordPaymentTransaction($splitLoan['loan_id'], '2026-10-19', 150, 'FRAMS Test Collector', $collectorId, $clientId, 'Cash', 'VERIFY-SPLIT', 50, 'Synthetic split payment');
$splitSummary = calculateLoanBalanceSummary($splitLoan['loan_id']);
$splitStored = $conn->query('SELECT amount_paid, penalty_paid FROM payments WHERE loan_id = ' . (int)$splitLoan['loan_id'])->fetch(PDO::FETCH_ASSOC);
$splitPass = framsNear((float)$splitStored['amount_paid'], 100)
    && framsNear((float)$splitStored['penalty_paid'], 50)
    && framsNear((float)$splitSummary['remaining_balance'], 900)
    && framsNear((float)$splitSummary['penalty_due'], 0)
    && framsNear((float)$splitSummary['total_amount_due'], 900);
framsRecord([
    'id' => 'FIN-33',
    'category' => 'Financial',
    'feature' => 'Outstanding balance after a mixed penalty payment',
    'preconditions' => 'Synthetic loan payable 1000 with one overdue installment. Penalty due before payment is 50.00.',
    'inputs' => 'cash received 150, penalty portion 50',
    'expected' => 'Scheduled balance 900.00. Penalty due 0.00. Total amount due 900.00.',
    'actual' => json_encode(['receipt' => $splitReceipt, 'stored' => $splitStored, 'summary' => $splitSummary]),
    'difference' => (string)round((float)$splitSummary['remaining_balance'] - 900, 2),
    'status' => $splitPass ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'The 50.00 penalty is not subtracted from total_payable.',
]);

$loansPageOutstanding = (float)$conn->query("SELECT COALESCE(SUM(CASE WHEN status <> 'Paid' THEN total_payable ELSE 0 END), 0) FROM loans")->fetchColumn();
$balanceOutstanding = 0.0;
foreach ($conn->query('SELECT loan_id FROM loans')->fetchAll(PDO::FETCH_COLUMN) as $loanId) {
    $balanceOutstanding += (float)calculateLoanBalanceSummary((int)$loanId)['remaining_balance'];
}
$balanceOutstanding = round($balanceOutstanding, 2);
framsRecord([
    'id' => 'FIN-34',
    'category' => 'Financial',
    'feature' => 'Loans-page outstanding versus balance function',
    'preconditions' => 'Comparison is limited to synthetic rows in frams_capstone_test after the payments above.',
    'inputs' => 'SUM of total_payable for loans not marked Paid, compared with calculateLoanBalanceSummary remaining balances',
    'expected' => 'The loans page outstanding equals the sum of remaining scheduled balances.',
    'actual' => json_encode(['loans_page_formula' => $loansPageOutstanding, 'balance_function' => $balanceOutstanding]),
    'difference' => (string)round($loansPageOutstanding - $balanceOutstanding, 2),
    'status' => framsNear($loansPageOutstanding, $balanceOutstanding) ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'loans_lending.php sums total_payable and does not subtract payments. The reports page uses payable minus payments.',
]);

$hash = password_hash('Test-Only-Password-1!', PASSWORD_DEFAULT);
$match = passwordMatches('Test-Only-Password-1!', $hash);
$mismatch = passwordMatches('wrong-password', $hash);
$plaintext = passwordMatches('secret', 'secret');
framsRecord([
    'id' => 'SEC-01',
    'category' => 'Security',
    'feature' => 'Password hashing and verification',
    'preconditions' => 'In-memory hash. No user row was updated.',
    'inputs' => 'password_hash with PASSWORD_DEFAULT, then passwordMatches',
    'expected' => 'Correct password matches. Wrong password and a plaintext stored value do not.',
    'actual' => json_encode(['match' => $match, 'mismatch' => $mismatch, 'plaintext' => $plaintext, 'algorithm_prefix' => substr($hash, 0, 4)]),
    'difference' => '0',
    'status' => ($match === true && $mismatch === false && $plaintext === false && str_starts_with($hash, '$2y$')) ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'The hash itself is not stored in this report.',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$token = csrfEnsureToken();
$csrfOk = csrfValidate($token);
$csrfBad = csrfValidate('not-the-token');
$csrfEmpty = csrfValidate('');
framsRecord([
    'id' => 'SEC-02',
    'category' => 'Security',
    'feature' => 'CSRF token comparison',
    'preconditions' => 'CLI session. No form was posted to the production application.',
    'inputs' => 'matching token, mismatched token, empty token',
    'expected' => 'true, false, false',
    'actual' => json_encode(['match' => $csrfOk, 'mismatch' => $csrfBad, 'empty' => $csrfEmpty]),
    'difference' => '0',
    'status' => ($csrfOk === true && $csrfBad === false && $csrfEmpty === false) ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'csrfRequireValid applies to POST requests. This test checks the comparison function only.',
]);

$secret = 'synthetic-webhook-secret-for-local-verification';
$body = '{"id":"evt_local_verify","type":"payment.paid"}';
$timestamp = (string)time();
$goodSig = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
$goodHeader = 't=' . $timestamp . ',te=' . $goodSig;
$badHeader = 't=' . $timestamp . ',te=' . str_repeat('ab', 32);
$oldHeader = 't=' . (string)(time() - 1000) . ',te=' . hash_hmac('sha256', (string)(time() - 1000) . '.' . $body, $secret);
$sigGood = lendingVerifyPaymongoWebhookSignature($body, $goodHeader, $secret, 'test');
$sigBad = lendingVerifyPaymongoWebhookSignature($body, $badHeader, $secret, 'test');
$sigMissing = lendingVerifyPaymongoWebhookSignature($body, '', $secret, 'test');
$sigOld = lendingVerifyPaymongoWebhookSignature($body, $oldHeader, $secret, 'test');
$sigEmptySecret = lendingVerifyPaymongoWebhookSignature($body, $goodHeader, '', 'test');
framsRecord([
    'id' => 'SEC-03',
    'category' => 'Security',
    'feature' => 'Webhook signature verification',
    'preconditions' => 'Local function call. PayMongo did not send this event. The secret is synthetic.',
    'inputs' => 'valid test-mode signature, wrong signature, missing header, timestamp older than 300 seconds, empty secret',
    'expected' => 'true, false, false, false, false',
    'actual' => json_encode(['valid' => $sigGood, 'invalid' => $sigBad, 'missing' => $sigMissing, 'expired' => $sigOld, 'empty_secret' => $sigEmptySecret]),
    'difference' => '0',
    'status' => ($sigGood && !$sigBad && !$sigMissing && !$sigOld && !$sigEmptySecret) ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'The project .env webhook secret is empty. This test did not read or print it. An empty secret fails closed in the function.',
]);

$collectorA = (int)$manifest['users']['frams_test_collector'];
$loanBId = (int)$manifest['loans']['b']['loan_id'];
$loanAId = (int)$manifest['loans']['a']['loan_id'];
$_SESSION['logged_in'] = true;
$_SESSION['role'] = 'Collector';
$_SESSION['user_id'] = $collectorA;
$scopeOwn = ensureCollectorCanAccessLoan($loanAId, $collectorA);
$scopeOther = ensureCollectorCanAccessLoan($loanBId, $collectorA);
$_SESSION['logged_in'] = false;
$_SESSION['role'] = '';
framsRecord([
    'id' => 'SEC-04',
    'category' => 'Security',
    'feature' => 'Collector loan scope',
    'preconditions' => 'Synthetic loans in the isolated database.',
    'inputs' => 'collector A against assigned loan A and unassigned loan B',
    'expected' => 'true for A and false for B',
    'actual' => json_encode(['own' => $scopeOwn, 'other' => $scopeOther]),
    'difference' => '0',
    'status' => ($scopeOwn === true && $scopeOther === false) ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'This is a direct function call, not a browser session.',
]);

$loginBad = false;
try {
    $loginResult = login("admin' OR '1'='1", 'not-a-real-password');
    $loginBad = $loginResult !== false;
} catch (Throwable $e) {
    $loginBad = true;
}
framsRecord([
    'id' => 'SEC-05',
    'category' => 'Security',
    'feature' => 'Login identifier with a SQL injection string',
    'preconditions' => 'Isolated database. The statement is prepared. No production account is involved.',
    'inputs' => "username admin' OR '1'='1 with a wrong password",
    'expected' => 'Login returns false and does not authenticate a user.',
    'actual' => $loginBad ? 'authenticated or threw' : 'rejected',
    'difference' => '0',
    'status' => $loginBad ? 'FAIL' : 'PASS',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'One prepared-statement login attempt. This is not a full SQL injection assessment.',
]);

$perfStart = hrtime(true);
for ($i = 0; $i < 1000; $i++) {
    calculateLoanInterestBreakdown(10000, 5, 12);
}
$interestMs = (hrtime(true) - $perfStart) / 1e6;
$perfStart = hrtime(true);
for ($i = 0; $i < 200; $i++) {
    buildPaymentSchedule(10000, 5, 12, 'Monthly', '2026-01-15', 0);
}
$scheduleMs = (hrtime(true) - $perfStart) / 1e6;
$perfStart = hrtime(true);
for ($i = 0; $i < 100; $i++) {
    calculateLoanBalanceSummary($partial['loan_id']);
}
$balanceMs = (hrtime(true) - $perfStart) / 1e6;
framsRecord([
    'id' => 'PERF-01',
    'category' => 'Performance',
    'feature' => 'Local function timing',
    'preconditions' => 'CLI on this workstation. Isolated database for the balance calls. Not a concurrent load test.',
    'inputs' => '1000 interest calculations, 200 monthly schedules, 100 balance summaries for one synthetic loan',
    'expected' => 'Measured durations only. No capacity target is claimed.',
    'actual' => json_encode([
        'interest_1000_ms' => round($interestMs, 3),
        'schedule_200_ms' => round($scheduleMs, 3),
        'balance_100_ms' => round($balanceMs, 3),
    ]),
    'difference' => 'n/a',
    'status' => 'PASS',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'These are single-process timings. They do not estimate concurrent users.',
]);

$borrowerB = (int)$manifest['users']['frams_test_borrower_b'];
$collectorB = (int)$manifest['users']['frams_test_collector_b'];
$clientB = (int)$manifest['clients']['b'];
$simLoan = framsInsertSyntheticLoan($conn, [
    'borrower_id' => $borrowerB,
    'collector_id' => $collectorB,
    'client_id' => $clientB,
    'name' => 'Synthetic QR',
    'loan_number' => 'FRAMS-VERIFY-QR',
    'principal' => 5000,
    'interest' => 500,
    'payable' => 5500,
    'installment' => 2750,
    'term_label' => '2 months',
    'frequency' => 'Monthly',
    'released' => '2026-10-19',
    'final_due' => '2026-12-19',
    'schedule' => [
        ['due' => '2026-11-19', 'principal' => 2500, 'interest' => 250, 'total' => 2750, 'status' => 'Upcoming'],
        ['due' => '2026-12-19', 'principal' => 2500, 'interest' => 250, 'total' => 2750, 'status' => 'Upcoming'],
    ],
]);
$paymentsBeforeSim = (int)$conn->query('SELECT COUNT(*) FROM payments WHERE loan_id = ' . (int)$simLoan['loan_id'])->fetchColumn();
$insertSim = $conn->prepare("INSERT INTO paymongo_qrph_transactions (internal_reference, payment_intent_id, loan_id, client_id, borrower_user_id, initiated_by_user_id, collector_id, selected_method, amount, penalty_paid, currency, status, provider_status, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'E-Wallet', 2750.00, 0.00, 'PHP', 'pending', 'awaiting_next_action', DATE_ADD(NOW(), INTERVAL 30 MINUTE))");
$insertSim->execute(['QRPH-LOCAL-SIM-001', 'pi_local_simulation_only', $simLoan['loan_id'], $clientB, $borrowerB, $collectorB, $collectorB]);
$simEvent = [
    'id' => 'evt_local_simulation_paid_001',
    'type' => 'payment.paid',
    'livemode' => false,
    'resource' => [
        'id' => 'pay_local_simulation_only',
        'type' => 'payment',
        'attributes' => [
            'amount' => 275000,
            'currency' => 'PHP',
            'status' => 'paid',
            'livemode' => false,
            'payment_intent_id' => 'pi_local_simulation_only',
            'source' => ['type' => 'qrph'],
        ],
    ],
];
$simRaw = json_encode($simEvent);
$simResult = lendingProcessPaymongoWebhookEvent($simEvent, $simRaw);
$simDuplicate = lendingProcessPaymongoWebhookEvent($simEvent, $simRaw);
$simStatus = (string)$conn->query("SELECT status FROM paymongo_qrph_transactions WHERE payment_intent_id = 'pi_local_simulation_only'")->fetchColumn();
$simPayments = (int)$conn->query('SELECT COUNT(*) FROM payments WHERE loan_id = ' . (int)$simLoan['loan_id'])->fetchColumn();
$simBalance = calculateLoanBalanceSummary($simLoan['loan_id']);
$simReceipt = (string)$conn->query('SELECT receipt_number FROM payments WHERE loan_id = ' . (int)$simLoan['loan_id'] . ' ORDER BY payment_id DESC LIMIT 1')->fetchColumn();
framsRecord([
    'id' => 'PM-SIM-01',
    'category' => 'PayMongo',
    'feature' => 'Local simulated payment.paid event',
    'preconditions' => 'Isolated database. PAYMONGO_MODE forced to test by the test prepend. PayMongo did not deliver this event.',
    'inputs' => 'Local payment.paid payload for 2750.00 PHP, livemode false, source qrph',
    'expected' => 'processed, transaction succeeded, one payment, remaining balance 2750.00',
    'actual' => json_encode(['first' => $simResult, 'status' => $simStatus, 'payments' => $simPayments, 'receipt' => $simReceipt, 'remaining' => $simBalance['remaining_balance']]),
    'difference' => (string)round((float)$simBalance['remaining_balance'] - 2750, 2),
    'status' => ($simResult === 'processed' && $simStatus === 'succeeded' && $simPayments === $paymentsBeforeSim + 1 && framsNear((float)$simBalance['remaining_balance'], 2750)) ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'SIMULATION. This is not a PayMongo payment and not a provider webhook delivery.',
]);
framsRecord([
    'id' => 'PM-SIM-02',
    'category' => 'PayMongo',
    'feature' => 'Duplicate local simulated event',
    'preconditions' => 'Same simulated event sent a second time.',
    'inputs' => 'evt_local_simulation_paid_001 repeated',
    'expected' => 'duplicate, and the payment count stays 1',
    'actual' => json_encode(['duplicate_result' => $simDuplicate, 'payments' => $simPayments]),
    'difference' => '0',
    'status' => ($simDuplicate === 'duplicate' && $simPayments === 1) ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'SIMULATION. Duplicate detection uses the local event id.',
]);

$failedLoan = framsInsertSyntheticLoan($conn, [
    'borrower_id' => $borrowerB,
    'collector_id' => $collectorB,
    'client_id' => $clientB,
    'name' => 'Synthetic QR Failed',
    'loan_number' => 'FRAMS-VERIFY-QR-FAIL',
    'principal' => 5000,
    'interest' => 500,
    'payable' => 5500,
    'installment' => 2750,
    'term_label' => '2 months',
    'frequency' => 'Monthly',
    'released' => '2026-10-19',
    'final_due' => '2026-12-19',
    'schedule' => [
        ['due' => '2026-11-19', 'principal' => 2500, 'interest' => 250, 'total' => 2750, 'status' => 'Upcoming'],
        ['due' => '2026-12-19', 'principal' => 2500, 'interest' => 250, 'total' => 2750, 'status' => 'Upcoming'],
    ],
]);
$conn->prepare("INSERT INTO paymongo_qrph_transactions (internal_reference, payment_intent_id, loan_id, client_id, borrower_user_id, initiated_by_user_id, collector_id, selected_method, amount, penalty_paid, currency, status, provider_status, expires_at) VALUES ('QRPH-LOCAL-SIM-FAIL', 'pi_local_simulation_fail', ?, ?, ?, ?, ?, 'E-Wallet', 2750.00, 0.00, 'PHP', 'pending', 'awaiting_next_action', DATE_ADD(NOW(), INTERVAL 30 MINUTE))")->execute([$failedLoan['loan_id'], $clientB, $borrowerB, $collectorB, $collectorB]);
$failEvent = [
    'id' => 'evt_local_simulation_failed_001',
    'type' => 'payment.failed',
    'livemode' => false,
    'resource' => [
        'id' => 'pay_local_simulation_failed',
        'type' => 'payment',
        'attributes' => [
            'amount' => 275000,
            'currency' => 'PHP',
            'status' => 'failed',
            'livemode' => false,
            'payment_intent_id' => 'pi_local_simulation_fail',
            'source' => ['type' => 'qrph'],
        ],
    ],
];
$failResult = lendingProcessPaymongoWebhookEvent($failEvent, json_encode($failEvent));
$failStatus = (string)$conn->query("SELECT status FROM paymongo_qrph_transactions WHERE payment_intent_id = 'pi_local_simulation_fail'")->fetchColumn();
$failPayments = (int)$conn->query('SELECT COUNT(*) FROM payments WHERE loan_id = ' . (int)$failedLoan['loan_id'])->fetchColumn();
framsRecord([
    'id' => 'PM-SIM-03',
    'category' => 'PayMongo',
    'feature' => 'Local simulated payment.failed event',
    'preconditions' => 'Pending synthetic QR row. No provider call.',
    'inputs' => 'payment.failed for 2750.00',
    'expected' => 'Transaction failed and payment count remains 0.',
    'actual' => json_encode(['result' => $failResult, 'status' => $failStatus, 'payments' => $failPayments]),
    'difference' => '0',
    'status' => ($failStatus === 'failed' && $failPayments === 0) ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'SIMULATION. No receipt was created.',
]);

$mismatchLoanPaymentsBefore = (int)$conn->query('SELECT COUNT(*) FROM payments WHERE loan_id = ' . (int)$manifest['loans']['b']['loan_id'])->fetchColumn();
$conn->prepare("INSERT INTO paymongo_qrph_transactions (internal_reference, payment_intent_id, loan_id, client_id, borrower_user_id, initiated_by_user_id, collector_id, selected_method, amount, penalty_paid, currency, status, provider_status, expires_at) VALUES ('QRPH-LOCAL-SIM-MISMATCH', 'pi_local_simulation_mismatch', ?, ?, ?, ?, ?, 'E-Wallet', 2750.00, 0.00, 'PHP', 'pending', 'awaiting_next_action', DATE_ADD(NOW(), INTERVAL 30 MINUTE))")->execute([$loanBId, $clientB, $borrowerB, $collectorB, $collectorB]);
$mismatchEvent = [
    'id' => 'evt_local_simulation_mismatch_001',
    'type' => 'payment.paid',
    'livemode' => false,
    'resource' => [
        'id' => 'pay_local_simulation_mismatch',
        'type' => 'payment',
        'attributes' => [
            'amount' => 100,
            'currency' => 'PHP',
            'status' => 'paid',
            'livemode' => false,
            'payment_intent_id' => 'pi_local_simulation_mismatch',
            'source' => ['type' => 'qrph'],
        ],
    ],
];
$mismatchResult = lendingProcessPaymongoWebhookEvent($mismatchEvent, json_encode($mismatchEvent));
$mismatchStatus = (string)$conn->query("SELECT status FROM paymongo_qrph_transactions WHERE payment_intent_id = 'pi_local_simulation_mismatch'")->fetchColumn();
$mismatchPayments = (int)$conn->query('SELECT COUNT(*) FROM payments WHERE loan_id = ' . (int)$loanBId)->fetchColumn();
framsRecord([
    'id' => 'PM-SIM-04',
    'category' => 'PayMongo',
    'feature' => 'Local simulated amount mismatch',
    'preconditions' => 'Pending row expects 2750.00. Payload says 1.00.',
    'inputs' => 'payment.paid amount 100 centavos',
    'expected' => 'review_required and no new payment',
    'actual' => json_encode(['result' => $mismatchResult, 'status' => $mismatchStatus, 'payments_before' => $mismatchLoanPaymentsBefore, 'payments_after' => $mismatchPayments]),
    'difference' => (string)($mismatchPayments - $mismatchLoanPaymentsBefore),
    'status' => ($mismatchResult === 'review_required' && $mismatchStatus === 'review_required' && $mismatchPayments === $mismatchLoanPaymentsBefore) ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'SIMULATION.',
]);

$ignored = lendingProcessPaymongoWebhookEvent([
    'id' => 'evt_local_simulation_unknown_001',
    'type' => 'payment.paid',
    'livemode' => false,
    'resource' => [
        'id' => 'pay_unknown',
        'type' => 'payment',
        'attributes' => [
            'amount' => 100,
            'currency' => 'PHP',
            'status' => 'paid',
            'livemode' => false,
            'payment_intent_id' => 'pi_does_not_exist_locally',
            'source' => ['type' => 'qrph'],
        ],
    ],
], '{"simulation":"unknown"}');
framsRecord([
    'id' => 'PM-SIM-05',
    'category' => 'PayMongo',
    'feature' => 'Local simulated unknown payment intent',
    'preconditions' => 'No matching QR row.',
    'inputs' => 'payment.paid for pi_does_not_exist_locally',
    'expected' => 'ignored',
    'actual' => $ignored,
    'difference' => '0',
    'status' => $ignored === 'ignored' ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'SIMULATION. The event row is stored as ignored.',
]);

$apiMessage = '';
$apiStatus = '';
$apiPaymentsBefore = (int)$conn->query('SELECT COUNT(*) FROM payments')->fetchColumn();
try {
    lendingCreatePaymongoQrphTransaction((int)$failedLoan['loan_id'], $collectorB, 'E-Wallet', 100.0, 0.0);
    $apiMessage = 'created without error';
} catch (Throwable $e) {
    $apiMessage = framsRedact($e->getMessage());
}
$apiRow = $conn->query('SELECT status, provider_status FROM paymongo_qrph_transactions WHERE loan_id = ' . (int)$failedLoan['loan_id'] . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$apiPaymentsAfter = (int)$conn->query('SELECT COUNT(*) FROM payments')->fetchColumn();
if (isset($apiRow['status'])) {
    $apiStatus = (string)$apiRow['status'] . '/' . (string)$apiRow['provider_status'];
}
$conn->exec("UPDATE paymongo_qrph_transactions SET failure_message = '[redacted]' WHERE failure_message IS NOT NULL AND failure_message <> ''");
$apiPass = $apiPaymentsBefore === $apiPaymentsAfter && ($apiRow['status'] ?? '') === 'failed' && str_contains($apiMessage, 'HTTP 401');
framsRecord([
    'id' => 'PM-SIM-06',
    'category' => 'PayMongo',
    'feature' => 'Invalid API credential attempt',
    'preconditions' => 'Test process uses a synthetic invalid test key. The live key in .env was not loaded.',
    'inputs' => 'Create a 100.00 QR Ph payment intent',
    'expected' => 'Provider authentication fails, the QR row is failed, and no repayment is posted.',
    'actual' => json_encode(['message' => $apiMessage, 'row' => $apiStatus, 'payments_before' => $apiPaymentsBefore, 'payments_after' => $apiPaymentsAfter]),
    'difference' => (string)($apiPaymentsAfter - $apiPaymentsBefore),
    'status' => $apiPass ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'This contacted api.paymongo.com with an invalid test key. It is not a successful QR code and not a live charge. Failure text stored in the test database was redacted after capture.',
]);

$loggedEvents = (int)$conn->query("SELECT COUNT(*) FROM paymongo_qrph_webhook_events WHERE event_id LIKE 'evt_local_simulation_%'")->fetchColumn();
framsRecord([
    'id' => 'PM-SIM-07',
    'category' => 'PayMongo',
    'feature' => 'Webhook event logging',
    'preconditions' => 'Local simulations above.',
    'inputs' => 'count of evt_local_simulation_% rows',
    'expected' => 'At least 4 logged events',
    'actual' => (string)$loggedEvents,
    'difference' => '0',
    'status' => $loggedEvents >= 4 ? 'PASS' : 'FAIL',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'SIMULATION log rows. These are not provider delivery records.',
]);

framsRecord([
    'id' => 'PM-SIM-08',
    'category' => 'PayMongo',
    'feature' => 'Live payment creation and external webhook delivery',
    'preconditions' => 'Local workstation. No public HTTPS tunnel is configured by this run.',
    'inputs' => 'none sent with a live key',
    'expected' => 'No live charge and no claim of provider delivery.',
    'actual' => 'Not executed against live PayMongo. External delivery was not attempted.',
    'difference' => 'n/a',
    'status' => 'BLOCKED',
    'evidence' => 'testing/evidence/verification_results.json',
    'notes' => 'The project .env is in live mode and its webhook secret is empty. Those values were not used. A tunnel and test-mode webhook would be required before a real provider event can be verified.',
]);

$outDir = dirname(__DIR__) . '/evidence';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
$counts = ['PASS' => 0, 'FAIL' => 0, 'BLOCKED' => 0, 'NOT_EXECUTED' => 0];
foreach ($results as $row) {
    $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
}
$payload = [
    'started_at' => $started,
    'finished_at' => date('c'),
    'database' => 'frams_capstone_test',
    'production_database_used' => false,
    'counts' => $counts,
    'executed' => count($results),
    'results' => $results,
];
file_put_contents($outDir . '/verification_results.json', json_encode($payload, JSON_PRETTY_PRINT));
echo 'WROTE ' . count($results) . ' results' . PHP_EOL;
