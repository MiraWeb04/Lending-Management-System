# FRAMS Unit Testing Report

Execution date: 18 October 2026

Results below come from the recorded test run against the isolated database `frams_capstone_test`. The existing `lending_management` database was not modified.

## Counts

| Executed | Passed | Failed | Blocked | Not Executed | Pass rate |
| --- | --- | --- | --- | --- | --- |
| 45 | 43 | 2 | 0 | 0 | 95.56% of 45 completed pass/fail tests |

Pass rate uses passed tests divided by passed plus failed tests. Blocked tests are not treated as passed.

## Test cases

| ID | Scenario | Expected | Actual | Status | Evidence | Remarks |
| --- | --- | --- | --- | --- | --- | --- |
| UT-001 | Standard monthly interest rate | 5.0 percent | 5 | PASS | logs/cli_test_execution.log |  |
| UT-002 | Twelve-month interest breakdown | monthly 500.00, interest 6000.00, payable 16000.00 | {"monthly_interest":500,"total_interest":6000,"total_payable":16000} | PASS | logs/cli_test_execution.log | Formula: monthly = principal x rate/100; total interest = monthly x months; payable = principal + total interest. |
| UT-003 | Three-month interest breakdown | monthly 500.00, interest 1500.00, payable 11500.00 | {"monthly_interest":500,"total_interest":1500,"total_payable":11500} | PASS | logs/cli_test_execution.log |  |
| UT-004 | Zero principal | all amounts 0.00 | {"monthly_interest":0,"total_interest":0,"total_payable":0} | PASS | logs/cli_test_execution.log |  |
| UT-005 | Negative principal | payable is 0.00 | {"monthly_interest":0,"total_interest":0,"total_payable":0} | PASS | logs/cli_test_execution.log | The implementation clamps principal with max(0, amount) instead of throwing. |
| UT-006 | Negative interest rate | interest 0.00 and payable 8000.00 | {"monthly_interest":0,"total_interest":0,"total_payable":8000} | PASS | logs/cli_test_execution.log | Negative rates are clamped to zero. |
| UT-007 | Zero loan duration | Invalid duration is rejected or does not create one month of interest. | {"monthly_interest":500,"total_interest":500,"total_payable":10500} | FAIL | logs/cli_test_execution.log | max(1, term) coerces zero months to one month and charges interest of 500.00. |
| UT-008 | Negative loan duration | Invalid duration does not produce a one-month charge. | {"monthly_interest":500,"total_interest":500,"total_payable":10500} | FAIL | logs/cli_test_execution.log | Same coercion as UT-007. |
| UT-009 | Decimal rounding | monthly 5.02, interest 15.05, payable 115.38 | {"monthly_interest":5.02,"total_interest":15.05,"total_payable":115.38} | PASS | logs/cli_test_execution.log | Each returned field is rounded separately. Rounded monthly interest times months is 15.06, while total interest remains 15.05. |
| UT-010 | Policy maximum principal | monthly 25000.00, interest 300000.00, payable 800000.00 | {"monthly_interest":25000,"total_interest":300000,"total_payable":800000} | PASS | logs/cli_test_execution.log |  |
| UT-011 | Loan term label parsing | 12 | 12 | PASS | logs/cli_test_execution.log |  |
| UT-012 | Non-numeric loan term | 0 | 0 | PASS | logs/cli_test_execution.log |  |
| UT-013 | Numeric zero term parsing | The parser returns 1 because numeric input uses max(1, value). | 1 | PASS | logs/cli_test_execution.log | This matches the coercion observed in UT-007. |
| UT-014 | Payment frequency normalization | semi-monthly, weekly, monthly | checked three labels | PASS | logs/cli_test_execution.log |  |
| UT-015 | Installment counts | 3, 12, 6, and 60 | monthly 3, weekly 12, semi-monthly 6, daily 60 | PASS | logs/cli_test_execution.log | Daily count without explicit dates is term months times 30. |
| UT-016 | Monthly due date | 2026-03-15 | 2026-03-15 | PASS | logs/cli_test_execution.log |  |
| UT-017 | Penalty rates by frequency | 1, 2, 3, and 5 percent | 1, 2, 3, 5 | PASS | logs/cli_test_execution.log |  |
| UT-018 | Installment amount from total payable | 3833.33 | 3833.33 | PASS | logs/cli_test_execution.log |  |
| UT-019 | Scheduled installment override | 4000.00 | 4000 | PASS | logs/cli_test_execution.log |  |
| UT-020 | Password policy | true, false, false | valid accepted; short and alphanumeric rejected | PASS | logs/cli_test_execution.log |  |
| UT-021 | Password verification | hash matches; wrong password and plaintext storage do not | hash match true; plaintext comparison false | PASS | logs/cli_test_execution.log |  |
| UT-022 | CSRF token comparison | true, false, false | matching accepted; mismatch and empty rejected | PASS | logs/cli_test_execution.log |  |
| UT-023 | Valid webhook signature | true | true | PASS | logs/cli_test_execution.log | Local function test. No PayMongo request. |
| UT-024 | Invalid webhook signature | false | false | PASS | logs/cli_test_execution.log |  |
| UT-025 | Missing webhook signature | false | false | PASS | logs/cli_test_execution.log |  |
| UT-026 | Expired webhook timestamp | false | false | PASS | logs/cli_test_execution.log |  |
| UT-027 | Malformed webhook JSON | null | null | PASS | logs/cli_test_execution.log |  |
| UT-028 | Valid webhook event parse | id and type extracted | evt_parse payment.paid | PASS | logs/cli_test_execution.log |  |
| UT-029 | Webhook amount match | true then false | match/no-match | PASS | logs/cli_test_execution.log |  |
| UT-030 | QR image URL allow list | only the PayMongo HTTPS URL is safe | safe=yes unsafe-accepted=no | PASS | logs/cli_test_execution.log |  |
| UT-031 | Proposed monthly payment | 1333.33 | 1333.33 | PASS | logs/cli_test_execution.log | 16000 / 12 = 1333.333..., rounded to 1333.33. |
| UT-032 | Maximum principal from payment | approximately 9999.98 | 9999.98 | PASS | logs/cli_test_execution.log | Rounding of the forward payment prevents an exact 10000 reverse value. |
| UT-033 | Loan amount policy clamp | 3000.00 | 3000 | PASS | logs/cli_test_execution.log |  |
| UT-034 | Employment length parsing | 24 and 2 | 24 and 2 | PASS | logs/cli_test_execution.log |  |
| UT-035 | Eligible affordability estimate | Eligible | Eligible | PASS | logs/cli_test_execution.log | DTI after observed: 11.11 |
| UT-036 | Incomplete eligibility input | Not Eligible and status_code incomplete | Not Eligible/incomplete | PASS | logs/cli_test_execution.log |  |
| UT-037 | DTI above policy limit | Not Eligible | Not Eligible DTI 66.67 | PASS | logs/cli_test_execution.log |  |
| UT-038 | Conditional eligibility | Conditionally Eligible | Conditionally Eligible | PASS | logs/cli_test_execution.log |  |
| UT-039 | Existing debt blocks the wizard | a non-empty blocking message | blocked | PASS | logs/cli_test_execution.log |  |
| UT-040 | Missing upload file | ok false | rejected | PASS | logs/cli_test_execution.log | No file uploaded. |
| UT-041 | No penalty before the due date | 0.00 | 0 | PASS | logs/cli_test_execution.log |  |
| UT-042 | Initial loan balance | payable 11500, paid 0, remaining 11500 | {"total_payable":11500,"total_paid":0,"penalty_paid":0,"remaining_balance":11500,"penalty_due":0,"total_amount_due":11500} | PASS | logs/cli_test_execution.log |  |
| UT-043 | Zero payment amount | InvalidArgumentException | rejected | PASS | logs/cli_test_execution.log |  |
| UT-044 | Negative payment amount | rejected as a non-positive amount | rejected | PASS | logs/cli_test_execution.log | The validator clamps the input to zero and then rejects it. |
| UT-045 | Overpayment rejection | exception and payment count unchanged | rejected; count 0 to 0 | PASS | logs/cli_test_execution.log |  |

## Findings in this category

### F-001 — Low

- Component: calculateLoanInterestBreakdown
- Description: A zero or negative loan term is coerced to one month, so the calculator charges one month of interest instead of rejecting the duration.
- Reproduction: Call calculateLoanInterestBreakdown(10000, 5, 0). Observed total interest 500.00 and total payable 10500.00.
- Recommendation: Reject a term less than 1 at the calculator and at the application form before interest is stored.
- Evidence: `01_Unit_Testing/logs/cli_test_execution.log`
- Demonstrated: Yes
- Retest: Not retested. Application code was not changed.
