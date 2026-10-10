<?php
/**
 * Admin — public contact form inquiries and email replies.
 */

require_once 'includes/auth_lending.php';
requireAdmin();

require_once 'includes/db_lending.php';
require_once 'includes/ui_helpers.php';
require_once 'includes/contact_inquiry_helpers.php';

ensureContactInquiriesSchema();

$user = getCurrentUser();
$adminUserId = (int)($user['user_id'] ?? 0);

$message = '';
$messageType = 'success';
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$viewId = isset($_GET['view']) && is_numeric($_GET['view']) ? (int)$_GET['view'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequireValid();

    if (isset($_POST['send_reply'], $_POST['inquiry_id']) && is_numeric($_POST['inquiry_id'])) {
        $inquiryId = (int)$_POST['inquiry_id'];
        $replyBody = trim((string)($_POST['reply_body'] ?? ''));
        $result = replyToContactInquiry($inquiryId, $adminUserId, $replyBody);
        $message = $result['message'];
        $messageType = !empty($result['success']) ? (($result['email_sent'] ?? true) ? 'success' : 'warning') : 'danger';
        header('Location: contact_inquiries_lending.php?view=' . $inquiryId . '&msg=' . urlencode($message) . '&type=' . urlencode($messageType));
        exit;
    }

    if (isset($_POST['set_status'], $_POST['inquiry_id'], $_POST['status']) && is_numeric($_POST['inquiry_id'])) {
        $inquiryId = (int)$_POST['inquiry_id'];
        updateContactInquiryStatus($inquiryId, trim((string)$_POST['status']));
        header('Location: contact_inquiries_lending.php?view=' . $inquiryId);
        exit;
    }

    if (isset($_POST['resend_reply'], $_POST['reply_id']) && is_numeric($_POST['reply_id'])) {
        $replyId = (int)$_POST['reply_id'];
        $result = resendContactInquiryReplyEmail($replyId);
        $message = (string)($result['message'] ?? 'Done.');
        $messageType = !empty($result['success']) ? 'success' : 'danger';
        $inquiryId = (int)($_POST['inquiry_id'] ?? 0);
        header('Location: contact_inquiries_lending.php?view=' . $inquiryId . '&msg=' . urlencode($message) . '&type=' . urlencode($messageType));
        exit;
    }

    if (isset($_POST['delete_inquiry'], $_POST['inquiry_id']) && is_numeric($_POST['inquiry_id'])) {
        $inquiryId = (int)$_POST['inquiry_id'];
        $deleted = deleteContactInquiry($inquiryId);
        $statusQuery = 'status=' . rawurlencode($statusFilter);
        if ($deleted) {
            header(
                'Location: contact_inquiries_lending.php?' . $statusQuery
                . '&msg=' . rawurlencode('Inquiry deleted permanently.')
                . '&type=success'
            );
        } else {
            header(
                'Location: contact_inquiries_lending.php?view=' . $inquiryId . '&' . $statusQuery
                . '&msg=' . rawurlencode('Could not delete this inquiry.')
                . '&type=danger'
            );
        }
        exit;
    }
}

if (isset($_GET['msg'])) {
    $message = trim((string)$_GET['msg']);
    $messageType = in_array($_GET['type'] ?? '', ['success', 'warning', 'danger'], true) ? (string)$_GET['type'] : 'success';
}

$viewInquiry = null;
$viewReplies = [];
if ($viewId > 0) {
    $viewInquiry = getContactInquiryById($viewId);
    if ($viewInquiry) {
        markContactInquiryRead($viewId);
        $viewReplies = fetchContactInquiryReplies($viewId);
        $viewInquiry = getContactInquiryById($viewId);
    }
}

$inquiries = fetchContactInquiries($statusFilter === 'all' ? null : $statusFilter, 150);
$newCount = countContactInquiriesByStatus('new');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/lending_assets.php'; lendingRenderPwaMeta(); ?>
    <title>Contact Inquiries - Lending Management System</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="css/lending_styles.css">
    <link rel="stylesheet" href="css/pwa.css">
</head>
<body>
    <?php include 'includes/nav_lending.php'; ?>

    <div class="app-content py-2">
        <div class="dashboard-header mb-4">
            <div>
                <div class="dashboard-breadcrumb"><a href="dashboard_lending.php">Home</a> / Contact inquiries</div>
                <h1 class="page-title mb-1"><i class="fas fa-envelope-open-text me-2"></i>Contact Inquiries</h1>
                <p class="page-subtitle mb-0">Messages from the public website contact form. Reply by email — no borrower account required.</p>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?php echo htmlspecialchars($messageType === 'warning' ? 'warning' : ($messageType === 'danger' ? 'danger' : 'success'), ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="kpi-grid mb-4">
            <div class="kpi-card kpi-card--primary">
                <div class="kpi-card__top"><div class="kpi-card__label">New</div><div class="kpi-card__icon"><i class="fas fa-inbox"></i></div></div>
                <div class="kpi-card__value"><?php echo (int)$newCount; ?></div>
                <div class="kpi-card__meta">Awaiting first read</div>
            </div>
            <div class="kpi-card kpi-card--info">
                <div class="kpi-card__top"><div class="kpi-card__label">Total</div><div class="kpi-card__icon"><i class="fas fa-envelope"></i></div></div>
                <div class="kpi-card__value"><?php echo countContactInquiriesByStatus(null); ?></div>
                <div class="kpi-card__meta">All time</div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-5">
                <div class="panel-card panel-card--flush">
                    <div class="chart-panel__header table-panel__toolbar">
                        <h6 class="m-0 fw-bold">Inbox</h6>
                        <form method="get" class="d-flex gap-2 align-items-center mb-0">
                            <?php if ($viewId > 0): ?>
                                <input type="hidden" name="view" value="<?php echo (int)$viewId; ?>">
                            <?php endif; ?>
                            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Filter by status">
                                <option value="all"<?php echo $statusFilter === 'all' ? ' selected' : ''; ?>>All</option>
                                <option value="new"<?php echo $statusFilter === 'new' ? ' selected' : ''; ?>>New</option>
                                <option value="read"<?php echo $statusFilter === 'read' ? ' selected' : ''; ?>>Open</option>
                                <option value="replied"<?php echo $statusFilter === 'replied' ? ' selected' : ''; ?>>Replied</option>
                                <option value="closed"<?php echo $statusFilter === 'closed' ? ' selected' : ''; ?>>Closed</option>
                            </select>
                        </form>
                    </div>
                    <div class="list-group list-group-flush contact-inquiry-list">
                        <?php if (count($inquiries) === 0): ?>
                            <div class="p-4"><?php echo renderEmptyState('fa-inbox', 'No inquiries', 'Submissions from the homepage contact form will appear here.'); ?></div>
                        <?php else: ?>
                            <?php foreach ($inquiries as $row): ?>
                                <?php
                                $id = (int)$row['id'];
                                $isActive = $viewId === $id;
                                $status = (string)($row['status'] ?? 'new');
                                ?>
                                <a href="contact_inquiries_lending.php?view=<?php echo $id; ?>&status=<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>"
                                   class="list-group-item list-group-item-action contact-inquiry-list__item<?php echo $isActive ? ' active' : ''; ?><?php echo $status === 'new' ? ' contact-inquiry-list__item--new' : ''; ?>">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div class="min-w-0">
                                            <div class="fw-bold text-truncate"><?php echo htmlspecialchars((string)$row['sender_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div class="small text-muted text-truncate"><?php echo htmlspecialchars((string)$row['sender_email'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        </div>
                                        <span class="badge rounded-pill bg-<?php echo $status === 'new' ? 'primary' : ($status === 'replied' ? 'success' : 'secondary'); ?>"><?php echo htmlspecialchars(contactInquiryStatusLabel($status), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <p class="small mb-1 mt-2 text-truncate"><?php echo htmlspecialchars((string)$row['message'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    <div class="small text-muted"><?php echo date('M j, Y · g:i A', strtotime((string)$row['created_at'])); ?></div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <?php if (!$viewInquiry): ?>
                    <div class="panel-card p-4">
                        <?php echo renderEmptyState('fa-envelope-open', 'Select an inquiry', 'Choose a message from the list to read and reply.'); ?>
                    </div>
                <?php else: ?>
                    <div class="panel-card panel-card--flush mb-3">
                        <div class="chart-panel__header">
                            <div>
                                <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars((string)$viewInquiry['sender_name'], ENT_QUOTES, 'UTF-8'); ?></h6>
                                <a href="mailto:<?php echo htmlspecialchars((string)$viewInquiry['sender_email'], ENT_QUOTES, 'UTF-8'); ?>" class="small"><?php echo htmlspecialchars((string)$viewInquiry['sender_email'], ENT_QUOTES, 'UTF-8'); ?></a>
                            </div>
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <span class="badge bg-secondary"><?php echo htmlspecialchars(contactInquiryStatusLabel((string)$viewInquiry['status']), ENT_QUOTES, 'UTF-8'); ?></span>
                                <form method="post" class="mb-0">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="inquiry_id" value="<?php echo (int)$viewInquiry['id']; ?>">
                                    <input type="hidden" name="set_status" value="1">
                                    <?php if (($viewInquiry['status'] ?? '') !== 'closed'): ?>
                                        <input type="hidden" name="status" value="closed">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">Mark closed</button>
                                    <?php else: ?>
                                        <input type="hidden" name="status" value="read">
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Reopen</button>
                                    <?php endif; ?>
                                </form>
                                <form method="post" class="mb-0" onsubmit="return confirm('Delete this inquiry and all sent replies? This cannot be undone.');">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="inquiry_id" value="<?php echo (int)$viewInquiry['id']; ?>">
                                    <input type="hidden" name="delete_inquiry" value="1">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete inquiry">
                                        <i class="fas fa-trash-alt me-1"></i>Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                        <div class="p-4">
                            <div class="contact-inquiry-original mb-4">
                                <div class="small text-muted mb-2">Received <?php echo date('F j, Y \a\t g:i A', strtotime((string)$viewInquiry['created_at'])); ?></div>
                                <div class="contact-inquiry-original__body"><?php echo nl2br(htmlspecialchars((string)$viewInquiry['message'], ENT_QUOTES, 'UTF-8')); ?></div>
                            </div>

                            <?php if (count($viewReplies) > 0): ?>
                                <h6 class="fw-bold mb-3"><i class="fas fa-reply me-2"></i>Sent replies</h6>
                                <?php foreach ($viewReplies as $reply): ?>
                                    <div class="contact-inquiry-reply mb-3">
                                        <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-2">
                                            <div class="small text-muted">
                                                <?php echo htmlspecialchars((string)($reply['admin_name'] ?? 'Admin'), ENT_QUOTES, 'UTF-8'); ?>
                                                · <?php echo date('M j, Y · g:i A', strtotime((string)$reply['created_at'])); ?>
                                            </div>
                                            <?php if ((int)($reply['email_sent'] ?? 0) === 1): ?>
                                                <span class="badge bg-success"><i class="fas fa-check me-1"></i>Email delivered</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark"><i class="fas fa-triangle-exclamation me-1"></i>Email failed</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="contact-inquiry-reply__body"><?php echo nl2br(htmlspecialchars((string)$reply['reply_body'], ENT_QUOTES, 'UTF-8')); ?></div>
                                        <?php if ((int)($reply['email_sent'] ?? 0) !== 1): ?>
                                            <form method="post" class="mt-2 mb-0">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="inquiry_id" value="<?php echo (int)$viewInquiry['id']; ?>">
                                                <input type="hidden" name="reply_id" value="<?php echo (int)$reply['id']; ?>">
                                                <input type="hidden" name="resend_reply" value="1">
                                                <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-paper-plane me-1"></i>Resend email</button>
                                            </form>
                                            <?php if (!empty($reply['email_error'])): ?>
                                                <div class="small text-danger mt-1"><?php echo htmlspecialchars((string)$reply['email_error'], ENT_QUOTES, 'UTF-8'); ?></div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <hr class="my-4">
                            <h6 class="fw-bold mb-3">Reply by email</h6>
                            <p class="small text-muted">Your reply is sent to <strong><?php echo htmlspecialchars((string)$viewInquiry['sender_email'], ENT_QUOTES, 'UTF-8'); ?></strong> even if they are not registered as a borrower.</p>
                            <form method="post">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="inquiry_id" value="<?php echo (int)$viewInquiry['id']; ?>">
                                <input type="hidden" name="send_reply" value="1">
                                <div class="mb-3">
                                    <label for="reply_body" class="form-label">Message</label>
                                    <textarea class="form-control" id="reply_body" name="reply_body" rows="6" required placeholder="Write your response to the sender..."></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-2"></i>Send reply</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php include 'includes/nav_footer_lending.php'; ?>
</body>
</html>
