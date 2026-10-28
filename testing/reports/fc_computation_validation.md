# Financial computation validation

Execution date: 20 October 2026

PHP 8.2.12. Payment and eligibility cases used the isolated database `frams_capstone_test`. Row counts in `lending_management` were compared before and after the run across 20 tables and were unchanged. No production PHP file was edited. No PayMongo charge was sent.

Expected amounts were calculated with BCMath in `testing/harness/run_fc_validation.php` and were not taken from the application functions. Application functions were called afterward. Currency tolerance is 0.00 after half-up rounding to two decimal places. A difference of one centavo is a failure.

Machine-readable rows: `testing/evidence/fc_validation_results.json` and `testing/reports/fc_computation_results.csv`.

## A. What was executed

| Executed rows | Passed | Failed | Blocked | Not executed |
| --- | ---: | ---: | ---: | ---: |
| 25 | 19 | 6 | 0 | 0 |

These 25 rows cover FC-01 through FC-10. They do not cover the whole lending system, the loans-page portfolio total, screen entry of a payment, or a browser session.

## B. Manuscript table

| Test | Expected result | Actual system result | Difference | Outcome |
| --- | --- | --- | --- | --- |
| FC-01a | 10,000 at 5% for 12 months: interest 500.00, total interest 6,000.00, payable 16,000.00 | 500.00 / 6,000.00 / 16,000.00 | 0.00 | PASS |
| FC-01b | 10,000 at 5% for 3 months: 500.00 / 1,500.00 / 11,500.00 | same | 0.00 | PASS |
| FC-01c | 8,000 at 5% for 1 month: 400.00 / 400.00 / 8,400.00 | same | 0.00 | PASS |
| FC-02a | 3 monthly rows of 3,833.33; last due 2026-04-15; even-split sum 11,499.99 | 3 rows, 3,833.33, last due 2026-04-15, sum 11,499.99 | 0.00 against the even split | PASS |
| FC-02b | 12 weekly rows of 958.33; last due 2026-04-15; even-split sum 11,499.96 | same count, amount, and last date | 0.00 against the even split | PASS |
| FC-02c | Flat installment 13,333.33 on payable 160,000.00 | Public calculator expression returns 8,560.75 | −4,772.58 | FAIL |
| FC-03 | Pay 2,000.00 of 11,500.00; remaining 9,500.00 | amount_paid 2,000.00; remaining 9,500.00 | 0.00 | PASS |
| FC-04 | Receipt 2,500.00 splits into scheduled 2,000.00 and penalty 500.00; remaining 8,000.00 | penalty due before 500.00; amount_paid 2,000.00; penalty_paid 500.00; remaining 8,000.00 | 0.00 | PASS |
| FC-05a | 100.33 at 5% for 3 months: 5.02 / 15.05 / 115.38 | 5.02 / 15.05 / 115.38 | 0.00 | PASS |
| FC-05b | Schedule rows sum to payable 11,500.00 | sum 11,499.99; last row 3,833.33 | −0.01 | FAIL |
| FC-05c | 12 weekly rows sum to payable 11,500.00 | sum 11,499.96 | −0.04 | FAIL |
| FC-06a | DTI 40.00, Eligible | DTI 40.00, Eligible | 0.00 | PASS |
| FC-06b | DTI 38.67, Eligible | DTI 38.67, Eligible | 0.00 | PASS |
| FC-06c | DTI 41.33, Not Eligible | DTI 41.33, Not Eligible | 0.00 | PASS |
| FC-07a | Affordable principal 60,000.00; request 60,000.00; Eligible | maximum 60,000.00; Eligible | 0.00 | PASS |
| FC-07b | Request 45,000.00 is below 60,000.00; DTI 30.00; Eligible | same | 0.00 | PASS |
| FC-07c | Request 60,000.50 is above 60,000.00; displayed DTI 40.00; Conditionally Eligible | same | 0.00 | PASS |
| FC-07d | DTI-safe principal 750.00 | reported maximum 3,000.00; DTI at 3,000.00 is 43.00 and Not Eligible | +2,250.00 | FAIL |
| FC-08 | Request 31,000.00; DTI 41.33; affordable principal 30,000.00; Not Eligible | Not Eligible; DTI 41.33; maximum 30,000.00 | 0.00 | PASS |
| FC-09 | Two payments, 2,000.00 then 3,000.00; remaining 6,500.00 | two rows; paid sum 5,000.00; remaining 6,500.00 | 0.00 | PASS |
| FC-10a | Payments of 0.00, −25.00, and 2,000.01 rejected; no new row | all rejected; rows added 0 | 0 | PASS |
| FC-10b | Exact payment of 2,000.00 leaves 0.00 and status Paid | remaining 0.00; status Paid | 0.00 | PASS |
| FC-10c | Zero principal payable 0.00 | 0.00 | 0.00 | PASS |
| FC-10d | Negative principal is not a documented loan | accepted and clamped; payable 0.00 | input was not rejected | FAIL |
| FC-10e | Zero-month term does not add a month of interest; payable 10,000.00 | payable 10,500.00 | +500.00 | FAIL |

## C. Independent calculation

Documented flat interest, confirmed in `calculateLoanInterestBreakdown` before it was used as the expected rule:

- Monthly interest = principal × (monthly rate / 100)
- Total interest = monthly interest × number of months
- Total payable = principal + total interest
- The rate constant is 5 percent
- Each stored money figure is half-up to two decimal places

The public rate label says “% per month”, but the expression in `index.php` divides that rate by 12 and uses an amortizing payment. That expression was executed in PHP for FC-02c. This run did not open the page in a browser.

Installment count is the term in months, times 4 for weekly, times 2 for semi-monthly, and times 30 for daily when both dates are not supplied. Principal and the already rounded total interest are divided by that count. The last due date is then replaced with the release date plus the term in calendar months. FC-02a and FC-02b passed against that written rule. Their sums are still short of total payable, which is FC-05.

Debt-to-income after the loan is existing monthly obligations plus the proposed monthly payment, divided by gross income, times 100. The proposed payment is total payable divided by the term in months. The affordable principal is the room under 40 percent of income, multiplied by the term, then divided by 1 + (rate / 100 × term).

Worked DTI cases, gross income 10,000.00, no existing debt, 12 months:

- 30,000.00 → payable 48,000.00 → payment 4,000.00 → 40.00 percent
- 29,000.00 → payable 46,400.00 → payment 3,866.67 → 38.67 percent
- 31,000.00 → payable 49,600.00 → payment 4,133.33 → 41.33 percent

Worked affordable principal, gross income 20,000.00, no existing debt, 12 months:

- Room = 8,000.00
- Principal = (8,000.00 × 12) / 1.60 = 60,000.00

FC-04 penalty, before the payment function was called:

- One overdue monthly installment of 10,000.00
- Penalty = 10,000.00 × 0.05 = 500.00
- Receipt 2,500.00 allocated as scheduled 2,000.00 and penalty 500.00
- Remaining = 10,000.00 − 2,000.00 = 8,000.00

The overdue date used by the run was 19 October 2026, one day before the PHP date 20 October 2026.

## D. Tolerance

Pass requires the same centavo after half-up rounding. No wider tolerance was allowed. FC-05a also records that 5.02 × 3 = 15.06, which is 0.01 above the stored total interest of 15.05. That comparison is a note. The pass is against rounding of the unrounded product 15.0495.

## E. Failed tests

1. FC-02c. The public calculator does not use the flat installment. For 100,000.00 over 12 months at the labeled 5 percent, the page expression returns 8,560.75 and the loan engine installment is 13,333.33.
2. FC-05b. Three monthly installments of 3,833.33 sum to 11,499.99. Total payable is 11,500.00. The last row is not raised to 3,833.34.
3. FC-05c. Twelve weekly installments of 958.33 sum to 11,499.96, which is 0.04 below 11,500.00.
4. FC-07d. Income 10,000.00 and existing debt 3,900.00 leave room for a 100.00 payment. The principal that stays at 40 percent is 750.00. `clampLoanAmountToPolicy()` reports 3,000.00. A request for that 3,000.00 figure has DTI 43.00 percent and is Not Eligible. The request for 750.00 is Conditionally Eligible because it is below the product minimum, and its DTI is 40.00 percent.
5. FC-10d. Principal −5,000.00 is accepted. The literal formula would produce −5,250.00. The function returns 0.00 and does not raise a validation error.
6. FC-10e. A term of 0 months is replaced with 1 month. Payable on 10,000.00 becomes 10,500.00 instead of 10,000.00.

## F. Payment allocation

FC-04 passed on a synthetic loan in `frams_capstone_test`. The scheduled balance decreased by 2,000.00, not by 2,500.00. The penalty was stored in `payments.penalty_paid`. Remaining balance is `loans.total_payable` minus the sum of `payments.amount_paid`, so the penalty is not deducted from the scheduled payable. After the 500.00 penalty was recorded, penalty still due was 0.00.

This holds when the installment is already overdue and the penalty due is at least the designated penalty. The code limits `penalty_paid` to the penalty still due. A designated penalty larger than the amount due is reduced before the split.

## G. What was not executed

- No payment was posted through the payment screen.
- The public calculator was not opened in a browser in this run. FC-02c executed the JavaScript expression in PHP.
- The loans-page outstanding query was inspected and not run. `loans_lending.php` lines 204–205 sum `total_payable` for loans whose status is not Paid. That sum does not subtract payments. `calculateLoanBalanceSummary()` does subtract payments. Those two figures were not measured again in this run.
- No original borrower row was updated.

## H. Source locations

| Behavior | Location |
| --- | --- |
| Flat interest and payable | `lending_system/includes/loan_helpers.php`, lines 16–35 |
| Term coerced to at least 1 month | same file, line 25 |
| Negative principal clamped to 0 | same file, line 23 |
| Installment count and penalty rates | same file, lines 176–226 |
| Overdue penalty | same file, lines 228–286 |
| Remaining balance | same file, lines 319–353 |
| Payment split | `lending_system/includes/db_lending.php`, lines 448–466 |
| Balance reaches Paid | same file, lines 211–243 |
| Schedule rows and final due date | `lending_system/loan_release_lending.php`, lines 177–221 |
| DTI, affordable principal, classification | `lending_system/includes/loan_eligibility_helpers.php`, lines 74–227 |
| Minimum-loan clamp | same file, lines 100–106 |
| Public amortizing expression | `lending_system/index.php`, lines 372–377 |
| Stored payment columns | `payments.amount_paid`, `payments.penalty_paid`, `payments.interest_paid` |
| Stored loan total | `loans.total_payable` |
| Schedule amount used for penalty | `loan_payment_schedules.total_amount_due` |

## I. Recommended corrections

These were not applied.

1. Reject a term below 1 month instead of charging one month of interest.
2. Reject a negative principal instead of silently changing it to zero.
3. Put the leftover centavos on the last installment so the schedule sum equals total payable. For the 10,000.00 / 3-month monthly case, the last row would be 3,833.34. For the weekly case, the last row would be 958.37.
4. Make the public calculator use the flat installment, or label it as an amortizing illustration that is not the loan price.
5. Report the DTI-safe principal even when it is below 3,000.00. Keep the 3,000.00 minimum as a separate product-limit message. Do not present 3,000.00 as the maximum eligible amount when that amount fails the 40 percent test.

## Methodology note for Chapter IV

Expected values were computed from the written flat-interest, debt-to-income, and penalty rules with a decimal calculator, then compared with the values returned by the PHP functions and with payment rows stored in the isolated database. A case passed only when the observed value matched that independent result to the centavo, or when an invalid payment was rejected and left the payment count unchanged. Twenty-five cases were executed. Nineteen passed and six failed. This does not establish that every screen, report, or loan in the office database is correct.
