import re
import zipfile
from pathlib import Path
from xml.etree import ElementTree as ET

NS = {"w": "http://schemas.openxmlformats.org/wordprocessingml/2006/main"}
ROOT = Path(r"C:\xampp\htdocs\lending_system")
OUT = ROOT / "testing" / "evidence" / "research_extracts"
OUT.mkdir(parents=True, exist_ok=True)

FILES = [
    ROOT / "lending_system" / "documentation" / "FRAMS_Chapters_1_to_5_UAT_updated.docx",
    ROOT / "lending_system" / "documentation" / "Chapter_4_System_Implementation_and_Results.docx",
    ROOT / "lending_system" / "documentation" / "Test_Cases_PayMongo_Penetration_and_System_Testing.docx",
    ROOT / "lending_system" / "documentation" / "FRAMS_References_Questionnaires_and_Forms.docx",
    ROOT / "lending_system" / "documentation" / "Capstone20G4_Chapters_1_to_3_Revised.docx",
]


def paragraphs(path: Path) -> list[str]:
    with zipfile.ZipFile(path) as zf:
        xml = zf.read("word/document.xml")
    root = ET.fromstring(xml)
    lines = []
    for p in root.findall(".//w:p", NS):
        texts = [t.text or "" for t in p.findall(".//w:t", NS)]
        line = "".join(texts).strip()
        if line:
            lines.append(line)
    return lines


for path in FILES:
    if not path.exists():
        print("missing", path.name)
        continue
    lines = paragraphs(path)
    text = "\n".join(lines)
    target = OUT / (path.stem + ".txt")
    target.write_text(text, encoding="utf-8")
    hits = []
    for i, line in enumerate(lines):
        if re.search(r"4\.81|4\.78|4\.75|Functionality|questionnaire|respondent|Likert|Unit Testing|test case", line, re.I):
            hits.append(f"{i}: {line[:240]}")
    print(path.name, "paragraphs", len(lines), "hits", len(hits))
    (OUT / (path.stem + ".hits.txt")).write_text("\n".join(hits), encoding="utf-8")
