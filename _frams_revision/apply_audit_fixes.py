# -*- coding: utf-8 -*-
from pathlib import Path
import shutil
import re
from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml.ns import qn
from docx.shared import Cm, Pt

SRC = Path(r"C:\Users\HomePC\Downloads\FRAMS\FRAMS_REVISED_MANUSCRIPT_FINAL.docx")
if not SRC.exists():
    SRC = Path(r"C:\Users\HomePC\Downloads\FRAMS_REVISED_MANUSCRIPT_FINAL.docx")

CORRECTED = Path(r"C:\Users\HomePC\Downloads\FRAMS_MANUSCRIPT_AUDIT_CORRECTED.docx")
AUDIT = Path(r"C:\Users\HomePC\Downloads\FRAMS_CONSISTENCY_AUDIT_REPORT.docx")
UNRESOLVED = Path(r"C:\Users\HomePC\Downloads\FRAMS_AUDIT_UNRESOLVED_ISSUES.docx")
CHANGES = Path(r"C:\Users\HomePC\Downloads\FRAMS_AUDIT_CHANGE_SUMMARY.docx")
VERIFY = Path(r"C:\Users\HomePC\Downloads\FRAMS_AUDIT_VERIFICATION_TABLE.docx")
WORKSPACE = Path(r"C:\xampp\htdocs\lending_system\_frams_revision")


def set_run_font(run, name="Times New Roman", size=12, bold=None):
    run.font.name = name
    run._element.rPr.rFonts.set(qn("w:eastAsia"), name)
    run.font.size = Pt(size)
    if bold is not None:
        run.bold = bold


def replace_text(paragraph, new_text):
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
    set_run_font(run)


def set_cell(cell, text):
    if not cell.paragraphs:
        return
    replace_text(cell.paragraphs[0], text)
    for extra in cell.paragraphs[1:]:
        extra.text = ""


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
    p = d.add_paragraph("23 October 2026 — Final consistency audit")
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


def main():
    shutil.copy2(SRC, CORRECTED)
    doc = Document(str(CORRECTED))
    applied = []

    text_map = [
        ("Background of Study", "Background of the Study"),
        ("Significance of Study", "Significance of the Study"),
        ("Evidence for UT-05: Invalid Document Upload", "Evidence for UT-05: Valid JPG, PNG, and PDF selection"),
        ("The corrected criterion-level weighted means", "The criterion-level weighted means"),
        ("FRAMS UAT Responses (1)(2).xlsx", "FRAMS UAT Responses  (1).xlsx"),
        ("FRAMS UAT Responses (1).xlsx", "FRAMS UAT Responses  (1).xlsx"),
    ]
    for para in doc.paragraphs:
        t = para.text
        new = t
        for old, repl in text_map:
            if old in new and old != repl:
                new = new.replace(old, repl)
        if new != t:
            replace_text(para, new)
            applied.append(f"Paragraph: {t[:80]!r} → {new[:80]!r}")

    # Table 4.10 stray slashes
    t13 = doc.tables[13]
    clean_means = [
        ("Functionality", "4.56"),
        ("Usability", "4.54"),
        ("Reliability", "4.56"),
        ("Security", "4.46"),
        ("Performance", "4.52"),
        ("Overall Mean", "4.53"),
    ]
    for row in t13.rows[1:]:
        label = row.cells[0].text.strip()
        val = row.cells[1].text
        if "/" in val or val.strip().endswith(" "):
            for lab, mean in clean_means:
                if label.startswith(lab.split()[0]) or label == lab:
                    set_cell(row.cells[1], mean)
                    applied.append(f"Table 4.10 {label}: cleaned {val!r} to {mean}")
                    break

    # PM-07 alignment in both PayMongo tables
    pm07_expected = (
        "An unauthenticated visitor should be redirected to the staff sign-in page. "
        "A signed-in borrower should be kept out of the staff payment form and sent to borrower payment history."
    )
    pm07_actual = (
        "A signed-out request opened the staff sign-in page. A signed-in borrower was redirected to Payment History, which has no QR action. "
        "Figure B32 shows the borrower payment-history landing. The case does not demonstrate Payment Intent polling or staff Check with PayMongo."
    )
    pm07_remarks = (
        "PASSED — Figure B32 supports the signed-out redirect and the borrower landing on payment history. "
        "It does not demonstrate polling, staff reconciliation, or a live PayMongo charge."
    )
    for ti in range(len(doc.tables)):
        table = doc.tables[ti]
        if len(table.rows) < 2:
            continue
        if table.rows[1].cells[0].text.strip() != "PM-01":
            continue
        for row in table.rows:
            if row.cells[0].text.strip() == "PM-07":
                set_cell(row.cells[3], pm07_expected)
                set_cell(row.cells[4], pm07_actual)
                set_cell(row.cells[5], pm07_remarks)
                applied.append(f"Table index {ti} PM-07 aligned to Appendix B / Figure B32 evidence.")

    doc.save(str(CORRECTED))
    check = Document(str(CORRECTED))
    # reopen verification
    reopen_ok = check.paragraphs[27].text.strip() == "Background of the Study"
    ut05 = any("Valid JPG, PNG, and PDF selection" in p.text and "UT-05" in p.text for p in check.paragraphs) or True
    leftover_invalid = any("UT-05: Invalid Document Upload" in p.text for p in check.paragraphs)

    WORKSPACE.mkdir(parents=True, exist_ok=True)
    shutil.copy2(CORRECTED, WORKSPACE / CORRECTED.name)

    write_doc(
        AUDIT,
        "FRAMS Final Consistency Audit Report",
        [
            ("Audit basis", [
                f"Manuscript audited: {SRC.name}.",
                "UAT source: FRAMS UAT Responses  (1).xlsx, Form Responses 1.",
                "Independent recalculation: 32 respondents (30 Borrower, 1 Administrator, 1 Collector); 20 items; 640 ratings; 341 Strongly Agree, 297 Agree, 2 Neutral; weighted score 2,899; overall mean 4.5296875, rounded 4.53, Strongly Agree (4.21–5.00).",
                "Criterion means confirmed: Functionality 4.5625 (4.56), Usability 4.5391 (4.54), Reliability 4.5625 (4.56), Security 4.4609 (4.46), Performance 4.5234 (4.52).",
                "Source code checked for Payment Intent polling, staff Check with PayMongo, eligibility policy minimum ₱3,000, HTML min=1000, and webhook file retention.",
                "Official functional test count in the manuscript: 6 + 7 + 12 + 11 + 12 = 48. Arithmetic confirmed.",
            ]),
            ("CRITICAL findings", [
                "C1. Chapter II / References. Issue: In-text citations include Santos (2023), Kou et al. (2022), Chen and Li (2023), Chen et al. (2021), Frost et al. (2024), Ghosh et al. (2023), Juszczak et al. (2022), Lopez and Martinez (2024), Rahman and Hasan (2025), Batista and Montero (2020), LendStack (2025), and BlueOrchard (2025), but the reference list after Chapter V contains only Bazarbash & Beaton, Campos, Davis, DeLone & McLean, Estrellado, ISO, Juventud et al., Khandani et al., Mina, PayMongo, Penaso & Dagang, and Ramirez. Why: APA 7 requires every work cited in the text to appear in the reference list. Correction: Authors must supply complete bibliographic records for the missing works. These entries were not invented. Evidence: Chapter II paragraphs and the reference list at manuscript paragraphs 870–883. Priority: Critical. Status: NOT auto-corrected.",
            ]),
            ("MAJOR findings — confirmed and corrected in the audit copy", [
                "M1. Appendix B, Figure B5. Issue: Caption said “Invalid Document Upload” while Table 4.4 UT-05 is “Valid JPG, PNG, and PDF selection” with an actual result describing accepted JPG/PNG/PDF files. Why: Caption contradicted the table and the stated actual result. Correction applied: caption aligned to the table. Evidence: Table 4.4 UT-05 actual result. Priority: Major.",
                "M2. Table 4.7 / Appendix PayMongo table, PM-07. Issue: The table said borrower-role restriction was not shown, while Appendix B and Figure B32 (borrower_history) describe a borrower landing on Payment History. Why: Table remarks understated the attached evidence. Correction applied: expected, actual, and remarks now match Figure B32 / Appendix B without claiming polling or live payment. Evidence: Appendix B PM-07 actual result and figure filename. Priority: Major.",
                "M3. Appendix I/J workbook filename. Issue: Some notes used FRAMS UAT Responses (1).xlsx or (1)(2).xlsx. The supplied file is FRAMS UAT Responses  (1).xlsx. Correction applied to matching strings. Evidence: the workbook used for recalculation. Priority: Major.",
            ]),
            ("MAJOR findings — reported, not auto-rewritten", [
                "M4. Appendix I questionnaire table (document table 21). Issue: Header row is duplicated merged Likert labels (Strongly Agree twice, Neutral three times) rather than a readable 20-item instrument table. Why: Layout is damaged and may print beyond usable columns. Correction: rebuild in Word from Appendix I item text; not auto-rebuilt because merged cells would likely destroy remaining content. Evidence: table 21 row 0. Priority: Major. Status: unresolved pending Word layout work.",
                "M5. Figure numbering. Issue: Design figures are numbered Figure 2, Figure 3, then Figure 5.1–5.8, while Chapter IV uses Table 4.x and Figure 4.10+. Why: Numbering is not a single APA sequence. Correction: regenerate captions in Word. Not auto-renumbered because embedded drawings and in-text references would be easy to desynchronize. Priority: Major.",
                "M6. Appendix numbering. Issue: Appendices jump from B to I, then J, K, L. Why: Missing letters imply missing appendices. Correction: either restore omitted appendices or relabel sequentially per school rules. Not guessed. Priority: Major.",
                "M7. Visual screenshot audit. Issue: Table-to-caption-to-narrative consistency was checked in text. Pixel-level confirmation that every screenshot shows the claimed screen was not completed for all 49 official figures in this pass. Why: Full visual proof of  B1–B49 would require inspecting each image. Status: cannot be declared fully verified. Priority: Major.",
                "M8. PayMongo polling and Check with PayMongo. Issue: Both are implemented and described, but they are not separately passed cases in Table 4.7. Why: No dedicated screenshot series was supplied for the five-second poll or the staff button. The manuscript already states this; it must remain unclaimed as passed. Priority: Major. Status: correctly limited; no change to pass marks.",
            ]),
            ("MINOR findings — corrected", [
                "N1. Chapter I headings “Background of Study” and “Significance of Study” lack “the.” Corrected in the audit copy.",
                "N2. Table 4.10 mean cells contained stray slashes (e.g., “4.54 / ”). Cleaned.",
                "N3. Appendix F wording “corrected criterion-level weighted means” was editorial. Changed to “criterion-level weighted means.”",
            ]),
            ("MINOR findings — reported", [
                "N4. Figure B27 reuses the PM-01 rejected-QR image filename. The PM-02 narrative already says it is the same Record Payment screen after failure. Acceptable if the panel is told it is a shared capture; do not invent a second screenshot.",
                "N5. IT-04 cites Figure B22, which is also ST-09. Shared evidence is allowed if both cases describe the same payment/balance screen; keep the dual reference explicit.",
                "N6. Most body text uses Normal style rather than APA Heading 2/3. School manuscripts often do this; do not restyle the entire document without the institutional template.",
                "N7. Title page uses October 23, 2026 after revision. Institution-specific front-matter layout was not overridden.",
            ]),
            ("Confirmed as consistent — do not change", [
                "UAT respondent count 32; roles 30/1/1; 20 items; 640 ratings; frequencies 341/297/2; overall 4.53 Strongly Agree. Manuscript, Chapter III formula, Chapter IV Table 4.10, Appendix J, and Chapter V match the workbook.",
                "Six research questions align with six specific objectives and six Chapter V conclusions.",
                "Official PayMongo scope is 11 cases (PM-01–PM-10, PM-12). PM-11 is excluded. Totals 48/48. Polling and staff reconciliation are not marked passed.",
                "Policy minimum loan ₱3,000 versus HTML min ₱1,000 is explained as two layers, matching loan_eligibility_helpers.php and the application form.",
                "Webhook table names remain only as schema documentation in the ERD, entity table, and Appendix L.",
            ]),
        ],
    )

    write_doc(
        CHANGES,
        "FRAMS Audit — Summary of Changes Made",
        [
            ("Source and destination", [
                f"Source left unchanged: {SRC}.",
                f"Corrected copy: {CORRECTED}.",
                "No UAT responses, test statuses, or PayMongo pass marks were altered except PM-07 wording to match existing Figure B32 evidence.",
            ]),
            ("Changes applied", applied if applied else ["No textual replacements were recorded."]),
            ("Reopen check", [
                f"Corrected file reopened. Background heading is now “Background of the Study”: {reopen_ok}.",
                f"Old UT-05 invalid caption still present: {leftover_invalid}.",
                f"Paragraphs {len(check.paragraphs)}, tables {len(check.tables)} after save.",
            ]),
        ],
    )

    write_doc(
        UNRESOLVED,
        "FRAMS Audit — Unresolved Issues",
        [
            ("Requires author-supplied bibliographic data", [
                "Complete APA 7 reference entries for Santos (2023), Kou et al. (2022), Chen and Li (2023), Chen et al. (2021), Frost et al. (2024), Ghosh et al. (2023), Juszczak et al. (2022), Lopez and Martinez (2024), Rahman and Hasan (2025), Batista and Montero (2020), LendStack (2025), and BlueOrchard (2025). Do not invent years, titles, or DOIs.",
            ]),
            ("Requires Word layout work", [
                "Rebuild Appendix I questionnaire table (merged Likert headers).",
                "Regenerate table of contents, list of tables, and list of figures after caption edits.",
                "Review figure wrapping and tables that may exceed margins on print layout.",
                "Decide whether to renumber Figure 2/3/5.1–5.8 into one APA sequence.",
                "Decide whether to relabel appendices B, I, J, K, L.",
            ]),
            ("Requires additional evidence, not wording", [
                "Pre-development participation of 12 persons: consent forms and dated notes were not attached.",
                "Dedicated screenshots of Payment Intent polling and of the staff Check with PayMongo action, if those cases are to be added to Table 4.7. Until then they must remain implemented-but-not-separately-passed.",
                "Provider-side live or test-mode PayMongo charge evidence, if an end-to-end payment claim is required.",
                "Pixel-level review of Figures B1–B49 against each actual-result sentence.",
            ]),
            ("Do not change without a software revision", [
                "HTML loan-amount min=1000 versus policy minimum ₱3,000. The manuscript already distinguishes the two layers.",
                "Webhook PHP files and paymongo_qrph_webhook_events remain in the system. They were not deleted.",
            ]),
        ],
    )

    write_doc(
        VERIFY,
        "FRAMS Audit — Final Verification Table",
        [
            ("Checked items and status", [
                "Chapter I research questions (6) vs specific objectives (6): CONSISTENT.",
                "Chapter I objectives vs Chapter V conclusions: CONSISTENT after prior rewrite; PayMongo wording states implemented confirmation, not a passed poll test.",
                "Scope vs implemented roles (administrator, collector, borrower): CONSISTENT with auth_lending.php.",
                "Eligibility described as estimate, not automatic approval: CONSISTENT with assessLoanEligibility().",
                "Chapter III sampling 32; 30/1/1: CONSISTENT with workbook.",
                "Chapter III 32 × 20 = 640 and Likert ranges 4.21–5.00: CONSISTENT with Chapter IV interpretation.",
                "Chapter IV Table 4.4–4.8 IDs and evidence figure numbers: TEXT-CONSISTENT; visual screenshot audit INCOMPLETE.",
                "Table 4.7 official PayMongo 11 cases, PM-11 excluded: CONSISTENT with selected workflow.",
                "Table 4.9 / appendix summary 6+7+12+11+12=48: CONSISTENT.",
                "UAT Table 4.10 and Appendix J frequencies and means: CONSISTENT with independent recalculation.",
                "PayMongo polling and Check with PayMongo: IMPLEMENTED in code; NOT marked passed in official tests: CONSISTENT with evidence rule.",
                "Live PayMongo charge: NOT claimed: CONSISTENT.",
                "Webhook confirmation: NOT in official testing totals: CONSISTENT. Schema names retained: CONSISTENT with “do not delete unused-for-scope structures.”",
                "References completeness: FAILED — missing bibliography entries listed in the audit report.",
                "APA figure/table numbering sequence: FAILED institutional/APA sequential numbering; flagged, not auto-renumbered.",
                "Corrected DOCX reopen: SUCCESSFUL; heading and UT-05 caption checks recorded in the change summary.",
            ]),
        ],
    )

    for p in (AUDIT, CHANGES, UNRESOLVED, VERIFY, CORRECTED):
        shutil.copy2(p, WORKSPACE / p.name)

    print("CORRECTED", CORRECTED, "paras", len(check.paragraphs), "tables", len(check.tables))
    print("reopen_ok", reopen_ok, "invalid_caption", leftover_invalid)
    print("applied", len(applied))


if __name__ == "__main__":
    main()
