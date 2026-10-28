# FRAMS Security Testing Report

Execution date: 18 October 2026

Results below come from the recorded test run against the isolated database `frams_capstone_test`. The existing `lending_management` database was not modified.

## Counts

| Executed | Passed | Failed | Blocked | Not Executed | Pass rate |
| --- | --- | --- | --- | --- | --- |
| 17 | 14 | 3 | 0 | 0 | 82.35% of 17 completed pass/fail tests |

Pass rate uses passed tests divided by passed plus failed tests. Blocked tests are not treated as passed.

## Test cases

| ID | Scenario | Expected | Actual | Status | Evidence | Remarks |
| --- | --- | --- | --- | --- | --- | --- |
| SEC-001 | Password storage | bcrypt hash that verifies | bcrypt hash verifies | PASS | logs/cli_test_execution.log | The hash value is not recorded. |
| SEC-002 | Login SQL injection payload | false | Isolated retest passed: the credential was rejected and the session was cleared. | PASS | logs/cli_test_execution.log | The first CLI run reported a failure because the test script had already written output, so PHP could not complete session_regenerate_id or session_destroy. A second process with output buffering confirmed the application behavior. Application code was not changed. |
| SEC-003 | Output encoding helper | encoded angle brackets | &lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt; | PASS | logs/cli_test_execution.log | Page-level reflection is tested over HTTP. |
| SEC-004 | Wrong CSRF token | false | rejected | PASS | logs/cli_test_execution.log |  |
| SEC-005 | Collector cannot pay another loan | payment count unchanged and not assigned message | count 1 to 1; assigned message yes | PASS | logs/http_test_execution.log |  |
| SEC-006 | Collector loan lookup IDOR | success false and no borrower name | success false | PASS | logs/http_test_execution.log |  |
| SEC-007 | Borrower receipt IDOR | redirect away from the other receipt | HTTP 302 other borrower not shown | PASS | logs/http_test_execution.log | HTTP 302. |
| SEC-008 | Login SQL injection over HTTP | invalid credentials and no SQL syntax error | rejected | PASS | 04_Security_Testing/screenshots/SEC-008_sqli_login.png |  |
| SEC-009 | Login username encoding | encoded attribute value | encoded | PASS | logs/http_test_execution.log |  |
| SEC-010 | Reflected HTML in expense message | marker encoded and not present as HTML | raw HTML reflected | FAIL | 04_Security_Testing/screenshots/SEC-010_reflected_message.png | Authenticated reflected output. |
| SEC-011 | Expense search SQL injection | HTTP 200 and no SQLSTATE | HTTP 200 no sql error | PASS | logs/http_test_execution.log | The search uses a bound LIKE parameter. |
| SEC-012 | Payment without CSRF token | HTTP 419 and payment count unchanged | HTTP 419; count 2 to 2 | PASS | logs/http_test_execution.log |  |
| SEC-013 | Payment with incorrect CSRF token | HTTP 419 and payment count unchanged | HTTP 419; count 2 to 2 | PASS | logs/http_test_execution.log |  |
| SEC-014 | Loan status change without CSRF token | request rejected and status unchanged | HTTP 200; status Active to Overdue | FAIL | logs/http_test_execution.log | Observed behavior is the test result. Status will be restored to Active after evidence is saved if it changed. |
| SEC-015 | Session cookie attributes | HttpOnly present | HttpOnly yes; Secure no | PASS | logs/http_test_execution.log | Secure is absent because this server is HTTP. lendingConfigureRuntime sets Secure only when HTTPS is detected. |
| SEC-016 | Security response headers | X-Frame-Options, X-Content-Type-Options, Content-Security-Policy, and Referrer-Policy | missing X-Frame-Options, X-Content-Type-Options, Content-Security-Policy, Referrer-Policy | FAIL | logs/http_test_execution.log |  |
| SEC-017 | Excessive login length | invalid credentials or normal validation, no SQLSTATE | no sql error HTTP 200 | PASS | logs/http_test_execution.log |  |

## Findings in this category

### F-002 — Medium

- Component: expenses_lending.php message query parameter
- Description: The expenses page prints the message query parameter without encoding, so an authenticated user can be sent a link that injects HTML.
- Reproduction: Sign in as an administrator on the isolated server and open expenses_lending.php?message=FRAMSXSS<b>probe</b>&type=success. The response contains the raw b tag.
- Recommendation: Encode $message and $messageType with htmlspecialchars before output. Apply the same fix on the other pages that echo those variables.
- Evidence: `04_Security_Testing/logs/http_test_execution.log`
- Demonstrated: Yes
- Retest: Not retested. Application code was not changed.

### F-003 — Medium

- Component: loans_lending.php update_status
- Description: An authenticated administrator request can change a loan status without a CSRF token.
- Reproduction: Sign in as the synthetic administrator and POST update_status, loan_id, and status=Overdue to loans_lending.php without _csrf_token. The loan status changes.
- Recommendation: Call csrfRequireValid() before update_status, as payments_lending.php already does.
- Evidence: `04_Security_Testing/logs/http_test_execution.log`
- Demonstrated: Yes
- Retest: Not retested. The synthetic loan status is restored after the demonstration. Application code was not changed.

### F-004 — Low

- Component: HTTP response headers
- Description: Login responses do not set X-Frame-Options, X-Content-Type-Options, Content-Security-Policy, Referrer-Policy.
- Reproduction: GET login_lending.php on the isolated server and inspect the response headers.
- Recommendation: Send the missing headers from a shared include used by every page.
- Evidence: `04_Security_Testing/logs/http_test_execution.log`
- Demonstrated: Yes
- Retest: Not retested. Application code was not changed.

### F-005 — Low

- Component: users_lending.php
- Description: With APP_ENV=local, display_errors is on. The user list passes a null email to htmlspecialchars and prints a deprecation warning that includes the application filesystem path.
- Reproduction: Sign in as the synthetic administrator on the isolated server and open users_lending.php. The email column shows the deprecation text and path.
- Recommendation: Pass an empty string when email is null, and set APP_ENV=production with display_errors off on any shared server.
- Evidence: `04_Security_Testing/screenshots/SEC-018_error_disclosure.png`
- Demonstrated: Yes
- Retest: Not retested. Application code was not changed.
