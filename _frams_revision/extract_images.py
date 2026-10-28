# -*- coding: utf-8 -*-
from pathlib import Path
from docx import Document
from docx.document import Document as DocumentClass
from lxml import etree

NS = {"w": "http://schemas.openxmlformats.org/wordprocessingml/2006/main",
      "a": "http://schemas.openxmlformats.org/drawingml/2006/main",
      "r": "http://schemas.openxmlformats.org/officeDocument/2006/relationships"}

MS = Path(r"C:\Users\HomePC\Downloads\FRAMS_MANUSCRIPT_AUDIT_CORRECTED.docx")
OUTDIR = Path(r"C:\xampp\htdocs\lending_system\_frams_revision\extracted_images")
OUTDIR.mkdir(parents=True, exist_ok=True)
LOG = Path(r"C:\xampp\htdocs\lending_system\_frams_revision\image_map.txt")

d = Document(str(MS))
lines = []

# TOC / fields
lines.append("=== FIELDS / TOC ===")
xml = d.element.body.xml
for needle in ["TOC", "w:instrText", "List of Tables", "List of Figures", "TABLE OF CONTENTS"]:
    lines.append(f"{needle} count in body xml: {xml.count(needle)}")

# ISO / PayMongo body paragraphs
lines.append("\n=== ISO / PayMongo paras ===")
for i, p in enumerate(d.paragraphs):
    t = p.text
    if "ISO" in t or "25010" in t or "PayMongo API" in t or "developers.paymongo" in t:
        lines.append(f"[{i}] {t[:300]}")

# section page
sec = d.sections[0]
lines.append(f"\nPAGE w={sec.page_width.cm:.2f} h={sec.page_height.cm:.2f} L={sec.left_margin.cm:.2f} R={sec.right_margin.cm:.2f}")

# collect blip embed ids near captions B26-B37
from docx.oxml.ns import qn

def para_image_rids(paragraph):
    rids = []
    for blip in paragraph._p.iter(qn("a:blip")):
        rid = blip.get(qn("r:embed"))
        if rid:
            rids.append(rid)
    return rids

wanted = []
for i, p in enumerate(d.paragraphs):
    t = p.text.strip()
    if t.startswith("Figure B") or "B26" in t or "B27" in t or "PM-0" in t or "PM-1" in t:
        rids = para_image_rids(p)
        # also next few paras
        extra = []
        for j in range(i, min(i+4, len(d.paragraphs))):
            extra.extend(para_image_rids(d.paragraphs[j]))
        if rids or t.startswith("Figure B2") or t.startswith("Figure B3"):
            wanted.append((i, t[:120], list(dict.fromkeys(extra))))

# Save unique images used around B26-B37
saved = {}
img_dir_files = []
for i, p in enumerate(d.paragraphs):
    t = p.text.strip()
    if not (t.startswith("Figure B") or t.startswith("Evidence for")):
        continue
    rids = para_image_rids(p)
    # look at this para and previous (image often precedes caption)
    prev_rids = para_image_rids(d.paragraphs[i-1]) if i else []
    allr = list(dict.fromkeys(prev_rids + rids))
    if not allr:
        continue
    for rid in allr:
        if rid in saved:
            continue
        try:
            rel = d.part.rels[rid]
            blob = rel.target_part.blob
            ext = Path(rel.target_ref).suffix or ".png"
            name = f"p{i:04d}_{rid}{ext}"
            path = OUTDIR / name
            path.write_bytes(blob)
            saved[rid] = name
            img_dir_files.append((i, t[:100], name, len(blob)))
        except Exception as e:
            img_dir_files.append((i, t[:100], f"ERR {e}", 0))

lines.append("\n=== SAVED IMAGES NEAR CAPTIONS ===")
for rec in img_dir_files:
    lines.append(str(rec))

# specifically dump B26-B37 neighborhood
lines.append("\n=== B26-B37 NEIGHBORHOOD ===")
for i, p in enumerate(d.paragraphs):
    t = p.text.strip()
    if i < 1020 or i > 1115:
        continue
    rids = para_image_rids(p)
    has_draw = "w:drawing" in p._p.xml
    lines.append(f"[{i}] draw={int(has_draw)} rids={rids} {t[:160]}")

LOG.write_text("\n".join(lines), encoding="utf-8")
print("saved", len(saved), "images to", OUTDIR)
print("log", LOG)
