<?php
/**
 * Borrower Dashboard for RJ and RR Finance Services
 */

require_once 'includes/auth_lending.php';
require_once 'includes/db_lending.php';

if (!isLoggedIn()) {
    header('Location: borrower_login_lending.php');
    exit;
}

if (!isBorrower()) {
    header('Location: ' . getDashboardRedirectUrl(getCurrentUser()));
    exit;
}

$user = getCurrentUser();

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
$requiredLoanTypes = ['Salary Loan', 'Business Loan', 'Emergency Loan', 'Vehicle Financing'];
$availableLoanProducts = count($requiredLoanTypes);
$applicationDocuments = [];
$missingDocuments = [];
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

if ($client) {
    $latestLoan = lendingDashboardFetchRow('SELECT l.*, r.loan_number, r.payment_frequency, r.released_at AS release_date FROM loans l LEFT JOIN loan_releases r ON l.release_id = r.id WHERE l.client_id = ? ORDER BY l.loan_id DESC LIMIT 1', [$client['client_id']]);
    if ($latestLoan) {
        $loanStatus = strtolower(trim((string)$latestLoan['status']));
        if (in_array($loanStatus, ['active', 'overdue'], true)) {
            $activeLoan = $latestLoan;
        }
        if ($loanStatus === 'paid') {
            $loanCompleted = $latestLoan;
        }

        if (!empty($latestLoan['collector_id'])) {
            $collectorRow = lendingDashboardFetchRow('SELECT full_name FROM users WHERE user_id = ? LIMIT 1', [(int)$latestLoan['collector_id']]);
            $loanOfficer = $collectorRow['full_name'] ?? null;
        }
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
        $isVerified = strtolower(trim((string)$doc['verification_status'])) === 'verified';
        if (!$isVerified) {
            $missingDocuments[] = $doc;
        }
    }
}

if ($application || $latestLoan) {
    $statusKey = strtolower(trim($application['status'] ?? ''));
    $loanStage = 'pending';

    if ($statusKey === 'rejected') {
        $loanStage = 'rejected';
    } elseif (in_array($statusKey, ['documents incomplete', 'requires additional documents'], true)) {
        $loanStage = 'requires_documents';
    } elseif ($statusKey === 'under review') {
        $loanStage = 'under_review';
    } elseif (in_array($statusKey, ['approved', 'waiting for loan agreement', 'ready for release'], true)) {
        $loanStage = 'approved';
    } elseif ($statusKey === 'released') {
        $loanStage = 'released';
    }

    if ($latestLoan) {
        if ($loanStatus === 'paid') {
            $loanStage = 'fully_paid';
        } elseif (in_array($loanStatus, ['active', 'overdue'], true)) {
            $loanStage = 'active';
        } elseif ($loanStage === 'approved') {
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
        if ($stageKey === 'approved' && !in_array($statusKey, ['approved', 'waiting for loan agreement', 'ready for release', 'released'], true) && !$latestLoan) {
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
    case 'active':
        $heroTitle = 'Your loan is active';
        $heroMessage = 'Your loan is active and payments are being tracked in real time. Stay on schedule to keep your account in good standing.';
        $heroButton = ['label' => 'View Loan Details', 'href' => 'borrower_my_loan_lending.php', 'icon' => 'fa-wallet'];
        break;
    case 'fully_paid':
        $heroTitle = 'Loan completed successfully';
        $heroMessage = 'Congratulations! You have successfully completed your loan. Thank you for choosing RL&RR Lending Management System.';
        $heroButton = ['label' => 'Apply for Another Loan', 'href' => 'borrower_loan_application_lending.php', 'icon' => 'fa-plus-circle'];
        break;
    case 'rejected':
        $heroTitle = 'Application was not approved';
        $heroMessage = 'Your loan application was not approved at this time. Please contact support or submit a new application with updated information.';
        $heroButton = ['label' => 'Apply for New Loan', 'href' => 'borrower_loan_application_lending.php', 'icon' => 'fa-redo-alt'];
        break;
    default:
        $heroTitle = 'Welcome to RL&RR Lending Management System';
        $heroMessage = "You don't have any loan applications yet. Start your first loan application to begin your borrowing journey.";
        $heroButton = ['label' => 'Apply for Loan', 'href' => 'borrower_loan_application_lending.php', 'icon' => 'fa-file-signature'];
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
    <title>Borrower Dashboard - RJ and RR Finance Services</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/lending_styles.css">
</head>
<body>
    <?php include 'includes/nav_lending.php'; ?>

    <div class="container-fluid py-4">
        <div class="page-hero collector-hero mb-4">
            <div class="d-flex align-items-center gap-3">
                <div class="collector-hero__logo">
                    <img src="images/logo.png" alt="RJ and RR Finance Services logo">
                </div>
                <div>
                    <h1 class="page-title"><i class="fas fa-home me-2"></i>Borrower Dashboard</h1>
                    <p class="page-subtitle">Welcome back, <?php echo $borrowerName; ?>. Review your loan progress and account updates in one place.</p>
                </div>
            </div>
            <div class="collector-hero__pill-wrap mt-3">
                <span class="collector-hero__chip"><i class="fas fa-shield-alt me-2"></i>Secure portal</span>
                <span class="collector-hero__chip collector-hero__chip--accent"><i class="fas fa-bolt me-2"></i>Real-time updates</span>
            </div>
        </div>

        <div class="row g-4 mb-4 align-items-stretch">
            <div class="col-xl-8">
                <div class="card shadow-sm h-100">
                    <div class="card-body d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div class="bg-primary-subtle text-primary rounded-4 p-3"><i class="fas fa-info-circle fa-lg"></i></div>
                                <div>
                                    <h4 class="fw-bold mb-1"><?php echo htmlspecialchars($heroTitle); ?></h4>
                                    <p class="text-muted mb-0"><?php echo htmlspecialchars($heroMessage); ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3">
                            <a href="<?php echo htmlspecialchars($heroButton['href']); ?>" class="btn btn-primary btn-lg rounded-pill">
                                <i class="fas <?php echo htmlspecialchars($heroButton['icon']); ?> me-2"></i><?php echo htmlspecialchars($heroButton['label']); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 fw-bold"><i class="fas fa-clipboard-list me-2"></i>Current Status</h6>
                    </div>
                    <div class="card-body">
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
                    </div>
                </div>
            </div>
        </div>

        <?php if ($application): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-body py-3 px-3">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="progress-step-icon bg-primary text-white rounded-circle d-flex align-items-center justify-content-center">
                                <i class="fas fa-stream"></i>
                            </div>
                            <div>
                                <div class="text-secondary small">Application Progress Tracker</div>
                                <div class="fw-semibold">Follow your loan lifecycle</div>
                            </div>
                        </div>
                        <div class="text-muted small">Current stage: <?php echo htmlspecialchars($loanStageLabel); ?></div>
                    </div>
                    <div class="progress-tracker mt-4">
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
                        <div class="d-flex flex-column flex-md-row align-items-center gap-3">
                            <?php foreach ($loanProgressSteps as $index => $step): ?>
                                <?php $stepPosition = $index + 1; ?>
                                <?php $isComplete = $stepPosition <= $currentProgressIndex; ?>
                                <div class="progress-step <?php echo $isComplete ? 'progress-step-active' : ''; ?>">
                                    <div class="progress-step-badge <?php echo $isComplete ? 'bg-primary text-white' : 'bg-secondary text-white'; ?>">
                                        <i class="fas <?php echo htmlspecialchars($step['icon']); ?>"></i>
                                    </div>
                                    <div class="progress-step-label fw-semibold mt-2"><?php echo htmlspecialchars($step['label']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <?php if ($loanStage === 'no_application'): ?>
                <?php $cards = [
                    ['label' => 'Loan Application', 'value' => 'No Application', 'icon' => 'fa-file-alt', 'color' => 'primary'],
                    ['label' => 'Application Status', 'value' => 'Not Started', 'icon' => 'fa-hourglass-start', 'color' => 'info'],
                    ['label' => 'Loan Eligibility', 'value' => 'Ready to Apply', 'icon' => 'fa-check-circle', 'color' => 'success'],
                    ['label' => 'Available Loan Products', 'value' => $availableLoanProducts . ' Products', 'icon' => 'fa-box-open', 'color' => 'warning']
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
                    ['label' => 'Missing Requirements', 'value' => count($missingDocuments) . ' items', 'icon' => 'fa-list-check', 'color' => 'warning'],
                    ['label' => 'Next Action', 'value' => 'Upload Required Documents', 'icon' => 'fa-upload', 'color' => 'primary'],
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

            <?php foreach ($cards as $card): ?>
                <div class="col-xl-3 col-md-6">
                    <div class="card metric-card shadow-sm h-100">
                        <div class="card-body d-flex justify-content-between align-items-center">
                            <div>
                                <div class="metric-label"><?php echo htmlspecialchars($card['label']); ?></div>
                                <div class="metric-value"><?php echo htmlspecialchars($card['value']); ?></div>
                            </div>
                            <div class="metric-icon bg-<?php echo htmlspecialchars($card['color']); ?>-subtle text-<?php echo htmlspecialchars($card['color']); ?>"><i class="fas <?php echo htmlspecialchars($card['icon']); ?>"></i></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-xl-8">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 fw-bold"><i class="fas fa-history me-2"></i>Payment History</h6>
                    </div>
                    <div class="card-body">
                        <?php if ($historyLoan && count($recentPayments) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
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
                            <div class="text-center py-5">
                                <p class="mb-2 fw-semibold">No payment history available because you don't have an active loan.</p>
                                <p class="text-muted mb-0">Once your loan is released, recent transactions and payment details will appear here.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 fw-bold"><i class="fas fa-bell me-2"></i>Notifications</h6>
                        <a href="notifications_lending.php" class="btn btn-sm btn-outline-primary">View All</a>
                    </div>
                    <div class="card-body">
                        <?php if (count($notifications) > 0): ?>
                            <?php foreach ($notifications as $notification): ?>
                                <div class="d-flex align-items-start gap-2 mb-3 <?php echo $notification['is_read'] ? 'text-muted' : ''; ?>">
                                    <div class="metric-icon <?php echo $notification['is_read'] ? 'bg-secondary-subtle text-secondary' : 'bg-primary-subtle text-primary'; ?>"><i class="fas fa-bell"></i></div>
                                    <div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($notification['title']); ?></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars($notification['message']); ?></div>
                                        <div class="text-muted extra-small"><?php echo date('M d, Y H:i', strtotime($notification['created_at'])); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-4">No notifications yet.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($loanStage === 'requires_documents' && count($missingDocuments) > 0): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 fw-bold"><i class="fas fa-file-circle-exclamation me-2"></i>Missing Requirements</h6>
                </div>
                <div class="card-body">
                    <div class="list-group">
                        <?php foreach ($missingDocuments as $document): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($document['document_name']); ?></div>
                                    <div class="text-muted small"><?php echo htmlspecialchars($document['verification_status']); ?><?php echo !empty($document['rejection_reason']) ? ' · Reason: ' . htmlspecialchars($document['rejection_reason']) : ''; ?></div>
                                </div>
                                <span class="badge bg-warning text-dark">Action Needed</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <style>
        .progress-tracker { min-height: 90px; }
        .progress-step { display: inline-flex; flex-direction: column; align-items: center; justify-content: center; padding: 1rem .85rem; border-radius: 1rem; background: #f8f9fa; transition: transform .25s ease, background .25s ease; }
        .progress-step-active { background: #e9f2ff; transform: translateY(-2px); }
        .progress-step-badge { width: 44px; height: 44px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; }
        .progress-step-label { font-size: .92rem; text-align: center; max-width: 120px; }
        .metric-card .metric-value { font-size: 1.2rem; font-weight: 700; }
        .metric-icon { width: 52px; height: 52px; display: inline-flex; align-items: center; justify-content: center; border-radius: 1rem; font-size: 1.2rem; }
        .progress-step-icon { width: 50px; height: 50px; }
        .collector-hero .page-title { font-size: 2.15rem; }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/lending.js"></script>
</body>
</html>
