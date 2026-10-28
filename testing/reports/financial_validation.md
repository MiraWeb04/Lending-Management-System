# Financial computation results

Execution date: 19 October 2026

Expected amounts were calculated with BCMath, half-up to centavos, from the documented flat formula. The application functions were then called and compared. Payment cases ran only in `frams_capstone_test`. `lending_management` row counts were the same after the run as before it.

Formula confirmed in code before it was used as the expected result:

- Monthly interest = principal × (monthly rate / 100)
- Total interest = monthly interest × months
- Total payable = principal + total interest
- The monthly rate constant is 5

That formula does not change with payment frequency. A daily loan is still charged 5% of the original principal for each month of the term.

## Counts from this run

| Executed | Passed | Failed | Blocked | Not executed |
| --- | ---: | ---: | ---: | ---: |
| 34 financial cases (FIN-01 through FIN-34) | 26 | 8 | 0 | 0 |

Full rows are in `testing/reports/executed_test_results.csv` and `testing/evidence/verification_results.json`.

## Passed calculations

| Test | Inputs | Expected | Actual | Difference |
| --- | --- | --- | --- | --- |
| FIN-01 | 10,000 at 5% for 12 months | 500.00 / 6,000.00 / 16,000.00 | same | 0 |
| FIN-02 | 10,000 at 5% for 3 months | 500.00 / 1,500.00 / 11,500.00 | same | 0 |
| FIN-03 | 500,000 at 5% for 12 months | 25,000.00 / 300,000.00 / 800,000.00 | same | 0 |
| FIN-04 | 100.33 at 5% for 3 months | 5.02 / 15.05 / 115.38 | same | 0 |
| FIN-05 | 8,000 at 5% for 1 month | 400.00 / 400.00 / 8,400.00 | same | 0 |
| FIN-06 | principal 0 | all zero | all zero | 0 |
| FIN-07 | principal −5,000 | no positive loan | payable 0.00 | clamped, not rejected |
| FIN-08 | rate −4 | payable equals principal 8,000.00 | same | 0 |
| FIN-11 | principal 1,000,000,000,000 | exact 5% flat result | same | 0 |
| FIN-12 | term parsing | 12, 0, and numeric 0 becomes 1 | same | 0 |
| FIN-13 | rate constant | 5.0 | 5 | 0 |
| FIN-14 | four frequencies, same 10,000 / 3 months | payable stays 11,500.00; counts 3, 12, 6, 90 | same | 0 |
| FIN-18 | penalty rates | 1, 2, 3, 5 | same | 0 |
| FIN-20 | proposed payment on 10,000 / 12 | 1,333.33 | 1,333.33 | 0 |
| FIN-21 | reverse principal from 1,333.33 | 9,999.98 | 9,999.98 | 0 |
| FIN-22 | affordable 10,000 request | Eligible, DTI 4.44, max principal 90,000.00 | same | 0 |
| FIN-23 | request that lands on 40.00% DTI | Eligible, payment 4,000.00 | same | 0 |
| FIN-24 | 31,000 request on 10,000 income | Not Eligible, DTI 41.33 | same | 0 |
| FIN-26 | income and amount omitted | Not Eligible / incomplete | same | 0 |
| FIN-27 | employment of 1 month, otherwise affordable | Conditionally Eligible | same | 0 |
| FIN-28 | pay 2,000 of 11,500 | remaining 9,500.00, receipt `RJRR-*` | same | 0 |
| FIN-29 | pay 12,000 of 11,500 | rejected, no row | rejected, count stayed 0 | 0 |
| FIN-30 | pay 0 and −25 | both rejected | both rejected | 0 |
| FIN-31 | two overdue 1,000 installments at 5% | penalty 100.00 | 100 | 0 |
| FIN-32 | pay 80, all designated penalty | scheduled balance stays 2,000.00, penalty still due 20.00 | same | 0 |
| FIN-33 | pay 150 of which 50 is penalty | scheduled balance 900.00, penalty due 0.00 | same | 0 |

FIN-04 rounds the unrounded product. Rounded monthly interest is 5.02, and 5.02 × 3 = 15.06, while the stored total interest is 15.05. The test passed against the unrounded formula. The one-cent display difference remains a rounding limitation.

FIN-14 passed the documented formula. It does not show that 5% per month is an appropriate price for a daily or weekly product.

FIN-28 through FIN-33 used synthetic loans. Client email was null, so no borrower email was sent. A partial payment leaves the installment `Upcoming` until the installment total is covered or the due date has passed. Overpayments are rejected rather than stored as credit.

## Failed calculations

| Test | Expected | Actual | Difference | Why it failed |
| --- | --- | --- | --- | --- |
| FIN-09 | A zero-month term is rejected | payable 10,500.00 | one month of interest was charged | `max(1, term)` |
| FIN-10 | A negative term is rejected | payable 10,500.00 | same coercion | `max(1, term)` |
| FIN-15 | 3 monthly installments sum to 11,500.00 | dates were correct; sum 11,499.99 | −0.01 | each installment rounded to 3,833.33 and the remainder was not put on the last row |
| FIN-16 | 12 weekly dates, 7 days apart, sum 11,500.00 | 12 rows, sum 11,499.96, last gap 13 days | −0.04 and a broken weekly cadence | the last due date is replaced with the calendar term end, 15 April 2026 instead of the seventh-day step |
| FIN-17 | 30 daily dates stay one day apart and sum to 10,500.00 | count 30 and sum 10,500.00, but the last date is 15 February 2026 | last date is one day later than the daily step, which ended 14 February 2026 | same final-date overwrite |
| FIN-19 | Public calculator matches the 5% flat engine | public page shows 8,560.75; engine installment is 13,333.33 | −4,772.59 | `index.php` divides the labeled monthly rate by 12 and uses an amortizing formula |
| FIN-25 | Affordable principal is 750.00 | reported maximum eligible is 3,000.00; status Not Eligible; DTI after the 3,000 request is 46% | +2,250.00 | `clampLoanAmountToPolicy()` raises the affordable amount to the 3,000 minimum |
| FIN-34 | Loans-page outstanding equals remaining balances | loans-page formula 43,000.00; balance function 40,900.00 | +2,100.00 | `loans_lending.php` sums `total_payable` for loans that are not Paid and does not subtract payments |

FIN-19 was also visible in the browser on the isolated server. For 100,000 over 12 months at the labeled 5% per month, the page displayed ₱8,560.75. Screenshot: `testing/evidence/browser/FIN-19_public_calculator.png`.

## Not executed as financial cases

No production loan was recalculated. No PDF receipt was opened. Email delivery was not tested because the synthetic clients have no email address. A declining-balance interest method is not implemented, so it was not tested.
