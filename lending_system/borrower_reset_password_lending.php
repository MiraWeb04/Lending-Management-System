<?php
require_once 'includes/auth_lending.php';
require_once 'includes/email_templates.php';

$token = trim($_GET['token'] ?? '');
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim($_POST['token'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $password2 = trim($_POST['password2'] ?? '');

    if ($password === '' || $password2 === '') {
        $error = 'Please enter and confirm your new password.';
    } elseif ($password !== $password2) {
        $error = 'Passwords do not match.';
    } else {
        $user = resetUserPasswordByToken($token, $password);
        if ($user) {
            // Auto login after reset
            startUserSession($user);
            header('Location: ' . getDashboardRedirectUrl($user));
            exit;
        } else {
            $error = 'Invalid or expired token.';
        }
    }
}

$validUser = $token !== '' ? getUserByResetToken($token) : false;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/lending_assets.php'; lendingRenderPwaMeta(); ?>
    <title>Reset Password - RJ and RR Finance Services</title>
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
                <h2 class="auth-title mb-1">Reset password</h2>
                <p class="auth-subtitle mb-4">Choose a strong new password for your account.</p>
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <?php if (!$validUser): ?>
                    <div class="alert alert-warning">Invalid or expired token. Please request a new reset link.</div>
                    <a href="borrower_forgot_password_lending.php" class="btn btn-primary">Request Reset</a>
                <?php else: ?>
                <form method="post" class="public-auth-form" novalidate>
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                    <div class="mb-3">
                        <label class="form-label" for="password">New Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-key"></i></span>
                            <input type="password" id="password" name="password" class="form-control" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="password2">Confirm Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-key"></i></span>
                            <input type="password" id="password2" name="password2" class="form-control" required>
                        </div>
                    </div>

                    <div class="d-grid gap-2">
                        <button class="btn btn-primary btn-lg login-btn" type="submit">Change Password</button>
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
