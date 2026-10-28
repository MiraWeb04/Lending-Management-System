# Manual tasks still required

Date: 19 October 2026

These items were not completed by the automated session. The original database was not modified.

## Research records only the researcher can supply

- Completed questionnaires, or the item-frequency tally, for the 26 statements in Form C.2
- The respondent counts behind the 30 borrowers, 1 administrator, and 1 collector
- The source of the 4.81 series, which is not in the chapter file stored in this project
- A resolution of the conflict inside `FRAMS_Chapters_1_to_5_UAT_updated.docx`: Table 4.6 and Table 4.7 state results, and Chapter 5 says the numbers have not been entered
- Any signed client-acceptance page cited by Chapter 4

## Office workflow that still needs a person at the screen

Run these on a copy or on `frams_capstone_test`, not on the live borrower records.

1. Register a new synthetic borrower and confirm the pending account and success page (`UT-03`, `ST-01`).
2. Approve that account and sign in as the borrower (`IT-01`, `ST-02`).
3. Submit the five-step application with the five required files, then open the administrator review (`IT-02`, `ST-03`, `ST-04`).
4. On the loan step, enter an amount that fails the 40% debt-to-income rule and confirm the wizard message (`UT-04`).
5. Upload a disallowed file type and a file larger than 8 MB (`UT-05`, `PT-10`).
6. Generate the agreement, accept it with the borrower name, assign an active collector, and release the loan (`IT-03`, `ST-05`, `ST-06`).
7. Record a cash payment from the payment screen, open the borrower history, and download the PDF receipt (`IT-04`, `ST-07`, `ST-08`).
8. Open the penalty page after a penalty portion and confirm the figure (`IT-05`).
9. Open a report for that payment date and compare the total with the stored payment (`IT-06`, `ST-11`).
10. Pay the synthetic loan down to zero and confirm the loan status becomes Paid (`ST-09`).
11. Mark a notification read and confirm the unread count (`ST-12`).
12. Exercise an expired and a reused password-reset code (`PT-12`).
13. As a borrower, request another borrower's receipt URL (`PT-08`).
14. As a collector, request a loan assigned to the other collector (`PT-09` in the browser; the function check already passed).

## PayMongo tasks

1. Replace the local live mode with test keys and set the webhook secret. Rotate the live keys that are already in the local `.env`. Do not paste the keys into the manuscript.
2. Start an HTTPS tunnel and register the webhook for `payment.paid`, `payment.failed`, `qrph.expired`, and `payment_intent.awaiting_payment_method`.
3. Create one QR for a synthetic loan, leave it unpaid, and show that the balance does not change (`PM-01`, `PM-02`).
4. Complete one PayMongo test-mode payment and keep the provider event id (`PM-09`, `PM-10`).
5. Confirm a second open QR is blocked (`PM-08`) and that a linked payment cannot be deleted (`PM-12`).

## Screenshots still missing

The three files in `testing/evidence/browser/` cover the isolated dashboard, the invalid staff login, and the public calculator. The manuscript figures 4.1 through 4.16 still need captures of registration, the five application steps, eligibility, agreement, release, payment entry, penalties, reports, and user management. Capture them from the isolated server or from sample records that are allowed in the manuscript. Do not photograph live borrower documents or API keys.

## Code defects to fix before calling the build final

These failed in this session. They were not changed.

- Reject a loan term below 1 month instead of charging one month (`FIN-09`, `FIN-10`).
- Make installment totals add up to total payable, and keep weekly and daily due dates on their own interval (`FIN-15`, `FIN-16`, `FIN-17`).
- Make the public calculator use the same 5% flat formula as the loan engine, or relabel it if the amortizing figure is only an illustration (`FIN-19`).
- Stop raising an unaffordable principal to the 3,000 minimum (`FIN-25`).
- Make the loans page outstanding subtract payments (`FIN-34`).
- Add the missing browser headers if the manuscript's security discussion requires them (`HTTP-02`).

## Database work that should wait for a backup

- A baseline schema for the original tables
- Foreign keys for loans, payments, clients, and schedules, after orphan rows are reviewed
- No automatic destructive migration
