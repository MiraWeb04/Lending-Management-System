<?php
/**
 * Borrower registration success page for RJ and RR Finance Services
 */
require_once 'includes/auth_lending.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Submitted - RJ and RR Finance Services</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/lending_styles.css">
</head>
<body class="public-auth-page">
    <div class="public-auth-shell">
        <div class="public-auth-card shadow-sm text-center">
            <div class="login-logo mx-auto mb-3">
                <img src="images/logo.png" alt="RJ and RR Finance Services logo">
            </div>
            <h2 class="auth-title mb-2">Registration Submitted</h2>
            <p class="auth-subtitle mb-4">Your registration has been successfully submitted.</p>

            <div class="alert alert-success rounded-4 text-start">
                <p class="mb-2"><strong>Your account is currently under review by RJ and RR Finance Services.</strong></p>
                <p class="mb-0">You will be notified once your account has been approved.</p>
            </div>

            <div class="registration-section">
                <h5 class="section-subtitle">Verification Pending</h5>
                <p class="text-muted mb-3">Email verification and SMS verification can be enabled in the future.</p>
                <div class="row g-3 justify-content-center">
                    <div class="col-md-5">
                        <div class="consent-card">
                            <i class="fas fa-envelope-open-text text-primary mb-2"></i>
                            <div class="fw-semibold">Email Verification</div>
                            <small class="text-muted">Planned for future release</small>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="consent-card">
                            <i class="fas fa-sms text-primary mb-2"></i>
                            <div class="fw-semibold">SMS Verification</div>
                            <small class="text-muted">Planned for future release</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-center gap-3 flex-wrap mt-4">
                <a href="index.php" class="btn btn-outline-primary rounded-pill">Return to Home</a>
                <a href="borrower_login_lending.php" class="btn btn-primary">Back to Login</a>
            </div>
        </div>
    </div>
</body>
</html>
