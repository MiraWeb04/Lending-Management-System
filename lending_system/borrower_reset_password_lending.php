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
    <title>Reset Password - RJ and RR Finance Services</title>
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
                <h4 class="mb-3">Reset Password</h4>
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
            </div>

            <div class="auth-footer mt-4 text-center">
                <p class="mb-0 text-muted">Need help? Contact our office for assistance.</p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
