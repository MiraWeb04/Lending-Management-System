# FRAMS PayMongo Testing Report

Execution date: 18 October 2026

Results below come from the recorded test run against the isolated database `frams_capstone_test`. The existing `lending_management` database was not modified.

## Counts

| Executed | Passed | Failed | Blocked | Not Executed | Pass rate |
| --- | --- | --- | --- | --- | --- |
| 19 | 17 | 0 | 2 | 0 | 100.00% of 17 completed pass/fail tests |

Pass rate uses passed tests divided by passed plus failed tests. Blocked tests are not treated as passed.

## Test cases

| ID | Scenario | Expected | Actual | Status | Evidence | Remarks |
| --- | --- | --- | --- | --- | --- | --- |
| PM-001 | Invalid test key does not complete a payment | exception, transaction status failed, payment count unchanged | PayMongo could not create the QR payment (HTTP 401). API key [redacted-key] does not exist. Go to https://developers.paymongo.com/docs/authentication to know more about our API authentication.; status=failed; payments 1 to 1 | PASS | logs/cli_test_execution.log | Real HTTP call to api.paymongo.com using an invalid test-mode key. This is not a completed test-mode payment and not a live charge. |
| PM-002 | Zero QR amount | InvalidArgumentException and no new transaction row | rejected; rows 1 to 1 | PASS | logs/cli_test_execution.log | Local validation. No provider call because the amount fails first. |
| PM-003 | Cash is not sent to QR Ph | InvalidArgumentException | rejected | PASS | logs/cli_test_execution.log | Local validation. |
| PM-004 | QR amount above balance | amount exception and no succeeded payment | rejected | PASS | logs/cli_test_execution.log | Local validation before the provider call. |
| PM-005 | Simulated paid webhook posts one receipt | processed, transaction succeeded, one payment of 2750.00 | result processed; status succeeded; receipt RJRR-20261018-000002 | PASS | logs/cli_test_execution.log | Local simulation. PayMongo did not deliver this webhook and no live payment was created. |
| PM-006 | Duplicate paid webhook | duplicate and payment count stays 1 | duplicate; count 1 to 1 | PASS | logs/cli_test_execution.log | Local simulation. |
| PM-007 | Failed webhook does not post a receipt | transaction failed and zero payments | result processed; status failed; payments 0 | PASS | logs/cli_test_execution.log | Local simulation. |
| PM-008 | Mismatched paid amount | review_required and zero payments | result review_required; status review_required; payments 0 | PASS | logs/cli_test_execution.log | Local simulation. Loan B now has an unresolved review_required attempt. |
| PM-009 | Unknown payment intent | ignored | ignored | PASS | logs/cli_test_execution.log | Local simulation. |
| PM-010 | Unsupported event type | pending transaction unchanged | result processed; status pending | PASS | logs/cli_test_execution.log | Local simulation. This pending row is closed afterward so loan A can accept a cash payment. |
| PM-011 | Live PayMongo payment creation | No live charge is created. | No request was sent with the live key. | BLOCKED | logs/cli_test_execution.log | Blocked on purpose. The isolated process replaces live credentials with an invalid test key. |
| PM-012 | External webhook delivery | External delivery is not claimed. | Only local handler calls were executed. | BLOCKED | logs/cli_test_execution.log | Local simulations are not PayMongo deliveries. |
| PM-013 | HTTP webhook rejects invalid signature | HTTP 401 | HTTP 401 {"received":false,"status":"invalid_signature"} | PASS | 06_PayMongo_Testing/screenshots/PM-013_invalid_signature.txt | Local endpoint. The signature secret is synthetic. |
| PM-014 | HTTP webhook rejects missing signature | HTTP 401 | HTTP 401 {"received":false,"status":"invalid_signature"} | PASS | logs/http_test_execution.log | Local endpoint. |
| PM-015 | HTTP webhook rejects malformed JSON | HTTP 400 | HTTP 400 {"received":false,"status":"invalid_event"} | PASS | logs/http_test_execution.log | Local endpoint. Signature was calculated with the synthetic test secret. |
| PM-016 | Webhook GET is not accepted | HTTP 405 | HTTP 405 | PASS | logs/http_test_execution.log | Local endpoint. |
| PM-017 | Signed unknown event over HTTP | HTTP 200 ignored and payment count unchanged | HTTP 200 {"received":true,"status":"ignored"}; payments 3 to 3 | PASS | logs/http_test_execution.log | Local simulation delivered to the application endpoint. PayMongo did not send it. |
| PM-018 | Repeated HTTP delivery of the same event | duplicate response and payment count unchanged | HTTP 200 {"received":true,"status":"duplicate"}; payments 3 to 3 | PASS | logs/http_test_execution.log | Local simulation. |
| PM-019 | Isolated server mode | test | test | PASS | logs/http_test_execution.log | The project .env mode remains live and was not used by this server. |

## Findings in this category

### F-006 — High

- Component: PayMongo configuration
- Description: The project .env sets PAYMONGO_MODE=live and contains a live secret key and live public key. PAYMONGO_WEBHOOK_SECRET is empty. No live API request was sent during this test.
- Reproduction: Read the configuration keys without printing their values. Mode is live, the secret key prefix is sk_live, and the webhook secret is empty.
- Recommendation: Use test keys and PAYMONGO_MODE=test on this local workstation. Configure the matching webhook secret before accepting QR Ph events. Do not commit .env.
- Evidence: `06_PayMongo_Testing/logs/environment_probe.txt`
- Demonstrated: Configuration observed. No live charge was attempted.
- Retest: Not changed. Live credentials were not used.
