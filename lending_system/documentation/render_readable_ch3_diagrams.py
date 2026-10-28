"""Portrait Chapter 3 DFD and ERD.

The manuscript page is A4 portrait. The text column is about 5.7 inches wide.
These drawings are composed at that width so names print at about 10 to 11 points.
"""

import math
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

DPI = 300
PAGE_W = 5.65

OUT_DIR = Path(__file__).parent
DFD_OUT = OUT_DIR / "frams_dfd_level1.png"
ERD_OUT = OUT_DIR / "frams_erd.png"

NAVY = (31, 78, 121)
ENTITY = (189, 215, 238)
GREEN = (198, 239, 206)
GREEN_EDGE = (0, 102, 0)
STORE = (255, 242, 204)
STORE_EDGE = (191, 143, 0)
INK = (20, 28, 38)
HEADER = (214, 232, 247)
LINE = (31, 78, 121)
WHITE = (255, 255, 255)


def px(inches):
    return int(round(inches * DPI))


def font(size_pt, bold=False):
    name = "segoeuib.ttf" if bold else "segoeui.ttf"
    return ImageFont.truetype(rf"C:\Windows\Fonts\{name}", max(8, int(round(size_pt / 72 * DPI))))


F_NAME = font(10, True)
F_PROC = font(11, True)
F_SUB = font(8.5)
F_FLOW = font(8, True)
F_STORE = font(9.5, True)
F_ATTR = font(8.5)
F_TITLE = font(9, True)
F_NOTE = font(8)
F_CARD = font(8, True)


def size_of(draw, text, fnt):
    box = draw.textbbox((0, 0), text, font=fnt)
    return box[2] - box[0], box[3] - box[1]


def fit_font(draw, text, max_in, start_pt, bold, floor=7.5):
    pt = start_pt
    while pt > floor:
        fnt = font(pt, bold)
        if size_of(draw, text, fnt)[0] <= px(max_in):
            return fnt
        pt -= 0.5
    return font(floor, bold)


def centered(draw, cx, cy, text, fnt, fill=INK):
    tw, th = size_of(draw, text, fnt)
    draw.text((px(cx) - tw / 2, px(cy) - th / 2), text, font=fnt, fill=fill)


def gap_label(draw, cx, cy, text, max_w):
    fnt = fit_font(draw, text, max_w - 0.04, 8, True, floor=7)
    tw, th = size_of(draw, text, fnt)
    x = px(cx) - tw / 2
    y = px(cy) - th / 2
    pad = px(0.015)
    draw.rectangle((x - pad, y - pad, x + tw + pad, y + th + pad), fill=WHITE)
    draw.text((x, y), text, font=fnt, fill=INK)


def arrow(draw, x1, y1, x2, y2):
    width = max(2, px(0.012))
    head = px(0.08)
    ang = math.atan2(y2 - y1, x2 - x1)
    end_x = x2 - math.cos(ang) * head * 0.45
    end_y = y2 - math.sin(ang) * head * 0.45
    draw.line((x1, y1, end_x, end_y), fill=LINE, width=width)
    spread = 0.45
    p2 = (x2 - head * math.cos(ang - spread), y2 - head * math.sin(ang - spread))
    p3 = (x2 - head * math.cos(ang + spread), y2 - head * math.sin(ang + spread))
    draw.polygon([(x2, y2), p2, p3], fill=LINE)


def hline(draw, x1, x2, y):
    arrow(draw, px(x1), px(y), px(x2), px(y))


def render_dfd():
    # Actors on the left, process on the right, stores full width underneath.
    ent_x, ent_w, ent_h = 0.06, 1.55, 0.32
    proc_x, proc_w, proc_h = 3.05, 2.48, 0.62
    store_x, store_w, store_h = 0.06, 5.52, 0.28
    left_gap = proc_x - (ent_x + ent_w)

    bands = [
        ("1.0", ["Register and", "approve borrower"],
         [("Borrower", "Registration", True), ("Administrator", "Approve or reject", True)],
         ["D1 Users", "D2 Borrower applications", "D3 Clients"], None),
        ("2.0", ["Apply and estimate", "eligibility"],
         [("Borrower", "Application", True), ("Borrower", "Estimate", False)],
         ["D4 Loan applications", "D5 Documents"], None),
        ("3.0", ["Agree and release", "the loan"],
         [("Administrator", "Review", True), ("Borrower", "Accept or decline", True)],
         ["D6 Agreements", "D7 Releases and loans", "D8 Installment schedule"], None),
        ("4.0", ["Record payment", "and penalty"],
         [("Collector", "Payment", True), ("Collector", "Receipt", False)],
         ["D9 Payments", "D7 Loans"], "Reads loan balance"),
        ("5.0", ["Prepare reports and", "notifications"],
         [("Administrator", "Period", True), ("Administrator", "Report", False)],
         ["D10 Notifications", "D11 Expenses"], "Reads loans and payments"),
    ]

    y = 0.06
    laid = []
    for number, plines, actors, stores, back in bands:
        actor_h = len(actors) * ent_h + (len(actors) - 1) * 0.05
        top = y
        mid = y + max(actor_h, proc_h) / 2
        store_top = y + max(actor_h, proc_h) + 0.08
        store_span = len(stores) * store_h + (len(stores) - 1) * 0.04
        laid.append((top, mid, store_top, number, plines, actors, stores, back))
        y = store_top + store_span + 0.10

    legend_y = y + 0.02
    page_h = legend_y + 0.62
    img = Image.new("RGB", (px(PAGE_W), px(page_h)), WHITE)
    d = ImageDraw.Draw(img)

    for top, mid, store_top, number, plines, actors, stores, back in laid:
        proc_y = mid - proc_h / 2
        d.rounded_rectangle(
            (px(proc_x), px(proc_y), px(proc_x + proc_w), px(proc_y + proc_h)),
            radius=px(proc_h / 2), fill=GREEN, outline=GREEN_EDGE, width=max(2, px(0.015)),
        )
        lines = [number] + plines
        fonts = [F_PROC, F_SUB, F_SUB]
        heights = [size_of(d, t, f)[1] for t, f in zip(lines, fonts)]
        total = sum(heights) + px(0.012) * (len(lines) - 1)
        ty = px(mid) - total / 2
        for t, f, th in zip(lines, fonts, heights):
            tw = size_of(d, t, f)[0]
            d.text((px(proc_x + proc_w / 2) - tw / 2, ty), t, font=f, fill=INK)
            ty += th + px(0.012)

        for i, (name, label, inbound) in enumerate(actors):
            ay = top + i * (ent_h + 0.05)
            d.rounded_rectangle(
                (px(ent_x), px(ay), px(ent_x + ent_w), px(ay + ent_h)),
                radius=px(0.04), fill=ENTITY, outline=NAVY, width=max(2, px(0.012)),
            )
            centered(d, ent_x + ent_w / 2, ay + ent_h / 2, name, F_NAME)
            ly = ay + ent_h / 2
            if inbound:
                hline(d, ent_x + ent_w + 0.02, proc_x - 0.02, ly)
            else:
                hline(d, proc_x - 0.02, ent_x + ent_w + 0.02, ly)
            gap_label(d, ent_x + ent_w + left_gap / 2, ly - 0.10, label, left_gap)

        for i, name in enumerate(stores):
            sy = store_top + i * (store_h + 0.04)
            d.rectangle(
                (px(store_x), px(sy), px(store_x + store_w), px(sy + store_h)),
                fill=STORE, outline=STORE_EDGE, width=max(2, px(0.012)),
            )
            d.line((px(store_x + 0.18), px(sy), px(store_x + 0.18), px(sy + store_h)), fill=STORE_EDGE, width=max(2, px(0.012)))
            fnt = fit_font(d, name, store_w - 0.32, 9.5, True, floor=8)
            tw, th = size_of(d, name, fnt)
            d.text((px(store_x + 0.26), px(sy + store_h / 2) - th / 2), name, font=fnt, fill=INK)
            if i == 0:
                arrow(d, px(proc_x + proc_w / 2), px(proc_y + proc_h), px(proc_x + proc_w / 2), px(sy))
            if back and i == len(stores) - 1:
                gap_label(d, store_x + 3.15, sy + store_h / 2, back, 1.7)

    d.rectangle((px(0.08), px(legend_y), px(0.32), px(legend_y + 0.16)), fill=ENTITY, outline=NAVY, width=2)
    d.text((px(0.38), px(legend_y)), "External entity", font=F_NOTE, fill=INK)
    d.rounded_rectangle((px(1.85), px(legend_y), px(2.22), px(legend_y + 0.16)), radius=px(0.08), fill=GREEN, outline=GREEN_EDGE, width=2)
    d.text((px(2.28), px(legend_y)), "Process", font=F_NOTE, fill=INK)
    d.rectangle((px(3.15), px(legend_y), px(3.48), px(legend_y + 0.16)), fill=STORE, outline=STORE_EDGE, width=2)
    d.text((px(3.54), px(legend_y)), "Data store", font=F_NOTE, fill=INK)
    note = "A store drawn more than once is the same file. D7 holds the release and the loan. The collector records a payment only for an assigned released loan."
    d.text((px(0.08), px(legend_y + 0.24)), note, font=F_NOTE, fill=INK)
    # wrap note if needed
    if size_of(d, note, F_NOTE)[0] > px(PAGE_W - 0.16):
        d.rectangle((px(0.06), px(legend_y + 0.22), px(PAGE_W), px(page_h)), fill=WHITE)
        d.text((px(0.08), px(legend_y + 0.22)), "A store drawn more than once is the same file. D7 holds the release and the loan.", font=F_NOTE, fill=INK)
        d.text((px(0.08), px(legend_y + 0.38)), "The collector records a payment only for an assigned released loan.", font=F_NOTE, fill=INK)

    img.save(DFD_OUT, "PNG")
    print("wrote", DFD_OUT, img.size, "inches", PAGE_W, round(page_h, 2))


def box_geom(x, y, w, h):
    return {
        "x": x, "y": y, "w": w, "h": h,
        "cx": x + w / 2, "cy": y + h / 2,
        "n": (x + w / 2, y), "s": (x + w / 2, y + h),
        "west": (x, y + h / 2), "east": (x + w, y + h / 2),
        "sw": (x + 0.28, y + h), "se": (x + w - 0.28, y + h),
    }


def draw_box(draw, g, title, lines):
    x, y, w, h = g["x"], g["y"], g["w"], g["h"]
    draw.rectangle((px(x), px(y), px(x + w), px(y + h)), fill=WHITE, outline=NAVY, width=max(2, px(0.012)))
    head = 0.20
    draw.rectangle((px(x), px(y), px(x + w), px(y + head)), fill=HEADER, outline=NAVY, width=max(2, px(0.012)))
    fnt = fit_font(draw, title, w - 0.08, 9.5, True, floor=8)
    centered(draw, g["cx"], y + head / 2, title, fnt)
    ty = y + head + 0.03
    for line in lines:
        af = fit_font(draw, line, w - 0.10, 8, False, floor=7)
        draw.text((px(x + 0.05), px(ty)), line, font=af, fill=INK)
        ty += 0.12


def poly(draw, points):
    width = max(2, px(0.01))
    pts = [(px(p[0]), px(p[1])) for p in points]
    draw.line(pts, fill=LINE, width=width)


def render_erd():
    bw, bh = 2.50, 0.48
    gx, gy = 0.46, 0.24
    x0, y0 = 0.08, 0.05

    def origin(col, row):
        return x0 + col * (bw + gx), y0 + row * (bh + gy)

    specs = [
        ("password_resets", 0, 0, ["PK id, FK user_id", "token hash, expires_at"]),
        ("users", 1, 0, ["PK user_id, role, status", "username, email, password hash"]),
        ("borrower_applications", 0, 1, ["PK application_id, FK user_id", "approval status"]),
        ("clients", 1, 1, ["PK client_id, FK user_id", "FK collector_id, identity, contact"]),
        ("notifications", 0, 2, ["PK notification_id, FK user_id", "message, read state"]),
        ("email_notifications", 1, 9, ["PK id, recipient, type", "status, sent_at"]),
        ("loan_application_documents", 0, 3, ["PK document_id, FK loan_app_id", "file, verification status"]),
        ("loan_applications", 1, 3, ["PK loan_app_id, FK user_id", "income, terms, eligibility"]),
        ("loan_application_reviews", 0, 4, ["PK review_id, FK loan_app_id", "decision, created_by"]),
        ("loan_agreements", 1, 4, ["PK agreement_id, FK loan_app_id", "borrower and lender sign"]),
        ("loan_payment_schedules", 0, 5, ["PK schedule_id, FK release_id", "due date, principal, interest, status"]),
        ("loan_releases", 1, 5, ["PK release_id, FK loan_app_id", "loan number, collector"]),
        ("loans", 0, 6, ["PK loan_id, FK client_id", "FK release_id, payable, frequency"]),
        ("payments", 1, 6, ["PK payment_id, FK loan_id", "amount, penalty, receipt, method"]),
        ("paymongo_qrph_transactions", 0, 7, ["PK id, FK loan_id, FK payment_id", "intent, amount, QR status, expires"]),
        ("paymongo_qrph_webhook_events", 1, 7, ["PK id, FK transaction_id", "event id, type, status"]),
        ("contact_inquiries", 0, 8, ["PK id, sender, email, status", "public website form"]),
        ("contact_inquiry_replies", 1, 8, ["PK id, FK inquiry_id", "FK admin_user_id, reply"]),
        ("expenses", 0, 9, ["PK expense_id", "amount, date, category"]),
    ]
    geom = {}
    for name, col, row, _lines in specs:
        x, y = origin(col, row)
        geom[name] = box_geom(x, y, bw, bh)
    g = geom

    def gap_y(upper, lower):
        return (g[upper]["s"][1] + g[lower]["n"][1]) / 2

    def mid_x(left, right):
        return (g[left]["east"][0] + g[right]["west"][0]) / 2

    routes = []

    def add(points, label, at):
        routes.append((points, label, at))

    add([g["password_resets"]["east"], g["users"]["west"]], "1 to M", (mid_x("password_resets", "users"), g["users"]["cy"]))
    add([g["users"]["s"], g["clients"]["n"]], "1 to M", (g["users"]["cx"] + 0.18, gap_y("users", "clients")))
    y_ub = gap_y("users", "borrower_applications")
    add([
        g["users"]["sw"], (g["users"]["sw"][0], y_ub),
        (g["borrower_applications"]["cx"], y_ub), g["borrower_applications"]["n"],
    ], "1 to M", ((g["users"]["sw"][0] + g["borrower_applications"]["cx"]) / 2, y_ub))
    add([g["clients"]["s"], g["loan_applications"]["n"]], "1 to M", (g["clients"]["cx"] + 0.18, g["notifications"]["cy"]))
    add([g["loan_application_documents"]["east"], g["loan_applications"]["west"]], "1 to M", (mid_x("loan_application_documents", "loan_applications"), g["loan_applications"]["cy"]))
    add([g["loan_applications"]["s"], g["loan_agreements"]["n"]], "1 to 1", (g["loan_applications"]["cx"] + 0.18, gap_y("loan_applications", "loan_agreements")))
    add([g["loan_application_reviews"]["east"], g["loan_agreements"]["west"]], "1 to M", (mid_x("loan_application_reviews", "loan_agreements"), g["loan_agreements"]["cy"]))
    add([g["loan_agreements"]["s"], g["loan_releases"]["n"]], "1 to 1", (g["loan_agreements"]["cx"] + 0.18, gap_y("loan_agreements", "loan_releases")))
    add([g["loan_releases"]["west"], g["loan_payment_schedules"]["east"]], "1 to M", (mid_x("loan_payment_schedules", "loan_releases"), g["loan_releases"]["cy"]))
    y_rl = gap_y("loan_releases", "loans")
    add([
        g["loan_releases"]["sw"], (g["loan_releases"]["sw"][0], y_rl),
        (g["loans"]["cx"], y_rl), g["loans"]["n"],
    ], "1 to 1", ((g["loan_releases"]["sw"][0] + g["loans"]["cx"]) / 2, y_rl))
    add([g["loans"]["east"], g["payments"]["west"]], "1 to M", (mid_x("loans", "payments"), g["loans"]["cy"]))
    y_qp = gap_y("payments", "paymongo_qrph_transactions")
    add([
        g["payments"]["sw"], (g["payments"]["sw"][0], y_qp),
        (g["paymongo_qrph_transactions"]["cx"], y_qp), g["paymongo_qrph_transactions"]["n"],
    ], "1 to M", ((g["payments"]["sw"][0] + g["paymongo_qrph_transactions"]["cx"]) / 2, y_qp))
    add([g["paymongo_qrph_transactions"]["east"], g["paymongo_qrph_webhook_events"]["west"]], "1 to M", (mid_x("paymongo_qrph_transactions", "paymongo_qrph_webhook_events"), g["paymongo_qrph_transactions"]["cy"]))
    add([g["contact_inquiries"]["east"], g["contact_inquiry_replies"]["west"]], "1 to M", (mid_x("contact_inquiries", "contact_inquiry_replies"), g["contact_inquiries"]["cy"]))

    last = g["expenses"]
    page_h = last["y"] + last["h"] + 0.48
    img = Image.new("RGB", (px(PAGE_W), px(page_h)), WHITE)
    d = ImageDraw.Draw(img)
    for points, _label, _at in routes:
        poly(d, points)
    for name, _c, _r, lines in specs:
        draw_box(d, g[name], name, lines)
    for _points, label, (lx, ly) in routes:
        gap_label(d, lx, ly, label, 0.44)
    d.text((px(0.08), px(page_h - 0.40)), "Collector assignment is the collector_id on clients.", font=F_NOTE, fill=INK)
    d.text((px(0.08), px(page_h - 0.24)), "PayMongo QR Ph posts a payment only after a verified webhook.", font=F_NOTE, fill=INK)
    d.text((px(0.08), px(page_h - 0.10)), "Expenses and email notifications have no required link to a loan.", font=F_NOTE, fill=INK)
    img.save(ERD_OUT, "PNG")
    print("wrote", ERD_OUT, img.size, "inches", PAGE_W, round(page_h, 2))


if __name__ == "__main__":
    render_dfd()
    render_erd()
