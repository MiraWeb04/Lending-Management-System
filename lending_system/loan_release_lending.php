<?php
/**
 * Admin Loan Release Module for RJ and RR Finance Services
 */

require_once 'includes/auth_lending.php';
requireAdmin();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'includes/db_lending.php';
require_once 'includes/agreement_helpers.php';
require_once 'includes/loan_helpers.php';
ensureNotificationsSchema();
ensureLoanAgreementsSchema();

function ensureReleaseRelatedSchema() {
    global $conn;

    $clientColumns = [];
    $clientColumnStmt = $conn->query('SHOW COLUMNS FROM clients');
    foreach ($clientColumnStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $clientColumns[$column['Field']] = true;
    }

    if (!isset($clientColumns['collector_id'])) {
        $conn->exec('ALTER TABLE clients ADD COLUMN collector_id INT NULL DEFAULT NULL AFTER contact');
    }
    if (!isset($clientColumns['user_id'])) {
        $conn->exec('ALTER TABLE clients ADD COLUMN user_id INT NULL DEFAULT NULL AFTER client_id');
    }

    $loanColumns = [];
    $loanColumnStmt = $conn->query('SHOW COLUMNS FROM loans');
    foreach ($loanColumnStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $loanColumns[$column['Field']] = true;
    }

    $loanColumnAdds = [
        'collector_id' => 'ALTER TABLE loans ADD COLUMN collector_id INT NULL DEFAULT NULL AFTER status',
        'loan_number' => 'ALTER TABLE loans ADD COLUMN loan_number VARCHAR(100) NULL AFTER collector_id',
        'release_id' => 'ALTER TABLE loans ADD COLUMN release_id INT NULL DEFAULT NULL AFTER loan_number',
        'payment_frequency' => "ALTER TABLE loans ADD COLUMN payment_frequency VARCHAR(50) DEFAULT 'Monthly' AFTER release_id",
        'agreement_status' => "ALTER TABLE loans ADD COLUMN agreement_status VARCHAR(50) DEFAULT 'Pending' AFTER payment_frequency",
        'released_by' => 'ALTER TABLE loans ADD COLUMN released_by VARCHAR(150) DEFAULT NULL AFTER agreement_status'
    ];

    foreach ($loanColumnAdds as $columnName => $alterSql) {
        if (!isset($loanColumns[$columnName])) {
            $conn->exec($alterSql);
        }
    }
}

ensureReleaseRelatedSchema();

function getReleaseStatusLabel($status) {
    $normalized = strtolower(trim((string)$status));
    switch ($normalized) {
        case 'ready for release':
            return 'Ready for Release';
        case 'agreement accepted':
            return 'Agreement Accepted';
        case 'approved':
            return 'Approved';
        case 'waiting for loan agreement':
            return 'Waiting for Loan Agreement';
        default:
            return $status ? ucfirst($normalized) : 'Pending';
    }
}

function getReleaseStatusBadgeClass($status) {
    $normalized = strtolower(trim((string)$status));
    switch ($normalized) {
        case 'ready for release':
            return 'bg-success';
        case 'agreement accepted':
            return 'bg-primary';
        case 'approved':
            return 'bg-warning text-dark';
        case 'waiting for loan agreement':
            return 'bg-info text-dark';
        default:
            return 'bg-secondary';
    }
}

$conn->exec("CREATE TABLE IF NOT EXISTS loan_releases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id INT UNSIGNED NOT NULL,
    borrower_user_id INT DEFAULT NULL,
    collector_user_id INT DEFAULT NULL,
    loan_number VARCHAR(100) DEFAULT NULL,
    loan_amount DECIMAL(12,2) DEFAULT NULL,
    interest_rate DECIMAL(5,2) DEFAULT NULL,
    loan_term VARCHAR(50) DEFAULT NULL,
    payment_frequency VARCHAR(50) DEFAULT 'Monthly',
    release_status VARCHAR(50) DEFAULT 'Pending',
    released_at DATETIME DEFAULT NULL,
    released_by VARCHAR(150) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->exec("CREATE TABLE IF NOT EXISTS loan_payment_schedules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    release_id INT UNSIGNED NOT NULL,
    installment_number INT DEFAULT NULL,
    due_date DATE DEFAULT NULL,
    principal_amount DECIMAL(12,2) DEFAULT NULL,
    interest_amount DECIMAL(12,2) DEFAULT NULL,
    total_amount_due DECIMAL(12,2) DEFAULT NULL,
    status VARCHAR(50) DEFAULT 'Upcoming',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->exec("CREATE TABLE IF NOT EXISTS loan_release_notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    title VARCHAR(150) NOT NULL,
    message TEXT DEFAULT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$user = getCurrentUser();
$message = $_SESSION['loan_release_message'] ?? '';
$messageType = $_SESSION['loan_release_type'] ?? '';
unset($_SESSION['loan_release_message'], $_SESSION['loan_release_type']);

function parseLoanTermMonths($loanTerm) {
    if (is_numeric($loanTerm)) {
        return max(1, (int)$loanTerm);
    }

    if (preg_match('/(\d+)/', (string)$loanTerm, $matches)) {
        return max(1, (int)$matches[1]);
    }

    return 12;
}

function generateLoanNumber() {
    global $conn;
    $year = date('Y');
    $stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(loan_number, '-', -1) AS UNSIGNED)) AS last_sequence FROM loan_releases WHERE loan_number LIKE ?");
    $stmt->execute(["RJRR-LOAN-$year-%"]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $lastSequence = (int)($row['last_sequence'] ?? 0);
    $nextSequence = $lastSequence + 1;
    return 'RJRR-LOAN-' . $year . '-' . str_pad((string)$nextSequence, 6, '0', STR_PAD_LEFT);
}

function buildPaymentSchedule($loanAmount, $interestRate, $termMonths, $paymentFrequency, $releaseDate, $releaseId) {
    $installments = [];
    $termMonths = max(1, (int)$termMonths);
    $releaseDateTime = new DateTimeImmutable($releaseDate);
    $termEndDate = $releaseDateTime->modify('+' . $termMonths . ' months');
    $normalizedFrequency = normalizePaymentFrequencyKey($paymentFrequency);
    $daysBetween = (int)$releaseDateTime->diff($termEndDate)->days;

    switch ($normalizedFrequency) {
        case 'daily':
            $count = max(1, $daysBetween);
            break;
        case 'weekly':
            $count = max(1, (int)ceil($daysBetween / 7));
            break;
        case 'semi-monthly':
            $count = max(1, $termMonths * 2);
            break;
        case 'monthly':
        default:
            $count = max(1, $termMonths);
            break;
    }

    $principalAmount = $loanAmount / max(1, $count);
    $totalInterest = $loanAmount * ($interestRate / 100) * $termMonths;
    $interestAmount = $totalInterest / max(1, $count);

    $currentDate = new DateTime($releaseDate);
    $normalizedFrequency = normalizePaymentFrequencyKey($paymentFrequency);
    for ($i = 1; $i <= $count; $i++) {
        if ($normalizedFrequency === 'daily') {
            $currentDate->modify('+1 day');
        } elseif ($normalizedFrequency === 'weekly') {
            $currentDate->modify('+7 days');
        } elseif ($normalizedFrequency === 'semi-monthly') {
            $currentDate->modify('+15 days');
        } else {
            $currentDate->modify('+1 month');
        }

        $dueDate = $currentDate->format('Y-m-d');
        $installments[] = [
            'release_id' => $releaseId,
            'installment_number' => $i,
            'due_date' => $dueDate,
            'principal_amount' => round($principalAmount, 2),
            'interest_amount' => round($interestAmount, 2),
            'total_amount_due' => round($principalAmount + $interestAmount, 2),
            'status' => 'Upcoming',
        ];
    }

    // Ensure final installment due date does not overshoot the term end date.
    if (count($installments) > 0) {
        $finalIndex = count($installments) - 1;
        $installments[$finalIndex]['due_date'] = $termEndDate->format('Y-m-d');
    }

    return $installments;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // support multiple POST actions; generate_agreement is used to create a loan agreement for the borrower
    $postAction = trim($_POST['action'] ?? '');
    if ($postAction === 'generate_agreement') {
        $appId = (int)($_POST['application_id'] ?? 0);
        if ($appId <= 0) {
            $_SESSION['loan_release_message'] = 'Invalid application selected.';
            $_SESSION['loan_release_type'] = 'danger';
            header('Location: loan_release_lending.php');
            exit;
        }

        $application = executeQuery('SELECT * FROM loan_applications WHERE id = ? LIMIT 1', [$appId])->fetch(PDO::FETCH_ASSOC);
        if (!$application) {
            $_SESSION['loan_release_message'] = 'Application not found.';
            $_SESSION['loan_release_type'] = 'danger';
            header('Location: loan_release_lending.php');
            exit;
        }

        $borrowerId = (int)($application['user_id'] ?? 0);
        $existingAgreement = executeQuery('SELECT id FROM loan_agreements WHERE application_id = ? ORDER BY id DESC LIMIT 1', [$appId])->fetch(PDO::FETCH_ASSOC);
        if (!$existingAgreement) {
            $agreementText = generateLoanAgreementText($application);
            executeQuery('INSERT INTO loan_agreements (application_id, borrower_user_id, agreement_status, agreement_text, created_at) VALUES (?, ?, ?, ?, NOW())', [$appId, $borrowerId, 'Generated', $agreementText]);
        }
        executeQuery('UPDATE loan_applications SET status = ? WHERE id = ?', ['Ready for Release', $appId]);
        addNotification($borrowerId, 'Loan Agreement Generated', 'A loan agreement has been generated for your application. Please review and accept it.');

        $_SESSION['loan_release_message'] = 'Loan agreement generated and borrower notified.';
        $_SESSION['loan_release_type'] = 'success';
        header('Location: loan_release_lending.php?view_id=' . $appId);
        exit;
    }

    $applicationId = (int)($_POST['application_id'] ?? 0);
    $collectorId = (int)($_POST['collector_id'] ?? 0);
    $paymentFrequency = trim((string)($_POST['payment_frequency'] ?? ''));

    if ($applicationId <= 0) {
        $message = 'Please choose a loan application to release.';
        $messageType = 'danger';
    } else if ($collectorId <= 0) {
        $message = 'Please assign a collector before releasing the loan.';
        $messageType = 'danger';
    } else {
        $application = executeQuery(
            'SELECT la.*, u.status AS borrower_status, u.user_id AS borrower_user_id, u.full_name AS borrower_full_name FROM loan_applications la LEFT JOIN users u ON u.user_id = la.user_id WHERE la.id = ? LIMIT 1',
            [$applicationId]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$application) {
            $message = 'The selected loan application was not found.';
            $messageType = 'danger';
        } else {
            $agreementRow = executeQuery('SELECT agreement_status, signed_at FROM loan_agreements WHERE application_id = ? ORDER BY id DESC LIMIT 1', [$applicationId])->fetch(PDO::FETCH_ASSOC);
            $agreementStatus = trim((string)($agreementRow['agreement_status'] ?? $application['agreement_status'] ?? 'Pending'));
            $canRelease = in_array(strtolower((string)($application['status'] ?? '')), ['approved', 'agreement accepted', 'ready for release'], true)
                && in_array(strtolower($agreementStatus), ['generated', 'accepted', 'agreed'], true);

            if (!$canRelease) {
                $message = 'This loan is not eligible for release yet because the application is not ready for release or the agreement has not been generated/accepted.';
                $messageType = 'danger';
            } else {
                $collector = executeQuery('SELECT user_id, full_name, employee_id FROM users WHERE user_id = ? AND role = ? AND status = ? LIMIT 1', [$collectorId, 'Collector', 'Active'])->fetch(PDO::FETCH_ASSOC);
                if (!$collector) {
                    $message = 'The selected collector is not active.';
                    $messageType = 'danger';
                } else {
                    $loanAmount = (float)($application['loan_amount'] ?? 0);
                    $interestRate = (float)($application['approved_interest_rate'] ?? 5.0);
                    $loanTermMonths = parseLoanTermMonths($application['loan_term'] ?? 12);
                    $releaseDate = date('Y-m-d');
                    $paymentFrequency = trim((string)($application['payment_frequency'] ?? $paymentFrequency));
                    if ($paymentFrequency === '') {
                        $paymentFrequency = 'Monthly';
                    }
                    $totalInterest = $loanAmount * ($interestRate / 100) * $loanTermMonths;
                    $totalPayable = $loanAmount + $totalInterest;
                    $loanNumber = generateLoanNumber();

                    $borrowerUserId = (int)($application['borrower_user_id'] ?? 0);
                    $borrowerEmail = trim((string)($application['email_address'] ?? ''));
                    $borrowerContact = trim((string)($application['mobile_number'] ?? ''));
                    $borrowerFullName = trim((string)($application['borrower_full_name'] ?? $application['borrower_name'] ?? 'Borrower'));
                    $borrowerNameParts = preg_split('/\s+/', $borrowerFullName, 2);
                    $firstName = trim((string)($borrowerNameParts[0] ?? 'Borrower'));
                    $lastName = trim((string)($borrowerNameParts[1] ?? ''));
                    $clientFullName = trim($firstName . ' ' . $lastName);

                    $clientLookup = executeQuery(
                        'SELECT client_id FROM clients WHERE (user_id = ? OR email = ? OR contact = ?) LIMIT 1',
                        [$borrowerUserId > 0 ? $borrowerUserId : -1, $borrowerEmail !== '' ? $borrowerEmail : '##NO_MATCH##', $borrowerContact !== '' ? $borrowerContact : '##NO_MATCH##']
                    )->fetch(PDO::FETCH_ASSOC);

                    $clientId = null;
                    if ($clientLookup) {
                        $clientId = (int)($clientLookup['client_id'] ?? 0);
                        executeQuery('UPDATE clients SET collector_id = ?, user_id = ?, first_name = ?, last_name = ?, address = ?, contact = ?, email = ? WHERE client_id = ?', [
                            $collectorId,
                            $borrowerUserId > 0 ? $borrowerUserId : null,
                            $firstName,
                            $lastName,
                            trim((string)($application['complete_address'] ?? '')),
                            $borrowerContact,
                            $borrowerEmail,
                            $clientId,
                        ]);
                    } else {
                        $clientId = (int)insert('clients', [
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                            'address' => trim((string)($application['complete_address'] ?? '')),
                            'contact' => $borrowerContact,
                            'email' => $borrowerEmail,
                            'collector_id' => $collectorId,
                            'user_id' => $borrowerUserId > 0 ? $borrowerUserId : null,
                            'date_registered' => date('Y-m-d'),
                        ]);
                    }

                    $clientInfo = executeQuery('SELECT first_name, last_name FROM clients WHERE client_id = ? LIMIT 1', [$clientId])->fetch(PDO::FETCH_ASSOC);
                    $borrowerDisplayName = trim((string)($clientInfo['first_name'] ?? '') . ' ' . (string)($clientInfo['last_name'] ?? ''));
                    if ($borrowerDisplayName === '') {
                        $borrowerDisplayName = ($application['borrower_full_name'] ?? $application['borrower_name'] ?? 'Borrower');
                    }

                    $releaseId = executeQuery(
                        'INSERT INTO loan_releases (application_id, borrower_user_id, collector_user_id, loan_number, loan_amount, interest_rate, loan_term, payment_frequency, release_status, released_at, released_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)',
                        [
                            $applicationId,
                            (int)($application['borrower_user_id'] ?? 0),
                            $collectorId,
                            $loanNumber,
                            $loanAmount,
                            $interestRate,
                            $application['loan_term'] ?? '12 months',
                            $paymentFrequency,
                            'Released',
                            $user['full_name'] ?? $user['username'] ?? 'Admin',
                        ]
                    );
                    $releaseId = (int)$conn->lastInsertId();

                    $paymentSchedule = buildPaymentSchedule($loanAmount, $interestRate, $loanTermMonths, $paymentFrequency, $releaseDate, $releaseId);
                    $finalDueDate = $paymentSchedule[count($paymentSchedule) - 1]['due_date'] ?? $releaseDate;
                    $scheduledPayment = calculateAmountToCollect($totalPayable, $paymentFrequency, $loanTermMonths, null, $releaseDate, $finalDueDate);

                    $insertLoanResult = executeQuery(
                        'INSERT INTO loans (client_id, loan_amount, interest, total_payable, daily_payment, date_released, due_date, status, collector_id, loan_number, release_id, payment_frequency, agreement_status, released_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $clientId,
                            $loanAmount,
                            $interestRate,
                            $totalPayable,
                            $scheduledPayment,
                            $releaseDate,
                            $finalDueDate,
                            'Active',
                            $collectorId,
                            $loanNumber,
                            $releaseId,
                            $paymentFrequency,
                            'Accepted',
                            $user['full_name'] ?? $user['username'] ?? 'Admin'
                        ]
                    );
                    $loanId = (int)$conn->lastInsertId();

                    foreach ($paymentSchedule as $installment) {
                        executeQuery(
                            'INSERT INTO loan_payment_schedules (release_id, installment_number, due_date, principal_amount, interest_amount, total_amount_due, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
                            [
                                $installment['release_id'],
                                $installment['installment_number'],
                                $installment['due_date'],
                                $installment['principal_amount'],
                                $installment['interest_amount'],
                                $installment['total_amount_due'],
                                $installment['status'],
                            ]
                        );
                    }

                    executeQuery('UPDATE loan_applications SET status = ?, remarks = ?, reviewed_by = ?, reviewed_at = NOW(), agreement_status = ? WHERE id = ?', ['Approved', 'Loan released and activated.', $user['full_name'] ?? $user['username'] ?? 'Admin', 'Accepted', $applicationId]);

                    $firstDueDate = $paymentSchedule[0]['due_date'] ?? $releaseDate;
                    $borrowerDisplayName = $clientFullName ?: ($application['borrower_full_name'] ?? $application['borrower_name'] ?? 'Borrower');
                    addNotification((int)($application['borrower_user_id'] ?? 0), 'Congratulations! Your loan has been released.', 'Loan Number: ' . $loanNumber . ' | Assigned Collector: ' . ($collector['full_name'] ?? 'Assigned') . ' | First Payment Due Date: ' . $firstDueDate);
                    addNotification($collectorId, 'You have been assigned a new borrower.', 'Borrower Name: ' . $borrowerDisplayName . ' | Loan Number: ' . $loanNumber . ' | First Due Date: ' . $firstDueDate);
                    addNotification((int)$user['user_id'], 'Loan successfully released.', 'Loan Number: ' . $loanNumber . ' was successfully released and activated.');

                    $_SESSION['loan_release_message'] = 'Loan successfully released and activated.';
                    $_SESSION['loan_release_type'] = 'success';
                    header('Location: loan_release_lending.php');
                    exit;
                }
            }
        }
    }
}

$search = trim($_GET['search'] ?? '');
$agreementFilter = trim($_GET['agreement'] ?? 'all');
$viewId = (int)($_GET['view_id'] ?? 0);
$releaseId = (int)($_GET['release_id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$where = "la.status IN ('Approved', 'Agreement Accepted', 'Ready for Release') AND u.status = 'Active' AND NOT EXISTS (SELECT 1 FROM loan_releases lr WHERE lr.application_id = la.id)";
$params = [];

if ($search !== '') {
    $where .= ' AND (la.borrower_name LIKE ? OR la.loan_type LIKE ? OR la.loan_purpose LIKE ? OR la.email_address LIKE ?)';
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

if ($agreementFilter !== '' && $agreementFilter !== 'all') {
    $where .= ' AND (SELECT agreement_status FROM loan_agreements WHERE application_id = la.id ORDER BY id DESC LIMIT 1) = ?';
    $params[] = ucfirst($agreementFilter);
}

$countStmt = executeQuery("SELECT COUNT(*) AS total FROM loan_applications la LEFT JOIN users u ON u.user_id = la.user_id WHERE $where", $params);
$totalApplications = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalApplications / $perPage));

$applications = executeQuery(
    "SELECT la.*, u.full_name AS borrower_name, u.status AS borrower_status, u.user_id AS borrower_user_id, (SELECT agreement_status FROM loan_agreements WHERE application_id = la.id ORDER BY id DESC LIMIT 1) AS agreement_status_value FROM loan_applications la LEFT JOIN users u ON u.user_id = la.user_id WHERE $where ORDER BY la.submitted_at DESC LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
)->fetchAll(PDO::FETCH_ASSOC);

$activeCollectors = executeQuery("SELECT user_id, full_name, employee_id FROM users WHERE role = 'Collector' AND status = 'Active' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$selectedApplication = null;
if ($viewId > 0 || $releaseId > 0) {
    $selectedId = $viewId > 0 ? $viewId : $releaseId;
    $selectedApplication = executeQuery(
        'SELECT la.*, u.full_name AS borrower_name, u.status AS borrower_status, u.user_id AS borrower_user_id, (SELECT agreement_status FROM loan_agreements WHERE application_id = la.id ORDER BY id DESC LIMIT 1) AS agreement_status_value FROM loan_applications la LEFT JOIN users u ON u.user_id = la.user_id WHERE la.id = ? LIMIT 1',
        [$selectedId]
    )->fetch(PDO::FETCH_ASSOC);

    if ($selectedApplication) {
        $agreementRow = executeQuery('SELECT agreement_status, signed_at FROM loan_agreements WHERE application_id = ? ORDER BY id DESC LIMIT 1', [$selectedId])->fetch(PDO::FETCH_ASSOC);
        $selectedApplication['agreement_status_value'] = trim((string)($agreementRow['agreement_status'] ?? $selectedApplication['agreement_status_value'] ?? 'Pending'));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Release - Lending Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/lending_styles.css">
</head>
<body>
    <?php include 'includes/nav_lending.php'; ?>

    <div class="container-fluid py-4">
        <div class="page-hero">
            <div>
                <h1 class="page-title"><i class="fas fa-file-contract me-2"></i>Loan Release</h1>
                <p class="page-subtitle">Review approved borrower applications, assign a collector, and release loans securely.</p>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?php echo htmlspecialchars($messageType === 'success' ? 'success' : ($messageType === 'warning' ? 'warning' : 'danger')); ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <div class="col-xl-4">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <div class="metric-label">Pending Releases</div>
                                <div class="metric-value"><?php echo number_format($totalApplications); ?></div>
                            </div>
                            <div class="metric-icon bg-primary-subtle text-primary"><i class="fas fa-hourglass-half"></i></div>
                        </div>
                        <p class="text-muted mb-0">Approved applications that are ready for loan activation and collector assignment.</p>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <div class="metric-label">Active Collectors</div>
                                <div class="metric-value"><?php echo count($activeCollectors); ?></div>
                            </div>
                            <div class="metric-icon bg-success-subtle text-success"><i class="fas fa-user-tie"></i></div>
                        </div>
                        <p class="text-muted mb-0">Only active collectors are displayed in the release assignment dropdown.</p>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <div class="metric-label">Release Workflow</div>
                                <div class="metric-value">Protected</div>
                            </div>
                            <div class="metric-icon bg-warning-subtle text-warning"><i class="fas fa-shield-alt"></i></div>
                        </div>
                        <p class="text-muted mb-0">Only administrators can activate a released loan and assign a collector.</p>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($selectedApplication): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 fw-bold"><i class="fas fa-clipboard-list me-2"></i>Release Preparation</h6>
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-lg-7">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="border rounded-4 p-3 h-100">
                                        <div class="text-muted small">Borrower</div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($selectedApplication['borrower_name'] ?? 'N/A'); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="border rounded-4 p-3 h-100">
                                        <div class="text-muted small">Loan Amount</div>
                                        <div class="fw-semibold">₱<?php echo number_format((float)($selectedApplication['loan_amount'] ?? 0), 2); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="border rounded-4 p-3 h-100">
                                        <div class="text-muted small">Interest Rate</div>
                                        <div class="fw-semibold"><?php echo number_format((float)($selectedApplication['approved_interest_rate'] ?? 5.0), 2); ?>%</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="border rounded-4 p-3 h-100">
                                        <div class="text-muted small">Agreement Status</div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($selectedApplication['agreement_status_value'] ?? 'Pending'); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="border rounded-4 p-3 h-100">
                                        <div class="text-muted small">Release Status</div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars(getReleaseStatusLabel($selectedApplication['status'] ?? 'Pending')); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <form method="post" onsubmit="return confirm('Release this loan and activate the payment schedule?');">
                                <input type="hidden" name="application_id" value="<?php echo (int)$selectedApplication['id']; ?>">
                                <div class="mb-3">
                                    <label class="form-label">Assign Collector</label>
                                    <input type="text" class="form-control mb-2" id="collectorSearch" placeholder="Search collector by name or employee ID" autocomplete="off">
                                    <select class="form-select" name="collector_id" id="collectorSelect" required>
                                        <option value="">Select a collector</option>
                                        <?php foreach ($activeCollectors as $collector): ?>
                                            <option value="<?php echo (int)$collector['user_id']; ?>" data-search="<?php echo htmlspecialchars(strtolower(($collector['full_name'] ?? '') . ' ' . ($collector['employee_id'] ?? ''))); ?>">
                                                <?php echo htmlspecialchars($collector['full_name'] ?? 'Collector'); ?> - <?php echo htmlspecialchars($collector['employee_id'] ?? 'N/A'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Only one collector can be assigned for each released loan.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Payment Frequency</label>
                                    <?php $releasePaymentFrequency = trim((string)($selectedApplication['payment_frequency'] ?? 'Monthly')); ?>
                                    <select class="form-select" name="payment_frequency" required>
                                        <option value="Daily" <?php echo $releasePaymentFrequency === 'Daily' ? 'selected' : ''; ?>>Daily</option>
                                        <option value="Weekly" <?php echo $releasePaymentFrequency === 'Weekly' ? 'selected' : ''; ?>>Weekly</option>
                                        <option value="Semi-Monthly" <?php echo $releasePaymentFrequency === 'Semi-Monthly' || $releasePaymentFrequency === 'Every Half of the Month' ? 'selected' : ''; ?>>Semi-Monthly</option>
                                        <option value="Monthly" <?php echo $releasePaymentFrequency === 'Monthly' ? 'selected' : ''; ?>>Monthly</option>
                                    </select>
                                </div>
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-check-circle me-2"></i>Release Loan</button>
                                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#generateAgreementModal"><i class="fas fa-file-contract me-2"></i>Approve & Generate Agreement</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-header bg-white py-3 d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2">
                <div>
                    <h6 class="m-0 fw-bold"><i class="fas fa-list me-2"></i>Eligible Loan Applications</h6>
                    <div class="text-muted small">Applications that meet the release prerequisites are listed below.</div>
                </div>
                <form method="get" class="d-flex flex-wrap gap-2 align-items-center">
                    <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search borrower or loan type">
                    <select class="form-select" name="agreement">
                        <option value="all" <?php echo $agreementFilter === 'all' ? 'selected' : ''; ?>>All Agreements</option>
                        <option value="accepted" <?php echo $agreementFilter === 'accepted' ? 'selected' : ''; ?>>Accepted</option>
                        <option value="pending" <?php echo $agreementFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    </select>
                    <button type="submit" class="btn btn-outline-primary"><i class="fas fa-search me-2"></i>Search</button>
                </form>
            </div>
            <div class="card-body p-0">
                <?php if (empty($applications)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-folder-open fa-3x text-muted mb-3"></i>
                        <h6 class="fw-bold">No loans are waiting to be released</h6>
                        <p class="text-muted mb-0">Approved applications with accepted agreements will appear here immediately.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Loan Number</th>
                                    <th>Borrower</th>
                                    <th>Loan Type</th>
                                    <th>Loan Amount</th>
                                    <th>Loan Term</th>
                                    <th>Interest Rate</th>
                                    <th>Agreement Status</th>
                                    <th>Release Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($applications as $application): ?>
                                    <?php $agreementStatusValue = trim((string)($application['agreement_status_value'] ?? 'Pending')); ?>
                                    <tr>
                                        <td><span class="fw-semibold text-primary">Pending Release</span></td>
                                        <td><?php echo htmlspecialchars($application['borrower_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($application['loan_type'] ?? 'N/A'); ?></td>
                                        <td>₱<?php echo number_format((float)($application['loan_amount'] ?? 0), 2); ?></td>
                                        <td><?php echo htmlspecialchars($application['loan_term'] ?? 'N/A'); ?></td>
                                        <td><?php echo number_format((float)($application['approved_interest_rate'] ?? 5.0), 2); ?>%</td>
                                        <td>
                                            <?php if (strcasecmp($agreementStatusValue, 'Accepted') === 0): ?>
                                                <span class="badge bg-success">Accepted</span>
                                            <?php elseif (strcasecmp($agreementStatusValue, 'Declined') === 0): ?>
                                                <span class="badge bg-danger">Declined</span>
                                            <?php elseif (strcasecmp($agreementStatusValue, 'Generated') === 0 || strcasecmp($agreementStatusValue, 'Pending') === 0): ?>
                                                <span class="badge bg-warning text-dark"><?php echo htmlspecialchars($agreementStatusValue); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-info text-dark"><?php echo htmlspecialchars($agreementStatusValue); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <?php $releaseStatusValue = getReleaseStatusLabel($application['status'] ?? 'Pending'); ?>
                                        <td><span class="badge <?php echo htmlspecialchars(getReleaseStatusBadgeClass($application['status'] ?? 'Pending')); ?>"><?php echo htmlspecialchars($releaseStatusValue); ?></span></td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-2">
                                                <a href="loan_release_lending.php?view_id=<?php echo (int)$application['id']; ?>" class="btn btn-outline-primary btn-sm"><i class="fas fa-eye me-1"></i>View Details</a>
                                                <a href="loan_release_lending.php?release_id=<?php echo (int)$application['id']; ?>" class="btn btn-success btn-sm"><i class="fas fa-check-circle me-1"></i>Release Loan</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="loan_release_lending.php?search=<?php echo urlencode($search); ?>&agreement=<?php echo urlencode($agreementFilter); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>

    <!-- Generate Agreement Modal -->
    <div class="modal fade" id="generateAgreementModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="action" value="generate_agreement">
                    <input type="hidden" name="application_id" value="<?php echo isset($selectedApplication['id']) ? (int)$selectedApplication['id'] : ''; ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">Approve Loan Application</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>By clicking <strong>"Approve"</strong>, you confirm that this loan application has been reviewed and approved. The Loan Agreement will be automatically generated using the approved application details and made available to the borrower for electronic acceptance.</p>
                        <p>Please ensure that all applicant information, loan amount, repayment terms, and other loan details are accurate before proceeding.</p>
                        <p class="fw-semibold">Do you want to continue?</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Approve & Generate Agreement</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const searchInput = document.getElementById('collectorSearch');
            const collectorSelect = document.getElementById('collectorSelect');
            if (searchInput && collectorSelect) {
                searchInput.addEventListener('input', function () {
                    const query = this.value.toLowerCase();
                    Array.from(collectorSelect.options).forEach(function (option) {
                        if (option.value === '') {
                            return;
                        }
                        const haystack = (option.getAttribute('data-search') || '').toLowerCase();
                        option.hidden = haystack.indexOf(query) === -1;
                    });
                });
            }
        });
    </script>
</body>
</html>
