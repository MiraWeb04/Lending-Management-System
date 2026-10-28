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
require_once 'includes/borrower_registration_helpers.php';
ensureBorrowerApplicationsTable();

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
                                                <button type="button" class="btn btn-sm btn-outline-primary js-view-borrower" data-borrower-id="<?php echo (int)$borrower['id']; ?>">View Details</button>
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

    <div class="modal fade" id="borrowerDetailModal" tabindex="-1" aria-labelledby="borrowerDetailTitle" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="borrowerDetailTitle"><i class="fas fa-user me-2"></i>Borrower Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="borrowerDetailLoading" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading borrower information</span></div>
                        <p class="mt-3 mb-0 text-muted">Loading borrower information…</p>
                    </div>
                    <div id="borrowerDetailError" class="alert alert-danger d-none" role="alert"></div>
                    <div id="borrowerDetailContent" class="d-none"></div>
                </div>
                <div class="modal-footer flex-wrap gap-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-success" id="borrowerDetailApprove" disabled>Approve</button>
                    <button type="button" class="btn btn-danger" id="borrowerDetailReject" disabled>Reject</button>
                </div>
            </div>
        </div>
    </div>

    <?php foreach ($borrowers as $borrower): ?>
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
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const detailModalEl = document.getElementById('borrowerDetailModal');
            const detailModal = bootstrap.Modal.getOrCreateInstance(detailModalEl);
            const loadingEl = document.getElementById('borrowerDetailLoading');
            const errorEl = document.getElementById('borrowerDetailError');
            const contentEl = document.getElementById('borrowerDetailContent');
            const titleEl = document.getElementById('borrowerDetailTitle');
            const approveBtn = document.getElementById('borrowerDetailApprove');
            const rejectBtn = document.getElementById('borrowerDetailReject');
            const allowedIcons = ['fa-user', 'fa-address-book', 'fa-key', 'fa-id-card', 'fa-clipboard-list'];
            let requestToken = 0;
            let activeBorrowerId = 0;

            function setState(state) {
                loadingEl.classList.toggle('d-none', state !== 'loading');
                errorEl.classList.toggle('d-none', state !== 'error');
                contentEl.classList.toggle('d-none', state !== 'ready');
            }

            function showError(message) {
                errorEl.textContent = '';
                const text = document.createElement('div');
                text.textContent = message;
                const retry = document.createElement('button');
                retry.type = 'button';
                retry.className = 'btn btn-sm btn-outline-danger mt-3';
                retry.textContent = 'Try again';
                retry.addEventListener('click', function () {
                    if (activeBorrowerId > 0) {
                        loadBorrower(activeBorrowerId);
                    }
                });
                errorEl.appendChild(text);
                errorEl.appendChild(retry);
                setState('error');
            }

            function fieldCard(field) {
                const card = document.createElement('div');
                card.className = 'consent-card borrower-detail-item' + (field.wide ? ' borrower-detail-item--wide' : '');
                const label = document.createElement('div');
                label.className = 'borrower-detail-label';
                label.textContent = field.label || '';
                const value = document.createElement('div');
                const display = field.value && String(field.value).trim() !== '' ? String(field.value) : 'Not provided';
                value.className = 'borrower-detail-value' + (display === 'Not provided' ? ' is-empty' : '');
                value.textContent = display;
                card.appendChild(label);
                card.appendChild(value);
                return card;
            }

            function renderBorrower(borrower) {
                contentEl.textContent = '';
                const name = borrower.full_name && String(borrower.full_name).trim() !== '' ? borrower.full_name : 'Borrower';
                titleEl.textContent = '';
                const icon = document.createElement('i');
                icon.className = 'fas fa-user me-2';
                titleEl.appendChild(icon);
                titleEl.appendChild(document.createTextNode('Borrower Details — ' + name));

                (borrower.sections || []).forEach(function (section) {
                    const wrap = document.createElement('section');
                    wrap.className = 'borrower-detail-section';
                    const heading = document.createElement('h6');
                    if (allowedIcons.indexOf(section.icon) !== -1) {
                        const sectionIcon = document.createElement('i');
                        sectionIcon.className = 'fas ' + section.icon + ' me-2';
                        heading.appendChild(sectionIcon);
                    }
                    heading.appendChild(document.createTextNode(section.title || 'Details'));
                    wrap.appendChild(heading);

                    const grid = document.createElement('div');
                    grid.className = 'borrower-detail-grid';
                    (section.fields || []).forEach(function (field) {
                        grid.appendChild(fieldCard(field));
                    });
                    wrap.appendChild(grid);

                    if (section.image) {
                        const imageBlock = document.createElement('div');
                        imageBlock.className = 'borrower-id-block mt-3';
                        if (section.image.available) {
                            const frame = document.createElement('div');
                            frame.className = 'borrower-id-frame';
                            const imageLoading = document.createElement('div');
                            imageLoading.className = 'borrower-id-frame__status text-muted';
                            imageLoading.textContent = 'Loading ID image…';
                            const imageError = document.createElement('div');
                            imageError.className = 'alert alert-warning d-none mb-0';
                            imageError.textContent = 'The ID image could not be displayed.';
                            const image = document.createElement('img');
                            image.className = 'borrower-id-preview d-none';
                            image.alt = 'Uploaded valid identification card for ' + name;
                            const imageUrl = 'view_borrower_id_lending.php?id=' + encodeURIComponent(String(borrower.id));
                            const actions = document.createElement('div');
                            actions.className = 'd-flex flex-wrap gap-2 mt-3';
                            image.addEventListener('load', function () {
                                imageLoading.classList.add('d-none');
                                image.classList.remove('d-none');
                            });
                            image.addEventListener('error', function () {
                                imageLoading.classList.add('d-none');
                                image.classList.add('d-none');
                                imageError.classList.remove('d-none');
                                actions.classList.add('d-none');
                            });
                            frame.appendChild(imageLoading);
                            frame.appendChild(imageError);
                            frame.appendChild(image);
                            const enlarge = document.createElement('button');
                            enlarge.type = 'button';
                            enlarge.className = 'btn btn-sm btn-outline-primary';
                            enlarge.textContent = 'Enlarge ID image';
                            enlarge.addEventListener('click', function () {
                                const enlarged = image.classList.toggle('borrower-id-preview--full');
                                enlarge.textContent = enlarged ? 'Show smaller preview' : 'Enlarge ID image';
                            });
                            const openFull = document.createElement('a');
                            openFull.className = 'btn btn-sm btn-outline-primary';
                            openFull.href = imageUrl;
                            openFull.target = '_blank';
                            openFull.rel = 'noopener noreferrer';
                            openFull.textContent = 'Open full size';
                            actions.appendChild(enlarge);
                            actions.appendChild(openFull);
                            image.src = imageUrl;
                            imageBlock.appendChild(frame);
                            imageBlock.appendChild(actions);
                        } else {
                            const empty = document.createElement('div');
                            empty.className = 'alert alert-warning mb-0';
                            empty.textContent = section.image.message || 'No ID image was uploaded with this registration.';
                            imageBlock.appendChild(empty);
                        }
                        wrap.appendChild(imageBlock);
                    }

                    contentEl.appendChild(wrap);
                });
                setState('ready');
            }

            function switchToDecision(targetId) {
                const target = document.getElementById(targetId);
                if (!target) {
                    showError('The approval action could not be opened. Use the buttons in the registration list.');
                    return;
                }
                detailModalEl.addEventListener('hidden.bs.modal', function openNext() {
                    detailModalEl.removeEventListener('hidden.bs.modal', openNext);
                    bootstrap.Modal.getOrCreateInstance(target).show();
                });
                detailModal.hide();
            }

            function loadBorrower(borrowerId) {
                const token = ++requestToken;
                activeBorrowerId = borrowerId;
                approveBtn.disabled = true;
                rejectBtn.disabled = true;
                titleEl.textContent = 'Borrower Details';
                contentEl.textContent = '';
                setState('loading');
                detailModal.show();

                fetch('borrower_approval_details_lending.php?id=' + encodeURIComponent(String(borrowerId)), {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin'
                }).then(function (response) {
                    return response.json().then(function (payload) {
                        return { ok: response.ok, payload: payload };
                    }).catch(function () {
                        return { ok: false, payload: { error: 'Borrower information could not be retrieved.' } };
                    });
                }).then(function (result) {
                    if (token !== requestToken) {
                        return;
                    }
                    if (!result.ok || !result.payload || !result.payload.ok || !result.payload.borrower) {
                        showError((result.payload && result.payload.error) ? result.payload.error : 'Borrower information could not be retrieved.');
                        return;
                    }
                    if (Number(result.payload.borrower.id) !== borrowerId) {
                        showError('Borrower information could not be retrieved.');
                        return;
                    }
                    renderBorrower(result.payload.borrower);
                    approveBtn.disabled = false;
                    rejectBtn.disabled = false;
                    approveBtn.onclick = function () {
                        switchToDecision('approveModal' + borrowerId);
                    };
                    rejectBtn.onclick = function () {
                        switchToDecision('rejectModal' + borrowerId);
                    };
                }).catch(function () {
                    if (token !== requestToken) {
                        return;
                    }
                    showError('Borrower information could not be retrieved.');
                });
            }

            document.querySelectorAll('.js-view-borrower').forEach(function (button) {
                button.addEventListener('click', function () {
                    const borrowerId = Number(button.getAttribute('data-borrower-id'));
                    if (!Number.isInteger(borrowerId) || borrowerId <= 0) {
                        return;
                    }
                    loadBorrower(borrowerId);
                });
            });
        });
    </script>
</body>
</html>
