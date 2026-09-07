<?php
require_once 'includes/auth_lending.php';
require_once 'includes/mailer.php';
require_once 'includes/email_templates.php';

$mailCfg = require __DIR__ . '/includes/mail_config.php';

$message = '';
$showLink = false;
$resetLink = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    if ($identifier === '') {
        $message = 'Please enter your username or email.';
    } else {
        $user = getUserByUsernameOrEmail($identifier);
        // Always show success message to avoid leaking
        if ($user) {
            $token = createPasswordResetToken((int)$user['user_id']);
            $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['REQUEST_URI']);
            $resetLink = $base . '/borrower_reset_password_lending.php?token=' . urlencode($token);
            // Send email using local SMTP (Mailpit)
            $to = $user['email'] ?? '';
            if ($to !== '') {
                $subject = 'Password reset for your account';
                $content = '<p>Hello ' . htmlspecialchars($user['full_name']) . ',</p>' .
                       '<p>We received a request to reset your password. Click the button below to change your password. This link will expire in one hour.</p>' .
                       '<p style="margin:18px 0;"><a href="' . htmlspecialchars($resetLink) . '" style="display:inline-block;padding:10px 16px;background:#1565c0;color:#fff;border-radius:6px;text-decoration:none;">Reset your password</a></p>' .
                       '<p>If you did not request this, you can ignore this email.</p>';

                $html = renderEmailTemplate('Password Reset Request', $content);
                sendMailSMTP($to, $subject, $html, $mailCfg['from_email'] ?? null, $mailCfg['from_name'] ?? null);
            }
            // Show link only if explicitly enabled for debugging
            $showLink = (!empty($mailCfg['show_reset_link']));
        }
        $message = 'If an account exists for that identifier, a password reset link has been generated.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - RJ and RR Finance Services</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/lending_styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="public-auth-page">
    <div class="public-auth-shell">
        <div class="public-auth-card shadow-sm">
            <div class="text-center mb-4">
                <div class="login-logo mx-auto mb-3">
                    <img src="images/logo.png" alt="RJ and RR Finance Services logo">
                </div>
                <h2 class="auth-title mb-0">RJ and RR Finance Services</h2>
                <p class="auth-subtitle mb-0">Borrower Portal</p>
            </div>

            <div class="card-body">
                <h4 class="mb-3">Forgot Password</h4>
                <?php if ($message !== ''): ?>
                    <div class="alert alert-info login-alert"><?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>

                <form method="post" class="public-auth-form" novalidate>
                    <div class="mb-3">
                        <label class="form-label" for="identifier">Username or Email</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                            <input type="text" id="identifier" name="identifier" class="form-control" required autofocus>
                        </div>
                    </div>

                    <div class="d-grid gap-2">
                        <button class="btn btn-primary btn-lg login-btn" type="submit">Generate Reset Link</button>
                        <a href="borrower_login_lending.php" class="btn btn-outline-primary btn-lg rounded-pill">Back to Login</a>
                    </div>
                </form>

                <?php if (!empty($showLink) && $showLink && !empty($resetLink)): ?>
                    <div class="mt-3">
                        <p class="small text-muted">Reset link (for debugging):</p>
                        <a href="<?php echo htmlspecialchars($resetLink); ?>"><?php echo htmlspecialchars($resetLink); ?></a>
                    </div>
                <?php endif; ?>
            </div>

            <div class="auth-footer mt-4 text-center">
                <p class="mb-0 text-muted">Need help? Contact our office for assistance.</p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // optional small enhancements could go here
    </script>
</body>
</html>
