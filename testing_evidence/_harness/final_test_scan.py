import zipfile
from xml.etree import ElementTree as ET

NS = "{http://schemas.openxmlformats.org/wordprocessingml/2006/main}"
path = r"C:\Users\HomePC\Downloads\FRAMS_REVISED_DRAFT_UNIT_INTEGRATION_SYSTEM_PASSED.docx"
root = ET.fromstring(zipfile.ZipFile(path).read("word/document.xml"))
lines = []
for para in root.iter(NS + "p"):
    line = "".join((t.text or "") for t in para.iter(NS + "t")).strip()
    low = line.lower()
    if any(token in low for token in ("not yet tested", "pass/verify", "record the observed", "24*", "0 failed", "failed")):
        if line in ("Failed", "Not yet tested"):
            lines.append("HEADER " + line)
        else:
            lines.append(line[:400])
open(r"C:\xampp\htdocs\lending_system\testing_evidence\_harness\final_test_scan.txt", "w", encoding="utf-8").write("\n---\n".join(lines))
print("lines", len(lines))
