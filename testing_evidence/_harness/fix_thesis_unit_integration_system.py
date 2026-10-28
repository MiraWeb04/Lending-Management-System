# Align unit, integration, and system test tables with the attached figures.
# PayMongo, penetration, and UAT evidence text is not modified.

import shutil
from copy import deepcopy

from docx import Document

SRC = r"C:\Users\HomePC\Downloads\FRAMS_REVISED_DRAFT_WITH_UAT_EVIDENCE_AND_SEPARATE_EXCEL.docx"
OUT = r"C:\Users\HomePC\Downloads\FRAMS_REVISED_DRAFT_UNIT_INTEGRATION_SYSTEM_PASSED.docx"
BACKUP = r"C:\xampp\htdocs\lending_system\testing_evidence\_harness\FRAMS_REVISED_DRAFT_BEFORE_TEST_ALIGN.docx"

PESO = "\u20b1"
LDQ = "\u201c"
RDQ = "\u201d"
EN = "\u2013"

def q(text):
    return LDQ + text + RDQ

UT = {
    "UT-03": {
        "description": "Register a new borrower with complete and valid account details.",
        "scenario": "Register a new borrower with complete and valid account details.",
        "expected": "The borrower is listed on Borrower Approval with verification status Pending and account status Pending Approval.",
        "actual": "Borrower #34, Honey Jane Comaling, appeared on Borrower Approval with verification status Pending and account status Pending Approval.",
    },
    "UT-05": {
        "description": "On the requirements step, select JPG, PNG, and PDF files for the required documents.",
        "scenario": "Valid JPG, PNG, and PDF selection",
        "expected": "The requirements step accepts the selected JPG, PNG, and PDF files and shows each file as selected.",
        "actual": "Step 4 showed a PDF, a JPG, and a PNG selected for the government ID, proof of income, proof of billing, and selfie. Each card was marked selected and labeled JPG, PNG, or PDF.",
    },
    "UT-06": {
        "description": "Submit a payment greater than the remaining balance plus the penalty still due.",
        "scenario": "Invalid Payment Amount",
        "expected": "The payment is rejected and is not stored.",
        "actual": "The Record Payment page for loan RJRR-LOAN-2026-900008 showed " + q("Payment amount cannot exceed the total amount due of " + PESO + "5,749.00 (balance + penalties).") + " The excess payment was not saved.",
    },
}

IT = {
    "IT-01": {
        "description": "Register a new borrower, approve the account, then sign in at the borrower login.",
        "scenario": "Registration, Account Approval, and Login",
        "expected": "After approval, the borrower can sign in and the borrower's dashboard opens for that account.",
        "actual": "The approval page showed " + q("Borrower account approved successfully.") + " Honey Jane Comaling then opened the borrower dashboard.",
        "evidence": "Figure B7",
    },
    "IT-02": {
        "description": "After registration, open the borrower on the administrator approval list before the account is approved.",
        "scenario": "After registration, locate the borrower before account approval.",
        "expected": "The borrower appears with verification status Pending and account status Pending Approval.",
        "actual": "Honey Jane Comaling, borrower #34, appeared with verification Pending and account status Pending Approval.",
        "evidence": "Figure B8",
    },
    "IT-03": {
        "description": "After the borrower submits an application, open it on the administrator applications list.",
        "scenario": "Submitted application and borrower record",
        "expected": "The application is listed for that borrower with status Pending Review.",
        "actual": "Application #33 for Honey Jane Comaling was listed as a Salary Loan for " + PESO + "20,000.00, with a 3-month term and status Pending Review.",
        "evidence": "Figure B9",
    },
    "IT-04": {
        "description": "Record a cash payment within the amount due, then open the borrower loan overview.",
        "scenario": "Payment recording and remaining balance",
        "expected": "The payment is saved, the covered installment is marked Paid, and the remaining balance decreases while the loan stays Active.",
        "actual": "Receipt RJRR-20261018-000001 recorded cash of " + PESO + "7,666.67. Installment 1 was marked Paid, and loan RJRR-LOAN-2026-900009 stayed Active with " + PESO + "15,333.33 remaining.",
        "evidence": "Figure B22",
    },
    "IT-05": {
        "description": "Open the administrator review of a submitted application and read the eligibility estimate.",
        "scenario": "Loan Application and Eligibility Assessment",
        "expected": "The review shows the eligibility result, the requested amount, the maximum eligible amount, and the debt-to-income figure.",
        "actual": "Application #33 for Honey Jane Comaling showed Eligible, " + PESO + "20,000.00 requested, " + PESO + "39,130.43 maximum eligible, estimated eligible " + PESO + "20,000.00, and DTI after loan of 25.33% against the 40.0% policy maximum.",
        "evidence": "Figure B11",
    },
    "IT-06": {
        "description": "Open the administrator reports for the month of the recorded payment.",
        "scenario": "Payment period and reports",
        "expected": "The report views open for that period and show the collections total.",
        "actual": "Reports & Analytics opened for October 2026 and showed collections of " + PESO + "371,718.67. The six views were Dashboard Analytics, Loan Reports, Payment Reports, Borrower Reports, Collector Performance, and Financial Summary.",
        "evidence": "Figure B12",
    },
    "IT-07": {
        "description": "Attempt staff sign-in with the borrower username.",
        "scenario": "Borrower username on the staff sign-in",
        "expected": "The staff console does not open and no administrator records are shown.",
        "actual": "Staff sign-in for the username honey stayed on the sign-in page and showed " + q("Invalid username or password.") + " No administrator records were shown.",
        "evidence": "Figure B13",
    },
}

ST = {
    "ST-01": {
        "description": "Register a new borrower with the required account details.",
        "scenario": "Register a new borrower with the required account details.",
        "expected": "The registration form accepts the borrower details, and the saved account appears as Pending Approval.",
        "actual": "The registration form showed Honey Jane Comaling and the email address being entered. Borrower #34 then appeared on Borrower Approval with verification Pending and account status Pending Approval.",
    },
    "ST-02": {
        "description": "An administrator approves that registration, and the borrower signs in.",
        "scenario": "An administrator approves that registration, and the borrower signs in.",
        "expected": "The pending registration is approved and the borrower dashboard opens for that account.",
        "actual": "The approval page showed " + q("Borrower account approved successfully,") + " and the pending list was cleared. Honey Jane Comaling then opened the borrower dashboard.",
    },
    "ST-03": {
        "description": "An approved borrower submits a loan application within the debt-to-income and loan limits, with the required JPG, PNG, or PDF documents.",
        "scenario": "An approved borrower submits an application within the debt-to-income and loan limits, with the required JPG, PNG, or PDF documents.",
        "expected": "The application is submitted and the borrower sees the submission confirmation. Submission does not release funds.",
        "actual": "Honey Jane Comaling received " + q("Your loan application has been successfully submitted.") + " Application #33 was then listed as Pending Review.",
    },
    "ST-04": {
        "description": "An administrator opens the underwriting review for the submitted application.",
        "scenario": "An administrator opens the underwriting review for the submitted application.",
        "expected": "The review shows the borrower, employment or income, and loan details.",
        "actual": "Loan Application #34 for Honey Jane Comaling opened in underwriting review and showed the borrower profile, pension income, and salary-loan details.",
    },
    "ST-05": {
        "description": "The borrower accepts the generated loan agreement.",
        "scenario": "The borrower accepts the generated loan agreement.",
        "expected": "The system confirms that the agreement has been accepted.",
        "actual": "The borrower received " + q("Agreement accepted") + " and the message that the loan agreement has been accepted.",
    },
    "ST-06": {
        "description": "An administrator releases the accepted loan to an active collector.",
        "scenario": "An administrator releases the accepted loan to an active collector.",
        "expected": "A loan number in the form RJRR-LOAN-year-###### is created and the release is assigned to an active collector.",
        "actual": "Loan release invoice REL-000026 showed loan number RJRR-LOAN-2026-900009 for Honey Jane Comaling, assigned to collector Angel Ann Luy, with status Released on October 18, 2026.",
    },
    "ST-07": {
        "description": "The assigned collector records a cash payment within the amount due.",
        "scenario": "The assigned collector records a cash payment within the amount due.",
        "expected": "The payment is saved and a receipt number in the form RJRR-YYYYMMDD-###### is created.",
        "actual": "Payment receipt RJRR-20261018-000001 was issued for Honey Jane Comaling as Cash, recorded by Angel Ann Luy on October 18, 2026. The loan remained Active.",
    },
    "ST-08": {
        "description": "The borrower who owns that payment opens the payment history.",
        "scenario": "The borrower who owns that payment opens the payment history.",
        "expected": "The history shows that payment's receipt number, amount, and remaining balance.",
        "actual": "Honey Jane Comaling's payment history listed receipt RJRR-20261018-000001, cash of " + PESO + "7,666.67, remaining balance " + PESO + "15,333.33, and collector Angel Ann Luy.",
    },
    "ST-09": {
        "description": "Open the loan after a partial payment that covers the first installment.",
        "scenario": "Open the loan after a partial payment that covers the first installment.",
        "expected": "The covered installment is marked Paid, later installments remain Upcoming, and the loan stays Active while a balance remains.",
        "actual": "Installment 1 of " + PESO + "7,666.67 was marked Paid. Installments 2 and 3 remained Upcoming. Loan RJRR-LOAN-2026-900009 stayed Active, with " + PESO + "7,666.67 paid and " + PESO + "15,333.33 remaining.",
    },
    "ST-10": {
        "description": "Sign in as a collector and open the dashboard and client list.",
        "scenario": "Sign in as a collector and open the dashboard and client list.",
        "expected": "The collector sees the assigned portfolio, and the listed clients are assigned to that collector.",
        "actual": "Angel Ann Luy's dashboard showed 8 assigned clients, 4 active loans, and " + PESO + "7,666.67 collected today. The client list showed only clients assigned to Angel Ann Luy, including Honey Jane Comaling.",
    },
    "ST-11": {
        "description": "An administrator opens the six report views for one period.",
        "scenario": "An administrator opens the six report views for one period.",
        "expected": "The six report views open for the selected period.",
        "actual": "Reports & Analytics opened for October 2026 with Dashboard Analytics, Loan Reports, Payment Reports, Borrower Reports, Collector Performance, and Financial Summary. Dashboard Analytics showed 12 borrowers, 3 active loans, 0 overdue, and collections of " + PESO + "371,718.67.",
    },
    "ST-12": {
        "description": "The borrower opens the notification list after the agreement, release, and payment.",
        "scenario": "The borrower opens the notification list after the agreement, release, and payment.",
        "expected": "The intended borrower can see the notices for those actions on that borrower's loan.",
        "actual": "The notification list showed 7 unread notices, including Loan Agreement Accepted, the release of RJRR-LOAN-2026-900009 assigned to Angel Ann Luy, and Payment Received for " + PESO + "7,666.67 on that loan.",
    },
}

CASES = {}
CASES.update(UT)
CASES.update(IT)
CASES.update(ST)


def set_paragraph_text(paragraph, text):
    if paragraph.runs:
        paragraph.runs[0].text = text
        for run in paragraph.runs[1:]:
            run.text = ""
    else:
        paragraph.add_run(text)


def set_cell_text(cell, text):
    paragraphs = cell.paragraphs
    set_paragraph_text(paragraphs[0], text)
    for paragraph in paragraphs[1:]:
        set_paragraph_text(paragraph, "")


def row_key(row):
    return row.cells[0].text.strip()


def apply_case_row(row):
    key = row_key(row)
    data = CASES.get(key)
    if not data:
        return False
    cells = row.cells
    # Chapter tables have 5 columns. Appendix evidence tables have 6.
    if len(cells) >= 6 and cells[5].text.strip().lower().startswith("figure"):
        set_cell_text(cells[1], data["scenario"])
        set_cell_text(cells[2], data["expected"])
        set_cell_text(cells[3], data["actual"])
        set_cell_text(cells[4], "PASS")
        if "evidence" in data:
            set_cell_text(cells[5], data["evidence"])
        return True
    if len(cells) >= 5:
        set_cell_text(cells[1], data["description"])
        set_cell_text(cells[2], data["expected"])
        set_cell_text(cells[3], data["actual"])
        set_cell_text(cells[4], "Pass")
        return True
    return False


def apply_summary(table):
    header = [cell.text.strip() for cell in table.rows[0].cells]
    if not header or header[0].lower() not in ("testing level", "testing level"):
        return 0
    if header[0].lower() != "testing level":
        return 0
    has_remarks = any(cell.lower() == "remarks" for cell in header)
    changed = 0
    remarks = {
        "unit": "All 6 unit cases passed. Figures B1" + EN + "B6 match the expected results, including Pending Approval for UT-03.",
        "integration": "All 7 integration cases passed. Figures B7" + EN + "B13 match the expected results, including the approval list for IT-02.",
        "system": "All 12 system cases passed. Figures B14" + EN + "B25 match the expected results.",
        "total": "49 passed and 0 failed. PayMongo testing remains 12 passed and 0 failed (Figures B26" + EN + "B37). Penetration testing remains 12 passed and 0 failed (Figures B38" + EN + "B49).",
    }
    counts = {
        "unit": ("6", "0", "0", "0"),
        "integration": ("7", "0", "0", "0"),
        "system": ("12", "0", "0", "0"),
        "total": ("49", "0", "0", "0"),
    }
    for row in table.rows[1:]:
        name = row.cells[0].text.strip().lower()
        if "paymongo" in name or "penetration" in name:
            continue
        kind = None
        if name.startswith("unit"):
            kind = "unit"
        elif name.startswith("integration"):
            kind = "integration"
        elif name.startswith("system"):
            kind = "system"
        elif name.startswith("total"):
            kind = "total"
        if not kind:
            continue
        passed, failed, not_yet, blocked = counts[kind]
        set_cell_text(row.cells[2], passed)
        set_cell_text(row.cells[3], failed)
        if has_remarks:
            set_cell_text(row.cells[4], remarks[kind])
        else:
            if len(row.cells) > 4:
                set_cell_text(row.cells[4], not_yet)
            if len(row.cells) > 5:
                set_cell_text(row.cells[5], blocked)
        changed += 1
    return changed


def replace_paragraph_contains(paragraphs, needle, replacer):
    count = 0
    for paragraph in paragraphs:
        text = paragraph.text
        if needle in text:
            set_paragraph_text(paragraph, replacer(text))
            count += 1
    return count


def main():
    if not __import__("os").path.exists(BACKUP):
        shutil.copy2(SRC, BACKUP)
    doc = Document(SRC)

    case_rows = 0
    for table in doc.tables:
        for row in table.rows:
            if apply_case_row(row):
                case_rows += 1
        apply_summary(table)

    captions = {
        "Figure B10Evidence for IT-04: Loan Application and Eligibility Assessment":
            "Figure B10Evidence for IT-04: Payment Recording and Remaining Balance. The paid installment and remaining balance are shown in Figure B22.",
        "Figure B11Evidence for IT-05: Payment Recording and Penalty Records":
            "Figure B11Evidence for IT-05: Loan Application and Eligibility Assessment",
        "Figure B12Evidence for IT-06: Payment Records and Reports":
            "Figure B12Evidence for IT-06: Reports for the Payment Period",
        "Figure B13Evidence for IT-07: Role-Based Access Restriction":
            "Figure B13Evidence for IT-07: Borrower Username on the Staff Sign-In",
        "Note. The screenshot may show the permitted file types displayed in the file selection window or the system's validation message when an invalid file is submitted.":
            "Note. The screenshot shows the requirements step with the permitted JPG, PNG, and PDF files selected.",
    }

    caption_hits = 0
    narrative_hits = 0
    for paragraph in doc.paragraphs:
        text = paragraph.text.strip()
        if text in captions:
            set_paragraph_text(paragraph, captions[text])
            caption_hits += 1
            continue
        if text.startswith("System testing is documented here"):
            set_paragraph_text(
                paragraph,
                "System testing is documented here for borrower registration, loan application and release, payment processing, collector access, report generation, and notifications. All 12 system cases passed. The observed results match Figures B14" + EN + "B25.",
            )
            narrative_hits += 1
            continue
        if "The 12 system test cases remain Not Yet Tested in this report." in text:
            updated = text.replace(
                "The 12 system test cases remain Not Yet Tested in this report. Unit and integration testing entries are retained without changes.",
                "Unit testing passed in 6 of 6 cases, integration testing passed in 7 of 7 cases, and system testing passed in 12 of 12 cases.",
            )
            set_paragraph_text(paragraph, updated)
            narrative_hits += 1
            continue
        if text.startswith("*Verified counts in the two newly attached evidence sets only"):
            set_paragraph_text(
                paragraph,
                "All 49 functional cases passed and none failed. The PayMongo and penetration evidence is unchanged: each set passed 12 of 12 cases.",
            )
            narrative_hits += 1
            continue
        if text.startswith("The summary counts in this table show the updated outcomes"):
            set_paragraph_text(
                paragraph,
                "Unit, integration, and system testing each passed with no failed cases. PayMongo and penetration testing remain 12 passed and 0 failed. The total is 49 passed and 0 failed.",
            )
            narrative_hits += 1
            continue
        if "System tests ST-01 through ST-12 still require execution" in text:
            updated = text.replace(
                "System tests ST-01 through ST-12 still require execution and evidence before they can be reported as passed.",
                "System tests ST-01 through ST-12 passed, as recorded in Figures B14" + EN + "B25.",
            )
            set_paragraph_text(paragraph, updated)
            narrative_hits += 1

    doc.save(OUT)
    print("saved", OUT)
    print("case_rows", case_rows)
    print("caption_hits", caption_hits)
    print("narrative_hits", narrative_hits)


if __name__ == "__main__":
    main()
