# Lending Management System

## 1. System Overview

The Lending Management System is a server-rendered PHP and MySQL web application for RJ and RR Finance Services. It manages the operational flow from borrower registration and loan application through administrative review, loan agreement acceptance, loan release, collector assignment, repayment recording, and borrower payment tracking.

The system is intended for three users: administrators who manage lending operations, collectors who handle assigned accounts and payments, and borrowers who apply for loans and monitor their accounts. It addresses the need to keep borrower records, applications, loan terms, schedules, payments, expenses, notifications, and reports in one application instead of relying on disconnected manual records. Role-specific dashboards and access checks give each user a workflow appropriate to their responsibilities.

## 2. Objectives and Purpose

- Provide a public borrower portal for registration, login, loan applications, document submission, and application status tracking.
- Give administrators a central workspace for users, borrowers, collectors, clients, loan applications, loan releases, payments, expenses, and reports.
- Give collectors access to assigned clients, assigned loans, collection schedules, and payment recording.
- Generate repayment schedules from loan amount, interest, term, and payment frequency.
- Keep borrowers informed through in-application notifications and configured email messages.
- Recalculate loan and installment status from recorded payments and due dates.
- Provide operational summaries for collections, expenses, disbursements, outstanding receivables, and collector performance.

## 3. Key Features

### Authentication and account management

- Session-based authentication for staff and borrowers.
- Role-aware redirects to administrator, collector, or borrower dashboards.
- Password hashing for newly stored passwords and password verification during login.
- Profile updates and password changes.
- Token-based password reset flow with an expiry time and optional SMTP delivery.
- Active-account checks and borrower approval checks.

### Borrower portal

- Public landing page and borrower registration.
- Registration status handled through administrator approval.
- Multi-step loan application capturing identity, contact, employment or income information, loan details, and supporting documents.
- Application status and review remarks.
- Loan agreement viewing and acceptance or decline by entering the borrower name as a signature.
- Released-loan details, repayment schedule, payment history, notifications, and receipt downloads.

### Administrative lending operations

- Administrator dashboard with client, loan, payment, expense, and user summaries.
- User and role management.
- Collector account creation, editing, activation, and deactivation.
- Borrower registration approval or rejection.
- Client search, details, loan history, and collector assignment.
- Loan creation and status management for existing clients.
- Loan application document verification, approval, rejection, and requests for additional documents.
- Loan agreement generation and borrower notification.
- Loan release with active collector assignment, generated loan number, and generated installment schedule.
- Payment recording, payment deletion, receipt generation, search, and date filtering.
- Expense recording, category filtering, editing, and deletion.
- Reports for borrower and collector counts, loan statuses, collections, expenses, disbursements, receivables, net income, margins, and collector performance.

### Collection and notifications

- Collector dashboard for assigned clients, loans, pending collections, schedules, and daily payments.
- Payment methods and optional reference numbers.
- Receipt numbers in the `RJRR-YYYYMMDD-######` format.
- In-application notifications with unread counts and read state.
- SMTP email support for configured approval and password-reset messages.

## 4. User Roles and Permissions

Permissions are enforced in `includes/auth_lending.php` and in page-level checks.

| Role | Confirmed permissions |
| --- | --- |
| **Administrator** | Access the administrative dashboard; manage users and collectors; approve borrowers; review applications and documents; generate agreements; release loans; assign collectors; manage clients, loans, payments, and expenses; view reports; manage notifications and profile settings. |
| **Collector** | Access the collector dashboard; view assigned clients and loans; view collection schedules; record payments for assigned loans; view collection notifications; view or download receipts available to the account. Collector access is scoped using client and loan collector assignments. |
| **Borrower** | Register and log in after approval; submit a loan application and documents; view application status and notifications; review and accept or decline agreements; view released loans and schedules; view payment history; download owned receipts; update profile; reset a password. |

Collectors are redirected away from administrator-only pages, while borrower access is restricted to the logged-in borrower and linked client, application, loan, and payment records.

## 5. System Modules

| Module | Main entry points | Responsibility |
| --- | --- | --- |
| Public portal | `index.php`, `registration_lending.php` | Public information and borrower registration. |
| Authentication | `login_lending.php`, `borrower_login_lending.php`, `logout_lending.php` | Staff/borrower login, sessions, logout, and role redirects. |
| Borrower approval | `borrower_approval_lending.php` | Administrator review of borrower registrations. |
| Dashboards | `dashboard_lending.php`, `collector_dashboard_lending.php`, `borrower_dashboard_lending.php` | Role-specific summaries and shortcuts. |
| User and collector management | `users_lending.php`, `collectors_lending.php`, `profile_lending.php` | Account records, collector accounts, profile data, and passwords. |
| Client management | `clients_lending.php`, `client_details_lending.php` | Client records, history, search, details, and collector assignment. |
| Loan management | `loans_lending.php`, `loan_details_lending.php` | Direct staff loan records, details, balances, and status. |
| Loan applications | `borrower_loan_application_lending.php`, `loan_applications_lending.php` | Borrower submissions and administrator application/document review. |
| Agreements and release | `borrower_loan_agreement_lending.php`, `loan_release_lending.php` | Agreement acceptance, release validation, collector assignment, and schedules. |
| Payments | `payments_lending.php`, `borrower_payment_history_lending.php` | Collection recording, balance updates, receipts, and borrower history. |
| Expenses | `expenses_lending.php` | Business expense records and categories. |
| Reports | `reports_lending.php` | Administrative lending, collection, expense, and performance reports. |
| Notifications | `notifications_lending.php`, `borrower_notifications_lending.php` | User-specific notification lists and read state. |

## 6. System Workflow

### Borrower registration and approval

1. A borrower opens the public portal and submits registration details.
2. The system creates a user record and a `borrower_applications` record with a pending state.
3. An administrator reviews the registration.
4. Approval activates the borrower account, sets the borrower role, updates verification fields, and creates a notification. Rejection records the decision.

### Loan application and review

1. An approved borrower logs in and completes the multi-step loan form.
2. The application stores personal, employment/income, loan, payment-frequency, and repayment information.
3. Supporting files are stored under `uploads/loan_documents/` and referenced by `loan_application_documents`.
4. An administrator verifies or rejects documents and can approve, reject, or request additional documents.
5. Review decisions and notes are stored in `loan_application_reviews` and reflected in the application status.

### Agreement and loan release

1. The administrator generates a loan agreement for an eligible application.
2. The borrower receives an in-application notification and reviews the agreement.
3. The borrower accepts or declines the agreement; acceptance records the name and timestamp.
4. The administrator selects an active collector and releases the loan.
5. The system creates a `loan_releases` record, a `loans` record, and installment rows in `loan_payment_schedules`.
6. The release receives a generated loan number and the assigned users are notified.

### Collection and account monitoring

1. An administrator or the assigned collector selects an unpaid loan.
2. The payment amount, date, collector, method, and optional reference number are recorded.
3. The system generates a receipt number, recalculates loan status and schedule status, and stores the payment.
4. Borrowers can view their payment history and download a PDF receipt for their own payments.
5. Administrators can review collections, expenses, balances, and performance in reports.

## 7. Technologies Used

- **Backend:** PHP with server-rendered HTML pages.
- **Database:** MySQL/MariaDB-compatible database accessed through PDO MySQL.
- **Authentication:** PHP sessions and role checks.
- **Frontend:** HTML5, CSS3, JavaScript, Bootstrap 5.3.0-alpha1, Font Awesome 6, and Bootstrap Icons 1.11.3 loaded from CDNs.
- **Charts and exports:** Chart.js, html2pdf.js, and SheetJS/XLSX are referenced by the application pages.
- **Email:** A local SMTP client implemented with PHP `stream_socket_client`; the default configuration targets Mailpit at `127.0.0.1:1025`.
- **Web server:** Apache is the documented local deployment target through XAMPP.

No Composer manifest, npm manifest, framework configuration, automated test suite, or separate REST service is present in the repository.

## 8. Database

### Connection configuration

`includes/db_lending.php` connects to:

```text
Host: localhost
Database: lending_management
User: root
Password: empty by default in the source
Driver: PDO MySQL
Character set: utf8
```

Update these values for the local environment. PDO exceptions are enabled and emulated prepares are disabled.

### Tables used by the application

The source expects the core tables `users`, `clients`, `loans`, and `payments`. It also creates or extends several tables during requests:

- `users`: accounts, roles, status, profile data, and last-login data.
- `borrower_applications`: public borrower registration and verification data.
- `clients`: borrower/client records and collector assignments.
- `loan_applications`: borrower requests, income information, requested and approved terms, review state, and remarks.
- `loan_application_documents`: uploaded application documents and verification status.
- `loan_application_reviews`: administrator decisions and review notes.
- `loan_agreements`: generated agreements, acceptance state, signature name, and timestamps.
- `loan_releases`: released loan number, amount, borrower, collector, terms, and release metadata.
- `loan_payment_schedules`: installment dates, principal, interest, amount due, and installment status.
- `loans`: operational loan records and balances/status fields.
- `payments`: payment transactions, receipt data, method, reference, and collector data.
- `expenses`: expense description, amount, date, and category.
- `notifications`: user notifications and read state.
- `loan_release_notifications`: supplemental release notification table created by the release module.
- `password_resets`: expiring password-reset tokens.

Important relationships are represented by application queries: users link to borrower applications, clients, applications, notifications, releases, and loans; clients link to loans and collectors; applications link to documents, reviews, agreements, and releases; releases link to loans and schedules; loans link to payments.

There is no database dump or migration folder in the repository. The visible source does not provide a complete initial definition for every core table, so a deployment must supply compatible base tables before all modules can operate. Several feature tables and columns are created or added dynamically by PHP request code.

### Internal endpoint

`payments_lending.php` provides a JSON lookup used by the payment interface:

```text
GET payments_lending.php?action=fetch_loan&loan_id=<id>
```

It returns the selected loan, borrower name, amount paid, and remaining balance when the current user is authorized. No public REST API, webhook receiver, payment gateway, SMS integration, or cloud storage integration was found.

## 9. Installation and Setup

### Requirements

- XAMPP or another Apache/PHP/MySQL or MariaDB environment.
- PHP with the PDO MySQL extension enabled.
- A PHP version compatible with the source. PHP 8.0+ is the practical baseline because the code uses modern PHP syntax; the repository has not supplied a tested version matrix.
- Writable upload directories.
- Optional SMTP server or Mailpit for email testing.

### Local installation

1. Copy or clone the repository into the Apache document root. With XAMPP, the expected location is:

   ```text
   C:\xampp\htdocs\lending_system
   ```

2. Create a MySQL/MariaDB database named `lending_management`.

3. Provide the required base tables expected by the application: `users`, `clients`, `loans`, and `payments`. The repository does not include a complete SQL schema or seed script.

4. Edit `lending_system/includes/db_lending.php` and set the host, database name, username, and password for the local database.

5. Ensure PHP can write to:

   ```text
   lending_system/uploads/loan_applications/
   lending_system/uploads/loan_documents/
   ```

6. Review `lending_system/includes/mail_config.php`. For local email testing, start Mailpit or point the SMTP settings to another SMTP server. Email-dependent features can still be used without delivery only if the configured SMTP behavior is acceptable for the environment.

7. Start Apache and MySQL/MariaDB in XAMPP.

8. Open the application URLs below.

The repository does not contain verified default administrator credentials. Create or provision an active administrator account in the database through the project’s established data-management process; do not rely on the unsupported `admin/admin123` values from older documentation.

## 10. How to Use

### Entry points

- Public portal: `http://localhost/lending_system/lending_system/index.php`
- Staff login: `http://localhost/lending_system/lending_system/login_lending.php`
- Borrower login: `http://localhost/lending_system/lending_system/borrower_login_lending.php`
- Borrower registration: `http://localhost/lending_system/lending_system/registration_lending.php`

### Typical administrator use

1. Sign in through the staff login.
2. Approve borrower registrations and manage active collector accounts.
3. Review submitted applications and uploaded documents.
4. Approve, reject, or request additional documents.
5. Generate an agreement and wait for the borrower’s agreement action.
6. Assign an active collector and release the eligible loan.
7. Monitor schedules, record or review payments, manage expenses, and open reports.

### Typical collector use

1. Sign in through the staff login with a collector account.
2. Open the collector dashboard to see assigned clients, loans, schedules, and pending collections.
3. Record payments only for assigned accounts.
4. Review payment details and available receipts.

### Typical borrower use

1. Register through the public portal.
2. Wait for administrator approval, then sign in through the borrower login.
3. Complete and submit the loan application and supporting documents.
4. Follow application notifications and review the generated agreement.
5. Accept or decline the agreement.
6. After release, monitor the loan, installment schedule, notifications, and payment history.

## 11. Project Structure

```text
.
├── README.md
├── audit_results.txt                 Audit output kept in the workspace
├── modal_audit.py                    Audit utility
├── lending_system/
│   ├── *.php                         Server-rendered application pages
│   ├── includes/
│   │   ├── auth_lending.php          Sessions, authentication, roles, access checks
│   │   ├── db_lending.php             PDO connection and database helpers
│   │   ├── loan_helpers.php           Loan terms, frequencies, schedules, statuses
│   │   ├── agreement_helpers.php      Agreement text generation
│   │   ├── mailer.php                  SMTP client
│   │   ├── mail_config.php             SMTP settings
│   │   ├── email_templates.php         Email templates
│   │   ├── nav_lending.php             Role-aware navigation
│   │   └── update_status_lending.php   Loan status update query
│   ├── assets/
│   │   ├── lending.js
│   │   └── lending_styles.css
│   ├── css/
│   │   ├── lending_styles.css
│   │   └── nav_styles.css
│   ├── documentation/
│   │   └── README_lending.md           Earlier local usage notes
│   ├── images/                         Application images and branding
│   ├── uploads/
│   │   ├── loan_applications/           Application uploads
│   │   └── loan_documents/              Supporting loan documents
│   └── tools/                          Maintenance and data utilities
└── tmp_*.php                            Local inspection/debug utilities
```

## 12. Future Improvements

These are recommended improvements based on repository gaps, not currently implemented features:

- Add a versioned SQL schema or migration system for the core and feature tables.
- Add seed data or an administrator provisioning command without embedding production credentials.
- Add automated unit, integration, and role-authorization tests.
- Add a formal API contract if the JSON loan lookup must be consumed outside the payment page.
- Add CSRF tokens to state-changing forms and review session cookie/security settings for production deployment.
- Strengthen upload validation with file type, size, storage, access-control, and malware-scanning policies.
- Move secrets and database/SMTP settings to environment-based configuration.
- Replace request-time schema changes with controlled migrations and transactions for multi-table release/payment operations.
- Improve receipt numbering concurrency and add stronger database constraints and foreign keys.
- Add audit logs for approvals, releases, payment edits/deletions, collector assignment, and administrative changes.
- Standardize application, agreement, release, loan, and schedule status values across all modules.
- Add deployment documentation, backups, recovery procedures, and supported PHP/MySQL version information.

## Project Status

This README documents the behavior visible in the current repository. The application is a working PHP/MySQL codebase, but production readiness cannot be confirmed from source alone because the complete base database schema, deployment configuration, automated tests, and production security configuration are not included.
