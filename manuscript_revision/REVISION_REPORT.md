# FRAMS documentation revision report

Date: 23 October 2026

The original manuscript files and the original testing-evidence files were not overwritten. Revised copies are in this folder. `README.md` at the project root was updated because it is the working setup guide. A copy is `README_REVISED.md` in this folder.

## Files changed

| File | What changed |
| --- | --- |
| `README.md` | Payment confirmation is described as Payment Intent polling while the QR page is open, plus one-at-a-time staff reconciliation. Provider-callback setup, signing-secret setup, and callback test commands were removed from the setup steps. |
| `manuscript_revision/README_REVISED.md` | Copy of the updated README. |
| `manuscript_revision/FRAMS_Manuscript_REVISED.docx` | Revised full manuscript, based on `testing_evidence/_harness/FRAMS_REVISED_DRAFT_BEFORE_TEST_ALIGN.docx`. |
| `manuscript_revision/FRAMS_QRPh_Figures_REVISED.docx` | Revised PayMongo figure appendix. Callback figures were removed. The retained deletion figure is Figure B34, PM-09. |
| `manuscript_revision/FRAMS_Penetration_Figures_REVISED.docx` | Captions shifted from B38–B49 to B35–B46 so they follow the nine retained PayMongo figures. Embedded image file names were not renamed. |
| `manuscript_revision/FRAMS_References_Questionnaires_and_Forms_REVISED.docx` | The QR Ph questionnaire note no longer says a provider callback posts the payment. |
| `manuscript_revision/Test_Cases_PayMongo_REVISED.docx` | Official retained PayMongo table, aligned to the 19 October 2026 figure evidence. |

These source documents had no PayMongo or callback wording, so they were not revised: `lending_system/documentation/FRAMS_Chapters_1_to_5_UAT_updated.docx`, `Chapter_4_System_Implementation_and_Results.docx`, and `Capstone20G4_Chapters_1_to_3_Revised.docx`.

## Official testing table after the removal

The figure-backed PayMongo table had 12 cases. Three were removed. Nine remain, all previously passed in the isolated test environment:

| New ID | Former ID | Retained result |
| --- | --- | --- |
| PM-01 | PM-01 | Invalid isolated test key. PayMongo returned HTTP 401. No QR page opened. Not a successful QR. |
| PM-02 | PM-02 | Payment form stayed open. Balance unchanged. |
| PM-03 | PM-03 | Cash hid Dynamic QR Ph. Cash recording stayed available. |
| PM-04 | PM-04 | Blank, zero, and nonnumeric amounts were rejected. |
| PM-05 | PM-05 | Amount above the total due was rejected. |
| PM-06 | PM-06 | A collector was refused for another collector’s loan. |
| PM-07 | PM-07 | Signed-out and borrower sessions could not start a QR payment. |
| PM-08 | PM-08 | A second QR request was refused while an earlier attempt was unresolved. |
| PM-09 | PM-12 | Deletion of a QR-linked repayment was refused. |

Summary counts that included those 12 cases were recalculated only by subtraction:

- PayMongo: 9 cases, 9 passed, 0 failed.
- Penetration: still 12 passed. Figure numbers are now B35–B46.
- Unit 6, integration 7, and system 12 were not given new pass counts. System testing remains not yet tested.
- Documented case total: 46. Verified asterisk total: 21 (9 PayMongo + 12 penetration). The manuscript states that this is not a claim that all 46 cases passed.

The contradictory older sheet `Test_Cases_PayMongo_Penetration_and_System_Testing.docx` said PM-01 opened a QR page and that a test-mode payment passed. Those claims were not copied. The attached figures show HTTP 401 and no QR image.

## Callback items removed from the official tables

Removed from Table 4.7 and from the figure appendix:

- Former PM-09. Local signed callback simulation that posted one repayment.
- Former PM-10. Replay of that same local event as a duplicate.
- Former PM-11. Invalid signature requests to the local callback address.
- Figures B34–B36 that illustrated those three cases, including `B36_pm11_webhook_responses.png`.
- Summary wording that counted those simulations in the 12/12 PayMongo result.
- The older test-sheet rows for delivering a signed callback and for rejecting a bad signature.
- The older test-sheet row that reported a completed test-mode payment as passed. That result is not supported by the figure evidence.

The 19-case package in `testing_evidence/06_PayMongo_Testing/report.md` was not overwritten. Its callback cases (PM-005 through PM-010 and PM-012 through PM-018 in that package) are outside the implemented workflow and are not part of the revised official total.

## Outside the implemented workflow

`lending_system/tools/paymongo_webhook_smoke_test.php` was run on 23 October 2026 and failed on its first assertion, `accepts a valid test signature`, because `PAYMONGO_MODE` is `live` and the fixture signs the test-mode field. That failure is not a failed FRAMS payment-confirmation test. The script was not rerun, and its later assertions did not execute. Do not put it in the official system-test totals.

## Source code deliberately left in place

These files still contain callback handling because polling and reconciliation call functions in the same files. They were not deleted and their payment behavior was not changed for this documentation revision:

- `lending_system/includes/paymongo_qrph_webhook.php` — also holds `lendingReconcilePaymongoQrphTransaction()`, the Payment Intent interpretation, and the staff permission check.
- `lending_system/includes/paymongo_qrph.php` — QR creation and `GET /payment_intents/{id}`.
- `lending_system/paymongo_webhook_lending.php` — unused by the selected confirmation workflow.
- `lending_system/tools/paymongo_webhook_smoke_test.php`
- `lending_system/tools/paymongo_webhook_register_cli.php`
- `lending_system/payments_lending.php` — five-second poll and **Check with PayMongo**.
- `database/migrations/20261014_create_paymongo_qrph_transactions.sql`
- `database/migrations/20261014_add_paymongo_qrph_webhooks.sql`
- Tables `paymongo_qrph_transactions` and `paymongo_qrph_webhook_events`.

The README still names the second migration file because that is the file that must be applied. The sentence says applying it is not a callback setup step.

## Testing limitations that must stay visible

- Successful QR generation was not verified. PM-01 and PM-02 show rejection by an invalid test key.
- A completed test-mode or live wallet payment was not verified in the retained evidence.
- The 23 October 2026 command-line check did not call PayMongo. It did not leave a receipt in the database. It covered an unpaid intent, a failed payment, a wrong amount, role checks, one rolled-back post, duplicate protection for the same provider payment, and a rolled-back cash insert. It did not run a separate wrong-currency case.
- The collector denial in that check used loan ID 2147483646 because the first active collector had no second real loan outside that assignment.
- The five-second timer is in the QR page source. It was not watched in a signed-in browser. An unsigned status request redirected to staff sign-in.
- Pending QR rows are not reconciled in the background.
- Twelve system test cases remain not yet tested, as the source manuscript already said.
