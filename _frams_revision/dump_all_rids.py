# -*- coding: utf-8 -*-
from pathlib import Path
from docx import Document
from docx.oxml.ns import qn

MS = Path(r"C:\Users\HomePC\Downloads\FRAMS_MANUSCRIPT_AUDIT_CORRECTED.docx")
OUT = Path(r"C:\xampp\htdocs\lending_system\_frams_revision\all_rids.txt")
IMG = Path(r"C:\xampp\htdocs\lending_system\_frams_revision\extracted_images")
d = Document(str(MS))
lines = []

# all image rels
for rid, rel in d.part.rels.items():
    if "image" in rel.reltype:
        blob = rel.target_part.blob
        name = Path(rel.target_ref).name
        lines.append(f"{rid}\t{name}\t{len(blob)}")
        dest = IMG / f"all_{rid}_{name}"
        if not dest.exists():
            dest.write_bytes(blob)

# every paragraph with drawing, 880-1220
lines.append("\n=== DRAW PARAS ===")
for i, p in enumerate(d.paragraphs):
    if i < 880 or i > 1220:
        continue
    rids = [blip.get(qn("r:embed")) for blip in p._p.iter(qn("a:blip"))]
    rids = [r for r in rids if r]
    t = p.text.strip().replace("\n", " | ")
    if rids or t:
        lines.append(f"[{i}] rids={rids} {t[:180]}")

# front matter
lines.append("\n=== FRONT 0-80 ===")
for i, p in enumerate(d.paragraphs[:80]):
    t = p.text.strip()
    if t:
        lines.append(f"[{i}] {t[:200]}")

OUT.write_text("\n".join(lines), encoding="utf-8")
print("ok", OUT)
