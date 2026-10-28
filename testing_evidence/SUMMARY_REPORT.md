# FRAMS Testing Summary

Date: 18 October 2026
Revision: bc60c67

Distinct test cases: 122
Executed (pass, fail, or blocked): 122
Passed: 115
Failed: 5
Blocked: 2
Not executed: 0
Pass rate: 95.83% (115 / 120 passed-plus-failed tests)

The existing lending_management database row counts were unchanged. Tests used frams_capstone_test and synthetic accounts. No live PayMongo charge and no borrower email were sent.

Failed cases are the zero and negative loan-term coercion, the unencoded expense message, the loan status update without a CSRF token, and the missing security headers.

Blocked cases are live PayMongo payment creation and external webhook delivery.
