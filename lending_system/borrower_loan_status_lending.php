<?php
/**
 * Borrower Loan Status Tracking Page for RJ and RR Finance Services
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
    'Ready for Release' => 'Ready for Release',
    'Released' => 'Released',
    'Completed' => 'Completed'
];

function getStatusBadgeClass($status) {
    switch (strtolower(trim((string)$status))) {
        case 'approved':
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

function getProgressIndex($status) {
    $statusKey = strtolower(trim((string)$status));
    $map = [
        'pending review' => 1,
        'documents incomplete' => 2,
        'under review' => 3,
        'approved' => 4,
        'rejected' => 4,
        'waiting for loan agreement' => 5,
        'ready for release' => 6,
        'released' => 6,
        'completed' => 6
    ];
    return $map[$statusKey] ?? 1;
}

$currentStatus = $application['status'] ?? 'Pending Review';
$currentIndex = getProgressIndex($currentStatus);
$documentStatus = [
    ['name' => 'Valid Government ID', 'uploaded' => '2026-07-16', 'status' => 'Verified'],
    ['name' => 'Proof of Income', 'uploaded' => '2026-07-16', 'status' => 'Pending Review'],
    ['name' => 'Proof of Billing', 'uploaded' => '2026-07-16', 'status' => 'Rejected', 'reason' => 'Please upload a clearer copy.']
];
$remarks = [
    'Your application is currently under review.',
    'Please upload a clearer copy of your valid ID.'
];
$notifications = [
    'Application Submitted',
    'Documents Verified',
    'Application Under Review'
];

$agreementRow = null;
if ($application && !empty($application['id'])) {
    $agreementRow = executeQuery('SELECT * FROM loan_agreements WHERE application_id = ? ORDER BY id DESC LIMIT 1', [(int)$application['id']])->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Status - RJ and RR Finance Services</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/lending_styles.css">
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
                    <a href="borrower_loan_application_lending.php" class="btn btn-primary">Start New Application</a>
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
                                        <?php foreach ($documentStatus as $document): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($document['name']); ?></td>
                                                <td><?php echo htmlspecialchars($document['uploaded']); ?></td>
                                                <td>
                                                    <span class="badge <?php echo $document['status'] === 'Verified' ? 'bg-success' : ($document['status'] === 'Rejected' ? 'bg-danger' : 'bg-info text-dark'); ?>">
                                                        <?php echo htmlspecialchars($document['status']); ?>
                                                    </span>
                                                    <?php if ($document['status'] === 'Rejected' && !empty($document['reason'])): ?>
                                                        <div class="small text-muted mt-1">Reason: <?php echo htmlspecialchars($document['reason']); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
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
                            <ul class="list-group list-group-flush">
                                <?php foreach ($remarks as $remark): ?>
                                    <li class="list-group-item px-0"><?php echo htmlspecialchars($remark); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>

                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h6 class="m-0 fw-bold"><i class="fas fa-bell me-2"></i>Notifications</h6>
                        </div>
                        <div class="card-body">
                            <div class="d-flex flex-column gap-2">
                                <?php foreach ($notifications as $notification): ?>
                                    <div class="d-flex align-items-center gap-2 text-muted">
                                        <i class="fas fa-check-circle text-success"></i>
                                        <span><?php echo htmlspecialchars($notification); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="card shadow-sm">
                        <div class="card-header bg-white py-3">
                            <h6 class="m-0 fw-bold"><i class="fas fa-bolt me-2"></i>Quick Actions</h6>
                        </div>
                        <div class="card-body d-grid gap-2">
                            <?php if (strcasecmp((string)$currentStatus, 'Rejected') === 0): ?>
                                <a href="borrower_loan_application_lending.php" class="btn btn-primary">Submit New Application</a>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
