<?php
/**
 * Borrower Loan Status Tracking Page for RJ and RR Finance Services
 */

require_once 'includes/auth_lending.php';
require_once 'includes/db_lending.php';
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
$borrowerCanApplyForLoan = borrowerCanApplyForNewLoan((int)$user['user_id']);

if (borrowerShouldUseMyLoanPortal((int)$user['user_id'])) {
    header('Location: borrower_my_loan_lending.php');
    exit;
}

$application = executeQuery('SELECT * FROM loan_applications WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$user['user_id']])->fetch(PDO::FETCH_ASSOC);

$statusSteps = [
    'Application Submitted',
    'Documents Verified',
    'Under Review',
    'Approved or Rejected',
    'Loan Agreement',
    'Loan Released'
];

$statusLabels = [
    'Pending Review' => 'Pending Review',
    'Documents Incomplete' => 'Documents Incomplete',
    'Under Review' => 'Under Review',
    'Approved' => 'Approved',
    'Rejected' => 'Rejected',
    'Waiting for Loan Agreement' => 'Waiting for Loan Agreement',
    'Agreement Accepted' => 'Agreement Accepted',
    'Ready for Release' => 'Ready for Release',
    'Released' => 'Released',
    'Completed' => 'Completed'
];

function getStatusBadgeClass($status) {
    switch (strtolower(trim((string)$status))) {
        case 'approved':
        case 'agreement accepted':
        case 'released':
        case 'completed':
            return 'bg-success';
        case 'rejected':
            return 'bg-danger';
        case 'documents incomplete':
            return 'bg-warning text-dark';
        case 'under review':
        case 'waiting for loan agreement':
        case 'ready for release':
        case 'pending review':
        default:
            return 'bg-info text-dark';
    }
}

function getDocumentVerificationBadgeClass(string $status): string
{
    switch (strtolower(trim($status))) {
        case 'verified':
            return 'bg-success';
        case 'rejected':
            return 'bg-danger';
        case 'missing':
            return 'bg-secondary';
        default:
            return 'bg-info text-dark';
    }
}

$documentStatus = [];
$documentRows = [];
$adminRemarkLines = [];
$statusNotifications = [];

if ($application && !empty($application['id'])) {
    $applicationId = (int)$application['id'];
    $documentRows = executeQuery(
        'SELECT document_name, uploaded_at, verification_status, rejection_reason, document_path
         FROM loan_application_documents
         WHERE application_id = ?
         ORDER BY uploaded_at ASC, id ASC',
        [$applicationId]
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($documentRows as $documentRow) {
        $documentStatus[] = [
            'name' => trim((string)($documentRow['document_name'] ?? 'Document')),
            'uploaded' => !empty($documentRow['uploaded_at']) ? date('M d, Y', strtotime((string)$documentRow['uploaded_at'])) : '—',
            'status' => trim((string)($documentRow['verification_status'] ?? 'Pending Review')),
            'reason' => trim((string)($documentRow['rejection_reason'] ?? '')),
            'path' => trim((string)($documentRow['document_path'] ?? '')),
        ];
    }

    $remarksText = trim((string)($application['remarks'] ?? ''));
    if ($remarksText !== '') {
        $adminRemarkLines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $remarksText))));
    }
    if (empty($adminRemarkLines) && !empty($application['rejection_reason'])) {
        $adminRemarkLines[] = trim((string)$application['rejection_reason']);
    }

    $statusNotifications = executeQuery(
        'SELECT title, message, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 8',
        [(int)$user['user_id']]
    )->fetchAll(PDO::FETCH_ASSOC);
}

$agreementRow = null;
$currentStatus = $application['status'] ?? 'Pending Review';
$currentIndex = 1;

if ($application && !empty($application['id'])) {
    $applicationId = (int)$application['id'];
    $agreementRow = executeQuery('SELECT * FROM loan_agreements WHERE application_id = ? ORDER BY id DESC LIMIT 1', [$applicationId])->fetch(PDO::FETCH_ASSOC);
    $loanReleased = loanApplicationHasBeenReleased($applicationId);
    $progress = resolveBorrowerApplicationProgress($application, $documentRows, $agreementRow ?: null, $loanReleased);
    $currentIndex = (int)$progress['current_index'];
    $currentStatus = (string)$progress['display_status'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/lending_assets.php'; lendingRenderPwaMeta(); ?>
    <title>Loan Status - RJ and RR Finance Services</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="css/lending_styles.css">
    <link rel="stylesheet" href="css/pwa.css">
</head>
<body>
    <?php include 'includes/nav_lending.php'; ?>

    <div class="container-fluid py-4">
        <div class="page-hero collector-hero">
            <div class="d-flex align-items-center gap-3">
                <div class="collector-hero__logo">
                    <img src="images/logo.png" alt="RJ and RR Finance Services logo">
                </div>
                <div>
                    <h1 class="page-title"><i class="fas fa-chart-line me-2"></i>Loan Status</h1>
                    <p class="page-subtitle">Monitor your application progress and stay updated throughout the review process.</p>
                </div>
            </div>
        </div>

        <?php if (!$application): ?>
            <div class="card shadow-sm">
                <div class="card-body text-center py-5">
                    <div class="login-logo mx-auto mb-3">
                        <img src="images/logo.png" alt="RJ and RR Finance Services logo">
                    </div>
                    <h4 class="fw-bold text-primary">No Loan Application Found</h4>
                    <p class="text-muted mb-4">You do not have a loan application yet. Start one from the dashboard to begin tracking progress.</p>
                    <?php if ($borrowerCanApplyForLoan): ?>
                        <a href="borrower_loan_application_lending.php" class="btn btn-primary">Start New Application</a>
                    <?php else: ?>
                        <a href="borrower_my_loan_lending.php" class="btn btn-primary">View My Loan</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <div>
                            <h5 class="fw-bold mb-1">Application Progress</h5>
                            <p class="text-muted mb-0">Current stage: <?php echo htmlspecialchars($currentStatus); ?></p>
                        </div>
                        <span class="badge <?php echo getStatusBadgeClass($currentStatus); ?> fs-6 px-3 py-2"><?php echo htmlspecialchars($currentStatus); ?></span>
                    </div>

                    <div class="row g-3">
                        <?php foreach ($statusSteps as $index => $step): ?>
                            <?php $isCompleted = $index + 1 < $currentIndex; ?>
                            <?php $isCurrent = $index + 1 === $currentIndex; ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="consent-card h-100 <?php echo $isCurrent ? 'border-primary' : ''; ?>">
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <?php if ($isCompleted): ?>
                                            <span class="badge bg-success"><i class="fas fa-check"></i></span>
                                        <?php elseif ($isCurrent): ?>
                                            <span class="badge bg-primary"><i class="fas fa-clock"></i></span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><i class="fas fa-circle"></i></span>
                                        <?php endif; ?>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($step); ?></div>
                                    </div>
                                    <div class="small text-muted"><?php echo $isCompleted ? 'Completed' : ($isCurrent ? 'Current step' : 'Pending'); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-xl-8">
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h6 class="m-0 fw-bold"><i class="fas fa-file-alt me-2"></i>Loan Application Summary</h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="consent-card h-100">
                                        <div class="text-muted small">Application Number</div>
                                        <div class="fw-semibold">#<?php echo (int)$application['id']; ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="consent-card h-100">
                                        <div class="text-muted small">Loan Type</div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($application['loan_type'] ?? 'N/A'); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="consent-card h-100">
                                        <div class="text-muted small">Requested Amount</div>
                                        <div class="fw-semibold">₱<?php echo number_format((float)($application['loan_amount'] ?? 0), 2); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="consent-card h-100">
                                        <div class="text-muted small">Requested Term</div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($application['loan_term'] ?? 'N/A'); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="consent-card h-100">
                                        <div class="text-muted small">Loan Purpose</div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($application['loan_purpose'] ?? 'N/A'); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="consent-card h-100">
                                        <div class="text-muted small">Date Submitted</div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars(date('M d, Y', strtotime($application['submitted_at']))); ?></div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="consent-card h-100">
                                        <div class="text-muted small">Current Status</div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($application['status'] ?? 'Pending Review'); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h6 class="m-0 fw-bold"><i class="fas fa-file-upload me-2"></i>Uploaded Documents</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Document Name</th>
                                            <th>Upload Date</th>
                                            <th>Verification Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($documentStatus)): ?>
                                            <tr>
                                                <td colspan="3" class="text-center text-muted py-4">No uploaded documents found for this application yet.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($documentStatus as $document): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($document['name']); ?></td>
                                                    <td><?php echo htmlspecialchars($document['uploaded']); ?></td>
                                                    <td>
                                                        <span class="badge <?php echo getDocumentVerificationBadgeClass($document['status']); ?>">
                                                            <?php echo htmlspecialchars($document['status']); ?>
                                                        </span>
                                                        <?php if (strcasecmp($document['status'], 'Rejected') === 0 && !empty($document['reason'])): ?>
                                                            <div class="small text-muted mt-1">Reason: <?php echo htmlspecialchars($document['reason']); ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4">
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h6 class="m-0 fw-bold"><i class="fas fa-comment-alt me-2"></i>Admin Remarks</h6>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($adminRemarkLines)): ?>
                                <ul class="list-group list-group-flush">
                                    <?php foreach ($adminRemarkLines as $remark): ?>
                                        <li class="list-group-item px-0"><?php echo nl2br(htmlspecialchars($remark)); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="text-muted mb-0">No admin remarks yet. Check back after your application is reviewed.</p>
                            <?php endif; ?>
                            <?php if (!empty($application['reviewed_by']) && !empty($application['reviewed_at'])): ?>
                                <div class="small text-muted mt-3">Last updated by <?php echo htmlspecialchars((string)$application['reviewed_by']); ?> on <?php echo htmlspecialchars(date('M d, Y g:i A', strtotime((string)$application['reviewed_at']))); ?>.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h6 class="m-0 fw-bold"><i class="fas fa-bell me-2"></i>Notifications</h6>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($statusNotifications)): ?>
                                <div class="d-flex flex-column gap-3">
                                    <?php foreach ($statusNotifications as $notification): ?>
                                        <div>
                                            <div class="fw-semibold small"><?php echo htmlspecialchars((string)($notification['title'] ?? 'Update')); ?></div>
                                            <div class="text-muted small"><?php echo htmlspecialchars((string)($notification['message'] ?? '')); ?></div>
                                            <?php if (!empty($notification['created_at'])): ?>
                                                <div class="text-muted" style="font-size:0.75rem;"><?php echo htmlspecialchars(date('M d, Y g:i A', strtotime((string)$notification['created_at']))); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">No notifications yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card shadow-sm">
                        <div class="card-header bg-white py-3">
                            <h6 class="m-0 fw-bold"><i class="fas fa-bolt me-2"></i>Quick Actions</h6>
                        </div>
                        <div class="card-body d-grid gap-2">
                            <?php if (strcasecmp((string)$currentStatus, 'Rejected') === 0): ?>
                                <?php if ($borrowerCanApplyForLoan): ?>
                                    <a href="borrower_loan_application_lending.php" class="btn btn-primary">Submit New Application</a>
                                <?php else: ?>
                                    <a href="borrower_my_loan_lending.php" class="btn btn-primary">View My Loan</a>
                                <?php endif; ?>
                                <a href="mailto:support@rjrrfinance.com" class="btn btn-outline-primary rounded-pill">Contact Support</a>
                            <?php elseif (!empty($agreementRow)): ?>
                                <a href="borrower_loan_agreement_lending.php" class="btn btn-primary"><?php echo (strcasecmp((string)($agreementRow['agreement_status'] ?? ''), 'accepted') === 0) ? 'View Loan Agreement' : 'Review Loan Agreement'; ?></a>
                                <a href="borrower_dashboard_lending.php" class="btn btn-outline-primary rounded-pill">Return to Dashboard</a>
                            <?php elseif (strcasecmp((string)$currentStatus, 'Approved') === 0): ?>
                                <a href="borrower_loan_agreement_lending.php" class="btn btn-primary">Review Loan Agreement</a>
                                <a href="borrower_dashboard_lending.php" class="btn btn-outline-primary rounded-pill">Return to Dashboard</a>
                            <?php else: ?>
                                <a href="borrower_dashboard_lending.php" class="btn btn-outline-primary rounded-pill">Return to Dashboard</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="assets/pwa.js" defer></script>
</body>
</html>
