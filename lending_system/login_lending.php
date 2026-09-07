<?php
/**
 * Login Page for Lending Management System
 */

// Include authentication functions
require_once 'includes/auth_lending.php';

// Check if already logged in
if (isLoggedIn()) {
    $redirect = getDashboardRedirectUrl(getCurrentUser());
    header('Location: ' . $redirect);
    exit;
}

// Initialize variables
$error = '';
$username = '';

// Process login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    // Validate input
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        // Attempt to login
        $user = login($username, $password);
        
        if ($user) {
            $redirect = getDashboardRedirectUrl($user);
            header('Location: ' . $redirect);
            exit;
        } else {
            $error = 'Invalid username or password';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - RJ and RR Finance Services</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/lending_styles.css">
</head>
<body class="login-page-body">
    <div class="login-shell">
        <div class="login-card">
            <div class="login-card__brand">
                <div class="login-logo" aria-hidden="true">
                    <img src="images/logo.png" alt="RJ and RR Finance Services logo">
                </div>
                <div>
                    <h1>Welcome back</h1>
                    <p>Sign in to your borrower workspace</p>
                </div>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger login-alert"><?php echo $error; ?></div>
            <?php endif; ?>

            <form method="post" action="" class="login-form">
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <div class="input-group login-input-group">
                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                        <input type="text" class="form-control login-input" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>" required autofocus>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group login-input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" class="form-control login-input" id="password" name="password" required>
                        <button type="button" class="btn btn-outline-secondary password-toggle" aria-label="Show password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="rememberMe" name="remember_me" value="1">
                        <label class="form-check-label" for="rememberMe">Remember Me</label>
                    </div>
                    <a href="login_lending.php" class="forgot-password">Forgot Password?</a>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-primary btn-lg login-btn">
                        Sign In <i class="fas fa-sign-in-alt ms-2"></i>
                    </button>
                </div>
            </form>

            <div class="login-footer">
                <div class="small text-muted">&copy; <?php echo date('Y'); ?> RJ and RR Finance Services</div>
                <span>Secure access</span>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const passwordInput = document.getElementById('password');
            const toggleButton = document.querySelector('.password-toggle');

            if (passwordInput && toggleButton) {
                toggleButton.addEventListener('click', function () {
                    const isPassword = passwordInput.type === 'password';
                    passwordInput.type = isPassword ? 'text' : 'password';
                    toggleButton.innerHTML = isPassword ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
                    toggleButton.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
                });
            }
        });
    </script>
</body>
</html>