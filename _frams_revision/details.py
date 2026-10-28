import docx
from docx.oxml.ns import qn

p = r"C:\Users\HomePC\Downloads\FRAMS_3RDREVISIONS.docx"
d = docx.Document(p)
out = r"C:\xampp\htdocs\lending_system\_frams_revision\details.txt"

def runs_info(i):
    para = d.paragraphs[i]
    lines = [f"PARA {i} text={para.text[:200]!r}"]
    for ri, r in enumerate(para.runs):
        xml = r._r.xml
        kind = "draw" if "w:drawing" in xml else "text"
        lines.append(f"  run {ri} {kind} {r.text[:80]!r}")
    return "\n".join(lines)

with open(out, "w", encoding="utf-8") as f:
    for i in [81, 176, 177, 239, 286, 350, 680, 695, 696]:
        f.write(runs_info(i) + "\n\n")
    f.write("=== RECS ===\n")
    for i in range(846, 865):
        f.write(f"[{i}] {d.paragraphs[i].text}\n")
    f.write("\n=== REFS NOTES ===\n")
    for i in range(865, 880):
        f.write(f"[{i}] {d.paragraphs[i].text[:300]}\n")
    f.write("\n=== TABLE 0 ===\n")
    t = d.tables[0]
    for ri, row in enumerate(t.rows):
        cells = [c.text.replace("\n", " | ")[:180] for c in row.cells]
        f.write(f"R{ri}: {cells}\n")
    f.write("\n=== PM12 1098-1106 ===\n")
    for i in range(1091, 1108):
        f.write(f"[{i}] {d.paragraphs[i].text[:400]}\n")
    f.write("\n=== APP F 1211-1238 ===\n")
    for i in range(1211, 1239):
        f.write(f"[{i}] {d.paragraphs[i].text[:500]}\n")
    f.write("\n=== T19 HEADER ===\n")
    t = d.tables[19]
    f.write(" | ".join(c.text.replace('\n',' ') for c in t.rows[0].cells) + "\n")
    f.write("\n=== EDITORIAL SCAN ===\n")
    keys = ["manuscript should", "A.1", "A.2", "editorial", "before final", "Chapter 5 can", "not yet", "TODO", "FIXME", "researchers should verify"]
    for i, para in enumerate(d.paragraphs):
        tl = para.text.lower()
        if any(k.lower() in tl for k in keys):
            f.write(f"[{i}] {para.text[:240]}\n")

print("ok")
