# FRAMS Testing Evidence Package

This folder contains the 18 October 2026 testing run for the Financial Risk Assessment and Micro-Lending System.

## What is included

- Six category folders with `report.md`, `logs/`, `test_results/`, and `screenshots/` where a real page or result was captured.
- `FINAL_TESTING_REPORT.md`, `.pdf`, and `.docx`
- `SUMMARY_REPORT.md`
- `TESTING_RESULTS.xlsx`
- `EVIDENCE_INDEX.md`

## How the tests were executed

1. The structure of `lending_management` was cloned into `frams_capstone_test` without copying rows.
2. Synthetic administrator, collector, and borrower accounts and two loans were inserted only in the test database.
3. CLI tests ran under `testing_evidence/_harness/env_prepend.php`, which forces the test database, disables outbound mail, and replaces PayMongo credentials with an invalid test key.
4. A PHP built-in server on 127.0.0.1:8099 used the same prepend. HTTP tests and the Cursor browser used that server.
5. The server started for this run was stopped afterward. The synthetic loan status used for the CSRF demonstration was restored to Active in the test database.

## Status labels

- PASS: the observed result met the expected result.
- FAIL: the observed result did not meet the expected result.
- BLOCKED: the case could not be completed because of a documented limit.
- NOT EXECUTED: the case was not run.

Blocked and not executed cases are not counted as passed. The pass rate is 95.83% (115 passed / 120 passed plus failed).

## Screenshots and logs

The package contains 21 PNG files from the browser. Seventeen are unique captures. Four security and PayMongo files are copies of system screenshots and are identified as shared context in the category reports: the payments list, the administrator dashboard, the borrower loan page, and the users page. Unit and integration testing ran in the PHP CLI, so those folders contain a note instead of a browser image. CLI logs are `cli_test_execution.log`. HTTP logs are `http_test_execution.log`.

## PayMongo

The invalid-key result is a real HTTP 401 from api.paymongo.com. Webhook paid, failed, duplicate, and unknown results are local simulations. Live payment creation and inbound PayMongo delivery were blocked. The live key was not printed and was not used.

## Safe reproduction

Use the harness scripts in `_harness` only with the prepend file. Do not point them at `lending_management`. The synthetic password is defined in `prepare_isolated_db.php` and is not a production password.
