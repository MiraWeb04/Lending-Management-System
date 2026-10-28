# FRAMS System Testing Report

Execution date: 18 October 2026

Results below come from the recorded test run against the isolated database `frams_capstone_test`. The existing `lending_management` database was not modified.

## Counts

| Executed | Passed | Failed | Blocked | Not Executed | Pass rate |
| --- | --- | --- | --- | --- | --- |
| 20 | 20 | 0 | 0 | 0 | 100.00% of 20 completed pass/fail tests |

Pass rate uses passed tests divided by passed plus failed tests. Blocked tests are not treated as passed.

## Test cases

| ID | Scenario | Expected | Actual | Status | Evidence | Remarks |
| --- | --- | --- | --- | --- | --- | --- |
| ST-001 | Staff login page loads | HTTP 200 and the staff welcome heading | HTTP 200 contains Welcome back | PASS | 03_System_Testing/screenshots/ST-001_staff_login.png |  |
| ST-002 | Invalid staff login | HTTP 200 and Invalid username or password | HTTP 200 error shown | PASS | 03_System_Testing/screenshots/ST-002_invalid_login.png |  |
| ST-003 | Missing login fields | Please enter both username and password | validation message shown | PASS | logs/http_test_execution.log | The browser may also require the fields before submit. This request was sent directly. |
| ST-004 | Administrator login and dashboard | redirect to dashboard and Executive Dashboard | login HTTP 302; dashboard HTTP 200 dashboard shown | PASS | 03_System_Testing/screenshots/ST-004_admin_dashboard.png |  |
| ST-005 | Administrator client list | HTTP 200 and Synthetic BorrowerA | client visible | PASS | 03_System_Testing/screenshots/ST-005_admin_clients.png |  |
| ST-006 | Administrator loan list | The loan list shows the synthetic borrowers. | Synthetic BorrowerA (loan 29) and Synthetic BorrowerB (loan 30) are listed. The table shows the borrower name rather than the loan-number string. | PASS | 03_System_Testing/screenshots/ST-006_admin_loans_table.png | The first assertion looked for the text FRAMS-TEST-A. That string is not rendered in the loans table. The saved page HTML and the browser screenshot show both synthetic borrowers, so the result is PASS. |
| ST-007 | Administrator payment records | page contains a receipt or the cash amount | payment visible | PASS | 03_System_Testing/screenshots/ST-007_admin_payments.png |  |
| ST-008 | Administrator reports | HTTP 200 and a report heading | HTTP 200 | PASS | 03_System_Testing/screenshots/ST-008_admin_reports.png |  |
| ST-009 | User management page | HTTP 200 and synthetic usernames | users visible | PASS | 03_System_Testing/screenshots/ST-009_admin_users.png |  |
| ST-010 | Collector login and dashboard | HTTP 302 then collector dashboard content | login 302 dashboard 200 | PASS | 03_System_Testing/screenshots/ST-010_collector_dashboard.png |  |
| ST-011 | Collector loads the assigned loan | JSON success true | success true | PASS | logs/http_test_execution.log |  |
| ST-012 | Collector records a cash payment | payment count increases by 1 | HTTP 302; count 1 to 2; paid 7666.66 | PASS | 03_System_Testing/screenshots/ST-012_cash_payment_result.png | Form submission against the isolated database. |
| ST-013 | Non-cash QR start fails closed | no succeeded 100 peso QR transaction | HTTP 200; succeeded-100 rows 0; body has error yes | PASS | 03_System_Testing/screenshots/ST-013_qr_error.png | Invalid test key. No live PayMongo call. The provider returned HTTP 401 inside the application error. |
| ST-014 | Borrower login and dashboard | dashboard HTTP 200 | login 302 dashboard 200 | PASS | 03_System_Testing/screenshots/ST-014_borrower_dashboard.png |  |
| ST-015 | Borrower loan view | loan number visible | loan visible | PASS | 03_System_Testing/screenshots/ST-015_borrower_loan.png |  |
| ST-016 | Borrower payment history | RJRR receipt visible | receipt visible | PASS | 03_System_Testing/screenshots/ST-016_borrower_history.png |  |
| ST-017 | Borrower logout | dashboard redirects to login | logout 302; next 302 location borrower_login_lending.php | PASS | 03_System_Testing/screenshots/ST-017_after_logout.png |  |
| ST-018 | Unauthenticated dashboard | HTTP 302 to login | HTTP 302 | PASS | logs/http_test_execution.log |  |
| ST-019 | Borrower denied admin dashboard | redirect away from Executive Dashboard | HTTP 302 dashboard not rendered | PASS | logs/http_test_execution.log |  |
| ST-020 | Collector denied expenses | HTTP 403 | HTTP 403 | PASS | 03_System_Testing/screenshots/ST-020_collector_forbidden.png |  |

## Findings in this category

### F-007 — Medium

- Component: loans_lending.php outstanding statistic
- Description: The loans page outstanding figure sums total_payable for loans whose stored status is not Paid. It does not subtract recorded payments. After 10,416.66 in collections the card still showed 17,000.00, while the reports page outstanding was 6,583.34.
- Reproduction: Record partial payments on the isolated loans and compare the loans page outstanding card with the reports page outstanding card.
- Recommendation: Calculate outstanding as total payable minus recorded payments, consistent with calculateLoanBalanceSummary.
- Evidence: `03_System_Testing/screenshots/ST-006_admin_loans.png`
- Demonstrated: Yes
- Retest: Not retested. Application code was not changed.
