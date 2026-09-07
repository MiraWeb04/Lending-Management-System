<?php
/**
 * Borrower Login Page for RJ and RR Finance Services
 */

require_once 'includes/auth_lending.php';

if (isLoggedIn()) {
    $redirect = getDashboardRedirectUrl(getCurrentUser());
    header('Location: ' . $redirect);
    exit;
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter both your username or email and password.';
    } else {
        $user = authenticateBorrowerLogin($username, $password);
        if ($user) {
            $status = trim((string)($user['status'] ?? ''));
            if (borrowerAccountCanLogin($user)) {
                $user['status'] = 'Active';
                if (empty(trim((string)($user['role'] ?? '')))) {
                    $user['role'] = 'Borrower';
                }
                startUserSession($user);
                $redirect = getDashboardRedirectUrl($user);
                header('Location: ' . $redirect);
                exit;
            }

            $statusLabel = $status !== '' ? $status : 'Pending Approval';
            $error = 'Your account is currently awaiting administrator approval.';
            $pendingNotice = true;
            $pendingStatus = $statusLabel;
            $registrationDate = $user['date_created'] ?? '';
        } else {
            $error = 'Invalid username/email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Borrower Login - RJ and RR Finance Services</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/lending_styles.css">
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

            <?php if ($error !== ''): ?>
                <div class="alert <?php echo isset($pendingNotice) && $pendingNotice ? 'alert-warning' : 'alert-danger'; ?> rounded-4">
                    <div class="d-flex flex-column flex-md-row justify-content-between gap-2">
                        <div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($error); ?></div>
                            <?php if (!empty($pendingStatus)): ?>
                                <div class="small text-muted mt-1">Status Badge: <span class="badge bg-warning text-dark"><?php echo htmlspecialchars($pendingStatus); ?></span></div>
                            <?php endif; ?>
                            <?php if (!empty($registrationDate)): ?>
                                <div class="small text-muted mt-1">Registration Date: <?php echo htmlspecialchars(date('M d, Y', strtotime($registrationDate))); ?></div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($pendingNotice)): ?>
                            <div class="d-flex gap-2">
                                <a href="index.php" class="btn btn-outline-primary btn-sm rounded-pill">Return to Home</a>
                                <a href="mailto:support@rjrrfinance.com" class="btn btn-primary btn-sm rounded-pill">Contact Support</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" class="public-auth-form" novalidate>
                <div class="mb-3">
                    <label class="form-label" for="username">Username or Email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                        <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>" required autofocus>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password" required>
                        <button type="button" class="btn btn-outline-secondary password-toggle" data-target="password" aria-label="Show password"><i class="fas fa-eye"></i></button>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="remember_me" name="remember_me" value="1">
                        <label class="form-check-label" for="remember_me">Remember Me</label>
                    </div>
                    <a href="borrower_forgot_password_lending.php" class="text-decoration-none fw-semibold">Forgot Password?</a>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary btn-lg login-btn">
                        Login <i class="fas fa-sign-in-alt ms-2"></i>
                    </button>
                    <a href="registration_lending.php" class="btn btn-outline-primary btn-lg rounded-pill">Register Account</a>
                </div>
            </form>

            <div class="auth-footer mt-4 text-center">
                <p class="mb-0 text-muted">Need help? Contact our office for assistance.</p>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.password-toggle').forEach(function (button) {
                button.addEventListener('click', function () {
                    const targetId = button.getAttribute('data-target');
                    const input = document.getElementById(targetId);
                    if (!input) return;
                    const isPassword = input.type === 'password';
                    input.type = isPassword ? 'text' : 'password';
                    button.innerHTML = isPassword ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
                    button.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
                });
            });
        });
    </script>
</body>
</html>
