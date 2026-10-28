# FRAMS project inspection

Inspection date: 19 October 2026

This report describes the code and documents that are present in the workspace. It does not treat source inspection as a passing runtime test.

## What was inspected

- PHP application under `lending_system/`
- Shared helpers in `lending_system/includes/`
- Maintenance scripts in `lending_system/tools/`
- SQL files in `database/migrations/`
- `README.md` and the documents in `lending_system/documentation/`
- Prior evidence under `testing_evidence/` from 18 October 2026
- Local MariaDB metadata for `lending_management` and `frams_capstone_test`

No borrower names, payment rows, passwords, API keys, or webhook secrets from the original database are copied here.

## Application shape

FRAMS is a server-rendered PHP application for RJ and RR Finance Services. Pages are role-specific for an administrator, a collector, and a borrower. The database is reached through PDO MySQL with emulated prepares disabled. The configured database name is `lending_management`.

Confirmed engines on this workstation:

- PHP 8.2.12
- MariaDB 10.4.32 (`mariadb.org binary distribution`)

## Modules found in code

| Area | Main files |
| --- | --- |
| Staff and borrower login | `login_lending.php`, `borrower_login_lending.php`, `logout_lending.php`, `includes/auth_lending.php` |
| Registration and approval | `registration_lending.php`, `borrower_approval_lending.php` |
| Loan application | `borrower_loan_application_lending.php`, `loan_applications_lending.php` |
| Eligibility | `includes/loan_eligibility_helpers.php` |
| Interest, schedule, penalty, balance | `includes/loan_helpers.php`, `loan_release_lending.php` (`buildPaymentSchedule`) |
| Payments and receipts | `payments_lending.php`, `recordPaymentTransaction()` in `includes/db_lending.php` |
| PayMongo QR Ph | `includes/paymongo_qrph.php`, `includes/paymongo_qrph_webhook.php`, `paymongo_webhook_lending.php` |
| Penalties, expenses, reports | `penalties_lending.php`, `expenses_lending.php`, `reports_lending.php` |
| Mail | `includes/mailer.php`, `includes/email_service.php`, `includes/loan_email_helpers.php` |
| CSRF | `includes/csrf_lending.php` |

## Financial formula actually implemented

`getStandardMonthlyInterestRatePercent()` returns `5.0`.

`calculateLoanInterestBreakdown()` implements:

- Monthly interest = principal × (monthly rate / 100)
- Total interest = monthly interest × number of months
- Total payable = principal + total interest

Negative principal and negative rate are clamped to zero. A zero or negative month count is coerced to 1. The rate does not change when the payment frequency is daily, weekly, or semi-monthly. Installment count does change with frequency: monthly = months, weekly = months × 4, semi-monthly = months × 2, daily = months × 30 when no explicit date span is supplied.

`buildPaymentSchedule()` divides principal and total interest by the installment count and rounds each installment. It then replaces the final due date with the release date plus the term in calendar months.

Penalty rates in `getPenaltyRatePercentForFrequency()` are 1% daily, 2% weekly, 3% semi-monthly, and 5% monthly, applied to each overdue installment's `total_amount_due`. Penalty payments are stored in `payments.penalty_paid` and are not subtracted from `loans.total_payable`.

The public page `index.php` labels the sample rate as percent per month, then uses a different amortizing expression that divides that rate by 12 again. That expression is not the loan engine.

Eligibility uses a 40% debt-to-income ceiling, a loan range of 3,000 to 500,000, and a three-month employment guideline, all read from environment defaults. The maximum affordable principal is then forced through `clampLoanAmountToPolicy()`, which raises a smaller result to the 3,000 minimum.

## PayMongo configuration observed without printing secrets

The local `.env` is gitignored. Its mode value is `live`. A live secret-key prefix and a live public-key prefix are present. The webhook secret setting is empty. This inspection did not call PayMongo with those live values.

## Database artifacts

Two migration files exist:

- `database/migrations/20261014_create_paymongo_qrph_transactions.sql`
- `database/migrations/20261014_add_paymongo_qrph_webhooks.sql`

There is no migration that creates the original `users`, `clients`, `loans`, or `payments` tables. Those tables already exist in `lending_management`. Several feature tables are also created or altered by PHP at request time.

`README.md` still says there is no migration folder. That sentence is out of date. The PayMongo section of the README matches the two SQL files more closely.

## Documents compared with the code

`lending_system/documentation/FRAMS_Chapters_1_to_5_UAT_updated.docx` describes the same 5% flat monthly interest, the same penalty rates, and six unit cases plus seven integration cases. It states that those 13 cases passed. The same file's Chapter 5 says the numeric results still have to be entered from the sheets. Those two statements do not agree.

`FRAMS_References_Questionnaires_and_Forms.docx` contains a 26-statement evaluation form and blank tally rows. It does not contain filled respondent answers.

`Test_Cases_PayMongo_Penetration_and_System_Testing.docx` lists 12 PayMongo, 12 penetration, and 12 system cases and writes Pass in each remarks cell. Those remarks are text stored in the document. This inspection did not treat them as execution evidence.

`testing_evidence/` contains an earlier run dated 18 October 2026. That history was read so it would not be repeated as if it were today's run. Today's counts come only from the commands recorded in `testing/evidence/`.

## Gaps visible before testing

- No versioned schema for the original lending tables
- Only one foreign key in the live schema, from webhook events to QR transactions
- Loans-page outstanding sums `total_payable` and does not subtract payments
- Schedule rounding does not assign the remainder to the last installment
- Public calculator and loan engine disagree
- Live PayMongo mode with an empty webhook secret on this workstation
- No completed item-level UAT responses in the project files
