# Software test results

Execution date: 19 October 2026

The project documents plan 49 cases:

- Unit testing: 6 (`UT-01` to `UT-06` in the questionnaire forms; Table 4.4 in the chapters file)
- Integration testing: 7 (`IT-01` to `IT-07`; Table 4.5)
- System testing: 12 (`ST-01` to `ST-12`)
- PayMongo testing: 12 (`PM-01` to `PM-12`)
- Penetration testing: 12 (`PT-01` to `PT-12`)

The chapters file and the PayMongo/system/penetration document write Pass for those cases. This session did not treat that wording as execution evidence.

An earlier folder, `testing_evidence/`, records a separate run on 18 October 2026. Those files were not counted as tests executed today.

## What was executed today

Two suites ran against `frams_capstone_test` only.

| Suite | Executed | Passed | Failed | Blocked |
| --- | ---: | ---: | ---: | ---: |
| Calculation, payment, eligibility, local PayMongo simulation, and direct security calls | 48 | 39 | 8 | 1 |
| HTTP checks on `http://127.0.0.1:8099` | 15 | 13 | 2 | 0 |
| Combined records | 63 | 52 | 10 | 1 |

The 52 passes are not 49 planned cases, and they are not a statement that the manuscript tables passed. The row-level records are in `testing/reports/executed_test_results.csv`.

The isolated PHP server was started with a prepend file that forces the test database and replaces PayMongo credentials. It was stopped after the browser checks. The original database row counts did not change.

## Planned cases

| ID | This session | Status | Evidence and limitation |
| --- | --- | --- | --- |
| UT-01 | Staff and borrower logins for the three synthetic roles | Passed | `HTTP-08`, `HTTP-09`, `HTTP-10` |
| UT-02 | Wrong password | Passed | `HTTP-04` and `testing/evidence/browser/HTTP-04_invalid_login.png` |
| UT-03 | Register a new borrower through the form | Not executed | Registration was not submitted |
| UT-04 | Loan wizard blocks an unaffordable step | Not executed | Function results `FIN-24` and `FIN-25` exist; the five-step screen was not used |
| UT-05 | Reject a non-JPG/PNG/PDF or oversized upload | Not executed | No file was uploaded |
| UT-06 | Reject a payment above balance plus penalty | Passed | `FIN-29` called `recordPaymentTransaction()`; the payment screen was not submitted |
| IT-01 | Approve a registration and then sign in | Not executed | No new registration was approved |
| IT-02 | Submit a five-step application and open the review | Not executed | |
| IT-03 | Accept an agreement and release a loan | Not executed | Schedule generation was called as a function in `FIN-15` to `FIN-17`, not through release |
| IT-04 | Record a valid payment and see it on the related pages | Not executed as the full path | `FIN-28` stored a payment and reduced the balance in the test database; borrower history and reports were not opened for that payment |
| IT-05 | Penalty portion appears on the penalty page | Not executed as the page | `FIN-32` stored the penalty portion; `penalties_lending.php` was not opened |
| IT-06 | Report totals include the payment | Not executed | The reports page was timed, not reconciled to a specific payment |
| IT-07 | Collector or borrower blocked from an administrator page | Passed for the pages requested | Collector expenses returned HTTP 403 (`HTTP-09`). Borrower user management did not return HTTP 200 (`HTTP-10`) |
| ST-01 to ST-12 | Full office workflow from registration through notification read state | Not executed | Login, dashboard, and logout pieces overlap `HTTP-08` and `HTTP-12` only |
| PM-01 to PM-08 | Collector QR screens | Not executed | See `paymongo_test_results.md` |
| PM-09 | Real PayMongo test-mode payment | Blocked | No test-mode payment was created. Live mode was not used |
| PM-10 | Provider-delivered signed webhook | Not executed | A local simulation passed as `PM-SIM-01`. That is not a PayMongo delivery |
| PM-11 | Invalid webhook signature | Passed for an invalid signature on the local endpoint | `HTTP-13` returned HTTP 401. Missing and stale signatures passed as function checks in `SEC-03` |
| PM-12 | Refuse deletion of a linked QR payment | Not executed | |
| PT-01 | Every protected page without a session | Not executed as the full list | `HTTP-07` redirected `users_lending.php` to login |
| PT-02 | Wrong password or inactive account | Passed for the wrong password | Inactive-account login was not repeated in the HTTP suite |
| PT-03 | Borrower cannot open administrator pages | Passed for user management | `HTTP-10` |
| PT-04 | Collector cannot open administrator pages | Passed for expenses | `HTTP-09` returned HTTP 403. Other administrator pages were not each requested |
| PT-05 | Missing CSRF token | Passed for one payment post | `HTTP-11` returned HTTP 419 and the payment count stayed unchanged |
| PT-06 | SQL text in sign-in, search, and record fields | Passed for sign-in only | `HTTP-05` and `SEC-05` |
| PT-07 | Script markup displayed as text | Passed for the login username | `HTTP-06`. Other fields were not submitted |
| PT-08 | Borrower opens another borrower's record | Not executed | |
| PT-09 | Collector opens an unassigned loan | Passed as a function call | `SEC-04`. The browser was not used for this case |
| PT-10 | Bad upload | Not executed | Same gap as `UT-05` |
| PT-11 | Logout then protected page | Passed for the next dashboard request | `HTTP-12`. The browser Back button was not used |
| PT-12 | Expired or used password-reset link | Not executed | |

## Failed software checks in this session

These are not rows in the 49-case plan. They failed while the related behavior was executed.

| ID | Result |
| --- | --- |
| FIN-09, FIN-10 | Zero and negative terms are charged as one month |
| FIN-15, FIN-16, FIN-17 | Schedule totals or final due dates do not match the independent schedule |
| FIN-19 | Public calculator does not match the loan engine |
| FIN-25 | Affordable principal below 3,000 is reported as 3,000 |
| FIN-34 | Loans-page outstanding ignores payments |
| HTTP-02 | Login response did not include `X-Frame-Options`, `Content-Security-Policy`, or `X-Content-Type-Options` |
| HTTP-15 | The scripted second login returned HTTP 200, so its authenticated timings are not usable. A separate five-sample timing run is reported in the performance file |

## Browser evidence from this session

The browser used the isolated server, not the Apache site that points at `lending_management`.

- `testing/evidence/browser/HTTP-08_admin_dashboard.png` shows the synthetic administrator dashboard after today's test payments. Outstanding balance on that screen was ₱49,150 and receivable was ₱51,000.00.
- `testing/evidence/browser/HTTP-04_invalid_login.png` shows `Invalid username or password` for the synthetic administrator username and a wrong password.
- `testing/evidence/browser/FIN-19_public_calculator.png` shows ₱8,560.75 for 100,000 over 12 months at the labeled 5% rate.

Screenshots were not created for registration, document upload, agreement release, or a provider QR payment because those flows were not executed.
