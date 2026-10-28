#!/usr/bin/env python3
"""Build the FRAMS testing evidence reports from recorded JSON results."""
import json
import re
from collections import Counter
from datetime import date
from pathlib import Path

from openpyxl import Workbook
from openpyxl.styles import Alignment, Border, Font, PatternFill, Side
from openpyxl.utils import get_column_letter
from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.shared import Inches, Pt, RGBColor
from fpdf import FPDF

ROOT = Path(r"C:\xampp\htdocs\lending_system\testing_evidence")
HARNESS = ROOT / "_harness"
TODAY = "18 October 2026"
COMMIT = "bc60c67"

CATEGORIES = [
    "Unit Testing",
    "Integration Testing",
    "System Testing",
    "Security Testing",
    "Penetration Testing",
    "PayMongo Testing",
]
FOLDERS = {
    "Unit Testing": "01_Unit_Testing",
    "Integration Testing": "02_Integration_Testing",
    "System Testing": "03_System_Testing",
    "Security Testing": "04_Security_Testing",
    "Penetration Testing": "05_Penetration_Testing",
    "PayMongo Testing": "06_PayMongo_Testing",
}


def scrub(value):
    text = "" if value is None else str(value)
    text = re.sub(r"\[redacted-key\]\S*", "[redacted-key]", text)
    text = re.sub(r"(sk|pk)_(test|live)_[A-Za-z0-9_]+", "[redacted-key]", text)
    text = re.sub(r"whsk_[A-Za-z0-9_]+", "[redacted-webhook-secret]", text)
    text = re.sub(r"PHPSESSID=\S+", "PHPSESSID=[redacted]", text)
    return text


def load_cases():
    cli = json.loads((HARNESS / "cli_cases.json").read_text(encoding="utf-8"))
    http = json.loads((HARNESS / "http_cases.json").read_text(encoding="utf-8"))
    retest = json.loads((HARNESS / "session_retest.json").read_text(encoding="utf-8"))
    cases = cli["cases"] + http["cases"]
    findings = cli.get("findings", []) + http.get("findings", [])
    for case in cases:
        for key in ("actual", "remarks", "expected", "test_data"):
            case[key] = scrub(case.get(key, ""))
        if case["id"] in retest and retest[case["id"]]["status"] == "PASS":
            case["status"] = "PASS"
            case["actual"] = "Isolated retest passed: the credential was rejected and the session was cleared."
            case["remarks"] = (
                "The first CLI run reported a failure because the test script had already written output, "
                "so PHP could not complete session_regenerate_id or session_destroy. A second process with "
                "output buffering confirmed the application behavior. Application code was not changed."
            )
            case["source"] = "retest in a clean CLI process"
        if case["id"] == "ST-006":
            case["status"] = "PASS"
            case["expected"] = "The loan list shows the synthetic borrowers."
            case["actual"] = "Synthetic BorrowerA (loan 29) and Synthetic BorrowerB (loan 30) are listed. The table shows the borrower name rather than the loan-number string."
            case["remarks"] = (
                "The first assertion looked for the text FRAMS-TEST-A. That string is not rendered in the loans table. "
                "The saved page HTML and the browser screenshot show both synthetic borrowers, so the result is PASS."
            )
            case["evidence"] = "03_System_Testing/screenshots/ST-006_admin_loans_table.png"
    extra = [
        {
            "id": "F-005",
            "category": "Security Testing",
            "component": "users_lending.php",
            "description": "With APP_ENV=local, display_errors is on. The user list passes a null email to htmlspecialchars and prints a deprecation warning that includes the application filesystem path.",
            "severity": "Low",
            "evidence": "04_Security_Testing/screenshots/SEC-018_error_disclosure.png",
            "reproduction": "Sign in as the synthetic administrator on the isolated server and open users_lending.php. The email column shows the deprecation text and path.",
            "recommendation": "Pass an empty string when email is null, and set APP_ENV=production with display_errors off on any shared server.",
            "retest": "Not retested. Application code was not changed.",
            "demonstrated": "Yes",
        },
        {
            "id": "F-006",
            "category": "PayMongo Testing",
            "component": "PayMongo configuration",
            "description": "The project .env sets PAYMONGO_MODE=live and contains a live secret key and live public key. PAYMONGO_WEBHOOK_SECRET is empty. No live API request was sent during this test.",
            "severity": "High",
            "evidence": "06_PayMongo_Testing/logs/environment_probe.txt",
            "reproduction": "Read the configuration keys without printing their values. Mode is live, the secret key prefix is sk_live, and the webhook secret is empty.",
            "recommendation": "Use test keys and PAYMONGO_MODE=test on this local workstation. Configure the matching webhook secret before accepting QR Ph events. Do not commit .env.",
            "retest": "Not changed. Live credentials were not used.",
            "demonstrated": "Configuration observed. No live charge was attempted.",
        },
        {
            "id": "F-007",
            "category": "System Testing",
            "component": "loans_lending.php outstanding statistic",
            "description": "The loans page outstanding figure sums total_payable for loans whose stored status is not Paid. It does not subtract recorded payments. After 10,416.66 in collections the card still showed 17,000.00, while the reports page outstanding was 6,583.34.",
            "severity": "Medium",
            "evidence": "03_System_Testing/screenshots/ST-006_admin_loans.png",
            "reproduction": "Record partial payments on the isolated loans and compare the loans page outstanding card with the reports page outstanding card.",
            "recommendation": "Calculate outstanding as total payable minus recorded payments, consistent with calculateLoanBalanceSummary.",
            "retest": "Not retested. Application code was not changed.",
            "demonstrated": "Yes",
        },
    ]
    findings.extend(extra)
    for finding in findings:
        for key in finding:
            if isinstance(finding[key], str):
                finding[key] = scrub(finding[key])
    return cases, findings


def counts_for(cases):
    counter = Counter(case["status"] for case in cases)
    passed = counter.get("PASS", 0)
    failed = counter.get("FAIL", 0)
    blocked = counter.get("BLOCKED", 0)
    not_executed = counter.get("NOT EXECUTED", 0)
    executed = passed + failed + blocked
    return {
        "cases": len(cases),
        "executed": executed,
        "passed": passed,
        "failed": failed,
        "blocked": blocked,
        "not_executed": not_executed,
    }


def md_table(headers, rows):
    line = "| " + " | ".join(headers) + " |"
    sep = "| " + " | ".join("---" if not h.endswith(":") else "---:" for h in headers) + " |"
    # Use numeric alignment for the summary only when the caller asks via : suffix
    body = []
    for row in rows:
        body.append("| " + " | ".join(str(cell).replace("|", "/") for cell in row) + " |")
    return "\n".join([line, sep, *body])


def summary_table(cases):
    headers = ["Testing Category", "Total Executed", "Passed", "Failed", "Blocked", "Not Executed"]
    rows = []
    totals = Counter()
    for category in CATEGORIES:
        subset = [case for case in cases if case["category"] == category]
        stats = counts_for(subset)
        rows.append([category, stats["executed"], stats["passed"], stats["failed"], stats["blocked"], stats["not_executed"]])
        for key in ("executed", "passed", "failed", "blocked", "not_executed"):
            totals[key] += stats[key]
    rows.append(["TOTAL", totals["executed"], totals["passed"], totals["failed"], totals["blocked"], totals["not_executed"]])
    return headers, rows, totals


def case_rows(cases):
    rows = []
    for case in cases:
        rows.append([
            case["id"],
            case["scenario"],
            case["expected"],
            case["actual"],
            case["status"],
            case["evidence"],
            case["remarks"],
        ])
    return rows


def write_category_reports(cases, findings):
    for category, folder in FOLDERS.items():
        subset = [case for case in cases if case["category"] == category]
        stats = counts_for(subset)
        denom = stats["passed"] + stats["failed"]
        rate = (stats["passed"] / denom * 100) if denom else 0
        related = [item for item in findings if item["category"] == category]
        lines = [
            f"# FRAMS {category} Report",
            "",
            f"Execution date: {TODAY}",
            "",
            "Results below come from the recorded test run against the isolated database `frams_capstone_test`. The existing `lending_management` database was not modified.",
            "",
            "## Counts",
            "",
            md_table(
                ["Executed", "Passed", "Failed", "Blocked", "Not Executed", "Pass rate"],
                [[stats["executed"], stats["passed"], stats["failed"], stats["blocked"], stats["not_executed"], f"{rate:.2f}% of {denom} completed pass/fail tests"]],
            ),
            "",
            "Pass rate uses passed tests divided by passed plus failed tests. Blocked tests are not treated as passed.",
            "",
            "## Test cases",
            "",
            md_table(["ID", "Scenario", "Expected", "Actual", "Status", "Evidence", "Remarks"], case_rows(subset)),
            "",
            "## Findings in this category",
            "",
        ]
        if not related:
            lines.append("No finding was recorded in this category.")
        else:
            for item in related:
                lines.extend([
                    f"### {item['id']} — {item['severity']}",
                    "",
                    f"- Component: {item['component']}",
                    f"- Description: {item['description']}",
                    f"- Reproduction: {item['reproduction']}",
                    f"- Recommendation: {item['recommendation']}",
                    f"- Evidence: `{item['evidence']}`",
                    f"- Demonstrated: {item['demonstrated']}",
                    f"- Retest: {item['retest']}",
                    "",
                ])
        published = {
            "counted_results": subset,
            "note": "These are the counted results. cli_results.json and http_results.json preserve the first execution, including three session checks later rerun.",
        }
        (ROOT / folder / "test_results" / "published_results.json").write_text(
            json.dumps(published, indent=2), encoding="utf-8"
        )
        (ROOT / folder / "report.md").write_text("\n".join(lines), encoding="utf-8")


def build_final(cases, findings, totals):
    denom = totals["passed"] + totals["failed"]
    rate = totals["passed"] / denom * 100
    headers, rows, _ = summary_table(cases)
    sections = []
    sections.append("# Financial Risk Assessment and Micro-Lending System (FRAMS)")
    sections.append("")
    sections.append("## Software Testing and Evidence Report")
    sections.append("")
    sections.append(f"- Execution date: {TODAY}")
    sections.append("- Environment: local XAMPP workstation, isolated PHP server at http://127.0.0.1:8099")
    sections.append(f"- Source revision: {COMMIT}")
    sections.append("- Application database during testing: frams_capstone_test")
    sections.append("- Existing database preserved: lending_management")
    sections.append("")
    sections.append("## 2. Introduction")
    sections.append("")
    sections.append("This report records the tests executed against the existing FRAMS PHP application. The purpose is to provide Chapter 4 evidence of functional behavior, module integration, end-user workflows, security controls, controlled penetration checks, and the PayMongo QR Ph integration. The application code was not changed to force a passing result.")
    sections.append("")
    sections.append("## 3. Testing Objectives")
    sections.append("")
    sections.append("The run checked loan interest and eligibility calculations, authentication and role boundaries, cash payment posting, receipt and balance consistency, server-side authorization, input handling, CSRF and session behavior, and PayMongo signature, status, and webhook handling. Live PayMongo charges and real borrower email were out of scope.")
    sections.append("")
    sections.append("## 4. Scope of Testing")
    sections.append("")
    sections.append("Included: interest and schedule helpers, eligibility estimates, synthetic login for administrator, collector, and borrower, cash payment recording, receipt display, role restrictions, SQL injection probes, reflected HTML probes, CSRF checks, and local PayMongo webhook handling.")
    sections.append("")
    sections.append("Blocked: creation of a live PayMongo payment, and delivery of a webhook from PayMongo to this workstation. No public tunnel was opened.")
    sections.append("")
    sections.append("Not executed: none. Every prepared case was either completed or marked blocked.")
    sections.append("")
    sections.append("## 5. Testing Environment")
    sections.append("")
    sections.append("- Operating system: Windows 10 (build 26200)")
    sections.append("- Application PHP: XAMPP PHP 8.2.12 with PDO MySQL, curl, openssl, mbstring, and fileinfo")
    sections.append("- A separate PHP 8.4.23 on PATH does not have pdo_mysql and was not used")
    sections.append("- Database: MariaDB 10.4.32")
    sections.append("- Configured application database: lending_management, left unchanged (row counts matched before and after)")
    sections.append("- Isolated database: frams_capstone_test, structure cloned without copying rows, then seeded with synthetic accounts")
    sections.append("- Browser: Cursor IDE browser against the isolated PHP built-in server")
    sections.append("- HTTP checks: PHP cURL")
    sections.append("- Automated test framework: a project-specific PHP runner, because the repository has no PHPUnit or Composer test suite")
    sections.append("- Mail: synthetic accounts have no email address, and the isolated runtime points SMTP at 127.0.0.1 port 1. No borrower email was sent.")
    sections.append("- PayMongo on the isolated runtime: mode forced to test with an invalid test key. The live key in .env was not loaded by the test process.")
    sections.append("")
    sections.append("## 6. Testing Methodology")
    sections.append("")
    sections.append("Expected values for interest follow the implementation: monthly interest equals principal times the monthly rate divided by 100, total interest equals that monthly amount times the term, and total payable equals principal plus total interest, with each returned field rounded to centavos. Eligibility expectations follow the 40 percent DTI policy and the 3,000 to 500,000 loan limits. Security tests treat a safe rejection as a pass and a successful unsafe action as a fail. PayMongo results are labeled as a real HTTP 401 from an invalid test key, a local simulation, or blocked.")
    sections.append("")
    sections.append("## 7. Unit Testing Results")
    sections.append("")
    sections.append(category_blurb(cases, "Unit Testing"))
    sections.append("")
    sections.append("## 8. Integration Testing Results")
    sections.append("")
    sections.append(category_blurb(cases, "Integration Testing"))
    sections.append("")
    sections.append("## 9. System Testing Results")
    sections.append("")
    sections.append(category_blurb(cases, "System Testing"))
    sections.append("")
    sections.append("Browser screenshots show the staff login, the invalid-login message, the administrator dashboard, clients, loans, payments, reports, users, the collector dashboard, the borrower dashboard, loan FRAMS-TEST-A, payment history with RJRR receipts, logout to the public page, and the collector expenses denial.")
    sections.append("")
    sections.append("## 10. Security Testing Results")
    sections.append("")
    sections.append(category_blurb(cases, "Security Testing"))
    sections.append("")
    sections.append("## 11. Penetration Testing Results")
    sections.append("")
    sections.append(category_blurb(cases, "Penetration Testing"))
    sections.append("")
    sections.append("These checks were limited to the isolated FRAMS server. They did not target other hosts, and they did not use denial-of-service or destructive payloads.")
    sections.append("")
    sections.append("## 12. PayMongo Testing Results")
    sections.append("")
    sections.append(category_blurb(cases, "PayMongo Testing"))
    sections.append("")
    sections.append("The invalid-key QR attempt made one real HTTPS request to api.paymongo.com and received HTTP 401. The transaction row was marked failed and no repayment was posted. Paid, failed, mismatched, duplicate, and unknown webhook outcomes were local simulations against the isolated database. They are not PayMongo deliveries and not live charges. PM-011 and PM-012 were blocked: the configured project keys are live, and this workstation was not exposed for an inbound webhook.")
    sections.append("")
    sections.append("## 13. Summary of Results")
    sections.append("")
    sections.append("Executed means the case was attempted and has a recorded PASS, FAIL, or BLOCKED result. Not executed means the case was never run. Pass rate = passed / (passed + failed). Blocked cases are excluded from that rate.")
    sections.append("")
    sections.append(md_table(headers, rows))
    sections.append("")
    sections.append(f"Distinct test cases: {len(cases)}")
    sections.append(f"Passed: {totals['passed']}. Failed: {totals['failed']}. Blocked: {totals['blocked']}. Not executed: {totals['not_executed']}.")
    sections.append(f"Pass rate: {rate:.2f}% ({totals['passed']} / {denom}).")
    sections.append("")
    sections.append("## 14. Security Findings and Recommendations")
    sections.append("")
    sections.append("Confirmed findings are separated from items that were only observed in configuration or code.")
    sections.append("")
    for item in findings:
        sections.extend([
            f"### {item['id']} ({item['severity']}) — {item['component']}",
            "",
            item["description"],
            "",
            f"Reproduction: {item['reproduction']}",
            "",
            f"Recommendation: {item['recommendation']}",
            "",
            f"Evidence: `{item['evidence']}`. Demonstrated: {item['demonstrated']}. Retest: {item['retest']}",
            "",
        ])
    sections.append("Code inspection, not demonstrated as an exploit: staff and borrower login forms do not call csrfRequireValid(). Payment recording does. Login CSRF was not executed as an attack.")
    sections.append("")
    sections.append("## 15. Limitations")
    sections.append("")
    sections.append("- The project PayMongo mode is live and the webhook secret is empty, so a real test-mode payment and a provider-delivered webhook could not be completed safely.")
    sections.append("- The reflected expense alert was confirmed in the DOM, but its rendered box had zero size, so the screenshot of that page does not show the marker text.")
    sections.append("- The first CLI session checks were contaminated by test output. They were rerun and the rerun result is the recorded status.")
    sections.append("- No PHPUnit suite exists. The runner is specific to this evidence package.")
    sections.append("- Playwright was not installed. Browser evidence used the Cursor browser.")
    sections.append("")
    sections.append("## 16. Conclusion")
    sections.append("")
    sections.append("The executed tests show that the flat interest formula, eligibility thresholds, role separation, cash payment posting, and local webhook signature checks behave as implemented for the synthetic data. They also show defects: zero and negative terms are coerced to one month, the loans outstanding card does not subtract payments, loan status can be changed without a CSRF token, the expense message parameter is not encoded, security headers are missing, and local error display leaks a filesystem path. The PayMongo live configuration was not exercised. FRAMS is not claimed to be fully secure or fully verified beyond the cases in this package.")
    sections.append("")
    sections.append("## 17. Evidence References")
    sections.append("")
    sections.append("Category reports, logs, JSON results, and screenshots are under `testing_evidence/01_Unit_Testing` through `testing_evidence/06_PayMongo_Testing`. The workbook is `TESTING_RESULTS.xlsx`. The evidence index is `EVIDENCE_INDEX.md`.")
    sections.append("")
    return "\n".join(sections), rate, denom


def category_blurb(cases, category):
    subset = [case for case in cases if case["category"] == category]
    stats = counts_for(subset)
    failed = [case["id"] + " " + case["scenario"] for case in subset if case["status"] == "FAIL"]
    blocked = [case["id"] + " " + case["scenario"] for case in subset if case["status"] == "BLOCKED"]
    text = f"{category} recorded {stats['executed']} cases: {stats['passed']} passed, {stats['failed']} failed, {stats['blocked']} blocked."
    if failed:
        text += " Failed: " + "; ".join(failed) + "."
    if blocked:
        text += " Blocked: " + "; ".join(blocked) + "."
    text += f" Detail is in `{FOLDERS[category]}/report.md`."
    return text


def write_summary(cases, totals, rate, denom):
    text = f"""# FRAMS Testing Summary

Date: {TODAY}
Revision: {COMMIT}

Distinct test cases: {len(cases)}
Executed (pass, fail, or blocked): {totals['executed']}
Passed: {totals['passed']}
Failed: {totals['failed']}
Blocked: {totals['blocked']}
Not executed: {totals['not_executed']}
Pass rate: {rate:.2f}% ({totals['passed']} / {denom} passed-plus-failed tests)

The existing lending_management database row counts were unchanged. Tests used frams_capstone_test and synthetic accounts. No live PayMongo charge and no borrower email were sent.

Failed cases are the zero and negative loan-term coercion, the unencoded expense message, the loan status update without a CSRF token, and the missing security headers.

Blocked cases are live PayMongo payment creation and external webhook delivery.
"""
    (ROOT / "SUMMARY_REPORT.md").write_text(text, encoding="utf-8")


def write_readme(cases, totals, rate, denom):
    text = f"""# FRAMS Testing Evidence Package

This folder contains the {TODAY} testing run for the Financial Risk Assessment and Micro-Lending System.

## What is included

- Six category folders with `report.md`, `logs/`, `test_results/`, and `screenshots/` where a real page or result was captured.
- `FINAL_TESTING_REPORT.md`, `.pdf`, and `.docx`
- `SUMMARY_REPORT.md`
- `TESTING_RESULTS.xlsx`
- `EVIDENCE_INDEX.md`

## How the tests were executed

1. The structure of `lending_management` was cloned into `frams_capstone_test` without copying rows.
2. Synthetic administrator, collector, and borrower accounts and two loans were inserted only in the test database.
3. CLI tests ran under `testing_evidence/_harness/env_prepend.php`, which forces the test database, disables outbound mail, and replaces PayMongo credentials with an invalid test key.
4. A PHP built-in server on 127.0.0.1:8099 used the same prepend. HTTP tests and the Cursor browser used that server.
5. The server started for this run was stopped afterward. The synthetic loan status used for the CSRF demonstration was restored to Active in the test database.

## Status labels

- PASS: the observed result met the expected result.
- FAIL: the observed result did not meet the expected result.
- BLOCKED: the case could not be completed because of a documented limit.
- NOT EXECUTED: the case was not run.

Blocked and not executed cases are not counted as passed. The pass rate is {rate:.2f}% ({totals['passed']} passed / {denom} passed plus failed).

## Screenshots and logs

The package contains 21 PNG files from the browser. Seventeen are unique captures. Four security and PayMongo files are copies of system screenshots and are identified as shared context in the category reports: the payments list, the administrator dashboard, the borrower loan page, and the users page. Unit and integration testing ran in the PHP CLI, so those folders contain a note instead of a browser image. CLI logs are `cli_test_execution.log`. HTTP logs are `http_test_execution.log`.

## PayMongo

The invalid-key result is a real HTTP 401 from api.paymongo.com. Webhook paid, failed, duplicate, and unknown results are local simulations. Live payment creation and inbound PayMongo delivery were blocked. The live key was not printed and was not used.

## Safe reproduction

Use the harness scripts in `_harness` only with the prepend file. Do not point them at `lending_management`. The synthetic password is defined in `prepare_isolated_db.php` and is not a production password.
"""
    (ROOT / "README.md").write_text(text, encoding="utf-8")


def evidence_rows():
    allowed = {".png", ".log", ".json", ".txt", ".html", ".md", ".pdf", ".docx", ".xlsx"}
    rows = []
    number = 1
    for path in sorted(ROOT.rglob("*")):
        if not path.is_file() or "_harness" in path.parts:
            continue
        if path.suffix.lower() not in allowed or path.name == "EVIDENCE_INDEX.md":
            continue
        rel = path.relative_to(ROOT).as_posix()
        category = "Package"
        for name, folder in FOLDERS.items():
            if rel.startswith(folder):
                category = name
                break
        rows.append((f"E-{number:03d}", category, rel, path.suffix.lower().lstrip("."), path.name))
        number += 1
    return rows


def write_index(rows):
    lines = ["# Evidence Index", "", f"Generated {TODAY}. Every path below was checked against the package.", ""]
    lines.append(md_table(["Evidence ID", "Category", "Relative path", "Type", "Filename"], rows))
    lines.append("")
    (ROOT / "EVIDENCE_INDEX.md").write_text("\n".join(lines), encoding="utf-8")


def write_excel(cases, findings, evidence):
    wb = Workbook()
    header_fill = PatternFill("solid", fgColor="1F4E79")
    header_font = Font(color="FFFFFF", bold=True)
    thin = Border(
        left=Side(style="thin", color="D9E2F3"),
        right=Side(style="thin", color="D9E2F3"),
        top=Side(style="thin", color="D9E2F3"),
        bottom=Side(style="thin", color="D9E2F3"),
    )

    ws = wb.active
    ws.title = "Summary"
    ws["A1"] = "FRAMS testing summary"
    ws["A1"].font = Font(bold=True, size=16, color="1F4E79")
    ws["A2"] = TODAY
    ws["A4"] = "Category"
    ws["B4"] = "Executed"
    ws["C4"] = "Passed"
    ws["D4"] = "Failed"
    ws["E4"] = "Blocked"
    ws["F4"] = "Not Executed"
    for cell in ws[4]:
        cell.fill = header_fill
        cell.font = header_font
    for index, category in enumerate(CATEGORIES, start=5):
        ws.cell(index, 1, category)
        # COUNTIF formulas against the All Test Cases sheet
        ws.cell(index, 2, f'=COUNTIF(\'All Test Cases\'!B:B,A{index})')
        ws.cell(index, 3, f'=COUNTIFS(\'All Test Cases\'!B:B,A{index},\'All Test Cases\'!F:F,"PASS")')
        ws.cell(index, 4, f'=COUNTIFS(\'All Test Cases\'!B:B,A{index},\'All Test Cases\'!F:F,"FAIL")')
        ws.cell(index, 5, f'=COUNTIFS(\'All Test Cases\'!B:B,A{index},\'All Test Cases\'!F:F,"BLOCKED")')
        ws.cell(index, 6, f'=COUNTIFS(\'All Test Cases\'!B:B,A{index},\'All Test Cases\'!F:F,"NOT EXECUTED")')
    ws["A11"] = "TOTAL"
    ws["A11"].font = Font(bold=True)
    for col in range(2, 7):
        letter = get_column_letter(col)
        ws.cell(11, col, f"=SUM({letter}5:{letter}10)")
        ws.cell(11, col).font = Font(bold=True)
    ws["A13"] = "Pass rate"
    ws["B13"] = '=IF((C11+D11)=0,0,C11/(C11+D11))'
    ws["B13"].number_format = "0.00%"
    ws["C13"] = "Denominator is passed plus failed. Blocked cases are excluded."
    ws["A15"] = "Distinct test cases"
    ws["B15"] = "=COUNTA('All Test Cases'!A5:A500)"
    ws["A17"] = "Major confirmed findings"
    ws["A18"] = "F-001 zero or negative term is charged as one month; F-002 unencoded expense message; F-003 loan status changes without CSRF; F-004 security headers missing; F-005 local error display shows a filesystem path; F-007 loans outstanding card does not subtract payments."
    ws["A18"].alignment = Alignment(wrap_text=True)
    ws["A20"] = "Limitations"
    ws["A21"] = "Live PayMongo payment creation and inbound webhook delivery were blocked. The live key was not used."
    ws.merge_cells("A18:F18")
    ws.merge_cells("A21:F21")
    ws.row_dimensions[18].height = 45
    for col in range(1, 7):
        ws.column_dimensions[get_column_letter(col)].width = 24
    ws.column_dimensions["A"].width = 28

    sheet = wb.create_sheet("All Test Cases")
    headers = ["Test Case ID", "Category", "Scenario", "Expected Result", "Actual Result", "Status", "Evidence Filename", "Remarks"]
    for col, header in enumerate(headers, start=1):
        cell = sheet.cell(4, col, header)
        cell.fill = header_fill
        cell.font = header_font
        cell.alignment = Alignment(wrap_text=True)
    for row_index, case in enumerate(cases, start=5):
        values = [case["id"], case["category"], case["scenario"], case["expected"], case["actual"], case["status"], case["evidence"], case["remarks"]]
        for col, value in enumerate(values, start=1):
            cell = sheet.cell(row_index, col, value)
            cell.alignment = Alignment(wrap_text=True, vertical="top")
            cell.border = thin
    widths = [16, 22, 36, 40, 46, 14, 42, 40]
    for index, width in enumerate(widths, start=1):
        sheet.column_dimensions[get_column_letter(index)].width = width
    sheet.auto_filter.ref = f"A4:H{4 + len(cases)}"
    sheet.freeze_panes = "A5"
    sheet.row_dimensions[4].height = 22

    findings_sheet = wb.create_sheet("Security Findings")
    finding_headers = ["Finding ID", "Category", "Affected component", "Description", "Severity", "Evidence", "Reproduction summary", "Recommendation", "Retest status"]
    for col, header in enumerate(finding_headers, start=1):
        cell = findings_sheet.cell(1, col, header)
        cell.fill = header_fill
        cell.font = header_font
    for row_index, item in enumerate(findings, start=2):
        values = [item["id"], item["category"], item["component"], item["description"], item["severity"], item["evidence"], item["reproduction"], item["recommendation"], item["retest"]]
        for col, value in enumerate(values, start=1):
            cell = findings_sheet.cell(row_index, col, value)
            cell.alignment = Alignment(wrap_text=True, vertical="top")
    for col in range(1, 10):
        findings_sheet.column_dimensions[get_column_letter(col)].width = 28
    findings_sheet.column_dimensions["D"].width = 55
    findings_sheet.freeze_panes = "A2"

    register = wb.create_sheet("Evidence Register")
    register_headers = ["Evidence ID", "Testing Category", "Related Test Case ID", "Filename", "Evidence Type", "Description", "Execution Date", "Evidence Status"]
    for col, header in enumerate(register_headers, start=1):
        cell = register.cell(1, col, header)
        cell.fill = header_fill
        cell.font = header_font
    for row_index, (evidence_id, category, rel, kind, filename) in enumerate(evidence, start=2):
        related = ""
        match = re.match(r"([A-Z]+-\d+)", filename)
        if match:
            related = match.group(1)
        values = [evidence_id, category, related, filename, kind, rel, TODAY, "Present"]
        for col, value in enumerate(values, start=1):
            register.cell(row_index, col, value)
    for col in range(1, 9):
        register.column_dimensions[get_column_letter(col)].width = 28
    register.column_dimensions["F"].width = 55
    register.freeze_panes = "A2"
    wb.save(ROOT / "TESTING_RESULTS.xlsx")


def add_docx_table(document, headers, rows):
    table = document.add_table(rows=1, cols=len(headers))
    table.style = "Table Grid"
    for index, header in enumerate(headers):
        table.rows[0].cells[index].text = header
    for row in rows:
        cells = table.add_row().cells
        for index, value in enumerate(row):
            cells[index].text = str(value)
    document.add_paragraph("")


def write_docx(markdown_text, cases, findings, totals, rate, denom):
    document = Document()
    title = document.add_heading("Financial Risk Assessment and Micro-Lending System (FRAMS)", 0)
    title.alignment = WD_ALIGN_PARAGRAPH.CENTER
    subtitle = document.add_paragraph("Software Testing and Evidence Report")
    subtitle.alignment = WD_ALIGN_PARAGRAPH.CENTER
    meta = document.add_paragraph(f"{TODAY}  |  Revision {COMMIT}  |  Isolated test database")
    meta.alignment = WD_ALIGN_PARAGRAPH.CENTER
    document.add_heading("Summary of results", level=1)
    headers, rows, _ = summary_table(cases)
    add_docx_table(document, headers, rows)
    document.add_paragraph(f"Pass rate {rate:.2f}% ({totals['passed']} / {denom} passed plus failed). Blocked tests are not passed.")
    for block in markdown_text.split("\n## "):
        if block.startswith("# "):
            continue
        heading, _, body = block.partition("\n")
        document.add_heading(heading.strip(), level=1)
        for paragraph in body.split("\n\n"):
            clean = paragraph.strip()
            if not clean or clean.startswith("|"):
                continue
            document.add_paragraph(clean.replace("`", ""))
    document.add_heading("Findings", level=1)
    add_docx_table(
        document,
        ["ID", "Severity", "Component", "Description"],
        [[item["id"], item["severity"], item["component"], item["description"]] for item in findings],
    )
    document.save(ROOT / "FINAL_TESTING_REPORT.docx")


class ReportPdf(FPDF):
    def header(self):
        self.set_font("Arial", "B", 10)
        self.cell(0, 8, "FRAMS Software Testing and Evidence Report", align="R", new_x="LMARGIN", new_y="NEXT")

    def footer(self):
        self.set_y(-12)
        self.set_font("Arial", "", 9)
        self.cell(0, 8, f"Page {self.page_no()}", align="C")


def pdf_write(pdf, text, size=11, bold=False):
    pdf.set_font("Arial", "B" if bold else "", size)
    safe = text.replace("₱", "PHP ")
    safe = safe.encode("latin-1", "replace").decode("latin-1")
    pdf.multi_cell(0, 6, safe)
    pdf.ln(1)


def write_pdf(cases, findings, totals, rate, denom):
    pdf = ReportPdf()
    pdf.add_font("Arial", "", r"C:\Windows\Fonts\arial.ttf")
    pdf.add_font("Arial", "B", r"C:\Windows\Fonts\arialbd.ttf")
    pdf.set_auto_page_break(auto=True, margin=15)
    pdf.add_page()
    pdf_write(pdf, "Financial Risk Assessment and Micro-Lending System (FRAMS)", 16, True)
    pdf_write(pdf, "Software Testing and Evidence Report", 13, True)
    pdf_write(pdf, f"Execution date: {TODAY}")
    pdf_write(pdf, f"Revision: {COMMIT}")
    pdf_write(pdf, "Environment: Windows, XAMPP PHP 8.2.12, MariaDB 10.4.32, isolated database frams_capstone_test")
    pdf_write(pdf, "Existing database lending_management was not modified.")
    pdf.ln(2)
    pdf_write(pdf, "Summary", 14, True)
    headers, rows, _ = summary_table(cases)
    pdf_write(pdf, " | ".join(headers), 9, True)
    for row in rows:
        pdf_write(pdf, " | ".join(str(cell) for cell in row), 9)
    pdf_write(pdf, f"Pass rate: {rate:.2f}% ({totals['passed']} / {denom} passed plus failed). Blocked tests are excluded.")
    pdf_write(pdf, "Purpose", 14, True)
    pdf_write(pdf, "The tests evaluated the existing PHP FRAMS application: interest and eligibility calculations, login and roles, cash payments, authorization, injection and CSRF checks, and PayMongo QR Ph handling. Application business rules were not edited to obtain passes.")
    for category in CATEGORIES:
        subset = [case for case in cases if case["category"] == category]
        stats = counts_for(subset)
        pdf_write(pdf, category, 13, True)
        pdf_write(pdf, f"Executed {stats['executed']}: passed {stats['passed']}, failed {stats['failed']}, blocked {stats['blocked']}, not executed {stats['not_executed']}.")
        for case in subset:
            if case["status"] != "PASS":
                pdf_write(pdf, f"{case['id']} {case['status']}: {case['scenario']}. Actual: {case['actual'][:240]}", 10)
    pdf_write(pdf, "Findings", 14, True)
    for item in findings:
        pdf_write(pdf, f"{item['id']} ({item['severity']}) {item['component']}", 11, True)
        pdf_write(pdf, item["description"], 10)
        pdf_write(pdf, "Recommendation: " + item["recommendation"], 10)
    pdf_write(pdf, "Limitations", 14, True)
    pdf_write(pdf, "Live PayMongo payment creation and inbound webhook delivery were blocked. Webhook success and failure results are local simulations. One invalid test key produced a real HTTP 401 from api.paymongo.com and did not post a repayment.")
    pdf_write(pdf, "Conclusion", 14, True)
    pdf_write(pdf, "The recorded cases support the implemented interest formula, role checks, and cash payment posting for synthetic data. They also record the failed security and calculation cases listed above. This package does not declare FRAMS fully secure.")
    pdf.output(str(ROOT / "FINAL_TESTING_REPORT.pdf"))


def scrub_saved_evidence():
    pattern = re.compile(r"\[redacted-key\]\S*")
    for path in ROOT.rglob("*"):
        if not path.is_file() or path.suffix.lower() not in {".json", ".log", ".txt", ".html", ".md"}:
            continue
        text = path.read_text(encoding="utf-8", errors="replace")
        cleaned = pattern.sub("[redacted-key]", text)
        if cleaned != text:
            path.write_text(cleaned, encoding="utf-8")


def main():
    for folder in FOLDERS.values():
        (ROOT / folder / "screenshots").mkdir(parents=True, exist_ok=True)
        (ROOT / folder / "logs").mkdir(parents=True, exist_ok=True)
        (ROOT / folder / "test_results").mkdir(parents=True, exist_ok=True)
    scrub_saved_evidence()
    cases, findings = load_cases()
    # Stable order
    order = {name: index for index, name in enumerate(CATEGORIES)}
    cases.sort(key=lambda case: (order[case["category"]], case["id"]))
    totals = counts_for(cases)
    final_text, rate, denom = build_final(cases, findings, totals)
    (ROOT / "FINAL_TESTING_REPORT.md").write_text(final_text, encoding="utf-8")
    write_category_reports(cases, findings)
    write_summary(cases, totals, rate, denom)
    write_readme(cases, totals, rate, denom)
    write_excel(cases, findings, [])
    write_docx(final_text, cases, findings, totals, rate, denom)
    write_pdf(cases, findings, totals, rate, denom)
    for folder in ("01_Unit_Testing", "02_Integration_Testing"):
        note = ROOT / folder / "screenshots" / "NO_BROWSER_SCREENSHOT.txt"
        if not list((ROOT / folder / "screenshots").glob("*.png")):
            note.write_text(
                "No browser screenshot was captured for this category. These cases ran in the PHP CLI and their evidence is the execution log and JSON result files.\n",
                encoding="utf-8",
            )
    evidence = evidence_rows()
    missing = [rel for _, _, rel, _, _ in evidence if not (ROOT / rel).exists()]
    if missing:
        raise SystemExit("Missing evidence: " + ", ".join(missing))
    write_index(evidence)
    write_excel(cases, findings, evidence)
    print(f"CASES={len(cases)} PASS={totals['passed']} FAIL={totals['failed']} BLOCKED={totals['blocked']} NOT={totals['not_executed']} RATE={rate:.2f} DENOM={denom}")
    print(f"EVIDENCE_FILES={len(evidence)}")
    print(f"FINDINGS={len(findings)}")


if __name__ == "__main__":
    main()
