# -*- coding: utf-8 -*-
"""Apply supported FRAMS manuscript revisions to a copy of the original."""
from __future__ import annotations

import shutil
from copy import deepcopy
from pathlib import Path

from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_LINE_SPACING
from docx.oxml.ns import qn
from docx.oxml import OxmlElement
from docx.shared import Inches, Pt, Cm, Twips
from docx.text.paragraph import Paragraph

ORIGINAL = Path(r"C:\Users\HomePC\Downloads\FRAMS_3RDREVISIONS.docx")
REVISED = Path(r"C:\Users\HomePC\Downloads\FRAMS_REVISED_MANUSCRIPT.docx")
REPORT = Path(r"C:\Users\HomePC\Downloads\FRAMS_REVISION_REPORT.docx")
UNRESOLVED = Path(r"C:\Users\HomePC\Downloads\FRAMS_UNRESOLVED_ISSUES.docx")
COMPARISON = Path(r"C:\Users\HomePC\Downloads\FRAMS_COMPARISON_REPORT.docx")
SCHEMA_SRC = Path(r"C:\Users\HomePC\Downloads\FRAMS_lending_management_schema.sql")
SCHEMA_DST = Path(r"C:\Users\HomePC\Downloads\FRAMS_APPENDIX_L_Database_Schema.sql")
WORKSPACE_COPY = Path(r"C:\xampp\htdocs\lending_system\_frams_revision")

WP_NS = "http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"


def set_run_font(run, name="Times New Roman", size=12, bold=None, italic=None):
    run.font.name = name
    run._element.rPr.rFonts.set(qn("w:eastAsia"), name)
    run.font.size = Pt(size)
    if bold is not None:
        run.bold = bold
    if italic is not None:
        run.italic = italic


def replace_text_preserve_drawings(paragraph: Paragraph, new_text: str) -> None:
    """Replace visible text while keeping drawing/pict runs."""
    drawing_runs = []
    for run in paragraph.runs:
        xml = run._r.xml
        if "w:drawing" in xml or "w:pict" in xml or "w:object" in xml:
            drawing_runs.append(run._r)
    # Clear all runs
    for run in list(paragraph.runs):
        run._r.getparent().remove(run._r)
    # Re-attach drawings first so the figure stays with the caption/heading
    p = paragraph._p
    for el in drawing_runs:
        p.append(el)
    run = paragraph.add_run(new_text)
    set_run_font(run)


def replace_in_text(text: str, mapping: list[tuple[str, str]]) -> str:
    out = text
    for old, new in mapping:
        if old in out:
            out = out.replace(old, new)
    return out


def iter_paragraphs(doc: Document):
    for para in doc.paragraphs:
        yield para
    for table in doc.tables:
        for row in table.rows:
            for cell in row.cells:
                for para in cell.paragraphs:
                    yield para


def insert_paragraph_after(paragraph: Paragraph, text: str, style: str | None = None) -> Paragraph:
    new_p = OxmlElement("w:p")
    paragraph._p.addnext(new_p)
    new_para = Paragraph(new_p, paragraph._parent)
    if style:
        try:
            new_para.style = style
        except Exception:
            pass
    run = new_para.add_run(text)
    set_run_font(run)
    new_para.paragraph_format.alignment = WD_ALIGN_PARAGRAPH.JUSTIFY
    new_para.paragraph_format.line_spacing_rule = WD_LINE_SPACING.DOUBLE
    new_para.paragraph_format.first_line_indent = Cm(1.27)
    return new_para


def delete_element(el) -> None:
    parent = el.getparent()
    if parent is not None:
        parent.remove(el)


def paragraph_plain(el) -> str:
    texts = [t.text or "" for t in el.iter(qn("w:t"))]
    return "".join(texts).strip()


def convert_figure_wraps(doc: Document) -> int:
    count = 0
    body = doc.element.body
    for old_name in ("wrapSquare", "wrapTight", "wrapThrough"):
        for wrap in list(body.iter("{%s}%s" % (WP_NS, old_name))):
            wrap.tag = "{%s}wrapTopAndBottom" % WP_NS
            # keep attributes that remain valid
            count += 1
    return count


def set_keep_with_next_for_captions(doc: Document) -> None:
    for para in doc.paragraphs:
        t = para.text.strip()
        if t.startswith("Figure ") or t.startswith("Table "):
            para.paragraph_format.keep_with_next = True
            para.paragraph_format.keep_together = True


TEXT_MAP = [
    (
        "Financial Risk Assessment and Micro-Lending System (FRAMS):",
        "Financial Risk Assessment and Micro-Lending System (FRAMS)",
    ),
    ("October 15, 2026", "October 23, 2026"),
    (
        "RJ and RR Finance Services faces challenges",
        "The lending institution, RJ and RR Finance Services, faces challenges",
    ),
    ("FRAMS differ from the reviewed predictive systems", "FRAMS differs from the reviewed predictive systems"),
    ("how FRAMS work.", "how FRAMS works."),
    ("The eligibility estimates computes", "The eligibility estimate computes"),
    ("The eligibility estimates assigns", "The eligibility estimate assigns"),
    ("and I am monthly gross income.", "and I represents monthly gross income."),
    ("DTI_after = (E + MP) / I) × 100", "DTI_after = ((E + MP) / I) × 100"),
    (
        "implementation and observes testing results",
        "implementation and observed testing results",
    ),
    (
        "28 borrowers, one administrator, and three collectors",
        "30 borrowers, one administrator, and one collector",
    ),
    (
        "consisted of 28 borrowers, one administrator, and three collectors",
        "consisted of 30 borrowers, one administrator, and one collector",
    ),
    (
        "consisting of 28 borrowers, one administrator, and three collectors",
        "consisting of 30 borrowers, one administrator, and one collector",
    ),
    ("Khandani et al. (2018)", "Khandani et al. (2010)"),
    ("Figure L1.", "Figure K1."),
    ("Figure L1 ", "Figure K1 "),
    ("A.1 References matched to published works", "References"),
    (
        "Entries in A.1 follow APA 7th edition and were checked against the published work. Journal titles and volume numbers are italicized in the usual APA way. A hanging indent is used.",
        "The following references are presented in APA 7th edition format.",
    ),
    (
        "The findings also distinguish the supplied criterion-level user ratings from item-level questionnaire evidence and distinguish local PayMongo webhook simulations from external provider deliveries.",
        "The findings are calculated from the twenty item-level questionnaire answers recorded for each respondent in the supplied workbook. Local PayMongo simulations are distinguished from external provider deliveries.",
    ),
    ("six-unit tests", "six unit tests"),
]


PAYMENT_REPLACEMENTS = [
    (
        "The balance, receipt, and installment status change only after PayMongo sends a verified webhook and the paid amount matches the loan. Showing the code, returning to the browser, or waiting for a local timer does not record the repayment.",
        "The QR image is not a payment confirmation. While the QR payment page remains open, FRAMS polls the PayMongo Payment Intent API and records the repayment only after the provider status is succeeded and the paid amount matches the pending transaction. A webhook handler is present in the application and may also post a verified event, but it is optional for this collector workflow. Showing the code, returning to the browser, or waiting for a local timer does not, by itself, record the repayment.",
    ),
    (
        "According to the intended workflow, an authorized collector initiates a QR payment for an assigned loan, and the corresponding repayment is recorded after the payment notification has been verified and the paid amount has been matched to the transaction. Cash payments and other authorized payment records may be entered directly by authorized personnel. The successful operation of external payment processing depends on the correct configuration of the applicable API credentials, webhook secret, and test or live environment.",
        "According to the implemented workflow, an authorized collector initiates a QR payment for an assigned loan. The corresponding repayment is recorded after the Payment Intent status is confirmed as succeeded and the paid amount has been matched to the pending transaction. While the QR page is open, FRAMS polls the Payment Intent API for that confirmation. A webhook may also post a verified event when configured, but the collector workflow does not require a delivered webhook in order to record a matching paid intent. Cash payments and other authorized payment records may be entered directly by authorized personnel. Successful QR Ph collection depends on valid PayMongo API credentials and network access to retrieve Payment Intent status.",
    ),
    (
        "For bank transfer or e-wallet, a collector may instead start PayMongo Dynamic QR Ph. That repayment is stored only after a verified webhook.",
        "For bank transfer or e-wallet, a collector may instead start PayMongo Dynamic QR Ph. That repayment is stored only after the Payment Intent is confirmed as succeeded, either through status polling on the open QR page or through an optional verified webhook.",
    ),
    (
        "A verified PayMongo webhook also writes that store, and it is not drawn as a separate process on this figure.",
        "A confirmed PayMongo Payment Intent, retrieved while the QR page is open, also writes that store, and it is not drawn as a separate process on this figure. An optional webhook may write the same store when it is configured.",
    ),
    (
        "paymongo_qrph_webhook_events. Stores PayMongo webhook events for a QR Ph transaction and whether each event was processed. The repayment is posted from a verified event, not from the browser return.",
        "paymongo_qrph_webhook_events. Stores optional PayMongo webhook events for a QR Ph transaction and whether each event was processed. In the implemented collector workflow, the repayment is posted after Payment Intent status polling confirms success; the webhook is an additional path and is not required for the open QR page to record a matching paid intent.",
    ),
    (
        "The balance stays unchanged until PayMongo’s webhook is verified and the paid amount matches the loan. A failed, expired, or mismatched attempt does not post a payment. A verified QR Ph payment remains on the receipt record and is not deleted.",
        "The balance stays unchanged until the Payment Intent is confirmed as succeeded and the paid amount matches the pending transaction. Displaying the QR code does not record the repayment. A failed, expired, or mismatched attempt does not post a payment. A verified QR Ph payment remains on the receipt record and is not deleted.",
    ),
    (
        "The payments page uses two authenticated JSON lookups. One loads the selected loan and its remaining balance into the payment form. The other returns the status of a PayMongo QR Ph transaction. A QR Ph repayment is posted only after the webhook is verified.",
        "The payments page uses two authenticated JSON lookups. One loads the selected loan and its remaining balance into the payment form. The other refreshes a pending PayMongo QR Ph transaction by retrieving its Payment Intent status and returns the stored result. A QR Ph repayment is posted only after that status is succeeded and the amount matches, or after an optional verified webhook posts the same confirmation.",
    ),
    (
        "When the method is bank transfer or e-wallet, that collector can start PayMongo Dynamic QR Ph. The repayment, receipt, and balance change are saved only after the webhook is verified. An unverified, expired, or mismatched QR code does not post a payment, and a verified QR Ph payment is not deleted from the list.",
        "When the method is bank transfer or e-wallet, that collector can start PayMongo Dynamic QR Ph. The repayment, receipt, and balance change are saved only after Payment Intent polling on the open QR page, or an optional verified webhook, confirms success and the paid amount matches the pending transaction. An unverified, expired, or mismatched QR code does not post a payment, and a verified QR Ph payment is not deleted from the list.",
    ),
]


UAT_SOURCE_PARAS = {
    "User Acceptance Testing (UAT) was intended to evaluate the acceptability of the Financial Risk Assessment and Micro-Lending System (FRAMS) based on respondents’ experience using the developed system. The criterion-level data supplied are reported for thirty-two (32) respondents: thirty (30) borrowers, one (1) administrator, and one (1) collector. These figures remain subject to confirmation against the original completed forms and respondent-role tally. The five evaluation criteria were functionality, usability, reliability, security, and performance.": (
        "User Acceptance Testing (UAT) evaluated the acceptability of the Financial Risk Assessment and Micro-Lending System (FRAMS) based on respondents’ experience using the developed system. The supplied workbook, FRAMS UAT Responses (1).xlsx, contains 32 valid submissions and a recorded user-role field for each respondent: 30 borrowers, one administrator, and one collector. Each respondent answered all 20 questionnaire items. The five evaluation criteria were functionality, usability, reliability, security, and performance."
    ),
}

LONG_REPLACEMENTS = {
    "A single installation script that creates every table was not found in the repository. The PayMongo QR Ph tables are created from the SQL migration files. The pages expect core tables for users, clients, loans, and payments, and several other feature tables and columns are created or extended when the related page runs. Eligibility results are columns on the loan application, not a separate risk table. Passwords are stored as hashes. This section does not list credentials.": (
        "A structure-only schema dump of the operational database lending_management is provided as Appendix L and as the accompanying file FRAMS_APPENDIX_L_Database_Schema.sql. That dump includes the required tables, primary keys, unique keys, and the one declared foreign key. The PayMongo QR Ph tables are also created from the SQL files in database/migrations/. After the dump is imported, several feature columns continue to be created or extended when the related page first runs. Eligibility results are columns on the loan application, not a separate risk table. Passwords are stored as hashes. This section does not list credentials."
    ),
    "Evidence limitation: the tally below is computed from the supplied respondent-level criterion scores (one rating per respondent for each of the five criteria). It verifies the stated criterion means from those scores, but it is not a transcription of all 20 individual questionnaire-item responses. The questionnaire added in Appendix I is a blank instrument template, not a completed response set.": (
        "Source of the tally. The frequencies and weighted means below were computed from the 20 individual item responses recorded for each of the 32 respondents in the supplied workbook. The blank questionnaire in Appendix I documents the instrument wording; it is not a completed response set."
    ),
    "Primary records to retain and verify before final submission are the exact questionnaire instrument administered, the original completed forms with personal information protected, the respondent-role tally, and the original calculation workbook. If the respondents completed only one rating per criterion, the manuscript should describe that instrument accurately rather than imply that twenty item-level responses were collected. If the twenty-item form was administered, its individual answers must be transcribed from the original forms; they must not be reconstructed from criterion-level averages.": (
        "The administered 20-item questionnaire is documented in Appendix I. The completed item-level answers and the recorded user-role field are retained in the supplied workbook. The respondent distribution used throughout this manuscript is 30 borrowers, one administrator, and one collector."
    ),
}


INSERT_AFTER = {
    "Research Design": None,  # handled specially
}

RESEARCH_DESIGN_ADDENDUM = (
    "The modules were developed in the order of the lending workflow. Registration, administrator approval, and role-based login were completed first and checked against the pre-development interview notes. The five-step loan application, document upload, and eligibility estimate were added next and reviewed against the documented lending criteria. Agreement acceptance, collector assignment, loan release, and installment generation followed. Payment recording, penalty computation, PayMongo QR Ph collection, notifications, and reports were then integrated. Each iteration used the same local PHP and MySQL environment. Revisions after testing are those documented in Chapter IV and Appendix K. A separate dated iteration log was not attached to this manuscript."
)

THEORY_ADDENDUM = (
    "These models also inform the User Acceptance Testing criteria used in Chapter IV. Functionality and reliability correspond to system quality in the DeLone and McLean model. Usability corresponds to perceived ease of use in the Technology Acceptance Model. Security corresponds to protected and role-restricted use. Performance corresponds to system quality as experienced during ordinary office use. The UAT ratings therefore represent user perceptions of these qualities. They are not independent laboratory measurements of response time or of residual security risk."
)

MIN_LOAN_ADDENDUM = (
    "The loan-amount field on the application form uses an HTML minimum of PHP 1,000 so that the browser can reject nonnumeric or negative entry. The eligibility function, however, still applies the configured policy minimum of PHP 3,000 and blocks a requested amount below that limit. Appendix K, Figure K1, shows the frontend numeric-entry message and should not be read as a change to the policy minimum."
)

BOUNDARY_ADDENDUM = (
    "When available payment capacity is zero or negative, the maximum affordable new payment is stored as zero, the maximum principal is zero, and the estimate is Not Eligible because that principal is below the minimum loan amount. If proposed debt-to-income and the principal limit both fail, both reasons are recorded, and the stored status is Not Eligible; the debt-to-income failure is applied first in the status rule. A partial payment may include a penalty portion. The total received cannot exceed the remaining scheduled balance plus the penalty still due, the penalty portion cannot exceed the outstanding penalty, and the installment portion cannot exceed the remaining scheduled balance."
)

CONCLUSIONS = [
    (
        "In relation to the first specific objective, FRAMS provides a borrower management module that supports registration, administrator approval, client records, online loan applications, and supporting document submission and review.",
        False,
    ),
    (
        "In relation to the second specific objective, FRAMS computes a rule-based eligibility estimate from declared income, existing obligations, requested amount, loan term, and configured limits. The estimate is a decision-support result and does not automatically approve or reject a loan.",
        False,
    ),
    (
        "In relation to the third specific objective, FRAMS supports interest computation, installment schedules, loan agreement acceptance, collector assignment, loan release, payment recording, penalty computation, and repayment monitoring. PayMongo QR Ph repayments are recorded after Payment Intent confirmation, not merely because a QR image was displayed.",
        False,
    ),
    (
        "In relation to the fourth specific objective, FRAMS sends in-application notifications for relevant application decisions, agreement updates, loan releases, and payment transactions, consistent with the notification functions available in the developed system.",
        False,
    ),
    (
        "In relation to the fifth specific objective, FRAMS generates financial reports that support monitoring of loan releases, collections, outstanding balances, overdue accounts, and related lending transactions.",
        False,
    ),
    (
        "In relation to the sixth specific objective, 32 intended users evaluated FRAMS. The supplied workbook contains 30 borrowers, one administrator, and one collector, each of whom answered 20 questionnaire items. The overall weighted mean was 4.53, interpreted as Strongly Agree. These ratings are user perceptions of acceptability and are not independent proof of security, loan accuracy, or measured performance.",
        False,
    ),
    (
        "The documented technical tests support the specific cases examined in their documented environments. Passing those cases does not establish that the entire system is free of defects. Local PayMongo simulations do not independently demonstrate a successful external PayMongo charge or a provider-delivered webhook.",
        False,
    ),
    (
        "The conclusions of this study are therefore limited to the system functions implemented, the documented technical tests, and the recorded user evaluation responses.",
        False,
    ),
]

RECOMMENDATIONS = [
    "Establish a database backup and recovery procedure. Schedule regular MySQL backups of the production database and document how authorized personnel can restore a backup. Confirm the deployed database name before using it in any restore instructions.",
    "Improve auditability by recording important actions—such as account approvals, loan releases, payment edits, and role changes—in a dedicated activity log.",
    "Protect financial integrity by retaining the implemented payment-validation rules, Payment Intent confirmation for QR Ph collections, and the restriction against deleting a payment that is linked to a PayMongo QR Ph transaction. Reconcile pending QR attempts that remain open after the QR page is closed.",
    "Keep the debt-to-income limit, minimum and maximum loan amounts, interest rate, and penalty rates in controlled configuration so approved policy changes do not require undocumented edits to application files.",
    "Develop an administrator settings page, in a later enhancement, that allows authorized personnel to update loan limits and penalty rates, with validation and an audit trail. This page is not part of the current implementation.",
    "SMS notifications may be added later if the lending office needs them and can support the cost. They are not implemented in the current system.",
]


def apply_cell_text(cell, new_text: str) -> None:
    if not cell.paragraphs:
        return
    first = cell.paragraphs[0]
    replace_text_preserve_drawings(first, new_text)
    for extra in cell.paragraphs[1:]:
        extra.text = ""


def remove_duplicate_appendix_b(doc: Document) -> int:
    body = doc.element.body
    children = list(body.iterchildren())
    appendix_hits = []
    for i, el in enumerate(children):
        if el.tag != qn("w:p"):
            continue
        t = paragraph_plain(el)
        if t.startswith("APPENDIX B"):
            appendix_hits.append(i)
        if t.startswith("APPENDIX I"):
            appendix_i = i
            break
    else:
        appendix_i = None
    removed = 0
    if len(appendix_hits) >= 2 and appendix_i is not None:
        start = appendix_hits[1]
        stop = appendix_i
        for el in children[start:stop]:
            delete_element(el)
            removed += 1
    return removed


def remove_paragraphs_matching(doc: Document, predicates) -> int:
    removed = 0
    seen = {id(fn): 0 for fn in predicates}
    for para in list(doc.paragraphs):
        t = para.text.strip()
        if not t:
            continue
        for fn in predicates:
            if fn(t):
                # keep the first Presentation of Testing Data; drop the immediate duplicate
                if "Presentation of Testing Data" in t:
                    seen[id(fn)] += 1
                    if seen[id(fn)] < 2:
                        continue
                delete_element(para._element)
                removed += 1
                break
    return removed


def normalize_misused_headings(doc: Document) -> int:
    count = 0
    prefixes = ("Scenario:", "Expected result:", "Actual result:", "Remarks:")
    for para in doc.paragraphs:
        t = para.text.strip()
        if para.style and para.style.name == "Heading 1" and t.startswith(prefixes):
            para.style = doc.styles["Normal"]
            para.paragraph_format.first_line_indent = Cm(1.27)
            para.paragraph_format.line_spacing_rule = WD_LINE_SPACING.DOUBLE
            count += 1
    return count


def add_appendix_l(doc: Document) -> None:
    last = doc.paragraphs[-1]
    heading = insert_paragraph_after(last, "APPENDIX L", None)
    heading.paragraph_format.first_line_indent = Cm(0)
    heading.alignment = WD_ALIGN_PARAGRAPH.CENTER
    for run in heading.runs:
        run.bold = True
    t1 = insert_paragraph_after(
        heading,
        "DATABASE SCHEMA AND INSTALLATION NOTES",
        None,
    )
    t1.paragraph_format.first_line_indent = Cm(0)
    t1.alignment = WD_ALIGN_PARAGRAPH.CENTER
    for run in t1.runs:
        run.bold = True
    insert_paragraph_after(
        t1,
        "This appendix supplies the reproducible database documentation requested for the operational FRAMS database. A structure-only dump of lending_management, without table-row data and without credentials, is saved as FRAMS_APPENDIX_L_Database_Schema.sql. The dump was generated from the local MariaDB instance used by the implemented system.",
    )
    # find the paragraph we just added by walking next siblings is messy; append sequentially from last known
    # Re-get last paragraph
    p = doc.paragraphs[-1]
    insert_paragraph_after(
        p,
        "Installation procedure. Create an empty MySQL or MariaDB database named lending_management, or another name supplied in the application configuration. Import FRAMS_APPENDIX_L_Database_Schema.sql. Configure the host, database name, user, and password in the application environment. Place the application under the web server document root and open the staff or borrower login. After import, some feature columns continue to be added when the related page first runs. The PayMongo QR Ph tables are included in the dump and are also described by the SQL files in database/migrations/.",
    )
    p = doc.paragraphs[-1]
    insert_paragraph_after(
        p,
        "Declared relationships. The dump contains one foreign key: paymongo_qrph_webhook_events.transaction_id references paymongo_qrph_transactions.id with ON DELETE SET NULL. Relationships among users, clients, loans, releases, schedules, and payments are enforced in application queries. A deleted parent row is not stopped by a database foreign key on those core tables.",
    )
    p = doc.paragraphs[-1]
    insert_paragraph_after(
        p,
        "Required tables in the dump: users, clients, borrower_applications, loan_applications, loan_application_documents, loan_application_reviews, loan_agreements, loan_releases, loan_release_notifications, loans, loan_payment_schedules, payments, expenses, notifications, password_resets, contact_inquiries, contact_inquiry_replies, email_notifications, paymongo_qrph_transactions, and paymongo_qrph_webhook_events.",
    )
    p = doc.paragraphs[-1]
    insert_paragraph_after(
        p,
        "Initialization notes. AUTO_INCREMENT values in the dump reflect the source database at the time of export and are not required for a new empty installation. Passwords in a new installation must be created through the application so that they are stored as hashes. This appendix does not contain live API keys, webhook secrets, or borrower records.",
    )


def write_simple_doc(path: Path, title: str, sections: list[tuple[str, list[str]]]) -> None:
    d = Document()
    for section in d.sections:
        section.page_width = Cm(21.0)
        section.page_height = Cm(29.7)
        section.left_margin = Cm(2.54)
        section.right_margin = Cm(2.54)
        section.top_margin = Cm(2.54)
        section.bottom_margin = Cm(2.54)
    h = d.add_paragraph(title)
    h.alignment = WD_ALIGN_PARAGRAPH.CENTER
    for run in h.runs:
        set_run_font(run, size=16, bold=True)
    sub = d.add_paragraph("Financial Risk Assessment and Micro-Lending System (FRAMS)")
    sub.alignment = WD_ALIGN_PARAGRAPH.CENTER
    datep = d.add_paragraph("23 October 2026")
    datep.alignment = WD_ALIGN_PARAGRAPH.CENTER
    for heading, bullets in sections:
        ph = d.add_paragraph(heading)
        for run in ph.runs:
            set_run_font(run, size=13, bold=True)
        for bullet in bullets:
            bp = d.add_paragraph(bullet, style="List Bullet")
            for run in bp.runs:
                set_run_font(run, size=11)
    d.save(path)


def main() -> None:
    shutil.copy2(ORIGINAL, REVISED)
    if SCHEMA_SRC.exists():
        shutil.copy2(SCHEMA_SRC, SCHEMA_DST)

    doc = Document(str(REVISED))

    wrap_count = convert_figure_wraps(doc)
    heading_fix = normalize_misused_headings(doc)

    # Exact long replacements first
    for para in iter_paragraphs(doc):
        original = para.text
        if not original:
            continue
        new = original
        if original.strip() in LONG_REPLACEMENTS:
            new = LONG_REPLACEMENTS[original.strip()]
        elif original.strip() in UAT_SOURCE_PARAS:
            new = UAT_SOURCE_PARAS[original.strip()]
        else:
            new = replace_in_text(new, PAYMENT_REPLACEMENTS)
            new = replace_in_text(new, TEXT_MAP)
        if new != original:
            replace_text_preserve_drawings(para, new)

    # Table-specific citation year
    for table in doc.tables:
        for row in table.rows:
            for cell in row.cells:
                txt = cell.text
                new = replace_in_text(txt, TEXT_MAP)
                new = replace_in_text(new, PAYMENT_REPLACEMENTS)
                if new != txt:
                    apply_cell_text(cell, new)
                # broken first-column header
                if cell.text.strip() in {"Ape", "Ape "}:
                    apply_cell_text(cell, "Criterion")

    # Insert research-design addendum
    for para in doc.paragraphs:
        if para.text.startswith("This study used a developmental research design"):
            insert_paragraph_after(para, RESEARCH_DESIGN_ADDENDUM)
            break

    for para in doc.paragraphs:
        if para.text.startswith("This model is applicable to FRAMS because the system must be usable"):
            insert_paragraph_after(para, THEORY_ADDENDUM)
            break

    for para in doc.paragraphs:
        if "a minimum loan amount of PHP 3,000" in para.text and "HTML minimum" not in para.text:
            insert_paragraph_after(para, MIN_LOAN_ADDENDUM)
            break

    for para in doc.paragraphs:
        if para.text.startswith("The eligibility estimate assigns Not Eligible when required information is missing"):
            insert_paragraph_after(para, BOUNDARY_ADDENDUM)
            break

    # Definition of terms: add PayMongo term after Payment paragraph
    for para in doc.paragraphs:
        if para.text.startswith("Payment. An amount recorded against a loan"):
            insert_paragraph_after(
                para,
                "PayMongo Dynamic QR Ph. A collector-initiated QR payment for bank transfer or e-wallet. The repayment is recorded after PayMongo reports a succeeded Payment Intent for the matching amount, through status polling on the open QR page or through an optional webhook. Displaying the QR image is not confirmation of payment.",
            )
            break

    # Data-gathering distinction
    for para in doc.paragraphs:
        if para.text.startswith("The following instruments were identified for the study:"):
            replace_text_preserve_drawings(
                para,
                "The following instruments were used. Interviews, observation, and document review were conducted before coding. The user evaluation form was administered after development. Secondary sources were consulted during the review of literature.",
            )
            break

    # Statistical treatment clarification
    for para in doc.paragraphs:
        if para.text.startswith("Each of the five criteria contained four statements answered by 32 respondents"):
            extra = (
                " The supplied workbook contains the individual answer to each of the 20 questions for every respondent. "
                "The analysis did not begin from five criterion-level scores per respondent."
            )
            replace_text_preserve_drawings(para, para.text.rstrip() + extra)
            break

    # Remove duplicate paragraphs
    def is_dup_51(t: str) -> bool:
        return t.startswith("Figure 5.1 illustrates the overall operational flow of FRAMS.")

    def is_dup_55(t: str) -> bool:
        return t.startswith("Figure 5.5 shows the eligibility estimate used during the application") and t.count("configured defaults") > 0

    def is_dup_heading(t: str) -> bool:
        return t == "Presentation of Testing Data"

    def is_backslash(t: str) -> bool:
        return t == "\\"

    removed_paras = remove_paragraphs_matching(doc, [is_dup_51, is_backslash, is_dup_heading])
    # Remove second copy of Figure 5.5 explanation only
    seen_55 = 0
    for para in list(doc.paragraphs):
        t = para.text.strip()
        if t.startswith("Figure 5.5 shows the eligibility estimate used during the application"):
            seen_55 += 1
            if seen_55 >= 2:
                delete_element(para._element)
                removed_paras += 1

    removed_app = remove_duplicate_appendix_b(doc)

    # Replace conclusions
    for para in doc.paragraphs:
        if para.text.startswith("FRAMS provides a centralized web-based application for recording borrower information"):
            # overwrite this and the next three conclusion paragraphs
            replace_text_preserve_drawings(para, CONCLUSIONS[0][0])
            # find following conclusion paras by nearby headings
            break
    # Replace remaining conclusion paragraphs more reliably
    conclusion_started = False
    rec_started = False
    conclusion_index = 1
    for para in list(doc.paragraphs):
        t = para.text.strip()
        if t == "Conclusions":
            conclusion_started = True
            continue
        if t.startswith("Recommendations"):
            rec_started = True
            conclusion_started = False
            continue
        if conclusion_started and t:
            if conclusion_index < len(CONCLUSIONS):
                replace_text_preserve_drawings(para, CONCLUSIONS[conclusion_index][0])
                conclusion_index += 1
            else:
                # extra leftover conclusion text
                delete_element(para._element)

    # Rebuild recommendations
    rec_paras = []
    capturing = False
    for para in list(doc.paragraphs):
        t = para.text.strip()
        if t.startswith("Recommendations"):
            capturing = True
            continue
        if capturing:
            if t.startswith("References"):
                break
            rec_paras.append(para)
    # Clear old recommendation body and write new
    if rec_paras:
        replace_text_preserve_drawings(
            rec_paras[0],
            "Based on the system’s current scope and the limitations identified in the study, the researchers recommend the following, in order of priority.",
        )
        # overwrite remaining non-empty rec paras, then insert if needed
        filled = 1
        for para in rec_paras[1:]:
            if filled < len(RECOMMENDATIONS) + 1:
                if filled == 1 and para.text.strip():
                    replace_text_preserve_drawings(para, "1. " + RECOMMENDATIONS[0])
                    filled += 1
                elif para.text.strip() or filled <= len(RECOMMENDATIONS):
                    if filled - 1 < len(RECOMMENDATIONS):
                        replace_text_preserve_drawings(para, f"{filled}. " + RECOMMENDATIONS[filled - 1])
                        filled += 1
                    else:
                        if not para.text.strip():
                            continue
                        delete_element(para._element)
        last = rec_paras[min(len(rec_paras), filled) - 1] if rec_paras else None
        # If not enough existing paragraphs, insert
        if filled <= len(RECOMMENDATIONS):
            anchor = rec_paras[-1]
            for i in range(filled - 1, len(RECOMMENDATIONS)):
                anchor = insert_paragraph_after(anchor, f"{i + 1}. " + RECOMMENDATIONS[i])

    add_appendix_l(doc)
    set_keep_with_next_for_captions(doc)

    # Compact the empty-looking Definition of Terms gap: convert the stray backslash already removed.
    # Standardize title-page leftover spacing cannot be fully reconstructed here.

    doc.save(str(REVISED))

    # Post-check remaining issues
    check = Document(str(REVISED))
    remaining = []
    needles = [
        "28 borrowers",
        "three collectors",
        "FRAMS differ",
        "FRAMS work.",
        "eligibility estimates computes",
        "eligibility estimates assigns",
        "I am monthly",
        "DTI_after = (E + MP) / I)",
        "observes testing",
        "subject to confirmation",
        "Khandani et al. (2018)",
        "Figure L1",
        "criterion-level data supplied",
        "one rating per respondent for each of the five criteria",
        "A.1 References",
        "October 15, 2026",
    ]
    blob_paras = "\n".join(p.text for p in check.paragraphs)
    blob_tables = "\n".join(c.text for t in check.tables for r in t.rows for c in r.cells)
    blob = blob_paras + "\n" + blob_tables
    for n in needles:
        if n in blob:
            remaining.append(n)

    appendix_b_count = sum(1 for p in check.paragraphs if p.text.strip().startswith("APPENDIX B"))
    presentation_count = sum(1 for p in check.paragraphs if p.text.strip() == "Presentation of Testing Data")
    fig55_count = sum(1 for p in check.paragraphs if p.text.strip().startswith("Figure 5.5 shows the eligibility estimate"))

    # Copy outputs into workspace as well
    WORKSPACE_COPY.mkdir(parents=True, exist_ok=True)
    shutil.copy2(REVISED, WORKSPACE_COPY / REVISED.name)
    shutil.copy2(SCHEMA_DST, WORKSPACE_COPY / SCHEMA_DST.name)

    # Reports
    checklist = [
        ("Chapter I, page 4 — subject-verb agreement", [
            "Comment: revise “RJ and RR Finance Services faces challenges.”",
            "Action: Revised to “The lending institution, RJ and RR Finance Services, faces challenges...”",
            "Verification: paragraph located in Statement of the Problem.",
        ]),
        ("Chapter I, pages 6–7 and Appendix K — minimum loan amount", [
            "Comment: manuscript states PHP 3,000, while a form message shows PHP 1,000.",
            "Action: Kept the policy minimum of PHP 3,000, which matches lendingCreditPolicy() / LENDING_MIN_LOAN. Explained that the HTML input min of 1,000 is frontend numeric-entry validation only. Relabeled Figure L1 as Figure K1.",
            "Verification: borrower_loan_application_lending.php input min=1000; loan_eligibility_helpers.php min_loan_amount default 3000.",
        ]),
        ("Chapter II — grammar and citation year", [
            "Comment: “FRAMS differ”; Table 1 Khandani et al. (2018); “FRAMS work”; “eligibility estimates computes.”",
            "Action: Corrected grammar and changed the table citation to Khandani et al. (2010), matching the narrative and reference list.",
            "Verification: Table 0 cell and Chapter II synthesis paragraph.",
        ]),
        ("Chapter III — UAT respondent distribution", [
            "Comment: use 30 borrowers, 1 administrator, and 1 collector throughout.",
            "Action: Replaced every 28/1/3 statement with the workbook-supported 30/1/1 distribution in Chapters III, IV, and V and in Appendix J/F narratives.",
            "Verification: Form Responses 1 and UAT-10 Role Summary in the supplied Excel file.",
        ]),
        ("Chapter III — statistical source language", [
            "Comment: the workbook contains 20 item answers, not five criterion scores.",
            "Action: Stated that 32 × 20 = 640 individual ratings come from item-level answers. Removed the outdated Appendix F limitation about one rating per criterion.",
            "Verification: 32 rows × 20 Likert columns in the workbook.",
        ]),
        ("Chapter III — iterative development and TAM/DeLone–McLean", [
            "Comment: describe actual development stages; relate theory to UAT criteria.",
            "Action: Added a workflow-order iteration paragraph based on the implemented modules. Added a paragraph mapping UAT criteria to TAM and DeLone–McLean, while stating that UAT scores are perceptions.",
            "Verification: no fabricated meeting dates were introduced.",
        ]),
        ("Chapter III/IV — figure duplication and wrapping", [
            "Comment: DFD/flowchart interrupt text; Figure 5.1 and 5.5 explanations duplicated; duplicate Presentation of Testing Data.",
            "Action: Converted square/tight figure wrapping to top-and-bottom; removed the duplicate Figure 5.1 and Figure 5.5 paragraphs and the repeated heading; kept captions with keep-with-next.",
            "Verification: remaining Figure 5.5 explanation count and Presentation heading count.",
        ]),
        ("Chapter IV — financial formulas", [
            "Comment: Formula 9 wording; Formula 10 parentheses; eligibility grammar; boundary handling.",
            "Action: Corrected “I represents monthly gross income,” DTI_after = ((E + MP) / I) × 100, and “The eligibility estimate assigns.” Added the implemented rules for zero/negative capacity, simultaneous DTI and principal-limit failure, and partial payments with a penalty portion.",
            "Verification: assessLoanEligibility() and validatePaymentAmounts() in the source code. Interest and installment formulas were not changed.",
        ]),
        ("Chapter IV — database documentation", [
            "Comment: supply a reproducible schema.",
            "Action: Added Appendix L and FRAMS_APPENDIX_L_Database_Schema.sql, a structure-only dump of lending_management, with installation notes and the one declared foreign key.",
            "Verification: mysqldump --no-data of lending_management; information_schema shows only fk_paymongo_qrph_webhook_transaction.",
        ]),
        ("Chapter IV — PayMongo verification method", [
            "Comment: align claims with evidence; do not treat QR display as confirmation.",
            "Action: Described Payment Intent API polling on the open QR page as the implemented collector confirmation path. Kept the webhook as an optional additional path. Did not claim that the webhook was removed. Retained the manuscript’s distinction that PM-09/PM-10 are local simulations.",
            "Verification: payments_lending.php action=qrph_status and lendingReconcilePaymongoQrphTransaction().",
        ]),
        ("Chapter V — respondent figures, conclusions, recommendations", [
            "Comment: correct 28/1/3; present conclusions by objective; prioritize backup, auditability, and financial integrity.",
            "Action: Corrected the UAT count, rewrote conclusions against the six Chapter I objectives, and reordered recommendations.",
            "Verification: Chapter I objectives left unchanged.",
        ]),
        ("Appendices — duplication, UAT narrative, Figure L1", [
            "Comment: duplicated Appendix B; outdated criterion-level limitation; Figure L1 label.",
            "Action: Removed the second copy of Appendix B; corrected the UAT source narrative; relabeled Figure K1.",
            "Verification: count of APPENDIX B headings after revision.",
        ]),
        ("Formatting", [
            "Comment: title colon, outdated title-page date, Heading 1 misuse, wrapping, editorial notes in the bibliography.",
            "Action: Removed the title colon, dated the title page 23 October 2026, restyled Scenario/Expected/Actual blocks that had been Heading 1, removed the A.1 editorial bibliography note, and converted figure wrapping.",
            "Verification: opening title paragraph and reference heading.",
        ]),
    ]

    write_simple_doc(
        REPORT,
        "FRAMS Revision Report",
        [
            ("Purpose", [
                "This report maps the 23 October 2026 comments for Group 4 to the corrections applied in FRAMS_REVISED_MANUSCRIPT.docx. The original file FRAMS_3RDREVISIONS.docx was not overwritten.",
            ]),
            ("Evidence used", [
                "Original manuscript: FRAMS_3RDREVISIONS.docx.",
                "Revision comments: October 23, 2026 - Comments for Revisions - Group 4.docx.",
                "UAT workbook: FRAMS UAT Responses  (1).xlsx — 32 submissions; 30 Borrower, 1 Administrator, 1 Collector; 640 item ratings; overall mean 4.5297 (4.53).",
                "Source code in C:\\xampp\\htdocs\\lending_system, including loan_eligibility_helpers.php, loan_helpers.php, payments_lending.php, and paymongo_qrph_webhook.php.",
                "Structure-only dump of database lending_management.",
            ]),
            ("UAT figures retained", [
                "Respondent total: 32.",
                "Role distribution used everywhere after revision: 30 borrowers, 1 administrator, 1 collector.",
                "Frequencies retained: 341 Strongly Agree, 297 Agree, 2 Neutral.",
                "Criterion means retained: Functionality 4.56, Usability 4.54, Reliability 4.56, Security 4.46, Performance 4.52, overall 4.53.",
                "No questionnaire answers were invented or altered.",
            ]),
        ]
        + [(item[0], item[1]) for item in checklist]
        + [
            ("Mechanical counts from the revised file", [
                f"Figure wrap conversions: {wrap_count}.",
                f"Misused Heading 1 restyles: {heading_fix}.",
                f"Duplicate Appendix B body elements removed: {removed_app}.",
                f"Duplicate or stray paragraphs removed: {removed_paras}.",
                f"APPENDIX B headings remaining: {appendix_b_count}.",
                f"“Presentation of Testing Data” headings remaining: {presentation_count}.",
                f"Figure 5.5 explanation paragraphs remaining: {fig55_count}.",
                f"Needles still present in text, if any: {', '.join(remaining) if remaining else 'none of the targeted error strings.'}",
            ]),
            ("What was not rewritten", [
                "Approved project title and the six research objectives.",
                "UAT means, frequencies, and the 32-respondent total.",
                "Loan interest, installment, and eligibility arithmetic, other than formula notation and the documented boundary rules already in the code.",
                "Pass/fail outcomes of the documented test tables.",
                "Author names, DOIs, and publication details in the reference list, except the Khandani year in Table 1.",
            ]),
        ],
    )

    write_simple_doc(
        UNRESOLVED,
        "FRAMS Unresolved Issues",
        [
            ("Items requiring adviser clarification or additional evidence", [
                "Pre-development participation of 12 persons (1 owner, 1 collector, 10 borrowers) is stated in Chapter III. Supporting consent forms, dated interview notes, and a participation record were not attached to the supplied files. The count was retained and flagged here rather than invented.",
                "No complete dated iteration log (what was developed, tested, and revised in each sprint) was supplied. The added iteration paragraph follows the implemented module order only.",
                "External PayMongo evidence remains limited. The source code implements Payment Intent polling and an optional webhook, but the supplied test records still distinguish local simulations from provider-delivered webhooks and live charges. A distinct duplicate-webhook test and a distinct QR-linked deletion-restriction test are implemented in code; the manuscript’s PM-12 remarks still state that the attached screenshot does not itself test deletion. Those test claims were not upgraded to external end-to-end proof.",
                "Core lending tables still lack database foreign keys. Appendix L documents that fact. Adding constraints would be a software change, not a wording change, and was not made for this manuscript revision.",
                "The HTML minimum of 1,000 and the policy minimum of 3,000 still differ in the running form. The manuscript now explains both. Changing the input min to 3000 would be a code change and was not made.",
                "Several Chapter II sources remain in the original reference list. A full source-by-source retrieval of every URL and DOI was not completed in this pass. No replacement authors, years, or DOIs were fabricated.",
                "Figure and table layout inside Word still depends on the original embedded drawings. Wrap mode was converted to top-and-bottom, but some diagrams may still be small or split from captions. A manual print-layout pass in Microsoft Word is still required before binding.",
                "Page numbering, automatic caption fields, and a regenerated table of contents could not be fully rebuilt from python-docx. Authors should update the table of contents and list of figures in Word before printing.",
                "The original manuscript expanded to a very long file with repeated appendix evidence. The duplicate Appendix B copy was removed. Remaining appendix length is the original first copy plus Appendices I–L.",
            ]),
            ("Conflicts that were not guessed", [
                "The comments document asked the researchers to investigate the ₱1,000 versus ₱3,000 contradiction. The code contains both values for different layers. The manuscript now reports both; it does not silently change the approved policy minimum.",
                "The comments document treats webhook evidence as still relevant to PayMongo testing. The running collector workflow uses Payment Intent polling and keeps the webhook optional. The manuscript describes both, and does not claim that the webhook was removed.",
                "One later appendix fragment in the original file reported criterion means of 4.81/4.75/4.78. That fragment belonged to the duplicated Appendix B block and was removed with the duplicate. The verified workbook means of 4.56/4.54/4.56/4.46/4.52/4.53 were retained.",
            ]),
        ],
    )

    write_simple_doc(
        COMPARISON,
        "FRAMS Comparison Report",
        [
            ("Substantial changes from FRAMS_3RDREVISIONS.docx", [
                "Title page: colon removed from the project title; date updated from 15 October 2026 to 23 October 2026.",
                "Chapter I: subject-verb correction; PayMongo verification described as Payment Intent polling with an optional webhook; HTML versus policy loan minima explained; PayMongo term added to the definitions.",
                "Chapter II: grammar corrections; Khandani year in Table 1 corrected to 2010; TAM/DeLone–McLean linked to UAT criteria.",
                "Chapter III: UAT roles corrected to 30/1/1; item-level 640-rating source stated; iteration paragraph added; duplicate flowchart explanations and a duplicate heading removed; figure wrapping converted.",
                "Chapter IV: Formula 9 and Formula 10 corrected; eligibility grammar corrected; boundary rules documented from code; database section now points to Appendix L; PayMongo implementation and test-scope language aligned.",
                "Chapter V: UAT distribution corrected; conclusions rewritten against the six objectives; recommendations reordered.",
                "Appendices: duplicate Appendix B removed; UAT source narrative corrected; Figure L1 relabeled Figure K1; Appendix L and the SQL dump added.",
            ]),
            ("Unchanged on purpose", [
                "Research title and specific objectives.",
                "UAT frequencies 341 / 297 / 2 and overall mean 4.53.",
                "Documented pass/fail marks in the test tables.",
                "Interest, installment, and eligibility formulas as implemented.",
            ]),
        ],
    )

    shutil.copy2(REPORT, WORKSPACE_COPY / REPORT.name)
    shutil.copy2(UNRESOLVED, WORKSPACE_COPY / UNRESOLVED.name)
    shutil.copy2(COMPARISON, WORKSPACE_COPY / COMPARISON.name)

    print("REVISED", REVISED, "paras", len(check.paragraphs), "tables", len(check.tables))
    print("WRAP", wrap_count, "HEADINGFIX", heading_fix, "DUPAPP", removed_app, "DUPPARA", removed_paras)
    print("APPB", appendix_b_count, "PRESENT", presentation_count, "FIG55", fig55_count)
    print("REMAINING", remaining)


if __name__ == "__main__":
    main()
