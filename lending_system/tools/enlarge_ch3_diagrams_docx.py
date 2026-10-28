# -*- coding: utf-8 -*-
"""Put readable Chapter 3 DFD and ERD figures on landscape pages."""
import shutil
from copy import deepcopy
from pathlib import Path

from docx import Document
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from lxml import etree

SRC = Path(r"C:\Users\HomePC\Downloads\FRAMS_FINALDOCU.docx")
BACKUP = Path(r"C:\Users\HomePC\Downloads\FRAMS_FINALDOCU_before_readable_diagrams.docx")
OUT = Path(r"C:\Users\HomePC\Downloads\FRAMS_FINALDOCU_readable_diagrams.docx")
DFD = Path(r"C:\xampp\htdocs\lending_system\lending_system\documentation\frams_dfd_level1.png")
ERD = Path(r"C:\xampp\htdocs\lending_system\lending_system\documentation\frams_erd.png")

EMU = 914400
# PNG layout is 9.05 x 5.75 in. Shown a little larger, still inside the landscape page.
# Leave a little room under the caption so the section break does not create a blank page.
IMG_W_IN = 8.90
IMG_H_IN = 5.75 * (IMG_W_IN / 9.05)
A = "{http://schemas.openxmlformats.org/drawingml/2006/main}"
W = "{http://schemas.openxmlformats.org/wordprocessingml/2006/main}"


def drawing_run(paragraph):
    for child in paragraph._p:
        if child.tag == f"{W}r" and child.find(f".//{W}drawing") is not None:
            return child
    return None


def make_inline(run):
    """Drop the old floating position so the figure cannot hang off the page."""
    drawing = run.find(f".//{W}drawing")
    anchor = drawing.find(qn("wp:anchor"))
    if anchor is None:
        return
    inline = OxmlElement("wp:inline")
    for edge in ("distT", "distB", "distL", "distR"):
        inline.set(edge, "0")
    for tag in ("wp:extent", "wp:effectExtent", "wp:docPr", "wp:cNvGraphicFramePr"):
        el = anchor.find(qn(tag))
        if el is not None:
            inline.append(el)
    graphic = anchor.find(A + "graphic")
    if graphic is not None:
        inline.append(graphic)
    anchor.getparent().replace(anchor, inline)


def clear_crop(run):
    drawing = run.find(f".//{W}drawing")
    for node in list(drawing.findall(".//" + A + "srcRect")):
        node.getparent().remove(node)


def set_extent(run):
    cx = str(int(IMG_W_IN * EMU))
    cy = str(int(IMG_H_IN * EMU))
    drawing = run.find(f".//{W}drawing")
    for ext in drawing.findall(".//" + qn("wp:extent")):
        ext.set("cx", cx)
        ext.set("cy", cy)
    for ext in drawing.findall(".//" + A + "ext"):
        if ext.getparent().tag.endswith("xfrm"):
            ext.set("cx", cx)
            ext.set("cy", cy)


def replace_blob(doc, run, png_path):
    blip = run.find(".//" + A + "blip")
    rid = blip.get(qn("r:embed"))
    doc.part.related_parts[rid]._blob = png_path.read_bytes()


def image_paragraph(run):
    p = OxmlElement("w:p")
    pPr = OxmlElement("w:pPr")
    jc = OxmlElement("w:jc")
    jc.set(qn("w:val"), "center")
    spacing = OxmlElement("w:spacing")
    spacing.set(qn("w:before"), "60")
    spacing.set(qn("w:after"), "40")
    keep = OxmlElement("w:keepNext")
    pPr.extend([spacing, jc, keep])
    p.append(pPr)
    p.append(run)
    return p


def section_break(paragraph, body_sect, landscape):
    pPr = paragraph._p.get_or_add_pPr()
    for old in list(pPr.findall(qn("w:sectPr"))):
        pPr.remove(old)
    sect = deepcopy(body_sect)
    if sect.get(qn("w:rsidR")) is not None:
        pass
    pg = sect.find(qn("w:pgSz"))
    if landscape:
        pg.set(qn("w:w"), "16838")
        pg.set(qn("w:h"), "11906")
        pg.set(qn("w:orient"), "landscape")
        mar = sect.find(qn("w:pgMar"))
        for edge in ("top", "right", "bottom", "left"):
            mar.set(qn(f"w:{edge}"), "864")
    else:
        if qn("w:orient") in pg.attrib:
            del pg.attrib[qn("w:orient")]
    typ = sect.find(qn("w:type"))
    if typ is None:
        typ = OxmlElement("w:type")
        pg.addprevious(typ)
    typ.set(qn("w:val"), "nextPage")
    pPr.append(sect)


def find_para(doc, snippet):
    hits = [p for p in doc.paragraphs if snippet in p.text]
    if len(hits) != 1:
        raise SystemExit(f"{snippet!r} matched {len(hits)} paragraphs")
    return hits[0]


def main():
    if not BACKUP.exists():
        shutil.copy2(SRC, BACKUP)
    doc = Document(str(SRC))
    body_sect = doc.element.body.find(qn("w:sectPr"))

    dfd_text = find_para(doc, "Figure 2 is the Level 1 data flow diagram")
    dfd_cap = find_para(doc, "Figure 2 Level 1 Data Flow Diagram of FRAMS")
    erd_text = find_para(doc, "Figure 3 is the entity-relationship diagram")
    erd_cap = find_para(doc, "Figure 3 Entity-Relationship Diagram of FRAMS")
    note = find_para(doc, "PADAK-ON")

    dfd_run = drawing_run(dfd_text)
    erd_run = drawing_run(erd_text)
    if dfd_run is None or erd_run is None:
        raise SystemExit("Could not find the DFD or ERD drawing")

    dfd_run.getparent().remove(dfd_run)
    erd_run.getparent().remove(erd_run)
    replace_blob(doc, dfd_run, DFD)
    replace_blob(doc, erd_run, ERD)
    make_inline(dfd_run)
    make_inline(erd_run)
    clear_crop(dfd_run)
    clear_crop(erd_run)
    set_extent(dfd_run)
    set_extent(erd_run)

    # Drop the adviser note and a blank paragraph sitting before the ERD caption.
    previous = note._p.getprevious()
    nxt = note._p.getnext()
    note._p.getparent().remove(note._p)
    if nxt is not None and nxt.tag == f"{W}p" and not "".join(t.text or "" for t in nxt.findall(".//" + qn("w:t"))).strip():
        if nxt.find(".//" + qn("w:drawing")) is None and nxt is not erd_cap._p:
            nxt.getparent().remove(nxt)

    dfd_text._p.addnext(image_paragraph(dfd_run))
    erd_text._p.addnext(image_paragraph(erd_run))

    for cap in (dfd_cap, erd_cap):
        nxt = cap._p.getnext()
        if nxt is not None and nxt.tag == f"{W}p":
            text = "".join(t.text or "" for t in nxt.findall(".//" + qn("w:t"))).strip()
            if not text and nxt.find(".//" + qn("w:drawing")) is None and nxt.find(qn("w:sectPr")) is None:
                nxt.getparent().remove(nxt)

    for cap in (dfd_cap, erd_cap):
        pPr = cap._p.get_or_add_pPr()
        spacing = pPr.find(qn("w:spacing"))
        if spacing is None:
            spacing = OxmlElement("w:spacing")
            pPr.append(spacing)
        spacing.set(qn("w:before"), "40")
        spacing.set(qn("w:after"), "0")
        spacing.set(qn("w:line"), "240")
        spacing.set(qn("w:lineRule"), "auto")

    section_break(dfd_text, body_sect, landscape=False)
    section_break(dfd_cap, body_sect, landscape=True)
    section_break(erd_text, body_sect, landscape=False)
    section_break(erd_cap, body_sect, landscape=True)

    try:
        doc.save(str(SRC))
        saved = SRC
    except PermissionError:
        doc.save(str(OUT))
        saved = OUT
    print("saved", saved)
    print(f"image {IMG_W_IN:.2f} x {IMG_H_IN:.2f} in")


if __name__ == "__main__":
    main()
