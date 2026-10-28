# Financial Risk Assessment and Micro-Lending System (FRAMS)

## Software Testing and Evidence Report

- Execution date: 18 October 2026
- Environment: local XAMPP workstation, isolated PHP server at http://127.0.0.1:8099
- Source revision: bc60c67
- Application database during testing: frams_capstone_test
- Existing database preserved: lending_management

## 2. Introduction

This report records the tests executed against the existing FRAMS PHP application. The purpose is to provide Chapter 4 evidence of functional behavior, module integration, end-user workflows, security controls, controlled penetration checks, and the PayMongo QR Ph integration. The application code was not changed to force a passing result.

## 3. Testing Objectives

The run checked loan interest and eligibility calculations, authentication and role boundaries, cash payment posting, receipt and balance consistency, server-side authorization, input handling, CSRF and session behavior, and PayMongo signature, status, and webhook handling. Live PayMongo charges and real borrower email were out of scope.

## 4. Scope of Testing

Included: interest and schedule helpers, eligibility estimates, synthetic login for administrator, collector, and borrower, cash payment recording, receipt display, role restrictions, SQL injection probes, reflected HTML probes, CSRF checks, and local PayMongo webhook handling.

Blocked: creation of a live PayMongo payment, and delivery of a webhook from PayMongo to this workstation. No public tunnel was opened.

Not executed: none. Every prepared case was either completed or marked blocked.

## 5. Testing Environment

- Operating system: Windows 10 (build 26200)
- Application PHP: XAMPP PHP 8.2.12 with PDO MySQL, curl, openssl, mbstring, and fileinfo
- A separate PHP 8.4.23 on PATH does not have pdo_mysql and was not used
- Database: MariaDB 10.4.32
- Configured application database: lending_management, left unchanged (row counts matched before and after)
- Isolated database: frams_capstone_test, structure cloned without copying rows, then seeded with synthetic accounts
- Browser: Cursor IDE browser against the isolated PHP built-in server
- HTTP checks: PHP cURL
- Automated test framework: a project-specific PHP runner, because the repository has no PHPUnit or Composer test suite
- Mail: synthetic accounts have no email address, and the isolated runtime points SMTP at 127.0.0.1 port 1. No borrower email was sent.
- PayMongo on the isolated runtime: mode forced to test with an invalid test key. The live key in .env was not loaded by the test process.

## 6. Testing Methodology

Expected values for interest follow the implementation: monthly interest equals principal times the monthly rate divided by 100, total interest equals that monthly amount times the term, and total payable equals principal plus total interest, with each returned field rounded to centavos. Eligibility expectations follow the 40 percent DTI policy and the 3,000 to 500,000 loan limits. Security tests treat a safe rejection as a pass and a successful unsafe action as a fail. PayMongo results are labeled as a real HTTP 401 from an invalid test key, a local simulation, or blocked.

## 7. Unit Testing Results

Unit Testing recorded 45 cases: 43 passed, 2 failed, 0 blocked. Failed: UT-007 Zero loan duration; UT-008 Negative loan duration. Detail is in `01_Unit_Testing/report.md`.

## 8. Integration Testing Results

Integration Testing recorded 13 cases: 13 passed, 0 failed, 0 blocked. Detail is in `02_Integration_Testing/report.md`.

## 9. System Testing Results

System Testing recorded 20 cases: 20 passed, 0 failed, 0 blocked. Detail is in `03_System_Testing/report.md`.

Browser screenshots show the staff login, the invalid-login message, the administrator dashboard, clients, loans, payments, reports, users, the collector dashboard, the borrower dashboard, loan FRAMS-TEST-A, payment history with RJRR receipts, logout to the public page, and the collector expenses denial.

## 10. Security Testing Results

Security Testing recorded 17 cases: 14 passed, 3 failed, 0 blocked. Failed: SEC-010 Reflected HTML in expense message; SEC-014 Loan status change without CSRF token; SEC-016 Security response headers. Detail is in `04_Security_Testing/report.md`.

## 11. Penetration Testing Results

Penetration Testing recorded 8 cases: 8 passed, 0 failed, 0 blocked. Detail is in `05_Penetration_Testing/report.md`.

These checks were limited to the isolated FRAMS server. They did not target other hosts, and they did not use denial-of-service or destructive payloads.

## 12. PayMongo Testing Results

PayMongo Testing recorded 19 cases: 17 passed, 0 failed, 2 blocked. Blocked: PM-011 Live PayMongo payment creation; PM-012 External webhook delivery. Detail is in `06_PayMongo_Testing/report.md`.

The invalid-key QR attempt made one real HTTPS request to api.paymongo.com and received HTTP 401. The transaction row was marked failed and no repayment was posted. Paid, failed, mismatched, duplicate, and unknown webhook outcomes were local simulations against the isolated database. They are not PayMongo deliveries and not live charges. PM-011 and PM-012 were blocked: the configured project keys are live, and this workstation was not exposed for an inbound webhook.

## 13. Summary of Results

Executed means the case was attempted and has a recorded PASS, FAIL, or BLOCKED result. Not executed means the case was never run. Pass rate = passed / (passed + failed). Blocked cases are excluded from that rate.

| Testing Category | Total Executed | Passed | Failed | Blocked | Not Executed |
| --- | --- | --- | --- | --- | --- |
| Unit Testing | 45 | 43 | 2 | 0 | 0 |
| Integration Testing | 13 | 13 | 0 | 0 | 0 |
| System Testing | 20 | 20 | 0 | 0 | 0 |
| Security Testing | 17 | 14 | 3 | 0 | 0 |
| Penetration Testing | 8 | 8 | 0 | 0 | 0 |
| PayMongo Testing | 19 | 17 | 0 | 2 | 0 |
| TOTAL | 122 | 115 | 5 | 2 | 0 |

Distinct test cases: 122
Passed: 115. Failed: 5. Blocked: 2. Not executed: 0.
Pass rate: 95.83% (115 / 120).

## 14. Security Findings and Recommendations

Confirmed findings are separated from items that were only observed in configuration or code.

### F-001 (Low) — calculateLoanInterestBreakdown

A zero or negative loan term is coerced to one month, so the calculator charges one month of interest instead of rejecting the duration.

Reproduction: Call calculateLoanInterestBreakdown(10000, 5, 0). Observed total interest 500.00 and total payable 10500.00.

Recommendation: Reject a term less than 1 at the calculator and at the application form before interest is stored.

Evidence: `01_Unit_Testing/logs/cli_test_execution.log`. Demonstrated: Yes. Retest: Not retested. Application code was not changed.

### F-002 (Medium) — expenses_lending.php message query parameter

The expenses page prints the message query parameter without encoding, so an authenticated user can be sent a link that injects HTML.

Reproduction: Sign in as an administrator on the isolated server and open expenses_lending.php?message=FRAMSXSS<b>probe</b>&type=success. The response contains the raw b tag.

Recommendation: Encode $message and $messageType with htmlspecialchars before output. Apply the same fix on the other pages that echo those variables.

Evidence: `04_Security_Testing/logs/http_test_execution.log`. Demonstrated: Yes. Retest: Not retested. Application code was not changed.

### F-003 (Medium) — loans_lending.php update_status

An authenticated administrator request can change a loan status without a CSRF token.

Reproduction: Sign in as the synthetic administrator and POST update_status, loan_id, and status=Overdue to loans_lending.php without _csrf_token. The loan status changes.

Recommendation: Call csrfRequireValid() before update_status, as payments_lending.php already does.

Evidence: `04_Security_Testing/logs/http_test_execution.log`. Demonstrated: Yes. Retest: Not retested. The synthetic loan status is restored after the demonstration. Application code was not changed.

### F-004 (Low) — HTTP response headers

Login responses do not set X-Frame-Options, X-Content-Type-Options, Content-Security-Policy, Referrer-Policy.

Reproduction: GET login_lending.php on the isolated server and inspect the response headers.

Recommendation: Send the missing headers from a shared include used by every page.

Evidence: `04_Security_Testing/logs/http_test_execution.log`. Demonstrated: Yes. Retest: Not retested. Application code was not changed.

### F-005 (Low) — users_lending.php

With APP_ENV=local, display_errors is on. The user list passes a null email to htmlspecialchars and prints a deprecation warning that includes the application filesystem path.

Reproduction: Sign in as the synthetic administrator on the isolated server and open users_lending.php. The email column shows the deprecation text and path.

Recommendation: Pass an empty string when email is null, and set APP_ENV=production with display_errors off on any shared server.

Evidence: `04_Security_Testing/screenshots/SEC-018_error_disclosure.png`. Demonstrated: Yes. Retest: Not retested. Application code was not changed.

### F-006 (High) — PayMongo configuration

The project .env sets PAYMONGO_MODE=live and contains a live secret key and live public key. PAYMONGO_WEBHOOK_SECRET is empty. No live API request was sent during this test.

Reproduction: Read the configuration keys without printing their values. Mode is live, the secret key prefix is sk_live, and the webhook secret is empty.

Recommendation: Use test keys and PAYMONGO_MODE=test on this local workstation. Configure the matching webhook secret before accepting QR Ph events. Do not commit .env.

Evidence: `06_PayMongo_Testing/logs/environment_probe.txt`. Demonstrated: Configuration observed. No live charge was attempted.. Retest: Not changed. Live credentials were not used.

### F-007 (Medium) — loans_lending.php outstanding statistic

The loans page outstanding figure sums total_payable for loans whose stored status is not Paid. It does not subtract recorded payments. After 10,416.66 in collections the card still showed 17,000.00, while the reports page outstanding was 6,583.34.

Reproduction: Record partial payments on the isolated loans and compare the loans page outstanding card with the reports page outstanding card.

Recommendation: Calculate outstanding as total payable minus recorded payments, consistent with calculateLoanBalanceSummary.

Evidence: `03_System_Testing/screenshots/ST-006_admin_loans.png`. Demonstrated: Yes. Retest: Not retested. Application code was not changed.

Code inspection, not demonstrated as an exploit: staff and borrower login forms do not call csrfRequireValid(). Payment recording does. Login CSRF was not executed as an attack.

## 15. Limitations

- The project PayMongo mode is live and the webhook secret is empty, so a real test-mode payment and a provider-delivered webhook could not be completed safely.
- The reflected expense alert was confirmed in the DOM, but its rendered box had zero size, so the screenshot of that page does not show the marker text.
- The first CLI session checks were contaminated by test output. They were rerun and the rerun result is the recorded status.
- No PHPUnit suite exists. The runner is specific to this evidence package.
- Playwright was not installed. Browser evidence used the Cursor browser.

## 16. Conclusion

The executed tests show that the flat interest formula, eligibility thresholds, role separation, cash payment posting, and local webhook signature checks behave as implemented for the synthetic data. They also show defects: zero and negative terms are coerced to one month, the loans outstanding card does not subtract payments, loan status can be changed without a CSRF token, the expense message parameter is not encoded, security headers are missing, and local error display leaks a filesystem path. The PayMongo live configuration was not exercised. FRAMS is not claimed to be fully secure or fully verified beyond the cases in this package.

## 17. Evidence References

Category reports, logs, JSON results, and screenshots are under `testing_evidence/01_Unit_Testing` through `testing_evidence/06_PayMongo_Testing`. The workbook is `TESTING_RESULTS.xlsx`. The evidence index is `EVIDENCE_INDEX.md`.
