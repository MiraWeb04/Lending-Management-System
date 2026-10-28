# -*- coding: utf-8 -*-
from pathlib import Path
import shutil
from docx import Document
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Pt, Inches
from docx.text.paragraph import Paragraph
from docx.enum.text import WD_ALIGN_PARAGRAPH

SRC = Path(r"C:\Users\HomePC\Downloads\FRAMS_MANUSCRIPT_FINAL_REVIEWED.docx")
WORKSPACE = Path(r"C:\xampp\htdocs\lending_system\_frams_revision")
FRAMS_DIR = Path(r"C:\Users\HomePC\Downloads\FRAMS")
UNRESOLVED = Path(r"C:\Users\HomePC\Downloads\FRAMS_AUDIT_UNRESOLVED_ISSUES.docx")
CHANGES = Path(r"C:\Users\HomePC\Downloads\FRAMS_PRIORITY1_REFS_CHANGE_SUMMARY.docx")
PREV = Path(r"C:\Users\HomePC\Downloads\FRAMS_MANUSCRIPT_AUDIT_CORRECTED.docx")


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


def insert_paragraph_after(paragraph):
    new_p = OxmlElement("w:p")
    paragraph._p.addnext(new_p)
    return Paragraph(new_p, paragraph._parent)


def add_ref(after_prefix, parts):
    target = None
    for p in doc.paragraphs:
        if p.text.strip().startswith(after_prefix):
            target = p
    if target is None:
        raise SystemExit(f"anchor not found: {after_prefix}")
    para = insert_paragraph_after(target)
    for text, italic in parts:
        run = para.add_run(text)
        set_run_font(run, size=12, italic=italic)
    fmt = para.paragraph_format
    fmt.left_indent = Inches(0.5)
    fmt.first_line_indent = Inches(-0.5)
    fmt.space_after = Pt(6)
    fmt.line_spacing = 2.0
    para.alignment = WD_ALIGN_PARAGRAPH.LEFT
    return para.text[:80]


def find_para_containing(needle):
    for p in doc.paragraphs:
        if needle in p.text:
            return p
    return None


def write_doc(path, title, sections):
    d = Document()
    h = d.add_paragraph(title)
    h.alignment = WD_ALIGN_PARAGRAPH.CENTER
    for run in h.runs:
        set_run_font(run, size=16, bold=True)
    p = d.add_paragraph("Financial Risk Assessment and Micro-Lending System (FRAMS)")
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p = d.add_paragraph("23 October 2026 — Priority 1 references supplied by the researchers")
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


P128 = (
    "Shi et al. (2022) reviewed machine-learning, statistical, and deep-learning methods for credit-risk estimation "
    "and reported that ensemble and deep-learning models often outperform traditional statistical approaches on public datasets. "
    "Kou (2022), in an editor’s introduction to Financial Innovation, surveyed current fintech and credit-assessment research, "
    "including work comparing fintech lenders with traditional banks. This literature provides context for automated risk assessment, "
    "but its methods should not be conflated with the approach used in FRAMS. FRAMS does not implement a machine-learning score; "
    "it applies configured eligibility rules and presents the result for review by the authorized administrator."
)

P132 = (
    "Guan et al. (2023) examined responsible credit-risk assessment that combines machine learning with human expert rules "
    "so that lending decisions remain explainable. Chen, Ye, and Ye (2023) proposed an interpretable selective-learning model "
    "that uses logistic regression for most cases and a neural network only where a linear model is insufficient. "
    "Huang et al. (2023) compared BigTech big-data and machine-learning default prediction for small and medium-sized enterprises "
    "with traditional bank scorecards. Cornelli et al. (2024) studied fintech small-business lending platforms in the United States "
    "and reported that those platforms’ internal credit scores predicted loan performance more accurately than traditional credit scores, "
    "particularly in high-unemployment areas. These sources describe predictive or data-driven scoring. FRAMS has a narrower scope: "
    "it records installment status, payment history, overdue penalties, and reports, but it does not use a predictive model to identify "
    "likely default or automatically revise repayment terms."
)

P142 = (
    "Khandani et al. (2010) examined machine-learning models for consumer credit-risk assessment. "
    "The more recent studies reviewed above (Shi et al., 2022; Guan et al., 2023; Chen et al., 2023; Huang et al., 2023; "
    "Cornelli et al., 2024) likewise treat credit risk as a prediction problem using historical lending outcomes, alternative data, "
    "or internal fintech scores. These studies demonstrate approaches that differ from a fixed, rule-based assessment. "
    "They inform the broader research context, but their reported outcomes should not be presented as outcomes achieved by FRAMS."
)

P146 = (
    "The foreign literature and studies go further. They describe machine learning, alternative data, and predictive scoring "
    "(Kou, 2022; Shi et al., 2022; Bazarbash & Beaton, 2020; Guan et al., 2023; Chen et al., 2023; Huang et al., 2023; "
    "Cornelli et al., 2024; Khandani et al., 2010). Those capabilities are a research direction. They are not features of the system built in this study."
)


doc = Document(str(SRC))
applied = []

replacements = [
    ("Kou et al. (2022) examined machine-learning approaches", P128),
    ("Ghosh et al. (2023) discussed predictive analytics", P132),
    ("Khandani et al. (2010) examined machine-learning models for consumer credit-risk assessment. Batista", P142),
    ("The foreign literature and studies go further. They describe machine learning, alternative data, and continuous reassessment", P146),
]

for needle, new in replacements:
    p = find_para_containing(needle)
    if p is None:
        raise SystemExit(f"paragraph not found: {needle[:60]}")
    replace_text(p, new)
    applied.append(f"Rewrote Chapter II paragraph starting: {needle[:70]}")

# Table 1 predictive / delinquency rows
t0 = doc.tables[0]
for row in t0.rows:
    area = row.cells[0].text.strip()
    if area == "Predictive scoring":
        replace_text(row.cells[1].paragraphs[0], "Shi et al. (2022); Chen, Ye, and Ye (2023); Khandani et al. (2010)")
        for extra in row.cells[1].paragraphs[1:]:
            extra.text = ""
        replace_text(row.cells[2].paragraphs[0], "Machine-learning credit scoring and interpretable selective models")
        for extra in row.cells[2].paragraphs[1:]:
            extra.text = ""
        applied.append("Updated Table 1 Predictive scoring sources to verified Shi/Chen/Khandani entries.")
    if area == "Delinquency monitoring":
        replace_text(row.cells[1].paragraphs[0], "Guan et al. (2023); Huang et al. (2023); Cornelli et al. (2024)")
        for extra in row.cells[1].paragraphs[1:]:
            extra.text = ""
        replace_text(row.cells[2].paragraphs[0], "Explainable ML, BigTech/fintech scoring, and credit-access studies")
        for extra in row.cells[2].paragraphs[1:]:
            extra.text = ""
        applied.append("Updated Table 1 Delinquency monitoring sources to verified Guan/Huang/Cornelli entries.")

# Bibliographic inserts (after last matching prefix currently in the list)
# Chen after Campos
add_ref(
    "Campos, R. B.",
    [
        ("Chen, D., Ye, J., & Ye, W. (2023). Interpretable selective learning in credit risk. ", False),
        ("Research in International Business and Finance, 65", True),
        (", Article 101940. https://doi.org/10.1016/j.ribaf.2023.101940", False),
    ],
)
applied.append("Added Chen, Ye, and Ye (2023).")

add_ref(
    "Chen, D., Ye, J.",
    [
        ("Cornelli, G., Frost, J., Gambacorta, L., & Jagtiani, J. (2024). The impact of fintech lending on credit access for U.S. small businesses. ", False),
        ("Journal of Financial Stability, 73", True),
        (", Article 101290. https://doi.org/10.1016/j.jfs.2024.101290", False),
    ],
)
applied.append("Added Cornelli, Frost, Gambacorta, and Jagtiani (2024). In-text is Cornelli et al., not Frost et al.")

add_ref(
    "Estrellado, G. E.",
    [
        ("Guan, C., Suryanto, H., Mahidadia, A., Bain, M., & Compton, P. (2023). Responsible credit risk assessment with machine learning and knowledge acquisition. ", False),
        ("Human-Centric Intelligent Systems, 3", True),
        ("(3), 232–243. https://doi.org/10.1007/s44230-023-00035-1", False),
    ],
)
applied.append("Added Guan et al. (2023).")

add_ref(
    "Guan, C.",
    [
        ("Huang, Y., Li, Z., Qiu, H., Sun, T., Wang, X., & Zhang, L. (2023). BigTech credit risk assessment for SMEs. ", False),
        ("China Economic Review, 81", True),
        (", Article 102016. https://doi.org/10.1016/j.chieco.2023.102016", False),
    ],
)
applied.append("Added Huang et al. (2023). Publisher record lists Sun, T. (Tao Sun), not Tao, S.")

add_ref(
    "Khandani, A. E.",
    [
        ("Kou, G. (2022). Editor’s introduction. ", False),
        ("Financial Innovation, 8", True),
        (", Article 71. https://doi.org/10.1186/s40854-022-00374-1", False),
    ],
)
applied.append("Added Kou (2022). Published article number is 71, not 83; single author, so in-text is Kou (2022), not Kou et al.")

add_ref(
    "Santos, A. R.",
    [
        ("Shi, S., Tse, R., Luo, W., D’Addona, S., & Pau, G. (2022). Machine learning-driven credit risk: A systemic review. ", False),
        ("Neural Computing and Applications, 34", True),
        ("(17), 14327–14339. https://doi.org/10.1007/s00521-022-07472-2", False),
    ],
)
applied.append("Added Shi et al. (2022) with all five authors and issue 17, as required by APA 7.")

doc.save(str(SRC))

check = Document(str(SRC))
body = "\n".join(p.text for p in check.paragraphs)
leftovers = []
for needle in [
    "Kou et al. (2022)",
    "Chen and Li (2023)",
    "Chen & Li (2023)",
    "Frost et al. (2024)",
    "Ghosh et al. (2023)",
    "Rahman and Hasan (2025)",
    "Rahman & Hasan (2025)",
    "Batista and Montero (2020)",
    "Batista & Montero (2020)",
    "Chen et al. (2021)",
    "Juszczak et al. (2022)",
    "Lopez and Martinez (2024)",
    "Lopez & Martinez (2024)",
]:
    if needle in body:
        leftovers.append(needle)

print("leftovers", leftovers)
print("has Shi et al. (2022)", "Shi et al. (2022)" in body)
print("has Kou (2022)", "Kou (2022)" in body)
print("has Cornelli et al. (2024)", "Cornelli et al. (2024)" in body)
print("has Chen, Ye, and Ye (2023)", "Chen, Ye, and Ye (2023)" in body)
print("32/4.53 still", body.count("30 borrowers, one administrator"), body.count("4.53"))
print("PREV exists", PREV.exists())
print("tables", len(check.tables), "paras", len(check.paragraphs))

# refs dump
print("\nREFS:")
in_refs = False
for p in check.paragraphs:
    t = p.text.strip()
    if t == "References":
        in_refs = True
        continue
    if in_refs:
        if t.startswith("APPENDIX"):
            break
        if t:
            print(" ", t[:110])

write_doc(
    CHANGES,
    "FRAMS Priority 1 — Reference Update",
    [
        ("Source of this pass", [
            "Researchers supplied six bibliographic records for machine-learning credit risk and digital lending.",
            f"Working file updated in place: {SRC.name}. Previous audit copy preserved: {PREV.name}.",
        ]),
        ("Verified entries added", applied),
        ("Bibliographic corrections against publisher records", [
            "Kou (2022) is a single-author editor’s introduction. In-text is Kou (2022), not Kou et al. (2022). The Financial Innovation article number for DOI 10.1186/s40854-022-00374-1 is 71, not 83.",
            "Shi et al. (2022) has five authors (Shi, Tse, Luo, D’Addona, Pau). APA 7 requires all of them in the reference list. Volume/issue/pages: 34(17), 14327–14339. DOI 10.1007/s00521-022-07472-2. The published title spelling “systemic review” was kept.",
            "Huang et al. (2023): the publisher list is Huang, Li, Qiu, Sun, Wang, and Zhang. The fourth author is Tao Sun (Sun, T.), not Tao, S.",
            "Chen, Ye, and Ye (2023) replaced the unmatched Chen and Li (2023). First in-text citation lists all three names.",
            "Cornelli, Frost, Gambacorta, and Jagtiani (2024) replaced the unmatched Frost et al. (2024). APA lead author is Cornelli.",
        ]),
        ("Unmatched names removed from Chapter II and Table 1", [
            "Ghosh et al. (2023), Rahman and Hasan (2025), Batista and Montero (2020), Chen et al. (2021), Juszczak et al. (2022), and Lopez and Martinez (2024) were removed because no verified record was supplied in this batch. They were not replaced with lookalike papers under the old names.",
        ]),
        ("Still not fully finalized", [
            "ISO/IEC 25010 remains in the reference list without an in-text citation.",
            "Screenshot gaps (B32 borrower history, B35 list, B37 summary, polling, Check with PayMongo) and TOC/numbering decisions remain from the previous review.",
            "Verified UAT/test totals were not changed: 32; 30/1/1; 20 items; 640 ratings; 4.53; 48 official cases.",
        ]),
    ],
)

write_doc(
    UNRESOLVED,
    "FRAMS Audit — Unresolved Issues (After Priority 1 Reference Batch)",
    [
        ("Status", [
            "Chapter II machine-learning and digital-lending citations now match six verified publications. The manuscript is still not fully finalized.",
            f"Working file: {SRC.name}. Audit copy preserved: {PREV.name}.",
        ]),
        ("Bibliographic leftovers from this batch", leftovers if leftovers else [
            "No leftover unmatched in-text names from the previous missing-citation list were found after this pass.",
        ]),
        ("Still requires an author decision", [
            "ISO/IEC 25010:2011 is listed but not cited in the text. Cite it where the five UAT criteria are introduced, or remove the unused entry.",
        ]),
        ("Still requires screenshots, not wording", [
            "Figure B32 borrower payment-history image.",
            "Figure B35 Payments Management list (Payment ID 46).",
            "Figure B37 Payments Management summary (October 2026 / ₱10,516.66).",
            "Dedicated Payment Intent polling and staff Check with PayMongo captures, if those cases are to be marked passed.",
        ]),
        ("Still requires an institutional decision", [
            "Generate Table of Contents, List of Tables, and List of Figures in the college Word template. This DOCX has no TOC fields.",
            "Choose sequential APA figure numbering or keep the current mixed 1–5 / 5.1–5.8 / 4.x / Bx scheme.",
            "Confirm whether appendices C–H are omitted or still required.",
        ]),
        ("Do not change", [
            "32 UAT respondents; 30 borrowers, 1 administrator, 1 collector; 20 items; 640 ratings; overall mean 4.53; 48 official tests.",
            "Webhook tests remain outside official totals. Polling and Check with PayMongo remain implemented, not separately passed.",
        ]),
    ],
)

for dest in (WORKSPACE / SRC.name, FRAMS_DIR / SRC.name):
    dest.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(SRC, dest)
shutil.copy2(UNRESOLVED, WORKSPACE / UNRESOLVED.name)
shutil.copy2(CHANGES, WORKSPACE / CHANGES.name)
shutil.copy2(UNRESOLVED, FRAMS_DIR / UNRESOLVED.name)
shutil.copy2(CHANGES, FRAMS_DIR / CHANGES.name)
print("saved", SRC)
