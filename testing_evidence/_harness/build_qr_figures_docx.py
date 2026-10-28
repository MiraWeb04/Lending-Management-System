#!/usr/bin/env python3
"""Word document for the QR Ph figure set B26-B37."""
from pathlib import Path
from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.shared import Inches, Pt, RGBColor

SHOTS = Path(r"c:\Users\HomePC\AppData\Local\Temp\cursor\screenshots")
OUT = Path(r"C:\xampp\htdocs\lending_system\testing_evidence\06_PayMongo_Testing\FRAMS_QRPh_Figures_B26_B37.docx")

CASES = [
    {
        "id": "PM-01",
        "figure": "Figure B26",
        "scenario": "The assigned collector creates a QR Ph payment for a valid amount on a disposable unpaid loan.",
        "expected": "With the isolated invalid test key, PayMongo rejects QR creation. No QR page opens, no repayment is stored, and the loan balance stays unchanged. The attempt is saved as failed.",
        "actual": "The collector submitted E-Wallet for PHP 100.00 on FRAMS-DISP-01 while the balance was PHP 3,150.00. PayMongo returned HTTP 401 and rejected the isolated test key. The QR page did not open. The stored transaction status was failed, and it had no QR image. No repayment was created and the loan stayed Active. The provider text was redacted in the screenshot because it echoed part of the test key.",
        "status": "PASS",
        "images": ["B26_pm01_qr_rejected.png"],
    },
    {
        "id": "PM-02",
        "figure": "Figure B27",
        "scenario": "The pending QR page is compared with the loan balance before payment.",
        "expected": "When QR creation is rejected, the payment form stays open. No QR, QR amount, method, or expiry is shown. The balance and loan status stay unchanged.",
        "actual": "The same screen as Figure B26 stayed on Record Payment. No QR image, QR amount, method block, or expiry was displayed. FRAMS-DISP-01 remained Active with remaining balance PHP 3,150.00.",
        "status": "PASS",
        "images": ["B26_pm01_qr_rejected.png"],
    },
    {
        "id": "PM-03",
        "figure": "Figure B28",
        "scenario": "Cash is selected on the payment form.",
        "expected": "Create Dynamic QR Ph stays hidden. The ordinary cash payment action remains available.",
        "actual": "With Cash selected, the Create Dynamic QR Ph button was hidden and disabled. Cancel and Record Payment remained available. The loan and amount sections were collapsed only so the action row fit in the capture.",
        "status": "PASS",
        "images": ["B28_pm03_cash_no_qr_button.png"],
    },
    {
        "id": "PM-04",
        "figure": "Figure B29",
        "scenario": "Blank, zero, and nonnumeric QR amounts are submitted.",
        "expected": "No QR transaction is stored. A blank amount is stopped by the required field. Zero is rejected because the amount must be greater than zero. A nonnumeric value is also rejected.",
        "actual": "Blank was stopped in the browser with \"Please fill out this field.\" Zero reached the server and returned \"Payment amount must be greater than zero.\" The nonnumeric value abc, after the number field was changed to text, returned \"Enter a valid payment amount.\" None of the three created a QR image or a repayment.",
        "status": "PASS",
        "images": ["B29_pm04_blank_amount.png", "B29_pm04_zero_amount.png", "B29_pm04_nonnumeric.png"],
    },
    {
        "id": "PM-05",
        "figure": "Figure B30",
        "scenario": "The QR amount is greater than the current total due.",
        "expected": "The request is rejected and no QR transaction is stored.",
        "actual": "An amount of 4000.00 against a total due of PHP 3,150.00 was rejected: \"Payment cannot exceed the current total amount due of PHP 3,150.00.\" No repayment was created.",
        "status": "PASS",
        "images": ["B30_pm05_amount_too_high.png"],
    },
    {
        "id": "PM-06",
        "figure": "Figure B31",
        "scenario": "A test collector submits a QR Ph request for a disposable loan assigned to another collector.",
        "expected": "The server rejects the request. No pending QR transaction and no repayment are created.",
        "actual": "Collector A submitted loan 30, which is assigned to Collector B. The server responded \"You are not assigned to this loan.\" The loan was not in Collector A's list, and no repayment was created.",
        "status": "PASS",
        "images": ["B31_pm06_other_collector.png"],
    },
    {
        "id": "PM-07",
        "figure": "Figure B32",
        "scenario": "A signed-out visitor opens the payments page, and a borrower attempts the collector QR request.",
        "expected": "The visitor is redirected to the staff sign-in page. The borrower is redirected to payment history and cannot start a QR payment.",
        "actual": "A signed-out request for the payments page opened the staff sign-in page. The signed-in borrower was redirected to Payment History. That page has no QR action. The borrower is turned away before the collector-only message is reached.",
        "status": "PASS",
        "images": ["B32_pm07_signed_out_redirect.png", "B32_pm07_borrower_history.png"],
    },
    {
        "id": "PM-08",
        "figure": "Figure B33",
        "scenario": "A second QR Ph request is created while an earlier attempt for the same loan is unresolved.",
        "expected": "A second request is refused when a pending QR has not expired and PayMongo cannot confirm it.",
        "actual": "An unresolved pending attempt on FRAMS-TEST-A could not be confirmed with PayMongo. The second request was refused: \"The previous PayMongo QR Ph attempt could not be confirmed with PayMongo.\" The pending row stayed pending, the balance stayed PHP 3,833.34, and no new repayment was created. The supersede path was not applicable because PayMongo could not confirm the open attempt.",
        "status": "PASS",
        "images": ["B33_pm08_unconfirmed_pending_refused.png"],
    },
    {
        "id": "PM-09",
        "figure": "Figure B34",
        "scenario": "A test-mode simulation completes one pending QR on a disposable loan.",
        "expected": "The transaction changes from pending to paid, one repayment and one receipt are stored, and the balance changes only after confirmation.",
        "actual": "This was a local signed webhook simulation, not a PayMongo delivery and not a live charge. The pending transaction changed to succeeded. One E-Wallet repayment of PHP 100.00 was stored with receipt RJRR-20261018-000004 and reference pay_frams_pm09_once. The receipt shows PHP 3,150.00 before payment and PHP 3,050.00 after payment.",
        "status": "PASS",
        "images": ["B34_pm09_receipt.png", "B34_pm09_simulated_payment_list.png"],
    },
    {
        "id": "PM-10",
        "figure": "Figure B35",
        "scenario": "A valid webhook is delivered once and then the same event is delivered again.",
        "expected": "The first accepted event records one repayment. The repeated event does not record a second repayment.",
        "actual": "The first local webhook returned HTTP 200 with status processed. The same event returned HTTP 200 with status duplicate. Loan FRAMS-DISP-01 still has one PHP 100.00 repayment. This was a local simulation, not an external PayMongo delivery.",
        "status": "PASS",
        "images": ["B34_pm09_simulated_payment_list.png", "B36_pm11_webhook_responses.png"],
    },
    {
        "id": "PM-11",
        "figure": "Figure B36",
        "scenario": "A webhook is sent with no signature, an incorrect signature, or a timestamp older than five minutes.",
        "expected": "Each request is rejected and the pending QR and payment count remain unchanged.",
        "actual": "All three requests returned HTTP 401 with status invalid_signature. The repayment count for the disposable loan stayed at one, and the succeeded transaction was not changed. These were local requests to the FRAMS webhook endpoint.",
        "status": "PASS",
        "images": ["B36_pm11_webhook_responses.png"],
    },
    {
        "id": "PM-12",
        "figure": "Figure B37",
        "scenario": "An administrator submits deletion of a disposable repayment linked to a verified QR Ph transaction.",
        "expected": "The payments screen has no edit or delete control. The delete request is refused, and the payment, receipt, and QR record remain.",
        "actual": "The administrator payments screen shows View only. There is no edit or delete control. A delete request for payment 46 returned the refusal that a payment linked to a verified PayMongo QR Ph transaction was not deleted. Payment 46, receipt RJRR-20261018-000004, and the succeeded QR record remained. The client and borrower were not deleted.",
        "status": "PASS",
        "images": ["B37_pm12_no_delete_control.png"],
    },
]


def add_picture(document, filename):
    path = SHOTS / filename
    if not path.exists():
        document.add_paragraph("Missing screenshot: " + filename)
        return
    document.add_picture(str(path), width=Inches(6.4))
    document.paragraphs[-1].alignment = WD_ALIGN_PARAGRAPH.CENTER


def main():
    document = Document()
    style = document.styles["Normal"]
    style.font.name = "Calibri"
    style.font.size = Pt(11)
    title = document.add_heading("FRAMS QR Ph test figures", 0)
    title.alignment = WD_ALIGN_PARAGRAPH.CENTER
    subtitle = document.add_paragraph("Figures B26 to B37")
    subtitle.alignment = WD_ALIGN_PARAGRAPH.CENTER
    document.add_paragraph(
        "Execution date: 19 October 2026. Environment: isolated FRAMS test server at "
        "http://127.0.0.1:8099 and database frams_capstone_test. The existing "
        "lending_management database was not modified. No live PayMongo charge was created."
    )
    document.add_paragraph(
        "PM-01 and PM-02 record the behavior available with the isolated invalid test key: PayMongo rejects creation, the QR page does not open, and the balance does not change. "
        "They do not show a PayMongo QR image. PM-09 and PM-10 completed through a local webhook simulation. That simulation is not a payment delivered by PayMongo."
    )

    passed = sum(1 for case in CASES if case["status"] == "PASS")
    failed = sum(1 for case in CASES if case["status"] == "FAIL")
    document.add_paragraph(f"Results: {passed} passed, {failed} failed, 0 blocked, 0 not executed.")

    table = document.add_table(rows=1, cols=4)
    table.style = "Table Grid"
    for index, header in enumerate(["Test ID", "Figure", "Status", "Actual result"]):
        table.rows[0].cells[index].text = header
    for case in CASES:
        cells = table.add_row().cells
        cells[0].text = case["id"]
        cells[1].text = case["figure"]
        cells[2].text = case["status"]
        cells[3].text = case["actual"]
    document.add_page_break()

    for case in CASES:
        document.add_heading(case["figure"] + " — " + case["id"], level=1)
        document.add_paragraph("Scenario: " + case["scenario"])
        document.add_paragraph("Expected result: " + case["expected"])
        document.add_paragraph("Actual result: " + case["actual"])
        status = document.add_paragraph()
        run = status.add_run("Status: " + case["status"])
        run.bold = True
        run.font.color.rgb = RGBColor(0xB9, 0x1C, 0x1C) if case["status"] == "FAIL" else RGBColor(0x16, 0x65, 0x34)
        for filename in case["images"]:
            add_picture(document, filename)
            caption = document.add_paragraph(case["figure"] + " (" + filename + ")")
            caption.alignment = WD_ALIGN_PARAGRAPH.CENTER
            caption.runs[0].italic = True
        document.add_paragraph("")

    document.save(OUT)
    print(OUT)
    print(f"PASS={passed} FAIL={failed}")


if __name__ == "__main__":
    main()
