<?php
/**
 * Borrower Approval Management Page for RJ and RR Finance Services
 */

require_once 'includes/auth_lending.php';
requireAdmin();
require_once 'includes/db_lending.php';
ensureNotificationsSchema();
require_once 'includes/loan_email_helpers.php';
require_once 'includes/ui_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user = getCurrentUser();
require_once 'includes/db_lending.php';

$errorMsg = '';
$successMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $borrowerId = (int)($_POST['borrower_id'] ?? 0);
    $action = trim($_POST['action'] ?? '');
    $rejectionReason = trim($_POST['rejection_reason'] ?? '');

    if ($borrowerId > 0 && in_array($action, ['approve', 'reject'], true)) {
        $borrowerQuery = "SELECT ba.id, ba.user_id, ba.first_name, ba.middle_name, ba.last_name, ba.email, ba.mobile_number, ba.created_at, u.status, u.full_name, u.username FROM borrower_applications ba LEFT JOIN users u ON u.user_id = ba.user_id WHERE ba.id = ? LIMIT 1";
        $borrowerResult = executeQuery($borrowerQuery, [$borrowerId]);
        $borrower = $borrowerResult->fetch(PDO::FETCH_ASSOC);

        if ($borrower) {
            if ($action === 'approve') {
                $conn->exec("ALTER TABLE users MODIFY COLUMN role ENUM('Admin','Collector','Borrower') NOT NULL DEFAULT 'Borrower'");
                executeQuery('UPDATE users SET status = ?, role = ? WHERE user_id = ?', ['Active', 'Borrower', $borrower['user_id']]);
                executeQuery('UPDATE borrower_applications SET status = ?, verification_status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?', ['Approved', 'Verified', ($user['full_name'] ?? $user['username'] ?? 'Admin'), $borrowerId]);
                addNotification((int)$borrower['user_id'], 'Borrower Account Approved', 'Your borrower account has been approved. You may now log in and apply for a loan.');
                $successMsg = 'Borrower account approved successfully.';

                sendBorrowerAccountApprovedEmail($borrower);
            } else {
                if ($rejectionReason === '') {
                    $errorMsg = 'Please provide a rejection reason.';
                } else {
                    executeQuery('UPDATE users SET status = ? WHERE user_id = ?', ['Rejected', $borrower['user_id']]);
                    executeQuery('UPDATE borrower_applications SET status = ?, verification_status = ? WHERE id = ?', ['Rejected', 'Rejected', $borrowerId]);
                    addNotification((int)$borrower['user_id'], 'Borrower Account Rejected', 'Your borrower account was rejected. Reason: ' . $rejectionReason);
                    sendBorrowerAccountRejectedEmail($borrower, $rejectionReason);
                    $successMsg = 'Borrower account rejected successfully.';
                }
            }
        } else {
            $errorMsg = 'Borrower record not found.';
        }
    }

    if ($successMsg !== '' || $errorMsg !== '') {
        $_SESSION['borrower_approval_message'] = $successMsg !== '' ? $successMsg : $errorMsg;
        $_SESSION['borrower_approval_type'] = $successMsg !== '' ? 'success' : 'danger';
        header('Location: borrower_approval_lending.php');
        exit;
    }
}

if (isset($_SESSION['borrower_approval_message'])) {
    $successMsg = $_SESSION['borrower_approval_type'] === 'success' ? $_SESSION['borrower_approval_message'] : '';
    $errorMsg = $_SESSION['borrower_approval_type'] === 'danger' ? $_SESSION['borrower_approval_message'] : '';
    unset($_SESSION['borrower_approval_message'], $_SESSION['borrower_approval_type']);
}

$borrowersQuery = "SELECT ba.id, ba.user_id, CONCAT(COALESCE(ba.first_name, ''), ' ', COALESCE(ba.middle_name, ''), ' ', COALESCE(ba.last_name, '')) AS full_name, ba.email, ba.mobile_number, ba.created_at, ba.verification_status, ba.status FROM borrower_applications ba WHERE ba.status IS NULL OR ba.status NOT IN ('Approved', 'Rejected') ORDER BY ba.created_at DESC";
$borrowers = executeQuery($borrowersQuery)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/lending_assets.php'; lendingRenderPwaMeta(); ?>
    <title>Borrower Approval - RJ and RR Finance Services</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="css/lending_styles.css">
    <link rel="stylesheet" href="css/pwa.css">
</head>
<body>
    <?php include 'includes/nav_lending.php'; ?>

    <div class="app-content py-2">
        <?php
        echo renderAdminDashboardHeader(
            'Borrower Approval',
            'Review pending borrower registrations and manage approvals in a professional workflow.',
            'fa-user-check',
            'Borrower approval'
        );
        ?>

        <?php if ($errorMsg !== ''): ?>
            <div class="alert alert-danger rounded-4"><?php echo htmlspecialchars($errorMsg); ?></div>
        <?php endif; ?>

        <?php if ($successMsg !== ''): ?>
            <div class="alert alert-success rounded-4"><?php echo htmlspecialchars($successMsg); ?></div>
        <?php endif; ?>

        <div class="panel-card panel-card--flush">
            <div class="chart-panel__header">
                <h6 class="m-0 fw-bold"><i class="fas fa-users me-2 text-primary"></i>Borrower registrations</h6>
            </div>
            <div class="chart-panel__body p-0 pt-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Borrower ID</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Phone Number</th>
                                <th>Registration Date</th>
                                <th>Verification Status</th>
                                <th>Account Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($borrowers) > 0): ?>
                                <?php foreach ($borrowers as $borrower): ?>
                                    <tr>
                                        <td>#<?php echo (int)$borrower['id']; ?></td>
                                        <td><?php echo htmlspecialchars(trim($borrower['full_name'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars($borrower['email'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($borrower['mobile_number'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars(date('M d, Y', strtotime($borrower['created_at']))); ?></td>
                                        <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($borrower['verification_status'] ?? 'Pending'); ?></span></td>
                                        <td><span class="badge bg-warning text-dark"><?php echo htmlspecialchars($borrower['status'] ?? 'Pending Approval'); ?></span></td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-2">
                                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#detailModal<?php echo (int)$borrower['id']; ?>">View Details</button>
                                                <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#approveModal<?php echo (int)$borrower['id']; ?>">Approve</button>
                                                <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal<?php echo (int)$borrower['id']; ?>">Reject</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">No borrower registrations have been submitted yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php foreach ($borrowers as $borrower): ?>
        <div class="modal fade" id="detailModal<?php echo (int)$borrower['id']; ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-user me-2"></i>Borrower Details</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="consent-card">
                                    <div class="text-muted small">Full Name</div>
                                    <div class="fw-semibold"><?php echo htmlspecialchars(trim($borrower['full_name'] ?? '')); ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="consent-card">
                                    <div class="text-muted small">Email</div>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($borrower['email'] ?? ''); ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="consent-card">
                                    <div class="text-muted small">Phone Number</div>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($borrower['mobile_number'] ?? ''); ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="consent-card">
                                    <div class="text-muted small">Registration Date</div>
                                    <div class="fw-semibold"><?php echo htmlspecialchars(date('M d, Y', strtotime($borrower['created_at']))); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="approveModal<?php echo (int)$borrower['id']; ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-check-circle me-2"></i>Approve Borrower</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-0">Are you sure you want to approve <strong><?php echo htmlspecialchars(trim($borrower['full_name'] ?? '')); ?></strong>?</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="borrower_id" value="<?php echo (int)$borrower['id']; ?>">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="btn btn-success">Approve</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="rejectModal<?php echo (int)$borrower['id']; ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-times-circle me-2"></i>Reject Borrower</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="post">
                        <div class="modal-body">
                            <p>Provide a reason for rejecting this borrower account.</p>
                            <select class="form-select" name="rejection_reason" required>
                                <option value="">Select reason</option>
                                <option value="Incomplete requirements">Incomplete requirements</option>
                                <option value="Invalid identification">Invalid identification</option>
                                <option value="Duplicate account">Duplicate account</option>
                                <option value="Other">Other</option>
                            </select>
                            <input type="hidden" name="borrower_id" value="<?php echo (int)$borrower['id']; ?>">
                            <input type="hidden" name="action" value="reject">
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">Reject</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="assets/pwa.js" defer></script>
</body>
</html>
