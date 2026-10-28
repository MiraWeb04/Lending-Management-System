import zipfile
from xml.etree import ElementTree as ET

NS = "{http://schemas.openxmlformats.org/wordprocessingml/2006/main}"
path = r"C:\Users\HomePC\Downloads\FRAMS_REVISED_DRAFT_UNIT_INTEGRATION_SYSTEM_PASSED.docx"
root = ET.fromstring(zipfile.ZipFile(path).read("word/document.xml"))
parent = {child: node for node in root.iter() for child in list(node)}
for para in root.iter(NS + "p"):
    line = "".join((t.text or "") for t in para.iter(NS + "t"))
    if "Figure B10Evidence" in line or "Figure B11Evidence" in line or "still require" in line or "not yet tested" in line.lower():
        chain = []
        node = para
        while node in parent:
            node = parent[node]
            chain.append(node.tag.split("}")[-1])
            if len(chain) > 8:
                break
        print(line[:180])
        print("  ancestors:", " > ".join(chain))
        print("  pieces:", [(t.text or "")[:50] for t in para.iter(NS + "t")])
        print()
