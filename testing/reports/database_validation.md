# Database validation

Execution date: 19 October 2026

Engine: MariaDB 10.4.32.

The inspection was read-only for `lending_management`. Tests that inserted rows used `frams_capstone_test`, which was rebuilt from the table structures of `lending_management` and then filled with synthetic rows. The rebuild script compared source row counts before and after and reported that the source was unchanged. A later metadata check compared the same counts again. Differences: none.

No migration was applied. The PayMongo tables are already present, so a new migration was not created. Destructive statements were not run against `lending_management`.

## Tables required by the application

All of the following exist in both `lending_management` and `frams_capstone_test`:

`users`, `clients`, `loans`, `payments`, `expenses`, `notifications`, `borrower_applications`, `loan_applications`, `loan_application_documents`, `loan_application_reviews`, `loan_agreements`, `loan_releases`, `loan_payment_schedules`, `password_resets`, `paymongo_qrph_transactions`, `paymongo_qrph_webhook_events`

`lending_management` also contains `contact_inquiries`, `contact_inquiry_replies`, `email_notifications`, and `loan_release_notifications`.

Row counts were captured only as numbers in `testing/evidence/db_row_counts_before.json`. Individual borrower rows were not exported.

## Foreign keys

`information_schema` shows one foreign key in `lending_management`:

| Constraint | Table | References |
| --- | --- | --- |
| `fk_paymongo_qrph_webhook_transaction` | `paymongo_qrph_webhook_events.transaction_id` | `paymongo_qrph_transactions.id` with `ON DELETE SET NULL` |

No foreign key was found from `loans` to `clients`, from `payments` to `loans`, from `loan_payment_schedules` to `loan_releases`, or from `loan_releases` to `loan_applications`. The application keeps those relationships in queries. A deleted parent row is not stopped by the database. Integration test code from the earlier harness also observed that an invalid loan reference was not rejected by a foreign key and had to be rolled back by the test transaction.

## PayMongo structures

The migration comments target MariaDB 10.4.32. That matches this server. The migrations use `information_schema` checks and do not drop or delete rows.

Present on `paymongo_qrph_transactions`:

- Unique keys `paymongo_qrph_internal_reference_unique`, `paymongo_qrph_payment_intent_unique`, `uq_paymongo_qrph_provider_payment_id`, `uq_paymongo_qrph_payment_id`
- Lookup keys on loan/status, borrower/created, collector/created, and `expires_at`

Present on `payments`:

- Nullable `paymongo_qrph_transaction_id`
- Unique key `uq_payments_paymongo_qrph_transaction_id`

`README.md` says the repository has no migration folder. The folder `database/migrations/` exists and contains the two PayMongo files. The README's PayMongo setup steps otherwise match those files. There is still no install script for the original lending tables. A new machine cannot build the schema from the repository alone.

## Recommended corrections, not applied

1. Add a baseline schema dump for the original tables, taken from a backup and reviewed before it is committed. Do not generate it by altering `lending_management` during a test.
2. Add foreign keys only after the column types and existing orphan rows are checked. Likely pairs are `payments.loan_id` to `loans.loan_id`, `loans.client_id` to `clients.client_id`, and `loan_payment_schedules.release_id` to `loan_releases.id`. Do not add them with a script that deletes orphans automatically.
3. Keep the PayMongo migrations as they are. They do not need to be rerun on this database.
4. Correct `README.md` so it points at `database/migrations/` and no longer says the folder is absent.
5. Change `loans_lending.php` so outstanding balance subtracts recorded scheduled payments. That is an application defect confirmed as `FIN-34`, not a missing table.
