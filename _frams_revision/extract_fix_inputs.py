# -*- coding: utf-8 -*-
from pathlib import Path
from docx import Document
from openpyxl import load_workbook

MS = Path(r"C:\Users\HomePC\Downloads\FRAMS_MANUSCRIPT_AUDIT_CORRECTED.docx")
if not MS.exists():
    MS = Path(r"C:\Users\HomePC\Downloads\FRAMS\FRAMS_MANUSCRIPT_AUDIT_CORRECTED.docx")
ORIG = Path(r"C:\Users\HomePC\Downloads\FRAMS_3RDREVISIONS.docx")
XLSX = Path(r"C:\Users\HomePC\Downloads\FRAMS UAT Responses  (1).xlsx")
QDOC = Path(r"C:\Users\HomePC\Downloads\FRAMS_User_Evaluation_Questionnaire_and_Response_Sheets.docx")
OUT = Path(r"C:\xampp\htdocs\lending_system\_frams_revision\fix_inputs.txt")

lines = []
d = Document(str(MS))
lines.append(f"MS={MS} exists={MS.exists()} paras={len(d.paragraphs)} tables={len(d.tables)}")

# T13
t13 = d.tables[13]
lines.append("\n=== T13 ===")
for ri, row in enumerate(t13.rows):
    lines.append(" | ".join(c.text.replace("\n", " ").strip() for c in row.cells))

# T21 full
t21 = d.tables[21]
lines.append(f"\n=== T21 {len(t21.rows)}x{len(t21.columns)} ===")
for ri, row in enumerate(t21.rows):
    cells = [c.text.replace("\n", " | ").strip() for c in row.cells]
    lines.append(f"R{ri}: {cells}")

# figure/table captions
lines.append("\n=== CAPTIONS ===")
for i, p in enumerate(d.paragraphs):
    t = p.text.strip()
    if t.startswith("Figure ") or t.startswith("Table ") or t.startswith("APPENDIX") or t in ("List of Tables", "List of Figures", "Table of Contents"):
        lines.append(f"[{i}] {t[:240]}")

# ISO / PayMongo in-text
body = "\n".join(p.text for p in d.paragraphs)
for needle in ["ISO", "PayMongo API", "PayMongo.", "International Organization"]:
    lines.append(f"\nNEEDLE {needle}: {body.count(needle)}")

# original refs
if ORIG.exists():
    o = Document(str(ORIG))
    lines.append(f"\n=== ORIG REFS paras={len(o.paragraphs)} ===")
    in_refs = False
    for i, p in enumerate(o.paragraphs):
        t = p.text.strip()
        if t.lower().startswith("references") and i > 500:
            in_refs = True
        if in_refs:
            if t.upper().startswith("APPENDIX"):
                break
            if t:
                lines.append(f"[{i}] {t[:300]}")

# questionnaire workbook headers
if XLSX.exists():
    wb = load_workbook(str(XLSX), data_only=True)
    lines.append(f"\n=== XLSX sheets {wb.sheetnames} ===")
    ws = wb[wb.sheetnames[0]]
    headers = []
    for col in range(1, ws.max_column + 1):
        v = ws.cell(1, col).value
        headers.append(str(v)[:80] if v is not None else "")
    lines.append("HEADERS: " + " || ".join(headers))
    # also row 2
    row2 = [str(ws.cell(2, c).value)[:80] if ws.cell(2, c).value is not None else "" for c in range(1, min(ws.max_column, 30)+1)]
    lines.append("ROW2: " + " || ".join(row2))

if QDOC.exists():
    q = Document(str(QDOC))
    lines.append(f"\n=== QDOC paras={len(q.paragraphs)} tables={len(q.tables)} ===")
    for i, p in enumerate(q.paragraphs[:80]):
        if p.text.strip():
            lines.append(f"[{i}] {p.text.strip()[:220]}")
    for ti, tbl in enumerate(q.tables[:3]):
        lines.append(f"\nQDOC T{ti} {len(tbl.rows)}x{len(tbl.columns)}")
        for ri, row in enumerate(tbl.rows[:30]):
            cells = [c.text.replace("\n"," ")[:80].strip() for c in row.cells]
            lines.append(f"  R{ri}: {cells}")

# images in appendix B region
lines.append("\n=== IMAGE RELS COUNT ===")
n_img = 0
for rel in d.part.rels.values():
    if "image" in rel.reltype:
        n_img += 1
lines.append(f"images={n_img}")

OUT.write_text("\n".join(lines), encoding="utf-8")
print("wrote", OUT, "chars", OUT.stat().st_size)
