# Performance measurements

Execution date: 19 October 2026

This was not a load test and not a concurrent-user test. One PHP process issued the requests. No capacity number is inferred.

## Environment

| Item | Value |
| --- | --- |
| Machine | This Windows workstation |
| PHP | 8.2.12 |
| Database | MariaDB 10.4.32, database `frams_capstone_test` |
| Server | PHP built-in server at `127.0.0.1:8099`, with the isolated test prepend |
| Production Apache site | Not timed, because it uses `lending_management` |

## Function timings

`PERF-01`, one process, status Passed as a measurement:

| Operation | Samples | Total time |
| --- | ---: | ---: |
| `calculateLoanInterestBreakdown(10000, 5, 12)` | 1,000 | 0.746 ms |
| `buildPaymentSchedule()` for a 12-month monthly loan | 200 | 11.486 ms |
| `calculateLoanBalanceSummary()` for one synthetic loan | 100 | 395.272 ms |

The balance call is slower because each call reads the loan, the payments, and the overdue schedule.

## Page timings that are usable

Anonymous staff login page, five sequential GET requests (`HTTP-15` login samples):

| Sample | Milliseconds |
| ---: | ---: |
| 1 | 38.77 |
| 2 | 64.98 |
| 3 | 38.20 |
| 4 | 22.97 |
| 5 | 35.94 |
| Average | 40.17 |

The authenticated samples inside `HTTP-15` are not used. That script's second login returned HTTP 200, and the following client and report requests returned HTTP 302. Those numbers are redirects, not page render times. `HTTP-15` remains Failed for that reason.

A separate run logged in once as the synthetic administrator and then requested each page five times. Every response was HTTP 200. Source: `testing/evidence/authenticated_timings.json`.

| Page | Five samples (ms) | Average (ms) |
| --- | --- | ---: |
| `clients_lending.php` | 112.23, 79.78, 55.72, 101.56, 104.75 | 90.81 |
| `reports_lending.php` | 66.20, 85.51, 63.04, 76.10, 96.67 | 77.50 |
| `dashboard_lending.php` | 73.99, 137.85, 98.22, 109.22, 107.09 | 105.27 |

## Not measured

- Loan application submission through the wizard
- Payment recording through the payment form
- Concurrent users
- The production Apache and `lending_management` database
- Network delay from another office computer

Loan schedule generation was measured only as the in-process function above. Payment recording was executed in `FIN-28` but was not timed.
