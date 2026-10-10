<?php
/**
 * Borrower registration success page for RJ and RR Finance Services
 */
require_once 'includes/auth_lending.php';

$registeredEmail = trim((string)($_SESSION['borrower_registration_pending_email'] ?? ''));
unset($_SESSION['borrower_registration_pending_email']);

if ($registeredEmail === '') {
    $registeredEmail = trim((string)($_GET['email'] ?? ''));
}

$emailDisplay = $registeredEmail !== '' ? htmlspecialchars($registeredEmail) : 'the email address you provided';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/lending_assets.php'; lendingRenderPwaMeta(); ?>
    <title>Registration Submitted - RJ and RR Finance Services</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="css/lending_styles.css">
    <link rel="stylesheet" href="css/pwa.css">
</head>
<body class="public-auth-page">
    <div class="public-auth-layout">
        <?php include 'includes/borrower_auth_brand_panel.php'; ?>
        <div class="public-auth-panel">
            <div class="public-auth-card text-center">
                <div class="login-logo mx-auto mb-3" style="width:64px;height:64px">
                    <img src="images/logo.png" alt="RJ and RR Finance Services logo">
                </div>
                <h2 class="auth-title mb-2">Registration received</h2>
                <p class="auth-subtitle mb-4">Thank you for registering with RJ &amp; RR Finance Services.</p>
                <p class="text-muted small mb-0">Please review the confirmation message for next steps.</p>
                <div class="d-flex justify-content-center gap-2 flex-wrap mt-4">
                    <a href="index.php" class="btn btn-outline-primary rounded-pill">Return to home</a>
                    <a href="borrower_login_lending.php" class="btn btn-primary rounded-pill">Go to login</a>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="registrationSuccessModal" tabindex="-1" aria-labelledby="registrationSuccessModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
                <div class="modal-header border-0 pb-0">
                    <div class="w-100 text-center pt-2">
                        <div class="d-inline-grid place-items-center rounded-4 bg-success-subtle text-success mb-3" style="width:64px;height:64px;font-size:1.5rem;">
                            <i class="fas fa-circle-check" aria-hidden="true"></i>
                        </div>
                        <h5 class="modal-title fw-bold w-100" id="registrationSuccessModalLabel">Registration submitted successfully</h5>
                    </div>
                </div>
                <div class="modal-body text-center px-4 pb-2">
                    <p class="mb-3">Your borrower account has been created and is <strong>pending administrator verification</strong>.</p>
                    <p class="mb-3 text-muted">You will not be able to sign in until an admin approves your registration. Once your account is verified, we will send a confirmation email to:</p>
                    <p class="fw-bold text-primary mb-3"><?php echo $emailDisplay; ?></p>
                    <p class="small text-muted mb-0">If you do not see the email after approval, check your spam folder or contact our office.</p>
                </div>
                <div class="modal-footer border-0 justify-content-center gap-2 pb-4 px-4 flex-wrap">
                    <a href="borrower_login_lending.php" class="btn btn-primary rounded-pill px-4">OK, go to login</a>
                    <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4">Return to website</a>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="assets/pwa.js" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var modalEl = document.getElementById('registrationSuccessModal');
            if (modalEl && typeof bootstrap !== 'undefined') {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        });
    </script>
</body>
</html>
