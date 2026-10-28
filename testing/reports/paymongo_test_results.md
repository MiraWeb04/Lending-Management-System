# PayMongo QR Ph results

Execution date: 19 October 2026

No live payment was created. The live keys in the project `.env` were not loaded by the test process. The test process substituted a synthetic invalid test key and a synthetic webhook secret. Those values are not printed in this report.

PayMongo did not deliver any webhook during this session. Events named `evt_local_simulation_*` were created by the test script and posted to the local handler or the local HTTP endpoint. They are simulations.

## Configuration found

| Item | Observation |
| --- | --- |
| Database engine | MariaDB 10.4.32 |
| `.env` mode | `live` |
| Secret key prefix | `sk_live` is present |
| Public key prefix | `pk_live` is present |
| Webhook secret | empty |
| Tables | `paymongo_qrph_transactions` and `paymongo_qrph_webhook_events` exist in `lending_management` |
| Code behavior | A collector starts QR Ph for Bank Transfer or E-Wallet. Cash does not start it. A verified `payment.paid` event can post one repayment. An empty webhook secret makes the HTTP endpoint return HTTP 503 |

The empty webhook secret means the application, as configured on this workstation, cannot accept a real PayMongo webhook until a secret is set and the endpoint is reachable by HTTPS.

## Executed checks

| ID | Check | Expected | Actual | Status |
| --- | --- | --- | --- | --- |
| SEC-03 | Local signature function | valid accepted; wrong, missing, stale, and empty secret rejected | all five matched | Passed |
| PM-SIM-01 | Local simulated `payment.paid` for 2,750.00 | one receipt, status succeeded, remaining balance 2,750.00 | processed, succeeded, remaining 2,750.00 | Passed |
| PM-SIM-02 | Same local event again | duplicate, payment count stays 1 | duplicate, count 1 | Passed |
| PM-SIM-03 | Local simulated `payment.failed` | status failed, no payment | failed, 0 payments | Passed |
| PM-SIM-04 | Local paid event whose amount does not match | `review_required`, no payment | `review_required`, payment count unchanged | Passed |
| PM-SIM-05 | Unknown payment intent | ignored | ignored | Passed |
| PM-SIM-06 | Create a QR with the synthetic invalid test key | provider rejects it, row failed, no repayment | HTTP 401 from `api.paymongo.com`, row `failed/creation_failed`, payment count unchanged | Passed |
| PM-SIM-07 | Local event log | at least 4 simulation rows | 4 or more `evt_local_simulation_%` rows | Passed |
| HTTP-13 | POST an invalid signature to the local webhook | HTTP 401 `invalid_signature` | HTTP 401 | Passed |
| HTTP-14 | GET the webhook | HTTP 405 | HTTP 405 | Passed |
| PM-SIM-08 | Live charge and a provider-delivered webhook | not attempted | not attempted | Blocked |

`PM-SIM-06` is an authentication failure against PayMongo using a key that is not a real test-merchant key. It did not create a QR image and did not post a repayment. The provider error text stored in the test database was replaced with `[redacted]` after the status was recorded.

`PM-SIM-01` updated a synthetic loan balance and stored a `RJRR-` receipt number. No email was sent because the synthetic client has no email address. A PDF receipt was not downloaded.

## Planned PM-01 to PM-12

| ID | Status | Why |
| --- | --- | --- |
| PM-01 QR creation screen | Not executed | The collector payment screen was not used to start a QR |
| PM-02 Balance unchanged while QR is pending | Not executed | No QR image was displayed |
| PM-03 Cash does not offer QR | Not executed | Screen not used. The code restricts QR labels to Bank Transfer and E-Wallet |
| PM-04 Zero amount | Not executed on the screen | Local payment validation rejects zero in `FIN-30` |
| PM-05 Amount above the balance | Not executed on the screen | `FIN-29` rejected an overpayment before any provider call |
| PM-06 Unassigned collector | Not executed on the screen | |
| PM-07 Borrower cannot start QR | Not executed | |
| PM-08 Second open QR is blocked | Not executed | |
| PM-09 Test-mode payment confirmation | Blocked | No PayMongo test-mode payment exists for this run |
| PM-10 Provider webhook with a valid signature | Not executed | Only local simulations were processed |
| PM-11 Invalid signature | Passed for the local endpoint | `HTTP-13` and `SEC-03` |
| PM-12 Delete a linked QR payment | Not executed | |

## What is required before a real QR test

1. Put PayMongo test keys in the server environment and set the mode to `test`. Do not use the live keys for manuscript testing.
2. Set the matching test webhook secret. Do not leave it empty.
3. Expose the local webhook with a temporary HTTPS tunnel. PayMongo cannot deliver events to `localhost`.
4. Register the tunnel URL for `payment.paid`, `payment.failed`, `qrph.expired`, and `payment_intent.awaiting_payment_method`.
5. Create a QR only for a synthetic loan in a test database.
6. Keep the provider event id, the redacted dashboard screenshot, and the local `paymongo_qrph_webhook_events` row. Do not describe a local script call as a provider delivery.
