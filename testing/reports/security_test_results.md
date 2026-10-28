# Security test results

Execution date: 19 October 2026

These checks were limited to the local workstation and the isolated database `frams_capstone_test`. No external system was attacked. This report does not say that FRAMS is completely secure.

## Executed checks

| ID | Check | Result | Evidence |
| --- | --- | --- | --- |
| SEC-01 | `password_hash()` with the default algorithm and `passwordMatches()` | Passed. A correct password matched. A wrong password did not. A plaintext stored value did not match. The hash prefix was `$2y$`. The hash is not stored in the report. | `verification_results.json` |
| SEC-02 | CSRF comparison | Passed. The session token matched. A wrong token and an empty token did not. | function call only |
| SEC-03 | Webhook HMAC | Passed. A current test-mode signature matched. A wrong signature, a missing header, a timestamp older than 300 seconds, and an empty secret were rejected. The secret used here was synthetic. | function call only |
| SEC-04 | Collector loan scope | Passed. Collector A could access the assigned synthetic loan and could not access the other collector's loan. | direct function call, not a browser session |
| SEC-05 | Login identifier `admin' OR '1'='1` | Passed. The prepared login returned false. | isolated database |
| HTTP-04 | Wrong password in the browser and over HTTP | Passed. The page showed `Invalid username or password`. | `HTTP-04_invalid_login.png` |
| HTTP-05 | Same SQL text over HTTP | Passed. Ordinary login failure, no redirect into a dashboard. | `http_checks.json` |
| HTTP-06 | Username `<script>alert(1)</script>` | Passed. The login page escaped it. The raw script tag was not present. | `http_checks.json` |
| HTTP-07 | `users_lending.php` without a session | Passed. HTTP 302 to the staff login. | `http_checks.json` |
| HTTP-09 | Collector opened expenses | Passed. HTTP 403 and the unauthorized message path. | `http_checks.json` |
| HTTP-10 | Borrower opened user management | Passed. Borrower dashboard opened. User management did not return HTTP 200. | `http_checks.json` |
| HTTP-11 | Collector payment POST without a CSRF token | Passed. HTTP 419. Payment count stayed unchanged. | `http_checks.json` |
| HTTP-12 | Logout, then dashboard | Passed. The next request redirected to the staff login. | `http_checks.json` |
| HTTP-13 | Invalid webhook signature | Passed. HTTP 401 `invalid_signature`. | local simulation |
| HTTP-02 | Hardening headers on the login response | Failed. `X-Frame-Options`, `Content-Security-Policy`, and `X-Content-Type-Options` were absent. | `http_checks.json` |

Staff login does not call `csrfRequireValid()`. Payment posting does. The CSRF result covers the payment post that was sent, not every form in the application.

`passwordMatches()` no longer accepts a plaintext comparison. New passwords use `password_hash()`.

## Inspection findings that were not full attack tests

- `executeQuery()` prepares the SQL and binds parameters. The login query uses that path.
- `getRow()` interpolates the table name and the WHERE text. Callers that pass only bound parameters are safe. A caller that concatenates user input into the WHERE string would not be. Every caller was not executed.
- Session cookies are configured `HttpOnly` and `SameSite=Lax`. The secure flag follows HTTPS. This local HTTP server did not set the secure flag.
- The project `.env` contains live PayMongo keys and an empty webhook secret. `.env` is listed in `.gitignore`. The keys were not printed and were not used by the test process. They should be rotated because they are stored in a local environment file, and this workstation should use test keys.
- The webhook handler fails closed when the secret is empty (HTTP 503) and when the signature is wrong (HTTP 401).
- Output escaping was demonstrated on the login username only.

## Not executed

- Stored markup in names, remarks, and search results
- Another borrower's receipt or document URL
- Password-reset token reuse
- File-upload rejection
- A review of every query for concatenation
- Any test against a host other than this workstation

Failed financial cases in `financial_validation.md` are calculation defects. They are not, by themselves, authentication bypasses.
