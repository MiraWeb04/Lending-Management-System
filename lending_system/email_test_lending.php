<?php
/**
 * Admin-only SMTP test page.
 */

require_once 'includes/auth_lending.php';
requireAdmin();
require_once 'includes/db_lending.php';
require_once 'includes/loan_email_helpers.php';

$message = '';
$messageType = 'success';
require_once __DIR__ . '/includes/email_service.php';
$config = getMailConfig();
$emailLogs = getRecentEmailNotifications(30);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recipient = trim((string)($_POST['recipient'] ?? ''));
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        $message = 'Enter a valid recipient email address.';
        $messageType = 'danger';
    } else {
        $result = sendEmail(
            $recipient,
            'Lending System SMTP Test',
            '<p>This is a test email from the Lending Management System.</p><p>If you received this message, PHPMailer and Gmail SMTP are working.</p>',
            [],
            'smtp_test',
            'admin-test-' . strtolower($recipient) . '-' . date('YmdHis')
        );
        $message = $result['message'];
        if (!$result['success'] && !empty($result['log_id'])) {
            $message .= ' (Log #' . (int)$result['log_id'] . ')';
        }
        $messageType = $result['success'] ? 'success' : 'danger';
        $emailLogs = getRecentEmailNotifications(30);
    }
}

$smtpConfigured = ($config['smtp_host'] ?? '') === 'smtp.gmail.com'
    && !empty($config['smtp_user'])
    && !empty($config['smtp_pass']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/lending_assets.php'; lendingRenderPwaMeta(); ?>
    <title>Email SMTP Test - Lending Management System</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="css/lending_styles.css">
    <link rel="stylesheet" href="css/pwa.css">
</head>
<body>
    <?php include 'includes/nav_lending.php'; ?>
    <div class="app-content">
        <div class="dashboard-header">
            <div>
                <div class="dashboard-breadcrumb"><a href="dashboard_lending.php">Home</a> / Email SMTP Test</div>
                <h1 class="page-title mb-1"><i class="fas fa-envelope me-2"></i>Email SMTP Test</h1>
                <p class="page-subtitle mb-0">Validate delivery configuration and review notification logs.</p>
            </div>
            <span class="badge <?php echo $smtpConfigured ? 'bg-success' : 'bg-warning text-dark'; ?>"><?php echo $smtpConfigured ? 'SMTP Ready' : 'SMTP Incomplete'; ?></span>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> alert-permanent rounded-4"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card shadow-sm">
                    <div class="card-header bg-white py-3"><h6 class="m-0 fw-bold"><i class="fas fa-paper-plane me-2"></i>Send Test Email</h6></div>
                    <div class="card-body">
                        <form method="post" id="smtpTestForm">
                            <label class="form-label" for="recipient">Recipient Gmail or email address</label>
                            <input class="form-control mb-3" type="email" id="recipient" name="recipient" required placeholder="recipient@gmail.com">
                            <button class="btn btn-primary" type="submit" id="smtpTestSubmit"><i class="fas fa-paper-plane me-2"></i>Send Test Email</button>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm mt-4">
                    <div class="card-header bg-white py-3"><h6 class="m-0 fw-bold"><i class="fas fa-list me-2"></i>Recent Email Log</h6></div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Type</th>
                                        <th>Recipient</th>
                                        <th>Status</th>
                                        <th>Sent</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($emailLogs) === 0): ?>
                                        <tr><td colspan="5" class="text-center text-muted py-4">No email attempts logged yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($emailLogs as $log): ?>
                                            <?php
                                            $status = (string)($log['status'] ?? '');
                                            $badgeClass = $status === 'sent' ? 'bg-success' : ($status === 'failed' ? 'bg-danger' : 'bg-secondary');
                                            ?>
                                            <tr>
                                                <td><?php echo (int)($log['id'] ?? 0); ?></td>
                                                <td><span class="small"><?php echo htmlspecialchars((string)($log['notification_type'] ?? '')); ?></span></td>
                                                <td><?php echo htmlspecialchars((string)($log['recipient'] ?? '')); ?></td>
                                                <td><span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($status); ?></span></td>
                                                <td class="small text-muted"><?php echo htmlspecialchars((string)($log['sent_at'] ?? $log['created_at'] ?? '—')); ?></td>
                                            </tr>
                                            <?php if ($status === 'failed' && !empty($log['error_message'])): ?>
                                                <tr>
                                                    <td colspan="5" class="small text-danger bg-light"><?php echo htmlspecialchars((string)$log['error_message']); ?></td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white py-3"><h6 class="m-0 fw-bold"><i class="fas fa-server me-2"></i>SMTP Status</h6></div>
                    <div class="card-body">
                        <p class="mb-2"><strong>Server:</strong> <?php echo htmlspecialchars((string)($config['smtp_host'] ?? 'Not configured')); ?></p>
                        <p class="mb-2"><strong>Port:</strong> <?php echo (int)($config['smtp_port'] ?? 0); ?></p>
                        <p class="mb-2"><strong>Encryption:</strong> <?php echo htmlspecialchars((string)($config['smtp_secure'] ?? 'Not configured')); ?></p>
                        <p class="mb-2"><strong>From:</strong> <?php echo htmlspecialchars((string)($config['from_name'] ?? '')); ?> &lt;<?php echo htmlspecialchars((string)($config['from_email'] ?? '')); ?>&gt;</p>
                        <p class="mb-2"><strong>Authentication:</strong> <?php echo !empty($config['smtp_user']) ? 'Configured' : 'Missing username'; ?></p>
                        <p class="mb-2"><strong>Password:</strong> <?php echo !empty($config['smtp_pass']) ? '••••••••••••' : 'Not set'; ?></p>
                        <p class="mb-0"><strong>Configuration:</strong> <span class="badge <?php echo $smtpConfigured ? 'bg-success' : 'bg-warning text-dark'; ?>"><?php echo $smtpConfigured ? 'Ready' : 'Incomplete'; ?></span></p>
                        <?php if (!$smtpConfigured): ?>
                            <p class="small text-muted mt-3 mb-0">Copy <code>.env.example</code> to <code>.env</code> in the project root and set your Gmail address plus Google App Password.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php include 'includes/nav_footer_lending.php'; ?>
<script>
document.getElementById('smtpTestForm')?.addEventListener('submit', function () {
    var btn = document.getElementById('smtpTestSubmit');
    if (!btn || btn.disabled) return;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Sending…';
});
</script>
</body>
</html>
