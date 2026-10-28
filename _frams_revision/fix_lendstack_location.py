# -*- coding: utf-8 -*-
from pathlib import Path
import shutil
from docx import Document
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Pt, Inches
from docx.text.paragraph import Paragraph
from docx.enum.text import WD_ALIGN_PARAGRAPH

DEST = Path(r"C:\Users\HomePC\Downloads\FRAMS_MANUSCRIPT_FINAL_REVIEWED.docx")
COPIES = [
    Path(r"C:\xampp\htdocs\lending_system\_frams_revision\FRAMS_MANUSCRIPT_FINAL_REVIEWED.docx"),
    Path(r"C:\Users\HomePC\Downloads\FRAMS\FRAMS_MANUSCRIPT_FINAL_REVIEWED.docx"),
]

def set_run_font(run, name="Times New Roman", size=12, bold=None, italic=None):
    run.font.name = name
    run._element.rPr.rFonts.set(qn("w:eastAsia"), name)
    run.font.size = Pt(size)
    if bold is not None:
        run.bold = bold
    if italic is not None:
        run.italic = italic

def insert_paragraph_after(paragraph):
    new_p = OxmlElement("w:p")
    paragraph._p.addnext(new_p)
    return Paragraph(new_p, paragraph._parent)

doc = Document(str(DEST))
removed = 0
for p in list(doc.paragraphs):
    t = p.text.strip()
    if t.startswith("LendStack. (n.d.).") and "building the future of lending infrastructure" in t.lower():
        # keep only if it sits in the reference list (after Ramirez/Khandani bibliography form)
        # remove Chapter II mis-insert
        parent = p._p.getparent()
        parent.remove(p._p)
        removed += 1
        print("REMOVED misplaced:", t[:120])

# insert after bibliographic Khandani, A. E.
target = None
for p in doc.paragraphs:
    if p.text.strip().startswith("Khandani, A. E."):
        target = p
        break
if target is None:
    raise SystemExit("bibliographic Khandani not found")

para = insert_paragraph_after(target)
parts = [
    ("LendStack. (n.d.). ", False, False),
    ("Building the future of lending infrastructure", False, True),
    (". https://www.lendstack.in/", False, False),
]
for text, bold, italic in parts:
    run = para.add_run(text)
    set_run_font(run, size=12, bold=bold, italic=italic)
fmt = para.paragraph_format
fmt.left_indent = Inches(0.5)
fmt.first_line_indent = Inches(-0.5)
fmt.space_after = Pt(6)
fmt.line_spacing = 2.0
para.alignment = WD_ALIGN_PARAGRAPH.LEFT

doc.save(str(DEST))
print("saved, removed", removed)

# reopen
d = Document(str(DEST))
print("\nLendStack locations:")
in_ch2 = False
for i, p in enumerate(d.paragraphs):
    if "LendStack" in p.text:
        print(f"[{i}] {p.text[:200]}")
print("\nRefs around Khandani:")
for i, p in enumerate(d.paragraphs):
    t = p.text.strip()
    if t.startswith("Khandani, A.") or t.startswith("LendStack.") or t.startswith("Mina, J.") or t.startswith("Santos, A."):
        print(f"[{i}] {t[:180]}")

# synthesis should still mention LendStack (n.d.)
body = "\n".join(p.text for p in d.paragraphs)
print("\nCh2 narrative still has LendStack (n.d.)", "LendStack (n.d.) and BlueOrchard" in body)
print("paras", len(d.paragraphs), "tables", len(d.tables))
print("AUDIT_CORRECTED untouched check later")

for dest in COPIES:
    dest.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(DEST, dest)
    print("copied", dest)
