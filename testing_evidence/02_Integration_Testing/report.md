# FRAMS Integration Testing Report

Execution date: 18 October 2026

Results below come from the recorded test run against the isolated database `frams_capstone_test`. The existing `lending_management` database was not modified.

## Counts

| Executed | Passed | Failed | Blocked | Not Executed | Pass rate |
| --- | --- | --- | --- | --- | --- |
| 13 | 13 | 0 | 0 | 0 | 100.00% of 13 completed pass/fail tests |

Pass rate uses passed tests divided by passed plus failed tests. Blocked tests are not treated as passed.

## Test cases

| ID | Scenario | Expected | Actual | Status | Evidence | Remarks |
| --- | --- | --- | --- | --- | --- | --- |
| IT-001 | Administrator login | session role Admin | Admin session | PASS | logs/cli_test_execution.log |  |
| IT-002 | Collector login and role | collector true, admin false | collector only | PASS | logs/cli_test_execution.log |  |
| IT-003 | Collector client scope | true for A and false for B | A=yes B=no | PASS | logs/cli_test_execution.log |  |
| IT-004 | Collector loan scope | true for A and false for B | A=yes B=no | PASS | logs/cli_test_execution.log |  |
| IT-005 | Borrower authentication boundary | borrower function returns the user; staff login returns false | borrower-ok/staff-rejected | PASS | logs/cli_test_execution.log |  |
| IT-006 | Inactive account | false | rejected | PASS | logs/cli_test_execution.log |  |
| IT-007 | Wrong password | false and logged out | Isolated retest passed: the credential was rejected and the session was cleared. | PASS | logs/cli_test_execution.log | The first CLI run reported a failure because the test script had already written output, so PHP could not complete session_regenerate_id or session_destroy. A second process with output buffering confirmed the application behavior. Application code was not changed. |
| IT-008 | Borrower, client, and loan link | one linked row | client 20 loan 29 | PASS | logs/cli_test_execution.log |  |
| IT-009 | Invalid loan reference | the insert fails or is rolled back and no row remains | inserted; remaining 0 | PASS | logs/cli_test_execution.log | No foreign key stopped the insert; the test transaction was rolled back. |
| IT-010 | Cash payment posts to the loan | receipt RJRR-*, method Cash, remaining 7666.67 | receipt RJRR-20261018-000001 balance 7666.67 | PASS | logs/cli_test_execution.log | Direct function call on the isolated database. Email was not sent because the synthetic client email is null. |
| IT-011 | Rejected payment rolls back | exception and same payment count | rejected; count 1 to 1 | PASS | logs/cli_test_execution.log |  |
| IT-012 | Schedule status after payment | Paid, Upcoming, Upcoming | Paid, Upcoming, Upcoming | PASS | logs/cli_test_execution.log |  |
| IT-013 | Logout clears the session | isLoggedIn false | Isolated retest passed: the credential was rejected and the session was cleared. | PASS | logs/cli_test_execution.log | The first CLI run reported a failure because the test script had already written output, so PHP could not complete session_regenerate_id or session_destroy. A second process with output buffering confirmed the application behavior. Application code was not changed. |

## Findings in this category

No finding was recorded in this category.