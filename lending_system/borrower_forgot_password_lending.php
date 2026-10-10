<?php
require_once 'includes/auth_lending.php';
require_once 'includes/db_lending.php';
require_once 'includes/loan_email_helpers.php';

function clearPasswordOtpSession(): void
{
    unset(
        $_SESSION['password_reset_stage'],
        $_SESSION['password_reset_user_id'],
        $_SESSION['password_reset_email_mask']
    );
}

function maskAccountEmail(string $email): string
{
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        return 'your email';
    }

    $name = $parts[0];
    $visible = substr($name, 0, 1);

    return $visible . str_repeat('*', max(3, strlen($name) - 1)) . '@' . $parts[1];
}

$message = '';
$error = '';
$stage = (string)($_SESSION['password_reset_stage'] ?? 'request');
$resetUserId = (int)($_SESSION['password_reset_user_id'] ?? 0);
$emailMask = (string)($_SESSION['password_reset_email_mask'] ?? 'your email');
$passwordUpdated = false;

if ($stage !== 'request' && $resetUserId <= 0) {
    clearPasswordOtpSession();
    $stage = 'request';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'start_over') {
        clearPasswordOtpSession();
        $stage = 'request';
        $resetUserId = 0;
    } elseif ($action === 'send_otp' || $action === 'resend_otp') {
        $identifier = trim((string)($_POST['identifier'] ?? ''));
        $user = null;

        if ($action === 'resend_otp') {
            if ($resetUserId <= 0) {
                $error = 'Request a new code to continue.';
                $stage = 'request';
            } else {
                $userStmt = executeQuery('SELECT * FROM users WHERE user_id = ? LIMIT 1', [$resetUserId]);
                $user = $userStmt ? ($userStmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;
            }
        } elseif ($identifier === '') {
            $error = 'Please enter your username or email.';
        } else {
            $found = getUserByUsernameOrEmail($identifier);
            $user = $found ?: null;
        }

        if ($error === '' && $user) {
            $recipient = trim((string)($user['email'] ?? ''));
            if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $error = 'This account has no email address on file. Contact the office for help signing in.';
            } else {
                $otp = createPasswordResetOtp((int)$user['user_id']);
                $sent = $otp !== '' ? sendPasswordOtpEmail($user, $otp) : ['success' => false];
                if (empty($sent['success'])) {
                    executeQuery('DELETE FROM password_resets WHERE user_id = ?', [(int)$user['user_id']]);
                    $error = 'We could not send the code to your email. Please try again.';
                } else {
                    $_SESSION['password_reset_stage'] = 'otp';
                    $_SESSION['password_reset_user_id'] = (int)$user['user_id'];
                    $_SESSION['password_reset_email_mask'] = maskAccountEmail($recipient);
                    $stage = 'otp';
                    $resetUserId = (int)$user['user_id'];
                    $emailMask = $_SESSION['password_reset_email_mask'];
                    $message = $action === 'resend_otp'
                        ? 'A new code was sent to ' . $emailMask . '.'
                        : 'A 6-digit code was sent to ' . $emailMask . '.';
                }
            }
        } elseif ($error === '' && $action === 'send_otp') {
            $message = 'If an account exists for that username or email, a 6-digit code was sent to the Gmail address on file.';
        }
    } elseif ($action === 'verify_otp') {
        if ($resetUserId <= 0) {
            $error = 'Request a code before entering it.';
            $stage = 'request';
        } else {
            $otpError = verifyPasswordResetOtp($resetUserId, (string)($_POST['otp'] ?? ''));
            if ($otpError !== '') {
                $error = $otpError;
                if (!getActivePasswordResetRow($resetUserId)) {
                    clearPasswordOtpSession();
                    $stage = 'request';
                    $resetUserId = 0;
                } else {
                    $stage = 'otp';
                }
            } else {
                $_SESSION['password_reset_stage'] = 'password';
                $stage = 'password';
                $message = 'Code verified. Choose a new password.';
            }
        }
    } elseif ($action === 'change_password') {
        if ($stage !== 'password' || $resetUserId <= 0) {
            $error = 'Verify the email code before changing your password.';
            $stage = $resetUserId > 0 ? 'otp' : 'request';
        } else {
            $password = (string)($_POST['password'] ?? '');
            $password2 = (string)($_POST['password2'] ?? '');
            if ($password === '' || $password2 === '') {
                $error = 'Please enter and confirm your new password.';
            } elseif ($password !== $password2) {
                $error = 'Passwords do not match.';
            } elseif (strlen($password) < 8) {
                $error = 'Use at least 8 characters for your new password.';
            } else {
                $updated = resetUserPasswordAfterOtp($resetUserId, $password);
                if ($updated) {
                    clearPasswordOtpSession();
                    $stage = 'done';
                    $resetUserId = 0;
                    $passwordUpdated = true;
                    $message = 'Your password has been changed. Sign in with the new password.';
                } else {
                    clearPasswordOtpSession();
                    $stage = 'request';
                    $resetUserId = 0;
                    $error = 'That code has expired. Request a new code and try again.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/lending_assets.php'; lendingRenderPwaMeta(); ?>
    <title>Forgot Password - RJ and RR Finance Services</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/lending_styles.css">
    <link rel="stylesheet" href="css/pwa.css">
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
</head>
<body class="public-auth-page">
    <div class="public-auth-layout">
        <?php include 'includes/borrower_auth_brand_panel.php'; ?>
        <div class="public-auth-panel">
            <div class="public-auth-card shadow-sm">
            <a href="borrower_login_lending.php" class="public-auth-back"><i class="fas fa-arrow-left"></i> Back to login</a>
                <h2 class="auth-title mb-1">Forgot password</h2>
                <p class="auth-subtitle mb-4">
                    <?php if ($stage === 'otp'): ?>
                        Enter the 6-digit code sent to <?php echo htmlspecialchars($emailMask); ?>.
                    <?php elseif ($stage === 'password'): ?>
                        Choose a new password for your account.
                    <?php elseif ($passwordUpdated): ?>
                        Your password is updated.
                    <?php else: ?>
                        We will email a 6-digit code to the Gmail address on your account.
                    <?php endif; ?>
                </p>
                <?php if ($message !== ''): ?>
                    <div class="alert alert-info login-alert"><?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger login-alert"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <?php if ($passwordUpdated): ?>
                    <div class="d-grid">
                        <a href="borrower_login_lending.php" class="btn btn-primary btn-lg login-btn">Sign in</a>
                    </div>
                <?php elseif ($stage === 'otp'): ?>
                <form method="post" class="public-auth-form" novalidate>
                    <input type="hidden" name="action" value="verify_otp">
                    <div class="mb-3">
                        <label class="form-label" for="otp">Email code</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-shield-halved"></i></span>
                            <input type="text" id="otp" name="otp" class="form-control" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required autofocus>
                        </div>
                        <div class="form-text">The code expires in 10 minutes. Check your inbox and spam folder.</div>
                    </div>
                    <div class="d-grid gap-2">
                        <button class="btn btn-primary btn-lg login-btn" type="submit">Verify code</button>
                    </div>
                </form>
                <form method="post" class="mt-3 d-grid gap-2">
                    <input type="hidden" name="action" value="resend_otp">
                    <button class="btn btn-outline-primary btn-lg rounded-pill" type="submit">Resend code</button>
                </form>
                <form method="post" class="mt-2 d-grid">
                    <input type="hidden" name="action" value="start_over">
                    <button class="btn btn-link" type="submit">Use a different account</button>
                </form>
                <?php elseif ($stage === 'password'): ?>
                <form method="post" class="public-auth-form" novalidate>
                    <input type="hidden" name="action" value="change_password">
                    <div class="mb-3">
                        <label class="form-label" for="password">New password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-key"></i></span>
                            <input type="password" id="password" name="password" class="form-control" minlength="8" required autofocus>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password2">Confirm password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-key"></i></span>
                            <input type="password" id="password2" name="password2" class="form-control" minlength="8" required>
                        </div>
                    </div>
                    <div class="d-grid gap-2">
                        <button class="btn btn-primary btn-lg login-btn" type="submit">Change password</button>
                        <a href="borrower_login_lending.php" class="btn btn-outline-primary btn-lg rounded-pill">Back to Login</a>
                    </div>
                </form>
                <?php else: ?>
                <form method="post" class="public-auth-form" novalidate>
                    <input type="hidden" name="action" value="send_otp">
                    <div class="mb-3">
                        <label class="form-label" for="identifier">Username or Email</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                            <input type="text" id="identifier" name="identifier" class="form-control" required autofocus>
                        </div>
                    </div>
                    <div class="d-grid gap-2">
                        <button class="btn btn-primary btn-lg login-btn" type="submit">Send code</button>
                        <a href="borrower_login_lending.php" class="btn btn-outline-primary btn-lg rounded-pill">Back to Login</a>
                    </div>
                </form>
                <?php endif; ?>

            <div class="auth-footer mt-4 text-center">
                <p class="mb-0 text-muted small">Need help? <a href="index.php#contact">Contact our office</a></p>
            </div>
            </div>
        </div>
    </div>

    <script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="assets/pwa.js" defer></script>
</body>
</html>
