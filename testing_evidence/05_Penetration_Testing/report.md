# FRAMS Penetration Testing Report

Execution date: 18 October 2026

Results below come from the recorded test run against the isolated database `frams_capstone_test`. The existing `lending_management` database was not modified.

## Counts

| Executed | Passed | Failed | Blocked | Not Executed | Pass rate |
| --- | --- | --- | --- | --- | --- |
| 8 | 8 | 0 | 0 | 0 | 100.00% of 8 completed pass/fail tests |

Pass rate uses passed tests divided by passed plus failed tests. Blocked tests are not treated as passed.

## Test cases

| ID | Scenario | Expected | Actual | Status | Evidence | Remarks |
| --- | --- | --- | --- | --- | --- | --- |
| PEN-001 | Direct URL to user management | redirect to login | HTTP 302 | PASS | 05_Penetration_Testing/screenshots/PEN-001_anonymous_users.png |  |
| PEN-002 | Direct URL to payments | redirect to login | HTTP 302 | PASS | logs/http_test_execution.log |  |
| PEN-003 | Borrower direct URL to user management | redirect and no user table | HTTP 302 admin user not shown | PASS | logs/http_test_execution.log |  |
| PEN-004 | Anonymous payment post | redirect to login and payment count unchanged | HTTP 302; count 3 to 3 | PASS | logs/http_test_execution.log |  |
| PEN-005 | Borrower loan status post | status unchanged | Overdue to Overdue; HTTP 302 | PASS | logs/http_test_execution.log |  |
| PEN-006 | Direct loan details URL | redirect to login | HTTP 302 | PASS | logs/http_test_execution.log |  |
| PEN-007 | Collector direct URL to user management | redirect and no user administration table | HTTP 302 admin form not shown | PASS | logs/http_test_execution.log |  |
| PEN-008 | Client query does not mark a loan paid | loan status unchanged | Overdue to Overdue | PASS | logs/http_test_execution.log | Payment completion is not accepted from a browser query flag. |

## Findings in this category

No finding was recorded in this category.