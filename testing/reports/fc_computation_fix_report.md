# Financial computation fix report

Date: 20 October 2026

The flat 5% interest rule was kept. Production borrower rows in `lending_management` were not modified. After the corrections, `testing/harness/run_fc_validation.php` ran 28 cases on `frams_capstone_test`: 28 passed, 0 failed, 0 blocked. Source-database row counts were unchanged.

## Results after the fix

| Test | Expected | Actual | Outcome |
| --- | --- | --- | --- |
| FC-01a–c | Flat 5% interest and payable | Same centavo values | PASS |
| FC-02a | Monthly rows sum to 11,500.00; last due 2026-04-15 | Sum 11,500.00; last row 3,833.34 | PASS |
| FC-02b | Weekly rows sum to 11,500.00 | Sum 11,500.00 | PASS |
| FC-02c | Installment 13,333.33; final installment 13,333.37; payable 160,000.00 | Same, and `index.php` no longer uses the amortizing expression | PASS |
| FC-03 | Partial payment leaves 9,500.00 | 9,500.00 | PASS |
| FC-04 | 2,500.00 receipt splits into 2,000.00 scheduled and 500.00 penalty; balance 8,000.00 | Same | PASS |
| FC-05a | 100.33 for 3 months: 5.02 / 15.05 / 115.38 | Same | PASS |
| FC-05b | Monthly schedule sum 11,500.00; last installment 3,833.34 | Same | PASS |
| FC-05c | Weekly schedule sum 11,500.00 | 11,500.00 | PASS |
| FC-06a–c | DTI 40.00 Eligible; 38.67 Eligible; 41.33 Not Eligible | Same | PASS |
| FC-07a–c | Affordable principal 60,000.00; at, below, and just above that amount | Eligible, Eligible, Conditionally Eligible | PASS |
| FC-07d | DTI-safe principal 750.00; both 750.00 and 3,000.00 are Not Eligible | Maximum 750.00; Not Eligible; DTI at 3,000.00 is 43.00 | PASS |
| FC-07e | Request 600,000.00 is capped at 500,000.00 | Maximum 500,000.00; Conditionally Eligible | PASS |
| FC-08 | Request above both DTI and affordable principal | Not Eligible; DTI 41.33 | PASS |
| FC-09 | Two payments leave 6,500.00 | 6,500.00 | PASS |
| FC-10a | Zero, negative, and over-limit payments rejected | Rejected; no row added | PASS |
| FC-10b | Exact payment leaves 0.00 and status Paid | Paid | PASS |
| FC-10c | Zero principal payable 0.00 | 0.00 | PASS |
| FC-10d | Negative principal rejected | Interest and schedule functions throw | PASS |
| FC-10e | Zero-month term rejected | Interest and schedule functions throw | PASS |
| FC-10f | Negative term rejected; 12-month payable stays 16,000.00 | Rejected; payable 16,000.00 | PASS |
| FC-11 | Outstanding for the partial loan 9,500.00; Paid loan 0.00 | Same | PASS |

Evidence: `testing/evidence/fc_validation_results.json` and `testing/reports/fc_computation_results.csv`.

## FC-02c — public calculator

Original defect: for 100,000.00 over 12 months at the labeled 5%, the public page showed 8,560.75. The loan engine installment is 13,333.33. Difference −4,772.58.

Root cause: `index.php` divided the monthly rate by 12 and used an amortizing formula.

Correction: `buildPublicLoanQuote()` in `includes/loan_helpers.php` uses `calculateLoanInterestBreakdown()`. `loan_quote_lending.php` returns that quote. The page requests it and shows principal, term, monthly interest, total interest, total payable, the regular installment, and the final installment when the remainder makes it different. For this example the regular installment is 13,333.33, the final installment is 13,333.37, and total payable is 160,000.00.

The automated run executed the quote function and checked that `index.php` no longer contains the amortizing expression. It did not open a browser. The later browser section records the same 100,000.00 / 12-month example on the public page.

## FC-05b and FC-05c — installment rounding

Original defect: 10,000.00 for 3 months produced monthly rows totaling 11,499.99 and weekly rows totaling 11,499.96. Total payable is 11,500.00.

Root cause: every row was rounded independently, and the last row did not receive the leftover centavos.

Correction: `allocateEvenMoneyRows()` gives earlier rows the truncated centavo share and gives the last row the remainder. Principal, interest, and total payable each keep their own rounded totals. Due-date stepping and the final calendar-month due date were left as they were.

After the fix, the monthly last installment is 3,833.34 and the schedule sums to 11,500.00. The weekly schedule also sums to 11,500.00.

## FC-07d — affordable principal

Original defect: income 10,000.00 and existing debt 3,900.00 support a principal of 750.00. The estimate reported 3,000.00.

Root cause: `clampLoanAmountToPolicy()` raised every result to the 3,000.00 product minimum.

Correction: the cap now stops an amount at the 500,000.00 maximum and does not raise it to 3,000.00. When the DTI-safe principal is below the minimum product, the status is Not Eligible. The 40% threshold is unchanged. A request above the affordable amount remains Conditionally Eligible when DTI is still within the limit. A request above 500,000.00 is capped at 500,000.00 and is Conditionally Eligible, which is an estimate rather than an approval.

## FC-10d and FC-10e — invalid principal and term

Original defect: a principal of −5,000.00 became a payable of 0.00. A term of 0 months was charged as one month, producing 10,500.00 on a 10,000.00 principal.

Root cause: `calculateLoanInterestBreakdown()` used `max(0, principal)` and `max(1, term)`.

Correction: a negative principal and a term below 1 month throw `InvalidArgumentException`. `buildPaymentSchedule()` throws the same errors, so those values do not become a schedule. Zero principal is still a zero payable. A valid 12-month term is unchanged.

Loan release shows an error and does not release an explicit zero or negative term. Approval loan creation returns no loan for those values. A blank term is no longer replaced with 12 months on release, approval, or agreement generation. The term section below records that correction.

## Payment and penalty

`recordPaymentTransaction()` and `calculateLoanBalanceSummary()` were not changed. The retest still records a 2,500.00 receipt as 2,000.00 scheduled repayment and 500.00 penalty, with 8,000.00 remaining. Partial payments, two payments, an exact payoff, and rejected zero, negative, and excess payments also passed.

## Portfolio outstanding

The Outstanding card on the loans page was summing `total_payable` for loans whose status was not Paid. The label describes an open balance, so the query now uses `outstandingReceivableSelectSql()`: total payable minus `payments.amount_paid`, with Paid loans at 0. Penalty amounts are not subtracted from that balance. On the synthetic partial loan the result is 9,500.00. The separate Collected card still includes penalty cash and was not changed.

## Files changed

- `lending_system/includes/loan_helpers.php`
- `lending_system/includes/loan_eligibility_helpers.php`
- `lending_system/loan_release_lending.php`
- `lending_system/index.php`
- `lending_system/loan_quote_lending.php`
- `lending_system/loans_lending.php`
- `lending_system/loan_applications_lending.php`
- `lending_system/borrower_loan_application_lending.php`
- `lending_system/client_details_lending.php`
- `lending_system/loan_details_lending.php`
- `lending_system/loan_release_receipt_lending.php`
- `lending_system/borrower_loan_agreement_lending.php`
- `lending_system/includes/agreement_helpers.php`
- `testing/harness/run_fc_validation.php`
- `testing/harness/run_fc_term_validation.php`
- `testing/harness/run_fc_agreement_due_release_validation.php`

Expected amounts were not loosened. FC-10d and FC-10e now expect rejection because that is the required rule. FC-02a and FC-02b now expect the last-row remainder because the schedule must equal total payable. FC-07d now expects Not Eligible when 750.00 cannot meet the 3,000.00 product minimum.

## Browser checks

These checks are separate from the 28 automated cases. They were run on 20 October 2026 in the browser against the isolated server at `http://127.0.0.1:8099/index.php#calculator`. The header brand was "FRAMS Isolated Test". `lending_management` was not opened or modified.

### Valid quote

The calculator showed loan amount `100000`, term `12`, and a read-only sample rate of `5.0`.

Displayed result:

- Estimated installment: ₱13,333.33
- Principal ₱100,000.00 · Term 12 months · Monthly interest ₱5,000.00 · Total interest ₱60,000.00 · Total payable ₱160,000.00 · Final installment ₱13,333.37
- Flat interest at 5.0% of the original principal each month. Final terms are confirmed during loan review.

The page's request to `loan_quote_lending.php?principal=100000&months=12` returned HTTP 200 with `monthly_rate_percent` 5, `monthly_interest` 5000, `total_interest` 60000, `total_payable` 160000, `installment` 13333.33, `final_installment` 13333.37, and `months` 12.

`buildPublicLoanQuote()` allocates one installment row per month, so this quote has 12 installments. The page shows the first installment and the last installment; it does not list all 12 rows. Eleven installments of 13,333.33 plus the final installment of 13,333.37 are 16,000,000 centavos, which is 160,000.00.

Screenshot: `testing/evidence/browser/BRW-FC02c_valid_quote.png`.

Outcome: PASS.

### Invalid values

The calculator asks `loan_quote_lending.php` when the amount or term input changes, then shows that response. Each value below was placed in the calculator fields. The same page also requested the quote URL, so the HTTP status is the server response.

| Case | Values sent | On-screen result | HTTP |
| --- | --- | --- | --- |
| Negative principal | principal −100000, term 12 | ₱0.00 and "Loan principal cannot be negative." | 422 |
| Zero-month term | principal 100000, term 0 | ₱0.00 and "Loan term must be at least 1 month." | 422 |
| Empty term | principal 100000, term empty | ₱0.00 and "Enter a loan amount and a whole number of months." | 422 |
| Empty principal | principal empty, term 12 | ₱0.00 and "Enter a loan amount and a whole number of months." | 422 |
| Negative term | principal 100000, term −3 | ₱0.00 and "Loan term must be at least 1 month." | 422 |
| Nonnumeric term `abc` | The number field cleared the letters. A direct request used `months=abc`. | ₱0.00 and the empty-term message on screen. The direct request returned "Enter a loan amount and a whole number of months." | 422 |
| Nonnumeric principal `abc` | The number field cleared the letters. A direct request used `principal=abc`. | ₱0.00 and the empty-amount message on screen. The direct request returned the same whole-number message. | 422 |

None of these responses included a payable or an installment quote. Screenshot of the zero-month case: `testing/evidence/browser/BRW-FC10e_zero_term.png`.

Outcome: PASS. Invalid values shown in the calculator are rejected by the server. A nonnumeric query string is also rejected with HTTP 422; the number inputs themselves do not keep letters.

### Loan release term

The release form has no loan-term field. It posts the collector and the payment frequency. The term is taken from `approved_loan_term`, or from `loan_term` when the approved term is blank. The application wizard starts its term list at "Select", and eligibility treats a term of 0 months or less as a missing loan term and blocks progress. A blank stored term is therefore not an approved 12-month product rule.

`parseLoanTermMonths('')` returns 0. Release stops with "This application has no valid loan term. A loan term of at least 1 month is required." and does not write `12 months` into `loan_releases`. This path was not submitted in the browser, because a successful release would insert a loan. The later term tests call the same decision function and the schedule function without posting a release.

## Blank loan term

Root cause: `parseLoanTermMonthsForApproval()` returned 12 for a blank or unparseable term. `generateLoanAgreementText()` used `?? '12'` and then `resolveLoanTermMonths()`, which can also return 12 when a released loan has no term and no dates. The approval modal preview used the same 12-month substitute. The wizard already starts at "Select", and eligibility already treats a term of 0 months or less as incomplete, so 12 was not an approved product rule.

Correction: `resolveApplicationLoanTerm()` keeps an entered approved term. If that field is blank, it uses the requested term. Missing, zero, negative, and non-numeric values stay invalid and produce "A loan term of at least 1 month is required." Approval and agreement generation stop before writing a loan or an agreement. The agreement text prints the approved label. Release uses the same choice. The wizard blocks step 3 and final submit with that message. `resolveLoanTermMonths()` is unchanged for released-loan display, including its date estimate and its final 12 when both the term and the dates are missing. Agreement generation does not call it.

Files changed for this correction:

- `lending_system/includes/loan_helpers.php`
- `lending_system/includes/agreement_helpers.php`
- `lending_system/loan_applications_lending.php`
- `lending_system/loan_release_lending.php`
- `lending_system/borrower_loan_application_lending.php`
- `lending_system/borrower_loan_agreement_lending.php`
- `testing/harness/run_fc_term_validation.php`

The original harness was run again after this change: 28 passed, 0 failed, on `frams_capstone_test`, with `lending_management` row counts unchanged. The new term checks are separate. They passed 8 of 8. They did not insert or update borrower, application, agreement, loan, release, or payment rows. Evidence: `testing/evidence/fc_term_validation_results.json`.

| Test | Expected | Actual | Outcome |
| --- | --- | --- | --- |
| LT-01 | Approved "12 months" on 10,000.00 stays 12 months and payable 16,000.00 | Same | PASS |
| LT-02 | Blank term is invalid, eligibility lists Loan term, and agreement generation throws | Message "A loan term of at least 1 month is required." | PASS |
| LT-03 | "0 months" stays invalid even when the requested term is "12 Months" | 0 months, invalid | PASS |
| LT-04 | "-3" and "-3 months" stay negative and invalid | Both −3, Not Eligible | PASS |
| LT-05 | Approved "6 Months" wins over requested "24 Months" | Interest 3,000.00; payable 13,000.00 | PASS |
| LT-06 | Agreement text shows 6 Months, regular installment 2,166.66, and final installment 2,166.70 | Same text on a second call; no row written | PASS |
| LT-07 | Blank term and "Select" do not release | Schedule throws; release source has no `'12 months'` substitute | PASS |
| LT-08 | Stored "12 months" still prices 16,000.00. A blank term with dates does not become an agreement | Agreement rejected. Date display still returns 12 for a released loan with no term text | PASS |

The approval screen, agreement page, and release button were not opened in the browser for these eight cases. The browser session below is a later, separate check.

### Term validation results

Full output from `testing/evidence/fc_term_validation_results.json`:

```json
{
    "executed_at": "2026-10-20T18:08:31+02:00",
    "php_version": "8.2.12",
    "database": "frams_capstone_test",
    "writes": "none",
    "isolated_table_counts_unchanged": true,
    "counts_before": {
        "clients": 2,
        "loan_applications": 24,
        "loan_agreements": 0,
        "loans": 24,
        "loan_releases": 24,
        "payments": 24
    },
    "counts_after": {
        "clients": 2,
        "loan_applications": 24,
        "loan_agreements": 0,
        "loans": 24,
        "loan_releases": 24,
        "payments": 24
    },
    "counts": {
        "executed": 8,
        "passed": 8,
        "failed": 0
    },
    "results": [
        {
            "id": "LT-01",
            "expected": "Approved term \"12 months\" is kept. Payable on 10,000.00 is 16,000.00. No invented term.",
            "actual": {
                "label": "12 months",
                "months": 12,
                "valid": true,
                "total_payable": 16000
            },
            "status": "PASS",
            "explanation": "An explicit approved term is parsed and priced. The 12 comes from the stored term, not from a blank-term default."
        },
        {
            "id": "LT-02",
            "expected": "Blank approved and requested terms are invalid. Eligibility lists Loan term. Agreement generation throws the term message.",
            "actual": {
                "choice_valid": false,
                "choice_months": 0,
                "message": "A loan term of at least 1 month is required.",
                "eligibility_status": "Not Eligible",
                "missing_fields": [
                    "Loan term"
                ],
                "wizard_message": "We cannot verify an affordable loan amount yet. Please complete Step 2 (Employment & income), including income and debt information, then adjust your loan amount: Loan term.",
                "agreement": "A loan term of at least 1 month is required."
            },
            "status": "PASS",
            "explanation": "A missing term stays missing. The agreement helper does not substitute 12 months."
        },
        {
            "id": "LT-03",
            "expected": "An explicit zero-month term is invalid and does not fall back to a requested 12-month term.",
            "actual": {
                "label": "0 months",
                "months": 0,
                "valid": false,
                "message": "A loan term of at least 1 month is required.",
                "eligibility_status": "Not Eligible",
                "missing_fields": [
                    "Loan term"
                ]
            },
            "status": "PASS",
            "explanation": "Zero is an entered term. The requested 12-month value is not used in its place."
        },
        {
            "id": "LT-04",
            "expected": "A negative term is invalid, including a label such as \"-3 months\".",
            "actual": {
                "numeric_months": -3,
                "numeric_valid": false,
                "label_months": -3,
                "label_valid": false,
                "eligibility_status": "Not Eligible",
                "missing_fields": [
                    "Loan term"
                ]
            },
            "status": "PASS",
            "explanation": "Negative terms are rejected. They are not clamped to 1 or 12 months."
        },
        {
            "id": "LT-05",
            "expected": "Approved \"6 Months\" wins over requested \"24 Months\". Payable on 10,000.00 is 13,000.00.",
            "actual": {
                "label": "6 Months",
                "months": 6,
                "total_interest": 3000,
                "total_payable": 13000
            },
            "status": "PASS",
            "explanation": "The approved term is priced. The requested term does not replace it, and 24 months would have produced 22,000.00 payable."
        },
        {
            "id": "LT-06",
            "expected": "Agreement text shows \"6 Months\", regular installment 2,166.66, final installment 2,166.70, and does not show the requested 24-month term.",
            "actual": {
                "contains_approved_term": true,
                "contains_requested_term": false,
                "contains_installment": true,
                "contains_final_installment": true,
                "stable": true
            },
            "status": "PASS",
            "explanation": "The agreement is built from the approved term. The installment lines use the shared centavo split, which sums to 13,000.00. Repeating the call does not change the text and does not write a row."
        },
        {
            "id": "LT-07",
            "expected": "A blank or non-numeric term does not release. The schedule function rejects the parsed term, and the release page has no 12-month substitute.",
            "actual": {
                "blank_valid": false,
                "blank_months": 0,
                "select_valid": false,
                "select_months": 0,
                "schedule_rejected": true,
                "schedule_message": "Loan term must be at least 1 month.",
                "release_source_blocks_default": true
            },
            "status": "PASS",
            "explanation": "Release uses the same term choice as approval. \"Select\" is not a term, and the page does not store 12 months for it."
        },
        {
            "id": "LT-08",
            "expected": "A stored 12-month term still prices 16,000.00 and prints that term. A blank term with dates is not turned into an agreement. Released-loan display may still estimate months from dates.",
            "actual": {
                "display_months_from_dates": 12,
                "display_label": "12 months",
                "valid_payable": 16000,
                "blank_with_dates": "A loan term of at least 1 month is required.",
                "stored_term_line": true,
                "stored_installment": true
            },
            "status": "PASS",
            "explanation": "Existing valid terms are unchanged. Date-based display remains only in resolveLoanTermMonths(), which agreement generation does not use."
        }
    ]
}
```

## Browser verification of approval, agreement, and release

These results are separate from the 28 financial cases and the 8 term cases. They were observed on 20 October 2026 in the browser at `http://127.0.0.1:8099`. The server used the isolated prepend. The header on the public page was "FRAMS Isolated Test". The connected database for that server was `frams_capstone_test`.

Synthetic applications were inserted only in `frams_capstone_test`, viewed, and then deleted. `lending_management` row counts were the same after the session as before it: users 15, clients 9, loan applications 12, loan agreements 16, loans 11, loan releases 10, payments 20, notifications 179, and loan application reviews 9. The isolated database returned to its starting counts as well. No loan release was submitted, and the synthetic applications had no release row and no loan row.

The shared amounts used for comparison are flat 5% of the original principal each month. For 10,000.00 over 3 months the breakdown is monthly interest 500.00, total interest 1,500.00, and total payable 11,500.00. The shared installment split is 3,833.33, 3,833.33, and a final installment of 3,833.34. For 100,000.00 over 12 months the breakdown is monthly interest 5,000.00, total interest 60,000.00, and total payable 160,000.00. The shared split is eleven installments of 13,333.33 and a final installment of 13,333.37. For 10,000.00 over 6 months the payable is 16,000.00 and an equal monthly figure rounded to centavos is 2,166.67.

| Scenario | Expected | Actual | Outcome |
| --- | --- | --- | --- |
| Calculator, 100,000.00 for 12 months | Installment 13,333.33, final installment 13,333.37, payable 160,000.00 | Same figures on the public calculator | PASS |
| Calculator, 10,000.00 for 3 months | Installment 3,833.33, final installment 3,833.34, payable 11,500.00 | Same figures on the public calculator | PASS |
| Calculator, term 0 | ₱0.00 and "Loan term must be at least 1 month." | Same message, with no quote | PASS |
| Calculator, empty term | ₱0.00 and "Enter a loan amount and a whole number of months." | The live field was empty and showed that message. It did not price 12 months | PASS |
| Calculator, term −3 | ₱0.00 and "Loan term must be at least 1 month." | Same message, with no quote | PASS |
| Approval, stored approved term "6 Months" and requested term "24 Months" | The approved field stays "6 Months" and the preview uses 6 months | Field "6 Months", installment 2166.67, remarks "Term 6 months", due 2027-04-20 | PASS |
| Approval, blank term, then Approve & Generate Agreement | The message "A loan term of at least 1 month is required." and no new agreement | That message was in the page alert. The application stayed Pending Review with a blank term. No agreement row was added | PASS |
| Approval, approved term "0 months" and requested term "12 Months" | The approved field stays "0 months" and generation is rejected | Field "0 months", installment left blank, and the same required-term message after submit. The application row was unchanged | PASS |
| Approval, approved term "−3 months" | The field stays negative and is not priced as 12 months | Field "−3 months", installment left blank, interest on the details card ₱0.00 | PASS |
| Approval, term text "Select" | An unusable term stays invalid and is not treated as 12 months | The field stayed "Select", the installment stayed blank, and eligibility was Incomplete. The details card estimated due date was 2027-10-20 | FAIL |
| Agreement, approved "3 months" on 10,000.00, requested "12 months" | Term 3 months, payable 11,500.00, final installment 3,833.34 | Term "3 months", payable ₱11,500.00, and all three schedule rows ₱3,833.33 | FAIL |
| Agreement, approved "12 months" on 100,000.00, requested "6 Months" | Term 12 months, payable 160,000.00, final installment 13,333.37 | Term "12 months", payable ₱160,000.00, and all 12 schedule rows ₱13,333.33 | FAIL |
| Agreement page with a blank term and an existing generated row | The page shows the required-term message and does not create a 12-month agreement | The page showed "A loan term of at least 1 month is required." The accept button was absent. The stored agreement text was unchanged | PASS |
| Release preparation for a valid 3-month term | The screen shows the 3-month schedule totaling 11,500.00, with a final installment of 3,833.34 | The preparation panel showed the borrower, ₱10,000.00, and 5.00%. It did not show a schedule. Release Loan was not clicked | NOT RUN |
| Release submit for a missing, zero, negative, or non-numeric term | The screen rejects the release and does not store 12 months | The release form was not submitted. The list showed "3 months" for the valid row and "N/A" for the blank requested term | NOT RUN |

### Approval screen

The review modal for the synthetic application with requested term "24 Months" and approved term "6 Months" kept "6 Months" in Approved Loan Term. The installment preview was 2166.67. The generated remark said "Term 6 months" and the form due date was 04/20/2027. Screenshot: `testing/evidence/browser/BRW-APPROVAL-valid-term-field.png`. The installment card above that field is in `testing/evidence/browser/BRW-APPROVAL-valid-6-months.png`.

The loan-details card on that same modal still summarized the requested term: 24 payments, estimated interest ₱12,000.00, and estimated due date 2028-09-20. That card reads the requested term. It did not replace the approved term with 12 months.

A blank approved term and a blank requested term left the approved field empty, the installment empty, the loan term "Not Provided", the payment count "Not Available", interest ₱0.00, and the due date "Not Provided". Clicking Approve & Generate Agreement returned "A loan term of at least 1 month is required." The application remained Pending Review and no agreement row was inserted. The page then moves that alert into a short-lived toast, so the message was read from the page and had already closed before a screenshot of the toast could be taken.

An approved term of "0 months", with a requested term of "12 Months", stayed "0 months" in the approved field. The installment box stayed empty. Screenshot: `testing/evidence/browser/BRW-APPROVAL-zero-term-field.png`. Submitting Approve & Generate Agreement returned the same required-term message, and the stored approved term remained "0 months". The details card on that modal still described the requested "12 Months" term, including 12 payments and estimated interest ₱6,000.00.

An approved term of "−3 months" stayed in the field. The installment box stayed empty and the details-card interest was ₱0.00. Screenshot: `testing/evidence/browser/BRW-APPROVAL-negative-term-field.png`. The details-card due date was 2027-01-20. That display reads the digit 3 from the label.

The term "Select" stayed in the approved field. Eligibility was Incomplete and listed Loan term. Number of payments was "Not Available" and estimated interest was ₱0.00. The estimated due date on the details card was 2027-10-20, twelve months after 20 October 2026. Screenshot: `testing/evidence/browser/BRW-APPROVAL-select-due-date.png`. The due-date block in the review modal uses 12 months when the term text contains no number. That is the failed scenario in the table above. The approved term field itself was still "Select", and this case was not submitted.

### Loan agreement page

The borrower page for approved term "3 months" and requested term "12 months" showed Loan Term "3 months", monthly interest ₱500.00, total interest ₱1,500.00, installment ₱3,833.33, and total payable ₱11,500.00. The page text did not contain "12 months". Screenshots: `testing/evidence/browser/BRW-AGREEMENT-3-months.png`, `testing/evidence/browser/BRW-AGREEMENT-3-months-amounts.png`, and `testing/evidence/browser/BRW-AGREEMENT-3-months-schedule.png`.

The schedule listed three rows, each ₱3,833.33, due 19 November 2026, 19 December 2026, and 19 January 2027. The shared split puts the remainder on the last row, so the final installment should be ₱3,833.34 and the three rows should add to ₱11,500.00. The displayed rows add to ₱11,499.99. The agreement page divides total payable by the number of payments and repeats that rounded amount on every row. Accept was not clicked.

The borrower page for approved term "12 months" and requested term "6 Months" showed "The loan term is 12 months", total interest ₱60,000.00, total repayment ₱160,000.00, and "Each installment payment is ₱13,333.33". All 12 schedule rows were ₱13,333.33. The shared final installment is ₱13,333.37. Screenshot: `testing/evidence/browser/BRW-AGREEMENT-12-months.png`.

Opening the agreement for a blank term showed only "A loan term of at least 1 month is required." There was no accept button and no 12-month agreement. Screenshot: `testing/evidence/browser/BRW-AGREEMENT-blank-rejected.png`. The stored agreement text remained "STORED PLACEHOLDER — a missing term must not rewrite this as 12 months." No additional agreement row was created. The two valid generated agreements were refreshed by the page view with the approved term in the text: "Loan Term: 3 mo" and "Loan Term: 12". Those rows were deleted with the other synthetic rows at the end of the session.

### Loan release interface

The release list for the synthetic rows showed the stored requested term. The valid row showed "3 months". The blank requested term showed "N/A". Screenshot: `testing/evidence/browser/BRW-RELEASE-list-terms.png`. The zero-term and non-numeric rows showed "12 Months" in that column because that text is the requested term stored on those applications. Their approved terms were "0 months" and "abc".

Release preparation for the valid 3-month application showed the borrower, loan amount ₱10,000.00, interest 5.00%, and agreement status Accepted. It did not show the term or a payment schedule. Screenshot: `testing/evidence/browser/BRW-RELEASE-valid-no-schedule.png`. Release Loan was not clicked. A collector was selected on the blank-term preparation form, and the release button was not clicked. No `loan_releases` row was created for any synthetic application.

The schedule for a valid term, and the on-screen rejection for a missing, zero, negative, or non-numeric term, are NOT RUN. Producing the schedule requires the release submit, which inserts a loan. The rejection message is shown only after that same submit. The preparation screen has no schedule preview.

### Consistency across the calculator, agreement, and release

For 100,000.00, 5%, and 12 months, the calculator and the agreement page agree on monthly interest ₱5,000.00, total interest ₱60,000.00, total payable ₱160,000.00, and a regular installment of ₱13,333.33. The calculator also shows the final installment ₱13,333.37. The agreement schedule repeats ₱13,333.33 on the twelfth row.

For 10,000.00, 5%, and 3 months, the calculator and the agreement page agree on monthly interest ₱500.00, total interest ₱1,500.00, total payable ₱11,500.00, and a regular installment of ₱3,833.33. The calculator shows the final installment ₱3,833.34. The agreement schedule repeats ₱3,833.33 on the third row.

Calculator screenshots: `testing/evidence/browser/BRW-CALC-100000-12.png`, `testing/evidence/browser/BRW-CALC-10000-3.png`, and `testing/evidence/browser/BRW-CALC-zero-term.png`.

The release schedule for those same inputs was not produced in the browser during that session. The correction section below records the later agreement screens and the rolled-back release check.

## Corrections for agreement rounding, due date, and release

These checks were run on 21 October 2026 after the browser defects above. They do not replace the 28 financial cases or the 8 term cases. The financial harness was run again: 28 passed, 0 failed, on `frams_capstone_test`, with `lending_management` unchanged. The term harness was run again: 8 passed, 0 failed, with no rows written. LT-06 still passes. Its installment line now follows the shared centavo split, so a 10,000.00 loan for 6 months shows a regular installment of 2,166.66 and a final installment of 2,166.70. Those rows sum to the unchanged payable of 13,000.00. The earlier equal-rounded line of 2,166.67 summed to 13,000.02.

### Agreement installment rounding

Root cause: the agreement text and the agreement page divided total payable by the number of payments and rounded that same figure onto every row. The shared allocator, `allocateEvenMoneyRows()`, already places the leftover centavos on the last row. Total payable was not changed.

| Example | Before | After |
| --- | --- | --- |
| 10,000.00, 3 months, payable 11,500.00 | 3,833.33 on all three rows, sum 11,499.99 | 3,833.33, 3,833.33, final 3,833.34, sum 11,500.00 |
| 100,000.00, 12 months, payable 160,000.00 | 13,333.33 on all 12 rows, sum 159,999.96 | eleven rows of 13,333.33, final 13,333.37, sum 160,000.00 |

The agreement page and `generateLoanAgreementText()` both call `allocateEvenMoneyRows()`. Automated cases AG-01 and AG-02 passed. In the browser, the 3-month schedule showed ₱3,833.33, ₱3,833.33, and ₱3,833.34, with total payable ₱11,500.00. The 12-month schedule showed ₱13,333.33 on rows 1 through 11 and ₱13,333.37 on row 12, with total payable ₱160,000.00. Accept was not clicked. Screenshots: `testing/evidence/browser/BRW-AGREEMENT-3-months-schedule.png` and `testing/evidence/browser/BRW-AGREEMENT-12-months-schedule.png`.

### Due date for an invalid term

Root cause: the approval details card parsed a term with `preg_match('/(\\d+)/')` and used 12 months when the text had no digits. "Select" therefore showed a due date 12 months ahead. The same fallback was on the client details card.

`estimatePendingApplicationDueDate()` now returns an empty string for a missing, zero, negative, or nonnumeric term, including "Select". An explicit invalid approved term does not use the requested term. A valid approved term uses a stored approved due date, or today plus that term. The approval modal script does not calculate a due date when the typed term is below 1 month.

| Case | Approved term | Requested term | Result |
| --- | --- | --- | --- |
| DD-01 | missing | missing | Not Provided |
| DD-02 | Select | 12 Months | Not Provided |
| DD-03 | 0 months | 12 Months | Not Provided |
| DD-04 | -3 months | 12 Months | Not Provided |
| DD-05 | abc | 12 Months | Not Provided |
| DD-06 | 6 Months | 24 Months | 2027-04-20 from 2026-10-20 |
| DD-07 | 6 Months, stored date 2027-01-15 | 24 Months | 2027-01-15 |
| DD-08 | blank | 6 Months | 2027-04-20 |
| DD-09 | page source |  | Both cards call the helper and no longer contain the 12-month fallback |

In the browser, application 82 kept the approved field "Select". Estimated due date, estimated interest, and estimated payment were Not Provided, and the payment count was Not Available. The loan-term label still showed the stored requested term, "12 Months". Application 83 kept the approved field "6 Months" against a requested term of "24 Months". The card showed 6 payments, interest ₱3,000.00, and due date 2027-04-20. Screenshots: `testing/evidence/browser/BRW-APPROVAL-select-not-provided.png` and `testing/evidence/browser/BRW-APPROVAL-valid-6-due-date.png`.

### Loan release

The browser Confirm Release button was not clicked. That browser release check is NOT RUN.

The release flow was exercised in `frams_capstone_test` inside one transaction and then rolled back. A synthetic 10,000.00 loan for 3 months stored schedule rows of 3,833.33, 3,833.33, and 3,833.34. Their sum was 11,500.00, and total payable stayed 11,500.00. Applications with a missing term, "0 months", "-3 months", "Select", and "abc" were rejected with "A loan term of at least 1 month is required." None of those applications received a `loan_releases` row. After rollback, the isolated table counts matched the counts taken before the transaction, and `lending_management` counts were unchanged. Evidence: `testing/evidence/fc_agreement_due_release_results.json`. That run passed 17 of 17. The later run, after the approval-installment case was added, passed 18 of 18.

The synthetic browser applications used for the screenshots were deleted afterward. Isolated counts returned to the snapshot taken before that seed. `lending_management` was unchanged: users 15, clients 9, loan applications 12, loan agreements 16, loans 11, loan releases 10, payments 20, and notifications 179.

## Installment display and browser release

These checks were run after the correction section above. The financial harness, term harness, and agreement harness were run again on 21 October 2026.

| Harness | Result |
| --- | --- |
| Financial computation | 28 passed, 0 failed |
| Loan-term validation | 8 passed, 0 failed |
| Agreement, due date, and rolled-back release | 18 passed, 0 failed |

### Approval installment display

Root cause: the approval card and its saved installment used `round(total payable / number of payments, 2)`. For 10,000.00 over 6 months that figure is 2,166.67, and six of those rows would total 13,000.02. The agreement and release schedule use `allocateEvenMoneyRows()`, which keeps total payable at 13,000.00 with a regular installment of 2,166.66 and a final installment of 2,166.70.

The approval card, the regular-installment field, and the value saved as the estimated payment now use that shared split. When the final installment differs, the card states that the final payment receives the remaining centavos so the schedule equals total payable. The 5% flat-interest formula is unchanged.

In the browser, application 102 showed approved term "6 Months", regular installment ₱2,166.66, final installment ₱2,166.70, interest ₱3,000.00, 6 payments, and due date 2027-04-20. The requested term label still showed the stored request, "24 Months". Screenshot: `testing/evidence/browser/BRW-APPROVAL-6-month-installments.png`. Automated case AG-03 passed.

### Browser release

Confirm Release was clicked for one synthetic application in `frams_capstone_test`. The header on the invoice was "FRAMS Isolated Test". No agreement Accept button was clicked.

| Check | Expected | Actual | Outcome |
| --- | --- | --- | --- |
| Approved term | 3 months | Release row loan term "3 months"; invoice shows 3 month(s) | PASS |
| Amounts | Payable 11,500.00 | Invoice total payable ₱11,500.00; loan total_payable 11,500.00 | PASS |
| Saved schedule | 3,833.33, 3,833.33, 3,833.34 | Those three rows, one release, three schedule rows | PASS |
| Duplicate release | One release | One `loan_releases` row for the application | PASS |
| Invalid term in the browser | "Select" is rejected and stores nothing | Confirm Release for that application was not completed | NOT RUN |

The invalid-term browser submit is NOT RUN. The rolled-back harness still rejects missing, zero, negative, "Select", and nonnumeric terms before a release row is stored.

After the checks, the synthetic users, application, agreement, release, loan, schedule, client, and related notifications were deleted. Isolated counts matched the snapshot taken before the seed. `lending_management` counts were unchanged. Screenshot: `testing/evidence/browser/BRW-RELEASE-valid-receipt.png`.

## Remaining limits

- The latest regression run is recorded in "Final regression after the preview and label changes." Case identifiers do not overlap. Some groups repeat the same amounts, so the three passed counts are not a count of unique scenarios.
- `resolveLoanTermMonths()` can still estimate a term from release and due dates, and it still returns 12 when a released loan has neither a term nor those dates. That helper is for display of an existing loan. Approval, agreement generation, and release do not use it to fill a missing term.
- A stored loan whose term cannot be recalculated keeps the stored payable on the loan-details page instead of stopping the page.
- A generated agreement that already has stored text is left unchanged when its application term is invalid. The browser session opened that page. It showed "A loan term of at least 1 month is required." and left the stored text in place. Accept was not clicked.
- The release preview uses the application's stored payment frequency. When that frequency is already saved, changing the dropdown on the preparation screen does not change the preview or the schedule that Confirm Release saves.
- The preview and the release use the server's current date. A page left open past midnight can show the previous day's dates until it is reloaded.
- Browser Confirm Release for an approved term of "Select" was not completed. The preparation screen showed the rejection and no schedule. The rolled-back harness still rejects that term before any release row is stored.
- This run does not cover every screen, report, or the original office database.

## Approval labels and release preview

This pass did not change the 5% flat-interest formula, `allocateEvenMoneyRows()`, or the stored loan terms. The financial harness and the term harness were not rerun. The agreement, due-date, and release harness was rerun after the preview was added.

| Harness | Result |
| --- | --- |
| Agreement, due date, release, and schedule preview | 19 passed, 0 failed |
| Isolated database during that rerun | Unchanged by the harness |
| `lending_management` during that rerun | Unchanged |

Case RP-01 calls `previewReleaseSchedule()` and `buildPaymentSchedule()` with the same approved principal, approved term, frequency, and release date. For 10,000.00 over 3 months both return 3,833.33, 3,833.33, and 3,833.34, payable 11,500.00. An approved term of "Select" returns no due date and the message "A loan term of at least 1 month is required."

### Approval card labels

The details card now says "Requested Loan Term" for the stored request and "Approved Loan Term" for the stored approved term. "Number of Payments" still follows the resolved approved term. In the browser, application 111 showed requested term "24 Months", approved term "6 Months", 6 payments, regular installment ₱2,166.66, and final installment ₱2,166.70. Screenshot: `testing/evidence/browser/BRW-APPROVAL-term-labels.png`.

### Release schedule preview

The preparation screen calls `previewReleaseSchedule()`, which uses `resolveApplicationLoanTerm()`, `calculateLoanInterestBreakdown()`, and `buildPaymentSchedule()`. Those are the same functions the successful release path uses. The preview principal is the approved amount. The release date is today's server date, the same `date('Y-m-d')` stored on submit.

In the browser, synthetic application 112 showed approved principal ₱10,000.00, approved loan term "3 months", interest rate 5.00%, total interest ₱1,500.00, total payable ₱11,500.00, payment frequency Monthly, 3 payments, regular installment ₱3,833.33, final installment ₱3,833.34, first due date 2026-11-20, and final due date 2027-01-20. Confirm Release was not clicked for this application. Screenshots: `testing/evidence/browser/BRW-RELEASE-schedule-preview.png` and `testing/evidence/browser/BRW-RELEASE-schedule-preview-dates.png`.

For synthetic application 113, approved term "Select" and requested term "12 Months", the preview showed: "This application has no valid loan term. A loan term of at least 1 month is required. No repayment schedule is shown because this term cannot be released." No installment or due date was displayed. Screenshot: `testing/evidence/browser/BRW-RELEASE-select-no-schedule.png`.

### Browser tests

| Check | Expected | Actual | Outcome |
| --- | --- | --- | --- |
| Approval labels | Requested term, approved term, and payment count are separate | Application 111: requested 24 Months, approved 6 Months, 6 payments, regular ₱2,166.66, final ₱2,166.70 | PASS |
| Valid release preview | The eleven schedule fields match the shared schedule function | Application 112 showed ₱10,000.00, 3 months, 5.00%, ₱1,500.00, ₱11,500.00, Monthly, 3, ₱3,833.33, ₱3,833.34, 2026-11-20, 2027-01-20 | PASS |
| Select preview | No schedule and no fallback to the requested 12 months | Application 113 showed the invalid-term message and no due dates | PASS |
| Select Confirm Release | The submit is rejected and no release row is stored | Confirm Release was not completed. The browser safety check blocked the click, and the approval retry was not completed | NOT RUN |

No agreement Accept button was clicked. Applications 111, 112, and 113, their users, and the two synthetic agreements were deleted after the screenshots. Isolated counts returned to users 6, clients 2, loan applications 32, loan agreements 0, loans 32, loan releases 32, schedules 66, payments 34, and notifications 114. `lending_management` remained users 15, clients 9, loan applications 12, loan agreements 16, reviews 9, loans 11, loan releases 10, schedules 75, payments 20, and notifications 179.

## Final regression after the preview and label changes

The financial calculation, installment allocation, interest formula, and approved-term behavior were not changed for this run. All three harnesses were executed on 21 October 2026 against `frams_capstone_test`. Evidence files: `testing/evidence/fc_validation_results.json`, `testing/evidence/fc_term_validation_results.json`, and `testing/evidence/fc_agreement_due_release_results.json`.

| Group | Executed | Passed | Failed |
| --- | --- | --- | --- |
| Financial computation, FC-01a through FC-11 | 28 | 28 | 0 |
| Loan-term validation, LT-01 through LT-08 | 8 | 8 | 0 |
| Agreement, due date, release, and preview: AG-01 through AG-03, DD-01 through DD-09, RL-01 through RL-06, RP-01 | 19 | 19 | 0 |

No group failed. The 28, 8, and 19 figures are the recorded results in each file. Adding them to 55 counts recorded results, not 55 unique scenarios. The identifiers themselves do not repeat. The same behavior is checked in more than one group:

- 10,000.00 over 3 months, payable 11,500.00, installments 3,833.33, 3,833.33, and 3,833.34: AG-01, RP-01, and RL-06.
- 10,000.00 over 6 months, regular installment 2,166.66 and final installment 2,166.70: LT-06 and AG-03.
- 100,000.00 over 12 months, payable 160,000.00, regular installment 13,333.33 and final installment 13,333.37: FC-02c and AG-02.
- Invalid terms, including "Select", with no fallback to a requested 12-month term: LT-02, LT-07, DD-01 through DD-05, RL-01 through RL-05, and the Select branch of RP-01.

### Preview and saved schedule

RP-01 passed. For approved principal 10,000.00, approved term "3 months", requested amount 24,000.00, requested term "24 Months", Monthly, and release date 2026-10-21, `previewReleaseSchedule()` returned principal 10,000.00, term "3 months", payable 11,500.00, regular installment 3,833.33, final installment 3,833.34, first due date 2026-11-21, and final due date 2027-01-21. A separate `buildPaymentSchedule()` call with that principal, rate, term, frequency, and date returned installment amounts 3,833.33, 3,833.33, and 3,833.34. The preview's first and final due dates matched that call. `previewReleaseSchedule()` obtains those amounts and dates by calling `buildPaymentSchedule()`, which is the function the release page uses when a release is saved. The preview does not return the middle due date as its own field. That date is produced only inside `buildPaymentSchedule()`.

### Invalid terms before storage

RL-01 through RL-05 passed for a missing term, "0 months", "-3 months", "Select", and "abc", each with a requested term of "12 Months". Each was rejected with "This application has no valid loan term. A loan term of at least 1 month is required." The harness inserted those applications only inside a transaction, counted `loan_releases` for them, and recorded `invalid_releases_stored` 0. It then rolled the transaction back. The rejection text in `loan_release_lending.php` occurs before `$conn->beginTransaction()`. RP-01 also passed with `select_valid` false and `select_due` empty. RL-06 passed with the valid 3-month rows above, `rolled_back` true, and both database count checks true. Its recorded browser field is "NOT RUN".

### Browser checks and tests not run

Browser Confirm Release for an approved term of "Select" remains NOT RUN. The click was blocked by the browser safety check, and the approval retry was not completed. This regression did not repeat that click. The Select result above is the automated RL-04 and RP-01 evidence only.

Earlier browser checks, not repeated in this run, remain as previously recorded: approval labels passed on application 111, the valid schedule preview passed on application 112, and the Select preview passed on application 113. No agreement Accept button was clicked in this run.

### Databases

`lending_management` was unchanged. The financial harness compared all 20 of its tables and recorded `source_database_unchanged` true. The agreement harness compared its tracked tables before and after and recorded `lending_management_unchanged` true: users 15, clients 9, loan applications 12, loan agreements 16, loans 11, loan releases 10, schedules 75, payments 20, and notifications 179.

The term harness writes nothing. The agreement harness rolls its synthetic release back, and its isolated counts were the same before and after that harness. The financial harness inserts synthetic loan, release, schedule, and payment rows for the existing isolated test borrower and does not delete them. That is existing harness behavior, and it is confined to `frams_capstone_test`. After this run the agreement harness saw isolated counts of users 6, clients 2, loan applications 36, loan agreements 0, loans 36, loan releases 36, schedules 74, payments 39, and notifications 131, unchanged across that harness.
