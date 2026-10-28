# -*- coding: utf-8 -*-
from pathlib import Path
import shutil
import re
from copy import deepcopy

from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_LINE_SPACING
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_CELL_VERTICAL_ALIGNMENT
from docx.oxml.ns import qn
from docx.oxml import OxmlElement
from docx.shared import Cm, Pt, Twips, Inches, Emu
from docx.text.paragraph import Paragraph

SRC = Path(r"C:\Users\HomePC\Downloads\FRAMS_MANUSCRIPT_AUDIT_CORRECTED.docx")
DEST = Path(r"C:\Users\HomePC\Downloads\FRAMS_MANUSCRIPT_FINAL_REVIEWED.docx")
WORKSPACE = Path(r"C:\xampp\htdocs\lending_system\_frams_revision")
FRAMS_DIR = Path(r"C:\Users\HomePC\Downloads\FRAMS")
UNRESOLVED = Path(r"C:\Users\HomePC\Downloads\FRAMS_AUDIT_UNRESOLVED_ISSUES.docx")
CHANGES = Path(r"C:\Users\HomePC\Downloads\FRAMS_FINAL_REVIEW_CHANGE_SUMMARY.docx")
VERIFY_LOG = WORKSPACE / "final_review_verify.txt"

STATEMENTS = [
    ("A. Functionality", [
        "FRAMS allows authorized users to log in and access functions appropriate to their assigned roles.",
        "FRAMS allows borrowers to submit the information required for loan applications.",
        "FRAMS calculates loan interest and repayment amounts according to the system’s implemented formula.",
        "FRAMS allows authorized personnel to record and monitor loan payments.",
    ]),
    ("B. Usability", [
        "The FRAMS interface is organized and easy to understand.",
        "Menus and navigation help me find the functions needed for my role.",
        "Forms provide understandable instructions or validation messages when needed.",
        "I can complete the tasks relevant to my role without unnecessary difficulty.",
    ]),
    ("C. Reliability", [
        "FRAMS saves borrower, loan, and payment records consistently.",
        "Loan balances and installment schedules reflect the recorded transactions.",
        "FRAMS provides consistent information when I revisit a record or report.",
        "The system handles routine operations without unexpected interruption during my use.",
    ]),
    ("D. Security", [
        "FRAMS restricts pages and records according to the user’s assigned role.",
        "I can access only the information and functions permitted for my account.",
        "The system requires authentication before protected pages can be accessed.",
        "FRAMS provides appropriate protection for borrower and loan information during normal use.",
    ]),
    ("E. Performance", [
        "FRAMS loads the pages needed for my tasks within a reasonable time.",
        "The system processes routine forms and transactions within a reasonable time.",
        "FRAMS displays reports and loan information without excessive delay.",
        "The system remains responsive while I perform normal tasks.",
    ]),
]


def set_run_font(run, name="Times New Roman", size=12, bold=None, italic=None):
    run.font.name = name
    run._element.rPr.rFonts.set(qn("w:eastAsia"), name)
    run.font.size = Pt(size)
    if bold is not None:
        run.bold = bold
    if italic is not None:
        run.italic = italic


def replace_text(paragraph, new_text, size=12, bold=None):
    drawing_runs = []
    for run in paragraph.runs:
        xml = run._r.xml
        if "w:drawing" in xml or "w:pict" in xml:
            drawing_runs.append(run._r)
    for run in list(paragraph.runs):
        run._r.getparent().remove(run._r)
    for el in drawing_runs:
        paragraph._p.append(el)
    run = paragraph.add_run(new_text)
    set_run_font(run, size=size, bold=bold)


def set_cell_text(cell, text, size=10, bold=False, align="left", italic=False):
    cell.vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.CENTER
    if not cell.paragraphs:
        cell.add_paragraph()
    p0 = cell.paragraphs[0]
    p0.alignment = {
        "left": WD_ALIGN_PARAGRAPH.LEFT,
        "center": WD_ALIGN_PARAGRAPH.CENTER,
        "right": WD_ALIGN_PARAGRAPH.RIGHT,
    }[align]
    p0.paragraph_format.space_before = Pt(1)
    p0.paragraph_format.space_after = Pt(1)
    p0.paragraph_format.line_spacing = 1.0
    replace_text(p0, text, size=size, bold=bold)
    if italic and p0.runs:
        p0.runs[0].italic = True
    for extra in cell.paragraphs[1:]:
        extra.text = ""
    # no wrap overflow: allow wrap
    tc = cell._tc
    tcPr = tc.get_or_add_tcPr()
    tcW = tcPr.find(qn("w:tcW"))
    if tcW is None:
        tcW = OxmlElement("w:tcW")
        tcPr.append(tcW)


def shade_cell(cell, color):
    tcPr = cell._tc.get_or_add_tcPr()
    for old in tcPr.findall(qn("w:shd")):
        tcPr.remove(old)
    shd = OxmlElement("w:shd")
    shd.set(qn("w:val"), "clear")
    shd.set(qn("w:color"), "auto")
    shd.set(qn("w:fill"), color)
    tcPr.append(shd)


def set_table_fixed_width(table, width_twips, col_twips):
    tbl = table._tbl
    tblPr = tbl.tblPr
    tblW = tblPr.find(qn("w:tblW"))
    if tblW is None:
        tblW = OxmlElement("w:tblW")
        tblPr.append(tblW)
    tblW.set(qn("w:w"), str(width_twips))
    tblW.set(qn("w:type"), "dxa")
    layout = tblPr.find(qn("w:tblLayout"))
    if layout is None:
        layout = OxmlElement("w:tblLayout")
        tblPr.append(layout)
    layout.set(qn("w:type"), "fixed")
    # prevent autofit
    for gridCol, w in zip(tbl.find(qn("w:tblGrid")).findall(qn("w:gridCol")), col_twips):
        gridCol.set(qn("w:w"), str(w))
    for row in table.rows:
        for cell, w in zip(row.cells, col_twips):
            tcPr = cell._tc.get_or_add_tcPr()
            tcW = tcPr.find(qn("w:tcW"))
            if tcW is None:
                tcW = OxmlElement("w:tcW")
                tcPr.append(tcW)
            tcW.set(qn("w:w"), str(w))
            tcW.set(qn("w:type"), "dxa")
    # no table indent
    tblInd = tblPr.find(qn("w:tblInd"))
    if tblInd is None:
        tblInd = OxmlElement("w:tblInd")
        tblPr.append(tblInd)
    tblInd.set(qn("w:w"), "0")
    tblInd.set(qn("w:type"), "dxa")


def insert_paragraph_after(paragraph, text="", size=12, bold=False, italic=False):
    new_p = OxmlElement("w:p")
    paragraph._p.addnext(new_p)
    para = Paragraph(new_p, paragraph._parent)
    if text:
        run = para.add_run(text)
        set_run_font(run, size=size, bold=bold, italic=italic)
    para.paragraph_format.space_after = Pt(6)
    para.paragraph_format.line_spacing = 1.5
    return para


def add_hanging_ref(paragraph):
    fmt = paragraph.paragraph_format
    fmt.left_indent = Inches(0.5)
    fmt.first_line_indent = Inches(-0.5)
    fmt.space_after = Pt(6)
    fmt.line_spacing = 2.0
    paragraph.alignment = WD_ALIGN_PARAGRAPH.LEFT


def add_mixed_runs(paragraph, parts, size=12):
    """parts: list of (text, bold, italic)"""
    for run in list(paragraph.runs):
        run._r.getparent().remove(run._r)
    for text, bold, italic in parts:
        run = paragraph.add_run(text)
        set_run_font(run, size=size, bold=bold, italic=italic)


def write_doc(path, title, sections):
    d = Document()
    for section in d.sections:
        section.page_width = Cm(21.0)
        section.page_height = Cm(29.7)
        section.left_margin = Cm(2.54)
        section.right_margin = Cm(2.54)
    h = d.add_paragraph(title)
    h.alignment = WD_ALIGN_PARAGRAPH.CENTER
    for run in h.runs:
        set_run_font(run, size=16, bold=True)
    p = d.add_paragraph("Financial Risk Assessment and Micro-Lending System (FRAMS)")
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p = d.add_paragraph("23 October 2026 — Final review after consistency audit")
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    for heading, bullets in sections:
        ph = d.add_paragraph(heading)
        for run in ph.runs:
            set_run_font(run, size=13, bold=True)
        for b in bullets:
            bp = d.add_paragraph(b, style="List Bullet")
            for run in bp.runs:
                set_run_font(run, size=11)
    d.save(path)


def rebuild_questionnaire(doc, applied):
    old = doc.tables[21]
    n_rows = 2 + sum(1 + len(items) for _, items in STATEMENTS)
    new = doc.add_table(rows=n_rows, cols=7)
    try:
        new.style = "Table Grid"
    except Exception:
        pass
    new.alignment = WD_TABLE_ALIGNMENT.CENTER
    # usable width: 21.00 - 3.81 - 2.54 = 14.65 cm = 8291 twips
    col_cm = [1.05, 7.40, 1.24, 1.24, 1.24, 1.24, 1.24]
    col_twips = [int(c * 567) for c in col_cm]
    width_twips = sum(col_twips)

    headers = [
        "No.",
        "Evaluation Statement",
        "Strongly Agree",
        "Agree",
        "Neutral",
        "Disagree",
        "Strongly Disagree",
    ]
    scales = ["", "", "5", "4", "3", "2", "1"]
    for i, h in enumerate(headers):
        set_cell_text(new.rows[0].cells[i], h, size=8, bold=True, align="center")
        shade_cell(new.rows[0].cells[i], "1F4E79")
        if new.rows[0].cells[i].paragraphs[0].runs:
            new.rows[0].cells[i].paragraphs[0].runs[0].font.color.rgb = None
            rPr = new.rows[0].cells[i].paragraphs[0].runs[0]._element.get_or_add_rPr()
            color = OxmlElement("w:color")
            color.set(qn("w:val"), "FFFFFF")
            rPr.append(color)
    for i, h in enumerate(scales):
        set_cell_text(new.rows[1].cells[i], h, size=9, bold=True, align="center")
        shade_cell(new.rows[1].cells[i], "D6E3F0")

    r = 2
    item_no = 1
    for section, items in STATEMENTS:
        for c in range(7):
            set_cell_text(new.rows[r].cells[c], section if c == 0 else "", size=9, bold=True, align="left")
            shade_cell(new.rows[r].cells[c], "D9E2F3")
        new.rows[r].cells[0].merge(new.rows[r].cells[6])
        set_cell_text(new.rows[r].cells[0], section, size=9, bold=True, align="left")
        shade_cell(new.rows[r].cells[0], "D9E2F3")
        r += 1
        for statement in items:
            set_cell_text(new.rows[r].cells[0], str(item_no), size=9, bold=True, align="center")
            set_cell_text(new.rows[r].cells[1], statement, size=9, bold=False, align="left")
            for c in range(2, 7):
                set_cell_text(new.rows[r].cells[c], "☐", size=12, bold=False, align="center")
            item_no += 1
            r += 1

    set_table_fixed_width(new, width_twips, col_twips)
    old._tbl.addnext(new._tbl)
    old._tbl.getparent().remove(old._tbl)
    applied.append(
        f"Appendix I questionnaire rebuilt as a 7-column Likert table ({n_rows} rows) preserving all 20 original statements."
    )

    # caption immediately before the new table if missing
    prev = new._tbl.getprevious()
    need_caption = True
    if prev is not None and prev.tag == qn("w:p"):
        prev_text = "".join(prev.itertext())
        if "Table I1" in prev_text or "20-Item" in prev_text:
            need_caption = False
    if need_caption:
        p = OxmlElement("w:p")
        new._tbl.addprevious(p)
        para = Paragraph(p, doc)
        run = para.add_run("Table I1 FRAMS 20-Item User Acceptance Questionnaire")
        set_run_font(run, size=12, bold=True)
        para.alignment = WD_ALIGN_PARAGRAPH.CENTER
        applied.append("Added Table I1 caption immediately before the rebuilt Appendix I questionnaire.")


def insert_verified_references(doc, applied):
    refs_start = None
    for i, p in enumerate(doc.paragraphs):
        if p.text.strip() == "References" and i > 800:
            refs_start = i
            break
    if refs_start is None:
        applied.append("WARNING: References heading not found; no bibliography insert.")
        return

    # locate existing entries
    entries = {}
    end = None
    for i in range(refs_start, len(doc.paragraphs)):
        t = doc.paragraphs[i].text.strip()
        if t.startswith("APPENDIX"):
            end = i
            break
        if t.startswith("Bazarbash"):
            entries["Bazarbash"] = i
        elif t.startswith("Campos"):
            entries["Campos"] = i
        elif t.startswith("Khandani"):
            entries["Khandani"] = i
        elif t.startswith("Mina"):
            entries["Mina"] = i
        elif t.startswith("Ramirez"):
            entries["Ramirez"] = i
        elif t.startswith("PayMongo"):
            entries["PayMongo"] = i
    if end is None:
        end = len(doc.paragraphs)

    def make_ref_after(after_para, parts):
        para = insert_paragraph_after(after_para, "", size=12)
        add_mixed_runs(para, parts, size=12)
        add_hanging_ref(para)
        return para

    # BlueOrchard after Bazarbash
    if "Bazarbash" in entries and "BlueOrchard" not in " ".join(p.text for p in doc.paragraphs[refs_start:end]):
        make_ref_after(
            doc.paragraphs[entries["Bazarbash"]],
            [
                ("BlueOrchard. (2025, February 3). ", False, False),
                ("Microfinance outlook for 2025", False, True),
                (". https://www.blueorchard.com/microfinance-outlook-for-2025/", False, False),
            ],
        )
        applied.append("Added verified BlueOrchard (2025, February 3) reference from the publisher webpage.")

    # Re-find Mina after possible insert
    mina_para = None
    ramirez_para = None
    for p in doc.paragraphs:
        t = p.text.strip()
        if t.startswith("Mina, J."):
            mina_para = p
        if t.startswith("Ramirez, J."):
            ramirez_para = p
    body_all = "\n".join(p.text for p in doc.paragraphs)
    if mina_para is not None and "LendStack." not in body_all:
        make_ref_after(
            # insert before Mina by inserting after Khandani; if Khandani missing, after Mina's previous
            next(p for p in doc.paragraphs if p.text.strip().startswith("Khandani")),
            [
                ("LendStack. (n.d.). ", False, False),
                ("Building the future of lending infrastructure", False, True),
                (". https://www.lendstack.in/", False, False),
            ],
        )
        applied.append("Added verified LendStack (n.d.) reference from the vendor website; no dated 2025 publication was found.")

    if ramirez_para is not None and "Santos, A. R." not in "\n".join(p.text for p in doc.paragraphs):
        make_ref_after(
            ramirez_para,
            [
                ("Santos, A. R. (2023). The rise of digital payments: Transforming the financial landscape in the Philippines. ", False, False),
                ("International Journal of Applied Engineering and Technology, 5", False, True),
                ("(2), 22–27. https://romanpub.com/resources/ijaet%20v5-2-2023-04.pdf", False, False),
            ],
        )
        applied.append("Added verified Santos (2023) reference from the published PDF (ISSN 2633-4828, Vol. 5 No. 2, pp. 22–27).")

    # hanging indent on existing refs
    in_refs = False
    for p in doc.paragraphs:
        t = p.text.strip()
        if t == "References":
            in_refs = True
            continue
        if in_refs:
            if t.startswith("APPENDIX"):
                break
            if t and not t.startswith("The following references"):
                add_hanging_ref(p)


def main():
    if not SRC.exists():
        raise SystemExit(f"Missing source: {SRC}")
    DEST.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(SRC, DEST)
    # never overwrite the previous corrected manuscript
    assert SRC.exists()
    doc = Document(str(DEST))
    applied = [
        f"Copied {SRC.name} to {DEST.name} without modifying the previous corrected manuscript.",
    ]

    # --- in-text LendStack year correction ---
    for para in doc.paragraphs:
        t = para.text
        if "LendStack (2025)" in t or "LendStack, 2025" in t:
            new = t.replace("LendStack (2025)", "LendStack (n.d.)").replace("LendStack, 2025", "LendStack, n.d.")
            if new != t:
                replace_text(para, new)
                applied.append(f"Updated in-text LendStack year to n.d.: {t[:90]!r}")

    # --- PayMongo API documentation citation where API credentials are already named ---
    for para in doc.paragraphs:
        t = para.text
        if "PayMongo API credentials" in t and "(PayMongo, n.d.)" not in t and "PayMongo. (n.d.)" not in t:
            new = t.replace(
                "PayMongo API credentials",
                "PayMongo API credentials (PayMongo, n.d.)",
                1,
            )
            if new != t:
                replace_text(para, new)
                applied.append("Added in-text (PayMongo, n.d.) at the existing API-credentials sentence so the listed source is cited.")
                break

    insert_verified_references(doc, applied)

    # --- Appendix I ---
    rebuild_questionnaire(doc, applied)

    # --- Figure B32 caption vs actual screenshot ---
    for para in doc.paragraphs:
        t = para.text.strip()
        if t.startswith("Figure B32 (B32_pm07_borrower_history.png)"):
            replace_text(
                para,
                "Figure B32. Staff sign-in page after a signed-out request for the staff payments page. "
                "The attached screenshot shows the staff console sign-in form. A separate borrower payment-history screenshot is not embedded in this file.",
            )
            applied.append("Corrected Figure B32 caption to match the attached staff sign-in screenshot.")
        if t.startswith("Actual result: A signed-out request for the payments page opened the staff sign-in page."):
            if "not embedded" not in t:
                replace_text(
                    para,
                    t.rstrip()
                    + " The attached Figure B32 shows the staff sign-in page only; a borrower payment-history screenshot is not embedded in this file.",
                )
                applied.append("Added Figure B32 evidence limitation to the PM-07 appendix actual-result note.")

    # --- B35 / B37 missing embedded screenshots ---
    for para in doc.paragraphs:
        t = para.text.strip()
        if t == "Figure B35 (Payments Management list screenshot)":
            replace_text(
                para,
                "Figure B35 (Payments Management list screenshot). Note. No separate Payments Management list image is embedded beside this caption in the current file. "
                "The nearest attached PayMongo image is the PM-09 receipt (RJRR-20261018-000004). Do not treat that receipt as a payment-list screenshot.",
            )
            applied.append("Flagged Figure B35 as caption-only; no payment-list screenshot is embedded.")
        if t == "Figure B37 (Payments Management summary and payment list screenshot)":
            replace_text(
                para,
                "Figure B37 (Payments Management summary and payment list screenshot). Note. No separate Payments Management summary image is embedded beside this caption in the current file. "
                "This case is not treated as visual proof of payment deletion controls, polling, or a live PayMongo charge.",
            )
            applied.append("Flagged Figure B37 as caption-only; no summary screenshot is embedded.")

    # --- PM-07 table remarks: do not overclaim borrower-history screenshot ---
    pm07_actual = (
        "A signed-out request opened the staff sign-in page (Figure B32). "
        "The appendix test log also records that a signed-in borrower was redirected to Payment History, but that landing page is not shown in the remaining attached screenshot. "
        "The case does not demonstrate Payment Intent polling, staff Check with PayMongo, or a live PayMongo charge."
    )
    pm07_remarks = (
        "PASSED for the unauthenticated-access check supported by Figure B32 (staff sign-in). "
        "Borrower-history restriction is recorded in the test log but is not independently shown by the remaining embedded image. "
        "Not a polling, staff-reconciliation, or live-charge result."
    )
    for table in doc.tables:
        if len(table.rows) < 2:
            continue
        if table.rows[1].cells[0].text.strip() != "PM-01":
            continue
        for row in table.rows:
            if row.cells[0].text.strip() == "PM-07" and len(row.cells) >= 6:
                replace_text(row.cells[4].paragraphs[0], pm07_actual)
                for extra in row.cells[4].paragraphs[1:]:
                    extra.text = ""
                replace_text(row.cells[5].paragraphs[0], pm07_remarks)
                for extra in row.cells[5].paragraphs[1:]:
                    extra.text = ""
                applied.append("Updated PM-07 actual result and remarks so they do not treat the staff sign-in screenshot as borrower payment history.")

    # --- PM-10 / PM-12 remarks: missing screenshots, keep official pass marks unchanged ---
    for table in doc.tables:
        if len(table.rows) < 2:
            continue
        if table.rows[1].cells[0].text.strip() != "PM-01":
            continue
        for row in table.rows:
            tid = row.cells[0].text.strip()
            if tid == "PM-10" and len(row.cells) >= 6:
                remarks = row.cells[5].text.strip()
                note = (
                    "PASSED as a recorded-list check in the test log. "
                    "A Payments Management list screenshot is not embedded beside Figure B35 in this file. "
                    "This does not demonstrate Payment Intent polling or staff-initiated Check with PayMongo, and it is not a live PayMongo charge."
                )
                if "not embedded" not in remarks:
                    replace_text(row.cells[5].paragraphs[0], note)
                    for extra in row.cells[5].paragraphs[1:]:
                        extra.text = ""
                    applied.append("Updated PM-10 remarks: payment-list screenshot not embedded; pass mark unchanged.")
            if tid == "PM-12" and len(row.cells) >= 6:
                remarks = row.cells[5].text.strip()
                note = (
                    "PASSED as a summary-display check in the test log. "
                    "A Payments Management summary screenshot is not embedded beside Figure B37 in this file. "
                    "This does not test payment deletion restrictions, polling, staff Check with PayMongo, or a live charge."
                )
                if "not embedded" not in remarks:
                    replace_text(row.cells[5].paragraphs[0], note)
                    for extra in row.cells[5].paragraphs[1:]:
                        extra.text = ""
                    applied.append("Updated PM-12 remarks: summary screenshot not embedded; pass mark unchanged.")

    doc.save(str(DEST))

    # reopen verification
    check = Document(str(DEST))
    vlines = []
    vlines.append(f"paras={len(check.paragraphs)} tables={len(check.tables)}")
    # UAT invariants
    body = "\n".join(p.text for p in check.paragraphs)
    for needle in ["32", "30 borrowers", "20 questionnaire", "640", "4.53", "48"]:
        vlines.append(f"needle {needle}: {body.count(needle)}")
    # refs
    vlines.append("\n=== REFS ===")
    in_refs = False
    ref_count = 0
    for i, p in enumerate(check.paragraphs):
        t = p.text.strip()
        if t == "References" and i > 800:
            in_refs = True
        if in_refs:
            if t.startswith("APPENDIX"):
                break
            if t:
                vlines.append(f"[{i}] {t[:220]}")
                if t[0].isalpha() and "(" in t:
                    ref_count += 1
    vlines.append(f"ref_entries_guess={ref_count}")
    # T21
    t21 = check.tables[21]
    vlines.append(f"\nT21 {len(t21.rows)}x{len(t21.columns)}")
    stmts = []
    for ri, row in enumerate(t21.rows):
        cells = [c.text.replace("\n", " ").strip() for c in row.cells]
        vlines.append(f"R{ri}: {cells}")
        if cells and cells[0].isdigit():
            stmts.append(cells[1])
    vlines.append(f"statement_count={len(stmts)}")
    expected = [s for _, items in STATEMENTS for s in items]
    vlines.append(f"statements_match={stmts == expected}")
    # T13 means
    t13 = check.tables[13]
    vlines.append("\nT13")
    for row in t13.rows:
        vlines.append(" | ".join(c.text.strip() for c in row.cells))
    # T12 totals
    t12 = check.tables[12]
    vlines.append("\nT12 last row: " + " | ".join(c.text.strip().replace("\n", " ")[:80] for c in t12.rows[-1].cells))
    # LendStack
    vlines.append(f"\nLendStack (2025) leftover={body.count('LendStack (2025)')}")
    vlines.append(f"LendStack (n.d.)={body.count('LendStack (n.d.)')}")
    vlines.append(f"Santos, A. R. in refs={'Santos, A. R. (2023)' in body}")
    vlines.append(f"BlueOrchard. (2025={'BlueOrchard. (2025' in body}")
    vlines.append(f"B32 borrower_history leftover={body.count('B32_pm07_borrower_history.png')}")
    vlines.append(f"Figure B32 staff sign-in={'Staff sign-in page after a signed-out request' in body}")
    # PM-07
    for ti, table in enumerate(check.tables):
        if len(table.rows) > 1 and table.rows[1].cells[0].text.strip() == "PM-01":
            for row in table.rows:
                if row.cells[0].text.strip() == "PM-07":
                    vlines.append(f"T{ti} PM-07 remarks: {row.cells[5].text[:200]}")
    VERIFY_LOG.write_text("\n".join(vlines), encoding="utf-8")

    # reports
    missing_refs = [
        "Kou et al. (2022) — manuscript characterizes ML financial-risk assessment on digital lending platforms with real-time/adaptive algorithms. No unique matching 2022 Kou paper was verified; lookalike reviews were not substituted.",
        "Chen and Li (2023) — manuscript characterizes AI reassessment of risk during the life of a loan. No unique matching Chen & Li 2023 paper was verified.",
        "Chen et al. (2021) — manuscript characterizes an early-warning approach using transaction and behavior data. No unique matching Chen et al. 2021 paper was verified.",
        "Frost et al. (2024) — manuscript characterizes digital lending platforms using agile development and real-time risk analytics. Frost & Sullivan company material was not treated as Frost et al.",
        "Ghosh et al. (2023) — manuscript characterizes predictive analytics for microfinance delinquency. No unique matching Ghosh et al. 2023 paper was verified.",
        "Juszczak et al. (2022) — manuscript characterizes adaptive models updating risk from repayment behavior. No unique matching Juszczak et al. 2022 paper was verified.",
        "Lopez and Martinez (2024) — manuscript characterizes modular architecture and continuous assessment for reliability. No unique matching 2024 paper was verified.",
        "Rahman and Hasan (2025) — manuscript characterizes modular design and monitoring of changing borrower profiles. No unique matching 2025 paper was verified.",
        "Batista and Montero (2020) — manuscript characterizes a Brazilian digital micro-lending platform with automated scoring, groups, and repayment-behavior updates. Brazilian fintech news from 2020 exists, but no publication by these authors was verified.",
    ]
    write_doc(
        UNRESOLVED,
        "FRAMS Audit — Unresolved Issues (Updated After Final Review)",
        [
            ("Status of this review", [
                "The manuscript is improved but not fully finalized. Critical bibliographic gaps, missing screenshots, and institutional numbering decisions remain.",
                "Verified UAT and official test totals were not changed: 32 respondents (30 borrowers, 1 administrator, 1 collector); 20 items; 640 ratings; overall weighted mean 4.53; 48 official test cases.",
                f"Working file: {DEST.name}. Previous corrected file preserved: {SRC.name}.",
            ]),
            ("PRIORITY 1 — References still requiring researcher-supplied bibliographic records", missing_refs),
            ("Verified and added (do not invent further detail)", [
                "Santos, A. R. (2023). The rise of digital payments: Transforming the financial landscape in the Philippines. International Journal of Applied Engineering and Technology, 5(2), 22–27. Source: published PDF at romanpub.com (ISSN 2633-4828). The PDF ‘How to Cite’ line inconsistently says ‘Applied Engineering Research’; the running journal title on the PDF was used.",
                "BlueOrchard. (2025, February 3). Microfinance outlook for 2025. https://www.blueorchard.com/microfinance-outlook-for-2025/. Named webpage authors (Wehrle, Groeger, Fantini) were not substituted in-text because the manuscript cites the organization; authors may refine this to Wehrle et al. (2025) if the college requires named-author APA form.",
                "LendStack. (n.d.). Building the future of lending infrastructure. https://www.lendstack.in/. No dated 2025 publication was found; in-text citations were changed from (2025) to (n.d.).",
            ]),
            ("Unused listed sources that still need an in-text citation or removal", [
                "International Organization for Standardization (2011) / ISO/IEC 25010:2011 appears in the reference list. The five UAT criteria resemble that model, but Chapter III does not currently cite ISO. Authors must either add a true citation where the criteria are introduced or remove the unused entry. A citation was not invented.",
            ]),
            ("PRIORITY 2 — Appendix I", [
                "The 20-item Likert table was rebuilt in seven columns with SA(5)–SD(1) headers aligned to columns. Authors should open the DOCX in Print Layout and confirm that Table I1 stays inside the 1.5-inch left / 1-inch right margins used in this file.",
            ]),
            ("PRIORITY 3 — Numbering, TOC, wrapping (author/institution decisions required)", [
                "No Table of Contents, List of Tables, or List of Figures field exists in this DOCX (no TOC/instrText fields). Those lists cannot be regenerated from this file until the college template is applied in Microsoft Word.",
                "Figure numbering remains mixed: Figure 1–5 (design), Figure 5.1–5.8 (flowcharts), Figure 4.1–4.16 (implementation), and Figure B1–B49 / Figure K1 (appendices). Sequential APA numbering was not applied because in-text references and embedded drawings would desynchronize without an institutional rule.",
                "Appendix letters remain B, then I–L. Letters C–H are absent. They were not relabeled because the college may still require those omitted appendices.",
                "Decision needed from the adviser: (a) sequential APA figures/tables across the whole manuscript, or (b) keep chapter-based 4.x / 5.x plus appendix B/I/J/K/L numbering.",
            ]),
            ("PRIORITY 4 — Evidence still missing or not visually supported", [
                "Figure B32: the remaining embedded image is the staff sign-in page. It supports the signed-out redirect. It does not show borrower payment history. No screenshot was created to fill the gap.",
                "Figures B35 and B37: captions remain, but no Payments Management list/summary image is embedded beside those captions. PM-10 and PM-12 stay in the official 11-case/48-case totals as previously recorded, with remarks now stating that the screenshots are not embedded. Pass marks were not invented or removed.",
                "Payment Intent polling and staff-initiated Check with PayMongo remain implemented in code and described in the manuscript, but they are still not separately passed official cases. No dedicated screenshots were supplied, and none were fabricated.",
                "No provider-side live or test-mode PayMongo charge evidence was added. PM-09 shows a stored receipt (RJRR-20261018-000004, ₱100.00, remaining ₱3,050.00) and is not treated as a live charge.",
                "Figure B36 remains excluded local webhook-response evidence and is outside official totals.",
                "Pixel-level review of every unit/integration/system/penetration screenshot (B1–B25, B38–B49) was not completed in this pass. PayMongo B26–B34 and B36 were inspected.",
                "Pre-development 12-person documentation (consent/notes) is still not attached.",
            ]),
            ("Do not change without source-data correction", [
                "32 UAT respondents; 30 borrowers, 1 administrator, 1 collector; 20 items; 640 ratings; overall weighted mean 4.53; 48 official test cases.",
                "Webhook PHP and paymongo_qrph_webhook_events remain in the system and are outside official testing totals.",
            ]),
        ],
    )

    write_doc(
        CHANGES,
        "FRAMS Final Review — Summary of Changes",
        [
            ("Files", [
                f"Preserved: {SRC}",
                f"New manuscript: {DEST}",
                "Updated unresolved-issues report: FRAMS_AUDIT_UNRESOLVED_ISSUES.docx",
                "This change summary: FRAMS_FINAL_REVIEW_CHANGE_SUMMARY.docx",
            ]),
            ("Changes applied", applied if applied else ["No changes recorded."]),
            ("Verified screenshot comparisons (no images created or altered)", [
                "PM-01/PM-02 (shared Figure B26): Record Payment, HTTP 401, isolated test key rejected, QR page did not open, remaining ₱3,150.00, E-Wallet, FRAMS-DISP-01 — supports the safe-failure claim.",
                "PM-03 (Figure B28): Cash selected; Record Payment available; Create Dynamic QR Ph not shown — supports the cash-control claim.",
                "PM-04 (Figures B29a/b): “Payment amount must be greater than zero.” and “Enter a valid payment amount.” — supports zero and nonnumeric checks. Blank HTML5 required-field capture is still not a separate screenshot.",
                "PM-05 (Figure B30): “Payment cannot exceed the current total amount due of ₱3,150.00.” — supports the over-limit message. The typed 4000.00 value is not visible in the amount field after rejection.",
                "PM-06 (Figure B31): “You are not assigned to this loan.” and empty loan dropdown — supports the assignment restriction.",
                "PM-07 (Figure B32): staff sign-in form — supports signed-out redirect only; caption previously named borrower_history.png in error.",
                "PM-08 (Figure B33): exact unconfirmed-pending message on FRAMS-TEST-A with ₱3,833.34 — supports the blocked second attempt. Not a successful polling or live-charge screenshot.",
                "PM-09 (Figure B34): receipt RJRR-20261018-000004, E-Wallet, ₱100.00, ₱3,150.00 before, ₱3,050.00 remaining — supports receipt display of a stored record, not a live PayMongo charge.",
                "PM-10 / PM-12: no list/summary screenshot embedded. Webhook table (Figure B36) remains excluded.",
            ]),
            ("Values intentionally not changed", [
                "32 respondents; 30/1/1 roles; 20 items; 640 ratings; overall mean 4.53; 48 official tests (6+7+12+11+12).",
                "Official PayMongo scope remains PM-01–PM-10 and PM-12. Polling and Check with PayMongo remain implemented, not separately passed.",
            ]),
            ("Reopen verification", [
                f"See {VERIFY_LOG}.",
                "The manuscript is not claimed fully finalized while unmatched citations, missing screenshots, and TOC/numbering decisions remain.",
            ]),
        ],
    )

    copies = [
        WORKSPACE / DEST.name,
        WORKSPACE / UNRESOLVED.name,
        WORKSPACE / CHANGES.name,
        FRAMS_DIR / DEST.name,
        FRAMS_DIR / UNRESOLVED.name,
        FRAMS_DIR / CHANGES.name,
    ]
    FRAMS_DIR.mkdir(parents=True, exist_ok=True)
    shutil.copy2(DEST, WORKSPACE / DEST.name)
    shutil.copy2(UNRESOLVED, WORKSPACE / UNRESOLVED.name)
    shutil.copy2(CHANGES, WORKSPACE / CHANGES.name)
    shutil.copy2(DEST, FRAMS_DIR / DEST.name)
    shutil.copy2(UNRESOLVED, FRAMS_DIR / UNRESOLVED.name)
    shutil.copy2(CHANGES, FRAMS_DIR / CHANGES.name)
    # keep previous corrected copies untouched
    print("DEST", DEST, "paras", len(check.paragraphs), "tables", len(check.tables))
    print("statements_match", stmts == expected, "n_stmt", len(stmts), "cols", len(t21.columns))
    print("applied", len(applied))
    print("src_still_exists", SRC.exists())


if __name__ == "__main__":
    main()
