# Evidence index

Session date: 19 October 2026

Screenshots and JSON files below were produced in this session. The older `testing_evidence/` directory is a previous run from 18 October 2026 and is not indexed as today's proof.

## Reports

| File | Contents |
| --- | --- |
| `testing/reports/project_inspection.md` | Code and document inspection |
| `testing/reports/financial_validation.md` | FIN-01 to FIN-34 |
| `testing/reports/software_test_results.md` | Planned 49 cases and the checks actually run |
| `testing/reports/paymongo_test_results.md` | Local simulations and blocked provider delivery |
| `testing/reports/security_test_results.md` | Local authentication, CSRF, signature, and header checks |
| `testing/reports/performance_test_results.md` | Single-process timings |
| `testing/reports/database_validation.md` | Schema metadata |
| `testing/reports/uat_evidence_review.md` | Questionnaire and mean comparison |
| `testing/reports/missing_manual_tasks.md` | Remaining researcher and office tasks |
| `testing/reports/executed_test_results.csv` | Row-level results for the 63 executed records |

## Machine-readable results

| File | Test coverage |
| --- | --- |
| `testing/evidence/verification_results.json` | FIN-01–FIN-34, SEC-01–SEC-05, PERF-01, PM-SIM-01–PM-SIM-08 |
| `testing/evidence/http_checks.json` | HTTP-01–HTTP-15 |
| `testing/evidence/authenticated_timings.json` | Five HTTP 200 samples each for clients, reports, and dashboard |
| `testing/evidence/schema_inspection.json` | Tables, the one foreign key, PayMongo columns, production row-count comparison |
| `testing/evidence/db_row_counts_before.json` | Table counts taken before the isolated rebuild |

## Screenshots

| File | Case |
| --- | --- |
| `testing/evidence/browser/HTTP-08_admin_dashboard.png` | Synthetic administrator dashboard on the isolated server after today's test payments |
| `testing/evidence/browser/HTTP-04_invalid_login.png` | Staff login rejection for a wrong password |
| `testing/evidence/browser/FIN-19_public_calculator.png` | Public calculator showing ₱8,560.75 for 100,000 at the labeled 5% rate over 12 months |

## How the isolated run was kept off the original data

| File | Role |
| --- | --- |
| `testing/harness/probe_environment.php` | Read engine and table counts. Does not print secrets. |
| `testing/harness/run_verification.php` | Refuses to run unless the database is `frams_capstone_test` |
| `testing/harness/run_http_checks.php` | HTTP checks against `127.0.0.1:8099` |
| `testing/harness/inspect_schema.php` | Read-only metadata |
| `testing_evidence/_harness/env_prepend.php` | Forces the test database and blocks the live PayMongo keys from the test process |
| `testing_evidence/_harness/prepare_isolated_db.php` | Rebuilds only `frams_capstone_test` and aborts if `lending_management` counts change |

The PHP development server used for the browser and HTTP checks was stopped at the end of the session.
