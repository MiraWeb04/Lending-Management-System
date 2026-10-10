<?php
/**
 * Borrower Dashboard for RJ and RR Finance Services
 */

require_once 'includes/auth_lending.php';
require_once 'includes/db_lending.php';
require_once 'includes/ui_helpers.php';
require_once 'includes/notification_ui_helpers.php';
require_once 'includes/loan_helpers.php';

ensureNotificationsSchema();

if (!isLoggedIn()) {
    header('Location: borrower_login_lending.php');
    exit;
}

if (!isBorrower()) {
    header('Location: ' . getDashboardRedirectUrl(getCurrentUser()));
    exit;
}

$user = getCurrentUser();

$documentReuploadMessage = '';
$documentReuploadMessageType = 'success';
if (isset($_GET['doc_reupload']) && $_GET['doc_reupload'] === 'success') {
    $documentReuploadMessage = 'Your document was uploaded successfully and is pending review.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && trim((string)($_POST['action'] ?? '')) === 'reupload_document') {
    $documentId = (int)($_POST['document_id'] ?? 0);
    $upload = $_FILES['document_file'] ?? null;

    if ($documentId <= 0 || !is_array($upload) || empty($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
        $documentReuploadMessage = 'Please choose a valid file to upload.';
        $documentReuploadMessageType = 'danger';
    } else {
        $documentRow = executeQuery(
            'SELECT d.id, d.document_name, d.application_id, d.verification_status, la.user_id
             FROM loan_application_documents d
             INNER JOIN loan_applications la ON la.id = d.application_id
             WHERE d.id = ? AND la.user_id = ? LIMIT 1',
            [$documentId, (int)$user['user_id']]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$documentRow || strcasecmp(trim((string)($documentRow['verification_status'] ?? '')), 'Rejected') !== 0) {
            $documentReuploadMessage = 'This document is not eligible for re-upload.';
            $documentReuploadMessageType = 'danger';
        } else {
            $originalName = trim((string)($upload['name'] ?? ''));
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
            if (!in_array($extension, $allowedExtensions, true)) {
                $documentReuploadMessage = 'Only JPG, PNG, or PDF files are allowed.';
                $documentReuploadMessageType = 'danger';
            } else {
                $uploadDir = __DIR__ . '/uploads/loan_documents';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $destinationFileName = time() . '_' . bin2hex(random_bytes(8)) . '_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', basename($originalName));
                $destinationPath = $uploadDir . '/' . $destinationFileName;
                if (!move_uploaded_file($upload['tmp_name'], $destinationPath)) {
                    $documentReuploadMessage = 'Unable to save the uploaded file. Please try again.';
                    $documentReuploadMessageType = 'danger';
                } else {
                    $relativePath = 'uploads/loan_documents/' . $destinationFileName;
                    $applicationId = (int)($documentRow['application_id'] ?? 0);
                    executeQuery(
                        'UPDATE loan_application_documents SET document_path = ?, verification_status = ?, rejection_reason = NULL, uploaded_at = NOW() WHERE id = ?',
                        [$relativePath, 'Pending Review', $documentId]
                    );

                    $remainingRejected = (int)(executeQuery(
                        'SELECT COUNT(*) FROM loan_application_documents WHERE application_id = ? AND verification_status = ?',
                        [$applicationId, 'Rejected']
                    )->fetchColumn() ?: 0);

                    if ($remainingRejected === 0) {
                        executeQuery(
                            "UPDATE loan_applications SET status = 'Pending Review' WHERE id = ? AND status IN ('Documents Incomplete', 'Under Review')",
                            [$applicationId]
                        );
                    }

                    $documentLabel = trim((string)($documentRow['document_name'] ?? 'Document'));
                    notifyAdmins('Borrower Re-uploaded Document', 'Borrower re-uploaded "' . $documentLabel . '" for application #' . $applicationId . '.');
                    addNotification((int)$user['user_id'], 'Document Uploaded', 'Your updated "' . $documentLabel . '" was received and is pending review.');

                    header('Location: borrower_dashboard_lending.php?doc_reupload=success');
                    exit;
                }
            }
        }
    }
}

function lendingDashboardFetchRow($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;
}

function lendingDashboardFetchRows($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

// Ensure notifications schema exists before using notification helpers.
ensureNotificationsSchema();

$application = lendingDashboardFetchRow('SELECT * FROM loan_applications WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$user['user_id']]) ?: [];
$client = lendingDashboardFetchRow('SELECT client_id FROM clients WHERE user_id = ? LIMIT 1', [$user['user_id']]);
$activeLoan = null;
$latestLoan = null;
$loanCompleted = null;
$paymentSummary = ['total_paid' => 0, 'payment_count' => 0];
$remainingBalance = 0;
$nextDueDate = null;
$recentPayments = [];
$loanOfficer = null;
$applicationStatus = trim((string)($application['status'] ?? ''));
$applicationSubmittedOn = !empty($application['submitted_at']) ? date('M d, Y', strtotime($application['submitted_at'])) : null;
$applicationDocuments = [];
$missingDocuments = [];
$rejectedDocuments = [];
$loanStage = 'no_application';
$loanStageLabel = 'No Application';
$loanStatus = null;
$loanProgressStages = [
    ['key' => 'pending', 'label' => 'Application Submitted', 'icon' => 'fa-file-signature'],
    ['key' => 'under_review', 'label' => 'Under Review', 'icon' => 'fa-search'],
    ['key' => 'requires_documents', 'label' => 'Requires Additional Documents', 'icon' => 'fa-file-circle-exclamation'],
    ['key' => 'approved', 'label' => 'Approved', 'icon' => 'fa-thumbs-up'],
    ['key' => 'released', 'label' => 'Funds Released', 'icon' => 'fa-hand-holding-dollar'],
    ['key' => 'active', 'label' => 'Active Loan', 'icon' => 'fa-wallet'],
    ['key' => 'fully_paid', 'label' => 'Fully Paid', 'icon' => 'fa-star'],
];
$loanProgressSteps = [];
$trackingNewApplication = false;

$borrowerLoanHistory = fetchBorrowerLoanHistoryForUser((int)$user['user_id']);
if (!empty($borrowerLoanHistory)) {
    $latestLoan = $borrowerLoanHistory[0];
    $loanStatus = strtolower(trim((string)$latestLoan['status']));
    foreach ($borrowerLoanHistory as $loanCandidate) {
        $candidateStatus = strtolower(trim((string)($loanCandidate['status'] ?? '')));
        if (in_array($candidateStatus, ['active', 'overdue'], true)) {
            $activeLoan = $loanCandidate;
            break;
        }
    }
    if (!$activeLoan && $loanStatus === 'paid') {
        $loanCompleted = $latestLoan;
    }

    $collectorLoan = $activeLoan ?? $latestLoan;
    if (!empty($collectorLoan['collector_id'])) {
        $collectorRow = lendingDashboardFetchRow('SELECT full_name FROM users WHERE user_id = ? LIMIT 1', [(int)$collectorLoan['collector_id']]);
        $loanOfficer = $collectorRow['full_name'] ?? null;
    }
}

$historyLoan = $activeLoan ?? $loanCompleted ?? $latestLoan;

if ($historyLoan) {
    $recentPayments = lendingDashboardFetchRows('SELECT payment_date, amount_paid, receipt_number FROM payments WHERE loan_id = ? ORDER BY payment_date DESC, payment_id DESC LIMIT 5', [$historyLoan['loan_id']]);
}

if ($application) {
    $applicationDocuments = lendingDashboardFetchRows('SELECT * FROM loan_application_documents WHERE application_id = ? ORDER BY id ASC', [$application['id']]);
    if (empty($applicationDocuments)) {
        foreach (['Valid Government ID', 'Proof of Income', 'Proof of Billing', 'Selfie Holding Valid ID', 'Additional Supporting Documents'] as $documentName) {
            $applicationDocuments[] = [
                'document_name' => $documentName,
                'document_path' => null,
                'verification_status' => 'Missing',
                'rejection_reason' => null
            ];
        }
    }
    foreach ($applicationDocuments as $doc) {
        $docStatus = strtolower(trim((string)($doc['verification_status'] ?? '')));
        if ($docStatus === 'verified') {
            continue;
        }
        if ($docStatus === 'rejected') {
            $rejectedDocuments[] = $doc;
            continue;
        }
        $missingDocuments[] = $doc;
    }
}

if ($application || $latestLoan) {
    $statusKey = strtolower(trim($application['status'] ?? ''));
    $loanStage = 'pending';

    $documentVerification = analyzeLoanApplicationDocumentVerification($applicationDocuments);

    if ($statusKey === 'rejected') {
        $loanStage = 'rejected';
    } elseif (in_array($statusKey, ['documents incomplete', 'requires additional documents'], true) || $documentVerification['has_rejected']) {
        $loanStage = 'requires_documents';
    } elseif ($statusKey === 'under review' || $documentVerification['ready_for_under_review']) {
        $loanStage = 'under_review';
    } elseif (in_array($statusKey, ['approved', 'waiting for loan agreement', 'agreement accepted', 'ready for release'], true)) {
        $loanStage = 'approved';
    } elseif ($statusKey === 'released') {
        $loanStage = 'released';
    } elseif ($documentVerification['uploaded_count'] > 0 && !$documentVerification['ready_for_under_review']) {
        $loanStage = 'pending';
    }

    $trackingNewApplication = !empty($application['id'])
        && borrowerLatestApplicationIsNewerThanLoan($application, $latestLoan)
        && borrowerHasTrackableLoanApplication((int)$user['user_id']);

    if ($latestLoan && !$trackingNewApplication) {
        if ($loanStatus === 'paid') {
            $loanStage = 'fully_paid';
        } elseif (in_array($loanStatus, ['active', 'overdue'], true)) {
            $loanStage = 'active';
        } elseif ($loanStage === 'approved' || $statusKey === 'released') {
            $loanStage = 'released';
        }
    }

    $stageLabels = [
        'pending' => 'Pending Review',
        'under_review' => 'Under Review',
        'requires_documents' => 'Requires Additional Documents',
        'approved' => 'Approved',
        'released' => 'Funds Released',
        'active' => 'Active Loan',
        'fully_paid' => 'Completed',
        'rejected' => 'Rejected',
    ];
    $loanStageLabel = $stageLabels[$loanStage] ?? ucfirst(str_replace('_', ' ', $loanStage));

    $stageDefinitions = [
        'pending' => ['key' => 'pending', 'label' => 'Application Submitted', 'icon' => 'fa-file-signature'],
        'under_review' => ['key' => 'under_review', 'label' => 'Under Review', 'icon' => 'fa-search'],
        'requires_documents' => ['key' => 'requires_documents', 'label' => 'Requires Additional Documents', 'icon' => 'fa-file-circle-exclamation'],
        'approved' => ['key' => 'approved', 'label' => 'Approved', 'icon' => 'fa-thumbs-up'],
        'released' => ['key' => 'released', 'label' => 'Funds Released', 'icon' => 'fa-hand-holding-dollar'],
        'active' => ['key' => 'active', 'label' => 'Active Loan', 'icon' => 'fa-wallet'],
        'fully_paid' => ['key' => 'fully_paid', 'label' => 'Fully Paid', 'icon' => 'fa-star'],
        'rejected' => ['key' => 'rejected', 'label' => 'Rejected', 'icon' => 'fa-ban'],
    ];

    if ($loanStage === 'rejected') {
        $stageOrder = ['pending', 'under_review', 'rejected'];
    } else {
        $stageOrder = ['pending', 'under_review', 'requires_documents', 'approved', 'released', 'active', 'fully_paid'];
    }

    foreach ($stageOrder as $stageKey) {
        if ($stageKey === 'requires_documents' && !in_array($statusKey, ['documents incomplete', 'requires additional documents'], true)) {
            continue;
        }
        if ($stageKey === 'approved' && !in_array($statusKey, ['approved', 'waiting for loan agreement', 'agreement accepted', 'ready for release', 'released'], true) && !$latestLoan) {
            continue;
        }
        if ($stageKey === 'released' && !$latestLoan && $statusKey !== 'released') {
            continue;
        }
        if ($stageKey === 'active' && !$latestLoan) {
            continue;
        }
        if ($stageKey === 'fully_paid' && $loanStage !== 'fully_paid') {
            continue;
        }
        $loanProgressSteps[] = $stageDefinitions[$stageKey];
        if ($stageKey === $loanStage) {
            break;
        }
    }
}

if ($activeLoan) {
    $paymentSummary = lendingDashboardFetchRow('SELECT COALESCE(SUM(amount_paid), 0) AS total_paid, COUNT(*) AS payment_count FROM payments WHERE loan_id = ?', [$activeLoan['loan_id']]);
    $paymentSummary['total_paid'] = (float)($paymentSummary['total_paid'] ?? 0);
    $paymentSummary['payment_count'] = (int)($paymentSummary['payment_count'] ?? 0);
    $remainingBalance = max(0, (float)$activeLoan['total_payable'] - $paymentSummary['total_paid']);
    $nextDueRow = lendingDashboardFetchRow('SELECT due_date FROM loan_payment_schedules WHERE release_id = ? AND status != ? ORDER BY due_date ASC LIMIT 1', [(int)$activeLoan['release_id'], 'Paid']);
    $nextDueDate = $nextDueRow ? date('M d, Y', strtotime($nextDueRow['due_date'])) : null;
    $recentPayments = lendingDashboardFetchRows('SELECT payment_date, amount_paid, receipt_number FROM payments WHERE loan_id = ? ORDER BY payment_date DESC, payment_id DESC LIMIT 5', [$activeLoan['loan_id']]);
}

$notifications = getUserNotifications($user['user_id'], 5);
$unreadNotificationCount = getUnreadNotificationCount($user['user_id']);
$borrowerCanApplyForLoan = borrowerCanApplyForNewLoan((int)$user['user_id']);

function formatCurrency($value) {
    if ($value === '' || $value === null) {
        return 'No Active Loan';
    }
    return '₱' . number_format((float)$value, 2);
}

function formatDate($date) {
    return $date ? date('M d, Y', strtotime($date)) : 'Not Available';
}

function statusBadgeClass($label) {
    switch (strtolower(trim((string)$label))) {
        case 'pending review':
        case 'under review':
        case 'requires additional documents':
            return 'bg-info text-dark';
        case 'approved':
        case 'released':
        case 'active':
        case 'completed':
            return 'bg-success';
        case 'rejected':
            return 'bg-danger';
        default:
            return 'bg-secondary';
    }
}

$borrowerName = htmlspecialchars($user['full_name'] ?? $user['username'] ?? 'Borrower');
$paymentProgress = 0;
if ($activeLoan && !empty($activeLoan['total_payable']) && (float)$activeLoan['total_payable'] > 0) {
    $paymentProgress = round((float)$paymentSummary['total_paid'] / (float)$activeLoan['total_payable'] * 100);
    if ($paymentProgress > 100) {
        $paymentProgress = 100;
    }
}

switch ($loanStage) {
    case 'pending':
        $heroTitle = 'Your application is under review';
        $heroMessage = 'Your loan application has been successfully submitted and is waiting for review. We will notify you once there is an update.';
        $heroButton = ['label' => 'View Application Status', 'href' => 'borrower_loan_status_lending.php', 'icon' => 'fa-search'];
        break;
    case 'under_review':
        $heroTitle = 'Your application is being reviewed';
        $heroMessage = 'Your loan application is currently being reviewed by our loan officer. Please wait while we verify your submitted information and documents.';
        $heroButton = ['label' => 'Track Progress', 'href' => 'borrower_loan_status_lending.php', 'icon' => 'fa-chart-line'];
        break;
    case 'requires_documents':
        $heroTitle = 'Additional documents are required';
        $heroMessage = 'Additional documents are required before we can continue processing your application.';
        $heroButton = ['label' => 'Upload Documents', 'href' => 'borrower_loan_status_lending.php', 'icon' => 'fa-upload'];
        break;
    case 'approved':
        $heroTitle = 'Your application has been approved';
        $heroMessage = 'Congratulations! Your loan application has been approved and is waiting for fund release.';
        $heroButton = ['label' => 'View Loan Status', 'href' => 'borrower_loan_status_lending.php', 'icon' => 'fa-check-circle'];
        break;
    case 'released':
        $heroTitle = 'Your loan has been released';
        $heroMessage = 'Your funds have been released. View your loan details, repayment schedule, and payment progress anytime.';
        $heroButton = ['label' => 'View My Loan', 'href' => 'borrower_my_loan_lending.php', 'icon' => 'fa-file-invoice-dollar'];
        break;
    case 'active':
        $heroTitle = 'Your loan is active';
        $heroMessage = 'Your loan is active and payments are being tracked in real time. Stay on schedule to keep your account in good standing.';
        $heroButton = ['label' => 'View Loan Details', 'href' => 'borrower_my_loan_lending.php', 'icon' => 'fa-wallet'];
        break;
    case 'fully_paid':
        $heroTitle = 'Loan completed successfully';
        $heroMessage = 'Congratulations! You have successfully completed your loan. Thank you for choosing RL&RR Lending Management System.';
        if (!empty($trackingNewApplication)) {
            $heroTitle = 'Previous loan completed';
            $heroMessage = 'Your previous loan is fully paid. You can track your new loan application progress from Loan Status.';
            $heroButton = ['label' => 'View Application Status', 'href' => 'borrower_loan_status_lending.php', 'icon' => 'fa-chart-line'];
        } else {
            $heroButton = $borrowerCanApplyForLoan
                ? ['label' => 'Apply for Another Loan', 'href' => 'borrower_loan_application_lending.php', 'icon' => 'fa-plus-circle']
                : ['label' => 'View My Loan', 'href' => 'borrower_my_loan_lending.php', 'icon' => 'fa-file-invoice-dollar'];
        }
        break;
    case 'rejected':
        $heroTitle = 'Application was not approved';
        $heroMessage = 'Your loan application was not approved at this time. Please contact support or submit a new application with updated information.';
        $heroButton = $borrowerCanApplyForLoan
            ? ['label' => 'Apply for New Loan', 'href' => 'borrower_loan_application_lending.php', 'icon' => 'fa-redo-alt']
            : ['label' => 'View My Loan', 'href' => 'borrower_my_loan_lending.php', 'icon' => 'fa-file-invoice-dollar'];
        break;
    default:
        $heroTitle = 'Welcome to RL&RR Lending Management System';
        $heroMessage = $borrowerCanApplyForLoan
            ? "You don't have any loan applications yet. Start your first loan application to begin your borrowing journey."
            : 'You have an active loan that is not fully paid yet. Complete it before starting a new application.';
        $heroButton = $borrowerCanApplyForLoan
            ? ['label' => 'Apply for Loan', 'href' => 'borrower_loan_application_lending.php', 'icon' => 'fa-file-signature']
            : ['label' => 'View My Loan', 'href' => 'borrower_my_loan_lending.php', 'icon' => 'fa-file-invoice-dollar'];
        break;
}

function displayStatusText($value) {
    return $value !== '' && $value !== null ? htmlspecialchars($value) : 'Not Available';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/lending_assets.php'; lendingRenderPwaMeta(); ?>
    <title>Borrower Dashboard - RJ and RR Finance Services</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="css/lending_styles.css">
    <link rel="stylesheet" href="css/pwa.css">
</head>
<body>
    <?php include 'includes/nav_lending.php'; ?>

    <div class="app-content py-2">
        <div class="borrower-portal-hero">
            <div class="dashboard-header mb-0">
                <div>
                    <div class="dashboard-breadcrumb"><a href="borrower_dashboard_lending.php">Home</a> / My dashboard</div>
                    <h1 class="page-title mb-1"><i class="fas fa-house-user me-2"></i>Welcome, <?php echo $borrowerName; ?></h1>
                    <p class="page-subtitle mb-0"><?php echo htmlspecialchars($heroMessage); ?></p>
                </div>
                <div class="quick-actions">
                    <a href="<?php echo htmlspecialchars($heroButton['href']); ?>" class="btn btn-primary btn-sm"><i class="fas <?php echo htmlspecialchars($heroButton['icon']); ?> me-1"></i><?php echo htmlspecialchars($heroButton['label']); ?></a>
                    <?php if ($borrowerCanApplyForLoan): ?>
                        <a href="borrower_loan_application_lending.php" class="btn btn-outline-primary btn-sm">Apply for loan</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4 align-items-stretch">
            <div class="col-xl-4">
                <div class="panel-card panel-card--flush h-100">
                    <div class="chart-panel__header"><h6 class="m-0 fw-bold"><i class="fas fa-clipboard-list me-2"></i>Account snapshot</h6></div>
                    <div class="chart-panel__body">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div>
                                <div class="text-secondary small">Loan Lifecycle</div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($loanStageLabel); ?></div>
                            </div>
                            <span class="badge <?php echo statusBadgeClass($loanStageLabel); ?> py-2 px-3"><?php echo htmlspecialchars($loanStageLabel); ?></span>
                        </div>
                        <?php if ($application && $applicationSubmittedOn): ?>
                            <div class="mb-3">
                                <div class="text-secondary small">Application Date</div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($applicationSubmittedOn); ?></div>
                            </div>
                        <?php endif; ?>
                        <div class="mb-3">
                            <div class="text-secondary small">Assigned Loan Officer</div>
                            <div class="fw-semibold"><?php echo displayStatusText($loanOfficer ?: 'To be assigned'); ?></div>
                        </div>
                        <?php if ($activeLoan): ?>
                            <div class="mb-3">
                                <div class="text-secondary small">Next Payment Due</div>
                                <div class="fw-semibold"><?php echo displayStatusText($nextDueDate ?: 'No Payment Schedule Yet'); ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($loanStage === 'approved'): ?>
                            <div class="mb-3">
                                <div class="text-secondary small">Release Date</div>
                                <div class="fw-semibold">Pending</div>
                            </div>
                        <?php endif; ?>
                        <?php if ($loanStage === 'fully_paid' && $loanCompleted): ?>
                            <div class="mb-3">
                                <div class="text-secondary small">Completion Date</div>
                                <div class="fw-semibold"><?php echo displayStatusText(formatDate($loanCompleted['date_released'] ?? $loanCompleted['release_date'])); ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($activeLoan && $paymentProgress > 0): ?>
                            <div class="mt-3">
                                <div class="small text-muted mb-1">Payment progress</div>
                                <div class="progress-track mb-1"><div class="progress-track__fill" style="width: <?php echo (int)$paymentProgress; ?>%;"></div></div>
                                <div class="small fw-semibold"><?php echo (int)$paymentProgress; ?>% paid</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-xl-8">
                <?php
                $showApplicationProgress = $application && count($loanProgressSteps) > 0 && !in_array($loanStage, ['active', 'released', 'fully_paid'], true);
                ?>
                <?php if ($showApplicationProgress): ?>
                <div class="panel-card panel-card--flush h-100">
                    <div class="chart-panel__header table-panel__toolbar">
                        <div>
                            <h6 class="m-0 fw-bold"><i class="fas fa-route me-2"></i>Application journey</h6>
                            <small class="text-muted">Current stage: <?php echo htmlspecialchars($loanStageLabel); ?></small>
                        </div>
                        <a href="borrower_loan_status_lending.php" class="btn btn-sm btn-outline-primary">View status</a>
                    </div>
                    <div class="chart-panel__body">
                        <?php
                            $currentProgressIndex = 0;
                            $progressKeys = array_column($loanProgressSteps, 'key');
                            $currentStepPosition = array_search($loanStage, $progressKeys, true);
                            if ($currentStepPosition !== false) {
                                $currentProgressIndex = $currentStepPosition + 1;
                            } else {
                                $currentProgressIndex = count($loanProgressSteps);
                            }
                        ?>
                        <div class="borrower-stage-track">
                            <?php foreach ($loanProgressSteps as $index => $step): ?>
                                <?php $stepPosition = $index + 1; ?>
                                <?php $isComplete = $stepPosition <= $currentProgressIndex; ?>
                                <div class="borrower-stage-track__step<?php echo $isComplete ? ' is-active' : ''; ?>">
                                    <div class="borrower-stage-track__icon"><i class="fas <?php echo htmlspecialchars($step['icon']); ?>"></i></div>
                                    <div class="borrower-stage-track__label"><?php echo htmlspecialchars($step['label']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php elseif (in_array($loanStage, ['active', 'released', 'fully_paid'], true) && $historyLoan): ?>
                <div class="panel-card panel-card--flush h-100">
                    <div class="chart-panel__header table-panel__toolbar">
                        <div>
                            <h6 class="m-0 fw-bold"><i class="fas fa-file-invoice-dollar me-2"></i>My loan</h6>
                            <small class="text-muted">Your application progress is complete. Manage your active loan here.</small>
                        </div>
                        <a href="borrower_my_loan_lending.php" class="btn btn-sm btn-primary">Open My Loan</a>
                    </div>
                    <div class="chart-panel__body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="text-secondary small">Loan number</div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($historyLoan['loan_number'] ?? ('#' . (int)$historyLoan['loan_id'])); ?></div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-secondary small">Outstanding balance</div>
                                <div class="fw-semibold"><?php echo displayStatusText(is_numeric($remainingBalance) && $remainingBalance > 0 ? '₱' . number_format((float)$remainingBalance, 2) : (is_numeric($historyLoan['total_payable']) ? '₱' . number_format((float)$historyLoan['total_payable'], 2) : 'Not Available')); ?></div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-secondary small">Next payment due</div>
                                <div class="fw-semibold"><?php echo displayStatusText($nextDueDate ?: 'See schedule in My Loan'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="panel-card panel-card--flush h-100">
                    <div class="chart-panel__body d-flex align-items-center">
                        <?php echo renderEmptyState('fa-file-signature', htmlspecialchars($heroTitle), 'Use the button above to start your borrowing journey.'); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

            <?php if ($loanStage === 'no_application'): ?>
                <?php $cards = [
                    ['label' => 'Loan Application', 'value' => 'No Application', 'icon' => 'fa-file-alt', 'color' => 'primary'],
                    ['label' => 'Application Status', 'value' => 'Not Started', 'icon' => 'fa-hourglass-start', 'color' => 'info'],
                    ['label' => 'Loan Eligibility', 'value' => 'Ready to Apply', 'icon' => 'fa-check-circle', 'color' => 'success']
                ]; ?>
            <?php elseif ($loanStage === 'pending'): ?>
                <?php $cards = [
                    ['label' => 'Application Status', 'value' => 'Pending Review', 'icon' => 'fa-clock', 'color' => 'warning'],
                    ['label' => 'Submitted On', 'value' => displayStatusText($applicationSubmittedOn), 'icon' => 'fa-calendar-day', 'color' => 'info'],
                    ['label' => 'Assigned Loan Officer', 'value' => displayStatusText($loanOfficer ?: 'To be assigned'), 'icon' => 'fa-user-tie', 'color' => 'secondary'],
                    ['label' => 'Estimated Processing Time', 'value' => '3–5 Business Days', 'icon' => 'fa-hourglass-half', 'color' => 'primary']
                ]; ?>
            <?php elseif ($loanStage === 'under_review'): ?>
                <?php $cards = [
                    ['label' => 'Application Status', 'value' => 'Under Review', 'icon' => 'fa-search', 'color' => 'info'],
                    ['label' => 'Loan Officer', 'value' => displayStatusText($loanOfficer ?: 'To be assigned'), 'icon' => 'fa-user-tie', 'color' => 'secondary'],
                    ['label' => 'Current Stage', 'value' => 'Document Verification', 'icon' => 'fa-file-upload', 'color' => 'primary'],
                    ['label' => 'Estimated Decision', 'value' => date('M d, Y', strtotime('+5 days')), 'icon' => 'fa-calendar-check', 'color' => 'success']
                ]; ?>
            <?php elseif ($loanStage === 'requires_documents'): ?>
                <?php $cards = [
                    ['label' => 'Application Status', 'value' => 'Requires Additional Documents', 'icon' => 'fa-file-circle-exclamation', 'color' => 'danger'],
                    ['label' => 'Documents to Re-upload', 'value' => count($rejectedDocuments) . ' item(s)', 'icon' => 'fa-list-check', 'color' => 'warning'],
                    ['label' => 'Next Action', 'value' => 'Re-upload Rejected Documents', 'icon' => 'fa-upload', 'color' => 'primary'],
                    ['label' => 'Review Time', 'value' => '1–2 Business Days', 'icon' => 'fa-clock', 'color' => 'info']
                ]; ?>
            <?php elseif ($loanStage === 'approved'): ?>
                <?php $cards = [
                    ['label' => 'Status', 'value' => 'Approved', 'icon' => 'fa-thumbs-up', 'color' => 'success'],
                    ['label' => 'Approved Amount', 'value' => displayStatusText(is_numeric($application['loan_amount']) ? '₱' . number_format((float)$application['loan_amount'], 2) : 'Not Available'), 'icon' => 'fa-money-bill-wave', 'color' => 'primary'],
                    ['label' => 'Release Date', 'value' => 'Pending', 'icon' => 'fa-calendar-days', 'color' => 'warning'],
                    ['label' => 'Loan Officer', 'value' => displayStatusText($loanOfficer ?: 'To be assigned'), 'icon' => 'fa-user-tie', 'color' => 'secondary']
                ]; ?>
            <?php elseif ($loanStage === 'active'): ?>
                <?php $cards = [
                    ['label' => 'Active Loan Amount', 'value' => displayStatusText(is_numeric($activeLoan['loan_amount']) ? '₱' . number_format((float)$activeLoan['loan_amount'], 2) : 'Not Available'), 'icon' => 'fa-hand-holding-dollar', 'color' => 'success'],
                    ['label' => 'Remaining Balance', 'value' => displayStatusText(is_numeric($remainingBalance) ? '₱' . number_format((float)$remainingBalance, 2) : 'No Remaining Balance'), 'icon' => 'fa-wallet', 'color' => 'info'],
                    ['label' => 'Next Payment Due', 'value' => displayStatusText($nextDueDate ?: 'No Payment Schedule Yet'), 'icon' => 'fa-calendar-check', 'color' => 'warning'],
                    ['label' => 'Payment Progress', 'value' => $paymentProgress > 0 ? $paymentProgress . '% Paid' : 'No Payments Yet', 'icon' => 'fa-chart-line', 'color' => 'primary']
                ]; ?>
            <?php elseif ($loanStage === 'fully_paid'): ?>
                <?php $cards = [
                    ['label' => 'Loan Status', 'value' => 'Completed', 'icon' => 'fa-check-circle', 'color' => 'success'],
                    ['label' => 'Total Amount Paid', 'value' => displayStatusText(is_numeric($loanCompleted['total_payable']) ? '₱' . number_format((float)$loanCompleted['total_payable'], 2) : 'Not Available'), 'icon' => 'fa-wallet', 'color' => 'primary'],
                    ['label' => 'Completion Date', 'value' => displayStatusText(formatDate($loanCompleted['date_released'] ?? $loanCompleted['release_date'])), 'icon' => 'fa-calendar-day', 'color' => 'info'],
                    ['label' => 'Loan Rating', 'value' => '★★★★★', 'icon' => 'fa-star', 'color' => 'warning']
                ]; ?>
            <?php elseif ($loanStage === 'rejected'): ?>
                <?php $cards = [
                    ['label' => 'Application Status', 'value' => 'Rejected', 'icon' => 'fa-thumbs-down', 'color' => 'danger'],
                    ['label' => 'Reviewed On', 'value' => displayStatusText($applicationSubmittedOn), 'icon' => 'fa-calendar-day', 'color' => 'info'],
                    ['label' => 'Next Step', 'value' => 'Reapply with updated information', 'icon' => 'fa-redo-alt', 'color' => 'primary'],
                    ['label' => 'Support', 'value' => 'Contact our loan team', 'icon' => 'fa-headset', 'color' => 'secondary']
                ]; ?>
            <?php else: ?>
                <?php $cards = [
                    ['label' => 'Application Status', 'value' => $loanStageLabel, 'icon' => 'fa-info-circle', 'color' => 'secondary'],
                    ['label' => 'Submitted On', 'value' => displayStatusText($applicationSubmittedOn), 'icon' => 'fa-calendar-day', 'color' => 'info'],
                    ['label' => 'Next Action', 'value' => 'Please check your application', 'icon' => 'fa-check', 'color' => 'primary'],
                    ['label' => 'Loan Officer', 'value' => displayStatusText($loanOfficer ?: 'To be assigned'), 'icon' => 'fa-user-tie', 'color' => 'secondary']
                ]; ?>
            <?php endif; ?>

        <div class="kpi-grid<?php echo count($cards) === 3 ? ' kpi-grid--3' : ''; ?> mb-4">
            <?php
            $kpiVariants = ['primary', 'success', 'info', 'warning'];
            foreach ($cards as $i => $card):
                $variant = $kpiVariants[$i % count($kpiVariants)];
            ?>
                <div class="kpi-card kpi-card--<?php echo $variant; ?>">
                    <div class="kpi-card__top">
                        <div class="kpi-card__label"><?php echo htmlspecialchars($card['label']); ?></div>
                        <div class="kpi-card__icon"><i class="fas <?php echo htmlspecialchars($card['icon']); ?>"></i></div>
                    </div>
                    <div class="kpi-card__value" style="font-size:clamp(1rem,2.5cqi,1.35rem)"><?php echo $card['value']; ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-xl-8">
                <div class="panel-card panel-card--flush h-100">
                    <div class="chart-panel__header table-panel__toolbar">
                        <h6 class="m-0 fw-bold"><i class="fas fa-clock-rotate-left me-2"></i>Recent payments</h6>
                        <a href="borrower_payment_history_lending.php" class="btn btn-sm btn-outline-primary">Full history</a>
                    </div>
                    <div class="chart-panel__body pt-0 px-0 pb-0">
                        <?php if ($historyLoan && count($recentPayments) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 data-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Reference</th>
                                            <th>Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentPayments as $payment): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars(date('M d, Y', strtotime($payment['payment_date']))); ?></td>
                                                <td><?php echo htmlspecialchars($payment['receipt_number'] ?: 'REF'); ?></td>
                                                <td><?php echo formatCurrency($payment['amount_paid']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="p-4"><?php echo renderEmptyState('fa-receipt', 'No payments yet', 'Payment activity will appear here after your loan is released.'); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="panel-card panel-card--flush h-100">
                    <div class="chart-panel__header table-panel__toolbar">
                        <h6 class="m-0 fw-bold"><i class="fas fa-bell me-2"></i>Notifications</h6>
                        <a href="notifications_lending.php" class="btn btn-sm btn-outline-primary">View all</a>
                    </div>
                    <div class="chart-panel__body">
                        <?php if (count($notifications) > 0): ?>
                            <div class="notification-feed">
                            <?php foreach ($notifications as $notification): ?>
                                <?php echo renderNotificationFeedItem($notification, ['truncate' => 85]); ?>
                            <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <?php echo renderEmptyState('fa-bell-slash', 'No notifications yet'); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($documentReuploadMessage !== ''): ?>
            <div class="alert alert-<?php echo htmlspecialchars($documentReuploadMessageType === 'success' ? 'success' : 'danger'); ?> alert-dismissible fade show mb-4" role="alert">
                <?php echo htmlspecialchars($documentReuploadMessage); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($application && count($rejectedDocuments) > 0 && !in_array($loanStage, ['active', 'released', 'fully_paid'], true)): ?>
            <div class="panel-card panel-card--flush mb-4">
                <div class="chart-panel__header">
                    <h6 class="m-0 fw-bold"><i class="fas fa-file-circle-exclamation me-2 text-warning"></i>Documents needing re-upload</h6>
                </div>
                <div class="chart-panel__body">
                    <p class="text-muted small mb-3">Only documents marked as rejected require action. Upload a new file for each item below.</p>
                    <div class="list-group list-group-flush">
                        <?php foreach ($rejectedDocuments as $document): ?>
                            <div class="list-group-item px-0">
                                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                                    <div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars((string)($document['document_name'] ?? 'Document')); ?></div>
                                        <div class="text-muted small">
                                            Rejected<?php echo !empty($document['rejection_reason']) ? ' · Reason: ' . htmlspecialchars((string)$document['rejection_reason']) : ''; ?>
                                        </div>
                                    </div>
                                    <span class="badge bg-warning text-dark">Action Needed</span>
                                </div>
                                <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
                                    <input type="hidden" name="action" value="reupload_document">
                                    <input type="hidden" name="document_id" value="<?php echo (int)($document['id'] ?? 0); ?>">
                                    <div class="col-md-8">
                                        <label class="form-label small mb-1">Replace <?php echo htmlspecialchars((string)($document['document_name'] ?? 'document')); ?></label>
                                        <input type="file" class="form-control form-control-sm" name="document_file" accept=".jpg,.jpeg,.png,.pdf" required>
                                    </div>
                                    <div class="col-md-4">
                                        <button type="submit" class="btn btn-warning btn-sm w-100"><i class="fas fa-upload me-1"></i>Re-upload</button>
                                    </div>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

<?php include 'includes/nav_footer_lending.php'; ?>
</body>
</html>
