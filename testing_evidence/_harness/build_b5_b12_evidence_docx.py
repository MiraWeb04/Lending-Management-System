from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.shared import Inches, Pt, RGBColor

IMG = r"C:\xampp\htdocs\lending_system\testing_evidence\_harness\thesis_imgs"
OUT = r"C:\Users\HomePC\Downloads\FRAMS_Evidence_Figure_B5_B10_B11_B12.docx"

doc = Document()
section = doc.sections[0]
section.top_margin = Inches(0.7)
section.bottom_margin = Inches(0.7)
section.left_margin = Inches(0.75)
section.right_margin = Inches(0.75)

title = doc.add_heading("FRAMS evidence screenshots", level=0)
title.alignment = WD_ALIGN_PARAGRAPH.LEFT

intro = doc.add_paragraph(
    "These screenshots were taken from the Financial Risk Assessment and Micro-Lending System (FRAMS). "
    "They are the evidence for Figure B5, Figure B10, Figure B11, and Figure B12."
)
intro.paragraph_format.space_after = Pt(12)


def add_label(text, size=16):
    paragraph = doc.add_paragraph()
    run = paragraph.add_run(text)
    run.bold = True
    run.font.size = Pt(size)
    run.font.color.rgb = RGBColor(0x1F, 0x3A, 0x5F)
    paragraph.paragraph_format.space_before = Pt(14)
    paragraph.paragraph_format.space_after = Pt(2)
    return paragraph


def add_note(text):
    paragraph = doc.add_paragraph(text)
    paragraph.paragraph_format.space_after = Pt(8)
    return paragraph


def add_image(path, caption):
    doc.add_picture(path, width=Inches(6.4))
    last = doc.paragraphs[-1]
    last.alignment = WD_ALIGN_PARAGRAPH.CENTER
    cap = doc.add_paragraph(caption)
    cap.alignment = WD_ALIGN_PARAGRAPH.CENTER
    for run in cap.runs:
        run.italic = True
        run.font.size = Pt(10)
        run.font.color.rgb = RGBColor(0x55, 0x55, 0x55)
    cap.paragraph_format.space_after = Pt(12)


add_label("Figure B5")
add_note("Evidence for UT-05: Invalid Document Upload")
add_note("Note. The screenshot shows the requirements step with the permitted JPG, PNG, and PDF files selected.")
add_image(
    IMG + r"\image41.png",
    "Requirements step. The selected files are PDF, JPG, and PNG, and each card is labeled JPG, PNG, or PDF.",
)

add_label("Figure B10")
add_note("Evidence for IT-04: Payment Recording and Remaining Balance. The paid installment and remaining balance are shown in Figure B22.")
add_image(
    IMG + r"\image56.png",
    "Cash payment receipt RJRR-20261018-000001 for Honey Jane Comaling, recorded by Angel Ann Luy.",
)
add_image(
    IMG + r"\image58.png",
    "Loan RJRR-LOAN-2026-900009 remains Active. Amount paid is ₱7,666.67 and the remaining balance is ₱15,333.33.",
)
add_image(
    IMG + r"\image59.png",
    "Installment 1 is Paid. Installments 2 and 3 remain Upcoming.",
)

add_label("Figure B11")
add_note("Evidence for IT-05: Loan Application and Eligibility Assessment")
add_image(
    IMG + r"\image46.png",
    "Application #33 for Honey Jane Comaling is Eligible. Requested amount ₱20,000.00, maximum eligible ₱39,130.43, and DTI after loan 25.33%.",
)

add_label("Figure B12")
add_note("Evidence for IT-06: Reports for the Payment Period")
add_image(
    IMG + r"\image48.png",
    "Reports & Analytics for October 2026, with Dashboard Analytics, Loan Reports, Payment Reports, Borrower Reports, Collector Performance, and Financial Summary.",
)

doc.save(OUT)
print(OUT)
