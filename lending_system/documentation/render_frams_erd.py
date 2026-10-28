"""Render FRAMS ERD PNG in the original box-and-line layout."""

from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

W, H = 2480, 1580
BG = (255, 255, 255)
HEADER = (214, 232, 247)
BORDER = (44, 62, 80)
TEXT = (33, 37, 41)
MUTED = (90, 98, 110)
LINE = (90, 98, 110)

OUT = Path(__file__).with_name("frams_erd.png")


def font(size, bold=False):
    candidates = ["segoeuib.ttf", "segoeui.ttf"] if bold else ["segoeui.ttf", "arial.ttf", "calibri.ttf"]
    for name in candidates:
        try:
            return ImageFont.truetype(name, size)
        except OSError:
            continue
    return ImageFont.load_default()


F_TITLE = font(17, True)
F_BODY = font(14)
F_CARD = font(13, True)
F_NOTE = font(15)


def box(draw, x, y, w, h, title, lines):
    r = 10
    draw.rounded_rectangle((x, y, x + w, y + h), radius=r, fill="white", outline=BORDER, width=2)
    header_h = 34
    draw.rounded_rectangle((x, y, x + w, y + header_h + 8), radius=r, fill=HEADER, outline=BORDER, width=2)
    draw.rectangle((x + 2, y + header_h - 4, x + w - 2, y + header_h + 8), fill=HEADER)
    draw.line((x, y + header_h + 8, x + w, y + header_h + 8), fill=BORDER, width=2)
    tw = draw.textlength(title, font=F_TITLE)
    draw.text((x + (w - tw) / 2, y + 8), title, fill=TEXT, font=F_TITLE)
    ty = y + header_h + 16
    for line in lines:
        draw.text((x + 14, ty), line, fill=TEXT, font=F_BODY)
        ty += 20
    return {"x": x, "y": y, "w": w, "h": h}


def side(b, which):
    x, y, w, h = b["x"], b["y"], b["w"], b["h"]
    cx, cy = x + w / 2, y + h / 2
    return {
        "n": (cx, y),
        "s": (cx, y + h),
        "w": (x, cy),
        "e": (x + w, cy),
        "nw": (x + 40, y),
        "ne": (x + w - 40, y),
        "sw": (x + 40, y + h),
        "se": (x + w - 40, y + h),
        "wn": (x, y + 28),
        "ws": (x, y + h - 28),
        "en": (x + w, y + 28),
        "es": (x + w, y + h - 28),
    }[which]


def polyline(draw, pts):
    for i in range(len(pts) - 1):
        draw.line((pts[i][0], pts[i][1], pts[i + 1][0], pts[i + 1][1]), fill=LINE, width=2)


def label_at(draw, xy, text, dx=0, dy=-14):
    x, y = xy[0] + dx, xy[1] + dy
    tw = draw.textlength(text, font=F_CARD)
    draw.rectangle((x - 3, y - 1, x + tw + 3, y + 16), fill="white")
    draw.text((x, y), text, fill=MUTED, font=F_CARD)


def mid(a, b):
    return ((a[0] + b[0]) / 2, (a[1] + b[1]) / 2)


def main():
    img = Image.new("RGB", (W, H), BG)
    d = ImageDraw.Draw(img)

    users = box(d, 1020, 36, 300, 118, "users", [
        "PK user_id",
        "username, email, role",
        "password hash, status",
    ])
    pw = box(d, 40, 36, 260, 118, "password_resets", [
        "PK id",
        "FK user_id",
        "token hash, expires_at",
    ])
    ba = box(d, 360, 200, 280, 118, "borrower_applications", [
        "PK application_id",
        "FK user_id",
        "approval status",
    ])
    clients = box(d, 1005, 230, 330, 118, "clients", [
        "PK client_id",
        "FK user_id, FK collector_id",
        "identity, contact",
    ])
    notes = box(d, 1580, 36, 260, 118, "notifications", [
        "PK notification_id",
        "FK user_id",
        "message, read",
    ])
    email = box(d, 1980, 36, 260, 118, "email_notifications", [
        "PK id",
        "recipient, type",
        "status, sent_at",
    ])
    inq = box(d, 1580, 200, 260, 118, "contact_inquiries", [
        "PK id",
        "sender, email, status",
        "(public website form)",
    ])
    replies = box(d, 1980, 190, 280, 138, "contact_inquiry_replies", [
        "PK id",
        "FK inquiry_id",
        "FK admin_user_id, reply",
    ])

    docs = box(d, 40, 430, 300, 138, "loan_application_documents", [
        "PK document_id",
        "FK loan_app_id",
        "file, verification status",
    ])
    reviews = box(d, 40, 610, 300, 118, "loan_application_reviews", [
        "PK review_id",
        "FK loan_app_id",
        "decision, created_by",
    ])
    apps = box(d, 460, 450, 330, 158, "loan_applications", [
        "PK loan_app_id",
        "FK user_id",
        "income, terms",
        "eligibility, assessment",
    ])
    agr = box(d, 1005, 460, 320, 138, "loan_agreements", [
        "PK agreement_id",
        "FK loan_app_id",
        "borrower / lender sign",
    ])
    rel = box(d, 1480, 450, 310, 138, "loan_releases", [
        "PK release_id",
        "FK loan_app_id",
        "loan number, collector",
    ])

    sched = box(d, 40, 920, 300, 158, "loan_payment_schedules", [
        "PK schedule_id",
        "FK release_id",
        "due date, principal",
        "interest, status",
    ])
    loans = box(d, 480, 920, 330, 158, "loans", [
        "PK loan_id",
        "FK client_id, FK release_id",
        "total payable, frequency",
        "status",
    ])
    pays = box(d, 980, 920, 300, 158, "payments", [
        "PK payment_id",
        "FK loan_id",
        "amount, penalty, receipt",
        "payment_method",
    ])
    qr = box(d, 1420, 900, 340, 178, "paymongo_qrph_transactions", [
        "PK id",
        "FK loan_id, FK payment_id",
        "intent id, amount, currency",
        "QR status, expires_at",
    ])
    hook = box(d, 1900, 900, 330, 178, "paymongo_qrph_webhook_events", [
        "PK id",
        "FK transaction_id",
        "event_id, event_type",
        "processing status",
    ])
    exp = box(d, 1980, 1180, 250, 118, "expenses", [
        "PK expense_id",
        "amount, date",
        "category",
    ])

    # users -- password_resets
    p1, p2 = side(pw, "e"), side(users, "w")
    polyline(d, [p1, p2])
    label_at(d, mid(p1, p2), "1 to M", dy=-18)

    # users -- borrower_applications
    a, b_ = side(users, "ws"), side(ba, "n")
    polyline(d, [a, (a[0], b_[1] - 24), (b_[0], b_[1] - 24), b_])
    label_at(d, (a[0] - 70, (a[1] + b_[1]) / 2), "1 to M")

    # users -- clients
    a, b_ = side(users, "s"), side(clients, "n")
    polyline(d, [a, b_])
    label_at(d, mid(a, b_), "1 to M", dx=8)

    # users -- notifications
    a, b_ = side(users, "e"), side(notes, "w")
    polyline(d, [a, b_])
    label_at(d, mid(a, b_), "1 to M", dy=-18)

    # contact inquiries -- replies
    a, b_ = side(inq, "e"), side(replies, "w")
    polyline(d, [a, b_])
    label_at(d, mid(a, b_), "1 to M", dy=-18)

    # clients -- loan_applications
    a, b_ = side(clients, "ws"), side(apps, "n")
    polyline(d, [a, (a[0], b_[1] - 22), (b_[0], b_[1] - 22), b_])
    label_at(d, ((a[0] + b_[0]) / 2 - 30, b_[1] - 40), "1 to M")

    # documents -- applications
    a, b_ = side(docs, "e"), side(apps, "w")
    polyline(d, [a, b_])
    label_at(d, mid(a, b_), "1 to M", dy=-18)

    # reviews -- applications
    a, b_ = side(reviews, "e"), side(apps, "ws")
    polyline(d, [a, (a[0] + 36, a[1]), (a[0] + 36, b_[1]), b_])
    label_at(d, (a[0] + 8, a[1] - 18), "1 to M")

    # applications -- agreements -- releases
    a, b_ = side(apps, "e"), side(agr, "w")
    polyline(d, [a, b_])
    label_at(d, mid(a, b_), "1 to 1", dy=-18)
    a, b_ = side(agr, "e"), side(rel, "w")
    polyline(d, [a, b_])
    label_at(d, mid(a, b_), "1 to 1", dy=-18)

    # releases -- schedules (stay below reviews, above the loan row)
    a, b_ = side(rel, "s"), side(sched, "e")
    y_sched = 840
    polyline(d, [a, (a[0], y_sched), (b_[0] + 24, y_sched), (b_[0] + 24, b_[1]), b_])
    label_at(d, (380, y_sched - 18), "1 to M")

    # releases -- loans
    a, b_ = side(rel, "se"), side(loans, "ne")
    y_rel_loans = 870
    polyline(d, [a, (a[0], y_rel_loans), (b_[0], y_rel_loans), b_])
    label_at(d, ((a[0] + b_[0]) / 2 - 16, y_rel_loans - 18), "1 to 1")

    # clients -- loans
    a, b_ = side(clients, "s"), side(loans, "n")
    polyline(d, [a, (a[0], a[1] + 40), (b_[0], a[1] + 40), (b_[0], b_[1])])
    label_at(d, ((a[0] + b_[0]) / 2 - 20, a[1] + 22), "1 to M")

    # loans -- payments
    a, b_ = side(loans, "e"), side(pays, "w")
    polyline(d, [a, b_])
    label_at(d, mid(a, b_), "1 to M", dy=-18)

    # loans -- paymongo (under payments)
    a, b_ = side(loans, "se"), side(qr, "sw")
    polyline(d, [a, (a[0], a[1] + 28), (b_[0], a[1] + 28), b_])
    label_at(d, ((a[0] + b_[0]) / 2 - 20, a[1] + 10), "1 to M")

    # payments -- paymongo 1 to 1
    a, b_ = side(pays, "e"), side(qr, "w")
    polyline(d, [a, b_])
    label_at(d, mid(a, b_), "1 to 1", dy=-18)

    # paymongo -- webhook
    a, b_ = side(qr, "e"), side(hook, "w")
    polyline(d, [a, b_])
    label_at(d, mid(a, b_), "1 to M", dy=-18)

    note = (
        "Collector assignment is the collector_id on clients. "
        "PayMongo QR Ph posts a payment only after a verified webhook. "
        "Expenses and email_notifications are used by reports and mail, with no required FK to loans."
    )
    d.text((40, 1528), note, fill=MUTED, font=F_NOTE)
    img.save(OUT, "PNG")
    print(f"wrote {OUT}")


if __name__ == "__main__":
    main()
